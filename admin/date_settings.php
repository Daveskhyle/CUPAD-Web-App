<?php
session_start();
require_once '../includes/config.php';
$pdo = getDbConnection();

// Authentication Verification
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: ../index.php');
    exit();
}

// Generate CSRF Token for Secure AJAX calls
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$base_path = '../';
$username = $_SESSION['username'] ?? 'Admin';
$full_name = $_SESSION['full_name'] ?? 'System Administrator';
$role = $_SESSION['user_role'] ?? 'admin';

// Handle AJAX Auto-Save Request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'update_date_control') {
    header('Content-Type: application/json');
    
    // Validate CSRF
    $client_token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $client_token)) {
        echo json_encode(['success' => false, 'message' => 'Security token expired. Please refresh the page.']);
        exit();
    }
    
    $date_readonly = (isset($_POST['date_readonly']) && $_POST['date_readonly'] == '1') ? 1 : 0;
    
    try {
        $stmt = $pdo->prepare("UPDATE date_control_settings SET date_readonly = ?, updated_by = ?, updated_at = NOW() WHERE id = 1");
        $stmt->execute([$date_readonly, $username]);
        
        $stmt = $pdo->query("SELECT updated_at FROM date_control_settings WHERE id = 1");
        $updated_at = $stmt->fetchColumn();
        $formatted_date = date('M d, Y • h:i A', strtotime($updated_at));
        
        echo json_encode([
            'success' => true,
            'message' => $date_readonly ? 'Strict Policy Enforced: Date selection is now LOCKED.' : 'Flexible Policy Enabled: Date selection is now UNLOCKED.',
            'date_readonly' => $date_readonly,
            'updated_by' => $username,
            'updated_at' => $formatted_date,
            'timestamp_raw' => $updated_at
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: Unable to update date policy.']);
    }
    exit();
}

// Fetch Existing Settings
$stmt = $pdo->query("SELECT * FROM date_control_settings WHERE id = 1");
$settings = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$settings) {
    $pdo->exec("INSERT INTO date_control_settings (id, date_readonly, updated_by) VALUES (1, 0, 'system')");
    $settings = ['date_readonly' => 0, 'updated_by' => 'system', 'updated_at' => date('Y-m-d H:i:s')];
}

