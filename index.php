<?php
/**
 * Admin Login Page
 * Secure authentication with rate limiting and session management
 */

ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_secure', 0); // Set to 1 if using HTTPS
ini_set('session.cookie_samesite', 'Strict');

session_start();
require_once __DIR__ . '/config.php';

// Redirect if already logged in
if (isset($_SESSION['authenticated']) && $_SESSION['authenticated'] === true) {
    header('Location: ' . ($_SESSION['role'] === 'super' ? 'admin/admin.php' : 'admin/dashboard_lite.php'));
    exit;
}

$pdo = getDBConnection();

// Configuration
$maxAttempts = 5;
$lockoutMinutes = 15;
$rememberedUsername = $_COOKIE['remember_username'] ?? '';

// Get flash messages
$error = $_SESSION['login_error'] ?? '';
$success = $_SESSION['login_success'] ?? '';
$info = $_SESSION['login_info'] ?? '';
unset($_SESSION['login_error'], $_SESSION['login_success'], $_SESSION['login_info']);

// Check for timeout parameter
if (isset($_GET['timeout']) && $_GET['timeout'] == '1') {
    $info = 'Your session has expired. Please login again.';
}

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember = isset($_POST['remember']);

    // Basic validation
    if (empty($username) || empty($password)) {
        $_SESSION['login_error'] = "Please enter both username and password.";
        header("Location: index.php");
        exit;
    }

    // Check for SQL injection attempts
    if (preg_match('/[;\'"\\\\]/', $username)) {
        $_SESSION['login_error'] = "Invalid characters in username.";
        header("Location: index.php");
        exit;
    }

    // Check login attempts by username
    $stmt = $pdo->prepare("SELECT attempts, last_attempt FROM login_attempts WHERE username = ?");
    $stmt->execute([$username]);
    $attempt = $stmt->fetch();
    $remainingAttempts = $maxAttempts;

    if ($attempt) {
        $remainingAttempts = max(0, $maxAttempts - $attempt['attempts']);
        $lastAttemptTime = strtotime($attempt['last_attempt']);
        $lockoutExpiry = strtotime("+{$lockoutMinutes} minutes", $lastAttemptTime);
        
        $lockedOut = $attempt['attempts'] >= $maxAttempts && time() < $lockoutExpiry;
        
        if ($lockedOut) {
            $minutesLeft = ceil(($lockoutExpiry - time()) / 60);
            $_SESSION['login_error'] = "Account temporarily locked due to multiple failed login attempts. Please try again in {$minutesLeft} minute(s).";
            error_log("Login attempt for locked account: $username");
            header("Location: index.php");
            exit;
        }
        
        // Reset attempts if lockout period has passed
        if ($attempt['attempts'] >= $maxAttempts && time() >= $lockoutExpiry) {
            $pdo->prepare("DELETE FROM login_attempts WHERE username = ?")->execute([$username]);
            $remainingAttempts = $maxAttempts;
        }
    }

    // Validate credentials
    $stmt = $pdo->prepare("SELECT id, username, password, role, email FROM admins WHERE username = ? AND is_active = 1 LIMIT 1");
    $stmt->execute([$username]);
    $admin = $stmt->fetch();

    $ip = $_SERVER['REMOTE_ADDR'];

    if (!$admin || !password_verify($password, $admin['password'])) {
    // Insert or update login attempt
        $pdo->prepare("
            INSERT INTO login_attempts (username, ip_address, attempts, last_attempt)
            VALUES (?, ?, 1, NOW())
            ON DUPLICATE KEY UPDATE
                    attempts = attempts + 1,
                    last_attempt = NOW()
            ")->execute([$username, $ip]);

            $remainingAttempts = max(0, $remainingAttempts - 1);

            if ($remainingAttempts > 0) {
                $_SESSION['login_error'] = "Invalid username or password. You have {$remainingAttempts} attempt(s) remaining.";
            } else {
            $_SESSION['login_error'] = "Invalid username or password. Your account has been temporarily locked for {$lockoutMinutes} minutes.";
            }

    error_log("Failed login attempt for username: $username from IP: $ip");
    header("Location: index.php");
    exit;
}
    // Successful login - clear attempts
    $pdo->prepare("DELETE FROM login_attempts WHERE username = ?")->execute([$username]);

    // Regenerate session ID to prevent session fixation
    session_regenerate_id(true);

    // Set session variables
    $_SESSION['authenticated'] = true;
    $_SESSION['username'] = $admin['username'];
    $_SESSION['admin_id'] = $admin['id'];
    $_SESSION['role'] = $admin['role'];
    $_SESSION['email'] = $admin['email'] ?? '';
    $_SESSION['last_activity'] = time();
    $_SESSION['login_time'] = time();
    $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? '';

    // Handle "Remember Me"
    if ($remember) {
        setcookie('remember_username', $username, [
            'expires' => time() + (30 * 24 * 60 * 60),
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Strict'
        ]);
    } else {
        setcookie('remember_username', '', [
            'expires' => time() - 3600,
            'path' => '/'
        ]);
    }

    // Log successful login
    error_log("Successful login for user: $username (Role: {$admin['role']})");

    // Redirect based on role
    header('Location: ' . ($admin['role'] === 'super' ? 'admin.php' : 'dashboard_lite.php'));
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | Uptime Hotspot Admin</title>
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
            --dark: #1a202c;
            --dark-light: #2d3748;
            --gray: #4a5568;
            --gray-light: #cbd5e0;
            --text: #e2e8f0;
            --text-dim: #a0aec0;
            --success: #38a169;
            --danger: #e53e3e;
            --warning: #dd6b20;
        }

            body {
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    background: linear-gradient(135deg, #1a202c 0%, #2d3748 100%);
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: flex-start; /* Changed from center to allow natural flow */
    padding: 20px;
    position: relative;
    overflow-x: hidden; /* Prevent horizontal scroll but allow vertical */
}
        /* Login Container */
        .login-container {
            background: var(--dark);
            border: 1px solid var(--gray);
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
            padding: 40px;
            width: 100%;
            max-width: 450px;
            position: relative;
            z-index: 1;
        }

        /* Logo/Title */
        .logo-section {
            text-align: center;
            margin-bottom: 35px;
        }

        .logo {
            width: 80px;
            height: 80px;
            margin: 0 auto 20px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 36px;
            color: white;
            box-shadow: 0 8px 24px rgba(102, 126, 234, 0.4);
        }

        .login-title {
            font-size: 28px;
            font-weight: 700;
            color: var(--text);
            margin-bottom: 8px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .login-subtitle {
            font-size: 15px;
            color: var(--text-dim);
        }

        /* Alerts */
        .alert {
            padding: 14px 16px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 14px;
            animation: slideIn 0.3s ease-out;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateX(-20px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        .alert-error {
            background: rgba(229, 62, 62, 0.15);
            color: #fc8181;
            border: 1px solid rgba(229, 62, 62, 0.3);
        }

        .alert-success {
            background: rgba(56, 161, 105, 0.15);
            color: #68d391;
            border: 1px solid rgba(56, 161, 105, 0.3);
        }

        .alert-info {
            background: rgba(49, 130, 206, 0.15);
            color: #63b3ed;
            border: 1px solid rgba(49, 130, 206, 0.3);
        }

        /* Form */
        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            font-size: 14px;
            color: var(--gray-light);
        }

        .input-wrapper {
            position: relative;
        }

        .input-icon {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-dim);
            font-size: 16px;
        }

        .form-input {
            width: 100%;
            padding: 14px 16px 14px 45px;
            background: var(--dark-light);
            border: 2px solid var(--gray);
            border-radius: 12px;
            color: var(--text);
            font-size: 15px;
            transition: all 0.3s ease;
        }

        .form-input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .password-toggle {
            position: absolute;
            right: 16px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--primary);
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            padding: 4px 8px;
            border-radius: 6px;
            transition: all 0.2s;
        }

        .password-toggle:hover {
            background: rgba(102, 126, 234, 0.1);
        }

        /* Checkbox */
        .checkbox-group {
            display: flex;
            align-items: center;
            margin-bottom: 25px;
        }

        .checkbox-group input[type="checkbox"] {
            width: 18px;
            height: 18px;
            margin-right: 10px;
            cursor: pointer;
            accent-color: var(--primary);
        }

        .checkbox-group label {
            font-size: 14px;
            color: var(--text-dim);
            cursor: pointer;
            margin: 0;
        }

        /* Submit Button */
        .submit-btn {
            width: 100%;
            padding: 16px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .submit-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.4);
        }

        .submit-btn:active {
            transform: translateY(0);
        }

        /* Links */
        .links-section {
            margin-top: 25px;
            text-align: center;
        }

        .link-item {
            display: block;
            margin-bottom: 10px;
            font-size: 14px;
            color: var(--text-dim);
        }

        .link-item a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 600;
            transition: color 0.2s;
        }

        .link-item a:hover {
            color: var(--secondary);
            text-decoration: underline;
        }

        .divider {
            height: 1px;
            background: var(--gray);
            margin: 20px 0;
        }

       .footer {
    color: #e2e8f0;
    text-align: center;
    padding: 20px;
    border-top: 1px solid #4a5568;
    font-family: 'Inter', sans-serif;
}

