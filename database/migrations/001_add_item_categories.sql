-- 物資分類管理表
CREATE TABLE IF NOT EXISTS `item_categories` (
  `category_id` int(11) NOT NULL AUTO_INCREMENT,
  `category_name` varchar(50) NOT NULL UNIQUE,
  `description` text DEFAULT NULL,
  `icon` varchar(50) DEFAULT NULL,
  `display_order` int(11) DEFAULT 0,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`category_id`),
  KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 預設物資分類
INSERT IGNORE INTO `item_categories` (`category_name`, `description`, `icon`, `display_order`, `status`) VALUES
('蔬菜', '新鮮蔬菜類', 'fa-solid fa-leaf', 1, 'active'),
('水果', '新鮮水果類', 'fa-solid fa-apple-whole', 2, 'active'),
('穀物', '米、麵粉等穀物類', 'fa-solid fa-wheat-awn', 3, 'active'),
('乳製品', '牛奶、乳酪等乳製品', 'fa-solid fa-bottle-water', 4, 'active'),
('肉類', '肉品、雞蛋等蛋白質', 'fa-solid fa-drumstick', 5, 'active'),
('罐頭食品', '罐頭、瓶裝食品', 'fa-solid fa-jar', 6, 'active'),
('乾貨', '乾物、豆類、堅果', 'fa-solid fa-bowl-rice', 7, 'active'),
('飲料', '飲料、飲品類', 'fa-solid fa-mug-hot', 8, 'active'),
('其他', '其他物資', 'fa-solid fa-box', 99, 'active');

-- 新增欄位追蹤物資捐贈過程
ALTER TABLE `deliveries` ADD COLUMN IF NOT EXISTS `item_category` varchar(50) DEFAULT NULL;
ALTER TABLE `deliveries` ADD COLUMN IF NOT EXISTS `item_description` text DEFAULT NULL;
ALTER TABLE `deliveries` ADD COLUMN IF NOT EXISTS `received_location` varchar(100) DEFAULT NULL;
