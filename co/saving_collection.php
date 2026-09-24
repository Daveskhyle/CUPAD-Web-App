<?php
date_default_timezone_set('Africa/Lagos');
// co/savings_collection.php - SQL Based Version
session_start();

if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'co') {
    header('Location: ../index.php');
    exit();
}
$base_path = '../';

require_once $base_path . 'includes/config.php';

// --- SQL HELPER FUNCTIONS ---

function generate_uuid() {
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function is_weekend($dateString = 'now') {
    $ts = strtotime($dateString);
    if ($ts === false) $ts = time();
    return (date('N', $ts) >= 6);
}

function get_client_savings_balance_sql($client_id) {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("SELECT balance FROM saving_balances WHERE client_id = ?");
        $stmt->execute([$client_id]);
        $result = $stmt->fetch();
        return $result ? floatval($result['balance']) : 0;
    } catch (Exception $e) {
        error_log("get_client_savings_balance_sql error: " . $e->getMessage());
        return 0;
    }
}

function get_client_loan_balance_sql($client_id) {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(remaining_balance), 0) as total FROM disbursements WHERE client_id = ? AND status = 'active' AND remaining_balance > 0");
        $stmt->execute([$client_id]);
        return floatval($stmt->fetch()['total'] ?? 0);
    } catch (Exception $e) {
        return 0;
    }
}



function get_user_info_sql($username) {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$username]);
        return $stmt->fetch();
    } catch (Exception $e) {
        return null;
    }
}

function get_branch_name_sql($branch_id) {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("SELECT name FROM branches WHERE id = ?");
        $stmt->execute([$branch_id]);
        return $stmt->fetch()['name'] ?? '';
    } catch (Exception $e) {
        return '';
    }
}

function get_savings_collection_settings_sql() {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->query("SELECT * FROM savings_settings WHERE id = 1 LIMIT 1");
        $settings = $stmt->fetch(PDO::FETCH_ASSOC);

        return $settings ?: [
            'min_savings_amount' => 100,
            'max_savings_amount' => 1000000,
            'allow_weekend_collection' => 0,
            'savings_date_readonly' => 0,
        ];
    } catch (Exception $e) {
        error_log("get_savings_collection_settings_sql error: " . $e->getMessage());
        return [
            'min_savings_amount' => 100,
            'max_savings_amount' => 1000000,
            'allow_weekend_collection' => 0,
            'savings_date_readonly' => 0,
        ];
    }
}

