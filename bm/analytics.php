<?php
// bm/analytics.php - Branch Manager Analytics Dashboard
session_start();
require_once '../includes/config.php';

try {
    $conn = getDbConnection();
} catch (Exception $e) {
    die("Database Connection Failed: " . $e->getMessage());
}

$base_path = '../';

// --- AUTHENTICATION ---
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'bm') {
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Unauthorized']);
        exit();
    }
    header('Location: ../index.php');
    exit();
}

$bm_username = $_SESSION['username'] ?? '';
$user_id = $_SESSION['user_id'] ?? '';

// Fetch the BM's branch_id and branch_name
$stmt_b = $conn->prepare("SELECT u.branch_id, b.name as branch_name FROM users u JOIN branches b ON u.branch_id = b.id WHERE u.id = ?");
$stmt_b->execute([$user_id]);
$branch_info = $stmt_b->fetch(PDO::FETCH_ASSOC);

$branch_id = $branch_info['branch_id'] ?? '';
$bm_branch_name = $branch_info['branch_name'] ?? 'My Branch';

// Fetch all active COs in this branch for the filter dropdown
$co_list =[];
$stmt_cos = $conn->prepare("SELECT username, full_name FROM users WHERE branch_id = ? AND role = 'co' AND status = 'active' ORDER BY full_name ASC");
$stmt_cos->execute([$branch_id]);
while ($row = $stmt_cos->fetch(PDO::FETCH_ASSOC)) {
    $co_list[] = $row;
}

// Fetch all unique unions in this branch
$assigned_unions =[];
$stmt_unions = $conn->prepare("SELECT DISTINCT `union` FROM clients WHERE branch_id = ? AND `union` IS NOT NULL AND `union` != ''");
$stmt_unions->execute([$branch_id]);
while ($row = $stmt_unions->fetch(PDO::FETCH_ASSOC)) {
    $assigned_unions[] = $row['union'];
}
sort($assigned_unions);

// --- HYBRID CACHE SYSTEM ---
function get_cache_key($branch_id, $date_from, $date_to, $search = '') {
    return md5($branch_id . $date_from . $date_to . $search . 'v_bm_analytics_v2');
}

function get_cached_data($cache_key, $cache_dir) {
    $cache_file = $cache_dir . $cache_key . '.json';
    if (file_exists($cache_file) && (time() - filemtime($cache_file)) < 300) { // 300s = 5min
        return json_decode(file_get_contents($cache_file), true);
    }
    return null;
}

function save_to_cache($data, $cache_key, $cache_dir) {
    if (!is_dir($cache_dir)) mkdir($cache_dir, 0755, true);
    $file = $cache_dir . $cache_key . '.json';
    file_put_contents($file, json_encode($data));
}

// --- HELPER: FIXED PUBLIC HOLIDAYS ---
function is_holiday($date_obj) {
    $md = $date_obj->format('m-d');
    $holidays =['01-01', '05-01', '06-12', '10-01', '12-25', '12-26'];
    return in_array($md, $holidays);
}

// --- HELPER: WORKING DAYS CALCULATOR ---
function calculate_due_date($start_date_str, $working_days = 26) {
    $date = new DateTime($start_date_str);
    $date->setTime(0, 0, 0); 
    $count = 0;
    while ($count < $working_days) {
        $date->modify('+1 day');
        $day_of_week = $date->format('N'); 
        if ($day_of_week < 6 && !is_holiday($date)) {
            $count++;
        }
    }
    return $date;
}

// --- 2. DATA LOADING (HYBRID) ---
$cache_dir = $base_path . 'cache/';
$req_date_from = $_GET['date_from'] ?? date('Y-m-01');
$req_date_to = $_GET['date_to'] ?? date('Y-m-d');
$req_search = $_GET['search'] ?? '';

$cache_key = get_cache_key($branch_id, $req_date_from, $req_date_to, $req_search);
$cached_data = get_cached_data($cache_key, $cache_dir); // Set to null to debug cache misses

