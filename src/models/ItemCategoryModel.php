<?php
/**
 * 物資分類管理模型
 */

require_once __DIR__ . '/BaseModel.php';
require_once __DIR__ . '/../helpers/CategorySuggestHelper.php';

class ItemCategoryModel extends BaseModel {
    protected $table = 'item_categories';

    /** 沒設定減碳係數時使用的預設值（kgCO2e／每公斤惜食，與永續報告一致） */
    const DEFAULT_CARBON_FACTOR = 2.5;
    /** 沒設定即期提醒天數時使用的預設值 */
    const DEFAULT_EXPIRY_ALERT_DAYS = 7;
    const STORAGE_LABELS = ['room' => '常溫', 'chilled' => '冷藏', 'frozen' => '冷凍'];

    /** 預設分類的初始建議（可在「物資分類」頁修改）：建議保存方式、即期提醒天數 */
    private const DEFAULT_PROFILES = [
        '蔬菜' => ['chilled', 2], '水果' => ['room', 3], '穀物' => ['room', 5], '乳製品' => ['chilled', 3],
        '肉類' => ['chilled', 2], '罐頭食品' => ['room', 30], '乾貨' => ['room', 30], '飲料' => ['room', 14], '其他' => ['room', 7],
    ];

    public function __construct() {
        parent::__construct();
        $this->ensureKeywordsColumn();
        $this->ensureProfileColumns();
    }

