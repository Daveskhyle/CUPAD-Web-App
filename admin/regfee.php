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

// --- 3. HANDLE AJAX REQUESTS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'create':
            case 'update':
                $id = $_POST['id'] ?? 'p_' . time() . rand(1000, 9999);
                $duration = intval($_POST['duration'] ?? 1);
                $unit = in_array($_POST['unit'] ?? '', ['Days', 'Weeks', 'Months']) ? $_POST['unit'] : 'Months';
                $rate = floatval($_POST['rate'] ?? 0);
                $min_amount = floatval($_POST['min_amount'] ?? 0);
                $max_amount = floatval($_POST['max_amount'] ?? 0);
                $increment_amount = floatval($_POST['increment_amount'] ?? 0);
                $max_first_loan_amount = floatval($_POST['max_first_loan_amount'] ?? 0);
                $disbursement_percentage = intval($_POST['disbursement_percentage'] ?? 100);
                $registration_amount = floatval($_POST['registration_amount'] ?? 0);
                $min_days_after_registration = intval($_POST['min_days_after_registration'] ?? 0);
                $min_waiting_days_full_term = intval($_POST['min_waiting_days_full_term'] ?? 0);
                $min_waiting_days_early_payoff = intval($_POST['min_waiting_days_early_payoff'] ?? 0);
                $savings_requirement_percentage = floatval($_POST['savings_requirement_percentage'] ?? 0);
                $disbursement_date_readonly = isset($_POST['disbursement_date_readonly']) ? 1 : 0;
                $allow_weekends = isset($_POST['allow_weekends']) ? 1 : 0;
                $grace_period_days = intval($_POST['grace_period_days'] ?? 0);

                // Validation
                if ($duration < 1) throw new Exception("Duration must be at least 1");
                if ($rate < 0 || $rate > 100) throw new Exception("Rate must be between 0 and 100");
                if ($min_amount < 0 || $max_amount < 0) throw new Exception("Amounts cannot be negative");
                if ($min_amount > $max_amount) throw new Exception("Minimum amount cannot exceed maximum");
                if ($increment_amount <= 0) throw new Exception("Increment amount must be positive");
                if ($max_first_loan_amount > $max_amount) throw new Exception("First loan max cannot exceed general max");
                if ($disbursement_percentage < 1 || $disbursement_percentage > 100) throw new Exception("Disbursement percentage must be 1-100");

                if ($action === 'create') {
                    $stmt = $conn->prepare("INSERT INTO loan_plans VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([
                        $id, $duration, $unit, $rate, $min_amount, $max_amount, $increment_amount,
                        $max_first_loan_amount, $disbursement_percentage, $registration_amount,
                        $min_days_after_registration, $min_waiting_days_full_term, $min_waiting_days_early_payoff,
                        $savings_requirement_percentage, $disbursement_date_readonly, $allow_weekends, $grace_period_days
                    ]);
                } else {
                    $stmt = $conn->prepare("UPDATE loan_plans SET 
                        duration=?, unit=?, rate=?, min_amount=?, max_amount=?, increment_amount=?,
                        max_first_loan_amount=?, disbursement_percentage=?, registration_amount=?,
                        min_days_after_registration=?, min_waiting_days_full_term=?, min_waiting_days_early_payoff=?,
                        savings_requirement_percentage=?, disbursement_date_readonly=?, allow_weekends=?, grace_period_days=?
                        WHERE id=?");
                    $stmt->execute([
                        $duration, $unit, $rate, $min_amount, $max_amount, $increment_amount,
                        $max_first_loan_amount, $disbursement_percentage, $registration_amount,
                        $min_days_after_registration, $min_waiting_days_full_term, $min_waiting_days_early_payoff,
                        $savings_requirement_percentage, $disbursement_date_readonly, $allow_weekends, $grace_period_days, $id
                    ]);
                }

                // Log action
                $log_stmt = $conn->prepare("INSERT INTO system_logs (user_id, action, details, created_at) VALUES (?, ?, ?, NOW())");
                $log_stmt->execute([$_SESSION['user_id'] ?? 0, $action . '_loan_plan', json_encode(['id' => $id])]);

                echo json_encode(['success' => true, 'message' => 'Loan plan ' . ($action === 'create' ? 'created' : 'updated') . ' successfully']);
                exit;

            case 'delete':
                $id = $_POST['id'] ?? '';
                if (empty($id)) throw new Exception("Plan ID required");
                
                // Check if plan is in use
                $check = $conn->prepare("SELECT COUNT(*) FROM loans WHERE plan_id = ?");
                $check->execute([$id]);
                if ($check->fetchColumn() > 0) {
                    throw new Exception("Cannot delete: Plan is assigned to existing loans");
                }

                $stmt = $conn->prepare("DELETE FROM loan_plans WHERE id = ?");
                $stmt->execute([$id]);

                $log_stmt = $conn->prepare("INSERT INTO system_logs (user_id, action, details, created_at) VALUES (?, 'delete_loan_plan', ?, NOW())");
                $log_stmt->execute([$_SESSION['user_id'] ?? 0, json_encode(['id' => $id])]);

                echo json_encode(['success' => true, 'message' => 'Loan plan deleted successfully']);
                exit;

            case 'get':
                $id = $_POST['id'] ?? '';
                $stmt = $conn->prepare("SELECT * FROM loan_plans WHERE id = ?");
                $stmt->execute([$id]);
                $plan = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$plan) throw new Exception("Plan not found");
                echo json_encode(['success' => true, 'data' => $plan]);
                exit;
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

