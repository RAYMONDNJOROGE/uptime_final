-- Uptime Hotspot Database Schema
CREATE DATABASE IF NOT EXISTS uptime_hotspot;
USE uptime_hotspot;

-- Plans table - defines available hotspot plans
CREATE TABLE IF NOT EXISTS plans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(10) UNIQUE NOT NULL,         -- e.g. '2h', '6h'
    name VARCHAR(50) NOT NULL,                -- e.g. '2 Hours'
    price DECIMAL(10,2) NOT NULL,             -- e.g. 10.00
    uptime_limit VARCHAR(10) NOT NULL,        -- e.g. '2h', '1d'
    profile VARCHAR(50) NOT NULL DEFAULT 'default',
    description TEXT DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_code (code),
    INDEX idx_price (price)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Transactions table - stores all payment transactions
CREATE TABLE IF NOT EXISTS transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    checkout_request_id VARCHAR(100) UNIQUE NOT NULL,
    merchant_request_id VARCHAR(100) NOT NULL,
    phone VARCHAR(15) NOT NULL,
    amount DECIMAL(10, 2) NOT NULL,
    plan VARCHAR(10) NOT NULL,
    username VARCHAR(50) NOT NULL,
    password VARCHAR(50) NOT NULL,
    mac VARCHAR(20) DEFAULT NULL,
    request_ip VARCHAR(45) DEFAULT NULL, -- ✅ NEW COLUMN
    status ENUM('pending', 'completed', 'failed') DEFAULT 'pending',
    mpesa_receipt VARCHAR(50) DEFAULT NULL,
    error_message TEXT DEFAULT NULL,
    created_at DATETIME NOT NULL,
    completed_at DATETIME DEFAULT NULL,
    INDEX idx_checkout_request (checkout_request_id),
    INDEX idx_phone (phone),
    INDEX idx_status (status),
    INDEX idx_created_at (created_at),
    INDEX idx_request_ip (request_ip) -- ✅ NEW INDEX
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Users table - stores user accounts
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    phone VARCHAR(15) NOT NULL,
    plan VARCHAR(10) NOT NULL,
    mac VARCHAR(20) DEFAULT NULL,
    created_at DATETIME NOT NULL,
    expires_at DATETIME DEFAULT NULL,
    is_active TINYINT(1) DEFAULT 1,
    last_login DATETIME DEFAULT NULL,
    INDEX idx_username (username),
    INDEX idx_phone (phone),
    INDEX idx_is_active (is_active),
    FOREIGN KEY (plan) REFERENCES plans(code) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Active sessions table - tracks active hotspot sessions
CREATE TABLE IF NOT EXISTS active_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL,
    mac_address VARCHAR(20) NOT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    login_time DATETIME NOT NULL,
    logout_time DATETIME DEFAULT NULL,
    bytes_in BIGINT DEFAULT 0,
    bytes_out BIGINT DEFAULT 0,
    session_time INT DEFAULT 0,
    INDEX idx_username (username),
    INDEX idx_mac_address (mac_address),
    INDEX idx_login_time (login_time),
    FOREIGN KEY (username) REFERENCES users(username) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Payment logs table - detailed payment logs for auditing
CREATE TABLE IF NOT EXISTS payment_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    transaction_id INT NOT NULL,
    log_type ENUM('request', 'callback', 'error') NOT NULL,
    log_data TEXT NOT NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_transaction_id (transaction_id),
    INDEX idx_created_at (created_at),
    FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- API logs table - logs all API requests
CREATE TABLE IF NOT EXISTS api_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    endpoint VARCHAR(255) NOT NULL,
    method VARCHAR(10) NOT NULL,
    request_data TEXT DEFAULT NULL,
    response_data TEXT DEFAULT NULL,
    status_code INT DEFAULT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_endpoint (endpoint),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Settings table - store system settings
CREATE TABLE IF NOT EXISTS settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value TEXT NOT NULL,
    description TEXT DEFAULT NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Create view for transaction summary
CREATE OR REPLACE VIEW transaction_summary AS
SELECT 
    DATE(created_at) as transaction_date,
    status,
    COUNT(*) as transaction_count,
    SUM(amount) as total_amount
FROM transactions
GROUP BY DATE(created_at), status
ORDER BY transaction_date DESC;

-- Create view for active users
CREATE OR REPLACE VIEW active_users_view AS
SELECT 
    u.username,
    u.phone,
    u.plan,
    u.created_at,
    u.expires_at,
    s.login_time,
    s.ip_address,
    s.mac_address
FROM users u
LEFT JOIN active_sessions s ON u.username = s.username AND s.logout_time IS NULL
WHERE u.is_active = 1;

-- Create procedure to cleanup old records
DELIMITER //

CREATE PROCEDURE cleanup_old_records()
BEGIN
    -- Delete old logs (older than 90 days)
    DELETE FROM api_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY);
    DELETE FROM payment_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY);
    
    -- Delete old completed transactions (older than 1 year)
    DELETE FROM transactions 
    WHERE status = 'completed' 
    AND completed_at < DATE_SUB(NOW(), INTERVAL 1 YEAR);
    
    -- Deactivate expired users
    UPDATE users 
    SET is_active = 0 
    WHERE expires_at < NOW() AND is_active = 1;
END //

DELIMITER ;

-- Create event to run cleanup daily (if events are enabled)
SET GLOBAL event_scheduler = ON;

CREATE EVENT IF NOT EXISTS daily_cleanup
ON SCHEDULE EVERY 1 DAY
STARTS CURRENT_TIMESTAMP
DO CALL cleanup_old_records();