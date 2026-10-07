<?php
/**
 * 站內通知模型
 */

require_once __DIR__ . '/BaseModel.php';

class NotificationModel extends BaseModel {
    protected $table = 'notifications';

    public function __construct($connection = null) {
        if ($connection !== null) {
            $this->db = $connection;
            return;
        }

        parent::__construct();
    }

    public function notify($userId, $title, $message, $type = 'info') {
        $userId = (int) $userId;
        $title = $this->db->real_escape_string($title);
        $message = $this->db->real_escape_string($message);
        $type = $this->db->real_escape_string($type);
        $created = $this->db->query("INSERT INTO notifications (user_id, title, message, type) VALUES ({$userId}, '{$title}', '{$message}', '{$type}')");
        if (!$created) {
            error_log('無法建立站內通知：' . $this->db->error);
        }
        return $created;
    }

    public function notifyMembers($title, $message, $type = 'info', $memberType = null) {
        $memberTypeCondition = $memberType === null
            ? ''
            : " AND member_type = '" . $this->db->real_escape_string($memberType) . "'";
        return $this->notifyMatchingUsers(
            "role = 'member'{$memberTypeCondition}",
            $title,
            $message,
            $type
        );
    }

    public function notifyRole($role, $title, $message, $type = 'info') {
        $role = $this->db->real_escape_string($role);
        return $this->notifyMatchingUsers("role = '{$role}'", $title, $message, $type);
    }

    private function notifyMatchingUsers($condition, $title, $message, $type) {
        $result = $this->db->query("SELECT user_id FROM users WHERE {$condition} AND status = 'active'");
        if (!$result) {
            error_log('無法取得通知對象：' . $this->db->error);
            return 0;
        }

        $notifiedCount = 0;
        while ($user = $result->fetch_assoc()) {
            if ($this->notify((int) $user['user_id'], $title, $message, $type)) {
                $notifiedCount++;
            }
        }
        return $notifiedCount;
    }

    public function getForUser($userId) {
        $userId = (int) $userId;
        return $this->query("SELECT * FROM notifications WHERE user_id = {$userId} ORDER BY created_at DESC LIMIT 30");
    }

    public function getSince($userId, $notificationId) {
        $userId = (int) $userId;
        $notificationId = (int) $notificationId;
        $result = $this->db->query(
            "SELECT notification_id, title, message, type, created_at
             FROM notifications
             WHERE user_id = {$userId} AND notification_id > {$notificationId}
             ORDER BY notification_id ASC LIMIT 20"
        );
        if (!$result) {
            error_log('無法取得新通知：' . $this->db->error);
            return false;
        }

        $notifications = [];
        while ($notification = $result->fetch_assoc()) {
            $notifications[] = $notification;
        }
        return $notifications;
    }

    public function getLatestIdForUser($userId) {
        $userId = (int) $userId;
        $result = $this->db->query("SELECT COALESCE(MAX(notification_id), 0) AS latest_id FROM notifications WHERE user_id = {$userId}");
        if (!$result) {
            error_log('無法取得最新通知編號：' . $this->db->error);
            return 0;
        }

        $row = $result->fetch_assoc();
        return (int) ($row['latest_id'] ?? 0);
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
