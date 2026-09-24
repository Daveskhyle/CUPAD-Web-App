<?php
/**
 * Google OAuth Callback Handler
 * Processes Google authentication response
 */

require_once 'session_config.php';
require_once 'google_config.php';

// Check for errors
if (isset($_GET['error'])) {
    $_SESSION['login_error'] = 'Google authentication failed: ' . htmlspecialchars($_GET['error']);
    header('Location: index.php');
    exit;
}

// Verify state token
$stored_state = $_SESSION['google_oauth_state'] ?? $_COOKIE['google_oauth_state'] ?? null;

if (!$stored_state) {
    $_SESSION['login_error'] = 'Session expired. Please try again.';
    header('Location: index.php');
    exit;
}

if (isset($_GET['state']) && $_GET['state'] !== $stored_state) {
    $_SESSION['login_error'] = 'Invalid state parameter. Please try again.';
    header('Location: index.php');
    exit;
}

unset($_SESSION['google_oauth_state']);
if (isset($_COOKIE['google_oauth_state'])) {
    setcookie('google_oauth_state', '', time() - 3600, '/', '', true, false);
}

// Get authorization code
if (!isset($_GET['code'])) {
    $_SESSION['login_error'] = 'Authorization code not received.';
    header('Location: index.php');
    exit;
}

$code = $_GET['code'];

// Exchange code for access token
$token_params = [
    'code' => $code,
    'client_id' => GOOGLE_CLIENT_ID,
    'client_secret' => GOOGLE_CLIENT_SECRET,
    'redirect_uri' => GOOGLE_REDIRECT_URI,
    'grant_type' => 'authorization_code'
];

$ch = curl_init(GOOGLE_TOKEN_URL);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($token_params));
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

$token_response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($http_code !== 200) {
    $_SESSION['login_error'] = 'Failed to obtain access token from Google.';
    header('Location: index.php');
    exit;
}

$token_data = json_decode($token_response, true);

if (!isset($token_data['access_token'])) {
    $_SESSION['login_error'] = 'Access token not found in response.';
    header('Location: index.php');
    exit;
}

$access_token = $token_data['access_token'];

// Get user info from Google
$ch = curl_init(GOOGLE_USERINFO_URL);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $access_token]);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

$userinfo_response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($http_code !== 200) {
    $_SESSION['login_error'] = 'Failed to retrieve user information from Google.';
    header('Location: index.php');
    exit;
}

$user_info = json_decode($userinfo_response, true);

if (!isset($user_info['email'])) {
    $_SESSION['login_error'] = 'Email not provided by Google.';
    header('Location: index.php');
    exit;
}

// Load existing users
$users_file = 'users.json';
$users = [];

if (file_exists($users_file)) {
    $json = file_get_contents($users_file);
    $users = json_decode($json, true);
    if (!is_array($users)) {
        $users = [];
    }
}

// Check if user exists by email or Google ID
$user_found = null;
$user_key = null;

foreach ($users as $key => $user) {
    if ((isset($user['email']) && $user['email'] === $user_info['email']) ||
        (isset($user['google_id']) && $user['google_id'] === $user_info['id'])) {
        $user_found = $user;
        $user_key = $key;
        break;
    }
}

if ($user_found) {
    // Update existing user with Google info
    $users[$user_key]['google_id'] = $user_info['id'];
    $users[$user_key]['email'] = $user_info['email'];
    $users[$user_key]['last_login'] = date('Y-m-d H:i:s');
    
    // Update profile picture if available
    if (isset($user_info['picture'])) {
        $users[$user_key]['google_picture'] = $user_info['picture'];
    }
    
    file_put_contents($users_file, json_encode($users, JSON_PRETTY_PRINT));
    
    // Set session variables
    $_SESSION['user_id'] = $users[$user_key]['id'] ?? $users[$user_key]['username'];
    $_SESSION['role'] = $users[$user_key]['role'];
    $_SESSION['full_name'] = $users[$user_key]['name'] ?? $user_info['name'];
    $_SESSION['username'] = $users[$user_key]['username'];
    $_SESSION['profile_pic'] = $users[$user_key]['profile_pic'] ?? $user_info['picture'] ?? null;
    $_SESSION['branch'] = $users[$user_key]['branch_id'] ?? '';
    $_SESSION['area'] = $users[$user_key]['area_id'] ?? '';
    $_SESSION['google_authenticated'] = true;
    
    // Redirect based on role
    $role = strtolower($users[$user_key]['role']);
    switch ($role) {
        case 'admin': header('Location: admin/dashboard.php'); exit;
        case 'am': header('Location: am/dashboard.php'); exit;
        case 'bm': header('Location: bm/dashboard.php'); exit;
        case 'client': header('Location: client/dashboard.php'); exit;
        case 'co': header('Location: co/dashboard.php'); exit;
        case 'dzm': header('Location: dzm/dashboard.php'); exit;
        case 'zm': header('Location: zm/dashboard.php'); exit;
        default:
            $_SESSION['login_error'] = 'Unknown user role.';
            header('Location: index.php');
            exit;
    }
} else {
    // User not found - redirect to registration or show error
    $_SESSION['login_error'] = 'No account found with this Google email. Please contact administrator or use regular login.';
    header('Location: index.php');
    exit;
}
?>
