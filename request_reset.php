<?php
require_once __DIR__ . '/config.php';
require __DIR__ . '/vendor/autoload.php'; // PHPMailer autoload

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

session_start();
$pdo = getDBConnection();

$ip = $_SERVER['REMOTE_ADDR'];
$maxAttemptsPerDay = 5;
$cooldownSeconds = 60;
$now = time();
$nowSql = date('Y-m-d H:i:s', $now);
$message = '';
$isError = false;

$email = trim($_POST['email'] ?? '');
$resend = isset($_POST['resend']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$email) {
        $message = "Please enter your email.";
        $isError = true;
    } else {
        // Check if email exists
        $stmt = $pdo->prepare("SELECT id FROM admins WHERE email = ? AND is_active = 1 LIMIT 1");
        $stmt->execute([$email]);
        $admin = $stmt->fetch();

        if (!$admin) {
            $message = "No account found with that email.";
            $isError = true;
        } else {
            // Check OTP attempt record
            $stmt = $pdo->prepare("SELECT attempts, last_attempt, first_attempt FROM otp_attempts WHERE email = ? AND ip_address = ?");
            $stmt->execute([$email, $ip]);
            $record = $stmt->fetch();

            if ($record) {
                $last = strtotime($record['last_attempt']);
                $first = strtotime($record['first_attempt']);

                if ($record['attempts'] >= $maxAttemptsPerDay && $first > strtotime('-1 day')) {
                    $message = "Too many OTP requests for this email from your IP. Try again tomorrow.";
                    $isError = true;
                } elseif (($now - $last) < $cooldownSeconds) {
                    $wait = $cooldownSeconds - ($now - $last);
                    $message = "Please wait {$wait} seconds before requesting again.";
                    $isError = true;
                }
            }

            if (!$isError) {
                $otp = str_pad(random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
                $expires = date('Y-m-d H:i:s', strtotime('+10 minutes'));

                $pdo->prepare("UPDATE admins SET reset_otp = ?, otp_expires_at = ? WHERE email = ?")
                    ->execute([$otp, $expires, $email]);

                if ($record) {
                    $pdo->prepare("UPDATE otp_attempts SET attempts = attempts + 1, last_attempt = ? WHERE email = ? AND ip_address = ?")
                        ->execute([$nowSql, $email, $ip]);
                } else {
                    $pdo->prepare("INSERT INTO otp_attempts (email, ip_address, attempts, last_attempt, first_attempt) VALUES (?, ?, 1, ?, ?)")
                        ->execute([$email, $ip, $nowSql, $nowSql]);
                }

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
                    $mail->Subject = 'Your OTP Code';
                    $mail->Body = "Your OTP is: $otp\nIt expires in 10 minutes.";

                    $mail->send();
                    $_SESSION['otp_email'] = $email;
                    $message = $resend ? "OTP resent to your email." : "OTP sent to your email.";
                } catch (Exception $e) {
                    $message = "Email failed: {$mail->ErrorInfo}";
                    $isError = true;
                }
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
<body class="bg-blue-100 min-h-screen flex items-center justify-center font-sans">
  <div class="w-full max-w-md bg-white border border-blue-200 rounded-2xl shadow-xl p-8">

    <h2 class="text-xl font-bold text-blue-800 mb-4 text-center">Request Password Reset</h2>

    <?php if ($message): ?>
      <div class="p-3 rounded-md mb-4 text-sm text-center shadow-sm 
        <?= $isError ? 'bg-red-100 border border-red-300 text-red-700' : 'bg-blue-100 border border-blue-300 text-blue-700' ?>">
        <?= htmlspecialchars($message) ?>
      </div>
    <?php endif; ?>

    <form method="POST" class="space-y-4">
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Email:</label>
        <input type="email" name="email" required
          value="<?= htmlspecialchars($_SESSION['otp_email'] ?? '') ?>"
          class="w-full px-4 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
      </div>

      <button type="submit"
        class="w-full bg-blue-600 hover:bg-blue-700 text-white font-medium py-2.5 rounded-lg shadow-md focus:ring-2 focus:ring-blue-400 focus:ring-offset-1">
        Send OTP
      </button>

      <?php if (isset($_SESSION['otp_email'])): ?>
        <button type="submit" name="resend"
          class="w-full bg-blue-500 hover:bg-blue-600 text-white font-medium py-2.5 rounded-lg shadow-md focus:ring-2 focus:ring-blue-300 focus:ring-offset-1">
          Resend OTP
        </button>
      <?php endif; ?>
    </form>

    <p class="text-center text-sm text-gray-600 mt-5">
      <a href="reset_with_otp.php" class="text-blue-600 hover:underline hover:text-blue-800">Received OTP?</a>
    </p>
  </div>
</body>
</html>