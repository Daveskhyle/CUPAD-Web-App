<?php
/**
 * CUPAD Configuration File
 * Defines database connection and application constants
 */



// =====================================================
// Application Settings
// =====================================================
define('APP_NAME', 'CUPAD');
define('APP_VERSION', '2.0.0');

// Timezone
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

/**
 * Get setting value from JSON
 */
function get_setting($key, $default = null) {
    $settingsFile = DATA_PATH . '/settings.json';
    if (file_exists($settingsFile)) {
        $settings = json_decode(file_get_contents($settingsFile), true) ?? [];
        return $settings[$key] ?? $default;
    }
    return $default;
}

/**
 * Set setting value
 */
function set_setting($key, $value) {
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
