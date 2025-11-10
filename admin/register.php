<?php
/**
 * Admin Registration Page
 * Token-based registration for new admin accounts
 */

ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_secure', 0); // Set to 1 if using HTTPS
ini_set('session.cookie_samesite', 'Strict');

session_start();
require_once __DIR__ . '/../config.php';
$pdo = getDBConnection();

// Configuration
$maxAttempts = 5;
$lockoutMinutes = 15;
$ip = $_SERVER['REMOTE_ADDR'];
$message = '';
$messageType = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    $token = trim($_POST['auth_token'] ?? '');

    // Basic validation
    if (empty($username) || empty($email) || empty($password) || empty($confirm) || empty($token)) {
        $message = "All fields are required.";
        $messageType = 'error';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = "Please enter a valid email address.";
        $messageType = 'error';
    } elseif (strlen($username) < 3 || strlen($username) > 32) {
        $message = "Username must be between 3 and 32 characters.";
        $messageType = 'error';
    } elseif (strlen($password) < 6) {
        $message = "Password must be at least 6 characters long.";
        $messageType = 'error';
    } elseif ($password !== $confirm) {
        $message = "Passwords do not match.";
        $messageType = 'error';
    } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
        $message = "Username can only contain letters, numbers, and underscores.";
        $messageType = 'error';
    } else {
        try {
            // Check rate limiting
            $stmt = $pdo->prepare("SELECT attempts, last_attempt FROM login_attempts WHERE username = ? OR ip_address = ?");
            $stmt->execute([$username, $ip]);
            $attempt = $stmt->fetch();
            $remainingAttempts = $maxAttempts;

            if ($attempt) {
                $remainingAttempts = max(0, $maxAttempts - $attempt['attempts']);
                $lastAttemptTime = strtotime($attempt['last_attempt']);
                $lockoutExpiry = strtotime("+{$lockoutMinutes} minutes", $lastAttemptTime);
                
                $lockedOut = $attempt['attempts'] >= $maxAttempts && time() < $lockoutExpiry;
                
                if ($lockedOut) {
                    $minutesLeft = ceil(($lockoutExpiry - time()) / 60);
                    $message = "Too many registration attempts. Please try again in {$minutesLeft} minute(s).";
                    $messageType = 'error';
                    goto display_page;
                }
            }

            // Normalize for comparison
            $normalizedToken = strtolower(trim($token));
            $normalizedEmail = strtolower(trim($email));

            // Validate token
            $stmt = $pdo->prepare("
                SELECT id, recipient_email, expires_at 
                FROM registration_tokens 
                WHERE LOWER(token) = ? 
                AND LOWER(recipient_email) = ? 
                AND used = 0 
                LIMIT 1
            ");
            $stmt->execute([$normalizedToken, $normalizedEmail]);
            $validToken = $stmt->fetch();

            if (!$validToken) {
                $message = "Invalid authorization token or email mismatch.";
                $messageType = 'error';
                
                // Track failed attempt
                if ($attempt) {
                    $pdo->prepare("UPDATE login_attempts SET attempts = attempts + 1, last_attempt = NOW() WHERE username = ? OR ip_address = ?")
                        ->execute([$username, $ip]);
                } else {
                    $pdo->prepare("INSERT INTO login_attempts (username, ip_address, attempts, last_attempt) VALUES (?, ?, 1, NOW())")
                        ->execute([$username, $ip]);
                }
                
                $remainingAttempts--;
                if ($remainingAttempts > 0) {
                    $message .= " You have {$remainingAttempts} attempt(s) remaining.";
                }
            } elseif (strtotime($validToken['expires_at']) < time()) {
                $message = "This authorization token has expired. Please request a new one.";
                $messageType = 'error';
            } else {
                // Check if username or email already exists
                $stmt = $pdo->prepare("SELECT id FROM admins WHERE LOWER(username) = ? OR LOWER(email) = ? LIMIT 1");
                $stmt->execute([strtolower($username), $normalizedEmail]);
                
                if ($stmt->fetch()) {
                    $message = "Username or email is already registered.";
                    $messageType = 'error';
                } else {
                    // Create new admin account
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    
                    $stmt = $pdo->prepare("
                        INSERT INTO admins (username, email, password, is_active, role, created_at) 
                        VALUES (?, ?, ?, 1, 'regular', NOW())
                    ");
                    $stmt->execute([$username, $email, $hash]);
                    $newAdminId = $pdo->lastInsertId();

                    // Mark token as used
                    $pdo->prepare("UPDATE registration_tokens SET used = 1, used_at = NOW() WHERE id = ?")
                        ->execute([$validToken['id']]);

                    // Clear any failed attempts
                    $pdo->prepare("DELETE FROM login_attempts WHERE username = ? OR ip_address = ?")
                        ->execute([$username, $ip]);

                    // Log the registration
                    error_log("New admin registered: $username (ID: $newAdminId, Email: $email)");

                    // Auto-login the new user
                    session_regenerate_id(true);
                    $_SESSION['authenticated'] = true;
                    $_SESSION['username'] = $username;
                    $_SESSION['admin_id'] = $newAdminId;
                    $_SESSION['role'] = 'regular';
                    $_SESSION['email'] = $email;
                    $_SESSION['last_activity'] = time();
                    $_SESSION['login_time'] = time();

                    // Set success message and redirect
                    $_SESSION['login_success'] = "Account created successfully! Welcome, $username.";
                    header('Location: dashboard_lite.php');
                    exit;
                }
            }

        } catch (PDOException $e) {
            error_log("Registration error: " . $e->getMessage());
            $message = "An error occurred during registration. Please try again.";
            $messageType = 'error';
        }
    }
}

display_page:
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Account | Uptime Hotspot Admin</title>
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

/* Body: fixed layout with stable scroll behavior */
html, body {
  height: 100%;
  overflow: hidden; /* prevent page scroll */
}

body {
  font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
  background: linear-gradient(135deg, #1a202c 0%, #2d3748 50%, #1a202c 100%);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 2rem;
  box-sizing: border-box;
  overflow: hidden;
}

/* Registration Container */
.register-container {
  background: var(--dark);
  border: 1px solid var(--gray);
  border-radius: 40px;
  box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
  padding: 20px;
  width: 100%;
  max-width: 500px;
  max-height: 95vh;
  overflow-y: auto;
  color: var(--text);
  scrollbar-width: thin;
  scrollbar-color: var(--gray) transparent;
}



   

        /* Logo/Title */
        .logo-section {
            text-align: center;
            margin-bottom: 30px;
        }

        .logo {
            width: 70px;
            height: 70px;
            margin: 0 auto 15px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border-radius: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            color: white;
            box-shadow: 0 8px 24px rgba(102, 126, 234, 0.4);
        }

        .register-title {
            font-size: 26px;
            font-weight: 700;
            color: var(--text);
            margin-bottom: 6px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .register-subtitle {
            font-size: 14px;
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
            margin-bottom: 18px;
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
            font-size: 15px;
        }

        .form-input {
            width: 100%;
            padding: 13px 16px 13px 45px;
            background: var(--dark-light);
            border: 2px solid var(--gray);
            border-radius: 12px;
            color: var(--text);
            font-size: 14px;
            transition: all 0.3s ease;
        }

        .form-input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .password-strength {
            margin-top: 6px;
            font-size: 12px;
            color: var(--text-dim);
        }

        .strength-bar {
            height: 4px;
            background: var(--gray);
            border-radius: 2px;
            margin-top: 4px;
            overflow: hidden;
        }

        .strength-fill {
            height: 100%;
            width: 0%;
            transition: all 0.3s ease;
            border-radius: 2px;
        }

        .strength-weak { width: 33%; background: var(--danger); }
        .strength-medium { width: 66%; background: var(--warning); }
        .strength-strong { width: 100%; background: var(--success); }

        /* Submit Button */
        .submit-btn {
            width: 100%;
            padding: 15px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin-top: 25px;
        }

        .submit-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.4);
        }

        .submit-btn:active {
            transform: translateY(0);
        }

        /* Info Box */
        .info-box {
            background: rgba(49, 130, 206, 0.1);
            border: 1px solid rgba(49, 130, 206, 0.2);
            border-radius: 12px;
            padding: 12px 16px;
            margin-bottom: 20px;
            font-size: 13px;
            color: var(--text-dim);
            display: flex;
            gap: 12px;
        }

        .info-box i {
            color: #63b3ed;
            margin-top: 2px;
        }

        /* Links */
        .back-link {
            text-align: center;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid var(--gray);
        }

        .back-link a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: color 0.2s;
        }

        .back-link a:hover {
            color: var(--secondary);
        }

        /* Responsive */
        @media (max-width: 480px) {
            .register-container {
                padding: 30px 25px;
            }

            .logo {
                width: 60px;
                height: 60px;
                font-size: 28px;
            }

            .register-title {
                font-size: 22px;
            }
        }
    </style>
