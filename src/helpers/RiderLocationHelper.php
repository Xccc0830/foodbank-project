<?php
/**
 * GO Rider 即時位置與多站路線建議。
 * 位置只保留每位騎手「最新一筆」，且只提供給該單的食物銀行人員與提供物資的商家查看。
 */

require_once __DIR__ . '/DistanceHelper.php';

class RiderLocationHelper {
    /** 位置超過這個秒數沒更新，就視為騎手已離線 */
    const FRESH_SECONDS = 600;
    const ACTIVE_STATUSES = "'claimed','arrived_pickup','picked_up'";

    public static function ensureTable(mysqli $db) {
        $db->query(
            "CREATE TABLE IF NOT EXISTS rider_locations (
                volunteer_id INT NOT NULL PRIMARY KEY,
                lat DECIMAL(10,7) NOT NULL,
                lon DECIMAL(10,7) NOT NULL,
                updated_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public static function validCoordinate($lat, $lon) {
        return is_numeric($lat) && is_numeric($lon) && abs((float) $lat) <= 90 && abs((float) $lon) <= 180;
    }

    /** 只有手上有進行中配送單的騎手才會被記錄位置 */
    public static function save(mysqli $db, $volunteerId, $lat, $lon) {
        $volunteerId = (int) $volunteerId;
        if (!self::validCoordinate($lat, $lon)) {
            return false;
        }
        $active = $db->query("SELECT 1 FROM deliveries WHERE volunteer_id = {$volunteerId} AND delivery_method = 'volunteer' AND status IN (" . self::ACTIVE_STATUSES . ") LIMIT 1");
        if (!$active || $active->num_rows === 0) {
            return false;
        }
        self::ensureTable($db);
        $stmt = $db->prepare("REPLACE INTO rider_locations (volunteer_id, lat, lon, updated_at) VALUES (?, ?, ?, NOW())");
        $lat = (float) $lat;
        $lon = (float) $lon;
        $stmt->bind_param('idd', $volunteerId, $lat, $lon);
        return $stmt->execute();
    }

    /**
     * 查看某張配送單騎手的位置。僅限食物銀行人員，或該單物資的捐贈商家。
     * @return array|null ['lat','lon','updated_at'] 或 null（無權限、未在運送中、位置過期）
     */
    public static function forDelivery(mysqli $db, $deliveryId, $viewerId, $viewerRole) {
        $deliveryId = (int) $deliveryId;
        $viewerId = (int) $viewerId;
        self::ensureTable($db);
        $result = $db->query(
            "SELECT l.lat, l.lon, l.updated_at, n.donor_id
             FROM deliveries d
             LEFT JOIN donations n ON n.donation_id = d.donation_id
             JOIN rider_locations l ON l.volunteer_id = d.volunteer_id
             WHERE d.delivery_id = {$deliveryId} AND d.status IN (" . self::ACTIVE_STATUSES . ")
               AND l.updated_at >= DATE_SUB(NOW(), INTERVAL " . self::FRESH_SECONDS . " SECOND)
             LIMIT 1"
        );
        $row = $result ? $result->fetch_assoc() : null;
        if (!$row) {
            return null;
        }
        if ($viewerRole !== 'foodbank_staff' && (int) $row['donor_id'] !== $viewerId) {
            return null;
        }
        return ['lat' => (float) $row['lat'], 'lon' => (float) $row['lon'], 'updated_at' => $row['updated_at']];
    }

    /**
     * 多站路線建議：從騎手目前位置出發，每次挑最近的「可去的站」。
     * 可去的站 = 尚未取貨單的取貨點，或已取貨單的送達點（同一單一定先取貨再送達）。
     * @return array 依序的站點 [['delivery_id','type'=>'pickup|dropoff','address','distance_km'], ...]
     */
    public static function planRoute(mysqli $db, $volunteerId, $lat, $lon) {
        $volunteerId = (int) $volunteerId;
        $result = $db->query(
            "SELECT delivery_id, status, pickup_address, delivery_address FROM deliveries
             WHERE volunteer_id = {$volunteerId} AND delivery_method = 'volunteer' AND status IN (" . self::ACTIVE_STATUSES . ")"
        );
        $pending = [];
        while ($result && ($row = $result->fetch_assoc())) {
            $pending[] = $row;
        }
        $position = ['lat' => (float) $lat, 'lon' => (float) $lon];
        $coordinateCache = [];
        $locate = static function ($address) use (&$coordinateCache) {
            if (!array_key_exists($address, $coordinateCache)) {
                $coordinateCache[$address] = geocodeAddress($address);
            }
            return $coordinateCache[$address];
        };

        $route = [];
        while ($pending) {
            $best = null;
            foreach ($pending as $index => $delivery) {
                $isPickup = $delivery['status'] !== 'picked_up';
                $address = $isPickup ? $delivery['pickup_address'] : $delivery['delivery_address'];
                $point = $locate($address);
                $distance = $point ? straightLineKm($position, $point) : 9999;
                if ($best === null || $distance < $best['distance']) {
                    $best = ['index' => $index, 'distance' => $distance, 'point' => $point, 'isPickup' => $isPickup, 'address' => $address];
                }
            }
            $delivery = $pending[$best['index']];
            $route[] = [
                'delivery_id' => (int) $delivery['delivery_id'],
                'type' => $best['isPickup'] ? 'pickup' : 'dropoff',
                'address' => $best['address'],
                'distance_km' => $best['distance'] >= 9999 ? null : round($best['distance'], 1),
            ];
            if ($best['point']) {
                $position = $best['point'];
            }
            if ($best['isPickup']) {
                // 取完貨後，這張單的送達點才變成可去的站
                $pending[$best['index']]['status'] = 'picked_up';
            } else {
                array_splice($pending, $best['index'], 1);
            }
        }
        return $route;
    }
}
