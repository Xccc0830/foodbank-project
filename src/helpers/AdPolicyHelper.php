<?php
/**
 * 廣告刊登規範（自動檢查的部分）。完整條文見 docs/planning/AD_RULES.md，頁面上的「廣告刊登規範」視窗也會顯示同樣內容。
 *
 * - errors：違反法規明文禁止的內容，直接擋下不能送出（食物銀行人員刊登也一樣）。
 * - warnings：可能有問題、需要審核人員判斷的內容，會標示在待審核清單。
 */

class AdPolicyHelper {
    public const TITLE_MAX = 50;
    public const CONTENT_MIN = 10;
    public const CONTENT_MAX = 500;
    public const MAX_DAYS = 90;          // 單次刊登最長天數
    public const DEFAULT_DAYS = 30;      // 沒填結束日時，自動刊登 30 天
    public const MAX_OPEN_PER_ADVERTISER = 3; // 每位刊登者同時「審核中＋刊登中」最多幾則

    /** 規範條文（頁面顯示用），每條：[標題, 說明, 依據] */
    public const RULES = [
        ['內容真實、不誇大', '不得有虛偽、誇大或易生誤解的內容；價格、優惠、贈品須清楚寫明條件與期限。', '公平交易法第 21 條、消費者保護法第 22 條'],
        ['食品不得宣稱醫療效能', '食品、飲料不得宣稱治療、治癒、預防疾病或改善生理機能（例如降血壓、抗癌、消炎）。', '食品安全衛生管理法第 28 條、健康食品管理法第 6 條'],
        ['禁止菸品與電子煙廣告', '不得刊登菸品、電子煙或加熱菸的廣告或促銷。', '菸害防制法第 9 條'],
        ['酒類廣告須加警語', '酒類廣告必須同時標示「禁止酒駕」與「飲酒過量，有害健康」，且不得以未成年人為對象。', '菸酒管理法第 37 條'],
        ['禁止違法或不當內容', '不得涉及賭博、彩金、未經核准的藥品或醫療器材、借貸代辦、多層次傳銷招募、選舉或政治宣傳、色情或歧視內容。', '刑法、藥事法、醫療器材管理法、公職人員選舉罷免法'],
        ['勸募須有許可字號', '以募款、捐款為目的的廣告，須寫出主管機關核准的勸募許可字號。', '公益勸募條例第 7 條'],
        ['尊重個資與著作權', '不得放他人個資（電話、身分證、地址）或未經授權的圖片、商標。', '個人資料保護法、著作權法、商標法'],
        ['標示刊登者', '系統會自動顯示刊登的企業或組織名稱，不可冒用他人名義。', '平台規定'],
        ['格式與檔期', '標題 50 字內、內容 10–500 字；圖片限 JPG／PNG／WebP 且 5MB 以下；連結須為 https 開頭；每次最長刊登 90 天（沒填結束日自動刊登 30 天）；每個帳號同時最多 3 則審核中或刊登中的廣告。', '平台規定'],
        ['審核與下架', '企業廣告須經食物銀行審核才會刊登；違反規範者，食物銀行可隨時下架。', '平台規定'],
    ];

    /** 退回時可直接選的原因 */
    public const REJECT_PRESETS = [
        '內容誇大或易生誤解（公平交易法第 21 條）',
        '食品宣稱醫療效能（食安法第 28 條）',
        '酒類廣告缺少警語（菸酒管理法第 37 條）',
        '涉及禁止刊登的商品或服務',
        '募款廣告缺少勸募許可字號',
        '含他人個資或未授權圖片',
        '與食物銀行公益宗旨不符',
    ];

    private const BLOCK_WORDS = [
        '菸品與電子煙廣告禁止刊登（菸害防制法）' => ['香菸', '菸品', '電子煙', '加熱菸', '雪茄', '菸草'],
        '食品不得宣稱醫療效能（食安法第 28 條）' => ['治療', '治癒', '根治', '療效', '藥效', '抗癌', '防癌', '降血壓', '降血糖', '降血脂', '消炎', '壯陽', '預防疾病'],
        '不得涉及賭博或彩金' => ['博弈', '賭博', '賭場', '彩金', '百家樂', '運彩代買'],
        '不得刊登借貸代辦或傳銷招募' => ['免審核貸款', '借貸代辦', '信用貸款', '下線招募', '多層次傳銷'],
    ];

