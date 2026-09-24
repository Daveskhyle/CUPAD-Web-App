<?php
date_default_timezone_set('Africa/Lagos');
session_start();
require_once '../includes/config.php';

// Auth Check
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: ../index.php');
    exit();
}

$conn = getDbConnection();
$base_path = '../';

// Fetch users with location and branch info
$users = [];
$total_with_location = 0;
$total_online_with_location = 0;
$branches_list = [];
$roles_list = [];

try {
    $stmt = $conn->query("
        SELECT 
            u.id, u.username, u.full_name, u.role, u.is_online, u.last_login, u.profile_pic,
            u.last_latitude, u.last_longitude,
            COALESCE(b.name, 'Unassigned Branch') as branch_name
        FROM users u
        LEFT JOIN branches b ON u.branch_id = b.id
        ORDER BY u.is_online DESC, u.last_login DESC
    ");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($users as &$u) {
        $has_valid_coords = !empty($u['last_latitude']) && !empty($u['last_longitude']) && is_numeric($u['last_latitude']) && is_numeric($u['last_longitude']);
        
        $u['has_location'] = $has_valid_coords;
        if ($has_valid_coords) {
            $u['last_latitude'] = (float)$u['last_latitude'];
            $u['last_longitude'] = (float)$u['last_longitude'];
            $total_with_location++;
            if ($u['is_online']) $total_online_with_location++;
        }

        if (!empty($u['branch_name']) && !in_array($u['branch_name'], $branches_list)) {
            $branches_list[] = $u['branch_name'];
        }
        if (!empty($u['role']) && !in_array($u['role'], $roles_list)) {
            $roles_list[] = $u['role'];
        }

        // Profile Picture Resolution
        $pic_filename = $u['profile_pic'] ?? 'default_avatar.png';
        if (strpos($pic_filename, 'uploads/') === 0) {
            $pic_path = $base_path . $pic_filename;
        } else {
            $pic_path = $base_path . 'uploads/' . $pic_filename;
        }

        $pic_exists = file_exists($pic_path) && !empty($u['profile_pic']) && strpos($pic_filename, 'default') === false;

        $u['profile_pic_url'] = $pic_path;
        $u['has_valid_pic'] = $pic_exists;
        $u['display_name'] = !empty($u['full_name']) ? $u['full_name'] : $u['username'];
    }
    unset($u); // Break reference
} catch (PDOException $e) {
    error_log("Fetch locations error: " . $e->getMessage());
}

// Relative time helper
function timeAgo($datetime) {
    if (!$datetime) return 'Never active';
    $time = strtotime($datetime);
    $diff = time() - $time;
    
    if ($diff < 45) return 'Just now';
    if ($diff < 3600) return floor($diff / 60) . ' mins ago';
    if ($diff < 86400) return floor($diff / 3600) . ' hrs ago';
    if ($diff < 172800) return 'Yesterday';
    if ($diff < 604800) return floor($diff / 86400) . ' days ago';
    return date('M j, Y', $time);
}

