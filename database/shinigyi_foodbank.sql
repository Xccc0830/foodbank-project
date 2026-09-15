-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- 主機： localhost
-- 產生時間： 2026 年 09 月 15 日 08:33
-- 伺服器版本： 10.4.28-MariaDB
-- PHP 版本： 8.2.4

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- 資料庫： `shinigyi_foodbank`
--

-- --------------------------------------------------------

--
-- 資料表結構 `activities`
--

CREATE TABLE `activities` (
  `activity_id` int(11) NOT NULL,
  `title` varchar(150) NOT NULL,
  `activity_type` enum('donation_drive','briefing','cleanup','promotion','other') NOT NULL DEFAULT 'other',
  `description` text DEFAULT NULL,
  `start_at` datetime NOT NULL,
  `end_at` datetime DEFAULT NULL,
  `capacity` int(11) DEFAULT NULL,
  `status` enum('planned','ongoing','completed','cancelled') NOT NULL DEFAULT 'planned',
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- 傾印資料表的資料 `activities`
--

INSERT INTO `activities` (`activity_id`, `title`, `activity_type`, `description`, `start_at`, `end_at`, `capacity`, `status`, `created_by`, `created_at`) VALUES
(3, '文山區惜食募集日', 'donation_drive', '協助整理與募集社區剩食物資。', '2026-09-17 14:02:07', '2026-09-17 18:02:07', 20, 'planned', NULL, '2026-09-15 06:02:07'),
(4, '食安運送志工說明會', 'briefing', '認識防拆貼紙、冷鏈運送與異常回報流程。', '2026-09-20 14:02:07', '2026-09-20 16:02:07', 30, 'planned', NULL, '2026-09-15 06:02:07');

-- --------------------------------------------------------

--
-- 資料表結構 `activity_assignments`
--

CREATE TABLE `activity_assignments` (
  `assignment_id` int(11) NOT NULL,
  `activity_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `status` enum('registered','attended','cancelled') NOT NULL DEFAULT 'registered',
  `cancelled_at` datetime DEFAULT NULL,
  `points` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `assignment_type` enum('individual','company') NOT NULL DEFAULT 'individual',
  `organization_name` varchar(150) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- 資料表結構 `audit_logs`
--

CREATE TABLE `audit_logs` (
  `log_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `action_type` varchar(100) NOT NULL,
  `table_name` varchar(100) DEFAULT NULL,
  `record_id` int(11) DEFAULT NULL,
  `old_values` text DEFAULT NULL,
  `new_values` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `action_timestamp` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- 資料表結構 `beneficiaries`
--

CREATE TABLE `beneficiaries` (
  `beneficiary_id` int(11) NOT NULL,
  `beneficiary_code` varchar(50) NOT NULL,
  `first_name` varchar(50) NOT NULL,
  `last_name` varchar(50) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `city` varchar(50) DEFAULT NULL,
  `postal_code` varchar(20) DEFAULT NULL,
  `family_size` int(11) DEFAULT NULL,
  `income_level` enum('low','medium','high') NOT NULL,
  `registration_date` date NOT NULL,
  `status` enum('active','inactive','suspended') DEFAULT 'active',
  `case_worker_id` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- 傾印資料表的資料 `beneficiaries`
--

INSERT INTO `beneficiaries` (`beneficiary_id`, `beneficiary_code`, `first_name`, `last_name`, `email`, `phone`, `address`, `city`, `postal_code`, `family_size`, `income_level`, `registration_date`, `status`, `case_worker_id`, `notes`, `created_at`, `updated_at`) VALUES
(1, 'DEMO-BEN-001', '王小美', '陳', NULL, NULL, NULL, NULL, NULL, 4, 'low', '2026-09-15', 'active', NULL, 'Demo 展示用受益者', '2026-09-15 06:02:07', '2026-09-15 06:02:07'),
(2, 'DEMO-BEN-002', '林志明', '黃', NULL, NULL, NULL, NULL, NULL, 2, 'medium', '2026-09-15', 'active', NULL, 'Demo 展示用受益者', '2026-09-15 06:02:07', '2026-09-15 06:02:07');

-- --------------------------------------------------------

--
-- 資料表結構 `beneficiary_distributions`
--

CREATE TABLE `beneficiary_distributions` (
  `distribution_id` int(11) NOT NULL,
  `beneficiary_id` int(11) NOT NULL,
  `distribution_date` datetime NOT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `status` enum('pending','approved','completed','cancelled') DEFAULT 'pending',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- 資料表結構 `deliveries`
--

CREATE TABLE `deliveries` (
  `delivery_id` int(11) NOT NULL,
  `donation_id` int(11) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `volunteer_id` int(11) DEFAULT NULL,
  `vehicle_type` enum('car','motorcycle') NOT NULL,
  `total_distance_km` decimal(8,2) NOT NULL DEFAULT 0.00,
  `weight_kg` decimal(8,2) NOT NULL DEFAULT 0.00,
  `urgency` enum('normal','priority','urgent') NOT NULL DEFAULT 'normal',
  `points` int(11) NOT NULL DEFAULT 0,
  `pickup_address` varchar(255) NOT NULL,
  `delivery_address` varchar(255) NOT NULL,
  `status` enum('open','claimed','picked_up','delivered','exception','cancelled') NOT NULL DEFAULT 'open',
  `exception_notes` text DEFAULT NULL,
  `delivered_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `pickup_confirmed_at` datetime DEFAULT NULL,
  `seal_intact` tinyint(1) DEFAULT NULL,
  `item_count_confirmed` tinyint(1) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- 傾印資料表的資料 `deliveries`
--

INSERT INTO `deliveries` (`delivery_id`, `donation_id`, `created_by`, `volunteer_id`, `vehicle_type`, `total_distance_km`, `weight_kg`, `urgency`, `points`, `pickup_address`, `delivery_address`, `status`, `exception_notes`, `delivered_at`, `created_at`, `updated_at`, `pickup_confirmed_at`, `seal_intact`, `item_count_confirmed`) VALUES
(3, 8, NULL, NULL, 'motorcycle', 4.50, 12.00, 'urgent', 24, '暖心好食店：台北市文山區', '忠信食物銀行', 'open', NULL, NULL, '2026-09-15 06:02:07', '2026-09-15 06:02:07', NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- 資料表結構 `departments`
--

CREATE TABLE `departments` (
  `department_id` int(11) NOT NULL,
  `department_code` varchar(50) NOT NULL,
  `department_name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `manager_id` int(11) DEFAULT NULL,
  `budget` decimal(12,2) DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- 資料表結構 `distribution_items`
--

CREATE TABLE `distribution_items` (
  `detail_id` int(11) NOT NULL,
  `distribution_id` int(11) NOT NULL,
  `inventory_id` int(11) NOT NULL,
  `quantity` decimal(10,2) NOT NULL,
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- 資料表結構 `donations`
--

CREATE TABLE `donations` (
  `donation_id` int(11) NOT NULL,
  `donor_id` int(11) DEFAULT NULL,
  `donor_name` varchar(100) NOT NULL,
  `donation_type` enum('food','supplies','money','other') NOT NULL,
  `quantity` decimal(10,2) DEFAULT NULL,
  `unit` varchar(20) DEFAULT NULL,
  `donation_date` datetime NOT NULL,
  `received_by` int(11) DEFAULT NULL,
  `status` enum('received','pending','assessed','approved','rejected','archived') DEFAULT 'pending',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `item_name` varchar(100) DEFAULT NULL,
  `weight_kg` decimal(10,2) DEFAULT NULL,
  `size_description` varchar(100) DEFAULT NULL,
  `expiry_date` date DEFAULT NULL,
  `pickup_deadline` datetime DEFAULT NULL,
  `delivery_option` enum('donor_delivery','volunteer_delivery','food_bank_pickup') DEFAULT 'volunteer_delivery',
  `vehicle_type` enum('car','motorcycle','none') DEFAULT 'none',
  `photo_path` varchar(255) DEFAULT NULL,
  `evaluation_notes` text DEFAULT NULL,
  `seal_code` varchar(30) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- 傾印資料表的資料 `donations`
--

INSERT INTO `donations` (`donation_id`, `donor_id`, `donor_name`, `donation_type`, `quantity`, `unit`, `donation_date`, `received_by`, `status`, `notes`, `created_at`, `updated_at`, `item_name`, `weight_kg`, `size_description`, `expiry_date`, `pickup_deadline`, `delivery_option`, `vehicle_type`, `photo_path`, `evaluation_notes`, `seal_code`) VALUES
(8, 8, '暖心好食店', 'food', 20.00, '份', '2026-09-15 14:02:07', NULL, 'approved', 'Demo 展示用物資', '2026-09-15 06:02:07', '2026-09-15 06:02:07', '愛心便當', 12.00, '中型保冷箱 2 箱', '2026-09-17', '2026-09-15 22:02:07', 'volunteer_delivery', 'motorcycle', NULL, NULL, 'FB-DEMO001'),
(9, 8, '綠野超市', 'food', 8.00, '箱', '2026-09-15 14:02:07', NULL, 'pending', '請官方人員進行食安評估', '2026-09-15 06:02:07', '2026-09-15 06:02:07', '新鮮蔬果', 25.00, '大型紙箱 8 箱', '2026-09-16', '2026-09-15 19:02:07', 'food_bank_pickup', 'car', NULL, NULL, NULL),
(10, NULL, '清心福泉', 'food', 1.00, '包', '2026-09-15 08:20:56', NULL, 'pending', '', '2026-09-15 06:20:56', '2026-09-15 06:20:56', '珍珠', 3.00, '一包', '2026-09-18', '2026-09-17 16:30:00', 'volunteer_delivery', 'motorcycle', 'uploads/donations/donation_20260915_082056_5f97d060c728.jpg', NULL, NULL);

-- --------------------------------------------------------

--
-- 資料表結構 `donors`
--

CREATE TABLE `donors` (
  `donor_id` int(11) NOT NULL,
  `donor_code` varchar(50) NOT NULL,
  `donor_name` varchar(100) NOT NULL,
  `donor_type` enum('individual','company','organization') NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `contact_person` varchar(100) DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `total_donations` decimal(12,2) DEFAULT 0.00,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- 資料表結構 `inventory`
--

CREATE TABLE `inventory` (
  `inventory_id` int(11) NOT NULL,
  `item_code` varchar(50) NOT NULL,
  `item_name` varchar(100) NOT NULL,
  `category` varchar(50) NOT NULL,
  `description` text DEFAULT NULL,
  `quantity_on_hand` decimal(10,2) NOT NULL DEFAULT 0.00,
  `reorder_level` decimal(10,2) DEFAULT NULL,
  `unit` varchar(20) DEFAULT NULL,
  `location` varchar(100) DEFAULT NULL,
  `expiry_date` date DEFAULT NULL,
  `last_updated` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `status` enum('available','low_stock','expired','removed') DEFAULT 'available'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- 傾印資料表的資料 `inventory`
--

INSERT INTO `inventory` (`inventory_id`, `item_code`, `item_name`, `category`, `description`, `quantity_on_hand`, `reorder_level`, `unit`, `location`, `expiry_date`, `last_updated`, `status`) VALUES
(1, 'DEMO-RICE-001', '白米', '乾糧', NULL, 42.00, 10.00, '袋', 'A-01', '2027-03-14', '2026-09-15 06:02:07', 'available'),
(2, 'DEMO-VEG-001', '新鮮蔬菜', '蔬果', NULL, 6.00, 10.00, '箱', '冷藏區', '2026-09-18', '2026-09-15 06:02:07', 'low_stock'),
(3, 'DEMO-MILK-001', '鮮奶', '乳品', NULL, 18.00, 8.00, '瓶', '冷藏區', '2026-09-25', '2026-09-15 06:02:07', 'available');

-- --------------------------------------------------------

--
-- 資料表結構 `inventory_transactions`
--

CREATE TABLE `inventory_transactions` (
  `transaction_id` int(11) NOT NULL,
  `inventory_id` int(11) NOT NULL,
  `transaction_type` enum('in','out','adjustment','loss') NOT NULL,
  `quantity` decimal(10,2) NOT NULL,
  `reference_type` varchar(50) DEFAULT NULL,
  `reference_id` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `performed_by` int(11) DEFAULT NULL,
  `transaction_date` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- 資料表結構 `notifications`
--

CREATE TABLE `notifications` (
  `notification_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `title` varchar(150) NOT NULL,
  `message` text NOT NULL,
  `type` enum('info','success','warning','error') NOT NULL DEFAULT 'info',
  `read_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- 資料表結構 `otp_codes`
--

CREATE TABLE `otp_codes` (
  `otp_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `phone` varchar(20) NOT NULL,
  `purpose` enum('phone_verification','password_reset') NOT NULL,
  `code_hash` varchar(64) NOT NULL,
  `attempts` int(11) NOT NULL DEFAULT 0,
  `expires_at` datetime NOT NULL,
  `consumed_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- 資料表結構 `password_resets`
--

CREATE TABLE `password_resets` (
  `reset_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `token_hash` varchar(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- 資料表結構 `point_transactions`
--

CREATE TABLE `point_transactions` (
  `transaction_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `delivery_id` int(11) DEFAULT NULL,
  `points` int(11) NOT NULL,
  `transaction_type` enum('earned','redeemed','adjusted') NOT NULL DEFAULT 'earned',
  `description` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- 傾印資料表的資料 `point_transactions`
--

INSERT INTO `point_transactions` (`transaction_id`, `user_id`, `delivery_id`, `points`, `transaction_type`, `description`, `created_at`) VALUES
(3, 4, NULL, -30, 'redeemed', '兌換獎勵', '2026-08-23 06:50:40');

-- --------------------------------------------------------

--
-- 資料表結構 `public_relations`
--

CREATE TABLE `public_relations` (
  `pr_id` int(11) NOT NULL,
  `event_name` varchar(150) NOT NULL,
  `event_date` date NOT NULL,
  `event_type` enum('fundraiser','awareness','community','partnership','media') NOT NULL,
  `description` text DEFAULT NULL,
  `location` varchar(200) DEFAULT NULL,
  `organizer_id` int(11) DEFAULT NULL,
  `participants_count` int(11) DEFAULT NULL,
  `status` enum('planned','ongoing','completed','cancelled') DEFAULT 'planned',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- 資料表結構 `purchases`
--

CREATE TABLE `purchases` (
  `purchase_id` int(11) NOT NULL,
  `purchase_code` varchar(50) NOT NULL,
  `supplier_id` int(11) DEFAULT NULL,
  `supplier_name` varchar(100) NOT NULL,
  `purchase_date` date NOT NULL,
  `delivery_date` date DEFAULT NULL,
  `total_amount` decimal(12,2) DEFAULT NULL,
  `status` enum('draft','pending','approved','received','cancelled') DEFAULT 'draft',
  `requested_by` int(11) DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- 資料表結構 `purchase_items`
--

CREATE TABLE `purchase_items` (
  `purchase_item_id` int(11) NOT NULL,
  `purchase_id` int(11) NOT NULL,
  `item_name` varchar(100) NOT NULL,
  `quantity` decimal(10,2) NOT NULL,
  `unit_price` decimal(10,2) DEFAULT NULL,
  `total_price` decimal(12,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- 資料表結構 `reward_catalog`
--

CREATE TABLE `reward_catalog` (
  `reward_id` int(11) NOT NULL,
  `title` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `cost_points` int(11) NOT NULL,
  `stock` int(11) DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- 傾印資料表的資料 `reward_catalog`
--

INSERT INTO `reward_catalog` (`reward_id`, `title`, `description`, `cost_points`, `stock`, `status`, `created_at`) VALUES
(1, '愛心店家 9 折優惠券', '合作店家消費可折抵，掃描店家 QR Code 自行扣點', 30, NULL, 'active', '2026-08-23 06:35:36'),
(2, '食物銀行公益禮盒', '兌換一份食物銀行整理的公益物資禮盒', 80, 20, 'active', '2026-08-23 06:35:36'),
(3, '公益貢獻感謝狀', '累積貢獻達標即可換取實體感謝狀', 150, NULL, 'active', '2026-08-23 06:35:36');

-- --------------------------------------------------------

--
-- 資料表結構 `reward_redemptions`
--

CREATE TABLE `reward_redemptions` (
  `redemption_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `reward_id` int(11) NOT NULL,
  `points_spent` int(11) NOT NULL,
  `status` enum('pending','fulfilled','cancelled') NOT NULL DEFAULT 'pending',
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- 資料表結構 `sales`
--

CREATE TABLE `sales` (
  `sale_id` int(11) NOT NULL,
  `sale_code` varchar(50) NOT NULL,
  `sale_date` datetime NOT NULL,
  `customer_name` varchar(100) DEFAULT NULL,
  `customer_email` varchar(100) DEFAULT NULL,
  `total_amount` decimal(12,2) DEFAULT NULL,
  `payment_method` enum('cash','credit_card','check','transfer') NOT NULL,
  `status` enum('completed','pending','cancelled') DEFAULT 'completed',
  `notes` text DEFAULT NULL,
  `processed_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- 資料表結構 `sale_items`
--

CREATE TABLE `sale_items` (
  `sale_item_id` int(11) NOT NULL,
  `sale_id` int(11) NOT NULL,
  `inventory_id` int(11) NOT NULL,
  `quantity` decimal(10,2) NOT NULL,
  `unit_price` decimal(10,2) DEFAULT NULL,
  `total_price` decimal(12,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- 資料表結構 `suppliers`
--

CREATE TABLE `suppliers` (
  `supplier_id` int(11) NOT NULL,
  `supplier_code` varchar(50) NOT NULL,
  `supplier_name` varchar(100) NOT NULL,
  `contact_person` varchar(100) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `city` varchar(50) DEFAULT NULL,
  `postal_code` varchar(20) DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- 資料表結構 `users`
--

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `email` varchar(100) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `role` enum('admin','foodbank_staff','volunteer','donor') NOT NULL DEFAULT 'foodbank_staff',
  `department` varchar(50) DEFAULT NULL,
  `status` enum('active','inactive','suspended') DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_by` int(11) DEFAULT NULL,
  `phone_verified` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- 傾印資料表的資料 `users`
--

INSERT INTO `users` (`user_id`, `username`, `password`, `email`, `full_name`, `phone`, `role`, `department`, `status`, `created_at`, `updated_at`, `created_by`, `phone_verified`) VALUES
(1, 'admin', '$2y$10$mwRwKGIC21Jv1rzC99a/IOSMQqyLrggkn0JwceZ3cW.r0x01eh42e', 'admin@foodbank.local', '系統管理員', NULL, 'admin', NULL, 'active', '2026-08-18 16:12:45', '2026-09-15 06:14:35', NULL, 0),
(2, 'manager', '866485796cfa8d7c0cf7111640205b83076433547577511d81f8030ae99ecea5', 'manager@foodbank.local', '食物銀行主管', NULL, 'foodbank_staff', NULL, 'active', '2026-08-18 16:46:44', '2026-09-15 06:26:24', NULL, 0),
(3, 'staff', '10176e7b7b24d317acfcf8d2064cfd2f24e154f7b5a96603077d5ef813d6a6b6', 'staff@foodbank.local', '食物銀行人員', NULL, 'foodbank_staff', NULL, 'active', '2026-08-18 16:46:44', '2026-09-15 06:26:24', NULL, 0),
(4, 'volunteer', '25a21eab5feca60534fc732ff65e27984b61e43d0c7a4614b9710cd01456c37a', 'volunteer@foodbank.local', '平台志工', NULL, 'volunteer', NULL, 'active', '2026-08-18 16:46:44', '2026-09-15 06:26:24', NULL, 0),
(6, 'Xccc0830', '2e256634b197e5f0a14f7ceacd8db15359ae6f3ee6668977b256d557ad01a215', 'chesterhsu0830@gmail.com', '許策', NULL, 'volunteer', NULL, 'active', '2026-08-18 17:00:06', '2026-08-18 17:00:45', NULL, 0),
(7, 'official', '3fae19dadf1a05245ffa9cd28f3e4530dc42d16511f743883da4e0f5c70fdc12', 'official@foodbank.local', '官方審核人員', NULL, 'foodbank_staff', NULL, 'active', '2026-08-18 17:03:08', '2026-09-15 06:26:24', NULL, 0),
(8, 'donor', '0df8b21212b360c2862c2cce12a4f3d883f13acdc4b59f43cf5b2fcfd2c30954', 'donor@foodbank.local', '捐贈店家', NULL, 'donor', NULL, 'active', '2026-08-18 17:03:08', '2026-09-15 06:26:24', NULL, 0);

-- --------------------------------------------------------

--
-- 資料表結構 `volunteer_consents`
--

CREATE TABLE `volunteer_consents` (
  `user_id` int(11) NOT NULL,
  `agreed_disclaimer` tinyint(1) NOT NULL DEFAULT 0,
  `agreed_mutual_aid` tinyint(1) NOT NULL DEFAULT 0,
  `video_watched` tinyint(1) NOT NULL DEFAULT 0,
  `completed_at` datetime DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- 資料表結構 `warehouses`
--

CREATE TABLE `warehouses` (
  `warehouse_id` int(11) NOT NULL,
  `warehouse_code` varchar(50) NOT NULL,
  `warehouse_name` varchar(100) NOT NULL,
  `address` text NOT NULL,
  `city` varchar(50) DEFAULT NULL,
  `postal_code` varchar(20) DEFAULT NULL,
  `manager_id` int(11) DEFAULT NULL,
  `capacity` decimal(10,2) DEFAULT NULL,
  `current_usage` decimal(10,2) DEFAULT NULL,
  `status` enum('active','inactive','maintenance') DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- 已傾印資料表的索引
--

--
-- 資料表索引 `activities`
--
ALTER TABLE `activities`
  ADD PRIMARY KEY (`activity_id`);

--
-- 資料表索引 `activity_assignments`
--
ALTER TABLE `activity_assignments`
  ADD PRIMARY KEY (`assignment_id`),
  ADD UNIQUE KEY `unique_activity_user` (`activity_id`,`user_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `activity_status_cancelled_at` (`activity_id`,`user_id`,`status`,`cancelled_at`);

--
-- 資料表索引 `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD PRIMARY KEY (`log_id`),
  ADD KEY `action_timestamp` (`action_timestamp`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `action_type` (`action_type`);

--
-- 資料表索引 `beneficiaries`
--
ALTER TABLE `beneficiaries`
  ADD PRIMARY KEY (`beneficiary_id`),
  ADD UNIQUE KEY `beneficiary_code` (`beneficiary_code`),
  ADD KEY `case_worker_id` (`case_worker_id`),
  ADD KEY `status` (`status`),
  ADD KEY `registration_date` (`registration_date`),
  ADD KEY `income_level` (`income_level`),
  ADD KEY `idx_beneficiaries_status` (`status`);

--
-- 資料表索引 `beneficiary_distributions`
--
ALTER TABLE `beneficiary_distributions`
  ADD PRIMARY KEY (`distribution_id`),
  ADD KEY `beneficiary_id` (`beneficiary_id`),
  ADD KEY `approved_by` (`approved_by`),
  ADD KEY `distribution_date` (`distribution_date`),
  ADD KEY `status` (`status`);

--
-- 資料表索引 `deliveries`
--
ALTER TABLE `deliveries`
  ADD PRIMARY KEY (`delivery_id`),
  ADD KEY `status` (`status`),
  ADD KEY `volunteer_id` (`volunteer_id`),
  ADD KEY `donation_id` (`donation_id`);

--
-- 資料表索引 `departments`
--
ALTER TABLE `departments`
  ADD PRIMARY KEY (`department_id`),
  ADD UNIQUE KEY `department_code` (`department_code`),
  ADD KEY `manager_id` (`manager_id`),
  ADD KEY `status` (`status`);

--
-- 資料表索引 `distribution_items`
--
ALTER TABLE `distribution_items`
  ADD PRIMARY KEY (`detail_id`),
  ADD KEY `inventory_id` (`inventory_id`),
  ADD KEY `distribution_id` (`distribution_id`);

--
-- 資料表索引 `donations`
--
ALTER TABLE `donations`
  ADD PRIMARY KEY (`donation_id`),
  ADD KEY `donation_date` (`donation_date`),
  ADD KEY `status` (`status`),
  ADD KEY `received_by` (`received_by`),
  ADD KEY `idx_donations_status` (`status`);

--
-- 資料表索引 `donors`
--
ALTER TABLE `donors`
  ADD PRIMARY KEY (`donor_id`),
  ADD UNIQUE KEY `donor_code` (`donor_code`),
  ADD KEY `donor_type` (`donor_type`),
  ADD KEY `status` (`status`);

--
-- 資料表索引 `inventory`
--
ALTER TABLE `inventory`
  ADD PRIMARY KEY (`inventory_id`),
  ADD UNIQUE KEY `item_code` (`item_code`),
  ADD KEY `category` (`category`),
  ADD KEY `status` (`status`),
  ADD KEY `expiry_date` (`expiry_date`),
  ADD KEY `idx_inventory_category` (`category`);

--
-- 資料表索引 `inventory_transactions`
--
ALTER TABLE `inventory_transactions`
  ADD PRIMARY KEY (`transaction_id`),
  ADD KEY `inventory_id` (`inventory_id`),
  ADD KEY `performed_by` (`performed_by`),
  ADD KEY `transaction_date` (`transaction_date`),
  ADD KEY `transaction_type` (`transaction_type`);

--
-- 資料表索引 `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`notification_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `read_at` (`read_at`);

--
-- 資料表索引 `otp_codes`
--
ALTER TABLE `otp_codes`
  ADD PRIMARY KEY (`otp_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `purpose` (`purpose`);

--
-- 資料表索引 `password_resets`
--
ALTER TABLE `password_resets`
  ADD PRIMARY KEY (`reset_id`),
  ADD KEY `user_id` (`user_id`);

--
-- 資料表索引 `point_transactions`
--
ALTER TABLE `point_transactions`
  ADD PRIMARY KEY (`transaction_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `delivery_id` (`delivery_id`);

--
-- 資料表索引 `public_relations`
--
ALTER TABLE `public_relations`
  ADD PRIMARY KEY (`pr_id`),
  ADD KEY `organizer_id` (`organizer_id`),
  ADD KEY `event_date` (`event_date`),
  ADD KEY `event_type` (`event_type`),
  ADD KEY `status` (`status`);

--
-- 資料表索引 `purchases`
--
ALTER TABLE `purchases`
  ADD PRIMARY KEY (`purchase_id`),
  ADD UNIQUE KEY `purchase_code` (`purchase_code`),
  ADD KEY `requested_by` (`requested_by`),
  ADD KEY `approved_by` (`approved_by`),
  ADD KEY `purchase_date` (`purchase_date`),
  ADD KEY `status` (`status`),
  ADD KEY `supplier_name` (`supplier_name`),
  ADD KEY `idx_purchases_status` (`status`);

--
-- 資料表索引 `purchase_items`
--
ALTER TABLE `purchase_items`
  ADD PRIMARY KEY (`purchase_item_id`),
  ADD KEY `purchase_id` (`purchase_id`);

--
-- 資料表索引 `reward_catalog`
--
ALTER TABLE `reward_catalog`
  ADD PRIMARY KEY (`reward_id`);

--
-- 資料表索引 `reward_redemptions`
--
ALTER TABLE `reward_redemptions`
  ADD PRIMARY KEY (`redemption_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `reward_id` (`reward_id`);

--
-- 資料表索引 `sales`
--
ALTER TABLE `sales`
  ADD PRIMARY KEY (`sale_id`),
  ADD UNIQUE KEY `sale_code` (`sale_code`),
  ADD KEY `processed_by` (`processed_by`),
  ADD KEY `sale_date` (`sale_date`),
  ADD KEY `status` (`status`),
  ADD KEY `idx_sales_date` (`sale_date`);

--
-- 資料表索引 `sale_items`
--
ALTER TABLE `sale_items`
  ADD PRIMARY KEY (`sale_item_id`),
  ADD KEY `inventory_id` (`inventory_id`),
  ADD KEY `sale_id` (`sale_id`);

--
-- 資料表索引 `suppliers`
--
ALTER TABLE `suppliers`
  ADD PRIMARY KEY (`supplier_id`),
  ADD UNIQUE KEY `supplier_code` (`supplier_code`),
  ADD KEY `status` (`status`);

--
-- 資料表索引 `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `role` (`role`),
  ADD KEY `department` (`department`),
  ADD KEY `status` (`status`),
  ADD KEY `idx_users_role` (`role`);

--
-- 資料表索引 `volunteer_consents`
--
ALTER TABLE `volunteer_consents`
  ADD PRIMARY KEY (`user_id`);

--
-- 資料表索引 `warehouses`
--
ALTER TABLE `warehouses`
  ADD PRIMARY KEY (`warehouse_id`),
  ADD UNIQUE KEY `warehouse_code` (`warehouse_code`),
  ADD KEY `manager_id` (`manager_id`),
  ADD KEY `status` (`status`);

--
-- 在傾印的資料表使用自動遞增(AUTO_INCREMENT)
--

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `activities`
--
ALTER TABLE `activities`
  MODIFY `activity_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `activity_assignments`
--
ALTER TABLE `activity_assignments`
  MODIFY `assignment_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `audit_logs`
--
ALTER TABLE `audit_logs`
  MODIFY `log_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `beneficiaries`
--
ALTER TABLE `beneficiaries`
  MODIFY `beneficiary_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `beneficiary_distributions`
--
ALTER TABLE `beneficiary_distributions`
  MODIFY `distribution_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `deliveries`
--
ALTER TABLE `deliveries`
  MODIFY `delivery_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `departments`
--
ALTER TABLE `departments`
  MODIFY `department_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `distribution_items`
--
ALTER TABLE `distribution_items`
  MODIFY `detail_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `donations`
--
ALTER TABLE `donations`
  MODIFY `donation_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `donors`
--
ALTER TABLE `donors`
  MODIFY `donor_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `inventory`
--
ALTER TABLE `inventory`
  MODIFY `inventory_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `inventory_transactions`
--
ALTER TABLE `inventory_transactions`
  MODIFY `transaction_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `notifications`
--
ALTER TABLE `notifications`
  MODIFY `notification_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `otp_codes`
--
ALTER TABLE `otp_codes`
  MODIFY `otp_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `password_resets`
--
ALTER TABLE `password_resets`
  MODIFY `reset_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `point_transactions`
--
ALTER TABLE `point_transactions`
  MODIFY `transaction_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `public_relations`
--
ALTER TABLE `public_relations`
  MODIFY `pr_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `purchases`
--
ALTER TABLE `purchases`
  MODIFY `purchase_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `purchase_items`
--
ALTER TABLE `purchase_items`
  MODIFY `purchase_item_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `reward_catalog`
--
ALTER TABLE `reward_catalog`
  MODIFY `reward_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `reward_redemptions`
--
ALTER TABLE `reward_redemptions`
  MODIFY `redemption_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `sales`
--
ALTER TABLE `sales`
  MODIFY `sale_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `sale_items`
--
ALTER TABLE `sale_items`
  MODIFY `sale_item_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `suppliers`
--
ALTER TABLE `suppliers`
  MODIFY `supplier_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `warehouses`
--
ALTER TABLE `warehouses`
  MODIFY `warehouse_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 已傾印資料表的限制式
--

--
-- 資料表的限制式 `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD CONSTRAINT `audit_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`);

--
-- 資料表的限制式 `beneficiaries`
--
ALTER TABLE `beneficiaries`
  ADD CONSTRAINT `beneficiaries_ibfk_1` FOREIGN KEY (`case_worker_id`) REFERENCES `users` (`user_id`);

--
-- 資料表的限制式 `beneficiary_distributions`
--
ALTER TABLE `beneficiary_distributions`
  ADD CONSTRAINT `beneficiary_distributions_ibfk_1` FOREIGN KEY (`beneficiary_id`) REFERENCES `beneficiaries` (`beneficiary_id`),
  ADD CONSTRAINT `beneficiary_distributions_ibfk_2` FOREIGN KEY (`approved_by`) REFERENCES `users` (`user_id`);

--
-- 資料表的限制式 `departments`
--
ALTER TABLE `departments`
  ADD CONSTRAINT `departments_ibfk_1` FOREIGN KEY (`manager_id`) REFERENCES `users` (`user_id`);

--
-- 資料表的限制式 `distribution_items`
--
ALTER TABLE `distribution_items`
  ADD CONSTRAINT `distribution_items_ibfk_1` FOREIGN KEY (`distribution_id`) REFERENCES `beneficiary_distributions` (`distribution_id`),
  ADD CONSTRAINT `distribution_items_ibfk_2` FOREIGN KEY (`inventory_id`) REFERENCES `inventory` (`inventory_id`);

--
-- 資料表的限制式 `donations`
--
ALTER TABLE `donations`
  ADD CONSTRAINT `donations_ibfk_1` FOREIGN KEY (`received_by`) REFERENCES `users` (`user_id`);

--
-- 資料表的限制式 `inventory_transactions`
--
ALTER TABLE `inventory_transactions`
  ADD CONSTRAINT `inventory_transactions_ibfk_1` FOREIGN KEY (`inventory_id`) REFERENCES `inventory` (`inventory_id`),
  ADD CONSTRAINT `inventory_transactions_ibfk_2` FOREIGN KEY (`performed_by`) REFERENCES `users` (`user_id`);

--
-- 資料表的限制式 `public_relations`
--
ALTER TABLE `public_relations`
  ADD CONSTRAINT `public_relations_ibfk_1` FOREIGN KEY (`organizer_id`) REFERENCES `users` (`user_id`);

--
-- 資料表的限制式 `purchases`
--
ALTER TABLE `purchases`
  ADD CONSTRAINT `purchases_ibfk_1` FOREIGN KEY (`requested_by`) REFERENCES `users` (`user_id`),
  ADD CONSTRAINT `purchases_ibfk_2` FOREIGN KEY (`approved_by`) REFERENCES `users` (`user_id`);

--
-- 資料表的限制式 `purchase_items`
--
ALTER TABLE `purchase_items`
  ADD CONSTRAINT `purchase_items_ibfk_1` FOREIGN KEY (`purchase_id`) REFERENCES `purchases` (`purchase_id`);

--
-- 資料表的限制式 `sales`
--
ALTER TABLE `sales`
  ADD CONSTRAINT `sales_ibfk_1` FOREIGN KEY (`processed_by`) REFERENCES `users` (`user_id`);

--
-- 資料表的限制式 `sale_items`
--
ALTER TABLE `sale_items`
  ADD CONSTRAINT `sale_items_ibfk_1` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`sale_id`),
  ADD CONSTRAINT `sale_items_ibfk_2` FOREIGN KEY (`inventory_id`) REFERENCES `inventory` (`inventory_id`);

--
-- 資料表的限制式 `warehouses`
--
ALTER TABLE `warehouses`
  ADD CONSTRAINT `warehouses_ibfk_1` FOREIGN KEY (`manager_id`) REFERENCES `users` (`user_id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
