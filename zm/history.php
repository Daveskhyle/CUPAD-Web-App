<?php
date_default_timezone_set('Africa/Lagos');
session_start();
require_once '../includes/config.php';
$pdo = getDbConnection();

// --- AUTHENTICATION ---
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'zm') {
    if (isset($_GET['action'])) {
        http_response_code(403);
        echo json_encode(['error' => 'Unauthorized']);
        exit();
    }
    header('Location: ../index.php');
    exit();
}

$user_id = $_SESSION['user_id'];

// Fetch the ZM's zone_id
$stmt_z = $pdo->prepare("SELECT zone_id FROM users WHERE id = :uid");
$stmt_z->execute(['uid' => $user_id]);
$zone_id = $stmt_z->fetchColumn();

if (!$zone_id) {
    die("Access denied: No zone assigned.");
}

// =================================================================
// --- AJAX HANDLER: Load History Data ---
// =================================================================
if (isset($_GET['action']) && $_GET['action'] === 'load_history') {
    header('Content-Type: application/json');

    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
    $offset = ($page - 1) * $limit;

    // Filters
    $search = $_GET['search'] ?? '';
    $type_filter = $_GET['type'] ?? 'all';
    $area_filter = $_GET['area'] ?? 'all';
    $officer_filter = $_GET['officer'] ?? 'all';
    $start_date = $_GET['start_date'] ?? '';
    $end_date = $_GET['end_date'] ?? '';

    // 1. Determine which COs to include based on Area & Officer filters
    $sql_cos = "
        SELECT u.username 
        FROM users u
        JOIN branches b ON u.branch_id = b.id
        JOIN areas a ON b.area_id = a.id
        WHERE a.zone_id = ? AND u.role = 'co' AND u.status = 'active'
    ";
    $cos_params =[$zone_id];

    if ($area_filter !== 'all') {
        $sql_cos .= " AND a.id = ?";
        $cos_params[] = $area_filter;
    }
    if ($officer_filter !== 'all') {
        $sql_cos .= " AND u.username = ?";
        $cos_params[] = $officer_filter;
    }

    $stmt_cos = $pdo->prepare($sql_cos);
    $stmt_cos->execute($cos_params);
    $co_usernames = $stmt_cos->fetchAll(PDO::FETCH_COLUMN);

    if (empty($co_usernames)) {
        echo json_encode(['success' => true, 'html' => '', 'has_more' => false]);
        exit();
    }

    $placeholders = implode(',', array_fill(0, count($co_usernames), '?'));

    // 2. Build the UNION query
    $sql = "
        SELECT type, client_name, amount, date, officer, activity_id FROM (
            SELECT 
                CASE 
                    WHEN s.type IN ('withdrawal', 'return') OR s.amount < 0 THEN 'Withdrawal'
                    ELSE 'Saving'
                END as type,
                c.name as client_name, ABS(s.amount) as amount, s.date, s.officer, s.id as activity_id
            FROM saving_collections s 
            JOIN clients c ON s.client_id = c.id 
            WHERE s.officer IN ($placeholders)
            
            UNION ALL
            
            SELECT 'Payment' as type, c.name as client_name, p.amount_collected as amount, p.date, p.officer, p.id as activity_id 
            FROM loan_collections p 
            JOIN clients c ON p.client_id = c.id 
            WHERE p.officer IN ($placeholders)
            
            UNION ALL
            
            SELECT 'Disbursement' as type, c.name as client_name, d.principal as amount, d.created_at as date, d.officer, d.id as activity_id 
            FROM disbursements d 
            JOIN clients c ON d.client_id = c.id 
            WHERE d.officer IN ($placeholders)

            UNION ALL

            SELECT 'Registration' as type, r.client_name, r.amount, r.date, r.officer, r.id as activity_id
            FROM registrations r 
            WHERE r.officer IN ($placeholders)
        ) as activities
        WHERE 1=1
    ";

    // Parameters for the 4 subqueries
    $params = array_merge($co_usernames, $co_usernames, $co_usernames, $co_usernames);

    // Apply Filters
    if ($type_filter !== 'all') {
        $filter_map =[
            'saving' => 'Saving', 'withdrawal' => 'Withdrawal',
            'payment' => 'Payment', 'disbursement' => 'Disbursement',
            'registration' => 'Registration'
        ];
        if (isset($filter_map[$type_filter])) {
            $sql .= " AND type = ?";
            $params[] = $filter_map[$type_filter];
        }
    }

    if (!empty($search)) {
        $sql .= " AND client_name LIKE ?";
        $params[] = "%$search%";
    }

    if (!empty($start_date)) {
        $sql .= " AND CAST(date AS DATE) >= ?";
        $params[] = $start_date;
    }

    if (!empty($end_date)) {
        $sql .= " AND CAST(date AS DATE) <= ?";
        $params[] = $end_date;
    }

    $sql .= " ORDER BY date DESC, activity_id DESC LIMIT ? OFFSET ?";
    $params[] = (int)$limit;
    $params[] = (int)$offset;

    $stmt = $pdo->prepare($sql);
    // Bind parameters carefully due to LIMIT/OFFSET needing INT
    foreach ($params as $key => $val) {
        $stmt->bindValue($key + 1, $val, is_int($val) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->execute();
    $activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $has_more = count($activities) === $limit;

    // Generate HTML
    $html = '';
    foreach ($activities as $act) {
        $type = $act['type'];
        $colorClass = '';
        $bgClass = '';
        $icon = '';

        switch ($type) {
            case 'Saving': $colorClass = 'text-emerald-600 dark:text-emerald-400'; $bgClass = 'bg-emerald-100 dark:bg-emerald-500/20'; $icon = 'fa-piggy-bank'; break;
            case 'Withdrawal': $colorClass = 'text-rose-600 dark:text-rose-400'; $bgClass = 'bg-rose-100 dark:bg-rose-500/20'; $icon = 'fa-wallet'; break;
            case 'Payment': $colorClass = 'text-purple-600 dark:text-purple-400'; $bgClass = 'bg-purple-100 dark:bg-purple-500/20'; $icon = 'fa-money-bill-wave'; break;
            case 'Disbursement': $colorClass = 'text-blue-600 dark:text-blue-400'; $bgClass = 'bg-blue-100 dark:bg-blue-500/20'; $icon = 'fa-hand-holding-usd'; break;
            case 'Registration': $colorClass = 'text-indigo-600 dark:text-indigo-400'; $bgClass = 'bg-indigo-100 dark:bg-indigo-500/20'; $icon = 'fa-user-plus'; break;
        }

        $formatted_date = date('M d, Y', strtotime($act['date']));
        $formatted_time = date('h:i A', strtotime($act['date']));

        $html .= '
        <tr class="hover:bg-gray-50 dark:hover:bg-slate-800/50 transition border-b border-gray-200 dark:border-slate-700">
            <td class="p-3">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-full flex items-center justify-center shrink-0 ' . $bgClass . ' ' . $colorClass . '">
                        <i class="fas ' . $icon . '"></i>
                    </div>
                    <div>
                        <div class="font-bold text-gray-900 dark:text-white">' . htmlspecialchars($act['client_name']) . '</div>
                        <div class="text-xs text-gray-500 flex items-center gap-1 mt-0.5"><i class="fas fa-user-tie"></i> ' . htmlspecialchars($act['officer']) . '</div>
                    </div>
                </div>
            </td>
            <td class="p-3">
                <span class="px-2 py-1 text-[0.65rem] font-bold uppercase tracking-wider rounded ' . $bgClass . ' ' . $colorClass . '">' . $type . '</span>
            </td>
            <td class="p-3 font-mono font-bold ' . $colorClass . ' text-sm whitespace-nowrap">
                ₦' . number_format($act['amount']) . '
            </td>
            <td class="p-3 text-sm text-gray-600 dark:text-gray-300 whitespace-nowrap">
                <div class="font-semibold">' . $formatted_date . '</div>
                <div class="text-xs text-gray-400">' . $formatted_time . '</div>
            </td>
        </tr>';
    }

    echo json_encode([
        'success' => true,
        'html' => $html,
        'has_more' => $has_more
    ]);
    exit();
}

// ====================================================================
// --- INITIAL PAGE LOAD: Render the shell and filters ---
// ====================================================================

// Fetch ZM Profile Data
$sql_user = "SELECT full_name, profile_pic FROM users WHERE id = :uid";
$stmt_u = $pdo->prepare($sql_user);
$stmt_u->execute(['uid' => $user_id]);
$user_data = $stmt_u->fetch();

$profile_pic = !empty($user_data['profile_pic']) ? $user_data['profile_pic'] : 'default_avatar.png';

// Fetch Areas in this Zone
$stmt_ar = $pdo->prepare("SELECT id, name FROM areas WHERE zone_id = ? ORDER BY name ASC");
$stmt_ar->execute([$zone_id]);
$zone_areas = $stmt_ar->fetchAll(PDO::FETCH_ASSOC);

// Fetch COs in this Zone
$stmt_cos_list = $pdo->prepare("
    SELECT u.username, u.full_name 
    FROM users u
    JOIN branches b ON u.branch_id = b.id
    JOIN areas a ON b.area_id = a.id
    WHERE a.zone_id = ? AND u.role = 'co' AND u.status = 'active'
    ORDER BY u.username ASC
");
$stmt_cos_list->execute([$zone_id]);
$zone_cos = $stmt_cos_list->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#3b82f6">
    <title>Transaction History | CUPAD ZM</title>
    
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
                }
            }
        }
    }
    </script>

    <style>
        :root {
            --primary-color: #3b82f6; 
            --bg-primary: #f0f2f5; --bg-secondary: #ffffff; --bg-card: #ffffff;
            --bg-header: rgba(255, 255, 255, 0.95);
            --text-primary: #1f2937; --text-secondary: #6b7280; --border-color: #e5e7eb;
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

        /* Filter Inputs */
        .filter-input {
            width: 100%; padding: 0.6rem 1rem; border-radius: 0.75rem; 
            border: 1px solid var(--border-color); background: var(--bg-primary); 
            color: var(--text-primary); font-size: 0.875rem; outline: none;
            transition: border-color 0.2s;
        }
        .filter-input:focus { border-color: var(--primary-color); }
        select.filter-input { appearance: none; background-image: url("data:image/svg+xml;charset=US-ASCII,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%22292.4%22%20height%3D%22292.4%22%3E%3Cpath%20fill%3D%22%236b7280%22%20d%3D%22M287%2069.4a17.6%2017.6%200%200%200-13-5.4H18.4c-5%200-9.3%201.8-12.9%205.4A17.6%2017.6%200%200%200%200%2082.2c0%205%201.8%209.3%205.4%2012.9l128%20127.9c3.6%203.6%207.8%205.4%2012.8%205.4s9.2-1.8%2012.8-5.4L287%2095c3.5-3.5%205.4-7.8%205.4-12.8%200-5-1.9-9.2-5.5-12.8z%22%2F%3E%3C%2Fsvg%3E"); background-repeat: no-repeat; background-position: right 1rem top 50%; background-size: 0.65rem auto; }

        /* Mobile Nav */
        .mobile-bottom-nav { display: none; position: fixed; bottom: 0; left: 0; right: 0; background: var(--bg-secondary); border-top: 1px solid var(--border-color); padding: 0.5rem 1rem calc(0.5rem + env(safe-area-inset-bottom)); z-index: 100; justify-content: space-between; box-shadow: 0 -4px 10px rgba(0,0,0,0.05); }
        .nav-item { display: flex; flex-direction: column; align-items: center; justify-content: center; text-decoration: none; color: var(--text-secondary); font-size: 0.7rem; font-weight: 500; gap: 4px; flex: 1; }
        .nav-item i { font-size: 1.4rem; }
        .nav-item.active { color: var(--primary-color); }
        @media (max-width: 768px) { .mobile-bottom-nav { display: flex; } }

        /* Print Styles */
        @media print {
            .main-header, .mobile-bottom-nav, #filter-section, #load-more-btn { display: none !important; }
            body { background: white; color: black; padding: 0; }
            .print-header { display: block !important; margin-bottom: 20px; text-align: center; }
            table { width: 100%; border-collapse: collapse; }
            th, td { border: 1px solid #ccc; padding: 8px; text-align: left; }
        }
        .print-header { display: none; }
    </style>
</head>

<body>
    <!-- HEADER -->
    <header class="main-header">
        <nav class="navbar">
            <a href="dashboard.php" class="logo"><img src="<?php echo htmlspecialchars('../favicon.ico'); ?>" alt="Logo"><span>CUPAD ZM</span></a>
            <div class="flex items-center gap-3">
                <button id="theme-toggle" class="p-2 text-gray-500 dark:text-gray-400 rounded-full hover:bg-gray-100 dark:hover:bg-gray-800 transition"><i class="fas fa-moon dark:hidden"></i><i class="fas fa-sun hidden dark:inline"></i></button>
                <div class="profile-btn" onclick="window.location.href='profile.php'"><i class="fas fa-user"></i><?php if ($profile_pic !== 'default_avatar.png'): ?><img src="../<?php echo $profile_pic; ?>" alt="Profile" class="absolute inset-0"><?php endif; ?></div>
                <a href="../logout.php" class="p-2 text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-full transition" title="Logout"><i class="fas fa-sign-out-alt"></i></a>
            </div>
        </nav>
    </header>

    <main class="max-w-7xl mx-auto px-4 py-6">
        
        <div class="print-header">
            <h1 class="text-2xl font-bold">Transaction History</h1>
            <p>Zone: <?php echo htmlspecialchars($zone_id); ?> | Printed on: <?php echo date('M d, Y H:i'); ?></p>
        </div>

        <div class="flex justify-between items-end mb-6">
            <div>
                <h1 class="text-2xl font-extrabold text-gray-900 dark:text-white">Transaction History</h1>
                <p class="text-sm text-gray-500 mt-1">View and filter zone-wide activities</p>
            </div>
            <button onclick="window.print()" class="hidden md:flex items-center gap-2 bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 text-gray-700 dark:text-gray-300 px-4 py-2 rounded-lg font-semibold hover:bg-gray-50 dark:hover:bg-slate-700 transition shadow-sm">
                <i class="fas fa-print"></i> Print
            </button>
        </div>

        <!-- FILTERS -->
        <div id="filter-section" class="bg-white dark:bg-slate-800 rounded-xl p-4 shadow-sm border border-gray-200 dark:border-slate-700 mb-6">
            <form id="filter-form" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6 gap-3">
                
                <!-- Search -->
                <div class="xl:col-span-2 relative">
                    <label class="block text-[10px] font-bold uppercase text-gray-500 mb-1">Search Client</label>
                    <i class="fas fa-search absolute left-3 top-[28px] text-gray-400"></i>
                    <input type="text" id="search" name="search" class="filter-input pl-9" placeholder="Enter client name...">
                </div>

                <!-- Date From -->
                <div>
                    <label class="block text-[10px] font-bold uppercase text-gray-500 mb-1">From Date</label>
                    <input type="date" id="start_date" name="start_date" class="filter-input">
                </div>

                <!-- Date To -->
                <div>
                    <label class="block text-[10px] font-bold uppercase text-gray-500 mb-1">To Date</label>
                    <input type="date" id="end_date" name="end_date" class="filter-input">
                </div>

                <!-- Type -->
                <div>
                    <label class="block text-[10px] font-bold uppercase text-gray-500 mb-1">Activity Type</label>
                    <select id="type" name="type" class="filter-input">
                        <option value="all">All Types</option>
                        <option value="saving">Savings</option>
                        <option value="withdrawal">Withdrawals</option>
                        <option value="payment">Repayments</option>
                        <option value="disbursement">Loans</option>
                        <option value="registration">Registrations</option>
                    </select>
                </div>

                <!-- Area -->
                <div>
                    <label class="block text-[10px] font-bold uppercase text-gray-500 mb-1">Area</label>
                    <select id="area" name="area" class="filter-input">
                        <option value="all">All Areas</option>
                        <?php foreach($zone_areas as $ar): ?>
                            <option value="<?php echo $ar['id']; ?>"><?php echo htmlspecialchars($ar['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Officer -->
                <div>
                    <label class="block text-[10px] font-bold uppercase text-gray-500 mb-1">Officer</label>
                    <select id="officer" name="officer" class="filter-input">
                        <option value="all">All Officers</option>
                        <?php foreach($zone_cos as $co): ?>
                            <option value="<?php echo htmlspecialchars($co['username']); ?>">
                                <?php echo htmlspecialchars($co['username']); ?> 
                                <?php if(!empty($co['full_name'])) echo " (" . explode(' ', trim($co['full_name']))[0] . ")"; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <!-- Buttons -->
                <div class="flex items-end gap-2 xl:col-span-6 lg:col-span-3 md:col-span-2">
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2.5 rounded-lg font-bold shadow-md transition flex-1 md:flex-none">
                        <i class="fas fa-filter mr-1"></i> Apply Filters
                    </button>
                    <button type="button" id="reset-btn" class="bg-gray-100 dark:bg-slate-700 hover:bg-gray-200 dark:hover:bg-slate-600 text-gray-700 dark:text-gray-300 px-5 py-2.5 rounded-lg font-bold transition">
                        Reset
                    </button>
                </div>
            </form>
        </div>

        <!-- RESULTS TABLE -->
        <div class="bg-white dark:bg-slate-800 rounded-xl shadow-sm border border-gray-200 dark:border-slate-700 overflow-hidden mb-6">
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="bg-gray-50 dark:bg-slate-900/50 border-b border-gray-200 dark:border-slate-700">
                            <th class="p-4 text-[10px] font-bold uppercase tracking-wider text-gray-500">Client / Officer</th>
                            <th class="p-4 text-[10px] font-bold uppercase tracking-wider text-gray-500">Type</th>
                            <th class="p-4 text-[10px] font-bold uppercase tracking-wider text-gray-500">Amount</th>
                            <th class="p-4 text-[10px] font-bold uppercase tracking-wider text-gray-500">Date & Time</th>
                        </tr>
                    </thead>
                    <tbody id="history-tbody">
                        <!-- Content loaded via AJAX -->
                    </tbody>
                </table>
            </div>
            
            <!-- Loading / Empty States -->
            <div id="loader" class="text-center py-10 hidden">
                <i class="fas fa-spinner fa-spin text-3xl text-blue-500"></i>
                <p class="text-sm text-gray-500 mt-3">Loading history...</p>
            </div>
            <div id="no-results" class="text-center py-12 hidden">
                <div class="w-16 h-16 bg-gray-100 dark:bg-slate-800 rounded-full flex items-center justify-center mx-auto mb-4 text-gray-400 text-2xl">
                    <i class="fas fa-search"></i>
                </div>
                <h3 class="text-lg font-bold text-gray-800 dark:text-white mb-1">No records found</h3>
                <p class="text-gray-500 text-sm">Try adjusting your filters or search term.</p>
            </div>
        </div>

        <!-- LOAD MORE -->
        <div class="text-center mb-10">
            <button id="load-more-btn" class="hidden bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 text-blue-600 dark:text-blue-400 px-6 py-2.5 rounded-full font-bold shadow-sm hover:shadow-md transition">
                Load More Records
            </button>
        </div>

    </main>

    <!-- MOBILE BOTTOM NAVIGATION -->
    <nav class="mobile-bottom-nav">
        <a href="dashboard.php" class="nav-item">
             <i class="fas fa-home"></i>
             <span>Home</span>
        </a>
        <a href="areas.php" class="nav-item">
            <i class="fas fa-map-marked-alt"></i>
            <span>Areas</span>
        </a>
        <a href="branches.php" class="nav-item">
            <i class="fas fa-building"></i>
            <span>Branches</span>
        </a>
        <a href="history.php" class="nav-item active">
            <div style="background: var(--primary-color); width: 45px; height: 45px; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; margin-top: -25px; box-shadow: 0 4px 10px rgba(59, 130, 246, 0.4);">
                <i class="fas fa-history" style="font-size: 1.2rem; margin:0;"></i>
             </div>
            <span style="font-size:0.65rem; font-weight:600; margin-top:2px;">History</span>
        </a>
    </nav>

    <script>
    class HistoryManager {
        constructor() {
            this.page = 1;
            this.isLoading = false;
            this.hasMore = true;
            
            this.tbody = document.getElementById('history-tbody');
            this.loader = document.getElementById('loader');
            this.noResults = document.getElementById('no-results');
            this.loadMoreBtn = document.getElementById('load-more-btn');
            this.form = document.getElementById('filter-form');
            
            this.init();
        }

        init() {
            // Theme setup
            if(localStorage.getItem('theme') === 'dark') document.documentElement.classList.add('dark');
            document.getElementById('theme-toggle').addEventListener('click', () => {
                document.documentElement.classList.toggle('dark');
                localStorage.setItem('theme', document.documentElement.classList.contains('dark') ? 'dark' : 'light');
            });

            // Form Submit (Apply Filters)
            this.form.addEventListener('submit', (e) => {
                e.preventDefault();
                this.page = 1;
                this.tbody.innerHTML = '';
                this.loadData();
            });

            // Reset Button
            document.getElementById('reset-btn').addEventListener('click', () => {
                this.form.reset();
                this.page = 1;
                this.tbody.innerHTML = '';
                this.loadData();
            });

            // Load More Button
            this.loadMoreBtn.addEventListener('click', () => {
                this.page++;
                this.loadData();
            });

            // Initial Load
            this.loadData();
        }

        async loadData() {
            if (this.isLoading) return;
            this.isLoading = true;
            this.noResults.classList.add('hidden');
            this.loadMoreBtn.classList.add('hidden');
            
            if (this.page === 1) {
                this.loader.classList.remove('hidden');
            } else {
                this.loadMoreBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Loading...';
                this.loadMoreBtn.classList.remove('hidden');
            }

            const formData = new FormData(this.form);
            const params = new URLSearchParams(formData);
            params.append('action', 'load_history');
            params.append('page', this.page);
            params.append('limit', 20);

            try {
                // FIX: Use the URL object to safely build the path without duplicate/encoded '?'
                const fetchUrl = new URL(window.location.href.split('?')[0]);
                fetchUrl.search = params.toString();

                const response = await fetch(fetchUrl);
                
                // Catch server errors (like 404 or 500) before trying to parse JSON
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }

                const data = await response.json();

                if (data.success) {
                    if (this.page === 1 && data.html === '') {
                        this.noResults.classList.remove('hidden');
                    } else {
                        this.tbody.insertAdjacentHTML('beforeend', data.html);
                    }
                    
                    this.hasMore = data.has_more;
                    if (this.hasMore) {
                        this.loadMoreBtn.classList.remove('hidden');
                        this.loadMoreBtn.innerHTML = 'Load More Records';
                    }
                }
            } catch (error) {
                console.error("Fetch error:", error);
                // Graceful fallback instead of crashing
                if (this.page === 1) {
                    this.noResults.classList.remove('hidden');
                    this.noResults.querySelector('h3').textContent = 'Error Loading Data';
                    this.noResults.querySelector('p').textContent = 'Please check your connection and try again.';
                }
            } finally {
                this.isLoading = false;
                this.loader.classList.add('hidden');
            }
        }
    }

    document.addEventListener('DOMContentLoaded', () => {
        new HistoryManager();
    });
    </script>
</body>
</html>