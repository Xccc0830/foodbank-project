-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- 主機： localhost
-- 產生時間： 2026 年 09 月 22 日 11:07
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
  `activity_type_detail` varchar(100) DEFAULT NULL,
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

INSERT INTO `activities` (`activity_id`, `title`, `activity_type`, `activity_type_detail`, `description`, `start_at`, `end_at`, `capacity`, `status`, `created_by`, `created_at`) VALUES
(3, '文山區惜食募集日', 'donation_drive', NULL, '協助整理與募集社區剩食物資。', '2026-09-17 14:02:07', '2026-09-17 18:02:07', 20, 'planned', NULL, '2026-09-15 06:02:07'),
(4, '食安運送志工說明會', 'briefing', NULL, '認識防拆貼紙、冷鏈運送與異常回報流程。', '2026-09-20 14:02:07', '2026-09-20 16:02:07', 30, 'planned', NULL, '2026-09-15 06:02:07'),
(5, '淨灘', 'cleanup', NULL, '白沙灣淨灘活動，天氣不佳則日期順延', '2026-09-30 14:57:00', NULL, 50, 'planned', 1, '2026-09-18 06:57:28');

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
  `cancellation_reason` varchar(500) DEFAULT NULL,
  `points` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `assignment_type` enum('individual','company') NOT NULL DEFAULT 'individual',
  `organization_name` varchar(150) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- 傾印資料表的資料 `activity_assignments`
--

