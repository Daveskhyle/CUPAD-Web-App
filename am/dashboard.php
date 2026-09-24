<?php
// am/dashboard.php - Area Manager Dashboard
session_start();
require_once '../includes/config.php';
$pdo = getDbConnection();

// Ensure User is Logged In and is an Area Manager (am)
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'am') {
    if (isset($_GET['action'])) {
        http_response_code(403);
        echo json_encode(['error' => 'Unauthorized']);
        exit();
    }
    header('Location: ../index.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username']; 

// Fetch the AM's area_id
$stmt_a = $pdo->prepare("SELECT area_id FROM users WHERE id = :uid");
$stmt_a->execute(['uid' => $user_id]);
$area_id = $stmt_a->fetchColumn();

// --- 1. AJAX HANDLERS ---
if (isset($_GET['action'])) {
    $action = $_GET['action'];
    
    switch($action) {
        case 'load_activities':
            handleLoadActivities($pdo, $area_id);
            break;
        case 'search_activities':
            handleSearchActivities($pdo, $area_id);
            break;
        case 'fetch_notifications':
            handleFetchNotifications($pdo, $username);
            break;
        case 'mark_notifications_read':
            handleMarkNotificationsRead($pdo, $username);
            break;
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
            exit();
    }
}

// --- AJAX FUNCTION IMPLEMENTATIONS ---

function handleFetchNotifications($pdo, $username) {
    $show_read = isset($_GET['show_read']) && $_GET['show_read'] === 'true';
    $sql = "SELECT id, message, is_read as 'read', created_at as timestamp 
            FROM notifications 
            WHERE user = :uname";
            
    if (!$show_read) {
        $sql .= " AND is_read = 0";
    }
    
    $sql .= " ORDER BY created_at DESC LIMIT 20";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['uname' => $username]);
    $notifications = $stmt->fetchAll();

    header('Content-Type: application/json');
    echo json_encode($notifications);
    exit();
}

function handleMarkNotificationsRead($pdo, $username) {
    $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user = :uname AND is_read = 0");
    $success = $stmt->execute(['uname' => $username]);

    header('Content-Type: application/json');
    echo json_encode(['success' => $success]);
    exit();
}

function handleLoadActivities($pdo, $area_id) {
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
    $offset = ($page - 1) * $limit;
    $filter = $_GET['filter'] ?? 'all';

    // Get all CO usernames in this area (all branches under this area)
    $stmt_cos = $pdo->prepare("SELECT username FROM users WHERE branch_id IN (SELECT id FROM branches WHERE area_id = :aid) AND role = 'co' AND status = 'active'");
    $stmt_cos->execute(['aid' => $area_id]);
    $co_usernames = $stmt_cos->fetchAll(PDO::FETCH_COLUMN);
    
    if (empty($co_usernames)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'html' => '', 'has_more' => false, 'current_page' => $page]);
        exit();
    }
    
    $placeholders = implode(',', array_fill(0, count($co_usernames), '?'));

    $sql = "
        SELECT type, client_name, amount, date, officer FROM (
            SELECT 
                CASE 
                    WHEN s.type IN ('withdrawal', 'return') OR s.amount < 0 THEN 'Withdrawal'
                    ELSE 'Saving'
                END as type,
                c.name as client_name, 
                ABS(s.amount) as amount, 
                s.date, 
                s.officer,
                s.id as activity_id
            FROM saving_collections s 
            JOIN clients c ON s.client_id = c.id 
            WHERE s.officer IN ($placeholders)
            
            UNION ALL
            
            SELECT 'Payment' as type, c.name as client_name, p.amount_collected as amount, p.date, p.officer, p.id as activity_id 
            FROM loan_collections p 
            JOIN clients c ON p.client_id = c.id 
            WHERE p.officer IN ($placeholders)
            
            UNION ALL
            
            SELECT 'Disbursement' as type, c.name as client_name, d.principal as amount, d.created_at as date, d.officer, d.id as activity_id 
            FROM disbursements d 
            JOIN clients c ON d.client_id = c.id 
            WHERE d.officer IN ($placeholders)

            UNION ALL

            SELECT 'Registration' as type, r.client_name, r.amount, r.date, r.officer, r.id as activity_id
            FROM registrations r 
            WHERE r.officer IN ($placeholders)
        ) as activities
    ";
    
    $params = array_merge($co_usernames, $co_usernames, $co_usernames, $co_usernames);
    $where_clause = "";

    if ($filter !== 'all') {
        $filter_map =[
            'saving' => 'Saving',
            'withdrawal' => 'Withdrawal',
            'payment' => 'Payment',
            'disbursement' => 'Disbursement',
            'registration' => 'Registration',
        ];

        if (array_key_exists($filter, $filter_map)) {
            $where_clause = " WHERE type = ?";
            $params[] = $filter_map[$filter];
        }
    }
    
    $sql .= $where_clause;
    $sql .= " ORDER BY date DESC, activity_id DESC LIMIT ? OFFSET ?";

    $params[] = $limit;
    $params[] = $offset;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    
    $activities = $stmt->fetchAll();
    $has_more = count($activities) >= $limit;

    $activities_html = '';
    foreach ($activities as $index => $activity) {
        $activity_type_class = strtolower($activity['type']);
        $icon = getActivityIcon($activity['type']);
        $color = getActivityColor($activity['type']);
        $activities_html .= generateActivityHTML($activity, $activity_type_class, $icon, $color, $index);
    }
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'html' => $activities_html,
        'has_more' => $has_more,
        'current_page' => $page
    ]);
    exit();
}

