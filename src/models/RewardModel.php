<?php
/**
 * 公益點數兌換模型
 */

require_once __DIR__ . '/BaseModel.php';

class RewardModel extends BaseModel {
    protected $table = 'reward_catalog';

    public function getBalance($userId) {
        $userId = (int) $userId;
        $result = $this->db->query("SELECT COALESCE(SUM(points), 0) AS balance FROM point_transactions WHERE user_id = {$userId}");
        $row = $result ? $result->fetch_assoc() : null;
        return $row ? (int) $row['balance'] : 0;
    }

    public function getActiveCatalog() {
        return $this->query("SELECT * FROM reward_catalog WHERE status = 'active' ORDER BY cost_points ASC");
    }

    public function getRedemptionsByUser($userId) {
        $userId = (int) $userId;
        return $this->query(
            "SELECT rr.*, rc.title FROM reward_redemptions rr
             JOIN reward_catalog rc ON rc.reward_id = rr.reward_id
             WHERE rr.user_id = {$userId}
             ORDER BY rr.created_at DESC"
        );
    }

    public function redeem($userId, $rewardId) {
        $userId = (int) $userId;
        $rewardId = (int) $rewardId;

        $rewardResult = $this->db->query("SELECT cost_points, stock, status FROM reward_catalog WHERE reward_id = {$rewardId} LIMIT 1");
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
            $this->db->query("INSERT INTO point_transactions (user_id, points, transaction_type, description) VALUES ({$userId}, -{$cost}, 'redeemed', '兌換獎勵')");
            $this->db->query("INSERT INTO reward_redemptions (user_id, reward_id, points_spent, status) VALUES ({$userId}, {$rewardId}, {$cost}, 'pending')");

            if ($reward['stock'] !== null) {
                $this->db->query("UPDATE reward_catalog SET stock = stock - 1 WHERE reward_id = {$rewardId} AND stock > 0");
            }

            $this->db->commit();
            return true;
        } catch (Throwable $exception) {
            $this->db->rollback();
            return false;
        }
    }

    public function createReward($data) {
        return $this->insert($data);
    }
}
