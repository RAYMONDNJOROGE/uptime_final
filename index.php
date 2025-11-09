<?php
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_secure', 0);

session_start();
require_once __DIR__ . '/config.php';

$error = '';
$pdo = getDBConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!$username || !$password) {
        $error = "Please enter both username and password.";
    } else {
        $stmt = $pdo->prepare("SELECT id, username, password, role FROM admins WHERE username = ? AND is_active = 1 LIMIT 1");
        $stmt->execute([$username]);
        $admin = $stmt->fetch();

        if (!$admin) {
            $error = "❌ No account found for that username.";
        } elseif (!password_verify($password, $admin['password'])) {
            $error = "Incorrect password. Please try again.";
        } else {
            $_SESSION['authenticated'] = true;
            $_SESSION['username'] = $admin['username'];
            $_SESSION['admin_id'] = $admin['id'];
            $_SESSION['role'] = $admin['role'];

            if ($admin['role'] === 'super') {
                header('Location: admin.php');
            } else {
                header('Location: dashboard_lite.php');
            }
            exit;
        }
    }
}

$rememberedUsername = $_COOKIE['remember_username'] ?? '';
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Login | Uptime Hotspot</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gradient-to-br from-blue-100 via-blue-200 to-blue-300 min-h-screen flex items-center justify-center font-sans">

  <div class="w-full max-w-md bg-white/80 backdrop-blur-md border border-blue-100 rounded-2xl shadow-xl p-8 transition transform hover:-translate-y-1 hover:shadow-2xl">

    <!-- 🔷 Header -->
    <h1 class="text-3xl font-extrabold text-center text-blue-900 mb-1 tracking-wide">
      UPTIME HOTSPOT
    </h1>
    <h2 class="text-lg font-semibold text-center text-blue-700 mb-6">
      Admin Login
    </h2>

    <!-- 🔔 Error Message -->
    <?php if ($error): ?>
      <div class="flex items-center bg-red-100 border border-red-300 text-red-700 p-3 rounded-md mb-4 text-sm shadow-sm" role="alert" aria-live="polite">
        <svg class="w-5 h-5 mr-2 text-red-500" fill="currentColor" viewBox="0 0 20 20">
          <path fill-rule="evenodd" d="M8.257 3.099c.366-.446.957-.446 1.323 0l6.518 7.945c.329.4.329.957 0 1.357l-6.518 7.945c-.366.446-.957.446-1.323 0L1.739 12.401a1 1 0 010-1.357l6.518-7.945zM11 13a1 1 0 10-2 0v2a1 1 0 002 0v-2zm-1-8a1 1 0 100 2 1 1 0 000-2z" clip-rule="evenodd"/>
        </svg>
        <span><?= htmlspecialchars($error) ?></span>
      </div>
    <?php endif; ?>

    <!-- 🔑 Login Form -->
    <form method="POST" class="space-y-5">

      <!-- Username -->
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Username</label>
        <input 
          type="text" 
          name="username" 
          value="<?= htmlspecialchars($rememberedUsername) ?>" 
          required
          class="w-full px-4 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition duration-150"
        >
      </div>

      <!-- Password -->
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Password</label>
        <div class="relative">
          <input 
            type="password" 
            name="password" 
            id="password" 
            required
            class="w-full px-4 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition duration-150"
          >
          <button 
            type="button" 
            onclick="togglePassword()" 
            class="absolute right-3 top-2.5 text-sm text-blue-600 hover:text-blue-800 font-medium focus:outline-none"
          >
            Show
          </button>
        </div>
      </div>

      <!-- Submit Button -->
      <button 
        type="submit"
        class="w-full bg-blue-600 hover:bg-blue-700 text-white font-medium py-2.5 rounded-lg shadow-md transition duration-200 focus:ring-2 focus:ring-blue-400 focus:ring-offset-1"
      >
        Login
      </button>
    </form>

    <!-- Forgot Password + Register -->
    <div class="text-center text-sm text-gray-600 mt-5 space-y-2">
      <p>
        <a href="request_reset.php" class="text-blue-600 hover:underline hover:text-blue-800 transition">
          Forgot Password?
        </a>
      </p>
      <p>
        <a href="register.php" class="text-blue-600 hover:underline hover:text-blue-800 transition">
          Don't have an account? Register Here
        </a>
      </p>
    </div>
  </div>

  <script>
    function togglePassword() {
      const input = document.getElementById('password');
      const button = event.target;
      if (input.type === 'password') {
        input.type = 'text';
        button.textContent = 'Hide';
      } else {
        input.type = 'password';
        button.textContent = 'Show';
      }
    }
  </script>

</body>
</html>