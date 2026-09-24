<?php
// bm/loans.php - Branch Manager Loan Management
date_default_timezone_set('Africa/Lagos');
session_start();

require_once '../includes/config.php';

try {
    $pdo = getDbConnection();
} catch (Exception $e) {
    die("Database Connection Failed: " . $e->getMessage());
}

// --- AUTHENTICATION ---
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'bm') {
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Unauthorized']);
        exit();
    }
    header('Location: ../index.php');
    exit();
}

$base_path = '../';
$bm_username = $_SESSION['username'] ?? '';
$user_id = $_SESSION['user_id'] ?? '';

// Fetch the BM's branch_id
$stmt_b = $pdo->prepare("SELECT u.branch_id, b.name as branch_name FROM users u JOIN branches b ON u.branch_id = b.id WHERE u.id = ?");
$stmt_b->execute([$user_id]);
$branch_info = $stmt_b->fetch(PDO::FETCH_ASSOC);
$branch_id = $branch_info['branch_id'] ?? '';
$branch_name = $branch_info['branch_name'] ?? 'My Branch';

// --- AJAX HANDLERS ---

if (isset($_GET['ajax']) && $_GET['ajax'] === '1' && isset($_GET['action'])) {
    header('Content-Type: application/json');

    // 1. Fetch Notifications
    if ($_GET['action'] === 'fetch_notifications') {
        $show_read = isset($_GET['show_read']) && $_GET['show_read'] === 'true';
        $sql = "SELECT id, message, is_read as 'read', created_at as timestamp 
                FROM notifications 
                WHERE user = ?";
                
        if (!$show_read) {
            $sql .= " AND is_read = 0";
        }
        $sql .= " ORDER BY created_at DESC LIMIT 20";
        
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$bm_username]);
            $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'data' => $notifications]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit();
    }

    // 2. Mark Notifications Read
    if ($_GET['action'] === 'mark_notifications_read') {
        try {
            $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user = ? AND is_read = 0");
            $success = $stmt->execute([$bm_username]);
            echo json_encode(['success' => $success]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit();
    }

    // 3. Fetch Loans Data
    if ($_GET['action'] === 'fetch_loans') {
        $co_filter = $_GET['co'] ?? 'all';
        $status_filter = $_GET['status'] ?? 'all';
        
        $where = "WHERE d.branch_id = ?";
        $params = [$branch_id];

        if ($co_filter !== 'all') {
            $where .= " AND d.officer = ?";
            $params[] = $co_filter;
        }
        
        if ($status_filter !== 'all') {
            $where .= " AND d.status = ?";
            $params[] = $status_filter;
            if ($status_filter === 'active') {
                $where .= " AND d.remaining_balance > 0";
            }
        }

        $sql = "
            SELECT d.id, d.client_name, d.officer, d.principal, d.total_payable, 
                   d.remaining_balance, d.num_installments, d.date, d.due_date, 
                   d.status, d.notes, c.union as client_union 
            FROM disbursements d
            LEFT JOIN clients c ON d.client_id = c.id
            $where 
            ORDER BY d.date DESC
        ";

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $loans = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $today = new DateTime();
            $today->setTime(0,0,0);
            
            $formatted_loans =[];
            foreach ($loans as $loan) {
                // Extract Image from Notes
                $image_path = null;
                if (preg_match('/Image:\s*([^\s|]+)/', $loan['notes'] ?? '', $matches)) {
                    $image_path = $matches[1];
                }

                // Determine Overdue Status
                $is_overdue = false;
                $due_date = null;
                if ($loan['status'] !== 'completed' && floatval($loan['remaining_balance']) > 0) {
                    if (!empty($loan['due_date'])) {
                        $due_date = new DateTime($loan['due_date']);
                    } else {
                        $due_date = new DateTime($loan['date']);
                        $due_date->modify('+' . (intval($loan['num_installments']) + 10) . ' days');
                    }
                    if ($today > $due_date) {
                        $is_overdue = true;
                    }
                }

                $formatted_loans[] =[
                    'id' => $loan['id'],
                    'client_name' => $loan['client_name'],
                    'union' => $loan['client_union'] ?? 'Unassigned',
                    'officer' => $loan['officer'],
                    'principal' => floatval($loan['principal']),
                    'total_payable' => floatval($loan['total_payable']),
                    'remaining' => floatval($loan['remaining_balance']),
                    'date' => date('M d, Y', strtotime($loan['date'])),
                    'status' => $loan['status'],
                    'is_overdue' => $is_overdue,
                    'image_path' => $image_path ? $base_path . $image_path : null
                ];
            }
            
            echo json_encode(['success' => true, 'data' => $formatted_loans]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit();
    }
}

// --- FETCH METRICS FOR TOP CARDS ---
$metrics =[
    'active_count' => 0,
    'active_value' => 0,
    'overdue_count' => 0,
    'completed_count' => 0
];

try {
    // Active
    $stmt = $pdo->prepare("SELECT COUNT(*) as cnt, SUM(remaining_balance) as val FROM disbursements WHERE branch_id = ? AND remaining_balance > 0");
    $stmt->execute([$branch_id]);
    $act = $stmt->fetch();
    $metrics['active_count'] = $act['cnt'] ?? 0;
    $metrics['active_value'] = $act['val'] ?? 0;

    // Completed
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM disbursements WHERE branch_id = ? AND status = 'completed'");
    $stmt->execute([$branch_id]);
    $metrics['completed_count'] = $stmt->fetchColumn();

    // Overdue Approximation
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM disbursements WHERE branch_id = ? AND status = 'active' AND remaining_balance > 0 AND due_date IS NOT NULL AND due_date < CURDATE()");
    $stmt->execute([$branch_id]);
    $metrics['overdue_count'] = $stmt->fetchColumn();
} catch (Exception $e) {}

// Fetch CO List
$stmt_cos = $pdo->prepare("SELECT username, full_name FROM users WHERE branch_id = ? AND role = 'co' AND status = 'active' ORDER BY full_name ASC");
$stmt_cos->execute([$branch_id]);
$co_list = $stmt_cos->fetchAll(PDO::FETCH_ASSOC);

// User Profile for Header
$stmt = $pdo->prepare("SELECT profile_pic FROM users WHERE username = ?");
$stmt->execute([$bm_username]);
$u = $stmt->fetch(PDO::FETCH_ASSOC);
$profile_pic = !empty($u['profile_pic']) ? $u['profile_pic'] : 'default_avatar.png';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#3b82f6">
    <title>Loan Management | CUPAD BM</title>
    
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
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        html.dark ::-webkit-scrollbar-thumb { background: #334155; }
        
        .main-header { background: var(--bg-header); backdrop-filter: blur(12px); border-bottom: 1px solid var(--border-color); position: sticky; top: 0; z-index: 50; padding-top: env(safe-area-inset-top); }
        .navbar { display: flex; justify-content: space-between; align-items: center; padding: 0.8rem 1rem; max-width: 1280px; margin: 0 auto; }
        .logo { font-weight: 700; font-size: 1.25rem; color: var(--primary-color); display: flex; align-items: center; gap: 0.5rem; text-decoration: none; }
        .logo img { height: 32px; width: auto; }
        
        .dashboard-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1rem; margin-bottom: 1.5rem; }
        .dashboard-card { border-radius: var(--border-radius-xl); padding: 1.5rem; position: relative; overflow: hidden; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1); color: white; transition: transform 0.2s ease; border: none; }
        .card-gradient-blue { background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); }
        .card-gradient-purple { background: linear-gradient(135deg, #8b5cf6 0%, #6d28d9 100%); }
        .card-gradient-red { background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); }
        .card-bg-icon { position: absolute; right: -10px; bottom: -20px; font-size: 6rem; opacity: 0.1; transform: rotate(-10deg); pointer-events: none; }
        
        .mobile-bottom-nav { display: none; position: fixed; bottom: 0; left: 0; right: 0; background: var(--bg-secondary); border-top: 1px solid var(--border-color); padding: 0.5rem 1rem calc(0.5rem + env(safe-area-inset-bottom)); z-index: 100; justify-content: space-between; box-shadow: 0 -4px 10px rgba(0,0,0,0.05); }
        .nav-item { display: flex; flex-direction: column; align-items: center; justify-content: center; text-decoration: none; color: var(--text-secondary); font-size: 0.7rem; font-weight: 500; gap: 4px; flex: 1; }
        .nav-item i { font-size: 1.4rem; }
        .nav-item.active { color: var(--primary-color); }

        @media (max-width: 768px) { 
            .mobile-bottom-nav { display: flex; } 
            .dashboard-grid { display: flex; overflow-x: auto; scroll-snap-type: x mandatory; gap: 1rem; padding-bottom: 0.5rem; margin-right: -1rem; padding-right: 1.5rem; scrollbar-width: none; } 
            .dashboard-card { min-width: 85vw; scroll-snap-align: center; flex-shrink: 0; } 
            #notification-dropdown { position: fixed; top: 60px; left: 1rem; right: 1rem; width: auto; max-width: none; }
            
            /* Bottom Sheet Modal on Mobile */
            .modal-content {
                margin-top: auto;
                margin-bottom: 0;
                border-radius: 1.5rem 1.5rem 0 0 !important;
                width: 100% !important;
                max-width: 100% !important;
                max-height: 85vh !important;
                transform: translateY(100%) scale(1) !important;
                transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1) !important;
            }
            .modal-overlay.active .modal-content {
                transform: translateY(0) scale(1) !important;
            }
        }

        /* Notification Dropdown Styling */
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
        #notification-list::-webkit-scrollbar { width: 4px; }
        #notification-list::-webkit-scrollbar-track { background: transparent; }
        #notification-list::-webkit-scrollbar-thumb { background-color: var(--text-secondary); border-radius: 20px; opacity: 0.3; }

        .profile-btn { width: 36px; height: 36px; border-radius: 50%; overflow: hidden; border: 2px solid var(--primary-color); background: var(--bg-secondary); cursor: pointer; display: flex; align-items: center; justify-content: center; position: relative; }
        .profile-btn img { width: 100%; height: 100%; object-fit: cover; }
        .profile-fallback-icon { font-size: 1.2rem; color: var(--text-secondary); }
        
        #toast-container { position: fixed; top: 1rem; left: 50%; transform: translateX(-50%); z-index: 999; width: 90%; max-width: 350px; pointer-events: none; }
        .toast { background: var(--bg-secondary); border-left: 4px solid; padding: 1rem; border-radius: 8px; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); margin-bottom: 0.5rem; display: flex; align-items: center; gap: 10px; animation: slideDown 0.3s ease; pointer-events: auto; }
        @keyframes slideDown { from { transform: translateY(-20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
        
        /* Modal styling */
        .modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(15, 23, 42, 0.7); backdrop-filter: blur(4px); z-index: 999; display: none; align-items: center; justify-content: center; opacity: 0; transition: opacity 0.2s; }
        .modal-overlay.active { opacity: 1; display: flex; }
        .modal-content { background: var(--bg-card); width: 90%; max-width: 500px; max-height: 90vh; overflow-y: auto; border-radius: 1.5rem; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25); transform: scale(0.95); transition: transform 0.2s cubic-bezier(0.4, 0, 0.2, 1); }
        .modal-overlay.active .modal-content { transform: scale(1); }
        
        /* Skeleton Animation */
        @keyframes pulse-skeleton {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        .skeleton-box { animation: pulse-skeleton 1.5s ease-in-out infinite; background-color: var(--border-color); border-radius: 0.25rem; }

        /* Custom Table Sticky Header */
        .sticky-th { position: sticky; top: 0; background: var(--bg-primary); z-index: 10; }
        html.dark .sticky-th { background: #111827; } /* gray-900 */
    </style>
</head>
<body class="transition-colors duration-200">

    <div id="toast-container"></div>

    <!-- HEADER -->
    <header class="main-header">
        <nav class="navbar">
            <a href="dashboard.php" class="logo">
                <img src="<?php echo htmlspecialchars($base_path . 'favicon.ico'); ?>" alt="Logo">
                <span>CUPAD BM</span>
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
                                <button id="show-read-btn" onclick="toggleReadNotifications()" class="text-xs text-gray-500 hover:text-gray-700 dark:hover:text-gray-300 font-medium transition">Show read</button>
                                <button onclick="markNotificationsAsRead()" class="text-xs text-blue-600 dark:text-blue-400 font-medium hover:underline">Mark all read</button>
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
                        <img src="<?php echo htmlspecialchars($base_path . $profile_pic ?? ''); ?>" alt="Profile" class="absolute inset-0" onerror="this.style.display='none'"> 
                    <?php endif; ?>
                </div>

                <a href="<?php echo htmlspecialchars($base_path . 'logout.php'); ?>" class="p-2 text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-full transition" title="Logout">
                    <i class="fas fa-sign-out-alt"></i>
                </a>
            </div>
        </nav>
    </header>

    <main class="max-w-7xl mx-auto px-4 py-6">
        
        <div class="flex flex-col lg:flex-row justify-between items-start lg:items-center gap-4 mb-6">
            <div>
                <h1 class="text-2xl font-extrabold text-gray-900 dark:text-white">Loan Management</h1>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1"><?php echo htmlspecialchars($branch_name ?? 'My Branch'); ?> - View & Track Disbursements</p>
            </div>
        </div>

        <!-- Metric Cards -->
        <div class="dashboard-grid">
            <div class="dashboard-card card-gradient-blue"> 
                <i class="fas fa-file-invoice-dollar card-bg-icon"></i> 
                <div class="relative z-10"> 
                    <div class="text-sm font-semibold uppercase opacity-90">Active Loans</div> 
                    <div class="text-3xl font-extrabold mt-1 mb-2 count-up" id="metric_active"><?php echo $metrics['active_count']; ?></div> 
                    <div class="text-xs bg-black/20 rounded-full px-3 py-1 inline-flex items-center"><i class="fas fa-coins mr-1"></i> ₦<?php echo number_format($metrics['active_value']); ?> Outstanding</div> 
                </div> 
            </div>
            
            <div class="dashboard-card card-gradient-purple"> 
                <i class="fas fa-check-circle card-bg-icon"></i> 
                <div class="relative z-10"> 
                    <div class="text-sm font-semibold uppercase opacity-90">Completed Loans</div> 
                    <div class="text-3xl font-extrabold mt-1 mb-2 count-up" id="metric_completed"><?php echo $metrics['completed_count']; ?></div> 
                    <div class="text-xs bg-black/20 rounded-full px-3 py-1 inline-flex items-center"><i class="fas fa-history mr-1"></i> Fully Paid Off</div> 
                </div> 
            </div>
            
            <div class="dashboard-card card-gradient-red"> 
                <i class="fas fa-exclamation-triangle card-bg-icon"></i> 
                <div class="relative z-10"> 
                    <div class="text-sm font-semibold uppercase opacity-90">Overdue Loans</div> 
                    <div class="text-3xl font-extrabold mt-1 mb-2 count-up" id="metric_overdue"><?php echo $metrics['overdue_count']; ?></div> 
                    <div class="text-xs bg-black/20 rounded-full px-3 py-1 inline-flex items-center"><i class="fas fa-clock mr-1"></i> Requires Attention</div> 
                </div> 
            </div>
        </div>

        <!-- Filter & Search Panel -->
        <div class="bg-white dark:bg-gray-800 rounded-t-2xl border-x border-t border-gray-200 dark:border-gray-700 shadow-sm p-4">
            <div class="flex flex-col md:flex-row gap-3">
                <div class="relative flex-1">
                    <i class="fas fa-search absolute left-4 top-1/2 -translate-y-1/2 text-gray-400"></i>
                    <input type="text" id="searchInput" placeholder="Search client name, union or officer..." class="w-full pl-11 pr-4 py-2.5 bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-xl text-sm focus:ring-2 focus:ring-primary focus:bg-white dark:focus:bg-gray-800 text-gray-700 dark:text-gray-300 transition-all">
                </div>
                <div class="flex gap-2 w-full md:w-auto overflow-x-auto pb-1 md:pb-0 scrollbar-hide">
                    <div class="relative min-w-[140px] flex-1 md:flex-none">
                        <select id="coFilter" class="w-full appearance-none py-2.5 pl-4 pr-10 rounded-xl bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-700 focus:ring-2 focus:ring-primary text-gray-700 dark:text-gray-300 cursor-pointer font-medium text-sm transition-all">
                            <option value="all">All Officers</option>
                            <?php foreach($co_list as $co): ?>
                                <option value="<?php echo htmlspecialchars($co['username'] ?? ''); ?>"><?php echo htmlspecialchars($co['full_name'] ?? $co['username'] ?? 'Unknown'); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <i class="fas fa-chevron-down absolute right-4 top-1/2 -translate-y-1/2 text-gray-400 text-xs pointer-events-none"></i>
                    </div>
                    <div class="relative min-w-[130px] flex-1 md:flex-none">
                        <select id="statusFilter" class="w-full appearance-none py-2.5 pl-4 pr-10 rounded-xl bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-700 focus:ring-2 focus:ring-primary text-gray-700 dark:text-gray-300 cursor-pointer font-medium text-sm transition-all">
                            <option value="all">All Status</option>
                            <option value="active" selected>Active</option>
                            <option value="completed">Completed</option>
                        </select>
                        <i class="fas fa-chevron-down absolute right-4 top-1/2 -translate-y-1/2 text-gray-400 text-xs pointer-events-none"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Data Table -->
        <div class="bg-white dark:bg-gray-800 rounded-b-2xl border border-gray-200 dark:border-gray-700 shadow-sm overflow-hidden mb-8">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm whitespace-nowrap">
                    <thead class="sticky-th text-gray-500 dark:text-gray-400 uppercase text-xs font-bold border-b border-gray-200 dark:border-gray-700">
                        <tr>
                            <th class="p-4 hidden md:table-cell">Date</th>
                            <th class="p-4">Client Information</th>
                            <th class="p-4 hidden md:table-cell">Officer</th>
                            <th class="p-4">Repayment Progress</th>
                            <th class="p-4 text-center">Status</th>
                            <th class="p-4 text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody id="loanTableBody" class="divide-y divide-gray-100 dark:divide-gray-700 bg-white dark:bg-gray-800">
                        <!-- Rendered via JS -->
                    </tbody>
                </table>
            </div>
        </div>

    </main>

    <!-- Detail Modal -->
    <div id="loanModal" class="modal-overlay">
        <div class="modal-content border border-gray-200 dark:border-gray-700 p-6 flex flex-col h-full md:h-auto">
            
            <!-- Mobile pull indicator -->
            <div class="w-12 h-1.5 bg-gray-300 dark:bg-gray-600 rounded-full mx-auto mb-4 md:hidden"></div>
            
            <div class="flex justify-between items-center mb-5 pb-4 border-b border-gray-100 dark:border-gray-700">
                <h3 class="text-xl font-bold text-gray-900 dark:text-white flex items-center gap-2">
                    <div class="w-8 h-8 rounded-full bg-blue-100 dark:bg-blue-900/30 text-blue-600 flex items-center justify-center">
                        <i class="fas fa-file-invoice"></i>
                    </div>
                    Loan Details
                </h3>
                <button onclick="closeModal()" class="w-8 h-8 rounded-full bg-gray-100 dark:bg-gray-800 text-gray-500 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 flex items-center justify-center transition"><i class="fas fa-times"></i></button>
            </div>
            
            <div class="space-y-4 text-sm text-left mb-6 flex-1 overflow-y-auto pr-1">
                
                <div class="bg-gray-50 dark:bg-gray-800/50 p-4 rounded-xl border border-gray-100 dark:border-gray-700 space-y-3">
                    <div class="flex justify-between items-center"><span class="text-gray-500 dark:text-gray-400 font-medium">Client:</span><span id="mdl_client" class="font-bold text-gray-900 dark:text-white text-base"></span></div>
                    <div class="flex justify-between items-center"><span class="text-gray-500 dark:text-gray-400 font-medium">Union:</span><span id="mdl_union" class="font-medium text-gray-700 dark:text-gray-300"></span></div>
                    <div class="flex justify-between items-center"><span class="text-gray-500 dark:text-gray-400 font-medium">Officer:</span><span id="mdl_officer" class="font-medium text-blue-600 dark:text-blue-400 bg-blue-50 dark:bg-blue-900/20 px-2 py-0.5 rounded-md"></span></div>
                    <div class="flex justify-between items-center"><span class="text-gray-500 dark:text-gray-400 font-medium">Disbursed:</span><span id="mdl_date" class="font-medium text-gray-700 dark:text-gray-300"></span></div>
                </div>
                
                <div class="bg-gray-50 dark:bg-gray-800/50 p-4 rounded-xl border border-gray-100 dark:border-gray-700 space-y-3">
                    <div class="flex justify-between items-center"><span class="text-gray-500 dark:text-gray-400 font-medium">Principal:</span><span id="mdl_principal" class="font-bold text-gray-700 dark:text-gray-200"></span></div>
                    <div class="flex justify-between items-center"><span class="text-gray-500 dark:text-gray-400 font-medium">Total Payable:</span><span id="mdl_payable" class="font-bold text-gray-900 dark:text-white"></span></div>
                    
                    <div class="pt-3 border-t border-dashed border-gray-200 dark:border-gray-700">
                        <div class="flex justify-between items-end mb-2">
                            <span class="text-gray-500 dark:text-gray-400 font-bold uppercase text-xs tracking-wider">Remaining Balance:</span>
                            <span id="mdl_remaining" class="font-extrabold text-red-500 text-lg"></span>
                        </div>
                        
                        <!-- Progress Bar in Modal -->
                        <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-2 mt-1">
                            <div id="mdl_progress" class="bg-primary h-2 rounded-full transition-all duration-500" style="width: 0%"></div>
                        </div>
                        <div class="flex justify-between text-[10px] text-gray-500 font-medium mt-1 uppercase">
                            <span id="mdl_paid_pct">0% Paid</span>
                            <span>100%</span>
                        </div>
                    </div>
                </div>

                <div class="text-left mt-2">
                    <h4 class="text-sm font-bold text-gray-800 dark:text-white mb-3 flex items-center gap-2"><i class="fas fa-camera text-gray-400"></i> Proof Document</h4>
                    
                    <div id="mdl_image_container" class="mb-2 hidden text-center relative group">
                        <a id="mdl_image_link" href="#" target="_blank" class="block rounded-xl overflow-hidden border border-gray-200 dark:border-gray-700 shadow-sm h-48 bg-gray-100 dark:bg-gray-800 relative">
                            <img id="mdl_image" src="" alt="Proof" class="w-full h-full object-cover">
                            <div class="absolute inset-0 bg-black/50 opacity-0 group-hover:opacity-100 flex flex-col items-center justify-center transition backdrop-blur-sm">
                                <i class="fas fa-external-link-alt text-white text-3xl mb-2"></i>
                                <span class="text-white text-xs font-bold uppercase tracking-wider">View Document</span>
                            </div>
                        </a>
                    </div>
                    
                    <div id="mdl_no_image" class="text-center p-6 bg-gray-50 dark:bg-gray-800 rounded-xl border border-dashed border-gray-300 dark:border-gray-600 hidden">
                        <div class="w-10 h-10 rounded-full bg-gray-200 dark:bg-gray-700 flex items-center justify-center mx-auto mb-2">
                            <i class="fas fa-image text-gray-400 text-lg"></i>
                        </div>
                        <p class="text-sm font-medium text-gray-500 dark:text-gray-400">No document attached.</p>
                    </div>
                </div>
            </div>
            
            <div class="mt-4 pt-4 border-t border-gray-100 dark:border-gray-700 md:hidden">
                <button onclick="closeModal()" class="w-full bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 text-gray-800 dark:text-white font-bold py-3 rounded-xl transition">Close</button>
            </div>
        </div>
    </div>

    <!-- BM Mobile Navigation -->
    <nav class="mobile-bottom-nav">
        <a href="dashboard.php" class="nav-item">
            <i class="fas fa-home"></i>
            <span>Home</span>
        </a>
        <a href="combined_collection.php" class="nav-item">
            <i class="fas fa-coins"></i>
            <span>Collect</span>
        </a>
        <a href="disbursement.php" class="nav-item">
             <i class="fas fa-file-invoice-dollar"></i>
             <span>Loans Form</span>
        </a>
        <a href="loans.php" class="nav-item active">
             <div style="background: var(--primary-color); width: 45px; height: 45px; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; margin-top: -25px; box-shadow: 0 4px 10px rgba(59, 130, 246, 0.4);">
                <i class="fas fa-list-alt" style="font-size: 1.2rem; margin:0;"></i>
             </div>
             <span style="font-size:0.65rem; font-weight:600; margin-top:2px;">All Loans</span>
        </a>
        <a href="analytics.php" class="nav-item">
            <i class="fas fa-chart-bar"></i>
            <span>Reports</span>
        </a>
    </nav>

    <script>
        let allLoans =[];
        let showReadNotifications = false;

        function showToast(msg, type) {
            const el = document.createElement('div');
            el.className = 'toast';
            el.style.borderLeftColor = type === 'success' ? '#22c55e' : '#ef4444';
            el.innerHTML = `<i class="fas ${type==='success'?'fa-check-circle text-green-500':'fa-times-circle text-red-500'}"></i> <span class="text-sm font-medium text-gray-800 dark:text-gray-200">${msg}</span>`;
            document.getElementById('toast-container').appendChild(el);
            setTimeout(() => { el.style.opacity = '0'; setTimeout(() => el.remove(), 300); }, 3000);
        }

        // --- Notifications Engine ---
        async function fetchNotifications() {
            try {
                const res = await fetch(`?ajax=1&action=fetch_notifications&show_read=${showReadNotifications}`);
                const json = await res.json();
                if(!json.success) return;
                const notifications = json.data;
                
                const listContainer = document.getElementById('notification-list');
                const badge = document.getElementById('notification-badge');
                
                const unreadCount = notifications.filter(n => !n.read).length;
                if (unreadCount > 0) {
                    badge.textContent = unreadCount > 9 ? '9+' : unreadCount;
                    badge.classList.remove('hidden');
                } else {
                    badge.classList.add('hidden');
                }

                if (notifications.length > 0) {
                    let html = '';
                    notifications.forEach(n => {
                        const date = new Date(n.timestamp || n.date).toLocaleString([], { month: 'short', day: 'numeric', hour: '2-digit', minute:'2-digit' });
                        const readClass = n.read ? 'read' : 'unread';
                        
                        let icon = 'fa-bell';
                        const msg = n.message.toLowerCase();
                        if(msg.includes('saving') || msg.includes('deposit')) icon = 'fa-piggy-bank';
                        else if(msg.includes('withdrawal')) icon = 'fa-wallet';
                        else if(msg.includes('loan') || msg.includes('disburse')) icon = 'fa-hand-holding-usd';
                        else if(msg.includes('repay') || msg.includes('payment')) icon = 'fa-money-bill-wave';
                        else if(msg.includes('client') || msg.includes('register')) icon = 'fa-user-plus';

                        html += `
                            <div class="notification-item ${readClass}">
                                <div class="notif-icon">
                                    <i class="fas ${icon}"></i>
                                </div>
                                <div class="notif-content">
                                    <p class="notif-message">${n.message}</p>
                                    <span class="notif-time">${date}</span>
                                </div>
                                ${!n.read ? '<div class="notif-dot"></div>' : ''}
                            </div>
                        `;
                    });
                    listContainer.innerHTML = html;
                } else {
                    const message = showReadNotifications ? 'No notifications' : 'No new notifications';
                    listContainer.innerHTML = `
                        <div class="flex flex-col items-center justify-center py-8 text-gray-400">
                            <i class="far fa-bell-slash text-2xl mb-2 opacity-50"></i>
                            <span class="text-sm">${message}</span>
                        </div>`;
                }
            } catch (error) {
                console.error('Error fetching notifications:', error);
            }
        }

        function toggleReadNotifications() {
            showReadNotifications = !showReadNotifications;
            const btn = document.getElementById('show-read-btn');
            btn.textContent = showReadNotifications ? 'Show unread' : 'Show read';
            fetchNotifications();
        }

        async function markNotificationsAsRead() {
            try {
                const res = await fetch(`?ajax=1&action=mark_notifications_read`, { method: 'POST' });
                const result = await res.json();
                if (result.success) {
                    document.getElementById('notification-badge').classList.add('hidden');
                    document.getElementById('notification-list').innerHTML = `
                        <div class="flex flex-col items-center justify-center py-8 text-gray-400">
                            <i class="far fa-bell-slash text-2xl mb-2 opacity-50"></i>
                            <span class="text-sm">No new notifications</span>
                        </div>`;
                }
            } catch (error) {
                console.error('Error marking notifications:', error);
            }
        }

        const notifBtn = document.getElementById('notification-btn');
        const notifDropdown = document.getElementById('notification-dropdown');
        notifBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            notifDropdown.classList.toggle('hidden');
        });

        document.addEventListener('click', (e) => {
            if (!notifBtn.contains(e.target) && !notifDropdown.contains(e.target)) {
                if (!notifDropdown.classList.contains('hidden')) {
                    markNotificationsAsRead();
                }
                notifDropdown.classList.add('hidden');
            }
        });

        // --- Loans Engine ---
        function renderSkeletons() {
            const tbody = document.getElementById('loanTableBody');
            let skeletons = '';
            for(let i=0; i<5; i++) {
                skeletons += `
                <tr class="animate-pulse">
                    <td class="p-4 hidden md:table-cell"><div class="h-4 w-20 skeleton-box"></div></td>
                    <td class="p-4">
                        <div class="h-4 w-32 skeleton-box mb-2"></div>
                        <div class="h-3 w-24 skeleton-box"></div>
                    </td>
                    <td class="p-4 hidden md:table-cell"><div class="h-4 w-24 skeleton-box"></div></td>
                    <td class="p-4"><div class="h-4 w-28 skeleton-box mb-2"></div><div class="h-2 w-full skeleton-box"></div></td>
                    <td class="p-4 text-center"><div class="h-6 w-16 skeleton-box mx-auto rounded-full"></div></td>
                    <td class="p-4 text-center"><div class="h-8 w-8 skeleton-box mx-auto rounded-lg"></div></td>
                </tr>`;
            }
            tbody.innerHTML = skeletons;
        }

        async function fetchLoans() {
            renderSkeletons();
            
            const co = document.getElementById('coFilter').value;
            const status = document.getElementById('statusFilter').value;
            
            try {
                const res = await fetch(`?ajax=1&action=fetch_loans&co=${encodeURIComponent(co)}&status=${encodeURIComponent(status)}`);
                const json = await res.json();
                
                if (json.success) {
                    allLoans = json.data;
                    renderTable();
                } else {
                    document.getElementById('loanTableBody').innerHTML = `<tr><td colspan="6" class="p-8 text-center text-red-500 font-medium"><i class="fas fa-exclamation-circle mr-2"></i>${json.error}</td></tr>`;
                }
            } catch (err) {
                document.getElementById('loanTableBody').innerHTML = '<tr><td colspan="6" class="p-8 text-center text-red-500 font-medium"><i class="fas fa-wifi mr-2"></i>Network Error</td></tr>';
            }
        }

        function renderTable() {
            const tbody = document.getElementById('loanTableBody');
            const search = document.getElementById('searchInput').value.toLowerCase();
            
            const filtered = allLoans.filter(l => l.client_name.toLowerCase().includes(search) || l.union.toLowerCase().includes(search) || l.officer.toLowerCase().includes(search));
            
            if (filtered.length === 0) {
                tbody.innerHTML = `<tr><td colspan="6" class="p-16 text-center text-gray-500">
                    <div class="w-16 h-16 bg-gray-50 dark:bg-gray-800/50 rounded-full flex items-center justify-center mx-auto mb-4 border border-dashed border-gray-200 dark:border-gray-700">
                        <i class="fas fa-search text-gray-400 text-xl"></i>
                    </div>
                    <p class="font-medium">No matching loans found.</p>
                    <p class="text-xs text-gray-400 mt-1">Try adjusting your filters or search term.</p>
                </td></tr>`;
                return;
            }
            
            tbody.innerHTML = filtered.map(l => {
                let statusBadge = '';
                if (l.status === 'completed') {
                    statusBadge = '<span class="bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-400 px-2.5 py-1 rounded-md text-[10px] font-bold border border-green-200 dark:border-green-800/50 uppercase tracking-wide">COMPLETED</span>';
                } else if (l.is_overdue) {
                    statusBadge = '<span class="bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-400 px-2.5 py-1 rounded-md text-[10px] font-bold border border-red-200 dark:border-red-800/50 uppercase tracking-wide">OVERDUE</span>';
                } else {
                    statusBadge = '<span class="bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-400 px-2.5 py-1 rounded-md text-[10px] font-bold border border-blue-200 dark:border-blue-800/50 uppercase tracking-wide">ACTIVE</span>';
                }

                const paidAmount = l.total_payable - l.remaining;
                const progressPct = l.total_payable > 0 ? (paidAmount / l.total_payable) * 100 : 0;
                let progressColor = progressPct >= 100 ? 'bg-green-500' : 'bg-primary';
                if(l.is_overdue && progressPct < 100) progressColor = 'bg-red-500';

                const imgIndicator = l.image_path ? '<i class="fas fa-paperclip text-blue-400 ml-2" title="Has Document"></i>' : '';

                return `
                <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/80 transition-colors group border-b border-gray-50 dark:border-gray-800 last:border-0">
                    <td class="p-4 text-gray-500 dark:text-gray-400 text-xs font-medium hidden md:table-cell">${l.date}</td>
                    
                    <td class="p-4">
                        <div class="font-bold text-gray-900 dark:text-white flex items-center">
                            ${l.client_name} ${imgIndicator}
                        </div>
                        <div class="text-[10px] text-gray-500 dark:text-gray-400 font-medium uppercase tracking-wide mt-1 flex flex-wrap gap-1 items-center">
                            <span class="bg-gray-100 dark:bg-gray-700 px-1.5 py-0.5 rounded">${l.union}</span>
                            <span class="md:hidden border-l border-gray-300 dark:border-gray-600 pl-1 ml-0.5"><i class="fas fa-user-tie"></i> ${l.officer}</span>
                            <span class="md:hidden border-l border-gray-300 dark:border-gray-600 pl-1 ml-0.5"><i class="far fa-calendar-alt"></i> ${l.date}</span>
                        </div>
                    </td>
                    
                    <td class="p-4 hidden md:table-cell">
                        <span class="inline-flex items-center gap-1.5 px-2 py-1 rounded-md bg-gray-50 dark:bg-gray-800 text-gray-600 dark:text-gray-300 text-[11px] font-medium border border-gray-200 dark:border-gray-700">
                            <i class="fas fa-user-tie text-[10px] text-gray-400"></i> ${l.officer}
                        </span>
                    </td>
                    
                    <td class="p-4">
                        <div class="flex justify-between items-end mb-1">
                            <span class="font-mono font-bold text-sm ${l.remaining > 0 ? 'text-gray-700 dark:text-gray-300' : 'text-green-600 dark:text-green-400'}">₦${l.remaining.toLocaleString()}</span>
                            <span class="text-[10px] text-gray-400 font-medium uppercase tracking-wider">Remaining</span>
                        </div>
                        <div class="w-full bg-gray-100 dark:bg-gray-700 rounded-full h-1.5">
                            <div class="${progressColor} h-1.5 rounded-full" style="width: ${progressPct}%"></div>
                        </div>
                    </td>
                    
                    <td class="p-4 text-center">${statusBadge}</td>
                    
                    <td class="p-4 text-center">
                        <!-- Desktop Button -->
                        <button onclick="openModal('${l.id}')" class="hidden md:flex bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 px-3 py-1.5 rounded-lg shadow-sm hover:bg-blue-50 dark:hover:bg-gray-600 hover:border-blue-200 dark:hover:border-gray-500 hover:text-blue-600 dark:hover:text-white transition-all items-center justify-center gap-2 mx-auto text-xs font-bold text-gray-600 dark:text-gray-300 group-hover:shadow">
                            <i class="far fa-eye"></i> <span>View</span>
                        </button>
                        <!-- Mobile Button -->
                        <button onclick="openModal('${l.id}')" class="md:hidden w-8 h-8 rounded-full bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 flex items-center justify-center text-gray-500 hover:text-blue-500 hover:border-blue-300 transition-colors mx-auto">
                            <i class="fas fa-chevron-right text-xs"></i>
                        </button>
                    </td>
                </tr>`;
            }).join('');
        }

        // Modal Logic
        function openModal(id) {
            const loan = allLoans.find(l => l.id === id);
            if (!loan) return;

            document.getElementById('mdl_client').textContent = loan.client_name;
            document.getElementById('mdl_union').textContent = loan.union;
            document.getElementById('mdl_officer').textContent = loan.officer;
            document.getElementById('mdl_date').textContent = loan.date;
            document.getElementById('mdl_principal').textContent = '₦' + loan.principal.toLocaleString();
            document.getElementById('mdl_payable').textContent = '₦' + loan.total_payable.toLocaleString();
            document.getElementById('mdl_remaining').textContent = '₦' + loan.remaining.toLocaleString();

            const paidAmount = loan.total_payable - loan.remaining;
            const progressPct = loan.total_payable > 0 ? (paidAmount / loan.total_payable) * 100 : 0;
            
            document.getElementById('mdl_progress').style.width = `${progressPct}%`;
            document.getElementById('mdl_progress').className = `h-2 rounded-full transition-all duration-500 ${progressPct >= 100 ? 'bg-green-500' : (loan.is_overdue ? 'bg-red-500' : 'bg-primary')}`;
            document.getElementById('mdl_paid_pct').textContent = `${progressPct.toFixed(0)}% Paid`;

            const imgCont = document.getElementById('mdl_image_container');
            const noImg = document.getElementById('mdl_no_image');
            const imgTag = document.getElementById('mdl_image');
            const imgLink = document.getElementById('mdl_image_link');

            if (loan.image_path) {
                imgTag.src = loan.image_path;
                imgLink.href = loan.image_path;
                imgCont.classList.remove('hidden');
                noImg.classList.add('hidden');
            } else {
                imgCont.classList.add('hidden');
                noImg.classList.remove('hidden');
            }

            document.getElementById('loanModal').classList.add('active');
        }

        function closeModal() {
            document.getElementById('loanModal').classList.remove('active');
        }
        
        // Close modal on outside click
        document.getElementById('loanModal').addEventListener('click', function(e) {
            if(e.target === this) closeModal();
        });

        // Events
        document.getElementById('coFilter').addEventListener('change', fetchLoans);
        document.getElementById('statusFilter').addEventListener('change', fetchLoans);
        document.getElementById('searchInput').addEventListener('input', () => {
            clearTimeout(window.searchTimeout);
            window.searchTimeout = setTimeout(renderTable, 300);
        });

        document.getElementById('theme-toggle').addEventListener('click', () => {
            document.documentElement.classList.toggle('dark');
            localStorage.setItem('theme', document.documentElement.classList.contains('dark') ? 'dark' : 'light');
        });

        // Init
        const savedTheme = localStorage.getItem('theme');
        if (savedTheme === 'dark') document.documentElement.classList.add('dark');
        
        fetchLoans();
        fetchNotifications();
        setInterval(fetchNotifications, 10000);
        
        // Setup count up animations
        function animateNumbers() {
            document.querySelectorAll('.count-up').forEach(el => {
                const target = parseFloat(el.textContent.replace(/,/g, '')) || 0;
                let start = 0;
                let startTime = null;

                const animation = (currentTime) => {
                    if (startTime === null) startTime = currentTime;
                    const progress = Math.min((currentTime - startTime) / 800, 1); 
                    const currentVal = Math.floor(progress * (target - start) + start);
                    
                    el.innerText = currentVal.toLocaleString('en-US');

                    if (progress < 1) {
                        requestAnimationFrame(animation);
                    } else {
                        el.innerText = target.toLocaleString('en-US');
                    }
                };
                requestAnimationFrame(animation);
            });
        }
        setTimeout(animateNumbers, 200);

    </script>
</body>
</html>