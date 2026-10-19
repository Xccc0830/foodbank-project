<?php
/**
 * 食物銀行入庫紀錄與庫存彙總。
 */

require_once __DIR__ . '/BaseModel.php';

class InventoryReceiptModel extends BaseModel {
    protected $table = 'inventory_receipts';
    private $ready = false;
    private $hasError = false;
    private $lastAvailableQuantity = null;

    public function __construct($connection = null) {
        if ($connection !== null) {
            $this->db = $connection;
        } else {
            parent::__construct();
        }

        $this->ensureTable();
    }

    private function ensureTable() {
        $created = $this->db->query(
            "CREATE TABLE IF NOT EXISTS inventory_receipts (
                receipt_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                item_name VARCHAR(150) NOT NULL,
                category VARCHAR(50) NOT NULL,
                movement_type ENUM('in', 'out') NOT NULL DEFAULT 'in',
                quantity DECIMAL(12,2) NOT NULL,
                unit VARCHAR(30) NOT NULL DEFAULT '件',
                donor_name VARCHAR(150) DEFAULT NULL,
                received_date DATE NOT NULL,
                expiry_date DATE DEFAULT NULL,
                storage_location VARCHAR(150) DEFAULT NULL,
                notes TEXT DEFAULT NULL,
                received_by INT NOT NULL,
                source_donation_id INT DEFAULT NULL,
                source_item_id INT DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (receipt_id),
                KEY idx_inventory_receipts_item (item_name, category, unit),
                KEY idx_inventory_receipts_received_date (received_date),
                KEY idx_inventory_receipts_expiry_date (expiry_date),
                UNIQUE KEY uq_inventory_receipt_source (source_donation_id, source_item_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );

        if (!$created) {
            $this->hasError = true;
            error_log('無法建立物資入庫紀錄資料表：' . $this->db->error);
            return;
        }

        $categoryColumn = $this->db->query("SHOW COLUMNS FROM inventory_receipts LIKE 'category'");
        $categoryDefinition = $categoryColumn ? $categoryColumn->fetch_assoc() : null;
        if ($categoryDefinition && stripos($categoryDefinition['Type'], 'enum(') === 0
            && !$this->db->query("ALTER TABLE inventory_receipts MODIFY category VARCHAR(50) NOT NULL")) {
            $this->hasError = true;
            error_log('無法更新物資入庫分類欄位：' . $this->db->error);
            return;
        }

        $movementColumn = $this->db->query("SHOW COLUMNS FROM inventory_receipts LIKE 'movement_type'");
        if (!$movementColumn || ($movementColumn->num_rows === 0
            && !$this->db->query("ALTER TABLE inventory_receipts ADD COLUMN movement_type ENUM('in', 'out') NOT NULL DEFAULT 'in' AFTER category"))) {
            $this->hasError = true;
            error_log('無法更新物資出入庫欄位：' . $this->db->error);
            return;
        }

        foreach ([
            'source_donation_id' => 'INT DEFAULT NULL',
            'source_item_id' => 'INT DEFAULT NULL',
        ] as $column => $definition) {
            $columnResult = $this->db->query("SHOW COLUMNS FROM inventory_receipts LIKE '{$column}'");
            if (!$columnResult || ($columnResult->num_rows === 0
                && !$this->db->query("ALTER TABLE inventory_receipts ADD COLUMN {$column} {$definition}"))) {
                $this->hasError = true;
                error_log("無法更新物資入庫欄位 {$column}：" . $this->db->error);
                return;
            }
        }

        $sourceIndex = $this->db->query("SHOW INDEX FROM inventory_receipts WHERE Key_name = 'uq_inventory_receipt_source'");
        if (!$sourceIndex || ($sourceIndex->num_rows === 0
            && !$this->db->query("ALTER TABLE inventory_receipts ADD UNIQUE KEY uq_inventory_receipt_source (source_donation_id, source_item_id)"))) {
            $this->hasError = true;
            error_log('無法建立物資入庫來源索引：' . $this->db->error);
            return;
        }
        $this->ready = true;
    }

    public function isReady() {
        return $this->ready;
    }

    public function hasError() {
        return $this->hasError;
    }

    public function getLastAvailableQuantity() {
        return $this->lastAvailableQuantity;
    }

    public function addReceipt(array $data, $userId) {
        $data['movement_type'] = 'in';
        return $this->recordManualMovement($data, $userId)['status'] === 'success';
    }

    public function recordManualMovement(array $data, $userId) {
        if (!$this->ready) {
            return ['status' => 'error'];
        }

        $movementType = (string) ($data['movement_type'] ?? 'in');
        $itemName = trim((string) ($data['item_name'] ?? ''));
        $category = trim((string) ($data['category'] ?? ''));
        $quantity = filter_var($data['quantity'] ?? null, FILTER_VALIDATE_FLOAT);
        $unit = trim((string) ($data['unit'] ?? ''));
        $receivedDate = (string) ($data['received_date'] ?? '');
        $expiryDate = trim((string) ($data['expiry_date'] ?? ''));
        $donorName = trim((string) ($data['donor_name'] ?? ''));
        $storageLocation = trim((string) ($data['storage_location'] ?? ''));
        $notes = trim((string) ($data['notes'] ?? ''));
        $userId = (int) $userId;
        if (!in_array($movementType, ['in', 'out'], true)
            || $itemName === '' || strlen($itemName) > 450
            || $category === '' || strlen($category) > 150
            || $quantity === false || $quantity <= 0
            || $unit === '' || strlen($unit) > 90
            || !$this->isValidDate($receivedDate)
            || ($expiryDate !== '' && !$this->isValidDate($expiryDate))
            || ($movementType === 'in' && $expiryDate !== '' && $expiryDate < $receivedDate)
            || ($movementType === 'out' && $expiryDate !== '')
            || $userId <= 0 || ($movementType === 'in' && !$this->categoryExists($category))) {
            return ['status' => 'invalid'];
        }

        $lockName = $this->stockLockName($itemName, $category, $unit);
        if (!$this->acquireStockLock($lockName)) {
            return ['status' => 'error'];
        }

        if (!$this->db->begin_transaction()) {
            $this->hasError = true;
            error_log('無法開始物資異動交易：' . $this->db->error);
            $this->releaseStockLocks([$lockName]);
            return ['status' => 'error'];
        }

        $statement = null;
        try {
            $stockStatement = $this->db->prepare(
                "SELECT movement_type, quantity FROM inventory_receipts
                 WHERE item_name = ? AND category = ? AND unit = ? FOR UPDATE"
            );
            if (!$stockStatement) {
                throw new RuntimeException('無法查詢目前庫存：' . $this->db->error);
            }
            $stockStatement->bind_param('sss', $itemName, $category, $unit);
            if (!$stockStatement->execute()) {
                throw new RuntimeException('無法查詢目前庫存：' . $stockStatement->error);
            }
            $stockResult = $stockStatement->get_result();
            $available = 0.0;
            while ($stockResult && ($stockRow = $stockResult->fetch_assoc())) {
                $available += $stockRow['movement_type'] === 'out'
                    ? -(float) $stockRow['quantity']
                    : (float) $stockRow['quantity'];
            }
            $stockStatement->close();
            $this->lastAvailableQuantity = max(0, $available);

            if ($movementType === 'out' && (float) $quantity > $available) {
                $this->db->rollback();
                return ['status' => 'insufficient_stock', 'available' => max(0, $available)];
            }

            $statement = $this->db->prepare(
                "INSERT INTO inventory_receipts
                    (item_name, category, movement_type, quantity, unit, donor_name, received_date, expiry_date,
                     storage_location, notes, received_by)
                 VALUES (?, ?, ?, ?, ?, NULLIF(?, ''), ?, NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''), ?)"
            );
            if (!$statement) {
                throw new RuntimeException('無法準備物資異動紀錄：' . $this->db->error);
            }
            $movementNotes = $movementType === 'out' && $notes === '' ? '物資取出' : $notes;
            $statement->bind_param(
                'sssdssssssi',
                $itemName,
                $category,
                $movementType,
                $quantity,
                $unit,
                $donorName,
                $receivedDate,
                $expiryDate,
                $storageLocation,
                $movementNotes,
                $userId
            );
            if (!$statement->execute()) {
                throw new RuntimeException('無法儲存物資異動紀錄：' . $statement->error);
            }
            $statement->close();
            $statement = null;
            if (!$this->db->commit()) {
                throw new RuntimeException('無法完成物資異動交易：' . $this->db->error);
            }
            return ['status' => 'success', 'available' => $available];
        } catch (Throwable $error) {
            if ($statement) {
                $statement->close();
            }
            $this->db->rollback();
            $this->hasError = true;
            error_log('物資異動失敗：' . $error->getMessage());
            return ['status' => 'error'];
        } finally {
            $this->releaseStockLocks([$lockName]);
        }
    }