function handleSearchActivities($pdo, $area_id) {
    $query = $_GET['q'] ?? '';
    $searchTerm = "%$query%";
    $filter = $_GET['filter'] ?? 'all';

    // Get all CO usernames in this area
    $stmt_cos = $pdo->prepare("SELECT username FROM users WHERE branch_id IN (SELECT id FROM branches WHERE area_id = :aid) AND role = 'co' AND status = 'active'");
    $stmt_cos->execute(['aid' => $area_id]);
    $co_usernames = $stmt_cos->fetchAll(PDO::FETCH_COLUMN);
    
    if (empty($co_usernames)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'html' => '']);
        exit();
    }
    
    $placeholders = implode(',', array_fill(0, count($co_usernames), '?'));

    $sql = "
        SELECT type, client_name, amount, date, officer FROM (
            SELECT 
                CASE 
                    WHEN s.type IN ('withdrawal', 'return') OR s.amount < 0 THEN 'Withdrawal'
                    ELSE 'Saving'
                END as type,
                c.name as client_name, 
                ABS(s.amount) as amount, 
                s.date, 
                s.officer,
                s.id as activity_id
            FROM saving_collections s 
            JOIN clients c ON s.client_id = c.id 
            WHERE s.officer IN ($placeholders)
            
            UNION ALL
            
            SELECT 'Payment' as type, c.name as client_name, p.amount_collected as amount, p.date, p.officer, p.id as activity_id
            FROM loan_collections p 
            JOIN clients c ON p.client_id = c.id 
            WHERE p.officer IN ($placeholders)
            
            UNION ALL
            
            SELECT 'Disbursement' as type, c.name as client_name, d.principal as amount, d.created_at as date, d.officer, d.id as activity_id
            FROM disbursements d 
            JOIN clients c ON d.client_id = c.id 
            WHERE d.officer IN ($placeholders)

            UNION ALL

            SELECT 'Registration' as type, r.client_name, r.amount, r.date, r.officer, r.id as activity_id
            FROM registrations r 
            WHERE r.officer IN ($placeholders)
        ) as activities
        WHERE (client_name LIKE ? OR type LIKE ? OR officer LIKE ?)
    ";

    $params = array_merge($co_usernames, $co_usernames, $co_usernames, $co_usernames, [$searchTerm, $searchTerm, $searchTerm]);

    if ($filter !== 'all') {
        $filter_map =[
            'saving' => 'Saving',
            'withdrawal' => 'Withdrawal',
            'payment' => 'Payment',
            'disbursement' => 'Disbursement',
            'registration' => 'Registration',
        ];

        if (array_key_exists($filter, $filter_map)) {
            $sql .= " AND type = ?";
            $params[] = $filter_map[$filter];
        }
    }
    
    $sql .= " ORDER BY date DESC, activity_id DESC LIMIT 20";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $activities = $stmt->fetchAll();
    
    $activities_html = '';
    foreach ($activities as $index => $activity) {
        $activity_type_class = strtolower($activity['type']);
        $icon = getActivityIcon($activity['type']);
        $color = getActivityColor($activity['type']);
        $activities_html .= generateActivityHTML($activity, $activity_type_class, $icon, $color, $index);
    }
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'html' => $activities_html]);
    exit();
}

// --- 2. HELPER FUNCTIONS ---
function getActivityIcon($type) {
    $icons =[
        'Saving' => 'fa-piggy-bank',
        'Withdrawal' => 'fa-wallet',
        'Payment' => 'fa-money-bill-wave',
        'Disbursement' => 'fa-hand-holding-usd',
        'Registration' => 'fa-user-plus',
        'default' => 'fa-dollar-sign'
    ];
    return $icons[$type] ?? $icons['default'];
}

function getActivityColor($type) {
    $colors =[
        'Saving' => '#22c55e',       
        'Withdrawal' => '#ef4444',   
        'Payment' => '#8b5cf6',      
        'Disbursement' => '#3b82f6', 
        'Registration' => '#6366f1', 
        'default' => '#6b7280'
    ];
    return $colors[$type] ?? $colors['default'];
}

function generateActivityHTML($activity, $activity_type_class, $icon, $color, $index) {
    $client_display_name = htmlspecialchars($activity['client_name'] ?? 'N/A');
    $amount_display = '₦' . number_format($activity['amount']);
    $officer_name = htmlspecialchars($activity['officer'] ?? 'System');
    
    $formatted_date = '';
    if (!empty($activity['date'])) {
        $timestamp = strtotime($activity['date']);
        $formatted_date = ($timestamp !== false) ? date('M d, h:i A', $timestamp) : htmlspecialchars($activity['date']);
    }
    
    $badge_style = "background: " . $color . "15; color: " . $color . ";";
    $icon_bg = "background: " . $color . "15; color: " . $color . ";";

    return '
    <div class="activity-card" data-type="' . $activity_type_class . '" role="listitem">
        <div class="ac-icon-box" style="' . $icon_bg . '">
            <i class="fas ' . $icon . '"></i>
        </div>
        <div class="ac-content">
            <div class="ac-top">
                <div class="client-name">' . $client_display_name . '</div>
                <div class="activity-amount" style="color: ' . $color . ';">' . $amount_display . '</div>
            </div>
            <div class="ac-bottom">
                <span class="type-badge" style="' . $badge_style . '">' . htmlspecialchars($activity['type']) . '</span>
                <div class="activity-date"><i class="fas fa-user-tie"></i> ' . $officer_name . ' &bull; <i class="far fa-clock"></i> ' . $formatted_date . '</div>
            </div>
        </div>
    </div>';
}

// --- 3. MAIN PAGE DATA FETCHING ---
$base_path = '../';

// Initialize Variables
$full_name = $_SESSION['name'] ?? 'Area Manager';
$profile_pic = 'default_avatar.png';
$area_name   = 'Unassigned';
$zone_name   = 'Unassigned';

// 1. Fetch User Details & Location Names
$sql_user = "
    SELECT u.full_name, u.profile_pic, 
           a.name as area_name, z.name as zone_name
    FROM users u
    LEFT JOIN areas a ON u.area_id = a.id
    LEFT JOIN zones z ON u.zone_id = z.id
    WHERE u.id = :uid
";
$stmt_u = $pdo->prepare($sql_user);
$stmt_u->execute(['uid' => $user_id]);
$user_data = $stmt_u->fetch();

if ($user_data) {
    $full_name = !empty($user_data['full_name']) ? $user_data['full_name'] : $full_name;
    $profile_pic = $user_data['profile_pic'];
    if (!empty($user_data['area_name'])) $area_name = $user_data['area_name'];
    if (!empty($user_data['zone_name'])) $zone_name = $user_data['zone_name'];
}

// --- STATS CALCULATION (Area Level) ---
// Use Month-to-Date logic to align with analytics.php
$start_of_month = date('Y-m-01');
$today = date('Y-m-d');

// 2. Area Monthly Net Savings
$sql_net_savings = "
    SELECT COALESCE(SUM(CASE WHEN amount < 0 OR LOWER(s.type) IN ('withdrawal', 'return', 'adjust') THEN -ABS(amount) ELSE amount END), 0) 
    FROM saving_collections s 
    JOIN clients c ON s.client_id = c.id
    WHERE c.branch_id IN (SELECT id FROM branches WHERE area_id = :aid) AND CAST(s.date AS DATE) BETWEEN :start_date AND :end_date";
$stmt_ns = $pdo->prepare($sql_net_savings);
$stmt_ns->execute(['aid' => $area_id, 'start_date' => $start_of_month, 'end_date' => $today]);
$area_monthly_savings = $stmt_ns->fetchColumn();

// 3. Area Monthly Disbursement
$sql_disb = "
    SELECT COALESCE(SUM(principal), 0) 
    FROM disbursements d
    JOIN clients c ON d.client_id = c.id
    WHERE c.branch_id IN (SELECT id FROM branches WHERE area_id = :aid) AND d.date BETWEEN :start_date AND :end_date";
$stmt_d = $pdo->prepare($sql_disb);
$stmt_d->execute(['aid' => $area_id, 'start_date' => $start_of_month, 'end_date' => $today]);
$area_monthly_disbursed = $stmt_d->fetchColumn();

