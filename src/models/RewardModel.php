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
            "SELECT reward_id AS source_id, 'foodbank' AS source_type, NULL AS source_owner_id, title, description, cost_points, stock
             FROM reward_catalog
             WHERE status = 'active'
             UNION ALL
             SELECT item_id AS source_id, 'donor' AS source_type, donor_id AS source_owner_id, title, description, cost_points, stock
             FROM donor_reward_items
             WHERE status = 'active'
             ORDER BY cost_points ASC"
        );
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

        $rewardResult = $this->db->query("SELECT title, cost_points, stock, status FROM {$table} WHERE {$idColumn} = {$sourceId} LIMIT 1");
        $reward = $rewardResult ? $rewardResult->fetch_assoc() : null;

        if (!$reward || $reward['status'] !== 'active') {
            return false;
        }

        if ($reward['stock'] !== null && (int) $reward['stock'] <= 0) {
            return false;
        }

        $cost = (int) $reward['cost_points'];
        if ($this->getBalance($userId) < $cost) {
            return false;
        }

        Transaction::begin($this->db);
        try {
            // 資料庫設定成出錯不報錯，所以每一步都自己檢查；任何一步失敗就整筆退回，不會扣了幣卻沒拿到兌換券。
            // 先鎖住這位使用者，連按兩次兌換也只會一筆一筆處理，不會重複扣幣。
            if (!$this->db->query("SELECT user_id FROM users WHERE user_id = {$userId} FOR UPDATE")) {
                throw new RuntimeException('鎖定使用者失敗');
            }
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
            $claimCreated = $this->db->query(
                "INSERT INTO reward_claims (user_id, source_type, reward_id, donor_item_id, title, points_spent, token_hash, token_value, token_expires_at)
                 VALUES ({$userId}, '{$sourceType}', " . ($sourceType === 'foodbank' ? $sourceId : 'NULL') . ", " . ($sourceType === 'donor' ? $sourceId : 'NULL') . ", '{$title}', {$cost}, '{$tokenHash}', '{$token}', '{$expiresAt}')"
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

    public function fulfillByToken($token, $verifierId, $verifierRole) {
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

        $currentTime = $this->db->real_escape_string(date('Y-m-d H:i:s'));
        $updated = $this->db->query(
            "UPDATE reward_claims SET status = 'fulfilled', redeemed_at = NOW(), redeemed_by = {$verifierId}
             WHERE claim_id = " . (int) $claim['claim_id'] . " AND status = 'pending' AND token_expires_at > '{$currentTime}'"
        );
        if ($updated && $this->db->affected_rows === 1) {
            $this->notifyVolunteerFulfilled((int) $claim['user_id'], $claim['title']);
            return ['success' => true, 'message' => '兌換完成，憑證已核銷。'];
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
        $title = trim((string) ($data['title'] ?? ''));
        $description = trim((string) ($data['description'] ?? ''));
        $costPoints = (int) ($data['cost_points'] ?? 0);
        $stock = $data['stock'] === null || $data['stock'] === '' ? null : (int) $data['stock'];
        $category = (string) ($data['category'] ?? 'other');
        $allowedCategories = ['discount', 'product', 'experience', 'other'];

        if ($donorId <= 0 || $title === '' || $costPoints <= 0 || ($stock !== null && $stock < 0) || !in_array($category, $allowedCategories, true)) {
            return false;
        }

        return $this->insertInto('donor_reward_items', [
            'donor_id' => $donorId,
            'title' => $title,
            'description' => $description,
            'cost_points' => $costPoints,
            'stock' => $stock,
            'category' => $category,
            'status' => 'active',
        ]);
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
            "SELECT rc.claim_id AS redemption_id, rc.title, rc.points_spent, rc.status, rc.created_at, rc.redeemed_at,
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
        $title = $this->db->real_escape_string($data['title']);
        $description = $this->db->real_escape_string($data['description']);
        $category = $this->db->real_escape_string($data['category']);
        $costPoints = (int) $data['cost_points'];
        $stock = $data['stock'] === null ? 'NULL' : (int) $data['stock'];
        $ownerCondition = $isAdmin
            ? "item_id = {$itemId}"
            : "item_id = {$itemId} AND donor_id = {$donorId}";

        return $itemId > 0 && ($isAdmin || $donorId > 0) && $this->db->query(
            "UPDATE donor_reward_items
             SET title = '{$title}', description = '{$description}', category = '{$category}', cost_points = {$costPoints}, stock = {$stock}
             WHERE {$ownerCondition} AND status = 'active'"
        );
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
