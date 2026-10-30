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
        $this->ensureRevisionSupport();
    }

    /** 物資單可由商家重送時，允許修改的欄位 */
    const RESUBMIT_FIELDS = [
        'item_name', 'quantity', 'unit', 'weight_kg', 'size_description',
        'expiry_date', 'pickup_deadline', 'photo_path', 'donor_address', 'notes',
    ];

    /** 欄位的中文名稱（審核頁勾選、商家頁顯示用） */
    const RESUBMIT_FIELD_LABELS = [
        'item_name' => '物資名稱',
        'quantity' => '數量',
        'unit' => '單位',
        'weight_kg' => '重量(kg)',
        'size_description' => '尺寸說明',
        'expiry_date' => '保存期限',
        'pickup_deadline' => '取貨期限',
        'photo_path' => '物資照片',
        'donor_address' => '取貨地址',
        'notes' => '備註',
    ];

    /** 商家的預設取貨地址（沒有則回傳空字串） */
    public function getDefaultPickupAddress($userId) {
        $userId = (int) $userId;
        $result = $this->db->query("SELECT default_pickup_address FROM users WHERE user_id = {$userId} LIMIT 1");
        $row = $result ? $result->fetch_assoc() : null;
        return trim((string) ($row['default_pickup_address'] ?? ''));
    }

    public function setDefaultPickupAddress($userId, $address) {
        $address = trim((string) $address);
        if ($address === '' || mb_strlen($address) > 255) {
            return false;
        }
        $stmt = $this->db->prepare("UPDATE users SET default_pickup_address = ? WHERE user_id = ?");
        $userId = (int) $userId;
        $stmt->bind_param('si', $address, $userId);
        return $stmt->execute();
    }

    private function ensureRevisionSupport() {
        if ($this->hasDonationItemsTable()) {
            $itemColumns = [
                'category_id' => "ALTER TABLE donation_items ADD category_id INT DEFAULT NULL",
                'storage_condition' => "ALTER TABLE donation_items ADD storage_condition ENUM('room','chilled','frozen') NOT NULL DEFAULT 'room'",
                'package_state' => "ALTER TABLE donation_items ADD package_state ENUM('sealed','opened') NOT NULL DEFAULT 'sealed'",
            ];
            foreach ($itemColumns as $column => $alterSql) {
                $result = $this->db->query("SHOW COLUMNS FROM donation_items LIKE '{$column}'");
                if ($result && $result->num_rows === 0) {
                    $this->db->query($alterSql);
                }
            }
        }
        $userColumn = $this->db->query("SHOW COLUMNS FROM users LIKE 'default_pickup_address'");
        if ($userColumn && $userColumn->num_rows === 0) {
            $this->db->query("ALTER TABLE users ADD default_pickup_address VARCHAR(255) DEFAULT NULL");
        }

        $columns = [
            'revision_no' => "ALTER TABLE donations ADD revision_no INT NOT NULL DEFAULT 1",
            'rejected_fields' => "ALTER TABLE donations ADD rejected_fields TEXT NULL",
        ];
        foreach ($columns as $column => $alterSql) {
            $result = $this->db->query("SHOW COLUMNS FROM donations LIKE '{$column}'");
            if ($result && $result->num_rows === 0) {
                $this->db->query($alterSql);
            }
        }

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS donation_revisions (
                revision_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                donation_id INT NOT NULL,
                revision_no INT NOT NULL,
                event ENUM('submitted','approved','rejected','resubmitted') NOT NULL,
                snapshot LONGTEXT DEFAULT NULL,
                reason TEXT DEFAULT NULL,
                rejected_fields TEXT DEFAULT NULL,
                actor_id INT DEFAULT NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_donation_revisions_donation (donation_id, revision_no),
                CONSTRAINT donation_revisions_ibfk_1 FOREIGN KEY (donation_id) REFERENCES donations (donation_id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    /**
     * 寫一筆送審歷史（含當下物資單內容快照），不覆蓋舊紀錄。
     */
    private function recordRevision($donationId, $event, $reason = null, array $rejectedFields = [], $actorId = null) {
        $donationId = (int) $donationId;
        $result = $this->db->query("SELECT * FROM donations WHERE donation_id = {$donationId} LIMIT 1");
        $donation = $result ? $result->fetch_assoc() : null;
        if (!$donation) {
            return false;
        }

        $snapshot = [];
        foreach (self::RESUBMIT_FIELDS as $field) {
            $snapshot[$field] = $donation[$field] ?? null;
        }
        $stmt = $this->db->prepare(
            "INSERT INTO donation_revisions (donation_id, revision_no, event, snapshot, reason, rejected_fields, actor_id)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $revisionNo = (int) $donation['revision_no'];
        $snapshotJson = json_encode($snapshot, JSON_UNESCAPED_UNICODE);
        $fieldsJson = $rejectedFields ? json_encode(array_values($rejectedFields), JSON_UNESCAPED_UNICODE) : null;
        $stmt->bind_param('iissssi', $donationId, $revisionNo, $event, $snapshotJson, $reason, $fieldsJson, $actorId);
        return $stmt->execute();
    }

    /**
     * 取得某張物資單的送審歷史（新到舊）。
     */
    public function getRevisions($donationId) {
        $donationId = (int) $donationId;
        $result = $this->db->query(
            "SELECT * FROM donation_revisions WHERE donation_id = {$donationId} ORDER BY revision_id DESC"
        );
        $rows = [];
        while ($result && ($row = $result->fetch_assoc())) {
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * 把三個舊狀態欄位（status / evaluation_status / current_status）合併成一個統一狀態。
     * 回傳：submitted、rejected、approved、published、received（已到食物銀行）、archived。
     */
    public static function lifecycleState(array $donation) {
        $status = $donation['status'] ?? 'pending';
        $evaluation = $donation['evaluation_status'] ?? 'pending';
        $current = $donation['current_status'] ?? 'waiting_pickup';

        if ($status === 'archived') {
            return 'archived';
        }
        if ($status === 'rejected' || $evaluation === 'rejected') {
            return 'rejected';
        }
        if ($current === 'inspection_complete') {
            return 'received';
        }
        if ($status === 'published' || $evaluation === 'published') {
            return 'published';
        }
        if (in_array($evaluation, ['approved_volunteer', 'approved_self_delivery'], true) || $status === 'approved') {
            return 'approved';
        }
        return 'submitted';
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
        if ($donationId) {
            $this->recordRevision($donationId, 'submitted', null, [], $donor_data['donor_id'] ?? null);
        }
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

    /** 一張物資單底下的所有物資明細（含分類名稱），依填寫順序排列。 */
    public function getDonationItems($donationId) {
        $donationId = (int) $donationId;
        if (!$this->hasDonationItemsTable()) {
            return [];
        }
        return $this->query(
            "SELECT i.*, c.category_name
             FROM donation_items i
             LEFT JOIN item_categories c ON c.category_id = i.category_id
             WHERE i.donation_id = {$donationId} ORDER BY i.item_id ASC"
        );
    }

    private function hasDonationItemsTable() {
        $result = $this->db->query("SHOW TABLES LIKE 'donation_items'");
        return $result && $result->num_rows > 0;
    }

    /**
     * 審查商家物資捐贈，完成後移至已評估區塊
     */
    public function reviewMaterialDonation($donation_id, $decision, $rejection_reason = '', $foodbankDeliveryOption = '', array $rejectedFields = [], $reviewerId = null) {
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
                    rejected_fields = " . ($decision === 'accepted' || !$rejectedFields ? 'NULL' : "'" . $this->db->real_escape_string(json_encode(array_values($rejectedFields), JSON_UNESCAPED_UNICODE)) . "'") . ",
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
        if ($this->db->affected_rows < 1) {
            return false;
        }

        $this->recordRevision(
            $donation_id,
            $decision === 'accepted' ? 'approved' : 'rejected',
            $decision === 'accepted' ? null : $rejection_reason,
            $decision === 'accepted' ? [] : $rejectedFields,
            $reviewerId
        );
        return true;
    }

    /**
     * 商家在被婉拒的原單上修改後重新送審。
     * 只有該商家本人、且狀態為已婉拒才可操作；若審核方標記了問題欄位，只能改那些欄位。
     * 失敗時回傳錯誤訊息字串，成功回傳 true。
     */
    public function resubmitDonation($donationId, $donorId, array $changes) {
        $donationId = (int) $donationId;
        $donorId = (int) $donorId;
        $donation = $this->getDonationById($donationId);

        if (!$donation || (int) $donation['donor_id'] !== $donorId) {
            return '找不到這張物資單。';
        }
        if (self::lifecycleState($donation) !== 'rejected') {
            return '只有被婉拒的物資單可以重新送審。';
        }

        $allowed = self::RESUBMIT_FIELDS;
        $flagged = json_decode((string) ($donation['rejected_fields'] ?? ''), true);
        if (is_array($flagged) && $flagged) {
            $allowed = array_values(array_intersect($allowed, $flagged));
        }
        $changes = array_intersect_key($changes, array_flip($allowed));
        if (!$changes) {
            return '請修改被標記的欄位後再送審。';
        }

        $sets = [];
        foreach ($changes as $field => $value) {
            $sets[] = "{$field} = " . ($value === null || $value === '' ? 'NULL' : "'" . $this->db->real_escape_string((string) $value) . "'");
        }
        $sets[] = "status = 'pending'";
        $sets[] = "evaluation_status = 'pending'";
        $sets[] = "revision_no = revision_no + 1";
        $sets[] = "updated_at = NOW()";

        // 條件再檢查一次狀態，避免同時被重複送出
        $this->db->query(
            "UPDATE {$this->table} SET " . implode(', ', $sets) . "
             WHERE donation_id = {$donationId} AND donor_id = {$donorId}
               AND (status = 'rejected' OR evaluation_status = 'rejected')"
        );
        if ($this->db->affected_rows < 1) {
            return '送審失敗，請重新整理後再試。';
        }

        // 第一筆物資明細與主單同步，審核頁才會看到修改後的內容
        $itemChanges = array_intersect_key($changes, array_flip(['item_name', 'quantity', 'unit', 'expiry_date']));
        if ($itemChanges && $this->hasDonationItemsTable()) {
            $itemSets = [];
            foreach ($itemChanges as $field => $value) {
                $itemSets[] = "{$field} = " . ($value === null || $value === '' ? 'NULL' : "'" . $this->db->real_escape_string((string) $value) . "'");
            }
            $this->db->query(
                "UPDATE donation_items SET " . implode(', ', $itemSets) . "
                 WHERE item_id = (SELECT first_id FROM (SELECT MIN(item_id) AS first_id FROM donation_items WHERE donation_id = {$donationId}) t)"
            );
        }

        $this->recordRevision($donationId, 'resubmitted', null, [], $donorId);
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

        // 一次填多項物資時，審核人員可逐項修改；主單的名稱／重量改由各項物資彙總。
        $itemEdits = [];
        if (isset($publishData['items']) && is_array($publishData['items'])) {
            $existingItems = $this->getDonationItems($donation_id);
            $existingIds = array_map('intval', array_column($existingItems, 'item_id'));
            foreach ($publishData['items'] as $itemId => $edit) {
                if (!in_array((int) $itemId, $existingIds, true) || !is_array($edit)) {
                    continue;
                }
                $name = trim((string) ($edit['item_name'] ?? ''));
                $quantity = (float) ($edit['quantity'] ?? 0);
                if ($name === '' || $quantity <= 0) {
                    return false;
                }
                $itemEdits[(int) $itemId] = [
                    'name' => $name,
                    'quantity' => $quantity,
                    'weight' => is_numeric($edit['item_weight_kg'] ?? null) ? max(0, (float) $edit['item_weight_kg']) : null,
                ];
            }
            if ($itemEdits) {
                $names = array_column($itemEdits, 'name');
                $summaryName = count($names) > 1 ? $names[0] . ' 等 ' . count($names) . ' 項' : $names[0];
                $totalWeight = array_sum(array_map(static function ($e) { return $e['weight'] ?? 0; }, $itemEdits));
                $publishData['item_name'] = $summaryName;
                $publishData['weight_kg'] = $totalWeight;
                $publishData['quantity'] = reset($itemEdits)['quantity'];
                $updates = array_values(array_filter($updates, static function ($sql) {
                    return strpos($sql, 'item_name =') !== 0 && strpos($sql, 'quantity =') !== 0 && strpos($sql, 'weight_kg =') !== 0;
                }));
                $updates[] = "item_name = '" . $this->db->real_escape_string($summaryName) . "'";
                $updates[] = 'quantity = ' . (float) $publishData['quantity'];
                $updates[] = 'weight_kg = ' . (float) $totalWeight;
            }
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

        foreach ($itemEdits as $itemId => $edit) {
            $weightSql = $edit['weight'] === null ? 'NULL' : (float) $edit['weight'];
            if (!$this->db->query(
                "UPDATE donation_items SET item_name = '" . $this->db->real_escape_string($edit['name']) . "',
                        quantity = " . (float) $edit['quantity'] . ", item_weight_kg = {$weightSql}
                 WHERE item_id = " . (int) $itemId . " AND donation_id = {$donation_id}"
            )) {
                $this->db->rollback();
                return false;
            }
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
                '新的 GO Rider 配送任務',
                sprintf(
                    '物資「%s」已發布 %d 筆配送任務，請立即前往配送任務查看並接單。',
                    (string) ($publishData['item_name'] ?? $existingDonation['item_name'] ?? '新物資'),
                    $splitCount
                ),
                'info',
                'general'
            );
            if ($notifiedCount === 0) {
                error_log("配送任務發布後沒有通知到 GO Rider：donation_id={$donation_id}");
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
                       GROUP_CONCAT(d.delivery_id ORDER BY d.delivery_id) AS delivery_ids,
                       GROUP_CONCAT(DISTINCT CONCAT(uv.full_name, '：', COALESCE(NULLIF(uv.phone, ''), '未提供電話')) SEPARATOR '；') AS volunteer_contacts,
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
