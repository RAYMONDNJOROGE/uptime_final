<?php
require_once __DIR__ . '/config.php';
$pdo = getDBConnection();
$message = '';
$isSuccess = false;

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
        $stmt = $pdo->prepare("SELECT reset_otp, otp_expires_at FROM admins WHERE email = ? AND is_active = 1 LIMIT 1");
        $stmt->execute([$email]);
        $admin = $stmt->fetch();

        if (!$admin) {
            $message = "❌ No active admin found with that email.";
        } elseif ($admin['reset_otp'] !== $otp) {
            $message = "❌ Incorrect OTP.";
        } elseif ($admin['otp_expires_at'] < date('Y-m-d H:i:s')) {
            $message = "⏱️ OTP has expired. Please request a new one.";
        } else {
            $hash = password_hash($new, PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE admins SET password = ?, reset_otp = NULL, otp_expires_at = NULL WHERE email = ?")
                ->execute([$hash, $email]);

            $message = "Password updated. You can now log in.";
            $isSuccess = true;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Reset Password | Uptime Hotspot</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gradient-to-br from-blue-100 via-blue-200 to-blue-300 min-h-screen flex items-center justify-center font-sans">
  <div class="w-full max-w-md bg-white/80 backdrop-blur-md border border-blue-100 rounded-2xl shadow-xl p-8">
    <h2 class="text-xl font-bold text-blue-800 mb-4 text-center">🔑 Reset Your Password</h2>

    <?php if ($message): ?>
      <?php
        $bg = $isSuccess ? 'bg-green-100 border-green-300 text-green-700' : 'bg-red-100 border-red-300 text-red-700';
      ?>
      <div class="<?= $bg ?> p-3 rounded-md mb-4 text-sm text-center shadow-sm">
        <?= htmlspecialchars($message) ?>
      </div>
    <?php endif; ?>

    <form method="POST" class="space-y-4">
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
        <input type="email" name="email" required class="w-full px-4 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-2 focus:ring-blue-500">
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">OTP</label>
        <input type="text" name="otp" required class="w-full px-4 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-2 focus:ring-blue-500">
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">New Password</label>
        <input type="password" name="new_password" required class="w-full px-4 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-2 focus:ring-blue-500">
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Confirm Password</label>
        <input type="password" name="confirm_password" required class="w-full px-4 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-2 focus:ring-blue-500">
      </div>
      <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-medium py-2.5 rounded-lg shadow-md">
        Reset Password
      </button>
    </form>

    <p class="text-center text-sm text-gray-600 mt-5">
      <a href="index.php" class="text-blue-600 hover:underline hover:text-blue-800 transition">
        ← Back to Login
      </a>
    </p>
  </div>
</body>
</html>