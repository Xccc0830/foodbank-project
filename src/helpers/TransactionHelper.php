<?php
/**
 * 可以巢狀使用的資料庫交易。
 *
 * MySQL 在交易中再開一次交易，會把前一個交易直接存檔。
 * 例如測試想「跑完全部復原」而先開了交易，裡面呼叫的功能（兌換、確認收貨…）又自己開交易，
 * 測試資料就會被提前存進資料庫、復原不了。
 *
 * 這裡記錄每條資料庫連線目前開了幾層交易：
 * 最外層才真的開始／存檔／復原；裡面的層改用「儲存點」（SAVEPOINT），
 * 只存檔或復原自己這一層，不影響外層。
 */
class Transaction {
    /** @var array<int,int> 每條連線目前的交易層數 */
    private static $depth = [];

    /** 目前是否有任何連線在交易中。 */
    public static function active() {
        return array_sum(self::$depth) > 0;
    }

    public static function begin(mysqli $db) {
        $key = spl_object_id($db);
        $level = self::$depth[$key] ?? 0;
        $ok = $level === 0 ? $db->begin_transaction() : $db->query('SAVEPOINT tx_level_' . $level);
        if ($ok) {
            self::$depth[$key] = $level + 1;
        }
        return (bool) $ok;
    }

    public static function commit(mysqli $db) {
        $key = spl_object_id($db);
        $level = self::$depth[$key] ?? 0;
        if ($level <= 1) {
            $ok = $db->commit();
            if ($ok) {
                self::$depth[$key] = 0;
            }
            return (bool) $ok;
        }
        $ok = $db->query('RELEASE SAVEPOINT tx_level_' . ($level - 1));
        if ($ok) {
            self::$depth[$key] = $level - 1;
        }
        return (bool) $ok;
    }

    public static function rollback(mysqli $db) {
        $key = spl_object_id($db);
        $level = self::$depth[$key] ?? 0;
        if ($level <= 1) {
            self::$depth[$key] = 0;
            return (bool) $db->rollback();
        }
        $savepoint = 'tx_level_' . ($level - 1);
        $ok = $db->query('ROLLBACK TO SAVEPOINT ' . $savepoint);
        $db->query('RELEASE SAVEPOINT ' . $savepoint);
        self::$depth[$key] = $level - 1;
        return (bool) $ok;
    }
}
