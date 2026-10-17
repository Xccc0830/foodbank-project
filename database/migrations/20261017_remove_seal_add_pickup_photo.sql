-- 10/13 專題會議決議：取消防拆封條機制，改為商家填寫並拍照上傳、志工取貨時拍照。
ALTER TABLE `deliveries`
  ADD COLUMN IF NOT EXISTS `pickup_photo_path` varchar(255) DEFAULT NULL AFTER `pickup_confirmed_at`;

ALTER TABLE `deliveries`
  DROP COLUMN IF EXISTS `seal_code`,
  DROP COLUMN IF EXISTS `seal_intact`,
  DROP COLUMN IF EXISTS `item_count_confirmed`;

ALTER TABLE `donations`
  DROP INDEX IF EXISTS `idx_donations_seal_code`;

ALTER TABLE `donations`
  DROP COLUMN IF EXISTS `seal_code`;
