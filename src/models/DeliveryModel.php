<?php
/**
 * 配送任務與公益點數模型
 */

require_once __DIR__ . '/BaseModel.php';

class DeliveryModel extends BaseModel {
    protected $table = 'deliveries';

    public function __construct() {
        parent::__construct();
        $this->ensureDeliveryMethodColumn();
    }

    private function ensureDeliveryMethodColumn() {
        $result = $this->db->query("SHOW COLUMNS FROM deliveries LIKE 'delivery_method'");
        if ($result && $result->num_rows === 0) {
            $this->db->query(
                "ALTER TABLE deliveries ADD delivery_method ENUM('food_bank', 'volunteer', 'donor') NOT NULL DEFAULT 'volunteer' AFTER donation_id"
            );
        }
    }

    public function getAllDeliveries() {
        $sql = "SELECT d.*, u.full_name AS volunteer_name
                FROM deliveries d
                LEFT JOIN users u ON u.user_id = d.volunteer_id
                ORDER BY d.created_at DESC";
        return $this->query($sql);
    }

    public function createDelivery($data) {
        $deliveryMethod = $this->normalizeDeliveryMethod($data['delivery_method'] ?? 'volunteer');
        if ($deliveryMethod === false) {
            return false;
        }

        $donationId = $this->normalizeDonationId($data['donation_id'] ?? null);
        if ($donationId === false) {
            return false;
        }

        $data['donation_id'] = $donationId;
        $data['delivery_method'] = $deliveryMethod;
        $data['status'] = $deliveryMethod === 'volunteer' ? 'open' : 'claimed';
        if ($deliveryMethod === 'food_bank') {
            $data['volunteer_id'] = (int) ($data['created_by'] ?? 0);
        }
        $data['points'] = max(0, $this->calculatePoints(
            $data['vehicle_type'],
            (float) $data['total_distance_km'],
            (float) $data['weight_kg'],
            $data['urgency']
        ));
        $deliveryId = $this->insert($data);
        if (!$deliveryId) {
            return false;
        }

        if ($deliveryMethod === 'volunteer') {
            $notificationMessage = "新的配送任務 #{$deliveryId} 已發布，請至配送任務查看並接單。";
            $notifiedCount = $this->notifyUsersByRole('volunteer', '新的配送任務', $notificationMessage, 'info');
            if ($notifiedCount === 0) {
                error_log("配送任務發布後沒有可通知的志工：delivery_id={$deliveryId}");
            }
        }

        return $deliveryId;
    }

    public function getDeliveryById($deliveryId) {
        $deliveryId = (int) $deliveryId;
        $result = $this->db->query("SELECT * FROM deliveries WHERE delivery_id = {$deliveryId} LIMIT 1");
        return $result ? $result->fetch_assoc() : null;
    }

    public function canManageDelivery($deliveryId, $userId, $userRole) {
        if (!in_array($userRole, ['admin', 'foodbank_staff'], true)) {
            return false;
        }

        $deliveryId = (int) $deliveryId;
        $userId = (int) $userId;
        $result = $this->db->query("SELECT created_by FROM deliveries WHERE delivery_id = {$deliveryId} LIMIT 1");
        if (!$result || $result->num_rows === 0) {
            return false;
        }

        $delivery = $result->fetch_assoc();
        return (int) ($delivery['created_by'] ?? 0) === $userId
            || in_array($userRole, ['admin', 'foodbank_staff'], true);
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
        $deliveryMethod = $this->normalizeDeliveryMethod($data['delivery_method'] ?? 'volunteer');
        if ($deliveryMethod === false) {
            return false;
        }
        $pickupAddress = $this->db->real_escape_string(trim((string) ($data['pickup_address'] ?? '')));
        $deliveryAddress = $this->db->real_escape_string(trim((string) ($data['delivery_address'] ?? '忠信食物銀行')));
        $donationId = $this->normalizeDonationId($data['donation_id'] ?? null);
        if ($donationId === false) {
            return false;
        }
        $donationValue = $donationId === null ? 'NULL' : (string) $donationId;
        $points = max(0, $this->calculatePoints($vehicleType, $distance, $weight, $urgency));

        if ($pickupAddress === '' || $deliveryAddress === '') {
            return false;
        }

        $sql = "UPDATE deliveries SET donation_id = {$donationValue}, delivery_method = '{$deliveryMethod}', vehicle_type = '{$vehicleType}', total_distance_km = {$distance}, weight_kg = {$weight}, urgency = '{$urgency}', points = {$points}, pickup_address = '{$pickupAddress}', delivery_address = '{$deliveryAddress}', updated_at = NOW() WHERE delivery_id = {$deliveryId} LIMIT 1";
        return $this->db->query($sql);
    }

    private function normalizeDeliveryMethod($deliveryMethod) {
        return in_array($deliveryMethod, ['food_bank', 'volunteer', 'donor'], true) ? $deliveryMethod : false;
    }

