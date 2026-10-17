-- Consolidated upgrade for the 10/13 meeting decisions.
-- Apply to an existing installation instead of applying the four component
-- migrations individually. This does not replace schema.sql.

-- Remove live GPS tracking.
DROP TABLE IF EXISTS `delivery_location_trail`;
DROP TABLE IF EXISTS `delivery_locations`;

-- Replace tamper-seal fields with a volunteer pickup photo.
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

-- Add the volunteer application and review workflow.
CREATE TABLE IF NOT EXISTS `rider_applications` (
  `application_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `vehicle_type` enum('motorcycle','car','both') NOT NULL,
  `license_photo_path` varchar(255) NOT NULL,
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `review_notes` text DEFAULT NULL,
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`application_id`),
  KEY `idx_rider_applications_user` (`user_id`, `status`),
  KEY `idx_rider_applications_status` (`status`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO `permissions` (`permission_key`, `label`, `category`) VALUES
  ('page.rider_application', '申請外送志工', 'page'),
  ('page.rider_review', '外送志工審核', 'page');

-- Preserve current Rider access for existing general members unless explicitly revoked.
INSERT INTO `user_permission_overrides` (`user_id`, `permission_key`, `granted`)
SELECT u.`user_id`, k.`permission_key`, 1
FROM `users` u
CROSS JOIN (
  SELECT 'rider.accept_task' AS `permission_key`
  UNION ALL SELECT 'page.deliveries'
  UNION ALL SELECT 'page.material_transport'
) k
WHERE u.`role` = 'member' AND u.`member_type` = 'general'
  AND NOT EXISTS (
    SELECT 1 FROM `user_permission_overrides` o
    WHERE o.`user_id` = u.`user_id` AND o.`permission_key` = 'rider.accept_task' AND o.`granted` = 0
  )
ON DUPLICATE KEY UPDATE `granted` = `granted`;

DELETE FROM `role_permission_defaults`
WHERE `role` = 'member' AND `member_type` = 'general'
  AND `permission_key` IN ('page.deliveries', 'page.material_transport', 'rider.accept_task');

INSERT IGNORE INTO `role_permission_defaults` (`role`, `member_type`, `permission_key`) VALUES
  ('member', 'general', 'page.rider_application'),
  ('foodbank_staff', '', 'page.rider_review');

-- Add staff roles, news and ads, arrival estimates, blacklist, and inventory permissions.
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `staff_type` enum('admin','clerk') DEFAULT NULL AFTER `member_type`,
  ADD COLUMN IF NOT EXISTS `tax_id` varchar(8) DEFAULT NULL AFTER `staff_type`,
  ADD COLUMN IF NOT EXISTS `referral_source` varchar(50) DEFAULT NULL AFTER `tax_id`,
  ADD COLUMN IF NOT EXISTS `blacklisted_at` datetime DEFAULT NULL AFTER `referral_source`,
  ADD COLUMN IF NOT EXISTS `blacklist_reason` varchar(255) DEFAULT NULL AFTER `blacklisted_at`;

ALTER TABLE `deliveries`
  ADD COLUMN IF NOT EXISTS `estimated_arrival_start` datetime DEFAULT NULL AFTER `pickup_photo_path`,
  ADD COLUMN IF NOT EXISTS `estimated_arrival_end` datetime DEFAULT NULL AFTER `estimated_arrival_start`,
  ADD COLUMN IF NOT EXISTS `overdue_notified_at` datetime DEFAULT NULL AFTER `estimated_arrival_end`;

CREATE TABLE IF NOT EXISTS `advertisements` (
  `ad_id` int(11) NOT NULL AUTO_INCREMENT,
  `advertiser_id` int(11) NOT NULL,
  `title` varchar(150) NOT NULL,
  `content` text NOT NULL,
  `link_url` varchar(255) DEFAULT NULL,
  `image_path` varchar(255) DEFAULT NULL,
  `status` enum('active','removed') NOT NULL DEFAULT 'active',
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`ad_id`),
  KEY `idx_advertisements_status` (`status`, `created_at`),
  KEY `idx_advertisements_advertiser` (`advertiser_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE `role_permission_defaults`
  MODIFY `member_type` enum('general','enterprise','clerk','') NOT NULL DEFAULT '';

UPDATE `permissions` SET `label` = '主頁' WHERE `permission_key` = 'page.dashboard';

INSERT IGNORE INTO `permissions` (`permission_key`, `label`, `category`) VALUES
  ('page.news', '最新消息公告', 'page'),
  ('page.inventory', '物資盤點', 'page'),
  ('delivery.manage', '發布／編輯／處理配送任務', 'delivery'),
  ('delivery.confirm_arrival', '確認配送已抵達並查收', 'delivery'),
  ('inventory.inspect', '完成物資清點', 'inventory'),
  ('ad.publish', '刊登廣告', 'news'),
  ('activity.publish', '發布公益活動', 'news');

INSERT IGNORE INTO `role_permission_defaults` (`role`, `member_type`, `permission_key`)
SELECT `role`, `member_type`, 'page.news' FROM `role_permission_defaults` WHERE `permission_key` = 'page.activities';

INSERT INTO `user_permission_overrides` (`user_id`, `permission_key`, `granted`)
SELECT `user_id`, 'page.news', `granted` FROM `user_permission_overrides` WHERE `permission_key` = 'page.activities'
ON DUPLICATE KEY UPDATE `granted` = VALUES(`granted`);

DELETE FROM `permissions` WHERE `permission_key` = 'page.activities';

INSERT IGNORE INTO `role_permission_defaults` (`role`, `member_type`, `permission_key`) VALUES
  ('foodbank_staff', '', 'page.inventory'),
  ('foodbank_staff', '', 'delivery.manage'),
  ('foodbank_staff', '', 'delivery.confirm_arrival'),
  ('foodbank_staff', '', 'inventory.inspect'),
  ('foodbank_staff', '', 'ad.publish'),
  ('foodbank_staff', '', 'activity.publish'),
  ('member', 'enterprise', 'ad.publish'),
  ('member', 'enterprise', 'activity.publish'),
  ('foodbank_staff', 'clerk', 'page.dashboard'),
  ('foodbank_staff', 'clerk', 'page.order_tracking'),
  ('foodbank_staff', 'clerk', 'page.deliveries'),
  ('foodbank_staff', 'clerk', 'page.inventory'),
  ('foodbank_staff', 'clerk', 'page.notifications'),
  ('foodbank_staff', 'clerk', 'delivery.confirm_arrival'),
  ('foodbank_staff', 'clerk', 'inventory.inspect');
