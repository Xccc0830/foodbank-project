-- 為用戶表添加企業驗證欄位
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `is_enterprise_verified` tinyint(1) DEFAULT 0 AFTER `phone_verified`;
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `enterprise_name` varchar(150) DEFAULT NULL AFTER `is_enterprise_verified`;
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `enterprise_verified_at` datetime DEFAULT NULL AFTER `enterprise_name`;

-- 創建企業驗證申請表
CREATE TABLE IF NOT EXISTS `enterprise_verification_requests` (
  `request_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `enterprise_name` varchar(150) NOT NULL,
  `business_registration_number` varchar(50) DEFAULT NULL,
  `business_license_url` text DEFAULT NULL,
  `submission_date` datetime DEFAULT current_timestamp(),
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `reviewer_id` int(11) DEFAULT NULL,
  `review_date` datetime DEFAULT NULL,
  `review_notes` text DEFAULT NULL,
  PRIMARY KEY (`request_id`),
  KEY `user_id` (`user_id`),
  KEY `status` (`status`),
  CONSTRAINT `enterprise_verification_requests_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
