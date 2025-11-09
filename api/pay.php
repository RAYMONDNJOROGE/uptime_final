<?php
/**
 * M-Pesa Payment Initiation Endpoint
 * Handles STK Push requests from the frontend
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config.php';

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJSON(['success' => false, 'message' => 'Method not allowed'], 405);
}

// Get client IP
$requestIP = $_SERVER['REMOTE_ADDR'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? 'unknown';

// Valid plan configurations
$VALID_PLANS = [
    '30min' => 5,
    '2h' => 10,
    '12h' => 30,
    '24h' => 40,
    '48h' => 70,
    '1w' => 240
];

// Get JSON input
$json = file_get_contents('php://input');
$data = json_decode($json, true);

// Log the request
logAPIRequest('/pay.php', 'POST', $data);

if (!$data) {
    sendJSON(['success' => false, 'message' => 'Invalid request data'], 400);
}

// Validate required fields
$required = ['phone', 'amount', 'plan', 'planName', 'mac', 'username'];
foreach ($required as $field) {
    if (empty($data[$field])) {
        sendJSON(['success' => false, 'message' => "Missing required field: $field"], 400);
    }
}

// Validate phone number
$phone = validatePhone($data['phone']);
if (!$phone) {
    sendJSON(['success' => false, 'message' => 'Invalid phone number format'], 400);
}

// Validate amount
$amount = floatval($data['amount']);
if ($amount <= 0) {
    sendJSON(['success' => false, 'message' => 'Invalid amount'], 400);
}

// Validate plan exists and amount matches
$plan = $data['plan'];
if (!isset($VALID_PLANS[$plan])) {
    sendJSON(['success' => false, 'message' => 'Invalid plan selected'], 400);
}

if ($VALID_PLANS[$plan] != $amount) {
    sendJSON(['success' => false, 'message' => 'Amount does not match selected plan'], 400);
}

// Validate MAC address format
$mac = $data['mac'];
if (!preg_match('/^([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})$/', $mac)) {
    sendJSON(['success' => false, 'message' => 'Invalid MAC address format'], 400);
}

// Sanitize inputs for logging/display
$planName = htmlspecialchars($data['planName'], ENT_QUOTES, 'UTF-8');
$username = htmlspecialchars($data['username'], ENT_QUOTES, 'UTF-8');

// Get database connection
$pdo = getDBConnection();
if (!$pdo) {
    error_log('Database connection failed in pay.php');
    sendJSON(['success' => false, 'message' => 'Service temporarily unavailable. Please try again.'], 503);
}

/**
 * Get M-Pesa Access Token
 */
function getAccessToken() {
    $curl = curl_init(MPESA_OAUTH_URL);
    curl_setopt($curl, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_HEADER, false);
    curl_setopt($curl, CURLOPT_USERPWD, MPESA_CONSUMER_KEY . ':' . MPESA_CONSUMER_SECRET);
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, true); // IMPORTANT: Keep SSL verification enabled
    curl_setopt($curl, CURLOPT_CAINFO, __DIR__ . '/../certs/cacert.pem'); // Adjust path if needed
    curl_setopt($curl, CURLOPT_TIMEOUT, 30);
    
    $result = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    
    if ($error) {
        error_log("cURL Error getting access token: $error");
        return false;
    }
    
    if ($status == 200) {
        $result = json_decode($result);
        return $result->access_token ?? false;
    }
    
    error_log("Failed to get access token. Status: $status, Response: $result");
    return false;
}

/**
 * Initiate STK Push
 */
function initiateSTKPush($phone, $amount, $accountRef, $description, $accessToken) {
    $timestamp = date('YmdHis');
    $password = base64_encode(MPESA_SHORTCODE . MPESA_PASSKEY . $timestamp);
    
    $postData = [
        'BusinessShortCode' => MPESA_SHORTCODE,
        'Password' => $password,
        'Timestamp' => $timestamp,
        'TransactionType' => 'CustomerPayBillOnline',
        'Amount' => (int)$amount,
        'PartyA' => $phone,
        'PartyB' => MPESA_SHORTCODE,
        'PhoneNumber' => $phone,
        'CallBackURL' => MPESA_CALLBACK_URL,
        'AccountReference' => $accountRef,
        'TransactionDesc' => $description
    ];
    
    $curl = curl_init(MPESA_STK_URL);
    curl_setopt($curl, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $accessToken
    ]);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_POST, true);
    curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($postData));
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, true); // IMPORTANT: Keep SSL verification enabled
    curl_setopt($curl, CURLOPT_CAINFO, __DIR__ . '/../certs/cacert.pem'); // Adjust path if needed
    curl_setopt($curl, CURLOPT_TIMEOUT, 30);
    
    $result = curl_exec($curl);
    $error = curl_error($curl);
    curl_close($curl);
    
    if ($error) {
        error_log("cURL Error initiating STK: $error");
        return false;
    }
    
    return json_decode($result);
}

