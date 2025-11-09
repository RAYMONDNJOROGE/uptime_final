<?php
/**
 * MikroTik RouterOS API Class
 * Improved version with better error handling and optimization
 */

require_once __DIR__ . '/../config.php';

class MikrotikAPI {
    private string $host;
    private string $user;
    private string $pass;
    private int $port;
    private $socket = null;
    private bool $connected = false;
    private int $timeout = 10;

    // Plan configurations matching frontend plans
    private array $plans = [
        '30min'   => ['uptime_limit' => '30m', 'profile' => '30_Minutes'],
        '2h'      => ['uptime_limit' => '2h', 'profile' => '2_Hours'],
        '12h'     => ['uptime_limit' => '12h', 'profile' => '12_Hours'],
        '24h'     => ['uptime_limit' => '1d', 'profile' => '24_Hours'],
        '48h'     => ['uptime_limit' => '2d', 'profile' => '48_Hours'],
        '1w'      => ['uptime_limit' => '7d', 'profile' => '1_Week'],
        
        // Alternative plan names for backward compatibility
        '30_Minutes' => ['uptime_limit' => '30m', 'profile' => '30_Minutes'],
        '2_Hours'    => ['uptime_limit' => '2h', 'profile' => '2_Hours'],
        '12_Hours'   => ['uptime_limit' => '12h', 'profile' => '12_Hours'],
        '24_Hours'   => ['uptime_limit' => '1d', 'profile' => '24_Hours'],
        '48_Hours'   => ['uptime_limit' => '2d', 'profile' => '48_Hours'],
        '1_Week'     => ['uptime_limit' => '7d', 'profile' => '1_Week']
    ];

    public function __construct() {
        $this->host = MIKROTIK_HOST;
        $this->user = MIKROTIK_USER;
        $this->pass = MIKROTIK_PASS;
        $this->port = MIKROTIK_PORT;
    }

    /**
     * Connect to MikroTik router
     */
    public function connect(): bool {
        if ($this->connected) {
            return true;
        }

        try {
            $this->socket = @fsockopen($this->host, $this->port, $errno, $errstr, $this->timeout);
            
            if (!$this->socket) {
                throw new Exception("Connection failed: $errstr ($errno)");
            }

            stream_set_timeout($this->socket, $this->timeout);
            
            // Initial login request
            $this->write('/login');
            $response = $this->read();

            // Check if challenge-response authentication is required
            if (isset($response[0]['ret'])) {
                // Challenge-response authentication (older RouterOS versions)
                $challenge = $response[0]['ret'];
                $md5 = md5(chr(0) . $this->pass . pack('H*', $challenge));
                
                $this->write('/login', false);
                $this->write('=name=' . $this->user, false);
                $this->write('=response=00' . $md5);
            } else {
                // Plain password authentication (newer RouterOS versions)
                $this->write('/login', false);
                $this->write('=name=' . $this->user, false);
                $this->write('=password=' . $this->pass);
            }

            $response = $this->read();
            
            if (isset($response[0]['!done'])) {
                $this->connected = true;
                error_log("✅ Connected to MikroTik at {$this->host}:{$this->port}");
                return true;
            }

            throw new Exception("Login failed: " . json_encode($response));

        } catch (Exception $e) {
            error_log("❌ MikroTik connection error: " . $e->getMessage());
            $this->cleanup();
            return false;
        }
    }

    /**
     * Disconnect from MikroTik router
     */
    public function disconnect(): void {
        if ($this->socket && $this->connected) {
            try {
                $this->write('/quit');
            } catch (Exception $e) {
                error_log("Error during quit: " . $e->getMessage());
            }
        }
        $this->cleanup();
    }

    /**
     * Clean up connection resources
     */
    private function cleanup(): void {
        if ($this->socket) {
            @fclose($this->socket);
            $this->socket = null;
        }
        $this->connected = false;
    }

    /**
     * Write command to socket
     */
    private function write(string $command, bool $end = true): void {
        if (!$this->socket) {
            throw new Exception("Socket not connected");
        }

        $length = strlen($command);
        
        // Encode length according to MikroTik API protocol
        if ($length < 0x80) {
            $lengthEncoded = chr($length);
        } elseif ($length < 0x4000) {
            $lengthEncoded = chr(($length >> 8) | 0x80) . chr($length & 0xFF);
        } elseif ($length < 0x200000) {
            $lengthEncoded = chr(($length >> 16) | 0xC0) . chr(($length >> 8) & 0xFF) . chr($length & 0xFF);
        } else {
            $lengthEncoded = chr(0xE0) . chr(($length >> 24) & 0xFF) . chr(($length >> 16) & 0xFF) . chr(($length >> 8) & 0xFF) . chr($length & 0xFF);
        }

        fwrite($this->socket, $lengthEncoded . $command);
        
        if ($end) {
            fwrite($this->socket, chr(0));
        }
    }

