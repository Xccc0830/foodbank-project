-- 系統規劃書新增模組：配送、公益點數、活動認領
USE shinigyi_foodbank;

CREATE TABLE IF NOT EXISTS deliveries (
    delivery_id INT PRIMARY KEY AUTO_INCREMENT,
    donation_id INT NULL,
    volunteer_id INT NULL,
    vehicle_type ENUM('car','motorcycle') NOT NULL,
    total_distance_km DECIMAL(8,2) NOT NULL DEFAULT 0,
    weight_kg DECIMAL(8,2) NOT NULL DEFAULT 0,
    urgency ENUM('normal','priority','urgent') NOT NULL DEFAULT 'normal',
    points INT NOT NULL DEFAULT 0,
    pickup_address VARCHAR(255) NOT NULL,
    delivery_address VARCHAR(255) NOT NULL,
    status ENUM('open','claimed','picked_up','delivered','exception','cancelled') NOT NULL DEFAULT 'open',
    exception_notes TEXT NULL,
    pickup_confirmed_at DATETIME NULL,
    seal_intact TINYINT(1) NULL,
    item_count_confirmed TINYINT(1) NULL,
    delivered_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX (status), INDEX (volunteer_id), INDEX (donation_id)
);

CREATE TABLE IF NOT EXISTS point_transactions (
    transaction_id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    delivery_id INT NULL,
    points INT NOT NULL,
    transaction_type ENUM('earned','redeemed','adjusted') NOT NULL DEFAULT 'earned',
    description VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (user_id), INDEX (delivery_id)
);

CREATE TABLE IF NOT EXISTS activities (
    activity_id INT PRIMARY KEY AUTO_INCREMENT,
    title VARCHAR(150) NOT NULL,
    activity_type ENUM('donation_drive','briefing','cleanup','promotion','other') NOT NULL DEFAULT 'other',
    description TEXT,
    start_at DATETIME NOT NULL,
    end_at DATETIME NULL,
    capacity INT NULL,
    status ENUM('planned','ongoing','completed','cancelled') NOT NULL DEFAULT 'planned',
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS activity_assignments (
    assignment_id INT PRIMARY KEY AUTO_INCREMENT,
    activity_id INT NOT NULL,
    user_id INT NOT NULL,
    status ENUM('registered','attended','cancelled') NOT NULL DEFAULT 'registered',
    points INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_activity_user (activity_id, user_id),
    INDEX (user_id)
);
