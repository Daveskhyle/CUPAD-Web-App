<?php
// transfer_client.php
date_default_timezone_set('Africa/Lagos');
ini_set('date.timezone', 'Africa/Lagos');
session_start();
require_once '../includes/config.php';
$conn = getDbConnection();

// --- AUTH CHECK ---
if (!isset($_SESSION['user_role']) || !in_array($_SESSION['user_role'], ['admin', 'tm', 'zm'])) {
    header('Location: ../index.php');
    exit();
}

$base_path = '../';
$current_user = $_SESSION['username'] ?? '';
$user_role = $_SESSION['user_role'] ?? '';
$user_zone = $_SESSION['zone_id'] ?? null;
$user_area = $_SESSION['area_id'] ?? null;
$full_name = $_SESSION['full_name'] ?? 'User';

// --- PROFILE PICTURE FETCH ---
$my_pic = 'default_avatar.png';
try {
    $stmt = $conn->prepare("SELECT profile_pic FROM users WHERE username = ?");
    $stmt->execute([$current_user]);
    $user_data = $stmt->fetch();
    $my_pic = $user_data['profile_pic'] ?? 'default_avatar.png';
} catch (Exception $e) { }

$profile_pic_path = $base_path . 'uploads/' . $my_pic;
$has_profile_pic = file_exists($profile_pic_path) && $my_pic !== 'default_avatar.png';

// --- FETCH ZONES, AREAS, BRANCHES FOR DROPDOWNS ---
$zones = [];
$areas = [];
$branches = [];
$officers =[];

