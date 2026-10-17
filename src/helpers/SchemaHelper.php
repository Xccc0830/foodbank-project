<?php
/**
 * 補齊 users 資料表的新欄位，讓尚未套用遷移的舊資料庫也能登入。
 * 完整的升級仍應執行 database/migrations/ 內的遷移檔（含權限矩陣與新資料表）。
 */
function ensureUserColumns(mysqli $connection) {
    $check = $connection->query("SHOW COLUMNS FROM users LIKE 'blacklist_reason'");
    if (!$check || $check->num_rows > 0) {
        return;
    }

    $columns = [
        'staff_type' => "ALTER TABLE users ADD COLUMN staff_type ENUM('admin','clerk') DEFAULT NULL AFTER member_type",
        'tax_id' => "ALTER TABLE users ADD COLUMN tax_id VARCHAR(8) DEFAULT NULL",
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
