<?php
/**
 * Deleted Users Manager
 * Handles saving and retrieving deleted user data
 */

class DeletedUsersManager {
    private $deletedUsersFile;
    private $deletedSavingsFile;
    
    public function __construct() {
        $this->deletedUsersFile = __DIR__ . '/../data/deleted_users.json';
        $this->deletedSavingsFile = __DIR__ . '/../data/deleted_savings.json';
        
        // Ensure files exist
        $this->initializeFiles();
    }
    
    private function initializeFiles() {
        if (!file_exists($this->deletedUsersFile)) {
            file_put_contents($this->deletedUsersFile, json_encode([], JSON_PRETTY_PRINT));
        }
        if (!file_exists($this->deletedSavingsFile)) {
            file_put_contents($this->deletedSavingsFile, json_encode([], JSON_PRETTY_PRINT));
        }
    }
    
    /**
     * Save deleted user data
     */
    public function saveDeletedUser($userData, $userSavings = []) {
        try {
            // Add deletion metadata
            $deletedUser = $userData;
            $deletedUser['deleted_at'] = date('Y-m-d H:i:s');
            $deletedUser['deleted_by'] = $_SESSION['username'] ?? 'system';
            $deletedUser['original_id'] = $userData['id'];
            $deletedUser['deletion_id'] = 'DEL_' . time() . '_' . uniqid();
            
            // Load existing deleted users
            $deletedUsers = $this->getDeletedUsers();
            $deletedUsers[] = $deletedUser;
            
            // Save updated deleted users
            $result = file_put_contents($this->deletedUsersFile, json_encode($deletedUsers, JSON_PRETTY_PRINT));
            
            // Save associated savings if any
            if (!empty($userSavings)) {
                $this->saveDeletedSavings($userData['id'], $userSavings, $deletedUser['deletion_id']);
            }
            
            return $result !== false;
        } catch (Exception $e) {
            error_log("Error saving deleted user: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Save deleted user's savings data
     */
    private function saveDeletedSavings($clientId, $savings, $deletionId) {
        try {
            $deletedSavingsData = [
                'deletion_id' => $deletionId,
                'original_client_id' => $clientId,
                'deleted_at' => date('Y-m-d H:i:s'),
                'savings_data' => $savings
            ];
            
            $deletedSavings = $this->getDeletedSavings();
            $deletedSavings[] = $deletedSavingsData;
            
            return file_put_contents($this->deletedSavingsFile, json_encode($deletedSavings, JSON_PRETTY_PRINT)) !== false;
        } catch (Exception $e) {
            error_log("Error saving deleted savings: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get all deleted users
     */
    public function getDeletedUsers() {
        try {
            $content = file_get_contents($this->deletedUsersFile);
            return json_decode($content, true) ?: [];
        } catch (Exception $e) {
            error_log("Error reading deleted users: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get deleted savings
     */
    public function getDeletedSavings() {
        try {
            $content = file_get_contents($this->deletedSavingsFile);
            return json_decode($content, true) ?: [];
        } catch (Exception $e) {
            error_log("Error reading deleted savings: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Search deleted users by various criteria
     */
    public function searchDeletedUsers($searchTerm = '', $dateFrom = '', $dateTo = '') {
        $deletedUsers = $this->getDeletedUsers();
        
        if (empty($searchTerm) && empty($dateFrom) && empty($dateTo)) {
            return $deletedUsers;
        }
        
        return array_filter($deletedUsers, function($user) use ($searchTerm, $dateFrom, $dateTo) {
            $matchesSearch = empty($searchTerm) || 
                stripos($user['name'], $searchTerm) !== false ||
                stripos($user['phone'], $searchTerm) !== false ||
                stripos($user['union'], $searchTerm) !== false ||
                stripos($user['officer_name'], $searchTerm) !== false;
            
            $matchesDate = true;
            if (!empty($dateFrom) || !empty($dateTo)) {
                $deletedDate = date('Y-m-d', strtotime($user['deleted_at']));
                
                if (!empty($dateFrom)) {
                    $matchesDate = $matchesDate && ($deletedDate >= $dateFrom);
                }
                if (!empty($dateTo)) {
                    $matchesDate = $matchesDate && ($deletedDate <= $dateTo);
                }
            }
            
            return $matchesSearch && $matchesDate;
        });
    }
    
    /**
     * Get deleted user's savings by deletion ID
     */
    public function getDeletedUserSavings($deletionId) {
        $deletedSavings = $this->getDeletedSavings();
        
        foreach ($deletedSavings as $savings) {
            if ($savings['deletion_id'] === $deletionId) {
                return $savings['savings_data'];
            }
        }
        
        return [];
    }
    
    /**
     * Restore deleted user (move back to active users)
     */
    public function restoreUser($deletionId) {
        try {
            $deletedUsers = $this->getDeletedUsers();
            $userToRestore = null;
            $remainingUsers = [];
            
            // Find and remove user from deleted list
            foreach ($deletedUsers as $user) {
                if ($user['deletion_id'] === $deletionId) {
                    $userToRestore = $user;
                } else {
                    $remainingUsers[] = $user;
                }
            }
            
            if (!$userToRestore) {
                return false;
            }
            
            // Remove deletion metadata
            unset($userToRestore['deleted_at']);
            unset($userToRestore['deleted_by']);
            unset($userToRestore['deletion_id']);
            $userToRestore['id'] = $userToRestore['original_id'];
            unset($userToRestore['original_id']);
            
            // Add back to active users
            $clientsFile = __DIR__ . '/../data/clients.json';
            $clients = json_decode(file_get_contents($clientsFile), true) ?: [];
            $clients[] = $userToRestore;
            
            // Save both files
            $clientsSaved = file_put_contents($clientsFile, json_encode($clients, JSON_PRETTY_PRINT)) !== false;
            $deletedSaved = file_put_contents($this->deletedUsersFile, json_encode($remainingUsers, JSON_PRETTY_PRINT)) !== false;
            
            // Restore savings if any
            $this->restoreUserSavings($deletionId);
            
            return $clientsSaved && $deletedSaved;
        } catch (Exception $e) {
            error_log("Error restoring user: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Restore deleted user's savings
     */
    private function restoreUserSavings($deletionId) {
        try {
            $deletedSavings = $this->getDeletedSavings();
            $savingsToRestore = null;
            $remainingSavings = [];
            
            foreach ($deletedSavings as $savings) {
                if ($savings['deletion_id'] === $deletionId) {
                    $savingsToRestore = $savings['savings_data'];
                } else {
                    $remainingSavings[] = $savings;
                }
            }
            
            if ($savingsToRestore) {
                $savingsFile = __DIR__ . '/../data/savings.json';
                $currentSavings = json_decode(file_get_contents($savingsFile), true) ?: [];
                $currentSavings = array_merge($currentSavings, $savingsToRestore);
                
                file_put_contents($savingsFile, json_encode($currentSavings, JSON_PRETTY_PRINT));
                file_put_contents($this->deletedSavingsFile, json_encode($remainingSavings, JSON_PRETTY_PRINT));
            }
            
            return true;
        } catch (Exception $e) {
            error_log("Error restoring savings: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Permanently delete a user record (cannot be restored)
     */
    public function permanentlyDelete($deletionId) {
        try {
            $deletedUsers = $this->getDeletedUsers();
            $deletedSavings = $this->getDeletedSavings();
            
            // Remove from deleted users
            $remainingUsers = array_filter($deletedUsers, function($user) use ($deletionId) {
                return $user['deletion_id'] !== $deletionId;
            });
            
            // Remove from deleted savings
            $remainingSavings = array_filter($deletedSavings, function($savings) use ($deletionId) {
                return $savings['deletion_id'] !== $deletionId;
            });
            
            $usersSaved = file_put_contents($this->deletedUsersFile, json_encode(array_values($remainingUsers), JSON_PRETTY_PRINT)) !== false;
            $savingsSaved = file_put_contents($this->deletedSavingsFile, json_encode(array_values($remainingSavings), JSON_PRETTY_PRINT)) !== false;
            
            return $usersSaved && $savingsSaved;
        } catch (Exception $e) {
            error_log("Error permanently deleting user: " . $e->getMessage());
            return false;
        }
    }
}
?>