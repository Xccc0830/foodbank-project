<?php
/**
 * 檔案上傳共用工具
 */

/**
 * 驗證並儲存捐贈物資照片，回傳相對於 public/ 的路徑，失敗回傳 false
 */
function uploadDonationPhoto(array $file) {
    return saveUploadedImage($file, 'uploads/donations', 'donation', BASE_PATH . '/public');
}

/**
 * 驗證並儲存 GO Rider 取貨照片，回傳相對於 public/ 的路徑，失敗回傳 false
 */
function uploadPickupPhoto(array $file) {
    return saveUploadedImage($file, 'uploads/pickups', 'pickup', BASE_PATH . '/public');
}

/**
 * 驗證並儲存廣告圖片，回傳相對於 public/ 的路徑，失敗回傳 false
 */
function uploadAdImage(array $file) {
    return saveUploadedImage($file, 'uploads/ads', 'ad', BASE_PATH . '/public');
}

/**
 * 驗證並儲存 GO Rider 駕照照片。駕照屬個資，存放於網站根目錄之外的 storage/，
 * 只能透過審核人員專用的下載動作讀取。回傳相對於 storage/ 的路徑，失敗回傳 false
 */
function uploadRiderLicensePhoto(array $file) {
    return saveUploadedImage($file, 'rider_licenses', 'license', BASE_PATH . '/storage');
}

function saveUploadedImage(array $file, $relativeDirectory, $prefix, $rootDirectory) {
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
    $filename = $prefix . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $extension;
    $destinationDir = $rootDirectory . '/' . $relativeDirectory;
    $destinationPath = $destinationDir . '/' . $filename;

    if (!is_dir($destinationDir)) {
        // Suppress PHP warnings and return false when filesystem permissions block creation.
        if (!@mkdir($destinationDir, 0775, true) && !is_dir($destinationDir)) {
            return false;
        }
    }

    if (!is_writable($destinationDir)) {
        return false;
    }

    if (!@move_uploaded_file($file['tmp_name'], $destinationPath)) {
        return false;
    }

    return $relativeDirectory . '/' . $filename;
}
