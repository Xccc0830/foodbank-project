<?php
// 騎手位置回傳權限與多站路線排序。全程在交易內執行，結束後復原。
require_once __DIR__ . '/../src/models/DonationModel.php';
require_once __DIR__ . '/../src/models/DeliveryModel.php';
require_once __DIR__ . '/../src/helpers/RiderLocationHelper.php';

$donationModel = new DonationModel();
$deliveryModel = new DeliveryModel();
$db = $GLOBALS['db']->getConnection();

function check($label, $ok) {
    echo ($ok ? 'PASS' : 'FAIL') . ' - ' . $label . PHP_EOL;
    if (!$ok) {
        $GLOBALS['failed'] = true;
    }
}

$donorId = 8;
$riderId = 6; // 用沒有真實配送單的會員，避免被實際資料影響測試
$staffId = 7;

// 建表是 DDL，會讓 MySQL 自動提交，必須在開啟交易之前先做
RiderLocationHelper::ensureTable($db);
$created = ['deliveries' => [], 'donations' => []];
$db->begin_transaction();
try {
    $make = function ($pickup, $dropoff) use (&$created, $donationModel, $deliveryModel, $donorId, $staffId) {
        $donationId = $donationModel->addDonation([
            'donor_id' => $donorId, 'donor_name' => '測試商家', 'donation_type' => 'food',
            'item_name' => '麵包', 'quantity' => 3, 'unit' => '袋',
        ]);
        $created['donations'][] = $donationId;
        $deliveryId = $deliveryModel->createDelivery([
            'donation_id' => $donationId, 'delivery_method' => 'volunteer', 'created_by' => $staffId,
            'vehicle_type' => 'motorcycle', 'total_distance_km' => 3, 'weight_kg' => 2, 'urgency' => 'normal',
            'pickup_address' => $pickup, 'delivery_address' => $dropoff,
        ]);
        $created['deliveries'][] = $deliveryId;
        return $deliveryId;
    };
    $first = $make('台北市信義區松仁路1號', '台北市信義區市府路1號');

    check('沒接單的騎手不會被記錄位置', !RiderLocationHelper::save($db, $riderId, 25.03, 121.56));
    $deliveryModel->claimDelivery($first, $riderId);
    check('接單後可記錄位置', RiderLocationHelper::save($db, $riderId, 25.03, 121.56));
    check('座標超出範圍會被拒絕', !RiderLocationHelper::save($db, $riderId, 125.0, 121.56));
    check('食物銀行看得到位置', RiderLocationHelper::forDelivery($db, $first, $staffId, 'foodbank_staff') !== null);
    check('該單商家看得到位置', RiderLocationHelper::forDelivery($db, $first, $donorId, 'member') !== null);
    check('其他會員看不到位置', RiderLocationHelper::forDelivery($db, $first, 99999, 'member') === null);

    $db->query("UPDATE rider_locations SET updated_at = DATE_SUB(NOW(), INTERVAL 20 MINUTE) WHERE volunteer_id = {$riderId}");
    check('超過 10 分鐘沒更新就不顯示', RiderLocationHelper::forDelivery($db, $first, $staffId, 'foodbank_staff') === null);

    $second = $make('台北市大安區忠孝東路四段1號', '台北市大安區仁愛路四段1號');
    $deliveryModel->claimDelivery($second, $riderId);
    $route = RiderLocationHelper::planRoute($db, $riderId, 25.03, 121.56);
    check('兩張單共排出 4 站', count($route) === 4);
    $seen = [];
    $orderOk = true;
    foreach ($route as $stop) {
        if ($stop['type'] === 'dropoff' && empty($seen[$stop['delivery_id']])) {
            $orderOk = false;
        }
        if ($stop['type'] === 'pickup') {
            $seen[$stop['delivery_id']] = true;
        }
    }
    check('每張單都先取貨再送達', $orderOk);
} finally {
    $db->rollback();
    // 模型建立時可能有 DDL 造成自動提交，rollback 不保證有效，所以再明確清掉這次建立的資料
    if ($created['deliveries']) {
        $db->query('DELETE FROM deliveries WHERE delivery_id IN (' . implode(',', array_map('intval', $created['deliveries'])) . ')');
    }
    if ($created['donations']) {
        $db->query('DELETE FROM donations WHERE donation_id IN (' . implode(',', array_map('intval', $created['donations'])) . ')');
    }
    $db->query("DELETE FROM rider_locations WHERE volunteer_id = {$riderId}");
}

if (!empty($failed)) {
    exit(1);
}
echo PHP_EOL . 'All rider location tests passed.' . PHP_EOL;
