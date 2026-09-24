<?php
// am/branches.php - Area Manager Branches Overview
session_start();
require_once '../includes/config.php';
$pdo = getDbConnection();

// 1. Authentication & Role Check
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'am') {
    header('Location: ../index.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username']; 
$full_name = $_SESSION['name'] ?? 'Area Manager';
$base_path = '../';

// Fetch the AM's area_id
$stmt_a = $pdo->prepare("SELECT area_id FROM users WHERE id = :uid");
$stmt_a->execute(['uid' => $user_id]);
$area_id = $stmt_a->fetchColumn();

// Fetch User Profile Data
$sql_user = "SELECT profile_pic FROM users WHERE id = :uid";
$stmt_u = $pdo->prepare($sql_user);
$stmt_u->execute(['uid' => $user_id]);
$user_data = $stmt_u->fetch();
$profile_pic = $user_data['profile_pic'] ?? 'default_avatar.png';

// 2. Fetch Branches and their Metrics for the Area
// Use Month-to-Date logic to align with other pages
$start_of_month = date('Y-m-01');
$today = date('Y-m-d');

$sql_branches = "
    SELECT 
        b.id, 
        b.name as branch_name,
        b.status as branch_status,
        (SELECT COALESCE(NULLIF(u.full_name, ''), u.username) FROM users u WHERE u.branch_id = b.id AND LOWER(u.role) = 'bm' AND LOWER(u.status) = 'active' LIMIT 1) as bm_name,
        (SELECT COUNT(*) FROM clients c WHERE c.branch_id = b.id AND c.status = 'active') as active_clients,
        (SELECT COALESCE(SUM(CASE WHEN amount < 0 OR LOWER(s.type) IN ('withdrawal', 'return', 'adjust') THEN -ABS(amount) ELSE amount END), 0) 
         FROM saving_collections s JOIN clients c2 ON s.client_id = c2.id 
         WHERE c2.branch_id = b.id AND CAST(s.date AS DATE) BETWEEN :start_date1 AND :end_date1) as monthly_net_savings,
        (SELECT COALESCE(SUM(lc.amount_collected), 0) 
         FROM loan_collections lc JOIN clients c3 ON lc.client_id = c3.id 
         WHERE c3.branch_id = b.id AND CAST(lc.date AS DATE) BETWEEN :start_date2 AND :end_date2) as monthly_collections,
        (SELECT COALESCE(SUM(d.remaining_balance), 0) 
         FROM disbursements d JOIN clients c4 ON d.client_id = c4.id 
         WHERE c4.branch_id = b.id AND d.remaining_balance > 0) as outstanding_portfolio,
         (SELECT COUNT(*) FROM users u WHERE u.branch_id = b.id AND u.role = 'co' AND u.status = 'active') as active_cos
    FROM branches b
    WHERE b.area_id = :aid
    ORDER BY monthly_collections DESC, b.name ASC
";

$stmt_branches = $pdo->prepare($sql_branches);
$stmt_branches->execute([
    'aid' => $area_id,
    'start_date1' => $start_of_month, 'end_date1' => $today,
    'start_date2' => $start_of_month, 'end_date2' => $today
]);
$branches = $stmt_branches->fetchAll();

// Calculate Totals for the header
$total_branches = count($branches);
$total_area_clients = array_sum(array_column($branches, 'active_clients'));
$total_area_savings = array_sum(array_column($branches, 'monthly_net_savings'));
$total_area_collections = array_sum(array_column($branches, 'monthly_collections'));

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>Branches Overview | CUPAD</title>
    
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
        .main-header { background: var(--bg-header); backdrop-filter: blur(12px); border-bottom: 1px solid var(--border-color); position: sticky; top: 0; z-index: 50; padding-top: env(safe-area-inset-top); }
        .navbar { display: flex; justify-content: space-between; align-items: center; padding: 0.8rem 1rem; max-width: 1280px; margin: 0 auto; }
        .logo { font-weight: 700; font-size: 1.25rem; color: var(--primary-color); display: flex; align-items: center; gap: 0.5rem; text-decoration: none; }
        .logo img { height: 32px; width: auto; }
        
        .profile-btn { width: 36px; height: 36px; border-radius: 50%; overflow: hidden; border: 2px solid var(--primary-color); background: var(--bg-secondary); cursor: pointer; display: flex; align-items: center; justify-content: center; position: relative; }
        .profile-btn img { width: 100%; height: 100%; object-fit: cover; }
        .profile-fallback-icon { font-size: 1.2rem; color: var(--text-secondary); }
        
        /* Dashboard Stats Grid (Scrollable on Mobile) */
        .dashboard-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1rem; margin-bottom: 2rem; }
        .dashboard-card { border-radius: var(--border-radius-xl); padding: 1.5rem; position: relative; overflow: hidden; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1); border: none; color: white; transition: transform 0.2s ease; }
        .dashboard-card:active { transform: scale(0.98); }
        .card-gradient-blue { background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); }
        .card-gradient-green { background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%); }
        .card-gradient-orange { background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); }
        .card-gradient-purple { background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%); }
        .card-bg-icon { position: absolute; right: -10px; bottom: -20px; font-size: 6rem; color: white; opacity: 0.1; transform: rotate(-10deg); pointer-events: none; }

        /* Branches Specific Grid */
        .branches-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 1.5rem; }
        .branch-card { background: var(--bg-card); border-radius: var(--border-radius-xl); overflow: hidden; box-shadow: var(--shadow-sm); border: 1px solid var(--border-color); transition: transform 0.2s, box-shadow 0.2s; }
        .branch-card:hover { transform: translateY(-3px); box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1); }
        
        .branch-header { padding: 1.25rem; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: flex-start; }
        .branch-title { font-weight: 800; font-size: 1.15rem; color: var(--text-primary); display: flex; align-items: center; gap: 0.5rem; }
        .branch-status { font-size: 0.7rem; font-weight: 700; padding: 0.2rem 0.6rem; border-radius: 12px; text-transform: uppercase; }
        .status-active { background: rgba(34, 197, 94, 0.1); color: var(--success-color); }
        .status-inactive { background: rgba(239, 68, 68, 0.1); color: var(--error-color); }
        
        .bm-info { display: flex; align-items: center; gap: 0.75rem; padding-top: 0.75rem; }
        .bm-avatar { width: 32px; height: 32px; border-radius: 50%; background: var(--bg-primary); display: flex; align-items: center; justify-content: center; color: var(--text-secondary); }
        .bm-details { display: flex; flex-direction: column; }
        .bm-name { font-size: 0.85rem; font-weight: 600; color: var(--text-primary); }
        .bm-role { font-size: 0.7rem; color: var(--text-secondary); }

        .branch-body { padding: 1.25rem; }
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
        .btn-view { padding: 0.4rem 1rem; background: var(--primary-color); color: white; border-radius: 8px; font-size: 0.8rem; font-weight: 600; text-decoration: none; transition: background 0.2s; box-shadow: 0 2px 5px rgba(59, 130, 246, 0.3); }
        .btn-view:hover { background: #2563eb; transform: translateY(-1px); box-shadow: 0 4px 8px rgba(59, 130, 246, 0.4); }

        .mobile-bottom-nav { display: none; position: fixed; bottom: 0; left: 0; right: 0; background: var(--bg-secondary); border-top: 1px solid var(--border-color); padding: 0.5rem 1rem calc(0.5rem + env(safe-area-inset-bottom)); z-index: 100; justify-content: space-between; box-shadow: 0 -4px 10px rgba(0,0,0,0.05); }
        .nav-item { display: flex; flex-direction: column; align-items: center; justify-content: center; text-decoration: none; color: var(--text-secondary); font-size: 0.7rem; font-weight: 500; gap: 4px; flex: 1; }
        .nav-item i { font-size: 1.4rem; }
        .nav-item.active { color: var(--primary-color); }
        
        @media (max-width: 768px) { 
            .mobile-bottom-nav { display: flex; } 
            
            /* Mobile Scrollable Dashboard Grid */
            .dashboard-grid { display: flex; overflow-x: auto; scroll-snap-type: x mandatory; gap: 1rem; padding-bottom: 0.5rem; margin-right: -1rem; padding-right: 1.5rem; scrollbar-width: none; }
            .dashboard-grid::-webkit-scrollbar { display: none; }
            .dashboard-card { min-width: 85vw; scroll-snap-align: center; flex-shrink: 0; }
        }
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
            </div>
        </nav>
    </header>

    <!-- MAIN CONTENT -->
    <main class="max-w-7xl mx-auto px-4 py-6">
        
        <!-- Page Header -->
        <div class="mb-6 flex items-center justify-between">
            <div>
                <a href="dashboard.php" class="text-blue-500 hover:underline text-sm mb-1 inline-block">
                    <i class="fas fa-arrow-left mr-1"></i> Back to Dashboard
                </a>
                <h1 class="text-2xl font-extrabold text-gray-900 dark:text-white">Area Branches</h1>
            </div>
        </div>

        <!-- STATS (Scrollable Dashboard Grid) -->
        <div class="dashboard-grid">
            <div class="dashboard-card card-gradient-purple">
                <i class="fas fa-building card-bg-icon"></i>
                <div class="flex justify-between items-start relative z-10">
                    <div class="min-w-0">
                        <div class="text-sm font-semibold uppercase opacity-90">Total Branches</div>
                        <div class="text-2xl lg:text-3xl font-extrabold mt-1 mb-2 truncate"><?php echo number_format($total_branches); ?></div>
                        <div class="text-xs bg-black/20 rounded-full px-3 py-1 inline-flex items-center"><i class="fas fa-map-marked-alt mr-1"></i> Area-wide</div>
                    </div>
                </div>
            </div>

            <div class="dashboard-card card-gradient-blue">
                <i class="fas fa-users card-bg-icon"></i>
                <div class="flex justify-between items-start relative z-10">
                    <div class="min-w-0">
                        <div class="text-sm font-semibold uppercase opacity-90">Total Clients</div>
                        <div class="text-2xl lg:text-3xl font-extrabold mt-1 mb-2 truncate"><?php echo number_format($total_area_clients); ?></div>
                        <div class="text-xs bg-black/20 rounded-full px-3 py-1 inline-flex items-center"><i class="fas fa-check-circle mr-1"></i> Active</div>
                    </div>
                </div>
            </div>

            <div class="dashboard-card card-gradient-green">
                <i class="fas fa-piggy-bank card-bg-icon"></i>
                <div class="flex justify-between items-start relative z-10">
                    <div class="min-w-0">
                        <div class="text-sm font-semibold uppercase opacity-90">Net Savings</div>
                        <div class="text-2xl lg:text-3xl font-extrabold mt-1 mb-2 truncate" title="₦<?php echo number_format($total_area_savings); ?>">₦<?php echo number_format($total_area_savings); ?></div>
                        <div class="text-xs bg-black/20 rounded-full px-3 py-1 inline-flex items-center"><i class="fas fa-chart-line mr-1"></i> This Month</div>
                    </div>
                </div>
            </div>

            <div class="dashboard-card card-gradient-orange">
                <i class="fas fa-coins card-bg-icon"></i>
                <div class="flex justify-between items-start relative z-10">
                    <div class="min-w-0">
                        <div class="text-sm font-semibold uppercase opacity-90">Collections</div>
                        <div class="text-2xl lg:text-3xl font-extrabold mt-1 mb-2 truncate" title="₦<?php echo number_format($total_area_collections); ?>">₦<?php echo number_format($total_area_collections); ?></div>
                        <div class="text-xs bg-black/20 rounded-full px-3 py-1 inline-flex items-center"><i class="fas fa-calendar-alt mr-1"></i> This Month</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Search / Filter -->
        <div class="mb-6 flex justify-between items-center">
            <div class="relative w-full max-w-md">
                <i class="fas fa-search absolute left-4 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                <input type="text" id="branchSearch" placeholder="Search branches..." class="w-full pl-11 pr-4 py-3 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500 shadow-sm transition-all">
            </div>
        </div>

        <!-- Branches Grid -->
        <div class="branches-grid" id="branchesContainer">
            <?php foreach ($branches as $branch): ?>
                <?php 
                    $status_class = strtolower($branch['branch_status']) === 'active' ? 'status-active' : 'status-inactive';
                    $bm_display = $branch['bm_name'] ?? 'Unassigned';
                ?>
                <div class="branch-card" data-name="<?php echo strtolower(htmlspecialchars($branch['branch_name'])); ?>">
                    <div class="branch-header">
                        <div>
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
                                <span class="metric-label"><i class="fas fa-piggy-bank text-gray-400"></i> MTD Savings</span>
                                <span class="metric-value <?php echo $branch['monthly_net_savings'] < 0 ? 'val-error' : 'val-success'; ?>">
                                    ₦<?php echo number_format($branch['monthly_net_savings']); ?>
                                </span>
                            </div>
                            <div class="metric-item">
                                <span class="metric-label"><i class="fas fa-hand-holding-usd text-gray-400"></i> MTD Collection</span>
                                <span class="metric-value val-primary">₦<?php echo number_format($branch['monthly_collections']); ?></span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="branch-footer">
                        <span class="co-count-badge">
                            <i class="fas fa-briefcase mr-1 text-gray-500"></i> <?php echo number_format($branch['active_cos']); ?> COs
                        </span>
                        <!-- Pass branch_id via URL parameter for analytics filtering -->
                        <a href="analytics.php?branch_id=<?php echo urlencode($branch['id']); ?>" class="btn-view">
                            View Reports <i class="fas fa-arrow-right ml-1 text-xs"></i>
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>

            <?php if(empty($branches)): ?>
                <div class="col-span-full text-center py-12 bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700">
                    <i class="fas fa-building text-4xl text-gray-300 dark:text-gray-600 mb-3"></i>
                    <h3 class="text-lg font-bold text-gray-900 dark:text-white">No Branches Found</h3>
                    <p class="text-gray-500 dark:text-gray-400 mt-1">There are currently no active branches assigned to your area.</p>
                </div>
            <?php endif; ?>
        </div>

    </main>

    <!-- MOBILE BOTTOM NAVIGATION (AM) -->
    <nav class="mobile-bottom-nav">
        <a href="dashboard.php" class="nav-item">
             <i class="fas fa-home"></i>
             <span>Home</span>
        </a>
        <a href="branches.php" class="nav-item active">
            <i class="fas fa-building text-blue-500" style="font-size: 1.5rem;"></i>
            <span class="text-blue-500 font-bold">Branches</span>
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

        // Simple Client-side Search for Branches
        document.getElementById('branchSearch').addEventListener('input', function(e) {
            const term = e.target.value.toLowerCase();
            const cards = document.querySelectorAll('.branch-card');
            
            cards.forEach(card => {
                const name = card.getAttribute('data-name');
                if(name.includes(term)) {
                    card.style.display = 'block';
                } else {
                    card.style.display = 'none';
                }
            });
        });
    </script>
</body>
</html>