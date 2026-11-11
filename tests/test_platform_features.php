<?php
// 規格防呆檢查、廣告審核、感謝留言。資料庫部分在交易內執行，結束後復原。
require_once __DIR__ . '/../src/helpers/SpecCheckHelper.php';
require_once __DIR__ . '/../src/models/DonationModel.php';
require_once __DIR__ . '/../src/models/DeliveryModel.php';
require_once __DIR__ . '/../src/models/AdvertisementModel.php';
require_once __DIR__ . '/../src/models/ThanksModel.php';

function check($label, $ok) {
    echo ($ok ? 'PASS' : 'FAIL') . ' - ' . $label . PHP_EOL;
    if (!$ok) {
        $GLOBALS['failed'] = true;
    }
}
function messages($result) {
    return implode('|', array_column($result['warnings'], 'message'));
}

$today = '2026-10-30';
check('白米歸類為主食乾貨', SpecCheckHelper::check(['item_name' => '白米'], $today)['category'] === '主食乾貨');
check('已過期會報錯', strpos(messages(SpecCheckHelper::check(['expiry_date' => '2026-10-01'], $today)), '已過期') !== false);
check('明天到期會提醒', strpos(messages(SpecCheckHelper::check(['expiry_date' => '2026-10-31'], $today)), '2 天') !== false);
check('期限超過 3 年會提醒', strpos(messages(SpecCheckHelper::check(['expiry_date' => '2031-01-01'], $today)), '3 年') !== false);
check('麵包期限太長會提醒', strpos(messages(SpecCheckHelper::check(['item_name' => '吐司', 'expiry_date' => '2026-12-30'], $today)), '通常保存不到') !== false);
check('規格 5kg×3 與重量 2kg 差太多會提醒', strpos(messages(SpecCheckHelper::check(['item_name' => '白米', 'specification' => '5kg', 'quantity' => 3, 'weight_kg' => 2], $today)), '差異較大') !== false);
check('規格 500g×4 與重量 2kg 吻合不提醒', SpecCheckHelper::check(['specification' => '500g', 'quantity' => 4, 'weight_kg' => 2], $today)['warnings'] === []);
check('數量 0 會報錯', strpos(messages(SpecCheckHelper::check(['quantity' => 0], $today)), '1 以上的整數') !== false);
foreach (['-1', '0.1', '1/2'] as $bad) {
    check("數量 {$bad} 會報錯", isset(SpecCheckHelper::numberProblems(['quantity' => $bad])['quantity']));
    check("重量 {$bad} 會報錯", isset(SpecCheckHelper::numberProblems(['weight_kg' => $bad])['weight_kg']));
}
check('長 0.05 會報錯', isset(SpecCheckHelper::numberProblems(['length_cm' => '0.05'])['length_cm']));
check('長 12.5 可以', SpecCheckHelper::numberProblems(['length_cm' => '12.5', 'quantity' => '3', 'weight_kg' => '5']) === []);
check('數量 101 會提醒', strpos(messages(SpecCheckHelper::check(['quantity' => '101'], $today)), '超過 100') !== false);
check('數量 100 不提醒', SpecCheckHelper::check(['quantity' => '100'], $today)['warnings'] === []);

$donationModel = new DonationModel();
$deliveryModel = new DeliveryModel();
$adModel = new AdvertisementModel();
$thanksModel = new ThanksModel();
$db = $GLOBALS['db']->getConnection();
$donorId = 8; $riderId = 12; $staffId = 7;

