<?php
/**
 * 權限系統回歸測試：驗證 PermissionModel 計算出的「角色 + 會員類型」有效權限
 * 與重構前寫死於 public/index.php 的 $rolePages 完全一致（零回歸）。
 * 另外驗證使用者個人 override（加選/退選）可正確疊加於角色預設值之上。
 * 本測試只做讀取查詢與暫時性 override（測試結束會自行清除），不影響其他資料。
 */
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../src/models/PermissionModel.php';

function oldRolePages($role, $memberType) {
    $rolePages = [
        'foodbank_staff' => ['dashboard', 'order_tracking', 'donation_materials_review', 'deliveries', 'activities', 'item_categories', 'rewards', 'settings', 'users', 'volunteer_management', 'carbon_report', 'reports', 'notifications', 'certificate', 'activity_certificate'],
        'member' => $memberType === 'enterprise'
            ? ['dashboard', 'order_tracking', 'activities', 'rewards', 'notifications', 'donation_materials', 'carbon_report', 'certificate', 'activity_certificate']
            : ($memberType === 'general'
                ? ['dashboard', 'deliveries', 'material_transport', 'activities', 'rewards', 'reports', 'notifications', 'certificate', 'activity_certificate']
                : ['dashboard']),
    ];
    return $rolePages[$role] ?? ['dashboard'];
}

$permissionModel = new PermissionModel();
$failures = 0;

// 1. 角色 + 會員類型的預設權限應與舊版 $rolePages 完全一致。
$cases = [
    ['role' => 'foodbank_staff', 'member_type' => null],
    ['role' => 'member', 'member_type' => 'general'],
    ['role' => 'member', 'member_type' => 'enterprise'],
];
foreach ($cases as $case) {
    $effective = $permissionModel->getEffectivePermissions(0, $case['role'], $case['member_type']);
    $pagePermissions = array_map(static function ($key) {
        return substr($key, strlen('page.'));
    }, array_filter($effective, static function ($key) {
        return strpos($key, 'page.') === 0;
    }));
    sort($pagePermissions);

    $expected = oldRolePages($case['role'], $case['member_type']);
    sort($expected);

    $label = $case['role'] . '/' . ($case['member_type'] ?? '-');
    if ($pagePermissions === $expected) {
        echo "PASS role-default-parity ({$label})\n";
    } else {
        echo "FAIL role-default-parity ({$label}): expected " . implode(',', $expected) . ' got ' . implode(',', $pagePermissions) . "\n";
        $failures++;
    }
}

// 2. 個人 override 應可疊加於角色預設值之上（加選/退選），且不影響其他使用者。
// 使用資料庫中既有的一般會員帳號做暫時性 override 測試，測試結束會清除該筆 override。
$dbHandle = (new class extends PermissionModel {
    public function getConnection() {
        return $this->db;
    }
})->getConnection();

$testUserResult = $dbHandle->query("SELECT user_id FROM users WHERE role = 'member' AND member_type = 'general' AND status = 'active' LIMIT 1");
$testUser = $testUserResult ? $testUserResult->fetch_assoc() : null;

if ($testUser) {
    $testUserId = (int) $testUser['user_id'];
    $before = $permissionModel->getEffectivePermissions($testUserId, 'member', 'general');
    $hadRiderPermission = in_array('rider.accept_task', $before, true);

    // 退選 Rider 任務，驗證 override granted=0 會蓋過角色預設值。
    $permissionModel->revokeOverride($testUserId, 'rider.accept_task');
    $afterRevoke = $permissionModel->getEffectivePermissions($testUserId, 'member', 'general');
    if (!in_array('rider.accept_task', $afterRevoke, true)) {
        echo "PASS override-revoke (user #{$testUserId} rider.accept_task removed)\n";
    } else {
        echo "FAIL override-revoke (user #{$testUserId} rider.accept_task still present)\n";
        $failures++;
    }

    // 清除 override，應恢復為角色預設值。
    $permissionModel->clearOverride($testUserId, 'rider.accept_task');
    $afterClear = $permissionModel->getEffectivePermissions($testUserId, 'member', 'general');
    if (in_array('rider.accept_task', $afterClear, true) === $hadRiderPermission) {
        echo "PASS override-clear-restores-default (user #{$testUserId})\n";
    } else {
        echo "FAIL override-clear-restores-default (user #{$testUserId})\n";
        $failures++;
    }
} else {
    echo "SKIP override-tests (no active general member found in database)\n";
}

if ($failures > 0) {
    echo "\n{$failures} test(s) failed.\n";
    exit(1);
}

echo "\nAll permission tests passed.\n";
