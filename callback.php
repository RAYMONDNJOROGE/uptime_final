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

require_once 'config.php';
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

// Handle status check (GET)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['checkoutRequestId'])) {
    $checkoutRequestId = $_GET['checkoutRequestId'];

    // Log GET request
    file_put_contents(__DIR__ . '/callback_debug.log', date('c') . " - GET: " . json_encode($_GET) . "\n", FILE_APPEND);

    try {
        $stmt = $pdo->prepare("
            SELECT status, username, password, error_message 
            FROM transactions 
            WHERE checkout_request_id = ?
        ");
        $stmt->execute([$checkoutRequestId]);
        $transaction = $stmt->fetch();

        if ($transaction) {
            echo json_encode([
                'status' => $transaction['status'],
                'username' => $transaction['username'] ?? '',
                'password' => $transaction['password'] ?? '',
                'message' => $transaction['error_message'] ?? ''
            ]);
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

// Handle M-Pesa callback (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $callbackData = file_get_contents('php://input');

    // Log raw callback
    file_put_contents(__DIR__ . '/mpesa_callbacks.log', date('Y-m-d H:i:s') . " - " . $callbackData . "\n", FILE_APPEND);

    $callback = json_decode($callbackData, true);

    if (!isset($callback['Body']['stkCallback'])) {
        error_log('Invalid callback structure');
        echo json_encode(['ResultCode' => 1, 'ResultDesc' => 'Invalid callback data']);
        exit;
    }

    $stkCallback = $callback['Body']['stkCallback'];
    $merchantRequestId = $stkCallback['MerchantRequestID'];
    $checkoutRequestId = $stkCallback['CheckoutRequestID'];
    $resultCode = $stkCallback['ResultCode'];
    $resultDesc = $stkCallback['ResultDesc'];

    try {
        $stmt = $pdo->prepare("
            SELECT * FROM transactions 
            WHERE checkout_request_id = ? AND merchant_request_id = ?
        ");
        $stmt->execute([$checkoutRequestId, $merchantRequestId]);
        $transaction = $stmt->fetch();

        if (!$transaction) {
            throw new Exception('Transaction not found');
        }

        // Log callback
        $logStmt = $pdo->prepare("
            INSERT INTO payment_logs (transaction_id, log_type, log_data, created_at) 
            VALUES (?, 'callback', ?, NOW())
        ");
        $logStmt->execute([$transaction['id'], $callbackData]);

        if ($resultCode == 0) {
            // Extract metadata
            $mpesaReceiptNumber = '';
            $amount = 0;
            $phoneNumber = '';

            if (isset($stkCallback['CallbackMetadata']['Item'])) {
                foreach ($stkCallback['CallbackMetadata']['Item'] as $item) {
                    switch ($item['Name']) {
                        case 'MpesaReceiptNumber':
                            $mpesaReceiptNumber = $item['Value'];
                            break;
                        case 'Amount':
                            $amount = $item['Value'];
                            break;
                        case 'PhoneNumber':
                            $phoneNumber = $item['Value'];
                            break;
                    }
                }
            }

            // Create MikroTik user
            $mikrotik = new MikrotikAPI();
            $created = $mikrotik->createUser(
                $transaction['username'],
                $transaction['password'],
                $transaction['plan']
            );

            if ($created) {
                $stmt = $pdo->prepare("
                    UPDATE transactions 
                    SET status = 'completed', 
                        mpesa_receipt = ?, 
                        completed_at = NOW() 
                    WHERE id = ?
                ");
                $stmt->execute([$mpesaReceiptNumber, $transaction['id']]);

                $userStmt = $pdo->prepare("
                    INSERT INTO users 
                    (username, password, phone, plan, mac, created_at, is_active) 
                    VALUES (?, ?, ?, ?, ?, NOW(), 1)
                    ON DUPLICATE KEY UPDATE 
                    password = VALUES(password),
                    plan = VALUES(plan),
                    mac = VALUES(mac)
                ");
                $userStmt->execute([
                    $transaction['username'],
                    $transaction['password'],
                    $transaction['phone'],
                    $transaction['plan'],
                    $transaction['mac']
                ]);

                error_log("User created successfully: " . $transaction['username']);
                echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Success']);
            } else {
                throw new Exception('Failed to create MikroTik user');
            }

        } else {
            $stmt = $pdo->prepare("
                UPDATE transactions 
                SET status = 'failed', 
                    error_message = ? 
                WHERE id = ?
            ");
            $stmt->execute([$resultDesc, $transaction['id']]);

            error_log("Payment failed: $resultDesc");
            echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Payment failed recorded']);
        }

    } catch (Exception $e) {
        error_log('Callback processing error: ' . $e->getMessage());

        if (isset($transaction['id'])) {
            try {
                $stmt = $pdo->prepare("
                    UPDATE transactions 
                    SET status = 'failed', 
                        error_message = ? 
                    WHERE id = ?
                ");
                $stmt->execute([$e->getMessage(), $transaction['id']]);

                $logStmt = $pdo->prepare("
                    INSERT INTO payment_logs (transaction_id, log_type, log_data, created_at) 
                    VALUES (?, 'error', ?, NOW())
                ");
                $logStmt->execute([$transaction['id'], $e->getMessage()]);
            } catch (Exception $logError) {
                error_log('Failed to log error: ' . $logError->getMessage());
            }
        }

        echo json_encode(['ResultCode' => 1, 'ResultDesc' => $e->getMessage()]);
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