    public function recordDonationInspection($donationId, $userId, array $categoryIds) {
        if (!$this->ready) {
            return false;
        }

        $donationId = (int) $donationId;
        $userId = (int) $userId;
        if ($donationId <= 0 || $userId <= 0) {
            return false;
        }
        if (!$this->db->begin_transaction()) {
            $this->hasError = true;
            error_log('無法開始物資清點入庫交易：' . $this->db->error);
            return false;
        }

        $success = false;
        try {
            $donationStatement = $this->db->prepare(
                "SELECT donation_id, order_number, donor_name, donation_date, status, current_status
                 FROM donations WHERE donation_id = ? FOR UPDATE"
            );
            if (!$donationStatement) {
                throw new RuntimeException('無法查詢捐贈單：' . $this->db->error);
            }
            $donationStatement->bind_param('i', $donationId);
            if (!$donationStatement->execute()) {
                throw new RuntimeException('無法查詢捐贈單：' . $donationStatement->error);
            }
            $donationResult = $donationStatement->get_result();
            $donation = $donationResult ? $donationResult->fetch_assoc() : null;
            $donationStatement->close();
            if (!$donation || $donation['status'] === 'rejected' || $donation['current_status'] === 'inspection_complete') {
                $this->db->rollback();
                return false;
            }

            $deliveryStatement = $this->db->prepare(
                "SELECT COUNT(*) AS delivery_count,
                        SUM(status = 'delivered') AS delivered_count,
                        SUM(status NOT IN ('delivered', 'cancelled')) AS unfinished_count
                 FROM deliveries WHERE donation_id = ?"
            );
            if (!$deliveryStatement) {
                throw new RuntimeException('無法確認配送狀態：' . $this->db->error);
            }
            $deliveryStatement->bind_param('i', $donationId);
            if (!$deliveryStatement->execute()) {
                throw new RuntimeException('無法確認配送狀態：' . $deliveryStatement->error);
            }
            $deliveryResult = $deliveryStatement->get_result();
            $deliveryStatus = $deliveryResult ? $deliveryResult->fetch_assoc() : null;
            $deliveryStatement->close();
            if (!$deliveryStatus || (int) $deliveryStatus['delivery_count'] === 0
                || (int) $deliveryStatus['delivered_count'] === 0
                || (int) $deliveryStatus['unfinished_count'] > 0) {
                $this->db->rollback();
                return false;
            }

            $itemStatement = $this->db->prepare(
                "SELECT item_id, item_name, quantity, unit, expiry_date
                 FROM donation_items WHERE donation_id = ? ORDER BY item_id"
            );
            if (!$itemStatement) {
                throw new RuntimeException('無法查詢捐贈物資明細：' . $this->db->error);
            }
            $itemStatement->bind_param('i', $donationId);
            if (!$itemStatement->execute()) {
                throw new RuntimeException('無法查詢捐贈物資明細：' . $itemStatement->error);
            }
            $itemResult = $itemStatement->get_result();
            $items = $itemResult ? $itemResult->fetch_all(MYSQLI_ASSOC) : [];
            $itemStatement->close();
            if (!$items) {
                $this->db->rollback();
                return false;
            }

            $categories = [];
            $categoryStatement = $this->db->prepare(
                "SELECT category_name FROM item_categories WHERE category_id = ? AND status = 'active' LIMIT 1"
            );
            if (!$categoryStatement) {
                throw new RuntimeException('無法查詢物資分類：' . $this->db->error);
            }
            foreach ($items as $item) {
                $itemId = (int) $item['item_id'];
                $categoryId = (int) ($categoryIds[$itemId] ?? 0);
                if ($categoryId <= 0) {
                    $categoryStatement->close();
                    $this->db->rollback();
                    return false;
                }
                $categoryStatement->bind_param('i', $categoryId);
                if (!$categoryStatement->execute()) {
                    throw new RuntimeException('無法查詢物資分類：' . $categoryStatement->error);
                }
                $categoryResult = $categoryStatement->get_result();
                $category = $categoryResult ? $categoryResult->fetch_assoc() : null;
                if (!$category) {
                    $categoryStatement->close();
                    $this->db->rollback();
                    return false;
                }
                $categories[$itemId] = $category['category_name'];
            }
            $categoryStatement->close();

            $stockLockNames = [];
            foreach ($items as $item) {
                $itemId = (int) $item['item_id'];
                $stockLockNames[] = $this->stockLockName(
                    (string) $item['item_name'],
                    $categories[$itemId],
                    (string) ($item['unit'] ?: '件')
                );
            }
            $stockLockNames = array_values(array_unique($stockLockNames));
            sort($stockLockNames, SORT_STRING);
            foreach ($stockLockNames as $stockLockName) {
                if (!$this->acquireStockLock($stockLockName)) {
                    throw new RuntimeException('無法鎖定物資庫存，請稍後重試。');
                }
            }

            $insertStatement = $this->db->prepare(
                "INSERT INTO inventory_receipts
                    (item_name, category, movement_type, quantity, unit, donor_name, received_date, expiry_date, notes,
                     received_by, source_donation_id, source_item_id)
                 VALUES (?, ?, 'in', ?, ?, NULLIF(?, ''), CURDATE(), NULLIF(?, ''), ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE receipt_id = receipt_id"
            );
            if (!$insertStatement) {
                throw new RuntimeException('無法準備入庫紀錄：' . $this->db->error);
            }
            $orderNumber = trim((string) ($donation['order_number'] ?? ''));
            $notes = '完成清點自動入庫' . ($orderNumber !== '' ? '（訂單 ' . $orderNumber . '）' : '');
            foreach ($items as $item) {
                $itemId = (int) $item['item_id'];
                $itemName = (string) $item['item_name'];
                $category = $categories[$itemId];
                $quantity = (float) $item['quantity'];
                $unit = (string) ($item['unit'] ?: '件');
                $donorName = (string) ($donation['donor_name'] ?? '');
                $expiryDate = (string) ($item['expiry_date'] ?? '');
                $sourceItemId = $itemId;
                $insertStatement->bind_param(
                    'ssdssssiii',
                    $itemName,
                    $category,
                    $quantity,
                    $unit,
                    $donorName,
                    $expiryDate,
                    $notes,
                    $userId,
                    $donationId,
                    $sourceItemId
                );
                if (!$insertStatement->execute()) {
                    throw new RuntimeException('無法寫入入庫紀錄：' . $insertStatement->error);
                }
            }
            $insertStatement->close();

            $updateStatement = $this->db->prepare(
                "UPDATE donations SET current_status = 'inspection_complete', status_updated_at = NOW()
                 WHERE donation_id = ? AND status <> 'rejected' AND current_status <> 'inspection_complete'"
            );
            if (!$updateStatement) {
                throw new RuntimeException('無法更新捐贈清點狀態：' . $this->db->error);
            }
            $updateStatement->bind_param('i', $donationId);
            if (!$updateStatement->execute() || $updateStatement->affected_rows !== 1) {
                throw new RuntimeException('無法更新捐贈清點狀態：' . $updateStatement->error);
            }
            $updateStatement->close();

            if (!$this->db->commit()) {
                throw new RuntimeException('無法完成物資清點入庫交易：' . $this->db->error);
            }
            $success = true;
        } catch (Throwable $error) {
            $this->db->rollback();
            $this->hasError = true;
            error_log('物資清點入庫失敗：' . $error->getMessage());
        } finally {
            if (!empty($stockLockNames)) {
                $this->releaseStockLocks($stockLockNames);
            }
        }

        return $success;
    }

    public function getStockSummary($keyword = '') {
        if (!$this->ready) {
            return [];
        }

        $keyword = trim((string) $keyword);
        $like = '%' . $keyword . '%';
        $statement = $this->db->prepare(
            "SELECT item_name, category, unit,
                    SUM(CASE WHEN movement_type = 'out' THEN -quantity ELSE quantity END) AS total_quantity,
                    SUM(movement_type = 'in') AS receipt_count, MAX(received_date) AS latest_received_date,
                    MIN(expiry_date) AS next_expiry_date
             FROM inventory_receipts
             WHERE (? = '' OR item_name LIKE ? OR donor_name LIKE ? OR storage_location LIKE ?)
             GROUP BY item_name, category, unit
             HAVING total_quantity > 0
             ORDER BY latest_received_date DESC, item_name ASC"
        );
        if (!$statement) {
            $this->hasError = true;
            error_log('無法查詢物資庫存彙總：' . $this->db->error);
            return [];
        }

        $statement->bind_param('ssss', $keyword, $like, $like, $like);
        if (!$statement->execute()) {
            $this->hasError = true;
            error_log('無法查詢物資庫存彙總：' . $statement->error);
            $statement->close();
            return [];
        }

        $result = $statement->get_result();
        $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $statement->close();
        return $rows;
    }

    public function getRecentReceipts($keyword = '', $limit = 100) {
        if (!$this->ready) {
            return [];
        }

        $keyword = trim((string) $keyword);
        $like = '%' . $keyword . '%';
        $limit = max(1, min(500, (int) $limit));
        $statement = $this->db->prepare(
            "SELECT receipt_id, item_name, category, movement_type, quantity, unit, donor_name,
                    received_date, expiry_date, storage_location, notes
             FROM inventory_receipts
             WHERE (? = '' OR item_name LIKE ? OR donor_name LIKE ? OR storage_location LIKE ?)
             ORDER BY received_date DESC, receipt_id DESC
             LIMIT {$limit}"
        );
        if (!$statement) {
            $this->hasError = true;
            error_log('無法查詢物資入庫紀錄：' . $this->db->error);
            return [];
        }

        $statement->bind_param('ssss', $keyword, $like, $like, $like);
        if (!$statement->execute()) {
            $this->hasError = true;
            error_log('無法查詢物資入庫紀錄：' . $statement->error);
            $statement->close();
            return [];
        }

        $result = $statement->get_result();
        $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $statement->close();
        return $rows;
    }

    public function getSummaryCounts() {
        if (!$this->ready) {
            return ['receipt_count' => 0, 'item_count' => 0, 'today_count' => 0];
        }

        $result = $this->db->query(
            "SELECT SUM(movement_type = 'in') AS receipt_count,
                    (SELECT COUNT(*) FROM (
                        SELECT item_name, category, unit
                        FROM inventory_receipts
                        GROUP BY item_name, category, unit
                        HAVING SUM(CASE WHEN movement_type = 'out' THEN -quantity ELSE quantity END) > 0
                    ) AS current_stock) AS item_count,
                    SUM(received_date = CURDATE() AND movement_type = 'in') AS today_count
             FROM inventory_receipts"
        );
        if (!$result) {
            $this->hasError = true;
            error_log('無法查詢物資入庫統計：' . $this->db->error);
            return ['receipt_count' => 0, 'item_count' => 0, 'today_count' => 0];
        }

        $row = $result->fetch_assoc();
        return [
            'receipt_count' => (int) ($row['receipt_count'] ?? 0),
            'item_count' => (int) ($row['item_count'] ?? 0),
            'today_count' => (int) ($row['today_count'] ?? 0),
        ];
    }

    private function isValidDate($value) {
        $date = DateTime::createFromFormat('!Y-m-d', $value);
        return $date && $date->format('Y-m-d') === $value;
    }

    private function categoryExists($categoryName) {
        $statement = $this->db->prepare(
            "SELECT category_id FROM item_categories WHERE category_name = ? AND status = 'active' LIMIT 1"
        );
        if (!$statement) {
            $this->hasError = true;
            error_log('無法驗證物資分類：' . $this->db->error);
            return false;
        }
        $statement->bind_param('s', $categoryName);
        if (!$statement->execute()) {
            $this->hasError = true;
            error_log('無法驗證物資分類：' . $statement->error);
            $statement->close();
            return false;
        }
        $result = $statement->get_result();
        $found = $result && $result->num_rows === 1;
        $statement->close();
        return $found;
    }

    private function stockLockName($itemName, $category, $unit) {
        $key = json_encode([(string) $itemName, (string) $category, (string) $unit], JSON_UNESCAPED_UNICODE);
        return 'inventory_' . substr(hash('sha256', $key), 0, 48);
    }

    private function acquireStockLock($lockName) {
        $statement = $this->db->prepare('SELECT GET_LOCK(?, 5) AS lock_acquired');
        if (!$statement) {
            $this->hasError = true;
            error_log('無法準備物資庫存鎖定：' . $this->db->error);
            return false;
        }
        $statement->bind_param('s', $lockName);
        if (!$statement->execute()) {
            $this->hasError = true;
            error_log('無法鎖定物資庫存：' . $statement->error);
            $statement->close();
            return false;
        }
        $result = $statement->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $statement->close();
        if ((int) ($row['lock_acquired'] ?? 0) !== 1) {
            $this->hasError = true;
            error_log('無法鎖定物資庫存：等待庫存鎖定逾時。');
            return false;
        }
        return true;
    }

    private function releaseStockLocks(array $lockNames) {
        foreach ($lockNames as $lockName) {
            $statement = $this->db->prepare('SELECT RELEASE_LOCK(?)');
            if (!$statement) {
                $this->hasError = true;
                error_log('無法準備釋放物資庫存鎖：' . $this->db->error);
                continue;
            }
            $statement->bind_param('s', $lockName);
            if (!$statement->execute()) {
                $this->hasError = true;
                error_log('無法釋放物資庫存鎖：' . $statement->error);
            }
            $statement->close();
        }
    }
}
