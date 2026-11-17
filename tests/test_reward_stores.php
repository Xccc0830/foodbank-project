<?php
// 使用專用測試資料庫；測試資料在交易結束後回滾。
require_once __DIR__ . '/../src/models/RewardModel.php';

$model = new RewardModel();
$db = $GLOBALS['db']->getConnection();
$failed = false;
function storeCheck($label, $ok) {
    echo ($ok ? 'PASS' : 'FAIL') . ' - ' . $label . PHP_EOL;
    if (!$ok) $GLOBALS['failed'] = true;
}
function findOffer($offers, $id) {
    foreach ($offers as $offer) {
        if ((int) $offer['item_id'] === (int) $id) return $offer;
    }
    return null;
}

Transaction::begin($db);
try {
    $db->query("UPDATE users SET default_pickup_address = '測試店址', open_days = '1,3,5', business_hours = '09:00-18:00', closed_days = '國定假日' WHERE user_id = 11");
    $base = ['title' => '商家清單測試', 'description' => '使用說明', 'category' => 'discount', 'stock' => null];
    $direct = $model->createDonorReward(11, $base + ['rule_type' => 'direct_exchange', 'min_spend' => 0, 'cost_points' => 80]);
    $cash = $model->createDonorReward(11, $base + ['rule_type' => 'min_spend_discount', 'min_spend' => 100, 'discount_amount' => 20, 'cost_points' => 20]);
    $other = $model->createDonorReward(13, $base + ['rule_type' => 'min_spend_percentage', 'min_spend' => 150, 'discount_rate' => '0.90', 'cost_points' => 15]);
    $soldOut = $model->createDonorReward(11, array_replace($base, ['stock' => 0]) + ['rule_type' => 'direct_exchange', 'min_spend' => 0, 'cost_points' => 1]);
    $inactive = $model->createDonorReward(11, $base + ['rule_type' => 'direct_exchange', 'min_spend' => 0, 'cost_points' => 2]);
    $model->deleteDonorReward($inactive, 11);

    $offers = $model->getPartnerStoreOffers();
    $row = findOffer($offers, $direct);
    storeCheck('同一店家可列出不同優惠', $row && findOffer($offers, $cash) && (int) findOffer($offers, $cash)['donor_id'] === 11);
    storeCheck('顯示店家登記地址與營業資訊', $row && $row['default_pickup_address'] === '測試店址'
        && $row['open_days'] === '1,3,5' && $row['business_hours'] === '09:00-18:00' && $row['closed_days'] === '國定假日');
    storeCheck('列出不同店家的優惠規則', findOffer($offers, $other) && findOffer($offers, $other)['rule_type'] === 'min_spend_percentage');
    storeCheck('已售罄方案保留供持券者查詢並標示庫存', findOffer($offers, $soldOut) && (int) findOffer($offers, $soldOut)['stock'] === 0);
    storeCheck('不列出停用的方案', !findOffer($offers, $inactive));
    $db->query("UPDATE users SET status = 'inactive' WHERE user_id = 13");
    storeCheck('停用店家不列入清單', !findOffer($model->getPartnerStoreOffers(), $other));
} finally {
    Transaction::rollback($db);
}
exit($failed ? 1 : 0);
