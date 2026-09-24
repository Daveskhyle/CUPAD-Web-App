<?php
// --- 1. CONFIGURATION ---
date_default_timezone_set('Africa/Lagos');
ini_set('date.timezone', 'Africa/Lagos');
session_start();
require_once '../includes/config.php';
$conn = getDbConnection();

// --- 2. AUTH CHECK ---
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: ../index.php');
    exit();
}

$base_path = '../';
$success_message = '';
$error_message = '';

// --- 3. FETCH CURRENT SETTINGS ---
try {
    $stmt = $conn->query("SELECT * FROM savings_settings WHERE id = 1");
    $settings = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$settings) {
        $conn->query("INSERT INTO savings_settings (id) VALUES (1)");
        $settings = [
            'min_savings_amount' => 100.00,
            'max_savings_amount' => 1000000.00,
            'allow_weekend_collection' => 0,
            'savings_date_readonly' => 0
        ];
    }
} catch (PDOException $e) {
    $error_message = "Error loading settings: " . $e->getMessage();
    $settings = [
        'min_savings_amount' => 100.00,
        'max_savings_amount' => 1000000.00,
        'allow_weekend_collection' => 0,
        'savings_date_readonly' => 0
    ];
}

// --- 4. HANDLE FORM SUBMISSION (AJAX) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    header('Content-Type: application/json');
    try {
        $min_amount = floatval($_POST['min_savings_amount'] ?? 100);
        $max_amount = floatval($_POST['max_savings_amount'] ?? 1000000);
        $allow_weekend = isset($_POST['allow_weekend_collection']) ? 1 : 0;
        $date_readonly = isset($_POST['savings_date_readonly']) ? 1 : 0;

        if ($min_amount < 0 || $max_amount < 0) {
            throw new Exception("Amounts cannot be negative");
        }
        if ($min_amount > $max_amount) {
            throw new Exception("Minimum amount cannot be greater than maximum amount");
        }

        // --- MODIFICATION: Removed 'updated_at' column from the query to fix the SQL error.
        $stmt = $conn->prepare("UPDATE savings_settings SET 
            min_savings_amount = ?, 
            max_savings_amount = ?, 
            allow_weekend_collection = ?, 
            savings_date_readonly = ?
            WHERE id = 1");

        $stmt->execute([$min_amount, $max_amount, $allow_weekend, $date_readonly]);

        // Log the action
        $log_stmt = $conn->prepare("INSERT INTO system_logs (user_id, action, details, created_at) VALUES (?, 'update_savings_settings', ?, NOW())");
        $log_details = json_encode([
            'min_savings_amount' => $min_amount,
            'max_savings_amount' => $max_amount,
            'allow_weekend_collection' => $allow_weekend,
            'savings_date_readonly' => $date_readonly
        ]);
        $log_stmt->execute([$_SESSION['user_id'] ?? 0, $log_details]);

        echo json_encode(['success' => true, 'message' => 'Settings saved successfully']);
        exit;
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

// --- 5. USER DATA ---
$full_name = $_SESSION['full_name'] ?? 'Admin';
$username = $_SESSION['username'] ?? '';
$role = $_SESSION['role'] ?? '';

$my_pic = 'default_avatar.png';
try {
    $stmt = $conn->prepare("SELECT profile_pic FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user_data = $stmt->fetch();
    $my_pic = $user_data['profile_pic'] ?? 'default_avatar.png';
} catch (Exception $e) { }

$profile_pic_path = $base_path . 'uploads/' . $my_pic;
$has_profile_pic = file_exists($profile_pic_path) && $my_pic !== 'default_avatar.png';

?>
<!DOCTYPE html>
<html lang="en" class="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Savings Settings - CUPAD</title>
    <link rel="icon" type="image/png" href="<?php echo htmlspecialchars($base_path . 'uploads/CUPAD LOGO.png'); ?>">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />
    <style>
        /* --- MODIFICATION: Changed primary theme colors to green --- */
        :root { 
            --primary: #059669; /* Green-600 */
            --primary-dark: #047857; /* Green-700 */
            --primary-light: #d1fae5; /* Green-100 */
            --success: #059669; 
            --success-light: #d1fae5;
            --warning: #d97706; 
            --warning-light: #ffedd5;
            --danger: #dc2626; 
            --danger-light: #fee2e2;
            --info: #0284c7; 
            --info-light: #e0f2fe;
            --bg-body: #f1f5f9; 
            --bg-surface: #ffffff; 
            --text-main: #0f172a; 
            --text-muted: #64748b; 
            --border: #e2e8f0; 
            --radius-lg: 16px; 
            --radius-md: 10px; 
            --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05); 
            --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1); 
            --shadow-lg: 0 20px 25px -5px rgba(0,0,0,0.1), 0 10px 10px -5px rgba(0,0,0,0.04);
            --nav-height: 70px; 
        }
        html.dark { 
            --bg-body: #0f172a; 
            --bg-surface: #1e293b; 
            --text-main: #f8fafc; 
            --text-muted: #94a3b8; 
            --border: #334155; 
            --primary: #34d399; /* Green-400 */
            --primary-light: rgba(52, 211, 153, 0.2);
            --success: #34d399; 
            --success-light: rgba(52, 211, 153, 0.2);
            --warning: #fbbf24; 
            --warning-light: rgba(245, 158, 11, 0.2);
            --danger: #f87171; 
            --danger-light: rgba(244, 63, 94, 0.2);
            --info: #38bdf8; 
            --info-light: rgba(56, 189, 248, 0.2);
        }
        * { box-sizing: border-box; outline: none; }
        body { 
            font-family: 'Plus Jakarta Sans', sans-serif; 
            background: var(--bg-body); 
            color: var(--text-main); 
            margin: 0; 
            padding-top: var(--nav-height); 
            transition: background-color 0.3s, color 0.3s; 
        }
        a { text-decoration: none; color: inherit; }

        /* Toast Notifications */
        .toast-container {
            position: fixed;
            top: calc(var(--nav-height) + 1rem);
            right: 1.5rem;
            z-index: 9999;
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            pointer-events: none;
        }
        .toast {
            background: var(--bg-surface);
            border-left: 4px solid var(--success);
            padding: 1rem 1.5rem;
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-lg);
            display: flex;
            align-items: center;
            gap: 0.75rem;
            min-width: 300px;
            transform: translateX(400px);
            opacity: 0;
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            pointer-events: auto;
        }
        .toast.show {
            transform: translateX(0);
            opacity: 1;
        }
        .toast.error {
            border-left-color: var(--danger);
        }
        .toast-icon {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: var(--success-light);
            color: var(--success);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .toast.error .toast-icon {
            background: var(--danger-light);
            color: var(--danger);
        }
        .toast-content {
            flex: 1;
        }
        .toast-title {
            font-weight: 700;
            font-size: 0.95rem;
            margin-bottom: 0.25rem;
        }
        .toast-message {
            font-size: 0.85rem;
            color: var(--text-muted);
        }
        .toast-close {
            background: none;
            border: none;
            color: var(--text-muted);
            cursor: pointer;
            padding: 0.25rem;
            transition: color 0.2s;
        }
        .toast-close:hover {
            color: var(--text-main);
        }

        /* Header Styles */
        .main-header { 
            position: fixed; 
            top: 0; 
            left: 0; 
            right: 0; 
            height: var(--nav-height); 
            background: rgba(255,255,255,0.85); 
            backdrop-filter: blur(12px); 
            border-bottom: 1px solid var(--border); 
            z-index: 50; 
            transition: background 0.3s; 
        }
        html.dark .main-header { background: rgba(15, 23, 42, 0.85); }
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
            gap: 0.75rem; 
            font-weight: 800; 
            font-size: 1.35rem; 
            color: var(--primary); 
        }
        .logo img { height: 38px; }
        .nav-right { display: flex; align-items: center; gap: 1rem; }
        .icon-btn { 
            width: 36px; 
            height: 36px; 
            border-radius: 50%; 
            border: none; 
            background: transparent; 
            color: var(--text-muted); 
            cursor: pointer; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            font-size: 1.1rem; 
            transition: 0.2s; 
        }
        .icon-btn:hover { background: var(--bg-body); color: var(--text-main); }
        .user-dropdown-wrap { position: relative; }
        .user-pill { 
            display: flex; 
            align-items: center; 
            gap: 0.75rem; 
            padding: 4px 8px 4px 4px; 
            border: 1px solid var(--border); 
            border-radius: 99px; 
            background: var(--bg-surface); 
            cursor: pointer; 
            transition: all 0.2s; 
        }
        .user-pill:hover { border-color: var(--primary); box-shadow: var(--shadow-sm); }
        .user-avatar { 
            width: 34px; 
            height: 34px; 
            border-radius: 50%; 
            object-fit: cover; 
        }
        .user-avatar-fallback { 
            width: 34px; 
            height: 34px; 
            border-radius: 50%; 
            background: var(--bg-body); 
            color: var(--text-muted); 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            font-size: 1rem; 
            border: 1px solid var(--border); 
        }
        .user-info { 
            display: flex; 
            flex-direction: column; 
            line-height: 1.1; 
            padding-right: 0.5rem; 
        }
        .user-name { font-weight: 600; font-size: 0.85rem; }
        .user-role { font-size: 0.7rem; color: var(--text-muted); text-transform: uppercase; }
        .dropdown-menu { 
            position: absolute; 
            top: 125%; 
            right: 0; 
            background: var(--bg-surface); 
            border: 1px solid var(--border); 
            border-radius: var(--radius-md); 
            box-shadow: var(--shadow-md); 
            min-width: 200px; 
            display: none; 
            z-index: 1000; 
            flex-direction: column; 
            overflow: hidden; 
            animation: scaleIn 0.2s ease; 
            transform-origin: top right; 
        }
        @keyframes scaleIn { 
            from { opacity: 0; transform: scale(0.95); } 
            to { opacity: 1; transform: scale(1); } 
        }
        .dropdown-menu.show { display: flex; }
        .dropdown-item { 
            padding: 0.75rem 1rem; 
            display: flex; 
            align-items: center; 
            gap: 0.75rem; 
            font-size: 0.9rem; 
            transition: 0.2s; 
        }
        .dropdown-item:hover { background: var(--bg-body); color: var(--primary); }
        .text-danger { color: var(--danger) !important; }

        /* Main Container */
        .container { 
            max-width: 800px; 
            margin: 0 auto; 
            padding: 2rem 1.5rem 4rem; 
        }

        /* Page Header */
        .page-header {
            margin-bottom: 2rem;
            position: relative;
        }
        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--text-muted);
            font-size: 0.9rem;
            margin-bottom: 1rem;
            transition: all 0.2s;
            padding: 0.5rem 0;
        }
        .back-link:hover {
            color: var(--primary);
            transform: translateX(-4px);
        }
        .page-title-wrap {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        .page-icon {
            width: 56px;
            height: 56px;
            border-radius: var(--radius-lg);
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            box-shadow: var(--shadow-md);
        }
        .page-title-content h1 {
            margin: 0;
            font-size: 1.75rem;
            font-weight: 800;
            color: var(--text-main);
        }
        .page-title-content p {
            margin: 0.25rem 0 0;
            color: var(--text-muted);
            font-size: 0.95rem;
        }

        /* Card Styles */
        .card { 
            background: var(--bg-surface); 
            border: 1px solid var(--border); 
            border-radius: var(--radius-lg); 
            padding: 1.5rem; 
            box-shadow: var(--shadow-sm);
            margin-bottom: 1.5rem;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .card:hover {
            box-shadow: var(--shadow-md);
        }

        .card-header {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 1.5rem;
        }

        .card-header-icon {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            background: var(--primary-light);
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
        }

        .card-header h2 {
            margin: 0;
            font-size: 1.1rem;
            font-weight: 700;
        }
        .card-header p {
            margin: 0.25rem 0 0;
            font-size: 0.85rem;
            color: var(--text-muted);
        }

        /* Form Styles */
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
        }

        .form-group {
            margin-bottom: 1.25rem;
        }

        .form-label {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 0.5rem;
            font-weight: 600;
            font-size: 0.9rem;
            color: var(--text-main);
        }

        .badge {
            font-size: 0.7rem;
            padding: 0.25rem 0.5rem;
            border-radius: 99px;
            background: var(--bg-body);
            color: var(--text-muted);
            font-weight: 500;
        }

        .input-wrapper {
            position: relative;
        }

        .input-icon {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            font-size: 1rem;
            transition: color 0.2s;
        }

        input[type="number"],
        input[type="text"] {
            width: 100%;
            padding: 0.875rem 1rem 0.875rem 2.5rem;
            border-radius: var(--radius-md);
            border: 2px solid var(--border);
            background: var(--bg-body);
            color: var(--text-main);
            font-family: inherit;
            font-size: 0.95rem;
            transition: all 0.2s;
        }

        input[type="number"]:focus,
        input[type="text"]:focus {
            border-color: var(--primary);
            background: var(--bg-surface);
            box-shadow: 0 0 0 4px rgba(5, 150, 105, 0.1); /* MODIFICATION: Adjusted shadow color */
        }

        input[type="number"]:focus + .input-icon,
        input[type="text"]:focus + .input-icon {
            color: var(--primary);
        }

        .input-hint {
            font-size: 0.8rem;
            color: var(--text-muted);
            margin-top: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.4rem;
        }

        .validation-msg {
            font-size: 0.8rem;
            margin-top: 0.5rem;
            display: none;
            align-items: center;
            gap: 0.4rem;
        }
        .validation-msg.show {
            display: flex;
        }
        .validation-msg.error {
            color: var(--danger);
        }
        .validation-msg.success {
            color: var(--success);
        }

        /* Toggle Switch */
        .toggle-group {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 1.25rem;
            background: var(--bg-body);
            border-radius: var(--radius-md);
            border: 2px solid transparent;
            margin-bottom: 1rem;
            transition: all 0.2s;
            cursor: pointer;
        }

        .toggle-group:hover {
            border-color: var(--primary);
            background: var(--bg-surface);
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }

        .toggle-group.active {
            border-color: var(--primary);
            background: var(--primary-light);
        }

        .toggle-info {
            display: flex;
            align-items: center;
            gap: 1rem;
            flex: 1;
        }

        .toggle-icon-wrap {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            background: var(--bg-surface);
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            transition: all 0.2s;
        }
        .toggle-group:hover .toggle-icon-wrap {
            transform: scale(1.1);
        }

        .toggle-text h4 {
            margin: 0 0 0.25rem;
            font-size: 0.95rem;
            font-weight: 600;
        }

        .toggle-text p {
            margin: 0;
            font-size: 0.8rem;
            color: var(--text-muted);
        }

        .switch { 
            position: relative; 
            display: inline-block; 
            width: 52px; 
            height: 30px; 
            flex-shrink: 0;
        }
        .switch input { 
            opacity: 0; 
            width: 0; 
            height: 0; 
        }
        .slider { 
            position: absolute; 
            cursor: pointer; 
            top: 0; 
            left: 0; 
            right: 0; 
            bottom: 0; 
            background-color: var(--border); 
            transition: .4s; 
            border-radius: 30px; 
        }
        .slider:before { 
            position: absolute; 
            content: "\f00d"; /* FontAwesome fa-times icon */
            font-family: "Font Awesome 6 Free";
            font-weight: 900;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            color: var(--text-muted);
            height: 24px; 
            width: 24px; 
            left: 3px; 
            bottom: 3px; 
            background-color: white; 
            transition: .4s cubic-bezier(0.4, 0.0, 0.2, 1); 
            border-radius: 50%; 
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        input:checked + .slider { 
            background-color: var(--success); 
        }
        input:checked + .slider:before { 
            transform: translateX(22px); 
            content: "\f00c"; /* FontAwesome fa-check icon */
            color: var(--success);
        }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: var(--bg-surface);
            padding: 1.25rem;
            border-radius: var(--radius-md);
            border: 1px solid var(--border);
            text-align: center;
            transition: all 0.2s;
            position: relative;
            overflow: hidden;
        }
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: var(--primary);
            transform: scaleX(0);
            transition: transform 0.3s;
        }
        .stat-card:hover::before {
            transform: scaleX(1);
        }
        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-md);
        }

        .stat-value {
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--primary);
            margin-bottom: 0.25rem;
            transition: all 0.3s;
        }
        .stat-card.changed .stat-value {
            color: var(--warning);
            transform: scale(1.1);
        }

        .stat-label {
            font-size: 0.75rem;
            color: var(--text-muted);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-status {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            font-size: 0.75rem;
            margin-top: 0.5rem;
            padding: 0.25rem 0.5rem;
            border-radius: 99px;
            background: var(--bg-body);
        }
        .stat-status.active {
            background: var(--success-light);
            color: var(--success);
        }
        .stat-status.inactive {
            background: var(--danger-light);
            color: var(--danger);
        }

        /* --- MODIFICATION: Added auto-save status styles --- */
        .auto-save-status {
            text-align: center;
            padding: 1rem;
            margin-top: 2rem;
            font-size: 0.9rem;
            color: var(--text-muted);
            border-radius: var(--radius-md);
            height: 50px; /* Prevent layout shift */
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            transition: all 0.3s ease-in-out;
        }
        .auto-save-status.saving {
            color: var(--warning);
            background: var(--warning-light);
        }
        .auto-save-status.success {
            color: var(--success);
            background: var(--success-light);
        }
        .auto-save-status.error {
            color: var(--danger);
            background: var(--danger-light);
        }

        @keyframes spin-sm {
            to { transform: rotate(360deg); }
        }

        /* Responsive Improvements */
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            .form-row {
                grid-template-columns: 1fr;
            }
            .toggle-group {
                flex-direction: row;
                text-align: left;
                padding: 1rem;
                gap: 1rem;
            }
            .toggle-info {
                flex-direction: row;
                gap: 0.75rem;
            }
        }

        @media (max-width: 640px) {
            .navbar {
                padding: 0 1rem;
            }
            .container {
                padding: 1rem 1rem 4rem; 
            }
            .card {
                padding: 1.25rem;
            }
            
            .page-title-wrap {
                flex-direction: row;
                align-items: center;
                text-align: left;
            }
            .page-icon {
                width: 48px;
                height: 48px;
                font-size: 1.25rem;
            }
            .page-title-content h1 {
                font-size: 1.4rem;
            }

            /* Compact header user badge */
            .user-info, .user-pill .fa-chevron-down {
                display: none;
            }
            .user-pill {
                padding: 2px;
                border-radius: 50%;
            }

            .stat-value {
                font-size: 1.2rem;
            }

            /* Compact toggle list items */
            .toggle-icon-wrap {
                width: 36px;
                height: 36px;
                font-size: 0.9rem;
            }
            .toggle-text h4 {
                font-size: 0.9rem;
            }
            .toggle-text p {
                font-size: 0.75rem;
            }
        }
    </style>
