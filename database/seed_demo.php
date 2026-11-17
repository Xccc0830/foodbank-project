<?php
/**
 * 建立示範資料：照系統的正常流程走（商家送出 → 食物銀行審核／發布 → GO Rider 接單、取貨、送達 → 確認收貨 → 清點入庫），
 * 讓物資審核、配送任務、訂單追蹤、庫存、心意幣、通知各頁面都有彼此對得上的資料。
 *
 * 用法（在專案根目錄）：
 *   /Applications/XAMPP/xamppfiles/bin/php database/seed_demo.php
 * 資料庫裡已經有捐贈單時不會執行，避免重複建立；確定要再加一批請加 --force。
 *
 * 使用的示範帳號：商家 store_demo（幸福超市）、love_store_001（愛心商家001）、donor（捐贈店家）；
 * GO Rider courier_demo；食物銀行人員 official。
 */
require_once __DIR__ . '/../src/models/DonationModel.php';
require_once __DIR__ . '/../src/models/DeliveryModel.php';
require_once __DIR__ . '/../src/models/InventoryReceiptModel.php';
require_once __DIR__ . '/../src/models/FoodbankProfileModel.php';
require_once __DIR__ . '/../src/helpers/SpecCheckHelper.php';

$donationModel = new DonationModel();
$deliveryModel = new DeliveryModel();
// 注意：$conn 是系統的資料庫物件，這裡的連線另外取名 $conn，避免蓋掉它
$conn = $GLOBALS['db']->getConnection();
$receiptModel = new InventoryReceiptModel($conn);

$existing = (int) $conn->query("SELECT COUNT(*) c FROM donations")->fetch_assoc()['c'];
if ($existing > 0 && !in_array('--force', $argv, true)) {
    exit("資料庫已有 {$existing} 筆捐贈單，為避免重複沒有執行。確定要再建立一批請加 --force。\n");
}

function userIdOf(mysqli $conn, $username) {
    $name = $conn->real_escape_string($username);
    $row = $conn->query("SELECT user_id FROM users WHERE username = '{$name}' LIMIT 1")->fetch_assoc();
    if (!$row) {
        exit("找不到示範帳號 {$username}，請先匯入 database/schema.sql。\n");
    }
    return (int) $row['user_id'];
}

function categoryIdOf(mysqli $conn, $name) {
    $name = $conn->real_escape_string($name);
    $row = $conn->query("SELECT category_id FROM item_categories WHERE category_name = '{$name}' LIMIT 1")->fetch_assoc();
    return $row ? (int) $row['category_id'] : null;
}

function step($ok, $label) {
    if (!$ok) {
        throw new RuntimeException("失敗：{$label}");
    }
    echo "  ✓ {$label}\n";
}

$store = userIdOf($conn, 'store_demo');
$loveStore = userIdOf($conn, 'love_store_001');
$donor = userIdOf($conn, 'donor');
$rider = userIdOf($conn, 'courier_demo');
$staff = userIdOf($conn, 'official');
$foodbankAddress = FoodbankProfileModel::DEFAULT_ADDRESS;
$photo = 'uploads/donations/donation_20260915_082056_5f97d060c728.jpg'; // 黑糖粉圓照片
$pickupPhoto = 'uploads/pickups/pickup_20261028_170127_33dd18a9f754.jpg';

/**
 * 商家送出一張物資捐贈單（與「物資捐贈」頁送出時寫入的欄位相同），回傳捐贈單編號。
 * $items: 每項 [品名, 分類, 保存方式 room/chilled/frozen, 數量, 單位, 單項重量kg, 效期天數]
 */
