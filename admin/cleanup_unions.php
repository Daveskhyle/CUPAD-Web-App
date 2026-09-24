<?php
session_start();
date_default_timezone_set('Africa/Lagos');

// --- 1. DATABASE CONNECTION ---
// ====================================
$db_host = 'localhost';
$db_name = 'cupad_db'; // UPDATE THIS
$db_user = 'root';     // UPDATE THIS
$db_pass = '';         // UPDATE THIS

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Database Connection Failed: " . $e->getMessage());
}

// --- 2. SECURITY & INITIALIZATION ---
// ====================================
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: ../login.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$base_path = '../';
// User Info from Session (Assumes login script sets these)
$full_name = $_SESSION['full_name'] ?? 'Admin User';
$username = $_SESSION['username'] ?? 'Admin';
$user_id = $_SESSION['user_id'] ?? 1; // Fallback to 1 if not set

// Feedback Variables
$toast_message = '';
$toast_type = '';

// --- 3. HANDLE DELETION LOGIC ---
// ====================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // 1. Verify CSRF
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $toast_message = "Security Token Error.";
        $toast_type = "error";
    } else {
        $action_type = '';
        $rows_affected = 0;

        try {
            $pdo->beginTransaction();

            // CASE A: Delete Single Union
            if (isset($_POST['union_to_delete'])) {
                $target = $_POST['union_to_delete'];
                
                // Update clients table: Set union to NULL where union matches target
                $stmt = $pdo->prepare("UPDATE clients SET `union` = NULL WHERE `union` = ?");
                $stmt->execute([$target]);
                $rows_affected = $stmt->rowCount();
                
                // Optionally update unions table status
                $pdo->prepare("UPDATE unions SET status = 'inactive' WHERE name = ?")->execute([$target]);

                $action_type = "Deleted union '$target'";
            }
            // CASE B: Bulk Delete (Checkboxes)
            elseif (isset($_POST['bulk_unions_json'])) {
                $targets = json_decode($_POST['bulk_unions_json'], true);
                if (is_array($targets) && count($targets) > 0) {
                    // Create placeholders for IN clause
                    $placeholders = implode(',', array_fill(0, count($targets), '?'));
                    
                    $stmt = $pdo->prepare("UPDATE clients SET `union` = NULL WHERE `union` IN ($placeholders)");
                    $stmt->execute($targets);
                    $rows_affected = $stmt->rowCount();
                    
                    // Mark unions as inactive
                    $stmtUnion = $pdo->prepare("UPDATE unions SET status = 'inactive' WHERE name IN ($placeholders)");
                    $stmtUnion->execute($targets);

                    $action_type = "Deleted " . count($targets) . " unions";
                }
            }
            // CASE C: Global Wipe (Delete All)
            elseif (isset($_POST['delete_all_global']) && $_POST['delete_all_global'] === 'true') {
                $stmt = $pdo->query("UPDATE clients SET `union` = NULL WHERE `union` IS NOT NULL AND `union` != ''");
                $rows_affected = $stmt->rowCount();
                $action_type = "Wiped ALL unions";
            }
            // CASE D: Delete Single Member from Union
            elseif (isset($_POST['remove_member_id']) && isset($_POST['remove_member_union'])) {
                $clientId = $_POST['remove_member_id'];
                $targetUnion = $_POST['remove_member_union'];
                
                $stmt = $pdo->prepare("UPDATE clients SET `union` = NULL WHERE id = ? AND `union` = ?");
                $stmt->execute([$clientId, $targetUnion]);
                $rows_affected = $stmt->rowCount();
                
                // Get client name for the toast message
                $nameStmt = $pdo->prepare("SELECT name FROM clients WHERE id = ?");
                $nameStmt->execute([$clientId]);
                $clientName = $nameStmt->fetchColumn() ?: 'Client';

                $action_type = "Removed '$clientName' from '$targetUnion'";
            }

            // Commit and Log
            if ($rows_affected > 0) {
                // Log to audit_log
                $auditSql = "INSERT INTO audit_log (user_id, username, action, table_name, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)";
                $details = json_encode(['description' => $action_type, 'rows_affected' => $rows_affected]);
                $pdo->prepare($auditSql)->execute([$user_id, $username, 'DELETE_UNION_ASSIGNMENT', 'clients', $details, $_SERVER['REMOTE_ADDR']]);

                $pdo->commit();
                $toast_message = "Success! $action_type.";
                $toast_type = "success";
            } else {
                $pdo->rollBack(); // No changes needed
                $toast_message = "No records were updated (records may not exist).";
                $toast_type = "info";
            }

        } catch (Exception $e) {
            $pdo->rollBack();
            $toast_message = "Database Error: " . $e->getMessage();
            $toast_type = "error";
        }
    }
}

