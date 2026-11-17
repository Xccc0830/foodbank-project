<?php
/**
 * 心意幣兌換模型
 */

require_once __DIR__ . '/BaseModel.php';

class RewardModel extends BaseModel {
    protected $table = 'reward_catalog';

    public function __construct() {
        parent::__construct();
        if (schemaChecksNeeded()) {
            $this->ensureClaimsTable();
            $this->ensureRewardRuleColumns();
        }
    }

    private function ensureRewardRuleColumns() {
        $columns = [
            'donor_reward_items' => [
                'rule_type' => "ENUM('direct_exchange','min_spend_discount','min_spend_percentage') NOT NULL DEFAULT 'direct_exchange'",
                'min_spend' => 'INT UNSIGNED NOT NULL DEFAULT 0',
                'discount_amount' => 'INT UNSIGNED DEFAULT NULL',
                'discount_rate' => 'DECIMAL(4,2) DEFAULT NULL',
            ],
            'reward_claims' => [
                'bill_amount' => 'DECIMAL(10,2) DEFAULT NULL',
                'final_cash_paid' => 'DECIMAL(10,2) DEFAULT NULL',
                'rule_type' => "ENUM('direct_exchange','min_spend_discount','min_spend_percentage') DEFAULT NULL",
                'min_spend' => 'INT UNSIGNED DEFAULT NULL',
                'discount_amount' => 'INT UNSIGNED DEFAULT NULL',
                'discount_rate' => 'DECIMAL(4,2) DEFAULT NULL',
                'reward_description' => 'TEXT DEFAULT NULL',
            ],
        ];
        foreach ($columns as $table => $definitions) {
            foreach ($definitions as $column => $definition) {
                $result = $this->db->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
                if (!$result) {
                    throw new RuntimeException("無法檢查 {$table}.{$column}: " . $this->db->error);
                }
                if ($result->num_rows === 0 && !$this->db->query("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}")) {
                    throw new RuntimeException("無法新增 {$table}.{$column}: " . $this->db->error);
                }
            }
        }
    }

