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
        $this->ensurePickupPhotoColumn();
        $this->ensureArrivalWindowColumns();
        self::ensureDeliveryRewardColumns($this->db);
    }

    public static function ensureDeliveryRewardColumns($db) {
        $columns = [
            'estimated_duration_minutes' => "ALTER TABLE deliveries ADD estimated_duration_minutes SMALLINT UNSIGNED DEFAULT NULL AFTER total_distance_km",
            'picked_up_at' => "ALTER TABLE deliveries ADD picked_up_at DATETIME DEFAULT NULL AFTER pickup_confirmed_at",
        ];
        foreach ($columns as $column => $alterSql) {
            $result = $db->query("SHOW COLUMNS FROM deliveries LIKE '{$column}'");
            if ($result && $result->num_rows === 0) {
                $db->query($alterSql);
            }
        }
    }

    private function ensureArrivalWindowColumns() {
        $columns = [
            'estimated_arrival_start' => "ALTER TABLE deliveries ADD estimated_arrival_start DATETIME DEFAULT NULL AFTER pickup_photo_path",
            'estimated_arrival_end' => "ALTER TABLE deliveries ADD estimated_arrival_end DATETIME DEFAULT NULL AFTER estimated_arrival_start",
            'overdue_notified_at' => "ALTER TABLE deliveries ADD overdue_notified_at DATETIME DEFAULT NULL AFTER estimated_arrival_end",
        ];
        foreach ($columns as $column => $alterSql) {
            $result = $this->db->query("SHOW COLUMNS FROM deliveries LIKE '{$column}'");
            if ($result && $result->num_rows === 0) {
                $this->db->query($alterSql);
            }
        }
    }

    private function ensurePickupPhotoColumn() {
        $result = $this->db->query("SHOW COLUMNS FROM deliveries LIKE 'pickup_photo_path'");
        if ($result && $result->num_rows === 0) {
            $this->db->query("ALTER TABLE deliveries ADD pickup_photo_path VARCHAR(255) DEFAULT NULL AFTER pickup_confirmed_at");
        }
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

    public function getAllDeliveries() {
        $sql = "SELECT d.*, n.order_number, n.donor_name, u.full_name AS volunteer_name, u.phone AS volunteer_phone
                FROM deliveries d
                LEFT JOIN donations n ON n.donation_id = d.donation_id
                LEFT JOIN users u ON u.user_id = d.volunteer_id
                ORDER BY d.created_at DESC";
        return $this->enrichTasksWithItemSummary($this->query($sql));
    }

    /**
     * 志工取貨後依物資預估運送時間設定預估送達範圍（起點為預估時間，終點多留緩衝）。
     */
    private function setArrivalWindow($deliveryId) {
        $deliveryId = (int) $deliveryId;
        $result = $this->db->query(
            "SELECT COALESCE(NULLIF(d.estimated_duration_minutes, 0), n.estimated_duration_minutes, 0) AS estimated_duration_minutes
             FROM deliveries d
             LEFT JOIN donations n ON n.donation_id = d.donation_id
             WHERE d.delivery_id = {$deliveryId} LIMIT 1"
        );
        $row = $result ? $result->fetch_assoc() : null;
        $minutes = (int) ($row['estimated_duration_minutes'] ?? 0);
        $duration = $minutes > 0 ? max(10, $minutes) : 30;
        $buffer = max(15, (int) ceil($duration / 2));
        $end = $duration + $buffer;
        $this->db->query(
            "UPDATE deliveries
             SET estimated_arrival_start = DATE_ADD(NOW(), INTERVAL {$duration} MINUTE),
                 estimated_arrival_end = DATE_ADD(NOW(), INTERVAL {$end} MINUTE),
                 overdue_notified_at = NULL
             WHERE delivery_id = {$deliveryId}"
        );
    }

    /**
     * 找出已取貨但超過預估送達時間的配送任務，通知食物銀行人員並附上志工聯絡方式。
     * 每筆任務只通知一次；回傳本次新通知的任務數。
     */
    public function notifyOverdueDeliveries() {
        $overdue = $this->query(
            "SELECT d.delivery_id, d.estimated_arrival_start, d.estimated_arrival_end,
                    n.order_number, u.full_name AS volunteer_name, u.phone AS volunteer_phone
             FROM deliveries d
             LEFT JOIN donations n ON n.donation_id = d.donation_id
             LEFT JOIN users u ON u.user_id = d.volunteer_id
             WHERE d.status = 'picked_up' AND d.delivery_method = 'volunteer'
               AND d.estimated_arrival_end IS NOT NULL AND d.estimated_arrival_end < NOW()
               AND d.overdue_notified_at IS NULL"
        );

        $notified = 0;
        foreach ($overdue as $delivery) {
            $deliveryId = (int) $delivery['delivery_id'];
            $this->db->query("UPDATE deliveries SET overdue_notified_at = NOW() WHERE delivery_id = {$deliveryId} AND overdue_notified_at IS NULL");
            if ($this->db->affected_rows !== 1) {
                continue;
            }

            $window = date('H:i', strtotime($delivery['estimated_arrival_start'])) . '–' . date('H:i', strtotime($delivery['estimated_arrival_end']));
            $order = !empty($delivery['order_number']) ? "（訂單 {$delivery['order_number']}）" : '';
            $contact = ($delivery['volunteer_name'] ?? '外送志工') . '，電話：' . ($delivery['volunteer_phone'] ?: '未提供');
            $this->notifyUsersByRoles(
                ['foodbank_staff'],
                '配送逾時提醒',
                "配送任務 #{$deliveryId}{$order} 已超過預估送達時間 {$window}，請聯繫外送志工：{$contact}。",
                'warning'
            );
            $notified++;
        }
        return $notified;
    }

    public function getDeliveryStatus($deliveryId, $userId, $userRole) {
        $deliveryId = (int) $deliveryId;
        $userId = (int) $userId;
        $accessCondition = $userRole === 'foodbank_staff'
            ? '1 = 1'
            : "(d.volunteer_id = {$userId} OR n.donor_id = {$userId})";
        $result = $this->db->query(
            "SELECT d.delivery_id, d.status, d.pickup_address, d.delivery_address,
                    u.full_name AS courier_name,
                    d.vehicle_type, d.estimated_arrival_start, d.estimated_arrival_end,
                    n.delivery_date, n.delivery_time,
                    (
                        SELECT COUNT(*)
                        FROM deliveries other_delivery
                        WHERE other_delivery.volunteer_id = d.volunteer_id
                          AND other_delivery.delivery_id <> d.delivery_id
                          AND other_delivery.delivery_method = 'volunteer'
                          AND other_delivery.status IN ('claimed', 'picked_up')
                    ) AS other_active_delivery_count
             FROM deliveries d
             LEFT JOIN donations n ON n.donation_id = d.donation_id
             LEFT JOIN users u ON u.user_id = d.volunteer_id
             WHERE d.delivery_id = {$deliveryId} AND {$accessCondition}
             LIMIT 1"
        );
        if (!$result) {
            return false;
        }
        $tracking = $result->fetch_assoc();
        return $tracking ?: null;
    }

    private function notifyDonor($deliveryId, $title, $message, $type = 'info') {
        $result = $this->db->query(
            "SELECT n.donor_id FROM deliveries d
             INNER JOIN donations n ON n.donation_id = d.donation_id
             WHERE d.delivery_id = " . (int) $deliveryId . " LIMIT 1"
        );
        $row = $result ? $result->fetch_assoc() : null;
        if ($row && (int) $row['donor_id'] > 0) {
            $this->notifyUser((int) $row['donor_id'], $title, $message, $type);
        }
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
        if (!isset($data['estimated_duration_minutes']) && $donationId !== null) {
            $durationResult = $this->db->query(
                "SELECT estimated_duration_minutes FROM donations WHERE donation_id = {$donationId} LIMIT 1"
            );
            $duration = $durationResult ? $durationResult->fetch_assoc() : null;
            $data['estimated_duration_minutes'] = $duration['estimated_duration_minutes'] ?? null;
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
            SET d.status = 'picked_up', d.pickup_confirmed_at = NOW(), d.picked_up_at = NOW(), d.updated_at = NOW()
            WHERE d.delivery_id = {$deliveryId}
              AND n.donor_id = {$donorId}
              AND d.delivery_method = 'volunteer'
              AND d.status = 'claimed'") && $this->db->affected_rows === 1;
    }

    public function completeDonationDeliveries($donationId) {
        $donationId = (int) $donationId;
        $result = $this->db->query(
            "SELECT d.delivery_id, d.volunteer_id, d.vehicle_type, d.total_distance_km, d.weight_kg, d.urgency,
                    d.estimated_duration_minutes, d.picked_up_at, d.pickup_confirmed_at,
                    COALESCE(NULLIF(d.estimated_duration_minutes, 0), n.estimated_duration_minutes, 0) AS reward_estimated_minutes
             FROM deliveries d
             LEFT JOIN donations n ON n.donation_id = d.donation_id
             WHERE d.donation_id = {$donationId} AND d.status IN ('claimed', 'picked_up')"
        );
        if (!$result || $result->num_rows === 0) {
            return false;
        }

        $this->db->begin_transaction();
        try {
            while ($delivery = $result->fetch_assoc()) {
                $deliveryId = (int) $delivery['delivery_id'];
                $updated = $this->db->query("UPDATE deliveries SET status = 'delivered', delivered_at = NOW(), updated_at = NOW() WHERE delivery_id = {$deliveryId} AND status IN ('claimed', 'picked_up')");
                if (!$updated || $this->db->affected_rows !== 1) {
                    throw new RuntimeException('配送任務狀態已變更，無法完成行程。');
                }
                $deliveredAt = $this->getDeliveredAt($deliveryId);
                if ($deliveredAt === null) {
                    throw new RuntimeException('無法取得配送完成時間。');
                }
                $volunteerId = (int) ($delivery['volunteer_id'] ?? 0);
                $points = $this->calculateCompletedDeliveryPoints($delivery, $deliveredAt);
                if (!$this->db->query("UPDATE deliveries SET points = {$points} WHERE delivery_id = {$deliveryId}")) {
                    throw new RuntimeException('無法更新配送點數。');
                }
                if ($volunteerId > 0) {
                    if (!$this->db->query("INSERT INTO point_transactions (user_id, delivery_id, points, transaction_type, description) VALUES ({$volunteerId}, {$deliveryId}, {$points}, 'earned', '完成惜食配送')")) {
                        throw new RuntimeException('無法記錄配送點數交易。');
                    }
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
        $estimatedDuration = null;
        if ($donationId !== null) {
            $durationResult = $this->db->query(
                "SELECT estimated_duration_minutes FROM donations WHERE donation_id = {$donationId} LIMIT 1"
            );
            $duration = $durationResult ? $durationResult->fetch_assoc() : null;
            $estimatedDuration = isset($duration['estimated_duration_minutes']) && (int) $duration['estimated_duration_minutes'] > 0
                ? (int) $duration['estimated_duration_minutes']
                : null;
        }
        $estimatedDurationValue = $estimatedDuration === null ? 'NULL' : (string) $estimatedDuration;
        $points = max(0, $this->calculatePoints($vehicleType, $distance, $weight, $urgency));
        if ($pickupAddress === '' || $deliveryAddress === '') {
            return false;
        }

        $sql = "UPDATE deliveries SET donation_id = {$donationValue}, delivery_method = '{$deliveryMethod}', vehicle_type = '{$vehicleType}', total_distance_km = {$distance}, estimated_duration_minutes = {$estimatedDurationValue}, weight_kg = {$weight}, urgency = '{$urgency}', status = '{$status}', points = {$points}, pickup_address = '{$pickupAddress}', delivery_address = '{$deliveryAddress}', updated_at = NOW() WHERE delivery_id = {$deliveryId} LIMIT 1";
        $this->db->begin_transaction();
        $updated = $this->db->query($sql);
        if (!$updated) {
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
        return $this->db->query("DELETE FROM deliveries WHERE delivery_id = {$deliveryId}");
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
            $this->notifyDonor($deliveryId, 'Go Rider 已接單', "配送任務 #{$deliveryId} 已由 Go Rider 接單，正在前往取貨。", 'info');
        }
        return $updated && $this->db->affected_rows === 1;
    }

    public function cancelClaimedDelivery($deliveryId, $volunteerId) {
        $deliveryId = (int) $deliveryId;
        $volunteerId = (int) $volunteerId;
        $updated = $this->db->query("UPDATE deliveries SET volunteer_id = NULL, status = 'open', updated_at = NOW() WHERE delivery_id = {$deliveryId} AND delivery_method = 'volunteer' AND status = 'claimed' AND volunteer_id = {$volunteerId}");
        return $updated && $this->db->affected_rows === 1;
    }

    public function confirmPickup($deliveryId, $volunteerId, $pickupPhotoPath) {
        $deliveryId = (int) $deliveryId;
        $volunteerId = (int) $volunteerId;
        $pickupPhotoPath = trim((string) $pickupPhotoPath);

        if ($pickupPhotoPath === '') {
            return false;
        }

        $photoValue = $this->db->real_escape_string($pickupPhotoPath);
        $updated = $this->db->query("UPDATE deliveries SET status = 'picked_up', pickup_confirmed_at = NOW(), picked_up_at = NOW(), pickup_photo_path = '{$photoValue}' WHERE delivery_id = {$deliveryId} AND volunteer_id = {$volunteerId} AND status = 'claimed'");
        $wasUpdated = $updated && $this->db->affected_rows === 1;
        if ($wasUpdated) {
            $this->setArrivalWindow($deliveryId);
            $this->notifyDonor($deliveryId, 'Go Rider 已取貨', "配送任務 #{$deliveryId} 物資已取貨，Go Rider 正在前往送達地點。", 'info');
        }
        return $wasUpdated;
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
                $statusUpdateFields = ", picked_up_at = NOW()";
                break;
            case 'in_transit':
                $statusUpdateFields = ", transit_at = NOW()";
                break;
            case 'delivered':
                return $this->finalizeDeliveryCompletion($deliveryId);
        }

        $sql = "UPDATE deliveries SET status = '{$status}'{$statusUpdateFields}, updated_at = NOW() WHERE delivery_id = {$deliveryId}";
        return $this->db->query($sql);
    }

    public function completeDelivery($deliveryId) {
        return $this->finalizeDeliveryCompletion($deliveryId);
    }

    private function finalizeDeliveryCompletion($deliveryId) {
        $deliveryId = (int) $deliveryId;
        $ownerCondition = " AND status IN ('claimed', 'picked_up', 'in_transit')";

        $result = $this->db->query(
            "SELECT d.volunteer_id, d.vehicle_type, d.total_distance_km, d.weight_kg, d.urgency,
                    d.estimated_duration_minutes, d.picked_up_at, d.pickup_confirmed_at,
                    COALESCE(NULLIF(d.estimated_duration_minutes, 0), n.estimated_duration_minutes, 0) AS reward_estimated_minutes
             FROM deliveries d
             LEFT JOIN donations n ON n.donation_id = d.donation_id
             WHERE d.delivery_id = {$deliveryId}{$ownerCondition} LIMIT 1"
        );
        $delivery = $result ? $result->fetch_assoc() : null;

        if (!$delivery) {
            return false;
        }

        $this->db->begin_transaction();
        try {
            $updated = $this->db->query("UPDATE deliveries SET status = 'delivered', delivered_at = NOW() WHERE delivery_id = {$deliveryId}{$ownerCondition}");
            if (!$updated || $this->db->affected_rows !== 1) {
                throw new RuntimeException('配送任務狀態已變更，無法完成行程。');
            }
            $deliveredAt = $this->getDeliveredAt($deliveryId);
            if ($deliveredAt === null) {
                throw new RuntimeException('無法取得配送完成時間。');
            }
            $volunteerId = (int) $delivery['volunteer_id'];
            $points = $this->calculateCompletedDeliveryPoints($delivery, $deliveredAt);
            if (!$this->db->query("UPDATE deliveries SET points = {$points} WHERE delivery_id = {$deliveryId}")) {
                throw new RuntimeException('無法更新配送點數。');
            }
            if ($volunteerId > 0) {
                if (!$this->db->query("INSERT INTO point_transactions (user_id, delivery_id, points, transaction_type, description) VALUES ({$volunteerId}, {$deliveryId}, {$points}, 'earned', '完成惜食配送')")) {
                    throw new RuntimeException('無法記錄配送點數交易。');
                }
            }
            $this->db->commit();
            $this->notifyDonor($deliveryId, 'Go Rider 已送達', "配送任務 #{$deliveryId} 已送達，感謝您的愛心。", 'success');
            if ($volunteerId > 0) {
                $this->notifyUser($volunteerId, '配送已完成', "配送任務 #{$deliveryId} 已確認收貨，獲得 {$points} 枚興毅幣。", 'success');
            }
            return true;
        } catch (Throwable $exception) {
            $this->db->rollback();
            return false;
        }
    }

    private function getDeliveredAt($deliveryId) {
        $result = $this->db->query("SELECT delivered_at FROM deliveries WHERE delivery_id = " . (int) $deliveryId . " LIMIT 1");
        $row = $result ? $result->fetch_assoc() : null;
        return $row['delivered_at'] ?? null;
    }

    private function calculateCompletedDeliveryPoints(array $delivery, $deliveredAt) {
        $pickedUpAt = $delivery['picked_up_at'] ?? $delivery['pickup_confirmed_at'] ?? null;
        $estimatedMinutes = (float) ($delivery['reward_estimated_minutes'] ?? $delivery['estimated_duration_minutes'] ?? 0);
        $actualMinutes = 0;
        $pickupTimestamp = $pickedUpAt ? strtotime($pickedUpAt) : false;
        $deliveryTimestamp = $deliveredAt ? strtotime($deliveredAt) : false;
        if ($pickupTimestamp !== false && $deliveryTimestamp !== false && $deliveryTimestamp > $pickupTimestamp) {
            $actualMinutes = (float) ceil(($deliveryTimestamp - $pickupTimestamp) / 60);
        }

        return $this->calculatePoints(
            (string) ($delivery['vehicle_type'] ?? 'motorcycle'),
            (float) ($delivery['total_distance_km'] ?? 0),
            (float) ($delivery['weight_kg'] ?? 0),
            (string) ($delivery['urgency'] ?? 'normal'),
            $estimatedMinutes,
            $actualMinutes
        );
    }

    private function notifyUser($userId, $title, $message, $type) {
        require_once __DIR__ . '/NotificationModel.php';
        return (new NotificationModel($this->db))->notify((int) $userId, $title, $message, $type);
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

    public static function calculatePoints(
        string $vehicle,
        float $distance,
        float $weight,
        string $urgency = 'normal',
        float $estimatedMinutes = 0,
        float $actualMinutes = 0
    ): int {
        $isCar = $vehicle === 'car';
        $distance = is_finite($distance) ? max(0, $distance) : 0;
        $weight = is_finite($weight) ? max(0, $weight) : 0;
        $estimatedMinutes = is_finite($estimatedMinutes) ? $estimatedMinutes : 0;
        $actualMinutes = is_finite($actualMinutes) ? $actualMinutes : 0;

        $base = $isCar ? 8.0 : 5.0;
        $distanceFee = $isCar
            ? max(0, min($distance, 5) - 2) * 2
                + max(0, min($distance, 10) - 5) * 3
                + max(0, $distance - 10) * 4
            : max(0, min($distance, 5) - 1) * 3
                + max(0, min($distance, 10) - 5) * 4
                + max(0, $distance - 10) * 5;
        $weightFee = $isCar
            ? max(0, min($weight, 20) - 5) * 0.5
                + max(0, min($weight, 50) - 20)
                + max(0, $weight - 50) * 1.5
            : max(0, min($weight, 10) - 3)
                + max(0, min($weight, 20) - 10) * 2;

        $urgencyMultiplier = [
            'normal' => 1.0,
            'priority' => 1.2,
            'urgent' => 1.5,
        ][$urgency] ?? 1.0;

        $earlyBonus = 0.0;
        if ($estimatedMinutes > 0 && $actualMinutes > 0) {
            $earlyRatio = ($estimatedMinutes - $actualMinutes) / $estimatedMinutes;
            if ($earlyRatio >= 0.10 && $earlyRatio < 0.20) {
                $earlyBonus = 0.10;
            } elseif ($earlyRatio >= 0.20 && $earlyRatio < 0.30) {
                $earlyBonus = 0.15;
            } elseif ($earlyRatio >= 0.30 && $earlyRatio < 0.60) {
                $earlyBonus = 0.20;
            }
        }

        return max(0, (int) floor(($base + $distanceFee + $weightFee) * $urgencyMultiplier * (1 + $earlyBonus)));
    }
}
