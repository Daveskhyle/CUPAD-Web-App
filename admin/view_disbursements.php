<?php
// admin/view_disbursements.php
session_start();
require_once '../includes/config.php';
$pdo = getDbConnection();

// --- AUTH CHECK ---
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: ../index.php');
    exit();
}

$base_path = '../';

// Helper to extract image from notes
function extractImage($notes) {
    if (preg_match('/\|\s*Image:\s*(.+)$/i', $notes, $matches)) {
        return trim($matches[1]);
    }
    return null;
}

// --- AJAX DATA FETCHING ENDPOINT ---
if (isset($_GET['ajax_fetch'])) {
    header('Content-Type: application/json');
    
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $limit = isset($_GET['limit']) ? max(1, (int)$_GET['limit']) : 25; 
    $offset = ($page - 1) * $limit;

    // Added LEFT JOIN on clients to fetch phone and address for the modal
    $base_sql = "FROM disbursements d
                 LEFT JOIN clients c ON d.client_id = c.id
                 LEFT JOIN branches b ON d.branch_id = b.id
                 LEFT JOIN areas a ON b.area_id = a.id
                 LEFT JOIN zones z ON b.zone_id = z.id
                 WHERE 1=1";
    $params =[];

    // Filter Logic
    if (!empty($_GET['zone_id'])) {
        $base_sql .= " AND z.id = ?";
        $params[] = $_GET['zone_id'];
    }
    if (!empty($_GET['area_id'])) {
        $base_sql .= " AND a.id = ?";
        $params[] = $_GET['area_id'];
    }
    if (!empty($_GET['branch_id'])) {
        $base_sql .= " AND d.branch_id = ?";
        $params[] = $_GET['branch_id'];
    }
    if (!empty($_GET['search'])) {
        $base_sql .= " AND (d.client_name LIKE ? OR d.officer LIKE ? OR d.id LIKE ?)";
        $search_param = "%" . trim($_GET['search']) . "%";
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
    }
    if (!empty($_GET['start_date'])) {
        $base_sql .= " AND DATE(d.date) >= ?";
        $params[] = $_GET['start_date'];
    }
    if (!empty($_GET['end_date'])) {
        $base_sql .= " AND DATE(d.date) <= ?";
        $params[] = $_GET['end_date'];
    }

    // 1. Get Total Records Count
    $count_stmt = $pdo->prepare("SELECT COUNT(*) " . $base_sql);
    $count_stmt->execute($params);
    $total_records = $count_stmt->fetchColumn();
    $total_pages = ceil($total_records / $limit);

    // 2. Fetch Data with Pagination
    $data_sql = "SELECT d.*, c.phone as client_phone, c.address as client_address, 
                 b.name as branch_name, a.name as area_name, z.name as zone_name " 
              . $base_sql 
              . " ORDER BY d.date DESC LIMIT " . (int)$limit . " OFFSET " . (int)$offset;
    
    $data_stmt = $pdo->prepare($data_sql);
    $data_stmt->execute($params);
    $records = $data_stmt->fetchAll(PDO::FETCH_ASSOC);

    // 3. Format Data for the Frontend (Table & Modal)
    foreach ($records as &$rec) {
        $clean_notes = $rec['notes'] ?? '';
        $image = extractImage($clean_notes);
        
        if ($image) {
            $clean_notes = preg_replace('/\|\s*Image:\s*.+$/i', '', $clean_notes);
        }

        $rec['has_image'] = $image && file_exists($base_path . $image);
        $rec['image_url'] = $rec['has_image'] ? htmlspecialchars($base_path . $image) : null;
        $rec['clean_notes'] = nl2br(htmlspecialchars(trim($clean_notes) ?: 'No additional notes provided.'));
        
        // General Safety
        $rec['client_name_safe'] = htmlspecialchars($rec['client_name']);
        $rec['client_id_safe'] = htmlspecialchars($rec['client_id']);
        $rec['client_phone_safe'] = htmlspecialchars($rec['client_phone'] ?? 'N/A');
        $rec['branch_safe'] = htmlspecialchars($rec['branch_name'] ?? 'Unassigned');
        $rec['area_safe'] = htmlspecialchars($rec['area_name'] ?? '-');
        $rec['zone_safe'] = htmlspecialchars($rec['zone_name'] ?? '-');
        $rec['officer_safe'] = htmlspecialchars($rec['officer']);
        
        // Financials
        $rec['principal_formatted'] = number_format((float)$rec['principal'], 2);
        $rec['total_payable_fmt'] = number_format((float)$rec['total_payable'], 2);
        $rec['remaining_balance_fmt'] = number_format((float)$rec['remaining_balance'], 2);
        
        $rec['status_safe'] = htmlspecialchars($rec['status']);
        $rec['status_class'] = strtolower($rec['status']) === 'active' ? 'active' : 'completed';
        
        // Dates
        $rec['date_fmt'] = date('d M Y', strtotime($rec['date']));
        $rec['time_fmt'] = date('h:i A', strtotime($rec['date']));
        $rec['payoff_date_fmt'] = date('d M Y', strtotime($rec['payoff_date']));
    }

    echo json_encode([
        'records' => $records,
        'total_records' => $total_records,
        'total_pages' => $total_pages,
        'current_page' => $page
    ]);
    exit();
}

