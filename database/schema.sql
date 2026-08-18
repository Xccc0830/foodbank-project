-- 食物銀行管理系統 - 數據庫 Schema
-- 創建時間: 2026-08-18

-- =====================================================
-- 1. 用戶管理表
-- =====================================================
CREATE TABLE IF NOT EXISTS users (
    user_id INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    phone VARCHAR(20),
    role ENUM('admin', 'manager', 'staff', 'volunteer') NOT NULL DEFAULT 'staff',
    department VARCHAR(50),
    status ENUM('active', 'inactive', 'suspended') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT,
    INDEX (role),
    INDEX (department),
    INDEX (status)
);

-- =====================================================
-- 2. 外援服務部 - 捐贈管理表
-- =====================================================
CREATE TABLE IF NOT EXISTS donations (
    donation_id INT PRIMARY KEY AUTO_INCREMENT,
    donor_id INT,
    donor_name VARCHAR(100) NOT NULL,
    donation_type ENUM('food', 'supplies', 'money', 'other') NOT NULL,
    quantity DECIMAL(10, 2),
    unit VARCHAR(20),
    donation_date DATETIME NOT NULL,
    received_by INT,
    status ENUM('received', 'pending', 'rejected', 'archived') DEFAULT 'pending',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX (donation_date),
    INDEX (status),
    INDEX (received_by),
    FOREIGN KEY (received_by) REFERENCES users(user_id)
);

-- =====================================================
-- 3. 內部倉庫 - 庫存管理表
-- =====================================================
CREATE TABLE IF NOT EXISTS inventory (
    inventory_id INT PRIMARY KEY AUTO_INCREMENT,
    item_code VARCHAR(50) UNIQUE NOT NULL,
    item_name VARCHAR(100) NOT NULL,
    category VARCHAR(50) NOT NULL,
    description TEXT,
    quantity_on_hand DECIMAL(10, 2) NOT NULL DEFAULT 0,
    reorder_level DECIMAL(10, 2),
    unit VARCHAR(20),
    location VARCHAR(100),
    expiry_date DATE,
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    status ENUM('available', 'low_stock', 'expired', 'removed') DEFAULT 'available',
    INDEX (category),
    INDEX (status),
    INDEX (expiry_date)
);

-- =====================================================
-- 4. 庫存異動記錄表
-- =====================================================
CREATE TABLE IF NOT EXISTS inventory_transactions (
    transaction_id INT PRIMARY KEY AUTO_INCREMENT,
    inventory_id INT NOT NULL,
    transaction_type ENUM('in', 'out', 'adjustment', 'loss') NOT NULL,
    quantity DECIMAL(10, 2) NOT NULL,
    reference_type VARCHAR(50),
    reference_id INT,
    notes TEXT,
    performed_by INT,
    transaction_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (inventory_id) REFERENCES inventory(inventory_id),
    FOREIGN KEY (performed_by) REFERENCES users(user_id),
    INDEX (transaction_date),
    INDEX (transaction_type)
);

-- =====================================================
-- 5. 外運營配置 - 受益者管理表
-- =====================================================
CREATE TABLE IF NOT EXISTS beneficiaries (
    beneficiary_id INT PRIMARY KEY AUTO_INCREMENT,
    beneficiary_code VARCHAR(50) UNIQUE NOT NULL,
    first_name VARCHAR(50) NOT NULL,
    last_name VARCHAR(50) NOT NULL,
    email VARCHAR(100),
    phone VARCHAR(20),
    address TEXT,
    city VARCHAR(50),
    postal_code VARCHAR(20),
    family_size INT,
    income_level ENUM('low', 'medium', 'high') NOT NULL,
    registration_date DATE NOT NULL,
    status ENUM('active', 'inactive', 'suspended') DEFAULT 'active',
    case_worker_id INT,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (case_worker_id) REFERENCES users(user_id),
    INDEX (status),
    INDEX (registration_date),
    INDEX (income_level)
);