$full_name = $_SESSION['full_name'] ?? 'Administrator';
?>
<!DOCTYPE html>
<html lang="en" class="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GPS Telemetry & Fleet Tracker - CUPAD Admin</title>
    <link rel="icon" type="image/png" href="<?php echo htmlspecialchars($base_path . 'uploads/CUPAD LOGO.png'); ?>">
    
    <!-- Fonts & FontAwesome -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />
    
    <!-- Leaflet & MarkerCluster CSS -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.css" />
    <link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.Default.css" />

    <style>
        :root {
            --primary: #3b82f6;
            --primary-rgb: 59, 130, 246;
            --primary-hover: #2563eb;
            --primary-soft: rgba(59, 130, 246, 0.12);
            --success: #10b981;
            --success-soft: rgba(16, 185, 129, 0.12);
            --warning: #f59e0b;
            --warning-soft: rgba(245, 158, 11, 0.12);
            --danger: #ef4444;
            --danger-soft: rgba(239, 68, 68, 0.12);
            --purple: #8b5cf6;
            --purple-soft: rgba(139, 92, 246, 0.12);
            
            --bg-body: #f8fafc;
            --bg-surface: #ffffff;
            --bg-surface-hover: #f1f5f9;
            --bg-glass: rgba(255, 255, 255, 0.85);
            --text-main: #0f172a;
            --text-muted: #64748b;
            --border: #e2e8f0;
            --border-light: #f1f5f9;
            
            --radius-2xl: 24px;
            --radius-xl: 18px;
            --radius-lg: 14px;
            --radius-md: 10px;
            --radius-sm: 6px;
            
            --shadow-xs: 0 1px 2px rgba(0,0,0,0.04);
            --shadow-sm: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
            --shadow-md: 0 10px 25px -5px rgba(15, 23, 42, 0.08);
            --shadow-xl: 0 25px 50px -12px rgba(15, 23, 42, 0.18);
            
            --nav-height: 72px;
            --transition: all 0.22s cubic-bezier(0.4, 0, 0.2, 1);
        }

        html.dark {
            --primary: #60a5fa;
            --primary-rgb: 96, 165, 250;
            --primary-hover: #3b82f6;
            --primary-soft: rgba(96, 165, 250, 0.18);
            --bg-body: #0b0f19;
            --bg-surface: #111827;
            --bg-surface-hover: #1e293b;
            --bg-glass: rgba(17, 24, 39, 0.85);
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
            --border: #1f293d;
            --border-light: #182234;
            --shadow-md: 0 10px 25px -5px rgba(0, 0, 0, 0.5);
            --shadow-xl: 0 25px 50px -12px rgba(0, 0, 0, 0.7);
        }

        * { box-sizing: border-box; outline: none; margin: 0; padding: 0; }
        body { 
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif; 
            background: var(--bg-body); 
            color: var(--text-main); 
            padding-top: var(--nav-height); 
            transition: background-color 0.3s ease, color 0.3s ease; 
            -webkit-font-smoothing: antialiased;
            min-height: 100vh;
        }
        a { text-decoration: none; color: inherit; }

        /* Navbar Header */
        .main-header { 
            position: fixed; top: 0; left: 0; right: 0; 
            height: var(--nav-height); 
            background: var(--bg-glass); 
            backdrop-filter: blur(20px); 
            -webkit-backdrop-filter: blur(20px);
            border-bottom: 1px solid var(--border); 
            z-index: 1000; 
            transition: var(--transition);
        }
        .navbar { 
            max-width: 1440px; margin: 0 auto; padding: 0 1.5rem; 
            display: flex; align-items: center; justify-content: space-between; height: 100%; 
        }
        .logo { display: flex; align-items: center; gap: 0.75rem; font-weight: 800; font-size: 1.25rem; color: var(--primary); }
        .logo img { height: 38px; width: auto; border-radius: 6px; }

        /* Container */
        .container { max-width: 1440px; margin: 0 auto; padding: 2rem 1.5rem 5rem; }
        
        .page-header { 
            display: flex; justify-content: space-between; align-items: flex-start; 
            margin-bottom: 1.75rem; flex-wrap: wrap; gap: 1rem; 
        }
        .page-title-wrap h1 { font-size: 1.75rem; font-weight: 800; display: flex; align-items: center; gap: 0.75rem; letter-spacing: -0.02em; }
        .page-title-wrap p { margin-top: 0.35rem; color: var(--text-muted); font-size: 0.9rem; }

        /* Status Banner / Refresh Indicator */
        .sync-badge {
            display: inline-flex; align-items: center; gap: 0.45rem; background: var(--bg-surface);
            border: 1px solid var(--border); padding: 0.4rem 0.85rem; border-radius: 99px; font-size: 0.8rem;
            font-weight: 600; color: var(--text-muted);
        }
        .live-dot {
            width: 8px; height: 8px; border-radius: 50%; background: var(--success);
            box-shadow: 0 0 0 3px var(--success-soft); animation: livePulse 2s infinite ease-in-out;
        }
        @keyframes livePulse { 0% { transform: scale(0.95); opacity: 0.8; } 50% { transform: scale(1.35); opacity: 1; } 100% { transform: scale(0.95); opacity: 0.8; } }

        /* Buttons */
        .btn {
            padding: 0.6rem 1.2rem; border-radius: var(--radius-md); font-size: 0.85rem; font-weight: 700;
            display: inline-flex; align-items: center; gap: 0.5rem; cursor: pointer; border: 1px solid transparent;
            transition: var(--transition); background: transparent; color: var(--text-main); font-family: inherit;
        }
        .btn:active { transform: scale(0.97); }
        .btn-outline { background: var(--bg-surface); border-color: var(--border); color: var(--text-main); }
        .btn-outline:hover { background: var(--bg-surface-hover); border-color: var(--primary); color: var(--primary); }
        .btn-primary { background: var(--primary); color: #fff; box-shadow: 0 4px 14px -2px rgba(var(--primary-rgb), 0.4); }
        .btn-primary:hover { background: var(--primary-hover); transform: translateY(-1px); }
        .btn-sm { padding: 0.4rem 0.75rem; font-size: 0.78rem; border-radius: var(--radius-sm); }
        .icon-btn { width: 40px; height: 40px; padding: 0; justify-content: center; border-radius: var(--radius-md); }

        /* Metric Highlights */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1.25rem; margin-bottom: 2rem; }
        .stat-card { 
            background: var(--bg-surface); border: 1px solid var(--border); padding: 1.35rem 1.5rem; 
            border-radius: var(--radius-xl); box-shadow: var(--shadow-sm); 
            display: flex; align-items: center; gap: 1.25rem; transition: var(--transition);
            position: relative; overflow: hidden;
        }
        .stat-card::before {
            content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px;
            background: transparent; transition: var(--transition);
        }
        .stat-card:hover { transform: translateY(-3px); box-shadow: var(--shadow-md); }
        .stat-card.blue:hover::before { background: var(--primary); }
        .stat-card.green:hover::before { background: var(--success); }
        .stat-card.orange:hover::before { background: var(--warning); }
        .stat-card.rose:hover::before { background: var(--danger); }

        .stat-icon { 
            width: 54px; height: 54px; border-radius: 16px; display: flex; align-items: center; justify-content: center; 
            font-size: 1.45rem; flex-shrink: 0;
        }
        .stat-info h3 { font-size: 1.85rem; font-weight: 800; line-height: 1; letter-spacing: -0.02em; }
        .stat-info p { margin-top: 5px; font-size: 0.8rem; color: var(--text-muted); font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; }

        .bg-blue { background: var(--primary-soft); color: var(--primary); }
        .bg-green { background: var(--success-soft); color: var(--success); }
        .bg-orange { background: var(--warning-soft); color: var(--warning); }
        .bg-rose { background: var(--danger-soft); color: var(--danger); }

        /* Quick Filter Chips */
        .chip-container { display: flex; gap: 0.5rem; flex-wrap: wrap; margin-bottom: 1.5rem; }
        .chip {
            padding: 0.5rem 1.1rem; border-radius: 99px; background: var(--bg-surface); border: 1px solid var(--border);
            font-size: 0.82rem; font-weight: 700; color: var(--text-muted); cursor: pointer; transition: var(--transition);
            display: inline-flex; align-items: center; gap: 0.6rem; user-select: none;
        }
        .chip:hover { background: var(--bg-surface-hover); color: var(--text-main); }
        .chip.active { background: var(--primary-soft); border-color: var(--primary); color: var(--primary); }
        .chip-count { background: rgba(0,0,0,0.06); padding: 2px 8px; border-radius: 99px; font-size: 0.72rem; }
        html.dark .chip-count { background: rgba(255,255,255,0.12); }

        /* Interactive Map Section */
        .map-card { 
            background: var(--bg-surface); border: 1px solid var(--border); 
            border-radius: var(--radius-2xl); padding: 1.25rem; margin-bottom: 2rem; 
            box-shadow: var(--shadow-md); transition: var(--transition); position: relative;
        }
        .map-toolbar { 
            display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; 
            flex-wrap: wrap; gap: 0.75rem; 
        }
        .map-controls-group { display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: center; }

        #userMap { 
            width: 100%; height: 500px; border-radius: var(--radius-xl); border: 1px solid var(--border); 
            z-index: 1; transition: height 0.3s ease;
        }

        /* Fullscreen Map Mode */
        .map-card.fullscreen {
            position: fixed !important; top: 0 !important; left: 0 !important; right: 0 !important; bottom: 0 !important;
            width: 100vw !important; height: 100vh !important; z-index: 99990 !important;
            border-radius: 0 !important; margin: 0 !important; padding: 1.25rem !important;
            display: flex; flex-direction: column; background: var(--bg-surface);
        }
        .map-card.fullscreen #userMap { flex: 1; height: 100% !important; border-radius: var(--radius-lg); }

        /* Custom Leaflet Markers */
        .map-pin-container { position: relative; width: 44px; height: 44px; cursor: pointer; }
        .map-pin-avatar {
            width: 42px; height: 42px; border-radius: 50%; border: 3px solid #fff;
            box-shadow: 0 4px 14px rgba(0,0,0,0.35); background-size: cover; background-position: center;
            display: flex; align-items: center; justify-content: center; font-weight: 800; color: white; font-size: 0.85rem;
            background: linear-gradient(135deg, var(--primary), #4f46e5); overflow: hidden;
            transition: transform 0.2s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }
        .map-pin-container:hover .map-pin-avatar { transform: scale(1.15); border-color: var(--primary); }
        .map-pin-pulse {
            position: absolute; top: -1px; right: -1px; width: 13px; height: 13px;
            background: var(--success); border: 2px solid white; border-radius: 50%; z-index: 2;
        }
        .map-pin-pulse::after {
            content: ''; position: absolute; width: 100%; height: 100%; top: 0; left: 0;
            border-radius: 50%; background: var(--success); animation: pulseMarker 1.8s infinite ease-in-out;
        }
        @keyframes pulseMarker { 0% { transform: scale(1); opacity: 0.8; } 100% { transform: scale(2.8); opacity: 0; } }

        /* Marker Cluster Customization */
        .marker-cluster-small, .marker-cluster-medium, .marker-cluster-large {
            background-color: rgba(var(--primary-rgb), 0.3) !important;
        }
        .marker-cluster-small div, .marker-cluster-medium div, .marker-cluster-large div {
            background-color: var(--primary) !important; color: #fff !important; font-weight: 800;
            font-family: 'Plus Jakarta Sans', sans-serif;
        }

        /* Leaflet Popups */
        .leaflet-popup-content-wrapper {
            background: var(--bg-surface) !important; color: var(--text-main) !important;
            border-radius: var(--radius-xl) !important; padding: 0.35rem !important;
            box-shadow: var(--shadow-xl) !important; border: 1px solid var(--border);
        }
        .leaflet-popup-tip { background: var(--bg-surface) !important; }
        .popup-card { padding: 0.6rem; min-width: 220px; font-family: 'Plus Jakarta Sans', sans-serif; }
        .popup-user { display: flex; align-items: center; gap: 0.75rem; margin-bottom: 0.85rem; }
        .popup-avatar { 
            width: 44px; height: 44px; border-radius: 50%; background: var(--primary-soft); 
            display: flex; align-items: center; justify-content: center; font-weight: 800; 
            color: var(--primary); overflow: hidden; flex-shrink: 0; object-fit: cover; border: 2px solid var(--border); 
        }
        .popup-info strong { display: block; font-size: 0.95rem; color: var(--text-main); }
        .popup-info span { font-size: 0.75rem; color: var(--text-muted); font-weight: 600; }
        .popup-meta-item { display: flex; align-items: center; gap: 0.4rem; font-size: 0.8rem; margin-bottom: 0.35rem; color: var(--text-muted); }
        .popup-meta-item i { width: 14px; color: var(--primary); }

        /* Data Table Card */
        .table-card { background: var(--bg-surface); border: 1px solid var(--border); border-radius: var(--radius-2xl); overflow: hidden; box-shadow: var(--shadow-md); }
        .table-toolbar { 
            padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--border); 
            display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; 
        }
        .toolbar-filters { display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap; }
        
        .select-filter {
            padding: 0.6rem 1rem; border-radius: var(--radius-md); border: 1px solid var(--border);
            background: var(--bg-body); color: var(--text-main); font-size: 0.82rem; font-weight: 600;
            cursor: pointer; transition: var(--transition);
        }
        .select-filter:focus { border-color: var(--primary); }

        .search-box { position: relative; width: 100%; max-width: 280px; }
        .search-box input { 
            width: 100%; padding: 0.6rem 1rem 0.6rem 2.4rem; border-radius: 99px; 
            border: 1px solid var(--border); background: var(--bg-body); color: var(--text-main); 
            font-size: 0.85rem; transition: var(--transition);
        }
        .search-box input:focus { border-color: var(--primary); box-shadow: 0 0 0 3px var(--primary-soft); }
        .search-box i { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--text-muted); font-size: 0.85rem; }

        .table-responsive { overflow-x: auto; max-height: 580px; }
        table { width: 100%; border-collapse: collapse; text-align: left; font-size: 0.88rem; }
        th { 
            background: var(--bg-body); padding: 0.95rem 1.25rem; font-weight: 700; 
            color: var(--text-muted); border-bottom: 1px solid var(--border); 
            text-transform: uppercase; font-size: 0.72rem; letter-spacing: 0.05em;
            position: sticky; top: 0; z-index: 10; cursor: pointer; user-select: none;
        }
        th:hover { color: var(--text-main); }
        th i.sort-icon { margin-left: 4px; font-size: 0.75rem; opacity: 0.4; }
        th.active-sort i.sort-icon { opacity: 1; color: var(--primary); }

        td { padding: 0.95rem 1.25rem; border-bottom: 1px solid var(--border); vertical-align: middle; transition: var(--transition); }
        tr:hover td { background: var(--bg-surface-hover); }
        tr.highlighted td { background: var(--primary-soft) !important; }
        tr:last-child td { border-bottom: none; }

        /* Row Badges & Components */
        .user-badge { display: flex; align-items: center; gap: 0.85rem; }
        .user-avatar-img { width: 42px; height: 42px; border-radius: 50%; object-fit: cover; border: 2px solid var(--border); flex-shrink: 0; }
        .user-avatar-initials { 
            width: 42px; height: 42px; border-radius: 50%; background: linear-gradient(135deg, var(--primary), #4f46e5); 
            color: white; font-weight: 800; font-size: 0.9rem; display: flex; align-items: center; justify-content: center;
            border: 2px solid var(--border); flex-shrink: 0;
        }
        .user-meta strong { display: block; font-size: 0.92rem; color: var(--text-main); font-weight: 700; }
        .user-meta span { font-size: 0.78rem; color: var(--text-muted); }

        .status-pill {
            display: inline-flex; align-items: center; gap: 0.45rem; padding: 4px 11px; 
            border-radius: 99px; font-size: 0.75rem; font-weight: 700;
        }
        .pill-online { background: var(--success-soft); color: var(--success); }
        .pill-offline { background: var(--bg-body); color: var(--text-muted); border: 1px solid var(--border); }

        .role-badge { 
            display: inline-block; padding: 3px 9px; border-radius: 6px; 
            font-size: 0.72rem; font-weight: 800; text-transform: uppercase; 
            background: var(--primary-soft); color: var(--primary); 
        }

        .coord-pill { 
            display: inline-flex; align-items: center; gap: 0.5rem; padding: 5px 12px; 
            border-radius: 8px; background: var(--bg-body); border: 1px solid var(--border); 
            font-family: 'JetBrains Mono', monospace; font-size: 0.78rem; cursor: pointer; transition: var(--transition);
        }
        .coord-pill:hover { background: var(--primary-soft); border-color: var(--primary); color: var(--primary); }
        .badge-no-location { 
            display: inline-flex; align-items: center; gap: 0.35rem; padding: 4px 10px; 
            border-radius: 6px; background: var(--danger-soft); color: var(--danger); 
            font-size: 0.75rem; font-weight: 700; 
        }

        /* Proximity Tag */
        .proximity-tag {
            font-size: 0.72rem; font-family: 'JetBrains Mono', monospace; font-weight: 700;
            color: var(--purple); background: var(--purple-soft); padding: 2px 7px; border-radius: 4px;
            margin-top: 3px; display: inline-block;
        }

        /* Offcanvas Inspection Drawer */
        .drawer-overlay {
            position: fixed; inset: 0; background: rgba(0,0,0,0.55); backdrop-filter: blur(5px);
            z-index: 99995; opacity: 0; pointer-events: none; transition: var(--transition);
        }
        .drawer-overlay.active { opacity: 1; pointer-events: auto; }
        .drawer {
            position: fixed; top: 0; right: 0; bottom: 0; width: 100%; max-width: 460px;
            background: var(--bg-surface); border-left: 1px solid var(--border); z-index: 99996;
            transform: translateX(100%); transition: transform 0.35s cubic-bezier(0.16, 1, 0.3, 1);
            display: flex; flex-direction: column; box-shadow: var(--shadow-xl); overflow-y: auto;
        }
        .drawer.active { transform: translateX(0); }
        .drawer-header { padding: 1.5rem; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; }
        .drawer-body { padding: 1.5rem; flex: 1; }
        .drawer-profile-card { 
            text-align: center; padding: 1.75rem 1.5rem; background: var(--bg-body); 
            border-radius: var(--radius-xl); margin-bottom: 1.5rem; border: 1px solid var(--border); 
        }
        .drawer-avatar { 
            width: 86px; height: 86px; border-radius: 50%; margin: 0 auto 0.85rem; 
            background: linear-gradient(135deg, var(--primary), #4f46e5); color: white; 
            display: flex; align-items: center; justify-content: center; font-size: 1.75rem; 
            font-weight: 800; object-fit: cover; border: 4px solid var(--bg-surface); 
            box-shadow: var(--shadow-md);
        }
        
        .info-card {
            background: var(--bg-body); border: 1px solid var(--border); border-radius: var(--radius-lg);
            padding: 0.5rem 1.25rem; margin-bottom: 1.5rem;
        }
        .info-row { display: flex; justify-content: space-between; align-items: center; padding: 0.8rem 0; border-bottom: 1px solid var(--border); font-size: 0.85rem; }
        .info-row:last-child { border-bottom: none; }
        .info-row span { color: var(--text-muted); font-weight: 500; }
        .info-row strong { color: var(--text-main); font-weight: 700; text-align: right; }

        .address-box {
            background: var(--bg-body); border: 1px dashed var(--border); border-radius: var(--radius-lg);
            padding: 1rem; font-size: 0.82rem; color: var(--text-muted); line-height: 1.5; margin-bottom: 1.5rem;
        }

        .nav-links-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.5rem; margin-top: 0.75rem; }
        .nav-btn {
            display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 0.4rem;
            padding: 0.85rem 0.5rem; border-radius: var(--radius-md); background: var(--bg-body);
            border: 1px solid var(--border); font-size: 0.75rem; font-weight: 700; color: var(--text-main);
            transition: var(--transition);
        }
        .nav-btn:hover { background: var(--primary-soft); border-color: var(--primary); color: var(--primary); }
        .nav-btn i { font-size: 1.15rem; }

        /* Toast Feedback */
        #toast {
            position: fixed; bottom: 2rem; right: 2rem; background: var(--text-main); color: var(--bg-body);
            padding: 0.85rem 1.4rem; border-radius: var(--radius-md); font-weight: 600; font-size: 0.85rem;
            box-shadow: var(--shadow-xl); opacity: 0; transform: translateY(20px); transition: var(--transition);
            z-index: 999999; display: flex; align-items: center; gap: 0.65rem; pointer-events: none;
        }
        #toast.show { opacity: 1; transform: translateY(0); }

        /* Custom Scrollbars */
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: var(--border); border-radius: 99px; }
        ::-webkit-scrollbar-thumb:hover { background: var(--text-muted); }

        /* Print Media Optimization */
        @media print {
            .main-header, .page-header button, .map-card, .table-toolbar, .chip-container, #toast, .drawer-overlay, .drawer { display: none !important; }
            body { padding-top: 0; background: #fff; color: #000; }
            .container { max-width: 100%; padding: 0; }
            .table-card { box-shadow: none; border: none; }
            table { border: 1px solid #ddd; }
            th, td { padding: 8px; border: 1px solid #ddd; }
        }
    </style>
</head>
<body>

    <!-- Main Navigation Bar -->
    <header class="main-header">
        <div class="navbar">
            <a href="dashboard.php" class="logo">
                <img src="<?php echo htmlspecialchars($base_path . 'uploads/CUPAD LOGO.png'); ?>" alt="CUPAD Logo">
                <span>CUPAD Telemetry</span>
            </a>
            <div style="display:flex; align-items:center; gap:0.75rem;">
                <div class="sync-badge" id="liveSyncStatus">
                    <div class="live-dot"></div>
                    <span>Telemetry Active</span>
                </div>
                <button class="btn btn-outline" onclick="window.print()" title="Print Summary Report">
                    <i class="fas fa-print"></i> <span style="display:none; @media(min-width:768px){display:inline;}">Print</span>
                </button>
                <a href="dashboard.php" class="btn btn-outline">
                    <i class="fas fa-arrow-left"></i> Dashboard
                </a>
                <button id="themeToggle" class="btn btn-outline icon-btn" title="Toggle Light / Dark Mode">
                    <i class="fas fa-moon"></i>
                </button>
            </div>
        </div>
    </header>

    <!-- Main Container -->
    <main class="container">
        
        <!-- Header & Top Level Controls -->
        <div class="page-header">
            <div class="page-title-wrap">
                <h1><i class="fas fa-satellite-dish" style="color:var(--primary)"></i> Staff GPS Location & Geotracking</h1>
                <p>Live satellite telemetry, branch distribution mapping, and staff GPS coordinates.</p>
            </div>
            <div style="display:flex; gap:0.6rem; flex-wrap:wrap;">
                <button class="btn btn-outline" onclick="locateAdminPosition()">
                    <i class="fas fa-crosshairs" style="color:var(--purple)"></i> My Location & Distance
                </button>
                <button class="btn btn-primary" onclick="exportToCSV()">
                    <i class="fas fa-file-arrow-down"></i> Export CSV Dataset
                </button>
            </div>
        </div>

        <!-- Quick Filter Badges -->
        <div class="chip-container">
            <div class="chip active" onclick="setQuickFilter('all', this)">
                <i class="fas fa-users"></i> All Staff <span class="chip-count"><?php echo count($users); ?></span>
            </div>
            <div class="chip" onclick="setQuickFilter('online', this)">
                <i class="fas fa-circle" style="color:var(--success)"></i> Online with GPS <span class="chip-count"><?php echo $total_online_with_location; ?></span>
            </div>
            <div class="chip" onclick="setQuickFilter('offline', this)">
                <i class="fas fa-circle" style="color:var(--text-muted)"></i> Offline Staff <span class="chip-count"><?php echo count($users) - $total_online_with_location; ?></span>
            </div>
            <div class="chip" onclick="setQuickFilter('missing', this)">
                <i class="fas fa-triangle-exclamation" style="color:var(--danger)"></i> Missing GPS Data <span class="chip-count"><?php echo count($users) - $total_with_location; ?></span>
            </div>
        </div>

        <!-- Key Metrics Cards -->
        <div class="stats-grid">
            <div class="stat-card blue">
                <div class="stat-icon bg-blue"><i class="fas fa-user-group"></i></div>
                <div class="stat-info">
                    <h3><?php echo count($users); ?></h3>
                    <p>Total Registered Staff</p>
                </div>
            </div>
            <div class="stat-card green">
                <div class="stat-icon bg-green"><i class="fas fa-location-crosshairs"></i></div>
                <div class="stat-info">
                    <h3><?php echo $total_with_location; ?></h3>
                    <p>Tracked GPS Positions</p>
                </div>
            </div>
            <div class="stat-card orange">
                <div class="stat-icon bg-orange"><i class="fas fa-tower-broadcast"></i></div>
                <div class="stat-info">
                    <h3><?php echo $total_online_with_location; ?></h3>
                    <p>Active Live Transmitters</p>
                </div>
            </div>
            <div class="stat-card rose">
                <div class="stat-icon bg-rose"><i class="fas fa-location-dot-slash"></i></div>
                <div class="stat-info">
                    <h3><?php echo count($users) - $total_with_location; ?></h3>
                    <p>Untracked / Off-Grid</p>
                </div>
            </div>
        </div>

        <!-- Leaflet Geolocation Map -->
        <div class="map-card" id="mapCardContainer">
            <div class="map-toolbar">
                <div>
                    <strong style="font-size:1.05rem; display:flex; align-items:center; gap:0.55rem;">
                        <i class="fas fa-map-location-dot" style="color:var(--primary)"></i> Live Staff Coordinate Map
                    </strong>
                    <span style="font-size:0.8rem; color:var(--text-muted);">Pins group dynamically into clusters. Click pins to reveal profile cards.</span>
                </div>
                <div class="map-controls-group">
                    <!-- Tile Layer Selector -->
                    <select id="mapLayerSelect" class="select-filter" onchange="changeMapLayer(this.value)">
                        <option value="voyager">CartoDB Voyager (Clean)</option>
                        <option value="osm">OpenStreetMap Standard</option>
                        <option value="dark">CartoDB Dark Canvas</option>
                        <option value="satellite">Esri Satellite Imagery</option>
                    </select>

                    <button class="btn btn-outline btn-sm" onclick="toggleGeofenceRings()" id="btnGeofence" title="Toggle 2km radius circle rings">
                        <i class="fas fa-circle-notch"></i> Proximity Rings
                    </button>
                    <button class="btn btn-outline btn-sm" onclick="recenterMap()" title="Fit all staff in view">
                        <i class="fas fa-compress-arrows-alt"></i> Recenter
                    </button>
                    <button class="btn btn-outline btn-sm" id="btnToggleFullscreen" onclick="toggleMapFullscreen()">
                        <i class="fas fa-expand"></i> Fullscreen
                    </button>
                </div>
            </div>
            <div id="userMap"></div>
        </div>

        <!-- Detailed Tabular Registry -->
        <div class="table-card">
            <div class="table-toolbar">
                <div>
                    <strong style="font-size:1.05rem;">Staff Geolocation Directory</strong>
                    <span id="recordCount" style="display:block; font-size:0.78rem; color:var(--text-muted); margin-top:2px;">
                        Showing <?php echo count($users); ?> total staff records
                    </span>
                </div>
                <div class="toolbar-filters">
                    <!-- Branch Filter -->
                    <select id="branchSelect" class="select-filter" onchange="filterTableAndMap()">
                        <option value="">All Branches (<?php echo count($branches_list); ?>)</option>
                        <?php foreach ($branches_list as $b): ?>
                            <option value="<?php echo htmlspecialchars($b); ?>"><?php echo htmlspecialchars($b); ?></option>
                        <?php endforeach; ?>
                    </select>

                    <!-- Role Filter -->
                    <select id="roleSelect" class="select-filter" onchange="filterTableAndMap()">
                        <option value="">All Roles (<?php echo count($roles_list); ?>)</option>
                        <?php foreach ($roles_list as $r): ?>
                            <option value="<?php echo htmlspecialchars($r); ?>"><?php echo htmlspecialchars(strtoupper($r)); ?></option>
                        <?php endforeach; ?>
                    </select>

                    <!-- Search Box -->
                    <div class="search-box">
                        <i class="fas fa-search"></i>
                        <input type="text" id="userSearch" placeholder="Search staff name or role..." onkeyup="filterTableAndMap()">
                    </div>
                </div>
            </div>

            <div class="table-responsive">
                <table id="userTable">
                    <thead>
                        <tr>
                            <th onclick="sortTable(0)">Staff Member <i class="fas fa-sort sort-icon"></i></th>
                            <th onclick="sortTable(1)">Role <i class="fas fa-sort sort-icon"></i></th>
                            <th onclick="sortTable(2)">Assigned Branch <i class="fas fa-sort sort-icon"></i></th>
                            <th onclick="sortTable(3)">Status <i class="fas fa-sort sort-icon"></i></th>
                            <th onclick="sortTable(4)">Last Activity <i class="fas fa-sort sort-icon"></i></th>
                            <th>GPS Telemetry</th>
                            <th style="text-align:right;">Quick Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($users)): ?>
                            <tr>
                                <td colspan="7" style="text-align:center; padding:3.5rem; color:var(--text-muted);">
                                    <i class="fas fa-compass" style="font-size:2.5rem; display:block; margin-bottom:0.75rem; opacity:0.35;"></i>
                                    No staff GPS records currently exist in the database.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($users as $u): 
                                $has_coords = $u['has_location'];
                                $initials = strtoupper(substr($u['display_name'], 0, 2));
                                $rel_time = timeAgo($u['last_login']);
                            ?>
                                <tr id="row-user-<?php echo $u['id']; ?>"
                                    data-user-id="<?php echo $u['id']; ?>" 
                                    data-online="<?php echo $u['is_online'] ? '1' : '0'; ?>" 
                                    data-has-coords="<?php echo $has_coords ? '1' : '0'; ?>"
                                    data-branch="<?php echo htmlspecialchars($u['branch_name']); ?>"
                                    data-role="<?php echo htmlspecialchars($u['role']); ?>"
                                    onclick="openDrawer(<?php echo htmlspecialchars(json_encode($u)); ?>)">
                                    <td>
                                        <div class="user-badge">
                                            <?php if ($u['has_valid_pic']): ?>
                                                <img src="<?php echo htmlspecialchars($u['profile_pic_url']); ?>" class="user-avatar-img" alt="Avatar">
                                            <?php else: ?>
                                                <div class="user-avatar-initials"><?php echo $initials; ?></div>
                                            <?php endif; ?>
                                            <div class="user-meta">
                                                <strong><?php echo htmlspecialchars($u['display_name']); ?></strong>
                                                <span>@<?php echo htmlspecialchars($u['username']); ?></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td><span class="role-badge"><?php echo htmlspecialchars($u['role']); ?></span></td>
                                    <td style="font-weight:600;"><?php echo htmlspecialchars($u['branch_name']); ?></td>
                                    <td>
                                        <?php if ($u['is_online']): ?>
                                            <span class="status-pill pill-online"><i class="fas fa-circle" style="font-size:0.5rem;"></i> Online</span>
                                        <?php else: ?>
                                            <span class="status-pill pill-offline"><i class="fas fa-circle" style="font-size:0.5rem;"></i> Offline</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span style="font-size:0.82rem; color:var(--text-muted);" title="<?php echo $u['last_login'] ? date('M j, Y • g:i A', strtotime($u['last_login'])) : ''; ?>">
                                            <?php echo $rel_time; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($has_coords): ?>
                                            <div>
                                                <div class="coord-pill" onclick="event.stopPropagation(); copyCoordinates(<?php echo $u['last_latitude']; ?>, <?php echo $u['last_longitude']; ?>)" title="Click to copy coordinates">
                                                    <i class="fas fa-copy" style="color:var(--primary); font-size:0.75rem;"></i>
                                                    <span><?php echo number_format($u['last_latitude'], 5); ?>, <?php echo number_format($u['last_longitude'], 5); ?></span>
                                                </div>
                                                <div class="distance-badge-container" id="dist-tag-<?php echo $u['id']; ?>"></div>
                                            </div>
                                        <?php else: ?>
                                            <span class="badge-no-location"><i class="fas fa-satellite-dish"></i> No GPS Signal</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align:right;" onclick="event.stopPropagation();">
                                        <?php if ($has_coords): ?>
                                            <div style="display:inline-flex; gap:0.4rem;">
                                                <button class="btn btn-primary btn-sm" onclick="focusOnMap(<?php echo $u['last_latitude']; ?>, <?php echo $u['last_longitude']; ?>, <?php echo $u['id']; ?>)" title="Focus Marker">
                                                    <i class="fas fa-crosshairs"></i> Pan
                                                </button>
                                                <a href="https://maps.google.com/?q=<?php echo $u['last_latitude']; ?>,<?php echo $u['last_longitude']; ?>" target="_blank" class="btn btn-outline btn-sm icon-btn" title="Open in Google Maps">
                                                    <i class="fas fa-arrow-up-right-from-square"></i>
                                                </a>
                                            </div>
                                        <?php else: ?>
                                            <button class="btn btn-outline btn-sm" onclick="openDrawer(<?php echo htmlspecialchars(json_encode($u)); ?>)"><i class="fas fa-eye"></i> Details</button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </main>

    <!-- Offcanvas Inspection Drawer -->
    <div class="drawer-overlay" id="drawerOverlay" onclick="closeDrawer()"></div>
    <div class="drawer" id="userDrawer">
        <div class="drawer-header">
            <strong style="font-size:1.1rem; display:flex; align-items:center; gap:0.5rem;">
                <i class="fas fa-id-badge" style="color:var(--primary)"></i> Staff Telemetry File
            </strong>
            <button class="btn btn-outline icon-btn" onclick="closeDrawer()"><i class="fas fa-times"></i></button>
        </div>
        <div class="drawer-body" id="drawerContent">
            <!-- Dynamically Loaded via JavaScript -->
        </div>
    </div>

    <!-- Feedback Toast -->
    <div id="toast"><i class="fas fa-circle-check" style="color:var(--success)"></i> <span id="toastMsg">Action completed</span></div>

    <!-- Leaflet & Clustering Script Libraries -->
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js"></script>

    <script>
        // Theme Management
        const themeToggle = document.getElementById('themeToggle');
        const html = document.documentElement;
        
        function applyTheme(isDark) {
            if(isDark) {
                html.classList.add('dark'); html.classList.remove('light');
                themeToggle.innerHTML = '<i class="fas fa-sun"></i>';
            } else {
                html.classList.remove('dark'); html.classList.add('light');
                themeToggle.innerHTML = '<i class="fas fa-moon"></i>';
            }
        }
        
        applyTheme(localStorage.getItem('theme') === 'dark');
        themeToggle.addEventListener('click', () => {
            const isDark = !html.classList.contains('dark');
            localStorage.setItem('theme', isDark ? 'dark' : 'light');
            applyTheme(isDark);
        });

        // Initialize Map & Tile Layer Providers
        const defaultCenter = [6.5244, 3.3792]; // Lagos, Nigeria default center
        const map = L.map('userMap', {
            zoomControl: true,
            maxZoom: 19
        }).setView(defaultCenter, 6);

        const tileLayers = {
            voyager: L.tileLayer('https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png', {
                attribution: '&copy; CartoDB &copy; OpenStreetMap', maxZoom: 19
            }),
            osm: L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; OpenStreetMap contributors', maxZoom: 19
            }),
            dark: L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
                attribution: '&copy; CartoDB &copy; OpenStreetMap', maxZoom: 19
            }),
            satellite: L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
                attribution: 'Tiles &copy; Esri', maxZoom: 19
            })
        };

        // Add Default Base Layer
        let activeTileKey = localStorage.getItem('mapLayer') || 'voyager';
        if (tileLayers[activeTileKey]) {
            tileLayers[activeTileKey].addTo(map);
            document.getElementById('mapLayerSelect').value = activeTileKey;
        } else {
            tileLayers.voyager.addTo(map);
        }

        function changeMapLayer(key) {
            Object.values(tileLayers).forEach(layer => map.removeLayer(layer));
            if (tileLayers[key]) {
                tileLayers[key].addTo(map);
                localStorage.setItem('mapLayer', key);
            }
        }

        // Initialize Leaflet Marker Cluster Group
        const clusterGroup = L.markerClusterGroup({
            showCoverageOnHover: false,
            maxClusterRadius: 40,
            spiderfyOnMaxZoom: true
        });
        map.addLayer(clusterGroup);

        const usersData = <?php echo json_encode($users, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
        const markerRegistry = new Map();
        const geofenceCircles = [];
        let bounds = [];
        let showGeofence = false;
        let adminLocation = null;
        let adminMarker = null;

        // Build Custom Markers & Clusters
        usersData.forEach(user => {
            if (user.has_location) {
                const lat = user.last_latitude;
                const lng = user.last_longitude;

                bounds.push([lat, lng]);
                
                const initials = (user.display_name || 'U').substring(0, 2).toUpperCase();
                const isOnline = user.is_online == 1;

                const pinAvatarStyle = user.has_valid_pic 
                    ? `style="background-image: url('${user.profile_pic_url}'); background-size: cover; background-position: center;"` 
                    : '';
                const pinInitials = user.has_valid_pic ? '' : initials;

                const customPinHtml = `
                    <div class="map-pin-container" title="${user.display_name}">
                        <div class="map-pin-avatar" ${pinAvatarStyle}>${pinInitials}</div>
                        ${isOnline ? '<div class="map-pin-pulse"></div>' : ''}
                    </div>
                `;

                const customIcon = L.divIcon({
                    html: customPinHtml, className: '',
                    iconSize: [44, 44], iconAnchor: [22, 22], popupAnchor: [0, -22]
                });

                const marker = L.marker([lat, lng], { icon: customIcon });

                // Interactive Popup
                const popupAvatarHtml = user.has_valid_pic
                    ? `<img src="${user.profile_pic_url}" class="popup-avatar" alt="Avatar">`
                    : `<div class="popup-avatar">${initials}</div>`;

                const popupHtml = `
                    <div class="popup-card">
                        <div class="popup-user">
                            ${popupAvatarHtml}
                            <div class="popup-info">
                                <strong>${user.display_name}</strong>
                                <span>@${user.username} • <span style="color:var(--primary);">${user.role.toUpperCase()}</span></span>
                            </div>
                        </div>
                        <div class="popup-meta-item"><i class="fas fa-building"></i> <span><b>Branch:</b> ${user.branch_name}</span></div>
                        <div class="popup-meta-item"><i class="fas fa-signal"></i> <span><b>Status:</b> ${isOnline ? '<span style="color:var(--success);font-weight:700;">Online</span>' : '<span style="color:var(--text-muted);">Offline</span>'}</span></div>
                        <div class="popup-meta-item"><i class="fas fa-location-dot"></i> <span style="font-family:monospace; font-size:0.75rem;">${lat.toFixed(5)}, ${lng.toFixed(5)}</span></div>
                        
                        <div style="margin-top:0.75rem; display:flex; gap:0.4rem;">
                            <button class="btn btn-primary btn-sm" onclick="openDrawer(${JSON.stringify(user).replace(/"/g, '&quot;')})" style="flex:1; justify-content:center;">
                                <i class="fas fa-circle-info"></i> Full Dossier
                            </button>
                            <a href="https://maps.google.com/?q=${lat},${lng}" target="_blank" class="btn btn-outline btn-sm icon-btn" title="Open in Google Maps">
                                <i class="fas fa-arrow-up-right-from-square"></i>
                            </a>
                        </div>
                    </div>
                `;

                marker.bindPopup(popupHtml);
                clusterGroup.addLayer(marker);

                // Add Proximity Radius Circle
                const circle = L.circle([lat, lng], {
                    radius: 2000, // 2km Geofence
                    color: isOnline ? 'var(--success)' : 'var(--primary)',
                    fillColor: isOnline ? 'var(--success)' : 'var(--primary)',
                    fillOpacity: 0.08,
                    weight: 1.5,
                    dashArray: '4, 6'
                });
                geofenceCircles.push(circle);

                markerRegistry.set(parseInt(user.id), { marker, circle, lat, lng, user });
            }
        });

        // Fit map bounds smoothly
        if (bounds.length > 0) {
            map.fitBounds(bounds, { padding: [50, 50], maxZoom: 14 });
        }

        // Map Control Functions
        function recenterMap() {
            if (bounds.length > 0) map.fitBounds(bounds, { padding: [50, 50] });
            else map.setView(defaultCenter, 6);
        }

        function toggleGeofenceRings() {
            showGeofence = !showGeofence;
            const btn = document.getElementById('btnGeofence');
            
            geofenceCircles.forEach(circle => {
                if (showGeofence) circle.addTo(map);
                else map.removeLayer(circle);
            });

            if (showGeofence) {
                btn.classList.add('btn-primary');
                btn.classList.remove('btn-outline');
                showToast('Proximity radius rings (2km) enabled');
            } else {
                btn.classList.remove('btn-primary');
                btn.classList.add('btn-outline');
                showToast('Proximity rings hidden');
            }
        }

        function toggleMapFullscreen() {
            const mapCard = document.getElementById('mapCardContainer');
            const btn = document.getElementById('btnToggleFullscreen');
            const isFullscreen = mapCard.classList.toggle('fullscreen');

            if (isFullscreen) {
                btn.innerHTML = '<i class="fas fa-compress"></i> Exit';
                document.body.style.overflow = 'hidden';
            } else {
                btn.innerHTML = '<i class="fas fa-expand"></i> Fullscreen';
                document.body.style.overflow = '';
            }

            setTimeout(() => map.invalidateSize(), 300);
        }

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                const mapCard = document.getElementById('mapCardContainer');
                if (mapCard && mapCard.classList.contains('fullscreen')) {
                    toggleMapFullscreen();
                }
            }
        });

        function focusOnMap(lat, lng, userId) {
            // Unspiderfy and Zoom
            map.setView([lat, lng], 16, { animate: true });
            
            const item = markerRegistry.get(parseInt(userId));
            if (item) {
                clusterGroup.zoomToShowLayer(item.marker, () => {
                    item.marker.openPopup();
                });
            }

            // Highlight table row
            document.querySelectorAll('#userTable tbody tr').forEach(r => r.classList.remove('highlighted'));
            const targetRow = document.getElementById(`row-user-${userId}`);
            if (targetRow) {
                targetRow.classList.add('highlighted');
                targetRow.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }

            window.scrollTo({ top: document.getElementById('mapCardContainer').offsetTop - 90, behavior: 'smooth' });
        }

        // Distance & Proximity Calculator (Haversine Formula)
        function calculateDistanceKm(lat1, lon1, lat2, lon2) {
            const R = 6371; // Earth's radius in km
            const dLat = (lat2 - lat1) * Math.PI / 180;
            const dLon = (lon2 - lon1) * Math.PI / 180;
            const a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
                      Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
                      Math.sin(dLon / 2) * Math.sin(dLon / 2);
            const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
            return R * c;
        }

        function locateAdminPosition() {
            if (!navigator.geolocation) {
                showToast('Geolocation is not supported by your browser.');
                return;
            }

            showToast('Fetching your GPS location...');
            navigator.geolocation.getCurrentPosition(
                (pos) => {
                    adminLocation = [pos.coords.latitude, pos.coords.longitude];
                    
                    if (adminMarker) map.removeLayer(adminMarker);

                    const adminIcon = L.divIcon({
                        html: `
                            <div style="width:20px;height:20px;background:var(--purple);border:3px solid white;border-radius:50%;box-shadow:0 0 10px rgba(139,92,246,0.7);animation:livePulse 1.5s infinite;"></div>
                        `,
                        className: '', iconSize: [20, 20], iconAnchor: [10, 10]
                    });

                    adminMarker = L.marker(adminLocation, { icon: adminIcon }).addTo(map);
                    adminMarker.bindPopup('<b>You are here (Admin Console)</b>').openPopup();
                    
                    map.setView(adminLocation, 12, { animate: true });

                    // Update Distances on table
                    usersData.forEach(u => {
                        if (u.has_location) {
                            const dist = calculateDistanceKm(adminLocation[0], adminLocation[1], u.last_latitude, u.last_longitude);
                            const tagEl = document.getElementById(`dist-tag-${u.id}`);
                            if (tagEl) {
                                tagEl.innerHTML = `<span class="proximity-tag"><i class="fas fa-person-walking"></i> ${dist < 1 ? (dist * 1000).toFixed(0) + ' m' : dist.toFixed(1) + ' km'} away</span>`;
                            }
                        }
                    });

                    showToast('Admin position locked. Distances calculated!');
                },
                (err) => {
                    showToast('Could not fetch location: ' + err.message);
                },
                { enableHighAccuracy: true, timeout: 10000 }
            );
        }

        // Quick Filter Badges
        let currentQuickFilter = 'all';
        function setQuickFilter(type, el) {
            document.querySelectorAll('.chip').forEach(c => c.classList.remove('active'));
            el.classList.add('active');
            currentQuickFilter = type;
            filterTableAndMap();
        }

        // Combined Live Filter
        function filterTableAndMap() {
            const query = document.getElementById('userSearch').value.toLowerCase();
            const branchFilter = document.getElementById('branchSelect').value;
            const roleFilter = document.getElementById('roleSelect').value;
            const rows = document.querySelectorAll('#userTable tbody tr');
            let visibleCount = 0;

            clusterGroup.clearLayers();

            rows.forEach(row => {
                const text = row.innerText.toLowerCase();
                const userId = parseInt(row.getAttribute('data-user-id'));
                const isOnline = row.getAttribute('data-online') === '1';
                const hasCoords = row.getAttribute('data-has-coords') === '1';
                const branch = row.getAttribute('data-branch');
                const role = row.getAttribute('data-role');

                // Filter Evaluations
                let passQuick = true;
                if (currentQuickFilter === 'online') passQuick = isOnline && hasCoords;
                else if (currentQuickFilter === 'offline') passQuick = !isOnline;
                else if (currentQuickFilter === 'missing') passQuick = !hasCoords;

                let passBranch = !branchFilter || branch === branchFilter;
                let passRole = !roleFilter || role === roleFilter;
                let passQuery = text.includes(query);

                const isVisible = passQuick && passBranch && passRole && passQuery;
                row.style.display = isVisible ? '' : 'none';

                if (isVisible) {
                    visibleCount++;
                    if (markerRegistry.has(userId)) {
                        clusterGroup.addLayer(markerRegistry.get(userId).marker);
                    }
                }
            });

            document.getElementById('recordCount').innerText = `Showing ${visibleCount} filtered staff records`;
        }

        // Table Column Sorter
        let sortDirection = {};
        function sortTable(colIndex) {
            const table = document.getElementById('userTable');
            const tbody = table.querySelector('tbody');
            const rows = Array.from(tbody.querySelectorAll('tr'));
            
            const isAsc = !sortDirection[colIndex];
            sortDirection[colIndex] = isAsc;

            document.querySelectorAll('th').forEach(th => th.classList.remove('active-sort'));
            table.querySelectorAll('th')[colIndex].classList.add('active-sort');

            rows.sort((a, b) => {
                const aText = a.children[colIndex].innerText.trim();
                const bText = b.children[colIndex].innerText.trim();
                return isAsc ? aText.localeCompare(bText) : bText.localeCompare(aText);
            });

            rows.forEach(row => tbody.appendChild(row));
        }

        // Inspection Drawer & Reverse Geocoding
        async function openDrawer(user) {
            const drawer = document.getElementById('userDrawer');
            const overlay = document.getElementById('drawerOverlay');
            const content = document.getElementById('drawerContent');
            const initials = (user.display_name || 'U').substring(0, 2).toUpperCase();
            const hasCoords = user.has_location;

            const drawerAvatarHtml = user.has_valid_pic
                ? `<img src="${user.profile_pic_url}" class="drawer-avatar" alt="Avatar">`
                : `<div class="drawer-avatar">${initials}</div>`;

            content.innerHTML = `
                <div class="drawer-profile-card">
                    ${drawerAvatarHtml}
                    <h3 style="font-size:1.25rem; font-weight:800; letter-spacing:-0.01em; margin-bottom:2px;">${user.display_name}</h3>
                    <span style="color:var(--text-muted); font-size:0.85rem;">@${user.username}</span>
                    <div style="margin-top:0.85rem; display:flex; justify-content:center; gap:0.5rem;">
                        <span class="role-badge">${user.role}</span>
                        ${user.is_online == 1 ? '<span class="status-pill pill-online"><i class="fas fa-circle" style="font-size:0.4rem;"></i> Online</span>' : '<span class="status-pill pill-offline"><i class="fas fa-circle" style="font-size:0.4rem;"></i> Offline</span>'}
                    </div>
                </div>

                <div class="info-card">
                    <div class="info-row"><span>Assigned Branch</span><strong>${user.branch_name}</strong></div>
                    <div class="info-row"><span>Last Timestamp</span><strong>${user.last_login || 'Never recorded'}</strong></div>
                    <div class="info-row"><span>Latitude</span><strong style="font-family:monospace;">${hasCoords ? user.last_latitude.toFixed(6) : 'N/A'}</strong></div>
                    <div class="info-row"><span>Longitude</span><strong style="font-family:monospace;">${hasCoords ? user.last_longitude.toFixed(6) : 'N/A'}</strong></div>
                </div>

                ${hasCoords ? `
                    <div style="margin-bottom:0.6rem; font-size:0.78rem; font-weight:700; text-transform:uppercase; color:var(--text-muted); letter-spacing:0.04em;">
                        Estimated Geographic Area (OpenStreetMap)
                    </div>
                    <div class="address-box" id="reverseGeoAddress">
                        <i class="fas fa-spinner fa-spin"></i> Resolving reverse address...
                    </div>

                    <div style="font-size:0.78rem; font-weight:700; text-transform:uppercase; color:var(--text-muted); letter-spacing:0.04em; margin-bottom:0.4rem;">
                        Launch Navigation App
                    </div>
                    <div class="nav-links-grid">
                        <a href="https://maps.google.com/?q=${user.last_latitude},${user.last_longitude}" target="_blank" class="nav-btn">
                            <i class="fab fa-google" style="color:#4285F4;"></i> Google Maps
                        </a>
                        <a href="https://maps.apple.com/?ll=${user.last_latitude},${user.last_longitude}&q=${encodeURIComponent(user.display_name)}" target="_blank" class="nav-btn">
                            <i class="fab fa-apple" style="color:var(--text-main);"></i> Apple Maps
                        </a>
                        <a href="https://waze.com/ul?ll=${user.last_latitude},${user.last_longitude}&navigate=yes" target="_blank" class="nav-btn">
                            <i class="fab fa-waze" style="color:#33ccff;"></i> Waze App
                        </a>
                    </div>

                    <div style="margin-top:1.5rem; display:flex; flex-direction:column; gap:0.6rem;">
                        <button class="btn btn-primary" onclick="focusOnMap(${user.last_latitude}, ${user.last_longitude}, ${user.id}); closeDrawer();" style="justify-content:center;">
                            <i class="fas fa-crosshairs"></i> Pan to Coordinate on Map
                        </button>
                        <button class="btn btn-outline" onclick="copyCoordinates(${user.last_latitude}, ${user.last_longitude})" style="justify-content:center;">
                            <i class="fas fa-copy"></i> Copy Raw GPS Coordinates
                        </button>
                    </div>
                ` : `
                    <div style="text-align:center; padding:2rem 1rem; background:var(--danger-soft); border-radius:var(--radius-lg); color:var(--danger);">
                        <i class="fas fa-triangle-exclamation" style="font-size:2rem; margin-bottom:0.5rem; display:block;"></i>
                        <strong>No GPS Telemetry Found</strong>
                        <p style="font-size:0.8rem; margin-top:4px; opacity:0.85;">This staff account has not yet transmitted GPS location signals.</p>
                    </div>
                `}
            `;

            drawer.classList.add('active');
            overlay.classList.add('active');

            // Perform Reverse Geocoding with OSM Nominatim
            if (hasCoords) {
                try {
                    const response = await fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${user.last_latitude}&lon=${user.last_longitude}`);
                    const data = await response.json();
                    const addressEl = document.getElementById('reverseGeoAddress');
                    if (addressEl) {
                        addressEl.innerHTML = `<i class="fas fa-map-pin" style="color:var(--primary); margin-right:4px;"></i> ${data.display_name || 'Address details resolved.'}`;
                    }
                } catch (e) {
                    const addressEl = document.getElementById('reverseGeoAddress');
                    if (addressEl) addressEl.innerHTML = `<i class="fas fa-info-circle"></i> Coordinates: ${user.last_latitude}, ${user.last_longitude}`;
                }
            }
        }

        function closeDrawer() {
            document.getElementById('userDrawer').classList.remove('active');
            document.getElementById('drawerOverlay').classList.remove('active');
        }

        // Copy Coordinates & Toast Helpers
        function copyCoordinates(lat, lng) {
            const text = `${lat}, ${lng}`;
            navigator.clipboard.writeText(text).then(() => {
                showToast(`Coordinates [${text}] copied to clipboard`);
            });
        }

        function showToast(message) {
            const toast = document.getElementById('toast');
            document.getElementById('toastMsg').innerText = message;
            toast.classList.add('show');
            setTimeout(() => toast.classList.remove('show'), 2800);
        }

        // CSV Export Tool
        function exportToCSV() {
            let csv = ['Staff Member,Username,Role,Branch,Status,Latitude,Longitude,Last Timestamp'];
            const rows = document.querySelectorAll('#userTable tbody tr');

            rows.forEach(row => {
                if (row.style.display !== 'none') {
                    const userId = parseInt(row.getAttribute('data-user-id'));
                    const u = usersData.find(x => parseInt(x.id) === userId);
                    if (u) {
                        const name = `"${(u.display_name).replace(/"/g, '""')}"`;
                        const status = u.is_online == 1 ? 'Online' : 'Offline';
                        csv.push([
                            name,
                            u.username,
                            u.role,
                            `"${u.branch_name}"`,
                            status,
                            u.last_latitude || '',
                            u.last_longitude || '',
                            `"${u.last_login || ''}"`
                        ].join(','));
                    }
                }
            });

            const blob = new Blob([csv.join('\n')], { type: 'text/csv;charset=utf-8;' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.setAttribute('href', url);
            a.setAttribute('download', `CUPAD_Staff_Locations_${new Date().toISOString().slice(0,10)}.csv`);
            a.click();
            showToast('CSV Dataset successfully compiled & downloaded.');
        }
    </script>
</body>
</html>