// Profile Picture Resolution
$my_pic = 'default_avatar.png';
try {
    $stmt = $pdo->prepare("SELECT profile_pic FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user_data = $stmt->fetch();
    $my_pic = $user_data['profile_pic'] ?? 'default_avatar.png';
} catch (Exception $e) {}

$profile_pic_path = $base_path . 'uploads/' . $my_pic;
$has_profile_pic = file_exists($profile_pic_path) && $my_pic !== 'default_avatar.png';
$is_locked = ($settings['date_readonly'] == 1);
?>
<!DOCTYPE html>
<html lang="en" class="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Date Policy & Collection Control — CUPAD Admin</title>
    <link rel="icon" type="image/png" href="<?php echo htmlspecialchars($base_path . 'uploads/CUPAD LOGO.png'); ?>">
    
    <!-- Google Fonts & Font Awesome 6 -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />

    <style>
        :root {
            --primary: #2563eb;
            --primary-hover: #1d4ed8;
            --primary-soft: rgba(37, 99, 235, 0.08);
            --primary-border: rgba(37, 99, 235, 0.25);
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
            --border-hover: #cbd5e1;
            
            --radius-xl: 20px;
            --radius-lg: 14px;
            --radius-md: 10px;
            --radius-sm: 6px;
            
            --shadow-xs: 0 1px 2px 0 rgba(0, 0, 0, 0.04);
            --shadow-sm: 0 4px 6px -1px rgba(0, 0, 0, 0.04), 0 2px 4px -2px rgba(0, 0, 0, 0.04);
            --shadow-md: 0 10px 15px -3px rgba(0, 0, 0, 0.06), 0 4px 6px -4px rgba(0, 0, 0, 0.04);
            --shadow-lg: 0 20px 25px -5px rgba(0, 0, 0, 0.08), 0 8px 10px -6px rgba(0, 0, 0, 0.04);
            
            --nav-height: 70px;
            --transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        }

        html.dark {
            --bg-body: #0a0f1d;
            --bg-surface: #111827;
            --bg-subtle: #1e293b;
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
            --border: #1f293d;
            --border-hover: #334155;
            --primary: #3b82f6;
            --primary-hover: #60a5fa;
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
            position: fixed; top: 0; left: 0; right: 0;
            height: var(--nav-height);
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
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
            position: absolute; top: calc(100% + 8px); right: 0;
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
        .container { max-width: 1080px; margin: 0 auto; padding: 2.25rem 1.5rem 4rem; }

        /* Page Hero Header */
        .page-header {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 1.25rem;
            margin-bottom: 2rem;
        }
        .header-left { display: flex; align-items: center; gap: 1rem; }
        .back-btn {
            width: 44px; height: 44px;
            background: var(--bg-surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-main);
            transition: var(--transition);
            box-shadow: var(--shadow-xs);
        }
        .back-btn:hover { border-color: var(--primary); color: var(--primary); transform: translateX(-2px); }
        .page-title { font-size: 1.6rem; font-weight: 800; letter-spacing: -0.03em; }
        .page-subtitle { font-size: 0.88rem; color: var(--text-muted); margin-top: 0.2rem; }

        /* Live Sync Indicator Pill */
        .sync-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.45rem 0.9rem;
            background: var(--bg-surface);
            border: 1px solid var(--border);
            border-radius: 99px;
            font-size: 0.78rem;
            font-weight: 600;
            color: var(--text-muted);
            box-shadow: var(--shadow-xs);
        }
        .sync-dot {
            width: 8px; height: 8px;
            border-radius: 50%;
            background: var(--success);
            box-shadow: 0 0 0 3px var(--success-soft);
            animation: pulseDot 2s infinite;
        }
        @keyframes pulseDot {
            0%, 100% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.2); opacity: 0.7; }
        }

        /* Policy Selection Matrix */
        .policy-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 1.25rem;
            margin-bottom: 1.5rem;
        }

        .policy-card {
            background: var(--bg-surface);
            border: 2px solid var(--border);
            border-radius: var(--radius-xl);
            padding: 1.5rem;
            cursor: pointer;
            position: relative;
            transition: var(--transition);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        .policy-card:hover {
            border-color: var(--border-hover);
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }
        .policy-card.active.locked {
            border-color: var(--primary);
            background: linear-gradient(180deg, var(--primary-soft) 0%, var(--bg-surface) 100%);
            box-shadow: 0 10px 25px -5px rgba(37, 99, 235, 0.15);
        }
        .policy-card.active.unlocked {
            border-color: var(--success);
            background: linear-gradient(180deg, var(--success-soft) 0%, var(--bg-surface) 100%);
            box-shadow: 0 10px 25px -5px rgba(16, 185, 129, 0.15);
        }

        .policy-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            margin-bottom: 1rem;
        }
        .policy-icon {
            width: 48px; height: 48px;
            border-radius: var(--radius-lg);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
            transition: var(--transition);
        }
        .locked .policy-icon { background: var(--primary-soft); color: var(--primary); }
        .unlocked .policy-icon { background: var(--success-soft); color: var(--success); }

        .radio-indicator {
            width: 22px; height: 22px;
            border-radius: 50%;
            border: 2px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: center;
            transition: var(--transition);
        }
        .policy-card.active.locked .radio-indicator {
            border-color: var(--primary);
            background: var(--primary);
        }
        .policy-card.active.unlocked .radio-indicator {
            border-color: var(--success);
            background: var(--success);
        }
        .radio-indicator::after {
            content: '';
            width: 8px; height: 8px;
            border-radius: 50%;
            background: #ffffff;
            opacity: 0;
            transform: scale(0);
            transition: var(--transition);
        }
        .policy-card.active .radio-indicator::after {
            opacity: 1;
            transform: scale(1);
        }

        .policy-title { font-size: 1.1rem; font-weight: 800; margin-bottom: 0.35rem; }
        .policy-desc { font-size: 0.85rem; color: var(--text-muted); line-height: 1.5; margin-bottom: 1.25rem; }

        .policy-features {
            list-style: none;
            padding-top: 1rem;
            border-top: 1px solid var(--border);
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            font-size: 0.8rem;
            font-weight: 500;
        }
        .policy-features li { display: flex; align-items: center; gap: 0.5rem; }
        .policy-features li i { font-size: 0.85rem; }
        .locked .policy-features li i { color: var(--primary); }
        .unlocked .policy-features li i { color: var(--success); }

        /* Simulator Card (Real-time Officer Perspective) */
        .simulator-card {
            background: var(--bg-surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-xl);
            padding: 1.75rem;
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow-sm);
        }
        .sim-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1.25rem;
        }
        .sim-title {
            font-size: 0.95rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .sim-badge {
            font-size: 0.72rem;
            font-weight: 700;
            padding: 0.25rem 0.65rem;
            border-radius: 99px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .sim-badge.locked { background: var(--primary-soft); color: var(--primary); }
        .sim-badge.unlocked { background: var(--success-soft); color: var(--success); }

        .sim-field-wrap {
            background: var(--bg-subtle);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 1.25rem;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.25rem;
            align-items: center;
        }
        @media (max-width: 768px) {
            .sim-field-wrap { grid-template-columns: 1fr; }
        }

        .sim-label { font-size: 0.78rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; margin-bottom: 0.35rem; display: block; }
        .sim-input-box {
            position: relative;
            display: flex;
            align-items: center;
        }
        .sim-input-box input {
            width: 100%;
            padding: 0.65rem 1rem 0.65rem 2.4rem;
            border-radius: var(--radius-md);
            border: 1px solid var(--border);
            background: var(--bg-surface);
            color: var(--text-main);
            font-family: inherit;
            font-size: 0.875rem;
            font-weight: 600;
            transition: var(--transition);
        }
        .sim-input-box i.input-icon {
            position: absolute;
            left: 0.85rem;
            color: var(--text-muted);
            font-size: 0.9rem;
        }
        .sim-input-box.disabled input {
            background: var(--bg-subtle);
            color: var(--text-muted);
            cursor: not-allowed;
            border-style: dashed;
        }
        .sim-lock-indicator {
            position: absolute;
            right: 0.85rem;
            font-size: 0.8rem;
            color: var(--primary);
        }

        /* Audit & Info Bar */
        .audit-strip {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 1rem;
            padding: 1rem 1.4rem;
            background: var(--bg-surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            font-size: 0.825rem;
            color: var(--text-muted);
        }
        .audit-user { display: flex; align-items: center; gap: 0.5rem; font-weight: 600; color: var(--text-main); }
        
        /* Toast Notifications */
        #toast-container {
            position: fixed;
            bottom: 24px;
            right: 24px;
            z-index: 10000;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .toast {
            background: var(--bg-surface);
            color: var(--text-main);
            padding: 0.9rem 1.2rem;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-lg);
            border: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-size: 0.875rem;
            font-weight: 600;
            min-width: 320px;
            animation: slideToast 0.3s cubic-bezier(0.16, 1, 0.3, 1) forwards;
        }
        .toast-success { border-left: 4px solid var(--success); }
        .toast-success i { color: var(--success); }
        .toast-error { border-left: 4px solid var(--danger); }
        .toast-error i { color: var(--danger); }

        @keyframes slideToast {
            from { opacity: 0; transform: translateY(12px) scale(0.96); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }
    </style>
</head>
<body>

    <!-- Sticky Main Header -->
    <header class="main-header">
        <div class="navbar">
            <a href="dashboard.php" class="logo">
                <img src="<?php echo htmlspecialchars($base_path . 'uploads/CUPAD LOGO.png'); ?>" alt="CUPAD Logo">
                <span>CUPAD Admin</span>
            </a>
            <div class="nav-right">
                <button class="icon-btn" id="themeToggle" title="Toggle Dark/Light Mode">
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
                            <span class="user-role"><?php echo htmlspecialchars($role); ?></span>
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

    <div id="toast-container"></div>

    <main class="container">
        
        <!-- Hero Header -->
        <div class="page-header">
            <div class="header-left">
                <a href="dashboard.php" class="back-btn" title="Return to Dashboard">
                    <i class="fas fa-arrow-left"></i>
                </a>
                <div>
                    <h1 class="page-title">Date Entry Control Policy</h1>
                    <p class="page-subtitle">Configure strict real-time posting rules vs backdated flexibility for collection officers.</p>
                </div>
            </div>
            <div class="sync-pill">
                <span class="sync-dot"></span>
                <span>Active Synchronization</span>
            </div>
        </div>

        <!-- Policy Selection Matrix -->
        <div class="policy-grid">
            
            <!-- Strict Locked Card -->
            <div class="policy-card locked <?php echo $is_locked ? 'active' : ''; ?>" id="cardLocked" onclick="setPolicy(1)">
                <div>
                    <div class="policy-header">
                        <div class="policy-icon">
                            <i class="fas fa-shield-halved"></i>
                        </div>
                        <div class="radio-indicator"></div>
                    </div>
                    <h3 class="policy-title">Strict Policy (Locked)</h3>
                    <p class="policy-desc">
                        Enforces <strong>Today's Date only</strong>. Collection officers cannot modify, backdate, or post-date any entries.
                    </p>
                </div>
                <ul class="policy-features">
                    <li><i class="fas fa-check-circle"></i> Maximum anti-fraud compliance</li>
                    <li><i class="fas fa-check-circle"></i> Prevents retroactive alterations</li>
                    <li><i class="fas fa-check-circle"></i> Date field locked to server timestamp</li>
                </ul>
            </div>

            <!-- Flexible Unlocked Card -->
            <div class="policy-card unlocked <?php echo !$is_locked ? 'active' : ''; ?>" id="cardUnlocked" onclick="setPolicy(0)">
                <div>
                    <div class="policy-header">
                        <div class="policy-icon">
                            <i class="fas fa-calendar-check"></i>
                        </div>
                        <div class="radio-indicator"></div>
                    </div>
                    <h3 class="policy-title">Flexible Policy (Unlocked)</h3>
                    <p class="policy-desc">
                        Allows collection officers to select past or custom calendar dates for record rectification and legacy entries.
                    </p>
                </div>
                <ul class="policy-features">
                    <li><i class="fas fa-check-circle"></i> Enables retroactive backlog catchup</li>
                    <li><i class="fas fa-check-circle"></i> Flexible field corrections</li>
                    <li><i class="fas fa-check-circle"></i> Full date picker functionality</li>
                </ul>
            </div>

        </div>

        <!-- Live Officer Simulation Sandbox -->
        <div class="simulator-card">
            <div class="sim-header">
                <div class="sim-title">
                    <i class="fas fa-desktop" style="color: var(--primary);"></i>
                    Officer Perspective Simulation (Live Preview)
                </div>
                <span class="sim-badge <?php echo $is_locked ? 'locked' : 'unlocked'; ?>" id="simBadge">
                    <?php echo $is_locked ? 'Field Locked & Read-Only' : 'Field Active & Editable'; ?>
                </span>
            </div>

            <div class="sim-field-wrap">
                <div>
                    <span class="sim-label">Collection Date Field</span>
                    <div class="sim-input-box <?php echo $is_locked ? 'disabled' : ''; ?>" id="simInputBox">
                        <i class="fas fa-calendar-days input-icon"></i>
                        <input type="text" id="simDateInput" value="<?php echo date('Y-m-d'); ?>" <?php echo $is_locked ? 'readonly' : ''; ?>>
                        <i class="fas fa-lock sim-lock-indicator" id="simLockIcon" style="<?php echo $is_locked ? '' : 'display:none;'; ?>"></i>
                    </div>
                </div>
                <div>
                    <span class="sim-label">Officer Permission Status</span>
                    <p style="font-size: 0.85rem; color: var(--text-muted); line-height: 1.4;" id="simStatusDesc">
                        <?php if ($is_locked): ?>
                            Officers cannot select or edit dates. Submissions are permanently bound to <strong><?php echo date('F d, Y'); ?></strong>.
                        <?php else: ?>
                            Officers have full access to pick any calendar date from the UI calendar widget.
                        <?php endif; ?>
                    </p>
                </div>
            </div>
        </div>

        <!-- Audit Strip -->
        <div class="audit-strip">
            <div class="audit-user">
                <i class="fas fa-clock-rotate-left" style="color: var(--primary);"></i>
                <span>Last Modified By: <strong id="auditUser"><?php echo htmlspecialchars($settings['updated_by']); ?></strong></span>
            </div>
            <div id="auditTime">
                Updated on <?php echo date('M d, Y • h:i A', strtotime($settings['updated_at'])); ?>
            </div>
        </div>

    </main>

    <script>
        const CSRF_TOKEN = "<?php echo $_SESSION['csrf_token']; ?>";
        let isProcessing = false;

        // Auto-Save Policy Handler
        async function setPolicy(readonlyValue) {
            if (isProcessing) return;
            isProcessing = true;

            const cardLocked = document.getElementById('cardLocked');
            const cardUnlocked = document.getElementById('cardUnlocked');
            
            // Immediate UI feedback
            if (readonlyValue === 1) {
                cardLocked.classList.add('active');
                cardUnlocked.classList.remove('active');
            } else {
                cardUnlocked.classList.add('active');
                cardLocked.classList.remove('active');
            }

            try {
                const formData = new FormData();
                formData.append('ajax_action', 'update_date_control');
                formData.append('date_readonly', readonlyValue.toString());
                formData.append('csrf_token', CSRF_TOKEN);

                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });

                const result = await response.json();

                if (result.success) {
                    updateSimulation(result.date_readonly === 1);
                    document.getElementById('auditUser').textContent = result.updated_by;
                    document.getElementById('auditTime').textContent = "Updated on " + result.updated_at;
                    showToast(result.message, 'success');
                } else {
                    showToast(result.message || 'Failed to update policy.', 'error');
                }
            } catch (error) {
                showToast('Network error. Check connection.', 'error');
            } finally {
                isProcessing = false;
            }
        }

        // Live Preview Renderer
        function updateSimulation(isLocked) {
            const simBadge = document.getElementById('simBadge');
            const simInputBox = document.getElementById('simInputBox');
            const simDateInput = document.getElementById('simDateInput');
            const simLockIcon = document.getElementById('simLockIcon');
            const simStatusDesc = document.getElementById('simStatusDesc');

            if (isLocked) {
                simBadge.className = 'sim-badge locked';
                simBadge.textContent = 'Field Locked & Read-Only';
                simInputBox.classList.add('disabled');
                simDateInput.setAttribute('readonly', 'true');
                simLockIcon.style.display = 'block';
                simStatusDesc.innerHTML = 'Officers cannot select or edit dates. Submissions are permanently bound to <strong>Today\'s Server Date</strong>.';
            } else {
                simBadge.className = 'sim-badge unlocked';
                simBadge.textContent = 'Field Active & Editable';
                simInputBox.classList.remove('disabled');
                simDateInput.removeAttribute('readonly');
                simLockIcon.style.display = 'none';
                simStatusDesc.innerHTML = 'Officers have full access to pick any calendar date from the UI calendar widget.';
            }
        }

        // Toast Engine
        function showToast(message, type = 'success') {
            const container = document.getElementById('toast-container');
            const toast = document.createElement('div');
            toast.className = `toast toast-${type}`;
            
            const icon = type === 'success' ? 'fa-circle-check' : 'fa-triangle-exclamation';
            toast.innerHTML = `<i class="fas ${icon}"></i> <span>${message}</span>`;
            
            container.appendChild(toast);

            setTimeout(() => {
                toast.style.opacity = '0';
                toast.style.transform = 'translateY(10px)';
                toast.style.transition = 'all 0.3s ease';
                setTimeout(() => toast.remove(), 300);
            }, 4000);
        }

        // Dropdown & Theme Initialization
        document.addEventListener('DOMContentLoaded', () => {
            // Theme setup
            if (localStorage.getItem('theme') === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                document.documentElement.classList.add('dark');
            } else {
                document.documentElement.classList.remove('dark');
            }

            document.getElementById('themeToggle')?.addEventListener('click', () => {
                const isDark = document.documentElement.classList.toggle('dark');
                localStorage.setItem('theme', isDark ? 'dark' : 'light');
            });

            // Dropdown Toggle
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