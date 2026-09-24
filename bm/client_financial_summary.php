<?php
date_default_timezone_set('Africa/Lagos');
// bm/client_financial_summary.php
session_start();

// --- DATABASE CONNECTION ---
require_once '../includes/config.php';
$pdo = getDbConnection(); 

// --- AUTHENTICATION ---
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'bm') {
    header('Location: ../index.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'] ?? '';
$base_path = '../';

// --- DATA LOADING ---

// 1. Fetch BM's Branch ID & Profile
$stmt_b = $pdo->prepare("
    SELECT u.branch_id, u.full_name, u.profile_pic, b.name as branch_name 
    FROM users u 
    LEFT JOIN branches b ON u.branch_id = b.id 
    WHERE u.id = ?
");
$stmt_b->execute([$user_id]);
$user_data = $stmt_b->fetch(PDO::FETCH_ASSOC);

$bm_branch_id = $user_data['branch_id'] ?? 0;
$full_name = !empty($user_data['full_name']) ? $user_data['full_name'] : 'Branch Manager';
$profile_pic = !empty($user_data['profile_pic']) ? $user_data['profile_pic'] : 'default_avatar.png';
$branch_name = $user_data['branch_name'] ?? 'Unknown Branch';

if (!$bm_branch_id) {
    die("Access denied: No branch assigned to this manager.");
}

// 2. Fetch active Credit Officers in this branch for the filter dropdown
$stmt_co = $pdo->prepare("SELECT username, full_name FROM users WHERE branch_id = ? AND role = 'co' AND status = 'active' ORDER BY full_name ASC");
$stmt_co->execute([$bm_branch_id]);
$branch_cos = $stmt_co->fetchAll(PDO::FETCH_ASSOC);

$co_names = [];
foreach ($branch_cos as $co) {
    $co_names[$co['username']] = $co['full_name'] ?: $co['username'];
}

// Handle Filters (CO & Date)
$selected_co = $_GET['co'] ?? 'all';
$selected_co_name = ($selected_co !== 'all' && isset($co_names[$selected_co])) ? $co_names[$selected_co] : 'Branch';

$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';

// Build dynamic subtitle based on dates
$subtitle_date = date('M d, Y');
if (!empty($start_date) && !empty($end_date)) {
    $subtitle_date = date('M d, Y', strtotime($start_date)) . ' to ' . date('M d, Y', strtotime($end_date));
} elseif (!empty($start_date)) {
    $subtitle_date = 'From ' . date('M d, Y', strtotime($start_date));
} elseif (!empty($end_date)) {
    $subtitle_date = 'Until ' . date('M d, Y', strtotime($end_date));
}

// 3. Fetch Clients (Filtered by Branch, optionally by CO and Date)
$query = "
    SELECT 
        c.id, 
        c.name, 
        c.phone, 
        c.`union`,
        c.officer_username,
        COALESCE(u.full_name, c.officer_username) as officer_name,
        (SELECT balance FROM saving_balances WHERE client_id = c.id LIMIT 1) as total_savings,
        COALESCE(l.active_balance, 0) as loan_balance,
        COALESCE(l.original_principal, 0) as loan_principal
    FROM clients c
    LEFT JOIN users u ON c.officer_username = u.username
    LEFT JOIN (
        SELECT 
            client_id, 
            SUM(remaining_balance) as active_balance,
            SUM(principal) as original_principal
        FROM disbursements 
        WHERE remaining_balance > 0
        GROUP BY client_id
    ) l ON c.id = l.client_id
    WHERE c.branch_id = ? AND c.status = 'active'
";

$params = [$bm_branch_id];

// Apply CO Filter
if ($selected_co !== 'all') {
    $query .= " AND c.officer_username = ?";
    $params[] = $selected_co;
}

// Apply Date Filters (filtering by client registration date)
if (!empty($start_date)) {
    $query .= " AND DATE(c.created_at) >= ?";
    $params[] = $start_date;
}
if (!empty($end_date)) {
    $query .= " AND DATE(c.created_at) <= ?";
    $params[] = $end_date;
}

$query .= " ORDER BY c.`union` ASC, c.name ASC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);

// Initialize Grouping Arrays
$unions_data = [];
$grand_totals = [
    'savings' => 0,
    'loans' => 0,
    'net' => 0,
    'clients_count' => 0,
    'debtors_count' => 0
];

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $cid = $row['id'];
    $u_name = $row['union'] ?: 'Unassigned';
    $officer_display = explode(' ', trim($row['officer_name']))[0]; 
    
    // Parse Values
    $client_savings = floatval($row['total_savings'] ?? 0);
    $active_loan = floatval($row['loan_balance'] ?? 0); 
    $principal = floatval($row['loan_principal'] ?? 0);
    
    // Calculate Progress
    $loan_progress = 0;
    $has_active_loan = ($active_loan > 0);
    
    if ($has_active_loan && $principal > 0) {
        $repaid = max(0, $principal - $active_loan);
        $loan_progress = min(100, round(($repaid / $principal) * 100));
    }

    $net_position = $client_savings - $active_loan;

    // Determine Status
    $status_type = 'saver'; 
    if ($has_active_loan) $status_type = 'debtor';
    if ($net_position < 0) $status_type = 'risk';

    // Client Data Object
    $client_data = [
        'id' => $cid,
        'name' => $row['name'],
        'phone' => $row['phone'] ?? '',
        'officer' => $officer_display,
        'savings' => $client_savings,
        'loan_balance' => $active_loan,
        'loan_progress' => $loan_progress,
        'net_position' => $net_position,
        'has_loan' => $has_active_loan,
        'status_type' => $status_type
    ];

    // Initialize Union Key
    if (!isset($unions_data[$u_name])) {
        $unions_data[$u_name] = [
            'name' => $u_name,
            'clients' => [],
            'totals' => ['savings' => 0, 'loans' => 0, 'net' => 0, 'count' => 0]
        ];
    }

    // Add to Union
    $unions_data[$u_name]['clients'][] = $client_data;
    $unions_data[$u_name]['totals']['savings'] += $client_savings;
    $unions_data[$u_name]['totals']['loans'] += $active_loan;
    $unions_data[$u_name]['totals']['net'] += $net_position;
    $unions_data[$u_name]['totals']['count']++;

    // Add to Grand Totals
    $grand_totals['savings'] += $client_savings;
    $grand_totals['loans'] += $active_loan;
    $grand_totals['net'] += $net_position;
    $grand_totals['clients_count']++;
    if ($has_active_loan) $grand_totals['debtors_count']++;
}
?>
<!DOCTYPE html>
<html lang="en" class="antialiased">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#3b82f6">
    <title>Branch Financial Summary | CUPAD BM</title>
    
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">    
    <script src="https://cdn.tailwindcss.com"></script>

    <script>
    tailwind.config = {
        darkMode: 'class',
        theme: {
            extend: {
                fontFamily: { sans: ['Inter', 'sans-serif'] },
                colors: {
                    primary: '#3b82f6',
                    secondary: '#8b5cf6',
                    dark: { 900: '#0f172a', 800: '#1e293b', 700: '#334155' }
                }
            }
        }
    }
    </script>

    <style>
        body { padding-bottom: 90px; -webkit-tap-highlight-color: transparent; }

        /* Custom Scrollbar */
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        .dark ::-webkit-scrollbar-thumb { background: #475569; }

        /* Hide generic calendar icon in date inputs for better cross-browser styling */
        input[type="date"]::-webkit-calendar-picker-indicator {
            background: transparent;
            bottom: 0; color: transparent; cursor: pointer; height: auto; left: 0; position: absolute; right: 0; top: 0; width: auto;
        }

        /* Glassmorphism Header & Controls */
        .glass-header {
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border-bottom: 1px solid rgba(226, 232, 240, 0.8);
        }
        .dark .glass-header {
            background: rgba(15, 23, 42, 0.85);
            border-bottom: 1px solid rgba(51, 65, 85, 0.8);
        }

        .glass-controls {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border-bottom: 1px solid rgba(226, 232, 240, 0.6);
        }
        .dark .glass-controls {
            background: rgba(30, 41, 59, 0.9);
            border-bottom: 1px solid rgba(51, 65, 85, 0.6);
        }

        /* Dashboard Cards */
        .dash-card { transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1), box-shadow 0.3s; }
        .dash-card:hover { transform: translateY(-3px); }
        .bg-icon { position: absolute; right: -5%; bottom: -15%; font-size: 7rem; opacity: 0.15; transform: rotate(-15deg); pointer-events: none; transition: transform 0.5s ease; }
        .dash-card:hover .bg-icon { transform: rotate(0deg) scale(1.1); opacity: 0.2; }

        /* Client Card Hover Effects */
        .client-card { transition: all 0.2s ease-in-out; border-left: 4px solid transparent; }
        .client-card:hover { transform: translateY(-2px); box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.1); }
        .dark .client-card:hover { box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.3); }

        .status-saver { border-left-color: #22c55e; }
        .status-debtor { border-left-color: #f59e0b; }
        .status-risk { border-left-color: #ef4444; }

        /* Animations */
        @keyframes fadeInDown { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
        .animate-fade-in { animation: fadeInDown 0.3s ease-out forwards; }
        
        .union-content { display: block; overflow: hidden; max-height: 5000px; transition: max-height 0.4s ease-in-out, opacity 0.4s ease-in-out; opacity: 1; }
        .union-content.hidden-smooth { max-height: 0; opacity: 0; pointer-events: none; padding-top: 0 !important; padding-bottom: 0 !important; }
        
        .chevron-icon { transition: transform 0.3s ease; }
        .collapsed .chevron-icon { transform: rotate(-90deg); }

        /* Print styling */
        @media print {
            .no-print, .glass-controls, nav, header { display: none !important; }
            body { background: white; color: black; padding: 0; }
            .dash-card { break-inside: avoid; background: none !important; border: 1px solid #ccc; color: black !important; box-shadow: none !important; }
            .dash-card * { color: black !important; }
            .view-grid { display: none !important; }
            .view-table { display: block !important; }
            table { width: 100%; border-collapse: collapse; }
            th, td { border: 1px solid #ddd; padding: 8px; }
            .union-content { max-height: none !important; opacity: 1 !important; display: block !important; }
        }
    </style>
</head>

<body class="bg-gray-50 text-gray-800 dark:bg-dark-900 dark:text-gray-100 transition-colors duration-200">

    <!-- HEADER -->
    <header class="glass-header sticky top-0 z-50 pt-[env(safe-area-inset-top)]">
        <nav class="flex justify-between items-center px-4 py-3 max-w-7xl mx-auto">
            <a href="dashboard.php" class="flex items-center gap-2 text-xl font-bold text-blue-600 dark:text-blue-400 decoration-none">
                <img src="<?php echo htmlspecialchars($base_path . 'favicon.ico'); ?>" alt="Logo" class="h-8 w-auto drop-shadow-md">
                <span>CUPAD BM</span>
            </a>
            
            <div class="flex items-center gap-3">
                <button id="theme-toggle" class="p-2.5 rounded-full text-gray-500 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-slate-800 transition-colors">
                    <i class="fas fa-moon dark:hidden text-lg"></i>
                    <i class="fas fa-sun hidden dark:inline text-lg"></i>
                </button>
                
                <a href="profile.php" class="relative w-10 h-10 rounded-full border-2 border-blue-500 overflow-hidden bg-gray-200 dark:bg-slate-700 hover:ring-2 hover:ring-blue-300 transition-all">
                    <?php if (isset($profile_pic) && $profile_pic !== 'default_avatar.png'): ?>
                        <img src="<?php echo $base_path . $profile_pic; ?>" alt="Profile" class="w-full h-full object-cover"> 
                    <?php else: ?>
                        <div class="w-full h-full flex items-center justify-center text-gray-500 dark:text-gray-300">
                            <i class="fas fa-user"></i>
                        </div>
                    <?php endif; ?>
                </a>

                <a href="<?php echo htmlspecialchars($base_path . 'logout.php'); ?>" class="hidden sm:flex items-center gap-2 p-2 px-3 text-sm font-medium text-red-600 bg-red-50 hover:bg-red-100 dark:text-red-400 dark:bg-red-900/20 dark:hover:bg-red-900/40 rounded-full transition-colors" title="Logout">
                    <i class="fas fa-sign-out-alt"></i> <span>Logout</span>
                </a>
            </div>
        </nav>
    </header>

    <main class="max-w-7xl mx-auto px-4 py-6">
        
        <!-- Page Header -->
        <div class="flex flex-col md:flex-row justify-between items-start md:items-end mb-6 gap-4">
            <div>
                <h1 class="text-3xl font-extrabold text-gray-900 dark:text-white tracking-tight">
                    <?php echo $selected_co === 'all' ? 'Branch Summary' : htmlspecialchars($selected_co_name) . "'s Report"; ?>
                </h1>
                <div class="flex flex-wrap items-center gap-2 mt-2 text-sm text-gray-500 dark:text-gray-400 font-medium">
                    <span class="flex items-center gap-1"><i class="fas fa-building text-gray-400"></i> <?php echo htmlspecialchars($branch_name); ?></span>
                    <span class="hidden sm:inline">•</span>
                    <span class="flex items-center gap-1 text-blue-600 dark:text-blue-400 bg-blue-50 dark:bg-blue-900/30 px-2 py-0.5 rounded-md">
                        <i class="fas fa-calendar-alt"></i> <?php echo $subtitle_date; ?>
                    </span>
                    <span class="hidden sm:inline">•</span>
                    <span class="flex items-center gap-1"><i class="fas fa-users text-gray-400"></i> <?php echo number_format($grand_totals['clients_count']); ?> Clients</span>
                </div>
            </div>
            <button onclick="window.print()" class="no-print bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 text-gray-700 dark:text-gray-200 font-semibold hover:bg-gray-50 dark:hover:bg-slate-700 px-4 py-2 rounded-xl transition-all shadow-sm flex items-center gap-2">
                <i class="fas fa-print text-blue-500"></i> Print Report
            </button>
        </div>
        
        <!-- DASHBOARD STATS -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-5 mb-8">
            <!-- Total Savings -->
            <div class="dash-card rounded-2xl p-6 relative overflow-hidden bg-gradient-to-br from-blue-500 to-blue-700 shadow-lg shadow-blue-500/30 text-white">
                <i class="fas fa-piggy-bank bg-icon"></i>
                <div class="relative z-10">
                    <div class="text-blue-100 font-semibold uppercase tracking-wider text-xs mb-1">
                        <?php echo $selected_co === 'all' ? 'Branch Total Savings' : 'Officer Total Savings'; ?>
                    </div>
                    <div class="text-4xl font-black">₦<?php echo number_format($grand_totals['savings']); ?></div>
                </div>
            </div>

            <!-- Outstanding Loans -->
            <div class="dash-card rounded-2xl p-6 relative overflow-hidden bg-gradient-to-br from-orange-400 to-orange-600 shadow-lg shadow-orange-500/30 text-white">
                <i class="fas fa-hand-holding-usd bg-icon"></i>
                <div class="relative z-10">
                    <div class="text-orange-100 font-semibold uppercase tracking-wider text-xs mb-1">Active Outstanding Loans</div>
                    <div class="text-4xl font-black">₦<?php echo number_format($grand_totals['loans']); ?></div>
                </div>
            </div>

            <!-- Client Base -->
            <div class="dash-card rounded-2xl p-6 relative overflow-hidden bg-gradient-to-br from-indigo-500 to-purple-600 shadow-lg shadow-indigo-500/30 text-white">
                <i class="fas fa-users bg-icon"></i>
                <div class="relative z-10">
                    <div class="text-indigo-100 font-semibold uppercase tracking-wider text-xs mb-1">Active Client Base</div>
                    <div class="flex items-baseline gap-2">
                        <div class="text-4xl font-black"><?php echo number_format($grand_totals['clients_count']); ?></div>
                        <div class="text-sm text-indigo-100 font-medium">members</div>
                    </div>
                    <div class="mt-2 text-xs font-medium bg-white/20 inline-block px-2 py-1 rounded-lg backdrop-blur-sm">
                        <i class="fas fa-exclamation-circle mr-1"></i> <?php echo number_format($grand_totals['debtors_count']); ?> with Active Loans
                    </div>
                </div>
            </div>
        </div>

        <!-- STICKY FILTER CONTROLS -->
        <div class="no-print glass-controls sticky top-[60px] md:top-[70px] z-40 -mx-4 px-4 py-4 mb-8 shadow-sm">
            <div class="flex flex-col gap-4">
                
                <div class="flex flex-col lg:flex-row gap-3 w-full">
                    <!-- Data Filters Form -->
                    <form method="GET" class="flex flex-col sm:flex-row flex-wrap xl:flex-nowrap gap-3 w-full lg:w-auto">
                        
                        <!-- Officer Dropdown -->
                        <div class="relative w-full sm:w-auto min-w-[200px]">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-gray-400">
                                <i class="fas fa-user-tie"></i>
                            </div>
                            <select name="co" class="block w-full pl-10 pr-10 py-2.5 text-sm bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-600 rounded-xl appearance-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-shadow text-gray-700 dark:text-gray-200 cursor-pointer shadow-sm">
                                <option value="all">All Branch Officers</option>
                                <?php foreach($branch_cos as $co): ?>
                                    <option value="<?php echo htmlspecialchars($co['username']); ?>" <?php echo $selected_co === $co['username'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($co['full_name'] ?: $co['username']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="absolute inset-y-0 right-0 pr-3 flex items-center pointer-events-none text-gray-400">
                                <i class="fas fa-chevron-down text-xs"></i>
                            </div>
                        </div>

                        <!-- Date Range -->
                        <div class="flex items-center gap-2 w-full sm:w-auto">
                            <div class="relative flex-1 sm:flex-none">
                                <input type="date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>" 
                                       class="block w-full px-3 py-2.5 text-sm bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-600 rounded-xl focus:ring-2 focus:ring-blue-500 transition-shadow text-gray-700 dark:text-gray-200 shadow-sm relative z-10" 
                                       title="Registration Start Date">
                                <?php if(empty($start_date)): ?>
                                <div class="absolute inset-0 flex items-center px-3 pointer-events-none z-20 text-gray-400 dark:text-gray-500 bg-white dark:bg-slate-800 rounded-xl">
                                    <i class="fas fa-calendar-alt mr-2"></i> <span class="text-sm">Start Date</span>
                                </div>
                                <?php endif; ?>
                            </div>
                            
                            <span class="text-gray-400 dark:text-gray-500 font-medium text-sm">to</span>
                            
                            <div class="relative flex-1 sm:flex-none">
                                <input type="date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>" 
                                       class="block w-full px-3 py-2.5 text-sm bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-600 rounded-xl focus:ring-2 focus:ring-blue-500 transition-shadow text-gray-700 dark:text-gray-200 shadow-sm relative z-10" 
                                       title="Registration End Date">
                                <?php if(empty($end_date)): ?>
                                <div class="absolute inset-0 flex items-center px-3 pointer-events-none z-20 text-gray-400 dark:text-gray-500 bg-white dark:bg-slate-800 rounded-xl">
                                    <i class="fas fa-calendar-check mr-2"></i> <span class="text-sm">End Date</span>
                                </div>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Apply/Clear Actions -->
                            <div class="flex items-center gap-1 shrink-0">
                                <button type="submit" class="p-2.5 px-4 bg-blue-600 hover:bg-blue-700 text-white rounded-xl text-sm font-semibold transition-colors shadow-sm flex items-center gap-2">
                                    <i class="fas fa-check"></i> <span class="hidden sm:inline">Apply</span>
                                </button>
                                
                                <?php if(!empty($start_date) || !empty($end_date) || $selected_co !== 'all'): ?>
                                    <a href="client_financial_summary.php" class="p-2.5 px-3 bg-gray-100 hover:bg-gray-200 dark:bg-slate-700 dark:hover:bg-slate-600 text-gray-600 dark:text-gray-300 rounded-xl transition-colors shadow-sm" title="Clear Filters">
                                        <i class="fas fa-undo-alt"></i>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </form>

                    <!-- Client Search -->
                    <div class="flex-1 min-w-[200px] relative">
                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none text-gray-400">
                            <i class="fas fa-search"></i>
                        </div>
                        <input type="text" id="clientSearch" class="block w-full pl-11 pr-4 py-2.5 text-sm bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-600 rounded-xl focus:ring-2 focus:ring-blue-500 transition-shadow text-gray-800 dark:text-gray-100 shadow-sm" placeholder="Search clients by name, phone...">
                    </div>

                    <!-- View Toggle -->
                    <div class="hidden sm:flex bg-gray-200/50 dark:bg-slate-800 p-1 rounded-xl shrink-0 border border-gray-200 dark:border-slate-700">
                        <button class="view-btn active px-3 py-1.5 rounded-lg text-sm font-medium transition-colors text-gray-500 dark:text-gray-400 focus:outline-none" id="btnGrid" onclick="toggleView('grid')" title="Grid View">
                            <i class="fas fa-th-large"></i>
                        </button>
                        <button class="view-btn px-3 py-1.5 rounded-lg text-sm font-medium transition-colors text-gray-500 dark:text-gray-400 focus:outline-none" id="btnTable" onclick="toggleView('table')" title="List View">
                            <i class="fas fa-list"></i>
                        </button>
                    </div>
                </div>

                <!-- Client Status Tabs -->
                <div class="flex gap-2 overflow-x-auto pb-1 scrollbar-hide" id="filterTabs">
                    <button class="filter-chip active px-4 py-1.5 rounded-full text-sm font-semibold bg-blue-600 text-white shadow-md shadow-blue-500/20 transition-all border border-transparent whitespace-nowrap" data-filter="all">All Clients</button>
                    <button class="filter-chip px-4 py-1.5 rounded-full text-sm font-semibold bg-white dark:bg-slate-800 text-gray-600 dark:text-gray-300 border border-gray-200 dark:border-slate-700 hover:bg-gray-50 dark:hover:bg-slate-700 transition-all whitespace-nowrap" data-filter="debtor">With Loans</button>
                    <button class="filter-chip px-4 py-1.5 rounded-full text-sm font-semibold bg-white dark:bg-slate-800 text-gray-600 dark:text-gray-300 border border-gray-200 dark:border-slate-700 hover:bg-gray-50 dark:hover:bg-slate-700 transition-all whitespace-nowrap" data-filter="saver">Debt Free</button>
                    <button class="filter-chip px-4 py-1.5 rounded-full text-sm font-semibold bg-white dark:bg-slate-800 text-gray-600 dark:text-gray-300 border border-gray-200 dark:border-slate-700 hover:bg-gray-50 dark:hover:bg-slate-700 transition-all whitespace-nowrap" data-filter="risk">High Risk</button>
                </div>
            </div>
        </div>

        <!-- UNION LIST -->
        <div id="unionsContainer" class="space-y-6">
            <?php foreach ($unions_data as $union_name => $data): ?>
            <div class="union-section bg-white dark:bg-slate-800 rounded-2xl shadow-sm border border-gray-100 dark:border-slate-700 overflow-hidden" data-union-name="<?php echo htmlspecialchars(strtolower($union_name)); ?>">
                
                <!-- Collapsible Header -->
                <div class="union-header flex justify-between items-center p-4 cursor-pointer hover:bg-gray-50 dark:hover:bg-slate-750 transition-colors select-none" onclick="toggleUnion(this)">
                    <div class="flex items-center gap-4">
                        <div class="w-12 h-12 rounded-xl bg-blue-100 dark:bg-blue-900/40 text-blue-600 dark:text-blue-400 flex items-center justify-center font-bold text-lg shadow-inner">
                            <?php echo strtoupper(substr($union_name, 0, 1)); ?>
                        </div>
                        <div>
                            <div class="font-bold text-lg text-gray-900 dark:text-white"><?php echo htmlspecialchars($union_name); ?></div>
                            <div class="text-sm font-medium text-gray-500 dark:text-gray-400 mt-0.5">
                                <i class="fas fa-users mr-1"></i> <?php echo $data['totals']['count']; ?> Members
                            </div>
                        </div>
                    </div>
                    <div class="flex items-center gap-4">
                        <div class="hidden md:flex items-center gap-6 text-sm text-gray-500 dark:text-gray-400 font-medium mr-4">
                            <span>Total Sav: <span class="text-green-600 dark:text-green-400 font-bold">₦<?php echo number_format($data['totals']['savings']); ?></span></span>
                            <span>Total Ln: <span class="text-red-500 dark:text-red-400 font-bold">₦<?php echo number_format($data['totals']['loans']); ?></span></span>
                        </div>
                        <div class="w-8 h-8 rounded-full bg-gray-100 dark:bg-slate-700 flex items-center justify-center text-gray-500">
                            <i class="fas fa-chevron-down chevron-icon"></i>
                        </div>
                    </div>
                </div>

                <div class="union-content border-t border-gray-100 dark:border-slate-700 bg-gray-50/50 dark:bg-slate-900/20">
                    
                    <!-- Union Summary Mobile Cards -->
                    <div class="p-4 md:hidden">
                        <div class="grid grid-cols-3 gap-2">
                            <div class="bg-green-50 dark:bg-green-900/20 rounded-xl p-2 text-center border border-green-100 dark:border-green-900/30">
                                <div class="text-[0.65rem] font-bold text-green-600 uppercase mb-1">Savings</div>
                                <div class="text-sm font-bold text-green-700 dark:text-green-400 truncate">₦<?php echo number_format($data['totals']['savings']); ?></div>
                            </div>
                            <div class="bg-red-50 dark:bg-red-900/20 rounded-xl p-2 text-center border border-red-100 dark:border-red-900/30">
                                <div class="text-[0.65rem] font-bold text-red-600 uppercase mb-1">Loans</div>
                                <div class="text-sm font-bold text-red-700 dark:text-red-400 truncate">₦<?php echo number_format($data['totals']['loans']); ?></div>
                            </div>
                            <div class="bg-blue-50 dark:bg-blue-900/20 rounded-xl p-2 text-center border border-blue-100 dark:border-blue-900/30">
                                <div class="text-[0.65rem] font-bold text-blue-600 uppercase mb-1">Net</div>
                                <div class="text-sm font-bold text-blue-700 dark:text-blue-400 truncate">₦<?php echo number_format($data['totals']['net']); ?></div>
                            </div>
                        </div>
                    </div>

                    <!-- Client Grid View -->
                    <div class="view-grid p-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
                        <?php foreach ($data['clients'] as $c): 
                            $initial = strtoupper(substr($c['name'], 0, 1));
                            $status_class = 'status-' . $c['status_type'];
                            $net_class = $c['net_position'] >= 0 ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400' : 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400';
                        ?>
                        <a href="history.php?client_id=<?php echo $c['id']; ?>" 
                           class="client-card client-item <?php echo $status_class; ?> bg-white dark:bg-slate-800 rounded-xl p-4 border border-gray-200 dark:border-slate-700 flex flex-col gap-3 relative overflow-hidden group" 
                           data-name="<?php echo htmlspecialchars(strtolower($c['name'])); ?>" 
                           data-phone="<?php echo htmlspecialchars($c['phone']); ?>"
                           data-officer="<?php echo htmlspecialchars(strtolower($c['officer'])); ?>"
                           data-status="<?php echo $c['status_type']; ?>">
                            
                            <!-- Net Badge -->
                            <div class="absolute top-3 right-3 text-[0.65rem] font-bold px-2 py-1 rounded-md <?php echo $net_class; ?>">
                                <?php echo $c['net_position'] >= 0 ? '+' : ''; ?>₦<?php echo number_format($c['net_position']); ?>
                            </div>

                            <div class="flex items-center gap-3 pr-16">
                                <div class="w-10 h-10 rounded-full bg-gray-100 dark:bg-slate-700 text-gray-700 dark:text-gray-200 flex items-center justify-center font-bold flex-shrink-0 border border-gray-200 dark:border-slate-600">
                                    <?php echo $initial; ?>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <div class="font-bold text-gray-900 dark:text-white truncate group-hover:text-blue-600 dark:group-hover:text-blue-400 transition-colors"><?php echo htmlspecialchars($c['name']); ?></div>
                                    <div class="text-xs text-gray-500 mt-0.5 flex items-center gap-1 font-medium">
                                        <i class="fas fa-user-tie text-blue-400"></i> <?php echo htmlspecialchars($c['officer']); ?>
                                    </div>
                                </div>
                            </div>

                            <div class="flex justify-between bg-gray-50 dark:bg-slate-900/50 p-2.5 rounded-lg border border-gray-100 dark:border-slate-700 mt-1">
                                <div class="text-center flex-1 border-r border-gray-200 dark:border-slate-600">
                                    <div class="text-[0.65rem] uppercase text-gray-500 font-bold tracking-wide">Saved</div>
                                    <div class="font-bold text-green-600 dark:text-green-500 text-sm mt-0.5">₦<?php echo number_format($c['savings']); ?></div>
                                </div>
                                <div class="text-center flex-1">
                                    <div class="text-[0.65rem] uppercase text-gray-500 font-bold tracking-wide">Loan</div>
                                    <div class="font-bold <?php echo $c['loan_balance'] > 0 ? 'text-red-500' : 'text-gray-400'; ?> text-sm mt-0.5">
                                        ₦<?php echo number_format($c['loan_balance']); ?>
                                    </div>
                                </div>
                            </div>

                            <?php if ($c['has_loan']): ?>
                            <div class="mt-auto pt-2">
                                <div class="flex justify-between text-[0.65rem] text-gray-500 dark:text-gray-400 mb-1 font-bold">
                                    <span>Repayment</span>
                                    <span class="<?php echo $c['loan_progress'] >= 75 ? 'text-green-500' : 'text-orange-500'; ?>"><?php echo $c['loan_progress']; ?>%</span>
                                </div>
                                <div class="w-full h-1.5 bg-gray-200 dark:bg-slate-700 rounded-full overflow-hidden">
                                    <div class="h-full rounded-full <?php echo $c['loan_progress'] >= 75 ? 'bg-green-500' : 'bg-gradient-to-r from-orange-400 to-red-500'; ?>" style="width: <?php echo $c['loan_progress']; ?>%;"></div>
                                </div>
                            </div>
                            <?php else: ?>
                                <div class="mt-auto pt-2 flex items-center justify-center gap-1.5 text-xs text-gray-500 dark:text-gray-400 font-medium bg-green-50 dark:bg-green-900/10 py-1.5 rounded-lg border border-green-100 dark:border-green-900/20">
                                    <i class="fas fa-check-circle text-green-500"></i> Saver / Debt Free
                                </div>
                            <?php endif; ?>
                        </a>
                        <?php endforeach; ?>
                    </div>

                    <!-- Client Table View -->
                    <div class="view-table hidden p-4">
                        <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800 shadow-sm">
                            <table class="w-full text-sm text-left">
                                <thead class="bg-gray-50 dark:bg-slate-900/50 text-gray-500 dark:text-gray-400 uppercase text-[0.7rem] font-bold tracking-wider">
                                    <tr>
                                        <th class="px-4 py-3">Client</th>
                                        <th class="px-4 py-3">Officer</th>
                                        <th class="px-4 py-3 text-right">Savings</th>
                                        <th class="px-4 py-3 text-right">Loan Bal</th>
                                        <th class="px-4 py-3 text-right">Net Pos</th>
                                        <th class="px-4 py-3 text-center">Status</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200 dark:divide-slate-700">
                                    <?php foreach ($data['clients'] as $c): 
                                        $initial = strtoupper(substr($c['name'], 0, 1));
                                    ?>
                                    <tr onclick="window.location.href='history.php?client_id=<?php echo $c['id']; ?>'" 
                                        class="client-item hover:bg-gray-50 dark:hover:bg-slate-750 cursor-pointer transition-colors group"
                                        data-name="<?php echo htmlspecialchars(strtolower($c['name'])); ?>" 
                                        data-phone="<?php echo htmlspecialchars($c['phone']); ?>"
                                        data-officer="<?php echo htmlspecialchars(strtolower($c['officer'])); ?>"
                                        data-status="<?php echo $c['status_type']; ?>">
                                        
                                        <td class="px-4 py-3 whitespace-nowrap">
                                            <div class="flex items-center gap-3">
                                                <div class="w-8 h-8 rounded-full bg-gray-100 dark:bg-slate-700 flex items-center justify-center font-bold text-gray-700 dark:text-gray-300 text-xs">
                                                    <?php echo $initial; ?>
                                                </div>
                                                <div>
                                                    <div class="font-bold text-gray-900 dark:text-white group-hover:text-blue-600 dark:group-hover:text-blue-400 transition-colors"><?php echo htmlspecialchars($c['name']); ?></div>
                                                    <div class="text-[0.7rem] text-gray-500"><?php echo htmlspecialchars($c['phone']); ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        
                                        <td class="px-4 py-3 whitespace-nowrap">
                                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md text-xs font-medium bg-blue-50 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400 border border-blue-100 dark:border-blue-800">
                                                <i class="fas fa-user-tie"></i> <?php echo htmlspecialchars($c['officer']); ?>
                                            </span>
                                        </td>
                                        
                                        <td class="px-4 py-3 whitespace-nowrap text-right font-mono font-bold text-green-600 dark:text-green-500">
                                            ₦<?php echo number_format($c['savings']); ?>
                                        </td>
                                        
                                        <td class="px-4 py-3 whitespace-nowrap text-right font-mono font-bold <?php echo $c['loan_balance'] > 0 ? 'text-red-500' : 'text-gray-400'; ?>">
                                            ₦<?php echo number_format($c['loan_balance']); ?>
                                        </td>
                                        
                                        <td class="px-4 py-3 whitespace-nowrap text-right font-mono font-bold <?php echo $c['net_position'] >= 0 ? 'text-blue-600 dark:text-blue-400' : 'text-orange-500'; ?>">
                                            ₦<?php echo number_format($c['net_position']); ?>
                                        </td>
                                        
                                        <td class="px-4 py-3 whitespace-nowrap text-center">
                                            <?php if($c['has_loan']): ?>
                                                <span class="inline-flex px-2 py-1 text-[0.65rem] font-bold rounded-md bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400 border border-red-200 dark:border-red-800/50">Active Loan</span>
                                            <?php else: ?>
                                                <span class="inline-flex px-2 py-1 text-[0.65rem] font-bold rounded-md bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400 border border-green-200 dark:border-green-800/50">Saver</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Empty State -->
        <div id="noResults" class="hidden flex-col items-center justify-center py-16 text-center animate-fade-in">
            <div class="w-20 h-20 bg-gray-100 dark:bg-slate-800 rounded-full flex items-center justify-center mb-4 text-gray-400 dark:text-gray-500 border border-gray-200 dark:border-slate-700 shadow-sm">
                <i class="fas fa-search text-3xl"></i>
            </div>
            <h3 class="text-lg font-bold text-gray-900 dark:text-white mb-1">No clients found</h3>
            <p class="text-sm text-gray-500 max-w-sm">We couldn't find any clients matching your current search and filter criteria. Try clearing some filters.</p>
            <button onclick="document.getElementById('clientSearch').value=''; document.querySelector('.filter-chip[data-filter=\'all\']').click();" class="mt-4 px-4 py-2 bg-blue-50 text-blue-600 dark:bg-blue-900/30 dark:text-blue-400 font-semibold rounded-lg hover:bg-blue-100 dark:hover:bg-blue-900/50 transition-colors">
                Clear Search
            </button>
        </div>

        <!-- GRAND TOTAL FOOTER -->
        <?php if (!empty($unions_data)): ?>
        <div class="no-print mt-12 mb-4 p-6 bg-white dark:bg-slate-800 rounded-2xl border border-gray-200 dark:border-slate-700 shadow-sm relative overflow-hidden">
            <div class="absolute top-0 right-0 w-32 h-32 bg-gray-50 dark:bg-slate-700 rounded-bl-full -mr-16 -mt-16 opacity-50 z-0 pointer-events-none"></div>
            
            <h3 class="text-xs font-black text-gray-400 uppercase tracking-[0.2em] mb-6 relative z-10 flex items-center gap-2">
                <i class="fas fa-chart-pie"></i> Report Summary
            </h3>
            
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-6 relative z-10">
                <div class="border-l-4 border-green-500 pl-4">
                    <div class="text-[0.65rem] font-bold text-gray-500 uppercase mb-1">Total Savings</div>
                    <div class="text-2xl font-black text-gray-900 dark:text-white">₦<?php echo number_format($grand_totals['savings']); ?></div>
                </div>
                <div class="border-l-4 border-red-500 pl-4">
                    <div class="text-[0.65rem] font-bold text-gray-500 uppercase mb-1">Total Debt</div>
                    <div class="text-2xl font-black text-gray-900 dark:text-white">₦<?php echo number_format($grand_totals['loans']); ?></div>
                </div>
                <div class="border-l-4 border-blue-500 pl-4">
                    <div class="text-[0.65rem] font-bold text-gray-500 uppercase mb-1">Net Position</div>
                    <div class="text-2xl font-black <?php echo $grand_totals['net'] >= 0 ? 'text-blue-600 dark:text-blue-400' : 'text-orange-500'; ?>">
                        ₦<?php echo number_format($grand_totals['net']); ?>
                    </div>
                </div>
            </div>
            
            <div class="mt-6 pt-6 border-t border-gray-100 dark:border-slate-700 flex justify-between items-center text-[0.7rem] text-gray-400 font-medium relative z-10">
                <span>System Generated</span>
                <span><?php echo date('d M Y, h:i A'); ?></span>
            </div>
        </div>
        <?php endif; ?>

    </main>

    <!-- MOBILE NAV -->
    <nav class="md:hidden fixed bottom-0 left-0 right-0 bg-white dark:bg-slate-900 border-t border-gray-200 dark:border-slate-800 z-50 px-2 pb-[env(safe-area-inset-bottom)] shadow-[0_-4px_15px_rgba(0,0,0,0.05)]">
        <div class="flex justify-between items-center py-2">
            <a href="dashboard.php" class="flex flex-col items-center gap-1 w-1/5 text-gray-500 dark:text-gray-400 hover:text-blue-600 transition-colors">
                <i class="fas fa-home text-lg"></i>
                <span class="text-[0.65rem] font-medium">Home</span>
            </a>
            <a href="combined_collection.php" class="flex flex-col items-center gap-1 w-1/5 text-gray-500 dark:text-gray-400 hover:text-blue-600 transition-colors">
                <i class="fas fa-coins text-lg"></i>
                <span class="text-[0.65rem] font-medium">Collect</span>
            </a>
            <a href="client_financial_summary.php" class="flex flex-col items-center w-1/5 relative group">
                <div class="absolute -top-6 bg-blue-600 text-white w-12 h-12 rounded-full flex items-center justify-center border-4 border-gray-50 dark:border-dark-900 shadow-lg shadow-blue-500/40">
                    <i class="fas fa-file-invoice text-lg"></i>
                </div>
                <span class="text-[0.65rem] font-bold text-blue-600 mt-7">Summary</span>
            </a>
            <a href="clients.php" class="flex flex-col items-center gap-1 w-1/5 text-gray-500 dark:text-gray-400 hover:text-blue-600 transition-colors">
                <i class="fas fa-users text-lg"></i>
                <span class="text-[0.65rem] font-medium">Clients</span>
            </a>
            <a href="analytics.php" class="flex flex-col items-center gap-1 w-1/5 text-gray-500 dark:text-gray-400 hover:text-blue-600 transition-colors">
                <i class="fas fa-chart-bar text-lg"></i>
                <span class="text-[0.65rem] font-medium">Reports</span>
            </a>
        </div>
    </nav>

    <script>
        // Dark Mode Logic
        const t = localStorage.getItem('theme');
        if (t === 'dark' || (!t && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            document.documentElement.classList.add('dark');
        }
        
        document.getElementById('theme-toggle').addEventListener('click', () => {
            document.documentElement.classList.toggle('dark');
            localStorage.setItem('theme', document.documentElement.classList.contains('dark') ? 'dark' : 'light');
        });

        // View Toggling Logic (Grid / Table)
        function toggleView(viewType) {
            const gridContainers = document.querySelectorAll('.view-grid');
            const tableContainers = document.querySelectorAll('.view-table');
            const btnGrid = document.getElementById('btnGrid');
            const btnTable = document.getElementById('btnTable');

            // Reset active states
            [btnGrid, btnTable].forEach(btn => {
                if(btn) {
                    btn.classList.remove('bg-white', 'dark:bg-slate-700', 'shadow-sm', 'text-blue-600', 'dark:text-blue-400');
                    btn.classList.add('text-gray-500', 'dark:text-gray-400');
                }
            });

            if (viewType === 'grid') {
                gridContainers.forEach(el => el.classList.remove('hidden'));
                tableContainers.forEach(el => el.classList.add('hidden'));
                if(btnGrid) {
                    btnGrid.classList.add('bg-white', 'dark:bg-slate-700', 'shadow-sm', 'text-blue-600', 'dark:text-blue-400');
                    btnGrid.classList.remove('text-gray-500', 'dark:text-gray-400');
                }
                localStorage.setItem('bmClientViewPref', 'grid');
            } else {
                gridContainers.forEach(el => el.classList.add('hidden'));
                tableContainers.forEach(el => el.classList.remove('hidden'));
                if(btnTable) {
                    btnTable.classList.add('bg-white', 'dark:bg-slate-700', 'shadow-sm', 'text-blue-600', 'dark:text-blue-400');
                    btnTable.classList.remove('text-gray-500', 'dark:text-gray-400');
                }
                localStorage.setItem('bmClientViewPref', 'table');
            }
        }

        const savedView = localStorage.getItem('bmClientViewPref') || 'grid';
        toggleView(savedView);

        // Accordion Toggle
        function toggleUnion(header) {
            header.classList.toggle('collapsed');
            const content = header.nextElementSibling;
            content.classList.toggle('hidden-smooth');
        }

        // Filtering Logic
        let currentFilter = 'all';
        const filterBtns = document.querySelectorAll('.filter-chip');
        const searchInput = document.getElementById('clientSearch');

        // Status Tabs Logic
        filterBtns.forEach(btn => {
            btn.addEventListener('click', () => {
                // Reset all tabs
                filterBtns.forEach(b => {
                    b.classList.remove('bg-blue-600', 'text-white', 'shadow-md', 'border-transparent');
                    b.classList.add('bg-white', 'dark:bg-slate-800', 'text-gray-600', 'dark:text-gray-300', 'border-gray-200', 'dark:border-slate-700');
                });
                
                // Activate clicked tab
                btn.classList.add('bg-blue-600', 'text-white', 'shadow-md', 'border-transparent');
                btn.classList.remove('bg-white', 'dark:bg-slate-800', 'text-gray-600', 'dark:text-gray-300', 'border-gray-200', 'dark:border-slate-700');
                
                currentFilter = btn.dataset.filter;
                applyFilters();
            });
        });

        // Search Input Event
        searchInput.addEventListener('input', applyFilters);

        function applyFilters() {
            const searchTerm = searchInput.value.toLowerCase().trim();
            const unions = document.querySelectorAll('.union-section');
            let totalVisible = 0;

            unions.forEach(union => {
                const unionName = union.dataset.unionName || '';
                const items = union.querySelectorAll('.client-item');
                let visibleInUnion = 0;
                
                const unionMatch = unionName.includes(searchTerm);

                items.forEach(item => {
                    const name = item.dataset.name || '';
                    const phone = item.dataset.phone || '';
                    const officer = item.dataset.officer || '';
                    const status = item.dataset.status || '';
                    
                    // Tab Filter Match
                    let filterPass = (currentFilter === 'all') || 
                                     (currentFilter === 'debtor' && (status === 'debtor' || status === 'risk')) ||
                                     (currentFilter === 'risk' && status === 'risk') ||
                                     (currentFilter === 'saver' && status === 'saver');

                    // Text Search Match
                    let searchPass = unionMatch || name.includes(searchTerm) || phone.includes(searchTerm) || officer.includes(searchTerm);

                    const isRow = item.tagName === 'TR';
                    const displayStyle = isRow ? 'table-row' : 'flex';

                    if (filterPass && searchPass) {
                        item.style.display = displayStyle;
                        item.classList.add('animate-fade-in'); // simple enter animation
                        visibleInUnion++;
                        totalVisible++;
                    } else {
                        item.style.display = 'none';
                        item.classList.remove('animate-fade-in');
                    }
                });

                // Show/Hide Union Section wrapper
                if (visibleInUnion > 0) {
                    union.style.display = 'block';
                    // Auto-expand union if searching
                    if(searchTerm.length > 0 && visibleInUnion < 5) {
                        const header = union.querySelector('.union-header');
                        const content = union.querySelector('.union-content');
                        header.classList.remove('collapsed');
                        content.classList.remove('hidden-smooth');
                    }
                } else {
                    union.style.display = 'none';
                }
            });

            // Toggle Empty State
            const noResultsState = document.getElementById('noResults');
            if(noResultsState) {
                noResultsState.style.display = totalVisible === 0 ? 'flex' : 'none';
            }
        }
    </script>
</body>
</html>