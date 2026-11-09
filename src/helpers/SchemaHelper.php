<?php
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