    private function ensureClaimsTable() {
        $this->db->query(
            "CREATE TABLE IF NOT EXISTS reward_claims (
                claim_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id INT NOT NULL,
                source_type ENUM('foodbank', 'donor') NOT NULL,
                reward_id INT DEFAULT NULL,
                donor_item_id INT DEFAULT NULL,
                title VARCHAR(150) NOT NULL,
                points_spent INT NOT NULL,
                status ENUM('pending', 'fulfilled', 'cancelled') NOT NULL DEFAULT 'pending',
                token_hash CHAR(64) NOT NULL,
                token_value CHAR(64) DEFAULT NULL,
                token_expires_at DATETIME NOT NULL,
                redeemed_at DATETIME DEFAULT NULL,
                redeemed_by INT DEFAULT NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (claim_id),
                UNIQUE KEY uq_reward_claim_token (token_hash),
                KEY idx_reward_claim_user (user_id),
                KEY idx_reward_claim_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
        $columnResult = $this->db->query("SHOW COLUMNS FROM reward_claims LIKE 'token_value'");
        if ($columnResult && $columnResult->num_rows === 0) {
            $this->db->query("ALTER TABLE reward_claims ADD token_value CHAR(64) DEFAULT NULL AFTER token_hash");
        }
    }

    public function getBalance($userId) {
        $userId = (int) $userId;
        $result = $this->db->query("SELECT COALESCE(SUM(points), 0) AS balance FROM point_transactions WHERE user_id = {$userId}");
        $row = $result ? $result->fetch_assoc() : null;
        return max(0, $row ? (int) $row['balance'] : 0);
    }

    public function getAvailableRewards() {
        return $this->query(
            "SELECT reward_id AS source_id, 'foodbank' AS source_type, NULL AS source_owner_id, title, description, cost_points, stock,
                    NULL AS category, NULL AS rule_type, 0 AS min_spend, NULL AS discount_amount, NULL AS discount_rate, NULL AS donor_name
             FROM reward_catalog
             WHERE status = 'active'
             UNION ALL
             SELECT dri.item_id AS source_id, 'donor' AS source_type, dri.donor_id AS source_owner_id, dri.title, dri.description, dri.cost_points, dri.stock,
                    dri.category, dri.rule_type, dri.min_spend, dri.discount_amount, dri.discount_rate, COALESCE(NULLIF(u.enterprise_name, ''), u.full_name) AS donor_name
             FROM donor_reward_items dri
             LEFT JOIN users u ON u.user_id = dri.donor_id
             WHERE dri.status = 'active'
             ORDER BY cost_points ASC"
        );
    }

    public function getPartnerStoreOffers() {
        $result = $this->db->query(
            "SELECT u.user_id AS donor_id, COALESCE(NULLIF(u.enterprise_name, ''), u.full_name) AS donor_name,
                    u.default_pickup_address, u.open_days, u.business_hours, u.closed_days,
                    dri.item_id, dri.title, dri.description, dri.cost_points, dri.stock, dri.rule_type,
                    dri.min_spend, dri.discount_amount, dri.discount_rate
             FROM donor_reward_items dri
             JOIN users u ON u.user_id = dri.donor_id
             WHERE u.status = 'active' AND u.role = 'member' AND u.member_type = 'enterprise'
               AND dri.status = 'active'
             ORDER BY donor_name, u.user_id, dri.cost_points, dri.item_id"
        );
        if (!$result) {
            throw new RuntimeException('無法讀取合作商家優惠: ' . $this->db->error);
        }
        return $result->fetch_all(MYSQLI_ASSOC);
    }

    public function getPendingFoodbankClaims() {
        return $this->query(
            "SELECT rc.claim_id, rc.title, rc.points_spent, rc.created_at,
                    u.full_name AS volunteer_name
             FROM reward_claims rc
             LEFT JOIN users u ON u.user_id = rc.user_id
             WHERE rc.source_type = 'foodbank' AND rc.status = 'pending'
             ORDER BY rc.created_at DESC"
        );
    }

    public function getFoodbankRedemptions() {
        return $this->query(
            "SELECT rc.claim_id, rc.title, rc.points_spent, rc.status,
                    rc.created_at, rc.redeemed_at, volunteer.full_name AS volunteer_name,
                    verifier.full_name AS verifier_name
             FROM reward_claims rc
             LEFT JOIN users volunteer ON volunteer.user_id = rc.user_id
             LEFT JOIN users verifier ON verifier.user_id = rc.redeemed_by
             WHERE rc.source_type = 'foodbank'
             ORDER BY rc.created_at DESC"
        );
    }

    public function redeem($userId, $sourceType, $sourceId) {
        $userId = (int) $userId;
        $sourceType = $sourceType === 'donor' ? 'donor' : 'foodbank';
        $sourceId = (int) $sourceId;
        $table = $sourceType === 'donor' ? 'donor_reward_items' : 'reward_catalog';
        $idColumn = $sourceType === 'donor' ? 'item_id' : 'reward_id';

        if (!Transaction::begin($this->db)) {
            error_log('無法開始獎勵兌換交易');
            return false;
        }
        try {
            // 資料庫設定成出錯不報錯，所以每一步都自己檢查；任何一步失敗就整筆退回，不會扣了幣卻沒拿到兌換券。
            // 先鎖住這位使用者，連按兩次兌換也只會一筆一筆處理，不會重複扣幣。
            if (!$this->db->query("SELECT user_id FROM users WHERE user_id = {$userId} FOR UPDATE")) {
                throw new RuntimeException('鎖定使用者失敗');
            }
            $rewardResult = $this->db->query("SELECT * FROM {$table} WHERE {$idColumn} = {$sourceId} LIMIT 1 FOR UPDATE");
            if (!$rewardResult) {
                throw new RuntimeException('讀取獎勵失敗');
            }
            $reward = $rewardResult->fetch_assoc();
            if (!$reward || $reward['status'] !== 'active' || ($reward['stock'] !== null && (int) $reward['stock'] <= 0)) {
                Transaction::rollback($this->db);
                return false;
            }
            $cost = (int) $reward['cost_points'];
            $balanceResult = $this->db->query("SELECT COALESCE(SUM(points), 0) AS balance FROM point_transactions WHERE user_id = {$userId}");
            $balanceRow = $balanceResult ? $balanceResult->fetch_assoc() : null;
            $currentBalance = max(0, $balanceRow ? (int) $balanceRow['balance'] : 0);
            if ($currentBalance < $cost) {
                Transaction::rollback($this->db);
                return false;
            }

            $token = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $token);
            $expiresAt = date('Y-m-d H:i:s', time() + 600);
            $title = $this->db->real_escape_string($reward['title']);
            if (!$this->db->query("INSERT INTO point_transactions (user_id, points, transaction_type, description) VALUES ({$userId}, -{$cost}, 'redeemed', '兌換公益獎勵')")) {
                throw new RuntimeException('扣除心意幣失敗');
            }
            $ruleType = $sourceType === 'donor' ? "'" . $this->db->real_escape_string($reward['rule_type']) . "'" : 'NULL';
            $minSpend = $sourceType === 'donor' ? (int) $reward['min_spend'] : 'NULL';
            $discountAmount = $sourceType === 'donor' && $reward['discount_amount'] !== null ? (int) $reward['discount_amount'] : 'NULL';
            $discountRate = $sourceType === 'donor' && $reward['discount_rate'] !== null ? "'" . $this->db->real_escape_string($reward['discount_rate']) . "'" : 'NULL';
            $description = $sourceType === 'donor' && $reward['description'] !== null ? "'" . $this->db->real_escape_string($reward['description']) . "'" : 'NULL';
            $claimCreated = $this->db->query(
                "INSERT INTO reward_claims (user_id, source_type, reward_id, donor_item_id, title, points_spent, token_hash, token_value, token_expires_at, rule_type, min_spend, discount_amount, discount_rate, reward_description)
                 VALUES ({$userId}, '{$sourceType}', " . ($sourceType === 'foodbank' ? $sourceId : 'NULL') . ", " . ($sourceType === 'donor' ? $sourceId : 'NULL') . ", '{$title}', {$cost}, '{$tokenHash}', '{$token}', '{$expiresAt}', {$ruleType}, {$minSpend}, {$discountAmount}, {$discountRate}, {$description})"
            );
            if (!$claimCreated) {
                throw new RuntimeException('建立兌換券失敗');
            }
            $claimId = (int) $this->db->insert_id;

            if ($reward['stock'] !== null) {
                $stockUpdated = $this->db->query("UPDATE {$table} SET stock = stock - 1 WHERE {$idColumn} = {$sourceId} AND stock > 0");
                if (!$stockUpdated || $this->db->affected_rows !== 1) {
                    throw new RuntimeException('庫存已用完');
                }
            }

            if (!Transaction::commit($this->db)) {
                throw new RuntimeException('儲存兌換紀錄失敗');
            }
            $this->notifyRedemptionCreated($userId, $sourceType, $sourceId, $reward['title'], $cost);
            return ['token' => $token, 'expires_at' => $expiresAt, 'claim_id' => $claimId];
        } catch (Throwable $exception) {
            Transaction::rollback($this->db);
            error_log('獎勵兌換失敗: ' . $exception->getMessage());
            return false;
        }
    }

