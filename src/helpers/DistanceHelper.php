<?php
/**
 * 地址距離估算工具
 * 使用 OpenStreetMap Nominatim 進行地址地理編碼，
 * 再透過 OSRM 路徑規劃服務計算兩點間的實際路程距離與時間。
 * 無需申請付費的地圖 API 金鑰。
 */

/**
 * 將地址字串轉換為經緯度座標
 * OpenStreetMap 對台灣門牌號碼（號、巷、弄）的收錄並不完整，
 * 因此會由完整地址開始嘗試，逐步簡化到路段層級以提高成功率。
 * 回傳 ['lat' => float, 'lon' => float, 'approximate' => bool]，失敗回傳 false
 */
function geocodeAddress($address) {
    $address = trim((string) $address);
    if ($address === '') {
        return false;
    }

    $cached = geocodeCacheGet($address);
    if ($cached !== null) {
        return $cached;
    }

    $normalizedAddress = normalizeTaiwanPostalCode($address);
    foreach (buildAddressVariants($address) as $variant) {
        $variantCached = $variant === $address ? null : geocodeCacheGet($variant);
        $coordinate = $variantCached ?? geocodeExactAddress($variant);
        if ($coordinate === 'rate_limited') {
            $GLOBALS['geocode_rate_limited'] = true;
            return false;
        }
        if ($coordinate !== false) {
            $coordinate['approximate'] = !empty($coordinate['approximate'])
                || normalizeTaiwanPostalCode($variant) !== $normalizedAddress;
            geocodeCachePut($address, $coordinate);
            return $coordinate;
        }
    }

    return false;
}

function geocodeCacheConnection() {
    return isset($GLOBALS['db']) && is_object($GLOBALS['db']) && method_exists($GLOBALS['db'], 'getConnection')
        ? $GLOBALS['db']->getConnection()
        : null;
}

