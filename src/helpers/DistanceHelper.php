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

    foreach (buildAddressVariants($address) as $index => $variant) {
        $coordinate = geocodeExactAddress($variant);
        if ($coordinate !== false) {
            $coordinate['approximate'] = $index > 0;
            return $coordinate;
        }
    }

    return false;
}

/**
 * 依「完整地址 → 去除樓層/室號 → 僅保留路段」的順序，
 * 產生數個由精確到概略的地址查詢字串
 */
function buildAddressVariants($address) {
    $variants = [$address];

    // 去除樓層、室號、「之X」等常見的門牌附加資訊
    $noFloor = preg_replace('/(?:\d+\s*[Ff]|地下\d+樓?|\d+樓|之\d+|\d+室)+$/u', '', $address);
    $noFloor = trim($noFloor, " \t\n\r\0\x0B,、");
    if ($noFloor !== '' && !in_array($noFloor, $variants, true)) {
        $variants[] = $noFloor;
    }

    // 僅保留「路／街／大道（＋段）」，捨去巷弄號等細節，退回路段層級定位
    $base = $noFloor !== '' ? $noFloor : $address;
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

    $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query([
        'q' => $query,
        'format' => 'json',
        'limit' => 1,
        'countrycodes' => 'tw',
    ]);

    $response = httpGetJson($url);
    if (!is_array($response) || empty($response[0]['lat']) || empty($response[0]['lon'])) {
        return false;
    }

    return [
        'lat' => (float) $response[0]['lat'],
        'lon' => (float) $response[0]['lon'],
    ];
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
    $origin = geocodeAddress($originAddress);
    if ($origin === false) {
        return ['error' => '無法辨識取貨地址，請輸入更完整的地址（含縣市、路名、門牌號碼）。'];
    }

    $destination = geocodeAddress($destinationAddress);
    if ($destination === false) {
        return ['error' => '無法辨識送達地址，請輸入更完整的地址（含縣市、路名、門牌號碼）。'];
    }

    $route = routeDistance($origin, $destination);
    if ($route === false) {
        return ['error' => '無法計算兩地址之間的路程，請確認地址是否可到達。'];
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
    if (!function_exists('curl_init')) {
        return false;
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
        return false;
    }

    $decoded = json_decode($body, true);
    return json_last_error() === JSON_ERROR_NONE ? $decoded : false;
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
