<?php
// zm/analytics.php - Zonal Manager Analytics Dashboard
session_start();
require_once '../includes/config.php';

try {
    $conn = getDbConnection();
} catch (Exception $e) {
    die("Database Connection Failed: " . $e->getMessage());
}

$base_path = '../';

// --- AUTHENTICATION ---
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'zm') {
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Unauthorized']);
        exit();
    }
    header('Location: ../index.php');
    exit();
}

$zm_username = $_SESSION['username'] ?? '';
$user_id = $_SESSION['user_id'] ?? '';

// Fetch the ZM's zone_id and details
$stmt_z = $conn->prepare("SELECT u.zone_id, z.name as zone_name, u.full_name, u.profile_pic FROM users u LEFT JOIN zones z ON u.zone_id = z.id WHERE u.id = ?");
$stmt_z->execute([$user_id]);
$zm_info = $stmt_z->fetch(PDO::FETCH_ASSOC);

$zone_id = $zm_info['zone_id'] ?? '';
$zone_name = $zm_info['zone_name'] ?? 'My Zone';
$full_name = !empty($zm_info['full_name']) ? $zm_info['full_name'] : 'Zonal Manager';
$profile_pic = !empty($zm_info['profile_pic']) ? $zm_info['profile_pic'] : 'default_avatar.png';

// Fetch all active areas in this zone
$area_list =[];
$stmt_areas = $conn->prepare("SELECT id, name FROM areas WHERE zone_id = ? ORDER BY name ASC");
$stmt_areas->execute([$zone_id]);
while ($row = $stmt_areas->fetch(PDO::FETCH_ASSOC)) {
    $area_list[] = $row;
}
$area_ids = array_column($area_list, 'id');

// Fetch all active branches in these areas
$branch_list =[];
if (!empty($area_ids)) {
    $placeholders_a = implode(',', array_fill(0, count($area_ids), '?'));
    $stmt_branches = $conn->prepare("SELECT id, name, area_id FROM branches WHERE area_id IN ($placeholders_a) AND status = 'active' ORDER BY name ASC");
    $stmt_branches->execute($area_ids);
    while ($row = $stmt_branches->fetch(PDO::FETCH_ASSOC)) {
        $branch_list[] = $row;
    }
}
$branch_ids = array_column($branch_list, 'id');

// Fetch all active COs in these branches
$co_list =[];
if (!empty($branch_ids)) {
    $placeholders_b = implode(',', array_fill(0, count($branch_ids), '?'));
    $stmt_cos = $conn->prepare("SELECT id, username, full_name, branch_id FROM users WHERE branch_id IN ($placeholders_b) AND role = 'co' AND status = 'active'");
    $stmt_cos->execute($branch_ids);
    while ($row = $stmt_cos->fetch(PDO::FETCH_ASSOC)) {
        $co_list[] = $row;
    }
}