function record_savings_sql($client_id, $amount, $date, $officer) {
    try {
        $pdo = getDbConnection();
        $pdo->beginTransaction();

        $tx_id = 'SAV-' . strtoupper(generate_uuid());
        $payment_date = date('Y-m-d', strtotime($date));

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM saving_collections WHERE client_id = ? AND DATE(date) = ? AND amount = ?");
        $stmt->execute([$client_id, $payment_date, $amount]);
        if ($stmt->fetchColumn() > 0) {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'Client already has a savings transaction with this amount on this date.'];
        }

        // Get current balance from saving_balances table
        $stmt = $pdo->prepare("SELECT balance FROM saving_balances WHERE client_id = ?");
        $stmt->execute([$client_id]);
        $current_balance = $stmt->fetchColumn();
        $previous_balance = $current_balance ? floatval($current_balance) : 0;
        $new_balance = $previous_balance + $amount;

        // Get or create savings account ID
        $stmt = $pdo->prepare("SELECT id FROM savings WHERE client_id = ? AND status = 'active' LIMIT 1");
        $stmt->execute([$client_id]);
        $savings_id = $stmt->fetchColumn();
        
        if (!$savings_id) {
            $savings_id = 'SVG-' . strtoupper(generate_uuid());
            $stmt = $pdo->prepare("INSERT INTO savings (id, client_id, officer, balance, status, created_at) VALUES (?, ?, ?, ?, 'active', NOW())");
            $stmt->execute([$savings_id, $client_id, $officer, $new_balance]);
        } else {
            $stmt = $pdo->prepare("UPDATE savings SET balance = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$new_balance, $savings_id]);
        }

        $stmt = $pdo->prepare("INSERT INTO saving_collections (transaction_id, client_id, savings_id, amount, type, date, officer, balance_after, created_at) VALUES (?, ?, ?, ?, 'deposit', ?, ?, ?, NOW())");
        $stmt->execute([$tx_id, $client_id, $savings_id, $amount, date('Y-m-d H:i:s', strtotime($date)), $officer, $new_balance]);

        // Update saving_balances table - DELETE old entries first to avoid duplicates
        if (!empty($client_id)) {
            $stmt = $pdo->prepare("DELETE FROM saving_balances WHERE client_id = ? LIMIT 1");
            $stmt->execute([$client_id]);
            
            $stmt = $pdo->prepare("INSERT INTO saving_balances (client_id, balance, last_updated) VALUES (?, ?, NOW())");
            $stmt->execute([$client_id, $new_balance]);
        }

        $pdo->commit();
        return ['success' => true, 'message' => 'Savings recorded successfully', 'transaction_id' => $tx_id, 'new_balance' => $new_balance];

    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        error_log("record_savings_sql error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
    }
}

function get_savings_transactions_sql($officer, $union_filter = 'all', $date_filter = 'current_month', $page = 1, $limit = 10) {
    try {
        $pdo = getDbConnection();
        $sql = "SELECT sc.transaction_id, c.name as client_name, sc.amount as amount_collected, sc.date, sc.officer 
                FROM saving_collections sc
                JOIN clients c ON sc.client_id = c.id
                WHERE sc.officer = ? AND sc.type = 'deposit'";
        
        $params = [$officer];
        $types = "s";

        if ($union_filter !== 'all') {
            $sql .= " AND c.union = ?";
            $params[] = $union_filter;
        }

        if ($date_filter === 'today') {
            $sql .= " AND DATE(sc.date) = CURDATE()";
        } elseif ($date_filter === 'current_month') {
            $sql .= " AND YEAR(sc.date) = YEAR(CURDATE()) AND MONTH(sc.date) = MONTH(CURDATE())";
        }

        $count_sql = str_replace("SELECT sc.transaction_id, c.name as client_name, sc.amount as amount_collected, sc.date, sc.officer", "SELECT COUNT(*) as cnt", $sql);
        $stmt = $pdo->prepare($count_sql);
        $stmt->execute($params);
        $total = $stmt->fetch()['cnt'];

        $sql .= " ORDER BY sc.date DESC LIMIT ? OFFSET ?";
        $offset = ($page - 1) * $limit;
        $params[] = $limit;
        $params[] = $offset;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return ['data' => $stmt->fetchAll(), 'total' => $total];
    } catch (Exception $e) {
        error_log("get_savings_transactions_sql error: " . $e->getMessage());
        return ['data' => [], 'total' => 0];
    }
}

function get_savings_stats_sql($officer) {
    try {
        $pdo = getDbConnection();

        $stmt = $pdo->prepare("SELECT COUNT(DISTINCT c.id) FROM clients c WHERE c.officer_username = ? AND c.status = 'active' AND EXISTS (SELECT 1 FROM savings s WHERE s.client_id = c.id AND s.balance > 0 AND s.status = 'active')");
        $stmt->execute([$officer]);
        $active_savers = $stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COALESCE(SUM(s.balance), 0) FROM savings s JOIN clients c ON s.client_id = c.id WHERE c.officer_username = ? AND s.status = 'active'");
        $stmt->execute([$officer]);
        $total_savings = $stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM saving_collections WHERE officer = ? AND type = 'deposit' AND YEAR(date) = YEAR(CURDATE()) AND MONTH(date) = MONTH(CURDATE())");
        $stmt->execute([$officer]);
        $month_total = $stmt->fetchColumn();

        return ['active_savers' => intval($active_savers), 'total_savings' => floatval($total_savings), 'month_total' => floatval($month_total)];
    } catch (Exception $e) {
        return ['active_savers' => 0, 'total_savings' => 0, 'month_total' => 0];
    }
}

// --- CONFIGURATION ---
$savings_settings = get_savings_collection_settings_sql();
$min_savings_amount = $savings_settings['min_savings_amount'] ?? 100;
$max_savings_amount = $savings_settings['max_savings_amount'] ?? 1000000;
$allow_weekend_collection = (bool)($savings_settings['allow_weekend_collection'] ?? false);
$savings_date_readonly = (bool)($savings_settings['savings_date_readonly'] ?? false);

// --- AJAX HANDLING ---
if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'get_client_financial_data') {
    header('Content-Type: application/json');
    $client_id = $_GET['client_id'] ?? '';
    if (empty($client_id)) { echo json_encode(['success' => false, 'savings_balance' => 0, 'loan_balance' => 0]); exit(); }
    echo json_encode(['success' => true, 'savings_balance' => get_client_savings_balance_sql($client_id), 'loan_balance' => get_client_loan_balance_sql($client_id)]);
    exit();
}

if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'get_transactions') {
    header('Content-Type: application/json');
    $page = max(1, intval($_GET['page'] ?? 1));
    $limit = max(1, min(100, intval($_GET['limit'] ?? 10)));
    $union_filter = $_GET['union'] ?? 'all';
    $date_filter = $_GET['date_filter'] ?? 'current_month';

    $co_username = $_SESSION['username'] ?? '';
    $result = get_savings_transactions_sql($co_username, $union_filter, $date_filter, $page, $limit);
    $page_total = 0;

    foreach ($result['data'] as $e) {
        $page_total += floatval($e['amount_collected'] ?? 0);
    }

    $total_pages = ceil($result['total'] / $limit);
    echo json_encode(['success' => true, 'data' => $result['data'], 'pagination' => ['current_page' => $page, 'total_pages' => $total_pages, 'total_records' => $result['total'], 'limit' => $limit, 'has_next' => $page < $total_pages, 'has_prev' => $page > 1], 'totals' => ['page_total' => $page_total]]);
    exit();
}

// --- DATA LOADING ---
$username = $_SESSION['username'] ?? '';
$user_info = get_user_info_sql($username);
$full_name = $user_info['name'] ?? $user_info['full_name'] ?? $_SESSION['name'] ?? 'Credit Officer';
$profile_pic = $user_info['profile_pic'] ?? 'default_avatar.png';

// Fetch clients directly by officer (like loan_collection.php)
$clients = [];
$clients_by_union = [];
$assigned_unions = [];

