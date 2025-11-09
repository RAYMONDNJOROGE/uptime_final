<?php
/**
 * MikroTik Hotspot Admin Dashboard
 * Manage hotspot users, sessions, and admin registration tokens
 */

// Secure session settings — must be before session_start()
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_secure', 0); // Set to 1 if using HTTPS

session_start();
$timeoutDuration = 300; // 300 seconds = 5 minutes

if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeoutDuration) {
    session_unset();
    session_destroy();
    header('Location: index.php?timeout=1');
    exit;
}

$_SESSION['last_activity'] = time();
// 🔐 Redirect to login if not authenticated
if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    header('Location: ../../index.php');
    exit;
}

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/api/mikrotikapi.php';
require_once __DIR__ . '/vendor/autoload.php'; // PHPMailer

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$pdo = getDBConnection();
header('Content-Type: text/html; charset=utf-8');

function generateCode($length = 6) {
    $characters = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $code = '';
    for ($i = 0; $i < $length; $i++) {
        $code .= $characters[random_int(0, strlen($characters) - 1)];
    }
    return $code;
}

$api = new MikrotikAPI();
$connectionTest = $api->testConnection();
$changePasswordMessage = '';

if (!isset($_SESSION['authenticated']) || $_SESSION['role'] !== 'super') {
    header('Location: dashboard_lite.php');
    exit;
}

// 🔄 Handle POST actions
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
                    $changePasswordMessage = '<div class="alert alert-success">Password updated successfully.</div>';
                } else {
                    $changePasswordMessage = '<div class="alert alert-error">Current password is incorrect.</div>';
                }
            } else {
                $changePasswordMessage = '<div class="alert alert-error">Please fill all fields and confirm your new password.</div>';
            }
            break;

        case 'delete_admin':
                $targetId = $_POST['admin_id'] ?? '';
                if ($targetId && is_numeric($targetId)) {
                    // Prevent deleting self or other super admins
                    $stmt = $pdo->prepare("SELECT role FROM admins WHERE id = ?");
                    $stmt->execute([$targetId]);
                    $target = $stmt->fetch();

                        if ($target && $target['role'] === 'regular' && $targetId != $_SESSION['admin_id']) {
                            $pdo->prepare("DELETE FROM admins WHERE id = ?")->execute([$targetId]);
                            $changePasswordMessage = '<div class="alert alert-success">Regular admin deleted successfully.</div>';
                        } else {
                            $changePasswordMessage = '<div class="alert alert-error">Cannot delete this admin.</div>';
                        }
                }
                break;

        case 'create_user':
            $plan = $_POST['plan'] ?? '';
            if (!empty($plan)) {
                $username = generateCode(6);
                $password = generateCode(6);
                $success = $api->createUser($username, $password, $plan);

                if ($success) {
                    $changePasswordMessage = '
                    <div class="credentials-box">
                        <h3 style="margin-bottom: 5px;">User Created Successfully!</h3>
                        <p style="opacity: 0.9; font-size: 14px;">Share these credentials with your client</p>
                        <div class="credentials-grid">
                            <div class="credential-item">
                                <div class="credential-label">USERNAME</div>
                                <div class="credential-value">
                                    <span id="username-value">' . $username . '</span>
                                    <button class="copy-btn" onclick="copyToClipboard(\'username-value\', this)">📋 Copy</button>
                                </div>
                            </div>
                            <div class="credential-item">
                                <div class="credential-label">PASSWORD</div>
                                <div class="credential-value">
                                    <span id="password-value">' . $password . '</span>
                                    <button class="copy-btn" onclick="copyToClipboard(\'password-value\', this)">📋 Copy</button>
                                </div>
                            </div>
                        </div>
                        <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid rgba(255,255,255,0.2);">
                            <p style="font-size: 13px; opacity: 0.9;">Plan: <strong>' . htmlspecialchars($plan) . '</strong></p>
                            <button class="copy-btn" onclick="copyBoth(\'' . $username . '\', \'' . $password . '\', this)" style="margin-top: 10px;">📋 Copy Both (Username & Password)</button>
                        </div>
                    </div>';
                } else {
                    $changePasswordMessage = '<div class="alert alert-error">Failed to create user. Please check MikroTik connection and logs.</div>';
                }
            }
            break;

        case 'generate_token':
            if ($_SESSION['role'] !== 'super') {
                $changePasswordMessage = '<div class="alert alert-error">Access denied. Only super admins can generate tokens.</div>';
                break;
            }

            $recipientEmail = trim($_POST['recipient_email'] ?? '');

            if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
                $changePasswordMessage = '<div class="alert alert-error">Invalid email address.</div>';
                break;
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
                $mail->Body = "Hello,\n\nYou’ve been authorized to register as an admin.\n\nUse this token during registration:\n\n<span>$token</span>\n\nThis token expires in 5 minutes.";

                $mail->send();
                $changePasswordMessage = '<div class="alert alert-success">Token sent to ' . htmlspecialchars($recipientEmail) . '.</div>';
            } catch (Exception $e) {
                $changePasswordMessage = '<div class="alert alert-error">Failed to send email: ' . htmlspecialchars($mail->ErrorInfo) . '</div>';
            }
            break;

        case 'refresh':
            $connectionTest = $api->testConnection();
            break;
    }
}