// --- 4. DATA PREPARATION ---
// =========================================

$unions_details = []; 
$total_clients_in_unions = 0;

try {
    // Optimized Query: Get Clients + Savings Balance + Active Loan Balance
    // We group by client here, then group by union in PHP
    $sql = "
        SELECT 
            c.id, 
            c.name, 
            c.union, 
            COALESCE(sb.balance, 0.00) AS savings_balance,
            (
                SELECT SUM(d.remaining_balance) 
                FROM disbursements d 
                WHERE d.client_id = c.id 
                AND d.status IN ('active', 'restructured', 'defaulted')
                AND d.remaining_balance > 0.01
            ) AS loan_balance
        FROM clients c
        LEFT JOIN saving_balances sb ON c.id = sb.client_id
        WHERE c.union IS NOT NULL AND c.union != ''
        ORDER BY c.union ASC, c.name ASC
    ";

    $stmt = $pdo->query($sql);
    $results = $stmt->fetchAll();

    foreach ($results as $row) {
        $u_name = $row['union'];
        
        if (!isset($unions_details[$u_name])) {
            $unions_details[$u_name] = [];
        }
        
        $unions_details[$u_name][] = [
            'id' => $row['id'],
            'display_id' => $row['id'],
            'name' => $row['name'],
            'savings' => (float)$row['savings_balance'],
            'loan' => (float)$row['loan_balance']
        ];
        $total_clients_in_unions++;
    }

} catch (PDOException $e) {
    $toast_message = "Error loading data: " . $e->getMessage();
    $toast_type = "error";
}

// Sort Unions by size (largest first)
uasort($unions_details, function($a, $b) {
    return count($b) - count($a);
});

// Convert to array for JS
$js_unions = [];
foreach($unions_details as $name => $members) {
    $js_unions[] = [
        'name' => $name,
        'count' => count($members),
        'members' => $members
    ];
}