function submitDonation(DonationModel $model, mysqli $conn, array $order, array $items) {
    $first = $items[0];
    $summaryName = count($items) > 1 ? $first[0] . ' 等 ' . count($items) . ' 項' : $first[0];
    $totalWeight = array_sum(array_map(static function ($i) { return $i[5] * $i[3]; }, $items));
    $now = date('Y-m-d H:i:s');
    $data = [
        'donor_id' => $order['donor_id'],
        'donor_name' => $order['donor_name'],
        'donor_address' => $order['address'],
        'donation_type' => 'food',
        'quantity' => $first[3],
        'unit' => $first[4],
        'donation_date' => $now,
        'status' => 'pending',
        'evaluation_status' => 'pending',
        'delivery_method' => 'volunteer_assist',
        'current_status' => 'waiting_pickup',
        'split_count' => 1,
        'notes' => $order['notes'] ?? null,
        'created_at' => $now,
        'updated_at' => $now,
        'item_name' => $summaryName,
        'weight_kg' => $totalWeight,
        'expiry_date' => date('Y-m-d', strtotime('+' . min(array_column($items, 6)) . ' days')),
        'pickup_deadline' => date('Y-m-d H:i:s', strtotime('+1 day 18:00')),
        'delivery_option' => $order['delivery_option'] ?? 'volunteer_delivery',
        'vehicle_type' => $order['vehicle'] ?? 'motorcycle',
        'photo_path' => $order['photo'] ?? null,
        'need_inspection' => 1,
        'delivery_date' => date('Y-m-d'),
        'delivery_time' => $order['time'] ?? '15:00:00',
        'delivery_address' => $order['foodbank_address'],
        'estimated_distance_km' => $order['distance'],
        'estimated_duration_minutes' => (int) round($order['distance'] * 4 + 10),
        'is_urgent' => !empty($order['urgent']) ? 1 : 0,
    ];

    Transaction::begin($conn);
    $donationId = $model->addDonation($data);
    if (!$donationId) {
        Transaction::rollback($conn);
        return false;
    }
    $statement = $conn->prepare('INSERT INTO donation_items (donation_id, item_name, category_id, storage_condition, package_state, quantity, unit, item_weight_kg, expiry_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    foreach ($items as [$name, $category, $storage, $quantity, $unit, $weight, $expiryDays]) {
        $categoryId = categoryIdOf($conn, $category);
        $package = 'sealed';
        $quantity = (float) $quantity;
        $itemWeight = (float) ($weight * $quantity);
        $expiry = date('Y-m-d', strtotime("+{$expiryDays} days"));
        $statement->bind_param('isissdsds', $donationId, $name, $categoryId, $storage, $package, $quantity, $unit, $itemWeight, $expiry);
        if (!$statement->execute()) {
            Transaction::rollback($conn);
            return false;
        }
    }
    $statement->close();
    Transaction::commit($conn);
    return (int) $donationId;
}

function deliveryIdOf(mysqli $conn, $donationId) {
    $row = $conn->query("SELECT delivery_id FROM deliveries WHERE donation_id = " . (int) $donationId . " ORDER BY delivery_id LIMIT 1")->fetch_assoc();
    return $row ? (int) $row['delivery_id'] : 0;
}

$base = ['foodbank_address' => $foodbankAddress];

