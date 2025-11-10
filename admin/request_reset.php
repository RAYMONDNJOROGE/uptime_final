<?php
/**
 * Password Reset - OTP Request Page
 * Sends OTP to user's email with rate limiting
 */

require_once __DIR__ . '/../config.php';
require __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

session_start();
$pdo = getDBConnection();

// Configuration
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$maxAttemptsPerDay = 5;
$cooldownSeconds = 60;
$otpExpiryMinutes = 10;

$now = time();
$nowSql = date('Y-m-d H:i:s', $now);
$message = '';
$messageType = '';

$email = trim($_POST['email'] ?? '');
$resend = isset($_POST['resend']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($email)) {
        $message = "Please enter your email address.";
        $messageType = 'error';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = "Please enter a valid email address.";
        $messageType = 'error';
    } else {
        // Check if email exists and account is active
        $stmt = $pdo->prepare("SELECT id, username FROM admins WHERE email = ? AND is_active = 1 LIMIT 1");
        $stmt->execute([$email]);
        $admin = $stmt->fetch();

        if (!$admin) {
            // Don't reveal if email exists (security best practice)
            $message = "If an account exists with this email, an OTP has been sent.";
            $messageType = 'info';
            error_log("Password reset attempted for non-existent email: $email");
        } else {
            // Check OTP attempt rate limiting
            $stmt = $pdo->prepare("SELECT attempts, last_attempt, first_attempt FROM otp_attempts WHERE email = ? AND ip_address = ?");
            $stmt->execute([$email, $ip]);
            $record = $stmt->fetch();

            $canProceed = true;
            
            if ($record) {
                $last = strtotime($record['last_attempt']);
                $first = strtotime($record['first_attempt']);
                $dayAgo = strtotime('-1 day');

                // Reset counter if more than 24 hours passed
                if ($first < $dayAgo) {
                    $pdo->prepare("DELETE FROM otp_attempts WHERE email = ? AND ip_address = ?")
                        ->execute([$email, $ip]);
                    $record = null;
                } 
                // Check daily limit
                elseif ($record['attempts'] >= $maxAttemptsPerDay) {
                    $hoursLeft = ceil((($first + 86400) - $now) / 3600);
                    $message = "Too many OTP requests for this email from your IP. Please try again in {$hoursLeft} hour(s).";
                    $messageType = 'error';
                    $canProceed = false;
                    error_log("OTP rate limit exceeded for email: $email from IP: $ip");
                } 
                // Check cooldown between requests
                elseif (($now - $last) < $cooldownSeconds) {
                    $wait = $cooldownSeconds - ($now - $last);
                    $message = "Please wait {$wait} second(s) before requesting another OTP.";
                    $messageType = 'warning';
                    $canProceed = false;
                }
            }

            if ($canProceed) {
                // Generate 6-digit OTP
                $otp = str_pad(random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
                $expires = date('Y-m-d H:i:s', strtotime("+{$otpExpiryMinutes} minutes"));

                // Store OTP in database
                $pdo->prepare("UPDATE admins SET reset_otp = ?, otp_expires_at = ? WHERE email = ?")
                    ->execute([$otp, $expires, $email]);

                // Update or create attempt record
                if ($record) {
                    $pdo->prepare("UPDATE otp_attempts SET attempts = attempts + 1, last_attempt = ? WHERE email = ? AND ip_address = ?")
                        ->execute([$nowSql, $email, $ip]);
                } else {
                    $pdo->prepare("INSERT INTO otp_attempts (email, ip_address, attempts, last_attempt, first_attempt) VALUES (?, ?, 1, ?, ?)")
                        ->execute([$email, $ip, $nowSql, $nowSql]);
                }

                // Send OTP via email
                $mail = new PHPMailer(true);
                try {
                    $mail->isSMTP();
                    $mail->Host = 'smtp.gmail.com';
                    $mail->SMTPAuth = true;
                    $mail->Username = 'uptimetechmasters@gmail.com';
                    $mail->Password = 'aszhqrssbadrfyml';
                    $mail->SMTPSecure = 'tls';
                    $mail->Port = 587;

                    $mail->setFrom('uptimetechmasters@gmail.com', 'Uptime Hotspot');
                    $mail->addAddress($email);
                    $mail->Subject = 'Password Reset OTP - Uptime Hotspot';
                    
                    // HTML email body
                    $mail->isHTML(true);
                    $mail->Body = "
                        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
                            <div style='background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 30px; text-align: center;'>
                                <h1 style='color: white; margin: 0;'>Uptime Hotspot</h1>
                            </div>
                            <div style='padding: 30px; background: #f8f9fa;'>
                                <h2 style='color: #333;'>Password Reset Request</h2>
                                <p style='color: #666; line-height: 1.6;'>
                                    Hello <strong>{$admin['username']}</strong>,<br><br>
                                    You requested to reset your password. Use the following OTP code:
                                </p>
                                <div style='background: white; padding: 20px; text-align: center; border-radius: 8px; margin: 20px 0;'>
                                    <h1 style='color: #667eea; margin: 0; font-size: 36px; letter-spacing: 8px;'>{$otp}</h1>
                                </div>
                                <p style='color: #666; line-height: 1.6;'>
                                    This code will expire in <strong>{$otpExpiryMinutes} minutes</strong>.<br><br>
                                    If you didn't request this, please ignore this email.
                                </p>
                            </div>
                            <div style='padding: 20px; text-align: center; background: #333; color: #999; font-size: 12px;'>
                                <p>© " . date('Y') . " Uptime Tech Masters. All rights reserved.</p>
                            </div>
                        </div>
                    ";
                    
                    $mail->AltBody = "Your OTP for password reset is: {$otp}\n\nThis code expires in {$otpExpiryMinutes} minutes.\n\nIf you didn't request this, please ignore this email.";

                    $mail->send();
                    
                    $_SESSION['otp_email'] = $email;
                    $_SESSION['otp_sent_at'] = time();
                    
                    $message = $resend 
                        ? "OTP has been resent to your email. Please check your inbox." 
                        : "OTP has been sent to your email. Please check your inbox.";
                    $messageType = 'success';
                    
                    error_log("OTP sent successfully to email: $email");
                    
                } catch (Exception $e) {
                    $message = "Failed to send email. Please try again later.";
                    $messageType = 'error';
                    error_log("Email sending failed for $email: " . $mail->ErrorInfo);
                }
            }
        }
    }
}