/** 地址查過一次就存起來，避免重複打外部服務被限流。 */
function geocodeCacheGet($address) {
    $connection = geocodeCacheConnection();
    if (!$connection) {
        return null;
    }
    static $tableReady = false;
    if (!$tableReady) {
        $connection->query(
            "CREATE TABLE IF NOT EXISTS geocode_cache (
                address_hash CHAR(32) NOT NULL PRIMARY KEY,
                address VARCHAR(255) NOT NULL,
                lat DECIMAL(10,7) NOT NULL,
                lon DECIMAL(10,7) NOT NULL,
                approximate TINYINT(1) NOT NULL DEFAULT 0,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
        $tableReady = true;
    }
    $hash = md5($address);
    $result = $connection->query("SELECT lat, lon, approximate FROM geocode_cache WHERE address_hash = '{$hash}' LIMIT 1");
    $row = $result ? $result->fetch_assoc() : null;
    return $row ? ['lat' => (float) $row['lat'], 'lon' => (float) $row['lon'], 'approximate' => (bool) $row['approximate']] : null;
}

function geocodeCachePut($address, array $coordinate) {
    $connection = geocodeCacheConnection();
    if (!$connection) {
        return;
    }
    $stmt = $connection->prepare("REPLACE INTO geocode_cache (address_hash, address, lat, lon, approximate) VALUES (?, ?, ?, ?, ?)");
    $hash = md5($address);
    $short = mb_substr($address, 0, 255);
    $approximate = !empty($coordinate['approximate']) ? 1 : 0;
    $stmt->bind_param('ssddi', $hash, $short, $coordinate['lat'], $coordinate['lon'], $approximate);
    $stmt->execute();
}

/**
 * 去除 Google Maps 地址開頭的台灣郵遞區號，避免地理編碼服務將其誤認成門牌。
 */
function normalizeTaiwanPostalCode($address) {
    $address = trim((string) $address);
    $normalizedAddress = preg_replace('/^\s*\d{3}(?:\s*-\s*\d{2,3}|\d{2,3})?\s*(?=[\p{Han}])/u', '', $address);
    return trim((string) ($normalizedAddress ?? $address));
}

/**
 * 依「完整地址 → 去除樓層/室號 → 僅保留路段」的順序，
 * 產生數個由精確到概略的地址查詢字串
 */
function buildAddressVariants($address) {
    $normalizedAddress = normalizeTaiwanPostalCode($address);
    $variants = [$normalizedAddress];
    if ($address !== $normalizedAddress) {
        $variants[] = $address;
    }

    // 去除樓層、室號、「之X」等常見的門牌附加資訊
    $noFloor = preg_replace('/(?:\d+\s*[Ff]|地下\d+樓?|\d+樓|之\d+|\d+室)+$/u', '', $normalizedAddress);
    $noFloor = trim($noFloor, " \t\n\r\0\x0B,、");
    if ($noFloor !== '' && !in_array($noFloor, $variants, true)) {
        $variants[] = $noFloor;
    }

    // 僅保留「路／街／大道（＋段）」，捨去巷弄號等細節，退回路段層級定位
    $base = $noFloor !== '' ? $noFloor : $normalizedAddress;
    if (preg_match('/^(.*?(?:路|街|大道)(?:[一二三四五六七八九十0-9]+段)?)/u', $base, $matches)) {
        $roadOnly = trim($matches[1]);
        if ($roadOnly !== '' && !in_array($roadOnly, $variants, true)) {
            $variants[] = $roadOnly;
        }
    }

    return $variants;
}

/**
 * 呼叫 Nominatim 查詢單一地址字串，成功回傳座標，失敗回傳 false
 */
function geocodeExactAddress($address) {
    $address = trim((string) $address);
    if ($address === '') {
        return false;
    }

    // 補上「台灣」以提高地址比對準確度（若使用者尚未輸入國家）
    $query = (mb_strpos($address, '台灣') === false && mb_strpos($address, '臺灣') === false)
        ? $address . ', 台灣'
        : $address;

    // 有設定 Google Maps 金鑰時優先使用（對台灣門牌最準，也沒有 Nominatim 的頻率限制）
    if (defined('GOOGLE_MAPS_API_KEY') && GOOGLE_MAPS_API_KEY !== '') {
        $google = httpGetJsonWithStatus('https://maps.googleapis.com/maps/api/geocode/json?' . http_build_query([
            'address' => $query,
            'region' => 'tw',
            'language' => 'zh-TW',
            'key' => GOOGLE_MAPS_API_KEY,
        ]));
        $location = $google['body']['results'][0]['geometry']['location'] ?? null;
        if ($location) {
            return ['lat' => (float) $location['lat'], 'lon' => (float) $location['lng']];
        }
    }

    // Nominatim 規定每秒最多 1 次請求；跨請求共用一個時間戳來節流。
    throttleNominatim();
    $response = httpGetJsonWithStatus('https://nominatim.openstreetmap.org/search?' . http_build_query([
        'q' => $query,
        'format' => 'json',
        'limit' => 1,
        'countrycodes' => 'tw',
    ]));
    if ($response['status'] === 429 || $response['status'] === 403) {
        return 'rate_limited';
    }

    $body = $response['body'];
    if (!is_array($body) || empty($body[0]['lat']) || empty($body[0]['lon'])) {
        return false;
    }

    return [
        'lat' => (float) $body[0]['lat'],
        'lon' => (float) $body[0]['lon'],
    ];
}

function throttleNominatim() {
    $file = sys_get_temp_dir() . '/foodbank_nominatim_last';
    $handle = @fopen($file, 'c+');
    if (!$handle) {
        usleep(1100000);
        return;
    }
    flock($handle, LOCK_EX);
    $last = (float) stream_get_contents($handle);
    $wait = 1.1 - (microtime(true) - $last);
    if ($wait > 0) {
        usleep((int) ($wait * 1000000));
    }
    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, (string) microtime(true));
    flock($handle, LOCK_UN);
    fclose($handle);
}

/**
 * 呼叫 OSRM 服務，取得兩座標之間的實際路程距離（公里）與時間（分鐘）
 * 回傳 ['distance_km' => float, 'duration_minutes' => float]，失敗回傳 false
 */