try {
    // Zones
    $stmt = $conn->query("SELECT id, name FROM zones ORDER BY name");
    $zones = $stmt->fetchAll();
    
    // Areas
    $stmt = $conn->query("SELECT id, name, zone_id FROM areas ORDER BY name");
    $areas = $stmt->fetchAll();
    
    // Branches with hierarchy info
    $stmt = $conn->query("
        SELECT b.id, b.name, b.zone_id, b.area_id, z.name as zone_name, a.name as area_name 
        FROM branches b 
        LEFT JOIN zones z ON b.zone_id = z.id 
        LEFT JOIN areas a ON b.area_id = a.id 
        WHERE b.status = 'active' 
        ORDER BY z.name, a.name, b.name
    ");
    $branches = $stmt->fetchAll();
    
} catch (PDOException $e) {
    error_log("Transfer Client - Fetch Error: " . $e->getMessage());
}

// --- HANDLE AJAX REQUESTS ---
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    
    // Get officers for a branch
    if ($_GET['action'] === 'get_officers' && isset($_GET['branch_id'])) {
        try {
            $stmt = $conn->prepare("
                SELECT u.username, u.full_name, u.role 
                FROM users u 
                LEFT JOIN assignments a ON u.username = a.co 
                WHERE (a.branch = ? OR u.branch_id = ?) 
                AND u.status = 'active' 
                AND u.role IN ('co', 'bm', 'am')
                GROUP BY u.username 
                ORDER BY u.full_name
            ");
            $stmt->execute([$_GET['branch_id'], $_GET['branch_id']]);
            $officers = $stmt->fetchAll();
            echo json_encode(['success' => true, 'officers' => $officers]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit();
    }
    
    // Get unions for a CO
    if ($_GET['action'] === 'get_co_unions' && isset($_GET['co_username'])) {
        try {
            $co_username = $_GET['co_username'];
            
            // Get unions from both clients table AND assignments table (matching union_groups.php logic)
            $stmt = $conn->prepare("
                SELECT DISTINCT union_name FROM (
                    SELECT `union` as union_name FROM clients 
                    WHERE officer_username = ? AND status = 'active' AND `union` IS NOT NULL AND `union` != '' AND `union` != 'Unassigned'
                    UNION
                    SELECT `union` as union_name FROM assignments 
                    WHERE co = ? AND status = 'active' AND `union` IS NOT NULL AND `union` != '' AND `union` != 'Unassigned'
                ) AS all_unions
                ORDER BY union_name ASC
            ");
            $stmt->execute([$co_username, $co_username]);
            $unions = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            echo json_encode(['success' => true, 'unions' => $unions, 'count' => count($unions)]);
        } catch (PDOException $e) {
            error_log("Union fetch error: " . $e->getMessage());
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit();
    }
    
    // Search clients
    if ($_GET['action'] === 'search_clients' && isset($_GET['query'])) {
        $query = '%' . $_GET['query'] . '%';
        try {
            // Build query based on user role permissions
            $sql = "
                SELECT c.id, c.name, c.phone, c.union, c.branch_id, c.officer_username,
                       b.name as branch_name, u.full_name as officer_name
                FROM clients c
                LEFT JOIN branches b ON c.branch_id = b.id
                LEFT JOIN users u ON c.officer_username = u.username
                WHERE (c.name LIKE ? OR c.phone LIKE ? OR c.id LIKE ?)
                AND c.status = 'active'
            ";
            $params = [$query, $query, $query];
            
            // Role-based filtering
            if ($user_role === 'zm' && $user_zone) {
                $sql .= " AND b.zone_id = ?";
                $params[] = $user_zone;
            } elseif ($user_role === 'am' && $user_area) {
                $sql .= " AND b.area_id = ?";
                $params[] = $user_area;
            }
            
            $sql .= " ORDER BY c.name LIMIT 10"; 
            
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            $clients = $stmt->fetchAll();
            echo json_encode(['success' => true, 'clients' => $clients]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit();
    }
    
    // Get client details
    if ($_GET['action'] === 'get_client' && isset($_GET['client_id'])) {
        try {
            $stmt = $conn->prepare("
                SELECT c.*, b.name as branch_name, b.zone_id, b.area_id,
                       z.name as zone_name, a.name as area_name,
                       u.full_name as officer_name
                FROM clients c
                LEFT JOIN branches b ON c.branch_id = b.id
                LEFT JOIN zones z ON b.zone_id = z.id
                LEFT JOIN areas a ON b.area_id = a.id
                LEFT JOIN users u ON c.officer_username = u.username
                WHERE c.id = ?
            ");
            $stmt->execute([$_GET['client_id']]);
            $client = $stmt->fetch();
            
            if ($client) {
                // Get client's active loans
                $stmt = $conn->prepare("
                    SELECT id, principal, remaining_balance, status 
                    FROM disbursements 
                    WHERE client_id = ? AND status = 'active'
                ");
                $stmt->execute([$_GET['client_id']]);
                $loans = $stmt->fetchAll();
                
                echo json_encode(['success' => true, 'client' => $client, 'loans' => $loans]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Client not found']);
            }
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit();
    }

    // Get recent transfers for AJAX refresh
    if ($_GET['action'] === 'get_recent_transfers') {
        try {
            $sql = "
                SELECT t.*, 
                       cb.name as from_branch_name, 
                       tb.name as to_branch_name,
                       u.full_name as transferred_by_name,
                       DATE_FORMAT(t.transfer_date, '%b %e, %Y %H:%i') as formatted_date
                FROM client_transfers t
                LEFT JOIN branches cb ON t.from_branch_id = cb.id
                LEFT JOIN branches tb ON t.to_branch_id = tb.id
                LEFT JOIN users u ON t.transferred_by = u.username
                WHERE 1=1
            ";
            
            if ($user_role === 'zm' && $user_zone) {
                $sql .= " AND (cb.zone_id = ? OR tb.zone_id = ?)";
                $params = [$user_zone, $user_zone];
            } elseif ($user_role === 'am' && $user_area) {
                $sql .= " AND (cb.area_id = ? OR tb.area_id = ?)";
                $params = [$user_area, $user_area];
            } else {
                $params = [];
            }
            
            $sql .= " ORDER BY t.transfer_date DESC LIMIT 10";
            
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            $transfers = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'transfers' => $transfers]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit();
    }
}

// --- HANDLE TRANSFER SUBMISSION ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    $client_id = $_POST['client_id'] ?? '';
    $target_branch_id = $_POST['target_branch_id'] ?? '';
    $target_officer = $_POST['target_officer'] ?? '';
    $target_union = $_POST['target_union'] ?? '';
    $transfer_reason = $_POST['transfer_reason'] ?? '';
    $transfer_type = $_POST['transfer_type'] ?? 'permanent'; 
    
    if (empty($client_id) || empty($target_branch_id) || empty($target_officer)) {
        echo json_encode(['success' => false, 'error' => 'Missing required fields']);
        exit();
    }
    
    try {
        $conn->beginTransaction();
        
        // Get current client info before transfer, including the old union
        $stmt = $conn->prepare("SELECT branch_id, officer_username, name, `union` FROM clients WHERE id = ?");
        $stmt->execute([$client_id]);
        $old_data = $stmt->fetch();
        
        if (!$old_data) {
            throw new Exception("Client not found");
        }
        
        $old_branch = $old_data['branch_id'];
        $old_officer = $old_data['officer_username'];
        $client_name = $old_data['name'];
        $final_union = !empty($target_union) ? $target_union : $old_data['union'];
        
        // Update client (Now handles Union if provided)
        if (!empty($target_union)) {
            $stmt = $conn->prepare("
                UPDATE clients 
                SET branch_id = ?, officer_username = ?, `union` = ?, updated_at = NOW() 
                WHERE id = ?
            ");
            $stmt->execute([$target_branch_id, $target_officer, $target_union, $client_id]);
        } else {
            $stmt = $conn->prepare("
                UPDATE clients 
                SET branch_id = ?, officer_username = ?, updated_at = NOW() 
                WHERE id = ?
            ");
            $stmt->execute([$target_branch_id, $target_officer, $client_id]);
        }
        
        // Log the transfer
        $stmt = $conn->prepare("
            INSERT INTO client_transfers 
            (client_id, client_name, from_branch_id, from_officer, to_branch_id, to_officer, 
             transferred_by, transfer_type, reason, transfer_date, status) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'completed')
        ");
        $stmt->execute([
            $client_id, $client_name, $old_branch, $old_officer,
            $target_branch_id, $target_officer, $current_user, $transfer_type, $transfer_reason
        ]);
        
        // Ensure the new officer is assigned to this client's union at the new branch
        if (!empty($final_union) && $final_union !== 'Unassigned') {
            $stmt = $conn->prepare("
                INSERT IGNORE INTO assignments (branch, co, `union`, assigned_date, status) 
                VALUES (?, ?, ?, CURDATE(), 'active')
            ");
            $stmt->execute([$target_branch_id, $target_officer, $final_union]);
        }
        
        // Log activity
        $stmt = $conn->prepare("
            INSERT INTO activity_log (user, action, details, ip_address) 
            VALUES (?, 'client_transfer', ?, ?)
        ");
        $details = json_encode([
            'client_id' => $client_id,
            'client_name' => $client_name,
            'from_branch' => $old_branch,
            'to_branch' => $target_branch_id,
            'from_officer' => $old_officer,
            'to_officer' => $target_officer,
            'union' => $target_union,
            'type' => $transfer_type
        ]);
        $stmt->execute([$current_user, $details, $_SERVER['REMOTE_ADDR'] ?? '']);
        
        $conn->commit();
        
        echo json_encode([
            'success' => true, 
            'message' => 'Client transferred successfully',
            'client_name' => $client_name
        ]);
        
    } catch (Exception $e) {
        $conn->rollBack();
        error_log("Transfer Error: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

// --- PAGE LOAD: GET RECENT TRANSFERS ---
$recent_transfers =[];
try {
    $sql = "
        SELECT t.*, 
               cb.name as from_branch_name, 
               tb.name as to_branch_name,
               u.full_name as transferred_by_name,
               DATE_FORMAT(t.transfer_date, '%b %e, %Y %H:%i') as formatted_date
        FROM client_transfers t
        LEFT JOIN branches cb ON t.from_branch_id = cb.id
        LEFT JOIN branches tb ON t.to_branch_id = tb.id
        LEFT JOIN users u ON t.transferred_by = u.username
        WHERE 1=1
    ";
    
    // Role-based filtering for transfer history
    if ($user_role === 'zm' && $user_zone) {
        $sql .= " AND (cb.zone_id = ? OR tb.zone_id = ?)";
        $params =[$user_zone, $user_zone];
    } elseif ($user_role === 'am' && $user_area) {
        $sql .= " AND (cb.area_id = ? OR tb.area_id = ?)";
        $params = [$user_area, $user_area];
    } else {
        $params =[];
    }
    
    $sql .= " ORDER BY t.transfer_date DESC LIMIT 10";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $recent_transfers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Recent Transfers Error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en" class="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transfer Client - CUPAD</title>
    <link rel="icon" type="image/png" href="<?php echo htmlspecialchars($base_path . 'uploads/CUPAD LOGO.png'); ?>">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />
    <style>
        :root { 
            --primary: #2563eb; --primary-dark: #1d4ed8; --success: #059669; --warning: #d97706; 
            --danger: #dc2626; --info: #0284c7; --bg-body: #f1f5f9; --bg-surface: #ffffff; 
            --text-main: #0f172a; --text-muted: #64748b; --border: #e2e8f0; 
            --radius-lg: 16px; --radius-md: 10px; --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05); 
            --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1); --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1); 
            --nav-height: 70px; 
        }
        html.dark { 
            --bg-body: #0f172a; --bg-surface: #1e293b; --text-main: #f8fafc; 
            --text-muted: #94a3b8; --border: #334155; --primary: #3b82f6; 
            --success: #34d399; --warning: #fbbf24; --danger: #f87171; --info: #38bdf8;
        }
        * { box-sizing: border-box; outline: none; }
        body { 
            font-family: 'Plus Jakarta Sans', sans-serif; background: var(--bg-body); 
            color: var(--text-main); margin: 0; padding-top: var(--nav-height); 
            transition: background-color 0.3s, color 0.3s; 
        }
        a { text-decoration: none; color: inherit; }
        
        /* Header Setup */
        .main-header { 
            position: fixed; top: 0; left: 0; right: 0; height: var(--nav-height); 
            background: rgba(255,255,255,0.85); backdrop-filter: blur(12px); 
            border-bottom: 1px solid var(--border); z-index: 50; transition: background 0.3s;
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
        
        /* User Dropdown */
        .user-dropdown-wrap { position: relative; }
        .user-pill { 
            display: flex; align-items: center; gap: 0.75rem; padding: 4px 8px 4px 4px; 
            border: 1px solid var(--border); border-radius: 99px; 
            background: var(--bg-surface); cursor: pointer; transition: all 0.2s;
        }
        .user-pill:hover { border-color: var(--primary); box-shadow: var(--shadow-sm); }
        .user-avatar { width: 34px; height: 34px; border-radius: 50%; object-fit: cover; }
        .user-avatar-fallback { 
            width: 34px; height: 34px; border-radius: 50%; background: var(--bg-body); 
            color: var(--text-muted); display: flex; align-items: center; justify-content: center; 
            font-size: 1rem; border: 1px solid var(--border); 
        }
        .user-info { display: flex; flex-direction: column; line-height: 1.1; padding-right: 0.5rem; }
        .user-name { font-weight: 600; font-size: 0.85rem; }
        .user-role { font-size: 0.7rem; color: var(--text-muted); text-transform: uppercase; }
        
        .dropdown-menu { 
            position: absolute; top: 125%; right: 0; background: var(--bg-surface); 
            border: 1px solid var(--border); border-radius: var(--radius-md); 
            box-shadow: var(--shadow-md); min-width: 200px; display: none; 
            z-index: 1000; flex-direction: column; overflow: hidden; 
            animation: scaleIn 0.2s ease; transform-origin: top right; 
        }
        @keyframes scaleIn { from { opacity: 0; transform: scale(0.95); } to { opacity: 1; transform: scale(1); } }
        .dropdown-menu.show { display: flex; }
        .dropdown-item { 
            padding: 0.75rem 1rem; display: flex; align-items: center; 
            gap: 0.75rem; font-size: 0.9rem; transition: 0.2s; color: var(--text-main);
        }
        .dropdown-item:hover { background: var(--bg-body); color: var(--primary); }
        .text-danger { color: var(--danger) !important; }
        
        /* Container */
        .container { max-width: 1200px; margin: 0 auto; padding: 2rem 1.5rem 4rem; }
        
        /* Page Header */
        .page-header { 
            display: flex; justify-content: space-between; align-items: center; 
            margin-bottom: 2rem; flex-wrap: wrap; gap: 1rem;
        }
        .page-title { margin: 0; font-size: 1.75rem; font-weight: 800; color: var(--text-main); }
        .page-subtitle { margin: 0.25rem 0 0; color: var(--text-muted); font-size: 0.95rem; }
        
        /* Transfer Grid */
        .transfer-grid { 
            display: grid; grid-template-columns: 1fr auto 1fr; gap: 1.5rem; 
            margin-bottom: 2rem; align-items: start;
        }
        @media (max-width: 1024px) { 
            .transfer-grid { grid-template-columns: 1fr; gap: 1rem; } 
        }
        
        /* Cards */
        .card { 
            background: var(--bg-surface); border: 1px solid var(--border); 
            border-radius: var(--radius-lg); padding: 1.5rem; 
            box-shadow: var(--shadow-sm); position: relative;
            height: 100%; display: flex; flex-direction: column;
        }
        .card-header { 
            display: flex; justify-content: flex-start; align-items: center; 
            margin-bottom: 1.25rem; padding-bottom: 1rem; 
            border-bottom: 1px solid var(--border);
        }
        
        .step-badge {
            display: inline-flex; align-items: center; justify-content: center;
            width: 32px; height: 32px; border-radius: 50%;
            background: var(--primary); color: white; font-size: 0.9rem; 
            font-weight: 700; margin-right: 0.75rem; flex-shrink: 0;
            box-shadow: 0 4px 6px -1px rgba(37,99,235,0.2);
        }
        .step-badge.destination { background: var(--success); box-shadow: 0 4px 6px -1px rgba(5,150,105,0.2); }
        .step-badge.details { background: var(--warning); box-shadow: 0 4px 6px -1px rgba(217,119,6,0.2); }
        
        .card-title { 
            font-size: 1.1rem; font-weight: 700; color: var(--text-main);
            display: flex; align-items: center; margin: 0;
        }
        
        /* Form Elements */
        .form-group { margin-bottom: 1.25rem; }
        .form-label { 
            display: block; margin-bottom: 0.5rem; 
            font-weight: 600; font-size: 0.9rem; color: var(--text-main);
        }
        .form-control { 
            width: 100%; padding: 0.8rem 1rem; 
            border-radius: var(--radius-md); border: 1px solid var(--border); 
            background: var(--bg-body); color: var(--text-main); 
            font-family: inherit; font-size: 0.95rem; transition: 0.2s; 
        }
        .form-control:focus { 
            border-color: var(--primary); box-shadow: 0 0 0 3px rgba(37,99,235,0.1); 
        }
        .form-control:disabled {
            background: var(--bg-body); opacity: 0.6; cursor: not-allowed;
            border-color: var(--border);
        }
        
        select.form-control { cursor: pointer; appearance: none; }
        select.form-control:disabled { cursor: not-allowed; }
        .select-wrapper { position: relative; }
        .select-wrapper::after {
            content: '\f078'; font-family: 'Font Awesome 6 Free'; font-weight: 900;
            position: absolute; right: 15px; top: 50%; transform: translateY(-50%);
            color: var(--text-muted); font-size: 0.8rem; pointer-events: none;
        }
        textarea.form-control { resize: vertical; min-height: 100px; }
        
        /* Enhanced Search Box */
        .search-box { position: relative; margin-bottom: 1rem; z-index: 10; }
        .search-box input { 
            width: 100%; padding: 1rem 2.5rem 1rem 3rem; 
            border-radius: var(--radius-md); border: 1px solid var(--border); 
            background: var(--bg-body); font-size: 1rem; color: var(--text-main);
            transition: all 0.2s;
        }
        .search-box input:focus {
            border-color: var(--primary); 
            box-shadow: 0 0 0 3px rgba(37,99,235,0.1); background: var(--bg-surface);
        }
        .search-icon { 
            position: absolute; left: 1rem; top: 50%; 
            transform: translateY(-50%); color: var(--text-muted); font-size: 1.1rem;
        }
        .search-spinner {
            position: absolute; right: 1rem; top: 50%; transform: translateY(-50%);
            color: var(--primary); display: none;
        }
        .search-clear {
            position: absolute; right: 1rem; top: 50%; transform: translateY(-50%);
            color: var(--text-muted); cursor: pointer; display: none; padding: 5px;
        }
        .search-clear:hover { color: var(--danger); }
        
        /* Floating Search Results */
        .search-results { 
            position: absolute; top: calc(100% + 5px); left: 0; right: 0;
            max-height: 300px; overflow-y: auto; z-index: 20;
            border: 1px solid var(--border); border-radius: var(--radius-md);
            background: var(--bg-surface); box-shadow: var(--shadow-lg);
            display: none;
        }
        .search-result-item { 
            padding: 1rem; border-bottom: 1px solid var(--border); 
            cursor: pointer; transition: 0.2s;
        }
        .search-result-item:hover { background: var(--bg-body); }
        .search-result-item:last-child { border-bottom: none; }
        .result-name { font-weight: 600; color: var(--text-main); }
        .result-meta { 
            font-size: 0.8rem; color: var(--text-muted); 
            margin-top: 0.25rem; display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: center;
        }
        
        /* Enhanced Client Preview */
        .client-preview { 
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white; padding: 1.5rem; border-radius: var(--radius-md);
            margin-bottom: 1rem; display: none; box-shadow: var(--shadow-md);
            position: relative; overflow: hidden;
        }
        .client-preview::before {
            content: ''; position: absolute; top: -50px; right: -50px;
            width: 150px; height: 150px; background: rgba(255,255,255,0.1);
            border-radius: 50%; pointer-events: none;
        }
        .client-preview.active { display: block; animation: slideDown 0.3s ease; }
        @keyframes slideDown { from { opacity: 0; transform: translateY(-10px); } }
        
        .preview-header { 
            display: flex; justify-content: space-between; align-items: flex-start;
            margin-bottom: 1rem; position: relative; z-index: 2;
        }
        .preview-avatar {
            width: 48px; height: 48px; border-radius: 50%; background: rgba(255,255,255,0.2);
            display: flex; align-items: center; justify-content: center; font-size: 1.5rem;
            margin-right: 1rem; flex-shrink: 0; border: 2px solid rgba(255,255,255,0.5);
        }
        .preview-name-container { flex: 1; }
        .preview-name { font-size: 1.25rem; font-weight: 700; margin: 0 0 0.2rem 0; line-height: 1.2; }
        .preview-id { font-size: 0.75rem; opacity: 0.9; background: rgba(0,0,0,0.2); padding: 2px 8px; border-radius: 12px; display: inline-block;}
        
        .preview-details { 
            display: grid; grid-template-columns: repeat(2, 1fr); gap: 0.75rem;
            font-size: 0.85rem; position: relative; z-index: 2;
            background: rgba(0,0,0,0.15); padding: 1rem; border-radius: 8px;
        }
        .preview-item { display: flex; align-items: center; gap: 0.5rem; }
        .preview-item i { width: 16px; opacity: 0.7; }
        
        /* Transfer Arrow Flow */
        .transfer-arrow-container {
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            height: 100%; padding: 2rem 0;
        }
        .transfer-arrow { 
            display: flex; align-items: center; justify-content: center;
            width: 60px; height: 60px; border-radius: 50%;
            background: var(--bg-surface); border: 2px solid var(--border);
            font-size: 1.5rem; color: var(--primary); box-shadow: var(--shadow-sm);
            z-index: 5; position: relative; transition: all 0.3s ease;
        }
        .transfer-arrow::after {
            content: ''; position: absolute; top: -8px; left: -8px; right: -8px; bottom: -8px;
            border-radius: 50%; border: 1px dashed var(--primary); opacity: 0.3;
            animation: rotatePulse 10s linear infinite;
        }
        @keyframes rotatePulse {
            0% { transform: rotate(0deg) scale(1); }
            50% { transform: rotate(180deg) scale(1.05); }
            100% { transform: rotate(360deg) scale(1); }
        }
        @media (max-width: 1024px) { 
            .transfer-arrow-container { padding: 0.5rem 0; }
            .transfer-arrow { transform: rotate(90deg); width: 45px; height: 45px; font-size: 1.2rem; } 
        }
        
        /* Info & Warning Boxes */
        .info-box { 
            background: #dbeafe; border-left: 4px solid var(--primary);
            padding: 1rem; border-radius: 0 var(--radius-md) var(--radius-md) 0;
            margin-bottom: 1.25rem; font-size: 0.9rem; color: #1e40af;
            display: flex; gap: 0.75rem; align-items: flex-start;
        }
        .info-box i { font-size: 1.1rem; margin-top: 2px; }
        html.dark .info-box { background: rgba(59, 130, 246, 0.15); color: #93c5fd; }
        
        .warning-box { 
            background: #fef3c7; border-left: 4px solid var(--warning);
            padding: 1rem; border-radius: 0 var(--radius-md) var(--radius-md) 0;
            margin-top: 1rem; font-size: 0.85rem; color: #92400e;
            display: flex; gap: 0.75rem; align-items: flex-start;
        }
        html.dark .warning-box { background: rgba(217, 119, 6, 0.15); color: #fcd34d; }
        
        /* Buttons */
        .btn { 
            display: inline-flex; align-items: center; justify-content: center;
            gap: 0.5rem; padding: 0.875rem 1.5rem; 
            border-radius: var(--radius-md); border: none;
            font-weight: 600; font-size: 0.95rem; cursor: pointer;
            transition: all 0.2s; font-family: inherit; position: relative;
            overflow: hidden;
        }
        .btn-primary { background: var(--primary); color: white; }
        .btn-primary:hover { background: var(--primary-dark); }
        .btn-secondary { background: var(--bg-body); color: var(--text-main); border: 1px solid var(--border); }
        .btn-secondary:hover { background: var(--border); }
        .btn-success { background: var(--success); color: white; }
        .btn-success:hover { filter: brightness(0.9); }
        .btn:disabled { opacity: 0.6; cursor: not-allowed; }
        
        .btn-group { display: flex; gap: 0.75rem; margin-top: auto; padding-top: 1rem;}
        
        /* Spinner */
        .spinner { 
            width: 20px; height: 20px; 
            border: 3px solid rgba(255,255,255,0.3); border-radius: 50%;
            border-top-color: white; animation: spin 1s linear infinite;
            display: none;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        .btn.loading .spinner { display: block; }
        .btn.loading .btn-text { display: none; }
        
        /* Transfer Type Toggle */
        .transfer-type { 
            display: flex; gap: 0.5rem; margin-bottom: 1.5rem;
            background: var(--bg-body); padding: 0.35rem; border-radius: var(--radius-md);
        }
        .type-option { 
            flex: 1; padding: 0.75rem; text-align: center; color: var(--text-muted);
            border-radius: 8px; cursor: pointer; font-size: 0.9rem;
            font-weight: 600; transition: 0.2s; display: flex; align-items: center; justify-content: center; gap: 0.5rem;
        }
        .type-option:hover { background: var(--bg-surface); color: var(--text-main); }
        .type-option.active { 
            background: var(--bg-surface); color: var(--primary);
            box-shadow: var(--shadow-sm);
        }
        
        /* History Table */
        .history-table { width: 100%; border-collapse: collapse; font-size: 0.9rem; }
        .history-table th, .history-table td { 
            padding: 1rem; text-align: left; 
            border-bottom: 1px solid var(--border); color: var(--text-main);
        }
        .history-table th { 
            font-weight: 600; color: var(--text-muted); 
            text-transform: uppercase; font-size: 0.75rem; letter-spacing: 0.5px;
            background: var(--bg-body);
        }
        .history-table tr:hover td { background: var(--bg-body); }
        
        .branch-flow { display: flex; align-items: center; gap: 0.5rem; }
        .branch-flow i { color: var(--text-muted); font-size: 0.8rem; }
        .officer-name { font-size: 0.8rem; color: var(--text-muted); display: block; margin-top: 2px;}
        
        .status-badge { 
            display: inline-flex; align-items: center; gap: 0.35rem;
            padding: 0.35rem 0.85rem; border-radius: 99px; font-size: 0.75rem; font-weight: 600;
        }
        .status-completed { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0;}
        .status-pending { background: #fef3c7; color: #92400e; border: 1px solid #fde68a;}
        html.dark .status-completed { background: rgba(16, 185, 129, 0.15); color: #34d399; border-color: rgba(52, 211, 153, 0.2);}
        html.dark .status-pending { background: rgba(217, 119, 6, 0.15); color: #fbbf24; border-color: rgba(251, 191, 36, 0.2);}
        
        .type-badge {
            font-size: 0.75rem; padding: 2px 8px; border-radius: 6px; 
            background: var(--bg-body); border: 1px solid var(--border); font-weight: 600;
        }
        
        /* Toast */
        .toast-container { 
            position: fixed; top: 85px; right: 20px; z-index: 100; 
            display: flex; flex-direction: column; gap: 10px; pointer-events: none; 
        }
        .toast { 
            pointer-events: auto; background: var(--bg-surface); color: var(--text-main); 
            padding: 1rem; border-radius: var(--radius-md); box-shadow: var(--shadow-md); 
            border-left: 4px solid var(--primary); min-width: 250px; 
            animation: slideIn 0.3s forwards; display: flex; align-items: center; 
            gap: 0.75rem; font-size: 0.9rem; opacity: 0; transform: translateX(50px); 
        }
        .toast.success { border-left-color: var(--success); }
        .toast.error { border-left-color: var(--danger); }
        @keyframes slideIn { from { opacity: 0; transform: translateX(50px); } to { opacity: 1; transform: translateX(0); } }
        
        /* Empty State */
        .empty-state { 
            text-align: center; padding: 3rem 1rem; color: var(--text-muted);
        }
        .empty-state i { font-size: 3rem; margin-bottom: 1rem; opacity: 0.3; }
        
        /* Responsive */
        @media (max-width: 768px) {
            .container { padding: 1rem; }
            .page-header { flex-direction: column; align-items: flex-start; }
            .preview-details { grid-template-columns: 1fr; }
            .btn-group { flex-direction: column; }
            .history-table { font-size: 0.8rem; }
            .history-table th, .history-table td { padding: 0.75rem 0.5rem; }
            .branch-flow { flex-direction: column; align-items: flex-start; gap: 0.2rem; }
            .branch-flow i { transform: rotate(90deg); margin: 2px 0; }
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
                <button class="icon-btn" onclick="history.back()" title="Go Back">
                    <i class="fas fa-arrow-left"></i>
                </button>
                <a href="dashboard.php" class="icon-btn" title="Dashboard">
                    <i class="fas fa-home"></i>
                </a>
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
                            <span class="user-role"><?php echo strtoupper($user_role); ?></span>
                        </div>
                        <i class="fas fa-chevron-down" style="font-size:0.7rem; color:var(--text-muted)"></i>
                    </div>
                    <div class="dropdown-menu" id="userDropdown">
                        <div style="padding: 0.75rem 1rem; font-size: 0.75rem; color:var(--text-muted); font-weight:600">SIGNED IN AS</div>
                        <div style="padding: 0 1rem 0.5rem; font-weight:700"><?php echo htmlspecialchars($current_user); ?></div>
                        <div style="height:1px; background:var(--border); margin:0"></div>
                        <a href="profile.php" class="dropdown-item"><i class="fas fa-user-circle" style="color:var(--primary)"></i> My Profile</a>
                        <div style="height:1px; background:var(--border); margin:0"></div>
                        <a href="../logout.php" class="dropdown-item text-danger"><i class="fas fa-sign-out-alt"></i> Logout</a>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <div class="toast-container" id="toastContainer"></div>

    <main class="container">
        <!-- Page Header -->
        <div class="page-header">
            <div>
                <h1 class="page-title">Transfer Client</h1>
                <p class="page-subtitle">Reassign clients between branches, Credit Officers, and Unions seamlessly.</p>
            </div>
            <a href="transfer_history.php" class="btn btn-secondary">
                <i class="fas fa-history"></i> View Transfer History
            </a>
        </div>

        <!-- Transfer Form -->
        <form id="transferForm">
            <div class="transfer-grid">
                
                <!-- COLUMN 1: Source / Select Client -->
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">
                            <span class="step-badge">1</span> Select Client
                        </h2>
                    </div>
                    
                    <div class="search-box">
                        <i class="fas fa-search search-icon"></i>
                        <input type="text" id="clientSearch" placeholder="Search by name, phone, or client ID..." autocomplete="off">
                        <i class="fas fa-spinner fa-spin search-spinner" id="searchSpinner"></i>
                        <i class="fas fa-times search-clear" id="searchClear" onclick="clearClientSearch()"></i>
                        
                        <!-- Floating Results -->
                        <div class="search-results" id="searchResults"></div>
                    </div>
                    
                    <!-- Selected Client Preview -->
                    <div class="client-preview" id="clientPreview">
                        <div class="preview-header">
                            <div style="display: flex; align-items: center;">
                                <div class="preview-avatar" id="previewInitials">C</div>
                                <div class="preview-name-container">
                                    <h3 class="preview-name" id="previewName">-</h3>
                                    <span class="preview-id" id="previewId">-</span>
                                </div>
                            </div>
                            <button type="button" class="icon-btn" onclick="clearClient()" style="color: white; background: rgba(255,255,255,0.2); width:30px; height:30px;">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                        
                        <div class="preview-details">
                            <div class="preview-item" title="Phone Number">
                                <i class="fas fa-phone"></i>
                                <span id="previewPhone">-</span>
                            </div>
                            <div class="preview-item" title="Union">
                                <i class="fas fa-users"></i>
                                <span id="previewUnion">-</span>
                            </div>
                            <div class="preview-item" title="Current Branch">
                                <i class="fas fa-building"></i>
                                <span id="previewBranch">-</span>
                            </div>
                            <div class="preview-item" title="Current Officer">
                                <i class="fas fa-user-tie"></i>
                                <span id="previewOfficer">-</span>
                            </div>
                        </div>
                        
                        <div id="loanWarning" class="warning-box">
                            <i class="fas fa-exclamation-triangle" style="margin-top:2px;"></i>
                            <div>
                                <strong>Active Loans (<span id="activeLoanCount">0</span>)</strong><br>
                                Proceeding will automatically update these loan assignments to the new officer.
                            </div>
                        </div>
                    </div>
                    
                    <input type="hidden" name="client_id" id="selectedClientId">
                </div>

                <!-- ARROW -->
                <div class="transfer-arrow-container">
                    <div class="transfer-arrow">
                        <i class="fas fa-long-arrow-alt-right"></i>
                    </div>
                </div>

                <!-- COLUMN 2: Destination -->
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">
                            <span class="step-badge destination">2</span> Destination
                        </h2>
                    </div>
                    
                    <div class="info-box">
                        <i class="fas fa-info-circle"></i>
                        <div>Select the target branch and assign a new Credit Officer (or Union) for this client.</div>
                    </div>
                    
                    <!-- Zone Selection -->
                    <div class="form-group">
                        <label class="form-label">Zone</label>
                        <div class="select-wrapper">
                            <select class="form-control" id="zoneSelect" onchange="filterAreas()">
                                <option value="">Select Zone</option>
                                <?php foreach ($zones as $zone): ?>
                                    <option value="<?php echo htmlspecialchars($zone['id']); ?>">
                                        <?php echo htmlspecialchars($zone['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <!-- Area Selection -->
                    <div class="form-group">
                        <label class="form-label">Area</label>
                        <div class="select-wrapper">
                            <select class="form-control" id="areaSelect" onchange="filterBranches()" disabled>
                                <option value="">Select Area first</option>
                            </select>
                        </div>
                    </div>
                    
                    <!-- Branch Selection -->
                    <div class="form-group">
                        <label class="form-label">Branch</label>
                        <div class="select-wrapper">
                            <select class="form-control" name="target_branch_id" id="branchSelect" onchange="loadOfficers()" disabled required>
                                <option value="">Select Branch first</option>
                            </select>
                        </div>
                    </div>
                    
                    <!-- Officer Selection -->
                    <div class="form-group">
                        <label class="form-label">Credit Officer</label>
                        <div class="select-wrapper">
                            <select class="form-control" name="target_officer" id="officerSelect" disabled required>
                                <option value="">Select Officer first</option>
                            </select>
                        </div>
                    </div>
                    
                    <!-- Union Selection (appears when CO is selected) -->
                    <div class="form-group" id="unionGroup" style="display: none;">
                        <label class="form-label">Union (Optional)</label>
                        <div class="select-wrapper">
                            <select class="form-control" name="target_union" id="unionSelect" disabled>
                                <option value="">Select Union</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Transfer Details (Full Width Bottom) -->
            <div class="card" style="margin-bottom: 2rem;">
                <div class="card-header">
                    <h2 class="card-title">
                        <span class="step-badge details">3</span> Transfer Details
                    </h2>
                </div>
                
                <div class="transfer-type">
                    <div class="type-option active" onclick="setTransferType('permanent', this)">
                        <i class="fas fa-exchange-alt"></i> Permanent Transfer
                    </div>
                    <div class="type-option" onclick="setTransferType('temporary', this)">
                        <i class="fas fa-clock"></i> Temporary Assignment
                    </div>
                </div>
                <input type="hidden" name="transfer_type" id="transferType" value="permanent">
                
                <div class="form-group">
                    <label class="form-label">Reason for Transfer</label>
                    <textarea name="transfer_reason" id="transferReason" class="form-control" placeholder="Briefly explain the reason (Optional)"></textarea>
                </div>
                
                <div class="btn-group">
                    <button type="button" class="btn btn-secondary" onclick="resetForm()">
                        <i class="fas fa-undo"></i> Reset Form
                    </button>
                    <button type="submit" class="btn btn-success" id="submitBtn" disabled style="flex:1;">
                        <span class="btn-text">
                            <i class="fas fa-check-circle"></i> Confirm Client Transfer
                        </span>
                        <div class="spinner"></div>
                    </button>
                </div>
            </div>
        </form>

        <!-- Recent Transfers Table -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">
                    <div class="icon-btn" style="background: rgba(2,132,199,0.1); color: var(--info); margin-right: 0.75rem; width: 32px; height: 32px;"><i class="fas fa-history"></i></div>
                    Recent Transfers
                </h2>
            </div>
            
            <div class="empty-state" id="emptyTransfersState" style="<?php echo empty($recent_transfers) ? 'display:block;' : 'display:none;'; ?>">
                <i class="fas fa-exchange-alt"></i>
                <p>No transfers recorded in your jurisdiction yet.</p>
            </div>
            
            <div style="overflow-x: auto; <?php echo empty($recent_transfers) ? 'display:none;' : 'display:block;'; ?>" id="transfersTableContainer">
                <table class="history-table">
                    <thead>
                        <tr>
                            <th>Client Info</th>
                            <th>Transfer Path</th>
                            <th>Type</th>
                            <th>Processed By</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody id="recentTransfersBody">
                        <?php foreach ($recent_transfers as $transfer): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($transfer['client_name']); ?></strong>
                                    <div style="font-size:0.75rem; color:var(--text-muted); margin-top:2px;">
                                        ID: <?php echo substr($transfer['client_id'], 0, 8); ?>...
                                    </div>
                                </td>
                                <td>
                                    <div class="branch-flow">
                                        <div>
                                            <strong><?php echo htmlspecialchars($transfer['from_branch_name'] ?? 'Unknown'); ?></strong>
                                            <span class="officer-name">CO: <?php echo htmlspecialchars($transfer['from_officer']); ?></span>
                                        </div>
                                        <i class="fas fa-arrow-right"></i>
                                        <div>
                                            <strong><?php echo htmlspecialchars($transfer['to_branch_name'] ?? 'Unknown'); ?></strong>
                                            <span class="officer-name">CO: <?php echo htmlspecialchars($transfer['to_officer']); ?></span>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="type-badge">
                                        <?php echo ucfirst($transfer['transfer_type']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($transfer['transferred_by_name'] ?? $transfer['transferred_by']); ?><br>
                                    <span style="font-size:0.7rem; color:var(--text-muted);"><?php echo htmlspecialchars($transfer['formatted_date']); ?></span>
                                </td>
                                <td>
                                    <span class="status-badge status-<?php echo $transfer['status']; ?>">
                                        <i class="fas fa-check-circle"></i>
                                        <?php echo ucfirst($transfer['status']); ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <script>
        // --- 1. USER DROPDOWN & THEME TOGGLE ---
        const userTrigger = document.getElementById('userDropdownTrigger');
        const userDropdown = document.getElementById('userDropdown');

        userTrigger.addEventListener('click', (e) => { 
            e.stopPropagation(); 
            userDropdown.classList.toggle('show'); 
        });
        
        document.addEventListener('click', (e) => { 
            if (!userTrigger.contains(e.target) && !userDropdown.contains(e.target)) {
                userDropdown.classList.remove('show'); 
            }
        });

        // Theme Setup
        const themeToggleBtn = document.getElementById('themeToggle');
        themeToggleBtn.addEventListener('click', () => {
            const html = document.documentElement;
            const isDark = html.classList.toggle('dark');
            html.classList.toggle('light');
            localStorage.setItem('theme', isDark ? 'dark' : 'light');
            themeToggleBtn.innerHTML = isDark ? '<i class="fas fa-sun"></i>' : '<i class="fas fa-moon"></i>';
        });

        if(localStorage.getItem('theme') === 'dark') {
            document.documentElement.classList.add('dark'); 
            document.documentElement.classList.remove('light');
            themeToggleBtn.innerHTML = '<i class="fas fa-sun"></i>';
        }

        // --- 2. DATA LOADED FROM PHP ---
        const areas = <?php echo json_encode($areas); ?>;
        const branches = <?php echo json_encode($branches); ?>;
        let selectedClient = null;
        
        // --- 3. CLIENT SEARCH ---
        const clientSearch = document.getElementById('clientSearch');
        const searchResults = document.getElementById('searchResults');
        const searchSpinner = document.getElementById('searchSpinner');
        const searchClear = document.getElementById('searchClear');
        let searchTimeout;
        
        clientSearch.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            const query = this.value.trim();
            
            searchClear.style.display = query.length > 0 ? 'block' : 'none';
            
            if (query.length < 2) {
                searchResults.style.display = 'none';
                searchSpinner.style.display = 'none';
                return;
            }
            
            searchSpinner.style.display = 'block';
            searchTimeout = setTimeout(() => searchClients(query), 400);
        });
        
        function clearClientSearch() {
            clientSearch.value = '';
            searchResults.style.display = 'none';
            searchClear.style.display = 'none';
            clientSearch.focus();
        }
        
        async function searchClients(query) {
            try {
                const res = await fetch(`?action=search_clients&query=${encodeURIComponent(query)}`);
                const data = await res.json();
                
                if (data.success && data.clients.length > 0) {
                    renderSearchResults(data.clients);
                } else {
                    searchResults.innerHTML = '<div class="search-result-item" style="text-align:center; color:var(--text-muted);"><i class="fas fa-search" style="opacity:0.5; display:block; margin-bottom:5px; font-size:1.5rem;"></i>No clients found matching "'+escapeHtml(query)+'"</div>';
                    searchResults.style.display = 'block';
                }
            } catch (e) {
                console.error('Search error:', e);
            } finally {
                searchSpinner.style.display = 'none';
            }
        }
        
        function renderSearchResults(clients) {
            searchResults.innerHTML = clients.map(c => `
                <div class="search-result-item" onclick="selectClient('${c.id}')">
                    <div class="result-name">${escapeHtml(c.name)}</div>
                    <div class="result-meta">
                        <span><i class="fas fa-phone"></i> ${c.phone || 'N/A'}</span>
                        <span><i class="fas fa-building"></i> ${escapeHtml(c.branch_name || 'N/A')}</span>
                        <span><i class="fas fa-user-tie"></i> CO: ${escapeHtml(c.officer_name || c.officer_username || 'N/A')}</span>
                    </div>
                </div>
            `).join('');
            searchResults.style.display = 'block';
        }
        
        async function selectClient(clientId) {
            searchResults.style.display = 'none';
            clientSearch.value = 'Loading client details...';
            clientSearch.disabled = true;
            searchClear.style.display = 'none';
            
            try {
                const res = await fetch(`?action=get_client&client_id=${encodeURIComponent(clientId)}`);
                const data = await res.json();
                
                if (data.success) {
                    selectedClient = data.client;
                    displayClientPreview(data.client, data.loans);
                    document.getElementById('selectedClientId').value = clientId;
                    clientSearch.value = data.client.name;
                    searchClear.style.display = 'block';
                    checkFormValidity();
                } else {
                    clientSearch.value = '';
                    showToast('Client not found', 'error');
                }
            } catch (e) {
                console.error('Select client error:', e);
                clientSearch.value = '';
                showToast('Network error loading client', 'error');
            } finally {
                clientSearch.disabled = false;
            }
        }
        
        function displayClientPreview(client, loans) {
            document.getElementById('previewName').textContent = client.name;
            document.getElementById('previewId').textContent = 'ID: ' + client.id;
            
            // Set initials for avatar
            const initials = client.name.split(' ').map(n => n[0]).join('').substring(0, 2).toUpperCase();
            document.getElementById('previewInitials').textContent = initials || 'C';
            
            document.getElementById('previewPhone').textContent = client.phone || 'N/A';
            document.getElementById('previewUnion').textContent = client.union || 'No Union';
            document.getElementById('previewBranch').textContent = client.branch_name || 'No Branch';
            document.getElementById('previewOfficer').textContent = client.officer_name || client.officer_username || 'Unassigned';
            
            const loanWarning = document.getElementById('loanWarning');
            if (loans && loans.length > 0) {
                document.getElementById('activeLoanCount').textContent = loans.length;
                loanWarning.style.display = 'flex';
            } else {
                loanWarning.style.display = 'none';
            }
            
            document.getElementById('clientPreview').classList.add('active');
        }
        
        function clearClient() {
            selectedClient = null;
            document.getElementById('selectedClientId').value = '';
            clearClientSearch();
            document.getElementById('clientPreview').classList.remove('active');
            checkFormValidity();
        }
        
        // --- 4. HIERARCHY FILTERING ---
        function filterAreas() {
            const zoneId = document.getElementById('zoneSelect').value;
            const areaSelect = document.getElementById('areaSelect');
            const branchSelect = document.getElementById('branchSelect');
            const officerSelect = document.getElementById('officerSelect');
            const unionGroup = document.getElementById('unionGroup');
            const unionSelect = document.getElementById('unionSelect');
            
            areaSelect.innerHTML = '<option value="">Select Area</option>';
            branchSelect.innerHTML = '<option value="">Select Branch first</option>';
            officerSelect.innerHTML = '<option value="">Select Officer first</option>';
            unionSelect.innerHTML = '<option value="">Select Union</option>';
            
            areaSelect.disabled = !zoneId;
            branchSelect.disabled = true;
            officerSelect.disabled = true;
            unionGroup.style.display = 'none';
            unionSelect.disabled = true;
            
            if (zoneId) {
                const filteredAreas = areas.filter(a => a.zone_id === zoneId);
                if(filteredAreas.length > 0) {
                    filteredAreas.forEach(a => {
                        areaSelect.innerHTML += `<option value="${a.id}">${escapeHtml(a.name)}</option>`;
                    });
                } else {
                    areaSelect.innerHTML = '<option value="">No areas in this zone</option>';
                }
            } else {
                 areaSelect.innerHTML = '<option value="">Select Area first</option>';
            }
            
            checkFormValidity();
        }
        
        function filterBranches() {
            const zoneId = document.getElementById('zoneSelect').value;
            const areaId = document.getElementById('areaSelect').value;
            const branchSelect = document.getElementById('branchSelect');
            const officerSelect = document.getElementById('officerSelect');
            const unionGroup = document.getElementById('unionGroup');
            const unionSelect = document.getElementById('unionSelect');
            
            branchSelect.innerHTML = '<option value="">Select Branch</option>';
            officerSelect.innerHTML = '<option value="">Select Officer first</option>';
            officerSelect.disabled = true;
            unionGroup.style.display = 'none';
            unionSelect.disabled = true;
            
            if(!areaId) {
                branchSelect.disabled = true;
                branchSelect.innerHTML = '<option value="">Select Branch first</option>';
                checkFormValidity();
                return;
            }
            
            let filtered = branches;
            if (zoneId) filtered = filtered.filter(b => b.zone_id === zoneId);
            if (areaId) filtered = filtered.filter(b => b.area_id === areaId);
            
            if(filtered.length > 0) {
                filtered.forEach(b => {
                    branchSelect.innerHTML += `<option value="${b.id}">${escapeHtml(b.name)}</option>`;
                });
                branchSelect.disabled = false;
            } else {
                branchSelect.innerHTML = '<option value="">No branches in this area</option>';
                branchSelect.disabled = true;
            }
            
            checkFormValidity();
        }
        
        async function loadOfficers() {
            const branchId = document.getElementById('branchSelect').value;
            const officerSelect = document.getElementById('officerSelect');
            const unionGroup = document.getElementById('unionGroup');
            const unionSelect = document.getElementById('unionSelect');
            
            officerSelect.innerHTML = '<option value="">Loading officers...</option>';
            officerSelect.disabled = true;
            unionGroup.style.display = 'none';
            unionSelect.disabled = true;
            
            if (!branchId) {
                officerSelect.innerHTML = '<option value="">Select Officer first</option>';
                checkFormValidity();
                return;
            }
            
            try {
                const res = await fetch(`?action=get_officers&branch_id=${encodeURIComponent(branchId)}`);
                const data = await res.json();
                
                officerSelect.innerHTML = '<option value="">Select Officer</option>';
                
                if (data.success && data.officers.length > 0) {
                    data.officers.forEach(o => {
                        const roleLabel = o.role ? ` (${o.role.toUpperCase()})` : '';
                        officerSelect.innerHTML += `<option value="${o.username}">${escapeHtml(o.full_name || o.username)}${roleLabel}</option>`;
                    });
                    officerSelect.disabled = false;
                } else {
                    officerSelect.innerHTML = '<option value="" disabled>No officers available in branch</option>';
                }
            } catch (e) {
                console.error('Load officers error:', e);
                officerSelect.innerHTML = '<option value="" disabled>Error loading officers</option>';
            }
            
            checkFormValidity();
        }

        async function loadUnions() {
            const officer = document.getElementById('officerSelect').value;
            const unionGroup = document.getElementById('unionGroup');
            const unionSelect = document.getElementById('unionSelect');
            
            unionSelect.innerHTML = '<option value="">Loading unions...</option>';
            
            if (!officer) {
                unionGroup.style.display = 'none';
                unionSelect.disabled = true;
                return;
            }
            
            try {
                const res = await fetch(`?action=get_co_unions&co_username=${encodeURIComponent(officer)}`);
                const data = await res.json();
                
                unionSelect.innerHTML = '<option value="">Select Union</option>';
                
                if (data.success && data.unions && data.unions.length > 0) {
                    data.unions.forEach(u => {
                        unionSelect.innerHTML += `<option value="${escapeHtml(u)}">${escapeHtml(u)}</option>`;
                    });
                    unionGroup.style.display = 'block';
                    unionSelect.disabled = false;
                } else {
                    unionGroup.style.display = 'none';
                    unionSelect.disabled = true;
                }
            } catch (e) {
                console.error('Load unions error:', e);
                unionSelect.innerHTML = '<option value="">Error loading unions</option>';
                unionGroup.style.display = 'none';
            }
        }
        
        // --- 5. TRANSFER TYPE ---
        function setTransferType(type, element) {
            document.querySelectorAll('.type-option').forEach(el => el.classList.remove('active'));
            element.classList.add('active');
            document.getElementById('transferType').value = type;
        }
        
        // --- 6. FORM VALIDATION & SUBMISSION ---
        function checkFormValidity() {
            const clientId = document.getElementById('selectedClientId').value;
            const branchId = document.getElementById('branchSelect').value;
            const officer = document.getElementById('officerSelect').value;
            const targetUnion = document.getElementById('unionSelect').value;
            
            // Check if branch, officer, AND union are exactly the same
            let isSameAssignment = false;
            if (selectedClient && selectedClient.branch_id === branchId && selectedClient.officer_username === officer) {
                const currentUnion = selectedClient.union || '';
                const newUnion = targetUnion || currentUnion; 
                
                // It's the same assignment ONLY if the union also hasn't changed
                if (currentUnion === newUnion) {
                    isSameAssignment = true;
                }
            }
            
            const isValid = clientId && branchId && officer && !isSameAssignment;
            const submitBtn = document.getElementById('submitBtn');
            submitBtn.disabled = !isValid;
            
            if (isSameAssignment && clientId && branchId && officer) {
                submitBtn.title = "Client is already assigned to this branch, officer, and union";
            } else {
                submitBtn.title = "";
            }
        }
        
        document.getElementById('officerSelect').addEventListener('change', function() {
            checkFormValidity();
            loadUnions();
        });
        
        document.getElementById('unionSelect').addEventListener('change', checkFormValidity);
        
        document.getElementById('transferForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const btn = document.getElementById('submitBtn');
            btn.classList.add('loading');
            btn.disabled = true;
            
            const formData = new FormData(this);
            
            try {
                const res = await fetch('', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await res.json();
                
                if (data.success) {
                    showToast(`Success! ${data.client_name} transferred.`, 'success');
                    
                    // Clear ONLY the client search and reason textarea (leaves destination intact)
                    clearClient();
                    document.getElementById('transferReason').value = '';
                    
                    // Dynamically refresh the recent transfers table
                    loadRecentTransfers();
                    
                    btn.classList.remove('loading');
                    btn.disabled = true; // Disabled until a new client is selected
                } else {
                    showToast(data.error || 'Transfer failed', 'error');
                    btn.classList.remove('loading');
                    btn.disabled = false;
                }
            } catch (e) {
                showToast('Connection error. Please try again.', 'error');
                btn.classList.remove('loading');
                btn.disabled = false;
            }
        });
        
        // --- 7. LOAD RECENT TRANSFERS (AJAX) ---
        async function loadRecentTransfers() {
            try {
                const res = await fetch('?action=get_recent_transfers');
                const data = await res.json();
                
                if (data.success) {
                    const tbody = document.getElementById('recentTransfersBody');
                    const emptyState = document.getElementById('emptyTransfersState');
                    const tableContainer = document.getElementById('transfersTableContainer');
                    
                    if (data.transfers.length === 0) {
                        emptyState.style.display = 'block';
                        tableContainer.style.display = 'none';
                        return;
                    }
                    
                    emptyState.style.display = 'none';
                    tableContainer.style.display = 'block';
                    
                    tbody.innerHTML = data.transfers.map(t => `
                        <tr>
                            <td>
                                <strong>${escapeHtml(t.client_name)}</strong>
                                <div style="font-size:0.75rem; color:var(--text-muted); margin-top:2px;">
                                    ID: ${t.client_id.substring(0, 8)}...
                                </div>
                            </td>
                            <td>
                                <div class="branch-flow">
                                    <div>
                                        <strong>${escapeHtml(t.from_branch_name || 'Unknown')}</strong>
                                        <span class="officer-name">CO: ${escapeHtml(t.from_officer)}</span>
                                    </div>
                                    <i class="fas fa-arrow-right"></i>
                                    <div>
                                        <strong>${escapeHtml(t.to_branch_name || 'Unknown')}</strong>
                                        <span class="officer-name">CO: ${escapeHtml(t.to_officer)}</span>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span class="type-badge">
                                    ${t.transfer_type.charAt(0).toUpperCase() + t.transfer_type.slice(1)}
                                </span>
                            </td>
                            <td>
                                ${escapeHtml(t.transferred_by_name || t.transferred_by)}<br>
                                <span style="font-size:0.7rem; color:var(--text-muted);">${escapeHtml(t.formatted_date)}</span>
                            </td>
                            <td>
                                <span class="status-badge status-${t.status}">
                                    <i class="fas fa-check-circle"></i>
                                    ${t.status.charAt(0).toUpperCase() + t.status.slice(1)}
                                </span>
                            </td>
                        </tr>
                    `).join('');
                }
            } catch (e) {
                console.error('Error loading history:', e);
            }
        }
        
        function resetForm() {
            document.getElementById('transferForm').reset();
            clearClient();
            document.getElementById('zoneSelect').value = '';
            filterAreas(); // Resets downstream dropdowns
            
            const unionGroup = document.getElementById('unionGroup');
            if (unionGroup) unionGroup.style.display = 'none';
            
            document.querySelectorAll('.type-option').forEach((el, i) => {
                el.classList.toggle('active', i === 0);
            });
            document.getElementById('transferType').value = 'permanent';
        }

        // --- 8. UTILITIES ---
        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        function showToast(message, type = 'info') {
            const container = document.getElementById('toastContainer');
            const toast = document.createElement('div');
            toast.className = `toast ${type}`;
            
            const icon = type === 'success' ? 'check-circle' : 
                        type === 'error' ? 'exclamation-circle' : 'info-circle';
            
            toast.innerHTML = `<i class="fas fa-${icon}"></i> ${message}`;
            container.appendChild(toast);
            
            setTimeout(() => toast.remove(), 4000);
        }
        
        // Close search results on click outside
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.search-box') && !e.target.closest('.search-results')) {
                searchResults.style.display = 'none';
            }
        });
    </script>
</body>
</html>