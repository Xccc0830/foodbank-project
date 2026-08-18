<?php
/**
 * 庫存模型 (Inventory Model)
 * 用於管理食物銀行的庫存
 */

require_once 'BaseModel.php';

class InventoryModel extends BaseModel {
    protected $table = 'inventory';

    /**
     * 取得所有庫存項目
     */
    public function getAllInventory($status = null) {
        $sql = "SELECT * FROM {$this->table}";
        
        if ($status) {
            $status = $this->db->real_escape_string($status);
            $sql .= " WHERE status = '{$status}'";
        }
        
        $sql .= " ORDER BY item_name ASC";
        return $this->query($sql);
    }

    /**
     * 根據代碼取得庫存項目
     */
    public function getInventoryByCode($code) {
        $code = $this->db->real_escape_string($code);
        $sql = "SELECT * FROM {$this->table} WHERE item_code = '{$code}' LIMIT 1";
        $result = $this->db->query($sql);
        return $result ? $result->fetch_assoc() : null;
    }

    /**
     * 根據分類取得庫存項目
     */
    public function getInventoryByCategory($category) {
        $category = $this->db->real_escape_string($category);
        $sql = "SELECT * FROM {$this->table} WHERE category = '{$category}' AND status = 'available'";
        return $this->query($sql);
    }

    /**
     * 新增庫存項目
     */
    public function addInventoryItem($item_data) {
        $item_data['item_code'] = $this->generateItemCode();
        return $this->insert($item_data);
    }

    /**
     * 生成庫存代碼
     */
    private function generateItemCode() {
        return 'INV' . date('YmdHis') . rand(1000, 9999);
    }

    /**
     * 更新庫存數量
     */
    public function updateQuantity($inventory_id, $quantity) {
        $inventory_id = intval($inventory_id);
        $quantity = floatval($quantity);
        
        $sql = "UPDATE {$this->table} SET quantity_on_hand = {$quantity} WHERE inventory_id = {$inventory_id}";
        return $this->db->query($sql);
    }

    /**
     * 增加庫存
     */
    public function addQuantity($inventory_id, $quantity) {
        $inventory_id = intval($inventory_id);
        $quantity = floatval($quantity);
        
        $sql = "UPDATE {$this->table} SET quantity_on_hand = quantity_on_hand + {$quantity} WHERE inventory_id = {$inventory_id}";
        return $this->db->query($sql);
    }

    /**
     * 減少庫存
     */
    public function reduceQuantity($inventory_id, $quantity) {
        $inventory_id = intval($inventory_id);
        $quantity = floatval($quantity);
        
        $sql = "UPDATE {$this->table} SET quantity_on_hand = quantity_on_hand - {$quantity} WHERE inventory_id = {$inventory_id}";
        return $this->db->query($sql);
    }

    /**
     * 取得庫存不足的項目
     */
    public function getLowStockItems() {
        $sql = "SELECT * FROM {$this->table} 
                WHERE quantity_on_hand <= reorder_level 
                AND status = 'available' 
                ORDER BY quantity_on_hand ASC";
        return $this->query($sql);
    }

    /**
     * 取得即將過期的項目
     */
    public function getExpiringSoonItems($days = 30) {
        $future_date = date('Y-m-d', strtotime("+{$days} days"));
        $today = date('Y-m-d');
        
        $sql = "SELECT * FROM {$this->table} 
                WHERE expiry_date BETWEEN '{$today}' AND '{$future_date}' 
                AND status = 'available'
                ORDER BY expiry_date ASC";
        return $this->query($sql);
    }

    /**
     * 取得總庫存價值（按數量計）
     */
    public function getTotalInventoryValue() {
        $sql = "SELECT SUM(quantity_on_hand) as total FROM {$this->table} WHERE status = 'available'";
        $result = $this->db->query($sql);
        $row = $result->fetch_assoc();
        return $row['total'] ?? 0;
    }

    /**
     * 統計可用項目數
     */
    public function countAvailableItems() {
        $sql = "SELECT COUNT(*) as total FROM {$this->table} WHERE status = 'available'";
        $result = $this->db->query($sql);
        $row = $result->fetch_assoc();
        return $row['total'] ?? 0;
    }

    /**
     * 更新庫存狀態
     */
    public function updateInventoryStatus($inventory_id, $status) {
        $inventory_id = intval($inventory_id);
        $status = $this->db->real_escape_string($status);
        
        $sql = "UPDATE {$this->table} SET status = '{$status}', last_updated = NOW() WHERE inventory_id = {$inventory_id}";
        return $this->db->query($sql);
    }

    /**
     * 搜索庫存項目
     */
    public function searchInventory($keyword) {
        $keyword = $this->db->real_escape_string('%' . $keyword . '%');
        $sql = "SELECT * FROM {$this->table} 
                WHERE item_name LIKE '{$keyword}' 
                OR item_code LIKE '{$keyword}' 
                OR description LIKE '{$keyword}'
                ORDER BY item_name ASC";
        return $this->query($sql);
    }
}
?>
