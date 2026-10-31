<?php
/**
 * 補齊 users 資料表的新欄位，讓舊資料庫在不匯入破壞性 schema.sql 的情況下仍可登入。
 * 此相容處理不會完成資料庫升級；需要新功能的資料庫應使用全新安裝。
 */
function ensureUserColumns($connection) {
    $columns = [
        'staff_type' => "ALTER TABLE users ADD COLUMN staff_type ENUM('admin','clerk') DEFAULT NULL AFTER member_type",
        'tax_id' => "ALTER TABLE users ADD COLUMN tax_id VARCHAR(8) DEFAULT NULL",
        'referral_source' => "ALTER TABLE users ADD COLUMN referral_source VARCHAR(50) DEFAULT NULL",
        'blacklisted_at' => "ALTER TABLE users ADD COLUMN blacklisted_at DATETIME DEFAULT NULL",
        'blacklist_reason' => "ALTER TABLE users ADD COLUMN blacklist_reason VARCHAR(255) DEFAULT NULL",
        'business_address' => "ALTER TABLE users ADD COLUMN business_address VARCHAR(255) DEFAULT NULL",
    ];
    foreach ($columns as $column => $alterSql) {
        $exists = $connection->query("SHOW COLUMNS FROM users LIKE '{$column}'");
        if ($exists && $exists->num_rows === 0) {
            $connection->query($alterSql);
        }
    }
}
