<?php
// 物資單「婉拒 → 修改 → 重新送審」流程測試；全程在交易內執行，結束後復原。
require __DIR__ . '/../src/models/DonationModel.php';

$model = new DonationModel();
$db = $GLOBALS['db']->getConnection();

function check($label, $ok) {
    echo ($ok ? 'PASS' : 'FAIL') . ' - ' . $label . PHP_EOL;
    if (!$ok) {
        $GLOBALS['failed'] = true;
    }
}

$donor = $db->query("SELECT user_id FROM users LIMIT 1")->fetch_assoc();
$donorId = (int) $donor['user_id'];

$db->begin_transaction();
try {
    $id = $model->addDonation([
        'donor_id' => $donorId, 'donor_name' => '測試商家', 'donation_type' => 'food',
        'item_name' => '白米', 'quantity' => 5, 'unit' => '包', 'expiry_date' => '2020-01-01',
    ]);
    check('建立後狀態為 submitted', DonationModel::lifecycleState($model->getDonationById($id)) === 'submitted');

    check('審核通過前不能重送', $model->resubmitDonation($id, $donorId, ['unit' => '箱']) !== true);

    $model->reviewMaterialDonation($id, 'rejected', '期限已過', '', ['expiry_date']);
    check('婉拒後狀態為 rejected', DonationModel::lifecycleState($model->getDonationById($id)) === 'rejected');

    check('只能改被標記的欄位', is_string($model->resubmitDonation($id, $donorId, ['unit' => '箱'])));
    check('別的商家不能重送', is_string($model->resubmitDonation($id, $donorId + 9999, ['expiry_date' => '2030-01-01'])));

    check('改被標記的欄位可重送', $model->resubmitDonation($id, $donorId, ['expiry_date' => '2030-01-01', 'unit' => '箱']) === true);
    $row = $model->getDonationById($id);
    check('重送後回到 submitted', DonationModel::lifecycleState($row) === 'submitted');
    check('版本號為 2', (int) $row['revision_no'] === 2);
    check('未標記欄位沒被改動', $row['unit'] === '包');
    check('重送後不能再重送', is_string($model->resubmitDonation($id, $donorId, ['expiry_date' => '2031-01-01'])));

    $defaultOk = $model->setDefaultPickupAddress($donorId, '台北市信義區松仁路1號')
        && $model->getDefaultPickupAddress($donorId) === '台北市信義區松仁路1號';
    check('可儲存並讀回預設取貨地址', $defaultOk);
    check('空地址不會被存成預設', !$model->setDefaultPickupAddress($donorId, '  '));

    $events = array_reverse(array_column($model->getRevisions($id), 'event'));
    check('歷史依序為 submitted/rejected/resubmitted', $events === ['submitted', 'rejected', 'resubmitted']);
} finally {
    $db->rollback();
}
exit(empty($failed) ? 0 : 1);
