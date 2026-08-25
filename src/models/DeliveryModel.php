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

    public function getDeliveryById($deliveryId) {
        $deliveryId = (int) $deliveryId;
        $result = $this->db->query("SELECT * FROM deliveries WHERE delivery_id = {$deliveryId} LIMIT 1");
        return $result ? $result->fetch_assoc() : null;
    }

    public function canManageDelivery($deliveryId, $userId, $userRole) {
        $deliveryId = (int) $deliveryId;
        $userId = (int) $userId;
        $result = $this->db->query("SELECT created_by FROM deliveries WHERE delivery_id = {$deliveryId} LIMIT 1");
        if (!$result || $result->num_rows === 0) {
            return false;
        }

        $delivery = $result->fetch_assoc();
        if ((int) ($delivery['created_by'] ?? 0) === $userId) {
            return true;
        }

        return in_array($userRole, ['admin', 'foodbank_staff'], true);
    }

    public function canDeleteDelivery($deliveryId, $userId, $userRole) {
        return $this->canManageDelivery($deliveryId, $userId, $userRole);
    }

    public function updateDelivery($deliveryId, $userId, $userRole, $data) {
        if (!$this->canManageDelivery($deliveryId, $userId, $userRole)) {
            return false;
        }

        $deliveryId = (int) $deliveryId;
        $vehicleType = in_array(($data['vehicle_type'] ?? 'motorcycle'), ['car', 'motorcycle'], true) ? $data['vehicle_type'] : 'motorcycle';
        $distance = (float) ($data['total_distance_km'] ?? 0);
        $weight = (float) ($data['weight_kg'] ?? 0);
        $urgency = in_array(($data['urgency'] ?? 'normal'), ['normal', 'priority', 'urgent'], true) ? $data['urgency'] : 'normal';
        $pickupAddress = $this->db->real_escape_string(trim((string) ($data['pickup_address'] ?? '')));
        $deliveryAddress = $this->db->real_escape_string(trim((string) ($data['delivery_address'] ?? '忠信食物銀行')));
        $donationId = (int) ($data['donation_id'] ?? 0);
        $points = $this->calculatePoints($vehicleType, $distance, $weight, $urgency);

        if ($pickupAddress === '' || $deliveryAddress === '') {
            return false;
        }

        $sql = "UPDATE deliveries SET donation_id = {$donationId}, vehicle_type = '{$vehicleType}', total_distance_km = {$distance}, weight_kg = {$weight}, urgency = '{$urgency}', points = {$points}, pickup_address = '{$pickupAddress}', delivery_address = '{$deliveryAddress}', updated_at = NOW() WHERE delivery_id = {$deliveryId} LIMIT 1";
        return $this->db->query($sql);
    }

    public function deleteDelivery($deliveryId, $userId, $userRole) {
        if (!$this->canDeleteDelivery($deliveryId, $userId, $userRole)) {
            return false;
        }

        return $this->db->query("DELETE FROM deliveries WHERE delivery_id = " . (int) $deliveryId . " LIMIT 1");
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