// Get remaining attempts
$remainingAttempts = $maxAttemptsPerDay;
if (isset($record) && $record) {
    $remainingAttempts = max(0, $maxAttemptsPerDay - $record['attempts']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Password Reset | Uptime Hotspot</title>
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
            --info: #3182ce;
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

.container {
  background: var(--dark);
  border: 1px solid var(--gray);
  border-radius: 20px;
  box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
  padding: 40px;
  width: 100%;
  max-width: 500px;
  max-height: 95vh;
  overflow-y: auto;
  color: var(--text);
  scrollbar-width: thin;
  scrollbar-color: var(--gray) transparent;
}

        .header {
            text-align: center;
            margin-bottom: 30px;
        }

        .icon-wrapper {
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

        .title {
            font-size: 24px;
            font-weight: 700;
            color: var(--text);
            margin-bottom: 8px;
        }

        .subtitle {
            font-size: 14px;
            color: var(--text-dim);
            line-height: 1.6;
        }

        .alert {
            padding: 14px 16px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            align-items: flex-start;
            gap: 12px;
            font-size: 14px;
            line-height: 1.5;
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

        .alert i {
            font-size: 18px;
            margin-top: 2px;
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

        .alert-warning {
            background: rgba(221, 107, 32, 0.15);
            color: #f6ad55;
            border: 1px solid rgba(221, 107, 32, 0.3);
        }

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

        .btn {
            width: 100%;
            padding: 16px;
            border: none;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
            margin-bottom: 12px;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.4);
        }

        .btn-secondary {
            background: var(--dark-light);
            color: var(--text);
            border: 2px solid var(--gray);
        }

        .btn-secondary:hover {
            border-color: var(--primary);
            background: rgba(102, 126, 234, 0.1);
        }

        .btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none !important;
        }

        .info-box {
            background: var(--dark-light);
            border: 1px solid var(--gray);
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 20px;
        }

        .info-item {
            display: flex;
            align-items: center;
            gap: 10px;
            color: var(--text-dim);
            font-size: 13px;
            margin-bottom: 8px;
        }

        .info-item:last-child {
            margin-bottom: 0;
        }

        .info-item i {
            color: var(--primary);
            width: 16px;
        }

        .divider {
            height: 1px;
            background: var(--gray);
            margin: 25px 0;
        }

        .link-section {
            text-align: center;
        }

        .link {
            color: var(--primary);
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
            transition: color 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .link:hover {
            color: var(--secondary);
            text-decoration: underline;
        }

        @media (max-width: 480px) {
            .container {
                padding: 30px 25px;
            }

            .icon-wrapper {
                width: 70px;
                height: 70px;
                font-size: 32px;
            }

            .title {
                font-size: 22px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="icon-wrapper">
                <i class="fas fa-key"></i>
            </div>
            <h1 class="title">Reset Password</h1>
            <p class="subtitle">Enter your email address and we'll send you an OTP code to reset your password.</p>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-<?= $messageType ?>">
                <i class="fas fa-<?= $messageType === 'error' ? 'exclamation-circle' : ($messageType === 'success' ? 'check-circle' : ($messageType === 'warning' ? 'exclamation-triangle' : 'info-circle')) ?>"></i>
                <span><?= htmlspecialchars($message) ?></span>
            </div>
        <?php endif; ?>

        <form method="POST">
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
                        value="<?= htmlspecialchars($_SESSION['otp_email'] ?? '') ?>"
                        placeholder="Enter your registered email"
                        required
                        autofocus
                    >
                </div>
            </div>

            <button type="submit" class="btn btn-primary">
                <i class="fas fa-paper-plane"></i>
                <span><?= isset($_SESSION['otp_email']) ? 'Send New OTP' : 'Send OTP' ?></span>
            </button>

            <?php if (isset($_SESSION['otp_email']) && isset($_SESSION['otp_sent_at'])): ?>
                <?php 
                $secondsSinceSent = time() - $_SESSION['otp_sent_at'];
                $canResend = $secondsSinceSent >= $cooldownSeconds;
                ?>
                <button 
                    style="margin-bottom: 10px;"
                    type="submit" 
                    name="resend" 
                    class="btn btn-secondary"
                    <?= !$canResend ? 'disabled' : '' ?>
                >
                    <i class="fas fa-redo"></i>
                    <span>
                        <?= $canResend 
                            ? 'Resend OTP' 
                            : 'Resend in ' . ($cooldownSeconds - $secondsSinceSent) . 's'
                        ?>
                    </span>
                </button>
            <?php endif; ?>
        </form>

        <div class="info-box">
            <div class="info-item">
                <i class="fas fa-clock"></i>
                <span>OTP expires in <?= $otpExpiryMinutes ?> minutes</span>
            </div>
            <div class="info-item">
                <i class="fas fa-shield-alt"></i>
                <span>Maximum <?= $maxAttemptsPerDay ?> requests per 24 hours</span>
            </div>
            <div class="info-item">
                <i class="fas fa-hourglass-half"></i>
                <span><?= $cooldownSeconds ?> second cooldown between requests</span>
            </div>
        </div>

        <div class="divider"></div>

        <div class="link-section">
            <a href="reset_with_otp.php" class="link">
                <i class="fas fa-sign-in-alt"></i>
                <span>Already have an OTP? Enter it here</span>
            </a>
        </div>

        <div class="divider"></div>

        <div class="link-section">
            <a href="/..index.php" class="link">
                <i class="fas fa-arrow-left"></i>
                <span>Back to Login</span>
            </a>
        </div>
    </div>

    <?php if (isset($_SESSION['otp_sent_at']) && !$canResend): ?>
    <script>
        // Countdown timer for resend button
        let countdown = <?= $cooldownSeconds - $secondsSinceSent ?>;
        const resendBtn = document.querySelector('button[name="resend"]');
        const resendSpan = resendBtn.querySelector('span');
        
        const timer = setInterval(() => {
            countdown--;
            if (countdown <= 0) {
                clearInterval(timer);
                resendBtn.disabled = false;
                resendSpan.textContent = 'Resend OTP';
            } else {
                resendSpan.textContent = `Resend in ${countdown}s`;
            }
        }, 1000);
    </script>
    <?php endif; ?>
</body>
</html>