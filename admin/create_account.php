<?php
session_start();

// --- 1. CONFIGURATION & DATABASE CONNECTION ---
if (file_exists('../includes/config.php')) {
    require_once '../includes/config.php';
    $pdo = function_exists('getDbConnection') ? getDbConnection() : null;
}

if (!$pdo) {
    define('DB_HOST', 'localhost');
    define('DB_NAME', 'cupadnam_db');
    define('DB_USER', 'cupadnam_db');
    define('DB_PASS', 'f2GrjZQCz8E39nCu9eLg');
    define('DB_CHARSET', 'utf8mb4');

    try {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
    } catch (PDOException $e) {
        die("Database Connection Failed: " . $e->getMessage());
    }
}

// Redirect / Reject if not an admin
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    if (isset($_POST['ajax_action'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Unauthorized session. Please log in again.']);
        exit();
    }
    header('Location: ../index.php');
    exit();
}

// CSRF Token Initialization
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// --- 2. AJAX LIVE ENDPOINTS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $action = $_POST['ajax_action'];

    // Live Username Availability Check
    if ($action === 'check_username') {
        $username = trim($_POST['username'] ?? '');
        if ($username === '') {
            echo json_encode(['success' => false, 'message' => 'Username cannot be empty.']);
            exit();
        }

        try {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
            $stmt->execute([$username]);
            $exists = $stmt->rowCount() > 0;

            echo json_encode([
                'success' => true,
                'available' => !$exists,
                'message' => $exists ? 'Username is already taken' : 'Username is available'
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Error checking username.']);
        }
        exit();
    }

    // Live Account Creation
    if ($action === 'create_account') {
        // Validate CSRF
        $client_token = $_POST['csrf_token'] ?? '';
        if (!hash_equals($_SESSION['csrf_token'], $client_token)) {
            echo json_encode(['success' => false, 'message' => 'Security token expired. Please refresh the page.']);
            exit();
        }

        $name = trim($_POST['name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $role = trim($_POST['role'] ?? 'co');
        
        $zone_id = isset($_POST['zone']) && trim($_POST['zone']) !== '' ? trim($_POST['zone']) : null;
        $area_id = isset($_POST['area']) && trim($_POST['area']) !== '' ? trim($_POST['area']) : null;
        $branch_id = isset($_POST['branch']) && trim($_POST['branch']) !== '' ? trim($_POST['branch']) : null;
        
        $profile_pic = 'default_avatar.png';

        if ($name === '' || $username === '' || $password === '') {
            echo json_encode(['success' => false, 'message' => 'Please fill in all required credentials.']);
            exit();
        }

        // Validate Role Hierarchy Requirements
        if (in_array($role, ['co', 'bm']) && ($branch_id === null || $branch_id === '')) {
            echo json_encode(['success' => false, 'message' => 'Please select a Branch for Credit Officers and Branch Managers.']);
            exit();
        }
        if ($role === 'am' && ($area_id === null || $area_id === '')) {
            echo json_encode(['success' => false, 'message' => 'Please select an Area for Area Managers.']);
            exit();
        }
        if (in_array($role, ['zm', 'dzm']) && ($zone_id === null || $zone_id === '')) {
            echo json_encode(['success' => false, 'message' => 'Please select a Zone for Zone Managers.']);
            exit();
        }

        // Auto-resolve missing parent hierarchy IDs
        if ($branch_id) {
            $b_stmt = $pdo->prepare("
                SELECT b.area_id, a.zone_id 
                FROM branches b 
                LEFT JOIN areas a ON b.area_id = a.id 
                WHERE b.id = ? OR b.name = ?
            ");
            $b_stmt->execute([$branch_id, $branch_id]);
            $loc = $b_stmt->fetch(PDO::FETCH_ASSOC);
            if ($loc) {
                if (!$area_id && !empty($loc['area_id'])) $area_id = $loc['area_id'];
                if (!$zone_id && !empty($loc['zone_id'])) $zone_id = $loc['zone_id'];
            }
        } elseif ($area_id) {
            $a_stmt = $pdo->prepare("SELECT zone_id FROM areas WHERE id = ? OR name = ?");
            $a_stmt->execute([$area_id, $area_id]);
            $loc_zone = $a_stmt->fetchColumn();
            if (!$zone_id && $loc_zone) {
                $zone_id = $loc_zone;
            }
        }

        // Profile Picture Upload Handler
        if (isset($_FILES['profile_pic']) && $_FILES['profile_pic']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = __DIR__ . '/../uploads/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            $file_tmp = $_FILES['profile_pic']['tmp_name'];
            $file_name = basename($_FILES['profile_pic']['name']);
            $file_size = $_FILES['profile_pic']['size'];
            $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
            $allowed_ext = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

            if ($file_size > 2 * 1024 * 1024) {
                echo json_encode(['success' => false, 'message' => 'Avatar image size must not exceed 2MB.']);
                exit();
            } elseif (!in_array($file_ext, $allowed_ext)) {
                echo json_encode(['success' => false, 'message' => 'Invalid image format. Allowed: JPG, PNG, WEBP, GIF.']);
                exit();
            } else {
                $safe_prefix = preg_replace('/[^a-zA-Z0-9]/', '', $username);
                if (empty($safe_prefix)) $safe_prefix = 'user';
                $new_file_name = $safe_prefix . '_' . time() . '.' . $file_ext;
                $target_path = $upload_dir . $new_file_name;
                if (move_uploaded_file($file_tmp, $target_path)) {
                    $profile_pic = 'uploads/' . $new_file_name;
                }
            }
        }

        try {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
            $stmt->execute([$username]);
            
            if ($stmt->rowCount() > 0) {
                echo json_encode(['success' => false, 'message' => "Username '{$username}' is already taken. Please choose another."]);
                exit();
            }

            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $sql = "INSERT INTO users (username, password, name, full_name, role, branch_id, area_id, zone_id, profile_pic, status, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', NOW())";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$username, $hashed_password, $name, $name, $role, $branch_id, $area_id, $zone_id, $profile_pic]);

            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

            echo json_encode([
                'success' => true,
                'message' => "Account for '{$name}' (@{$username}) was provisioned successfully!",
                'new_csrf_token' => $_SESSION['csrf_token']
            ]);
            exit();
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
            exit();
        }
    }
}

// --- 3. METADATA FETCHING ---
$current_username = $_SESSION['username'] ?? '';
$full_name = $_SESSION['full_name'] ?? 'Admin';
$current_role = $_SESSION['user_role'] ?? 'admin';
$base_path = '../';
$profile_pic_path = '';
$has_profile_pic = false;

try {
    $stmt = $pdo->prepare("SELECT profile_pic, full_name FROM users WHERE username = ?");
    $stmt->execute([$current_username]);
    $currentUser = $stmt->fetch();

    if ($currentUser) {
        $full_name = $currentUser['full_name'];
        if (!empty($currentUser['profile_pic']) && $currentUser['profile_pic'] !== 'default_avatar.png' && file_exists($base_path . $currentUser['profile_pic'])) {
            $profile_pic_path = $base_path . $currentUser['profile_pic'];
            $has_profile_pic = true;
        }
    }

    $zones = $pdo->query("SELECT id, name FROM zones ORDER BY name ASC")->fetchAll();
    $areas = $pdo->query("SELECT id, name, zone_id FROM areas ORDER BY name ASC")->fetchAll();
    $branches = $pdo->query("SELECT id, name, area_id FROM branches WHERE status = 'active' ORDER BY name ASC")->fetchAll();
} catch (Exception $e) {
    $zones = []; $areas = []; $branches = [];
}

$hour = date('H');
if ($hour < 12) $greeting = "Good Morning";
elseif ($hour < 17) $greeting = "Good Afternoon";
else $greeting = "Good Evening";
?>
<!DOCTYPE html>
<html lang="en" class="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Provision User — CUPAD Admin</title>
    <link rel="icon" type="image/png" href="<?php echo htmlspecialchars($base_path . 'uploads/CUPAD LOGO.png'); ?>">
    
    <!-- Fonts & Icons -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />

    <style>
        :root {
            --primary: #2563eb;
            --primary-hover: #1d4ed8;
            --primary-gradient: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            --primary-soft: rgba(37, 99, 235, 0.08);
            --primary-border: rgba(37, 99, 235, 0.25);
            
            --success: #10b981;
            --success-soft: rgba(16, 185, 129, 0.1);
            --warning: #f59e0b;
            --warning-soft: rgba(245, 158, 11, 0.1);
            --danger: #ef4444;
            --danger-soft: rgba(239, 68, 68, 0.1);
            --info: #06b6d4;
            
            --bg-body: #f8fafc;
            --bg-surface: #ffffff;
            --bg-subtle: #f1f5f9;
            --bg-card-hover: #f8fafc;
            
            --text-main: #0f172a;
            --text-muted: #64748b;
            --text-subtle: #94a3b8;
            --border: #e2e8f0;
            --border-hover: #cbd5e1;
            
            --radius-2xl: 20px;
            --radius-xl: 16px;
            --radius-lg: 12px;
            --radius-md: 8px;
            --radius-sm: 6px;
            
            --shadow-xs: 0 1px 2px 0 rgba(0, 0, 0, 0.04);
            --shadow-sm: 0 4px 6px -1px rgba(0, 0, 0, 0.04), 0 2px 4px -2px rgba(0, 0, 0, 0.04);
            --shadow-md: 0 10px 15px -3px rgba(0, 0, 0, 0.06), 0 4px 6px -4px rgba(0, 0, 0, 0.04);
            --shadow-lg: 0 20px 25px -5px rgba(0, 0, 0, 0.08), 0 8px 10px -6px rgba(0, 0, 0, 0.04);
            
            --nav-height: 70px;
            --transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        }

        html.dark {
            --bg-body: #090d16;
            --bg-surface: #111827;
            --bg-subtle: #1a2234;
            --bg-card-hover: #162032;
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
            --text-subtle: #64748b;
            --border: #1f293d;
            --border-hover: #334155;
            --primary: #3b82f6;
            --primary-hover: #60a5fa;
            --primary-gradient: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
            --primary-soft: rgba(59, 130, 246, 0.15);
            --primary-border: rgba(59, 130, 246, 0.35);
            --shadow-sm: 0 4px 6px -1px rgba(0, 0, 0, 0.3);
            --shadow-md: 0 10px 15px -3px rgba(0, 0, 0, 0.4);
            --shadow-lg: 0 20px 25px -5px rgba(0, 0, 0, 0.5);
        }

        * { box-sizing: border-box; outline: none; margin: 0; padding: 0; }
        body { 
            font-family: 'Plus Jakarta Sans', sans-serif; 
            background: var(--bg-body); 
            color: var(--text-main); 
            padding-top: var(--nav-height); 
            min-height: 100vh;
            line-height: 1.6;
            transition: background-color 0.3s ease, color 0.3s ease;
            -webkit-font-smoothing: antialiased;
        }
        a { text-decoration: none; color: inherit; }

        /* Header Navigation */
        .main-header { 
            position: fixed; 
            top: 0; left: 0; right: 0; 
            height: var(--nav-height); 
            background: rgba(255, 255, 255, 0.85); 
            backdrop-filter: blur(16px); 
            -webkit-backdrop-filter: blur(16px); 
            border-bottom: 1px solid var(--border); 
            z-index: 100; 
            transition: var(--transition);
        }
        html.dark .main-header { background: rgba(17, 24, 39, 0.85); }
        
        .navbar { 
            max-width: 1200px; 
            margin: 0 auto; 
            padding: 0 1.5rem; 
            display: flex; 
            align-items: center; 
            justify-content: space-between; 
            height: 100%; 
        }
        .logo { 
            display: flex; 
            align-items: center; 
            gap: 0.75rem; 
            font-weight: 800; 
            font-size: 1.25rem; 
            color: var(--primary); 
            letter-spacing: -0.02em;
        }
        .logo img { height: 36px; width: auto; object-fit: contain; }

        .nav-right { display: flex; align-items: center; gap: 0.85rem; }
        .icon-btn { 
            width: 38px; height: 38px; 
            border-radius: 50%; 
            border: 1px solid var(--border); 
            background: var(--bg-surface); 
            color: var(--text-muted); 
            cursor: pointer; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            font-size: 0.95rem; 
            transition: var(--transition); 
        }
        .icon-btn:hover { background: var(--bg-subtle); color: var(--text-main); transform: translateY(-1px); }

        .user-dropdown-wrap { position: relative; }
        .user-pill { 
            display: flex; 
            align-items: center; 
            gap: 0.65rem; 
            padding: 4px 12px 4px 4px; 
            border: 1px solid var(--border); 
            border-radius: 99px; 
            background: var(--bg-surface); 
            cursor: pointer; 
            transition: var(--transition); 
        }
        .user-pill:hover { border-color: var(--primary); box-shadow: var(--shadow-xs); }
        .user-avatar { width: 32px; height: 32px; border-radius: 50%; object-fit: cover; }
        .user-avatar-fallback { 
            width: 32px; height: 32px; 
            border-radius: 50%; 
            background: var(--bg-subtle); 
            color: var(--text-muted); 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            font-size: 0.85rem; 
        }
        .user-info { display: flex; flex-direction: column; line-height: 1.15; }
        .user-name { font-weight: 700; font-size: 0.82rem; }
        .user-role { font-size: 0.65rem; color: var(--text-muted); text-transform: uppercase; font-weight: 600; }

        .dropdown-menu { 
            position: absolute; 
            top: calc(100% + 8px); 
            right: 0; 
            background: var(--bg-surface); 
            border: 1px solid var(--border); 
            border-radius: var(--radius-lg); 
            box-shadow: var(--shadow-lg); 
            min-width: 200px; 
            display: none; 
            z-index: 1000; 
            flex-direction: column; 
            overflow: hidden; 
            padding: 0.4rem; 
        }
        .dropdown-menu.show { display: flex; animation: slideIn 0.15s ease-out; }
        @keyframes slideIn { from { opacity: 0; transform: translateY(-6px); } to { opacity: 1; transform: translateY(0); } }
        .dropdown-item { 
            padding: 0.65rem 0.85rem; 
            display: flex; 
            align-items: center; 
            gap: 0.65rem; 
            font-size: 0.85rem; 
            font-weight: 500; 
            color: var(--text-main); 
            border-radius: var(--radius-md); 
            transition: var(--transition); 
        }
        .dropdown-item:hover { background: var(--primary-soft); color: var(--primary); }
        .dropdown-item.text-danger:hover { background: var(--danger-soft); color: var(--danger); }
        .dropdown-divider { height: 1px; background: var(--border); margin: 0.3rem 0; }

        /* Container Layout */
        .container { max-width: 980px; margin: 0 auto; padding: 2.25rem 1.5rem 5rem; }

        .page-header { margin-bottom: 2rem; }
        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.82rem;
            color: var(--text-muted);
            margin-bottom: 0.5rem;
            font-weight: 500;
        }
        .breadcrumb a:hover { color: var(--primary); }
        .page-title {
            font-size: 1.85rem;
            font-weight: 800;
            letter-spacing: -0.03em;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        .page-subtitle { font-size: 0.9rem; color: var(--text-muted); margin-top: 0.35rem; }

        /* Quick Navigation Shortcuts */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }
        .action-card {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem 1.25rem;
            background: var(--bg-surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-xl);
            box-shadow: var(--shadow-xs);
            transition: var(--transition);
        }
        .action-card:hover {
            border-color: var(--primary-border);
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }
        .ac-icon {
            width: 44px; height: 44px;
            border-radius: var(--radius-lg);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            flex-shrink: 0;
        }
        .ac-icon.blue { background: var(--primary-soft); color: var(--primary); }
        .ac-icon.green { background: var(--success-soft); color: var(--success); }
        .ac-icon.purple { background: rgba(139, 92, 246, 0.12); color: #8b5cf6; }

        /* Main Form Card */
        .card { 
            background: var(--bg-surface); 
            border: 1px solid var(--border); 
            border-radius: var(--radius-2xl); 
            padding: 2.25rem; 
            box-shadow: var(--shadow-sm); 
        }

        .form-section {
            margin-bottom: 2.25rem;
            padding-bottom: 2.25rem;
            border-bottom: 1px solid var(--border);
        }
        .form-section:last-of-type {
            margin-bottom: 1.5rem;
            padding-bottom: 0;
            border-bottom: none;
        }

        .section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1.4rem;
        }
        .section-title {
            font-size: 0.92rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--primary);
            font-weight: 800;
            display: flex;
            align-items: center;
            gap: 0.6rem;
        }
        .section-badge {
            font-size: 0.72rem;
            padding: 0.2rem 0.65rem;
            border-radius: 99px;
            background: var(--primary-soft);
            color: var(--primary);
            font-weight: 700;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.25rem;
        }
        @media (max-width: 680px) {
            .form-grid { grid-template-columns: 1fr; }
            .card { padding: 1.5rem; }
        }

        .form-group { margin-bottom: 1.25rem; }
        .form-group:last-child { margin-bottom: 0; }

        .form-label {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 0.45rem;
            font-weight: 600;
            font-size: 0.86rem;
            color: var(--text-main);
        }
        .form-label .helper-link {
            font-size: 0.78rem;
            color: var(--primary);
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
        }
        .form-label .helper-link:hover { text-decoration: underline; }

        .input-wrap {
            position: relative;
            display: flex;
            align-items: center;
        }
        .input-icon {
            position: absolute;
            left: 1rem;
            color: var(--text-muted);
            font-size: 0.95rem;
            pointer-events: none;
            transition: var(--transition);
        }

        .form-input, .form-select {
            width: 100%;
            padding: 0.75rem 1rem 0.75rem 2.8rem;
            border-radius: var(--radius-lg);
            border: 1.5px solid var(--border);
            background: var(--bg-subtle);
            color: var(--text-main);
            font-family: inherit;
            font-size: 0.9rem;
            font-weight: 500;
            transition: var(--transition);
        }
        .form-input:focus, .form-select:focus {
            border-color: var(--primary);
            background: var(--bg-surface);
            box-shadow: 0 0 0 4px var(--primary-soft);
        }
        .form-input:focus ~ .input-icon, .form-select:focus ~ .input-icon {
            color: var(--primary);
        }

        .form-select {
            appearance: none;
            cursor: pointer;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%2364748b' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 1.1rem center;
            background-size: 1.1rem;
            padding-right: 2.75rem;
        }
        .form-select:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            background-color: var(--bg-subtle);
        }

        /* Live Username Badge */
        .live-status-badge {
            font-size: 0.76rem;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            margin-top: 0.4rem;
            padding: 0.2rem 0.6rem;
            border-radius: 99px;
            transition: var(--transition);
        }
        .live-status-badge.available { background: var(--success-soft); color: var(--success); }
        .live-status-badge.taken { background: var(--danger-soft); color: var(--danger); }
        .live-status-badge.checking { background: var(--bg-subtle); color: var(--text-muted); }

        /* Password Controls & Strength Bar */
        .password-toggle-btn {
            position: absolute;
            right: 0.75rem;
            width: 32px; height: 32px;
            border: none;
            background: transparent;
            color: var(--text-muted);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: var(--radius-sm);
            transition: var(--transition);
        }
        .password-toggle-btn:hover { color: var(--primary); background: var(--bg-subtle); }

        .strength-container {
            margin-top: 0.45rem;
            display: none;
        }
        .strength-bar-track {
            height: 5px;
            width: 100%;
            background: var(--border);
            border-radius: 99px;
            overflow: hidden;
            display: flex;
            gap: 2px;
        }
        .strength-segment {
            flex: 1;
            height: 100%;
            background: transparent;
            transition: var(--transition);
        }
        .strength-text {
            font-size: 0.72rem;
            font-weight: 700;
            margin-top: 0.25rem;
            display: flex;
            justify-content: space-between;
        }

        /* Interactive Role Selection Cards */
        .role-picker-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
            gap: 0.75rem;
            margin-bottom: 1.25rem;
        }
        .role-card {
            border: 1.5px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 0.85rem 1rem;
            background: var(--bg-subtle);
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            flex-direction: column;
            gap: 0.35rem;
            position: relative;
        }
        .role-card:hover {
            border-color: var(--primary-border);
            background: var(--bg-surface);
            transform: translateY(-2px);
        }
        .role-card.selected {
            border-color: var(--primary);
            background: var(--primary-soft);
            box-shadow: 0 0 0 2px var(--primary);
        }
        .role-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-weight: 700;
            font-size: 0.88rem;
        }
        .role-card-desc {
            font-size: 0.74rem;
            color: var(--text-muted);
            line-height: 1.35;
        }
        .role-scope-tag {
            font-size: 0.68rem;
            font-weight: 700;
            color: var(--primary);
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        /* Hierarchy Chain Box */
        .hierarchy-box {
            background: var(--bg-subtle);
            border: 1.5px solid var(--border);
            border-radius: var(--radius-xl);
            padding: 1.4rem;
            margin-top: 1.25rem;
            display: none;
            animation: fadeIn 0.25s ease-out;
        }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(-6px); } to { opacity: 1; transform: translateY(0); } }

        .hierarchy-chain-preview {
            display: flex;
            align-items: center;
            gap: 0.6rem;
            margin-bottom: 1.25rem;
            padding: 0.65rem 1rem;
            background: var(--bg-surface);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            font-size: 0.78rem;
            font-weight: 700;
            overflow-x: auto;
        }
        .chain-step {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            color: var(--text-muted);
        }
        .chain-step.active { color: var(--primary); }
        .chain-step.completed { color: var(--success); }

        /* Profile Avatar Drag Zone */
        .upload-card {
            border: 2px dashed var(--border);
            border-radius: var(--radius-xl);
            padding: 1.75rem 1.5rem;
            text-align: center;
            cursor: pointer;
            background: var(--bg-subtle);
            transition: var(--transition);
            position: relative;
        }
        .upload-card:hover, .upload-card.drag-over {
            border-color: var(--primary);
            background: var(--primary-soft);
        }
        .upload-circle {
            width: 58px; height: 58px;
            border-radius: 50%;
            background: var(--primary-soft);
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.35rem;
            margin: 0 auto 0.75rem;
            transition: var(--transition);
        }
        .upload-card:hover .upload-circle { transform: scale(1.08); }

        .preview-wrapper {
            display: none;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            gap: 0.5rem;
        }
        .preview-img {
            width: 86px; height: 86px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid var(--primary);
            box-shadow: var(--shadow-md);
        }
        .remove-avatar-btn {
            font-size: 0.75rem;
            color: var(--danger);
            font-weight: 700;
            cursor: pointer;
            padding: 0.2rem 0.6rem;
            border-radius: 99px;
            background: var(--danger-soft);
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            margin-top: 0.25rem;
        }
        .remove-avatar-btn:hover { opacity: 0.85; }

        /* Submission Bar */
        .form-actions {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-top: 2rem;
        }
        .btn-submit {
            flex: 1;
            padding: 0.95rem 1.5rem;
            background: var(--primary-gradient);
            color: #ffffff;
            border: none;
            border-radius: var(--radius-lg);
            font-size: 0.96rem;
            font-weight: 800;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.6rem;
            box-shadow: 0 4px 14px rgba(37, 99, 235, 0.3);
        }
        .btn-submit:hover {
            transform: translateY(-1px);
            box-shadow: 0 8px 20px rgba(37, 99, 235, 0.4);
        }
        .btn-submit:disabled {
            opacity: 0.7;
            cursor: not-allowed;
            transform: none;
        }

        .btn-reset {
            padding: 0.95rem 1.25rem;
            background: var(--bg-subtle);
            color: var(--text-muted);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            font-size: 0.9rem;
            font-weight: 700;
            cursor: pointer;
            transition: var(--transition);
        }
        .btn-reset:hover { background: var(--border); color: var(--text-main); }

        /* Toast Messages */
        .toast-container {
            position: fixed;
            top: 85px;
            right: 24px;
            z-index: 1000;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .toast {
            background: var(--bg-surface);
            color: var(--text-main);
            padding: 0.95rem 1.3rem;
            border-radius: var(--radius-xl);
            box-shadow: var(--shadow-lg);
            border: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-size: 0.88rem;
            font-weight: 600;
            min-width: 330px;
            animation: slideIn 0.3s cubic-bezier(0.16, 1, 0.3, 1) forwards;
        }
        .toast.success { border-left: 4px solid var(--success); }
        .toast.success i { color: var(--success); }
        .toast.error { border-left: 4px solid var(--danger); }
        .toast.error i { color: var(--danger); }
    </style>
</head>
<body>

    <!-- Header Navigation -->
    <header class="main-header">
        <div class="navbar">
            <a href="dashboard.php" class="logo">
                <img src="<?php echo htmlspecialchars($base_path . 'uploads/CUPAD LOGO.png'); ?>" alt="CUPAD Logo">
                <span>CUPAD Admin</span>
            </a>
            <div class="nav-right">
                <button class="icon-btn" id="themeToggle" title="Toggle Dark/Light Theme">
                    <i class="fas fa-moon"></i>
                </button>
                <div class="user-dropdown-wrap">
                    <div class="user-pill" id="userDropdownTrigger">
                        <?php if ($has_profile_pic): ?>
                            <img src="<?php echo htmlspecialchars($profile_pic_path); ?>" class="user-avatar" alt="Avatar">
                        <?php else: ?>
                            <div class="user-avatar-fallback"><i class="fas fa-user"></i></div>
                        <?php endif; ?>
                        <div class="user-info">
                            <span class="user-name"><?php echo htmlspecialchars($full_name); ?></span>
                            <span class="user-role"><?php echo htmlspecialchars($current_role); ?></span>
                        </div>
                        <i class="fas fa-chevron-down" style="font-size:0.68rem; color:var(--text-muted);"></i>
                    </div>
                    <div class="dropdown-menu" id="userDropdown">
                        <a href="profile.php" class="dropdown-item"><i class="fas fa-user-circle"></i> My Profile</a>
                        <div class="dropdown-divider"></div>
                        <a href="<?php echo htmlspecialchars($base_path . 'logout.php'); ?>" class="dropdown-item text-danger"><i class="fas fa-sign-out-alt"></i> Sign Out</a>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <div id="toastContainer" class="toast-container"></div>

    <main class="container">
        
        <!-- Header & Breadcrumbs -->
        <div class="page-header">
            <div class="breadcrumb">
                <a href="dashboard.php">Dashboard</a>
                <i class="fas fa-chevron-right" style="font-size:0.6rem;"></i>
                <a href="manage_users.php">Users Directory</a>
                <i class="fas fa-chevron-right" style="font-size:0.6rem;"></i>
                <span>Provision User</span>
            </div>
            <h1 class="page-title">
                <i class="fas fa-user-plus" style="color: var(--primary);"></i>
                Provision New User Account
            </h1>
            <p class="page-subtitle"><?php echo htmlspecialchars($greeting); ?>, <?php echo htmlspecialchars(explode(' ', $full_name)[0]); ?>. Assign credentials, roles, and automated jurisdictional scope.</p>
        </div>

        <!-- Quick Action Shortcuts -->
        <div class="quick-actions">
            <a href="dashboard.php" class="action-card">
                <div class="ac-icon blue"><i class="fas fa-arrow-left"></i></div>
                <div>
                    <h4 style="font-size:0.88rem; font-weight:700;">Dashboard</h4>
                    <p style="font-size:0.75rem; color:var(--text-muted);">Return to overview</p>
                </div>
            </a>
            <a href="manage_users.php" class="action-card">
                <div class="ac-icon green"><i class="fas fa-users-gear"></i></div>
                <div>
                    <h4 style="font-size:0.88rem; font-weight:700;">Directory</h4>
                    <p style="font-size:0.75rem; color:var(--text-muted);">View all staff & clients</p>
                </div>
            </a>
            <a href="assign_role.php" class="action-card">
                <div class="ac-icon purple"><i class="fas fa-shield-halved"></i></div>
                <div>
                    <h4 style="font-size:0.88rem; font-weight:700;">Permissions</h4>
                    <p style="font-size:0.75rem; color:var(--text-muted);">Role privilege matrix</p>
                </div>
            </a>
        </div>

        <!-- User Form Card -->
        <div class="card">
            <form id="createAccountForm" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" id="csrfTokenInput" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="ajax_action" value="create_account">
                <input type="hidden" name="role" id="selectedRoleInput" value="co">

                <!-- SECTION 1: Personal & Login Credentials -->
                <div class="form-section">
                    <div class="section-header">
                        <div class="section-title"><i class="fas fa-id-card"></i> 1. Identity & Credentials</div>
                        <span class="section-badge">Required</span>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="fullNameInput">
                            <span>Full Legal Name <span style="color:var(--danger)">*</span></span>
                        </label>
                        <div class="input-wrap">
                            <input type="text" name="name" id="fullNameInput" required class="form-input" placeholder="e.g. Samuel Olawale Adeyemi" autocomplete="name">
                            <i class="fas fa-user input-icon"></i>
                        </div>
                    </div>

                    <div class="form-grid">
                        <!-- Username with Live Checker -->
                        <div class="form-group">
                            <label class="form-label" for="usernameInput">
                                <span>Username <span style="color:var(--danger)">*</span></span>
                                <span class="helper-link" onclick="generateRandomUsername()"><i class="fas fa-wand-magic-sparkles"></i> Suggest</span>
                            </label>
                            <div class="input-wrap">
                                <input type="text" name="username" id="usernameInput" required class="form-input" placeholder="Enter username..." autocomplete="username">
                                <i class="fas fa-at input-icon"></i>
                            </div>
                            <div id="usernameLiveStatus" class="live-status-badge" style="display:none;"></div>
                        </div>

                        <!-- Password with Strength Gauge -->
                        <div class="form-group">
                            <label class="form-label" for="passwordInput">
                                <span>Password <span style="color:var(--danger)">*</span></span>
                                <span class="helper-link" onclick="generateStrongPassword()"><i class="fas fa-key"></i> Generate</span>
                            </label>
                            <div class="input-wrap">
                                <input type="password" name="password" id="passwordInput" required class="form-input" placeholder="Create strong password...">
                                <i class="fas fa-lock input-icon"></i>
                                <button type="button" class="password-toggle-btn" onclick="togglePasswordVisibility()" title="Toggle visibility">
                                    <i class="fas fa-eye" id="eyeIcon"></i>
                                </button>
                            </div>
                            <div class="strength-container" id="strengthContainer">
                                <div class="strength-bar-track">
                                    <div class="strength-segment" id="seg1"></div>
                                    <div class="strength-segment" id="seg2"></div>
                                    <div class="strength-segment" id="seg3"></div>
                                    <div class="strength-segment" id="seg4"></div>
                                </div>
                                <div class="strength-text">
                                    <span id="strengthLabel" style="color: var(--text-muted);">Strength</span>
                                    <span id="strengthHint" style="color: var(--text-subtle);">Use 8+ chars</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- SECTION 2: Role Selection & Scope -->
                <div class="form-section">
                    <div class="section-header">
                        <div class="section-title"><i class="fas fa-network-wired"></i> 2. Administrative Role & Scope</div>
                        <span class="section-badge" id="scopeBadge">Branch Level</span>
                    </div>

                    <div class="role-picker-grid">
                        <div class="role-card selected" onclick="selectRole('co', this)">
                            <div class="role-card-header">
                                <span>Credit Officer</span>
                                <i class="fas fa-check-circle" style="color:var(--primary);"></i>
                            </div>
                            <div class="role-card-desc">Daily field collections, client management, repayments.</div>
                            <span class="role-scope-tag">Branch Assigned</span>
                        </div>

                        <div class="role-card" onclick="selectRole('bm', this)">
                            <div class="role-card-header">
                                <span>Branch Manager</span>
                                <i class="fas fa-circle" style="color:var(--border);"></i>
                            </div>
                            <div class="role-card-desc">Branch supervision, daily approval, loan vetting.</div>
                            <span class="role-scope-tag">Branch Assigned</span>
                        </div>

                        <div class="role-card" onclick="selectRole('am', this)">
                            <div class="role-card-header">
                                <span>Area Manager</span>
                                <i class="fas fa-circle" style="color:var(--border);"></i>
                            </div>
                            <div class="role-card-desc">Supervises branches in a regional area.</div>
                            <span class="role-scope-tag">Area Assigned</span>
                        </div>

                        <div class="role-card" onclick="selectRole('zm', this)">
                            <div class="role-card-header">
                                <span>Zone Manager</span>
                                <i class="fas fa-circle" style="color:var(--border);"></i>
                            </div>
                            <div class="role-card-desc">Oversees entire zonal operations.</div>
                            <span class="role-scope-tag">Zone Assigned</span>
                        </div>

                        <div class="role-card" onclick="selectRole('tm', this)">
                            <div class="role-card-header">
                                <span>Top Mgmt</span>
                                <i class="fas fa-circle" style="color:var(--border);"></i>
                            </div>
                            <div class="role-card-desc">Executive reporting & global oversight.</div>
                            <span class="role-scope-tag">Global Scope</span>
                        </div>

                        <div class="role-card" onclick="selectRole('admin', this)">
                            <div class="role-card-header">
                                <span>Administrator</span>
                                <i class="fas fa-circle" style="color:var(--border);"></i>
                            </div>
                            <div class="role-card-desc">Full system controls, provisioning, configuration.</div>
                            <span class="role-scope-tag">Global Scope</span>
                        </div>
                    </div>

                    <!-- Dynamic Geographic Hierarchy Chain -->
                    <div id="hierarchyBox" class="hierarchy-box">
                        <div class="hierarchy-chain-preview">
                            <span class="chain-step" id="chainZone"><i class="fas fa-earth-africa"></i> <span>Zone</span></span>
                            <i class="fas fa-chevron-right" style="color:var(--text-subtle); font-size:0.6rem;"></i>
                            <span class="chain-step" id="chainArea"><i class="fas fa-city"></i> <span>Area</span></span>
                            <i class="fas fa-chevron-right" style="color:var(--text-subtle); font-size:0.6rem;"></i>
                            <span class="chain-step" id="chainBranch"><i class="fas fa-building"></i> <span>Branch</span></span>
                        </div>

                        <div class="form-grid">
                            <div class="form-group" id="zoneGroup">
                                <label class="form-label" id="zoneLabel">Assigned Zone <span style="color:var(--danger)">*</span></label>
                                <div class="input-wrap">
                                    <select id="zoneSelect" name="zone" class="form-select" onchange="onZoneChanged()">
                                        <option value="">-- Choose Zone --</option>
                                        <?php foreach ($zones as $z): ?>
                                            <option value="<?php echo htmlspecialchars($z['id']); ?>"><?php echo htmlspecialchars($z['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <i class="fas fa-earth-africa input-icon"></i>
                                </div>
                            </div>

                            <div class="form-group" id="areaGroup">
                                <label class="form-label" id="areaLabel">Assigned Area <span style="color:var(--danger)">*</span></label>
                                <div class="input-wrap">
                                    <select id="areaSelect" name="area" class="form-select" onchange="onAreaChanged()" disabled>
                                        <option value="">Select Zone first</option>
                                    </select>
                                    <i class="fas fa-city input-icon"></i>
                                </div>
                            </div>
                        </div>

                        <div class="form-group" id="branchGroup" style="margin-top: 1rem;">
                            <label class="form-label" id="branchLabel">Assigned Branch <span style="color:var(--danger)">*</span></label>
                            <div class="input-wrap">
                                <select id="branchSelect" name="branch" class="form-select" onchange="onBranchChanged()" disabled>
                                    <option value="">Select Area first</option>
                                </select>
                                <i class="fas fa-building input-icon"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- SECTION 3: Profile Photo -->
                <div class="form-section">
                    <div class="section-header">
                        <div class="section-title"><i class="fas fa-camera"></i> 3. Staff Avatar</div>
                        <span class="section-badge" style="background:var(--bg-subtle); color:var(--text-muted);">Optional</span>
                    </div>

                    <div class="upload-card" id="dropZone" onclick="document.getElementById('profilePicInput').click()">
                        <input type="file" name="profile_pic" id="profilePicInput" accept="image/*" style="display:none" onchange="handleAvatarSelection(this)">
                        
                        <div id="uploadDefaultState">
                            <div class="upload-circle"><i class="fas fa-cloud-arrow-up"></i></div>
                            <h4 style="font-size:0.92rem; font-weight:700; margin-bottom:0.25rem;">Drag & drop photo or browse</h4>
                            <p style="font-size:0.78rem; color:var(--text-muted);">PNG, JPG, WEBP, or GIF (Max 2MB)</p>
                        </div>

                        <div id="uploadPreviewState" class="preview-wrapper">
                            <img id="previewImg" src="" alt="Preview" class="preview-img">
                            <div id="fileNameDisplay" style="font-size:0.85rem; font-weight:700;"></div>
                            <button type="button" class="remove-avatar-btn" onclick="event.stopPropagation(); resetAvatarUpload();">
                                <i class="fas fa-trash-can"></i> Remove Photo
                            </button>
                        </div>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="reset" class="btn-reset" onclick="handleFormReset()">
                        <i class="fas fa-rotate-left"></i> Reset
                    </button>
                    <button type="submit" class="btn-submit" id="submitBtn">
                        <i class="fas fa-user-plus"></i>
                        <span>Provision Account</span>
                    </button>
                </div>
            </form>
        </div>

    </main>

    <script>
        const AREA_DATA = <?php echo json_encode($areas); ?>;
        const BRANCH_DATA = <?php echo json_encode($branches); ?>;

        const roleHierarchyMap = {
            'admin': [],
            'tm': [],
            'client': [],
            'zm': ['zone'],
            'dzm': ['zone'],
            'am': ['zone', 'area'],
            'bm': ['zone', 'area', 'branch'],
            'co': ['zone', 'area', 'branch']
        };

        const roleScopeLabels = {
            'co': 'Branch Level',
            'bm': 'Branch Level',
            'am': 'Area Level',
            'zm': 'Zone Level',
            'tm': 'Global Level',
            'admin': 'Full System'
        };

        function selectRole(roleCode, el) {
            document.querySelectorAll('.role-card').forEach(c => {
                c.classList.remove('selected');
                c.querySelector('.fa-check-circle, .fa-circle').className = 'fas fa-circle';
                c.querySelector('.fa-circle').style.color = 'var(--border)';
            });

            el.classList.add('selected');
            const icon = el.querySelector('.fa-circle');
            if (icon) {
                icon.className = 'fas fa-check-circle';
                icon.style.color = 'var(--primary)';
            }

            document.getElementById('selectedRoleInput').value = roleCode;
            document.getElementById('scopeBadge').textContent = roleScopeLabels[roleCode] || 'Custom Level';
            handleRoleChange();
        }

        function handleRoleChange() {
            const role = document.getElementById('selectedRoleInput').value;
            const required = roleHierarchyMap[role] || [];
            
            const hierarchyBox = document.getElementById('hierarchyBox');
            const zoneGroup = document.getElementById('zoneGroup');
            const areaGroup = document.getElementById('areaGroup');
            const branchGroup = document.getElementById('branchGroup');

            if (required.length > 0) {
                hierarchyBox.style.display = 'block';
                zoneGroup.style.display = required.includes('zone') ? 'block' : 'none';
                areaGroup.style.display = required.includes('area') ? 'block' : 'none';
                branchGroup.style.display = required.includes('branch') ? 'block' : 'none';
            } else {
                hierarchyBox.style.display = 'none';
            }
            updateChainPreview();
        }

        function updateChainPreview() {
            const zoneVal = document.getElementById('zoneSelect').selectedOptions[0]?.text || 'Zone';
            const areaVal = document.getElementById('areaSelect').selectedOptions[0]?.text || 'Area';
            const branchVal = document.getElementById('branchSelect').selectedOptions[0]?.text || 'Branch';

            const cZone = document.getElementById('chainZone');
            const cArea = document.getElementById('chainArea');
            const cBranch = document.getElementById('chainBranch');

            if (document.getElementById('zoneSelect').value) {
                cZone.className = 'chain-step completed';
                cZone.querySelector('span').textContent = zoneVal;
            } else {
                cZone.className = 'chain-step active';
                cZone.querySelector('span').textContent = 'Zone';
            }

            if (document.getElementById('areaSelect').value) {
                cArea.className = 'chain-step completed';
                cArea.querySelector('span').textContent = areaVal;
            } else {
                cArea.className = 'chain-step';
                cArea.querySelector('span').textContent = 'Area';
            }

            if (document.getElementById('branchSelect').value) {
                cBranch.className = 'chain-step completed';
                cBranch.querySelector('span').textContent = branchVal;
            } else {
                cBranch.className = 'chain-step';
                cBranch.querySelector('span').textContent = 'Branch';
            }
        }

        function onZoneChanged() {
            const zoneId = document.getElementById('zoneSelect').value;
            const areaSelect = document.getElementById('areaSelect');
            const branchSelect = document.getElementById('branchSelect');

            areaSelect.innerHTML = '<option value="">-- Choose Area --</option>';
            branchSelect.innerHTML = '<option value="">Select Area first</option>';
            branchSelect.disabled = true;

            if (!zoneId) {
                areaSelect.disabled = true;
                updateChainPreview();
                return;
            }

            const filteredAreas = AREA_DATA.filter(a => String(a.zone_id) === String(zoneId));
            filteredAreas.forEach(area => {
                const opt = document.createElement('option');
                opt.value = area.id;
                opt.textContent = area.name;
                areaSelect.appendChild(opt);
            });

            areaSelect.disabled = false;
            updateChainPreview();
        }

        function onAreaChanged() {
            const areaId = document.getElementById('areaSelect').value;
            const branchSelect = document.getElementById('branchSelect');

            branchSelect.innerHTML = '<option value="">-- Choose Branch --</option>';

            if (!areaId) {
                branchSelect.disabled = true;
                updateChainPreview();
                return;
            }

            const filteredBranches = BRANCH_DATA.filter(b => String(b.area_id) === String(areaId));
            filteredBranches.forEach(branch => {
                const opt = document.createElement('option');
                opt.value = branch.id;
                opt.textContent = branch.name;
                branchSelect.appendChild(opt);
            });

            branchSelect.disabled = false;
            updateChainPreview();
        }

        function onBranchChanged() {
            updateChainPreview();
        }

        // Live Debounced Username Availability Checker
        let usernameTimer = null;
        const usernameInput = document.getElementById('usernameInput');
        const usernameStatus = document.getElementById('usernameLiveStatus');

        usernameInput.addEventListener('input', function() {
            clearTimeout(usernameTimer);
            const val = this.value.trim();
            if (val.length === 0) {
                usernameStatus.style.display = 'none';
                return;
            }
            usernameStatus.style.display = 'inline-flex';
            usernameStatus.className = 'live-status-badge checking';
            usernameStatus.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Checking availability...';

            usernameTimer = setTimeout(() => {
                checkLiveUsername(val);
            }, 300);
        });

        async function checkLiveUsername(username) {
            if (!username) return;
            try {
                const formData = new FormData();
                formData.append('ajax_action', 'check_username');
                formData.append('username', username);

                const res = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });
                const data = await res.json();

                if (data.success) {
                    if (data.available) {
                        usernameStatus.className = 'live-status-badge available';
                        usernameStatus.innerHTML = '<i class="fas fa-circle-check"></i> ' + data.message;
                    } else {
                        usernameStatus.className = 'live-status-badge taken';
                        usernameStatus.innerHTML = '<i class="fas fa-circle-xmark"></i> ' + data.message;
                    }
                }
            } catch (err) {
                usernameStatus.style.display = 'none';
            }
        }

        function generateRandomUsername() {
            const name = document.getElementById('fullNameInput').value;
            const base = name ? name.split(' ')[0].toLowerCase().replace(/[^a-z0-9]/g, '') : 'staff';
            const randomCode = Math.floor(100 + Math.random() * 900);
            const userField = document.getElementById('usernameInput');
            userField.value = base + randomCode;
            checkLiveUsername(userField.value);
        }

        // Password Strength & Generator
        const passwordInput = document.getElementById('passwordInput');
        const strengthContainer = document.getElementById('strengthContainer');
        const segs = [document.getElementById('seg1'), document.getElementById('seg2'), document.getElementById('seg3'), document.getElementById('seg4')];
        const strengthLabel = document.getElementById('strengthLabel');
        const strengthHint = document.getElementById('strengthHint');

        passwordInput.addEventListener('input', function() {
            const val = this.value;
            if (val.length === 0) {
                strengthContainer.style.display = 'none';
                return;
            }
            strengthContainer.style.display = 'block';

            let score = 0;
            if (val.length >= 6) score++;
            if (val.length >= 10) score++;
            if (/[0-9]/.test(val) && /[a-zA-Z]/.test(val)) score++;
            if (/[^A-Za-z0-9]/.test(val)) score++;

            segs.forEach(s => s.style.background = 'transparent');
            if (score <= 1) {
                segs[0].style.background = 'var(--danger)';
                strengthLabel.textContent = 'Weak';
                strengthLabel.style.color = 'var(--danger)';
                strengthHint.textContent = 'Add numbers & symbols';
            } else if (score === 2) {
                segs[0].style.background = 'var(--warning)';
                segs[1].style.background = 'var(--warning)';
                strengthLabel.textContent = 'Fair';
                strengthLabel.style.color = 'var(--warning)';
                strengthHint.textContent = 'Make it 8+ chars';
            } else if (score === 3) {
                segs[0].style.background = 'var(--info)';
                segs[1].style.background = 'var(--info)';
                segs[2].style.background = 'var(--info)';
                strengthLabel.textContent = 'Good';
                strengthLabel.style.color = 'var(--info)';
                strengthHint.textContent = 'Strong password';
            } else {
                segs.forEach(s => s.style.background = 'var(--success)');
                strengthLabel.textContent = 'Strong';
                strengthLabel.style.color = 'var(--success)';
                strengthHint.textContent = 'Excellent!';
            }
        });

        function generateStrongPassword() {
            const chars = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789!@#$%&*';
            let pass = '';
            for (let i = 0; i < 11; i++) {
                pass += chars.charAt(Math.floor(Math.random() * chars.length));
            }
            passwordInput.value = pass;
            passwordInput.type = 'text';
            document.getElementById('eyeIcon').className = 'fas fa-eye-slash';
            passwordInput.dispatchEvent(new Event('input'));
            
            navigator.clipboard.writeText(pass).then(() => {
                showToast('Strong password generated & copied!', 'success');
            });
        }

        function togglePasswordVisibility() {
            const icon = document.getElementById('eyeIcon');
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                icon.className = 'fas fa-eye-slash';
            } else {
                passwordInput.type = 'password';
                icon.className = 'fas fa-eye';
            }
        }

        // Avatar Drag & Drop Handlers
        function handleAvatarSelection(input) {
            if (input.files && input.files[0]) {
                const file = input.files[0];
                if (file.size > 2 * 1024 * 1024) {
                    showToast('Avatar exceeds maximum 2MB size.', 'error');
                    input.value = '';
                    return;
                }
                const reader = new FileReader();
                reader.onload = function(e) {
                    document.getElementById('previewImg').src = e.target.result;
                    document.getElementById('fileNameDisplay').textContent = file.name;
                    document.getElementById('uploadDefaultState').style.display = 'none';
                    document.getElementById('uploadPreviewState').style.display = 'flex';
                };
                reader.readAsDataURL(file);
            }
        }

        function resetAvatarUpload() {
            document.getElementById('profilePicInput').value = '';
            document.getElementById('uploadDefaultState').style.display = 'block';
            document.getElementById('uploadPreviewState').style.display = 'none';
        }

        const dropZone = document.getElementById('dropZone');
        ['dragenter', 'dragover'].forEach(evt => {
            dropZone.addEventListener(evt, e => { e.preventDefault(); dropZone.classList.add('drag-over'); });
        });
        ['dragleave', 'drop'].forEach(evt => {
            dropZone.addEventListener(evt, e => { e.preventDefault(); dropZone.classList.remove('drag-over'); });
        });
        dropZone.addEventListener('drop', e => {
            const dt = e.dataTransfer;
            if (dt.files && dt.files[0]) {
                document.getElementById('profilePicInput').files = dt.files;
                handleAvatarSelection(document.getElementById('profilePicInput'));
            }
        });

        // Toast Feedback System
        function showToast(msg, type = 'info') {
            const container = document.getElementById('toastContainer');
            const div = document.createElement('div');
            div.className = `toast ${type}`;
            const icon = type === 'success' ? 'fa-circle-check' : 'fa-triangle-exclamation';
            div.innerHTML = `<i class="fas ${icon}"></i> <span>${msg}</span>`;
            container.appendChild(div);
            setTimeout(() => div.remove(), 4500);
        }

        function handleFormReset() {
            resetAvatarUpload();
            usernameStatus.style.display = 'none';
            strengthContainer.style.display = 'none';
            document.getElementById('areaSelect').innerHTML = '<option value="">Select Zone first</option>';
            document.getElementById('areaSelect').disabled = true;
            document.getElementById('branchSelect').innerHTML = '<option value="">Select Area first</option>';
            document.getElementById('branchSelect').disabled = true;
            selectRole('co', document.querySelector('.role-card'));
        }

        // Form Submit Controller
        const createAccountForm = document.getElementById('createAccountForm');
        const submitBtn = document.getElementById('submitBtn');

        createAccountForm.addEventListener('submit', async function(e) {
            e.preventDefault();

            const role = document.getElementById('selectedRoleInput').value;
            const zone = document.getElementById('zoneSelect').value;
            const area = document.getElementById('areaSelect').value;
            const branch = document.getElementById('branchSelect').value;

            // Client-side Validation Checks
            if (['co', 'bm'].includes(role) && !branch) {
                showToast('Please select a Zone, Area, and Branch for this staff role.', 'error');
                return;
            }
            if (role === 'am' && !area) {
                showToast('Please select a Zone and Area for the Area Manager.', 'error');
                return;
            }
            if (['zm', 'dzm'].includes(role) && !zone) {
                showToast('Please select a Zone for the Zone Manager.', 'error');
                return;
            }

            submitBtn.disabled = true;
            const originalBtnContent = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i> Provisioning Staff Account...';

            try {
                const formData = new FormData(this);

                // Explicitly bind values to guarantee submission
                formData.set('role', role);
                formData.set('zone', zone);
                formData.set('area', area);
                formData.set('branch', branch);

                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });

                const data = await response.json();

                if (data.success) {
                    showToast(data.message, 'success');
                    createAccountForm.reset();
                    handleFormReset();

                    if (data.new_csrf_token) {
                        document.getElementById('csrfTokenInput').value = data.new_csrf_token;
                    }
                } else {
                    showToast(data.message || 'Failed to create account.', 'error');
                }
            } catch (err) {
                showToast('Network error or server timeout. Please try again.', 'error');
            } finally {
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalBtnContent;
            }
        });

        // Theme & Dropdown Init
        document.addEventListener('DOMContentLoaded', () => {
            handleRoleChange();

            if (localStorage.getItem('theme') === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                document.documentElement.classList.add('dark');
            }

            document.getElementById('themeToggle')?.addEventListener('click', () => {
                const isDark = document.documentElement.classList.toggle('dark');
                localStorage.setItem('theme', isDark ? 'dark' : 'light');
            });

            const userPill = document.getElementById('userDropdownTrigger');
            const userMenu = document.getElementById('userDropdown');
            if (userPill && userMenu) {
                userPill.addEventListener('click', (e) => {
                    e.stopPropagation();
                    userMenu.classList.toggle('show');
                });
                document.addEventListener('click', () => userMenu.classList.remove('show'));
            }
        });
    </script>
</body>
</html>