try {
    echo "1. 待審核：幸福超市 白米、罐頭\n";
    $id = submitDonation($donationModel, $conn, $base + [
        'donor_id' => $store, 'donor_name' => '幸福超市', 'address' => '臺北市文山區木柵路三段102號', 'distance' => 3.2,
        'notes' => '放在後門倉庫，請按門鈴。',
    ], [
        ['白米 2kg', '穀物', 'room', 10, '包', 2, 180],
        ['鮪魚罐頭', '罐頭食品', 'room', 24, '罐', 0.2, 365],
    ]);
    step($id, "送出捐贈單 #{$id}");

    echo "2. 已婉拒：愛心商家001 吐司（效期太近）\n";
    $id = submitDonation($donationModel, $conn, $base + [
        'donor_id' => $loveStore, 'donor_name' => '愛心商家001', 'address' => '臺北市文山區興隆路四段10號', 'distance' => 1.5,
    ], [
        ['全麥吐司', '穀物', 'room', 8, '條', 0.5, 1],
    ]);
    step($id, "送出捐贈單 #{$id}");
    step($donationModel->reviewMaterialDonation($id, 'rejected', '效期只剩 1 天，來不及分送，請下次提早通知。', '', [], $staff), '食物銀行婉拒');

    echo "3. 已審核、待發布：捐贈店家 冷凍水餃\n";
    $id = submitDonation($donationModel, $conn, $base + [
        'donor_id' => $donor, 'donor_name' => '捐贈店家', 'address' => '臺北市文山區景興路200號', 'distance' => 4.6,
    ], [
        ['冷凍高麗菜水餃', '其他', 'frozen', 15, '包', 1, 60],
    ]);
    step($id, "送出捐贈單 #{$id}");
    step($donationModel->reviewMaterialDonation($id, 'accepted', '', 'volunteer_delivery', [], $staff), '食物銀行同意，由 GO Rider 配送');

    echo "4. 已發布、等 GO Rider 接單：幸福超市 鮮奶（緊急、冷藏）\n";
    $id = submitDonation($donationModel, $conn, $base + [
        'donor_id' => $store, 'donor_name' => '幸福超市', 'address' => '臺北市文山區木柵路三段102號', 'distance' => 3.2, 'urgent' => true,
        'notes' => '今天到期前要送到，請保持冷藏。', 'time' => '17:00:00',
    ], [
        ['鮮乳 936ml', '乳製品', 'chilled', 12, '瓶', 1, 2],
    ]);
    step($id, "送出捐贈單 #{$id}");
    step($donationModel->reviewMaterialDonation($id, 'accepted', '', 'volunteer_delivery', [], $staff), '食物銀行同意');
    step($donationModel->publishDonation($id, ['split_count' => 1, 'urgent_approved' => 1]), '發布配送任務（同意緊急加成）');

    echo "5. 配送中：愛心商家001 黑糖粉圓（GO Rider 已取貨）\n";
    $id = submitDonation($donationModel, $conn, $base + [
        'donor_id' => $loveStore, 'donor_name' => '愛心商家001', 'address' => '臺北市文山區興隆路四段10號', 'distance' => 1.5, 'photo' => $photo,
    ], [
        ['黑糖粉圓 3kg', '乾貨', 'room', 6, '包', 3, 90],
    ]);
    step($id, "送出捐贈單 #{$id}");
    step($donationModel->reviewMaterialDonation($id, 'accepted', '', 'volunteer_delivery', [], $staff), '食物銀行同意');
    step($donationModel->publishDonation($id, ['split_count' => 1]), '發布配送任務');
    $deliveryId = deliveryIdOf($conn, $id);
    step($deliveryModel->claimDelivery($deliveryId, $rider), "GO Rider 接單（配送 #{$deliveryId}）");
    step($deliveryModel->markArrivedAtPickup($deliveryId, $rider), 'GO Rider 到店');
    step($deliveryModel->confirmPickup($deliveryId, $rider, $pickupPhoto), 'GO Rider 拍照取貨');

    echo "6. 已完成並入庫：捐贈店家 蔬菜、蘋果（GO Rider 準時送達）\n";
    $id = submitDonation($donationModel, $conn, $base + [
        'donor_id' => $donor, 'donor_name' => '捐贈店家', 'address' => '臺北市文山區景興路200號', 'distance' => 4.6,
    ], [
        ['高麗菜', '蔬菜', 'chilled', 10, '顆', 1, 5],
        ['富士蘋果', '水果', 'chilled', 20, '顆', 0.3, 10],
    ]);
    step($id, "送出捐贈單 #{$id}");
    step($donationModel->reviewMaterialDonation($id, 'accepted', '', 'volunteer_delivery', [], $staff), '食物銀行同意');
    step($donationModel->publishDonation($id, ['split_count' => 1]), '發布配送任務');
    $deliveryId = deliveryIdOf($conn, $id);
    step($deliveryModel->claimDelivery($deliveryId, $rider), "GO Rider 接單（配送 #{$deliveryId}）");
    step($deliveryModel->markArrivedAtPickup($deliveryId, $rider), 'GO Rider 到店');
    step($deliveryModel->confirmPickup($deliveryId, $rider, $pickupPhoto), 'GO Rider 拍照取貨');
    step($deliveryModel->markArrivedAtDropoff($deliveryId, $rider), 'GO Rider 回報送達食物銀行');
    step($deliveryModel->completeDelivery($deliveryId, $staff), '食物銀行確認收貨，心意幣入帳');
    $categoryIds = [];
    foreach ($donationModel->getDonationItems($id) as $item) {
        $categoryIds[(int) $item['item_id']] = (int) $item['category_id'];
    }
    step($receiptModel->recordDonationInspection($id, $staff, $categoryIds), '清點入庫');

    echo "7. 已完成：幸福超市 泡麵（忠信自行派車，不發心意幣）\n";
    $id = submitDonation($donationModel, $conn, $base + [
        'donor_id' => $store, 'donor_name' => '幸福超市', 'address' => '臺北市文山區木柵路三段102號', 'distance' => 3.2, 'vehicle' => 'car',
    ], [
        ['泡麵（箱）', '乾貨', 'room', 5, '箱', 3, 120],
    ]);
    step($id, "送出捐贈單 #{$id}");
    step($donationModel->reviewMaterialDonation($id, 'accepted', '', 'food_bank_pickup', [], $staff), '食物銀行同意，由忠信自行派車');
    step($donationModel->publishDonation($id, ['split_count' => 1]), '建立派車任務');
    $deliveryId = deliveryIdOf($conn, $id);
    step($deliveryModel->completeDelivery($deliveryId, $staff), "確認忠信派車已完成（配送 #{$deliveryId}）");
} catch (Throwable $error) {
    echo "\n" . $error->getMessage() . "\n已建立的資料保留，可以用 phpMyAdmin 檢查或刪除。\n";
    exit(1);
}

echo "\n完成。可用 store_demo、love_store_001、donor（商家）、courier_demo（GO Rider）、official（食物銀行）登入查看。\n";
