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
    public function getAllDonations($status = null) {
        $sql = "SELECT * FROM {$this->table}";
        
        if ($status) {
            $status = $this->db->real_escape_string($status);
            $sql .= " WHERE status = '{$status}'";
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
}
?>
