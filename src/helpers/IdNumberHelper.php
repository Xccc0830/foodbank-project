<?php
/**
 * 身分證 / 居留證字號：格式檢查碼驗證、加密保存、去重雜湊、遮罩顯示。
 * 金鑰優先讀環境變數 ID_ENCRYPTION_KEY（base64，32 bytes）；沒有時使用 storage/keys/id.key
 * （不存在就自動產生，權限 600；storage/ 已被 .htaccess 擋掉，不可由網址讀取）。
 */

class IdNumberHelper {
    private const LETTER_CODES = [
        'A' => 10, 'B' => 11, 'C' => 12, 'D' => 13, 'E' => 14, 'F' => 15, 'G' => 16, 'H' => 17,
        'I' => 34, 'J' => 18, 'K' => 19, 'L' => 20, 'M' => 21, 'N' => 22, 'O' => 35, 'P' => 23,
        'Q' => 24, 'R' => 25, 'S' => 26, 'T' => 27, 'U' => 28, 'V' => 29, 'W' => 32, 'X' => 30,
        'Y' => 31, 'Z' => 33,
    ];

    public static function normalize($value) {
        return strtoupper(preg_replace('/\s+/', '', (string) $value));
    }

    /**
     * 本國身分證（1 碼英文 + 1/2 + 8 碼數字）與居留證（舊式：英文+英文+8 碼；新式：英文+8/9+8 碼）皆通過檢查碼才算有效。
     */
    public static function isValid($value) {
        $id = self::normalize($value);
        if (preg_match('/^[A-Z][12][0-9]{8}$/', $id)) {
            $digits = self::letterDigits($id[0]) . substr($id, 1);
        } elseif (preg_match('/^[A-Z][A-D][0-9]{8}$/', $id)) {
            // 舊式居留證：第二碼英文取其代碼個位數
            $digits = self::letterDigits($id[0]) . (self::LETTER_CODES[$id[1]] % 10) . substr($id, 2);
        } elseif (preg_match('/^[A-Z][89][0-9]{8}$/', $id)) {
            $digits = self::letterDigits($id[0]) . substr($id, 1);
        } else {
            return false;
        }

        $weights = [1, 9, 8, 7, 6, 5, 4, 3, 2, 1, 1];
        $sum = 0;
        foreach (str_split($digits) as $i => $digit) {
            $sum += (int) $digit * $weights[$i];
        }
        return $sum % 10 === 0;
    }

    private static function letterDigits($letter) {
        return (string) self::LETTER_CODES[$letter];
    }

    /** 例：A123456789 → A12****789 */
    public static function mask($value) {
        $id = self::normalize($value);
        return strlen($id) === 10 ? substr($id, 0, 3) . '****' . substr($id, 7) : '';
    }

    public static function encrypt($plain) {
        $iv = random_bytes(12);
        $cipher = openssl_encrypt(self::normalize($plain), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        return base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt($stored) {
        $raw = base64_decode((string) $stored, true);
        if ($raw === false || strlen($raw) < 29) {
            return null;
        }
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        return $plain === false ? null : $plain;
    }

    /** 去重用雜湊（HMAC，無金鑰無法反推）。 */
    public static function lookupHash($value) {
        return hash_hmac('sha256', self::normalize($value), self::key());
    }

    private static function key() {
        static $key = null;
        if ($key !== null) {
            return $key;
        }
        $env = getenv('ID_ENCRYPTION_KEY');
        if ($env !== false && $env !== '') {
            $decoded = base64_decode($env, true);
            if ($decoded === false || strlen($decoded) !== 32) {
                throw new RuntimeException('ID_ENCRYPTION_KEY 必須是 32 bytes 的 base64 字串。');
            }
            return $key = $decoded;
        }

        $dir = dirname(__DIR__, 2) . '/storage/keys';
        $file = $dir . '/id.key';
        if (!is_file($file)) {
            if (!is_dir($dir) && !mkdir($dir, 0700, true)) {
                throw new RuntimeException('無法建立金鑰資料夾。');
            }
            file_put_contents($file, base64_encode(random_bytes(32)), LOCK_EX);
            chmod($file, 0600);
        }
        $decoded = base64_decode(trim((string) file_get_contents($file)), true);
        if ($decoded === false || strlen($decoded) !== 32) {
            throw new RuntimeException('身分證加密金鑰檔案格式錯誤。');
        }
        return $key = $decoded;
    }
}
