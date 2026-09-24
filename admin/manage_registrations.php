<?php
session_start();

require_once '../includes/config.php';
$pdo = getDbConnection();

// Generate and validate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ============================================================
// 1. AJAX HANDLER FOR FETCHING DATA & REAL-TIME STATS
// ============================================================
if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'fetch_registrations') {
    header('Content-Type: application/json; charset=utf-8');
    
    if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Unauthorized']);
        exit();
    }

    try {
        $search = isset($_GET['search']) ? strtolower(trim($_GET['search'])) : '';
        $start_date = isset($_GET['start_date']) && !empty($_GET['start_date']) ? $_GET['start_date'] : null;
        $end_date = isset($_GET['end_date']) && !empty($_GET['end_date']) ? $_GET['end_date'] : null;
        $filter_officer = isset($_GET['officer']) && !empty($_GET['officer']) ? $_GET['officer'] : null;

        $where_clauses = [];
        $params = [];

        // Search Condition
        if (!empty($search)) {
            $where_clauses[] = "(LOWER(c.name) LIKE ? OR LOWER(r.union) LIKE ? OR LOWER(r.officer) LIKE ? OR LOWER(r.client_id) LIKE ?)";
            $search_param = "%$search%";
            $params = array_fill(0, 4, $search_param);
        }

        // Officer Filter
        if ($filter_officer) {
            $where_clauses[] = "r.officer = ?";
            $params[] = $filter_officer;
        }

        // Date Filter
        if ($start_date) {
            $where_clauses[] = "DATE(r.date) >= ?";
            $params[] = $start_date;
        }
        if ($end_date) {
            $where_clauses[] = "DATE(r.date) <= ?";
            $params[] = $end_date;
        }

        $where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

        // Total Filtered Count
        $count_sql = "SELECT COUNT(*) as total FROM registrations r LEFT JOIN clients c ON r.client_id = c.id $where_sql";
        $count_stmt = $pdo->prepare($count_sql);
        $count_stmt->execute($params);
        $total_records = (int)$count_stmt->fetch(PDO::FETCH_ASSOC)['total'];

        // Pagination
        $limit = isset($_GET['limit']) ? max(5, min(100, (int)$_GET['limit'])) : 10;
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $total_pages = $total_records > 0 ? (int)ceil($total_records / $limit) : 1;
        $page = max(1, min($page, $total_pages)); 
        $offset = ($page - 1) * $limit;

        // Paginated Query
        $sql = "
            SELECT 
                r.id,
                r.client_id,
                c.name as client_name,
                r.union,
                r.amount,
                r.date,
                r.officer,
                r.created_at
            FROM registrations r
            LEFT JOIN clients c ON r.client_id = c.id
            $where_sql
            ORDER BY r.date DESC, r.id DESC
            LIMIT $limit OFFSET $offset
        ";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $registrations = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $data = [];
        foreach ($registrations as $reg) {
            $data[] = [
                'id' => (int)$reg['id'],
                'client_id' => $reg['client_id'] ?? 'N/A',
                'client_name' => $reg['client_name'] ?? 'Unknown Client',
                'union' => $reg['union'] ?? '-',
                'amount' => (float)$reg['amount'],
                'date' => $reg['date'],
                'officer' => $reg['officer'] ?? 'Unassigned',
                'created_at' => $reg['created_at']
            ];
        }

        // Real-time Global Statistics
        $statsStmt = $pdo->query("SELECT COUNT(*) AS total_count, COALESCE(SUM(amount), 0) AS total_amount, COALESCE(AVG(amount), 0) AS avg_amount FROM registrations");
        $globalStats = $statsStmt->fetch(PDO::FETCH_ASSOC);

        $officerStmt = $pdo->query("SELECT DISTINCT officer FROM registrations WHERE officer IS NOT NULL AND officer != '' ORDER BY officer ASC");
        $liveOfficers = $officerStmt->fetchAll(PDO::FETCH_COLUMN);

        echo json_encode([
            'data' => $data,
            'total' => $total_records,
            'page' => $page,
            'limit' => $limit,
            'total_pages' => $total_pages,
            'global_total' => (int)$globalStats['total_count'],
            'total_amount' => (float)$globalStats['total_amount'],
            'avg_amount' => (float)$globalStats['avg_amount'],
            'unique_officers' => count($liveOfficers),
            'officers' => $liveOfficers
        ]);
        
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
    exit();
}

// ============================================================
// 2. AJAX CRUD HANDLER (NO PAGE RELOAD)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');

    if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized action.']);
        exit();
    }

    if (
        !isset($_POST['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], (string)$_POST['csrf_token'])
    ) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid security token. Please reload the page.'
        ]);
        exit();
    }

    try {
        $action = $_POST['action'];

        switch ($action) {
            case 'delete':
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) throw new Exception('Invalid registration ID.');

                $stmt = $pdo->prepare("DELETE FROM registrations WHERE id = ?");
                $stmt->execute([$id]);

                if ($stmt->rowCount() === 0) {
                    throw new Exception('Registration not found or already deleted.');
                }

                echo json_encode(['success' => true, 'message' => 'Registration deleted successfully.']);
                break;

            case 'edit':
                $id = (int)($_POST['id'] ?? 0);
                $union = trim((string)($_POST['union'] ?? ''));
                $amount = (float)($_POST['amount'] ?? 0);
                $date = trim((string)($_POST['date'] ?? ''));

                if ($id <= 0) throw new Exception('Invalid registration ID.');
                if ($union === '') throw new Exception('Union name is required.');
                if ($amount < 0) throw new Exception('Amount cannot be negative.');
                if ($date === '') throw new Exception('Date and time are required.');

                $mysqlDate = str_replace('T', ' ', $date);
                if (strlen($mysqlDate) === 16) {
                    $mysqlDate .= ':00';
                }

                $stmt = $pdo->prepare("
                    UPDATE registrations 
                    SET `union` = ?, 
                        amount = ?, 
                        date = ?, 
                        updated_at = NOW() 
                    WHERE id = ?
                ");
                $stmt->execute([$union, $amount, $mysqlDate, $id]);

                echo json_encode(['success' => true, 'message' => 'Registration updated successfully.']);
                break;

            case 'delete_all':
                if (($_POST['confirm_delete_all'] ?? '') !== 'yes') {
                    throw new Exception('Confirmation required to delete all records.');
                }

                $pdo->exec("DELETE FROM registrations");

                echo json_encode(['success' => true, 'message' => 'All registrations have been permanently cleared.']);
                break;

            default:
                throw new Exception('Invalid action requested.');
        }
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit();
}

// Authentication Check for View
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: ../index.php');
    exit();
}

$base_path = '../'; 
$page_title = "Manage Registrations - CUPAD Admin";
$full_name = $_SESSION['full_name'] ?? 'Admin';
$username = $_SESSION['username'] ?? '';
$role = $_SESSION['user_role'] ?? '';

// User Profile Picture
$profile_pic_path = $base_path . 'uploads/default_avatar.png';
try {
    $stmt = $pdo->prepare("SELECT profile_pic FROM users WHERE username = ? LIMIT 1");
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($user && !empty($user['profile_pic']) && file_exists($base_path . $user['profile_pic'])) {
        $profile_pic_path = $base_path . $user['profile_pic'];
    }
} catch (PDOException $e) {}

