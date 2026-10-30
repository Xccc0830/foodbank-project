<?php
// 身分證驗證：檢查碼、加密保存、去重、審核流程。全程在交易內執行，結束後復原。
require_once __DIR__ . '/../src/models/IdVerificationModel.php';

$model = new IdVerificationModel();
$db = $GLOBALS['db']->getConnection();

function check($label, $ok) {
    echo ($ok ? 'PASS' : 'FAIL') . ' - ' . $label . PHP_EOL;
    if (!$ok) {
        $GLOBALS['failed'] = true;
    }
}

check('正確字號通過檢查碼', IdNumberHelper::isValid('A123456789') && IdNumberHelper::isValid('f131104093'));
check('錯誤檢查碼被擋下', !IdNumberHelper::isValid('A123456788') && !IdNumberHelper::isValid('123') && !IdNumberHelper::isValid(''));
check('遮罩只留前 3 後 3', IdNumberHelper::mask('A123456789') === 'A12****789');
check('加密後可解回、且不是明文', ($enc = IdNumberHelper::encrypt('A123456789')) !== 'A123456789' && strpos($enc, 'A123456789') === false && IdNumberHelper::decrypt($enc) === 'A123456789');
check('被竄改的密文解不開', IdNumberHelper::decrypt(substr($enc, 0, -4) . 'AAAA') === null);

$memberId = 8;
$otherId = 11;
$staffId = 7;

$db->begin_transaction();
try {
    check('格式錯誤不能送出', is_string($model->submit($memberId, 'A123456788')));
    check('正確字號可送出', $model->submit($memberId, 'a123456789') === true);

    $stored = $db->query("SELECT id_number_encrypted, id_number_hash, id_verify_status FROM users WHERE user_id = {$memberId}")->fetch_assoc();
    check('資料庫裡是密文且狀態為 pending', $stored['id_verify_status'] === 'pending' && strpos($stored['id_number_encrypted'], 'A123456789') === false && strlen($stored['id_number_hash']) === 64);
    check('審核中不能重複送出', is_string($model->submit($memberId, 'A123456789')));
    check('別的帳號不能用同一個字號', is_string($model->submit($otherId, 'A123456789')));

    check('退回必須寫原因', !$model->review($memberId, $staffId, 'rejected', ''));
    check('不能審核自己', !$model->review($memberId, $memberId, 'approved'));
    check('審核人員可核准', $model->review($memberId, $staffId, 'approved'));
    check('核准後不能再審一次', !$model->review($memberId, $staffId, 'rejected', '重複'));

    $status = $model->getStatus($memberId);
    check('狀態為已通過，只回傳遮罩字號', $status['id_verify_status'] === 'approved' && $status['masked'] === 'A12****789' && !isset($status['id_number_encrypted']));
    $audit = (int) $db->query("SELECT COUNT(*) c FROM audit_logs WHERE action_type = 'id_verification_approved' AND record_id = {$memberId}")->fetch_assoc()['c'];
    check('審核有寫入稽核紀錄', $audit >= 1);
} finally {
    $db->rollback();
}
exit(empty($failed) ? 0 : 1);
