<?php
session_start();
date_default_timezone_set('Africa/Lagos');

// --- 1. SECURITY CHECK ---
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: ../index.php');
    exit();
}

// --- 2. DATABASE CONFIGURATION ---
require_once '../includes/config.php';
$pdo = getDbConnection();
$base_path = '../'; 

// --- 3. FETCH DROPDOWN DATA (HIERARCHY & USERS) ---
$zones = $pdo->query("SELECT id, name FROM zones ORDER BY name")->fetchAll();
$areas = $pdo->query("SELECT id, name, zone_id FROM areas ORDER BY name")->fetchAll();
$branches = $pdo->query("SELECT id, name, area_id FROM branches ORDER BY name")->fetchAll();

$stmtCo = $pdo->prepare("SELECT username, full_name FROM users WHERE role = 'co' ORDER BY full_name ASC");
$stmtCo->execute();
$coUsersList = $stmtCo->fetchAll();

// --- 4. CORE FILTERING LOGIC (SQL BUILDER) ---
function getFilteredData($pdo, $branchNameForCoBreakdown = null, $coUsernameForBreakdown = null) {
    $selectedZone = $_GET['zone'] ?? '';
    $selectedArea = $_GET['area'] ?? '';
    $selectedBranch = $_GET['branch'] ?? '';
    $selectedCoUsername = $_GET['co_username'] ?? '';
    $startDate = $_GET['start_date'] ?? '';
    $endDate = $_GET['end_date'] ?? '';
    $searchTags = $_GET['search_tags'] ?? '';

    $whereClauses = ["1=1"];
    $params = [];

    if (!empty($startDate) && !empty($endDate)) {
        $whereClauses[] = "d.date BETWEEN ? AND ?";
        $params[] = $startDate;
        $params[] = $endDate;
    }

    if (!empty($selectedCoUsername)) {
        $whereClauses[] = "d.officer = ?";
        $params[] = $selectedCoUsername;
    }

    if (!empty($selectedBranch)) {
        $whereClauses[] = "d.branch_id = ?";
        $params[] = $selectedBranch;
    } elseif (!empty($selectedArea)) {
        $whereClauses[] = "b.area_id = ?";
        $params[] = $selectedArea;
    } elseif (!empty($selectedZone)) {
        $whereClauses[] = "a.zone_id = ?";
        $params[] = $selectedZone;
    }

    if (!empty($searchTags)) {
        $tags = array_map('trim', explode(',', $searchTags));
        $tagClauses = [];
        foreach ($tags as $tag) {
            if(!empty($tag)) {
                $tagClauses[] = "b.name LIKE ?";
                $params[] = "%$tag%";
            }
        }
        if (!empty($tagClauses)) {
            $whereClauses[] = "(" . implode(' OR ', $tagClauses) . ")";
        }
    }

    // --- LEVEL 3: Individual CO Details ---
    if ($coUsernameForBreakdown) {
        $whereClauses[] = "d.officer = ?";
        $params[] = $coUsernameForBreakdown;

        $sql = "SELECT d.id as disbursement_id, d.date, d.client_name, d.principal as principal_amount, d.officer 
                FROM disbursements d
                INNER JOIN branches b ON d.branch_id = b.id
                LEFT JOIN areas a ON b.area_id = a.id
                WHERE " . implode(" AND ", $whereClauses) . "
                ORDER BY d.date DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $coDetails = $stmt->fetchAll();

        $stmtName = $pdo->prepare("SELECT full_name FROM users WHERE username = ?");
        $stmtName->execute([$coUsernameForBreakdown]);
        $coNameRow = $stmtName->fetch();

        return [
            'type' => 'co_details',
            'disbursements' => $coDetails,
            'coName' => htmlspecialchars($coNameRow['full_name'] ?? $coUsernameForBreakdown)
        ];
    }

    // --- LEVEL 2: Branch Breakdown ---
    if ($branchNameForCoBreakdown) {
        $whereClauses[] = "b.name = ?";
        $params[] = $branchNameForCoBreakdown;

        $sql = "SELECT d.officer as username, 
                       COALESCE(u.full_name, d.officer) as co_name, 
                       SUM(d.principal) as totalAmount, 
                       COUNT(d.id) as numDisbursements
                FROM disbursements d
                INNER JOIN branches b ON d.branch_id = b.id
                LEFT JOIN areas a ON b.area_id = a.id
                LEFT JOIN users u ON d.officer = u.username
                WHERE " . implode(" AND ", $whereClauses) . "
                GROUP BY d.officer
                ORDER BY co_name ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        return [
            'type' => 'co_breakdown',
            'coPerformance' => $stmt->fetchAll(),
            'branchName' => htmlspecialchars($branchNameForCoBreakdown ?? '')
        ];
    }

    // --- LEVEL 1: Main Summary ---
    $countSql = "SELECT COUNT(d.id) as total_count, SUM(d.principal) as total_amount, COUNT(DISTINCT b.id) as num_branches
                 FROM disbursements d
                 INNER JOIN branches b ON d.branch_id = b.id
                 LEFT JOIN areas a ON b.area_id = a.id
                 WHERE " . implode(" AND ", $whereClauses);
    $stmtCount = $pdo->prepare($countSql);
    $stmtCount->execute($params);
    $totals = $stmtCount->fetch();

    $groupSql = "SELECT d.date, 
                        b.name as branch, 
                        SUM(d.principal) as totalAmount, 
                        COUNT(d.id) as numDisbursements
                 FROM disbursements d
                 INNER JOIN branches b ON d.branch_id = b.id
                 LEFT JOIN areas a ON b.area_id = a.id
                 WHERE " . implode(" AND ", $whereClauses) . "
                 GROUP BY d.date, b.name
                 ORDER BY d.date ASC, b.name ASC";
    
    $stmtGroup = $pdo->prepare($groupSql);
    $stmtGroup->execute($params);

    return [
        'type' => 'branch_summary',
        'totalDisbursementsCount' => (int)($totals['total_count'] ?? 0),
        'totalDisbursedAmount' => (float)($totals['total_amount'] ?? 0.00),
        'numBranches' => (int)($totals['num_branches'] ?? 0),
        'branchDisbursements' => $stmtGroup->fetchAll(),
        'filters' => [
            'zone' => $selectedZone, 'area' => $selectedArea, 'branch' => $selectedBranch,
            'co_username' => $selectedCoUsername, 'start_date' => $startDate, 'end_date' => $endDate, 'search_tags' => $searchTags,
        ]
    ];
}

// --- 5. AJAX HANDLER ---
if (isset($_GET['ajax']) && $_GET['ajax'] === 'true') {
    header('Content-Type: application/json');
    try {
        $data = getFilteredData($pdo, $_GET['breakdown_branch'] ?? null, $_GET['breakdown_co'] ?? null);
        echo json_encode(['success' => true, 'data' => $data]);
    } catch (Exception $e) {
        error_log($e->getMessage());
        echo json_encode(['success' => false, 'message' => 'An error occurred while fetching data.']);
    }
    exit;
}

// --- 6. INITIAL PAGE LOAD DATA ---
$initialData = getFilteredData($pdo, null, null);

