<?php
/**
 * GO Rider 申請流程測試：一般會員預設無外送權限 → 申請 → 退回 → 重新申請 → 核准後取得外送權限。
 * 使用暫時建立的測試帳號，結束時會清除所有相關資料。
 */
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../src/models/RiderApplicationModel.php';

$riderModel = new RiderApplicationModel();
$permissionModel = new PermissionModel();
$connection = $db->getConnection();
$failures = 0;

function check($label, $condition) {
    global $failures;
    echo ($condition ? 'PASS ' : 'FAIL ') . $label . "\n";
    if (!$condition) {
        $failures++;
    }
}

$suffix = bin2hex(random_bytes(4));
$connection->query(
    "INSERT INTO users (username, password, email, full_name, phone, role, member_type, status, phone_verified)
     VALUES ('tmp_rider_{$suffix}', 'x', 'tmp_rider_{$suffix}@example.test', '測試 GO Rider 申請人', '0900000000', 'member', 'general', 'active', 1)"
);
$userId = (int) $connection->insert_id;
if ($userId === 0) {
    echo "SKIP rider-application-tests (cannot create temp user: {$connection->error})\n";
    exit(0);
}

try {
    $defaults = $permissionModel->getEffectivePermissions($userId, 'member', 'general');
    check('new general member has no rider permission', !array_intersect(RiderApplicationModel::RIDER_PERMISSIONS, $defaults));
    check('new general member can open the application page', in_array('page.rider_application', $defaults, true));
    check('new general member is not a rider', !$riderModel->isRider($userId));

    check('invalid vehicle type is rejected', !$riderModel->submit($userId, 'bicycle', 'rider_licenses/a.jpg')['success']);
    check('missing license is rejected', !$riderModel->submit($userId, 'motorcycle', '')['success']);

    $first = $riderModel->submit($userId, 'motorcycle', 'rider_licenses/a.jpg');
    check('valid application is accepted', $first['success']);
    check('duplicate pending application is blocked', !$riderModel->submit($userId, 'car', 'rider_licenses/b.jpg')['success']);

    $applicationId = (int) $riderModel->getLatestForUser($userId)['application_id'];
    check('reject requires a reason', !$riderModel->reject($applicationId, 1, ''));
    check('reject with reason succeeds', $riderModel->reject($applicationId, 1, '駕照照片不清楚'));
    check('rejected user is still not a rider', !$riderModel->isRider($userId));
    check('rejected application cannot be approved again', !$riderModel->approve($applicationId, 1));

    check('re-application after rejection is accepted', $riderModel->submit($userId, 'both', 'rider_licenses/c.jpg')['success']);
    $secondId = (int) $riderModel->getLatestForUser($userId)['application_id'];
    check('approve succeeds', $riderModel->approve($secondId, 1, '審核通過'));
    check('approved user is a rider', $riderModel->isRider($userId));

    $effective = $permissionModel->getEffectivePermissions($userId, 'member', 'general');
    check('approved user gets all rider permissions', count(array_intersect(RiderApplicationModel::RIDER_PERMISSIONS, $effective)) === count(RiderApplicationModel::RIDER_PERMISSIONS));
    check('approved rider cannot apply again', !$riderModel->submit($userId, 'car', 'rider_licenses/d.jpg')['success']);
} finally {
    $connection->query("DELETE FROM user_permission_overrides WHERE user_id = {$userId}");
    $connection->query("DELETE FROM rider_applications WHERE user_id = {$userId}");
    $connection->query("DELETE FROM volunteer_consents WHERE user_id = {$userId}");
    $connection->query("DELETE FROM notifications WHERE user_id = {$userId} OR message LIKE '測試 GO Rider 申請人 %'");
    $connection->query("DELETE FROM users WHERE user_id = {$userId}");
}

if ($failures > 0) {
    echo "\n{$failures} test(s) failed.\n";
    exit(1);
}

echo "\nAll rider application tests passed.\n";
