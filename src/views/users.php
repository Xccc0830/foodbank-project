<?php
/**
 * 會員帳號審核與一般會員管理
 */

if (($currentUser['role'] ?? '') !== 'foodbank_staff' || !$authService->can('admin.manage_users')) {
    echo '<div class="alert alert-error">只有管理員可以管理會員帳號。</div>';
    return;
}

$connection = $db->getConnection();
$accountMessage = null;
$accountError = null;
$isVolunteerManagement = ($page ?? '') === 'volunteer_management';
$protectedUsernames = ['admin', 'manager'];
$currentUserId = (int) ($currentUser['user_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = $_POST['action'] ?? '';
    $targetId = (int) ($_POST['user_id'] ?? 0);
    $targetResult = $targetId > 0 ? $connection->query("SELECT user_id, username, role, status, blacklisted_at FROM users WHERE user_id = {$targetId} LIMIT 1") : null;
    $target = $targetResult ? $targetResult->fetch_assoc() : null;

    if ($postAction === 'update_user_status') {
        $status = $_POST['status'] ?? 'inactive';
        if (!$target || !in_array($status, ['active', 'inactive', 'suspended'], true) || in_array($target['username'], $protectedUsernames, true)) {
            $accountError = '無法更新此帳號。';
        } elseif (!empty($target['blacklisted_at']) && $status === 'active') {
            $accountError = '此帳號已列入黑名單，請先解除黑名單才能開通。';
        } else {
            $connection->query("UPDATE users SET status = '{$status}' WHERE user_id = {$targetId}");
            $accountMessage = '帳號狀態已更新。';
        }
    } elseif ($postAction === 'blacklist_user') {
        $reason = trim((string) ($_POST['blacklist_reason'] ?? ''));
        if (!$target || $target['role'] !== 'member') {
            $accountError = '只能將會員列入黑名單。';
        } elseif ($reason === '') {
            $accountError = '請填寫列入黑名單的原因（例如偷竊物資）。';
        } else {
            $reasonSql = $connection->real_escape_string(mb_substr($reason, 0, 255));
            $connection->query("UPDATE users SET status = 'suspended', blacklisted_at = NOW(), blacklist_reason = '{$reasonSql}' WHERE user_id = {$targetId}");
            $accountMessage = '已列入黑名單，該會員無法登入，也無法再以相同電話或電子郵件註冊。';
        }
    } elseif ($postAction === 'unblacklist_user') {
        if ($target && !empty($target['blacklisted_at'])) {
            $connection->query("UPDATE users SET status = 'active', blacklisted_at = NULL, blacklist_reason = NULL WHERE user_id = {$targetId}");
            $accountMessage = '已解除黑名單。';
        } else {
            $accountError = '此帳號不在黑名單中。';
        }
    } elseif ($postAction === 'update_staff_type') {
        $staffType = $_POST['staff_type'] ?? '';
        if (!$target || $target['role'] !== 'foodbank_staff' || !in_array($staffType, ['admin', 'clerk'], true)) {
            $accountError = '無法變更此帳號類型。';
        } elseif (in_array($target['username'], $protectedUsernames, true) || (int) $target['user_id'] === $currentUserId) {
            $accountError = '系統保護帳號與目前登入的帳號不能變更類型。';
        } else {
            $connection->query("UPDATE users SET staff_type = '{$staffType}' WHERE user_id = {$targetId}");
            $accountMessage = '食物銀行帳號類型已更新，下次載入頁面即生效。';
        }
    } elseif ($postAction === 'create_staff') {
        $newUsername = trim((string) ($_POST['username'] ?? ''));
        $newName = trim((string) ($_POST['full_name'] ?? ''));
        $newEmail = trim((string) ($_POST['email'] ?? ''));
        $newPassword = (string) ($_POST['password'] ?? '');
        $newType = $_POST['staff_type'] ?? '';
        $usernameSql = $connection->real_escape_string($newUsername);
        $emailSql = $connection->real_escape_string($newEmail);
        $exists = $connection->query("SELECT user_id FROM users WHERE username = '{$usernameSql}' OR email = '{$emailSql}' LIMIT 1");

        if ($newUsername === '' || $newName === '' || !filter_var($newEmail, FILTER_VALIDATE_EMAIL) || strlen($newPassword) < 8 || !in_array($newType, ['admin', 'clerk'], true)) {
            $accountError = '請完整填寫帳號資料；密碼至少需要 8 個字元。';
        } elseif ($exists && $exists->num_rows > 0) {
            $accountError = '帳號或電子郵件已經被使用。';
        } else {
            $nameSql = $connection->real_escape_string($newName);
            $passwordSql = $connection->real_escape_string(password_hash($newPassword, PASSWORD_DEFAULT));
            $connection->query(
                "INSERT INTO users (username, password, email, full_name, role, member_type, staff_type, status, phone_verified)
                 VALUES ('{$usernameSql}', '{$passwordSql}', '{$emailSql}', '{$nameSql}', 'foodbank_staff', 'general', '{$newType}', 'active', 1)"
            );
            $accountMessage = '已建立食物銀行帳號。';
        }
    }
}

$users = [];
$result = $connection->query(
    "SELECT u.user_id, u.username, u.full_name, u.email, u.role, u.member_type, u.staff_type, u.tax_id, u.referral_source, u.blacklisted_at, u.blacklist_reason, u.status, u.phone, u.phone_verified, u.created_at, vc.completed_at AS consent_completed_at
     FROM users u
     LEFT JOIN volunteer_consents vc ON vc.user_id = u.user_id
    " . ($isVolunteerManagement ? "WHERE u.role = 'member' AND u.member_type = 'general'" : '') . "
    ORDER BY FIELD(u.status, 'inactive', 'active', 'suspended'), u.created_at DESC"
);
if ($result) {
    while ($user = $result->fetch_assoc()) {
        $users[] = $user;
    }
}
$roleLabels = ['foodbank_staff' => '忠信食物銀行', 'member' => '會員'];
$statusLabels = ['active' => '已開通', 'inactive' => '待審核', 'suspended' => '已停用'];
?>

<div class="view-header"><div><h1 class="view-title"><?php echo $isVolunteerManagement ? '一般會員管理' : '帳號審核'; ?></h1><p class="view-subtitle"><?php echo $isVolunteerManagement ? '查看一般會員申請、教育訓練與帳號狀態' : '審核一般會員與企業會員申請'; ?></p></div></div>
<?php if ($accountMessage): ?><div class="alert alert-success"><i class="fas fa-circle-check"></i><?php echo htmlspecialchars($accountMessage); ?></div><?php endif; ?>
<?php if (!empty($accountError)): ?><div class="alert alert-error"><i class="fas fa-circle-exclamation"></i><?php echo htmlspecialchars($accountError); ?></div><?php endif; ?>
<div class="card">
    <div class="card-header">
        <h2><?php echo $isVolunteerManagement ? '一般會員名單' : '平台帳號'; ?></h2>
        <p><?php echo $isVolunteerManagement ? '管理一般會員申請與開通狀態' : '新註冊帳號預設為待審核'; ?></p>
    </div>
    <div class="card-body users-table-body">
        <table class="data-table users-table">
            <thead><tr><th>姓名</th><th>帳號</th><th>會員類型</th><th>電話</th><th>電子郵件</th><th>教育訓練（外送志工）</th><th>狀態</th><th>操作</th></tr></thead>
            <tbody>
                <?php foreach ($users as $user): ?>
                    <?php
                    $memberLabel = $user['role'] === 'member'
                        ? ($user['member_type'] === 'enterprise' ? '企業會員' : '一般會員')
                        : ($user['staff_type'] === 'clerk' ? '食物銀行行政人員' : '食物銀行管理員');
                    $requiresConsent = $user['role'] === 'member' && $user['member_type'] === 'general';
                    $isBlacklisted = !empty($user['blacklisted_at']);
                    $isProtected = in_array($user['username'], $protectedUsernames, true);
                    ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($user['full_name']); ?></strong></td>
                        <td><?php echo htmlspecialchars($user['username']); ?></td>
                        <td>
                            <?php echo htmlspecialchars($memberLabel); ?>
                            <?php if ($user['role'] === 'member' && $user['member_type'] === 'enterprise'): ?>
                                <br><small>統一編號：<?php echo htmlspecialchars($user['tax_id'] ?: '未提供'); ?></small>
                                <?php if (!empty($user['referral_source'])): ?><br><small>得知來源：<?php echo htmlspecialchars($user['referral_source']); ?></small><?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars($user['phone'] ?? '-'); ?> <?php echo $user['phone_verified'] ? '<i class="fas fa-circle-check" title="已驗證"></i>' : ''; ?></td>
                        <td><?php echo htmlspecialchars($user['email']); ?></td>
                        <td><?php if ($requiresConsent): ?><?php echo $user['consent_completed_at'] ? '<span class="status status-active">已完成</span>' : '<span class="status status-pending">未申請</span>'; ?><?php else: ?>-<?php endif; ?></td>
                        <td>
                            <span class="status status-<?php echo htmlspecialchars($user['status']); ?>"><?php echo htmlspecialchars($statusLabels[$user['status']] ?? $user['status']); ?></span>
                            <?php if ($isBlacklisted): ?><br><span class="status status-exception">黑名單</span><br><small><?php echo htmlspecialchars($user['blacklist_reason'] ?? ''); ?></small><?php endif; ?>
                        </td>
                        <td>
                            <?php if (!$isProtected): ?>
                                <form method="post" class="btn-group">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="action" value="update_user_status">
                                    <input type="hidden" name="user_id" value="<?php echo (int) $user['user_id']; ?>">
                                    <select name="status" aria-label="帳號狀態">
                                        <option value="active" <?php echo $user['status'] === 'active' ? 'selected' : ''; ?>>開通</option>
                                        <option value="inactive" <?php echo $user['status'] === 'inactive' ? 'selected' : ''; ?>>待審核</option>
                                        <option value="suspended" <?php echo $user['status'] === 'suspended' ? 'selected' : ''; ?>>停用</option>
                                    </select>
                                    <button class="btn btn-primary btn-sm" type="submit">更新</button>
                                </form>
                                <?php if ($user['role'] === 'foodbank_staff' && (int) $user['user_id'] !== $currentUserId): ?>
                                    <form method="post" class="btn-group">
                                        <?php echo csrfField(); ?>
                                        <input type="hidden" name="action" value="update_staff_type">
                                        <input type="hidden" name="user_id" value="<?php echo (int) $user['user_id']; ?>">
                                        <select name="staff_type" aria-label="食物銀行帳號類型">
                                            <option value="admin" <?php echo $user['staff_type'] !== 'clerk' ? 'selected' : ''; ?>>管理員</option>
                                            <option value="clerk" <?php echo $user['staff_type'] === 'clerk' ? 'selected' : ''; ?>>行政人員</option>
                                        </select>
                                        <button class="btn btn-secondary btn-sm" type="submit">變更類型</button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($user['role'] === 'member'): ?>
                                    <?php if ($isBlacklisted): ?>
                                        <form method="post" class="btn-group" onsubmit="return confirm('確定要解除此會員的黑名單嗎？');">
                                            <?php echo csrfField(); ?>
                                            <input type="hidden" name="action" value="unblacklist_user">
                                            <input type="hidden" name="user_id" value="<?php echo (int) $user['user_id']; ?>">
                                            <button class="btn btn-secondary btn-sm" type="submit">解除黑名單</button>
                                        </form>
                                    <?php else: ?>
                                        <form method="post" class="btn-group" onsubmit="return confirm('列入黑名單後該會員將無法登入或重新註冊，確定嗎？');">
                                            <?php echo csrfField(); ?>
                                            <input type="hidden" name="action" value="blacklist_user">
                                            <input type="hidden" name="user_id" value="<?php echo (int) $user['user_id']; ?>">
                                            <input name="blacklist_reason" placeholder="原因（如偷竊物資）" maxlength="255" required>
                                            <button class="btn btn-danger btn-sm" type="submit">列入黑名單</button>
                                        </form>
                                    <?php endif; ?>
                                <?php endif; ?>
                            <?php else: ?>
                                系統保護帳號
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if (!$isVolunteerManagement): ?>
<div class="card mt-32">
    <div class="card-header"><h2>新增食物銀行帳號</h2><p>管理員擁有全部功能；行政人員只負責配送、清點與確認查收</p></div>
    <div class="card-body">
        <form method="post">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="create_staff">
            <div class="grid-2">
                <div class="form-group"><label>帳號*</label><input name="username" required maxlength="50"></div>
                <div class="form-group"><label>姓名*</label><input name="full_name" required maxlength="100"></div>
            </div>
            <div class="grid-2">
                <div class="form-group"><label>電子郵件*</label><input type="email" name="email" required maxlength="100"></div>
                <div class="form-group"><label>密碼*（至少 8 個字元）</label><input type="password" name="password" required minlength="8" autocomplete="new-password"></div>
            </div>
            <div class="form-group"><label>帳號類型*</label><select name="staff_type" required><option value="clerk">行政人員</option><option value="admin">管理員</option></select></div>
            <button class="btn btn-primary" type="submit">建立帳號</button>
        </form>
    </div>
</div>
<?php endif; ?>
