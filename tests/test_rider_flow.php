<?php
// 騎手取送件流程：接單 → 已到店 → 拍照取貨 → 食物銀行確認收貨 → 通知原商家（只一次）。
// 全程在交易內執行，結束後復原。
require_once __DIR__ . '/../src/models/DonationModel.php';
require_once __DIR__ . '/../src/models/DeliveryModel.php';
require_once __DIR__ . '/../src/models/NotificationModel.php';

$donationModel = new DonationModel();
$deliveryModel = new DeliveryModel();
$db = $GLOBALS['db']->getConnection();
new NotificationModel($db);

function check($label, $ok) {
    echo ($ok ? 'PASS' : 'FAIL') . ' - ' . $label . PHP_EOL;
    if (!$ok) {
        $GLOBALS['failed'] = true;
    }
}

$donorId = 8;
$riderId = 12;
$staffId = 7;

$db->begin_transaction();
try {
    $donationId = $donationModel->addDonation([
        'donor_id' => $donorId, 'donor_name' => '測試商家', 'donation_type' => 'food',
        'item_name' => '麵包', 'quantity' => 3, 'unit' => '袋',
    ]);
    $deliveryId = $deliveryModel->createDelivery([
        'donation_id' => $donationId, 'delivery_method' => 'volunteer', 'created_by' => $staffId,
        'vehicle_type' => 'motorcycle', 'total_distance_km' => 3, 'weight_kg' => 2, 'urgency' => 'normal',
        'pickup_address' => '台北市信義區松仁路1號', 'delivery_address' => '台北市信義區市府路1號',
    ]);

    check('接單成功', $deliveryModel->claimDelivery($deliveryId, $riderId));
    check('沒按到店就不能取貨（即使有照片）', !$deliveryModel->confirmPickup($deliveryId, $riderId, 'pickups/x.jpg'));
    check('別的騎手不能標記到店', !$deliveryModel->markArrivedAtPickup($deliveryId, $riderId + 99));
    check('標記已到店', $deliveryModel->markArrivedAtPickup($deliveryId, $riderId));
    check('到店不能重複標記', !$deliveryModel->markArrivedAtPickup($deliveryId, $riderId));
    check('沒照片不能取貨', !$deliveryModel->confirmPickup($deliveryId, $riderId, ''));
    check('還沒取貨不能確認收貨', !$deliveryModel->completeDelivery($deliveryId, $staffId));
    check('有照片可取貨', $deliveryModel->confirmPickup($deliveryId, $riderId, 'pickups/x.jpg'));
    check('別的騎手不能回報送達', !$deliveryModel->markArrivedAtDropoff($deliveryId, $riderId + 99));
    check('回報已送達食物銀行', $deliveryModel->markArrivedAtDropoff($deliveryId, $riderId));
    check('送達不能重複回報', !$deliveryModel->markArrivedAtDropoff($deliveryId, $riderId));
    // 騎手在預估時間內送到，但食物銀行晚了才確認：仍算準時
    $db->query("UPDATE deliveries SET arrived_dropoff_at = NOW() - INTERVAL 2 HOUR, estimated_arrival_end = NOW() - INTERVAL 1 HOUR WHERE delivery_id = {$deliveryId}");
    check('食物銀行確認收貨', $deliveryModel->completeDelivery($deliveryId, $staffId));
    $earned = $db->query("SELECT description FROM point_transactions WHERE delivery_id = {$deliveryId} AND transaction_type = 'earned' LIMIT 1")->fetch_assoc();
    check('準時與否看騎手回報送達的時間', $earned && strpos($earned['description'], '準時送達') !== false);
    check('已配達的單不能再編輯或刪除', !$deliveryModel->canManageDelivery($deliveryId, $staffId, 'foodbank_staff'));

    $row = $db->query("SELECT status, received_confirmed_by FROM deliveries WHERE delivery_id = {$deliveryId}")->fetch_assoc();
    check('狀態為 delivered 並記下確認人', $row['status'] === 'delivered' && (int) $row['received_confirmed_by'] === $staffId);

    $count = function () use ($db, $donorId, $deliveryId) {
        return (int) $db->query("SELECT COUNT(*) c FROM notifications WHERE user_id = {$donorId} AND dedupe_key = 'delivered:{$deliveryId}'")->fetch_assoc()['c'];
    };
    check('商家收到「物資已成功送達」通知', $count() === 1);
    check('重複確認不會重複通知', !$deliveryModel->completeDelivery($deliveryId, $staffId) && $count() === 1);
} finally {
    $db->rollback();
}
exit(empty($failed) ? 0 : 1);
