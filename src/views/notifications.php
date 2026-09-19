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

function getNotificationTarget($notification) {
    $deliveryTitles = ['新的配送任務', '配送任務已接單', '配送異常回報'];
    if (!in_array($notification['title'], $deliveryTitles, true)) {
        return null;
    }

    $deliveryId = null;
    if (preg_match('/配送任務\s*#(\d+)/u', $notification['message'], $matches)) {
        $deliveryId = (int) $matches[1];
    }

    return '?page=deliveries' . ($deliveryId ? '#delivery-' . $deliveryId : '');
}
?>

<div class="view-header"><div><h1 class="view-title">通知中心</h1><p class="view-subtitle">查看配送、評估與帳號流程的最新通知</p></div></div>
<div class="card"><div class="card-body">
<?php if ($notifications): ?><div class="notifications-table-body"><table class="data-table notifications-table"><thead><tr><th>標題</th><th>內容</th><th>時間</th><th>狀態</th><th>操作</th></tr></thead><tbody>
<?php foreach ($notifications as $notification): ?><tr><td><strong><?php echo htmlspecialchars($notification['title']); ?></strong></td><td><?php echo htmlspecialchars($notification['message']); ?></td><td><?php echo htmlspecialchars($notification['created_at']); ?></td><td><?php echo $notification['read_at'] ? '已讀' : '未讀'; ?></td><td><div class="notification-actions"><?php $target = getNotificationTarget($notification); ?><?php if ($target): ?><a class="btn btn-primary btn-sm" href="<?php echo htmlspecialchars($target, ENT_QUOTES, 'UTF-8'); ?>">前往處理</a><?php endif; ?><?php if (!$notification['read_at']): ?><form method="post"><input type="hidden" name="action" value="mark_notification_read"><input type="hidden" name="notification_id" value="<?php echo (int) $notification['notification_id']; ?>"><button class="btn btn-secondary btn-sm" type="submit">標記已讀</button></form><?php endif; ?></div></td></tr><?php endforeach; ?></tbody></table>
<?php endif; ?></div>
<?php if (!$notifications): ?><div class="empty-state"><i class="fas fa-bell-slash"></i><p>目前沒有通知</p></div><?php endif; ?></div></div>