if ($cached_data) {
    $branch_clients = $cached_data['branch_clients'];
    $branch_savings = $cached_data['branch_savings'];
    $branch_disbursements = $cached_data['branch_disbursements'];
    $branch_collections = $cached_data['branch_collections'];
    $branch_registrations = $cached_data['branch_registrations'];
} else {
    $officer_usernames = array_column($co_list, 'username');
    $placeholders = !empty($officer_usernames) ? implode(',', array_fill(0, count($officer_usernames), '?')) : "''";

    // 1. Clients
    $branch_clients =[];
    $stmt = $conn->prepare("SELECT id, name, `union`, status, officer_username FROM clients WHERE branch_id = ?");
    $stmt->execute([$branch_id]);
    $branch_clients = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 2. Savings (All time for balance calculation)
    $branch_savings =[];
    if (!empty($officer_usernames)) {
        $stmt = $conn->prepare("SELECT client_id, amount, date, type, officer FROM saving_collections WHERE officer IN ($placeholders)");
        $stmt->execute($officer_usernames);
        $branch_savings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // 3. Disbursements (All time for balance calculation)
    $branch_disbursements =[];
    if (!empty($officer_usernames)) {
        $stmt = $conn->prepare("SELECT id, client_id, principal as principal_amount, total_payable, remaining_balance, date, payoff_date, officer FROM disbursements WHERE officer IN ($placeholders)");
        $stmt->execute($officer_usernames);
        $branch_disbursements = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // 4. Collections
    $branch_collections =[];
     if (!empty($officer_usernames)) {
        $stmt = $conn->prepare("SELECT client_id, amount_collected, date, officer FROM loan_collections WHERE officer IN ($placeholders)");
        $stmt->execute($officer_usernames);
        $branch_collections = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // 5. Registrations
    $branch_registrations =[];
    if (!empty($officer_usernames)) {
        $stmt = $conn->prepare("SELECT client_id, `union`, amount, date, officer FROM registrations WHERE officer IN ($placeholders)");
        $stmt->execute($officer_usernames);
        $client_map = array_column($branch_clients, 'name', 'id');
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $row['client_name'] = $client_map[$row['client_id']] ?? 'Unknown';
            $branch_registrations[] = $row;
        }
    }
    
    save_to_cache([
        'branch_clients' => $branch_clients,
        'branch_savings' => $branch_savings,
        'branch_disbursements' => $branch_disbursements,
        'branch_collections' => $branch_collections,
        'branch_registrations' => $branch_registrations
    ], $cache_key, $cache_dir);
}

// --- 4. DATE FILTERING LOGIC ---
$preset = $_GET['preset'] ?? 'month'; 
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-d');

if ($preset) {
    switch ($preset) {
        case 'today': $date_from = $date_to = date('Y-m-d'); break;
        case 'week': $date_from = date('Y-m-d', strtotime('monday this week')); $date_to = date('Y-m-d'); break;
        case 'month': $date_from = date('Y-m-01'); $date_to = date('Y-m-d'); break;
        case 'last_month':
            $date_from = date('Y-m-01', strtotime('last month'));
            $date_to = date('Y-m-t', strtotime('last month'));
            break;
    }
}

function filter_by_date_range($data, $date_from, $date_to) {
    return array_filter($data, function($item) use ($date_from, $date_to) {
        $item_date = $item['date'] ?? '';
        if (empty($item_date)) return false;
        $timestamp = strtotime($item_date);
        if (!$timestamp) return false;
        $item_date_only = date('Y-m-d', $timestamp);
        return $item_date_only >= $date_from && $item_date_only <= $date_to;
    });
}

// Apply Filters
$filtered_savings = filter_by_date_range($branch_savings, $date_from, $date_to);
$filtered_disbursements = filter_by_date_range($branch_disbursements, $date_from, $date_to);
$filtered_collections = filter_by_date_range($branch_collections, $date_from, $date_to);
$filtered_registrations = filter_by_date_range($branch_registrations, $date_from, $date_to);

// --- 5. METRICS CALCULATION (BRANCH WIDE) ---
$total_clients = count(array_filter($branch_clients, fn($c) => $c['status'] === 'active'));
$today_obj = new DateTime(); $today_obj->setTime(0,0,0);

$savings_deposits = 0; $savings_withdrawals = 0;
foreach ($filtered_savings as $s) {
    $amt = floatval($s['amount'] ?? 0);
    $type = strtolower($s['type'] ?? '');
    if ($amt < 0 || $type === 'withdrawal' || $type === 'return' || $type === 'adjust') {
        $savings_withdrawals += abs($amt);
    } else {
        $savings_deposits += $amt;
    }
}
$net_savings = $savings_deposits - $savings_withdrawals;
$total_disbursements = array_sum(array_column($filtered_disbursements, 'principal_amount'));
$total_collections = array_sum(array_column($filtered_collections, 'amount_collected'));
$total_registrations = count($filtered_registrations);
$total_service_charges = 0;
foreach ($filtered_disbursements as $d) {
    $total_service_charges += max(0, floatval($d['total_payable'] ?? 0) - floatval($d['principal_amount'] ?? 0));
}
$active_loans = 0; $total_outstanding = 0; $total_overdue = 0;
foreach ($branch_disbursements as $loan) {
    $rem = floatval($loan['remaining_balance'] ?? 0);
    if ($rem > 0.01) { 
        $active_loans++;
        $total_outstanding += $rem;
        $due_date_obj = calculate_due_date($loan['date'], 26);
        if ($today_obj > $due_date_obj) $total_overdue += $rem;
    }
}
$par_ratio = $total_outstanding > 0 ? ($total_overdue / $total_outstanding) * 100 : 0;

// --- 6. AJAX HANDLER (API) ---
if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    header('Content-Type: application/json');
    $action = $_GET['action'] ?? 'main';
    $aj_from = $_GET['date_from'] ?? date('Y-m-d');
    $aj_to = $_GET['date_to'] ?? date('Y-m-d');
    
    // 6a. Officer Details (Union-level breakdown)
    if ($action === 'co_details') {
        $co_username = $_GET['co_username'] ?? '';
        $officer_clients = array_filter($branch_clients, fn($c) => $c['officer_username'] === $co_username);
        $officer_disbursements_all = array_filter($branch_disbursements, fn($d) => $d['officer'] === $co_username);
        
        $ajax_filtered_collections = array_filter($branch_collections, fn($c) => $c['officer'] === $co_username && $c['date'] >= $aj_from && $c['date'] <= $aj_to." 23:59:59");
        $ajax_filtered_savings = array_filter($branch_savings, fn($s) => $s['officer'] === $co_username && $s['date'] >= $aj_from && $s['date'] <= $aj_to." 23:59:59");
        $ajax_filtered_registrations = array_filter($branch_registrations, fn($r) => $r['officer'] === $co_username && $r['date'] >= $aj_from && $r['date'] <= $aj_to." 23:59:59");

        $officer_unions = array_unique(array_column($officer_clients, 'union'));
        sort($officer_unions);
        
        $union_breakdown =[];
        foreach ($officer_unions as $union) {
            if (empty($union)) continue;
            $u_clients = array_filter($officer_clients, fn($c) => ($c['union'] ?? '') === $union);
            $u_ids = array_column($u_clients, 'id');
            $u_savings = array_filter($ajax_filtered_savings, fn($s) => in_array($s['client_id'], $u_ids));
            $u_dep = 0; $u_wit = 0;
            foreach ($u_savings as $s) {
                $amt = floatval($s['amount'] ?? 0); $type = strtolower($s['type'] ?? '');
                if ($amt < 0 || $type === 'withdrawal' || $type === 'return' || $type === 'adjust') $u_wit += abs($amt);
                else $u_dep += $amt;
            }
            $u_coll = array_filter($ajax_filtered_collections, fn($c) => in_array($c['client_id'], $u_ids));
            $overdue_count = 0;
            foreach ($u_clients as $client) {
                $c_loans = array_filter($officer_disbursements_all, fn($d) => $d['client_id'] === $client['id']);
                 foreach ($c_loans as $loan) {
                    if (floatval($loan['remaining_balance'] ?? 0) > 0.01) {
                        if ($today_obj > calculate_due_date($loan['date'], 26)) { $overdue_count++; break; }
                    }
                }
            }
            $union_breakdown[] =['name' => $union, 'clients' => count($u_clients), 'total_savings' => $u_dep, 'withdrawals' => $u_wit, 'collections' => array_sum(array_column($u_coll, 'amount_collected')), 'registrations' => count(array_filter($ajax_filtered_registrations, fn($r) => ($r['union'] ?? '') === $union)), 'overdue_count' => $overdue_count];
        }
        echo json_encode(['unions' => $union_breakdown]);
        exit();
    }
    
    // 6b. NEW: Union Details (Client-level breakdown)
    if ($action === 'union_details') {
        $co_username = $_GET['co_username'] ?? '';
        $union_name = $_GET['union_name'] ?? '';
        $client_search = isset($_GET['q']) ? strtolower(trim($_GET['q'])) : '';

        // Pre-filter data for the specific officer for performance
        $co_savings_all = array_filter($branch_savings, fn($s) => $s['officer'] === $co_username);
        $co_disbursements_all = array_filter($branch_disbursements, fn($d) => $d['officer'] === $co_username);
        $ajax_filtered_collections = array_filter($branch_collections, fn($c) => $c['officer'] === $co_username && $c['date'] >= $aj_from && $c['date'] <= $aj_to." 23:59:59");
        $ajax_filtered_savings = array_filter($branch_savings, fn($s) => $s['officer'] === $co_username && $s['date'] >= $aj_from && $s['date'] <= $aj_to." 23:59:59");
        $ajax_filtered_disbursements = array_filter($branch_disbursements, fn($d) => $d['officer'] === $co_username && $d['date'] >= $aj_from && $d['date'] <= $aj_to." 23:59:59");
        
        $target_clients = array_filter($branch_clients, fn($c) => $c['officer_username'] === $co_username && $c['union'] === $union_name);
        
        $client_details =[];
        foreach ($target_clients as $client) {
            if ($client_search && (strpos(strtolower($client['name']), $client_search) === false && strpos(strtolower($client['id']), $client_search) === false)) continue;
            
            $cid = $client['id'];
            $savings_bal = 0;
            foreach (array_filter($co_savings_all, fn($s) => $s['client_id'] === $cid) as $s) {
                $amt = floatval($s['amount']); $type = strtolower($s['type'] ?? '');
                if ($amt < 0 || $type === 'withdrawal' || $type === 'return' || $type === 'adjust') $savings_bal -= abs($amt); else $savings_bal += $amt;
            }

            $outstanding_bal = 0; $days_overdue = 0; $is_paid_in_period = false;
            foreach (array_filter($co_disbursements_all, fn($d) => $d['client_id'] === $cid) as $loan) {
                $rem = floatval($loan['remaining_balance'] ?? 0);
                if ($rem > 0.01) {
                    $outstanding_bal = $rem;
                    $due_date_obj = calculate_due_date($loan['date'], 26);
                    if ($today_obj > $due_date_obj) $days_overdue = $today_obj->diff($due_date_obj)->days;
                }
                $payoff_date = $loan['payoff_date'] ?? null;
                if ($rem <= 0.01 && $payoff_date && $payoff_date >= $aj_from && $payoff_date <= $aj_to) $is_paid_in_period = true;
            }
            
            $range_savings = 0; $range_withdrawals = 0;
            foreach (array_filter($ajax_filtered_savings, fn($s) => $s['client_id'] === $cid) as $s) {
                $amt = floatval($s['amount'] ?? 0); $type = strtolower($s['type'] ?? '');
                if ($amt < 0 || $type === 'withdrawal' || $type === 'return' || $type === 'adjust') $range_withdrawals += abs($amt); else $range_savings += $amt;
            }

            $range_collected = array_sum(array_column(array_filter($ajax_filtered_collections, fn($c) => $c['client_id'] === $cid), 'amount_collected'));
            $range_disbursed = array_sum(array_column(array_filter($ajax_filtered_disbursements, fn($d) => $d['client_id'] === $cid), 'principal_amount'));
            
            $client_details[] = ['name' => $client['name'], 'id' => $cid, 'status' => $client['status'], 'savings_balance' => $savings_bal, 'loan_outstanding' => $outstanding_bal, 'is_fully_paid' => $is_paid_in_period, 'days_overdue' => $days_overdue, 'range_savings' => $range_savings, 'range_withdrawals' => $range_withdrawals, 'range_collected' => $range_collected, 'range_disbursed' => $range_disbursed];
        }
        echo json_encode(['clients' => $client_details]);
        exit();
    }
    
    // 6c. Main AJAX call for dashboard stats & CO table
    if ($action === 'main') {
        $co_stats =[];
        foreach ($co_list as $co) {
            $co_user = $co['username'];
            $officer_clients = array_filter($branch_clients, fn($c) => $c['officer_username'] === $co_user);
            $officer_client_ids = array_column($officer_clients, 'id');
            
            $co_savings = array_filter($filtered_savings, fn($s) => in_array($s['client_id'], $officer_client_ids));
            $co_disb = array_filter($filtered_disbursements, fn($d) => in_array($d['client_id'], $officer_client_ids));
            $co_coll = array_filter($filtered_collections, fn($c) => in_array($c['client_id'], $officer_client_ids));
            $co_regs = array_filter($filtered_registrations, fn($r) => in_array($r['client_id'], $officer_client_ids));

            $co_dep = 0; $co_wit = 0;
            foreach ($co_savings as $s) {
                $amt = floatval($s['amount'] ?? 0); $type = strtolower($s['type'] ?? '');
                if ($amt < 0 || $type === 'withdrawal' || $type === 'return' || $type === 'adjust') $co_wit += abs($amt); else $co_dep += $amt;
            }

            $paid_off_count = 0; $overdue_count = 0;
            $all_officer_loans = array_filter($branch_disbursements, fn($d) => $d['officer'] === $co_user);
            foreach ($officer_clients as $client) {
                $c_loans = array_filter($all_officer_loans, fn($d) => $d['client_id'] === $client['id']);
                $has_payoff = false; $is_overdue = false;
                foreach ($c_loans as $loan) {
                    $rem = floatval($loan['remaining_balance'] ?? 0);
                    if ($rem <= 0.01 && ($loan['payoff_date']??null) >= $aj_from && ($loan['payoff_date']??null) <= $aj_to) $has_payoff = true;
                    if ($rem > 0.01 && ($today_obj > calculate_due_date($loan['date'], 26))) $is_overdue = true;
                }
                if ($has_payoff) $paid_off_count++;
                if ($is_overdue) $overdue_count++;
            }
            $co_stats[] =['username' => $co['username'], 'name' => $co['full_name'], 'clients' => count($officer_clients), 'total_savings' => $co_dep, 'withdrawals' => abs($co_wit), 'net_savings' => $co_dep - $co_wit, 'disbursements' => array_sum(array_column($co_disb, 'principal_amount')), 'collections' => array_sum(array_column($co_coll, 'amount_collected')), 'registrations' => count($co_regs), 'paid_off' => $paid_off_count, 'overdue_count' => $overdue_count];
        }

        $daily_data =[];
        $period = new DatePeriod(new DateTime($date_from), new DateInterval('P1D'), (new DateTime($date_to))->modify('+1 day'));
        foreach ($period as $dt) {
            $day = $dt->format('Y-m-d');
            $day_coll_sum = 0;
            foreach($filtered_collections as $c) if(strpos($c['date']??'', $day) === 0) $day_coll_sum += floatval($c['amount_collected']);
            $day_sav_sum = 0;
            foreach($filtered_savings as $s) if(strpos($s['date']??'', $day) === 0 && floatval($s['amount']??0) > 0) $day_sav_sum += floatval($s['amount']);
            $daily_data[] =['date' => $dt->format('M d'), 'savings' => $day_sav_sum, 'collections' => $day_coll_sum];
        }

        echo json_encode([
            'metrics' =>['total_clients' => $total_clients, 'total_savings' => $savings_deposits, 'total_withdrawals' => abs($savings_withdrawals), 'net_savings' => $net_savings, 'total_disbursements' => $total_disbursements, 'total_collections' => $total_collections, 'par_ratio' => number_format($par_ratio, 1), 'active_loans' => $active_loans, 'total_outstanding' => $total_outstanding, 'total_service_charges' => $total_service_charges, 'total_registrations' => $total_registrations],
            'chart_daily' => $daily_data,
            'chart_comp' =>['Savings' => $savings_deposits, 'Disbursements' => $total_disbursements, 'Collections' => $total_collections],
            'co_table' => $co_stats,
            'dates' =>['from' => $date_from, 'to' => $date_to]
        ]);
        exit();
    }
}

// User Profile for Header
$stmt = $conn->prepare("SELECT full_name, profile_pic FROM users WHERE username = ?");
$stmt->execute([$bm_username]);
$full_name = 'Branch Manager'; $profile_pic = 'default_avatar.png';
if ($u = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $full_name = $u['full_name'] ?? 'Branch Manager';
    $profile_pic = !empty($u['profile_pic']) ? $u['profile_pic'] : 'default_avatar.png';
}

// --- PREVENT LIGHT MODE FLASH (READ COOKIE) ---
$theme = $_COOKIE['theme'] ?? 'light';
$theme_class = ($theme === 'dark') ? 'dark' : '';
?>
<!DOCTYPE html>
<html lang="en" class="<?php echo $theme_class; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#3b82f6">
    <title>Branch Analytics | CUPAD</title>
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
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>
        :root {
            --primary-color: #3b82f6; --secondary-color: #8b5cf6;
            --success-color: #22c55e; --warning-color: #f59e0b; --error-color: #ef4444;
            --bg-primary: #f0f2f5; --bg-secondary: #ffffff; --bg-card: #ffffff;
            --text-primary: #1f2937; --text-secondary: #6b7280; --border-color: #e5e7eb;
            --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
            --border-radius-lg: 1rem; --border-radius-xl: 1.5rem;
        }
        html.dark {
            --bg-primary: #0f172a; --bg-secondary: #1e293b; --bg-card: #1e293b;
            --text-primary: #f1f5f9; --text-secondary: #94a3b8; --border-color: rgba(255, 255, 255, 0.08);
        }
        body { font-family: 'Inter', sans-serif; background-color: var(--bg-primary); color: var(--text-primary); padding-bottom: 90px; -webkit-tap-highlight-color: transparent; }
        ::-webkit-scrollbar { width: 5px; height: 5px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        html.dark ::-webkit-scrollbar-thumb { background: #334155; }
        
        /* HEADER */
        .main-header { background: rgba(255, 255, 255, 0.95); backdrop-filter: blur(12px); border-bottom: 1px solid var(--border-color); position: sticky; top: 0; z-index: 50; padding-top: env(safe-area-inset-top); }
        html.dark .main-header { background: rgba(15, 23, 42, 0.95); }
        .navbar { display: flex; justify-content: space-between; align-items: center; padding: 0.8rem 1rem; max-width: 1280px; margin: 0 auto; }
        
        /* DASHBOARD GRID */
        .dashboard-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1rem; margin-bottom: 1.5rem; }
        .dashboard-card { border-radius: var(--border-radius-xl); padding: 1.5rem; position: relative; overflow: hidden; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1); color: white; transition: transform 0.2s ease; border: none; }
        .dashboard-card:active { transform: scale(0.98); }
        .card-gradient-blue { background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); }
        .card-gradient-green { background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%); }
        .card-gradient-orange { background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); }
        .card-gradient-red { background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); }
        .card-bg-icon { position: absolute; right: -10px; bottom: -20px; font-size: 6rem; opacity: 0.1; transform: rotate(-10deg); pointer-events: none; }
        
        @media (max-width: 768px) {
            .dashboard-grid { display: flex; overflow-x: auto; scroll-snap-type: x mandatory; gap: 1rem; padding-bottom: 0.5rem; margin-right: -1rem; padding-right: 1.5rem; scrollbar-width: none; }
            .dashboard-grid::-webkit-scrollbar { display: none; }
            .dashboard-card { min-width: 85vw; scroll-snap-align: center; flex-shrink: 0; }
        }

        /* MOBILE NAV */
        .mobile-bottom-nav { display: none; position: fixed; bottom: 0; left: 0; right: 0; background: var(--bg-secondary); border-top: 1px solid var(--border-color); padding: 0.5rem 1rem calc(0.5rem + env(safe-area-inset-bottom)); z-index: 100; justify-content: space-between; box-shadow: 0 -4px 10px rgba(0,0,0,0.05); }
        .nav-item { display: flex; flex-direction: column; align-items: center; justify-content: center; text-decoration: none; color: var(--text-secondary); font-size: 0.7rem; font-weight: 500; gap: 4px; flex: 1; }
        .nav-item i { font-size: 1.4rem; }
        .nav-item.active { color: var(--primary-color); }
        @media (max-width: 768px) { .mobile-bottom-nav { display: flex; } }

        .profile-btn { width: 36px; height: 36px; border-radius: 50%; overflow: hidden; border: 2px solid var(--primary-color); background: var(--bg-secondary); cursor: pointer; display: flex; align-items: center; justify-content: center; position: relative; }
        .profile-btn img { width: 100%; height: 100%; object-fit: cover; }
        .profile-fallback-icon { font-size: 1.2rem; color: var(--text-secondary); }
        .count-up { font-variant-numeric: tabular-nums; }
    </style>
