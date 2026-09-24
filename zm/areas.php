<?php
// zm/areas.php - Zonal Manager Areas Overview
session_start();
require_once '../includes/config.php';
$pdo = getDbConnection();

// Ensure User is Logged In and is a Zonal Manager (zm)
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'zm') {
    header('Location: ../index.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username']; 
$base_path = '../';

// Fetch the ZM's zone_id, zone name, and profile pic
$stmt_u = $pdo->prepare("
    SELECT u.zone_id, u.profile_pic, z.name as zone_name 
    FROM users u 
    LEFT JOIN zones z ON u.zone_id = z.id 
    WHERE u.id = :uid
");
$stmt_u->execute(['uid' => $user_id]);
$user_info = $stmt_u->fetch();

$zone_id = $user_info['zone_id'];
$zone_name = $user_info['zone_name'] ?? 'Unassigned';
$profile_pic = $user_info['profile_pic'] ?? 'default_avatar.png';

$current_month = date('Y-m');

// Fetch all areas for this zone with their aggregated statistics
$sql_areas = "
    SELECT 
        a.id, 
        a.name as area_name,
        (SELECT COUNT(*) FROM branches b WHERE b.area_id = a.id) as branch_count,
        (SELECT COUNT(*) FROM clients c WHERE c.branch_id IN (SELECT id FROM branches WHERE area_id = a.id) AND c.status = 'active') as active_clients,
        (SELECT COALESCE(SUM(CASE WHEN amount < 0 OR LOWER(s.type) IN ('withdrawal', 'return', 'adjust') THEN -ABS(amount) ELSE amount END), 0) 
         FROM saving_collections s JOIN clients c2 ON s.client_id = c2.id 
         WHERE c2.branch_id IN (SELECT id FROM branches WHERE area_id = a.id) AND DATE_FORMAT(s.date, '%Y-%m') = :m1) as monthly_net_savings,
        (SELECT COALESCE(SUM(lc.amount_collected), 0) 
         FROM loan_collections lc JOIN clients c3 ON lc.client_id = c3.id 
         WHERE c3.branch_id IN (SELECT id FROM branches WHERE area_id = a.id) AND DATE_FORMAT(lc.date, '%Y-%m') = :m2) as monthly_collections,
        (SELECT COALESCE(SUM(d.remaining_balance), 0) 
         FROM disbursements d JOIN clients c4 ON d.client_id = c4.id 
         WHERE c4.branch_id IN (SELECT id FROM branches WHERE area_id = a.id) AND d.remaining_balance > 0) as outstanding_portfolio
    FROM areas a
    WHERE a.zone_id = :zid
    ORDER BY a.name ASC
";

$stmt_areas = $pdo->prepare($sql_areas);
$stmt_areas->execute([
    'zid' => $zone_id, 
    'm1' => $current_month, 
    'm2' => $current_month
]);
$areas = $stmt_areas->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>Manage Areas | CUPAD ZM</title>
    
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

        /* Notification Styles */
        #notification-dropdown { width: 360px; transform-origin: top right; transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1); border-radius: 16px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04); overflow: hidden; border: 1px solid var(--border-color); }
        #notification-dropdown.hidden { display: none; opacity: 0; transform: scale(0.95); }
        #notification-dropdown:not(.hidden) { display: block; opacity: 1; transform: scale(1); }
        .notification-item { padding: 12px 16px; display: flex; gap: 12px; align-items: flex-start; border-bottom: 1px solid var(--border-color); cursor: pointer; transition: all 0.2s ease; position: relative; }
        .notification-item:hover { background-color: var(--bg-primary); }
        .notification-item.unread { background-color: rgba(59, 130, 246, 0.05); }
        .notification-item:last-child { border-bottom: none; }
        .notif-icon { width: 32px; height: 32px; border-radius: 50%; background: var(--bg-primary); display: flex; align-items: center; justify-content: center; color: var(--primary-color); flex-shrink: 0; font-size: 0.85rem; margin-top: 2px; border: 1px solid var(--border-color); }
        .notification-item.unread .notif-icon { background: var(--primary-color); color: white; border-color: transparent; }
        .notif-content { flex: 1; }
        .notif-message { font-size: 0.85rem; line-height: 1.4; color: var(--text-primary); margin-bottom: 4px; }
        .notification-item.unread .notif-message { font-weight: 500; }
        .notification-item.read .notif-message { color: var(--text-secondary); }
        .notif-time { font-size: 0.7rem; color: var(--text-secondary); display: flex; align-items: center; gap: 4px; }
        .notif-dot { width: 8px; height: 8px; border-radius: 50%; background-color: var(--error-color); margin-top: 8px; flex-shrink: 0; box-shadow: 0 0 0 2px var(--bg-secondary); }

        .search-container { background: var(--bg-card); border-radius: var(--border-radius-lg); padding: 1rem; margin-bottom: 1.5rem; box-shadow: var(--shadow-sm); border: 1px solid var(--border-color); }
        .search-input { width: 100%; padding: 0.75rem 1rem 0.75rem 2.5rem; border: 1px solid var(--border-color); border-radius: 0.75rem; background: var(--bg-primary); color: var(--text-primary); font-size: 1rem; }
        
        .area-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 1.25rem; }
        .area-card { background: var(--bg-card); border-radius: var(--border-radius-lg); padding: 1.25rem; border: 1px solid var(--border-color); box-shadow: var(--shadow-sm); transition: transform 0.2s, box-shadow 0.2s; display: flex; flex-direction: column; }
        .area-card:active { transform: scale(0.98); }
        
        .area-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1rem; padding-bottom: 1rem; border-bottom: 1px dashed var(--border-color); }
        .area-title { font-size: 1.1rem; font-weight: 700; display: flex; align-items: center; gap: 0.5rem; color: var(--text-primary); }
        .area-icon { width: 40px; height: 40px; border-radius: 10px; background: linear-gradient(135deg, #ec4899, #be185d); color: white; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; }
        
        .stat-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; margin-bottom: 1rem; }
        .stat-box { background: var(--bg-primary); padding: 0.75rem; border-radius: 0.5rem; }
        .stat-label { font-size: 0.75rem; color: var(--text-secondary); margin-bottom: 0.25rem; font-weight: 500; }
        .stat-value { font-size: 1.1rem; font-weight: 700; color: var(--text-primary); }
        .stat-value.success { color: var(--success-color); }
        .stat-value.primary { color: var(--primary-color); }
        .stat-value.warning { color: var(--warning-color); }
        
        .action-row { margin-top: auto; padding-top: 1rem; border-top: 1px solid var(--border-color); }
        .btn-view { display: block; width: 100%; text-align: center; background: var(--primary-color); color: white; padding: 0.75rem; border-radius: 0.5rem; font-weight: 600; text-decoration: none; transition: background 0.2s; box-shadow: 0 4px 6px -1px rgba(59, 130, 246, 0.2); }
        .btn-view:hover { background: #2563eb; }

        .mobile-bottom-nav { display: none; position: fixed; bottom: 0; left: 0; right: 0; background: var(--bg-secondary); border-top: 1px solid var(--border-color); padding: 0.5rem 1rem calc(0.5rem + env(safe-area-inset-bottom)); z-index: 100; justify-content: space-between; box-shadow: 0 -4px 10px rgba(0,0,0,0.05); }
        .nav-item { display: flex; flex-direction: column; align-items: center; justify-content: center; text-decoration: none; color: var(--text-secondary); font-size: 0.7rem; font-weight: 500; gap: 4px; flex: 1; }
        .nav-item i { font-size: 1.4rem; }
        .nav-item.active { color: var(--primary-color); }

        @media (max-width: 768px) {
            .mobile-bottom-nav { display: flex; }
            #notification-dropdown { position: fixed; top: 60px; left: 1rem; right: 1rem; width: auto; max-width: none; }
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
                
                <!-- Notification Bell -->
                <div class="relative">
                    <button id="notification-btn" class="p-2 text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800 rounded-full transition relative">
                        <i class="fas fa-bell text-lg"></i>
                        <span id="notification-badge" class="absolute -top-1 -right-1 min-w-[18px] h-[18px] bg-red-500 rounded-full text-white text-[10px] font-bold flex items-center justify-center border-2 border-white dark:border-gray-900 hidden px-1">0</span>
                    </button>
                    <!-- Dropdown -->
                    <div id="notification-dropdown" class="hidden absolute right-0 mt-3 bg-white dark:bg-gray-800 z-50">
                        <div class="p-3 border-b border-gray-100 dark:border-gray-700 flex justify-between items-center bg-gray-50 dark:bg-gray-900/50 backdrop-blur">
                            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Notifications</h3>
                            <div class="flex gap-3">
                                <button id="show-read-btn" class="text-xs text-gray-500 hover:text-gray-700 dark:hover:text-gray-300 font-medium transition">Show read</button>
                                <button id="mark-read-btn" class="text-xs text-blue-600 dark:text-blue-400 font-medium hover:underline">Mark all read</button>
                            </div>
                        </div>
                        <div id="notification-list" class="max-h-[325px] overflow-y-auto">
                            <!-- Items inserted here via JS -->
                        </div>
                    </div>
                </div>

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
        <div class="mb-4 flex items-center gap-2">
            <a href="dashboard.php" class="text-gray-500 dark:text-gray-400 hover:text-blue-600 dark:hover:text-blue-400 transition flex items-center gap-1 font-medium text-sm">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>
        </div>

        <div class="mb-6">
            <h1 class="text-2xl font-extrabold text-gray-900 dark:text-white">Areas Overview</h1>
            <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">
                <i class="fas fa-map mr-1"></i> Zone: <span class="font-semibold text-gray-800 dark:text-gray-200"><?php echo htmlspecialchars($zone_name); ?></span>
            </p>
        </div>

        <div class="search-container">
            <div class="relative">
                <i class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                <input type="text" id="areaSearch" class="search-input" placeholder="Search areas by name...">
            </div>
        </div>

        <div class="area-grid" id="areaContainer">
            <?php if (empty($areas)): ?>
                <div class="col-span-full bg-white dark:bg-gray-800 rounded-xl p-8 text-center border border-gray-200 dark:border-gray-700">
                    <i class="fas fa-map-marked-alt text-4xl text-gray-300 dark:text-gray-600 mb-3"></i>
                    <h3 class="text-lg font-bold text-gray-700 dark:text-gray-300">No Areas Found</h3>
                    <p class="text-gray-500 mt-1">There are currently no areas assigned to this zone.</p>
                </div>
            <?php else: ?>
                <?php foreach ($areas as $area): ?>
                    <div class="area-card" data-name="<?php echo strtolower(htmlspecialchars($area['area_name'])); ?>">
                        <div class="area-header">
                            <div class="flex items-center gap-3">
                                <div class="area-icon">
                                    <i class="fas fa-map-marked-alt"></i>
                                </div>
                                <div>
                                    <h2 class="area-title"><?php echo htmlspecialchars($area['area_name']); ?></h2>
                                    <div class="text-xs text-gray-500 mt-1">
                                        <span class="bg-gray-100 dark:bg-gray-700 px-2 py-1 rounded-full"><i class="fas fa-building mr-1"></i> <?php echo number_format($area['branch_count']); ?> Branches</span>
                                        <span class="bg-gray-100 dark:bg-gray-700 px-2 py-1 rounded-full"><i class="fas fa-users mr-1"></i> <?php echo number_format($area['active_clients']); ?> Clients</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="stat-grid">
                            <div class="stat-box">
                                <div class="stat-label">Monthly Net Savings</div>
                                <div class="stat-value success truncate" title="₦<?php echo number_format($area['monthly_net_savings']); ?>">₦<?php echo number_format($area['monthly_net_savings']); ?></div>
                            </div>
                            <div class="stat-box">
                                <div class="stat-label">Monthly Collections</div>
                                <div class="stat-value primary truncate" title="₦<?php echo number_format($area['monthly_collections']); ?>">₦<?php echo number_format($area['monthly_collections']); ?></div>
                            </div>
                            <div class="stat-box col-span-2 flex justify-between items-center border-l-4 border-yellow-500 pl-3 bg-gray-50 dark:bg-gray-800">
                                <span class="stat-label mb-0 text-gray-700 dark:text-gray-300">Outstanding Portfolio</span>
                                <span class="stat-value warning font-bold">₦<?php echo number_format($area['outstanding_portfolio']); ?></span>
                            </div>
                        </div>

                        <div class="action-row">
                            <a href="branches.php>" class="btn-view">
                                <i class="fas fa-eye mr-1"></i> View Branches
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </main>

    <!-- MOBILE BOTTOM NAVIGATION (ZM) -->
    <nav class="mobile-bottom-nav">
        <a href="dashboard.php" class="nav-item">
            <i class="fas fa-home"></i>
            <span>Home</span>
        </a>
        <a href="areas.php" class="nav-item active">
             <div style="background: var(--primary-color); width: 45px; height: 45px; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; margin-top: -25px; box-shadow: 0 4px 10px rgba(59, 130, 246, 0.4);">
                <i class="fas fa-map-marked-alt" style="font-size: 1.2rem; margin:0;"></i>
             </div>
             <span style="font-size:0.65rem; font-weight:600; margin-top:2px;">Areas</span>
        </a>
        <a href="branches.php" class="nav-item">
            <i class="fas fa-building"></i>
            <span>Branches</span>
        </a>
        <a href="analytics.php" class="nav-item">
            <i class="fas fa-chart-bar"></i>
            <span>Reports</span>
        </a>
    </nav>

    <script src="../js/heartbeat.js"></script>
    <script>
        // --- Theme & UI Logic ---
        const themeToggle = document.getElementById('theme-toggle');
        if (localStorage.getItem('theme') === 'dark') document.documentElement.classList.add('dark');
        
        themeToggle.addEventListener('click', () => {
            document.documentElement.classList.toggle('dark');
            localStorage.setItem('theme', document.documentElement.classList.contains('dark') ? 'dark' : 'light');
        });

        // --- Search Filter Logic ---
        const searchInput = document.getElementById('areaSearch');
        const cards = document.querySelectorAll('.area-card');

        searchInput.addEventListener('input', function(e) {
            const searchTerm = e.target.value.toLowerCase();
            cards.forEach(card => {
                const areaName = card.getAttribute('data-name');
                card.style.display = areaName.includes(searchTerm) ? 'flex' : 'none';
            });
        });

        // --- Notification Logic (Pulls from dashboard.php) ---
        class NotificationManager {
            constructor() {
                this.showRead = false;
                this.badge = document.getElementById('notification-badge');
                this.list = document.getElementById('notification-list');
                this.btn = document.getElementById('notification-btn');
                this.dropdown = document.getElementById('notification-dropdown');
                this.init();
            }

            init() {
                this.btn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    this.dropdown.classList.toggle('hidden');
                });

                document.addEventListener('click', (e) => {
                    if (!this.btn.contains(e.target) && !this.dropdown.contains(e.target)) {
                        this.dropdown.classList.add('hidden');
                    }
                });

                document.getElementById('show-read-btn').addEventListener('click', () => {
                    this.showRead = !this.showRead;
                    document.getElementById('show-read-btn').textContent = this.showRead ? 'Show unread' : 'Show read';
                    this.fetchNotifications();
                });

                document.getElementById('mark-read-btn').addEventListener('click', () => this.markAsRead());

                this.fetchNotifications();
                setInterval(() => this.fetchNotifications(), 15000);
            }

            async fetchNotifications() {
                try {
                    const res = await fetch(`dashboard.php?action=fetch_notifications&show_read=${this.showRead}`);
                    const notifications = await res.json();
                    
                    const unreadCount = notifications.filter(n => !n.read).length;
                    if (unreadCount > 0) {
                        this.badge.textContent = unreadCount > 9 ? '9+' : unreadCount;
                        this.badge.classList.remove('hidden');
                    } else {
                        this.badge.classList.add('hidden');
                    }

                    if (notifications.length > 0) {
                        this.list.innerHTML = notifications.map(n => `
                            <div class="notification-item ${n.read ? 'read' : 'unread'}">
                                <div class="notif-icon"><i class="fas fa-bell"></i></div>
                                <div class="notif-content">
                                    <p class="notif-message">${n.message}</p>
                                    <span class="notif-time">${new Date(n.timestamp).toLocaleString([], {month:'short', day:'numeric', hour:'2-digit', minute:'2-digit'})}</span>
                                </div>
                                ${!n.read ? '<div class="notif-dot"></div>' : ''}
                            </div>
                        `).join('');
                    } else {
                        this.list.innerHTML = `
                            <div class="flex flex-col items-center justify-center py-8 text-gray-400">
                                <i class="far fa-bell-slash text-2xl mb-2 opacity-50"></i>
                                <span class="text-sm">No notifications</span>
                            </div>`;
                    }
                } catch (e) { console.error("Notification error:", e); }
            }

            async markAsRead() {
                try {
                    await fetch('dashboard.php?action=mark_notifications_read', { method: 'POST' });
                    this.badge.classList.add('hidden');
                    this.fetchNotifications();
                } catch (e) {}
            }
        }
        new NotificationManager();
    </script>
</body>
</html>