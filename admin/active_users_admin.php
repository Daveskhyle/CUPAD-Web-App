<?php
date_default_timezone_set('Africa/Lagos');
session_start();

// --- 1. CONFIGURATION & DATABASE CONNECTION ---
ini_set('date.timezone', 'Africa/Lagos');
$base_path = '../';

// Database Credentials
$db_host = 'localhost';
$db_name = 'cupadnam_db';
$db_user = 'cupadnam_db';
$db_pass = 'f2GrjZQCz8E39nCu9eLg';

try {
    // Check if a global config exists, otherwise use credentials above
    if (file_exists('../config.php')) {
        require_once '../config.php';
    } else {
        $conn = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    die("<h3>Database Connection Error</h3><p>Could not connect to database.</p><small>Error: " . $e->getMessage() . "</small>");
}

// Check Admin Access
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: ../index.php');
    exit();
}

$full_name = $_SESSION['full_name'] ?? 'Admin';
$username = $_SESSION['username'] ?? 'admin';
$user_role = $_SESSION['role'] ?? 'admin';

// Get profile pic from DB
try {
    $stmt = $conn->prepare("SELECT profile_pic FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $res = $stmt->fetch();
    $my_profile_pic = $res['profile_pic'] ?? '';
} catch (PDOException $e) {
    $my_profile_pic = '';
}

$has_profile_pic = !empty($my_profile_pic) && file_exists($base_path . 'uploads/' . $my_profile_pic);
$profile_pic_path = $has_profile_pic ? $base_path . 'uploads/' . $my_profile_pic : '';

// --- 2. ACTIONS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    try {
        if ($_POST['action'] === 'force_logout') {
            $target_id = $_POST['user_id'] ?? null;
            if ($target_id) {
                $stmt = $conn->prepare("UPDATE users SET last_login = NULL WHERE id = ?");
                $stmt->execute([$target_id]);
                echo json_encode(['success' => true, 'message' => 'User logged out successfully.']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Invalid User ID.']);
            }
        } elseif ($_POST['action'] === 'bulk_logout') {
            $ids = json_decode($_POST['user_ids'] ?? '[]');
            if (is_array($ids) && count($ids) > 0) {
                // Create placeholders for IN clause
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $stmt = $conn->prepare("UPDATE users SET last_login = NULL WHERE id IN ($placeholders)");
                $stmt->execute($ids);
                echo json_encode(['success' => true, 'message' => 'Action completed successfully.']);
            } else {
                echo json_encode(['success' => false, 'message' => 'No users selected.']);
            }
        }
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database Error: ' . $e->getMessage()]);
    }
    exit();
}

// --- 3. HELPER FUNCTIONS ---
function timeAgo($timestamp) {
    if (empty($timestamp)) return 'Never';
    try {
        $tz = new DateTimeZone('Africa/Lagos');
        $dt = new DateTime($timestamp, $tz);
        $now = new DateTime('now', $tz);
        $diff = $now->getTimestamp() - $dt->getTimestamp();
        
        if ($diff < 60) return 'Just now';
        if ($diff < 3600) return floor($diff / 60) . 'm ago';
        if ($diff < 86400) return floor($diff / 3600) . 'h ago';
        return $dt->format('M j');
    } catch (Exception $e) { return 'Unknown'; }
}

function getUserRealStatus($last_login) {
    if (empty($last_login)) return 'never';
    $diff = time() - strtotime($last_login);
    if ($diff < 900) return 'online'; // 15 mins
    if ($diff < 3600) return 'recent'; // 60 mins
    return 'offline';
}

// FIXED: getAvatarColor function with guaranteed positive index
function getAvatarColor($name) {
    $colors = ['#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#ec4899', '#06b6d4'];
    $color_count = count($colors);
    
    // Handle empty or invalid names
    if (empty($name) || !is_string($name)) {
        return $colors[0]; // Return first color as default
    }
    
    // Generate hash using crc32 for consistent positive integers
    $hash = crc32($name);
    
    // Ensure positive index using modulo
    $index = abs($hash) % $color_count;
    
    // Double-check index is valid
    if ($index < 0 || $index >= $color_count) {
        $index = 0;
    }
    
    return $colors[$index];
}

