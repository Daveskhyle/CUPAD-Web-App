<?php
// transfer_hierarchy.php
session_start();
require_once '../includes/config.php';
$pdo = getDbConnection();

// --- AUTH CHECK ---
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: ../index.php');
    exit();
}

$base_path = '../';
$notification = null;

// --- DATA LOGIC FUNCTIONS ---
function getAllZones($pdo) {
    $stmt = $pdo->query("SELECT * FROM zones ORDER BY name ASC");
    return $stmt->fetchAll();
}

function getAllAreas($pdo) {
    $stmt = $pdo->query("SELECT a.*, z.name as zone_name FROM areas a LEFT JOIN zones z ON a.zone_id = z.id ORDER BY a.name ASC");
    return $stmt->fetchAll();
}

function getAllBranches($pdo) {
    $stmt = $pdo->query("SELECT b.*, z.name as zone_name, a.name as area_name 
                        FROM branches b 
                        LEFT JOIN zones z ON b.zone_id = z.id 
                        LEFT JOIN areas a ON b.area_id = a.id 
                        ORDER BY b.name ASC");
    return $stmt->fetchAll();
}

// --- POST HANDLERS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // 1. Move Area to a New Zone
    if (isset($_POST['move_area'])) {
        $area_id = $_POST['area_id'] ?? '';
        $new_zone_id = $_POST['new_zone_id'] ?? '';
        
        if ($area_id === '' || $new_zone_id === '') {
            $notification = ['type' => 'error', 'msg' => 'Please select both Area and Target Zone.'];
        } else {
            try {
                $pdo->beginTransaction();
                
                // Update the area's zone
                $stmt = $pdo->prepare("UPDATE areas SET zone_id = ? WHERE id = ?");
                $stmt->execute([$new_zone_id, $area_id]);
                
                // Cascade update: All branches in this area must also have their zone_id updated
                $stmt2 = $pdo->prepare("UPDATE branches SET zone_id = ? WHERE area_id = ?");
                $stmt2->execute([$new_zone_id, $area_id]);

                // CASCADE UPDATE: Sync all USERS in this area to the new Zone
                $stmt3 = $pdo->prepare("UPDATE users SET zone_id = ? WHERE area_id = ?");
                $stmt3->execute([$new_zone_id, $area_id]);
                
                $pdo->commit();
                $notification =['type' => 'success', 'msg' => 'Area, Branches, and Users were successfully moved to the new Zone.'];
            } catch (Exception $e) {
                $pdo->rollBack();
                $notification =['type' => 'error', 'msg' => 'Failed to move area: ' . $e->getMessage()];
            }
        }
    } 
    
    // 2. Move Branch to a New Area/Zone
    elseif (isset($_POST['move_branch'])) {
        $branch_id = $_POST['branch_id'] ?? '';
        $new_zone_id = $_POST['new_zone_id'] ?? '';
        $new_area_id = $_POST['new_area_id'] ?? '';
        
        if ($branch_id === '' || $new_zone_id === '' || $new_area_id === '') {
            $notification =['type' => 'error', 'msg' => 'All fields are required to move a branch.'];
        } else {
            // Verify new area belongs to new zone
            $stmt = $pdo->prepare("SELECT id FROM areas WHERE id = ? AND zone_id = ?");
            $stmt->execute([$new_area_id, $new_zone_id]);
            if (!$stmt->fetch()) {
                $notification =['type' => 'error', 'msg' => 'The selected target area does not belong to the selected target zone.'];
            } else {
                try {
                    $pdo->beginTransaction();

                    // Update the branch
                    $stmt = $pdo->prepare("UPDATE branches SET zone_id = ?, area_id = ? WHERE id = ?");
                    $stmt->execute([$new_zone_id, $new_area_id, $branch_id]);

                    // CASCADE UPDATE: Sync all USERS in this branch to the new Area & Zone
                    $stmt2 = $pdo->prepare("UPDATE users SET zone_id = ?, area_id = ? WHERE branch_id = ?");
                    $stmt2->execute([$new_zone_id, $new_area_id, $branch_id]);

                    $pdo->commit();
                    $notification =['type' => 'success', 'msg' => 'Branch and its Users were successfully moved to the new location.'];
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $notification =['type' => 'error', 'msg' => 'Failed to move branch: ' . $e->getMessage()];
                }
            }
        }
    }
}

