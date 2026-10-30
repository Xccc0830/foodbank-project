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
        $this->ensureArrivedPickupSupport();
    }

    private function ensureArrivedPickupSupport() {
        $status = $this->db->query("SHOW COLUMNS FROM deliveries LIKE 'status'");
        $row = $status ? $status->fetch_assoc() : null;
        if ($row && strpos($row['Type'], 'arrived_pickup') === false) {
            $this->db->query(
                "ALTER TABLE deliveries MODIFY status ENUM('open','claimed','arrived_pickup','picked_up','delivered','exception','cancelled') NOT NULL DEFAULT 'open'"
            );
        }
        $columns = [
            'arrived_pickup_at' => "ALTER TABLE deliveries ADD arrived_pickup_at DATETIME DEFAULT NULL",
            'received_confirmed_by' => "ALTER TABLE deliveries ADD received_confirmed_by INT DEFAULT NULL",
        ];
        foreach ($columns as $column => $alterSql) {
            $result = $this->db->query("SHOW COLUMNS FROM deliveries LIKE '{$column}'");
            if ($result && $result->num_rows === 0) {
                $this->db->query($alterSql);
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
        $sql = "SELECT d.*, n.order_number, n.donor_name, n.notes AS donation_notes, donor.phone AS donor_phone,
                       u.full_name AS volunteer_name, u.phone AS volunteer_phone
                FROM deliveries d
                LEFT JOIN donations n ON n.donation_id = d.donation_id
                LEFT JOIN users donor ON donor.user_id = n.donor_id
                LEFT JOIN users u ON u.user_id = d.volunteer_id
                ORDER BY d.created_at DESC";
        return $this->enrichTasksWithItemSummary($this->query($sql));
    }

    /**
     * GO Rider 取貨後依物資預估運送時間設定預估送達範圍（起點為預估時間，終點多留緩衝）。
     */
    private function setArrivalWindow($deliveryId) {
        $deliveryId = (int) $deliveryId;
        $result = $this->db->query(
            "SELECT n.estimated_duration_minutes FROM deliveries d
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
     * 找出已取貨但超過預估送達時間的配送任務，通知食物銀行人員並附上 GO Rider 聯絡方式。
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
            $contact = ($delivery['volunteer_name'] ?? 'GO Rider') . '，電話：' . ($delivery['volunteer_phone'] ?: '未提供');
            $this->notifyUsersByRoles(
                ['foodbank_staff'],
                '配送逾時提醒',
                "配送任務 #{$deliveryId}{$order} 已超過預估送達時間 {$window}，請聯繫 GO Rider：{$contact}。",
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
                          AND other_delivery.status IN ('claimed', 'arrived_pickup', 'picked_up')
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

    /**
     * 食物銀行確認收貨後，通知原提供商家「物資已成功送達」（同一張配送單只通知一次）。
     */
    private function notifyDonorDelivered($deliveryId) {
        $deliveryId = (int) $deliveryId;
        $result = $this->db->query(
            "SELECT n.donor_id, n.item_name FROM deliveries d
             INNER JOIN donations n ON n.donation_id = d.donation_id
             WHERE d.delivery_id = {$deliveryId} LIMIT 1"
        );
        $row = $result ? $result->fetch_assoc() : null;
        if (!$row || (int) $row['donor_id'] <= 0) {
            return false;
        }
        require_once __DIR__ . '/NotificationModel.php';
        $itemName = trim((string) ($row['item_name'] ?? ''));
        $label = $itemName !== '' ? "「{$itemName}」" : '';
        return (new NotificationModel($this->db))->notifyOnce(
            (int) $row['donor_id'],
            '物資已成功送達',
            "您捐贈的物資{$label}（配送任務 #{$deliveryId}）已由食物銀行確認收貨，感謝您的愛心。",
            'success',
            "delivered:{$deliveryId}",
            $deliveryId
        );
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
                error_log("配送任務發布後沒有可通知的 GO Rider：delivery_id={$deliveryId}");
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
                  AND d.status IN ('open', 'claimed', 'arrived_pickup', 'picked_up')
                ORDER BY n.published_at DESC, d.delivery_id ASC";
        return $this->enrichTasksWithItemSummary($this->query($sql));
    }

    public function completeDonationDeliveries($donationId) {
        $donationId = (int) $donationId;
        $result = $this->db->query("SELECT delivery_id, volunteer_id, points FROM deliveries WHERE donation_id = {$donationId} AND status IN ('claimed', 'arrived_pickup', 'picked_up')");
        if (!$result || $result->num_rows === 0) {
            return false;
        }

        $this->db->begin_transaction();
        try {
            while ($delivery = $result->fetch_assoc()) {
                $deliveryId = (int) $delivery['delivery_id'];
                $this->db->query("UPDATE deliveries SET status = 'delivered', delivered_at = NOW(), updated_at = NOW() WHERE delivery_id = {$deliveryId} AND status IN ('claimed', 'arrived_pickup', 'picked_up')");
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
                                    AND d.status IN ('open', 'claimed', 'arrived_pickup')
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
        $status = in_array(($data['status'] ?? 'open'), ['open', 'claimed', 'arrived_pickup', 'picked_up', 'exception', 'cancelled'], true)
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
                "配送任務 #{$deliveryId} 已由 GO Rider 接單。",
                'info'
            );
            if ($notifiedCount === 0) {
                error_log("GO Rider 接單通知沒有官方收件人：delivery_id={$deliveryId}");
            }
            $this->notifyDonor($deliveryId, 'GO Rider 已接單', "配送任務 #{$deliveryId} 已由 GO Rider 接單，正在前往取貨。", 'info');
        }
        return $updated && $this->db->affected_rows === 1;
    }

    public function cancelClaimedDelivery($deliveryId, $volunteerId) {
        $deliveryId = (int) $deliveryId;
        $volunteerId = (int) $volunteerId;
        $updated = $this->db->query("UPDATE deliveries SET volunteer_id = NULL, status = 'open', updated_at = NOW() WHERE delivery_id = {$deliveryId} AND delivery_method = 'volunteer' AND status = 'claimed' AND volunteer_id = {$volunteerId}");
        return $updated && $this->db->affected_rows === 1;
    }

    /**
     * 騎手按「已到店取貨」：已接單 → 已到店。之後必須上傳取貨照片才能完成取件。
     */
    public function markArrivedAtPickup($deliveryId, $volunteerId) {
        $deliveryId = (int) $deliveryId;
        $volunteerId = (int) $volunteerId;
        $this->db->query("UPDATE deliveries SET status = 'arrived_pickup', arrived_pickup_at = NOW(), updated_at = NOW() WHERE delivery_id = {$deliveryId} AND volunteer_id = {$volunteerId} AND delivery_method = 'volunteer' AND status = 'claimed'");
        if ($this->db->affected_rows !== 1) {
            return false;
        }
        $this->notifyDonor($deliveryId, 'GO Rider 已到店', "配送任務 #{$deliveryId} 的 GO Rider 已抵達店家，準備取貨。", 'info');
        return true;
    }

    public function confirmPickup($deliveryId, $volunteerId, $pickupPhotoPath) {
        $deliveryId = (int) $deliveryId;
        $volunteerId = (int) $volunteerId;
        $pickupPhotoPath = trim((string) $pickupPhotoPath);

        if ($pickupPhotoPath === '') {
            return false;
        }

        $photoValue = $this->db->real_escape_string($pickupPhotoPath);
        $updated = $this->db->query("UPDATE deliveries SET status = 'picked_up', pickup_confirmed_at = NOW(), pickup_photo_path = '{$photoValue}' WHERE delivery_id = {$deliveryId} AND volunteer_id = {$volunteerId} AND status = 'arrived_pickup'");
        $wasUpdated = $updated && $this->db->affected_rows === 1;
        if ($wasUpdated) {
            $this->setArrivalWindow($deliveryId);
            $this->notifyDonor($deliveryId, 'GO Rider 已取貨', "配送任務 #{$deliveryId} 物資已取貨，GO Rider 正在前往送達地點。", 'info');
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

        $updated = $this->db->query("UPDATE deliveries SET status = 'exception', exception_notes = '{$notes}' WHERE delivery_id = {$deliveryId} AND volunteer_id = {$volunteerId} AND status IN ('claimed', 'arrived_pickup', 'picked_up')");
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

    public function completeDelivery($deliveryId, $confirmedBy = null) {
        return $this->finalizeDeliveryCompletion($deliveryId, $confirmedBy);
    }

    private function finalizeDeliveryCompletion($deliveryId, $confirmedBy = null) {
        $deliveryId = (int) $deliveryId;
        $ownerCondition = " AND status IN ('claimed', 'arrived_pickup', 'picked_up', 'in_transit')";

        $result = $this->db->query("SELECT volunteer_id, points FROM deliveries WHERE delivery_id = {$deliveryId}{$ownerCondition} LIMIT 1");
        $delivery = $result ? $result->fetch_assoc() : null;

        if (!$delivery) {
            return false;
        }

        $this->db->begin_transaction();
        try {
            $confirmedSql = $confirmedBy ? (int) $confirmedBy : 'NULL';
            $this->db->query("UPDATE deliveries SET status = 'delivered', delivered_at = NOW(), received_confirmed_by = {$confirmedSql} WHERE delivery_id = {$deliveryId}{$ownerCondition}");
            if ($this->db->affected_rows !== 1) {
                throw new RuntimeException('配送任務狀態已變更，無法完成行程。');
            }
            $volunteerId = (int) $delivery['volunteer_id'];
            $points = (int) $delivery['points'];
            if ($volunteerId > 0) {
                $this->db->query("INSERT INTO point_transactions (user_id, delivery_id, points, transaction_type, description) VALUES ({$volunteerId}, {$deliveryId}, {$points}, 'earned', '完成惜食配送')");
            }
            $this->db->commit();
            $this->notifyDonorDelivered($deliveryId);
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

    public static function calculatePoints($vehicle, $distance, $weight, $urgency) {
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