// 📊 Fetch data
$allUsers = $api->getAllUsers();
$activeSessions = $api->getActiveHotspotSessions();
$totalUsers = count($allUsers);
$activeUsers = count($activeSessions);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Uptime Admin Dashboard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="dashboard.css"> <!-- Optional: externalize styles -->
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f0f2f5;
            min-height: 100vh;
        }
        
        /* Header */
        .header {
    background: linear-gradient(90deg, #2d3748, #1a202c);
    color: #fff;
    padding: 15px 25px;
    border-bottom: 2px solid #4a5568;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.2);
}

.header-content {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 10px;
}

/* ===== Title ===== */
.header-content h1 {
    font-size: 1.3rem;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 8px;
    margin: 0;
}

/* ===== Connection Status ===== */
.status-badge {
    font-size: 0.95rem;
    padding: 6px 12px;
    border-radius: 20px;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-weight: 500;
}

.status-badge span:first-child {
    font-size: 1.1rem;
}

/* Connected / Disconnected Styles */
.status-connected {
    background-color: rgba(56, 161, 105, 0.15);
    color: #48bb78;
    border: 1px solid #48bb78;
}

.status-disconnected {
    background-color: rgba(229, 62, 62, 0.15);
    color: #f56565;
    border: 1px solid #f56565;
}

/* ===== Logout Button ===== */
.logout-button {
    background-color: #e53e3e;
    color: #fff;
    border: none;
    padding: 8px 16px;
    border-radius: 6px;
    font-weight: 500;
    cursor: pointer;
    text-decoration: none;
    transition: background-color 0.25s ease, transform 0.1s ease;
}

.logout-button:hover {
    background-color: #c53030;
    transform: scale(1.03);
}

.logout-button:active {
    background-color: #9b2c2c;
    transform: scale(0.98);
}


    .logout-button {
        width: 100%;
        text-align: center;
    }
        
        /* Main Container */
        .container {
            max-width: 1400px;
            margin: 30px auto;
            padding: 0 30px;
        }
        
        /* Dashboard Grid */
        .dashboard-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 25px;
            margin-bottom: 25px;
        }
        
        .card {
    background: #1a202c;
    color: #e2e8f0;
    border-radius: 10px;
    padding: 20px;
    margin-top: 20px;
    box-shadow: 0 2px 6px rgba(0,0,0,0.25);
    border: 1px solid #2d3748;
}

/* ===== Card Header ===== */
.card-header {
    border-bottom: 1px solid #2d3748;
    margin-bottom: 15px;
    padding-bottom: 8px;
}

.card-header h2 {
    font-size: 1.1rem;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 8px;
    color: #63b3ed;
}

