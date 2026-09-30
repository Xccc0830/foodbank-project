ALTER TABLE `donations`
  ADD COLUMN IF NOT EXISTS `estimated_distance_km` decimal(8,2) DEFAULT NULL AFTER `delivery_address`,
  ADD COLUMN IF NOT EXISTS `estimated_duration_minutes` smallint(5) UNSIGNED DEFAULT NULL AFTER `estimated_distance_km`;
