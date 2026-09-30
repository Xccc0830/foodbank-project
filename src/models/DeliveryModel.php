<?php
/**
 * 配送任務與興毅幣模型
 */

require_once __DIR__ . '/BaseModel.php';

class DeliveryModel extends BaseModel {
    protected $table = 'deliveries';

    public function __construct() {
        parent::__construct();
        $this->ensureDeliveryMethodColumn();
        $this->ensureExceptionResponseColumns();
        $this->ensureDeliveryLocationsTable();
    }

    private function ensureDeliveryMethodColumn() {
        $result = $this->db->query("SHOW COLUMNS FROM deliveries LIKE 'delivery_method'");
        if ($result && $result->num_rows === 0) {
            $this->db->query(
                "ALTER TABLE deliveries ADD delivery_method ENUM('food_bank', 'volunteer') NOT NULL DEFAULT 'volunteer' AFTER donation_id"
            );
        }
    }

    private function ensureExceptionResponseColumns() {
        $columns = [
            'exception_response' => "ALTER TABLE deliveries ADD exception_response TEXT DEFAULT NULL AFTER exception_notes",
            'exception_resolved_at' => "ALTER TABLE deliveries ADD exception_resolved_at DATETIME DEFAULT NULL AFTER exception_response",
            'exception_resolved_by' => "ALTER TABLE deliveries ADD exception_resolved_by INT DEFAULT NULL AFTER exception_resolved_at",
        ];
        foreach ($columns as $column => $alterSql) {
            $result = $this->db->query("SHOW COLUMNS FROM deliveries LIKE '{$column}'");
            if ($result && $result->num_rows === 0) {
                $this->db->query($alterSql);
            }
        }
    }

