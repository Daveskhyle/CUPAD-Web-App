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

// --- 1. SECURITY CHECK ---
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: ../index.php');
    exit();
}

// --- 2. BACKEND LOGIC CLASS ---
class DeletedUsersManager {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    public function searchDeletedUsers($search, $from, $to) {
        $sql = "SELECT * FROM deleted_transactions WHERE transaction_type = 'user' ";
        $params = [];
        
        if (!empty($search)) {
            $sql .= "AND (JSON_EXTRACT(transaction_data, '$.name') LIKE :search 
                     OR JSON_EXTRACT(transaction_data, '$.phone') LIKE :search 
                     OR original_id LIKE :search) ";
            $params[':search'] = '%' . $search . '%';
        }
        
        if (!empty($from)) {
            $sql .= "AND DATE(deleted_at) >= :from_date ";
            $params[':from_date'] = $from;
        }
        
        if (!empty($to)) {
            $sql .= "AND DATE(deleted_at) <= :to_date ";
            $params[':to_date'] = $to;
        }
        
        $sql .= "ORDER BY deleted_at DESC";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $deleted = $stmt->fetchAll();
        
        // Format data to match old JSON structure
        $result = [];
        foreach ($deleted as $row) {
            $data = json_decode($row['transaction_data'], true);
            $result[] = [
                'deletion_id' => $row['id'],
                'original_id' => $row['original_id'],
                'deleted_at' => $row['deleted_at'],
                'deleted_by' => $row['deleted_by'],
                'name' => $data['name'] ?? 'Unknown',
                'phone' => $data['phone'] ?? 'N/A',
                'union' => $data['union'] ?? 'N/A',
                'officer_name' => $data['officer_name'] ?? '-',
                'id' => $data['id'] ?? $row['original_id']
            ];
        }
        
        return $result;
    }

    public function restoreUser($deletionId) {
        // Get deleted user data
        $stmt = $this->pdo->prepare("SELECT * FROM deleted_transactions WHERE id = ? AND transaction_type = 'user'");
        $stmt->execute([$deletionId]);
        $deleted = $stmt->fetch();
        
        if (!$deleted) return false;
        
        $userData = json_decode($deleted['transaction_data'], true);
        if (!$userData) return false;
        
        // Remove deletion metadata
        unset($userData['deletion_id'], $userData['deleted_at'], $userData['deleted_by']);
        $userData['restored_at'] = date('Y-m-d H:i:s');
        $userData['status'] = 'active';
        
        // Check if user already exists in users table
        $checkStmt = $this->pdo->prepare("SELECT id FROM users WHERE id = ? OR username = ?");
        $checkStmt->execute([$userData['id'] ?? 0, $userData['username'] ?? '']);
        if ($checkStmt->fetch()) {
            // Update existing user
            $fields = [];
            $values = [];
            foreach ($userData as $key => $val) {
                if ($key !== 'id' && $key !== 'created_at') {
                    $fields[] = "$key = :$key";
                    $values[":$key"] = $val;
                }
            }
            $values[':id'] = $userData['id'];
            $sql = "UPDATE users SET " . implode(', ', $fields) . " WHERE id = :id";
            $updateStmt = $this->pdo->prepare($sql);
            $updateStmt->execute($values);
        } else {
            // Insert new user
            $fields = array_keys($userData);
            $placeholders = array_map(function($f) { return ":$f"; }, $fields);
            $sql = "INSERT INTO users (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")";
            $insertStmt = $this->pdo->prepare($sql);
            $insertStmt->execute($userData);
        }
        
        // Delete from deleted_transactions
        $delStmt = $this->pdo->prepare("DELETE FROM deleted_transactions WHERE id = ?");
        return $delStmt->execute([$deletionId]);
    }

    public function permanentlyDelete($deletionId) {
        $stmt = $this->pdo->prepare("DELETE FROM deleted_transactions WHERE id = ? AND transaction_type = 'user'");
        return $stmt->execute([$deletionId]);
    }

    public function getSavingsHistory($originalUserId) {
        // Get savings history from saving_collections table
        $stmt = $this->pdo->prepare("
            SELECT sc.*, s.client_name 
            FROM saving_collections sc 
            LEFT JOIN savings s ON sc.savings_id = s.id 
            WHERE sc.client_id = ? 
            ORDER BY sc.date DESC
        ");
        $stmt->execute([$originalUserId]);
        $collections = $stmt->fetchAll();
        
        $result = [];
        foreach ($collections as $row) {
            $result[] = [
                'id' => $row['transaction_id'],
                'client_id' => $row['client_id'],
                'client_name' => $row['client_name'],
                'amount' => $row['amount'],
                'type' => $row['type'],
                'date' => $row['date'],
                'balance_after' => $row['balance_after'],
                'notes' => $row['notes']
            ];
        }
        
        return $result;
    }
    
    public function logActivity($user, $action, $details, $ipAddress) {
        $stmt = $this->pdo->prepare("
            INSERT INTO activity_log (user, action, details, ip_address, timestamp) 
            VALUES (?, ?, ?, ?, NOW())
        ");
        return $stmt->execute([$user, $action, $details, $ipAddress]);
    }
}

$manager = new DeletedUsersManager($pdo);

// Handle AJAX request for savings
if (isset($_GET['ajax_savings'])) {
    header('Content-Type: application/json');
    $origId = $_GET['original_id'] ?? '';
    $history = $manager->getSavingsHistory($origId);
    echo json_encode(array_values($history));
    exit();
}

$message = ''; 
$messageType = '';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id = $_POST['deletion_id'] ?? '';

    if ($action === 'restore') {
        if ($manager->restoreUser($id)) {
            // Log the restore action
            $manager->logActivity(
                $_SESSION['username'] ?? 'system',
                'restore_user',
                "Restored deleted user with deletion ID: $id",
                $_SERVER['REMOTE_ADDR']
            );
            $message = "User restored successfully.";
            $messageType = "success";
        } else {
            $message = "Failed to restore user.";
            $messageType = "error";
        }
    } elseif ($action === 'permanent_delete') {
        if ($manager->permanentlyDelete($id)) {
            // Log the permanent deletion
            $manager->logActivity(
                $_SESSION['username'] ?? 'system',
                'permanent_delete_user',
                "Permanently deleted user record with ID: $id",
                $_SERVER['REMOTE_ADDR']
            );
            $message = "Record permanently deleted.";
            $messageType = "success";
        } else {
            $message = "Failed to delete record.";
            $messageType = "error";
        }
    }
}

