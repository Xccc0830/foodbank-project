<?php
/**
 * 身分證審核：會員送出字號（加密保存）→ 食物銀行人員審核通過 / 退回。
 */

require_once __DIR__ . '/BaseModel.php';
require_once __DIR__ . '/NotificationModel.php';
require_once __DIR__ . '/../helpers/IdNumberHelper.php';

class IdVerificationModel extends BaseModel {
    protected $table = 'users';

    public function __construct() {
        parent::__construct();
        $this->ensureSchema();
    }

    private function ensureSchema() {
        $columns = [
            'id_number_encrypted' => "ALTER TABLE users ADD id_number_encrypted TEXT DEFAULT NULL",
            'id_number_hash' => "ALTER TABLE users ADD id_number_hash CHAR(64) DEFAULT NULL",
            'id_verify_status' => "ALTER TABLE users ADD id_verify_status ENUM('none','pending','approved','rejected') NOT NULL DEFAULT 'none'",
            'id_verify_note' => "ALTER TABLE users ADD id_verify_note VARCHAR(255) DEFAULT NULL",
            'id_verified_by' => "ALTER TABLE users ADD id_verified_by INT DEFAULT NULL",
            'id_verified_at' => "ALTER TABLE users ADD id_verified_at DATETIME DEFAULT NULL",
        ];
        foreach ($columns as $column => $alterSql) {
            $result = $this->db->query("SHOW COLUMNS FROM users LIKE '{$column}'");
            if ($result && $result->num_rows === 0) {
                $this->db->query($alterSql);
            }
        }
        $index = $this->db->query("SHOW INDEX FROM users WHERE Key_name = 'idx_users_id_number_hash'");
        if ($index && $index->num_rows === 0) {
            $this->db->query("ALTER TABLE users ADD INDEX idx_users_id_number_hash (id_number_hash)");
        }

        // 既有資料庫補上權限（全新安裝則由 schema.sql 建立）
        // 暫時只有一般會員需要驗證；企業會員、食物銀行管理員不用，管理員只負責審核。
        $this->db->query("INSERT IGNORE INTO permissions (permission_key, label, category) VALUES
            ('page.id_verification', '身分驗證', 'page'),
            ('id.review', '審核身分證', 'admin')");
        $this->db->query("INSERT IGNORE INTO role_permission_defaults (role, member_type, permission_key) VALUES
            ('member', 'general', 'page.id_verification'),
            ('foodbank_staff', '', 'page.id_verification'),
            ('foodbank_staff', '', 'id.review')");
    }

    /**
     * 會員送出身分證字號。成功回傳 true，失敗回傳錯誤訊息。
     */
    public function submit($userId, $idNumber) {
        $userId = (int) $userId;
        if (!IdNumberHelper::isValid($idNumber)) {
            return '身分證字號格式不正確，請檢查後重新輸入。';
        }

        $current = $this->getStatus($userId);
        if (!$current) {
            return '找不到帳號。';
        }
        if (in_array($current['id_verify_status'], ['pending', 'approved'], true)) {
            return $current['id_verify_status'] === 'approved' ? '您的身分已通過驗證。' : '審核中，請耐心等候。';
        }

        $hash = IdNumberHelper::lookupHash($idNumber);
        $stmt = $this->db->prepare("SELECT COUNT(*) AS c FROM users WHERE id_number_hash = ? AND user_id <> ? AND id_verify_status IN ('pending','approved')");
        $stmt->bind_param('si', $hash, $userId);
        $stmt->execute();
        if ((int) $stmt->get_result()->fetch_assoc()['c'] > 0) {
            return '此身分證字號已被其他帳號使用。';
        }

        $encrypted = IdNumberHelper::encrypt($idNumber);
        $update = $this->db->prepare(
            "UPDATE users SET id_number_encrypted = ?, id_number_hash = ?, id_verify_status = 'pending',
                    id_verify_note = NULL, id_verified_by = NULL, id_verified_at = NULL
             WHERE user_id = ?"
        );
        $update->bind_param('ssi', $encrypted, $hash, $userId);
        if (!$update->execute()) {
            return '送出失敗，請稍後再試。';
        }

        (new NotificationModel($this->db))->notifyRole('foodbank_staff', '有新的身分證待審核', "會員 #{$userId} 已送出身分證審核申請。", 'info');
        return true;
    }

    public function getStatus($userId) {
        $userId = (int) $userId;
        $rows = $this->query("SELECT user_id, id_verify_status, id_verify_note, id_verified_at, id_number_encrypted FROM users WHERE user_id = {$userId} LIMIT 1");
        if (!$rows) {
            return null;
        }
        $row = $rows[0];
        $plain = $row['id_number_encrypted'] ? IdNumberHelper::decrypt($row['id_number_encrypted']) : null;
        $row['masked'] = $plain ? IdNumberHelper::mask($plain) : '';
        unset($row['id_number_encrypted']);
        return $row;
    }

    /** 審核清單（只回傳遮罩後的字號，不回傳明文）。 */
    public function listForReview() {
        $rows = $this->query(
            "SELECT user_id, username, full_name, phone, id_number_encrypted, id_verify_status, id_verify_note, id_verified_at
             FROM users WHERE id_verify_status <> 'none' ORDER BY FIELD(id_verify_status, 'pending', 'rejected', 'approved'), user_id DESC"
        );
        foreach ($rows as &$row) {
            $plain = IdNumberHelper::decrypt($row['id_number_encrypted'] ?? '');
            $row['masked'] = $plain ? IdNumberHelper::mask($plain) : '';
            unset($row['id_number_encrypted']);
        }
        return $rows;
    }

    public function review($userId, $reviewerId, $decision, $note = '') {
        $userId = (int) $userId;
        $reviewerId = (int) $reviewerId;
        $note = trim((string) $note);
        if (!in_array($decision, ['approved', 'rejected'], true) || ($decision === 'rejected' && $note === '')) {
            return false;
        }
        if ($userId === $reviewerId) {
            return false;
        }

        $stmt = $this->db->prepare(
            "UPDATE users SET id_verify_status = ?, id_verify_note = ?, id_verified_by = ?, id_verified_at = NOW()
             WHERE user_id = ? AND id_verify_status = 'pending'"
        );
        $stmt->bind_param('ssii', $decision, $note, $reviewerId, $userId);
        $stmt->execute();
        if ($stmt->affected_rows !== 1) {
            return false;
        }

        $audit = $this->db->prepare("INSERT INTO audit_logs (user_id, action_type, table_name, record_id, new_values) VALUES (?, ?, 'users', ?, ?)");
        $action = 'id_verification_' . $decision;
        $audit->bind_param('isis', $reviewerId, $action, $userId, $note);
        $audit->execute();

        $notifier = new NotificationModel($this->db);
        if ($decision === 'approved') {
            $notifier->notify($userId, '身分驗證已通過', '您的身分證審核已通過。', 'success');
        } else {
            $notifier->notify($userId, '身分驗證未通過', '您的身分證審核未通過，原因：' . $note . '。請修正後重新送出。', 'warning');
        }
        return true;
    }
}
