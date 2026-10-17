<?php
/**
 * 食物銀行角色、逾時通知、廣告與統一編號驗證測試。
 * 使用暫時建立的資料，結束時清除。
 */
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../src/models/PermissionModel.php';
require __DIR__ . '/../src/models/DeliveryModel.php';
require __DIR__ . '/../src/models/AdvertisementModel.php';
require __DIR__ . '/../src/helpers/ValidationHelper.php';

$connection = $db->getConnection();
$failures = 0;

function check($label, $condition) {
    global $failures;
    echo ($condition ? 'PASS ' : 'FAIL ') . $label . "\n";
    if (!$condition) {
        $failures++;
    }
}

// 統一編號驗證
check('valid tax id accepted', isValidTaiwanTaxId('04595257'));
check('invalid checksum rejected', !isValidTaiwanTaxId('12345678'));
check('non-numeric tax id rejected', !isValidTaiwanTaxId('1234567a'));
check('short tax id rejected', !isValidTaiwanTaxId('0459525'));

$suffix = bin2hex(random_bytes(4));
$createdUsers = [];
$createdDonationId = null;
$createdDeliveryId = null;
$createdAdId = null;

function createUser($connection, $suffix, $name, $role, $memberType, $staffType, $phone) {
    $staffSql = $staffType === null ? 'NULL' : "'{$staffType}'";
    $connection->query(
        "INSERT INTO users (username, password, email, full_name, phone, role, member_type, staff_type, status, phone_verified)
         VALUES ('t_{$name}_{$suffix}', 'x', 't_{$name}_{$suffix}@example.test', 'T{$name}', '{$phone}', '{$role}', '{$memberType}', {$staffSql}, 'active', 1)"
    );
    return (int) $connection->insert_id;
}

