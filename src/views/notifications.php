<?php
/**
 * 站內通知
 */

require_once BASE_PATH . '/src/models/NotificationModel.php';

$notificationModel = new NotificationModel();
$userId = (int) $currentUser['user_id'];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_notification_read') {
    $notificationModel->markRead((int) $_POST['notification_id'], $userId);
}
$notifications = $notificationModel->getForUser($userId);
?>

<div class="view-header"><div><h1 class="view-title">通知中心</h1><p class="view-subtitle">查看配送、評估與帳號流程的最新通知</p></div></div>
<div class="card"><div class="card-body">
<?php if ($notifications): ?><table class="data-table"><thead><tr><th>標題</th><th>內容</th><th>時間</th><th>狀態</th><th>操作</th></tr></thead><tbody>
<?php foreach ($notifications as $notification): ?><tr><td><strong><?php echo htmlspecialchars($notification['title']); ?></strong></td><td><?php echo htmlspecialchars($notification['message']); ?></td><td><?php echo htmlspecialchars($notification['created_at']); ?></td><td><?php echo $notification['read_at'] ? '已讀' : '未讀'; ?></td><td><?php if (!$notification['read_at']): ?><form method="post"><input type="hidden" name="action" value="mark_notification_read"><input type="hidden" name="notification_id" value="<?php echo (int) $notification['notification_id']; ?>"><button class="btn btn-secondary btn-sm" type="submit">標記已讀</button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody></table>
<?php else: ?><div class="empty-state"><i class="fas fa-bell-slash"></i><p>目前沒有通知</p></div><?php endif; ?></div></div>
