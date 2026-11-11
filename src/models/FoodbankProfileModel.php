<?php
/**
 * 食物銀行資料（全食物銀行共用一份）：收貨地址、收貨日、收貨時間、公休日、收貨備註與聯絡方式。
 * 任何食物銀行人員都可以在「設置」修改；商家新增物資時「送達地址」會自動帶入這裡的地址。
 */

require_once __DIR__ . '/DonationModel.php';

class FoodbankProfileModel {
    public const NAME = '忠信食物銀行';
    /** 尚未設定時使用的地址（原本寫死在物資表單裡的地址） */
    public const DEFAULT_ADDRESS = '臺北市文山區明義里興隆路四段42巷3號1樓';

    private $db;

    public function __construct($connection = null) {
        $this->db = $connection ?: $GLOBALS['db']->getConnection();
        self::ensureTable($this->db);
    }

    public static function ensureTable(mysqli $db) {
        $db->query(
            "CREATE TABLE IF NOT EXISTS foodbank_profile (
                profile_id TINYINT NOT NULL PRIMARY KEY,
                contact_name VARCHAR(100) NULL,
                contact_phone VARCHAR(30) NULL,
                address VARCHAR(255) NULL,
                open_days VARCHAR(20) NULL,
                business_hours VARCHAR(100) NULL,
                closed_days VARCHAR(100) NULL,
                receiving_note VARCHAR(255) NULL,
                updated_by INT NULL,
                updated_at DATETIME NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    /** @return array<string,string> 沒設定過時，地址為預設地址，其餘為空字串 */
    public function get() {
        $result = $this->db->query("SELECT * FROM foodbank_profile WHERE profile_id = 1 LIMIT 1");
        $row = $result ? $result->fetch_assoc() : null;
        $profile = [];
        foreach (['contact_name', 'contact_phone', 'address', 'open_days', 'business_hours', 'closed_days', 'receiving_note', 'updated_at'] as $key) {
            $profile[$key] = (string) ($row[$key] ?? '');
        }
        if ($profile['address'] === '') {
            $profile['address'] = self::DEFAULT_ADDRESS;
        }
        return $profile;
    }

    /** 給商家與騎手看的一行收貨說明，例如「收貨日：週一、週二；收貨時間：09:00–17:00」；沒填任何資訊時回傳空字串。 */
    public static function receivingSummary(array $profile) {
        $parts = [];
        $days = DonationModel::formatOpenDays($profile['open_days'] ?? '');
        if ($days !== '') {
            $parts[] = '收貨日：' . str_replace('每天營業', '每天', $days);
        }
        if (($profile['business_hours'] ?? '') !== '') {
            $parts[] = '收貨時間：' . $profile['business_hours'];
        }
        if (($profile['closed_days'] ?? '') !== '') {
            $parts[] = '公休：' . $profile['closed_days'];
        }
        return implode('；', $parts);
    }

    /** @return array{success: bool, message: string} */
    public function save($userId, array $input) {
        $contactName = trim((string) ($input['contact_name'] ?? ''));
        $phone = trim((string) ($input['contact_phone'] ?? ''));
        $address = trim((string) ($input['address'] ?? ''));
        $hours = trim((string) ($input['business_hours'] ?? ''));
        $closed = trim((string) ($input['closed_days'] ?? ''));
        $note = trim((string) ($input['receiving_note'] ?? ''));
        $openDays = array_values(array_unique(array_filter(
            array_map('intval', (array) ($input['open_days'] ?? [])),
            static function ($day) { return isset(DonationModel::WEEKDAY_LABELS[$day]); }
        )));
        sort($openDays);

        if (mb_strlen($contactName) > 100 || mb_strlen($hours) > 100 || mb_strlen($closed) > 100) {
            return ['success' => false, 'message' => '聯絡人、收貨時間與公休日請各在 100 字以內。'];
        }
        if ($phone !== '' && !preg_match('/^[0-9+\-#() ]{6,30}$/', $phone)) {
            return ['success' => false, 'message' => '聯絡電話格式不正確（只能有數字、+、-、#、括號）。'];
        }
        if (mb_strlen($note) > 255 || preg_match('/[<>]/', $contactName . $hours . $closed . $note)) {
            return ['success' => false, 'message' => '收貨備註請在 255 字以內，且不可包含 < >。'];
        }

        $nullable = static function ($value) { return $value !== '' ? $value : null; };
        $contactName = $nullable($contactName);
        $phone = $nullable($phone);
        $openValue = $openDays ? implode(',', $openDays) : null;
        $hours = $nullable($hours);
        $closed = $nullable($closed);
        $note = $nullable($note);
        $userId = (int) $userId;
        $stmt = $this->db->prepare(
            "INSERT INTO foodbank_profile (profile_id, contact_name, contact_phone, address, open_days, business_hours, closed_days, receiving_note, updated_by, updated_at)
             VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE contact_name = VALUES(contact_name), contact_phone = VALUES(contact_phone), address = VALUES(address),
                open_days = VALUES(open_days), business_hours = VALUES(business_hours), closed_days = VALUES(closed_days),
                receiving_note = VALUES(receiving_note), updated_by = VALUES(updated_by), updated_at = NOW()"
        );
        $stmt->bind_param('sssssssi', $contactName, $phone, $address, $openValue, $hours, $closed, $note, $userId);
        if (!$stmt->execute()) {
            return ['success' => false, 'message' => '儲存失敗，請稍後再試。'];
        }
        return ['success' => true, 'message' => '已更新食物銀行資料，所有食物銀行人員都會看到同一份。'];
    }
}