// --- 4. AJAX HANDLER ---
if (isset($_GET['ajax_fetch'])) {
    header('Content-Type: application/json');
    
    // Calculate Stats via SQL
    try {
        // Online: < 15 mins, Recent: < 60 mins, Offline: > 60 mins or NULL
        $statsQuery = "SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN last_login >= DATE_SUB(NOW(), INTERVAL 15 MINUTE) THEN 1 ELSE 0 END) as online,
            SUM(CASE WHEN last_login >= DATE_SUB(NOW(), INTERVAL 60 MINUTE) AND last_login < DATE_SUB(NOW(), INTERVAL 15 MINUTE) THEN 1 ELSE 0 END) as recent,
            SUM(CASE WHEN last_login < DATE_SUB(NOW(), INTERVAL 60 MINUTE) OR last_login IS NULL THEN 1 ELSE 0 END) as offline
            FROM users";
        $statsStmt = $conn->query($statsQuery);
        $dbStats = $statsStmt->fetch();
        $stats = [
            'total' => (int)($dbStats['total'] ?? 0),
            'online' => (int)($dbStats['online'] ?? 0),
            'recent' => (int)($dbStats['recent'] ?? 0),
            'offline' => (int)($dbStats['offline'] ?? 0)
        ];
    } catch (PDOException $e) {
        $stats = ['total' => 0, 'online' => 0, 'recent' => 0, 'offline' => 0];
    }

    // Build Query with Filters
    $search = $_GET['search'] ?? '';
    $role_filter = $_GET['role'] ?? '';
    $status_filter = $_GET['status'] ?? '';
    $sort_by = $_GET['sort'] ?? 'last_login';
    $sort_dir = $_GET['dir'] ?? 'desc';
    
    // Whitelist sort columns for security
    $allowed_sorts = ['full_name', 'username', 'role', 'last_login'];
    if (!in_array($sort_by, $allowed_sorts)) {
        if ($sort_by === 'name') $sort_by = 'full_name'; // map frontend 'name' to DB 'full_name'
        elseif ($sort_by === 'status') $sort_by = 'last_login'; // approximate status sort by login time
        else $sort_by = 'last_login';
    }
    $sort_dir = ($sort_dir === 'asc') ? 'ASC' : 'DESC';

    $sql = "SELECT * FROM users WHERE 1=1";
    $params = [];

    if ($search) {
        $sql .= " AND (full_name LIKE ? OR username LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    if ($role_filter) {
        $sql .= " AND role = ?";
        $params[] = $role_filter;
    }

    if ($status_filter) {
        if ($status_filter === 'online') {
            $sql .= " AND last_login >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)";
        } elseif ($status_filter === 'recent') {
            $sql .= " AND last_login >= DATE_SUB(NOW(), INTERVAL 60 MINUTE) AND last_login < DATE_SUB(NOW(), INTERVAL 15 MINUTE)";
        } elseif ($status_filter === 'offline') {
            $sql .= " AND (last_login < DATE_SUB(NOW(), INTERVAL 60 MINUTE) OR last_login IS NULL)";
        }
    }

    // Count Total Filtered Results
    $countSql = str_replace("SELECT *", "SELECT COUNT(*)", $sql);
    $stmt = $conn->prepare($countSql);
    $stmt->execute($params);
    $total_filtered = $stmt->fetchColumn();

    // Export All Check
    if (isset($_GET['export_all'])) {
        $sql .= " ORDER BY $sort_by $sort_dir";
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $users = $stmt->fetchAll();
        echo json_encode(['data' => $users]);
        exit();
    }

    // Pagination
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $limit = 20;
    $offset = ($page - 1) * $limit;
    
    $sql .= " ORDER BY $sort_by $sort_dir LIMIT $limit OFFSET $offset";
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $paginated_users = $stmt->fetchAll();
    
    $has_more = ($offset + $limit) < $total_filtered;

    // Render HTML
    ob_start();
    foreach ($paginated_users as $user) {
        $id = $user['id'] ?? 0;
        $name = $user['full_name'] ?? $user['username'] ?? 'Unknown';
        $uname = $user['username'] ?? '';
        $role = strtolower($user['role'] ?? 'client');
        $last_login = $user['last_login'] ?? '';
        $pic = $user['profile_pic'] ?? '';
        $status = getUserRealStatus($last_login);
        
        // Map database roles to CSS classes
        $role_colors = [
            'admin' => 'role-admin', 
            'tm' => 'role-manager', 
            'am' => 'role-manager', 
            'bm' => 'role-manager', 
            'zm' => 'role-manager', 
            'dzm' => 'role-manager', 
            'co' => 'role-officer', 
            'client' => 'role-user'
        ];
        $r_class = $role_colors[$role] ?? 'role-user';
        
        $status_html = match($status) {
            'online' => '<span class="status-dot online pulse"></span> Online',
            'recent' => '<span class="status-dot away"></span> Away',
            'offline' => '<span class="status-dot offline"></span> Offline',
            default => '<span class="status-dot offline"></span> Never'
        };

        $login_ts = !empty($last_login) ? strtotime($last_login) * 1000 : 0;
        
        // FIXED: Ensure name is valid before passing to getAvatarColor
        $safe_name = is_string($name) ? $name : 'Unknown';
        $avatar_color = getAvatarColor($safe_name);
        $initial = !empty($safe_name) ? strtoupper(substr($safe_name, 0, 1)) : '?';
        
        $user_pic_path = (!empty($pic) && $pic !== 'default_avatar.png' && file_exists($base_path . 'uploads/' . $pic)) 
                         ? $base_path . 'uploads/' . $pic 
                         : '';
        ?>
        <tr class="user-row">
            <td class="check-cell"><input type="checkbox" class="row-checkbox" value="<?php echo $id; ?>" onclick="updateBulkState()"></td>
            <td>
                <div class="user-cell">
                    <?php if ($user_pic_path): ?>
                        <img src="<?php echo htmlspecialchars($user_pic_path); ?>" class="avatar" alt="User">
                    <?php else: ?>
                        <div class="avatar-initials" style="background: <?php echo htmlspecialchars($avatar_color); ?>"><?php echo htmlspecialchars($initial); ?></div>
                    <?php endif; ?>
                    <div class="user-meta">
                        <div class="name"><?php echo htmlspecialchars($name); ?></div>
                        <div class="handle">@<?php echo htmlspecialchars($uname); ?></div>
                    </div>
                </div>
            </td>
            <td><span class="role-badge <?php echo $r_class; ?>"><?php echo strtoupper($role); ?></span></td>
            <td>
                <?php if($status === 'online'): ?>
                    <div class="timer-active" data-start="<?php echo $login_ts; ?>">...</div>
                    <div class="timer-sub">Active now</div>
                <?php else: ?>
                    <div class="timer-text"><?php echo timeAgo($last_login); ?></div>
                    <div class="timer-sub"><?php echo !empty($last_login) ? date('g:i A', strtotime($last_login)) : '-'; ?></div>
                <?php endif; ?>
            </td>
            <td><div class="status-cell"><?php echo $status_html; ?></div></td>
            <td class="text-end">
                <button class="action-btn" onclick="window.location.href='manage_users.php?edit=<?php echo $id; ?>'"><i class="fas fa-pen"></i></button>
                <?php if($status !== 'never'): ?>
                <button class="action-btn danger" onclick="confirmLogout('<?php echo $id; ?>', '<?php echo htmlspecialchars($name); ?>')"><i class="fas fa-sign-out-alt"></i></button>
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }
    $html = ob_get_clean();

    echo json_encode([
        'stats' => $stats,
        'table_html' => $html,
        'has_more' => $has_more,
        'total_results' => $total_filtered
    ]);
    exit();
}