// Get search parameters
$searchTerm = $_GET['search'] ?? '';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$deletedUsers = $manager->searchDeletedUsers($searchTerm, $dateFrom, $dateTo);

// Get current user info from database
$full_name = $_SESSION['full_name'] ?? 'Admin';
$user_role = $_SESSION['role'] ?? 'Admin';
$my_profile_pic = '';

if (isset($_SESSION['username'])) {
    $userStmt = $pdo->prepare("SELECT profile_pic FROM users WHERE username = ?");
    $userStmt->execute([$_SESSION['username']]);
    $userData = $userStmt->fetch();
    if ($userData) {
        $my_profile_pic = $userData['profile_pic'] ?? '';
    }
}
?>

<!DOCTYPE html>
<html lang="en" class="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Deleted Users - CUPAD Admin</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />
    <style>
        :root {
            --primary: #2563eb; --primary-dark: #1d4ed8;
            --success: #059669; --warning: #d97706; --danger: #dc2626;
            --bg-body: #f8fafc; --bg-surface: #ffffff;
            --text-main: #0f172a; --text-muted: #64748b;
            --border: #e2e8f0; --radius-lg: 12px; --nav-height: 70px;
            --shadow-sm: 0 1px 3px 0 rgb(0 0 0 / 0.1);
        }
        html.dark {
            --bg-body: #0f172a; --bg-surface: #1e293b;
            --text-main: #f8fafc; --text-muted: #94a3b8;
            --border: #334155; --primary: #3b82f6;
        }
        * { box-sizing: border-box; outline: none; }
        body { font-family: 'Plus Jakarta Sans', sans-serif; background: var(--bg-body); color: var(--text-main); margin: 0; padding-top: var(--nav-height); transition: 0.3s; font-size: 0.9rem; }
        a { text-decoration: none; color: inherit; }

        /* HEADER */
        .main-header { position: fixed; top: 0; left: 0; right: 0; height: var(--nav-height); background: rgba(255,255,255,0.9); backdrop-filter: blur(8px); border-bottom: 1px solid var(--border); z-index: 50; }
        html.dark .main-header { background: rgba(15, 23, 42, 0.9); }
        .navbar { max-width: 1400px; margin: 0 auto; padding: 0 1.5rem; display: flex; align-items: center; justify-content: space-between; height: 100%; }
        .logo { display: flex; align-items: center; gap: 0.75rem; font-weight: 800; font-size: 1.35rem; color: var(--primary); }
        .nav-right { display: flex; align-items: center; gap: 1rem; }

        .theme-btn { width: 36px; height: 36px; border-radius: 50%; border: 1px solid var(--border); background: var(--bg-surface); color: var(--text-muted); cursor: pointer; display: flex; align-items: center; justify-content: center; transition: 0.2s; }
        .theme-btn:hover { color: var(--primary); border-color: var(--primary); }

        .user-pill { display: flex; align-items: center; gap: 0.75rem; padding: 4px 8px 4px 4px; border: 1px solid var(--border); border-radius: 99px; background: var(--bg-surface); }
        .user-avatar-fallback { width: 32px; height: 32px; border-radius: 50%; background: var(--bg-body); color: var(--text-muted); display: flex; align-items: center; justify-content: center; font-size: 0.9rem; }
        .user-info { display: flex; flex-direction: column; line-height: 1.1; padding-right: 0.5rem; }
        .user-name { font-weight: 600; font-size: 0.85rem; }
        
        /* CONTENT */
        .container { max-width: 1400px; margin: 0 auto; padding: 2rem 1.5rem; }
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; }
        .page-title { font-size: 1.5rem; font-weight: 700; display: flex; align-items: center; gap: 0.75rem; }
        .btn-back { padding: 0.5rem 1rem; border-radius: 8px; border: 1px solid var(--border); background: var(--bg-surface); color: var(--text-main); font-weight: 600; font-size: 0.85rem; display: flex; align-items: center; gap: 0.5rem; transition: 0.2s; }
        .btn-back:hover { border-color: var(--primary); color: var(--primary); }

        /* SEARCH BAR */
        .search-container { background: var(--bg-surface); border: 1px solid var(--border); border-radius: var(--radius-lg); padding: 1.25rem; margin-bottom: 1.5rem; display: flex; gap: 1rem; flex-wrap: wrap; align-items: end; }
        .form-group { flex: 1; min-width: 200px; }
        .form-group label { display: block; font-size: 0.8rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.4rem; }
        .form-input { width: 100%; padding: 0.65rem 1rem; border-radius: 8px; border: 1px solid var(--border); background: var(--bg-body); color: var(--text-main); transition: 0.2s; font-size: 0.9rem; }
        .form-input:focus { border-color: var(--primary); outline: none; box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1); }
        .btn-search { padding: 0.65rem 1.5rem; background: var(--primary); color: white; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; transition: 0.2s; display: flex; align-items: center; gap: 0.5rem; }
        .btn-search:hover { background: var(--primary-dark); }

        /* TABLE */
        .table-card { background: var(--bg-surface); border: 1px solid var(--border); border-radius: var(--radius-lg); overflow: hidden; box-shadow: var(--shadow-sm); }
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: separate; border-spacing: 0; }
        th { text-align: left; padding: 1rem 1.5rem; font-size: 0.75rem; text-transform: uppercase; color: var(--text-muted); font-weight: 600; border-bottom: 1px solid var(--border); background: var(--bg-body); }
        td { padding: 1rem 1.5rem; border-bottom: 1px solid var(--border); vertical-align: middle; }
        tr:last-child td { border-bottom: none; }
        tr:hover td { background: var(--bg-body); }

        /* TABLE ELEMENTS */
        .user-meta { display: flex; flex-direction: column; }
        .meta-name { font-weight: 600; font-size: 0.95rem; }
        .meta-id { font-size: 0.8rem; color: var(--text-muted); font-family: monospace; }
        .badge { padding: 2px 8px; border-radius: 6px; font-size: 0.75rem; font-weight: 600; background: rgba(59, 130, 246, 0.1); color: var(--primary); }
        .badge-gray { background: rgba(100, 116, 139, 0.1); color: var(--text-muted); }
        
        .actions { display: flex; justify-content: flex-end; gap: 0.5rem; }
        .action-btn { width: 32px; height: 32px; border-radius: 8px; display: flex; align-items: center; justify-content: center; border: 1px solid transparent; cursor: pointer; transition: 0.2s; background: transparent; color: var(--text-muted); }
        .action-btn:hover { background: var(--bg-body); color: var(--text-main); border-color: var(--border); }
        .btn-restore:hover { color: var(--success); background: rgba(16, 185, 129, 0.1); border-color: transparent; }
        .btn-delete:hover { color: var(--danger); background: rgba(239, 68, 68, 0.1); border-color: transparent; }

        /* MODAL */
        .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 100; display: none; align-items: center; justify-content: center; backdrop-filter: blur(2px); }
        .modal-box { background: var(--bg-surface); padding: 2rem; border-radius: var(--radius-lg); width: 90%; max-width: 450px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1); animation: popIn 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275); transform: scale(0.95); opacity: 0; }
        .modal-overlay.show .modal-box { transform: scale(1); opacity: 1; }
        @keyframes popIn { from { transform: scale(0.95); opacity: 0; } to { transform: scale(1); opacity: 1; } }
        
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; }
        .modal-title { font-size: 1.25rem; font-weight: 700; margin: 0; }
        .close-modal { background: none; border: none; font-size: 1.5rem; line-height: 1; color: var(--text-muted); cursor: pointer; }
        
        .confirm-body { text-align: center; padding: 1rem 0; }
        .confirm-icon { font-size: 3rem; color: var(--danger); margin-bottom: 1rem; display: block; }
        .modal-footer { display: flex; justify-content: center; gap: 1rem; margin-top: 1.5rem; }

        /* TOAST */
        .toast-container { position: fixed; bottom: 20px; right: 20px; z-index: 200; display: flex; flex-direction: column; gap: 10px; }
        .toast { padding: 12px 20px; background: var(--bg-surface); border-left: 4px solid var(--primary); box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); border-radius: 8px; display: flex; align-items: center; gap: 10px; transform: translateX(120%); transition: 0.3s; min-width: 300px; font-weight: 500; }
        .toast.show { transform: translateX(0); }
        .toast.success { border-color: var(--success); } .toast.error { border-color: var(--danger); }

        /* Empty State */
        .empty-state { text-align: center; padding: 4rem 1rem; color: var(--text-muted); }
        .empty-icon { font-size: 3rem; margin-bottom: 1rem; opacity: 0.3; }
    </style>
