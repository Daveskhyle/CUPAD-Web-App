<?php
/**
 * Google OAuth Configuration
 * Get credentials from: https://console.cloud.google.com/
 */

// Google OAuth Settings
define('GOOGLE_CLIENT_ID', '717939360813-ld4059e94rj968r0u45t22ndbensvh09.apps.googleusercontent.com');
define('GOOGLE_CLIENT_SECRET', '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');

// Auto-detect redirect URI based on environment
$is_local = in_array($_SERVER['HTTP_HOST'], ['localhost', '127.0.0.1', 'localhost:80']);
define('GOOGLE_REDIRECT_URI', $is_local ? 'http://localhost/cupad/google_callback' : 'https://cupad.name.ng/google_callback');

// OAuth URLs
define('GOOGLE_AUTH_URL', 'https://accounts.google.com/o/oauth2/v2/auth');
define('GOOGLE_TOKEN_URL', 'https://oauth2.googleapis.com/token');
define('GOOGLE_USERINFO_URL', 'https://www.googleapis.com/oauth2/v2/userinfo');

// Scopes
define('GOOGLE_SCOPES', 'openid email profile');
?>
