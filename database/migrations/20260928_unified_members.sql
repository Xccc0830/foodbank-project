SET @member_type_exists = (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'member_type'
);

SET @add_member_type_sql = IF(
  @member_type_exists = 0,
  'ALTER TABLE `users` ADD COLUMN `member_type` enum(''general'',''enterprise'') DEFAULT NULL AFTER `role`',
  'SELECT 1'
);
PREPARE add_member_type_statement FROM @add_member_type_sql;
EXECUTE add_member_type_statement;
DEALLOCATE PREPARE add_member_type_statement;

ALTER TABLE `users`
  MODIFY `role` enum('admin','foodbank_staff','member','volunteer','donor') NOT NULL DEFAULT 'foodbank_staff';

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
