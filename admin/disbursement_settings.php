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

// --- 3. HANDLE AJAX REQUESTS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'save_settings':
                $fields =[
                    'min_disbursement' => floatval($_POST['min_disbursement'] ?? 0),
                    'max_disbursement' => floatval($_POST['max_disbursement'] ?? 0),
                    'default_num_installments' => intval($_POST['default_num_installments'] ?? 1),
                    'default_weekly_installments' => intval($_POST['default_weekly_installments'] ?? 1),
                    'min_days_after_registration' => intval($_POST['min_days_after_registration'] ?? 0),
                    'global_max_first_loan_amount' => floatval($_POST['global_max_first_loan_amount'] ?? 0),
                    'global_max_increment_amount' => floatval($_POST['global_max_increment_amount'] ?? 0),
                    'min_waiting_days_full_term' => intval($_POST['min_waiting_days_full_term'] ?? 0),
                    'min_waiting_days_early_payoff' => intval($_POST['min_waiting_days_early_payoff'] ?? 0),
                    'savings_requirement_percentage' => floatval($_POST['savings_requirement_percentage'] ?? 0),
                    'default_interest_rate' => floatval($_POST['default_interest_rate'] ?? 0),
                    'default_weekly_interest_rate' => floatval($_POST['default_weekly_interest_rate'] ?? 0),
                    'disbursement_date_readonly' => isset($_POST['disbursement_date_readonly']) ? 1 : 0,
                    'allow_weekends' => isset($_POST['allow_weekends']) ? 1 : 0,
                    'duplicate_check_seconds' => intval($_POST['duplicate_check_seconds'] ?? 300)
                ];

                // Validation
                if ($fields['min_disbursement'] > $fields['max_disbursement']) {
                    throw new Exception("Minimum disbursement cannot exceed maximum");
                }
                if ($fields['default_num_installments'] < 1 || $fields['default_weekly_installments'] < 1) {
                    throw new Exception("Installments must be at least 1");
                }
                if ($fields['duplicate_check_seconds'] < 0) {
                    throw new Exception("Duplicate check seconds cannot be negative");
                }

                $sql = "UPDATE disbursement_settings SET " . 
                       implode('=?, ', array_keys($fields)) . "=? WHERE id=1";
                $stmt = $conn->prepare($sql);
                $stmt->execute(array_values($fields));

                // Log action
                $log_stmt = $conn->prepare("INSERT INTO system_logs (user_id, action, details, created_at) VALUES (?, 'update_disbursement_settings', ?, NOW())");
                $log_stmt->execute([$_SESSION['user_id'] ?? 0, json_encode($fields)]);

                echo json_encode(['success' => true, 'message' => 'Settings saved successfully']);
                exit;

            case 'update_message':
                $id = intval($_POST['id'] ?? 0);
                $message_text = trim($_POST['message_text'] ?? '');
                
                if (empty($message_text)) {
                    throw new Exception("Message text cannot be empty");
                }
                if (strlen($message_text) > 255) {
                    throw new Exception("Message text too long (max 255 characters)");
                }

                $stmt = $conn->prepare("UPDATE disbursement_messages SET message_text = ? WHERE id = ?");
                $stmt->execute([$message_text, $id]);

                echo json_encode(['success' => true, 'message' => 'Message updated successfully']);
                exit;

            case 'get_message':
                $id = intval($_POST['id'] ?? 0);
                $stmt = $conn->prepare("SELECT * FROM disbursement_messages WHERE id = ?");
                $stmt->execute([$id]);
                $message = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$message) throw new Exception("Message not found");
                echo json_encode(['success' => true, 'data' => $message]);
                exit;
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

