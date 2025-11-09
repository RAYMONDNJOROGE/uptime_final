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
$rememberedUsername = $_COOKIE['remember_username'] ?? '';
$error = $_SESSION['login_error'] ?? '';
unset($_SESSION['login_error']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember = isset($_POST['remember']);

    if (!$username || !$password) {
        $_SESSION['login_error'] = "Please enter both username and password.";
        header("Location: index.php");
        exit;
    }

    // Check login attempts by username + IP
    $stmt = $pdo->prepare("SELECT attempts, last_attempt FROM login_attempts WHERE username = ? AND ip_address = ?");
    $stmt->execute([$username, $ip]);
    $attempt = $stmt->fetch();
    $remainingAttempts = $maxAttempts;

    if ($attempt) {
        $remainingAttempts = max(0, $maxAttempts - $attempt['attempts']);
        $lockedOut = $attempt['attempts'] >= $maxAttempts && strtotime($attempt['last_attempt']) > strtotime("-{$lockoutMinutes} minutes");
        if ($lockedOut) {
            $_SESSION['login_error'] = "Too many failed attempts for this account from your IP. Try again after {$lockoutMinutes} minutes.";
            header("Location: index.php");
            exit;
        }
    }

    // Validate credentials
    $stmt = $pdo->prepare("SELECT id, username, password, role FROM admins WHERE username = ? AND is_active = 1 LIMIT 1");
    $stmt->execute([$username]);
    $admin = $stmt->fetch();

    if (!$admin || !password_verify($password, $admin['password'])) {
        if ($attempt) {
            $pdo->prepare("UPDATE login_attempts SET attempts = attempts + 1, last_attempt = NOW() WHERE username = ? AND ip_address = ?")
                ->execute([$username, $ip]);
        } else {
            $pdo->prepare("INSERT INTO login_attempts (username, ip_address, attempts, last_attempt) VALUES (?, ?, 1, NOW())")
                ->execute([$username, $ip]);
        }

        $remainingAttempts = max(0, $remainingAttempts - 1);
        $_SESSION['login_error'] = "Invalid credentials. You have {$remainingAttempts} attempt(s) left.";
        header("Location: index.php");
        exit;
    }

    // Success
    $pdo->prepare("DELETE FROM login_attempts WHERE username = ? AND ip_address = ?")->execute([$username, $ip]);

    $_SESSION['authenticated'] = true;
    $_SESSION['username'] = $admin['username'];
    $_SESSION['admin_id'] = $admin['id'];
    $_SESSION['role'] = $admin['role'];

    if ($remember) {
        setcookie('remember_username', $username, time() + (30 * 24 * 60 * 60), "/");
    } else {
        setcookie('remember_username', '', time() - 3600, "/");
    }

    header('Location: ' . ($admin['role'] === 'super' ? 'admin.php' : 'dashboard_lite.php'));
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Login | Uptime Hotspot</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-blue-100 min-h-screen flex flex-col items-center justify-center font-sans">

  <!-- Login Card -->
  <div class="w-full max-w-md bg-white border border-blue-200 rounded-2xl shadow-xl p-8">

    <h1 class="text-3xl font-extrabold text-center text-blue-900 mb-1 tracking-wide">UPTIME HOTSPOT</h1>
    <h2 class="text-lg font-semibold text-center text-blue-700 mb-6">Admin Login</h2>

    <?php if (!empty($error)): ?>
      <div class="bg-red-100 border border-red-300 text-red-700 p-3 rounded-md mb-4 text-sm shadow-sm">
        <?= htmlspecialchars($error) ?>
      </div>
    <?php endif; ?>

    <form method="POST" class="space-y-5">
      <!-- Username -->
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Username</label>
        <input type="text" name="username" value="<?= htmlspecialchars($rememberedUsername) ?>" required
          class="w-full px-4 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
      </div>

      <!-- Password -->
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Password</label>
        <div class="relative">
          <input type="password" name="password" id="password" required
            class="w-full px-4 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
          <button type="button" onclick="togglePassword()"
            class="absolute right-3 top-2.5 text-sm text-blue-600 hover:text-blue-800 font-medium"
            aria-label="Toggle password visibility">Show</button>
        </div>
      </div>

      <!-- Remember Me -->
      <div class="flex items-center">
        <input type="checkbox" name="remember" id="remember"
          class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded" <?= $rememberedUsername ? 'checked' : '' ?>>
        <label for="remember" class="ml-2 block text-sm text-gray-700">Remember Me</label>
      </div>

      <!-- Submit -->
      <button type="submit"
        class="w-full bg-blue-600 hover:bg-blue-700 text-white font-medium py-2.5 rounded-lg shadow-md focus:ring-2 focus:ring-blue-400 focus:ring-offset-1">
        Login
      </button>
    </form>

    <!-- Links -->
    <div class="text-center text-sm text-gray-600 mt-5 space-y-2">
      <p><a href="request_reset.php" class="text-blue-600 underline hover:text-blue-800">Forgot Password?</a></p>
      <p><a href="register.php" class="text-blue-600 underline hover:text-blue-800">Don't have an account? Register</a></p>
    </div>
  </div>

  <!-- Footer -->
  <div class="mt-6 text-center text-sm text-gray-700 space-y-1">
    <p class="font-semibold">Uptime Tech Masters</p>
    <p><span>Phone: </span><a href="tel:0791024153" class="text-blue-700 underline">0791024153</a></p>
    <p><span>Email: </span><a href="mailto:uptimetechmasters@gmail.com" class="text-blue-700 underline">uptimetechmasters@gmail.com</a></p>
  </div>

  <script>
    function togglePassword() {
      const input = document.getElementById('password');
      const button = event.target;
      input.type = input.type === 'password' ? 'text' : 'password';
      button.textContent = input.type === 'password' ? 'Show' : 'Hide';
    }
  </script>
</body>
</html>