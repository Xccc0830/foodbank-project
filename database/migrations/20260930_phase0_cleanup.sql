ALTER TABLE users
    MODIFY role ENUM('admin', 'foodbank_staff', 'member', 'volunteer', 'donor') NOT NULL DEFAULT 'foodbank_staff';

UPDATE users
SET member_type = CASE WHEN role = 'donor' THEN 'enterprise' ELSE 'general' END
WHERE role IN ('volunteer', 'donor');

UPDATE users
SET enterprise_name = COALESCE(NULLIF(enterprise_name, ''), full_name)
WHERE role = 'donor';

UPDATE users
SET role = 'member'
WHERE role IN ('volunteer', 'donor');

UPDATE users
SET role = 'foodbank_staff', member_type = COALESCE(member_type, 'general')
WHERE role = 'admin' OR username IN ('admin', 'manager');

ALTER TABLE users
    MODIFY role ENUM('foodbank_staff', 'member') NOT NULL DEFAULT 'foodbank_staff';

UPDATE notifications
SET message = REPLACE(message, '志工', '忠信GO RIDER')
WHERE message LIKE '%志工%';

UPDATE activities
SET title = REPLACE(title, '志工', '忠信GO RIDER')
WHERE title LIKE '%志工%';

UPDATE donations
SET delivery_option = 'food_bank_pickup'
WHERE delivery_option = 'donor_delivery';

UPDATE deliveries
SET delivery_method = 'food_bank'
WHERE delivery_method = 'donor';

ALTER TABLE donations
    MODIFY delivery_option ENUM('food_bank_pickup', 'volunteer_delivery') NOT NULL DEFAULT 'volunteer_delivery';

ALTER TABLE deliveries
    MODIFY delivery_method ENUM('food_bank', 'volunteer') NOT NULL DEFAULT 'volunteer';

CREATE TABLE IF NOT EXISTS donation_items (
    item_id INT NOT NULL AUTO_INCREMENT,
    donation_id INT NOT NULL,
    item_name VARCHAR(100) NOT NULL,
    donation_type VARCHAR(50) NOT NULL DEFAULT 'other',
    quantity DECIMAL(10,2) DEFAULT NULL,
    unit VARCHAR(20) DEFAULT NULL,
    weight_kg DECIMAL(10,2) DEFAULT NULL,
    size_description VARCHAR(100) DEFAULT NULL,
    expiry_date DATE DEFAULT NULL,
    photo_path VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (item_id),
    KEY idx_donation_items_donation (donation_id),
    CONSTRAINT donation_items_ibfk_1 FOREIGN KEY (donation_id) REFERENCES donations (donation_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO donation_items (donation_id, item_name, donation_type, quantity, unit, weight_kg, size_description, expiry_date, photo_path)
SELECT d.donation_id, COALESCE(NULLIF(d.item_name, ''), '未命名物資'), d.donation_type, d.quantity, d.unit,
       d.weight_kg, d.size_description, d.expiry_date, d.photo_path
FROM donations d
LEFT JOIN donation_items i ON i.donation_id = d.donation_id
WHERE i.item_id IS NULL;

DROP TABLE IF EXISTS distribution_items;
DROP TABLE IF EXISTS beneficiary_distributions;
DROP TABLE IF EXISTS inventory_transactions;
DROP TABLE IF EXISTS beneficiaries;
ALTER TABLE sale_items DROP FOREIGN KEY sale_items_ibfk_2;
DROP TABLE IF EXISTS inventory;
DROP TABLE IF EXISTS donors;
