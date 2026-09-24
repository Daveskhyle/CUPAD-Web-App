<?php
date_default_timezone_set('Africa/Lagos');
// zm/clients.php - Zonal Manager Client View
session_start();

// --- DATABASE CONNECTION ---
require_once __DIR__ . '/../includes/config.php';
$pdo = getDbConnection();

// --- AUTHENTICATION ---
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'zm') {
    header('Location: ../index.php');
    exit();
}

// --- Configuration ---
$current_username = $_SESSION['username'] ?? '';
$user_id = $_SESSION['user_id'];

// Fetch ZM's Zone ID
$stmt_z = $pdo->prepare("SELECT zone_id, full_name, profile_pic FROM users WHERE id = ?");
$stmt_z->execute([$user_id]);
$user_data = $stmt_z->fetch(PDO::FETCH_ASSOC);
$zone_id = $user_data['zone_id'] ?? 0;
$full_name = $user_data['full_name'] ?? 'Zonal Manager';
$profile_pic = $user_data['profile_pic'] ?? 'default_avatar.png';

if (!$zone_id) {
    die("Access denied: No zone assigned to this manager.");
}

// Fetch all branches in this ZM's zone
$stmt_br = $pdo->prepare("
    SELECT b.id, b.name, a.id as area_id, a.name as area_name 
    FROM branches b 
    JOIN areas a ON b.area_id = a.id 
    WHERE a.zone_id = ? AND b.status = 'active' 
    ORDER BY a.name ASC, b.name ASC
");
$stmt_br->execute([$zone_id]);
$zone_branches = $stmt_br->fetchAll(PDO::FETCH_ASSOC);
$branch_ids = array_column($zone_branches, 'id');

// Extract Unique Areas
$unique_areas =[];
foreach ($zone_branches as $br) {
    $unique_areas[$br['area_id']] = $br['area_name'];
}

// Properly quote the string IDs
$branch_ids_quoted = array_map(function($id) use ($pdo) {
    return $pdo->quote($id);
}, $branch_ids);
$branch_ids_str = empty($branch_ids_quoted) ? "'0'" : implode(',', $branch_ids_quoted);

// Fetch all Officers that have active clients in this zone's branches
$stmt_off = $pdo->prepare("
    SELECT DISTINCT c.officer_username as username, 
                    COALESCE(u.full_name, c.officer_username) as full_name, 
                    c.branch_id,
                    b.area_id
    FROM clients c
    LEFT JOIN users u ON c.officer_username = u.username
    LEFT JOIN branches b ON c.branch_id = b.id
    WHERE c.branch_id IN ($branch_ids_str) AND c.status = 'active'
    ORDER BY full_name ASC
");
$stmt_off->execute();
$zone_officers = $stmt_off->fetchAll(PDO::FETCH_ASSOC);

// --- Helper Functions ---
function format_naira(float $amount): string {
    return '₦' . number_format($amount, 0);
}

// --- AJAX HANDLERS ---

// 1. Update Guarantor
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax']) && $_POST['ajax'] === 'update_guarantor') {
    header('Content-Type: application/json');
    $client_id = $_POST['client_id'] ?? '';
    $g_name = trim($_POST['guarantor_name'] ?? '');
    $g_phone = trim($_POST['guarantor_phone'] ?? '');

    if (empty($client_id) || empty($g_name) || empty($g_phone)) {
        echo json_encode(['success' => false, 'message' => 'All fields are required.']);
        exit();
    }

    try {
        $stmt = $pdo->prepare("UPDATE clients SET guarantor_name = ?, guarantor_phone = ? WHERE id = ? AND branch_id IN ($branch_ids_str)");
        if ($stmt->execute([$g_name, $g_phone, $client_id])) {
            if($stmt->rowCount() > 0) {
                $sel = $pdo->prepare("SELECT id, guarantor_name, guarantor_phone FROM clients WHERE id = ?");
                $sel->execute([$client_id]);
                $updated_client = $sel->fetch(PDO::FETCH_ASSOC);
                echo json_encode(['success' => true, 'message' => 'Updated successfully', 'client' => $updated_client]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Client not found in your zone.']);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Update failed.']);
        }
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error.']);
    }
    exit();
}

// 2. Get Data
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_clients_data') {
    header('Content-Type: application/json');
    
    $page = max(1, intval($_GET['page'] ?? 1));
    $per_page = 15; 
    $offset = ($page - 1) * $per_page;
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $search_term = "%$search%";
    
    $filter_area = $_GET['area'] ?? 'all';
    $filter_branch = $_GET['branch'] ?? 'all';
    $filter_officer = $_GET['officer'] ?? 'all';

    $sort_param = $_GET['sort'] ?? 'name_asc';
    $sort_map =[
        'name_asc'     => 'c.name ASC',
        'savings_desc' => 'savings DESC, c.name ASC',
        'loan_desc'    => 'outstanding_loan DESC, c.name ASC',
        'officer_asc'  => 'officer_name ASC, c.name ASC'
    ];
    $order_by = $sort_map[$sort_param] ?? 'c.name ASC';

    $use_branch_filter = false;
    
    // Filter conditions based on Area and Branch
    if ($filter_branch !== 'all' && in_array($filter_branch, $branch_ids)) {
        $use_branch_filter = true;
        $branch_condition = "c.branch_id = :filter_branch";
    } elseif ($filter_area !== 'all') {
        $area_branch_ids = array_filter($zone_branches, fn($b) => $b['area_id'] == $filter_area);
        $valid_area_branches = array_intersect(array_column($area_branch_ids, 'id'), $branch_ids);
        
        if (!empty($valid_area_branches)) {
            $quoted_ab = array_map(function($id) use ($pdo) { return $pdo->quote($id); }, $valid_area_branches);
            $ab_str = implode(',', $quoted_ab);
            $branch_condition = "c.branch_id IN ($ab_str)";
        } else {
            $branch_condition = "1=0"; // No valid branches in this area
        }
    } else {
        $branch_condition = "c.branch_id IN ($branch_ids_str)";
    }

    // Filter condition based on Officer
    $use_officer_filter = ($filter_officer !== 'all');
    $officer_cond_sql = $use_officer_filter ? " AND c.officer_username = :filter_officer" : "";

    try {
        // Count Total
        $count_sql = "SELECT COUNT(id) FROM clients c WHERE $branch_condition $officer_cond_sql AND c.status = 'active' AND (c.name LIKE :s1 OR c.`union` LIKE :s2)";
        $stmt_count = $pdo->prepare($count_sql);
        if ($use_branch_filter) $stmt_count->bindValue(':filter_branch', $filter_branch);
        if ($use_officer_filter) $stmt_count->bindValue(':filter_officer', $filter_officer);
        $stmt_count->bindValue(':s1', $search_term);
        $stmt_count->bindValue(':s2', $search_term);
        $stmt_count->execute();
        $total_clients = $stmt_count->fetchColumn();

        // Total Savings
        $sav_sql = "SELECT SUM(sb.balance) FROM saving_balances sb JOIN clients c ON sb.client_id = c.id WHERE $branch_condition $officer_cond_sql AND c.status = 'active' AND (c.name LIKE :s1 OR c.`union` LIKE :s2)";
        $stmt_sav = $pdo->prepare($sav_sql);
        if ($use_branch_filter) $stmt_sav->bindValue(':filter_branch', $filter_branch);
        if ($use_officer_filter) $stmt_sav->bindValue(':filter_officer', $filter_officer);
        $stmt_sav->bindValue(':s1', $search_term);
        $stmt_sav->bindValue(':s2', $search_term);
        $stmt_sav->execute();
        $total_savings = floatval($stmt_sav->fetchColumn() ?? 0);

        // Total Loans
        $loan_sql = "SELECT SUM(d.remaining_balance) FROM disbursements d JOIN clients c ON d.client_id = c.id WHERE $branch_condition $officer_cond_sql AND d.remaining_balance > 0";
        $stmt_loan = $pdo->prepare($loan_sql);
        if ($use_branch_filter) $stmt_loan->bindValue(':filter_branch', $filter_branch);
        if ($use_officer_filter) $stmt_loan->bindValue(':filter_officer', $filter_officer);
        $stmt_loan->execute();
        $total_loans = floatval($stmt_loan->fetchColumn() ?? 0);

        // Fetch Clients
        $sql = "SELECT 
                    c.id, 
                    c.name, 
                    c.`union`, 
                    c.guarantor_name, 
                    c.guarantor_phone,
                    c.officer_username,
                    u.full_name as officer_name,
                    b.name as branch_name,
                    a.name as area_name,
                    (SELECT balance FROM saving_balances WHERE client_id = c.id LIMIT 1) as savings,
                    (SELECT SUM(remaining_balance) FROM disbursements d WHERE d.client_id = c.id AND d.remaining_balance > 0) as outstanding_loan
                FROM clients c
                LEFT JOIN users u ON c.officer_username = u.username
                LEFT JOIN branches b ON c.branch_id = b.id
                LEFT JOIN areas a ON b.area_id = a.id
                WHERE $branch_condition $officer_cond_sql
                  AND c.status = 'active' 
                  AND (c.name LIKE :s1 OR c.`union` LIKE :s2 OR u.full_name LIKE :s3)
                ORDER BY $order_by
                LIMIT :limit OFFSET :offset";
        
        $stmt = $pdo->prepare($sql);
        if ($use_branch_filter) $stmt->bindValue(':filter_branch', $filter_branch);
        if ($use_officer_filter) $stmt->bindValue(':filter_officer', $filter_officer);
        $stmt->bindValue(':s1', $search_term);
        $stmt->bindValue(':s2', $search_term);
        $stmt->bindValue(':s3', $search_term);
        $stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $display_data = [];
        foreach ($results as $row) {
            $row['savings'] = floatval($row['savings'] ?? 0);
            $row['outstanding_loan'] = floatval($row['outstanding_loan'] ?? 0);
            $row['guarantor_name'] = $row['guarantor_name'] ?? 'N/A';
            $row['guarantor_phone'] = $row['guarantor_phone'] ?? 'N/A';
            $row['officer_name'] = $row['officer_name'] ?? $row['officer_username'];
            $row['branch_name'] = $row['branch_name'] ?? 'Unknown Branch';
            $row['area_name'] = $row['area_name'] ?? 'Unknown Area';
            $display_data[] = $row;
        }

        $has_more = ($page * $per_page) < $total_clients;

        echo json_encode([
            'success' => true,
            'summary' =>[
                'total_clients' => $total_clients,
                'total_savings' => $total_savings,
                'total_loans' => $total_loans 
            ],
            'clients' => $display_data,
            'pagination' =>['has_more' => $has_more]
        ]);

    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit();
}

// 3. Get History
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_client_history') {
    header('Content-Type: application/json');
    $client_id = $_GET['id'] ?? '';
    
    try {
        $stmt = $pdo->prepare("SELECT c.name, c.officer_username, u.full_name as officer_name, c.branch_id, b.name as branch_name, a.name as area_name,
            (SELECT balance FROM saving_balances WHERE client_id = c.id LIMIT 1) as savings_bal,
            (SELECT SUM(remaining_balance) FROM disbursements d WHERE d.client_id = c.id AND d.remaining_balance > 0) as loan_bal
            FROM clients c 
            LEFT JOIN users u ON c.officer_username = u.username
            LEFT JOIN branches b ON c.branch_id = b.id
            LEFT JOIN areas a ON b.area_id = a.id
            WHERE c.id = ? AND c.branch_id IN ($branch_ids_str)");
        $stmt->execute([$client_id]);
        $client = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if(!$client) { 
            echo json_encode(['success'=>false, 'message'=>'Client not found or unauthorized']); 
            exit; 
        }
        $client_name = $client['name'];
        $savings_bal = floatval($client['savings_bal'] ?? 0);
        $loan_bal = floatval($client['loan_bal'] ?? 0);

        $timeline =[];

        // Savings
        $stmt = $pdo->prepare("SELECT transaction_id, amount, date, type FROM saving_collections WHERE client_id = ? ORDER BY date DESC LIMIT 40");
        $stmt->execute([$client_id]);
        while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $amount = floatval($row['amount']);
            $type = strtolower($row['type'] ?? 'deposit');
            $is_withdrawal = ($amount < 0) || in_array($type,['withdrawal', 'return_cash', 'return cash', 'debit', 'charge']);
            $display_amount = abs($amount);
            
            $description = 'Deposit';
            if (strpos($type, 'return') !== false || $type === 'return_cash') $description = 'Return Cash';
            elseif ($type === 'withdrawal' || $amount < 0) $description = 'Withdrawal';
            elseif ($type === 'transfer') $description = 'Transfer';
            elseif ($type === 'charge' || $type === 'fee') $description = 'Fee/Charges';
            
            $timeline[] =[
                'timestamp' => strtotime($row['date']),
                'date_formatted' => date('M d, Y h:i A', strtotime($row['date'])),
                'type' => 'savings',
                'title' => 'Savings Trans.',
                'amount' => format_naira($display_amount),
                'raw_amount' => $amount,
                'description' => $description,
                'icon' => 'fa-piggy-bank',
                'transaction_id' => $row['transaction_id'],
                'is_withdrawal' => $is_withdrawal
            ];
        }

        // Disbursements
        $stmt = $pdo->prepare("SELECT id, principal, date FROM disbursements WHERE client_id = ? ORDER BY date DESC LIMIT 20");
        $stmt->execute([$client_id]);
        while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $timeline[] =[
                'timestamp' => strtotime($row['date']),
                'date_formatted' => date('M d, Y h:i A', strtotime($row['date'])),
                'type' => 'loan',
                'title' => 'Disbursement',
                'amount' => format_naira($row['principal']),
                'description' => 'Loan Issued',
                'icon' => 'fa-hand-holding-usd',
                'transaction_id' => $row['id']
            ];
        }

        // Repayments
        $stmt = $pdo->prepare("SELECT transaction_id, amount_collected, date FROM loan_collections WHERE client_id = ? ORDER BY date DESC LIMIT 40");
        $stmt->execute([$client_id]);
        while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $timeline[] =[
                'timestamp' => strtotime($row['date']),
                'date_formatted' => date('M d, Y h:i A', strtotime($row['date'])),
                'type' => 'repayment',
                'title' => 'Repayment',
                'amount' => format_naira($row['amount_collected']),
                'description' => 'Loan Payment',
                'icon' => 'fa-money-bill-wave',
                'transaction_id' => $row['transaction_id']
            ];
        }

        usort($timeline, fn($a, $b) => $b['timestamp'] <=> $a['timestamp']);
        echo json_encode([
            'success' => true, 
            'client_name' => $client_name, 
            'officer_name' => $client['officer_name'] ?? $client['officer_username'],
            'branch_name' => $client['branch_name'] ?? 'Unknown',
            'area_name' => $client['area_name'] ?? 'Unknown Area',
            'summary' =>[
                'savings' => $savings_bal,
                'loan' => $loan_bal
            ],
            'history' => $timeline
        ]); 
    } catch (PDOException $e) {
        echo json_encode(['success'=>false, 'message'=>'DB Error']);
    }
    exit();
}