/* ===== Form Styles ===== */
.form-group {
    margin-bottom: 16px;
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.form-group label {
    font-size: 0.9rem;
    font-weight: 500;
    color: #cbd5e0;
    letter-spacing: 0.3px;
    transition: color 0.25s ease;
}

.form-group input {
    background: #2d3748;
    border: 1px solid #4a5568;
    color: #edf2f7;
    border-radius: 8px;
    padding: 10px 12px;
    font-size: 0.95rem;
    outline: none;
    transition: border-color 0.25s ease, box-shadow 0.25s ease, background 0.25s ease;
}

/* Hover + Focus effects */
.form-group input:hover {
    border-color: #718096;
}

.form-group input:focus {
    border-color: #63b3ed;
    background: #1a202c;
    box-shadow: 0 0 0 2px rgba(99,179,237,0.25);
}

/* Optional: invalid field visual */
.form-group input:invalid {
    border-color: #e53e3e;
    box-shadow: 0 0 0 1px rgba(229,62,62,0.2);
}

/* ===== Submit Button ===== */
.btn {
    background-color: #3182ce;
    color: #fff;
    border: none;
    padding: 10px 18px;
    border-radius: 6px;
    font-weight: 500;
    cursor: pointer;
    transition: background-color 0.25s ease, transform 0.1s ease;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    font-size: 0.95rem;
}

.btn:hover {
    background-color: #2b6cb0;
    transform: scale(1.03);
}

.btn:active {
    background-color: #2c5282;
    transform: scale(0.98);
}

.btn-icon {
    font-size: 1.1rem;
}
        
        /* Credentials Display */
        .credentials-box {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 25px;
            border-radius: 12px;
            margin-top: 20px;
        }
        .credentials-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-top: 15px;
        }
        .credential-item {
            background: rgba(255,255,255,0.15);
            padding: 15px;
            border-radius: 8px;
        }
        .credential-label {
            font-size: 12px;
            opacity: 0.9;
            margin-bottom: 5px;
        }
        .credential-value {
            font-size: 24px;
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
            padding: 8px 12px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 12px;
            transition: all 0.2s;
        }
        .copy-btn:hover {
            background: rgba(255,255,255,0.3);
        }
        .copy-btn.copied {
            background: rgba(40, 167, 69, 0.8);
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
            background: #f8f9fa;
            padding: 12px 15px;
            text-align: left;
            font-weight: 600;
            color: #555;
            font-size: 13px;
            border-bottom: 2px solid #e0e0e0;
        }
        table td {
            padding: 12px 15px;
            border-bottom: 1px solid #f0f0f0;
            font-size: 14px;
            color: #fff;
        }
        table tr:hover {
            background: #555;
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #999;
        }
        .empty-state svg {
            width: 64px;
            height: 64px;
            margin-bottom: 15px;
            opacity: 0.5;
        }
        
        /* Messages */
        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .alert-info {
            background: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
        }

        .logout-button {
    background-color: #e53e3e;
    color: #fff;
    border: none;
    padding: 8px 16px;
    border-radius: 6px;
    font-weight: 500;
    cursor: pointer;
    transition: background-color 0.25s ease, transform 0.1s ease;
}

.logout-button:hover {
    background-color: #c53030;
    transform: scale(1.03);
}

.logout-button:active {
    background-color: #9b2c2c;
    transform: scale(0.98);
}

        
        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }
        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            display: flex;
            align-items: center;
            gap: 15px;
        }
        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }
        .stat-icon.purple {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .stat-icon.green {
            background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
            color: white;
        }
        .stat-content h3 {
            font-size: 28px;
            color: #333;
            margin-bottom: 5px;
        }
        .stat-content p {
            font-size: 13px;
            color: #777;
        }
        
        /* Badge */
        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }
        .badge-success {
            background: #d4edda;
            color: #155724;
        }
        .badge-warning {
            background: #fff3cd;
            color: #856404;
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
                padding: 0 15px;
            }
        }
    </style>
</head>
<body>
   <div class="header">
    <div class="header-content">
        <h1>Uptime Admin Dashboard</h1>
        <div class="status-badge <?= $connectionTest['success'] ? 'status-connected' : 'status-disconnected'; ?>">
            <span>●</span>
            <?= $connectionTest['success'] ? 'Connected' : 'Disconnected'; ?>
            <?php if ($connectionTest['success']): ?>
                <span style="opacity: 0.8; margin-left: 5px;">(<?= $connectionTest['time']; ?>)</span>
            <?php endif; ?>
        </div>
        <a href="logout.php" class="logout-button">Logout</a>
    </div>
</div>

    <div class="container">
        <?= $changePasswordMessage ?>

        <!-- 🔢 Stats -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon purple">👥</div>
                <div class="stat-content">
                    <h3><?= $totalUsers ?></h3>
                    <p>Total Users</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon green">🟢</div>
                <div class="stat-content">
                    <h3><?= $activeUsers ?></h3>
                    <p>Active Sessions</p>
                </div>
            </div>
        </div>

       <!-- Change Admin Password -->
