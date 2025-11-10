CREATE DATABASE IF NOT EXISTS uptime_hotspot;
USE uptime_hotspot;

-- 🔐 Users table
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
    reset_otp VARCHAR(10) DEFAULT NULL,
    otp_expires_at DATETIME DEFAULT NULL,
    INDEX idx_username (username),
    INDEX idx_phone (phone),
    INDEX idx_is_active (is_active),
    FOREIGN KEY (plan) REFERENCES plans(code) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 🔐 Admins table
CREATE TABLE IF NOT EXISTS admins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    email VARCHAR(100) DEFAULT UNIQUE NOT NULL,
    reset_token VARCHAR(64) DEFAULT NULL,
    reset_expires DATETIME DEFAULT NULL,
    reset_otp VARCHAR(10) DEFAULT NULL,
    otp_expires_at DATETIME DEFAULT NULL,
    last_otp_requested_at DATETIME DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_login DATETIME DEFAULT NULL,
    is_active TINYINT(1) DEFAULT 1,
    role ENUM('super', 'regular') DEFAULT 'regular',
    INDEX idx_admin_username (username),
    INDEX idx_admin_email (email),
    INDEX idx_admin_token (reset_token),
    INDEX idx_admin_otp (reset_otp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 🛡️ Registration tokens
CREATE TABLE IF NOT EXISTS registration_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    token VARCHAR(64) UNIQUE NOT NULL,
    used_at DATETIME DEFAULT NULL AFTER token
    recipient_email VARCHAR(100) NOT NULL,
    expires_at DATETIME NOT NULL,
    used BOOLEAN DEFAULT FALSE,
    created_by INT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_token (token),
    INDEX idx_email (recipient_email),
    INDEX idx_expires (expires_at),
    FOREIGN KEY (created_by) REFERENCES admins(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 📦 Plans table
CREATE TABLE IF NOT EXISTS plans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(10) UNIQUE NOT NULL,
    name VARCHAR(50) NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    uptime_limit VARCHAR(10) NOT NULL,
    profile VARCHAR(50) NOT NULL DEFAULT 'default',
    description TEXT DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_code (code),
    INDEX idx_price (price)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 💰 Transactions table
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
    request_ip VARCHAR(45) DEFAULT NULL,
    status ENUM('pending', 'completed', 'failed') DEFAULT 'pending',
    mpesa_receipt VARCHAR(50) DEFAULT NULL,
    error_message TEXT DEFAULT NULL,
    created_at DATETIME NOT NULL,
    completed_at DATETIME DEFAULT NULL,
    INDEX idx_checkout_request (checkout_request_id),
    INDEX idx_phone (phone),
    INDEX idx_status (status),
    INDEX idx_created_at (created_at),
    INDEX idx_request_ip (request_ip)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 🧑‍💻 Active sessions
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

-- 🧾 Payment logs
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

-- 🌐 API logs
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

CREATE TABLE IF NOT EXISTS login_attempts (
  username VARCHAR(50) NOT NULL,
  attempts INT NOT NULL DEFAULT 1,
  ip_address VARCHAR(45) DEFAULT NULL,
  last_attempt DATETIME NOT NULL,
  PRIMARY KEY (username, ip_address),
  INDEX idx_last_attempt (last_attempt)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
DELETE FROM login_attempts WHERE last_attempt < DATE_SUB(NOW(), INTERVAL 1 DAY);

-- 🚫 OTP request attempts (rate limiting)
CREATE TABLE IF NOT EXISTS otp_attempts (
    ip_address VARCHAR(45) PRIMARY KEY,
    attempts INT NOT NULL DEFAULT 1,
    email VARCHAR(100) DEFAULT NOT NULL,
    last_attempt DATETIME NOT NULL,
    first_attempt DATETIME NOT NULL,
    INDEX idx_last_attempt (last_attempt)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;



-- ⚙️ Settings
CREATE TABLE IF NOT EXISTS settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value TEXT NOT NULL,
    description TEXT DEFAULT NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 📊 Views
CREATE OR REPLACE VIEW transaction_summary AS
SELECT 
    DATE(created_at) as transaction_date,
    status,
    COUNT(*) as transaction_count,
    SUM(amount) as total_amount
FROM transactions
GROUP BY DATE(created_at), status
ORDER BY transaction_date DESC;

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

-- 🧹 Cleanup procedure
DELIMITER //

CREATE PROCEDURE cleanup_old_records()
BEGIN
    DELETE FROM api_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY);
    DELETE FROM payment_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY);
    DELETE FROM transactions 
    WHERE status = 'completed' 
    AND completed_at < DATE_SUB(NOW(), INTERVAL 1 YEAR);
    UPDATE users 
    SET is_active = 0 
    WHERE expires_at < NOW() AND is_active = 1;
    DELETE FROM registration_tokens 
    WHERE used = FALSE AND expires_at < NOW();
END //

DELIMITER ;

-- 🕒 Daily cleanup event
SET GLOBAL event_scheduler = ON;

CREATE EVENT IF NOT EXISTS daily_cleanup
ON SCHEDULE EVERY 1 DAY
STARTS CURRENT_TIMESTAMP
DO CALL cleanup_old_records();