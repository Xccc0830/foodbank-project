<?php
/**
 * 表單驗證共用工具
 */

/**
 * 驗證台灣統一編號（8 碼數字，含檢查碼）。
 */
function isValidTaiwanTaxId($taxId) {
    if (!is_string($taxId) || !preg_match('/^\d{8}$/', $taxId)) {
        return false;
    }

    $weights = [1, 2, 1, 2, 1, 2, 4, 1];
    $sum = 0;
    foreach ($weights as $index => $weight) {
        $product = (int) $taxId[$index] * $weight;
        $sum += intdiv($product, 10) + $product % 10;
    }

    // 第 7 位為 7 時，進位後的總和 +1 也視為合法。
    return $sum % 5 === 0 || ($taxId[6] === '7' && ($sum + 1) % 5 === 0);
}

/**
 * 企業註冊「從何得知此系統」可選項目。
 */
function registrationReferralSources() {
    return ['朋友或同事介紹', '社群媒體', '學校或師長', '食物銀行或合作單位', '活動現場', '網路搜尋', '其他'];
}