</head>
<body>

    <!-- Toast Container -->
    <div class="toast-container" id="toastContainer"></div>

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
                        <?php if($has_profile_pic): ?>
                            <img src="<?php echo htmlspecialchars($profile_pic_path); ?>" class="user-avatar" alt="User">
                        <?php else: ?>
                            <div class="user-avatar-fallback"><i class="fas fa-user"></i></div>
                        <?php endif; ?>
                        <div class="user-info">
                            <span class="user-name"><?php echo htmlspecialchars($full_name); ?></span>
                            <span class="user-role"><?php echo ucfirst(htmlspecialchars($role)); ?></span>
                        </div>
                        <i class="fas fa-chevron-down" style="font-size:0.7rem; color:var(--text-muted)"></i>
                    </div>
                    <div class="dropdown-menu" id="userDropdown">
                        <div style="padding: 0.75rem 1rem; font-size: 0.75rem; color:var(--text-muted); font-weight:600">SIGNED IN AS</div>
                        <div style="padding: 0 1rem 0.5rem; font-weight:700"><?php echo htmlspecialchars($username); ?></div>
                        <div style="height:1px; background:var(--border); margin:0"></div>
                        <a href="profile.php" class="dropdown-item"><i class="fas fa-user-circle" style="color:var(--primary)"></i> My Profile</a>
                        <div style="height:1px; background:var(--border); margin:0"></div>
                        <a href="../logout.php" class="dropdown-item text-danger"><i class="fas fa-sign-out-alt"></i> Logout</a>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <main class="container">
        <div class="page-header">
            <a href="dashboard.php" class="back-link">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>
            
            <div class="page-title-wrap">
                <div class="page-icon">
                    <i class="fas fa-piggy-bank"></i>
                </div>
                <div class="page-title-content">
                    <h1>Savings Settings</h1>
                    <p>Configure collection parameters and transaction limits</p>
                </div>
            </div>
        </div>

        <!-- Live Preview Stats -->
        <div class="stats-grid">
            <div class="stat-card" id="statMin">
                <div class="stat-value">₦<?php echo number_format($settings['min_savings_amount'], 2); ?></div>
                <div class="stat-label">Min Amount</div>
            </div>
            <div class="stat-card" id="statMax">
                <div class="stat-value">₦<?php echo number_format($settings['max_savings_amount'], 2); ?></div>
                <div class="stat-label">Max Amount</div>
            </div>
            <div class="stat-card">
                <div class="stat-status <?php echo $settings['allow_weekend_collection'] ? 'active' : 'inactive'; ?>" id="statWeekend">
                    <i class="fas fa-<?php echo $settings['allow_weekend_collection'] ? 'check' : 'times'; ?>"></i>
                    <?php echo $settings['allow_weekend_collection'] ? 'Enabled' : 'Disabled'; ?>
                </div>
                <div class="stat-label" style="margin-top:0.5rem;">Weekend</div>
            </div>
            <div class="stat-card">
                <div class="stat-status <?php echo $settings['savings_date_readonly'] ? 'active' : 'inactive'; ?>" id="statReadonly">
                    <i class="fas fa-<?php echo $settings['savings_date_readonly'] ? 'lock' : 'unlock'; ?>"></i>
                    <?php echo $settings['savings_date_readonly'] ? 'Locked' : 'Editable'; ?>
                </div>
                <div class="stat-label" style="margin-top:0.5rem;">Date Field</div>
            </div>
        </div>

        <form method="POST" action="" id="settingsForm">
            <div class="card">
                <div class="card-header">
                    <div class="card-header-icon">
                        <i class="fas fa-sliders-h"></i>
                    </div>
                    <div>
                        <h2>Amount Limits</h2>
                        <p>Set minimum and maximum transaction thresholds</p>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">
                            Minimum Amount
                            <span class="badge">Required</span>
                        </label>
                        <div class="input-wrapper">
                            <i class="fas fa-naira-sign input-icon"></i>
                            <input type="number" name="min_savings_amount" id="minAmount" step="0.01" min="0" 
                                   value="<?php echo htmlspecialchars($settings['min_savings_amount']); ?>" required>
                        </div>
                        <div class="input-hint">
                            <i class="fas fa-info-circle"></i>
                            Lowest amount per transaction
                        </div>
                        <div class="validation-msg" id="minValidation"></div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">
                            Maximum Amount
                            <span class="badge">Required</span>
                        </label>
                        <div class="input-wrapper">
                            <i class="fas fa-naira-sign input-icon"></i>
                            <input type="number" name="max_savings_amount" id="maxAmount" step="0.01" min="0" 
                                   value="<?php echo htmlspecialchars($settings['max_savings_amount']); ?>" required>
                        </div>
                        <div class="input-hint">
                            <i class="fas fa-info-circle"></i>
                            Highest amount per transaction
                        </div>
                        <div class="validation-msg" id="maxValidation"></div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                     <!-- --- MODIFICATION: Changed icon from fa-cog to fa-tasks --- -->
                    <div class="card-header-icon" style="background: var(--info-light); color: var(--info);">
                        <i class="fas fa-tasks"></i>
                    </div>
                    <div>
                        <h2>Collection Settings</h2>
                        <p>Manage operational preferences</p>
                    </div>
                </div>

                <div class="toggle-group" onclick="document.getElementById('weekendToggle').click()">
                    <div class="toggle-info">
                        <div class="toggle-icon-wrap">
                            <i class="fas fa-calendar-weekend"></i>
                        </div>
                        <div class="toggle-text">
                            <h4>Allow Weekend Collection</h4>
                            <p>Enable savings collection on Saturdays and Sundays</p>
                        </div>
                    </div>
                    <label class="switch" onclick="event.stopPropagation()">
                        <input type="checkbox" name="allow_weekend_collection" id="weekendToggle"
                               <?php echo $settings['allow_weekend_collection'] ? 'checked' : ''; ?>>
                        <span class="slider"></span>
                    </label>
                </div>

                <div class="toggle-group" onclick="document.getElementById('readonlyToggle').click()">
                    <div class="toggle-info">
                        <div class="toggle-icon-wrap">
                            <i class="fas fa-calendar-lock"></i>
                        </div>
                        <div class="toggle-text">
                            <h4>Savings Date Readonly</h4>
                            <p>Prevent users from modifying transaction dates</p>
                        </div>
                    </div>
                    <label class="switch" onclick="event.stopPropagation()">
                        <input type="checkbox" name="savings_date_readonly" id="readonlyToggle"
                               <?php echo $settings['savings_date_readonly'] ? 'checked' : ''; ?>>
                        <span class="slider"></span>
                    </label>
                </div>
            </div>
            
            <!-- --- MODIFICATION: Removed save button group and added auto-save status indicator --- -->
            <div id="autoSaveStatus" class="auto-save-status"></div>
        </form>

        <div style="text-align: center; margin-top: 1rem; color: var(--text-muted); font-size: 0.8rem;">
            <i class="fas fa-info-circle"></i> Changes are saved automatically.
        </div>
    </main>

    <script>
        // Dropdown Toggle
        const userTrigger = document.getElementById('userDropdownTrigger');
        const userDropdown = document.getElementById('userDropdown');

        userTrigger.addEventListener('click', (e) => { 
            e.stopPropagation(); 
            userDropdown.classList.toggle('show'); 
        });

        document.addEventListener('click', (e) => { 
            if (!userTrigger.contains(e.target) && !userDropdown.contains(e.target)) {
                userDropdown.classList.remove('show'); 
            }
        });

        // Theme Toggle
        const themeToggle = document.getElementById('themeToggle');
        const html = document.documentElement;

        themeToggle.addEventListener('click', () => {
            const isDark = html.classList.toggle('dark');
            html.classList.toggle('light');
            localStorage.setItem('theme', isDark ? 'dark' : 'light');
            themeToggle.innerHTML = isDark ? '<i class="fas fa-sun"></i>' : '<i class="fas fa-moon"></i>';
        });

        if (localStorage.getItem('theme') === 'dark') {
            html.classList.add('dark');
            html.classList.remove('light');
            themeToggle.innerHTML = '<i class="fas fa-sun"></i>';
        }

        // Toast Notification System
        function showToast(message, type = 'success') {
            const container = document.getElementById('toastContainer');
            const toast = document.createElement('div');
            toast.className = `toast ${type}`;
            toast.innerHTML = `
                <div class="toast-icon">
                    <i class="fas fa-${type === 'success' ? 'check' : 'exclamation'}"></i>
                </div>
                <div class="toast-content">
                    <div class="toast-title">${type === 'success' ? 'Success' : 'Error'}</div>
                    <div class="toast-message">${message}</div>
                </div>
                <button class="toast-close" onclick="this.parentElement.remove()">
                    <i class="fas fa-times"></i>
                </button>
            `;
            container.appendChild(toast);
            
            setTimeout(() => toast.classList.add('show'), 10);
            setTimeout(() => {
                toast.classList.remove('show');
                setTimeout(() => toast.remove(), 400);
            }, 5000);
        }

        // --- MODIFICATION: Entire form submission logic refactored for auto-saving ---

        // Form Elements
        const settingsForm = document.getElementById('settingsForm');
        const minInput = document.getElementById('minAmount');
        const maxInput = document.getElementById('maxAmount');
        const weekendToggle = document.getElementById('weekendToggle');
        const readonlyToggle = document.getElementById('readonlyToggle');
        const autoSaveStatus = document.getElementById('autoSaveStatus');
        
        let initialLoad = true;

        function formatCurrency(amount) {
            return '₦' + parseFloat(amount).toLocaleString('en-NG', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }

        function updateStats() {
            document.querySelector('#statMin .stat-value').textContent = formatCurrency(minInput.value);
            document.querySelector('#statMax .stat-value').textContent = formatCurrency(maxInput.value);
            
            const weekendStatus = document.getElementById('statWeekend');
            weekendStatus.className = `stat-status ${weekendToggle.checked ? 'active' : 'inactive'}`;
            weekendStatus.innerHTML = `<i class="fas fa-${weekendToggle.checked ? 'check' : 'times'}"></i> ${weekendToggle.checked ? 'Enabled' : 'Disabled'}`;
            
            const readonlyStatus = document.getElementById('statReadonly');
            readonlyStatus.className = `stat-status ${readonlyToggle.checked ? 'active' : 'inactive'}`;
            readonlyStatus.innerHTML = `<i class="fas fa-${readonlyToggle.checked ? 'lock' : 'unlock'}"></i> ${readonlyToggle.checked ? 'Locked' : 'Editable'}`;
        }

        function validateInputs() {
            const min = parseFloat(minInput.value) || 0;
            const max = parseFloat(maxInput.value) || 0;
            const minVal = document.getElementById('minValidation');
            const maxVal = document.getElementById('maxValidation');
            
            let isValid = true;
            
            if (min > max) {
                minVal.textContent = 'Cannot be greater than maximum';
                minVal.className = 'validation-msg error show';
                maxVal.textContent = 'Must be greater than minimum';
                maxVal.className = 'validation-msg error show';
                isValid = false;
            } else {
                minVal.className = 'validation-msg';
                maxVal.className = 'validation-msg';
            }
            return isValid;
        }

        // Debounce function to delay execution
        function debounce(func, delay = 1500) {
            let timeout;
            return function(...args) {
                clearTimeout(timeout);
                timeout = setTimeout(() => {
                    func.apply(this, args);
                }, delay);
            };
        }

        const autoSaveChanges = async () => {
            if (!validateInputs()) {
                autoSaveStatus.className = 'auto-save-status error';
                autoSaveStatus.innerHTML = `<i class="fas fa-exclamation-triangle"></i> Please fix validation errors.`;
                return;
            }

            autoSaveStatus.className = 'auto-save-status saving';
            autoSaveStatus.innerHTML = `<i class="fas fa-spinner" style="animation: spin-sm 1s linear infinite;"></i> Saving...`;
            
            const formData = new FormData(settingsForm);
            formData.append('ajax', '1');
            
            try {
                const response = await fetch('', {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();
                
                if (result.success) {
                    showToast(result.message, 'success');
                    autoSaveStatus.className = 'auto-save-status success';
                    autoSaveStatus.innerHTML = `<i class="fas fa-check-circle"></i> All changes saved.`;
                } else {
                    showToast(result.message, 'error');
                    autoSaveStatus.className = 'auto-save-status error';
                    autoSaveStatus.innerHTML = `<i class="fas fa-times-circle"></i> ${result.message}`;
                }
            } catch (error) {
                showToast('Network error. Please try again.', 'error');
                autoSaveStatus.className = 'auto-save-status error';
                autoSaveStatus.innerHTML = `<i class="fas fa-shield-alt"></i> Network error. Please try again.`;
            }
        };

        const debouncedSave = debounce(autoSaveChanges);

        function handleInputChange() {
            updateStats();
            validateInputs();
            if (initialLoad) return; 
            
            autoSaveStatus.className = 'auto-save-status';
            autoSaveStatus.innerHTML = `Unsaved changes...`;
            debouncedSave();
        }

        [minInput, maxInput].forEach(input => input.addEventListener('input', handleInputChange));
        [weekendToggle, readonlyToggle].forEach(toggle => toggle.addEventListener('change', handleInputChange));

        // Toggle group click handling
        document.querySelectorAll('.toggle-group').forEach(group => {
            const checkbox = group.querySelector('input[type="checkbox"]');
            checkbox.addEventListener('change', () => {
                group.classList.toggle('active', checkbox.checked);
            });
            if (checkbox.checked) group.classList.add('active');
        });
        
        // Prevent form submission on Enter key
        settingsForm.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
            }
        });

        // Initialize and mark initial load as complete
        updateStats();
        setTimeout(() => { initialLoad = false; }, 500);

    </script>
</body>
</html>