$base_path = '../';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#3b82f6">
    <title>Zone Clients | CUPAD ZM</title>
    
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    
    <script>
    tailwind.config = {
        darkMode: 'class',
        theme: {
            extend: {
                colors: { primary: '#3b82f6', success: '#22c55e', warning: '#f59e0b', error: '#ef4444' }
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
        
        body { font-family: 'Inter', sans-serif; background-color: var(--bg-primary); color: var(--text-primary); padding-bottom: 90px; -webkit-tap-highlight-color: transparent; scroll-behavior: smooth; }
        
        ::-webkit-scrollbar { width: 5px; height: 5px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
        html.dark ::-webkit-scrollbar-thumb { background: #475569; }

        .main-header { background: var(--bg-header); backdrop-filter: blur(12px); border-bottom: 1px solid var(--border-color); position: sticky; top: 0; z-index: 50; padding-top: env(safe-area-inset-top); }
        .navbar { display: flex; justify-content: space-between; align-items: center; padding: 0.8rem 1rem; max-width: 1280px; margin: 0 auto; }
        .logo { font-weight: 700; font-size: 1.25rem; color: var(--primary-color); display: flex; align-items: center; gap: 0.5rem; text-decoration: none; }
        .logo img { height: 32px; width: auto; }
        .profile-btn { width: 36px; height: 36px; border-radius: 50%; overflow: hidden; border: 2px solid var(--primary-color); background: var(--bg-secondary); cursor: pointer; display: flex; align-items: center; justify-content: center; position: relative; }
        .profile-btn img { width: 100%; height: 100%; object-fit: cover; }
        .profile-fallback-icon { font-size: 1.2rem; color: var(--text-secondary); }

        .dashboard-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1rem; margin-bottom: 1.5rem; }
        .dashboard-card { border-radius: var(--border-radius-xl); padding: 1.5rem; position: relative; overflow: hidden; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1); border: none; color: white; transition: transform 0.2s ease; }
        .dashboard-card:active { transform: scale(0.98); }
        .card-gradient-blue { background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); }
        .card-gradient-green { background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%); }
        .card-gradient-red { background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); }
        .card-bg-icon { position: absolute; right: -10px; bottom: -20px; font-size: 6rem; color: white; opacity: 0.1; transform: rotate(-10deg); pointer-events: none; }

        .search-container { background: var(--bg-card); border-radius: var(--border-radius-lg); padding: 0.25rem; border: 1px solid var(--border-color); flex-grow: 1; box-shadow: var(--shadow-sm); display: flex; align-items: center; }
        .search-input { width: 100%; padding: 0.75rem 1rem 0.75rem 2.75rem; border: none; border-radius: 1rem; background: transparent; color: var(--text-primary); font-size: 16px; outline: none; }
        .custom-select { background: var(--bg-card); border: 1px solid var(--border-color); color: var(--text-primary); border-radius: var(--border-radius-lg); padding: 0.5rem 1rem; font-size: 0.9rem; font-weight: 600; outline: none; cursor: pointer; box-shadow: var(--shadow-sm); }
        .refresh-btn { background: var(--bg-card); border: 1px solid var(--border-color); color: var(--text-secondary); border-radius: var(--border-radius-lg); width: 48px; display: flex; align-items: center; justify-content: center; cursor: pointer; box-shadow: var(--shadow-sm); transition: all 0.2s; }
        .refresh-btn:active { transform: scale(0.95); }
        .spin { animation: spin 0.8s cubic-bezier(0.4, 0, 0.2, 1) infinite; }
        @keyframes spin { 100% { transform: rotate(360deg); } }

        .client-list-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 1.25rem; }
        
        /* Ghost Loading Styles */
        .ghost-card { background: var(--bg-card); border-radius: var(--border-radius-lg); border: 1px solid var(--border-color); padding: 1.25rem; box-shadow: var(--shadow-sm); display: flex; flex-direction: column; gap: 1rem; }
        .ghost-element { background: linear-gradient(90deg, var(--bg-primary) 25%, var(--border-color) 50%, var(--bg-primary) 75%); background-size: 200% 100%; animation: ghostShimmer 1.5s infinite ease-in-out; border-radius: 6px; }
        @keyframes ghostShimmer { 0% { background-position: 200% 0; } 100% { background-position: -200% 0; } }
        
        /* Actual Card Styles */
        .client-card { background: var(--bg-card); border-radius: var(--border-radius-lg); border: 1px solid var(--border-color); padding: 1.25rem; box-shadow: var(--shadow-sm); display: flex; flex-direction: column; gap: 1rem; opacity: 0; animation: fadeInUp 0.4s ease forwards; }
        .client-card:hover { transform: translateY(-2px); box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); }
        
        @keyframes fadeInUp { from { opacity: 0; transform: translateY(15px); } to { opacity: 1; transform: translateY(0); } }
        
        .client-avatar { width: 44px; height: 44px; border-radius: 12px; background: linear-gradient(135deg, #eff6ff, #dbeafe); color: #3b82f6; display: flex; align-items: center; justify-content: center; font-weight: 700; }
        html.dark .client-avatar { background: linear-gradient(135deg, #1e3a8a, #1e40af); color: #bfdbfe; }

        .modal-backdrop { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); backdrop-filter: blur(5px); z-index: 200; display: none; align-items: center; justify-content: center; opacity: 0; }
        .modal-backdrop.active { opacity: 1; }
        .modal-card { background: var(--bg-card); width: 92%; max-width: 480px; border-radius: 1.5rem; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.4); transform: scale(0.95); transition: transform 0.3s; border: 1px solid var(--border-color); max-height: 85vh; overflow: hidden; display: flex; flex-direction: column; }
        .modal-backdrop.active .modal-card { transform: scale(1); }
        
        /* Mobile Nav */
        .mobile-bottom-nav { display: none; position: fixed; bottom: 0; left: 0; right: 0; background: var(--bg-secondary); border-top: 1px solid var(--border-color); padding: 0.5rem 1rem calc(0.5rem + env(safe-area-inset-bottom)); z-index: 100; justify-content: space-between; box-shadow: 0 -4px 10px rgba(0,0,0,0.05); }
        .nav-item { display: flex; flex-direction: column; align-items: center; justify-content: center; text-decoration: none; color: var(--text-secondary); font-size: 0.7rem; font-weight: 500; gap: 4px; flex: 1; }
        .nav-item i { font-size: 1.4rem; }
        .nav-item.active { color: var(--primary-color); }

        @media (max-width: 768px) {
            .mobile-bottom-nav { display: flex; }
            .modal-card { position: absolute; bottom: 0; width: 100%; max-width: none; border-radius: 1.5rem 1.5rem 0 0; transform: translateY(100%); height: 85vh; padding-top: 12px; }
            .modal-backdrop.active .modal-card { transform: translateY(0); }
            .dashboard-grid { display: flex; overflow-x: auto; scroll-snap-type: x mandatory; gap: 1rem; padding-bottom: 0.5rem; margin-right: -1rem; padding-right: 1.5rem; scrollbar-width: none; }
            .dashboard-grid::-webkit-scrollbar { display: none; }
            .dashboard-card { min-width: 85vw; scroll-snap-align: center; flex-shrink: 0; }
        }
        
        .toast { position: fixed; top: 1rem; left: 50%; transform: translateX(-50%); z-index: 999; width: 90%; max-width: 350px; background: var(--bg-secondary); border-left: 4px solid; padding: 1rem; border-radius: 12px; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.15); display: flex; align-items: center; gap: 12px; animation: slideDown 0.4s cubic-bezier(0.34, 1.56, 0.64, 1); }
    </style>
</head>
<body>

    <!-- HEADER -->
    <header class="main-header">
        <nav class="navbar">
            <a href="dashboard.php" class="logo">
                <img src="<?php echo htmlspecialchars($base_path . 'favicon.ico'); ?>" alt="Logo">
                <span>CUPAD ZM</span>
            </a>
            
            <div class="flex items-center gap-3">
                <button id="theme-toggle" class="p-2 text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800 rounded-full transition">
                    <i class="fas fa-moon dark:hidden"></i><i class="fas fa-sun hidden dark:inline"></i>
                </button>
                
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
        <div class="mb-6">
            <h1 class="text-2xl font-extrabold text-gray-900 dark:text-white tracking-tight">Zone Clients</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Overview of all clients within your assigned zone.</p>
        </div>

        <!-- STATS -->
        <div class="dashboard-grid">
            <div class="dashboard-card card-gradient-blue">
                <i class="fas fa-users card-bg-icon"></i>
                <div class="flex justify-between items-start relative z-10">
                    <div class="min-w-0">
                        <div class="text-sm font-semibold uppercase opacity-90">Total Clients</div>
                        <div class="text-2xl lg:text-3xl font-extrabold mt-1 mb-2 truncate" id="totalClients">-</div>
                        <div class="text-xs bg-black/20 rounded-full px-3 py-1 inline-flex items-center"><i class="fas fa-users mr-1"></i> Active in Zone</div>
                    </div>
                </div>
            </div>
            <div class="dashboard-card card-gradient-green">
                <i class="fas fa-wallet card-bg-icon"></i>
                <div class="flex justify-between items-start relative z-10">
                    <div class="min-w-0">
                        <div class="text-sm font-semibold uppercase opacity-90">Zone Savings</div>
                        <div class="text-2xl lg:text-3xl font-extrabold mt-1 mb-2 truncate" id="totalSavings">-</div>
                        <div class="text-xs bg-black/20 rounded-full px-3 py-1 inline-flex items-center"><i class="fas fa-piggy-bank mr-1"></i> Total Balance</div>
                    </div>
                </div>
            </div>
            <div class="dashboard-card card-gradient-red">
                <i class="fas fa-hand-holding-usd card-bg-icon"></i>
                <div class="flex justify-between items-start relative z-10">
                    <div class="min-w-0">
                        <div class="text-sm font-semibold uppercase opacity-90">Outstanding Loans</div>
                        <div class="text-2xl lg:text-3xl font-extrabold mt-1 mb-2 truncate" id="totalLoans">-</div>
                        <div class="text-xs bg-black/20 rounded-full px-3 py-1 inline-flex items-center"><i class="fas fa-file-invoice-dollar mr-1"></i> Zone Remaining</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- CONTROLS -->
        <div class="flex flex-col md:flex-row gap-3 mb-6">
            <div class="search-container md:flex-1 w-full">
                <div class="relative w-full">
                    <i class="fas fa-search absolute left-4 top-1/2 -translate-y-1/2 text-gray-400"></i>
                    <input type="text" id="searchInput" class="search-input" placeholder="Search name, union, or officer...">
                </div>
            </div>
            <div class="flex flex-wrap sm:flex-nowrap gap-2 w-full md:w-auto">
                <select id="areaSelect" class="custom-select flex-1 sm:w-auto" onchange="updateBranches(); updateOfficers(); resetAndLoad()">
                    <option value="all">All Areas</option>
                    <?php foreach ($unique_areas as $a_id => $a_name): ?>
                        <option value="<?php echo htmlspecialchars($a_id); ?>">
                            <?php echo htmlspecialchars($a_name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <select id="branchSelect" class="custom-select flex-1 sm:w-auto" onchange="updateOfficers(); resetAndLoad()">
                    <option value="all">All Branches</option>
                    <?php foreach ($zone_branches as $br): ?>
                        <option value="<?php echo htmlspecialchars($br['id']); ?>">
                            <?php echo htmlspecialchars($br['area_name'] . ' - ' . $br['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <select id="officerSelect" class="custom-select flex-1 sm:w-auto" onchange="resetAndLoad()">
                    <option value="all">All Officers</option>
                    <?php 
                    $unique_officers_display =[];
                    foreach ($zone_officers as $off) {
                        if (!isset($unique_officers_display[$off['username']])) {
                            $unique_officers_display[$off['username']] = $off['full_name'];
                            echo '<option value="' . htmlspecialchars($off['username']) . '">' . htmlspecialchars($off['full_name']) . '</option>';
                        }
                    }
                    ?>
                </select>
                <select id="sortSelect" class="custom-select flex-1 sm:w-auto" onchange="resetAndLoad()">
                    <option value="name_asc">Name (A-Z)</option>
                    <option value="savings_desc">Highest Savings</option>
                    <option value="loan_desc">Highest Loan</option>
                    <option value="officer_asc">By Officer</option>
                </select>
                <button class="refresh-btn shrink-0" id="refreshBtn" onclick="forceRefresh()" title="Refresh Data">
                    <i class="fas fa-sync-alt"></i>
                </button>
            </div>
        </div>

        <!-- CLIENT LIST -->
        <div id="clientList" class="client-list-grid"></div>
        
        <div id="loadMoreContainer" class="text-center mt-8 hidden pb-8">
            <button id="loadMoreBtn" class="px-8 py-3 bg-white dark:bg-slate-800 border dark:border-slate-700 rounded-full text-sm font-bold shadow-sm hover:shadow-md transition-all" onclick="loadClients(true)">Load More</button>
        </div>
    </main>

    <!-- ZONAL MANAGER BOTTOM NAV -->
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
        <a href="clients.php" class="nav-item active">
             <div style="background: var(--primary-color); width: 45px; height: 45px; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; margin-top: -25px; box-shadow: 0 4px 10px rgba(59, 130, 246, 0.4);">
                <i class="fas fa-users" style="font-size: 1.2rem; margin:0;"></i>
             </div>
             <span style="font-size:0.65rem; font-weight:600; margin-top:2px;">Clients</span>
        </a>
        <a href="analytics.php" class="nav-item">
            <i class="fas fa-chart-bar"></i>
            <span>Reports</span>
        </a>
    </nav>

    <!-- EDIT MODAL -->
    <div id="editModal" class="modal-backdrop">
        <div class="modal-card">
            <div class="px-6 py-5 border-b border-gray-100 dark:border-slate-800 flex justify-between items-center">
                <div>
                    <span class="font-extrabold text-lg">Edit Guarantor</span>
                    <div class="text-xs text-gray-500 mt-0.5">Update client security details</div>
                </div>
                <button type="button" class="w-9 h-9 rounded-full bg-gray-100 dark:bg-slate-800 flex items-center justify-center text-gray-500 hover:bg-gray-200" onclick="closeModal('editModal')"><i class="fas fa-times"></i></button>
            </div>
            <form id="editForm" class="flex flex-col flex-1 overflow-hidden">
                <div class="p-6 overflow-y-auto">
                    <input type="hidden" id="editClientId" name="client_id">
                    <div class="mb-5">
                        <label class="block text-xs font-bold text-gray-500 mb-1.5 uppercase">Client Name</label>
                        <input type="text" id="editClientName" class="w-full p-3.5 bg-gray-50 dark:bg-slate-800/50 border dark:border-slate-700 rounded-xl text-gray-600 font-medium cursor-not-allowed" disabled>
                    </div>
                    <div class="mb-5">
                        <label class="block text-xs font-bold text-gray-500 mb-1.5 uppercase">Guarantor Name</label>
                        <input type="text" id="editGuarantorName" name="guarantor_name" class="w-full p-3.5 bg-white dark:bg-slate-900 border dark:border-slate-700 rounded-xl font-medium focus:ring-2 focus:ring-blue-500 outline-none" required>
                    </div>
                    <div class="mb-2">
                        <label class="block text-xs font-bold text-gray-500 mb-1.5 uppercase">Guarantor Phone</label>
                        <input type="tel" id="editGuarantorPhone" name="guarantor_phone" class="w-full p-3.5 bg-white dark:bg-slate-900 border dark:border-slate-700 rounded-xl font-medium font-mono focus:ring-2 focus:ring-blue-500 outline-none" required>
                    </div>
                </div>
                <div class="p-5 border-t border-gray-100 dark:border-slate-800 flex gap-3 bg-gray-50/80 dark:bg-slate-900/80">
                    <button type="button" class="flex-1 py-3.5 rounded-xl border dark:border-slate-600 font-bold" onclick="closeModal('editModal')">Cancel</button>
                    <button type="submit" class="flex-1 py-3.5 rounded-xl bg-blue-600 hover:bg-blue-700 text-white font-bold shadow-lg transition-all">Save</button>
                </div>
            </form>
        </div>
    </div>

    <!-- HISTORY MODAL -->
    <div id="historyModal" class="modal-backdrop">
        <div class="modal-card">
            <div class="px-6 py-5 border-b border-gray-100 dark:border-slate-800 flex justify-between items-start shrink-0">
                <div class="w-full pr-4">
                    <h3 class="font-extrabold text-xl truncate" id="historyModalTitle">History</h3>
                    <div id="historyModalSubtitle" class="mt-1.5"></div>
                </div>
                <button class="w-9 h-9 rounded-full bg-gray-100 dark:bg-slate-800 flex items-center justify-center text-gray-500 flex-shrink-0" onclick="closeModal('historyModal')"><i class="fas fa-times"></i></button>
            </div>
            <div id="historyFilters" class="flex w-full border-b border-gray-100 dark:border-slate-800 bg-white dark:bg-slate-900 z-10 shrink-0" style="display:none;">
                <button class="flex-1 py-3.5 text-[13px] font-bold text-blue-600 border-b-2 border-blue-600 history-tab" onclick="filterHistory('all', this)">All</button>
                <button class="flex-1 py-3.5 text-[13px] font-bold text-gray-500 border-b-2 border-transparent history-tab" onclick="filterHistory('savings', this)">Save</button>
                <button class="flex-1 py-3.5 text-[13px] font-bold text-gray-500 border-b-2 border-transparent history-tab" onclick="filterHistory('loan', this)">Loan</button>
                <button class="flex-1 py-3.5 text-[13px] font-bold text-gray-500 border-b-2 border-transparent history-tab" onclick="filterHistory('repayment', this)">Repay</button>
            </div>
            <div class="p-4 overflow-y-auto flex-1 bg-gray-50/50 dark:bg-slate-900/50" id="historyModalBody"></div>
        </div>
    </div>

    <script>
        const zoneBranches = <?php echo json_encode($zone_branches); ?>;
        const zoneOfficers = <?php echo json_encode($zone_officers); ?>;
        
        let currentPage = 1;
        let isLoading = false;
        let currentClientHistory =[];
        
        const $ = id => document.getElementById(id);
        const formatMoney = num => '₦' + Number(num).toLocaleString('en-US');

        // Theme Init
        if(localStorage.getItem('theme') === 'dark') document.documentElement.classList.add('dark');
        $('theme-toggle').onclick = () => {
            document.documentElement.classList.toggle('dark');
            localStorage.setItem('theme', document.documentElement.classList.contains('dark') ? 'dark' : 'light');
        };

        function showToast(msg, type) {
            const el = document.createElement('div');
            el.className = 'toast';
            el.style.borderLeftColor = type === 'success' ? '#22c55e' : '#ef4444';
            el.innerHTML = `<i class="fas ${type==='success'?'fa-check-circle text-green-500':'fa-exclamation-circle text-red-500'} text-xl"></i> <span class="text-sm font-semibold">${msg}</span>`;
            document.body.appendChild(el);
            setTimeout(() => { el.style.opacity = '0'; setTimeout(() => el.remove(), 400); }, 3000);
        }

        function closeModal(id) { $(id).classList.remove('active'); setTimeout(() => $(id).style.display = 'none', 300); }
        function openModal(id) { $(id).style.display = 'flex'; setTimeout(() => $(id).classList.add('active'), 10); }

        function updateBranches() {
            const areaId = $('areaSelect').value;
            const branchSelect = $('branchSelect');
            
            // Clear current options
            branchSelect.innerHTML = '<option value="all">All Branches</option>';
            
            // Filter branches depending on selected area
            const filteredBranches = areaId === 'all' 
                ? zoneBranches 
                : zoneBranches.filter(b => b.area_id == areaId);
                
            // Populate branch dropdown
            filteredBranches.forEach(b => {
                const opt = document.createElement('option');
                opt.value = b.id;
                opt.textContent = areaId === 'all' ? `${b.area_name} - ${b.name}` : b.name;
                branchSelect.appendChild(opt);
            });
        }

        function updateOfficers() {
            const areaId = $('areaSelect').value;
            const branchId = $('branchSelect').value;
            const officerSelect = $('officerSelect');
            
            // Clear current options
            officerSelect.innerHTML = '<option value="all">All Officers</option>';
            
            // Filter officers
            const filteredOfficers = zoneOfficers.filter(o => {
                if (branchId !== 'all') {
                    return o.branch_id == branchId;
                } else if (areaId !== 'all') {
                    return o.area_id == areaId;
                }
                return true; // if 'all' branches and 'all' areas are selected
            });
            
            // Keep unique officers by username (since one officer may be active in multiple branches)
            const uniqueOfficers =[];
            const seen = new Set();
            for (const o of filteredOfficers) {
                if (!seen.has(o.username)) {
                    seen.add(o.username);
                    uniqueOfficers.push(o);
                }
            }

            // Populate officer dropdown
            uniqueOfficers.forEach(o => {
                const opt = document.createElement('option');
                opt.value = o.username;
                opt.textContent = o.full_name;
                officerSelect.appendChild(opt);
            });
        }

        // Ghost Loading Render Function
        function renderSkeleton() {
            let html = '';
            for(let i=0; i<6; i++) {
                html += `
                <div class="ghost-card">
                    <div class="flex justify-between items-start">
                        <div class="flex gap-3 items-center">
                            <div class="ghost-element w-11 h-11 rounded-xl"></div>
                            <div>
                                <div class="ghost-element h-4 w-32 mb-2 rounded"></div>
                                <div class="ghost-element h-3 w-16 rounded-full"></div>
                            </div>
                        </div>
                        <div class="ghost-element w-9 h-9 rounded-full"></div>
                    </div>
                    <div class="flex gap-2 rounded-xl p-3 border dark:border-slate-700 bg-transparent">
                        <div class="flex-1"><div class="ghost-element h-4 w-full rounded mb-2"></div><div class="ghost-element h-4 w-3/4 rounded mx-auto"></div></div>
                        <div style="width:1px; background:var(--border-color);"></div>
                        <div class="flex-1"><div class="ghost-element h-4 w-full rounded mb-2"></div><div class="ghost-element h-4 w-3/4 rounded mx-auto"></div></div>
                    </div>
                    <div class="ghost-element h-12 w-full rounded-xl"></div>
                    <div class="flex gap-3">
                        <div class="ghost-element h-10 flex-1 rounded-lg"></div>
                        <div class="ghost-element h-10 flex-1 rounded-lg"></div>
                    </div>
                </div>`;
            }
            $('clientList').innerHTML = html;
        }

        async function loadClients(append = false) {
            if(isLoading) return;
            isLoading = true;
            
            const search = $('searchInput').value;
            const sort = $('sortSelect').value;
            const area = $('areaSelect').value;
            const branch = $('branchSelect').value;
            const officer = $('officerSelect').value;
            const page = append ? currentPage + 1 : 1;
            
            if(!append) renderSkeleton();
            else $('loadMoreBtn').innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Loading...';

            try {
                const res = await fetch(`?ajax=get_clients_data&page=${page}&search=${encodeURIComponent(search)}&sort=${sort}&area=${area}&branch=${branch}&officer=${encodeURIComponent(officer)}`);
                const data = await res.json();

                if(data.success) {
                    if(!append) {
                        $('totalClients').textContent = data.summary.total_clients;
                        $('totalSavings').textContent = formatMoney(data.summary.total_savings);
                        $('totalLoans').textContent = formatMoney(data.summary.total_loans);
                        $('clientList').innerHTML = '';
                    }

                    if(data.clients.length === 0 && !append) {
                        $('clientList').innerHTML = `
                            <div class="col-span-full text-center py-20 text-gray-400 flex flex-col items-center w-full">
                                <i class="fas fa-users text-4xl mb-4 opacity-50"></i>
                                <p class="text-lg font-bold">No clients found</p>
                            </div>`;
                    } else {
                        data.clients.forEach((c, index) => {
                            const delay = (index % 10) * 0.05; 
                            const initials = c.name.split(' ').map(n=>n[0]).join('').substring(0,2).toUpperCase();
                            
                            let statusColor = '#22c55e'; 
                            if(c.outstanding_loan > 0) statusColor = (c.outstanding_loan > c.savings) ? '#ef4444' : '#f59e0b';

                            const card = document.createElement('div');
                            card.className = 'client-card';
                            card.style.animationDelay = `${delay}s`;
                            card.innerHTML = `
                                <div class="flex justify-between items-start">
                                    <div class="flex gap-3 items-center">
                                        <div class="relative">
                                            <div class="client-avatar">${initials}</div>
                                            <div class="status-dot" style="background-color: ${statusColor}; width: 14px; height: 14px; border-radius: 50%; border: 2px solid var(--bg-card); position: absolute; bottom: -2px; right: -2px;"></div>
                                        </div>
                                        <div>
                                            <h3 class="font-bold text-gray-900 dark:text-white truncate w-36">${c.name}</h3>
                                            <span class="text-[10px] font-semibold bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-gray-300 px-2 py-0.5 rounded-full uppercase">${c.union}</span>
                                            <div class="text-[10px] font-bold text-blue-600 dark:text-blue-400 mt-1 flex items-center gap-1">
                                                <i class="fas fa-user-tie"></i> ${c.officer_name}
                                            </div>
                                            <div class="text-[10px] font-bold text-indigo-600 dark:text-indigo-400 mt-0.5 flex items-center gap-1 truncate w-40">
                                                <i class="fas fa-map-marker-alt"></i> ${c.area_name} &bull; ${c.branch_name}
                                            </div>
                                        </div>
                                    </div>
                                    <button class="w-9 h-9 rounded-full bg-blue-50 dark:bg-slate-800 text-blue-500 dark:text-blue-400 flex items-center justify-center hover:bg-blue-100" onclick="viewHistory('${c.id}')"><i class="fas fa-history text-sm"></i></button>
                                </div>
                                
                                <div class="flex gap-2 bg-gray-50 dark:bg-slate-800/50 rounded-xl p-3 border dark:border-slate-700 mt-2">
                                    <div class="flex-1 text-center">
                                        <span class="text-[10px] text-gray-500 font-semibold uppercase">Savings</span>
                                        <div class="text-sm font-extrabold text-green-600 dark:text-green-400">${formatMoney(c.savings)}</div>
                                    </div>
                                    <div style="width:1px; background:var(--border-color);"></div>
                                    <div class="flex-1 text-center">
                                        <span class="text-[10px] text-gray-500 font-semibold uppercase">Loan Bal</span>
                                        <div class="text-sm font-extrabold ${c.outstanding_loan > 0 ? 'text-red-500' : 'text-gray-400'}">${formatMoney(c.outstanding_loan)}</div>
                                    </div>
                                </div>

                                <div class="text-xs bg-gray-50 dark:bg-slate-800/50 p-3 rounded-xl border dark:border-slate-700 flex justify-between items-center">
                                    <span class="flex items-center gap-2 truncate"><i class="fas fa-user-shield text-gray-400"></i> <span class="font-semibold truncate w-24">${c.guarantor_name}</span></span>
                                    ${c.guarantor_phone !== 'N/A' ? `<a href="tel:${c.guarantor_phone}" class="font-mono text-blue-600 text-[11px]">${c.guarantor_phone}</a>` : '<span class="text-gray-400 text-[11px]">N/A</span>'}
                                </div>

                                <div class="flex gap-2 mt-auto">
                                    <button class="flex-1 py-2 rounded-lg bg-white dark:bg-slate-700 border dark:border-slate-600 text-xs font-bold hover:bg-gray-50" onclick="editGuarantor('${c.id}', '${c.name.replace(/'/g, "\\'")}', this)">Edit</button>
                                    <button class="flex-1 py-2 rounded-lg bg-blue-600 text-white text-xs font-bold hover:bg-blue-700 shadow-md" onclick="viewHistory('${c.id}')">View History</button>
                                </div>
                            `;
                            $('clientList').appendChild(card);
                        });
                    }
                    currentPage = page;
                    $('loadMoreContainer').style.display = data.pagination.has_more ? 'block' : 'none';
                } else {
                    if (!append) {
                        $('totalClients').textContent = '0';
                        $('totalSavings').textContent = '₦0';
                        $('totalLoans').textContent = '₦0';
                        $('clientList').innerHTML = `
                            <div class="col-span-full text-center py-20 text-red-500 flex flex-col items-center w-full">
                                <i class="fas fa-exclamation-triangle text-4xl mb-4 opacity-50"></i>
                                <p class="text-lg font-bold">Error loading clients</p>
                                <p class="text-sm mt-2">${data.message || 'Unknown error occurred.'}</p>
                            </div>`;
                    }
                    showToast(data.message || 'Failed to load data', 'error');
                }
            } catch(e) {
                if (!append) {
                    $('clientList').innerHTML = `
                        <div class="col-span-full text-center py-20 text-red-500 flex flex-col items-center w-full">
                            <i class="fas fa-wifi text-4xl mb-4 opacity-50"></i>
                            <p class="text-lg font-bold">Connection Error</p>
                        </div>`;
                }
                showToast('Failed to connect to server', 'error');
            } finally {
                isLoading = false;
                if(append) $('loadMoreBtn').innerHTML = 'Load More';
            }
        }

        // Edit & History Functions
        function editGuarantor(id, name, btnEl) {
            const card = btnEl.closest('.client-card');
            const gName = card.querySelector('.font-semibold.truncate').textContent;
            const phoneEl = card.querySelector('a[href^="tel"]');
            const gPhone = phoneEl ? phoneEl.textContent.trim() : '';

            $('editClientId').value = id;
            $('editClientName').value = name;
            $('editGuarantorName').value = gName === 'N/A' ? '' : gName;
            $('editGuarantorPhone').value = gPhone;
            openModal('editModal');
        }

        $('editForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            const btn = this.querySelector('button[type="submit"]');
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
            btn.disabled = true;
            try {
                const fd = new FormData(this);
                fd.append('ajax', 'update_guarantor');
                const res = await fetch('', { method: 'POST', body: fd });
                const data = await res.json();
                if(data.success) {
                    showToast('Updated successfully', 'success');
                    closeModal('editModal');
                    forceRefresh(); 
                } else {
                    showToast(data.message, 'error');
                }
            } catch(err) { showToast('Network error', 'error'); } 
            finally { btn.innerHTML = 'Save'; btn.disabled = false; }
        });

        async function viewHistory(id) {
            openModal('historyModal');
            $('historyFilters').style.display = 'none';
            $('historyModalTitle').textContent = 'Loading...';
            $('historyModalBody').innerHTML = '<div class="p-4 text-center"><i class="fas fa-spinner fa-spin text-2xl text-blue-500"></i></div>';
            
            try {
                const res = await fetch(`?ajax=get_client_history&id=${id}`);
                const data = await res.json();
                
                if(data.success) {
                    $('historyModalTitle').textContent = data.client_name;
                    $('historyModalSubtitle').innerHTML = `
                        <div class="flex gap-2 items-center flex-wrap">
                            <span class="text-[11px] font-bold bg-indigo-50 text-indigo-700 dark:bg-indigo-900/30 dark:text-indigo-400 px-2 py-1 rounded border border-indigo-100">
                                <i class="fas fa-map-marker-alt mr-1"></i> ${data.area_name}
                            </span>
                            <span class="text-[11px] font-bold bg-indigo-50 text-indigo-700 dark:bg-indigo-900/30 dark:text-indigo-400 px-2 py-1 rounded border border-indigo-100">
                                <i class="fas fa-building mr-1"></i> ${data.branch_name}
                            </span>
                            <span class="text-[11px] font-bold bg-blue-50 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400 px-2 py-1 rounded border border-blue-100">
                                <i class="fas fa-user-tie mr-1"></i> ${data.officer_name}
                            </span>
                            <span class="text-[11px] font-bold bg-green-50 text-green-700 dark:bg-green-900/30 dark:text-green-400 px-2 py-1 rounded border border-green-100">
                                Save: ${formatMoney(data.summary.savings)}
                            </span>
                            <span class="text-[11px] font-bold bg-orange-50 text-orange-700 dark:bg-orange-900/30 dark:text-orange-400 px-2 py-1 rounded border border-orange-100">
                                Loan: ${formatMoney(data.summary.loan)}
                            </span>
                        </div>
                    `;
                    currentClientHistory = data.history;
                    if(currentClientHistory.length > 0) $('historyFilters').style.display = 'flex';
                    renderHistory('all');
                } else {
                    $('historyModalBody').innerHTML = `<div class="text-center py-12 text-red-500">${data.message}</div>`;
                }
            } catch(e) {
                $('historyModalBody').innerHTML = '<div class="text-center py-12 text-red-500">Failed to load.</div>';
            }
        }

        function filterHistory(type, btnElement) {
            document.querySelectorAll('.history-tab').forEach(btn => {
                btn.className = 'flex-1 py-3.5 text-[13px] font-bold text-center border-b-2 border-transparent text-gray-500 history-tab';
            });
            btnElement.className = 'flex-1 py-3.5 text-[13px] font-bold text-blue-600 border-b-2 border-blue-600 history-tab';
            renderHistory(type);
        }

        function renderHistory(filterType) {
            const filtered = filterType === 'all' ? currentClientHistory : currentClientHistory.filter(item => item.type === filterType);
            if(filtered.length === 0) {
                $('historyModalBody').innerHTML = `<div class="text-center py-16 text-gray-400">No transactions found.</div>`;
                return;
            }
            
            let html = '';
            filtered.forEach((item, index) => {
                const isWithdrawal = item.is_withdrawal || item.description === 'Withdrawal' || item.description === 'Return Cash';
                let colorClass = item.type === 'savings' ? (isWithdrawal ? 'text-red-500' : 'text-green-500') : (item.type === 'loan' ? 'text-orange-500' : 'text-purple-500');
                let bgClass = item.type === 'savings' ? (isWithdrawal ? 'bg-red-50 dark:bg-red-900/20' : 'bg-green-50 dark:bg-green-900/20') : (item.type === 'loan' ? 'bg-orange-50 dark:bg-orange-900/20' : 'bg-purple-50 dark:bg-purple-900/20');
                
                html += `
                <div class="flex items-center justify-between p-3 bg-white dark:bg-slate-800 rounded-xl mb-2 shadow-sm border dark:border-slate-700" style="animation: fadeInUp 0.3s ease forwards; animation-delay: ${index*0.03}s; opacity:0">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl flex items-center justify-center ${bgClass} ${colorClass}">
                            <i class="fas ${item.icon}"></i>
                        </div>
                        <div>
                            <div class="font-bold text-sm">${item.title}</div>
                            <div class="text-[10px] text-gray-500">${item.date_formatted}</div>
                        </div>
                    </div>
                    <div class="text-right">
                        <div class="font-extrabold ${colorClass}">${item.amount}</div>
                        <div class="text-[9px] uppercase font-semibold text-gray-400">${item.description}</div>
                    </div>
                </div>`;
            });
            $('historyModalBody').innerHTML = html;
        }

        // Search Events
        let searchTimeout;
        $('searchInput').addEventListener('input', () => {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => { currentPage=1; loadClients(false); }, 400);
        });
        
        function resetAndLoad() { currentPage = 1; loadClients(false); }
        function forceRefresh() {
            const btn = $('refreshBtn').querySelector('i');
            btn.classList.add('spin');
            resetAndLoad();
            setTimeout(() => btn.classList.remove('spin'), 800); 
        }

        // Initial Load
        document.addEventListener('DOMContentLoaded', () => loadClients(false));
    </script>
</body>
</html>