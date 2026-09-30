<?php
/**
 * 拆單系統回歸測試：驗證 DonationModel::publishDonation() 產生的子單編號、
 * 物資數量比例分配，以及未拆單（split_count=1）情境下的既有行為不受影響。
 *
 * 本測試會建立一個暫時的抓取自正式資料庫結構的測試資料庫（複製一份 schema+資料），
 * 在其中執行拆單發布，驗證結果後刪除該測試資料庫，不會影響正式資料。
 */
require __DIR__ . '/../config/database.php';

$testDbName = 'foodbank_test_order_split_' . bin2hex(random_bytes(4));
$mysqlBin = '/Applications/XAMPP/xamppfiles/bin/mysql';
$mysqldumpBin = '/Applications/XAMPP/xamppfiles/bin/mysqldump';
$sourceDbName = DB_NAME;

$failures = 0;

function shellOk($command) {
    $output = [];
    $exitCode = 0;
    exec($command . ' 2>&1', $output, $exitCode);
    return $exitCode === 0;
}

if (!file_exists($mysqlBin) || !file_exists($mysqldumpBin)) {
    echo "SKIP order-split-tests (mysql/mysqldump binaries not found at expected XAMPP path)\n";
    exit(0);
}

$created = shellOk(escapeshellarg($mysqlBin) . ' -u ' . escapeshellarg(DB_USER) . ' -e ' . escapeshellarg("DROP DATABASE IF EXISTS {$testDbName}; CREATE DATABASE {$testDbName};"));
$cloned = $created && shellOk(escapeshellarg($mysqldumpBin) . ' -u ' . escapeshellarg(DB_USER) . ' ' . escapeshellarg($sourceDbName) . ' | ' . escapeshellarg($mysqlBin) . ' -u ' . escapeshellarg(DB_USER) . ' ' . escapeshellarg($testDbName));

if (!$cloned) {
    echo "SKIP order-split-tests (failed to clone database for isolated testing)\n";
    exit(0);
}

putenv("DB_NAME={$testDbName}");
// 讓 config/database.php 目前流程重新讀取新的 DB_NAME（本檔案自己 require config 前已定義過常數，
// 因此改以 mysqli 直接連線至測試資料庫，避免與已定義的 DB_NAME 常數衝突）。
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, $testDbName, DB_PORT);

require __DIR__ . '/../src/models/DonationModel.php';

// 建立一個只連向測試資料庫的 DonationModel 實例（略過 BaseModel 的全域連線）。
$donationModel = new class($conn) extends DonationModel {
    public function __construct($conn) {
        $this->db = $conn;
    }
};

$sourceRow = $conn->query('SELECT donation_id, order_number FROM donations WHERE status = "approved" LIMIT 1');
$donation = $sourceRow ? $sourceRow->fetch_assoc() : null;

if (!$donation) {
    echo "SKIP order-split-tests (no approved donation available as fixture)\n";
    $conn->close();
    shellOk(escapeshellarg($mysqlBin) . ' -u ' . escapeshellarg(DB_USER) . ' -e ' . escapeshellarg("DROP DATABASE IF EXISTS {$testDbName};"));
    exit(0);
}

$donationId = (int) $donation['donation_id'];
$orderNumber = $donation['order_number'];

$conn->query("UPDATE donations SET status = 'assessed' WHERE donation_id = {$donationId}");

$itemsBefore = $conn->query("SELECT item_id, quantity FROM donation_items WHERE donation_id = {$donationId}")->fetch_all(MYSQLI_ASSOC);

$published = $donationModel->publishDonation($donationId, ['split_count' => 3, 'delivery_method' => 'volunteer', 'urgency' => 'normal']);

if ($published) {
    echo "PASS publish-with-split (donation #{$donationId})\n";
} else {
    echo "FAIL publish-with-split (donation #{$donationId})\n";
    $failures++;
}

// 1. 子單編號應為 {order_number}-A / -B / -C。
$allocations = $conn->query("SELECT allocation_id, sub_order_number, allocation_number FROM donation_allocations WHERE donation_id = {$donationId} ORDER BY allocation_number ASC")->fetch_all(MYSQLI_ASSOC);
$expectedSuffixes = ['A', 'B', 'C'];
$actualNumbers = array_column($allocations, 'sub_order_number');
$expectedNumbers = array_map(static function ($suffix) use ($orderNumber) {
    return "{$orderNumber}-{$suffix}";
}, $expectedSuffixes);

