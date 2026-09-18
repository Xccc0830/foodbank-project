-- 創建商家獎勵方案表
CREATE TABLE IF NOT EXISTS `donor_reward_items` (
  `item_id` int(11) NOT NULL AUTO_INCREMENT,
  `donor_id` int(11) NOT NULL,
  `title` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `cost_points` int(11) NOT NULL,
  `stock` int(11) DEFAULT NULL,
  `category` enum('discount','product','experience','other') DEFAULT 'other',
  `redemption_code` varchar(50) DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`item_id`),
  KEY `donor_id` (`donor_id`),
  KEY `status` (`status`),
  CONSTRAINT `donor_reward_items_ibfk_1` FOREIGN KEY (`donor_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 創建商家獎勵兌換紀錄表
CREATE TABLE IF NOT EXISTS `donor_reward_redemptions` (
  `redemption_id` int(11) NOT NULL AUTO_INCREMENT,
  `item_id` int(11) NOT NULL,
  `volunteer_id` int(11) NOT NULL,
  `donor_id` int(11) NOT NULL,
  `points_spent` int(11) NOT NULL,
  `status` enum('pending','fulfilled','cancelled') NOT NULL DEFAULT 'pending',
  `redemption_code_used` varchar(50) DEFAULT NULL,
  `fulfilled_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`redemption_id`),
  KEY `item_id` (`item_id`),
  KEY `volunteer_id` (`volunteer_id`),
  KEY `donor_id` (`donor_id`),
  KEY `status` (`status`),
  CONSTRAINT `donor_reward_redemptions_ibfk_1` FOREIGN KEY (`item_id`) REFERENCES `donor_reward_items` (`item_id`) ON DELETE CASCADE,
  CONSTRAINT `donor_reward_redemptions_ibfk_2` FOREIGN KEY (`volunteer_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  CONSTRAINT `donor_reward_redemptions_ibfk_3` FOREIGN KEY (`donor_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
