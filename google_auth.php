<?php
require_once 'session_config.php';
require_once 'google_config.php';

$state = bin2hex(random_bytes(16));
$_SESSION['google_oauth_state'] = $state;
setcookie('google_oauth_state', $state, time() + 600, '/', '', true, false);

// Build authorization URL
$params = [
    'client_id' => GOOGLE_CLIENT_ID,
    'redirect_uri' => GOOGLE_REDIRECT_URI,
    'response_type' => 'code',
    'scope' => GOOGLE_SCOPES,
    'state' => $_SESSION['google_oauth_state'],
    'access_type' => 'offline',
    'prompt' => 'consent'
];

$auth_url = GOOGLE_AUTH_URL . '?' . http_build_query($params);
header('Location: ' . $auth_url);
exit;
?>