</head>
<body>

    <!-- HEADER -->
    <header class="main-header">
        <nav class="navbar">
            <a href="dashboard.php" class="flex items-center gap-2 no-underline">
                <img src="<?php echo htmlspecialchars($base_path . 'favicon.ico'); ?>" alt="Logo" class="h-8 w-auto">
                <span class="text-xl font-extrabold text-blue-600 tracking-tight">CUPAD BM</span>
            </a>
            <div class="flex items-center gap-3">
                <button id="themeToggle" class="p-2 text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800 rounded-full transition">
                    <i class="fas fa-moon dark:hidden"></i><i class="fas fa-sun hidden dark:inline"></i>
                </button>
                <div class="profile-btn" onclick="window.location.href='profile.php'">
                    <i class="fas fa-user profile-fallback-icon"></i>
                    <?php if ($profile_pic && $profile_pic !== 'default_avatar.png'): ?>
                        <img src="<?php echo htmlspecialchars($base_path . $profile_pic); ?>" alt="Profile" class="absolute inset-0" onerror="this.style.display='none'">
                    <?php endif; ?>
                </div>
                <a href="<?php echo htmlspecialchars($base_path . 'logout.php'); ?>" class="p-2 text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-full transition" title="Logout">
                    <i class="fas fa-sign-out-alt"></i>
                </a>
            </div>
        </nav>
    </header>

    <main class="max-w-7xl mx-auto px-4 py-6">
        
        <!-- Controls & Greeting -->
        <div class="flex flex-col lg:flex-row justify-between items-start lg:items-center gap-4 mb-6">
            <div>
                <h1 class="text-2xl font-extrabold text-gray-900 dark:text-white">Branch Analytics</h1>
                <div class="flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400 mt-1">
                    <span><?php echo htmlspecialchars($bm_branch_name); ?></span>
                    <span>&bull;</span>
                    <span class="font-mono bg-white dark:bg-gray-800 px-2 py-0.5 rounded border border-gray-200 dark:border-gray-700 text-xs" id="activeDateRange">...</span>
                </div>
            </div>

            <!-- Filters Section -->
            <div class="flex flex-col sm:flex-row gap-2 w-full lg:w-auto items-center">
                <form id="filterForm" class="flex flex-col sm:flex-row gap-2 w-full sm:w-auto bg-white dark:bg-gray-800 p-1.5 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm">
                    <select name="preset" id="preset" class="text-sm py-2 px-3 rounded-lg bg-gray-50 dark:bg-gray-900 border-none outline-none focus:ring-2 focus:ring-primary w-full sm:w-auto text-gray-700 dark:text-gray-300">
                        <option value="">Custom</option>
                        <option value="today">Today</option>
                        <option value="week">This Week</option>
                        <option value="month" selected>This Month</option>
                        <option value="last_month">Last Month</option>
                    </select>
                    <div class="flex items-center gap-2 bg-gray-50 dark:bg-gray-900 rounded-lg px-2 w-full sm:w-auto">
                        <input type="date" id="date_from" class="bg-transparent border-none text-sm py-2 px-1 focus:ring-0 text-gray-700 dark:text-gray-300 w-full">
                        <span class="text-gray-400">-</span>
                        <input type="date" id="date_to" class="bg-transparent border-none text-sm py-2 px-1 focus:ring-0 text-gray-700 dark:text-gray-300 w-full">
                    </div>
                    <button type="submit" id="refreshBtn" class="p-2 bg-primary text-white rounded-lg hover:bg-blue-700 transition w-full sm:w-auto" title="Force Refresh Data"><i class="fas fa-sync-alt"></i></button>
                </form>
            </div>
        </div>

        <!-- Metric Cards -->
        <div class="dashboard-grid">
            <div class="dashboard-card card-gradient-blue"> 
                <i class="fas fa-users card-bg-icon"></i> 
                <div class="flex justify-between items-start relative z-10"> 
                    <div>
                        <div class="text-sm font-semibold uppercase opacity-90">Total Active Clients</div> 
                        <div class="text-3xl font-extrabold mt-1 mb-2 count-up" id="metric_clients">0</div> 
                        <div class="text-xs bg-black/20 rounded-full px-3 py-1 inline-flex items-center"><i class="fas fa-building mr-1"></i> Branch Wide</div> 
                    </div>
                    <div class="w-12 h-12 bg-white/20 backdrop-blur rounded-xl flex items-center justify-center text-xl"><i class="fas fa-users"></i></div>
                </div> 
            </div>
            
            <div class="dashboard-card card-gradient-green"> 
                <i class="fas fa-piggy-bank card-bg-icon"></i> 
                <div class="flex justify-between items-start relative z-10"> 
                    <div>
                        <div class="text-sm font-semibold uppercase opacity-90">Branch Net Savings</div> 
                        <div class="text-2xl font-extrabold mt-1 mb-2">₦<span class="count-up" id="metric_net_savings_card">0</span></div> 
                        <div class="flex items-center gap-4 text-xs"> 
                            <span class="inline-flex items-center"><i class="fas fa-arrow-up mr-1 text-white/70"></i> <span id="metric_deposits_card">0</span></span> 
                            <span class="inline-flex items-center"><i class="fas fa-arrow-down mr-1 text-white/70"></i> <span id="metric_withdrawals_card">0</span></span> 
                        </div> 
                    </div>
                    <div class="w-12 h-12 bg-white/20 backdrop-blur rounded-xl flex items-center justify-center text-xl"><i class="fas fa-piggy-bank"></i></div>
                </div> 
            </div>
            
            <div class="dashboard-card card-gradient-orange"> 
                <i class="fas fa-paper-plane card-bg-icon"></i> 
                <div class="flex justify-between items-start relative z-10"> 
                    <div>
                        <div class="text-sm font-semibold uppercase opacity-90">Branch Disbursements</div> 
                        <div class="text-2xl font-extrabold mt-1 mb-2">₦<span class="count-up" id="metric_disbursements">0</span></div> 
                        <div class="text-xs bg-black/20 rounded-full px-3 py-1 inline-flex items-center"><i class="fas fa-hand-holding-usd mr-1"></i> Loans Given</div> 
                    </div>
                    <div class="w-12 h-12 bg-white/20 backdrop-blur rounded-xl flex items-center justify-center text-xl"><i class="fas fa-file-invoice-dollar"></i></div>
                </div> 
            </div>
            
            <div class="dashboard-card card-gradient-red"> 
                <i class="fas fa-money-bill-wave card-bg-icon"></i> 
                <div class="flex justify-between items-start relative z-10"> 
                    <div>
                        <div class="text-sm font-semibold uppercase opacity-90">Branch Collections</div> 
                        <div class="text-2xl font-extrabold mt-1 mb-2">₦<span class="count-up" id="metric_collections">0</span></div> 
                        <div class="text-xs bg-black/20 rounded-full px-3 py-1 inline-flex items-center"><i class="fas fa-check mr-1"></i> Repayments</div> 
                    </div>
                    <div class="w-12 h-12 bg-white/20 backdrop-blur rounded-xl flex items-center justify-center text-xl"><i class="fas fa-money-bill-wave"></i></div>
                </div> 
            </div>
        </div>

        <!-- Analysis Section -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
            <div class="bg-white dark:bg-gray-800 rounded-2xl p-5 border border-gray-200 dark:border-gray-700 shadow-sm"> 
                <h3 class="font-bold text-gray-800 dark:text-white mb-4 flex items-center gap-2"><i class="fas fa-heartbeat text-error"></i> Branch Portfolio Health</h3> 
                <div class="space-y-4 text-sm"> 
                    <div class="flex justify-between"><span class="text-gray-500 dark:text-gray-400">PAR Ratio (26 Days)</span><span class="font-bold text-gray-800 dark:text-white" id="metric_par">0%</span></div> 
                    <div class="flex justify-between"><span class="text-gray-500 dark:text-gray-400">Active Loans</span><span class="font-bold text-gray-800 dark:text-white" id="metric_active_loans">0</span></div> 
                    <div class="flex justify-between border-t border-gray-100 dark:border-gray-700 pt-2"><span class="text-gray-500 dark:text-gray-400 font-semibold">Total Outstanding</span><span class="font-bold text-error">₦<span id="metric_outstanding">0</span></span></div> 
                </div> 
            </div>
            
            <div class="bg-white dark:bg-gray-800 rounded-2xl p-5 border border-gray-200 dark:border-gray-700 shadow-sm"> 
                <h3 class="font-bold text-gray-800 dark:text-white mb-4 flex items-center gap-2"><i class="fas fa-calculator text-primary"></i> Branch Financials</h3> 
                <div class="space-y-3"> 
                    <div class="bg-gray-50 dark:bg-gray-700/50 p-3 rounded-lg flex justify-between items-center"> 
                        <div><p class="text-xs text-gray-500 uppercase">Net Savings</p><p class="font-bold text-gray-900 dark:text-white">₦<span id="metric_net_savings">0</span></p></div> 
                        <div class="text-right"><p class="text-xs text-red-400">Withdrawals</p><p class="font-medium text-red-500">-₦<span id="metric_withdrawals">0</span></p></div> 
                    </div> 
                    <div class="bg-gray-50 dark:bg-gray-700/50 p-3 rounded-lg flex justify-between items-center"> 
                        <div><p class="text-xs text-gray-500 uppercase">Service Charges (Income)</p><p class="font-bold text-success">₦<span id="metric_service_charges">0</span></p></div> 
                        <div class="text-right cursor-pointer" onclick="openRegistrationDetails()">
                            <p class="text-xs text-gray-500">New Registrations</p>
                            <p class="font-medium text-primary flex items-center justify-end gap-1 hover:underline"><i class="fas fa-user-plus text-[10px]"></i> <span id="metric_registrations">0</span></p>
                        </div> 
                    </div> 
                </div> 
            </div>
            
            <div class="bg-white dark:bg-gray-800 rounded-2xl p-5 border border-gray-200 dark:border-gray-700 shadow-sm flex flex-col"> 
                <h3 class="font-bold text-gray-800 dark:text-white mb-2 flex items-center gap-2"><i class="fas fa-chart-pie text-secondary"></i> Branch Portfolio Mix</h3> 
                <div class="relative flex-1 min-h-[160px]"><canvas id="compChart"></canvas></div> 
            </div>
        </div>

        <!-- Trend Chart -->
        <div class="bg-white dark:bg-gray-800 rounded-2xl p-6 mb-8 border border-gray-200 dark:border-gray-700 shadow-sm">
            <h3 class="font-bold text-gray-800 dark:text-white mb-4 flex items-center gap-2"><i class="fas fa-chart-line text-primary"></i> Branch Activity Trend</h3>
            <div class="relative h-64 w-full"><canvas id="trendChart"></canvas></div>
        </div>

        <!-- Officer Table -->
        <div class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 shadow-sm overflow-hidden">
            <div class="p-4 border-b border-gray-200 dark:border-gray-700 flex flex-col sm:flex-row justify-between items-center gap-3">
                 <h3 class="font-bold text-lg text-gray-800 dark:text-white">Performance by Credit Officer</h3>
                 <div class="relative w-full sm:w-64">
                    <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
                    <input type="text" id="officerSearchInput" placeholder="Search officers..." class="w-full pl-9 py-2 bg-gray-100 dark:bg-gray-900 border-none rounded-lg text-sm text-gray-700 dark:text-gray-300 focus:ring-2 focus:ring-primary">
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm whitespace-nowrap">
                    <thead class="bg-gray-50 dark:bg-gray-900/50 text-gray-500 dark:text-gray-400 uppercase text-xs font-semibold">
                        <tr>
                            <th class="p-4">Officer Name</th> <th class="p-4 text-center">Clients</th> <th class="p-4 text-right">Deposits</th> <th class="p-4 text-right">Withdrawals</th> <th class="p-4 text-right">Net Savings</th> <th class="p-4 text-right">Disbursed</th> <th class="p-4 text-right">Collected</th> <th class="p-4 text-center">Reg.</th> <th class="p-4 text-center">Paid Off</th> <th class="p-4 text-center">Overdue Clients</th>
                        </tr>
                    </thead>
                    <tbody id="coTableBody" class="divide-y divide-gray-100 dark:divide-gray-700">
                        <tr><td colspan="10" class="p-8 text-center text-gray-500">Loading data...</td></tr>
                    </tbody>
                    <tfoot id="coTableFooter" class="bg-gray-50 dark:bg-gray-900/50 font-bold text-gray-700 dark:text-gray-300"></tfoot>
                </table>
            </div>
        </div>
    </main>

    <!-- Detail Modal -->
    <div id="detailModal" class="fixed inset-0 z-[60] hidden">
        <div class="absolute inset-0 bg-gray-900/60 backdrop-blur-sm" onclick="closeModal()"></div>
        <div class="absolute inset-0 flex items-center justify-center p-4">
            <div class="bg-white dark:bg-gray-800 w-full max-w-5xl rounded-2xl shadow-2xl flex flex-col max-h-[90vh]">
                <div class="p-4 border-b border-gray-200 dark:border-gray-700 flex justify-between items-center bg-gray-50 dark:bg-gray-800/50 rounded-t-2xl">
                    <div class="flex items-center gap-3">
                        <button id="modalBackButton" onclick="goBackToUnionView()" class="hidden w-8 h-8 flex-shrink-0 items-center justify-center rounded-full bg-gray-200 dark:bg-gray-700 hover:bg-gray-300 dark:hover:bg-gray-600 transition"><i class="fas fa-arrow-left text-gray-600 dark:text-gray-300"></i></button>
                        <div><h3 class="font-bold text-lg text-gray-800 dark:text-white" id="modalTitle">Details</h3><p class="text-xs text-gray-500" id="modalSubtitle">Breakdown</p></div>
                    </div>
                    <div class="flex gap-2">
                        <input type="text" id="modalSearchInput" placeholder="Search..." class="bg-white dark:bg-gray-900 border-none rounded-lg text-sm px-3 py-1 focus:ring-1 focus:ring-primary w-24 sm:w-48 text-gray-700 dark:text-gray-300">
                        <button onclick="closeModal()" class="w-8 h-8 flex items-center justify-center rounded-full bg-gray-200 dark:bg-gray-700 hover:bg-gray-300 dark:hover:bg-gray-600 transition"><i class="fas fa-times text-gray-600 dark:text-gray-300"></i></button>
                    </div>
                </div>
                <div class="flex-1 overflow-y-auto p-0">
                    <table class="w-full text-left text-sm whitespace-nowrap">
                        <thead class="bg-gray-50 dark:bg-gray-900 sticky top-0 z-10 border-b border-gray-200 dark:border-gray-700" id="modalTableHead"></thead>
                        <tbody id="modalTableBody" class="divide-y divide-gray-100 dark:divide-gray-700"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- BM Mobile Navigation -->
    <nav class="mobile-bottom-nav">
        <a href="dashboard.php" class="nav-item">
            <i class="fas fa-home"></i>
            <span>Home</span>
        </a>
        <a href="combined_collection.php" class="nav-item">
            <i class="fas fa-coins"></i>
            <span>Collect</span>
        </a>
        <a href="disbursement.php" class="nav-item">
             <i class="fas fa-file-invoice-dollar"></i>
             <span>Loans</span>
        </a>
        <a href="clients.php" class="nav-item">
            <i class="fas fa-users"></i>
            <span>Clients</span>
        </a>
        <a href="analytics.php" class="nav-item active">
             <div style="background: var(--primary-color); width: 45px; height: 45px; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; margin-top: -25px; box-shadow: 0 4px 10px rgba(59, 130, 246, 0.4);">
                <i class="fas fa-chart-bar" style="font-size: 1.2rem; margin:0;"></i>
             </div>
             <span style="font-size:0.65rem; font-weight:600; margin-top:2px;">Reports</span>
        </a>
    </nav>

    <!-- Logic -->
    <script>
        let charts = { trend: null, comp: null };
        let chartData = { daily:[], comp: {} };
        let currentOfficer = null;
        let currentUnion = null;
        let currentModalView = null; // 'unions' or 'clients'

        const debounce = (func, wait) => {
            let timeout;
            return function(...args) {
                clearTimeout(timeout);
                timeout = setTimeout(() => func.apply(this, args), wait);
            };
        };

        document.addEventListener('DOMContentLoaded', () => {
            fetchData();
            
            document.getElementById('filterForm').addEventListener('submit', (e) => { 
                e.preventDefault(); 
                fetchData(true, true); // Force refresh cache on submit
            });
            
            document.getElementById('officerSearchInput').addEventListener('input', debounce(filterOfficerTable, 300));
            document.getElementById('modalSearchInput').addEventListener('input', debounce(() => { 
                if (currentModalView === 'unions') {
                    openDetails(currentOfficer.username, currentOfficer.name);
                } else if (currentModalView === 'clients') {
                    openUnionDetails(currentUnion);
                } else if (currentModalView === 'registrations') {
                    openRegistrationDetails(); // Re-trigger with search via backend if implemented, or we can filter DOM. For now it triggers reload.
                }
            }, 300));
            
            // Re-render charts explicitly if necessary on load to match initial theme
            if (document.documentElement.classList.contains('dark')) {
                renderCharts();
            }
        });

        async function fetchData(spinner = true, force = false) {
            const btn = document.getElementById('refreshBtn');
            if(spinner) { btn.querySelector('i').classList.add('fa-spin'); btn.disabled = true; }
            const preset = document.getElementById('preset').value;
            const from = document.getElementById('date_from').value;
            const to = document.getElementById('date_to').value;
            const forceParam = force ? '&force=1' : '';

            try {
                const res = await fetch(`?ajax=1&action=main&preset=${preset}&date_from=${from}&date_to=${to}${forceParam}`);
                const data = await res.json();
                updateUI(data);
            } catch(e) { console.error(e); }
            finally { if(spinner) { btn.querySelector('i').classList.remove('fa-spin'); btn.disabled = false; } }
        }

        function updateUI(data) {
            document.getElementById('activeDateRange').textContent = `${data.dates.from} - ${data.dates.to}`;
            document.getElementById('date_from').value = data.dates.from;
            document.getElementById('date_to').value = data.dates.to;['clients', 'net_savings_card', 'deposits_card', 'withdrawals_card', 'disbursements', 'collections'].forEach(id => animate('metric_' + id, data.metrics['total_' + id.replace('_card', '')] || data.metrics[id.replace('_card', '')]));
            
            document.getElementById('metric_par').textContent = data.metrics.par_ratio + '%';
            document.getElementById('metric_active_loans').textContent = data.metrics.active_loans.toLocaleString();
            document.getElementById('metric_outstanding').textContent = parseInt(data.metrics.total_outstanding).toLocaleString();
            document.getElementById('metric_net_savings').textContent = parseInt(data.metrics.net_savings).toLocaleString();
            document.getElementById('metric_withdrawals').textContent = parseInt(data.metrics.total_withdrawals).toLocaleString();
            document.getElementById('metric_service_charges').textContent = parseInt(data.metrics.total_service_charges).toLocaleString();
            document.getElementById('metric_registrations').textContent = data.metrics.total_registrations.toLocaleString();
            
            renderTable(data.co_table);
            chartData = { daily: data.chart_daily, comp: data.chart_comp };
            renderCharts();
        }

        function renderTable(rows) {
            const tbody = document.getElementById('coTableBody');
            const tfoot = document.getElementById('coTableFooter');
            
            if(!rows || !rows.length) {
                tbody.innerHTML = '<tr><td colspan="10" class="p-8 text-center text-gray-500">No credit officers found.</td></tr>';
                tfoot.innerHTML = ''; return;
            }
            
            const tableHtml = rows.map(r => {
                const overdueDisplay = r.overdue_count > 0 ? `<span class="px-2 py-0.5 rounded-full bg-red-100 text-red-600 font-bold">${r.overdue_count}</span>` : `<span class="text-gray-400">-</span>`;
                return `<tr class="co-row hover:bg-gray-50 dark:hover:bg-gray-800 cursor-pointer transition" data-name="${(r.name || r.username).toLowerCase()}" onclick="openDetails('${r.username}', '${r.name}')">
                    <td class="p-4 font-medium text-gray-900 dark:text-white">${r.name || r.username}</td> 
                    <td class="p-4 text-center text-gray-500">${r.clients}</td> 
                    <td class="p-4 text-right font-mono text-success">₦${parseInt(r.total_savings).toLocaleString()}</td> 
                    <td class="p-4 text-right font-mono text-error">₦${parseInt(r.withdrawals).toLocaleString()}</td> 
                    <td class="p-4 text-right font-mono text-primary font-bold">₦${parseInt(r.net_savings).toLocaleString()}</td> 
                    <td class="p-4 text-right font-mono text-warning">₦${parseInt(r.disbursements).toLocaleString()}</td> 
                    <td class="p-4 text-right font-mono text-gray-600 dark:text-gray-300">₦${parseInt(r.collections).toLocaleString()}</td> 
                    <td class="p-4 text-center font-bold text-blue-600">${r.registrations}</td> 
                    <td class="p-4 text-center font-bold text-green-600">${r.paid_off}</td> 
                    <td class="p-4 text-center">${overdueDisplay}</td>
                </tr>`;
            }).join('');
            
            tbody.innerHTML = tableHtml;
            
            const totals = rows.reduce((acc, r) => ({ cli: acc.cli + parseInt(r.clients), dep: acc.dep + parseInt(r.total_savings), wit: acc.wit + parseInt(r.withdrawals), net: acc.net + r.net_savings, disb: acc.disb + r.disbursements, col: acc.col + r.collections, reg: acc.reg + r.registrations, paid: acc.paid + r.paid_off, od: acc.od + r.overdue_count }), { cli: 0, dep: 0, wit: 0, net: 0, disb: 0, col: 0, reg: 0, paid: 0, od: 0 });
            tfoot.innerHTML = `<tr>
                <td class="p-4">BRANCH TOTALS</td> <td class="p-4 text-center">${totals.cli.toLocaleString()}</td> <td class="p-4 text-right font-mono">₦${parseInt(totals.dep).toLocaleString()}</td> <td class="p-4 text-right font-mono text-error">₦${parseInt(totals.wit).toLocaleString()}</td> <td class="p-4 text-right font-mono">₦${parseInt(totals.net).toLocaleString()}</td> <td class="p-4 text-right font-mono">₦${parseInt(totals.disb).toLocaleString()}</td> <td class="p-4 text-right font-mono">₦${parseInt(totals.col).toLocaleString()}</td> <td class="p-4 text-center">${totals.reg}</td> <td class="p-4 text-center font-bold text-green-600">${totals.paid}</td> <td class="p-4 text-center font-bold text-red-500">${totals.od}</td>
            </tr>`;
        }
        
        function filterOfficerTable() {
            const query = document.getElementById('officerSearchInput').value.toLowerCase();
            document.querySelectorAll('#coTableBody .co-row').forEach(row => {
                row.style.display = row.dataset.name.includes(query) ? '' : 'none';
            });
        }
        
        async function openDetails(co_username, co_name) {
            currentOfficer = { username: co_username, name: co_name };
            currentModalView = 'unions';
            document.getElementById('modalTitle').textContent = `Details: ${co_name || co_username}`;
            document.getElementById('modalSubtitle').textContent = `Union Breakdown for selected period`;
            document.getElementById('modalBackButton').classList.add('hidden');
            document.getElementById('modalSearchInput').placeholder = 'Search unions...';
            document.getElementById('modalSearchInput').value = '';
            
            const thead = document.getElementById('modalTableHead');
            thead.innerHTML = `<tr> <th class="p-3 text-xs uppercase text-gray-500">Union</th> <th class="p-3 text-xs uppercase text-gray-500 text-center">Clients</th> <th class="p-3 text-xs uppercase text-gray-500 text-right">Deposits</th> <th class="p-3 text-xs uppercase text-gray-500 text-right">Withdrawals</th> <th class="p-3 text-xs uppercase text-gray-500 text-right">Collections</th> <th class="p-3 text-xs uppercase text-gray-500 text-center">Reg.</th> <th class="p-3 text-xs uppercase text-gray-500 text-center">Overdue</th> </tr>`;
            document.getElementById('detailModal').classList.remove('hidden');
            const tbody = document.getElementById('modalTableBody');
            tbody.innerHTML = '<tr><td colspan="7" class="p-8 text-center"><i class="fas fa-spinner fa-spin text-2xl text-primary"></i></td></tr>';
            
            const from = document.getElementById('date_from').value;
            const to = document.getElementById('date_to').value;
            try {
                const res = await fetch(`?ajax=1&action=co_details&co_username=${encodeURIComponent(co_username)}&date_from=${from}&date_to=${to}`);
                const data = await res.json();
                if(data.unions && data.unions.length) {
                    const search = document.getElementById('modalSearchInput').value.toLowerCase();
                    const filtered = data.unions.filter(u => u.name.toLowerCase().includes(search));
                    
                    if (filtered.length > 0) {
                        tbody.innerHTML = filtered.map(u => `
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-800 cursor-pointer" onclick="openUnionDetails('${u.name}', event)">
                               <td class="p-3 font-medium text-gray-900 dark:text-white">${u.name}</td>
                               <td class="p-3 text-center text-gray-500">${u.clients}</td>
                               <td class="p-3 text-right font-mono text-success">₦${parseInt(u.total_savings).toLocaleString()}</td>
                               <td class="p-3 text-right font-mono text-error">₦${parseInt(u.withdrawals).toLocaleString()}</td>
                               <td class="p-3 text-right font-mono text-purple-600">₦${parseInt(u.collections).toLocaleString()}</td>
                               <td class="p-3 text-center font-bold text-blue-600">${u.registrations}</td>
                               <td class="p-3 text-center">${u.overdue_count > 0 ? `<span class="px-2 py-0.5 rounded-full bg-red-100 text-red-600 font-bold">${u.overdue_count}</span>` : '-'}</td>
                            </tr>`).join('');
                    } else {
                        tbody.innerHTML = '<tr><td colspan="7" class="p-8 text-center text-gray-500">No matching union data.</td></tr>';
                    }
                } else {
                    tbody.innerHTML = '<tr><td colspan="7" class="p-8 text-center text-gray-500">No union data.</td></tr>';
                }
            } catch(e) { console.error(e); tbody.innerHTML = '<tr><td colspan="7" class="p-8 text-center text-red-500">Error loading data.</td></tr>'; }
        }

        async function openUnionDetails(union_name, event) {
            if(event) event.stopPropagation();
            currentUnion = union_name;
            currentModalView = 'clients';
            document.getElementById('modalTitle').textContent = `Clients: ${union_name}`;
            document.getElementById('modalSubtitle').textContent = `${currentOfficer.name} - Activity for Period`;
            document.getElementById('modalBackButton').classList.remove('hidden');
            document.getElementById('modalSearchInput').placeholder = 'Search clients...';

            const thead = document.getElementById('modalTableHead');
            thead.innerHTML = `<tr> <th class="p-3 text-xs uppercase text-gray-500">Client</th> <th class="p-3 text-xs uppercase text-blue-500 text-right">Savings</th> <th class="p-3 text-xs uppercase text-red-500 text-right">Withdrawals</th> <th class="p-3 text-xs uppercase text-purple-500 text-right">Collected</th> <th class="p-3 text-xs uppercase text-orange-500 text-right">Disbursed</th> <th class="p-3 text-xs uppercase text-gray-500 text-right">Current Balance</th> <th class="p-3 text-xs uppercase text-gray-500 text-center">Status</th> </tr>`;
            const tbody = document.getElementById('modalTableBody');
            tbody.innerHTML = '<tr><td colspan="7" class="p-8 text-center"><i class="fas fa-spinner fa-spin text-2xl text-primary"></i></td></tr>';
            
            const from = document.getElementById('date_from').value;
            const to = document.getElementById('date_to').value;
            const q = document.getElementById('modalSearchInput').value;
            
            try {
                const res = await fetch(`?ajax=1&action=union_details&co_username=${encodeURIComponent(currentOfficer.username)}&union_name=${encodeURIComponent(union_name)}&date_from=${from}&date_to=${to}&q=${encodeURIComponent(q)}`);
                const data = await res.json();
                if(data.clients && data.clients.length) {
                    tbody.innerHTML = data.clients.map(c => {
                        let statusDisplay = '';
                        const isClosed = c.status === 'inactive' || c.status === 'closed';
                        if (isClosed) statusDisplay = `<span class="px-2 py-0.5 rounded bg-gray-100 text-gray-600 text-[10px] font-bold">CLOSED</span>`;
                        else if (c.days_overdue > 0) statusDisplay = `<span class="px-2 py-0.5 rounded bg-red-100 text-red-600 text-[10px] font-bold">${c.days_overdue} DAYS OVERDUE</span>`;
                        else if (c.loan_outstanding > 0) statusDisplay = `<span class="px-2 py-0.5 rounded bg-orange-100 text-orange-600 text-[10px] font-bold">ACTIVE LOAN</span>`;
                        else if (c.is_fully_paid) statusDisplay = `<span class="px-2 py-0.5 rounded bg-green-100 text-green-700 text-[10px] font-bold">PAID OFF</span>`;
                        else statusDisplay = `<span class="text-gray-300">-</span>`;

                        return `<tr class="hover:bg-gray-50 dark:hover:bg-gray-800 ${isClosed ? 'opacity-60' : ''}">
                            <td class="p-3"> <div class="font-medium text-gray-900 dark:text-white">${c.name}</div> <div class="text-xs text-gray-400 font-mono">${c.id}</div> </td>
                            <td class="p-3 text-right font-mono ${c.range_savings > 0 ? 'text-blue-600' : 'text-gray-300'}">${c.range_savings > 0 ? '₦'+parseFloat(c.range_savings).toLocaleString() : '-'}</td>
                            <td class="p-3 text-right font-mono ${c.range_withdrawals > 0 ? 'text-red-600' : 'text-gray-300'}">${c.range_withdrawals > 0 ? '₦'+parseFloat(c.range_withdrawals).toLocaleString() : '-'}</td>
                            <td class="p-3 text-right font-mono ${c.range_collected > 0 ? 'text-purple-600' : 'text-gray-300'}">${c.range_collected > 0 ? '₦'+parseFloat(c.range_collected).toLocaleString() : '-'}</td>
                            <td class="p-3 text-right font-mono ${c.range_disbursed > 0 ? 'text-orange-600' : 'text-gray-300'}">${c.range_disbursed > 0 ? '₦'+parseFloat(c.range_disbursed).toLocaleString() : '-'}</td>
                            <td class="p-3 text-right font-mono text-gray-500 text-xs"> <div>Sav: ₦${parseFloat(c.savings_balance).toLocaleString()}</div> ${c.loan_outstanding > 0 ? `<div class="text-error">Loan: ₦${parseFloat(c.loan_outstanding).toLocaleString()}</div>` : ''} </td>
                            <td class="p-3 text-center">${statusDisplay}</td>
                        </tr>`;
                    }).join('');
                } else {
                    tbody.innerHTML = '<tr><td colspan="7" class="p-8 text-center text-gray-500">No clients to display.</td></tr>';
                }
            } catch(e) { console.error(e); tbody.innerHTML = '<tr><td colspan="7" class="p-8 text-center text-red-500">Error loading data.</td></tr>'; }
        }

        async function openRegistrationDetails() {
            currentModalView = 'registrations';
            document.getElementById('modalTitle').textContent = `Branch Registrations`;
            document.getElementById('modalSubtitle').textContent = document.getElementById('activeDateRange').textContent;
            document.getElementById('modalBackButton').classList.add('hidden');
            
            const thead = document.getElementById('modalTableHead');
            thead.innerHTML = `
                <tr>
                    <th class="p-3 text-xs uppercase text-gray-500">Client</th>
                    <th class="p-3 text-xs uppercase text-gray-500">Officer</th>
                    <th class="p-3 text-xs uppercase text-gray-500">Union</th>
                    <th class="p-3 text-xs uppercase text-gray-500 text-right">Date</th>
                    <th class="p-3 text-xs uppercase text-gray-500 text-right">Fee</th>
                </tr>
            `;

            document.getElementById('detailModal').classList.remove('hidden');
            const tbody = document.getElementById('modalTableBody');
            tbody.innerHTML = '<tr><td colspan="5" class="p-8 text-center"><i class="fas fa-spinner fa-spin text-2xl text-primary"></i></td></tr>';

            const from = document.getElementById('date_from').value;
            const to = document.getElementById('date_to').value;

            try {
                const res = await fetch(`?ajax=1&action=fetch_registrations_list&date_from=${from}&date_to=${to}`);
                const data = await res.json();
                
                if (data.registrations && data.registrations.length > 0) {
                    tbody.innerHTML = data.registrations.map(r => `
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-800">
                            <td class="p-3">
                                <div class="font-medium text-gray-900 dark:text-white">${r.name}</div>
                                <div class="text-xs text-gray-400 font-mono">${r.id}</div>
                            </td>
                            <td class="p-3 text-sm text-gray-600 dark:text-gray-300">${r.officer}</td>
                            <td class="p-3 text-sm text-gray-600 dark:text-gray-300">${r.union}</td>
                            <td class="p-3 text-right text-sm text-gray-600 dark:text-gray-300">${r.date}</td>
                            <td class="p-3 text-right font-mono text-primary font-bold">₦${r.amount.toLocaleString()}</td>
                        </tr>
                    `).join('');
                } else {
                    tbody.innerHTML = '<tr><td colspan="5" class="p-8 text-center text-gray-500">No registrations found for this period.</td></tr>';
                }
            } catch (e) {
                console.error(e);
                tbody.innerHTML = '<tr><td colspan="5" class="p-8 text-center text-red-500">Error loading data.</td></tr>';
            }
        }

        function goBackToUnionView() {
            if (currentOfficer) openDetails(currentOfficer.username, currentOfficer.name);
        }

        function closeModal() {
            document.getElementById('detailModal').classList.add('hidden');
            currentOfficer = null; currentUnion = null; currentModalView = null;
            document.getElementById('modalSearchInput').value = '';
        }

        function renderCharts() {
            // Wait a brief moment to ensure DOM is updated with 'dark' class if changed
            setTimeout(() => {
                const isDark = document.documentElement.classList.contains('dark');
                const textColor = isDark ? '#94a3b8' : '#64748b';
                const gridColor = isDark ? '#334155' : '#e2e8f0';
                
                if(charts.trend) charts.trend.destroy();
                charts.trend = new Chart(document.getElementById('trendChart').getContext('2d'), { 
                    type: 'line', 
                    data: { 
                        labels: chartData.daily.map(d => d.date), 
                        datasets:[ 
                            { label: 'Collections', data: chartData.daily.map(d => d.collections), borderColor: '#8b5cf6', backgroundColor: '#8b5cf6', tension: 0.3, pointRadius: 2 }, 
                            { label: 'Savings', data: chartData.daily.map(d => d.savings), borderColor: '#22c55e', backgroundColor: '#22c55e', tension: 0.3, pointRadius: 2 } 
                        ] 
                    }, 
                    options: { 
                        responsive: true, maintainAspectRatio: false, 
                        plugins: { legend: { labels: { color: textColor } } }, 
                        scales: { 
                            x: { grid: { display: false }, ticks: { color: textColor } }, 
                            y: { grid: { color: gridColor }, ticks: { color: textColor, callback: v => '₦'+v/1000+'k' } } 
                        } 
                    } 
                });
                
                if(charts.comp) charts.comp.destroy();
                charts.comp = new Chart(document.getElementById('compChart').getContext('2d'), { 
                    type: 'doughnut', 
                    data: { 
                        labels:['Savings', 'Disbursed', 'Collected'], 
                        datasets:[{ 
                            data:[chartData.comp.Savings, chartData.comp.Disbursements, chartData.comp.Collections], 
                            backgroundColor:['#22c55e', '#f59e0b', '#8b5cf6'], borderWidth: 0 
                        }] 
                    }, 
                    options: { 
                        responsive: true, maintainAspectRatio: false, cutout: '70%', 
                        plugins: { legend: { position: 'right', labels: { color: textColor, boxWidth: 12 } } } 
                    } 
                });
            }, 10);
        }

        function animate(id, end) {
            const el = document.getElementById(id); if(!el) return;
            const startVal = parseInt(el.textContent.replace(/,/g, '')) || 0;
            const duration = 800; let startTime = null;
            function step(timestamp) {
                if(!startTime) startTime = timestamp;
                const progress = Math.min((timestamp - startTime) / duration, 1);
                el.innerText = Math.floor(progress * (end - startVal) + startVal).toLocaleString();
                if(progress < 1) requestAnimationFrame(step); else el.innerText = parseInt(end).toLocaleString();
            }
            requestAnimationFrame(step);
        }
        
        // Handle Theme Toggle with Cookies
        document.getElementById('themeToggle').addEventListener('click', () => {
            const isDark = document.documentElement.classList.toggle('dark');
            const theme = isDark ? 'dark' : 'light';
            
            // Save to localStorage
            localStorage.setItem('theme', theme);
            
            // Save to Cookies (1 year expiry)
            document.cookie = "theme=" + theme + "; path=/; max-age=" + (60*60*24*365);
            
            renderCharts();
        });
    </script>
</body>
</html>