INSERT INTO `activity_assignments` (`assignment_id`, `activity_id`, `user_id`, `status`, `cancelled_at`, `cancellation_reason`, `points`, `created_at`, `assignment_type`, `organization_name`) VALUES
(4, 3, 12, 'registered', NULL, NULL, 5, '2026-09-16 08:41:39', 'individual', NULL),
(5, 4, 12, 'registered', NULL, NULL, 5, '2026-09-16 08:42:06', 'individual', NULL),
(6, 5, 4, 'registered', NULL, NULL, 5, '2026-09-18 07:05:28', 'individual', NULL),
(7, 4, 4, 'registered', NULL, NULL, 5, '2026-09-18 07:05:29', 'individual', NULL),
(8, 3, 4, 'registered', NULL, NULL, 5, '2026-09-18 07:05:30', 'individual', NULL);

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
  `delivery_method` enum('food_bank','volunteer','donor') NOT NULL DEFAULT 'volunteer',
  `created_by` int(11) DEFAULT NULL,
  `volunteer_id` int(11) DEFAULT NULL,
  `vehicle_type` enum('car','motorcycle') NOT NULL,
  `total_distance_km` decimal(8,2) NOT NULL DEFAULT 0.00,
  `weight_kg` decimal(8,2) NOT NULL DEFAULT 0.00,
  `seal_code` varchar(50) DEFAULT NULL,
  `urgency` enum('normal','priority','urgent') NOT NULL DEFAULT 'normal',
  `points` int(11) NOT NULL DEFAULT 0,
  `pickup_address` varchar(255) NOT NULL,
  `delivery_address` varchar(255) NOT NULL,
  `status` enum('open','claimed','picked_up','delivered','exception','cancelled') NOT NULL DEFAULT 'open',
  `exception_notes` text DEFAULT NULL,
  `exception_response` text DEFAULT NULL,
  `exception_resolved_at` datetime DEFAULT NULL,
  `exception_resolved_by` int(11) DEFAULT NULL,
  `delivered_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `pickup_confirmed_at` datetime DEFAULT NULL,
  `seal_intact` tinyint(1) DEFAULT NULL,
  `item_count_confirmed` tinyint(1) DEFAULT NULL,
  `item_category` varchar(50) DEFAULT NULL,
  `item_description` text DEFAULT NULL,
  `received_location` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- 傾印資料表的資料 `deliveries`
--

INSERT INTO `deliveries` (`delivery_id`, `donation_id`, `delivery_method`, `created_by`, `volunteer_id`, `vehicle_type`, `total_distance_km`, `weight_kg`, `seal_code`, `urgency`, `points`, `pickup_address`, `delivery_address`, `status`, `exception_notes`, `exception_response`, `exception_resolved_at`, `exception_resolved_by`, `delivered_at`, `created_at`, `updated_at`, `pickup_confirmed_at`, `seal_intact`, `item_count_confirmed`, `item_category`, `item_description`, `received_location`) VALUES
(3, 8, 'volunteer', NULL, NULL, 'motorcycle', 4.50, 12.00, NULL, 'urgent', 24, '暖心好食店：台北市文山區', '忠信食物銀行', 'open', NULL, NULL, NULL, NULL, NULL, '2026-09-15 06:02:07', '2026-09-15 06:02:07', NULL, NULL, NULL, NULL, NULL, NULL),
(4, 16, 'volunteer', NULL, 4, 'motorcycle', 0.00, 23.00, '', 'normal', 0, '啊喔', '忠信食物銀行', 'delivered', NULL, NULL, NULL, NULL, '2026-09-22 14:21:18', '2026-09-22 06:15:53', '2026-09-22 06:21:18', NULL, NULL, NULL, 'food', '白米飯', NULL),
(5, 13, 'volunteer', NULL, 4, 'motorcycle', 0.00, 15.00, '', 'normal', 0, 'sabee', '忠信食物銀行', 'delivered', NULL, NULL, NULL, NULL, '2026-09-22 15:19:33', '2026-09-22 06:23:57', '2026-09-22 07:19:33', '2026-09-22 15:19:15', NULL, NULL, 'food', '巴沙魚', NULL),
(6, 12, 'volunteer', NULL, 4, 'motorcycle', 0.00, 10.00, '', 'normal', 0, 'QQ', '忠信食物銀行', 'delivered', NULL, NULL, NULL, NULL, '2026-09-22 15:18:17', '2026-09-22 06:24:02', '2026-09-22 07:18:17', '2026-09-22 15:17:45', NULL, NULL, 'supplies', '衛生紙', NULL),
(7, 17, 'volunteer', NULL, 4, 'motorcycle', 0.00, 23.00, '', 'normal', 0, '台北', '忠信食物銀行', 'delivered', NULL, NULL, NULL, NULL, '2026-09-22 15:19:29', '2026-09-22 06:48:12', '2026-09-22 07:19:29', '2026-09-22 15:19:13', NULL, NULL, 'supplies', '1', NULL),
(8, 18, 'volunteer', NULL, 4, 'car', 0.00, 24.00, '', 'normal', 0, '台北', '忠信食物銀行', 'delivered', NULL, NULL, NULL, NULL, '2026-09-22 15:19:26', '2026-09-22 07:18:33', '2026-09-22 07:19:26', '2026-09-22 15:19:11', NULL, NULL, 'food', '衛生紙', NULL);

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
  `status` enum('received','pending','assessed','approved','rejected','published','archived') DEFAULT 'pending',
  `evaluation_status` enum('pending','approved_volunteer','approved_self_delivery','published','rejected') DEFAULT 'pending',
  `delivery_method` enum('volunteer_assist','self_delivery') DEFAULT 'volunteer_assist',
  `approval_notes` text DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `rejected_at` datetime DEFAULT NULL,
  `published_at` datetime DEFAULT NULL,
  `current_status` enum('waiting_pickup','volunteer_received','in_transit','at_foodbank','inspection_complete') DEFAULT 'waiting_pickup',
  `status_updated_at` datetime DEFAULT NULL,
  `split_count` int(11) DEFAULT 1,
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
  `seal_code` varchar(30) DEFAULT NULL,
  `need_inspection` tinyint(1) DEFAULT 1,
  `inspection_notes` text DEFAULT NULL,
  `donor_address` varchar(255) DEFAULT NULL,
  `reward_options` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- 傾印資料表的資料 `donations`
--

INSERT INTO `donations` (`donation_id`, `donor_id`, `donor_name`, `donation_type`, `quantity`, `unit`, `donation_date`, `received_by`, `status`, `evaluation_status`, `delivery_method`, `approval_notes`, `approved_at`, `approved_by`, `rejection_reason`, `rejected_at`, `published_at`, `current_status`, `status_updated_at`, `split_count`, `notes`, `created_at`, `updated_at`, `item_name`, `weight_kg`, `size_description`, `expiry_date`, `pickup_deadline`, `delivery_option`, `vehicle_type`, `photo_path`, `evaluation_notes`, `seal_code`, `need_inspection`, `inspection_notes`, `donor_address`, `reward_options`) VALUES
(8, 8, '暖心好食店', 'food', 20.00, '份', '2026-09-15 14:02:07', NULL, 'approved', 'pending', 'volunteer_assist', NULL, NULL, NULL, NULL, NULL, NULL, 'waiting_pickup', NULL, 1, 'Demo 展示用物資', '2026-09-15 06:02:07', '2026-09-15 06:02:07', '愛心便當', 12.00, '中型保冷箱 2 箱', '2026-09-17', '2026-09-15 22:02:07', 'volunteer_delivery', 'motorcycle', NULL, NULL, 'FB-DEMO001', 1, NULL, NULL, NULL),
(9, 8, '綠野超市', 'food', 8.00, '箱', '2026-09-15 14:02:07', NULL, 'published', 'approved_volunteer', 'volunteer_assist', NULL, '2026-09-22 02:12:46', NULL, NULL, NULL, '2026-09-22 02:12:46', 'waiting_pickup', NULL, 1, '請官方人員進行食安評估', '2026-09-15 06:02:07', '2026-09-21 18:12:46', '新鮮蔬果', 25.00, '大型紙箱 8 箱', '2026-09-16', '2026-09-15 19:02:00', 'volunteer_delivery', 'car', NULL, NULL, NULL, 1, NULL, NULL, '[\"points\",\"goods\",\"free\",\"service_hours\"]'),
(10, NULL, '清心福泉', 'food', 1.00, '包', '2026-09-15 08:20:56', NULL, 'pending', 'pending', 'volunteer_assist', NULL, NULL, NULL, NULL, NULL, NULL, 'waiting_pickup', NULL, 1, '', '2026-09-15 06:20:56', '2026-09-15 06:20:56', '珍珠', 3.00, '一包', '2026-09-18', '2026-09-17 16:30:00', 'volunteer_delivery', 'motorcycle', 'uploads/donations/donation_20260915_082056_5f97d060c728.jpg', NULL, NULL, 1, NULL, NULL, NULL),
(11, 11, '幸福超市', 'food', 1.00, '包', '2026-09-16 09:39:43', NULL, 'approved', 'pending', 'volunteer_assist', NULL, NULL, NULL, NULL, NULL, NULL, 'waiting_pickup', NULL, 1, '', '2026-09-16 07:39:43', '2026-09-16 08:33:52', '珍珠', 2.00, '一包', '2026-09-19', '2026-09-17 15:39:00', 'volunteer_delivery', 'motorcycle', 'uploads/donations/donation_20260916_093943_9c3cb955c97d.jpg', '', 'FB-82065837', 1, NULL, NULL, NULL),
(12, 13, 'QQ', 'supplies', 30.00, '件', '2026-09-19 17:44:28', NULL, 'published', 'approved_volunteer', 'volunteer_assist', NULL, '2026-09-19 23:59:27', NULL, NULL, NULL, '2026-09-22 14:24:02', 'waiting_pickup', NULL, 1, '運送評估：貨車', '2026-09-19 09:44:28', '2026-09-22 06:24:02', '衛生紙', 10.00, '12 × 15 × 23 cm', '2026-09-19', '2026-09-26 23:44:00', 'food_bank_pickup', 'none', NULL, '', NULL, 1, NULL, NULL, '[\"points\",\"goods\",\"free\",\"service_hours\"]'),
(13, 13, 'sabee', 'food', 3.00, '條', '2026-09-19 18:14:42', NULL, 'published', 'approved_volunteer', 'volunteer_assist', NULL, '2026-09-20 00:15:18', NULL, NULL, NULL, '2026-09-22 14:23:57', 'waiting_pickup', NULL, 1, '物資類型細項：生鮮食品; 運送評估：貨車', '2026-09-19 10:14:42', '2026-09-22 06:23:57', '巴沙魚', 15.00, '11 × 25 × 35 cm', '2026-09-20', '2026-09-20 00:14:00', 'food_bank_pickup', 'none', NULL, '', NULL, 1, NULL, NULL, '[\"points\",\"goods\",\"free\",\"service_hours\"]'),
(14, 13, 'Pigpig', 'food', 50.00, '袋', '2026-09-19 18:26:18', NULL, 'assessed', 'rejected', 'self_delivery', NULL, NULL, NULL, '不好吃', '2026-09-20 00:26:44', NULL, 'waiting_pickup', NULL, 1, '運送評估：貨車', '2026-09-19 10:26:18', '2026-09-19 16:26:44', '豬肉', 90.00, '12 × 12 × 12 cm', '2026-09-17', '2026-09-25 00:26:00', 'donor_delivery', 'none', NULL, NULL, NULL, 1, NULL, NULL, NULL),
(15, 13, '123', 'supplies', 23.00, '瓶', '2026-09-19 18:35:14', NULL, 'published', 'approved_volunteer', 'volunteer_assist', NULL, '2026-09-20 00:35:38', NULL, NULL, NULL, '2026-09-22 13:52:04', 'waiting_pickup', NULL, 1, '運送評估選項：機車', '2026-09-19 10:35:14', '2026-09-22 05:52:04', '洗衣精', 89.00, '23 × 23 × 22.74 cm', '2026-09-19', '2026-09-26 00:35:00', 'food_bank_pickup', 'motorcycle', NULL, NULL, NULL, 1, NULL, NULL, '[\"points\",\"goods\",\"free\",\"service_hours\"]'),
(16, 13, '啊喔', 'food', 23.00, '包', '2026-09-19 18:41:58', NULL, 'published', 'approved_volunteer', 'volunteer_assist', NULL, '2026-09-20 00:45:29', NULL, NULL, NULL, '2026-09-22 14:15:53', 'waiting_pickup', NULL, 1, '運送評估選項：機車', '2026-09-19 10:41:58', '2026-09-22 06:15:53', '白米飯', 23.00, '23 × 23 × 23 cm', '2026-09-25', '2026-09-26 00:41:00', 'volunteer_delivery', 'motorcycle', NULL, NULL, NULL, 1, NULL, NULL, '[\"points\",\"goods\",\"free\",\"service_hours\"]'),
(17, 13, 'test1', 'supplies', 1093.00, '件', '2026-09-22 14:42:23', NULL, 'published', 'approved_volunteer', 'volunteer_assist', NULL, '2026-09-22 14:43:46', NULL, NULL, NULL, '2026-09-22 14:48:12', 'waiting_pickup', NULL, 1, '運送評估選項：貨車', '2026-09-22 06:42:23', '2026-09-22 06:48:12', '1', 23.00, '23 × 23 × 23 箱', '2026-09-04', '2026-09-25 14:41:00', 'food_bank_pickup', 'none', NULL, NULL, NULL, 1, NULL, '台北', '[\"points\",\"goods\",\"free\",\"service_hours\"]'),
(18, 13, 'test2', 'food', 24.00, '件', '2026-09-22 14:43:04', NULL, 'published', 'approved_volunteer', 'volunteer_assist', NULL, '2026-09-22 14:43:23', NULL, NULL, NULL, '2026-09-22 15:18:33', 'waiting_pickup', NULL, 1, '運送評估選項：汽車', '2026-09-22 06:43:04', '2026-09-22 07:18:33', '衛生紙', 24.00, '24 × 24 × 24 cm', '2026-09-29', '2026-09-18 14:42:00', 'food_bank_pickup', 'car', NULL, NULL, NULL, 1, NULL, '台北', '[\"points\",\"goods\",\"free\",\"service_hours\"]');

-- --------------------------------------------------------

--
-- 資料表結構 `donation_allocations`
--

CREATE TABLE `donation_allocations` (
  `allocation_id` int(11) NOT NULL,
  `donation_id` int(11) NOT NULL,
  `allocation_number` int(11) NOT NULL DEFAULT 1,
  `quantity` decimal(10,2) NOT NULL,
  `unit` varchar(20) DEFAULT NULL,
  `status` enum('pending','assigned','in_transit','completed') DEFAULT 'pending',
  `assigned_to` int(11) DEFAULT NULL,
  `assigned_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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
