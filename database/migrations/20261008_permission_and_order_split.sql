-- 權限系統：permission 目錄 + 角色預設矩陣 + 使用者個人覆寫
CREATE TABLE IF NOT EXISTS `permissions` (
  `permission_key` VARCHAR(50) NOT NULL,
  `label` VARCHAR(100) NOT NULL,
  `category` VARCHAR(50) NOT NULL,
  PRIMARY KEY (`permission_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `role_permission_defaults` (
  `role` ENUM('foodbank_staff','member') NOT NULL,
  `member_type` ENUM('general','enterprise','') NOT NULL DEFAULT '', -- 空字串代表不區分 member_type（例如 foodbank_staff）；PK 不可為 NULL，故以空字串為明確 sentinel 值
  `permission_key` VARCHAR(50) NOT NULL,
  PRIMARY KEY (`role`, `member_type`, `permission_key`),
  KEY `idx_rpd_permission_key` (`permission_key`),
  CONSTRAINT `role_permission_defaults_ibfk_1` FOREIGN KEY (`permission_key`) REFERENCES `permissions` (`permission_key`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `user_permission_overrides` (
  `user_id` INT(11) NOT NULL,
  `permission_key` VARCHAR(50) NOT NULL,
  `granted` TINYINT(1) NOT NULL DEFAULT 1,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`, `permission_key`),
  KEY `idx_upo_permission_key` (`permission_key`),
  CONSTRAINT `user_permission_overrides_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  CONSTRAINT `user_permission_overrides_ibfk_2` FOREIGN KEY (`permission_key`) REFERENCES `permissions` (`permission_key`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 種子資料：權限目錄
INSERT IGNORE INTO `permissions` (`permission_key`, `label`, `category`) VALUES
  ('page.dashboard', '儀表板', 'page'),
  ('page.order_tracking', '訂單追蹤', 'page'),
  ('page.deliveries', '配送任務', 'page'),
  ('page.material_transport', '物資運送', 'page'),
  ('page.activities', '活動報名／發布', 'page'),
  ('page.item_categories', '物資分類', 'page'),
  ('page.rewards', '興毅幣兌換', 'page'),
  ('page.carbon_report', '永續報告', 'page'),
  ('page.reports', '數據分析／榮譽榜', 'page'),
  ('page.notifications', '通知中心', 'page'),
  ('page.donation_materials', '物資捐贈', 'page'),
  ('page.donation_materials_review', '物資捐贈審查', 'page'),
  ('page.settings', '設置', 'page'),
  ('page.users', '帳號審核', 'page'),
  ('page.volunteer_management', '一般會員管理', 'page'),
  ('page.certificate', '證書', 'page'),
  ('page.activity_certificate', '活動證書', 'page'),
  ('rider.accept_task', '承接 Rider 配送任務', 'delivery'),
  ('donation.create', '刊登物資捐贈', 'donation'),
  ('donation.review', '審查／拆單物資捐贈', 'donation'),
  ('admin.manage_users', '管理帳號審核', 'admin');

-- 種子資料：角色預設權限矩陣（重現目前 $rolePages 行為，確保零回歸）
INSERT IGNORE INTO `role_permission_defaults` (`role`, `member_type`, `permission_key`) VALUES
  ('foodbank_staff', '', 'page.dashboard'),
  ('foodbank_staff', '', 'page.order_tracking'),
  ('foodbank_staff', '', 'page.donation_materials_review'),
  ('foodbank_staff', '', 'page.deliveries'),
  ('foodbank_staff', '', 'page.activities'),
  ('foodbank_staff', '', 'page.item_categories'),
  ('foodbank_staff', '', 'page.rewards'),
  ('foodbank_staff', '', 'page.settings'),
  ('foodbank_staff', '', 'page.users'),
  ('foodbank_staff', '', 'page.volunteer_management'),
  ('foodbank_staff', '', 'page.carbon_report'),
  ('foodbank_staff', '', 'page.reports'),
  ('foodbank_staff', '', 'page.notifications'),
  ('foodbank_staff', '', 'page.certificate'),
  ('foodbank_staff', '', 'page.activity_certificate'),
  ('foodbank_staff', '', 'donation.review'),
  ('foodbank_staff', '', 'admin.manage_users'),
  ('member', 'enterprise', 'page.dashboard'),
  ('member', 'enterprise', 'page.order_tracking'),
  ('member', 'enterprise', 'page.activities'),
  ('member', 'enterprise', 'page.rewards'),
  ('member', 'enterprise', 'page.notifications'),
  ('member', 'enterprise', 'page.donation_materials'),
  ('member', 'enterprise', 'page.carbon_report'),
  ('member', 'enterprise', 'page.certificate'),
  ('member', 'enterprise', 'page.activity_certificate'),
  ('member', 'enterprise', 'donation.create'),
  ('member', 'general', 'page.dashboard'),
  ('member', 'general', 'page.deliveries'),
  ('member', 'general', 'page.material_transport'),
  ('member', 'general', 'page.activities'),
  ('member', 'general', 'page.rewards'),
  ('member', 'general', 'page.reports'),
  ('member', 'general', 'page.notifications'),
  ('member', 'general', 'page.certificate'),
  ('member', 'general', 'page.activity_certificate'),
  ('member', 'general', 'rider.accept_task');

-- 既有帳號回填：所有目前已存在的一般會員視為已加選 Rider 任務（維持現行行為，零回歸）
INSERT IGNORE INTO `user_permission_overrides` (`user_id`, `permission_key`, `granted`)
SELECT `user_id`, 'rider.accept_task', 1 FROM `users` WHERE `role` = 'member' AND `member_type` = 'general';

-- 物資項目：新增長寬高與重量欄位（商家刊登表單使用）
ALTER TABLE `donation_items`
  ADD COLUMN IF NOT EXISTS `length_cm` DECIMAL(6,2) DEFAULT NULL AFTER `unit`,
  ADD COLUMN IF NOT EXISTS `width_cm` DECIMAL(6,2) DEFAULT NULL AFTER `length_cm`,
  ADD COLUMN IF NOT EXISTS `height_cm` DECIMAL(6,2) DEFAULT NULL AFTER `width_cm`,
  ADD COLUMN IF NOT EXISTS `item_weight_kg` DECIMAL(8,2) DEFAULT NULL AFTER `height_cm`;

-- 拆單系統：子單（Sub-order / Child Order）補齊編號與長寬高欄位
ALTER TABLE `donation_allocations`
  ADD COLUMN IF NOT EXISTS `sub_order_number` VARCHAR(40) DEFAULT NULL AFTER `allocation_number`,
  ADD COLUMN IF NOT EXISTS `length_cm` DECIMAL(6,2) DEFAULT NULL AFTER `sub_order_number`,
  ADD COLUMN IF NOT EXISTS `width_cm` DECIMAL(6,2) DEFAULT NULL AFTER `length_cm`,
  ADD COLUMN IF NOT EXISTS `height_cm` DECIMAL(6,2) DEFAULT NULL AFTER `width_cm`,
  ADD COLUMN IF NOT EXISTS `weight_kg` DECIMAL(8,2) DEFAULT NULL AFTER `height_cm`,
  ADD UNIQUE KEY IF NOT EXISTS `uq_sub_order_number` (`sub_order_number`);

-- 子單內容物明細：允許同一物資項目被拆到不同子單、各自數量
CREATE TABLE IF NOT EXISTS `donation_allocation_items` (
  `allocation_item_id` INT(11) NOT NULL AUTO_INCREMENT,
  `allocation_id` INT(11) NOT NULL,
  `donation_item_id` INT(11) NOT NULL,
  `quantity` DECIMAL(10,2) NOT NULL,
  PRIMARY KEY (`allocation_item_id`),
  KEY `idx_dai_allocation_id` (`allocation_id`),
  KEY `idx_dai_donation_item_id` (`donation_item_id`),
  CONSTRAINT `donation_allocation_items_ibfk_1` FOREIGN KEY (`allocation_id`) REFERENCES `donation_allocations` (`allocation_id`) ON DELETE CASCADE,
  CONSTRAINT `donation_allocation_items_ibfk_2` FOREIGN KEY (`donation_item_id`) REFERENCES `donation_items` (`item_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 配送任務關聯到特定子單（未拆單時維持 NULL，代表對應主單整批配送）
ALTER TABLE `deliveries`
  ADD COLUMN IF NOT EXISTS `allocation_id` INT(11) DEFAULT NULL AFTER `donation_id`,
  ADD KEY IF NOT EXISTS `idx_deliveries_allocation_id` (`allocation_id`);

-- 活動報名：企業會員可自填參與人數
ALTER TABLE `activity_assignments`
  ADD COLUMN IF NOT EXISTS `participant_count` INT(11) NOT NULL DEFAULT 1 AFTER `organization_name`;
