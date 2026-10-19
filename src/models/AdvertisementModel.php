<?php
/**
 * 廣告專區模型（最新消息公告）
 */

require_once __DIR__ . '/BaseModel.php';

class AdvertisementModel extends BaseModel {
    protected $table = 'advertisements';

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

    public function create($advertiserId, $title, $content, $linkUrl = null, $imagePath = null, $startDate = null, $endDate = null) {
        $title = trim((string) $title);
        $content = trim((string) $content);
        $linkUrl = trim((string) $linkUrl);
        if ($title === '' || $content === '') {
            return false;
        }
        if ($linkUrl !== '' && !preg_match('#^https?://#i', $linkUrl)) {
            return false;
        }
        $start = $this->dateOrNull($startDate);
        $end = $this->dateOrNull($endDate);
        if ($start !== null && $end !== null && $end < $start) {
            return false;
        }

        $advertiserId = (int) $advertiserId;
        $titleSql = $this->db->real_escape_string(mb_substr($title, 0, 150));
        $contentSql = $this->db->real_escape_string($content);
        $linkSql = $linkUrl === '' ? 'NULL' : "'" . $this->db->real_escape_string(mb_substr($linkUrl, 0, 255)) . "'";
        $imageSql = $imagePath ? "'" . $this->db->real_escape_string($imagePath) . "'" : 'NULL';
        $startSql = $start === null ? 'NULL' : "'{$start}'";
        $endSql = $end === null ? 'NULL' : "'{$end}'";

        $created = $this->db->query(
            "INSERT INTO advertisements (advertiser_id, title, content, link_url, image_path, start_date, end_date)
             VALUES ({$advertiserId}, '{$titleSql}', '{$contentSql}', {$linkSql}, {$imageSql}, {$startSql}, {$endSql})"
        );
        return $created ? (int) $this->db->insert_id : false;
    }

    /**
     * 刊登者本人或食物銀行人員可下架廣告。
     */
    public function remove($adId, $userId, $userRole) {
        $adId = (int) $adId;
        $userId = (int) $userId;
        $ownerCondition = $userRole === 'foodbank_staff' ? '' : " AND advertiser_id = {$userId}";
        $this->db->query("UPDATE advertisements SET status = 'removed' WHERE ad_id = {$adId} AND status = 'active'{$ownerCondition}");
        return $this->db->affected_rows === 1;
    }

    private function dateOrNull($date) {
        $date = trim((string) $date);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : null;
    }
}