-- 資料表結構 `donor_reward_items`
--

CREATE TABLE `donor_reward_items` (
  `item_id` int(11) NOT NULL,
  `donor_id` int(11) NOT NULL,
  `title` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `cost_points` int(11) NOT NULL,
  `stock` int(11) DEFAULT NULL,
  `category` enum('discount','product','experience','other') DEFAULT 'other',
  `redemption_code` varchar(50) DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- 傾印資料表的資料 `donor_reward_items`
--

INSERT INTO `donor_reward_items` (`item_id`, `donor_id`, `title`, `description`, `cost_points`, `stock`, `category`, `redemption_code`, `status`, `created_at`, `updated_at`) VALUES
(1, 13, '愛心店家 9 折優惠券', '合作店家消費可折抵，掃描店家 QR Code 自行扣點', 30, NULL, 'discount', NULL, 'active', '2026-08-23 06:35:36', '2026-08-23 06:35:36');

-- --------------------------------------------------------

--
-- 資料表結構 `donor_reward_redemptions`
--

CREATE TABLE `donor_reward_redemptions` (
  `redemption_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `volunteer_id` int(11) NOT NULL,
  `donor_id` int(11) NOT NULL,
  `points_spent` int(11) NOT NULL,
  `status` enum('pending','fulfilled','cancelled') NOT NULL DEFAULT 'pending',
  `redemption_code_used` varchar(50) DEFAULT NULL,
  `fulfilled_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- 資料表結構 `enterprise_verification_requests`
--

CREATE TABLE `enterprise_verification_requests` (
  `request_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `enterprise_name` varchar(150) NOT NULL,
  `business_registration_number` varchar(50) DEFAULT NULL,
  `business_license_url` text DEFAULT NULL,
  `submission_date` datetime DEFAULT current_timestamp(),
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `reviewer_id` int(11) DEFAULT NULL,
  `review_date` datetime DEFAULT NULL,
  `review_notes` text DEFAULT NULL
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
-- 資料表結構 `item_categories`
--

CREATE TABLE `item_categories` (
  `category_id` int(11) NOT NULL,
  `category_name` varchar(50) NOT NULL,
  `description` text DEFAULT NULL,
  `icon` varchar(50) DEFAULT NULL,
  `display_order` int(11) DEFAULT 0,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- 傾印資料表的資料 `item_categories`
--

INSERT INTO `item_categories` (`category_id`, `category_name`, `description`, `icon`, `display_order`, `status`, `created_at`, `updated_at`) VALUES
(1, '蔬菜', '新鮮蔬菜類', 'fa-solid fa-leaf', 1, 'active', '2026-09-18 06:46:26', '2026-09-18 06:46:26'),
(2, '水果', '新鮮水果類', 'fa-solid fa-apple-whole', 2, 'active', '2026-09-18 06:46:26', '2026-09-18 06:46:26'),
(3, '穀物', '米、麵粉等穀物類', 'fa-solid fa-wheat-awn', 3, 'active', '2026-09-18 06:46:26', '2026-09-18 06:46:26'),
(4, '乳製品', '牛奶、乳酪等乳製品', 'fa-solid fa-bottle-water', 4, 'active', '2026-09-18 06:46:26', '2026-09-18 06:46:26'),
(5, '肉類', '肉品、雞蛋等蛋白質', 'fa-solid fa-drumstick', 5, 'active', '2026-09-18 06:46:26', '2026-09-18 06:46:26'),
(6, '罐頭食品', '罐頭、瓶裝食品', 'fa-solid fa-jar', 6, 'active', '2026-09-18 06:46:26', '2026-09-18 06:46:26'),
(7, '乾貨', '乾物、豆類、堅果', 'fa-solid fa-bowl-rice', 7, 'active', '2026-09-18 06:46:26', '2026-09-18 06:46:26'),
(8, '飲料', '飲料、飲品類', 'fa-solid fa-mug-hot', 8, 'active', '2026-09-18 06:46:26', '2026-09-18 06:46:26'),
(9, '其他', '其他物資', 'fa-solid fa-box', 99, 'active', '2026-09-18 06:46:26', '2026-09-18 06:46:26');

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

--
-- 傾印資料表的資料 `notifications`
--

INSERT INTO `notifications` (`notification_id`, `user_id`, `title`, `message`, `type`, `read_at`, `created_at`) VALUES
(1, 1, '配送任務已接單', '配送任務 #4 已由志工接單。', 'info', NULL, '2026-09-22 06:16:10'),
(2, 2, '配送任務已接單', '配送任務 #4 已由志工接單。', 'info', '2026-09-22 14:21:29', '2026-09-22 06:16:10'),
(3, 3, '配送任務已接單', '配送任務 #4 已由志工接單。', 'info', NULL, '2026-09-22 06:16:10'),
(4, 7, '配送任務已接單', '配送任務 #4 已由志工接單。', 'info', NULL, '2026-09-22 06:16:10'),
(5, 4, '配送已完成', '配送任務 #4 已確認收貨，獲得 0 點公益點數。', 'success', '2026-09-22 14:22:32', '2026-09-22 06:21:18'),
(6, 1, '配送任務已接單', '配送任務 #5 已由志工接單。', 'info', NULL, '2026-09-22 06:24:51'),
(7, 2, '配送任務已接單', '配送任務 #5 已由志工接單。', 'info', '2026-09-22 15:22:51', '2026-09-22 06:24:51'),
(8, 3, '配送任務已接單', '配送任務 #5 已由志工接單。', 'info', NULL, '2026-09-22 06:24:51'),
(9, 7, '配送任務已接單', '配送任務 #5 已由志工接單。', 'info', NULL, '2026-09-22 06:24:51'),
(10, 1, '配送任務已接單', '配送任務 #6 已由志工接單。', 'info', NULL, '2026-09-22 06:53:54'),
(11, 2, '配送任務已接單', '配送任務 #6 已由志工接單。', 'info', '2026-09-22 15:22:52', '2026-09-22 06:53:54'),
(12, 3, '配送任務已接單', '配送任務 #6 已由志工接單。', 'info', NULL, '2026-09-22 06:53:54'),
(13, 7, '配送任務已接單', '配送任務 #6 已由志工接單。', 'info', NULL, '2026-09-22 06:53:54'),
(14, 1, '配送任務已接單', '配送任務 #7 已由志工接單。', 'info', NULL, '2026-09-22 06:54:26'),
(15, 2, '配送任務已接單', '配送任務 #7 已由志工接單。', 'info', '2026-09-22 15:22:53', '2026-09-22 06:54:26'),
(16, 3, '配送任務已接單', '配送任務 #7 已由志工接單。', 'info', NULL, '2026-09-22 06:54:26'),
(17, 7, '配送任務已接單', '配送任務 #7 已由志工接單。', 'info', NULL, '2026-09-22 06:54:26'),
(18, 1, '配送任務已接單', '配送任務 #7 已由志工接單。', 'info', NULL, '2026-09-22 07:18:50'),
(19, 2, '配送任務已接單', '配送任務 #7 已由志工接單。', 'info', '2026-09-22 15:22:54', '2026-09-22 07:18:50'),
(20, 3, '配送任務已接單', '配送任務 #7 已由志工接單。', 'info', NULL, '2026-09-22 07:18:50'),
(21, 7, '配送任務已接單', '配送任務 #7 已由志工接單。', 'info', NULL, '2026-09-22 07:18:50'),
(22, 1, '配送任務已接單', '配送任務 #8 已由志工接單。', 'info', NULL, '2026-09-22 07:18:54'),
(23, 2, '配送任務已接單', '配送任務 #8 已由志工接單。', 'info', '2026-09-22 15:22:55', '2026-09-22 07:18:54'),
(24, 3, '配送任務已接單', '配送任務 #8 已由志工接單。', 'info', NULL, '2026-09-22 07:18:54'),
(25, 7, '配送任務已接單', '配送任務 #8 已由志工接單。', 'info', NULL, '2026-09-22 07:18:54');

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
(3, 4, NULL, -30, 'redeemed', '兌換獎勵', '2026-08-23 06:50:40'),
(4, 4, 4, 0, 'earned', '完成惜食配送', '2026-09-22 06:21:18'),
(5, 4, 6, 0, 'earned', '完成惜食配送', '2026-09-22 07:18:17'),
(6, 4, 8, 0, 'earned', '完成惜食配送', '2026-09-22 07:19:26'),
(7, 4, 7, 0, 'earned', '完成惜食配送', '2026-09-22 07:19:29'),
(8, 4, 5, 0, 'earned', '完成惜食配送', '2026-09-22 07:19:33');

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

--
-- 傾印資料表的資料 `purchases`
--

INSERT INTO `purchases` (`purchase_id`, `purchase_code`, `supplier_id`, `supplier_name`, `purchase_date`, `delivery_date`, `total_amount`, `status`, `requested_by`, `approved_by`, `notes`, `created_at`, `updated_at`) VALUES
(1, 'PUR20260815001', 1, 'ABC 食品供應公司', '2026-08-15', '2026-08-20', 5000.00, 'pending', NULL, NULL, NULL, '2026-09-16 07:57:28', '2026-09-16 07:57:28'),
(2, 'PUR20260814001', 2, 'XYZ 商貿公司', '2026-08-14', '2026-08-18', 3500.00, 'approved', NULL, NULL, NULL, '2026-09-16 07:57:28', '2026-09-16 07:57:28');

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
(2, '食物銀行公益禮盒', '兌換一份食物銀行整理的公益物資禮盒', 80, 20, 'active', '2026-08-23 06:35:36'),
(3, '公益貢獻感謝狀', '累積貢獻達標即可換取實體感謝狀', 150, NULL, 'active', '2026-08-23 06:35:36');

-- --------------------------------------------------------

--
-- 資料表結構 `reward_claims`
--

CREATE TABLE `reward_claims` (
  `claim_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(11) NOT NULL,
  `source_type` enum('foodbank','donor') NOT NULL,
  `reward_id` int(11) DEFAULT NULL,
  `donor_item_id` int(11) DEFAULT NULL,
  `title` varchar(150) NOT NULL,
  `points_spent` int(11) NOT NULL,
  `status` enum('pending','fulfilled','cancelled') NOT NULL DEFAULT 'pending',
  `token_hash` char(64) NOT NULL,
  `token_value` char(64) DEFAULT NULL,
  `token_expires_at` datetime NOT NULL,
  `redeemed_at` datetime DEFAULT NULL,
  `redeemed_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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

--
-- 傾印資料表的資料 `suppliers`
--

INSERT INTO `suppliers` (`supplier_id`, `supplier_code`, `supplier_name`, `contact_person`, `email`, `phone`, `address`, `city`, `postal_code`, `status`, `created_at`, `updated_at`) VALUES
(1, 'ABC001', 'ABC 食品供應公司', '王經理', 'contact@abc.com', '010-1234-5678', NULL, '北京', NULL, 'active', '2026-09-16 07:57:28', '2026-09-16 07:57:28'),
(2, 'XYZ001', 'XYZ 商貿公司', '李主任', 'contact@xyz.com', '010-9876-5432', NULL, '上海', NULL, 'active', '2026-09-16 07:57:28', '2026-09-16 07:57:28');

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
  `phone_verified` tinyint(1) NOT NULL DEFAULT 0,
  `is_enterprise_verified` tinyint(1) DEFAULT 0,
  `enterprise_name` varchar(150) DEFAULT NULL,
  `enterprise_verified_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- 傾印資料表的資料 `users`
--

INSERT INTO `users` (`user_id`, `username`, `password`, `email`, `full_name`, `phone`, `role`, `department`, `status`, `created_at`, `updated_at`, `created_by`, `phone_verified`, `is_enterprise_verified`, `enterprise_name`, `enterprise_verified_at`) VALUES
(1, 'admin', '$2y$10$mwRwKGIC21Jv1rzC99a/IOSMQqyLrggkn0JwceZ3cW.r0x01eh42e', 'admin@foodbank.local', '系統管理員', NULL, 'admin', NULL, 'active', '2026-08-18 16:12:45', '2026-09-15 06:14:35', NULL, 0, 0, NULL, NULL),
(2, 'manager', '$2y$10$iL8M6sR5fDEijHGLU/dWqeHTuSusk4IjCJ5VAsz.OytAgc/2Dc7QG', 'manager@foodbank.local', '食物銀行主管', NULL, 'foodbank_staff', NULL, 'active', '2026-08-18 16:46:44', '2026-09-16 08:46:08', NULL, 0, 0, NULL, NULL),
(3, 'staff', '10176e7b7b24d317acfcf8d2064cfd2f24e154f7b5a96603077d5ef813d6a6b6', 'staff@foodbank.local', '食物銀行人員', NULL, 'foodbank_staff', NULL, 'active', '2026-08-18 16:46:44', '2026-09-15 06:26:24', NULL, 0, 0, NULL, NULL),
(4, 'volunteer', '$2y$10$ZWwCxo.mGqgE5GvPiR5nH.WhufmwuBbGPtJ0TEmEcqveablDm8uCK', 'volunteer@foodbank.local', '平台志工', NULL, 'volunteer', NULL, 'active', '2026-08-18 16:46:44', '2026-09-18 07:05:20', NULL, 0, 0, NULL, NULL),
(6, 'Xccc0830', '2e256634b197e5f0a14f7ceacd8db15359ae6f3ee6668977b256d557ad01a215', 'chesterhsu0830@gmail.com', '許策', NULL, 'volunteer', NULL, 'active', '2026-08-18 17:00:06', '2026-08-18 17:00:45', NULL, 0, 0, NULL, NULL),
(7, 'official', '3fae19dadf1a05245ffa9cd28f3e4530dc42d16511f743883da4e0f5c70fdc12', 'official@foodbank.local', '官方審核人員', NULL, 'foodbank_staff', NULL, 'active', '2026-08-18 17:03:08', '2026-09-15 06:26:24', NULL, 0, 0, NULL, NULL),
(8, 'donor', '0df8b21212b360c2862c2cce12a4f3d883f13acdc4b59f43cf5b2fcfd2c30954', 'donor@foodbank.local', '捐贈店家', NULL, 'donor', NULL, 'active', '2026-08-18 17:03:08', '2026-09-15 06:26:24', NULL, 0, 0, NULL, NULL),
(11, 'store_demo', '$2y$10$RUndnqZIN/Nw5FJx/X059eW0ZsjRovylNN8kt7k2HioHPuiQEGKMu', 'store_demo@foodbank.local', '幸福超市', '0912345678', 'donor', '零售部門', 'active', '2026-09-16 07:17:33', '2026-09-16 07:17:33', NULL, 1, 0, NULL, NULL),
(12, 'courier_demo', '$2y$10$v16TT2Uetokuln3OeVD7PuY0ZCqMM/nwKnFUFtJg1SzFmJhjknZjC', 'courier_demo@foodbank.local', '配送志工A', '0923456789', 'volunteer', '配送部門', 'active', '2026-09-16 07:17:33', '2026-09-16 07:17:33', NULL, 1, 0, NULL, NULL),
(13, 'love_store_001', '$2y$10$H/gfMvpdwPze2NoQkZVBwOS1ccKHVOT/HNc0/pxSaP9/F6IOaYRLG', 'store001@foodbank.local', '愛心商家001', NULL, 'donor', NULL, 'active', '2026-09-18 07:16:10', '2026-09-18 07:17:17', NULL, 0, 0, NULL, NULL);

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

--
-- 傾印資料表的資料 `volunteer_consents`
--

INSERT INTO `volunteer_consents` (`user_id`, `agreed_disclaimer`, `agreed_mutual_aid`, `video_watched`, `completed_at`, `updated_at`) VALUES
(12, 1, 1, 1, '2026-09-16 15:17:33', '2026-09-16 07:17:33');

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
  ADD KEY `idx_donations_status` (`status`),
  ADD KEY `idx_donations_evaluation_status` (`evaluation_status`),
  ADD KEY `idx_donations_published_at` (`published_at`),
  ADD KEY `idx_donations_seal_code` (`seal_code`);

--
-- 資料表索引 `donation_allocations`
--
ALTER TABLE `donation_allocations`
  ADD PRIMARY KEY (`allocation_id`),
  ADD KEY `donation_id` (`donation_id`),
  ADD KEY `status` (`status`);

--
-- 資料表索引 `donors`
--
ALTER TABLE `donors`
  ADD PRIMARY KEY (`donor_id`),
  ADD UNIQUE KEY `donor_code` (`donor_code`),
  ADD KEY `donor_type` (`donor_type`),
  ADD KEY `status` (`status`);

--
-- 資料表索引 `donor_reward_items`
--
ALTER TABLE `donor_reward_items`
  ADD PRIMARY KEY (`item_id`),
  ADD KEY `donor_id` (`donor_id`),
  ADD KEY `status` (`status`);

--
-- 資料表索引 `donor_reward_redemptions`
--
ALTER TABLE `donor_reward_redemptions`
  ADD PRIMARY KEY (`redemption_id`),
  ADD KEY `item_id` (`item_id`),
  ADD KEY `volunteer_id` (`volunteer_id`),
  ADD KEY `donor_id` (`donor_id`),
  ADD KEY `status` (`status`);

--
-- 資料表索引 `enterprise_verification_requests`
--
ALTER TABLE `enterprise_verification_requests`
  ADD PRIMARY KEY (`request_id`),
  ADD KEY `user_id` (`user_id`),
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
-- 資料表索引 `item_categories`
--
ALTER TABLE `item_categories`
  ADD PRIMARY KEY (`category_id`),
  ADD UNIQUE KEY `category_name` (`category_name`),
  ADD KEY `status` (`status`);

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
-- 資料表索引 `reward_claims`
--
ALTER TABLE `reward_claims`
  ADD PRIMARY KEY (`claim_id`),
  ADD UNIQUE KEY `uq_reward_claim_token` (`token_hash`),
  ADD KEY `idx_reward_claim_user` (`user_id`),
  ADD KEY `idx_reward_claim_status` (`status`);

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
  MODIFY `activity_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `activity_assignments`
--
ALTER TABLE `activity_assignments`
  MODIFY `assignment_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

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
  MODIFY `delivery_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

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
  MODIFY `donation_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `donation_allocations`
--
ALTER TABLE `donation_allocations`
  MODIFY `allocation_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `donors`
--
ALTER TABLE `donors`
  MODIFY `donor_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `donor_reward_items`
--
ALTER TABLE `donor_reward_items`
  MODIFY `item_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `donor_reward_redemptions`
--
ALTER TABLE `donor_reward_redemptions`
  MODIFY `redemption_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `enterprise_verification_requests`
--
ALTER TABLE `enterprise_verification_requests`
  MODIFY `request_id` int(11) NOT NULL AUTO_INCREMENT;

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
-- 使用資料表自動遞增(AUTO_INCREMENT) `item_categories`
--
ALTER TABLE `item_categories`
  MODIFY `category_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `notifications`
--
ALTER TABLE `notifications`
  MODIFY `notification_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=26;

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
  MODIFY `transaction_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `public_relations`
--
ALTER TABLE `public_relations`
  MODIFY `pr_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `purchases`
--
ALTER TABLE `purchases`
  MODIFY `purchase_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

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
-- 使用資料表自動遞增(AUTO_INCREMENT) `reward_claims`
--
ALTER TABLE `reward_claims`
  MODIFY `claim_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

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
  MODIFY `supplier_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

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
-- 資料表的限制式 `donation_allocations`
--
ALTER TABLE `donation_allocations`
  ADD CONSTRAINT `donation_allocations_ibfk_1` FOREIGN KEY (`donation_id`) REFERENCES `donations` (`donation_id`) ON DELETE CASCADE;

--
-- 資料表的限制式 `donor_reward_items`
--
ALTER TABLE `donor_reward_items`
  ADD CONSTRAINT `donor_reward_items_ibfk_1` FOREIGN KEY (`donor_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- 資料表的限制式 `donor_reward_redemptions`
--
ALTER TABLE `donor_reward_redemptions`
  ADD CONSTRAINT `donor_reward_redemptions_ibfk_1` FOREIGN KEY (`item_id`) REFERENCES `donor_reward_items` (`item_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `donor_reward_redemptions_ibfk_2` FOREIGN KEY (`volunteer_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `donor_reward_redemptions_ibfk_3` FOREIGN KEY (`donor_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- 資料表的限制式 `enterprise_verification_requests`
--
ALTER TABLE `enterprise_verification_requests`
  ADD CONSTRAINT `enterprise_verification_requests_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

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
