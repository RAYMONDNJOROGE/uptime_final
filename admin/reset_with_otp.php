<?php
require_once __DIR__ . '/../config.php';
session_start();
$pdo = getDBConnection();

$message = '';
$isSuccess = false;
$email = '';
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

// Handle feedback from redirected POST
if (isset($_SESSION['otp_reset_feedback'])) {
    $message = $_SESSION['otp_reset_feedback']['message'] ?? '';
    $isSuccess = $_SESSION['otp_reset_feedback']['success'] ?? false;
    $email = $_SESSION['otp_reset_feedback']['email'] ?? '';
    unset($_SESSION['otp_reset_feedback']);
}

// Handle POST submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $otp = trim($_POST['otp'] ?? '');
    $new = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if (!$email || !$otp || !$new || !$confirm) {
        $message = "All fields are required.";
    } elseif ($new !== $confirm) {
        $message = "Passwords do not match.";
    } else {
        $stmt = $pdo->prepare("SELECT id, reset_otp, otp_expires_at FROM admins WHERE email = ? AND is_active = 1 LIMIT 1");
        $stmt->execute([$email]);
        $admin = $stmt->fetch();

        if (!$admin) {
            $message = "No active admin found with that email.";
        } elseif ($admin['reset_otp'] !== $otp) {
            $message = "Incorrect OTP.";
        } elseif ($admin['otp_expires_at'] < date('Y-m-d H:i:s')) {
            $message = "OTP has expired. Please request a new one.";
        } else {
            $hash = password_hash($new, PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE admins SET password = ?, reset_otp = NULL, otp_expires_at = NULL WHERE email = ?")
                ->execute([$hash, $email]);

            $pdo->prepare("INSERT INTO api_logs (endpoint, method, request_data, response_data, status_code, ip_address, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?)")->execute([
                'reset_with_otp.php',
                'POST',
                json_encode(['email' => $email]),
                json_encode(['status' => 'success']),
                200,
                $ip,
                date('Y-m-d H:i:s')
            ]);

            $message = "✅ Password updated. You can now log in.";
            $isSuccess = true;
        }
    }

    // Store feedback and redirect
    $_SESSION['otp_reset_feedback'] = [
        'message' => $message,
        'success' => $isSuccess,
        'email' => $email
    ];
    header("Location: reset_with_otp.php");
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Reset Password | Uptime Hotspot Admin</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
  <style>
    :root {
      --primary: #667eea;
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

    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
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
  padding: 1rem;
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
  max-height: 90vh;
  overflow-y: auto;
  color: var(--text);
  scrollbar-width: thin;
  scrollbar-color: var(--gray) transparent;
}


    h2 {
      text-align: center;
      font-size: 26px;
      font-weight: 700;
      margin-bottom: 10px;
      background: linear-gradient(135deg, var(--primary), var(--secondary));
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
    }

    .divider {
            height: 1px;
            background: var(--gray);
            margin: 25px 0;
        }

    p.subtitle {
      text-align: center;
      font-size: 14px;
      color: var(--text-dim);
      margin-bottom: 30px;
    }

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
      from { opacity: 0; transform: translateX(-20px); }
      to { opacity: 1; transform: translateX(0); }
    }

    .alert-success {
      background: rgba(56, 161, 105, 0.15);
      color: #68d391;
      border: 1px solid rgba(56, 161, 105, 0.3);
    }

    .alert-error {
      background: rgba(229, 62, 62, 0.15);
      color: #fc8181;
      border: 1px solid rgba(229, 62, 62, 0.3);
    }

    .form-group {
      margin-bottom: 20px;
    }

    label {
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

    .back-link {
      text-align: center;
      margin-top: 25px;
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
  </style>
</head>
<body>
  <div class="container">
    <div class="icon-wrapper">
                <i class="fas fa-key"></i>
            </div>
    <h2>Reset Password</h2>
    <p class="subtitle">Enter your email, OTP, and new password</p>

    <?php if (!empty($message)): ?>
      <div class="alert <?= $isSuccess ? 'alert-success' : 'alert-error' ?>">
        <i class="fas fa-<?= $isSuccess ? 'check-circle' : 'exclamation-circle' ?>"></i>
        <span><?= htmlspecialchars($message) ?></span>
      </div>
    <?php endif; ?>

    <form method="POST" onsubmit="return validateStrength();">
      <div class="form-group">
        <label for="email">
                    <i class="fas fa-envelope"></i> Email Address
                </label>
        <div class="input-wrapper">
          <i class="fas fa-envelope input-icon"></i>
          <input type="email" name="email" id="email" class="form-input" placeholder="your.email@example.com" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
        </div>
      </div>

      <div class="form-group">
        <label for="auth_token">
                    <i class="fas fa-key"></i> OTP
                </label>
        <div class="input-wrapper">
          <i class="fas fa-key input-icon"></i>
          <input type="text" name="otp" id="otp" class="form-input" placeholder="One Time Password" required value="<?= htmlspecialchars($_POST['otp'] ?? '') ?>">
        </div>
      </div>

            <div class="form-group">
        <label for="password">
                    <i class="fas fa-lock"></i>  New Password
                </label>
        <div class="input-wrapper">
          <i class="fas fa-lock input-icon"></i>
          <input type="password" name="new_password" id="new_password" placeholder="Create a strong password" class="form-input" required oninput="checkStrength(this.value)">
        </div>
        <div class="password-strength">
          <span id="strengthLabel">Strength: Too Short</span>
          <div class="strength-bar"><div id="strengthFill" class="strength-fill"></div></div>
        </div>
      </div>

      <div class="form-group">
        <label for="password">
                    <i class="fas fa-lock"></i>  Confirm Password
                </label>
        <div class="input-wrapper">
          <i class="fas fa-lock input-icon"></i>
          <input type="password" name="confirm_password" id="confirm_password" placeholder="Re-enter your password" class="form-input" required>
        </div>
      </div>


      <button type="submit" class="submit-btn">
        <i class="fas fa-sync-alt"></i> Reset Password
      </button>
    </form>
    <div class="divider"></div>

    <div class="back-link">
      <a href="../index.php"><i class="fas fa-arrow-left"></i> Back to Login</a>
    </div>

  </div>

  <script>
function checkStrength(password) {
  const label = document.getElementById('strengthLabel');
  const fill = document.getElementById('strengthFill');
  let score = 0;

  if (password.length >= 8) score++;
  if (/[A-Z]/.test(password)) score++;
  if (/[a-z]/.test(password)) score++;
  if (/[0-9]/.test(password)) score++;
  if (/[^A-Za-z0-9]/.test(password)) score++;

  if (score <= 2) {
    label.textContent = 'Strength: Weak';
    fill.className = 'strength-fill strength-weak';
  } else if (score === 3 || score === 4) {
    label.textContent = 'Strength: Medium';
    fill.className = 'strength-fill strength-medium';
  } else {
    label.textContent = 'Strength: Strong';
    fill.className = 'strength-fill strength-strong';
  }
}

function validateStrength() {
  const password = document.getElementById('new_password').value;
  if (password.length < 8) {
    alert("Password must be at least 8 characters.");
    return false;
  }
  return true;
}
</script>

</body>
</html>