// Initial Server Stats
try {
    $stmt = $pdo->query("SELECT COUNT(*) as total, COALESCE(SUM(amount), 0) as total_amount, COALESCE(AVG(amount), 0) as avg_amount FROM registrations");
    $initStats = $stmt->fetch(PDO::FETCH_ASSOC);
    $total_registrations = (int)$initStats['total'];
    $total_amount = (float)$initStats['total_amount'];
    $avg_amount = (float)$initStats['avg_amount'];
    
    $stmt = $pdo->query("SELECT DISTINCT officer FROM registrations WHERE officer IS NOT NULL AND officer != '' ORDER BY officer");
    $unique_officers = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $total_registrations = 0;
    $total_amount = 0;
    $avg_amount = 0;
    $unique_officers = [];
}
?>
<!DOCTYPE html>
<html lang="en" class="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    <link rel="icon" type="image/png" href="<?php echo htmlspecialchars($base_path . 'uploads/CUPAD LOGO.png'); ?>">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />

    <style>
        :root {
            --primary: #2563eb; --primary-dark: #1d4ed8; --primary-light: rgba(37, 99, 235, 0.1);
            --success: #059669; --warning: #d97706; --danger: #dc2626; --info: #0284c7;
            --bg-body: #f8fafc; --bg-surface: #ffffff;
            --text-main: #0f172a; --text-muted: #64748b;
            --border: #e2e8f0; --radius-xl: 20px; --radius-lg: 16px; --radius-md: 10px;
            --shadow-xs: 0 1px 2px 0 rgb(0 0 0 / 0.05);
            --shadow-sm: 0 4px 6px -1px rgb(0 0 0 / 0.05), 0 2px 4px -2px rgb(0 0 0 / 0.05);
            --shadow-md: 0 10px 15px -3px rgb(0 0 0 / 0.08), 0 4px 6px -4px rgb(0 0 0 / 0.04);
            --shadow-lg: 0 20px 25px -5px rgba(0,0,0,0.1), 0 8px 10px -6px rgba(0,0,0,0.05);
            --nav-height: 70px;
        }

        html.dark {
            --bg-body: #090d16; --bg-surface: #131b2e;
            --text-main: #f8fafc; --text-muted: #94a3b8;
            --border: #202d45; --primary: #3b82f6; --primary-light: rgba(59, 130, 246, 0.15);
            --success: #34d399; --warning: #fbbf24; --danger: #f87171; --info: #38bdf8;
        }

        * { box-sizing: border-box; outline: none; margin: 0; padding: 0; }
        body { font-family: 'Plus Jakarta Sans', sans-serif; background: var(--bg-body); color: var(--text-main); padding-top: var(--nav-height); transition: background-color 0.3s, color 0.3s; min-height: 100vh; }
        a { text-decoration: none; color: inherit; }

        /* Header Navigation */
        .main-header { position: fixed; top: 0; left: 0; right: 0; height: var(--nav-height); background: rgba(255,255,255,0.85); backdrop-filter: blur(12px); border-bottom: 1px solid var(--border); z-index: 60; transition: background 0.3s; }
        html.dark .main-header { background: rgba(19, 27, 46, 0.85); }
        .navbar { max-width: 1440px; margin: 0 auto; padding: 0 1.5rem; display: flex; align-items: center; justify-content: space-between; height: 100%; }
        .logo { display: flex; align-items: center; gap: 0.75rem; font-weight: 800; font-size: 1.35rem; color: var(--primary); }
        .logo img { height: 38px; }
        .nav-right { display: flex; align-items: center; gap: 0.75rem; }
        
        .icon-btn { width: 40px; height: 40px; border-radius: 12px; border: 1px solid var(--border); background: var(--bg-surface); color: var(--text-muted); cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 1rem; transition: all 0.2s; }
        .icon-btn:hover { background: var(--bg-body); color: var(--primary); border-color: var(--primary); }
        
        .user-dropdown-wrap { position: relative; }
        .user-pill { display: flex; align-items: center; gap: 0.65rem; padding: 4px 10px 4px 4px; border: 1px solid var(--border); border-radius: 99px; background: var(--bg-surface); cursor: pointer; transition: all 0.2s; }
        .user-pill:hover { border-color: var(--primary); box-shadow: var(--shadow-xs); }
        .user-avatar { width: 34px; height: 34px; border-radius: 50%; object-fit: cover; }
        .user-avatar-fallback { width: 34px; height: 34px; border-radius: 50%; background: var(--primary-light); color: var(--primary); display: flex; align-items: center; justify-content: center; font-size: 0.9rem; font-weight: 700; }
        .user-info { display: flex; flex-direction: column; line-height: 1.1; padding-right: 0.25rem; }
        .user-name { font-weight: 700; font-size: 0.85rem; }
        .user-role { font-size: 0.68rem; color: var(--text-muted); text-transform: uppercase; font-weight: 600; }

        .dropdown-menu { position: absolute; top: calc(100% + 8px); right: 0; background: var(--bg-surface); border: 1px solid var(--border); border-radius: var(--radius-md); box-shadow: var(--shadow-lg); min-width: 210px; display: none; z-index: 1000; flex-direction: column; overflow: hidden; animation: scaleIn 0.2s cubic-bezier(0.16, 1, 0.3, 1); transform-origin: top right; }
        @keyframes scaleIn { from { opacity: 0; transform: scale(0.95); } to { opacity: 1; transform: scale(1); } }
        .dropdown-menu.show { display: flex; }
        .dropdown-item { padding: 0.75rem 1rem; display: flex; align-items: center; gap: 0.75rem; font-size: 0.875rem; font-weight: 500; transition: 0.2s; }
        .dropdown-item:hover { background: var(--bg-body); color: var(--primary); }
        .text-danger { color: var(--danger) !important; }

        /* Container */
        .container { max-width: 1440px; margin: 0 auto; padding: 2rem 1.5rem 5rem; }

        /* Header Title Banner */
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.75rem; flex-wrap: wrap; gap: 1rem; }
        .page-header h1 { font-size: 1.85rem; font-weight: 800; letter-spacing: -0.5px; }
        .page-header p { margin-top: 0.25rem; color: var(--text-muted); font-size: 0.95rem; }

        /* ============================================================ */
        /* FULL-WIDTH SINGLE CARD CAROUSEL                              */
        /* ============================================================ */
        .stats-carousel-section { 
            position: relative; 
            margin-bottom: 2.25rem; 
            width: 100%;
        }
        
        .carousel-header-controls { 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            margin-bottom: 0.85rem; 
        }
        .carousel-title { 
            font-size: 0.85rem; 
            text-transform: uppercase; 
            font-weight: 800; 
            letter-spacing: 0.6px; 
            color: var(--text-muted); 
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .carousel-nav-buttons { display: flex; gap: 0.5rem; }
        
        .carousel-btn { 
            width: 36px; 
            height: 36px; 
            border-radius: 50%; 
            border: 1px solid var(--border); 
            background: var(--bg-surface); 
            color: var(--text-main); 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            cursor: pointer; 
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1); 
            box-shadow: var(--shadow-xs); 
        }
        .carousel-btn:hover { 
            background: var(--primary); 
            color: white; 
            border-color: var(--primary); 
            transform: scale(1.06); 
        }

        /* The Carousel Container holding full-width slides */
        .stats-carousel-container { 
            display: flex; 
            width: 100%;
            overflow-x: auto; 
            scroll-snap-type: x mandatory; 
            scrollbar-width: none; 
            -ms-overflow-style: none; 
            scroll-behavior: smooth;
            border-radius: var(--radius-xl);
            box-shadow: var(--shadow-sm);
        }
        .stats-carousel-container::-webkit-scrollbar { display: none; }

        /* Each Card occupies 100% of the entire carousel width */
        .stat-card { 
            position: relative; 
            flex: 0 0 100%; 
            width: 100%; 
            min-width: 100%;
            max-width: 100%;
            padding: 2rem 2.5rem; 
            border-radius: var(--radius-xl); 
            overflow: hidden; 
            display: flex; 
            flex-direction: column; 
            justify-content: space-between; 
            min-height: 180px; 
            scroll-snap-align: start; 
            scroll-snap-stop: always;
            box-sizing: border-box;
            user-select: none;
            transition: transform 0.3s ease;
        }

        .stat-content { position: relative; z-index: 2; width: 100%; }
        .stat-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 1rem; }
        .stat-icon { 
            width: 48px; 
            height: 48px; 
            border-radius: 14px; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            font-size: 1.4rem; 
            background: rgba(255,255,255,0.22); 
            backdrop-filter: blur(10px); 
            box-shadow: inset 0 0 0 1px rgba(255,255,255,0.2);
        }
        
        .stat-value { 
            font-size: 2.5rem; 
            font-weight: 800; 
            line-height: 1.1; 
            letter-spacing: -1px; 
            margin-bottom: 0.35rem; 
        }
        .stat-label { 
            font-size: 0.85rem; 
            font-weight: 700; 
            text-transform: uppercase; 
            letter-spacing: 0.8px; 
            opacity: 0.92; 
        }

        /* Large decorative watermark */
        .watermark-icon { 
            position: absolute; 
            right: 25px; 
            bottom: -15px; 
            font-size: 9rem; 
            opacity: 0.12; 
            transform: rotate(-10deg); 
            z-index: 1; 
            pointer-events: none; 
            transition: transform 0.4s ease, opacity 0.4s ease; 
        }
        .stat-card:hover .watermark-icon { 
            transform: rotate(0deg) scale(1.08); 
            opacity: 0.18;
        }

        /* Gradients */
        .stat-card.fill-blue { background: linear-gradient(135deg, #1d4ed8, #3b82f6); color: white; }
        .stat-card.fill-green { background: linear-gradient(135deg, #047857, #10b981); color: white; }
        .stat-card.fill-teal { background: linear-gradient(135deg, #0f766e, #14b8a6); color: white; }
        .stat-card.fill-purple { background: linear-gradient(135deg, #6d28d9, #8b5cf6); color: white; }
        .stat-card.fill-orange { background: linear-gradient(135deg, #c2410c, #f97316); color: white; }

        /* Carousel Indicator Dots */
        .carousel-dots { 
            display: flex; 
            justify-content: center; 
            align-items: center;
            gap: 0.5rem; 
            margin-top: 0.85rem; 
        }
        .dot { 
            width: 8px; 
            height: 8px; 
            border-radius: 50%; 
            background: var(--border); 
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); 
            cursor: pointer; 
        }
        .dot.active { 
            width: 28px; 
            border-radius: 99px; 
            background: var(--primary); 
            box-shadow: 0 0 10px var(--primary-light);
        }

        /* Filter Panel */
        .filter-panel { background: var(--bg-surface); border: 1px solid var(--border); border-radius: var(--radius-lg); padding: 0; margin-bottom: 1.5rem; overflow: hidden; max-height: 0; opacity: 0; transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1); box-shadow: var(--shadow-sm); }
        .filter-panel.open { padding: 1.5rem; max-height: 400px; opacity: 1; }
        .filter-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem; align-items: flex-end; }
        .filter-label { font-size: 0.75rem; font-weight: 700; color: var(--text-muted); margin-bottom: 0.35rem; display: block; text-transform: uppercase; letter-spacing: 0.4px; }
        .form-select, .form-date, .form-input { width: 100%; padding: 0.7rem 1rem; border: 1px solid var(--border); border-radius: var(--radius-md); background: var(--bg-body); color: var(--text-main); font-size: 0.875rem; font-family: inherit; transition: all 0.2s; }
        .form-select:focus, .form-date:focus, .form-input:focus { border-color: var(--primary); box-shadow: 0 0 0 3px var(--primary-light); }

        .quick-dates { display: flex; gap: 0.4rem; flex-wrap: wrap; margin-top: 0.5rem; }
        .date-chip { font-size: 0.75rem; padding: 0.35rem 0.8rem; border-radius: 99px; background: var(--bg-body); border: 1px solid var(--border); color: var(--text-muted); cursor: pointer; transition: all 0.2s; font-weight: 600; }
        .date-chip:hover, .date-chip.active { background: var(--primary-light); color: var(--primary); border-color: var(--primary); }

        /* Buttons */
        .btn-primary, .btn-secondary, .btn-danger, .btn-filter { 
            padding: 0.65rem 1.25rem; border-radius: var(--radius-md); font-size: 0.875rem; font-weight: 600; 
            cursor: pointer; transition: all 0.2s; display: inline-flex; align-items: center; gap: 0.5rem; border: none; 
        }
        .btn-primary { background: var(--primary); color: white; box-shadow: 0 2px 8px rgba(37,99,235,0.25); }
        .btn-primary:hover { background: var(--primary-dark); transform: translateY(-1px); }
        .btn-secondary { background: var(--bg-surface); color: var(--text-main); border: 1px solid var(--border); }
        .btn-secondary:hover { background: var(--bg-body); border-color: var(--primary); color: var(--primary); }
        .btn-danger { background: var(--danger); color: white; }
        .btn-danger:hover { background: #b91c1c; transform: translateY(-1px); }
        .btn-filter { background: var(--bg-surface); border: 1px solid var(--border); color: var(--text-muted); }
        .btn-filter.active { background: var(--primary-light); border-color: var(--primary); color: var(--primary); }
        .filter-badge { background: var(--primary); color: white; font-size: 0.7rem; padding: 0.1rem 0.45rem; border-radius: 99px; }

        /* Data Table Container */
        .table-container { background: var(--bg-surface); border: 1px solid var(--border); border-radius: var(--radius-lg); box-shadow: var(--shadow-sm); overflow: hidden; }
        .table-toolbar { padding: 1rem 1.5rem; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap; background: rgba(248, 250, 252, 0.4); }
        html.dark .table-toolbar { background: rgba(19, 27, 46, 0.4); }

        .search-wrapper { position: relative; flex: 1; max-width: 380px; min-width: 220px; }
        .search-input { width: 100%; padding: 0.65rem 2.2rem 0.65rem 2.4rem; border: 1px solid var(--border); border-radius: 99px; background: var(--bg-surface); color: var(--text-main); font-size: 0.875rem; transition: all 0.2s; }
        .search-input:focus { border-color: var(--primary); box-shadow: 0 0 0 3px var(--primary-light); }
        .search-wrapper .search-icon { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: var(--text-muted); font-size: 0.85rem; }
        .search-wrapper .clear-btn { position: absolute; right: 12px; top: 50%; transform: translateY(-50%); background: none; border: none; color: var(--text-muted); cursor: pointer; display: none; font-size: 0.85rem; }

        table { width: 100%; border-collapse: collapse; text-align: left; }
        th, td { padding: 0.95rem 1.25rem; border-bottom: 1px solid var(--border); }
        th { background: rgba(248, 250, 252, 0.7); font-weight: 700; font-size: 0.72rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.4px; }
        html.dark th { background: rgba(19, 27, 46, 0.6); }
        tbody tr { transition: background 0.15s ease; }
        tbody tr:hover { background: rgba(37, 99, 235, 0.03); }

        /* Badges & Pills */
        .id-badge { display: inline-flex; align-items: center; gap: 0.35rem; padding: 0.3rem 0.65rem; border-radius: 8px; font-size: 0.75rem; font-weight: 700; font-family: monospace; background: var(--primary-light); color: var(--primary); cursor: pointer; transition: all 0.2s; }
        .id-badge:hover { filter: brightness(0.95); }
        .currency-text { font-weight: 800; color: var(--success); }

        /* Action Buttons */
        .action-group { display: flex; align-items: center; justify-content: flex-end; gap: 0.35rem; }
        .action-btn { width: 34px; height: 34px; border-radius: 8px; border: none; background: transparent; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; transition: all 0.2s; color: var(--text-muted); font-size: 0.9rem; }
        .action-btn:hover { background: var(--bg-body); }
        .action-btn.edit:hover { color: var(--primary); background: var(--primary-light); }
        .action-btn.delete:hover { color: var(--danger); background: rgba(220, 38, 38, 0.12); }

        /* Pagination Toolbar */
        .pagination { display: flex; align-items: center; justify-content: space-between; padding: 1.1rem 1.5rem; border-top: 1px solid var(--border); }
        .pagination-btn { padding: 0.45rem 0.9rem; border-radius: 8px; font-size: 0.8rem; font-weight: 600; border: 1px solid var(--border); background: var(--bg-surface); color: var(--text-main); cursor: pointer; transition: all 0.2s; }
        .pagination-btn:hover:not(:disabled) { background: var(--primary); color: white; border-color: var(--primary); }
        .pagination-btn:disabled { opacity: 0.4; cursor: not-allowed; }

        /* Skeletons */
        .skeleton { background: linear-gradient(90deg, var(--bg-body) 25%, var(--border) 50%, var(--bg-body) 75%); background-size: 200% 100%; animation: shimmer 1.5s infinite; border-radius: 6px; }
        @keyframes shimmer { 0% { background-position: 200% 0; } 100% { background-position: -200% 0; } }

        /* Modals */
        .modal-backdrop { position: fixed; inset: 0; background: rgba(9, 13, 22, 0.6); backdrop-filter: blur(6px); z-index: 1000; display: none; align-items: center; justify-content: center; opacity: 0; transition: opacity 0.25s ease; padding: 1rem; }
        .modal-backdrop.show { display: flex; opacity: 1; }
        .modal-content { background: var(--bg-surface); padding: 1.75rem; border-radius: var(--radius-xl); width: 100%; max-width: 500px; box-shadow: var(--shadow-lg); border: 1px solid var(--border); transform: scale(0.95); transition: transform 0.25s ease; }
        .modal-backdrop.show .modal-content { transform: scale(1); }

        /* Toast Notifications */
        .toast-container { position: fixed; top: 85px; right: 20px; z-index: 2000; display: flex; flex-direction: column; gap: 10px; pointer-events: none; }
        .toast { pointer-events: auto; background: var(--bg-surface); color: var(--text-main); padding: 0.95rem 1.25rem; border-radius: var(--radius-md); box-shadow: var(--shadow-lg); border-left: 4px solid var(--primary); min-width: 320px; display: flex; align-items: center; gap: 0.75rem; font-size: 0.875rem; font-weight: 600; animation: toastSlideIn 0.3s forwards; }
        .toast.success { border-left-color: var(--success); }
        .toast.error { border-left-color: var(--danger); }
        @keyframes toastSlideIn { from { opacity: 0; transform: translateX(50px); } to { opacity: 1; transform: translateX(0); } }

        /* Empty State */
        .empty-state { text-align: center; padding: 3.5rem 1rem; color: var(--text-muted); }
        .empty-state i { font-size: 3.2rem; margin-bottom: 0.85rem; opacity: 0.3; }

        @media (max-width: 600px) {
            .stat-card { padding: 1.5rem; min-height: 160px; }
            .stat-value { font-size: 2rem; }
            .watermark-icon { font-size: 6.5rem; right: 10px; }
            .table-toolbar { flex-direction: column; align-items: stretch; }
            .search-wrapper { max-width: 100%; }
        }
        .hidden { display: none !important; }
    </style>
</head>
<body>

    <!-- Header Navbar -->
    <header class="main-header">
        <div class="navbar">
            <a href="dashboard.php" class="logo">
                <img src="<?php echo htmlspecialchars($base_path . 'uploads/CUPAD LOGO.png'); ?>" alt="Logo">
                <span>CUPAD</span>
            </a>
            <div class="nav-right">
                <button class="icon-btn" id="themeToggle" title="Toggle Theme"><i class="fas fa-moon"></i></button>
                <div class="user-dropdown-wrap">
                    <div class="user-pill" id="userDropdownTrigger">
                        <?php if (strpos($profile_pic_path, 'default_avatar.png') === false): ?>
                            <img src="<?php echo htmlspecialchars($profile_pic_path); ?>" class="user-avatar" alt="User">
                        <?php else: ?>
                            <div class="user-avatar-fallback"><?php echo strtoupper(substr($full_name, 0, 1)); ?></div>
                        <?php endif; ?>
                        <div class="user-info">
                            <span class="user-name"><?php echo htmlspecialchars($full_name); ?></span>
                            <span class="user-role"><?php echo htmlspecialchars($role); ?></span>
                        </div>
                        <i class="fas fa-chevron-down" style="font-size:0.65rem; color:var(--text-muted)"></i>
                    </div>
                    <div class="dropdown-menu" id="userDropdown">
                        <div style="padding: 0.75rem 1rem 0.25rem; font-size: 0.7rem; color:var(--text-muted); font-weight:700">SIGNED IN AS</div>
                        <div style="padding: 0 1rem 0.75rem; font-weight:800;"><?php echo htmlspecialchars($username); ?></div>
                        <div style="height:1px; background:var(--border);"></div>
                        <a href="profile.php" class="dropdown-item"><i class="fas fa-user-circle" style="color:var(--primary)"></i> My Profile</a>
                        <a href="dashboard.php" class="dropdown-item"><i class="fas fa-chart-line" style="color:var(--info)"></i> Dashboard</a>
                        <div style="height:1px; background:var(--border);"></div>
                        <a href="<?php echo htmlspecialchars($base_path . 'logout.php'); ?>" class="dropdown-item text-danger"><i class="fas fa-sign-out-alt"></i> Logout</a>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <!-- Toast Notifications Area -->
    <div id="toastContainer" class="toast-container"></div>

    <main class="container">
        
        <!-- Header Page Title -->
        <div class="page-header">
            <div>
                <h1>Registrations Manager</h1>
                <p>Monitor transactions, filter field records, and edit client union subscriptions seamlessly.</p>
            </div>
            <div style="display: flex; gap: 0.75rem;">
                <button type="button" id="refreshBtn" class="btn-secondary" onclick="fetchRegistrations()">
                    <i class="fas fa-sync-alt"></i> Refresh Data
                </button>
            </div>
        </div>

        <!-- ============================================================ -->
        <!-- SUMMARY CAROUSEL (ONE CARD OCCUPIES 100% FULL WIDTH)         -->
        <!-- ============================================================ -->
        <section class="stats-carousel-section">
            <div class="carousel-header-controls">
                <span class="carousel-title"><i class="fas fa-chart-pie"></i> Real-time Key Metric Highlights</span>
                <div class="carousel-nav-buttons">
                    <button class="carousel-btn" id="carouselPrev" title="Previous Slide"><i class="fas fa-chevron-left"></i></button>
                    <button class="carousel-btn" id="carouselNext" title="Next Slide"><i class="fas fa-chevron-right"></i></button>
                </div>
            </div>

            <!-- Single Slide Carousel Container -->
            <div class="stats-carousel-container" id="statsCarousel">
                
                <!-- Card 1: Total Registrations (Full Width) -->
                <div class="stat-card fill-blue">
                    <i class="fas fa-users watermark-icon"></i>
                    <div class="stat-content">
                        <div class="stat-header">
                            <div class="stat-icon"><i class="fas fa-users"></i></div>
                            <span style="font-size: 0.75rem; font-weight:800; background: rgba(255,255,255,0.2); padding: 0.25rem 0.65rem; border-radius: 99px;">TOTAL OVERVIEW</span>
                        </div>
                        <div class="stat-value" id="statTotal"><?php echo number_format($total_registrations); ?></div>
                        <div class="stat-label">Total Registered Clients & Members</div>
                    </div>
                </div>
                
                <!-- Card 2: Total Amount (Full Width) -->
                <div class="stat-card fill-green">
                    <i class="fas fa-wallet watermark-icon"></i>
                    <div class="stat-content">
                        <div class="stat-header">
                            <div class="stat-icon"><i class="fas fa-wallet"></i></div>
                            <span style="font-size: 0.75rem; font-weight:800; background: rgba(255,255,255,0.2); padding: 0.25rem 0.65rem; border-radius: 99px;">TOTAL COLLECTIONS (NGN)</span>
                        </div>
                        <div class="stat-value" id="statAmount">₦<?php echo number_format($total_amount, 2); ?></div>
                        <div class="stat-label">Cumulative Total Revenue Collected</div>
                    </div>
                </div>

                <!-- Card 3: Average Amount (Full Width) -->
                <div class="stat-card fill-teal">
                    <i class="fas fa-calculator watermark-icon"></i>
                    <div class="stat-content">
                        <div class="stat-header">
                            <div class="stat-icon"><i class="fas fa-calculator"></i></div>
                            <span style="font-size: 0.75rem; font-weight:800; background: rgba(255,255,255,0.2); padding: 0.25rem 0.65rem; border-radius: 99px;">AVERAGE VALUE</span>
                        </div>
                        <div class="stat-value" id="statAvg">₦<?php echo number_format($avg_amount, 2); ?></div>
                        <div class="stat-label">Mean Registration Amount Per Client</div>
                    </div>
                </div>
                
                <!-- Card 4: Active Officers (Full Width) -->
                <div class="stat-card fill-purple">
                    <i class="fas fa-user-tie watermark-icon"></i>
                    <div class="stat-content">
                        <div class="stat-header">
                            <div class="stat-icon"><i class="fas fa-user-tie"></i></div>
                            <span style="font-size: 0.75rem; font-weight:800; background: rgba(255,255,255,0.2); padding: 0.25rem 0.65rem; border-radius: 99px;">FIELD STAFF</span>
                        </div>
                        <div class="stat-value" id="statOfficers"><?php echo count($unique_officers); ?></div>
                        <div class="stat-label">Active Field Registration Officers</div>
                    </div>
                </div>

                <!-- Card 5: Fast Navigation (Full Width) -->
                <a href="dashboard.php" class="stat-card fill-orange" style="cursor: pointer;">
                    <i class="fas fa-th-large watermark-icon"></i>
                    <div class="stat-content">
                        <div class="stat-header">
                            <div class="stat-icon"><i class="fas fa-arrow-left"></i></div>
                            <span style="font-size: 0.75rem; font-weight:800; background: rgba(255,255,255,0.2); padding: 0.25rem 0.65rem; border-radius: 99px;">QUICK ACCESS</span>
                        </div>
                        <div class="stat-value" style="font-size: 2.2rem;">Back to Dashboard</div>
                        <div class="stat-label">Click here to return to primary dashboard modules</div>
                    </div>
                </a>
            </div>

            <!-- Dots Indicator -->
            <div class="carousel-dots" id="carouselDots"></div>
        </section>

        <!-- Advanced Filter Collapse Panel -->
        <div id="filterPanel" class="filter-panel">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem;">
                <h4 style="font-size: 0.95rem; font-weight: 700; display: flex; align-items: center; gap: 0.5rem;">
                    <i class="fas fa-sliders-h" style="color: var(--primary);"></i> Filter Criteria
                </h4>
                <button type="button" id="closeFilters" class="icon-btn" style="width:30px; height:30px;"><i class="fas fa-times"></i></button>
            </div>
            <div class="filter-grid">
                <div>
                    <label class="filter-label">Assigned Officer</label>
                    <select id="officerFilter" class="form-select">
                        <option value="">All Officers</option>
                        <?php foreach ($unique_officers as $off): ?>
                            <?php if(!empty($off)): ?>
                            <option value="<?php echo htmlspecialchars($off); ?>"><?php echo htmlspecialchars($off); ?></option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div style="grid-column: span 2;">
                    <label class="filter-label">Registration Date Range</label>
                    <div style="display: flex; gap: 0.5rem; align-items: center;">
                        <input type="date" id="startDate" class="form-date">
                        <span style="color: var(--text-muted); font-size: 0.85rem; font-weight: 600;">to</span>
                        <input type="date" id="endDate" class="form-date">
                    </div>
                    <div class="quick-dates">
                        <button type="button" class="date-chip" onclick="setDateRange('today')">Today</button>
                        <button type="button" class="date-chip" onclick="setDateRange('7days')">Last 7 Days</button>
                        <button type="button" class="date-chip" onclick="setDateRange('month')">This Month</button>
                    </div>
                </div>
                
                <div>
                    <button type="button" id="resetFilters" class="btn-secondary" style="width: 100%; justify-content: center;">
                        <i class="fas fa-undo"></i> Reset Filters
                    </button>
                </div>
            </div>
        </div>

        <!-- Modern Data Table -->
        <div class="table-container">
            <div class="table-toolbar">
                <div style="display: flex; gap: 0.75rem; flex: 1; flex-wrap: wrap;">
                    <button type="button" id="toggleFiltersBtn" class="btn-filter">
                        <i class="fas fa-filter"></i> Filters <span id="filterCount" class="filter-badge hidden">0</span>
                    </button>
                    <div class="search-wrapper">
                        <i class="fas fa-search search-icon"></i>
                        <input type="text" id="searchInput" placeholder="Search client name, ID, union..." class="search-input">
                        <button type="button" id="clearSearch" class="clear-btn"><i class="fas fa-times"></i></button>
                    </div>
                </div>
                
                <div style="display: flex; align-items: center; gap: 0.75rem;">
                    <button type="button" id="deleteAllBtn" class="btn-danger" style="padding: 0.55rem 1rem; font-size: 0.82rem;">
                        <i class="fas fa-trash-alt"></i> Clear All
                    </button>
                    <div style="display: flex; align-items: center; gap: 0.4rem;">
                        <span style="font-size: 0.75rem; color: var(--text-muted); font-weight: 700;">SHOW</span>
                        <select id="limitSelect" class="form-select" style="width: 75px; padding: 0.45rem 0.5rem; font-size: 0.8rem;">
                            <option value="10" selected>10</option>
                            <option value="25">25</option>
                            <option value="50">50</option>
                        </select>
                    </div>
                </div>
            </div>

            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr>
                            <th>Client ID</th>
                            <th>Client Name</th>
                            <th>Union</th>
                            <th>Amount Paid</th>
                            <th>Registration Date</th>
                            <th>Officer</th>
                            <th style="text-align: right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="tableBody">
                        <!-- Filled dynamically by AJAX -->
                    </tbody>
                </table>
            </div>

            <div class="pagination">
                <div style="font-size: 0.85rem; color: var(--text-muted); font-weight: 500;">
                    Showing <span id="pageStart" style="color: var(--text-main); font-weight: 700;">0</span> to <span id="pageEnd" style="color: var(--text-main); font-weight: 700;">0</span> of <span id="pageTotal" style="color: var(--text-main); font-weight: 700;">0</span> entries
                </div>
                <div style="display: flex; gap: 0.5rem; align-items: center;">
                    <button class="pagination-btn" id="prevPageBtn"><i class="fas fa-chevron-left"></i> Prev</button>
                    <span id="pageIndicator" style="font-size: 0.85rem; font-weight: 700; margin: 0 0.5rem;">Page 1 of 1</span>
                    <button class="pagination-btn" id="nextPageBtn">Next <i class="fas fa-chevron-right"></i></button>
                </div>
            </div>
        </div>
    </main>

    <!-- Edit Modal -->
    <div id="editModal" class="modal-backdrop">
        <div class="modal-content">
            <div style="display:flex; justify-content:space-between; align-items: center; margin-bottom:1.25rem;">
                <h3 style="font-size:1.2rem; font-weight:800; display: flex; align-items: center; gap: 0.5rem;">
                    <i class="fas fa-pen-to-square" style="color: var(--primary);"></i> Edit Record
                </h3>
                <button type="button" onclick="closeModal('editModal')" class="icon-btn" style="width: 32px; height: 32px;"><i class="fas fa-times"></i></button>
            </div>
            <form id="editForm">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" id="editId">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                
                <div style="margin-bottom:1rem;">
                    <label class="filter-label">Client Name</label>
                    <input type="text" id="editClientNameDisplay" disabled class="form-input" style="opacity: 0.7; font-weight: 700;">
                </div>
                
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:1rem; margin-bottom:1rem;">
                    <div>
                        <label class="filter-label">Union</label>
                        <input type="text" name="union" id="editUnion" required class="form-input" placeholder="e.g. Traders Union">
                    </div>
                    <div>
                        <label class="filter-label">Amount (₦)</label>
                        <input type="number" name="amount" id="editAmount" step="0.01" min="0" required class="form-input">
                    </div>
                </div>
                
                <div style="margin-bottom:1.5rem;">
                    <label class="filter-label">Date & Time</label>
                    <input type="datetime-local" name="date" id="editDate" required class="form-input">
                </div>
                
                <div style="display:flex; justify-content:flex-end; gap:0.75rem;">
                    <button type="button" onclick="closeModal('editModal')" class="btn-secondary">Cancel</button>
                    <button type="submit" class="btn-primary"><i class="fas fa-save"></i> Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Single Delete Modal Confirmation -->
    <div id="deleteSingleModal" class="modal-backdrop">
        <div class="modal-content" style="max-width: 380px; text-align: center;">
            <div style="width: 50px; height: 50px; border-radius: 50%; background: rgba(220,38,38,0.12); color: var(--danger); display: flex; align-items: center; justify-content: center; margin: 0 auto 1rem; font-size: 1.4rem;">
                <i class="fas fa-trash"></i>
            </div>
            <h3 style="font-weight: 800; margin-bottom: 0.5rem;">Delete Registration?</h3>
            <p style="color: var(--text-muted); font-size: 0.875rem; margin-bottom: 1.5rem;">This entry will be permanently removed from database history.</p>
            <div style="display: flex; gap: 0.75rem;">
                <button type="button" onclick="closeModal('deleteSingleModal')" class="btn-secondary" style="flex:1; justify-content:center;">Cancel</button>
                <button type="button" id="confirmSingleDeleteBtn" class="btn-danger" style="flex:1; justify-content:center;">Delete</button>
            </div>
        </div>
    </div>

    <!-- Delete All Modal -->
    <div id="deleteAllModal" class="modal-backdrop">
        <div class="modal-content" style="max-width: 400px; text-align: center;">
            <div style="width: 56px; height: 56px; border-radius: 50%; background: rgba(220,38,38,0.15); color: var(--danger); display: flex; align-items: center; justify-content: center; margin: 0 auto 1rem; font-size: 1.5rem;">
                <i class="fas fa-radiation"></i>
            </div>
            <h3 style="font-weight: 800; margin-bottom: 0.5rem;">Delete ALL Registrations?</h3>
            <p style="color: var(--text-muted); font-size: 0.875rem; margin-bottom: 1.5rem;">
                This will wipe out all registration logs. This action <strong>cannot</strong> be undone.
            </p>
            <form id="deleteAllForm" style="display:flex; gap:0.75rem;">
                <input type="hidden" name="action" value="delete_all">
                <input type="hidden" name="confirm_delete_all" value="yes">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <button type="button" onclick="closeModal('deleteAllModal')" class="btn-secondary" style="flex:1; justify-content:center;">Cancel</button>
                <button type="submit" class="btn-danger" style="flex:1; justify-content:center;"><i class="fas fa-trash"></i> Delete Everything</button>
            </form>
        </div>
    </div>

    <script>
        // State Variables
        let currentPage = 1, limit = 10, totalPages = 1, searchQuery = '', debounceTimer;
        let currentData = [];
        let pendingDeleteId = null;

        // Elements
        const tableBody = document.getElementById('tableBody');
        const filterPanel = document.getElementById('filterPanel');
        const filterBtn = document.getElementById('toggleFiltersBtn');
        const filterCountBadge = document.getElementById('filterCount');
        const searchInput = document.getElementById('searchInput');
        const clearSearchBtn = document.getElementById('clearSearch');
        const officerSelect = document.getElementById('officerFilter');
        const startDateInput = document.getElementById('startDate');
        const endDateInput = document.getElementById('endDate');
        const limitSelect = document.getElementById('limitSelect');

        // Toast Dispatcher
        function showToast(message, type = 'info') {
            const div = document.createElement('div');
            div.className = `toast ${type}`;
            const icon = type === 'success' ? 'check-circle' : (type === 'error' ? 'exclamation-triangle' : 'info-circle');
            div.innerHTML = `<i class="fas fa-${icon}"></i> <span>${message}</span>`;
            document.getElementById('toastContainer').appendChild(div);
            setTimeout(() => {
                div.style.opacity = '0';
                div.style.transform = 'translateX(50px)';
                div.style.transition = 'all 0.3s ease';
                setTimeout(() => div.remove(), 300);
            }, 3500);
        }

        // ============================================================
        // 1. AJAX FETCH & REAL-TIME DASHBOARD SYNC
        // ============================================================
        async function fetchRegistrations() {
            renderSkeleton();

            const params = new URLSearchParams({
                ajax_action: 'fetch_registrations',
                page: currentPage,
                limit: limit,
                search: searchQuery,
                officer: officerSelect.value,
                start_date: startDateInput.value,
                end_date: endDateInput.value
            });

            try {
                const res = await fetch(`?${params.toString()}`, { credentials: 'same-origin' });
                if (!res.ok) throw new Error(`HTTP Error: ${res.status}`);
                
                const data = await res.json();
                if (data.error) throw new Error(data.error);

                currentData = data.data || [];
                currentPage = data.page || 1;
                totalPages = data.total_pages || 1;

                renderTable(data);
                updatePagination(data);
                updateFilterBadge();

                // Live Sync Summary Stats Counters
                if (data.global_total !== undefined) {
                    animateNumber(document.getElementById('statTotal'), data.global_total);
                }
                if (data.total_amount !== undefined) {
                    document.getElementById('statAmount').textContent = '₦' + Number(data.total_amount).toLocaleString('en-NG', {minimumFractionDigits: 2});
                }
                if (data.avg_amount !== undefined) {
                    document.getElementById('statAvg').textContent = '₦' + Number(data.avg_amount).toLocaleString('en-NG', {minimumFractionDigits: 2});
                }
                if (data.unique_officers !== undefined) {
                    animateNumber(document.getElementById('statOfficers'), data.unique_officers);
                }

                // Sync Officer Dropdown
                if (Array.isArray(data.officers)) {
                    const currentSelected = officerSelect.value;
                    officerSelect.innerHTML = '<option value="">All Officers</option>';
                    data.officers.forEach(off => {
                        const opt = document.createElement('option');
                        opt.value = off;
                        opt.textContent = off;
                        if (off === currentSelected) opt.selected = true;
                        officerSelect.appendChild(opt);
                    });
                }

            } catch (err) {
                console.error(err);
                tableBody.innerHTML = `
                    <tr>
                        <td colspan="7" style="text-align:center; padding:3rem;">
                            <div style="color:var(--danger)">
                                <i class="fas fa-circle-exclamation" style="font-size:2rem; margin-bottom:0.5rem;"></i>
                                <p>Failed to load data from server.</p>
                                <button type="button" class="btn-secondary" style="margin-top:0.75rem;" onclick="fetchRegistrations()">Try Again</button>
                            </div>
                        </td>
                    </tr>
                `;
                showToast(err.message || 'Error fetching records', 'error');
            }
        }

        // Render Skeletons
        function renderSkeleton() {
            tableBody.innerHTML = Array(5).fill(0).map(() => `
                <tr>
                    <td><div class="skeleton" style="height:1.2rem; width:70px;"></div></td>
                    <td><div class="skeleton" style="height:1.2rem; width:140px;"></div></td>
                    <td><div class="skeleton" style="height:1.2rem; width:100px;"></div></td>
                    <td><div class="skeleton" style="height:1.2rem; width:90px;"></div></td>
                    <td><div class="skeleton" style="height:1.2rem; width:110px;"></div></td>
                    <td><div class="skeleton" style="height:1.2rem; width:90px;"></div></td>
                    <td><div class="skeleton" style="height:1.2rem; width:60px; margin-left:auto;"></div></td>
                </tr>
            `).join('');
        }

        // Render Table Data
        function renderTable(data) {
            tableBody.innerHTML = '';
            if (!data.data || data.data.length === 0) {
                tableBody.innerHTML = `
                    <tr>
                        <td colspan="7" class="empty-state">
                            <i class="fas fa-folder-open"></i>
                            <h4 style="font-weight:700; margin-bottom:0.25rem;">No Records Found</h4>
                            <p style="font-size:0.85rem;">Try adjusting search keywords or active filters.</p>
                        </td>
                    </tr>
                `;
                return;
            }

            data.data.forEach((row, idx) => {
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td>
                        <span class="id-badge" onclick="copyToClipboard('${escapeHtml(row.client_id)}')" title="Click to copy">
                            ${escapeHtml(row.client_id)} <i class="far fa-copy" style="font-size:0.65rem;"></i>
                        </span>
                    </td>
                    <td><div style="font-weight:700;">${escapeHtml(row.client_name)}</div></td>
                    <td><span style="color:var(--text-muted); font-size:0.85rem;">${escapeHtml(row.union)}</span></td>
                    <td><span class="currency-text">₦${parseFloat(row.amount).toLocaleString('en-NG', {minimumFractionDigits: 2})}</span></td>
                    <td><span style="font-size:0.85rem; color:var(--text-muted);">${formatDate(row.date)}</span></td>
                    <td>
                        <div style="display:flex; align-items:center; gap:0.4rem; font-size:0.85rem; font-weight:600;">
                            <i class="fas fa-user-circle" style="color:var(--primary); font-size:1rem;"></i>
                            <span>${escapeHtml(row.officer)}</span>
                        </div>
                    </td>
                    <td style="text-align:right;">
                        <div class="action-group">
                            <button type="button" class="action-btn edit" title="Edit" onclick="openEdit(${idx})">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button type="button" class="action-btn delete" title="Delete" onclick="openSingleDelete(${row.id})">
                                <i class="fas fa-trash-alt"></i>
                            </button>
                        </div>
                    </td>
                `;
                tableBody.appendChild(tr);
            });
        }

        // Pagination Bar Update
        function updatePagination(data) {
            const total = data.total || 0;
            const start = total === 0 ? 0 : (currentPage - 1) * limit + 1;
            const end = Math.min(currentPage * limit, total);

            document.getElementById('pageStart').textContent = start;
            document.getElementById('pageEnd').textContent = end;
            document.getElementById('pageTotal').textContent = total;
            document.getElementById('pageIndicator').textContent = `Page ${currentPage} of ${totalPages}`;

            document.getElementById('prevPageBtn').disabled = (currentPage <= 1);
            document.getElementById('nextPageBtn').disabled = (currentPage >= totalPages);
        }

        document.getElementById('prevPageBtn').addEventListener('click', () => {
            if (currentPage > 1) { currentPage--; fetchRegistrations(); }
        });
        document.getElementById('nextPageBtn').addEventListener('click', () => {
            if (currentPage < totalPages) { currentPage++; fetchRegistrations(); }
        });

        // Filter Badge State
        function updateFilterBadge() {
            let count = 0;
            if (officerSelect.value) count++;
            if (startDateInput.value || endDateInput.value) count++;
            
            if (count > 0) {
                filterBtn.classList.add('active');
                filterCountBadge.textContent = count;
                filterCountBadge.classList.remove('hidden');
            } else {
                filterBtn.classList.remove('active');
                filterCountBadge.classList.add('hidden');
            }
        }

        // ============================================================
        // 2. FULL-WIDTH (1 CARD PER VIEW) CAROUSEL CONTROLLER
        // ============================================================
        const carousel = document.getElementById('statsCarousel');
        const prevBtn = document.getElementById('carouselPrev');
        const nextBtn = document.getElementById('carouselNext');
        const dotsContainer = document.getElementById('carouselDots');
        const cards = carousel.querySelectorAll('.stat-card');

        // Create Indicators
        cards.forEach((_, idx) => {
            const dot = document.createElement('div');
            dot.className = `dot ${idx === 0 ? 'active' : ''}`;
            dot.dataset.index = idx;
            dot.addEventListener('click', () => {
                const width = carousel.clientWidth;
                carousel.scrollTo({ left: idx * width, behavior: 'smooth' });
            });
            dotsContainer.appendChild(dot);
        });

        function updateDots() {
            const width = carousel.clientWidth;
            if (!width) return;
            const activeIndex = Math.round(carousel.scrollLeft / width);
            document.querySelectorAll('.carousel-dots .dot').forEach((dot, idx) => {
                dot.classList.toggle('active', idx === activeIndex);
            });
        }

        carousel.addEventListener('scroll', updateDots);

        // Previous button: slides exactly 1 full width card left
        prevBtn.addEventListener('click', () => {
            carousel.scrollBy({ left: -carousel.clientWidth, behavior: 'smooth' });
        });

        // Next button: slides exactly 1 full width card right
        nextBtn.addEventListener('click', () => {
            carousel.scrollBy({ left: carousel.clientWidth, behavior: 'smooth' });
        });

        // Handle window resize gracefully
        window.addEventListener('resize', updateDots);

        // ============================================================
        // 3. EDIT & DELETE AJAX OPERATIONS
        // ============================================================
        function openEdit(index) {
            const row = currentData[index];
            if (!row) return;

            document.getElementById('editId').value = row.id;
            document.getElementById('editClientNameDisplay').value = `${row.client_name} (${row.client_id})`;
            document.getElementById('editUnion').value = row.union;
            document.getElementById('editAmount').value = row.amount;

            let dt = '';
            if (row.date) {
                const d = new Date(row.date);
                if (!isNaN(d)) {
                    dt = d.toISOString().slice(0, 16);
                }
            }
            document.getElementById('editDate').value = dt;

            openModal('editModal');
        }

        document.getElementById('editForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            const btn = this.querySelector('button[type="submit"]');
            const originalText = btn.innerHTML;

            try {
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';

                const formData = new FormData(this);
                const res = await fetch('', {
                    method: 'POST',
                    body: formData,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });

                const data = await res.json();
                if (!res.ok || !data.success) throw new Error(data.message || 'Update failed');

                closeModal('editModal');
                showToast(data.message, 'success');
                fetchRegistrations();
            } catch (err) {
                showToast(err.message, 'error');
            } finally {
                btn.disabled = false;
                btn.innerHTML = originalText;
            }
        });

        function openSingleDelete(id) {
            pendingDeleteId = id;
            openModal('deleteSingleModal');
        }

        document.getElementById('confirmSingleDeleteBtn').addEventListener('click', async function() {
            if (!pendingDeleteId) return;
            const btn = this;
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

            try {
                const formData = new FormData();
                formData.append('action', 'delete');
                formData.append('id', pendingDeleteId);
                formData.append('csrf_token', '<?php echo $_SESSION['csrf_token']; ?>');

                const res = await fetch('', {
                    method: 'POST',
                    body: formData,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });

                const data = await res.json();
                if (!res.ok || !data.success) throw new Error(data.message || 'Deletion failed');

                closeModal('deleteSingleModal');
                showToast(data.message, 'success');
                fetchRegistrations();
            } catch (err) {
                showToast(err.message, 'error');
            } finally {
                btn.disabled = false;
                btn.innerHTML = 'Delete';
                pendingDeleteId = null;
            }
        });

        // Delete All
        document.getElementById('deleteAllForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            const btn = this.querySelector('button[type="submit"]');
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Erasing...';

            try {
                const formData = new FormData(this);
                const res = await fetch('', {
                    method: 'POST',
                    body: formData,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });

                const data = await res.json();
                if (!res.ok || !data.success) throw new Error(data.message || 'Operation failed');

                closeModal('deleteAllModal');
                showToast(data.message, 'success');
                currentPage = 1;
                fetchRegistrations();
            } catch (err) {
                showToast(err.message, 'error');
            } finally {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-trash"></i> Delete Everything';
            }
        });

        // ============================================================
        // 4. HELPERS & EVENT LISTENERS
        // ============================================================
        function openModal(id) {
            const modal = document.getElementById(id);
            modal.style.display = 'flex';
            setTimeout(() => modal.classList.add('show'), 10);
        }

        function closeModal(id) {
            const modal = document.getElementById(id);
            modal.classList.remove('show');
            setTimeout(() => modal.style.display = 'none', 250);
        }

        window.onclick = function(e) {
            if (e.target.classList.contains('modal-backdrop')) {
                closeModal(e.target.id);
            }
        };

        window.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                document.querySelectorAll('.modal-backdrop.show').forEach(m => closeModal(m.id));
            }
            if (e.key === '/' && document.activeElement !== searchInput) {
                e.preventDefault();
                searchInput.focus();
            }
        });

        function copyToClipboard(text) {
            navigator.clipboard.writeText(text).then(() => {
                showToast(`Copied ${text} to clipboard!`, 'info');
            });
        }

        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function formatDate(str) {
            if (!str) return '-';
            const d = new Date(str);
            return isNaN(d) ? str : d.toLocaleDateString('en-NG', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
        }

        function animateNumber(el, target) {
            const start = parseInt(el.textContent.replace(/,/g, '')) || 0;
            const diff = target - start;
            if (diff === 0) return;
            const steps = 15;
            let current = start;
            let count = 0;
            const timer = setInterval(() => {
                count++;
                current += Math.ceil(diff / steps);
                if (count >= steps) {
                    el.textContent = Number(target).toLocaleString();
                    clearInterval(timer);
                } else {
                    el.textContent = Number(current).toLocaleString();
                }
            }, 20);
        }

        function setDateRange(type) {
            const now = new Date();
            const pad = n => n.toISOString().split('T')[0];

            if (type === 'today') {
                startDateInput.value = pad(now);
                endDateInput.value = pad(now);
            } else if (type === '7days') {
                const past = new Date(); past.setDate(now.getDate() - 7);
                startDateInput.value = pad(past);
                endDateInput.value = pad(now);
            } else if (type === 'month') {
                const first = new Date(now.getFullYear(), now.getMonth(), 1);
                startDateInput.value = pad(first);
                endDateInput.value = pad(now);
            }

            document.querySelectorAll('.date-chip').forEach(c => c.classList.remove('active'));
            if (event && event.target) event.target.classList.add('active');
            currentPage = 1;
            fetchRegistrations();
        }

        // Live Search Input Debounce
        searchInput.addEventListener('input', (e) => {
            clearSearchBtn.style.display = e.target.value ? 'block' : 'none';
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(() => {
                searchQuery = e.target.value.trim();
                currentPage = 1;
                fetchRegistrations();
            }, 280);
        });

        clearSearchBtn.addEventListener('click', () => {
            searchInput.value = '';
            clearSearchBtn.style.display = 'none';
            searchQuery = '';
            currentPage = 1;
            fetchRegistrations();
        });

        [officerSelect, startDateInput, endDateInput, limitSelect].forEach(el => {
            el.addEventListener('change', () => {
                if (el === limitSelect) limit = parseInt(limitSelect.value);
                currentPage = 1;
                fetchRegistrations();
            });
        });

        document.getElementById('toggleFiltersBtn').addEventListener('click', () => filterPanel.classList.toggle('open'));
        document.getElementById('closeFilters').addEventListener('click', () => filterPanel.classList.remove('open'));
        document.getElementById('resetFilters').addEventListener('click', () => {
            officerSelect.value = '';
            startDateInput.value = '';
            endDateInput.value = '';
            searchInput.value = '';
            searchQuery = '';
            clearSearchBtn.style.display = 'none';
            document.querySelectorAll('.date-chip').forEach(c => c.classList.remove('active'));
            currentPage = 1;
            fetchRegistrations();
            showToast('Filters cleared', 'info');
        });

        document.getElementById('deleteAllBtn').addEventListener('click', () => openModal('deleteAllModal'));

        // Theme Toggle Logic
        const themeBtn = document.getElementById('themeToggle');
        const html = document.documentElement;
        if (localStorage.getItem('theme') === 'dark') {
            html.classList.add('dark');
            html.classList.remove('light');
            themeBtn.innerHTML = '<i class="fas fa-sun"></i>';
        }
        themeBtn.addEventListener('click', () => {
            const isDark = html.classList.toggle('dark');
            html.classList.toggle('light', !isDark);
            localStorage.setItem('theme', isDark ? 'dark' : 'light');
            themeBtn.innerHTML = isDark ? '<i class="fas fa-sun"></i>' : '<i class="fas fa-moon"></i>';
        });

        // Dropdown Click Handlers
        const userTrigger = document.getElementById('userDropdownTrigger');
        const userMenu = document.getElementById('userDropdown');
        userTrigger.addEventListener('click', (e) => {
            e.stopPropagation();
            userMenu.classList.toggle('show');
        });
        document.addEventListener('click', (e) => {
            if (!userTrigger.contains(e.target)) userMenu.classList.remove('show');
        });

        // Initial Load
        document.addEventListener('DOMContentLoaded', fetchRegistrations);
    </script>
</body>
</html>