<div class="card">
  <div class="card-header"><h2>Change Admin Password</h2></div>
  <div class="card-body">
    <form method="POST">
      <input type="hidden" name="action" value="change_password">
      <div class="form-group">
        <label for="current_password">Current Password</label>
        <input type="password" name="current_password" required>
      </div>
      <div class="form-group">
        <label for="new_password">New Password</label>
        <input type="password" name="new_password" required>
      </div>
      <div class="form-group">
        <label for="confirm_password">Confirm New Password</label>
        <input type="password" name="confirm_password" required>
      </div>
      <button type="submit" class="btn"><span class="btn-icon">🔄</span> Update Password</button>
    </form>
  </div>
</div>

<?php if ($_SESSION['role'] === 'super'): ?>
  <!-- Generate Admin Registration Token -->
  <div class="card" style="margin-top: 30px;">
    <div class="card-header"><h2>Generate Admin Registration Token</h2></div>
    <div class="card-body">
      <form method="POST">
        <input type="hidden" name="action" value="generate_token">
        <div class="form-group">
          <label for="recipient_email">Recipient Email</label>
          <input type="email" name="recipient_email" required>
        </div>
        <button type="submit" class="btn"><span class="btn-icon">📧</span> Send Token</button>
      </form>
    </div>
  </div>
<?php endif; ?>

<!-- Feedback Message -->
<?php if (!empty($changePasswordMessage)): ?>
  <div style="margin-top: 20px;">
    <?= $changePasswordMessage ?>
  </div>
<?php endif; ?>

<?php
$regularAdmins = [];
if ($_SESSION['role'] === 'super') {
    $stmt = $pdo->query("SELECT id, username, email, created_at FROM admins WHERE role = 'regular'");
    $regularAdmins = $stmt->fetchAll();
}
?>

<?php if ($_SESSION['role'] === 'super'): ?>
<div class="card" style="margin-top: 30px;">
  <div class="card-header">
    <h2>👥 Manage Regular Admins</h2>
    <span class="badge badge-warning"><?= count($regularAdmins) ?> Total</span>
  </div>
  <div class="card-body" style="padding: 0;">
    <?php if (!empty($regularAdmins)): ?>
      <div class="table-container">
        <table>
          <thead>
            <tr>
              <th>Username</th>
              <th>Email</th>
              <th>Created</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($regularAdmins as $admin): ?>
              <tr>
                <td><?= htmlspecialchars($admin['username']) ?></td>
                <td><?= htmlspecialchars($admin['email'] ?? '-') ?></td>
                <td><?= htmlspecialchars($admin['created_at']) ?></td>
                <td>
                  <form method="POST" onsubmit="return confirm('Delete this admin?');">
                    <input type="hidden" name="action" value="delete_admin">
                    <input type="hidden" name="admin_id" value="<?= $admin['id'] ?>">
                    <button type="submit" class="btn" style="background:#e53e3e;">🗑️ Delete</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php else: ?>
      <div class="empty-state">
        <p>No regular admins found.</p>
      </div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

        <!-- Create User -->
        <div class="dashboard-grid">
            <div class="card">
                <div class="card-header"><h2>Create New User</h2></div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="create_user">
                        <div class="form-group">
                            <label>Select Plan</label>
                            <select name="plan" required
    style="
        background: #2d3748;
        color: #e2e8f0;
        border: 1px solid #4a5568;
        border-radius: 6px;
        padding: 10px 12px;
        font-size: 0.95rem;
        width: 100%;
        outline: none;
        transition: border-color 0.25s ease, box-shadow 0.25s ease;
        appearance: none;
        cursor: pointer;
    "
    onfocus="this.style.borderColor='#63b3ed'; this.style.boxShadow='0 0 0 2px rgba(99,179,237,0.25)'"
    onblur="this.style.borderColor='#4a5568'; this.style.boxShadow='none'">
    <option value="">-- Choose a plan --</option>
    <option value="30min">⏱️ 30 Minutes</option>
    <option value="2h">⏱️ 2 Hours</option>
    <option value="12h">⏱️ 12 Hours</option>
    <option value="24h">⏱️ 24 Hours (1 Day)</option>
    <option value="48h">⏱️ 48 Hours (2 Days)</option>
    <option value="1w">⏱️ 1 Week (7 Days)</option>
