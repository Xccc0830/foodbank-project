<?php
/**
 * 管理員帳號審核
 */

if (($currentUser['role'] ?? '') !== 'admin') {
    echo '<div class="alert alert-error">只有系統管理者可以審核帳號。</div>';
    return;
}

$connection = $db->getConnection();
$accountMessage = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_user_status') {
    $userId = (int) ($_POST['user_id'] ?? 0);
    $status = $_POST['status'] ?? 'inactive';
    $allowedStatuses = ['active', 'inactive', 'suspended'];
    if (in_array($status, $allowedStatuses, true) && $userId > 0) {
        $connection->query("UPDATE users SET status = '{$status}' WHERE user_id = {$userId} AND username <> 'admin'");
        $accountMessage = '帳號狀態已更新。';
    }
}

$users = [];
$result = $connection->query("SELECT user_id, username, full_name, email, role, status, created_at FROM users ORDER BY FIELD(status, 'inactive', 'active', 'suspended'), created_at DESC");
if ($result) {
    while ($user = $result->fetch_assoc()) {
        $users[] = $user;
    }
}
$roleLabels = ['admin' => '系統管理者', 'manager' => '食物銀行管理者', 'staff' => '工作人員', 'volunteer' => '平台志工'];
$statusLabels = ['active' => '已開通', 'inactive' => '待審核', 'suspended' => '已停用'];
?>

<div class="view-header"><div><h1 class="view-title">帳號審核</h1><p class="view-subtitle">審核平台角色申請與帳號狀態</p></div></div>
<?php if ($accountMessage): ?><div class="alert alert-success"><i class="fas fa-circle-check"></i><?php echo htmlspecialchars($accountMessage); ?></div><?php endif; ?>
<div class="card"><div class="card-header"><h2>平台帳號</h2><p>新註冊帳號預設為待審核</p></div><div class="card-body"><table class="data-table"><thead><tr><th>姓名</th><th>帳號</th><th>角色</th><th>電子郵件</th><th>狀態</th><th>操作</th></tr></thead><tbody>
<?php foreach ($users as $user): ?><tr><td><strong><?php echo htmlspecialchars($user['full_name']); ?></strong></td><td><?php echo htmlspecialchars($user['username']); ?></td><td><?php echo htmlspecialchars($roleLabels[$user['role']] ?? $user['role']); ?></td><td><?php echo htmlspecialchars($user['email']); ?></td><td><span class="status status-<?php echo htmlspecialchars($user['status']); ?>"><?php echo htmlspecialchars($statusLabels[$user['status']] ?? $user['status']); ?></span></td><td><?php if ($user['username'] !== 'admin'): ?><form method="post" class="btn-group"><input type="hidden" name="action" value="update_user_status"><input type="hidden" name="user_id" value="<?php echo (int) $user['user_id']; ?>"><select name="status" aria-label="帳號狀態"><option value="active" <?php echo $user['status'] === 'active' ? 'selected' : ''; ?>>開通</option><option value="inactive" <?php echo $user['status'] === 'inactive' ? 'selected' : ''; ?>>待審核</option><option value="suspended" <?php echo $user['status'] === 'suspended' ? 'selected' : ''; ?>>停用</option></select><button class="btn btn-primary btn-sm" type="submit">更新</button></form><?php else: ?>系統保護帳號<?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div></div>
