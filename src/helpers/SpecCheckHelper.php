<?php
/**
 * 物資規格防呆檢查：分類建議、規格與重量對照、保存期限是否合理。
 * 只回傳「提醒」，不阻擋送出，由商家自行決定採用或維持原樣。
 */

class SpecCheckHelper {
    private const CATEGORY_KEYWORDS = [
        '生鮮' => ['肉', '魚', '蝦', '菜', '蛋', '牛奶', '鮮奶', '豆腐', '水果'],
        '烘焙' => ['麵包', '蛋糕', '吐司', '餅乾', '甜點'],
        '主食乾貨' => ['米', '麵', '油', '糖', '鹽', '麵粉', '泡麵', '冬粉'],
        '飲料' => ['茶', '咖啡', '果汁', '汽水', '飲料', '水'],
        '罐頭' => ['罐頭', '罐'],
    ];

    /** 保存期限偏長 / 偏短的合理上限（天） */
    private const FRESH_MAX_DAYS = 14;
    private const MAX_DAYS = 1095;

    /**
     * @param array $item 可含 item_name, specification, quantity, unit, weight_kg, expiry_date
     * @return array{category:?string, warnings:array<int,array{level:string,field:string,message:string}>}
     */
    public static function check(array $item, $today = null) {
        $warnings = [];
        $name = trim((string) ($item['item_name'] ?? ''));
        $spec = trim((string) ($item['specification'] ?? ''));
        $quantity = is_numeric($item['quantity'] ?? null) ? (float) $item['quantity'] : null;
        $weight = is_numeric($item['weight_kg'] ?? null) ? (float) $item['weight_kg'] : null;
        $expiry = trim((string) ($item['expiry_date'] ?? ''));
        $today = $today ?: date('Y-m-d');

        $category = self::guessCategory($name);
        $storage = (string) ($item['storage_condition'] ?? '');
        if ($category === '生鮮' && $storage === 'room') {
            $warnings[] = self::warn('warn', 'storage_condition', '生鮮食品通常需要冷藏或冷凍，保存方式是否要改？');
        }

        if ($quantity !== null) {
            if ($quantity <= 0) {
                $warnings[] = self::warn('error', 'quantity', '數量必須大於 0。');
            } elseif ($quantity > 1000) {
                $warnings[] = self::warn('warn', 'quantity', '數量超過 1000，請確認是否填錯（例如把重量填進數量）。');
            }
        }

        // 規格寫的重量（例如 5kg、500g、2公斤）× 數量，與填寫的總重量對照
        $specKg = self::parseKg($spec) ?? self::parseKg($name);
        if ($specKg !== null && $weight !== null && $weight > 0 && $quantity !== null && $quantity > 0) {
            $expected = $specKg * $quantity;
            if (abs($expected - $weight) / max($expected, 0.01) > 0.2) {
                $warnings[] = self::warn('warn', 'weight_kg', sprintf('規格約 %s kg × %s ＝ %s kg，與填寫的重量 %s kg 差異較大，請確認。', self::num($specKg), self::num($quantity), self::num($expected), self::num($weight)));
            }
        }
        if ($weight !== null && $quantity !== null && $quantity > 0 && $weight / $quantity > 100) {
            $warnings[] = self::warn('warn', 'weight_kg', '平均每份超過 100 公斤，請確認重量單位是公斤。');
        }

        if ($expiry !== '') {
            $expiryTime = strtotime($expiry);
            if ($expiryTime === false) {
                $warnings[] = self::warn('error', 'expiry_date', '保存期限格式不正確。');
            } else {
                $days = (int) floor(($expiryTime - strtotime($today)) / 86400);
                if ($days < 0) {
                    $warnings[] = self::warn('error', 'expiry_date', '保存期限已過期，無法捐贈。');
                } elseif ($days <= 1) {
                    $warnings[] = self::warn('warn', 'expiry_date', '保存期限剩不到 2 天，可能來不及配送，請確認。');
                } elseif ($days > self::MAX_DAYS) {
                    $warnings[] = self::warn('warn', 'expiry_date', '保存期限超過 3 年，請確認年份是否填對。');
                } elseif (in_array($category, ['生鮮', '烘焙'], true) && $days > self::FRESH_MAX_DAYS) {
                    $warnings[] = self::warn('warn', 'expiry_date', "「{$category}」類通常保存不到 " . self::FRESH_MAX_DAYS . ' 天，請確認期限。');
                }
            }
        }

        return ['category' => $category, 'warnings' => $warnings];
    }

    public static function guessCategory($name) {
        foreach (self::CATEGORY_KEYWORDS as $category => $keywords) {
            foreach ($keywords as $keyword) {
                if ($name !== '' && mb_strpos($name, $keyword) !== false) {
                    return $category;
                }
            }
        }
        return null;
    }

    /** 從文字中找出重量並換算成公斤；找不到回傳 null。 */
    public static function parseKg($text) {
        if (preg_match('/(\d+(?:\.\d+)?)\s*(kg|公斤|KG)/u', (string) $text, $m)) {
            return (float) $m[1];
        }
        if (preg_match('/(\d+(?:\.\d+)?)\s*(g|公克|克)(?![a-zA-Z])/u', (string) $text, $m)) {
            return (float) $m[1] / 1000;
        }
        return null;
    }

    private static function warn($level, $field, $message) {
        return ['level' => $level, 'field' => $field, 'message' => $message];
    }

    private static function num($value) {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
