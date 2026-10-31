<?php
/**
 * 捐贈模型 (Donation Model)
 * 用於管理食物銀行的捐贈記錄
 */

require_once 'BaseModel.php';
require_once __DIR__ . '/DeliveryModel.php';

class DonationModel extends BaseModel {
    protected $table = 'donations';

    public function __construct() {
        parent::__construct();
        $this->ensureOrderNumberColumn();
        $this->ensureEstimatedDistanceColumns();
    }

    private function ensureOrderNumberColumn() {
        $result = $this->db->query("SHOW COLUMNS FROM donations LIKE 'order_number'");
        if ($result && $result->num_rows === 0) {
            $this->db->query("ALTER TABLE donations ADD order_number VARCHAR(30) NULL AFTER donation_id");
        }

        $this->db->query(
            "UPDATE donations
             SET order_number = CONCAT('FB-', DATE_FORMAT(donation_date, '%Y%m%d'), '-', LPAD(donation_id, 6, '0'))
             WHERE order_number IS NULL OR order_number = ''"
        );
        $indexResult = $this->db->query("SHOW INDEX FROM donations WHERE Key_name = 'unique_order_number'");
        if ($indexResult && $indexResult->num_rows === 0) {
            $this->db->query("ALTER TABLE donations ADD UNIQUE KEY unique_order_number (order_number)");
        }
    }

    private function ensureEstimatedDistanceColumns() {
        $distanceColumn = $this->db->query("SHOW COLUMNS FROM donations LIKE 'estimated_distance_km'");
        if ($distanceColumn && $distanceColumn->num_rows === 0) {
            $this->db->query("ALTER TABLE donations ADD estimated_distance_km DECIMAL(8,2) NULL AFTER delivery_address");
        }

        $durationColumn = $this->db->query("SHOW COLUMNS FROM donations LIKE 'estimated_duration_minutes'");
        if ($durationColumn && $durationColumn->num_rows === 0) {
            $this->db->query("ALTER TABLE donations ADD estimated_duration_minutes SMALLINT UNSIGNED NULL AFTER estimated_distance_km");
        }
    }

    private function generateOrderNumber() {
        return 'FB-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
    }

    /**
     * 取得所有捐贈記錄
     */
    public function getAllDonations($status = null, $donorId = null) {
        $sql = "SELECT * FROM {$this->table}";
        $conditions = [];
        
        if ($status) {
            $status = $this->db->real_escape_string($status);
            $conditions[] = "status = '{$status}'";
        }

        if ($donorId !== null) {
            $conditions[] = 'donor_id = ' . (int) $donorId;
        }

        if ($conditions) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }
        
        $sql .= " ORDER BY donation_date DESC";
        $donations = $this->query($sql);
        $uniqueDonations = [];

        foreach ($donations as $donation) {
            $donationId = (int) ($donation['donation_id'] ?? 0);
            if (!isset($uniqueDonations[$donationId])) {
                $uniqueDonations[$donationId] = $donation;
            }
        }

