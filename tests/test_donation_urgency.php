<?php
require_once __DIR__ . '/../src/models/DonationModel.php';

$model = new DonationModel();
$db = $GLOBALS['db']->getConnection();
$donorResult = $db->query("SELECT user_id FROM users WHERE user_id = 8 LIMIT 1");
$donor = $donorResult ? $donorResult->fetch_assoc() : null;
if (!$donor) {
    echo "SKIP donation-urgency-tests (donor fixture unavailable)\n";
    exit(0);
}

$failed = false;
function urgencyCheck($label, $ok) {
    echo ($ok ? 'PASS' : 'FAIL') . ' - ' . $label . PHP_EOL;
    if (!$ok) {
        $GLOBALS['failed'] = true;
    }
}

Transaction::begin($db);
try {
    $donorId = (int) $donor['user_id'];
    $db->query("UPDATE donations SET donation_date = DATE_SUB(NOW(), INTERVAL 2 MONTH) WHERE donor_id = {$donorId} AND (urgency = 'urgent' OR is_urgent = 1)");
    $base = [
        'donor_id' => $donorId,
        'donor_name' => '緊急額度測試',
        'donation_type' => 'food',
        'item_name' => '測試物資',
        'quantity' => 1,
        'unit' => '箱',
        'urgency' => 'urgent',
    ];
    $created = [];
    for ($i = 0; $i < DonationModel::URGENT_MONTHLY_LIMIT; $i++) {
        $created[] = $model->addDonation($base);
    }
    urgencyCheck('本月前五單緊急申請成功', count(array_filter($created)) === DonationModel::URGENT_MONTHLY_LIMIT);
    urgencyCheck('第六單緊急申請遭後端拒絕', $model->addDonation($base) === false);
    urgencyCheck('超額嘗試寫入政策稽核紀錄', (int) $db->query(
        "SELECT COUNT(*) AS total FROM audit_logs WHERE user_id = {$donorId} AND action_type = 'policy_urgent_limit_attempt'"
    )->fetch_assoc()['total'] === 1);
    urgencyCheck('超額嘗試通知食物銀行人員', (int) $db->query(
        "SELECT COUNT(*) AS total FROM notifications WHERE title = '緊急運送額度稽核' AND message LIKE '%" . $donorId . "%'"
    )->fetch_assoc()['total'] > 0);
} finally {
    Transaction::rollback($db);
}

exit($failed ? 1 : 0);
