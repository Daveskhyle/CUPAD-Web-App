<?php
/**
 * User Lockout Helper Functions
 * Provides comprehensive user lockout management functionality
 */

class UserLockoutManager {
    private $users_file;
    private $max_attempts;
    private $lockout_duration;
    private $attempt_window;
    
    public function __construct($users_file = 'users.json', $max_attempts = 5, $lockout_duration = 300, $attempt_window = 900) {
        $this->users_file = $users_file;
        $this->max_attempts = $max_attempts;
        $this->lockout_duration = $lockout_duration; // 5 minutes default
        $this->attempt_window = $attempt_window; // 15 minutes default
    }
    
    /**
     * Check if user is currently locked out
     */
    public function isUserLockedOut($username) {
        $users = $this->loadUsers();
        $user = $this->findUser($users, $username);
        
        if (!$user) {
            return false;
        }
        
        // Check if lockout is still active
        if (isset($user['lockout_until']) && $user['lockout_until'] > time()) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Record failed login attempt
     */
    public function recordFailedAttempt($username) {
        $users = $this->loadUsers();
        $user_key = $this->findUserKey($users, $username);
        
        if ($user_key === null) {
            return false;
        }
        
        $current_time = time();
        
        // Initialize failed attempts array if not exists
        if (!isset($users[$user_key]['failed_login_attempts'])) {
            $users[$user_key]['failed_login_attempts'] = [];
        }
        
        // Add current failed attempt
        $users[$user_key]['failed_login_attempts'][] = $current_time;
        
        // Clean old attempts outside the window
        $users[$user_key]['failed_login_attempts'] = array_filter(
            $users[$user_key]['failed_login_attempts'],
            function($timestamp) use ($current_time) {
                return ($current_time - $timestamp) < $this->attempt_window;
            }
        );
        
        // Check if lockout threshold reached
        $attempt_count = count($users[$user_key]['failed_login_attempts']);
        if ($attempt_count >= $this->max_attempts) {
            $users[$user_key]['lockout_until'] = $current_time + $this->lockout_duration;
        }
        
        $this->saveUsers($users);
        
        return [
            'attempts_count' => $attempt_count,
            'attempts_remaining' => max(0, $this->max_attempts - $attempt_count),
            'locked_out' => $attempt_count >= $this->max_attempts,
            'lockout_until' => $users[$user_key]['lockout_until'] ?? null
        ];
    }
    
    /**
     * Clear failed attempts on successful login
     */
    public function clearFailedAttempts($username) {
        $users = $this->loadUsers();
        $user_key = $this->findUserKey($users, $username);
        
        if ($user_key !== null) {
            $users[$user_key]['failed_login_attempts'] = [];
            $users[$user_key]['lockout_until'] = null;
            $this->saveUsers($users);
            return true;
        }
        
        return false;
    }
    
    /**
     * Get user lockout status with detailed information
     */
    public function getUserLockoutStatus($username) {
        $users = $this->loadUsers();
        $user = $this->findUser($users, $username);
        
        if (!$user) {
            return ['user_exists' => false];
        }
        
        $current_time = time();
        $failed_attempts = $user['failed_login_attempts'] ?? [];
        
        // Filter recent attempts
        $recent_attempts = array_filter($failed_attempts, function($timestamp) use ($current_time) {
            return ($current_time - $timestamp) < $this->attempt_window;
        });
        
        $is_locked = isset($user['lockout_until']) && $user['lockout_until'] > $current_time;
        
        return [
            'user_exists' => true,
            'username' => $username,
            'is_locked' => $is_locked,
            'lockout_until' => $user['lockout_until'] ?? null,
            'remaining_lockout_time' => $is_locked ? ($user['lockout_until'] - $current_time) : 0,
            'remaining_lockout_minutes' => $is_locked ? ceil(($user['lockout_until'] - $current_time) / 60) : 0,
            'failed_attempts_count' => count($recent_attempts),
            'attempts_remaining' => max(0, $this->max_attempts - count($recent_attempts)),
            'last_failed_attempt' => !empty($recent_attempts) ? max($recent_attempts) : null,
            'can_attempt_login' => !$is_locked
        ];
    }
    
    /**
     * Manually unlock a user (admin function)
     */
    public function unlockUser($username) {
        $users = $this->loadUsers();
        $user_key = $this->findUserKey($users, $username);
        
        if ($user_key !== null) {
            $users[$user_key]['failed_login_attempts'] = [];
            $users[$user_key]['lockout_until'] = null;
            $this->saveUsers($users);
            return true;
        }
        
        return false;
    }
    
    /**
     * Manually lock a user (admin function)
     */
    public function lockUser($username, $lockout_until = null) {
        $users = $this->loadUsers();
        $user_key = $this->findUserKey($users, $username);
        
        if ($user_key !== null) {
            $users[$user_key]['lockout_until'] = $lockout_until ?? (time() + $this->lockout_duration);
            $this->saveUsers($users);
            return true;
        }
        
        return false;
    }
    
    /**
     * Get all locked users
     */
    public function getLockedUsers() {
        $users = $this->loadUsers();
        $locked_users = [];
        $current_time = time();
        
        foreach ($users as $user) {
            if (isset($user['lockout_until']) && $user['lockout_until'] > $current_time) {
                $locked_users[] = [
                    'username' => $user['username'],
                    'name' => $user['name'] ?? $user['username'],
                    'lockout_until' => $user['lockout_until'],
                    'remaining_minutes' => ceil(($user['lockout_until'] - $current_time) / 60),
                    'failed_attempts' => count($user['failed_login_attempts'] ?? [])
                ];
            }
        }
        
        return $locked_users;
    }
    
    /**
     * Clean expired lockouts
     */
    public function cleanExpiredLockouts() {
        $users = $this->loadUsers();
        $current_time = time();
        $cleaned = 0;
        
        foreach ($users as $key => $user) {
            if (isset($user['lockout_until']) && $user['lockout_until'] <= $current_time) {
                $users[$key]['lockout_until'] = null;
                $users[$key]['failed_login_attempts'] = [];
                $cleaned++;
            }
        }
        
        if ($cleaned > 0) {
            $this->saveUsers($users);
        }
        
        return $cleaned;
    }
    
    // Private helper methods
    private function loadUsers() {
        if (!file_exists($this->users_file)) {
            throw new Exception("Users file not found: {$this->users_file}");
        }
        
        $json = file_get_contents($this->users_file);
        $users = json_decode($json, true);
        
        return is_array($users) ? $users : [];
    }
    
    private function saveUsers($users) {
        return file_put_contents($this->users_file, json_encode($users, JSON_PRETTY_PRINT));
    }
    
    private function findUser($users, $username) {
        foreach ($users as $user) {
            if (isset($user['username']) && $user['username'] === $username) {
                return $user;
            }
        }
        return null;
    }
    
    private function findUserKey($users, $username) {
        foreach ($users as $key => $user) {
            if (isset($user['username']) && $user['username'] === $username) {
                return $key;
            }
        }
        return null;
    }
}

// Convenience functions for quick access
function checkUserLockout($username, $users_file = 'users.json') {
    $lockout_manager = new UserLockoutManager($users_file);
    return $lockout_manager->isUserLockedOut($username);
}

function getUserLockoutInfo($username, $users_file = 'users.json') {
    $lockout_manager = new UserLockoutManager($users_file);
    return $lockout_manager->getUserLockoutStatus($username);
}

?>