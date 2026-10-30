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
check('數量 0 會報錯', strpos(messages(SpecCheckHelper::check(['quantity' => 0], $today)), '大於 0') !== false);

$donationModel = new DonationModel();
$deliveryModel = new DeliveryModel();
$adModel = new AdvertisementModel();
$thanksModel = new ThanksModel();
$db = $GLOBALS['db']->getConnection();
$donorId = 8; $riderId = 12; $staffId = 7;

$db->begin_transaction();
try {
    $memberAd = $adModel->create($donorId, '測試廣告', '內容', '', null, '', '');
    $staffAd = $adModel->create($staffId, '官方廣告', '內容', '', null, '', '', true);
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
}
exit(empty($failed) ? 0 : 1);
