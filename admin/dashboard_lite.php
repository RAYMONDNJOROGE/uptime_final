<?php
/**
 * MikroTik Hotspot Admin Dashboard
 * Manage hotspot users, sessions, and admin registration tokens
 */

// Secure session settings
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_secure', 0); // Set to 1 if using HTTPS

session_start();
$timeoutDuration = 1800; // 30 minutes

if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeoutDuration) {
    session_unset();
    session_destroy();
    header('Location: ../index.php?timeout=1');
    exit;
}

$_SESSION['last_activity'] = time();

// 🔐 Redirect to login if not authenticated
if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    header('Location: ../index.php');
    exit;
}

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../api/mikrotikapi.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$pdo = getDBConnection();

function generateCode($length = 6) {
    $characters = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $code = '';
    for ($i = 0; $i < $length; $i++) {
        $code .= $characters[random_int(0, strlen($characters) - 1)];
    }
    return $code;
}

// Initialize variables
$message = '';
$messageType = '';
$api = new MikrotikAPI();
$connectionTest = $api->testConnection();
$routerConnected = $connectionTest['success'];

// Initialize data with safe defaults
$allUsers = [];
$activeSessions = [];
$totalUsers = 0;
$activeUsers = 0;

// Try to fetch data only if connected
if ($routerConnected) {
    try {
        $allUsers = $api->getAllUsers();
        $activeSessions = $api->getActiveHotspotSessions();
        $totalUsers = count($allUsers);
        $activeUsers = count($activeSessions);
    } catch (Exception $e) {
        error_log("Error fetching MikroTik data: " . $e->getMessage());
    }
}

$allowed_roles = ['super', 'regular'];

if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed_roles)) {
    header('Location: logout.php');
    exit;
}


