<?php
// tm/zones.php - Top Management Zones Overview
date_default_timezone_set('Africa/Lagos');
session_start();
require_once '../includes/config.php';
$pdo = getDbConnection();

// 1. Authentication & Role Check
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'tm') {
    if (isset($_GET['action'])) {
        http_response_code(403);
        echo json_encode(['error' => 'Unauthorized']);
        exit();
    }
    header('Location: ../index.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username']; 
$base_path = '../';

// Fetch the TM's profile pic
$stmt_u = $pdo->prepare("SELECT profile_pic FROM users WHERE id = :uid");
$stmt_u->execute(['uid' => $user_id]);
$user_data = $stmt_u->fetch();
$profile_pic = $user_data['profile_pic'] ?? 'default_avatar.png';

// 2. Fetch Zones and Aggregate Metrics
$start_of_month = date('Y-m-01');
$today = date('Y-m-d');

$sql_zones = "
    SELECT 
        z.id, 
        z.name as zone_name,
        (SELECT COALESCE(NULLIF(u.full_name, ''), u.username) FROM users u WHERE u.zone_id = z.id AND LOWER(u.role) = 'zm' AND LOWER(u.status) = 'active' LIMIT 1) as zm_name,
        (SELECT COUNT(*) FROM areas a WHERE a.zone_id = z.id) as total_areas,
        (SELECT COUNT(*) FROM branches b JOIN areas a ON b.area_id = a.id WHERE a.zone_id = z.id) as total_branches,
        (SELECT COUNT(*) FROM clients c JOIN branches b ON c.branch_id = b.id JOIN areas a ON b.area_id = a.id WHERE a.zone_id = z.id AND c.status = 'active') as active_clients,
        (SELECT COALESCE(SUM(CASE WHEN s.amount < 0 OR LOWER(s.type) IN ('withdrawal', 'return', 'adjust') THEN -ABS(s.amount) ELSE s.amount END), 0) 
         FROM saving_collections s JOIN clients c ON s.client_id = c.id JOIN branches b ON c.branch_id = b.id JOIN areas a ON b.area_id = a.id
         WHERE a.zone_id = z.id AND CAST(s.date AS DATE) BETWEEN :start_date1 AND :end_date1) as monthly_net_savings,
        (SELECT COALESCE(SUM(lc.amount_collected), 0) 
         FROM loan_collections lc JOIN clients c ON lc.client_id = c.id JOIN branches b ON c.branch_id = b.id JOIN areas a ON b.area_id = a.id
         WHERE a.zone_id = z.id AND CAST(lc.date AS DATE) BETWEEN :start_date2 AND :end_date2) as monthly_collections,
        (SELECT COALESCE(SUM(d.remaining_balance), 0) 
         FROM disbursements d JOIN clients c ON d.client_id = c.id JOIN branches b ON c.branch_id = b.id JOIN areas a ON b.area_id = a.id
         WHERE a.zone_id = z.id AND d.remaining_balance > 0) as outstanding_portfolio
    FROM zones z
    ORDER BY z.name ASC
";

$stmt_zones = $pdo->prepare($sql_zones);
$stmt_zones->execute([
    'start_date1' => $start_of_month,
    'end_date1' => $today,
    'start_date2' => $start_of_month,
    'end_date2' => $today
]);
$zones = $stmt_zones->fetchAll(PDO::FETCH_ASSOC);

// Calculate Totals for the Dashboard Header
$total_zones = count($zones);
$total_system_areas = array_sum(array_column($zones, 'total_areas'));
$total_system_portfolio = array_sum(array_column($zones, 'outstanding_portfolio'));
$total_system_clients = array_sum(array_column($zones, 'active_clients'));
$total_system_savings = array_sum(array_column($zones, 'monthly_net_savings'));
$total_system_collections = array_sum(array_column($zones, 'monthly_collections'));

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>Zones Overview | CUPAD TM</title>
    
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">    
    <script src="https://cdn.tailwindcss.com"></script>

    <script>
    tailwind.config = {
        darkMode: 'class',
        theme: {
            extend: {
                colors: {
                    primary: '#3b82f6', secondary: '#8b5cf6',
                    success: '#22c55e', warning: '#f59e0b', error: '#ef4444',
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
        
        /* Dashboard Carousel */
        .dashboard-carousel-wrapper { position: relative; margin-bottom: 2rem; }
        .dashboard-carousel { display: flex; gap: 1rem; overflow-x: auto; scroll-snap-type: x mandatory; scrollbar-width: none; padding-bottom: 0.5rem; scroll-behavior: smooth; -webkit-overflow-scrolling: touch; }
        .dashboard-carousel::-webkit-scrollbar { display: none; }
        .dashboard-card { border-radius: var(--border-radius-xl); padding: 1.5rem; position: relative; overflow: hidden; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1); border: none; color: white; transition: transform 0.2s ease; min-width: 260px; flex: 1 0 auto; scroll-snap-align: start; }
        .dashboard-card:active { transform: scale(0.98); }
        
        .card-gradient-blue { background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); }
        .card-gradient-green { background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%); }
        .card-gradient-orange { background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); }
        .card-gradient-purple { background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%); }
        .card-gradient-teal { background: linear-gradient(135deg, #14b8a6 0%, #0d9488 100%); }
        .card-bg-icon { position: absolute; right: -10px; bottom: -20px; font-size: 6rem; color: white; opacity: 0.1; transform: rotate(-10deg); pointer-events: none; }

        /* Carousel Navigation Buttons */
        .carousel-btn { position: absolute; top: 50%; transform: translateY(-50%); width: 40px; height: 40px; border-radius: 50%; background: var(--bg-card); box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); display: flex; align-items: center; justify-content: center; color: var(--text-primary); border: 1px solid var(--border-color); cursor: pointer; z-index: 10; opacity: 0; transition: opacity 0.2s, background 0.2s; }
        .dashboard-carousel-wrapper:hover .carousel-btn { opacity: 1; }
        .carousel-btn:hover { background: var(--bg-primary); }
        .carousel-btn.left { left: -15px; }
        .carousel-btn.right { right: -15px; }
        @media (max-width: 768px) {
            .carousel-btn { display: none; }
            .dashboard-card { min-width: 85vw; }
        }

        /* Zones Grid */
        .zones-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 1.5rem; }
        .zone-card { background: var(--bg-card); border-radius: var(--border-radius-xl); overflow: hidden; box-shadow: var(--shadow-sm); border: 1px solid var(--border-color); transition: transform 0.2s, box-shadow 0.2s; display: flex; flex-direction: column; }
        .zone-card:hover { transform: translateY(-3px); box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1); }
        
        .zone-header { padding: 1.25rem; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: flex-start; background: linear-gradient(to right, rgba(139, 92, 246, 0.05), transparent); }
        .zone-title { font-weight: 800; font-size: 1.25rem; color: var(--text-primary); display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.5rem; }
        
        .zm-info { display: flex; align-items: center; gap: 0.75rem; }
        .zm-avatar { width: 36px; height: 36px; border-radius: 50%; background: var(--bg-primary); display: flex; align-items: center; justify-content: center; color: var(--secondary-color); border: 1px solid var(--border-color); }
        .zm-details { display: flex; flex-direction: column; }
        .zm-name { font-size: 0.9rem; font-weight: 600; color: var(--text-primary); }
        .zm-role { font-size: 0.75rem; color: var(--text-secondary); }

        .zone-body { padding: 1.25rem; flex: 1; }
        .metrics-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
        .metric-item { display: flex; flex-direction: column; gap: 0.25rem; }
        .metric-label { font-size: 0.75rem; color: var(--text-secondary); display: flex; align-items: center; gap: 0.35rem; }
        .metric-value { font-size: 1.05rem; font-weight: 700; color: var(--text-primary); }
        .val-success { color: var(--success-color); }
        .val-primary { color: var(--primary-color); }
        .val-warning { color: var(--warning-color); }

        .zone-footer { padding: 1rem 1.25rem; background: var(--bg-primary); border-top: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; }
        .sub-entities { font-size: 0.75rem; color: var(--text-secondary); font-weight: 600; display: flex; gap: 0.75rem; }
        .sub-badge { background: var(--bg-secondary); padding: 0.3rem 0.6rem; border-radius: 8px; border: 1px solid var(--border-color); display: flex; align-items: center; gap: 0.25rem; }
        
        .btn-view { padding: 0.4rem 1rem; background: var(--secondary-color); color: white; border-radius: 8px; font-size: 0.8rem; font-weight: 600; text-decoration: none; transition: background 0.2s; }
        .btn-view:hover { background: #7c3aed; }

        /* Mobile Nav */
        .mobile-bottom-nav { display: none; position: fixed; bottom: 0; left: 0; right: 0; background: var(--bg-secondary); border-top: 1px solid var(--border-color); padding: 0.5rem 1rem calc(0.5rem + env(safe-area-inset-bottom)); z-index: 100; justify-content: space-between; box-shadow: 0 -4px 10px rgba(0,0,0,0.05); }
        .nav-item { display: flex; flex-direction: column; align-items: center; justify-content: center; text-decoration: none; color: var(--text-secondary); font-size: 0.7rem; font-weight: 500; gap: 4px; flex: 1; }
        .nav-item i { font-size: 1.4rem; }
        .nav-item.active { color: var(--primary-color); }
        
        @media (max-width: 768px) { 
            .mobile-bottom-nav { display: flex; } 
        }
    </style>
</head>

<body>
    <!-- HEADER -->
    <header class="main-header">
        <nav class="navbar">
            <a href="dashboard.php" class="logo">
                <img src="<?php echo htmlspecialchars($base_path . 'favicon.ico'); ?>" alt="Logo">
                <span>CUPAD TM</span>
            </a>
            <div class="flex items-center gap-3">
                <button id="theme-toggle" class="p-2 text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800 rounded-full transition">
                    <i class="fas fa-moon dark:hidden"></i><i class="fas fa-sun hidden dark:inline"></i>
                </button>
                <div class="profile-btn" onclick="window.location.href='profile.php'">
                    <i class="fas fa-user text-gray-500 text-xl"></i>
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
        <div class="mb-6 flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div>
                <h1 class="text-2xl font-extrabold text-gray-900 dark:text-white">Zones Overview</h1>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">High-level view of all organizational zones.</p>
            </div>
        </div>

        <!-- STATS CAROUSEL -->
        <div class="dashboard-carousel-wrapper group">
            <button id="scrollLeft" class="carousel-btn left">
                <i class="fas fa-chevron-left"></i>
            </button>
            
            <div class="dashboard-carousel" id="statCarousel">
                
                <div class="dashboard-card card-gradient-purple">
                    <i class="fas fa-layer-group card-bg-icon"></i>
                    <div class="flex justify-between items-start relative z-10">
                        <div class="min-w-0">
                            <div class="text-sm font-semibold uppercase opacity-90">Total Zones</div>
                            <div class="text-2xl lg:text-3xl font-extrabold mt-1 mb-2 truncate"><?php echo number_format($total_zones); ?></div>
                            <div class="text-xs bg-black/20 rounded-full px-3 py-1 inline-flex items-center"><i class="fas fa-map-marked-alt mr-1"></i> <?php echo number_format($total_system_areas); ?> Attached Areas</div>
                        </div>
                    </div>
                </div>

                <div class="dashboard-card card-gradient-blue">
                    <i class="fas fa-users card-bg-icon"></i>
                    <div class="flex justify-between items-start relative z-10">
                        <div class="min-w-0">
                            <div class="text-sm font-semibold uppercase opacity-90">Total Clients</div>
                            <div class="text-2xl lg:text-3xl font-extrabold mt-1 mb-2 truncate"><?php echo number_format($total_system_clients); ?></div>
                            <div class="text-xs bg-black/20 rounded-full px-3 py-1 inline-flex items-center"><i class="fas fa-globe mr-1"></i> System-Wide Active</div>
                        </div>
                    </div>
                </div>

                <div class="dashboard-card card-gradient-green">
                    <i class="fas fa-briefcase card-bg-icon"></i>
                    <div class="flex justify-between items-start relative z-10">
                        <div class="min-w-0">
                            <div class="text-sm font-semibold uppercase opacity-90">Total Portfolio</div>
                            <div class="text-2xl lg:text-3xl font-extrabold mt-1 mb-2 truncate" title="₦<?php echo number_format($total_system_portfolio); ?>">₦<?php echo number_format($total_system_portfolio); ?></div>
                            <div class="text-xs bg-black/20 rounded-full px-3 py-1 inline-flex items-center"><i class="fas fa-wallet mr-1"></i> Active Loans</div>
                        </div>
                    </div>
                </div>

                <div class="dashboard-card card-gradient-teal">
                    <i class="fas fa-piggy-bank card-bg-icon"></i>
                    <div class="flex justify-between items-start relative z-10">
                        <div class="min-w-0">
                            <div class="text-sm font-semibold uppercase opacity-90">Total Savings</div>
                            <div class="text-2xl lg:text-3xl font-extrabold mt-1 mb-2 truncate" title="₦<?php echo number_format($total_system_savings); ?>">₦<?php echo number_format($total_system_savings); ?></div>
                            <div class="text-xs bg-black/20 rounded-full px-3 py-1 inline-flex items-center"><i class="fas fa-calendar-alt mr-1"></i> MTD Global Net</div>
                        </div>
                    </div>
                </div>

                <div class="dashboard-card card-gradient-orange">
                    <i class="fas fa-hand-holding-usd card-bg-icon"></i>
                    <div class="flex justify-between items-start relative z-10">
                        <div class="min-w-0">
                            <div class="text-sm font-semibold uppercase opacity-90">Total Collections</div>
                            <div class="text-2xl lg:text-3xl font-extrabold mt-1 mb-2 truncate" title="₦<?php echo number_format($total_system_collections); ?>">₦<?php echo number_format($total_system_collections); ?></div>
                            <div class="text-xs bg-black/20 rounded-full px-3 py-1 inline-flex items-center"><i class="fas fa-money-bill-wave mr-1"></i> MTD System-Wide</div>
                        </div>
                    </div>
                </div>
                
            </div>
            
            <button id="scrollRight" class="carousel-btn right">
                <i class="fas fa-chevron-right"></i>
            </button>
        </div>

        <!-- Search Bar -->
        <div class="mb-6 relative max-w-md">
            <i class="fas fa-search absolute left-4 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
            <input type="text" id="zoneSearch" placeholder="Search zones or managers..." class="w-full pl-11 pr-4 py-3 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500 shadow-sm transition-all">
        </div>

        <!-- Zones Grid -->
        <div class="zones-grid" id="zonesContainer">
            <?php foreach ($zones as $zone): ?>
                <?php 
                    $zm_display = $zone['zm_name'] ?? 'No Manager Assigned';
                    $search_string = strtolower($zone['zone_name'] . ' ' . $zm_display);
                ?>
                <div class="zone-card" data-search="<?php echo htmlspecialchars($search_string); ?>">
                    <div class="zone-header">
                        <div>
                            <h3 class="zone-title">
                                <i class="fas fa-layer-group text-purple-500"></i> 
                                <?php echo htmlspecialchars($zone['zone_name']); ?>
                            </h3>
                            <div class="zm-info">
                                <div class="zm-avatar">
                                    <i class="fas <?php echo $zone['zm_name'] ? 'fa-user-tie' : 'fa-user-minus text-red-400'; ?>"></i>
                                </div>
                                <div class="zm-details">
                                    <span class="zm-name <?php echo !$zone['zm_name'] ? 'text-red-500 italic' : ''; ?>">
                                        <?php echo htmlspecialchars($zm_display); ?>
                                    </span>
                                    <span class="zm-role">Zonal Manager</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="zone-body">
                        <div class="metrics-grid">
                            <div class="metric-item">
                                <span class="metric-label"><i class="fas fa-users text-gray-400"></i> Active Clients</span>
                                <span class="metric-value"><?php echo number_format($zone['active_clients']); ?></span>
                            </div>
                            <div class="metric-item">
                                <span class="metric-label"><i class="fas fa-wallet text-gray-400"></i> Outstanding Loan</span>
                                <span class="metric-value val-warning">₦<?php echo number_format($zone['outstanding_portfolio']); ?></span>
                            </div>
                            <div class="metric-item">
                                <span class="metric-label"><i class="fas fa-piggy-bank text-gray-400"></i> MTD Savings</span>
                                <span class="metric-value <?php echo $zone['monthly_net_savings'] < 0 ? 'val-error' : 'val-success'; ?>">
                                    ₦<?php echo number_format($zone['monthly_net_savings']); ?>
                                </span>
                            </div>
                            <div class="metric-item">
                                <span class="metric-label"><i class="fas fa-hand-holding-usd text-gray-400"></i> MTD Collection</span>
                                <span class="metric-value val-primary">₦<?php echo number_format($zone['monthly_collections']); ?></span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="zone-footer">
                        <div class="sub-entities">
                            <span class="sub-badge"><i class="fas fa-map-marked-alt text-pink-500"></i> <?php echo number_format($zone['total_areas']); ?> Areas</span>
                            <span class="sub-badge"><i class="fas fa-building text-blue-500"></i> <?php echo number_format($zone['total_branches']); ?> Br</span>
                        </div>
                        <a href="areas.php?zone_id=<?php echo urlencode($zone['id']); ?>" class="btn-view">
                            View Areas
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>

            <?php if(empty($zones)): ?>
                <div class="col-span-full text-center py-12 bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700">
                    <i class="fas fa-layer-group text-4xl text-gray-300 dark:text-gray-600 mb-3"></i>
                    <h3 class="text-lg font-bold text-gray-900 dark:text-white">No Zones Found</h3>
                    <p class="text-gray-500 dark:text-gray-400 mt-1">There are currently no active zones in the system.</p>
                </div>
            <?php endif; ?>
        </div>

    </main>

    <!-- MOBILE BOTTOM NAVIGATION -->
    <nav class="mobile-bottom-nav">
        <a href="dashboard.php" class="nav-item">
            <i class="fas fa-home"></i>
            <span>Home</span>
        </a>
        <a href="zones.php" class="nav-item active">
             <div style="background: var(--primary-color); width: 45px; height: 45px; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; margin-top: -25px; box-shadow: 0 4px 10px rgba(59, 130, 246, 0.4);">
                <i class="fas fa-layer-group" style="font-size: 1.2rem; margin:0;"></i>
             </div>
             <span style="font-size:0.65rem; font-weight:600; margin-top:2px;">Zones</span>
        </a>
        <a href="branches.php" class="nav-item">
            <i class="fas fa-building"></i>
            <span>Branches</span>
        </a>
        <a href="users.php" class="nav-item">
            <i class="fas fa-user-tie"></i>
            <span>Staff</span>
        </a>
        <a href="analytics.php" class="nav-item">
            <i class="fas fa-chart-bar"></i>
            <span>Reports</span>
        </a>
    </nav>

    <!-- JS SCRIPTS -->
    <script>
        // Dark Mode Logic
        const t = localStorage.getItem('theme');
        if(t==='dark') document.documentElement.classList.add('dark');
        document.getElementById('theme-toggle').addEventListener('click', () => {
            document.documentElement.classList.toggle('dark');
            localStorage.setItem('theme', document.documentElement.classList.contains('dark') ? 'dark' : 'light');
        });

        // Carousel Scroll Logic
        const carousel = document.getElementById('statCarousel');
        const scrollLeftBtn = document.getElementById('scrollLeft');
        const scrollRightBtn = document.getElementById('scrollRight');

        if (scrollLeftBtn && scrollRightBtn && carousel) {
            scrollLeftBtn.addEventListener('click', () => {
                carousel.scrollBy({ left: -300, behavior: 'smooth' });
            });
            scrollRightBtn.addEventListener('click', () => {
                carousel.scrollBy({ left: 300, behavior: 'smooth' });
            });
        }

        // Search Logic
        const searchInput = document.getElementById('zoneSearch');
        const cards = document.querySelectorAll('.zone-card');

        searchInput.addEventListener('input', (e) => {
            const term = e.target.value.toLowerCase();
            cards.forEach(card => {
                const searchData = card.getAttribute('data-search');
                if (searchData.includes(term)) {
                    card.style.display = 'flex';
                } else {
                    card.style.display = 'none';
                }
            });
        });
    </script>
</body>
</html>