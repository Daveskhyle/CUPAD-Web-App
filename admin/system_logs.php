<?php
// system_logs.php
date_default_timezone_set('Africa/Lagos');
ini_set('date.timezone', 'Africa/Lagos');
session_start();
require_once '../includes/config.php';
$conn = getDbConnection();

// Auth check
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: ../index.php');
    exit();
}

$base_path = '../';

// Fetch user profile pic
$my_pic = 'default_avatar.png';
$username = $_SESSION['username'] ?? '';
try {
    $stmt = $conn->prepare("SELECT profile_pic, full_name, role FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user_data = $stmt->fetch();
    $my_pic = $user_data['profile_pic'] ?? 'default_avatar.png';
    $full_name = $user_data['full_name'] ?? 'Admin';
    $role = $user_data['role'] ?? '';
} catch (Exception $e) { 
    $full_name = $_SESSION['full_name'] ?? 'Admin';
    $role = $_SESSION['role'] ?? '';
}

$profile_pic_path = $base_path . 'uploads/' . $my_pic;
$has_profile_pic = file_exists($profile_pic_path) && $my_pic !== 'default_avatar.png';

// Pagination and filtering
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 50;
$offset = ($page - 1) * $limit;

$filter_user = isset($_GET['user']) ? trim($_GET['user']) : '';
$filter_action = isset($_GET['action']) ? trim($_GET['action']) : '';
$filter_table = isset($_GET['table']) ? trim($_GET['table']) : '';
$filter_date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$filter_date_to = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';

// Build query
$where_clauses = [];
$params = [];

if ($filter_user) {
    $where_clauses[] = "(al.username LIKE ? OR al.user_id = ?)";
    $params[] = "%$filter_user%";
    $params[] = $filter_user;
}

if ($filter_action) {
    $where_clauses[] = "al.action = ?";
    $params[] = $filter_action;
}

if ($filter_table) {
    $where_clauses[] = "al.table_name = ?";
    $params[] = $filter_table;
}

if ($filter_date_from) {
    $where_clauses[] = "al.created_at >= ?";
    $params[] = $filter_date_from . ' 00:00:00';
}

if ($filter_date_to) {
    $where_clauses[] = "al.created_at <= ?";
    $params[] = $filter_date_to . ' 23:59:59';
}

$where_sql = $where_clauses ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

// Get total count
$count_sql = "SELECT COUNT(*) FROM audit_log al $where_sql";
$stmt = $conn->prepare($count_sql);
$stmt->execute($params);
$total_records = $stmt->fetchColumn();
$total_pages = ceil($total_records / $limit);

// Get logs with user details
$sql = "
    SELECT 
        al.*,
        u.full_name as user_full_name,
        u.role as user_role
    FROM audit_log al
    LEFT JOIN users u ON al.user_id = u.id
    $where_sql
    ORDER BY al.created_at DESC
    LIMIT $limit OFFSET $offset
";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get distinct actions for filter
$actions = $conn->query("SELECT DISTINCT action FROM audit_log WHERE action IS NOT NULL ORDER BY action")->fetchAll(PDO::FETCH_COLUMN);
$tables = $conn->query("SELECT DISTINCT table_name FROM audit_log WHERE table_name IS NOT NULL ORDER BY table_name")->fetchAll(PDO::FETCH_COLUMN);

// Get stats
$stats = [
    'total_logs' => $conn->query("SELECT COUNT(*) FROM audit_log")->fetchColumn(),
    'today_logs' => $conn->query("SELECT COUNT(*) FROM audit_log WHERE DATE(created_at) = CURDATE()")->fetchColumn(),
    'unique_users' => $conn->query("SELECT COUNT(DISTINCT user_id) FROM audit_log")->fetchColumn(),
    'unique_tables' => $conn->query("SELECT COUNT(DISTINCT table_name) FROM audit_log WHERE table_name IS NOT NULL")->fetchColumn()
];

// Export functionality
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="system_logs_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['ID', 'User', 'Action', 'Table', 'Record ID', 'Old Values', 'New Values', 'IP Address', 'User Agent', 'Date/Time']);
    
    $export_sql = str_replace("LIMIT $limit OFFSET $offset", "", $sql);
    $stmt = $conn->prepare($export_sql);
    $stmt->execute($params);
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, [
            $row['id'],
            $row['username'] ?? 'System',
            $row['action'],
            $row['table_name'] ?? '-',
            $row['record_id'] ?? '-',
            $row['old_values'] ? substr($row['old_values'], 0, 100) . '...' : '-',
            $row['new_values'] ? substr($row['new_values'], 0, 100) . '...' : '-',
            $row['ip_address'] ?? '-',
            $row['user_agent'] ? substr($row['user_agent'], 0, 50) . '...' : '-',
            $row['created_at']
        ]);
    }
    fclose($output);
    exit();
}
?>
<!DOCTYPE html>
<html lang="en" class="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Logs - CUPAD</title>
    <link rel="icon" type="image/png" href="<?php echo htmlspecialchars($base_path . 'uploads/CUPAD LOGO.png'); ?>">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />
    <style>
        :root { 
            --primary: #2563eb; --primary-dark: #1d4ed8; --success: #059669; --warning: #d97706; 
            --danger: #dc2626; --info: #0284c7; --bg-body: #f1f5f9; --bg-surface: #ffffff; 
            --text-main: #0f172a; --text-muted: #64748b; --border: #e2e8f0; 
            --radius-lg: 16px; --radius-md: 10px; --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05); 
            --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1); --nav-height: 70px; 
        }
        html.dark { 
            --bg-body: #0f172a; --bg-surface: #1e293b; --text-main: #f8fafc; 
            --text-muted: #94a3b8; --border: #334155; --primary: #3b82f6; 
        }
        * { box-sizing: border-box; outline: none; }
        body { 
            font-family: 'Plus Jakarta Sans', sans-serif; background: var(--bg-body); 
            color: var(--text-main); margin: 0; padding-top: var(--nav-height); 
            transition: background-color 0.3s, color 0.3s; 
        }
        a { text-decoration: none; color: inherit; }
        
        /* Header */
        .main-header { 
            position: fixed; top: 0; left: 0; right: 0; height: var(--nav-height); 
            background: rgba(255,255,255,0.85); backdrop-filter: blur(12px); 
            border-bottom: 1px solid var(--border); z-index: 50; 
        }
        html.dark .main-header { background: rgba(15, 23, 42, 0.85); }
        .navbar { 
            max-width: 1400px; margin: 0 auto; padding: 0 1.5rem; 
            display: flex; align-items: center; justify-content: space-between; height: 100%; 
        }
        .logo { 
            display: flex; align-items: center; gap: 0.75rem; 
            font-weight: 800; font-size: 1.35rem; color: var(--primary); 
        }
        .logo img { height: 38px; }
        .nav-right { display: flex; align-items: center; gap: 1rem; }
        .icon-btn { 
            width: 36px; height: 36px; border-radius: 50%; border: none; 
            background: transparent; color: var(--text-muted); cursor: pointer; 
            display: flex; align-items: center; justify-content: center; 
            font-size: 1.1rem; transition: 0.2s; 
        }
        .icon-btn:hover { background: var(--bg-body); color: var(--text-main); }
        .user-dropdown-wrap { position: relative; }
        .user-pill { 
            display: flex; align-items: center; gap: 0.75rem; 
            padding: 4px 8px 4px 4px; border: 1px solid var(--border); 
            border-radius: 99px; background: var(--bg-surface); cursor: pointer; 
        }
        .user-avatar { width: 34px; height: 34px; border-radius: 50%; object-fit: cover; }
        .user-avatar-fallback { 
            width: 34px; height: 34px; border-radius: 50%; background: var(--bg-body); 
            color: var(--text-muted); display: flex; align-items: center; justify-content: center; 
        }
        .user-info { display: flex; flex-direction: column; line-height: 1.1; }
        .user-name { font-weight: 600; font-size: 0.85rem; }
        .user-role { font-size: 0.7rem; color: var(--text-muted); text-transform: uppercase; }
        .dropdown-menu { 
            position: absolute; top: 125%; right: 0; background: var(--bg-surface); 
            border: 1px solid var(--border); border-radius: var(--radius-md); 
            box-shadow: var(--shadow-md); min-width: 200px; display: none; 
            flex-direction: column; overflow: hidden; 
        }
        .dropdown-menu.show { display: flex; }
        .dropdown-item { 
            padding: 0.75rem 1rem; display: flex; align-items: center; 
            gap: 0.75rem; font-size: 0.9rem; 
        }
        .dropdown-item:hover { background: var(--bg-body); color: var(--primary); }
        .text-danger { color: var(--danger) !important; }

        /* Main Container */
        .container { max-width: 1400px; margin: 0 auto; padding: 2rem 1.5rem 4rem; }
        
        /* Page Header */
        .page-header { 
            display: flex; justify-content: space-between; align-items: center; 
            margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem; 
        }
        .page-title { 
            font-size: 1.5rem; font-weight: 800; margin: 0;
            display: flex; align-items: center; gap: 0.5rem;
        }

        /* Stats Cards */
        .stats-grid { 
            display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); 
            gap: 1rem; margin-bottom: 1.5rem; 
        }
        .stat-card { 
            background: var(--bg-surface); padding: 1.25rem; border-radius: var(--radius-lg);
            border: 1px solid var(--border); display: flex; align-items: center; gap: 1rem;
        }
        .stat-icon { 
            width: 48px; height: 48px; border-radius: 12px; 
            display: flex; align-items: center; justify-content: center;
            font-size: 1.25rem;
        }
        .stat-icon.blue { background: #dbeafe; color: #2563eb; }
        .stat-icon.green { background: #d1fae5; color: #059669; }
        .stat-icon.orange { background: #ffedd5; color: #ea580c; }
        .stat-icon.purple { background: #ede9fe; color: #7c3aed; }
        html.dark .stat-icon.blue { background: rgba(59, 130, 246, 0.2); color: #60a5fa; }
        html.dark .stat-icon.green { background: rgba(16, 185, 129, 0.2); color: #34d399; }
        html.dark .stat-icon.orange { background: rgba(245, 158, 11, 0.2); color: #fbbf24; }
        html.dark .stat-icon.purple { background: rgba(139, 92, 246, 0.2); color: #a78bfa; }
        .stat-content h3 { margin: 0; font-size: 1.5rem; font-weight: 800; }
        .stat-content p { margin: 0; font-size: 0.85rem; color: var(--text-muted); }

        /* Filter Card */
        .filter-card { 
            background: var(--bg-surface); border: 1px solid var(--border); 
            border-radius: var(--radius-lg); padding: 1.5rem; margin-bottom: 1.5rem;
        }
        .filter-grid { 
            display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); 
            gap: 1rem; 
        }
        .form-group { margin-bottom: 0; }
        .form-label { 
            display: block; margin-bottom: 0.5rem; font-size: 0.85rem; 
            font-weight: 600; color: var(--text-muted); 
        }
        .form-control, .form-select { 
            width: 100%; padding: 0.6rem 0.75rem; border-radius: 8px;
            border: 1px solid var(--border); background: var(--bg-body);
            color: var(--text-main); font-family: inherit; font-size: 0.9rem;
        }
        .form-control:focus, .form-select:focus { 
            border-color: var(--primary); outline: none; 
        }
        .filter-actions { 
            display: flex; gap: 0.75rem; margin-top: 1rem; 
            justify-content: flex-end; 
        }
        .btn { 
            padding: 0.6rem 1.25rem; border-radius: 8px; border: none;
            font-weight: 600; font-size: 0.9rem; cursor: pointer;
            display: inline-flex; align-items: center; gap: 0.5rem;
            transition: all 0.2s;
        }
        .btn-primary { background: var(--primary); color: white; }
        .btn-primary:hover { background: var(--primary-dark); }
        .btn-secondary { 
            background: var(--bg-body); color: var(--text-main); 
            border: 1px solid var(--border); 
        }
        .btn-secondary:hover { background: var(--border); }
        .btn-success { background: var(--success); color: white; }
        .btn-success:hover { opacity: 0.9; }

        /* Table Card */
        .table-card { 
            background: var(--bg-surface); border: 1px solid var(--border); 
            border-radius: var(--radius-lg); overflow: hidden;
        }
        .table-header { 
            padding: 1rem 1.5rem; border-bottom: 1px solid var(--border);
            display: flex; justify-content: space-between; align-items: center;
        }
        .table-responsive { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 0.9rem; }
        th { 
            background: var(--bg-body); padding: 0.875rem 1rem;
            text-align: left; font-weight: 600; color: var(--text-muted);
            font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.5px;
            white-space: nowrap;
        }
        td { 
            padding: 1rem; border-bottom: 1px solid var(--border);
            vertical-align: top;
        }
        tr:hover { background: var(--bg-body); }
        tr:last-child td { border-bottom: none; }

        /* Action Badges */
        .badge { 
            display: inline-flex; align-items: center; gap: 0.25rem;
            padding: 0.35rem 0.75rem; border-radius: 99px;
            font-size: 0.75rem; font-weight: 600;
        }
        .badge-create { background: #d1fae5; color: #059669; }
        .badge-update { background: #dbeafe; color: #2563eb; }
        .badge-delete { background: #fee2e2; color: #dc2626; }
        .badge-login { background: #fef3c7; color: #d97706; }
        .badge-other { background: #f3f4f6; color: #6b7280; }
        html.dark .badge-create { background: rgba(16, 185, 129, 0.2); color: #34d399; }
        html.dark .badge-update { background: rgba(59, 130, 246, 0.2); color: #60a5fa; }
        html.dark .badge-delete { background: rgba(220, 38, 38, 0.2); color: #f87171; }
        html.dark .badge-login { background: rgba(217, 119, 6, 0.2); color: #fbbf24; }

        /* JSON Preview */
        .json-preview { 
            max-width: 200px; max-height: 60px; overflow: hidden;
            font-family: monospace; font-size: 0.75rem;
            background: var(--bg-body); padding: 0.5rem;
            border-radius: 6px; color: var(--text-muted);
            cursor: pointer; position: relative;
        }
        .json-preview::after {
            content: '...'; position: absolute; bottom: 0; right: 0;
            background: var(--bg-body); padding: 0 0.25rem;
        }
        .json-preview:hover { max-height: none; overflow: visible; }
        .json-preview:hover::after { display: none; }

        /* User Info */
        .user-cell { display: flex; align-items: center; gap: 0.75rem; }
        .user-avatar-sm { 
            width: 32px; height: 32px; border-radius: 50%;
            background: var(--primary); color: white;
            display: flex; align-items: center; justify-content: center;
            font-size: 0.75rem; font-weight: 700;
        }
        .user-details { line-height: 1.2; }
        .user-details strong { display: block; font-size: 0.9rem; }
        .user-details small { color: var(--text-muted); font-size: 0.75rem; }

        /* Pagination */
        .pagination-wrap { 
            padding: 1rem 1.5rem; border-top: 1px solid var(--border);
            display: flex; justify-content: space-between; align-items: center;
        }
        .pagination { display: flex; gap: 0.25rem; }
        .page-link { 
            padding: 0.5rem 0.75rem; border-radius: 6px;
            border: 1px solid var(--border); background: var(--bg-surface);
            color: var(--text-main); font-size: 0.85rem;
            display: flex; align-items: center; gap: 0.25rem;
        }
        .page-link:hover:not(.disabled) { background: var(--bg-body); border-color: var(--primary); }
        .page-link.active { background: var(--primary); color: white; border-color: var(--primary); }
        .page-link.disabled { opacity: 0.5; cursor: not-allowed; }

        /* Modal */
        .modal-overlay { 
            position: fixed; inset: 0; background: rgba(0,0,0,0.5);
            display: none; align-items: center; justify-content: center;
            z-index: 100; padding: 1rem;
        }
        .modal-overlay.active { display: flex; }
        .modal { 
            background: var(--bg-surface); border-radius: var(--radius-lg);
            max-width: 800px; width: 100%; max-height: 90vh;
            overflow: hidden; display: flex; flex-direction: column;
        }
        .modal-header { 
            padding: 1rem 1.5rem; border-bottom: 1px solid var(--border);
            display: flex; justify-content: space-between; align-items: center;
        }
        .modal-body { padding: 1.5rem; overflow-y: auto; }
        .modal-footer { 
            padding: 1rem 1.5rem; border-top: 1px solid var(--border);
            display: flex; justify-content: flex-end; gap: 0.75rem;
        }
        .json-block { 
            background: var(--bg-body); padding: 1rem;
            border-radius: 8px; font-family: monospace;
            font-size: 0.85rem; overflow-x: auto;
            white-space: pre-wrap; word-break: break-all;
        }

        /* Empty State */
        .empty-state { 
            text-align: center; padding: 4rem 2rem; color: var(--text-muted);
        }
        .empty-state i { font-size: 3rem; margin-bottom: 1rem; opacity: 0.5; }

        /* IP & User Agent */
        .meta-info { font-size: 0.8rem; color: var(--text-muted); }
        .meta-info i { width: 16px; text-align: center; margin-right: 0.25rem; }

        @media (max-width: 768px) {
            .filter-grid { grid-template-columns: 1fr; }
            .table-responsive { font-size: 0.8rem; }
            th, td { padding: 0.75rem 0.5rem; }
            .json-preview { max-width: 100px; }
        }
    </style>
</head>
<body>
    <header class="main-header">
        <div class="navbar">
            <a href="dashboard.php" class="logo">
                <img src="<?php echo htmlspecialchars($base_path . 'uploads/CUPAD LOGO.png'); ?>" alt="Logo">
                <span>CUPAD</span>
            </a>
            <div class="nav-right">
                <button class="icon-btn" id="themeToggle" title="Toggle Theme">
                    <i class="fas fa-moon"></i>
                </button>
                <div class="user-dropdown-wrap">
                    <div class="user-pill" id="userDropdownTrigger">
                        <?php if($has_profile_pic): ?>
                            <img src="<?php echo htmlspecialchars($profile_pic_path); ?>" class="user-avatar" alt="User">
                        <?php else: ?>
                            <div class="user-avatar-fallback"><i class="fas fa-user"></i></div>
                        <?php endif; ?>
                        <div class="user-info">
                            <span class="user-name"><?php echo htmlspecialchars($full_name); ?></span>
                            <span class="user-role"><?php echo ucfirst(htmlspecialchars($role)); ?></span>
                        </div>
                        <i class="fas fa-chevron-down" style="font-size:0.7rem; color:var(--text-muted)"></i>
                    </div>
                    <div class="dropdown-menu" id="userDropdown">
                        <div style="padding: 0.75rem 1rem; font-size: 0.75rem; color:var(--text-muted); font-weight:600">SIGNED IN AS</div>
                        <div style="padding: 0 1rem 0.5rem; font-weight:700"><?php echo htmlspecialchars($username); ?></div>
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
                <i class="fas fa-file-alt" style="color: var(--primary);"></i>
                System Audit Logs
            </h1>
            <a href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'csv'])); ?>" class="btn btn-success">
                <i class="fas fa-download"></i> Export CSV
            </a>
        </div>

        <!-- Stats -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon blue"><i class="fas fa-database"></i></div>
                <div class="stat-content">
                    <h3><?php echo number_format($stats['total_logs']); ?></h3>
                    <p>Total Logs</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon green"><i class="fas fa-calendar-day"></i></div>
                <div class="stat-content">
                    <h3><?php echo number_format($stats['today_logs']); ?></h3>
                    <p>Today's Logs</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon orange"><i class="fas fa-users"></i></div>
                <div class="stat-content">
                    <h3><?php echo number_format($stats['unique_users']); ?></h3>
                    <p>Active Users</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon purple"><i class="fas fa-table"></i></div>
                <div class="stat-content">
                    <h3><?php echo number_format($stats['unique_tables']); ?></h3>
                    <p>Tables Affected</p>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="filter-card">
            <form method="GET" class="filter-grid">
                <div class="form-group">
                    <label class="form-label">User</label>
                    <input type="text" name="user" class="form-control" placeholder="Username or ID" 
                           value="<?php echo htmlspecialchars($filter_user); ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Action</label>
                    <select name="action" class="form-select">
                        <option value="">All Actions</option>
                        <?php foreach($actions as $action): ?>
                            <option value="<?php echo htmlspecialchars($action); ?>" 
                                <?php echo $filter_action === $action ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($action); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Table</label>
                    <select name="table" class="form-select">
                        <option value="">All Tables</option>
                        <?php foreach($tables as $table): ?>
                            <option value="<?php echo htmlspecialchars($table); ?>" 
                                <?php echo $filter_table === $table ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($table); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Date From</label>
                    <input type="date" name="date_from" class="form-control" 
                           value="<?php echo htmlspecialchars($filter_date_from); ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Date To</label>
                    <input type="date" name="date_to" class="form-control" 
                           value="<?php echo htmlspecialchars($filter_date_to); ?>">
                </div>
                <div class="filter-actions" style="grid-column: 1 / -1;">
                    <a href="system_logs.php" class="btn btn-secondary">Reset</a>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-filter"></i> Apply Filters
                    </button>
                </div>
            </form>
        </div>

        <!-- Logs Table -->
        <div class="table-card">
            <div class="table-header">
                <span style="color: var(--text-muted); font-size: 0.9rem;">
                    Showing <?php echo (($page - 1) * $limit) + 1; ?> - 
                    <?php echo min($page * $limit, $total_records); ?> of 
                    <?php echo number_format($total_records); ?> records
                </span>
            </div>
            <div class="table-responsive">
                <?php if(empty($logs)): ?>
                    <div class="empty-state">
                        <i class="fas fa-inbox"></i>
                        <h3>No logs found</h3>
                        <p>Try adjusting your filters or check back later.</p>
                    </div>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>User</th>
                                <th>Action</th>
                                <th>Table</th>
                                <th>Record</th>
                                <th>Changes</th>
                                <th>IP Address</th>
                                <th>Timestamp</th>
                                <th>Details</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($logs as $log): 
                                $action_class = match(strtolower($log['action'])) {
                                    'create', 'insert' => 'badge-create',
                                    'update', 'edit', 'modify' => 'badge-update',
                                    'delete', 'remove' => 'badge-delete',
                                    'login', 'logout' => 'badge-login',
                                    default => 'badge-other'
                                };
                                $initials = strtoupper(substr($log['user_full_name'] ?? $log['username'] ?? 'S', 0, 2));
                            ?>
                                <tr>
                                    <td>#<?php echo htmlspecialchars($log['id']); ?></td>
                                    <td>
                                        <div class="user-cell">
                                            <div class="user-avatar-sm"><?php echo $initials; ?></div>
                                            <div class="user-details">
                                                <strong><?php echo htmlspecialchars($log['user_full_name'] ?? $log['username'] ?? 'System'); ?></strong>
                                                <small><?php echo htmlspecialchars($log['user_role'] ?? 'N/A'); ?></small>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge <?php echo $action_class; ?>">
                                            <i class="fas <?php echo match(strtolower($log['action'])) {
                                                'create' => 'fa-plus',
                                                'update' => 'fa-edit',
                                                'delete' => 'fa-trash',
                                                'login' => 'fa-sign-in-alt',
                                                'logout' => 'fa-sign-out-alt',
                                                default => 'fa-circle'
                                            }; ?>"></i>
                                            <?php echo htmlspecialchars($log['action']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($log['table_name'] ?? '-'); ?></td>
                                    <td><code><?php echo htmlspecialchars(substr($log['record_id'] ?? '-', 0, 20)); ?></code></td>
                                    <td>
                                        <?php if($log['old_values'] || $log['new_values']): ?>
                                            <div class="json-preview" onclick="showDetails(<?php echo htmlspecialchars(json_encode($log)); ?>)">
                                                <?php echo $log['old_values'] ? 'Old: ' . substr($log['old_values'], 0, 30) : ''; ?>
                                                <?php echo $log['new_values'] ? 'New: ' . substr($log['new_values'], 0, 30) : ''; ?>
                                            </div>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="meta-info">
                                            <i class="fas fa-network-wired"></i>
                                            <?php echo htmlspecialchars($log['ip_address'] ?? 'N/A'); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php 
                                            $date = new DateTime($log['created_at']);
                                            echo $date->format('M j, Y'); 
                                        ?><br>
                                        <small style="color: var(--text-muted);">
                                            <?php echo $date->format('g:i A'); ?>
                                        </small>
                                    </td>
                                    <td>
                                        <button class="icon-btn" onclick="showDetails(<?php echo htmlspecialchars(json_encode($log)); ?>)" 
                                                title="View Details" style="width: 32px; height: 32px;">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <?php if($total_pages > 1): ?>
                <div class="pagination-wrap">
                    <span style="color: var(--text-muted); font-size: 0.85rem;">
                        Page <?php echo $page; ?> of <?php echo $total_pages; ?>
                    </span>
                    <div class="pagination">
                        <?php 
                            $query_params = $_GET;
                            $query_params['page'] = $page - 1;
                        ?>
                        <a href="<?php echo $page > 1 ? '?' . http_build_query($query_params) : '#'; ?>" 
                           class="page-link <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                            <i class="fas fa-chevron-left"></i> Prev
                        </a>
                        
                        <?php 
                            $start = max(1, $page - 2);
                            $end = min($total_pages, $page + 2);
                            for($i = $start; $i <= $end; $i++): 
                                $query_params['page'] = $i;
                        ?>
                            <a href="?<?php echo http_build_query($query_params); ?>" 
                               class="page-link <?php echo $i === $page ? 'active' : ''; ?>">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>
                        
                        <?php $query_params['page'] = $page + 1; ?>
                        <a href="<?php echo $page < $total_pages ? '?' . http_build_query($query_params) : '#'; ?>" 
                           class="page-link <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                            Next <i class="fas fa-chevron-right"></i>
                        </a>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <!-- Detail Modal -->
    <div class="modal-overlay" id="detailModal">
        <div class="modal">
            <div class="modal-header">
                <h3 style="margin: 0;"><i class="fas fa-info-circle" style="color: var(--primary);"></i> Log Details</h3>
                <button class="icon-btn" onclick="closeModal()" style="width: 32px; height: 32px;">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body" id="modalBody">
                <!-- Content injected via JS -->
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeModal()">Close</button>
            </div>
        </div>
    </div>

    <script>
        // User dropdown
        const userTrigger = document.getElementById('userDropdownTrigger');
        const userDropdown = document.getElementById('userDropdown');
        userTrigger.addEventListener('click', (e) => { 
            e.stopPropagation(); 
            userDropdown.classList.toggle('show'); 
        });
        document.addEventListener('click', (e) => { 
            if (!userTrigger.contains(e.target) && !userDropdown.contains(e.target)) 
                userDropdown.classList.remove('show'); 
        });

        // Theme toggle
        document.getElementById('themeToggle').addEventListener('click', () => {
            const html = document.documentElement;
            const isDark = html.classList.toggle('dark');
            html.classList.toggle('light');
            localStorage.setItem('theme', isDark ? 'dark' : 'light');
            document.querySelector('#themeToggle i').className = isDark ? 'fas fa-sun' : 'fas fa-moon';
        });

        // Load saved theme
        if(localStorage.getItem('theme') === 'dark') {
            document.documentElement.classList.add('dark');
            document.documentElement.classList.remove('light');
            document.querySelector('#themeToggle i').className = 'fas fa-sun';
        }

        // Modal functions
        function showDetails(log) {
            const modal = document.getElementById('detailModal');
            const body = document.getElementById('modalBody');
            
            const oldValues = log.old_values ? JSON.stringify(JSON.parse(log.old_values), null, 2) : 'None';
            const newValues = log.new_values ? JSON.stringify(JSON.parse(log.new_values), null, 2) : 'None';
            
            body.innerHTML = `
                <div style="margin-bottom: 1.5rem;">
                    <label class="form-label">Action Information</label>
                    <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 1rem; font-size: 0.9rem;">
                        <div><strong>ID:</strong> #${log.id}</div>
                        <div><strong>Action:</strong> ${log.action}</div>
                        <div><strong>Table:</strong> ${log.table_name || '-'}</div>
                        <div><strong>Record ID:</strong> ${log.record_id || '-'}</div>
                        <div><strong>User:</strong> ${log.user_full_name || log.username || 'System'}</div>
                        <div><strong>Timestamp:</strong> ${new Date(log.created_at).toLocaleString()}</div>
                        <div><strong>IP Address:</strong> ${log.ip_address || 'N/A'}</div>
                        <div><strong>User Agent:</strong> ${log.user_agent ? log.user_agent.substring(0, 50) + '...' : 'N/A'}</div>
                    </div>
                </div>
                
                <div style="margin-bottom: 1.5rem;">
                    <label class="form-label">Old Values</label>
                    <div class="json-block">${oldValues}</div>
                </div>
                
                <div>
                    <label class="form-label">New Values</label>
                    <div class="json-block">${newValues}</div>
                </div>
            `;
            
            modal.classList.add('active');
        }

        function closeModal() {
            document.getElementById('detailModal').classList.remove('active');
        }

        // Close modal on overlay click
        document.getElementById('detailModal').addEventListener('click', (e) => {
            if(e.target === e.currentTarget) closeModal();
        });

        // Close modal on Escape key
        document.addEventListener('keydown', (e) => {
            if(e.key === 'Escape') closeModal();
        });
    </script>
</body>
</html>