    public function getPendingClaimsByUser($userId) {
        $userId = (int) $userId;
        $result = $this->db->query(
            "SELECT * FROM reward_claims
             WHERE user_id = {$userId} AND status = 'pending'
             ORDER BY created_at DESC"
        );
        $claims = [];
        if ($result) {
            while ($claim = $result->fetch_assoc()) {
                if (empty($claim['token_value']) || strtotime($claim['token_expires_at']) <= time()) {
                    $claim = $this->refreshClaimToken((int) $claim['claim_id'], $userId, true) ?: $claim;
                }
                if (strtotime($claim['token_expires_at']) > time()) {
                    $claims[] = $claim;
                }
            }
        }
        return $claims;
    }

    public function getPendingClaimById($claimId, $userId) {
        $claimId = (int) $claimId;
        $userId = (int) $userId;
        $result = $this->db->query(
            "SELECT * FROM reward_claims
             WHERE claim_id = {$claimId} AND user_id = {$userId} AND status = 'pending'
             LIMIT 1"
        );
        $claim = $result ? $result->fetch_assoc() : null;
        if (!$claim) {
            return null;
        }
        $claim = $this->refreshClaimToken($claimId, $userId, true) ?: $claim;
        if ($claim['source_type'] === 'donor') {
            $itemId = (int) $claim['donor_item_id'];
            $ownerResult = $this->db->query("SELECT COALESCE(NULLIF(u.enterprise_name, ''), u.full_name) AS donor_name, d.description
                FROM donor_reward_items d JOIN users u ON u.user_id = d.donor_id WHERE d.item_id = {$itemId} LIMIT 1");
            if (!$ownerResult) {
                throw new RuntimeException('無法讀取優惠店家: ' . $this->db->error);
            }
            $owner = $ownerResult->fetch_assoc();
            $claim['donor_name'] = $owner['donor_name'] ?? null;
            if ($claim['reward_description'] === null && $claim['rule_type'] === null) {
                $claim['reward_description'] = $owner['description'] ?? null;
            }
        }
        return $claim;
    }

    public function refreshClaimToken($claimId, $userId, $force = false) {
        $claimId = (int) $claimId;
        $userId = (int) $userId;
        $result = $this->db->query(
            "SELECT * FROM reward_claims WHERE claim_id = {$claimId} AND user_id = {$userId} AND status = 'pending' LIMIT 1"
        );
        $claim = $result ? $result->fetch_assoc() : null;
        if (!$claim) {
            return false;
        }

        if (!$force && strtotime($claim['token_expires_at']) > time() && !empty($claim['token_value'])) {
            return $claim;
        }
        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + 600);
        $tokenHash = $this->db->real_escape_string(hash('sha256', $token));
        $this->db->query(
            "UPDATE reward_claims SET token_hash = '{$tokenHash}', token_value = '{$token}', token_expires_at = '{$expiresAt}'
             WHERE claim_id = {$claimId} AND user_id = {$userId} AND status = 'pending'"
        );
        return $this->db->affected_rows === 1
            ? array_merge($claim, ['token' => $token, 'token_value' => $token, 'token_expires_at' => $expiresAt])
            : false;
    }