.footer-title {
    font-size: 16px;
    font-weight: 600;
    margin-bottom: 10px;
}

.footer-contacts {
    display: flex;
    justify-content: center;
    gap: 20px;
    flex-wrap: wrap;
}

.footer-contact {
    color: #e2e8f0;
    text-decoration: none;
    display: flex;
    align-items: center;
    gap: 5px;
    font-size: 14px;
}

.footer-contact i {
    color: #667eea;
}

.footer-contact:hover {
    color: #667eea;
}

.footer-copy {
    margin-top: 15px;
    font-size: 12px;
    color: #a0aec0;
}

        /* Responsive */
        @media (max-width: 480px) {
            .login-container {
                padding: 30px 25px;
            }

            .logo {
                width: 70px;
                height: 70px;
                font-size: 32px;
            }

            .login-title {
                font-size: 24px;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="logo-section">
            <div class="logo">
                <i class="fas fa-wifi"></i>
            </div>
            <h1 class="login-title">UPTIME HOTSPOT</h1>
            <p class="login-subtitle">Admin Portal</p>
        </div>

        <!-- Error Messages -->
        <?php if (!empty($error)): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <span><?= htmlspecialchars($error) ?></span>
            </div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <span><?= htmlspecialchars($success) ?></span>
            </div>
        <?php endif; ?>

        <?php if (!empty($info)): ?>
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i>
                <span><?= htmlspecialchars($info) ?></span>
            </div>
        <?php endif; ?>

        <!-- Login Form -->
        <form method="POST" autocomplete="on">
            <div class="form-group">
                <label for="username">
                    <i class="fas fa-user"></i> Username
                </label>
                <div class="input-wrapper">
                    <i class="fas fa-user input-icon"></i>
                    <input 
                        type="text" 
                        id="username" 
                        name="username" 
                        class="form-input"
                        value="<?= htmlspecialchars($rememberedUsername) ?>"
                        placeholder="Enter your username"
                        autocomplete="username"
                        required
                        autofocus
                    >
                </div>
            </div>

            <div class="form-group">
                <label for="password">
                    <i class="fas fa-lock"></i> Password
                </label>
                <div class="input-wrapper">
                    <i class="fas fa-lock input-icon"></i>
                    <input 
                        type="password" 
                        id="password" 
                        name="password" 
                        class="form-input"
                        placeholder="Enter your password"
                        autocomplete="current-password"
                        required
                    >
                    <button type="button" class="password-toggle" onclick="togglePassword()">
                        <span id="toggle-text">Show</span>
                    </button>
                </div>
            </div>

            <div class="checkbox-group">
                <input 
                    type="checkbox" 
                    id="remember" 
                    name="remember"
                    <?= $rememberedUsername ? 'checked' : '' ?>
                >
                <label for="remember">Remember my username</label>
            </div>

            <button type="submit" class="submit-btn">
                <i class="fas fa-sign-in-alt"></i>
                <span>Sign In</span>
            </button>
        </form>

        <div class="divider"></div>

        <!-- Links -->
        <div class="links-section">
            <div class="link-item">
                <i class="fas fa-key"></i>
                <a href="admin/request_reset.php">Forgot your password?</a>
            </div>
            <div class="link-item">
                <i class="fas fa-user-plus"></i>
                <a href="admin/register.php">Create new account</a>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <div class="footer">
        <div class="footer-title">
            <i class="fas fa-headset"></i> Need Help?
        </div>
        <div class="footer-contacts">
            <a href="tel:+254791024153" class="footer-contact">
                <i class="fas fa-phone"></i>
                <span>+254 791 024 153</span>
            </a>
            <a href="mailto:uptimetechmasters@gmail.com" class="footer-contact">
                <i class="fas fa-envelope"></i>
                <span>uptimetechmasters@gmail.com</span>
            </a>
        </div>
        <p style="margin-top: 15px; font-size: 12px;">
            <i class="fas fa-copyright"></i> <?= date('Y') ?> Uptime Tech Masters
        </p>
    </div>

    <script>
        function togglePassword() {
            const input = document.getElementById('password');
            const toggleText = document.getElementById('toggle-text');
            
            if (input.type === 'password') {
                input.type = 'text';
                toggleText.textContent = 'Hide';
            } else {
                input.type = 'password';
                toggleText.textContent = 'Show';
            }
        }

        // Auto-dismiss alerts after 8 seconds
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                alert.style.animation = 'fadeOut 0.3s ease-out';
                setTimeout(() => alert.remove(), 300);
            });
        }, 8000);
    </script>
</body>
</html>