    /**
     * Read response from socket
     */
    private function read(): array {
        if (!$this->socket) {
            throw new Exception("Socket not connected");
        }

        $response = [];
        $current = [];

        while (true) {
            $lengthByte = ord(fread($this->socket, 1));
            
            // Parse length according to MikroTik API protocol
            if ($lengthByte === 0) {
                if (!empty($current)) {
                    $response[] = $current;
                }
                break;
            } elseif ($lengthByte < 0x80) {
                $length = $lengthByte;
            } elseif ($lengthByte < 0xC0) {
                $length = (($lengthByte & 0x3F) << 8) + ord(fread($this->socket, 1));
            } elseif ($lengthByte < 0xE0) {
                $length = (($lengthByte & 0x1F) << 16) + (ord(fread($this->socket, 1)) << 8) + ord(fread($this->socket, 1));
            } else {
                $length = (ord(fread($this->socket, 1)) << 24) + (ord(fread($this->socket, 1)) << 16) + (ord(fread($this->socket, 1)) << 8) + ord(fread($this->socket, 1));
            }

            $word = fread($this->socket, $length);

            // Check for sentence markers
            if ($word === '!done') {
                $current['!done'] = true;
                $response[] = $current;
                $current = [];
            } elseif ($word === '!trap') {
                $current['!trap'] = true;
            } elseif ($word === '!fatal') {
                $current['!fatal'] = true;
            } elseif (strpos($word, '=') !== false) {
                // Parse attribute
                $parts = explode('=', $word, 3);
                if (count($parts) >= 2) {
                    $key = ltrim($parts[1], '=');
                    $value = $parts[2] ?? '';
                    $current[$key] = $value;
                }
            }
        }

        return $response;
    }

    /**
     * Get available plans
     */
    public function getAvailablePlans(): array {
        return $this->plans;
    }

    /**
     * Set custom plans
     */
    public function setPlans(array $plans): void {
        $this->plans = $plans;
    }

    /**
     * Create or update hotspot user
     */
    public function createUser(string $username, string $password, string $plan): bool {
        if (!$this->connect()) {
            error_log("❌ Failed to connect to MikroTik for user creation");
            return false;
        }

        try {
            // Validate plan
            if (!isset($this->plans[$plan])) {
                throw new Exception("Invalid plan: $plan");
            }

            $planConfig = $this->plans[$plan];
            $profileName = $planConfig['profile'] ?? $plan;
            $uptimeLimit = $planConfig['uptime_limit'];

            error_log("📝 Creating/updating user: $username with plan: $plan (uptime: $uptimeLimit)");

            // Check if user already exists
            $this->write('/ip/hotspot/user/print', false);
            $this->write('?name=' . $username);
            $existing = $this->read();

            $userExists = false;
            $userId = null;

            foreach ($existing as $item) {
                if (isset($item['name']) && $item['name'] === $username && isset($item['.id'])) {
                    $userExists = true;
                    $userId = $item['.id'];
                    break;
                }
            }

            if ($userExists && $userId) {
                // Update existing user
                error_log("🔄 Updating existing user: $username");
                
                $this->write('/ip/hotspot/user/set', false);
                $this->write('=.id=' . $userId, false);
                $this->write('=password=' . $password, false);
                $this->write('=limit-uptime=' . $uptimeLimit, false);
                $this->write('=profile=' . $profileName, false);
                $this->write('=comment=' . $plan);
                
                $result = $this->read();

                if (isset($result[0]['!done'])) {
                    error_log("✅ User $username updated successfully");
                    return true;
                }

                throw new Exception("Failed to update user");
            } else {
                // Create new user
                error_log("➕ Creating new user: $username");
                
                $this->write('/ip/hotspot/user/add', false);
                $this->write('=name=' . $username, false);
                $this->write('=password=' . $password, false);
                $this->write('=limit-uptime=' . $uptimeLimit, false);
                $this->write('=profile=' . $profileName, false);
                $this->write('=comment=' . $plan);
                
                $response = $this->read();

                if (isset($response[0]['!done'])) {
                    error_log("✅ User $username created successfully");
                    return true;
                }

                // Check for errors
                if (isset($response[0]['!trap'])) {
                    $errorMsg = $response[0]['message'] ?? 'Unknown error';
                    throw new Exception("MikroTik error: $errorMsg");
                }

                throw new Exception("Failed to create user - unexpected response");
            }

        } catch (Exception $e) {
            error_log("❌ MikroTik user creation error: " . $e->getMessage());
            return false;
        } finally {
            $this->disconnect();
        }
    }