    private const WARN_WORDS = [
        '含絕對或保證用語，請確認有依據' => ['保證', '100%', '百分之百', '第一品牌', '最便宜', '唯一', '零風險', '永久有效'],
        '含減重、美容相關說法，請確認沒有暗示療效' => ['減肥', '瘦身', '燃脂', '排毒', '美白', '抗老'],
        '含政治或選舉相關內容' => ['候選人', '投票給', '競選', '政黨'],
    ];

    private const ALCOHOL_WORDS = ['啤酒', '紅酒', '白酒', '清酒', '威士忌', '高粱', '調酒', '酒類', '葡萄酒', '烈酒'];
    private const DONATION_WORDS = ['募款', '捐款', '勸募', '愛心捐'];

    /**
     * @return array{errors: string[], warnings: string[]}
     */
    public static function check($title, $content, $linkUrl = '', $startDate = '', $endDate = '') {
        $title = trim((string) $title);
        $content = trim((string) $content);
        $linkUrl = trim((string) $linkUrl);
        $text = $title . "\n" . $content;
        $errors = [];
        $warnings = [];

        if ($title === '' || mb_strlen($title) > self::TITLE_MAX) {
            $errors[] = '標題必填，且在 ' . self::TITLE_MAX . ' 字以內。';
        }
        $length = mb_strlen($content);
        if ($length < self::CONTENT_MIN || $length > self::CONTENT_MAX) {
            $errors[] = '內容需為 ' . self::CONTENT_MIN . '–' . self::CONTENT_MAX . ' 字（目前 ' . $length . ' 字）。';
        }
        if ($linkUrl !== '' && !preg_match('#^https://[^\s<>"]+$#i', $linkUrl)) {
            $errors[] = '連結須為 https:// 開頭的網址。';
        }

        $start = self::date($startDate) ?? date('Y-m-d');
        $end = self::date($endDate);
        if (self::date($startDate) !== null && $start < date('Y-m-d')) {
            $errors[] = '開始刊登日不可早於今天。';
        }
        if ($end !== null) {
            if ($end < $start) {
                $errors[] = '結束日不可早於開始日。';
            } elseif ((strtotime($end) - strtotime($start)) / 86400 + 1 > self::MAX_DAYS) {
                $errors[] = '單次刊登最長 ' . self::MAX_DAYS . ' 天，請縮短檔期。';
            }
        }

        foreach (self::BLOCK_WORDS as $reason => $words) {
            $hits = self::hits($text, $words);
            if ($hits) {
                $errors[] = $reason . '：出現「' . implode('」「', $hits) . '」。';
            }
        }
        if (self::hits($text, self::ALCOHOL_WORDS) && (mb_strpos($text, '禁止酒駕') === false || mb_strpos($text, '飲酒過量，有害健康') === false)) {
            $errors[] = '酒類廣告須同時標示「禁止酒駕」與「飲酒過量，有害健康」（菸酒管理法第 37 條）。';
        }
        if (preg_match('/[A-Z][12]\d{8}/', $text)) {
            $errors[] = '內容疑似含身分證字號，請刪除（個資法）。';
        }

        foreach (self::WARN_WORDS as $reason => $words) {
            $hits = self::hits($text, $words);
            if ($hits) {
                $warnings[] = $reason . '：「' . implode('」「', $hits) . '」';
            }
        }
        if (self::hits($text, self::DONATION_WORDS) && !preg_match('/勸募.{0,6}字第?\s*\d+/u', $text)) {
            $warnings[] = '看起來是募款廣告，但沒有寫勸募許可字號（公益勸募條例）';
        }
        if (preg_match('/09\d{2}[-\s]?\d{3}[-\s]?\d{3}/', $text)) {
            $warnings[] = '內容含手機號碼，請確認是刊登者本人或公司同意公開的號碼（個資法）';
        }
        if ($linkUrl !== '' && preg_match('#^https://(?:bit\.ly|reurl\.cc|tinyurl\.com|t\.co|goo\.gl)/#i', $linkUrl)) {
            $warnings[] = '連結是短網址，請確認實際導向的網站';
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    /** 沒填結束日時的預設結束日 */
    public static function defaultEndDate($startDate) {
        $start = self::date($startDate) ?? date('Y-m-d');
        return date('Y-m-d', strtotime($start . ' +' . (self::DEFAULT_DAYS - 1) . ' days'));
    }

    private static function hits($text, array $words) {
        return array_values(array_filter($words, static function ($word) use ($text) {
            return mb_stripos($text, $word) !== false;
        }));
    }

    private static function date($value) {
        $value = trim((string) $value);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
    }
}
