<?php
date_default_timezone_set('Africa/Lagos');
// co/clients.php - PDO Version
session_start();

// --- DATABASE CONNECTION ---
require_once __DIR__ . '/../includes/config.php';
$pdo = getDbConnection();

// --- AUTHENTICATION ---
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'co') {
    header('Location: ../index.php');
    exit();
}

// --- Configuration ---
$current_username = $_SESSION['username'] ?? '';

// --- Helper Functions ---
function format_naira(float $amount): string {
    return '₦' . number_format($amount, 0);
}

// --- AJAX HANDLERS ---

// 1. Update Client & Guarantor Details
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax']) && $_POST['ajax'] === 'update_client_details') {
    header('Content-Type: application/json');
    $client_id = $_POST['client_id'] ?? '';
    $c_phone   = trim($_POST['client_phone'] ?? '');
    $g_name    = trim($_POST['guarantor_name'] ?? '');
    $g_phone   = trim($_POST['guarantor_phone'] ?? '');

    if (empty($client_id) || empty($c_phone) || empty($g_name) || empty($g_phone)) {
        echo json_encode(['success' => false, 'message' => 'All fields are required.']);
        exit();
    }

    try {
        $stmt = $pdo->prepare("UPDATE clients SET phone = ?, guarantor_name = ?, guarantor_phone = ? WHERE id = ? AND officer_username = ?");
        if ($stmt->execute([$c_phone, $g_name, $g_phone, $client_id, $current_username])) {
            $sel = $pdo->prepare("SELECT id, phone, guarantor_name, guarantor_phone FROM clients WHERE id = ?");
            $sel->execute([$client_id]);
            $updated_client = $sel->fetch(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'message' => 'Details updated successfully', 'client' => $updated_client]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Update failed.']);
        }
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error.']);
    }
    exit();
}