try {
    $adminId = createUser($connection, $suffix, 'admin', 'foodbank_staff', 'general', 'admin', '0900000001');
    $clerkId = createUser($connection, $suffix, 'clerk', 'foodbank_staff', 'general', 'clerk', '0900000002');
    $legacyStaffId = createUser($connection, $suffix, 'legacy', 'foodbank_staff', 'general', null, '0900000005');
    $riderId = createUser($connection, $suffix, 'rider', 'member', 'general', null, '0912345678');
    $enterpriseId = createUser($connection, $suffix, 'ent', 'member', 'enterprise', null, '0900000003');
    $createdUsers = [$adminId, $clerkId, $legacyStaffId, $riderId, $enterpriseId];

    $permissionModel = new PermissionModel();
    $admin = $permissionModel->getEffectivePermissions($adminId, 'foodbank_staff', 'general');
    $clerk = $permissionModel->getEffectivePermissions($clerkId, 'foodbank_staff', 'general');
    $legacy = $permissionModel->getEffectivePermissions($legacyStaffId, 'foodbank_staff', 'general');
    $enterprise = $permissionModel->getEffectivePermissions($enterpriseId, 'member', 'enterprise');

    check('admin can manage deliveries and users', in_array('delivery.manage', $admin, true) && in_array('admin.manage_users', $admin, true) && in_array('page.users', $admin, true));
    check('staff without staff_type keeps admin rights', in_array('delivery.manage', $legacy, true));
    check('clerk can confirm arrival and inspect inventory', in_array('delivery.confirm_arrival', $clerk, true) && in_array('inventory.inspect', $clerk, true) && in_array('page.inventory', $clerk, true));
    check('clerk cannot manage deliveries, users or settings', !array_intersect(['delivery.manage', 'page.users', 'page.settings', 'page.donation_materials_review', 'page.rewards', 'admin.manage_users'], $clerk));
    check('clerk page list is delivery-focused', in_array('page.deliveries', $clerk, true) && in_array('page.order_tracking', $clerk, true));
    check('enterprise can publish ads and activities', in_array('ad.publish', $enterprise, true) && in_array('activity.publish', $enterprise, true));
    check('everyone can open the news page', in_array('page.news', $admin, true) && in_array('page.news', $enterprise, true));

    // 逾時通知
    $connection->query(
        "INSERT INTO donations (donor_id, donor_name, donation_type, quantity, unit, status, order_number, item_name)
         VALUES ({$enterpriseId}, '測試捐贈者', 'food', 1, '份', 'approved', 'TEST-{$suffix}', '測試物資')"
    );
    $createdDonationId = (int) $connection->insert_id;
    $connection->query(
        "INSERT INTO deliveries (donation_id, delivery_method, volunteer_id, vehicle_type, pickup_address, delivery_address, status, estimated_arrival_start, estimated_arrival_end)
         VALUES ({$createdDonationId}, 'volunteer', {$riderId}, 'motorcycle', '取貨地址', '忠信食物銀行', 'picked_up', NOW() - INTERVAL 90 MINUTE, NOW() - INTERVAL 30 MINUTE)"
    );
    $createdDeliveryId = (int) $connection->insert_id;

    $deliveryModel = new DeliveryModel();
    $notified = $deliveryModel->notifyOverdueDeliveries();
    check('overdue delivery is detected', $notified >= 1);
    $notice = $connection->query("SELECT message FROM notifications WHERE user_id = {$adminId} AND title = '配送逾時提醒' AND message LIKE '%#{$createdDeliveryId}%' LIMIT 1")->fetch_assoc();
    check('overdue notice reaches staff with volunteer phone', $notice && strpos($notice['message'], '0912345678') !== false);
    check('overdue delivery is only notified once', $deliveryModel->notifyOverdueDeliveries() === 0);

    $connection->query("UPDATE deliveries SET status = 'picked_up', estimated_arrival_end = NOW() + INTERVAL 30 MINUTE, overdue_notified_at = NULL WHERE delivery_id = {$createdDeliveryId}");
    check('on-time delivery is not flagged', $deliveryModel->notifyOverdueDeliveries() === 0);

    // 取貨後會設定預估送達範圍
    $connection->query("UPDATE deliveries SET status = 'claimed', estimated_arrival_start = NULL, estimated_arrival_end = NULL WHERE delivery_id = {$createdDeliveryId}");
    check('pickup with photo succeeds', $deliveryModel->confirmPickup($createdDeliveryId, $riderId, 'uploads/pickups/test.jpg'));
    $window = $connection->query("SELECT estimated_arrival_start < estimated_arrival_end AS ok, estimated_arrival_start > NOW() AS future FROM deliveries WHERE delivery_id = {$createdDeliveryId}")->fetch_assoc();
    check('pickup sets an estimated arrival window', $window && (int) $window['ok'] === 1 && (int) $window['future'] === 1);

    // 廣告
    $adModel = new AdvertisementModel();
    check('ad without content is rejected', $adModel->create($enterpriseId, '標題', '') === false);
    check('ad with non-http link is rejected', $adModel->create($enterpriseId, '標題', '內容', 'javascript:alert(1)') === false);
    check('ad with reversed dates is rejected', $adModel->create($enterpriseId, '標題', '內容', null, null, '2026-12-31', '2026-01-01') === false);
    $createdAdId = $adModel->create($enterpriseId, 'T廣告', '測試內容', 'https://example.com');
    check('valid ad is created', $createdAdId !== false);
    $activeTitles = array_column($adModel->getActiveAds(50), 'title');
    check('new ad is listed', in_array('T廣告', $activeTitles, true));
    check('other member cannot remove the ad', !$adModel->remove($createdAdId, $riderId, 'member'));
    check('staff can remove the ad', $adModel->remove($createdAdId, $adminId, 'foodbank_staff'));
    check('removed ad is hidden', !in_array('T廣告', array_column($adModel->getActiveAds(50), 'title'), true));
} finally {
    if ($createdDeliveryId) {
        $connection->query("DELETE FROM deliveries WHERE delivery_id = {$createdDeliveryId}");
    }
    if ($createdDonationId) {
        $connection->query("DELETE FROM donations WHERE donation_id = {$createdDonationId}");
    }
    $connection->query("DELETE FROM advertisements WHERE title IN ('T廣告', '標題')");
    foreach ($createdUsers as $userId) {
        $connection->query("DELETE FROM notifications WHERE user_id = {$userId}");
        $connection->query("DELETE FROM user_permission_overrides WHERE user_id = {$userId}");
        $connection->query("DELETE FROM users WHERE user_id = {$userId}");
    }
    $connection->query("DELETE FROM notifications WHERE message LIKE '%TEST-{$suffix}%'");
}

if ($failures > 0) {
    echo "\n{$failures} test(s) failed.\n";
    exit(1);
}

echo "\nAll staff role / overdue / advertisement tests passed.\n";
