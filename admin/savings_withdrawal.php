<?php
// admin/saving_withdrawal.php
date_default_timezone_set('Africa/Lagos');
session_start();

$base_path = '../';

// --- DATABASE CONNECTION ---
require_once $base_path . 'includes/config.php';
$pdo = getDbConnection();

// Access Control - ADMIN ONLY
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: ../index.php');
    exit();
}

$admin_username = $_SESSION['username'] ?? 'Admin';
$full_name = $_SESSION['full_name'] ?? 'Admin';
$role = $_SESSION['user_role'] ?? 'admin';

// Profile Picture Fetch
$my_pic = 'default_avatar.png';
try {
    $stmt = $pdo->prepare("SELECT profile_pic FROM users WHERE username = ?");
    $stmt->execute([$admin_username]);
    $user_data = $stmt->fetch();
    $my_pic = $user_data['profile_pic'] ?? 'default_avatar.png';
} catch (Exception $e) { }

$profile_pic_path = $base_path . 'uploads/' . $my_pic;
$has_profile_pic = file_exists($profile_pic_path) && $my_pic !== 'default_avatar.png';

define('LAPSED_FUND_CLIENT_ID', 'COMPANY-FUND-LAPSED');

// --- AUTO-CREATE LAPSED SAVINGS HISTORY TABLE ---
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS lapsed_savings_history (
        id INT AUTO_INCREMENT PRIMARY KEY,
        transaction_id VARCHAR(50) NOT NULL,
        client_id VARCHAR(50) NOT NULL,
        client_name VARCHAR(255) NOT NULL,
        amount DECIMAL(15,2) NOT NULL,
        branch VARCHAR(100) DEFAULT 'Unassigned',
        co VARCHAR(100) DEFAULT 'Unassigned',
        zo VARCHAR(100) DEFAULT 'Unassigned',
        area VARCHAR(100) DEFAULT 'Unassigned',
        date DATETIME NOT NULL,
        processed_by VARCHAR(100) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
} catch (Exception $e) {
    // Fail silently if table already exists or permission issues
}

// Helper: UUID
function generateUUID() {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

// --- LOAD SETTINGS ---
try {
    $stmt = $pdo->query("SELECT * FROM withdrawal_settings LIMIT 1");
    $w_settings = $stmt->fetch() ?:[
        'max_cash_withdrawal' => 50000, 'require_image_for_cash' => 1,
        'allow_weekend_withdrawals' => 0, 'max_withdrawals_per_day' => 1,
        'blocked_withdrawal_types' => '[]', 'withdrawal_date_readonly' => 0,
        'buffer_cash' => 10, 'buffer_withdrawal' => 10, 'buffer_return' => 10, 'buffer_adjust' => 10
    ];
} catch (Exception $e) {
    die("Error loading settings: " . $e->getMessage());
}

// --- AJAX POST HANDLING (TRANSACTIONS) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'process_withdrawal') {
    header('Content-Type: application/json');
    $client_id = trim($_POST['client_id'] ?? '');
    $amount = floatval($_POST['amount'] ?? 0);
    $type = $_POST['withdrawal_type'] ?? 'cash';
    $input_date = $_POST['date'] ?? date('Y-m-d H:i:s');
    $officer = $_SESSION['username'];
    
    try {
        $pdo->beginTransaction();

        // 1. Get Client (With Hierarchy Details) & Current Balance
        $stmt = $pdo->prepare("
            SELECT c.*, sb.balance 
            FROM clients c 
            LEFT JOIN saving_balances sb ON c.id = sb.client_id 
            WHERE c.id = ? FOR UPDATE
        ");
        $stmt->execute([$client_id]);
        $client = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$client) throw new Exception("Client not found.");
        
        $current_balance = floatval($client['balance'] ?? 0);
        $client_name = $client['name'];
        
        // Fetch organization hierarchy from client profile
        $c_branch = $client['branch'] ?? 'Unassigned';
        $c_co = $client['co'] ?? ($client['officer'] ?? 'Unassigned');
        $c_zo = $client['zo'] ?? 'Unassigned';
        $c_area = $client['area'] ?? 'Unassigned';

        // 2. Get Active Loan Info
        $stmt = $pdo->prepare("
            SELECT * FROM disbursements 
            WHERE client_id = ? AND status != 'completed' AND remaining_balance > 0
            ORDER BY date DESC LIMIT 1
        ");
        $stmt->execute([$client_id]);
        $active_loan = $stmt->fetch();

        $total_loan_balance = $active_loan ? floatval($active_loan['remaining_balance']) : 0;
        $new_rem_bal = $total_loan_balance;

        // 3. Process Specific Types
        $pic_path = null;
        $amount_deducted = 0;

        if ($type === 'lapsed_saving') {
            if ($amount <= 0) throw new Exception("Invalid amount.");
            if ($current_balance < $amount) throw new Exception("Amount exceeds available savings balance.");
            $amount_deducted = $amount;

            // Transfer lapsed amount to Company Fund (Lapsed) — not a withdrawal, a transfer
            $fund_stmt = $pdo->prepare("SELECT balance FROM saving_balances WHERE client_id = ? FOR UPDATE");
            $fund_stmt->execute([LAPSED_FUND_CLIENT_ID]);
            $fund_row = $fund_stmt->fetch();
            if (!$fund_row) throw new Exception("Company Fund (Lapsed) account not set up. Run admin/setup_lapsed_fund.php first.");

            $fund_new_balance = floatval($fund_row['balance']) + $amount_deducted;
            $fund_tx_id = 'LPF-' . generateUUID();
            $fund_notes = 'Lapsed saving transferred from ' . $client_name . ' [Branch: ' . $c_branch . ', ZO: ' . $c_zo . ', Area: ' . $c_area . ', CO: ' . $c_co . ']';

            $pdo->prepare("INSERT INTO saving_collections (transaction_id, client_id, amount, type, date, officer, balance_after, notes) VALUES (?, ?, ?, 'adjust', ?, ?, ?, ?)")
                ->execute([$fund_tx_id, LAPSED_FUND_CLIENT_ID, $amount_deducted, $input_date, $officer, $fund_new_balance, $fund_notes]);

            $pdo->prepare("UPDATE saving_balances SET balance = ? WHERE client_id = ?")
                ->execute([$fund_new_balance, LAPSED_FUND_CLIENT_ID]);

        } elseif ($type === 'cash') {
            if ($amount <= 0) throw new Exception("Invalid amount.");
            if ($current_balance < $amount) throw new Exception("Insufficient savings.");

            if ($w_settings['require_image_for_cash']) {
                if (!isset($_FILES['picture']) || $_FILES['picture']['error'] !== UPLOAD_ERR_OK) {
                    throw new Exception("Proof image required.");
                }
                $ext = strtolower(pathinfo($_FILES['picture']['name'], PATHINFO_EXTENSION));
                $newname = preg_replace('/[^a-zA-Z0-9]/', '', $client_name) . '_' . time() . '.' . $ext;
                $dir = $base_path . 'uploads/withdrawals/';
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                if (!move_uploaded_file($_FILES['picture']['tmp_name'], $dir . $newname)) {
                    throw new Exception("Image upload failed.");
                }
                $pic_path = 'uploads/withdrawals/' . $newname;
            }
            $amount_deducted = $amount;
        } 
        elseif ($type === 'return' || $type === 'withdrawal') {
            $is_return = ($type === 'return');
            if ($is_return && $total_loan_balance <= 0) throw new Exception("No active loan to return.");
            if ($current_balance <= 0) throw new Exception("No savings available.");
            if (!$is_return && $current_balance < $amount) throw new Exception("Insufficient savings.");

            $amount_deducted = $is_return ? min($current_balance, $total_loan_balance) : $amount;
            if ($amount_deducted <= 0) throw new Exception("Amount must be positive.");

            if ($active_loan) {
                $new_rem_bal = floatval($active_loan['remaining_balance']) - $amount_deducted;
                $status = ($new_rem_bal <= 0) ? 'completed' : 'active';
                $payoff_sql = ($new_rem_bal <= 0) ? ", payoff_date = '$input_date'" : "";

                $upd_loan = $pdo->prepare("UPDATE disbursements SET remaining_balance = ?, status = ? $payoff_sql WHERE id = ?");
                $upd_loan->execute([$new_rem_bal, $status, $active_loan['id']]);
            }
        } else {
            throw new Exception("Invalid withdrawal type.");
        }

        // 4. Insert Saving Transaction
        $prefix = 'WTH-';
        if($type === 'return') $prefix = 'RTN-';
        if($type === 'cash') $prefix = 'CSH-';
        if($type === 'lapsed_saving') $prefix = 'LPS-';

        $tx_id = $prefix . generateUUID();
        $new_balance = $current_balance - $amount_deducted;
        
        $note = ($pic_path ? "Image: " . basename($pic_path) : "Admin " . ucfirst(str_replace('_', ' ', $type)));
        if ($type === 'lapsed_saving') {
            $note = "Lapsed Saving Cleared [Branch: $c_branch | ZO: $c_zo | Area: $c_area | CO: $c_co]";
        }
        
        // Map lapsed_saving/withdrawal to valid enum values
        $db_type = match($type) {
            'lapsed_saving' => 'adjust',
            'withdrawal'    => 'withdrawal',
            default         => $type
        };

        $stmt = $pdo->prepare("
            INSERT INTO saving_collections 
            (transaction_id, client_id, amount, type, date, officer, balance_after, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$tx_id, $client_id, -$amount_deducted, $db_type, $input_date, $officer, $new_balance, $note]);

        // 5. Update Saving Balance Table
        $stmt = $pdo->prepare("UPDATE saving_balances SET balance = ? WHERE client_id = ?");
        $stmt->execute([$new_balance, $client_id]);

        // 6. Record Specific History Log for Lapsed Savings
        if ($type === 'lapsed_saving') {
            $stmt = $pdo->prepare("
                INSERT INTO lapsed_savings_history 
                (transaction_id, client_id, client_name, amount, branch, co, zo, area, date, processed_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $tx_id, $client_id, $client_name, $amount_deducted, 
                $c_branch, $c_co, $c_zo, $c_area, $input_date, $officer
            ]);
        }

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'message' => ucfirst(str_replace('_', ' ', $type)) . " processed. Deducted: ₦" . number_format($amount_deducted),
            'new_savings_balance' => $new_balance,
            'new_loan_balance' => $new_rem_bal
        ]);

    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
    exit();
}

