<?php
// co/analytics.php
session_start();

// --- DATABASE CONFIGURATION ---
define('DB_HOST', 'localhost');
define('DB_NAME', 'cupadnam_db'); // Update if different in your actual setup
define('DB_USER', 'cupadnam_db'); // Update if different
define('DB_PASS', 'f2GrjZQCz8E39nCu9eLg'); // Update if different
define('DB_CHARSET', 'utf8mb4');

function getDbConnection() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            // For AJAX requests, return JSON error
            if (isset($_GET['ajax'])) {
                header('Content-Type: application/json');
                echo json_encode(['error' => 'Database connection failed']);
                exit();
            }
            die("Database Connection Error.");
        }
    }
    return $pdo;
}

$base_path = '../';
$pdo = getDbConnection();

// --- AUTHENTICATION ---
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'co') {
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Unauthorized']);
        exit();
    }
    header('Location: ../index.php');
    exit();
}

$co_username = $_SESSION['username'] ?? '';

// --- HELPER: FIXED PUBLIC HOLIDAYS ---
function is_holiday($date_obj) {
    $md = $date_obj->format('m-d');
    $holidays = ['01-01', '05-01', '06-12', '10-01', '12-25', '12-26'];
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

// --- 1. USER CONTEXT & ASSIGNMENTS ---
// Fetch CO Branch Name
$co_branch_name = 'My Branch';
try {
    $stmt = $pdo->prepare("SELECT b.name FROM users u JOIN branches b ON u.branch_id = b.id WHERE u.username = ?");
    $stmt->execute([$co_username]);
    $res = $stmt->fetch();
    if($res) $co_branch_name = $res['name'];
} catch (Exception $e) {}

// Fetch Assigned Unions
$assigned_unions = [];
try {
    $stmt = $pdo->prepare("SELECT DISTINCT `union` FROM assignments WHERE co = ? AND status = 'active'");
    $stmt->execute([$co_username]);
    $assigned_unions = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {}

// --- 4. DATE FILTERING SETUP ---
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

// --- 6. AJAX HANDLER ---
if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    header('Content-Type: application/json');
    $aj_from = $date_from;
    $aj_to = $date_to;

    // 6a. FETCH REGISTRATION LIST
    if (isset($_GET['action']) && $_GET['action'] === 'fetch_registrations_list') {
        $target_union = $_GET['union_name'] ?? null;
        
        $sql = "SELECT r.client_name, r.client_id, r.union, r.date, r.amount, 'New' as type 
                FROM registrations r 
                WHERE r.officer = ? AND DATE(r.date) BETWEEN ? AND ?";
        $params = [$co_username, $aj_from, $aj_to];

        if (!empty($target_union)) {
            $sql .= " AND r.union = ?";
            $params[] = $target_union;
        }
        $sql .= " ORDER BY r.date DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $raw_regs = $stmt->fetchAll();

        $reg_list = [];
        foreach ($raw_regs as $reg) {
            $reg_list[] = [
                'name' => $reg['client_name'],
                'id' => $reg['client_id'],
                'union' => $reg['union'],
                'date' => date('M d, Y', strtotime($reg['date'])),
                'amount' => floatval($reg['amount']),
                'type' => $reg['type']
            ];
        }
        echo json_encode(['registrations' => $reg_list]);
        exit();
    }

    // 6b. FETCH UNION DETAILS
    if (isset($_GET['action']) && $_GET['action'] === 'union_details') {
        $target_union = $_GET['union_name'] ?? '';
        $client_search = isset($_GET['q']) ? trim($_GET['q']) : '';
        $today_obj = new DateTime(); $today_obj->setTime(0,0,0);

        // Fetch Clients in Union with Savings Balance
        $sql = "SELECT c.id, c.name, 
                       COALESCE((SELECT balance FROM savings s WHERE s.client_id = c.id LIMIT 1), 0) as savings_balance
                FROM clients c 
                WHERE c.officer_username = ? AND c.union = ?";
        $params = [$co_username, $target_union];

        if ($client_search) {
            $sql .= " AND (c.name LIKE ? OR c.id LIKE ?)";
            $params[] = "%$client_search%";
            $params[] = "%$client_search%";
        }
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $clients = $stmt->fetchAll();

        $union_client_details = [];

        foreach ($clients as $client) {
            $cid = $client['id'];
            
            // Get Active Loan info for this client
            // Note: Schema doesn't imply ON UPDATE CASCADE, so ID is stable
            $stmtLoan = $pdo->prepare("SELECT date, remaining_balance, payoff_date FROM disbursements WHERE client_id = ? AND (status = 'active' OR (status = 'completed' AND payoff_date BETWEEN ? AND ?))");
            $stmtLoan->execute([$cid, $aj_from, $aj_to]);
            $loans = $stmtLoan->fetchAll();

            $outstanding_bal = 0; 
            $days_overdue = 0;
            $is_paid_in_period = false;

            foreach ($loans as $loan) {
                $rem = floatval($loan['remaining_balance']);
                
                // Active Loan Calculation
                if ($rem > 0.01) {
                    $outstanding_bal += $rem;
                    $due_date_obj = calculate_due_date($loan['date'], 26);
                    if ($today_obj > $due_date_obj) {
                        // Use latest overdue if multiple (though rarely multiple active loans)
                        $days_overdue = max($days_overdue, $today_obj->diff($due_date_obj)->days);
                    }
                } 
                // Check if paid off recently
                elseif ($loan['payoff_date']) {
                    $is_paid_in_period = true; // Logic handled by query date range
                }
            }

            // Period Range Stats (Collections & Savings)
            $stmtRange = $pdo->prepare("
                SELECT 
                    SUM(CASE WHEN type = 'repayment' THEN amount_collected ELSE 0 END) as col_total
                FROM loan_collections WHERE client_id = ? AND DATE(date) BETWEEN ? AND ?
            ");
            $stmtRange->execute([$cid, $aj_from, $aj_to]);
            $range_col = $stmtRange->fetchColumn() ?: 0;

            $stmtSavRange = $pdo->prepare("
                SELECT SUM(amount) FROM saving_collections 
                WHERE client_id = ? AND type = 'deposit' AND DATE(date) BETWEEN ? AND ?
            ");
            $stmtSavRange->execute([$cid, $aj_from, $aj_to]);
            $range_sav = $stmtSavRange->fetchColumn() ?: 0;

            $union_client_details[] = [
                'name' => $client['name'],
                'id' => $cid,
                'savings_balance' => floatval($client['savings_balance']),
                'loan_outstanding' => $outstanding_bal,
                'is_fully_paid' => $is_paid_in_period,
                'days_overdue' => $days_overdue,
                'range_savings' => floatval($range_sav),
                'range_collected' => floatval($range_col)
            ];
        }
        echo json_encode(['clients' => $union_client_details]);
        exit();
    }

    // 6c. GLOBAL METRICS & CHARTS
    
    // 1. Total Clients
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM clients WHERE officer_username = ?");
    $stmt->execute([$co_username]);
    $total_clients = $stmt->fetchColumn();

    // 2. Savings (Transactions in Period)
    $stmt = $pdo->prepare("
        SELECT 
            SUM(CASE WHEN type = 'deposit' THEN amount ELSE 0 END) as deposits,
            SUM(CASE WHEN type IN ('withdrawal', 'return') THEN amount ELSE 0 END) as withdrawals
        FROM saving_collections 
        WHERE officer = ? AND DATE(date) BETWEEN ? AND ?
    ");
    $stmt->execute([$co_username, $aj_from, $aj_to]);
    $sav_stats = $stmt->fetch();
    $savings_deposits = floatval($sav_stats['deposits'] ?? 0);
    $savings_withdrawals = floatval($sav_stats['withdrawals'] ?? 0); // Keep positive for display logic
    $net_savings = $savings_deposits - $savings_withdrawals;

    // 3. Disbursements (In Period)
    $stmt = $pdo->prepare("SELECT SUM(principal) FROM disbursements WHERE officer = ? AND date BETWEEN ? AND ?");
    $stmt->execute([$co_username, $aj_from, $aj_to]);
    $total_disbursements = floatval($stmt->fetchColumn() ?? 0);

    // 4. Collections (In Period)
    $stmt = $pdo->prepare("SELECT SUM(amount_collected) FROM loan_collections WHERE officer = ? AND DATE(date) BETWEEN ? AND ?");
    $stmt->execute([$co_username, $aj_from, $aj_to]);
    $total_collections = floatval($stmt->fetchColumn() ?? 0);

    // 5. Service Charges (Approximation based on period disbursements)
    $stmt = $pdo->prepare("SELECT SUM(total_payable - principal) FROM disbursements WHERE officer = ? AND date BETWEEN ? AND ?");
    $stmt->execute([$co_username, $aj_from, $aj_to]);
    $total_service_charges = floatval($stmt->fetchColumn() ?? 0);

    // 6. Registrations
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM registrations WHERE officer = ? AND DATE(date) BETWEEN ? AND ?");
    $stmt->execute([$co_username, $aj_from, $aj_to]);
    $total_registrations = intval($stmt->fetchColumn());

    // 7. Portfolio Health (Active Loans / PAR)
    // Need to loop for due_date calculation
    $stmt = $pdo->prepare("SELECT principal, total_payable, remaining_balance, date FROM disbursements WHERE officer = ? AND status = 'active'");
    $stmt->execute([$co_username]);
    $active_loans_data = $stmt->fetchAll();

    $active_loans_count = 0;
    $total_outstanding = 0;
    $total_overdue = 0;
    $total_expected_collections = 0; // Cumulative expectation from completed loans + active loans total payable
    $today_obj = new DateTime(); $today_obj->setTime(0,0,0);

    foreach ($active_loans_data as $loan) {
        $rem = floatval($loan['remaining_balance']);
        if ($rem > 0.01) {
            $active_loans_count++;
            $total_outstanding += $rem;
            $due_date_obj = calculate_due_date($loan['date'], 26);
            if ($today_obj > $due_date_obj) {
                $total_overdue += $rem;
            }
        }
    }

    // Collection Rate (Simulated: Total Collected / (Total Collected + Outstanding that is overdue?))
    // A simplified metric often used: Collected / (Collected + Outstanding) or just historical.
    // For now, let's use: Collections / (Collections + Overdue) to show efficiency, or 100% if no overdue.
    // However, exact standard formula requires "Expected Collection". Let's stick to logic from previous code if available,
    // otherwise use Total Collected vs Total Disbursed (Revolving) or similar.
    // Previous code: $total_expected_collections based on "completed" loans.
    // Let's approximate Collection Rate = Collected / (Collected + Overdue)
    $denominator = ($total_collections + $total_overdue);
    $collection_rate = $denominator > 0 ? ($total_collections / $denominator) * 100 : 100;
    $par_ratio = $total_outstanding > 0 ? ($total_overdue / $total_outstanding) * 100 : 0;

    // 8. Union Breakdown
    $union_stats = [];
    foreach ($assigned_unions as $union) {
        // Clients Count
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM clients WHERE `union` = ? AND officer_username = ?");
        $stmt->execute([$union, $co_username]);
        $u_cli_count = $stmt->fetchColumn();

        // Savings (Period)
        $stmt = $pdo->prepare("
            SELECT 
                SUM(CASE WHEN sc.type = 'deposit' THEN sc.amount ELSE 0 END) as dep,
                SUM(CASE WHEN sc.type IN ('withdrawal','return') THEN sc.amount ELSE 0 END) as wit
            FROM saving_collections sc
            JOIN clients c ON sc.client_id = c.id
            WHERE c.union = ? AND c.officer_username = ? AND DATE(sc.date) BETWEEN ? AND ?
        ");
        $stmt->execute([$union, $co_username, $aj_from, $aj_to]);
        $u_sav = $stmt->fetch();
        $u_dep = floatval($u_sav['dep']);
        $u_wit = floatval($u_sav['wit']);

        // Disbursements (Period)
        $stmt = $pdo->prepare("
            SELECT SUM(d.principal) FROM disbursements d
            JOIN clients c ON d.client_id = c.id
            WHERE c.union = ? AND c.officer_username = ? AND d.date BETWEEN ? AND ?
        ");
        $stmt->execute([$union, $co_username, $aj_from, $aj_to]);
        $u_disb = floatval($stmt->fetchColumn());

        // Collections (Period)
        $stmt = $pdo->prepare("
            SELECT SUM(lc.amount_collected) FROM loan_collections lc
            JOIN clients c ON lc.client_id = c.id
            WHERE c.union = ? AND c.officer_username = ? AND DATE(lc.date) BETWEEN ? AND ?
        ");
        $stmt->execute([$union, $co_username, $aj_from, $aj_to]);
        $u_coll = floatval($stmt->fetchColumn());

        // Registrations (Period)
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM registrations WHERE `union` = ? AND officer = ? AND DATE(date) BETWEEN ? AND ?");
        $stmt->execute([$union, $co_username, $aj_from, $aj_to]);
        $u_reg = intval($stmt->fetchColumn());

        // Paid Off & Overdue Counts (Requires Active Loan Logic per Union)
        $stmt = $pdo->prepare("
            SELECT d.remaining_balance, d.payoff_date, d.date 
            FROM disbursements d JOIN clients c ON d.client_id = c.id
            WHERE c.union = ? AND c.officer_username = ?
        ");
        $stmt->execute([$union, $co_username]);
        $u_loans = $stmt->fetchAll();

        $u_paid_off = 0;
        $u_overdue = 0;

        foreach ($u_loans as $l) {
            $rem = floatval($l['remaining_balance']);
            if ($rem <= 0.01 && $l['payoff_date'] >= $aj_from && $l['payoff_date'] <= $aj_to) {
                $u_paid_off++;
            }
            if ($rem > 0.01) {
                $due = calculate_due_date($l['date'], 26);
                if ($today_obj > $due) $u_overdue++;
            }
        }

        $union_stats[] = [
            'name' => $union,
            'clients' => $u_cli_count,
            'total_savings' => $u_dep,
            'withdrawals' => $u_wit,
            'net_savings' => $u_dep - $u_wit,
            'disbursements' => $u_disb,
            'collections' => $u_coll,
            'registrations' => $u_reg,
            'paid_off' => $u_paid_off,
            'overdue_count' => $u_overdue
        ];
    }

    // 9. Daily Chart Data
    $daily_data = [];
    $period = new DatePeriod(new DateTime($aj_from), new DateInterval('P1D'), (new DateTime($aj_to))->modify('+1 day'));
    
    // Optimize: Fetch grouped data instead of looping days with SQL
    $stmtCol = $pdo->prepare("
        SELECT DATE(date) as day, SUM(amount_collected) as total 
        FROM loan_collections WHERE officer = ? AND DATE(date) BETWEEN ? AND ? 
        GROUP BY DATE(date)
    ");
    $stmtCol->execute([$co_username, $aj_from, $aj_to]);
    $daily_col = $stmtCol->fetchAll(PDO::FETCH_KEY_PAIR);

    $stmtSav = $pdo->prepare("
        SELECT DATE(date) as day, SUM(amount) as total 
        FROM saving_collections WHERE officer = ? AND type='deposit' AND DATE(date) BETWEEN ? AND ? 
        GROUP BY DATE(date)
    ");
    $stmtSav->execute([$co_username, $aj_from, $aj_to]);
    $daily_sav = $stmtSav->fetchAll(PDO::FETCH_KEY_PAIR);

    foreach ($period as $dt) {
        $day = $dt->format('Y-m-d');
        $dispDay = $dt->format('M d');
        $daily_data[] = [
            'date' => $dispDay,
            'savings' => floatval($daily_sav[$day] ?? 0),
            'collections' => floatval($daily_col[$day] ?? 0)
        ];
    }

    echo json_encode([
        'metrics' => [
            'total_clients' => intval($total_clients),
            'total_savings' => $savings_deposits,
            'total_withdrawals' => $savings_withdrawals,
            'net_savings' => $net_savings,
            'total_disbursements' => $total_disbursements,
            'total_collections' => $total_collections,
            'collection_rate' => number_format($collection_rate, 1),
            'par_ratio' => number_format($par_ratio, 1),
            'active_loans' => $active_loans_count,
            'total_outstanding' => $total_outstanding,
            'total_service_charges' => $total_service_charges,
            'total_registrations' => $total_registrations
        ],
        'chart_daily' => $daily_data,
        'chart_comp' => ['Savings' => $savings_deposits, 'Disbursements' => $total_disbursements, 'Collections' => $total_collections],
        'union_table' => $union_stats,
        'dates' => ['from' => $aj_from, 'to' => $aj_to]
    ]);
    exit();
}

// --- PROFILE PICTURE & INIT ---
$profile_pic = 'default_avatar.png';
$full_name = 'Credit Officer';
try {
    $stmt = $pdo->prepare("SELECT full_name, profile_pic FROM users WHERE username = ?");
    $stmt->execute([$co_username]);
    $u = $stmt->fetch();
    if($u) {
        $full_name = $u['full_name'] ?? 'Credit Officer';
        $profile_pic = !empty($u['profile_pic']) ? $u['profile_pic'] : 'default_avatar.png';
    }
} catch(Exception $e) {}

$h = date('H');
$greeting = $h < 12 ? "Good Morning" : ($h < 18 ? "Good Afternoon" : "Good Evening");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#3b82f6">
    <title>Analytics | CUPAD</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
    tailwind.config = {
        darkMode: 'class',
        theme: {
            extend: {
                fontFamily: { sans: ['Inter', 'sans-serif'] },
                colors: {
                    primary: '#3b82f6', secondary: '#8b5cf6',
                    success: '#22c55e', warning: '#f59e0b', error: '#ef4444',
                    bg: { light: '#f0f2f5', dark: '#0f172a' },
                    dark: { surface: '#1e293b', border: '#334155' }
                }
            }
        }
    }
    </script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>
        :root {
            --primary-color: #3b82f6; --secondary-color: #8b5cf6;
            --bg-primary: #f0f2f5; --bg-secondary: #ffffff;
            --bg-header: rgba(255, 255, 255, 0.95);
            --border-radius-xl: 1.5rem;
        }
        html.dark {
            --bg-primary: #0f172a; --bg-secondary: #1e293b;
            --bg-header: rgba(15, 23, 42, 0.95);
        }
        body { background-color: var(--bg-primary); padding-bottom: 90px; -webkit-tap-highlight-color: transparent; }
        .main-header { background: var(--bg-header); backdrop-filter: blur(12px); position: sticky; top: 0; z-index: 50; border-bottom: 1px solid rgba(0,0,0,0.05); }
        html.dark .main-header { border-bottom: 1px solid rgba(255,255,255,0.05); }
        .dashboard-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1rem; margin-bottom: 1.5rem; }
        .dashboard-card { border-radius: var(--border-radius-xl); padding: 1.5rem; position: relative; overflow: hidden; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1); color: white; border: none; }
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
        .mobile-bottom-nav { display: none; position: fixed; bottom: 0; left: 0; right: 0; background: var(--bg-secondary); border-top: 1px solid rgba(0,0,0,0.05); padding: 0.5rem 1rem calc(0.5rem + env(safe-area-inset-bottom)); z-index: 100; justify-content: space-between; }
        html.dark .mobile-bottom-nav { border-top: 1px solid rgba(255,255,255,0.05); }
        .nav-item { display: flex; flex-direction: column; align-items: center; justify-content: center; color: #6b7280; font-size: 0.7rem; font-weight: 500; gap: 4px; flex: 1; text-decoration: none; }
        .nav-item i { font-size: 1.4rem; }
        .nav-item.active { color: var(--primary-color); }
        @media (max-width: 768px) { .mobile-bottom-nav { display: flex; } }
        .profile-btn { width: 36px; height: 36px; border-radius: 50%; overflow: hidden; border: 2px solid var(--primary-color); background: var(--bg-secondary); display: flex; align-items: center; justify-content: center; }
        .count-up { font-variant-numeric: tabular-nums; }
    </style>
    <script>
        if (localStorage.getItem('theme') === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            document.documentElement.classList.add('dark');
        } else {
            document.documentElement.classList.remove('dark');
        }
    </script>
</head>
<body class="bg-slate-50 text-slate-900 dark:bg-dark-surface dark:text-slate-100 transition-colors duration-200">

    <!-- HEADER -->
    <header class="main-header px-4 py-3">
        <div class="max-w-7xl mx-auto flex justify-between items-center">
            <a href="dashboard.php" class="flex items-center gap-2 no-underline">
                <img src="<?php echo htmlspecialchars($base_path . 'favicon.ico'); ?>" alt="Logo" class="h-8 w-auto">
                <span class="text-xl font-extrabold text-blue-600 tracking-tight">CUPAD</span>
            </a>
            <div class="flex items-center gap-3">
                <button id="themeToggle" class="p-2 text-gray-500 dark:text-gray-400 rounded-full hover:bg-gray-100 dark:hover:bg-slate-800 transition">
                    <i class="fas fa-moon dark:hidden"></i><i class="fas fa-sun hidden dark:inline"></i>
                </button>
                <div class="profile-btn cursor-pointer" onclick="window.location.href='profile.php'">
                    <?php if ($profile_pic && $profile_pic !== 'default_avatar.png'): ?>
                        <img src="<?php echo $base_path . 'uploads/' . $profile_pic; ?>" alt="Profile" class="w-full h-full object-cover">
                    <?php else: ?>
                        <i class="fas fa-user text-gray-400"></i>
                    <?php endif; ?>
                </div>
                <a href="<?php echo htmlspecialchars($base_path . 'logout.php'); ?>" class="p-2 text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-full transition">
                    <i class="fas fa-sign-out-alt"></i>
                </a>
            </div>
        </div>
    </header>

    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        
        <!-- Controls & Greeting -->
        <div class="flex flex-col lg:flex-row justify-between items-start lg:items-center gap-4 mb-6">
            <div>
                <h1 class="text-2xl font-extrabold text-gray-900 dark:text-white"><?php echo $greeting; ?></h1>
                <div class="flex items-center gap-2 text-sm text-slate-500 dark:text-slate-400 mt-1">
                    <span><?php echo htmlspecialchars($co_branch_name); ?></span>
                    <span>&bull;</span>
                    <span class="font-mono bg-white dark:bg-slate-800 px-2 py-0.5 rounded border border-slate-200 dark:border-slate-700 text-xs" id="activeDateRange">...</span>
                </div>
            </div>

            <!-- Filters Section -->
            <div class="flex flex-col sm:flex-row gap-2 w-full lg:w-auto items-center">
                <label class="flex items-center cursor-pointer bg-white dark:bg-slate-800 px-3 py-2 rounded-xl border border-slate-200 dark:border-slate-700 w-full sm:w-auto justify-center sm:justify-start">
                    <input type="checkbox" id="liveToggle" class="sr-only peer">
                    <div class="relative w-9 h-5 bg-slate-200 peer-focus:outline-none rounded-full peer dark:bg-slate-700 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:start-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-green-500"></div>
                    <span class="ms-2 text-sm font-medium text-slate-700 dark:text-slate-300 flex items-center gap-1.5">Live <span id="liveStatus" class="hidden w-2 h-2 rounded-full bg-green-500 animate-pulse"></span></span>
                </label>

                <form id="filterForm" class="flex flex-col sm:flex-row gap-2 w-full sm:w-auto bg-white dark:bg-slate-800 p-1.5 rounded-xl border border-slate-200 dark:border-slate-700 shadow-sm">
                    <select name="preset" id="preset" class="text-sm py-2 px-3 rounded-lg bg-slate-50 dark:bg-slate-900 border-none outline-none focus:ring-2 focus:ring-primary w-full sm:w-auto">
                        <option value="">Custom</option>
                        <option value="today">Today</option>
                        <option value="week">This Week</option>
                        <option value="month" selected>This Month</option>
                        <option value="last_month">Last Month</option>
                    </select>
                    <div class="flex items-center gap-2 bg-slate-50 dark:bg-slate-900 rounded-lg px-2 w-full sm:w-auto">
                        <input type="date" id="date_from" class="bg-transparent border-none text-sm py-2 px-1 focus:ring-0 text-slate-700 dark:text-slate-300 w-full">
                        <span class="text-slate-400">-</span>
                        <input type="date" id="date_to" class="bg-transparent border-none text-sm py-2 px-1 focus:ring-0 text-slate-700 dark:text-slate-300 w-full">
                    </div>
                    <button type="submit" id="refreshBtn" class="p-2 bg-primary text-white rounded-lg hover:bg-blue-700 transition w-full sm:w-auto"><i class="fas fa-sync-alt"></i></button>
                </form>
            </div>
        </div>

        <!-- Metric Cards -->
        <div class="dashboard-grid">
            <div class="dashboard-card card-gradient-blue">
                <i class="fas fa-users card-bg-icon"></i>
                <div class="relative z-10">
                    <div class="text-sm font-semibold uppercase opacity-90">Total Clients</div>
                    <div class="text-3xl font-extrabold mt-1 mb-2 count-up" id="metric_clients">0</div>
                    <div class="text-xs bg-black/20 rounded-full px-3 py-1 inline-flex items-center"><i class="fas fa-users mr-1"></i> Active Base</div>
                </div>
            </div>
            <div class="dashboard-card card-gradient-green">
                <i class="fas fa-piggy-bank card-bg-icon"></i>
                <div class="relative z-10">
                    <div class="text-sm font-semibold uppercase opacity-90">Net Savings</div>
                    <div class="text-2xl font-extrabold mt-1 mb-2">₦<span class="count-up" id="metric_net_savings_card">0</span></div>
                    <div class="flex items-center gap-4 text-xs">
                        <span class="inline-flex items-center"><i class="fas fa-arrow-up mr-1 text-white/70"></i> <span id="metric_deposits_card">0</span></span>
                        <span class="inline-flex items-center"><i class="fas fa-arrow-down mr-1 text-white/70"></i> <span id="metric_withdrawals_card">0</span></span>
                    </div>
                </div>
            </div>
            <div class="dashboard-card card-gradient-orange">
                <i class="fas fa-paper-plane card-bg-icon"></i>
                <div class="relative z-10">
                    <div class="text-sm font-semibold uppercase opacity-90">Disbursements</div>
                    <div class="text-2xl font-extrabold mt-1 mb-2">₦<span class="count-up" id="metric_disbursements">0</span></div>
                    <div class="text-xs bg-black/20 rounded-full px-3 py-1 inline-flex items-center"><i class="fas fa-hand-holding-usd mr-1"></i> Loans Given</div>
                </div>
            </div>
            <div class="dashboard-card card-gradient-red">
                <i class="fas fa-money-bill-wave card-bg-icon"></i>
                <div class="relative z-10">
                    <div class="text-sm font-semibold uppercase opacity-90">Collections</div>
                    <div class="text-2xl font-extrabold mt-1 mb-2">₦<span class="count-up" id="metric_collections">0</span></div>
                    <div class="text-xs bg-black/20 rounded-full px-3 py-1 inline-flex items-center"><i class="fas fa-check mr-1"></i> Repayments</div>
                </div>
            </div>
        </div>

        <!-- Analysis Section -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
            <div class="bg-white dark:bg-slate-800 rounded-2xl p-5 border border-slate-200 dark:border-slate-700 shadow-sm">
                <h3 class="font-bold text-slate-800 dark:text-white mb-4 flex items-center gap-2"><i class="fas fa-heartbeat text-error"></i> Portfolio Health</h3>
                <div class="space-y-4 text-sm">
                    <div class="flex justify-between"><span class="text-slate-500 dark:text-slate-400">Collection Rate</span><span class="font-bold text-slate-800 dark:text-white" id="metric_col_rate">0%</span></div>
                    <div class="flex justify-between"><span class="text-slate-500 dark:text-slate-400">PAR Ratio</span><span class="font-bold text-slate-800 dark:text-white" id="metric_par">0%</span></div>
                    <div class="flex justify-between"><span class="text-slate-500 dark:text-slate-400">Active Loans</span><span class="font-bold text-slate-800 dark:text-white" id="metric_active_loans">0</span></div>
                    <div class="flex justify-between border-t border-slate-100 dark:border-slate-700 pt-2"><span class="text-slate-500 dark:text-slate-400 font-semibold">Outstanding</span><span class="font-bold text-error">₦<span id="metric_outstanding">0</span></span></div>
                </div>
            </div>

            <div class="bg-white dark:bg-slate-800 rounded-2xl p-5 border border-slate-200 dark:border-slate-700 shadow-sm">
                <h3 class="font-bold text-slate-800 dark:text-white mb-4 flex items-center gap-2"><i class="fas fa-calculator text-primary"></i> Financials</h3>
                <div class="space-y-3">
                    <div class="bg-slate-50 dark:bg-slate-700/50 p-3 rounded-lg flex justify-between items-center">
                        <div><p class="text-xs text-slate-500 uppercase">Net Savings</p><p class="font-bold text-slate-900 dark:text-white">₦<span id="metric_net_savings">0</span></p></div>
                        <div class="text-right"><p class="text-xs text-red-400">Withdrawals</p><p class="font-medium text-red-500">-₦<span id="metric_withdrawals">0</span></p></div>
                    </div>
                    <div class="bg-slate-50 dark:bg-slate-700/50 p-3 rounded-lg flex justify-between items-center">
                        <div><p class="text-xs text-slate-500 uppercase">Service Charges</p><p class="font-bold text-success">₦<span id="metric_service_charges">0</span></p></div>
                        <!-- CLICKABLE REGISTRATION COUNT -->
                        <div class="text-right cursor-pointer" onclick="openRegistrationDetails()">
                            <p class="text-xs text-slate-500">Reg. Fees</p>
                            <p class="font-medium text-primary flex items-center justify-end gap-1 hover:underline"><i class="fas fa-user-plus text-[10px]"></i> <span id="metric_registrations">0</span></p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white dark:bg-slate-800 rounded-2xl p-5 border border-slate-200 dark:border-slate-700 shadow-sm flex flex-col">
                 <h3 class="font-bold text-slate-800 dark:text-white mb-2 flex items-center gap-2"><i class="fas fa-chart-pie text-secondary"></i> Portfolio Mix</h3>
                 <div class="relative flex-1 min-h-[160px]"><canvas id="compChart"></canvas></div>
            </div>
        </div>

        <!-- Trend Chart -->
        <div class="bg-white dark:bg-slate-800 rounded-2xl p-6 mb-8 border border-slate-200 dark:border-slate-700 shadow-sm">
            <h3 class="font-bold text-slate-800 dark:text-white mb-4 flex items-center gap-2"><i class="fas fa-chart-line text-primary"></i> Activity Trend</h3>
            <div class="relative h-64 w-full"><canvas id="trendChart"></canvas></div>
        </div>

        <!-- Union Table -->
        <div class="bg-white dark:bg-slate-800 rounded-2xl border border-slate-200 dark:border-slate-700 shadow-sm overflow-hidden">
            <div class="p-4 border-b border-slate-200 dark:border-slate-700 flex flex-col sm:flex-row justify-between items-center gap-3">
                 <h3 class="font-bold text-lg text-slate-800 dark:text-white">Performance by Union</h3>
                 <div class="relative w-full sm:w-64">
                    <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400"></i>
                    <input type="text" id="unionSearchInput" placeholder="Search unions..." class="w-full pl-9 py-2 bg-slate-100 dark:bg-slate-900 border-none rounded-lg text-sm focus:ring-2 focus:ring-primary">
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm whitespace-nowrap">
                    <thead class="bg-slate-50 dark:bg-slate-900/50 text-slate-500 dark:text-slate-400 uppercase text-xs font-semibold">
                        <tr>
                            <th class="p-4">Union Name</th>
                            <th class="p-4 text-center">Clients</th>
                            <th class="p-4 text-right">Deposits</th>
                            <th class="p-4 text-right">Withdrawals</th>
                            <th class="p-4 text-right">Net Savings</th>
                            <th class="p-4 text-right">Disbursed</th>
                            <th class="p-4 text-right">Collected</th>
                            <th class="p-4 text-center">Registrations</th>
                            <th class="p-4 text-center">Paid Off</th>
                            <th class="p-4 text-center">Overdue</th>
                            <th class="p-4 text-center">Status</th>
                        </tr>
                    </thead>
                    <tbody id="unionTableBody" class="divide-y divide-slate-100 dark:divide-slate-700">
                        <tr><td colspan="11" class="p-8 text-center text-slate-500">Loading data...</td></tr>
                    </tbody>
                    <tfoot id="unionTableFooter" class="bg-slate-50 dark:bg-slate-900/50 font-bold text-slate-700 dark:text-slate-300"></tfoot>
                </table>
            </div>
        </div>
    </main>

    <!-- MOBILE NAV -->
    <nav class="mobile-bottom-nav">
        <a href="dashboard.php" class="nav-item"><i class="fas fa-home"></i><span>Home</span></a>
        <a href="saving_collection.php" class="nav-item"><i class="fas fa-piggy-bank"></i><span>Save</span></a>
        <a href="analytics.php" class="nav-item active relative">
             <div class="absolute -top-6 bg-blue-500 text-white w-12 h-12 rounded-full flex items-center justify-center shadow-lg shadow-blue-500/40 border-4 border-slate-50 dark:border-slate-900"><i class="fas fa-chart-bar text-lg"></i></div>
             <span class="mt-7 text-[10px] font-bold">Analytics</span>
        </a>
        <a href="disbursement.php" class="nav-item"><i class="fas fa-hand-holding-usd"></i><span>Disburse</span></a>
        <a href="loan_collection.php" class="nav-item"><i class="fas fa-money-bill-wave"></i><span>Repay</span></a>
    </nav>

    <!-- Detail Modal -->
    <div id="detailModal" class="fixed inset-0 z-[60] hidden">
        <div class="absolute inset-0 bg-slate-900/60 backdrop-blur-sm" onclick="closeModal()"></div>
        <div class="absolute inset-0 flex items-center justify-center p-4">
            <div class="bg-white dark:bg-slate-800 w-full max-w-4xl rounded-2xl shadow-2xl flex flex-col max-h-[90vh]">
                <div class="p-4 border-b border-slate-200 dark:border-slate-700 flex justify-between items-center bg-slate-50 dark:bg-slate-800/50 rounded-t-2xl">
                    <div><h3 class="font-bold text-lg text-slate-800 dark:text-white" id="modalTitle">Details</h3><p class="text-xs text-slate-500" id="modalSubtitle">Client Breakdown</p></div>
                    <div class="flex gap-2"><input type="text" id="clientSearchInput" placeholder="Search..." class="bg-white dark:bg-slate-900 border-none rounded-lg text-sm px-3 py-1 focus:ring-1 focus:ring-primary w-24 sm:w-48"><button onclick="closeModal()" class="w-8 h-8 flex items-center justify-center rounded-full bg-slate-200 dark:bg-slate-700 hover:bg-slate-300 dark:hover:bg-slate-600 transition"><i class="fas fa-times"></i></button></div>
                </div>
                <div class="flex-1 overflow-y-auto p-0">
                    <table class="w-full text-left text-sm whitespace-nowrap">
                        <thead class="bg-slate-50 dark:bg-slate-900 sticky top-0 z-10" id="modalTableHead">
                            <!-- Headers injected via JS -->
                        </thead>
                        <tbody id="modalTableBody" class="divide-y divide-slate-100 dark:divide-slate-700"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Logic -->
    <script>
        let charts = { trend: null, comp: null };
        let chartData = { daily: [], comp: {} };
        let liveInterval = null;
        let currentUnion = null;
        let currentView = 'performance'; // 'performance' or 'registrations'

        const debounce = (func, wait) => {
            let timeout;
            return function(...args) {
                clearTimeout(timeout);
                timeout = setTimeout(() => func.apply(this, args), wait);
            };
        };

        document.addEventListener('DOMContentLoaded', () => {
            fetchData();
            document.getElementById('filterForm').addEventListener('submit', (e) => { e.preventDefault(); fetchData(); });
            document.getElementById('unionSearchInput').addEventListener('input', debounce(() => fetchData(false), 300));
            document.getElementById('clientSearchInput').addEventListener('input', debounce(() => { 
                if(currentView === 'performance' && currentUnion) openDetails(currentUnion);
                // Search for registrations view could be implemented here too
            }, 300));
            
            document.getElementById('liveToggle').addEventListener('change', (e) => {
                const status = document.getElementById('liveStatus');
                if (e.target.checked) {
                    status.classList.remove('hidden');
                    liveInterval = setInterval(() => {
                        if(document.activeElement.tagName !== 'INPUT' && document.getElementById('detailModal').classList.contains('hidden')) fetchData(false);
                    }, 5000);
                } else {
                    status.classList.add('hidden');
                    if(liveInterval) clearInterval(liveInterval);
                }
            });
        });

        async function fetchData(spinner = true) {
            const btn = document.getElementById('refreshBtn');
            const icon = btn.querySelector('i');
            if(spinner) { icon.classList.add('fa-spin'); btn.disabled = true; }
            const preset = document.getElementById('preset').value;
            const from = document.getElementById('date_from').value;
            const to = document.getElementById('date_to').value;
            const search = document.getElementById('unionSearchInput').value;

            try {
                const res = await fetch(`?ajax=1&preset=${preset}&date_from=${from}&date_to=${to}&search=${encodeURIComponent(search)}`);
                const data = await res.json();
                updateUI(data);
            } catch(e) { console.error(e); }
            finally { if(spinner) { icon.classList.remove('fa-spin'); btn.disabled = false; } }
        }

        function updateUI(data) {
            document.getElementById('activeDateRange').textContent = `${data.dates.from} - ${data.dates.to}`;
            document.getElementById('date_from').value = data.dates.from;
            document.getElementById('date_to').value = data.dates.to;

            animate('metric_clients', data.metrics.total_clients);
            animate('metric_net_savings_card', data.metrics.net_savings, true);
            animate('metric_deposits_card', data.metrics.total_savings);
            animate('metric_withdrawals_card', data.metrics.total_withdrawals);
            animate('metric_disbursements', data.metrics.total_disbursements, true);
            animate('metric_collections', data.metrics.total_collections, true);
            document.getElementById('metric_col_rate').textContent = data.metrics.collection_rate + '%';
            document.getElementById('metric_par').textContent = data.metrics.par_ratio + '%';
            document.getElementById('metric_active_loans').textContent = data.metrics.active_loans;
            document.getElementById('metric_outstanding').textContent = parseInt(data.metrics.total_outstanding).toLocaleString();
            document.getElementById('metric_net_savings').textContent = parseInt(data.metrics.net_savings).toLocaleString();
            document.getElementById('metric_withdrawals').textContent = parseInt(data.metrics.total_withdrawals).toLocaleString();
            document.getElementById('metric_service_charges').textContent = parseInt(data.metrics.total_service_charges).toLocaleString();
            document.getElementById('metric_registrations').textContent = data.metrics.total_registrations;

            renderTable(data.union_table);
            chartData = { daily: data.chart_daily, comp: data.chart_comp };
            renderCharts();
        }

        function renderTable(rows) {
            const tbody = document.getElementById('unionTableBody');
            const tfoot = document.getElementById('unionTableFooter');
            
            if(!rows || !rows.length) {
                tbody.innerHTML = '<tr><td colspan="11" class="p-8 text-center text-slate-500">No data found.</td></tr>';
                tfoot.innerHTML = '';
                return;
            }

            tbody.innerHTML = rows.map(r => {
                const activity = parseFloat(r.net_savings) + parseFloat(r.collections);
                const badgeClass = activity > 100000 ? 'bg-emerald-100 text-emerald-700' : (activity > 50000 ? 'bg-blue-100 text-blue-700' : 'bg-slate-100 text-slate-600');
                const badgeText = activity > 100000 ? 'High' : (activity > 50000 ? 'Med' : 'Low');
                const overdueDisplay = r.overdue_count > 0 ? `<span class="px-2 py-0.5 rounded-full bg-red-100 text-red-600 font-bold">${r.overdue_count}</span>` : `<span class="text-slate-400">-</span>`;

                return `<tr class="hover:bg-slate-50 dark:hover:bg-slate-800 cursor-pointer transition" onclick="openDetails('${r.name}')">
                    <td class="p-4 font-medium text-slate-900 dark:text-white">${r.name}</td>
                    <td class="p-4 text-center text-slate-500">${r.clients}</td>
                    <td class="p-4 text-right font-mono text-success">₦${parseInt(r.total_savings).toLocaleString()}</td>
                    <td class="p-4 text-right font-mono text-error">₦${parseInt(r.withdrawals).toLocaleString()}</td>
                    <td class="p-4 text-right font-mono text-primary font-bold">₦${parseInt(r.net_savings).toLocaleString()}</td>
                    <td class="p-4 text-right font-mono text-warning">₦${parseInt(r.disbursements).toLocaleString()}</td>
                    <td class="p-4 text-right font-mono text-slate-600 dark:text-slate-300">₦${parseInt(r.collections).toLocaleString()}</td>
                    
                    <!-- CLICKABLE REGISTRATION CELL -->
                    <td class="p-4 text-center text-blue-600 hover:text-blue-800 font-bold hover:bg-blue-50 dark:hover:bg-blue-900/20 rounded transition" onclick="openRegistrationDetails('${r.name}', event)">${r.registrations}</td>
                    
                    <td class="p-4 text-center font-bold text-green-600 bg-green-50 dark:bg-green-900/10">${r.paid_off}</td>
                    <td class="p-4 text-center">${overdueDisplay}</td>
                    <td class="p-4 text-center"><span class="px-2 py-1 rounded-full text-xs font-bold ${badgeClass}">${badgeText}</span></td>
                </tr>`;
            }).join('');

            const totals = rows.reduce((acc, r) => ({
                cli: acc.cli + parseInt(r.clients), dep: acc.dep + parseInt(r.total_savings), wit: acc.wit + parseInt(r.withdrawals),
                net: acc.net + r.net_savings, disb: acc.disb + r.disbursements, col: acc.col + r.collections,
                reg: acc.reg + r.registrations, paid: acc.paid + r.paid_off, od: acc.od + r.overdue_count
            }), { cli: 0, dep: 0, wit: 0, net: 0, disb: 0, col: 0, reg: 0, paid: 0, od: 0 });

            tfoot.innerHTML = `<tr>
                <td class="p-4">TOTALS</td>
                <td class="p-4 text-center">${totals.cli.toLocaleString()}</td>
                <td class="p-4 text-right font-mono">₦${parseInt(totals.dep).toLocaleString()}</td>
                <td class="p-4 text-right font-mono text-error">₦${parseInt(totals.wit).toLocaleString()}</td>
                <td class="p-4 text-right font-mono">₦${parseInt(totals.net).toLocaleString()}</td>
                <td class="p-4 text-right font-mono">₦${parseInt(totals.disb).toLocaleString()}</td>
                <td class="p-4 text-right font-mono">₦${parseInt(totals.col).toLocaleString()}</td>
                <td class="p-4 text-center">${totals.reg}</td>
                <td class="p-4 text-center font-bold text-green-600">${totals.paid}</td>
                <td class="p-4 text-center font-bold text-red-500">${totals.od}</td>
                <td></td>
            </tr>`;
        }

        async function openRegistrationDetails(union = null, event = null) {
            if(event) event.stopPropagation();
            
            currentView = 'registrations';
            document.getElementById('modalTitle').textContent = union ? `Registrations: ${union}` : `All Registrations`;
            document.getElementById('modalSubtitle').textContent = document.getElementById('activeDateRange').textContent;
            
            const thead = document.getElementById('modalTableHead');
            thead.innerHTML = `
                <tr>
                    <th class="p-3 text-xs uppercase text-slate-500">Client</th>
                    <th class="p-3 text-xs uppercase text-slate-500">Union</th>
                    <th class="p-3 text-xs uppercase text-slate-500 text-right">Date</th>
                    <th class="p-3 text-xs uppercase text-slate-500 text-right">Fee</th>
                    <th class="p-3 text-xs uppercase text-slate-500 text-right">Type</th>
                </tr>
            `;

            document.getElementById('detailModal').classList.remove('hidden');
            const tbody = document.getElementById('modalTableBody');
            tbody.innerHTML = '<tr><td colspan="5" class="p-8 text-center"><i class="fas fa-spinner fa-spin text-2xl text-primary"></i></td></tr>';

            const from = document.getElementById('date_from').value;
            const to = document.getElementById('date_to').value;
            const unionParam = union ? `&union_name=${encodeURIComponent(union)}` : '';

            try {
                const res = await fetch(`?ajax=1&action=fetch_registrations_list${unionParam}&date_from=${from}&date_to=${to}`);
                const data = await res.json();
                
                if (data.registrations && data.registrations.length > 0) {
                    tbody.innerHTML = data.registrations.map(r => `
                        <tr class="hover:bg-slate-50 dark:hover:bg-slate-800">
                            <td class="p-3">
                                <div class="font-medium text-slate-900 dark:text-white">${r.name}</div>
                                <div class="text-xs text-slate-400 font-mono">${r.id}</div>
                            </td>
                            <td class="p-3 text-sm text-slate-600 dark:text-slate-300">${r.union}</td>
                            <td class="p-3 text-right text-sm text-slate-600 dark:text-slate-300">${r.date}</td>
                            <td class="p-3 text-right font-mono text-primary font-bold">₦${r.amount.toLocaleString()}</td>
                            <td class="p-3 text-right text-xs uppercase text-slate-500">${r.type}</td>
                        </tr>
                    `).join('');
                } else {
                    tbody.innerHTML = '<tr><td colspan="5" class="p-8 text-center text-slate-500">No registrations found for this period.</td></tr>';
                }
            } catch (e) {
                console.error(e);
                tbody.innerHTML = '<tr><td colspan="5" class="p-8 text-center text-red-500">Error loading data.</td></tr>';
            }
        }

        async function openDetails(union) {
            currentUnion = union;
            currentView = 'performance';
            document.getElementById('modalTitle').textContent = `Details: ${union}`;
            document.getElementById('modalSubtitle').textContent = 'Client Breakdown';
            
            const thead = document.getElementById('modalTableHead');
            thead.innerHTML = `
                <tr>
                    <th class="p-3 text-xs uppercase text-slate-500">Client</th>
                    <th class="p-3 text-xs uppercase text-slate-500 text-right">Total Sav</th>
                    <th class="p-3 text-xs uppercase text-slate-500 text-right">Loan Bal</th>
                    <th class="p-3 text-xs uppercase text-blue-500 text-right bg-blue-50/50 dark:bg-blue-900/10">Saved (Period)</th>
                    <th class="p-3 text-xs uppercase text-purple-500 text-right bg-purple-50/50 dark:bg-purple-900/10">Paid (Period)</th>
                </tr>
            `;

            document.getElementById('detailModal').classList.remove('hidden');
            const tbody = document.getElementById('modalTableBody');
            tbody.innerHTML = '<tr><td colspan="5" class="p-8 text-center"><i class="fas fa-spinner fa-spin text-2xl text-primary"></i></td></tr>';
            
            const from = document.getElementById('date_from').value;
            const to = document.getElementById('date_to').value;
            const q = document.getElementById('clientSearchInput').value;

            try {
                const res = await fetch(`?ajax=1&action=union_details&union_name=${encodeURIComponent(union)}&date_from=${from}&date_to=${to}&q=${encodeURIComponent(q)}`);
                const data = await res.json();
                if(data.clients && data.clients.length) {
                    tbody.innerHTML = data.clients.map(c => {
                        let loanDisplay = '';
                        if (c.days_overdue > 0) {
                             loanDisplay = `<div class="flex flex-col items-end"><span class="text-error font-bold">₦${parseFloat(c.loan_outstanding).toLocaleString()}</span><span class="text-[9px] bg-red-100 text-red-600 px-1 rounded uppercase font-bold mt-0.5">${c.days_overdue} DAYS OVERDUE</span></div>`;
                        } else if (c.loan_outstanding > 0) {
                             loanDisplay = `<span class="text-error font-bold">₦${parseFloat(c.loan_outstanding).toLocaleString()}</span>`;
                        } else if (c.is_fully_paid) {
                             loanDisplay = `<span class="bg-green-100 text-green-700 px-2 py-0.5 rounded text-[10px] font-bold">PAID OFF</span>`;
                        } else {
                             loanDisplay = `<span class="text-slate-300">-</span>`;
                        }

                        return `
                        <tr class="hover:bg-slate-50 dark:hover:bg-slate-800">
                            <td class="p-3">
                                <div class="font-medium text-slate-900 dark:text-white">${c.name}</div>
                                <div class="text-xs text-slate-400 font-mono">${c.id}</div>
                            </td>
                            <td class="p-3 text-right font-mono text-success">₦${parseFloat(c.savings_balance).toLocaleString()}</td>
                            <td class="p-3 text-right font-mono">${loanDisplay}</td>
                            <td class="p-3 text-right font-mono ${c.range_savings > 0 ? 'text-blue-600 dark:text-blue-400' : 'text-slate-300'}">${c.range_savings > 0 ? '₦'+parseFloat(c.range_savings).toLocaleString() : '-'}</td>
                            <td class="p-3 text-right font-mono ${c.range_collected > 0 ? 'text-purple-600 dark:text-purple-400' : 'text-slate-300'}">${c.range_collected > 0 ? '₦'+parseFloat(c.range_collected).toLocaleString() : '-'}</td>
                        </tr>
                    `}).join('');
                } else {
                    tbody.innerHTML = '<tr><td colspan="5" class="p-8 text-center text-slate-500">No matching clients.</td></tr>';
                }
            } catch(e) { console.error(e); }
        }

        function closeModal() {
            document.getElementById('detailModal').classList.add('hidden');
            currentUnion = null;
            document.getElementById('clientSearchInput').value = '';
        }

        function renderCharts() {
            const ctxT = document.getElementById('trendChart').getContext('2d');
            const ctxC = document.getElementById('compChart').getContext('2d');
            const isDark = document.documentElement.classList.contains('dark');
            const textColor = isDark ? '#94a3b8' : '#64748b';
            const gridColor = isDark ? '#334155' : '#e2e8f0';

            if(charts.trend) charts.trend.destroy();
            charts.trend = new Chart(ctxT, {
                type: 'line',
                data: {
                    labels: chartData.daily.map(d => d.date),
                    datasets: [
                        { label: 'Coll.', data: chartData.daily.map(d => d.collections), borderColor: '#8b5cf6', backgroundColor: '#8b5cf6', tension: 0.3, pointRadius: 2 },
                        { label: 'Save', data: chartData.daily.map(d => d.savings), borderColor: '#22c55e', backgroundColor: '#22c55e', tension: 0.3, pointRadius: 2 }
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
            charts.comp = new Chart(ctxC, {
                type: 'doughnut',
                data: {
                    labels: ['Savings', 'Disbursed', 'Collected'],
                    datasets: [{
                        data: [chartData.comp.Savings, chartData.comp.Disbursements, chartData.comp.Collections],
                        backgroundColor: ['#22c55e', '#f59e0b', '#8b5cf6'], borderWidth: 0
                    }]
                },
                options: {
                    responsive: true, maintainAspectRatio: false, cutout: '70%',
                    plugins: { legend: { position: 'right', labels: { color: textColor, boxWidth: 12 } } }
                }
            });
        }

        function animate(id, end, isCurrency = false) {
            const el = document.getElementById(id); if(!el) return;
            const start = 0, duration = 1000; let startTime = null;
            function step(timestamp) {
                if(!startTime) startTime = timestamp;
                const progress = Math.min((timestamp - startTime) / duration, 1);
                const val = Math.floor(progress * (end - start) + start);
                el.innerText = isCurrency ? val.toLocaleString() : val;
                if(progress < 1) requestAnimationFrame(step);
                else el.innerText = isCurrency ? end.toLocaleString() : end;
            }
            requestAnimationFrame(step);
        }
    </script>
</body>
</html>