    /**
     * Remove user from MikroTik
     */
    public function removeUser(string $username): bool {
        if (!$this->connect()) {
            error_log("❌ Failed to connect to MikroTik for user removal");
            return false;
        }

        try {
            $this->write('/ip/hotspot/user/print', false);
            $this->write('?name=' . $username);
            $users = $this->read();

            $removed = false;
            foreach ($users as $user) {
                if (isset($user['.id']) && isset($user['name']) && $user['name'] === $username) {
                    $this->write('/ip/hotspot/user/remove', false);
                    $this->write('=.id=' . $user['.id']);
                    $this->read();
                    $removed = true;
                    error_log("✅ User $username removed successfully");
                }
            }

            return $removed;

        } catch (Exception $e) {
            error_log("❌ MikroTik user removal error: " . $e->getMessage());
            return false;
        } finally {
            $this->disconnect();
        }
    }

    /**
     * Get user information
     */
    public function getUserInfo(string $username): ?array {
        if (!$this->connect()) {
            return null;
        }

        try {
            $this->write('/ip/hotspot/user/print', false);
            $this->write('?name=' . $username);
            $response = $this->read();

            foreach ($response as $item) {
                if (isset($item['name']) && $item['name'] === $username) {
                    return $item;
                }
            }

            return null;

        } catch (Exception $e) {
            error_log("❌ MikroTik get user error: " . $e->getMessage());
            return null;
        } finally {
            $this->disconnect();
        }
    }

    /**
     * Disconnect active user session
     */
    public function disconnectUser(string $username): bool {
        if (!$this->connect()) {
            return false;
        }

        try {
            $this->write('/ip/hotspot/active/print', false);
            $this->write('?user=' . $username);
            $active = $this->read();

            $disconnected = false;
            foreach ($active as $session) {
                if (isset($session['.id'])) {
                    $this->write('/ip/hotspot/active/remove', false);
                    $this->write('=.id=' . $session['.id']);
                    $this->read();
                    $disconnected = true;
                    error_log("✅ Session disconnected for user: $username");
                }
            }

            return $disconnected;

        } catch (Exception $e) {
            error_log("❌ MikroTik disconnect user error: " . $e->getMessage());
            return false;
        } finally {
            $this->disconnect();
        }
    }

    /**
     * Get active hotspot sessions
     */
    public function getActiveHotspotSessions(): array {
        if (!$this->connect()) {
            return [];
        }

        try {
            $this->write('/ip/hotspot/active/print');
            $sessions = $this->read();
            return $sessions;
        } catch (Exception $e) {
            error_log("❌ Error getting active sessions: " . $e->getMessage());
            return [];
        } finally {
            $this->disconnect();
        }
    }

    /**
     * Get hotspot user profiles
     */
    public function getHotspotUserProfiles(): array {
        if (!$this->connect()) {
            return [];
        }

        try {
            $this->write('/ip/hotspot/user/profile/print');
            $profiles = $this->read();
            return $profiles;
        } catch (Exception $e) {
            error_log("❌ Error getting profiles: " . $e->getMessage());
            return [];
        } finally {
            $this->disconnect();
        }
    }

    /**
     * Get all hotspot users
     */
    public function getAllUsers(): array {
        if (!$this->connect()) {
            return [];
        }

        try {
            $this->write('/ip/hotspot/user/print');
            $users = $this->read();
            return $users;
        } catch (Exception $e) {
            error_log("❌ Error getting users: " . $e->getMessage());
            return [];
        } finally {
            $this->disconnect();
        }
    }

    /**
     * Test connection to MikroTik
     */
    public function testConnection(): array {
        $startTime = microtime(true);
        
        if (!$this->connect()) {
            return [
                'success' => false,
                'message' => 'Connection failed',
                'time' => round((microtime(true) - $startTime) * 1000, 2) . 'ms'
            ];
        }

        try {
            $this->write('/system/identity/print');
            $identity = $this->read();
            
            $routerName = $identity[0]['name'] ?? 'Unknown';
            
            return [
                'success' => true,
                'message' => 'Connected successfully',
                'router' => $routerName,
                'time' => round((microtime(true) - $startTime) * 1000, 2) . 'ms'
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'time' => round((microtime(true) - $startTime) * 1000, 2) . 'ms'
            ];
        } finally {
            $this->disconnect();
        }
    }

    /**
     * Destructor - ensure connection is closed
     */
    public function __destruct() {
        $this->disconnect();
    }
}
?>