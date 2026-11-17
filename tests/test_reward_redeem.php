<?php
// 兌換心意幣：成功時扣幣、建立兌換券並回傳編號；幣不足或庫存用完時不扣幣。
// 全程在交易內執行，結束後復原（redeem() 自己的交易會變成內層，不會提前存檔）。
require_once __DIR__ . '/../src/models/RewardModel.php';

$rewardModel = new RewardModel();
$db = $GLOBALS['db']->getConnection();

function check($label, $ok) {
    echo ($ok ? 'PASS' : 'FAIL') . ' - ' . $label . PHP_EOL;
    if (!$ok) {
        $GLOBALS['failed'] = true;
    }
}

$riderId = 12;
$maxClaimId = (int) $db->query("SELECT COALESCE(MAX(claim_id), 0) m FROM reward_claims")->fetch_assoc()['m'];

Transaction::begin($db);
try {
    $db->query("INSERT INTO reward_catalog (title, description, cost_points, stock, status) VALUES ('測試獎勵', '測試用', 5, 1, 'active')");
    $rewardId = (int) $db->insert_id;
    $balanceBefore = $rewardModel->getBalance($riderId);
    $db->query("INSERT INTO point_transactions (user_id, points, transaction_type, description) VALUES ({$riderId}, 10, 'earned', '測試入帳')");

    $claim = $rewardModel->redeem($riderId, 'foodbank', $rewardId);
    check('兌換成功', is_array($claim));
    check('回傳正確的兌換券編號', is_array($claim) && $claim['claim_id'] > $maxClaimId
        && (int) $db->query("SELECT COUNT(*) c FROM reward_claims WHERE claim_id = " . (int) $claim['claim_id'] . " AND reward_id = {$rewardId}")->fetch_assoc()['c'] === 1);
    $riderClaims = $rewardModel->getRedemptionsByUser($riderId);
    check('舊制兌換券出現在騎手歷史紀錄', is_array($claim) && count(array_filter($riderClaims, function ($row) use ($claim) {
        return (int) $row['claim_id'] === (int) $claim['claim_id'] && $row['source_type'] === 'foodbank';
    })) === 1);
    check('扣掉 5 枚心意幣', $rewardModel->getBalance($riderId) === $balanceBefore + 5);
    check('庫存減為 0', (int) $db->query("SELECT stock FROM reward_catalog WHERE reward_id = {$rewardId}")->fetch_assoc()['stock'] === 0);

    $balanceMid = $rewardModel->getBalance($riderId);
    check('庫存用完不能再兌換', $rewardModel->redeem($riderId, 'foodbank', $rewardId) === false);
    check('兌換失敗不扣幣', $rewardModel->getBalance($riderId) === $balanceMid);

    $db->query("UPDATE reward_catalog SET stock = NULL, cost_points = 999999 WHERE reward_id = {$rewardId}");
    check('心意幣不足不能兌換', $rewardModel->redeem($riderId, 'foodbank', $rewardId) === false);
    check('心意幣不足時不扣幣', $rewardModel->getBalance($riderId) === $balanceMid);

    // 愛心商家提供的優惠：GO Rider 兌換後，商家的「獎勵兌換紀錄」要看得到，並可標記完成
    $storeId = 11;
    $otherStoreId = 13;
    $db->query("INSERT INTO donor_reward_items (donor_id, title, description, cost_points, stock, category, status) VALUES ({$storeId}, '測試商家優惠', '測試用', 3, NULL, 'discount', 'active')");
    $donorItemId = (int) $db->insert_id;
    $donorClaim = $rewardModel->redeem($riderId, 'donor', $donorItemId);
    check('兌換商家優惠成功', is_array($donorClaim));
    $storeRows = array_values(array_filter($rewardModel->getDonorRedemptions($storeId), function ($row) use ($donorClaim) {
        return is_array($donorClaim) && (int) $row['redemption_id'] === (int) $donorClaim['claim_id'];
    }));
    check('商家看得到 GO Rider 的兌換紀錄', count($storeRows) === 1 && $storeRows[0]['status'] === 'pending' && (int) $storeRows[0]['points_spent'] === 3);
    check('其他商家看不到這筆兌換', !in_array($donorClaim['claim_id'] ?? 0, array_map('intval', array_column($rewardModel->getDonorRedemptions($otherStoreId), 'redemption_id')), true));
    check('其他商家不能標記完成', !$rewardModel->updateDonorRedemptionStatus($otherStoreId, (int) ($donorClaim['claim_id'] ?? 0), 'fulfilled'));
    check('提供優惠的商家可以標記完成', $rewardModel->updateDonorRedemptionStatus($storeId, (int) ($donorClaim['claim_id'] ?? 0), 'fulfilled'));
} finally {
    Transaction::rollback($db);
}
exit(empty($failed) ? 0 : 1);
