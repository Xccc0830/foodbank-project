<?php
/**
 * 廣告專區模型（最新消息公告）
 */

require_once __DIR__ . '/BaseModel.php';

class AdvertisementModel extends BaseModel {
    protected $table = 'advertisements';

    public function __construct() {
        parent::__construct();
        $this->ensureReviewColumns();
    }

    private function ensureReviewColumns() {
        $status = $this->db->query("SHOW COLUMNS FROM advertisements LIKE 'status'");
        $row = $status ? $status->fetch_assoc() : null;
        if ($row && strpos($row['Type'], 'pending') === false) {
            $this->db->query("ALTER TABLE advertisements MODIFY status ENUM('pending','active','rejected','removed') NOT NULL DEFAULT 'pending'");
        }
        $columns = [
            'reject_reason' => "ALTER TABLE advertisements ADD reject_reason VARCHAR(255) DEFAULT NULL",
            'reviewed_by' => "ALTER TABLE advertisements ADD reviewed_by INT DEFAULT NULL",
            'reviewed_at' => "ALTER TABLE advertisements ADD reviewed_at DATETIME DEFAULT NULL",
            'fee_amount' => "ALTER TABLE advertisements ADD fee_amount DECIMAL(10,2) DEFAULT NULL",
            'policy_agreed_at' => "ALTER TABLE advertisements ADD policy_agreed_at DATETIME DEFAULT NULL COMMENT '刊登者同意廣告刊登規範的時間'",
            'policy_flags' => "ALTER TABLE advertisements ADD policy_flags TEXT DEFAULT NULL COMMENT '自動檢查出需審核人員注意的項目（JSON）'",
        ];
        foreach ($columns as $column => $alterSql) {
            $result = $this->db->query("SHOW COLUMNS FROM advertisements LIKE '{$column}'");
            if ($result && $result->num_rows === 0) {
                $this->db->query($alterSql);
            }
        }
    }

    /** 待審核的廣告申請（食物銀行審核用） */
    public function listPending() {
        return $this->query(
            "SELECT a.*, COALESCE(NULLIF(u.enterprise_name, ''), u.full_name) AS advertiser_name
             FROM advertisements a LEFT JOIN users u ON u.user_id = a.advertiser_id
             WHERE a.status = 'pending' ORDER BY a.created_at ASC"
        );
    }

    /** 某位刊登者尚未上架的申請（審核中 / 被退回） */
    public function listOwnUnpublished($advertiserId) {
        $advertiserId = (int) $advertiserId;
        return $this->query(
            "SELECT * FROM advertisements WHERE advertiser_id = {$advertiserId} AND status IN ('pending','rejected')
             ORDER BY created_at DESC LIMIT 20"
        );
    }

    public function approve($adId, $reviewerId, $feeAmount = null) {
        $adId = (int) $adId;
        $reviewerId = (int) $reviewerId;
        $fee = is_numeric($feeAmount) && $feeAmount >= 0 ? round((float) $feeAmount, 2) : null;
        $stmt = $this->db->prepare(
            "UPDATE advertisements SET status = 'active', reviewed_by = ?, reviewed_at = NOW(), fee_amount = ?, reject_reason = NULL
             WHERE ad_id = ? AND status = 'pending'"
        );
        $stmt->bind_param('idi', $reviewerId, $fee, $adId);
        $stmt->execute();
        return $stmt->affected_rows === 1 && $this->notifyAdvertiser($adId, '廣告審核通過', '您的廣告已通過審核並開始刊登。', 'success');
    }

    public function reject($adId, $reviewerId, $reason) {
        $adId = (int) $adId;
        $reviewerId = (int) $reviewerId;
        $reason = trim((string) $reason);
        if ($reason === '') {
            return false;
        }
        $reason = mb_substr($reason, 0, 255);
        $stmt = $this->db->prepare(
            "UPDATE advertisements SET status = 'rejected', reviewed_by = ?, reviewed_at = NOW(), reject_reason = ?
             WHERE ad_id = ? AND status = 'pending'"
        );
        $stmt->bind_param('isi', $reviewerId, $reason, $adId);
        $stmt->execute();
        return $stmt->affected_rows === 1 && $this->notifyAdvertiser($adId, '廣告審核未通過', '您的廣告未通過審核，原因：' . $reason, 'warning');
    }

