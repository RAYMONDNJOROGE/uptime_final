<?php
/**
 * Uptime Hotspot Configuration File
 * Update these settings according to your setup
 */

// Error reporting (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');

// Database Configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'uptime_hotspot');
define('DB_USER', 'root');
define('DB_PASS', 'aSdF.119***');

// MikroTik Configuration
define('MIKROTIK_HOST', '175.0.0.1');  // Your MikroTik Router IP
define('MIKROTIK_USER', 'fanatik');          // MikroTik admin username
define('MIKROTIK_PASS', 'Hi2939.9');  // MikroTik admin password
define('MIKROTIK_PORT', 8728);           // API port (default 8728)

// M-Pesa Configuration
// IMPORTANT: Get these from Safaricom Developer Portal: https://developer.safaricom.co.ke/
define('MPESA_CONSUMER_KEY', '1bvBpyAQdFgnAxVgrPOoE0wNlnqdgqmTGw2ifirVgeG0gscJ');
define('MPESA_CONSUMER_SECRET', 'hu1EnuMQO4asAmvwqRn65c5OZwDqTnYAz9hA5NQaL0GopQQOAkuJjRhGWFtOAiak');
define('MPESA_PASSKEY', 'bfb279f9aa9bdbcf158e97dd71a467cd2e0c893059b10f78e6b72ada1ed2c919');
define('MPESA_SHORTCODE', '174379'); // Your Paybill/Till Number

// M-Pesa Environment (sandbox or production)
define('MPESA_ENV', 'sandbox'); // Change to 'production' when going live


// M-Pesa API URLs
if (MPESA_ENV === 'production') {
    define('MPESA_OAUTH_URL', 'https://api.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials');
    define('MPESA_STK_URL', 'https://api.safaricom.co.ke/mpesa/stkpush/v1/processrequest');
} else {
    define('MPESA_OAUTH_URL', 'https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials');
    define('MPESA_STK_URL', 'https://sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest');
}

// Callback URL - MUST be publicly accessible with HTTPS
// Update this with your actual domain/ngrok URL
define('MPESA_CALLBACK_URL', 'https://db3935ac5eff.ngrok-free.app/api/callback.php');

// Application Settings
define('APP_NAME', 'Uptime Hotspot');
define('SUPPORT_EMAIL', 'uptimehotspot@gmail.com');
define('SUPPORT_PHONE', '+254791024153');

// Timezone
date_default_timezone_set('Africa/Nairobi');

// Session Configuration
/**ini_set('session.cookie_httponly', 1);
*ini_set('session.use_only_cookies', 1);
*ini_set('session.cookie_secure', 0); // Set to 1 if using HTTPS
**/

/**
 * Get Database Connection
 */
function getDBConnection() {
    static $pdo = null;
    
    if ($pdo !== null) {
        return $pdo;
    }
    
    try {
        $pdo = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_PERSISTENT => true
            ]
        );
        return $pdo;
    } catch (PDOException $e) {
        error_log("Database connection failed: " . $e->getMessage());
        return false;
    }
}

/**
 * Log API Request
 */
function logAPIRequest($endpoint, $method, $requestData = null, $responseData = null, $statusCode = null) {
    try {
        $pdo = getDBConnection();
        if (!$pdo) return;
        
        $stmt = $pdo->prepare("
            INSERT INTO api_logs 
            (endpoint, method, request_data, response_data, status_code, ip_address, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $stmt->execute([
            $endpoint,
            $method,
            $requestData ? json_encode($requestData) : null,
            $responseData ? json_encode($responseData) : null,
            $statusCode,
            $_SERVER['REMOTE_ADDR'] ?? null
        ]);
    } catch (Exception $e) {
        error_log("Failed to log API request: " . $e->getMessage());
    }
}

/**
 * Send JSON Response
 */
function sendJSON($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

/**
 * Validate Phone Number (Kenyan format)
 */
function validatePhone($phone) {
    // Remove spaces and special characters
    $phone = preg_replace('/[^0-9]/', '', $phone);
    
    // Check if valid Kenyan number (07/01 or 254)
    if (preg_match('/^(254|0)?[17]\d{8}$/', $phone)) {
        // Format to 254XXXXXXXXX
        if (substr($phone, 0, 1) === '0') {
            return '254' . substr($phone, 1);
        } elseif (substr($phone, 0, 3) !== '254') {
            return '254' . $phone;
        }
        return $phone;
    }
    
    return false;
}

/**
 * Generate Random Password
 */
function generatePassword($length = 8) {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $password = '';
    $charsLength = strlen($chars);
    
    for ($i = 0; $i < $length; $i++) {
        $password .= $chars[random_int(0, $charsLength - 1)];
    }
    
    return $password;
}

/**
 * Generate Username from Phone
 */
function generateUsername($phone) {
    // Use last 6 digits of phone number
    return 'user_' . substr($phone, -6);
}

/**
 * Get Plan Configuration
 */
function getPlanConfig($planId) {
    global $PLANS;
    return $PLANS[$planId] ?? null;
}

/**
 * Validate Plan
 */
function validatePlan($planId, $amount) {
    $plan = getPlanConfig($planId);
    if (!$plan) {
        return false;
    }
    
    // Check if amount matches
    if ($plan['price'] != $amount) {
        return false;
    }
    
    return true;
}
?>