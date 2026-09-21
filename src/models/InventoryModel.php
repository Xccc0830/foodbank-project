<?php
/**
 * 庫存模型
 */

require_once __DIR__ . '/BaseModel.php';

class InventoryModel extends BaseModel
{
    protected $table = 'inventory';

    /**
     * 取得所有庫存，供庫存管理與受益者分配流程使用。
     */
    public function getAllInventory()
    {
        $sql = "SELECT * FROM {$this->table} ORDER BY item_name ASC, inventory_id ASC";
        $result = $this->db->query($sql);
        $rows = [];

        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
            }
        }

        return $rows;
    }
}