    private function ensureDeliveryLocationsTable() {
        $existing = $this->db->query("SHOW TABLES LIKE 'delivery_locations'");
        if (!$existing) {
            throw new RuntimeException('無法確認配送定位資料表：' . $this->db->error);
        }
        if ($existing->num_rows > 0) {
            return;
        }

        $created = $this->db->query(
            "CREATE TABLE delivery_locations (
                delivery_id INT NOT NULL,
                latitude DECIMAL(10,7) DEFAULT NULL,
                longitude DECIMAL(10,7) DEFAULT NULL,
                accuracy_meters DECIMAL(8,2) DEFAULT NULL,
                is_sharing TINYINT(1) NOT NULL DEFAULT 0,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (delivery_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
        if (!$created) {
            throw new RuntimeException('無法建立配送定位資料表：' . $this->db->error);
        }
    }

    private function clearExpiredLiveLocations() {
        $deleted = $this->db->query(
            "DELETE FROM delivery_locations
             WHERE updated_at < NOW() - INTERVAL 2 MINUTE"
        );
        if (!$deleted) {
            throw new RuntimeException('無法清除逾時的配送定位資料：' . $this->db->error);
        }
    }

    public function getAllDeliveries() {
        $this->clearExpiredLiveLocations();
        $sql = "SELECT d.*, n.order_number, n.donor_name, u.full_name AS volunteer_name,
                       COALESCE(dl.is_sharing, 0) AS is_location_sharing
                FROM deliveries d
                LEFT JOIN donations n ON n.donation_id = d.donation_id
                LEFT JOIN users u ON u.user_id = d.volunteer_id
                LEFT JOIN delivery_locations dl ON dl.delivery_id = d.delivery_id
                ORDER BY d.created_at DESC";
        return $this->enrichTasksWithItemSummary($this->query($sql));
    }

    public function getLiveTracking($deliveryId, $userId, $userRole) {
        $this->clearExpiredLiveLocations();
        $deliveryId = (int) $deliveryId;
        $userId = (int) $userId;
        $accessCondition = $userRole === 'foodbank_staff'
            ? '1 = 1'
            : "(d.volunteer_id = {$userId} OR n.donor_id = {$userId})";
        $result = $this->db->query(
            "SELECT d.delivery_id, d.status, d.pickup_address, d.delivery_address,
                    u.full_name AS courier_name,
                    d.vehicle_type,
                    n.delivery_date, n.delivery_time,
                    (
                        SELECT COUNT(*)
                        FROM deliveries other_delivery
                        WHERE other_delivery.volunteer_id = d.volunteer_id
                          AND other_delivery.delivery_id <> d.delivery_id
                          AND other_delivery.delivery_method = 'volunteer'
                          AND other_delivery.status IN ('claimed', 'picked_up')
                    ) AS other_active_delivery_count,
                    CASE WHEN d.status = 'picked_up' AND dl.is_sharing = 1 THEN dl.latitude ELSE NULL END AS latitude,
                    CASE WHEN d.status = 'picked_up' AND dl.is_sharing = 1 THEN dl.longitude ELSE NULL END AS longitude,
                    CASE WHEN d.status = 'picked_up' AND dl.is_sharing = 1 THEN dl.accuracy_meters ELSE NULL END AS accuracy_meters,
                    CASE WHEN d.status = 'picked_up' AND dl.is_sharing = 1 THEN UNIX_TIMESTAMP(dl.updated_at) ELSE NULL END AS location_updated_at,
                    CASE WHEN d.status = 'picked_up' AND dl.is_sharing = 1 THEN 1 ELSE 0 END AS is_sharing
             FROM deliveries d
             LEFT JOIN donations n ON n.donation_id = d.donation_id
             LEFT JOIN users u ON u.user_id = d.volunteer_id
             LEFT JOIN delivery_locations dl ON dl.delivery_id = d.delivery_id
             WHERE d.delivery_id = {$deliveryId} AND {$accessCondition}
             LIMIT 1"
        );
        if (!$result) {
            return false;
        }
        return $result->fetch_assoc() ?: null;
    }

    public function updateLiveLocation($deliveryId, $volunteerId, $latitude, $longitude, $accuracyMeters) {
        $deliveryId = (int) $deliveryId;
        $volunteerId = (int) $volunteerId;
        if (!is_numeric($latitude) || !is_numeric($longitude) || !is_numeric($accuracyMeters)) {
            return false;
        }

        $latitude = (float) $latitude;
        $longitude = (float) $longitude;
        $accuracyMeters = (float) $accuracyMeters;
        if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180 || $accuracyMeters < 0 || $accuracyMeters > 100000) {
            return false;
        }

        $eligible = $this->db->query(
            "SELECT delivery_id FROM deliveries
             WHERE delivery_id = {$deliveryId}
               AND volunteer_id = {$volunteerId}
               AND delivery_method = 'volunteer'
               AND status = 'picked_up'
             LIMIT 1"
        );
        if (!$eligible) {
            return false;
        }
        if ($eligible->num_rows === 0) {
            return false;
        }

        return $this->db->query(
            "INSERT INTO delivery_locations (delivery_id, latitude, longitude, accuracy_meters, is_sharing)
             VALUES ({$deliveryId}, {$latitude}, {$longitude}, {$accuracyMeters}, 1)
             ON DUPLICATE KEY UPDATE
                latitude = VALUES(latitude),
                longitude = VALUES(longitude),
                accuracy_meters = VALUES(accuracy_meters),
                is_sharing = 1,
                updated_at = CURRENT_TIMESTAMP"
        );
    }

    public function stopLiveLocation($deliveryId, $volunteerId) {
        $deliveryId = (int) $deliveryId;
        $volunteerId = (int) $volunteerId;
        $result = $this->db->query(
            "DELETE dl FROM delivery_locations dl
             INNER JOIN deliveries d ON d.delivery_id = dl.delivery_id
             WHERE d.delivery_id = {$deliveryId} AND d.volunteer_id = {$volunteerId}"
        );
        return (bool) $result;
    }

    private function clearLiveLocation($deliveryId) {
        return $this->db->query("DELETE FROM delivery_locations WHERE delivery_id = " . (int) $deliveryId);
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
            $notifiedCount = $this->notifyGeneralMembers('新的配送任務', $notificationMessage, 'info');
            if ($notifiedCount === 0) {
                error_log("配送任務發布後沒有可通知的忠信GO RIDER：delivery_id={$deliveryId}");
            }
        }

        return $deliveryId;
    }

