<?php
// zm/branches.php - Zonal Manager Branches Overview
date_default_timezone_set('Africa/Lagos');
session_start();
require_once '../includes/config.php';
$pdo = getDbConnection();

// 1. Authentication & Role Check
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'zm') {
    header('Location: ../index.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username']; 
$full_name = $_SESSION['name'] ?? 'Zonal Manager';
$base_path = '../';

// Fetch the ZM's zone_id and profile pic
$stmt_u = $pdo->prepare("SELECT zone_id, profile_pic FROM users WHERE id = :uid");
$stmt_u->execute(['uid' => $user_id]);
$user_data = $stmt_u->fetch();

$zone_id = $user_data['zone_id'] ?? 0;
$profile_pic = $user_data['profile_pic'] ?? 'default_avatar.png';

if (!$zone_id) {
    die("Access denied: No zone assigned to this manager.");
}

// --- AJAX Handler for Monthly Summary (Last 6 Months) ---
if (isset($_GET['ajax']) && $_GET['ajax'] === 'monthly_summary') {
    header('Content-Type: application/json');
    $branch_id = $_GET['branch_id'] ?? 0;
    
    // Security: verify this branch is in the ZM's zone
    $stmt_check = $pdo->prepare("SELECT b.id, b.name FROM branches b JOIN areas a ON b.area_id = a.id WHERE b.id = ? AND a.zone_id = ?");
    $stmt_check->execute([$branch_id, $zone_id]);
    $branch_info = $stmt_check->fetch(PDO::FETCH_ASSOC);
    
    if (!$branch_info) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized or Branch not found.']);
        exit;
    }

    // Helper closure to fetch data for a specific month/year
    $getMetrics = function($m, $y) use ($pdo, $branch_id) {
        // Savings Deposits
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(sc.amount), 0) FROM saving_collections sc JOIN clients c ON sc.client_id = c.id WHERE c.branch_id = ? AND MONTH(sc.date) = ? AND YEAR(sc.date) = ? AND sc.amount > 0 AND LOWER(sc.type) NOT IN ('withdrawal', 'return', 'adjust')");
        $stmt->execute([$branch_id, $m, $y]);
        $savings = $stmt->fetchColumn();

        // Withdrawals
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(ABS(sc.amount)), 0) FROM saving_collections sc JOIN clients c ON sc.client_id = c.id WHERE c.branch_id = ? AND MONTH(sc.date) = ? AND YEAR(sc.date) = ? AND (sc.amount < 0 OR LOWER(sc.type) IN ('withdrawal', 'return', 'adjust'))");
        $stmt->execute([$branch_id, $m, $y]);
        $withdrawals = $stmt->fetchColumn();

        // Disbursements
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(d.principal), 0) FROM disbursements d JOIN clients c ON d.client_id = c.id WHERE c.branch_id = ? AND MONTH(d.date) = ? AND YEAR(d.date) = ?");
        $stmt->execute([$branch_id, $m, $y]);
        $disbursements = $stmt->fetchColumn();

        // Repayments
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(lc.amount_collected), 0) FROM loan_collections lc JOIN clients c ON lc.client_id = c.id WHERE c.branch_id = ? AND MONTH(lc.date) = ? AND YEAR(lc.date) = ?");
        $stmt->execute([$branch_id, $m, $y]);
        $repayments = $stmt->fetchColumn();

        return[
            'savings' => (float)$savings,
            'withdrawals' => (float)$withdrawals,
            'disbursements' => (float)$disbursements,
            'repayments' => (float)$repayments
        ];
    };

    // Calculate last 6 months
    $months_data =[];
    $base_time = strtotime(date('Y-m-01 12:00:00')); // Use the 1st of current month to safely subtract months
    
    for ($i = 0; $i < 6; $i++) {
        $time = strtotime("-$i months", $base_time);
        $m = date('m', $time);
        $y = date('Y', $time);
        
        $months_data[] =[
            'index' => $i,
            'full_name' => date('F Y', $time),
            'short_name' => date('M Y', $time),
            'data' => $getMetrics($m, $y)
        ];
    }
    
    echo json_encode([
        'success' => true,
        'branch_name' => $branch_info['name'],
        'months' => $months_data
    ]);
    exit;
}

