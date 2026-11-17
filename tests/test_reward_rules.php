<?php
// 使用專用測試資料庫；測試資料在交易結束後回滾。
require_once __DIR__ . '/../src/models/RewardModel.php';

$model = new RewardModel();
$db = $GLOBALS['db']->getConnection();
$failed = false;
function ruleCheck($label, $ok) {
    echo ($ok ? 'PASS' : 'FAIL') . ' - ' . $label . PHP_EOL;
    if (!$ok) $GLOBALS['failed'] = true;
}

$base = ['title' => '規則測試', 'description' => '', 'category' => 'discount', 'stock' => null];
Transaction::begin($db);
try {
    $db->query("INSERT INTO point_transactions (user_id, points, transaction_type, description) VALUES (12, 1000, 'earned', '規則測試')");
    $cash = array_replace($base, ['description' => '僅限本店使用', 'rule_type' => 'min_spend_discount', 'min_spend' => 100, 'discount_amount' => 20, 'cost_points' => 20]);
    ruleCheck('折抵金額必須等於點數', !$model->createDonorReward(11, array_replace($cash, ['cost_points' => 19])));
    ruleCheck('低消不能小於折抵', !$model->createDonorReward(11, array_replace($cash, ['min_spend' => 19])));
    ruleCheck('直換低消必須為零', !$model->createDonorReward(11, $base + ['rule_type' => 'direct_exchange', 'min_spend' => 1, 'cost_points' => 10]));
    $percent = $base + ['rule_type' => 'min_spend_percentage', 'min_spend' => 150, 'discount_rate' => '0.90', 'cost_points' => 15];
    ruleCheck('折扣比例不能為 1', !$model->createDonorReward(11, array_replace($percent, ['discount_rate' => '1.00'])));
    ruleCheck('打折必須有低消', !$model->createDonorReward(11, array_replace($percent, ['min_spend' => 0])));
    $item = $model->createDonorReward(11, $cash);
    $otherItem = $model->createDonorReward(13, $percent);
    $catalog = $model->getAvailableRewards();
    $cashRows = array_values(array_filter($catalog, static function ($row) use ($item) {
        return $row['source_type'] === 'donor' && (int) $row['source_id'] === (int) $item;
    }));
    $otherRows = array_values(array_filter($catalog, static function ($row) use ($otherItem) {
        return $row['source_type'] === 'donor' && (int) $row['source_id'] === (int) $otherItem;
    }));
    ruleCheck('不同商家可獨立設定優惠並顯示店名', count($cashRows) === 1 && count($otherRows) === 1
        && $cashRows[0]['rule_type'] === 'min_spend_discount' && $otherRows[0]['rule_type'] === 'min_spend_percentage'
        && !empty($cashRows[0]['donor_name']) && !empty($otherRows[0]['donor_name']));
    $claim = $model->redeem(12, 'donor', $item);
    ruleCheck('建立並領取折抵券', $item && is_array($claim));
    if (!$item || !is_array($claim)) throw new RuntimeException('無法繼續測試折抵券');
    ruleCheck('編輯時也檢查規則', !$model->updateDonorReward($item, 11, array_replace($cash, ['cost_points' => 19])));
    $token = $claim['token'];
    $detail = $model->getPendingClaimById($claim['claim_id'], 12);
    ruleCheck('券面顯示提供店家及使用說明', !empty($detail['donor_name']) && $detail['reward_description'] === '僅限本店使用');
    $token = $detail['token_value'];
    ruleCheck('其他店家無法查詢', !$model->previewClaim($token, 13, 'member')['success']);
    ruleCheck('必須輸入金額', !$model->fulfillByToken($token, 11, 'member')['success']);
    ruleCheck('低於門檻不能核銷', !$model->fulfillByToken($token, 11, 'member', '99.99')['success']);
    ruleCheck('非法金額不能核銷', !$model->fulfillByToken($token, 11, 'member', '100.001')['success']);
    ruleCheck('折抵券不能走標記完成捷徑', !$model->updateDonorRedemptionStatus(11, $claim['claim_id'], 'fulfilled'));
    ruleCheck('其他店家不能編輯方案', !$model->updateDonorReward($item, 13, array_replace($cash, ['discount_amount' => 30, 'cost_points' => 30])));
    ruleCheck('更新方案成功', $model->updateDonorReward($item, 11, array_replace($cash, ['discount_amount' => 30, 'cost_points' => 30, 'description' => '新說明'])));
    $detail = $model->getPendingClaimById($claim['claim_id'], 12);
    $token = $detail['token_value'];
    ruleCheck('券面保留領券當時的說明', $detail['reward_description'] === '僅限本店使用');
    ruleCheck('依領券當時的規則核銷', $model->fulfillByToken($token, 11, 'member', '100.00')['success']);
    $saved = $db->query('SELECT bill_amount, final_cash_paid FROM reward_claims WHERE claim_id = ' . (int) $claim['claim_id'])->fetch_assoc();
    ruleCheck('折抵金額儲存正確', $saved['bill_amount'] === '100.00' && $saved['final_cash_paid'] === '80.00');
    ruleCheck('不能重複核銷', !$model->fulfillByToken($token, 11, 'member', '100')['success']);

    $item = $model->createDonorReward(11, $percent);
    $claim = $model->redeem(12, 'donor', $item);
    ruleCheck('建立並領取打折券', $item && is_array($claim));
    if (!$item || !is_array($claim)) throw new RuntimeException('無法繼續測試打折券');
    ruleCheck('打折低消檢查', !$model->fulfillByToken($claim['token'], 11, 'member', '149.99')['success']);
    ruleCheck('打折券可在門檻核銷', $model->fulfillByToken($claim['token'], 11, 'member', '155.00')['success']);
    $saved = $db->query('SELECT bill_amount, final_cash_paid FROM reward_claims WHERE claim_id = ' . (int) $claim['claim_id'])->fetch_assoc();
    ruleCheck('打折四捨五入且儲存正確', $saved['bill_amount'] === '155.00' && $saved['final_cash_paid'] === '140.00');

    $direct = $base + ['rule_type' => 'direct_exchange', 'min_spend' => 0, 'cost_points' => 10];
    $item = $model->createDonorReward(11, $direct);
    $claim = $model->redeem(12, 'donor', $item);
    ruleCheck('直換無需帳單', $claim && $model->fulfillByToken($claim['token'], 11, 'member')['success']);

    $db->query("INSERT INTO reward_catalog (title, cost_points, status) VALUES ('官辦禮品測試', 5, 'active')");
    $claim = $model->redeem(12, 'foodbank', (int) $db->insert_id);
    ruleCheck('官辦禮品只能由職員核銷', $claim && !$model->fulfillByToken($claim['token'], 11, 'member')['success']);
    ruleCheck('官辦禮品無需帳單', $claim && $model->fulfillByToken($claim['token'], 3, 'foodbank_staff')['success']);
} finally {
    Transaction::rollback($db);
}
exit($failed ? 1 : 0);
