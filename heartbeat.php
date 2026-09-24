<?php
/**
 * Heartbeat - Keep user online status updated
 * Call this endpoint every 2-3 minutes from active pages
 */
session_start();
require_once __DIR__ . '/includes/config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

try {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare("UPDATE users SET last_activity = NOW(), is_online = 1 WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    error_log("Heartbeat error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Update failed']);
}