// 2. Fetch all Areas in this Zone (for filtering)
$stmt_areas = $pdo->prepare("SELECT id, name FROM areas WHERE zone_id = :zid ORDER BY name ASC");
$stmt_areas->execute(['zid' => $zone_id]);
$zone_areas = $stmt_areas->fetchAll(PDO::FETCH_ASSOC);

// 3. Fetch Branches and their Cumulative Metrics for the ENTIRE Zone
$sql_branches = "
    SELECT 
        b.id, 
        b.name as branch_name,
        b.area_id,
        a.name as area_name,
        b.status as branch_status,
        (SELECT COALESCE(NULLIF(u.full_name, ''), u.username) FROM users u WHERE u.branch_id = b.id AND LOWER(u.role) = 'bm' AND LOWER(u.status) = 'active' LIMIT 1) as bm_name,
        (SELECT COUNT(*) FROM clients c WHERE c.branch_id = b.id AND c.status = 'active') as active_clients,
        (SELECT COALESCE(SUM(sb.balance), 0) 
         FROM saving_balances sb JOIN clients c2 ON sb.client_id = c2.id 
         WHERE c2.branch_id = b.id AND c2.status = 'active') as total_net_savings,
        (SELECT COALESCE(SUM(lc.amount_collected), 0) 
         FROM loan_collections lc JOIN clients c3 ON lc.client_id = c3.id 
         WHERE c3.branch_id = b.id) as total_collections,
        (SELECT COALESCE(SUM(d.remaining_balance), 0) 
         FROM disbursements d JOIN clients c4 ON d.client_id = c4.id 
         WHERE c4.branch_id = b.id AND d.remaining_balance > 0) as outstanding_portfolio,
         (SELECT COUNT(*) FROM users u WHERE u.branch_id = b.id AND u.role = 'co' AND u.status = 'active') as active_cos
    FROM branches b
    JOIN areas a ON b.area_id = a.id
    WHERE a.zone_id = :zid
    ORDER BY a.name ASC, total_collections DESC, b.name ASC
";

$stmt_branches = $pdo->prepare($sql_branches);
$stmt_branches->execute([
    'zid' => $zone_id
]);
$branches = $stmt_branches->fetchAll(PDO::FETCH_ASSOC);

