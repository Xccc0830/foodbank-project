-- 活動報名新增取消時間欄位（供 24 小時冷卻判斷使用）
USE shinigyi_foodbank;

ALTER TABLE activity_assignments
    ADD COLUMN IF NOT EXISTS cancelled_at DATETIME NULL AFTER status;

ALTER TABLE activity_assignments
    ADD INDEX IF NOT EXISTS activity_status_cancelled_at (activity_id, user_id, status, cancelled_at);