-- =====================================================
-- 6. 受益者分配記錄表
-- =====================================================
CREATE TABLE IF NOT EXISTS beneficiary_distributions (
    distribution_id INT PRIMARY KEY AUTO_INCREMENT,
    beneficiary_id INT NOT NULL,
    distribution_date DATETIME NOT NULL,
    approved_by INT,
    status ENUM('pending', 'approved', 'completed', 'cancelled') DEFAULT 'pending',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (beneficiary_id) REFERENCES beneficiaries(beneficiary_id),
    FOREIGN KEY (approved_by) REFERENCES users(user_id),
    INDEX (distribution_date),
    INDEX (status)
);

-- =====================================================
-- 7. 分配明細表
-- =====================================================
CREATE TABLE IF NOT EXISTS distribution_items (
    detail_id INT PRIMARY KEY AUTO_INCREMENT,
    distribution_id INT NOT NULL,
    inventory_id INT NOT NULL,
    quantity DECIMAL(10, 2) NOT NULL,
    notes TEXT,
    FOREIGN KEY (distribution_id) REFERENCES beneficiary_distributions(distribution_id),
    FOREIGN KEY (inventory_id) REFERENCES inventory(inventory_id),
    INDEX (distribution_id)
);

-- =====================================================
-- 8. 購購管理 - 採購單表
-- =====================================================
CREATE TABLE IF NOT EXISTS purchases (
    purchase_id INT PRIMARY KEY AUTO_INCREMENT,
    purchase_code VARCHAR(50) UNIQUE NOT NULL,
    supplier_id INT,
    supplier_name VARCHAR(100) NOT NULL,
    purchase_date DATE NOT NULL,
    delivery_date DATE,
    total_amount DECIMAL(12, 2),
    status ENUM('draft', 'pending', 'approved', 'received', 'cancelled') DEFAULT 'draft',
    requested_by INT,
    approved_by INT,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (requested_by) REFERENCES users(user_id),
    FOREIGN KEY (approved_by) REFERENCES users(user_id),
    INDEX (purchase_date),
    INDEX (status),
    INDEX (supplier_name)
);

-- =====================================================
-- 9. 採購單明細表
-- =====================================================
CREATE TABLE IF NOT EXISTS purchase_items (
    purchase_item_id INT PRIMARY KEY AUTO_INCREMENT,
    purchase_id INT NOT NULL,
    item_name VARCHAR(100) NOT NULL,
    quantity DECIMAL(10, 2) NOT NULL,
    unit_price DECIMAL(10, 2),
    total_price DECIMAL(12, 2),
    FOREIGN KEY (purchase_id) REFERENCES purchases(purchase_id),
    INDEX (purchase_id)
);

-- =====================================================
-- 10. 供應商管理表
-- =====================================================
CREATE TABLE IF NOT EXISTS suppliers (
    supplier_id INT PRIMARY KEY AUTO_INCREMENT,
    supplier_code VARCHAR(50) UNIQUE NOT NULL,
    supplier_name VARCHAR(100) NOT NULL,
    contact_person VARCHAR(100),
    email VARCHAR(100),
    phone VARCHAR(20),
    address TEXT,
    city VARCHAR(50),
    postal_code VARCHAR(20),
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX (status)
);

-- =====================================================
-- 11. 總務與 - 部門管理表
-- =====================================================
CREATE TABLE IF NOT EXISTS departments (
    department_id INT PRIMARY KEY AUTO_INCREMENT,
    department_code VARCHAR(50) UNIQUE NOT NULL,
    department_name VARCHAR(100) NOT NULL,
    description TEXT,
    manager_id INT,
    budget DECIMAL(12, 2),
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (manager_id) REFERENCES users(user_id),
    INDEX (status)
);

-- =====================================================
-- 12. 倉庫安排 - 倉庫管理表
-- =====================================================
CREATE TABLE IF NOT EXISTS warehouses (
    warehouse_id INT PRIMARY KEY AUTO_INCREMENT,
    warehouse_code VARCHAR(50) UNIQUE NOT NULL,
    warehouse_name VARCHAR(100) NOT NULL,
    address TEXT NOT NULL,
    city VARCHAR(50),
    postal_code VARCHAR(20),
    manager_id INT,
    capacity DECIMAL(10, 2),
    current_usage DECIMAL(10, 2),
    status ENUM('active', 'inactive', 'maintenance') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (manager_id) REFERENCES users(user_id),
    INDEX (status)
);

