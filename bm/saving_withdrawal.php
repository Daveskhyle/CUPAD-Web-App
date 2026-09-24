<?php
date_default_timezone_set('Africa/Lagos');
// bm/saving_withdrawal.php - Branch Manager Version (Optimized & Fixed Double Record)
session_start();

$base_path = '../';

// --- DATABASE CONNECTION ---
require_once $base_path . 'includes/config.php';
$pdo = getDbConnection();

// Access Control - BM Only
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'bm') {
    header('Location: ../index.php');
    exit();
}

$username = $_SESSION['username'] ?? '';
$user_id = $_SESSION['user_id'] ?? '';

// Fetch the BM's branch_id
$stmt_b = $pdo->prepare("SELECT branch_id FROM users WHERE id = :uid");
$stmt_b->execute(['uid' => $user_id]);
$branch_id = $stmt_b->fetchColumn();

// Helper: UUID
function generateUUID() {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

// --- LOAD SETTINGS (SQL) ---
try {
    // Withdrawal Settings
    $stmt = $pdo->query("SELECT * FROM withdrawal_settings LIMIT 1");
    $w_settings = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$w_settings) {
        $w_settings =[
            'max_cash_withdrawal' => 50000, 'require_image_for_cash' => 1,
            'allow_weekend_withdrawals' => 0, 'max_withdrawals_per_day' => 1,
            'blocked_withdrawal_types' => '[]', 'withdrawal_date_readonly' => 0,
            'buffer_cash' => 10, 'buffer_withdrawal' => 10, 'buffer_return' => 10, 'buffer_adjust' => 10
        ];
    }
    
    // Disbursement Settings (for Loan limits)
    $stmt = $pdo->query("SELECT * FROM disbursement_settings LIMIT 1");
    $d_settings = $stmt->fetch(PDO::FETCH_ASSOC);
    $d_settings = $d_settings ?:[
        'global_max_first_loan_amount' => 20000,
        'global_max_increment_amount' => 20000,
        'max_disbursement' => 200000
    ];

} catch (Exception $e) {
    die("Error loading settings: " . $e->getMessage());
}

