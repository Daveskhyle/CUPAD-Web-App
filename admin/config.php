<?php
// =================================================================
// SYSTEM CONFIGURATION & CONSTANTS
// =================================================================

// 1. Directory Paths
define('ROOT_PATH', __DIR__ . '/../');
define('UPLOADS_PATH', ROOT_PATH . 'uploads/');
define('ADMIN_PATH', __DIR__ . '/');
define('INCLUDES_PATH', __DIR__ . '/includes/');

// 2. Database Configuration (PDO)
// Credentials updated as requested
define('DB_HOST', 'localhost');
define('DB_NAME', 'cupadnam_db');
define('DB_USER', 'cupadnam_db');
define('DB_PASS', 'f2GrjZQCz8E39nCu9eLg');
define('DB_CHARSET', 'utf8mb4');

// 3. Application Settings (Fallbacks if DB fails)
define('DEFAULT_TIMEZONE', 'Africa/Lagos');
define('APP_NAME', 'CUPAD');

// 4. Google API Credentials (OAuth)
define('GOOGLE_CLIENT_ID', '469325847421-8gqtvd8hmgp27ufb1tp2uqs0ip2k8gnk.apps.googleusercontent.com');
define('GOOGLE_CLIENT_SECRET', 'GOCSPXurbEMuG5oel0VHW1Tl5KLC7bZPPV');
define('GOOGLE_REDIRECT_URI', 'https://cupad.name.ng/google_auth.php');

// 5. Global Database Connection Function
// Usage: $pdo = getDBConnection();
function getDBConnection() {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (\PDOException $e) {
            // Log error or show user-friendly message
            error_log("Database Connection Error: " . $e->getMessage());
            die("System Error: Unable to connect to the database. Please check configuration.");
        }
    }
    return $pdo;
}

// Set default timezone
date_default_timezone_set(DEFAULT_TIMEZONE);
?>