// Load all data
$zones = getAllZones($pdo);
$areas = getAllAreas($pdo);
$branches = getAllBranches($pdo);

// User Profile logic to match Dashboard
$username = $_SESSION['username'] ?? '';
$full_name = $_SESSION['full_name'] ?? 'Admin';
$role = $_SESSION['role'] ?? $_SESSION['user_role'] ?? 'Admin';

$my_pic = 'default_avatar.png';
try {
    $stmt = $pdo->prepare("SELECT profile_pic FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user_data = $stmt->fetch();
    if ($user_data && !empty($user_data['profile_pic'])) {
        $my_pic = $user_data['profile_pic'];
    }
} catch (Exception $e) { }

$profile_pic_path = $base_path . 'uploads/' . $my_pic;
$has_profile_pic = file_exists($profile_pic_path) && $my_pic !== 'default_avatar.png';
?>
<!DOCTYPE html>
<html lang="en" class="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transfer Hierarchy - CUPAD</title>
    <link rel="icon" type="image/png" href="<?php echo htmlspecialchars($base_path . 'uploads/CUPAD LOGO.png'); ?>">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />
    <style>
        :root {
            --primary: #2563eb; --primary-dark: #1d4ed8;
            --success: #059669; --warning: #d97706; --danger: #dc2626; --info: #0284c7;
            --bg-body: #f1f5f9; --bg-surface: #ffffff;
            --text-main: #0f172a; --text-muted: #64748b;
            --border: #e2e8f0; 
            --radius-lg: 16px; --radius-md: 10px;
            --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
            --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1);
            --nav-height: 70px;
        }

        html.dark {
            --bg-body: #0f172a; --bg-surface: #1e293b;
            --text-main: #f8fafc; --text-muted: #94a3b8;
            --border: #334155; --primary: #3b82f6; 
            --success: #34d399; --danger: #f87171; --warning: #fbbf24;
        }

        * { box-sizing: border-box; outline: none; }
        body { font-family: 'Plus Jakarta Sans', sans-serif; background: var(--bg-body); color: var(--text-main); margin: 0; padding-top: var(--nav-height); transition: background-color 0.3s, color 0.3s; }
        a { text-decoration: none; color: inherit; }

        /* Modern Dashboard Header */
        .main-header { position: fixed; top: 0; left: 0; right: 0; height: var(--nav-height); background: rgba(255,255,255,0.85); backdrop-filter: blur(12px); border-bottom: 1px solid var(--border); z-index: 50; transition: background 0.3s; }
        html.dark .main-header { background: rgba(15, 23, 42, 0.85); }
        .navbar { max-width: 1400px; margin: 0 auto; padding: 0 1.5rem; display: flex; align-items: center; justify-content: space-between; height: 100%; }
        .logo { display: flex; align-items: center; gap: 0.75rem; font-weight: 800; font-size: 1.35rem; color: var(--primary); }
        .logo img { height: 38px; }
        .nav-right { display: flex; align-items: center; gap: 1rem; }
        
        .icon-btn { width: 36px; height: 36px; border-radius: 50%; border: none; background: transparent; color: var(--text-muted); cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; transition: 0.2s; }
        .icon-btn:hover { background: var(--bg-body); color: var(--text-main); }

        /* User Dropdown Profile */
        .user-dropdown-wrap { position: relative; }
        .user-pill { display: flex; align-items: center; gap: 0.75rem; padding: 4px 8px 4px 4px; border: 1px solid var(--border); border-radius: 99px; background: var(--bg-surface); cursor: pointer; transition: all 0.2s; }
        .user-pill:hover { border-color: var(--primary); box-shadow: var(--shadow-sm); }
        .user-avatar { width: 34px; height: 34px; border-radius: 50%; object-fit: cover; }
        .user-avatar-fallback { width: 34px; height: 34px; border-radius: 50%; background: var(--bg-body); color: var(--text-muted); display: flex; align-items: center; justify-content: center; font-size: 1rem; border: 1px solid var(--border); }
        .user-info { display: flex; flex-direction: column; line-height: 1.1; padding-right: 0.5rem; }
        .user-name { font-weight: 600; font-size: 0.85rem; }
        .user-role { font-size: 0.7rem; color: var(--text-muted); text-transform: uppercase; }
        
        .dropdown-menu { position: absolute; top: 125%; right: 0; background: var(--bg-surface); border: 1px solid var(--border); border-radius: var(--radius-md); box-shadow: var(--shadow-md); min-width: 200px; display: none; z-index: 1000; flex-direction: column; overflow: hidden; animation: scaleIn 0.2s ease; transform-origin: top right; }
        @keyframes scaleIn { from { opacity: 0; transform: scale(0.95); } to { opacity: 1; transform: scale(1); } }
        .dropdown-menu.show { display: flex; }
        .dropdown-item { padding: 0.75rem 1rem; display: flex; align-items: center; gap: 0.75rem; font-size: 0.9rem; transition: 0.2s; }
        .dropdown-item:hover { background: var(--bg-body); color: var(--primary); }
        .text-danger { color: var(--danger) !important; }

        /* Container & Cards */
        .container { max-width: 1000px; margin: 0 auto; padding: 2rem 1.5rem 4rem; }
        .page-header { margin-bottom: 2rem; display: flex; justify-content: space-between; align-items: flex-end; flex-wrap: wrap; gap: 1rem; }
        .page-title { font-size: 1.75rem; font-weight: 800; margin: 0; }
        .page-subtitle { color: var(--text-muted); margin-top: 0.5rem; }

        .btn-outline { padding: 0.6rem 1.2rem; background: var(--bg-surface); border: 1px solid var(--border); border-radius: var(--radius-md); font-size: 0.9rem; font-weight: 600; display: inline-flex; align-items: center; gap: 0.5rem; cursor: pointer; transition: 0.2s; color: var(--text-main); }
        .btn-outline:hover { background: var(--bg-body); border-color: var(--primary); color: var(--primary); }

        .card { background: var(--bg-surface); border: 1px solid var(--border); border-radius: var(--radius-lg); padding: 1.5rem; box-shadow: var(--shadow-sm); margin-bottom: 1.5rem; }
        .card-header { margin-bottom: 1.25rem; border-bottom: 1px solid var(--border); padding-bottom: 1rem; display: flex; justify-content: space-between; align-items: center; }
        .card-title { font-size: 1.1rem; font-weight: 700; color: var(--text-main); display: flex; align-items: center; gap: 0.5rem; }

        /* Tabs */
        .tabs-header { display: flex; gap: 1rem; border-bottom: 1px solid var(--border); margin-bottom: 2rem; overflow-x: auto; padding-bottom: 0.5rem; }
        .tab-btn { background: none; border: none; padding: 0.75rem 1.25rem; font-family: inherit; font-size: 0.95rem; font-weight: 600; color: var(--text-muted); cursor: pointer; border-radius: var(--radius-md); transition: 0.2s; white-space: nowrap; display: flex; align-items: center; gap: 0.5rem; }
        .tab-btn:hover { color: var(--primary); background: var(--bg-body); }
        .tab-btn.active { color: var(--primary); background: rgba(37,99,235,0.1); }
        .tab-content { display: none; animation: fadeIn 0.3s ease; }
        .tab-content.active { display: block; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

        /* Form Controls */
        .form-group { margin-bottom: 1.25rem; }
        .form-label { display: block; margin-bottom: 0.5rem; font-weight: 600; font-size: 0.9rem; color: var(--text-main); }
        
        .select-wrapper { position: relative; width: 100%; }
        .select-wrapper select { width: 100%; padding: 0.8rem 1rem; border-radius: var(--radius-md); border: 1px solid var(--border); background: var(--bg-body); color: var(--text-main); appearance: none; cursor: pointer; font-family: inherit; transition: 0.2s; }
        .select-wrapper select:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(37,99,235,0.1); }
        .select-arrow { position: absolute; right: 15px; top: 50%; transform: translateY(-50%); pointer-events: none; font-size: 0.8rem; color: var(--text-muted); }

        .btn-submit { width: 100%; padding: 0.9rem; background: var(--primary); color: white; border: none; border-radius: var(--radius-md); font-weight: 600; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 0.5rem; transition: 0.2s; margin-top: 1.5rem; position: relative; overflow: hidden; }
        .btn-submit:hover { background: var(--primary-dark); transform: translateY(-1px); }
        
        .btn-warning { background: var(--warning); }
        .btn-warning:hover { background: #b45309; }

        /* Toast */
        .toast-container { position: fixed; top: 85px; right: 20px; z-index: 100; display: flex; flex-direction: column; gap: 10px; pointer-events: none; }
        .toast { pointer-events: auto; background: var(--bg-surface); color: var(--text-main); padding: 1rem; border-radius: var(--radius-md); box-shadow: var(--shadow-md); border-left: 4px solid var(--primary); min-width: 250px; animation: slideIn 0.3s forwards; display: flex; align-items: center; gap: 0.75rem; font-size: 0.9rem; }
        .toast.success { border-left-color: var(--success); }
        .toast.error { border-left-color: var(--danger); }
        @keyframes slideIn { from { opacity: 0; transform: translateX(50px); } to { opacity: 1; transform: translateX(0); } }
        
        /* Alerts */
        .alert-info { background: rgba(2, 132, 199, 0.1); color: var(--info); padding: 1rem; border-radius: var(--radius-md); border: 1px solid rgba(2, 132, 199, 0.2); font-size: 0.9rem; margin-bottom: 1.5rem; display: flex; gap: 0.75rem; align-items: flex-start; line-height: 1.4; }
        .alert-warning { background: rgba(217, 119, 6, 0.1); color: var(--warning); border: 1px solid rgba(217, 119, 6, 0.2); }
        html.dark .alert-info { background: rgba(56, 189, 248, 0.1); color: #38bdf8; border-color: rgba(56, 189, 248, 0.2); }
    </style>
</head>
<body>

    <header class="main-header">
        <div class="navbar">
            <a href="<?php echo htmlspecialchars($base_path . 'index.php'); ?>" class="logo">
                <img src="<?php echo htmlspecialchars($base_path . 'uploads/CUPAD LOGO.png'); ?>" alt="Logo">
                <span>CUPAD</span>
            </a>
            <div class="nav-right">
                <a href="<?php echo htmlspecialchars('manage_hierarchy.php'); ?>" class="icon-btn" title="Back to Hierarchy Manage">
                    <i class="fas fa-arrow-left"></i>
                </a>
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
                        <div style="padding: 0.75rem 1rem; font-size: 0.75rem; color:var(--text-muted); font-weight:600">SIGNED IN AS</div>
                        <div style="padding: 0 1rem 0.5rem; font-weight:700"><?php echo htmlspecialchars($username); ?></div>
                        <div style="height:1px; background:var(--border); margin:0"></div>
                        <a href="profile.php" class="dropdown-item"><i class="fas fa-user-circle" style="color:var(--primary)"></i> My Profile</a>
                        <div style="height:1px; background:var(--border); margin:0"></div>
                        <a href="../logout.php" class="dropdown-item text-danger"><i class="fas fa-sign-out-alt"></i> Logout</a>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <div id="toastContainer" class="toast-container"></div>

    <main class="container">
        <!-- Page Header -->
        <div class="page-header">
            <div>
                <h1 class="page-title">Transfer Locations</h1>
                <p class="page-subtitle">Reassign branches to different areas or move areas to new zones.</p>
            </div>
            <div>
                <a href="manage_hierarchy.php" class="btn-outline">
                    <i class="fas fa-sitemap"></i> View Hierarchy
                </a>
            </div>
        </div>

        <!-- Navigation Tabs -->
        <div class="tabs-header">
            <button class="tab-btn active" onclick="openTab('t_branch', this)">
                <i class="fas fa-building"></i> Move Branch
            </button>
            <button class="tab-btn" onclick="openTab('t_area', this)">
                <i class="fas fa-map-marker-alt"></i> Move Area
            </button>
        </div>

        <!-- Tab 1: Move Branch -->
        <div id="t_branch" class="tab-content active">
            <div class="card">
                <div class="card-header">
                    <div class="card-title"><i class="fas fa-exchange-alt" style="color:var(--primary)"></i> Move Branch to another Area</div>
                </div>
                
                <div class="alert-info">
                    <i class="fas fa-info-circle" style="margin-top: 2px;"></i>
                    <span>Transferring a branch will automatically update its mapping in the system. <strong>All staff attached to this branch will instantly reflect the new Area and Zone.</strong></span>
                </div>

                <form method="post">
                    <div class="form-group">
                        <label class="form-label">Select Branch to Move</label>
                        <div class="select-wrapper">
                            <select name="branch_id" required>
                                <option value="" disabled selected>-- Choose a Branch --</option>
                                <?php foreach ($branches as $branch): ?>
                                    <option value="<?php echo htmlspecialchars($branch['id']); ?>">
                                        <?php echo htmlspecialchars($branch['name']); ?> 
                                        (Current: <?php echo htmlspecialchars($branch['zone_name']); ?> / <?php echo htmlspecialchars($branch['area_name']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <i class="fas fa-chevron-down select-arrow"></i>
                        </div>
                    </div>

                    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1.5rem; margin-top: 2rem;">
                        <div class="form-group">
                            <label class="form-label">Target Zone</label>
                            <div class="select-wrapper">
                                <select id="branch_new_zone" name="new_zone_id" required onchange="filterBranchTargetAreas()">
                                    <option value="" disabled selected>-- Select Target Zone --</option>
                                    <?php foreach ($zones as $zone): ?>
                                        <option value="<?php echo htmlspecialchars($zone['id']); ?>"><?php echo htmlspecialchars($zone['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <i class="fas fa-chevron-down select-arrow"></i>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Target Area</label>
                            <div class="select-wrapper">
                                <select id="branch_new_area" name="new_area_id" required>
                                    <option value="" disabled selected>Select Target Zone First</option>
                                    <?php foreach ($areas as $area): ?>
                                        <option value="<?php echo htmlspecialchars($area['id']); ?>" data-zone="<?php echo htmlspecialchars($area['zone_id']); ?>" style="display:none;">
                                            <?php echo htmlspecialchars($area['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <i class="fas fa-chevron-down select-arrow"></i>
                            </div>
                        </div>
                    </div>

                    <button type="submit" name="move_branch" class="btn-submit">
                        <i class="fas fa-dolly"></i> Transfer Branch
                    </button>
                </form>
            </div>
        </div>

        <!-- Tab 2: Move Area -->
        <div id="t_area" class="tab-content">
            <div class="card">
                <div class="card-header">
                    <div class="card-title"><i class="fas fa-layer-group" style="color:var(--warning)"></i> Move Area to another Zone</div>
                </div>

                <div class="alert-info alert-warning">
                    <i class="fas fa-exclamation-triangle" style="margin-top: 2px;"></i>
                    <span><strong>Warning:</strong> Moving an Area to a new Zone will automatically cascade the update to <b>ALL branches and ALL staff (Users)</b> currently working inside that Area.</span>
                </div>

                <form method="post" onsubmit="return confirm('Are you sure you want to move this Area? All underlying branches and staff will also be updated to the new Zone.');">
                    <div class="form-group">
                        <label class="form-label">Select Area to Move</label>
                        <div class="select-wrapper">
                            <select name="area_id" required>
                                <option value="" disabled selected>-- Choose an Area --</option>
                                <?php foreach ($areas as $area): ?>
                                    <option value="<?php echo htmlspecialchars($area['id']); ?>">
                                        <?php echo htmlspecialchars($area['name']); ?> 
                                        (Current Zone: <?php echo htmlspecialchars($area['zone_name']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <i class="fas fa-chevron-down select-arrow"></i>
                        </div>
                    </div>

                    <div class="form-group" style="margin-top: 2rem;">
                        <label class="form-label">Target Zone</label>
                        <div class="select-wrapper">
                            <select name="new_zone_id" required>
                                <option value="" disabled selected>-- Select Target Zone --</option>
                                <?php foreach ($zones as $zone): ?>
                                    <option value="<?php echo htmlspecialchars($zone['id']); ?>"><?php echo htmlspecialchars($zone['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <i class="fas fa-chevron-down select-arrow"></i>
                        </div>
                    </div>

                    <button type="submit" name="move_area" class="btn-submit btn-warning">
                        <i class="fas fa-truck-moving"></i> Transfer Area, Branches & Staff
                    </button>
                </form>
            </div>
        </div>

    </main>

    <script>
        // --- User Dropdown ---
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

        // --- Tabs Logic ---
        function openTab(tabId, btn) {
            document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            document.getElementById(tabId).classList.add('active');
            btn.classList.add('active');
        }

        // --- Theme Toggle ---
        const themeToggle = document.getElementById('themeToggle');
        const themeIcon = themeToggle.querySelector('i');
        const html = document.documentElement;

        function updateThemeIcon() {
            themeIcon.className = html.classList.contains('dark') ? 'fas fa-sun' : 'fas fa-moon';
        }

        themeToggle.addEventListener('click', () => {
            const isDark = html.classList.toggle('dark');
            html.classList.toggle('light');
            localStorage.setItem('theme', isDark ? 'dark' : 'light');
            updateThemeIcon();
        });

        if (localStorage.getItem('theme') === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            html.classList.add('dark');
            html.classList.remove('light');
        }
        updateThemeIcon();

        // --- Toast Logic ---
        function showToast(msg, type = 'info') {
            const container = document.getElementById('toastContainer');
            const div = document.createElement('div');
            div.className = `toast ${type}`;
            let icon = type === 'success' ? 'check-circle' : (type === 'error' ? 'exclamation-circle' : 'info-circle');
            div.innerHTML = `<i class="fas fa-${icon}"></i> ${msg}`;
            container.appendChild(div);
            setTimeout(() => {
                div.style.opacity = '0';
                div.style.transform = 'translateX(50px)';
                setTimeout(() => div.remove(), 300);
            }, 4000);
        }

        <?php if ($notification): ?>
            showToast("<?php echo addslashes($notification['msg']); ?>", "<?php echo $notification['type']; ?>");
        <?php endif; ?>

        // --- Form Filtering (Target Zone -> Target Area) ---
        function filterBranchTargetAreas() {
            const zoneSelect = document.getElementById('branch_new_zone');
            const areaSelect = document.getElementById('branch_new_area');
            const selectedZone = zoneSelect.value;
            
            // Reset area dropdown
            areaSelect.value = ''; 
            
            const options = areaSelect.querySelectorAll('option');
            options.forEach(opt => {
                if (opt.value === '') {
                    opt.style.display = 'block';
                    opt.textContent = selectedZone ? '-- Select Target Area --' : 'Select Target Zone First';
                } else {
                    const optZone = opt.getAttribute('data-zone');
                    if (optZone === selectedZone) {
                        opt.style.display = 'block';
                    } else {
                        opt.style.display = 'none';
                    }
                }
            });
        }

        // Initialize on load
        document.addEventListener('DOMContentLoaded', filterBranchTargetAreas);
    </script>
</body>
</html>