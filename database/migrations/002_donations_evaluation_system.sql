-- 食物銀行評估派車功能擴展
-- 為 donations 表添加新的欄位以支持完整的評估和派車流程

ALTER TABLE `donations` ADD COLUMN IF NOT EXISTS `evaluation_status` enum('pending','approved_volunteer','approved_self_delivery','published','rejected') DEFAULT 'pending' AFTER `status`;
ALTER TABLE `donations` ADD COLUMN IF NOT EXISTS `delivery_method` enum('volunteer_assist','self_delivery') DEFAULT 'volunteer_assist' AFTER `evaluation_status`;
ALTER TABLE `donations` ADD COLUMN IF NOT EXISTS `approval_notes` text DEFAULT NULL AFTER `delivery_method`;
ALTER TABLE `donations` ADD COLUMN IF NOT EXISTS `approved_at` datetime DEFAULT NULL AFTER `approval_notes`;
ALTER TABLE `donations` ADD COLUMN IF NOT EXISTS `approved_by` int(11) DEFAULT NULL AFTER `approved_at`;
ALTER TABLE `donations` ADD COLUMN IF NOT EXISTS `rejection_reason` text DEFAULT NULL AFTER `approved_by`;
ALTER TABLE `donations` ADD COLUMN IF NOT EXISTS `rejected_at` datetime DEFAULT NULL AFTER `rejection_reason`;
ALTER TABLE `donations` ADD COLUMN IF NOT EXISTS `published_at` datetime DEFAULT NULL AFTER `rejected_at`;
ALTER TABLE `donations` ADD COLUMN IF NOT EXISTS `current_status` enum('waiting_pickup','volunteer_received','in_transit','at_foodbank','inspection_complete') DEFAULT 'waiting_pickup' AFTER `published_at`;
ALTER TABLE `donations` ADD COLUMN IF NOT EXISTS `status_updated_at` datetime DEFAULT NULL AFTER `current_status`;
ALTER TABLE `donations` ADD COLUMN IF NOT EXISTS `split_count` int(11) DEFAULT 1 AFTER `status_updated_at`;
ALTER TABLE `donations` ADD COLUMN IF NOT EXISTS `seal_code` varchar(50) DEFAULT NULL AFTER `split_count`;
ALTER TABLE `donations` ADD COLUMN IF NOT EXISTS `need_inspection` tinyint(1) DEFAULT 1 AFTER `seal_code`;
ALTER TABLE `donations` ADD COLUMN IF NOT EXISTS `inspection_notes` text DEFAULT NULL AFTER `need_inspection`;

-- 為 deliveries 表添加 seal_code 以追蹤防拆貼紙
ALTER TABLE `deliveries` ADD COLUMN IF NOT EXISTS `seal_code` varchar(50) DEFAULT NULL AFTER `weight_kg`;

-- 創建物資分配日誌表（用於追蹤拆單）
CREATE TABLE IF NOT EXISTS `donation_allocations` (
  `allocation_id` int(11) NOT NULL AUTO_INCREMENT,
  `donation_id` int(11) NOT NULL,
  `allocation_number` int(11) NOT NULL DEFAULT 1,
  `quantity` decimal(10,2) NOT NULL,
  `unit` varchar(20) DEFAULT NULL,
  `status` enum('pending','assigned','in_transit','completed') DEFAULT 'pending',
  `assigned_to` int(11) DEFAULT NULL,
  `assigned_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`allocation_id`),
  KEY `donation_id` (`donation_id`),
  KEY `status` (`status`),
  CONSTRAINT `donation_allocations_ibfk_1` FOREIGN KEY (`donation_id`) REFERENCES `donations` (`donation_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 索引優化
CREATE INDEX IF NOT EXISTS `idx_donations_evaluation_status` ON `donations` (`evaluation_status`);
CREATE INDEX IF NOT EXISTS `idx_donations_published_at` ON `donations` (`published_at`);
CREATE INDEX IF NOT EXISTS `idx_donations_seal_code` ON `donations` (`seal_code`);
