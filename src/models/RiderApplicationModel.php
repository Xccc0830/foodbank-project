<?php
/**
 * 外送志工申請模型
 * 一般會員註冊後預設不具外送權限，需上傳駕照申請並經食物銀行審核；
 * 審核通過後以個人權限覆寫（user_permission_overrides）授予外送相關能力。
 */

require_once __DIR__ . '/BaseModel.php';
require_once __DIR__ . '/PermissionModel.php';
require_once __DIR__ . '/NotificationModel.php';

class RiderApplicationModel extends BaseModel {
    protected $table = 'rider_applications';

    public const VEHICLE_TYPES = ['motorcycle', 'car', 'both'];
    public const RIDER_PERMISSIONS = ['rider.accept_task', 'page.deliveries', 'page.material_transport'];

    public function isRider($userId) {
        $user = $this->getMember($userId);
        if (!$user) {
            return false;
        }
        $permissions = (new PermissionModel())->getEffectivePermissions((int) $userId, $user['role'], $user['member_type']);
        return in_array('rider.accept_task', $permissions, true);
    }

    public function getLatestForUser($userId) {
        $userId = (int) $userId;
        $rows = $this->query("SELECT * FROM rider_applications WHERE user_id = {$userId} ORDER BY application_id DESC LIMIT 1");
        return $rows[0] ?? null;
    }

    public function getById($applicationId) {
        $applicationId = (int) $applicationId;
        $rows = $this->query(
            "SELECT a.*, u.full_name, u.username, u.phone, u.email
             FROM rider_applications a
             INNER JOIN users u ON u.user_id = a.user_id
             WHERE a.application_id = {$applicationId} LIMIT 1"
        );
        return $rows[0] ?? null;
    }

    public function listApplications($status = null) {
        $condition = '';
        if ($status !== null) {
            $condition = "WHERE a.status = '" . $this->db->real_escape_string((string) $status) . "'";
        }
        return $this->query(
            "SELECT a.*, u.full_name, u.username, u.phone, u.email
             FROM rider_applications a
             INNER JOIN users u ON u.user_id = a.user_id
             {$condition}
             ORDER BY FIELD(a.status, 'pending', 'approved', 'rejected'), a.created_at DESC"
        );
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function submit($userId, $vehicleType, $licensePhotoPath) {
        $userId = (int) $userId;
        $user = $this->getMember($userId);
        if (!$user || $user['status'] !== 'active') {
            return ['success' => false, 'message' => '只有已開通的一般會員可以申請成為外送志工。'];
        }
        if (!in_array($vehicleType, self::VEHICLE_TYPES, true) || trim((string) $licensePhotoPath) === '') {
            return ['success' => false, 'message' => '請選擇可駕駛的車種並上傳駕照照片。'];
        }
        if ($this->isRider($userId)) {
            return ['success' => false, 'message' => '您已具備外送志工資格。'];
        }
        $latest = $this->getLatestForUser($userId);
        if ($latest && $latest['status'] === 'pending') {
            return ['success' => false, 'message' => '您已有申請正在審核中。'];
        }

        $pathEscaped = $this->db->real_escape_string($licensePhotoPath);
        $vehicleEscaped = $this->db->real_escape_string($vehicleType);
        $this->db->begin_transaction();
        $saved = $this->db->query(
            "INSERT INTO rider_applications (user_id, vehicle_type, license_photo_path)
             VALUES ({$userId}, '{$vehicleEscaped}', '{$pathEscaped}')"
        );
        $consented = $saved && $this->db->query(
            "INSERT INTO volunteer_consents (user_id, agreed_disclaimer, agreed_mutual_aid, video_watched, completed_at)
             VALUES ({$userId}, 1, 1, 1, NOW())
             ON DUPLICATE KEY UPDATE agreed_disclaimer = 1, agreed_mutual_aid = 1, video_watched = 1, completed_at = NOW()"
        );
        if (!$consented) {
            $this->db->rollback();
            return ['success' => false, 'message' => '申請送出失敗，請稍後再試。'];
        }
        $this->db->commit();

        (new NotificationModel($this->db))->notifyRole(
            'foodbank_staff',
            '新的外送志工申請',
            ($user['full_name'] ?? '一般會員') . ' 已送出外送志工申請，請至「外送志工審核」處理。',
            'info'
        );

        return ['success' => true, 'message' => '申請已送出，食物銀行審核通過後會通知您。'];
    }

    public function approve($applicationId, $reviewerId, $notes = '') {
        $application = $this->getById($applicationId);
        if (!$application || $application['status'] !== 'pending') {
            return false;
        }

        $applicationId = (int) $application['application_id'];
        $reviewerId = (int) $reviewerId;
        $notesSql = $this->nullableText($notes);
        $userId = (int) $application['user_id'];

        $this->db->begin_transaction();
        $updated = $this->db->query(
            "UPDATE rider_applications
             SET status = 'approved', reviewed_by = {$reviewerId}, reviewed_at = NOW(), review_notes = {$notesSql}
             WHERE application_id = {$applicationId} AND status = 'pending'"
        );
        if (!$updated || $this->db->affected_rows !== 1) {
            $this->db->rollback();
            return false;
        }

        $permissionModel = new PermissionModel();
        foreach (self::RIDER_PERMISSIONS as $permissionKey) {
            if (!$permissionModel->grantOverride($userId, $permissionKey)) {
                $this->db->rollback();
                return false;
            }
        }
        $this->db->commit();

        (new NotificationModel($this->db))->notify(
            $userId,
            '外送志工申請通過',
            '您的外送志工申請已通過，現在可以前往「配送任務」接單。',
            'success'
        );
        return true;
    }

    public function reject($applicationId, $reviewerId, $notes) {
        $notes = trim((string) $notes);
        $application = $this->getById($applicationId);
        if ($notes === '' || !$application || $application['status'] !== 'pending') {
            return false;
        }

        $applicationId = (int) $application['application_id'];
        $reviewerId = (int) $reviewerId;
        $notesSql = $this->nullableText($notes);
        $updated = $this->db->query(
            "UPDATE rider_applications
             SET status = 'rejected', reviewed_by = {$reviewerId}, reviewed_at = NOW(), review_notes = {$notesSql}
             WHERE application_id = {$applicationId} AND status = 'pending'"
        );
        if (!$updated || $this->db->affected_rows !== 1) {
            return false;
        }

        (new NotificationModel($this->db))->notify(
            (int) $application['user_id'],
            '外送志工申請未通過',
            '您的外送志工申請未通過：' . $notes . '。您可以補齊資料後重新申請。',
            'warning'
        );
        return true;
    }

    private function getMember($userId) {
        $userId = (int) $userId;
        $rows = $this->query(
            "SELECT user_id, full_name, role, member_type, status FROM users
             WHERE user_id = {$userId} AND role = 'member' AND member_type = 'general' LIMIT 1"
        );
        return $rows[0] ?? null;
    }

    private function nullableText($text) {
        $text = trim((string) $text);
        return $text === '' ? 'NULL' : "'" . $this->db->real_escape_string($text) . "'";
    }
}