    public function getDeliveryById($deliveryId) {
        $deliveryId = (int) $deliveryId;
        $result = $this->db->query("SELECT * FROM deliveries WHERE delivery_id = {$deliveryId} LIMIT 1");
        return $result ? $result->fetch_assoc() : null;
    }

    public function getDonationDeliveryProgress($donationId) {
        $donationId = (int) $donationId;
        $result = $this->db->query("SELECT COUNT(*) AS total_tasks,
                           SUM(CASE WHEN volunteer_id IS NOT NULL THEN 1 ELSE 0 END) AS accepted_tasks,
                           SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) AS completed_tasks
                                    FROM deliveries
                                    WHERE donation_id = {$donationId}
                                      AND delivery_method = 'volunteer'");
        $progress = $result ? $result->fetch_assoc() : null;
        return [
            'total_tasks' => (int) ($progress['total_tasks'] ?? 0),
            'accepted_tasks' => (int) ($progress['accepted_tasks'] ?? 0),
            'completed_tasks' => (int) ($progress['completed_tasks'] ?? 0),
        ];
    }

    public function getDonorTransportTasks($donorId) {
        $donorId = (int) $donorId;
        $sql = "SELECT d.*, n.donor_name, n.donor_address, n.donation_type, n.item_name,
                       n.quantity, n.unit, n.weight_kg AS published_weight_kg,
                       n.size_description, n.photo_path, n.pickup_deadline,
                       n.estimated_distance_km, n.estimated_duration_minutes
                FROM deliveries d
                INNER JOIN donations n ON n.donation_id = d.donation_id
                WHERE n.donor_id = {$donorId}
                  AND n.status = 'published'
                  AND d.delivery_method = 'volunteer'
                  AND d.status IN ('open', 'claimed', 'picked_up')
                ORDER BY n.published_at DESC, d.delivery_id ASC";
        return $this->enrichTasksWithItemSummary($this->query($sql));
    }