// 4. Area Active Loans Count & Outstanding Balance
$sql_active = "
    SELECT COUNT(*) as loan_count, COALESCE(SUM(d.remaining_balance), 0) as total_outstanding
    FROM disbursements d
    JOIN clients c ON d.client_id = c.id
    WHERE c.branch_id IN (SELECT id FROM branches WHERE area_id = :aid) AND d.remaining_balance > 0";
$stmt_a = $pdo->prepare($sql_active);
$stmt_a->execute(['aid' => $area_id]);
$active_data = $stmt_a->fetch();

$area_active_loans_count = $active_data['loan_count'] ?? 0;
$area_outstanding_balance = $active_data['total_outstanding'] ?? 0;

// 5. Total Area Active Clients
$sql_clients = "
    SELECT COUNT(*) FROM clients 
    WHERE branch_id IN (SELECT id FROM branches WHERE area_id = :aid) AND status = 'active'";
$stmt_c = $pdo->prepare($sql_clients);
$stmt_c->execute(['aid' => $area_id]);
$area_total_clients = $stmt_c->fetchColumn();

// 6. Branch Performance (Updated to mirror analytics.php logic)
//    FIX: Use unique placeholders for each date range to avoid PDO driver issues.
$sql_branch_perf = "
    SELECT 
        b.id, 
        b.name as branch_name,
        (SELECT COUNT(*) FROM clients c WHERE c.branch_id = b.id AND c.status = 'active') as active_clients,
        
        -- Monthly Deposits (analytic-style)
        (SELECT COALESCE(SUM(s.amount), 0)
         FROM saving_collections s JOIN clients c2 ON s.client_id = c2.id 
         WHERE c2.branch_id = b.id AND CAST(s.date AS DATE) BETWEEN :start_date1 AND :end_date1
           AND s.amount > 0 AND LOWER(s.type) NOT IN ('withdrawal', 'return', 'adjust')) as monthly_deposits,
        
        -- Monthly Withdrawals (analytic-style)
        (SELECT COALESCE(SUM(ABS(s.amount)), 0)
         FROM saving_collections s JOIN clients c3 ON s.client_id = c3.id 
         WHERE c3.branch_id = b.id AND CAST(s.date AS DATE) BETWEEN :start_date2 AND :end_date2
           AND (s.amount < 0 OR LOWER(s.type) IN ('withdrawal', 'return', 'adjust'))) as monthly_withdrawals,
        
        -- Monthly Collections
        (SELECT COALESCE(SUM(lc.amount_collected), 0) 
         FROM loan_collections lc JOIN clients c4 ON lc.client_id = c4.id 
         WHERE c4.branch_id = b.id AND CAST(lc.date AS DATE) BETWEEN :start_date3 AND :end_date3) as monthly_collections,
        
        -- Total Outstanding Portfolio
        (SELECT COALESCE(SUM(d.remaining_balance), 0) 
         FROM disbursements d JOIN clients c5 ON d.client_id = c5.id 
         WHERE c5.branch_id = b.id AND d.remaining_balance > 0) as outstanding_portfolio
         
    FROM branches b
    WHERE b.area_id = :aid AND b.status = 'active'
    ORDER BY monthly_collections DESC
";
$stmt_bp = $pdo->prepare($sql_branch_perf);
$stmt_bp->execute([
    'aid' => $area_id, 
    'start_date1' => $start_of_month, 'end_date1' => $today,
    'start_date2' => $start_of_month, 'end_date2' => $today,
    'start_date3' => $start_of_month, 'end_date3' => $today
]);
$branch_performances = $stmt_bp->fetchAll();


// 7. Initial Activity Load
$stmt_cos = $pdo->prepare("SELECT username FROM users WHERE branch_id IN (SELECT id FROM branches WHERE area_id = :aid) AND role = 'co' AND status = 'active'");
$stmt_cos->execute(['aid' => $area_id]);
$co_usernames = $stmt_cos->fetchAll(PDO::FETCH_COLUMN);

$initial_activities =[];
$has_more = false;
$initial_limit = 10;

if (!empty($co_usernames)) {
    $placeholders = implode(',', array_fill(0, count($co_usernames), '?'));

    $sql_init_act = "
        SELECT type, client_name, amount, date, officer FROM (
            SELECT 
                CASE 
                    WHEN s.type IN ('withdrawal', 'return') OR s.amount < 0 THEN 'Withdrawal'
                    ELSE 'Saving'
                END as type,
                c.name as client_name, 
                ABS(s.amount) as amount, 
                s.date, 
                s.officer,
                s.id as activity_id
            FROM saving_collections s 
            JOIN clients c ON s.client_id = c.id 
            WHERE s.officer IN ($placeholders)
            
            UNION ALL
            
            SELECT 'Payment' as type, c.name as client_name, p.amount_collected as amount, p.date, p.officer, p.id as activity_id
            FROM loan_collections p 
            JOIN clients c ON p.client_id = c.id 
            WHERE p.officer IN ($placeholders)
            
            UNION ALL
            
            SELECT 'Disbursement' as type, c.name as client_name, d.principal as amount, d.created_at as date, d.officer, d.id as activity_id
            FROM disbursements d 
            JOIN clients c ON d.client_id = c.id 
            WHERE d.officer IN ($placeholders)

            UNION ALL

            SELECT 'Registration' as type, r.client_name, r.amount, r.date, r.officer, r.id as activity_id
            FROM registrations r 
            WHERE r.officer IN ($placeholders)
        ) as activities
        ORDER BY date DESC, activity_id DESC
        LIMIT ?
    ";
    
    $stmt_ia = $pdo->prepare($sql_init_act);
    $params = array_merge($co_usernames, $co_usernames, $co_usernames, $co_usernames, [$initial_limit]);
    $stmt_ia->execute($params);
    $initial_activities = $stmt_ia->fetchAll();
    
    $has_more = count($initial_activities) >= $initial_limit;
}

