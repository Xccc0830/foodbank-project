<?php
/**
 * 捐贈模型 (Donation Model)
 * 用於管理食物銀行的捐贈記錄
 */

require_once 'BaseModel.php';

class DonationModel extends BaseModel {
    protected $table = 'donations';

    public function __construct() {
        parent::__construct();
        $this->ensureOrderNumberColumn();
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
        $itemData = [
            'item_name' => $donor_data['item_name'] ?? null,
            'donation_type' => $donor_data['donation_type'] ?? 'other',
            'quantity' => $donor_data['quantity'] ?? null,
            'unit' => $donor_data['unit'] ?? null,
            'weight_kg' => $donor_data['weight_kg'] ?? null,
            'size_description' => $donor_data['size_description'] ?? null,
            'expiry_date' => $donor_data['expiry_date'] ?? null,
            'photo_path' => $donor_data['photo_path'] ?? null,
        ];
        $donationId = $this->insert($donor_data);
        if ($donationId && $this->hasDonationItemsTable()) {
            $columns = array_keys($itemData);
            $values = array_map(function ($value) {
                return $value === null ? 'NULL' : "'" . $this->db->real_escape_string((string) $value) . "'";
            }, $itemData);
            $this->db->query(
                "INSERT INTO donation_items (donation_id, " . implode(', ', $columns) . ") VALUES (" . (int) $donationId . ", " . implode(', ', $values) . ")"
            );
        }
        return $donationId;
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
        return $result ? $result->fetch_assoc() : null;
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
        $pickupAddress = trim((string) ($publishData['donor_address'] ?? $existingDonation['donor_address'] ?? ''));
        if ($pickupAddress === '') {
            $pickupAddress = (string) ($publishData['donor_name'] ?? $existingDonation['donor_name'] ?? '商家取貨地點');
        }
        $itemCategory = $this->db->real_escape_string((string) ($existingDonation['donation_type'] ?? 'other'));
        $itemDescription = $this->db->real_escape_string((string) ($publishData['item_name'] ?? $existingDonation['item_name'] ?? '物資'));
        $pickupAddress = $this->db->real_escape_string($pickupAddress);
        $sealCode = $this->db->real_escape_string((string) ($existingDonation['seal_code'] ?? ''));

        for ($taskNumber = 0; $taskNumber < $splitCount; $taskNumber++) {
            $taskSql = "INSERT INTO deliveries
                (donation_id, delivery_method, vehicle_type, total_distance_km, weight_kg, seal_code, urgency, points, pickup_address, delivery_address, status, item_category, item_description)
                VALUES ({$donation_id}, '{$deliveryMethod}', '{$vehicleType}', 0, {$weight}, '{$sealCode}', 'normal', 0, '{$pickupAddress}', '忠信食物銀行', 'open', '{$itemCategory}', '{$itemDescription}')";
            if (!$this->db->query($taskSql)) {
                $this->db->rollback();
                return false;
            }
        }

        $this->db->commit();
        return true;
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
                {$where}
                GROUP BY n.donation_id
                {$having}
                ORDER BY n.donation_date DESC";
        return $this->query($sql);
    }
}