    private function notifyAdvertiser($adId, $title, $message, $type) {
        require_once __DIR__ . '/NotificationModel.php';
        $rows = $this->query("SELECT advertiser_id FROM advertisements WHERE ad_id = " . (int) $adId . " LIMIT 1");
        if ($rows) {
            (new NotificationModel($this->db))->notify((int) $rows[0]['advertiser_id'], $title, $message, $type);
        }
        return true;
    }

    /**
     * 目前有效（未下架、在刊登期間內）的廣告，最新的在前。
     */
    public function getActiveAds($limit = 50) {
        $limit = max(1, (int) $limit);
        return $this->query(
            "SELECT a.*, COALESCE(NULLIF(u.enterprise_name, ''), u.full_name) AS advertiser_name
             FROM advertisements a
             LEFT JOIN users u ON u.user_id = a.advertiser_id
             WHERE a.status = 'active'
               AND (a.start_date IS NULL OR a.start_date <= CURDATE())
               AND (a.end_date IS NULL OR a.end_date >= CURDATE())
             ORDER BY a.created_at DESC, a.ad_id DESC
             LIMIT {$limit}"
        );
    }

    /**
     * 刊登廣告：先依「廣告刊登規範」（AdPolicyHelper）檢查，違規直接擋下；需要注意的項目記在 policy_flags 給審核人員看。
     * @return array{success: bool, message: string, ad_id?: int, warnings?: string[]}
     */
    public function create($advertiserId, $title, $content, $linkUrl = null, $imagePath = null, $startDate = null, $endDate = null, $autoApprove = false, $policyAgreed = false) {
        require_once __DIR__ . '/../helpers/AdPolicyHelper.php';
        if (!$policyAgreed) {
            return ['success' => false, 'message' => '請先閱讀並勾選同意「廣告刊登規範」。'];
        }
        $title = trim((string) $title);
        $content = trim((string) $content);
        $linkUrl = trim((string) $linkUrl);
        $check = AdPolicyHelper::check($title, $content, $linkUrl, $startDate, $endDate);
        if ($check['errors']) {
            return ['success' => false, 'message' => '不符合廣告刊登規範：' . implode(' ', $check['errors'])];
        }

        $advertiserId = (int) $advertiserId;
        if (!$autoApprove) {
            $open = $this->query("SELECT COUNT(*) AS c FROM advertisements WHERE advertiser_id = {$advertiserId} AND status IN ('pending','active') AND (end_date IS NULL OR end_date >= CURDATE())");
            if ((int) ($open[0]['c'] ?? 0) >= AdPolicyHelper::MAX_OPEN_PER_ADVERTISER) {
                return ['success' => false, 'message' => '每個帳號同時最多 ' . AdPolicyHelper::MAX_OPEN_PER_ADVERTISER . ' 則審核中或刊登中的廣告，請等舊廣告到期或下架後再申請。'];
            }
        }

        $start = $this->dateOrNull($startDate) ?? date('Y-m-d');
        $end = $this->dateOrNull($endDate) ?? AdPolicyHelper::defaultEndDate($start);
        $flags = $check['warnings'] ? json_encode($check['warnings'], JSON_UNESCAPED_UNICODE) : null;
        $link = $linkUrl !== '' ? $linkUrl : null;
        $status = $autoApprove ? 'active' : 'pending';
        $stmt = $this->db->prepare(
            "INSERT INTO advertisements (advertiser_id, title, content, link_url, image_path, start_date, end_date, status, policy_agreed_at, policy_flags)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)"
        );
        $stmt->bind_param('issssssss', $advertiserId, $title, $content, $link, $imagePath, $start, $end, $status, $flags);
        if (!$stmt->execute()) {
            return ['success' => false, 'message' => '刊登失敗，請稍後再試。'];
        }
        return ['success' => true, 'message' => '', 'ad_id' => (int) $this->db->insert_id, 'warnings' => $check['warnings']];
    }

    /**
     * 刊登者本人或食物銀行人員可下架廣告。
     */
    public function remove($adId, $userId, $userRole) {
        $adId = (int) $adId;
        $userId = (int) $userId;
        $ownerCondition = $userRole === 'foodbank_staff' ? '' : " AND advertiser_id = {$userId}";
        $this->db->query("UPDATE advertisements SET status = 'removed' WHERE ad_id = {$adId} AND status IN ('active','pending'){$ownerCondition}");
        return $this->db->affected_rows === 1;
    }

    private function dateOrNull($date) {
        $date = trim((string) $date);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : null;
    }
}