// --- OPTIMIZED DATA PREPARATION FOR VIEW ---
$client_data_js =[];
$total_savings = 0;
$total_savers = 0;

// 1. Fetch all clients and savings balances in ONE query
$stmt = $pdo->query("
    SELECT c.id, c.name, c.union, COALESCE(sb.balance, 0) as savings_balance 
    FROM clients c
    LEFT JOIN saving_balances sb ON c.id = sb.client_id
    WHERE c.status = 'active'
    ORDER BY c.name ASC
");
$raw_clients = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 2. Fetch all active loans in ONE query
$loans_stmt = $pdo->query("
    SELECT client_id, remaining_balance, total_payable, num_installments 
    FROM disbursements 
    WHERE status != 'completed' AND remaining_balance > 0
");
$active_loans =[];
while ($loan = $loans_stmt->fetch(PDO::FETCH_ASSOC)) {
    $active_loans[$loan['client_id']] = $loan;
}

// 3. Map Data using PHP arrays
foreach ($raw_clients as $c) {
    $cid = $c['id'];
    $u = $c['union'] ?? 'Unassigned';
    
    $s_bal = (float)$c['savings_balance'];
    if ($s_bal > 0) { 
        $total_savings += $s_bal; 
        $total_savers++; 
    }

    $loan = $active_loans[$cid] ?? null;
    $l_bal = $loan ? (float)$loan['remaining_balance'] : 0;
    $total_payable = $loan ? (float)$loan['total_payable'] : 0;
    $num_inst = $loan ? (int)$loan['num_installments'] : 1;
    $inst_amt = ($num_inst > 0) ? ($total_payable / $num_inst) : 0;

    $client_data_js[] =[
        'id' => $cid,
        'name' => $c['name'],
        'union' => $u,
        'savings_balance' => $s_bal,
        'loan_balance' => $l_bal,
        'total_installment_amount' => round($inst_amt),
    ];
}

// Stats for Dashboard using indexed LIKE query
$current_month = date('Y-m');
$stmt = $pdo->prepare("SELECT COUNT(*) as cnt, ABS(SUM(amount)) as total FROM saving_collections WHERE amount < 0 AND date LIKE ?");
$stmt->execute(["$current_month%"]);
$stats = $stmt->fetch(PDO::FETCH_ASSOC);
$monthly_withdrawals_count = $stats['cnt'] ?? 0;
$monthly_withdrawals_amt = $stats['total'] ?? 0;
?>
<!DOCTYPE html>
<html lang="en" class="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>Admin Savings Adjustments | CUPAD</title>
    
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <style>
        /* --- CSS VARIABLES & RESET --- */
        :root {
            --primary: #2563eb; --primary-dark: #1d4ed8; --primary-light: #eff6ff;
            --success: #10b981; --warning: #f59e0b; --danger: #ef4444; 
            --bg-body: #f8fafc; --bg-card: #ffffff; --bg-input: #f1f5f9;
            --text-main: #0f172a; --text-muted: #64748b; --text-light: #94a3b8;
            --border: #e2e8f0; --border-focus: #93c5fd;
            --shadow-sm: 0 1px 2px 0 rgba(0,0,0,0.05);
            --shadow-md: 0 4px 6px -1px rgba(0,0,0,0.1), 0 2px 4px -1px rgba(0,0,0,0.06);
            --shadow-lg: 0 10px 15px -3px rgba(0,0,0,0.1), 0 4px 6px -2px rgba(0,0,0,0.05);
            --shadow-glow: 0 0 15px rgba(37, 99, 235, 0.3);
            --radius-md: 0.75rem; --radius-lg: 1rem; --radius-xl: 1.5rem;
            --nav-height: 70px;
        }
        
        html.dark {
            --bg-body: #0f172a; --bg-card: #1e293b; --bg-input: #0f172a;
            --text-main: #f8fafc; --text-muted: #94a3b8; --text-light: #64748b;
            --border: #334155; --border-focus: #3b82f6;
            --shadow-sm: 0 1px 2px 0 rgba(0,0,0,0.3);
            --shadow-md: 0 4px 6px -1px rgba(0,0,0,0.4);
            --shadow-lg: 0 10px 15px -3px rgba(0,0,0,0.5);
            --primary-light: rgba(37,99,235,0.2);
        }
        
        * { box-sizing: border-box; margin: 0; padding: 0; outline: none; }
        body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: var(--bg-body); color: var(--text-main); padding-top: var(--nav-height); padding-bottom: 90px; -webkit-tap-highlight-color: transparent; transition: background-color 0.3s, color 0.3s; }
        a { text-decoration: none; color: inherit; }
        button { font-family: inherit; cursor: pointer; border: none; background: none; }
        input, select { font-family: inherit; }

        /* --- NAVBAR --- */
        .main-header { position: fixed; top: 0; left: 0; right: 0; height: var(--nav-height); background: var(--bg-card); opacity: 0.95; backdrop-filter: blur(10px); border-bottom: 1px solid var(--border); z-index: 50; transition: background 0.3s; }
        .navbar { max-width: 1400px; margin: 0 auto; padding: 0 1.5rem; display: flex; align-items: center; justify-content: space-between; height: 100%; }
        .logo { display: flex; align-items: center; gap: 0.75rem; font-weight: 800; font-size: 1.35rem; color: var(--primary); }
        .logo img { height: 38px; }
        .nav-right { display: flex; align-items: center; gap: 1rem; }
        .icon-btn { width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; color: var(--text-muted); transition: 0.2s; }
        .icon-btn:hover { background: var(--bg-input); color: var(--text-main); }
        
        .user-dropdown-wrap { position: relative; }
        .user-pill { display: flex; align-items: center; gap: 0.75rem; padding: 4px 8px 4px 4px; border: 1px solid var(--border); border-radius: 99px; background: var(--bg-card); cursor: pointer; transition: 0.2s; }
        .user-pill:hover { border-color: var(--primary); box-shadow: var(--shadow-sm); }
        .user-avatar { width: 34px; height: 34px; border-radius: 50%; object-fit: cover; }
        .user-info { display: flex; flex-direction: column; line-height: 1.1; padding-right: 0.5rem; }
        .user-name { font-weight: 600; font-size: 0.85rem; color: var(--text-main); }
        .user-role { font-size: 0.7rem; color: var(--text-muted); text-transform: uppercase; }
        
        /* Dropdown */
        .dropdown-menu { position: absolute; top: 125%; right: 0; background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius-lg); box-shadow: var(--shadow-md); min-width: 200px; display: none; z-index: 1000; flex-direction: column; overflow: hidden; transform-origin: top right; }
        .dropdown-menu.show { display: flex; animation: scaleIn 0.2s ease; }
        @keyframes scaleIn { from { opacity: 0; transform: scale(0.95); } to { opacity: 1; transform: scale(1); } }
        .dropdown-item { padding: 0.75rem 1rem; display: flex; align-items: center; gap: 0.75rem; font-size: 0.9rem; color: var(--text-main); transition: 0.2s; }
        .dropdown-item:hover { background: var(--bg-input); color: var(--primary); }
        .dropdown-divider { height: 1px; background: var(--border); margin: 0; }

        /* --- MAIN CONTENT & HEADER --- */
        .main-content { max-width: 1400px; margin: 0 auto; padding: 2rem 1.5rem; }
        .page-header { margin-bottom: 1.5rem; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; }
        .page-title { font-size: 1.75rem; font-weight: 800; color: var(--text-main); letter-spacing: -0.03em; }
        .page-subtitle { font-size: 0.95rem; color: var(--text-muted); margin-top: 0.25rem; font-weight: 500; }
        
        /* Link Button */
        .btn-history-link { background: var(--bg-card); border: 1px solid var(--border); color: var(--text-main); padding: 0.75rem 1.25rem; border-radius: var(--radius-md); font-weight: 700; font-size: 0.9rem; display: inline-flex; align-items: center; gap: 0.5rem; transition: 0.3s; box-shadow: var(--shadow-sm); text-decoration: none; }
        .btn-history-link:hover { border-color: var(--primary); color: var(--primary); transform: translateY(-2px); box-shadow: var(--shadow-md); }

        /* --- DASHBOARD STATS --- */
        .dashboard-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1.5rem; margin-bottom: 2.5rem; }
        .stat-card { border-radius: var(--radius-xl); padding: 1.75rem; position: relative; overflow: hidden; box-shadow: var(--shadow-md); color: white; transition: transform 0.3s, box-shadow 0.3s; border: none; }
        .stat-card:hover { transform: translateY(-5px); box-shadow: var(--shadow-lg); }
        .card-blue { background: linear-gradient(135deg, #2563eb 0%, #1e40af 100%); }
        .card-green { background: linear-gradient(135deg, #10b981 0%, #047857 100%); }
        .card-purple { background: linear-gradient(135deg, #8b5cf6 0%, #6d28d9 100%); }
        .stat-icon-bg { position: absolute; right: -15px; bottom: -20px; font-size: 7rem; color: white; opacity: 0.1; transform: rotate(-15deg); pointer-events: none; transition: transform 0.4s; }
        .stat-card:hover .stat-icon-bg { transform: rotate(0deg) scale(1.1); }
        .stat-label { font-size: 0.8rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; opacity: 0.9; margin-bottom: 0.5rem; }
        .stat-value { font-size: 2.25rem; font-weight: 800; letter-spacing: -0.03em; position: relative; z-index: 10; line-height: 1; }

        /* --- CENTERED FORM PANEL --- */
        .form-panel-container { max-width: 650px; margin: 0 auto; position: relative; }
        .form-card { background: var(--bg-card); border-radius: var(--radius-xl); border: 1px solid var(--border); padding: 2rem; box-shadow: var(--shadow-md); transition: 0.4s; }
        
        .form-header { font-size: 1.25rem; font-weight: 800; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.5rem; color: var(--text-main); }
        
        /* Search Box */
        .search-wrapper { position: relative; margin-bottom: 0; }
        .search-icon { position: absolute; left: 1.25rem; top: 50%; transform: translateY(-50%); color: var(--primary); font-size: 1.1rem; pointer-events: none; }
        .search-input { width: 100%; padding: 1.2rem 1rem 1.2rem 3.5rem; border-radius: var(--radius-lg); border: 2px solid var(--border); background: var(--bg-input); color: var(--text-main); font-size: 1.1rem; font-weight: 600; transition: all 0.3s ease; box-shadow: inset 0 2px 4px rgba(0,0,0,0.02); }
        .search-input:focus { border-color: var(--primary); background: var(--bg-card); box-shadow: var(--shadow-glow); outline: none; }
        .search-input::placeholder { color: var(--text-light); font-weight: 500; }
        
        /* Dropdown Results */
        .search-results { position: absolute; top: 100%; left: 0; right: 0; background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius-lg); box-shadow: var(--shadow-lg); max-height: 300px; overflow-y: auto; z-index: 100; margin-top: 0.5rem; display: none; flex-direction: column; }
        .search-results.active { display: flex; animation: slideDown 0.2s ease; }
        @keyframes slideDown { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
        
        .search-item { padding: 1rem; cursor: pointer; display: flex; align-items: center; gap: 1rem; border-bottom: 1px solid var(--border); transition: 0.2s; }
        .search-item:last-child { border-bottom: none; }
        .search-item:hover { background: var(--bg-input); }
        .si-avatar { width: 40px; height: 40px; border-radius: 10px; background: var(--primary-light); color: var(--primary); font-weight: 800; font-size: 1rem; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .si-info { flex: 1; overflow: hidden; }
        .si-name { font-weight: 700; font-size: 1rem; color: var(--text-main); margin-bottom: 0.2rem; }
        .si-union { font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase; font-weight: 700; }

        /* --- PREMIUM WALLET CARD (Selected Client) --- */
        .wallet-card { background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%); border-radius: var(--radius-lg); padding: 1.5rem; color: white; margin-bottom: 1.5rem; position: relative; overflow: hidden; box-shadow: 0 10px 25px -5px rgba(37, 99, 235, 0.4); animation: fadeIn 0.4s ease; }
        .wallet-card::after { content: ''; position: absolute; top: -50%; right: -20%; width: 200px; height: 200px; background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%); border-radius: 50%; pointer-events: none; }
        
        .wc-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1.5rem; position: relative; z-index: 2; }
        .wc-client { display: flex; gap: 1rem; align-items: center; }
        .wc-avatar { width: 50px; height: 50px; border-radius: 12px; background: rgba(255,255,255,0.2); backdrop-filter: blur(4px); color: white; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 1.25rem; border: 1px solid rgba(255,255,255,0.3); }
        .wc-name { font-size: 1.15rem; font-weight: 800; margin-bottom: 0.2rem; letter-spacing: -0.02em; }
        .wc-union { font-size: 0.75rem; font-weight: 600; opacity: 0.9; text-transform: uppercase; letter-spacing: 0.05em; background: rgba(0,0,0,0.2); padding: 0.2rem 0.5rem; border-radius: 4px; display: inline-block; }
        
        .btn-change { background: rgba(255,255,255,0.2); border: 1px solid rgba(255,255,255,0.3); color: white; padding: 0.4rem 0.8rem; border-radius: 99px; font-size: 0.75rem; font-weight: 700; transition: 0.2s; backdrop-filter: blur(4px); }
        .btn-change:hover { background: rgba(255,255,255,0.3); transform: translateY(-1px); }

        .wc-stats { display: flex; gap: 1rem; position: relative; z-index: 2; }
        .wc-stat-box { flex: 1; }
        .wc-stat-label { font-size: 0.7rem; font-weight: 600; text-transform: uppercase; opacity: 0.8; margin-bottom: 0.3rem; letter-spacing: 0.05em; }
        .wc-stat-val { font-size: 1.4rem; font-weight: 800; letter-spacing: -0.02em; }
        .wc-divider { width: 1px; background: rgba(255,255,255,0.2); margin: 0 0.5rem; }

        /* Form Inputs */
        .form-section { animation: fadeInUp 0.4s ease forwards; }
        @keyframes fadeInUp { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

        .form-group { margin-bottom: 1.5rem; position: relative; }
        .form-label { display: flex; justify-content: space-between; font-size: 0.75rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; margin-bottom: 0.5rem; letter-spacing: 0.05em; }
        
        .form-input { width: 100%; padding: 1rem 1.2rem; border-radius: var(--radius-md); border: 1px solid var(--border); background: var(--bg-input); color: var(--text-main); font-size: 1rem; font-weight: 600; transition: 0.3s; outline: none; appearance: none; }
        .form-input:focus { border-color: var(--primary); background: var(--bg-card); box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.1); }
        .form-input:disabled, .form-input[readonly] { background: var(--bg-body); color: var(--text-muted); cursor: not-allowed; opacity: 0.8; border-style: dashed; }
        .form-icon { position: absolute; left: 1.2rem; top: 2.5rem; color: var(--text-light); font-size: 1rem; pointer-events: none; }
        .has-icon { padding-left: 2.8rem; }
        .select-arrow { position: absolute; right: 1.2rem; top: 2.5rem; color: var(--text-muted); font-size: 0.9rem; pointer-events: none; }
        
        /* Pills & Buttons */
        .btn-pill { background: var(--primary-light); color: var(--primary); border: none; border-radius: 99px; padding: 0.2rem 0.6rem; font-size: 0.65rem; font-weight: 800; cursor: pointer; transition: 0.2s; text-transform: uppercase; letter-spacing: 0.05em; }
        .btn-pill:hover { background: rgba(37,99,235,0.2); transform: translateY(-1px); }

        .btn-primary { width: 100%; padding: 1.1rem; border-radius: var(--radius-md); background: var(--primary); color: white; font-size: 1.05rem; font-weight: 800; display: flex; align-items: center; justify-content: center; gap: 0.5rem; transition: 0.3s; box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3); border: none; cursor: pointer; position: relative; overflow: hidden; }
        .btn-primary:hover { background: var(--primary-dark); transform: translateY(-2px); box-shadow: 0 6px 15px rgba(37, 99, 235, 0.4); }
        .btn-primary:active { transform: translateY(0); }
        .btn-primary:disabled { background: var(--text-muted); box-shadow: none; cursor: not-allowed; transform: none; }

        .btn-secondary { background: var(--bg-input); color: var(--text-main); border: 1px solid var(--border); font-weight: 700; padding: 0.8rem; border-radius: var(--radius-md); transition: 0.2s; cursor: pointer; width: 100%; }
        .btn-secondary:hover { background: var(--border); }

        /* Drop Zone */
        .drop-zone { border: 2px dashed var(--border); border-radius: var(--radius-md); padding: 2rem; text-align: center; cursor: pointer; transition: 0.3s; background: var(--bg-input); }
        .drop-zone:hover { border-color: var(--primary); background: var(--primary-light); }
        .drop-zone i { font-size: 2.5rem; color: var(--primary); margin-bottom: 0.75rem; opacity: 0.7; }
        .drop-zone p { margin: 0; font-size: 0.9rem; color: var(--text-main); font-weight: 700; }
        .img-preview { max-height: 150px; border-radius: var(--radius-md); border: 2px solid var(--border); display: none; margin: 0 auto; box-shadow: var(--shadow-sm); }

        /* --- MODALS --- */
        .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.6); backdrop-filter: blur(5px); z-index: 200; display: none; align-items: center; justify-content: center; opacity: 0; transition: opacity 0.3s ease; }
        .modal-overlay.active { display: flex; opacity: 1; }
        .modal-content { background: var(--bg-card); width: 92%; max-width: 420px; border-radius: var(--radius-xl); box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5); transform: scale(0.95) translateY(20px); transition: 0.4s cubic-bezier(0.34, 1.56, 0.64, 1); display: flex; flex-direction: column; overflow: hidden; border: 1px solid var(--border); }
        .modal-overlay.active .modal-content { transform: scale(1) translateY(0); }
        
        .modal-header { padding: 1.5rem; text-align: center; background: var(--bg-card); border-bottom: 1px solid var(--border); }
        .modal-icon { width: 64px; height: 64px; border-radius: 50%; background: var(--primary-light); color: var(--primary); display: flex; align-items: center; justify-content: center; font-size: 2rem; margin: 0 auto 1rem; }
        .modal-icon.danger { background: rgba(239,68,68,0.1); color: var(--danger); }
        .modal-icon.success { background: rgba(16,185,129,0.1); color: var(--success); }
        .modal-title { font-size: 1.25rem; font-weight: 800; color: var(--text-main); margin-bottom: 0.25rem; }
        
        .modal-body { padding: 1.5rem; font-size: 0.95rem; color: var(--text-muted); line-height: 1.5; text-align: center; }
        .modal-footer { padding: 1.5rem; background: var(--bg-input); display: flex; gap: 1rem; }
        .modal-footer button { flex: 1; }

        /* Toast */
        .toast { position: fixed; top: 20px; left: 50%; background: var(--bg-card); padding: 1rem 1.5rem; border-radius: var(--radius-lg); border-left: 4px solid var(--primary); box-shadow: var(--shadow-lg); z-index: 9999; display: flex; align-items: center; gap: 1rem; opacity: 0; transform: translateY(-20px) translateX(-50%); transition: 0.4s cubic-bezier(0.21, 1.02, 0.73, 1); }
        .toast.show { opacity: 1; transform: translateY(0) translateX(-50%); }

        /* Animation utilities */
        .fade-out { opacity: 0; visibility: hidden; transition: 0.3s; position: absolute; }
        .fade-in { opacity: 1; visibility: visible; transition: 0.3s; position: relative; }
    </style>
