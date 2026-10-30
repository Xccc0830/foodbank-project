<?php
/**
 * OTP 產生與驗證工具（開發環境：驗證碼直接顯示於頁面，尚未串接簡訊服務商）
 */

function generateOtpForUser(mysqli $connection, int $userId, string $phone, string $purpose): string {
    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $codeHash = hash('sha256', $code);
    $phoneEscaped = $connection->real_escape_string($phone);
    $purposeEscaped = $connection->real_escape_string($purpose);
    $expiresAt = date('Y-m-d H:i:s', time() + 600);

    $connection->query("UPDATE otp_codes SET consumed_at = NOW() WHERE user_id = {$userId} AND purpose = '{$purposeEscaped}' AND consumed_at IS NULL");
    $connection->query("INSERT INTO otp_codes (user_id, phone, purpose, code_hash, expires_at) VALUES ({$userId}, '{$phoneEscaped}', '{$purposeEscaped}', '{$codeHash}', '{$expiresAt}')");

    return $code;
}

function verifyOtpForUser(mysqli $connection, int $userId, string $purpose, string $code): bool {
    $purposeEscaped = $connection->real_escape_string($purpose);
    $result = $connection->query("SELECT otp_id, code_hash, attempts, expires_at FROM otp_codes WHERE user_id = {$userId} AND purpose = '{$purposeEscaped}' AND consumed_at IS NULL ORDER BY otp_id DESC LIMIT 1");
    $otp = $result ? $result->fetch_assoc() : null;

    if (!$otp || $otp['attempts'] >= 5 || strtotime($otp['expires_at']) < time()) {
        return false;
    }

    $otpId = (int) $otp['otp_id'];
    $connection->query("UPDATE otp_codes SET attempts = attempts + 1 WHERE otp_id = {$otpId}");

    if (!hash_equals($otp['code_hash'], hash('sha256', $code))) {
        return false;
    }

    $connection->query("UPDATE otp_codes SET consumed_at = NOW() WHERE otp_id = {$otpId}");
    return true;
}
