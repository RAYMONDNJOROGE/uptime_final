<?php
/**
 * MikroTik RouterOS API Class
 * Updated using working logic from MikroTikManual
 */

require_once 'config.php';

class MikrotikAPI {
    private string $host;
    private string $user;
    private string $pass;
    private int $port;
    private $socket = null;
    private bool $connected = false;
    private array $plans = [];

    public function __construct() {
        $this->host = MIKROTIK_HOST;
        $this->user = MIKROTIK_USER;
        $this->pass = MIKROTIK_PASS;
        $this->port = MIKROTIK_PORT;
    }

    public function connect(): bool {
        $this->socket = @fsockopen($this->host, $this->port, $errno, $errstr, 5);
        if (!$this->socket) {
            error_log("❌ Socket error: $errstr ($errno)");
            return false;
        }

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
            error_log("✅ Connected to MikroTik at {$this->host}");
            return true;
        }

        error_log("❌ Login failed: " . print_r($response, true));
        return false;
    }

    public function disconnect(): void {
        if ($this->socket) {
            fclose($this->socket);
            $this->socket = null;
            $this->connected = false;
            error_log("🔌 Disconnected from MikroTik");
        }
    }

    private function write(string $command, bool $end = true): void {
        fwrite($this->socket, chr(strlen($command)) . $command);
        if ($end) fwrite($this->socket, chr(0));
    }

    private function read(): array {
        $response = [];
        while (true) {
            $length = ord(fread($this->socket, 1));
            if ($length === 0) break;
            $word = fread($this->socket, $length);
            $parts = explode('=', $word, 2);
            if (count($parts) === 2) {
                $response[] = [$parts[0] => $parts[1]];
            } else {
                $response[] = [$word => true];
            }
        }
        return $response;
    }

    public function createPPPoEUser(string $username, string $password, string $profile = 'default'): bool {
        $this->write('/ppp/secret/add', false);
        $this->write('=name=' . $username, false);
        $this->write('=password=' . $password, false);
        $this->write('=service=pppoe', false);
        $this->write('=profile=' . $profile);
        $response = $this->read();
        return isset($response[0]['!done']);
    }

    public function getActiveHotspotSessions(): array {
        $this->write('/ip/hotspot/active/print');
        return $this->read();
    }

    public function getActivePPPoESessions(): array {
        $this->write('/ppp/active/print');
        return $this->read();
    }

    public function getHotspotProfiles(): array {
        $this->write('/ip/hotspot/user/profile/print');
        return $this->read();
    }

    public function getHotspotPlans(): array {
        $this->write('/ip/hotspot/user/profile/print');
        $profiles = $this->read();

            if (empty($profiles)) {
                    error_log("⚠️ No hotspot profiles returned.");
                return [];
            }

            $plans = [];
            foreach ($profiles as $profile) {
                if (!isset($profile['name'])) continue;

                $plans[$profile['name']] = [
                    'name' => $profile['name'],
                    'uptime_limit' => $profile['session-timeout'] ?? '1h',
                    'price' => $profile['rate-limit'] ?? 'N/A',
                    'description' => 'Shared users: ' . ($profile['shared-users'] ?? '1'),
                    'profile' => $profile['name']
                ];
        }

        return $plans;
    }
    public function setPlans(array $plans): void {
        $this->plans = $plans;
    }
    public function getPPPoEPlans(): array {
        $this->write('/ppp/profile/print');
            $profiles = $this->read();

            $plans = [];
        foreach ($profiles as $profile) {
            if (!isset($profile['name'])) continue;

            $plans[$profile['name']] = [
                'name' => $profile['name'],
                'local_address' => $profile['local-address'] ?? '',
                'remote_address' => $profile['remote-address'] ?? '',
                'rate_limit' => $profile['rate-limit'] ?? '',
                'description' => 'Rate: ' . ($profile['rate-limit'] ?? 'N/A'),
                'profile' => $profile['name']
            ];
        }

        return $plans;
    }
    /**
     * Create hotspot user in MikroTik
     */
    public function createUser($username, $password, $plan) {
        if (!$this->connect()) {
            error_log("Failed to connect to MikroTik for user creation");
            return false;
        }
        
        try {
            // Validate plan
            if (!isset($this->plans[$plan])) {
                throw new Exception('Invalid plan: ' . $plan);
            }
            
            $planConfig = $this->plans[$plan];
            
            // Check if user already exists
            $this->write('/ip/hotspot/user/print', false);
            $this->write('?name=' . $username);
            $existing = $this->read();
            
            if (!isset($existing[0]['!done'])) {
                // User exists, update it
                error_log("User $username exists, updating...");
                foreach ($existing as $user) {
                    if (isset($user['.id'])) {
                        $this->write('/ip/hotspot/user/set', false);
                        $this->write('=.id=' . $user['.id'], false);
                        $this->write('=password=' . $password, false);
                        $this->write('=limit-uptime=' . $planConfig['uptime_limit'], false);
                        $this->write('=profile=' . $planConfig['profile']);
                        $result = $this->read();
                        
                        if (isset($result[0]['!done'])) {
                            error_log("User $username updated successfully");
                            $this->disconnect();
                            return true;
                        }
                        break;
                    }
                }
            } else {
                // Create new user
                error_log("Creating new user: $username");
                $this->write('/ip/hotspot/user/add', false);
                $this->write('=name=' . $username, false);
                $this->write('=password=' . $password, false);
                $this->write('=limit-uptime=' . $planConfig['uptime_limit'], false);
                $this->write('=profile=' . $planConfig['profile']);
                $response = $this->read();
                
                if (isset($response[0]['!done'])) {
                    error_log("User $username created successfully");
                    $this->disconnect();
                    return true;
                } else {
                    error_log("Failed to create user. Response: " . print_r($response, true));
                    throw new Exception('Failed to create user in MikroTik');
                }
            }
            
            $this->disconnect();
            return false;
            
        } catch (Exception $e) {
            error_log('MikroTik user creation error: ' . $e->getMessage());
            $this->disconnect();
            return false;
        }
    }
    
    /**
     * Remove user from MikroTik
     */
    public function removeUser($username) {
        if (!$this->connect()) {
            return false;
        }
        
        try {
            $this->write('/ip/hotspot/user/print', false);
            $this->write('?name=' . $username);
            $users = $this->read();
            
            foreach ($users as $user) {
                if (isset($user['.id'])) {
                    $this->write('/ip/hotspot/user/remove', false);
                    $this->write('=.id=' . $user['.id']);
                    $this->read();
                }
            }
            
            $this->disconnect();
            return true;
            
        } catch (Exception $e) {
            error_log('MikroTik user removal error: ' . $e->getMessage());
            $this->disconnect();
            return false;
        }
    }
    
    /**
     * Get user info
     */
    public function getUserInfo($username) {
        if (!$this->connect()) {
            return false;
        }
        
        try {
            $this->write('/ip/hotspot/user/print', false);
            $this->write('?name=' . $username);
            $response = $this->read();
            
            $this->disconnect();
            
            if (isset($response[0]) && !isset($response[0]['!done'])) {
                return $response[0];
            }
            
            return false;
            
        } catch (Exception $e) {
            error_log('MikroTik get user error: ' . $e->getMessage());
            $this->disconnect();
            return false;
        }
    }
    
    /**
     * Disconnect active user session
     */
    public function disconnectUser($username) {
        if (!$this->connect()) {
            return false;
        }
        
        try {
            $this->write('/ip/hotspot/active/print', false);
            $this->write('?user=' . $username);
            $active = $this->read();
            
            foreach ($active as $session) {
                if (isset($session['.id'])) {
                    $this->write('/ip/hotspot/active/remove', false);
                    $this->write('=.id=' . $session['.id']);
                    $this->read();
                }
            }
            
            $this->disconnect();
            return true;
            
        } catch (Exception $e) {
            error_log('MikroTik disconnect user error: ' . $e->getMessage());
            $this->disconnect();
            return false;
        }
    }
}
?>