// Calculate Totals for the header
$total_branches = count($branches);
$total_zone_clients = array_sum(array_column($branches, 'active_clients'));
$total_zone_savings = array_sum(array_column($branches, 'total_net_savings'));
$total_zone_collections = array_sum(array_column($branches, 'total_collections'));

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>Zone Branches | CUPAD</title>
    
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
            --border-radius-lg: 1rem; --border-radius-xl: 1.5rem;
        }
        html.dark {
            --bg-primary: #0f172a; --bg-secondary: #1e293b; --bg-card: #1e293b;
            --bg-header: rgba(15, 23, 42, 0.95);
            --text-primary: #f1f5f9; --text-secondary: #94a3b8; --border-color: rgba(255, 255, 255, 0.08);
        }
        body { font-family: 'Inter', sans-serif; background-color: var(--bg-primary); color: var(--text-primary); padding-bottom: 90px; -webkit-tap-highlight-color: transparent; }
        
        .hide-scrollbar::-webkit-scrollbar { display: none; }
        .hide-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }

        .main-header { background: var(--bg-header); backdrop-filter: blur(12px); border-bottom: 1px solid var(--border-color); position: sticky; top: 0; z-index: 50; padding-top: env(safe-area-inset-top); }
        .navbar { display: flex; justify-content: space-between; align-items: center; padding: 0.8rem 1rem; max-width: 1280px; margin: 0 auto; }
        .logo { font-weight: 700; font-size: 1.25rem; color: var(--primary-color); display: flex; align-items: center; gap: 0.5rem; text-decoration: none; }
        .logo img { height: 32px; width: auto; }
        
        .profile-btn { width: 36px; height: 36px; border-radius: 50%; overflow: hidden; border: 2px solid var(--primary-color); background: var(--bg-secondary); cursor: pointer; display: flex; align-items: center; justify-content: center; position: relative; }
        .profile-btn img { width: 100%; height: 100%; object-fit: cover; }
        .profile-fallback-icon { font-size: 1.2rem; color: var(--text-secondary); }
        
        /* Dashboard Stats Grid */
        .dashboard-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1rem; margin-bottom: 2rem; }
        .dashboard-card { border-radius: var(--border-radius-xl); padding: 1.5rem; position: relative; overflow: hidden; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1); border: none; color: white; transition: transform 0.2s ease; }
        .dashboard-card:active { transform: scale(0.98); }
        .card-gradient-blue { background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); }
        .card-gradient-green { background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%); }
        .card-gradient-orange { background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); }
        .card-gradient-purple { background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%); }
        .card-bg-icon { position: absolute; right: -10px; bottom: -20px; font-size: 6rem; color: white; opacity: 0.1; transform: rotate(-10deg); pointer-events: none; }

        /* Controls */
        .controls-wrapper { display: flex; flex-direction: column; gap: 1rem; margin-bottom: 1.5rem; }
        @media (min-width: 768px) { .controls-wrapper { flex-direction: row; justify-content: space-between; align-items: center; } }
        .search-box { position: relative; flex: 1; max-width: 500px; }
        .filter-select { padding: 0.75rem 1rem; border-radius: 0.75rem; border: 1px solid var(--border-color); background: var(--bg-card); color: var(--text-primary); outline: none; box-shadow: var(--shadow-sm); font-weight: 500; }

        /* Branches Grid */
        .branches-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 1.5rem; }
        .branch-card { cursor: pointer; background: var(--bg-card); border-radius: var(--border-radius-xl); overflow: hidden; box-shadow: var(--shadow-sm); border: 1px solid var(--border-color); transition: transform 0.2s, box-shadow 0.2s; }
        .branch-card:hover { transform: translateY(-3px); box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1); border-color: var(--primary-color); }
        
        .branch-header { padding: 1.25rem; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: flex-start; }
        .branch-title { font-weight: 800; font-size: 1.15rem; color: var(--text-primary); display: flex; align-items: center; gap: 0.5rem; }
        .branch-area-badge { font-size: 0.65rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; color: var(--secondary-color); margin-bottom: 0.25rem; display: flex; align-items: center; gap: 0.25rem; }
        
        .branch-status { font-size: 0.7rem; font-weight: 700; padding: 0.2rem 0.6rem; border-radius: 12px; text-transform: uppercase; }
        .status-active { background: rgba(34, 197, 94, 0.1); color: var(--success-color); }
        .status-inactive { background: rgba(239, 68, 68, 0.1); color: var(--error-color); }
        
        .bm-info { display: flex; align-items: center; gap: 0.75rem; padding-top: 0.75rem; }
        .bm-avatar { width: 32px; height: 32px; border-radius: 50%; background: var(--bg-primary); display: flex; align-items: center; justify-content: center; color: var(--text-secondary); }
        .bm-details { display: flex; flex-direction: column; }
        .bm-name { font-size: 0.85rem; font-weight: 600; color: var(--text-primary); }
        .bm-role { font-size: 0.7rem; color: var(--text-secondary); }

        .branch-body { padding: 1.25rem; pointer-events: none; }
        .metrics-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 0.5rem; }
        .metric-item { display: flex; flex-direction: column; gap: 0.25rem; }
        .metric-label { font-size: 0.75rem; color: var(--text-secondary); display: flex; align-items: center; gap: 0.35rem; }
        .metric-value { font-size: 1rem; font-weight: 700; color: var(--text-primary); }
        .val-success { color: var(--success-color); }
        .val-primary { color: var(--primary-color); }
        .val-warning { color: var(--warning-color); }
        .val-error { color: var(--error-color); }

        .branch-footer { padding: 1rem 1.25rem; background: var(--bg-primary); border-top: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; }
        .co-count-badge { font-size: 0.75rem; color: var(--text-secondary); font-weight: 600; background: var(--bg-secondary); padding: 0.3rem 0.7rem; border-radius: 20px; border: 1px solid var(--border-color); }
        
        .btn-view { padding: 0.4rem 0.8rem; border-radius: 8px; font-size: 0.8rem; font-weight: 600; text-decoration: none; transition: background 0.2s, transform 0.2s; box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1); cursor: pointer; display: inline-flex; align-items: center; gap: 0.25rem; background: var(--primary-color); color: white; border: none; }
        .btn-view:hover { background: #2563eb; transform: translateY(-1px); box-shadow: 0 4px 8px rgba(59, 130, 246, 0.4); }

        /* Modal Styles */
        .modal-backdrop { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); backdrop-filter: blur(5px); z-index: 200; display: none; align-items: center; justify-content: center; opacity: 0; transition: opacity 0.3s ease; }
        .modal-backdrop.active { opacity: 1; }
        .modal-card { background: var(--bg-card); width: 92%; max-width: 480px; border-radius: 1.5rem; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.4); transform: scale(0.95); transition: transform 0.3s; border: 1px solid var(--border-color); max-height: 85vh; overflow: hidden; display: flex; flex-direction: column; }
        .modal-backdrop.active .modal-card { transform: scale(1); }

        /* Mobile Nav & Tweaks */
        .mobile-bottom-nav { display: none; position: fixed; bottom: 0; left: 0; right: 0; background: var(--bg-secondary); border-top: 1px solid var(--border-color); padding: 0.5rem 1rem calc(0.5rem + env(safe-area-inset-bottom)); z-index: 100; justify-content: space-between; box-shadow: 0 -4px 10px rgba(0,0,0,0.05); }
        .nav-item { display: flex; flex-direction: column; align-items: center; justify-content: center; text-decoration: none; color: var(--text-secondary); font-size: 0.7rem; font-weight: 500; gap: 4px; flex: 1; }
        .nav-item i { font-size: 1.4rem; }
        .nav-item.active { color: var(--primary-color); }
        
        @media (max-width: 768px) { 
            .mobile-bottom-nav { display: flex; } 
            .dashboard-grid { display: flex; overflow-x: auto; scroll-snap-type: x mandatory; gap: 1rem; padding-bottom: 0.5rem; margin-right: -1rem; padding-right: 1.5rem; scrollbar-width: none; }
            .dashboard-grid::-webkit-scrollbar { display: none; }
            .dashboard-card { min-width: 85vw; scroll-snap-align: center; flex-shrink: 0; }
            
            .modal-card { position: absolute; bottom: 0; width: 100%; max-width: none; border-radius: 1.5rem 1.5rem 0 0; transform: translateY(100%); height: auto; max-height: 85vh; padding-bottom: env(safe-area-inset-bottom); }
            .modal-backdrop.active .modal-card { transform: translateY(0); }
        }
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
            </div>
        </nav>
    </header>

    <!-- MAIN CONTENT -->
    <main class="max-w-7xl mx-auto px-4 py-6">
        
        <!-- Page Header -->
        <div class="mb-6 flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-extrabold text-gray-900 dark:text-white">Zone Branches</h1>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Overview of all branches within your assigned zone.</p>
            </div>
        </div>

        <!-- STATS (Scrollable Dashboard Grid) -->
        <div class="dashboard-grid">
            <div class="dashboard-card card-gradient-purple">
                <i class="fas fa-building card-bg-icon"></i>
                <div class="flex justify-between items-start relative z-10">
                    <div class="min-w-0">
                        <div class="text-sm font-semibold uppercase opacity-90">Total Branches</div>
                        <div class="text-2xl lg:text-3xl font-extrabold mt-1 mb-2 truncate" id="statBranches"><?php echo number_format($total_branches); ?></div>
                        <div class="text-xs bg-black/20 rounded-full px-3 py-1 inline-flex items-center"><i class="fas fa-map-marked-alt mr-1"></i> Zone-wide</div>
                    </div>
                </div>
            </div>

            <div class="dashboard-card card-gradient-blue">
                <i class="fas fa-users card-bg-icon"></i>
                <div class="flex justify-between items-start relative z-10">
                    <div class="min-w-0">
                        <div class="text-sm font-semibold uppercase opacity-90">Total Clients</div>
                        <div class="text-2xl lg:text-3xl font-extrabold mt-1 mb-2 truncate" id="statClients"><?php echo number_format($total_zone_clients); ?></div>
                        <div class="text-xs bg-black/20 rounded-full px-3 py-1 inline-flex items-center"><i class="fas fa-check-circle mr-1"></i> Active</div>
                    </div>
                </div>
            </div>

            <div class="dashboard-card card-gradient-green">
                <i class="fas fa-piggy-bank card-bg-icon"></i>
                <div class="flex justify-between items-start relative z-10">
                    <div class="min-w-0">
                        <div class="text-sm font-semibold uppercase opacity-90">Total Net Savings</div>
                        <div class="text-2xl lg:text-3xl font-extrabold mt-1 mb-2 truncate" id="statSavings" title="₦<?php echo number_format($total_zone_savings); ?>">₦<?php echo number_format($total_zone_savings); ?></div>
                        <div class="text-xs bg-black/20 rounded-full px-3 py-1 inline-flex items-center"><i class="fas fa-chart-line mr-1"></i> All Time</div>
                    </div>
                </div>
            </div>

            <div class="dashboard-card card-gradient-orange">
                <i class="fas fa-coins card-bg-icon"></i>
                <div class="flex justify-between items-start relative z-10">
                    <div class="min-w-0">
                        <div class="text-sm font-semibold uppercase opacity-90">Total Collections</div>
                        <div class="text-2xl lg:text-3xl font-extrabold mt-1 mb-2 truncate" id="statCollections" title="₦<?php echo number_format($total_zone_collections); ?>">₦<?php echo number_format($total_zone_collections); ?></div>
                        <div class="text-xs bg-black/20 rounded-full px-3 py-1 inline-flex items-center"><i class="fas fa-calendar-alt mr-1"></i> All Time</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Controls: Search / Filter -->
        <div class="controls-wrapper">
            <div class="search-box">
                <i class="fas fa-search absolute left-4 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                <input type="text" id="branchSearch" placeholder="Search branch or area name..." class="w-full pl-11 pr-4 py-3 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500 shadow-sm transition-all">
            </div>
            <select id="areaFilter" class="filter-select w-full md:w-auto">
                <option value="all">All Areas</option>
                <?php foreach($zone_areas as $area): ?>
                    <option value="<?php echo htmlspecialchars($area['id']); ?>">
                        <?php echo htmlspecialchars($area['name']); ?> Area
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- Branches Grid -->
        <div class="branches-grid" id="branchesContainer">
            <?php foreach ($branches as $branch): ?>
                <?php 
                    $status_class = strtolower($branch['branch_status']) === 'active' ? 'status-active' : 'status-inactive';
                    $bm_display = $branch['bm_name'] ?? 'Unassigned';
                    // Combined search string (branch + area)
                    $search_string = strtolower($branch['branch_name'] . ' ' . $branch['area_name']);
                ?>
                <!-- ENTIRE CARD IS CLICKABLE -->
                <div class="branch-card" 
                     onclick="viewMonthlySummary('<?php echo htmlspecialchars($branch['id']); ?>')"
                     data-search="<?php echo htmlspecialchars($search_string); ?>" 
                     data-area="<?php echo htmlspecialchars($branch['area_id']); ?>"
                     data-clients="<?php echo htmlspecialchars($branch['active_clients']); ?>"
                     data-savings="<?php echo htmlspecialchars($branch['total_net_savings']); ?>"
                     data-collections="<?php echo htmlspecialchars($branch['total_collections']); ?>"
                     title="Click to view Monthly Performance Summary">
                     
                    <div class="branch-header">
                        <div>
                            <div class="branch-area-badge">
                                <i class="fas fa-map-marked-alt"></i> <?php echo htmlspecialchars($branch['area_name']); ?>
                            </div>
                            <h3 class="branch-title">
                                <i class="fas fa-building text-blue-500"></i> 
                                <?php echo htmlspecialchars($branch['branch_name']); ?>
                            </h3>
                            <div class="bm-info">
                                <div class="bm-avatar">
                                    <i class="fas <?php echo $branch['bm_name'] ? 'fa-user-tie text-blue-500' : 'fa-user-minus text-red-400'; ?>"></i>
                                </div>
                                <div class="bm-details">
                                    <span class="bm-name <?php echo !$branch['bm_name'] ? 'text-red-500 italic' : ''; ?>">
                                        <?php echo htmlspecialchars($bm_display); ?>
                                    </span>
                                    <span class="bm-role">Branch Manager</span>
                                </div>
                            </div>
                        </div>
                        <span class="branch-status <?php echo $status_class; ?>">
                            <?php echo htmlspecialchars($branch['branch_status']); ?>
                        </span>
                    </div>
                    
                    <div class="branch-body">
                        <div class="metrics-grid">
                            <div class="metric-item">
                                <span class="metric-label"><i class="fas fa-users text-gray-400"></i> Active Clients</span>
                                <span class="metric-value"><?php echo number_format($branch['active_clients']); ?></span>
                            </div>
                            <div class="metric-item">
                                <span class="metric-label"><i class="fas fa-wallet text-gray-400"></i> Outstanding Loan</span>
                                <span class="metric-value val-warning">₦<?php echo number_format($branch['outstanding_portfolio']); ?></span>
                            </div>
                            <div class="metric-item">
                                <span class="metric-label"><i class="fas fa-piggy-bank text-gray-400"></i> Total Savings</span>
                                <span class="metric-value <?php echo $branch['total_net_savings'] < 0 ? 'val-error' : 'val-success'; ?>">
                                    ₦<?php echo number_format($branch['total_net_savings']); ?>
                                </span>
                            </div>
                            <div class="metric-item">
                                <span class="metric-label"><i class="fas fa-hand-holding-usd text-gray-400"></i> Total Collection</span>
                                <span class="metric-value val-primary">₦<?php echo number_format($branch['total_collections']); ?></span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="branch-footer">
                        <span class="co-count-badge">
                            <i class="fas fa-briefcase mr-1 text-gray-500"></i> <?php echo number_format($branch['active_cos']); ?> COs
                        </span>
                        
                        <!-- event.stopPropagation() prevents opening the modal when clicking reports -->
                        <a href="analytics.php?branch=<?php echo htmlspecialchars($branch['id']); ?>" class="btn-view" onclick="event.stopPropagation();">
                            Reports <i class="fas fa-arrow-right ml-1 text-xs"></i>
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>

            <?php if(empty($branches)): ?>
                <div class="col-span-full text-center py-12 bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700">
                    <i class="fas fa-building text-4xl text-gray-300 dark:text-gray-600 mb-3"></i>
                    <h3 class="text-lg font-bold text-gray-900 dark:text-white">No Branches Found</h3>
                    <p class="text-gray-500 dark:text-gray-400 mt-1">There are currently no active branches assigned to areas within your zone.</p>
                </div>
            <?php endif; ?>
        </div>

    </main>

    <!-- MONTHLY SUMMARY MODAL -->
    <div id="summaryModal" class="modal-backdrop" onclick="if(event.target===this) closeModal('summaryModal')">
        <div class="modal-card">
            <div class="px-6 py-5 border-b border-gray-100 dark:border-slate-800 flex justify-between items-center bg-gray-50 dark:bg-slate-900 rounded-t-3xl">
                <div>
                    <span class="font-extrabold text-xl text-gray-900 dark:text-white" id="summaryModalTitle">Branch Summary</span>
                    <div class="text-sm font-semibold text-blue-600 dark:text-blue-400 mt-0.5"><i class="fas fa-chart-pie mr-1"></i> Performance Metrics</div>
                </div>
                <button type="button" class="w-9 h-9 rounded-full bg-gray-200 dark:bg-slate-800 flex items-center justify-center text-gray-600 hover:bg-gray-300 transition-colors" onclick="closeModal('summaryModal')"><i class="fas fa-times"></i></button>
            </div>
            <div class="p-6 overflow-y-auto" id="summaryModalBody">
                <!-- Injected dynamically via JS -->
            </div>
        </div>
    </div>

    <!-- MOBILE BOTTOM NAVIGATION (ZM) -->
    <nav class="mobile-bottom-nav">
        <a href="dashboard.php" class="nav-item">
            <i class="fas fa-home"></i>
            <span>Home</span>
        </a>
        <a href="areas.php" class="nav-item">
            <i class="fas fa-map-marked-alt"></i>
            <span>Areas</span>
        </a>
        <a href="branches.php" class="nav-item active">
             <div style="background: var(--primary-color); width: 45px; height: 45px; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; margin-top: -25px; box-shadow: 0 4px 10px rgba(59, 130, 246, 0.4);">
                <i class="fas fa-building" style="font-size: 1.2rem; margin:0;"></i>
             </div>
             <span style="font-size:0.65rem; font-weight:600; margin-top:2px;">Branches</span>
        </a>
        <a href="clients.php" class="nav-item">
            <i class="fas fa-users"></i>
            <span>Clients</span>
        </a>
        <a href="analytics.php" class="nav-item">
            <i class="fas fa-chart-bar"></i>
            <span>Reports</span>
        </a>
    </nav>

    <!-- JS SCRIPTS -->
    <script>
        // Dark Mode Initialization
        const t = localStorage.getItem('theme');
        if(t==='dark') document.documentElement.classList.add('dark');
        
        document.getElementById('theme-toggle').addEventListener('click', () => {
            document.documentElement.classList.toggle('dark');
            localStorage.setItem('theme', document.documentElement.classList.contains('dark') ? 'dark' : 'light');
        });

        // Search and Filter Logic
        const searchInput = document.getElementById('branchSearch');
        const areaFilter = document.getElementById('areaFilter');
        const cards = document.querySelectorAll('.branch-card');

        function filterBranches() {
            const term = searchInput.value.toLowerCase();
            const selectedArea = areaFilter.value;

            let visibleBranches = 0;
            let visibleClients = 0;
            let visibleSavings = 0;
            let visibleCollections = 0;

            cards.forEach(card => {
                const searchData = card.getAttribute('data-search').toLowerCase();
                const cardArea = card.getAttribute('data-area');
                
                const matchesSearch = searchData.includes(term);
                const matchesArea = (selectedArea === 'all') || (cardArea === selectedArea);

                if (matchesSearch && matchesArea) {
                    card.style.display = 'block';
                    visibleBranches++;
                    visibleClients += parseInt(card.getAttribute('data-clients') || 0);
                    visibleSavings += parseFloat(card.getAttribute('data-savings') || 0);
                    visibleCollections += parseFloat(card.getAttribute('data-collections') || 0);
                } else {
                    card.style.display = 'none';
                }
            });

            document.getElementById('statBranches').textContent = new Intl.NumberFormat('en-US').format(visibleBranches);
            document.getElementById('statClients').textContent = new Intl.NumberFormat('en-US').format(visibleClients);
            document.getElementById('statSavings').textContent = '₦' + new Intl.NumberFormat('en-US').format(visibleSavings);
            document.getElementById('statCollections').textContent = '₦' + new Intl.NumberFormat('en-US').format(visibleCollections);
        }

        searchInput.addEventListener('input', filterBranches);
        areaFilter.addEventListener('change', filterBranches);

        // Modal Controls
        function openModal(id) { 
            const modal = document.getElementById(id);
            modal.style.display = 'flex'; 
            setTimeout(() => modal.classList.add('active'), 10); 
        }
        
        function closeModal(id) { 
            const modal = document.getElementById(id);
            modal.classList.remove('active'); 
            setTimeout(() => modal.style.display = 'none', 300); 
        }

        // Global variable to hold months data for fast tab switching
        let branchMonthsData =[];

        // Fetch Monthly Summary logic
        async function viewMonthlySummary(branchId) {
            openModal('summaryModal');
            const bodyDiv = document.getElementById('summaryModalBody');
            bodyDiv.innerHTML = '<div class="text-center py-10"><i class="fas fa-spinner fa-spin text-3xl text-blue-500"></i><p class="mt-3 text-gray-500 font-medium">Fetching summary...</p></div>';
            
            try {
                const res = await fetch(`?ajax=monthly_summary&branch_id=${branchId}`);
                const data = await res.json();
                
                if(data.success) {
                    document.getElementById('summaryModalTitle').textContent = data.branch_name;
                    
                    // Store data globally for tab switching
                    branchMonthsData = data.months;
                    
                    // Render the most recent month tab (index 0) by default
                    renderSummaryTab(0);
                } else {
                    bodyDiv.innerHTML = `<div class="text-center py-10 text-red-500"><i class="fas fa-exclamation-circle text-4xl mb-3 opacity-50"></i><p class="font-bold">${data.message}</p></div>`;
                }
            } catch(e) {
                bodyDiv.innerHTML = `<div class="text-center py-10 text-red-500"><i class="fas fa-wifi text-4xl mb-3 opacity-50"></i><p class="font-bold">Failed to load data. Please check your connection.</p></div>`;
            }
        }

        // Renders specific month's data into the modal body
        function renderSummaryTab(activeIndex) {
            const bodyDiv = document.getElementById('summaryModalBody');
            const dataset = branchMonthsData[activeIndex];
            
            const fmt = num => '₦' + Number(num).toLocaleString('en-US');
            const netSavings = dataset.data.savings - dataset.data.withdrawals;
            
            // Build the scrollable tab buttons
            let tabsHtml = `<div class="flex overflow-x-auto hide-scrollbar gap-2 mb-5 pb-1">`;
            branchMonthsData.forEach((m, idx) => {
                const isActive = (idx === activeIndex);
                tabsHtml += `
                    <button class="flex-none px-4 py-2.5 text-sm font-bold rounded-lg transition-all ${isActive ? 'bg-blue-600 text-white shadow-md' : 'bg-gray-100 dark:bg-slate-800 text-gray-500 hover:bg-gray-200 dark:hover:bg-slate-700'}" 
                            onclick="renderSummaryTab(${idx})">
                        ${m.short_name} ${idx === 0 ? '<span class="text-[10px] ml-1 opacity-70 uppercase">(Current)</span>' : ''}
                    </button>
                `;
            });
            tabsHtml += `</div>`;
            
            bodyDiv.innerHTML = `
                ${tabsHtml}

                <!-- Metrics Grid -->
                <div class="grid grid-cols-2 gap-4">
                    <div class="bg-green-50 dark:bg-green-900/20 p-4 rounded-2xl border border-green-100 dark:border-green-800/50">
                        <div class="text-[11px] text-green-600 dark:text-green-400 font-bold uppercase mb-1"><i class="fas fa-arrow-down mr-1"></i> Savings In</div>
                        <div class="text-lg md:text-xl font-extrabold text-green-700 dark:text-green-300 truncate" title="${fmt(dataset.data.savings)}">${fmt(dataset.data.savings)}</div>
                    </div>
                    <div class="bg-red-50 dark:bg-red-900/20 p-4 rounded-2xl border border-red-100 dark:border-red-800/50">
                        <div class="text-[11px] text-red-600 dark:text-red-400 font-bold uppercase mb-1"><i class="fas fa-arrow-up mr-1"></i> Withdrawals</div>
                        <div class="text-lg md:text-xl font-extrabold text-red-700 dark:text-red-300 truncate" title="${fmt(dataset.data.withdrawals)}">${fmt(dataset.data.withdrawals)}</div>
                    </div>
                    <div class="bg-purple-50 dark:bg-purple-900/20 p-4 rounded-2xl border border-purple-100 dark:border-purple-800/50">
                        <div class="text-[11px] text-purple-600 dark:text-purple-400 font-bold uppercase mb-1"><i class="fas fa-arrow-down mr-1"></i> Repayments In</div>
                        <div class="text-lg md:text-xl font-extrabold text-purple-700 dark:text-purple-300 truncate" title="${fmt(dataset.data.repayments)}">${fmt(dataset.data.repayments)}</div>
                    </div>
                    <div class="bg-orange-50 dark:bg-orange-900/20 p-4 rounded-2xl border border-orange-100 dark:border-orange-800/50">
                        <div class="text-[11px] text-orange-600 dark:text-orange-400 font-bold uppercase mb-1"><i class="fas fa-arrow-up mr-1"></i> Loans Issued</div>
                        <div class="text-lg md:text-xl font-extrabold text-orange-700 dark:text-orange-300 truncate" title="${fmt(dataset.data.disbursements)}">${fmt(dataset.data.disbursements)}</div>
                    </div>
                </div>
                
                <!-- Net Overviews -->
                <div class="mt-4 bg-gray-50 dark:bg-slate-800 p-5 rounded-2xl text-center border border-gray-200 dark:border-slate-700">
                    <div class="text-xs text-gray-500 font-bold uppercase tracking-wider mb-2">Net Savings</div>
                    <div class="text-3xl font-black ${netSavings >= 0 ? 'text-green-500' : 'text-red-500'} truncate" title="${fmt(netSavings)}">
                        ${fmt(netSavings)}
                    </div>
                </div>
            `;
        }
    </script>
</body>
</html>