// Greeting
$h = date('H');
$greeting = $h < 12 ? "Good Morning" : ($h < 18 ? "Good Afternoon" : "Good Evening");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>Area Manager Dashboard | CUPAD</title>
    
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">    
    <script src="https://cdn.tailwindcss.com"></script>

    <script>
    tailwind.config = {
        darkMode: 'class',
        theme: {
            extend: {
                colors: {
                    primary: '#3b82f6',
                    secondary: '#8b5cf6',
                    success: '#22c55e',
                    warning: '#f59e0b',
                    error: '#ef4444',
                    bg: { light: '#f0f2f5', dark: '#0f172a' }
                }
            }
        }
    }
    </script>
    <style>
        :root {
            --primary-color: #3b82f6; --secondary-color: #8b5cf6;
            --success-color: #22c55e; --warning-color: #f59e0b; --error-color: #ef4444;
            --bg-primary: #f0f2f5; --bg-secondary: #ffffff; --bg-card: #ffffff;
            --bg-header: rgba(255, 255, 255, 0.95);
            --text-primary: #1f2937; --text-secondary: #6b7280; --border-color: #e5e7eb;
            --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
            --border-radius-lg: 1rem; --border-radius-xl: 1.5rem;
        }
        html.dark {
            --bg-primary: #0f172a; --bg-secondary: #1e293b; --bg-card: #1e293b;
            --bg-header: rgba(15, 23, 42, 0.95);
            --text-primary: #f1f5f9; --text-secondary: #94a3b8; --border-color: rgba(255, 255, 255, 0.08);
        }
        body { font-family: 'Inter', sans-serif; background-color: var(--bg-primary); color: var(--text-primary); padding-bottom: 90px; -webkit-tap-highlight-color: transparent; }
        .main-header { background: var(--bg-header); backdrop-filter: blur(12px); border-bottom: 1px solid var(--border-color); position: sticky; top: 0; z-index: 50; padding-top: env(safe-area-inset-top); }
        .navbar { display: flex; justify-content: space-between; align-items: center; padding: 0.8rem 1rem; max-width: 1280px; margin: 0 auto; }
        .logo { font-weight: 700; font-size: 1.25rem; color: var(--primary-color); display: flex; align-items: center; gap: 0.5rem; text-decoration: none; }
        .logo img { height: 32px; width: auto; }
        .dashboard-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1rem; margin-bottom: 1.5rem; }
        .dashboard-card { border-radius: var(--border-radius-xl); padding: 1.5rem; position: relative; overflow: hidden; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1); border: none; color: white; transition: transform 0.2s ease; }
        .dashboard-card:active { transform: scale(0.98); }
        .card-gradient-blue { background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); }
        .card-gradient-green { background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%); }
        .card-gradient-orange { background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); }
        .card-gradient-purple { background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%); }
        .card-bg-icon { position: absolute; right: -10px; bottom: -20px; font-size: 6rem; color: white; opacity: 0.1; transform: rotate(-10deg); pointer-events: none; }
        .quick-links-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(100px, 1fr)); gap: 1rem; margin-bottom: 2rem; }
        .quick-link-card { background: var(--bg-card); border-radius: var(--border-radius-lg); padding: 1rem; display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; gap: 0.5rem; text-decoration: none; color: inherit; box-shadow: var(--shadow-sm); border: 1px solid var(--border-color); }
        .quick-link-icon { width: 44px; height: 44px; border-radius: 12px; display: flex; align-items: center; justify-content: center; color: white; font-size: 1.1rem; flex-shrink: 0; }
        .quick-link-label { font-size: 0.8rem; font-weight: 600; }
        .co-scroll-container { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 1.25rem; margin-bottom: 2rem; }
        .co-card { background: var(--bg-card); border-radius: var(--border-radius-lg); padding: 1.25rem; border: 1px solid var(--border-color); box-shadow: var(--shadow-sm); position: relative; }
        .co-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem; border-bottom: 1px solid var(--border-color); padding-bottom: 0.75rem; }
        .co-name { font-weight: 700; font-size: 1rem; color: var(--text-primary); }
        .co-count { font-size: 0.75rem; background: var(--bg-primary); padding: 3px 10px; border-radius: 12px; color: var(--text-secondary); }
        .co-stat-row { display: flex; justify-content: space-between; font-size: 0.85rem; margin-top: 0.5rem; }
        .activities-container { background: var(--bg-card); border-radius: var(--border-radius-xl); padding: 1.25rem; box-shadow: var(--shadow-sm); }
        .activity-card { display: flex; align-items: center; background: var(--bg-secondary); padding: 1rem; margin-bottom: 0.75rem; border-radius: var(--border-radius-lg); border: 1px solid var(--border-color); }
        .ac-icon-box { width: 44px; height: 44px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; margin-right: 0.8rem; flex-shrink: 0; }
        .ac-content { flex: 1; display: flex; flex-direction: column; gap: 0.2rem; }
        .ac-top { display: flex; justify-content: space-between; align-items: center; }
        .ac-bottom { display: flex; justify-content: space-between; align-items: center; margin-top: 2px; }
        .client-name { font-weight: 600; font-size: 0.95rem; color: var(--text-primary); }
        .activity-amount { font-weight: 700; font-size: 1rem; }
        .type-badge { font-size: 0.65rem; font-weight: 700; text-transform: uppercase; padding: 0.15rem 0.5rem; border-radius: 6px; }
        .activity-date { font-size: 0.7rem; color: var(--text-secondary); }
        .search-filter-container { background: var(--bg-card); border-radius: var(--border-radius-xl); padding: 1rem; margin-bottom: 1.5rem; }
        .search-row { display: flex; gap: 0.5rem; margin-bottom: 1rem; }
        .search-input { width: 100%; padding: 0.75rem 1rem 0.75rem 3rem; border: 1px solid var(--border-color); border-radius: 0.75rem; background: var(--bg-primary); color: var(--text-primary); font-size: 16px; }
        .filter-tabs { display: flex; gap: 0.5rem; overflow-x: auto; padding-bottom: 5px; scrollbar-width: none; -ms-overflow-style: none; }
        .filter-tabs::-webkit-scrollbar { display: none; }
        .filter-tab { white-space: nowrap; padding: 0.5rem 1rem; border-radius: 2rem; background: var(--bg-primary); color: var(--text-secondary); border: 1px solid transparent; font-weight: 600; font-size: 0.85rem; }
        .filter-tab.active { background: var(--primary-color); color: white; }
        #notification-dropdown { width: 360px; transform-origin: top right; transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1); border-radius: 16px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04); overflow: hidden; border: 1px solid var(--border-color); }
        #notification-dropdown.hidden { display: none; opacity: 0; transform: scale(0.95); }
        #notification-dropdown:not(.hidden) { display: block; opacity: 1; transform: scale(1); }
        .notification-item { padding: 12px 16px; display: flex; gap: 12px; align-items: flex-start; border-bottom: 1px solid var(--border-color); cursor: pointer; transition: all 0.2s ease; position: relative; }
        .notification-item:hover { background-color: var(--bg-primary); }
        .notification-item.unread { background-color: rgba(59, 130, 246, 0.05); }
        .notification-item:last-child { border-bottom: none; }
        .notif-icon { width: 32px; height: 32px; border-radius: 50%; background: var(--bg-primary); display: flex; align-items: center; justify-content: center; color: var(--primary-color); flex-shrink: 0; font-size: 0.85rem; margin-top: 2px; border: 1px solid var(--border-color); }
        .notification-item.unread .notif-icon { background: var(--primary-color); color: white; border-color: transparent; }
        .notif-content { flex: 1; }
        .notif-message { font-size: 0.85rem; line-height: 1.4; color: var(--text-primary); margin-bottom: 4px; }
        .notification-item.unread .notif-message { font-weight: 500; }
        .notification-item.read .notif-message { color: var(--text-secondary); }
        .notif-time { font-size: 0.7rem; color: var(--text-secondary); display: flex; align-items: center; gap: 4px; }
        .notif-dot { width: 8px; height: 8px; border-radius: 50%; background-color: var(--error-color); margin-top: 8px; flex-shrink: 0; box-shadow: 0 0 0 2px var(--bg-secondary); }
        #notification-list::-webkit-scrollbar { width: 4px; }
        #notification-list::-webkit-scrollbar-track { background: transparent; }
        #notification-list::-webkit-scrollbar-thumb { background-color: var(--text-secondary); border-radius: 20px; opacity: 0.3; }
        .profile-btn { width: 36px; height: 36px; border-radius: 50%; overflow: hidden; border: 2px solid var(--primary-color); background: var(--bg-secondary); cursor: pointer; display: flex; align-items: center; justify-content: center; position: relative; }
        .profile-btn img { width: 100%; height: 100%; object-fit: cover; }
        .profile-fallback-icon { font-size: 1.2rem; color: var(--text-secondary); }
        .mobile-bottom-nav { display: none; position: fixed; bottom: 0; left: 0; right: 0; background: var(--bg-secondary); border-top: 1px solid var(--border-color); padding: 0.5rem 1rem calc(0.5rem + env(safe-area-inset-bottom)); z-index: 100; justify-content: space-between; box-shadow: 0 -4px 10px rgba(0,0,0,0.05); }
        .nav-item { display: flex; flex-direction: column; align-items: center; justify-content: center; text-decoration: none; color: var(--text-secondary); font-size: 0.7rem; font-weight: 500; gap: 4px; flex: 1; }
        .nav-item i { font-size: 1.4rem; }
        .nav-item.active { color: var(--primary-color); }
        @media (max-width: 768px) {
            .mobile-bottom-nav { display: flex; }
            .dashboard-grid { display: flex; overflow-x: auto; scroll-snap-type: x mandatory; gap: 1rem; padding-bottom: 0.5rem; margin-right: -1rem; padding-right: 1.5rem; scrollbar-width: none; }
            .dashboard-grid::-webkit-scrollbar { display: none; }
            .dashboard-card { min-width: 85vw; scroll-snap-align: center; flex-shrink: 0; }
            .quick-links-grid { display: flex; overflow-x: auto; scroll-snap-type: x mandatory; gap: 0.75rem; padding-bottom: 0.5rem; margin-bottom: 1.5rem; scrollbar-width: none; }
            .quick-links-grid::-webkit-scrollbar { display: none; }
            .quick-link-card { min-width: 100px; scroll-snap-align: start; padding: 1rem 0.5rem; }
            .co-scroll-container { display: flex; overflow-x: auto; scroll-snap-type: x mandatory; gap: 1rem; padding-bottom: 0.5rem; margin-bottom: 1.5rem; scrollbar-width: none; }
            .co-scroll-container::-webkit-scrollbar { display: none; }
            .co-card { min-width: 280px; scroll-snap-align: start; }
            #notification-dropdown { position: fixed; top: 60px; left: 1rem; right: 1rem; width: auto; max-width: none; }
        }
    </style>
