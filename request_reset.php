<?php
require_once __DIR__ . '/config.php';
require __DIR__ . '/vendor/autoload.php'; // PHPMailer autoload

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$pdo = getDBConnection();
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');

    if (!$email) {
        $message = "Please enter your email.";
    } else {
        // Check if email exists
        $stmt = $pdo->prepare("SELECT id FROM admins WHERE email = ? AND is_active = 1 LIMIT 1");
        $stmt->execute([$email]);
        $admin = $stmt->fetch();

        if (!$admin) {
            $message = "No admin found with that email.";
        } else {
            // Generate OTP and expiry
            $otp = str_pad(random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
            $expires = date('Y-m-d H:i:s', strtotime('+10 minutes'));

            $pdo->prepare("UPDATE admins SET reset_otp = ?, otp_expires_at = ? WHERE email = ?")
                ->execute([$otp, $expires, $email]);

            // Send OTP via PHPMailer
            $mail = new PHPMailer(true);
            try {
                $mail->isSMTP();
                $mail->Host = 'smtp.gmail.com';
                $mail->SMTPAuth = true;
                $mail->Username = 'uptimetechmasters@gmail.com';       // Replace with your email
                $mail->Password = 'aszhqrssbadrfyml';          // Use Gmail App Password
                $mail->SMTPSecure = 'tls';
                $mail->Port = 587;

                $mail->setFrom('uptimetechmasters@gmail.com', 'Uptime Hotspot');
                $mail->addAddress($email);
                $mail->Subject = 'Your OTP Code';
                $mail->Body    = "Your OTP is: $otp\nIt expires in 10 minutes.";

                $mail->send();
                $message = "OTP sent to your email.";
            } catch (Exception $e) {
                $message = "Email failed: {$mail->ErrorInfo}";
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Request OTP | Uptime Hotspot</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gradient-to-br from-blue-100 via-blue-200 to-blue-300 min-h-screen flex items-center justify-center font-sans">
  <div class="w-full max-w-md bg-white/80 backdrop-blur-md border border-blue-100 rounded-2xl shadow-xl p-8">
    <h2 class="text-xl font-bold text-blue-800 mb-4 text-center">Request Password Reset</h2>

    <?php if ($message): ?>
      <div class="bg-blue-100 border border-blue-300 text-blue-700 p-3 rounded-md mb-4 text-sm text-center shadow-sm">
        <?= htmlspecialchars($message) ?>
      </div>
    <?php endif; ?>

    <form method="POST" class="space-y-4">
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
        <input type="email" name="email" required class="w-full px-4 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-2 focus:ring-blue-500">
      </div>
      <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-medium py-2.5 rounded-lg shadow-md">
        Send OTP
      </button>
    </form>

    <p class="text-center text-sm text-gray-600 mt-5">
      <a href="reset_with_otp.php" class="text-blue-600 hover:underline">Received OTP?</a>
    </p>
  </div>
</body>
</html>