// --- 4. FETCH DATA ---
try {
    $settings_stmt = $conn->query("SELECT * FROM disbursement_settings WHERE id = 1");
    $settings = $settings_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$settings) {
        // Insert default if not exists
        $conn->query("INSERT INTO disbursement_settings (id) VALUES (1)");
        $settings = $conn->query("SELECT * FROM disbursement_settings WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
    }

    $messages_stmt = $conn->query("SELECT * FROM disbursement_messages ORDER BY id");
    $messages = $messages_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error_message = "Error loading data: " . $e->getMessage();
    $settings =[];
    $messages =[];
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

function formatCurrency($amount) {
    return '₦' . number_format($amount, 2);
}
?>
<!DOCTYPE html>
<html lang="en" class="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Disbursement Settings - CUPAD</title>
    <link rel="icon" type="image/png" href="<?php echo htmlspecialchars($base_path . 'uploads/CUPAD LOGO.png'); ?>">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />
    <style>
        :root { 
            --primary: #2563eb; 
            --primary-dark: #1d4ed8; 
            --primary-light: #eff6ff;
            --success: #059669; 
            --success-light: #d1fae5;
            --warning: #d97706; 
            --warning-light: #ffedd5;
            --danger: #dc2626; 
            --danger-light: #fee2e2;
            --info: #0ea5e9; 
            --info-light: #e0f2fe;
            --bg-body: #f8fafc; 
            --bg-surface: #ffffff; 
            --text-main: #0f172a; 
            --text-muted: #64748b; 
            --border: #e2e8f0; 
            --radius-lg: 16px; 
            --radius-md: 10px; 
            --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05); 
            --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.05), 0 2px 4px -1px rgb(0 0 0 / 0.03); 
            --shadow-lg: 0 20px 25px -5px rgba(0,0,0,0.1), 0 10px 10px -5px rgba(0,0,0,0.04);
            --nav-height: 70px; 
        }
        html.dark { 
            --bg-body: #0f172a; 
            --bg-surface: #1e293b; 
            --text-main: #f8fafc; 
            --text-muted: #94a3b8; 
            --border: #334155; 
            --primary: #3b82f6; 
            --primary-dark: #60a5fa;
            --primary-light: rgba(59, 130, 246, 0.15);
            --success: #34d399; 
            --success-light: rgba(16, 185, 129, 0.2);
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
            backdrop-filter: blur(16px); 
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
            max-width: 1200px; 
            margin: 0 auto; 
            padding: 2.5rem 1.5rem 4rem; 
        }

        /* Page Header */
        .page-header {
            margin-bottom: 2.5rem;
            position: relative;
        }
        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--text-muted);
            font-size: 0.9rem;
            margin-bottom: 1.25rem;
            transition: all 0.2s;
            padding: 0.5rem 0;
            font-weight: 500;
        }
        .back-link:hover {
            color: var(--primary);
            transform: translateX(-4px);
        }
        .page-title-wrap {
            display: flex;
            align-items: center;
            gap: 1.25rem;
        }
        .page-icon {
            width: 60px;
            height: 60px;
            border-radius: var(--radius-lg);
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.75rem;
            box-shadow: 0 10px 15px -3px rgba(37, 99, 235, 0.2);
        }
        .page-title-content h1 {
            margin: 0;
            font-size: 1.85rem;
            font-weight: 800;
            color: var(--text-main);
            letter-spacing: -0.02em;
        }
        .page-title-content p {
            margin: 0.35rem 0 0;
            color: var(--text-muted);
            font-size: 1rem;
        }

        /* Tabs */
        .tabs {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 2rem;
            border-bottom: 2px solid var(--border);
            padding-bottom: 0;
        }
        .tab {
            padding: 0.875rem 1.5rem;
            font-weight: 600;
            font-size: 0.95rem;
            color: var(--text-muted);
            cursor: pointer;
            border-bottom: 3px solid transparent;
            margin-bottom: -2px;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .tab:hover {
            color: var(--primary);
            background: var(--bg-surface);
            border-radius: var(--radius-md) var(--radius-md) 0 0;
        }
        .tab.active {
            color: var(--primary);
            border-bottom-color: var(--primary);
        }
        .tab-content {
            display: none;
        }
        .tab-content.active {
            display: block;
            animation: fadeIn 0.3s ease;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Stats Overview */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1.25rem;
            margin-bottom: 2.5rem;
        }
        .stat-card {
            background: var(--bg-surface);
            padding: 1.5rem;
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            text-align: center;
            transition: all 0.2s ease;
            box-shadow: var(--shadow-sm);
        }
        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-md);
            border-color: var(--primary-light);
        }
        .stat-value {
            font-size: 1.75rem;
            font-weight: 800;
            color: var(--primary);
            margin-bottom: 0.35rem;
            letter-spacing: -0.02em;
        }
        .stat-label {
            font-size: 0.8rem;
            color: var(--text-muted);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Card Styles */
        .card { 
            background: var(--bg-surface); 
            border: 1px solid var(--border); 
            border-radius: var(--radius-lg); 
            padding: 2rem; 
            box-shadow: var(--shadow-md);
            margin-bottom: 2rem;
            transition: box-shadow 0.3s ease;
        }
        .card-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1.75rem;
        }
        .card-header-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            background: var(--primary-light);
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.35rem;
        }
        .card-header h2 {
            margin: 0;
            font-size: 1.15rem;
            font-weight: 700;
        }
        .card-header p {
            margin: 0.25rem 0 0;
            font-size: 0.9rem;
            color: var(--text-muted);
        }

        /* Form Styles */
        .form-row {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1.5rem;
            margin-bottom: 1.25rem;
        }
        .form-group {
            margin-bottom: 1.25rem;
        }
        .form-label {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 0.65rem;
            font-weight: 600;
            font-size: 0.9rem;
            color: var(--text-main);
        }
        .badge {
            font-size: 0.7rem;
            padding: 0.25rem 0.5rem;
            border-radius: 99px;
            background: var(--primary-light);
            color: var(--primary);
            font-weight: 600;
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
            font-size: 1.05rem;
            transition: color 0.2s ease;
        }
        .input-wrapper:focus-within .input-icon {
            color: var(--primary);
        }
        input[type="number"],
        input[type="text"] {
            width: 100%;
            padding: 0.875rem 1rem 0.875rem 2.75rem;
            border-radius: var(--radius-md);
            border: 1px solid var(--border);
            background: var(--bg-surface);
            color: var(--text-main);
            font-family: inherit;
            font-size: 0.95rem;
            font-weight: 500;
            transition: all 0.2s ease;
            box-shadow: 0 1px 2px rgba(0,0,0,0.02);
        }
        input[type="number"]:focus,
        input[type="text"]:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 4px var(--primary-light);
            outline: none;
        }
        .input-hint {
            font-size: 0.8rem;
            color: var(--text-muted);
            margin-top: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.4rem;
        }

        /* Toggle Switch */
        .toggle-group {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 1.25rem 1.5rem;
            background: var(--bg-body);
            border-radius: var(--radius-md);
            border: 1px solid var(--border);
            margin-bottom: 1rem;
            transition: all 0.2s ease;
            cursor: pointer;
        }
        .toggle-group:hover {
            border-color: var(--primary);
            background: var(--bg-surface);
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }
        .toggle-group:hover .toggle-icon-wrap {
            color: var(--primary);
            transform: scale(1.05);
        }
        .toggle-group.active {
            border-color: var(--primary);
            background: var(--primary-light);
        }
        .toggle-info {
            display: flex;
            align-items: center;
            gap: 1.25rem;
            flex: 1;
        }
        .toggle-icon-wrap {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            background: var(--bg-surface);
            color: var(--text-muted);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            transition: all 0.2s ease;
            box-shadow: var(--shadow-sm);
        }
        .toggle-group.active .toggle-icon-wrap {
            background: var(--primary);
            color: white;
            box-shadow: none;
        }
        .toggle-text h4 {
            margin: 0 0 0.35rem;
            font-size: 1rem;
            font-weight: 600;
        }
        .toggle-text p {
            margin: 0;
            font-size: 0.85rem;
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
            content: ""; 
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
            background-color: var(--primary); 
        }
        input:checked + .slider:before { 
            transform: translateX(22px); 
        }

        /* Messages List */
        .messages-list {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }
        .message-item {
            background: var(--bg-body);
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            padding: 1.25rem 1.5rem;
            display: flex;
            align-items: flex-start;
            gap: 1.25rem;
            transition: all 0.2s ease;
        }
        .message-item:hover {
            border-color: var(--primary);
            background: var(--bg-surface);
            transform: translateX(4px);
            box-shadow: var(--shadow-sm);
        }
        .message-icon {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            background: var(--primary-light);
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            flex-shrink: 0;
        }
        .message-content {
            flex: 1;
        }
        .message-key {
            font-size: 0.75rem;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 700;
            margin-bottom: 0.4rem;
            display: inline-block;
            background: var(--bg-surface);
            padding: 0.2rem 0.5rem;
            border-radius: 4px;
            border: 1px solid var(--border);
        }
        .message-text {
            font-size: 0.95rem;
            color: var(--text-main);
            line-height: 1.6;
        }
        .message-edit {
            padding: 0.6rem 1.2rem;
            border-radius: var(--radius-md);
            border: 1px solid var(--border);
            background: var(--bg-surface);
            color: var(--text-muted);
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-weight: 600;
            font-size: 0.85rem;
        }
        .message-edit:hover {
            background: var(--primary-light);
            border-color: var(--primary);
            color: var(--primary);
        }

        /* Buttons & Status Sync */
        .btn-group {
            display: flex;
            gap: 1rem;
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--border);
            align-items: center;
        }
        .btn {
            flex: 1;
            padding: 0.875rem 1.5rem;
            border-radius: var(--radius-md);
            font-weight: 600;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.6rem;
            border: none;
            font-family: inherit;
        }
        .btn-primary {
            background: var(--primary);
            color: white;
            position: relative;
            overflow: hidden;
            box-shadow: 0 4px 6px -1px rgba(37, 99, 235, 0.1);
        }
        .btn-primary:hover:not(:disabled) {
            background: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: 0 10px 15px -3px rgba(37, 99, 235, 0.25);
        }
        .btn-primary:disabled {
            opacity: 0.7;
            cursor: not-allowed;
        }
        .btn-secondary {
            background: var(--bg-surface);
            color: var(--text-muted);
            border: 1px solid var(--border);
        }
        .btn-secondary:hover {
            background: var(--bg-body);
            color: var(--text-main);
            border-color: var(--text-muted);
        }

        /* Save Status Indicator */
        .save-status-indicator {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.9rem;
            color: var(--text-muted);
            margin-right: auto;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        .save-status-indicator i {
            font-size: 1.1rem;
            transition: color 0.3s ease;
        }
        .save-status-indicator.saving { color: var(--warning); }
        .save-status-indicator.saving i { color: var(--warning); }
        .save-status-indicator.saved { color: var(--success); }
        .save-status-indicator.saved i { color: var(--success); }
        .save-status-indicator.error { color: var(--danger); }
        .save-status-indicator.error i { color: var(--danger); }

        /* Modal */
        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.6);
            backdrop-filter: blur(4px);
            z-index: 1000;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        .modal-overlay.show {
            display: flex;
            opacity: 1;
        }
        .modal {
            background: var(--bg-surface);
            border-radius: var(--radius-lg);
            width: 100%;
            max-width: 600px;
            max-height: 90vh;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            transform: scale(0.95) translateY(10px);
            transition: transform 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            box-shadow: var(--shadow-lg);
        }
        .modal-overlay.show .modal {
            transform: scale(1) translateY(0);
        }
        .modal-header {
            padding: 1.5rem 2rem;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--bg-surface);
        }
        .modal-header h2 {
            margin: 0;
            font-size: 1.25rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            color: var(--text-main);
        }
        .modal-close {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            border: none;
            background: var(--bg-body);
            color: var(--text-muted);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
            font-size: 1.1rem;
        }
        .modal-close:hover {
            background: var(--danger-light);
            color: var(--danger);
        }
        .modal-body {
            padding: 2rem;
            overflow-y: auto;
        }
        .modal-footer {
            padding: 1.25rem 2rem;
            border-top: 1px solid var(--border);
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
            background: var(--bg-body);
        }

        .modal-textarea {
            width: 100%;
            padding: 1rem;
            border-radius: var(--radius-md);
            border: 1px solid var(--border);
            background: var(--bg-surface);
            color: var(--text-main);
            font-family: inherit;
            font-size: 0.95rem;
            resize: vertical;
            transition: all 0.2s ease;
            box-shadow: 0 1px 2px rgba(0,0,0,0.02);
            line-height: 1.5;
        }
        .modal-textarea:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 4px var(--primary-light);
            outline: none;
        }
        .modal-input-readonly {
            background: var(--bg-body) !important;
            color: var(--text-muted) !important;
            cursor: not-allowed;
            border-color: var(--border) !important;
            box-shadow: none !important;
            font-weight: 600;
        }

        /* Loading Animation */
        .btn-primary.loading .btn-text {
            opacity: 0;
        }
        .btn-spinner {
            position: absolute;
            width: 20px;
            height: 20px;
            border: 2px solid rgba(255,255,255,0.3);
            border-top-color: white;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
            opacity: 0;
            transition: opacity 0.2s;
        }
        .btn-primary.loading .btn-spinner {
            opacity: 1;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            .form-row {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            .form-row {
                grid-template-columns: 1fr;
            }
            .tabs {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }
            .message-item {
                flex-direction: column;
            }
            .message-edit {
                width: 100%;
                justify-content: center;
            }
            .btn-group {
                flex-direction: column;
            }
            .btn-group .btn {
                width: 100%;
            }
            .save-status-indicator {
                margin-right: 0;
                margin-bottom: 1rem;
                justify-content: center;
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
                    <i class="fas fa-money-bill-transfer"></i>
                </div>
                <div class="page-title-content">
                    <h1>Disbursement Settings</h1>
                    <p>Configure loan disbursement parameters and operational messages</p>
                </div>
            </div>
        </div>

        <!-- Tabs -->
        <div class="tabs">
            <div class="tab active" onclick="switchTab('settings')">
                <i class="fas fa-sliders-h"></i> General Settings
            </div>
            <div class="tab" onclick="switchTab('messages')">
                <i class="fas fa-comments"></i> System Messages
                <span class="badge" style="margin-left: 0.5rem; background: var(--primary); color: white; border-radius: 99px; padding: 0.25rem 0.6rem;"><?php echo count($messages); ?></span>
            </div>
        </div>

        <!-- Settings Tab -->
        <div id="settingsTab" class="tab-content active">
            <!-- Stats Overview -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-value"><?php echo formatCurrency($settings['min_disbursement'] ?? 0); ?></div>
                    <div class="stat-label">Min Disbursement</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?php echo formatCurrency($settings['max_disbursement'] ?? 0); ?></div>
                    <div class="stat-label">Max Disbursement</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?php echo $settings['default_num_installments'] ?? 0; ?>/<?php echo $settings['default_weekly_installments'] ?? 0; ?></div>
                    <div class="stat-label">Default Installments</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?php echo (($settings['savings_requirement_percentage'] ?? 0) * 100); ?>%</div>
                    <div class="stat-label">Savings Required</div>
                </div>
            </div>

            <form id="settingsForm">
                <div class="card">
                    <div class="card-header">
                        <div class="card-header-icon">
                            <i class="fas fa-money-bill-wave"></i>
                        </div>
                        <div>
                            <h2>Amount Limits</h2>
                            <p>Configure disbursement amount ranges</p>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Minimum Disbursement <span class="badge">Required</span></label>
                            <div class="input-wrapper">
                                <i class="fas fa-naira-sign input-icon"></i>
                                <input type="number" name="min_disbursement" id="minDisbursement" step="0.01" min="0"
                                       value="<?php echo htmlspecialchars($settings['min_disbursement'] ?? 10000); ?>" required>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Maximum Disbursement <span class="badge">Required</span></label>
                            <div class="input-wrapper">
                                <i class="fas fa-naira-sign input-icon"></i>
                                <input type="number" name="max_disbursement" id="maxDisbursement" step="0.01" min="0"
                                       value="<?php echo htmlspecialchars($settings['max_disbursement'] ?? 300000); ?>" required>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Global Max First Loan <span class="badge">Required</span></label>
                            <div class="input-wrapper">
                                <i class="fas fa-naira-sign input-icon"></i>
                                <input type="number" name="global_max_first_loan_amount" id="maxFirstLoan" step="0.01" min="0"
                                       value="<?php echo htmlspecialchars($settings['global_max_first_loan_amount'] ?? 40000); ?>" required>
                            </div>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Global Max Increment <span class="badge">Required</span></label>
                            <div class="input-wrapper">
                                <i class="fas fa-naira-sign input-icon"></i>
                                <input type="number" name="global_max_increment_amount" id="maxIncrement" step="0.01" min="0"
                                       value="<?php echo htmlspecialchars($settings['global_max_increment_amount'] ?? 20000); ?>" required>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Savings Requirement (%) <span class="badge" style="background: var(--bg-body); color: var(--text-muted)">0-1</span></label>
                            <div class="input-wrapper">
                                <i class="fas fa-percent input-icon"></i>
                                <input type="number" name="savings_requirement_percentage" id="savingsReq" step="0.01" min="0" max="1"
                                       value="<?php echo htmlspecialchars($settings['savings_requirement_percentage'] ?? 0.20); ?>" required>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <div class="card-header-icon" style="background: var(--info-light); color: var(--info);">
                            <i class="fas fa-calendar-alt"></i>
                        </div>
                        <div>
                            <h2>Installment & Waiting</h2>
                            <p>Configure repayment terms and waiting periods</p>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Default Daily Installments</label>
                            <div class="input-wrapper">
                                <i class="fas fa-list-ol input-icon"></i>
                                <input type="number" name="default_num_installments" id="dailyInstallments" min="1"
                                       value="<?php echo htmlspecialchars($settings['default_num_installments'] ?? 23); ?>" required>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Default Weekly Installments</label>
                            <div class="input-wrapper">
                                <i class="fas fa-list-ol input-icon"></i>
                                <input type="number" name="default_weekly_installments" id="weeklyInstallments" min="1"
                                       value="<?php echo htmlspecialchars($settings['default_weekly_installments'] ?? 24); ?>" required>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Min Days After Registration</label>
                            <div class="input-wrapper">
                                <i class="fas fa-user-clock input-icon"></i>
                                <input type="number" name="min_days_after_registration" id="minDaysReg" min="0"
                                       value="<?php echo htmlspecialchars($settings['min_days_after_registration'] ?? 2); ?>" required>
                            </div>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Wait Days (Full Term)</label>
                            <div class="input-wrapper">
                                <i class="fas fa-hourglass-start input-icon"></i>
                                <input type="number" name="min_waiting_days_full_term" id="waitFull" min="0"
                                       value="<?php echo htmlspecialchars($settings['min_waiting_days_full_term'] ?? 0); ?>">
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Wait Days (Early Payoff)</label>
                            <div class="input-wrapper">
                                <i class="fas fa-hourglass-end input-icon"></i>
                                <input type="number" name="min_waiting_days_early_payoff" id="waitEarly" min="0"
                                       value="<?php echo htmlspecialchars($settings['min_waiting_days_early_payoff'] ?? 0); ?>">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <div class="card-header-icon" style="background: var(--warning-light); color: var(--warning);">
                            <i class="fas fa-percentage"></i>
                        </div>
                        <div>
                            <h2>Interest Rates</h2>
                            <p>Default interest rates for different loan types</p>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Default Daily Rate <span class="badge" style="background: var(--bg-body); color: var(--text-muted)">Decimal</span></label>
                            <div class="input-wrapper">
                                <i class="fas fa-percent input-icon"></i>
                                <input type="number" name="default_interest_rate" id="dailyRate" step="0.01" min="0" max="1"
                                       value="<?php echo htmlspecialchars($settings['default_interest_rate'] ?? 0.15); ?>" required>
                            </div>
                            <div class="input-hint">
                                <i class="fas fa-info-circle"></i>
                                e.g., 0.15 = 15%
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Default Weekly Rate <span class="badge" style="background: var(--bg-body); color: var(--text-muted)">Decimal</span></label>
                            <div class="input-wrapper">
                                <i class="fas fa-percent input-icon"></i>
                                <input type="number" name="default_weekly_interest_rate" id="weeklyRate" step="0.01" min="0" max="1"
                                       value="<?php echo htmlspecialchars($settings['default_weekly_interest_rate'] ?? 0.20); ?>" required>
                            </div>
                            <div class="input-hint">
                                <i class="fas fa-info-circle"></i>
                                e.g., 0.20 = 20%
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Duplicate Check (Seconds)</label>
                            <div class="input-wrapper">
                                <i class="fas fa-shield-alt input-icon"></i>
                                <input type="number" name="duplicate_check_seconds" id="dupCheck" min="0"
                                       value="<?php echo htmlspecialchars($settings['duplicate_check_seconds'] ?? 300); ?>" required>
                            </div>
                            <div class="input-hint">
                                <i class="fas fa-info-circle"></i>
                                300 = 5 minutes
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <div class="card-header-icon" style="background: var(--success-light); color: var(--success);">
                            <i class="fas fa-cogs"></i>
                        </div>
                        <div>
                            <h2>Options</h2>
                            <p>Configure operational preferences</p>
                        </div>
                    </div>
                    <div class="toggle-group" onclick="document.getElementById('weekendToggle').click()">
                        <div class="toggle-info">
                            <div class="toggle-icon-wrap">
                                <i class="fas fa-calendar-check"></i>
                            </div>
                            <div class="toggle-text">
                                <h4>Allow Weekend Disbursements</h4>
                                <p>Process disbursements on Saturdays and Sundays</p>
                            </div>
                        </div>
                        <label class="switch" onclick="event.stopPropagation()">
                            <input type="checkbox" name="allow_weekends" id="weekendToggle"
                                   <?php echo ($settings['allow_weekends'] ?? 0) ? 'checked' : ''; ?>>
                            <span class="slider"></span>
                        </label>
                    </div>

                    <div class="toggle-group" onclick="document.getElementById('readonlyToggle').click()">
                        <div class="toggle-info">
                            <div class="toggle-icon-wrap">
                                <i class="fas fa-lock"></i>
                            </div>
                            <div class="toggle-text">
                                <h4>Disbursement Date Readonly</h4>
                                <p>Prevent users from changing the disbursement date</p>
                            </div>
                        </div>
                        <label class="switch" onclick="event.stopPropagation()">
                            <input type="checkbox" name="disbursement_date_readonly" id="readonlyToggle"
                                   <?php echo ($settings['disbursement_date_readonly'] ?? 1) ? 'checked' : ''; ?>>
                            <span class="slider"></span>
                        </label>
                    </div>
                </div>

                <div class="btn-group">
                    <div class="save-status-indicator saved" id="saveStatus">
                        <i class="fas fa-check-circle"></i>
                        <span id="saveStatusText">All changes saved</span>
                    </div>
                    <button type="submit" class="btn btn-primary" id="saveBtn">
                        <div class="btn-spinner"></div>
                        <span class="btn-text"><i class="fas fa-save"></i> Save Settings</span>
                    </button>
                    <a href="dashboard.php" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Cancel
                    </a>
                </div>
            </form>
        </div>

        <!-- Messages Tab -->
        <div id="messagesTab" class="tab-content">
            <div class="card">
                <div class="card-header">
                    <div class="card-header-icon" style="background: var(--info-light); color: var(--info);">
                        <i class="fas fa-comments"></i>
                    </div>
                    <div>
                        <h2>System Messages</h2>
                        <p>Customize messages shown to users during disbursement</p>
                    </div>
                </div>
                <div class="messages-list" id="messagesList">
                    <?php foreach ($messages as $message): ?>
                        <div class="message-item" data-id="<?php echo $message['id']; ?>">
                            <div class="message-icon">
                                <i class="fas fa-comment-dots"></i>
                            </div>
                            <div class="message-content">
                                <div class="message-key"><?php echo htmlspecialchars($message['message_key']); ?></div>
                                <div class="message-text"><?php echo htmlspecialchars($message['message_text']); ?></div>
                            </div>
                            <button class="message-edit" onclick="editMessage(<?php echo $message['id']; ?>)">
                                <i class="fas fa-edit"></i> Edit
                            </button>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </main>

    <!-- Message Edit Modal -->
    <div class="modal-overlay" id="messageModal">
        <div class="modal">
            <div class="modal-header">
                <h2>
                    <i class="fas fa-edit" style="color: var(--primary);"></i>
                    Edit Message
                </h2>
                <button class="modal-close" onclick="closeMessageModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form id="messageForm">
                <div class="modal-body">
                    <input type="hidden" name="id" id="messageId">
                    <div class="form-group">
                        <label class="form-label">Message Key</label>
                        <input type="text" id="messageKeyDisplay" class="modal-input-readonly" disabled>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Message Text <span style="color: var(--danger);">*</span></label>
                        <textarea name="message_text" id="messageText" class="modal-textarea" rows="4" required></textarea>
                        <div class="input-hint">
                            <i class="fas fa-info-circle"></i>
                            Use {placeholder} for dynamic values (e.g., {min_amount}, {max_size})
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeMessageModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="saveMessageBtn">
                        <div class="btn-spinner"></div>
                        <span class="btn-text"><i class="fas fa-save"></i> Save Message</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

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

        // Toast Notifications
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

        // Tab Switching
        function switchTab(tab) {
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
            
            if (tab === 'settings') {
                document.querySelector('.tab:first-child').classList.add('active');
                document.getElementById('settingsTab').classList.add('active');
            } else {
                document.querySelector('.tab:last-child').classList.add('active');
                document.getElementById('messagesTab').classList.add('active');
            }
        }

        // Auto-Save Logic
        const settingsForm = document.getElementById('settingsForm');
        const saveStatus = document.getElementById('saveStatus');
        const saveStatusText = document.getElementById('saveStatusText');
        const saveStatusIcon = saveStatus.querySelector('i');
        
        const originalValues = {};
        let autoSaveTimeout;

        settingsForm.querySelectorAll('input[type="number"], input[type="checkbox"]').forEach(input => {
            originalValues[input.name] = input.type === 'checkbox' ? input.checked : input.value;
        });

        function updateSaveStatus(state) {
            saveStatus.className = 'save-status-indicator'; 
            if (state === 'saving') {
                saveStatus.classList.add('saving');
                saveStatusIcon.className = 'fas fa-circle-notch fa-spin';
                saveStatusText.textContent = 'Saving changes...';
            } else if (state === 'saved') {
                saveStatus.classList.add('saved');
                saveStatusIcon.className = 'fas fa-check-circle';
                saveStatusText.textContent = 'All changes saved';
            } else if (state === 'unsaved') {
                saveStatus.classList.add('saving'); // Give it the warning color
                saveStatusIcon.className = 'fas fa-circle';
                saveStatusText.textContent = 'Unsaved changes...';
            } else if (state === 'error') {
                saveStatus.classList.add('error');
                saveStatusIcon.className = 'fas fa-exclamation-circle';
                saveStatusText.textContent = 'Error saving';
            }
        }

        async function triggerSave(silent = false) {
            updateSaveStatus('saving');
            const btn = document.getElementById('saveBtn');
            btn.classList.add('loading');
            btn.disabled = true;

            const formData = new FormData(settingsForm);
            formData.append('ajax', '1');
            formData.append('action', 'save_settings');

            try {
                const response = await fetch('', { method: 'POST', body: formData });
                const result = await response.json();
                
                if (result.success) {
                    if (!silent) showToast(result.message, 'success');
                    updateSaveStatus('saved');
                    // Update original values cache
                    settingsForm.querySelectorAll('input').forEach(input => {
                        originalValues[input.name] = input.type === 'checkbox' ? input.checked : input.value;
                    });
                } else {
                    updateSaveStatus('error');
                    showToast(result.message, 'error');
                }
            } catch (error) {
                updateSaveStatus('error');
                showToast('Network error. Please try again.', 'error');
            } finally {
                btn.classList.remove('loading');
                btn.disabled = false;
            }
        }

        function checkChanges() {
            let hasChanges = false;
            settingsForm.querySelectorAll('input[type="number"], input[type="checkbox"]').forEach(input => {
                const current = input.type === 'checkbox' ? input.checked : input.value;
                if (current != originalValues[input.name]) hasChanges = true;
            });
            
            if (hasChanges) {
                updateSaveStatus('unsaved');
                clearTimeout(autoSaveTimeout);
                // Trigger auto-save after user stops making changes for 1.2s
                autoSaveTimeout = setTimeout(() => {
                    triggerSave(true); // Silent save 
                }, 1200); 
            }
        }

        settingsForm.querySelectorAll('input').forEach(input => {
            input.addEventListener('change', checkChanges);
            input.addEventListener('input', checkChanges);
        });

        // Toggle group active states & propagation
        document.querySelectorAll('.toggle-group').forEach(group => {
            const checkbox = group.querySelector('input[type="checkbox"]');
            checkbox.addEventListener('change', () => {
                group.classList.toggle('active', checkbox.checked);
                checkChanges();
            });
            if (checkbox.checked) group.classList.add('active');
        });

        // Manual form submission
        settingsForm.addEventListener('submit', function(e) {
            e.preventDefault();
            clearTimeout(autoSaveTimeout); // Prevent double firing if clicking manually
            triggerSave(false); // Make it a visible save (with toast popup)
        });

        // Message Editing (Modal)
        function editMessage(id) {
            const modal = document.getElementById('messageModal');
            const item = document.querySelector(`.message-item[data-id="${id}"]`);
            
            document.getElementById('messageId').value = id;
            document.getElementById('messageKeyDisplay').value = item.querySelector('.message-key').textContent;
            document.getElementById('messageText').value = item.querySelector('.message-text').textContent;
            
            modal.classList.add('show');
        }

        function closeMessageModal() {
            document.getElementById('messageModal').classList.remove('show');
        }

        document.getElementById('messageForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const btn = document.getElementById('saveMessageBtn');
            btn.classList.add('loading');
            btn.disabled = true;
            
            const formData = new FormData(this);
            formData.append('ajax', '1');
            formData.append('action', 'update_message');
            
            try {
                const response = await fetch('', {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();
                
                if (result.success) {
                    showToast(result.message, 'success');
                    // Update the message in the list
                    const id = document.getElementById('messageId').value;
                    const newText = document.getElementById('messageText').value;
                    document.querySelector(`.message-item[data-id="${id}"] .message-text`).textContent = newText;
                    closeMessageModal();
                } else {
                    showToast(result.message, 'error');
                }
            } catch (error) {
                showToast('Network error. Please try again.', 'error');
            } finally {
                btn.classList.remove('loading');
                btn.disabled = false;
            }
        });

        // Close modals on overlay click
        document.querySelectorAll('.modal-overlay').forEach(overlay => {
            overlay.addEventListener('click', (e) => {
                if (e.target === overlay) {
                    if (overlay.id === 'messageModal') closeMessageModal();
                }
            });
        });
    </script>
</body>
</html>