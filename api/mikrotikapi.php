<?php
/**
 * MikroTik RouterOS API Class
 * Enhanced version with better error handling, connection management, and optimization
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
    private int $maxRetries = 2;

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
     * Connect to MikroTik router with retry logic
     */
    public function connect(): bool {
        if ($this->connected && $this->socket) {
            // Verify connection is still alive
            if ($this->isSocketAlive()) {
                return true;
            }
            $this->cleanup();
        }

        $attempts = 0;
        $lastError = null;

        while ($attempts < $this->maxRetries) {
            try {
                $attempts++;
                
                // Attempt to open socket connection
                $context = stream_context_create([
                    'socket' => [
                        'tcp_nodelay' => true,
                    ]
                ]);
                
                $this->socket = @stream_socket_client(
                    "tcp://{$this->host}:{$this->port}",
                    $errno,
                    $errstr,
                    $this->timeout,
                    STREAM_CLIENT_CONNECT,
                    $context
                );
                
                if (!$this->socket) {
                    throw new Exception("Connection failed: $errstr ($errno)");
                }

                stream_set_timeout($this->socket, $this->timeout);
                stream_set_blocking($this->socket, true);
                
                // Perform authentication
                if ($this->authenticate()) {
                    $this->connected = true;
                    error_log("Connected to MikroTik at {$this->host}:{$this->port} (attempt $attempts)");
                    return true;
                }
                
                throw new Exception("Authentication failed");

            } catch (Exception $e) {
                $lastError = $e->getMessage();
                error_log("MikroTik connection attempt $attempts failed: " . $lastError);
                $this->cleanup();
                
                if ($attempts < $this->maxRetries) {
                    usleep(500000); // Wait 0.5 seconds before retry
                }
            }
        }

        error_log("MikroTik connection failed after {$this->maxRetries} attempts: $lastError");
        return false;
    }

    /**
     * Authenticate with MikroTik
     */
    private function authenticate(): bool {
        try {
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
            
            return isset($response[0]['!done']);

        } catch (Exception $e) {
            error_log("Authentication error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if socket is still alive
     */
    private function isSocketAlive(): bool {
        if (!$this->socket) {
            return false;
        }

        try {
            $meta = stream_get_meta_data($this->socket);
            return !$meta['eof'] && !$meta['timed_out'];
        } catch (Exception $e) {
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
                usleep(100000); // Wait 0.1 seconds for graceful disconnect
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
     * Write command to socket with improved error handling
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

        $data = $lengthEncoded . $command;
        if ($end) {
            $data .= chr(0);
        }

        $written = @fwrite($this->socket, $data);
        
        if ($written === false || $written < strlen($data)) {
            throw new Exception("Failed to write to socket");
        }
    }

    /**
     * Read response from socket with improved error handling
     */
    private function read(): array {
        if (!$this->socket) {
            throw new Exception("Socket not connected");
        }

        $response = [];
        $current = [];
        $timeout = time() + $this->timeout;

        while (time() < $timeout) {
            // Check for data availability
            $read = [$this->socket];
            $write = null;
            $except = null;
            
            if (stream_select($read, $write, $except, 1) === 0) {
                continue;
            }

            $lengthByte = @fread($this->socket, 1);
            
            if ($lengthByte === false || $lengthByte === '') {
                throw new Exception("Failed to read from socket");
            }
            
            $lengthByte = ord($lengthByte);
            
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

        if (empty($response)) {
            throw new Exception("Read timeout - no response received");
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
     * Validate plan exists
     */
    private function validatePlan(string $plan): array {
        if (!isset($this->plans[$plan])) {
            throw new Exception("Invalid plan: $plan. Available plans: " . implode(', ', array_keys($this->plans)));
        }
        return $this->plans[$plan];
    }

    /**
     * Create or update hotspot user with enhanced error handling
     */
    public function createUser(string $username, string $password, string $plan): bool {
        // Validate inputs
        if (empty($username) || strlen($username) > 64) {
            error_log("Invalid username: must be 1-64 characters");
            return false;
        }

        if (empty($password) || strlen($password) > 64) {
            error_log("Invalid password: must be 1-64 characters");
            return false;
        }

        if (!$this->connect()) {
            error_log("Failed to connect to MikroTik for user creation");
            return false;
        }

        try {
            // Validate and get plan configuration
            $planConfig = $this->validatePlan($plan);
            $profileName = $planConfig['profile'] ?? $plan;
            $uptimeLimit = $planConfig['uptime_limit'];

            error_log("Creating/updating user: $username with plan: $plan (uptime: $uptimeLimit, profile: $profileName)");

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
                error_log("Updating existing user: $username (ID: $userId)");
                
                $this->write('/ip/hotspot/user/set', false);
                $this->write('=.id=' . $userId, false);
                $this->write('=password=' . $password, false);
                $this->write('=limit-uptime=' . $uptimeLimit, false);
                $this->write('=profile=' . $profileName, false);
                $this->write('=comment=' . $plan);
                
                $result = $this->read();

                if (isset($result[0]['!done'])) {
                    error_log("User $username updated successfully");
                    return true;
                }

                if (isset($result[0]['!trap'])) {
                    $errorMsg = $result[0]['message'] ?? 'Unknown error';
                    throw new Exception("Update failed: $errorMsg");
                }

                throw new Exception("Failed to update user - unexpected response");
            } else {
                // Create new user
                error_log("Creating new user: $username");
                
                $this->write('/ip/hotspot/user/add', false);
                $this->write('=name=' . $username, false);
                $this->write('=password=' . $password, false);
                $this->write('=limit-uptime=' . $uptimeLimit, false);
                $this->write('=profile=' . $profileName, false);
                $this->write('=comment=' . $plan);
                
                $response = $this->read();

                if (isset($response[0]['!done'])) {
                    error_log("User $username created successfully");
                    return true;
                }

                // Check for errors
                if (isset($response[0]['!trap'])) {
                    $errorMsg = $response[0]['message'] ?? 'Unknown error';
                    throw new Exception("Creation failed: $errorMsg");
                }

                throw new Exception("Failed to create user - unexpected response");
            }

        } catch (Exception $e) {
            error_log("MikroTik user creation error: " . $e->getMessage());
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
            error_log("Failed to connect to MikroTik for user removal");
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
                    error_log("User $username removed successfully");
                }
            }

            if (!$removed) {
                error_log("User $username not found for removal");
            }

            return $removed;

        } catch (Exception $e) {
            error_log("MikroTik user removal error: " . $e->getMessage());
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
            error_log("MikroTik get user error: " . $e->getMessage());
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
                    error_log("Session disconnected for user: $username");
                }
            }

            return $disconnected;

        } catch (Exception $e) {
            error_log("MikroTik disconnect user error: " . $e->getMessage());
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
            
            // Filter out non-session data
            $validSessions = [];
            foreach ($sessions as $session) {
                if (isset($session['user']) && !isset($session['!done'])) {
                    $validSessions[] = $session;
                }
            }
            
            return $validSessions;
        } catch (Exception $e) {
            error_log("Error getting active sessions: " . $e->getMessage());
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
            error_log("Error getting profiles: " . $e->getMessage());
            return [];
        } finally {
            $this->disconnect();
        }
    }

    /**
     * Get all hotspot users with filtering
     */
    public function getAllUsers(array $filters = []): array {
            if (!$this->connect()) {
                return [];
            }

            try {
                $this->write('/ip/hotspot/user/print');

                $users = [];
            while ($response = $this->read(false)) { // read(false) prevents stopping at first '!done'
                    foreach ($response as $user) {
                // Skip the final '!done' message
                if (isset($user['!done'])) continue;

                if (isset($user['name'])) {
                    // Apply optional filters
                    if (!empty($filters)) {
                        $matches = true;
                        foreach ($filters as $key => $value) {
                            if (!isset($user[$key]) || $user[$key] !== $value) {
                                $matches = false;
                                break;
                            }
                        }
                        if ($matches) {
                            $users[] = $user;
                        }
                    } else {
                        $users[] = $user;
                    }
                }
            }

            // Stop if this chunk contains '!done'
            $done = array_filter($response, fn($r) => isset($r['!done']));
            if (!empty($done)) break;
        }

                return $users;
                    } catch (Exception $e) {
                        error_log("Error getting users: " . $e->getMessage());
                return [];
            } finally {
                $this->disconnect();
            }
    }

    /**
     * Test connection to MikroTik with detailed diagnostics
     */
    public function testConnection(): array {
        $startTime = microtime(true);
        
        if (!$this->connect()) {
            $elapsed = round((microtime(true) - $startTime) * 1000, 2);
            return [
                'success' => false,
                'message' => 'Connection failed',
                'router' => 'Unknown',
                'time' => $elapsed . 'ms',
                'host' => $this->host,
                'port' => $this->port
            ];
        }

        try {
            $this->write('/system/identity/print');
            $identity = $this->read();
            
            $routerName = $identity[0]['name'] ?? 'Unknown';
            $elapsed = round((microtime(true) - $startTime) * 1000, 2);
            
            return [
                'success' => true,
                'message' => 'Connected successfully',
                'router' => $routerName,
                'time' => $elapsed . 'ms',
                'host' => $this->host,
                'port' => $this->port
            ];
        } catch (Exception $e) {
            $elapsed = round((microtime(true) - $startTime) * 1000, 2);
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'router' => 'Unknown',
                'time' => $elapsed . 'ms',
                'host' => $this->host,
                'port' => $this->port
            ];
        } finally {
            $this->disconnect();
        }
    }

    /**
     * Get system resource information
     */
    public function getSystemResources(): ?array {
        if (!$this->connect()) {
            return null;
        }

        try {
            $this->write('/system/resource/print');
            $resources = $this->read();
            return $resources[0] ?? null;
        } catch (Exception $e) {
            error_log("Error getting system resources: " . $e->getMessage());
            return null;
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