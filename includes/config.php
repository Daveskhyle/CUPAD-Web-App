<?php
/**
 * CUPAD Configuration File
 * Defines database connection and application constants
 */

// =====================================================
// Database Configuration
// =====================================================
define('DB_HOST', 'localhost');
define('DB_NAME', 'cupadnam_db');
define('DB_USER', 'cupadnam_db');
define('DB_PASS', 'f2GrjZQCz8E39nCu9eLg');
define('DB_CHARSET', 'utf8mb4');

// =====================================================
// Storage Configuration
// =====================================================
define('USE_SQL_STORAGE', true);
define('SQL_MIGRATION_COMPLETE', true);

// =====================================================
// Application Settings
// =====================================================
define('APP_NAME', 'CUPAD');
define('APP_VERSION', '2.0.0');
date_default_timezone_set('Africa/Lagos');

// =====================================================
// File Paths (for JSON fallback)
// =====================================================
define('BASE_PATH', dirname(__DIR__));
define('DATA_PATH', BASE_PATH . '/data');
define('ADMIN_DATA_PATH', BASE_PATH . '/admin/data');
define('UPLOADS_PATH', BASE_PATH . '/uploads');

// =====================================================
// Helper Functions
// =====================================================

function getDbConnection() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ]);
            $pdo->exec("SET NAMES " . DB_CHARSET);
        } catch (PDOException $e) {
            error_log("Database connection failed: " . $e->getMessage());
            throw new Exception("Database connection failed");
        }
    }
    return $pdo;
}

function get_setting($key, $default = null) {
    if (USE_SQL_STORAGE && SQL_MIGRATION_COMPLETE) {
        try {
            $pdo = getDbConnection();
            $stmt = $pdo->prepare("SELECT value, type FROM settings WHERE `key` = ?");
            $stmt->execute([$key]);
            $result = $stmt->fetch();
            if ($result) {
                switch ($result['type']) {
                    case 'integer': return (int)$result['value'];
                    case 'boolean': return $result['value'] === 'true' || $result['value'] === true || $result['value'] == 1;
                    case 'json':
                    case 'array': return json_decode($result['value'], true);
                    default: return $result['value'];
                }
            }
        } catch (Exception $e) {}
    }
    $settingsFile = DATA_PATH . '/settings.json';
    if (file_exists($settingsFile)) {
        $settings = json_decode(file_get_contents($settingsFile), true) ?? [];
        return $settings[$key] ?? $default;
    }
    return $default;
}

function set_setting($key, $value, $group = 'general', $type = 'string') {
    if (USE_SQL_STORAGE && SQL_MIGRATION_COMPLETE) {
        try {
            $pdo = getDbConnection();
            if (is_array($value) || is_object($value)) {
                $value = json_encode($value);
                $type = 'json';
            }
            $stmt = $pdo->prepare("INSERT INTO settings (`key`, `value`, `group`, `type`) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), `type` = VALUES(`type`)");
            $stmt->execute([$key, $value, $group, $type]);
            return true;
        } catch (Exception $e) {
            error_log("Failed to save setting: " . $e->getMessage());
            return false;
        }
    }
    $settingsFile = DATA_PATH . '/settings.json';
    $settings = file_exists($settingsFile) ? json_decode(file_get_contents($settingsFile), true) ?? [] : [];
    $settings[$key] = $value;
    return file_put_contents($settingsFile, json_encode($settings, JSON_PRETTY_PRINT)) !== false;
}

// =====================================================
// Business Rules (from settings)
// =====================================================
define('MIN_LOAN_AMOUNT', get_setting('min_loan_amount', 5000));
define('MAX_LOAN_AMOUNT', get_setting('max_loan_amount', 500000));
define('REQUIRED_SAVINGS_PERCENTAGE', get_setting('required_savings_percentage', 0.10));
define('DEFAULT_INTEREST_RATE', get_setting('interest_rate_default', 10));
define('CLIENT_REGISTRATION_FEE', get_setting('client_registration_fee', 1000));
define('WEEKLY_REGISTRATION_FEE', get_setting('weekly_registration_fee', 2000));
define('MONTHLY_REGISTRATION_FEE', get_setting('monthly_registration_fee', 2500));
define('NUM_INSTALLMENTS', get_setting('num_installments', 12));
define('PENALTY_RATE_DAILY', get_setting('penalty_rate_daily', 0.5));