        return array_values($uniqueDonations);
    }

    /**
     * 新增捐贈記錄
     */
    public function addDonation($donor_data) {
        $donor_data['donation_date'] = date('Y-m-d H:i:s');
        $donor_data['order_number'] = $this->generateOrderNumber();
        return $this->insert($donor_data);
    }

    private function hasDonationItemsTable() {
        $result = $this->db->query("SHOW TABLES LIKE 'donation_items'");
        return $result && $result->num_rows > 0;
    }

    /**
     * 審查商家物資捐贈，完成後移至已評估區塊
     */
    public function reviewMaterialDonation($donation_id, $decision, $rejection_reason = '', $foodbankDeliveryOption = '') {
        $donation_id = (int) $donation_id;
        if (!in_array($decision, ['accepted', 'rejected'], true)) {
            return false;
        }

        $evaluationStatus = $decision === 'accepted' ? 'approved_volunteer' : 'rejected';
        $evaluationStatus = $this->db->real_escape_string($evaluationStatus);
        $rejectionReason = $this->db->real_escape_string($rejection_reason);
        $allowedDeliveryOptions = ['food_bank_pickup', 'volunteer_delivery'];
        if ($decision === 'accepted' && !in_array($foodbankDeliveryOption, $allowedDeliveryOptions, true)) {
            return false;
        }
        if ($foodbankDeliveryOption !== '' && !in_array($foodbankDeliveryOption, $allowedDeliveryOptions, true)) {
            return false;
        }

        if ($foodbankDeliveryOption === '') {
            $existingResult = $this->db->query("SELECT delivery_option, delivery_method FROM {$this->table} WHERE donation_id = {$donation_id} AND status = 'pending' LIMIT 1");
            $existingDonation = $existingResult ? $existingResult->fetch_assoc() : null;
            $foodbankDeliveryOption = $existingDonation['delivery_option'] ?? 'volunteer_delivery';
            $deliveryMethod = $foodbankDeliveryOption === 'food_bank_pickup' ? 'self_delivery' : 'volunteer_assist';
        } else {
            $foodbankDeliveryOption = $this->db->real_escape_string($foodbankDeliveryOption);
            $deliveryMethod = $foodbankDeliveryOption === 'food_bank_pickup' ? 'self_delivery' : 'volunteer_assist';
        }

        $foodbankDeliveryOption = $this->db->real_escape_string($foodbankDeliveryOption);
        $deliveryMethod = $this->db->real_escape_string($deliveryMethod);

        $sql = "UPDATE {$this->table}
                SET status = 'assessed',
                    evaluation_status = '{$evaluationStatus}',
                    evaluation_notes = NULL,
                    rejection_reason = " . ($decision === 'accepted' ? 'NULL' : "'{$rejectionReason}'") . ",
                    delivery_option = '{$foodbankDeliveryOption}',
                    delivery_method = '{$deliveryMethod}',
                    approved_at = " . ($decision === 'accepted' ? 'NOW()' : 'NULL') . ",
                    rejected_at = " . ($decision === 'accepted' ? 'NULL' : 'NOW()') . ",
                    updated_at = NOW()
                WHERE donation_id = {$donation_id}
                  AND status = 'pending'";
        if (!$this->db->query($sql)) {
            return false;
        }

        return true;
    }

    /**
     * 取得單筆捐贈記錄
     */
    public function getDonationById($donation_id) {
        $donation_id = intval($donation_id);
        $sql = "SELECT * FROM {$this->table} WHERE donation_id = {$donation_id} LIMIT 1";
        $result = $this->db->query($sql);
        $donation = $result ? $result->fetch_assoc() : null;
        if (!$donation || !$this->hasDonationItemsTable()) {
            return $donation;
        }

        $summaryResult = $this->db->query(
            "SELECT COALESCE(SUM(item_weight_kg), 0) AS total_item_weight
             FROM donation_items WHERE donation_id = {$donation_id}"
        );
        $summary = $summaryResult ? $summaryResult->fetch_assoc() : null;
        if ((float) ($donation['weight_kg'] ?? 0) <= 0 && (float) ($summary['total_item_weight'] ?? 0) > 0) {
            $donation['weight_kg'] = $summary['total_item_weight'];
        }

        $firstItemResult = $this->db->query(
            "SELECT quantity, unit, LEFT(specification, 100) AS specification, length_cm, width_cm, height_cm
             FROM donation_items WHERE donation_id = {$donation_id}
             ORDER BY item_id ASC LIMIT 1"
        );
        $firstItem = $firstItemResult ? $firstItemResult->fetch_assoc() : null;
        if ($firstItem) {
            if ((float) ($donation['quantity'] ?? 0) <= 0) {
                $donation['quantity'] = $firstItem['quantity'];
                $donation['unit'] = $firstItem['unit'];
            }

            if (trim((string) ($donation['size_description'] ?? '')) === '') {
                $sizeDescription = trim((string) ($firstItem['specification'] ?? ''));
                if ($sizeDescription === '' && $firstItem['length_cm'] !== null
                    && $firstItem['width_cm'] !== null && $firstItem['height_cm'] !== null) {
                    $sizeDescription = sprintf(
                        '長寬高：%s × %s × %s cm',
                        number_format((float) $firstItem['length_cm'], 2),
                        number_format((float) $firstItem['width_cm'], 2),
                        number_format((float) $firstItem['height_cm'], 2)
                    );
                }
                if ($sizeDescription !== '') {
                    $donation['size_description'] = $sizeDescription;
                }
            }
        }

        return $donation;
    }

    public function publishDonation($donation_id, $publishData = []) {
        $donation_id = (int) $donation_id;
        if (!is_array($publishData)) {
            $publishData = ['split_count' => $publishData];
        }
        $splitCount = max(1, (int) ($publishData['split_count'] ?? 1));
        $needInspection = !empty($publishData['need_inspection']) ? 1 : 0;
        $rewardOptions = $publishData['reward_options'] ?? [];
        $rewardOptions = is_array($rewardOptions) ? array_values(array_intersect(
            ['points', 'goods', 'free', 'service_hours'],
            $rewardOptions
        )) : [];

        $updates = [
            "status = 'published'",
            'published_at = NOW()',
            "split_count = {$splitCount}",
            "need_inspection = {$needInspection}",
            "reward_options = '" . $this->db->real_escape_string(json_encode($rewardOptions, JSON_UNESCAPED_UNICODE)) . "'",
        ];
        foreach (['donor_name', 'donor_address', 'item_name', 'quantity', 'weight_kg', 'size_description', 'photo_path', 'pickup_deadline'] as $field) {
            if (array_key_exists($field, $publishData)) {
                $value = $publishData[$field];
                $updates[] = $value === null || $value === ''
                    ? "{$field} = NULL"
                    : "{$field} = '" . $this->db->real_escape_string((string) $value) . "'";
            }
        }
        if (array_key_exists('inspection_notes', $publishData)) {
            $inspectionNotes = $publishData['inspection_notes'];
            $updates[] = $inspectionNotes === '' ? 'inspection_notes = NULL' : "inspection_notes = '" . $this->db->real_escape_string($inspectionNotes) . "'";
        }

        $existingResult = $this->db->query("SELECT * FROM {$this->table} WHERE donation_id = {$donation_id} AND status = 'assessed' LIMIT 1");
        $existingDonation = $existingResult ? $existingResult->fetch_assoc() : null;
        if (!$existingDonation) {
            return false;
        }

        $deliveryOption = $existingDonation['delivery_option'] ?? '';
        if (!in_array($deliveryOption, ['food_bank_pickup', 'volunteer_delivery'], true)) {
            return false;
        }

        $this->db->begin_transaction();
        if (!$this->db->query("UPDATE {$this->table} SET " . implode(', ', $updates) . " WHERE donation_id = {$donation_id} AND status = 'assessed'")) {
            $this->db->rollback();
            return false;
        }

        $deliveryMethod = $deliveryOption === 'food_bank_pickup' ? 'food_bank' : 'volunteer';
        $vehicleType = in_array(($existingDonation['vehicle_type'] ?? ''), ['car', 'motorcycle'], true)
            ? $existingDonation['vehicle_type']
            : 'motorcycle';
        $weight = ((float) ($publishData['weight_kg'] ?? $existingDonation['weight_kg'] ?? 0)) / $splitCount;
        $distance = max(0, (float) ($existingDonation['estimated_distance_km'] ?? 0));
        $points = $deliveryMethod === 'volunteer'
            ? max(0, DeliveryModel::calculatePoints($vehicleType, $distance, $weight, 'normal'))
            : 0;
        $pickupAddress = trim((string) ($publishData['donor_address'] ?? $existingDonation['donor_address'] ?? ''));
        if ($pickupAddress === '') {
            $pickupAddress = (string) ($publishData['donor_name'] ?? $existingDonation['donor_name'] ?? '商家取貨地點');
        }
        $itemCategory = $this->db->real_escape_string((string) ($existingDonation['donation_type'] ?? 'other'));
        $itemDescription = $this->db->real_escape_string((string) ($publishData['item_name'] ?? $existingDonation['item_name'] ?? '物資'));
        $pickupAddress = $this->db->real_escape_string($pickupAddress);

        // 拆單系統：split_count > 1 時，除了原有的配送任務，額外建立正式的主單/子單關聯
        // （donation_allocations 子單 + sub_order_number，並將對應物資項目依比例分配到各子單）。
        $orderNumber = (string) ($existingDonation['order_number'] ?? '');
        $donationItems = $this->hasDonationItemsTable()
            ? $this->query("SELECT item_id, quantity, unit FROM donation_items WHERE donation_id = {$donation_id}")
            : [];
        $totalQuantity = (float) ($publishData['quantity'] ?? $existingDonation['quantity'] ?? 0);
        $unit = $this->db->real_escape_string((string) ($existingDonation['unit'] ?? '件'));

        for ($taskNumber = 1; $taskNumber <= $splitCount; $taskNumber++) {
            $allocationId = null;
            if ($orderNumber !== '') {
                $subOrderNumber = $this->db->real_escape_string($orderNumber . '-' . $this->allocationSuffix($taskNumber));
                $allocationQuantity = $splitCount > 0 ? round($totalQuantity / $splitCount, 2) : $totalQuantity;
                if (!$this->db->query(
                    "INSERT INTO donation_allocations (donation_id, allocation_number, sub_order_number, weight_kg, quantity, unit, status)
                     VALUES ({$donation_id}, {$taskNumber}, '{$subOrderNumber}', {$weight}, {$allocationQuantity}, '{$unit}', 'pending')"
                )) {
                    $this->db->rollback();
                    return false;
                }
                $allocationId = (int) $this->db->insert_id;

                foreach ($donationItems as $donationItem) {
                    $itemQuantity = (float) ($donationItem['quantity'] ?? 0);
                    $portion = $splitCount > 0 ? round($itemQuantity / $splitCount, 2) : $itemQuantity;
                    if ($portion <= 0) {
                        continue;
                    }
                    $donationItemId = (int) $donationItem['item_id'];
                    if (!$this->db->query(
                        "INSERT INTO donation_allocation_items (allocation_id, donation_item_id, quantity) VALUES ({$allocationId}, {$donationItemId}, {$portion})"
                    )) {
                        $this->db->rollback();
                        return false;
                    }
                }
            }

            $allocationColumn = $allocationId !== null ? ", allocation_id" : '';
            $allocationValue = $allocationId !== null ? ", {$allocationId}" : '';
            $taskSql = "INSERT INTO deliveries
                (donation_id{$allocationColumn}, delivery_method, vehicle_type, total_distance_km, weight_kg, urgency, points, pickup_address, delivery_address, status, item_category, item_description)
                VALUES ({$donation_id}{$allocationValue}, '{$deliveryMethod}', '{$vehicleType}', {$distance}, {$weight}, 'normal', {$points}, '{$pickupAddress}', '忠信食物銀行', 'open', '{$itemCategory}', '{$itemDescription}')";
            if (!$this->db->query($taskSql)) {
                $this->db->rollback();
                return false;
            }
        }

        $this->db->commit();
        if ($deliveryMethod === 'volunteer') {
            require_once __DIR__ . '/NotificationModel.php';
            $notificationModel = new NotificationModel($this->db);
            $notifiedCount = $notificationModel->notifyMembers(
                '新的 Go Rider 配送任務',
                sprintf(
                    '物資「%s」已發布 %d 筆配送任務，請立即前往配送任務查看並接單。',
                    (string) ($publishData['item_name'] ?? $existingDonation['item_name'] ?? '新物資'),
                    $splitCount
                ),
                'info',
                'general'
            );
            if ($notifiedCount === 0) {
                error_log("配送任務發布後沒有通知到忠信 GO RIDER：donation_id={$donation_id}");
            }
        }
        return true;
    }

    /**
     * 產生子單後綴：1=>A, 2=>B, ..., 26=>Z, 27=>AA ...（Excel 欄位命名風格）。
     */
    private function allocationSuffix($number) {
        $number = (int) $number;
        $suffix = '';
        while ($number > 0) {
            $remainder = ($number - 1) % 26;
            $suffix = chr(65 + $remainder) . $suffix;
            $number = intdiv($number - 1, 26);
        }
        return $suffix === '' ? 'A' : $suffix;
    }

    /**
     * 取得單一主單（捐贈）的所有子單，含內容物與長寬高，供拆單管理與追蹤頁使用。
     */
    public function getDonationAllocations($donationId) {
        $donationId = (int) $donationId;
        $allocations = $this->query(
            "SELECT a.*,
                    (SELECT d.delivery_id FROM deliveries d WHERE d.allocation_id = a.allocation_id LIMIT 1) AS delivery_id,
                    (SELECT d.status FROM deliveries d WHERE d.allocation_id = a.allocation_id LIMIT 1) AS delivery_status,
                    (SELECT d.volunteer_id FROM deliveries d WHERE d.allocation_id = a.allocation_id LIMIT 1) AS volunteer_id
             FROM donation_allocations a
             WHERE a.donation_id = {$donationId}
             ORDER BY a.allocation_number ASC"
        );

        foreach ($allocations as &$allocation) {
            $allocationId = (int) $allocation['allocation_id'];
            $allocation['items'] = $this->query(
                "SELECT ai.quantity, di.item_id, di.item_name, di.brand, di.specification, di.unit,
                        di.length_cm, di.width_cm, di.height_cm, di.item_weight_kg
                 FROM donation_allocation_items ai
                 JOIN donation_items di ON di.item_id = ai.donation_item_id
                 WHERE ai.allocation_id = {$allocationId}"
            );
        }
        unset($allocation);

        return $allocations;
    }

    public function getOrderTracking($donorId = null, $status = null) {
        $conditions = [];
        $having = '';
        if ($donorId !== null) {
            $conditions[] = 'n.donor_id = ' . (int) $donorId;
        }
        if ($status !== null && in_array($status, ['pending', 'processing', 'in_transit', 'completed', 'rejected'], true)) {
            $status = $this->db->real_escape_string($status);
            $having = "HAVING order_status = '{$status}'";
        }

        $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
        $sql = "SELECT n.donation_id, n.order_number, n.donor_name, n.item_name, n.quantity, n.unit,
                       n.donation_date, n.status AS donation_status, n.current_status,
                       COUNT(d.delivery_id) AS delivery_count,
                       STRING_AGG(d.delivery_id::text, ',' ORDER BY d.delivery_id) AS delivery_ids,
                       STRING_AGG(DISTINCT (uv.full_name || '：' || COALESCE(NULLIF(uv.phone, ''), '未提供電話')), '；') AS volunteer_contacts,
                       SUM(CASE WHEN d.status = 'delivered' THEN 1 ELSE 0 END) AS completed_delivery_count,
                       MAX(d.status) AS latest_delivery_status,
                       CASE
                           WHEN n.status = 'rejected' THEN 'rejected'
                           WHEN COUNT(d.delivery_id) > 0 AND SUM(CASE WHEN d.status = 'delivered' THEN 1 ELSE 0 END) = COUNT(d.delivery_id) THEN 'completed'
                           WHEN COUNT(d.delivery_id) > 0 AND SUM(CASE WHEN d.status IN ('claimed', 'picked_up', 'exception') THEN 1 ELSE 0 END) > 0 THEN 'in_transit'
                           WHEN n.status IN ('pending', 'assessed') THEN 'pending'
                           ELSE 'processing'
                       END AS order_status
                FROM donations n
                LEFT JOIN deliveries d ON d.donation_id = n.donation_id
                LEFT JOIN users uv ON uv.user_id = d.volunteer_id AND d.delivery_method = 'volunteer'
                {$where}
                GROUP BY n.donation_id
                {$having}
                ORDER BY n.donation_date DESC";
        $rows = $this->query($sql);

        // 主單整體進度已由上方彙總得出；這裡補上各子單（拆單）的個別配送狀態供追蹤頁顯示。
        foreach ($rows as &$row) {
            $row['sub_orders'] = $this->getDonationAllocations((int) $row['donation_id']);
        }
        unset($row);

        return $rows;
    }
}