    public function donorConfirmPickup($deliveryId, $donorId) {
        $deliveryId = (int) $deliveryId;
        $donorId = (int) $donorId;
        return $this->db->query("UPDATE deliveries d
            INNER JOIN donations n ON n.donation_id = d.donation_id
            SET d.status = 'picked_up', d.pickup_confirmed_at = NOW(), d.updated_at = NOW()
            WHERE d.delivery_id = {$deliveryId}
              AND n.donor_id = {$donorId}
              AND d.delivery_method = 'volunteer'
              AND d.status = 'claimed'") && $this->db->affected_rows === 1;
    }

    public function completeDonationDeliveries($donationId) {
        $donationId = (int) $donationId;
        $result = $this->db->query("SELECT delivery_id, volunteer_id, points FROM deliveries WHERE donation_id = {$donationId} AND status IN ('claimed', 'picked_up')");
        if (!$result || $result->num_rows === 0) {
            return false;
        }

        $this->db->begin_transaction();
        try {
            while ($delivery = $result->fetch_assoc()) {
                $deliveryId = (int) $delivery['delivery_id'];
                $this->db->query("UPDATE deliveries SET status = 'delivered', delivered_at = NOW(), updated_at = NOW() WHERE delivery_id = {$deliveryId} AND status IN ('claimed', 'picked_up')");
                if (!$this->clearLiveLocation($deliveryId)) {
                    throw new RuntimeException('無法清除已完成配送的定位資料。');
                }
                $volunteerId = (int) ($delivery['volunteer_id'] ?? 0);
                if ($volunteerId > 0) {
                    $points = (int) ($delivery['points'] ?? 0);
                    $this->db->query("INSERT INTO point_transactions (user_id, delivery_id, points, transaction_type, description) VALUES ({$volunteerId}, {$deliveryId}, {$points}, 'earned', '完成惜食配送')");
                }
            }
            $this->db->commit();
            return true;
        } catch (Throwable $exception) {
            $this->db->rollback();
            return false;
        }
    }

        public function getMaterialTransportTasks() {
                $sql = "SELECT d.*, n.donor_name, n.donor_address, n.donation_type, n.item_name,
                                             n.quantity, n.unit, n.weight_kg AS published_weight_kg,
                                             n.size_description, n.photo_path, n.pickup_deadline,
                                             n.need_inspection, n.inspection_notes, n.reward_options,
                                             n.split_count
                                FROM deliveries d
                                INNER JOIN donations n ON n.donation_id = d.donation_id
                                WHERE n.status = 'published'
                                    AND d.delivery_method = 'volunteer'
                                    AND d.status IN ('open', 'claimed')
                                ORDER BY n.published_at DESC, d.delivery_id ASC";
                return $this->enrichTasksWithItemSummary($this->query($sql));
        }

        public function getMaterialTransportTask($deliveryId) {
                $deliveryId = (int) $deliveryId;
                $result = $this->db->query("SELECT d.*, n.donor_name, n.donor_address, n.donation_type, n.item_name,
                                                                                     n.quantity, n.unit, n.weight_kg AS published_weight_kg,
                                                                                     n.size_description, n.photo_path, n.pickup_deadline,
                                                                                     n.need_inspection, n.inspection_notes, n.reward_options,
                                                                                     n.split_count, n.status AS donation_status
                                                                        FROM deliveries d
                                                                        INNER JOIN donations n ON n.donation_id = d.donation_id
                                                                        WHERE d.delivery_id = {$deliveryId}
                                                                            AND n.status = 'published'
                                                                            AND d.delivery_method = 'volunteer'
                                                                        LIMIT 1");
                $row = $result ? $result->fetch_assoc() : null;
                if (!$row) {
                        return null;
                }
                $enriched = $this->enrichTasksWithItemSummary([$row]);
                return $enriched[0] ?? $row;
        }

            public function getMaterialTransportHistoryTasks($volunteerId) {
                $volunteerId = (int) $volunteerId;
                $sql = "SELECT d.*, n.donor_name, n.donor_address, n.donation_type, n.item_name,
                                 n.quantity, n.unit, n.weight_kg AS published_weight_kg,
                                 n.size_description, n.photo_path, n.pickup_deadline
                        FROM deliveries d
                        INNER JOIN donations n ON n.donation_id = d.donation_id
                        WHERE n.status = 'published'
                            AND d.delivery_method = 'volunteer'
                            AND d.volunteer_id = {$volunteerId}
                            AND d.status = 'delivered'
                        ORDER BY d.delivered_at DESC, d.delivery_id DESC";
                return $this->enrichTasksWithItemSummary($this->query($sql));
            }

    /**
     * 補上物資件數與長寬高摘要，供 Rider 任務卡片/詳情顯示。
     * 若配送任務已綁定特定子單 (allocation_id)，僅彙總該子單的物資；
     * 否則彙總整張主單 (donation_id) 的物資。
     */
    private function enrichTasksWithItemSummary(array $rows) {
        if (empty($rows)) {
            return $rows;
        }

        $allocationIds = [];
        $donationIds = [];
        foreach ($rows as $row) {
            if (!empty($row['allocation_id'])) {
                $allocationIds[] = (int) $row['allocation_id'];
            } elseif (!empty($row['donation_id'])) {
                $donationIds[] = (int) $row['donation_id'];
            }
        }

        $allocationSummaries = [];
        if (!empty($allocationIds)) {
            $ids = implode(',', array_unique($allocationIds));
            foreach ($this->query(
                "SELECT ai.allocation_id, COUNT(*) AS item_count,
                        MAX(di.length_cm) AS max_length_cm, MAX(di.width_cm) AS max_width_cm, MAX(di.height_cm) AS max_height_cm,
                        SUM(di.item_weight_kg) AS total_item_weight_kg
                 FROM donation_allocation_items ai
                 JOIN donation_items di ON di.item_id = ai.donation_item_id
                 WHERE ai.allocation_id IN ({$ids})
                 GROUP BY ai.allocation_id"
            ) as $summaryRow) {
                $allocationSummaries[(int) $summaryRow['allocation_id']] = $summaryRow;
            }
        }

        $donationSummaries = [];
        if (!empty($donationIds)) {
            $ids = implode(',', array_unique($donationIds));
            foreach ($this->query(
                "SELECT donation_id, COUNT(*) AS item_count,
                        MAX(length_cm) AS max_length_cm, MAX(width_cm) AS max_width_cm, MAX(height_cm) AS max_height_cm,
                        SUM(item_weight_kg) AS total_item_weight_kg
                 FROM donation_items
                 WHERE donation_id IN ({$ids})
                 GROUP BY donation_id"
            ) as $summaryRow) {
                $donationSummaries[(int) $summaryRow['donation_id']] = $summaryRow;
            }
        }

        foreach ($rows as &$row) {
            $summary = !empty($row['allocation_id'])
                ? ($allocationSummaries[(int) $row['allocation_id']] ?? null)
                : ($donationSummaries[(int) ($row['donation_id'] ?? 0)] ?? null);
            $row['item_count'] = $summary ? (int) $summary['item_count'] : 0;
            $row['max_length_cm'] = $summary['max_length_cm'] ?? null;
            $row['max_width_cm'] = $summary['max_width_cm'] ?? null;
            $row['max_height_cm'] = $summary['max_height_cm'] ?? null;
            $row['total_item_weight_kg'] = $summary['total_item_weight_kg'] ?? null;
        }
        unset($row);

        return $rows;
    }

    public function canManageDelivery($deliveryId, $userId, $userRole) {
        if ($userRole !== 'foodbank_staff') {
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
            || $userRole === 'foodbank_staff';
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
        $status = in_array(($data['status'] ?? 'open'), ['open', 'claimed', 'picked_up', 'exception', 'cancelled'], true)
            ? $data['status']
            : 'open';
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

        $sql = "UPDATE deliveries SET donation_id = {$donationValue}, delivery_method = '{$deliveryMethod}', vehicle_type = '{$vehicleType}', total_distance_km = {$distance}, weight_kg = {$weight}, urgency = '{$urgency}', status = '{$status}', points = {$points}, pickup_address = '{$pickupAddress}', delivery_address = '{$deliveryAddress}', updated_at = NOW() WHERE delivery_id = {$deliveryId} LIMIT 1";
        $this->db->begin_transaction();
        $updated = $this->db->query($sql);
        if (!$updated || (($status !== 'picked_up' || $deliveryMethod !== 'volunteer') && !$this->clearLiveLocation($deliveryId))) {
            $this->db->rollback();
            return false;
        }
        return $this->db->commit();
    }

    public function updateException($deliveryId, $userId, $userRole, $notes, $response) {
        if (!$this->canManageDelivery($deliveryId, $userId, $userRole)) {
            return false;
        }

        $deliveryId = (int) $deliveryId;
        $notes = trim((string) $notes);
        $response = trim((string) $response);
        $notesEscaped = $this->db->real_escape_string($notes);
        $responseEscaped = $this->db->real_escape_string($response);

        return $this->db->query(
            "UPDATE deliveries
             SET exception_notes = '{$notesEscaped}',
                 exception_response = '{$responseEscaped}',
                 updated_at = NOW()
             WHERE delivery_id = {$deliveryId}
             LIMIT 1"
        );
    }

    private function normalizeDeliveryMethod($deliveryMethod) {
        return in_array($deliveryMethod, ['food_bank', 'volunteer'], true) ? $deliveryMethod : false;
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

        $deliveryId = (int) $deliveryId;
        if (!$this->clearLiveLocation($deliveryId)) {
            return false;
        }
        return $this->db->query("DELETE FROM deliveries WHERE delivery_id = {$deliveryId} LIMIT 1");
    }

    public function claimDelivery($deliveryId, $volunteerId) {
        $deliveryId = (int) $deliveryId;
        $volunteerId = (int) $volunteerId;
        $updated = $this->db->query("UPDATE deliveries SET volunteer_id = {$volunteerId}, status = 'claimed' WHERE delivery_id = {$deliveryId} AND delivery_method = 'volunteer' AND status = 'open'");
        if ($updated && $this->db->affected_rows > 0) {
            $notifiedCount = $this->notifyUsersByRoles(
                ['foodbank_staff'],
                '配送任務已接單',
                "配送任務 #{$deliveryId} 已由忠信GO RIDER接單。",
                'info'
            );
            if ($notifiedCount === 0) {
                error_log("忠信GO RIDER接單通知沒有官方收件人：delivery_id={$deliveryId}");
            }
        }
        return $updated && $this->db->affected_rows === 1;
    }

    public function cancelClaimedDelivery($deliveryId, $volunteerId) {
        $deliveryId = (int) $deliveryId;
        $volunteerId = (int) $volunteerId;
        $updated = $this->db->query("UPDATE deliveries SET volunteer_id = NULL, status = 'open', updated_at = NOW() WHERE delivery_id = {$deliveryId} AND delivery_method = 'volunteer' AND status = 'claimed' AND volunteer_id = {$volunteerId}");
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
            if (!$this->clearLiveLocation($deliveryId)) {
                error_log("配送異常後清除定位失敗：delivery_id={$deliveryId}");
            }
            $notificationMessage = "配送任務 #{$deliveryId}：{$notes}";
            $notifiedCount = $this->notifyUsersByRoles(['foodbank_staff'], '配送異常回報', $notificationMessage, 'warning');
            if ($notifiedCount === 0) {
                error_log("配送異常通知沒有收件人：delivery_id={$deliveryId}");
            }
        }
        return $wasUpdated;
    }

    public function resolveException($deliveryId, $officialUserId, $resolution, $nextStatus) {
        $deliveryId = (int) $deliveryId;
        $officialUserId = (int) $officialUserId;
        $resolution = trim((string) $resolution);
        $nextStatus = in_array($nextStatus, ['claimed', 'cancelled'], true) ? $nextStatus : 'claimed';

        if ($resolution === '') {
            return false;
        }

        $resolutionEscaped = $this->db->real_escape_string($resolution);
        $result = $this->db->query(
            "SELECT volunteer_id FROM deliveries
             WHERE delivery_id = {$deliveryId} AND status = 'exception' LIMIT 1"
        );
        $delivery = $result ? $result->fetch_assoc() : null;
        if (!$delivery) {
            return false;
        }

        $updated = $this->db->query(
            "UPDATE deliveries
             SET status = '{$nextStatus}',
                 exception_response = '{$resolutionEscaped}',
                 exception_resolved_at = NOW(),
                 exception_resolved_by = {$officialUserId},
                 updated_at = NOW()
             WHERE delivery_id = {$deliveryId} AND status = 'exception'"
        );
        if (!$updated || $this->db->affected_rows !== 1) {
            return false;
        }

        $volunteerId = (int) ($delivery['volunteer_id'] ?? 0);
        if ($volunteerId > 0) {
            $this->notifyUser(
                $volunteerId,
                '配送異常已處理',
                "配送任務 #{$deliveryId} 的異常處理結果：{$resolution}",
                'info'
            );
        }
        return true;
    }

    public function rejectException($deliveryId, $officialUserId, $response) {
        $deliveryId = (int) $deliveryId;
        $officialUserId = (int) $officialUserId;
        $response = trim((string) $response);
        if ($response === '') {
            return false;
        }

        $responseEscaped = $this->db->real_escape_string($response);
        $updated = $this->db->query(
            "UPDATE deliveries
             SET status = 'claimed',
                 exception_response = '{$responseEscaped}',
                 exception_resolved_at = NOW(),
                 exception_resolved_by = {$officialUserId},
                 updated_at = NOW()
             WHERE delivery_id = {$deliveryId} AND status IN ('exception', 'cancelled')"
        );
        if (!$updated || $this->db->affected_rows !== 1) {
            return false;
        }

        $result = $this->db->query("SELECT volunteer_id FROM deliveries WHERE delivery_id = {$deliveryId} LIMIT 1");
        $delivery = $result ? $result->fetch_assoc() : null;
        $volunteerId = (int) ($delivery['volunteer_id'] ?? 0);
        if ($volunteerId > 0) {
            $this->notifyUser(
                $volunteerId,
                '異常回報已駁回',
                "配送任務 #{$deliveryId} 的異常回報已駁回：{$response}",
                'info'
            );
        }
        return true;
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
        return $this->finalizeDeliveryCompletion($deliveryId, null);
    }

    /**
     * 供配送會員在自己的配送任務「完成行程」時呼叫，僅限本人已取貨的任務。
     */
    public function completeDeliveryByVolunteer($deliveryId, $volunteerId) {
        $volunteerId = (int) $volunteerId;
        if ($volunteerId <= 0) {
            return false;
        }
        return $this->finalizeDeliveryCompletion($deliveryId, $volunteerId);
    }

    private function finalizeDeliveryCompletion($deliveryId, $requiredVolunteerId) {
        $deliveryId = (int) $deliveryId;
        $ownerCondition = $requiredVolunteerId !== null
            ? " AND volunteer_id = {$requiredVolunteerId} AND delivery_method = 'volunteer' AND status = 'picked_up'"
            : " AND status IN ('claimed', 'picked_up', 'in_transit')";

        $result = $this->db->query("SELECT volunteer_id, points FROM deliveries WHERE delivery_id = {$deliveryId}{$ownerCondition} LIMIT 1");
        $delivery = $result ? $result->fetch_assoc() : null;

        if (!$delivery) {
            return false;
        }

        $this->db->begin_transaction();
        try {
            $this->db->query("UPDATE deliveries SET status = 'delivered', delivered_at = NOW() WHERE delivery_id = {$deliveryId}{$ownerCondition}");
            if ($this->db->affected_rows !== 1) {
                throw new RuntimeException('配送任務狀態已變更，無法完成行程。');
            }
            if (!$this->clearLiveLocation($deliveryId)) {
                throw new RuntimeException('無法清除已完成配送的定位資料。');
            }
            $volunteerId = (int) $delivery['volunteer_id'];
            $points = (int) $delivery['points'];
            if ($volunteerId > 0) {
                $this->db->query("INSERT INTO point_transactions (user_id, delivery_id, points, transaction_type, description) VALUES ({$volunteerId}, {$deliveryId}, {$points}, 'earned', '完成惜食配送')");
            }
            $this->db->commit();
            if ($volunteerId > 0) {
                $this->notifyUser($volunteerId, '配送已完成', "配送任務 #{$deliveryId} 已確認收貨，獲得 {$points} 枚興毅幣。", 'success');
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

    private function notifyGeneralMembers($title, $message, $type) {
        $result = $this->db->query("SELECT user_id FROM users WHERE role = 'member' AND member_type = 'general' AND status = 'active'");
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
