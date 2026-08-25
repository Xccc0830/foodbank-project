<?php
/**
 * 基礎 Model 類
 * 所有模型應繼承此類
 */

require_once __DIR__ . '/../../config/database.php';

abstract class BaseModel {
    protected $db;
    protected $table;

    public function __construct() {
        global $db;
        $this->db = $db->getConnection();
    }

    /**
     * 取得所有記錄
     */
    public function getAll($limit = null, $offset = 0) {
        $sql = "SELECT * FROM {$this->table}";
        
        if ($limit) {
            $sql .= " LIMIT {$limit} OFFSET {$offset}";
        }
        
        $result = $this->db->query($sql);
        $rows = [];
        
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
            }
        }
        
        return $rows;
    }

    /**
     * 根據 ID 取得記錄
     */
    public function getById($id) {
        $id = intval($id);
        $sql = "SELECT * FROM {$this->table} WHERE id = {$id} LIMIT 1";
        $result = $this->db->query($sql);
        return $result ? $result->fetch_assoc() : null;
    }

    /**
     * 插入新記錄
     */
    public function insert($data) {
        $columns = array_keys($data);
        $values = [];

        foreach ($data as $value) {
            if ($value === null) {
                $values[] = 'NULL';
                continue;
            }

            $values[] = "'" . $this->db->real_escape_string((string) $value) . "'";
        }

        $sql = "INSERT INTO {$this->table} (" . implode(',', $columns) . ") VALUES (" . implode(',', $values) . ")";

        if ($this->db->query($sql)) {
            return $this->db->insert_id;
        }

        return false;
    }

    /**
     * 更新記錄
     */
    public function update($id, $data) {
        $id = intval($id);
        $set = [];

        foreach ($data as $key => $value) {
            if ($value === null) {
                $set[] = "{$key} = NULL";
                continue;
            }

            $set[] = "{$key} = '" . $this->db->real_escape_string((string) $value) . "'";
        }

        $setString = implode(',', $set);
        $sql = "UPDATE {$this->table} SET {$setString} WHERE id = {$id}";

        return $this->db->query($sql);
    }

    /**
     * 刪除記錄
     */
    public function delete($id) {
        $id = intval($id);
        $sql = "DELETE FROM {$this->table} WHERE id = {$id}";
        return $this->db->query($sql);
    }

    /**
     * 計數記錄
     */
    public function count() {
        $sql = "SELECT COUNT(*) as total FROM {$this->table}";
        $result = $this->db->query($sql);
        $row = $result->fetch_assoc();
        return $row['total'];
    }

    /**
     * 自定義查詢
     */
    public function query($sql) {
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
?>
