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

require_once 'config.php';

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJSON(['success' => false, 'message' => 'Method not allowed'], 405);
}

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

// Get database connection
$pdo = getDBConnection();
if (!$pdo) {
    sendJSON(['success' => false, 'message' => 'Database connection failed'], 500);
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
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
    
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
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
    
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
    $phone = $data['phone'];
    $amount = $data['amount'];
    $plan = $data['plan'];
    $planName = $data['planName'];
    $mac = $data['mac'];
    $username = $data['username'];
    
    // Generate credentials if username is phone number
    if ($username === $phone) {
        $username = generateUsername($phone);
    }
    $password = generatePassword();
    
    // Check if user already exists with pending transaction
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
            'message' => 'You have a pending payment. Please wait or try again in 1 minute.'
        ], 400);
    }
    
    // Get M-Pesa access token
    $accessToken = getAccessToken();
    if (!$accessToken) {
        throw new Exception('Failed to authenticate with M-Pesa. Please try again.');
    }
    
    // Initiate STK Push
    $accountRef = $planName ."-". substr($phone, -4) . '_' . rand(1000, 9999);
    $description = "Payment for $planName";
    $stkResponse = initiateSTKPush($phone, $amount, $accountRef, $description, $accessToken);
    
    if (!$stkResponse) {
        throw new Exception('Failed to initiate payment. Please try again.');
    }
    
    // Check for errors in response
    if (isset($stkResponse->errorCode)) {
        throw new Exception($stkResponse->errorMessage ?? 'Payment request failed');
    }
    
    if (!isset($stkResponse->ResponseCode) || $stkResponse->ResponseCode != '0') {
        $errorMsg = $stkResponse->ResponseDescription ?? $stkResponse->errorMessage ?? 'Failed to initiate payment';
        throw new Exception($errorMsg);
    }
    
    // Store transaction in database
    $stmt = $pdo->prepare("
        INSERT INTO transactions 
        (checkout_request_id, merchant_request_id, phone, amount, plan, username, password, mac, status, created_at) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
    ");
    
    $success = $stmt->execute([
        $stkResponse->CheckoutRequestID,
        $stkResponse->MerchantRequestID,
        $phone,
        $amount,
        $plan,
        $username,
        $password,
        $mac
    ]);
    
    if (!$success) {
        throw new Exception('Failed to save transaction');
    }
    
    // Log successful initiation
    $logStmt = $pdo->prepare("
        INSERT INTO payment_logs (transaction_id, log_type, log_data, created_at) 
        VALUES (LAST_INSERT_ID(), 'request', ?, NOW())
    ");
    $logStmt->execute([json_encode($stkResponse)]);
    
    sendJSON([
        'success' => true,
        'message' => 'STK Push sent successfully',
        'checkoutRequestId' => $stkResponse->CheckoutRequestID,
        'merchantRequestId' => $stkResponse->MerchantRequestID
    ]);
    
} catch (Exception $e) {
    error_log('Payment initiation error: ' . $e->getMessage());
    sendJSON([
        'success' => false,
        'message' => $e->getMessage()
    ], 500);
}
?>