// --- POST HANDLING (TRANSACTIONS) ---
$message = '';
$message_type = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['client_id'])) {
    $client_id = trim($_POST['client_id']);
    $amount = floatval($_POST['amount'] ?? 0);
    $type = $_POST['withdrawal_type'] ?? 'cash';
    $input_date = $_POST['date'] ?? date('Y-m-d H:i:s');
    $officer = $username; // BM Processing
    
    // Basic Validations
    $allow_weekends = $w_settings['allow_weekend_withdrawals'];
    $is_weekend = (date('N', strtotime($input_date)) >= 6);
    $blocked_types = json_decode($w_settings['blocked_withdrawal_types'] ?? '[]', true) ?:[];

    if (in_array($type, $blocked_types)) {
        $message = 'This withdrawal type is blocked.'; $message_type = 'error';
    } elseif (!$allow_weekends && $is_weekend) {
        $message = 'Weekend withdrawals are disabled.'; $message_type = 'warning';
    } else {
        try {
            $pdo->beginTransaction();

            // 1. Get Client & Current Balance (Locking Read + BM Security Check)
            $stmt = $pdo->prepare("
                SELECT c.name, c.union, c.branch_id, sb.balance 
                FROM clients c 
                LEFT JOIN saving_balances sb ON c.id = sb.client_id 
                WHERE c.id = ? FOR UPDATE
            ");
            $stmt->execute([$client_id]);
            $client = $stmt->fetch();
            
            if (!$client) throw new Exception("Client not found.");
            if ($client['branch_id'] != $branch_id) throw new Exception("Unauthorized client branch mismatch.");
            
            $current_balance = floatval($client['balance'] ?? 0);
            $client_name = $client['name'];

            // 2. Get Active Loan Info (Most Recent by Date)
            $stmt = $pdo->prepare("
                SELECT * FROM disbursements 
                WHERE client_id = ? AND status != 'completed' AND remaining_balance > 0
                ORDER BY date DESC LIMIT 1
            ");
            $stmt->execute([$client_id]);
            $active_loan = $stmt->fetch();

            $total_loan_balance = 0;
            if ($active_loan) {
                $total_loan_balance = floatval($active_loan['remaining_balance']);
            }

            // 3. Logic: 10th Installment & Buffer Checks
            if ($active_loan) {
                $loan_total = floatval($active_loan['total_payable']);
                $loan_rem = floatval($active_loan['remaining_balance']);
                $paid = $loan_total - $loan_rem;
                $num_inst = intval($active_loan['num_installments'] ?: 23);
                $inst_amt = ($num_inst > 0) ? ($loan_total / $num_inst) : 0;
                $installments_paid = ($inst_amt > 0) ? floor($paid / $inst_amt) : 0;

                // Rule: 10 Installments for Cash/Withdrawal
                if (($type === 'cash' || $type === 'withdrawal') && $installments_paid < 10) {
                    throw new Exception("Withdrawal denied. Minimum 10 installments required (Paid: $installments_paid).");
                }

                // Rule: Buffer Percentage
                $principal = floatval($active_loan['principal']); 
                $buffer_col = 'buffer_' . $type;
                $buffer_pct = floatval($w_settings[$buffer_col] ?? 10) / 100;
                $required_savings = $principal * $buffer_pct;

                if ($current_balance < $required_savings) {
                    throw new Exception("Denied. Savings below " . ($buffer_pct*100) . "% of principal. Req: ₦" . number_format($required_savings));
                }
            }

            // 4. Daily Limit Check
            $today = date('Y-m-d', strtotime($input_date));
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM saving_collections 
                WHERE client_id = ? AND DATE(date) = ? AND (type IN ('cash','withdrawal','return'))
            ");
            $stmt->execute([$client_id, $today]);
            $daily_count = $stmt->fetchColumn();
            
            if ($daily_count >= $w_settings['max_withdrawals_per_day']) {
                throw new Exception("Daily withdrawal limit reached.");
            }

            // 5. Process Specific Types
            $pic_path = null;
            $amount_deducted = 0;

            if ($type === 'cash') {
                if ($amount <= 0) throw new Exception("Invalid amount.");
                if ($amount > $w_settings['max_cash_withdrawal']) throw new Exception("Exceeds cash limit.");
                if ($current_balance < $amount) throw new Exception("Insufficient savings.");

                // Image Upload
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

                // Calculate Deduction
                $amount_deducted = $is_return ? min($current_balance, $total_loan_balance) : $amount;
                
                if ($amount_deducted <= 0) throw new Exception("Amount must be positive.");

                // Update Loan Record
                if ($active_loan) {
                    $new_rem_bal = floatval($active_loan['remaining_balance']) - $amount_deducted;
                    $status = ($new_rem_bal <= 0) ? 'completed' : 'active';
                    $payoff_sql = ($new_rem_bal <= 0) ? ", payoff_date = '$input_date'" : "";

                    $upd_loan = $pdo->prepare("
                        UPDATE disbursements 
                        SET remaining_balance = ?, status = ? $payoff_sql 
                        WHERE id = ?
                    ");
                    $upd_loan->execute([$new_rem_bal, $status, $active_loan['id']]);
                    // NOTE: Removed insertion into loan_collections to prevent double record in feed.
                }
            }

            // 6. Insert Saving Transaction (Negative Amount)
            $tx_id = ($type === 'return' ? 'RTN-' : ($type === 'cash' ? 'CSH-' : 'WTH-')) . generateUUID();
            $stmt = $pdo->prepare("
                INSERT INTO saving_collections 
                (transaction_id, client_id, amount, type, date, officer, balance_after, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $new_balance = $current_balance - $amount_deducted;
            $note = ($pic_path ? "Image: " . basename($pic_path) : "Used for loan deduction");
            $note .= " | Processed by BM";
            
            $stmt->execute([
                $tx_id, $client_id, -$amount_deducted, $type, $input_date, $officer, $new_balance, $note
            ]);

            // 7. Update Saving Balance Table
            $stmt = $pdo->prepare("UPDATE saving_balances SET balance = ? WHERE client_id = ?");
            $stmt->execute([$new_balance, $client_id]);

            // 8. Check if client should be set to inactive (return type only)
            if ($type === 'return' && $new_balance <= 0) {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM disbursements WHERE client_id = ? AND status = 'active'");
                $stmt->execute([$client_id]);
                if ($stmt->fetchColumn() == 0) {
                    $stmt = $pdo->prepare("UPDATE clients SET status = 'inactive' WHERE id = ?");
                    $stmt->execute([$client_id]);
                }
            }

            $pdo->commit();
            $message = ucfirst($type) . " processed successfully. Amount: ₦" . number_format($amount_deducted);
            $message_type = 'success';

        } catch (Exception $e) {
            $pdo->rollBack();
            $message = $e->getMessage();
            $message_type = 'error';
        }
    }
    
    $_SESSION['message'] = $message;
    $_SESSION['message_type'] = $message_type;
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit();
}

// --- AJAX HISTORY LOADER ---
if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    header('Content-Type: application/json');
    $page = max(1, intval($_GET['page'] ?? 1));
    $co_filter = $_GET['co'] ?? 'all';
    $union_filter = $_GET['union'] ?? 'all';
    $limit = 10;
    $offset = ($page - 1) * $limit;
    
    $where = "WHERE sc.amount < 0 AND c.branch_id = ?";
    $params = [$branch_id];

    if ($co_filter !== 'all') {
        $where .= " AND c.officer_username = ?";
        $params[] = $co_filter;
    }

    if ($union_filter !== 'all') {
        $where .= " AND c.union = ?";
        $params[] = $union_filter;
    }

    $sql = "SELECT sc.transaction_id, sc.date, ABS(sc.amount) as amount, sc.type, c.name as client, sc.officer 
            FROM saving_collections sc
            JOIN clients c ON sc.client_id = c.id
            $where
            ORDER BY sc.date DESC LIMIT $limit OFFSET $offset";
            
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM saving_collections sc JOIN clients c ON sc.client_id = c.id $where");
    $stmt->execute($params);
    $total = $stmt->fetchColumn();
    
    echo json_encode(['status'=>'success', 'entries'=>$entries, 'has_more'=>($offset + count($entries) < $total)]);
    exit;
}

// --- LOAN PLANS (For UI Profile) ---
$loan_terms =[];
try {
    $stmt = $pdo->prepare("SELECT * FROM loan_plans");
    $stmt->execute();
    while ($plan = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $plan_key = strtolower($plan['duration'] . '_' . str_replace(' ', '_', $plan['unit']));
        $loan_terms[$plan_key] =[
            'id' => $plan['id'],
            'installments' => (int)$plan['duration'],
            'unit' => $plan['unit'],
            'min_amount' => (float)$plan['min_amount'],
            'max_amount' => (float)$plan['max_amount'],
            'increment_amount' => (float)$plan['increment_amount'],
            'max_first_loan_amount' => (float)$plan['max_first_loan_amount']
        ];
    }
} catch (Exception $e) {}

// --- DATA PREPARATION FOR VIEW ---

// 1. Fetch COs in branch
$stmt_cos = $pdo->prepare("SELECT username FROM users WHERE branch_id = ? AND role = 'co' AND status = 'active'");
$stmt_cos->execute([$branch_id]);
$co_list = $stmt_cos->fetchAll(PDO::FETCH_COLUMN);
sort($co_list);

// 2. Fetch Clients (Branch Wide)
$client_data_js =[];
$total_savings = 0;
$total_savers = 0;

$stmt = $pdo->prepare("
    SELECT c.id, c.name, c.union, c.officer_username, c.client_type, sb.balance as savings_balance 
    FROM clients c
    LEFT JOIN saving_balances sb ON c.id = sb.client_id
    WHERE c.branch_id = ? AND c.status = 'active'
    ORDER BY c.union, c.name
");
$stmt->execute([$branch_id]);
$raw_clients = $stmt->fetchAll(PDO::FETCH_ASSOC);

// N+1 Query Optimization: Fetch all active disbursements for branch
$d_stmt = $pdo->prepare("
    SELECT d.* 
    FROM disbursements d
    JOIN clients c ON d.client_id = c.id
    WHERE c.branch_id = ? AND d.status != 'completed' AND d.remaining_balance > 0 
    ORDER BY d.date DESC
");
$d_stmt->execute([$branch_id]);
$all_active_loans = $d_stmt->fetchAll(PDO::FETCH_ASSOC);

$loans_by_client =[];
foreach($all_active_loans as $l) {
    if(!isset($loans_by_client[$l['client_id']])) {
        $loans_by_client[$l['client_id']] = $l; 
    }
}

// N+1 Query Optimization: Fetch max principal for limits
$max_stmt = $pdo->prepare("
    SELECT d.client_id, MAX(d.principal) as max_p 
    FROM disbursements d
    JOIN clients c ON d.client_id = c.id 
    WHERE c.branch_id = ? 
    GROUP BY d.client_id
");
$max_stmt->execute([$branch_id]);
$max_prev_by_client =[];
foreach($max_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $max_prev_by_client[$row['client_id']] = floatval($row['max_p']);
}

// Build JS Client Data array
foreach ($raw_clients as $c) {
    $cid = $c['id'];
    $u = $c['union'] ?? 'Unassigned';
    $co = $c['officer_username'] ?? 'Unassigned';
    
    $s_bal = floatval($c['savings_balance'] ?? 0);
    if ($s_bal > 0) { $total_savings += $s_bal; $total_savers++; }

    $loan = $loans_by_client[$cid] ?? null;
    $l_bal = $loan ? floatval($loan['remaining_balance']) : 0;
    $active_type = $loan ? ($loan['loan_term_type'] ?? ($loan['num_installments'].' '.$loan['unit'])) : 'None';
    $active_principal = $loan ? floatval($loan['principal']) : 0;
    
    $max_prev = $max_prev_by_client[$cid] ?? 0;
    $next_limit = ($max_prev == 0) 
        ? $d_settings['global_max_first_loan_amount'] 
        : min($max_prev + $d_settings['global_max_increment_amount'], $d_settings['max_disbursement']);

    $total_payable = $loan ? floatval($loan['total_payable']) : 0;
    $num_inst = $loan ? intval($loan['num_installments']) : 1;
    $inst_amt = ($num_inst > 0) ? ($total_payable / $num_inst) : 0;

    $paid_amount = $total_payable - $l_bal;
    $installments_paid = ($inst_amt > 0) ? floor($paid_amount / $inst_amt) : 0;

    $client_data_js[] = [
        'id' => $cid,
        'name' => $c['name'],
        'union' => $u,
        'officer_username' => $co,
        'savings_balance' => $s_bal,
        'loan_balance' => $l_bal,
        'total_installment_amount' => round($inst_amt),
        'active_loan_type' => $active_type,
        'active_loan_total' => $total_payable,
        'active_loan_installments' => $num_inst,
        'installments_paid' => $installments_paid,
        'active_loan_principal' => $active_principal,
        'next_loan_limit' => $next_limit
    ];
}

// 3. Stats for Dashboard (Current Month, Branch Wide)
$current_month = date('Y-m');
$stmt = $pdo->prepare("
    SELECT COUNT(*) as cnt, ABS(SUM(sc.amount)) as total 
    FROM saving_collections sc
    JOIN clients c ON sc.client_id = c.id 
    WHERE c.branch_id = ? AND sc.amount < 0 AND DATE_FORMAT(sc.date, '%Y-%m') = ?
");
$stmt->execute([$branch_id, $current_month]);
$stats = $stmt->fetch();
$monthly_withdrawals_count = $stats['cnt'] ?? 0;
$monthly_withdrawals_amt = $stats['total'] ?? 0;

// User Profile
$profile_pic = 'default_avatar.png';
$u_stmt = $pdo->prepare("SELECT profile_pic FROM users WHERE username = ?");
$u_stmt->execute([$username]);
$u_res = $u_stmt->fetch();
if($u_res && $u_res['profile_pic']) $profile_pic = $u_res['profile_pic'];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#ef4444">
    <title>Withdrawal | CUPAD BM</title>
    
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    
    <script>
    tailwind.config = {
        darkMode: 'class',
        theme: {
            extend: {
                colors: {
                    primary: '#ef4444',
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
            --primary-color: #ef4444; --secondary-color: #8b5cf6;
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
        
        /* HEADER */
        .main-header { background: rgba(255, 255, 255, 0.95); backdrop-filter: blur(12px); border-bottom: 1px solid var(--border-color); position: sticky; top: 0; z-index: 50; padding-top: env(safe-area-inset-top); }
        html.dark .main-header { background: rgba(15, 23, 42, 0.95); }
        .navbar { display: flex; justify-content: space-between; align-items: center; padding: 0.8rem 1rem; max-width: 1280px; margin: 0 auto; }
        
        /* DASHBOARD GRID */
        .dashboard-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1rem; margin-bottom: 1.5rem; }
        .dashboard-card { border-radius: var(--border-radius-xl); padding: 1.5rem; position: relative; overflow: hidden; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1); color: white; transition: transform 0.2s ease; border: none; }
        .dashboard-card:active { transform: scale(0.98); }
        .card-gradient-red { background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); }
        .card-gradient-amber { background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); }
        .card-gradient-green { background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%); }
        .card-bg-icon { position: absolute; right: -10px; bottom: -20px; font-size: 6rem; color: white; opacity: 0.1; transform: rotate(-10deg); pointer-events: none; }
        
        /* ACTIVITY FEED */
        .activity-card { display: flex; align-items: center; background: var(--bg-secondary); padding: 1rem; margin-bottom: 0.75rem; border-radius: var(--border-radius-lg); border: 1px solid var(--border-color); }
        .ac-icon-box { width: 44px; height: 44px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; margin-right: 0.8rem; flex-shrink: 0; }
        .ac-content { flex: 1; min-width: 0; }
        .ac-top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 2px;}
        .ac-bottom { display: flex; justify-content: space-between; align-items: center; margin-top: 2px; }
        .client-name { font-weight: 600; font-size: 0.95rem; color: var(--text-primary); }
        .activity-amount { font-weight: 700; font-size: 1rem; }
        .activity-date { font-size: 0.7rem; color: var(--text-secondary); }

        /* FORM */
        .input-group { background: var(--bg-primary); border: 1px solid var(--border-color); border-radius: 0.75rem; padding: 0.75rem; transition: all 0.2s ease; position: relative; }
        .input-group:focus-within { border-color: var(--primary-color); box-shadow: 0 0 0 2px rgba(239, 68, 68, 0.2); background: var(--bg-secondary); }
        .input-group:hover { border-color: #cbd5e1; }
        html.dark .input-group:hover { border-color: #475569; }
        
        .input-field { background: transparent; border: none; width: 100%; color: var(--text-primary); outline: none; font-size: 0.95rem; appearance: none; -webkit-appearance: none; padding-right: 2rem; cursor: pointer; }
        .input-field option { background-color: var(--bg-secondary); color: var(--text-primary); padding: 10px; }
        
        /* BOTTOM NAV */
        .mobile-bottom-nav { display: none; position: fixed; bottom: 0; left: 0; right: 0; background: var(--bg-secondary); border-top: 1px solid var(--border-color); padding: 0.5rem 1rem calc(0.5rem + env(safe-area-inset-bottom)); z-index: 100; justify-content: space-between; box-shadow: 0 -4px 10px rgba(0,0,0,0.05); }
        .nav-item { display: flex; flex-direction: column; align-items: center; justify-content: center; text-decoration: none; color: var(--text-secondary); font-size: 0.7rem; font-weight: 500; gap: 4px; flex: 1; }
        .nav-item i { font-size: 1.4rem; }
        .nav-item.active { color: var(--primary-color); }
        
        /* CONFIRM MODAL */
        .modal-overlay { 
            position: fixed; top: 0; left: 0; width: 100%; height: 100%; 
            background: rgba(0,0,0,0.5); backdrop-filter: blur(4px); 
            z-index: 999; 
            display: none; 
            align-items: center; justify-content: center; 
            opacity: 0; transition: opacity 0.2s; 
            pointer-events: none; 
        }
        .modal-overlay.active { 
            opacity: 1; 
            display: flex; 
            pointer-events: auto; 
        }
        .modal-content { background: var(--bg-card); width: 90%; max-width: 400px; padding: 1.5rem; border-radius: 1.5rem; text-align: center; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25); transform: scale(0.95); transition: transform 0.2s; }
        .modal-overlay.active .modal-content { transform: scale(1); }

        @media (max-width: 768px) {
            .mobile-bottom-nav { display: flex; }
            .dashboard-grid { display: flex; overflow-x: auto; scroll-snap-type: x mandatory; gap: 1rem; padding-bottom: 0.5rem; margin-right: -1rem; padding-right: 1.5rem; scrollbar-width: none; }
            .dashboard-grid::-webkit-scrollbar { display: none; }
            .dashboard-card { min-width: 85vw; scroll-snap-align: center; flex-shrink: 0; }
        }
        
        #toast-container { position: fixed; top: 1rem; left: 50%; transform: translateX(-50%); z-index: 999; width: 90%; max-width: 350px; pointer-events: none; }
        .toast { background: var(--bg-secondary); border-left: 4px solid; padding: 1rem; border-radius: 8px; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); margin-bottom: 0.5rem; display: flex; align-items: center; gap: 10px; animation: slideDown 0.3s ease; pointer-events: auto; }
        @keyframes slideDown { from { transform: translateY(-20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }

        .profile-btn { width: 36px; height: 36px; border-radius: 50%; overflow: hidden; border: 2px solid var(--primary-color); background: var(--bg-secondary); cursor: pointer; display: flex; align-items: center; justify-content: center; position: relative; }
        .profile-btn img { width: 100%; height: 100%; object-fit: cover; }
        .profile-fallback-icon { font-size: 1.2rem; color: var(--text-secondary); }
    </style>
</head>
<body>

    <div id="toast-container"></div>

    <!-- Confirm Modal -->
    <div id="confirmModal" class="modal-overlay">
        <div class="modal-content border border-gray-200 dark:border-gray-700">
            <div class="w-16 h-16 bg-red-100 text-red-500 rounded-full flex items-center justify-center mx-auto mb-4 text-2xl">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
            <h3 class="text-lg font-bold text-gray-900 dark:text-white mb-2">Confirm Return?</h3>
            <p class="text-sm text-gray-500 dark:text-gray-400 mb-6">This will pay off the loan using available savings. This action cannot be easily undone.</p>
            <div class="flex gap-3">
                <button id="cancelModalBtn" class="flex-1 py-3 rounded-xl border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 font-bold hover:bg-gray-50 dark:hover:bg-slate-700">Cancel</button>
                <button id="confirmModalBtn" class="flex-1 py-3 rounded-xl bg-red-600 text-white font-bold hover:bg-red-700 shadow-lg shadow-red-500/30">Confirm</button>
            </div>
        </div>
    </div>

    <!-- HEADER -->
    <header class="main-header">
        <nav class="navbar">
            <a href="dashboard.php" class="flex items-center gap-2 no-underline">
                <img src="<?php echo htmlspecialchars($base_path . 'favicon.ico'); ?>" alt="Logo" class="h-8">
                <span class="text-xl font-extrabold text-blue-600 tracking-tight">CUPAD BM</span>
            </a>
            
            <div class="flex items-center gap-3">
                <button id="theme-toggle" class="p-2 text-gray-500 dark:text-gray-400">
                    <i class="fas fa-moon dark:hidden"></i><i class="fas fa-sun hidden dark:inline"></i>
                </button>
                
                <div class="profile-btn" onclick="window.location.href='profile.php'">
                    <i class="fas fa-user profile-fallback-icon"></i>
                    <?php if ($profile_pic && $profile_pic !== 'default_avatar.png'): ?>
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
        
        <!-- TITLE -->
        <div class="mb-6">
            <h1 class="text-2xl font-extrabold text-gray-900 dark:text-white">Withdrawal</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400">Process branch payouts and loan deductions</p>
        </div>

        <!-- STATS -->
        <div class="dashboard-grid">
            <div class="dashboard-card card-gradient-green">
                <i class="fas fa-users card-bg-icon"></i>
                <div class="flex justify-between items-start relative z-10">
                    <div>
                        <div class="text-sm font-semibold uppercase opacity-90">Branch Savers</div>
                        <div class="text-3xl font-extrabold mt-1 mb-2 count-up" data-target="<?php echo $total_savers; ?>">0</div>
                        <div class="text-xs bg-black/20 rounded-full px-3 py-1 inline-flex items-center"><i class="fas fa-hashtag mr-1"></i> Clients</div>
                    </div>
                    <div class="w-12 h-12 bg-white/20 backdrop-blur rounded-xl flex items-center justify-center text-xl"><i class="fas fa-user-plus"></i></div>
                </div>
            </div>

            <div class="dashboard-card card-gradient-amber">
                <i class="fas fa-piggy-bank card-bg-icon"></i>
                <div class="flex justify-between items-start relative z-10">
                    <div>
                        <div class="text-sm font-semibold uppercase opacity-90">Branch Savings</div>
                        <div class="text-3xl font-extrabold mt-1 mb-2">₦<span class="count-up" data-target="<?php echo $total_savings; ?>" data-currency="true">0</span></div>
                        <div class="text-xs bg-black/20 rounded-full px-3 py-1 inline-flex items-center"><i class="fas fa-chart-line mr-1"></i> All Time</div>
                    </div>
                    <div class="w-12 h-12 bg-white/20 backdrop-blur rounded-xl flex items-center justify-center text-xl"><i class="fas fa-coins"></i></div>
                </div>
            </div>

            <!-- Installment Count -->
            <div class="dashboard-card card-gradient-red">
                <i class="fas fa-calendar-check card-bg-icon"></i>
                <div class="flex justify-between items-start relative z-10">
                    <div>
                        <div class="text-sm font-semibold uppercase opacity-90">Monthly Txns</div>
                        <div class="text-3xl font-extrabold mt-1 mb-2 count-up" data-target="<?php echo $monthly_withdrawals_count; ?>">0</div>
                        <div class="text-xs bg-black/20 rounded-full px-3 py-1 inline-flex items-center">
                            <i class="fas fa-wallet mr-1"></i> ₦<?php echo number_format($monthly_withdrawals_amt); ?>
                        </div>
                    </div>
                    <div class="w-12 h-12 bg-white/20 backdrop-blur rounded-xl flex items-center justify-center text-xl"><i class="fas fa-file-invoice"></i></div>
                </div>
            </div>
        </div>

        <!-- MAIN LAYOUT -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            
            <!-- FORM SECTION -->
            <div class="lg:col-span-1">
                <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm border border-gray-200 dark:border-slate-700 sticky top-24">
                    <div class="p-5 border-b border-gray-100 dark:border-slate-700">
                        <h2 class="text-lg font-bold flex items-center gap-2 text-gray-800 dark:text-white">
                            <i class="fas fa-hand-holding-usd text-red-500"></i> New Transaction
                        </h2>
                    </div>

                    <form id="withdrawalForm" method="POST" enctype="multipart/form-data" class="p-5 space-y-5">
                        
                        <!-- Credit Officer Filter -->
                        <div>
                            <label class="text-xs font-bold text-gray-500 uppercase mb-1 block">Credit Officer</label>
                            <div class="input-group py-2">
                                <i class="fas fa-user-tie absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 pointer-events-none"></i>
                                <select id="co-filter" class="input-field cursor-pointer pl-8">
                                    <option value="all">All Credit Officers</option>
                                    <?php foreach($co_list as $co_name): ?>
                                        <option value="<?php echo htmlspecialchars($co_name); ?>"><?php echo htmlspecialchars($co_name); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <i class="fas fa-chevron-down absolute right-3 top-1/2 -translate-y-1/2 text-xs text-gray-400 pointer-events-none"></i>
                            </div>
                        </div>

                        <!-- Union Filter -->
                        <div>
                            <label class="text-xs font-bold text-gray-500 uppercase mb-2 block">Union Filter</label>
                            <div id="union-tags" class="flex flex-wrap gap-2"></div>
                        </div>

                        <!-- Client Dropdown -->
                        <div>
                            <label class="text-xs font-bold text-gray-500 uppercase mb-1 block">Client</label>
                            <div class="input-group py-2">
                                <i class="fas fa-user absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 group-focus-within:text-red-500 transition-colors pointer-events-none"></i>
                                <select id="client_id" name="client_id" required class="input-field cursor-pointer pl-8">
                                    <option value="">Select Client...</option>
                                </select>
                                <i class="fas fa-chevron-down absolute right-3 top-1/2 -translate-y-1/2 text-xs text-gray-400 pointer-events-none"></i>
                            </div>
                        </div>

                        <!-- Client Stats (Dynamic Enhanced Info Card) -->
                        <div id="client-info-card" class="hidden rounded-xl border border-gray-200 dark:border-slate-700 overflow-hidden bg-white dark:bg-slate-800 shadow-sm mb-4 transition-all duration-300">
                            <!-- Header -->
                            <div class="px-4 py-2 bg-gray-50 dark:bg-slate-700/50 border-b border-gray-100 dark:border-slate-700 flex justify-between items-center">
                                <span class="text-xs font-bold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Client Profile</span> 
                                <span id="net-position-badge" class="bg-gray-200 text-gray-600 px-2 py-0.5 rounded text-[10px] uppercase font-bold">Checking...</span>
                            </div>

                            <!-- Main Stats Grid -->
                            <div class="p-4 grid grid-cols-2 gap-y-4 gap-x-6">
                                
                                <!-- Savings -->
                                <div>
                                    <div class="flex items-center gap-1.5 mb-0.5">
                                        <div class="w-1.5 h-1.5 rounded-full bg-green-500"></div>
                                        <span class="text-[10px] font-bold text-gray-400 uppercase tracking-wide">Savings Balance</span>
                                    </div>
                                    <div id="info-savings" class="text-lg font-extrabold text-gray-800 dark:text-white tracking-tight">₦0</div>
                                </div>

                                <!-- Loan Balance -->
                                <div class="text-right">
                                    <div class="flex items-center gap-1.5 mb-0.5 justify-end">
                                        <span class="text-[10px] font-bold text-gray-400 uppercase tracking-wide">Outstanding Loan</span>
                                        <div class="w-1.5 h-1.5 rounded-full bg-red-500"></div>
                                    </div>
                                    <div id="info-loan" class="text-lg font-extrabold text-red-600 tracking-tight">₦0</div>
                                    <div id="info-installment-count" class="text-[10px] text-gray-500 font-bold mt-1 hidden"></div>
                                </div>

                                <!-- Active Plan (Detailed) -->
                                <div class="col-span-2 pt-3 border-t border-dashed border-gray-200 dark:border-slate-700">
                                    <div class="flex justify-between items-end">
                                        <div>
                                            <span class="text-[10px] font-bold text-gray-400 uppercase block mb-1">Active Loan Plan</span>
                                            <div class="flex items-center gap-2">
                                                <span id="info-plan-icon" class="text-blue-500 bg-blue-50 dark:bg-blue-900/30 p-1.5 rounded-md">
                                                    <i class="fas fa-file-signature text-xs"></i>
                                                </span>
                                                <span id="info-plan" class="text-sm font-bold text-blue-600 dark:text-blue-400">None</span>
                                            </div>
                                        </div>
                                        
                                        <div class="text-right">
                                            <span class="text-[10px] font-bold text-gray-400 uppercase block mb-1">Next Loan Limit</span>
                                            <span id="info-limit" class="text-sm font-bold text-gray-600 dark:text-gray-300">₦0</span>
                                        </div>
                                    </div>
                                </div>

                                <!-- Eligibility Notification Area -->
                                <div id="eligibility-container" class="col-span-2 mt-2 hidden"></div>

                                <!-- Savings Health / Buffer Status -->
                                <div id="savings-health-container" class="col-span-2 mt-3 pt-3 border-t border-dashed border-gray-200 dark:border-slate-700 hidden">
                                    <div class="flex justify-between items-center mb-1">
                                        <span class="text-[10px] font-bold text-gray-400 uppercase">Savings Buffer (<span id="buffer-percentage-display">10</span>% Req.)</span>
                                        <span id="savings-health-percent" class="text-xs font-bold">0%</span>
                                    </div>
                                    <div class="w-full bg-gray-200 dark:bg-slate-700 rounded-full h-1.5">
                                        <div id="savings-health-bar" class="bg-red-500 h-1.5 rounded-full transition-all duration-500" style="width: 0%"></div>
                                    </div>
                                    <p id="savings-health-msg" class="text-[10px] mt-1 text-red-500 font-medium">Below required % of Principal. Withdrawal Locked.</p>
                                </div>
                            </div>
                        </div>

                        <!-- Type Dropdown -->
                        <div>
                            <label class="text-xs font-bold text-gray-500 uppercase mb-1 block">Transaction Type</label>
                            <div class="input-group py-2">
                                <select id="withdrawal_type" name="withdrawal_type" class="input-field cursor-pointer">
                                    <option value="cash">Cash Withdrawal (Manual)</option>
                                    <option value="withdrawal" selected>Withdrawal (Deduct)</option>
                                    <option value="return">Return (Payoff Loan)</option>
                                </select>
                                <i class="fas fa-chevron-down absolute right-3 top-1/2 -translate-y-1/2 text-xs text-gray-400 pointer-events-none"></i>
                            </div>
                            <p id="type-help" class="text-[10px] text-gray-400 mt-1 italic">Deducts from savings to reduce loan balance.</p>
                        </div>

                        <!-- Amount -->
                        <div id="amount-container">
                            <label class="text-xs font-bold text-gray-500 uppercase mb-1 block">Amount</label>
                            <div class="input-group flex items-center py-2">
                                <span class="text-gray-400 mr-2">₦</span>
                                <input type="number" id="amount" name="amount" step="0.01" class="input-field font-bold p-0" placeholder="0.00">
                            </div>
                            <p id="amount-helper" class="text-[10px] text-red-500 mt-1 font-bold h-4"></p>
                        </div>

                        <!-- Image Upload (For Cash) -->
                        <div id="image-upload-container" class="hidden">
                            <label class="text-xs font-bold text-gray-500 uppercase mb-1 block">Proof Image</label>
                            <input type="file" name="picture" id="picture" accept="image/*" class="hidden">
                            <div id="drop-zone" class="border-2 border-dashed border-gray-300 dark:border-slate-600 rounded-xl p-4 text-center cursor-pointer hover:bg-gray-50 dark:hover:bg-slate-800/50 transition">
                                <div id="upload-placeholder">
                                    <i class="fas fa-camera text-2xl text-gray-300 mb-1"></i>
                                    <p class="text-xs text-gray-500">Tap to upload</p>
                                </div>
                                <img id="img-preview" class="hidden h-16 w-auto mx-auto rounded border dark:border-slate-600">
                            </div>
                        </div>

                        <!-- Date -->
                        <div>
                            <div class="flex justify-between items-center mb-1">
                                <label class="text-xs font-bold text-gray-500 uppercase block">Date</label>
                                <button type="button" id="btn-set-now" class="text-[10px] font-bold text-red-600 hover:underline">SET NOW</button>
                            </div>
                            <div class="input-group py-2">
                                <input type="datetime-local" id="date" name="date" value="<?php echo date('Y-m-d\TH:i'); ?>" <?php echo ($w_settings['withdrawal_date_readonly']??0) ? 'readonly' : ''; ?> class="input-field text-sm p-0 pr-0">
                            </div>
                        </div>

                        <!-- RED BUTTON -->
                        <button type="button" id="preSubmitBtn" class="w-full py-3.5 bg-red-600 hover:bg-red-700 text-white rounded-xl font-bold shadow-lg shadow-red-500/30 transition transform active:scale-95">
                            Process Transaction
                        </button>
                    </form>
                </div>
            </div>

            <!-- HISTORY FEED (RIGHT) -->
            <div class="lg:col-span-2">
                <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm border border-gray-200 dark:border-slate-700 min-h-[500px] flex flex-col">
                    <div class="p-5 border-b border-gray-100 dark:border-slate-700 flex flex-wrap justify-between items-center gap-3">
                        <h2 class="text-lg font-bold text-gray-800 dark:text-white whitespace-nowrap">Transaction History</h2>
                        <div class="flex flex-wrap gap-2">
                            <select id="history-co-filter" class="text-xs bg-gray-100 dark:bg-slate-700 border-none rounded-lg px-2 py-1 outline-none cursor-pointer font-semibold text-red-600">
                                <option value="all">All COs</option>
                                <?php foreach($co_list as $co_name): ?>
                                    <option value="<?php echo htmlspecialchars($co_name); ?>"><?php echo htmlspecialchars($co_name); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select id="history-union-filter" class="text-xs bg-gray-100 dark:bg-slate-700 border-none rounded-lg px-2 py-1 outline-none cursor-pointer">
                                <option value="all">All Unions</option>
                            </select>
                            <button id="loadMoreBtn" class="text-xs font-bold text-red-500 hover:underline hidden">More</button>
                        </div>
                    </div>

                    <div id="history-container" class="p-4 flex-1 overflow-y-auto max-h-[600px]">
                        <div class="text-center py-10 text-gray-400">Loading...</div>
                    </div>
                </div>
            </div>

        </div>
    </main>

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
        <a href="saving_withdrawal.php" class="nav-item active">
             <!-- RED CENTER FAB -->
             <div style="background: var(--primary-color); width: 45px; height: 45px; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; margin-top: -25px; box-shadow: 0 4px 10px rgba(239, 68, 68, 0.4);">
                <i class="fas fa-wallet" style="font-size: 1.2rem; margin:0;"></i>
             </div>
             <span style="font-size:0.65rem; font-weight:600; margin-top:2px;">Withdraw</span>
        </a>
        <a href="clients.php" class="nav-item">
            <i class="fas fa-users"></i>
            <span>Clients</span>
        </a>
    </nav>

    <script>
        // Init Data
        const clientData = <?php echo json_encode($client_data_js); ?>;
        const withdrawalSettings = <?php echo json_encode($w_settings); ?>;
        const loanTerms = <?php echo json_encode($loan_terms); ?>;
        const blockedTypes = JSON.parse(withdrawalSettings.blocked_withdrawal_types || '[]');
        const sessionMsg = "<?php echo isset($_SESSION['message']) ? addslashes($_SESSION['message']) : ''; ?>";
        const sessionType = "<?php echo isset($_SESSION['message_type']) ? $_SESSION['message_type'] : ''; ?>";
        const RED_COLOR = '#ef4444';

        let historyState = { page: 1, co: 'all', union: 'all', type: 'all', loading: false, hasMore: true };

        const els = {
            coFilter: document.getElementById('co-filter'),
            unionTags: document.getElementById('union-tags'),
            clientSelect: document.getElementById('client_id'),
            infoCard: document.getElementById('client-info-card'),
            infoSavings: document.getElementById('info-savings'),
            infoLoan: document.getElementById('info-loan'),
            netPosBadge: document.getElementById('net-position-badge'),
            typeSelect: document.getElementById('withdrawal_type'),
            amountInput: document.getElementById('amount'),
            amountHelper: document.getElementById('amount-helper'),
            amountContainer: document.getElementById('amount-container'),
            imgContainer: document.getElementById('image-upload-container'),
            fileInput: document.getElementById('picture'),
            imgPreview: document.getElementById('img-preview'),
            dropZone: document.getElementById('drop-zone'),
            uploadPlaceholder: document.getElementById('upload-placeholder'),
            typeHelp: document.getElementById('type-help'),
            historyContainer: document.getElementById('history-container'),
            historyCoFilter: document.getElementById('history-co-filter'),
            historyUnionFilter: document.getElementById('history-union-filter'),
            loadMoreBtn: document.getElementById('loadMoreBtn'),
            form: document.getElementById('withdrawalForm'),
            preSubmitBtn: document.getElementById('preSubmitBtn'),
            modal: document.getElementById('confirmModal'),
            cancelModalBtn: document.getElementById('cancelModalBtn'),
            confirmModalBtn: document.getElementById('confirmModalBtn')
        };

        const formatCurrency = (amt) => '₦' + parseFloat(amt).toLocaleString('en-US');

        // Enhanced Toast
        function showToast(msg, type) {
            const el = document.createElement('div');
            el.className = 'toast';
            let borderColor, iconClass;
            
            switch(type) {
                case 'success': borderColor = '#22c55e'; iconClass = 'fa-check-circle text-green-500'; break;
                case 'warning': borderColor = '#f59e0b'; iconClass = 'fa-exclamation-triangle text-orange-500'; break;
                case 'info': borderColor = '#3b82f6'; iconClass = 'fa-info-circle text-blue-500'; break;
                case 'error': default: borderColor = '#ef4444'; iconClass = 'fa-times-circle text-red-500'; break;
            }

            el.style.borderLeftColor = borderColor;
            el.innerHTML = `<i class="fas ${iconClass}"></i> <span class="text-sm font-medium text-gray-800 dark:text-gray-200">${msg}</span>`;
            document.getElementById('toast-container').appendChild(el);
            setTimeout(() => { el.style.opacity = '0'; setTimeout(() => el.remove(), 300); }, 3000);
        }

        if(sessionMsg) { showToast(sessionMsg, sessionType); <?php unset($_SESSION['message'], $_SESSION['message_type']); ?> }

        // --- FILTER LOGIC ---
        function getUnions(co) {
            const unions = new Set();
            clientData.forEach(c => {
                if (co === 'all' || c.officer_username === co) unions.add(c.union || 'Unassigned');
            });
            return Array.from(unions).sort();
        }

        function getClients(union, co) {
            return clientData.filter(c => (c.union || 'Unassigned') === union && (co === 'all' || c.officer_username === co));
        }

        function initUnions() {
            const co = els.coFilter.value;
            const unions = getUnions(co);
            
            els.unionTags.innerHTML = '';
            if(!unions.length) { els.unionTags.innerHTML = '<span class="text-xs italic text-gray-400">No unions assigned</span>'; populateClients(null, co); return; }
            
            unions.forEach((u, i) => {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = `px-3 py-1 rounded-full text-xs font-bold border transition ${i===0 ? 'bg-red-600 text-white border-red-600' : 'bg-white text-gray-500 border-gray-200 hover:bg-gray-50 dark:bg-slate-700 dark:text-gray-300 dark:border-slate-600'}`;
                btn.textContent = u;
                btn.onclick = () => selectUnion(u, btn);
                els.unionTags.appendChild(btn);
                if(i===0) selectUnion(u, btn);
            });
        }

        function selectUnion(union, btnEl) {
            Array.from(els.unionTags.children).forEach(c => c.className = 'px-3 py-1 rounded-full text-xs font-bold border transition bg-white text-gray-500 border-gray-200 hover:bg-gray-50 dark:bg-slate-700 dark:text-gray-300 dark:border-slate-600');
            if(btnEl) btnEl.className = 'px-3 py-1 rounded-full text-xs font-bold border transition bg-red-600 text-white border-red-600';
            populateClients(union, els.coFilter.value);
        }

        function populateClients(union, co) {
            els.clientSelect.innerHTML = '<option value="">Select Client...</option>';
            if(union) {
                const clients = getClients(union, co);
                clients.forEach(c => els.clientSelect.innerHTML += `<option value="${c.id}">${c.name}</option>`);
            }
            updateFormUI();
        }

        els.coFilter.addEventListener('change', initUnions);

        function initWithdrawalTypes() {
            const options = els.typeSelect.querySelectorAll('option');
            options.forEach(option => {
                const type = option.value;
                if (blockedTypes.includes(type)) {
                    option.disabled = true;
                    option.textContent += ' (Blocked)';
                    option.style.color = '#ef4444';
                }
            });
            
            if (blockedTypes.includes(els.typeSelect.value)) {
                const availableOption = Array.from(options).find(opt => !opt.disabled && opt.value);
                if (availableOption) {
                    els.typeSelect.value = availableOption.value;
                } else {
                    els.typeSelect.value = '';
                }
            }
        }

        els.clientSelect.onchange = updateFormUI;
        els.typeSelect.onchange = updateFormUI;

        els.amountInput.addEventListener('input', (e) => {
            const val = parseFloat(e.target.value);
            els.amountHelper.textContent = val > 0 ? formatCurrency(val) : '';
        });

        function updateFormUI() {
            const cid = els.clientSelect.value;
            const type = els.typeSelect.value;
            const client = clientData.find(c => c.id == cid); 
            
            const infoPlan = document.getElementById('info-plan');
            const infoPlanIcon = document.getElementById('info-plan-icon');
            const infoLimit = document.getElementById('info-limit');
            const infoInstallmentCount = document.getElementById('info-installment-count');
            const bufferPercentageDisplay = document.getElementById('buffer-percentage-display');
            
            if (bufferPercentageDisplay) {
                const bufferKey = 'buffer_' + type;
                const bufferValue = withdrawalSettings[bufferKey] || 10;
                bufferPercentageDisplay.textContent = bufferValue;
            }
            
            const healthContainer = document.getElementById('savings-health-container');
            const healthPercent = document.getElementById('savings-health-percent');
            const healthBar = document.getElementById('savings-health-bar');
            const healthMsg = document.getElementById('savings-health-msg');
            const eligibilityContainer = document.getElementById('eligibility-container');

            if(client) {
                els.infoCard.classList.remove('hidden');
                
                els.infoSavings.textContent = formatCurrency(client.savings_balance);
                els.infoLoan.textContent = formatCurrency(client.loan_balance);
                
                if (client.loan_balance > 0) {
                    infoPlan.textContent = client.active_loan_type;
                    infoPlan.className = "text-sm font-bold text-blue-600 dark:text-blue-400";
                    infoPlanIcon.innerHTML = '<i class="fas fa-clock text-xs"></i>'; 
                    infoPlanIcon.className = "text-blue-500 bg-blue-50 dark:bg-blue-900/30 p-1.5 rounded-md";

                    infoInstallmentCount.textContent = `${client.installments_paid}/${client.active_loan_installments} Paid`;
                    infoInstallmentCount.classList.remove('hidden');

                    if (type === 'cash' || type === 'withdrawal') {
                        eligibilityContainer.classList.remove('hidden');
                        const isEligible = client.installments_paid >= 10;
                        const req = 10;
                        
                        if (isEligible) {
                            eligibilityContainer.innerHTML = `
                                <div class="bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-lg p-2.5 flex items-start gap-2">
                                    <i class="fas fa-check-circle text-green-500 mt-0.5"></i>
                                    <div>
                                        <p class="text-xs font-bold text-green-700 dark:text-green-400">Withdrawal Eligible</p>
                                        <p class="text-[10px] text-green-600 dark:text-green-500">Client has paid ${client.installments_paid} installments (Min: ${req}).</p>
                                    </div>
                                </div>
                            `;
                        } else {
                            eligibilityContainer.innerHTML = `
                                <div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-2.5 flex items-start gap-2">
                                    <i class="fas fa-lock text-red-500 mt-0.5"></i>
                                    <div>
                                        <p class="text-xs font-bold text-red-700 dark:text-red-400">Withdrawal Locked</p>
                                        <p class="text-[10px] text-red-600 dark:text-red-500">Only ${client.installments_paid} installments paid. Minimum ${req} required.</p>
                                    </div>
                                </div>
                            `;
                        }
                    } else {
                        eligibilityContainer.classList.add('hidden');
                    }
                } else {
                    infoPlan.textContent = "No Active Loan";
                    infoPlan.className = "text-sm font-bold text-gray-400 italic";
                    infoPlanIcon.innerHTML = '<i class="fas fa-check-circle text-xs"></i>'; 
                    infoPlanIcon.className = "text-gray-400 bg-gray-100 dark:bg-slate-700 p-1.5 rounded-md";
                    infoInstallmentCount.classList.add('hidden');
                    eligibilityContainer.classList.add('hidden');
                }
                
                if (client.loan_balance > 0 && client.active_loan_principal > 0) {
                    healthContainer.classList.remove('hidden');
                    const principal = client.active_loan_principal;
                    const savings = client.savings_balance;
                    const bufferKey = 'buffer_' + type;
                    const bufferPercentage = withdrawalSettings[bufferKey] || 10;
                    
                    const ratio = (savings / principal);
                    const percent = ratio * 100;
                    
                    healthPercent.textContent = percent.toFixed(1) + '%';
                    const barWidth = Math.min(percent, 100);
                    healthBar.style.width = barWidth + '%';

                    if (percent < bufferPercentage) {
                        healthBar.className = "bg-red-500 h-1.5 rounded-full transition-all duration-500";
                        healthPercent.className = "text-xs font-bold text-red-500";
                        healthMsg.textContent = `Balance (₦${savings.toLocaleString()}) < ${bufferPercentage}% of Principal (₦${principal.toLocaleString()}). Withdrawal Locked.`;
                        healthMsg.className = "text-[10px] mt-1 text-red-500 font-bold";
                    } else {
                        healthBar.className = "bg-green-500 h-1.5 rounded-full transition-all duration-500";
                        healthPercent.className = "text-xs font-bold text-green-500";
                        healthMsg.textContent = "Savings buffer healthy. Withdrawals allowed.";
                        healthMsg.className = "text-[10px] mt-1 text-green-500 font-medium";
                    }
                } else {
                    healthContainer.classList.add('hidden');
                }

                infoLimit.textContent = formatCurrency(client.next_loan_limit);
                
                const net = client.savings_balance - client.loan_balance;
                if(net >= 0) {
                    els.netPosBadge.textContent = 'Net Saver';
                    els.netPosBadge.className = 'bg-green-100 text-green-700 px-2 py-0.5 rounded text-[10px] uppercase font-bold border border-green-200';
                } else {
                    els.netPosBadge.textContent = 'Net Borrower';
                    els.netPosBadge.className = 'bg-red-100 text-red-700 px-2 py-0.5 rounded text-[10px] uppercase font-bold border border-red-200';
                }
            } else {
                els.infoCard.classList.add('hidden');
            }

            if(type === 'return') {
                els.amountContainer.classList.add('hidden'); els.amountInput.removeAttribute('required');
                els.imgContainer.classList.add('hidden'); els.fileInput.removeAttribute('required');
                els.typeHelp.textContent = "Uses savings (up to loan amount) to pay off loan.";
            } else if (type === 'cash') {
                els.amountContainer.classList.remove('hidden'); els.amountInput.setAttribute('required', 'true'); els.amountInput.removeAttribute('readonly'); els.amountInput.value = '';
                els.imgContainer.classList.remove('hidden'); els.fileInput.setAttribute('required', 'true');
                const maxCash = withdrawalSettings.max_cash_withdrawal || 50000;
                els.typeHelp.textContent = `Manual cash payout. Max: ₦${maxCash.toLocaleString()}. Requires photo.`;
            } else {
                els.amountContainer.classList.remove('hidden'); els.amountInput.setAttribute('required', 'true'); els.amountInput.setAttribute('readonly', 'true');
                els.imgContainer.classList.add('hidden'); els.fileInput.removeAttribute('required');
                els.typeHelp.textContent = "Deducts installment from savings.";
                if(client && client.total_installment_amount > 0) {
                    els.amountInput.value = client.total_installment_amount;
                    els.amountHelper.textContent = formatCurrency(client.total_installment_amount);
                } else {
                    els.amountInput.value = '';
                    els.amountHelper.textContent = '';
                }
            }
        }

        els.dropZone.onclick = () => els.fileInput.click();
        els.fileInput.onchange = (e) => {
            const f = e.target.files[0];
            if(f) {
                const reader = new FileReader();
                reader.onload = e => { 
                    els.imgPreview.src = e.target.result; 
                    els.imgPreview.classList.remove('hidden'); 
                    els.uploadPlaceholder.classList.add('hidden');
                };
                reader.readAsDataURL(f);
            }
        };

        // --- SUBMIT LOGIC ---
        els.preSubmitBtn.onclick = (e) => {
            const type = els.typeSelect.value;
            const client = clientData.find(c => c.id == els.clientSelect.value);
            const selectedDate = document.getElementById('date').value;
            
            if(!client) { showToast('Please select a client', 'error'); return; }

            if (blockedTypes.includes(type)) {
                showToast('This withdrawal type is currently blocked', 'error'); return;
            }

            if (!withdrawalSettings.allow_weekend_withdrawals) {
                const selD = new Date(selectedDate);
                const day = selD.getDay(); 
                if(day === 0 || day === 6) { 
                    showToast('Withdrawals allowed Mon-Fri only', 'warning'); return;
                }
            }

            // In BM view, daily limit logic requires server check usually, but we skip client-side block for simplicity 
            // since BM does not load full branch withdrawal history locally. It will fall back to server block.

            if (type === 'cash') {
                const amount = parseFloat(els.amountInput.value) || 0;
                const maxCash = withdrawalSettings.max_cash_withdrawal || 50000;
                if(amount > maxCash) { showToast(`Exceeds cash limit of ₦${maxCash.toLocaleString()}`, 'warning'); return; }
            }

            if (type === 'return') {
                if (client.loan_balance <= 0) { showToast('No outstanding loans to pay', 'info'); return; }
                if (client.savings_balance <= 0) { showToast('No savings available', 'error'); return; }
                els.modal.classList.add('active'); 
                return;
            } 
            
            submitForm();
        };

        els.cancelModalBtn.onclick = () => els.modal.classList.remove('active');
        els.confirmModalBtn.onclick = () => {
            els.modal.classList.remove('active');
            submitForm();
        };

        function submitForm() {
            const type = els.typeSelect.value;
            const amount = parseFloat(els.amountInput.value) || 0;
            const client = clientData.find(c => c.id == els.clientSelect.value);

            if(type === 'cash') {
                if(client.savings_balance < amount) { showToast('Insufficient funds', 'error'); return; }
                if(withdrawalSettings.require_image_for_cash && !els.fileInput.files[0]) { showToast('Photo required', 'error'); return; }
            }

            els.preSubmitBtn.disabled = true;
            els.preSubmitBtn.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i> Processing...';
            els.form.submit();
        }

        // --- HISTORY LOAD LOGIC ---
        function initHistoryFilters() {
            const coSel = els.historyCoFilter;
            const uSel = els.historyUnionFilter;
            
            coSel.onchange = e => {
                historyState.co = e.target.value;
                const unions = getUnions(historyState.co);
                uSel.innerHTML = '<option value="all">All Unions</option>';
                unions.forEach(u => uSel.innerHTML += `<option value="${u}">${u}</option>`);
                historyState.union = 'all';
                historyState.page = 1;
                loadHistory(true);
            };

            const unions = getUnions(historyState.co);
            uSel.innerHTML = '<option value="all">All Unions</option>';
            unions.forEach(u => uSel.innerHTML += `<option value="${u}">${u}</option>`);

            uSel.onchange = e => { historyState.union = e.target.value; historyState.page = 1; loadHistory(true); };
        }

        async function loadHistory(reset) {
            if(historyState.loading) return;
            historyState.loading = true;
            if(reset) { els.historyContainer.innerHTML = ''; historyState.page = 1; }
            
            try {
                const res = await fetch(`?ajax=1&page=${historyState.page}&co=${historyState.co}&union=${historyState.union}`);
                const data = await res.json();
                
                if(reset && data.entries.length === 0) {
                    els.historyContainer.innerHTML = '<div class="text-center py-10 opacity-50"><i class="fas fa-inbox text-4xl mb-2"></i><div>No transactions found</div></div>';
                } else {
                    data.entries.forEach(item => {
                        const html = `
                        <div class="activity-card">
                            <div class="ac-icon-box" style="background: ${RED_COLOR}15; color: ${RED_COLOR};">
                                <i class="fas ${item.type==='cash'?'fa-money-bill-wave':(item.type==='return'?'fa-undo':'fa-wallet')}"></i>
                            </div>
                            <div class="ac-content min-w-0">
                                <div class="ac-top mb-1">
                                    <div class="client-name truncate pr-2">${item.client}</div>
                                    <div class="activity-amount" style="color: ${RED_COLOR};">₦${parseInt(item.amount).toLocaleString()}</div>
                                </div>
                                <div class="ac-bottom">
                                    <span style="font-size:0.65rem; font-weight:700; background:${RED_COLOR}15; color:${RED_COLOR}; padding:2px 6px; rounded:4px; text-transform:uppercase;">${item.type}</span>
                                    <div class="activity-date whitespace-nowrap"><i class="fas fa-user-tie"></i> ${item.officer} &bull; <i class="far fa-clock"></i> ${item.date.substring(5,16)}</div>
                                </div>
                            </div>
                        </div>`;
                        els.historyContainer.insertAdjacentHTML('beforeend', html);
                    });
                }
                if(data.has_more) { els.loadMoreBtn.classList.remove('hidden'); els.loadMoreBtn.onclick = () => { historyState.page++; loadHistory(false); }; }
                else els.loadMoreBtn.classList.add('hidden');
            } catch(e) { console.error(e); } 
            finally { historyState.loading = false; }
        }

        document.getElementById('btn-set-now').onclick = () => {
            const now = new Date(); now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
            document.getElementById('date').value = now.toISOString().slice(0,16);
        };

        const themeBtn = document.getElementById('theme-toggle');
        themeBtn.onclick = () => { document.documentElement.classList.toggle('dark'); localStorage.setItem('theme', document.documentElement.classList.contains('dark') ? 'dark' : 'light'); };
        
        const savedTheme = localStorage.getItem('theme');
        if (savedTheme === 'dark') document.documentElement.classList.add('dark');
        
        function animateNumbers() {
            document.querySelectorAll('.count-up').forEach(el => {
                const t = parseFloat(el.dataset.target); const curr = el.dataset.currency;
                let s = 0; 
                const anim = (ts) => {
                    if(!s) s=ts; const p = Math.min((ts-s)/1000, 1);
                    el.innerText = curr ? Math.round(p*t).toLocaleString('en-US') : Math.round(p*t);
                    if(p<1) requestAnimationFrame(anim);
                };
                requestAnimationFrame(anim);
            });
        }

        initUnions();
        initWithdrawalTypes();
        initHistoryFilters();
        loadHistory(true);
        animateNumbers();
    </script>
</body>
</html>