<?php
session_start();
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: ../index.php');
    exit();
}

require_once '../includes/config.php';
$pdo = getDbConnection();

$base_path = '../';

// --- 1. GET USER INFO FOR HEADER ---
$full_name = $_SESSION['full_name'] ?? 'Admin';
$username = $_SESSION['username'] ?? 'admin';
$user_role = $_SESSION['user_role'] ?? 'admin';

// Profile Pic Logic - SQL Based
$my_profile_pic = '';
try {
    $stmt = $pdo->prepare("SELECT profile_pic FROM users WHERE username = ? LIMIT 1");
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($user && !empty($user['profile_pic'])) {
        $my_profile_pic = $user['profile_pic'];
    }
} catch (PDOException $e) {
    // Silently fail
}

// --- 2. BACKEND LOGIC ---
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    
    // --- GET LOANS ---
    if ($_GET['action'] === 'get_loans') {
        try {
            $status_filter = $_GET['status'] ?? 'all';
            $search = $_GET['search'] ?? '';
            $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
            $limit = 10;
            
            // Build base query
            $where_clauses = [];
            $params = [];
            
            // Status filter
            if ($status_filter === 'active') {
                $where_clauses[] = "(d.status = 'active' OR (d.remaining_balance > 0.01 AND d.status != 'completed'))";
            } elseif ($status_filter === 'completed') {
                $where_clauses[] = "(d.status = 'completed' OR d.remaining_balance <= 0.01)";
            }
            
            // Search filter
            if (!empty($search)) {
                $where_clauses[] = "c.name LIKE ?";
                $params[] = "%$search%";
            }
            
            $where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';
            
            // Count total records
            $count_sql = "SELECT COUNT(*) as total FROM disbursements d LEFT JOIN clients c ON d.client_id = c.id $where_sql";
            $count_stmt = $pdo->prepare($count_sql);
            $count_stmt->execute($params);
            $total_records = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
            
            // Get paginated data
            $offset = ($page - 1) * $limit;
            $sql = "
                SELECT 
                    d.id as transaction_id,
                    d.client_id,
                    d.principal,
                    d.total_payable,
                    d.remaining_balance,
                    d.date,
                    d.status,
                    d.officer,
                    c.name as client_name
                FROM disbursements d
                LEFT JOIN clients c ON d.client_id = c.id
                $where_sql
                ORDER BY d.date DESC
                LIMIT $limit OFFSET $offset
            ";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $loans = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Add computed fields
            $filtered_loans = [];
            foreach ($loans as $idx => $loan) {
                $remaining = floatval($loan['remaining_balance'] ?? 0);
                $status = ($remaining > 0.01 && $loan['status'] !== 'completed') ? 'active' : 'completed';
                
                $filtered_loans[] = [
                    'index' => $idx + $offset, // Use offset + index for consistent referencing
                    'transaction_id' => $loan['transaction_id'],
                    'client_name' => $loan['client_name'] ?? 'Unknown',
                    'client_id' => $loan['client_id'],
                    'officer' => $loan['officer'] ?? '',
                    'principal_amount' => floatval($loan['principal'] ?? 0),
                    'total_payable' => floatval($loan['total_payable'] ?? 0),
                    'remaining_balance' => $remaining,
                    'date' => $loan['date'],
                    'status' => $status
                ];
            }
            
            $total_pages = ceil($total_records / $limit);
            
            echo json_encode([
                'success' => true, 
                'loans' => $filtered_loans,
                'pagination' => [
                    'current_page' => $page,
                    'total_pages' => $total_pages,
                    'total_records' => $total_records,
                    'start' => $total_records > 0 ? $offset + 1 : 0,
                    'end' => min($offset + $limit, $total_records)
                ]
            ]);
            
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit();
    }
    
    // --- UPDATE LOAN ---
    if ($_GET['action'] === 'update_loan' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        
        // We need to find the actual loan by offset since we don't have a direct ID
        $new_balance = floatval($input['remaining_balance'] ?? 0);
        $new_status = $input['status'] ?? 'active';
        $target_index = $input['index'] ?? -1;
        
        try {
            // First get all loans with same filters to find the correct one
            // For simplicity, we'll use the ID if provided, otherwise search by position
            if (isset($input['loan_id'])) {
                $loan_id = $input['loan_id'];
            } else {
                // Get the loan at the specific offset
                $offset = (int)$target_index;
                $find_sql = "
                    SELECT d.id 
                    FROM disbursements d
                    LEFT JOIN clients c ON d.client_id = c.id
                    ORDER BY d.date DESC
                    LIMIT 1 OFFSET $offset
                ";
                $find_stmt = $pdo->query($find_sql);
                $found = $find_stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$found) {
                    echo json_encode(['success' => false, 'message' => 'Loan not found']);
                    exit();
                }
                $loan_id = $found['id'];
            }
            
            // Determine final status
            $final_status = $new_status;
            if ($new_balance <= 0.01) {
                $final_status = 'completed';
            }
            
            // Build update
            $updates = ["remaining_balance = ?", "status = ?", "updated_at = NOW()"];
            $update_params = [$new_balance, $final_status];
            
            // Handle payoff_date
            if ($final_status === 'completed') {
                $updates[] = "payoff_date = ?";
                $update_params[] = date('Y-m-d');
            } else {
                $updates[] = "payoff_date = NULL";
            }
            
            $update_params[] = $loan_id; // For WHERE clause
            
            $sql = "UPDATE disbursements SET " . implode(", ", $updates) . " WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($update_params);
            
            echo json_encode(['success' => true]);
            
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit();
    }
    
    echo json_encode(['success' => false, 'message' => 'Unknown action']);
    exit();
}
?>
<!DOCTYPE html>
<html lang="en" class="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Loans - CUPAD</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />
    <style>
        :root {
            --primary: #2563eb; --primary-dark: #1d4ed8;
            --success: #059669; --warning: #d97706; --danger: #dc2626;
            --bg-body: #f8fafc; --bg-surface: #ffffff;
            --text-main: #0f172a; --text-muted: #64748b;
            --border: #e2e8f0; --radius-lg: 16px; --nav-height: 70px;
            --shadow-sm: 0 1px 3px rgba(0,0,0,0.1);
        }
        html.dark {
            --bg-body: #0f172a; --bg-surface: #1e293b;
            --text-main: #f8fafc; --text-muted: #94a3b8;
            --border: #334155; --primary: #3b82f6;
        }
        * { box-sizing: border-box; outline: none; }
        body { font-family: 'Plus Jakarta Sans', sans-serif; background: var(--bg-body); color: var(--text-main); margin: 0; padding-top: var(--nav-height); font-size: 0.92rem; transition: 0.3s; }
        a { text-decoration: none; color: inherit; }

        /* HEADER */
        .main-header { position: fixed; top: 0; left: 0; right: 0; height: var(--nav-height); background: rgba(255,255,255,0.85); backdrop-filter: blur(10px); border-bottom: 1px solid var(--border); z-index: 50; }
        html.dark .main-header { background: rgba(15, 23, 42, 0.85); }
        .navbar { max-width: 1400px; margin: 0 auto; padding: 0 1.5rem; display: flex; align-items: center; justify-content: space-between; height: 100%; }
        .logo { display: flex; align-items: center; gap: 0.75rem; font-weight: 800; font-size: 1.35rem; color: var(--primary); }
        .nav-right { display: flex; align-items: center; gap: 1rem; }
        
        .icon-btn { width: 36px; height: 36px; border-radius: 50%; background: transparent; color: var(--text-muted); cursor: pointer; display: flex; align-items: center; justify-content: center; border: none; transition: 0.2s; }
        .icon-btn:hover { background: var(--bg-body); color: var(--text-main); }

        /* USER PILL */
        .user-pill { display: flex; align-items: center; gap: 0.75rem; padding: 4px 8px 4px 4px; border: 1px solid var(--border); border-radius: 99px; background: var(--bg-surface); cursor: pointer; transition: 0.2s; }
        .user-pill:hover { border-color: var(--primary); }
        .user-avatar-fb { width: 34px; height: 34px; border-radius: 50%; background: var(--bg-body); color: var(--text-muted); display: flex; align-items: center; justify-content: center; border: 1px solid var(--border); font-size: 1rem; }
        .user-info { display: flex; flex-direction: column; line-height: 1.1; padding-right: 0.5rem; }
        .user-name { font-weight: 600; font-size: 0.85rem; }
        
        /* CONTENT */
        .container { max-width: 1400px; margin: 0 auto; padding: 2rem 1.5rem; }
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; }
        .page-title { font-weight: 700; font-size: 1.5rem; display: flex; align-items: center; gap: 0.75rem; }
        
        .btn { padding: 0.6rem 1.2rem; border-radius: 99px; border: 1px solid var(--border); background: var(--bg-surface); color: var(--text-main); cursor: pointer; display: flex; align-items: center; gap: 0.5rem; font-weight: 600; font-size: 0.85rem; transition: 0.2s; }
        .btn:hover { border-color: var(--primary); color: var(--primary); background: var(--bg-body); }

        /* FILTERS */
        .filters-card { background: var(--bg-surface); padding: 1.5rem; border-radius: var(--radius-lg); border: 1px solid var(--border); margin-bottom: 1.5rem; box-shadow: var(--shadow-sm); display: flex; gap: 1rem; align-items: end; flex-wrap: wrap; }
        .form-group { flex: 1; min-width: 200px; }
        .form-label { display: block; font-size: 0.8rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.4rem; }
        .form-select, .form-input { width: 100%; padding: 0.6rem 1rem; border-radius: 10px; border: 1px solid var(--border); background: var(--bg-body); color: var(--text-main); font-size: 0.9rem; transition: 0.2s; }
        .form-select:focus, .form-input:focus { border-color: var(--primary); outline: none; box-shadow: 0 0 0 3px rgba(37,99,235,0.1); }

        /* TABLE */
        .table-card { background: var(--bg-surface); border: 1px solid var(--border); border-radius: var(--radius-lg); overflow: hidden; box-shadow: var(--shadow-sm); }
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: separate; border-spacing: 0; min-width: 900px; }
        th { text-align: left; padding: 1rem 1.5rem; font-size: 0.75rem; text-transform: uppercase; color: var(--text-muted); font-weight: 600; background: var(--bg-surface); border-bottom: 1px solid var(--border); }
        td { padding: 1rem 1.5rem; border-bottom: 1px solid var(--border); vertical-align: middle; }
        tr:hover td { background: var(--bg-body); }
        
        .client-cell { display: flex; align-items: center; gap: 1rem; }
        .avatar-initials { width: 40px; height: 40px; border-radius: 50%; background: #dbeafe; color: var(--primary); display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.9rem; }
        html.dark .avatar-initials { background: rgba(37,99,235,0.2); }
        
        .progress-bar { width: 100%; height: 6px; background: var(--bg-body); border-radius: 99px; margin-top: 6px; overflow: hidden; }
        .progress-fill { height: 100%; border-radius: 99px; transition: width 0.5s ease; }
        .status-badge { padding: 4px 10px; border-radius: 99px; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; }
        .active-badge { background: #ffedd5; color: #ea580c; }
        .completed-badge { background: #d1fae5; color: #059669; }
        html.dark .active-badge { background: rgba(234,88,12,0.15); }
        html.dark .completed-badge { background: rgba(16,185,129,0.15); }

        /* PAGINATION */
        .pagination { padding: 1rem 1.5rem; display: flex; justify-content: space-between; align-items: center; border-top: 1px solid var(--border); }
        .page-nums { display: flex; gap: 0.5rem; }
        .page-btn { width: 32px; height: 32px; border-radius: 8px; display: flex; align-items: center; justify-content: center; border: 1px solid var(--border); cursor: pointer; transition: 0.2s; background: var(--bg-surface); color: var(--text-main); }
        .page-btn:hover:not(:disabled) { border-color: var(--primary); color: var(--primary); }
        .page-btn:disabled { opacity: 0.5; cursor: not-allowed; }

        /* --- IMPROVED MODAL STYLES --- */
        .modal-overlay {
            position: fixed; inset: 0; background: rgba(15, 23, 42, 0.6); 
            backdrop-filter: blur(4px); z-index: 100; display: none; 
            align-items: center; justify-content: center; opacity: 0; transition: opacity 0.3s ease;
        }
        .modal-overlay.open { display: flex; opacity: 1; }

        .modal-box {
            background: var(--bg-surface); padding: 0; border-radius: 24px; 
            width: 95%; max-width: 420px; box-shadow: 0 20px 50px rgba(0,0,0,0.2); 
            transform: scale(0.95); transition: transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
            border: 1px solid var(--border); overflow: hidden;
        }
        .modal-overlay.open .modal-box { transform: scale(1); }

        /* Modal Header */
        .modal-top { padding: 1.5rem 1.5rem 1rem; border-bottom: 1px solid var(--border); background: var(--bg-body); }
        .modal-client-name { font-size: 1.1rem; font-weight: 700; margin-bottom: 0.2rem; color: var(--text-main); }
        .modal-sub-info { font-size: 0.85rem; color: var(--text-muted); display: flex; justify-content: space-between; align-items: center; }

        /* Modal Body */
        .modal-body { padding: 1.5rem; }

        /* Hero Input Wrapper */
        .balance-wrapper {
            background: var(--bg-body); border: 2px solid var(--border);
            border-radius: 16px; padding: 1rem; margin-bottom: 1rem;
            transition: 0.2s; position: relative; display: block;
        }
        .balance-wrapper:focus-within { border-color: var(--primary); box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.1); }

        .balance-label { font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 700; color: var(--text-muted); margin-bottom: 0.5rem; display: block; }

        .input-row { display: flex; align-items: center; gap: 0.5rem; }
        .currency-symbol { font-size: 1.5rem; font-weight: 600; color: var(--text-muted); }
        .hero-input {
            width: 100%; border: none; background: transparent; 
            font-size: 2rem; font-weight: 700; color: var(--text-main);
            padding: 0; margin: 0; outline: none; font-family: 'Plus Jakarta Sans', sans-serif;
        }
        .hero-input::placeholder { color: var(--border); }

        /* Quick Action Button */
        .btn-mark-paid {
            background: rgba(16, 185, 129, 0.1); color: #10b981; border: none;
            font-size: 0.75rem; font-weight: 700; padding: 6px 12px; border-radius: 8px;
            cursor: pointer; transition: 0.2s; white-space: nowrap;
        }
        .btn-mark-paid:hover { background: #10b981; color: white; }

        /* Segmented Control for Status */
        .status-segment {
            display: flex; background: var(--bg-body); padding: 4px; 
            border-radius: 12px; border: 1px solid var(--border); margin-bottom: 1.5rem;
        }
        .segment-opt {
            flex: 1; text-align: center; padding: 0.6rem; font-size: 0.9rem; font-weight: 600;
            color: var(--text-muted); cursor: pointer; border-radius: 9px; transition: 0.2s;
            user-select: none; display: flex; align-items: center; justify-content: center; gap: 6px;
        }
        .segment-opt:hover { color: var(--text-main); }
        .segment-opt.selected { background: var(--bg-surface); color: var(--primary); box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
        .segment-opt.selected[data-val="completed"] { color: var(--success); }
        .segment-opt.selected[data-val="active"] { color: var(--warning); }
        .status-radio { display: none; }

        /* Footer Actions */
        .modal-actions { display: grid; grid-template-columns: 1fr 1.5fr; gap: 1rem; }
        .btn-cancel { background: transparent; border: 1px solid var(--border); color: var(--text-muted); display:flex; justify-content:center; align-items:center;}
        .btn-cancel:hover { background: var(--bg-body); color: var(--text-main); border-color: var(--text-muted); }
        
        .btn-primary { background: var(--primary); color: white; border: none; padding: 0.7rem; width: 100%; border-radius: 10px; font-weight: 600; cursor: pointer; font-size: 0.95rem; display: flex; justify-content: center; align-items: center; transition: 0.2s;}
        .btn-primary:hover { background: var(--primary-dark); }
        .btn-primary:disabled { opacity: 0.7; cursor: not-allowed; }

        /* TOAST */
        .toast { position: fixed; bottom: 20px; right: 20px; padding: 12px 20px; background: var(--bg-surface); border-left: 4px solid var(--primary); box-shadow: 0 5px 15px rgba(0,0,0,0.1); border-radius: 8px; display: flex; align-items: center; gap: 10px; transform: translateX(120%); transition: 0.3s; z-index: 200; font-weight: 500; }
        .toast.show { transform: translateX(0); }
        .toast.success { border-color: var(--success); } .toast.error { border-color: var(--danger); }
    </style>
</head>
<body>

    <!-- Header -->
    <header class="main-header">
        <div class="navbar">
            <a href="dashboard.php" class="logo">
                <img src="<?php echo htmlspecialchars($base_path . 'uploads/CUPAD LOGO.png'); ?>" style="height:38px; margin-right:8px;" alt="CUPAD">
                <span>CUPAD Admin</span>
            </a>
            <div class="nav-right">
                <button id="themeToggle" class="icon-btn"><i class="fas fa-moon"></i></button>
                <div class="user-pill">
                    <?php if(!empty($my_profile_pic) && file_exists($base_path . $my_profile_pic)): ?>
                        <img src="<?php echo htmlspecialchars($base_path . $my_profile_pic); ?>" style="width:32px; height:32px; border-radius:50%; object-fit:cover;">
                    <?php else: ?>
                        <div class="user-avatar-fb"><i class="fas fa-user"></i></div>
                    <?php endif; ?>
                    <div class="user-info">
                        <span class="user-name"><?php echo htmlspecialchars($full_name); ?></span>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <div id="toast" class="toast"><i class="fas fa-info-circle"></i> <span id="toastMsg"></span></div>

    <main class="container">
        
        <div class="page-header">
            <div class="page-title"><i class="fas fa-money-check-alt text-muted"></i> Loan Management</div>
            <a href="dashboard.php" class="btn"><i class="fas fa-arrow-left"></i> Dashboard</a>
        </div>

        <div class="filters-card">
            <div class="form-group">
                <label>Filter Status</label>
                <select id="statusFilter" class="form-select">
                    <option value="all">All Loans</option>
                    <option value="active">Active (Ongoing)</option>
                    <option value="completed">Completed</option>
                </select>
            </div>
            <div class="form-group" style="flex: 2;">
                <label>Search Client</label>
                <input type="text" id="searchInput" class="form-input" placeholder="Enter client name...">
            </div>
            <div class="form-group" style="flex:0">
                <label>&nbsp;</label>
                <div style="text-align:right; font-size:0.9rem; color:var(--text-muted); white-space:nowrap">
                    Total: <strong id="totalRecords" style="color:var(--text-main)">0</strong>
                </div>
            </div>
        </div>

        <div class="table-card">
            <div class="table-wrap">
                <table id="loanTable">
                    <thead>
                        <tr>
                            <th>Client Details</th>
                            <th>Financials</th>
                            <th style="width:25%">Progress</th>
                            <th>Status</th>
                            <th style="text-align:right">Action</th>
                        </tr>
                    </thead>
                    <tbody id="tableBody"></tbody>
                </table>
                <div id="emptyState" style="text-align:center; padding:3rem; color:var(--text-muted); display:none">
                    <i class="fas fa-inbox fa-3x" style="opacity:0.3; margin-bottom:1rem"></i>
                    <p>No loans found matching your criteria.</p>
                </div>
            </div>

            <div class="pagination" id="paginationControls">
                <div style="font-size:0.9rem; color:var(--text-muted)">
                    Showing <span id="pgStart">0</span> - <span id="pgEnd">0</span> of <span id="pgTotal">0</span>
                </div>
                <div class="page-nums">
                    <button id="btnPrev" class="page-btn"><i class="fas fa-chevron-left"></i></button>
                    <button id="btnNext" class="page-btn"><i class="fas fa-chevron-right"></i></button>
                </div>
            </div>
        </div>
    </main>

    <!-- NEW IMPROVED MODAL -->
    <div id="editModal" class="modal-overlay">
        <div class="modal-box">
            <!-- Header with Context -->
            <div class="modal-top">
                <div class="modal-client-name" id="displayClientName">Client Name</div>
                <div class="modal-sub-info">
                    <span>Total Loan Amount:</span>
                    <span id="displayTotalPayable" style="font-weight:700; color:var(--text-main)">₦0.00</span>
                </div>
            </div>

            <div class="modal-body">
                <!-- Hero Balance Input -->
                <label class="balance-wrapper">
                    <div style="display:flex; justify-content:space-between; align-items:center">
                        <span class="balance-label">Remaining Balance</span>
                        <button type="button" class="btn-mark-paid" onclick="setZero()">
                            <i class="fas fa-check"></i> Mark Fully Paid
                        </button>
                    </div>
                    <div class="input-row">
                        <span class="currency-symbol">₦</span>
                        <input type="number" id="editRemainingBalance" class="hero-input" placeholder="0.00" step="0.01">
                    </div>
                </label>

                <!-- Status Segmented Control -->
                <span class="balance-label" style="margin-bottom:0.5rem">Loan Status</span>
                <div class="status-segment">
                    <label class="segment-opt" id="lblActive" onclick="selectStatus('active')" data-val="active">
                        <input type="radio" name="status_opt" value="active" class="status-radio">
                        <i class="fas fa-sync-alt"></i> Active
                    </label>
                    <label class="segment-opt" id="lblCompleted" onclick="selectStatus('completed')" data-val="completed">
                        <input type="radio" name="status_opt" value="completed" class="status-radio">
                        <i class="fas fa-check-circle"></i> Completed
                    </label>
                </div>
                
                <!-- Hidden Input to store selected status for JS logic -->
                <input type="hidden" id="editStatus" value="active">

                <!-- Footer Buttons -->
                <div class="modal-actions">
                    <button class="btn btn-cancel" onclick="closeModal()">Cancel</button>
                    <button class="btn-primary" id="saveBtn" onclick="saveLoan()">
                        Update Loan
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        // --- Theme Logic ---
        const themeBtn = document.getElementById('themeToggle');
        const html = document.documentElement;
        if(localStorage.getItem('theme') === 'dark') { html.classList.add('dark'); themeBtn.innerHTML = '<i class="fas fa-sun"></i>'; }
        
        themeBtn.onclick = () => {
            html.classList.toggle('dark');
            const isDark = html.classList.contains('dark');
            localStorage.setItem('theme', isDark ? 'dark' : 'light');
            themeBtn.innerHTML = isDark ? '<i class="fas fa-sun"></i>' : '<i class="fas fa-moon"></i>';
        };

        // --- App Logic ---
        let currentLoanIndex = -1;
        let currentLoanId = null; // Store actual loan ID
        let currentPage = 1;
        const formatter = new Intl.NumberFormat('en-NG', { style: 'currency', currency: 'NGN', minimumFractionDigits: 0 });

        function showToast(msg, type='success') {
            const toast = document.getElementById('toast');
            toast.className = `toast ${type}`;
            toast.innerHTML = `<i class="fas fa-${type==='success'?'check-circle':'exclamation-circle'}"></i> ${msg}`;
            toast.classList.add('show');
            setTimeout(() => toast.classList.remove('show'), 3000);
        }

        function loadLoans(page = 1) {
            currentPage = page;
            const status = document.getElementById('statusFilter').value;
            const search = document.getElementById('searchInput').value;
            const tbody = document.getElementById('tableBody');
            
            tbody.innerHTML = '<tr><td colspan="5" style="text-align:center; padding:3rem; color:var(--text-muted)"><i class="fas fa-circle-notch fa-spin"></i> Loading...</td></tr>';

            fetch(`?action=get_loans&page=${page}&status=${status}&search=${encodeURIComponent(search)}`)
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        renderTable(data.loans);
                        renderPagination(data.pagination);
                        document.getElementById('totalRecords').innerText = data.pagination.total_records;
                    } else {
                        showToast(data.message || 'Failed to load data', 'error');
                    }
                })
                .catch(() => showToast('Failed to load data', 'error'));
        }

        function renderTable(loans) {
            const tbody = document.getElementById('tableBody');
            tbody.innerHTML = '';

            if (loans.length === 0) {
                document.getElementById('emptyState').style.display = 'block';
                document.getElementById('paginationControls').style.display = 'none';
                return;
            }
            document.getElementById('emptyState').style.display = 'none';
            document.getElementById('paginationControls').style.display = 'flex';

            loans.forEach(loan => {
                const total = parseFloat(loan.total_payable);
                const rem = parseFloat(loan.remaining_balance);
                const paid = total - rem;
                const pct = total > 0 ? Math.min(100, Math.max(0, (paid/total)*100)) : 0;
                
                let color = pct >= 100 ? '#10b981' : (pct < 20 ? '#ef4444' : '#3b82f6');
                const initials = loan.client_name.charAt(0);

                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td>
                        <div class="client-cell">
                            <div class="avatar-initials">${initials}</div>
                            <div>
                                <div style="font-weight:600">${loan.client_name}</div>
                                <div style="font-size:0.8rem; color:var(--text-muted)">Off: ${loan.officer}</div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <div style="font-weight:600">${formatter.format(rem)}</div>
                        <div style="font-size:0.8rem; color:var(--text-muted)">of ${formatter.format(total)}</div>
                    </td>
                    <td>
                        <div class="progress-bar"><div class="progress-fill" style="width:${pct}%; background:${color}"></div></div>
                        <div style="font-size:0.75rem; text-align:right; margin-top:2px; font-weight:600; color:var(--text-muted)">${pct.toFixed(0)}% Paid</div>
                    </td>
                    <td>
                        <span class="status-badge ${loan.status === 'active' ? 'active-badge' : 'completed-badge'}">
                            ${loan.status === 'active' ? '<i class="fas fa-sync-alt fa-spin" style="font-size:0.7rem; margin-right:4px"></i> Active' : '<i class="fas fa-check"></i> Paid'}
                        </span>
                    </td>
                    <td style="text-align:right">
                        <button class="icon-btn" onclick="openEdit(${loan.index}, '${loan.transaction_id}', '${loan.client_name.replace(/'/g, "\\'")}', ${rem}, ${total}, '${loan.status}')"><i class="fas fa-pen"></i></button>
                    </td>
                `;
                tbody.appendChild(tr);
            });
        }

        function renderPagination(pg) {
            document.getElementById('pgStart').innerText = pg.start;
            document.getElementById('pgEnd').innerText = pg.end;
            document.getElementById('pgTotal').innerText = pg.total_records;
            
            const prev = document.getElementById('btnPrev');
            const next = document.getElementById('btnNext');
            
            prev.disabled = pg.current_page <= 1;
            next.disabled = pg.current_page >= pg.total_pages;
            
            prev.onclick = () => loadLoans(pg.current_page - 1);
            next.onclick = () => loadLoans(pg.current_page + 1);
        }

        // --- NEW MODAL LOGIC ---
        function openEdit(idx, loanId, name, bal, total, stat) {
            currentLoanIndex = idx;
            currentLoanId = loanId; // Store the actual loan ID
            
            // Populate Header and Inputs
            document.getElementById('displayClientName').innerText = name;
            document.getElementById('displayTotalPayable').innerText = formatter.format(total);
            document.getElementById('editRemainingBalance').value = bal;
            
            // Set Initial Status State
            selectStatus(stat);

            // Open Modal with Animation
            const modal = document.getElementById('editModal');
            modal.style.display = 'flex';
            setTimeout(() => modal.classList.add('open'), 10);
            
            // Focus Input
            document.getElementById('editRemainingBalance').focus();
        }

        function closeModal() {
            const modal = document.getElementById('editModal');
            modal.classList.remove('open');
            setTimeout(() => modal.style.display = 'none', 300);
        }

        function selectStatus(val) {
            document.getElementById('editStatus').value = val;
            
            const lblActive = document.getElementById('lblActive');
            const lblCompleted = document.getElementById('lblCompleted');
            
            if (val === 'active') {
                lblActive.classList.add('selected');
                lblCompleted.classList.remove('selected');
            } else {
                lblActive.classList.remove('selected');
                lblCompleted.classList.add('selected');
            }
        }

        function setZero() {
            document.getElementById('editRemainingBalance').value = 0;
            selectStatus('completed');
            
            // Visual feedback
            const wrapper = document.querySelector('.balance-wrapper');
            wrapper.style.borderColor = '#10b981';
            setTimeout(() => wrapper.style.borderColor = '', 300);
        }

        function saveLoan() {
            if (currentLoanIndex === -1 || !currentLoanId) return;
            const bal = parseFloat(document.getElementById('editRemainingBalance').value);
            const stat = document.getElementById('editStatus').value;
            const btn = document.getElementById('saveBtn');
            
            if(bal < 0) { showToast("Balance cannot be negative", "error"); return; }
            
            btn.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i> Saving...';
            btn.disabled = true;

            fetch('?action=update_loan', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ 
                    index: currentLoanIndex,
                    loan_id: currentLoanId, // Send actual loan ID
                    remaining_balance: bal, 
                    status: stat 
                })
            }).then(r => r.json()).then(d => {
                if (d.success) { 
                    closeModal(); 
                    loadLoans(currentPage); 
                    showToast('Loan updated successfully'); 
                } else {
                    showToast(d.message || 'Update failed', 'error');
                }
            }).catch(() => {
                showToast('Connection error', 'error');
            }).finally(() => { 
                btn.innerHTML = 'Update Loan'; 
                btn.disabled = false; 
            });
        }

        // --- Init ---
        document.addEventListener('DOMContentLoaded', () => {
            loadLoans(1);
            
            const debounce = (func, wait) => {
                let timeout;
                return (...args) => { clearTimeout(timeout); timeout = setTimeout(() => func.apply(this, args), wait); };
            };

            document.getElementById('statusFilter').addEventListener('change', () => loadLoans(1));
            document.getElementById('searchInput').addEventListener('input', debounce(() => loadLoans(1), 400));
            
            // Close modal on click outside
            window.onclick = (e) => { 
                if(e.target.classList.contains('modal-overlay')) closeModal(); 
            };

            // Smart Status Switcher
            document.getElementById('editRemainingBalance').addEventListener('input', function() {
                const val = parseFloat(this.value);
                if (val <= 0.01) selectStatus('completed');
                else selectStatus('active');
            });
        });
    </script>
</body>
</html>