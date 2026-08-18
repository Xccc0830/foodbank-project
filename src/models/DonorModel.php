<?php
/**
 * 捐獻者模型 (Donor Model)
 */

require_once 'BaseModel.php';

class DonorModel extends BaseModel {
    protected $table = 'donors';

    /**
     * 取得所有捐獻者
     */
    public function getAllDonors($status = null) {
        $sql = "SELECT * FROM {$this->table}";
        
        if ($status) {
            $status = $this->db->real_escape_string($status);
            $sql .= " WHERE status = '{$status}'";
        }
        
        $sql .= " ORDER BY total_donations DESC";
        return $this->query($sql);
    }

    /**
     * 根據代碼取得捐獻者
     */
    public function getDonorByCode($code) {
        $code = $this->db->real_escape_string($code);
        $sql = "SELECT * FROM {$this->table} WHERE donor_code = '{$code}' LIMIT 1";
        $result = $this->db->query($sql);
        return $result ? $result->fetch_assoc() : null;
    }

    /**
     * 新增捐獻者
     */
    public function addDonor($donor_data) {
        $donor_data['donor_code'] = $this->generateDonorCode();
        return $this->insert($donor_data);
    }

    /**
     * 生成捐獻者代碼
     */
    private function generateDonorCode() {
        return 'DON' . date('YmdHis') . rand(1000, 9999);
    }

    /**
     * 根據類型取得捐獻者
     */
    public function getDonorsByType($type) {
        $type = $this->db->real_escape_string($type);
        $sql = "SELECT * FROM {$this->table} WHERE donor_type = '{$type}' AND status = 'active'";
        return $this->query($sql);
    }

    /**
     * 統計捐獻者數量
     */
    public function countActiveDonors() {
        $sql = "SELECT COUNT(*) as total FROM {$this->table} WHERE status = 'active'";
        $result = $this->db->query($sql);
        $row = $result->fetch_assoc();
        return $row['total'] ?? 0;
    }

    /**
     * 取得最大捐獻者（按捐獻總額）
     */
    public function getTopDonors($limit = 10) {
        $limit = intval($limit);
        $sql = "SELECT * FROM {$this->table} WHERE status = 'active' ORDER BY total_donations DESC LIMIT {$limit}";
        return $this->query($sql);
    }

    /**
     * 更新捐獻者狀態
     */
    public function updateDonorStatus($donor_id, $status) {
        $donor_id = intval($donor_id);
        $status = $this->db->real_escape_string($status);
        
        $sql = "UPDATE {$this->table} SET status = '{$status}', updated_at = NOW() WHERE donor_id = {$donor_id}";
        return $this->db->query($sql);
    }

    /**
     * 更新捐獻總額
     */
    public function updateTotalDonations($donor_id, $amount) {
        $donor_id = intval($donor_id);
        $amount = floatval($amount);
        
        $sql = "UPDATE {$this->table} SET total_donations = total_donations + {$amount} WHERE donor_id = {$donor_id}";
        return $this->db->query($sql);
    }
}
?>
