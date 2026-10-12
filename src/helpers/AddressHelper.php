<?php
/**
 * 地址欄位格式檢核。
 *
 * 回傳 null 代表通過，否則回傳錯誤訊息。
 */
function validateAddress($address, $label) {
    $address = trim((string) $address);
    if ($address === '') {
        return $label . '為必填欄位。';
    }

    $length = function_exists('mb_strlen') ? mb_strlen($address, 'UTF-8') : strlen($address);
    if ($length < 5 || $length > 255) {
        return $label . '長度需介於 5 到 255 個字元。';
    }
    if (!preg_match('/[\p{Han}\p{L}]/u', $address)) {
        return $label . '必須包含文字，請輸入完整地址。';
    }
    if (preg_match('/^[\d\s\p{P}\p{S}]+$/u', $address)) {
        return $label . '格式不正確，請輸入完整地址。';
    }
    if (preg_match('/[<>]/', $address)) {
        return $label . '含有不允許的字元。';
    }

    return null;
}
