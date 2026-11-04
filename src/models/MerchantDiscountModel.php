<?php
/**
 * 合作商家折扣模型：1 興毅幣 = 1 元，商家自訂滿額門檻與單筆折抵上限（%）。
 * 流程：GO Rider 產生短效折抵憑證 → 商家輸入憑證與消費金額 → 系統計算並扣幣 → 必要時商家沖銷退幣。
 */

require_once __DIR__ . '/BaseModel.php';
require_once __DIR__ . '/NotificationModel.php';

class MerchantDiscountModel extends BaseModel {
    protected $table = 'merchant_discount_rules';

    const TOKEN_TTL_MINUTES = 10;
    const REVERSAL_WINDOW_HOURS = 24;
    const MAX_BILL_AMOUNT = 1000000;

    const CATEGORIES = [
        'dining' => '餐飲',
        'bakery' => '烘焙甜點',
        'grocery' => '生鮮雜貨',
        'retail' => '零售商品',
        'service' => '生活服務',
        'other' => '其他',
    ];

    public function __construct() {
        parent::__construct();
        $this->ensureSchema();
    }

    private function ensureSchema() {
        static $ensured = false;
        if ($ensured) {
            return;
        }
        $ensured = true;
        $this->db->query(
            "CREATE TABLE IF NOT EXISTS merchant_discount_rules (
                merchant_id INT NOT NULL,
                store_name VARCHAR(100) NOT NULL,
                category VARCHAR(20) NOT NULL DEFAULT 'other',
                address VARCHAR(200) DEFAULT NULL,
                contact_phone VARCHAR(30) DEFAULT NULL,
                description VARCHAR(500) DEFAULT NULL,
                min_spend INT NOT NULL DEFAULT 0,
                max_discount_percent TINYINT UNSIGNED NOT NULL DEFAULT 10,
                status ENUM('active','inactive') NOT NULL DEFAULT 'active',
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (merchant_id),
                KEY idx_mdr_status_category (status, category)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
        $this->db->query(
            "CREATE TABLE IF NOT EXISTS member_discount_tokens (
                token_hash CHAR(64) NOT NULL,
                user_id INT NOT NULL,
                expires_at DATETIME NOT NULL,
                used_at DATETIME DEFAULT NULL,
                used_by_merchant_id INT DEFAULT NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (token_hash),
                KEY idx_mdt_user (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
        $this->db->query(
            "CREATE TABLE IF NOT EXISTS merchant_discount_redemptions (
                redemption_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                merchant_id INT NOT NULL,
                user_id INT NOT NULL,
                bill_amount INT NOT NULL,
                coins_used INT NOT NULL,
                final_amount INT NOT NULL,
                rule_min_spend INT NOT NULL DEFAULT 0,
                rule_max_percent TINYINT UNSIGNED NOT NULL DEFAULT 0,
                status ENUM('completed','reversed') NOT NULL DEFAULT 'completed',
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                reversed_at DATETIME DEFAULT NULL,
                PRIMARY KEY (redemption_id),
                KEY idx_mdred_merchant (merchant_id, created_at),
                KEY idx_mdred_user (user_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );

        // 既有資料庫補上權限（全新安裝則由 schema.sql 建立）
        $this->db->query("INSERT IGNORE INTO permissions (permission_key, label, category) VALUES
            ('page.merchant_directory', '折扣商家一覽', 'page'),
            ('page.merchant_rules', '店家折扣設定', 'page')");
        $this->db->query("INSERT IGNORE INTO role_permission_defaults (role, member_type, permission_key) VALUES
            ('foodbank_staff', '', 'page.merchant_directory'),
            ('foodbank_staff', 'clerk', 'page.merchant_directory'),
            ('member', 'enterprise', 'page.merchant_directory'),
            ('member', 'enterprise', 'page.merchant_rules'),
            ('member', 'general', 'page.merchant_directory')");
    }

    /**
     * 可折抵幣數：未達滿額門檻為 0；否則取「持有幣數」與「消費金額 × 上限%」較小者（1 幣 = 1 元，無條件捨去）。
     */
    public static function calculateDiscount($billAmount, $balance, $minSpend, $maxPercent) {
        $bill = (int) $billAmount;
        $balance = max(0, (int) $balance);
        $minSpend = max(0, (int) $minSpend);
        $percent = max(0, min(100, (int) $maxPercent));
        if ($bill <= 0 || $bill < $minSpend || $percent <= 0) {
            return 0;
        }
        return min($balance, intdiv($bill * $percent, 100));
    }

    public function getBalance($userId, $forUpdate = false) {
        $userId = (int) $userId;
        $result = $this->db->query(
            "SELECT COALESCE(SUM(points), 0) AS balance FROM point_transactions WHERE user_id = {$userId}" . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $row = $result ? $result->fetch_assoc() : null;
        return max(0, $row ? (int) $row['balance'] : 0);
    }

    // ---------- 商家折扣規則 ----------

    public function getRule($merchantId) {
        $merchantId = (int) $merchantId;
        $rows = $this->query("SELECT * FROM merchant_discount_rules WHERE merchant_id = {$merchantId} LIMIT 1");
        return $rows[0] ?? null;
    }

    /**
     * 儲存商家折扣規則。成功回傳 true，失敗回傳錯誤訊息字串。
     */
    public function saveRule($merchantId, array $data) {
        $merchantId = (int) $merchantId;
        $storeName = trim((string) ($data['store_name'] ?? ''));
        $category = (string) ($data['category'] ?? 'other');
        $address = trim((string) ($data['address'] ?? ''));
        $phone = trim((string) ($data['contact_phone'] ?? ''));
        $description = trim((string) ($data['description'] ?? ''));
        $minSpend = filter_var($data['min_spend'] ?? 0, FILTER_VALIDATE_INT);
        $percent = filter_var($data['max_discount_percent'] ?? 0, FILTER_VALIDATE_INT);
        $status = ($data['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';

        if ($merchantId <= 0) {
            return '找不到商家帳號。';
        }
        if ($storeName === '' || mb_strlen($storeName) > 100) {
            return '請填寫店家名稱（100 字以內）。';
        }
        if (!isset(self::CATEGORIES[$category])) {
            return '請選擇有效的店家類別。';
        }
        if (mb_strlen($address) > 200 || mb_strlen($phone) > 30 || mb_strlen($description) > 500) {
            return '地址、電話或說明內容過長，請縮短後再試。';
        }
        if ($minSpend === false || $minSpend < 0 || $minSpend > 100000) {
            return '滿額門檻請填 0 到 100000 之間的整數（0 表示不限金額）。';
        }
        if ($percent === false || $percent < 1 || $percent > 100) {
            return '折抵上限請填 1 到 100 之間的整數百分比。';
        }

        $stmt = $this->db->prepare(
            "INSERT INTO merchant_discount_rules (merchant_id, store_name, category, address, contact_phone, description, min_spend, max_discount_percent, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE store_name = VALUES(store_name), category = VALUES(category), address = VALUES(address),
                contact_phone = VALUES(contact_phone), description = VALUES(description), min_spend = VALUES(min_spend),
                max_discount_percent = VALUES(max_discount_percent), status = VALUES(status)"
        );
        $stmt->bind_param('isssssiis', $merchantId, $storeName, $category, $address, $phone, $description, $minSpend, $percent, $status);
        return $stmt->execute() ? true : '儲存失敗，請稍後再試。';
    }

    /**
     * 所有人可見的商家一覽：只列出啟用中且帳號有效的企業會員。
     */
    public function searchMerchants($keyword = '', $category = '', $sort = 'name') {
        $conditions = ["r.status = 'active'", "u.status = 'active'", "u.role = 'member'", "u.member_type = 'enterprise'"];
        $types = '';
        $params = [];

        $keyword = trim((string) $keyword);
        if ($keyword !== '') {
            $like = '%' . addcslashes($keyword, '\\%_') . '%';
            $conditions[] = "(r.store_name LIKE ? OR r.address LIKE ? OR r.description LIKE ?)";
            $types .= 'sss';
            array_push($params, $like, $like, $like);
        }
        if ($category !== '' && isset(self::CATEGORIES[$category])) {
            $conditions[] = 'r.category = ?';
            $types .= 's';
            $params[] = $category;
        }

        $orderBy = [
            'percent' => 'r.max_discount_percent DESC, r.store_name ASC',
            'min_spend' => 'r.min_spend ASC, r.store_name ASC',
            'newest' => 'r.updated_at DESC, r.store_name ASC',
        ][$sort] ?? 'r.store_name ASC';

        $stmt = $this->db->prepare(
            "SELECT r.merchant_id, r.store_name, r.category, r.address, r.contact_phone, r.description,
                    r.min_spend, r.max_discount_percent, r.updated_at
             FROM merchant_discount_rules r
             JOIN users u ON u.user_id = r.merchant_id
             WHERE " . implode(' AND ', $conditions) . "
             ORDER BY {$orderBy}"
        );
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }

    // ---------- GO Rider 折抵憑證 ----------

    /**
     * 產生一組 10 分鐘內有效、僅能使用一次的折抵憑證。明碼只回傳一次，資料庫只存雜湊。
     */
    public function issueToken($userId) {
        $userId = (int) $userId;
        $this->db->query("DELETE FROM member_discount_tokens WHERE user_id = {$userId} AND used_at IS NULL");
        $this->db->query("DELETE FROM member_discount_tokens WHERE expires_at < DATE_SUB(NOW(), INTERVAL 1 DAY)");

        $token = strtoupper(bin2hex(random_bytes(5)));
        $hash = hash('sha256', $token);
        $minutes = (int) self::TOKEN_TTL_MINUTES;
        $stmt = $this->db->prepare(
            "INSERT INTO member_discount_tokens (token_hash, user_id, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL {$minutes} MINUTE))"
        );
        $stmt->bind_param('si', $hash, $userId);
        if (!$stmt->execute()) {
            return false;
        }
        return ['token' => $token, 'seconds_left' => $minutes * 60];
    }

    private function normalizeToken($token) {
        return strtoupper(preg_replace('/[^0-9A-Za-z]/', '', (string) $token));
    }

    private function findToken($token, $forUpdate = false) {
        $token = $this->normalizeToken($token);
        if ($token === '') {
            return null;
        }
        $hash = hash('sha256', $token);
        $stmt = $this->db->prepare(
            "SELECT t.user_id, t.expires_at, t.used_at, u.full_name, u.status AS user_status, u.role, u.member_type,
                    GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), t.expires_at)) AS seconds_left
             FROM member_discount_tokens t
             JOIN users u ON u.user_id = t.user_id
             WHERE t.token_hash = ? LIMIT 1" . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $stmt->bind_param('s', $hash);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        if ($row) {
            $row['token_hash'] = $hash;
        }
        return $row ?: null;
    }

    private function validateTokenRow($row) {
        if (!$row || $row['used_at'] !== null) {
            return '此折抵憑證不存在或已使用，請 GO Rider 重新產生。';
        }
        if ((int) $row['seconds_left'] <= 0) {
            return '折抵憑證已逾時，請 GO Rider 重新產生。';
        }
        if ($row['user_status'] !== 'active' || $row['role'] !== 'member' || $row['member_type'] !== 'general') {
            return '此帳號目前無法使用興毅幣折抵。';
        }
        return null;
    }

    // ---------- 折抵與沖銷 ----------

    /**
     * 試算折抵結果但不扣幣。成功回傳試算資料，失敗回傳 ['error' => 訊息]。
     */
    public function previewRedemption($merchantId, $token, $billAmount) {
        $bill = filter_var($billAmount, FILTER_VALIDATE_INT);
        if ($bill === false || $bill < 1 || $bill > self::MAX_BILL_AMOUNT) {
            return ['error' => '請輸入有效的消費金額（正整數）。'];
        }
        $rule = $this->getRule($merchantId);
        if (!$rule || $rule['status'] !== 'active') {
            return ['error' => '請先到「店家折扣設定」啟用您的折扣方案。'];
        }
        $row = $this->findToken($token);
        $tokenError = $this->validateTokenRow($row);
        if ($tokenError !== null) {
            return ['error' => $tokenError];
        }

        $balance = $this->getBalance($row['user_id']);
        $coins = self::calculateDiscount($bill, $balance, $rule['min_spend'], $rule['max_discount_percent']);
        $reason = null;
        if ($bill < (int) $rule['min_spend']) {
            $reason = '消費金額未達滿額門檻 ' . (int) $rule['min_spend'] . ' 元，無法折抵。';
        } elseif ($balance <= 0) {
            $reason = '此 GO Rider 目前沒有可用興毅幣。';
        } elseif ($coins <= 0) {
            $reason = '依目前規則可折抵金額為 0。';
        }

        return [
            'user_id' => (int) $row['user_id'],
            'rider_name' => $row['full_name'],
            'balance' => $balance,
            'bill_amount' => $bill,
            'coins' => $coins,
            'final_amount' => $bill - $coins,
            'min_spend' => (int) $rule['min_spend'],
            'max_percent' => (int) $rule['max_discount_percent'],
            'seconds_left' => (int) $row['seconds_left'],
            'reason' => $reason,
        ];
    }

    /**
     * 正式折抵：驗證憑證、鎖定餘額、依商家規則計算、扣幣並寫入核銷紀錄。
     * 成功回傳核銷資料，失敗回傳 ['error' => 訊息]。
     */
    public function redeem($merchantId, $token, $billAmount) {
        $merchantId = (int) $merchantId;
        $bill = filter_var($billAmount, FILTER_VALIDATE_INT);
        if ($bill === false || $bill < 1 || $bill > self::MAX_BILL_AMOUNT) {
            return ['error' => '請輸入有效的消費金額（正整數）。'];
        }

        $this->db->begin_transaction();
        try {
            $rule = $this->getRule($merchantId);
            if (!$rule || $rule['status'] !== 'active') {
                $this->db->rollback();
                return ['error' => '請先到「店家折扣設定」啟用您的折扣方案。'];
            }
            $row = $this->findToken($token, true);
            $tokenError = $this->validateTokenRow($row);
            if ($tokenError !== null) {
                $this->db->rollback();
                return ['error' => $tokenError];
            }

            $userId = (int) $row['user_id'];
            $balance = $this->getBalance($userId, true);
            $coins = self::calculateDiscount($bill, $balance, $rule['min_spend'], $rule['max_discount_percent']);
            if ($coins <= 0) {
                $this->db->rollback();
                return ['error' => $bill < (int) $rule['min_spend']
                    ? '消費金額未達滿額門檻 ' . (int) $rule['min_spend'] . ' 元，無法折抵。'
                    : '此 GO Rider 的興毅幣不足或依規則可折抵金額為 0。'];
            }

            $consume = $this->db->prepare(
                "UPDATE member_discount_tokens SET used_at = NOW(), used_by_merchant_id = ?
                 WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW()"
            );
            $consume->bind_param('is', $merchantId, $row['token_hash']);
            $consume->execute();
            if ($consume->affected_rows !== 1) {
                $this->db->rollback();
                return ['error' => '此折抵憑證已使用或逾時，請 GO Rider 重新產生。'];
            }

            $final = $bill - $coins;
            $minSpend = (int) $rule['min_spend'];
            $percent = (int) $rule['max_discount_percent'];
            $insert = $this->db->prepare(
                "INSERT INTO merchant_discount_redemptions (merchant_id, user_id, bill_amount, coins_used, final_amount, rule_min_spend, rule_max_percent)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            $insert->bind_param('iiiiiii', $merchantId, $userId, $bill, $coins, $final, $minSpend, $percent);
            $insert->execute();
            $redemptionId = (int) $this->db->insert_id;

            $description = '合作商家折抵 #' . $redemptionId . '（' . $rule['store_name'] . '）';
            $negative = -$coins;
            $ledger = $this->db->prepare(
                "INSERT INTO point_transactions (user_id, points, transaction_type, description) VALUES (?, ?, 'redeemed', ?)"
            );
            $ledger->bind_param('iis', $userId, $negative, $description);
            $ledger->execute();
            if ($redemptionId <= 0 || $ledger->affected_rows !== 1) {
                throw new RuntimeException('寫入折抵紀錄失敗。');
            }
            $this->db->commit();
        } catch (Throwable $exception) {
            $this->db->rollback();
            return ['error' => '折抵失敗，請稍後再試。'];
        }

        (new NotificationModel($this->db))->notify(
            $userId,
            '興毅幣折抵完成',
            sprintf('您在「%s」消費 %d 元，使用 %d 枚興毅幣折抵，實付 %d 元。', $rule['store_name'], $bill, $coins, $final),
            'success'
        );

        return [
            'redemption_id' => $redemptionId,
            'rider_name' => $row['full_name'],
            'bill_amount' => $bill,
            'coins' => $coins,
            'final_amount' => $final,
        ];
    }

    /**
     * 商家在時限內沖銷自己的折抵並退還興毅幣。成功回傳 true，失敗回傳錯誤訊息。
     */
    public function reverseRedemption($merchantId, $redemptionId) {
        $merchantId = (int) $merchantId;
        $redemptionId = (int) $redemptionId;
        $hours = (int) self::REVERSAL_WINDOW_HOURS;

        $this->db->begin_transaction();
        try {
            $result = $this->db->query(
                "SELECT r.*, (r.created_at >= DATE_SUB(NOW(), INTERVAL {$hours} HOUR)) AS in_window, d.store_name
                 FROM merchant_discount_redemptions r
                 LEFT JOIN merchant_discount_rules d ON d.merchant_id = r.merchant_id
                 WHERE r.redemption_id = {$redemptionId} AND r.merchant_id = {$merchantId} FOR UPDATE"
            );
            $row = $result ? $result->fetch_assoc() : null;
            if (!$row) {
                $this->db->rollback();
                return '找不到這筆折抵紀錄。';
            }
            if ($row['status'] !== 'completed') {
                $this->db->rollback();
                return '這筆折抵已沖銷過。';
            }
            if (!(int) $row['in_window']) {
                $this->db->rollback();
                return "超過 {$hours} 小時，無法自行沖銷，請聯絡食物銀行協助。";
            }

            $this->db->query(
                "UPDATE merchant_discount_redemptions SET status = 'reversed', reversed_at = NOW()
                 WHERE redemption_id = {$redemptionId} AND status = 'completed'"
            );
            if ($this->db->affected_rows !== 1) {
                $this->db->rollback();
                return '這筆折抵已沖銷過。';
            }

            $userId = (int) $row['user_id'];
            $coins = (int) $row['coins_used'];
            $description = '合作商家折抵沖銷 #' . $redemptionId;
            $ledger = $this->db->prepare(
                "INSERT INTO point_transactions (user_id, points, transaction_type, description) VALUES (?, ?, 'adjusted', ?)"
            );
            $ledger->bind_param('iis', $userId, $coins, $description);
            $ledger->execute();
            if ($ledger->affected_rows !== 1) {
                throw new RuntimeException('退幣失敗。');
            }
            $this->db->commit();
        } catch (Throwable $exception) {
            $this->db->rollback();
            return '沖銷失敗，請稍後再試。';
        }

        (new NotificationModel($this->db))->notify(
            $userId,
            '興毅幣折抵已沖銷',
            sprintf('「%s」的折抵 #%d 已由店家沖銷，已退還 %d 枚興毅幣。', $row['store_name'] ?? '合作商家', $redemptionId, $coins),
            'info'
        );
        return true;
    }

    // ---------- 紀錄與統計 ----------

    public function getRedemptionsByMerchant($merchantId, $limit = 50) {
        $merchantId = (int) $merchantId;
        $limit = max(1, (int) $limit);
        $hours = (int) self::REVERSAL_WINDOW_HOURS;
        return $this->query(
            "SELECT r.*, u.full_name AS rider_name,
                    (r.status = 'completed' AND r.created_at >= DATE_SUB(NOW(), INTERVAL {$hours} HOUR)) AS can_reverse
             FROM merchant_discount_redemptions r
             LEFT JOIN users u ON u.user_id = r.user_id
             WHERE r.merchant_id = {$merchantId}
             ORDER BY r.created_at DESC, r.redemption_id DESC LIMIT {$limit}"
        );
    }

    public function getRedemptionsByUser($userId, $limit = 50) {
        $userId = (int) $userId;
        $limit = max(1, (int) $limit);
        return $this->query(
            "SELECT r.*, d.store_name
             FROM merchant_discount_redemptions r
             LEFT JOIN merchant_discount_rules d ON d.merchant_id = r.merchant_id
             WHERE r.user_id = {$userId}
             ORDER BY r.created_at DESC, r.redemption_id DESC LIMIT {$limit}"
        );
    }

    /**
     * 食物銀行對帳用：每月每家商家已折抵（未沖銷）的筆數與總額。
     */
    public function getMonthlySettlement($limit = 60) {
        $limit = max(1, (int) $limit);
        return $this->query(
            "SELECT DATE_FORMAT(r.created_at, '%Y-%m') AS month, r.merchant_id,
                    COALESCE(d.store_name, u.full_name) AS store_name,
                    COUNT(*) AS redemption_count, SUM(r.coins_used) AS total_coins, SUM(r.bill_amount) AS total_bill
             FROM merchant_discount_redemptions r
             LEFT JOIN merchant_discount_rules d ON d.merchant_id = r.merchant_id
             LEFT JOIN users u ON u.user_id = r.merchant_id
             WHERE r.status = 'completed'
             GROUP BY month, r.merchant_id, store_name
             ORDER BY month DESC, total_coins DESC LIMIT {$limit}"
        );
    }
}
