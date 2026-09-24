<?php
session_start();

// BASE CONFIGURATION & DATABASE
require_once '../includes/config.php';
$pdo = getDbConnection();

$base_path = '../'; 
$page_title = "My Profile - CUPAD";

// SECURITY CHECK (ADMIN ONLY)
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: ' . $base_path . 'index.php');
    exit();
}

// SESSION & USER DATA
$full_name = $_SESSION['full_name'] ?? $_SESSION['name'] ?? 'Admin';
$username  = $_SESSION['username'] ?? '';
$role      = $_SESSION['user_role'] ?? 'admin';

// Generate and validate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// FETCH USER DATA FROM DATABASE
$email = '';
$profile_pic_filename = '';
$last_login = 'N/A';
$failed_attempts = 0;
$user_data = null;

try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? LIMIT 1");
    $stmt->execute([$username]);
    $user_data = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user_data) {
        $email = $user_data['email'] ?? '';
        $full_name = $user_data['full_name'] ?? $user_data['name'] ?? $full_name;
        $profile_pic_filename = $user_data['profile_pic'] ?? '';
        $last_login = !empty($user_data['last_login']) ? date('M d, Y • H:i', strtotime($user_data['last_login'])) : 'Recent';
        $failed_attempts = $user_data['failed_login_attempts'] ?? 0;
    }
} catch (PDOException $e) {
    error_log("Profile Fetch Error: " . $e->getMessage());
}

// Profile Picture Resolution
$profile_pic_path = $base_path . 'uploads/default_avatar.png';
$has_valid_pic = false;

if (!empty($profile_pic_filename) && file_exists($base_path . $profile_pic_filename)) {
    $profile_pic_path = $base_path . $profile_pic_filename;
    $has_valid_pic = true;
}