// Profile Pic Logic (Fetch from DB users table)
$profile_pic_path = '';
$has_profile_pic = false;
try {
    $stmt = $pdo->prepare("SELECT profile_pic FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $pic = $stmt->fetchColumn();

    if ($pic && $pic !== 'default_avatar.png') {
        // Checking if path is relative or absolute in your setup
        $check_path = $base_path . $pic; 
        // Or sometimes stored as 'uploads/...'
        if (!file_exists($check_path) && file_exists($base_path . 'uploads/' . $pic)) {
             $check_path = $base_path . 'uploads/' . $pic;
        }

        if (file_exists($check_path)) {
            $profile_pic_path = $check_path;
            $has_profile_pic = true;
        }
    }
} catch (Exception $e) { /* Ignore profile pic error */ }
?>
<!DOCTYPE html>
<html lang="en" class="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cleanup Unions - Admin</title>
    <link rel="icon" type="image/png" href="<?php echo htmlspecialchars($base_path . 'uploads/CUPAD LOGO.png'); ?>">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />

    <style>
        :root {
            --primary: #2563eb; --primary-dark: #1d4ed8;
            --success: #059669; --warning: #d97706; --danger: #dc2626;
            --bg-body: #f1f5f9; --bg-surface: #ffffff;
            --text-main: #0f172a; --text-muted: #64748b;
            --border: #e2e8f0; --radius-lg: 16px; --radius-md: 10px;
            --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
            --nav-height: 70px;
        }

        html.dark {
            --bg-body: #0f172a; --bg-surface: #1e293b;
            --text-main: #f8fafc; --text-muted: #94a3b8;
            --border: #334155; --primary: #3b82f6; --danger: #f87171;
        }

        * { box-sizing: border-box; outline: none; }
        body { font-family: 'Plus Jakarta Sans', sans-serif; background: var(--bg-body); color: var(--text-main); margin: 0; padding-top: var(--nav-height); }
        a { text-decoration: none; color: inherit; }

        /* HEADER */
        .main-header { position: fixed; top: 0; left: 0; right: 0; height: var(--nav-height); background: rgba(255,255,255,0.85); backdrop-filter: blur(12px); border-bottom: 1px solid var(--border); z-index: 50; }
        html.dark .main-header { background: rgba(15, 23, 42, 0.85); }
        .navbar { max-width: 1200px; margin: 0 auto; padding: 0 1.5rem; display: flex; align-items: center; justify-content: space-between; height: 100%; }
        .logo { display: flex; align-items: center; gap: 0.75rem; font-weight: 800; font-size: 1.35rem; color: var(--primary); }
        .logo img { height: 38px; }
        .nav-right { display: flex; align-items: center; gap: 1rem; }
        .icon-btn { width: 36px; height: 36px; border-radius: 50%; border: none; background: transparent; color: var(--text-muted); cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; }
        
        .user-pill { display: flex; align-items: center; gap: 0.75rem; padding: 4px 8px 4px 4px; border: 1px solid var(--border); border-radius: 99px; background: var(--bg-surface); cursor: pointer; }
        .user-avatar { width: 34px; height: 34px; border-radius: 50%; object-fit: cover; }
        .dropdown-menu { position: absolute; top: 125%; right: 0; background: var(--bg-surface); border: 1px solid var(--border); border-radius: var(--radius-md); box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1); min-width: 200px; display: none; flex-direction: column; z-index: 100; }
        .dropdown-menu.show { display: flex; }
        .dropdown-item { padding: 0.75rem 1rem; display: flex; align-items: center; gap: 0.75rem; font-size: 0.9rem; transition: 0.2s; }
        .dropdown-item:hover { background: var(--bg-body); color: var(--primary); }

        /* LAYOUT */
        .container { max-width: 1100px; margin: 0 auto; padding: 2rem 1.5rem 6rem; }
        .back-link { display: inline-flex; align-items: center; gap: 0.5rem; color: var(--text-muted); font-weight: 500; font-size: 0.9rem; margin-bottom: 0.5rem; }
        
        /* STATS */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 2rem; }
        .stat-card { background: var(--bg-surface); padding: 1.25rem; border-radius: var(--radius-lg); border: 1px solid var(--border); display: flex; align-items: center; gap: 1rem; }
        .stat-icon { width: 48px; height: 48px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; flex-shrink: 0; }
        .bg-purple { background: #ede9fe; color: #7c3aed; } html.dark .bg-purple { background: rgba(139, 92, 246, 0.2); color: #a78bfa; }
        .bg-orange { background: #ffedd5; color: #ea580c; } html.dark .bg-orange { background: rgba(245, 158, 11, 0.2); color: #fbbf24; }

        /* TOOLBAR */
        .card { background: var(--bg-surface); border: 1px solid var(--border); border-radius: var(--radius-lg); overflow: hidden; }
        .controls-toolbar { display: flex; justify-content: space-between; gap: 1rem; padding: 1.5rem; flex-wrap: wrap; align-items: center; border-bottom: 1px solid var(--border); }
        .search-wrap { position: relative; flex: 1; max-width: 350px; }
        .search-wrap input { width: 100%; padding: 0.7rem 1rem 0.7rem 2.4rem; border-radius: 99px; border: 1px solid var(--border); background: var(--bg-surface); color: var(--text-main); }
        .search-wrap i { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: var(--text-muted); }
        
        .btn-action { padding: 0.6rem 1.2rem; border-radius: 99px; border: 1px solid var(--border); background: var(--bg-surface); color: var(--text-main); cursor: pointer; display: flex; align-items: center; gap: 0.5rem; font-weight: 600; font-size: 0.9rem; transition: 0.2s; }
        .btn-action:hover { border-color: var(--primary); color: var(--primary); }
        .btn-danger-outline { color: var(--danger); border-color: rgba(220, 38, 38, 0.3); }
        .btn-danger-outline:hover { background: var(--danger); color: white; border-color: var(--danger); }

        /* TABLE */
        .alert-box { background: rgba(245, 158, 11, 0.1); border-left: 4px solid var(--warning); color: var(--warning); padding: 1rem; margin: 1.5rem 1.5rem 0; border-radius: 4px; font-size: 0.9rem; }
        
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; padding: 1rem 1.5rem; background: var(--bg-body); color: var(--text-muted); font-size: 0.75rem; text-transform: uppercase; font-weight: 700; border-bottom: 1px solid var(--border); }
        td { padding: 1rem 1.5rem; border-bottom: 1px solid var(--border); font-size: 0.9rem; }
        tr:hover td { background: var(--bg-body); }
        
        .btn-icon-small { width: 32px; height: 32px; border-radius: 6px; border: 1px solid var(--border); background: var(--bg-surface); color: var(--text-muted); cursor: pointer; display: flex; align-items: center; justify-content: center; transition: 0.2s; }
        .btn-icon-small:hover { border-color: var(--primary); color: var(--primary); }
        .btn-icon-small.delete:hover { border-color: var(--danger); color: var(--danger); background: rgba(220,38,38,0.1); }
        .actions-cell { display: flex; gap: 0.5rem; justify-content: flex-end; }
        input[type="checkbox"] { width: 16px; height: 16px; cursor: pointer; accent-color: var(--primary); }
        mark { background: rgba(255, 215, 0, 0.4); color: inherit; padding: 0 2px; border-radius: 2px; }

        /* FLOATING BAR */
        .floating-bar { position: fixed; bottom: 30px; left: 50%; transform: translateX(-50%) translateY(100px); background: var(--text-main); color: var(--bg-surface); padding: 1rem 2rem; border-radius: 99px; box-shadow: 0 10px 25px rgba(0,0,0,0.2); display: flex; align-items: center; gap: 2rem; z-index: 200; transition: transform 0.3s; }
        html.dark .floating-bar { background: #fff; color: #0f172a; }
        .floating-bar.visible { transform: translateX(-50%) translateY(0); }
        .fb-btn { background: var(--danger); color: white; border: none; padding: 0.5rem 1.5rem; border-radius: 20px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 0.5rem; }

        /* MODAL */
        .modal-overlay { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 300; display: none; align-items: center; justify-content: center; backdrop-filter: blur(4px); }
        .modal { background: var(--bg-surface); width: 95%; max-width: 600px; border-radius: var(--radius-lg); padding: 0; max-height: 85vh; display: flex; flex-direction: column; overflow: hidden; box-shadow: 0 20px 50px rgba(0,0,0,0.2); }
        .modal-header { display: flex; justify-content: space-between; align-items: center; padding: 1.5rem; border-bottom: 1px solid var(--border); background: var(--bg-body); }
        .modal-body { overflow-y: auto; flex: 1; padding: 1rem 1.5rem; }
        
        /* Updated Member List Styling to match Loan Manager */
        .client-list { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 0.75rem; }
        .member-card { 
            padding: 1rem; 
            border: 1px solid var(--border); 
            border-radius: 12px; 
            background: var(--bg-surface);
            display: grid; 
            grid-template-columns: 1.5fr 1fr 1fr 40px;
            align-items: center; 
            gap: 1rem;
            transition: 0.2s;
        }
        .member-card:hover { border-color: var(--primary); box-shadow: var(--shadow-sm); }

        .member-info { display: flex; align-items: center; gap: 10px; }
        .avatar-initials { 
            width: 38px; height: 38px; border-radius: 50%; 
            background: #dbeafe; color: var(--primary); 
            display: flex; align-items: center; justify-content: center; 
            font-weight: 700; font-size: 0.9rem; flex-shrink: 0;
        }
        html.dark .avatar-initials { background: rgba(37,99,235,0.2); }
        
        .financial-col { display: flex; flex-direction: column; font-size: 0.9rem; }
        .financial-label { font-size: 0.7rem; text-transform: uppercase; color: var(--text-muted); font-weight: 600; }
        .financial-val { font-weight: 600; color: var(--text-main); }
        .financial-val.debt { color: var(--danger); }
        .financial-val.savings { color: var(--success); }

        .btn-remove-member {
            background: transparent;
            border: 1px solid var(--border);
            color: var(--text-muted);
            width: 34px; height: 34px;
            border-radius: 8px;
            cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            transition: 0.2s;
        }
        .btn-remove-member:hover {
            color: var(--danger);
            border-color: var(--danger);
            background: rgba(220, 38, 38, 0.1);
        }

        /* Mobile Responsive Modal */
        @media (max-width: 500px) {
            .member-card { grid-template-columns: 1fr 40px; row-gap: 0.5rem; }
            .financial-col { flex-direction: row; gap: 0.5rem; align-items: center; font-size: 0.8rem; grid-column: 1 / -1; justify-content: space-between; background: var(--bg-body); padding: 0.5rem; border-radius: 6px; }
            .member-info { grid-column: 1 / 2; }
            .btn-remove-member { grid-column: 2 / 3; }
        }

        /* TOAST */
        .toast-container { position: fixed; top: 90px; right: 20px; z-index: 400; display: flex; flex-direction: column; gap: 10px; }
        .toast { background: var(--bg-surface); color: var(--text-main); padding: 1rem; border-radius: var(--radius-md); box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); border-left: 4px solid var(--primary); min-width: 300px; animation: slideIn 0.3s forwards; display: flex; align-items: center; gap: 0.75rem; }
        .toast.success { border-left-color: var(--success); } .toast.error { border-left-color: var(--danger); }
        @keyframes slideIn { from { opacity: 0; transform: translateX(50px); } to { opacity: 1; transform: translateX(0); } }

        /* PAGINATION */
        .pagination { display: flex; justify-content: flex-end; align-items: center; padding: 1rem 1.5rem; gap: 0.5rem; border-top: 1px solid var(--border); }
        .page-btn { width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; border: 1px solid var(--border); background: var(--bg-surface); border-radius: 6px; cursor: pointer; color: var(--text-muted); }
        .page-btn.active { background: var(--primary); color: white; border-color: var(--primary); }
    </style>
</head>
<body>

    <!-- HEADER -->
    <header class="main-header">
        <div class="navbar">
            <a href="dashboard.php" class="logo">
                <img src="<?php echo htmlspecialchars($base_path . 'uploads/CUPAD LOGO.png'); ?>" alt="Logo">
                <span>CUPAD</span>
            </a>
            <div class="nav-right">
                <button class="icon-btn" id="themeToggle"><i class="fas fa-moon"></i></button>
                <div class="user-dropdown-wrap">
                    <div class="user-pill" id="userDropdownTrigger">
                        <?php if($has_profile_pic): ?>
                            <img src="<?php echo htmlspecialchars($profile_pic_path); ?>" class="user-avatar" alt="User">
                        <?php else: ?>
                            <div class="user-avatar" style="background:var(--bg-body);display:flex;align-items:center;justify-content:center"><i class="fas fa-user"></i></div>
                        <?php endif; ?>
                        <i class="fas fa-chevron-down" style="font-size:0.7rem; color:var(--text-muted); margin-left:0.5rem"></i>
                    </div>
                    <div class="dropdown-menu" id="userDropdown">
                        <a href="dashboard.php" class="dropdown-item"><i class="fas fa-home"></i> Dashboard</a>
                        <a href="../logout.php" class="dropdown-item" style="color:var(--danger)"><i class="fas fa-sign-out-alt"></i> Logout</a>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <div id="toastContainer" class="toast-container"></div>

    <main class="container">
        <a href="dashboard.php" class="back-link"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
        <h1 style="margin:0; font-size:1.8rem; font-weight:800;">Cleanup Client Unions</h1>
        <p style="color:var(--text-muted); margin-top:0.5rem">Review member financials before removing them from unions.</p>

        <!-- STATS -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon bg-purple"><i class="fas fa-layer-group"></i></div>
                <div>
                    <h4 style="margin:0;font-size:1.5rem"><?php echo count($unions_details); ?></h4>
                    <span style="font-size:0.8rem;color:var(--text-muted);text-transform:uppercase;font-weight:600">Active Unions</span>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon bg-orange"><i class="fas fa-users"></i></div>
                <div>
                    <h4 style="margin:0;font-size:1.5rem"><?php echo $total_clients_in_unions; ?></h4>
                    <span style="font-size:0.8rem;color:var(--text-muted);text-transform:uppercase;font-weight:600">Clients Affected</span>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="alert-box">
                <i class="fas fa-exclamation-triangle"></i> 
                <strong>Warning:</strong> Deletion removes the union from associated clients. Actions are logged in the audit trail.
            </div>

            <!-- TOOLBAR -->
            <div class="controls-toolbar">
                <div class="search-wrap">
                    <i class="fas fa-search"></i>
                    <input type="text" id="searchInput" placeholder="Search unions..." onkeyup="handleSearch()">
                </div>
                <div style="display:flex; gap:0.5rem">
                    <button class="btn-action" onclick="exportCSV()"><i class="fas fa-file-csv"></i> Export CSV</button>
                    <!-- GLOBAL DELETE BUTTON -->
                    <button class="btn-action btn-danger-outline" onclick="confirmGlobalWipe()">
                        <i class="fas fa-bomb"></i> Delete ALL Unions
                    </button>
                </div>
            </div>

            <!-- TABLE -->
            <div style="overflow-x: auto;">
                <table id="unionTable">
                    <thead>
                        <tr>
                            <th style="width:40px; text-align:center"><input type="checkbox" id="selectAll" onclick="toggleSelectAll()"></th>
                            <th>Union Name</th>
                            <th>Members</th>
                            <th style="text-align:right">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="tableBody">
                        <!-- JS Rendered -->
                    </tbody>
                </table>
            </div>

            <!-- PAGINATION -->
            <div class="pagination" id="paginationControls"></div>
        </div>
    </main>

    <!-- FLOATING ACTION BAR -->
    <div class="floating-bar" id="floatingBar">
        <span class="fb-count" id="selectedCount">0 selected</span>
        <button class="fb-btn" onclick="confirmBulkDelete()">
            <i class="fas fa-trash-alt"></i> Delete Selected
        </button>
    </div>

    <!-- CLIENT MODAL -->
    <div class="modal-overlay" id="clientModal" onclick="if(event.target===this)closeModal()">
        <div class="modal">
            <div class="modal-header">
                <div>
                    <div class="modal-title" id="modalTitle" style="font-weight:700; font-size:1.2rem">Members</div>
                    <div style="font-size:0.85rem; color:var(--text-muted)">Review financials before removal</div>
                </div>
                <button onclick="closeModal()" style="background:none;border:none;font-size:1.5rem;cursor:pointer;color:var(--text-muted)">&times;</button>
            </div>
            <div class="modal-body">
                <ul class="client-list" id="modalList"></ul>
            </div>
        </div>
    </div>

    <!-- HIDDEN FORMS -->
    
    <!-- 1. Single Delete Form -->
    <form id="singleDeleteForm" method="POST" style="display:none">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
        <input type="hidden" name="union_to_delete" id="singleUnionInput">
    </form>

    <!-- 2. Bulk Delete Form -->
    <form id="bulkDeleteForm" method="POST" style="display:none">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
        <input type="hidden" name="bulk_unions_json" id="bulkUnionsInput">
    </form>

    <!-- 3. Global Delete Form -->
    <form id="globalDeleteForm" method="POST" style="display:none">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
        <input type="hidden" name="delete_all_global" value="true">
    </form>
    
    <!-- 4. Single Member Delete Form -->
    <form id="memberDeleteForm" method="POST" style="display:none">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
        <input type="hidden" name="remove_member_id" id="removeMemberId">
        <input type="hidden" name="remove_member_union" id="removeMemberUnion">
    </form>

    <script>
        // --- DATA ---
        const unionsData = <?php echo json_encode($js_unions); ?>;
        let currentPage = 1;
        const rowsPerPage = 10;
        let filteredData = [...unionsData];
        let selectedUnions = new Set();
        let searchTerm = '';

        // Formatter for Naira
        const formatter = new Intl.NumberFormat('en-NG', { style: 'currency', currency: 'NGN', minimumFractionDigits: 0 });

        // --- RENDER ---
        function renderTable() {
            const tbody = document.getElementById('tableBody');
            tbody.innerHTML = '';
            const start = (currentPage - 1) * rowsPerPage;
            const end = start + rowsPerPage;
            const pageData = filteredData.slice(start, end);

            if(pageData.length === 0) {
                tbody.innerHTML = `<tr><td colspan="4" style="text-align:center;padding:3rem;color:var(--text-muted)">No unions found.</td></tr>`;
                return;
            }

            pageData.forEach(union => {
                const isSelected = selectedUnions.has(union.name);
                const nameHtml = searchTerm ? union.name.replace(new RegExp(searchTerm, 'gi'), match => `<mark>${match}</mark>`) : union.name;

                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td style="text-align:center">
                        <input type="checkbox" value="${union.name}" ${isSelected ? 'checked' : ''} onchange="toggleRow('${union.name}')">
                    </td>
                    <td style="font-weight:600;color:var(--primary)">${nameHtml}</td>
                    <td><span style="background:var(--bg-body);padding:2px 8px;border-radius:12px;font-size:0.8rem;font-weight:600">${union.count}</span></td>
                    <td class="actions-cell">
                        <button class="btn-icon-small" onclick='openModal("${union.name.replace(/'/g, "\\'")}")' title="View & Edit Members"><i class="fas fa-users-cog"></i></button>
                        <button class="btn-icon-small delete" onclick='confirmSingleDelete("${union.name.replace(/'/g, "\\'")}")' title="Delete This Union"><i class="fas fa-trash-alt"></i></button>
                    </td>
                `;
                tbody.appendChild(tr);
            });
            updatePagination();
            updateSelectAllState();
        }

        // --- SEARCH ---
        function handleSearch() {
            searchTerm = document.getElementById('searchInput').value.trim();
            filteredData = searchTerm === '' 
                ? [...unionsData] 
                : unionsData.filter(u => u.name.toLowerCase().includes(searchTerm.toLowerCase()));
            currentPage = 1;
            renderTable();
        }

        // --- PAGINATION ---
        function updatePagination() {
            const container = document.getElementById('paginationControls');
            const totalPages = Math.ceil(filteredData.length / rowsPerPage);
            container.innerHTML = '';
            if(totalPages <= 1) return;

            const createBtn = (i, icon) => {
                const btn = document.createElement('button');
                btn.className = `page-btn ${i === currentPage ? 'active' : ''}`;
                btn.innerHTML = icon || i;
                btn.onclick = () => { currentPage = i; renderTable(); };
                container.appendChild(btn);
            };

            createBtn(currentPage > 1 ? currentPage - 1 : 1, '<i class="fas fa-chevron-left"></i>');
            for(let i=1; i<=totalPages; i++) {
                if(i===1 || i===totalPages || (i>=currentPage-1 && i<=currentPage+1)) createBtn(i);
                else if (container.lastChild.innerText !== '...') {
                    const span = document.createElement('span');
                    span.innerText = '...'; span.style.padding = '0 5px';
                    container.appendChild(span);
                }
            }
            createBtn(currentPage < totalPages ? currentPage + 1 : totalPages, '<i class="fas fa-chevron-right"></i>');
        }

        // --- SELECTION ---
        function toggleRow(name) {
            if(selectedUnions.has(name)) selectedUnions.delete(name); else selectedUnions.add(name);
            updateFloatingBar(); updateSelectAllState();
        }
        function toggleSelectAll() {
            const checked = document.getElementById('selectAll').checked;
            filteredData.slice((currentPage-1)*rowsPerPage, currentPage*rowsPerPage).forEach(u => {
                checked ? selectedUnions.add(u.name) : selectedUnions.delete(u.name);
            });
            renderTable(); updateFloatingBar();
        }
        function updateSelectAllState() {
            const pageData = filteredData.slice((currentPage-1)*rowsPerPage, currentPage*rowsPerPage);
            if(pageData.length === 0) return;
            document.getElementById('selectAll').checked = pageData.every(u => selectedUnions.has(u.name));
        }
        function updateFloatingBar() {
            const bar = document.getElementById('floatingBar');
            document.getElementById('selectedCount').innerText = `${selectedUnions.size} selected`;
            selectedUnions.size > 0 ? bar.classList.add('visible') : bar.classList.remove('visible');
        }

        // --- ACTIONS ---
        function confirmSingleDelete(name) {
            if(confirm(`Delete the union "${name}"?\nIt will be removed from all associated clients.`)) {
                document.getElementById('singleUnionInput').value = name;
                document.getElementById('singleDeleteForm').submit();
            }
        }
        function confirmBulkDelete() {
            const count = selectedUnions.size;
            if(confirm(`Delete ${count} selected unions?\nThey will be removed from all associated clients.`)) {
                document.getElementById('bulkUnionsInput').value = JSON.stringify(Array.from(selectedUnions));
                document.getElementById('bulkDeleteForm').submit();
            }
        }
        function confirmGlobalWipe() {
            const confirmation = prompt("⚠️ DANGER: GLOBAL WIPE\n\nThis will remove EVERY union assignment from EVERY client in the system.\n\nType 'DELETE ALL' to confirm:");
            if(confirmation === 'DELETE ALL') {
                document.getElementById('globalDeleteForm').submit();
            }
        }
        function confirmMemberDelete(clientId, unionName, clientName) {
            if(confirm(`Remove "${clientName}" from the union "${unionName}"?`)) {
                document.getElementById('removeMemberId').value = clientId;
                document.getElementById('removeMemberUnion').value = unionName;
                document.getElementById('memberDeleteForm').submit();
            }
        }

        // --- MODAL WITH FINANCIALS ---
        function openModal(name) {
            const union = unionsData.find(u => u.name === name);
            if(!union) return;
            document.getElementById('modalTitle').innerText = `${union.name} Members`;
            const list = document.getElementById('modalList');
            list.innerHTML = '';
            
            union.members.forEach(m => {
                const safeName = m.name.replace(/'/g, "\\'");
                const safeUnion = name.replace(/'/g, "\\'");
                const initials = m.name.charAt(0);
                
                // Format financials
                const loanHtml = m.loan > 0 
                    ? `<span class="financial-val debt">${formatter.format(m.loan)}</span>`
                    : `<span class="financial-val" style="color:var(--text-muted); font-weight:400">No active loan</span>`;
                    
                const savHtml = `<span class="financial-val savings">${formatter.format(m.savings)}</span>`;

                list.innerHTML += `
                    <li class="member-card">
                        <div class="member-info">
                            <div class="avatar-initials">${initials}</div>
                            <div style="font-weight:600; font-size:0.95rem; line-height:1.2">
                                ${m.name}
                                <div style="font-size:0.75rem; color:var(--text-muted); font-weight:400">Member</div>
                            </div>
                        </div>
                        
                        <div class="financial-col">
                            <span class="financial-label">Loan Balance</span>
                            ${loanHtml}
                        </div>
                        
                        <div class="financial-col">
                            <span class="financial-label">Savings</span>
                            ${savHtml}
                        </div>
                        
                        <button class="btn-remove-member" onclick="confirmMemberDelete('${m.id}', '${safeUnion}', '${safeName}')" title="Remove from Union">
                            <i class="fas fa-trash-alt" style="font-size:0.9rem"></i>
                        </button>
                    </li>`;
            });
            document.getElementById('clientModal').style.display = 'flex';
        }
        function closeModal() { document.getElementById('clientModal').style.display = 'none'; }
        
        function exportCSV() {
            let csv = "Union Name,Member Count\n";
            filteredData.forEach(row => csv += `"${row.name}",${row.count}\n`);
            const link = document.createElement("a");
            link.href = 'data:text/csv;charset=utf-8,' + encodeURI(csv);
            link.download = "unions_list.csv";
            link.click();
        }

        // --- INIT ---
        function showToast(msg, type) {
            const div = document.createElement('div');
            div.className = `toast ${type}`;
            div.innerHTML = `<i class="fas fa-${type === 'success' ? 'check-circle' : 'info-circle'}"></i> ${msg}`;
            document.getElementById('toastContainer').appendChild(div);
            setTimeout(() => div.remove(), 4000);
        }
        <?php if ($toast_message): ?> showToast("<?php echo addslashes($toast_message); ?>", "<?php echo $toast_type; ?>"); <?php endif; ?>

        document.getElementById('themeToggle').onclick = () => {
            const isDark = document.documentElement.classList.toggle('dark');
            localStorage.setItem('theme', isDark ? 'dark' : 'light');
            document.getElementById('themeToggle').innerHTML = isDark ? '<i class="fas fa-sun"></i>' : '<i class="fas fa-moon"></i>';
        };
        if(localStorage.getItem('theme') === 'dark') { document.documentElement.classList.add('dark'); document.getElementById('themeToggle').innerHTML = '<i class="fas fa-sun"></i>'; }
        
        document.getElementById('userDropdownTrigger').onclick = (e) => { e.stopPropagation(); document.getElementById('userDropdown').classList.toggle('show'); };
        document.onclick = () => document.getElementById('userDropdown').classList.remove('show');

        renderTable();
    </script>
</body>
</html>