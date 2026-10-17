-- 10/13 專題會議決議：不需要即時 GPS 定位，移除配送位置與軌跡資料表。
DROP TABLE IF EXISTS `delivery_location_trail`;
DROP TABLE IF EXISTS `delivery_locations`;
