-- Demo 展示資料，可重複匯入；正式環境請勿匯入
USE shinigyi_foodbank;

INSERT IGNORE INTO donations (donor_id, donor_name, donation_type, quantity, unit, donation_date, item_name, weight_kg, size_description, expiry_date, pickup_deadline, delivery_option, vehicle_type, status, seal_code, notes)
SELECT user_id, '暖心好食店', 'food', 20, '份', NOW(), '愛心便當', 12.00, '中型保冷箱 2 箱', DATE_ADD(CURDATE(), INTERVAL 2 DAY), DATE_ADD(NOW(), INTERVAL 8 HOUR), 'volunteer_delivery', 'motorcycle', 'approved', 'FB-DEMO001', 'Demo 展示用物資'
FROM users WHERE username = 'donor';

INSERT IGNORE INTO donations (donor_id, donor_name, donation_type, quantity, unit, donation_date, item_name, weight_kg, size_description, expiry_date, pickup_deadline, delivery_option, vehicle_type, status, notes)
SELECT user_id, '綠野超市', 'food', 8, '箱', NOW(), '新鮮蔬果', 25.00, '大型紙箱 8 箱', DATE_ADD(CURDATE(), INTERVAL 1 DAY), DATE_ADD(NOW(), INTERVAL 5 HOUR), 'food_bank_pickup', 'car', 'pending', '請官方人員進行食安評估'
FROM users WHERE username = 'donor';

INSERT IGNORE INTO inventory (item_code, item_name, category, quantity_on_hand, reorder_level, unit, location, expiry_date, status)
VALUES ('DEMO-RICE-001', '白米', '乾糧', 42, 10, '袋', 'A-01', DATE_ADD(CURDATE(), INTERVAL 180 DAY), 'available'),
       ('DEMO-VEG-001', '新鮮蔬菜', '蔬果', 6, 10, '箱', '冷藏區', DATE_ADD(CURDATE(), INTERVAL 3 DAY), 'low_stock'),
       ('DEMO-MILK-001', '鮮奶', '乳品', 18, 8, '瓶', '冷藏區', DATE_ADD(CURDATE(), INTERVAL 10 DAY), 'available');

INSERT IGNORE INTO beneficiaries (beneficiary_code, first_name, last_name, family_size, income_level, registration_date, status, notes)
VALUES ('DEMO-BEN-001', '王小美', '陳', 4, 'low', CURDATE(), 'active', 'Demo 展示用受益者'),
       ('DEMO-BEN-002', '林志明', '黃', 2, 'medium', CURDATE(), 'active', 'Demo 展示用受益者');

INSERT IGNORE INTO deliveries (donation_id, volunteer_id, vehicle_type, total_distance_km, weight_kg, urgency, points, pickup_address, delivery_address, status)
SELECT d.donation_id, NULL, 'motorcycle', 4.50, 12.00, 'urgent', 24, '暖心好食店：台北市文山區', '忠信食物銀行', 'open'
FROM donations d WHERE d.seal_code = 'FB-DEMO001';

INSERT IGNORE INTO activities (title, activity_type, description, start_at, end_at, capacity, status)
VALUES ('文山區惜食募集日', 'donation_drive', '協助整理與募集社區剩食物資。', DATE_ADD(NOW(), INTERVAL 2 DAY), DATE_ADD(NOW(), INTERVAL 2 DAY) + INTERVAL 4 HOUR, 20, 'planned'),
       ('食安運送志工說明會', 'briefing', '認識防拆貼紙、冷鏈運送與異常回報流程。', DATE_ADD(NOW(), INTERVAL 5 DAY), DATE_ADD(NOW(), INTERVAL 5 DAY) + INTERVAL 2 HOUR, 30, 'planned');
