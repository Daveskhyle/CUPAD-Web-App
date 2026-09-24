<?php
/**
 * Manage Clients
 * Styled to match Dashboard.php UI/UX
 */

// 1. SESSION & CONFIG
session_start();
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: ../index.php');
    exit();
}

require_once '../includes/config.php';

// CSRF Token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$pdo = getDbConnection();
$base_path = '../'; 

// ==========================================
//        BACKEND HELPER FUNCTIONS
// ==========================================

function getBranchMap($pdo) {
    $stmt = $pdo->query("SELECT id, name FROM branches");
    return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
}

function getFilteredClients($pdo, $page, $limit, $search, $unionFilter, $officerFilter, $savingsFilter, $branchFilter, $statusFilter, $planTypeFilter, $returnAll = false) {
    $offset = ($page - 1) * $limit;
    $params = [];
    
    // Check if the user wants to see deleted clients
    if ($statusFilter === 'deleted') {
        $whereClauses = ["c.deleted_at IS NOT NULL"];
    } else {
        $whereClauses = ["c.deleted_at IS NULL"];
        if ($statusFilter === 'active' || $statusFilter === 'inactive') {
            $whereClauses[] = "c.status = ?";
            $params[] = $statusFilter;
        }
    }

    if (!empty($search)) {
        $whereClauses[] = "(c.name LIKE ? OR c.id LIKE ? OR c.phone LIKE ?)";
        $searchParam = "%$search%";
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
    }

    if (!empty($unionFilter)) {
        $whereClauses[] = "c.`union` = ?";
        $params[] = $unionFilter;
    }

    if (!empty($officerFilter)) {
        $whereClauses[] = "c.officer_username = ?";
        $params[] = $officerFilter;
    }

    if (!empty($branchFilter)) {
        $whereClauses[] = "c.branch_id = ?";
        $params[] = $branchFilter;
    }

    if (!empty($planTypeFilter)) {
        $whereClauses[] = "c.client_type = ?";
        $params[] = $planTypeFilter;
    }

    if (!empty($savingsFilter)) {
        if ($savingsFilter === 'with-savings') {
            $whereClauses[] = "EXISTS (SELECT 1 FROM savings s WHERE s.client_id = c.id AND s.balance > 0)";
        } elseif ($savingsFilter === 'without-savings') {
            $whereClauses[] = "NOT EXISTS (SELECT 1 FROM savings s WHERE s.client_id = c.id AND s.balance > 0)";
        }
    }

    $whereSql = implode(" AND ", $whereClauses);

    $countSql = "SELECT COUNT(*) FROM clients c WHERE $whereSql";
    $stmt = $pdo->prepare($countSql);
    $stmt->execute($params);
    $totalClients = $stmt->fetchColumn();

    $sql = "
        SELECT 
            c.*,
            b.name as branch_name,
            u.full_name as officer_full_name,
            (SELECT COALESCE(SUM(CASE WHEN type = 'deposit' THEN amount ELSE 0 END) - SUM(CASE WHEN type IN ('cash', 'withdrawal', 'return') THEN ABS(amount) ELSE 0 END), 0) 
             FROM saving_collections WHERE client_id = c.id) as savings_total,
            (SELECT SUM(remaining_balance) FROM disbursements WHERE client_id = c.id AND status = 'active') as active_loan_balance
        FROM clients c
        LEFT JOIN branches b ON c.branch_id = b.id
        LEFT JOIN users u ON c.officer_username = u.username
        WHERE $whereSql
        ORDER BY c.created_at DESC
    ";

    if (!$returnAll) {
        $sql .= " LIMIT " . (int)$limit . " OFFSET " . (int)$offset;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $clients = $stmt->fetchAll();

    foreach ($clients as &$client) {
        $client['savings_total'] = (float)($client['savings_total'] ?? 0);
        $client['has_savings'] = $client['savings_total'] > 0;
        $client['active_loan_balance'] = (float)($client['active_loan_balance'] ?? 0);
        $client['has_active_loans'] = $client['active_loan_balance'] > 1;
        $client['officer_name'] = $client['officer_full_name'] ?? $client['officer_username'] ?? 'Unassigned';
        $client['branch_name'] = $client['branch_name'] ?? 'Unknown';
    }

    if ($returnAll) return $clients;

    $totalPages = ceil($totalClients / $limit);

    return [
        'clients' => $clients,
        'pagination' => [
            'current_page' => $page,
            'per_page' => $limit,
            'total' => $totalClients,
            'total_pages' => $totalPages,
            'from' => $totalClients > 0 ? $offset + 1 : 0,
            'to' => min($offset + $limit, $totalClients),
            'has_prev' => $page > 1,
            'has_next' => $page < $totalPages
        ]
    ];
}

// ==========================================
//           AJAX REQUEST HANDLER
// ==========================================

$action = $_GET['action'] ?? ($_POST['action'] ?? null);

if ($action) {
    try {
        if ($action === 'load_clients') {
            header('Content-Type: application/json');
            
            $page = intval($_GET['page'] ?? 1);
            $limit = intval($_GET['limit'] ?? 20);
            $search = trim($_GET['search'] ?? '');
            $unionFilter = trim($_GET['union'] ?? '');
            $officerFilter = trim($_GET['officer'] ?? '');
            $savingsFilter = trim($_GET['savings'] ?? '');
            $branchFilter = trim($_GET['branch'] ?? '');
            $statusFilter = trim($_GET['status'] ?? '');
            $planTypeFilter = trim($_GET['planType'] ?? '');

            $result = getFilteredClients(
                $pdo, $page, $limit, $search, $unionFilter, $officerFilter, 
                $savingsFilter, $branchFilter, $statusFilter, $planTypeFilter
            );

            // Re-build WHERE clauses to generate dynamic summary card stats matching the active filters
            $params = [];
            if ($statusFilter === 'deleted') {
                $whereClauses = ["c.deleted_at IS NOT NULL"];
            } else {
                $whereClauses = ["c.deleted_at IS NULL"];
                if ($statusFilter === 'active' || $statusFilter === 'inactive') {
                    $whereClauses[] = "c.status = ?";
                    $params[] = $statusFilter;
                }
            }

            if (!empty($search)) {
                $whereClauses[] = "(c.name LIKE ? OR c.id LIKE ? OR c.phone LIKE ?)";
                $searchParam = "%$search%";
                $params[] = $searchParam; $params[] = $searchParam; $params[] = $searchParam;
            }

            if (!empty($unionFilter)) { $whereClauses[] = "c.`union` = ?"; $params[] = $unionFilter; }
            if (!empty($officerFilter)) { $whereClauses[] = "c.officer_username = ?"; $params[] = $officerFilter; }
            if (!empty($branchFilter)) { $whereClauses[] = "c.branch_id = ?"; $params[] = $branchFilter; }
            if (!empty($planTypeFilter)) { $whereClauses[] = "c.client_type = ?"; $params[] = $planTypeFilter; }
            if (!empty($savingsFilter)) {
                if ($savingsFilter === 'with-savings') {
                    $whereClauses[] = "EXISTS (SELECT 1 FROM savings s WHERE s.client_id = c.id AND s.balance > 0)";
                } elseif ($savingsFilter === 'without-savings') {
                    $whereClauses[] = "NOT EXISTS (SELECT 1 FROM savings s WHERE s.client_id = c.id AND s.balance > 0)";
                }
            }

            $whereSql = implode(" AND ", $whereClauses);

            $stmtU = $pdo->prepare("SELECT COUNT(DISTINCT `union`) FROM clients c WHERE $whereSql AND `union` != '' AND `union` IS NOT NULL");
            $stmtU->execute($params);

            $stmtO = $pdo->prepare("SELECT COUNT(DISTINCT officer_username) FROM clients c WHERE $whereSql AND officer_username IS NOT NULL AND officer_username != ''");
            $stmtO->execute($params);

            $stmtB = $pdo->prepare("SELECT COUNT(DISTINCT branch_id) FROM clients c WHERE $whereSql AND branch_id IS NOT NULL");
            $stmtB->execute($params);

            $stats = [
                'clients'  => $result['pagination']['total'],
                'unions'   => (int)$stmtU->fetchColumn(),
                'officers' => (int)$stmtO->fetchColumn(),
                'branches' => (int)$stmtB->fetchColumn()
            ];

            echo json_encode(['success' => true, 'stats' => $stats] + $result);
            exit;
        }
        
        if ($action === 'export_csv') {
            $data = getFilteredClients($pdo, 1, 999999, 
                trim($_GET['search'] ?? ''), 
                trim($_GET['union'] ?? ''), 
                trim($_GET['officer'] ?? ''), 
                trim($_GET['savings'] ?? ''), 
                trim($_GET['branch'] ?? ''),
                trim($_GET['status'] ?? ''),
                trim($_GET['planType'] ?? ''),
                true
            );
            
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="clients_export_' . date('Y-m-d') . '.csv"');
            
            $output = fopen('php://output', 'w');
            fputcsv($output, ['Client ID', 'Name', 'Phone', 'Address', 'Branch', 'Union', 'Officer', 'Guarantor', 'Date Created', 'Has Savings', 'Savings Total', 'Active Loan Balance']);
            foreach ($data as $row) {
                fputcsv($output, [
                    $row['id'], $row['name'], $row['phone'], $row['address'],
                    $row['branch_name'], $row['union'], $row['officer_name'],
                    $row['guarantor_name'], $row['created_at'] ?? '', 
                    $row['has_savings'] ? 'Yes' : 'No', 
                    $row['savings_total'], 
                    $row['active_loan_balance']
                ]);
            }
            fclose($output);
            exit;
        }

        if ($action === 'get_client') {
            header('Content-Type: application/json');
            $stmt = $pdo->prepare("SELECT * FROM clients WHERE id = ?");
            $stmt->execute([$_GET['id'] ?? '']);
            $client = $stmt->fetch();
            
            if ($client) {
                $stmt = $pdo->prepare("SELECT id, balance FROM savings WHERE client_id = ?");
                $stmt->execute([$client['id']]);
                $savings = $stmt->fetch();
                
                $client['type'] = $client['client_type'];
                $client['savings_id'] = $savings['id'] ?? null;
                $client['savings_balance'] = $savings['balance'] ?? 0;
                
                echo json_encode(['success' => true, 'client' => $client]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Client not found.']);
            }
            exit;
        }

        if ($action === 'delete_client') {
            header('Content-Type: application/json');
            if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
                throw new Exception('Invalid security token');
            }

            $client_id = $_POST['client_id'] ?? '';
            
            $stmt = $pdo->prepare("UPDATE clients SET deleted_at = NOW() WHERE id = ?");
            $stmt->execute([$client_id]);
            
            if ($stmt->rowCount() > 0) {
                echo json_encode(['success' => true, 'message' => 'Client successfully deleted (moved to archive).']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Client not found or already deleted.']);
            }
            exit;
        }

        if ($action === 'restore_client') {
            header('Content-Type: application/json');
            if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
                throw new Exception('Invalid security token');
            }

            $client_id = $_POST['client_id'] ?? '';
            
            $stmt = $pdo->prepare("UPDATE clients SET deleted_at = NULL WHERE id = ?");
            $stmt->execute([$client_id]);
            
            if ($stmt->rowCount() > 0) {
                echo json_encode(['success' => true, 'message' => 'Client successfully restored!']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Client not found or already active.']);
            }
            exit;
        }

        if ($action === 'save_client') {
            header('Content-Type: application/json');
            if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
                throw new Exception('Invalid security token');
            }

            $old_client_id = $_POST['client_id'] ?? '';
            $new_client_id = trim($_POST['new_client_id'] ?? '');
            $name = trim($_POST['name'] ?? '');
            
            if (empty($new_client_id) || empty($name)) {
                throw new Exception('Client ID and Name are required.');
            }

            if ($old_client_id !== $new_client_id) {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM clients WHERE id = ?");
                $stmt->execute([$new_client_id]);
                if ($stmt->fetchColumn() > 0) {
                    throw new Exception('Client ID already exists.');
                }
            }

            if (!empty($old_client_id)) {
                $sql = "UPDATE clients SET 
                        id = ?, name = ?, phone = ?, address = ?, 
                        branch_id = ?, client_type = ?, status = ?,
                        guarantor_name = ?, guarantor_phone = ?, created_at = ?
                        WHERE id = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    $new_client_id, $name, $_POST['phone'] ?? null, $_POST['address'] ?? null,
                    $_POST['branch'] ?? null, $_POST['type'] ?? null, $_POST['status'] ?? 'active',
                    $_POST['guarantor_name'] ?? null, $_POST['guarantor_phone'] ?? null,
                    $_POST['created_at'] ?: date('Y-m-d H:i:s'), $old_client_id
                ]);
            } else {
                 $sql = "INSERT INTO clients (id, name, phone, address, branch_id, client_type, status, guarantor_name, guarantor_phone, created_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                 $stmt = $pdo->prepare($sql);
                 $stmt->execute([
                    $new_client_id, $name, $_POST['phone'] ?? null, $_POST['address'] ?? null,
                    $_POST['branch'] ?? null, $_POST['type'] ?? null, $_POST['status'] ?? 'active',
                    $_POST['guarantor_name'] ?? null, $_POST['guarantor_phone'] ?? null,
                    $_POST['created_at'] ?: date('Y-m-d H:i:s')
                ]);
            }

            echo json_encode(['success' => true, 'message' => 'Client saved successfully.']);
            exit;
        }

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

// ==========================================
//             HTML RENDERING
// ==========================================

$stats = ['clients' => 0, 'unions' => 0, 'officers' => 0, 'branches' => 0];

try {
    $stats['clients'] = $pdo->query("SELECT COUNT(*) FROM clients WHERE deleted_at IS NULL")->fetchColumn();
    $stats['unions'] = $pdo->query("SELECT COUNT(DISTINCT `union`) FROM clients WHERE `union` IS NOT NULL AND `union` != '' AND deleted_at IS NULL")->fetchColumn();
    $stats['officers'] = $pdo->query("SELECT COUNT(DISTINCT officer_username) FROM clients WHERE officer_username IS NOT NULL AND deleted_at IS NULL")->fetchColumn();
    $stats['branches'] = $pdo->query("SELECT COUNT(*) FROM branches WHERE status = 'active'")->fetchColumn();
} catch(Exception $e) {}

$branches = $pdo->query("SELECT id, name FROM branches")->fetchAll(PDO::FETCH_KEY_PAIR);
$unions_list = $pdo->query("SELECT DISTINCT `union` FROM clients WHERE `union` IS NOT NULL AND `union` != '' AND deleted_at IS NULL ORDER BY `union`")->fetchAll(PDO::FETCH_COLUMN);
$officers_list = $pdo->query("SELECT DISTINCT u.username, u.full_name FROM users u INNER JOIN clients c ON u.username = c.officer_username WHERE u.role = 'co' AND c.deleted_at IS NULL ORDER BY u.full_name")->fetchAll(PDO::FETCH_ASSOC);

$full_name = $_SESSION['full_name'] ?? 'Admin';
$user_role = $_SESSION['user_role'] ?? 'admin';
$username = $_SESSION['username'] ?? '';
$profile_pic_path = '';
$has_profile_pic = false;

try {
    $stmt = $pdo->prepare("SELECT profile_pic, full_name, role FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $u = $stmt->fetch();
    if($u) {
        $full_name = $u['full_name'];
        if(!empty($u['profile_pic']) && $u['profile_pic'] !== 'default_avatar.png') {
            $profile_pic_path = $base_path . 'uploads/' . $u['profile_pic'];
            $has_profile_pic = true;
        }
    }
} catch(Exception $e) {}

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
    <title>Manage Clients - CUPAD</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />
    <link rel="icon" type="image/png" href="<?php echo htmlspecialchars($base_path . 'uploads/CUPAD LOGO.png'); ?>">
    
    <style>
        :root { 
            --primary: #2563eb; --primary-dark: #1d4ed8; 
            --success: #059669; --warning: #d97706; --danger: #dc2626; --info: #0284c7;
            --bg-body: #f1f5f9; --bg-surface: #ffffff; 
            --text-main: #0f172a; --text-muted: #64748b;
            --border: #e2e8f0; 
            --radius-lg: 16px; --radius-md: 10px; --radius-sm: 8px;
            --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05); 
            --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1);
            --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1);
            --nav-height: 70px;
        }
        html.dark { 
            --bg-body: #0f172a; --bg-surface: #1e293b; 
            --text-main: #f8fafc; --text-muted: #94a3b8;
            --border: #334155; --primary: #3b82f6;
            --success: #34d399; --warning: #fbbf24; --danger: #f87171; --info: #38bdf8;
        }
        * { box-sizing: border-box; outline: none; }
        body { 
            font-family: 'Plus Jakarta Sans', sans-serif; 
            background: var(--bg-body); 
            color: var(--text-main); 
            margin: 0; 
            padding-top: var(--nav-height); 
            transition: background-color 0.3s, color 0.3s; 
        }
        a { text-decoration: none; color: inherit; }

        /* HEADER */
        .main-header { 
            position: fixed; 
            top: 0; left: 0; right: 0; 
            height: var(--nav-height); 
            background: rgba(255,255,255,0.85); 
            backdrop-filter: blur(12px); 
            border-bottom: 1px solid var(--border); 
            z-index: 50; 
            transition: background 0.3s; 
        }
        html.dark .main-header { background: rgba(15, 23, 42, 0.85); }
        .navbar { max-width: 1400px; margin: 0 auto; padding: 0 1.5rem; display: flex; align-items: center; justify-content: space-between; height: 100%; }
        .logo { display: flex; align-items: center; gap: 0.75rem; font-weight: 800; font-size: 1.35rem; color: var(--primary); }
        .logo img { height: 38px; }
        .nav-right { display: flex; align-items: center; gap: 1rem; }

        .icon-btn { 
            width: 36px; height: 36px; border-radius: 50%; border: none; background: transparent; 
            color: var(--text-muted); cursor: pointer; display: flex; align-items: center; justify-content: center; 
            font-size: 1.1rem; transition: all 0.2s; 
        }
        .icon-btn:hover { background: var(--bg-body); color: var(--text-main); transform: translateY(-1px); }

        /* User Dropdown */
        .user-dropdown-wrap { position: relative; }
        .user-pill { 
            display: flex; align-items: center; gap: 0.75rem; padding: 4px 8px 4px 4px; 
            border: 1px solid var(--border); border-radius: 99px; background: var(--bg-surface); cursor: pointer; transition: all 0.2s; 
        }
        .user-pill:hover { border-color: var(--primary); box-shadow: var(--shadow-sm); }
        .user-avatar, .user-avatar-fallback { 
            width: 34px !important; height: 34px !important; min-width: 34px; min-height: 34px; 
            max-width: 34px; max-height: 34px; border-radius: 50%; object-fit: cover; flex-shrink: 0; 
        }
        .user-avatar-fallback { 
            background: var(--bg-body); color: var(--text-muted); display: flex; align-items: center; 
            justify-content: center; font-size: 1rem; border: 1px solid var(--border); 
        }
        .user-info { display: flex; flex-direction: column; line-height: 1.1; padding-right: 0.5rem; }
        .user-name { font-weight: 600; font-size: 0.85rem; }
        .user-role { font-size: 0.7rem; color: var(--text-muted); text-transform: uppercase; }
        
        .dropdown-menu { 
            position: absolute; top: 125%; right: 0; background: var(--bg-surface); border: 1px solid var(--border); 
            border-radius: var(--radius-md); box-shadow: var(--shadow-md); min-width: 200px; display: none; 
            z-index: 1000; flex-direction: column; overflow: hidden; animation: scaleIn 0.2s ease; transform-origin: top right; 
        }
        @keyframes scaleIn { from { opacity: 0; transform: scale(0.95); } to { opacity: 1; transform: scale(1); } }
        .dropdown-menu.show { display: flex; }
        .dropdown-item { padding: 0.75rem 1rem; display: flex; align-items: center; gap: 0.75rem; font-size: 0.9rem; transition: 0.2s; }
        .dropdown-item:hover { background: var(--bg-body); color: var(--primary); }
        .text-danger { color: var(--danger) !important; }

        /* CONTAINER & WELCOME */
        .container { max-width: 1400px; margin: 0 auto; padding: 2rem 1.5rem 4rem; }
        .welcome-section { margin-bottom: 2rem; text-align: center; }
        .welcome-section h1 { margin: 0; font-size: 1.75rem; font-weight: 800; }
        .welcome-section p { margin: 0.5rem 0 0; color: var(--text-muted); }

        /* STATS GRID */
        .stats-container { margin-bottom: 2rem; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; }
        @media (max-width: 768px) { 
            .stats-container { margin: 0 -1.5rem 2rem; padding: 0 1.5rem; overflow: hidden; } 
            .stats-grid { display: flex; overflow-x: auto; scroll-snap-type: x mandatory; gap: 12px; padding-bottom: 15px; scrollbar-width: none; } 
            .stats-grid::-webkit-scrollbar { display: none; } 
            .stat-card { flex: 0 0 85%; min-width: 280px; scroll-snap-align: center; } 
        }
        
        .stat-card { 
            position: relative; background: var(--bg-surface); padding: 1.2rem; border-radius: var(--radius-lg); 
            overflow: hidden; display: flex; flex-direction: column; justify-content: space-between; min-height: 130px; 
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05), 0 2px 4px -1px rgba(0,0,0,0.03); transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); border: 1px solid transparent;
        }
        .stat-card:hover { transform: translateY(-5px); box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1), 0 10px 10px -5px rgba(0,0,0,0.04); }
        .stat-content { position: relative; z-index: 2; }
        .stat-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.75rem; }
        .stat-icon { width: 40px; height: 40px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; background: rgba(255,255,255,0.2); backdrop-filter: blur(4px); }
        .stat-value { font-size: 1.6rem; font-weight: 800; line-height: 1; letter-spacing: -0.5px; margin-bottom: 0.25rem; }
        .stat-label { font-size: 0.75rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.3px; opacity: 0.9; }
        .watermark-icon { position: absolute; right: -15px; bottom: -15px; font-size: 6rem; opacity: 0.12; transform: rotate(-15deg); z-index: 1; pointer-events: none; transition: transform 0.3s ease; }
        .stat-card:hover .watermark-icon { transform: rotate(0deg) scale(1.1); }
        .stat-card.fill-blue { background: linear-gradient(135deg, #3b82f6, #1d4ed8); color: white; border: none; }
        .stat-card.fill-green { background: linear-gradient(135deg, #10b981, #047857); color: white; border: none; }
        .stat-card.fill-orange { background: linear-gradient(135deg, #f59e0b, #b45309); color: white; border: none; }
        .stat-card.fill-purple { background: linear-gradient(135deg, #8b5cf6, #6d28d9); color: white; border: none; }
        
        /* PAGE HEADER */
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; flex-wrap: wrap; gap: 1rem; }
        .page-title { font-size: 1.5rem; font-weight: 700; display: flex; align-items: center; gap: 0.75rem; }
        .page-title .icon-wrapper { width: 42px; height: 42px; background: var(--bg-surface); border: 1px solid var(--border); border-radius: 12px; display: flex; align-items: center; justify-content: center; color: var(--text-muted); }
        .live-badge { font-size: 0.65rem; background: var(--success); color: white; padding: 3px 8px; border-radius: 99px; display: inline-flex; align-items: center; gap: 5px; vertical-align: middle; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; box-shadow: 0 0 10px rgba(5, 150, 105, 0.4); }
        .pulse-dot { width: 6px; height: 6px; background: white; border-radius: 50%; animation: pulse 1.5s infinite; }
        @keyframes pulse { 0% { transform: scale(0.95); opacity: 0.5; } 50% { transform: scale(1.3); opacity: 1; } 100% { transform: scale(0.95); opacity: 0.5; } }

        /* FILTERS & SEARCH */
        .filters-card { background: var(--bg-surface); border: 1px solid var(--border); border-radius: var(--radius-lg); padding: 1.5rem; margin-bottom: 1.5rem; box-shadow: var(--shadow-sm); }
        .filters-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem; align-items: end; }
        
        .form-group label { display: block; font-size: 0.85rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.4rem; }
        .form-control { width: 100%; padding: 0.6rem 1rem; border-radius: 99px; border: 1px solid var(--border); background: var(--bg-body); color: var(--text-main); font-family: inherit; transition: all 0.2s; font-size: 0.9rem; }
        .form-control:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1); }
        
        .search-input-wrapper { position: relative; display: flex; align-items: center; }
        .search-input-wrapper .search-icon { position: absolute; left: 14px; color: var(--text-muted); font-size: 0.9rem; }
        .search-input-wrapper input { padding-left: 36px; padding-right: 36px; }
        .search-input-wrapper .search-spinner { position: absolute; right: 14px; color: var(--primary); font-size: 1rem; display: none; }

        .btn { padding: 0.6rem 1.2rem; border-radius: 99px; border: 1px solid var(--border); background: var(--bg-surface); color: var(--text-main); font-weight: 600; font-size: 0.85rem; cursor: pointer; display: inline-flex; align-items: center; gap: 0.5rem; transition: all 0.2s; justify-content: center; font-family: inherit; }
        .btn:hover { border-color: var(--primary); color: var(--primary); background: var(--bg-body); transform: translateY(-1px); }
        .btn-primary { background: var(--primary); color: white; border: none; }
        .btn-primary:hover { background: var(--primary-dark); color: white; box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3); }
        .btn-outline { background: transparent; border: 1px solid var(--border); color: var(--text-main); } 
        .btn-outline:hover { border-color: var(--primary); color: var(--primary); }

        /* TABLE CARD & LOADING OVERLAY */
        .table-card { position: relative; background: var(--bg-surface); border: 1px solid var(--border); border-radius: var(--radius-lg); overflow: hidden; box-shadow: var(--shadow-sm); }
        .table-responsive { overflow-x: auto; min-height: 250px; }

        table { width: 100%; border-collapse: separate; border-spacing: 0; min-width: 1000px; }
        th { text-align: left; padding: 1rem 1.5rem; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-muted); background: var(--bg-surface); font-weight: 600; border-bottom: 1px solid var(--border); position: sticky; top: 0; z-index: 5; }
        td { padding: 1rem 1.5rem; border-bottom: 1px solid var(--border); font-size: 0.9rem; vertical-align: middle; transition: background 0.2s; }
        tr:hover td { background: var(--bg-body); }
        tr:last-child td { border-bottom: none; }

        /* SKELETON LOADING ANIMATION */
        @keyframes shimmer {
            0% { background-position: -200px 0; }
            100% { background-position: 200px 0; }
        }
        .skeleton {
            background: linear-gradient(90deg, #e2e8f0 25%, #f1f5f9 50%, #e2e8f0 75%);
            background-size: 400px 100%;
            animation: shimmer 1.5s infinite;
            border-radius: 6px;
            display: inline-block;
        }
        html.dark .skeleton {
            background: linear-gradient(90deg, #334155 25%, #475569 50%, #334155 75%);
            background-size: 400px 100%;
        }
        .skeleton-text { height: 14px; width: 80%; }
        .skeleton-text.short { width: 45%; }
        .skeleton-avatar { width: 34px; height: 34px; border-radius: 50%; }
        .skeleton-badge { width: 90px; height: 24px; border-radius: 99px; }

        /* TABLE CELLS */
        .client-meta { display: flex; flex-direction: column; }
        .client-name { font-weight: 600; font-size: 0.95rem; }
        .client-sub { font-size: 0.8rem; color: var(--text-muted); }
        
        .status-badge { padding: 4px 10px; border-radius: 99px; font-size: 0.75rem; font-weight: 600; display: inline-flex; align-items: center; gap: 0.4rem; }
        .bg-green-soft { background: rgba(16, 185, 129, 0.15); color: #10b981; }
        html.dark .bg-green-soft { background: rgba(52, 211, 153, 0.2); color: #34d399; }
        .bg-red-soft { background: rgba(239, 68, 68, 0.15); color: #ef4444; }
        html.dark .bg-red-soft { background: rgba(248, 113, 113, 0.2); color: #f87171; }
        .bg-gray-soft { background: rgba(107, 114, 128, 0.1); color: #6b7280; }
        html.dark .bg-gray-soft { background: rgba(148, 163, 184, 0.2); color: #94a3b8; }

        /* ACTION BUTTONS */
        .action-cell { display: flex; gap: 0.5rem; justify-content: flex-end; }
        .action-btn { width: 32px; height: 32px; border-radius: 8px; display: flex; align-items: center; justify-content: center; border: none; cursor: pointer; transition: all 0.2s; font-size: 0.9rem; background: transparent; color: var(--text-muted); }
        .action-btn:hover { background: var(--bg-body); color: var(--primary); transform: scale(1.1); }
        .action-btn.delete:hover { background: rgba(239, 68, 68, 0.1); color: var(--danger); }

        /* MODAL */
        .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 1000; display: none; align-items: center; justify-content: center; backdrop-filter: blur(2px); }
        .modal-overlay.show { display: flex; }
        .modal-box { background: var(--bg-surface); padding: 2rem; border-radius: var(--radius-lg); width: 90%; max-width: 600px; box-shadow: 0 10px 25px rgba(0,0,0,0.2); animation: popIn 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275); max-height: 90vh; overflow-y: auto; border: 1px solid var(--border); }
        @keyframes popIn { from { transform: scale(0.9); opacity: 0; } to { transform: scale(1); opacity: 1; } }
        
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; border-bottom: 1px solid var(--border); padding-bottom: 1rem; }
        .modal-title { font-size: 1.25rem; font-weight: 700; margin: 0; }
        .close-modal { background: none; border: none; font-size: 1.2rem; color: var(--text-muted); cursor: pointer; width: 32px; height: 32px; border-radius: 8px; display: flex; align-items: center; justify-content: center; transition: all 0.2s; }
        .close-modal:hover { background: var(--bg-body); color: var(--danger); }
        
        .modal-section { margin-bottom: 1.5rem; }
        .modal-section-title { font-size: 0.85rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; margin-bottom: 0.8rem; letter-spacing: 0.05em; }
        .modal-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
        @media(max-width: 600px) { .modal-grid { grid-template-columns: 1fr; } }

        /* PAGINATION */
        .pagination { padding: 1rem 1.5rem; display: flex; justify-content: space-between; align-items: center; border-top: 1px solid var(--border); background: var(--bg-surface); }
        .page-nums { display: flex; gap: 0.5rem; }
        .page-btn { width: 32px; height: 32px; border-radius: 8px; display: flex; align-items: center; justify-content: center; border: 1px solid var(--border); background: var(--bg-surface); cursor: pointer; color: var(--text-main); font-size: 0.9rem; transition: all 0.2s; font-family: inherit; }
        .page-btn.active { background: var(--primary); color: white; border-color: var(--primary); }
        .page-btn:hover:not(.active) { background: var(--bg-body); border-color: var(--primary); color: var(--primary); }
        .page-btn:disabled { opacity: 0.5; cursor: not-allowed; }

        /* TOAST */
        .toast-container { position: fixed; top: 85px; right: 20px; z-index: 2000; display: flex; flex-direction: column; gap: 10px; pointer-events: none; }
        .toast { pointer-events: auto; background: var(--bg-surface); color: var(--text-main); padding: 1rem; border-radius: var(--radius-md); box-shadow: var(--shadow-md); border-left: 4px solid var(--primary); min-width: 280px; animation: slideIn 0.3s forwards; display: none; align-items: center; gap: 0.75rem; font-size: 0.9rem; }
        .toast.success { border-left-color: var(--success); }
        .toast.error { border-left-color: var(--danger); }
        @keyframes slideIn { from { opacity: 0; transform: translateX(50px); } to { opacity: 1; transform: translateX(0); } }

        /* MISC */
        .empty-state { text-align: center; padding: 3rem; color: var(--text-muted); display: none; }
        .empty-state.show { display: block; animation: popIn 0.4s ease; }
        .empty-state i { font-size: 3rem; margin-bottom: 1rem; opacity: 0.3; }
        
        .loading-spinner { display: inline-block; width: 20px; height: 20px; border: 3px solid rgba(255,255,255,0.3); border-radius: 50%; border-top-color: white; animation: spin 1s ease-in-out infinite; }
        .loading-spinner.primary { border-color: rgba(37,99,235,0.2); border-top-color: var(--primary); }
        @keyframes spin { to { transform: rotate(360deg); } }

        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: var(--bg-body); }
        ::-webkit-scrollbar-thumb { background: var(--border); border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: var(--text-muted); }
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
                            <span class="user-role"><?php echo ucfirst(htmlspecialchars($user_role)); ?></span>
                        </div>
                        <i class="fas fa-chevron-down" style="font-size:0.7rem; color:var(--text-muted)"></i>
                    </div>
                    <div class="dropdown-menu" id="userDropdown">
                        <div style="padding: 0.75rem 1rem; font-size: 0.75rem; color:var(--text-muted); font-weight:600">SIGNED IN AS</div>
                        <div style="padding: 0 1rem 0.5rem; font-weight:700"><?php echo htmlspecialchars($username); ?></div>
                        <div style="height:1px; background:var(--border); margin:0"></div>
                        <a href="profile.php" class="dropdown-item">
                            <i class="fas fa-user-circle" style="color:var(--primary)"></i> My Profile
                        </a>
                        <div style="height:1px; background:var(--border); margin:0"></div>
                        <a href="../logout.php" class="dropdown-item text-danger">
                            <i class="fas fa-sign-out-alt"></i> Logout
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <!-- Toast Container -->
    <div id="toastContainer" class="toast-container"></div>

    <main class="container">
        
        <!-- Welcome Section -->
        <div class="welcome-section">
            <h1><?php echo htmlspecialchars($greeting); ?>, <?php echo htmlspecialchars(explode(' ', $full_name)[0]); ?>! 👋</h1>
            <p>Manage and oversee all client accounts from your centralized dashboard.</p>
        </div>

        <!-- Stats Grid (AJAX Updated dynamically based on active filters) -->
        <div class="stats-container">
            <div class="stats-grid">
                <div class="stat-card fill-blue">
                    <i class="fas fa-users watermark-icon"></i>
                    <div class="stat-content">
                        <div class="stat-header">
                            <div class="stat-icon"><i class="fas fa-users"></i></div>
                        </div>
                        <div class="stat-value" id="stat-clients"><?php echo number_format($stats['clients']); ?></div>
                        <div class="stat-label" id="stat-clients-label">Total Active Clients</div>
                    </div>
                </div>
                <div class="stat-card fill-green">
                    <i class="fas fa-building watermark-icon"></i>
                    <div class="stat-content">
                        <div class="stat-header">
                            <div class="stat-icon"><i class="fas fa-building"></i></div>
                        </div>
                        <div class="stat-value" id="stat-unions"><?php echo number_format($stats['unions']); ?></div>
                        <div class="stat-label">Active Unions</div>
                    </div>
                </div>
                <div class="stat-card fill-orange">
                    <i class="fas fa-user-tie watermark-icon"></i>
                    <div class="stat-content">
                        <div class="stat-header">
                            <div class="stat-icon"><i class="fas fa-user-tie"></i></div>
                        </div>
                        <div class="stat-value" id="stat-officers"><?php echo number_format($stats['officers']); ?></div>
                        <div class="stat-label">Credit Officers</div>
                    </div>
                </div>
                <div class="stat-card fill-purple">
                    <i class="fas fa-map-marked-alt watermark-icon"></i>
                    <div class="stat-content">
                        <div class="stat-header">
                            <div class="stat-icon"><i class="fas fa-map-marked-alt"></i></div>
                        </div>
                        <div class="stat-value" id="stat-branches"><?php echo number_format($stats['branches']); ?></div>
                        <div class="stat-label">Active Branches</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Page Header with Actions -->
        <div class="page-header">
            <div class="page-title">
                <div class="icon-wrapper">
                    <i class="fas fa-users-cog"></i>
                </div>
                Client Management
                <span class="live-badge"><span class="pulse-dot"></span> Live</span>
            </div>
            <div style="display:flex; gap:0.5rem">
                <button id="exportBtn" class="btn btn-outline">
                    <i class="fas fa-file-csv"></i> Export CSV
                </button>
                <a href="dashboard.php" class="btn btn-primary">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
            </div>
        </div>

        <!-- Filters -->
        <div class="filters-card">
            <div class="filters-grid">
                <div class="form-group">
                    <label>Search Clients</label>
                    <div class="search-input-wrapper">
                        <i class="fas fa-search search-icon"></i>
                        <input type="text" id="searchInput" class="form-control" placeholder="Name, ID or Phone...">
                        <i class="fas fa-circle-notch fa-spin search-spinner" id="searchSpinner"></i>
                    </div>
                </div>
                <div class="form-group">
                    <label><i class="fas fa-building" style="margin-right:5px"></i>Branch</label>
                    <select id="branchFilter" class="form-control">
                        <option value="">All Branches</option>
                        <?php foreach ($branches as $id => $name): ?>
                            <option value="<?php echo htmlspecialchars($id); ?>"><?php echo htmlspecialchars($name); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label><i class="fas fa-users" style="margin-right:5px"></i>Union</label>
                    <select id="unionFilter" class="form-control">
                        <option value="">All Unions</option>
                        <?php foreach ($unions_list as $u): ?>
                            <option value="<?php echo htmlspecialchars($u); ?>"><?php echo htmlspecialchars($u); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label><i class="fas fa-user-tie" style="margin-right:5px"></i>Credit Officer</label>
                    <select id="officerFilter" class="form-control">
                        <option value="">All Officers</option>
                        <?php foreach ($officers_list as $officer): ?>
                            <option value="<?php echo htmlspecialchars($officer['username']); ?>"><?php echo htmlspecialchars($officer['username'] . ' - ' . $officer['full_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label><i class="fas fa-piggy-bank" style="margin-right:5px"></i>Savings Status</label>
                    <select id="savingsFilter" class="form-control">
                        <option value="">All Status</option>
                        <option value="with-savings">Has Savings</option>
                        <option value="without-savings">No Savings</option>
                    </select>
                </div>
                <div class="form-group">
                    <label><i class="fas fa-toggle-on" style="margin-right:5px"></i>Client Status</label>
                    <select id="statusFilter" class="form-control">
                        <option value="">All Active/Inactive</option>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                        <option value="deleted">Archived (Deleted)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label><i class="fas fa-calendar-check" style="margin-right:5px"></i>Plan Type</label>
                    <select id="planTypeFilter" class="form-control">
                        <option value="">All Plans</option>
                        <option value="23 Days">23 Days</option>
                        <option value="13 Weeks">13 Weeks</option>
                        <option value="24 Weeks">24 Weeks</option>
                        <option value="6 Months">6 Months</option>
                        <option value="8 Months">8 Months</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>&nbsp;</label>
                    <button id="clearFilters" class="btn btn-outline" style="width:100%">
                        <i class="fas fa-undo"></i> Reset
                    </button>
                </div>
            </div>
        </div>

        <!-- Table Card -->
        <div class="table-card">
            <div class="table-responsive">
                <table class="clients-table">
                    <thead>
                        <tr>
                            <th>Client Details</th>
                            <th>Contact Info</th>
                            <th>Branch</th>
                            <th>Union / Officer</th>
                            <th>Date Created</th>
                            <th>Savings Balance</th>
                            <th>Loan Status</th>
                            <th style="text-align: right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="clientsTableBody">
                        <!-- Skeleton loading shown on start/switch -->
                    </tbody>
                </table>
                <div id="emptyState" class="empty-state">
                    <i class="fas fa-folder-open"></i>
                    <p>No clients found matching your search or filters.</p>
                </div>
            </div>

            <!-- Pagination -->
            <div class="pagination" id="paginationControls">
                <div style="font-size:0.9rem; color:var(--text-muted)">
                    Showing <span id="pgStart" style="font-weight:600; color:var(--text-main)">0</span> - 
                    <span id="pgEnd" style="font-weight:600; color:var(--text-main)">0</span> of 
                    <span id="pgTotal">0</span> clients
                </div>
                <div class="page-nums">
                    <button id="prevBtn" class="page-btn"><i class="fas fa-chevron-left"></i></button>
                    <div id="pageNumbers" style="display:flex; gap:5px"></div>
                    <button id="nextBtn" class="page-btn"><i class="fas fa-chevron-right"></i></button>
                </div>
            </div>
        </div>
    </main>

    <!-- Edit Modal -->
    <div id="editModal" class="modal-overlay">
        <div class="modal-box">
            <div class="modal-header">
                <h3 class="modal-title"><i class="fas fa-user-edit" style="color:var(--primary); margin-right:8px"></i>Edit Client</h3>
                <button class="close-modal" onclick="closeEditModal()"><i class="fas fa-times"></i></button>
            </div>
            
            <form id="editClientForm">
                <input type="hidden" name="action" value="save_client">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="client_id" id="editClientId">
                
                <div class="modal-section">
                    <div class="modal-section-title">Primary Information</div>
                    <div class="modal-grid">
                        <div class="form-group">
                            <label>Full Name *</label>
                            <input type="text" name="name" id="editName" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label>Client ID *</label>
                            <input type="text" name="new_client_id" id="editNewClientId" class="form-control" required>
                        </div>
                    </div>
                    <div class="modal-grid">
                        <div class="form-group">
                            <label>Phone Number</label>
                            <input type="tel" name="phone" id="editPhone" class="form-control">
                        </div>
                        <div class="form-group">
                            <label>Branch</label>
                            <select name="branch" id="editBranch" class="form-control">
                                <option value="">-- Select Branch --</option>
                                <?php foreach($branches as $id => $name): ?>
                                    <option value="<?php echo htmlspecialchars($id); ?>"><?php echo htmlspecialchars($name); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="modal-grid">
                        <div class="form-group">
                            <label>Address</label>
                            <input type="text" name="address" id="editAddress" class="form-control">
                        </div>
                        <div class="form-group">
                            <label>Plan Type</label>
                            <select name="type" id="editType" class="form-control">
                                <option value="23 Days">23 Days</option>
                                <option value="13 Weeks">13 Weeks</option>
                                <option value="24 Weeks">24 Weeks</option>
                                <option value="6 Months">6 Months</option>
                                <option value="8 Months">8 Months</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Status</label>
                        <select name="status" id="editStatus" class="form-control">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>

                <div class="modal-section">
                    <div class="modal-section-title">Guarantor Information</div>
                    <div class="modal-grid">
                        <div class="form-group">
                            <label>Guarantor Name</label>
                            <input type="text" name="guarantor_name" id="editGuarantorName" class="form-control">
                        </div>
                        <div class="form-group">
                            <label>Guarantor Phone</label>
                            <input type="tel" name="guarantor_phone" id="editGuarantorPhone" class="form-control">
                        </div>
                    </div>
                </div>
                
                <div class="modal-section">
                    <div class="modal-section-title">Registration Details</div>
                    <div class="form-group">
                        <label>Date Created</label>
                        <input type="datetime-local" name="created_at" id="editCreatedAt" class="form-control">
                    </div>
                </div>

                <div style="margin-top:2rem; padding-top:1.5rem; border-top:1px solid var(--border); display:flex; justify-content:flex-end; gap:10px">
                    <button type="button" class="btn btn-outline" onclick="closeEditModal()">Cancel</button>
                    <button type="submit" id="saveBtn" class="btn btn-primary">
                        <i class="fas fa-save"></i> Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const CSRF_TOKEN = "<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>";
        let currentPage = 1;
        let itemsPerPage = 20;
        let debounceTimer;
        
        // Polling logic variables
        let autoRefreshInterval;
        const REFRESH_RATE = 15000;
        let currentAbortController = null;

        // --- Theme & Header Logic ---
        const themeBtn = document.getElementById('themeToggle');
        const html = document.documentElement;
        
        if(localStorage.getItem('theme') === 'dark') { 
            html.classList.add('dark'); 
            html.classList.remove('light');
            themeBtn.innerHTML = '<i class="fas fa-sun"></i>'; 
        }
        
        themeBtn.addEventListener('click', () => {
            html.classList.toggle('dark');
            html.classList.toggle('light');
            const isDark = html.classList.contains('dark');
            localStorage.setItem('theme', isDark ? 'dark' : 'light');
            themeBtn.innerHTML = isDark ? '<i class="fas fa-sun"></i>' : '<i class="fas fa-moon"></i>';
        });

        const userTrigger = document.getElementById('userDropdownTrigger');
        const userDropdown = document.getElementById('userDropdown');
        userTrigger.addEventListener('click', (e) => { 
            e.stopPropagation(); 
            userDropdown.classList.toggle('show'); 
        });
        document.addEventListener('click', (e) => { 
            if (!userTrigger.contains(e.target)) userDropdown.classList.remove('show'); 
        });

        // --- Toast Notification System ---
        function showToast(message, type = 'success') {
            const container = document.getElementById('toastContainer');
            const toast = document.createElement('div');
            toast.className = `toast ${type}`;
            const icon = type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : 'info-circle';
            toast.innerHTML = `<i class="fas fa-${icon}" style="font-size:1.1rem"></i> ${message}`;
            container.appendChild(toast);
            toast.style.display = 'flex';
            setTimeout(() => {
                toast.style.opacity = '0';
                toast.style.transform = 'translateX(50px)';
                setTimeout(() => toast.remove(), 300);
            }, 4000);
        }

        // --- Skeleton Loading Helper ---
        function showSkeletonLoading(rowsCount = 5) {
            const tbody = document.getElementById('clientsTableBody');
            const emptyState = document.getElementById('emptyState');
            emptyState.classList.remove('show');
            document.querySelector('.clients-table').style.display = 'table';
            
            let skeletonRows = '';
            for (let i = 0; i < rowsCount; i++) {
                skeletonRows += `
                    <tr>
                        <td>
                            <div style="display:flex; align-items:center; gap:12px;">
                                <div class="skeleton skeleton-avatar"></div>
                                <div class="client-meta" style="width: 120px; gap: 6px;">
                                    <div class="skeleton skeleton-text"></div>
                                    <div class="skeleton skeleton-text short"></div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <div class="client-meta" style="width: 110px; gap: 6px;">
                                <div class="skeleton skeleton-text"></div>
                                <div class="skeleton skeleton-text short"></div>
                            </div>
                        </td>
                        <td><div class="skeleton skeleton-text" style="width: 90px;"></div></td>
                        <td>
                            <div class="client-meta" style="width: 100px; gap: 6px;">
                                <div class="skeleton skeleton-text"></div>
                                <div class="skeleton skeleton-text short"></div>
                            </div>
                        </td>
                        <td><div class="skeleton skeleton-text short" style="width: 80px;"></div></td>
                        <td><div class="skeleton skeleton-badge"></div></td>
                        <td><div class="skeleton skeleton-badge"></div></td>
                        <td><div class="action-cell"><div class="skeleton skeleton-text short" style="width: 40px; height: 28px;"></div></div></td>
                    </tr>
                `;
            }
            tbody.innerHTML = skeletonRows;
        }

        // --- Data Loading & Seamless AJAX Polling ---
        document.addEventListener('DOMContentLoaded', () => {
            loadClients(1, false);
            setupEvents();
        });

        function startAutoRefresh() {
            if (autoRefreshInterval) clearInterval(autoRefreshInterval);
            autoRefreshInterval = setInterval(() => {
                if (!document.getElementById('editModal').classList.contains('show')) {
                    loadClients(currentPage, true);
                }
            }, REFRESH_RATE);
        }

        function getFilterParams() {
            return new URLSearchParams({
                search: document.getElementById('searchInput').value.trim(),
                branch: document.getElementById('branchFilter').value,
                union: document.getElementById('unionFilter').value,
                officer: document.getElementById('officerFilter').value,
                savings: document.getElementById('savingsFilter').value,
                status: document.getElementById('statusFilter').value,
                planType: document.getElementById('planTypeFilter').value
            });
        }

        async function loadClients(page = 1, isBackground = false) {
            currentPage = page;
            const tbody = document.getElementById('clientsTableBody');
            const searchSpinner = document.getElementById('searchSpinner');
            const searchInput = document.getElementById('searchInput');
            
            if (currentAbortController) currentAbortController.abort();
            currentAbortController = new AbortController();
            const signal = currentAbortController.signal;

            // Render skeleton placeholder if interactive user action (not background refresh)
            if (!isBackground) {
                showSkeletonLoading(5);
                if (searchInput.value.trim() !== '') {
                    searchSpinner.style.display = 'block';
                }
            }
            
            const params = getFilterParams();
            params.append('action', 'load_clients');
            params.append('page', page);
            params.append('limit', itemsPerPage);

            try {
                const res = await fetch(`?${params.toString()}`, { signal });
                const data = await res.json();
                
                if (data.success) {
                    renderTable(data.clients);
                    renderPagination(data.pagination);
                    
                    // Update Summary Cards dynamically
                    if (data.stats) {
                        document.getElementById('stat-clients').innerText = parseInt(data.stats.clients).toLocaleString();
                        document.getElementById('stat-unions').innerText = parseInt(data.stats.unions).toLocaleString();
                        document.getElementById('stat-officers').innerText = parseInt(data.stats.officers).toLocaleString();
                        document.getElementById('stat-branches').innerText = parseInt(data.stats.branches).toLocaleString();

                        // Dynamically update card 1 label based on selected status filter
                        const statusVal = document.getElementById('statusFilter').value;
                        const labelElem = document.getElementById('stat-clients-label');
                        if (statusVal === 'active') {
                            labelElem.innerText = "Total Active Clients";
                        } else if (statusVal === 'inactive') {
                            labelElem.innerText = "Total Inactive Clients";
                        } else if (statusVal === 'deleted') {
                            labelElem.innerText = "Total Archived Clients";
                        } else {
                            labelElem.innerText = "Matched Clients";
                        }
                    }
                } else {
                    if (!isBackground) throw new Error(data.message || 'Error loading data');
                }
            } catch (e) {
                if (e.name === 'AbortError') return; 
                console.error(e);
                if (!isBackground) {
                    tbody.innerHTML = `<tr><td colspan="8" style="text-align:center; padding:3rem; color:var(--danger)"><i class="fas fa-exclamation-circle" style="font-size:2.5rem; margin-bottom:1rem; display:block"></i>${e.message}</td></tr>`;
                }
            } finally {
                if (!isBackground) {
                    searchSpinner.style.display = 'none';
                }
            }

            if (!isBackground) startAutoRefresh();
        }

        function renderTable(clients) {
            const tbody = document.getElementById('clientsTableBody');
            const emptyState = document.getElementById('emptyState');
            
            if (clients.length === 0) {
                tbody.innerHTML = '';
                emptyState.classList.add('show');
                document.querySelector('.clients-table').style.display = 'none';
                document.getElementById('paginationControls').style.display = 'none';
                return;
            }

            emptyState.classList.remove('show');
            document.querySelector('.clients-table').style.display = 'table';
            document.getElementById('paginationControls').style.display = 'flex';

            const formatter = new Intl.NumberFormat('en-NG', { style: 'currency', currency: 'NGN' });
            
            const newClientIds = new Set(clients.map(c => String(c.id)));

            // Remove rows that no longer belong or skeleton rows
            Array.from(tbody.children).forEach(tr => {
                const rowId = tr.getAttribute('data-id');
                if (!rowId || !newClientIds.has(rowId)) {
                    tbody.removeChild(tr);
                }
            });

            clients.forEach((c, index) => {
                const initials = c.name ? c.name.split(' ').map(n => n[0]).join('').substring(0, 2).toUpperCase() : 'NA';
                
                const savingsHtml = c.has_savings 
                    ? `<div class="status-badge bg-green-soft"><i class="fas fa-check-circle"></i> ${formatter.format(c.savings_total)}</div>`
                    : `<div class="status-badge bg-gray-soft"><i class="fas fa-minus-circle"></i> No Savings</div>`;
                
                const loanHtml = c.has_active_loans
                    ? `<div class="status-badge bg-red-soft"><i class="fas fa-money-bill-wave"></i> ${formatter.format(c.active_loan_balance)}</div>`
                    : `<div class="status-badge bg-gray-soft"><i class="fas fa-check"></i> No Active Loans</div>`;

                const createdDate = c.created_at ? new Date(c.created_at).toLocaleDateString('en-GB', {
                    day: '2-digit', month: 'short', year: 'numeric'
                }) : '--';

                const actionHtml = c.deleted_at 
                    ? `
                        <button class="action-btn" style="color: var(--success);" onclick="restoreClient('${escapeHtml(c.id)}', '${escapeHtml(c.name.replace(/'/g, "\\'"))}')" title="Restore Client">
                            <i class="fas fa-trash-restore"></i>
                        </button>
                    ` 
                    : `
                        <button class="action-btn" onclick="openEdit('${escapeHtml(c.id)}')" title="Edit Client">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button class="action-btn delete" onclick="deleteClient('${escapeHtml(c.id)}', '${escapeHtml(c.name.replace(/'/g, "\\'"))}', ${c.has_savings})" title="Delete Client">
                            <i class="fas fa-trash-alt"></i>
                        </button>
                    `;

                const rowContent = `
                    <td>
                        <div style="display:flex; align-items:center; gap:12px;">
                            <div class="user-avatar-fallback" style="font-weight:700; font-size:0.9rem; background:linear-gradient(135deg, var(--primary), var(--primary-dark)); color:white; border:none">
                                ${initials}
                            </div>
                            <div class="client-meta">
                                <div class="client-name" style="${c.deleted_at ? 'text-decoration: line-through; color: var(--text-muted);' : ''}">${escapeHtml(c.name)}</div>
                                <div class="client-sub">ID: ${escapeHtml(c.id)}</div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <div class="client-meta">
                            <div>${c.phone ? `<i class="fas fa-phone" style="font-size:0.7rem; margin-right:4px; color:var(--primary)"></i>${escapeHtml(c.phone)}` : '<span style="color:var(--text-muted)">--</span>'}</div>
                            <div class="client-sub" style="max-width:150px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis" title="${escapeHtml(c.address || '')}">
                                ${c.address ? escapeHtml(c.address) : ''}
                            </div>
                        </div>
                    </td>
                    <td>
                        <div style="font-weight:500; font-size:0.9rem">
                            <i class="fas fa-building" style="color:var(--primary); margin-right:5px; font-size:0.8rem"></i>
                            ${escapeHtml(c.branch_name)}
                        </div>
                    </td>
                    <td>
                        <div class="client-meta">
                            <div style="font-weight:500">${c.union || '<span style="color:var(--text-muted)">No Union</span>'}</div>
                            <div class="client-sub"><i class="fas fa-user-tie" style="margin-right:4px"></i>${c.officer_name}</div>
                        </div>
                    </td>
                    <td>
                        <div style="font-size:0.85rem; color:var(--text-muted); white-space:nowrap">
                            <i class="fas fa-calendar" style="margin-right:5px"></i>${createdDate}
                        </div>
                    </td>
                    <td>${savingsHtml}</td>
                    <td>${loanHtml}</td>
                    <td>
                        <div class="action-cell">
                            ${actionHtml}
                        </div>
                    </td>
                `;

                let existingRow = tbody.querySelector(`tr[data-id="${c.id}"]`);
                
                if (existingRow) {
                    if (existingRow.innerHTML !== rowContent) {
                        existingRow.innerHTML = rowContent;
                    }
                    if (tbody.children[index] !== existingRow) {
                        tbody.insertBefore(existingRow, tbody.children[index]);
                    }
                } else {
                    const tr = document.createElement('tr');
                    tr.setAttribute('data-id', c.id);
                    tr.innerHTML = rowContent;
                    
                    if (tbody.children[index]) {
                        tbody.insertBefore(tr, tbody.children[index]);
                    } else {
                        tbody.appendChild(tr);
                    }
                }
            });
        }

        function renderPagination(pg) {
            document.getElementById('pgStart').innerText = pg.from;
            document.getElementById('pgEnd').innerText = pg.to;
            document.getElementById('pgTotal').innerText = pg.total;
            
            const nums = document.getElementById('pageNumbers');
            nums.innerHTML = '';
            
            let start = Math.max(1, pg.current_page - 2);
            let end = Math.min(pg.total_pages, start + 4);
            if (end - start < 4) start = Math.max(1, end - 4);
            
            for(let i = start; i <= end; i++) {
                const btn = document.createElement('button');
                btn.className = `page-btn ${i === pg.current_page ? 'active' : ''}`;
                btn.innerText = i;
                btn.onclick = () => loadClients(i, false);
                nums.appendChild(btn);
            }
            
            const prev = document.getElementById('prevBtn');
            prev.onclick = () => loadClients(pg.current_page - 1, false);
            prev.disabled = !pg.has_prev;
            prev.style.opacity = !pg.has_prev ? 0.5 : 1;

            const next = document.getElementById('nextBtn');
            next.onclick = () => loadClients(pg.current_page + 1, false);
            next.disabled = !pg.has_next;
            next.style.opacity = !pg.has_next ? 0.5 : 1;
        }

        // --- Actions ---
        async function deleteClient(id, name, hasSavings) {
            let msg = `Are you sure you want to remove client "${name}"?`;
            if(hasSavings) msg += `\n\n⚠️ NOTE: The client will be archived and hidden, but their savings history is kept safe.`;
            if(!confirm(msg)) return;

            const formData = new FormData();
            formData.append('action', 'delete_client');
            formData.append('client_id', id);
            formData.append('csrf_token', CSRF_TOKEN);

            showSkeletonLoading(3);

            try {
                const res = await fetch('', { method: 'POST', body: formData });
                const data = await res.json();
                
                if (data.success) {
                    showToast(data.message, 'success');
                    loadClients(currentPage, false);
                } else {
                    showToast(data.message || 'Failed to delete client', 'error');
                }
            } catch (e) {
                showToast("Connection failed. Please try again.", "error");
            }
        }

        async function restoreClient(id, name) {
            if(!confirm(`Are you sure you want to restore client "${name}"?`)) return;

            const formData = new FormData();
            formData.append('action', 'restore_client');
            formData.append('client_id', id);
            formData.append('csrf_token', CSRF_TOKEN);

            showSkeletonLoading(3);

            try {
                const res = await fetch('', { method: 'POST', body: formData });
                const data = await res.json();
                
                if (data.success) {
                    showToast(data.message, 'success');
                    loadClients(currentPage, false);
                } else {
                    showToast(data.message || 'Failed to restore client', 'error');
                }
            } catch (e) {
                showToast("Connection failed. Please try again.", "error");
            }
        }

        document.getElementById('editClientForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            const btn = document.getElementById('saveBtn');
            const originalHTML = btn.innerHTML;
            btn.innerHTML = '<div class="loading-spinner" style="width:16px; height:16px; border-width:2px; margin-right:8px"></div> Saving...';
            btn.disabled = true;

            const formData = new FormData(this);

            try {
                const res = await fetch('', { method: 'POST', body: formData });
                const data = await res.json();

                if (data.success) {
                    showToast('Client updated successfully!', 'success');
                    closeEditModal();
                    loadClients(currentPage, false);
                } else {
                    showToast(data.message || 'Failed to save changes', 'error');
                }
            } catch (e) {
                showToast("Connection error. Please try again.", "error");
            } finally {
                btn.innerHTML = originalHTML;
                btn.disabled = false;
            }
        });

        function openEdit(id) {
            fetch(`?action=get_client&id=${id}`)
                .then(r => r.json())
                .then(data => {
                    if(data.success) {
                        const c = data.client;
                        document.getElementById('editClientId').value = c.id;
                        document.getElementById('editNewClientId').value = c.id;
                        document.getElementById('editName').value = c.name;
                        document.getElementById('editPhone').value = c.phone || '';
                        document.getElementById('editAddress').value = c.address || '';
                        document.getElementById('editGuarantorName').value = c.guarantor_name || '';
                        document.getElementById('editGuarantorPhone').value = c.guarantor_phone || '';
                        document.getElementById('editBranch').value = c.branch_id || '';
                        document.getElementById('editType').value = c.client_type || '23 Days';
                        document.getElementById('editStatus').value = c.status || 'active';
                        const dateValue = c.created_at ? c.created_at.replace(' ', 'T').substring(0, 16) : '';
                        document.getElementById('editCreatedAt').value = dateValue;
                        
                        document.getElementById('editModal').classList.add('show');
                    } else {
                        showToast(data.message || 'Client not found', 'error');
                    }
                })
                .catch(() => showToast('Failed to load client data', 'error'));
        }

        function closeEditModal() {
            document.getElementById('editModal').classList.remove('show');
            document.getElementById('editClientForm').reset();
        }

        document.getElementById('editModal').addEventListener('click', (e) => {
            if (e.target === document.getElementById('editModal')) closeEditModal();
        });

        // --- Events ---
        function setupEvents() {
            document.getElementById('searchInput').addEventListener('input', () => {
                document.getElementById('searchSpinner').style.display = 'block';
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(() => loadClients(1, false), 500);
            });
            
            ['unionFilter', 'savingsFilter', 'branchFilter', 'officerFilter', 'statusFilter', 'planTypeFilter'].forEach(id => {
                document.getElementById(id).addEventListener('change', () => loadClients(1, false));
            });
            
            document.getElementById('clearFilters').addEventListener('click', () => {
                document.getElementById('searchInput').value = '';
                document.querySelectorAll('select').forEach(s => s.selectedIndex = 0);
                loadClients(1, false);
            });
            
            document.getElementById('exportBtn').addEventListener('click', () => {
                const params = getFilterParams();
                params.append('action', 'export_csv');
                window.location.href = `?${params.toString()}`;
            });
        }

        // --- Utils ---
        function escapeHtml(text) {
            if(!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    </script>
</body>
</html>