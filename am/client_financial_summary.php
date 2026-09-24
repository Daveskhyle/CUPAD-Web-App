<?php
date_default_timezone_set('Africa/Lagos');
// am/client_financial_summary.php - Area Manager Client Financial Summary
session_start();

// --- DATABASE CONNECTION ---
require_once '../includes/config.php';
$pdo = getDbConnection(); 

// --- AUTHENTICATION ---
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'am') {
    header('Location: ../index.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'] ?? '';
$base_path = '../';

// --- DATA LOADING ---

// 1. Fetch AM's Area ID & Profile
$stmt_a = $pdo->prepare("
    SELECT u.area_id, u.full_name, u.profile_pic, a.name as area_name 
    FROM users u 
    LEFT JOIN areas a ON u.area_id = a.id 
    WHERE u.id = ?
");
$stmt_a->execute([$user_id]);
$user_data = $stmt_a->fetch(PDO::FETCH_ASSOC);

$am_area_id = $user_data['area_id'] ?? 0;
$full_name = !empty($user_data['full_name']) ? $user_data['full_name'] : 'Area Manager';
$profile_pic = !empty($user_data['profile_pic']) ? $user_data['profile_pic'] : 'default_avatar.png';
$area_name = $user_data['area_name'] ?? 'Unknown Area';

if (!$am_area_id) {
    die("Access denied: No area assigned to this manager.");
}

// 2. Fetch active Branches in this area for the filter dropdown
$stmt_br = $pdo->prepare("SELECT id, name FROM branches WHERE area_id = ? AND status = 'active' ORDER BY name ASC");
$stmt_br->execute([$am_area_id]);
$area_branches = $stmt_br->fetchAll(PDO::FETCH_ASSOC);

$branch_names =[];
foreach ($area_branches as $br) {
    $branch_names[$br['id']] = $br['name'];
}

// Handle Branch Filtering
$selected_branch = $_GET['branch'] ?? 'all';
$selected_branch_name = ($selected_branch !== 'all' && isset($branch_names[$selected_branch])) ? $branch_names[$selected_branch] : 'Area';

// 3. Fetch Clients (Filtered by Area and optionally by Branch)
$query = "
    SELECT 
        c.id, 
        c.name, 
        c.phone, 
        c.`union`,
        c.officer_username,
        b.name as branch_name,
        COALESCE(u.full_name, c.officer_username) as officer_name,
        (SELECT balance FROM saving_balances WHERE client_id = c.id LIMIT 1) as total_savings,
        COALESCE(l.active_balance, 0) as loan_balance,
        COALESCE(l.original_principal, 0) as loan_principal
    FROM clients c
    JOIN branches b ON c.branch_id = b.id
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
    WHERE b.area_id = ? AND c.status = 'active'
";

$params = [$am_area_id];

if ($selected_branch !== 'all') {
    $query .= " AND c.branch_id = ?";
    $params[] = $selected_branch;
}

$query .= " ORDER BY b.name ASC, c.name ASC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);

// Initialize Grouping Arrays (Grouped by Branch for the AM)
$branches_data = [];
$grand_totals =[
    'savings' => 0,
    'loans' => 0,
    'net' => 0,
    'clients_count' => 0,
    'debtors_count' => 0
];

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $cid = $row['id'];
    $b_name = $row['branch_name'] ?: 'Unassigned Branch';
    $u_name = $row['union'] ?: 'No Union';
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
    $client_data =[
        'id' => $cid,
        'name' => $row['name'],
        'phone' => $row['phone'] ?? '',
        'officer' => $officer_display,
        'union' => $u_name,
        'savings' => $client_savings,
        'loan_balance' => $active_loan,
        'loan_progress' => $loan_progress,
        'net_position' => $net_position,
        'has_loan' => $has_active_loan,
        'status_type' => $status_type
    ];

    // Initialize Branch Key
    if (!isset($branches_data[$b_name])) {
        $branches_data[$b_name] =[
            'name' => $b_name,
            'clients' => [],
            'totals' =>['savings' => 0, 'loans' => 0, 'net' => 0, 'count' => 0]
        ];
    }

    // Add to Branch
    $branches_data[$b_name]['clients'][] = $client_data;
    $branches_data[$b_name]['totals']['savings'] += $client_savings;
    $branches_data[$b_name]['totals']['loans'] += $active_loan;
    $branches_data[$b_name]['totals']['net'] += $net_position;
    $branches_data[$b_name]['totals']['count']++;

    // Add to Grand Totals
    $grand_totals['savings'] += $client_savings;
    $grand_totals['loans'] += $active_loan;
    $grand_totals['net'] += $net_position;
    $grand_totals['clients_count']++;
    if ($has_active_loan) $grand_totals['debtors_count']++;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#3b82f6">
    <title>Area Financial Summary | CUPAD AM</title>
    
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

    <style>
        :root {
            --primary-color: #3b82f6; --secondary-color: #8b5cf6;
            --success-color: #22c55e; --warning-color: #f59e0b; --error-color: #ef4444;
            --bg-primary: #f0f2f5; --bg-secondary: #ffffff; --bg-card: #ffffff;
            --bg-header: rgba(255, 255, 255, 0.95);
            --text-primary: #1f2937; --text-secondary: #6b7280; --border-color: #e5e7eb;
            --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
            --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1);
            --border-radius-lg: 1rem; --border-radius-xl: 1.5rem;
        }
        
        html.dark {
            --bg-primary: #0f172a; --bg-secondary: #1e293b; --bg-card: #1e293b;
            --bg-header: rgba(15, 23, 42, 0.95);
            --text-primary: #f1f5f9; --text-secondary: #94a3b8; --border-color: rgba(255, 255, 255, 0.08);
        }

        body { font-family: 'Inter', sans-serif; background-color: var(--bg-primary); color: var(--text-primary); padding-bottom: 90px; -webkit-tap-highlight-color: transparent; }

        /* HEADER */
        .main-header { background: var(--bg-header); backdrop-filter: blur(12px); border-bottom: 1px solid var(--border-color); position: sticky; top: 0; z-index: 50; padding-top: env(safe-area-inset-top); }
        .navbar { display: flex; justify-content: space-between; align-items: center; padding: 0.8rem 1rem; max-width: 1280px; margin: 0 auto; }
        .logo { font-weight: 700; font-size: 1.25rem; color: var(--primary-color); display: flex; align-items: center; gap: 0.5rem; text-decoration: none; }
        .logo img { height: 32px; width: auto; }
        
        .profile-btn { width: 36px; height: 36px; border-radius: 50%; overflow: hidden; border: 2px solid var(--primary-color); background: var(--bg-secondary); cursor: pointer; display: flex; align-items: center; justify-content: center; position: relative; }
        .profile-btn img { width: 100%; height: 100%; object-fit: cover; }
        .profile-fallback-icon { font-size: 1.2rem; color: var(--text-secondary); }

        /* DASHBOARD GRID */
        .dashboard-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1rem; margin-bottom: 1.5rem; }
        .dashboard-card { border-radius: var(--border-radius-xl); padding: 1.5rem; position: relative; overflow: hidden; box-shadow: var(--shadow-md); border: none; color: white; transition: transform 0.2s ease; }
        .card-gradient-blue { background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); }
        .card-gradient-orange { background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); }
        .card-gradient-purple { background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%); }
        .card-bg-icon { position: absolute; right: -10px; bottom: -20px; font-size: 6rem; color: white; opacity: 0.1; transform: rotate(-10deg); pointer-events: none; }

        /* CONTROLS (Search + Filter + Branch Select) */
        .controls-wrapper { position: sticky; top: 72px; z-index: 40; background: var(--bg-primary); padding-bottom: 1rem; padding-top: 0.5rem; }
        .controls-inner { display: flex; flex-direction: column; gap: 0.8rem; }
        .search-row { display: flex; gap: 0.5rem; }
        
        .co-select {
            background: var(--bg-card); border: 1px solid var(--border-color); color: var(--text-primary); 
            border-radius: 1rem; padding: 0.6rem 2.5rem 0.6rem 1rem; font-size: 0.9rem; font-weight: 600; 
            outline: none; cursor: pointer; box-shadow: var(--shadow-sm); height: 100%; 
            appearance: none; background-image: url("data:image/svg+xml;charset=US-ASCII,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%22292.4%22%20height%3D%22292.4%22%3E%3Cpath%20fill%3D%22%236b7280%22%20d%3D%22M287%2069.4a17.6%2017.6%200%200%200-13-5.4H18.4c-5%200-9.3%201.8-12.9%205.4A17.6%2017.6%200%200%200%200%2082.2c0%205%201.8%209.3%205.4%2012.9l128%20127.9c3.6%203.6%207.8%205.4%2012.8%205.4s9.2-1.8%2012.8-5.4L287%2095c3.5-3.5%205.4-7.8%205.4-12.8%200-5-1.9-9.2-5.5-12.8z%22%2F%3E%3C%2Fsvg%3E"); 
            background-repeat: no-repeat; background-position: right 1rem top 50%; background-size: 0.65rem auto; 
        }

        .search-container { background: var(--bg-card); border-radius: 1rem; padding: 0.5rem; border: 1px solid var(--border-color); box-shadow: var(--shadow-sm); display: flex; align-items: center; flex: 1; min-width: 0; }
        .search-input { width: 100%; padding: 0.5rem 0.5rem 0.5rem 2.5rem; border: none; background: transparent; color: var(--text-primary); font-size: 1rem; outline: none; }
        
        .view-toggles { display: flex; background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 1rem; padding: 0.25rem; }
        .view-btn { padding: 0.5rem 1rem; border-radius: 0.75rem; color: var(--text-secondary); cursor: pointer; transition: all 0.2s; border: none; background: transparent; }
        .view-btn.active { background: var(--primary-color); color: white; shadow: var(--shadow-sm); }
        .view-btn:hover:not(.active) { background: var(--bg-primary); }

        .filter-tabs { display: flex; gap: 0.5rem; overflow-x: auto; padding: 0.2rem 0; scrollbar-width: none; }
        .filter-chip { white-space: nowrap; padding: 0.4rem 1rem; border-radius: 2rem; font-size: 0.8rem; font-weight: 600; background: var(--bg-card); border: 1px solid var(--border-color); color: var(--text-secondary); cursor: pointer; transition: all 0.2s; }
        .filter-chip.active { background: var(--primary-color); color: white; border-color: var(--primary-color); box-shadow: 0 2px 5px rgba(59, 130, 246, 0.3); }

        /* BRANCH SECTIONS */
        .branch-section { margin-bottom: 2rem; background: var(--bg-primary); border-radius: 1rem; overflow: hidden; }
        .branch-header { display: flex; justify-content: space-between; align-items: center; padding: 1rem; background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 1rem; cursor: pointer; transition: background 0.2s; user-select: none; }
        .branch-header:hover { background: rgba(59, 130, 246, 0.05); }
        .branch-header.collapsed .fa-chevron-down { transform: rotate(-90deg); }
        .branch-header i { transition: transform 0.3s; }
        
        .branch-content { display: block; padding-top: 1rem; animation: slideDown 0.3s ease-out; }
        .branch-content.hidden { display: none; }

        /* BRANCH STAT CARDS */
        .branch-stat-card { display: flex; align-items: center; gap: 0.75rem; padding: 1rem; border-radius: 0.75rem; transition: all 0.2s ease; position: relative; overflow: hidden; }
        .branch-stat-card:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.08); }
        .stat-icon { width: 36px; height: 36px; border-radius: 0.5rem; display: flex; align-items: center; justify-content: center; color: white; font-size: 0.9rem; flex-shrink: 0; box-shadow: 0 2px 8px rgba(0,0,0,0.15); }
        .stat-content { display: flex; flex-direction: column; gap: 0.15rem; flex: 1; min-width: 0; }
        .stat-label { font-size: 0.65rem; font-weight: 700; text-transform: uppercase; color: var(--text-secondary); letter-spacing: 0.03em; }
        .stat-value { font-size: 0.95rem; font-weight: 800; line-height: 1.2; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        
        @media (max-width: 640px) {
            .branch-stat-card { padding: 0.75rem; gap: 0.5rem; }
            .stat-icon { width: 32px; height: 32px; font-size: 0.8rem; }
            .stat-value { font-size: 0.85rem; }
        }

        /* GRID VIEW STYLES */
        .client-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 1rem; }
        .client-card { 
            background: var(--bg-card); border-radius: 1rem; padding: 1.25rem; 
            border: 1px solid var(--border-color); box-shadow: var(--shadow-sm); 
            display: flex; flex-direction: column; gap: 0.8rem; position: relative; 
            transition: all 0.2s ease; cursor: pointer; text-decoration: none; color: inherit;
            border-left: 4px solid transparent; 
        }
        .client-card:hover { transform: translateY(-3px); box-shadow: var(--shadow-md); border-color: var(--primary-color); }
        
        .cc-header { display: flex; align-items: center; gap: 0.75rem; }
        .cc-avatar { width: 42px; height: 42px; border-radius: 50%; background: linear-gradient(135deg, var(--bg-primary), var(--border-color)); color: var(--text-primary); display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 1rem; flex-shrink: 0; border: 2px solid var(--bg-primary); }
        .cc-name { font-weight: 700; font-size: 0.95rem; color: var(--text-primary); line-height: 1.2; }
        
        .cc-stats { display: flex; justify-content: space-between; background: var(--bg-primary); padding: 0.6rem; border-radius: 0.75rem; border: 1px solid var(--border-color); }
        .stat-box { text-align: center; flex: 1; }
        .stat-box:first-child { border-right: 1px solid var(--border-color); }
        .stat-label { font-size: 0.65rem; text-transform: uppercase; color: var(--text-secondary); font-weight: 600; margin-bottom: 2px; }
        .stat-val { font-weight: 700; font-size: 0.9rem; }
        
        .net-pos-badge { position: absolute; top: 1rem; right: 1rem; font-size: 0.7rem; font-weight: 800; padding: 2px 8px; border-radius: 6px; }

        /* TABLE VIEW STYLES */
        .client-table-container { overflow-x: auto; border-radius: 1rem; border: 1px solid var(--border-color); background: var(--bg-card); box-shadow: var(--shadow-sm); }
        .client-table { w-full: 100%; border-collapse: collapse; width: 100%; font-size: 0.85rem; }
        .client-table th { background: var(--bg-primary); color: var(--text-secondary); text-align: left; padding: 1rem; font-weight: 600; text-transform: uppercase; font-size: 0.7rem; letter-spacing: 0.05em; border-bottom: 1px solid var(--border-color); white-space: nowrap; }
        .client-table td { padding: 0.8rem 1rem; border-bottom: 1px solid var(--border-color); color: var(--text-primary); vertical-align: middle; white-space: nowrap; }
        .client-table tr:last-child td { border-bottom: none; }
        .client-table tr:hover { background: var(--bg-primary); cursor: pointer; }
        .table-avatar { width: 30px; height: 30px; border-radius: 50%; background: var(--bg-primary); display: flex; align-items: center; justify-content: center; font-size: 0.8rem; font-weight: bold; border: 1px solid var(--border-color); margin-right: 0.75rem; }

        /* Status Colors & Helpers */
        .status-saver { border-left-color: var(--success-color); }
        .status-debtor { border-left-color: var(--warning-color); }
        .status-risk { border-left-color: var(--error-color); }
        .pos-safe { background: rgba(34, 197, 94, 0.1); color: var(--success-color); }
        .pos-risk { background: rgba(239, 68, 68, 0.1); color: var(--error-color); }

        /* PRINT STYLES */
        @media print {
            .mobile-bottom-nav, .controls-wrapper, .main-header button, .main-header a, .branch-content.hidden { display: none !important; }
            .branch-content { display: block !important; }
            body { background: white; color: black; padding-bottom: 0; }
            .view-grid { display: none !important; }
            .view-table { display: block !important; }
            .client-table-container { border: 1px solid #000; box-shadow: none; }
            .client-table th, .client-table td { border-bottom: 1px solid #000; color: black; }
        }

        /* MOBILE NAV */
        .mobile-bottom-nav { display: none; position: fixed; bottom: 0; left: 0; right: 0; background: var(--bg-secondary); border-top: 1px solid var(--border-color); padding: 0.5rem 1rem calc(0.5rem + env(safe-area-inset-bottom)); z-index: 100; justify-content: space-between; box-shadow: 0 -4px 10px rgba(0,0,0,0.05); }
        .nav-item { display: flex; flex-direction: column; align-items: center; justify-content: center; text-decoration: none; color: var(--text-secondary); font-size: 0.7rem; font-weight: 500; gap: 4px; flex: 1; }
        .nav-item i { font-size: 1.4rem; }
        .nav-item.active { color: var(--primary-color); }

        @media (max-width: 768px) {
            .mobile-bottom-nav { display: flex; }
            .controls-wrapper { top: 60px; }
        }
        
        @keyframes slideDown { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
    </style>
</head>

<body>

    <!-- HEADER -->
    <header class="main-header">
        <nav class="navbar">
            <a href="dashboard.php" class="logo">
                <img src="<?php echo htmlspecialchars($base_path . 'favicon.ico'); ?>" alt="Logo">
                <span>CUPAD AM</span>
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
        
        <div class="flex justify-between items-end mb-4">
            <div>
                <h1 class="text-2xl font-extrabold text-gray-900 dark:text-white">
                    <?php echo $selected_branch === 'all' ? 'Area Summary' : htmlspecialchars($selected_branch_name) . " Summary"; ?>
                </h1>
                <p class="text-xs text-gray-500 mt-1"><?php echo htmlspecialchars($area_name); ?> Area • <?php echo date('M d, Y'); ?> • <?php echo $grand_totals['clients_count']; ?> Clients</p>
            </div>
            <button onclick="window.print()" class="text-sm text-blue-500 font-semibold hover:bg-blue-50 dark:hover:bg-blue-900/20 px-3 py-1 rounded-lg transition hidden md:block">
                <i class="fas fa-print mr-1"></i> Print Report
            </button>
        </div>
        
        <!-- DASHBOARD STATS -->
        <div class="dashboard-grid">
            <div class="dashboard-card card-gradient-blue">
                <i class="fas fa-piggy-bank card-bg-icon"></i>
                <div class="relative z-10">
                    <div class="text-sm font-semibold uppercase opacity-90"><?php echo $selected_branch === 'all' ? 'Area Savings' : 'Branch Savings'; ?></div>
                    <div class="text-3xl font-extrabold mt-1">₦<?php echo number_format($grand_totals['savings']); ?></div>
                </div>
            </div>

            <div class="dashboard-card card-gradient-orange">
                <i class="fas fa-hand-holding-usd card-bg-icon"></i>
                <div class="relative z-10">
                    <div class="text-sm font-semibold uppercase opacity-90">Outstanding Loans</div>
                    <div class="text-3xl font-extrabold mt-1">₦<?php echo number_format($grand_totals['loans']); ?></div>
                </div>
            </div>

            <div class="dashboard-card card-gradient-purple">
                <i class="fas fa-users card-bg-icon"></i>
                <div class="relative z-10">
                    <div class="text-sm font-semibold uppercase opacity-90">Client Base</div>
                    <div class="text-3xl font-extrabold mt-1"><?php echo $grand_totals['clients_count']; ?></div>
                    <div class="text-xs opacity-80 mt-1"><?php echo $grand_totals['debtors_count']; ?> with Active Loans</div>
                </div>
            </div>
        </div>

        <!-- STICKY CONTROLS -->
        <div class="controls-wrapper">
            <div class="controls-inner">
                <div class="search-row flex-col md:flex-row w-full gap-2">
                    
                    <!-- Branch Filter -->
                    <form method="GET" class="w-full md:w-auto">
                        <select name="branch" onchange="this.form.submit()" class="co-select w-full md:w-56">
                            <option value="all">All Area Branches</option>
                            <?php foreach($area_branches as $br): ?>
                                <option value="<?php echo htmlspecialchars($br['id']); ?>" <?php echo $selected_branch === $br['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($br['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </form>

                    <div class="search-container flex-1 w-full relative">
                        <i class="fas fa-search text-gray-400 absolute left-4 top-1/2 transform -translate-y-1/2"></i>
                        <input type="text" id="clientSearch" class="search-input" placeholder="Search client name, phone, union, or officer...">
                    </div>
                    
                    <!-- View Switcher -->
                    <div class="view-toggles shrink-0 self-end md:self-auto">
                        <button class="view-btn active" id="btnGrid" onclick="toggleView('grid')" title="Grid View">
                            <i class="fas fa-th-large"></i>
                        </button>
                        <button class="view-btn" id="btnTable" onclick="toggleView('table')" title="List View">
                            <i class="fas fa-list"></i>
                        </button>
                    </div>
                </div>

                <div class="filter-tabs" id="filterTabs">
                    <button class="filter-chip active" data-filter="all">All Clients</button>
                    <button class="filter-chip" data-filter="debtor">With Loans</button>
                    <button class="filter-chip" data-filter="saver">Debt Free</button>
                    <button class="filter-chip" data-filter="risk">High Risk</button>
                </div>
            </div>
        </div>

        <!-- BRANCH LIST -->
        <div id="branchesContainer">
            <?php foreach ($branches_data as $branch_name => $data): ?>
            <div class="branch-section" data-branch-name="<?php echo htmlspecialchars(strtolower($branch_name)); ?>">
                
                <!-- Collapsible Header -->
                <div class="branch-header" onclick="toggleBranch(this)">
                    <div class="flex items-center gap-2">
                        <div class="w-8 h-8 rounded-full bg-blue-100 dark:bg-blue-900 text-blue-600 dark:text-blue-300 flex items-center justify-center font-bold">
                            <i class="fas fa-building text-sm"></i>
                        </div>
                        <div>
                            <div class="font-bold text-gray-800 dark:text-gray-100"><?php echo htmlspecialchars($branch_name); ?></div>
                            <div class="text-xs text-gray-500"><?php echo $data['totals']['count']; ?> Members</div>
                        </div>
                    </div>
                    <i class="fas fa-chevron-down text-gray-400"></i>
                </div>

                <div class="branch-content">
                    <!-- Branch Summary Cards -->
                    <div class="px-4 pb-3">
                        <div class="grid grid-cols-3 gap-3">
                            <div class="branch-stat-card bg-gradient-to-br from-green-50 to-emerald-50 dark:from-green-900/20 dark:to-emerald-900/20 border border-green-200 dark:border-green-800">
                                <div class="stat-icon bg-green-500">
                                    <i class="fas fa-piggy-bank"></i>
                                </div>
                                <div class="stat-content">
                                    <span class="stat-label">Savings</span>
                                    <span class="stat-value text-green-600 dark:text-green-400">₦<?php echo number_format($data['totals']['savings']); ?></span>
                                </div>
                            </div>
                            <div class="branch-stat-card bg-gradient-to-br from-red-50 to-rose-50 dark:from-red-900/20 dark:to-rose-900/20 border border-red-200 dark:border-red-800">
                                <div class="stat-icon bg-red-500">
                                    <i class="fas fa-hand-holding-usd"></i>
                                </div>
                                <div class="stat-content">
                                    <span class="stat-label">Loans</span>
                                    <span class="stat-value text-red-600 dark:text-red-400">₦<?php echo number_format($data['totals']['loans']); ?></span>
                                </div>
                            </div>
                            <div class="branch-stat-card bg-gradient-to-br from-blue-50 to-indigo-50 dark:from-blue-900/20 dark:to-indigo-900/20 border border-blue-200 dark:border-blue-800">
                                <div class="stat-icon <?php echo $data['totals']['net'] >= 0 ? 'bg-blue-500' : 'bg-orange-500'; ?>">
                                    <i class="fas fa-<?php echo $data['totals']['net'] >= 0 ? 'chart-line' : 'exclamation-triangle'; ?>"></i>
                                </div>
                                <div class="stat-content">
                                    <span class="stat-label">Net</span>
                                    <span class="stat-value <?php echo $data['totals']['net'] >= 0 ? 'text-blue-600 dark:text-blue-400' : 'text-orange-600 dark:text-orange-400'; ?>">
                                        ₦<?php echo number_format($data['totals']['net']); ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Client Grid View -->
                    <div class="view-grid client-grid px-4 pb-4">
                        <?php foreach ($data['clients'] as $c): 
                            $initial = strtoupper(substr($c['name'], 0, 1));
                            $status_class = 'status-' . $c['status_type'];
                            $net_class = $c['net_position'] >= 0 ? 'pos-safe' : 'pos-risk';
                        ?>
                        <a href="history.php?client_id=<?php echo $c['id']; ?>" 
                           class="client-card client-item <?php echo $status_class; ?>" 
                           data-name="<?php echo htmlspecialchars(strtolower($c['name'])); ?>" 
                           data-phone="<?php echo htmlspecialchars($c['phone']); ?>"
                           data-officer="<?php echo htmlspecialchars(strtolower($c['officer'])); ?>"
                           data-union="<?php echo htmlspecialchars(strtolower($c['union'])); ?>"
                           data-status="<?php echo $c['status_type']; ?>">
                            
                            <span class="net-pos-badge <?php echo $net_class; ?>">
                                <?php echo $c['net_position'] >= 0 ? '+' : ''; ?>₦<?php echo number_format($c['net_position']); ?>
                            </span>

                            <div class="cc-header">
                                <div class="cc-avatar"><?php echo $initial; ?></div>
                                <div class="flex-1 min-w-0">
                                    <div class="cc-name truncate"><?php echo htmlspecialchars($c['name']); ?></div>
                                    <div class="text-xs text-blue-500 mt-0.5 font-medium flex items-center gap-1 truncate">
                                        <i class="fas fa-user-tie"></i> <?php echo htmlspecialchars($c['officer']); ?>
                                        <span class="mx-1">•</span>
                                        <i class="fas fa-users"></i> <?php echo htmlspecialchars($c['union']); ?>
                                    </div>
                                </div>
                            </div>

                            <div class="cc-stats">
                                <div class="stat-box">
                                    <div class="stat-label">Saved</div>
                                    <div class="stat-val text-green-500">₦<?php echo number_format($c['savings']); ?></div>
                                </div>
                                <div class="stat-box">
                                    <div class="stat-label">Loan</div>
                                    <div class="stat-val <?php echo $c['loan_balance'] > 0 ? 'text-red-500' : 'text-gray-400'; ?>">
                                        ₦<?php echo number_format($c['loan_balance']); ?>
                                    </div>
                                </div>
                            </div>

                            <?php if ($c['has_loan']): ?>
                            <div class="mt-1">
                                <div class="flex justify-between text-[0.65rem] text-gray-500 mb-1 font-medium">
                                    <span>Repayment Progress</span>
                                    <span><?php echo $c['loan_progress']; ?>%</span>
                                </div>
                                <div class="w-full h-1.5 bg-gray-100 dark:bg-gray-700 rounded-full overflow-hidden">
                                    <div class="h-full bg-gradient-to-r from-orange-500 to-red-500 rounded-full" style="width: <?php echo $c['loan_progress']; ?>%;"></div>
                                </div>
                            </div>
                            <?php else: ?>
                                <div class="mt-1 text-[0.65rem] text-gray-400 text-center py-1">
                                    <i class="fas fa-check-circle text-green-500 mr-1"></i> In Good Standing
                                </div>
                            <?php endif; ?>
                        </a>
                        <?php endforeach; ?>
                    </div>

                    <!-- Client Table View -->
                    <div class="view-table hidden px-4 pb-4">
                        <div class="client-table-container">
                            <table class="client-table">
                                <thead>
                                    <tr>
                                        <th>Client</th>
                                        <th>Union/Officer</th>
                                        <th>Savings</th>
                                        <th>Loan Bal</th>
                                        <th>Net Pos</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($data['clients'] as $c): 
                                        $initial = strtoupper(substr($c['name'], 0, 1));
                                    ?>
                                    <tr onclick="window.location.href='history.php?client_id=<?php echo $c['id']; ?>'" 
                                        class="client-item"
                                        data-name="<?php echo htmlspecialchars(strtolower($c['name'])); ?>" 
                                        data-phone="<?php echo htmlspecialchars($c['phone']); ?>"
                                        data-officer="<?php echo htmlspecialchars(strtolower($c['officer'])); ?>"
                                        data-union="<?php echo htmlspecialchars(strtolower($c['union'])); ?>"
                                        data-status="<?php echo $c['status_type']; ?>">
                                        <td>
                                            <div class="flex items-center">
                                                <div class="table-avatar"><?php echo $initial; ?></div>
                                                <div>
                                                    <div class="font-bold"><?php echo htmlspecialchars($c['name']); ?></div>
                                                    <div class="text-xs text-gray-500"><?php echo htmlspecialchars($c['phone']); ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="flex flex-col gap-1">
                                                <span class="text-[10px] uppercase font-bold text-gray-500"><?php echo htmlspecialchars($c['union']); ?></span>
                                                <span class="text-xs text-blue-600 dark:text-blue-400 font-semibold"><i class="fas fa-user-tie mr-1"></i><?php echo htmlspecialchars($c['officer']); ?></span>
                                            </div>
                                        </td>
                                        <td class="font-mono text-green-500 font-bold">₦<?php echo number_format($c['savings']); ?></td>
                                        <td class="font-mono text-red-500 font-bold">₦<?php echo number_format($c['loan_balance']); ?></td>
                                        <td class="font-mono font-bold <?php echo $c['net_position'] >= 0 ? 'text-blue-500' : 'text-orange-500'; ?>">
                                            ₦<?php echo number_format($c['net_position']); ?>
                                        </td>
                                        <td>
                                            <?php if($c['has_loan']): ?>
                                                <span class="text-xs bg-red-100 text-red-600 px-2 py-1 rounded font-bold">Loan Active</span>
                                            <?php else: ?>
                                                <span class="text-xs bg-green-100 text-green-600 px-2 py-1 rounded font-bold">Saver</span>
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

        <div id="noResults" class="hidden flex flex-col items-center justify-center py-12 text-gray-400">
            <div class="w-16 h-16 bg-gray-100 dark:bg-gray-800 rounded-full flex items-center justify-center mb-4">
                <i class="fas fa-search text-2xl"></i>
            </div>
            <p>No clients match your criteria.</p>
        </div>

        <!-- GRAND TOTAL FOOTER -->
        <div class="grand-total-section mt-8">
            <h3 class="text-lg font-bold text-gray-500 uppercase tracking-widest mb-6">Area Summary Report</h3>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div>
                    <div class="text-xs font-bold text-gray-400 uppercase mb-1">Total Savings</div>
                    <div class="text-2xl font-black text-green-600">₦<?php echo number_format($grand_totals['savings']); ?></div>
                </div>
                <div>
                    <div class="text-xs font-bold text-gray-400 uppercase mb-1">Total Debt</div>
                    <div class="text-2xl font-black text-red-600">₦<?php echo number_format($grand_totals['loans']); ?></div>
                </div>
                <div>
                    <div class="text-xs font-bold text-gray-400 uppercase mb-1">Net Position</div>
                    <div class="text-2xl font-black text-blue-600">₦<?php echo number_format($grand_totals['net']); ?></div>
                </div>
            </div>
            <div class="mt-6 pt-6 border-t border-gray-100 dark:border-gray-800 text-xs text-gray-400 pb-8">
                Generated by CUPAD System • <?php echo date('d M Y, h:i A'); ?>
            </div>
        </div>

    </main>

    <!-- MOBILE NAV -->
    <nav class="mobile-bottom-nav">
        <a href="dashboard.php" class="nav-item">
            <i class="fas fa-home"></i>
            <span>Home</span>
        </a>
        <a href="branches.php" class="nav-item">
            <i class="fas fa-building"></i>
            <span>Branches</span>
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

    <script>
        // Theme
        const t = localStorage.getItem('theme');
        if(t==='dark') document.documentElement.classList.add('dark');
        document.getElementById('theme-toggle').addEventListener('click', () => {
            document.documentElement.classList.toggle('dark');
            localStorage.setItem('theme', document.documentElement.classList.contains('dark') ? 'dark' : 'light');
        });

        // View Toggling Logic
        function toggleView(viewType) {
            const gridContainers = document.querySelectorAll('.view-grid');
            const tableContainers = document.querySelectorAll('.view-table');
            const btnGrid = document.getElementById('btnGrid');
            const btnTable = document.getElementById('btnTable');

            if (viewType === 'grid') {
                gridContainers.forEach(el => el.classList.remove('hidden'));
                tableContainers.forEach(el => el.classList.add('hidden'));
                btnGrid.classList.add('active');
                btnTable.classList.remove('active');
                localStorage.setItem('amClientViewPref', 'grid');
            } else {
                gridContainers.forEach(el => el.classList.add('hidden'));
                tableContainers.forEach(el => el.classList.remove('hidden'));
                btnGrid.classList.remove('active');
                btnTable.classList.add('active');
                localStorage.setItem('amClientViewPref', 'table');
            }
        }

        // Initialize view based on preference
        const savedView = localStorage.getItem('amClientViewPref') || 'grid';
        toggleView(savedView);

        // Toggle Branch Accordion
        function toggleBranch(header) {
            header.classList.toggle('collapsed');
            const content = header.nextElementSibling;
            content.classList.toggle('hidden');
        }

        // Filtering Logic
        let currentFilter = 'all';
        const filterBtns = document.querySelectorAll('.filter-chip');
        const searchInput = document.getElementById('clientSearch');

        // Filter Click Event
        filterBtns.forEach(btn => {
            btn.addEventListener('click', () => {
                filterBtns.forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                currentFilter = btn.dataset.filter;
                applyFilters();
            });
        });

        // Search Input Event
        searchInput.addEventListener('input', applyFilters);

        function applyFilters() {
            const searchTerm = searchInput.value.toLowerCase();
            const branches = document.querySelectorAll('.branch-section');
            let totalVisible = 0;

            branches.forEach(branch => {
                const branchName = branch.dataset.branchName || '';
                // Query both Grid Cards and Table Rows using the common class 'client-item'
                const items = branch.querySelectorAll('.client-item');
                let visibleInBranch = 0;
                
                // Branch name match
                const branchMatch = branchName.includes(searchTerm);

                items.forEach(item => {
                    const name = item.dataset.name || '';
                    const phone = item.dataset.phone || '';
                    const officer = item.dataset.officer || '';
                    const union = item.dataset.union || '';
                    const status = item.dataset.status || '';
                    
                    // Filter Check (All, Debtor, Saver, Risk)
                    let filterPass = (currentFilter === 'all') || 
                                     (currentFilter === 'debtor' && (status === 'debtor' || status === 'risk')) ||
                                     (currentFilter === 'risk' && status === 'risk') ||
                                     (currentFilter === 'saver' && status === 'saver');

                    // Search Check
                    let searchPass = branchMatch || name.includes(searchTerm) || phone.includes(searchTerm) || officer.includes(searchTerm) || union.includes(searchTerm);

                    // Logic for display:
                    const isRow = item.tagName === 'TR';
                    const displayStyle = isRow ? 'table-row' : 'flex';

                    if (filterPass && searchPass) {
                        item.style.display = displayStyle;
                        visibleInBranch++;
                        totalVisible++;
                    } else {
                        item.style.display = 'none';
                    }
                });

                // Show/Hide Branch Section
                if (visibleInBranch > 0) {
                    branch.style.display = 'block';
                    // Auto-expand if searching
                    if(searchTerm.length > 0 && visibleInBranch < 5) {
                        const header = branch.querySelector('.branch-header');
                        const content = branch.querySelector('.branch-content');
                        header.classList.remove('collapsed');
                        content.classList.remove('hidden');
                    }
                } else {
                    branch.style.display = 'none';
                }
            });

            document.getElementById('noResults').style.display = totalVisible === 0 ? 'flex' : 'none';
        }
    </script>
</body>
</html>