-- Go Rider 取貨後的行進軌跡（DeliveryModel 也會在缺少時自動建立）
CREATE TABLE IF NOT EXISTS delivery_location_trail (
    point_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    delivery_id INT NOT NULL,
    latitude DECIMAL(10,7) NOT NULL,
    longitude DECIMAL(10,7) NOT NULL,
    recorded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (point_id),
    KEY idx_delivery_recorded (delivery_id, point_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