// Handle POST actions with PRG pattern
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'change_password':
            $current = $_POST['current_password'] ?? '';
            $new = $_POST['new_password'] ?? '';
            $confirm = $_POST['confirm_password'] ?? '';
            $username = $_SESSION['username'] ?? '';

            if ($current && $new && $confirm && $new === $confirm) {
                $stmt = $pdo->prepare("SELECT password FROM admins WHERE username = ? AND is_active = 1 LIMIT 1");
                $stmt->execute([$username]);
                $admin = $stmt->fetch();

                if ($admin && password_verify($current, $admin['password'])) {
                    $newHash = password_hash($new, PASSWORD_DEFAULT);
                    $pdo->prepare("UPDATE admins SET password = ? WHERE username = ?")->execute([$newHash, $username]);
                    $_SESSION['flash_message'] = 'Password updated successfully.';
                    $_SESSION['flash_type'] = 'success';
                } else {
                    $_SESSION['flash_message'] = 'Current password is incorrect.';
                    $_SESSION['flash_type'] = 'error';
                }
            } else {
                $_SESSION['flash_message'] = 'Please fill all fields and confirm your new password.';
                $_SESSION['flash_type'] = 'error';
            }
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;

        case 'delete_admin':
            $targetId = $_POST['admin_id'] ?? '';
            if ($targetId && is_numeric($targetId)) {
                $stmt = $pdo->prepare("SELECT role FROM admins WHERE id = ?");
                $stmt->execute([$targetId]);
                $target = $stmt->fetch();

                if ($target && $target['role'] === 'regular' && $targetId != $_SESSION['admin_id']) {
                    $pdo->prepare("DELETE FROM admins WHERE id = ?")->execute([$targetId]);
                    $_SESSION['flash_message'] = 'Regular admin deleted successfully.';
                    $_SESSION['flash_type'] = 'success';
                } else {
                    $_SESSION['flash_message'] = 'Cannot delete this admin.';
                    $_SESSION['flash_type'] = 'error';
                }
            }
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;

        case 'create_user':
            if (!$routerConnected) {
                $_SESSION['flash_message'] = 'Cannot create user: MikroTik router is not connected.';
                $_SESSION['flash_type'] = 'error';
                header('Location: ' . $_SERVER['PHP_SELF']);
                exit;
            }

            $plan = $_POST['plan'] ?? '';
            if (!empty($plan)) {
                $username = generateCode(6);
                $password = generateCode(6);
                
                try {
                    $success = $api->createUser($username, $password, $plan);

                    if ($success) {
                        $_SESSION['flash_credentials'] = [
                            'username' => $username,
                            'password' => $password,
                            'plan' => $plan
                        ];
                        $_SESSION['flash_type'] = 'credentials';
                    } else {
                        $_SESSION['flash_message'] = 'Failed to create user. Please check MikroTik connection and logs.';
                        $_SESSION['flash_type'] = 'error';
                    }
                } catch (Exception $e) {
                    $_SESSION['flash_message'] = 'Error creating user: ' . $e->getMessage();
                    $_SESSION['flash_type'] = 'error';
                }
            }
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;

        case 'change_plan':
            if (!$routerConnected) {
                $_SESSION['flash_message'] = 'Cannot change plan: MikroTik router is not connected.';
                $_SESSION['flash_type'] = 'error';
                header('Location: ' . $_SERVER['PHP_SELF']);
                exit;
            }

            $username = trim($_POST['username'] ?? '');
            $new_plan = $_POST['new_plan'] ?? '';
            
            if (!empty($username) && !empty($new_plan)) {
                try {
                    $userInfo = $api->getUserInfo($username);
                    
                    if ($userInfo && isset($userInfo['password'])) {
                        $password = $userInfo['password'];
                        $success = $api->createUser($username, $password, $new_plan);
                        
                        if ($success) {
                            $_SESSION['flash_message'] = "Successfully changed plan for $username to $new_plan";
                            $_SESSION['flash_type'] = 'success';
                        } else {
                            $_SESSION['flash_message'] = 'Failed to change plan. Please check logs.';
                            $_SESSION['flash_type'] = 'error';
                        }
                    } else {
                        $_SESSION['flash_message'] = 'User not found or unable to retrieve user information.';
                        $_SESSION['flash_type'] = 'error';
                    }
                } catch (Exception $e) {
                    $_SESSION['flash_message'] = 'Error changing plan: ' . $e->getMessage();
                    $_SESSION['flash_type'] = 'error';
                }
            }
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;

        case 'generate_token':
            $recipientEmail = trim($_POST['recipient_email'] ?? '');

            if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
                $_SESSION['flash_message'] = 'Invalid email address.';
                $_SESSION['flash_type'] = 'error';
                header('Location: ' . $_SERVER['PHP_SELF']);
                exit;
            }

            try {
                $token = bin2hex(random_bytes(16));
                $expires = date('Y-m-d H:i:s', strtotime('+5 minutes'));
                $adminId = $_SESSION['admin_id'];

                $stmt = $pdo->prepare("INSERT INTO registration_tokens (token, recipient_email, expires_at, created_by) VALUES (?, ?, ?, ?)");
                $stmt->execute([$token, $recipientEmail, $expires, $adminId]);

                $mail = new PHPMailer(true);
                $mail->isSMTP();
                $mail->Host = 'smtp.gmail.com';
                $mail->SMTPAuth = true;
                $mail->Username = 'uptimetechmasters@gmail.com';
                $mail->Password = 'aszhqrssbadrfyml';
                $mail->SMTPSecure = 'tls';
                $mail->Port = 587;

                $mail->setFrom('uptimetechmasters@gmail.com', 'Uptime Hotspot');
                $mail->addAddress($recipientEmail);
                $mail->Subject = 'Your Admin Registration Token';
                $mail->Body = "Hello,\n\nYou've been authorized to register.\n\nCopy this token and use it during registration:\n\n$token\n\nThis token expires in 5 minutes.";

                $mail->send();
                $_SESSION['flash_message'] = "Token sent to $recipientEmail";
                $_SESSION['flash_type'] = 'success';
            } catch (Exception $e) {
                $_SESSION['flash_message'] = 'Failed to send email: ' . $mail->ErrorInfo;
                $_SESSION['flash_type'] = 'error';
            }
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;

        case 'refresh':
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
    }
}

