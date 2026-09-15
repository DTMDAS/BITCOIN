CREATE DATABASE IF NOT EXISTS bitcap_db;
USE bitcap_db;

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    uid VARCHAR(20) UNIQUE NOT NULL,
    name VARCHAR(100) NOT NULL,
    mobile VARCHAR(20) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    balance DECIMAL(10,2) DEFAULT 0.00,
    plan_active TINYINT(1) DEFAULT 0,
    upline VARCHAR(20) DEFAULT '',
    last_task_date DATE DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS deposits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    uid VARCHAR(20) NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    utr VARCHAR(50) NOT NULL,
    status VARCHAR(20) DEFAULT 'Success',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS withdrawals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    uid VARCHAR(20) NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    upi VARCHAR(100) NOT NULL,
    status VARCHAR(20) DEFAULT 'Pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS p2p_transfers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sender_uid VARCHAR(20) NOT NULL,
    receiver_uid VARCHAR(20) NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Default Admin Account (UID: BTC8527, Password: password)
INSERT INTO users (uid, name, mobile, email, password, balance, plan_active, upline) 
VALUES ('BTC8527', 'System Admin', '9999999999', 'admin@bitcoin.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 5000.00, 1, '')
ON DUPLICATE KEY UPDATE uid=uid;
