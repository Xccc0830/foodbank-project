<?php
/**
 * 站內通知模型
 */

require_once __DIR__ . '/BaseModel.php';

class NotificationModel extends BaseModel {
    protected $table = 'notifications';

    public function notify($userId, $title, $message, $type = 'info') {
        $userId = (int) $userId;
        $title = $this->db->real_escape_string($title);
        $message = $this->db->real_escape_string($message);
        $type = $this->db->real_escape_string($type);
        return $this->db->query("INSERT INTO notifications (user_id, title, message, type) VALUES ({$userId}, '{$title}', '{$message}', '{$type}')");
    }

    public function getForUser($userId) {
        $userId = (int) $userId;
        return $this->query("SELECT * FROM notifications WHERE user_id = {$userId} ORDER BY created_at DESC LIMIT 30");
    }

    public function getUnreadCount($userId) {
        $userId = (int) $userId;
        $result = $this->db->query("SELECT COUNT(*) AS unread_count FROM notifications WHERE user_id = {$userId} AND read_at IS NULL");
        if (!$result) {
            return 0;
        }

        $row = $result->fetch_assoc();
        return (int) ($row['unread_count'] ?? 0);
    }

    public function markRead($notificationId, $userId) {
        return $this->db->query("UPDATE notifications SET read_at = NOW() WHERE notification_id = " . (int) $notificationId . " AND user_id = " . (int) $userId);
    }
}