if (count($allocations) === 3 && $actualNumbers === $expectedNumbers) {
    echo "PASS sub-order-numbering (" . implode(',', $actualNumbers) . ")\n";
} else {
    echo "FAIL sub-order-numbering: expected " . implode(',', $expectedNumbers) . ' got ' . implode(',', $actualNumbers) . "\n";
    $failures++;
}

// 2. 每張子單的物資數量加總應等於原始數量（比例分配，允許四捨五入誤差）。
$quantityMismatch = false;
foreach ($itemsBefore as $item) {
    $itemId = (int) $item['item_id'];
    $originalQuantity = (float) $item['quantity'];
    $allocatedSum = $conn->query("SELECT SUM(quantity) AS total FROM donation_allocation_items WHERE donation_item_id = {$itemId}")->fetch_assoc();
    $allocatedTotal = (float) ($allocatedSum['total'] ?? 0);
    if (abs($allocatedTotal - $originalQuantity) > 0.05) {
        $quantityMismatch = true;
        echo "  mismatch item #{$itemId}: original={$originalQuantity} allocated_total={$allocatedTotal}\n";
    }
}
if (!$quantityMismatch) {
    echo "PASS proportional-quantity-distribution\n";
} else {
    echo "FAIL proportional-quantity-distribution\n";
    $failures++;
}

// 3. 每張子單應各自綁定唯一的 allocation_id（僅檢查本次拆單新建立的 3 筆配送任務；
//    捐贈單原本可能已存在其他未拆單的舊配送任務，allocation_id 為 NULL，不在此檢查範圍）。
$deliveryAllocations = $conn->query("SELECT delivery_id, allocation_id FROM deliveries WHERE donation_id = {$donationId} AND allocation_id IS NOT NULL")->fetch_all(MYSQLI_ASSOC);
$linkedAllocationIds = array_filter(array_column($deliveryAllocations, 'allocation_id'));
if (count($deliveryAllocations) === 3 && count(array_unique($linkedAllocationIds)) === 3) {
    echo "PASS delivery-allocation-linkage (3 deliveries, 3 distinct allocations)\n";
} else {
    echo "FAIL delivery-allocation-linkage\n";
    $failures++;
}

// 4. getDonationAllocations() 應回傳 3 筆子單，且各自帶有 items 明細。
$fetchedAllocations = $donationModel->getDonationAllocations($donationId);
$allHaveItems = count($fetchedAllocations) === 3 && array_reduce($fetchedAllocations, static function ($carry, $allocation) {
    return $carry && !empty($allocation['items']);
}, true);
if ($allHaveItems) {
    echo "PASS get-donation-allocations-returns-items\n";
} else {
    echo "FAIL get-donation-allocations-returns-items\n";
    $failures++;
}

// 5. 非拆單情境（split_count=1）應維持既有行為：僅產生 1 筆 delivery，allocation_id 可為 NULL。
$secondRow = $conn->query('SELECT donation_id FROM donations WHERE status = "approved" AND donation_id != ' . $donationId . ' LIMIT 1');
$secondDonation = $secondRow ? $secondRow->fetch_assoc() : null;
if ($secondDonation) {
    $secondDonationId = (int) $secondDonation['donation_id'];
    $conn->query("UPDATE donations SET status = 'assessed' WHERE donation_id = {$secondDonationId}");
    $publishedNoSplit = $donationModel->publishDonation($secondDonationId, ['split_count' => 1, 'delivery_method' => 'volunteer', 'urgency' => 'normal']);
    $noSplitAllocationCount = $conn->query("SELECT COUNT(*) AS total FROM donation_allocations WHERE donation_id = {$secondDonationId}")->fetch_assoc();
    if ($publishedNoSplit && (int) $noSplitAllocationCount['total'] <= 1) {
        echo "PASS legacy-non-split-publish-unaffected (donation #{$secondDonationId})\n";
    } else {
        echo "FAIL legacy-non-split-publish-unaffected (donation #{$secondDonationId})\n";
        $failures++;
    }
} else {
    echo "SKIP legacy-non-split-publish-unaffected (no second approved donation fixture available)\n";
}

$conn->close();
shellOk(escapeshellarg($mysqlBin) . ' -u ' . escapeshellarg(DB_USER) . ' -e ' . escapeshellarg("DROP DATABASE IF EXISTS {$testDbName};"));

if ($failures > 0) {
    echo "\n{$failures} test(s) failed.\n";
    exit(1);
}

echo "\nAll order-split tests passed.\n";
