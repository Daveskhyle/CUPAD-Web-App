<?php
// co/loan_collection.php - MySQL Version (Integrated with new Settings Tables)
require_once __DIR__ . '/../includes/config.php';
session_start();

// Use PDO for database connection (consistent with saving_collection.php)
try {
    $pdo = getDbConnection();
} catch (Exception $e) {
    die("Database Connection Failed: " . $e->getMessage());
}

if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'co') {
    header('Location: ../index.php');
    exit();
}

$base_path = '../';
$username = $_SESSION['username'] ?? '';

// --- 1. HELPER FUNCTIONS ---

function generate_uuid() {
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // Version 4
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // Variant bits
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

// --- 2. SETTINGS & CONTEXT ---

// 2.1 Fetch Loan Collection Settings from DB
$collection_settings =[
    'max_installments_per_payment' => 3,
    'min_installments_per_payment' => 1,
    'grace_period_days' => 2,
    'allow_partial_payments' => false,
    'allow_overpayment' => false,
    'collection_date_readonly' => false
];

try {
    $sett_query = $pdo->query("SELECT * FROM loan_collection_settings LIMIT 1");
    if ($sett_query && $row = $sett_query->fetch(PDO::FETCH_ASSOC)) {
        $collection_settings['max_installments_per_payment'] = (int)$row['max_installments_per_payment'];
        $collection_settings['min_installments_per_payment'] = (int)$row['min_installments_per_payment'];
        $collection_settings['grace_period_days'] = (int)$row['grace_period_days'];
        $collection_settings['allow_partial_payments'] = (bool)$row['allow_partial_payments'];
        $collection_settings['allow_overpayment'] = (bool)$row['allow_overpayment'];
        $collection_settings['collection_date_readonly'] = (bool)$row['collection_date_readonly'];
    }
} catch (Exception $e) {
    error_log("Failed to fetch loan collection settings: " . $e->getMessage());
}

$collection_date_readonly = $collection_settings['collection_date_readonly'];

// 2.2 Fetch Loan Plans (for UI display badges)
$loan_plans =[];
try {
    $lp_query = $pdo->query("SELECT * FROM loan_plans");
    if ($lp_query) {
        while ($row = $lp_query->fetch(PDO::FETCH_ASSOC)) {
            $loan_plans[] = $row;
        }
    }
} catch (Exception $e) {
    error_log("Failed to fetch loan plans: " . $e->getMessage());
}

// 2.3 User Data
$full_name = $_SESSION['name'] ?? 'Credit Officer';
$co_is_weekly = false;
$profile_pic = 'default_avatar.png';

try {
    $stmt = $pdo->prepare("SELECT full_name, profile_pic, is_weekly FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($user) {
        $full_name = $user['full_name'];
        $co_is_weekly = (bool)$user['is_weekly'];
        $profile_pic = $user['profile_pic'] ?? 'default_avatar.png';
    }
} catch (Exception $e) {
    error_log("Failed to fetch user data: " . $e->getMessage());
}

// --- 3. AJAX HISTORY HANDLER ---
if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'get_transactions') {
    header('Content-Type: application/json');

    $page = max(1, intval($_GET['page'] ?? 1));
    $limit = max(1, min(100, intval($_GET['limit'] ?? 10)));
    $offset = ($page - 1) * $limit;

    $union_filter = $_GET['union'] ?? 'all';
    $date_filter = $_GET['date_filter'] ?? 'current_month';

    try {
        // Build Query
        $sql = "SELECT lc.transaction_id, c.name as client_name, lc.amount_collected, lc.date, lc.remaining_balance 
                FROM loan_collections lc
                JOIN clients c ON lc.client_id = c.id
                WHERE lc.officer = ?";

        $params = [$username];

        // Filter by Union
        if ($union_filter !== 'all') {
            $sql .= " AND c.union = ?";
            $params[] = $union_filter;
        }

        // Filter by Date
        if ($date_filter === 'today') {
            $today = date('Y-m-d');
            $sql .= " AND DATE(lc.date) = ?";
            $params[] = $today;
        } elseif ($date_filter === 'current_month') {
            $start = date('Y-m-01');
            $end = date('Y-m-t');
            $sql .= " AND DATE(lc.date) BETWEEN ? AND ?";
            $params[] = $start;
            $params[] = $end;
        }

        // Count Total for Pagination
        $count_sql = str_replace("SELECT lc.transaction_id, c.name as client_name, lc.amount_collected, lc.date, lc.remaining_balance", "SELECT COUNT(*) as cnt", $sql);
        $stmt = $pdo->prepare($count_sql);
        $stmt->execute($params);
        $total_records = $stmt->fetch()['cnt'];

        // Fetch Data
        $sql .= " ORDER BY lc.date DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $formatted_records =[];
        $page_total = 0;
        foreach ($result as $row) {
            $page_total += floatval($row['amount_collected']);
            $formatted_records[] = $row;
        }

        echo json_encode([
            'success' => true,
            'data' => $formatted_records,
            'pagination' =>[
                'current_page' => $page,
                'total_pages' => ceil($total_records / $limit),
                'total_records' => $total_records,
                'limit' => $limit,
                'has_next' => ($page * $limit) < $total_records,
                'has_prev' => $page > 1
            ],
            'totals' =>['page_total' => $page_total]
        ]);

    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

// --- 4. MAIN PAGE DATA FETCHING ---

// Fetch Clients & Assignments (Unions)
$clients =[];
$clients_by_union = [];
$assigned_unions =[];

try {
    $stmt = $pdo->prepare("SELECT id, name, `union`, phone, officer_username FROM clients WHERE officer_username = ? AND status = 'active' ORDER BY name ASC");
    $stmt->execute([$username]);

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
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

// Fetch Disbursements (Active Loans) for JS calculation
$disbursements =[];
try {
    if (!empty($clients)) {
        $client_ids = array_column($clients, 'id');
        $placeholders = str_repeat('?,', count($client_ids) - 1) . '?';
        $today = date('Y-m-d');
        
        // Fetch all active loans for assigned clients (Matches combined_collection.php)
        $stmt = $pdo->prepare("SELECT id, client_id, officer, total_payable, remaining_balance, num_installments, date, status, payoff_date, loan_term_type, client_type 
                               FROM disbursements 
                               WHERE (status != 'completed' OR payoff_date >= ?) 
                                 AND client_id IN ($placeholders)");
        $params = array_merge([$today], $client_ids);
        $stmt->execute($params);
        $disbursements = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    error_log("Failed to fetch disbursements: " . $e->getMessage());
}

// Statistics: Outstanding
$active_disbursements = array_filter($disbursements, function($d) {
    return $d['status'] !== 'completed' && floatval($d['remaining_balance']) > 0;
});
$total_outstanding_loans = count($active_disbursements);
$total_outstanding_amount = array_sum(array_column($active_disbursements, 'remaining_balance'));

// Statistics: Personal Month Total
$current_month_start = date('Y-m-01');
$current_month_end = date('Y-m-t');
$personal_month_total = 0;

try {
    $stmt = $pdo->prepare("SELECT SUM(amount_collected) as total FROM loan_collections WHERE officer = ? AND DATE(date) BETWEEN ? AND ?");
    $stmt->execute([$username, $current_month_start, $current_month_end]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $personal_month_total = floatval($result['total'] ?? 0);
} catch (Exception $e) {
    error_log("Failed to fetch month total: " . $e->getMessage());
}

// --- 5. ACTION HANDLING (POST) ---

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $response =['success' => false, 'message' => ''];
    $is_ajax = isset($_POST['ajax']) && $_POST['ajax'] === '1';

    $client_id = $_POST['client_id'] ?? '';
    $installments = intval($_POST['num_installments'] ?? 0);
    $custom_pay_amount = floatval($_POST['custom_pay_amount'] ?? 0);
    $input_date = !empty($_POST['date']) ? $_POST['date'] : date('Y-m-d H:i:s');
    $payment_date = date('Y-m-d', strtotime($input_date));

    // Check if the payment date falls on a weekend (6 = Saturday, 7 = Sunday)
    $day_of_week = (int)date('N', strtotime($payment_date));

    // Basic Validation
    $client_exists = false;
    foreach ($clients as $c) { if ($c['id'] == $client_id) { $client_exists = true; break; } }

    if ($day_of_week >= 6) {
        $response['message'] = 'Loan collections are not allowed on weekends (Saturday and Sunday).';
    } elseif (!$client_exists) {
        $response['message'] = 'Invalid Client ID';
    } elseif ($custom_pay_amount <= 0 && $installments <= 0) {
        $response['message'] = 'Invalid payment amount.';
    } else {
        // ===================================================================
        // FIXED: Find Active Loan - Get MOST RECENT by date (matches JS logic)
        // ===================================================================
        $loan = null;
        $client_loans = array_filter($disbursements, function($d) use ($client_id) {
            return (string)$d['client_id'] === (string)$client_id && $d['status'] !== 'completed';
        });

        // Sort by date descending (most recent first) to match JavaScript behavior
        usort($client_loans, function($a, $b) {
            return strtotime($b['date']) - strtotime($a['date']);
        });

        // Take the first (most recent) active loan
        if (!empty($client_loans)) {
            $loan = array_values($client_loans)[0];
        }
        // ===================================================================

        if (!$loan) {
            $response['message'] = 'No active loan found for this client.';
        } else {
            // Limits Logic based on DB Settings
            $max_allowed = intval($collection_settings['max_installments_per_payment']);
            $total_inst = intval($loan['num_installments'] ?? ($co_is_weekly ? 24 : 23));
            $rem_bal = floatval($loan['remaining_balance']);
            $inst_amt = ($total_inst > 0) ? ($loan['total_payable'] / $total_inst) : 0;
            $rem_inst = ($inst_amt > 0.1) ? ceil($rem_bal / $inst_amt) : 0;

            // Override: 20-installment plan or near finish
            if ($total_inst === 20 || $rem_inst <= 3) {
                $max_allowed = max($max_allowed, 3);
            }

            if ($custom_pay_amount <= 0 && $installments > $max_allowed) {
                $response['message'] = "Cannot pay more than $max_allowed installment(s).";
            } else {
                // START CHECKS
                try {
                    $pdo->beginTransaction();

                    // 1. Duplicate Check
                    $chk = $pdo->prepare("SELECT id FROM loan_collections WHERE client_id = ? AND DATE(date) = ? FOR UPDATE");
                    $chk->execute([$client_id, $payment_date]);
                    if ($chk->rowCount() > 0) {
                        throw new Exception('Client already has a loan payment on this date.');
                    }

                    // 2. Savings Withdrawal Check (Blocking Rule)
                    $schk = $pdo->prepare("SELECT id FROM saving_collections WHERE client_id = ? AND DATE(date) = ? AND type = 'withdrawal'");
                    $schk->execute([$client_id, $payment_date]);
                    if ($schk->rowCount() > 0) {
                         // Warning: Withdrawal exists on same day
                    }

                    // 3. Grace Period Check
                    $grace_days = intval($collection_settings['grace_period_days']);
                    $disb_date = new DateTime($loan['date']);
                    $pay_date_obj = new DateTime($payment_date);
                    $min_date = clone $disb_date;
                    $min_date->modify("+$grace_days days");

                    if ($pay_date_obj < $min_date) {
                         throw new Exception("Collections allowed after " . $min_date->format('d M Y') . " ($grace_days day grace period).");
                    }

                    // Calculate Amount
                    if ($custom_pay_amount > 0) {
                        $pay_amt = $custom_pay_amount;
                    } else {
                        $pay_amt = $inst_amt * $installments;
                    }

                    // Overpayment Check
                    if (!$collection_settings['allow_overpayment'] && $pay_amt > ($rem_bal + 0.01)) {
                         throw new Exception('Amount exceeds remaining balance of ₦' . number_format($rem_bal, 2));
                    }

                    // Process Payment
                    $new_rem = max(0, $rem_bal - $pay_amt);
                    $is_paid_off = ($new_rem <= 0.01);
                    if ($is_paid_off) { $new_rem = 0; $status = 'completed'; } else { $status = $loan['status']; }

                    // Update Disbursement
                    $payoff_sql = $is_paid_off ? ", payoff_date = '$payment_date'" : "";
                    $upd = $pdo->prepare("UPDATE disbursements SET remaining_balance = ?, status = ? $payoff_sql WHERE id = ?");
                    $upd->execute([$new_rem, $status, $loan['id']]);
                    if (!$upd) throw new Exception("Failed to update loan balance.");

                    // Insert Collection
                    $tx_id = 'LCL-' . strtoupper(generate_uuid());
                    $ins = $pdo->prepare("INSERT INTO loan_collections (transaction_id, client_id, disbursement_id, amount_collected, date, officer, type, remaining_balance) VALUES (?, ?, ?, ?, ?, ?, 'repayment', ?)");
                    $ins->execute([$tx_id, $client_id, $loan['id'], $pay_amt, $input_date, $username, $new_rem]);
                    if (!$ins) throw new Exception("Failed to record transaction.");

                    $pdo->commit();

                    // Response
                    $client_name = '';
                    foreach($clients as $c) if($c['id'] == $client_id) $client_name = $c['name'];

                    $response['success'] = true;
                    $response['message'] = "Collected ₦" . number_format($pay_amt) . " from $client_name" . ($is_paid_off ? " (Paid Off!)" : "");
                    $response['transaction'] =[
                        'transaction_id' => $tx_id,
                        'client_name' => $client_name,
                        'client_id' => $client_id,
                        'amount_collected' => $pay_amt,
                        'date' => $input_date,
                        'remaining_balance' => $new_rem,
                        'loan_paid_off' => $is_paid_off
                    ];

                } catch (Exception $e) {
                    $pdo->rollBack();
                    $response['message'] = $e->getMessage();
                }
            }
        }
    }

    if ($is_ajax) {
        header('Content-Type: application/json');
        echo json_encode($response);
        exit();
    }
}

// Pass PHP data to JS
$js_clients_by_union = json_encode($clients_by_union);
$js_disbursements = json_encode($disbursements);
$js_settings = json_encode($collection_settings);
$js_assigned_unions = json_encode($assigned_unions);
$js_loan_plans = json_encode($loan_plans);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#8b5cf6">
    <title>Loan Collection | CUPAD</title>
    
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    
    <script>
    tailwind.config = {
        darkMode: 'class',
        theme: {
            extend: {
                colors: {
                    primary: '#8b5cf6', // Purple
                    secondary: '#3b82f6', // Blue
                    success: '#22c55e',
                    warning: '#f59e0b',
                    error: '#ef4444',
                    bg: { light: '#f0f2f5', dark: '#0f172a' }
                }
            }
        }
    }
    </script>
    
    <script>
        (function() {
            try {
                const localTheme = localStorage.getItem('theme');
                const sysTheme = window.matchMedia('(prefers-color-scheme: dark)').matches;
                if (localTheme === 'dark' || (!localTheme && sysTheme)) {
                    document.documentElement.classList.add('dark');
                } else {
                    document.documentElement.classList.remove('dark');
                }
            } catch (e) {}
        })();
    </script>

    <style>
        :root {
            --primary-color: #8b5cf6; --secondary-color: #3b82f6;
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
        
        .main-header { background: rgba(255, 255, 255, 0.95); backdrop-filter: blur(12px); border-bottom: 1px solid var(--border-color); position: sticky; top: 0; z-index: 50; padding-top: env(safe-area-inset-top); }
        html.dark .main-header { background: rgba(15, 23, 42, 0.95); }
        .navbar { display: flex; justify-content: space-between; align-items: center; padding: 0.8rem 1rem; max-width: 1280px; margin: 0 auto; }
        
        .dashboard-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1rem; margin-bottom: 1.5rem; }
        .dashboard-card { border-radius: var(--border-radius-xl); padding: 1.5rem; position: relative; overflow: hidden; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1); color: white; transition: transform 0.2s ease; border: none; }
        .dashboard-card:active { transform: scale(0.98); }
        .card-gradient-purple { background: linear-gradient(135deg, #8b5cf6 0%, #6d28d9 100%); }
        .card-gradient-amber { background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); }
        .card-gradient-blue { background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); }
        .card-bg-icon { position: absolute; right: -10px; bottom: -20px; font-size: 6rem; color: white; opacity: 0.1; transform: rotate(-10deg); pointer-events: none; }
        
        .activity-card { display: flex; align-items: center; background: var(--bg-secondary); padding: 1rem; margin-bottom: 0.75rem; border-radius: var(--border-radius-lg); border: 1px solid var(--border-color); }
        .ac-icon-box { width: 44px; height: 44px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; margin-right: 0.8rem; flex-shrink: 0; }
        .ac-content { flex: 1; }
        .ac-top { display: flex; justify-content: space-between; align-items: center; }
        .ac-bottom { display: flex; justify-content: space-between; align-items: center; margin-top: 2px; }
        .client-name { font-weight: 600; font-size: 0.95rem; color: var(--text-primary); }
        .activity-amount { font-weight: 700; font-size: 1rem; }
        .activity-date { font-size: 0.7rem; color: var(--text-secondary); }

        .input-group { background: var(--bg-primary); border: 1px solid var(--border-color); border-radius: 0.75rem; padding: 0.75rem; transition: all 0.2s ease; position: relative; }
        .input-group:focus-within { border-color: var(--primary-color); box-shadow: 0 0 0 2px rgba(139, 92, 246, 0.2); background: var(--bg-secondary); }
        .input-group:hover { border-color: #cbd5e1; }
        html.dark .input-group:hover { border-color: #475569; }
        
        .input-field { background: transparent; border: none; width: 100%; color: var(--text-primary); outline: none; font-size: 0.95rem; appearance: none; -webkit-appearance: none; padding-right: 2rem; cursor: pointer; }
        .input-field option { background-color: var(--bg-secondary); color: var(--text-primary); padding: 10px; }
        
        .mobile-bottom-nav { display: none; position: fixed; bottom: 0; left: 0; right: 0; background: var(--bg-secondary); border-top: 1px solid var(--border-color); padding: 0.5rem 1rem calc(0.5rem + env(safe-area-inset-bottom)); z-index: 100; justify-content: space-between; box-shadow: 0 -4px 10px rgba(0,0,0,0.05); }
        .nav-item { display: flex; flex-direction: column; align-items: center; justify-content: center; text-decoration: none; color: var(--text-secondary); font-size: 0.7rem; font-weight: 500; gap: 4px; flex: 1; }
        .nav-item i { font-size: 1.4rem; }
        .nav-item.active { color: var(--primary-color); }
        
        @media (max-width: 768px) {
            .mobile-bottom-nav { display: flex; }
            .dashboard-grid { display: flex; overflow-x: auto; scroll-snap-type: x mandatory; gap: 1rem; padding-bottom: 0.5rem; margin-right: -1rem; padding-right: 1.5rem; scrollbar-width: none; }
            .dashboard-grid::-webkit-scrollbar { display: none; }
            .dashboard-card { min-width: 85vw; scroll-snap-align: center; flex-shrink: 0; }
        }
        
        #toast-container { position: fixed; top: 1rem; left: 50%; transform: translateX(-50%); z-index: 999; width: 90%; max-width: 350px; pointer-events: none; }
        .toast { background: var(--bg-secondary); border-left: 4px solid; padding: 1rem; border-radius: 8px; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1); margin-bottom: 0.5rem; display: flex; align-items: center; gap: 10px; animation: slideDown 0.3s ease; pointer-events: auto; }
        @keyframes slideDown { from { transform: translateY(-20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }

        .profile-btn { width: 36px; height: 36px; border-radius: 50%; overflow: hidden; border: 2px solid var(--primary-color); background: var(--bg-secondary); cursor: pointer; display: flex; align-items: center; justify-content: center; position: relative; }
        .profile-btn img { width: 100%; height: 100%; object-fit: cover; }
        .profile-fallback-icon { font-size: 1.2rem; color: var(--text-secondary); }
    </style>
</head>
<body>

    <div id="toast-container"></div>

    <!-- HEADER -->
    <header class="main-header">
        <nav class="navbar">
            <a href="dashboard.php" class="flex items-center gap-2 no-underline">
                <img src="<?php echo htmlspecialchars($base_path . 'favicon.ico'); ?>" alt="Logo" class="h-8">
                <span class="text-xl font-extrabold text-blue-600 tracking-tight">CUPAD</span>
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
            <h1 class="text-2xl font-extrabold text-gray-900 dark:text-white">Loan Collection</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400">Manage repayments and view history</p>
        </div>

        <!-- STATS -->
        <div class="dashboard-grid">
            <div class="dashboard-card card-gradient-purple">
                <i class="fas fa-wallet card-bg-icon"></i>
                <div class="flex justify-between items-start relative z-10">
                    <div>
                        <div class="text-sm font-semibold uppercase opacity-90">Outstanding</div>
                        <div class="text-3xl font-extrabold mt-1 mb-2 count-up" data-target="<?php echo $total_outstanding_loans; ?>">0</div>
                        <div class="text-xs bg-black/20 rounded-full px-3 py-1 inline-flex items-center"><i class="fas fa-exclamation-circle mr-1"></i> Active Loans</div>
                    </div>
                    <div class="w-12 h-12 bg-white/20 backdrop-blur rounded-xl flex items-center justify-center text-xl"><i class="fas fa-file-invoice-dollar"></i></div>
                </div>
            </div>

            <div class="dashboard-card card-gradient-amber">
                <i class="fas fa-balance-scale card-bg-icon"></i>
                <div class="flex justify-between items-start relative z-10">
                    <div>
                        <div class="text-sm font-semibold uppercase opacity-90">Balance Due</div>
                        <div class="text-3xl font-extrabold mt-1 mb-2">₦<span class="count-up" data-target="<?php echo $total_outstanding_amount; ?>" data-currency="true">0</span></div>
                        <div class="text-xs bg-black/20 rounded-full px-3 py-1 inline-flex items-center"><i class="fas fa-chart-line mr-1"></i> Total</div>
                    </div>
                    <div class="w-12 h-12 bg-white/20 backdrop-blur rounded-xl flex items-center justify-center text-xl"><i class="fas fa-coins"></i></div>
                </div>
            </div>

            <div class="dashboard-card card-gradient-blue">
                <i class="fas fa-calendar-check card-bg-icon"></i>
                <div class="flex justify-between items-start relative z-10">
                    <div>
                        <div class="text-sm font-semibold uppercase opacity-90">Collected Month</div>
                        <div class="text-3xl font-extrabold mt-1 mb-2">₦<span class="count-up" data-target="<?php echo $personal_month_total; ?>" data-currency="true">0</span></div>
                        <div class="text-xs bg-black/20 rounded-full px-3 py-1 inline-flex items-center"><i class="fas fa-check-circle mr-1"></i> Performance</div>
                    </div>
                    <div class="w-12 h-12 bg-white/20 backdrop-blur rounded-xl flex items-center justify-center text-xl"><i class="fas fa-wallet"></i></div>
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
                            <i class="fas fa-money-bill-wave text-purple-600"></i> Record Payment
                        </h2>
                    </div>

                    <form id="collectionForm" class="p-5 space-y-5">
                        <input type="hidden" name="ajax" value="1">
                        
                        <!-- Union Filter -->
                        <div>
                            <label class="text-xs font-bold text-gray-500 uppercase mb-2 block">Union Filter</label>
                            <div id="union-tags" class="flex flex-wrap gap-2"></div>
                        </div>

                        <!-- Client Dropdown -->
                        <div>
                            <label class="text-xs font-bold text-gray-500 uppercase mb-1 block">Client</label>
                            <div class="input-group">
                                <i class="fas fa-user absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 group-focus-within:text-purple-500 transition-colors pointer-events-none"></i>
                                <select id="client_id" name="client_id" required class="input-field cursor-pointer pl-10">
                                    <option value="">Select Client...</option>
                                </select>
                                <i class="fas fa-chevron-down absolute right-3 top-1/2 -translate-y-1/2 text-xs text-gray-400 pointer-events-none"></i>
                            </div>
                        </div>

                        <!-- Client Stats (Dynamic) -->
                        <div id="client-info-card" class="hidden rounded-xl border border-gray-100 dark:border-slate-700 overflow-hidden">
                            <div class="px-3 py-2 text-xs font-bold text-white bg-gray-500 flex justify-between items-center">
                                <span>Loan Snapshot</span>
                                <div class="flex gap-2 items-center">
                                    <!-- Plan Badge -->
                                    <span id="info-plan-badge" class="hidden px-2 py-0.5 rounded-full bg-white/20 text-white text-[10px] font-bold uppercase tracking-wider"></span>
                                    <span class="bg-black/20 px-2 rounded-full">Active</span>
                                </div>
                            </div>
                            <div class="p-4 bg-gray-50 dark:bg-slate-900/50">
                                <div class="flex justify-between items-end mb-1">
                                    <span class="text-xs text-gray-500 uppercase font-bold">Outstanding Balance</span>
                                    <div id="info-loan" class="font-extrabold text-xl text-purple-600">₦0</div>
                                </div>
                                <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-1.5 mb-2">
                                    <div id="info-progress" class="bg-purple-500 h-1.5 rounded-full transition-all duration-500" style="width: 0%"></div>
                                </div>
                                <p id="info-installments-text" class="text-xs text-right text-gray-400"></p>
                            </div>
                        </div>

                        <!-- Installment Selection -->
                        <div id="installment-section" class="hidden">
                            <label class="text-xs font-bold text-gray-500 uppercase mb-1 block">Installments</label>
                            <div class="input-group">
                                <i class="fas fa-calculator absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 group-focus-within:text-purple-500 transition-colors pointer-events-none"></i>
                                <select id="num_installments" name="num_installments" class="input-field cursor-pointer pl-10">
                                    <option value="">Select count...</option>
                                </select>
                                <i class="fas fa-chevron-down absolute right-3 top-1/2 -translate-y-1/2 text-xs text-gray-400 pointer-events-none"></i>
                            </div>
                            
                            <!-- Dynamic Custom Input -->
                            <div id="custom-amount-container"></div>

                            <div class="mt-3 p-3 bg-purple-50 dark:bg-slate-700/50 rounded-lg text-center border border-purple-100 dark:border-slate-600">
                                <div class="text-xs text-purple-500 uppercase font-bold">Total To Collect</div>
                                <div id="total-to-pay" class="text-2xl font-extrabold text-purple-700 dark:text-purple-300">₦0</div>
                            </div>
                        </div>

                        <!-- Date -->
                        <div>
                            <div class="flex justify-between items-center mb-1">
                                <label class="text-xs font-bold text-gray-500 uppercase block">Date</label>
                                <button type="button" id="btn-set-now" class="text-[10px] font-bold text-purple-600 hover:underline" <?php echo $collection_date_readonly ? 'disabled style="opacity:0.5"' : ''; ?>>SET NOW</button>
                            </div>
                            <div class="input-group">
                                <input type="datetime-local" id="date" name="date" value="<?php echo date('Y-m-d\TH:i'); ?>" <?php echo $collection_date_readonly ? 'readonly' : ''; ?> class="input-field text-sm p-0">
                            </div>
                            <p class="text-[11px] text-amber-600 dark:text-amber-400 mt-1 font-medium"><i class="fas fa-info-circle mr-1"></i>Collections are not allowed on Saturdays or Sundays.</p>
                        </div>

                        <!-- ACTION BUTTON -->
                        <button type="submit" id="submitBtn" class="w-full py-3.5 bg-purple-600 hover:bg-purple-700 text-white rounded-xl font-bold shadow-lg shadow-purple-500/30 transition transform active:scale-95">
                            Record Payment
                        </button>
                    </form>
                </div>
            </div>

            <!-- HISTORY FEED (RIGHT) -->
            <div class="lg:col-span-2">
                <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm border border-gray-200 dark:border-slate-700 h-[650px] flex flex-col overflow-hidden relative">
                    
                    <div class="p-4 border-b border-gray-100 dark:border-slate-700 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3 shrink-0">
                        <h2 class="text-lg font-bold text-gray-800 dark:text-white whitespace-nowrap">Recent Transactions</h2>
                        
                        <div class="flex gap-2 overflow-x-auto w-full sm:w-auto pb-1 sm:pb-0" style="scrollbar-width: none; -ms-overflow-style: none;">
                            <select id="history-union-filter" class="text-xs bg-gray-100 dark:bg-slate-700 border-none rounded-lg px-2 py-1 outline-none shrink-0 cursor-pointer">
                                <option value="all">All Unions</option>
                            </select>
                            <button class="filter-date-btn text-xs px-3 py-1 text-gray-500 hover:bg-gray-100 dark:hover:bg-slate-700 rounded-lg shrink-0 transition" data-val="all">All</button>
                            <button class="filter-date-btn active text-xs px-3 py-1 bg-purple-100 text-purple-600 rounded-lg font-bold shrink-0 transition" data-val="current_month">This Month</button>
                            <button class="filter-date-btn text-xs px-3 py-1 text-gray-500 hover:bg-gray-100 dark:hover:bg-slate-700 rounded-lg shrink-0 transition" data-val="today">Today</button>
                        </div>
                    </div>

                    <div id="history-container" class="p-4 flex-1 overflow-y-auto scroll-smooth w-full">
                         <div class="text-center py-10 text-gray-400">Loading...</div>
                    </div>
                    
                    <div class="p-4 border-t border-gray-100 dark:border-slate-700 text-center flex justify-between items-center shrink-0 bg-white dark:bg-slate-800 z-10">
                         <span class="text-xs text-gray-500" id="pagination-info">...</span>
                         <div class="flex gap-2">
                            <button id="prev-page" class="w-8 h-8 flex items-center justify-center rounded-lg border border-gray-200 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-slate-700 text-xs transition"><i class="fas fa-chevron-left"></i></button>
                            <button id="next-page" class="w-8 h-8 flex items-center justify-center rounded-lg border border-gray-200 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-slate-700 text-xs transition"><i class="fas fa-chevron-right"></i></button>
                         </div>
                    </div>
                </div>
            </div>

        </div>
    </main>

    <!-- MOBILE BOTTOM NAV -->
    <nav class="mobile-bottom-nav">
        <a href="dashboard.php" class="nav-item">
            <i class="fas fa-home"></i>
            <span>Home</span>
        </a>
        <a href="saving_collection.php" class="nav-item">
            <i class="fas fa-piggy-bank"></i>
            <span>Save</span>
        </a>
        <a href="disbursement.php" class="nav-item">
             <i class="fas fa-hand-holding-usd"></i>
             <span>Disburse</span>
        </a>
        <a href="loan_collection.php" class="nav-item active">
             <div style="background: var(--primary-color); width: 45px; height: 45px; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; margin-top: -25px; box-shadow: 0 4px 10px rgba(139, 92, 246, 0.4);">
                <i class="fas fa-money-bill-wave" style="font-size: 1.2rem; margin:0;"></i>
             </div>
             <span style="font-size:0.65rem; font-weight:600; margin-top:2px;">Repay</span>
        </a>
        <a href="clients.php" class="nav-item">
            <i class="fas fa-users"></i>
            <span>Clients</span>
        </a>
    </nav>

    <script>
        const clientsByUnion = <?php echo $js_clients_by_union; ?>;
        const disbursements = <?php echo $js_disbursements; ?>;
        const settings = <?php echo $js_settings; ?>;
        const assignedUnions = <?php echo $js_assigned_unions; ?>;
        const loanPlans = <?php echo $js_loan_plans; ?>;
        const currentUser = "<?php echo $username; ?>";
        const isWeeklyOfficer = <?php echo $co_is_weekly ? 'true' : 'false'; ?>;
        
        const PURPLE_COLOR = '#8b5cf6';

        let state = {
            currentUnion: null,
            unionFilter: 'all',
            dateFilter: 'current_month',
            currentPage: 1,
            limit: 10,
            isLoading: false
        };

        const els = {
            unionTags: document.getElementById('union-tags'),
            clientSelect: document.getElementById('client_id'),
            clientInfoCard: document.getElementById('client-info-card'),
            infoLoan: document.getElementById('info-loan'),
            infoProgress: document.getElementById('info-progress'),
            infoText: document.getElementById('info-installments-text'),
            infoPlanBadge: document.getElementById('info-plan-badge'),
            installmentSection: document.getElementById('installment-section'),
            installSelect: document.getElementById('num_installments'),
            totalToPay: document.getElementById('total-to-pay'),
            customAmountContainer: document.getElementById('custom-amount-container'),
            historyContainer: document.getElementById('history-container'),
            form: document.getElementById('collectionForm'),
            submitBtn: document.getElementById('submitBtn'),
            paginationInfo: document.getElementById('pagination-info'),
            prevPage: document.getElementById('prev-page'),
            nextPage: document.getElementById('next-page'),
            historyUnionFilter: document.getElementById('history-union-filter'),
            dateInput: document.getElementById('date')
        };

        const formatCurrency = (amt) => '₦' + parseFloat(amt).toLocaleString('en-US', {minimumFractionDigits: 0, maximumFractionDigits: 0});

        function showToast(msg, type) {
            const el = document.createElement('div');
            el.className = 'toast';
            el.style.borderLeftColor = type === 'success' ? '#22c55e' : (type === 'error' ? '#ef4444' : '#3b82f6');
            el.innerHTML = `<i class="fas ${type==='success'?'fa-check-circle text-green-500':(type==='error'?'fa-exclamation-circle text-red-500':'fa-info-circle text-blue-500')}"></i> <span class="text-sm font-medium text-gray-800 dark:text-gray-200">${msg}</span>`;
            document.getElementById('toast-container').appendChild(el);
            setTimeout(() => { el.style.opacity = '0'; setTimeout(() => el.remove(), 300); }, 3000);
        }

        // --- WEEKEND VALIDATION HELPER ---
        function isWeekend(dateString) {
            if (!dateString) return false;
            const parts = dateString.split('T')[0].split('-');
            if (parts.length < 3) return false;
            // new Date(year, monthIndex, day) in local time
            const d = new Date(parts[0], parts[1] - 1, parts[2]);
            const day = d.getDay();
            return day === 0 || day === 6; // 0 = Sunday, 6 = Saturday
        }

        // --- CORE LOGIC ---

        function initUnions() {
            const unions = Object.keys(clientsByUnion);
            els.unionTags.innerHTML = '';
            
            if (unions.length === 0) {
                els.unionTags.innerHTML = '<span class="text-xs italic text-gray-400">No assigned unions</span>';
                return;
            }

            unions.forEach((union, idx) => {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = `px-3 py-1 rounded-full text-xs font-bold border transition ${idx === 0 ? 'bg-purple-600 text-white border-purple-600' : 'bg-white text-gray-500 border-gray-200 hover:bg-gray-50 dark:bg-slate-700 dark:text-gray-300 dark:border-slate-600'}`;
                btn.textContent = union;
                btn.onclick = () => selectUnion(union, btn);
                els.unionTags.appendChild(btn);
                if (idx === 0) selectUnion(union, btn);
            });
        }

        function selectUnion(union, btnEl) {
            state.currentUnion = union;
            Array.from(els.unionTags.children).forEach(c => c.className = 'px-3 py-1 rounded-full text-xs font-bold border transition bg-white text-gray-500 border-gray-200 hover:bg-gray-50 dark:bg-slate-700 dark:text-gray-300 dark:border-slate-600');
            btnEl.className = 'px-3 py-1 rounded-full text-xs font-bold border transition bg-purple-600 text-white border-purple-600';
            populateClients(union);
        }

        function populateClients(union) {
            els.clientSelect.innerHTML = '<option value="">Select Client...</option>';
            const unionClients = clientsByUnion[union] ||[];
            
            unionClients.forEach(client => {
                const activeLoan = getActiveLoan(client.id);
                if (activeLoan) {
                    els.clientSelect.innerHTML += `<option value="${client.id}">${client.name}</option>`;
                }
            });
            hideInfo();
        }

        function getActiveLoan(clientId) {
            const clientDisbursements = disbursements
                .filter(d => String(d.client_id) === String(clientId))
                .sort((a, b) => new Date(b.date) - new Date(a.date));
                
            return clientDisbursements.find(d => (d.remaining_balance === undefined || parseFloat(d.remaining_balance) > 0.01) && d.status !== 'completed');
        }

        function hideInfo() {
            els.clientInfoCard.classList.add('hidden');
            els.installmentSection.classList.add('hidden');
            els.customAmountContainer.innerHTML = '';
        }

        function getPlanName(loan) {
            if (loan.loan_term_type && loan.loan_term_type !== 'Standard') {
                return loan.loan_term_type;
            }
            if (loan.client_type) {
                for (const plan of loanPlans) {
                    const planLabel = (plan.duration || plan.installments) + ' ' + (plan.unit || '');
                    if (loan.client_type.toLowerCase().includes(planLabel.toLowerCase())) {
                        return planLabel;
                    }
                }
                return loan.client_type;
            }
            const installCount = parseInt(loan.num_installments);
            if (installCount === 23) return "23 Days";
            if (installCount === 13) return "13 Weeks";
            if (installCount === 24) return "24 Weeks";

            return "Standard";
        }

        els.clientSelect.addEventListener('change', (e) => {
            const clientId = e.target.value;
            if (!clientId) { hideInfo(); return; }

            const loan = getActiveLoan(clientId);
            if (loan) {
                els.clientInfoCard.classList.remove('hidden');
                const total = parseFloat(loan.total_payable);
                const remaining = parseFloat(loan.remaining_balance !== undefined ? loan.remaining_balance : total);
                const paid = total - remaining;
                
                els.infoLoan.textContent = formatCurrency(remaining);
                els.infoProgress.style.width = (total > 0 ? (paid/total)*100 : 0) + '%';
                
                const numInst = parseInt(loan.num_installments) || (isWeeklyOfficer ? 24 : 23);
                const instAmt = numInst > 0 ? total / numInst : 0;
                const instPaid = instAmt > 0 ? Math.round(paid / instAmt) : 0;
                els.infoText.textContent = `${instPaid}/${numInst} Paid • ${numInst-instPaid} Remaining`;

                const planName = getPlanName(loan);
                if(planName) {
                    els.infoPlanBadge.textContent = planName;
                    els.infoPlanBadge.classList.remove('hidden');
                } else {
                    els.infoPlanBadge.classList.add('hidden');
                }

                setupInstallments(remaining, instAmt);
            } else {
                showToast('No active loan found', 'info');
                hideInfo();
            }
        });

        function setupInstallments(remainingBal, amountPerInst) {
            els.installmentSection.classList.remove('hidden');
            els.installSelect.innerHTML = '<option value="">Select...</option>';
            els.totalToPay.textContent = '₦0';

            const min = settings.min_installments_per_payment || 1;
            let max = settings.max_installments_per_payment || 3;
            
            const clientId = els.clientSelect.value;
            const loan = getActiveLoan(clientId);
            
            if (loan) {
                const totalInstallments = parseInt(loan.num_installments) || 23;
                const remainingInstallments = amountPerInst > 0 ? Math.ceil(remainingBal / amountPerInst) : 0;
                
                if (totalInstallments === 20) {
                    max = 3;
                } else if (remainingInstallments <= 3) {
                    max = Math.max(max, 3);
                }
            }
            
            let autoSelectVal = null;
            let autoSelectTotal = 0;
            
            for (let i = min; i <= max; i++) {
                const total = i * amountPerInst;
                if (settings.allow_overpayment || total <= remainingBal + 1) { 
                    const opt = document.createElement('option');
                    opt.value = i;
                    opt.textContent = `${i} Installment${i>1?'s':''} (₦${Math.round(total).toLocaleString()})`;
                    opt.dataset.amount = total;
                    els.installSelect.appendChild(opt);
                    if (i === min) { autoSelectVal = String(min); autoSelectTotal = total; }
                }
            }
            
            if (settings.allow_partial_payments) {
                const customOpt = document.createElement('option');
                customOpt.value = 'custom';
                customOpt.textContent = 'Custom Amount...';
                customOpt.dataset.amount = '0';
                els.installSelect.appendChild(customOpt);
            }
            
            if (autoSelectVal) {
                els.installSelect.value = autoSelectVal;
                els.totalToPay.textContent = formatCurrency(autoSelectTotal);
            }
            
            els.installSelect.onchange = (e) => {
                const value = e.target.value;
                if (value === 'custom') {
                    showCustomAmountInput(remainingBal);
                } else {
                    els.customAmountContainer.innerHTML = '';
                    const opt = e.target.options[e.target.selectedIndex];
                    els.totalToPay.textContent = opt.dataset.amount ? formatCurrency(opt.dataset.amount) : '₦0';
                }
            };
        }

        function showCustomAmountInput(maxAmount) {
            els.customAmountContainer.innerHTML = `
                <div class="mt-2 input-group flex items-center">
                    <span class="text-gray-400 mr-2">₦</span>
                    <input type="number" id="custom-amount" name="custom_pay_amount" class="input-field font-bold p-0" placeholder="Enter amount" min="1" max="${maxAmount}">
                </div>
            `;
            
            document.getElementById('custom-amount').addEventListener('input', (e) => {
                const amount = parseFloat(e.target.value) || 0;
                els.totalToPay.textContent = formatCurrency(amount);
            });
        }

        // --- HISTORY FEED ---

        async function loadTransactions(page = 1) {
            if (state.isLoading) return;
            state.isLoading = true;
            els.historyContainer.innerHTML = '<div class="text-center py-10 flex flex-col items-center opacity-50"><i class="fas fa-spinner fa-spin text-2xl mb-2"></i><span>Loading...</span></div>';

            try {
                const params = new URLSearchParams({
                    ajax_action: 'get_transactions',
                    page: page,
                    limit: state.limit,
                    union: state.unionFilter,
                    date_filter: state.dateFilter
                });
                const res = await fetch(`?${params.toString()}`);
                const data = await res.json();

                if (data.success) {
                    renderFeed(data.data);
                    updatePagination(data.pagination);
                }
            } catch (err) { console.error(err); } 
            finally { state.isLoading = false; }
        }

        function renderFeed(rows) {
            els.historyContainer.innerHTML = '';
            if (rows.length === 0) {
                els.historyContainer.innerHTML = '<div class="text-center py-10 flex flex-col items-center opacity-50"><i class="fas fa-inbox text-4xl mb-2"></i><span>No transactions found</span></div>';
                return;
            }

            rows.forEach(item => {
                const html = `
                <div class="activity-card group hover:bg-gray-50 dark:hover:bg-slate-700/50 transition">
                    <div class="ac-icon-box" style="background: ${PURPLE_COLOR}15; color: ${PURPLE_COLOR};">
                        <i class="fas fa-money-bill-wave"></i>
                    </div>
                    <div class="ac-content min-w-0"> 
                        <div class="ac-top mb-1">
                            <div class="client-name truncate pr-2" title="${item.client_name}">${item.client_name}</div>
                            <div class="activity-amount whitespace-nowrap" style="color: ${PURPLE_COLOR};">₦${parseInt(item.amount_collected).toLocaleString()}</div>
                        </div>
                        <div class="ac-bottom">
                            <div class="flex gap-2 items-center">
                                <span class="text-[10px] font-bold uppercase tracking-wide opacity-80" style="background:${PURPLE_COLOR}15; color:${PURPLE_COLOR}; padding:2px 6px; border-radius:4px;">REPAYMENT</span>
                                ${parseFloat(item.remaining_balance) <= 0.01 ? '<span class="text-[10px] bg-green-100 text-green-700 px-1.5 py-0.5 rounded font-bold whitespace-nowrap">PAID OFF</span>' : ''}
                            </div>
                            <div class="activity-date whitespace-nowrap"><i class="far fa-clock mr-1"></i>${item.date.substring(5,16)}</div>
                        </div>
                    </div>
                </div>`;
                els.historyContainer.insertAdjacentHTML('beforeend', html);
            });
        }

        function updatePagination(meta) {
            state.currentPage = meta.current_page;
            const start = meta.total_records > 0 ? ((meta.current_page-1)*meta.limit)+1 : 0;
            els.paginationInfo.textContent = `${start}-${Math.min(meta.total_records, meta.current_page*meta.limit)} of ${meta.total_records}`;
            els.prevPage.disabled = !meta.has_prev;
            els.nextPage.disabled = !meta.has_next;
            els.prevPage.onclick = () => loadTransactions(meta.current_page - 1);
            els.nextPage.onclick = () => loadTransactions(meta.current_page + 1);
        }

        // Watch Date Input for Weekend Selection
        els.dateInput.addEventListener('change', function() {
            if (isWeekend(this.value)) {
                showToast('Weekend selected! Collections are not permitted on Saturdays or Sundays.', 'error');
            }
        });

        els.form.addEventListener('submit', async (e) => {
            e.preventDefault();

            // Client-side Weekend Check
            const chosenDate = els.dateInput.value;
            if (isWeekend(chosenDate)) {
                showToast('Loan collections are disabled on weekends (Saturday & Sunday).', 'error');
                return;
            }

            els.submitBtn.disabled = true;
            els.submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';

            try {
                const formData = new FormData(els.form);
                const selectedValue = formData.get('num_installments');
                
                if (selectedValue === 'custom') {
                    if (!settings.allow_partial_payments) {
                        showToast('Custom amounts are not allowed', 'error'); 
                        els.submitBtn.disabled=false; 
                        els.submitBtn.innerHTML='Record Payment'; 
                        return;
                    }
                    const customAmount = parseFloat(document.getElementById('custom-amount')?.value || '0');
                    if (customAmount <= 0) {
                        showToast('Please enter a valid amount', 'error'); 
                        els.submitBtn.disabled=false; 
                        els.submitBtn.innerHTML='Record Payment'; 
                        return;
                    }
                    
                    const loan = getActiveLoan(formData.get('client_id'));
                    if (loan) {
                        const remainingBal = parseFloat(loan.remaining_balance !== undefined ? loan.remaining_balance : loan.total_payable);
                        if (!settings.allow_overpayment && (customAmount - remainingBal) > 0.01) {
                            showToast(`Amount exceeds remaining balance of ${formatCurrency(remainingBal)}`, 'error'); 
                            els.submitBtn.disabled=false; 
                            els.submitBtn.innerHTML='Record Payment'; 
                            return;
                        }
                        formData.set('num_installments', '1');
                    }
                }

                const res = await fetch(window.location.href, { method: 'POST', body: formData });
                const result = await res.json();
                
                if (result.success) {
                    showToast(result.message, 'success');
                    const loan = disbursements.find(d => d.client_id === result.transaction.client_id);
                    if (loan) {
                        loan.remaining_balance = result.transaction.remaining_balance;
                        if(result.transaction.loan_paid_off) loan.status = 'completed';
                    }
                    els.clientSelect.value = "";
                    hideInfo();
                    populateClients(state.currentUnion);
                    loadTransactions(1);
                    animateNumbers();
                } else {
                    showToast(result.message, 'error');
                }
            } catch (err) { 
                showToast('Connection error', 'error'); 
            } finally {
                els.submitBtn.disabled = false;
                els.submitBtn.innerHTML = 'Record Payment';
            }
        });

        // --- FILTERS & UTILS ---

        if (assignedUnions.length > 0) {
            assignedUnions.forEach(u => els.historyUnionFilter.innerHTML += `<option value="${u}">${u}</option>`);
            els.historyUnionFilter.addEventListener('change', (e) => {
                state.unionFilter = e.target.value;
                loadTransactions(1);
            });
        }

        document.querySelectorAll('.filter-date-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                document.querySelectorAll('.filter-date-btn').forEach(b => {
                    b.className = 'filter-date-btn text-xs px-3 py-1 text-gray-500 hover:bg-gray-100 dark:hover:bg-slate-700 rounded-lg transition';
                });
                e.target.className = 'filter-date-btn active text-xs px-3 py-1 bg-purple-100 text-purple-600 rounded-lg font-bold transition';
                state.dateFilter = e.target.dataset.val;
                loadTransactions(1);
            });
        });

        document.getElementById('btn-set-now').onclick = () => {
            const now = new Date();
            const dayOfWeek = now.getDay();
            if (dayOfWeek === 0 || dayOfWeek === 6) {
                showToast('Today is a weekend. Collections are not allowed on weekends.', 'error');
                return;
            }
            now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
            document.getElementById('date').value = now.toISOString().slice(0,16);
        };

        function animateNumbers() {
            document.querySelectorAll('.count-up').forEach(el => {
                const t = parseFloat(el.dataset.target); const curr = el.dataset.currency;
                let s = 0; 
                const anim = (ts) => {
                    if(!s) s=ts; const p = Math.min((ts-s)/1000, 1);
                    el.innerText = curr ? Math.round(p*t).toLocaleString() : Math.round(p*t);
                    if(p<1) requestAnimationFrame(anim);
                };
                requestAnimationFrame(anim);
            });
        }

        document.getElementById('theme-toggle').onclick = () => {
            if (document.documentElement.classList.contains('dark')) {
                document.documentElement.classList.remove('dark');
                localStorage.setItem('theme', 'light');
            } else {
                document.documentElement.classList.add('dark');
                localStorage.setItem('theme', 'dark');
            }
        };

        initUnions();
        loadTransactions();
        animateNumbers();
    </script>
</body>
</html>