-- 更新愛心商家帳號密碼為簡易測試密碼 (love123)
UPDATE `users`
SET password = '$2y$10$H/gfMvpdwPze2NoQkZVBwOS1ccKHVOT/HNc0/pxSaP9/F6IOaYRLG'
WHERE username = 'love_store_001';

-- 如果帳號不存在，新增
INSERT INTO `users` (username, email, password, full_name, role, status, phone_verified)
SELECT 'love_store_001', 'store001@foodbank.local', '$2y$10$H/gfMvpdwPze2NoQkZVBwOS1ccKHVOT/HNc0/pxSaP9/F6IOaYRLG', '愛心商家001', 'donor', 'active', 0
WHERE NOT EXISTS (SELECT 1 FROM users WHERE username = 'love_store_001');