    private function normalizeDonationId($donationId) {
        if (!is_scalar($donationId)) {
            return false;
        }

        if ($donationId === null || trim((string) $donationId) === '' || (int) $donationId === 0) {
            return null;
        }

        if (!filter_var($donationId, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 2147483647],
        ])) {
            return false;
        }

        return (int) $donationId;
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
        $updated = $this->db->query("UPDATE deliveries SET volunteer_id = {$volunteerId}, status = 'claimed' WHERE delivery_id = {$deliveryId} AND delivery_method = 'volunteer' AND status = 'open'");
        if ($updated && $this->db->affected_rows > 0) {
            $notifiedCount = $this->notifyUsersByRoles(
                ['foodbank_staff', 'admin'],
                '配送任務已接單',
                "配送任務 #{$deliveryId} 已由志工接單。",
                'info'
            );
            if ($notifiedCount === 0) {
                error_log("志工接單通知沒有官方收件人：delivery_id={$deliveryId}");
            }
        }
        return $updated && $this->db->affected_rows === 1;
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
        $wasUpdated = $updated && $this->db->affected_rows > 0;
        if ($wasUpdated) {
            $notificationMessage = "配送任務 #{$deliveryId}：{$notes}";
            $notifiedCount = $this->notifyUsersByRoles(['foodbank_staff', 'admin'], '配送異常回報', $notificationMessage, 'warning');
            if ($notifiedCount === 0) {
                error_log("配送異常通知沒有收件人：delivery_id={$deliveryId}");
            }
        }
        return $wasUpdated;
    }

    public function updateDeliveryStatus($deliveryId, $status, $volunteerId = null) {
        $deliveryId = (int) $deliveryId;
        $validStatuses = ['waiting_pickup', 'collected', 'in_transit', 'delivered'];

        if (!in_array($status, $validStatuses, true)) {
            return false;
        }

        $statusUpdateFields = '';
        switch ($status) {
            case 'collected':
                $statusUpdateFields = ", collected_at = NOW()";
                break;
            case 'in_transit':
                $statusUpdateFields = ", transit_at = NOW()";
                break;
            case 'delivered':
                $statusUpdateFields = ", delivered_at = NOW()";
                break;
        }

        $sql = "UPDATE deliveries SET status = '{$status}'{$statusUpdateFields}, updated_at = NOW() WHERE delivery_id = {$deliveryId}";
        return $this->db->query($sql);
    }

    public function completeDelivery($deliveryId) {
        $deliveryId = (int) $deliveryId;
        $result = $this->db->query("SELECT volunteer_id, points FROM deliveries WHERE delivery_id = {$deliveryId} AND status IN ('claimed', 'picked_up', 'in_transit') LIMIT 1");
        $delivery = $result ? $result->fetch_assoc() : null;

        if (!$delivery) {
            return false;
        }

        $this->db->begin_transaction();
        try {
            $this->db->query("UPDATE deliveries SET status = 'delivered', delivered_at = NOW() WHERE delivery_id = {$deliveryId} AND status IN ('claimed', 'picked_up', 'in_transit')");
            $volunteerId = (int) $delivery['volunteer_id'];
            $points = (int) $delivery['points'];
            if ($volunteerId > 0) {
                $this->db->query("INSERT INTO point_transactions (user_id, delivery_id, points, transaction_type, description) VALUES ({$volunteerId}, {$deliveryId}, {$points}, 'earned', '完成惜食配送')");
            }
            $this->db->commit();
            if ($volunteerId > 0) {
                $this->notifyUser($volunteerId, '配送已完成', "配送任務 #{$deliveryId} 已確認收貨，獲得 {$points} 點公益點數。", 'success');
            }
            return true;
        } catch (Throwable $exception) {
            $this->db->rollback();
            return false;
        }
    }

    private function notifyUser($userId, $title, $message, $type) {
        require_once __DIR__ . '/NotificationModel.php';
        return (new NotificationModel())->notify((int) $userId, $title, $message, $type);
    }

    private function notifyUsersByRole($role, $title, $message, $type) {
        $role = $this->db->real_escape_string($role);
        $result = $this->db->query("SELECT user_id FROM users WHERE role = '{$role}' AND status = 'active'");
        $notifiedCount = 0;
        if ($result) {
            while ($user = $result->fetch_assoc()) {
                if ($this->notifyUser((int) $user['user_id'], $title, $message, $type)) {
                    $notifiedCount++;
                }
            }
        }
        return $notifiedCount;
    }

    private function notifyUsersByRoles(array $roles, $title, $message, $type) {
        $escapedRoles = array_map(function ($role) {
            return "'" . $this->db->real_escape_string($role) . "'";
        }, $roles);
        $result = $this->db->query(
            'SELECT user_id FROM users WHERE role IN (' . implode(',', $escapedRoles) . ") AND status = 'active'"
        );
        $notifiedCount = 0;
        if ($result) {
            while ($user = $result->fetch_assoc()) {
                if ($this->notifyUser((int) $user['user_id'], $title, $message, $type)) {
                    $notifiedCount++;
                }
            }
        }
        return $notifiedCount;
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