    public function previewClaim($token, $verifierId, $verifierRole) {
        $token = trim((string) $token);
        $claim = null;
        $verifierId = (int) $verifierId;

        if (preg_match('/^R([0-9]+)$/i', $token, $matches)) {
            $claimId = (int) $matches[1];
            if ($claimId > 0) {
                $result = $this->db->query("SELECT * FROM reward_claims WHERE claim_id = {$claimId} LIMIT 1");
                $claim = $result ? $result->fetch_assoc() : null;
            }
        } else {
            $tokenHash = $this->db->real_escape_string(hash('sha256', $token));
            $result = $this->db->query("SELECT * FROM reward_claims WHERE token_hash = '{$tokenHash}' LIMIT 1");
            $claim = $result ? $result->fetch_assoc() : null;
        }

        if (!$claim || $claim['status'] !== 'pending') {
            return ['success' => false, 'message' => '此兌換憑證已完成、已取消或不存在，請確認兌換編號或 QR Code。'];
        }
        if (strtotime($claim['token_expires_at']) <= time()) {
            return ['success' => false, 'message' => 'QR Code 已逾時，請 GO Rider 重新開啟兌換憑證。'];
        }
        if ($claim['source_type'] === 'foodbank' && $verifierRole !== 'foodbank_staff') {
            return ['success' => false, 'message' => '此品項只能由食物銀行方核銷。'];
        }
        if ($claim['source_type'] === 'donor') {
            $ownerResult = $this->db->query(
                "SELECT donor_id FROM donor_reward_items WHERE item_id = " . (int) $claim['donor_item_id'] . " LIMIT 1"
            );
            $owner = $ownerResult ? $ownerResult->fetch_assoc() : null;
            $verifierResult = $this->db->query("SELECT role, member_type FROM users WHERE user_id = {$verifierId} LIMIT 1");
            $verifier = $verifierResult ? $verifierResult->fetch_assoc() : null;
            if (($verifier['role'] ?? '') !== 'member' || ($verifier['member_type'] ?? '') !== 'enterprise' || !$owner || (int) $owner['donor_id'] !== $verifierId) {
                return ['success' => false, 'message' => '此優惠券只能由提供優惠的愛心商家核銷。'];
            }
        }

        return ['success' => true, 'claim' => $claim];
    }