// 建資料表欄位的指令（ALTER TABLE）會讓交易自動提交，rollback 不一定有效，所以結束時也明確刪除這次建立的資料
$memberAd = $staffAd = $donationId = $deliveryId = 0;
$notificationStart = (int) $db->query("SELECT COALESCE(MAX(notification_id), 0) AS m FROM notifications")->fetch_assoc()['m'];
$db->begin_transaction();
try {
    $memberAd = $adModel->create($donorId, '測試廣告', '這是測試用的廣告內容', '', null, '', '', false, true)['ad_id'] ?? 0;
    $staffAd = $adModel->create($staffId, '官方廣告', '這是測試用的官方廣告', '', null, '', '', true, true)['ad_id'] ?? 0;
    $statusOf = function ($id) use ($db) { return $db->query("SELECT status FROM advertisements WHERE ad_id = {$id}")->fetch_assoc()['status']; };
    check('會員送出的廣告先進入待審核', $statusOf($memberAd) === 'pending');
    check('食物銀行自己刊登的直接上架', $statusOf($staffAd) === 'active');
    $activeIds = array_column($adModel->getActiveAds(500), 'ad_id');
    check('待審核廣告不會出現在公開列表', !in_array($memberAd, $activeIds) && in_array($staffAd, $activeIds));
    check('退回必須寫原因', !$adModel->reject($memberAd, $staffId, ''));
    check('核准後上架並記錄費用', $adModel->approve($memberAd, $staffId, 500) && $statusOf($memberAd) === 'active');
    check('核准後不能再退回', !$adModel->reject($memberAd, $staffId, '太晚'));

    $donationId = $donationModel->addDonation(['donor_id' => $donorId, 'donor_name' => '測試商家', 'donation_type' => 'food', 'item_name' => '麵包', 'quantity' => 3, 'unit' => '袋']);
    $deliveryId = $deliveryModel->createDelivery([
        'donation_id' => $donationId, 'delivery_method' => 'volunteer', 'created_by' => $staffId,
        'vehicle_type' => 'motorcycle', 'total_distance_km' => 3, 'weight_kg' => 2, 'urgency' => 'normal',
        'pickup_address' => 'A', 'delivery_address' => 'B',
    ]);
    check('未送達的配送不能留感謝', is_string($thanksModel->send($deliveryId, $staffId, '謝謝')));
    $deliveryModel->claimDelivery($deliveryId, $riderId);
    $deliveryModel->markArrivedAtPickup($deliveryId, $riderId);
    $deliveryModel->confirmPickup($deliveryId, $riderId, 'pickups/x.jpg');
    $deliveryModel->completeDelivery($deliveryId, $staffId);
    check('空白感謝被擋下', is_string($thanksModel->send($deliveryId, $staffId, '  ')));
    check('已送達可留感謝', $thanksModel->send($deliveryId, $staffId, '謝謝您的愛心！') === true);
    check('同一筆不能重複感謝', is_string($thanksModel->send($deliveryId, $staffId, '再一次')));
    check('商家看得到感謝', count($thanksModel->forDonor($donorId)) >= 1);
    $notified = (int) $db->query("SELECT COUNT(*) c FROM notifications WHERE user_id = {$donorId} AND title = '收到一則感謝'")->fetch_assoc()['c'];
    check('商家收到感謝通知', $notified >= 1);
} finally {
    $db->rollback();
    if ($deliveryId) {
        $db->query("DELETE FROM thanks_messages WHERE delivery_id = " . (int) $deliveryId);
        $db->query("DELETE FROM deliveries WHERE delivery_id = " . (int) $deliveryId);
    }
    if ($donationId) {
        $db->query("DELETE FROM donation_allocation_items WHERE allocation_id IN (SELECT allocation_id FROM donation_allocations WHERE donation_id = " . (int) $donationId . ")");
        $db->query("DELETE FROM donation_allocations WHERE donation_id = " . (int) $donationId);
        $db->query("DELETE FROM donations WHERE donation_id = " . (int) $donationId);
    }
    foreach ([$memberAd, $staffAd] as $adId) {
        if ($adId) {
            $db->query("DELETE FROM advertisements WHERE ad_id = " . (int) $adId);
        }
    }
    $db->query("DELETE FROM notifications WHERE notification_id > {$notificationStart} AND user_id IN ({$donorId}, {$riderId}, {$staffId})");
}
exit(empty($failed) ? 0 : 1);