try {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare("SELECT id, name, `union`, phone, officer_username FROM clients WHERE officer_username = ? AND status = 'active' ORDER BY name ASC");
    $stmt->execute([$username]);
    
    while ($row = $stmt->fetch()) {
        $clients[] = $row;
        $u = $row['union'] ?? 'Unassigned';
        $clients_by_union[$u][] = $row;
        if (!in_array($u, $assigned_unions)) {
            $assigned_unions[] = $u;
        }
    }
    sort($assigned_unions);
} catch (Exception $e) {
    error_log("Failed to fetch clients: " . $e->getMessage());
}

$clients_by_id = [];
foreach ($clients as $c) {
    $clients_by_id[$c['id']] = $c;
}

$savings_by_client = [];
$total_clients_savings = 0;
$total_savings_amount = 0;

try {
    $pdo = getDbConnection();
    $client_ids = array_column($clients, 'id');
    if (!empty($client_ids)) {
        $placeholders = str_repeat('?,', count($client_ids) - 1) . '?';
        $stmt = $pdo->prepare("SELECT client_id, balance FROM savings WHERE client_id IN ($placeholders) AND status = 'active' AND balance > 0");
        $stmt->execute($client_ids);
        while ($row = $stmt->fetch()) {
            $savings_by_client[$row['client_id']] = floatval($row['balance']);
            $total_clients_savings++;
            $total_savings_amount += floatval($row['balance']);
        }
    }
    // Fallback: use saving_balances table if savings table is empty
    if ($total_clients_savings === 0 && !empty($client_ids)) {
        $stmt = $pdo->prepare("SELECT client_id, balance FROM saving_balances WHERE client_id IN ($placeholders) AND balance > 0");
        $stmt->execute($client_ids);
        while ($row = $stmt->fetch()) {
            $savings_by_client[$row['client_id']] = floatval($row['balance']);
            $total_clients_savings++;
            $total_savings_amount += floatval($row['balance']);
        }
    }
} catch (Exception $e) {
    error_log("Failed to fetch savings summary: " . $e->getMessage());
}

$stats = get_savings_stats_sql($username);
$personal_month_total = $stats['month_total'];

// --- ACTION HANDLING ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $response = ['success' => false, 'message' => ''];
    $client_id = $_POST['client_id'] ?? '';
    $amount = floatval($_POST['amount'] ?? 0);
    $date = !empty($_POST['date']) ? $_POST['date'] : date('Y-m-d H:i:s');
    $is_ajax = isset($_POST['ajax']) && $_POST['ajax'] === '1';

    if (!isset($clients_by_id[$client_id])) $response['message'] = 'Invalid Client ID';
    elseif ($amount < $min_savings_amount) $response['message'] = 'Amount must be at least ₦' . number_format($min_savings_amount) . '.';
    elseif ($amount > $max_savings_amount) $response['message'] = 'Amount cannot exceed ₦' . number_format($max_savings_amount) . '.';
    elseif (!$allow_weekend_collection && is_weekend($date)) $response['message'] = 'Savings collection not allowed on weekends.';
    else {
        $result = record_savings_sql($client_id, $amount, $date, $username);
        if ($result['success']) {
            $response['success'] = true;
            $response['message'] = "Saved ₦" . number_format($amount) . " from " . $clients_by_id[$client_id]['name'];
        } else {
            $response['message'] = $result['message'];
        }
    }

    if ($is_ajax) { header('Content-Type: application/json'); echo json_encode($response); exit(); }
}