    public function fulfillByToken($token, $verifierId, $verifierRole, $billAmount = null) {
        $preview = $this->previewClaim($token, $verifierId, $verifierRole);
        if (!$preview['success']) {
            return $preview;
        }
        $claim = $preview['claim'];
        $verifierId = (int) $verifierId;
        $billSql = 'NULL';
        $finalSql = 'NULL';
        $rule = $claim['rule_type'] ?? 'direct_exchange';
        if ($claim['source_type'] === 'donor' && $rule !== 'direct_exchange') {
            if (!is_scalar($billAmount) || !preg_match('/^\d{1,8}(?:\.\d{1,2})?$/D', (string) $billAmount)) {
                return ['success' => false, 'message' => '請輸入有效的消費總金額（最多兩位小數）。'];
            }
            $bill = (float) $billAmount;
            if ($bill < (int) $claim['min_spend']) {
                return ['success' => false, 'message' => '消費金額未達最低消費門檻。'];
            }
            if ($rule === 'min_spend_discount' && $claim['discount_amount'] !== null) {
                $final = max(0, $bill - (int) $claim['discount_amount']);
            } elseif ($rule === 'min_spend_percentage' && $claim['discount_rate'] !== null) {
                $cents = (int) round($bill * 100);
                $ratePercent = (int) round((float) $claim['discount_rate'] * 100);
                $final = intdiv($cents * $ratePercent + 5000, 10000);
            } else {
                return ['success' => false, 'message' => '優惠規則無效，無法核銷。'];
            }
            $billSql = number_format($bill, 2, '.', '');
            $finalSql = number_format($final, 2, '.', '');
        }

        $currentTime = $this->db->real_escape_string(date('Y-m-d H:i:s'));
        $tokenHash = $this->db->real_escape_string($claim['token_hash']);
        $updated = $this->db->query(
            "UPDATE reward_claims SET status = 'fulfilled', redeemed_at = NOW(), redeemed_by = {$verifierId}, bill_amount = {$billSql}, final_cash_paid = {$finalSql}
             WHERE claim_id = " . (int) $claim['claim_id'] . " AND token_hash = '{$tokenHash}' AND status = 'pending' AND token_expires_at > '{$currentTime}'"
        );
        if ($updated && $this->db->affected_rows === 1) {
            $this->notifyVolunteerFulfilled((int) $claim['user_id'], $claim['title']);
            return ['success' => true, 'message' => '兌換完成，憑證已核銷。'];
        }
        if (!$updated) {
            error_log('核銷更新失敗: ' . $this->db->error);
            return ['success' => false, 'message' => '核銷儲存失敗，請稍後再試。'];
        }
        return ['success' => false, 'message' => '兌換憑證狀態已變更，請重新掃描。'];
    }

    private function notifyRedemptionCreated($volunteerId, $sourceType, $sourceId, $title, $cost) {
        $title = $this->db->real_escape_string($title);
        $message = $this->db->real_escape_string("GO Rider 已兌換「{$title}」，使用 {$cost} 枚心意幣，請至心意幣兌換頁面查看並核銷。");
        $roles = $sourceType === 'foodbank' ? "('foodbank_staff')" : "('member')";
        $ownerCondition = $sourceType === 'donor'
            ? " AND u.member_type = 'enterprise' AND u.user_id = (SELECT donor_id FROM donor_reward_items WHERE item_id = " . (int) $sourceId . " LIMIT 1)"
            : '';
        $this->db->query(
            "INSERT INTO notifications (user_id, title, message, type)
             SELECT u.user_id, '新的心意幣兌換', '{$message}', 'info'
             FROM users u
             WHERE u.role IN {$roles} AND u.status = 'active'{$ownerCondition}"
        );
    }