// 2. Get Data (Refresh/Search/Sort)
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_clients_data') {
    header('Content-Type: application/json');
    
    $page = max(1, intval($_GET['page'] ?? 1));
    $per_page = 10;
    $offset = ($page - 1) * $per_page;
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $search_term = "%$search%";
    
    // Set default view to 'all_active'
    $loan_status = $_GET['loan_status'] ?? 'all_active';

    // Sorting Logic
    $sort_param = $_GET['sort'] ?? 'name_asc';
    $sort_map =[
        'name_asc'     => 'c.name ASC',
        'savings_desc' => 'savings DESC, c.name ASC',
        'loan_desc'    => 'outstanding_loan DESC, c.name ASC'
    ];
    $order_by = $sort_map[$sort_param] ?? 'c.name ASC';

    // Filter Logic
    $having_clause = '';
    if ($loan_status === 'all_active') {
        $having_clause = "HAVING outstanding_loan > 0 OR savings > 0";
    } elseif ($loan_status === 'active_loan') {
        $having_clause = "HAVING outstanding_loan > 0";
    } elseif ($loan_status === 'active_savings') {
        $having_clause = "HAVING savings > 0 AND (outstanding_loan IS NULL OR outstanding_loan = 0)";
    } else { // inactive
        $having_clause = "HAVING (outstanding_loan IS NULL OR outstanding_loan = 0) AND EXISTS (SELECT 1 FROM disbursements d2 WHERE d2.client_id = c.id)";
    }

    try {
        // 1. Calculate Summaries
        $stmt = $pdo->prepare("SELECT COUNT(id) FROM clients WHERE officer_username = ? AND status = 'active' AND (name LIKE ? OR `union` LIKE ?)");
        $stmt->execute([$current_username, $search_term, $search_term]);
        $total_clients = $stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT SUM(sb.balance) 
                                FROM saving_balances sb 
                                WHERE sb.client_id IN (
                                    SELECT id FROM clients 
                                    WHERE officer_username = ? AND status = 'active' AND (name LIKE ? OR `union` LIKE ?)
                                )");
        $stmt->execute([$current_username, $search_term, $search_term]);
        $total_savings = floatval($stmt->fetchColumn() ?? 0);

        $stmt = $pdo->prepare("SELECT SUM(remaining_balance) FROM disbursements WHERE officer = ? AND remaining_balance > 0");
        $stmt->execute([$current_username]);
        $total_loans = floatval($stmt->fetchColumn() ?? 0);

        // 2. Fetch Paginated Client List
        $sql = "SELECT 
                    c.id, 
                    c.name, 
                    c.phone,
                    c.union, 
                    c.guarantor_name, 
                    c.guarantor_phone,
                    (SELECT balance FROM saving_balances WHERE client_id = c.id LIMIT 1) as savings,
                    (SELECT SUM(remaining_balance) FROM disbursements d WHERE d.client_id = c.id AND d.remaining_balance > 0) as outstanding_loan
                FROM clients c
                WHERE c.officer_username = :officer 
                  AND c.status = 'active' 
                  AND (c.name LIKE :search OR c.`union` LIKE :search2)
                $having_clause
                ORDER BY $order_by
                LIMIT :limit OFFSET :offset";
        
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':officer', $current_username);
        $stmt->bindValue(':search', $search_term);
        $stmt->bindValue(':search2', $search_term);
        $stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $display_data =[];
        foreach ($results as $row) {
            $row['savings'] = floatval($row['savings'] ?? 0);
            $row['outstanding_loan'] = floatval($row['outstanding_loan'] ?? 0);
            $row['phone'] = $row['phone'] ?? 'N/A';
            $row['guarantor_name'] = $row['guarantor_name'] ?? 'N/A';
            $row['guarantor_phone'] = $row['guarantor_phone'] ?? 'N/A';
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

// 3. Get History - Tabular & Timeline Data
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_client_history') {
    header('Content-Type: application/json');
    $client_id = $_GET['id'] ?? '';
    
    try {
        $stmt = $pdo->prepare("
            SELECT 
                c.name,
                (SELECT balance FROM saving_balances WHERE client_id = c.id LIMIT 1) as savings_bal,
                (SELECT SUM(remaining_balance) FROM disbursements d WHERE d.client_id = c.id AND d.remaining_balance > 0) as loan_bal
            FROM clients c 
            WHERE c.id = ? AND c.officer_username = ?
        ");
        $stmt->execute([$client_id, $current_username]);
        $client = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if(!$client) { 
            echo json_encode(['success'=>false, 'message'=>'Client not found or unauthorized']); 
            exit; 
        }
        $client_name = $client['name'];
        $savings_bal = floatval($client['savings_bal'] ?? 0);
        $loan_bal = floatval($client['loan_bal'] ?? 0);

        $timeline =[];

        // Savings (Limit 40)
        $stmt = $pdo->prepare("SELECT transaction_id, amount, date, type FROM saving_collections WHERE client_id = ? ORDER BY date DESC LIMIT 40");
        $stmt->execute([$client_id]);
        while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $amount = floatval($row['amount']);
            $type = strtolower($row['type'] ?? 'deposit');
            
            $is_withdrawal = ($amount < 0) || in_array($type,['withdrawal', 'return_cash', 'return cash', 'debit', 'charge']);
            $display_amount = abs($amount);
            
            $description = 'Deposit';
            if (strpos($type, 'return') !== false || $type === 'return_cash') {
                $description = 'Return Cash';
            } elseif ($type === 'withdrawal' || $amount < 0) {
                $description = 'Withdrawal';
            } elseif ($type === 'transfer') {
                $description = 'Transfer';
            } elseif ($type === 'charge' || $type === 'fee') {
                $description = 'Fee/Charges';
            }
            
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

        // Disbursements (Limit 20)
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

        // Repayments (Limit 40)
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

$stmt = $pdo->prepare("SELECT full_name, profile_pic FROM users WHERE username = ?");
$stmt->execute([$current_username]);
$u_row = $stmt->fetch(PDO::FETCH_ASSOC);
$profile_pic = $u_row['profile_pic'] ?? 'default_avatar.png';
$base_path = '../';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#3b82f6">
    <title>My Clients | CUPAD</title>
    
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
            --primary-color: #3b82f6; --success-color: #22c55e; --warning-color: #f59e0b; --error-color: #ef4444;
            --bg-primary: #f4f6f8; --bg-secondary: #ffffff; --bg-card: #ffffff;
            --text-primary: #1f2937; --text-secondary: #6b7280; --border-color: #e5e7eb;
            --shadow-sm: 0 1px 3px 0 rgba(0,0,0,0.05), 0 1px 2px -1px rgba(0,0,0,0.05);
            --shadow-md: 0 4px 6px -1px rgba(0,0,0,0.05), 0 2px 4px -2px rgba(0,0,0,0.05);
            --border-radius-lg: 1.25rem;
        }
        html.dark {
            --bg-primary: #0f172a; --bg-secondary: #1e293b; --bg-card: #1e293b;
            --text-primary: #f8fafc; --text-secondary: #94a3b8; --border-color: rgba(255, 255, 255, 0.08);
            --shadow-sm: 0 1px 3px 0 rgba(0,0,0,0.2); --shadow-md: 0 4px 6px -1px rgba(0,0,0,0.3);
        }
        
        body { font-family: 'Inter', sans-serif; background-color: var(--bg-primary); color: var(--text-primary); padding-bottom: 90px; -webkit-tap-highlight-color: transparent; scroll-behavior: smooth; }
        
        /* SCROLLBAR STYLING */
        ::-webkit-scrollbar { width: 5px; height: 5px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
        html.dark ::-webkit-scrollbar-thumb { background: #475569; }
        ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
        .hide-scrollbar::-webkit-scrollbar { display: none; }
        .hide-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }

        /* HEADER & DASHBOARD CARDS */
        .main-header { background: rgba(255, 255, 255, 0.90); backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px); border-bottom: 1px solid var(--border-color); position: sticky; top: 0; z-index: 50; padding-top: env(safe-area-inset-top); transition: background-color 0.3s; }
        html.dark .main-header { background: rgba(15, 23, 42, 0.90); }
        .navbar { display: flex; justify-content: space-between; align-items: center; padding: 0.8rem 1rem; max-width: 1280px; margin: 0 auto; }
        .dashboard-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1.25rem; margin-bottom: 1.5rem; }
        .dashboard-card { border-radius: 1.5rem; padding: 1.5rem; position: relative; overflow: hidden; box-shadow: var(--shadow-md); color: white; transition: transform 0.2s ease, box-shadow 0.2s ease; border: none; }
        .dashboard-card:hover { transform: translateY(-2px); box-shadow: 0 10px 20px -5px rgba(0, 0, 0, 0.15); }
        .dashboard-card:active { transform: scale(0.98); }
        .card-gradient-blue { background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); }
        .card-gradient-green { background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%); }
        .card-gradient-red { background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); }
        .card-bg-icon { position: absolute; right: -10px; bottom: -20px; font-size: 6rem; color: white; opacity: 0.1; transform: rotate(-10deg); pointer-events: none; }

        /* SEARCH & FILTER AREA */
        .controls-container { display: flex; flex-direction: column; gap: 0.75rem; margin-bottom: 1.5rem; }
        @media(min-width: 768px) { .controls-container { flex-direction: row; } }
        .search-container { background: var(--bg-card); border-radius: var(--border-radius-lg); padding: 0.25rem; border: 1px solid var(--border-color); flex-grow: 1; transition: all 0.2s; box-shadow: var(--shadow-sm); }
        .search-container:focus-within { border-color: var(--primary-color); box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15); }
        .input-group { position: relative; display: flex; align-items: center; }
        .search-input { width: 100%; padding: 0.75rem 1rem 0.75rem 2.75rem; border: none; border-radius: 1rem; background: transparent; color: var(--text-primary); font-size: 15px; outline: none; }
        .search-input::placeholder { color: #9ca3af; }
        .search-icon { position: absolute; left: 1rem; color: #9ca3af; font-size: 1.1rem; transition: color 0.2s; }
        .search-container:focus-within .search-icon { color: var(--primary-color); }
        
        .filter-controls { display: flex; gap: 0.5rem; }
        .custom-select { background: var(--bg-card); border: 1px solid var(--border-color); color: var(--text-primary); border-radius: var(--border-radius-lg); padding: 0.5rem 1rem; font-size: 0.9rem; font-weight: 600; outline: none; flex-grow: 1; cursor: pointer; transition: all 0.2s; box-shadow: var(--shadow-sm); appearance: none; background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e"); background-repeat: no-repeat; background-position: right 1rem center; background-size: 1em; padding-right: 2.5rem; }
        .custom-select:focus { border-color: var(--primary-color); box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15); }
        .refresh-btn { background: var(--bg-card); border: 1px solid var(--border-color); color: var(--text-secondary); border-radius: var(--border-radius-lg); width: 48px; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: all 0.2s; box-shadow: var(--shadow-sm); }
        .refresh-btn:hover { background: var(--bg-primary); color: var(--primary-color); }
        .refresh-btn:active { transform: scale(0.92); }
        .spin { animation: spin 0.8s cubic-bezier(0.4, 0, 0.2, 1) infinite; }
        @keyframes spin { 100% { transform: rotate(360deg); } }

        /* CLIENT CARDS */
        .client-list-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 1.25rem; }
        .client-card { background: var(--bg-card); border-radius: var(--border-radius-lg); border: 1px solid var(--border-color); padding: 1.25rem; box-shadow: var(--shadow-sm); display: flex; flex-direction: column; gap: 1rem; opacity: 0; transition: transform 0.2s, box-shadow 0.2s; animation: fadeInUp 0.4s ease forwards; }
        .client-card:hover { transform: translateY(-3px); box-shadow: var(--shadow-md); border-color: rgba(59, 130, 246, 0.3); }
        @keyframes fadeInUp { from { opacity: 0; transform: translateY(15px); } to { opacity: 1; transform: translateY(0); } }
        
        .client-card-header { display: flex; justify-content: space-between; align-items: flex-start; gap: 0.75rem; }
        .client-avatar-wrapper { position: relative; margin-top: 0.1rem; }
        .client-avatar { width: 44px; height: 44px; border-radius: 12px; background: linear-gradient(135deg, #eff6ff, #dbeafe); color: var(--primary-color); display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 1.1rem; flex-shrink: 0; border: 1px solid rgba(59,130,246,0.1); }
        html.dark .client-avatar { background: linear-gradient(135deg, #1e3a8a, #1e40af); color: #bfdbfe; border-color: rgba(59,130,246,0.2); }
        .status-dot { position: absolute; bottom: -2px; right: -2px; width: 14px; height: 14px; border-radius: 50%; border: 2px solid var(--bg-card); z-index: 2; }
        
        .client-info { flex: 1; min-width: 0; display: flex; flex-direction: column; justify-content: center; }
        .client-info h3 { font-size: 1.05rem; font-weight: 700; color: var(--text-primary); margin: 0; line-height: 1.3; letter-spacing: -0.01em; word-break: break-word; overflow-wrap: break-word; }
        .client-union-badge { font-size: 0.65rem; background: var(--bg-primary); padding: 3px 8px; border-radius: 12px; color: var(--text-secondary); font-weight: 600; display: inline-block; text-transform: uppercase; letter-spacing: 0.05em; border: 1px solid var(--border-color); white-space: nowrap; }
        
        .client-stats { display: flex; gap: 0.5rem; background: var(--bg-primary); border-radius: 12px; padding: 0.75rem; border: 1px solid var(--border-color); }
        .stat-box { flex: 1; text-align: center; display: flex; flex-direction: column; justify-content: center; }
        .stat-label { font-size: 0.65rem; color: var(--text-secondary); text-transform: uppercase; font-weight: 600; letter-spacing: 0.05em; margin-bottom: 2px; }
        .stat-val { font-size: 0.95rem; font-weight: 800; letter-spacing: -0.02em; }
        
        .client-actions { display: flex; gap: 0.75rem; margin-top: auto; }
        .action-btn { flex: 1; padding: 0.7rem; border-radius: 10px; font-size: 0.85rem; font-weight: 600; text-align: center; transition: all 0.2s; display: flex; align-items: center; justify-content: center; gap: 6px; cursor: pointer; }
        .btn-secondary { background: var(--bg-primary); color: var(--text-primary); border: 1px solid var(--border-color); }
        .btn-secondary:hover { background: #e2e8f0; border-color: #cbd5e1; }
        html.dark .btn-secondary:hover { background: #334155; border-color: #475569; }
        .btn-primary { background: var(--primary-color); color: white; border: none; box-shadow: 0 4px 6px -1px rgba(59, 130, 246, 0.3); }
        .btn-primary:hover { background: #2563eb; transform: translateY(-1px); box-shadow: 0 6px 8px -1px rgba(59, 130, 246, 0.4); }
        .btn-primary:active { transform: translateY(0); }

        /* SKELETON LOADER & ANIMATIONS */
        .skeleton { background: linear-gradient(90deg, var(--bg-primary) 25%, var(--border-color) 50%, var(--bg-primary) 75%); background-size: 200% 100%; animation: skeleton-loading 1.5s infinite ease-in-out; border-radius: 4px; }
        @keyframes skeleton-loading { 0% { background-position: 200% 0; } 100% { background-position: -200% 0; } }

        /* MODALS (BottomSheet on Mobile) */
        .modal-backdrop { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); backdrop-filter: blur(5px); z-index: 200; display: none; align-items: center; justify-content: center; opacity: 0; transition: opacity 0.3s ease; }
        .modal-backdrop.active { opacity: 1; }
        
        .modal-card { background: var(--bg-card); width: 92%; max-width: 480px; border-radius: 1.5rem; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.4); transform: scale(0.95) translateY(10px); transition: transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1); border: 1px solid var(--border-color); display: flex; flex-direction: column; max-height: 85vh; overflow: hidden; position: relative; }
        .modal-backdrop.active .modal-card { transform: scale(1) translateY(0); }
        
        /* Mobile specific bottom sheet */
        @media (max-width: 768px) {
            .modal-card { position: absolute; bottom: 0; width: 100%; max-width: none; border-radius: 1.5rem 1.5rem 0 0; transform: translateY(100%); height: 85vh; padding-top: 12px; }
            .modal-backdrop.active .modal-card { transform: translateY(0); }
            /* Drag handle indicator */
            .modal-card::before { content: ''; position: absolute; top: 12px; left: 50%; transform: translateX(-50%); width: 40px; height: 4px; background: var(--border-color); border-radius: 4px; z-index: 20; }
            .dashboard-grid { display: flex; overflow-x: auto; scroll-snap-type: x mandatory; padding-bottom: 0.5rem; margin-right: -1rem; padding-right: 1.5rem; scrollbar-width: none; }
            .dashboard-card { min-width: 85vw; scroll-snap-align: center; flex-shrink: 0; }
        }
        
        /* BOTTOM NAV */
        .mobile-bottom-nav { display: none; position: fixed; bottom: 0; left: 0; right: 0; background: rgba(255, 255, 255, 0.95); backdrop-filter: blur(10px); border-top: 1px solid var(--border-color); padding: 0.5rem 1rem calc(0.5rem + env(safe-area-inset-bottom)); z-index: 100; justify-content: space-between; box-shadow: 0 -4px 6px -1px rgba(0,0,0,0.05); }
        html.dark .mobile-bottom-nav { background: rgba(30, 41, 59, 0.95); }
        @media (max-width: 768px) { .mobile-bottom-nav { display: flex; } }
        .nav-item { display: flex; flex-direction: column; align-items: center; text-decoration: none; color: var(--text-secondary); font-size: 0.7rem; font-weight: 600; flex: 1; gap: 4px; transition: color 0.2s; }
        .nav-item.active { color: var(--primary-color); }
        
        .toast { position: fixed; top: 1rem; left: 50%; transform: translateX(-50%); z-index: 999; width: 90%; max-width: 350px; background: var(--bg-secondary); border-left: 4px solid; padding: 1rem; border-radius: 12px; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.15); display: flex; align-items: center; gap: 12px; animation: slideDown 0.4s cubic-bezier(0.34, 1.56, 0.64, 1) forwards; }
        .toast.fade-out { animation: slideUp 0.3s ease-in forwards; }
        @keyframes slideDown { from { transform: translateY(-30px) translateX(-50%); opacity: 0; } to { transform: translateY(0) translateX(-50%); opacity: 1; } }
        @keyframes slideUp { from { transform: translateY(0) translateX(-50%); opacity: 1; } to { transform: translateY(-30px) translateX(-50%); opacity: 0; } }
    </style>
</head>
<body>

    <!-- HEADER -->
    <header class="main-header">
        <nav class="navbar">
            <a href="dashboard.php" class="flex items-center gap-2 no-underline group">
                <img src="<?php echo htmlspecialchars($base_path . 'favicon.ico'); ?>" alt="Logo" class="h-8 group-hover:scale-105 transition-transform duration-300">
                <span class="text-xl font-extrabold text-blue-600 tracking-tight">CUPAD</span>
            </a>
            <div class="flex items-center gap-4">
                <button id="theme-toggle" class="w-10 h-10 rounded-full flex items-center justify-center bg-gray-100 dark:bg-slate-800 text-gray-500 dark:text-gray-400 hover:bg-gray-200 dark:hover:bg-slate-700 transition-colors">
                    <i class="fas fa-moon dark:hidden text-lg"></i>
                    <i class="fas fa-sun hidden dark:inline text-lg"></i>
                </button>
                
                <div class="relative w-10 h-10 border-2 border-blue-500 rounded-full overflow-hidden bg-blue-100 dark:bg-slate-800 flex items-center justify-center cursor-pointer shadow-sm hover:shadow-md transition-shadow" onclick="window.location.href='profile.php'">
                    <i class="fas fa-user text-blue-500/50 dark:text-blue-400/50 absolute text-lg"></i>
                    <img src="<?php echo htmlspecialchars($base_path . $profile_pic); ?>" alt="Profile" class="w-full h-full object-cover relative z-10" onerror="this.style.opacity='0'">
                </div>
                
                <a href="<?php echo htmlspecialchars($base_path . 'logout.php'); ?>" class="w-10 h-10 rounded-full flex items-center justify-center text-red-500 bg-red-50 dark:bg-red-900/20 hover:bg-red-100 dark:hover:bg-red-900/40 transition-colors">
                    <i class="fas fa-sign-out-alt"></i>
                </a>
            </div>
        </nav>
    </header>

    <main class="max-w-7xl mx-auto px-4 py-6">
        <div class="mb-6">
            <h1 class="text-2xl font-extrabold text-gray-900 dark:text-white tracking-tight">Clients Portfolio</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Manage client profiles, savings, and loan histories.</p>
        </div>

        <!-- STATS -->
        <div class="dashboard-grid">
            <div class="dashboard-card card-gradient-blue">
                <i class="fas fa-users card-bg-icon"></i>
                <div class="relative z-10">
                    <div class="text-xs font-bold uppercase tracking-wider opacity-90 mb-1">Total Clients</div>
                    <div class="text-3xl font-extrabold" id="totalClients">-</div>
                </div>
            </div>
            <div class="dashboard-card card-gradient-green">
                <i class="fas fa-wallet card-bg-icon"></i>
                <div class="relative z-10">
                    <div class="text-xs font-bold uppercase tracking-wider opacity-90 mb-1">Total Savings</div>
                    <div class="text-3xl font-extrabold tracking-tight" id="totalSavings">-</div>
                </div>
            </div>
            <div class="dashboard-card card-gradient-red">
                <i class="fas fa-hand-holding-usd card-bg-icon"></i>
                <div class="relative z-10">
                    <div class="text-xs font-bold uppercase tracking-wider opacity-90 mb-1">Outstanding Loans</div>
                    <div class="text-3xl font-extrabold tracking-tight" id="totalLoans">-</div>
                </div>
            </div>
        </div>

        <!-- SEARCH & FILTER -->
        <div class="controls-container">
            <div class="search-container">
                <div class="input-group">
                    <i class="fas fa-search search-icon"></i>
                    <input type="text" id="searchInput" class="search-input" placeholder="Search by Name or Union...">
                </div>
            </div>
            <div class="filter-controls">
                <select id="loanStatusFilter" class="custom-select" onchange="resetAndLoad()">
                    <option value="all_active" selected>All Active (Loans or Savings)</option>
                    <option value="active_loan">Has Active Loan</option>
                    <option value="active_savings">Active Savings (No Loan)</option>
                    <option value="inactive">Paid Off Loans</option>
                </select>
                <select id="sortSelect" class="custom-select" onchange="resetAndLoad()">
                    <option value="name_asc">Name (A-Z)</option>
                    <option value="savings_desc">Highest Savings</option>
                    <option value="loan_desc">Highest Loan</option>
                </select>
                <button class="refresh-btn" id="refreshBtn" onclick="forceRefresh()" title="Refresh Data">
                    <i class="fas fa-sync-alt"></i>
                </button>
            </div>
        </div>

        <!-- CLIENT LIST -->
        <div id="clientList" class="client-list-grid">
            <!-- Populated via JS -->
        </div>
        
        <div id="loadMoreContainer" class="text-center mt-8 hidden pb-8">
            <button id="loadMoreBtn" class="px-8 py-3 bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-full text-sm font-bold text-gray-700 dark:text-gray-300 shadow-sm hover:shadow-md hover:-translate-y-0.5 transition-all duration-200" onclick="loadClients(true)">
                Load More Clients
            </button>
        </div>
    </main>

    <!-- MOBILE BOTTOM NAV -->
    <nav class="mobile-bottom-nav">
        <a href="dashboard.php" class="nav-item"><i class="fas fa-home text-xl mb-1"></i><span>Home</span></a>
        <a href="saving_collection.php" class="nav-item"><i class="fas fa-piggy-bank text-xl mb-1"></i><span>Save</span></a>
        <a href="clients.php" class="nav-item active">
             <div class="w-12 h-12 bg-blue-600 rounded-full flex items-center justify-center text-white -mt-6 shadow-lg shadow-blue-500/40 ring-4 ring-white dark:ring-slate-900 transition-transform active:scale-95">
                <i class="fas fa-users text-lg"></i>
             </div>
             <span class="mt-1">Clients</span>
        </a>
        <a href="disbursement.php" class="nav-item"><i class="fas fa-hand-holding-usd text-xl mb-1"></i><span>Disburse</span></a>
        <a href="loan_collection.php" class="nav-item"><i class="fas fa-money-bill-wave text-xl mb-1"></i><span>Repay</span></a>
    </nav>

    <!-- EDIT MODAL -->
    <div id="editModal" class="modal-backdrop">
        <div class="modal-card">
            <div class="px-6 py-5 border-b border-gray-100 dark:border-slate-800 flex justify-between items-center bg-white dark:bg-slate-900 z-10 md:rounded-t-3xl">
                <div>
                    <span class="font-extrabold text-lg text-gray-900 dark:text-white">Edit Client Details</span>
                    <div class="text-xs text-gray-500 font-medium mt-0.5">Update personal & security info</div>
                </div>
                <button type="button" class="w-9 h-9 rounded-full bg-gray-100 dark:bg-slate-800 flex items-center justify-center text-gray-500 hover:bg-gray-200 dark:hover:bg-slate-700 hover:text-gray-800 dark:hover:text-white transition-all" onclick="closeModal('editModal')"><i class="fas fa-times"></i></button>
            </div>
            <form id="editForm" class="flex flex-col flex-1 overflow-hidden">
                <div class="p-6 overflow-y-auto">
                    <input type="hidden" id="editClientId" name="client_id">
                    
                    <div class="mb-5">
                        <label class="block text-[11px] font-bold text-gray-500 mb-1.5 uppercase tracking-wider">Client Name</label>
                        <input type="text" id="editClientName" class="w-full p-3.5 bg-gray-50 dark:bg-slate-800/50 border border-gray-200 dark:border-slate-700 rounded-xl text-gray-600 dark:text-gray-400 font-bold cursor-not-allowed outline-none" disabled>
                    </div>

                    <div class="mb-5 relative group">
                        <label class="block text-[11px] font-bold text-gray-500 mb-1.5 uppercase tracking-wider">Client Phone</label>
                        <div class="relative">
                            <i class="fas fa-phone-alt absolute left-4 top-1/2 -translate-y-1/2 text-gray-400 group-focus-within:text-blue-500 transition-colors"></i>
                            <input type="tel" id="editClientPhone" name="client_phone" pattern="[0-9+\- ]+" class="w-full pl-11 pr-4 py-3.5 bg-white dark:bg-slate-900 border border-gray-200 dark:border-slate-700 rounded-xl focus:ring-2 focus:ring-blue-500/50 focus:border-blue-500 outline-none font-medium transition-all font-mono" required placeholder="080XXXXXXXX">
                        </div>
                    </div>
                    
                    <div class="mb-5 relative group">
                        <label class="block text-[11px] font-bold text-gray-500 mb-1.5 uppercase tracking-wider">Guarantor Name</label>
                        <div class="relative">
                            <i class="fas fa-user-shield absolute left-4 top-1/2 -translate-y-1/2 text-gray-400 group-focus-within:text-blue-500 transition-colors"></i>
                            <input type="text" id="editGuarantorName" name="guarantor_name" class="w-full pl-11 pr-4 py-3.5 bg-white dark:bg-slate-900 border border-gray-200 dark:border-slate-700 rounded-xl focus:ring-2 focus:ring-blue-500/50 focus:border-blue-500 outline-none font-medium transition-all" required placeholder="Enter full name">
                        </div>
                    </div>
                    
                    <div class="mb-2 relative group">
                        <label class="block text-[11px] font-bold text-gray-500 mb-1.5 uppercase tracking-wider">Guarantor Phone</label>
                        <div class="relative">
                            <i class="fas fa-phone-alt absolute left-4 top-1/2 -translate-y-1/2 text-gray-400 group-focus-within:text-blue-500 transition-colors"></i>
                            <input type="tel" id="editGuarantorPhone" name="guarantor_phone" pattern="[0-9+\- ]+" class="w-full pl-11 pr-4 py-3.5 bg-white dark:bg-slate-900 border border-gray-200 dark:border-slate-700 rounded-xl focus:ring-2 focus:ring-blue-500/50 focus:border-blue-500 outline-none font-medium transition-all font-mono" required placeholder="080XXXXXXXX">
                        </div>
                    </div>
                </div>
                <div class="p-5 border-t border-gray-100 dark:border-slate-800 flex gap-3 bg-gray-50/80 dark:bg-slate-900/80">
                    <button type="button" class="flex-1 py-3.5 rounded-xl border border-gray-300 dark:border-slate-600 text-gray-700 dark:text-gray-200 font-bold hover:bg-gray-100 dark:hover:bg-slate-800 transition-colors" onclick="closeModal('editModal')">Cancel</button>
                    <button type="submit" class="flex-1 py-3.5 rounded-xl bg-blue-600 hover:bg-blue-700 text-white font-bold shadow-lg shadow-blue-500/30 transition-all hover:-translate-y-0.5">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <!-- HISTORY MODAL -->
    <div id="historyModal" class="modal-backdrop">
        <div class="modal-card">
            <div class="px-6 py-5 border-b border-gray-100 dark:border-slate-800 flex justify-between items-start bg-white dark:bg-slate-900 z-10 shrink-0 md:rounded-t-3xl">
                <div class="w-full pr-4 overflow-hidden flex-1">
                    <h3 class="font-extrabold text-xl text-gray-900 dark:text-white break-words leading-tight" id="historyModalTitle">History</h3>
                    <div id="historyModalSubtitle" class="mt-2"></div>
                </div>
                <button class="w-9 h-9 shrink-0 rounded-full bg-gray-100 dark:bg-slate-800 flex items-center justify-center text-gray-500 hover:bg-gray-200 dark:hover:bg-slate-700 transition-colors" onclick="closeModal('historyModal')">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <div id="historyFilters" class="flex w-full border-b border-gray-100 dark:border-slate-800 bg-white dark:bg-slate-900 z-10 shrink-0" style="display:none;">
                <button class="flex-1 py-3.5 text-[13px] font-extrabold text-center border-b-2 text-blue-600 border-blue-600 history-tab transition-colors focus:outline-none uppercase tracking-wider" onclick="filterHistory('all', this)">All</button>
                <button class="flex-1 py-3.5 text-[13px] font-bold text-center border-b-2 border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 history-tab transition-colors focus:outline-none uppercase tracking-wider" onclick="filterHistory('savings', this)">Save</button>
                <button class="flex-1 py-3.5 text-[13px] font-bold text-center border-b-2 border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 history-tab transition-colors focus:outline-none uppercase tracking-wider" onclick="filterHistory('loan', this)">Loan</button>
                <button class="flex-1 py-3.5 text-[13px] font-bold text-center border-b-2 border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 history-tab transition-colors focus:outline-none uppercase tracking-wider" onclick="filterHistory('repayment', this)">Repay</button>
            </div>

            <div class="p-4 overflow-y-auto flex-1 bg-gray-50/50 dark:bg-slate-900/50 relative" id="historyModalBody"></div>
        </div>
    </div>

    <script>
        let currentPage = 1;
        let isLoading = false;
        let currentClientHistory = [];
        
        const $ = id => document.getElementById(id);
        const formatMoney = num => '₦' + Number(num).toLocaleString('en-US');

        // Helper to generate a quick WhatsApp link
        const generateWaLink = (phone) => {
            if(!phone || phone === 'N/A') return '';
            let p = phone.replace(/\D/g, '');
            // Convert standard 080... to 23480...
            if(p.startsWith('0')) p = '234' + p.substring(1);
            if(p.length < 10) return ''; // Invalid length
            return `<a href="https://wa.me/${p}" target="_blank" class="w-6 h-6 rounded-full bg-green-50 dark:bg-green-900/20 text-green-500 flex items-center justify-center hover:bg-green-100 dark:hover:bg-green-900/40 transition-colors shadow-sm ml-1" onclick="event.stopPropagation();" title="Message on WhatsApp"><i class="fab fa-whatsapp text-[13px]"></i></a>`;
        };

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
            setTimeout(() => {
                el.classList.add('fade-out');
                setTimeout(() => el.remove(), 300);
            }, 3000);
        }

        function closeModal(id) { 
            $(id).classList.remove('active'); 
            setTimeout(() => $(id).style.display = 'none', 300); 
        }
        function openModal(id) { 
            $(id).style.display = 'flex'; 
            setTimeout(() => $(id).classList.add('active'), 10); 
        }

        function renderSkeleton() {
            let html = '';
            for(let i=0; i<6; i++) {
                html += `
                <div class="bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-[1.25rem] p-5 shadow-sm">
                    <div class="flex justify-between items-start mb-4">
                        <div class="flex gap-3 w-full">
                            <div class="skeleton w-11 h-11 rounded-xl"></div>
                            <div class="flex-1">
                                <div class="skeleton h-5 w-3/4 mb-2 rounded"></div>
                                <div class="skeleton h-4 w-1/2 rounded-full"></div>
                            </div>
                        </div>
                    </div>
                    <div class="skeleton h-[72px] w-full rounded-xl mb-3"></div>
                    <div class="skeleton h-12 w-full rounded-lg mb-3"></div>
                    <div class="flex gap-3">
                        <div class="skeleton h-10 flex-1 rounded-xl"></div>
                        <div class="skeleton h-10 flex-1 rounded-xl"></div>
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
            const loanStatus = $('loanStatusFilter').value;
            const page = append ? currentPage + 1 : 1;
            
            if(!append) renderSkeleton();
            else $('loadMoreBtn').innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Loading...';

            try {
                const res = await fetch(`?ajax=get_clients_data&page=${page}&search=${encodeURIComponent(search)}&sort=${sort}&loan_status=${loanStatus}`);
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
                            <div class="col-span-full text-center py-20 text-gray-500 dark:text-gray-400 flex flex-col items-center">
                                <div class="w-20 h-20 bg-gray-100 dark:bg-slate-800 rounded-full flex items-center justify-center mb-4 shadow-inner"><i class="fas fa-search text-3xl opacity-50"></i></div>
                                <p class="text-lg font-bold text-gray-700 dark:text-gray-300">No clients found</p>
                                <p class="text-sm mt-1">Try adjusting your search or filters.</p>
                            </div>`;
                    } else {
                        data.clients.forEach((c, index) => {
                            const delay = (index % 10) * 0.05; 
                            const initials = c.name.split(' ').map(n=>n[0]).join('').substring(0,2).toUpperCase();
                            
                            // Links & Integrations
                            const cWaLink = generateWaLink(c.phone);
                            const gWaLink = generateWaLink(c.guarantor_phone);

                            const clientPhoneLink = c.phone !== 'N/A' 
                                ? `<a href="tel:${c.phone}" class="font-mono text-gray-500 hover:text-blue-600 dark:text-gray-400 dark:hover:text-blue-400 hover:underline flex items-center gap-1.5 text-[11px]"><i class="fas fa-phone-alt opacity-70"></i> ${c.phone}</a>`
                                : `<span class="font-mono text-gray-400 text-[11px] flex items-center gap-1.5"><i class="fas fa-phone-alt opacity-50"></i> N/A</span>`;

                            const guarantorPhoneLink = c.guarantor_phone !== 'N/A' 
                                ? `<a href="tel:${c.guarantor_phone}" class="font-mono text-blue-600 dark:text-blue-400 hover:underline flex items-center gap-1.5"><i class="fas fa-phone-alt text-[10px] opacity-70"></i> ${c.guarantor_phone}</a>`
                                : `<span class="font-mono text-gray-400 flex items-center gap-1.5"><i class="fas fa-phone-alt text-[10px] opacity-50"></i> N/A</span>`;

                            // Status Indicator Color
                            let statusColor = '#22c55e'; // Green (Only savings)
                            if(c.outstanding_loan > 0) {
                                if(c.outstanding_loan > c.savings) statusColor = '#ef4444'; // Red
                                else statusColor = '#f59e0b'; // Orange
                            }

                            const card = document.createElement('div');
                            card.className = 'client-card';
                            card.style.animationDelay = `${delay}s`;
                            card.setAttribute('data-id', c.id);
                            
                            card.innerHTML = `
                                <div class="client-card-header">
                                    <div class="flex gap-3 items-start flex-1 min-w-0 pr-2">
                                        <div class="client-avatar-wrapper mt-0.5">
                                            <div class="client-avatar">${initials}</div>
                                            <div class="status-dot" style="background-color: ${statusColor}; border-color: var(--bg-card);"></div>
                                        </div>
                                        <div class="client-info">
                                            <h3 title="${c.name}">${c.name}</h3>
                                            <div class="flex items-center gap-2 mt-1.5 flex-wrap">
                                                <span class="client-union-badge">${c.union}</span>
                                                ${clientPhoneLink}
                                                ${cWaLink}
                                            </div>
                                        </div>
                                    </div>
                                    <button class="shrink-0 w-9 h-9 rounded-full bg-blue-50 dark:bg-slate-800 text-blue-500 dark:text-blue-400 flex items-center justify-center hover:bg-blue-100 dark:hover:bg-slate-700 transition-colors shadow-sm" onclick="viewHistory('${c.id}')" title="View History">
                                        <i class="fas fa-history text-sm"></i>
                                    </button>
                                </div>
                                
                                <div class="client-stats mt-2">
                                    <div class="stat-box">
                                        <span class="stat-label">Savings</span>
                                        <span class="stat-val text-green-600 dark:text-green-400">${formatMoney(c.savings)}</span>
                                    </div>
                                    <div style="width:1px; background:var(--border-color); margin: 0.25rem 0;"></div>
                                    <div class="stat-box">
                                        <span class="stat-label">Loan Bal</span>
                                        <span class="stat-val ${c.outstanding_loan > 0 ? 'text-red-500 dark:text-red-400' : 'text-gray-400'}">${formatMoney(c.outstanding_loan)}</span>
                                    </div>
                                </div>

                                <div class="text-xs bg-gray-50/80 dark:bg-slate-800/50 p-3 rounded-xl border border-gray-100 dark:border-slate-700 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-2 transition-colors hover:bg-gray-100 dark:hover:bg-slate-800">
                                    <span class="flex items-start gap-2 max-w-full">
                                        <i class="fas fa-user-shield text-gray-400 mt-0.5 shrink-0"></i> 
                                        <span class="font-semibold text-gray-700 dark:text-gray-300 break-words leading-tight">${c.guarantor_name}</span>
                                    </span>
                                    <div class="flex items-center gap-2 shrink-0 self-end sm:self-auto">
                                        ${guarantorPhoneLink}
                                        ${gWaLink}
                                    </div>
                                </div>

                                <div class="client-actions">
                                    <button class="action-btn btn-secondary" 
                                        data-cphone="${c.phone}" 
                                        data-gname="${c.guarantor_name}" 
                                        data-gphone="${c.guarantor_phone}" 
                                        onclick="editClientDetails('${c.id}', '${c.name.replace(/'/g, "\\'")}', this)">
                                        <i class="fas fa-pen text-gray-500 dark:text-gray-400"></i> Edit
                                    </button>
                                    <button class="action-btn btn-primary" onclick="window.location.href='saving_collection.php'"><i class="fas fa-plus"></i> Transact</button>
                                </div>
                            `;
                            $('clientList').appendChild(card);
                        });
                    }
                    currentPage = page;
                    $('loadMoreContainer').style.display = data.pagination.has_more ? 'block' : 'none';
                }
            } catch(e) {
                showToast('Failed to load data', 'error');
            } finally {
                isLoading = false;
                if(append) $('loadMoreBtn').innerHTML = 'Load More Clients';
            }
        }

        // Search & Refresh Events
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

        // Edit Flow
        function editClientDetails(id, name, btnEl) {
            const cPhone = btnEl.getAttribute('data-cphone');
            const gName = btnEl.getAttribute('data-gname');
            const gPhone = btnEl.getAttribute('data-gphone');

            $('editClientId').value = id;
            $('editClientName').value = name;
            $('editClientPhone').value = cPhone === 'N/A' ? '' : cPhone;
            $('editGuarantorName').value = gName === 'N/A' ? '' : gName;
            $('editGuarantorPhone').value = gPhone === 'N/A' ? '' : gPhone;

            openModal('editModal');
            setTimeout(() => $('editClientPhone').focus(), 100);
        }

        $('editForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            const btn = this.querySelector('button[type="submit"]');
            const originalText = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Saving...';
            btn.disabled = true;

            try {
                const fd = new FormData(this);
                fd.append('ajax', 'update_client_details');
                const res = await fetch('', { method: 'POST', body: fd });
                const data = await res.json();

                if(data.success) {
                    showToast('Client details updated securely.', 'success');
                    closeModal('editModal');
                    forceRefresh(); 
                } else {
                    showToast(data.message, 'error');
                }
            } catch(err) { showToast('Network error. Try again.', 'error'); } 
            finally { btn.innerHTML = originalText; btn.disabled = false; }
        });

        // History Flow
        async function viewHistory(id) {
            openModal('historyModal');
            $('historyFilters').style.display = 'none';
            $('historyModalTitle').textContent = 'Loading Details...';
            $('historyModalSubtitle').innerHTML = '<div class="skeleton h-6 w-48 rounded-md mt-1"></div>';
            
            let skeletonHtml = '';
            for(let i=0; i<4; i++) {
                skeletonHtml += `
                <div class="flex items-center justify-between p-4 bg-white dark:bg-slate-800 rounded-2xl mb-3 shadow-sm border border-gray-100 dark:border-slate-700/50">
                    <div class="flex items-center gap-4 w-full">
                        <div class="skeleton w-12 h-12 rounded-2xl flex-shrink-0"></div>
                        <div class="flex-1">
                            <div class="skeleton h-4 w-2/3 mb-2 rounded"></div>
                            <div class="skeleton h-3 w-1/3 rounded"></div>
                        </div>
                        <div class="flex flex-col items-end gap-2 w-24">
                            <div class="skeleton h-4 w-full rounded"></div>
                            <div class="skeleton h-3 w-2/3 rounded"></div>
                        </div>
                    </div>
                </div>`;
            }
            $('historyModalBody').innerHTML = skeletonHtml;
            
            try {
                const res = await fetch(`?ajax=get_client_history&id=${id}`);
                const data = await res.json();
                
                if(data.success) {
                    $('historyModalTitle').textContent = data.client_name;
                    
                    $('historyModalSubtitle').innerHTML = `
                        <div class="flex gap-2.5 items-center flex-wrap">
                            <span class="text-[11px] font-bold bg-green-50 text-green-700 dark:bg-green-900/30 dark:text-green-400 px-2.5 py-1 rounded border border-green-100 dark:border-green-800/50 flex items-center gap-1.5">
                                <i class="fas fa-piggy-bank opacity-70"></i> ${formatMoney(data.summary.savings)}
                            </span>
                            <span class="text-[11px] font-bold bg-orange-50 text-orange-700 dark:bg-orange-900/30 dark:text-orange-400 px-2.5 py-1 rounded border border-orange-100 dark:border-orange-800/50 flex items-center gap-1.5">
                                <i class="fas fa-hand-holding-usd opacity-70"></i> ${formatMoney(data.summary.loan)}
                            </span>
                        </div>
                    `;

                    currentClientHistory = data.history;
                    
                    if(currentClientHistory.length > 0) {
                        $('historyFilters').style.display = 'flex';
                        document.querySelectorAll('.history-tab').forEach((btn, index) => {
                            if(index === 0) {
                                btn.className = 'flex-1 py-3.5 text-[13px] font-extrabold text-center border-b-2 text-blue-600 border-blue-600 history-tab transition-colors focus:outline-none uppercase tracking-wider';
                            } else {
                                btn.className = 'flex-1 py-3.5 text-[13px] font-bold text-center border-b-2 border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 history-tab transition-colors focus:outline-none uppercase tracking-wider';
                            }
                        });
                    }
                    renderHistory('all');
                } else {
                    $('historyModalBody').innerHTML = `<div class="text-center py-12 text-red-500 font-medium">${data.message}</div>`;
                }
            } catch(e) {
                $('historyModalBody').innerHTML = '<div class="text-center py-12 text-red-500 font-medium">Failed to load history.</div>';
            }
        }

        // Tab Switching Logic
        function filterHistory(type, btnElement) {
            document.querySelectorAll('.history-tab').forEach(btn => {
                btn.className = 'flex-1 py-3.5 text-[13px] font-bold text-center border-b-2 border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 history-tab transition-colors focus:outline-none uppercase tracking-wider';
            });
            btnElement.className = 'flex-1 py-3.5 text-[13px] font-extrabold text-center border-b-2 text-blue-600 border-blue-600 history-tab transition-colors focus:outline-none uppercase tracking-wider';
            renderHistory(type);
        }

        function renderHistory(filterType) {
            const filtered = filterType === 'all' ? currentClientHistory : currentClientHistory.filter(item => item.type === filterType);
            
            if(filtered.length === 0) {
                let emptyIcon = 'fa-receipt';
                let emptyText = 'No transactions found.';
                
                if(filterType === 'savings') { emptyIcon = 'fa-piggy-bank'; emptyText = 'No savings recorded yet.'; }
                else if(filterType === 'loan') { emptyIcon = 'fa-hand-holding-usd'; emptyText = 'No loans disbursed yet.'; }
                else if(filterType === 'repayment') { emptyIcon = 'fa-money-bill-wave'; emptyText = 'No loan repayments yet.'; }
                
                $('historyModalBody').innerHTML = `
                    <div class="text-center py-16 text-gray-400 flex flex-col items-center justify-center h-full">
                        <div class="w-16 h-16 rounded-full bg-gray-100 dark:bg-slate-800 flex items-center justify-center mb-4 shadow-inner"><i class="fas ${emptyIcon} text-2xl opacity-40"></i></div>
                        <p class="text-[14px] font-semibold text-gray-500">${emptyText}</p>
                    </div>`;
                return;
            }
            
            let html = '';
            let currentMonth = '';
            
            filtered.forEach((item, index) => {
                const dateObj = new Date(item.timestamp * 1000);
                const monthStr = dateObj.toLocaleString('en-US', { month: 'long', year: 'numeric' });
                
                if (monthStr !== currentMonth) {
                    html += `<div class="sticky top-0 bg-gray-50/95 dark:bg-slate-900/95 backdrop-blur-md z-10 py-2.5 px-1 mb-2 mt-4 first:mt-0 border-b border-gray-200 dark:border-slate-800/80">
                                <span class="text-[11px] font-extrabold text-gray-500 dark:text-gray-400 uppercase tracking-widest">${monthStr}</span>
                             </div>`;
                    currentMonth = monthStr;
                }

                const isWithdrawal = item.is_withdrawal || 
                                     item.description === 'Withdrawal' || 
                                     item.description === 'Return Cash' ||
                                     item.description === 'Fee/Charges' ||
                                     (item.raw_amount && item.raw_amount < 0);
                
                let badgeClass = '';
                let iconClass = '';
                let amountColor = '';
                let prefix = '';

                if(item.type === 'savings') {
                    if(isWithdrawal) {
                        badgeClass = 'text-red-600 bg-red-50 border-red-100 dark:border-red-900/30 dark:bg-red-900/20 dark:text-red-400';
                        iconClass = 'text-red-500 bg-red-50 dark:bg-red-900/20';
                        amountColor = 'text-red-600 dark:text-red-400';
                        prefix = '-';
                    } else {
                        badgeClass = 'text-green-600 bg-green-50 border-green-100 dark:border-green-900/30 dark:bg-green-900/20 dark:text-green-400';
                        iconClass = 'text-green-500 bg-green-50 dark:bg-green-900/20';
                        amountColor = 'text-green-600 dark:text-green-400';
                        prefix = '+';
                    }
                } else if(item.type === 'loan') {
                    badgeClass = 'text-orange-600 bg-orange-50 border-orange-100 dark:border-orange-900/30 dark:bg-orange-900/20 dark:text-orange-400';
                    iconClass = 'text-orange-500 bg-orange-50 dark:bg-orange-900/20';
                    amountColor = 'text-orange-600 dark:text-orange-400';
                    prefix = ''; 
                } else if(item.type === 'repayment') {
                    badgeClass = 'text-purple-600 bg-purple-50 border-purple-100 dark:border-purple-900/30 dark:bg-purple-900/20 dark:text-purple-400';
                    iconClass = 'text-purple-500 bg-purple-50 dark:bg-purple-900/20';
                    amountColor = 'text-purple-600 dark:text-purple-400';
                    prefix = '';
                }
                
                const shortDate = dateObj.toLocaleString('en-US', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
                const shortTxId = item.transaction_id.length > 10 ? item.transaction_id.substring(0, 10) + '...' : item.transaction_id;

                html += `
                    <div class="flex items-center justify-between p-4 bg-white dark:bg-slate-800 rounded-2xl mb-3 shadow-sm border border-gray-100 dark:border-slate-700/50 hover:shadow-md hover:border-blue-200 dark:hover:border-blue-500/30 transition-all duration-200 opacity-0 transform translate-y-2" style="animation: fadeInUp 0.4s ease forwards; animation-delay: ${Math.min(index * 0.05, 0.5)}s">
                        <div class="flex items-center gap-4 w-[65%]">
                            <div class="w-12 h-12 rounded-2xl flex items-center justify-center text-lg flex-shrink-0 ${iconClass}">
                                <i class="fas ${item.icon}"></i>
                            </div>
                            <div class="overflow-hidden flex flex-col gap-1">
                                <div class="font-bold text-[15px] text-gray-900 dark:text-gray-100 leading-none truncate">${item.title}</div>
                                <div class="flex items-center gap-1.5 flex-wrap">
                                    <span class="text-[12px] text-gray-500 dark:text-gray-400 font-medium whitespace-nowrap">${shortDate}</span>
                                </div>
                                <div>
                                    <span class="px-2 py-0.5 rounded border text-[9px] font-bold uppercase tracking-widest ${badgeClass}">${item.description}</span>
                                </div>
                            </div>
                        </div>
                        <div class="text-right flex flex-col items-end gap-1.5 flex-shrink-0">
                            <div class="font-extrabold text-[15px] ${amountColor} tracking-tight">
                                ${prefix}${item.amount}
                            </div>
                            <div class="flex items-center gap-1.5 bg-gray-50 dark:bg-slate-900/80 px-2.5 py-1 rounded border border-gray-200 dark:border-slate-700 cursor-pointer hover:bg-gray-200 dark:hover:bg-slate-700 transition-colors group" onclick="copyTx('${item.transaction_id}', this)" title="Copy ID">
                                <i class="far fa-copy text-[10px] text-gray-400 group-hover:text-blue-500 transition-colors"></i>
                                <span class="font-mono text-[10px] font-medium text-gray-500 group-hover:text-blue-500 transition-colors">${shortTxId}</span>
                            </div>
                        </div>
                    </div>`;
            });
            $('historyModalBody').innerHTML = html;
        }

        async function copyTx(txId, el) {
            if(event) event.stopPropagation();
            try {
                await navigator.clipboard.writeText(txId);
                const icon = el.querySelector('i');
                const span = el.querySelector('span');
                const originalIconClass = icon.className;
                const originalText = span.textContent;
                
                icon.className = 'fas fa-check text-[10px] text-green-500';
                span.textContent = 'Copied!';
                span.classList.add('text-green-500', 'font-bold');
                
                setTimeout(() => {
                    icon.className = originalIconClass;
                    span.textContent = originalText;
                    span.classList.remove('text-green-500', 'font-bold');
                }, 2000);
            } catch(e) { showToast('Failed to copy', 'error'); }
        }

        document.addEventListener('DOMContentLoaded', () => loadClients(false));
    </script>
</body>
</html>