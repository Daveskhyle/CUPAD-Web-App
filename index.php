<?php
date_default_timezone_set('Africa/Lagos');

// Include database configuration
require_once __DIR__ . '/includes/config.php';

// --- 1. AUDIT LOGGER ---
function logAttempt($username, $status) {
    $logFile = __DIR__ . '/login_audit.log';
    $safeUser = preg_replace('/[^a-zA-Z0-9_\-]/', '', $username);
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $date = date('Y-m-d H:i:s');
    $entry = "[$date] IP: $ip | User: $safeUser | Status: $status" . PHP_EOL;
    @file_put_contents($logFile, $entry, FILE_APPEND);
}

// --- 2. SECURE SESSION & COOKIE SETTINGS ---
$cookieParams = session_get_cookie_params();
$isSecure = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || 
            (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

session_set_cookie_params([
    'lifetime' => $cookieParams['lifetime'],
    'path' => $cookieParams['path'],
    'domain' => $cookieParams['domain'],
    'secure' => $isSecure,
    'httponly' => true,
    'samesite' => 'Lax'
]);

session_start();

// Helper for Setting Hardened Cookies
function setSecureCookie($name, $value, $expires = 0) {
    global $isSecure;
    setcookie($name, $value, [
        'expires'  => $expires,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isSecure,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
}

// --- 3. SECURITY HEADERS ---
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");

// --- HELPER: Database Connection Singleton ---
function getSharedDbConnection() {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = getDbConnection();
    }
    return $pdo;
}

// --- HELPER: Get User from SQL ---
function getUserByUsername($username) {
    try {
        $pdo = getSharedDbConnection();
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND status = 'active' LIMIT 1");
        $stmt->execute([$username]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("getUserByUsername error: " . $e->getMessage());
        return null;
    }
}

// --- HELPER: Get User by Passkey ---
function getUserByPasskey($credentialId) {
    try {
        $pdo = getSharedDbConnection();
        $stmt = $pdo->prepare("
            SELECT u.*, up.id as passkey_id, up.created_at as passkey_created 
            FROM users u
            INNER JOIN user_passkeys up ON u.id = up.user_id
            WHERE up.credential_id = ? AND u.status = 'active'
            LIMIT 1
        ");
        $stmt->execute([$credentialId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("getUserByPasskey error: " . $e->getMessage());
        return null;
    }
}

// --- HELPER: Update User ---
function updateUser($userId, $data) {
    try {
        if (empty($data)) return true;
        $pdo = getSharedDbConnection();
        $sets = [];
        $params = [];
        foreach ($data as $key => $value) {
            $sets[] = "`$key` = ?";
            $params[] = is_array($value) ? json_encode($value) : $value;
        }
        $params[] = $userId;
        $sql = "UPDATE users SET " . implode(', ', $sets) . " WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        return $stmt->execute($params);
    } catch (Exception $e) {
        error_log("updateUser error: " . $e->getMessage());
        return false;
    }
}

// --- HELPER: Prevent Double Logins ---
function isUserCurrentlyActive($userId) {
    return false;
}

// --- HELPER: Register Passkey ---
function registerPasskey($userId, $credentialId) {
    try {
        $pdo = getSharedDbConnection();
        $stmt = $pdo->prepare("SELECT id FROM user_passkeys WHERE user_id = ? AND credential_id = ? LIMIT 1");
        $stmt->execute([$userId, $credentialId]);
        if ($stmt->fetch()) {
            return ['success' => true, 'message' => 'Fingerprint already registered.'];
        }

        $stmt = $pdo->prepare("INSERT INTO user_passkeys (user_id, credential_id, created_at) VALUES (?, ?, NOW())");
        if ($stmt->execute([$userId, $credentialId])) {
            return ['success' => true, 'message' => 'Fingerprint registered successfully!'];
        }
        return ['success' => false, 'message' => 'Failed to save passkey.'];
    } catch (Exception $e) {
        error_log("registerPasskey error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to save passkey.'];
    }
}

// --- HELPER: Check if User has Passkeys ---
function userHasPasskeys($userId) {
    try {
        $pdo = getSharedDbConnection();
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM user_passkeys WHERE user_id = ?");
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Exception $e) {
        error_log("userHasPasskeys error: " . $e->getMessage());
        return false;
    }
}

// --- HELPER: Update User Online Status ---
function updateUserOnlineStatus($userId, $isOnline = true) {
    try {
        $pdo = getSharedDbConnection();
        if ($isOnline) {
            $stmt = $pdo->prepare("UPDATE users SET is_online = 1, last_activity = NOW(), last_login = NOW() WHERE id = ?");
            $stmt->execute([$userId]);
            
            $sessionId = session_id();
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
            
            $stmt = $pdo->prepare("INSERT INTO user_sessions (user_id, session_id, ip_address, user_agent, login_time, last_activity, is_active) 
                VALUES (?, ?, ?, ?, NOW(), NOW(), 1) 
                ON DUPLICATE KEY UPDATE last_activity = NOW(), is_active = 1");
            $stmt->execute([$userId, $sessionId, $ip, $userAgent]);
        } else {
            $stmt = $pdo->prepare("UPDATE users SET is_online = 0, last_logout = NOW() WHERE id = ?");
            $stmt->execute([$userId]);
            
            $sessionId = session_id();
            $stmt = $pdo->prepare("UPDATE user_sessions SET logout_time = NOW(), is_active = 0 WHERE user_id = ? AND session_id = ?");
            $stmt->execute([$userId, $sessionId]);
        }
        return true;
    } catch (Exception $e) {
        error_log("updateUserOnlineStatus error: " . $e->getMessage());
        return false;
    }
}

// --- HELPER: Set Session Variables ---
function setUserSession($user) {
    $displayName = $user['full_name'] ?? $user['name'] ?? $user['username'];
    $_SESSION['user_id'] = $user['id'] ?? $user['username'];
    $_SESSION['user_role'] = $user['role'];
    $_SESSION['name'] = $displayName;
    $_SESSION['full_name'] = $displayName;
    $_SESSION['username'] = $user['username'];
    $_SESSION['profile_pic'] = $user['profile_pic'] ?? null;
    $_SESSION['branch'] = $user['branch_id'] ?? '';
    $_SESSION['area'] = $user['area_id'] ?? '';
    $_SESSION['zone'] = $user['zone_id'] ?? '';
    $_SESSION['email'] = $user['email'] ?? '';
    $_SESSION['phone'] = $user['phone'] ?? '';
    $_SESSION['login_time'] = time();
    
    updateUserOnlineStatus($user['id'], true);
}

// --- HELPER: Get Redirect URL by Role ---
function getRedirectByRole($role) {
    $role = strtolower($role);
    return match ($role) {
        'admin' => 'admin/dashboard.php',
        'tm' => 'tm/dashboard.php',
        'am' => 'am/dashboard.php',
        'bm' => 'bm/dashboard.php',
        'client' => 'client/dashboard.php',
        'co' => 'co/dashboard.php',
        'dzm' => 'dzm/dashboard.php',
        'zm' => 'zm/dashboard.php',
        default => 'co/dashboard.php',
    };
}

// --- HELPER: GENERATE RANDOM CHALLENGE FOR PASSKEY ---
if (empty($_SESSION['webauthn_challenge'])) {
    $_SESSION['webauthn_challenge'] = bin2hex(random_bytes(32));
}

// --- 4. AUTO LOGIN LOGIC (Cookie) ---
if (!isset($_SESSION['username']) && isset($_COOKIE['remember_username'])) {
    $remembered_username = $_COOKIE['remember_username'];
    $user = getUserByUsername($remembered_username);

    if ($user) {
        setUserSession($user);
        logAttempt($user['username'], 'AUTO_LOGIN_SUCCESS');
        setSecureCookie('last_login_username', $user['username'], time() + (30 * 24 * 60 * 60));
        $redirect = getRedirectByRole($user['role']);
        if ($redirect) { header("Location: $redirect"); exit; }
    } else {
        setSecureCookie('remember_username', '', time() - 3600);
    }
}

// Ensure CSRF Token exists
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

$error = '';
if (isset($_GET['error']) && $_GET['error'] === 'unauthorized') $error = 'You must be logged in as an admin to access that page.';
if (isset($_SESSION['login_error'])) { $error = $_SESSION['login_error']; unset($_SESSION['login_error']); }

$is_ajax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

// --- 5. POST REQUEST HANDLER ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    define('MAX_LOGIN_ATTEMPTS', 5);
    define('LOGIN_ATTEMPT_WINDOW', 15 * 60);
    define('LOCKOUT_PERIOD', 10 * 60);

    $ajax_response = [];

    // --- A. PASSKEY REGISTRATION ---
    if (isset($_POST['action']) && $_POST['action'] === 'register_passkey') {
        if (!isset($_SESSION['username']) || !isset($_SESSION['user_id'])) {
            echo json_encode(['success' => false, 'message' => 'You must be logged in first.']);
            exit;
        }
        $rawCredId = $_POST['credentialId'] ?? '';

        if ($rawCredId) {
            $result = registerPasskey($_SESSION['user_id'], $rawCredId);
            echo json_encode($result);
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid credential ID.']);
        }
        exit;
    }

    // --- B. PASSKEY LOGIN ---
    if (isset($_POST['action']) && $_POST['action'] === 'login_passkey') {
        // Enforce Compulsory Location Access Permission
        if (isset($_POST['location_status']) && $_POST['location_status'] === 'denied') {
            echo json_encode(['success' => false, 'message' => 'Location access is required to login. Please enable location permissions.']);
            exit;
        }

        $credId = $_POST['credentialId'] ?? '';
        $user = getUserByPasskey($credId);

        if ($user) {
            if (isUserCurrentlyActive($user['id'])) {
                logAttempt($user['username'], 'BLOCKED_DOUBLE_LOGIN');
                echo json_encode(['success' => false, 'message' => 'This account is currently active on another device.']);
                exit;
            }

            // Only update coordinates if non-empty values are received (preserves existing DB coordinates)
            $updateData = [
                'last_login' => date('Y-m-d H:i:s')
            ];
            if (isset($_POST['latitude']) && trim((string)$_POST['latitude']) !== '') {
                $updateData['last_latitude'] = trim((string)$_POST['latitude']);
            }
            if (isset($_POST['longitude']) && trim((string)$_POST['longitude']) !== '') {
                $updateData['last_longitude'] = trim((string)$_POST['longitude']);
            }

            updateUser($user['id'], $updateData);

            setUserSession($user);
            logAttempt($user['username'], 'PASSKEY_LOGIN_SUCCESS');
            setSecureCookie('last_login_username', $user['username'], time() + (30 * 24 * 60 * 60));

            $displayName = $user['full_name'] ?? $user['name'] ?? $user['username'];
            $redirect = getRedirectByRole($user['role']);
            echo json_encode([
                'success' => true,
                'redirect' => $redirect,
                'user_name' => $displayName
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Fingerprint not recognized.']);
        }
        exit;
    }

    // --- C. STANDARD LOGIN WITH SEAMLESS CSRF AUTO-REFRESH ---
    $post_csrf = isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
    $csrf_valid = !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $post_csrf);

    // Refresh CSRF Token for future requests
    $new_csrf_token = bin2hex(random_bytes(32));
    $_SESSION['csrf_token'] = $new_csrf_token;

    if (!empty($_POST['website_url'])) {
        logAttempt('BOT', 'HONEYPOT_TRIGGERED');
        sleep(1);
        $ajax_response = ['success' => false, 'message' => 'System error.', 'new_csrf' => $new_csrf_token];
    }
    elseif (isset($_POST['location_status']) && $_POST['location_status'] === 'denied') {
        logAttempt($_POST['username'] ?? 'UNKNOWN', 'LOCATION_DENIED');
        $ajax_response = ['success' => false, 'message' => 'Location access is required to login. Please enable location permissions in your browser.', 'new_csrf' => $new_csrf_token];
    }
    else {
        $username = trim((string)($_POST['username'] ?? ''));
        $password = $_POST['password'] ?? '';

        $user = getUserByUsername($username);

        if (!$user) {
            logAttempt($username, 'INVALID_USER');
            $ajax_response = ['success' => false, 'message' => 'Invalid credentials.', 'new_csrf' => $new_csrf_token];
        } else {
            $failed_attempts = [];
            if (!empty($user['failed_login_attempts'])) {
                $failed_attempts = is_string($user['failed_login_attempts'])
                    ? json_decode($user['failed_login_attempts'], true) ?? []
                    : $user['failed_login_attempts'];
            }

            if (!empty($user['lockout_until']) && strtotime($user['lockout_until']) > time()) {
                $rem = ceil((strtotime($user['lockout_until']) - time()) / 60);
                logAttempt($username, 'LOCKED_OUT_ATTEMPT');
                $ajax_response = ['success' => false, 'message' => "Account locked. Try again in {$rem}m.", 'lockout_time' => $rem, 'new_csrf' => $new_csrf_token];
            } else {
                $valid = false;
                if (isset($user['password']) && !empty($user['password'])) {
                    if (strpos($user['password'], '$2y$') === 0 || strpos($user['password'], '$2a$') === 0 || strpos($user['password'], '$argon2') === 0) {
                        $valid = password_verify($password, $user['password']);
                    } else {
                        $valid = ($user['password'] === $password);
                    }
                }

                if ($valid) {
                    if (isUserCurrentlyActive($user['id'])) {
                        logAttempt($username, 'BLOCKED_DOUBLE_LOGIN');
                        $ajax_response = ['success' => false, 'message' => 'This account is currently active on another device.', 'new_csrf' => $new_csrf_token];
                    } else {
                        logAttempt($username, 'SUCCESS');
                        setSecureCookie('last_login_username', $user['username'], time() + (30 * 24 * 60 * 60));

                        // Only update coordinates if non-empty values are received (preserves existing DB coordinates)
                        $updateData = [
                            'failed_login_attempts' => json_encode([]),
                            'lockout_until' => null,
                            'last_login' => date('Y-m-d H:i:s')
                        ];
                        if (isset($_POST['latitude']) && trim((string)$_POST['latitude']) !== '') {
                            $updateData['last_latitude'] = trim((string)$_POST['latitude']);
                        }
                        if (isset($_POST['longitude']) && trim((string)$_POST['longitude']) !== '') {
                            $updateData['last_longitude'] = trim((string)$_POST['longitude']);
                        }

                        updateUser($user['id'], $updateData);

                        session_regenerate_id(true);
                        setUserSession($user);

                        if (isset($_POST['remember_me']) && $_POST['remember_me'] === 'on') {
                            setSecureCookie('remember_username', $user['username'], time() + (30 * 24 * 60 * 60));
                        } else {
                            if (isset($_COOKIE['remember_username'])) setSecureCookie('remember_username', '', time() - 3600);
                        }

                        $promptPasskey = !userHasPasskeys($user['id']);
                        $redirect = getRedirectByRole($user['role']);
                        $displayName = $user['full_name'] ?? $user['name'] ?? ucfirst($user['username']);

                        $ajax_response = [
                            'success' => true,
                            'redirect' => $redirect,
                            'user_name' => $displayName,
                            'prompt_passkey' => $promptPasskey,
                            'new_csrf' => $_SESSION['csrf_token']
                        ];
                    }

                } else {
                    logAttempt($username, 'FAILED_PASSWORD');

                    $failed_attempts[] = time();
                    $failed_attempts = array_filter($failed_attempts, fn($ts) => (time() - $ts) < LOGIN_ATTEMPT_WINDOW);

                    $rem_attempts = MAX_LOGIN_ATTEMPTS - count($failed_attempts);

                    if ($rem_attempts <= 0) {
                        updateUser($user['id'], [
                            'lockout_until' => date('Y-m-d H:i:s', time() + LOCKOUT_PERIOD),
                            'failed_login_attempts' => json_encode(array_values($failed_attempts))
                        ]);
                        $msg = "Account locked. Try again in 10 minutes.";
                        $ajax_response = ['success' => false, 'message' => $msg, 'lockout_time' => 10, 'new_csrf' => $new_csrf_token];
                    } else {
                        updateUser($user['id'], [
                            'failed_login_attempts' => json_encode(array_values($failed_attempts))
                        ]);
                        $msg = "Invalid credentials. {$rem_attempts} attempts left.";
                        $ajax_response = ['success' => false, 'message' => $msg, 'attempts_remaining' => $rem_attempts, 'new_csrf' => $new_csrf_token];
                    }
                }
            }
        }
    }

    if ($is_ajax) {
        header('Content-Type: application/json');
        echo json_encode($ajax_response);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f3f4f6" id="theme-color-meta">

    <!-- AUTO DETECT, APPLY AND SAVE THEME TO LOCALSTORAGE FOR DASHBOARDS -->
    <script>
        (function() {
            let activeTheme = localStorage.getItem('theme');
            // Auto-detect system preference if user has never selected a theme
            if (!activeTheme) {
                activeTheme = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
                localStorage.setItem('theme', activeTheme); // Persist for dashboards & sub-pages
            }

            const metaThemeColor = document.getElementById('theme-color-meta');
            if (activeTheme === 'dark') {
                document.documentElement.classList.add('dark');
                if(metaThemeColor) metaThemeColor.setAttribute('content', '#0f172a');
            } else {
                document.documentElement.classList.remove('dark');
                if(metaThemeColor) metaThemeColor.setAttribute('content', '#f3f4f6');
            }
        })();
    </script>

    <!-- PWA Support -->
    <link rel="manifest" href="manifest.json">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="CUPAD">
    <link rel="apple-touch-icon" href="uploads/CUPAD LOGO.png">

    <title>CUPAD Login</title>
    <link rel="icon" type="image/png" sizes="32x32" href="uploads/CUPAD LOGO.png">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <style>
        @keyframes aurora { from { background-position: 0% 50%; } to { background-position: 100% 50%; } }
        @keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
        @keyframes pulse-logo { 0%, 100% { transform: scale(1); opacity: 1; } 50% { transform: scale(1.05); opacity: 0.9; } }
        @keyframes shake { 0%, 100% { transform: translateX(0); } 25% { transform: translateX(-10px); } 75% { transform: translateX(10px); } }
        @keyframes float { 0%, 100% { transform: translateY(0px); } 50% { transform: translateY(-10px); } }

        .shake-animation { animation: shake 0.4s ease-in-out; border-color: var(--error) !important; }

        /* --- VARIABLES --- */
        :root {
            --primary: hsl(217, 89%, 61%);
            --primary-dark: hsl(217, 89%, 50%);
            --secondary: hsl(283, 89%, 61%);
            --success: #22c55e;
            --error: #ef4444;
            --bg-glass: rgba(255, 255, 255, 0.7);
            --border-glass: rgba(255, 255, 255, 0.4);
            --text-main: #1f2937;
            --text-sub: #6b7280;
            --input-bg: rgba(255,255,255,0.6);
        }

        html.dark {
            --bg-glass: rgba(31, 41, 55, 0.7);
            --border-glass: rgba(255, 255, 255, 0.1);
            --text-main: #ffffff;
            --text-sub: #9ca3af;
            --input-bg: rgba(17, 24, 39, 0.6);
        }

        /* --- BASE RESET --- */
        * { box-sizing: border-box; outline: none; }
        body {
            margin: 0; font-family: 'Inter', sans-serif;
            background-color: #f3f4f6; color: var(--text-main);
            min-height: 100vh; overflow-x: hidden; width: 100%;
        }
        html.dark body { background-color: #0f172a; }
        a { text-decoration: none; }

        /* --- BACKGROUND FX --- */
        #background-effects { position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: -2; overflow: hidden; pointer-events: none; }
        #aurora-container { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 150vw; height: 150vh; filter: blur(80px) saturate(150%); opacity: 0.35; }
        .aurora-item { position: absolute; border-radius: 50%; background-image: radial-gradient(circle, var(--primary), transparent 60%); animation: aurora 15s ease-in-out infinite alternate; }
        .aurora-item:nth-child(2) { --primary: var(--secondary); animation-duration: 20s; animation-delay: -5s; }
        #particle-container { position: absolute; top: 0; left: 0; width: 100%; height: 100%; z-index: 1; }

        /* --- APP WELCOME SCREEN --- */
        #app-welcome-screen {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(243, 244, 246, 0.95); backdrop-filter: blur(20px);
            z-index: 99990; 
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            transition: opacity 0.5s ease, transform 0.6s cubic-bezier(0.16, 1, 0.3, 1), visibility 0.5s;
        }
        html.dark #app-welcome-screen { background: rgba(15, 23, 42, 0.95); }
        
        #app-welcome-screen.hidden { opacity: 0; transform: translateY(-20px) scale(0.98); visibility: hidden; pointer-events: none; }

        .welcome-skip-btn {
            position: absolute; top: 24px; right: 24px;
            background: transparent; border: none; color: var(--text-sub);
            font-size: 1rem; font-weight: 500; cursor: pointer; transition: color 0.2s;
            padding: 8px 16px; z-index: 10;
        }
        .welcome-skip-btn:hover { color: var(--primary); }

        .welcome-carousel { position: relative; width: 90%; max-width: 450px; height: 350px; margin-bottom: 2rem; }

        .welcome-slide {
            position: absolute; top: 0; left: 0; width: 100%; height: 100%;
            display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center;
            opacity: 0; transform: translateX(50px); pointer-events: none;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .welcome-slide.active { opacity: 1; transform: translateX(0); pointer-events: all; }
        .welcome-slide.exit-left { opacity: 0; transform: translateX(-50px); }
        
        .welcome-logo {
            width: 140px; margin-bottom: 1.5rem;
            animation: float 4s ease-in-out infinite;
            filter: drop-shadow(0 10px 15px rgba(0,0,0,0.1));
        }

        .welcome-icon-wrapper {
            width: 90px; height: 90px; border-radius: 50%;
            background: linear-gradient(135deg, rgba(59,130,246,0.1), rgba(168,85,247,0.1));
            color: var(--primary); font-size: 2.5rem;
            display: flex; align-items: center; justify-content: center;
            margin-bottom: 2rem; border: 1px solid var(--border-glass);
        }
        
        .welcome-title { font-size: 2rem; font-weight: 800; color: var(--text-main); margin: 0 0 12px 0; line-height: 1.2; }
        .welcome-subtitle { font-size: 1.1rem; color: var(--text-sub); margin: 0; line-height: 1.6; padding: 0 10px; }

        .welcome-controls-area { display: flex; flex-direction: column; align-items: center; gap: 1.5rem; width: 90%; max-width: 450px; }

        .welcome-dots { display: flex; gap: 8px; justify-content: center; }
        .welcome-dots .dot { width: 8px; height: 8px; border-radius: 50%; background: var(--border-glass); border: 1px solid rgba(150,150,150,0.3); transition: all 0.3s ease; cursor: pointer; }
        .welcome-dots .dot.active { width: 24px; border-radius: 4px; background: var(--primary); border-color: var(--primary); }
        #welcome-action-btn { width: 100%; height: 54px; border-radius: 1rem; font-size: 1.1rem; }

        /* --- CONTROLS --- */
        .control-btn {
            position: fixed; top: 20px; z-index: 50;
            background: var(--bg-glass); backdrop-filter: blur(10px);
            border: 1px solid var(--border-glass);
            padding: 10px 16px; border-radius: 99px;
            color: var(--text-main); cursor: pointer;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05); transition: all 0.3s ease;
            font-size: 0.9rem; font-weight: 500; display: flex; align-items: center; gap: 8px;
        }
        .control-btn:hover { transform: translateY(-2px); box-shadow: 0 8px 12px rgba(0,0,0,0.1); }
        .control-btn select { background: transparent; border: none; color: inherit; font: inherit; cursor: pointer; }
        html.dark .control-btn select option { background-color: #1f2937; }
        #theme-toggle-container { left: 20px; }
        #lang-selector-container { right: 20px; }

        /* --- WAITING STATE OVERLAY --- */
        #full-screen-loader {
            position: fixed; top: 0; left: 0; width: 100vw; height: 100vh;
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(15px); z-index: 99999;
            display: none; flex-direction: column; justify-content: center; align-items: center;
            opacity: 0; transition: opacity 0.4s ease;
        }
        html.dark #full-screen-loader { background: rgba(3, 7, 18, 0.95); }
        #full-screen-loader.show { display: flex; opacity: 1; }
        .loader-logo { width: 80px; margin-bottom: 24px; animation: pulse-logo 2s infinite ease-in-out; }
        .modern-spinner { width: 48px; height: 48px; border: 4px solid rgba(59, 130, 246, 0.2); border-top: 4px solid var(--primary); border-radius: 50%; animation: spin 1s linear infinite; }
        .loader-text { margin-top: 24px; font-size: 1.5rem; font-weight: 700; color: var(--text-main); }
        html.dark .loader-text { color: #ffffff !important; }
        .loader-subtext { font-size: 1rem; color: var(--text-sub); margin-top: 8px; }

        /* --- LOCATION DENIED MODAL --- */
        .modal-overlay {
            position: fixed; inset: 0;
            background: rgba(15, 23, 42, 0.65);
            backdrop-filter: blur(12px) saturate(180%);
            z-index: 1000000;
            display: flex; justify-content: center; align-items: center;
            opacity: 0; visibility: hidden; pointer-events: none;
            transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
            padding: 1.25rem;
        }
        .modal-overlay.show { opacity: 1; visibility: visible; pointer-events: auto; }

        .modal-content {
            background: var(--bg-glass);
            border: 1px solid var(--border-glass);
            padding: 2.25rem 1.75rem 1.75rem; border-radius: 1.5rem;
            max-width: 440px; width: 100%; text-align: center;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.35);
            transform: scale(0.92) translateY(15px);
            transition: all 0.35s cubic-bezier(0.34, 1.56, 0.64, 1);
            position: relative;
            overflow: hidden;
        }
        .modal-overlay.show .modal-content { transform: scale(1) translateY(0); }

        .modal-close-btn {
            position: absolute; top: 1rem; right: 1rem;
            background: transparent; border: none; color: var(--text-sub);
            font-size: 1.1rem; cursor: pointer; padding: 0.5rem;
            border-radius: 50%; transition: all 0.2s ease;
            display: flex; align-items: center; justify-content: center;
            width: 34px; height: 34px;
        }
        .modal-close-btn:hover { background: rgba(150, 150, 150, 0.15); color: var(--text-main); }

        .modal-icon-badge {
            width: 72px; height: 72px; border-radius: 50%;
            background: rgba(239, 68, 68, 0.12); color: var(--error);
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 2rem; margin-bottom: 1.25rem;
            border: 1px solid rgba(239, 68, 68, 0.25);
            box-shadow: 0 0 25px rgba(239, 68, 68, 0.2);
            animation: pulse-badge 2s infinite ease-in-out;
        }
        @keyframes pulse-badge {
            0%, 100% { transform: scale(1); box-shadow: 0 0 25px rgba(239, 68, 68, 0.2); }
            50% { transform: scale(1.05); box-shadow: 0 0 35px rgba(239, 68, 68, 0.4); }
        }

        .modal-title { font-size: 1.35rem; font-weight: 800; margin-bottom: 0.5rem; color: var(--text-main); letter-spacing: -0.02em; }
        .modal-text { font-size: 0.95rem; color: var(--text-sub); margin-bottom: 1.5rem; line-height: 1.5; }

        .modal-steps { display: flex; flex-direction: column; gap: 0.75rem; margin-bottom: 1.75rem; text-align: left; }
        .modal-step-item {
            display: flex; align-items: center; gap: 0.85rem;
            background: rgba(150, 150, 150, 0.08);
            border: 1px solid rgba(150, 150, 150, 0.12);
            padding: 0.85rem 1rem; border-radius: 1rem;
            transition: all 0.2s ease;
        }
        .modal-step-item:hover { background: rgba(150, 150, 150, 0.12); border-color: rgba(150, 150, 150, 0.2); }

        .modal-step-badge {
            width: 26px; height: 26px; border-radius: 50%;
            background: var(--primary); color: #ffffff;
            font-size: 0.8rem; font-weight: 700;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0; box-shadow: 0 2px 8px rgba(59, 130, 246, 0.3);
        }
        .modal-step-text { font-size: 0.875rem; color: var(--text-main); line-height: 1.45; font-weight: 500; }
        .modal-step-icon { color: var(--primary); font-size: 0.95rem; margin: 0 2px; }

        .modal-actions { display: flex; flex-direction: column; gap: 0.65rem; }
        .btn-secondary {
            background: transparent; color: var(--text-sub); border: 1px solid var(--border-glass);
            padding: 0.85rem; border-radius: 0.75rem; font-weight: 600; font-size: 0.95rem;
            cursor: pointer; transition: all 0.2s ease; width: 100%;
        }
        .btn-secondary:hover { background: rgba(150, 150, 150, 0.1); color: var(--text-main); }

        /* --- MAIN LAYOUT --- */
        .login-container { display: flex; width: 100%; min-height: 100vh; overflow: hidden; flex-wrap: wrap; }

        .info-side {
            flex: 1 1 50%; width: 50%; padding: clamp(2rem, 5vw, 4rem);
            display: flex; flex-direction: column; justify-content: center;
            color: #fff; position: relative;
            background-image: linear-gradient(rgba(0,0,0,0.7), rgba(0,0,0,0.6)), url('uploads/cupad logo.png');
            background-size: cover; background-position: center;
        }
        .info-title { font-size: clamp(2.5rem, 5vw, 4rem); font-weight: 800; margin-bottom: 1rem; line-height: 1.1; }
        .info-subtitle { font-size: clamp(1rem, 1.5vw, 1.3rem); margin-bottom: 2rem; max-width: 600px; opacity: 0.9; }
        .info-features { list-style: none; padding: 0; }
        .info-features li { display: flex; align-items: center; gap: 1rem; margin-bottom: 1.2rem; font-size: 1.1rem; }
        .info-features li i { width: 24px; color: var(--primary); }

        .login-side {
            flex: 1 1 50%; width: 50%; padding: clamp(1.5rem, 5vw, 4rem);
            display: flex; align-items: center; justify-content: center; position: relative;
        }
        .login-card { width: 100%; max-width: 440px; z-index: 10; }
        .login-header { text-align: center; margin-bottom: 2.5rem; }
        .login-logo { height: 60px; margin-bottom: 1rem; }
        .login-title { font-size: 1.8rem; font-weight: 700; color: var(--primary); margin: 0; }
        .login-subtitle { color: var(--text-sub); font-size: 0.95rem; margin-top: 0.5rem; }

        /* --- FORM STYLES --- */
        .form-container { display: flex; flex-direction: column; gap: 1.25rem; }

        .input-group { position: relative; }
        .input-group .icon { position: absolute; left: 1rem; top: 50%; transform: translateY(-50%); color: var(--text-sub); pointer-events: none; transition: 0.2s; z-index: 2; }

        .form-input {
            width: 100%; padding: 1.2rem 1rem 0.5rem 2.8rem; height: 56px;
            border-radius: 0.75rem; border: 1px solid rgba(150,150,150,0.3);
            background-color: var(--input-bg); color: var(--text-main);
            font-size: 1rem; transition: all 0.2s;
            -webkit-box-shadow: 0 0 0 30px var(--input-bg) inset !important;
            -webkit-text-fill-color: var(--text-main) !important;
        }

        .floating-label {
            position: absolute; left: 2.8rem; top: 18px;
            color: var(--text-sub); font-size: 1rem; pointer-events: none;
            transition: all 0.2s ease;
        }

        .form-input:focus, .form-input:not(:placeholder-shown) { border-color: var(--primary); box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.15); }
        .form-input:focus + .floating-label, .form-input:not(:placeholder-shown) + .floating-label { top: 8px; font-size: 0.75rem; color: var(--primary); font-weight: 600; }
        .form-input:focus ~ .icon { color: var(--primary); }

        .caps-lock-icon { position: absolute; right: 2.5rem; top: 50%; transform: translateY(-50%); color: var(--warning, #f59e0b); display: none; font-size: 1.1rem; }
        #show-password-toggle { position: absolute; right: 1rem; top: 50%; transform: translateY(-50%); cursor: pointer; color: var(--text-sub); padding: 5px; }

        .passkey-btn { position: absolute; right: 1rem; top: 50%; transform: translateY(-50%); cursor: pointer; color: var(--primary); font-size: 1.5rem; padding: 5px; transition: 0.2s; z-index: 5; }
        .passkey-btn:hover { color: var(--primary-dark); transform: translateY(-50%) scale(1.1); }

        .btn-primary {
            position: relative; overflow: hidden; background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: #fff; padding: 0.9rem; border-radius: 0.75rem; font-weight: 600; font-size: 1rem; border: none; cursor: pointer;
            transition: all 0.3s ease; width: 100%; letter-spacing: 0.5px;
        }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 10px 20px rgba(37, 99, 235, 0.2); }
        .btn-primary:disabled { opacity: 0.6; cursor: not-allowed; transform: none; box-shadow: none; filter: grayscale(40%); }

        .ripple { position: absolute; background: rgba(255, 255, 255, 0.3); border-radius: 50%; transform: scale(0); animation: ripple 0.6s linear; pointer-events: none; }
        @keyframes ripple { to { transform: scale(4); opacity: 0; } }

        .form-options { display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.25rem; font-size: 0.9rem; }
        .remember-me { display: flex; align-items: center; gap: 0.5rem; color: var(--text-sub); cursor: pointer; }
        .forgot-password-link { color: var(--primary); font-weight: 600; }
        #password-strength-bar { height: 3px; width: 0; background: transparent; transition: 0.3s; margin-top: -8px; border-radius: 2px; }
        .social-login-divider { display: flex; align-items: center; color: var(--text-sub); margin: 1.75rem 0; font-size: 0.85rem; }
        .social-login-divider::before, .social-login-divider::after { content: ''; flex: 1; border-bottom: 1px solid var(--border-glass); opacity: 0.5; }
        .social-login-divider span { padding: 0 10px; }

        .social-btn {
            display: flex; align-items: center; justify-content: center; gap: 10px; width: 100%; height: 50px; border-radius: 0.75rem;
            text-decoration: none; font-weight: 600; color: var(--text-main); border: 1px solid rgba(120,120,120,0.2); background: var(--input-bg); transition: 0.2s;
        }
        .social-btn:hover { background: rgba(120,120,120,0.05); transform: translateY(-1px); }

        .toast {
            position: fixed; top: 24px; right: 24px; transform: translateX(120%); background: var(--bg-glass); backdrop-filter: blur(12px);
            color: var(--text-main); padding: 1rem 1.5rem; border-radius: 1rem; box-shadow: 0 8px 30px rgba(0,0,0,0.12); z-index: 100000;
            border: 1px solid var(--border-glass); display: flex; align-items: center; gap: 12px; transition: 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }
        .toast.show { transform: translateX(0); }
        .toast i { font-size: 1.2rem; }
        .toast.success i { color: var(--success); }
        .toast.error i { color: var(--error); }

        #pwa-install-popup, #passkey-setup-popup {
            position: fixed; bottom: 20px; right: 20px; z-index: 1000; background: var(--bg-glass); backdrop-filter: blur(10px);
            border: 1px solid var(--border-glass); border-radius: 12px; padding: 16px; max-width: 300px; transform: translateY(100px); opacity: 0; transition: all 0.3s ease; display: none;
        }
        #pwa-install-popup.show, #passkey-setup-popup.show { transform: translateY(0); opacity: 1; display: block; }
        .install-popup-content { display: flex; align-items: center; gap: 12px; }
        .install-popup-icon { font-size: 24px; color: var(--primary); }
        .install-popup-text { flex: 1; }
        .install-popup-title { font-weight: 600; margin: 0 0 4px 0; }
        .install-popup-desc { font-size: 0.85rem; color: var(--text-sub); margin: 0; }
        .install-popup-actions { display: flex; gap: 8px; margin-top: 12px; }
        .install-btn { background: var(--primary); color: white; border: none; padding: 8px 16px; border-radius: 6px; cursor: pointer; font-size: 0.85rem; }
        .dismiss-btn { background: transparent; color: var(--text-sub); border: 1px solid var(--border-glass); padding: 8px 16px; border-radius: 6px; cursor: pointer; font-size: 0.85rem; }

        .login-footer { position: fixed; bottom: 10px; width: 100%; text-align: center; color: var(--text-sub); font-size: 0.8rem; pointer-events: none; z-index: 10; }

        @media (prefers-reduced-motion: reduce) {
            .aurora-item, .modern-spinner, .loader-logo, .shake-animation, .welcome-logo { animation: none !important; }
            #particle-container { display: none; }
        }
        @media (max-width: 992px) {
            .info-side { display: none; }
            .login-side { width: 100%; flex: 1 1 100%; }
        }
        @media (max-width: 480px) {
            .control-btn span { display: none; }
            .login-card { padding: 0 10px; }
            .welcome-skip-btn { top: 70px; }
        }
    </style>
</head>
<body>

    <!-- APP WELCOME / ONBOARDING SCREEN -->
    <div id="app-welcome-screen">
        <button class="welcome-skip-btn" id="welcome-skip-btn">Skip</button>
        
        <div class="welcome-carousel">
            <div class="welcome-slide active" data-index="0">
                <img src="uploads/CUPAD LOGO.png" alt="CUPAD Logo" class="welcome-logo">
                <h1 class="welcome-title">Welcome to CUPAD</h1>
                <p class="welcome-subtitle">Empowering communities through strategic financial management and sustained growth.</p>
            </div>
            
            <div class="welcome-slide" data-index="1">
                <div class="welcome-icon-wrapper"><i class="fas fa-shield-alt"></i></div>
                <h1 class="welcome-title">Highly Secure</h1>
                <p class="welcome-subtitle">Your data is protected with enterprise-grade encryption and biometric passkey support.</p>
            </div>

            <div class="welcome-slide" data-index="2">
                <div class="welcome-icon-wrapper"><i class="fas fa-chart-line"></i></div>
                <h1 class="welcome-title">Drive Growth</h1>
                <p class="welcome-subtitle">Monitor performance and scale your business seamlessly from your central dashboard.</p>
            </div>
        </div>

        <div class="welcome-controls-area">
            <div class="welcome-dots">
                <span class="dot active" data-slide="0"></span>
                <span class="dot" data-slide="1"></span>
                <span class="dot" data-slide="2"></span>
            </div>
            <button id="welcome-action-btn" class="btn-primary ripple-btn">Next</button>
        </div>
    </div>

    <!-- FULL SCREEN WAITING STATE -->
    <div id="full-screen-loader" aria-live="polite">
        <img src="uploads/CUPAD LOGO.png" alt="Logo" class="loader-logo">
        <div class="modern-spinner"></div>
        <div class="loader-text" id="loadingText">Authenticating...</div>
        <div class="loader-subtext" id="loadingSubtext">Please wait while we secure your session</div>
    </div>

    <!-- ANIMATED BACKGROUND -->
    <div id="background-effects">
        <div id="aurora-container">
            <div class="aurora-item" style="width: 50vw; height: 50vw; top: 10%; left: 10%;"></div>
            <div class="aurora-item" style="width: 40vw; height: 40vw; top: 60%; left: 70%;"></div>
        </div>
        <canvas id="particle-container"></canvas>
    </div>

    <!-- CONTROLS -->
    <button id="theme-toggle-container" class="control-btn" title="Toggle Theme">
        <i id="themeIcon" class="fas fa-moon"></i>
        <span>Theme</span>
    </button>

    <div id="lang-selector-container" class="control-btn" title="Change Language">
        <i class="fas fa-globe"></i>
        <select id="language-selector">
            <option value="en">English</option>
            <option value="es">Español</option>
            <option value="yo">Yorùbá</option>
        </select>
    </div>

    <div id="toast" aria-live="polite"></div>

    <!-- PWA Install Popup -->
    <div id="pwa-install-popup">
        <div class="install-popup-content">
            <i class="fas fa-mobile-alt install-popup-icon"></i>
            <div class="install-popup-text">
                <p class="install-popup-title">Install CUPAD App</p>
                <p class="install-popup-desc">Get quick access from your home screen</p>
            </div>
        </div>
        <div class="install-popup-actions">
            <button class="install-btn" id="install-pwa-btn">Install</button>
            <button class="dismiss-btn" id="dismiss-install-btn">Later</button>
        </div>
    </div>

    <!-- Passkey Setup Popup -->
    <div id="passkey-setup-popup">
        <div class="install-popup-content">
            <i class="fas fa-fingerprint install-popup-icon"></i>
            <div class="install-popup-text">
                <p class="install-popup-title">Setup Fingerprint</p>
                <p class="install-popup-desc">Login faster next time using biometric.</p>
            </div>
        </div>
        <div class="install-popup-actions">
            <button class="install-btn" id="setup-passkey-btn">Setup Now</button>
            <button class="dismiss-btn" id="dismiss-passkey-btn">Not Now</button>
        </div>
    </div>

    <!-- LOCATION DENIED MODAL -->
    <div id="location-denied-modal" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="loc-modal-title">
        <div class="modal-content">
            <button class="modal-close-btn" id="modal-close-x" title="Close"><i class="fas fa-times"></i></button>

            <div class="modal-icon-badge">
                <i class="fas fa-location-dot"></i>
            </div>
            
            <h2 class="modal-title" id="loc-modal-title">Location Access Required</h2>
            <p class="modal-text">To log in to CUPAD, location permission must be enabled in your browser settings.</p>
            
            <div class="modal-steps">
                <div class="modal-step-item">
                    <span class="modal-step-badge">1</span>
                    <div class="modal-step-text">
                        Tap <strong>Site Controls</strong> <i class="fas fa-sliders modal-step-icon"></i> or <strong>Lock</strong> <i class="fas fa-lock modal-step-icon"></i> in your browser address bar.
                    </div>
                </div>
                
                <div class="modal-step-item">
                    <span class="modal-step-badge">2</span>
                    <div class="modal-step-text">
                        Set <strong>Location</strong> permission to <strong style="color:var(--success);">Allow</strong>.
                    </div>
                </div>

                <div class="modal-step-item">
                    <span class="modal-step-badge">3</span>
                    <div class="modal-step-text">
                        Tap <strong>Refresh Page</strong> below and attempt to log in again.
                    </div>
                </div>
            </div>
            
            <div class="modal-actions">
                <button id="reload-page-btn" class="btn-primary ripple-btn" style="height: 48px; font-size: 1rem;">
                    <i class="fas fa-arrows-rotate" style="margin-right: 8px;"></i> Refresh Page
                </button>
                <button id="close-location-modal" class="btn-secondary">Dismiss</button>
            </div>
        </div>
    </div>

    <div class="login-container">
        <div class="info-side">
            <h1 class="info-title" data-lang-key="infoTitle">CUPAD</h1>
            <p class="info-subtitle" data-lang-key="infoSubtitle">Welcome to the Comeup Poverty Alleviation Development. Your trusted partner in financial management and growth.</p>
            <ul class="info-features">
                <li><i class="fas fa-shield-alt"></i> <span data-lang-key="featureSecure">Secure & Reliable</span></li>
                <li><i class="fas fa-chart-line"></i> <span data-lang-key="featureGrowth">Drive Growth</span></li>
                <li><i class="fas fa-users"></i> <span data-lang-key="featureCommunity">Community Focused</span></li>
            </ul>
        </div>

        <div class="login-side">
            <div class="login-card">
                <div class="login-header">
                    <img src="uploads/CUPAD LOGO.png" alt="CUPAD Logo" class="login-logo">
                    <h1 class="login-title" data-lang-key="formTitle">Staff Login</h1>
                    <p class="login-subtitle" data-lang-key="formSubtitle">Sign in to continue to your dashboard</p>
                </div>

                <?php if ($error): ?>
                    <script>
                        document.addEventListener('DOMContentLoaded', () => showToast("<?= htmlspecialchars($error); ?>", 'error'));
                    </script>
                <?php endif; ?>

                <form action="index.php" method="POST" class="form-container" id="loginForm">
                    <input type="hidden" name="csrf_token" id="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

                    <!-- Honeypot -->
                    <div style="display:none; opacity:0; visibility:hidden;">
                        <input type="text" name="website_url" tabindex="-1" autocomplete="off">
                    </div>

                    <div class="input-group">
                        <input type="text" id="username" name="username" class="form-input" required autocomplete="username" placeholder=" "
                               value="<?= isset($_COOKIE['last_login_username']) ? htmlspecialchars($_COOKIE['last_login_username']) : '' ?>">
                        <label for="username" class="floating-label" data-lang-key="usernamePlaceholder">Username</label>
                        <i class="fas fa-user icon"></i>
                        <i class="fas fa-fingerprint passkey-btn" id="trigger-passkey-login" title="Login with Fingerprint"></i>
                    </div>

                    <div class="input-group">
                        <input type="password" id="password" name="password" class="form-input" required autocomplete="current-password" placeholder=" ">
                        <label for="password" class="floating-label" data-lang-key="passwordPlaceholder">Password</label>
                        <i class="fas fa-lock icon"></i>
                        <i class="fas fa-eye" id="show-password-toggle"></i>
                        <i class="fas fa-arrow-up caps-lock-icon" id="capsLockWarning" title="Caps Lock is ON"></i>
                    </div>
                    <div id="password-strength-meter"><div id="password-strength-bar"></div></div>

                    <div class="form-options">
                        <label class="remember-me">
                            <input type="checkbox" name="remember_me">
                            <span data-lang-key="rememberMe" style="margin-left:5px;">Remember Me</span>
                        </label>
                        <a href="forgot_password.php" class="forgot-password-link" data-lang-key="forgotPassword">Forgot Password?</a>
                    </div>

                    <!-- REAL-TIME LOCATION STATUS BANNER -->
                    <div id="location-status-banner" aria-live="polite" style="display: flex; align-items: center; gap: 10px; padding: 10px 14px; border-radius: 0.75rem; font-size: 0.85rem; font-weight: 500; background: rgba(150,150,150,0.08); color: var(--text-sub); border: 1px solid var(--border-glass); transition: all 0.3s ease;">
                        <i id="location-status-icon" class="fas fa-spinner fa-spin" style="color: var(--primary);"></i>
                        <span id="location-status-text">Checking location permission...</span>
                    </div>

                    <button type="submit" class="btn-primary ripple-btn" id="loginSubmitBtn" data-lang-key="loginButton">
                        <span id="loginButtonText">Login</span>
                    </button>
                </form>

                <div class="social-login-divider"><span>Or continue with</span></div>

                <div class="social-login-buttons">
                    <a href="google_auth" class="social-btn" title="Sign in with Google">
                        <img src="https://www.svgrepo.com/show/475656/google-color.svg" alt="Google" width="20" height="20">
                        <span>Google</span>
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="login-footer">
        &copy; <span id="year"></span> CUPAD System. All rights reserved.
    </div>

    <script>
        const isSecure = window.location.protocol === 'https:' || window.location.hostname === 'localhost';

        const bufferToBase64 = (buffer) => btoa(String.fromCharCode(...new Uint8Array(buffer))).replace(/\+/g, "-").replace(/\//g, "_").replace(/=+$/, "");
        const base64ToBuffer = (base64) => {
            const padding = "=".repeat((4 - (base64.length % 4)) % 4);
            const base64Safe = (base64 + padding).replace(/-/g, "+").replace(/_/g, "/");
            const rawData = atob(base64Safe);
            const outputArray = new Uint8Array(rawData.length);
            for (let i = 0; i < rawData.length; ++i) { outputArray[i] = rawData.charCodeAt(i); }
            return outputArray.buffer;
        };
        const challengeStr = "<?= $_SESSION['webauthn_challenge'] ?>";

        function showToast(message, type = 'error', duration = 5000) {
            const container = document.getElementById('toast');
            container.innerHTML = `<i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'}"></i><span>${message}</span>`;
            container.className = `toast ${type} show`;
            setTimeout(() => { container.className = container.className.replace('show', ''); }, duration);
        }

        function getTimeBasedGreeting() {
            const h = new Date().getHours();
            return h < 12 ? "Good Morning" : (h < 18 ? "Good Afternoon" : "Good Evening");
        }

        // --- CACHED LOCATION MANAGER & REAL-TIME BANNER ---
        let cachedPosition = null;
        let locationFetchPromise = null;

        function updateLocationBanner(state, customText) {
            const banner = document.getElementById('location-status-banner');
            const icon = document.getElementById('location-status-icon');
            const text = document.getElementById('location-status-text');

            if (!banner) return;

            if (state === 'acquiring') {
                banner.style.borderColor = 'var(--border-glass)';
                banner.style.background = 'rgba(150,150,150,0.08)';
                banner.style.color = 'var(--text-sub)';
                icon.className = 'fas fa-spinner fa-spin';
                icon.style.color = 'var(--primary)';
                text.textContent = customText || 'Checking location permission...';
            } 
            else if (state === 'success') {
                banner.style.borderColor = 'rgba(34, 197, 94, 0.4)';
                banner.style.background = 'rgba(34, 197, 94, 0.08)';
                banner.style.color = 'var(--success)';
                icon.className = 'fas fa-check-circle';
                icon.style.color = 'var(--success)';
                text.textContent = customText || 'Location permission active & verified.';
            } 
            else if (state === 'granted_no_gps') {
                banner.style.borderColor = 'rgba(34, 197, 94, 0.4)';
                banner.style.background = 'rgba(34, 197, 94, 0.08)';
                banner.style.color = 'var(--success)';
                icon.className = 'fas fa-check-circle';
                icon.style.color = 'var(--success)';
                text.textContent = customText || 'Location permission active (GPS signal pending).';
            }
            else if (state === 'denied') {
                banner.style.borderColor = 'rgba(239, 68, 68, 0.4)';
                banner.style.background = 'rgba(239, 68, 68, 0.08)';
                banner.style.color = 'var(--error)';
                icon.className = 'fas fa-exclamation-triangle';
                icon.style.color = 'var(--error)';
                text.innerHTML = customText || 'Location permission required to login. <a href="#" id="retry-loc-link" style="color: var(--primary); text-decoration: underline; font-weight: 600;">How to enable</a>';

                const retryBtn = document.getElementById('retry-loc-link');
                if (retryBtn) {
                    retryBtn.addEventListener('click', (e) => {
                        e.preventDefault();
                        document.getElementById('location-denied-modal').classList.add('show');
                    });
                }
            }
        }

        function prefetchLocation() {
            if (!navigator.geolocation) {
                updateLocationBanner('granted_no_gps', 'Location API unavailable on device.');
                return Promise.resolve({ permission: 'granted', coords: null });
            }

            updateLocationBanner('acquiring');

            locationFetchPromise = new Promise((resolve, reject) => {
                let isSettled = false;

                const safetyTimer = setTimeout(() => {
                    if (!isSettled) {
                        isSettled = true;
                        updateLocationBanner('granted_no_gps', 'Location access enabled (GPS signal pending).');
                        resolve({ permission: 'granted', coords: null });
                    }
                }, 3500);

                navigator.geolocation.getCurrentPosition(
                    (position) => {
                        if (!isSettled) {
                            isSettled = true;
                            clearTimeout(safetyTimer);
                            cachedPosition = position;
                            updateLocationBanner('success');
                            resolve({ permission: 'granted', coords: position.coords });
                        }
                    },
                    (error) => {
                        if (!isSettled) {
                            isSettled = true;
                            clearTimeout(safetyTimer);
                            if (error.code === error.PERMISSION_DENIED) {
                                updateLocationBanner('denied');
                                reject({ type: 'PERMISSION_DENIED', message: 'Location permission is required to login. Please enable location permissions.' });
                            } else {
                                updateLocationBanner('granted_no_gps', 'Location access enabled (GPS signal pending).');
                                resolve({ permission: 'granted', coords: null });
                            }
                        }
                    },
                    {
                        enableHighAccuracy: true,
                        timeout: 3000,
                        maximumAge: 60000
                    }
                );
            });

            locationFetchPromise.finally(() => {
                setTimeout(() => { locationFetchPromise = null; }, 5000);
            });

            return locationFetchPromise;
        }

        function requireLocation() {
            if (cachedPosition) {
                return Promise.resolve({ permission: 'granted', coords: cachedPosition.coords });
            }
            if (locationFetchPromise) {
                return locationFetchPromise;
            }
            return prefetchLocation();
        }

        if (navigator.permissions && navigator.permissions.query) {
            navigator.permissions.query({ name: 'geolocation' }).then((status) => {
                if (status.state === 'denied') {
                    updateLocationBanner('denied');
                }
                status.onchange = () => {
                    if (status.state === 'granted') {
                        prefetchLocation();
                    } else if (status.state === 'denied') {
                        updateLocationBanner('denied');
                    }
                };
            }).catch(() => {});
        }

        document.querySelectorAll('.ripple-btn').forEach(btn => {
            btn.addEventListener('click', function(e) {
                const circle = document.createElement('span');
                const diameter = Math.max(this.clientWidth, this.clientHeight);
                const radius = diameter / 2;
                circle.style.width = circle.style.height = `${diameter}px`;
                circle.style.left = `${e.clientX - (this.offsetLeft + this.offsetParent.offsetLeft) - radius}px`;
                circle.style.top = `${e.clientY - (this.offsetTop + this.offsetParent.offsetTop) - radius}px`;
                circle.classList.add('ripple');
                const ripple = this.getElementsByClassName('ripple')[0];
                if (ripple) ripple.remove();
                this.appendChild(circle);
            });
        });

        document.addEventListener('DOMContentLoaded', function() {
            prefetchLocation();

            const usernameInput = document.getElementById('username');
            if (usernameInput) {
                usernameInput.addEventListener('focus', () => {
                    if (!cachedPosition) prefetchLocation();
                }, { once: true });
            }

            // --- LOCATION MODAL UI HANDLERS ---
            const locModal = document.getElementById('location-denied-modal');
            const closeLocModal = () => locModal.classList.remove('show');

            document.getElementById('close-location-modal').addEventListener('click', closeLocModal);
            document.getElementById('modal-close-x').addEventListener('click', closeLocModal);
            document.getElementById('reload-page-btn').addEventListener('click', () => window.location.reload());

            locModal.addEventListener('click', (e) => {
                if (e.target === locModal) closeLocModal();
            });

            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape' && locModal.classList.contains('show')) closeLocModal();
            });

            // --- ADAPTIVE THEME LOGIC (PERSISTS TO LOCALSTORAGE FOR ALL DASHBOARDS) ---
            const themeToggle = document.getElementById('theme-toggle-container');
            const html = document.documentElement;
            const themeIcon = document.getElementById('themeIcon');
            const themeMeta = document.getElementById('theme-color-meta');

            function applyTheme(isDark) {
                const themeVal = isDark ? 'dark' : 'light';
                localStorage.setItem('theme', themeVal);

                if (isDark) {
                    html.classList.add('dark');
                    if (themeMeta) themeMeta.setAttribute('content', '#0f172a');
                    if (themeIcon) themeIcon.className = 'fas fa-sun';
                } else {
                    html.classList.remove('dark');
                    if (themeMeta) themeMeta.setAttribute('content', '#f3f4f6');
                    if (themeIcon) themeIcon.className = 'fas fa-moon';
                }
            }

            // Apply and persist on load
            applyTheme(html.classList.contains('dark'));

            themeToggle.addEventListener('click', () => {
                const isDark = !html.classList.contains('dark');
                applyTheme(isDark);
                initParticles(); 
            });

            // Auto-detect system changes in real time
            window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', e => {
                applyTheme(e.matches);
                initParticles();
            });

            // --- APP WELCOME / ONBOARDING SCREEN ---
            const welcomeScreen = document.getElementById('app-welcome-screen');
            const welcomeActionBtn = document.getElementById('welcome-action-btn');
            const skipBtn = document.getElementById('welcome-skip-btn');
            const slides = document.querySelectorAll('.welcome-slide');
            const dots = document.querySelectorAll('.welcome-dots .dot');
            let currentSlide = 0;

            const todayDateString = new Date().toISOString().split('T')[0];
            if (localStorage.getItem('cupad_app_started') === todayDateString) {
                welcomeScreen.style.display = 'none';
                document.getElementById('username').focus();
            }

            function updateCarousel(newIndex) {
                if(newIndex >= slides.length) return closeWelcomeScreen();
                slides.forEach((slide, idx) => {
                    slide.classList.remove('active', 'exit-left');
                    if (idx < newIndex) slide.classList.add('exit-left');
                    else if (idx === newIndex) slide.classList.add('active');
                });
                dots.forEach((dot, idx) => { dot.classList.toggle('active', idx === newIndex); });
                currentSlide = newIndex;
                welcomeActionBtn.textContent = currentSlide === slides.length - 1 ? 'Get Started' : 'Next';
            }

            function closeWelcomeScreen() {
                welcomeScreen.classList.add('hidden');
                localStorage.setItem('cupad_app_started', todayDateString);
                setTimeout(() => document.getElementById('username').focus(), 500);
            }

            welcomeActionBtn.addEventListener('click', () => updateCarousel(currentSlide + 1));
            skipBtn.addEventListener('click', closeWelcomeScreen);
            dots.forEach(dot => dot.addEventListener('click', (e) => updateCarousel(parseInt(e.target.dataset.slide))));

            if(!isSecure) { showToast('Fingerprint requires HTTPS or localhost.', 'error', 10000); }

            const loginForm = document.getElementById('loginForm');
            const loginButton = document.getElementById('loginSubmitBtn');
            const fullScreenLoader = document.getElementById('full-screen-loader');
            const loadingText = document.getElementById('loadingText');
            let pendingRedirectUrl = '';
            document.getElementById('year').textContent = new Date().getFullYear();

            // --- PASSKEY LOGIN HANDLER ---
            document.getElementById('trigger-passkey-login').addEventListener('click', async () => {
                if(!isSecure) return showToast('Fingerprint requires HTTPS.', 'error');

                try {
                    fullScreenLoader.classList.add('show');
                    loadingText.textContent = "Verifying Location Permission...";

                    let locResult;
                    try {
                        locResult = await requireLocation();
                    } catch (locErr) {
                        fullScreenLoader.classList.remove('show');
                        if (locErr && locErr.type === 'PERMISSION_DENIED') {
                            document.getElementById('location-denied-modal').classList.add('show');
                            showToast(locErr.message, 'error');
                            return;
                        }
                        locResult = { permission: 'granted', coords: null };
                    }

                    loadingText.textContent = "Verifying Fingerprint...";
                    const credential = await navigator.credentials.get({
                        publicKey: { challenge: base64ToBuffer(challengeStr), userVerification: "preferred" }
                    });

                    const formData = new FormData();
                    formData.append('action', 'login_passkey');
                    formData.append('credentialId', bufferToBase64(credential.rawId));
                    formData.append('location_status', locResult.permission);
                    
                    // Only append coordinates if GPS captured coordinates successfully
                    if (locResult.coords && locResult.coords.latitude && locResult.coords.longitude) {
                        formData.append('latitude', locResult.coords.latitude);
                        formData.append('longitude', locResult.coords.longitude);
                    }

                    loadingText.textContent = "Authenticating...";
                    const response = await fetch(window.location.href, { method: 'POST', body: formData });
                    const data = await response.json();

                    if(data.success) {
                        loadingText.innerHTML = `${getTimeBasedGreeting()}, <span style="color:var(--primary)">${data.user_name}</span>`;
                        setTimeout(() => { window.location.href = data.redirect; }, 1000);
                    } else {
                        showToast(data.message, 'error');
                        fullScreenLoader.classList.remove('show');
                    }
                } catch (e) {
                    fullScreenLoader.classList.remove('show');
                    console.error(e);
                    if (e.name === 'NotAllowedError') showToast('Request cancelled.', 'error');
                    else if (e.name === 'NotSupportedError') showToast('Device not supported.', 'error');
                    else showToast('Login failed.', 'error');
                }
            });

            // --- PASSKEY REGISTRATION HANDLER ---
            document.getElementById('setup-passkey-btn').addEventListener('click', async () => {
                document.getElementById('passkey-setup-popup').classList.remove('show');
                if(!isSecure) { showToast('Setup requires HTTPS.', 'error'); if(pendingRedirectUrl) window.location.href = pendingRedirectUrl; return; }

                try {
                    const inputUsername = document.getElementById('username').value.trim() || "User";
                    const userIdBuffer = new TextEncoder().encode(inputUsername);

                    const credential = await navigator.credentials.create({
                        publicKey: {
                            challenge: base64ToBuffer(challengeStr),
                            rp: { name: "CUPAD" },
                            user: { id: userIdBuffer, name: inputUsername, displayName: inputUsername },
                            pubKeyCredParams: [{ alg: -7, type: "public-key" }, { alg: -257, type: "public-key" }],
                            authenticatorSelection: { authenticatorAttachment: "platform", userVerification: "required", residentKey: "required", requireResidentKey: true },
                            timeout: 60000
                        }
                    });

                    const formData = new FormData();
                    formData.append('action', 'register_passkey');
                    formData.append('credentialId', bufferToBase64(credential.rawId));

                    const response = await fetch(window.location.href, { method: 'POST', body: formData });
                    const data = await response.json();

                    if(data.success) {
                        showToast('Fingerprint setup successfully!', 'success');
                        setTimeout(() => { if(pendingRedirectUrl) window.location.href = pendingRedirectUrl; else window.location.reload(); }, 1000);
                    } else {
                        showToast(data.message, 'error');
                        if(pendingRedirectUrl) window.location.href = pendingRedirectUrl;
                    }
                } catch (e) {
                    console.error(e);
                    showToast('Setup skipped.', 'error');
                    if(pendingRedirectUrl) window.location.href = pendingRedirectUrl;
                }
            });

            document.getElementById('dismiss-passkey-btn').addEventListener('click', () => {
                document.getElementById('passkey-setup-popup').classList.remove('show');
                localStorage.setItem('passkey_setup_dismissed', 'true');
                if(pendingRedirectUrl) window.location.href = pendingRedirectUrl;
                else window.location.reload();
            });

            // --- STANDARD LOGIN SUBMIT HANDLER WITH SEAMLESS RETRY ---
            async function performLogin(isAutoRetry = false) {
                if (!isAutoRetry) {
                    fullScreenLoader.classList.add('show');
                    loginButton.disabled = true;
                }

                try {
                    loadingText.textContent = "Verifying Location Permission...";
                    let locResult;
                    try {
                        locResult = await requireLocation();
                    } catch (locErr) {
                        fullScreenLoader.classList.remove('show');
                        loginButton.disabled = false;
                        
                        if (locErr && locErr.type === 'PERMISSION_DENIED') {
                            document.getElementById('location-denied-modal').classList.add('show');
                            showToast(locErr.message, 'error');
                            const formCard = document.querySelector('.login-card');
                            formCard.classList.add('shake-animation');
                            setTimeout(() => formCard.classList.remove('shake-animation'), 400);
                            return;
                        }
                        locResult = { permission: 'granted', coords: null };
                    }

                    const formData = new FormData(loginForm);
                    formData.append('location_status', locResult.permission);
                    
                    // Only append coordinates if GPS captured coordinates successfully
                    if (locResult.coords && locResult.coords.latitude && locResult.coords.longitude) {
                        formData.append('latitude', locResult.coords.latitude);
                        formData.append('longitude', locResult.coords.longitude);
                    }

                    loadingText.textContent = "Authenticating...";
                    const response = await fetch(window.location.href, {
                        method: 'POST', 
                        body: formData, 
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                        credentials: 'same-origin'
                    });
                    const data = await response.json();

                    // Automatically update hidden CSRF token whenever returned by server
                    if (data.new_csrf) {
                        document.getElementById('csrf_token').value = data.new_csrf;
                    }

                    if (data.success) {
                        pendingRedirectUrl = data.redirect;
                        if(data.prompt_passkey && isSecure && !localStorage.getItem('passkey_setup_dismissed')) {
                            fullScreenLoader.classList.remove('show');
                            document.getElementById('passkey-setup-popup').classList.add('show');
                        } else {
                            const greeting = getTimeBasedGreeting();
                            loadingText.innerHTML = `${greeting}, <span style="color:var(--primary)">${data.user_name}</span>`;
                            setTimeout(() => { window.location.href = data.redirect; }, 1800);
                        }
                    } else if (data.auto_retry && !isAutoRetry) {
                        // Silent token auto-retry: Resubmit immediately with updated CSRF token without showing any error to user
                        return await performLogin(true);
                    } else {
                        showToast(data.message, 'error');
                        fullScreenLoader.classList.remove('show');
                        loginButton.disabled = false;
                        const formCard = document.querySelector('.login-card');
                        formCard.classList.add('shake-animation');
                        setTimeout(() => formCard.classList.remove('shake-animation'), 400);
                    }
                } catch (error) {
                    fullScreenLoader.classList.remove('show');
                    loginButton.disabled = false;
                    showToast('Connection error. Retrying...', 'error');
                    
                    const formCard = document.querySelector('.login-card');
                    formCard.classList.add('shake-animation');
                    setTimeout(() => formCard.classList.remove('shake-animation'), 400);
                }
            }

            loginForm.addEventListener('submit', function(e) {
                e.preventDefault();
                performLogin();
            });

            // --- PWA LOGIC ---
            let deferredPrompt;
            const isStandalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone;
            if ('serviceWorker' in navigator) { window.addEventListener('load', () => { navigator.serviceWorker.register('sw.js'); }); }

            window.addEventListener('beforeinstallprompt', (e) => {
                e.preventDefault(); deferredPrompt = e;
                if (!isStandalone && !localStorage.getItem('pwa-dismissed')) { setTimeout(() => document.getElementById('pwa-install-popup').classList.add('show'), 3000); }
            });
            document.getElementById('install-pwa-btn').addEventListener('click', () => {
                if (deferredPrompt) { deferredPrompt.prompt(); deferredPrompt.userChoice.then(() => { document.getElementById('pwa-install-popup').classList.remove('show'); deferredPrompt = null; }); }
            });
            document.getElementById('dismiss-install-btn').addEventListener('click', () => { document.getElementById('pwa-install-popup').classList.remove('show'); localStorage.setItem('pwa-dismissed', 'true'); });
            window.addEventListener('appinstalled', () => { document.getElementById('pwa-install-popup').classList.remove('show'); });

            // UI Helpers
            window.addEventListener('offline', () => showToast('No internet connection', 'error'));
            window.addEventListener('online', () => showToast('Back online', 'success'));
            const pwdInput = document.getElementById('password');
            const capsIcon = document.getElementById('capsLockWarning');
            pwdInput.addEventListener('keyup', (e) => { if (e.getModifierState("CapsLock")) capsIcon.style.display = 'block'; else capsIcon.style.display = 'none'; });
            document.getElementById('show-password-toggle').addEventListener('click', function() { this.classList.toggle('fa-eye'); this.classList.toggle('fa-eye-slash'); pwdInput.type = pwdInput.type === 'password' ? 'text' : 'password'; });

            // Password Strength
            const strengthBar = document.getElementById('password-strength-bar');
            pwdInput.addEventListener('input', () => {
                const pass = pwdInput.value;
                let score = 0;
                if (pass.length > 8) score++;
                if (/[A-Z]/.test(pass)) score++;
                if (/[0-9]/.test(pass)) score++;
                if (/[^A-Za-z0-9]/.test(pass)) score++;
                let color = score >= 3 ? '#22c55e' : (score >= 2 ? '#f97316' : '#ef4444');
                strengthBar.style.width = (pass.length ? (score / 4 * 100) : 0) + '%';
                strengthBar.style.backgroundColor = color;
            });

            // --- OPTIMIZED PARTICLES ENGINE ---
            const canvas = document.getElementById('particle-container');
            const ctx = canvas.getContext('2d');
            let particles = [];
            let animationFrameId = null;
            let mouse = { x: null, y: null, radius: 150 };
            
            window.addEventListener('mousemove', (e) => { mouse.x = e.x; mouse.y = e.y; });
            window.addEventListener('touchmove', (e) => { mouse.x = e.touches[0].clientX; mouse.y = e.touches[0].clientY; });

            function initParticles() {
                canvas.width = window.innerWidth; canvas.height = document.body.scrollHeight; particles = [];
                const particleCount = Math.min(Math.floor(canvas.width * canvas.height / 18000), 60);
                for(let i=0; i<particleCount; i++) {
                    particles.push({
                        x: Math.random()*canvas.width, y: Math.random()*canvas.height,
                        size: Math.random()*2+1, density: (Math.random()*30)+1,
                        dirX: Math.random()*0.4-0.2, dirY: Math.random()*0.4-0.2,
                        color: html.classList.contains('dark')?'rgba(255,255,255,0.6)':'rgba(0,0,0,0.6)'
                    });
                }
            }

            function animate() {
                if (document.hidden) return;
                animationFrameId = requestAnimationFrame(animate); 
                ctx.clearRect(0,0,canvas.width,canvas.height);
                particles.forEach(p => {
                    p.x += p.dirX; p.y += p.dirY;
                    let dx = mouse.x - p.x; let dy = mouse.y - p.y;
                    let distance = Math.sqrt(dx*dx + dy*dy);
                    if (distance < mouse.radius) {
                        const forceDirectionX = dx / distance; const forceDirectionY = dy / distance;
                        const force = (mouse.radius - distance) / mouse.radius;
                        const dirX = forceDirectionX * force * p.density; const dirY = forceDirectionY * force * p.density;
                        p.x -= dirX; p.y -= dirY;
                    }
                    if(p.x<0||p.x>canvas.width)p.dirX*=-1; if(p.y<0||p.y>canvas.height)p.dirY*=-1;
                    ctx.beginPath(); ctx.arc(p.x,p.y,p.size,0,Math.PI*2); ctx.fillStyle=p.color; ctx.fill();
                });
            }

            document.addEventListener('visibilitychange', () => {
                if (document.hidden) {
                    cancelAnimationFrame(animationFrameId);
                } else {
                    animate();
                }
            });

            window.addEventListener('resize', initParticles); initParticles(); animate();
        });
    </script>
</body>
</html>