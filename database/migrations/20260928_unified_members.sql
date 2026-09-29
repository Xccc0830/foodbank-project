ALTER TABLE `users`
  MODIFY `role` enum('admin','foodbank_staff','member','volunteer','donor') NOT NULL DEFAULT 'foodbank_staff',
  ADD COLUMN `member_type` enum('general','enterprise') DEFAULT NULL AFTER `role`;

UPDATE `users`
SET
  `member_type` = CASE
    WHEN `role` = 'volunteer' THEN 'general'
    WHEN `role` = 'donor' THEN 'enterprise'
    ELSE `member_type`
  END,
  `enterprise_name` = CASE
    WHEN `role` = 'donor' THEN COALESCE(NULLIF(`enterprise_name`, ''), `full_name`)
    ELSE `enterprise_name`
  END,
  `role` = CASE
    WHEN `role` IN ('volunteer', 'donor') THEN 'member'
    ELSE `role`
  END
WHERE `role` IN ('volunteer', 'donor');
