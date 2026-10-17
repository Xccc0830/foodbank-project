-- 10/13 專題會議決議：一般會員註冊後預設僅能瀏覽與參加公益活動，
-- 需另外申請「外送志工」（上傳駕照）並經食物銀行審核後，才具備外送權限。
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

-- 既有一般會員先保留外送權限（零回歸）；若曾被個人覆寫退選 rider.accept_task 則不補發。
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

-- 一般會員預設不再具備外送權限，改為只能申請。
DELETE FROM `role_permission_defaults`
WHERE `role` = 'member' AND `member_type` = 'general'
  AND `permission_key` IN ('page.deliveries', 'page.material_transport', 'rider.accept_task');

INSERT IGNORE INTO `role_permission_defaults` (`role`, `member_type`, `permission_key`) VALUES
  ('member', 'general', 'page.rider_application'),
  ('foodbank_staff', '', 'page.rider_review');
