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
        return max(0, $row ? (int) $row['balance'] : 0);
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
            $balanceResult = $this->db->query("SELECT COALESCE(SUM(points), 0) AS balance FROM point_transactions WHERE user_id = {$userId}");
            $balanceRow = $balanceResult ? $balanceResult->fetch_assoc() : null;
            $currentBalance = max(0, $balanceRow ? (int) $balanceRow['balance'] : 0);
            if ($currentBalance < $cost) {
                $this->db->rollback();
                return false;
            }

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

    public function getDonorRedemptions($donorId) {
        $donorId = (int) $donorId;
        return $this->query(
            "SELECT drr.*, dri.title, u.full_name AS volunteer_name
             FROM donor_reward_redemptions drr
             JOIN donor_reward_items dri ON dri.item_id = drr.item_id
             JOIN users u ON u.user_id = drr.volunteer_id
             WHERE drr.donor_id = {$donorId}
             ORDER BY drr.created_at DESC"
        );
    }

    public function updateDonorRedemptionStatus($donorId, $redemptionId, $status) {
        $donorId = (int) $donorId;
        $redemptionId = (int) $redemptionId;
        $allowedStatuses = ['fulfilled', 'cancelled'];

        if ($donorId <= 0 || $redemptionId <= 0 || !in_array($status, $allowedStatuses, true)) {
            return false;
        }

        $statusEscaped = $this->db->real_escape_string($status);
        $fulfilledAt = $status === 'fulfilled' ? 'NOW()' : 'NULL';
        return (bool) $this->db->query(
            "UPDATE donor_reward_redemptions
             SET status = '{$statusEscaped}', fulfilled_at = {$fulfilledAt}
             WHERE redemption_id = {$redemptionId} AND donor_id = {$donorId} AND status = 'pending'"
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
}