// --- 4. FETCH LOAN PLANS ---
try {
    $stmt = $conn->query("SELECT * FROM loan_plans ORDER BY duration, unit, min_amount");
    $loan_plans = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $loan_plans = [];
    $error_message = "Error loading loan plans: " . $e->getMessage();
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

// Helper functions
function formatCurrency($amount) {
    return '₦' . number_format($amount, 2);
}

function formatDuration($duration, $unit) {
    return $duration . ' ' . $unit;
}

function getPlanColor($unit) {
    return match($unit) {
        'Days' => '#059669',
        'Weeks' => '#d97706',
        'Months' => '#2563eb',
        default => '#64748b'
    };
}
?>
<!DOCTYPE html>
<html lang="en" class="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Loan Plans - CUPAD</title>
    <link rel="icon" type="image/png" href="<?php echo htmlspecialchars($base_path . 'uploads/CUPAD LOGO.png'); ?>">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />
    <style>
        :root { 
            --primary: #2563eb; 
            --primary-dark: #1d4ed8; 
            --primary-light: #dbeafe;
            --success: #059669; 
            --success-light: #d1fae5;
            --warning: #d97706; 
            --warning-light: #ffedd5;
            --danger: #dc2626; 
            --danger-light: #fee2e2;
            --info: #0284c7; 
            --info-light: #e0f2fe;
            --purple: #7c3aed;
            --purple-light: #ede9fe;
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
            --primary: #3b82f6; 
            --primary-light: rgba(59, 130, 246, 0.2);
            --success: #34d399; 
            --success-light: rgba(16, 185, 129, 0.2);
            --warning: #fbbf24; 
            --warning-light: rgba(245, 158, 11, 0.2);
            --danger: #f87171; 
            --danger-light: rgba(244, 63, 94, 0.2);
            --info: #38bdf8; 
            --info-light: rgba(56, 189, 248, 0.2);
            --purple: #a78bfa;
            --purple-light: rgba(139, 92, 246, 0.2);
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
            max-width: 1400px; 
            margin: 0 auto; 
            padding: 2rem 1.5rem 4rem; 
        }

        /* Page Header */
        .page-header {
            margin-bottom: 2rem;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            flex-wrap: wrap;
            gap: 1rem;
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
            background: linear-gradient(135deg, var(--purple), #5b21b6);
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

        /* Action Button */
        .btn-primary {
            background: var(--purple);
            color: white;
            padding: 0.875rem 1.5rem;
            border-radius: var(--radius-md);
            font-weight: 600;
            font-size: 0.95rem;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            border: none;
            font-family: inherit;
            box-shadow: var(--shadow-sm);
        }
        .btn-primary:hover {
            background: #5b21b6;
            transform: translateY(-2px);
            box-shadow: 0 10px 20px -5px rgba(124, 58, 237, 0.3);
        }

        /* Stats Overview */
        .stats-bar {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1rem;
            margin-bottom: 2rem;
        }
        .stat-item {
            background: var(--bg-surface);
            padding: 1.25rem;
            border-radius: var(--radius-md);
            border: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            background: var(--purple-light);
            color: var(--purple);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
        }
        .stat-info h3 {
            margin: 0;
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--text-main);
        }
        .stat-info p {
            margin: 0.25rem 0 0;
            font-size: 0.85rem;
            color: var(--text-muted);
        }

        /* Plans Grid */
        .plans-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(380px, 1fr));
            gap: 1.5rem;
        }

        .plan-card {
            background: var(--bg-surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            overflow: hidden;
            transition: all 0.3s;
            position: relative;
        }
        .plan-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-lg);
            border-color: var(--purple);
        }

        .plan-header {
            padding: 1.5rem;
            background: linear-gradient(135deg, var(--bg-body), var(--bg-surface));
            border-bottom: 1px solid var(--border);
            position: relative;
        }
        .plan-duration {
            position: absolute;
            top: 1rem;
            right: 1rem;
            padding: 0.5rem 1rem;
            border-radius: 99px;
            font-weight: 700;
            font-size: 0.85rem;
            color: white;
        }
        .plan-duration.days { background: var(--success); }
        .plan-duration.weeks { background: var(--warning); }
        .plan-duration.months { background: var(--primary); }

        .plan-id {
            font-size: 0.75rem;
            color: var(--text-muted);
            font-family: monospace;
            margin-bottom: 0.5rem;
        }
        .plan-title {
            font-size: 1.25rem;
            font-weight: 700;
            margin: 0;
            color: var(--text-main);
        }
        .plan-rate {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            margin-top: 0.75rem;
            padding: 0.5rem 1rem;
            background: var(--purple-light);
            color: var(--purple);
            border-radius: 99px;
            font-weight: 600;
            font-size: 0.9rem;
        }

        .plan-body {
            padding: 1.5rem;
        }

        .amount-range {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1.5rem;
            padding: 1rem;
            background: var(--bg-body);
            border-radius: var(--radius-md);
        }
        .amount-item {
            text-align: center;
        }
        .amount-item.small {
            font-size: 0.85rem;
        }
        .amount-label {
            font-size: 0.75rem;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.25rem;
        }
        .amount-value {
            font-weight: 700;
            color: var(--text-main);
            font-size: 1.1rem;
        }
        .amount-arrow {
            color: var(--text-muted);
            font-size: 1.25rem;
        }

        .plan-details {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        .detail-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-size: 0.9rem;
        }
        .detail-icon {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            background: var(--bg-body);
            color: var(--text-muted);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.9rem;
        }
        .detail-text {
            display: flex;
            flex-direction: column;
        }
        .detail-label {
            font-size: 0.75rem;
            color: var(--text-muted);
        }
        .detail-value {
            font-weight: 600;
            color: var(--text-main);
        }

        .plan-badges {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
        }
        .badge {
            padding: 0.375rem 0.75rem;
            border-radius: 99px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
        }
        .badge-success {
            background: var(--success-light);
            color: var(--success);
        }
        .badge-warning {
            background: var(--warning-light);
            color: var(--warning);
        }
        .badge-info {
            background: var(--info-light);
            color: var(--info);
        }
        .badge-secondary {
            background: var(--bg-body);
            color: var(--text-muted);
        }

        .plan-actions {
            display: flex;
            gap: 0.75rem;
            padding-top: 1rem;
            border-top: 1px solid var(--border);
        }
        .btn-action {
            flex: 1;
            padding: 0.625rem;
            border-radius: var(--radius-md);
            font-weight: 600;
            font-size: 0.85rem;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            border: 1px solid var(--border);
            background: var(--bg-body);
            color: var(--text-muted);
        }
        .btn-action:hover {
            background: var(--border);
            color: var(--text-main);
        }
        .btn-action.edit:hover {
            background: var(--primary-light);
            border-color: var(--primary);
            color: var(--primary);
        }
        .btn-action.delete:hover {
            background: var(--danger-light);
            border-color: var(--danger);
            color: var(--danger);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            background: var(--bg-surface);
            border-radius: var(--radius-lg);
            border: 2px dashed var(--border);
        }
        .empty-icon {
            width: 80px;
            height: 80px;
            margin: 0 auto 1.5rem;
            border-radius: 50%;
            background: var(--bg-body);
            color: var(--text-muted);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
        }
        .empty-state h3 {
            margin: 0 0 0.5rem;
            font-size: 1.25rem;
            color: var(--text-main);
        }
        .empty-state p {
            margin: 0 0 1.5rem;
            color: var(--text-muted);
        }

        /* Modal */
        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.5);
            backdrop-filter: blur(4px);
            z-index: 1000;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            opacity: 0;
            transition: opacity 0.3s;
        }
        .modal-overlay.show {
            display: flex;
            opacity: 1;
        }
        .modal {
            background: var(--bg-surface);
            border-radius: var(--radius-lg);
            width: 100%;
            max-width: 800px;
            max-height: 90vh;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            transform: scale(0.9) translateY(20px);
            transition: transform 0.3s;
            box-shadow: var(--shadow-lg);
        }
        .modal-overlay.show .modal {
            transform: scale(1) translateY(0);
        }
        .modal-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .modal-header h2 {
            margin: 0;
            font-size: 1.25rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
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
            transition: all 0.2s;
        }
        .modal-close:hover {
            background: var(--danger-light);
            color: var(--danger);
        }
        .modal-body {
            padding: 1.5rem;
            overflow-y: auto;
            flex: 1;
        }
        .modal-footer {
            padding: 1rem 1.5rem;
            border-top: 1px solid var(--border);
            display: flex;
            justify-content: flex-end;
            gap: 0.75rem;
            background: var(--bg-body);
        }

        /* Form Styles in Modal */
        .form-section {
            margin-bottom: 2rem;
        }
        .form-section-title {
            font-size: 0.875rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-muted);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .form-row {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem;
            margin-bottom: 1rem;
        }
        .form-group {
            margin-bottom: 1rem;
        }
        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            font-size: 0.875rem;
            color: var(--text-main);
        }
        .form-label span {
            color: var(--danger);
            margin-left: 2px;
        }
        input[type="text"],
        input[type="number"],
        select {
            width: 100%;
            padding: 0.75rem 1rem;
            border-radius: var(--radius-md);
            border: 2px solid var(--border);
            background: var(--bg-body);
            color: var(--text-main);
            font-family: inherit;
            font-size: 0.9rem;
            transition: all 0.2s;
        }
        input:focus,
        select:focus {
            border-color: var(--purple);
            background: var(--bg-surface);
            box-shadow: 0 0 0 4px rgba(124, 58, 237, 0.1);
        }
        .input-group {
            position: relative;
        }
        .input-suffix {
            position: absolute;
            right: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            font-weight: 600;
            font-size: 0.9rem;
        }
        .input-group input {
            padding-right: 3rem;
        }

        .toggle-row {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
        }
        .toggle-item {
            flex: 1;
            min-width: 200px;
            padding: 1rem;
            background: var(--bg-body);
            border: 2px solid var(--border);
            border-radius: var(--radius-md);
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        .toggle-item:hover {
            border-color: var(--purple);
        }
        .toggle-item.active {
            border-color: var(--success);
            background: var(--success-light);
        }
        .toggle-item input {
            display: none;
        }
        .toggle-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background: var(--bg-surface);
            color: var(--text-muted);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            transition: all 0.2s;
        }
        .toggle-item.active .toggle-icon {
            background: var(--success);
            color: white;
        }
        .toggle-text h4 {
            margin: 0 0 0.25rem;
            font-size: 0.95rem;
        }
        .toggle-text p {
            margin: 0;
            font-size: 0.8rem;
            color: var(--text-muted);
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border-radius: var(--radius-md);
            font-weight: 600;
            font-size: 0.9rem;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            border: none;
            font-family: inherit;
        }
        .btn-secondary {
            background: var(--bg-body);
            color: var(--text-muted);
            border: 1px solid var(--border);
        }
        .btn-secondary:hover {
            background: var(--border);
            color: var(--text-main);
        }
        .btn-save {
            background: var(--purple);
            color: white;
            position: relative;
        }
        .btn-save:hover:not(:disabled) {
            background: #5b21b6;
        }
        .btn-save:disabled {
            opacity: 0.7;
            cursor: not-allowed;
        }
        .btn-spinner {
            width: 18px;
            height: 18px;
            border: 2px solid rgba(255,255,255,0.3);
            border-top-color: white;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
            display: none;
        }
        .btn-save.loading .btn-spinner {
            display: block;
        }
        .btn-save.loading .btn-text {
            display: none;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .stats-bar {
                grid-template-columns: repeat(2, 1fr);
            }
            .form-row {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        @media (max-width: 768px) {
            .plans-grid {
                grid-template-columns: 1fr;
            }
            .stats-bar {
                grid-template-columns: 1fr;
            }
            .form-row {
                grid-template-columns: 1fr;
            }
            .page-header {
                flex-direction: column;
            }
            .modal {
                max-height: 95vh;
            }
        }

        /* Delete Confirmation */
        .confirm-dialog {
            text-align: center;
            padding: 2rem;
        }
        .confirm-icon {
            width: 80px;
            height: 80px;
            margin: 0 auto 1.5rem;
            border-radius: 50%;
            background: var(--danger-light);
            color: var(--danger);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.5rem;
        }
        .confirm-dialog h3 {
            margin: 0 0 0.5rem;
            font-size: 1.25rem;
        }
        .confirm-dialog p {
            margin: 0 0 1.5rem;
            color: var(--text-muted);
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
            <div>
                <a href="dashboard.php" class="back-link">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
                <div class="page-title-wrap">
                    <div class="page-icon">
                        <i class="fas fa-clipboard-list"></i>
                    </div>
                    <div class="page-title-content">
                        <h1>Loan Plans</h1>
                        <p>Manage loan products and their terms</p>
                    </div>
                </div>
            </div>
            <button class="btn-primary" onclick="openModal()">
                <i class="fas fa-plus"></i> Create Plan
            </button>
        </div>

        <!-- Stats Bar -->
        <div class="stats-bar">
            <div class="stat-item">
                <div class="stat-icon">
                    <i class="fas fa-layer-group"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo count($loan_plans); ?></h3>
                    <p>Total Plans</p>
                </div>
            </div>
            <div class="stat-item">
                <div class="stat-icon" style="background: var(--success-light); color: var(--success);">
                    <i class="fas fa-calendar-day"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo count(array_filter($loan_plans, fn($p) => $p['unit'] === 'Days')); ?></h3>
                    <p>Short Term</p>
                </div>
            </div>
            <div class="stat-item">
                <div class="stat-icon" style="background: var(--warning-light); color: var(--warning);">
                    <i class="fas fa-calendar-week"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo count(array_filter($loan_plans, fn($p) => $p['unit'] === 'Weeks')); ?></h3>
                    <p>Medium Term</p>
                </div>
            </div>
            <div class="stat-item">
                <div class="stat-icon" style="background: var(--primary-light); color: var(--primary);">
                    <i class="fas fa-calendar-alt"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo count(array_filter($loan_plans, fn($p) => $p['unit'] === 'Months')); ?></h3>
                    <p>Long Term</p>
                </div>
            </div>
        </div>

        <!-- Plans Grid -->
        <?php if (empty($loan_plans)): ?>
            <div class="empty-state">
                <div class="empty-icon">
                    <i class="fas fa-clipboard-list"></i>
                </div>
                <h3>No Loan Plans Yet</h3>
                <p>Create your first loan plan to get started</p>
                <button class="btn-primary" onclick="openModal()">
                    <i class="fas fa-plus"></i> Create First Plan
                </button>
            </div>
        <?php else: ?>
            <div class="plans-grid" id="plansGrid">
                <?php foreach ($loan_plans as $plan): 
                    $color = getPlanColor($plan['unit']);
                ?>
                    <div class="plan-card" data-id="<?php echo htmlspecialchars($plan['id']); ?>">
                        <div class="plan-header">
                            <div class="plan-duration <?php echo strtolower($plan['unit']); ?>">
                                <?php echo formatDuration($plan['duration'], $plan['unit']); ?>
                            </div>
                            <div class="plan-id"><?php echo htmlspecialchars($plan['id']); ?></div>
                            <h3 class="plan-title">
                                <?php echo formatCurrency($plan['min_amount']); ?> - <?php echo formatCurrency($plan['max_amount']); ?>
                            </h3>
                            <div class="plan-rate">
                                <i class="fas fa-percentage"></i>
                                <?php echo $plan['rate']; ?>% Interest
                            </div>
                        </div>
                        
                        <div class="plan-body">
                            <div class="amount-range">
                                <div class="amount-item small">
                                    <div class="amount-label">First Loan Max</div>
                                    <div class="amount-value"><?php echo formatCurrency($plan['max_first_loan_amount']); ?></div>
                                </div>
                                <div class="amount-arrow">
                                    <i class="fas fa-arrow-right"></i>
                                </div>
                                <div class="amount-item">
                                    <div class="amount-label">Increment</div>
                                    <div class="amount-value"><?php echo formatCurrency($plan['increment_amount']); ?></div>
                                </div>
                            </div>

                            <div class="plan-details">
                                <div class="detail-item">
                                    <div class="detail-icon">
                                        <i class="fas fa-money-check-alt"></i>
                                    </div>
                                    <div class="detail-text">
                                        <span class="detail-label">Disbursement</span>
                                        <span class="detail-value"><?php echo $plan['disbursement_percentage']; ?>%</span>
                                    </div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-icon">
                                        <i class="fas fa-user-plus"></i>
                                    </div>
                                    <div class="detail-text">
                                        <span class="detail-label">Registration</span>
                                        <span class="detail-value"><?php echo formatCurrency($plan['registration_amount']); ?></span>
                                    </div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-icon">
                                        <i class="fas fa-piggy-bank"></i>
                                    </div>
                                    <div class="detail-text">
                                        <span class="detail-label">Savings Req</span>
                                        <span class="detail-value"><?php echo ($plan['savings_requirement_percentage'] * 100); ?>%</span>
                                    </div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-icon">
                                        <i class="fas fa-clock"></i>
                                    </div>
                                    <div class="detail-text">
                                        <span class="detail-label">Min Wait</span>
                                        <span class="detail-value"><?php echo $plan['min_days_after_registration']; ?> days</span>
                                    </div>
                                </div>
                            </div>

                            <div class="plan-badges">
                                <?php if ($plan['allow_weekends']): ?>
                                    <span class="badge badge-success"><i class="fas fa-calendar-check"></i> Weekends</span>
                                <?php endif; ?>
                                <?php if ($plan['disbursement_date_readonly']): ?>
                                    <span class="badge badge-info"><i class="fas fa-lock"></i> Date Locked</span>
                                <?php endif; ?>
                                <?php if ($plan['grace_period_days'] > 0): ?>
                                    <span class="badge badge-warning"><i class="fas fa-hand-holding-heart"></i> <?php echo $plan['grace_period_days']; ?>d Grace</span>
                                <?php endif; ?>
                            </div>

                            <div class="plan-actions">
                                <button class="btn-action edit" onclick="editPlan('<?php echo htmlspecialchars($plan['id']); ?>')">
                                    <i class="fas fa-edit"></i> Edit
                                </button>
                                <button class="btn-action delete" onclick="confirmDelete('<?php echo htmlspecialchars($plan['id']); ?>')">
                                    <i class="fas fa-trash-alt"></i> Delete
                                </button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </main>

    <!-- Plan Modal -->
    <div class="modal-overlay" id="planModal">
        <div class="modal">
            <div class="modal-header">
                <h2>
                    <i class="fas fa-clipboard-list" style="color: var(--purple);"></i>
                    <span id="modalTitle">Create Loan Plan</span>
                </h2>
                <button class="modal-close" onclick="closeModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form id="planForm">
                <div class="modal-body">
                    <input type="hidden" name="id" id="planId">
                    <input type="hidden" name="action" id="formAction" value="create">
                    
                    <div class="form-section">
                        <div class="form-section-title">
                            <i class="fas fa-clock"></i> Duration & Interest
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Duration <span>*</span></label>
                                <input type="number" name="duration" id="duration" min="1" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Unit <span>*</span></label>
                                <select name="unit" id="unit" required>
                                    <option value="Days">Days</option>
                                    <option value="Weeks">Weeks</option>
                                    <option value="Months">Months</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Interest Rate (%) <span>*</span></label>
                                <div class="input-group">
                                    <input type="number" name="rate" id="rate" step="0.01" min="0" max="100" required>
                                    <span class="input-suffix">%</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <div class="form-section-title">
                            <i class="fas fa-money-bill-wave"></i> Amount Settings
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Min Amount <span>*</span></label>
                                <input type="number" name="min_amount" id="minAmount" step="0.01" min="0" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Max Amount <span>*</span></label>
                                <input type="number" name="max_amount" id="maxAmount" step="0.01" min="0" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Increment Amount <span>*</span></label>
                                <input type="number" name="increment_amount" id="incrementAmount" step="0.01" min="0" required>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Max First Loan <span>*</span></label>
                                <input type="number" name="max_first_loan_amount" id="maxFirstLoan" step="0.01" min="0" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Disbursement % <span>*</span></label>
                                <div class="input-group">
                                    <input type="number" name="disbursement_percentage" id="disbursementPct" min="1" max="100" required>
                                    <span class="input-suffix">%</span>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Registration Fee <span>*</span></label>
                                <input type="number" name="registration_amount" id="registrationAmount" step="0.01" min="0" required>
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <div class="form-section-title">
                            <i class="fas fa-shield-alt"></i> Requirements & Waiting
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Min Days After Reg <span>*</span></label>
                                <input type="number" name="min_days_after_registration" id="minDaysReg" min="0" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Wait Days (Full Term)</label>
                                <input type="number" name="min_waiting_days_full_term" id="waitFullTerm" min="0" value="0">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Wait Days (Early Payoff)</label>
                                <input type="number" name="min_waiting_days_early_payoff" id="waitEarly" min="0" value="0">
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Savings Requirement (%)</label>
                                <div class="input-group">
                                    <input type="number" name="savings_requirement_percentage" id="savingsReq" step="0.01" min="0" max="1" value="0.20">
                                    <span class="input-suffix">%</span>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Grace Period (Days)</label>
                                <input type="number" name="grace_period_days" id="gracePeriod" min="0" max="30" value="0">
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <div class="form-section-title">
                            <i class="fas fa-toggle-on"></i> Options
                        </div>
                        <div class="toggle-row">
                            <label class="toggle-item" onclick="this.classList.toggle('active'); document.getElementById('allowWeekends').click()">
                                <input type="checkbox" name="allow_weekends" id="allowWeekends">
                                <div class="toggle-icon">
                                    <i class="fas fa-calendar-check"></i>
                                </div>
                                <div class="toggle-text">
                                    <h4>Allow Weekends</h4>
                                    <p>Process loans on weekends</p>
                                </div>
                            </label>
                            <label class="toggle-item" onclick="this.classList.toggle('active'); document.getElementById('dateReadonly').click()">
                                <input type="checkbox" name="disbursement_date_readonly" id="dateReadonly" checked>
                                <div class="toggle-icon">
                                    <i class="fas fa-lock"></i>
                                </div>
                                <div class="toggle-text">
                                    <h4>Lock Disbursement Date</h4>
                                    <p>Prevent date changes</p>
                                </div>
                            </label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                    <button type="submit" class="btn btn-save" id="saveBtn">
                        <div class="btn-spinner"></div>
                        <span class="btn-text"><i class="fas fa-save"></i> Save Plan</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal-overlay" id="deleteModal">
        <div class="modal" style="max-width: 400px;">
            <div class="confirm-dialog">
                <div class="confirm-icon">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <h3>Delete Loan Plan?</h3>
                <p>This action cannot be undone. Make sure no active loans use this plan.</p>
                <div style="display: flex; gap: 0.75rem; justify-content: center;">
                    <button class="btn btn-secondary" onclick="closeDeleteModal()">Cancel</button>
                    <button class="btn btn-save" style="background: var(--danger);" onclick="executeDelete()">
                        <i class="fas fa-trash-alt"></i> Delete
                    </button>
                </div>
            </div>
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

        // Modal Functions
        function openModal(planId = null) {
            const modal = document.getElementById('planModal');
            const form = document.getElementById('planForm');
            const title = document.getElementById('modalTitle');
            
            form.reset();
            document.querySelectorAll('.toggle-item').forEach(t => t.classList.remove('active'));
            
            if (planId) {
                title.textContent = 'Edit Loan Plan';
                document.getElementById('formAction').value = 'update';
                document.getElementById('planId').value = planId;
                loadPlanData(planId);
            } else {
                title.textContent = 'Create Loan Plan';
                document.getElementById('formAction').value = 'create';
                document.getElementById('planId').value = 'p_' + Date.now() + Math.floor(Math.random() * 1000);
                // Set defaults
                document.getElementById('dateReadonly').checked = true;
                document.querySelector('.toggle-item:last-child').classList.add('active');
            }
            
            modal.classList.add('show');
        }

        function closeModal() {
            document.getElementById('planModal').classList.remove('show');
        }

        async function loadPlanData(planId) {
            try {
                const response = await fetch('', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `ajax=1&action=get&id=${planId}`
                });
                const result = await response.json();
                
                if (result.success) {
                    const data = result.data;
                    document.getElementById('duration').value = data.duration;
                    document.getElementById('unit').value = data.unit;
                    document.getElementById('rate').value = data.rate;
                    document.getElementById('minAmount').value = data.min_amount;
                    document.getElementById('maxAmount').value = data.max_amount;
                    document.getElementById('incrementAmount').value = data.increment_amount;
                    document.getElementById('maxFirstLoan').value = data.max_first_loan_amount;
                    document.getElementById('disbursementPct').value = data.disbursement_percentage;
                    document.getElementById('registrationAmount').value = data.registration_amount;
                    document.getElementById('minDaysReg').value = data.min_days_after_registration;
                    document.getElementById('waitFullTerm').value = data.min_waiting_days_full_term;
                    document.getElementById('waitEarly').value = data.min_waiting_days_early_payoff;
                    document.getElementById('savingsReq').value = data.savings_requirement_percentage;
                    document.getElementById('gracePeriod').value = data.grace_period_days;
                    
                    document.getElementById('allowWeekends').checked = data.allow_weekends == 1;
                    document.getElementById('dateReadonly').checked = data.disbursement_date_readonly == 1;
                    
                    // Update toggle visuals
                    const toggles = document.querySelectorAll('.toggle-item');
                    if (data.allow_weekends == 1) toggles[0].classList.add('active');
                    if (data.disbursement_date_readonly == 1) toggles[1].classList.add('active');
                }
            } catch (error) {
                showToast('Error loading plan data', 'error');
            }
        }

        // Form Submission
        document.getElementById('planForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const btn = document.getElementById('saveBtn');
            btn.classList.add('loading');
            btn.disabled = true;
            
            const formData = new FormData(this);
            formData.append('ajax', '1');
            
            try {
                const response = await fetch('', {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();
                
                if (result.success) {
                    showToast(result.message, 'success');
                    closeModal();
                    setTimeout(() => location.reload(), 500);
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

        // Edit Plan
        function editPlan(planId) {
            openModal(planId);
        }

        // Delete Functions
        let deletePlanId = null;

        function confirmDelete(planId) {
            deletePlanId = planId;
            document.getElementById('deleteModal').classList.add('show');
        }

        function closeDeleteModal() {
            document.getElementById('deleteModal').classList.remove('show');
            deletePlanId = null;
        }

        async function executeDelete() {
            if (!deletePlanId) return;
            
            try {
                const response = await fetch('', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `ajax=1&action=delete&id=${deletePlanId}`
                });
                const result = await response.json();
                
                if (result.success) {
                    showToast(result.message, 'success');
                    document.querySelector(`[data-id="${deletePlanId}"]`).remove();
                    closeDeleteModal();
                } else {
                    showToast(result.message, 'error');
                }
            } catch (error) {
                showToast('Network error. Please try again.', 'error');
            }
        }

        // Close modals on overlay click
        document.querySelectorAll('.modal-overlay').forEach(overlay => {
            overlay.addEventListener('click', (e) => {
                if (e.target === overlay && overlay.id === 'planModal') closeModal();
                if (e.target === overlay && overlay.id === 'deleteModal') closeDeleteModal();
            });
        });
    </script>
</body>
</html>