// --- USER PROFILE FETCH (Dashboard Match) ---
$full_name = $_SESSION['full_name'] ?? 'Admin';
$username = $_SESSION['username'] ?? '';
$role = $_SESSION['role'] ?? '';

$my_pic = 'default_avatar.png';
try {
    $stmt = $pdo->prepare("SELECT profile_pic FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user_data = $stmt->fetch();
    if ($user_data && !empty($user_data['profile_pic'])) {
        $my_pic = $user_data['profile_pic'];
    }
} catch (Exception $e) { }

$profile_pic_path = $base_path . 'uploads/' . $my_pic;
$has_profile_pic = file_exists($profile_pic_path) && $my_pic !== 'default_avatar.png';

// --- FETCH HIERARCHY DATA FOR FILTERS INITIALIZATION ---
$zones = $pdo->query("SELECT * FROM zones ORDER BY name ASC")->fetchAll();
$areas = $pdo->query("SELECT id, name, zone_id FROM areas ORDER BY name ASC")->fetchAll();
$branches = $pdo->query("SELECT id, name, area_id, zone_id FROM branches ORDER BY name ASC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en" class="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Disbursements - CUPAD</title>
    <link rel="icon" type="image/png" href="<?php echo htmlspecialchars($base_path . 'uploads/CUPAD LOGO.png'); ?>">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />
    <style>
        :root { 
            --primary: #2563eb; --primary-dark: #1d4ed8; 
            --success: #059669; --warning: #d97706; --danger: #dc2626; --info: #0284c7; 
            --bg-body: #f1f5f9; --bg-surface: #ffffff; 
            --text-main: #0f172a; --text-muted: #64748b; 
            --border: #e2e8f0; --radius-lg: 16px; --radius-md: 10px; 
            --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05); --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1); 
            --nav-height: 70px; 
        }
        html.dark { 
            --bg-body: #0f172a; --bg-surface: #1e293b; 
            --text-main: #f8fafc; --text-muted: #94a3b8; 
            --border: #334155; --primary: #3b82f6; 
            --success: #34d399; --warning: #fbbf24; --danger: #f87171; --info: #38bdf8; 
        }
        
        * { box-sizing: border-box; outline: none; }
        body { font-family: 'Plus Jakarta Sans', sans-serif; background: var(--bg-body); color: var(--text-main); margin: 0; padding-top: var(--nav-height); transition: background-color 0.3s, color 0.3s; }
        a { text-decoration: none; color: inherit; }
        
        /* Header */
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

        /* Page Layout */
        .container { max-width: 1400px; margin: 0 auto; padding: 2rem 1.5rem 4rem; }
        .page-header { margin-bottom: 2rem; display: flex; justify-content: space-between; align-items: flex-end; flex-wrap: wrap; gap: 1rem; }
        .page-title { font-size: 1.5rem; font-weight: 800; margin: 0; display: flex; align-items: center; gap: 0.5rem; color: var(--text-main); }
        
        .card { background: var(--bg-surface); border: 1px solid var(--border); border-radius: var(--radius-lg); padding: 1.5rem; box-shadow: var(--shadow-sm); margin-bottom: 1.5rem; }

        /* Filters */
        .filter-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem; align-items: end; }
        .form-group { display: flex; flex-direction: column; gap: 0.4rem; }
        .form-label { font-size: 0.8rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; }
        .form-control { width: 100%; padding: 0.7rem 1rem; border-radius: 8px; border: 1px solid var(--border); background: var(--bg-body); color: var(--text-main); font-family: inherit; font-size: 0.9rem; transition: 0.2s; }
        .form-control:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(37,99,235,0.1); }
        
        .btn { padding: 0.7rem 1.2rem; border-radius: 8px; font-weight: 600; cursor: pointer; transition: 0.2s; font-size: 0.9rem; border: 1px solid transparent; display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem; }
        .btn-primary { background: var(--primary); color: white; }
        .btn-primary:hover { background: var(--primary-dark); }
        .btn-outline { background: transparent; border-color: var(--border); color: var(--text-main); }
        .btn-outline:hover { background: var(--bg-body); border-color: var(--text-muted); }

        /* Table */
        .table-responsive { overflow-x: auto; border-radius: var(--radius-md); }
        table { width: 100%; border-collapse: collapse; min-width: 1000px; }
        th { background: var(--bg-body); color: var(--text-muted); font-size: 0.8rem; font-weight: 700; text-transform: uppercase; padding: 1rem; text-align: left; white-space: nowrap; border-bottom: 1px solid var(--border); }
        td { padding: 1rem; border-bottom: 1px solid var(--border); color: var(--text-main); font-size: 0.9rem; vertical-align: middle; }
        tr:last-child td { border-bottom: none; }
        tr:hover { background: var(--bg-body); }

        .client-info { display: flex; flex-direction: column; }
        .client-name { font-weight: 700; color: var(--primary); font-size: 0.95rem; }
        .meta-text { font-size: 0.75rem; color: var(--text-muted); margin-top: 2px; }

        .location-info { display: flex; flex-direction: column; gap: 2px; }
        .loc-branch { font-weight: 600; }
        
        /* Thumbnails */
        .thumb-box { width: 45px; height: 45px; border-radius: 8px; background: var(--bg-body); border: 1px solid var(--border); display: flex; align-items: center; justify-content: center; overflow: hidden; cursor: pointer; transition: transform 0.2s; }
        .thumb-box img { width: 100%; height: 100%; object-fit: cover; }
        .thumb-box:hover { transform: scale(1.1); box-shadow: var(--shadow-md); z-index: 10; position: relative; }
        .no-thumb { color: var(--text-muted); font-size: 1.2rem; opacity: 0.5; }

        .badge { padding: 0.2rem 0.6rem; border-radius: 99px; font-size: 0.7rem; font-weight: 700; text-transform: uppercase; display: inline-block; }
        .badge.active { background: rgba(5, 150, 105, 0.1); color: var(--success); }
        .badge.completed { background: rgba(37, 99, 235, 0.1); color: var(--primary); }

        .empty-state { padding: 4rem 2rem; text-align: center; color: var(--text-muted); }
        .empty-state i { font-size: 3rem; margin-bottom: 1rem; opacity: 0.3; }

        /* Pagination Control */
        .pagination-container { display: flex; justify-content: space-between; align-items: center; padding: 1.5rem; border-top: 1px solid var(--border); background: var(--bg-surface); }
        .pagination { display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap; }
        .pagination button { padding: 0.5rem 0.85rem; border: 1px solid var(--border); background: var(--bg-surface); color: var(--text-main); border-radius: 6px; cursor: pointer; font-weight: 600; font-size: 0.85rem; transition: 0.2s; }
        .pagination button:hover:not(:disabled) { border-color: var(--primary); color: var(--primary); }
        .pagination button.active { background: var(--primary); color: white; border-color: var(--primary); }
        .pagination button:disabled { opacity: 0.5; cursor: not-allowed; }
        .pagination .dots { color: var(--text-muted); font-weight: 600; padding: 0 0.5rem; }

        /* Full Image Modal */
        #imgModal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.85); backdrop-filter: blur(4px); z-index: 3000; align-items: center; justify-content: center; padding: 2rem; animation: fadeIn 0.2s ease; }
        #imgModal img { max-width: 100%; max-height: 90vh; border-radius: var(--radius-md); box-shadow: 0 10px 25px rgba(0,0,0,0.5); }
        .close-img-modal { position: absolute; top: 20px; right: 30px; font-size: 2rem; color: white; cursor: pointer; transition: 0.2s; }
        .close-img-modal:hover { color: var(--danger); transform: scale(1.1); }

        /* Details Popup Modal */
        .modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.6); backdrop-filter: blur(4px); z-index: 2000; align-items: center; justify-content: center; padding: 1.5rem; animation: fadeIn 0.2s ease; }
        .modal-container { background: var(--bg-body); width: 100%; max-width: 1100px; max-height: 90vh; border-radius: var(--radius-lg); display: flex; flex-direction: column; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25); overflow: hidden; animation: scaleIn 0.2s ease; }
        .modal-header { padding: 1.5rem 2rem; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; background: var(--bg-surface); }
        .modal-body { padding: 2rem; overflow-y: auto; flex: 1; }
        
        .modal-title { font-size: 1.25rem; font-weight: 800; display: flex; align-items: center; gap: 0.5rem; color: var(--text-main); margin: 0; }
        .close-modal-btn { background: none; border: none; font-size: 1.5rem; color: var(--text-muted); cursor: pointer; transition: 0.2s; display: flex; align-items: center; justify-content: center; width: 40px; height: 40px; border-radius: 50%; }
        .close-modal-btn:hover { background: var(--bg-body); color: var(--danger); }

        /* Popup Inner Grid matches disbursement_details.php */
        .detail-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; }
        @media(max-width: 900px) { .detail-grid { grid-template-columns: 1fr; } }
        
        .modal-card { background: var(--bg-surface); border: 1px solid var(--border); border-radius: var(--radius-md); padding: 1.5rem; display: flex; flex-direction: column; }
        .modal-card-header { margin-bottom: 1.25rem; border-bottom: 1px solid var(--border); padding-bottom: 1rem; display: flex; justify-content: space-between; align-items: center; }
        .modal-card-title { font-size: 1.05rem; font-weight: 700; color: var(--text-main); display: flex; align-items: center; gap: 0.5rem; }

        .data-list { list-style: none; padding: 0; margin: 0; }
        .data-item { display: flex; justify-content: space-between; align-items: center; padding: 0.75rem 0; border-bottom: 1px dashed var(--border); }
        .data-item:last-child { border-bottom: none; padding-bottom: 0; }
        .data-label { color: var(--text-muted); font-size: 0.9rem; font-weight: 500; display: flex; align-items: center; gap: 0.5rem; }
        .data-value { color: var(--text-main); font-weight: 700; font-size: 0.95rem; text-align: right; }
        
        .highlight-text { font-size: 1.2rem; color: var(--primary); font-weight: 800; }

        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }

        /* Print Media Query */
        @media print {
            body > *:not(#detailsModal) { display: none !important; }
            #detailsModal { display: block !important; position: static; background: white; padding: 0; }
            .modal-container { box-shadow: none; max-width: 100%; max-height: none; overflow: visible; border: none; }
            .modal-header .close-modal-btn, .modal-header .btn-outline { display: none !important; }
            .modal-body { padding: 0; }
        }
    </style>
</head>
<body>

    <!-- Header -->
    <header class="main-header">
        <div class="navbar">
            <a href="dashboard.php" class="logo">
                <img src="<?php echo htmlspecialchars($base_path . 'uploads/CUPAD LOGO.png'); ?>" alt="Logo">
                <span>CUPAD</span>
            </a>
            <div class="nav-right">
                <a href="dashboard.php" class="icon-btn" title="Back to Dashboard"><i class="fas fa-home"></i></a>
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
            <div>
                <h1 class="page-title"><i class="fas fa-hand-holding-usd" style="color:var(--primary)"></i> Disbursement Directory</h1>
                <p style="margin: 0.5rem 0 0; color: var(--text-muted);">View, search, and filter all disbursed loans across branches.</p>
            </div>
            <div class="meta-text" style="font-size: 0.9rem; padding: 0.5rem 1rem; background: var(--bg-surface); border: 1px solid var(--border); border-radius: var(--radius-md);">
                Total Found: <b id="totalRecordsCounter">0</b>
            </div>
        </div>

        <!-- Filter Card -->
        <div class="card">
            <form id="filterForm" class="filter-grid">
                <div class="form-group">
                    <label class="form-label">Zone</label>
                    <select name="zone_id" id="filter_zone" class="form-control" onchange="filterHierarchies()">
                        <option value="">All Zones</option>
                        <?php foreach ($zones as $z): ?>
                            <option value="<?php echo $z['id']; ?>"><?php echo htmlspecialchars($z['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Area</label>
                    <select name="area_id" id="filter_area" class="form-control" onchange="filterHierarchies()">
                        <option value="">All Areas</option>
                        <?php foreach ($areas as $a): ?>
                            <option value="<?php echo $a['id']; ?>" data-zone="<?php echo $a['zone_id']; ?>"><?php echo htmlspecialchars($a['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Branch</label>
                    <select name="branch_id" id="filter_branch" class="form-control">
                        <option value="">All Branches</option>
                        <?php foreach ($branches as $b): ?>
                            <option value="<?php echo $b['id']; ?>" data-area="<?php echo $b['area_id']; ?>" data-zone="<?php echo $b['zone_id']; ?>"><?php echo htmlspecialchars($b['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Search</label>
                    <input type="text" name="search" id="filter_search" class="form-control" placeholder="Client, ID, Officer...">
                </div>
                <div class="form-group">
                    <label class="form-label">Start Date</label>
                    <input type="date" name="start_date" id="filter_start" class="form-control">
                </div>
                <div class="form-group">
                    <label class="form-label">End Date</label>
                    <input type="date" name="end_date" id="filter_end" class="form-control">
                </div>
                <div class="form-group" style="flex-direction: row; gap: 0.5rem;">
                    <button type="submit" class="btn btn-primary" style="flex: 1;"><i class="fas fa-filter"></i> Apply</button>
                    <button type="button" class="btn btn-outline" title="Reset Filters" onclick="resetFilters()"><i class="fas fa-sync-alt"></i></button>
                </div>
            </form>
        </div>

        <!-- Data Table -->
        <div class="card" style="padding: 0; overflow: hidden;">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th style="width: 60px; text-align: center;">Proof</th>
                            <th>Client Details</th>
                            <th>Location (Branch/Area/Zone)</th>
                            <th>Credit Officer</th>
                            <th>Amount / Status</th>
                            <th>Date & Time</th>
                            <th style="text-align: right;">Action</th>
                        </tr>
                    </thead>
                    <tbody id="tableBody">
                        <!-- AJAX content injected here -->
                    </tbody>
                </table>
            </div>
            
            <!-- Pagination Section -->
            <div class="pagination-container" id="paginationContainer">
                <span class="meta-text" id="pageInfoText" style="font-size: 0.85rem;"></span>
                <div class="pagination" id="paginationControls"></div>
            </div>
        </div>
    </main>

    <!-- Disbursement Details Modal Overlay -->
    <div id="detailsModal" class="modal-overlay" onclick="handleModalOverlayClick(event)">
        <div class="modal-container">
            <div class="modal-header">
                <div>
                    <h2 class="modal-title"><i class="fas fa-file-invoice-dollar" style="color:var(--primary)"></i> Disbursement Record</h2>
                    <p style="margin:0; font-size:0.85rem; color:var(--text-muted); font-family:monospace;">TXN ID: <span id="mdl_txn_id"></span></p>
                </div>
                <div style="display:flex; gap:1rem; align-items:center;">
                    <button onclick="window.print()" class="btn btn-outline" style="padding: 0.4rem 0.8rem; font-size:0.85rem;">
                        <i class="fas fa-print"></i> Print
                    </button>
                    <button class="close-modal-btn" onclick="closeDetailsModal()"><i class="fas fa-times"></i></button>
                </div>
            </div>
            
            <div class="modal-body">
                <div class="detail-grid">
                    
                    <!-- Left Column: Details -->
                    <div style="display: flex; flex-direction: column; gap: 1.5rem;">
                        
                        <!-- Financial Overview -->
                        <div class="modal-card">
                            <div class="modal-card-header">
                                <div class="modal-card-title"><i class="fas fa-money-check-alt text-muted"></i> Financial Details</div>
                                <span id="mdl_status" class="badge"></span>
                            </div>
                            <ul class="data-list">
                                <li class="data-item">
                                    <span class="data-label"><i class="fas fa-hand-holding-usd"></i> Principal Amount</span>
                                    <span class="data-value highlight-text" id="mdl_principal"></span>
                                </li>
                                <li class="data-item">
                                    <span class="data-label"><i class="fas fa-percentage"></i> Interest Rate</span>
                                    <span class="data-value" id="mdl_interest"></span>
                                </li>
                                <li class="data-item">
                                    <span class="data-label"><i class="fas fa-equals"></i> Total Payable</span>
                                    <span class="data-value" id="mdl_total"></span>
                                </li>
                                <li class="data-item">
                                    <span class="data-label"><i class="fas fa-balance-scale-left" style="color:var(--warning)"></i> Remaining Balance</span>
                                    <span class="data-value" style="color:var(--danger)" id="mdl_balance"></span>
                                </li>
                            </ul>
                        </div>

                        <!-- Term & Dates -->
                        <div class="modal-card">
                            <div class="modal-card-header">
                                <div class="modal-card-title"><i class="fas fa-calendar-alt text-muted"></i> Schedule & Terms</div>
                            </div>
                            <ul class="data-list">
                                <li class="data-item">
                                    <span class="data-label">Loan Plan</span>
                                    <span class="data-value" id="mdl_plan"></span>
                                </li>
                                <li class="data-item">
                                    <span class="data-label">Installments</span>
                                    <span class="data-value" id="mdl_installments"></span>
                                </li>
                                <li class="data-item">
                                    <span class="data-label">Disbursement Date</span>
                                    <span class="data-value" id="mdl_disb_date"></span>
                                </li>
                                <li class="data-item">
                                    <span class="data-label">Expected Payoff Date</span>
                                    <span class="data-value" style="color:var(--info)" id="mdl_payoff_date"></span>
                                </li>
                            </ul>
                        </div>

                        <!-- Entity Information -->
                        <div class="modal-card">
                            <div class="modal-card-header">
                                <div class="modal-card-title"><i class="fas fa-users text-muted"></i> Entity Information</div>
                            </div>
                            <ul class="data-list">
                                <li class="data-item">
                                    <span class="data-label"><i class="fas fa-user text-muted"></i> Client Name</span>
                                    <span class="data-value" id="mdl_client_name"></span>
                                </li>
                                <li class="data-item">
                                    <span class="data-label"><i class="fas fa-phone text-muted"></i> Phone</span>
                                    <span class="data-value" id="mdl_client_phone"></span>
                                </li>
                                <li class="data-item">
                                    <span class="data-label"><i class="fas fa-user-tie text-muted"></i> Credit Officer</span>
                                    <span class="data-value" id="mdl_officer"></span>
                                </li>
                                <li class="data-item">
                                    <span class="data-label"><i class="fas fa-building text-muted"></i> Branch</span>
                                    <span class="data-value" id="mdl_branch"></span>
                                </li>
                            </ul>
                        </div>

                    </div>

                    <!-- Right Column: Proof Image -->
                    <div style="display: flex; flex-direction: column; gap: 1.5rem;">
                        <div class="modal-card" style="flex: 1;">
                            <div class="modal-card-header">
                                <div class="modal-card-title"><i class="fas fa-camera text-muted"></i> Proof of Disbursement</div>
                                <a href="#" id="mdl_btn_download" download class="btn btn-outline" style="padding: 0.3rem 0.6rem; font-size:0.8rem;">
                                    <i class="fas fa-download"></i> Save
                                </a>
                            </div>
                            
                            <div id="mdl_image_container" style="width: 100%; min-height: 250px; background: var(--bg-body); border-radius: var(--radius-md); border: 1px dashed var(--border); display: flex; align-items: center; justify-content: center; overflow: hidden;">
                                <!-- Image injected via JS -->
                            </div>

                            <div style="margin-top: 1.5rem;">
                                <span class="data-label" style="margin-bottom: 0.5rem;"><i class="fas fa-sticky-note"></i> System Notes</span>
                                <div id="mdl_notes" style="padding: 1rem; background: var(--bg-body); border-radius: var(--radius-md); font-size: 0.9rem; color: var(--text-main); border: 1px solid var(--border);">
                                    <!-- Notes injected via JS -->
                                </div>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </div>

    <!-- Full Image Modal (Zoom) -->
    <div id="imgModal" onclick="handleImgModalClick(event)">
        <span class="close-img-modal" onclick="closeImage()">&times;</span>
        <img id="modalImg" src="" alt="Full Image">
    </div>

    <script>
        // Store currently loaded data globally for the modal
        let currentRecords =[];

        // --- Header Dropdown Logic ---
        const userTrigger = document.getElementById('userDropdownTrigger');
        const userDropdown = document.getElementById('userDropdown');

        if (userTrigger && userDropdown) {
            userTrigger.addEventListener('click', (e) => { 
                e.stopPropagation(); 
                userDropdown.classList.toggle('show'); 
            });
            document.addEventListener('click', (e) => { 
                if (!userTrigger.contains(e.target) && !userDropdown.contains(e.target)) {
                    userDropdown.classList.remove('show'); 
                }
            });
        }

        // --- Theme Toggle Logic ---
        const themeToggle = document.getElementById('themeToggle');
        const themeIcon = themeToggle.querySelector('i');
        const html = document.documentElement;

        function updateThemeIcon() {
            themeIcon.className = html.classList.contains('dark') ? 'fas fa-sun' : 'fas fa-moon';
        }

        themeToggle.addEventListener('click', () => {
            const isDark = html.classList.toggle('dark');
            html.classList.toggle('light');
            localStorage.setItem('theme', isDark ? 'dark' : 'light');
            updateThemeIcon();
        });

        if (localStorage.getItem('theme') === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            html.classList.add('dark'); html.classList.remove('light');
        }
        updateThemeIcon();

        // --- Cascading Dropdowns Logic (Zone -> Area -> Branch) ---
        function filterHierarchies() {
            const zoneId = document.getElementById('filter_zone').value;
            const areaSelect = document.getElementById('filter_area');
            const branchSelect = document.getElementById('filter_branch');

            // Filter Areas
            Array.from(areaSelect.options).forEach(opt => {
                if (opt.value === "") return;
                opt.style.display = (zoneId === "" || opt.dataset.zone === zoneId) ? "block" : "none";
            });
            if (areaSelect.selectedOptions[0] && areaSelect.selectedOptions[0].style.display === "none") {
                areaSelect.value = "";
            }

            // Filter Branches
            Array.from(branchSelect.options).forEach(opt => {
                if (opt.value === "") return;
                const matchZone = (zoneId === "" || opt.dataset.zone === zoneId);
                const matchArea = (areaSelect.value === "" || opt.dataset.area === areaSelect.value);
                opt.style.display = (matchZone && matchArea) ? "block" : "none";
            });
            if (branchSelect.selectedOptions[0] && branchSelect.selectedOptions[0].style.display === "none") {
                branchSelect.value = "";
            }
        }
        document.addEventListener("DOMContentLoaded", filterHierarchies);

        // --- AJAX DATA FETCHING ---
        const filterForm = document.getElementById('filterForm');
        
        filterForm.addEventListener('submit', function(e) {
            e.preventDefault();
            fetchData(1);
        });

        function resetFilters() {
            filterForm.reset();
            filterHierarchies();
            fetchData(1);
        }

        async function fetchData(page = 1) {
            const tbody = document.getElementById('tableBody');
            
            // Show loading spinner
            tbody.innerHTML = `<tr><td colspan="7" style="text-align:center; padding:4rem;">
                <i class="fas fa-circle-notch fa-spin fa-2x" style="color:var(--primary); margin-bottom: 1rem;"></i>
                <h3 style="margin:0; font-size:1rem; color:var(--text-muted);">Loading records...</h3>
            </td></tr>`;

            const formData = new FormData(filterForm);
            const params = new URLSearchParams(formData);
            params.append('ajax_fetch', '1');
            params.append('page', page);
            params.append('limit', 25);

            try {
                const res = await fetch('?' + params.toString());
                const data = await res.json();
                
                // Store fetched records globally
                currentRecords = data.records;

                document.getElementById('totalRecordsCounter').innerText = parseInt(data.total_records).toLocaleString();
                renderTable(data.records);
                renderPagination(data.current_page, data.total_pages);

            } catch (err) {
                tbody.innerHTML = `<tr><td colspan="7">
                    <div class="empty-state" style="color:var(--danger)">
                        <i class="fas fa-exclamation-triangle"></i>
                        <h2>Connection Error</h2>
                        <p>Failed to load data. Please check your network and try again.</p>
                    </div>
                </td></tr>`;
                document.getElementById('paginationControls').innerHTML = '';
                document.getElementById('pageInfoText').innerText = '';
            }
        }

        function renderTable(records) {
            const tbody = document.getElementById('tableBody');
            if (records.length === 0) {
                tbody.innerHTML = `<tr><td colspan="7">
                    <div class="empty-state">
                        <i class="fas fa-box-open"></i>
                        <h2>No disbursements found</h2>
                        <p>Try adjusting your search or filter parameters to find what you're looking for.</p>
                    </div>
                </td></tr>`;
                return;
            }

            let html = '';
            records.forEach(d => {
                let imageHtml = d.has_image 
                    ? `<img src="${d.image_url}" alt="Proof">` 
                    : `<i class="fas fa-image no-thumb"></i>`;
                
                let imgOnClick = d.has_image ? `onclick="openImage('${d.image_url}')" title="Click to enlarge"` : '';

                html += `
                    <tr>
                        <td style="text-align: center;">
                            <div class="thumb-box" style="margin: 0 auto;" ${imgOnClick}>
                                ${imageHtml}
                            </div>
                        </td>
                        <td>
                            <div class="client-info">
                                <span class="client-name">${d.client_name_safe}</span>
                                <span class="meta-text"><i class="fas fa-id-badge" style="opacity:0.7"></i> ID: ${d.client_id_safe}</span>
                            </div>
                        </td>
                        <td>
                            <div class="location-info">
                                <span class="loc-branch"><i class="fas fa-building text-muted"></i> ${d.branch_safe}</span>
                                <span class="meta-text">${d.area_safe} &bull; ${d.zone_safe}</span>
                            </div>
                        </td>
                        <td>
                            <span style="font-weight: 600; color: var(--text-main);"><i class="fas fa-user-tie text-muted" style="margin-right: 4px;"></i>${d.officer_safe}</span>
                        </td>
                        <td>
                            <div class="client-info">
                                <span style="font-weight: 800; font-size: 1.05rem; color: var(--text-main);">₦${d.principal_formatted}</span>
                                <span style="margin-top: 4px;">
                                    <span class="badge ${d.status_class}">
                                        ${d.status_safe}
                                    </span>
                                </span>
                            </div>
                        </td>
                        <td>
                            <div class="client-info">
                                <span style="font-weight: 600; color: var(--text-main);"><i class="far fa-calendar-alt text-muted" style="margin-right: 4px;"></i>${d.date_fmt}</span>
                                <span class="meta-text"><i class="far fa-clock text-muted" style="margin-right: 4px;"></i>${d.time_fmt}</span>
                            </div>
                        </td>
                        <td style="text-align: right;">
                            <button onclick="openDetailsModal('${d.id}')" class="btn btn-outline" style="padding: 0.4rem 0.8rem; font-size: 0.8rem;">
                                <i class="fas fa-eye"></i> View Details
                            </button>
                        </td>
                    </tr>
                `;
            });
            tbody.innerHTML = html;
        }

        function renderPagination(currentPage, totalPages) {
            const container = document.getElementById('paginationControls');
            const pageInfo = document.getElementById('pageInfoText');
            
            if (totalPages <= 0) {
                container.innerHTML = '';
                pageInfo.innerText = '';
                return;
            }

            pageInfo.innerText = `Page ${currentPage} of ${totalPages}`;
            let html = '';

            // Previous Button
            if (currentPage > 1) {
                html += `<button onclick="fetchData(${currentPage - 1})"><i class="fas fa-chevron-left"></i> Prev</button>`;
            } else {
                html += `<button disabled><i class="fas fa-chevron-left"></i> Prev</button>`;
            }

            // Windowing Logic for Pages (show 2 around current)
            let startPage = Math.max(1, currentPage - 2);
            let endPage = Math.min(totalPages, currentPage + 2);

            if (startPage > 1) {
                html += `<button onclick="fetchData(1)">1</button>`;
                if (startPage > 2) html += `<span class="dots">...</span>`;
            }

            for (let i = startPage; i <= endPage; i++) {
                if (i === currentPage) {
                    html += `<button class="active">${i}</button>`;
                } else {
                    html += `<button onclick="fetchData(${i})">${i}</button>`;
                }
            }

            if (endPage < totalPages) {
                if (endPage < totalPages - 1) html += `<span class="dots">...</span>`;
                html += `<button onclick="fetchData(${totalPages})">${totalPages}</button>`;
            }

            // Next Button
            if (currentPage < totalPages) {
                html += `<button onclick="fetchData(${currentPage + 1})">Next <i class="fas fa-chevron-right"></i></button>`;
            } else {
                html += `<button disabled>Next <i class="fas fa-chevron-right"></i></button>`;
            }

            container.innerHTML = html;
        }

        // --- Disbursement Details Modal Logic ---
        function openDetailsModal(id) {
            const record = currentRecords.find(r => r.id == id);
            if (!record) return;

            // Populate Text Fields
            document.getElementById('mdl_txn_id').innerText = record.id;
            
            const badge = document.getElementById('mdl_status');
            badge.innerText = record.status_safe;
            badge.className = `badge ${record.status_class}`;

            document.getElementById('mdl_principal').innerText = '₦' + record.principal_formatted;
            document.getElementById('mdl_interest').innerText = record.interest_rate + '%';
            document.getElementById('mdl_total').innerText = '₦' + record.total_payable_fmt;
            document.getElementById('mdl_balance').innerText = '₦' + record.remaining_balance_fmt;

            document.getElementById('mdl_plan').innerText = record.loan_term_type || 'Standard';
            document.getElementById('mdl_installments').innerText = record.num_installments + ' Payments';
            document.getElementById('mdl_disb_date').innerText = record.date_fmt + ', ' + record.time_fmt;
            document.getElementById('mdl_payoff_date').innerText = record.payoff_date_fmt;

            document.getElementById('mdl_client_name').innerText = record.client_name_safe;
            document.getElementById('mdl_client_phone').innerText = record.client_phone_safe;
            document.getElementById('mdl_officer').innerText = record.officer_safe;
            document.getElementById('mdl_branch').innerText = record.branch_safe;

            // Handle Image
            const imgContainer = document.getElementById('mdl_image_container');
            const downloadBtn = document.getElementById('mdl_btn_download');

            if (record.has_image) {
                imgContainer.innerHTML = `<img src="${record.image_url}" alt="Disbursement Proof" style="max-width:100%; max-height:400px; object-fit:contain; cursor:zoom-in;" onclick="openImage('${record.image_url}')">`;
                downloadBtn.href = record.image_url;
                downloadBtn.download = `Proof_${record.id}`;
                downloadBtn.style.display = 'inline-flex';
            } else {
                imgContainer.innerHTML = `<div style="text-align:center; color:var(--text-muted); padding: 3rem 0;">
                    <i class="fas fa-image" style="font-size:3rem; opacity:0.3; margin-bottom:1rem; display:block;"></i>
                    <span>No proof image attached to this record.</span>
                </div>`;
                downloadBtn.style.display = 'none';
            }

            // Handle Notes
            document.getElementById('mdl_notes').innerHTML = record.clean_notes;

            // Show Modal
            document.getElementById('detailsModal').style.display = 'flex';
        }

        function closeDetailsModal() {
            document.getElementById('detailsModal').style.display = 'none';
        }

        function handleModalOverlayClick(e) {
            // Close if clicking outside the modal content
            if (e.target.id === 'detailsModal') {
                closeDetailsModal();
            }
        }

        // --- Simple Image Zoom Modal Logic ---
        const imgModal = document.getElementById('imgModal');
        const modalImg = document.getElementById('modalImg');

        function openImage(src) {
            modalImg.src = src;
            imgModal.style.display = 'flex';
        }

        function closeImage() {
            imgModal.style.display = 'none';
        }

        function handleImgModalClick(e) {
            if (e.target === imgModal) {
                closeImage();
            }
        }

        // Initialize fetch on page load
        document.addEventListener('DOMContentLoaded', () => {
            fetchData(1);
        });
    </script>
</body>
</html>