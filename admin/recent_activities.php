<?php
date_default_timezone_set('Africa/Lagos');
ini_set('date.timezone', 'Africa/Lagos');
session_start();
require_once '../includes/config.php';
$conn = getDbConnection();

if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: ../index.php');
    exit();
}

$base_path = '../';
$current_username = $_SESSION['username'] ?? '';
$full_name = $_SESSION['full_name'] ?? 'Admin';
$current_role = $_SESSION['user_role'] ?? 'admin';

$stmt = $conn->prepare("SELECT profile_pic FROM users WHERE username = ?");
$stmt->execute([$current_username]);
$currentUser = $stmt->fetch();
$profile_pic_path = '';
$has_profile_pic = false;

if ($currentUser && !empty($currentUser['profile_pic']) && $currentUser['profile_pic'] !== 'default_avatar.png') {
    $profile_pic_path = $base_path . $currentUser['profile_pic'];
    if (file_exists($profile_pic_path)) {
        $has_profile_pic = true;
    }
}

$hour = date('H');
if ($hour < 12) $greeting = "Good Morning";
elseif ($hour < 17) $greeting = "Good Afternoon";
else $greeting = "Good Evening";
?>
<!DOCTYPE html>
<html lang="en" class="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recent Activities - CUPAD Admin</title>
    <link rel="icon" type="image/png" href="<?php echo htmlspecialchars($base_path . 'uploads/CUPAD%20LOGO.png'); ?>">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />
    <style>
        :root { --primary: #2563eb; --primary-dark: #1d4ed8; --success: #059669; --warning: #d97706; --danger: #dc2626; --info: #0284c7; --bg-body: #f1f5f9; --bg-surface: #ffffff; --text-main: #0f172a; --text-muted: #64748b; --border: #e2e8f0; --radius-lg: 16px; --radius-md: 10px; --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05); --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1); --nav-height: 70px; }
        html.dark { --bg-body: #0f172a; --bg-surface: #1e293b; --text-main: #f8fafc; --text-muted: #94a3b8; --border: #334155; --primary: #3b82f6; --success: #34d399; --warning: #fbbf24; --danger: #f87171; --info: #38bdf8; }
        * { box-sizing: border-box; outline: none; }
        body { font-family: 'Plus Jakarta Sans', sans-serif; background: var(--bg-body); color: var(--text-main); margin: 0; padding-top: var(--nav-height); transition: background-color 0.3s, color 0.3s; }
        a { text-decoration: none; color: inherit; }
        .main-header { position: fixed; top: 0; left: 0; right: 0; height: var(--nav-height); background: rgba(255,255,255,0.85); backdrop-filter: blur(12px); border-bottom: 1px solid var(--border); z-index: 50; transition: background 0.3s; }
        html.dark .main-header { background: rgba(15, 23, 42, 0.85); }
        .navbar { max-width: 1400px; margin: 0 auto; padding: 0 1.5rem; display: flex; align-items: center; justify-content: space-between; height: 100%; }
        .logo { display: flex; align-items: center; gap: 0.75rem; font-weight: 800; font-size: 1.35rem; color: var(--primary); }
        .logo img { height: 38px; }
        .nav-right { display: flex; align-items: center; gap: 1rem; }
        .icon-btn { width: 36px; height: 36px; border-radius: 50%; border: none; background: transparent; color: var(--text-muted); cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; transition: 0.2s; }
        .icon-btn:hover { background: var(--bg-body); color: var(--text-main); }
        .user-dropdown-wrap { position: relative; }
        .user-pill { display: flex; align-items: center; gap: 0.75rem; padding: 4px 8px 4px 4px; border: 1px solid var(--border); border-radius: 99px; background: var(--bg-surface); cursor: pointer; transition: all 0.2s; }
        .user-pill:hover { border-color: var(--primary); box-shadow: var(--shadow-sm); }
        .user-avatar { width: 34px; height: 34px; border-radius: 50%; object-fit: cover; }
        .user-avatar-fallback { width: 34px; height: 34px; border-radius: 50%; background: var(--bg-body); color: var(--text-muted); display: flex; align-items: center; justify-content: center; font-size: 1rem; border: 1px solid var(--border); }
        .user-info { display: flex; flex-direction: column; line-height: 1.1; padding-right: 0.5rem; }
        .user-name { font-weight: 600; font-size: 0.85rem; }
        .user-role { font-size: 0.7rem; color: var(--text-muted); text-transform: uppercase; }
        .dropdown-menu { position: absolute; top: 125%; right: 0; background: var(--bg-surface); border: 1px solid var(--border); border-radius: var(--radius-md); box-shadow: var(--shadow-md); min-width: 200px; display: none; z-index: 1000; flex-direction: column; overflow: hidden; animation: scaleIn 0.2s ease; transform-origin: top right; }
        @keyframes scaleIn { from { opacity: 0; transform: scale(0.95); } to { opacity: 1; transform: scale(1); } }
        .dropdown-menu.show { display: flex; }
        .dropdown-item { padding: 0.75rem 1rem; display: flex; align-items: center; gap: 0.75rem; font-size: 0.9rem; transition: 0.2s; }
        .dropdown-item:hover { background: var(--bg-body); color: var(--primary); }
        .text-danger { color: var(--danger) !important; }
        .container { max-width: 1200px; margin: 0 auto; padding: 2rem 1.5rem 4rem; }
        .page-header { margin-bottom: 2rem; }
        .page-title { margin: 0 0 0.5rem; font-size: 1.75rem; font-weight: 800; display: flex; align-items: center; gap: 0.75rem; }
        .page-subtitle { margin: 0; color: var(--text-muted); font-size: 0.95rem; }
        .controls-bar { display: flex; gap: 1rem; margin-bottom: 1.5rem; flex-wrap: wrap; align-items: center; }
        .search-wrapper { position: relative; flex: 1; min-width: 250px; max-width: 400px; }
        .search-wrapper input { width: 100%; padding: 0.7rem 2.5rem 0.7rem 2.5rem; border-radius: 99px; border: 1px solid var(--border); background: var(--bg-surface); color: var(--text-main); transition: 0.2s; }
        .search-wrapper input:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(37,99,235,0.1); }
        .search-wrapper i { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: var(--text-muted); font-size: 0.9rem; }
        .filter-group { display: flex; gap: 0.5rem; flex-wrap: wrap; }
        .filter-btn { padding: 0.6rem 1rem; border-radius: 99px; border: 1px solid var(--border); background: var(--bg-surface); color: var(--text-muted); cursor: pointer; font-size: 0.85rem; font-weight: 600; transition: 0.2s; display: flex; align-items: center; gap: 0.5rem; }
        .filter-btn:hover { border-color: var(--primary); color: var(--primary); }
        .filter-btn.active { background: var(--primary); color: white; border-color: var(--primary); }
        .card { background: var(--bg-surface); border: 1px solid var(--border); border-radius: var(--radius-lg); padding: 1.5rem; box-shadow: var(--shadow-sm); }
        .activity-list { display: flex; flex-direction: column; gap: 0; }
        .activity-item { display: flex; gap: 1rem; padding: 1.25rem 0; border-bottom: 1px solid var(--border); animation: fadeIn 0.3s ease; transition: background 0.2s; margin: 0 -1rem; padding-left: 1rem; padding-right: 1rem; }
        .activity-item:last-child { border-bottom: none; }
        .activity-item:hover { background: var(--bg-body); }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(5px); } to { opacity: 1; transform: translateY(0); } }
        .activity-icon { width: 48px; height: 48px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.3rem; flex-shrink: 0; }
        .icon-success { background: rgba(5, 150, 105, 0.1); color: var(--success); }
        .icon-warning { background: rgba(217, 119, 6, 0.1); color: var(--warning); }
        .icon-info { background: rgba(2, 132, 199, 0.1); color: var(--info); }
        .icon-danger { background: rgba(220, 38, 38, 0.1); color: var(--danger); }
        .icon-muted { background: rgba(100, 116, 139, 0.1); color: var(--text-muted); }
        .activity-content { flex: 1; min-width: 0; }
        .activity-header { display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; margin-bottom: 0.5rem; flex-wrap: wrap; }
        .activity-title { font-weight: 700; font-size: 1rem; color: var(--text-main); }
        .activity-time { font-size: 0.8rem; color: var(--text-muted); white-space: nowrap; }
        .activity-description { font-size: 0.9rem; color: var(--text-muted); line-height: 1.5; word-break: break-word; }
        .skeleton { background: #e2e8f0; border-radius: 4px; animation: pulse 1.5s infinite; }
        html.dark .skeleton { background: #334155; }
        @keyframes pulse { 0% { opacity: 0.6; } 50% { opacity: 1; } 100% { opacity: 0.6; } }
        .sk-item { display: flex; gap: 1rem; padding: 1.25rem 0; border-bottom: 1px solid var(--border); }
        .sk-icon { width: 48px; height: 48px; border-radius: 12px; }
        .sk-content { flex: 1; }
        .sk-line { height: 14px; margin-bottom: 8px; border-radius: 4px; }
        .sk-line.title { width: 60%; }
        .sk-line.desc { width: 85%; }
        .loading-more { text-align: center; padding: 1.5rem; color: var(--text-muted); }
        .empty-state { text-align: center; padding: 4rem 2rem; color: var(--text-muted); }
        .empty-state i { font-size: 3rem; margin-bottom: 1rem; opacity: 0.5; display: block; }
        .stats-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 2rem; }
        .stat-box { background: var(--bg-surface); border: 1px solid var(--border); border-radius: var(--radius-md); padding: 1.25rem; display: flex; align-items: center; gap: 1rem; }
        .stat-icon { width: 48px; height: 48px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.3rem; flex-shrink: 0; }
        .stat-content { flex: 1; }
        .stat-value { font-size: 1.5rem; font-weight: 800; line-height: 1; margin-bottom: 0.25rem; }
        .stat-label { font-size: 0.8rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; }
    </style>
</head>
<body>
    <header class="main-header">
        <div class="navbar">
            <a href="dashboard.php" class="logo">
                <img src="<?php echo htmlspecialchars($base_path . 'uploads/CUPAD%20LOGO.png'); ?>" alt="Logo" onerror="this.style.display='none'">
                <span>CUPAD</span>
            </a>
            <div class="nav-right">
                <a href="dashboard.php" class="icon-btn" title="Dashboard"><i class="fas fa-home"></i></a>
                <button class="icon-btn" id="themeToggle"><i class="fas fa-moon"></i></button>
                <div class="user-dropdown-wrap">
                    <div class="user-pill" id="userDropdownTrigger">
                        <?php if($has_profile_pic): ?>
                            <img src="<?php echo htmlspecialchars($profile_pic_path); ?>" class="user-avatar" alt="User">
                        <?php else: ?>
                            <div class="user-avatar-fallback"><i class="fas fa-user"></i></div>
                        <?php endif; ?>
                        <div class="user-info">
                            <span class="user-name"><?php echo htmlspecialchars($full_name); ?></span>
                            <span class="user-role"><?php echo ucfirst(htmlspecialchars($current_role)); ?></span>
                        </div>
                        <i class="fas fa-chevron-down" style="font-size:0.7rem; color:var(--text-muted)"></i>
                    </div>
                    <div class="dropdown-menu" id="userDropdown">
                        <div style="padding: 0.75rem 1rem; font-size: 0.75rem; color:var(--text-muted); font-weight:600">SIGNED IN AS</div>
                        <div style="padding: 0 1rem 0.5rem; font-weight:700"><?php echo htmlspecialchars($current_username); ?></div>
                        <div style="height:1px; background:var(--border); margin:0"></div>
                        <a href="profile.php" class="dropdown-item"><i class="fas fa-user-circle" style="color:var(--primary)"></i> My Profile</a>
                        <div style="height:1px; background:var(--border); margin:0"></div>
                        <a href="../logout.php" class="dropdown-item text-danger"><i class="fas fa-sign-out-alt"></i> Logout</a>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <main class="container">
        <div class="page-header">
            <h1 class="page-title">
                <i class="fas fa-history" style="color:var(--primary)"></i>
                Recent Activities
            </h1>
            <p class="page-subtitle">Real-time system activity log showing all transactions and events</p>
        </div>

        <div class="stats-row">
            <div class="stat-box">
                <div class="stat-icon icon-success"><i class="fas fa-check-circle"></i></div>
                <div class="stat-content">
                    <div class="stat-value" id="totalActivities">0</div>
                    <div class="stat-label">Total Activities</div>
                </div>
            </div>
            <div class="stat-box">
                <div class="stat-icon icon-info"><i class="fas fa-clock"></i></div>
                <div class="stat-content">
                    <div class="stat-value" id="todayActivities">0</div>
                    <div class="stat-label">Today</div>
                </div>
            </div>
            <div class="stat-box">
                <div class="stat-icon icon-warning"><i class="fas fa-calendar-week"></i></div>
                <div class="stat-content">
                    <div class="stat-value" id="weekActivities">0</div>
                    <div class="stat-label">This Week</div>
                </div>
            </div>
        </div>

        <div class="controls-bar">
            <div class="search-wrapper">
                <i class="fas fa-search"></i>
                <input type="text" id="searchInput" placeholder="Search activities...">
            </div>
            <div class="filter-group">
                <button class="filter-btn active" data-filter="all"><i class="fas fa-list"></i> All</button>
                <button class="filter-btn" data-filter="disbursement"><i class="fas fa-money-bill-wave"></i> Loans</button>
                <button class="filter-btn" data-filter="repayment"><i class="fas fa-hand-holding-usd"></i> Repayments</button>
                <button class="filter-btn" data-filter="savings"><i class="fas fa-piggy-bank"></i> Savings</button>
                <button class="filter-btn" data-filter="new_client"><i class="fas fa-user-plus"></i> Clients</button>
            </div>
            <button class="icon-btn" onclick="reloadActivities()" title="Refresh" style="margin-left:auto">
                <i class="fas fa-sync-alt"></i>
            </button>
        </div>

        <div class="card">
            <div class="activity-list" id="activityList"></div>
        </div>
    </main>

    <script>
        const userTrigger = document.getElementById('userDropdownTrigger');
        const userDropdown = document.getElementById('userDropdown');
        userTrigger.addEventListener('click', (e) => { e.stopPropagation(); userDropdown.classList.toggle('show'); });
        document.addEventListener('click', (e) => { if (!userTrigger.contains(e.target) && !userDropdown.contains(e.target)) userDropdown.classList.remove('show'); });

        document.getElementById('themeToggle').addEventListener('click', () => {
            const html = document.documentElement;
            const isDark = html.classList.toggle('dark');
            html.classList.toggle('light');
            localStorage.setItem('theme', isDark ? 'dark' : 'light');
            document.querySelector('#themeToggle i').className = isDark ? 'fas fa-sun' : 'fas fa-moon';
        });
        if(localStorage.getItem('theme') === 'dark') {
            document.documentElement.classList.add('dark');
            document.documentElement.classList.remove('light');
            document.querySelector('#themeToggle i').className = 'fas fa-sun';
        }

        let page = 1;
        let isLoading = false;
        let hasMore = true;
        let currentFilter = 'all';
        let searchQuery = '';
        let allActivities = [];

        const activityList = document.getElementById('activityList');

        function getSkeletonHTML() {
            return `<div class="sk-item"><div class="skeleton sk-icon"></div><div class="sk-content"><div class="skeleton sk-line title"></div><div class="skeleton sk-line desc"></div></div></div>`.repeat(5);
        }

        async function loadActivities() {
            if(isLoading || !hasMore) return;
            isLoading = true;

            if(page === 1) activityList.innerHTML = getSkeletonHTML();
            else {
                const loader = document.createElement('div');
                loader.className = 'loading-more';
                loader.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i> Loading more...';
                loader.id = 'loader';
                activityList.appendChild(loader);
            }

            try {
                const res = await fetch(`fetch_activities.php?page=${page}&limit=20`);
                if (!res.ok) {
                    throw new Error(`HTTP error! status: ${res.status}`);
                }
                const text = await res.text();
                
                if (!text.trim().startsWith('{') && !text.trim().startsWith('[')) {
                    console.error('Invalid JSON response:', text.substring(0, 500));
                    throw new Error('Server returned invalid JSON');
                }
                
                const data = JSON.parse(text);

                if(data.error) {
                    console.error('Server error:', data.error);
                    if(page === 1) activityList.innerHTML = `<div class="empty-state"><i class="fas fa-exclamation-circle"></i><p>Error: ${data.error}</p></div>`;
                    return;
                }

                if(page === 1) {
                    activityList.innerHTML = '';
                    allActivities = [];
                }
                document.getElementById('loader')?.remove();

                if(data.activities && data.activities.length > 0) {
                    allActivities = allActivities.concat(data.activities);
                    renderActivities(data.activities);
                    page++;
                    hasMore = data.has_more;
                    updateStats();
                } else if (page === 1) {
                    activityList.innerHTML = '<div class="empty-state"><i class="fas fa-inbox"></i><p>No activities found.</p></div>';
                }
            } catch(e) {
                console.error('Load activities error:', e);
                if(page === 1) activityList.innerHTML = `<div class="empty-state"><i class="fas fa-exclamation-circle"></i><p>Error loading activities: ${e.message}</p><p style="font-size:0.8rem; margin-top:0.5rem;">Check browser console for details</p></div>`;
            } finally {
                isLoading = false;
            }
        }

        function renderActivities(activities) {
            activities.forEach(act => {
                if(currentFilter !== 'all') {
                    const actType = act.raw_data?.source_table || '';
                    const actSubtype = act.raw_data?.transaction_subtype || '';
                    if(currentFilter === 'disbursement' && actType !== 'disbursements') return;
                    if(currentFilter === 'repayment' && actType !== 'loan_collections') return;
                    if(currentFilter === 'savings' && actType !== 'saving_collections') return;
                    if(currentFilter === 'new_client' && actType !== 'clients') return;
                }

                if(searchQuery) {
                    const searchText = (act.title + ' ' + act.description).toLowerCase();
                    if(!searchText.includes(searchQuery.toLowerCase())) return;
                }

                let iconClass = act.type === 'success' ? 'icon-success' : 
                              (act.type === 'warning' ? 'icon-warning' : 
                               (act.type === 'danger' ? 'icon-danger' : 
                                (act.type === 'info' ? 'icon-info' : 'icon-muted')));

                const html = `
                    <div class="activity-item">
                        <div class="activity-icon ${iconClass}">
                            <i class="${act.icon}"></i>
                        </div>
                        <div class="activity-content">
                            <div class="activity-header">
                                <div class="activity-title">${act.title}</div>
                                <div class="activity-time">${act.time_ago}</div>
                            </div>
                            <div class="activity-description">${act.description}</div>
                        </div>
                    </div>
                `;
                activityList.insertAdjacentHTML('beforeend', html);
            });
        }

        function reloadActivities() {
            page = 1;
            hasMore = true;
            allActivities = [];
            activityList.innerHTML = '';
            loadActivities();
        }

        function updateStats() {
            const total = allActivities.length;
            const today = new Date().toISOString().split('T')[0];
            const weekAgo = new Date(Date.now() - 7 * 24 * 60 * 60 * 1000).toISOString();

            const todayCount = allActivities.filter(a => a.timestamp?.startsWith(today)).length;
            const weekCount = allActivities.filter(a => a.timestamp >= weekAgo).length;

            document.getElementById('totalActivities').textContent = total;
            document.getElementById('todayActivities').textContent = todayCount;
            document.getElementById('weekActivities').textContent = weekCount;
        }

        document.querySelectorAll('.filter-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                currentFilter = this.dataset.filter;
                reloadActivities();
            });
        });

        let searchTimeout;
        document.getElementById('searchInput').addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                searchQuery = this.value;
                reloadActivities();
            }, 500);
        });

        window.addEventListener('scroll', () => {
            if(isLoading || !hasMore) return;
            if(window.innerHeight + window.scrollY >= document.body.offsetHeight - 500) {
                loadActivities();
            }
        });

        loadActivities();
    </script>
</body>
</html>
