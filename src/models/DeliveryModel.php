<?php
/**
 * 配送任務與公益點數模型
 */

require_once __DIR__ . '/BaseModel.php';

class DeliveryModel extends BaseModel {
    protected $table = 'deliveries';

    public function getAllDeliveries() {
        $sql = "SELECT d.*, u.full_name AS volunteer_name
                FROM deliveries d
                LEFT JOIN users u ON u.user_id = d.volunteer_id
                ORDER BY d.created_at DESC";
        return $this->query($sql);
    }

    public function createDelivery($data) {
        $data['status'] = 'open';
        $data['points'] = $this->calculatePoints(
            $data['vehicle_type'],
            (float) $data['total_distance_km'],
            (float) $data['weight_kg'],
            $data['urgency']
        );
        return $this->insert($data);
    }

    public function claimDelivery($deliveryId, $volunteerId) {
        $deliveryId = (int) $deliveryId;
        $volunteerId = (int) $volunteerId;
        $updated = $this->db->query("UPDATE deliveries SET volunteer_id = {$volunteerId}, status = 'claimed' WHERE delivery_id = {$deliveryId} AND status = 'open'");
        if ($updated && $this->db->affected_rows > 0) {
            $this->notifyUsersByRole('foodbank_staff', '配送任務已接單', "配送任務 #{$deliveryId} 已由志工接單。", 'info');
        }
        return $updated;
    }

    public function confirmPickup($deliveryId, $volunteerId, $sealIntact, $itemCountConfirmed) {
        $deliveryId = (int) $deliveryId;
        $volunteerId = (int) $volunteerId;
        $sealIntact = $sealIntact ? 1 : 0;
        $itemCountConfirmed = $itemCountConfirmed ? 1 : 0;

        if (!$sealIntact || !$itemCountConfirmed) {
            return false;
        }

        return $this->db->query("UPDATE deliveries SET status = 'picked_up', pickup_confirmed_at = NOW(), seal_intact = {$sealIntact}, item_count_confirmed = {$itemCountConfirmed} WHERE delivery_id = {$deliveryId} AND volunteer_id = {$volunteerId} AND status = 'claimed'");
    }

    public function reportException($deliveryId, $volunteerId, $notes) {
        $deliveryId = (int) $deliveryId;
        $volunteerId = (int) $volunteerId;
        $notes = $this->db->real_escape_string(trim($notes));

        if ($notes === '') {
            return false;
        }

        $updated = $this->db->query("UPDATE deliveries SET status = 'exception', exception_notes = '{$notes}' WHERE delivery_id = {$deliveryId} AND volunteer_id = {$volunteerId} AND status IN ('claimed', 'picked_up')");
        if ($updated && $this->db->affected_rows > 0) {
            $this->notifyUsersByRole('foodbank_staff', '配送異常回報', "配送任務 #{$deliveryId}：{$notes}", 'warning');
        }
        return $updated;
    }

    public function completeDelivery($deliveryId) {
        $deliveryId = (int) $deliveryId;
        $result = $this->db->query("SELECT volunteer_id, points FROM deliveries WHERE delivery_id = {$deliveryId} AND status IN ('claimed', 'picked_up') LIMIT 1");
        $delivery = $result ? $result->fetch_assoc() : null;

        if (!$delivery || !$delivery['volunteer_id']) {
            return false;
        }

        $this->db->begin_transaction();
        try {
            $this->db->query("UPDATE deliveries SET status = 'delivered', delivered_at = NOW() WHERE delivery_id = {$deliveryId} AND status IN ('claimed', 'picked_up')");
            $volunteerId = (int) $delivery['volunteer_id'];
            $points = (int) $delivery['points'];
            $this->db->query("INSERT INTO point_transactions (user_id, delivery_id, points, transaction_type, description) VALUES ({$volunteerId}, {$deliveryId}, {$points}, 'earned', '完成惜食配送')");
            $this->db->commit();
            $this->notifyUser($volunteerId, '配送已完成', "配送任務 #{$deliveryId} 已確認收貨，獲得 {$points} 點公益點數。", 'success');
            return true;
        } catch (Throwable $exception) {
            $this->db->rollback();
            return false;
        }
    }

    private function notifyUser($userId, $title, $message, $type) {
        require_once __DIR__ . '/NotificationModel.php';
        (new NotificationModel())->notify((int) $userId, $title, $message, $type);
    }

    private function notifyUsersByRole($role, $title, $message, $type) {
        $role = $this->db->real_escape_string($role);
        $result = $this->db->query("SELECT user_id FROM users WHERE role = '{$role}' AND status = 'active'");
        if ($result) {
            while ($user = $result->fetch_assoc()) {
                $this->notifyUser((int) $user['user_id'], $title, $message, $type);
            }
        }
    }

    public function calculatePoints($vehicle, $distance, $weight, $urgency) {
        $isCar = $vehicle === 'car';
        $points = $isCar ? 10 : 5;

        if ($distance <= 2) {
            $points += $isCar ? 5 : 3;
        } elseif ($distance <= 4) {
            $points += $isCar ? 8 : 5;
        } elseif ($distance <= 6) {
            $points += $isCar ? 11 : 7;
        } elseif ($distance <= 8) {
            $points += $isCar ? 14 : 10;
        } elseif ($distance <= 10) {
            $points += $isCar ? 17 : 13;
        } else {
            $points += $isCar ? 20 : 15;
        }

        if ($weight <= 5) {
            $points += $isCar ? 2 : 0;
        } elseif ($weight <= 10) {
            $points += $isCar ? 5 : 3;
        } elseif ($weight <= 20) {
            $points += $isCar ? 8 : 6;
        } else {
            $points += $isCar ? 12 : 10;
        }

        $urgencyPoints = [
            'normal' => $isCar ? 3 : 0,
            'priority' => $isCar ? 6 : 3,
            'urgent' => $isCar ? 10 : 6,
        ];

        return $points + ($urgencyPoints[$urgency] ?? 0);
    }
}