    private function notifyVolunteerFulfilled($volunteerId, $title) {
        $title = $this->db->real_escape_string($title);
        $this->db->query(
            "INSERT INTO notifications (user_id, title, message, type)
             VALUES (" . (int) $volunteerId . ", '心意幣兌換已核銷', '「{$title}」已由食物銀行完成核銷。', 'success')"
        );
    }

    public function createReward($data) {
        return $this->insert($data);
    }

    public function createDonorReward($donorId, $data) {
        $donorId = (int) $donorId;
        $data = $this->validateDonorReward($data);
        if ($donorId <= 0 || $data === false) {
            return false;
        }
        return $this->insertInto('donor_reward_items', ['donor_id' => $donorId] + $data + ['status' => 'active']);
    }

    private function validateDonorReward($data) {
        $title = trim((string) ($data['title'] ?? ''));
        $description = trim((string) ($data['description'] ?? ''));
        $cost = $data['cost_points'] ?? '';
        $stockValue = $data['stock'] ?? null;
        $stock = $stockValue === null || $stockValue === '' ? null : $stockValue;
        $category = (string) ($data['category'] ?? 'other');
        $allowedCategories = ['discount', 'product', 'experience', 'other'];
        $rule = $data['rule_type'] ?? 'direct_exchange';
        $minSpend = $data['min_spend'] ?? 0;
        $amount = $data['discount_amount'] ?? null;
        $rate = $data['discount_rate'] ?? null;
        $integer = static function ($value, $max) {
            return is_scalar($value) && preg_match('/^\d+$/D', (string) $value) && (float) $value <= $max;
        };
        if ($title === '' || !$integer($cost, 2147483647) || (int) $cost <= 0
            || ($stock !== null && !$integer($stock, 2147483647)) || !in_array($category, $allowedCategories, true)
            || !in_array($rule, ['direct_exchange', 'min_spend_discount', 'min_spend_percentage'], true)
            || !$integer($minSpend, 99999999)) {
            return false;
        }
        if ($rule === 'direct_exchange') {
            if ((int) $minSpend !== 0) return false;
            $amount = $rate = null;
        } elseif ($rule === 'min_spend_discount') {
            if (!$integer($amount, 2147483647) || (int) $amount !== (int) $cost || (int) $minSpend < (int) $amount) return false;
            $amount = (int) $amount;
            $rate = null;
        } else {
            if ((int) $minSpend <= 0 || !is_scalar($rate) || !preg_match('/^0\.\d{1,2}$/D', (string) $rate) || (float) $rate < 0.01 || (float) $rate > 0.99) return false;
            $amount = null;
            $rate = number_format((float) $rate, 2, '.', '');
        }
        return [
            'title' => $title,
            'description' => $description,
            'cost_points' => (int) $cost,
            'stock' => $stock,
            'category' => $category,
            'rule_type' => $rule,
            'min_spend' => (int) $minSpend,
            'discount_amount' => $amount,
            'discount_rate' => $rate,
        ];
    }

    public function getDonorRewards($donorId) {
        $donorId = (int) $donorId;
        return $this->query("SELECT * FROM donor_reward_items WHERE donor_id = {$donorId} ORDER BY created_at DESC");
    }

    /**
     * 商家看自己提供的獎勵被 GO Rider 兌換的紀錄（GO Rider 兌換時寫入 reward_claims）。
     * redemption_id 就是兌換編號 claim_id。
     */
    public function getDonorRedemptions($donorId) {
        $donorId = (int) $donorId;
        return $this->query(
            "SELECT rc.claim_id AS redemption_id, rc.title, rc.points_spent, rc.status, rc.created_at, rc.redeemed_at, rc.rule_type, rc.bill_amount, rc.final_cash_paid,
                    u.full_name AS volunteer_name
             FROM reward_claims rc
             JOIN donor_reward_items dri ON dri.item_id = rc.donor_item_id
             JOIN users u ON u.user_id = rc.user_id
             WHERE rc.source_type = 'donor' AND dri.donor_id = {$donorId}
             ORDER BY rc.created_at DESC"
        );
    }