-- =====================================================
-- 13. 銷售與 - 銷售記錄表
-- =====================================================
CREATE TABLE IF NOT EXISTS sales (
    sale_id INT PRIMARY KEY AUTO_INCREMENT,
    sale_code VARCHAR(50) UNIQUE NOT NULL,
    sale_date DATETIME NOT NULL,
    customer_name VARCHAR(100),
    customer_email VARCHAR(100),
    total_amount DECIMAL(12, 2),
    payment_method ENUM('cash', 'credit_card', 'check', 'transfer') NOT NULL,
    status ENUM('completed', 'pending', 'cancelled') DEFAULT 'completed',
    notes TEXT,
    processed_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (processed_by) REFERENCES users(user_id),
    INDEX (sale_date),
    INDEX (status)
);

-- =====================================================
-- 14. 銷售明細表
-- =====================================================
CREATE TABLE IF NOT EXISTS sale_items (
    sale_item_id INT PRIMARY KEY AUTO_INCREMENT,
    sale_id INT NOT NULL,
    inventory_id INT NOT NULL,
    quantity DECIMAL(10, 2) NOT NULL,
    unit_price DECIMAL(10, 2),
    total_price DECIMAL(12, 2),
    FOREIGN KEY (sale_id) REFERENCES sales(sale_id),
    FOREIGN KEY (inventory_id) REFERENCES inventory(inventory_id),
    INDEX (sale_id)
);

-- =====================================================
-- 15. 公眾與宣傳 - 公眾關係表
-- =====================================================
CREATE TABLE IF NOT EXISTS public_relations (
    pr_id INT PRIMARY KEY AUTO_INCREMENT,
    event_name VARCHAR(150) NOT NULL,
    event_date DATE NOT NULL,
    event_type ENUM('fundraiser', 'awareness', 'community', 'partnership', 'media') NOT NULL,
    description TEXT,
    location VARCHAR(200),
    organizer_id INT,
    participants_count INT,
    status ENUM('planned', 'ongoing', 'completed', 'cancelled') DEFAULT 'planned',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (organizer_id) REFERENCES users(user_id),
    INDEX (event_date),
    INDEX (event_type),
    INDEX (status)
);

-- =====================================================
-- 16. 捐獻者管理表
-- =====================================================
CREATE TABLE IF NOT EXISTS donors (
    donor_id INT PRIMARY KEY AUTO_INCREMENT,
    donor_code VARCHAR(50) UNIQUE NOT NULL,
    donor_name VARCHAR(100) NOT NULL,
    donor_type ENUM('individual', 'company', 'organization') NOT NULL,
    email VARCHAR(100),
    phone VARCHAR(20),
    address TEXT,
    contact_person VARCHAR(100),
    status ENUM('active', 'inactive') DEFAULT 'active',
    total_donations DECIMAL(12, 2) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX (donor_type),
    INDEX (status)
);

-- =====================================================
-- 17. 審核日誌表
-- =====================================================
CREATE TABLE IF NOT EXISTS audit_logs (
    log_id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT,
    action_type VARCHAR(100) NOT NULL,
    table_name VARCHAR(100),
    record_id INT,
    old_values TEXT,
    new_values TEXT,
    ip_address VARCHAR(45),
    action_timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id),
    INDEX (action_timestamp),
    INDEX (user_id),
    INDEX (action_type)
);

-- =====================================================
-- 創建索引以提高查詢性能
-- =====================================================
CREATE INDEX idx_donations_status ON donations(status);
CREATE INDEX idx_inventory_category ON inventory(category);
CREATE INDEX idx_beneficiaries_status ON beneficiaries(status);
CREATE INDEX idx_purchases_status ON purchases(status);
CREATE INDEX idx_sales_date ON sales(sale_date);
CREATE INDEX idx_users_role ON users(role);

-- =====================================================
-- 初始管理員用戶（密碼需要使用 SHA-256 或 bcrypt）
-- =====================================================
INSERT INTO users (username, password, email, full_name, role, status)
VALUES ('admin', SHA2('admin123', 256), 'admin@foodbank.local', '系統管理員', 'admin', 'active')
ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP;
