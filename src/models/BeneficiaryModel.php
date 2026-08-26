<?php
/**
 * 受益者模型 (Beneficiary Model)
 * 用於管理食物銀行的受益者信息
 */

require_once 'BaseModel.php';

class BeneficiaryModel extends BaseModel {
    protected $table = 'beneficiaries';

    /**
     * 取得所有受益者
     */
    public function getAllBeneficiaries($status = null) {
        $sql = "SELECT * FROM {$this->table}";
        
        if ($status) {
            $status = $this->db->real_escape_string($status);
            $sql .= " WHERE status = '{$status}'";
        }
        
        $sql .= " ORDER BY registration_date DESC";
        return $this->query($sql);
    }

    /**
     * 根據代碼取得受益者
     */
    public function getBeneficiaryByCode($code) {
        $code = $this->db->real_escape_string($code);
        $sql = "SELECT * FROM {$this->table} WHERE beneficiary_code = '{$code}' LIMIT 1";
        $result = $this->db->query($sql);
        return $result ? $result->fetch_assoc() : null;
    }

    /**
     * 新增受益者
     */
    public function addBeneficiary($beneficiary_data) {
        $beneficiary_data['registration_date'] = date('Y-m-d');
        $beneficiary_data['beneficiary_code'] = $this->generateBeneficiaryCode();
        return $this->insert($beneficiary_data);
    }

    /**
     * 生成受益者代碼
     */
    private function generateBeneficiaryCode() {
        return 'BEN' . date('YmdHis') . rand(1000, 9999);
    }

    /**
     * 根據收入級別取得受益者
     */
    public function getBeneficiariesByIncomeLevel($income_level) {
        $income_level = $this->db->real_escape_string($income_level);
        $sql = "SELECT * FROM {$this->table} WHERE income_level = '{$income_level}' AND status = 'active'";
        return $this->query($sql);
    }

    /**
     * 取得特定 Case Worker 的受益者
     */
    public function getBeneficiariesByCaseWorker($case_worker_id) {
        $case_worker_id = intval($case_worker_id);
        $sql = "SELECT * FROM {$this->table} WHERE case_worker_id = {$case_worker_id} AND status = 'active'";
        return $this->query($sql);
    }

    /**
     * 統計受益者數量
     */
    public function countActiveBeneficiaries() {
        $sql = "SELECT COUNT(*) as total FROM {$this->table} WHERE status = 'active'";
        $result = $this->db->query($sql);
        $row = $result->fetch_assoc();
        return $row['total'] ?? 0;
    }

    /**
     * 統計家庭成員總數
     */
    public function getTotalFamilyMembers() {
        $sql = "SELECT SUM(family_size) as total FROM {$this->table} WHERE status = 'active'";
        $result = $this->db->query($sql);
        $row = $result->fetch_assoc();
        return $row['total'] ?? 0;
    }

    /**
     * 更新受益者狀態
     */
    public function updateBeneficiaryStatus($beneficiary_id, $status) {
        $beneficiary_id = intval($beneficiary_id);
        $status = $this->db->real_escape_string($status);
        
        $sql = "UPDATE {$this->table} SET status = '{$status}', updated_at = NOW() WHERE beneficiary_id = {$beneficiary_id}";
        return $this->db->query($sql);
    }

    /**
     * 搜索受益者
     */
    public function searchBeneficiaries($keyword) {
        $keyword = $this->db->real_escape_string('%' . $keyword . '%');
        $sql = "SELECT * FROM {$this->table} 
                WHERE first_name LIKE '{$keyword}' 
                OR last_name LIKE '{$keyword}' 
                OR email LIKE '{$keyword}' 
                OR phone LIKE '{$keyword}'
                ORDER BY registration_date DESC";
        return $this->query($sql);
    }

    /**
     * 刪除受益者（軟刪或硬刪依需求）
     * 目前實作為硬刪除：直接從資料表移除資料
     */
    public function deleteBeneficiary($beneficiary_id) {
        $id = intval($beneficiary_id);
        if ($id <= 0) {
            return false;
        }

        $sql = "DELETE FROM {$this->table} WHERE beneficiary_id = {$id} LIMIT 1";
        return $this->db->query($sql);
    }
}
?>
