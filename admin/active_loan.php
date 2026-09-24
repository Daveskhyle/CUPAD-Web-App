<?php
session_start();
date_default_timezone_set('Africa/Lagos');

// --- DATABASE CONFIGURATION ---
define('DB_HOST', 'localhost');
define('DB_NAME', 'cupadnam_db');
define('DB_USER', 'cupadnam_db');
define('DB_PASS', 'f2GrjZQCz8E39nCu9eLg');
define('DB_CHARSET', 'utf8mb4');

// --- DATABASE CONNECTION ---
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Database Connection Failed: " . $e->getMessage());
}

// --- 1. AUTHENTICATION CHECK ---
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: ../index.php');
    exit();
}

$base_path = '../';

// --- 2. AJAX API HANDLERS ---
if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    // --- FETCH ACTIVE LOANS ---
    if ($_GET['action'] === 'get_active_loans') {
        try {
            // Get active loans from disbursements table matching schema
            $stmt = $pdo->prepare("
                SELECT d.*, c.name as client_name, c.photo as profile_pic
                FROM disbursements d
                LEFT JOIN clients c ON d.client_id = c.id
                WHERE d.status = 'active' 
                OR (d.remaining_balance > 0.01 AND d.status != 'completed')
                ORDER BY d.date DESC
            ");
            $stmt->execute();
            $loans = $stmt->fetchAll();
            
            // Add index for reference and format data
            foreach ($loans as $idx => &$loan) {
                $loan['index'] = $idx;
                $loan['remaining_balance'] = floatval($loan['remaining_balance'] ?? 0);
                $loan['principal'] = floatval($loan['principal'] ?? 0);
                $loan['total_payable'] = floatval($loan['total_payable'] ?? 0);
                $loan['transaction_id'] = $loan['id']; // Map id to transaction_id for compatibility
            }
            
            echo json_encode(['success' => true, 'loans' => $loans]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit();
    }

    // --- UPDATE LOAN ---
    if ($_GET['action'] === 'update_loan' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        
        try {
            // First get all active loans to find the ID by index
            $stmt = $pdo->prepare("
                SELECT d.id, d.client_id, d.status, d.remaining_balance
                FROM disbursements d
                WHERE d.status = 'active' OR (d.remaining_balance > 0.01 AND d.status != 'completed')
                ORDER BY d.date DESC
            ");
            $stmt->execute();
            $allLoans = $stmt->fetchAll();
            
            $idx = $input['index'] ?? -1;
            if ($idx < 0 || !isset($allLoans[$idx])) {
                echo json_encode(['success' => false, 'message' => 'Loan not found']);
                exit();
            }
            
            $loanId = $allLoans[$idx]['id'];
            $clientId = $allLoans[$idx]['client_id'];
            
            $newStatus = isset($input['status']) ? strtolower($input['status']) : $allLoans[$idx]['status'];
            $newRemaining = isset($input['remaining_balance']) ? floatval($input['remaining_balance']) : floatval($allLoans[$idx]['remaining_balance']);
            
            // Check if this update makes it active
            $wouldBeActive = ($newStatus === 'active') || ($newRemaining > 0.01 && $newStatus !== 'completed');
            
            // Check for other active loans from same client
            if ($wouldBeActive && $clientId) {
                $checkStmt = $pdo->prepare("
                    SELECT COUNT(*) as count 
                    FROM disbursements 
                    WHERE client_id = ? 
                    AND id != ?
                    AND (status = 'active' OR (remaining_balance > 0.01 AND status != 'completed'))
                ");
                $checkStmt->execute([$clientId, $loanId]);
                $result = $checkStmt->fetch();
                
                if ($result['count'] > 0) {
                    echo json_encode(['success' => false, 'message' => 'Client already has another active loan. Close that one first.']);
                    exit();
                }
            }
            
            // Build update query dynamically based on schema fields
            $updates = [];
            $params = [];
            
            if (isset($input['remaining_balance'])) {
                $updates[] = "remaining_balance = ?";
                $params[] = floatval($input['remaining_balance']);
            }
            
            if (isset($input['status'])) {
                $updates[] = "status = ?";
                $params[] = $input['status'];
                
                // Handle payoff_date based on status
                if ($input['status'] === 'completed') {
                    $updates[] = "payoff_date = ?";
                    $params[] = date('Y-m-d');
                } else {
                    $updates[] = "payoff_date = NULL";
                }
            }
            
            if (isset($input['principal_amount'])) {
                $updates[] = "principal = ?";
                $params[] = floatval($input['principal_amount']);
            }
            
            if (isset($input['total_payable'])) {
                $updates[] = "total_payable = ?";
                $params[] = floatval($input['total_payable']);
            }
            
            // updated_at is handled by ON UPDATE CURRENT_TIMESTAMP in schema
            // but we can explicitly set it if needed
            
            if (empty($updates)) {
                echo json_encode(['success' => false, 'message' => 'No fields to update']);
                exit();
            }
            
            $params[] = $loanId; // For WHERE clause
            
            $sql = "UPDATE disbursements SET " . implode(", ", $updates) . " WHERE id = ?";
            $updateStmt = $pdo->prepare($sql);
            $updateStmt->execute($params);
            
            // Log activity
            $logStmt = $pdo->prepare("
                INSERT INTO activity_log (user, action, details, ip_address, timestamp) 
                VALUES (?, 'update_loan', ?, ?, NOW())
            ");
            $logStmt->execute([
                $_SESSION['username'] ?? 'system',
                "Updated loan ID: $loanId, Status: $newStatus, Balance: $newRemaining",
                $_SERVER['REMOTE_ADDR']
            ]);
            
            echo json_encode(['success' => true, 'message' => 'Loan updated']);
            
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit();
    }

    // --- DELETE LOAN ---
    if ($_GET['action'] === 'delete_loan' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        
        try {
            // First get all active loans to find the ID by index
            $stmt = $pdo->prepare("
                SELECT d.id
                FROM disbursements d
                WHERE d.status = 'active' OR (d.remaining_balance > 0.01 AND d.status != 'completed')
                ORDER BY d.date DESC
            ");
            $stmt->execute();
            $allLoans = $stmt->fetchAll();
            
            $idx = $input['index'] ?? -1;
            if ($idx < 0 || !isset($allLoans[$idx])) {
                echo json_encode(['success' => false, 'message' => 'Loan not found']);
                exit();
            }
            
            $loanId = $allLoans[$idx]['id'];
            
            // Get loan data before deletion for logging
            $loanDataStmt = $pdo->prepare("SELECT * FROM disbursements WHERE id = ?");
            $loanDataStmt->execute([$loanId]);
            $loanData = $loanDataStmt->fetch();
            
            // Delete related loan_collections first (foreign key constraint handling)
            $delCollections = $pdo->prepare("DELETE FROM loan_collections WHERE disbursement_id = ?");
            $delCollections->execute([$loanId]);
            
            // Delete the disbursement
            $delStmt = $pdo->prepare("DELETE FROM disbursements WHERE id = ?");
            $delStmt->execute([$loanId]);
            
            // Log deletion to activity_log
            $logStmt = $pdo->prepare("
                INSERT INTO activity_log (user, action, details, ip_address, timestamp) 
                VALUES (?, 'delete_loan', ?, ?, NOW())
            ");
            $logStmt->execute([
                $_SESSION['username'] ?? 'system',
                "Deleted loan ID: $loanId, Client: " . ($loanData['client_name'] ?? 'Unknown'),
                $_SERVER['REMOTE_ADDR']
            ]);
            
            // Optionally log to deleted_transactions for audit trail
            $delTransStmt = $pdo->prepare("
                INSERT INTO deleted_transactions (original_id, transaction_type, transaction_data, deleted_by, deleted_at) 
                VALUES (?, 'loan', ?, ?, NOW())
            ");
            $delTransStmt->execute([
                $loanId,
                json_encode($loanData),
                $_SESSION['username'] ?? 'system'
            ]);
            
            echo json_encode(['success' => true, 'message' => 'Loan deleted']);
            
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit();
    }
    
    echo json_encode(['success' => false, 'message' => 'Unknown action']);
    exit();
}

// --- 3. PAGE RENDER & USER INFO ---
$full_name = $_SESSION['full_name'] ?? 'Admin';
$username = $_SESSION['username'] ?? 'admin';
$role = $_SESSION['user_role'] ?? 'admin';
$profile_pic_path = '';
$has_profile_pic = false;

try {
    $stmt = $pdo->prepare("SELECT profile_pic FROM users WHERE username = ? LIMIT 1");
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    
    if ($user && !empty($user['profile_pic'])) {
        $picPath = $base_path . $user['profile_pic'];
        if (file_exists($picPath)) {
            $profile_pic_path = $picPath;
            $has_profile_pic = true;
        }
    }
} catch (PDOException $e) {
    // Silently fail
}
?>
<!DOCTYPE html>
<html lang="en" class="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Active Loans | CUPAD</title>
    <link rel="icon" type="image/png" href="<?php echo htmlspecialchars($base_path . 'uploads/CUPAD LOGO.png'); ?>">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />
    
    <style>
        /* --- DASHBOARD VARIABLES & CSS --- */
        :root {
            --primary: #2563eb; --primary-dark: #1d4ed8;
            --success: #059669; --warning: #d97706; --danger: #dc2626;
            --bg-body: #f1f5f9; --bg-surface: #ffffff;
            --text-main: #0f172a; --text-muted: #64748b;
            --border: #e2e8f0; --radius-lg: 16px; --radius-md: 10px;
            --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1);
            --nav-height: 70px;
            --skel-bg: #e2e8f0;
        }

        html.dark {
            --bg-body: #0f172a; --bg-surface: #1e293b;
            --text-main: #f8fafc; --text-muted: #94a3b8;
            --border: #334155; --primary: #3b82f6;
            --skel-bg: #334155;
        }

        * { box-sizing: border-box; outline: none; }
        body { font-family: 'Plus Jakarta Sans', sans-serif; background: var(--bg-body); color: var(--text-main); margin: 0; padding-top: var(--nav-height); transition: 0.3s; }
        a { text-decoration: none; color: inherit; }

        /* Header */
        .main-header { position: fixed; top: 0; left: 0; right: 0; height: var(--nav-height); background: rgba(255,255,255,0.85); backdrop-filter: blur(10px); border-bottom: 1px solid var(--border); z-index: 50; }
        html.dark .main-header { background: rgba(15, 23, 42, 0.85); }
        .navbar { max-width: 1400px; margin: 0 auto; padding: 0 1.5rem; display: flex; align-items: center; justify-content: space-between; height: 100%; }
        .logo { display: flex; align-items: center; gap: 0.75rem; font-weight: 800; font-size: 1.35rem; color: var(--primary); }
        .logo img { height: 38px; }
        .nav-right { display: flex; align-items: center; gap: 1rem; }
        
        .icon-btn { width: 36px; height: 36px; border-radius: 50%; border: none; background: transparent; color: var(--text-muted); cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; transition: 0.2s; }
        .icon-btn:hover { background: var(--bg-body); color: var(--text-main); }

        /* User Dropdown */
        .user-dropdown-wrap { position: relative; }
        .user-pill { display: flex; align-items: center; gap: 0.75rem; padding: 4px 8px 4px 4px; border: 1px solid var(--border); border-radius: 99px; background: var(--bg-surface); cursor: pointer; transition: 0.2s; }
        .user-pill:hover { border-color: var(--primary); }
        .user-avatar { width: 34px; height: 34px; border-radius: 50%; object-fit: cover; }
        .user-avatar-fallback { width: 34px; height: 34px; border-radius: 50%; background: var(--bg-body); color: var(--text-muted); display: flex; align-items: center; justify-content: center; border: 1px solid var(--border); }
        .user-info { display: flex; flex-direction: column; line-height: 1.1; padding-right: 0.5rem; }
        .user-name { font-weight: 600; font-size: 0.85rem; }
        .dropdown-menu { position: absolute; top: 120%; right: 0; background: var(--bg-surface); border: 1px solid var(--border); border-radius: var(--radius-md); box-shadow: var(--shadow-md); min-width: 200px; display: none; z-index: 1000; flex-direction: column; }
        .dropdown-menu.show { display: flex; }
        .dropdown-item { padding: 0.75rem 1rem; display: flex; align-items: center; gap: 0.75rem; font-size: 0.9rem; color: var(--text-main); transition: 0.2s; }
        .dropdown-item:hover { background: var(--bg-body); color: var(--primary); }

        /* Container */
        .container { max-width: 1400px; margin: 0 auto; padding: 2rem 1.5rem 4rem; }
        
        /* Page Header */
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; flex-wrap: wrap; gap: 1rem; }
        .page-title h1 { margin: 0; font-size: 1.5rem; font-weight: 800; }
        .page-title p { margin: 0.5rem 0 0; color: var(--text-muted); font-size: 0.9rem; }
        .btn-back { display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.6rem 1rem; border: 1px solid var(--border); border-radius: var(--radius-md); background: var(--bg-surface); color: var(--text-main); font-weight: 500; font-size: 0.9rem; transition: 0.2s; }
        .btn-back:hover { border-color: var(--primary); color: var(--primary); }

        /* Card & Table */
        .card { background: var(--bg-surface); border: 1px solid var(--border); border-radius: var(--radius-lg); box-shadow: var(--shadow-md); overflow: hidden; }
        .card-toolbar { padding: 1.25rem; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: wrap; }
        .search-box { position: relative; max-width: 300px; width: 100%; }
        .search-box input { width: 100%; padding: 0.6rem 1rem 0.6rem 2.4rem; border-radius: var(--radius-md); border: 1px solid var(--border); background: var(--bg-body); color: var(--text-main); }
        .search-box i { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--text-muted); }

        .custom-table { width: 100%; border-collapse: collapse; }
        .custom-table th { text-align: left; padding: 1rem 1.5rem; font-size: 0.8rem; text-transform: uppercase; color: var(--text-muted); font-weight: 600; border-bottom: 1px solid var(--border); background: var(--bg-body); }
        .custom-table td { padding: 1rem 1.5rem; border-bottom: 1px solid var(--border); vertical-align: middle; color: var(--text-main); font-size: 0.95rem; }
        .custom-table tr:hover { background: var(--bg-body); }

        /* Client Cell */
        .client-cell { display: flex; align-items: center; gap: 1rem; }
        .client-avatar-sm { width: 40px; height: 40px; border-radius: 50%; object-fit: cover; background: var(--border); flex-shrink: 0; }
        
        /* Badges */
        .badge { padding: 4px 10px; border-radius: 99px; font-size: 0.75rem; font-weight: 700; display: inline-flex; align-items: center; gap: 5px; }
        .badge-success { background: rgba(5, 150, 105, 0.1); color: var(--success); }
        .badge-warning { background: rgba(217, 119, 6, 0.1); color: var(--warning); }
        
        /* Action Buttons */
        .btn-icon { width: 32px; height: 32px; border-radius: 6px; border: 1px solid var(--border); display: inline-flex; align-items: center; justify-content: center; color: var(--text-muted); transition: 0.2s; cursor: pointer; background: var(--bg-surface); margin-left: 0.25rem; }
        .btn-icon:hover { border-color: var(--primary); color: var(--primary); transform: translateY(-1px); }
        .btn-icon.delete:hover { border-color: var(--danger); color: var(--danger); }

        /* Modal */
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); backdrop-filter: blur(2px); align-items: center; justify-content: center; }
        .modal.show { display: flex; animation: fadeIn 0.2s; }
        .modal-content { background: var(--bg-surface); border: 1px solid var(--border); border-radius: var(--radius-lg); width: 90%; max-width: 500px; padding: 1.5rem; position: relative; box-shadow: var(--shadow-md); }
        .modal-header { font-weight: 700; font-size: 1.2rem; margin-bottom: 1rem; display: flex; justify-content: space-between; }
        .form-group { margin-bottom: 1rem; }
        .form-group label { display: block; font-size: 0.85rem; color: var(--text-muted); margin-bottom: 0.5rem; }
        .form-control { width: 100%; padding: 0.75rem; border: 1px solid var(--border); border-radius: var(--radius-md); background: var(--bg-body); color: var(--text-main); }
        .form-control:disabled { opacity: 0.7; cursor: not-allowed; }
        
        .modal-footer { display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 1.5rem; }
        .btn-cancel { background: transparent; border: 1px solid var(--border); color: var(--text-muted); padding: 0.75rem 1.25rem; border-radius: var(--radius-md); cursor: pointer; font-weight: 600; transition: 0.2s; }
        .btn-save { background: var(--primary); color: white; border: none; padding: 0.75rem 1.25rem; border-radius: var(--radius-md); cursor: pointer; font-weight: 600; transition: 0.2s; }
        .btn-delete-confirm { background: var(--danger); color: white; border: none; padding: 0.75rem 1.25rem; border-radius: var(--radius-md); cursor: pointer; font-weight: 600; transition: 0.2s; }

        /* Skeleton */
        .skeleton-box { display: inline-block; height: 1em; position: relative; overflow: hidden; background-color: var(--skel-bg); border-radius: 4px; }
        .skeleton-box::after { position: absolute; top: 0; right: 0; bottom: 0; left: 0; transform: translateX(-100%); background-image: linear-gradient( 90deg, rgba(255, 255, 255, 0) 0, rgba(255, 255, 255, 0.2) 20%, rgba(255, 255, 255, 0.5) 60%, rgba(255, 255, 255, 0) ); animation: shimmer 2s infinite; content: ''; }
        @keyframes shimmer { 100% { transform: translateX(100%); } }

        @keyframes fadeIn { from { opacity: 0; transform: scale(0.95); } to { opacity: 1; transform: scale(1); } }
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
                        <a href="profile.php" class="dropdown-item"><i class="fas fa-user-circle"></i> Profile</a>
                        <div class="dropdown-divider"></div>
                        <a href="../logout.php" class="dropdown-item" style="color:var(--danger)"><i class="fas fa-sign-out-alt"></i> Logout</a>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <main class="container">
        <!-- Page Title -->
        <div class="page-header">
            <div class="page-title">
                <h1>Active Loans</h1>
                <p>Manage all loans currently marked as active or running.</p>
            </div>
            <a href="dashboard.php" class="btn-back">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>
        </div>

        <!-- Content Card -->
        <div class="card">
            <!-- Toolbar -->
            <div class="card-toolbar">
                <div class="search-box">
                    <i class="fas fa-search"></i>
                    <input type="text" id="tableSearch" placeholder="Search client or Txn ID..." onkeyup="searchTable()">
                </div>
                <div style="font-size: 0.9rem; color: var(--text-muted);">
                    Total Active: <strong id="totalCount">...</strong>
                </div>
            </div>

            <!-- Table -->
            <div style="overflow-x: auto;">
                <table class="custom-table" id="loansTable">
                    <thead>
                        <tr>
                            <th width="5%">#</th>
                            <th width="30%">Client Details</th>
                            <th width="20%">Transaction Info</th>
                            <th width="15%">Principal</th>
                            <th width="15%">Balance</th>
                            <th width="15%" style="text-align:right">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="loansBody">
                        <!-- Loaded via JS -->
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <!-- EDIT MODAL -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <span>Edit Loan Details</span>
                <i class="fas fa-times" style="cursor:pointer" onclick="closeModal('editModal')"></i>
            </div>
            
            <div class="form-group">
                <label>Client Name</label>
                <input type="text" id="editClient" class="form-control" disabled>
            </div>
            <div class="form-group">
                <label>Transaction ID</label>
                <input type="text" id="editTxn" class="form-control" disabled>
            </div>

            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:1rem;">
                <div class="form-group">
                    <label>Principal (₦)</label>
                    <input type="number" id="editPrincipal" class="form-control" step="0.01">
                </div>
                <div class="form-group">
                    <label>Total Payable (₦)</label>
                    <input type="number" id="editTotalPayable" class="form-control" step="0.01">
                </div>
            </div>

            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:1rem;">
                <div class="form-group">
                    <label>Balance Remaining (₦)</label>
                    <input type="number" id="editRemaining" class="form-control" step="0.01">
                </div>
                <div class="form-group">
                    <label>Status</label>
                    <select id="editStatus" class="form-control">
                        <option value="active">Active</option>
                        <option value="completed">Completed</option>
                        <option value="defaulted">Defaulted</option>
                        <option value="restructured">Restructured</option>
                    </select>
                </div>
            </div>

            <div class="modal-footer">
                <button class="btn-cancel" onclick="closeModal('editModal')">Cancel</button>
                <button class="btn-save" onclick="saveEdit()">Save Changes</button>
            </div>
        </div>
    </div>

    <!-- DELETE MODAL -->
    <div id="deleteModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <span>Confirm Deletion</span>
                <i class="fas fa-times" style="cursor:pointer" onclick="closeModal('deleteModal')"></i>
            </div>
            <p>Are you sure you want to delete this loan record? This action cannot be undone.</p>
            <div class="modal-footer">
                <button class="btn-cancel" onclick="closeModal('deleteModal')">Cancel</button>
                <button class="btn-delete-confirm" onclick="confirmDeleteAction()">Delete Loan</button>
            </div>
        </div>
    </div>

    <script>
        let loansData = [];
        let currentEditIndex = -1;
        let deleteTargetIndex = -1;

        document.addEventListener('DOMContentLoaded', () => {
            renderSkeleton();
            fetchActiveLoans();
        });

        // --- 1. DATA LOADING & SKELETON ---
        function renderSkeleton() {
            const tbody = document.getElementById('loansBody');
            let html = '';
            for(let i=0; i<5; i++) {
                html += `
                    <tr>
                        <td><div class="skeleton-box" style="width:20px"></div></td>
                        <td>
                            <div class="client-cell">
                                <div class="skeleton-box" style="width:40px; height:40px; border-radius:50%"></div>
                                <div>
                                    <div class="skeleton-box" style="width:120px; margin-bottom:5px"></div>
                                    <div class="skeleton-box" style="width:60px; height:10px"></div>
                                </div>
                            </div>
                        </td>
                        <td><div class="skeleton-box" style="width:100px"></div></td>
                        <td><div class="skeleton-box" style="width:80px"></div></td>
                        <td><div class="skeleton-box" style="width:80px"></div></td>
                        <td><div class="skeleton-box" style="width:60px"></div></td>
                    </tr>
                `;
            }
            tbody.innerHTML = html;
        }

        async function fetchActiveLoans() {
            try {
                const res = await fetch('?action=get_active_loans');
                const data = await res.json();
                if (data.success) {
                    loansData = data.loans;
                    renderTable(loansData);
                } else {
                    document.getElementById('loansBody').innerHTML = '<tr><td colspan="6" align="center">Error loading data: ' + (data.message || 'Unknown error') + '</td></tr>';
                }
            } catch(e) {
                console.error(e);
                document.getElementById('loansBody').innerHTML = '<tr><td colspan="6" align="center">Connection error</td></tr>';
            }
        }

        function renderTable(data) {
            const tbody = document.getElementById('loansBody');
            document.getElementById('totalCount').innerText = data.length;
            tbody.innerHTML = '';

            if (data.length === 0) {
                tbody.innerHTML = '<tr><td colspan="6" align="center" style="padding:2rem; color:var(--text-muted)">No active loans found.</td></tr>';
                return;
            }

            data.forEach((ln, i) => {
                const tr = document.createElement('tr');
                tr.className = 'main-row';
                
                // Avatar Logic
                let avatarHtml = ln.profile_pic 
                    ? `<img src="<?php echo $base_path; ?>${ln.profile_pic}" class="client-avatar-sm" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex'">`
                    : `<div class="client-avatar-sm" style="display:flex;align-items:center;justify-content:center;color:var(--text-muted)"><i class="fas fa-user"></i></div>`;
                
                // If has profile pic but might fail, add fallback div
                if (ln.profile_pic && !avatarHtml.includes('onerror')) {
                    avatarHtml += `<div class="client-avatar-sm" style="display:none;align-items:center;justify-content:center;color:var(--text-muted)"><i class="fas fa-user"></i></div>`;
                }
                
                // Status Badge
                let badgeClass = (ln.remaining_balance > 0) ? 'badge-warning' : 'badge-success';
                let statusText = (ln.remaining_balance > 0) ? 'Active' : 'Settled';

                tr.innerHTML = `
                    <td>${i + 1}</td>
                    <td>
                        <div class="client-cell">
                            ${avatarHtml}
                            <div>
                                <div style="font-weight:600; font-size:0.9rem">${escapeHtml(ln.client_name)}</div>
                                <div style="font-size:0.75rem; color:var(--text-muted)">${escapeHtml(ln.client_id)}</div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <div style="font-weight:600; font-size:0.85rem">${escapeHtml(ln.transaction_id || ln.id)}</div>
                        <div style="font-size:0.75rem; color:var(--text-muted)">${escapeHtml(ln.date)}</div>
                    </td>
                    <td style="color:var(--text-muted)">${formatCurrency(ln.principal)}</td>
                    <td style="font-weight:700; color:var(--danger)">${formatCurrency(ln.remaining_balance)}</td>
                    <td style="text-align:right">
                        <button class="btn-icon" onclick="openEdit(${ln.index})" title="Edit"><i class="fas fa-pen"></i></button>
                        <button class="btn-icon delete" onclick="openDelete(${ln.index})" title="Delete"><i class="fas fa-trash"></i></button>
                    </td>
                `;
                tbody.appendChild(tr);
            });
        }

        // --- 2. ACTIONS (EDIT/DELETE) ---
        function openEdit(index) {
            currentEditIndex = index;
            const ln = loansData.find(l => l.index === index);
            if (!ln) return;

            document.getElementById('editClient').value = ln.client_name || '';
            document.getElementById('editTxn').value = ln.transaction_id || ln.id || '';
            document.getElementById('editPrincipal').value = ln.principal || 0;
            document.getElementById('editTotalPayable').value = ln.total_payable || 0;
            document.getElementById('editRemaining').value = ln.remaining_balance || 0;
            document.getElementById('editStatus').value = ln.status || 'active';
            
            document.getElementById('editModal').classList.add('show');
        }

        function openDelete(index) {
            deleteTargetIndex = index;
            document.getElementById('deleteModal').classList.add('show');
        }

        function closeModal(id) {
            document.getElementById(id).classList.remove('show');
        }

        async function saveEdit() {
            if (currentEditIndex < 0) return;
            
            const btn = document.querySelector('#editModal .btn-save');
            const originalText = btn.innerText;
            btn.innerText = "Saving...";
            btn.disabled = true;

            const payload = {
                index: currentEditIndex,
                principal_amount: parseFloat(document.getElementById('editPrincipal').value) || 0,
                total_payable: parseFloat(document.getElementById('editTotalPayable').value) || 0,
                remaining_balance: parseFloat(document.getElementById('editRemaining').value) || 0,
                status: document.getElementById('editStatus').value
            };

            try {
                const res = await fetch('?action=update_loan', {
                    method: 'POST',
                    headers: {'Content-Type':'application/json'},
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                
                if (data.success) {
                    closeModal('editModal');
                    fetchActiveLoans(); // Refresh
                } else {
                    alert(data.message || 'Failed to update');
                }
            } catch(e) {
                alert('Connection Error');
            } finally {
                btn.innerText = originalText;
                btn.disabled = false;
            }
        }

        async function confirmDeleteAction() {
            if (deleteTargetIndex < 0) return;

            const btn = document.querySelector('#deleteModal .btn-delete-confirm');
            const originalText = btn.innerText;
            btn.innerText = "Deleting...";
            btn.disabled = true;

            try {
                const res = await fetch('?action=delete_loan', {
                    method: 'POST',
                    headers: {'Content-Type':'application/json'},
                    body: JSON.stringify({index: deleteTargetIndex})
                });
                const data = await res.json();
                
                if (data.success) {
                    closeModal('deleteModal');
                    fetchActiveLoans();
                } else {
                    alert(data.message || 'Failed to delete');
                }
            } catch(e) {
                alert('Connection Error');
            } finally {
                btn.innerText = originalText;
                btn.disabled = false;
            }
        }

        // --- 3. UTILS & SEARCH ---
        function formatCurrency(val) {
            return '₦' + (parseFloat(val) || 0).toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2});
        }
        function escapeHtml(text) {
            if (!text) return '';
            return text.toString().replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
        }

        function searchTable() {
            const input = document.getElementById("tableSearch");
            const filter = input.value.toUpperCase();
            const table = document.getElementById("loansTable");
            const tr = table.getElementsByClassName("main-row");

            for (let i = 0; i < tr.length; i++) {
                // Search in Client (Column 2) and Txn (Column 3)
                const clientTd = tr[i].getElementsByTagName("td")[1];
                const txnTd = tr[i].getElementsByTagName("td")[2];
                if (clientTd || txnTd) {
                    const clientTxt = clientTd.textContent || clientTd.innerText;
                    const txnTxt = txnTd.textContent || txnTd.innerText;
                    if (clientTxt.toUpperCase().indexOf(filter) > -1 || txnTxt.toUpperCase().indexOf(filter) > -1) {
                        tr[i].style.display = "";
                    } else {
                        tr[i].style.display = "none";
                    }
                }
            }
        }

        // --- 4. THEME & DROPDOWN (Shared Logic) ---
        const userTrigger = document.getElementById('userDropdownTrigger');
        const userDropdown = document.getElementById('userDropdown');
        if(userTrigger){
            userTrigger.addEventListener('click', (e) => { e.stopPropagation(); userDropdown.classList.toggle('show'); });
            document.addEventListener('click', (e) => { if (!userTrigger.contains(e.target)) userDropdown.classList.remove('show'); });
        }
        const themeBtn = document.getElementById('themeToggle');
        if(themeBtn){
            themeBtn.addEventListener('click', () => {
                const html = document.documentElement;
                const isDark = html.classList.toggle('dark');
                html.classList.toggle('light');
                localStorage.setItem('theme', isDark ? 'dark' : 'light');
                themeBtn.querySelector('i').className = isDark ? 'fas fa-sun' : 'fas fa-moon';
            });
            if(localStorage.getItem('theme') === 'dark') {
                document.documentElement.classList.add('dark');
                document.documentElement.classList.remove('light');
                themeBtn.querySelector('i').className = 'fas fa-sun';
            }
        }
    </script>
</body>
</html>