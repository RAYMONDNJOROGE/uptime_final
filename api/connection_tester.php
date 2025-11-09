<?php
/**
 * Manual MikroTik API Connection Tester
 * -------------------------------------
 * Enter your MikroTik credentials below and test connectivity.
 */

class MikroTikManual {
    private $host;
    private $user;
    private $pass;
    private $port;
    private $socket = null;
    private $connected = false;

    public function __construct($host, $user, $pass, $port = 8728) {
        $this->host = $host;
        $this->user = $user;
        $this->pass = $pass;
        $this->port = $port;
    }

    public function connect() {
        $this->socket = @fsockopen($this->host, $this->port, $errno, $errstr, 5);
        if (!$this->socket) return "❌ Socket error: $errstr ($errno)";

        stream_set_timeout($this->socket, 5);
        $this->write('/login');
        $response = $this->read();

        if (isset($response[0]['!done']) && isset($response[0]['ret'])) {
            $challenge = $response[0]['ret'];
            $md5 = md5(chr(0) . $this->pass . pack('H*', $challenge));
            $this->write('/login', false);
            $this->write('=name=' . $this->user, false);
            $this->write('=response=00' . $md5);
        } else {
            $this->write('/login', false);
            $this->write('=name=' . $this->user, false);
            $this->write('=password=' . $this->pass);
        }

        $response = $this->read();
        if (isset($response[0]['!done'])) {
            $this->connected = true;
            return "✅ Connected successfully to MikroTik router at {$this->host}";
        }

        return "❌ Login failed: " . htmlspecialchars($response[0]['message'] ?? 'Unknown error');
    }

    public function disconnect() {
        if ($this->socket) fclose($this->socket);
    }

    private function write($command, $end = true) {
        fwrite($this->socket, chr(strlen($command)) . $command);
        if ($end) fwrite($this->socket, chr(0));
    }

    private function read() {
        $response = [];
        while (true) {
            $length = ord(fread($this->socket, 1));
            if ($length == 0) break;
            $word = fread($this->socket, $length);
            $parts = explode('=', $word, 2);
            if (count($parts) == 2) {
                $response[] = [$parts[0] => $parts[1]];
            } else {
                $response[] = [$word => true];
            }
        }
        return $response;
    }
}

// Handle form submission
$status = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $host = $_POST['host'];
    $user = $_POST['user'];
    $pass = $_POST['pass'];
    $port = $_POST['port'] ?: 8728;

    $api = new MikroTikManual($host, $user, $pass, $port);
    $status = $api->connect();
    $api->disconnect();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>MikroTik Manual Connection Test</title>
  <style>
    body { font-family: sans-serif; background: #f0f0f0; padding: 2rem; }
    form { background: #fff; padding: 1rem; border-radius: 8px; max-width: 400px; margin-bottom: 2rem; }
    input { width: 100%; padding: 0.5rem; margin-bottom: 1rem; }
    button { padding: 0.5rem 1rem; background: #0077cc; color: white; border: none; border-radius: 4px; }
    .status { padding: 1rem; border-radius: 6px; background: #fff; max-width: 400px; }
  </style>
</head>
<body>

<h2>MikroTik Manual Connection Test</h2>

<form method="POST">
  <label>Router IP:</label>
  <input type="text" name="host" required placeholder="e.g. 192.168.88.1">
  <label>Username:</label>
  <input type="text" name="user" required placeholder="e.g. admin">
  <label>Password:</label>
  <input type="password" name="pass" required>
  <label>Port (default 8728):</label>
  <input type="text" name="port" placeholder="8728">
  <button type="submit">Test Connection</button>
</form>

<?php if ($status): ?>
  <div class="status"><?= $status ?></div>
<?php endif; ?>

</body>
</html>