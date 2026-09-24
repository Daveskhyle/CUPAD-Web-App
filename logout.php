<?php
/**
 * Logout - SQL Based Version
 */
session_start();

// Clear remember me cookie if exists
if (isset($_COOKIE['remember_token'])) {
    // Include database to clear token
    require_once 'includes/db_connect.php';

    if (isset($_SESSION['user_id'])) {
        try {
            $stmt = $pdo->prepare("UPDATE users SET remember_token = NULL WHERE id = ?");
            $stmt->execute([$_SESSION['user_id']]);
        } catch (PDOException $e) {
            // Silent fail
        }
    }

    // Clear cookie
    setcookie('remember_token', '', time() - 3600, '/');
}

// Destroy session
$_SESSION = array();

if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

session_destroy();

// Redirect to login
header("Location: index.php");
exit;