</head>
<body>
    <div class="register-container">
        <div class="logo-section">
            <div class="logo">
                <i class="fas fa-user-plus"></i>
            </div>
            <h1 class="register-title">Create Account</h1>
            <p class="register-subtitle">Register as a new admin</p>
        </div>

        <div class="info-box">
            <i class="fas fa-info-circle"></i>
            <div>
                <strong>Authorization Token Required</strong><br>
                You need a valid token sent to your email by a super admin to create an account.
            </div>
        </div>

        <!-- Messages -->
        <?php if (!empty($message)): ?>
            <div class="alert alert-<?= $messageType ?>">
                <i class="fas fa-<?= $messageType === 'error' ? 'exclamation-circle' : 'check-circle' ?>"></i>
                <span><?= htmlspecialchars($message) ?></span>
            </div>
        <?php endif; ?>

        <!-- Registration Form -->
        <form method="POST" id="registerForm">
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
                        placeholder="Choose a username"
                        pattern="[a-zA-Z0-9_]{3,32}"
                        title="3-32 characters, letters, numbers and underscore only"
                        required
                        autofocus
                    >
                </div>
            </div>

            <div class="form-group">
                <label for="email">
                    <i class="fas fa-envelope"></i> Email Address
                </label>
                <div class="input-wrapper">
                    <i class="fas fa-envelope input-icon"></i>
                    <input 
                        type="email" 
                        id="email" 
                        name="email" 
                        class="form-input"
                        placeholder="your.email@example.com"
                        required
                    >
                </div>
                <p style="font-size: 12px; color: var(--text-dim); margin-top: 4px;">
                    Must match the email your token was sent to
                </p>
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
                        placeholder="Create a strong password"
                        minlength="6"
                        required
                        oninput="checkPasswordStrength()"
                    >
                </div>
                <div class="password-strength">
                    <div class="strength-bar">
                        <div class="strength-fill" id="strengthBar"></div>
                    </div>
                    <span id="strengthText" style="margin-top: 4px; display: block;"></span>
                </div>
            </div>

            <div class="form-group">
                <label for="confirm_password">
                    <i class="fas fa-lock"></i> Confirm Password
                </label>
                <div class="input-wrapper">
                    <i class="fas fa-lock input-icon"></i>
                    <input 
                        type="password" 
                        id="confirm_password" 
                        name="confirm_password" 
                        class="form-input"
                        placeholder="Re-enter your password"
                        required
                        oninput="checkPasswordMatch()"
                    >
                </div>
                <span id="matchText" style="font-size: 12px; margin-top: 4px; display: block;"></span>
            </div>

            <div class="form-group">
                <label for="auth_token">
                    <i class="fas fa-key"></i> Authorization Token
                </label>
                <div class="input-wrapper">
                    <i class="fas fa-key input-icon"></i>
                    <input 
                        type="text" 
                        id="auth_token" 
                        name="auth_token" 
                        class="form-input"
                        placeholder="Enter your token"
                        required
                    >
                </div>
                <p style="font-size: 12px; color: var(--text-dim); margin-top: 4px;">
                    Check your email for the token sent by the admin
                </p>
            </div>

            <button type="submit" class="submit-btn">
                <i class="fas fa-user-plus"></i>
                <span>Create Account</span>
            </button>
        </form>

        <div class="back-link">
            <a href="/..index.php">
                <i class="fas fa-arrow-left"></i>
                Back to Login
            </a>
        </div>
    </div>

    <script>
        function checkPasswordStrength() {
            const password = document.getElementById('password').value;
            const strengthBar = document.getElementById('strengthBar');
            const strengthText = document.getElementById('strengthText');
            
            let strength = 0;
            
            if (password.length >= 6) strength++;
            if (password.length >= 10) strength++;
            if (/[a-z]/.test(password) && /[A-Z]/.test(password)) strength++;
            if (/\d/.test(password)) strength++;
            if (/[^a-zA-Z0-9]/.test(password)) strength++;
            
            strengthBar.className = 'strength-fill';
            
            if (strength <= 2) {
                strengthBar.classList.add('strength-weak');
                strengthText.textContent = 'Weak password';
                strengthText.style.color = 'var(--danger)';
            } else if (strength <= 4) {
                strengthBar.classList.add('strength-medium');
                strengthText.textContent = 'Medium strength';
                strengthText.style.color = 'var(--warning)';
            } else {
                strengthBar.classList.add('strength-strong');
                strengthText.textContent = 'Strong password';
                strengthText.style.color = 'var(--success)';
            }
        }
        
        function checkPasswordMatch() {
            const password = document.getElementById('password').value;
            const confirm = document.getElementById('confirm_password').value;
            const matchText = document.getElementById('matchText');
            
            if (confirm.length === 0) {
                matchText.textContent = '';
                return;
            }
            
            if (password === confirm) {
                matchText.textContent = '✓ Passwords match';
                matchText.style.color = 'var(--success)';
            } else {
                matchText.textContent = '✗ Passwords do not match';
                matchText.style.color = 'var(--danger)';
            }
        }

        // Auto-dismiss alerts
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