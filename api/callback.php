<?php
/**
 * M-Pesa Callback Handler
 * Receives payment confirmations and creates MikroTik accounts
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config.php';
require_once 'mikrotikapi.php';

// Get database connection
$pdo = getDBConnection();
if (!$pdo) {
    error_log('Database connection failed in callback');
    echo json_encode([
        'status' => 'error',
        'message' => 'Database connection failed',
        'username' => '',
        'password' => ''
    ]);
    exit;
}

/**
 * Verify M-Pesa callback authenticity
 */
function verifyMpesaCallback() {
    // M-Pesa callback IP addresses (update these with actual Safaricom IPs)
    $allowedIPs = [
        '196.201.214.200',
        '196.201.214.206',
        '196.201.213.114',
        '196.201.214.207',
        '196.201.214.208'
    ];
    
    $clientIP = $_SERVER['REMOTE_ADDR'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    
    // For development/testing, you can temporarily disable this check
    // Remove this condition in production!
    // Add define('MPESA_CALLBACK_VERIFY', false); to config.php to disable verification
    if (defined('MPESA_CALLBACK_VERIFY') && constant('MPESA_CALLBACK_VERIFY') === false) {
        return true;
    }
    
    if (!in_array($clientIP, $allowedIPs)) {
        error_log("Unauthorized callback attempt from IP: $clientIP");
        return false;
    }
    
    return true;
}

/**
 * Validate checkout request ID format
 */
function validateCheckoutRequestId($id) {
    // M-Pesa checkout request IDs follow pattern: ws_CO_[timestamp]_[random]
    return preg_match('/^ws_CO_\d+_\d+$/', $id);
}

/**
 * Create MikroTik user with retry logic
 */
function createMikrotikUserWithRetry($username, $password, $plan, $maxRetries = 3) {
    $mikrotik = new MikrotikAPI();
    
    for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
        try {
            $created = $mikrotik->createUser($username, $password, $plan);
            
            if ($created) {
                return ['success' => true, 'message' => 'User created successfully'];
            }
            
            error_log("MikroTik user creation failed - Attempt $attempt/$maxRetries");
            
            if ($attempt < $maxRetries) {
                sleep(2); // Wait 2 seconds before retry
            }
        } catch (Exception $e) {
            error_log("MikroTik API error (Attempt $attempt/$maxRetries): " . $e->getMessage());
            
            if ($attempt < $maxRetries) {
                sleep(2);
            }
        }
    }
    
    return ['success' => false, 'message' => 'Failed to create user after retries'];
}

