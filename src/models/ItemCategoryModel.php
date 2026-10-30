<?php
/**
 * 物資分類管理模型
 */

require_once __DIR__ . '/BaseModel.php';

class ItemCategoryModel extends BaseModel {
    protected $table = 'item_categories';

    public function getAllCategories($onlyActive = false) {
        $sql = "SELECT * FROM item_categories";
        if ($onlyActive) {
            $sql .= " WHERE status = 'active'";
        }
        $sql .= " ORDER BY display_order ASC, category_id ASC";
        return $this->query($sql);
    }

    public function getCategoryById($categoryId) {
        $categoryId = (int) $categoryId;
        $result = $this->db->query("SELECT * FROM item_categories WHERE category_id = {$categoryId} LIMIT 1");
        return $result ? $result->fetch_assoc() : null;
    }

    public function getCategoryByName($categoryName) {
        $categoryName = $this->db->real_escape_string(trim($categoryName));
        $result = $this->db->query("SELECT * FROM item_categories WHERE category_name = '{$categoryName}' LIMIT 1");
        return $result ? $result->fetch_assoc() : null;
    }

    public function createCategory($data) {
        $categoryName = $this->db->real_escape_string(trim($data['category_name'] ?? ''));
        $description = $this->db->real_escape_string(trim($data['description'] ?? ''));
        $icon = $this->db->real_escape_string(trim($data['icon'] ?? ''));
        $displayOrder = (int) ($data['display_order'] ?? 0);

        if ($categoryName === '') {
            return false;
        }

        if ($this->getCategoryByName($categoryName)) {
            return false;
        }

        $sql = "INSERT INTO item_categories (category_name, description, icon, display_order, status)
                VALUES ('{$categoryName}', '{$description}', '{$icon}', {$displayOrder}, 'active')";
        return $this->db->query($sql) ? $this->db->insert_id : false;
    }

    public function updateCategory($categoryId, $data) {
        $categoryId = (int) $categoryId;
        $categoryName = $this->db->real_escape_string(trim($data['category_name'] ?? ''));
        $description = $this->db->real_escape_string(trim($data['description'] ?? ''));
        $icon = $this->db->real_escape_string(trim($data['icon'] ?? ''));
        $displayOrder = (int) ($data['display_order'] ?? 0);
        $status = in_array($data['status'] ?? '', ['active', 'inactive'], true) ? $data['status'] : 'active';

        if ($categoryName === '') {
            return false;
        }

        $sql = "UPDATE item_categories
                SET category_name = '{$categoryName}',
                    description = '{$description}',
                    icon = '{$icon}',
                    display_order = {$displayOrder},
                    status = '{$status}'
                WHERE category_id = {$categoryId} LIMIT 1";
        return $this->db->query($sql);
    }

    public function deleteCategory($categoryId) {
        $categoryId = (int) $categoryId;
        return $this->db->query("DELETE FROM item_categories WHERE category_id = {$categoryId} LIMIT 1");
    }
}