</head>
<body>

    <div id="toastContainer"></div>

    <div id="confirmModal" class="modal-overlay">
        <div class="modal-content">
            <div class="modal-header">
                <div class="modal-icon" id="modalIcon"><i class="fas fa-question"></i></div>
                <div class="modal-title" id="modalTitle">Confirm Action</div>
            </div>
            <div class="modal-body" id="modalDesc">
                Are you sure you want to proceed?
            </div>
            <div class="modal-footer">
                <button class="btn-secondary" onclick="closeModal('confirmModal')">Cancel</button>
                <button class="btn-primary" id="confirmModalBtn" style="padding:0.8rem; box-shadow:none;">Confirm</button>
            </div>
        </div>
    </div>

    <header class="main-header">
        <div class="navbar">
            <a href="dashboard.php" class="logo">
                <img src="<?php echo htmlspecialchars($base_path . 'uploads/CUPAD LOGO.png'); ?>" alt="Logo">
                <span>CUPAD Admin</span>
            </a>
            <div class="nav-right">
                <a href="lapsed_savings_history.php" class="icon-btn" title="View Lapsed History"><i class="fas fa-history"></i></a>
                <a href="dashboard.php" class="icon-btn" title="Dashboard"><i class="fas fa-home"></i></a>
                <button class="icon-btn" id="themeToggle"><i class="fas fa-moon"></i></button>
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
                        <i class="fas fa-chevron-down" style="font-size:0.7rem; color:var(--text-muted);"></i>
                    </div>
                    <div class="dropdown-menu" id="userDropdown">
                        <div class="dropdown-header">SIGNED IN AS</div>
                        <div class="dropdown-item" style="font-weight: 700; cursor: default; background: transparent; padding-top: 0;"><?php echo htmlspecialchars($admin_username); ?></div>
                        <div class="dropdown-divider"></div>
                        <a href="profile.php" class="dropdown-item"><i class="fas fa-user-circle" style="color:var(--primary)"></i> My Profile</a>
                        <div class="dropdown-divider"></div>
                        <a href="../logout.php" class="dropdown-item text-danger"><i class="fas fa-sign-out-alt"></i> Logout</a>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <main class="main-content">
        <div class="page-header">
            <div>
                <h1 class="page-title">Savings Adjustments</h1>
                <p class="page-subtitle">Instantly process client payouts, loan returns, and clear lapsed savings.</p>
            </div>
            
            <!-- NEW: The History Link Button -->
            <a href="lapsed_savings_history.php" class="btn-history-link">
                <i class="fas fa-file-invoice-dollar" style="color: var(--primary); font-size: 1.1rem;"></i> 
                Lapsed History Ledger
            </a>
        </div>

        <!-- STATS -->
        <div class="dashboard-grid">
            <div class="stat-card card-blue">
                <i class="fas fa-users stat-icon-bg"></i>
                <div class="stat-label">System Active Savers</div>
                <div class="stat-value" id="stat_savers"><?php echo number_format($total_savers); ?></div>
            </div>
            <div class="stat-card card-green">
                <i class="fas fa-piggy-bank stat-icon-bg"></i>
                <div class="stat-label">Total System Savings</div>
                <div class="stat-value">₦<span id="stat_savings_amount"><?php echo number_format($total_savings); ?></span></div>
            </div>
            <div class="stat-card card-purple">
                <i class="fas fa-bolt stat-icon-bg"></i>
                <div class="stat-label">Monthly Withdrawals</div>
                <div class="stat-value" style="display:flex; align-items:baseline; gap:0.5rem;">
                    <span id="stat_month_count"><?php echo number_format($monthly_withdrawals_count); ?></span>
                    <span style="font-size:0.9rem; font-weight:600; opacity:0.8; letter-spacing:0;">(₦<span id="stat_month_amt"><?php echo number_format($monthly_withdrawals_amt); ?></span>)</span>
                </div>
            </div>
        </div>

        <!-- CENTERED FORM PANEL -->
        <div class="form-panel-container">
            <div class="form-card">
                
                <!-- Search Section -->
                <div id="searchSection" class="fade-in">
                    <div class="form-header" style="justify-content: center; margin-bottom: 2rem; font-size: 1.4rem;">
                        <i class="fas fa-search" style="color:var(--primary); background:var(--primary-light); padding:0.75rem; border-radius:12px;"></i> Find a Client
                    </div>
                    
                    <div class="search-wrapper">
                        <i class="fas fa-search search-icon"></i>
                        <input type="text" id="clientSearch" class="search-input" placeholder="Enter client name or union..." autocomplete="off">
                        <div id="searchResults" class="search-results"></div>
                    </div>
                    <p style="text-align:center; color:var(--text-muted); font-size:0.85rem; margin-top:1rem; font-weight:500;">Type at least 2 characters to search the entire database instantly.</p>
                </div>

                <!-- Action Section (Hidden initially) -->
                <div id="actionSection" class="fade-out hidden">
                    
                    <!-- Premium Wallet Card -->
                    <div class="wallet-card">
                        <div class="wc-header">
                            <div class="wc-client">
                                <div class="wc-avatar" id="scAvatar">JD</div>
                                <div>
                                    <div class="wc-name" id="scName">John Doe</div>
                                    <div class="wc-union" id="scUnion">Union Name</div>
                                </div>
                            </div>
                            <button class="btn-change" onclick="resetClientSelection()"><i class="fas fa-exchange-alt"></i> Change</button>
                        </div>
                        
                        <div class="wc-stats">
                            <div class="wc-stat-box">
                                <div class="wc-stat-label">Savings Balance</div>
                                <div class="wc-stat-val" id="scSavings">₦0</div>
                            </div>
                            <div class="wc-divider"></div>
                            <div class="wc-stat-box">
                                <div class="wc-stat-label">Active Loan</div>
                                <div class="wc-stat-val" id="scLoan">₦0</div>
                            </div>
                        </div>
                    </div>

                    <!-- Form -->
                    <form id="withdrawalForm" class="form-section">
                        <input type="hidden" name="client_id" id="client_id">

                        <div class="form-group">
                            <label class="form-label">Transaction Type</label>
                            <select id="withdrawal_type" name="withdrawal_type" class="form-input" style="color:var(--primary); font-weight:800; cursor:pointer;">
                                <option value="lapsed_saving">Lapsed Saving (Clear Balance)</option>
                                <option value="cash">Cash Withdrawal (Manual)</option>
                                <option value="withdrawal">Withdrawal (Loan Deduct)</option>
                                <option value="return">Return (Payoff Loan)</option>
                            </select>
                            <i class="fas fa-chevron-down select-arrow"></i>
                            <div id="type-help" style="font-size: 0.8rem; color: var(--text-muted); margin-top: 8px; font-weight: 600; line-height: 1.4;"></div>
                        </div>

                        <div class="form-group" id="amount-container">
                            <label class="form-label">
                                <span>Amount</span>
                                <button type="button" id="btnMax" class="btn-pill hidden">Set Max</button>
                            </label>
                            <i class="fas fa-naira-sign form-icon"></i>
                            <input type="number" id="amount" name="amount" step="0.01" class="form-input has-icon" placeholder="0.00">
                            <div id="amount-helper" style="font-size: 0.8rem; color: var(--primary); font-weight: 800; margin-top: 6px; height: 16px;"></div>
                        </div>

                        <div class="form-group hidden" id="image-upload-container">
                            <label class="form-label">Proof Image</label>
                            <input type="file" name="picture" id="picture" accept="image/*" style="display:none;">
                            <div class="drop-zone" id="drop-zone">
                                <div id="upload-placeholder">
                                    <i class="fas fa-cloud-upload-alt"></i>
                                    <p>Click to upload receipt/proof</p>
                                </div>
                                <img id="img-preview" class="img-preview">
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">
                                <span>Date & Time</span>
                                <button type="button" id="btn-set-now" class="btn-pill">SET NOW</button>
                            </label>
                            <input type="datetime-local" id="date" name="date" value="<?php echo date('Y-m-d\TH:i'); ?>" class="form-input">
                        </div>

                        <button type="button" id="preSubmitBtn" class="btn-primary" style="margin-top: 2rem;">
                            <i class="fas fa-bolt" style="color:#fbbf24;"></i> <span id="btnText">Execute Adjustment</span>
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </main>

    <script>
        // Data Configuration
        const clientData = <?php echo json_encode($client_data_js); ?>;
        let currentSelectedClient = null;
        
        let sysTotalSavings = <?php echo $total_savings; ?>;
        let sysMonthlyCount = <?php echo $monthly_withdrawals_count; ?>;
        let sysMonthlyAmt = <?php echo $monthly_withdrawals_amt; ?>;

        const els = {
            searchSection: document.getElementById('searchSection'),
            clientSearch: document.getElementById('clientSearch'),
            searchResults: document.getElementById('searchResults'),
            actionSection: document.getElementById('actionSection'),
            clientIdInput: document.getElementById('client_id'),
            
            scAvatar: document.getElementById('scAvatar'),
            scName: document.getElementById('scName'),
            scUnion: document.getElementById('scUnion'),
            scSavings: document.getElementById('scSavings'),
            scLoan: document.getElementById('scLoan'),

            typeSelect: document.getElementById('withdrawal_type'),
            amountInput: document.getElementById('amount'),
            amountHelper: document.getElementById('amount-helper'),
            amountContainer: document.getElementById('amount-container'),
            btnMax: document.getElementById('btnMax'),
            imgContainer: document.getElementById('image-upload-container'),
            fileInput: document.getElementById('picture'),
            imgPreview: document.getElementById('img-preview'),
            dropZone: document.getElementById('drop-zone'),
            uploadPlaceholder: document.getElementById('upload-placeholder'),
            typeHelp: document.getElementById('type-help'),
            form: document.getElementById('withdrawalForm'),
            preSubmitBtn: document.getElementById('preSubmitBtn'),
            btnText: document.getElementById('btnText'),
            
            modal: document.getElementById('confirmModal'),
            modalIcon: document.getElementById('modalIcon'),
            modalTitle: document.getElementById('modalTitle'),
            modalDesc: document.getElementById('modalDesc'),
            confirmModalBtn: document.getElementById('confirmModalBtn')
        };

        const formatCurrency = (amt) => '₦' + parseFloat(amt).toLocaleString('en-US', {minimumFractionDigits: 0, maximumFractionDigits: 0});

        function showToast(msg, type = 'info') {
            const div = document.createElement('div'); 
            div.className = 'toast show';
            let icon = type === 'success' ? 'check-circle' : (type === 'error' ? 'times-circle' : 'exclamation-circle');
            let color = type === 'success' ? 'var(--success)' : (type === 'error' ? 'var(--danger)' : 'var(--warning)');
            div.style.borderLeftColor = color;
            div.innerHTML = `<i class="fas fa-${icon}" style="font-size:1.5rem; color:${color};"></i> <span style="font-weight:700; font-size:0.95rem; color:var(--text-main);">${msg}</span>`;
            document.getElementById('toastContainer').appendChild(div);
            setTimeout(() => { div.classList.remove('show'); setTimeout(() => div.remove(), 400); }, 3500);
        }

        function closeModal(id) { document.getElementById(id).classList.remove('active'); setTimeout(() => document.getElementById(id).style.display = 'none', 300); }
        function openModal(id) { document.getElementById(id).style.display = 'flex'; setTimeout(() => document.getElementById(id).classList.add('active'), 10); }

        // Theme Toggle
        const themeBtn = document.getElementById('themeToggle');
        if(localStorage.getItem('theme') === 'dark') document.documentElement.classList.add('dark');
        if (themeBtn) themeBtn.onclick = () => { const isDark = document.documentElement.classList.toggle('dark'); localStorage.setItem('theme', isDark ? 'dark' : 'light'); };
        
        const userTrigger = document.getElementById('userDropdownTrigger');
        if(userTrigger) {
            userTrigger.onclick = (e) => { e.stopPropagation(); document.getElementById('userDropdown').classList.toggle('show'); };
            document.onclick = (e) => { if (!document.getElementById('userDropdown').contains(e.target)) document.getElementById('userDropdown').classList.remove('show'); };
        }

        // --- SEARCH LOGIC ---
        els.clientSearch.addEventListener('input', function() {
            const val = this.value.toLowerCase().trim();
            els.searchResults.innerHTML = '';
            
            if (val.length < 2) {
                els.searchResults.classList.remove('active');
                return;
            }

            const filtered = clientData.filter(c => c.name.toLowerCase().includes(val) || c.union.toLowerCase().includes(val)).slice(0, 8);
            
            if (filtered.length === 0) {
                els.searchResults.innerHTML = '<div style="padding: 1.5rem; text-align: center; color: var(--text-muted); font-size: 0.9rem; font-weight:600;">No clients found</div>';
            } else {
                filtered.forEach(c => {
                    const div = document.createElement('div');
                    div.className = 'search-item';
                    div.innerHTML = `
                        <div class="si-avatar">${c.name.substring(0,2).toUpperCase()}</div>
                        <div class="si-info">
                            <div class="si-name">${c.name}</div>
                            <div class="si-union"><i class="fas fa-users" style="opacity:0.7"></i> ${c.union}</div>
                        </div>
                        <div style="text-align:right;">
                            <div style="font-size:0.85rem; color:var(--success); font-weight:800;">${formatCurrency(c.savings_balance)}</div>
                            <div style="font-size:0.65rem; color:var(--text-muted); text-transform:uppercase; font-weight:700;">Savings</div>
                        </div>
                    `;
                    div.onclick = () => selectClient(c);
                    els.searchResults.appendChild(div);
                });
            }
            els.searchResults.classList.add('active');
        });

        document.addEventListener('click', (e) => {
            if (!els.clientSearch.contains(e.target) && !els.searchResults.contains(e.target)) {
                els.searchResults.classList.remove('active');
            }
        });

        function selectClient(client) {
            currentSelectedClient = client;
            els.clientIdInput.value = client.id;
            
            // Populate Premium Card
            els.scAvatar.textContent = client.name.substring(0,2).toUpperCase();
            els.scName.textContent = client.name;
            els.scUnion.textContent = client.union;
            els.scSavings.textContent = formatCurrency(client.savings_balance);
            els.scLoan.textContent = formatCurrency(client.loan_balance);

            // Hide Search, Show Form Smoothly
            els.searchSection.classList.remove('fade-in');
            els.searchSection.classList.add('fade-out');
            els.searchResults.classList.remove('active');
            
            setTimeout(() => {
                els.searchSection.classList.add('hidden');
                els.actionSection.classList.remove('hidden');
                els.actionSection.classList.remove('fade-out');
                els.actionSection.classList.add('fade-in');
                updateFormUI();
            }, 250);
        }

        function resetClientSelection() {
            currentSelectedClient = null;
            els.clientIdInput.value = '';
            els.clientSearch.value = '';
            
            els.actionSection.classList.remove('fade-in');
            els.actionSection.classList.add('fade-out');
            
            setTimeout(() => {
                els.actionSection.classList.add('hidden');
                els.searchSection.classList.remove('hidden');
                els.searchSection.classList.remove('fade-out');
                els.searchSection.classList.add('fade-in');
                els.clientSearch.focus();
            }, 250);
        }

        // --- FORM LOGIC ---
        els.typeSelect.addEventListener('change', updateFormUI);

        els.amountInput.addEventListener('input', (e) => {
            const val = parseFloat(e.target.value);
            els.amountHelper.textContent = val > 0 ? formatCurrency(val) : '';
        });

        els.btnMax.addEventListener('click', () => {
            if(currentSelectedClient && currentSelectedClient.savings_balance > 0) {
                els.amountInput.value = currentSelectedClient.savings_balance;
                els.amountHelper.textContent = formatCurrency(currentSelectedClient.savings_balance);
            }
        });

        function updateFormUI() {
            const type = els.typeSelect.value;
            els.btnMax.classList.add('hidden');
            
            if (type === 'lapsed_saving') {
                els.amountContainer.classList.remove('hidden'); 
                els.amountInput.required = true; 
                els.amountInput.readOnly = false; 
                els.imgContainer.classList.add('hidden'); 
                els.fileInput.required = false;
                els.typeHelp.innerHTML = "<i class='fas fa-exclamation-triangle' style='color:var(--danger);'></i> <b>Admin Override:</b> Instantly transfers lapsed/expired savings bypassing all CO rules.";
                if(currentSelectedClient) els.btnMax.classList.remove('hidden');
            } 
            else if(type === 'return') {
                els.amountContainer.classList.add('hidden'); els.amountInput.required = false;
                els.imgContainer.classList.add('hidden'); els.fileInput.required = false;
                els.typeHelp.innerHTML = "<i class='fas fa-info-circle'></i> Uses available savings to pay off the outstanding loan balance entirely.";
            } 
            else if (type === 'cash') {
                els.amountContainer.classList.remove('hidden'); els.amountInput.required = true; els.amountInput.readOnly = false;
                els.imgContainer.classList.remove('hidden'); els.fileInput.required = true;
                els.typeHelp.innerHTML = "<i class='fas fa-info-circle'></i> Manual cash payout to client. Requires photo proof.";
            } 
            else {
                els.amountContainer.classList.remove('hidden'); els.amountInput.required = true; els.amountInput.readOnly = true;
                els.imgContainer.classList.add('hidden'); els.fileInput.required = false;
                els.typeHelp.innerHTML = "<i class='fas fa-info-circle'></i> Automatically deducts a single loan installment from savings.";
                if(currentSelectedClient && currentSelectedClient.total_installment_amount > 0) {
                    els.amountInput.value = currentSelectedClient.total_installment_amount;
                    els.amountHelper.textContent = formatCurrency(currentSelectedClient.total_installment_amount);
                } else {
                    els.amountInput.value = ''; els.amountHelper.textContent = '';
                }
            }
        }

        els.dropZone.addEventListener('click', () => els.fileInput.click());
        els.fileInput.addEventListener('change', (e) => {
            const f = e.target.files[0];
            if(f) {
                const reader = new FileReader();
                reader.onload = e => { els.imgPreview.src = e.target.result; els.imgPreview.style.display = 'block'; els.uploadPlaceholder.style.display = 'none'; };
                reader.readAsDataURL(f);
            }
        });

        document.getElementById('btn-set-now').addEventListener('click', () => {
            const now = new Date(); now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
            document.getElementById('date').value = now.toISOString().slice(0,16);
        });

        // Submit Logic
        els.preSubmitBtn.addEventListener('click', () => {
            if(!currentSelectedClient) { showToast('Please select a client', 'error'); return; }

            const type = els.typeSelect.value;
            const amount = parseFloat(els.amountInput.value) || 0;

            if (type === 'lapsed_saving') {
                if (amount <= 0 || isNaN(amount)) { showToast('Enter a valid amount', 'error'); return; }
                if (amount > currentSelectedClient.savings_balance) { showToast('Amount exceeds available balance', 'error'); return; }
                
                els.modalIcon.className = 'modal-icon danger';
                els.modalIcon.innerHTML = '<i class="fas fa-eraser"></i>';
                els.modalTitle.textContent = 'Clear Lapsed Saving?';
                els.modalDesc.innerHTML = `You are about to permanently remove <br><b style="font-size:1.5rem; color:var(--text-main); display:block; margin:0.5rem 0;">₦${amount.toLocaleString()}</b> from <b>${currentSelectedClient.name}</b>'s balance. <br>The history and hierarchy will be logged.`;
                els.confirmModalBtn.style.background = 'var(--danger)';
                openModal('confirmModal');
                return;
            }

            if (type === 'return') {
                if (currentSelectedClient.loan_balance <= 0) { showToast('No outstanding loans to pay', 'warning'); return; }
                if (currentSelectedClient.savings_balance <= 0) { showToast('No savings available', 'error'); return; }
                
                els.modalIcon.className = 'modal-icon success';
                els.modalIcon.innerHTML = '<i class="fas fa-undo"></i>';
                els.modalTitle.textContent = 'Confirm Return?';
                els.modalDesc.innerHTML = `This will instantly pay off the loan using available savings for <br><b>${currentSelectedClient.name}</b>.`;
                els.confirmModalBtn.style.background = 'var(--success)';
                openModal('confirmModal');
                return;
            } 
            
            if(type === 'cash') {
                if(currentSelectedClient.savings_balance < amount) { showToast('Insufficient funds', 'error'); return; }
                if(!els.fileInput.files[0]) { showToast('Photo proof required', 'error'); return; }
            }
            
            processAjaxSubmit();
        });

        els.confirmModalBtn.addEventListener('click', () => { closeModal('confirmModal'); processAjaxSubmit(); });

        async function processAjaxSubmit() {
            const originalIcon = '<i class="fas fa-bolt" style="color:#fbbf24;"></i>';
            els.btnText.textContent = 'Processing...';
            els.preSubmitBtn.querySelector('i').className = 'fas fa-spinner fa-spin';
            els.preSubmitBtn.querySelector('i').style.color = 'white';
            els.preSubmitBtn.disabled = true;

            const formData = new FormData(els.form);
            formData.append('ajax_action', 'process_withdrawal');

            try {
                const response = await fetch('', { method: 'POST', body: formData });
                const data = await response.json();

                if (data.success) {
                    showToast(data.message, 'success');
                    
                    // Dynamic client & global stats update
                    const cid = els.clientIdInput.value;
                    const cIndex = clientData.findIndex(c => c.id == cid);
                    let deductedAmt = 0;
                    
                    if(cIndex > -1) {
                        deductedAmt = clientData[cIndex].savings_balance - data.new_savings_balance;
                        clientData[cIndex].savings_balance = data.new_savings_balance;
                        clientData[cIndex].loan_balance = data.new_loan_balance;
                        
                        // Update visual card instantly with a brief highlight flash
                        els.scSavings.textContent = formatCurrency(data.new_savings_balance);
                        els.scLoan.textContent = formatCurrency(data.new_loan_balance);
                        currentSelectedClient = clientData[cIndex];
                    }
                    
                    sysTotalSavings -= deductedAmt;
                    sysMonthlyCount += 1;
                    sysMonthlyAmt += deductedAmt;
                    
                    document.getElementById('stat_savings_amount').innerText = sysTotalSavings.toLocaleString('en-US', {minimumFractionDigits: 0, maximumFractionDigits: 0});
                    document.getElementById('stat_month_count').innerText = sysMonthlyCount.toLocaleString();
                    document.getElementById('stat_month_amt').innerText = sysMonthlyAmt.toLocaleString('en-US', {minimumFractionDigits: 0, maximumFractionDigits: 0});
                    
                    els.amountInput.value = ''; els.amountHelper.textContent = '';
                    els.fileInput.value = ''; els.imgPreview.style.display = 'none'; els.uploadPlaceholder.style.display = 'block';
                    updateFormUI();
                    
                    setTimeout(() => resetClientSelection(), 1500); // Reset form after 1.5 seconds smoothly
                } else {
                    showToast(data.message || 'Transaction failed', 'error');
                }
            } catch (err) {
                showToast('Connection error during submission', 'error');
                console.error(err);
            } finally {
                els.btnText.textContent = 'Execute Adjustment';
                els.preSubmitBtn.querySelector('i').className = 'fas fa-bolt';
                els.preSubmitBtn.querySelector('i').style.color = '#fbbf24';
                els.preSubmitBtn.disabled = false;
            }
        }
    </script>
</body>
</html>