    /**
     * 商家手動把待核銷的兌換標記為已完成（例如 GO Rider 已到店領取但沒掃 QR Code）。
     * 取消並退回心意幣的規則尚未訂定，目前只開放標記完成。
     */
    public function updateDonorRedemptionStatus($donorId, $redemptionId, $status) {
        $donorId = (int) $donorId;
        $redemptionId = (int) $redemptionId;
        if ($donorId <= 0 || $redemptionId <= 0 || $status !== 'fulfilled') {
            return false;
        }

        return (bool) $this->db->query(
            "UPDATE reward_claims
             SET status = 'fulfilled', redeemed_at = NOW(), redeemed_by = {$donorId}
             WHERE claim_id = {$redemptionId} AND source_type = 'donor' AND status = 'pending'
               AND (rule_type IS NULL OR rule_type = 'direct_exchange')
               AND donor_item_id IN (SELECT item_id FROM donor_reward_items WHERE donor_id = {$donorId})"
        ) && $this->db->affected_rows === 1;
    }

    private function insertInto($table, $data) {
        $columns = array_keys($data);
        $values = [];

        foreach ($data as $value) {
            $values[] = $value === null
                ? 'NULL'
                : "'" . $this->db->real_escape_string((string) $value) . "'";
        }

        $sql = "INSERT INTO {$table} (" . implode(',', $columns) . ") VALUES (" . implode(',', $values) . ")";
        return $this->db->query($sql) ? $this->db->insert_id : false;
    }

    public function updateReward($rewardId, $data) {
        $rewardId = (int) $rewardId;
        $title = $this->db->real_escape_string($data['title']);
        $description = $this->db->real_escape_string($data['description']);
        $costPoints = (int) $data['cost_points'];
        $stock = $data['stock'] === null ? 'NULL' : (int) $data['stock'];

        return $rewardId > 0 && $this->db->query(
            "UPDATE reward_catalog
             SET title = '{$title}', description = '{$description}', cost_points = {$costPoints}, stock = {$stock}
             WHERE reward_id = {$rewardId} AND status = 'active'"
        );
    }

    public function deleteReward($rewardId) {
        $rewardId = (int) $rewardId;
        return $rewardId > 0 && $this->db->query(
            "UPDATE reward_catalog SET status = 'inactive' WHERE reward_id = {$rewardId} AND status = 'active'"
        );
    }

    public function updateDonorReward($itemId, $donorId, $data, $isAdmin = false) {
        $itemId = (int) $itemId;
        $donorId = (int) $donorId;
        $data = $this->validateDonorReward($data);
        if ($data === false) return false;
        $set = [];
        foreach ($data as $key => $value) {
            $set[] = $key . ' = ' . ($value === null ? 'NULL' : "'" . $this->db->real_escape_string((string) $value) . "'");
        }
        $ownerCondition = $isAdmin
            ? "item_id = {$itemId}"
            : "item_id = {$itemId} AND donor_id = {$donorId}";

        if ($itemId <= 0 || (!$isAdmin && $donorId <= 0)) return false;
        $updated = $this->db->query(
            "UPDATE donor_reward_items
             SET " . implode(', ', $set) . "
             WHERE {$ownerCondition} AND status = 'active'"
        );
        if (!$updated) return false;
        if ($this->db->affected_rows > 0) return true;
        $existing = $this->db->query("SELECT item_id FROM donor_reward_items WHERE {$ownerCondition} AND status = 'active' LIMIT 1");
        return $existing && $existing->num_rows === 1;
    }

    public function deleteDonorReward($itemId, $donorId, $isAdmin = false) {
        $itemId = (int) $itemId;
        $donorId = (int) $donorId;
        $ownerCondition = $isAdmin
            ? "item_id = {$itemId}"
            : "item_id = {$itemId} AND donor_id = {$donorId}";

        return $itemId > 0 && ($isAdmin || $donorId > 0) && $this->db->query(
            "UPDATE donor_reward_items SET status = 'inactive'
             WHERE {$ownerCondition} AND status = 'active'"
        );
    }
}
