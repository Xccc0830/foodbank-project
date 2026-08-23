-- 擴充模組：防拆貼紙、OTP、忘記密碼、志工同意書、點數兌換、企業認領
USE shinigyi_foodbank;

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS phone_verified TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE donations
    ADD COLUMN IF NOT EXISTS seal_code VARCHAR(30) NULL;

CREATE TABLE IF NOT EXISTS otp_codes (
    otp_id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    phone VARCHAR(20) NOT NULL,
    purpose ENUM('phone_verification','password_reset') NOT NULL,
    code_hash VARCHAR(64) NOT NULL,
    attempts INT NOT NULL DEFAULT 0,
    expires_at DATETIME NOT NULL,
    consumed_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (user_id), INDEX (purpose)
);

CREATE TABLE IF NOT EXISTS password_resets (
    reset_id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    token_hash VARCHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (user_id)
);

CREATE TABLE IF NOT EXISTS volunteer_consents (
    user_id INT PRIMARY KEY,
    agreed_disclaimer TINYINT(1) NOT NULL DEFAULT 0,
    agreed_mutual_aid TINYINT(1) NOT NULL DEFAULT 0,
    video_watched TINYINT(1) NOT NULL DEFAULT 0,
    completed_at DATETIME NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS reward_catalog (
    reward_id INT PRIMARY KEY AUTO_INCREMENT,
    title VARCHAR(150) NOT NULL,
    description TEXT NULL,
    cost_points INT NOT NULL,
    stock INT NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS reward_redemptions (
    redemption_id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    reward_id INT NOT NULL,
    points_spent INT NOT NULL,
    status ENUM('pending','fulfilled','cancelled') NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (user_id), INDEX (reward_id)
);

ALTER TABLE activity_assignments
    ADD COLUMN IF NOT EXISTS assignment_type ENUM('individual','company') NOT NULL DEFAULT 'individual',
    ADD COLUMN IF NOT EXISTS organization_name VARCHAR(150) NULL;

INSERT IGNORE INTO reward_catalog (reward_id, title, description, cost_points, stock, status) VALUES
    (1, '愛心店家 9 折優惠券', '合作店家消費可折抵，掃描店家 QR Code 自行扣點', 30, NULL, 'active'),
    (2, '食物銀行公益禮盒', '兌換一份食物銀行整理的公益物資禮盒', 80, 20, 'active'),
    (3, '公益貢獻感謝狀', '累積貢獻達標即可換取實體感謝狀', 150, NULL, 'active');
