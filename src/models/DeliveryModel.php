<?php
/**
 * 配送任務與心意幣模型
 */

require_once __DIR__ . '/BaseModel.php';
require_once __DIR__ . '/../helpers/RiderLocationHelper.php';

class DeliveryModel extends BaseModel {
    protected $table = 'deliveries';

    public function __construct() {
        parent::__construct();
        if (schemaChecksNeeded()) {
            $this->ensureDeliveryMethodColumn();
            $this->ensureExceptionResponseColumns();
            $this->ensurePickupPhotoColumn();
            $this->ensureArrivalWindowColumns();
            $this->ensureArrivedPickupSupport();
            $this->ensureFoodbankVehicleColumn();
        }
    }

    /** 忠信派車可選的車種（GO Rider 派車仍用 vehicle_type 的機車／汽車） */
    public const FOODBANK_VEHICLES = [
        'motorcycle' => '機車',
        'car' => '汽車',
        'small_truck' => '小貨車',
        'refrigerated_truck' => '冷藏車',
        'frozen_truck' => '冷凍車',
    ];

    private function ensureFoodbankVehicleColumn() {
        $result = $this->db->query("SHOW COLUMNS FROM deliveries LIKE 'foodbank_vehicle'");
        if ($result && $result->num_rows === 0) {
            $this->db->query("ALTER TABLE deliveries ADD foodbank_vehicle VARCHAR(30) DEFAULT NULL COMMENT '忠信派車的車種' AFTER vehicle_type");
        }
    }