// ==================== GET: Status Check ====================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['checkoutRequestId'])) {
    $checkoutRequestId = $_GET['checkoutRequestId'];

    // Validate format
    if (!validateCheckoutRequestId($checkoutRequestId)) {
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'message' => 'Invalid checkout request ID format'
        ]);
        exit;
    }

    // Rate limiting for status checks
    $clientIP = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $cacheKey = "status_check_$clientIP";
    
    // Simple rate limiting using file-based cache (consider using Redis/Memcached for production)
    $rateLimitFile = sys_get_temp_dir() . "/rate_limit_$clientIP.txt";
    if (file_exists($rateLimitFile)) {
        $lastCheck = (int)file_get_contents($rateLimitFile);
        if (time() - $lastCheck < 2) { // Max 1 request per 2 seconds per IP
            http_response_code(429);
            echo json_encode([
                'status' => 'error',
                'message' => 'Too many requests. Please wait.'
            ]);
            exit;
        }
    }
    file_put_contents($rateLimitFile, time());

    try {
        $stmt = $pdo->prepare("
            SELECT status, username, password, error_message, credentials_retrieved 
            FROM transactions 
            WHERE checkout_request_id = ?
        ");
        $stmt->execute([$checkoutRequestId]);
        $transaction = $stmt->fetch();

        if ($transaction) {
            $response = [
                'status' => $transaction['status'],
                'message' => $transaction['error_message'] ?? ''
            ];
            
            // Only return credentials if not previously retrieved and status is completed
            if ($transaction['status'] === 'completed' && !$transaction['credentials_retrieved']) {
                $response['username'] = $transaction['username'] ?? '';
                $response['password'] = $transaction['password'] ?? '';
                
                // Mark credentials as retrieved (security best practice)
                $updateStmt = $pdo->prepare("
                    UPDATE transactions 
                    SET credentials_retrieved = 1, 
                        credentials_retrieved_at = NOW() 
                    WHERE checkout_request_id = ?
                ");
                $updateStmt->execute([$checkoutRequestId]);
            } else if ($transaction['status'] === 'completed') {
                // Credentials already retrieved - don't expose them again
                $response['username'] = '';
                $response['password'] = '';
                $response['message'] = 'Credentials already retrieved';
            } else {
                $response['username'] = '';
                $response['password'] = '';
            }
            
            echo json_encode($response);
        } else {
            echo json_encode([
                'status' => 'not_found',
                'username' => '',
                'password' => '',
                'message' => 'Transaction not found'
            ]);
        }
    } catch (Exception $e) {
        error_log('Status check error: ' . $e->getMessage());
        echo json_encode([
            'status' => 'error',
            'username' => '',
            'password' => '',
            'message' => 'Failed to check status'
        ]);
    }
    exit;
}

// ==================== POST: M-Pesa Callback ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Verify callback authenticity
    if (!verifyMpesaCallback()) {
        http_response_code(403);
        echo json_encode(['ResultCode' => 1, 'ResultDesc' => 'Forbidden']);
        exit;
    }
    
    $callbackData = file_get_contents('php://input');

    // Log callback with rotation (keep last 1000 lines)
    $logFile = __DIR__ . '/mpesa_callbacks.log';
    $logEntry = date('Y-m-d H:i:s') . " - " . $callbackData . "\n";
    
    if (file_exists($logFile) && filesize($logFile) > 5000000) { // 5MB limit
        $lines = file($logFile);
        file_put_contents($logFile, implode('', array_slice($lines, -1000)));
    }
    file_put_contents($logFile, $logEntry, FILE_APPEND);

    $callback = json_decode($callbackData, true);

    if (!isset($callback['Body']['stkCallback'])) {
        error_log('Invalid callback structure');
        echo json_encode(['ResultCode' => 1, 'ResultDesc' => 'Invalid callback data']);
        exit;
    }

    $stkCallback = $callback['Body']['stkCallback'];
    $merchantRequestId = $stkCallback['MerchantRequestID'] ?? '';
    $checkoutRequestId = $stkCallback['CheckoutRequestID'] ?? '';
    $resultCode = $stkCallback['ResultCode'] ?? -1;
    $resultDesc = $stkCallback['ResultDesc'] ?? 'Unknown error';

    // Validate IDs
    if (empty($merchantRequestId) || empty($checkoutRequestId)) {
        error_log('Missing merchant or checkout request ID');
        echo json_encode(['ResultCode' => 1, 'ResultDesc' => 'Missing required IDs']);
        exit;
    }

    try {
        // Use transaction for atomicity
        $pdo->beginTransaction();
        
        // Lock the transaction row to prevent concurrent processing
        $stmt = $pdo->prepare("
            SELECT * FROM transactions 
            WHERE checkout_request_id = ? AND merchant_request_id = ?
            FOR UPDATE
        ");
        $stmt->execute([$checkoutRequestId, $merchantRequestId]);
        $transaction = $stmt->fetch();

        if (!$transaction) {
            $pdo->rollBack();
            error_log("Transaction not found: $checkoutRequestId");
            echo json_encode(['ResultCode' => 1, 'ResultDesc' => 'Transaction not found']);
            exit;
        }

        // Check idempotency - prevent duplicate processing
        if ($transaction['status'] === 'completed') {
            $pdo->rollBack();
            error_log("Transaction already completed: $checkoutRequestId");
            echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Already processed']);
            exit;
        }

        // Log callback
        $logStmt = $pdo->prepare("
            INSERT INTO payment_logs (transaction_id, log_type, log_data, created_at) 
            VALUES (?, 'callback', ?, NOW())
        ");
        $logStmt->execute([$transaction['id'], $callbackData]);

        // Payment successful
        if ($resultCode == 0) {
            // Extract metadata
            $mpesaReceiptNumber = '';
            $amount = 0;
            $phoneNumber = '';
            $transactionDate = '';

            if (isset($stkCallback['CallbackMetadata']['Item'])) {
                foreach ($stkCallback['CallbackMetadata']['Item'] as $item) {
                    switch ($item['Name']) {
                        case 'MpesaReceiptNumber':
                            $mpesaReceiptNumber = $item['Value'];
                            break;
                        case 'Amount':
                            $amount = floatval($item['Value']);
                            break;
                        case 'PhoneNumber':
                            $phoneNumber = $item['Value'];
                            break;
                        case 'TransactionDate':
                            $transactionDate = $item['Value'];
                            break;
                    }
                }
            }

            // Verify amount matches (allow 1 shilling tolerance for floating point)
            if (abs($amount - $transaction['amount']) > 1) {
                throw new Exception("Amount mismatch: expected {$transaction['amount']}, received {$amount}");
            }

            // Verify phone number matches
            $expectedPhone = preg_replace('/[^0-9]/', '', $transaction['phone']);
            $receivedPhone = preg_replace('/[^0-9]/', '', $phoneNumber);
            
            if (substr($expectedPhone, -9) !== substr($receivedPhone, -9)) {
                error_log("Phone mismatch: expected {$expectedPhone}, received {$receivedPhone}");
                // Don't fail - log warning only as M-Pesa sometimes formats phones differently
            }

            // Create MikroTik user with retry logic
            $result = createMikrotikUserWithRetry(
                $transaction['username'],
                $transaction['password'],
                $transaction['plan']
            );

            if ($result['success']) {
                // Update transaction status
                $stmt = $pdo->prepare("
                    UPDATE transactions 
                    SET status = 'completed', 
                        mpesa_receipt = ?, 
                        mpesa_amount = ?,
                        mpesa_phone = ?,
                        completed_at = NOW() 
                    WHERE id = ?
                ");
                $stmt->execute([
                    $mpesaReceiptNumber, 
                    $amount, 
                    $phoneNumber,
                    $transaction['id']
                ]);

                // Insert or update user record
                $userStmt = $pdo->prepare("
                    INSERT INTO users 
                    (username, password, phone, plan, mac, mpesa_receipt, created_at, is_active) 
                    VALUES (?, ?, ?, ?, ?, ?, NOW(), 1)
                    ON DUPLICATE KEY UPDATE 
                    password = VALUES(password),
                    plan = VALUES(plan),
                    mac = VALUES(mac),
                    mpesa_receipt = VALUES(mpesa_receipt),
                    is_active = 1
                ");
                $userStmt->execute([
                    $transaction['username'],
                    $transaction['password'],
                    $transaction['phone'],
                    $transaction['plan'],
                    $transaction['mac'],
                    $mpesaReceiptNumber
                ]);

                // Commit transaction
                $pdo->commit();

                error_log("Payment processed successfully: {$transaction['username']} - Receipt: $mpesaReceiptNumber");
                echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Success']);
                
            } else {
                throw new Exception("MikroTik user creation failed: {$result['message']}");
            }

        } else {
            // Payment failed or cancelled
            $stmt = $pdo->prepare("
                UPDATE transactions 
                SET status = 'failed', 
                    error_message = ?,
                    failed_at = NOW() 
                WHERE id = ?
            ");
            $stmt->execute([$resultDesc, $transaction['id']]);
            
            $pdo->commit();

            error_log("Payment failed for {$transaction['username']}: $resultDesc (Code: $resultCode)");
            echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Payment failure recorded']);
        }

    } catch (Exception $e) {
        // Rollback transaction
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        
        error_log('Callback processing error: ' . $e->getMessage());

        // Update transaction with error
        if (isset($transaction['id'])) {
            try {
                $stmt = $pdo->prepare("
                    UPDATE transactions 
                    SET status = 'failed', 
                        error_message = ?,
                        failed_at = NOW() 
                    WHERE id = ?
                ");
                $stmt->execute([$e->getMessage(), $transaction['id']]);

                $logStmt = $pdo->prepare("
                    INSERT INTO payment_logs (transaction_id, log_type, log_data, created_at) 
                    VALUES (?, 'error', ?, NOW())
                ");
                $logStmt->execute([$transaction['id'], json_encode([
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ])]);
            } catch (Exception $logError) {
                error_log('Failed to log error: ' . $logError->getMessage());
            }
        }

        // Don't expose internal errors to M-Pesa
        echo json_encode(['ResultCode' => 1, 'ResultDesc' => 'Processing error']);
    }

    exit;
}

// Invalid method
http_response_code(405);
echo json_encode([
    'status' => 'error',
    'message' => 'Method not allowed',
    'username' => '',
    'password' => ''
]);
?>