// FORM SUBMISSION HANDLING (AJAX & POST)
$is_ajax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
$response_data = ['success' => false, 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $response_data['message'] = 'Security validation failed. Please refresh the page.';
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode($response_data);
            exit;
        }
    }

    try {
        // --- 1. UPDATE PROFILE ---
        if (isset($_POST['form_action']) && $_POST['form_action'] === 'update_profile') {
            $new_full_name = trim($_POST['fullName'] ?? '');
            $new_email = filter_var(trim($_POST['email'] ?? ''), FILTER_SANITIZE_EMAIL);
            $field_errors = [];

            if (empty($new_full_name) || strlen($new_full_name) < 2) {
                $field_errors['fullName'] = 'Please enter a valid full name.';
            }
            if (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
                $field_errors['email'] = 'Please enter a valid email address.';
            }

            if (empty($field_errors)) {
                $new_pic_path = $profile_pic_filename;

                // Handle Image Upload
                if (isset($_FILES['settings_profile_picture']) && $_FILES['settings_profile_picture']['error'] === UPLOAD_ERR_OK) {
                    $file = $_FILES['settings_profile_picture'];
                    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                    $allowed_exts = ['jpeg', 'jpg', 'png', 'webp'];

                    if (in_array($ext, $allowed_exts) && $file['size'] <= 5 * 1024 * 1024) {
                        $upload_dir = $base_path . 'uploads/';
                        if (!is_dir($upload_dir)) {
                            mkdir($upload_dir, 0755, true);
                        }
                        $relative_filename = 'uploads/admin_' . preg_replace('/[^a-zA-Z0-9]/', '', $username) . '_' . time() . '.' . $ext;
                        
                        if (move_uploaded_file($file['tmp_name'], $base_path . $relative_filename)) {
                            $new_pic_path = $relative_filename;
                            $profile_pic_path = $base_path . $relative_filename;
                            $has_valid_pic = true;
                        }
                    } else {
                        $field_errors['settings_profile_picture'] = 'Invalid file type or size exceeds 5MB.';
                    }
                }

                if (empty($field_errors)) {
                    $update_stmt = $pdo->prepare("UPDATE users SET full_name = ?, email = ?, profile_pic = ? WHERE username = ?");
                    $update_stmt->execute([$new_full_name, $new_email, $new_pic_path, $username]);

                    $_SESSION['full_name'] = $new_full_name;
                    $_SESSION['name'] = $new_full_name;
                    $full_name = $new_full_name;
                    $email = $new_email;

                    $response_data['success'] = true;
                    $response_data['message'] = 'Profile updated successfully.';
                    $response_data['data'] = [
                        'fullName' => $new_full_name, 
                        'email' => $new_email, 
                        'profilePicPath' => $profile_pic_path,
                        'hasValidPic' => $has_valid_pic
                    ];
                } else {
                    $response_data['field_errors'] = $field_errors;
                }
            } else {
                $response_data['field_errors'] = $field_errors;
            }
        }
        
        // --- 2. CHANGE PASSWORD ---
        if (isset($_POST['form_action']) && $_POST['form_action'] === 'change_password') {
            $current = $_POST['currentPassword'] ?? '';
            $new = $_POST['newPassword'] ?? '';
            $confirm = $_POST['confirmPassword'] ?? '';
            $field_errors = [];

            // Verify current password from database
            $pass_stmt = $pdo->prepare("SELECT password FROM users WHERE username = ? LIMIT 1");
            $pass_stmt->execute([$username]);
            $stored_user = $pass_stmt->fetch(PDO::FETCH_ASSOC);

            if (!$stored_user || (!password_verify($current, $stored_user['password']) && $current !== $stored_user['password'])) {
                $field_errors['currentPassword'] = 'Current password is incorrect.';
            }
            if (strlen($new) < 6) {
                $field_errors['newPassword'] = 'New password must be at least 6 characters.';
            }
            if ($new !== $confirm) {
                $field_errors['confirmPassword'] = 'Passwords do not match.';
            }

            if (empty($field_errors)) {
                $new_hashed = password_hash($new, PASSWORD_DEFAULT);
                $up_pass = $pdo->prepare("UPDATE users SET password = ? WHERE username = ?");
                $up_pass->execute([$new_hashed, $username]);

                $response_data['success'] = true;
                $response_data['message'] = 'Password changed successfully.';
            } else {
                $response_data['field_errors'] = $field_errors;
            }
        }

    } catch (PDOException $e) {
        $response_data['message'] = 'Database error: ' . $e->getMessage();
    }

    if ($is_ajax) {
        header('Content-Type: application/json');
        echo json_encode($response_data);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    <link rel="icon" type="image/png" href="<?php echo htmlspecialchars($base_path . 'uploads/CUPAD LOGO.png'); ?>">
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />

    <style>
        :root {
            --primary: #2563eb; 
            --primary-hover: #1d4ed8;
            --primary-soft: rgba(37, 99, 235, 0.08);
            --success: #10b981; 
            --success-soft: rgba(16, 185, 129, 0.1);
            --warning: #f59e0b; 
            --warning-soft: rgba(245, 158, 11, 0.1);
            --danger: #ef4444; 
            --danger-soft: rgba(239, 68, 68, 0.1);
            --info: #06b6d4;
            --info-soft: rgba(6, 182, 212, 0.1);
            
            --bg-body: #f8fafc; 
            --bg-surface: #ffffff;
            --bg-subtle: #f1f5f9;
            --text-main: #0f172a; 
            --text-muted: #64748b;
            --border: #e2e8f0; 
            
            --radius-xl: 20px;
            --radius-lg: 14px; 
            --radius-md: 10px;
            --radius-sm: 6px;
            
            --shadow-xs: 0 1px 2px 0 rgb(0 0 0 / 0.05);
            --shadow-sm: 0 4px 6px -1px rgba(0, 0, 0, 0.04);
            --shadow-md: 0 10px 15px -3px rgba(0, 0, 0, 0.06);
            --shadow-lg: 0 20px 25px -5px rgba(0, 0, 0, 0.08);
            
            --nav-height: 72px;
            --transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        }

        html.dark {
            --bg-body: #090d16; 
            --bg-surface: #131b2e;
            --bg-subtle: #1e293b;
            --text-main: #f8fafc; 
            --text-muted: #94a3b8;
            --border: #1e293b; 
            --primary: #3b82f6; 
            --primary-hover: #60a5fa;
            --primary-soft: rgba(59, 130, 246, 0.15);
            --shadow-sm: 0 4px 6px -1px rgba(0, 0, 0, 0.3);
            --shadow-md: 0 10px 15px -3px rgba(0, 0, 0, 0.4);
        }

        * { box-sizing: border-box; outline: none; margin: 0; padding: 0; }
        body { 
            font-family: 'Plus Jakarta Sans', sans-serif; 
            background: var(--bg-body); 
            color: var(--text-main); 
            padding-top: var(--nav-height); 
            transition: background-color 0.3s ease, color 0.3s ease; 
            min-height: 100vh;
            -webkit-font-smoothing: antialiased;
        }
        a { text-decoration: none; color: inherit; }

        /* Top Header Navigation */
        .main-header { 
            position: fixed; 
            top: 0; left: 0; right: 0; 
            height: var(--nav-height); 
            background: rgba(255,255,255,0.85); 
            backdrop-filter: blur(12px); 
            -webkit-backdrop-filter: blur(12px);
            border-bottom: 1px solid var(--border); 
            z-index: 50; 
            transition: var(--transition);
        }
        html.dark .main-header { background: rgba(19, 27, 46, 0.85); }
        
        .navbar { 
            max-width: 1400px; 
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
            gap: 0.85rem; 
            font-weight: 800; 
            font-size: 1.35rem; 
            color: var(--primary); 
            letter-spacing: -0.02em;
        }
        .logo img { height: 38px; width: auto; object-fit: contain; }
        
        .nav-right { display: flex; align-items: center; gap: 1rem; }
        .icon-btn { 
            width: 40px; 
            height: 40px; 
            border-radius: 50%; 
            border: 1px solid var(--border); 
            background: var(--bg-surface); 
            color: var(--text-muted); 
            cursor: pointer; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            font-size: 1rem; 
            transition: var(--transition); 
        }
        .icon-btn:hover { background: var(--bg-subtle); color: var(--text-main); transform: translateY(-1px); }
        
        .user-dropdown-wrap { position: relative; }
        .user-pill { 
            display: flex; 
            align-items: center; 
            gap: 0.75rem; 
            padding: 5px 12px 5px 5px; 
            border: 1px solid var(--border); 
            border-radius: 99px; 
            background: var(--bg-surface); 
            cursor: pointer; 
            transition: var(--transition); 
        }
        .user-pill:hover { border-color: var(--primary); box-shadow: var(--shadow-xs); }
        .user-avatar { width: 34px; height: 34px; border-radius: 50%; object-fit: cover; }
        .user-info { display: flex; flex-direction: column; line-height: 1.2; }
        .user-name { font-weight: 700; font-size: 0.85rem; }
        .user-role { font-size: 0.68rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; font-weight: 600; }
        
        .dropdown-menu { 
            position: absolute; 
            top: calc(100% + 10px); 
            right: 0; 
            background: var(--bg-surface); 
            border: 1px solid var(--border); 
            border-radius: var(--radius-lg); 
            box-shadow: var(--shadow-lg); 
            min-width: 220px; 
            display: none; 
            z-index: 1000; 
            flex-direction: column; 
            overflow: hidden; 
            padding: 0.5rem;
            animation: dropdownFade 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        }
        @keyframes dropdownFade {
            from { opacity: 0; transform: translateY(-8px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .dropdown-menu.show { display: flex; }
        .dropdown-item { 
            padding: 0.75rem 1rem; 
            display: flex; 
            align-items: center; 
            gap: 0.75rem; 
            font-size: 0.875rem; 
            font-weight: 500;
            color: var(--text-main); 
            border-radius: var(--radius-md);
            transition: var(--transition); 
        }
        .dropdown-item:hover { background: var(--primary-soft); color: var(--primary); }
        .dropdown-item.text-danger:hover { background: var(--danger-soft); color: var(--danger); }
        .dropdown-divider { height: 1px; background: var(--border); margin: 0.35rem 0; }

        /* Container */
        .container { max-width: 1200px; margin: 0 auto; padding: 2.25rem 1.5rem 4rem; }

        /* Page Header */
        .page-header {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 1.25rem;
            margin-bottom: 2rem;
        }
        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.8rem;
            color: var(--text-muted);
            margin-bottom: 0.4rem;
            font-weight: 500;
        }
        .breadcrumb a:hover { color: var(--primary); }
        .page-title {
            font-size: 1.75rem;
            font-weight: 800;
            letter-spacing: -0.03em;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        /* Buttons */
        .btn {
            padding: 0.75rem 1.5rem;
            border-radius: var(--radius-md);
            font-size: 0.875rem;
            font-weight: 700;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            border: 1px solid transparent;
            font-family: inherit;
        }
        .btn-primary { background: var(--primary); color: #ffffff; box-shadow: 0 4px 12px rgba(37, 99, 235, 0.25); }
        .btn-primary:hover { background: var(--primary-hover); transform: translateY(-1px); box-shadow: 0 6px 16px rgba(37, 99, 235, 0.35); }
        .btn-secondary { background: var(--bg-surface); color: var(--text-main); border-color: var(--border); }
        .btn-secondary:hover { background: var(--bg-subtle); }

        /* Hero Profile Banner */
        .hero-card {
            background: linear-gradient(135deg, var(--primary) 0%, #1d4ed8 100%);
            color: #ffffff;
            padding: 2.5rem 2rem;
            border-radius: var(--radius-xl);
            position: relative;
            overflow: hidden;
            margin-bottom: 2rem;
            box-shadow: 0 10px 25px -5px rgba(37, 99, 235, 0.35);
        }
        .hero-bg-icon {
            position: absolute;
            right: -20px;
            bottom: -30px;
            font-size: 11rem;
            opacity: 0.08;
            transform: rotate(-15deg);
            pointer-events: none;
        }
        .hero-content {
            position: relative;
            z-index: 10;
            display: flex;
            align-items: center;
            gap: 2rem;
            flex-wrap: wrap;
        }

        /* Avatar Container */
        .avatar-wrapper {
            position: relative;
            width: 110px;
            height: 110px;
            flex-shrink: 0;
        }
        .avatar-img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 50%;
            border: 4px solid rgba(255, 255, 255, 0.3);
            box-shadow: var(--shadow-md);
            background: var(--bg-surface);
        }
        .avatar-fallback {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.75rem;
            color: var(--primary);
            background: var(--bg-surface);
            border: 4px solid rgba(255, 255, 255, 0.3);
        }
        .avatar-edit-btn {
            position: absolute;
            bottom: 2px;
            right: 2px;
            background: #ffffff;
            color: var(--primary);
            width: 34px;
            height: 34px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: var(--shadow-md);
            cursor: pointer;
            transition: var(--transition);
            font-size: 0.875rem;
        }
        .avatar-edit-btn:hover { transform: scale(1.1); }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1.25rem;
            margin-bottom: 2rem;
        }
        .stat-card {
            background: var(--bg-surface);
            padding: 1.25rem 1.5rem;
            border-radius: var(--radius-xl);
            border: 1px solid var(--border);
            box-shadow: var(--shadow-sm);
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        .stat-icon {
            width: 46px;
            height: 46px;
            border-radius: var(--radius-lg);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            flex-shrink: 0;
        }

        /* Layout Grid */
        .profile-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.75rem;
        }

        /* Forms & Inputs */
        .card {
            background: var(--bg-surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-xl);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
        }
        .card-header {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--border);
            background: var(--bg-surface);
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-weight: 700;
            font-size: 1.05rem;
        }
        .card-body { padding: 1.5rem; }

        .input-group {
            position: relative;
            margin-bottom: 1.25rem;
        }
        .input-label {
            display: block;
            font-size: 0.8rem;
            font-weight: 700;
            color: var(--text-muted);
            margin-bottom: 0.4rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .input-wrap {
            position: relative;
        }
        .input-icon {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            font-size: 0.9rem;
        }
        .input-field {
            width: 100%;
            padding: 0.75rem 1rem 0.75rem 2.6rem;
            border-radius: var(--radius-md);
            border: 1px solid var(--border);
            background: var(--bg-subtle);
            color: var(--text-main);
            font-family: inherit;
            font-size: 0.9rem;
            transition: var(--transition);
        }
        .input-field:focus {
            border-color: var(--primary);
            background: var(--bg-surface);
            box-shadow: 0 0 0 3px var(--primary-soft);
        }

        /* Activity Timeline */
        .timeline-list { display: flex; flex-direction: column; gap: 1rem; }
        .timeline-item {
            display: flex;
            align-items: flex-start;
            gap: 0.85rem;
            padding: 0.85rem;
            background: var(--bg-subtle);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
        }

        /* Toast Notifications */
        #toast-container {
            position: fixed;
            top: 1.5rem;
            right: 1.5rem;
            z-index: 9999;
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            max-width: 380px;
            width: 100%;
            pointer-events: none;
        }
        .toast {
            background: var(--bg-surface);
            border-left: 4px solid var(--primary);
            padding: 1rem 1.25rem;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-lg);
            display: flex;
            align-items: center;
            gap: 0.85rem;
            pointer-events: auto;
            animation: toastIn 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            border-top: 1px solid var(--border);
            border-right: 1px solid var(--border);
            border-bottom: 1px solid var(--border);
        }
        @keyframes toastIn {
            from { opacity: 0; transform: translateX(30px); }
            to { opacity: 1; transform: translateX(0); }
        }

        @media (max-width: 992px) {
            .profile-grid { grid-template-columns: 1fr; }
        }
        @media (max-width: 640px) {
            .hero-content { flex-direction: column; text-align: center; }
            .hero-content .avatar-wrapper { margin: 0 auto; }
        }
    </style>
</head>
<body>

    <div id="toast-container"></div>

    <!-- Main Navigation Header -->
    <header class="main-header">
        <div class="navbar">
            <a href="dashboard.php" class="logo">
                <img src="<?php echo htmlspecialchars($base_path . 'uploads/CUPAD LOGO.png'); ?>" alt="Logo">
                <span>CUPAD</span>
            </a>
            
            <div class="nav-right">
                <button class="icon-btn" id="themeToggle" title="Toggle Theme">
                    <i class="fas fa-moon"></i>
                </button>

                <div class="user-dropdown-wrap">
                    <div class="user-pill" id="userDropdownTrigger">
                        <?php if($has_valid_pic): ?>
                            <img src="<?php echo htmlspecialchars($profile_pic_path); ?>" class="user-avatar" alt="Avatar">
                        <?php else: ?>
                            <div class="user-avatar" style="background:var(--bg-subtle); border:1px solid var(--border); display:flex; align-items:center; justify-content:center; color:var(--text-muted);"><i class="fas fa-user"></i></div>
                        <?php endif; ?>
                        <div class="user-info">
                            <span class="user-name"><?php echo htmlspecialchars($full_name); ?></span>
                            <span class="user-role"><?php echo ucfirst(htmlspecialchars($role)); ?></span>
                        </div>
                        <i class="fas fa-chevron-down" style="font-size:0.75rem; color:var(--text-muted); margin-left:0.25rem;"></i>
                    </div>
                    
                    <div class="dropdown-menu" id="userDropdown">
                        <a href="profile.php" class="dropdown-item"><i class="fas fa-user-circle"></i> My Profile</a>
                        <a href="dashboard.php" class="dropdown-item"><i class="fas fa-gauge"></i> Admin Dashboard</a>
                        <div class="dropdown-divider"></div>
                        <a href="<?php echo htmlspecialchars($base_path . 'logout.php'); ?>" class="dropdown-item text-danger"><i class="fas fa-sign-out-alt"></i> Logout</a>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <main class="container">
        
        <!-- Breadcrumbs & Header -->
        <div class="page-header">
            <div>
                <div class="breadcrumb">
                    <a href="dashboard.php">Dashboard</a>
                    <i class="fas fa-chevron-right" style="font-size:0.65rem;"></i>
                    <span>Admin Profile</span>
                </div>
                <h1 class="page-title">
                    <i class="fas fa-user-gear" style="color: var(--primary);"></i>
                    Account Settings
                </h1>
            </div>
            <a href="dashboard.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>
        </div>

        <!-- Hero Card Banner -->
        <div class="hero-card">
            <i class="fas fa-user-shield hero-bg-icon"></i>
            <div class="hero-content">
                <div class="avatar-wrapper">
                    <?php if($has_valid_pic): ?>
                        <img src="<?php echo htmlspecialchars($profile_pic_path); ?>" alt="Avatar" id="heroAvatar" class="avatar-img">
                    <?php else: ?>
                        <div id="heroAvatarFallback" class="avatar-fallback"><i class="fas fa-user"></i></div>
                        <img src="" alt="Avatar" id="heroAvatar" class="avatar-img" style="display:none;">
                    <?php endif; ?>
                    
                    <label for="settings_profile_picture" class="avatar-edit-btn" title="Change Avatar">
                        <i class="fas fa-camera"></i>
                    </label>
                </div>

                <div>
                    <h2 style="font-size: 1.75rem; font-weight: 800; letter-spacing: -0.02em;" id="displayName"><?php echo htmlspecialchars($full_name); ?></h2>
                    <p style="opacity: 0.9; font-size: 0.9rem; margin-top: 0.2rem;">@<?php echo htmlspecialchars($username); ?></p>
                    <div style="display: flex; gap: 0.5rem; margin-top: 0.85rem; flex-wrap: wrap;">
                        <span style="padding: 0.25rem 0.75rem; background: rgba(255,255,255,0.2); border-radius: 99px; font-size: 0.75rem; font-weight: 700; backdrop-filter: blur(4px);">
                            <i class="fas fa-shield-halved"></i> System Administrator
                        </span>
                        <span style="padding: 0.25rem 0.75rem; background: rgba(255,255,255,0.2); border-radius: 99px; font-size: 0.75rem; font-weight: 700; backdrop-filter: blur(4px);">
                            <i class="fas fa-circle-check"></i> Verified Account
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Quick Stats Overview -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon" style="background: var(--success-soft); color: var(--success);">
                    <i class="fas fa-user-check"></i>
                </div>
                <div>
                    <div style="font-size:0.75rem; font-weight:700; color:var(--text-muted); text-transform:uppercase;">Account Status</div>
                    <div style="font-size:1.15rem; font-weight:800; color:var(--success);">Active</div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon" style="background: var(--info-soft); color: var(--info);">
                    <i class="fas fa-clock"></i>
                </div>
                <div>
                    <div style="font-size:0.75rem; font-weight:700; color:var(--text-muted); text-transform:uppercase;">Last Active Login</div>
                    <div style="font-size:0.95rem; font-weight:800;"><?php echo $last_login; ?></div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon" style="background: var(--danger-soft); color: var(--danger);">
                    <i class="fas fa-shield-cat"></i>
                </div>
                <div>
                    <div style="font-size:0.75rem; font-weight:700; color:var(--text-muted); text-transform:uppercase;">Failed Attempts</div>
                    <div style="font-size:1.15rem; font-weight:800; color:var(--danger);"><?php echo $failed_attempts; ?></div>
                </div>
            </div>
        </div>

        <!-- Forms & Security Workspace -->
        <div class="profile-grid">
            
            <!-- Update Profile Form -->
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-user-pen" style="color: var(--primary);"></i>
                    Profile Information
                </div>
                <div class="card-body">
                    <form id="profileForm" enctype="multipart/form-data">
                        <input type="hidden" name="form_action" value="update_profile">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        
                        <!-- Hidden Image Input Triggered by Hero Camera Button -->
                        <input type="file" id="settings_profile_picture" name="settings_profile_picture" style="display:none;" accept="image/*">

                        <div class="input-group">
                            <label class="input-label">Full Name</label>
                            <div class="input-wrap">
                                <i class="fas fa-user input-icon"></i>
                                <input type="text" name="fullName" value="<?php echo htmlspecialchars($full_name); ?>" class="input-field" required>
                            </div>
                        </div>

                        <div class="input-group">
                            <label class="input-label">Email Address</label>
                            <div class="input-wrap">
                                <i class="fas fa-envelope input-icon"></i>
                                <input type="email" name="email" value="<?php echo htmlspecialchars($email); ?>" class="input-field" placeholder="admin@cupad.org" required>
                            </div>
                        </div>

                        <div class="input-group">
                            <label class="input-label">Username (Read-Only)</label>
                            <div class="input-wrap">
                                <i class="fas fa-at input-icon"></i>
                                <input type="text" value="<?php echo htmlspecialchars($username); ?>" class="input-field" disabled style="opacity: 0.7; cursor: not-allowed;">
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary" style="width: 100%;">
                            <i class="fas fa-floppy-disk"></i> Save Profile Changes
                        </button>
                    </form>
                </div>
            </div>

            <!-- Password & Security Form -->
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-lock" style="color: var(--warning);"></i>
                    Security & Password
                </div>
                <div class="card-body">
                    <form id="passwordForm">
                        <input type="hidden" name="form_action" value="change_password">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        
                        <div class="input-group">
                            <label class="input-label">Current Password</label>
                            <div class="input-wrap">
                                <i class="fas fa-key input-icon"></i>
                                <input type="password" name="currentPassword" class="input-field" placeholder="••••••••" required>
                            </div>
                        </div>

                        <div class="input-group">
                            <label class="input-label">New Password</label>
                            <div class="input-wrap">
                                <i class="fas fa-lock input-icon"></i>
                                <input type="password" name="newPassword" class="input-field" placeholder="Min. 6 characters" required>
                            </div>
                        </div>

                        <div class="input-group">
                            <label class="input-label">Confirm New Password</label>
                            <div class="input-wrap">
                                <i class="fas fa-check-double input-icon"></i>
                                <input type="password" name="confirmPassword" class="input-field" placeholder="Re-enter new password" required>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-secondary" style="width: 100%; border-color: var(--primary); color: var(--primary);">
                            <i class="fas fa-shield-halved"></i> Update Password
                        </button>
                    </form>
                </div>
            </div>

        </div>

    </main>

    <script>
        const $ = id => document.getElementById(id);

        // Toast Notification System
        function showToast(msg, type = 'success') {
            const container = $('toast-container');
            const toast = document.createElement('div');
            toast.className = 'toast';
            
            const isSuccess = type === 'success';
            toast.style.borderLeftColor = isSuccess ? 'var(--success)' : 'var(--danger)';
            
            toast.innerHTML = `
                <i class="fas ${isSuccess ? 'fa-check-circle' : 'fa-exclamation-triangle'}" style="color: ${isSuccess ? 'var(--success)' : 'var(--danger)'}; font-size: 1.1rem;"></i>
                <span style="font-size: 0.875rem; font-weight: 600; color: var(--text-main);">${msg}</span>
            `;
            
            container.appendChild(toast);

            setTimeout(() => {
                toast.style.opacity = '0';
                toast.style.transform = 'translateX(30px)';
                toast.style.transition = 'all 0.3s ease';
                setTimeout(() => toast.remove(), 300);
            }, 3500);
        }

        // Image Preview Handler
        const fileInput = $('settings_profile_picture');
        const heroAvatar = $('heroAvatar');
        const fallbackIcon = $('heroAvatarFallback');
        
        if (fileInput) {
            fileInput.addEventListener('change', (e) => {
                const file = e.target.files[0];
                if(file) {
                    const reader = new FileReader();
                    reader.onload = (e) => { 
                        heroAvatar.src = e.target.result;
                        heroAvatar.style.display = 'block';
                        if(fallbackIcon) fallbackIcon.style.display = 'none';
                    };
                    reader.readAsDataURL(file);
                }
            });
        }

        // AJAX Form Submission Handler
        const handleForm = (formId) => {
            const form = $(formId);
            if (!form) return;

            form.addEventListener('submit', async (e) => {
                e.preventDefault();
                const btn = form.querySelector('button[type="submit"]');
                const originalHtml = btn.innerHTML;
                
                btn.disabled = true; 
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';

                try {
                    const fd = new FormData(form);
                    // Append file if triggering profile update
                    if (formId === 'profileForm' && fileInput.files[0]) {
                        fd.append('settings_profile_picture', fileInput.files[0]);
                    }

                    const res = await fetch('', { 
                        method: 'POST', 
                        body: fd, 
                        headers: { 'X-Requested-With': 'XMLHttpRequest' }
                    });
                    
                    const data = await res.json();

                    if (data.success) {
                        showToast(data.message, 'success');
                        
                        if (formId === 'profileForm' && data.data) {
                            $('displayName').innerText = data.data.fullName;
                        }
                        if (formId === 'passwordForm') {
                            form.reset();
                        }
                    } else {
                        let msg = data.message || 'An error occurred during submission.';
                        if (data.field_errors) {
                            msg = Object.values(data.field_errors)[0];
                        }
                        showToast(msg, 'error');
                    }
                } catch (err) { 
                    showToast('Network error or server unavailable.', 'error'); 
                } finally { 
                    btn.disabled = false; 
                    btn.innerHTML = originalHtml; 
                }
            });
        };

        handleForm('profileForm');
        handleForm('passwordForm');

        // Theme and Dropdown Logic
        document.addEventListener('DOMContentLoaded', () => {
            // Theme Toggle
            if (localStorage.getItem('theme') === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                document.documentElement.classList.add('dark');
            } else {
                document.documentElement.classList.remove('dark');
            }

            document.getElementById('themeToggle').addEventListener('click', () => {
                const isDark = document.documentElement.classList.toggle('dark');
                localStorage.setItem('theme', isDark ? 'dark' : 'light');
            });

            // User Dropdown
            const trig = document.getElementById('userDropdownTrigger');
            const menu = document.getElementById('userDropdown');
            if (trig && menu) {
                trig.addEventListener('click', (e) => { 
                    e.stopPropagation(); 
                    menu.classList.toggle('show'); 
                });
                document.addEventListener('click', () => { 
                    menu.classList.remove('show'); 
                });
            }
        });

        // Prevent POST re-submission on refresh
        if (window.history.replaceState) {
            window.history.replaceState(null, null, window.location.href);
        }
    </script>
</body>
</html>