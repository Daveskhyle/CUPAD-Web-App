<?php
session_start();

// --- 1. DB CONNECTION & AUTH ---

// Database Configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'cupadnam_db');
define('DB_USER', 'cupadnam_db');
define('DB_PASS', 'f2GrjZQCz8E39nCu9eLg');
define('DB_CHARSET', 'utf8mb4');

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Database Connection Failed: " . $e->getMessage());
}

// Auth Check
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: ../index.php');
    exit();
}

// CSRF Protection
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$base_path = '../';

// --- 2. HELPERS ---

function generateUuid() {
    return uniqid('u_', true); 
}

// Fetch Hierarchy Data for Dropdowns
$branches = $pdo->query("SELECT * FROM branches WHERE status = 'active' ORDER BY name ASC")->fetchAll();

// Fetch Credit Officers (Joined with Branch Name for JS Filtering)
$cos = $pdo->query("
    SELECT u.id, u.username, u.full_name as name, u.branch_id, b.name as branch_name 
    FROM users u 
    LEFT JOIN branches b ON u.branch_id = b.id
    WHERE u.role IN ('co', 'credit_officer') AND u.status = 'active'
    ORDER BY u.full_name ASC
")->fetchAll();

// User Profile Data
$full_name = $_SESSION['full_name'] ?? 'Admin';
$username = $_SESSION['username'] ?? 'admin';
$has_profile_pic = false;
$profile_pic_path = '';

$stmt = $pdo->prepare("SELECT profile_pic FROM users WHERE username = ?");
$stmt->execute([$username]);
$currentUser = $stmt->fetch();
if ($currentUser && !empty($currentUser['profile_pic']) && file_exists($base_path . $currentUser['profile_pic'])) {
    $profile_pic_path = $base_path . $currentUser['profile_pic'];
    $has_profile_pic = true;
}

// --- 3. AJAX REQUEST HANDLING ---
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    ini_set('display_errors', 0);
    header('Content-Type: application/json');
    
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        echo json_encode(['success' => false, 'message' => 'CSRF token validation failed.']);
        exit();
    }
    
    if (isset($_POST['action'])) {
        try {
            switch ($_POST['action']) {
                case 'add':
                    $name = trim($_POST['name']);
                    $description = trim($_POST['description']);
                    $branch_name = trim($_POST['branch']);
                    $co_username = $_POST['co'];

                    if (empty($name)) { echo json_encode(['success' => false, 'message' => 'Union name is required.']); exit(); }

                    // Check if assignment exists
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM assignments WHERE `union` = ? AND branch = ?");
                    $stmt->execute([$name, $branch_name]);
                    if ($stmt->fetchColumn() > 0) {
                        echo json_encode(['success' => false, 'message' => 'Union name already exists in this branch.']);
                        exit();
                    }

                    $pdo->beginTransaction();

                    // 1. Get Branch ID (Robust Lookup)
                    $stmtB = $pdo->prepare("SELECT id FROM branches WHERE TRIM(LOWER(name)) = TRIM(LOWER(?)) LIMIT 1");
                    $stmtB->execute([$branch_name]);
                    $branchRow = $stmtB->fetch();
                    $branch_id = $branchRow ? $branchRow['id'] : null;

                    // 2. Ensure Union exists in 'unions' table
                    $stmtU = $pdo->prepare("SELECT id FROM unions WHERE name = ? AND branch_id = ?");
                    $stmtU->execute([$name, $branch_id]);
                    if (!$stmtU->fetch()) {
                        $newUnionId = generateUuid();
                        $stmtInsU = $pdo->prepare("INSERT INTO unions (id, name, branch_id, description, status) VALUES (?, ?, ?, ?, 'active')");
                        $stmtInsU->execute([$newUnionId, $name, $branch_id, $description]);
                    }

                    // 3. Create Assignment
                    $stmtInsA = $pdo->prepare("INSERT INTO assignments (co, `union`, branch, assigned_date, status) VALUES (?, ?, ?, NOW(), 'active')");
                    $stmtInsA->execute([$co_username, $name, $branch_name]);

                    $pdo->commit();
                    echo json_encode(['success' => true, 'message' => 'Union added successfully.']);
                    break;
                
                case 'delete': 
                    $assignment_id = intval($_POST['assignment_index']);
                    $stmt = $pdo->prepare("DELETE FROM assignments WHERE id = ?");
                    $stmt->execute([$assignment_id]);
                    
                    if ($stmt->rowCount() > 0) {
                        echo json_encode(['success' => true, 'message' => 'Union assignment deleted successfully.']);
                    } else {
                        echo json_encode(['success' => false, 'message' => 'Assignment not found.']);
                    }
                    break;

                case 'bulk_delete_clients': 
                    $ids_to_delete = isset($_POST['ids']) ? json_decode($_POST['ids'], true) : [];
                    if (empty($ids_to_delete)) { echo json_encode(['success' => false, 'message' => 'No clients selected.']); exit(); }

                    $placeholders = implode(',', array_fill(0, count($ids_to_delete), '?'));
                    $stmt = $pdo->prepare("UPDATE clients SET status = 'inactive', updated_at = NOW() WHERE id IN ($placeholders)");
                    $stmt->execute($ids_to_delete);
                    
                    echo json_encode(['success' => true, 'message' => $stmt->rowCount() . " client(s) moved to Recycle Bin."]);
                    break;

                case 'get_closed_clients':
                    $stmt = $pdo->prepare("SELECT c.id, c.name, c.`union`, c.updated_at as deleted_at FROM clients c WHERE c.status = 'inactive' ORDER BY c.updated_at DESC");
                    $stmt->execute();
                    echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
                    break;

                case 'restore_clients':
                    $ids = isset($_POST['ids']) ? json_decode($_POST['ids'], true) : [];
                    if (empty($ids)) { echo json_encode(['success' => false, 'message' => 'No clients selected.']); exit(); }

                    $placeholders = implode(',', array_fill(0, count($ids), '?'));
                    $stmt = $pdo->prepare("UPDATE clients SET status = 'active' WHERE id IN ($placeholders)");
                    $stmt->execute($ids);
                    
                    echo json_encode(['success' => true, 'message' => $stmt->rowCount() . " client(s) restored."]);
                    break;
                
                case 'edit':
                    $old_name = trim($_POST['old_name']);
                    $original_branch = $_POST['original_branch'];
                    $original_co = $_POST['original_co'];
                    
                    $new_name = trim($_POST['name']);
                    $description = trim($_POST['description']);
                    $branch_name = trim($_POST['branch']);
                    $co_username = $_POST['co'];

                    $pdo->beginTransaction();

                    // Get IDs for new and old branches
                    $stmtB1 = $pdo->prepare("SELECT id FROM branches WHERE TRIM(LOWER(name)) = TRIM(LOWER(?)) LIMIT 1"); 
                    $stmtB1->execute([$original_branch]); $bid1 = $stmtB1->fetchColumn();
                    
                    $stmtB2 = $pdo->prepare("SELECT id FROM branches WHERE TRIM(LOWER(name)) = TRIM(LOWER(?)) LIMIT 1"); 
                    $stmtB2->execute([$branch_name]); $bid2 = $stmtB2->fetchColumn();

                    // 1. Update Unions Table
                    $stmtUpdU = $pdo->prepare("UPDATE unions SET name = ?, branch_id = ?, description = ? WHERE name = ?");
                    // Note: We update based on name primarily
                    $stmtUpdU->execute([$new_name, $bid2, $description, $old_name]);

                    // 2. Update Assignments Table
                    $stmtUpdA = $pdo->prepare("UPDATE assignments SET `union` = ?, branch = ?, co = ? WHERE `union` = ? AND branch = ? AND co = ?");
                    $stmtUpdA->execute([$new_name, $branch_name, $co_username, $old_name, $original_branch, $original_co]);

                    // 3. Update Clients to reflect new structure
                    $stmtUpdC = $pdo->prepare("UPDATE clients SET `union` = ?, branch_id = ?, officer_username = ? WHERE `union` = ? AND branch_id = ? AND officer_username = ?");
                    $stmtUpdC->execute([$new_name, $bid2, $co_username, $old_name, $bid1, $original_co]);

                    $pdo->commit();
                    echo json_encode(['success' => true, 'message' => 'Union updated successfully.']);
                    break;

                case 'get_unions':
                    // --- DATA CLEANUP ROUTINE (Fixes "Unknown Branch") ---
                    // 1. Sync unions.branch_id based on assignments.branch text matching branches.name
                    $pdo->exec("
                        UPDATE unions u 
                        JOIN assignments a ON TRIM(u.name) = TRIM(a.union)
                        JOIN branches b ON TRIM(LOWER(a.branch)) = TRIM(LOWER(b.name))
                        SET u.branch_id = b.id 
                        WHERE u.branch_id IS NULL OR u.branch_id = ''
                    ");
                    // 2. Ensure every assignment has a union record
                    $stmtMissing = $pdo->query("
                        SELECT DISTINCT a.union, a.branch, b.id as bid 
                        FROM assignments a 
                        LEFT JOIN branches b ON TRIM(LOWER(a.branch)) = TRIM(LOWER(b.name))
                        LEFT JOIN unions u ON TRIM(a.union) = TRIM(u.name) 
                        WHERE u.id IS NULL AND a.union IS NOT NULL
                    ");
                    $missing = $stmtMissing->fetchAll();
                    if($missing) {
                        $stmtIns = $pdo->prepare("INSERT INTO unions (id, name, branch_id, status) VALUES (?, ?, ?, 'active')");
                        foreach($missing as $m) {
                            $stmtIns->execute([uniqid('u_autofix_',true), $m['union'], $m['bid']]);
                        }
                    }
                    // --------------------------------------------------------

                    $page = max(1, intval($_POST['page'] ?? 1));
                    $page_size = intval($_POST['page_size'] ?? 10);
                    $offset = ($page - 1) * $page_size;
                    
                    $search = strtolower(trim($_POST['search'] ?? ''));
                    $b_filter = trim($_POST['branch_filter'] ?? '');
                    $c_filter = trim($_POST['co_filter'] ?? '');

                    $params = [];
                    $whereClauses = ["1=1"];
                    
                    if ($search) {
                        $whereClauses[] = "(LOWER(un.name) LIKE ? OR LOWER(b.name) LIKE ? OR LOWER(u.full_name) LIKE ?)";
                        $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%";
                    }
                    if ($b_filter) {
                        $whereClauses[] = "(TRIM(b.name) = ? OR TRIM(a.branch) = ?)";
                        $params[] = $b_filter;
                        $params[] = $b_filter;
                    }
                    if ($c_filter) {
                        $whereClauses[] = "a.co = ?";
                        $params[] = $c_filter;
                    }

                    $whereSql = implode(" AND ", $whereClauses);

                    $sql = "SELECT 
                                a.id as assignment_index,
                                un.name as name,
                                COALESCE(b.name, a.branch, 'Unknown Branch') as branch,
                                un.description,
                                a.co as co_username,
                                u.full_name as co_name
                            FROM unions un
                            LEFT JOIN branches b ON un.branch_id = b.id
                            LEFT JOIN assignments a ON TRIM(un.name) = TRIM(a.`union`)
                            LEFT JOIN users u ON a.co = u.username
                            WHERE $whereSql
                            GROUP BY un.name -- Prevent duplicates
                            ORDER BY un.name ASC
                            LIMIT $page_size OFFSET $offset";

                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($params);
                    $unions = $stmt->fetchAll();

                    // Count
                    $countSql = "SELECT COUNT(DISTINCT un.name) 
                                 FROM unions un
                                 LEFT JOIN branches b ON un.branch_id = b.id
                                 LEFT JOIN assignments a ON TRIM(un.name) = TRIM(a.`union`)
                                 LEFT JOIN users u ON a.co = u.username
                                 WHERE $whereSql";
                    $stmtCount = $pdo->prepare($countSql);
                    $stmtCount->execute($params);
                    $total = $stmtCount->fetchColumn();

                    // Stats
                    $statTotalUnions = $pdo->query("SELECT COUNT(*) FROM unions")->fetchColumn();
                    $statActiveAssign = $pdo->query("SELECT COUNT(*) FROM assignments WHERE status='active'")->fetchColumn();
                    $statTotalUsers = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();

                    echo json_encode([
                        'success' => true,
                        'unions' => $unions,
                        'stats' => ['total_groups' => $statTotalUnions, 'active_assignments' => $statActiveAssign, 'total_users' => $statTotalUsers],
                        'pagination' => [
                            'current_page' => $page, 'total_pages' => ceil($total/$page_size), 
                            'total_items' => $total, 'start' => ($total > 0) ? $offset + 1 : 0, 'end' => min($offset + $page_size, $total)
                        ]
                    ]);
                    break;
                
                case 'get_union_clients':
                    $union_name = $_POST['union_name'];
                    $branch_name = $_POST['branch_name'];
                    $co_username = $_POST['co_username'];

                    // 1. Get Branch ID (Robust)
                    $stmtB = $pdo->prepare("SELECT id FROM branches WHERE TRIM(LOWER(name)) = TRIM(LOWER(?)) LIMIT 1");
                    $stmtB->execute([$branch_name]);
                    $bId = $stmtB->fetchColumn();

                    $paramsC = [$union_name, $co_username];
                    $branchClause = " AND c.branch_id = ? ";
                    
                    if ($bId) {
                        array_splice($paramsC, 1, 0, $bId); 
                    } else {
                        // Fallback if branch ID not found, don't filter by branch ID to allow seeing clients
                        $branchClause = ""; 
                    }

                    // 2. Optimized Query
                    $sql = "SELECT 
                                c.id, c.name, c.phone,
                                COALESCE(SUM(s.balance), 0) as savings,
                                COALESCE(SUM(d.remaining_balance), 0) as loan
                            FROM clients c 
                            LEFT JOIN savings s ON c.id = s.client_id AND s.status = 'active'
                            LEFT JOIN disbursements d ON c.id = d.client_id AND d.status = 'active'
                            WHERE c.`union` = ? 
                              $branchClause
                              AND c.officer_username = ? 
                              AND c.status != 'inactive'
                            GROUP BY c.id";

                    $stmtC = $pdo->prepare($sql);
                    $stmtC->execute($paramsC);
                    $clients = $stmtC->fetchAll();
                    
                    foreach ($clients as &$client) {
                        $client['savings'] = floatval($client['savings']);
                        $client['loan'] = floatval($client['loan']);
                    }

                    echo json_encode(['success' => true, 'clients' => $clients]);
                    break;
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Server Error: ' . $e->getMessage()]);
        }
    }
    exit();
}
?>
<!DOCTYPE html>
<html lang="en" class="light">
<head>
    <meta charset="UTF-8">
    <title>Manage Unions - CUPAD</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="<?php echo htmlspecialchars($base_path . 'uploads/CUPAD LOGO.png'); ?>">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />
    <style>
        /* --- PREMIUM CSS VARIABLES --- */
        :root {
            --primary: #4f46e5;         /* Indigo 600 */
            --primary-hover: #4338ca;   /* Indigo 700 */
            --primary-light: #e0e7ff;   /* Indigo 100 */
            --secondary: #3b82f6;       /* Blue 500 */
            
            --success: #10b981; --success-light: #d1fae5;
            --warning: #f59e0b; --warning-light: #fef3c7;
            --danger: #ef4444;  --danger-light: #fee2e2;
            
            --bg-body: #f8fafc;         /* Slate 50 */
            --bg-surface: #ffffff;      /* White */
            --bg-surface-hover: #f1f5f9;/* Slate 100 */
            
            --text-main: #0f172a;       /* Slate 900 */
            --text-muted: #64748b;      /* Slate 500 */
            --text-light: #94a3b8;      /* Slate 400 */
            
            --border-color: #e2e8f0;    /* Slate 200 */
            --border-light: #f1f5f9;
            
            --radius-sm: 8px;
            --radius-md: 12px;
            --radius-lg: 16px;
            --radius-full: 9999px;
            
            --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
            --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.05), 0 2px 4px -2px rgb(0 0 0 / 0.05);
            --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.05), 0 4px 6px -4px rgb(0 0 0 / 0.02);
            --shadow-glow: 0 0 15px rgba(79, 70, 229, 0.2);
            
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            
            --shimmer-bg: linear-gradient(90deg, var(--bg-surface-hover) 25%, var(--border-color) 50%, var(--bg-surface-hover) 75%);
        }

        html.dark {
            --bg-body: #0f172a;         /* Slate 900 */
            --bg-surface: #1e293b;      /* Slate 800 */
            --bg-surface-hover: #334155;/* Slate 700 */
            
            --text-main: #f8fafc;       /* Slate 50 */
            --text-muted: #94a3b8;      /* Slate 400 */
            --text-light: #64748b;      /* Slate 500 */
            
            --border-color: #334155;    /* Slate 700 */
            --border-light: #1e293b;
            
            --primary-light: rgba(79, 70, 229, 0.2);
            --success-light: rgba(16, 185, 129, 0.2);
            --danger-light: rgba(239, 68, 68, 0.2);
            --warning-light: rgba(245, 158, 11, 0.2);
            
            --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.3), 0 2px 4px -2px rgb(0 0 0 / 0.2);
            --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.3), 0 4px 6px -4px rgb(0 0 0 / 0.2);
            --shadow-glow: 0 0 15px rgba(79, 70, 229, 0.4);
            
            --shimmer-bg: linear-gradient(90deg, var(--bg-surface-hover) 25%, var(--border-color) 50%, var(--bg-surface-hover) 75%);
        }

        /* --- GLOBAL STYLES --- */
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: var(--text-light); border-radius: var(--radius-full); }
        ::-webkit-scrollbar-thumb:hover { background: var(--text-muted); }

        body { 
            font-family: 'Plus Jakarta Sans', sans-serif; 
            background: var(--bg-body); 
            color: var(--text-main); 
            margin: 0; 
            padding-top: 80px; 
            transition: var(--transition); 
            -webkit-font-smoothing: antialiased;
        }
        * { box-sizing: border-box; outline: none; }
        a { text-decoration: none; color: inherit; }

        /* --- HEADER --- */
        .main-header { 
            position: fixed; top: 0; left: 0; right: 0; height: 72px; 
            background: rgba(255, 255, 255, 0.7); 
            backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px);
            border-bottom: 1px solid var(--border-color); 
            z-index: 50; transition: var(--transition);
        }
        html.dark .main-header { background: rgba(30, 41, 59, 0.8); }
        .navbar { max-width: 1400px; margin: 0 auto; height: 100%; display: flex; align-items: center; justify-content: space-between; padding: 0 1.5rem; }
        
        .logo { display: flex; align-items: center; gap: 0.75rem; font-weight: 800; font-size: 1.35rem; color: var(--text-main); letter-spacing: -0.02em; }
        .logo img { height: 38px; filter: drop-shadow(0 2px 4px rgba(0,0,0,0.1)); }

        .nav-right { display: flex; align-items: center; gap: 1.25rem; }
        .theme-btn { 
            background: var(--bg-surface); border: 1px solid var(--border-color); color: var(--text-muted); 
            cursor: pointer; font-size: 1.1rem; width: 40px; height: 40px; border-radius: var(--radius-full); 
            display: flex; align-items: center; justify-content: center; transition: var(--transition);
            box-shadow: var(--shadow-sm);
        }
        .theme-btn:hover { color: var(--primary); border-color: var(--primary); transform: translateY(-1px); box-shadow: var(--shadow-glow); }
        
        .user-pill { 
            display: flex; align-items: center; gap: 0.75rem; padding: 0.35rem 1rem 0.35rem 0.35rem; 
            background: var(--bg-surface); border: 1px solid var(--border-color); border-radius: var(--radius-full); 
            cursor: pointer; transition: var(--transition); box-shadow: var(--shadow-sm);
        }
        .user-pill:hover { border-color: var(--primary); box-shadow: var(--shadow-md); transform: translateY(-1px); }
        .user-avatar { width: 34px; height: 34px; border-radius: var(--radius-full); object-fit: cover; background: var(--primary-light); color: var(--primary); display: flex; align-items: center; justify-content: center; font-size: 0.9rem; }

        .container { max-width: 1400px; margin: 0 auto; padding: 2rem 1.5rem; }

        /* --- STATS GRID --- */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1.5rem; margin-bottom: 2.5rem; }
        .stat-card { 
            background: var(--bg-surface); padding: 1.5rem; border-radius: var(--radius-lg); 
            border: 1px solid var(--border-color); box-shadow: var(--shadow-md); 
            display: flex; align-items: center; gap: 1.25rem; transition: var(--transition);
            position: relative; overflow: hidden;
        }
        .stat-card:hover { transform: translateY(-3px); box-shadow: var(--shadow-lg); border-color: var(--primary-light); }
        .stat-card::after { 
            content: ''; position: absolute; right: -20px; top: -20px; width: 100px; height: 100px; 
            background: radial-gradient(circle, var(--primary-light) 0%, transparent 70%); opacity: 0.5; border-radius: 50%;
        }
        .stat-icon { 
            width: 56px; height: 56px; border-radius: var(--radius-md); 
            display: flex; align-items: center; justify-content: center; font-size: 1.5rem; z-index: 1;
        }
        .stat-icon.blue { background: var(--primary-light); color: var(--primary); }
        .stat-icon.green { background: var(--success-light); color: var(--success); }
        .stat-icon.orange { background: var(--warning-light); color: var(--warning); }
        .stat-info { z-index: 1; }
        .stat-info h3 { margin: 0; font-size: 1.75rem; font-weight: 800; color: var(--text-main); letter-spacing: -0.02em; }
        .stat-info p { margin: 0.25rem 0 0; font-size: 0.9rem; color: var(--text-muted); font-weight: 500; }

        /* --- MAIN CARD --- */
        .card { 
            background: var(--bg-surface); border-radius: var(--radius-lg); border: 1px solid var(--border-color); 
            box-shadow: var(--shadow-md); overflow: hidden; transition: var(--transition);
        }
        .card-header { 
            padding: 1.5rem 1.75rem; border-bottom: 1px solid var(--border-color); 
            display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; 
        }
        .page-title h1 { margin: 0; font-size: 1.35rem; font-weight: 700; color: var(--text-main); letter-spacing: -0.02em; }
        
        /* Buttons */
        .btn-primary { 
            background: linear-gradient(135deg, var(--primary), var(--secondary)); color: white; 
            border: none; padding: 0.65rem 1.25rem; border-radius: var(--radius-sm); font-weight: 600; 
            cursor: pointer; display: inline-flex; align-items: center; gap: 0.5rem; transition: var(--transition); 
            font-size: 0.9rem; box-shadow: 0 4px 6px -1px rgba(79, 70, 229, 0.2); font-family: inherit;
        }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 6px 12px -2px rgba(79, 70, 229, 0.3); }
        .btn-primary:active { transform: translateY(0); }
        
        .btn-danger { background: linear-gradient(135deg, var(--danger), #b91c1c); box-shadow: 0 4px 6px -1px rgba(239, 68, 68, 0.2); }
        .btn-danger:hover { box-shadow: 0 6px 12px -2px rgba(239, 68, 68, 0.3); }

        .btn-success { background: linear-gradient(135deg, var(--success), #047857); box-shadow: 0 4px 6px -1px rgba(16, 185, 129, 0.2); }
        .btn-success:hover { box-shadow: 0 6px 12px -2px rgba(16, 185, 129, 0.3); }

        .btn-reset { 
            background: var(--bg-surface); border: 1px solid var(--border-color); color: var(--text-main); 
            padding: 0.65rem 1rem; border-radius: var(--radius-sm); cursor: pointer; transition: var(--transition); 
            font-weight: 600; display: inline-flex; align-items: center; gap: 0.5rem; font-family: inherit; font-size: 0.9rem;
        }
        .btn-reset:hover { border-color: var(--text-muted); background: var(--bg-surface-hover); }

        /* Toolbar */
        .toolbar { padding: 1.25rem 1.75rem; background: var(--bg-body); border-bottom: 1px solid var(--border-color); display: flex; gap: 1rem; flex-wrap: wrap; }
        .search-wrap { position: relative; flex: 1; min-width: 250px; }
        .search-wrap i { position: absolute; left: 1rem; top: 50%; transform: translateY(-50%); color: var(--text-light); transition: var(--transition); }
        .search-wrap input:focus + i, .search-wrap input:not(:placeholder-shown) + i { color: var(--primary); }
        
        .form-control { 
            width: 100%; padding: 0.7rem 1rem 0.7rem 2.5rem; border: 1px solid var(--border-color); 
            border-radius: var(--radius-sm); font-family: inherit; font-size: 0.95rem; 
            background: var(--bg-surface); color: var(--text-main); transition: var(--transition); box-shadow: var(--shadow-sm);
        }
        select.form-control { padding-left: 1rem; cursor: pointer; appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='%2364748b'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M19 9l-7 7-7-7'%3E%3C/path%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 1rem center; background-size: 1rem; padding-right: 2.5rem;}
        html.dark select.form-control { background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='%2394a3b8'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M19 9l-7 7-7-7'%3E%3C/path%3E%3C/svg%3E"); }
        
        .form-control:focus { border-color: var(--primary); box-shadow: 0 0 0 4px var(--primary-light); }

        /* --- TABLE --- */
        .table-responsive { overflow-x: auto; width: 100%; min-height: 300px; }
        .custom-table { width: 100%; border-collapse: separate; border-spacing: 0; text-align: left; }
        .custom-table th { 
            background: rgba(var(--bg-surface), 0.9); backdrop-filter: blur(8px);
            padding: 1rem 1.75rem; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; 
            color: var(--text-muted); font-weight: 700; border-bottom: 1px solid var(--border-color); 
            position: sticky; top: 0; z-index: 10; user-select: none;
        }
        .custom-table td { 
            padding: 1.25rem 1.75rem; border-bottom: 1px solid var(--border-light); 
            font-size: 0.95rem; vertical-align: middle; color: var(--text-main); transition: var(--transition); 
        }
        .custom-table tbody tr { transition: var(--transition); }
        .custom-table tbody tr:hover td { background: var(--bg-surface-hover); }
        .custom-table tbody tr:last-child td { border-bottom: none; }
        
        /* Custom Checkboxes */
        input[type="checkbox"] { 
            appearance: none; width: 1.2rem; height: 1.2rem; border: 2px solid var(--text-light); 
            border-radius: 4px; cursor: pointer; transition: var(--transition); position: relative;
            background: var(--bg-surface); display: inline-flex; align-items: center; justify-content: center;
        }
        input[type="checkbox"]:checked { background: var(--primary); border-color: var(--primary); }
        input[type="checkbox"]:checked::after {
            content: '\f00c'; font-family: 'Font Awesome 6 Free'; font-weight: 900;
            color: white; font-size: 0.7rem; position: absolute;
        }
        
        .badge { 
            display: inline-flex; align-items: center; gap: 0.35rem; padding: 0.35rem 0.85rem; 
            border-radius: var(--radius-full); font-size: 0.8rem; font-weight: 600; 
        }
        .badge::before { content:''; display:block; width:6px; height:6px; border-radius:50%; }
        .badge.branch { background: var(--primary-light); color: var(--primary); border: 1px solid rgba(79,70,229,0.1); }
        .badge.branch::before { background: var(--primary); }
        
        .co-cell { display: flex; align-items: center; gap: 1rem; }
        .avatar-initial { 
            width: 38px; height: 38px; border-radius: var(--radius-full); 
            display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 0.9rem; 
            letter-spacing: 1px; box-shadow: inset 0 0 0 1px rgba(0,0,0,0.05); border: 2px solid var(--bg-surface);
        }
        
        .action-cell { display: flex; gap: 0.5rem; justify-content: flex-end; }
        .icon-action { 
            width: 36px; height: 36px; border-radius: var(--radius-sm); border: 1px solid transparent; 
            background: transparent; color: var(--text-muted); cursor: pointer; 
            display: flex; align-items: center; justify-content: center; transition: var(--transition); font-size: 1rem;
        }
        .icon-action:hover { background: var(--bg-surface-hover); color: var(--primary); border-color: var(--border-color); transform: translateY(-2px); box-shadow: var(--shadow-sm); }
        .icon-action.delete:hover { background: var(--danger-light); color: var(--danger); border-color: rgba(239, 68, 68, 0.2); }
        .icon-action.restore:hover { background: var(--success-light); color: var(--success); border-color: rgba(16, 185, 129, 0.2); }

        .pagination-bar { 
            display: flex; justify-content: space-between; align-items: center; padding: 1.25rem 1.75rem; 
            border-top: 1px solid var(--border-color); background: var(--bg-surface); 
        }
        .page-info { font-size: 0.9rem; color: var(--text-muted); font-weight: 500; }
        .page-btns button { 
            background: var(--bg-surface); border: 1px solid var(--border-color); width: 36px; height: 36px;
            border-radius: var(--radius-sm); cursor: pointer; margin-left: 0.5rem; color: var(--text-main); 
            transition: var(--transition); display: inline-flex; align-items: center; justify-content: center;
        }
        .page-btns button:hover:not(:disabled) { border-color: var(--primary); color: var(--primary); box-shadow: 0 0 0 3px var(--primary-light); }
        .page-btns button:disabled { opacity: 0.5; cursor: not-allowed; }

        /* --- SKELETON LOADING --- */
        @keyframes shimmer { 0% { background-position: -1000px 0; } 100% { background-position: 1000px 0; } }
        .skeleton { 
            height: 16px; border-radius: 6px; 
            background: var(--shimmer-bg); background-size: 1000px 100%; animation: shimmer 2s infinite linear; 
        }
        .skeleton-circle { 
            width: 38px; height: 38px; border-radius: 50%; 
            background: var(--shimmer-bg); background-size: 1000px 100%; animation: shimmer 2s infinite linear; 
        }

        /* --- MODALS --- */
        .modal-overlay { 
            position: fixed; top: 0; left: 0; width: 100%; height: 100%; 
            background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px);
            z-index: 100; display: flex; align-items: center; justify-content: center; 
            opacity: 0; visibility: hidden; transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1); 
        }
        .modal-overlay.show { opacity: 1; visibility: visible; }
        
        .modal-box { 
            background: var(--bg-surface); width: 90%; max-width: 500px; border-radius: var(--radius-lg); 
            padding: 2rem; transform: scale(0.95) translateY(10px); transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1); 
            box-shadow: var(--shadow-lg); max-height: 90vh; display: flex; flex-direction: column; 
            border: 1px solid var(--border-color);
        }
        .modal-overlay.show .modal-box { transform: scale(1) translateY(0); }
        
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; flex-shrink: 0; }
        .modal-title { font-weight: 800; font-size: 1.25rem; color: var(--text-main); letter-spacing: -0.02em; }
        .close-modal { 
            background: var(--bg-surface-hover); border: none; width: 32px; height: 32px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 1rem; cursor: pointer; color: var(--text-muted); transition: var(--transition); 
        }
        .close-modal:hover { background: var(--danger-light); color: var(--danger); transform: rotate(90deg); }
        
        .modal-body { overflow-y: auto; padding-right: 5px; flex-grow: 1; } 
        .modal-body::-webkit-scrollbar { width: 4px; }

        .form-group { margin-bottom: 1.25rem; }
        .form-label { display: block; font-size: 0.9rem; font-weight: 600; color: var(--text-main); margin-bottom: 0.5rem; }
        textarea.form-control { resize: vertical; min-height: 100px; }

        /* --- TOASTS --- */
        .toast-container { position: fixed; bottom: 24px; right: 24px; z-index: 200; display: flex; flex-direction: column; gap: 12px; }
        .toast { 
            background: var(--bg-surface); padding: 1rem 1.25rem; border-radius: var(--radius-md); 
            box-shadow: var(--shadow-lg); border: 1px solid var(--border-color); 
            display: flex; align-items: center; gap: 1rem; font-size: 0.95rem; font-weight: 500;
            animation: slideInToast 0.4s cubic-bezier(0.16, 1, 0.3, 1); position: relative; overflow: hidden;
        }
        .toast.success .toast-icon { color: var(--success); }
        .toast.error .toast-icon { color: var(--danger); }
        .toast-progress { position: absolute; bottom: 0; left: 0; height: 3px; background: var(--primary); animation: toastProgress 3s linear forwards; }
        .toast.success .toast-progress { background: var(--success); }
        .toast.error .toast-progress { background: var(--danger); }
        
        @keyframes slideInToast { from{ transform: translateX(120%) scale(0.9); opacity:0; } to{ transform: translateX(0) scale(1); opacity:1; } }
        @keyframes toastProgress { from{ width: 100%; } to{ width: 0%; } }

        .modal-footer-summary { 
            margin-top: 1.5rem; padding: 1.25rem; border-top: 1px solid var(--border-color); 
            display: flex; justify-content: space-between; align-items: center; font-weight: 700; font-size: 0.95rem; 
            background: var(--bg-surface-hover); border-radius: var(--radius-md); 
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
                <button class="theme-btn" id="themeToggle" title="Toggle Theme"><i class="fas fa-moon"></i></button>
                <div class="user-pill" onclick="window.location.href='../logout.php'" title="Logout">
                    <?php if($has_profile_pic): ?>
                        <img src="<?php echo htmlspecialchars($profile_pic_path); ?>" class="user-avatar" alt="User">
                    <?php else: ?>
                        <div class="user-avatar"><i class="fas fa-user"></i></div>
                    <?php endif; ?>
                    <span style="font-size:0.9rem; font-weight:700; color:var(--text-main); padding-right:0.5rem"><?php echo htmlspecialchars($full_name); ?></span>
                </div>
            </div>
        </div>
    </header>

    <div id="toastContainer" class="toast-container"></div>

    <main class="container">
        <!-- Stats Row -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon blue"><i class="fas fa-layer-group"></i></div>
                <div class="stat-info">
                    <h3 id="statTotalGroups">0</h3>
                    <p>Total Unions</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon green"><i class="fas fa-check-circle"></i></div>
                <div class="stat-info">
                    <h3 id="statActive">0</h3>
                    <p>Active Assignments</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon orange"><i class="fas fa-users"></i></div>
                <div class="stat-info">
                    <h3 id="statOfficers"><?php echo count($cos); ?></h3>
                    <p>Credit Officers</p>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <div class="page-title">
                    <h1>Manage Unions</h1>
                </div>
                <div style="display:flex; gap:12px; flex-wrap: wrap;">
                    <button class="btn-reset" onclick="openRecycleBin()" title="Recycle Bin">
                        <i class="fas fa-recycle" style="color:var(--text-muted)"></i> Recycle Bin
                    </button>
                    <button class="btn-primary" onclick="openModal('addModal')">
                        <i class="fas fa-plus"></i> Create Union
                    </button>
                </div>
            </div>

            <!-- Enhanced Toolbar -->
            <div class="toolbar">
                <div class="search-wrap">
                    <i class="fas fa-search"></i>
                    <input type="text" id="searchInput" class="form-control" placeholder="Search unions, branches, officers...">
                </div>
                <select id="branchFilter" class="form-control" style="width: 180px; flex-shrink: 0;">
                    <option value="">All Branches</option>
                    <?php foreach ($branches as $branch): ?>
                        <option value="<?php echo htmlspecialchars(trim($branch['name'])); ?>">
                            <?php echo htmlspecialchars($branch['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <select id="coFilter" class="form-control" style="width: 180px; flex-shrink: 0;">
                    <option value="">All Officers</option>
                </select>
                <button id="resetFilters" class="btn-reset" title="Reset Filters" style="padding: 0.65rem; width: 42px; justify-content:center;"><i class="fas fa-undo"></i></button>
            </div>

            <div class="table-responsive">
                <table class="custom-table">
                    <thead>
                        <tr>
                            <th>Union Name & Summary</th>
                            <th>Branch</th>
                            <th>Assigned Officer</th>
                            <th style="text-align:right">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="tableBody">
                        <!-- Premium Skeleton Loader -->
                        <?php for($i=0; $i<5; $i++): ?>
                        <tr>
                            <td><div class="skeleton" style="width: 60%; margin-bottom: 8px;"></div><div class="skeleton" style="width: 40%; height: 12px"></div></td>
                            <td><div class="skeleton" style="width: 80px; border-radius: var(--radius-full);"></div></td>
                            <td><div style="display:flex; gap:12px; align-items:center"><div class="skeleton-circle"></div><div style="display:flex; flex-direction:column; gap:6px;"><div class="skeleton" style="width: 120px;"></div><div class="skeleton" style="width: 80px; height:10px;"></div></div></div></td>
                            <td><div class="skeleton" style="width: 100px; float: right;"></div></td>
                        </tr>
                        <?php endfor; ?>
                    </tbody>
                </table>
            </div>

            <div class="pagination-bar">
                <div class="page-info" id="paginationInfo">Showing 0 results</div>
                <div class="page-btns">
                    <button id="prevBtn" onclick="changePage(-1)"><i class="fas fa-chevron-left"></i></button>
                    <button id="nextBtn" onclick="changePage(1)"><i class="fas fa-chevron-right"></i></button>
                </div>
            </div>
        </div>
    </main>

    <!-- Add Modal -->
    <div id="addModal" class="modal-overlay">
        <div class="modal-box">
            <div class="modal-header">
                <div class="modal-title">New Union Assignment</div>
                <button class="close-modal" onclick="closeModal('addModal')"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <form id="addForm">
                    <input type="hidden" name="action" value="add">
                    <div class="form-group">
                        <label class="form-label">Union Name</label>
                        <input type="text" name="name" class="form-control" required placeholder="e.g. Traders Association">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Branch</label>
                        <select name="branch" class="form-control" required onchange="populateModalCo(this.value, 'addCoSelect')">
                            <option value="">Select Branch...</option>
                            <?php foreach ($branches as $b): ?>
                                <option value="<?php echo htmlspecialchars(trim($b['name'])); ?>"><?php echo htmlspecialchars($b['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Credit Officer</label>
                        <select name="co" id="addCoSelect" class="form-control" required disabled>
                            <option value="">Select Branch First</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Description (Optional)</label>
                        <textarea name="description" class="form-control" placeholder="Add brief notes..."></textarea>
                    </div>
                    <button type="submit" class="btn-primary" style="width:100%; justify-content:center; padding: 0.9rem; margin-top: 0.5rem; font-size: 1rem;">Create Union</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Modal -->
    <div id="editModal" class="modal-overlay">
        <div class="modal-box">
            <div class="modal-header">
                <div class="modal-title">Edit Assignment</div>
                <button class="close-modal" onclick="closeModal('editModal')"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <form id="editForm">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="old_name" id="editOldName">
                    <input type="hidden" name="original_branch" id="editOrigBranch">
                    <input type="hidden" name="original_co" id="editOrigCo">

                    <div class="form-group">
                        <label class="form-label">Union Name</label>
                        <input type="text" name="name" id="editName" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Branch</label>
                        <select name="branch" id="editBranch" class="form-control" required onchange="populateModalCo(this.value, 'editCoSelect')">
                            <?php foreach ($branches as $b): ?>
                                <option value="<?php echo htmlspecialchars(trim($b['name'])); ?>"><?php echo htmlspecialchars($b['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Credit Officer</label>
                        <select name="co" id="editCoSelect" class="form-control" required></select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Description</label>
                        <textarea name="description" id="editDesc" class="form-control"></textarea>
                    </div>
                    <button type="submit" class="btn-primary" style="width:100%; justify-content:center; padding: 0.9rem; margin-top: 0.5rem; font-size: 1rem;">Save Changes</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Confirm Delete Modal -->
    <div id="confirmModal" class="modal-overlay">
        <div class="modal-box" style="max-width: 400px; text-align: center; padding: 2.5rem 2rem;">
            <div style="font-size: 4rem; color: var(--danger); margin-bottom: 1rem; filter: drop-shadow(0 4px 6px rgba(239, 68, 68, 0.3));"><i class="fas fa-exclamation-circle"></i></div>
            <h3 style="margin: 0 0 0.75rem; font-size: 1.5rem; font-weight:800; color:var(--text-main); letter-spacing:-0.02em;">Are you sure?</h3>
            <p style="color: var(--text-muted); margin-bottom: 2rem; line-height:1.5;">This will permanently delete the assignment. Associated clients will remain but may be unassigned.</p>
            <div style="display: flex; gap: 1rem; justify-content: center;">
                <button class="btn-reset" onclick="closeModal('confirmModal')" style="flex:1; justify-content:center;">Cancel</button>
                <button class="btn-primary btn-danger" id="confirmDeleteBtn" style="flex:1; justify-content:center;">Yes, Delete</button>
            </div>
        </div>
    </div>

    <!-- Clients List Modal -->
    <div id="clientsModal" class="modal-overlay">
        <div class="modal-box" style="max-width: 850px; height: 85vh; padding:0; overflow:hidden;">
            <div class="modal-header" style="padding: 1.5rem 2rem; border-bottom:1px solid var(--border-color); margin-bottom:0; background:var(--bg-surface);">
                <div class="modal-title" id="clientsTitle">Clients</div>
                <div style="margin-left:auto; display:flex; gap:0.75rem">
                    <button class="btn-reset" onclick="exportToCSV()" title="Export CSV" style="padding:0.5rem 0.75rem"><i class="fas fa-file-csv"></i> Export</button>
                    <button id="bulkDeleteBtn" onclick="executeBulkDelete()" class="btn-primary btn-danger" 
                            style="display: none; padding: 0.5rem 1rem;">
                        <i class="fas fa-trash"></i> Move to Bin (<span id="selectedCount">0</span>)
                    </button>
                    <button class="close-modal" onclick="closeModal('clientsModal')" style="margin-left:0.5rem"><i class="fas fa-times"></i></button>
                </div>
            </div>
            <div class="modal-body" id="clientsContent" style="padding: 0; background:var(--bg-body);">
                <div style="text-align:center; padding:4rem;">
                    <div class="skeleton-circle" style="margin: 0 auto; width: 50px; height: 50px; margin-bottom:1rem;"></div>
                    <div style="color:var(--text-muted); font-weight:500;">Loading clients...</div>
                </div>
            </div>
            <div id="clientsFooter" class="modal-footer-summary" style="display:none; margin-top:0; border-radius:0;"></div>
        </div>
    </div>

    <!-- Recycle Bin Modal -->
    <div id="recycleBinModal" class="modal-overlay">
        <div class="modal-box" style="max-width: 800px; height: 85vh; padding:0; overflow:hidden;">
            <div class="modal-header" style="padding: 1.5rem 2rem; border-bottom:1px solid var(--border-color); margin-bottom:0; background:var(--bg-surface);">
                <div class="modal-title" style="display:flex; align-items:center; gap:0.5rem;"><i class="fas fa-recycle" style="color:var(--text-muted)"></i> Closed Clients</div>
                <div style="margin-left:auto; display:flex; gap:0.75rem">
                    <button id="bulkRestoreBtn" onclick="executeBulkRestore()" class="btn-primary btn-success" 
                            style="display: none; padding: 0.5rem 1rem;">
                        <i class="fas fa-trash-restore"></i> Restore (<span id="restoreCount">0</span>)
                    </button>
                    <button class="close-modal" onclick="closeModal('recycleBinModal')" style="margin-left:0.5rem"><i class="fas fa-times"></i></button>
                </div>
            </div>
            <div class="modal-body" id="recycleBinContent" style="padding: 0; background:var(--bg-body);">
                <div style="padding: 4rem; text-align: center;">
                     <div class="skeleton-circle" style="margin: 0 auto; width: 50px; height: 50px; margin-bottom:1rem;"></div>
                     <div style="color:var(--text-muted); font-weight:500;">Loading recycle bin...</div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // --- DATA & CONFIG ---
        const csrfToken = '<?php echo $_SESSION['csrf_token']; ?>';
        const cosData = <?php echo json_encode(array_values($cos)); ?>;
        
        let currPage = 1;
        let filters = { search: '', branch: '', co: '' };
        let currentUnionData = { unionName: '', branchName: '', officerName: '', clients: [] };
        let closedClientsData = []; 

        // --- THEME ---
        const themeBtn = document.getElementById('themeToggle');
        themeBtn.addEventListener('click', () => {
            const isDark = document.documentElement.classList.toggle('dark');
            localStorage.setItem('theme', isDark ? 'dark' : 'light');
            themeBtn.innerHTML = isDark ? '<i class="fas fa-sun"></i>' : '<i class="fas fa-moon"></i>';
        });
        if(localStorage.getItem('theme') === 'dark') {
            document.documentElement.classList.add('dark');
            themeBtn.innerHTML = '<i class="fas fa-sun"></i>';
        }

        // --- FILTER & UI LOGIC ---
        function populateFilterCo(branchName) {
            const coFilter = document.getElementById('coFilter');
            coFilter.innerHTML = '<option value="">All Officers</option>';
            const safeBranchName = branchName ? branchName.trim() : "";
            let count = 0;

            cosData.forEach(c => {
                const safeCoBranch = c.branch_name ? c.branch_name.trim() : "";
                if (safeBranchName === "" || safeCoBranch === safeBranchName) {
                    const opt = document.createElement('option');
                    opt.value = c.username;
                    opt.textContent = (c.name && c.name.trim() !== '') ? c.name : c.username;
                    coFilter.appendChild(opt);
                    count++;
                }
            });
            
            if(safeBranchName !== "" && count === 0) {
                const opt = document.createElement('option');
                opt.textContent = "-- No Officers in this Branch --";
                opt.disabled = true;
                coFilter.appendChild(opt);
            }
        }

        window.populateModalCo = function(branchName, targetId, selectedVal = null) {
            const sel = document.getElementById(targetId);
            sel.innerHTML = '<option value="">Select Officer</option>';
            sel.disabled = false;
            let count = 0;
            const safeBranchName = branchName ? branchName.trim() : "";

            cosData.forEach(c => {
                const safeCoBranch = c.branch_name ? c.branch_name.trim() : "";
                if(safeCoBranch === safeBranchName) {
                     const o = document.createElement('option');
                     o.value = c.username;
                     o.textContent = (c.name && c.name.trim() !== '') ? c.name : c.username;
                     if(selectedVal && c.username === selectedVal) o.selected = true;
                     sel.appendChild(o);
                     count++;
                }
            });
            
            if(count === 0) { 
                sel.innerHTML = '<option>No officers in this branch</option>'; 
                if(!selectedVal) sel.disabled = true; 
            }
        };

        // --- MAIN LOAD ---
        function loadUnions() {
            const tbody = document.getElementById('tableBody');
            
            const fd = new FormData();
            fd.append('csrf_token', csrfToken);
            fd.append('action', 'get_unions');
            fd.append('page', currPage);
            fd.append('search', filters.search);
            fd.append('branch_filter', filters.branch);
            fd.append('co_filter', filters.co);

            fetch(window.location.href, { method: 'POST', body: fd, headers: {'X-Requested-With': 'XMLHttpRequest'} })
            .then(r => r.json())
            .then(d => {
                if(d.success) {
                    renderTable(d.unions);
                    updateStats(d.stats);
                    updatePagination(d.pagination);
                } else {
                    showToast(d.message || 'Error loading data', 'error');
                }
            })
            .catch(e => {
                console.error(e);
                tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;padding:3rem;color:var(--danger);font-weight:600;"><i class="fas fa-exclamation-triangle" style="font-size:2rem;margin-bottom:1rem;display:block;"></i>Failed to load data.</td></tr>';
            });
        }

        function renderTable(data) {
            const tbody = document.getElementById('tableBody');
            if(!data || data.length === 0) {
                tbody.innerHTML = `<tr><td colspan="4" style="text-align:center; padding: 4rem 1rem;"><div style="color:var(--text-light); font-size: 3.5rem; margin-bottom:1rem;"><i class="fas fa-folder-open"></i></div><div style="color:var(--text-muted); font-weight:600; font-size:1.1rem;">No unions found</div><div style="color:var(--text-light); font-size:0.9rem; margin-top:0.5rem;">Try adjusting your filters or create a new union.</div></td></tr>`;
                return;
            }

            tbody.innerHTML = data.map(u => {
                let coDisplay = '<span style="color:var(--text-light); font-weight:500;">Unassigned</span>';
                
                const safeName = encodeURIComponent(u.name);
                const safeBranch = encodeURIComponent(u.branch);
                const safeCo = encodeURIComponent(u.co_username || '');
                const safeDesc = encodeURIComponent(u.description || '');

                if(u.co_username) {
                    const coName = u.co_name || u.co_username;
                    const initials = getInitials(coName);
                    const color = getColorFromInitials(coName);
                    coDisplay = `<div class="co-cell"><div class="avatar-initial" style="background:${color}15; color:${color}; border-color:${color}30;">${initials}</div><div><div style="font-weight:700; font-size:0.95rem; color:var(--text-main);">${coName}</div><div style="font-size:0.8rem; color:var(--text-muted); font-weight:500;">@${u.co_username}</div></div></div>`;
                }

                const badge = `<span class="badge branch">${u.branch}</span>`;
                const deleteBtn = `<button class="icon-action delete" title="Delete Assignment" onclick="confirmDelete(${u.assignment_index})"><i class="fas fa-trash-alt"></i></button>`;

                return `
                <tr>
                    <td data-label="Union Name">
                        <div style="font-weight:700; color:var(--text-main); font-size:1.05rem;">${u.name}</div>
                        <div style="font-size:0.85rem; color:var(--text-muted); margin-top:4px; font-weight:500; line-height:1.4;">${u.description || '<span style="opacity:0.5">No description provided</span>'}</div>
                    </td>
                    <td data-label="Branch">${badge}</td>
                    <td data-label="Officer">${coDisplay}</td>
                    <td data-label="Actions">
                        <div class="action-cell">
                            <button class="icon-action" title="View Clients" 
                                onclick="viewClients(decodeURIComponent('${safeName}'), decodeURIComponent('${safeBranch}'), decodeURIComponent('${safeCo}'))">
                                <i class="fas fa-users"></i>
                            </button>
                            <button class="icon-action" title="Edit" 
                                onclick="openEdit(decodeURIComponent('${safeName}'), decodeURIComponent('${safeBranch}'), decodeURIComponent('${safeCo}'), decodeURIComponent('${safeDesc}'))">
                                <i class="fas fa-pen"></i>
                            </button>
                            ${deleteBtn}
                        </div>
                    </td>
                </tr>`;
            }).join('');
        }

        // --- VIEW CLIENTS ---
        function viewClients(union, branchName, co) {
            document.getElementById('clientsTitle').innerText = `${union}`;
            document.getElementById('clientsContent').innerHTML = '<div style="padding:4rem;text-align:center;"><div class="skeleton-circle" style="margin: 0 auto; width: 50px; height: 50px; margin-bottom:1rem;"></div><div style="color:var(--text-muted); font-weight:500;">Loading clients...</div></div>';
            openModal('clientsModal');
            
            const fd = new FormData();
            fd.append('csrf_token', csrfToken);
            fd.append('action', 'get_union_clients');
            fd.append('union_name', union);
            fd.append('branch_name', branchName);
            fd.append('co_username', co);
            
            fetch(window.location.href, { method: 'POST', body: fd, headers: {'X-Requested-With': 'XMLHttpRequest'} })
            .then(r => r.json())
            .then(d => {
                if(d.success) {
                    currentUnionData = { unionName: union, branchName: branchName, officerName: co, clients: d.clients };
                    renderClientTable();
                } else {
                    document.getElementById('clientsContent').innerHTML = `<div style="padding:3rem; text-align:center; color:var(--danger); font-weight:600;">${d.message}</div>`;
                }
            });
        }

        function renderClientTable() {
            const list = currentUnionData.clients;
            const content = document.getElementById('clientsContent');
            const footer = document.getElementById('clientsFooter');
            
            if (!list || list.length === 0) {
                content.innerHTML = '<div style="padding:4rem; text-align:center; background:var(--bg-surface); height:100%;"><i class="fas fa-users-slash" style="font-size:3.5rem; color:var(--text-light); margin-bottom:1rem"></i><br><span style="color:var(--text-muted); font-weight:600; font-size:1.1rem;">No active clients in this union.</span></div>';
                footer.style.display = 'none';
                document.getElementById('bulkDeleteBtn').style.display = 'none';
                return;
            }

            let grandLoan = 0; let grandSavings = 0;
            const rows = list.map(c => {
                grandLoan += c.loan; grandSavings += c.savings;
                const loanStyle = c.loan > 0 ? 'color: var(--danger); font-weight: 700;' : 'color: var(--text-light); font-weight:500;';
                const savingsStyle = c.savings > 0 ? 'color: var(--success); font-weight: 700;' : 'color: var(--text-light); font-weight:500;';
                return `
                <tr id="client-row-${c.id}" class="clickable-row" onclick="rowToggle(event, '${c.id}')">
                    <td style="width: 50px; text-align:center; padding-left:1.5rem;"><input type="checkbox" class="client-checkbox" value="${c.id}" onchange="updateBulkBtn()"></td>
                    <td><div style="font-weight:700; color:var(--text-main);">${c.name}</div></td>
                    <td style="color:var(--text-muted); font-size:0.9rem; font-weight:500;">${c.phone || '-'}</td>
                    <td style="text-align:right; ${loanStyle}">${c.loan > 0 ? formatNumber(c.loan) : '-'}</td>
                    <td style="text-align:right; ${savingsStyle}">${c.savings > 0 ? formatNumber(c.savings) : '-'}</td>
                    <td style="text-align:right; padding-right:1.5rem;"><button class="icon-action delete" style="width:32px; height:32px; display:inline-flex;" title="Recycle Bin" onclick="deleteSingleClient('${c.id}')"><i class="fas fa-trash-alt" style="font-size:0.9rem"></i></button></td>
                </tr>`;
            }).join('');

            content.innerHTML = `<div style="overflow-x:auto; height:100%; background:var(--bg-surface);"><table class="custom-table" style="font-size:0.9rem;"><thead style="position: sticky; top: 0; z-index: 10;"><tr><th style="width: 50px; text-align:center; padding-left:1.5rem;"><input type="checkbox" id="selectAllBox" onchange="toggleSelectAll(this)"></th><th>Client Name</th><th>Phone</th><th style="text-align:right">Active Loan</th><th style="text-align:right">Savings</th><th style="padding-right:1.5rem;"></th></tr></thead><tbody>${rows}</tbody></table></div>`;
            footer.style.display = 'flex';
            footer.innerHTML = `
                <div style="color:var(--text-main);"><i class="fas fa-user-friends" style="color:var(--primary); margin-right:6px;"></i> Total Clients: <span style="font-weight:800">${list.length}</span></div>
                <div style="display:flex; gap:1.5rem;">
                    <div style="background:var(--danger-light); color:var(--danger); padding:0.4rem 0.8rem; border-radius:var(--radius-sm);"><i class="fas fa-hand-holding-usd"></i> Loans: ${formatNumber(grandLoan)}</div>
                    <div style="background:var(--success-light); color:var(--success); padding:0.4rem 0.8rem; border-radius:var(--radius-sm);"><i class="fas fa-piggy-bank"></i> Savings: ${formatNumber(grandSavings)}</div>
                </div>`;
        }

        // --- UTILS ---
        function getInitials(name) { return name.split(' ').map(n=>n[0]).join('').substring(0,2).toUpperCase(); }
        function getColorFromInitials(name) {
            const colors = ['#4f46e5', '#059669', '#d97706', '#dc2626', '#7c3aed', '#db2777', '#0891b2', '#ea580c'];
            let hash = 0; for (let i = 0; i < name.length; i++) hash = name.charCodeAt(i) + ((hash << 5) - hash);
            return colors[Math.abs(hash) % colors.length];
        }
        function formatNumber(num) { return Math.round(num).toLocaleString('en-US'); }
        
        function showToast(msg, type='success') {
            const t = document.createElement('div'); 
            t.className = `toast ${type}`; 
            const icon = type==='success' ? 'check-circle' : 'exclamation-circle';
            t.innerHTML = `
                <i class="fas fa-${icon} toast-icon" style="font-size:1.2rem;"></i> 
                <span style="flex:1;">${msg}</span>
                <div class="toast-progress"></div>
            `;
            document.getElementById('toastContainer').appendChild(t); 
            setTimeout(() => { 
                t.style.transform = 'translateX(120%) scale(0.9)'; 
                t.style.opacity = '0'; 
                setTimeout(()=>t.remove(), 300); 
            }, 3000);
        }

        function updateStats(s) {
            if(s) {
                document.getElementById('statTotalGroups').textContent = s.total_groups;
                document.getElementById('statActive').textContent = s.active_assignments;
            }
        }
        function updatePagination(p) {
            document.getElementById('paginationInfo').innerHTML = `Showing <span style="font-weight:700;color:var(--text-main)">${p.start}-${p.end}</span> of <span style="font-weight:700;color:var(--text-main)">${p.total_items}</span>`;
            document.getElementById('prevBtn').disabled = p.current_page <= 1;
            document.getElementById('nextBtn').disabled = p.current_page >= p.total_pages;
        }
        function changePage(d) { currPage += d; loadUnions(); }

        // --- EVENTS ---
        let timeout = null;
        document.getElementById('searchInput').addEventListener('keyup', e => { clearTimeout(timeout); timeout = setTimeout(() => { filters.search = e.target.value; currPage=1; loadUnions(); }, 300); });
        
        document.getElementById('branchFilter').addEventListener('change', e => { 
            const selectedBranch = e.target.value;
            filters.branch = selectedBranch; 
            filters.co = ''; 
            populateFilterCo(selectedBranch); 
            currPage = 1; 
            loadUnions(); 
        });

        document.getElementById('coFilter').addEventListener('change', e => { filters.co = e.target.value; currPage = 1; loadUnions(); });
        
        document.getElementById('resetFilters').addEventListener('click', () => { 
            document.getElementById('searchInput').value = ''; 
            document.getElementById('branchFilter').value = ''; 
            populateFilterCo(''); 
            filters = {search:'', branch:'', co:''}; 
            currPage = 1; 
            loadUnions(); 
        });

        // Modals & Forms
        function openModal(id) { document.getElementById(id).classList.add('show'); }
        function closeModal(id) { document.getElementById(id).classList.remove('show'); }
        document.addEventListener('keydown', (e) => { if (e.key === 'Escape') document.querySelectorAll('.modal-overlay.show').forEach(el => el.classList.remove('show')); });

        function setupForm(id, modalId) {
            document.getElementById(id).addEventListener('submit', function(e){
                e.preventDefault();
                const btn = this.querySelector('button[type="submit"]');
                const origText = btn.innerHTML;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
                btn.disabled = true;

                const fd = new FormData(this);
                fd.append('csrf_token', csrfToken);
                fetch(window.location.href, {method:'POST', body:fd, headers:{'X-Requested-With':'XMLHttpRequest'}})
                .then(r=>r.json()).then(d=>{ 
                    showToast(d.message, d.success?'success':'error'); 
                    if(d.success) { closeModal(modalId); this.reset(); loadUnions(); } 
                }).finally(()=>{
                    btn.innerHTML = origText;
                    btn.disabled = false;
                });
            });
        }
        setupForm('addForm', 'addModal');
        setupForm('editForm', 'editModal');

        function openEdit(name, branch, co, desc) {
            document.getElementById('editOldName').value = name;
            document.getElementById('editOrigBranch').value = branch;
            document.getElementById('editOrigCo').value = co;
            
            document.getElementById('editName').value = name;
            document.getElementById('editBranch').value = branch;
            document.getElementById('editDesc').value = desc;
            
            populateModalCo(branch, 'editCoSelect', co);
            openModal('editModal');
        }

        let deleteId = null;
        function confirmDelete(idx) { deleteId = idx; openModal('confirmModal'); }
        document.getElementById('confirmDeleteBtn').addEventListener('click', function() {
            if(deleteId !== null) {
                const btn = this; const origText = btn.innerHTML;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Deleting...'; btn.disabled = true;
                
                const fd = new FormData();
                fd.append('csrf_token', csrfToken);
                fd.append('action', 'delete');
                fd.append('assignment_index', deleteId);
                fetch(window.location.href, {method:'POST', body:fd, headers:{'X-Requested-With':'XMLHttpRequest'}})
                .then(r=>r.json()).then(d=>{ 
                    closeModal('confirmModal'); 
                    showToast(d.message, d.success?'success':'error'); 
                    if(d.success) loadUnions(); 
                }).finally(()=>{ btn.innerHTML = origText; btn.disabled = false; });
            }
        });

        // --- CLIENT ACTIONS (CSV, DELETE, RESTORE) ---
        function exportToCSV() {
            if(!currentUnionData.clients.length) return;
            let csv = "ID,Name,Phone,Loan,Savings\n";
            currentUnionData.clients.forEach(c => csv += `${c.id},"${c.name}",${c.phone||''},${c.loan},${c.savings}\n`);
            const link = document.createElement("a");
            link.href = encodeURI("data:text/csv;charset=utf-8," + csv);
            link.download = `clients_${currentUnionData.unionName.replace(/\s+/g,'_')}.csv`;
            link.click();
        }

        function toggleSelectAll(s) { document.querySelectorAll('.client-checkbox').forEach(cb => cb.checked = s.checked); updateBulkBtn(); }
        function updateBulkBtn() {
            const count = document.querySelectorAll('.client-checkbox:checked').length;
            const btn = document.getElementById('bulkDeleteBtn');
            const span = document.getElementById('selectedCount');
            if(count>0) { btn.style.display='inline-flex'; span.innerText=count; } else { btn.style.display='none'; }
        }

        function executeBulkDelete() {
            const boxes = document.querySelectorAll('.client-checkbox:checked');
            if(boxes.length===0) return;
            if(!confirm(`Move ${boxes.length} clients to Recycle Bin?`)) return;
            sendToBin(Array.from(boxes).map(cb => cb.value));
        }
        function deleteSingleClient(id) { if(confirm("Move client to Recycle Bin?")) sendToBin([id]); }
        function sendToBin(ids) {
            const fd = new FormData();
            fd.append('csrf_token', csrfToken);
            fd.append('action', 'bulk_delete_clients');
            fd.append('ids', JSON.stringify(ids));
            fetch(window.location.href, {method:'POST', body:fd, headers:{'X-Requested-With':'XMLHttpRequest'}})
            .then(r=>r.json()).then(d=>{ 
                showToast(d.message, d.success?'success':'error'); 
                if(d.success) { currentUnionData.clients = currentUnionData.clients.filter(c => !ids.includes(c.id)); renderClientTable(); }
            });
        }
        
        function rowToggle(e, id) {
            if (['input','button','a','select'].includes((e.target.tagName||'').toLowerCase()) || e.target.closest('.icon-action')) return;
            const cb = document.querySelector(`.client-checkbox[value="${id}"]`);
            if (cb) { cb.checked = !cb.checked; updateBulkBtn(); }
        }

        // --- RECYCLE BIN ---
        function openRecycleBin() {
            document.getElementById('bulkRestoreBtn').style.display='none';
            document.getElementById('recycleBinContent').innerHTML='<div style="padding:4rem;text-align:center;"><div class="skeleton-circle" style="margin: 0 auto; width: 50px; height: 50px; margin-bottom:1rem;"></div><div style="color:var(--text-muted); font-weight:500;">Loading recycle bin...</div></div>';
            openModal('recycleBinModal');
            const fd = new FormData(); fd.append('csrf_token', csrfToken); fd.append('action', 'get_closed_clients');
            fetch(window.location.href, {method:'POST', body:fd, headers:{'X-Requested-With':'XMLHttpRequest'}}).then(r=>r.json()).then(d=>{ 
                closedClientsData = d.success ? d.data : []; renderRecycleBin(); 
            });
        }
        function renderRecycleBin() {
            const content = document.getElementById('recycleBinContent');
            if (!closedClientsData.length) { content.innerHTML = '<div style="padding:4rem; text-align:center; background:var(--bg-surface); height:100%;"><i class="fas fa-box-open" style="font-size:3.5rem; color:var(--text-light); margin-bottom:1rem"></i><br><span style="color:var(--text-muted); font-weight:600; font-size:1.1rem;">Recycle bin is empty.</span></div>'; return; }
            const rows = closedClientsData.map(c => `<tr><td style="text-align:center; padding-left:1.5rem;"><input type="checkbox" class="restore-checkbox" value="${c.id}" onchange="updateRestoreBtn()"></td><td><div style="font-weight:700;color:var(--text-main);">${c.name}</div></td><td style="color:var(--text-muted);font-weight:500;">${c.union||'-'}</td><td style="text-align:right; padding-right:1.5rem;"><button class="icon-action restore" style="display:inline-flex;" onclick="restoreSingleClient('${c.id}')"><i class="fas fa-trash-restore"></i></button></td></tr>`).join('');
            content.innerHTML = `<div style="overflow-y:auto; height:100%; background:var(--bg-surface);"><table class="custom-table" style="font-size:0.9rem"><thead><tr><th style="width:50px;text-align:center; padding-left:1.5rem;"><input type="checkbox" onchange="toggleSelectAllRestore(this)"></th><th>Name</th><th>Union</th><th style="text-align:right; padding-right:1.5rem;">Action</th></tr></thead><tbody>${rows}</tbody></table></div>`;
        }
        function toggleSelectAllRestore(s) { document.querySelectorAll('.restore-checkbox').forEach(cb => cb.checked = s.checked); updateRestoreBtn(); }
        function updateRestoreBtn() { const c = document.querySelectorAll('.restore-checkbox:checked').length; const b = document.getElementById('bulkRestoreBtn'); if(c>0){b.style.display='inline-flex';document.getElementById('restoreCount').innerText=c;}else{b.style.display='none';} }
        function restoreSingleClient(id) { if(confirm("Restore client?")) executeRestore([id]); }
        function executeBulkRestore() { const ids = Array.from(document.querySelectorAll('.restore-checkbox:checked')).map(cb=>cb.value); if(ids.length && confirm(`Restore ${ids.length} clients?`)) executeRestore(ids); }
        function executeRestore(ids) {
            const fd = new FormData(); fd.append('csrf_token', csrfToken); fd.append('action', 'restore_clients'); fd.append('ids', JSON.stringify(ids));
            fetch(window.location.href, {method:'POST', body:fd, headers:{'X-Requested-With':'XMLHttpRequest'}}).then(r=>r.json()).then(d=>{ showToast(d.message, d.success?'success':'error'); if(d.success) openRecycleBin(); });
        }

        // --- INIT ---
        populateFilterCo('');
        loadUnions();

    </script>
</body>
</html>