$selectedZone = $initialData['filters']['zone'];
$selectedArea = $initialData['filters']['area'];
$selectedBranch = $initialData['filters']['branch'];
$selectedCoUsername = $initialData['filters']['co_username'];
$startDate = $initialData['filters']['start_date'];
$endDate = $initialData['filters']['end_date'];
$searchTags = $initialData['filters']['search_tags'];

$totalDisbursementsCount = $initialData['totalDisbursementsCount'];
$totalDisbursedAmount = $initialData['totalDisbursedAmount'];
$branchDisbursements = $initialData['branchDisbursements'];

$full_name = $_SESSION['full_name'] ?? 'Admin';
$user_role = $_SESSION['user_role'] ?? 'Admin'; 
$my_profile_pic = '';

$stmtUser = $pdo->prepare("SELECT profile_pic FROM users WHERE username = ?");
$stmtUser->execute([$_SESSION['username'] ?? '']);
if ($currentUser = $stmtUser->fetch()) $my_profile_pic = $currentUser['profile_pic'];
?>

<!DOCTYPE html>
<html lang="en" class="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Branch Disbursement Report</title>
    
    <!-- Fonts & Icons -->
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />
    
    <!-- Libraries -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <style>
        /* --- CSS VARIABLES & CUSTOM SCROLLBARS --- */
        :root {
            --primary: #2563eb; --primary-dark: #1d4ed8; --primary-light: #eff6ff;
            --bg-body: #f8fafc; --bg-surface: #ffffff;
            --text-main: #0f172a; --text-muted: #64748b;
            --border: #e2e8f0; --border-strong: #cbd5e1;
            --success: #10b981; --danger: #ef4444; 
            --radius-lg: 16px; --radius-md: 10px; --radius-sm: 6px;
            --nav-height: 70px;
            --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
            --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.05), 0 2px 4px -2px rgb(0 0 0 / 0.05);
            --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.05), 0 4px 6px -4px rgb(0 0 0 / 0.025);
            --transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            --skel-bg: #e2e8f0; --skel-highlight: #f1f5f9;
        }
        html.dark {
            --bg-body: #0f172a; --bg-surface: #1e293b;
            --text-main: #f8fafc; --text-muted: #94a3b8;
            --border: #334155; --border-strong: #475569;
            --primary: #3b82f6; --primary-light: rgba(59, 130, 246, 0.15);
            --skel-bg: #334155; --skel-highlight: #475569;
        }
        
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: var(--border-strong); border-radius: 10px; }
        ::-webkit-scrollbar-thumb:hover { background: var(--text-muted); }

        * { box-sizing: border-box; outline: none; }
        body { font-family: 'Plus Jakarta Sans', sans-serif; background: var(--bg-body); color: var(--text-main); margin: 0; padding-top: var(--nav-height); transition: var(--transition); font-size: 0.95rem; }
        a { text-decoration: none; color: inherit; }
        
        /* Tabular nums for perfect vertical alignment of numbers */
        .tabular-nums { font-variant-numeric: tabular-nums; }

        @keyframes fadeInUp { from { opacity: 0; transform: translateY(15px); } to { opacity: 1; transform: translateY(0); } }
        .animate-up { animation: fadeInUp 0.4s ease forwards; }

        /* --- SKELETON ANIMATIONS (MODERN) --- */
        @keyframes skeletonPulse {
            0% { background-color: var(--skel-bg); }
            50% { background-color: var(--skel-highlight); }
            100% { background-color: var(--skel-bg); }
        }
        .skel { animation: skeletonPulse 1.5s ease-in-out infinite; border-radius: var(--radius-sm); }
        .skel-circle { border-radius: 50%; }
        .skel-text { height: 14px; }
        .skel-title { height: 24px; margin-bottom: 8px; width: 60%; }

        /* --- HEADER --- */
        .main-header { position: fixed; top: 0; left: 0; right: 0; height: var(--nav-height); background: rgba(255,255,255,0.85); backdrop-filter: blur(16px); border-bottom: 1px solid var(--border); z-index: 50; transition: background 0.3s; }
        html.dark .main-header { background: rgba(30, 41, 59, 0.85); }
        .navbar { max-width: 1400px; margin: 0 auto; padding: 0 1.5rem; display: flex; align-items: center; justify-content: space-between; height: 100%; }
        .logo { display: flex; align-items: center; gap: 0.75rem; font-weight: 800; font-size: 1.35rem; color: var(--primary); letter-spacing: -0.5px;}
        .nav-right { display: flex; align-items: center; gap: 1.25rem; }

        #themeToggle { width: 40px; height: 40px; border-radius: 50%; border: 1px solid var(--border); background: var(--bg-surface); color: var(--text-muted); cursor: pointer; display: flex; align-items: center; justify-content: center; transition: var(--transition); box-shadow: var(--shadow-sm); }
        #themeToggle:hover { background: var(--primary-light); border-color: var(--primary); color: var(--primary); transform: translateY(-2px); }
        
        .user-pill { display: flex; align-items: center; gap: 0.75rem; padding: 4px 16px 4px 4px; border: 1px solid var(--border); border-radius: 99px; background: var(--bg-surface); box-shadow: var(--shadow-sm); }
        .user-avatar-img, .user-avatar-fallback { width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700; background: var(--primary-light); color: var(--primary); object-fit: cover;}
        .user-info { display: flex; flex-direction: column; line-height: 1.2; }
        .user-name { font-weight: 700; font-size: 0.85rem; }
        .user-role { font-size: 0.7rem; color: var(--text-muted); text-transform: uppercase; font-weight: 700;}

        /* --- LAYOUT & CARDS --- */
        .container { max-width: 1400px; margin: 0 auto; padding: 2rem 1.5rem; }
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; flex-wrap: wrap; gap: 1rem;}
        .page-title { font-size: 1.75rem; font-weight: 800; display: flex; align-items: center; gap: 0.75rem; letter-spacing: -0.5px;}
        .page-title i { color: var(--primary); background: var(--primary-light); padding: 12px; border-radius: var(--radius-md); font-size: 1.25rem;}
        
        .card { background: var(--bg-surface); border: 1px solid var(--border); border-radius: var(--radius-lg); box-shadow: var(--shadow-sm); transition: var(--transition); }

        /* --- STATS GRID --- */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1.5rem; margin-bottom: 2rem; }
        .stat-card { padding: 1.5rem; display: flex; align-items: center; gap: 1.25rem; position: relative; overflow: hidden; }
        .stat-card:hover { transform: translateY(-4px); box-shadow: var(--shadow-md); border-color: var(--border-strong); }
        .stat-icon { width: 60px; height: 60px; border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; flex-shrink: 0;}
        .stat-info h3 { font-size: 1.85rem; font-weight: 800; margin: 0; line-height: 1.2; letter-spacing: -0.5px;}
        .stat-info p { margin: 0.25rem 0 0 0; color: var(--text-muted); font-size: 0.95rem; font-weight: 600; }
        .stat-blue { background: rgba(37,99,235,0.1); color: var(--primary); border: 1px solid rgba(37,99,235,0.2);}
        .stat-green { background: rgba(16,185,129,0.1); color: var(--success); border: 1px solid rgba(16,185,129,0.2);}
        .stat-purple { background: rgba(147,51,234,0.1); color: #9333ea; border: 1px solid rgba(147,51,234,0.2);}

        /* --- CHART & FILTER --- */
        .chart-card { padding: 1.5rem; margin-bottom: 2rem; height: 350px; position: relative;}
        .chart-skeleton-overlay { position: absolute; inset: 0; background: var(--bg-surface); display: flex; align-items: flex-end; justify-content: space-between; padding: 2rem 3rem 3rem 3rem; border-radius: var(--radius-lg); z-index: 10; opacity: 0; visibility: hidden; transition: 0.3s; gap: 2%;}
        .chart-skeleton-overlay.active { opacity: 1; visibility: visible; }
        .chart-skeleton-overlay .skel-bar { width: 100%; border-radius: 4px 4px 0 0; }

        .search-card { padding: 1.75rem; margin-bottom: 2rem; }
        .search-form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1.25rem; align-items: end; }
        .form-group label { display: block; font-size: 0.85rem; font-weight: 700; color: var(--text-muted); margin-bottom: 0.6rem; letter-spacing: 0.3px;}
        .form-control { width: 100%; padding: 0.75rem 1rem; border-radius: var(--radius-md); border: 1px solid var(--border); background: var(--bg-body); color: var(--text-main); font-family: inherit; font-size: 0.95rem; font-weight: 500; transition: var(--transition);}
        .form-control:focus { border-color: var(--primary); box-shadow: 0 0 0 4px var(--primary-light); background: var(--bg-surface);}
        
        .btn-group { display: flex; gap: 1rem; margin-top: 1.75rem; flex-wrap: wrap; border-top: 1px solid var(--border); padding-top: 1.5rem; justify-content: space-between; align-items: center; }
        .btn-sub-group { display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: center; }
        .btn { padding: 0.75rem 1.25rem; border-radius: var(--radius-md); border: none; font-weight: 600; font-size: 0.95rem; cursor: pointer; display: inline-flex; align-items: center; gap: 0.5rem; transition: var(--transition); text-decoration: none;}
        .btn:active { transform: scale(0.96); }
        .btn-outline { background: var(--bg-surface); border: 1px solid var(--border); color: var(--text-main); box-shadow: var(--shadow-sm);} 
        .btn-outline:hover { border-color: var(--text-muted); background: var(--bg-body); }
        .btn-csv { border-color: #10b981; color: #10b981; background: transparent; } .btn-csv:hover { background: rgba(16,185,129,0.1); }
        .btn-pdf { border-color: #dc2626; color: #dc2626; background: transparent; } .btn-pdf:hover { background: rgba(220,38,38,0.1); }

        /* --- TABLE --- */
        .table-card { margin-bottom: 2rem; overflow: hidden; }
        .table-responsive { overflow-x: auto; max-height: 800px; position: relative;} 
        table { width: 100%; border-collapse: separate; border-spacing: 0; min-width: 900px; }
        
        /* Glassmorphism Sticky Headers */
        th { 
            position: sticky; top: 0; z-index: 10; 
            background: rgba(248, 250, 252, 0.85);
            backdrop-filter: blur(8px); 
            text-align: left; padding: 1.25rem 1.5rem; font-size: 0.75rem; text-transform: uppercase; 
            color: var(--text-muted); font-weight: 800; border-bottom: 1px solid var(--border); user-select: none;
        }
        html.dark th { background: rgba(15, 23, 42, 0.85); }
        
        th.sortable { cursor: pointer; transition: color 0.2s; }
        th.sortable:hover { color: var(--text-main); }
        th.sortable i { margin-left: 6px; opacity: 0.4; }
        th.sortable.active i { opacity: 1; color: var(--primary); }

        td { padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--border); vertical-align: middle; transition: background 0.2s;}
        .text-right { text-align: right !important; }
        
        /* Row grouping visual tweaks */
        .date-group-border td { border-top: 4px solid var(--border) !important; }
        tr:first-child.date-group-border td { border-top: none !important; } 

        .clickable-row { cursor: pointer; position: relative;}
        .clickable-row:hover td { background-color: var(--primary-light); }
        .view-btn { font-size: 0.75rem; font-weight: 700; background: var(--primary); color: white; padding: 6px 12px; border-radius: 99px; opacity: 0; transition: var(--transition); display: inline-flex; align-items: center; gap: 6px; transform: translateX(10px); }
        .clickable-row:hover .view-btn { opacity: 1; transform: translateX(0); }

        /* Table Skeleton specifically */
        .table-skeleton-row { display: flex; align-items: center; padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--border); }
        .ts-col { padding: 0 10px; }

        /* --- MODAL --- */
        .modal-overlay { position: fixed; inset: 0; background: rgba(15, 23, 42, 0.7); z-index: 100; display: none; align-items: center; justify-content: center; backdrop-filter: blur(5px); }
        .modal-box { background: var(--bg-surface); border-radius: var(--radius-lg); width: 95%; max-width: 850px; box-shadow: var(--shadow-lg); display: flex; flex-direction: column; max-height: 85vh; overflow: hidden; transform: scale(0.95); opacity: 0; transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);}
        .modal-overlay.active .modal-box { transform: scale(1); opacity: 1; }
        
        .modal-header { display: flex; justify-content: space-between; align-items: center; padding: 1.5rem 2rem; border-bottom: 1px solid var(--border); }
        .modal-title-wrapper { display: flex; align-items: center; gap: 1rem; }
        .modal-title { font-size: 1.35rem; font-weight: 800; margin: 0; display:flex; align-items:center; gap: 10px;}
        .close-modal { background: var(--bg-body); border: 1px solid var(--border); width: 38px; height: 38px; border-radius: 50%; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: var(--transition);}
        .close-modal:hover { background: var(--danger-light); color: var(--danger); transform: rotate(90deg);}
        .modal-back-btn { display: none; background: var(--primary-light); color: var(--primary); width: 38px; height: 38px; border-radius: 50%; border:none; align-items: center; justify-content: center; cursor: pointer; }
        
        .modal-body { overflow-y: auto; flex: 1; padding: 0; background: var(--bg-body); position: relative;}
        .modal-body table th { background: rgba(255, 255, 255, 0.85); }
        html.dark .modal-body table th { background: rgba(30, 41, 59, 0.85); }
        .modal-body table td { background: var(--bg-surface); }
        .modal-footer { padding: 1.25rem 2rem; border-top: 1px solid var(--border); background: var(--bg-surface); text-align: right; }

        .hidden { display: none !important; }
        
        .empty-state { text-align: center; padding: 5rem 2rem; color: var(--text-muted); display: flex; flex-direction: column; align-items: center; gap: 1rem;}
        .empty-state-icon { width: 80px; height: 80px; border-radius: 50%; background: var(--bg-body); display: flex; align-items: center; justify-content: center; font-size: 2.5rem; color: var(--border-strong); margin-bottom: 0.5rem; }
        .empty-state h4 { margin: 0; font-size: 1.25rem; font-weight: 800; color: var(--text-main);}
    </style>