</head>

<body>
    <!-- HEADER -->
    <header class="main-header">
        <nav class="navbar">
            <a href="dashboard.php" class="logo">
                <img src="<?php echo htmlspecialchars($base_path . 'favicon.ico'); ?>" alt="Logo">
                <span>CUPAD AM</span>
            </a>
            
            <div class="flex items-center gap-3">
                <button id="theme-toggle" class="p-2 text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800 rounded-full transition">
                    <i class="fas fa-moon dark:hidden"></i><i class="fas fa-sun hidden dark:inline"></i>
                </button>
                
                <!-- Notification Bell -->
                <div class="relative">
                    <button id="notification-btn" class="p-2 text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800 rounded-full transition relative">
                        <i class="fas fa-bell text-lg"></i>
                        <span id="notification-badge" class="absolute -top-1 -right-1 min-w-[18px] h-[18px] bg-red-500 rounded-full text-white text-[10px] font-bold flex items-center justify-center border-2 border-white dark:border-gray-900 hidden px-1">0</span>
                    </button>
                    <!-- Dropdown -->
                    <div id="notification-dropdown" class="hidden absolute right-0 mt-3 bg-white dark:bg-gray-800 z-50">
                        <div class="p-3 border-b border-gray-100 dark:border-gray-700 flex justify-between items-center bg-gray-50 dark:bg-gray-900/50 backdrop-blur">
                            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Notifications</h3>
                            <div class="flex gap-3">
                                <button id="show-read-btn" onclick="dashboard.toggleReadNotifications()" class="text-xs text-gray-500 hover:text-gray-700 dark:hover:text-gray-300 font-medium transition">Show read</button>
                                <button onclick="dashboard.markNotificationsAsRead()" class="text-xs text-blue-600 dark:text-blue-400 font-medium hover:underline">Mark all read</button>
                            </div>
                        </div>
                        <div id="notification-list" class="max-h-[325px] overflow-y-auto">
                            <!-- Items inserted here via JS -->
                        </div>
                    </div>
                </div>

                <div class="profile-btn" onclick="window.location.href='profile.php'">
                    <i class="fas fa-user profile-fallback-icon"></i>
                    <?php if (isset($profile_pic) && $profile_pic !== 'default_avatar.png'): ?>
                        <img src="<?php echo $base_path . $profile_pic; ?>" alt="Profile" class="absolute inset-0" onerror="this.style.display='none'"> 
                    <?php endif; ?>
                </div>

                <a href="<?php echo htmlspecialchars($base_path . 'logout.php'); ?>" class="p-2 text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-full transition" title="Logout">
                    <i class="fas fa-sign-out-alt"></i>
                </a>
            </div>
        </nav>
    </header>

    <main class="max-w-7xl mx-auto px-4 py-6">
        
        <div class="mb-6 text-center">
            <h1 class="text-2xl font-extrabold text-gray-900 dark:text-white"><?php echo $greeting . ', ' . (!empty($full_name) ? explode(' ', trim($full_name))[0] : $username); ?></h1>
            <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">
                <i class="fas fa-map-marked-alt mr-1"></i>
                Area Manager, <?php echo htmlspecialchars($area_name); ?> 
                <span class="mx-2">•</span>
                <i class="fas fa-map mr-1"></i>
                <?php echo htmlspecialchars($zone_name); ?> Zone
            </p>
        </div>
        
        <!-- STATS (Area Level) -->
        <div class="dashboard-grid">
            <div class="dashboard-card card-gradient-blue">
                <i class="fas fa-piggy-bank card-bg-icon"></i>
                <div class="flex justify-between items-start relative z-10">
                    <div class="min-w-0">
                        <div class="text-sm font-semibold uppercase opacity-90">Area Net Savings</div>
                        <div class="text-2xl lg:text-3xl font-extrabold mt-1 mb-2 truncate" title="₦<?php echo number_format($area_monthly_savings); ?>">₦<?php echo number_format($area_monthly_savings); ?></div>
                        <div class="text-xs bg-black/20 rounded-full px-3 py-1 inline-flex items-center"><i class="fas fa-chart-line mr-1"></i> This Month</div>
                    </div>
                </div>
            </div>

            <a href="disbursements.php" class="dashboard-card card-gradient-orange" style="text-decoration: none; color: inherit;">
                <i class="fas fa-hand-holding-usd card-bg-icon"></i>
                <div class="flex justify-between items-start relative z-10">
                    <div class="min-w-0">
                        <div class="text-sm font-semibold uppercase opacity-90">Area Disbursed</div>
                        <div class="text-2xl lg:text-3xl font-extrabold mt-1 mb-2 truncate" title="₦<?php echo number_format($area_monthly_disbursed); ?>">₦<?php echo number_format($area_monthly_disbursed); ?></div>
                        <div class="text-xs bg-black/20 rounded-full px-3 py-1 inline-flex items-center"><i class="fas fa-paper-plane mr-1"></i> This Month</div>
                    </div>
                </div>
            </a>

            <div class="dashboard-card card-gradient-green">
                <i class="fas fa-check-circle card-bg-icon"></i>
                <div class="flex justify-between items-start relative z-10">
                    <div class="min-w-0">
                        <div class="text-sm font-semibold uppercase opacity-90">Outstanding Portfolio</div>
                        <div class="text-2xl lg:text-3xl font-extrabold mt-1 mb-2 truncate" title="₦<?php echo number_format($area_outstanding_balance); ?>">
                            ₦<?php echo number_format($area_outstanding_balance); ?>
                        </div>
                        <div class="text-xs bg-black/20 rounded-full px-3 py-1 inline-flex items-center">
                            <i class="fas fa-file-signature mr-1"></i> <?php echo number_format($area_active_loans_count); ?> Active Loans
                        </div>
                    </div>
                </div>
            </div>

            <div class="dashboard-card card-gradient-purple">
                <i class="fas fa-users card-bg-icon"></i>
                <div class="flex justify-between items-start relative z-10">
                    <div class="min-w-0">
                        <div class="text-sm font-semibold uppercase opacity-90">Total Clients</div>
                        <div class="text-2xl lg:text-3xl font-extrabold mt-1 mb-2 truncate"><?php echo number_format($area_total_clients); ?></div>
                        <div class="text-xs bg-black/20 rounded-full px-3 py-1 inline-flex items-center truncate"><i class="fas fa-users mr-1"></i> Area-wide Active</div>
                    </div>
                </div>
            </div>
        </div>