$js_clients_by_union = json_encode($clients_by_union);
$js_savings_by_client = json_encode($savings_by_client);
$js_assigned_unions = json_encode($assigned_unions);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#3b82f6">
    <title>Savings Collection | CUPAD</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
    tailwind.config = { darkMode: 'class', theme: { extend: { colors: { primary: '#3b82f6', secondary: '#8b5cf6', success: '#22c55e', warning: '#f59e0b', error: '#ef4444', bg: { light: '#f0f2f5', dark: '#0f172a' } } } } }
    </script>
    <style>
        :root { --primary-color: #3b82f6; --secondary-color: #8b5cf6; --success-color: #22c55e; --warning-color: #f59e0b; --error-color: #ef4444; --bg-primary: #f0f2f5; --bg-secondary: #ffffff; --bg-card: #ffffff; --text-primary: #1f2937; --text-secondary: #6b7280; --border-color: #e5e7eb; --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05); --border-radius-lg: 1rem; --border-radius-xl: 1.5rem; }
        html.dark { --bg-primary: #0f172a; --bg-secondary: #1e293b; --bg-card: #1e293b; --text-primary: #f1f5f9; --text-secondary: #94a3b8; --border-color: rgba(255, 255, 255, 0.08); }
        body { font-family: 'Inter', sans-serif; background-color: var(--bg-primary); color: var(--text-primary); padding-bottom: 90px; -webkit-tap-highlight-color: transparent; }
        ::-webkit-scrollbar { width: 5px; height: 5px; } ::-webkit-scrollbar-track { background: transparent; } ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; } html.dark ::-webkit-scrollbar-thumb { background: #334155; } * { scrollbar-width: thin; scrollbar-color: #cbd5e1 transparent; } html.dark * { scrollbar-color: #334155 transparent; }
        .main-header { background: rgba(255, 255, 255, 0.95); backdrop-filter: blur(12px); border-bottom: 1px solid var(--border-color); position: sticky; top: 0; z-index: 50; padding-top: env(safe-area-inset-top); } html.dark .main-header { background: rgba(15, 23, 42, 0.95); }
        .navbar { display: flex; justify-content: space-between; align-items: center; padding: 0.8rem 1rem; max-width: 1280px; margin: 0 auto; }
        .dashboard-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1rem; margin-bottom: 1.5rem; }
        .dashboard-card { border-radius: var(--border-radius-xl); padding: 1.5rem; position: relative; overflow: hidden; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1); color: white; transition: transform 0.2s ease; border: none; } .dashboard-card:active { transform: scale(0.98); }
        .card-gradient-green { background: linear-gradient(135deg, #10b981 0%, #059669 100%); } .card-gradient-emerald { background: linear-gradient(135deg, #34d399 0%, #059669 100%); } .card-gradient-blue { background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); }
        .card-bg-icon { position: absolute; right: -10px; bottom: -20px; font-size: 6rem; color: white; opacity: 0.1; transform: rotate(-10deg); pointer-events: none; }
        .activity-card { display: flex; align-items: center; background: var(--bg-secondary); padding: 1rem; margin-bottom: 0.75rem; border-radius: var(--border-radius-lg); border: 1px solid var(--border-color); }
        .ac-icon-box { width: 44px; height: 44px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; margin-right: 0.8rem; flex-shrink: 0; } .ac-content { flex: 1; } .ac-top { display: flex; justify-content: space-between; align-items: center; } .ac-bottom { display: flex; justify-content: space-between; align-items: center; margin-top: 2px; }
        .client-name { font-weight: 600; font-size: 0.95rem; color: var(--text-primary); } .activity-amount { font-weight: 700; font-size: 1rem; } .activity-date { font-size: 0.7rem; color: var(--text-secondary); }
        .input-group { background: var(--bg-primary); border: 1px solid var(--border-color); border-radius: 0.75rem; padding: 0.75rem; transition: all 0.2s ease; position: relative; } .input-group:focus-within { border-color: var(--success-color); box-shadow: 0 0 0 2px rgba(34, 197, 94, 0.2); background: var(--bg-secondary); } .input-group:hover { border-color: #cbd5e1; } html.dark .input-group:hover { border-color: #475569; }
        .input-field { background: transparent; border: none; width: 100%; color: var(--text-primary); outline: none; font-size: 0.95rem; appearance: none; -webkit-appearance: none; padding-right: 2rem; } .input-field option { background-color: var(--bg-secondary); color: var(--text-primary); padding: 10px; }
        .mobile-bottom-nav { display: none; position: fixed; bottom: 0; left: 0; right: 0; background: var(--bg-secondary); border-top: 1px solid var(--border-color); padding: 0.5rem 1rem calc(0.5rem + env(safe-area-inset-bottom)); z-index: 100; justify-content: space-between; box-shadow: 0 -4px 10px rgba(0,0,0,0.05); }
        .nav-item { display: flex; flex-direction: column; align-items: center; justify-content: center; text-decoration: none; color: var(--text-secondary); font-size: 0.7rem; font-weight: 500; gap: 4px; flex: 1; } .nav-item i { font-size: 1.4rem; } .nav-item.active { color: var(--success-color); }
        @media (max-width: 768px) { .mobile-bottom-nav { display: flex; } .dashboard-grid { display: flex; overflow-x: auto; scroll-snap-type: x mandatory; gap: 1rem; padding-bottom: 0.5rem; margin-right: -1rem; padding-right: 1.5rem; scrollbar-width: none; } .dashboard-grid::-webkit-scrollbar { display: none; } .dashboard-card { min-width: 85vw; scroll-snap-align: center; flex-shrink: 0; } }
        #toast-container { position: fixed; top: 1rem; left: 50%; transform: translateX(-50%); z-index: 999; width: 90%; max-width: 350px; pointer-events: none; }
        .toast { background: var(--bg-secondary); border-left: 4px solid; padding: 1rem; border-radius: 8px; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); margin-bottom: 0.5rem; display: flex; align-items: center; gap: 10px; animation: slideDown 0.3s ease; pointer-events: auto; }
        @keyframes slideDown { from { transform: translateY(-20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
        .profile-btn { width: 36px; height: 36px; border-radius: 50%; overflow: hidden; border: 2px solid var(--primary-color); background: var(--bg-secondary); cursor: pointer; display: flex; align-items: center; justify-content: center; position: relative; } .profile-btn img { width: 100%; height: 100%; object-fit: cover; } .profile-fallback-icon { font-size: 1.2rem; color: var(--text-secondary); }
    </style>
</head>
<body>
    <div id="toast-container"></div>
    <header class="main-header">
        <nav class="navbar">
            <a href="dashboard.php" class="flex items-center gap-2 no-underline">
                <img src="<?php echo htmlspecialchars($base_path . 'favicon.ico'); ?>" alt="Logo" class="h-8">
                <span class="text-xl font-extrabold text-blue-600 tracking-tight">CUPAD</span>
            </a>
            <div class="flex items-center gap-3">
                <button id="theme-toggle" class="p-2 text-gray-500 dark:text-gray-400"><i class="fas fa-moon dark:hidden"></i><i class="fas fa-sun hidden dark:inline"></i></button>
                <div class="profile-btn" onclick="window.location.href='profile.php'">
                    <i class="fas fa-user profile-fallback-icon"></i>
                    <?php if ($profile_pic && $profile_pic !== 'default_avatar.png'): ?><img src="<?php echo $base_path . $profile_pic; ?>" alt="Profile" class="absolute inset-0" onerror="this.style.display='none'"><?php endif; ?>
                </div>
                <a href="<?php echo htmlspecialchars($base_path . 'logout.php'); ?>" class="p-2 text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-full transition" title="Logout"><i class="fas fa-sign-out-alt"></i></a>
            </div>
        </nav>
    </header>
    <main class="max-w-7xl mx-auto px-4 py-6">
        <div class="mb-6"><h1 class="text-2xl font-extrabold text-gray-900 dark:text-white">Savings Collection</h1><p class="text-sm text-gray-500 dark:text-gray-400">Record client deposits and view history</p></div>
        <div class="dashboard-grid">
            <div class="dashboard-card card-gradient-green"><i class="fas fa-users card-bg-icon"></i><div class="flex justify-between items-start relative z-10"><div><div class="text-sm font-semibold uppercase opacity-90">Active Savers</div><div class="text-3xl font-extrabold mt-1 mb-2 count-up" data-target="<?php echo $total_clients_savings; ?>">0</div><div class="text-xs bg-black/20 rounded-full px-3 py-1 inline-flex items-center"><i class="fas fa-hashtag mr-1"></i> Clients</div></div><div class="w-12 h-12 bg-white/20 backdrop-blur rounded-xl flex items-center justify-center text-xl"><i class="fas fa-user-plus"></i></div></div></div>
            <div class="dashboard-card card-gradient-emerald"><i class="fas fa-piggy-bank card-bg-icon"></i><div class="flex justify-between items-start relative z-10"><div><div class="text-sm font-semibold uppercase opacity-90">Total Savings</div><div class="text-3xl font-extrabold mt-1 mb-2">₦<span class="count-up" data-target="<?php echo $total_savings_amount; ?>" data-currency="true">0</span></div><div class="text-xs bg-black/20 rounded-full px-3 py-1 inline-flex items-center"><i class="fas fa-chart-line mr-1"></i> All Time</div></div><div class="w-12 h-12 bg-white/20 backdrop-blur rounded-xl flex items-center justify-center text-xl"><i class="fas fa-coins"></i></div></div></div>
            <div class="dashboard-card card-gradient-blue"><i class="fas fa-calendar-check card-bg-icon"></i><div class="flex justify-between items-start relative z-10"><div><div class="text-sm font-semibold uppercase opacity-90">Collected Month</div><div class="text-3xl font-extrabold mt-1 mb-2">₦<span class="count-up" data-target="<?php echo $personal_month_total; ?>" data-currency="true">0</span></div><div class="text-xs bg-black/20 rounded-full px-3 py-1 inline-flex items-center"><i class="fas fa-calendar-day mr-1"></i> Performance</div></div><div class="w-12 h-12 bg-white/20 backdrop-blur rounded-xl flex items-center justify-center text-xl"><i class="fas fa-wallet"></i></div></div></div>
        </div>
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            <div class="lg:col-span-1">
                <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm border border-gray-200 dark:border-slate-700 sticky top-24">
                    <div class="p-5 border-b border-gray-100 dark:border-slate-700"><h2 class="text-lg font-bold flex items-center gap-2 text-gray-800 dark:text-white"><i class="fas fa-plus-circle text-green-500"></i> Record Savings</h2></div>
                    <form id="savingsForm" class="p-5 space-y-5">
                        <input type="hidden" name="ajax" value="1">
                        <div><label class="text-xs font-bold text-gray-500 uppercase mb-2 block">Union Filter</label><div id="union-tags" class="flex flex-wrap gap-2"></div></div>
                        
                        <!-- START: UPDATED CLIENT DROPDOWN -->
                        <div>
                            <label class="text-xs font-bold text-gray-500 uppercase mb-1 block">Client</label>
                            <div class="input-group">
                                <i class="fas fa-user absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 pointer-events-none"></i>
                                <select id="client_id" name="client_id" required class="input-field cursor-pointer pl-8">
                                    <option value="">Select Client...</option>
                                </select>
                                <i class="fas fa-chevron-down absolute right-3 top-1/2 -translate-y-1/2 text-xs text-gray-400 pointer-events-none"></i>
                            </div>
                        </div>
                        <!-- END: UPDATED CLIENT DROPDOWN -->
                        
                        <div id="client-info-card" class="hidden rounded-xl border border-gray-100 dark:border-slate-700 overflow-hidden"><div class="px-3 py-2 text-xs font-bold text-white bg-gray-500 flex justify-between"><span>Financial Snapshot</span><span class="bg-black/20 px-2 rounded-full">Live</span></div><div class="p-3 bg-gray-50 dark:bg-slate-900/50 grid grid-cols-2 gap-2 text-sm"><div><span class="text-[10px] text-gray-400 uppercase">Savings Balance</span><div id="info-savings" class="font-bold text-green-600">₦0</div></div><div class="text-right"><span class="text-[10px] text-gray-400 uppercase">Loan Balance</span><div id="info-loan" class="font-bold text-red-600">₦0</div></div></div><div class="px-3 pb-3 bg-gray-50 dark:bg-slate-900/50"><div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-1.5 mb-1"><div id="info-progress" class="bg-green-500 h-1.5 rounded-full transition-all duration-500" style="width: 0%"></div></div><div class="flex justify-between text-[10px]"><span class="text-gray-400">Loan Coverage</span><span class="font-bold text-green-600" id="info-coverage">0%</span></div></div></div>
                        <div><label class="text-xs font-bold text-gray-500 uppercase mb-1 block">Amount</label><div class="input-group flex items-center"><span class="text-gray-400 mr-2">₦</span><input type="number" id="amount" name="amount" min="<?php echo $min_savings_amount; ?>" max="<?php echo $max_savings_amount; ?>" step="0.01" required class="input-field font-bold p-0" placeholder="<?php echo number_format($min_savings_amount); ?> - <?php echo number_format($max_savings_amount); ?>"></div><div class="text-xs text-gray-400 mt-1">Min: ₦<?php echo number_format($min_savings_amount); ?> | Max: ₦<?php echo number_format($max_savings_amount); ?></div></div>
                        <div><div class="flex justify-between items-center mb-1"><label class="text-xs font-bold text-gray-500 uppercase block">Date</label><button type="button" id="btn-set-now" class="text-[10px] font-bold text-green-600 hover:underline">SET NOW</button></div><div class="input-group"><input type="datetime-local" id="date" name="date" value="<?php echo date('Y-m-d\TH:i'); ?>" class="input-field text-sm p-0" <?php echo $savings_date_readonly ? 'readonly' : ''; ?>></div><?php if (!$allow_weekend_collection): ?><div class="text-xs text-orange-500 mt-1"><i class="fas fa-exclamation-triangle"></i> Weekend collection disabled</div><?php endif; ?></div>
                        <button type="submit" id="submitBtn" class="w-full py-3.5 bg-green-600 hover:bg-green-700 text-white rounded-xl font-bold shadow-lg shadow-green-500/30 transition transform active:scale-95">Record Savings</button>
                    </form>
                </div>
            </div>
            <div class="lg:col-span-2">
                <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm border border-gray-200 dark:border-slate-700 min-h-[500px] flex flex-col">
                    <div class="p-5 border-b border-gray-100 dark:border-slate-700 flex justify-between items-center"><h2 class="text-lg font-bold text-gray-800 dark:text-white">Recent Transactions</h2><div class="flex gap-2"><select id="history-union-filter" class="text-xs bg-gray-100 dark:bg-slate-700 border-none rounded-lg px-2 py-1 outline-none"><option value="all">All Unions</option></select><button class="filter-date-btn text-xs px-3 py-1 text-gray-500 hover:bg-gray-100 dark:hover:bg-slate-700 rounded-lg" data-val="all">All</button><button class="filter-date-btn active text-xs px-3 py-1 bg-green-100 text-green-600 rounded-lg font-bold" data-val="current_month">This Month</button><button class="filter-date-btn text-xs px-3 py-1 text-gray-500 hover:bg-gray-100 dark:hover:bg-slate-700 rounded-lg" data-val="today">Today</button></div></div>
                    <div id="history-container" class="p-4 flex-1 overflow-y-auto max-h-[600px]"><div class="text-center py-10 text-gray-400">Loading...</div></div>
                    <div class="p-4 border-t border-gray-100 dark:border-slate-700 text-center flex justify-between items-center"><span class="text-xs text-gray-500" id="pagination-info">...</span><div class="flex gap-2"><button id="prev-page" class="w-8 h-8 flex items-center justify-center rounded-lg border border-gray-200 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-slate-700 text-xs"><i class="fas fa-chevron-left"></i></button><button id="next-page" class="w-8 h-8 flex items-center justify-center rounded-lg border border-gray-200 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-slate-700 text-xs"><i class="fas fa-chevron-right"></i></button></div></div>
                </div>
            </div>
        </div>
    </main>
    <nav class="mobile-bottom-nav">
        <a href="dashboard.php" class="nav-item"><i class="fas fa-home"></i><span>Home</span></a>
        <a href="saving_collection.php" class="nav-item active"><div style="background: var(--success-color); width: 45px; height: 45px; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; margin-top: -25px; box-shadow: 0 4px 10px rgba(34, 197, 94, 0.4);"><i class="fas fa-piggy-bank" style="font-size: 1.2rem; margin:0;"></i></div><span style="font-size:0.65rem; font-weight:600; margin-top:2px;">Save</span></a>
        <a href="disbursement.php" class="nav-item"><i class="fas fa-hand-holding-usd"></i><span>Disburse</span></a>
        <a href="loan_collection.php" class="nav-item"><i class="fas fa-money-bill-wave"></i><span>Repay</span></a>
        <a href="clients.php" class="nav-item"><i class="fas fa-users"></i><span>Clients</span></a>
    </nav>
    <script>
        const clientsByUnion = <?php echo $js_clients_by_union; ?>;
        const savingsByClient = <?php echo $js_savings_by_client; ?>;
        const assignedUnions = <?php echo $js_assigned_unions; ?>;
        const currentUser = "<?php echo $username; ?>";
        const GREEN_COLOR = '#22c55e';
        const minSavingsAmount = <?php echo $min_savings_amount; ?>;
        const maxSavingsAmount = <?php echo $max_savings_amount; ?>;
        const allowWeekendCollection = <?php echo $allow_weekend_collection ? 'true' : 'false'; ?>;
        const savingsDateReadonly = <?php echo $savings_date_readonly ? 'true' : 'false'; ?>;

        let state = { currentUnion: null, unionFilter: 'all', dateFilter: 'current_month', currentPage: 1, limit: 10, isLoading: false };
        const els = { unionTags: document.getElementById('union-tags'), clientSelect: document.getElementById('client_id'), clientInfoCard: document.getElementById('client-info-card'), infoSavings: document.getElementById('info-savings'), infoLoan: document.getElementById('info-loan'), infoProgress: document.getElementById('info-progress'), infoCoverage: document.getElementById('info-coverage'), amountInput: document.getElementById('amount'), historyContainer: document.getElementById('history-container'), form: document.getElementById('savingsForm'), submitBtn: document.getElementById('submitBtn'), paginationInfo: document.getElementById('pagination-info'), prevPage: document.getElementById('prev-page'), nextPage: document.getElementById('next-page'), historyUnionFilter: document.getElementById('history-union-filter') };
        const formatCurrency = (a) => '₦' + parseFloat(a).toLocaleString('en-US', {minimumFractionDigits: 0, maximumFractionDigits: 0});

        function showToast(msg, type) { const el = document.createElement('div'); el.className = 'toast'; el.style.borderLeftColor = type === 'success' ? '#22c55e' : '#ef4444'; el.innerHTML = `<i class="fas ${type==='success'?'fa-check-circle text-green-500':'fa-exclamation-circle text-red-500'}"></i> <span class="text-sm font-medium text-gray-800 dark:text-gray-200">${msg}</span>`; document.getElementById('toast-container').appendChild(el); setTimeout(() => { el.style.opacity = '0'; setTimeout(() => el.remove(), 300); }, 3000); }
        function initUnions() { const unions = Object.keys(clientsByUnion); els.unionTags.innerHTML = ''; if (unions.length === 0) { els.unionTags.innerHTML = '<span class="text-xs italic text-gray-400">No assigned unions</span>'; return; } unions.forEach((u, i) => { const btn = document.createElement('button'); btn.type = 'button'; btn.className = `px-3 py-1 rounded-full text-xs font-bold border transition ${i === 0 ? 'bg-green-600 text-white border-green-600' : 'bg-white text-gray-500 border-gray-200 hover:bg-gray-50 dark:bg-slate-700 dark:text-gray-300 dark:border-slate-600'}`; btn.textContent = u; btn.onclick = () => selectUnion(u, btn); els.unionTags.appendChild(btn); if (i === 0) selectUnion(u, btn); }); }
        function selectUnion(u, btn) { state.currentUnion = u; Array.from(els.unionTags.children).forEach(c => c.className = 'px-3 py-1 rounded-full text-xs font-bold border transition bg-white text-gray-500 border-gray-200 hover:bg-gray-50 dark:bg-slate-700 dark:text-gray-300 dark:border-slate-600'); btn.className = 'px-3 py-1 rounded-full text-xs font-bold border transition bg-green-600 text-white border-green-600'; populateClients(u); }
        function populateClients(u) { els.clientSelect.innerHTML = '<option value="">Select Client...</option>'; (clientsByUnion[u] || []).forEach(c => { els.clientSelect.innerHTML += `<option value="${c.id}">${c.name}</option>`; }); hideInfo(); }
        function hideInfo() { els.clientInfoCard.classList.add('hidden'); }
        async function loadClientFinancialData(cid) { try { const r = await fetch(`?ajax_action=get_client_financial_data&client_id=${cid}`); const d = await r.json(); if (d.success) { els.infoSavings.textContent = formatCurrency(d.savings_balance); els.infoLoan.textContent = formatCurrency(d.loan_balance); savingsByClient[cid] = d.savings_balance; const c = d.loan_balance > 0 ? Math.min(100, (d.savings_balance / d.loan_balance) * 100) : 100; els.infoProgress.style.width = c + '%'; els.infoCoverage.textContent = Math.round(c) + '%'; } } catch (e) { console.error(e); } }
        els.clientSelect.addEventListener('change', async (e) => { const cid = e.target.value; if (!cid) { hideInfo(); return; } els.clientInfoCard.classList.remove('hidden'); els.infoSavings.textContent = savingsByClient[cid] ? formatCurrency(savingsByClient[cid]) : '...'; await loadClientFinancialData(cid); });
        async function loadTransactions(page = 1) { if (state.isLoading) return; state.isLoading = true; els.historyContainer.innerHTML = '<div class="text-center py-10 flex flex-col items-center opacity-50"><i class="fas fa-spinner fa-spin text-2xl mb-2"></i><span>Loading...</span></div>'; try { const r = await fetch(`?ajax_action=get_transactions&page=${page}&limit=${state.limit}&union=${state.unionFilter}&date_filter=${state.dateFilter}`); const d = await r.json(); if (d.success) { renderFeed(d.data); updatePagination(d.pagination); } } catch (e) { console.error(e); } finally { state.isLoading = false; } }
        function renderFeed(rows) { els.historyContainer.innerHTML = ''; if (rows.length === 0) { els.historyContainer.innerHTML = '<div class="text-center py-10 flex flex-col items-center opacity-50"><i class="fas fa-inbox text-4xl mb-2"></i><span>No transactions found</span></div>'; return; } rows.forEach(i => { els.historyContainer.insertAdjacentHTML('beforeend', `<div class="activity-card"><div class="ac-icon-box" style="background: ${GREEN_COLOR}15; color: ${GREEN_COLOR};"><i class="fas fa-piggy-bank"></i></div><div class="ac-content"><div class="ac-top"><div class="client-name">${i.client_name}</div><div class="activity-amount" style="color: ${GREEN_COLOR};">₦${parseInt(i.amount_collected).toLocaleString()}</div></div><div class="ac-bottom"><span style="font-size:0.65rem; font-weight:700; background:${GREEN_COLOR}15; color:${GREEN_COLOR}; padding:2px 6px; rounded:4px;">DEPOSIT</span><div class="activity-date"><i class="far fa-clock"></i> ${i.date.substring(5,16)}</div></div></div></div>`); }); }
        function updatePagination(m) { state.currentPage = m.current_page; const s = m.total_records > 0 ? ((m.current_page-1)*m.limit)+1 : 0; els.paginationInfo.textContent = `${s}-${Math.min(m.total_records, m.current_page*m.limit)} of ${m.total_records}`; els.prevPage.disabled = !m.has_prev; els.nextPage.disabled = !m.has_next; els.prevPage.onclick = () => loadTransactions(m.current_page - 1); els.nextPage.onclick = () => loadTransactions(m.current_page + 1); }
        els.form.addEventListener('submit', async (e) => { e.preventDefault(); const amt = parseFloat(els.amountInput.value); const dv = document.getElementById('date').value; if (amt < minSavingsAmount) { showToast(`Amount must be at least ₦${minSavingsAmount.toLocaleString()}`, 'error'); return; } if (amt > maxSavingsAmount) { showToast(`Amount cannot exceed ₦${maxSavingsAmount.toLocaleString()}`, 'error'); return; } if (!allowWeekendCollection && dv) { const sd = new Date(dv); if (sd.getDay() === 0 || sd.getDay() === 6) { showToast('Savings collection not allowed on weekends', 'error'); return; } } const btn = els.submitBtn; const ot = btn.innerHTML; btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...'; try { const fd = new FormData(els.form); const r = await fetch(window.location.href, { method: 'POST', body: fd }); const rs = await r.json(); if (rs.success) { showToast(rs.message, 'success'); const cid = fd.get('client_id'); const am = parseFloat(fd.get('amount')); document.querySelector('.card-gradient-emerald .count-up').dataset.target = parseFloat(document.querySelector('.card-gradient-emerald .count-up').dataset.target) + am; if (!savingsByClient[cid] || savingsByClient[cid] === 0) document.querySelector('.card-gradient-green .count-up').dataset.target = parseFloat(document.querySelector('.card-gradient-green .count-up').dataset.target) + 1; document.querySelector('.card-gradient-blue .count-up').dataset.target = parseFloat(document.querySelector('.card-gradient-blue .count-up').dataset.target) + am; animateNumbers(); if (cid) { savingsByClient[cid] = (savingsByClient[cid] || 0) + am; if (els.clientSelect.value === cid) { els.infoSavings.textContent = formatCurrency(savingsByClient[cid]); loadClientFinancialData(cid); } } els.amountInput.value = ""; loadTransactions(1); } else { showToast(rs.message, 'error'); } } catch (e) { showToast('Connection error', 'error'); } finally { btn.disabled = false; btn.innerHTML = ot; } });
        if (assignedUnions.length > 0) { assignedUnions.forEach(u => els.historyUnionFilter.innerHTML += `<option value="${u}">${u}</option>`); els.historyUnionFilter.addEventListener('change', (e) => { state.unionFilter = e.target.value; loadTransactions(1); }); }
        document.querySelectorAll('.filter-date-btn').forEach(btn => { btn.addEventListener('click', (e) => { document.querySelectorAll('.filter-date-btn').forEach(b => { b.className = 'filter-date-btn text-xs px-3 py-1 text-gray-500 hover:bg-gray-100 dark:hover:bg-slate-700 rounded-lg transition'; }); e.target.className = 'filter-date-btn active text-xs px-3 py-1 bg-green-100 text-green-600 rounded-lg font-bold transition'; state.dateFilter = e.target.dataset.val; loadTransactions(1); }); });
        document.getElementById('btn-set-now').onclick = () => { if (!savingsDateReadonly) { const n = new Date(); n.setMinutes(n.getMinutes() - n.getTimezoneOffset()); document.getElementById('date').value = n.toISOString().slice(0,16); } };
        function animateNumbers() { document.querySelectorAll('.count-up').forEach(el => { const t = parseFloat(el.dataset.target); const c = el.dataset.currency; let s = 0; const anim = (ts) => { if(!s) s=ts; const p = Math.min((ts-s)/1000, 1); el.innerText = c ? Math.round(p*t).toLocaleString() : Math.round(p*t); if(p<1) requestAnimationFrame(anim); }; requestAnimationFrame(anim); }); }
        const th = localStorage.getItem('theme'); if(th==='dark') document.documentElement.classList.add('dark');
        document.getElementById('theme-toggle').onclick = () => { document.documentElement.classList.toggle('dark'); localStorage.setItem('theme', document.documentElement.classList.contains('dark') ? 'dark' : 'light'); };
        initUnions(); loadTransactions(); animateNumbers();
    </script>
</body>
</html>