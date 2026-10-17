-- 10/13 專題會議決議（第二批）：食物銀行分管理員／行政人員、最新消息公告（廣告＋活動）、
-- 配送預估送達時間與逾時通知、企業註冊欄位、黑名單、物資盤點。

-- 使用者欄位：食物銀行帳號類型、企業統一編號、得知來源、黑名單。
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `staff_type` enum('admin','clerk') DEFAULT NULL AFTER `member_type`,
  ADD COLUMN IF NOT EXISTS `tax_id` varchar(8) DEFAULT NULL AFTER `staff_type`,
  ADD COLUMN IF NOT EXISTS `referral_source` varchar(50) DEFAULT NULL AFTER `tax_id`,
  ADD COLUMN IF NOT EXISTS `blacklisted_at` datetime DEFAULT NULL AFTER `referral_source`,
  ADD COLUMN IF NOT EXISTS `blacklist_reason` varchar(255) DEFAULT NULL AFTER `blacklisted_at`;

-- 配送預估送達時間範圍與逾時通知記錄。
ALTER TABLE `deliveries`
  ADD COLUMN IF NOT EXISTS `estimated_arrival_start` datetime DEFAULT NULL AFTER `pickup_photo_path`,
  ADD COLUMN IF NOT EXISTS `estimated_arrival_end` datetime DEFAULT NULL AFTER `estimated_arrival_start`,
  ADD COLUMN IF NOT EXISTS `overdue_notified_at` datetime DEFAULT NULL AFTER `estimated_arrival_end`;

-- 廣告專區。
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

-- 權限目錄與預設矩陣。
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

-- 「活動報名／發布」與「廣告」合併為「最新消息公告」：把 page.activities 的授權轉移到 page.news。
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
