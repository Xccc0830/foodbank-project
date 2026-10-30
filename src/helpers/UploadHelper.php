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

function storedPublicImageUrl($storedPath) {
    $storedPath = (string) $storedPath;
    if (strpos($storedPath, 'supabase-public:') !== 0) {
        return APP_URL . '/' . ltrim($storedPath, '/');
    }

    $baseUrl = rtrim((string) getenv('SUPABASE_URL'), '/');
    $bucket = getenv('SUPABASE_STORAGE_PUBLIC_BUCKET') ?: 'foodbank-public';
    $objectPath = substr($storedPath, strlen('supabase-public:'));
    return $baseUrl . '/storage/v1/object/public/' . rawurlencode($bucket) . '/' . encodeStorageObjectPath($objectPath);
}

function downloadPrivateStorageObject($storedPath) {
    if (strpos((string) $storedPath, 'supabase-private:') !== 0) {
        return false;
    }

    $baseUrl = rtrim((string) getenv('SUPABASE_URL'), '/');
    $serviceKey = (string) getenv('SUPABASE_SERVICE_ROLE_KEY');
    $bucket = getenv('SUPABASE_STORAGE_PRIVATE_BUCKET') ?: 'rider-licenses';
    if ($baseUrl === '' || parse_url($baseUrl, PHP_URL_SCHEME) !== 'https' || $serviceKey === '') {
        error_log('Supabase Storage credentials are not configured for private object access.');
        return false;
    }

    $objectPath = substr((string) $storedPath, strlen('supabase-private:'));
    $url = $baseUrl . '/storage/v1/object/' . rawurlencode($bucket) . '/' . encodeStorageObjectPath($objectPath);
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $serviceKey, 'apikey: ' . $serviceKey],
        CURLOPT_TIMEOUT => 20,
    ]);
    $body = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $contentType = (string) curl_getinfo($curl, CURLINFO_CONTENT_TYPE);
    $curlError = curl_error($curl);
    curl_close($curl);

    if ($body === false || $status !== 200) {
        error_log('Supabase private object download failed (HTTP ' . $status . '): ' . $curlError);
        return false;
    }
    return ['body' => $body, 'content_type' => $contentType];
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
    if (DB_DRIVER === 'pgsql') {
        return uploadSupabaseObject(
            $file['tmp_name'],
            $mimeType,
            $relativeDirectory . '/' . $filename,
            $relativeDirectory === 'rider_licenses'
        );
    }

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

function uploadSupabaseObject($localPath, $mimeType, $objectPath, $private) {
    $baseUrl = rtrim((string) getenv('SUPABASE_URL'), '/');
    $serviceKey = (string) getenv('SUPABASE_SERVICE_ROLE_KEY');
    if ($baseUrl === '' || parse_url($baseUrl, PHP_URL_SCHEME) !== 'https' || $serviceKey === '') {
        error_log('Supabase Storage upload was requested but its URL or service key is missing.');
        return false;
    }

    $bucket = $private
        ? (getenv('SUPABASE_STORAGE_PRIVATE_BUCKET') ?: 'rider-licenses')
        : (getenv('SUPABASE_STORAGE_PUBLIC_BUCKET') ?: 'foodbank-public');
    $url = $baseUrl . '/storage/v1/object/' . rawurlencode($bucket) . '/' . encodeStorageObjectPath($objectPath);
    $contents = file_get_contents($localPath);
    if ($contents === false) {
        error_log('Could not read the uploaded image temporary file.');
        return false;
    }

    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $contents,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $serviceKey,
            'apikey: ' . $serviceKey,
            'Content-Type: ' . $mimeType,
            'x-upsert: false',
        ],
        CURLOPT_TIMEOUT => 20,
    ]);
    $response = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);

    if ($response === false || $status < 200 || $status >= 300) {
        error_log('Supabase Storage upload failed (HTTP ' . $status . '): ' . $curlError);
        return false;
    }

    return ($private ? 'supabase-private:' : 'supabase-public:') . $objectPath;
}

function encodeStorageObjectPath($objectPath) {
    return implode('/', array_map('rawurlencode', explode('/', ltrim((string) $objectPath, '/'))));
}