    /**
     * 食物銀行為忠信派車的配送單指定車種；只能改還沒送達的忠信派車單。
     * @param int|null $donationId 指定時改這張捐贈單底下所有忠信派車配送單
     */
    public function setFoodbankVehicle($deliveryId, $vehicle, $donationId = null) {
        if (!isset(self::FOODBANK_VEHICLES[$vehicle])) {
            return false;
        }
        $where = $donationId !== null ? 'donation_id = ' . (int) $donationId : 'delivery_id = ' . (int) $deliveryId;
        $stmt = $this->db->prepare("UPDATE deliveries SET foodbank_vehicle = ? WHERE {$where} AND delivery_method = 'food_bank' AND status NOT IN ('delivered', 'cancelled')");
        $stmt->bind_param('s', $vehicle);
        return $stmt->execute();
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
            'arrived_dropoff_at' => "ALTER TABLE deliveries ADD arrived_dropoff_at DATETIME DEFAULT NULL",
            'received_confirmed_by' => "ALTER TABLE deliveries ADD received_confirmed_by INT DEFAULT NULL",
            'multi_stop_bonus' => "ALTER TABLE deliveries ADD multi_stop_bonus INT NOT NULL DEFAULT 0",
            'is_cold_storage' => "ALTER TABLE deliveries ADD is_cold_storage TINYINT(1) NOT NULL DEFAULT 0",
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
        $sql = "SELECT d.*, n.order_number, n.donor_name, n.notes AS donation_notes, donor.phone AS donor_phone, donor.business_hours AS donor_business_hours, donor.open_days AS donor_open_days, donor.closed_days AS donor_closed_days, donor.pickup_note AS donor_pickup_note,
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
                    CASE WHEN d.delivery_method = 'food_bank' THEN '忠信食物銀行' ELSE u.full_name END AS courier_name,
                    d.delivery_method,
                    d.vehicle_type, d.foodbank_vehicle, d.estimated_arrival_start, d.estimated_arrival_end,
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
        if ($tracking) {
            $tracking['foodbank_vehicle_label'] = self::FOODBANK_VEHICLES[$tracking['foodbank_vehicle'] ?? ''] ?? '';
        }
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
        try {
            $data['points'] = $deliveryMethod === 'volunteer'
                ? $this->calculatePoints(
                    $data['vehicle_type'] ?? 'motorcycle',
                    (float) ($data['total_distance_km'] ?? 0),
                    (float) ($data['weight_kg'] ?? 0),
                    $data['urgency'] ?? 'normal'
                )
                : 0;
        } catch (InvalidArgumentException $exception) {
            return false;
        }
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
                           SUM(CASE WHEN volunteer_id IS NOT NULL OR delivery_method = 'food_bank' THEN 1 ELSE 0 END) AS accepted_tasks,
                           SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) AS completed_tasks,
                           SUM(CASE WHEN " . self::COMPLETABLE_CONDITION . " THEN 1 ELSE 0 END) AS completable_tasks,
                           MAX(delivery_method = 'food_bank') AS is_food_bank
                                    FROM deliveries
                                    WHERE donation_id = {$donationId}
                                      AND delivery_method IN ('volunteer', 'food_bank')");
        $progress = $result ? $result->fetch_assoc() : null;
        return [
            'total_tasks' => (int) ($progress['total_tasks'] ?? 0),
            'accepted_tasks' => (int) ($progress['accepted_tasks'] ?? 0),
            'completed_tasks' => (int) ($progress['completed_tasks'] ?? 0),
            'completable_tasks' => (int) ($progress['completable_tasks'] ?? 0), // 可確認收貨（已取貨）的單數
            'is_food_bank' => !empty($progress['is_food_bank']),
        ];
    }

    public function getDonorTransportTasks($donorId) {
        $donorId = (int) $donorId;
        $sql = "SELECT d.*, n.donor_name, n.donor_address, n.donation_type, n.item_name,
                       n.quantity, n.unit, n.weight_kg AS published_weight_kg,
                       n.size_description, n.photo_path, n.pickup_deadline,
                       n.estimated_distance_km, n.estimated_duration_minutes,
                       n.delivery_date, n.delivery_time,
                       u.full_name AS volunteer_name, u.phone AS volunteer_phone
                FROM deliveries d
                INNER JOIN donations n ON n.donation_id = d.donation_id
                LEFT JOIN users u ON u.user_id = d.volunteer_id
                WHERE n.donor_id = {$donorId}
                  AND n.status = 'published'
                  AND d.delivery_method IN ('volunteer', 'food_bank')
                  AND d.status IN ('open', 'claimed', 'arrived_pickup', 'picked_up')
                ORDER BY n.published_at DESC, d.delivery_id ASC";
        return $this->enrichTasksWithItemSummary($this->query($sql));
    }

    /**
     * 食物銀行確認這張物資的配送單都已收到貨：逐張走正式的完成流程
     * （入帳心意幣與加給、通知商家到貨）。忠信派車的單也一併完成。
     */
    public function completeDonationDeliveries($donationId, $confirmedBy = null) {
        $donationId = (int) $donationId;
        $result = $this->db->query(
            "SELECT delivery_id FROM deliveries WHERE donation_id = {$donationId} AND " . self::COMPLETABLE_CONDITION
        );
        if (!$result || $result->num_rows === 0) {
            return false;
        }
        $completedAny = false;
        while ($delivery = $result->fetch_assoc()) {
            $completedAny = $this->finalizeDeliveryCompletion((int) $delivery['delivery_id'], $confirmedBy) || $completedAny;
        }
        return $completedAny;
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
                        SUM(di.item_weight_kg) AS total_item_weight_kg,
                        MAX(FIELD(di.storage_condition, 'room', 'chilled', 'frozen')) AS storage_level
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
                        SUM(item_weight_kg) AS total_item_weight_kg,
                        MAX(FIELD(storage_condition, 'room', 'chilled', 'frozen')) AS storage_level
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
            $row['storage_level'] = (int) ($summary['storage_level'] ?? 1);
        }
        unset($row);

        return $rows;
    }

    /**
     * 可以「確認收貨」的配送單：
     * GO Rider 的單必須已取貨（拍照）才能確認；忠信派車的單沒有騎手取貨步驟，進行中即可確認。
     */
    const COMPLETABLE_CONDITION = "((delivery_method = 'food_bank' AND status IN ('open', 'claimed', 'arrived_pickup', 'picked_up'))
        OR (COALESCE(delivery_method, 'volunteer') <> 'food_bank' AND status = 'picked_up'))";

    public function canManageDelivery($deliveryId, $userId, $userRole) {
        if ($userRole !== 'foodbank_staff') {
            return false;
        }

        $deliveryId = (int) $deliveryId;
        $userId = (int) $userId;
        $result = $this->db->query("SELECT created_by, status FROM deliveries WHERE delivery_id = {$deliveryId} LIMIT 1");
        if (!$result || $result->num_rows === 0) {
            return false;
        }

        $delivery = $result->fetch_assoc();
        // 已配達的單心意幣已入帳，不能再編輯或刪除（避免狀態被改回待接單而重複發幣）
        if ($delivery['status'] === 'delivered') {
            return false;
        }
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
        $vehicleType = $data['vehicle_type'] ?? 'motorcycle';
        $distance = (float) ($data['total_distance_km'] ?? 0);
        $weight = (float) ($data['weight_kg'] ?? 0);
        $urgency = $data['urgency'] ?? 'normal';
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
        if ($distance < 0 || !is_finite($distance)) {
            return false;
        }
        try {
            $points = $deliveryMethod === 'volunteer'
                ? $this->calculatePoints($vehicleType, $distance, $weight, $urgency)
                : 0;
        } catch (InvalidArgumentException $exception) {
            return false;
        }
        if ($pickupAddress === '' || $deliveryAddress === '') {
            return false;
        }

        $sql = "UPDATE deliveries SET donation_id = {$donationValue}, delivery_method = '{$deliveryMethod}', vehicle_type = '{$vehicleType}', total_distance_km = {$distance}, weight_kg = {$weight}, urgency = '{$urgency}', status = '{$status}', points = {$points}, pickup_address = '{$pickupAddress}', delivery_address = '{$deliveryAddress}', updated_at = NOW() WHERE delivery_id = {$deliveryId} LIMIT 1";
        Transaction::begin($this->db);
        $updated = $this->db->query($sql);
        if (!$updated) {
            Transaction::rollback($this->db);
            return false;
        }
        return Transaction::commit($this->db);
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

    /**
     * GO Rider 回報已送達食物銀行：記下實際送達時間（用來判斷「準時送達 +1」），
     * 狀態仍是已取貨，等食物銀行確認收貨後心意幣才入帳。
     */
    public function markArrivedAtDropoff($deliveryId, $volunteerId) {
        $deliveryId = (int) $deliveryId;
        $volunteerId = (int) $volunteerId;
        $this->db->query("UPDATE deliveries SET arrived_dropoff_at = NOW(), updated_at = NOW() WHERE delivery_id = {$deliveryId} AND volunteer_id = {$volunteerId} AND delivery_method = 'volunteer' AND status = 'picked_up' AND arrived_dropoff_at IS NULL");
        if ($this->db->affected_rows !== 1) {
            return false;
        }
        $this->notifyUsersByRoles(['foodbank_staff'], 'GO Rider 已送達', "配送任務 #{$deliveryId} 的 GO Rider 已回報送達食物銀行，請清點物資並確認收貨。", 'info');
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

    public function completeDelivery($deliveryId, $confirmedBy = null) {
        return $this->finalizeDeliveryCompletion($deliveryId, $confirmedBy);
    }

    private function finalizeDeliveryCompletion($deliveryId, $confirmedBy = null) {
        $deliveryId = (int) $deliveryId;
        $ownerCondition = ' AND ' . self::COMPLETABLE_CONDITION;

        $result = $this->db->query("SELECT volunteer_id, points, delivery_method FROM deliveries WHERE delivery_id = {$deliveryId}{$ownerCondition} LIMIT 1");
        $delivery = $result ? $result->fetch_assoc() : null;

        if (!$delivery) {
            return false;
        }

        if (!Transaction::begin($this->db)) {
            error_log('無法開始配送完成交易');
            return false;
        }
        try {
            $volunteerId = (int) $delivery['volunteer_id'];
            if ($volunteerId > 0) {
                $userResult = $this->db->query("SELECT user_id FROM users WHERE user_id = {$volunteerId} FOR UPDATE");
                if (!$userResult || !$userResult->fetch_assoc()) {
                    throw new RuntimeException('無法鎖定配送員帳號。');
                }
            }
            $lockedResult = $this->db->query(
                "SELECT volunteer_id, points, delivery_method, delivery_address, arrived_dropoff_at,
                        estimated_arrival_start, estimated_arrival_end, pickup_confirmed_at
                 FROM deliveries WHERE delivery_id = {$deliveryId}{$ownerCondition} FOR UPDATE"
            );
            $lockedDelivery = $lockedResult ? $lockedResult->fetch_assoc() : null;
            if (!$lockedResult || !$lockedDelivery || (int) $lockedDelivery['volunteer_id'] !== $volunteerId) {
                throw new RuntimeException('配送任務狀態或配送員已變更。');
            }
            $delivery = $lockedDelivery;
            $confirmedSql = $confirmedBy ? (int) $confirmedBy : 'NULL';
            $volunteerCondition = $delivery['volunteer_id'] === null ? 'volunteer_id IS NULL' : "volunteer_id = {$volunteerId}";
            $updated = $this->db->query("UPDATE deliveries SET status = 'delivered', delivered_at = NOW(), received_confirmed_by = {$confirmedSql} WHERE delivery_id = {$deliveryId}{$ownerCondition} AND {$volunteerCondition}");
            if (!$updated || $this->db->affected_rows !== 1) {
                throw new RuntimeException('配送任務狀態已變更，無法完成行程。');
            }
            $points = (int) $delivery['points'];
            if ($delivery['delivery_method'] === 'volunteer' && $volunteerId > 0) {
                $description = $this->db->real_escape_string('完成惜食配送（依減碳貢獻公式計算）');
                if (!$this->db->query("INSERT INTO point_transactions (user_id, delivery_id, points, transaction_type, description) VALUES ({$volunteerId}, {$deliveryId}, {$points}, 'earned', '{$description}')")) {
                    throw new RuntimeException('無法記錄配送點數。');
                }
                $this->auditDeliveryAnomalies($deliveryId, $delivery, $confirmedBy);
            }
            if (!Transaction::commit($this->db)) {
                throw new RuntimeException('無法儲存配送完成交易。');
            }
        } catch (Throwable $exception) {
            Transaction::rollback($this->db);
            error_log('配送完成失敗: ' . $exception->getMessage());
            return false;
        }
        $this->notifyDonorDelivered($deliveryId);
        if ($volunteerId > 0) {
            if ($delivery['delivery_method'] === 'volunteer') {
                $this->notifyUser($volunteerId, '配送已完成', "配送任務 #{$deliveryId} 已確認收貨，獲得 {$points} 枚心意幣。", 'success');
            }
        }
        return true;
    }

    private function auditDeliveryAnomalies($deliveryId, array $delivery, $confirmedBy) {
        $reasons = [];
        $arrivalTime = !empty($delivery['arrived_dropoff_at']) ? strtotime($delivery['arrived_dropoff_at']) : false;
        if ($arrivalTime === false) {
            $reasons[] = '未回報抵達時間';
        } else {
            $pickupTime = !empty($delivery['pickup_confirmed_at']) ? strtotime($delivery['pickup_confirmed_at']) : false;
            if ($pickupTime !== false && $arrivalTime < $pickupTime) {
                $reasons[] = '抵達時間早於取貨時間';
            }
            $arrivalStart = !empty($delivery['estimated_arrival_start']) ? strtotime($delivery['estimated_arrival_start']) : false;
            $arrivalEnd = !empty($delivery['estimated_arrival_end']) ? strtotime($delivery['estimated_arrival_end']) : false;
            if ($arrivalStart !== false && $arrivalEnd !== false && ($arrivalTime < $arrivalStart || $arrivalTime > $arrivalEnd)) {
                $reasons[] = '抵達時間超出預估時段';
            }
        }

        $locationTable = $this->db->query("SHOW TABLES LIKE 'rider_locations'");
        $locationResult = $locationTable && $locationTable->num_rows > 0
            ? $this->db->query(
                "SELECT lat, lon, updated_at FROM rider_locations
                 WHERE volunteer_id = " . (int) $delivery['volunteer_id'] . "
                   AND updated_at >= DATE_SUB(NOW(), INTERVAL " . RiderLocationHelper::FRESH_SECONDS . " SECOND)
                 LIMIT 1"
            )
            : false;
        $location = $locationResult ? $locationResult->fetch_assoc() : null;
        if (!$location) {
            $reasons[] = '完成配送時沒有有效的近期 GPS 位置';
        } else {
            $addressHash = md5(trim((string) $delivery['delivery_address']));
            $cacheTable = $this->db->query("SHOW TABLES LIKE 'geocode_cache'");
            $destinationResult = $cacheTable && $cacheTable->num_rows > 0
                ? $this->db->query(
                    "SELECT lat, lon FROM geocode_cache WHERE address_hash = '" . $this->db->real_escape_string($addressHash) . "' LIMIT 1"
                )
                : false;
            $destination = $destinationResult ? $destinationResult->fetch_assoc() : null;
            if ($destination) {
                $distance = straightLineKm(
                    ['lat' => (float) $location['lat'], 'lon' => (float) $location['lon']],
                    ['lat' => (float) $destination['lat'], 'lon' => (float) $destination['lon']]
                );
                if ($distance > 1.0) {
                    $reasons[] = '完成配送時 GPS 距離送達地點超過 1 公里';
                }
            }
        }

        if (!$reasons) {
            return;
        }
        $details = json_encode([
            'reasons' => $reasons,
            'arrived_dropoff_at' => $delivery['arrived_dropoff_at'],
            'estimated_arrival_start' => $delivery['estimated_arrival_start'],
            'estimated_arrival_end' => $delivery['estimated_arrival_end'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $actorId = $confirmedBy ? (int) $confirmedBy : null;
        $stmt = $this->db->prepare(
            "INSERT INTO audit_logs (user_id, action_type, table_name, record_id, new_values)
             VALUES (?, 'policy_delivery_anomaly', 'deliveries', ?, ?)"
        );
        if (!$stmt) {
            throw new RuntimeException('無法準備配送異常稽核紀錄: ' . $this->db->error);
        }
        $stmt->bind_param('iis', $actorId, $deliveryId, $details);
        if (!$stmt->execute()) {
            throw new RuntimeException('無法儲存配送異常稽核紀錄: ' . $stmt->error);
        }
        $this->notifyUsersByRoles(
            ['foodbank_staff'],
            '配送異常待稽核',
            '配送任務 #' . (int) $deliveryId . ' 偵測到時間或 GPS 異常，已記錄供食物銀行人員複核。',
            'warning'
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

    public static function maximumWeightForVehicle($vehicle) {
        return ['motorcycle' => 20.0, 'car' => 50.0][$vehicle] ?? null;
    }

    /**
     * 心意幣 = floor(min(60, [B + (W × 0.34 + D × E) × 3] × U)).
     */
    public static function calculatePoints($vehicle, $distance, $weight, $urgency, $coldStorage = false) {
        $maximumWeight = self::maximumWeightForVehicle($vehicle);
        $urgencyMultipliers = ['normal' => 1.0, 'priority' => 1.2, 'urgent' => 1.5];
        if ($maximumWeight === null
            || !is_numeric($distance) || !is_finite((float) $distance) || (float) $distance < 0
            || !is_numeric($weight) || !is_finite((float) $weight)
            || (float) $weight < 0 || (float) $weight > $maximumWeight
            || !isset($urgencyMultipliers[$urgency])) {
            throw new InvalidArgumentException('配送車種、距離、重量或緊急度不符合心意幣計算規範。');
        }

        $basePoints = $vehicle === 'car' ? 7 : 5;
        $emissionFactor = $vehicle === 'car' ? 0.115 : 0.095;
        $points = ($basePoints + ((float) $weight * 0.34 + (float) $distance * $emissionFactor) * 3)
            * $urgencyMultipliers[$urgency];
        return min(60, (int) floor($points + 1e-9));
    }
}