function routeDistance(array $origin, array $destination) {
    if (!isset($origin['lat'], $origin['lon'], $destination['lat'], $destination['lon'])) {
        return false;
    }

    $coordinates = sprintf(
        '%F,%F;%F,%F',
        $origin['lon'],
        $origin['lat'],
        $destination['lon'],
        $destination['lat']
    );
    $url = 'https://router.project-osrm.org/route/v1/driving/' . $coordinates . '?overview=false';

    $response = httpGetJson($url);
    if (!is_array($response) || ($response['code'] ?? '') !== 'Ok' || empty($response['routes'][0])) {
        return false;
    }

    $route = $response['routes'][0];
    return [
        'distance_km' => round(((float) ($route['distance'] ?? 0)) / 1000, 2),
        'duration_minutes' => round(((float) ($route['duration'] ?? 0)) / 60, 1),
    ];
}

/**
 * 計算兩地址之間的路程距離（公里）與預估運送時間（分鐘）
 * 回傳 ['distance_km' => float, 'duration_minutes' => float, 'origin' => [...], 'destination' => [...]]
 * 失敗時回傳 ['error' => string]
 */
function calculateAddressDistance($originAddress, $destinationAddress) {
    $GLOBALS['geocode_rate_limited'] = false;
    $busyMessage = '地圖查詢服務目前較忙碌，請過一分鐘後再試（填過的地址會被記住，之後會比較快）。';

    $origin = geocodeAddress($originAddress);
    if ($origin === false) {
        return ['error' => !empty($GLOBALS['geocode_rate_limited']) ? $busyMessage : '無法辨識取貨地址，請輸入更完整的地址（含縣市、路名、門牌號碼）。'];
    }

    $destination = geocodeAddress($destinationAddress);
    if ($destination === false) {
        return ['error' => !empty($GLOBALS['geocode_rate_limited']) ? $busyMessage : '無法辨識送達地址，請輸入更完整的地址（含縣市、路名、門牌號碼）。'];
    }

    $route = routeDistance($origin, $destination);
    if ($route === false) {
        // 路徑服務暫時不可用：改用直線距離 ×1.3 概估，並標示為概略值
        $straight = straightLineKm($origin, $destination);
        $route = [
            'distance_km' => round($straight * 1.3, 2),
            'duration_minutes' => round($straight * 1.3 / 30 * 60, 1),
        ];
        $origin['approximate'] = true;
    }

    return array_merge($route, [
        'origin' => $origin,
        'destination' => $destination,
        'approximate' => !empty($origin['approximate']) || !empty($destination['approximate']),
    ]);
}

/**
 * 呼叫外部 URL 並將回應解析為 JSON 陣列，失敗回傳 false
 */
function httpGetJson($url) {
    $response = httpGetJsonWithStatus($url);
    return $response['body'];
}

function straightLineKm(array $a, array $b) {
    $earth = 6371.0;
    $dLat = deg2rad($b['lat'] - $a['lat']);
    $dLon = deg2rad($b['lon'] - $a['lon']);
    $h = sin($dLat / 2) ** 2 + cos(deg2rad($a['lat'])) * cos(deg2rad($b['lat'])) * sin($dLon / 2) ** 2;
    return 2 * $earth * asin(min(1, sqrt($h)));
}

/**
 * 與 httpGetJson 相同，但同時回傳 HTTP 狀態碼：['status' => int, 'body' => array|false]
 */
function httpGetJsonWithStatus($url) {
    if (!function_exists('curl_init')) {
        return ['status' => 0, 'body' => false];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        // Nominatim 使用政策要求提供可識別的 User-Agent
        CURLOPT_USERAGENT => 'foodbank-project-distance-estimator/1.0',
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $body = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false || $httpCode < 200 || $httpCode >= 300) {
        return ['status' => (int) $httpCode, 'body' => false];
    }

    $decoded = json_decode($body, true);
    return ['status' => (int) $httpCode, 'body' => json_last_error() === JSON_ERROR_NONE ? $decoded : false];
}

/**
 * 將公尺／公里距離格式化為人類可讀文字
 */
function formatDistanceText($distanceKm) {
    $distanceKm = (float) $distanceKm;
    if ($distanceKm < 1) {
        return round($distanceKm * 1000) . ' 公尺';
    }
    return round($distanceKm, 2) . ' 公里';
}

/**
 * 將分鐘數格式化為人類可讀文字
 */
function formatDurationText($durationMinutes) {
    $durationMinutes = (float) $durationMinutes;
    if ($durationMinutes < 1) {
        return '約 1 分鐘';
    }
    return '約 ' . round($durationMinutes) . ' 分鐘';
}