// --- HYBRID CACHE SYSTEM ---
function get_cache_key($zone_id, $date_from, $date_to, $search = '') {
    return md5($zone_id . $date_from . $date_to . $search . 'v_zm_analytics_v1');
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

// --- HELPER: FIXED PUBLIC HOLIDAYS & WORKING DAYS ---
function is_holiday($date_obj) {
    $md = $date_obj->format('m-d');
    $holidays =['01-01', '05-01', '06-12', '10-01', '12-25', '12-26'];
    return in_array($md, $holidays);
}

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

$cache_key = get_cache_key($zone_id, $req_date_from, $req_date_to);
$cached_data = get_cached_data($cache_key, $cache_dir); 

if (isset($_GET['force']) && $_GET['force'] == '1') $cached_data = null; // Force refresh

if ($cached_data) {
    $zone_clients = $cached_data['zone_clients'];
    $zone_savings = $cached_data['zone_savings'];
    $zone_disbursements = $cached_data['zone_disbursements'];
    $zone_collections = $cached_data['zone_collections'];
    $zone_registrations = $cached_data['zone_registrations'];
} else {
    $officer_usernames = array_column($co_list, 'username');
    $placeholders = !empty($officer_usernames) ? implode(',', array_fill(0, count($officer_usernames), '?')) : "''";
    $placeholders_b = !empty($branch_ids) ? implode(',', array_fill(0, count($branch_ids), '?')) : "''";

    // 1. Clients
    $zone_clients =[];
    if (!empty($branch_ids)) {
        $stmt = $conn->prepare("SELECT id, name, `union`, status, officer_username, branch_id FROM clients WHERE branch_id IN ($placeholders_b)");
        $stmt->execute($branch_ids);
        $zone_clients = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // 2. Savings
    $zone_savings =[];
    if (!empty($branch_ids)) {
        $stmt = $conn->prepare("SELECT sc.client_id, sc.amount, sc.date, sc.type, sc.officer FROM saving_collections sc JOIN clients c ON sc.client_id = c.id WHERE c.branch_id IN ($placeholders_b)");
        $stmt->execute($branch_ids);
        $zone_savings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // 3. Disbursements
    $zone_disbursements =[];
    if (!empty($branch_ids)) {
        $stmt = $conn->prepare("SELECT d.id, d.client_id, d.principal as principal_amount, d.total_payable, d.remaining_balance, d.date, d.payoff_date, d.officer FROM disbursements d JOIN clients c ON d.client_id = c.id WHERE c.branch_id IN ($placeholders_b)");
        $stmt->execute($branch_ids);
        $zone_disbursements = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // 4. Collections
    $zone_collections =[];
    if (!empty($branch_ids)) {
        $stmt = $conn->prepare("SELECT lc.client_id, lc.amount_collected, lc.date, lc.officer FROM loan_collections lc JOIN clients c ON lc.client_id = c.id WHERE c.branch_id IN ($placeholders_b)");
        $stmt->execute($branch_ids);
        $zone_collections = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // 5. Registrations
    $zone_registrations =[];
    if (!empty($branch_ids)) {
        $stmt = $conn->prepare("SELECT r.client_id, r.`union`, r.amount, r.date, r.officer FROM registrations r JOIN clients c ON r.client_id = c.id WHERE c.branch_id IN ($placeholders_b)");
        $stmt->execute($branch_ids);
        $client_map = array_column($zone_clients, 'name', 'id');
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $row['client_name'] = $client_map[$row['client_id']] ?? 'Unknown';
            $zone_registrations[] = $row;
        }
    }
    
    save_to_cache([
        'zone_clients' => $zone_clients,
        'zone_savings' => $zone_savings,
        'zone_disbursements' => $zone_disbursements,
        'zone_collections' => $zone_collections,
        'zone_registrations' => $zone_registrations
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
$filtered_savings = filter_by_date_range($zone_savings, $date_from, $date_to);
$filtered_disbursements = filter_by_date_range($zone_disbursements, $date_from, $date_to);
$filtered_collections = filter_by_date_range($zone_collections, $date_from, $date_to);
$filtered_registrations = filter_by_date_range($zone_registrations, $date_from, $date_to);

// --- 5. METRICS CALCULATION (ZONE WIDE) ---
$total_clients = count(array_filter($zone_clients, fn($c) => $c['status'] === 'active'));
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
foreach ($zone_disbursements as $loan) {
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
    
    // 6a. Main AJAX call - Zone Dash & Area Table
    if ($action === 'main') {
        $area_stats =[];
        foreach ($area_list as $area) {
            $a_id = $area['id'];
            $a_branches = array_filter($branch_list, fn($b) => $b['area_id'] == $a_id);
            $a_branch_ids = array_column($a_branches, 'id');
            
            $a_clients = array_filter($zone_clients, fn($c) => in_array($c['branch_id'], $a_branch_ids));
            $a_client_ids = array_column($a_clients, 'id');
            
            $a_savings = array_filter($filtered_savings, fn($s) => in_array($s['client_id'], $a_client_ids));
            $a_disb = array_filter($filtered_disbursements, fn($d) => in_array($d['client_id'], $a_client_ids));
            $a_coll = array_filter($filtered_collections, fn($c) => in_array($c['client_id'], $a_client_ids));
            $a_regs = array_filter($filtered_registrations, fn($r) => in_array($r['client_id'], $a_client_ids));

            $a_dep = 0; $a_wit = 0;
            foreach ($a_savings as $s) {
                $amt = floatval($s['amount'] ?? 0); $type = strtolower($s['type'] ?? '');
                if ($amt < 0 || $type === 'withdrawal' || $type === 'return' || $type === 'adjust') $a_wit += abs($amt); else $a_dep += $amt;
            }

            $paid_off_count = 0; $overdue_count = 0;
            $all_area_loans = array_filter($zone_disbursements, fn($d) => in_array($d['client_id'], $a_client_ids));
            foreach ($a_clients as $client) {
                $c_loans = array_filter($all_area_loans, fn($d) => $d['client_id'] === $client['id']);
                $has_payoff = false; $is_overdue = false;
                foreach ($c_loans as $loan) {
                    $rem = floatval($loan['remaining_balance'] ?? 0);
                    if ($rem <= 0.01 && ($loan['payoff_date']??null) >= $aj_from && ($loan['payoff_date']??null) <= $aj_to) $has_payoff = true;
                    if ($rem > 0.01 && ($today_obj > calculate_due_date($loan['date'], 26))) $is_overdue = true;
                }
                if ($has_payoff) $paid_off_count++;
                if ($is_overdue) $overdue_count++;
            }
            
            $a_cos = count(array_filter($co_list, fn($co) => in_array($co['branch_id'], $a_branch_ids)));

            $area_stats[] = [
                'id' => $area['id'], 
                'name' => $area['name'], 
                'branch_count' => count($a_branches),
                'co_count' => $a_cos,
                'clients' => count($a_clients), 
                'total_savings' => $a_dep, 
                'withdrawals' => abs($a_wit), 
                'net_savings' => $a_dep - $a_wit, 
                'disbursements' => array_sum(array_column($a_disb, 'principal_amount')), 
                'collections' => array_sum(array_column($a_coll, 'amount_collected')), 
                'registrations' => count($a_regs), 
                'paid_off' => $paid_off_count, 
                'overdue_count' => $overdue_count
            ];
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
            'main_table' => $area_stats,
            'dates' =>['from' => $date_from, 'to' => $date_to]
        ]);
        exit();
    }

    // 6b. Area Details (Branch Breakdown)
    if ($action === 'area_details') {
        $a_id = $_GET['area_id'] ?? '';
        $area_branches = array_filter($branch_list, fn($b) => $b['area_id'] == $a_id);
        
        $branch_stats = [];
        foreach ($area_branches as $branch) {
            $b_id = $branch['id'];
            $b_clients = array_filter($zone_clients, fn($c) => $c['branch_id'] == $b_id);
            $b_client_ids = array_column($b_clients, 'id');
            
            $b_savings = array_filter($filtered_savings, fn($s) => in_array($s['client_id'], $b_client_ids));
            $b_disb = array_filter($filtered_disbursements, fn($d) => in_array($d['client_id'], $b_client_ids));
            $b_coll = array_filter($filtered_collections, fn($c) => in_array($c['client_id'], $b_client_ids));
            $b_regs = array_filter($filtered_registrations, fn($r) => in_array($r['client_id'], $b_client_ids));

            $b_dep = 0; $b_wit = 0;
            foreach ($b_savings as $s) {
                $amt = floatval($s['amount'] ?? 0); $type = strtolower($s['type'] ?? '');
                if ($amt < 0 || $type === 'withdrawal' || $type === 'return' || $type === 'adjust') $b_wit += abs($amt); else $b_dep += $amt;
            }

            $paid_off_count = 0; $overdue_count = 0;
            $all_branch_loans = array_filter($zone_disbursements, fn($d) => in_array($d['client_id'], $b_client_ids));
            foreach ($b_clients as $client) {
                $c_loans = array_filter($all_branch_loans, fn($d) => $d['client_id'] === $client['id']);
                $has_payoff = false; $is_overdue = false;
                foreach ($c_loans as $loan) {
                    $rem = floatval($loan['remaining_balance'] ?? 0);
                    if ($rem <= 0.01 && ($loan['payoff_date']??null) >= $aj_from && ($loan['payoff_date']??null) <= $aj_to) $has_payoff = true;
                    if ($rem > 0.01 && ($today_obj > calculate_due_date($loan['date'], 26))) $is_overdue = true;
                }
                if ($has_payoff) $paid_off_count++;
                if ($is_overdue) $overdue_count++;
            }
            
            $b_cos = count(array_filter($co_list, fn($co) => $co['branch_id'] == $b_id));

            $branch_stats[] =[
                'id' => $branch['id'], 
                'name' => $branch['name'], 
                'co_count' => $b_cos,
                'clients' => count($b_clients), 
                'total_savings' => $b_dep, 
                'withdrawals' => abs($b_wit), 
                'net_savings' => $b_dep - $b_wit, 
                'disbursements' => array_sum(array_column($b_disb, 'principal_amount')), 
                'collections' => array_sum(array_column($b_coll, 'amount_collected')), 
                'registrations' => count($b_regs), 
                'paid_off' => $paid_off_count, 
                'overdue_count' => $overdue_count
            ];
        }
        
        echo json_encode(['branches' => $branch_stats]);
        exit();
    }

    // 6c. Branch Details (CO Breakdown)
    if ($action === 'branch_details') {
        $b_id = $_GET['branch_id'] ?? '';
        $branch_cos = array_filter($co_list, fn($c) => $c['branch_id'] == $b_id);
        
        $co_stats =[];
        foreach ($branch_cos as $co) {
            $co_user = $co['username'];
            $officer_clients = array_filter($zone_clients, fn($c) => $c['officer_username'] === $co_user);
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
            $all_officer_loans = array_filter($zone_disbursements, fn($d) => $d['officer'] === $co_user);
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
            $co_stats[] = ['username' => $co['username'], 'name' => $co['full_name'], 'clients' => count($officer_clients), 'total_savings' => $co_dep, 'withdrawals' => abs($co_wit), 'net_savings' => $co_dep - $co_wit, 'disbursements' => array_sum(array_column($co_disb, 'principal_amount')), 'collections' => array_sum(array_column($co_coll, 'amount_collected')), 'registrations' => count($co_regs), 'paid_off' => $paid_off_count, 'overdue_count' => $overdue_count];
        }
        
        echo json_encode(['cos' => $co_stats]);
        exit();
    }

    // 6d. CO Details (Union Breakdown)
    if ($action === 'co_details') {
        $co_username = $_GET['co_username'] ?? '';
        $officer_clients = array_filter($zone_clients, fn($c) => $c['officer_username'] === $co_username);
        $officer_disbursements_all = array_filter($zone_disbursements, fn($d) => $d['officer'] === $co_username);
        
        $ajax_filtered_collections = array_filter($zone_collections, fn($c) => $c['officer'] === $co_username && $c['date'] >= $aj_from && $c['date'] <= $aj_to." 23:59:59");
        $ajax_filtered_savings = array_filter($zone_savings, fn($s) => $s['officer'] === $co_username && $s['date'] >= $aj_from && $s['date'] <= $aj_to." 23:59:59");
        $ajax_filtered_registrations = array_filter($zone_registrations, fn($r) => $r['officer'] === $co_username && $r['date'] >= $aj_from && $r['date'] <= $aj_to." 23:59:59");

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
    
    // 6e. Union Details (Client Breakdown)
    if ($action === 'union_details') {
        $co_username = $_GET['co_username'] ?? '';
        $union_name = $_GET['union_name'] ?? '';
        $client_search = isset($_GET['q']) ? strtolower(trim($_GET['q'])) : '';

        $co_savings_all = array_filter($zone_savings, fn($s) => $s['officer'] === $co_username);
        $co_disbursements_all = array_filter($zone_disbursements, fn($d) => $d['officer'] === $co_username);
        $ajax_filtered_collections = array_filter($zone_collections, fn($c) => $c['officer'] === $co_username && $c['date'] >= $aj_from && $c['date'] <= $aj_to." 23:59:59");
        $ajax_filtered_savings = array_filter($zone_savings, fn($s) => $s['officer'] === $co_username && $s['date'] >= $aj_from && $s['date'] <= $aj_to." 23:59:59");
        $ajax_filtered_disbursements = array_filter($zone_disbursements, fn($d) => $d['officer'] === $co_username && $d['date'] >= $aj_from && $d['date'] <= $aj_to." 23:59:59");
        
        $target_clients = array_filter($zone_clients, fn($c) => $c['officer_username'] === $co_username && $c['union'] === $union_name);
        
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
    <title>Zone Analytics | CUPAD</title>
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
        .card-gradient-pink { background: linear-gradient(135deg, #ec4899 0%, #be185d 100%); }
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
                <span class="text-xl font-extrabold text-blue-600 tracking-tight">CUPAD ZM</span>
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
                <h1 class="text-2xl font-extrabold text-gray-900 dark:text-white">Zone Analytics</h1>
                <div class="flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400 mt-1">
                    <span><i class="fas fa-map text-blue-500"></i> <?php echo htmlspecialchars($zone_name); ?></span>
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
            <div class="dashboard-card card-gradient-pink"> 
                <i class="fas fa-users card-bg-icon"></i> 
                <div class="flex justify-between items-start relative z-10"> 
                    <div>
                        <div class="text-sm font-semibold uppercase opacity-90">Zone Active Clients</div> 
                        <div class="text-3xl font-extrabold mt-1 mb-2 count-up" id="metric_clients">0</div> 
                        <div class="text-xs bg-black/20 rounded-full px-3 py-1 inline-flex items-center"><i class="fas fa-globe mr-1"></i> Across Entire Zone</div> 
                    </div>
                    <div class="w-12 h-12 bg-white/20 backdrop-blur rounded-xl flex items-center justify-center text-xl"><i class="fas fa-users"></i></div>
                </div> 
            </div>
            
            <div class="dashboard-card card-gradient-blue"> 
                <i class="fas fa-piggy-bank card-bg-icon"></i> 
                <div class="flex justify-between items-start relative z-10"> 
                    <div>
                        <div class="text-sm font-semibold uppercase opacity-90">Zone Net Savings</div> 
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
                        <div class="text-sm font-semibold uppercase opacity-90">Zone Disbursements</div> 
                        <div class="text-2xl font-extrabold mt-1 mb-2">₦<span class="count-up" id="metric_disbursements">0</span></div> 
                        <div class="text-xs bg-black/20 rounded-full px-3 py-1 inline-flex items-center"><i class="fas fa-hand-holding-usd mr-1"></i> Total Outflow</div> 
                    </div>
                    <div class="w-12 h-12 bg-white/20 backdrop-blur rounded-xl flex items-center justify-center text-xl"><i class="fas fa-file-invoice-dollar"></i></div>
                </div> 
            </div>
            
            <div class="dashboard-card card-gradient-green"> 
                <i class="fas fa-money-bill-wave card-bg-icon"></i> 
                <div class="flex justify-between items-start relative z-10"> 
                    <div>
                        <div class="text-sm font-semibold uppercase opacity-90">Zone Collections</div> 
                        <div class="text-2xl font-extrabold mt-1 mb-2">₦<span class="count-up" id="metric_collections">0</span></div> 
                        <div class="text-xs bg-black/20 rounded-full px-3 py-1 inline-flex items-center"><i class="fas fa-check mr-1"></i> Loan Repayments</div> 
                    </div>
                    <div class="w-12 h-12 bg-white/20 backdrop-blur rounded-xl flex items-center justify-center text-xl"><i class="fas fa-money-bill-wave"></i></div>
                </div> 
            </div>
        </div>

        <!-- Analysis Section -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
            <div class="bg-white dark:bg-gray-800 rounded-2xl p-5 border border-gray-200 dark:border-gray-700 shadow-sm"> 
                <h3 class="font-bold text-gray-800 dark:text-white mb-4 flex items-center gap-2"><i class="fas fa-heartbeat text-error"></i> Zone Portfolio Health</h3> 
                <div class="space-y-4 text-sm"> 
                    <div class="flex justify-between"><span class="text-gray-500 dark:text-gray-400">PAR Ratio (26 Days)</span><span class="font-bold text-gray-800 dark:text-white" id="metric_par">0%</span></div> 
                    <div class="flex justify-between"><span class="text-gray-500 dark:text-gray-400">Active Loans</span><span class="font-bold text-gray-800 dark:text-white" id="metric_active_loans">0</span></div> 
                    <div class="flex justify-between border-t border-gray-100 dark:border-gray-700 pt-2"><span class="text-gray-500 dark:text-gray-400 font-semibold">Total Outstanding</span><span class="font-bold text-error">₦<span id="metric_outstanding">0</span></span></div> 
                </div> 
            </div>
            
            <div class="bg-white dark:bg-gray-800 rounded-2xl p-5 border border-gray-200 dark:border-gray-700 shadow-sm"> 
                <h3 class="font-bold text-gray-800 dark:text-white mb-4 flex items-center gap-2"><i class="fas fa-calculator text-primary"></i> Zone Financials</h3> 
                <div class="space-y-3"> 
                    <div class="bg-gray-50 dark:bg-gray-700/50 p-3 rounded-lg flex justify-between items-center"> 
                        <div><p class="text-xs text-gray-500 uppercase">Net Savings</p><p class="font-bold text-gray-900 dark:text-white">₦<span id="metric_net_savings">0</span></p></div> 
                        <div class="text-right"><p class="text-xs text-red-400">Withdrawals</p><p class="font-medium text-red-500">-₦<span id="metric_withdrawals">0</span></p></div> 
                    </div> 
                    <div class="bg-gray-50 dark:bg-gray-700/50 p-3 rounded-lg flex justify-between items-center"> 
                        <div><p class="text-xs text-gray-500 uppercase">Service Charges (Income)</p><p class="font-bold text-success">₦<span id="metric_service_charges">0</span></p></div> 
                        <div class="text-right">
                            <p class="text-xs text-gray-500">New Registrations</p>
                            <p class="font-medium text-primary flex items-center justify-end gap-1"><i class="fas fa-user-plus text-[10px]"></i> <span id="metric_registrations">0</span></p>
                        </div> 
                    </div> 
                </div> 
            </div>
            
            <div class="bg-white dark:bg-gray-800 rounded-2xl p-5 border border-gray-200 dark:border-gray-700 shadow-sm flex flex-col"> 
                <h3 class="font-bold text-gray-800 dark:text-white mb-2 flex items-center gap-2"><i class="fas fa-chart-pie text-secondary"></i> Zone Portfolio Mix</h3> 
                <div class="relative flex-1 min-h-[160px]"><canvas id="compChart"></canvas></div> 
            </div>
        </div>

        <!-- Trend Chart -->
        <div class="bg-white dark:bg-gray-800 rounded-2xl p-6 mb-8 border border-gray-200 dark:border-gray-700 shadow-sm">
            <h3 class="font-bold text-gray-800 dark:text-white mb-4 flex items-center gap-2"><i class="fas fa-chart-line text-primary"></i> Zone Activity Trend</h3>
            <div class="relative h-64 w-full"><canvas id="trendChart"></canvas></div>
        </div>

        <!-- Main Areas Table -->
        <div class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 shadow-sm overflow-hidden">
            <div class="p-4 border-b border-gray-200 dark:border-gray-700 flex flex-col sm:flex-row justify-between items-center gap-3">
                 <h3 class="font-bold text-lg text-gray-800 dark:text-white">Performance by Area</h3>
                 <div class="relative w-full sm:w-64">
                    <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
                    <input type="text" id="mainSearchInput" placeholder="Search areas..." class="w-full pl-9 py-2 bg-gray-100 dark:bg-gray-900 border-none rounded-lg text-sm text-gray-700 dark:text-gray-300 focus:ring-2 focus:ring-primary">
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm whitespace-nowrap">
                    <thead class="bg-gray-50 dark:bg-gray-900/50 text-gray-500 dark:text-gray-400 uppercase text-xs font-semibold">
                        <tr>
                            <th class="p-4">Area Name</th> <th class="p-4 text-center">Branches</th> <th class="p-4 text-center">COs</th> <th class="p-4 text-center">Clients</th> <th class="p-4 text-right">Deposits</th> <th class="p-4 text-right">Withdrawals</th> <th class="p-4 text-right">Net Savings</th> <th class="p-4 text-right">Disbursed</th> <th class="p-4 text-right">Collected</th> <th class="p-4 text-center">Reg.</th> <th class="p-4 text-center">Paid Off</th> <th class="p-4 text-center">Overdue</th>
                        </tr>
                    </thead>
                    <tbody id="mainTableBody" class="divide-y divide-gray-100 dark:divide-gray-700">
                        <tr><td colspan="12" class="p-8 text-center text-gray-500">Loading data...</td></tr>
                    </tbody>
                    <tfoot id="mainTableFooter" class="bg-gray-50 dark:bg-gray-900/50 font-bold text-gray-700 dark:text-gray-300"></tfoot>
                </table>
            </div>
        </div>
    </main>

    <!-- Deep Drill Detail Modal -->
    <div id="detailModal" class="fixed inset-0 z-[60] hidden">
        <div class="absolute inset-0 bg-gray-900/60 backdrop-blur-sm" onclick="closeModal()"></div>
        <div class="absolute inset-0 flex items-center justify-center p-4">
            <div class="bg-white dark:bg-gray-800 w-full max-w-6xl rounded-2xl shadow-2xl flex flex-col max-h-[90vh]">
                <div class="p-4 border-b border-gray-200 dark:border-gray-700 flex justify-between items-center bg-gray-50 dark:bg-gray-800/50 rounded-t-2xl">
                    <div class="flex items-center gap-3">
                        <button id="modalBackButton" onclick="navigateModalBack()" class="hidden w-8 h-8 flex-shrink-0 items-center justify-center rounded-full bg-gray-200 dark:bg-gray-700 hover:bg-gray-300 dark:hover:bg-gray-600 transition"><i class="fas fa-arrow-left text-gray-600 dark:text-gray-300"></i></button>
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

    <!-- Mobile Navigation -->
    <nav class="mobile-bottom-nav">
        <a href="dashboard.php" class="nav-item">
            <i class="fas fa-home"></i>
            <span>Home</span>
        </a>
        <a href="areas.php" class="nav-item">
            <i class="fas fa-map-marked-alt"></i>
            <span>Areas</span>
        </a>
        <a href="branches.php" class="nav-item">
            <i class="fas fa-building"></i>
            <span>Branches</span>
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
        
        // Modal State Machine for Deep Drill Down
        let modalState = {
            view: null, // 'branches', 'cos', 'unions', 'clients'
            area: null,
            branch: null,
            officer: null,
            union: null
        };

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
                fetchData(true, true); 
            });
            
            document.getElementById('mainSearchInput').addEventListener('input', debounce(filterMainTable, 300));
            
            document.getElementById('modalSearchInput').addEventListener('input', debounce(() => { 
                if (modalState.view === 'branches') {
                    openAreaDetails(modalState.area.id, modalState.area.name);
                } else if (modalState.view === 'cos') {
                    openBranchDetails(modalState.branch.id, modalState.branch.name);
                } else if (modalState.view === 'unions') {
                    openOfficerDetails(modalState.officer.username, modalState.officer.name);
                } else if (modalState.view === 'clients') {
                    openUnionDetails(modalState.union);
                }
            }, 300));
            
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
            
            renderTable(data.main_table);
            chartData = { daily: data.chart_daily, comp: data.chart_comp };
            renderCharts();
        }

        function renderTable(rows) {
            const tbody = document.getElementById('mainTableBody');
            const tfoot = document.getElementById('mainTableFooter');
            
            if(!rows || !rows.length) {
                tbody.innerHTML = '<tr><td colspan="12" class="p-8 text-center text-gray-500">No active areas found.</td></tr>';
                tfoot.innerHTML = ''; return;
            }
            
            const tableHtml = rows.map(r => {
                const overdueDisplay = r.overdue_count > 0 ? `<span class="px-2 py-0.5 rounded-full bg-red-100 text-red-600 font-bold">${r.overdue_count}</span>` : `<span class="text-gray-400">-</span>`;
                return `<tr class="data-row hover:bg-gray-50 dark:hover:bg-gray-800 cursor-pointer transition" data-name="${r.name.toLowerCase()}" onclick="openAreaDetails('${r.id}', '${r.name.replace(/'/g,"\\'")}')">
                    <td class="p-4 font-medium text-pink-600 dark:text-pink-400"><i class="fas fa-map-marked-alt mr-1"></i> ${r.name}</td> 
                    <td class="p-4 text-center font-bold text-gray-700 dark:text-gray-300">${r.branch_count}</td>
                    <td class="p-4 text-center text-gray-500">${r.co_count}</td>
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
            
            const totals = rows.reduce((acc, r) => ({ bra: acc.bra + r.branch_count, cos: acc.cos + r.co_count, cli: acc.cli + parseInt(r.clients), dep: acc.dep + parseInt(r.total_savings), wit: acc.wit + parseInt(r.withdrawals), net: acc.net + r.net_savings, disb: acc.disb + r.disbursements, col: acc.col + r.collections, reg: acc.reg + r.registrations, paid: acc.paid + r.paid_off, od: acc.od + r.overdue_count }), { bra: 0, cos: 0, cli: 0, dep: 0, wit: 0, net: 0, disb: 0, col: 0, reg: 0, paid: 0, od: 0 });
            tfoot.innerHTML = `<tr>
                <td class="p-4">ZONE TOTALS</td> <td class="p-4 text-center">${totals.bra}</td> <td class="p-4 text-center">${totals.cos}</td> <td class="p-4 text-center">${totals.cli.toLocaleString()}</td> <td class="p-4 text-right font-mono">₦${parseInt(totals.dep).toLocaleString()}</td> <td class="p-4 text-right font-mono text-error">₦${parseInt(totals.wit).toLocaleString()}</td> <td class="p-4 text-right font-mono">₦${parseInt(totals.net).toLocaleString()}</td> <td class="p-4 text-right font-mono">₦${parseInt(totals.disb).toLocaleString()}</td> <td class="p-4 text-right font-mono">₦${parseInt(totals.col).toLocaleString()}</td> <td class="p-4 text-center">${totals.reg}</td> <td class="p-4 text-center font-bold text-green-600">${totals.paid}</td> <td class="p-4 text-center font-bold text-red-500">${totals.od}</td>
            </tr>`;
        }
        
        function filterMainTable() {
            const query = document.getElementById('mainSearchInput').value.toLowerCase();
            document.querySelectorAll('#mainTableBody .data-row').forEach(row => {
                row.style.display = row.dataset.name.includes(query) ? '' : 'none';
            });
        }
        
        // --- Modal Drill Down Logic ---
        
        function navigateModalBack() {
            if (modalState.view === 'clients') {
                openOfficerDetails(modalState.officer.username, modalState.officer.name);
            } else if (modalState.view === 'unions') {
                openBranchDetails(modalState.branch.id, modalState.branch.name);
            } else if (modalState.view === 'cos') {
                openAreaDetails(modalState.area.id, modalState.area.name);
            }
        }

        async function openAreaDetails(area_id, area_name) {
            modalState.view = 'branches';
            modalState.area = { id: area_id, name: area_name };
            
            document.getElementById('modalTitle').textContent = `Branches in ${area_name}`;
            document.getElementById('modalSubtitle').textContent = `Performance by Branch`;
            document.getElementById('modalBackButton').classList.add('hidden'); // Top level modal in ZM Drill
            document.getElementById('modalSearchInput').placeholder = 'Search branches...';
            document.getElementById('modalSearchInput').value = '';
            
            const thead = document.getElementById('modalTableHead');
            thead.innerHTML = `<tr> <th class="p-3 text-xs uppercase text-gray-500">Branch Name</th> <th class="p-3 text-xs uppercase text-gray-500 text-center">COs</th> <th class="p-3 text-xs uppercase text-gray-500 text-center">Clients</th> <th class="p-3 text-xs uppercase text-gray-500 text-right">Deposits</th> <th class="p-3 text-xs uppercase text-gray-500 text-right">Withdrawals</th> <th class="p-3 text-xs uppercase text-gray-500 text-right">Net Savings</th> <th class="p-3 text-xs uppercase text-gray-500 text-right">Disbursed</th> <th class="p-3 text-xs uppercase text-gray-500 text-right">Collections</th> <th class="p-3 text-xs uppercase text-gray-500 text-center">Overdue</th> </tr>`;
            
            document.getElementById('detailModal').classList.remove('hidden');
            const tbody = document.getElementById('modalTableBody');
            tbody.innerHTML = '<tr><td colspan="9" class="p-8 text-center"><i class="fas fa-spinner fa-spin text-2xl text-primary"></i></td></tr>';
            
            const from = document.getElementById('date_from').value;
            const to = document.getElementById('date_to').value;
            
            try {
                const res = await fetch(`?ajax=1&action=area_details&area_id=${area_id}&date_from=${from}&date_to=${to}`);
                const data = await res.json();
                
                if(data.branches && data.branches.length) {
                    const search = document.getElementById('modalSearchInput').value.toLowerCase();
                    const filtered = data.branches.filter(b => b.name.toLowerCase().includes(search));
                    
                    if (filtered.length > 0) {
                        tbody.innerHTML = filtered.map(b => `
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-800 cursor-pointer transition" onclick="openBranchDetails('${b.id}', '${b.name.replace(/'/g,"\\'")}')">
                               <td class="p-3 font-medium text-blue-600 dark:text-blue-400"><i class="fas fa-building mr-2"></i> ${b.name}</td>
                               <td class="p-3 text-center text-gray-500">${b.co_count}</td>
                               <td class="p-3 text-center text-gray-500">${b.clients}</td>
                               <td class="p-3 text-right font-mono text-success">₦${parseInt(b.total_savings).toLocaleString()}</td>
                               <td class="p-3 text-right font-mono text-error">₦${parseInt(b.withdrawals).toLocaleString()}</td>
                               <td class="p-3 text-right font-mono text-primary font-bold">₦${parseInt(b.net_savings).toLocaleString()}</td>
                               <td class="p-3 text-right font-mono text-warning">₦${parseInt(b.disbursements).toLocaleString()}</td>
                               <td class="p-3 text-right font-mono text-gray-600 dark:text-gray-300">₦${parseInt(b.collections).toLocaleString()}</td>
                               <td class="p-3 text-center">${b.overdue_count > 0 ? `<span class="px-2 py-0.5 rounded-full bg-red-100 text-red-600 font-bold">${b.overdue_count}</span>` : '-'}</td>
                            </tr>`).join('');
                    } else {
                        tbody.innerHTML = '<tr><td colspan="9" class="p-8 text-center text-gray-500">No matching branches.</td></tr>';
                    }
                } else {
                    tbody.innerHTML = '<tr><td colspan="9" class="p-8 text-center text-gray-500">No branches found in this area.</td></tr>';
                }
            } catch(e) { console.error(e); tbody.innerHTML = '<tr><td colspan="9" class="p-8 text-center text-red-500">Error loading data.</td></tr>'; }
        }

        async function openBranchDetails(branch_id, branch_name) {
            modalState.view = 'cos';
            modalState.branch = { id: branch_id, name: branch_name };
            
            document.getElementById('modalTitle').textContent = `Officers in ${branch_name}`;
            document.getElementById('modalSubtitle').textContent = `Performance by CO`;
            document.getElementById('modalBackButton').classList.remove('hidden');
            document.getElementById('modalSearchInput').placeholder = 'Search officers...';
            document.getElementById('modalSearchInput').value = '';
            
            const thead = document.getElementById('modalTableHead');
            thead.innerHTML = `<tr> <th class="p-3 text-xs uppercase text-gray-500">Officer Name</th> <th class="p-3 text-xs uppercase text-gray-500 text-center">Clients</th> <th class="p-3 text-xs uppercase text-gray-500 text-right">Deposits</th> <th class="p-3 text-xs uppercase text-gray-500 text-right">Withdrawals</th> <th class="p-3 text-xs uppercase text-gray-500 text-right">Net Savings</th> <th class="p-3 text-xs uppercase text-gray-500 text-right">Disbursed</th> <th class="p-3 text-xs uppercase text-gray-500 text-right">Collections</th> <th class="p-3 text-xs uppercase text-gray-500 text-center">Overdue</th> </tr>`;
            
            const tbody = document.getElementById('modalTableBody');
            tbody.innerHTML = '<tr><td colspan="8" class="p-8 text-center"><i class="fas fa-spinner fa-spin text-2xl text-primary"></i></td></tr>';
            
            const from = document.getElementById('date_from').value;
            const to = document.getElementById('date_to').value;
            
            try {
                const res = await fetch(`?ajax=1&action=branch_details&branch_id=${branch_id}&date_from=${from}&date_to=${to}`);
                const data = await res.json();
                
                if(data.cos && data.cos.length) {
                    const search = document.getElementById('modalSearchInput').value.toLowerCase();
                    const filtered = data.cos.filter(c => (c.name || c.username).toLowerCase().includes(search));
                    
                    if (filtered.length > 0) {
                        tbody.innerHTML = filtered.map(c => `
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-800 cursor-pointer transition" onclick="openOfficerDetails('${c.username}', '${(c.name || c.username).replace(/'/g,"\\'")}')">
                               <td class="p-3 font-medium text-gray-900 dark:text-white"><i class="fas fa-user-tie text-gray-400 mr-2"></i> ${c.name || c.username}</td>
                               <td class="p-3 text-center text-gray-500">${c.clients}</td>
                               <td class="p-3 text-right font-mono text-success">₦${parseInt(c.total_savings).toLocaleString()}</td>
                               <td class="p-3 text-right font-mono text-error">₦${parseInt(c.withdrawals).toLocaleString()}</td>
                               <td class="p-3 text-right font-mono text-primary font-bold">₦${parseInt(c.net_savings).toLocaleString()}</td>
                               <td class="p-3 text-right font-mono text-warning">₦${parseInt(c.disbursements).toLocaleString()}</td>
                               <td class="p-3 text-right font-mono text-gray-600 dark:text-gray-300">₦${parseInt(c.collections).toLocaleString()}</td>
                               <td class="p-3 text-center">${c.overdue_count > 0 ? `<span class="px-2 py-0.5 rounded-full bg-red-100 text-red-600 font-bold">${c.overdue_count}</span>` : '-'}</td>
                            </tr>`).join('');
                    } else {
                        tbody.innerHTML = '<tr><td colspan="8" class="p-8 text-center text-gray-500">No matching officers.</td></tr>';
                    }
                } else {
                    tbody.innerHTML = '<tr><td colspan="8" class="p-8 text-center text-gray-500">No officers found in this branch.</td></tr>';
                }
            } catch(e) { console.error(e); tbody.innerHTML = '<tr><td colspan="8" class="p-8 text-center text-red-500">Error loading data.</td></tr>'; }
        }

        async function openOfficerDetails(co_username, co_name) {
            modalState.view = 'unions';
            modalState.officer = { username: co_username, name: co_name };
            
            document.getElementById('modalTitle').textContent = `Unions: ${co_name || co_username}`;
            document.getElementById('modalSubtitle').textContent = `Branch: ${modalState.branch.name}`;
            document.getElementById('modalBackButton').classList.remove('hidden');
            document.getElementById('modalSearchInput').placeholder = 'Search unions...';
            document.getElementById('modalSearchInput').value = '';
            
            const thead = document.getElementById('modalTableHead');
            thead.innerHTML = `<tr> <th class="p-3 text-xs uppercase text-gray-500">Union Name</th> <th class="p-3 text-xs uppercase text-gray-500 text-center">Clients</th> <th class="p-3 text-xs uppercase text-gray-500 text-right">Deposits</th> <th class="p-3 text-xs uppercase text-gray-500 text-right">Withdrawals</th> <th class="p-3 text-xs uppercase text-gray-500 text-right">Collections</th> <th class="p-3 text-xs uppercase text-gray-500 text-center">Reg.</th> <th class="p-3 text-xs uppercase text-gray-500 text-center">Overdue</th> </tr>`;
            
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
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-800 cursor-pointer transition" onclick="openUnionDetails('${u.name.replace(/'/g,"\\'")}', event)">
                               <td class="p-3 font-medium text-gray-900 dark:text-white"><i class="fas fa-layer-group text-secondary mr-2"></i> ${u.name}</td>
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
            modalState.view = 'clients';
            modalState.union = union_name;
            
            document.getElementById('modalTitle').textContent = `Clients: ${union_name}`;
            document.getElementById('modalSubtitle').textContent = `${modalState.officer.name} - Activity Period`;
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
                const res = await fetch(`?ajax=1&action=union_details&co_username=${encodeURIComponent(modalState.officer.username)}&union_name=${encodeURIComponent(union_name)}&date_from=${from}&date_to=${to}&q=${encodeURIComponent(q)}`);
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

        function closeModal() {
            document.getElementById('detailModal').classList.add('hidden');
            modalState = { view: null, area: null, branch: null, officer: null, union: null };
            document.getElementById('modalSearchInput').value = '';
        }

        // --- Charts & UI Helpers ---
        function renderCharts() {
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
                        datasets: [{ 
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
        
        document.getElementById('themeToggle').addEventListener('click', () => {
            const isDark = document.documentElement.classList.toggle('dark');
            const theme = isDark ? 'dark' : 'light';
            localStorage.setItem('theme', theme);
            document.cookie = "theme=" + theme + "; path=/; max-age=" + (60*60*24*365);
            renderCharts();
        });
    </script>
</body>
</html>