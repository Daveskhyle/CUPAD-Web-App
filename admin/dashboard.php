<?php
// --- 1. CONFIGURATION ---
date_default_timezone_set('Africa/Lagos');
ini_set('date.timezone', 'Africa/Lagos');
session_start();
require_once '../includes/config.php';
$conn = getDbConnection();

// --- 3. AUTH CHECK ---
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: ../index.php');
    exit();
}

$base_path = '../';
$current_year = date('Y');

// --- 4. HELPER FUNCTION FOR DASHBOARD DATA ---
function get_dashboard_calculations($conn, $base_path) {
    $stats = [
        'total_users' => 0, 'total_clients' => 0, 'total_branches' => 0,
        'active_sessions' => 0, 'system_health' => 98.5, 'pending_registrations' => 0,
        'daily_transactions' => 0, 'revenue_today' => 0, 'security_alerts' => 0,
        'total_savings' => 0, 'total_collections' => 0, 'registration_revenue' => 0,
        'active_loans' => 0, 'pending_approvals' => 0, 'monthly_collections' => 0
    ];
    
    $monthly_revenue = array_fill(0, 12, 0); // Jan-Dec
    
    try {
        // 1. Users & Active Sessions
        $today = date('Y-m-d');
        $five_mins_ago = date('Y-m-d H:i:s', strtotime('-5 minutes'));
        $stmt = $conn->prepare("SELECT 
            COUNT(*) as total, 
            SUM(CASE WHEN last_login >= ? THEN 1 ELSE 0 END) as active 
            FROM users");
        $stmt->execute([$five_mins_ago]);
        $user_stats = $stmt->fetch();
        $stats['total_users'] = $user_stats['total'] ?? 0;
        $stats['active_sessions'] = $user_stats['active'] ?? 0;

        // 2. Clients
        $stats['total_clients'] = $conn->query("SELECT COUNT(*) FROM clients")->fetchColumn();

        // 3. Branches
        $stats['total_branches'] = $conn->query("SELECT COUNT(*) FROM branches WHERE status = 'active'")->fetchColumn();

        // 4. Financials (Today & Monthly Chart)
        $current_year_val = date('Y');

        // A. Loan Collections
        $stmt = $conn->prepare("
            SELECT MONTH(date) as m,
                   SUM(amount_collected) as total,
                   SUM(CASE WHEN DATE(date) = ? THEN amount_collected ELSE 0 END) as today_total,
                   SUM(CASE WHEN DATE(date) = ? THEN 1 ELSE 0 END) as today_count
            FROM loan_collections
            WHERE YEAR(date) = ?
            GROUP BY MONTH(date)
        ");
        $stmt->execute([$today, $today, $current_year_val]);
        $revenue_today = 0;
        $daily_tx_count = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $monthly_revenue[(int)$row['m'] - 1] += (float)$row['total'];
            $revenue_today  += (float)$row['today_total'];
            $daily_tx_count += (int)$row['today_count'];
        }

        // B. Savings Deposits
        $stmt = $conn->prepare("
            SELECT MONTH(date) as m,
                   SUM(amount) as total,
                   SUM(CASE WHEN DATE(date) = ? THEN amount ELSE 0 END) as today_total,
                   SUM(CASE WHEN DATE(date) = ? THEN 1 ELSE 0 END) as today_count
            FROM saving_collections
            WHERE YEAR(date) = ? AND type = 'deposit'
            GROUP BY MONTH(date)
        ");
        $stmt->execute([$today, $today, $current_year_val]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $monthly_revenue[(int)$row['m'] - 1] += (float)$row['total'];
            $revenue_today  += (float)$row['today_total'];
            $daily_tx_count += (int)$row['today_count'];
        }

        $stats['revenue_today'] = $revenue_today;
        $stats['daily_transactions'] = $daily_tx_count;

        // 5. Registrations (Current Month)
        $current_month = date('Y-m');
        $stmt = $conn->prepare("SELECT COUNT(*) FROM registrations WHERE date LIKE ?");
        $stmt->execute(["{$current_month}%"]);
        $stats['pending_registrations'] = (int)$stmt->fetchColumn();

        // 6. Active Loans
        $stats['active_loans'] = $conn->query("SELECT COUNT(*) FROM disbursements WHERE status = 'active' AND remaining_balance > 0")->fetchColumn();

        // 7. Monthly Collections
        $stmt = $conn->prepare("SELECT SUM(amount_collected) FROM loan_collections WHERE date LIKE ?");
        $stmt->execute(["{$current_month}%"]);
        $stats['monthly_collections'] = (float)$stmt->fetchColumn();

        // 8. Total Savings
        $stmt = $conn->query("SELECT COALESCE(SUM(balance), 0) FROM saving_balances");
        $stats['total_savings'] = (float)$stmt->fetchColumn();

    } catch (PDOException $e) {
        error_log("Dashboard Stats Error: " . $e->getMessage());
    }

    // 9. Maintenance Status
    $m_flag = $base_path . 'maintenance.flag';
    $m_active = file_exists($m_flag);

    return [
        'stats' => $stats,
        'chart' => $monthly_revenue,
        'maintenance' => $m_active
    ];
}

// --- 5. AJAX ENDPOINT FOR INFINITE SCROLL ACTIVITIES ---
if (isset($_GET['fetch_activities'])) {
    ini_set('display_errors', '0');
    error_reporting(E_ALL);
    ob_start();
    header('Content-Type: application/json');
    
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $limit = isset($_GET['limit']) ? max(1, (int)$_GET['limit']) : 10;
    $offset = ($page - 1) * $limit;
    
    $activities = [];

    // Helper functions guarded against redeclaration
    if (!function_exists('timeAgo')) {
        function timeAgo($timestamp) {
            $ts = strtotime($timestamp);
            if (!$ts) return 'Unknown';
            $diff = time() - $ts;
            if ($diff < 60) return 'Just now';
            if ($diff < 3600) return floor($diff / 60) . 'm ago';
            if ($diff < 86400) return floor($diff / 3600) . 'h ago';
            return date('M j, g:i A', $ts);
        }
    }

    if (!function_exists('formatCurrency')) {
        function formatCurrency($amount) {
            return '₦' . number_format(abs((float)$amount), 2);
        }
    }

    if (!function_exists('getActivityIcon')) {
        function getActivityIcon($type, $subtype) {
            $iconMap = [
                'disbursement' => 'fa-money-bill-wave',
                'repayment' => 'fa-hand-holding-usd',
                'epayment' => 'fa-hand-holding-usd',
                'auto_repayment' => 'fa-sync-alt',
                'savings_deposit' => 'fa-piggy-bank',
                'savings_withdrawal' => 'fa-wallet',
                'savings_withdrawal_cash' => 'fa-wallet',
                'savings_return' => 'fa-undo',
                'savings_transfer' => 'fa-exchange-alt',
                'savings_interest' => 'fa-percentage',
                'savings_fee' => 'fa-file-invoice-dollar',
                'new_client' => 'fa-user-plus',
                'new_user' => 'fa-user-cog',
                'penalty' => 'fa-exclamation-circle',
                'adjustment' => 'fa-sliders-h'
            ];
            $key = $type === 'saving_collections' ? 'savings_' . $subtype : ($subtype ?: $type);
            return $iconMap[$key] ?? 'fa-circle';
        }
    }

    if (!function_exists('getActivityColor')) {
        function getActivityColor($type, $subtype) {
            $colorMap = [
                'disbursement' => 'warning',
                'repayment' => 'success',
                'epayment' => 'success',
                'auto_repayment' => 'info',
                'savings_deposit' => 'success',
                'savings_withdrawal' => 'warning',
                'savings_withdrawal_cash' => 'warning',
                'savings_return' => 'danger',
                'savings_transfer' => 'info',
                'savings_interest' => 'success',
                'savings_fee' => 'danger',
                'new_client' => 'success',
                'new_user' => 'info',
                'penalty' => 'danger',
                'adjustment' => 'muted'
            ];
            $key = $type === 'saving_collections' ? 'savings_' . $subtype : ($subtype ?: $type);
            return $colorMap[$key] ?? 'muted';
        }
    }

    try {
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $sql = "
        SELECT 
            t.id as primary_key,
            t.transaction_id,
            t.client_id,
            c.name as client_name,
            t.branch_id,
            COALESCE(t.branch_id, c.branch_id) as actual_branch_id,
            COALESCE(b.name, c.branch_id, 'N/A') as branch_name,
            t.date as timestamp,
            CASE 
                WHEN t.source_table = 'disbursements' THEN 'disbursement'
                WHEN t.source_table = 'loan_collections' THEN t.subtype
                WHEN t.source_table = 'saving_collections' THEN CONCAT('savings_', t.subtype)
            END as activity_type,
            t.amount,
            t.total_payable,
            t.remaining_balance,
            t.interest_rate,
            t.num_installments,
            t.installment_amount,
            t.source_table,
            t.subtype as transaction_subtype
        FROM (
            SELECT 
                id, id as transaction_id, client_id, branch_id, date, 'disbursements' as source_table, 
                NULL as subtype, principal as amount, total_payable, remaining_balance, interest_rate, 
                num_installments, (total_payable / NULLIF(num_installments,0)) as installment_amount
            FROM disbursements
            
            UNION ALL
            
            SELECT 
                id, transaction_id, client_id, NULL as branch_id, date, 'loan_collections' as source_table, 
                type as subtype, amount_collected as amount, NULL, remaining_balance, NULL, NULL, NULL
            FROM loan_collections
            
            UNION ALL
            
            SELECT 
                id, transaction_id, client_id, NULL as branch_id, date, 'saving_collections' as source_table, 
                type as subtype, amount, NULL, balance_after, NULL, NULL, NULL
            FROM saving_collections
            
            UNION ALL
            
            SELECT 
                id, CONCAT('CLI-', id) as transaction_id, id as client_id, branch_id, created_at as date, 
                'clients' as source_table, 'new_client' as subtype, 0 as amount, NULL, NULL, NULL, NULL, NULL
            FROM clients
            
            UNION ALL
            
            SELECT 
                u.id, CONCAT('USR-', u.id) as transaction_id, NULL as client_id, u.branch_id, u.created_at as date, 
                'users' as source_table, 'new_user' as subtype, 0 as amount, NULL, NULL, NULL, NULL, NULL
            FROM users u
            WHERE u.created_at IS NOT NULL
        ) t
        LEFT JOIN clients c ON t.client_id = c.id OR (t.source_table = 'clients' AND t.client_id = c.id)
        LEFT JOIN branches b ON COALESCE(t.branch_id, c.branch_id) = b.id
        ORDER BY t.date DESC 
        LIMIT :limit OFFSET :offset
        ";

        $stmt = $conn->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $raw_activities = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($raw_activities as $row) {
            $type = $row['activity_type'] ?? '';
            $amount = floatval($row['amount'] ?? 0);
            $isDisbursement = strpos($type, 'disbursement') !== false;
            $isRepayment = strpos($type, 'repayment') !== false || strpos($type, 'collection') !== false;
            $isSavings = strpos($type, 'savings') !== false;
            $isNewClient = strpos($type, 'new_client') !== false;
            $isNewUser = strpos($type, 'new_user') !== false;
            
            $description = '';
            $title = '';
            
            if ($isDisbursement) {
                $title = 'Loan Disbursement';
                $desc = "Principal: " . formatCurrency($amount);
                if (!empty($row['total_payable'])) $desc .= " | Payable: " . formatCurrency($row['total_payable']);
                if (!empty($row['interest_rate'])) $desc .= " @ " . round($row['interest_rate']) . "%";
                if (!empty($row['num_installments'])) $desc .= " (" . $row['num_installments'] . " wks)";
                $description = $desc;
            } elseif ($isRepayment) {
                $title = 'Loan Repayment';
                $desc = "Amount: " . formatCurrency($amount);
                if ($row['remaining_balance'] !== null) $desc .= " | Bal: " . formatCurrency($row['remaining_balance']);
                $description = $desc;
            } elseif ($isSavings) {
                $action = str_replace('savings_', '', $type);
                $title = 'Savings ' . ucfirst($action);
                $desc = "Amount: " . formatCurrency($amount);
                if ($row['remaining_balance'] !== null) $desc .= " | Bal: " . formatCurrency($row['remaining_balance']);
                $description = $desc;
            } elseif ($isNewClient) {
                $title = 'New Client Enrolled';
                $description = "Registered client record";
            } elseif ($isNewUser) {
                $title = 'Staff Account Created';
                $description = "New administrative access granted";
            } else {
                $title = str_replace('_', ' ', ucfirst((string)$type));
                $description = formatCurrency($amount);
            }

            $meta = [];
            if (!empty($row['client_name']) && !$isNewClient) $meta[] = htmlspecialchars($row['client_name']);
            if (!empty($row['branch_name']) && $row['branch_name'] !== 'N/A') $meta[] = htmlspecialchars($row['branch_name']);
            if (!empty($meta)) $description .= " — " . implode(' • ', $meta);

            $activities[] = [
                'title' => $title,
                'description' => $description,
                'icon' => 'fas ' . getActivityIcon($row['source_table'] ?? '', $row['transaction_subtype'] ?? ''),
                'type' => getActivityColor($row['source_table'] ?? '', $row['transaction_subtype'] ?? ''),
                'timestamp' => $row['timestamp'] ?? '',
                'time_ago' => timeAgo($row['timestamp'] ?? ''),
                'raw_data' => $row
            ];
        }

        $has_more = count($activities) >= $limit;

        ob_clean();
        echo json_encode([
            'activities' => $activities, 
            'page' => $page,
            'has_more' => $has_more
        ]);
    } catch (Throwable $e) {
        error_log("Dashboard Activities Error: " . $e->getMessage());
        ob_clean();
        echo json_encode(['error' => $e->getMessage(), 'activities' => [], 'page' => $page, 'has_more' => false]);
    }
    exit();
}

// --- 6. AJAX ENDPOINT FOR REAL-TIME DASHBOARD DATA ---
if (isset($_GET['fetch_realtime_data'])) {
    ini_set('display_errors', '0');
    ob_start();
    header('Content-Type: application/json');
    try {
        $data = get_dashboard_calculations($conn, $base_path);
        ob_clean();
        echo json_encode($data);
    } catch (Throwable $e) {
        ob_clean();
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit();
}

// --- INITIAL PAGE LOAD DATA ---
$dashboard_data = get_dashboard_calculations($conn, $base_path);
$dashboard_stats = $dashboard_data['stats'];
$monthly_revenue = $dashboard_data['chart'];
$is_maintenance_active = $dashboard_data['maintenance'];

$full_name = $_SESSION['full_name'] ?? 'Admin';
$username = $_SESSION['username'] ?? '';
$role = $_SESSION['role'] ?? 'Admin';

// Profile Picture Fetch
$my_pic = 'default_avatar.png';
try {
    $stmt = $conn->prepare("SELECT profile_pic FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user_data = $stmt->fetch();
    $my_pic = $user_data['profile_pic'] ?? 'default_avatar.png';
} catch (Exception $e) { }

$profile_pic_path = $base_path . $my_pic;
$has_profile_pic = file_exists($profile_pic_path) && $my_pic !== 'default_avatar.png';

// Fetch All Users for Notification Dropdown
$all_users = [];
try {
    $stmt = $conn->query("SELECT id, full_name, username, role FROM users WHERE status = 'active' ORDER BY full_name ASC");
    $all_users = $stmt->fetchAll();
} catch (Exception $e) { }

?>
<!DOCTYPE html>
<html lang="en" class="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - CUPAD</title>
    <link rel="icon" type="image/png" href="<?php echo htmlspecialchars($base_path . 'uploads/CUPAD LOGO.png'); ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>
        :root {
            --primary: #2563eb;
            --primary-hover: #1d4ed8;
            --primary-soft: rgba(37, 99, 235, 0.10);
            --primary-border: rgba(37, 99, 235, 0.28);
            --success: #10b981;
            --success-soft: rgba(16, 185, 129, 0.12);
            --warning: #f59e0b;
            --warning-soft: rgba(245, 158, 11, 0.12);
            --danger: #ef4444;
            --danger-soft: rgba(239, 68, 68, 0.12);
            --info: #06b6d4;
            --info-soft: rgba(6, 182, 212, 0.12);
            --purple: #8b5cf6;
            --purple-soft: rgba(139, 92, 246, 0.12);

            --bg-body: #f4f6fb;
            --bg-surface: #ffffff;
            --bg-subtle: #f1f5f9;
            --text-main: #0f172a;
            --text-muted: #64748b;
            --border: #e2e8f0;
            --border-hover: #cbd5e1;

            --radius-xl: 20px;
            --radius-lg: 16px;
            --radius-md: 10px;
            --radius-sm: 8px;

            --shadow-xs: 0 1px 2px 0 rgb(0 0 0 / 0.05);
            --shadow-sm: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
            --shadow-md: 0 10px 15px -3px rgba(0, 0, 0, 0.08);
            --shadow-lg: 0 20px 25px -5px rgba(0, 0, 0, 0.12);
            --shadow-primary: 0 8px 20px rgba(37, 99, 235, 0.25);

            --nav-height: 72px;
            --transition: all 0.22s cubic-bezier(0.4, 0, 0.2, 1);
            --ease-bounce: cubic-bezier(0.34, 1.56, 0.64, 1);
        }

        html.dark {
            --bg-body: #090d16;
            --bg-surface: #131c2e;
            --bg-subtle: #1e293b;
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
            --border: #1e293b;
            --border-hover: #334155;
            --primary: #3b82f6;
            --primary-hover: #60a5fa;
            --primary-soft: rgba(59, 130, 246, 0.16);
            --primary-border: rgba(59, 130, 246, 0.40);
            --shadow-sm: 0 4px 6px -1px rgba(0, 0, 0, 0.3);
            --shadow-md: 0 10px 15px -3px rgba(0, 0, 0, 0.45);
            --shadow-lg: 0 20px 25px -5px rgba(0, 0, 0, 0.55);
            --shadow-primary: 0 8px 20px rgba(59, 130, 246, 0.35);
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
        button { font-family: inherit; cursor: pointer; }

        /* ===== NAVIGATION HEADER ===== */
        .main-header {
            position: fixed; top: 0; left: 0; right: 0;
            height: var(--nav-height);
            background: rgba(255, 255, 255, 0.82);
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
            border-bottom: 1px solid var(--border);
            z-index: 50;
            transition: var(--transition);
        }
        html.dark .main-header { background: rgba(19, 28, 46, 0.82); }
        .navbar { max-width: 1400px; margin: 0 auto; padding: 0 1.5rem; display: flex; align-items: center; justify-content: space-between; height: 100%; }

        .logo {
            display: flex; align-items: center; gap: 0.85rem;
            font-weight: 800; font-size: 1.35rem; color: var(--primary);
            letter-spacing: -0.02em; cursor: pointer; transition: var(--transition);
        }
        .logo:hover { opacity: 0.85; transform: scale(0.98); }
        .logo img { height: 38px; width: auto; }

        .nav-right { display: flex; align-items: center; gap: 0.75rem; }
        .icon-btn {
            width: 40px; height: 40px; border-radius: 50%;
            border: 1px solid var(--border); background: var(--bg-surface);
            color: var(--text-muted); cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            font-size: 1rem; transition: var(--transition); position: relative;
        }
        .icon-btn:hover { background: var(--bg-subtle); color: var(--text-main); transform: translateY(-1px); }

        /* User Dropdown */
        .user-dropdown-wrap { position: relative; }
        .user-pill {
            display: flex; align-items: center; gap: 0.75rem;
            padding: 5px 12px 5px 5px;
            border: 1px solid var(--border); border-radius: 99px;
            background: var(--bg-surface); cursor: pointer; transition: var(--transition);
        }
        .user-pill:hover { border-color: var(--primary); box-shadow: var(--shadow-xs); }
        .user-avatar { width: 34px; height: 34px; border-radius: 50%; object-fit: cover; }
        .user-avatar-fallback { width: 34px; height: 34px; border-radius: 50%; background: var(--bg-subtle); color: var(--text-muted); display: flex; align-items: center; justify-content: center; font-size: 0.9rem; border: 1px solid var(--border); }
        .user-info { display: flex; flex-direction: column; line-height: 1.2; }
        .user-name { font-weight: 700; font-size: 0.85rem; }
        .user-role { font-size: 0.68rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; font-weight: 600; }

        .dropdown-menu {
            position: absolute; top: calc(100% + 10px); right: 0;
            background: var(--bg-surface); border: 1px solid var(--border);
            border-radius: var(--radius-lg); box-shadow: var(--shadow-lg);
            min-width: 230px; display: none; z-index: 1000;
            flex-direction: column; overflow: hidden; padding: 0.5rem;
            animation: dropdownFade 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        }
        @keyframes dropdownFade { from { opacity: 0; transform: translateY(-8px); } to { opacity: 1; transform: translateY(0); } }
        .dropdown-menu.show { display: flex; }
        .dropdown-item { padding: 0.75rem 1rem; display: flex; align-items: center; gap: 0.75rem; font-size: 0.875rem; font-weight: 500; color: var(--text-main); border-radius: var(--radius-md); transition: var(--transition); }
        .dropdown-item:hover { background: var(--primary-soft); color: var(--primary); }
        .dropdown-item.text-danger:hover { background: var(--danger-soft); color: var(--danger); }

        /* ===== SIDEBAR DRAWER ===== */
        .sidebar-backdrop {
            position: fixed; inset: 0; background: rgba(15, 23, 42, 0.65);
            backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px);
            z-index: 1050; opacity: 0; pointer-events: none; transition: opacity 0.3s ease;
        }
        .sidebar-backdrop.show { opacity: 1; pointer-events: auto; }
        .sidebar-drawer {
            position: fixed; top: 0; left: 0; bottom: 0; width: 320px; max-width: 85vw;
            background: var(--bg-surface); border-right: 1px solid var(--border);
            z-index: 1100; transform: translateX(-100%);
            transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            display: flex; flex-direction: column; box-shadow: var(--shadow-lg); overflow-y: auto;
        }
        .sidebar-drawer.show { transform: translateX(0); }
        .sidebar-header { padding: 1.25rem 1.5rem; display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid var(--border); }
        .sidebar-close-btn { background: none; border: none; font-size: 1.25rem; color: var(--text-muted); cursor: pointer; transition: var(--transition); width: 34px; height: 34px; border-radius: 50%; display: flex; align-items: center; justify-content: center; }
        .sidebar-close-btn:hover { background: var(--bg-subtle); color: var(--text-main); }
        .sidebar-profile { padding: 1.25rem 1.5rem; background: var(--bg-subtle); border-bottom: 1px solid var(--border); display: flex; align-items: center; gap: 0.85rem; }
        .sidebar-menu { padding: 1rem; display: flex; flex-direction: column; gap: 0.35rem; }
        .sidebar-link {
            display: flex; align-items: center; gap: 0.85rem; padding: 0.8rem 1rem;
            border-radius: var(--radius-md); font-size: 0.9rem; font-weight: 600;
            color: var(--text-main); transition: var(--transition);
        }
        .sidebar-link:hover, .sidebar-link.active { background: var(--primary-soft); color: var(--primary); }

        /* ===== CONTAINER ===== */
        .container { max-width: 1400px; margin: 0 auto; padding: 2rem 1.5rem 4rem; }

        /* ===== STATS ===== */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1.25rem; margin-bottom: 2rem; }
        .stat-card {
            position: relative; background: var(--bg-surface); padding: 1.4rem 1.5rem;
            border-radius: var(--radius-xl); overflow: hidden;
            display: flex; flex-direction: column; justify-content: space-between;
            min-height: 135px; box-shadow: var(--shadow-sm); transition: var(--transition);
            border: 1px solid var(--border);
        }
        .stat-card:hover { transform: translateY(-3px); box-shadow: var(--shadow-md); border-color: var(--primary); }
        .stat-content { position: relative; z-index: 2; }
        .stat-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.85rem; }
        .stat-icon { width: 44px; height: 44px; border-radius: var(--radius-lg); display: flex; align-items: center; justify-content: center; font-size: 1.25rem; flex-shrink: 0; }
        .stat-value { font-size: 1.75rem; font-weight: 800; line-height: 1; letter-spacing: -0.03em; margin-bottom: 0.35rem; }
        .stat-label { font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-muted); }
        .watermark-icon { position: absolute; right: -10px; bottom: -15px; font-size: 5.5rem; opacity: 0.05; transform: rotate(-10deg); z-index: 1; pointer-events: none; transition: var(--transition); }
        .stat-card:hover .watermark-icon { transform: rotate(0deg) scale(1.08); opacity: 0.08; }

        @media (max-width: 768px) {
            .stats-container { margin: 0 -1.5rem 1.5rem; padding: 0 1.5rem; }
            .stats-grid { display: flex; overflow-x: auto; scroll-snap-type: x mandatory; gap: 12px; padding-bottom: 12px; scrollbar-width: none; -ms-overflow-style: none; }
            .stats-grid::-webkit-scrollbar { display: none; }
            .stat-card { flex: 0 0 85%; min-width: 270px; scroll-snap-align: center; scroll-snap-stop: always; }
            .carousel-dots { display: flex; justify-content: center; gap: 6px; margin-top: 0.25rem; margin-bottom: 1.5rem; }
            .carousel-dot { width: 8px; height: 8px; border-radius: 50%; background: var(--border); transition: var(--transition); }
            .carousel-dot.active { width: 22px; border-radius: 99px; background: var(--primary); }
        }
        @media (min-width: 769px) { .carousel-dots { display: none; } }

        .stat-card.fill-blue .stat-icon { background: var(--primary-soft); color: var(--primary); }
        .stat-card.fill-green .stat-icon { background: var(--success-soft); color: var(--success); }
        .stat-card.fill-orange .stat-icon { background: var(--warning-soft); color: var(--warning); }
        .stat-card.fill-purple .stat-icon { background: var(--purple-soft); color: var(--purple); }
        .stat-card.fill-teal .stat-icon { background: var(--info-soft); color: var(--info); }
        .stat-card.fill-rose .stat-icon { background: var(--danger-soft); color: var(--danger); }

        /* ===== DASHBOARD GRID (EQUAL HEIGHT) ===== */
        .dashboard-grid { 
            display: grid; 
            grid-template-columns: 2fr 1fr; 
            gap: 1.75rem; 
            margin-bottom: 2.5rem; 
            align-items: stretch; 
        }
        @media(max-width: 1024px) { .dashboard-grid { grid-template-columns: 1fr; } }

        .card { 
            background: var(--bg-surface); 
            border: 1px solid var(--border); 
            border-radius: var(--radius-xl); 
            padding: 1.5rem; 
            display: flex; 
            flex-direction: column; 
            box-shadow: var(--shadow-sm); 
            height: 100%;
        }
        .card-header { 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            margin-bottom: 1.25rem; 
            flex-shrink: 0;
        }
        .card-title { font-size: 1.1rem; font-weight: 800; letter-spacing: -0.01em; display: flex; align-items: center; gap: 0.65rem; }
        
        .chart-container { 
            position: relative; 
            width: 100%; 
            flex: 1;
            height: 400px; 
            min-height: 400px; 
        }

        /* Activity feed */
        .activity-feed { 
            flex: 1;
            height: 400px; 
            min-height: 400px; 
            max-height: 400px; 
            overflow-y: auto; 
            padding-right: 6px; 
        }
        .activity-feed::-webkit-scrollbar { width: 4px; }
        .activity-feed::-webkit-scrollbar-thumb { background: var(--border); border-radius: 99px; }
        .activity-item { display: flex; gap: 1rem; padding: 0.85rem 0; border-bottom: 1px dashed var(--border); animation: fadeIn 0.3s ease; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(4px); } to { opacity: 1; transform: translateY(0); } }
        .timeline-dot { width: 10px; height: 10px; border-radius: 50%; margin-top: 6px; flex-shrink: 0; }
        .dot-success { background: var(--success); box-shadow: 0 0 0 4px var(--success-soft); }
        .dot-warning { background: var(--warning); box-shadow: 0 0 0 4px var(--warning-soft); }
        .dot-info { background: var(--info); box-shadow: 0 0 0 4px var(--info-soft); }
        .dot-danger { background: var(--danger); box-shadow: 0 0 0 4px var(--danger-soft); }
        .dot-muted { background: var(--text-muted); box-shadow: 0 0 0 4px rgba(100,116,139,0.15); }

        /* Ghost shimmer */
        .ghost-feed-item { display: flex; gap: 1rem; padding: 0.85rem 0; border-bottom: 1px dashed var(--border); position: relative; overflow: hidden; }
        .ghost-dot { width: 10px; height: 10px; border-radius: 50%; background: var(--border); margin-top: 6px; flex-shrink: 0; }
        .ghost-line { height: 12px; background: var(--border); border-radius: 6px; margin-bottom: 6px; opacity: 0.6; }
        .ghost-line.w-75 { width: 75%; }
        .ghost-line.w-50 { width: 50%; }
        .ghost-line.w-20 { width: 20%; }
        .ghost-shimmer-wrap { position: relative; overflow: hidden; }
        .ghost-shimmer-wrap::after { content: ''; position: absolute; inset: 0; transform: translateX(-100%); background: linear-gradient(90deg, rgba(255,255,255,0) 0%, rgba(255,255,255,0.5) 50%, rgba(255,255,255,0) 100%); animation: ghostShimmer 1.5s infinite; }
        html.dark .ghost-shimmer-wrap::after { background: linear-gradient(90deg, rgba(255,255,255,0) 0%, rgba(255,255,255,0.1) 50%, rgba(255,255,255,0) 100%); }
        @keyframes ghostShimmer { 100% { transform: translateX(100%); } }

        /* ===== SECTION HEADER ===== */
        .section-header { font-size: 1.25rem; font-weight: 800; letter-spacing: -0.02em; margin-bottom: 1.25rem; display: flex; align-items: center; gap: 0.6rem; }

        /* ===== ACTION CARDS ===== */
        .action-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 1.1rem; margin-bottom: 2.25rem; }
        .action-card {
            display: flex; gap: 1rem; padding: 1.15rem;
            background: var(--bg-surface); border: 1px solid var(--border);
            border-radius: var(--radius-lg); transition: var(--transition);
            align-items: center; position: relative; cursor: pointer; box-shadow: var(--shadow-xs);
        }
        .action-card:hover { border-color: var(--primary); box-shadow: var(--shadow-md); transform: translateY(-2px); }
        .ac-icon { width: 44px; height: 44px; border-radius: var(--radius-md); display: flex; align-items: center; justify-content: center; font-size: 1.2rem; flex-shrink: 0; transition: var(--transition); }
        .ac-text h3 { margin: 0 0 0.2rem; font-size: 0.925rem; font-weight: 700; color: var(--text-main); }
        .ac-text p { margin: 0; font-size: 0.78rem; color: var(--text-muted); line-height: 1.35; }
        .ac-arrow { position: absolute; right: 1rem; color: var(--text-muted); font-size: 0.75rem; opacity: 0; transform: translateX(-6px); transition: var(--transition); }
        .action-card:hover .ac-arrow { opacity: 1; transform: translateX(0); color: var(--primary); }

        .bg-blue { background: var(--primary-soft); color: var(--primary); }
        .bg-green { background: var(--success-soft); color: var(--success); }
        .bg-orange { background: var(--warning-soft); color: var(--warning); }
        .bg-purple { background: var(--purple-soft); color: var(--purple); }
        .bg-rose { background: var(--danger-soft); color: var(--danger); }
        .bg-teal { background: var(--info-soft); color: var(--info); }

        /* ===== TOOLBAR ===== */
        .controls-toolbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem; }
        .search-wrapper { position: relative; width: 100%; max-width: 360px; }
        .search-wrapper input {
            width: 100%; padding: 0.7rem 2.4rem 0.7rem 2.5rem; border-radius: 99px;
            border: 1px solid var(--border); background: var(--bg-surface); color: var(--text-main);
            font-size: 0.875rem; font-family: inherit; transition: var(--transition);
        }
        .search-wrapper input:focus { border-color: var(--primary); box-shadow: 0 0 0 3px var(--primary-soft); }
        .search-wrapper i.search-icon { position: absolute; left: 1rem; top: 50%; transform: translateY(-50%); color: var(--text-muted); font-size: 0.85rem; }
        .search-clear { position: absolute; right: 1rem; top: 50%; transform: translateY(-50%); cursor: pointer; color: var(--text-muted); font-size: 0.85rem; display: none; }

        /* ===== TABS ===== */
        .tabs-header { display: flex; gap: 0.5rem; overflow-x: auto; border-bottom: 1px solid var(--border); padding-bottom: 0.75rem; margin-bottom: 1.75rem; scrollbar-width: none; }
        .tabs-header::-webkit-scrollbar { display: none; }
        .tab-btn {
            background: none; border: none; padding: 0.6rem 1.1rem;
            font-family: inherit; font-size: 0.9rem; font-weight: 700;
            color: var(--text-muted); cursor: pointer; border-radius: var(--radius-md);
            transition: var(--transition); white-space: nowrap; display: flex; align-items: center; gap: 0.55rem;
        }
        .tab-btn:hover { color: var(--primary); background: var(--bg-subtle); }
        .tab-btn.active { color: #fff; background: var(--primary); box-shadow: var(--shadow-primary); }
        .tab-content { display: none; animation: fadeIn 0.3s ease; }
        .tab-content.active { display: block; }

        /* ===== SWITCH ===== */
        .switch { position: relative; display: inline-block; width: 44px; height: 24px; margin-left: auto; flex-shrink: 0; }
        .switch input { opacity: 0; width: 0; height: 0; }
        .slider { position: absolute; cursor: pointer; inset: 0; background-color: var(--border); transition: .3s; border-radius: 24px; }
        .slider:before { position: absolute; content: ""; height: 18px; width: 18px; left: 3px; bottom: 3px; background-color: #ffffff; transition: .3s; border-radius: 50%; box-shadow: 0 1px 3px rgba(0,0,0,0.2); }
        input:checked + .slider { background-color: var(--danger); }
        input:checked + .slider:before { transform: translateX(20px); }

        /* ===== NOTIFICATIONS ===== */
        .notif-wrapper { display: grid; grid-template-columns: 2fr 1fr; gap: 1.75rem; align-items: start; }
        @media(max-width: 900px) { .notif-wrapper { grid-template-columns: 1fr; } }
        .form-group { margin-bottom: 1.25rem; }
        .form-label { display: block; margin-bottom: 0.5rem; font-weight: 700; font-size: 0.85rem; color: var(--text-main); }
        .select-wrapper { position: relative; }
        .select-wrapper select { width: 100%; padding: 0.75rem 1rem; border-radius: var(--radius-md); border: 1px solid var(--border); background: var(--bg-subtle); color: var(--text-main); appearance: none; cursor: pointer; font-family: inherit; font-size: 0.875rem; transition: var(--transition); }
        .select-wrapper select:focus, textarea:focus { border-color: var(--primary); background: var(--bg-surface); box-shadow: 0 0 0 3px var(--primary-soft); }
        .select-arrow { position: absolute; right: 1rem; top: 50%; transform: translateY(-50%); pointer-events: none; font-size: 0.8rem; color: var(--text-muted); }
        textarea { width: 100%; padding: 0.85rem; border-radius: var(--radius-md); border: 1px solid var(--border); background: var(--bg-subtle); color: var(--text-main); font-family: inherit; font-size: 0.875rem; resize: vertical; transition: var(--transition); }
        .char-count { text-align: right; font-size: 0.75rem; color: var(--text-muted); margin-top: 6px; font-weight: 600; }
        .quick-chips { display: flex; gap: 0.5rem; margin-bottom: 1.5rem; flex-wrap: wrap; }
        .chip { padding: 6px 14px; background: var(--bg-subtle); border: 1px solid var(--border); border-radius: 99px; font-size: 0.78rem; font-weight: 600; cursor: pointer; transition: var(--transition); color: var(--text-muted); }
        .chip:hover { background: var(--primary); color: #ffffff; border-color: var(--primary); }
        .btn-send { width: 100%; padding: 0.85rem; background: var(--primary); color: #ffffff; border: none; border-radius: var(--radius-md); font-weight: 700; font-size: 0.9rem; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 0.6rem; transition: var(--transition); box-shadow: var(--shadow-primary); }
        .btn-send:hover { background: var(--primary-hover); transform: translateY(-1px); }
        .btn-send:disabled { background: var(--text-muted); box-shadow: none; cursor: not-allowed; transform: none; }
        .spinner { width: 20px; height: 20px; border: 3px solid rgba(255,255,255,0.3); border-radius: 50%; border-top-color: #ffffff; animation: spin 1s ease-in-out infinite; display: none; }
        @keyframes spin { to { transform: rotate(360deg); } }
        .btn-send.loading .btn-text, .btn-send.loading i { display: none; }
        .btn-send.loading .spinner { display: block; }

        .history-item { display: flex; gap: 0.85rem; padding: 0.85rem 0; border-bottom: 1px solid var(--border); animation: fadeIn 0.3s; }
        .history-item:last-child { border-bottom: none; }
        .h-icon { width: 34px; height: 34px; border-radius: var(--radius-md); background: var(--primary-soft); color: var(--primary); display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 0.85rem; }
        .h-content { flex: 1; overflow: hidden; }
        .h-top { display: flex; justify-content: space-between; font-size: 0.8rem; margin-bottom: 2px; }
        .h-top strong { color: var(--text-main); font-weight: 700; }
        .h-top span { color: var(--text-muted); font-size: 0.72rem; }
        .h-content p { margin: 0; font-size: 0.825rem; color: var(--text-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

        /* ===== TOAST ===== */
        .toast-container { position: fixed; top: 85px; right: 20px; z-index: 100; display: flex; flex-direction: column; gap: 10px; pointer-events: none; }
        .toast { pointer-events: auto; background: var(--bg-surface); color: var(--text-main); padding: 0.9rem 1.25rem; border-radius: var(--radius-lg); box-shadow: var(--shadow-lg); border-left: 4px solid var(--primary); min-width: 280px; max-width: 360px; animation: slideIn 0.3s var(--ease-bounce); display: flex; align-items: center; gap: 0.75rem; font-size: 0.875rem; font-weight: 600; opacity: 0; transform: translateX(50px); border-top: 1px solid var(--border); border-right: 1px solid var(--border); border-bottom: 1px solid var(--border); }
        .toast.success { border-left-color: var(--success); }
        .toast.error { border-left-color: var(--danger); }
        @keyframes slideIn { from { opacity: 0; transform: translateX(50px); } to { opacity: 1; transform: translateY(0); } }

        /* ===== MOBILE BOTTOM NAV ===== */
        .mobile-bottom-nav {
            display: none; position: fixed; bottom: 0; left: 0; right: 0;
            background: rgba(255, 255, 255, 0.92); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px);
            border-top: 1px solid var(--border); padding: 0.45rem 0.5rem calc(0.45rem + env(safe-area-inset-bottom));
            z-index: 1000; box-shadow: 0 -4px 15px rgba(0, 0, 0, 0.05);
            justify-content: space-around; align-items: center;
        }
        html.dark .mobile-bottom-nav { background: rgba(19, 28, 46, 0.92); }
        .nav-tab-item { display: flex; flex-direction: column; align-items: center; justify-content: center; color: var(--text-muted); font-size: 0.7rem; font-weight: 700; gap: 3px; flex: 1; padding: 4px 0; transition: var(--transition); }
        .nav-tab-item i { font-size: 1.25rem; }
        .nav-tab-item.active { color: var(--primary); }
        @media (max-width: 768px) { .mobile-bottom-nav { display: flex; } body { padding-bottom: 80px; } }

        .no-results { grid-column: 1 / -1; padding: 2.5rem; text-align: center; color: var(--text-muted); display: none; flex-direction: column; align-items: center; gap: 0.5rem; font-weight: 600; }
        .empty-state { text-align: center; color: var(--text-muted); padding: 2rem 0; font-weight: 500; }
        .empty-state i { font-size: 1.75rem; margin-bottom: 0.5rem; opacity: 0.4; }
    </style>
</head>
<body>

    <!-- Main Navigation Header -->
    <header class="main-header">
        <div class="navbar">
            <div class="logo" id="logoSidebarTrigger" title="Open Administrative Menu">
                <img src="<?php echo htmlspecialchars($base_path . 'uploads/CUPAD LOGO.png'); ?>" alt="CUPAD Logo">
                <span>CUPAD</span>
            </div>
            <div class="nav-right">
                <a href="clients.php" class="icon-btn" title="View All Clients"><i class="fas fa-users"></i></a>
                <button class="icon-btn" id="themeToggle" title="Toggle Theme"><i class="fas fa-moon"></i></button>
                <div class="user-dropdown-wrap">
                    <div class="user-pill" id="userDropdownTrigger">
                        <?php if($has_profile_pic): ?>
                            <img src="<?php echo htmlspecialchars($profile_pic_path); ?>" class="user-avatar" alt="User Avatar">
                        <?php else: ?>
                            <div class="user-avatar-fallback"><i class="fas fa-user"></i></div>
                        <?php endif; ?>
                        <div class="user-info">
                            <span class="user-name"><?php echo htmlspecialchars($full_name); ?></span>
                            <span class="user-role"><?php echo ucfirst(htmlspecialchars($role)); ?></span>
                        </div>
                        <i class="fas fa-chevron-down" style="font-size:0.75rem; color:var(--text-muted)"></i>
                    </div>
                    <div class="dropdown-menu" id="userDropdown">
                        <div style="padding: 0.75rem 1rem 0.25rem; font-size: 0.7rem; color:var(--text-muted); font-weight:700; text-transform:uppercase;">SIGNED IN AS</div>
                        <div style="padding: 0 1rem 0.6rem; font-weight:800; font-size:0.9rem;"><?php echo htmlspecialchars($username); ?></div>
                        <div style="height:1px; background:var(--border); margin:0;"></div>
                        <a href="profile.php" class="dropdown-item"><i class="fas fa-user-circle" style="color:var(--primary)"></i> My Profile</a>
                        <div style="height:1px; background:var(--border); margin:0;"></div>
                        <a href="../logout.php" class="dropdown-item text-danger"><i class="fas fa-sign-out-alt"></i> Logout</a>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <!-- Slide-Out Side Navbar Drawer -->
    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>
    <div class="sidebar-drawer" id="sidebarDrawer">
        <div class="sidebar-header">
            <div style="display:flex; align-items:center; gap:0.75rem; font-weight:800; color:var(--primary);">
                <img src="<?php echo htmlspecialchars($base_path . 'uploads/CUPAD LOGO.png'); ?>" alt="Logo" style="height:32px;">
                <span>Navigation Menu</span>
            </div>
            <button class="sidebar-close-btn" id="sidebarCloseBtn">&times;</button>
        </div>
        <div class="sidebar-profile">
            <?php if($has_profile_pic): ?>
                <img src="<?php echo htmlspecialchars($profile_pic_path); ?>" style="width:40px; height:40px; border-radius:50%; object-fit:cover;" alt="User">
            <?php else: ?>
                <div style="width:40px; height:40px; border-radius:50%; background:var(--bg-surface); border:1px solid var(--border); display:flex; align-items:center; justify-content:center; color:var(--text-muted);"><i class="fas fa-user"></i></div>
            <?php endif; ?>
            <div style="display:flex; flex-direction:column;">
                <span style="font-weight:700; font-size:0.9rem;"><?php echo htmlspecialchars($full_name); ?></span>
                <span style="font-size:0.75rem; color:var(--text-muted);"><?php echo htmlspecialchars($username); ?></span>
            </div>
        </div>
        <div class="sidebar-menu">
            <a href="dashboard.php" class="sidebar-link active"><i class="fas fa-gauge"></i> Dashboard</a>
            <a href="manage_clients.php" class="sidebar-link"><i class="fas fa-user-friends"></i> Manage Clients</a>
            <a href="manage_loans.php" class="sidebar-link"><i class="fas fa-hand-holding-usd"></i> Loan Management</a>
            <a href="transaction_manager.php" class="sidebar-link"><i class="fas fa-exchange-alt"></i> Transactions</a>
            <a href="client_financial_summary.php" class="sidebar-link"><i class="fas fa-file-invoice-dollar"></i> Financial Summary</a>
            <a href="backup_restore.php" class="sidebar-link"><i class="fas fa-database"></i> Backup & Restore</a>
            <a href="system_logs.php" class="sidebar-link"><i class="fas fa-file-alt"></i> System Logs</a>
            <a href="profile.php" class="sidebar-link"><i class="fas fa-user-gear"></i> Profile Settings</a>
            <div style="height:1px; background:var(--border); margin:0.5rem 0;"></div>
            <a href="../logout.php" class="sidebar-link" style="color:var(--danger);"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
    </div>

    <div id="toastContainer" class="toast-container"></div>

    <main class="container">
        <!-- 1. Stats Carousel for Mobile Screens -->
        <div class="stats-container">
            <div class="stats-grid" id="statsGrid">
                <a href="active_users_admin.php" class="stat-card fill-blue">
                    <i class="fas fa-users watermark-icon"></i>
                    <div class="stat-content">
                        <div class="stat-header"><div class="stat-icon"><i class="fas fa-users"></i></div></div>
                        <div class="stat-value" id="rt_users"><?php echo number_format($dashboard_stats['total_users']); ?></div>
                        <div class="stat-label">Total Users</div>
                    </div>
                </a>
                <div class="stat-card fill-green">
                    <i class="fas fa-user-friends watermark-icon"></i>
                    <div class="stat-content">
                        <div class="stat-header"><div class="stat-icon"><i class="fas fa-user-friends"></i></div></div>
                        <div class="stat-value" id="rt_clients"><?php echo number_format($dashboard_stats['total_clients']); ?></div>
                        <div class="stat-label">Total Clients</div>
                    </div>
                </div>
                <div class="stat-card fill-orange">
                    <i class="fas fa-building watermark-icon"></i>
                    <div class="stat-content">
                        <div class="stat-header"><div class="stat-icon"><i class="fas fa-building"></i></div></div>
                        <div class="stat-value" id="rt_branches"><?php echo $dashboard_stats['total_branches']; ?></div>
                        <div class="stat-label">Active Branches</div>
                    </div>
                </div>
                <div class="stat-card fill-purple">
                    <i class="fas fa-wifi watermark-icon"></i>
                    <div class="stat-content">
                        <div class="stat-header"><div class="stat-icon"><i class="fas fa-wifi"></i></div></div>
                        <div class="stat-value" id="rt_sessions"><?php echo $dashboard_stats['active_sessions']; ?></div>
                        <div class="stat-label">Online Users</div>
                    </div>
                </div>
                <div class="stat-card fill-teal">
                    <i class="fas fa-heartbeat watermark-icon"></i>
                    <div class="stat-content">
                        <div class="stat-header"><div class="stat-icon"><i class="fas fa-heartbeat"></i></div></div>
                        <div class="stat-value"><span id="rt_health"><?php echo $dashboard_stats['system_health']; ?></span>%</div>
                        <div class="stat-label">System Health</div>
                    </div>
                </div>
                <a href="manage_registrations.php" class="stat-card fill-rose">
                    <i class="fas fa-user-plus watermark-icon"></i>
                    <div class="stat-content">
                        <div class="stat-header"><div class="stat-icon"><i class="fas fa-user-plus"></i></div></div>
                        <div class="stat-value" id="rt_pending"><?php echo $dashboard_stats['pending_registrations']; ?></div>
                        <div class="stat-label">Monthly Regs</div>
                    </div>
                </a>
                <div class="stat-card fill-green">
                    <i class="fas fa-chart-line watermark-icon"></i>
                    <div class="stat-content">
                        <div class="stat-header"><div class="stat-icon"><i class="fas fa-chart-line"></i></div></div>
                        <div class="stat-value" id="rt_revenue"><?php echo $dashboard_stats['daily_transactions']; ?></div>
                        <div class="stat-label">Transactions Today</div>
                    </div>
                </div>
                <div class="stat-card fill-blue">
                    <i class="fas fa-dollar-sign watermark-icon"></i>
                    <div class="stat-content">
                        <div class="stat-header"><div class="stat-icon"><i class="fas fa-dollar-sign"></i></div></div>
                        <div class="stat-value" id="rt_revenue_today">₦<?php echo number_format($dashboard_stats['revenue_today']); ?></div>
                        <div class="stat-label">Revenue Today</div>
                    </div>
                </div>
                <div class="stat-card fill-orange">
                    <i class="fas fa-hand-holding-usd watermark-icon"></i>
                    <div class="stat-content">
                        <div class="stat-header"><div class="stat-icon"><i class="fas fa-hand-holding-usd"></i></div></div>
                        <div class="stat-value" id="rt_active_loans"><?php echo number_format($dashboard_stats['active_loans']); ?></div>
                        <div class="stat-label">Active Loans</div>
                    </div>
                </div>
                <div class="stat-card fill-rose">
                    <i class="fas fa-tools watermark-icon"></i>
                    <div class="stat-content">
                        <div class="stat-header"><div class="stat-icon"><i class="fas fa-tools"></i></div></div>
                        <div class="stat-value" id="mStatus"><?php echo $is_maintenance_active ? 'ON' : 'OFF'; ?></div>
                        <div class="stat-label">Maintenance</div>
                    </div>
                </div>
            </div>
            <div class="carousel-dots" id="carouselDots"></div>
        </div>

        <!-- 2. Charts & Recent Activity Workspace -->
        <div class="dashboard-grid">
            <div class="card">
                <div class="card-header">
                    <div class="card-title">
                        <i class="fas fa-chart-area" style="color:var(--primary)"></i>
                        Revenue Trends (<?php echo $current_year; ?>)
                    </div>
                </div>
                <div class="chart-container">
                    <canvas id="revenueChart"></canvas>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <div class="card-title">
                        <i class="fas fa-history" style="color:var(--warning)"></i>
                        Recent Activities
                    </div>
                    <div style="display:flex; gap:0.5rem">
                        <a href="recent_activities.php" class="icon-btn" title="View Full Log"><i class="fas fa-external-link-alt"></i></a>
                        <button class="icon-btn" onclick="reloadActivities()" title="Refresh Feed"><i class="fas fa-sync-alt"></i></button>
                    </div>
                </div>
                <div class="activity-feed" id="activityFeed"></div>
            </div>
        </div>

        <!-- 3. Quick Actions Grid -->
        <h3 class="section-header"><i class="fas fa-bolt" style="color:var(--warning)"></i> Quick Actions</h3>
        <div class="action-grid">
            <a href="create_account.php" class="action-card">
                <div class="ac-icon bg-blue"><i class="fas fa-user-plus"></i></div>
                <div class="ac-text"><h3>New Account</h3><p>Create staff login</p></div>
                <i class="fas fa-chevron-right ac-arrow"></i>
            </a>
            <a href="manage_registrations.php" class="action-card">
                <div class="ac-icon bg-green"><i class="fas fa-user-check"></i></div>
                <div class="ac-text"><h3>Approve Regs</h3><p>Review pending</p></div>
                <i class="fas fa-chevron-right ac-arrow"></i>
            </a>
            <a href="recent_activities.php" class="action-card">
                <div class="ac-icon bg-teal"><i class="fas fa-history"></i></div>
                <div class="ac-text"><h3>History</h3><p>Recent events</p></div>
                <i class="fas fa-chevron-right ac-arrow"></i>
            </a>
            <a href="analytics.php" class="action-card">
                <div class="ac-icon bg-purple"><i class="fas fa-chart-bar"></i></div>
                <div class="ac-text"><h3>Analytics</h3><p>View insights</p></div>
                <i class="fas fa-chevron-right ac-arrow"></i>
            </a>
            <a href="transaction_manager.php" class="action-card">
                <div class="ac-icon bg-orange"><i class="fas fa-exchange-alt"></i></div>
                <div class="ac-text"><h3>Transactions</h3><p>Manage all entries</p></div>
                <i class="fas fa-chevron-right ac-arrow"></i>
            </a>
            <a href="client_financial_summary.php" class="action-card">
                <div class="ac-icon bg-teal"><i class="fas fa-file-invoice-dollar"></i></div>
                <div class="ac-text"><h3>Financial Summary</h3><p>Savings & loans overview</p></div>
                <i class="fas fa-chevron-right ac-arrow"></i>
            </a>
            <a href="system_logs.php" class="action-card">
                <div class="ac-icon bg-rose"><i class="fas fa-file-alt"></i></div>
                <div class="ac-text"><h3>System Logs</h3><p>Audit trail</p></div>
                <i class="fas fa-chevron-right ac-arrow"></i>
            </a>
        </div>

        <!-- 4. Administrative Controls Toolbar -->
        <div class="controls-toolbar">
            <h3 class="section-header" style="margin:0;"><i class="fas fa-sliders-h" style="color:var(--primary)"></i> Administrative Controls</h3>
            <div class="search-wrapper">
                <i class="fas fa-search search-icon"></i>
                <input type="text" id="actionSearch" placeholder="Filter controls & settings..." onkeyup="debouncedFilter()">
                <i class="fas fa-times search-clear" id="searchClear" onclick="clearSearch()"></i>
            </div>
        </div>

        <div class="tabs-container">
            <div class="tabs-header">
                <button class="tab-btn active" onclick="openTab('t_users', this)"><i class="fas fa-users-cog"></i> Users & Staff</button>
                <button class="tab-btn" onclick="openTab('t_clients', this)"><i class="fas fa-user-friends"></i> Client Operations</button>
                <button class="tab-btn" onclick="openTab('t_finance', this)"><i class="fas fa-wallet"></i> Financial Reports</button>
                <button class="tab-btn" onclick="openTab('t_config', this)"><i class="fas fa-sliders-h"></i> Configuration</button>
                <button class="tab-btn" onclick="openTab('t_system', this)"><i class="fas fa-server"></i> System & Security</button>
                <button class="tab-btn" onclick="openTab('t_notifications', this)"><i class="fas fa-bell"></i> Notifications</button>
            </div>

            <!-- Tab 1: Users -->
            <div id="t_users" class="tab-content active">
                <div class="action-grid">
                    <a href="assign_role.php" class="action-card" data-tags="role permission">
                        <div class="ac-icon bg-blue"><i class="fas fa-user-tag"></i></div>
                        <div class="ac-text"><h3>Assign Roles</h3><p>Manage staff permissions.</p></div>
                        <i class="fas fa-chevron-right ac-arrow"></i>
                    </a>
                    <a href="active_users_admin.php" class="action-card" data-tags="monitor online">
                        <div class="ac-icon bg-purple"><i class="fas fa-users"></i></div>
                        <div class="ac-text"><h3>Active Users</h3><p>Real-time monitor.</p></div>
                        <i class="fas fa-chevron-right ac-arrow"></i>
                    </a>
                    <a href="create_account.php" class="action-card" data-tags="add user">
                        <div class="ac-icon bg-blue"><i class="fas fa-user-plus"></i></div>
                        <div class="ac-text"><h3>Create Account</h3><p>Add new staff member.</p></div>
                        <i class="fas fa-chevron-right ac-arrow"></i>
                    </a>
                    <a href="manage_users.php" class="action-card" data-tags="edit user">
                        <div class="ac-icon bg-blue"><i class="fas fa-users-cog"></i></div>
                        <div class="ac-text"><h3>Manage Users</h3><p>Edit accounts & profiles.</p></div>
                        <i class="fas fa-chevron-right ac-arrow"></i>
                    </a>
                    <a href="manage_lockout.php" class="action-card" data-tags="ban unlock">
                        <div class="ac-icon bg-rose"><i class="fas fa-user-lock"></i></div>
                        <div class="ac-text"><h3>Lockouts</h3><p>Unlock user accounts.</p></div>
                        <i class="fas fa-chevron-right ac-arrow"></i>
                    </a>
                    <a href="deleted_users_manager.php" class="action-card" data-tags="restore trash">
                        <div class="ac-icon bg-orange"><i class="fas fa-trash-restore"></i></div>
                        <div class="ac-text"><h3>Deleted Users</h3><p>Restore soft-deleted users.</p></div>
                        <i class="fas fa-chevron-right ac-arrow"></i>
                    </a>
                    <a href="view_user_locations.php" class="action-card" data-tags="gps tracker map coordinates latitude longitude location user">
                        <div class="ac-icon bg-purple"><i class="fas fa-map-marked-alt"></i></div>
                        <div class="ac-text"><h3>User GPS Locations</h3><p>View user login coordinates.</p></div>
                        <i class="fas fa-chevron-right ac-arrow"></i>
                    </a>
                    <div class="no-results" id="noRes_users"><i class="fas fa-search" style="font-size:2rem"></i> No matching controls found.</div>
                </div>
            </div>

            <!-- Tab 2: Client Operations -->
            <div id="t_clients" class="tab-content">
                <div class="action-grid">
                    <a href="manage_clients.php" class="action-card" data-tags="client customer">
                        <div class="ac-icon bg-green"><i class="fas fa-user-friends"></i></div>
                        <div class="ac-text"><h3>Manage Clients</h3><p>Browse client database.</p></div>
                        <i class="fas fa-chevron-right ac-arrow"></i>
                    </a>
                    <a href="manage_registrations.php" class="action-card" data-tags="approve">
                        <div class="ac-icon bg-teal"><i class="fas fa-user-check"></i></div>
                        <div class="ac-text"><h3>Monthly Regs</h3><p><span id="rt_pending_text"><?php echo $dashboard_stats['pending_registrations']; ?></span> Pending this month.</p></div>
                        <i class="fas fa-chevron-right ac-arrow"></i>
                    </a>
                    <a href="manage_loans.php" class="action-card" data-tags="loan active completed">
                        <div class="ac-icon bg-teal"><i class="fas fa-hand-holding-usd"></i></div>
                        <div class="ac-text"><h3>Manage Loans</h3><p>Active & completed loans.</p></div>
                        <i class="fas fa-chevron-right ac-arrow"></i>
                    </a>
                    <a href="active_loans.php" class="action-card" data-tags="loan active">
                        <div class="ac-icon bg-blue"><i class="fas fa-file-invoice-dollar"></i></div>
                        <div class="ac-text"><h3>Active Loans</h3><p>Current active loans.</p></div>
                        <i class="fas fa-chevron-right ac-arrow"></i>
                    </a>
                    <a href="transfer_client.php" class="action-card" data-tags="move branch">
                        <div class="ac-icon bg-orange"><i class="fas fa-exchange-alt"></i></div>
                        <div class="ac-text"><h3>Transfer Client</h3><p>Reassign branches.</p></div>
                        <i class="fas fa-chevron-right ac-arrow"></i>
                    </a>
                    <a href="manage_unions.php" class="action-card" data-tags="group">
                        <div class="ac-icon bg-purple"><i class="fas fa-users"></i></div>
                        <div class="ac-text"><h3>Manage Unions</h3><p>Cooperatives & groups.</p></div>
                        <i class="fas fa-chevron-right ac-arrow"></i>
                    </a>
                    <a href="close_clients.php" class="action-card" data-tags="close archive">
                        <div class="ac-icon bg-rose"><i class="fas fa-user-times"></i></div>
                        <div class="ac-text"><h3>Close Clients</h3><p>Archive client files.</p></div>
                        <i class="fas fa-chevron-right ac-arrow"></i>
                    </a>
                    <a href="restore_clients.php" class="action-card" data-tags="restore unarchive">
                        <div class="ac-icon bg-green"><i class="fas fa-user-check"></i></div>
                        <div class="ac-text"><h3>Restore Clients</h3><p>Unarchive client files.</p></div>
                        <i class="fas fa-chevron-right ac-arrow"></i>
                    </a>
                    <a href="clients_two_active_loans.php" class="action-card" data-tags="multiple loans">
                        <div class="ac-icon bg-orange"><i class="fas fa-exclamation-triangle"></i></div>
                        <div class="ac-text"><h3>Multiple Loans</h3><p>Clients with 2+ loans.</p></div>
                        <i class="fas fa-chevron-right ac-arrow"></i>
                    </a>
                    <a href="orphaned_clients.php" class="action-card" data-tags="orphaned unassigned branch missing fix">
                        <div class="ac-icon bg-rose"><i class="fas fa-unlink"></i></div>
                        <div class="ac-text"><h3>Orphaned Clients</h3><p>Fix unlinked branch records.</p></div>
                        <i class="fas fa-chevron-right ac-arrow"></i>
                    </a>
                    <div class="no-results" id="noRes_clients"><i class="fas fa-search" style="font-size:2rem"></i> No matching controls found.</div>
                </div>
            </div>

            <!-- Tab 3: Finance -->
            <div id="t_finance" class="tab-content">
                <div class="action-grid">
                    <a href="transaction_manager.php" class="action-card" data-tags="ledger history">
                        <div class="ac-icon bg-teal"><i class="fas fa-exchange-alt"></i></div>
                        <div class="ac-text"><h3>Transactions</h3><p>View system entries.</p></div>
                        <i class="fas fa-chevron-right ac-arrow"></i>
                    </a>
                    <a href="client_financial_summary.php" class="action-card" data-tags="money report">
                        <div class="ac-icon bg-teal"><i class="fas fa-file-invoice-dollar"></i></div>
                        <div class="ac-text"><h3>Financial Summary</h3><p>Savings & Loans reports.</p></div>
                        <i class="fas fa-chevron-right ac-arrow"></i>
                    </a>
                    <a href="manage_client_finances.php" class="action-card" data-tags="money balance">
                        <div class="ac-icon bg-blue"><i class="fas fa-wallet"></i></div>
                        <div class="ac-text"><h3>Client Finances</h3><p>Manage account balances.</p></div>
                        <i class="fas fa-chevron-right ac-arrow"></i>
                    </a>
                    <a href="branch_disbursement.php" class="action-card" data-tags="loan money">
                        <div class="ac-icon bg-purple"><i class="fas fa-chart-bar"></i></div>
                        <div class="ac-text"><h3>Disbursements</h3><p>Loan reports.</p></div>
                        <i class="fas fa-chevron-right ac-arrow"></i>
                    </a>
                    <a href="view_disbursements.php" class="action-card" data-tags="loan disbursement view list">
                        <div class="ac-icon bg-teal"><i class="fas fa-list-alt"></i></div>
                        <div class="ac-text"><h3>View Disbursements</h3><p>Browse all disbursed loans.</p></div>
                        <i class="fas fa-chevron-right ac-arrow"></i>
                    </a>
                    <a href="view_withdrawal.php" class="action-card" data-tags="withdrawal savings withdraw view list">
                        <div class="ac-icon bg-rose"><i class="fas fa-money-bill-wave"></i></div>
                        <div class="ac-text"><h3>View Withdrawals</h3><p>Browse all withdrawals.</p></div>
                        <i class="fas fa-chevron-right ac-arrow"></i>
                    </a>
                    <a href="branch_savings.php" class="action-card" data-tags="savings branch">
                        <div class="ac-icon bg-green"><i class="fas fa-piggy-bank"></i></div>
                        <div class="ac-text"><h3>Branch Savings</h3><p>Savings balance reports.</p></div>
                        <i class="fas fa-chevron-right ac-arrow"></i>
                    </a>
                    <a href="show_pictures.php" class="action-card" data-tags="image receipt pictures">
                        <div class="ac-icon bg-orange"><i class="fas fa-images"></i></div>
                        <div class="ac-text"><h3>Receipts & Pics</h3><p>View disbursement images.</p></div>
                        <i class="fas fa-chevron-right ac-arrow"></i>
                    </a>
                    <a href="deleted_transactions.php" class="action-card" data-tags="deleted trash restore">
                        <div class="ac-icon bg-rose"><i class="fas fa-trash-restore"></i></div>
                        <div class="ac-text"><h3>Deleted Transactions</h3><p>Restore deleted entries.</p></div>
                        <i class="fas fa-chevron-right ac-arrow"></i>
                    </a>
                    <a href="get_deleted_savings.php" class="action-card" data-tags="deleted savings">
                        <div class="ac-icon bg-rose"><i class="fas fa-undo"></i></div>
                        <div class="ac-text"><h3>Deleted Savings</h3><p>View deleted savings.</p></div>
                        <i class="fas fa-chevron-right ac-arrow"></i>
                    </a>
                    <a href="savings_balance.php" class="action-card" data-tags="adjust savings balance">
                        <div class="ac-icon bg-blue"><i class="fas fa-wallet"></i></div>
                        <div class="ac-text"><h3>Adjust Savings</h3><p>Modify client balances.</p></div>
                        <i class="fas fa-chevron-right ac-arrow"></i>
                    </a>
                    <a href="savings_withdrawal.php" class="action-card" data-tags="savings withdrawal payout lapsed return cash">
                        <div class="ac-icon bg-rose"><i class="fas fa-hand-holding-usd"></i></div>
                        <div class="ac-text"><h3>Savings Withdrawal</h3><p>Process payouts & returns.</p></div>
                        <i class="fas fa-chevron-right ac-arrow"></i>
                    </a>
                    <div class="no-results" id="noRes_finance"><i class="fas fa-search" style="font-size:2rem"></i> No matching controls found.</div>
                </div>
            </div>

            <!-- Tab 4: Config -->
            <div id="t_config" class="tab-content">
                <div class="action-grid">
                    <a href="plan.php" class="action-card" data-tags="setting fee">
                        <div class="ac-icon bg-blue"><i class="fas fa-dollar-sign"></i></div>
                        <div class="ac-text"><h3>Plan Settings</h3><p>Plan types & rules.</p></div>
                        <i class="fas fa-chevron-right ac-arrow"></i>
                    </a>
                    <a href="disbursement_settings.php" class="action-card" data-tags="setting loan">
                        <div class="ac-icon bg-blue"><i class="fas fa-hand-holding-usd"></i></div>
                        <div class="ac-text"><h3>Disburse Config</h3><p>Loan disbursement rules.</p></div>
                        <i class="fas fa-chevron-right ac-arrow"></i>
                    </a>
                    <a href="loan_collection_settings.php" class="action-card" data-tags="setting repay">
                        <div class="ac-icon bg-blue"><i class="fas fa-money-bill-wave"></i></div>
                        <div class="ac-text"><h3>Collection Config</h3><p>Repayment parameters.</p></div>
                        <i class="fas fa-chevron-right ac-arrow"></i>
                    </a>
                    <a href="savings_collection_settings.php" class="action-card" data-tags="setting save">
                        <div class="ac-icon bg-blue"><i class="fas fa-piggy-bank"></i></div>
                        <div class="ac-text"><h3>Savings Config</h3><p>Deposit parameters.</p></div>
                        <i class="fas fa-chevron-right ac-arrow"></i>
                    </a>
                    <a href="withdrawal_settings.php" class="action-card" data-tags="setting withdraw">
                        <div class="ac-icon bg-blue"><i class="fas fa-wallet"></i></div>
                        <div class="ac-text"><h3>Withdraw Config</h3><p>Payout parameters.</p></div>
                        <i class="fas fa-chevron-right ac-arrow"></i>
                    </a>
                    <a href="date_settings.php" class="action-card" data-tags="setting date lock control collection">
                        <div class="ac-icon bg-blue"><i class="fas fa-coins"></i></div>
                        <div class="ac-text"><h3>Date Control</h3><p>Lock/unlock date fields.</p></div>
                        <i class="fas fa-chevron-right ac-arrow"></i>
                    </a>
                    <div class="no-results" id="noRes_config"><i class="fas fa-search" style="font-size:2rem"></i> No matching controls found.</div>
                </div>
            </div>

            <!-- Tab 5: System -->
            <div id="t_system" class="tab-content">
                <div class="action-grid">
                    <a href="manage_zones_branches.php" class="action-card" data-tags="map location">
                        <div class="ac-icon bg-purple"><i class="fas fa-map-marked-alt"></i></div>
                        <div class="ac-text"><h3>Zones & Branches</h3><p>Manage office locations.</p></div>
                        <i class="fas fa-chevron-right ac-arrow"></i>
                    </a>
                    <a href="location_tracker.php" class="action-card" data-tags="gps track">
                        <div class="ac-icon bg-purple"><i class="fas fa-map-marker-alt"></i></div>
                        <div class="ac-text"><h3>Location Tracker</h3><p>Track field officers.</p></div>
                        <i class="fas fa-chevron-right ac-arrow"></i>
                    </a>
                    <a href="view_user_locations.php" class="action-card" data-tags="map visual">
                        <div class="ac-icon bg-purple"><i class="fas fa-globe"></i></div>
                        <div class="ac-text"><h3>Location Map</h3><p>Visual map dashboard.</p></div>
                        <i class="fas fa-chevron-right ac-arrow"></i>
                    </a>
                    <a href="delete_user_locations.php" class="action-card" data-tags="location delete">
                        <div class="ac-icon bg-rose"><i class="fas fa-map-marker-slash"></i></div>
                        <div class="ac-text"><h3>Delete Locations</h3><p>Purge location trails.</p></div>
                        <i class="fas fa-chevron-right ac-arrow"></i>
                    </a>
                    <a href="manage_pin.php" class="action-card" data-tags="secure pin">
                        <div class="ac-icon bg-blue"><i class="fas fa-shield-alt"></i></div>
                        <div class="ac-text"><h3>Manage PIN</h3><p>Security authorization codes.</p></div>
                        <i class="fas fa-chevron-right ac-arrow"></i>
                    </a>
                    <a href="backup_restore.php" class="action-card" data-tags="db save">
                        <div class="ac-icon bg-blue"><i class="fas fa-database"></i></div>
                        <div class="ac-text"><h3>Backup & Restore</h3><p>Database management.</p></div>
                        <i class="fas fa-chevron-right ac-arrow"></i>
                    </a>
                    <a href="migration.php" class="action-card" data-tags="sql json">
                        <div class="ac-icon bg-purple"><i class="fas fa-server"></i></div>
                        <div class="ac-text"><h3>Migration</h3><p>JSON/SQL transfers.</p></div>
                        <i class="fas fa-chevron-right ac-arrow"></i>
                    </a>
                    <a href="system_migration.php" class="action-card" data-tags="system migrate">
                        <div class="ac-icon bg-teal"><i class="fas fa-sync-alt"></i></div>
                        <div class="ac-text"><h3>System Migration</h3><p>Full system migrations.</p></div>
                        <i class="fas fa-chevron-right ac-arrow"></i>
                    </a>
                    <a href="compress_uploads.php" class="action-card" data-tags="compress space">
                        <div class="ac-icon bg-orange"><i class="fas fa-compress"></i></div>
                        <div class="ac-text"><h3>Compress Data</h3><p>Optimize upload storage.</p></div>
                        <i class="fas fa-chevron-right ac-arrow"></i>
                    </a>
                    <a href="compress_disbursement.php" class="action-card" data-tags="compress loans">
                        <div class="ac-icon bg-orange"><i class="fas fa-file-archive"></i></div>
                        <div class="ac-text"><h3>Compress Loans</h3><p>Archive loan files.</p></div>
                        <i class="fas fa-chevron-right ac-arrow"></i>
                    </a>
                    <a href="system_logs.php" class="action-card" data-tags="log audit">
                        <div class="ac-icon bg-rose"><i class="fas fa-file-alt"></i></div>
                        <div class="ac-text"><h3>System Logs</h3><p>Audit trail records.</p></div>
                        <i class="fas fa-chevron-right ac-arrow"></i>
                    </a>
                    <a href="export_hierarchy.php" class="action-card" data-tags="export structure">
                        <div class="ac-icon bg-green"><i class="fas fa-file-export"></i></div>
                        <div class="ac-text"><h3>Export Hierarchy</h3><p>Export structure tree.</p></div>
                        <i class="fas fa-chevron-right ac-arrow"></i>
                    </a>
                    <a href="transfer_hierarchy.php" class="action-card" data-tags="transfer hierarchy zone area branch">
                        <div class="ac-icon bg-orange"><i class="fas fa-exchange-alt"></i></div>
                        <div class="ac-text"><h3>Transfer Hierarchy</h3><p>Reorganize branches/areas.</p></div>
                        <i class="fas fa-chevron-right ac-arrow"></i>
                    </a>
                    <a href="link_google_accounts.php" class="action-card" data-tags="google oauth">
                        <div class="ac-icon bg-blue"><i class="fab fa-google"></i></div>
                        <div class="ac-text"><h3>Google Accounts</h3><p>Link OAuth accounts.</p></div>
                        <i class="fas fa-chevron-right ac-arrow"></i>
                    </a>
                    <a href="cleanup_unions.php" class="action-card" data-tags="clean unions">
                        <div class="ac-icon bg-orange"><i class="fas fa-broom"></i></div>
                        <div class="ac-text"><h3>Cleanup Unions</h3><p>Remove duplicate unions.</p></div>
                        <i class="fas fa-chevron-right ac-arrow"></i>
                    </a>
                    <a href="super_admin.php" class="action-card" data-tags="admin super">
                        <div class="ac-icon bg-rose"><i class="fas fa-user-shield"></i></div>
                        <div class="ac-text"><h3>Super Admin</h3><p>Advanced system controls.</p></div>
                        <i class="fas fa-chevron-right ac-arrow"></i>
                    </a>
                    <a href="../passkey_setup.php" class="action-card" data-tags="fingerprint biometric security">
                        <div class="ac-icon bg-blue"><i class="fas fa-fingerprint"></i></div>
                        <div class="ac-text"><h3>Passkey Setup</h3><p>Biometric authentication.</p></div>
                        <i class="fas fa-chevron-right ac-arrow"></i>
                    </a>
                    <a href="sync_balances.php" class="action-card" data-tags="sync balance fix">
                        <div class="ac-icon bg-teal"><i class="fas fa-sync-alt"></i></div>
                        <div class="ac-text"><h3>Sync Balances</h3><p>Recalculate balance ledger.</p></div>
                        <i class="fas fa-chevron-right ac-arrow"></i>
                    </a>
                    <div class="action-card" data-tags="offline maintenance" style="cursor:default">
                        <div class="ac-icon bg-rose"><i class="fas fa-tools"></i></div>
                        <div class="ac-text" style="flex:1"><h3>Maintenance</h3><p>Restrict user access.</p></div>
                        <label class="switch">
                            <input type="checkbox" id="maintenanceToggle" <?php echo $is_maintenance_active ? 'checked' : ''; ?>>
                            <span class="slider"></span>
                        </label>
                    </div>
                    <div class="no-results" id="noRes_system"><i class="fas fa-search" style="font-size:2rem"></i> No matching controls found.</div>
                </div>
            </div>

            <!-- Tab 6: Notifications -->
            <div id="t_notifications" class="tab-content">
                <div class="notif-wrapper">
                    <div class="card compose-card">
                        <div class="card-header">
                            <div class="card-title"><i class="fas fa-paper-plane" style="color:var(--primary)"></i> Compose Notification</div>
                        </div>
                        <form id="notifForm">
                            <div class="form-group">
                                <label class="form-label">Send To</label>
                                <div class="select-wrapper">
                                    <select name="recipient_id" id="recipient_id" required>
                                        <option value="" disabled selected>Choose recipient...</option>
                                        <optgroup label="Broadcast Groups">
                                            <option value="all_users">📢 All Users</option>
                                            <option value="all_cos">👮 All Credit Officers</option>
                                        </optgroup>
                                        <optgroup label="Individual Staff">
                                            <?php
                                            foreach ($all_users as $u) {
                                                $u_role = isset($u['role']) ? ucfirst($u['role']) : 'User';
                                                $u_name = htmlspecialchars($u['full_name'] ?? $u['username']);
                                                echo '<option value="' . htmlspecialchars($u['id']) . '">' . $u_name . ' (' . $u_role . ')</option>';
                                            }
                                            ?>
                                        </optgroup>
                                    </select>
                                    <i class="fas fa-chevron-down select-arrow"></i>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Message</label>
                                <textarea name="message" id="notif_message" rows="4" maxlength="300" placeholder="Type your notification or message here..." required></textarea>
                                <div class="char-count"><span id="charCurrent">0</span>/300</div>
                            </div>
                            <div class="quick-chips">
                                <span class="chip" onclick="setMsg('Please submit your weekly reports by 5 PM today.')">Weekly Report</span>
                                <span class="chip" onclick="setMsg('System maintenance scheduled for tonight at 11 PM.')">Maintenance</span>
                                <span class="chip" onclick="setMsg('Staff meeting started. Please join immediately.')">Meeting Alert</span>
                            </div>
                            <button type="submit" class="btn-send" id="sendBtn">
                                <span class="btn-text">Send Notification</span>
                                <i class="fas fa-paper-plane"></i>
                                <div class="spinner"></div>
                            </button>
                        </form>
                    </div>

                    <div class="card history-card">
                        <div class="card-header">
                            <div class="card-title"><i class="fas fa-history" style="color:var(--text-muted)"></i> Broadcast History</div>
                        </div>
                        <div class="notif-history-list" id="notifHistoryList">
                            <?php
                            try {
                                $stmt = $conn->query("SELECT * FROM notifications ORDER BY created_at DESC LIMIT 5");
                                $recent_notifs = $stmt->fetchAll();
                            } catch(Exception $e) { $recent_notifs = []; }

                            if (empty($recent_notifs)): ?>
                                <div class="empty-state"><i class="fas fa-inbox"></i><p>No notifications sent yet.</p></div>
                            <?php else: foreach($recent_notifs as $n): 
                                $rec_name = "Unknown";
                                $rec_target = $n['user'] ?? $n['recipient_id'] ?? 'System';
                                
                                if($rec_target === 'all_users') $rec_name = "All Users";
                                elseif($rec_target === 'all_cos') $rec_name = "All COs";
                                else {
                                     if (is_numeric($rec_target)) {
                                         foreach($all_users as $u) { if($u['id'] == $rec_target) { $rec_name = $u['full_name'] ?? $u['username']; break; } }
                                     } else {
                                         $rec_name = $rec_target;
                                     }
                                }
                            ?>
                                <div class="history-item">
                                    <div class="h-icon"><i class="fas fa-envelope"></i></div>
                                    <div class="h-content">
                                        <div class="h-top"><strong>To: <?php echo htmlspecialchars($rec_name); ?></strong><span><?php echo date('M j, H:i', strtotime($n['created_at'])); ?></span></div>
                                        <p><?php echo htmlspecialchars(substr($n['message'], 0, 50)) . (strlen($n['message'])>50?'...':''); ?></p>
                                    </div>
                                </div>
                            <?php endforeach; endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Mobile Sticky Bottom Navigation (5 Tabs) -->
    <nav class="mobile-bottom-nav">
        <a href="dashboard.php" class="nav-tab-item active">
            <i class="fas fa-home"></i>
            <span>Home</span>
        </a>
        <a href="manage_clients.php" class="nav-tab-item">
            <i class="fas fa-users"></i>
            <span>Clients</span>
        </a>
        <a href="manage_loans.php" class="nav-tab-item">
            <i class="fas fa-hand-holding-usd"></i>
            <span>Loans</span>
        </a>
        <a href="transaction_manager.php" class="nav-tab-item">
            <i class="fas fa-wallet"></i>
            <span>Finance</span>
        </a>
        <a href="backup_restore.php" class="nav-tab-item">
            <i class="fas fa-gear"></i>
            <span>System</span>
        </a>
    </nav>

    <script>
        // Side Navbar Drawer Trigger Logic
        function openSidebar() {
            document.getElementById('sidebarDrawer').classList.add('show');
            document.getElementById('sidebarBackdrop').classList.add('show');
        }
        function closeSidebar() {
            document.getElementById('sidebarDrawer').classList.remove('show');
            document.getElementById('sidebarBackdrop').classList.remove('show');
        }

        document.getElementById('logoSidebarTrigger').addEventListener('click', openSidebar);
        document.getElementById('sidebarBackdrop').addEventListener('click', closeSidebar);
        document.getElementById('sidebarCloseBtn').addEventListener('click', closeSidebar);

        // User Dropdown Logic
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

        // Tab Navigation Logic
        function openTab(tabId, btn) {
            document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            document.getElementById(tabId).classList.add('active');
            btn.classList.add('active');
        }

        // Mobile Stats Carousel Touch Indicator Dots
        const statsGrid = document.getElementById('statsGrid');
        const dotsContainer = document.getElementById('carouselDots');
        
        if (statsGrid && dotsContainer) {
            const cards = statsGrid.querySelectorAll('.stat-card');
            dotsContainer.innerHTML = '';
            cards.forEach((_, idx) => {
                const dot = document.createElement('div');
                dot.className = `carousel-dot ${idx === 0 ? 'active' : ''}`;
                dotsContainer.appendChild(dot);
            });

            statsGrid.addEventListener('scroll', () => {
                const scrollPos = statsGrid.scrollLeft;
                const cardWidth = cards[0].offsetWidth + 12;
                const activeIndex = Math.round(scrollPos / cardWidth);
                const dots = dotsContainer.querySelectorAll('.carousel-dot');
                dots.forEach((dot, idx) => {
                    dot.classList.toggle('active', idx === activeIndex);
                });
            });
        }

        // Live Search Filter for Controls
        let debounceTimer;
        function debouncedFilter() { clearTimeout(debounceTimer); debounceTimer = setTimeout(filterTabs, 250); }
        function clearSearch() { document.getElementById('actionSearch').value = ''; filterTabs(); }
        function filterTabs() {
            const input = document.getElementById('actionSearch').value.toLowerCase();
            const clearBtn = document.getElementById('searchClear');
            const tabs = ['users', 'clients', 'finance', 'config', 'system', 'notifications'];
            let foundInTab = {};
            
            clearBtn.style.display = input.length > 0 ? 'block' : 'none';
            
            tabs.forEach(tab => {
                const container = document.getElementById('t_' + tab);
                const cards = container.querySelectorAll('.action-card');
                const noRes = document.getElementById('noRes_' + tab);
                let visibleCount = 0;
                
                cards.forEach(card => {
                    const text = card.innerText.toLowerCase();
                    const tags = card.getAttribute('data-tags') || '';
                    const match = text.includes(input) || tags.includes(input);
                    if (input === '' || match) { 
                        card.style.display = 'flex'; 
                        visibleCount++; 
                        if (match && input !== '') foundInTab['t_' + tab] = true; 
                    } else { 
                        card.style.display = 'none'; 
                    }
                });
                if(noRes) noRes.style.display = (visibleCount === 0 && input !== '') ? 'flex' : 'none';
            });
            
            if (input !== '') {
                const keys = Object.keys(foundInTab);
                if (keys.length > 0) {
                    const currentTab = document.querySelector('.tab-content.active').id;
                    if (!foundInTab[currentTab]) { 
                        const btn = document.querySelector(`button[onclick="openTab('${keys[0]}', this)"]`); 
                        if(btn) btn.click(); 
                    }
                }
            }
        }

        // Recent Activity Feed with Ghost Shimmer
        let page = 1; 
        let isLoading = false; 
        let hasMore = true; 
        const feed = document.getElementById('activityFeed');
        
        loadActivities();
        
        feed.addEventListener('scroll', () => { 
            if(isLoading || !hasMore) return; 
            if(feed.scrollTop + feed.clientHeight >= feed.scrollHeight - 12) loadActivities(); 
        });

        function reloadActivities() { 
            page = 1; 
            hasMore = true; 
            feed.innerHTML = ''; 
            loadActivities(); 
        }
        
        function getGhostHTML() { 
            return `
            <div class="ghost-feed-item ghost-shimmer-wrap">
                <div class="ghost-dot"></div>
                <div style="flex:1">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 6px;">
                        <div class="ghost-line w-50" style="margin-bottom:0;"></div>
                        <div class="ghost-line w-20" style="margin-bottom:0;"></div>
                    </div>
                    <div class="ghost-line w-75"></div>
                </div>
            </div>`.repeat(4); 
        }

        async function loadActivities() {
            if(isLoading || !hasMore) return;
            isLoading = true;
            let loadingEl;
            
            if(page === 1) {
                feed.innerHTML = getGhostHTML();
            } else { 
                loadingEl = document.createElement('div'); 
                loadingEl.className = 'ghost-feed-item ghost-shimmer-wrap'; 
                loadingEl.innerHTML = `
                    <div class="ghost-dot"></div>
                    <div style="flex:1">
                        <div class="ghost-line w-50"></div>
                        <div class="ghost-line w-75"></div>
                    </div>`; 
                feed.appendChild(loadingEl); 
            }

            try {
                const res = await fetch(`?fetch_activities&page=${page}&limit=10`);
                const text = await res.text();
                let data;
                try {
                    data = JSON.parse(text);
                } catch (jsonErr) {
                    console.error("Server returned non-JSON output:", text);
                    if (page === 1) {
                        feed.innerHTML = '<div style="text-align:center; padding:1.5rem; color:var(--danger); font-weight:600;"><i class="fas fa-exclamation-triangle" style="margin-right:0.4rem;"></i>Server returned an invalid response. See console.</div>';
                    }
                    return;
                }
                
                if(page === 1) feed.innerHTML = '';
                if(loadingEl) loadingEl.remove();
                
                if(data.activities && data.activities.length > 0) {
                    data.activities.forEach(act => {
                        let dotClass = act.type === 'success' ? 'dot-success' : 
                                      (act.type === 'warning' ? 'dot-warning' : 
                                       (act.type === 'danger' ? 'dot-danger' : 
                                        (act.type === 'info' ? 'dot-info' : 'dot-muted')));
                        
                        let html = `
                            <div class="activity-item">
                                <div class="timeline-dot ${dotClass}"></div>
                                <div style="flex:1; min-width: 0;">
                                    <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 0.5rem; flex-wrap: wrap;">
                                        <div style="font-weight: 700; font-size: 0.9rem; color: var(--text-main);">
                                            <i class="${act.icon}" style="margin-right: 0.4rem; color: var(--primary);"></i>
                                            ${act.title}
                                        </div>
                                        <div style="font-size: 0.725rem; color: var(--text-muted); white-space: nowrap;">
                                            ${act.time_ago}
                                        </div>
                                    </div>
                                    <div style="font-size: 0.825rem; color: var(--text-muted); line-height: 1.4; margin-top: 0.2rem; word-break: break-word;">
                                        ${act.description}
                                    </div>
                                </div>
                            </div>
                        `;
                        feed.insertAdjacentHTML('beforeend', html);
                    });
                    page++; 
                    hasMore = data.has_more;
                } else if (page === 1) { 
                    feed.innerHTML = '<div class="empty-state"><i class="fas fa-inbox"></i><p>No recent activity found.</p></div>'; 
                }
            } catch(e) { 
                console.error("Activity load error:", e); 
                if(page === 1) feed.innerHTML = '<div style="text-align:center; padding:1.5rem; color:var(--danger); font-weight:600;">Error loading activity feed.</div>'; 
            } finally { 
                isLoading = false; 
            }
        }

        // Real-Time Polling Updates
        function startRealtimeUpdates() {
            setInterval(async () => {
                try {
                    const res = await fetch('?fetch_realtime_data=1');
                    if (!res.ok) return;
                    const data = await res.json();
                    
                    document.getElementById('rt_users').innerText = data.stats.total_users.toLocaleString();
                    document.getElementById('rt_clients').innerText = data.stats.total_clients.toLocaleString();
                    document.getElementById('rt_branches').innerText = data.stats.total_branches;
                    document.getElementById('rt_sessions').innerText = data.stats.active_sessions;
                    document.getElementById('rt_health').innerText = data.stats.system_health;
                    
                    document.getElementById('rt_pending').innerText = data.stats.pending_registrations;
                    const pText = document.getElementById('rt_pending_text');
                    if(pText) pText.innerText = data.stats.pending_registrations;
                    
                    document.getElementById('rt_active_loans').innerText = data.stats.active_loans.toLocaleString();
                    document.getElementById('rt_revenue').innerText = data.stats.daily_transactions;
                    document.getElementById('rt_revenue_today').innerText = '₦' + data.stats.revenue_today.toLocaleString();
                    
                    const mToggle = document.getElementById('maintenanceToggle');
                    const mStatus = document.getElementById('mStatus');
                    if (mToggle && mToggle.checked !== data.maintenance) {
                        mToggle.checked = data.maintenance;
                        mStatus.innerText = data.maintenance ? 'ON' : 'OFF';
                    }

                    if (revenueChart) {
                        const currentData = JSON.stringify(revenueChart.data.datasets[0].data);
                        const newData = JSON.stringify(Object.values(data.chart));
                        if(currentData !== newData) {
                            revenueChart.data.datasets[0].data = Object.values(data.chart);
                            revenueChart.update();
                        }
                    }
                } catch (e) { console.error("Realtime update error:", e); }
            }, 6000);
        }
        startRealtimeUpdates();

        // Maintenance Mode Toggle
        document.getElementById('maintenanceToggle').addEventListener('change', async function() {
            try {
                const res = await fetch('toggle_maintenance.php', { 
                    method: 'POST', 
                    headers: {'Content-Type':'application/json'}, 
                    body: JSON.stringify({maintenance: this.checked}) 
                });
                const data = await res.json();
                if(data.success) {
                    showToast(this.checked ? 'Maintenance Mode Enabled' : 'Maintenance Mode Disabled', 'success');
                    document.getElementById('mStatus').textContent = this.checked ? 'ON' : 'OFF';
                } else { 
                    this.checked = !this.checked; 
                    showToast('Error: ' + (data.message || 'Action failed'), 'error'); 
                }
            } catch(e) { 
                this.checked = !this.checked; 
                showToast('Network connection failed', 'error'); 
            }
        });

        // Toast Helper
        function showToast(msg, type = 'info') {
            const div = document.createElement('div'); 
            div.className = `toast ${type}`;
            let icon = type === 'success' ? 'check-circle' : (type === 'error' ? 'exclamation-triangle' : 'info-circle');
            div.innerHTML = `<i class="fas fa-${icon}"></i> ${msg}`;
            document.getElementById('toastContainer').appendChild(div);
            setTimeout(() => { div.style.opacity = '0'; div.style.transition = 'opacity 0.3s'; setTimeout(() => div.remove(), 300); }, 4000);
        }

        // Notification Sender Handler
        const msgInput = document.getElementById('notif_message');
        const charCount = document.getElementById('charCurrent');
        if(msgInput) { 
            msgInput.addEventListener('input', function() { 
                charCount.textContent = this.value.length; 
            }); 
        }
        
        function setMsg(text) { 
            if(msgInput) { 
                msgInput.value = text; 
                charCount.textContent = text.length; 
                msgInput.focus(); 
            } 
        }
        
        const notifForm = document.getElementById('notifForm');
        if(notifForm) {
            notifForm.addEventListener('submit', async function(e) {
                e.preventDefault();
                const btn = document.getElementById('sendBtn');
                const recipient = document.getElementById('recipient_id').value;
                const message = document.getElementById('notif_message').value;
                
                btn.classList.add('loading'); 
                btn.disabled = true;
                
                try {
                    const res = await fetch('send_notification.php', { 
                        method: 'POST', 
                        headers: { 'Content-Type': 'application/json' }, 
                        body: JSON.stringify({ recipient_id: recipient, message: message }) 
                    });
                    const data = await res.json();
                    
                    if(data.success) {
                        showToast(data.message, 'success');
                        notifForm.reset(); 
                        charCount.textContent = '0';
                        if(data.new_log) addToHistory(data.new_log);
                    } else { 
                        showToast(data.message || 'Failed to dispatch message.', 'error'); 
                    }
                } catch(error) { 
                    showToast('Failed to connect to notification service.', 'error'); 
                } finally { 
                    btn.classList.remove('loading'); 
                    btn.disabled = false; 
                }
            });
        }

        function addToHistory(log) {
            const list = document.getElementById('notifHistoryList');
            const emptyState = list.querySelector('.empty-state');
            if(emptyState) emptyState.remove();
            
            const html = `
                <div class="history-item">
                    <div class="h-icon"><i class="fas fa-check-circle" style="color:var(--success)"></i></div>
                    <div class="h-content">
                        <div class="h-top"><strong>To: ${log.target}</strong><span>Just now</span></div>
                        <p>${log.message}</p>
                    </div>
                </div>`;
            list.insertAdjacentHTML('afterbegin', html);
            if(list.children.length > 5) list.lastElementChild.remove();
        }

        // Theme Switcher & Chart Initializer
        document.getElementById('themeToggle').addEventListener('click', () => {
            const html = document.documentElement;
            const isDark = html.classList.toggle('dark');
            html.classList.toggle('light');
            localStorage.setItem('theme', isDark ? 'dark' : 'light');
            document.querySelector('#themeToggle i').className = isDark ? 'fas fa-sun' : 'fas fa-moon';
            initChart();
        });

        if(localStorage.getItem('theme') === 'dark') {
            document.documentElement.classList.add('dark'); 
            document.documentElement.classList.remove('light');
            document.querySelector('#themeToggle i').className = 'fas fa-sun';
        }

        let revenueChart;
        function initChart() {
            const ctxCanvas = document.getElementById('revenueChart');
            if(!ctxCanvas) return;
            const ctx = ctxCanvas.getContext('2d');
            if(revenueChart) revenueChart.destroy();
            
            const isDark = document.documentElement.classList.contains('dark');
            const textColor = isDark ? '#94a3b8' : '#64748b';
            const gridColor = isDark ? '#1e293b' : '#e2e8f0';
            
            let gradient = ctx.createLinearGradient(0, 0, 0, 320);
            gradient.addColorStop(0, 'rgba(37, 99, 235, 0.45)'); 
            gradient.addColorStop(1, 'rgba(37, 99, 235, 0.0)');
            
            revenueChart = new Chart(ctxCanvas, {
                type: 'line',
                data: {
                    labels: ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'],
                    datasets: [{ 
                        label: 'Revenue (₦)', 
                        data: <?php echo json_encode(array_values($monthly_revenue)); ?>, 
                        borderColor: '#2563eb', 
                        backgroundColor: gradient, 
                        borderWidth: 3, 
                        pointBackgroundColor: '#ffffff', 
                        pointBorderColor: '#2563eb', 
                        pointBorderWidth: 2,
                        pointRadius: 4, 
                        pointHoverRadius: 7, 
                        fill: true, 
                        tension: 0.35 
                    }]
                },
                options: {
                    responsive: true, 
                    maintainAspectRatio: false,
                    plugins: { 
                        legend: { display: false }, 
                        tooltip: { 
                            backgroundColor: isDark ? '#1e293b' : '#ffffff', 
                            titleColor: isDark ? '#f8fafc' : '#0f172a', 
                            bodyColor: isDark ? '#94a3b8' : '#64748b', 
                            borderColor: isDark ? '#334155' : '#e2e8f0', 
                            borderWidth: 1, 
                            padding: 12, 
                            displayColors: false, 
                            callbacks: { 
                                label: function(context) { 
                                    return 'Revenue: ₦' + context.parsed.y.toLocaleString(); 
                                } 
                            } 
                        } 
                    },
                    scales: { 
                        x: { 
                            ticks: { color: textColor, font: { family: 'Plus Jakarta Sans', weight: '600' } }, 
                            grid: { display: false } 
                        }, 
                        y: { 
                            ticks: { color: textColor, font: { family: 'Plus Jakarta Sans' } }, 
                            grid: { color: gridColor, borderDash: [4, 4], drawBorder: false }, 
                            beginAtZero: true 
                        } 
                    }
                }
            });
        }
        window.onload = initChart;
    </script>
</body>
</html>