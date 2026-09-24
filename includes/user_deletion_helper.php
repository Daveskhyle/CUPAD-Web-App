<?php
require_once __DIR__ . '/deleted_users_manager.php';

/**
 * Helper functions for user deletion process
 */

/**
 * Delete user and save their data to deleted_users.json
 */
function deleteUserWithBackup($clientId) {
    try {
        // Load current data
        $clientsFile = __DIR__ . '/../data/clients.json';
        $savingsFile = __DIR__ . '/../data/savings.json';
        
        $clients = json_decode(file_get_contents($clientsFile), true) ?: [];
        $savings = json_decode(file_get_contents($savingsFile), true) ?: [];
        
        // Find user to delete
        $userToDelete = null;
        $remainingClients = [];
        
        foreach ($clients as $client) {
            if ($client['id'] === $clientId) {
                $userToDelete = $client;
            } else {
                $remainingClients[] = $client;
            }
        }
        
        if (!$userToDelete) {
            return ['success' => false, 'message' => 'User not found'];
        }
        
        // Find user's savings
        $userSavings = [];
        $remainingSavings = [];
        
        foreach ($savings as $saving) {
            if ($saving['client'] === $userToDelete['name']) {
                $userSavings[] = $saving;
            } else {
                $remainingSavings[] = $saving;
            }
        }
        
        // Save to deleted users
        $deletedManager = new DeletedUsersManager();
        $saved = $deletedManager->saveDeletedUser($userToDelete, $userSavings);
        
        if ($saved) {
            // Remove from active data
            $clientsSaved = file_put_contents($clientsFile, json_encode($remainingClients, JSON_PRETTY_PRINT)) !== false;
            $savingsSaved = file_put_contents($savingsFile, json_encode($remainingSavings, JSON_PRETTY_PRINT)) !== false;
            
            if ($clientsSaved && $savingsSaved) {
                return [
                    'success' => true, 
                    'message' => 'User deleted successfully and backed up',
                    'savings_count' => count($userSavings)
                ];
            } else {
                return ['success' => false, 'message' => 'Failed to update active data files'];
            }
        } else {
            return ['success' => false, 'message' => 'Failed to backup user data'];
        }
        
    } catch (Exception $e) {
        error_log("Error in deleteUserWithBackup: " . $e->getMessage());
        return ['success' => false, 'message' => 'System error occurred'];
    }
}

/**
 * Get user's total savings before deletion
 */
function getUserSavingsTotal($clientName) {
    try {
        $savingsFile = __DIR__ . '/../data/savings.json';
        $savings = json_decode(file_get_contents($savingsFile), true) ?: [];
        
        $total = 0;
        foreach ($savings as $saving) {
            if ($saving['client'] === $clientName) {
                $total += $saving['amount'];
            }
        }
        
        return $total;
    } catch (Exception $e) {
        return 0;
    }
}

/**
 * Check if user has any savings before deletion
 */
function userHasSavings($clientName) {
    try {
        $savingsFile = __DIR__ . '/../data/savings.json';
        $savings = json_decode(file_get_contents($savingsFile), true) ?: [];
        
        foreach ($savings as $saving) {
            if ($saving['client'] === $clientName) {
                return true;
            }
        }
        
        return false;
    } catch (Exception $e) {
        return false;
    }
}
?>