try {
    // Rate limiting - Check payment attempts per phone (max 5 per hour)
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM transactions 
        WHERE phone = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
    ");
    $stmt->execute([$phone]);
    $hourlyAttempts = $stmt->fetchColumn();
    
    if ($hourlyAttempts >= 5) {
        sendJSON([
            'success' => false, 
            'message' => 'Too many payment attempts. Please try again in an hour.'
        ], 429);
    }
    
    // Rate limiting - Check payment attempts per IP (max 10 per hour)
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM transactions 
        WHERE request_ip = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
    ");
    $stmt->execute([$requestIP]);
    $ipAttempts = $stmt->fetchColumn();
    
    if ($ipAttempts >= 10) {
        sendJSON([
            'success' => false, 
            'message' => 'Too many requests from your network. Please try again later.'
        ], 429);
    }
    
    // Check for pending transaction (within 2 minutes to avoid duplicate STK pushes)
    $stmt = $pdo->prepare("
        SELECT * FROM transactions 
        WHERE phone = ? AND status = 'pending' 
        AND created_at > DATE_SUB(NOW(), INTERVAL 1 MINUTE)
        ORDER BY created_at DESC LIMIT 1
    ");
    $stmt->execute([$phone]);
    $pendingTx = $stmt->fetch();
    
    if ($pendingTx) {
        sendJSON([
            'success' => false, 
            'message' => 'You have a pending payment. Please complete it or wait 1 minute before trying again.'
        ], 400);
    }
    
    // Generate credentials if username is phone number
    if ($username === $phone || empty($username)) {
        $username = generateUsername($phone);
    }
    
    // Validate generated username
    if (empty($username) || strlen($username) < 3) {
        error_log("Failed to generate valid username for phone: $phone");
        sendJSON(['success' => false, 'message' => 'Failed to create account. Please try again.'], 500);
    }
    
    $password = generatePassword();
    
    // Validate generated password
    if (empty($password) || strlen($password) < 6) {
        error_log("Failed to generate valid password");
        sendJSON(['success' => false, 'message' => 'Failed to create account. Please try again.'], 500);
    }
    
    // Get M-Pesa access token
    $accessToken = getAccessToken();
    if (!$accessToken) {
        error_log('Failed to get M-Pesa access token');
        sendJSON(['success' => false, 'message' => 'Payment service temporarily unavailable. Please try again.'], 503);
    }

    function generateRandomAlphaNum($length = 6) {
            $chars = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        return substr(str_shuffle(str_repeat($chars, 5)), 0, $length);
    }

    
    // Initiate STK Push
    $accountRef = $planName . "-" . substr($phone, -4) . '_' . generateRandomAlphaNum();
    $description = "Payment for $planName";
    $stkResponse = initiateSTKPush($phone, $amount, $accountRef, $description, $accessToken);
    
    if (!$stkResponse) {
        error_log('STK Push initiation failed - no response');
        sendJSON(['success' => false, 'message' => 'Failed to initiate payment. Please try again.'], 500);
    }
    
    // Check for errors in response
    if (isset($stkResponse->errorCode)) {
        error_log("M-Pesa Error: {$stkResponse->errorCode} - " . ($stkResponse->errorMessage ?? 'Unknown error'));
        sendJSON(['success' => false, 'message' => 'Payment request failed. Please try again.'], 500);
    }
    
    if (!isset($stkResponse->ResponseCode) || $stkResponse->ResponseCode != '0') {
        $errorMsg = $stkResponse->ResponseDescription ?? $stkResponse->errorMessage ?? 'Failed to initiate payment';
        error_log("STK Push failed: $errorMsg");
        sendJSON(['success' => false, 'message' => 'Payment request failed. Please try again.'], 500);
    }
    
    // Start database transaction for atomicity
    $pdo->beginTransaction();
    
    try {
        // Store transaction in database
        $stmt = $pdo->prepare("
            INSERT INTO transactions 
            (checkout_request_id, merchant_request_id, phone, amount, plan, username, password, mac, request_ip, status, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
        ");
        
        $success = $stmt->execute([
            $stkResponse->CheckoutRequestID,
            $stkResponse->MerchantRequestID,
            $phone,
            $amount,
            $plan,
            $username,
            $password, // Note: Consider hashing if MikroTik supports it
            $mac,
            $requestIP
        ]);
        
        if (!$success) {
            throw new Exception('Failed to save transaction to database');
        }
        
        $transactionId = $pdo->lastInsertId();
        
        // Log successful initiation
        $logStmt = $pdo->prepare("
            INSERT INTO payment_logs (transaction_id, log_type, log_data, created_at) 
            VALUES (?, 'request', ?, NOW())
        ");
        $logStmt->execute([$transactionId, json_encode($stkResponse)]);
        
        // Commit transaction
        $pdo->commit();
        
        sendJSON([
            'success' => true,
            'message' => 'STK Push sent successfully',
            'checkoutRequestId' => $stkResponse->CheckoutRequestID,
            'merchantRequestId' => $stkResponse->MerchantRequestID
        ]);
        
    } catch (Exception $e) {
        // Rollback on any error
        $pdo->rollBack();
        throw $e;
    }
    
} catch (Exception $e) {
    error_log('Payment initiation error: ' . $e->getMessage());
    sendJSON([
        'success' => false,
        'message' => 'An error occurred while processing your payment. Please try again.'
    ], 500);
}
?>