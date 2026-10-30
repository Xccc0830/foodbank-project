<?php
/**
 * 心意 / 感恩：食物銀行對已送達的物資，向原商家留下感謝訊息。
 */

require_once __DIR__ . '/BaseModel.php';
require_once __DIR__ . '/NotificationModel.php';

class ThanksModel extends BaseModel {
    protected $table = 'thanks_messages';

    const BADGES = [5 => '愛心銅章', 15 => '愛心銀章', 30 => '愛心金章'];

    public function __construct() {
        parent::__construct();
        $this->db->query(
            "CREATE TABLE IF NOT EXISTS thanks_messages (
                thanks_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                delivery_id INT NOT NULL,
                to_donor_id INT NOT NULL,
                from_user_id INT NOT NULL,
                message VARCHAR(300) NOT NULL,
                is_public TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_thanks_delivery (delivery_id),
                KEY idx_thanks_donor (to_donor_id, created_at),
                CONSTRAINT thanks_messages_ibfk_1 FOREIGN KEY (delivery_id) REFERENCES deliveries (delivery_id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    /**
     * 對已送達的配送單留下感謝（每張配送單一則）。成功回傳 true，失敗回傳錯誤訊息。
     */
    public function send($deliveryId, $fromUserId, $message, $isPublic = true) {
        $deliveryId = (int) $deliveryId;
        $message = trim((string) $message);
        if ($message === '' || mb_strlen($message) > 300) {
            return '感謝訊息請填寫 1～300 字。';
        }

        $rows = $this->query(
            "SELECT n.donor_id FROM deliveries d INNER JOIN donations n ON n.donation_id = d.donation_id
             WHERE d.delivery_id = {$deliveryId} AND d.status = 'delivered' LIMIT 1"
        );
        if (!$rows || (int) $rows[0]['donor_id'] <= 0) {
            return '只有已送達的配送單可以留下感謝。';
        }
        $donorId = (int) $rows[0]['donor_id'];

        $fromUserId = (int) $fromUserId;
        $public = $isPublic ? 1 : 0;
        $stmt = $this->db->prepare(
            "INSERT IGNORE INTO thanks_messages (delivery_id, to_donor_id, from_user_id, message, is_public) VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->bind_param('iiisi', $deliveryId, $donorId, $fromUserId, $message, $public);
        $stmt->execute();
        if ($stmt->affected_rows !== 1) {
            return '這筆配送已經留過感謝了。';
        }

        (new NotificationModel($this->db))->notify($donorId, '收到一則感謝', '食物銀行留言：' . $message, 'success');
        return true;
    }

    public function forDonor($donorId, $limit = 20) {
        $donorId = (int) $donorId;
        $limit = max(1, (int) $limit);
        return $this->query("SELECT * FROM thanks_messages WHERE to_donor_id = {$donorId} ORDER BY created_at DESC LIMIT {$limit}");
    }

    public function sentDeliveryIds() {
        return array_column($this->query("SELECT delivery_id FROM thanks_messages"), 'delivery_id');
    }

    /** 依收到的感謝數換算目前勳章（沒有達標回傳 null） */
    public function badgeFor($donorId) {
        $donorId = (int) $donorId;
        $rows = $this->query("SELECT COUNT(*) AS c FROM thanks_messages WHERE to_donor_id = {$donorId}");
        $count = (int) ($rows[0]['c'] ?? 0);
        $badge = null;
        foreach (self::BADGES as $threshold => $name) {
            if ($count >= $threshold) {
                $badge = $name;
            }
        }
        return ['count' => $count, 'badge' => $badge];
    }
}
