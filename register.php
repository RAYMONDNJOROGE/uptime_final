<?php
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_secure', 0);

session_start();
require_once __DIR__ . '/config.php';
$pdo = getDBConnection();

$maxAttempts = 5;
$lockoutMinutes = 15;
$ip = $_SERVER['REMOTE_ADDR'];
$message = '';
$isError = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    $token = trim($_POST['auth_token'] ?? '');

    if (!$username || !$email || !$password || !$confirm || !$token) {
        $message = "All fields are required.";
        $isError = true;
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = "Invalid email format.";
        $isError = true;
    } elseif ($password !== $confirm) {
        $message = "Passwords do not match.";
        $isError = true;
    } else {
        try {
            // Check lockout
            $stmt = $pdo->prepare("SELECT attempts, last_attempt FROM login_attempts WHERE username = ? AND ip_address = ?");
            $stmt->execute([$username, $ip]);
            $attempt = $stmt->fetch();
            $remainingAttempts = $maxAttempts;

            if ($attempt) {
                $remainingAttempts = max(0, $maxAttempts - $attempt['attempts']);
                $lockedOut = $attempt['attempts'] >= $maxAttempts && strtotime($attempt['last_attempt']) > strtotime("-{$lockoutMinutes} minutes");
                if ($lockedOut) {
                    $message = "Too many failed attempts for this account from your IP. Try again after {$lockoutMinutes} minutes.";
                    $isError = true;
                }
            }

            if (!$isError) {
                $normalizedToken = strtolower($token);
                $normalizedEmail = strtolower($email);

                $stmt = $pdo->prepare("SELECT id FROM registration_tokens WHERE LOWER(token) = ? AND LOWER(recipient_email) = ? AND used = 0 AND expires_at > NOW() LIMIT 1");
                $stmt->execute([$normalizedToken, $normalizedEmail]);
                $validToken = $stmt->fetch();

                if (!$validToken) {
                    $message = "Invalid or expired authorization token for this email.";
                    $isError = true;
                } else {
                    $stmt = $pdo->prepare("SELECT id FROM admins WHERE username = ? OR email = ? LIMIT 1");
                    $stmt->execute([$username, $email]);
                    if ($stmt->fetch()) {
                        $message = "Username or email already exists.";
                        $isError = true;
                    } else {
                        $hash = password_hash($password, PASSWORD_DEFAULT);
                        $pdo->prepare("INSERT INTO admins (username, email, password, is_active, role, created_at) VALUES (?, ?, ?, 1, 'regular', NOW())")
                            ->execute([$username, $email, $hash]);

                        $pdo->prepare("UPDATE registration_tokens SET used = 1 WHERE id = ?")->execute([$validToken['id']]);
                        $pdo->prepare("DELETE FROM login_attempts WHERE username = ? AND ip_address = ?")->execute([$username, $ip]);

                        $_SESSION['authenticated'] = true;
                        $_SESSION['username'] = $username;
                        $_SESSION['role'] = 'regular';

                        header('Location: index.php');
                        exit;
                    }
                }
            }

            // If error occurred, increment attempt count
            if ($isError) {
                if ($attempt) {
                    $pdo->prepare("UPDATE login_attempts SET attempts = attempts + 1, last_attempt = NOW() WHERE username = ? AND ip_address = ?")
                        ->execute([$username, $ip]);
                } else {
                    $pdo->prepare("INSERT INTO login_attempts (username, ip_address, attempts, last_attempt) VALUES (?, ?, 1, NOW())")
                        ->execute([$username, $ip]);
                }

                $remainingAttempts = max(0, $remainingAttempts - 1);
                $message .= " You have {$remainingAttempts} attempt(s) left.";
            }

        } catch (PDOException $e) {
            $message = "Server error: " . htmlspecialchars($e->getMessage());
            $isError = true;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Create User | Uptime Hotspot</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gradient-to-br from-blue-100 via-blue-200 to-blue-300 min-h-screen flex items-center justify-center font-sans">
  <div class="w-full max-w-md bg-white/80 backdrop-blur-md border border-blue-100 rounded-2xl shadow-xl p-8">
    <h2 class="text-xl font-bold text-blue-800 mb-4 text-center">Create User Account</h2>

    <?php if ($message): ?>
      <div class="p-3 rounded-md mb-4 text-sm text-center shadow-sm 
        <?= $isError ? 'bg-red-100 border border-red-300 text-red-700' : 'bg-green-100 border border-green-300 text-green-700' ?>">
        <?= htmlspecialchars($message) ?>
      </div>
    <?php endif; ?>

    <form method="POST" class="space-y-4">
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Username</label>
        <input type="text" name="username" required class="w-full px-4 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-2 focus:ring-blue-500">
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
        <input type="email" name="email" required class="w-full px-4 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-2 focus:ring-blue-500">
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Password</label>
        <input type="password" name="password" required class="w-full px-4 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-2 focus:ring-blue-500">
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Confirm Password</label>
        <input type="password" name="confirm_password" required class="w-full px-4 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-2 focus:ring-blue-500">
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Authorization Token</label>
        <input type="text" name="auth_token" required class="w-full px-4 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-2 focus:ring-blue-500">
      </div>
      <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-medium py-2.5 rounded-lg shadow-md">
        Create Account
      </button>
    </form>

    <p class="text-center text-sm text-gray-600 mt-5">
      <a href="index.php" class="text-blue-600 hover:underline">← Back to Login</a>
    </p>
  </div>
</body>
</html>