</select>

                        </div>
                        <button type="submit" class="btn">
                            <span class="btn-icon">🎫</span>
                            Generate User
                        </button>
                    </form>
                </div>
            </div>

            <!-- ⚡ Quick Actions Card -->
            <div class="card">
                <div class="card-header">
                    <h2>Quick Actions</h2>
                </div>
                <div class="card-body">
                    <p style="color: #666; font-size: 14px; margin-bottom: 20px;">
                        Router: <strong><?= $connectionTest['success'] ? $connectionTest['router'] : 'Not connected'; ?></strong>
                    </p>
                    <form method="POST">
                        <input type="hidden" name="action" value="refresh">
                        <button type="submit" class="btn" style="width: 100%;">
                            <span class="btn-icon">🔄</span>
                            Refresh Dashboard
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- 🟢 Active Sessions Table -->
        <div class="card" style="margin-bottom: 25px;">
            <div class="card-header">
                <h2><span>🟢</span> Active Hotspot Sessions</h2>
                <span class="badge badge-success"><?= $activeUsers ?> Active</span>
            </div>
            <div class="card-body" style="padding: 0;">
                <?php if (!empty($activeSessions)): ?>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Username</th>
                                    <th>IP Address</th>
                                    <th>MAC Address</th>
                                    <th>Uptime</th>
                                    <th>Session Time</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($activeSessions as $session): ?>
                                    <?php if (isset($session['user'])): ?>
                                        <tr>
                                            <td><strong><?= htmlspecialchars($session['user']) ?></strong></td>
                                            <td><?= htmlspecialchars($session['address'] ?? 'N/A') ?></td>
                                            <td><code style="font-size: 12px;"><?= htmlspecialchars($session['mac-address'] ?? 'N/A') ?></code></td>
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
                        <svg fill="currentColor" viewBox="0 0 20 20">
                            <path d="M10 2a8 8 0 100 16 8 8 0 000-16zM8 9a1 1 0 112 0v4a1 1 0 11-2 0V9zm1-4a1 1 0 110 2 1 1 0 010-2z"/>
                        </svg>
                        <p>No active sessions at the moment</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- 📋 All Users Table -->
        <div class="card">
            <div class="card-header">
                <h2>All Hotspot Users</h2>
                <span class="badge badge-warning"><?= $totalUsers ?> Total</span>
            </div>
            <div class="card-body" style="padding: 0;">
                <?php if (!empty($allUsers)): ?>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Username</th>
                                    <th>Profile</th>
                                    <th>Uptime Limit</th>
                                    <th>Server</th>
                                    <th>Comment</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($allUsers as $user): ?>
                                    <?php if (isset($user['name'])): ?>
                                        <tr>
                                            <td><strong><?= htmlspecialchars($user['name']) ?></strong></td>
                                            <td><span class="badge badge-success"><?= htmlspecialchars($user['profile'] ?? 'default') ?></span></td>
                                            <td><?= htmlspecialchars($user['limit-uptime'] ?? 'Unlimited') ?></td>
                                            <td><?= htmlspecialchars($user['server'] ?? 'N/A') ?></td>
                                            <td><?= htmlspecialchars($user['comment'] ?? '-') ?></td>
                                        </tr>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <svg fill="currentColor" viewBox="0 0 20 20">
                            <path d="M9 6a3 3 0 11-6 0 3 3 0 016 0zM17 6a3 3 0 11-6 0 3 3 0 016 0zM12.93 17c.046-.327.07-.66.07-1a6.97 6.97 0 00-1.5-4.33A5 5 0 0119 16v1h-6.07zM6 11a5 5 0 015 5v1H1v-1a5 5 0 015-5z"/>
                        </svg>
                        <p>No users found in the system</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- 📋 Copy Scripts -->
    <script>
        function copyToClipboard(id, btn) {
            const text = document.getElementById(id).textContent;
            navigator.clipboard.writeText(text).then(() => {
                const original = btn.innerHTML;
                btn.innerHTML = 'Copied!';
                btn.classList.add('copied');
                setTimeout(() => {
                    btn.innerHTML = original;
                    btn.classList.remove('copied');
                }, 2000);
            });
        }

        function copyBoth(username, password, btn) {
            const text = `Username: ${username}\nPassword: ${password}`;
            navigator.clipboard.writeText(text).then(() => {
                const original = btn.innerHTML;
                btn.innerHTML = 'Copied Both!';
                btn.classList.add('copied');
                setTimeout(() => {
                    btn.innerHTML = original;
                    btn.classList.remove('copied');
                }, 2000);
            });
        }
    </script>
</body>
</html>