</head>
<body>

    <header class="main-header">
        <div class="navbar">
            <a href="dashboard.php" class="logo">
                <img src="<?php echo htmlspecialchars($base_path . 'uploads/CUPAD LOGO.png'); ?>" style="height:38px; margin-right:8px;" alt="CUPAD">
                <span>CUPAD Admin</span>
            </a>
            <div class="nav-right">
                <button id="themeToggle" title="Switch Theme"><i class="fas fa-moon"></i></button>
                <div class="user-pill">
                    <?php if(!empty($my_profile_pic) && file_exists($base_path . $my_profile_pic)): ?>
                        <img src="<?php echo htmlspecialchars($base_path . $my_profile_pic); ?>" class="user-avatar-img" alt="User">
                    <?php else: ?>
                        <div class="user-avatar-fallback"><?php echo strtoupper(substr($full_name ?? 'A', 0, 1)); ?></div>
                    <?php endif; ?>
                    <div class="user-info">
                        <span class="user-name"><?php echo htmlspecialchars($full_name ?? ''); ?></span>
                        <span class="user-role"><?php echo htmlspecialchars($user_role ?? ''); ?></span>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <main class="container">
        
        <div class="page-header animate-up">
            <div class="page-title"><i class="fas fa-chart-bar"></i> Branch Disbursements</div>
            <a href="dashboard.php" class="btn btn-outline"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
        </div>

        <!-- 1. STATS SECTION -->
        <div id="stats-wrapper" class="animate-up" style="animation-delay: 0.1s;">
            <!-- Real Content -->
            <div class="stats-grid" id="summary-content">
                <div class="card stat-card">
                    <div class="stat-icon stat-blue"><i class="fas fa-file-invoice-dollar"></i></div>
                    <div class="stat-info">
                        <h3 id="total-count" class="tabular-nums"><?php echo number_format($totalDisbursementsCount); ?></h3>
                        <p>Total Disbursements</p>
                    </div>
                </div>
                <div class="card stat-card">
                    <div class="stat-icon stat-green"><i class="fas fa-wallet"></i></div>
                    <div class="stat-info">
                        <h3 id="total-amount" class="tabular-nums">₦<?php echo number_format($totalDisbursedAmount); ?></h3>
                        <p>Total Disbursed</p>
                    </div>
                </div>
                <div class="card stat-card">
                    <div class="stat-icon stat-purple"><i class="fas fa-network-wired"></i></div>
                    <div class="stat-info">
                        <h3 id="num-branches" class="tabular-nums"><?php echo number_format($initialData['numBranches']); ?></h3>
                        <p>Active Branches</p>
                    </div>
                </div>
            </div>
            
            <!-- Skeleton Content -->
            <div class="stats-grid hidden" id="summary-skeleton">
                <div class="card stat-card">
                    <div class="skel skel-circle" style="width: 60px; height: 60px; flex-shrink:0;"></div>
                    <div style="flex:1;">
                        <div class="skel skel-title"></div>
                        <div class="skel skel-text" style="width: 40%;"></div>
                    </div>
                </div>
                <div class="card stat-card">
                    <div class="skel skel-circle" style="width: 60px; height: 60px; flex-shrink:0;"></div>
                    <div style="flex:1;">
                        <div class="skel skel-title"></div>
                        <div class="skel skel-text" style="width: 40%;"></div>
                    </div>
                </div>
                <div class="card stat-card">
                    <div class="skel skel-circle" style="width: 60px; height: 60px; flex-shrink:0;"></div>
                    <div style="flex:1;">
                        <div class="skel skel-title"></div>
                        <div class="skel skel-text" style="width: 40%;"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 2. CHART SECTION -->
        <div class="card chart-card animate-up" id="chart-container" style="animation-delay: 0.15s;">
            <!-- Real Chart -->
            <canvas id="disbursementChart"></canvas>
            
            <!-- Chart Skeleton Overlay -->
            <div class="chart-skeleton-overlay" id="chart-skeleton">
                <div class="skel skel-bar" style="height: 30%;"></div>
                <div class="skel skel-bar" style="height: 60%;"></div>
                <div class="skel skel-bar" style="height: 40%;"></div>
                <div class="skel skel-bar" style="height: 80%;"></div>
                <div class="skel skel-bar" style="height: 50%;"></div>
                <div class="skel skel-bar" style="height: 90%;"></div>
                <div class="skel skel-bar" style="height: 35%;"></div>
                <div class="skel skel-bar" style="height: 70%;"></div>
                <div class="skel skel-bar" style="height: 20%;"></div>
                <div class="skel skel-bar" style="height: 100%;"></div>
                <div class="skel skel-bar" style="height: 65%;"></div>
                <div class="skel skel-bar" style="height: 45%;"></div>
            </div>
        </div>

        <!-- 3. FILTER SECTION -->
        <div class="card search-card animate-up" style="animation-delay: 0.2s;">
            <form id="filter-form">
                <div class="search-form-grid">
                    <div class="form-group">
                        <label><i class="far fa-calendar-alt text-muted" style="margin-right:6px;"></i> Quick Date</label>
                        <select id="date-range-quick" class="form-control">
                            <option value="">Custom Range</option>
                            <optgroup label="Daily">
                                <option value="today">Today</option>
                                <option value="yesterday">Yesterday</option>
                                <option value="2daysago">2 Days Ago</option>
                            </optgroup>
                            <optgroup label="Specific Days (Last Week)">
                                <option value="lastweekmonday">Last Week Monday</option>
                                <option value="lastweektuesday">Last Week Tuesday</option>
                                <option value="lastweekwednesday">Last Week Wednesday</option>
                                <option value="lastweekthursday">Last Week Thursday</option>
                                <option value="lastweekfriday">Last Week Friday</option>
                            </optgroup>
                            <optgroup label="Intervals & Periods">
                                <option value="thisweek">This Week</option>
                                <option value="lastweek">Last Week</option>
                                <option value="last7days">Last 7 Days</option>
                                <option value="last30days">Last 30 Days</option>
                                <option value="thismonth">This Month</option>
                                <option value="lastmonth">Last Month</option>
                                <option value="last3months">Last 3 Months</option>
                                <option value="last6months">Last 6 Months</option>
                                <option value="thisyear">This Year</option>
                                <option value="lastyear">Last Year</option>
                            </optgroup>
                        </select>
                    </div>
                    
                    <div class="form-group"><label>Start Date</label><input type="date" id="start_date" name="start_date" class="form-control" value="<?php echo htmlspecialchars($startDate ?? ''); ?>"></div>
                    <div class="form-group"><label>End Date</label><input type="date" id="end_date" name="end_date" class="form-control" value="<?php echo htmlspecialchars($endDate ?? ''); ?>"></div>

                    <div class="form-group">
                        <label><i class="fas fa-map-marker-alt text-muted" style="margin-right:6px;"></i> Zone</label>
                        <select id="zone" name="zone" class="form-control">
                            <option value="">All Zones</option>
                            <?php foreach ($zones as $zone): ?>
                                <option value="<?php echo htmlspecialchars($zone['id'] ?? ''); ?>" <?php echo ($selectedZone == $zone['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($zone['name'] ?? ''); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group"><label>Area</label><select id="area" name="area" class="form-control"><option value="">All Areas</option></select></div>
                    <div class="form-group"><label>Branch</label><select id="branch" name="branch" class="form-control"><option value="">All Branches</option></select></div>

                    <div class="form-group">
                        <label><i class="fas fa-user-tie text-muted" style="margin-right:6px;"></i> Credit Officer</label>
                        <select id="co_username" name="co_username" class="form-control">
                            <option value="">All Officers</option>
                            <?php foreach ($coUsersList as $user): ?>
                                <option value="<?php echo htmlspecialchars($user['username'] ?? ''); ?>" <?php echo ($selectedCoUsername == $user['username']) ? 'selected' : ''; ?>><?php echo htmlspecialchars(($user['full_name'] ?? $user['username'] ?? '') . ' (' . ($user['username'] ?? '') . ')'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label><i class="fas fa-search text-muted" style="margin-right:6px;"></i> Search</label>
                        <input type="text" id="search_tags" name="search_tags" class="form-control" placeholder="Search branch..." value="<?php echo htmlspecialchars($searchTags ?? ''); ?>">
                    </div>
                </div>

                <div class="btn-group">
                    <div class="btn-sub-group">
                        <button type="button" class="btn btn-outline" id="clear-filter-btn"><i class="fas fa-redo"></i> Reset Filters</button>
                    </div>
                    <div class="btn-sub-group">
                        <button type="button" class="btn btn-outline btn-csv" id="export-csv-btn"><i class="fas fa-file-csv"></i> Export CSV</button>
                        <button type="button" class="btn btn-outline btn-pdf" id="export-pdf-btn"><i class="fas fa-file-pdf"></i> Export PDF</button>
                        <button type="button" class="btn btn-outline" id="print-table-btn"><i class="fas fa-print"></i> Print</button>
                    </div>
                </div>
            </form>
        </div>

        <!-- 4. TABLE SECTION -->
        <div class="card table-card animate-up" style="animation-delay: 0.3s;" id="table-wrapper">
            
            <!-- Skeleton Table -->
            <div id="table-skeleton" class="hidden">
                <div class="table-skeleton-row" style="background:var(--bg-body);"><div class="skel skel-text" style="width:100px;"></div></div>
                <?php for($i=0; $i<6; $i++): ?>
                <div class="table-skeleton-row">
                    <div class="ts-col" style="width: 15%;"><div class="skel skel-text" style="width: 60%;"></div></div>
                    <div class="ts-col" style="width: 15%;"><div class="skel skel-text" style="width: 50%;"></div></div>
                    <div class="ts-col" style="width: 35%;"><div class="skel skel-text" style="width: 80%;"></div></div>
                    <div class="ts-col" style="width: 20%; display:flex; justify-content:flex-end;"><div class="skel skel-text" style="width: 60%;"></div></div>
                    <div class="ts-col" style="width: 15%; display:flex; justify-content:flex-end;"><div class="skel skel-text" style="width: 30%;"></div></div>
                </div>
                <?php endfor; ?>
            </div>

            <!-- Real Table -->
            <div class="table-responsive" id="table-content">
                <table id="report-table">
                    <thead>
                        <tr>
                            <th class="sortable active" data-sort="date" style="width: 15%;">Date <i class="fas fa-sort-down"></i></th>
                            <th style="width: 15%;">Day</th>
                            <th class="sortable" data-sort="branch" style="width: 35%;">Branch Name <i class="fas fa-sort"></i></th>
                            <th class="sortable text-right" data-sort="amount" style="width: 20%;">Disbursement <i class="fas fa-sort"></i></th>
                            <th class="sortable text-right" data-sort="clients" style="width: 15%;">Clients <i class="fas fa-sort"></i> <span style="opacity:0; width:30px; display:inline-block;"></span></th>
                        </tr>
                    </thead>
                    <tbody id="report-table-body">
                        <!-- Rendered by JS -->
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <!-- DRILL DOWN MODAL -->
    <div id="drillDownModal" class="modal-overlay">
        <div class="modal-box">
            <div class="modal-header">
                <div class="modal-title-wrapper">
                    <button id="modalBackBtn" class="modal-back-btn" title="Back"><i class="fas fa-arrow-left"></i></button>
                    <h3 class="modal-title" id="modalTitleText">Details</h3>
                </div>
                <button class="close-modal" onclick="closeModal()" title="Close"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body table-responsive">
                <!-- Modal Skeleton -->
                <div id="modal-skeleton" class="hidden" style="position: absolute; inset:0; background:var(--bg-body); z-index:5;">
                    <div class="table-skeleton-row" style="background:var(--bg-surface);"><div class="skel skel-text" style="width:100px;"></div></div>
                    <?php for($i=0; $i<5; $i++): ?>
                    <div class="table-skeleton-row" style="background:var(--bg-surface);">
                        <div class="ts-col" style="width: 50%; display:flex; align-items:center; gap:12px;">
                            <div class="skel skel-circle" style="width:32px; height:32px; flex-shrink:0;"></div>
                            <div class="skel skel-text" style="width: 60%;"></div>
                        </div>
                        <div class="ts-col" style="width: 30%; display:flex; justify-content:flex-end;"><div class="skel skel-text" style="width: 70%;"></div></div>
                        <div class="ts-col" style="width: 20%; display:flex; justify-content:flex-end;"><div class="skel skel-text" style="width: 40%;"></div></div>
                    </div>
                    <?php endfor; ?>
                </div>

                <!-- Modal Content -->
                <table id="modal-table" style="width: 100%;">
                    <thead id="modal-thead"></thead>
                    <tbody id="modal-tbody"></tbody>
                </table>
            </div>
            <div class="modal-footer"><button class="btn btn-outline" onclick="closeModal()">Close Window</button></div>
        </div>
    </div>

    <script>
        // --- NATIVE CURRENCY FORMATTER (UI ONLY) ---
        const formatCurrency = (amount) => {
            return new Intl.NumberFormat('en-NG', { style: 'currency', currency: 'NGN', minimumFractionDigits: 0 }).format(amount);
        };

        // --- THEME LOGIC ---
        const themeBtn = document.getElementById('themeToggle');
        const html = document.documentElement;
        if(localStorage.getItem('theme') === 'dark') { html.classList.add('dark'); themeBtn.innerHTML = '<i class="fas fa-sun"></i>'; }
        
        themeBtn.addEventListener('click', () => {
            html.classList.toggle('dark');
            const isDark = html.classList.contains('dark');
            localStorage.setItem('theme', isDark ? 'dark' : 'light');
            themeBtn.innerHTML = isDark ? '<i class="fas fa-sun"></i>' : '<i class="fas fa-moon"></i>';
            if (disbursementChart) updateChart(currentBranchData); // Re-render chart colors
        });

        // --- GLOBAL VARIABLES ---
        let currentBranchData = <?php echo json_encode($branchDisbursements); ?>;
        let currentOpenBranch = null; 
        let isFetching = false;
        let disbursementChart = null;
        let currentSort = { column: 'date', order: 'asc' }; // Sort State

        function debounce(func, wait) {
            let timeout;
            return function(...args) { clearTimeout(timeout); timeout = setTimeout(() => func.apply(this, args), wait); };
        }

        document.addEventListener('DOMContentLoaded', function () {
            const areasData = <?php echo json_encode($areas); ?>;
            const branchesData = <?php echo json_encode($branches); ?>;
            const form = document.getElementById('filter-form');
            const zoneSelect = document.getElementById('zone');
            const areaSelect = document.getElementById('area');
            const branchSelect = document.getElementById('branch');
            const coSelect = document.getElementById('co_username');
            const startDateInput = document.getElementById('start_date');
            const endDateInput = document.getElementById('end_date');
            const dateRangeQuickSelect = document.getElementById('date-range-quick');
            const searchInput = document.getElementById('search_tags');
            const modal = document.getElementById('drillDownModal');
            const modalOverlay = document.querySelector('.modal-overlay');
            const modalTbody = document.getElementById('modal-tbody');

            // Initialize UI
            populateAreas('<?php echo $selectedZone ?>', '<?php echo $selectedArea ?>');
            populateBranches('<?php echo $selectedArea ?>', '<?php echo $selectedBranch ?>');
            renderMainTable(currentBranchData);
            initChart();
            updateChart(currentBranchData);

            // --- DATE UTILITIES ---
            function formatDate(date) {
                const y = date.getFullYear(); const m = String(date.getMonth() + 1).padStart(2, '0'); const d = String(date.getDate()).padStart(2, '0');
                return `${y}-${m}-${d}`;
            }

            function applyDateRange(rangeKey) {
                const today = new Date(); let start = null; let end = new Date(today);
                const currentDayOfWeek = today.getDay() || 7; 

                if (rangeKey === 'today') { start = today; } 
                else if (rangeKey === 'yesterday') { start = new Date(today.setDate(today.getDate() - 1)); end = start; }
                else if (rangeKey === '2daysago') { start = new Date(today.setDate(today.getDate() - 2)); end = start; }
                else if (rangeKey === 'thisweek') { start = new Date(today.setDate(today.getDate() - currentDayOfWeek + 1)); }
                else if (rangeKey === 'lastweek') { start = new Date(today.setDate(today.getDate() - currentDayOfWeek - 6)); end = new Date(today); end.setDate(today.getDate() - currentDayOfWeek); }
                else if (rangeKey.startsWith('lastweek')) {
                    const dayMap = { 'lastweekmonday': 6, 'lastweektuesday': 5, 'lastweekwednesday': 4, 'lastweekthursday': 3, 'lastweekfriday': 2 };
                    if (dayMap[rangeKey] !== undefined) { start = new Date(today.setDate(today.getDate() - currentDayOfWeek - dayMap[rangeKey])); end = start; }
                }
                else if (rangeKey === 'last7days') { start = new Date(today.setDate(today.getDate() - 6)); } 
                else if (rangeKey === 'last30days') { start = new Date(today.setDate(today.getDate() - 29)); } 
                else if (rangeKey === 'last3months') { start = new Date(today.setMonth(today.getMonth() - 3)); }
                else if (rangeKey === 'last6months') { start = new Date(today.setMonth(today.getMonth() - 6)); }
                else if (rangeKey === 'thismonth') { start = new Date(today.getFullYear(), today.getMonth(), 1); } 
                else if (rangeKey === 'lastmonth') { start = new Date(today.getFullYear(), today.getMonth() - 1, 1); end = new Date(today.getFullYear(), today.getMonth(), 0); }
                else if (rangeKey === 'thisyear') { start = new Date(today.getFullYear(), 0, 1); } 
                else if (rangeKey === 'lastyear') { start = new Date(today.getFullYear() - 1, 0, 1); end = new Date(today.getFullYear() - 1, 11, 31); } 
                else { return; }

                startDateInput.value = formatDate(start); endDateInput.value = formatDate(end);
                fetchReportData();
            }

            // --- HIERARCHY CASCADING ---
            function populateAreas(zoneId, selectedId = null) {
                areaSelect.innerHTML = '<option value="">All Areas</option>'; branchSelect.innerHTML = '<option value="">All Branches</option>';
                if (zoneId) {
                    areasData.filter(a => a.zone_id == zoneId).forEach(area => {
                        const option = new Option(area.name, area.id); if (area.id == selectedId) option.selected = true; areaSelect.add(option);
                    });
                }
            }
            function populateBranches(areaId, selectedId = null) {
                branchSelect.innerHTML = '<option value="">All Branches</option>';
                if (areaId) {
                    branchesData.filter(b => b.area_id == areaId).forEach(branch => {
                        const option = new Option(branch.name, branch.id); if (branch.id == selectedId) option.selected = true; branchSelect.add(option);
                    });
                }
            }
            
            // --- AUTO FILTER LISTENERS ---
            searchInput.addEventListener('input', debounce(fetchReportData, 500));
            form.querySelectorAll('select:not(#date-range-quick)').forEach(select => select.addEventListener('change', fetchReportData));
            dateRangeQuickSelect.addEventListener('change', function() { applyDateRange(this.value); });
            [startDateInput, endDateInput].forEach(input => { input.addEventListener('change', () => { dateRangeQuickSelect.value = ''; fetchReportData(); }); });
            zoneSelect.addEventListener('change', function () { populateAreas(this.value); coSelect.value = ''; });
            areaSelect.addEventListener('change', function () { populateBranches(this.value); coSelect.value = ''; });
            form.addEventListener('submit', (e) => { e.preventDefault(); fetchReportData(); });

            async function fetchReportData() {
                if(isFetching) return;
                isFetching = true;
                toggleSkeleton(true);
                try {
                    const formData = new URLSearchParams(new FormData(form)).toString();
                    const response = await fetch(`?ajax=true&${formData}`);
                    const json = await response.json();
                    if (json.success) updateReportUI(json.data);
                } catch (error) { console.error('Fetch error:', error); } 
                finally { toggleSkeleton(false); isFetching = false; }
            }

            function toggleSkeleton(show) {
                document.getElementById('summary-content').classList.toggle('hidden', show);
                document.getElementById('summary-skeleton').classList.toggle('hidden', !show);
                
                document.getElementById('table-content').classList.toggle('hidden', show);
                document.getElementById('table-skeleton').classList.toggle('hidden', !show);
                
                const chartOverlay = document.getElementById('chart-skeleton');
                if(show) { chartOverlay.classList.add('active'); } else { chartOverlay.classList.remove('active'); }
            }

            function updateReportUI(data) {
                document.getElementById('total-count').textContent = data.totalDisbursementsCount.toLocaleString();
                document.getElementById('total-amount').textContent = formatCurrency(data.totalDisbursedAmount);
                document.getElementById('num-branches').textContent = data.numBranches.toLocaleString();
                
                currentBranchData = data.branchDisbursements;
                updateChart(currentBranchData);
                sortDataAndRender();
            }

            // --- CHART.JS LOGIC ---
            function initChart() {
                const ctx = document.getElementById('disbursementChart').getContext('2d');
                Chart.defaults.font.family = "'Plus Jakarta Sans', sans-serif";
                
                disbursementChart = new Chart(ctx, {
                    type: 'bar',
                    data: { labels: [], datasets: [{ label: 'Total Disbursed', data: [], backgroundColor: '#3b82f6', borderRadius: 6, hoverBackgroundColor: '#1d4ed8' }] },
                    options: {
                        responsive: true, maintainAspectRatio: false,
                        animation: { duration: 500 },
                        plugins: { 
                            legend: { display: false },
                            tooltip: { backgroundColor: 'rgba(15, 23, 42, 0.9)', titleFont: { size: 13 }, bodyFont: { size: 14, weight:'bold' }, padding: 12, cornerRadius: 8, callbacks: { label: function(context) { return formatCurrency(context.raw); } } }
                        },
                        scales: {
                            y: { beginAtZero: true, grid: { color: 'rgba(100, 116, 139, 0.1)' }, border:{display:false}, ticks: { callback: function(value) { return '₦' + (value/1000).toFixed(0) + 'k'; }, font: {size: 11} } },
                            x: { grid: { display: false }, border:{display:false}, ticks: { font: {size: 11} } }
                        }
                    }
                });
            }

            function updateChart(data) {
                if(!disbursementChart) return;
                const isDark = html.classList.contains('dark');
                const textColor = isDark ? '#94a3b8' : '#64748b';
                
                const grouped = {};
                data.forEach(d => {
                    const dateObj = new Date(d.date || '1970-01-01');
                    if(isNaN(dateObj.getTime())) return;
                    const label = dateObj.toLocaleDateString('en-GB', { day: 'numeric', month: 'short' });
                    grouped[label] = (grouped[label] || 0) + parseFloat(d.totalAmount || 0);
                });

                disbursementChart.data.labels = Object.keys(grouped);
                disbursementChart.data.datasets[0].data = Object.values(grouped);
                disbursementChart.options.scales.x.ticks.color = textColor;
                disbursementChart.options.scales.y.ticks.color = textColor;
                disbursementChart.update();
            }

            // --- TABLE RENDERING & SORTING ---
            document.querySelectorAll('th.sortable').forEach(th => {
                th.addEventListener('click', () => {
                    const column = th.dataset.sort;
                    if(currentSort.column === column) {
                        currentSort.order = currentSort.order === 'asc' ? 'desc' : 'asc';
                    } else {
                        currentSort.column = column; currentSort.order = 'desc'; 
                    }
                    
                    document.querySelectorAll('th.sortable').forEach(el => { el.classList.remove('active'); el.querySelector('i').className = 'fas fa-sort'; });
                    th.classList.add('active');
                    th.querySelector('i').className = currentSort.order === 'asc' ? 'fas fa-sort-up' : 'fas fa-sort-down';
                    
                    sortDataAndRender();
                });
            });

            function sortDataAndRender() {
                let sortedData = [...currentBranchData];
                
                sortedData.sort((a, b) => {
                    let valA, valB;
                    if(currentSort.column === 'date') { valA = new Date(a.date).getTime(); valB = new Date(b.date).getTime(); }
                    else if(currentSort.column === 'branch') { valA = a.branch.toLowerCase(); valB = b.branch.toLowerCase(); }
                    else if(currentSort.column === 'amount') { valA = parseFloat(a.totalAmount); valB = parseFloat(b.totalAmount); }
                    else if(currentSort.column === 'clients') { valA = parseInt(a.numDisbursements); valB = parseInt(b.numDisbursements); }
                    
                    if (valA < valB) return currentSort.order === 'asc' ? -1 : 1;
                    if (valA > valB) return currentSort.order === 'asc' ? 1 : -1;
                    return 0;
                });
                
                renderMainTable(sortedData);
            }

            function renderMainTable(data) {
                const tbody = document.getElementById('report-table-body');
                
                if (data.length === 0) {
                    tbody.innerHTML = `<tr><td colspan="5"><div class="empty-state"><div class="empty-state-icon"><i class="fas fa-search"></i></div><h4>No Data Found</h4><p>Try adjusting your filters or date range.</p></div></td></tr>`;
                    return;
                }

                let htmlContent = '';

                if (currentSort.column === 'date') {
                    const groupedData = {};
                    data.forEach(item => { const k = item.date || '1970-01-01'; if (!groupedData[k]) groupedData[k] = []; groupedData[k].push(item); });

                    for (const [dateString, branches] of Object.entries(groupedData)) {
                        const dateObj = new Date(dateString);
                        const dayName = !isNaN(dateObj) ? dateObj.toLocaleDateString('en-GB', { weekday: 'long' }) : 'N/A';
                        const formattedDate = !isNaN(dateObj) ? dateObj.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }) : 'N/A';

                        branches.forEach((item, index) => {
                            const branchName = item.branch || 'Unknown Branch';
                            htmlContent += `<tr class="clickable-row branch-row ${index === 0 ? 'date-group-border' : ''}" data-branch-name="${branchName}">`;
                            if (index === 0) {
                                htmlContent += `<td rowspan="${branches.length}" style="vertical-align:top; white-space:nowrap; font-weight:700;">${formattedDate}</td>
                                                <td rowspan="${branches.length}" style="vertical-align:top; color:var(--text-muted);">${dayName}</td>`;
                            }
                            htmlContent += `<td><strong>${branchName}</strong></td>
                                            <td class="text-right tabular-nums" style="font-weight:800; color:var(--success)">${formatCurrency(item.totalAmount)}</td>
                                            <td class="text-right tabular-nums"><span style="font-weight:700; margin-right:15px;">${item.numDisbursements}</span><span class="view-btn">View <i class="fas fa-arrow-right"></i></span></td></tr>`;
                        });
                    }
                } 
                else {
                    data.forEach((item, index) => {
                        const dateObj = new Date(item.date || '1970-01-01');
                        const dayName = !isNaN(dateObj) ? dateObj.toLocaleDateString('en-GB', { weekday: 'short' }) : 'N/A';
                        const formattedDate = !isNaN(dateObj) ? dateObj.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }) : 'N/A';
                        const branchName = item.branch || 'Unknown Branch';
                        
                        htmlContent += `<tr class="clickable-row branch-row" data-branch-name="${branchName}">
                                        <td style="white-space:nowrap; font-weight:700;">${formattedDate}</td>
                                        <td style="color:var(--text-muted);">${dayName}</td>
                                        <td><strong>${branchName}</strong></td>
                                        <td class="text-right tabular-nums" style="font-weight:800; color:var(--success)">${formatCurrency(item.totalAmount)}</td>
                                        <td class="text-right tabular-nums"><span style="font-weight:700; margin-right:15px;">${item.numDisbursements}</span><span class="view-btn">View <i class="fas fa-arrow-right"></i></span></td></tr>`;
                    });
                }
                
                tbody.innerHTML = htmlContent;
                document.querySelectorAll('.branch-row').forEach(row => row.addEventListener('click', () => fetchCoPerformance(row.dataset.branchName)));
            }

            // --- MODAL / DRILL DOWN LOGIC ---
            function toggleModalSkeleton(show) { 
                document.getElementById('modal-table').style.opacity = show ? '0' : '1'; 
                document.getElementById('modal-skeleton').classList.toggle('hidden', !show); 
            }
            
            async function fetchCoPerformance(branchName) {
                if (branchName === 'Unknown Branch') return; 
                modal.style.display = 'flex'; 
                setTimeout(() => modalOverlay.classList.add('active'), 10);
                
                toggleModalSkeleton(true); modalTbody.innerHTML = ''; 
                document.getElementById('modalTitleText').innerHTML = `<i class="fas fa-building text-muted"></i> <span>${branchName} Performance</span>`;
                document.getElementById('modalBackBtn').style.display = 'none'; currentOpenBranch = branchName; 

                try {
                    const formData = new URLSearchParams(new FormData(form)).toString();
                    const response = await fetch(`?ajax=true&breakdown_branch=${encodeURIComponent(branchName)}&${formData}`);
                    const json = await response.json();
                    if(json.success && json.data.type === 'co_breakdown') {
                        document.getElementById('modal-thead').innerHTML = `<tr><th>Credit Officer</th><th class="text-right">Total Disbursed</th><th class="text-right">Clients</th></tr>`;
                        if(json.data.coPerformance.length === 0){
                            modalTbody.innerHTML = `<tr><td colspan="3"><div class="empty-state"><div class="empty-state-icon"><i class="fas fa-user-slash"></i></div><h4>No Officers Found</h4></div></td></tr>`;
                        } else {
                            modalTbody.innerHTML = json.data.coPerformance.map(row => `
                                <tr class="clickable-row co-row" data-username="${row.username}" data-name="${row.co_name}">
                                    <td><div style="display:flex; align-items:center; gap:12px;"><div class="user-avatar-fallback" style="width:32px; height:32px; font-size:0.85rem;">${(row.co_name || 'U').charAt(0).toUpperCase()}</div><span style="font-weight:700;">${row.co_name}</span></div></td>
                                    <td class="text-right tabular-nums" style="font-weight:800; color:var(--success)">${formatCurrency(row.totalAmount)}</td>
                                    <td class="text-right tabular-nums"><span style="font-weight:700; margin-right:15px;">${row.numDisbursements}</span><span class="view-btn">View <i class="fas fa-arrow-right"></i></span></td>
                                </tr>`).join('');
                            document.querySelectorAll('.co-row').forEach(row => row.addEventListener('click', () => fetchCoDetails(row.dataset.username, row.dataset.name)));
                        }
                    }
                } catch(e) { console.error(e); } finally { toggleModalSkeleton(false); }
            }

            async function fetchCoDetails(username, name) {
                toggleModalSkeleton(true); modalTbody.innerHTML = '';
                document.getElementById('modalTitleText').innerHTML = `<i class="fas fa-user-tie text-muted"></i> <span>${name} Details</span> <i class="fas fa-spinner fa-spin text-muted" style="font-size:1rem; margin-left:8px;" id="modal-spinner"></i>`;
                document.getElementById('modalBackBtn').style.display = 'flex';

                try {
                    const formData = new URLSearchParams(new FormData(form)).toString();
                    const response = await fetch(`?ajax=true&breakdown_co=${encodeURIComponent(username)}&${formData}`);
                    const json = await response.json();
                    if(json.success && json.data.type === 'co_details') {
                        document.getElementById('modal-thead').innerHTML = `<tr><th>Date</th><th>Client Name</th><th class="text-right">Amount</th><th class="text-right">Ref ID</th></tr>`;
                        if(json.data.disbursements.length === 0){
                            modalTbody.innerHTML = `<tr><td colspan="4"><div class="empty-state"><div class="empty-state-icon"><i class="fas fa-receipt"></i></div><h4>No Disbursements</h4></div></td></tr>`;
                        } else {
                            modalTbody.innerHTML = json.data.disbursements.map(row => {
                                const d = new Date(row.date); const pd = isNaN(d) ? 'N/A' : d.toLocaleDateString('en-GB', { day:'2-digit', month:'short', year:'numeric' });
                                return `<tr><td style="color:var(--text-muted); font-size:0.9rem; font-weight:700;">${pd}</td><td style="font-weight:700;">${row.client_name}</td>
                                <td class="text-right tabular-nums" style="font-weight:800; color:var(--success)">${formatCurrency(row.principal_amount)}</td><td class="text-right tabular-nums" style="font-family:monospace; color:var(--text-muted);">#${row.disbursement_id}</td></tr>`;
                            }).join('');
                        }
                    }
                } catch(e) { console.error(e); } finally { toggleModalSkeleton(false); document.getElementById('modal-spinner')?.remove(); }
            }

            // --- UTILS & EXPORTS ---
            document.getElementById('clear-filter-btn').addEventListener('click', () => { form.reset(); populateAreas(''); fetchReportData(); });
            document.getElementById('modalBackBtn').addEventListener('click', () => { if (currentOpenBranch) fetchCoPerformance(currentOpenBranch); });
            
            window.closeModal = () => { 
                modalOverlay.classList.remove('active'); 
                setTimeout(() => { modal.style.display = 'none'; }, 300); 
            }; 
            window.onclick = (e) => { if(e.target === modal) closeModal(); };

            // CSV Export
            document.getElementById('export-csv-btn').addEventListener('click', () => {
                if (!currentBranchData || currentBranchData.length === 0) return alert('No data to export.');
                let csvContent = "Date,Day,Branch Name,Disbursement Amount (NGN),Clients\n";
                
                currentBranchData.forEach(d => {
                    const dateObj = new Date(d.date || '1970-01-01');
                    const fd = isNaN(dateObj) ? 'N/A' : dateObj.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
                    const dy = isNaN(dateObj) ? 'N/A' : dateObj.toLocaleDateString('en-GB', { weekday: 'short' });
                    csvContent += `"${fd}","${dy}","${d.branch}","${d.totalAmount}","${d.numDisbursements}"\n`;
                });

                const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
                const link = document.createElement("a");
                link.href = URL.createObjectURL(blob);
                link.download = `Branch_Disbursements_${new Date().toISOString().split('T')[0]}.csv`;
                link.click();
            });

            // FIXED PDF EXPORT
            document.getElementById('export-pdf-btn').addEventListener('click', () => {
                if (!currentBranchData || currentBranchData.length === 0) return alert('No data to export.');
                const { jsPDF } = window.jspdf; const doc = new jsPDF('p', 'mm', 'a4');
                doc.setFontSize(16); doc.text("Daily Branch Disbursements", 14, 20); doc.setFontSize(10); doc.text(`Generated on: ${new Date().toLocaleString()}`, 14, 28);
                
                const tableData = currentBranchData.map(d => {
                    const dateObj = new Date(d.date || '1970-01-01');
                    // Use NGN instead of Naira symbol to prevent the '¦' character issue in jsPDF
                    const safeAmount = `NGN ${new Intl.NumberFormat('en-US', { minimumFractionDigits: 0 }).format(d.totalAmount)}`;

                    return [
                        isNaN(dateObj) ? 'N/A' : dateObj.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }),
                        isNaN(dateObj) ? 'N/A' : dateObj.toLocaleDateString('en-GB', { weekday: 'short' }),
                        d.branch || 'Unknown Branch',
                        safeAmount, 
                        d.numDisbursements
                    ];
                });

                doc.autoTable({ 
                    startY: 35, 
                    head: [['Date', 'Day', 'Branch', 'Total Amount', 'Clients']], 
                    body: tableData, 
                    theme: 'striped', 
                    headStyles: { fillColor: [37, 99, 235] }, 
                    columnStyles: { 3: { halign: 'right' }, 4: { halign: 'right' } } 
                });
                
                doc.save('branch_disbursements_report.pdf');
            });

            // Print
            document.getElementById('print-table-btn').addEventListener('click', () => {
                const win = window.open('', '', 'height=700,width=900');
                win.document.write(`<html><head><title>Print Report</title><style>body{font-family:sans-serif; padding: 20px;} table{width:100%;border-collapse:collapse; margin-top:20px;} th{background:#f1f5f9; font-size:12px; text-transform:uppercase; text-align:left;} th,td{border-bottom:1px solid #e2e8f0; padding:12px;} .text-right{text-align:right;} .view-btn{display:none;} i.fa-sort-down, i.fa-sort-up, i.fa-sort{display:none;}</style></head><body><h2>Branch Disbursements</h2><p>Generated: ${new Date().toLocaleString()}</p>`);
                win.document.write(document.getElementById('report-table').cloneNode(true).outerHTML);
                win.document.write('</body></html>'); win.document.close(); setTimeout(() => win.print(), 500);
            });
        });
    </script>
</body>
</html>