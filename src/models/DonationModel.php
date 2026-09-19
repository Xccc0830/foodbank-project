<?php
/**
 * 捐贈模型 (Donation Model)
 * 用於管理食物銀行的捐贈記錄
 */

require_once 'BaseModel.php';

class DonationModel extends BaseModel {
    protected $table = 'donations';

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
        return $this->query($sql);
    }

    /**
     * 根據捐贈者 ID 取得捐贈記錄
     */
    public function getDonationsByDonor($donor_id) {
        $donor_id = intval($donor_id);
        $sql = "SELECT * FROM {$this->table} WHERE donor_id = {$donor_id} ORDER BY donation_date DESC";
        return $this->query($sql);
    }

    /**
     * 新增捐贈記錄
     */
    public function addDonation($donor_data) {
        $donor_data['donation_date'] = date('Y-m-d H:i:s');
        return $this->insert($donor_data);
    }

    /**
     * 更新食物銀行評估結果
     */
    public function updateEvaluation($donation_id, $status, $evaluation_notes = '') {
        $donation_id = intval($donation_id);
        $status = $this->db->real_escape_string($status);
        $evaluation_notes = $this->db->real_escape_string($evaluation_notes);

        $sql = "UPDATE {$this->table}
                SET status = '{$status}', evaluation_notes = '{$evaluation_notes}'
                WHERE donation_id = {$donation_id}";
        return $this->db->query($sql);
    }

    /**
     * 審查商家物資捐贈，完成後移至已評估區塊
     */
    public function reviewMaterialDonation($donation_id, $decision, $rejection_reason = '', $foodbankDeliveryOption = 'food_bank_pickup') {
        $donation_id = (int) $donation_id;
        $evaluationStatus = $decision === 'accepted' ? 'approved_volunteer' : 'rejected';
        $evaluationStatus = $this->db->real_escape_string($evaluationStatus);
        $rejectionReason = $this->db->real_escape_string($rejection_reason);
        $allowedDeliveryOptions = ['food_bank_pickup', 'volunteer_delivery'];
        if (!in_array($foodbankDeliveryOption, $allowedDeliveryOptions, true)) {
            return false;
        }
        $foodbankDeliveryOption = $this->db->real_escape_string($foodbankDeliveryOption);
        $deliveryMethod = $foodbankDeliveryOption === 'food_bank_pickup' ? 'self_delivery' : 'volunteer_assist';

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
        return $this->db->query($sql);
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

    /**
     * 批准物資時產生防拆貼紙編號（若尚未產生）
     */
    public function assignSealCodeIfMissing($donation_id) {
        $donation_id = intval($donation_id);
        $sealCode = 'FB-' . strtoupper(bin2hex(random_bytes(4)));
        $sealCodeEscaped = $this->db->real_escape_string($sealCode);

        $this->db->query("UPDATE {$this->table} SET seal_code = '{$sealCodeEscaped}' WHERE donation_id = {$donation_id} AND (seal_code IS NULL OR seal_code = '')");
        return $sealCode;
    }

    /**
     * 取得特定日期範圍的捐贈
     */
    public function getDonationsByDateRange($start_date, $end_date) {
        $start_date = $this->db->real_escape_string($start_date);
        $end_date = $this->db->real_escape_string($end_date);
        
        $sql = "SELECT * FROM {$this->table} 
                WHERE donation_date BETWEEN '{$start_date}' AND '{$end_date}' 
                ORDER BY donation_date DESC";
        
        return $this->query($sql);
    }

    /**
     * 取得待處理的捐贈
     */
    public function getPendingDonations() {
        $sql = "SELECT * FROM {$this->table} 
                WHERE status = 'pending' 
                ORDER BY donation_date ASC";
        return $this->query($sql);
    }

    /**
     * 更新捐贈狀態
     */
    public function updateDonationStatus($donation_id, $status) {
        $donation_id = intval($donation_id);
        $status = $this->db->real_escape_string($status);
        
        $sql = "UPDATE {$this->table} SET status = '{$status}' WHERE donation_id = {$donation_id}";
        return $this->db->query($sql);
    }

    /**
     * 統計捐贈總額
     */
    public function getTotalDonationAmount($start_date = null, $end_date = null) {
        $sql = "SELECT SUM(quantity) as total FROM {$this->table} WHERE donation_type = 'money'";

        if ($start_date && $end_date) {
            $start_date = $this->db->real_escape_string($start_date);
            $end_date = $this->db->real_escape_string($end_date);
            $sql .= " AND donation_date BETWEEN '{$start_date}' AND '{$end_date}'";
        }

        $result = $this->db->query($sql);
        $row = $result->fetch_assoc();
        return $row['total'] ?? 0;
    }

    public function approveDonation($donation_id, $delivery_method = 'volunteer_assist') {
        $donation_id = (int) $donation_id;
        $delivery_method = $this->db->real_escape_string($delivery_method);

        $status = $delivery_method === 'self_delivery' ? 'approved_self_delivery' : 'approved_volunteer';
        $sealCode = 'FB-' . strtoupper(bin2hex(random_bytes(4)));
        $sealCodeEscaped = $this->db->real_escape_string($sealCode);

        $sql = "UPDATE {$this->table}
                SET status = '{$status}',
                    delivery_method = '{$delivery_method}',
                    seal_code = '{$sealCodeEscaped}',
                    approved_at = NOW()
                WHERE donation_id = {$donation_id}";
        return $this->db->query($sql);
    }

    public function rejectDonation($donation_id, $reason = '') {
        $donation_id = (int) $donation_id;
        $reason = $this->db->real_escape_string($reason);

        $sql = "UPDATE {$this->table}
                SET status = 'rejected',
                    rejection_reason = '{$reason}',
                    rejected_at = NOW()
                WHERE donation_id = {$donation_id}";
        return $this->db->query($sql);
    }

    public function publishDonation($donation_id, $split_count = 1, $rewards_config = []) {
        $donation_id = (int) $donation_id;
        $split_count = max(1, (int) $split_count);

        if ($split_count === 1) {
            $sql = "UPDATE {$this->table}
                    SET status = 'published',
                        published_at = NOW(),
                        split_count = 1
                    WHERE donation_id = {$donation_id}";
            return $this->db->query($sql);
        }

        $this->db->begin_transaction();
        try {
            $this->db->query("UPDATE {$this->table}
                            SET status = 'published',
                                published_at = NOW(),
                                split_count = {$split_count}
                            WHERE donation_id = {$donation_id}");
            $this->db->commit();
            return true;
        } catch (Throwable $e) {
            $this->db->rollback();
            return false;
        }
    }

    public function updateDeliveryStatus($donation_id, $new_status) {
        $donation_id = (int) $donation_id;
        $validStatuses = [
            'waiting_pickup',
            'volunteer_received',
            'in_transit',
            'at_foodbank',
            'inspection_complete'
        ];

        $new_status = $this->db->real_escape_string($new_status);
        if (!in_array($new_status, $validStatuses)) {
            return false;
        }

        $sql = "UPDATE {$this->table}
                SET current_status = '{$new_status}',
                    status_updated_at = NOW()
                WHERE donation_id = {$donation_id}";
        return $this->db->query($sql);
    }

    public function getDonationsByEvaluationStatus($status) {
        $status = $this->db->real_escape_string($status);
        $sql = "SELECT * FROM {$this->table}
                WHERE status = '{$status}'
                ORDER BY donation_date DESC";
        return $this->query($sql);
    }

    public function getPublishedDonations() {
        $sql = "SELECT * FROM {$this->table}
                WHERE status IN ('published', 'waiting_pickup', 'volunteer_received', 'in_transit')
                ORDER BY published_at DESC";
        return $this->query($sql);
    }
}
