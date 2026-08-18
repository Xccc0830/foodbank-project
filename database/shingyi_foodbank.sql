-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- 主機： 127.0.0.1
-- 產生時間： 2026-08-18 18:29:19
-- 伺服器版本： 10.4.32-MariaDB
-- PHP 版本： 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- 資料庫： `shingyi_foodbank`
--

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
  `status` enum('received','pending','rejected','archived') DEFAULT 'pending',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



CREATE TABLE `donors` (
  `donor_id` int(11) NOT NULL,
  `donor_code` varchar(50) NOT NULL,
  `donor_name` varchar(100) NOT NULL,
  `donor_type` enum('individual','company','organization') NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `contact_person` varchar(100) DEFAULT NULL,
  `status` enum('received','pending','assessed','approved','rejected','archived') DEFAULT 'pending',
  `total_donations` decimal(12,2) DEFAULT 0.00,
  `item_name` varchar(100) DEFAULT NULL,
  `weight_kg` decimal(10,2) DEFAULT NULL,
  `size_description` varchar(100) DEFAULT NULL,
  `expiry_date` date DEFAULT NULL,
  `pickup_deadline` datetime DEFAULT NULL,
  `delivery_option` enum('donor_delivery','volunteer_delivery','food_bank_pickup') DEFAULT 'volunteer_delivery',
  `vehicle_type` enum('car','motorcycle','none') DEFAULT 'none',
  `photo_path` varchar(255) DEFAULT NULL,
  `evaluation_notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()

-- --------------------------------------------------------


--
-- 系統規劃書新增模組：配送、公益點數、活動認領
--
CREATE TABLE `deliveries` (
  `delivery_id` int(11) NOT NULL AUTO_INCREMENT,
  `donation_id` int(11) DEFAULT NULL,
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
  PRIMARY KEY (`delivery_id`), KEY `idx_deliveries_status` (`status`), KEY `idx_deliveries_volunteer` (`volunteer_id`), KEY `idx_deliveries_donation` (`donation_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `point_transactions` (
  `transaction_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `delivery_id` int(11) DEFAULT NULL,
  `points` int(11) NOT NULL,
  `transaction_type` enum('earned','redeemed','adjusted') NOT NULL DEFAULT 'earned',
  `description` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`transaction_id`), KEY `idx_points_user` (`user_id`), KEY `idx_points_delivery` (`delivery_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `activities` (
  `activity_id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(150) NOT NULL,
  `activity_type` enum('donation_drive','briefing','cleanup','promotion','other') NOT NULL DEFAULT 'other',
  `description` text DEFAULT NULL,
  `start_at` datetime NOT NULL,
  `end_at` datetime DEFAULT NULL,
  `capacity` int(11) DEFAULT NULL,
  `status` enum('planned','ongoing','completed','cancelled') NOT NULL DEFAULT 'planned',
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`activity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `activity_assignments` (
  `assignment_id` int(11) NOT NULL AUTO_INCREMENT,
  `activity_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `status` enum('registered','attended','cancelled') NOT NULL DEFAULT 'registered',
  `points` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`assignment_id`), UNIQUE KEY `unique_activity_user` (`activity_id`,`user_id`), KEY `idx_activity_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
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
  `role` enum('admin','manager','staff','volunteer') NOT NULL DEFAULT 'staff',
  `department` varchar(50) DEFAULT NULL,
  `status` enum('active','inactive','suspended') DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- 傾印資料表的資料 `users`
--

INSERT INTO `users` (`user_id`, `username`, `password`, `email`, `full_name`, `phone`, `role`, `department`, `status`, `created_at`, `updated_at`, `created_by`) VALUES
(1, 'admin', '240be518fabd2724ddb6f04eeb1da5967448d7e831c08c8fa822809f74c720a9', 'admin@foodbank.local', '系統管理員', NULL, 'admin', NULL, 'active', '2026-08-18 16:07:33', '2026-08-18 16:07:33', NULL);

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
-- 使用資料表自動遞增(AUTO_INCREMENT) `audit_logs`
--
ALTER TABLE `audit_logs`
  MODIFY `log_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `beneficiaries`
--
ALTER TABLE `beneficiaries`
  MODIFY `beneficiary_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `beneficiary_distributions`
--
ALTER TABLE `beneficiary_distributions`
  MODIFY `distribution_id` int(11) NOT NULL AUTO_INCREMENT;

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
  MODIFY `donation_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `donors`
--
ALTER TABLE `donors`
  MODIFY `donor_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `inventory`
--
ALTER TABLE `inventory`
  MODIFY `inventory_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `inventory_transactions`
--
ALTER TABLE `inventory_transactions`
  MODIFY `transaction_id` int(11) NOT NULL AUTO_INCREMENT;

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
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

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