<!-- QUICK ACTIONS FOR AM -->
        <h2 class="text-lg font-bold text-gray-800 dark:text-white mb-3">Area Management</h2>
        <div class="quick-links-grid">
            <a href="branches.php" class="quick-link-card">
                <div class="quick-link-icon" style="background: linear-gradient(135deg, #10b981, #059669);"><i class="fas fa-building"></i></div>
                <span class="quick-link-label">Branches</span>
            </a>
            <a href="clients.php" class="quick-link-card">
                <div class="quick-link-icon" style="background: linear-gradient(135deg, #3b82f6, #1d4ed8);"><i class="fas fa-users"></i></div>
                <span class="quick-link-label">All Clients</span>
            </a>
            <a href="analytics.php" class="quick-link-card">
                <div class="quick-link-icon" style="background: linear-gradient(135deg, #f97316, #ea580c);"><i class="fas fa-chart-bar"></i></div>
                <span class="quick-link-label">Reports</span>
            </a>
            <a href="history.php" class="quick-link-card">
                <div class="quick-link-icon" style="background: linear-gradient(135deg, #14b8a6, #0d9488);"><i class="fas fa-history"></i></div>
                <span class="quick-link-label">History</span>
            </a>
            <!-- Changed from arrears.php to client_financial_summary.php -->
            <a href="client_financial_summary.php" class="quick-link-card">
                <div class="quick-link-icon" style="background: linear-gradient(135deg, #ef4444, #dc2626);"><i class="fas fa-file-invoice-dollar"></i></div>
                <span class="quick-link-label">Fin. Summary</span>
            </a>
            <a href="../passkey_setup.php" class="quick-link-card">
                <div class="quick-link-icon" style="background: linear-gradient(135deg, #64748b, #475569);"><i class="fas fa-fingerprint"></i></div>
                <span class="quick-link-label">Passkey</span>
            </a>
        </div>

        
        <!-- BRANCHES PERFORMANCE (Area Level) -->
        <h2 class="text-lg font-bold text-gray-800 dark:text-white mb-3">Branches Overview (This Month)</h2>
        <div class="co-scroll-container">
            <?php foreach ($branch_performances as $branch): 
                // Calculate Net Savings in PHP from the new SQL columns
                $monthly_net_savings = $branch['monthly_deposits'] - $branch['monthly_withdrawals'];
            ?>
            <div class="co-card">
                <div class="co-head">
                    <div class="co-name" title="<?php echo htmlspecialchars($branch['branch_name']); ?>">
                        <i class="fas fa-building mr-1 text-blue-500"></i> <?php echo htmlspecialchars($branch['branch_name']); ?>
                    </div>
                    <div class="co-count"><i class="fas fa-users"></i> <?php echo number_format($branch['active_clients']); ?></div>
                </div>
                <div class="co-stat-row">
                    <span style="color:var(--text-secondary);"><i class="fas fa-arrow-up text-green-500/80 mr-1"></i> Deposits</span>
                    <span style="color:var(--success-color); font-weight:700;">₦<?php echo number_format($branch['monthly_deposits']); ?></span>
                </div>
                 <div class="co-stat-row">
                    <span style="color:var(--text-secondary);"><i class="fas fa-arrow-down text-red-500/80 mr-1"></i> Withdrawals</span>
                    <span style="color:var(--error-color); font-weight:700;">₦<?php echo number_format($branch['monthly_withdrawals']); ?></span>
                </div>
                <div class="co-stat-row font-bold" style="margin-top: 0.5rem; border-top: 1px dashed var(--border-color); padding-top: 0.5rem;">
                    <span style="color:var(--text-secondary);">Net Savings</span>
                    <span style="color:var(--primary-color);">₦<?php echo number_format($monthly_net_savings); ?></span>
                </div>
                <div class="co-stat-row">
                    <span style="color:var(--text-secondary);">Collections</span>
                    <span style="color:var(--secondary-color); font-weight:700;">₦<?php echo number_format($branch['monthly_collections']); ?></span>
                </div>
                <div class="co-stat-row">
                    <span style="color:var(--text-secondary);">Outstanding Loans</span>
                    <span style="color:var(--warning-color); font-weight:700;">₦<?php echo number_format($branch['outstanding_portfolio']); ?></span>
                </div>
            </div>
            <?php endforeach; ?>
            <?php if(empty($branch_performances)): ?>
                <div class="text-gray-400 italic p-4">No active branches assigned to this area.</div>
            <?php endif; ?>
        </div>

        <!-- AREA WIDE SEARCH & FEED -->
        <div class="search-filter-container">
            <div class="search-row">
                <div style="position:relative; flex:1;">
                    <i class="fas fa-search" style="position:absolute; left:1rem; top:50%; transform:translateY(-50%); color:var(--text-secondary);"></i>
                    <input type="text" id="activity-search" class="search-input" placeholder="Search client, officer, or type...">
                </div>
                <button id="clear-filters" style="background:transparent; color:var(--text-secondary); border:none; cursor:pointer;">Clear</button>
            </div>
            <div class="filter-tabs">
                <button class="filter-tab active" data-filter="all">All</button>
                <button class="filter-tab" data-filter="saving">Savings</button>
                <button class="filter-tab" data-filter="withdrawal">Withdrawal</button>
                <button class="filter-tab" data-filter="payment">Repayment</button>
                <button class="filter-tab" data-filter="disbursement">Loans</button>
                <button class="filter-tab" data-filter="registration">Registration</button>
            </div>
        </div>

        <div class="activities-container">
            <div class="flex justify-between mb-4">
                <h2 class="text-lg font-bold text-gray-800 dark:text-white">Area Activity Feed</h2>
                <button id="refresh-activities" class="text-gray-400 hover:text-blue-500"><i class="fas fa-sync-alt"></i></button>
            </div>
            
            <div id="activities-list">
                <?php foreach ($initial_activities as $index => $activity): 
                    $type = strtolower($activity['type']);
                    echo generateActivityHTML($activity, $type, getActivityIcon($activity['type']), getActivityColor($activity['type']), $index);
                endforeach; ?>
                <?php if(empty($initial_activities)): ?>
                    <div class="text-center py-8 text-gray-400">No area activities found.</div>
                <?php endif; ?>
            </div>
            
            <?php if ($has_more): ?>
            <div id="load-more-btn-container" class="text-center mt-6">
                <button id="load-more-btn" class="px-6 py-2 bg-blue-600 text-white rounded-lg font-bold shadow-md hover:bg-blue-700 transition w-full" onclick="loadMoreActivities()">Load More</button>
            </div>
            <?php endif; ?>
        </div>

    </main>

    <!-- MOBILE BOTTOM NAVIGATION (AM) -->
    <nav class="mobile-bottom-nav">
        <a href="dashboard.php" class="nav-item active">
             <div style="background: var(--primary-color); width: 45px; height: 45px; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; margin-top: -25px; box-shadow: 0 4px 10px rgba(59, 130, 246, 0.4);">
                <i class="fas fa-home" style="font-size: 1.2rem; margin:0;"></i>
             </div>
             <span style="font-size:0.65rem; font-weight:600; margin-top:2px;">Home</span>
        </a>
        <a href="branches.php" class="nav-item">
            <i class="fas fa-building"></i>
            <span>Branches</span>
        </a>
        <a href="clients.php" class="nav-item">
            <i class="fas fa-users"></i>
            <span>Clients</span>
        </a>
        <a href="analytics.php" class="nav-item">
            <i class="fas fa-chart-bar"></i>
            <span>Reports</span>
        </a>
    </nav>

    <script src="../js/heartbeat.js"></script>
    <script>
        class EnhancedDashboard {
            constructor() {
                this.isLoading = false;
                this.currentPage = 1;
                this.hasMore = <?php echo $has_more ? 'true' : 'false'; ?>;
                this.limit = 10;
                this.notificationInterval = null;
                this.showReadNotifications = false;
                this.lastKnownTimestamp = 0;
                this.currentFilter = 'all';
                this.notificationAudio = new Audio("data:audio/mp3;base64,//uQxAAAAANIAAAAAExBTUUzLjEwMKqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq//uQxAAAAANIAAAAAExBTUUzLjEwMKqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq//uQxAAAAANIAAAAAExBTUUzLjEwMKqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq//uQxAAAAANIAAAAAExBTUUzLjEwMKqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq//uQxAAAAANIAAAAAExBTUUzLjEwMKqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq");
                this.init();
            }

            init() {
                const t = localStorage.getItem('theme');
                if(t==='dark') document.documentElement.classList.add('dark');
                
                document.getElementById('theme-toggle').addEventListener('click', () => {
                    document.documentElement.classList.toggle('dark');
                    localStorage.setItem('theme', document.documentElement.classList.contains('dark') ? 'dark' : 'light');
                });

                const notifBtn = document.getElementById('notification-btn');
                const notifDropdown = document.getElementById('notification-dropdown');
                
                notifBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    notifDropdown.classList.toggle('hidden');
                });

                const unlockAudio = () => {
                    this.notificationAudio.volume = 1.0;
                    this.notificationAudio.play().then(() => {
                        this.notificationAudio.pause();
                        this.notificationAudio.currentTime = 0;
                    }).catch(e => console.log('Audio autoplay prevented initially'));

                    if ("Notification" in window && Notification.permission !== "granted") {
                        Notification.requestPermission();
                    }
                    document.removeEventListener('click', unlockAudio);
                };
                document.addEventListener('click', unlockAudio);

                document.addEventListener('click', (e) => {
                    if (!notifBtn.contains(e.target) && !notifDropdown.contains(e.target)) {
                        if (!notifDropdown.classList.contains('hidden')) {
                            this.markNotificationsAsRead();
                        }
                        notifDropdown.classList.add('hidden');
                    }
                });

                document.getElementById('activity-search').addEventListener('input', (e) => this.debounceSearch(e.target.value));
                
                document.querySelectorAll('.filter-tab').forEach(tab => {
                    tab.addEventListener('click', (e) => {
                        const newFilter = e.target.dataset.filter;
                        if (newFilter === this.currentFilter) return;

                        document.querySelectorAll('.filter-tab').forEach(t => t.classList.remove('active'));
                        e.currentTarget.classList.add('active');
                        this.applyFilter(newFilter);
                    });
                });

                document.getElementById('clear-filters').addEventListener('click', () => {
                    document.getElementById('activity-search').value = '';
                    document.querySelectorAll('.filter-tab').forEach(t => t.classList.remove('active'));
                    document.querySelector('.filter-tab[data-filter="all"]').classList.add('active');
                    this.applyFilter('all');
                });

                document.getElementById('refresh-activities').addEventListener('click', () => this.refreshActivities());

                this.fetchNotifications();
                this.notificationInterval = setInterval(() => this.fetchNotifications(), 10000);
            }

            getNotificationIcon(msg) {
                msg = msg.toLowerCase();
                if(msg.includes('saving') || msg.includes('deposit')) return 'fa-piggy-bank';
                if(msg.includes('withdrawal')) return 'fa-wallet';
                if(msg.includes('loan') || msg.includes('disburse')) return 'fa-hand-holding-usd';
                if(msg.includes('repay') || msg.includes('payment')) return 'fa-money-bill-wave';
                if(msg.includes('client') || msg.includes('register')) return 'fa-user-plus';
                return 'fa-bell';
            }

            playNotificationSound() {
                this.notificationAudio.currentTime = 0;
                this.notificationAudio.play().catch(err => console.log('Audio play failed:', err));
            }

            showBrowserNotification(message) {
                if ("Notification" in window && Notification.permission === "granted") {
                    new Notification("CUPAD AM Dashboard", {
                        body: message,
                        icon: '<?php echo $base_path; ?>favicon.ico'
                    });
                }
            }

            async fetchNotifications() {
                const url = window.location.href.split('?')[0];
                try {
                    const res = await fetch(`${url}?action=fetch_notifications&show_read=${this.showReadNotifications}`);
                    const notifications = await res.json();
                    
                    if (notifications.length > 0) {
                        const latest = notifications[0];
                        const latestTime = new Date(latest.timestamp || latest.date).getTime();
                        
                        if (this.lastKnownTimestamp !== 0 && latestTime > this.lastKnownTimestamp) {
                            this.playNotificationSound();
                            this.showBrowserNotification(latest.message);
                        }
                        this.lastKnownTimestamp = latestTime;
                    }

                    const listContainer = document.getElementById('notification-list');
                    const badge = document.getElementById('notification-badge');
                    
                    const unreadCount = notifications.filter(n => !n.read).length;
                    
                    if (unreadCount > 0) {
                        badge.textContent = unreadCount > 9 ? '9+' : unreadCount;
                        badge.classList.remove('hidden');
                    } else {
                        badge.classList.add('hidden');
                    }

                    if (notifications.length > 0) {
                        let html = '';
                        notifications.forEach(n => {
                            const date = new Date(n.timestamp || n.date).toLocaleString([], { month: 'short', day: 'numeric', hour: '2-digit', minute:'2-digit' });
                            const readClass = n.read ? 'read' : 'unread';
                            const icon = this.getNotificationIcon(n.message);
                            
                            html += `
                                <div class="notification-item ${readClass}">
                                    <div class="notif-icon">
                                        <i class="fas ${icon}"></i>
                                    </div>
                                    <div class="notif-content">
                                        <p class="notif-message">${n.message}</p>
                                        <span class="notif-time">${date}</span>
                                    </div>
                                    ${!n.read ? '<div class="notif-dot"></div>' : ''}
                                </div>
                            `;
                        });
                        listContainer.innerHTML = html;
                    } else {
                        const message = this.showReadNotifications ? 'No notifications' : 'No new notifications';
                        listContainer.innerHTML = `
                            <div class="flex flex-col items-center justify-center py-8 text-gray-400">
                                <i class="far fa-bell-slash text-2xl mb-2 opacity-50"></i>
                                <span class="text-sm">${message}</span>
                            </div>`;
                    }
                } catch (error) {
                    console.error('Error fetching notifications:', error);
                }
            }

            toggleReadNotifications() {
                this.showReadNotifications = !this.showReadNotifications;
                const btn = document.getElementById('show-read-btn');
                btn.textContent = this.showReadNotifications ? 'Show unread' : 'Show read';
                this.fetchNotifications();
            }

            async markNotificationsAsRead() {
                const url = window.location.href.split('?')[0];
                try {
                    const res = await fetch(`${url}?action=mark_notifications_read`, { 
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' }
                    });
                    const result = await res.json();
                    if (result.success) {
                        document.getElementById('notification-badge').classList.add('hidden');
                        document.getElementById('notification-list').innerHTML = `
                            <div class="flex flex-col items-center justify-center py-8 text-gray-400">
                                <i class="far fa-bell-slash text-2xl mb-2 opacity-50"></i>
                                <span class="text-sm">No new notifications</span>
                            </div>`;
                    }
                } catch (error) {
                    console.error('Error marking notifications:', error);
                }
            }

            debounceSearch(val) {
                clearTimeout(this.searchTimeout);
                this.searchTimeout = setTimeout(() => this.performSearch(val), 300);
            }

            async performSearch(query) {
                if (this.isLoading) return;
                this.isLoading = true;
                const url = window.location.href.split('?')[0];
                try {
                    const res = await fetch(`${url}?action=search_activities&q=${encodeURIComponent(query)}&filter=${this.currentFilter}`);
                    const data = await res.json();
                    if (data.success) {
                        document.getElementById('activities-list').innerHTML = data.html || '<div class="text-center py-4 text-gray-400">No matching activities found.</div>';
                        const btnContainer = document.getElementById('load-more-btn-container');
                        if (btnContainer) btnContainer.style.display = 'none';
                    }
                } finally {
                    this.isLoading = false;
                }
            }
            
            async applyFilter(filter) {
                this.currentFilter = filter;
                this.currentPage = 0;
                this.hasMore = true;
                
                document.getElementById('activities-list').innerHTML = '';
                const btnContainer = document.getElementById('load-more-btn-container');
                if (btnContainer) btnContainer.style.display = 'block';
                
                await this.loadMoreActivities();
            }

            async loadMoreActivities() {
                if (this.isLoading || !this.hasMore) return;
                this.isLoading = true;
                this.currentPage++;
                const btn = document.getElementById('load-more-btn');
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Loading...';
                const url = window.location.href.split('?')[0];

                try {
                    const res = await fetch(`${url}?action=load_activities&page=${this.currentPage}&limit=${this.limit}&filter=${this.currentFilter}`);
                    if (!res.ok) throw new Error('Network error');
                    const data = await res.json();
                    if (data.success) {
                        document.getElementById('activities-list').insertAdjacentHTML('beforeend', data.html);
                        this.hasMore = data.has_more;
                        
                        const btnContainer = document.getElementById('load-more-btn-container');
                        if (!this.hasMore && btnContainer) {
                            btnContainer.style.display = 'none';
                        }
                        
                        if (this.currentPage === 1 && data.html === '') {
                             document.getElementById('activities-list').innerHTML = '<div class="text-center py-8 text-gray-400">No activities found for this filter.</div>';
                        }
                    }
                } catch (error) {
                    this.currentPage--; 
                } finally {
                    this.isLoading = false;
                    btn.innerHTML = 'Load More';
                }
            }

            async refreshActivities() {
                this.isLoading = false;
                this.currentPage = 0;
                this.hasMore = true;
                
                document.getElementById('activity-search').value = '';
                document.querySelectorAll('.filter-tab').forEach(t => t.classList.remove('active'));
                document.querySelector('.filter-tab[data-filter="all"]').classList.add('active');
                this.currentFilter = 'all';

                document.getElementById('activities-list').innerHTML = '';
                const btnContainer = document.getElementById('load-more-btn-container');
                if(btnContainer) btnContainer.style.display = 'block';
                await this.loadMoreActivities();
            }
        }

        const dashboard = new EnhancedDashboard();
        function loadMoreActivities() { dashboard.loadMoreActivities(); }
    </script>
</body>
</html>