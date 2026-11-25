<?php
require_once __DIR__ . '/TransactionHelper.php';

/**
 * 補齊 users 資料表的新欄位，讓舊資料庫在不匯入破壞性 schema.sql 的情況下仍可登入。
 * 此相容處理不會完成資料庫升級；需要新功能的資料庫應使用全新安裝。
 */
function ensureUserColumns(mysqli $connection) {
    $check = $connection->query("SHOW COLUMNS FROM users LIKE 'blacklist_reason'");
    if (!$check || $check->num_rows > 0) {
        return;
    }

    $columns = [
        'staff_type' => "ALTER TABLE users ADD COLUMN staff_type ENUM('admin','clerk') DEFAULT NULL AFTER member_type",
        'tax_id' => "ALTER TABLE users ADD COLUMN tax_id VARCHAR(8) DEFAULT NULL",
        'default_pickup_address' => "ALTER TABLE users ADD COLUMN default_pickup_address VARCHAR(255) DEFAULT NULL",
        'referral_source' => "ALTER TABLE users ADD COLUMN referral_source VARCHAR(50) DEFAULT NULL",
        'blacklisted_at' => "ALTER TABLE users ADD COLUMN blacklisted_at DATETIME DEFAULT NULL",
        'blacklist_reason' => "ALTER TABLE users ADD COLUMN blacklist_reason VARCHAR(255) DEFAULT NULL",
    ];
    foreach ($columns as $column => $alterSql) {
        $exists = $connection->query("SHOW COLUMNS FROM users LIKE '{$column}'");
        if ($exists && $exists->num_rows === 0) {
            $connection->query($alterSql);
        }
    }
}

/** 企業會員可進入「設置」補填商家地址；舊資料庫沒有這筆預設權限時補上。 */
function ensureEnterpriseSettingsPermission(mysqli $connection) {
    $connection->query("INSERT IGNORE INTO role_permission_defaults (role, member_type, permission_key) VALUES ('member', 'enterprise', 'page.settings')");
}

/** 商家資料的新欄位：營業日（勾選）、營業時間、公休日（自由填寫）、取貨備註（給配送人員看）。 */
function ensureBusinessProfileColumns(mysqli $connection) {
    $columns = [
        'open_days' => 'VARCHAR(20) DEFAULT NULL',
        'business_hours' => 'VARCHAR(100) DEFAULT NULL',
        'closed_days' => 'VARCHAR(100) DEFAULT NULL',
        'pickup_note' => 'VARCHAR(255) DEFAULT NULL',
    ];
    foreach ($columns as $column => $definition) {
        $exists = $connection->query("SHOW COLUMNS FROM users LIKE '{$column}'");
        if ($exists && $exists->num_rows === 0) {
            $connection->query("ALTER TABLE users ADD COLUMN {$column} {$definition}");
        } elseif ($exists && $column === 'closed_days') {
            $connection->query("ALTER TABLE users MODIFY closed_days VARCHAR(100) DEFAULT NULL");
        }
    }
}

/** 登入失敗次數與鎖定時間：同一帳號連續輸錯 5 次鎖定 10 分鐘。 */
function ensureLoginLockColumns(mysqli $connection) {
    $columns = [
        'failed_login_count' => 'INT NOT NULL DEFAULT 0',
        'login_locked_until' => 'DATETIME DEFAULT NULL',
    ];
    foreach ($columns as $column => $definition) {
        $exists = $connection->query("SHOW COLUMNS FROM users LIKE '{$column}'");
        if ($exists && $exists->num_rows === 0) {
            $connection->query("ALTER TABLE users ADD COLUMN {$column} {$definition}");
        }
    }
}

/*
 * 資料表檢查只在「程式有更新」時跑一次：
 * 以 models、helpers、index.php、schema.sql 的修改時間算出指紋，存在資料庫的 app_meta 表；
 * 指紋相同就略過檢查（每次開頁面只多一個很快的查詢）。換了資料庫時，新資料庫沒有紀錄，會自動重新檢查。
 * 想強制重新檢查：DELETE FROM app_meta WHERE meta_key = 'schema_fingerprint';
 */
function schemaFingerprint() {
    static $fingerprint = null;
    if ($fingerprint === null) {
        $root = dirname(__DIR__, 2);
        $files = array_merge(
            glob($root . '/src/models/*.php') ?: [],
            glob($root . '/src/helpers/*.php') ?: [],
            [$root . '/public/index.php', $root . '/database/schema.sql']
        );
        $parts = [];
        foreach ($files as $file) {
            $parts[] = basename($file) . ':' . (int) @filemtime($file);
        }
        $fingerprint = md5(implode('|', $parts));
    }
    return $fingerprint;
}

function schemaChecksNeeded() {
    static $needed = null;
    // 補欄位、建表會讓 MySQL 自動把進行中的交易存檔，所以交易進行中一律不檢查
    if (Transaction::active()) {
        return false;
    }
    if ($needed === null) {
        $needed = true;
        $connection = isset($GLOBALS['db']) ? $GLOBALS['db']->getConnection() : null;
        if ($connection) {
            $result = $connection->query("SELECT meta_value FROM app_meta WHERE meta_key = 'schema_fingerprint' LIMIT 1");
            $row = $result ? $result->fetch_assoc() : null;
            $needed = !$row || $row['meta_value'] !== schemaFingerprint();
        }
    }
    return $needed;
}

/** 一次跑完所有資料表檢查（建立每個資料模型時會自動補欄位），全部完成後記下指紋。 */
function runAllSchemaChecks(mysqli $connection) {
    if (!schemaChecksNeeded()) {
        return;
    }
    ensureUserColumns($connection);
    ensureEnterpriseSettingsPermission($connection);
    ensureBusinessProfileColumns($connection);
    ensureLoginLockColumns($connection);

    $modelDir = dirname(__DIR__) . '/models';
    foreach (['ActivityModel', 'AdvertisementModel', 'DeliveryModel', 'DonationModel', 'FoodbankProfileModel', 'IdVerificationModel',
              'InventoryReceiptModel', 'ItemCategoryModel', 'NotificationModel', 'RewardModel', 'ThanksModel'] as $modelClass) {
        require_once $modelDir . '/' . $modelClass . '.php';
        new $modelClass();
    }

    if (!$connection->query(
        "CREATE TABLE IF NOT EXISTS partner_esg_reviews (
            review_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            donor_id INT NOT NULL,
            quarter CHAR(7) NOT NULL,
            status ENUM('certified','not_certified') NOT NULL,
            review_note TEXT NOT NULL,
            reviewed_by INT NOT NULL,
            reviewed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_partner_esg_quarter (donor_id, quarter),
            KEY idx_partner_esg_quarter (quarter)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    )) {
        throw new RuntimeException('無法建立季度商家 ESG 評估資料表: ' . $connection->error);
    }

    $connection->query(
        "CREATE TABLE IF NOT EXISTS app_meta (
            meta_key VARCHAR(50) NOT NULL PRIMARY KEY,
            meta_value VARCHAR(255) NOT NULL,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
    $fingerprint = $connection->real_escape_string(schemaFingerprint());
    $connection->query("REPLACE INTO app_meta (meta_key, meta_value) VALUES ('schema_fingerprint', '{$fingerprint}')");
}