    private function ensureProfileColumns() {
        $columns = [
            'default_storage' => "ALTER TABLE item_categories ADD default_storage ENUM('room','chilled','frozen') DEFAULT NULL AFTER keywords",
            'carbon_factor' => "ALTER TABLE item_categories ADD carbon_factor DECIMAL(6,2) DEFAULT NULL AFTER default_storage",
            'expiry_alert_days' => "ALTER TABLE item_categories ADD expiry_alert_days INT DEFAULT NULL AFTER carbon_factor",
        ];
        $added = false;
        foreach ($columns as $column => $alterSql) {
            $exists = $this->db->query("SHOW COLUMNS FROM item_categories LIKE '{$column}'");
            if ($exists && $exists->num_rows === 0) {
                $this->db->query($alterSql);
                $added = true;
            }
        }
        if ($added) {
            $stmt = $this->db->prepare("UPDATE item_categories SET default_storage = ?, expiry_alert_days = ? WHERE category_name = ? AND default_storage IS NULL AND expiry_alert_days IS NULL");
            foreach (self::DEFAULT_PROFILES as $categoryName => [$storage, $days]) {
                $stmt->bind_param('sis', $storage, $days, $categoryName);
                $stmt->execute();
            }
        }
        $this->db->query(
            "CREATE TABLE IF NOT EXISTS category_corrections (
                correction_id INT AUTO_INCREMENT PRIMARY KEY,
                item_name VARCHAR(150) NOT NULL,
                suggested_category_id INT DEFAULT NULL,
                chosen_category_id INT NOT NULL,
                source ENUM('donation','inventory') NOT NULL,
                resolved TINYINT(1) NOT NULL DEFAULT 0,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_category_corrections_open (resolved, chosen_category_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    /**
     * 使用者選的分類和系統自動判斷不同時記下來，讓食物銀行之後一鍵加入關鍵字。
     */
    public function recordCorrectionIfNeeded($itemName, $chosenCategoryId, $source) {
        $itemName = trim((string) $itemName);
        $chosenCategoryId = (int) $chosenCategoryId;
        if ($itemName === '' || $chosenCategoryId <= 0 || !in_array($source, ['donation', 'inventory'], true)) {
            return false;
        }
        $suggestion = CategorySuggestHelper::suggest($this->db, $itemName);
        $suggestedId = $suggestion['category_id'] ?? null;
        if ($suggestedId === $chosenCategoryId) {
            return false;
        }
        $stmt = $this->db->prepare("INSERT INTO category_corrections (item_name, suggested_category_id, chosen_category_id, source) VALUES (?, ?, ?, ?)");
        $nameValue = mb_substr($itemName, 0, 150);
        $stmt->bind_param('siis', $nameValue, $suggestedId, $chosenCategoryId, $source);
        return $stmt->execute();
    }

    /** 尚未處理的修正，同名稱＋同分類合併計次，最多 20 筆 */
    public function getPendingCorrections() {
        return $this->query(
            "SELECT c.item_name, c.chosen_category_id, chosen.category_name AS chosen_name,
                    MAX(suggested.category_name) AS suggested_name, COUNT(*) AS times, MAX(c.created_at) AS last_at
             FROM category_corrections c
             INNER JOIN item_categories chosen ON chosen.category_id = c.chosen_category_id
             LEFT JOIN item_categories suggested ON suggested.category_id = c.suggested_category_id
             WHERE c.resolved = 0
             GROUP BY c.item_name, c.chosen_category_id, chosen.category_name
             ORDER BY last_at DESC
             LIMIT 20"
        );
    }

    public function resolveCorrections($itemName, $chosenCategoryId) {
        $stmt = $this->db->prepare("UPDATE category_corrections SET resolved = 1 WHERE item_name = ? AND chosen_category_id = ? AND resolved = 0");
        $chosenCategoryId = (int) $chosenCategoryId;
        $stmt->bind_param('si', $itemName, $chosenCategoryId);
        return $stmt->execute();
    }

    /** 把一個關鍵字加進分類（已存在就不重複） */
    public function addKeyword($categoryId, $keyword) {
        $category = $this->getCategoryById($categoryId);
        $newKeywords = CategorySuggestHelper::parseKeywords($keyword);
        if (!$category || !$newKeywords) {
            return false;
        }
        $merged = implode('、', array_values(array_unique(array_merge(CategorySuggestHelper::parseKeywords($category['keywords'] ?? ''), $newKeywords))));
        $stmt = $this->db->prepare("UPDATE item_categories SET keywords = ? WHERE category_id = ?");
        $categoryId = (int) $categoryId;
        $stmt->bind_param('si', $merged, $categoryId);
        return $stmt->execute();
    }

    /** 從物資名稱去掉數量、重量與包裝單位，當作建議關鍵字（例如「富士蘋果 2kg」→「富士蘋果」） */
    public static function suggestKeywordFromName($itemName) {
        $keyword = preg_replace('/[\d０-９.．]+\s*(kg|g|ml|l|公斤|公克|克|毫升|公升|包|瓶|罐|盒|袋|箱|個|入|顆|條|片|斤)?/iu', '', (string) $itemName);
        $keyword = trim(preg_replace('/[\s()（）\[\]【】x×*+\-\/]+/u', '', $keyword));
        return mb_substr($keyword !== '' ? $keyword : trim((string) $itemName), 0, 20);
    }

    /** 自動分類用的關鍵字欄位；既有的預設分類第一次會帶入系統建議的關鍵字（NULL 才帶，清空過的不會被補回）。 */
    private function ensureKeywordsColumn() {
        $column = $this->db->query("SHOW COLUMNS FROM item_categories LIKE 'keywords'");
        if ($column && $column->num_rows === 0) {
            $this->db->query("ALTER TABLE item_categories ADD keywords TEXT DEFAULT NULL AFTER description");
        }
        $stmt = $this->db->prepare("UPDATE item_categories SET keywords = ? WHERE category_name = ? AND keywords IS NULL");
        foreach (CategorySuggestHelper::DEFAULT_KEYWORDS as $categoryName => $keywords) {
            $keywordText = implode('、', $keywords);
            $stmt->bind_param('ss', $keywordText, $categoryName);
            $stmt->execute();
        }
    }

    /** 建議保存方式、減碳係數、即期提醒天數轉成 SQL 值；留空代表使用系統預設 */
    private function profileSql(array $data) {
        $storage = $data['default_storage'] ?? '';
        $storageSql = isset(self::STORAGE_LABELS[$storage]) ? "'{$storage}'" : 'NULL';
        $carbon = $data['carbon_factor'] ?? '';
        $carbonSql = is_numeric($carbon) && (float) $carbon >= 0 && (float) $carbon < 1000 ? (string) round((float) $carbon, 2) : 'NULL';
        $alert = $data['expiry_alert_days'] ?? '';
        $alertSql = is_numeric($alert) && (int) $alert >= 0 && (int) $alert <= 365 ? (string) (int) $alert : 'NULL';
        return [$storageSql, $carbonSql, $alertSql];
    }

    private function normalizeKeywords($text) {
        return implode('、', CategorySuggestHelper::parseKeywords($text));
    }

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
        $keywords = $this->db->real_escape_string($this->normalizeKeywords($data['keywords'] ?? ''));
        $displayOrder = (int) ($data['display_order'] ?? 0);
        [$storageSql, $carbonSql, $alertSql] = $this->profileSql($data);

        if ($categoryName === '') {
            return false;
        }

        if ($this->getCategoryByName($categoryName)) {
            return false;
        }

        $sql = "INSERT INTO item_categories (category_name, description, keywords, default_storage, carbon_factor, expiry_alert_days, icon, display_order, status)
                VALUES ('{$categoryName}', '{$description}', '{$keywords}', {$storageSql}, {$carbonSql}, {$alertSql}, '{$icon}', {$displayOrder}, 'active')";
        return $this->db->query($sql) ? $this->db->insert_id : false;
    }

    public function updateCategory($categoryId, $data) {
        $categoryId = (int) $categoryId;
        $categoryName = $this->db->real_escape_string(trim($data['category_name'] ?? ''));
        $description = $this->db->real_escape_string(trim($data['description'] ?? ''));
        $icon = $this->db->real_escape_string(trim($data['icon'] ?? ''));
        $keywords = $this->db->real_escape_string($this->normalizeKeywords($data['keywords'] ?? ''));
        $displayOrder = (int) ($data['display_order'] ?? 0);
        [$storageSql, $carbonSql, $alertSql] = $this->profileSql($data);
        $status = in_array($data['status'] ?? '', ['active', 'inactive'], true) ? $data['status'] : 'active';

        if ($categoryName === '') {
            return false;
        }

        $sql = "UPDATE item_categories
                SET category_name = '{$categoryName}',
                    description = '{$description}',
                    keywords = '{$keywords}',
                    default_storage = {$storageSql},
                    carbon_factor = {$carbonSql},
                    expiry_alert_days = {$alertSql},
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
