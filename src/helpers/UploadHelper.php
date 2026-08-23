<?php
/**
 * 檔案上傳共用工具
 */

/**
 * 驗證並儲存捐贈物資照片，回傳相對於 public/ 的路徑，失敗回傳 false
 */
function uploadDonationPhoto(array $file) {
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        return false;
    }

    $maxBytes = 5 * 1024 * 1024;
    if ($file['size'] > $maxBytes) {
        return false;
    }

    $allowedMimeToExt = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!isset($allowedMimeToExt[$mimeType])) {
        return false;
    }

    $extension = $allowedMimeToExt[$mimeType];
    $filename = 'donation_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $extension;
    $destinationDir = BASE_PATH . '/public/uploads/donations';
    $destinationPath = $destinationDir . '/' . $filename;

    if (!is_dir($destinationDir)) {
        mkdir($destinationDir, 0755, true);
    }

    if (!move_uploaded_file($file['tmp_name'], $destinationPath)) {
        return false;
    }

    return 'uploads/donations/' . $filename;
}
