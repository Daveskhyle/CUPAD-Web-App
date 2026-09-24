<?php
session_start();
header('Content-Type: application/json');

// --- 1. Database Connection ---
define('DB_HOST', 'localhost');
define('DB_NAME', 'cupadnam_db');
define('DB_USER', 'cupadnam_db');
define('DB_PASS', 'f2GrjZQCz8E39nCu9eLg');
define('DB_CHARSET', 'utf8mb4');

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database Connection Failed: ' . $e->getMessage()]);
    exit();
}

// --- 2. Authorization Check ---
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized access']);
    exit();
}

// --- 3. Input Handling ---
$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['username']) || empty($input['username'])) {
    echo json_encode(['success' => false, 'error' => 'Username is required']);
    exit();
}

$username = trim($input['username']);

try {
    // --- 4. Logic: Clear Locations for User ---
    // Instead of deleting records from a JSON file, we update the user's record
    // in the 'users' table to set zone_id, area_id, and branch_id to NULL.
    
    // First check if the user exists and actually has locations assigned
    $checkStmt = $pdo->prepare("SELECT id FROM users WHERE username = ? AND (zone_id IS NOT NULL OR area_id IS NOT NULL OR branch_id IS NOT NULL)");
    $checkStmt->execute([$username]);
    
    if ($checkStmt->rowCount() === 0) {
        // User not found or has no locations to clear
        // You might want to check if the user exists at all to give a better error message,
        // but for now, matching the logic "No locations found for this user".
        echo json_encode(['success' => false, 'error' => 'No locations found for this user or user does not exist']);
        exit();
    }

    // Perform the update
    $updateStmt = $pdo->prepare("UPDATE users SET zone_id = NULL, area_id = NULL, branch_id = NULL WHERE username = ?");
    $updateStmt->execute([$username]);

    if ($updateStmt->rowCount() > 0) {
        // Log this action (Optional based on schema 'activity_log' table)
        $logStmt = $pdo->prepare("INSERT INTO activity_log (user, action, details, ip_address) VALUES (?, 'delete_user_location', ?, ?)");
        $logStmt->execute([
            $_SESSION['username'] ?? 'system', 
            "Cleared location assignments for user: $username", 
            $_SERVER['REMOTE_ADDR']
        ]);

        echo json_encode([
            'success' => true, 
            'message' => "Successfully deleted location record(s) for user: {$username}"
        ]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to update user location']);
    }

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Database Error: ' . $e->getMessage()]);
}
?>