// Display flash messages
if (isset($_SESSION['flash_message'])) {
    $message = $_SESSION['flash_message'];
    $messageType = $_SESSION['flash_type'];
    unset($_SESSION['flash_message'], $_SESSION['flash_type']);
}

// Handle credentials display
$showCredentials = false;
$credentials = [];
if (isset($_SESSION['flash_credentials'])) {
    $showCredentials = true;
    $credentials = $_SESSION['flash_credentials'];
    unset($_SESSION['flash_credentials']);
}

// Fetch regular admins
$regularAdmins = [];
$stmt = $pdo->query("SELECT id, username, email, created_at FROM admins WHERE role = 'regular'");
$regularAdmins = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Uptime Admin Dashboard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        :root {
            --primary: #667eea;
            --primary-dark: #5568d3;
            --secondary: #764ba2;
            --success: #38a169;
            --danger: #e53e3e;
            --warning: #dd6b20;
            --dark: #1a202c;
            --dark-light: #2d3748;
            --gray: #4a5568;
            --gray-light: #cbd5e0;
            --text: #e2e8f0;
            --text-dim: #a0aec0;
        }
        
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: linear-gradient(135deg, #1a202c 0%, #2d3748 100%);
            min-height: 100vh;
            color: var(--text);
        }
        
        /* Header */
        .header {
            background: var(--dark);
            border-bottom: 3px solid var(--primary);
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
            position: sticky;
            top: 0;
            z-index: 100;
        }
        
        .header-content {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .header h1 {
            font-size: 1.5rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 12px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .header-actions {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .status-badge {
            padding: 8px 16px;
            border-radius: 25px;
            font-size: 0.875rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
        }
        
        .status-connected {
            background: rgba(56, 161, 105, 0.2);
            color: #68d391;
            border: 2px solid #68d391;
        }
        
        .status-disconnected {
            background: rgba(229, 62, 62, 0.2);
            color: #fc8181;
            border: 2px solid #fc8181;
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.6; }
        }
        
        .logout-button {
            background: var(--danger);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .logout-button:hover {
            background: #c53030;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(229, 62, 62, 0.4);
        }
        
        /* Container */
        .container {
            max-width: 1400px;
            margin: 30px auto;
            padding: 0 30px 30px;
        }
        
        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: linear-gradient(135deg, var(--dark) 0%, var(--dark-light) 100%);
            padding: 25px;
            border-radius: 16px;
            border: 1px solid var(--gray);
            display: flex;
            align-items: center;
            gap: 20px;
            transition: all 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 24px rgba(102, 126, 234, 0.2);
        }
        
        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
        }
        
        .stat-icon.purple {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
        }
        
        .stat-icon.green {
            background: linear-gradient(135deg, #38a169, #48bb78);
        }
        
        .stat-content h3 {
            font-size: 2rem;
            margin-bottom: 5px;
        }
        
        .stat-content p {
            font-size: 0.875rem;
            color: var(--text-dim);
        }
        
        /* Dashboard Grid */
        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 25px;
            margin-bottom: 25px;
        }
        
        /* Cards */
        .card {
            background: var(--dark);
            border-radius: 16px;
            border: 1px solid var(--gray);
            overflow: hidden;
            transition: all 0.3s ease;
        }
        
        .card:hover {
            box-shadow: 0 8px 24px rgba(0,0,0,0.3);
        }
        
        .card-header {
            padding: 20px 25px;
            border-bottom: 1px solid var(--gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .card-header h2 {
            font-size: 1.125rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .card-body {
            padding: 25px;
        }
        
        /* Form Styles */
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            font-size: 0.875rem;
            color: var(--gray-light);
        }
        
        .form-group input,
        .form-group select {
            width: 100%;
            padding: 12px 16px;
            background: var(--dark-light);
            border: 2px solid var(--gray);
            border-radius: 10px;
            color: var(--text);
            font-size: 0.95rem;
            transition: all 0.3s ease;
        }
        
        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        /* Buttons */
        .btn {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 0.95rem;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.4);
        }
        
        .btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
        }
        
        .btn-danger {
            background: var(--danger);
        }
        
        .btn-danger:hover {
            background: #c53030;
            box-shadow: 0 6px 20px rgba(229, 62, 62, 0.4);
        }
        
        /* Credentials Box */
        .credentials-box {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            padding: 30px;
            border-radius: 16px;
            margin-bottom: 25px;
            animation: slideIn 0.4s ease-out;
        }
        
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .credentials-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-top: 20px;
        }
        
        .credential-item {
            background: rgba(255,255,255,0.15);
            padding: 20px;
            border-radius: 12px;
            backdrop-filter: blur(10px);
        }
        
        .credential-label {
            font-size: 0.75rem;
            opacity: 0.9;
            margin-bottom: 8px;
            font-weight: 600;
            letter-spacing: 1px;
        }
        
        .credential-value {
            font-size: 1.75rem;
            font-weight: 700;
            font-family: 'Courier New', monospace;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }
        
        .copy-btn {
            background: rgba(255,255,255,0.2);
            border: 1px solid rgba(255,255,255,0.3);
            color: white;
            padding: 10px 15px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 0.875rem;
            font-weight: 600;
            transition: all 0.2s;
        }
        
        .copy-btn:hover {
            background: rgba(255,255,255,0.3);
        }
        
        .copy-btn.copied {
            background: var(--success);
        }
        
        /* Alerts */
        .alert {
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            animation: slideIn 0.3s ease-out;
        }
        
        .alert-success {
            background: rgba(56, 161, 105, 0.2);
            color: #68d391;
            border: 1px solid #68d391;
        }
        
        .alert-error {
            background: rgba(229, 62, 62, 0.2);
            color: #fc8181;
            border: 1px solid #fc8181;
        }
        
        .alert-warning {
            background: rgba(221, 107, 32, 0.2);
            color: #f6ad55;
            border: 1px solid #f6ad55;
        }
        
        /* Tables */
        .table-container {
            overflow-x: auto;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        table th {
            background: var(--dark-light);
            padding: 14px 16px;
            text-align: left;
            font-weight: 600;
            font-size: 0.875rem;
            color: var(--gray-light);
            border-bottom: 2px solid var(--gray);
        }
        
        table td {
            padding: 14px 16px;
            border-bottom: 1px solid var(--gray);
            font-size: 0.95rem;
        }
        
        table tr:hover {
            background: var(--dark-light);
        }
        
        /* Badge */
        .badge {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .badge-success {
            background: rgba(56, 161, 105, 0.2);
            color: #68d391;
        }
        
        .badge-warning {
            background: rgba(221, 107, 32, 0.2);
            color: #f6ad55;
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-dim);
        }
        
        .empty-state i {
            font-size: 4rem;
            margin-bottom: 20px;
            opacity: 0.3;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .dashboard-grid {
                grid-template-columns: 1fr;
            }
            .credentials-grid {
                grid-template-columns: 1fr;
            }
            .container {
                padding: 0 15px 15px;
            }
            .stat-card {
                padding: 20px;
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <div class="header">
        <div class="header-content">
            <h1>
                <i class="fas fa-chart-line"></i>
                Welcome Admin: <?= htmlspecialchars($_SESSION['username'] ?? ''); ?>
            </h1>
            <div class="header-actions">
                <div class="status-badge <?= $routerConnected ? 'status-connected' : 'status-disconnected'; ?>">
                    <i class="fas fa-circle"></i>
                    <?= $routerConnected ? 'Connected' : 'Disconnected'; ?>
                    <?php if ($routerConnected): ?>
                        <span style="opacity: 0.8;">(<?= $connectionTest['time']; ?>)</span>
                    <?php endif; ?>
                </div>
                <a href="logout.php" class="logout-button">
                    <i class="fas fa-sign-out-alt"></i>
                    Logout
                </a>
            </div>
        </div>
    </div>

    <div class="container">
        <!-- Router Connection Error -->
        <?php if (!$routerConnected): ?>
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle"></i>
                <div>
                    <strong>Router Connection Failed</strong><br>
                    <small>Some features are unavailable. Please check your MikroTik connection settings.</small>
                </div>
            </div>
        <?php endif; ?>

        <!-- Flash Messages -->
        <?php if ($message): ?>
            <div class="alert alert-<?= $messageType ?>">
                <i class="fas fa-<?= $messageType === 'success' ? 'check-circle' : 'exclamation-circle' ?>"></i>
                <span><?= htmlspecialchars($message) ?></span>
            </div>
        <?php endif; ?>

        <!-- Credentials Display -->
        <?php if ($showCredentials): ?>
            <div class="credentials-box">
                <h3 style="margin-bottom: 5px; font-size: 1.5rem;">✅ User Created Successfully!</h3>
                <p style="opacity: 0.9; font-size: 0.95rem;">Share these credentials with your client</p>
                <div class="credentials-grid">
                    <div class="credential-item">
                        <div class="credential-label">USERNAME</div>
                        <div class="credential-value">
                            <span id="username-value"><?= $credentials['username'] ?></span>
                            <button class="copy-btn" onclick="copyToClipboard('username-value', this)">
                                <i class="fas fa-copy"></i> Copy
                            </button>
                        </div>
                    </div>
                    <div class="credential-item">
                        <div class="credential-label">PASSWORD</div>
                        <div class="credential-value">
                            <span id="password-value"><?= $credentials['password'] ?></span>
                            <button class="copy-btn" onclick="copyToClipboard('password-value', this)">
                                <i class="fas fa-copy"></i> Copy
                            </button>
                        </div>
                    </div>
                </div>
                <div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid rgba(255,255,255,0.2);">
                    <p style="font-size: 0.95rem; opacity: 0.9;">Plan: <strong><?= htmlspecialchars($credentials['plan']) ?></strong></p>
                    <button class="copy-btn" onclick="copyBoth('<?= $credentials['username'] ?>', '<?= $credentials['password'] ?>', this)" style="margin-top: 12px;">
                        <i class="fas fa-copy"></i> Copy Both (Username & Password)
                    </button>
                </div>
            </div>
        <?php endif; ?>

        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon purple">
                    <i class="fas fa-users"></i>
                </div>
                <div class="stat-content">
                    <h3><?= $totalUsers ?></h3>
                    <p>Total Users</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon green">
                    <i class="fas fa-signal"></i>
                </div>
                <div class="stat-content">
                    <h3><?= $activeUsers ?></h3>
                    <p>Active Sessions</p>
                </div>
            </div>
        </div>

        <!-- Main Actions -->
        <div class="dashboard-grid">
            <!-- Create User -->
            <div class="card">
                <div class="card-header">
                    <h2><i class="fas fa-user-plus"></i> Create New User</h2>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="create_user">
                        <div class="form-group">
                            <label><i class="fas fa-clock"></i> Select Plan</label>
                            <select name="plan" required <?= !$routerConnected ? 'disabled' : '' ?>>
                                <option value="">-- Choose a plan --</option>
                                <option value="30min">⏱️ 30 Minutes</option>
                                <option value="2h">⏱️ 2 Hours</option>
                                <option value="12h">⏱️ 12 Hours</option>
                                <option value="24h">⏱️ 24 Hours (1 Day)</option>
                                <option value="48h">⏱️ 48 Hours (2 Days)</option>
                                <option value="1w">⏱️ 1 Week (7 Days)</option>
                            </select>
                        </div>
                        <button type="submit" class="btn" <?= !$routerConnected ? 'disabled' : '' ?>>
                            <i class="fas fa-ticket-alt"></i>
                            Generate User
                        </button>
                    </form>
                </div>
            </div>

            <!-- Change Plan -->
            <div class="card">
                <div class="card-header">
                    <h2><i class="fas fa-sync-alt"></i> Change User Plan</h2>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="change_plan">
                        <div class="form-group">
                            <label><i class="fas fa-user"></i> Username</label>
                            <input type="text" name="username" placeholder="Enter username" required <?= !$routerConnected ? 'disabled' : '' ?>>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-clock"></i> New Plan</label>
                            <select name="new_plan" required <?= !$routerConnected ? 'disabled' : '' ?>>
                                <option value="">-- Choose new plan --</option>
                                <option value="30min">⏱️ 30 Minutes</option>
                                <option value="2h">⏱️ 2 Hours</option>
                                <option value="12h">⏱️ 12 Hours</option>
                                <option value="24h">⏱️ 24 Hours (1 Day)</option>
                                <option value="48h">⏱️ 48 Hours (2 Days)</option>
                                <option value="1w">⏱️ 1 Week (7 Days)</option>
                            </select>
                        </div>
                        <button type="submit" class="btn" <?= !$routerConnected ? 'disabled' : '' ?>>
                            <i class="fas fa-sync-alt"></i>
                            Change Plan
                        </button>
                    </form>
                </div>
            </div>
        </div>

         <!-- Admin Management -->
        <div class="dashboard-grid">
            <!-- Change Password -->
            <div class="card">
                <div class="card-header">
                    <h2><i class="fas fa-key"></i> Change Password</h2>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="change_password">
                        <div class="form-group">
                            <label><i class="fas fa-lock"></i> Current Password</label>
                            <input type="password" name="current_password" required>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-lock"></i> New Password</label>
                            <input type="password" name="new_password" required>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-lock"></i> Confirm Password</label>
                            <input type="password" name="confirm_password" required>
                        </div>
                        <button type="submit" class="btn">
                            <i class="fas fa-save"></i>
                            Update Password
                        </button>
                    </form>
                </div>
            </div>


        <!-- Quick Actions -->
        <div class="card" style="margin-top: 25px;">
            <div class="card-header">
                <h2><i class="fas fa-bolt"></i> Quick Actions</h2>
            </div>
            <div class="card-body">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                    <div style="padding: 15px; background: var(--dark-light); border-radius: 10px; border: 1px solid var(--gray);">
                        <p style="font-size: 0.875rem; color: var(--text-dim); margin-bottom: 5px;">
                            <i class="fas fa-router"></i> Router
                        </p>
                        <p style="font-weight: 600;">
                            <?= $routerConnected ? htmlspecialchars($connectionTest['router']) : 'Not Connected'; ?>
                        </p>
                    </div>
                    <div style="padding: 15px; background: var(--dark-light); border-radius: 10px; border: 1px solid var(--gray);">
                        <p style="font-size: 0.875rem; color: var(--text-dim); margin-bottom: 5px;">
                            <i class="fas fa-tachometer-alt"></i> Response Time
                        </p>
                        <p style="font-weight: 600;">
                            <?= $connectionTest['time'] ?? 'N/A'; ?>
                        </p>
                    </div>
                    <div style="padding: 15px; background: var(--dark-light); border-radius: 10px; border: 1px solid var(--gray);">
                        <form method="POST" style="margin: 0;">
                            <input type="hidden" name="action" value="refresh">
                            <button type="submit" class="btn" style="width: 100%; margin: 0;">
                                <i class="fas fa-sync-alt"></i>
                                Refresh Dashboard
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>


        <!-- Active Sessions -->
        <div class="card" style="margin-bottom: 25px; margin-left: 20px; margin-right: 20px;">
            <div class="card-header">
                <h2><i class="fas fa-broadcast-tower"></i> Active Hotspot Sessions</h2>
                <span class="badge badge-success"><?= $activeUsers ?> Active</span>
            </div>
            <div class="card-body" style="padding: 0;">
                <?php if ($routerConnected && !empty($activeSessions)): ?>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th><i class="fas fa-user"></i> Username</th>
                                    <th><i class="fas fa-network-wired"></i> IP Address</th>
                                    <th><i class="fas fa-ethernet"></i> MAC Address</th>
                                    <th><i class="fas fa-clock"></i> Uptime</th>
                                    <th><i class="fas fa-hourglass-half"></i> Time Left</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($activeSessions as $session): ?>
                                    <?php if (isset($session['user'])): ?>
                                        <tr>
                                            <td><strong><?= htmlspecialchars($session['user']) ?></strong></td>
                                            <td><?= htmlspecialchars($session['address'] ?? 'N/A') ?></td>
                                            <td><code><?= htmlspecialchars($session['mac-address'] ?? 'N/A') ?></code></td>
                                            <td><?= htmlspecialchars($session['uptime'] ?? 'N/A') ?></td>
                                            <td><?= htmlspecialchars($session['session-time-left'] ?? 'N/A') ?></td>
                                        </tr>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-broadcast-tower"></i>
                        <p><?= $routerConnected ? 'No active sessions at the moment' : 'Router not connected' ?></p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- All Users -->
        <div class="card" style="margin-bottom: 25px; margin-left: 20px; margin-right: 20px;">
            <div class="card-header">
                <h2><i class="fas fa-users"></i> All Hotspot Users</h2>
                <span class="badge badge-warning"><?= $totalUsers ?> Total</span>
            </div>
            <div class="card-body" style="padding: 0;">
                <?php if ($routerConnected && !empty($allUsers)): ?>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th><i class="fas fa-user"></i> Username</th>
                                    <th><i class="fas fa-tag"></i> Profile</th>
                                    <th><i class="fas fa-clock"></i> Uptime Limit</th>
                                    <th><i class="fas fa-key"></i> Password</th>
                                    <th><i class="fas fa-comment"></i> Comment</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($allUsers as $user): ?>
                                    <?php if (isset($user['name'])): ?>
                                        <tr>
                                            <td><strong><?= htmlspecialchars($user['name']) ?></strong></td>
                                            <td><span class="badge badge-success"><?= htmlspecialchars($user['profile'] ?? 'default') ?></span></td>
                                            <td><?= htmlspecialchars($user['limit-uptime'] ?? 'Unlimited') ?></td>
                                            <td><?= htmlspecialchars($user['password'] ?? 'N/A') ?></td>
                                            <td><?= htmlspecialchars($user['comment'] ?? '-') ?></td>
                                        </tr>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-users"></i>
                        <p><?= $routerConnected ? 'No users found in the system' : 'Router not connected' ?></p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

       
    <script>
        function copyToClipboard(id, btn) {
            const text = document.getElementById(id).textContent;
            navigator.clipboard.writeText(text).then(() => {
                const original = btn.innerHTML;
                btn.innerHTML = '<i class="fas fa-check"></i> Copied!';
                btn.classList.add('copied');
                setTimeout(() => {
                    btn.innerHTML = original;
                    btn.classList.remove('copied');
                }, 2000);
            }).catch(err => {
                alert('Failed to copy: ' + err);
            });
        }

        function copyBoth(username, password, btn) {
            const text = `Username: ${username}\nPassword: ${password}`;
            navigator.clipboard.writeText(text).then(() => {
                const original = btn.innerHTML;
                btn.innerHTML = '<i class="fas fa-check"></i> Copied Both!';
                btn.classList.add('copied');
                setTimeout(() => {
                    btn.innerHTML = original;
                    btn.classList.remove('copied');
                }, 2000);
            }).catch(err => {
                alert('Failed to copy: ' + err);
            });
        }
    </script>
</body>
</html>