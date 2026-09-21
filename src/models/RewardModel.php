<?php
/**
 * 公益點數兌換模型
 */

require_once __DIR__ . '/BaseModel.php';

class RewardModel extends BaseModel {
    protected $table = 'reward_catalog';

    public function __construct() {
        parent::__construct();
        $this->ensureClaimsTable();
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

    public function getActiveCatalog() {
        return $this->query("SELECT * FROM reward_catalog WHERE status = 'active' ORDER BY cost_points ASC");
    }

    public function getAvailableRewards() {
        return $this->query(
            "SELECT reward_id AS source_id, 'foodbank' AS source_type, title, description, cost_points, stock
             FROM reward_catalog
             WHERE status = 'active'
             UNION ALL
             SELECT item_id AS source_id, 'donor' AS source_type, title, description, cost_points, stock
             FROM donor_reward_items
             WHERE status = 'active'
             ORDER BY cost_points ASC"
        );
    }

    public function getRedemptionsByUser($userId) {
        $userId = (int) $userId;
        return $this->query(
            "SELECT claim_id, source_type, title, points_spent, status, token_expires_at, redeemed_at, created_at
             FROM reward_claims WHERE user_id = {$userId}
             UNION ALL
             SELECT rr.redemption_id, 'foodbank', rc.title, rr.points_spent, rr.status, NULL, NULL, rr.created_at
             FROM reward_redemptions rr
             JOIN reward_catalog rc ON rc.reward_id = rr.reward_id
             WHERE rr.user_id = {$userId}
             ORDER BY created_at DESC"
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

        $this->db->begin_transaction();
        try {
            $balanceResult = $this->db->query("SELECT COALESCE(SUM(points), 0) AS balance FROM point_transactions WHERE user_id = {$userId}");
            $balanceRow = $balanceResult ? $balanceResult->fetch_assoc() : null;
            $currentBalance = max(0, $balanceRow ? (int) $balanceRow['balance'] : 0);
            if ($currentBalance < $cost) {
                $this->db->rollback();
                return false;
            }

            $token = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $token);
            $expiresAt = date('Y-m-d H:i:s', time() + 600);
            $title = $this->db->real_escape_string($reward['title']);
            $this->db->query("INSERT INTO point_transactions (user_id, points, transaction_type, description) VALUES ({$userId}, -{$cost}, 'redeemed', '兌換公益獎勵')");
            $this->db->query(
                "INSERT INTO reward_claims (user_id, source_type, reward_id, donor_item_id, title, points_spent, token_hash, token_value, token_expires_at)
                 VALUES ({$userId}, '{$sourceType}', " . ($sourceType === 'foodbank' ? $sourceId : 'NULL') . ", " . ($sourceType === 'donor' ? $sourceId : 'NULL') . ", '{$title}', {$cost}, '{$tokenHash}', '{$token}', '{$expiresAt}')"
            );

            if ($reward['stock'] !== null) {
                $this->db->query("UPDATE {$table} SET stock = stock - 1 WHERE {$idColumn} = {$sourceId} AND stock > 0");
            }

            $this->db->commit();
            return ['token' => $token, 'expires_at' => $expiresAt, 'claim_id' => $this->db->insert_id];
        } catch (Throwable $exception) {
            $this->db->rollback();
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

    public function getLatestPendingClaim($userId) {
        $userId = (int) $userId;
        $result = $this->db->query(
            "SELECT * FROM reward_claims
             WHERE user_id = {$userId} AND status = 'pending'
             ORDER BY created_at DESC LIMIT 1"
        );
        return $result ? $result->fetch_assoc() : null;
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

    public function getClaimToken($claimId) {
        $claimId = (int) $claimId;
        $result = $this->db->query("SELECT * FROM reward_claims WHERE claim_id = {$claimId} LIMIT 1");
        $claim = $result ? $result->fetch_assoc() : null;
        return $claim && $claim['status'] === 'pending' && strtotime($claim['token_expires_at']) > time()
            ? $claim
            : null;
    }

    public function fulfillByToken($token, $verifierId, $verifierRole) {
        $tokenHash = $this->db->real_escape_string(hash('sha256', trim($token)));
        $verifierId = (int) $verifierId;
        $result = $this->db->query("SELECT * FROM reward_claims WHERE token_hash = '{$tokenHash}' LIMIT 1");
        $claim = $result ? $result->fetch_assoc() : null;

        if (!$claim || $claim['status'] !== 'pending') {
            return ['success' => false, 'message' => '此兌換憑證已完成、已取消或不存在。'];
        }
        if (strtotime($claim['token_expires_at']) <= time()) {
            return ['success' => false, 'message' => 'QR Code 已逾時，請志工重新開啟兌換憑證。'];
        }
        if ($claim['source_type'] === 'foodbank' && !in_array($verifierRole, ['admin', 'foodbank_staff'], true)) {
            return ['success' => false, 'message' => '此品項只能由食物銀行方核銷。'];
        }
        if ($claim['source_type'] === 'donor') {
            $ownerResult = $this->db->query(
                "SELECT donor_id FROM donor_reward_items WHERE item_id = " . (int) $claim['donor_item_id'] . " LIMIT 1"
            );
            $owner = $ownerResult ? $ownerResult->fetch_assoc() : null;
            if ($verifierRole !== 'donor' || !$owner || (int) $owner['donor_id'] !== $verifierId) {
                return ['success' => false, 'message' => '此優惠券只能由提供優惠的愛心商家核銷。'];
            }
        }

        $updated = $this->db->query(
            "UPDATE reward_claims SET status = 'fulfilled', redeemed_at = NOW(), redeemed_by = {$verifierId}
             WHERE claim_id = " . (int) $claim['claim_id'] . " AND status = 'pending' AND token_expires_at > NOW()"
        );
        return $updated && $this->db->affected_rows === 1
            ? ['success' => true, 'message' => '兌換完成，憑證已核銷。']
            : ['success' => false, 'message' => '兌換憑證狀態已變更，請重新掃描。'];
    }

    public function createReward($data) {
        return $this->insert($data);
    }
}