// Initial Stats for Page Load (Fallback if JS fails)
try {
    $initStats = $conn->query("SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN last_login >= DATE_SUB(NOW(), INTERVAL 15 MINUTE) THEN 1 ELSE 0 END) as online,
        SUM(CASE WHEN last_login >= DATE_SUB(NOW(), INTERVAL 60 MINUTE) AND last_login < DATE_SUB(NOW(), INTERVAL 15 MINUTE) THEN 1 ELSE 0 END) as recent,
        SUM(CASE WHEN last_login < DATE_SUB(NOW(), INTERVAL 60 MINUTE) OR last_login IS NULL THEN 1 ELSE 0 END) as offline
        FROM users")->fetch();
    $current_stats = [
        'total' => $initStats['total'] ?? 0,
        'online' => $initStats['online'] ?? 0,
        'recent' => $initStats['recent'] ?? 0,
        'offline' => $initStats['offline'] ?? 0
    ];
} catch (Exception $e) {
    $current_stats = ['total' => 0, 'online' => 0, 'recent' => 0, 'offline' => 0];
}
?>
<!DOCTYPE html>
<html lang="en" class="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Active Users - CUPAD</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400 ;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css " />
    <link rel="icon" type="image/png" href="<?php echo htmlspecialchars($base_path . 'uploads/CUPAD LOGO.png'); ?>">
    
    <style>
        :root {
            --primary: #2563eb; 
            --bg-body: #f8fafc; 
            --bg-card: #ffffff;
            --text-main: #0f172a; 
            --text-muted: #64748b;
            --border: #e2e8f0; 
            --nav-height: 70px;
            --scrollbar-bg: transparent;
            --scrollbar-thumb: #cbd5e1;
            --scrollbar-thumb-hover: #94a3b8;
        }

        html.dark {
            --bg-body: #0f172a; 
            --bg-card: #1e293b;
            --text-main: #f8fafc; 
            --text-muted: #94a3b8;
            --border: #334155;
            --primary: #3b82f6; 
            --scrollbar-thumb: #475569;
            --scrollbar-thumb-hover: #64748b;
        }

        * { box-sizing: border-box; outline: none; }
        body { font-family: 'Plus Jakarta Sans', sans-serif; background: var(--bg-body); color: var(--text-main); margin: 0; padding-top: var(--nav-height); font-size: 0.9rem; transition: background 0.3s, color 0.3s; }
        a { text-decoration: none; color: inherit; }

        /* Tiny Scrollbar Styles */
        ::-webkit-scrollbar {
            width: 4px;
            height: 4px;
        }
        
        ::-webkit-scrollbar-track {
            background: var(--scrollbar-bg);
            border-radius: 2px;
        }
        
        ::-webkit-scrollbar-thumb {
            background: var(--scrollbar-thumb);
            border-radius: 2px;
            transition: background 0.2s;
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: var(--scrollbar-thumb-hover);
        }
        
        /* Firefox */
        * {
            scrollbar-width: thin;
            scrollbar-color: var(--scrollbar-thumb) var(--scrollbar-bg);
        }

        /* HEADER (Synced with Dashboard) */
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
        .user-pill { display: flex; align-items: center; gap: 0.75rem; padding: 4px 8px 4px 4px; border: 1px solid var(--border); border-radius: 99px; background: var(--bg-card); cursor: pointer; transition: 0.2s; }
        .user-pill:hover { border-color: var(--primary); }
        .user-avatar-head { width: 34px; height: 34px; border-radius: 50%; object-fit: cover; }
        .user-avatar-fall { width: 34px; height: 34px; border-radius: 50%; background: var(--bg-body); color: var(--text-muted); display: flex; align-items: center; justify-content: center; font-size: 1rem; border: 1px solid var(--border); }
        .user-info { display: flex; flex-direction: column; line-height: 1.1; padding-right: 0.5rem; }
        .user-name { font-weight: 600; font-size: 0.85rem; }
        .user-role { font-size: 0.7rem; color: var(--text-muted); text-transform: uppercase; }
        .dropdown-menu { position: absolute; top: 120%; right: 0; background: var(--bg-card); border: 1px solid var(--border); border-radius: 12px; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); min-width: 200px; display: none; z-index: 1000; flex-direction: column; overflow: hidden; }
        .dropdown-menu.show { display: flex; }
        .dropdown-item { padding: 0.75rem 1rem; display: flex; align-items: center; gap: 0.75rem; font-size: 0.9rem; transition: 0.2s; }
        .dropdown-item:hover { background: var(--bg-body); color: var(--primary); }
        .text-danger { color: #dc2626 !important; }

        /* CONTENT */
        .container { max-width: 1400px; margin: 0 auto; padding: 2rem 1.5rem; }
        
        /* Stats Grid */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem; margin-bottom: 2rem; }
        .stat-card { background: var(--bg-card); padding: 1.25rem; border-radius: 12px; border: 1px solid var(--border); display: flex; align-items: center; gap: 1rem; transition: 0.2s; }
        .stat-card:hover { transform: translateY(-2px); border-color: var(--primary); box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); }
        .stat-icon { width: 48px; height: 48px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; flex-shrink: 0; }
        .stat-info { display: flex; flex-direction: column; }
        .stat-value { font-size: 1.5rem; font-weight: 700; line-height: 1.2; }
        .stat-label { font-size: 0.8rem; color: var(--text-muted); }
        .bg-blue { background: #dbeafe; color: #2563eb; } .bg-green { background: #d1fae5; color: #059669; }
        .bg-orange { background: #ffedd5; color: #ea580c; } .bg-rose { background: #ffe4e6; color: #e11d48; }
        html.dark .bg-blue { background: rgba(59,130,246,0.15); color: #60a5fa; }
        html.dark .bg-green { background: rgba(16,185,129,0.15); color: #34d399; }
        html.dark .bg-orange { background: rgba(245,158,11,0.15); color: #fbbf24; }
        html.dark .bg-rose { background: rgba(244,63,94,0.15); color: #fb7185; }

        /* Main Card */
        .card { background: var(--bg-card); border: 1px solid var(--border); border-radius: 12px; display: flex; flex-direction: column; height: 75vh; box-shadow: 0 1px 3px rgba(0,0,0,0.1); overflow: hidden; }
        
        /* Filter Bar */
        .filter-bar { padding: 1rem 1.5rem; border-bottom: 1px solid var(--border); display: flex; flex-wrap: wrap; gap: 1rem; align-items: center; justify-content: space-between; }
        .filter-group { display: flex; gap: 0.75rem; flex: 1; align-items: center; flex-wrap: wrap; }
        .search-input { padding: 0.5rem 1rem 0.5rem 2.2rem; border-radius: 8px; border: 1px solid var(--border); background: var(--bg-body); color: var(--text-main); font-size: 0.9rem; min-width: 240px; }
        .search-wrap { position: relative; }
        .search-wrap i { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--text-muted); font-size: 0.8rem; }
        .select-input { padding: 0.5rem 2rem 0.5rem 1rem; border-radius: 8px; border: 1px solid var(--border); background: var(--bg-body); color: var(--text-main); font-size: 0.9rem; cursor: pointer; appearance: none; }
        
        /* Table Styles (Clean & Simple) */
        .table-wrap { overflow: auto; flex: 1; }
        table { width: 100%; border-collapse: separate; border-spacing: 0; min-width: 800px; }
        th { position: sticky; top: 0; background: var(--bg-card); z-index: 10; padding: 1rem 1.5rem; text-align: left; font-size: 0.75rem; text-transform: uppercase; font-weight: 600; color: var(--text-muted); border-bottom: 1px solid var(--border); cursor: pointer; user-select: none; }
        th:hover { color: var(--primary); }
        td { padding: 1rem 1.5rem; border-bottom: 1px solid var(--border); vertical-align: middle; }
        tr { transition: background 0.2s; }
        tr:hover { background: var(--bg-body); }
        
        .user-cell { display: flex; align-items: center; gap: 1rem; }
        .avatar, .avatar-initials { width: 40px; height: 40px; border-radius: 50%; object-fit: cover; }
        .avatar-initials { display: flex; align-items: center; justify-content: center; color: white; font-weight: 700; font-size: 1rem; text-shadow: 0 1px 2px rgba(0,0,0,0.1); }
        .user-meta .name { font-weight: 600; color: var(--text-main); }
        .user-meta .handle { font-size: 0.8rem; color: var(--text-muted); }

        .role-badge { padding: 4px 10px; border-radius: 6px; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }
        .role-admin { background: #fce7f3; color: #db2777; }
        .role-manager { background: #e0f2fe; color: #0284c7; }
        .role-officer { background: #dcfce7; color: #16a34a; }
        .role-user { background: #ffedd5; color: #ea580c; }
        html.dark .role-admin { background: rgba(219,39,119,0.2); color: #f472b6; }
        html.dark .role-manager { background: rgba(2,132,199,0.2); color: #38bdf8; }
        html.dark .role-officer { background: rgba(22,163,74,0.2); color: #4ade80; }
        html.dark .role-user { background: rgba(234,88,12,0.2); color: #fb923c; }

        .status-cell { display: flex; align-items: center; gap: 6px; font-size: 0.85rem; font-weight: 500; }
        .status-dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; }
        .online { background: #10b981; } .away { background: #f59e0b; } .offline { background: #cbd5e1; }
        .pulse { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); animation: pulse-green 2s infinite; }
        @keyframes pulse-green { 0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); } 70% { transform: scale(1); box-shadow: 0 0 0 6px rgba(16, 185, 129, 0); } 100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); } }

        .timer-active { font-family: monospace; color: var(--success); font-weight: 600; font-size: 0.9rem; }
        .timer-text { font-size: 0.9rem; font-weight: 500; }
        .timer-sub { font-size: 0.75rem; color: var(--text-muted); }

        .action-btn { width: 32px; height: 32px; border-radius: 6px; border: none; background: transparent; color: var(--text-muted); cursor: pointer; transition: 0.2s; display: inline-flex; align-items: center; justify-content: center; }
        .action-btn:hover { background: rgba(0,0,0,0.05); color: var(--primary); }
        .action-btn.danger:hover { background: rgba(220,38,38,0.1); color: #dc2626; }

        .btn { padding: 0.6rem 1.2rem; border-radius: 8px; border: 1px solid var(--border); background: var(--bg-card); color: var(--text-main); font-weight: 600; font-size: 0.85rem; cursor: pointer; display: flex; align-items: center; gap: 0.5rem; transition: 0.2s; }
        .btn:hover { border-color: var(--primary); color: var(--primary); background: var(--bg-body); }
        .btn-danger { background: #dc2626; color: white; border: none; }
        .btn-danger:hover { background: #b91c1c; color: white; border-color: transparent; }

        .bulk-actions { display: none; align-items: center; gap: 1rem; animation: fadeIn 0.2s; }
        .bulk-actions.active { display: flex; }
        .check-cell { width: 40px; text-align: center; }
        .row-checkbox, .header-checkbox { width: 16px; height: 16px; accent-color: var(--primary); cursor: pointer; }

        /* Skeleton */
        .skeleton { background: linear-gradient(90deg, var(--bg-body) 25%, var(--border) 50%, var(--bg-body) 75%); background-size: 200% 100%; animation: shimmer 1.5s infinite; border-radius: 4px; display: inline-block; }
        @keyframes shimmer { 0% { background-position: 200% 0; } 100% { background-position: -200% 0; } }
        @keyframes fadeIn { from{opacity:0} to{opacity:1} }

        /* Modal & Toast */
        .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 100; display: none; align-items: center; justify-content: center; backdrop-filter: blur(2px); }
        .modal-box { background: var(--bg-card); padding: 2rem; border-radius: 12px; width: 90%; max-width: 400px; box-shadow: 0 10px 25px rgba(0,0,0,0.2); }
        .toast { position: fixed; bottom: 20px; right: 20px; background: var(--bg-card); padding: 12px 20px; border-radius: 8px; border-left: 4px solid var(--primary); box-shadow: 0 5px 15px rgba(0,0,0,0.15); display: flex; align-items: center; gap: 10px; transform: translateX(120%); transition: 0.3s; z-index: 200; }
        .toast.show { transform: translateX(0); }
        .toast.success { border-color: #10b981; } .toast.error { border-color: #ef4444; }
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
                <button class="icon-btn" id="themeToggle"><i class="fas fa-moon"></i></button>
                <div class="user-dropdown-wrap">
                    <div class="user-pill" id="userDropdownTrigger">
                        <?php if($has_profile_pic): ?>
                            <img src="<?php echo htmlspecialchars($profile_pic_path); ?>" class="user-avatar-head" alt="User">
                        <?php else: ?>
                            <div class="user-avatar-fall"><i class="fas fa-user"></i></div>
                        <?php endif; ?>
                        <div class="user-info">
                            <span class="user-name"><?php echo htmlspecialchars($full_name); ?></span>
                            <span class="user-role"><?php echo ucfirst(htmlspecialchars($user_role)); ?></span>
                        </div>
                        <i class="fas fa-chevron-down" style="font-size:0.7rem; color:var(--text-muted)"></i>
                    </div>
                    <div class="dropdown-menu" id="userDropdown">
                        <a href="profile.php" class="dropdown-item"><i class="fas fa-user-circle" style="color:var(--primary)"></i> My Profile</a>
                        <div style="height:1px; background:var(--border); margin:0"></div>
                        <a href="../logout.php" class="dropdown-item text-danger"><i class="fas fa-sign-out-alt"></i> Logout</a>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <main class="container">
        
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem">
            <h2 style="font-weight:700; margin:0; font-size:1.5rem">Active Users</h2>
            <a href="dashboard.php" class="btn"><i class="fas fa-arrow-left"></i> Dashboard</a>
        </div>

        <!-- 1. Stats -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon bg-blue"><i class="fas fa-users"></i></div>
                <div class="stat-info">
                    <div class="stat-value" id="st-total"><?php echo $current_stats['total']; ?></div>
                    <div class="stat-label">Total Users</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon bg-green"><i class="fas fa-wifi"></i></div>
                <div class="stat-info">
                    <div class="stat-value" id="st-online"><?php echo $current_stats['online']; ?></div>
                    <div class="stat-label">Online Now</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon bg-orange"><i class="fas fa-history"></i></div>
                <div class="stat-info">
                    <div class="stat-value" id="st-recent"><?php echo $current_stats['recent']; ?></div>
                    <div class="stat-label">Recently Active</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon bg-rose"><i class="fas fa-user-slash"></i></div>
                <div class="stat-info">
                    <div class="stat-value"><?php echo $current_stats['offline']; ?></div>
                    <div class="stat-label">Offline</div>
                </div>
            </div>
        </div>

        <!-- 2. Main Table Card -->
        <div class="card">
            <div class="filter-bar">
                <div class="filter-group">
                    <div class="search-wrap">
                        <i class="fas fa-search"></i>
                        <input type="text" id="searchInput" class="search-input" placeholder="Search by name...">
                    </div>
                    <select id="roleFilter" class="select-input">
                        <option value="">All Roles</option>
                        <option value="admin">Admin</option>
                        <option value="tm">Team Manager (TM)</option>
                        <option value="am">Area Manager (AM)</option>
                        <option value="bm">Branch Manager (BM)</option>
                        <option value="co">Loan Officer (CO)</option>
                        <option value="client">Client</option>
                    </select>
                    <select id="statusFilter" class="select-input">
                        <option value="">All Statuses</option>
                        <option value="online">Online</option>
                        <option value="recent">Away</option>
                        <option value="offline">Offline</option>
                    </select>
                </div>

                <div class="bulk-actions" id="bulkActions">
                    <span id="selectedCount" style="font-weight:600; font-size:0.9rem; color:var(--text-muted)">0 selected</span>
                    <button class="btn btn-danger" onclick="confirmBulkLogout()"><i class="fas fa-power-off"></i> Logout</button>
                </div>

                <div style="display:flex; gap:0.5rem">
                    <button class="btn" onclick="exportData()"><i class="fas fa-download"></i> Export</button>
                    <button class="icon-btn" onclick="resetAndFetch()"><i class="fas fa-sync-alt"></i></button>
                </div>
            </div>

            <div class="table-wrap" id="scrollContainer">
                <table id="userTable">
                    <thead>
                        <tr>
                            <th class="check-cell"><input type="checkbox" class="header-checkbox" id="selectAll" onclick="toggleSelectAll()"></th>
                            <th onclick="sortBy('name')">User <i class="fas fa-sort sort-icon"></i></th>
                            <th onclick="sortBy('role')">Role <i class="fas fa-sort sort-icon"></i></th>
                            <th onclick="sortBy('last_login')">Activity <i class="fas fa-sort sort-icon"></i></th>
                            <th onclick="sortBy('status')">Status <i class="fas fa-sort sort-icon"></i></th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="tableBody"></tbody>
                </table>
                <div class="empty-state" id="emptyState" style="display:none; text-align:center; padding:3rem; color:var(--text-muted)">
                    <i class="fas fa-search fa-3x mb-3" style="opacity:0.2"></i>
                    <p>No matching users found.</p>
                </div>
            </div>
        </div>
    </main>

    <!-- Confirm Modal -->
    <div class="modal-overlay" id="confirmModal">
        <div class="modal-box">
            <h3 style="margin-top:0; font-size:1.25rem">Confirm Action</h3>
            <p id="modalText" style="color:var(--text-muted)">Are you sure?</p>
            <div class="modal-actions">
                <button class="btn" onclick="closeModal()">Cancel</button>
                <button class="btn btn-danger" id="confirmBtn">Confirm</button>
            </div>
        </div>
    </div>
    
    <div id="toastContainer"></div>

    <script>
        const $ = (id) => document.getElementById(id);
        
        // --- Theme & Dropdown ---
        const themeBtn = $('themeToggle');
        const html = document.documentElement;
        if(localStorage.getItem('theme') === 'dark') { html.classList.add('dark'); themeBtn.innerHTML = '<i class="fas fa-sun"></i>'; }
        else { html.classList.add('light'); themeBtn.innerHTML = '<i class="fas fa-moon"></i>'; }

        themeBtn.addEventListener('click', () => {
            const isDark = html.classList.toggle('dark');
            html.classList.toggle('light');
            localStorage.setItem('theme', isDark ? 'dark' : 'light');
            themeBtn.innerHTML = isDark ? '<i class="fas fa-sun"></i>' : '<i class="fas fa-moon"></i>';
        });

        const userTrigger = $('userDropdownTrigger');
        userTrigger.addEventListener('click', (e) => { e.stopPropagation(); $('userDropdown').classList.toggle('show'); });
        document.addEventListener('click', (e) => { if (!userTrigger.contains(e.target)) $('userDropdown').classList.remove('show'); });

        // --- Data Logic ---
        let page = 1; let isLoading = false; let hasMore = true;
        let currentSort = 'last_login'; let currentDir = 'desc';

        function debounce(func, wait) {
            let timeout;
            return function(...args) { clearTimeout(timeout); timeout = setTimeout(() => func.apply(this, args), wait); };
        }

        function getSkeletonHTML() {
            let html = '';
            for(let i=0; i<8; i++) {
                html += `<tr class="sk-row"><td class="check-cell"><div class="skeleton" style="width:16px;height:16px"></div></td>
                <td><div style="display:flex;gap:1rem;align-items:center"><div class="skeleton sk-circle"></div><div><div class="skeleton sk-line"></div><div class="skeleton sk-line short"></div></div></div></td>
                <td><div class="skeleton sk-badge"></div></td><td><div class="skeleton sk-line"></div></td><td><div class="skeleton sk-badge"></div></td><td></td></tr>`;
            }
            return html;
        }

        async function fetchUsers(isAppend = false) {
            if (isLoading) return;
            isLoading = true;
            if(!isAppend) $('tableBody').innerHTML = getSkeletonHTML();

            const search = $('searchInput').value;
            const role = $('roleFilter').value;
            const status = $('statusFilter').value;

            try {
                const res = await fetch(`?ajax_fetch=1&page=${page}&search=${search}&role=${role}&status=${status}&sort=${currentSort}&dir=${currentDir}`);
                const data = await res.json();
                
                if (!isAppend) {
                    $('tableBody').innerHTML = data.table_html;
                    $('st-total').innerText = data.stats.total;
                    $('st-online').innerText = data.stats.online;
                    $('st-recent').innerText = data.stats.recent;
                } else {
                    $('tableBody').insertAdjacentHTML('beforeend', data.table_html);
                }
                $('emptyState').style.display = (data.total_results === 0) ? 'block' : 'none';
                hasMore = data.has_more;
                page++;
            } catch(e) { console.error(e); } finally { isLoading = false; }
        }

        function resetAndFetch() { page = 1; hasMore = true; fetchUsers(false); $('selectAll').checked = false; updateBulkState(); }

        function sortBy(column) {
            if (currentSort === column) currentDir = currentDir === 'asc' ? 'desc' : 'asc';
            else { currentSort = column; currentDir = 'asc'; }
            resetAndFetch();
        }

        const scrollContainer = $('scrollContainer');
        scrollContainer.addEventListener('scroll', () => {
            if (scrollContainer.scrollTop + scrollContainer.clientHeight >= scrollContainer.scrollHeight - 50 && hasMore) fetchUsers(true);
        });

        ['searchInput', 'roleFilter', 'statusFilter'].forEach(id => {
            $(id).addEventListener('input', debounce(resetAndFetch, 300));
        });

        function toggleSelectAll() {
            const master = $('selectAll').checked;
            document.querySelectorAll('.row-checkbox').forEach(cb => cb.checked = master);
            updateBulkState();
        }
        function updateBulkState() {
            const count = document.querySelectorAll('.row-checkbox:checked').length;
            $('bulkActions').className = count > 0 ? 'bulk-actions active' : 'bulk-actions';
            $('selectedCount').innerText = `${count} selected`;
        }

        let actionType = ''; let targetId = null;
        function confirmLogout(id, name) { actionType = 'force_logout'; targetId = id; $('modalText').innerText = `Logout ${name}?`; $('confirmModal').style.display = 'flex'; }
        function confirmBulkLogout() {
            const count = document.querySelectorAll('.row-checkbox:checked').length;
            if(count===0) return;
            actionType = 'bulk_logout'; $('modalText').innerText = `Logout ${count} users?`; $('confirmModal').style.display = 'flex';
        }
        function closeModal() { $('confirmModal').style.display = 'none'; }
        
        $('confirmBtn').addEventListener('click', async () => {
            const btn = $('confirmBtn'); btn.innerText = 'Processing...'; btn.disabled = true;
            const formData = new FormData();
            formData.append('action', actionType);
            if(actionType === 'force_logout') formData.append('user_id', targetId);
            else {
                const ids = Array.from(document.querySelectorAll('.row-checkbox:checked')).map(cb => cb.value);
                formData.append('user_ids', JSON.stringify(ids));
            }
            try {
                const res = await fetch('', {method:'POST', body:formData});
                const data = await res.json();
                if(data.success) { showToast(data.message, 'success'); resetAndFetch(); } 
                else showToast(data.message, 'error');
            } catch(e) { showToast('Connection Error', 'error'); }
            closeModal(); btn.innerText = 'Confirm'; btn.disabled = false;
        });

        function updateTimers() {
            const now = Date.now();
            document.querySelectorAll('.timer-active').forEach(el => {
                const start = parseInt(el.dataset.start);
                if (!start) return;
                const diff = Math.floor((now - start) / 1000);
                const h = Math.floor(diff / 3600);
                const m = Math.floor((diff % 3600) / 60);
                const s = diff % 60;
                el.innerText = `${h.toString().padStart(2,'0')}:${m.toString().padStart(2,'0')}:${s.toString().padStart(2,'0')}`;
            });
        }
        setInterval(updateTimers, 1000);

        function showToast(msg, type='info') {
            const box = document.createElement('div');
            box.className = `toast ${type}`;
            box.innerHTML = `<i class="fas fa-${type==='success'?'check-circle':'exclamation-circle'}"></i> ${msg}`;
            $('toastContainer').appendChild(box);
            setTimeout(() => box.classList.add('show'), 10);
            setTimeout(() => { box.classList.remove('show'); setTimeout(() => box.remove(), 300); }, 3000);
        }

        async function exportData() {
            const btn = event.currentTarget;
            const oldHtml = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i> Exporting...';
            btn.disabled = true;

            const search = $('searchInput').value;
            const role = $('roleFilter').value;
            const status = $('statusFilter').value;

            try {
                const res = await fetch(`?ajax_fetch=1&export_all=1&search=${search}&role=${role}&status=${status}&sort=${currentSort}&dir=${currentDir}`);
                const response = await res.json();
                const users = response.data;
                if (!users || users.length === 0) { showToast('No data to export', 'error'); return; }
                
                let csvContent = "data:text/csv;charset=utf-8,ID,Full Name,Username,Role,Last Login,Status\n";
                users.forEach(u => {
                    let statusStr = 'Never';
                    if(u.last_login) {
                        const diff = (Date.now()/1000 - new Date(u.last_login).getTime()/1000);
                        if(diff < 900) statusStr = 'Online';
                        else if(diff < 3600) statusStr = 'Away';
                        else statusStr = 'Offline';
                    }
                    csvContent += [u.id||'', `"${(u.full_name||'').replace(/"/g, '""')}"`, u.username||'', u.role||'', u.last_login||'', statusStr].join(",") + "\n";
                });
                const encodedUri = encodeURI(csvContent);
                const link = document.createElement("a");
                link.setAttribute("href", encodedUri);
                link.setAttribute("download", "active_users_export.csv");
                document.body.appendChild(link); link.click(); document.body.removeChild(link);
            } catch(e) { showToast('Export failed', 'error'); } 
            finally { btn.innerHTML = oldHtml; btn.disabled = false; }
        }

        resetAndFetch();
    </script>
</body>
</html>