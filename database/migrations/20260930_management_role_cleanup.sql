UPDATE `users`
SET `role` = 'foodbank_staff'
WHERE `role` = 'admin';

UPDATE `users`
SET
  `member_type` = CASE
    WHEN `role` = 'donor' THEN 'enterprise'
    ELSE COALESCE(`member_type`, 'general')
  END,
  `role` = 'member'
WHERE `role` IN ('volunteer', 'donor');

ALTER TABLE `users`
  MODIFY `role` enum('foodbank_staff','member') NOT NULL DEFAULT 'foodbank_staff';