</head>
<body>

    <!-- Header -->
    <header class="main-header">
        <div class="navbar">
            <a href="dashboard.php" class="logo">
                <img src="../uploads/CUPAD LOGO.png" style="height:38px;" alt="CUPAD">
                <span>CUPAD</span>
            </a>
            <div class="nav-right">
                <button id="themeToggle" class="theme-btn" title="Switch Theme"><i class="fas fa-moon"></i></button>
                <div class="user-pill">
                    <?php if(!empty($my_profile_pic)): ?>
                        <img src="../<?php echo htmlspecialchars($my_profile_pic); ?>" style="width:32px; height:32px; border-radius:50%; object-fit:cover;">
                    <?php else: ?>
                        <div class="user-avatar-fallback"><i class="fas fa-user"></i></div>
                    <?php endif; ?>
                    <div class="user-info">
                        <span class="user-name"><?php echo htmlspecialchars($full_name); ?></span>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <main class="container">
        
        <div class="page-header">
            <div class="page-title">
                <span style="width:40px; height:40px; background:var(--bg-surface); border:1px solid var(--border); border-radius:10px; display:flex; align-items:center; justify-content:center; color:var(--text-muted)">
                    <i class="fas fa-trash-restore"></i>
                </span>
                Deleted Users Manager
            </div>
            <a href="dashboard.php" class="btn-back"><i class="fas fa-arrow-left"></i> Dashboard</a>
        </div>

        <!-- Search Form -->
        <form method="GET" class="search-container">
            <div class="form-group">
                <label>Search Query</label>
                <input type="text" name="search" value="<?php echo htmlspecialchars($searchTerm); ?>" placeholder="Name, phone, or deletion ID..." class="form-input">
            </div>
            <div class="form-group" style="flex:0 0 180px">
                <label>From Date</label>
                <input type="date" name="date_from" value="<?php echo htmlspecialchars($dateFrom); ?>" class="form-input">
            </div>
            <div class="form-group" style="flex:0 0 180px">
                <label>To Date</label>
                <input type="date" name="date_to" value="<?php echo htmlspecialchars($dateTo); ?>" class="form-input">
            </div>
            <div class="form-group" style="flex:0 0 auto; padding-bottom:1px">
                <label>&nbsp;</label>
                <button type="submit" class="btn-search"><i class="fas fa-search"></i> Filter</button>
            </div>
        </form>

        <!-- Data Table -->
        <div class="table-card">
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>User Details</th>
                            <th>Officer / Union</th>
                            <th>Deletion Info</th>
                            <th>Date Deleted</th>
                            <th style="text-align:right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($deletedUsers)): ?>
                            <tr>
                                <td colspan="5">
                                    <div class="empty-state">
                                        <i class="fas fa-folder-open empty-icon"></i>
                                        <h3>No deleted records found</h3>
                                        <p>Try adjusting your search filters.</p>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($deletedUsers as $user): 
                                $origId = $user['original_id'] ?? '';
                            ?>
                                <tr>
                                    <td>
                                        <div class="user-meta">
                                            <span class="meta-name"><?php echo htmlspecialchars($user['name'] ?? 'Unknown'); ?></span>
                                            <span class="meta-id">Phone: <?php echo htmlspecialchars($user['phone'] ?? 'N/A'); ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="user-meta">
                                            <span style="font-weight:500"><?php echo htmlspecialchars($user['union'] ?? 'N/A'); ?></span>
                                            <span style="font-size:0.8rem; color:var(--text-muted)">Off: <?php echo htmlspecialchars($user['officer_name'] ?? '-'); ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge-gray">By: <?php echo htmlspecialchars($user['deleted_by'] ?? 'System'); ?></span>
                                        <div style="font-size:0.75rem; color:var(--text-muted); margin-top:4px">ID: #<?php echo htmlspecialchars($user['deletion_id']); ?></div>
                                    </td>
                                    <td style="color:var(--text-muted); font-size:0.85rem">
                                        <i class="far fa-clock"></i> <?php echo date('M j, Y h:i A', strtotime($user['deleted_at'])); ?>
                                    </td>
                                    <td>
                                        <div class="actions">
                                            <button class="action-btn" title="View Savings" onclick="viewSavings('<?php echo $origId; ?>', '<?php echo htmlspecialchars($user['name'] ?? 'User'); ?>')">
                                                <i class="fas fa-chart-bar"></i>
                                            </button>
                                            
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="action" value="restore">
                                                <input type="hidden" name="deletion_id" value="<?php echo $user['deletion_id']; ?>">
                                                <button type="submit" class="action-btn btn-restore" title="Restore User">
                                                    <i class="fas fa-undo"></i>
                                                </button>
                                            </form>

                                            <button class="action-btn btn-delete" title="Permanently Delete" onclick="confirmDelete('<?php echo $user['deletion_id']; ?>')">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <!-- Savings Modal -->
    <div id="savingsModal" class="modal-overlay">
        <div class="modal-box">
            <div class="modal-header">
                <h3 class="modal-title" id="savingsModalTitle">Savings History</h3>
                <button class="close-modal" onclick="closeModal('savingsModal')">&times;</button>
            </div>
            <div class="modal-body" id="savingsModalContent" style="max-height:400px; overflow-y:auto">
                <div style="text-align:center; padding:2rem; color:var(--text-muted)">
                    <i class="fas fa-circle-notch fa-spin"></i> Loading...
                </div>
            </div>
        </div>
    </div>

    <!-- Delete Modal -->
    <div id="deleteModal" class="modal-overlay">
        <div class="modal-box" style="max-width:400px">
            <div class="confirm-body">
                <i class="fas fa-exclamation-circle confirm-icon"></i>
                <h3 style="margin-bottom:0.5rem">Permanently Delete?</h3>
                <p style="color:var(--text-muted); font-size:0.9rem">This action cannot be undone. All user data will be lost forever.</p>
                
                <form method="POST" id="deleteForm">
                    <input type="hidden" name="action" value="permanent_delete">
                    <input type="hidden" name="deletion_id" id="deleteInputId">
                    <div class="modal-footer">
                        <button type="button" class