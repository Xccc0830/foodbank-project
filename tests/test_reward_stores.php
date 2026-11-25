<?php
// 使用專用測試資料庫；測試資料在交易結束後回滾。
require_once __DIR__ . '/../src/models/RewardModel.php';
define('BASE_PATH', dirname(__DIR__));

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
function rewardRuleText($offer) {
    return $offer['rule_type'];
}
function renderStoreList($rewardModel, $params) {
    $_GET = $params;
    ob_start();
    include BASE_PATH . '/src/views/reward_stores.php';
    return ob_get_clean();
}

Transaction::begin($db);
try {
    $db->query("UPDATE users SET default_pickup_address = '測試店址', open_days = '1,3,5', business_hours = '09:00-18:00', closed_days = '國定假日' WHERE user_id = 11");
    $base = ['title' => '商家清單測試', 'description' => '使用說明', 'category' => 'discount', 'stock' => null, 'retail_price' => 100];
    $direct = $model->createDonorReward(11, array_replace($base, ['title' => '搜尋測試直換']) + ['rule_type' => 'direct_exchange', 'min_spend' => 0, 'cost_points' => 80]);
    $cash = $model->createDonorReward(11, array_replace($base, ['title' => '搜尋測試折抵']) + ['rule_type' => 'min_spend_discount', 'min_spend' => 100, 'discount_amount' => 20, 'cost_points' => 20]);
    $other = $model->createDonorReward(13, array_replace($base, ['title' => '搜尋測試打折']) + ['rule_type' => 'min_spend_percentage', 'min_spend' => 150, 'discount_rate' => '0.90', 'cost_points' => 15]);
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
    $html = renderStoreList($model, ['store_search' => '測試店址', 'store_rule' => 'min_spend_discount']);
    storeCheck('地址搜尋與優惠篩選同時生效', strpos($html, '搜尋測試折抵') !== false
        && strpos($html, '搜尋測試直換') === false && strpos($html, '搜尋測試打折') === false);
    $html = renderStoreList($model, ['store_search' => '搜尋測試打折']);
    storeCheck('可依優惠名稱搜尋', strpos($html, '搜尋測試打折') !== false && strpos($html, '搜尋測試折抵') === false);
    $html = renderStoreList($model, ['store_search' => '<script>']);
    storeCheck('無結果提示與搜尋文字跳脫', strpos($html, '沒有符合條件') !== false
        && strpos($html, '&lt;script&gt;') !== false && strpos($html, '<script>') === false);
    $html = renderStoreList($model, ['store_rule' => 'invalid']);
    storeCheck('無效的優惠篩選有提示', strpos($html, '優惠方式無效') !== false);
    $db->query("UPDATE users SET status = 'inactive' WHERE user_id = 13");
    storeCheck('停用店家不列入清單', !findOffer($model->getPartnerStoreOffers(), $other));
} finally {
    Transaction::rollback($db);
}
exit($failed ? 1 : 0);
