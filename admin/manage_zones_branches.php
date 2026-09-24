<?php
// manage_hierarchy.php
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

function getZoneById($pdo, $zone_id) {
    $stmt = $pdo->prepare("SELECT * FROM zones WHERE id = ?");
    $stmt->execute([$zone_id]);
    return $stmt->fetch();
}

function getAreasByZone($pdo, $zone_id) {
    $stmt = $pdo->prepare("SELECT * FROM areas WHERE zone_id = ? ORDER BY name ASC");
    $stmt->execute([$zone_id]);
    return $stmt->fetchAll();
}

function getBranchesByArea($pdo, $area_id) {
    $stmt = $pdo->prepare("SELECT * FROM branches WHERE area_id = ? ORDER BY name ASC");
    $stmt->execute([$area_id]);
    return $stmt->fetchAll();
}

// --- POST HANDLERS ---

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // 1. Add Zone
    if (isset($_POST['add_zone'])) {
        $zone_name = trim($_POST['zone_name'] ?? '');
        if ($zone_name === '') {
            $notification = ['type' => 'error', 'msg' => 'Zone name required.'];
        } else {
            $zone_id = 'zone_' . uniqid();
            $stmt = $pdo->prepare("INSERT INTO zones (id, name, created_at) VALUES (?, ?, NOW())");
            if ($stmt->execute([$zone_id, $zone_name])) {
                $notification = ['type' => 'success', 'msg' => 'Zone added successfully.'];
            } else {
                $notification = ['type' => 'error', 'msg' => 'Failed to add zone.'];
            }
        }
    } 
    
    // 2. Add Area
    elseif (isset($_POST['add_area'])) {
        $area_name = trim($_POST['area_name'] ?? '');
        $zone_id = $_POST['zone_id'] ?? '';
        
        if ($area_name === '' || $zone_id === '') {
            $notification = ['type' => 'error', 'msg' => 'Area name and zone required.'];
        } elseif (!getZoneById($pdo, $zone_id)) {
            $notification = ['type' => 'error', 'msg' => 'Selected zone does not exist.'];
        } else {
            $area_id = 'area_' . uniqid();
            $stmt = $pdo->prepare("INSERT INTO areas (id, name, zone_id, created_at) VALUES (?, ?, ?, NOW())");
            if ($stmt->execute([$area_id, $area_name, $zone_id])) {
                $notification = ['type' => 'success', 'msg' => 'Area added successfully.'];
            } else {
                $notification = ['type' => 'error', 'msg' => 'Failed to add area.'];
            }
        }
    } 
    
    // 3. Add Branch
    elseif (isset($_POST['add_branch'])) {
        $branch_name = trim($_POST['branch_name'] ?? '');
        $area_id = $_POST['area_id'] ?? '';
        $zone_id = $_POST['zone_id'] ?? '';
        
        if ($branch_name === '' || $area_id === '' || $zone_id === '') {
            $notification = ['type' => 'error', 'msg' => 'All fields are required.'];
        } else {
            // Verify area belongs to zone
            $area = getAreasByZone($pdo, $zone_id);
            $areaValid = false;
            foreach ($area as $a) {
                if ($a['id'] === $area_id) {
                    $areaValid = true;
                    break;
                }
            }
            
            if (!$areaValid) {
                $notification = ['type' => 'error', 'msg' => 'Selected area does not belong to the selected zone.'];
            } else {
                $branch_id = 'branch_' . uniqid();
                $stmt = $pdo->prepare("INSERT INTO branches (id, name, zone_id, area_id, status, created_at) VALUES (?, ?, ?, ?, 'active', NOW())");
                if ($stmt->execute([$branch_id, $branch_name, $zone_id, $area_id])) {
                    $notification = ['type' => 'success', 'msg' => 'Branch added successfully.'];
                } else {
                    $notification = ['type' => 'error', 'msg' => 'Failed to add branch.'];
                }
            }
        }
    } 
    
    // 4. Delete Zone (and cascade)
    elseif (isset($_POST['delete_zone'])) {
        $zone_id = $_POST['zone_id'] ?? '';
        
        try {
            $pdo->beginTransaction();
            
            // Delete branches in this zone
            $stmt = $pdo->prepare("DELETE FROM branches WHERE zone_id = ?");
            $stmt->execute([$zone_id]);
            
            // Delete areas in this zone
            $stmt = $pdo->prepare("DELETE FROM areas WHERE zone_id = ?");
            $stmt->execute([$zone_id]);
            
            // Delete zone
            $stmt = $pdo->prepare("DELETE FROM zones WHERE id = ?");
            $stmt->execute([$zone_id]);
            
            $pdo->commit();
            $notification = ['type' => 'success', 'msg' => 'Zone and all dependencies deleted.'];
        } catch (Exception $e) {
            $pdo->rollBack();
            $notification = ['type' => 'error', 'msg' => 'Failed to delete zone: ' . $e->getMessage()];
        }
    } 
    
    // 5. Delete Area (and cascade)
    elseif (isset($_POST['delete_area'])) {
        $area_id = $_POST['area_id'] ?? '';
        
        try {
            $pdo->beginTransaction();
            
            // Delete branches in this area
            $stmt = $pdo->prepare("DELETE FROM branches WHERE area_id = ?");
            $stmt->execute([$area_id]);
            
            // Delete area
            $stmt = $pdo->prepare("DELETE FROM areas WHERE id = ?");
            $stmt->execute([$area_id]);
            
            $pdo->commit();
            $notification = ['type' => 'success', 'msg' => 'Area and all branches deleted.'];
        } catch (Exception $e) {
            $pdo->rollBack();
            $notification = ['type' => 'error', 'msg' => 'Failed to delete area: ' . $e->getMessage()];
        }
    } 
    
    // 6. Delete Branch
    elseif (isset($_POST['delete_branch'])) {
        $branch_id = $_POST['branch_id'] ?? '';
        
        $stmt = $pdo->prepare("DELETE FROM branches WHERE id = ?");
        if ($stmt->execute([$branch_id])) {
            $notification = ['type' => 'success', 'msg' => 'Branch deleted successfully.'];
        } else {
            $notification = ['type' => 'error', 'msg' => 'Failed to delete branch.'];
        }
    }
}

// Load all data
$zones = getAllZones($pdo);
$areas = getAllAreas($pdo);
$branches = getAllBranches($pdo);

// Helper for UI Profile
$full_name = $_SESSION['full_name'] ?? 'Admin';
$role = $_SESSION['user_role'] ?? 'Admin';
?>
<!DOCTYPE html>
<html lang="en" class="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Hierarchy - CUPAD</title>
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
            --success: #34d399; --danger: #f87171;
        }

        * { box-sizing: border-box; outline: none; }
        body { font-family: 'Plus Jakarta Sans', sans-serif; background: var(--bg-body); color: var(--text-main); margin: 0; padding-top: var(--nav-height); transition: background-color 0.3s, color 0.3s; }
        a { text-decoration: none; color: inherit; }

        /* Header */
        .main-header { position: fixed; top: 0; left: 0; right: 0; height: var(--nav-height); background: rgba(255,255,255,0.85); backdrop-filter: blur(12px); border-bottom: 1px solid var(--border); z-index: 50; transition: background 0.3s; }
        html.dark .main-header { background: rgba(15, 23, 42, 0.85); }
        .navbar { max-width: 1400px; margin: 0 auto; padding: 0 1.5rem; display: flex; align-items: center; justify-content: space-between; height: 100%; }
        .logo { display: flex; align-items: center; gap: 0.75rem; font-weight: 800; font-size: 1.35rem; color: var(--primary); }
        .nav-right { display: flex; align-items: center; gap: 1rem; }
        .icon-btn { width: 36px; height: 36px; border-radius: 50%; border: none; background: transparent; color: var(--text-muted); cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; transition: 0.2s; }
        .icon-btn:hover { background: var(--bg-body); color: var(--text-main); }

        /* Container & Cards */
        .container { max-width: 1200px; margin: 0 auto; padding: 2rem 1.5rem 4rem; }
        .page-header { margin-bottom: 2rem; }
        .page-title { font-size: 1.75rem; font-weight: 800; margin: 0; }
        .page-subtitle { color: var(--text-muted); margin-top: 0.5rem; }

        .card { background: var(--bg-surface); border: 1px solid var(--border); border-radius: var(--radius-lg); padding: 1.5rem; box-shadow: var(--shadow-sm); margin-bottom: 1.5rem; }
        .card-header { margin-bottom: 1.25rem; border-bottom: 1px solid var(--border); padding-bottom: 1rem; display: flex; justify-content: space-between; align-items: center; }
        .card-title { font-size: 1.1rem; font-weight: 700; color: var(--text-main); display: flex; align-items: center; gap: 0.5rem; }

        /* Stats Grid */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 2rem; }
        .stat-card { background: var(--bg-surface); padding: 1.5rem; border-radius: var(--radius-lg); border: 1px solid var(--border); display: flex; flex-direction: column; position: relative; overflow: hidden; }
        .stat-value { font-size: 2rem; font-weight: 800; color: var(--text-main); line-height: 1; margin-bottom: 0.5rem; }
        .stat-label { font-size: 0.85rem; font-weight: 600; text-transform: uppercase; color: var(--text-muted); }
        .stat-icon { position: absolute; right: -10px; bottom: -10px; font-size: 5rem; opacity: 0.05; transform: rotate(-15deg); color: var(--text-main); }
        
        .stat-card.blue { border-left: 4px solid var(--primary); }
        .stat-card.green { border-left: 4px solid var(--success); }
        .stat-card.orange { border-left: 4px solid var(--warning); }

        /* Tabs */
        .tabs-header { display: flex; gap: 1rem; border-bottom: 1px solid var(--border); margin-bottom: 2rem; overflow-x: auto; padding-bottom: 0.5rem; }
        .tab-btn { background: none; border: none; padding: 0.75rem 1.25rem; font-family: inherit; font-size: 0.95rem; font-weight: 600; color: var(--text-muted); cursor: pointer; border-radius: var(--radius-md); transition: 0.2s; white-space: nowrap; display: flex; align-items: center; gap: 0.5rem; }
        .tab-btn:hover { color: var(--primary); background: var(--bg-body); }
        .tab-btn.active { color: white; background: var(--primary); box-shadow: 0 4px 12px rgba(37, 99, 235, 0.2); }
        .tab-content { display: none; animation: fadeIn 0.3s ease; }
        .tab-content.active { display: block; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

        /* Forms */
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 2rem; }
        .form-group { margin-bottom: 1.25rem; }
        .form-label { display: block; margin-bottom: 0.5rem; font-weight: 600; font-size: 0.9rem; color: var(--text-main); }
        .form-input, .form-select { width: 100%; padding: 0.8rem 1rem; border-radius: var(--radius-md); border: 1px solid var(--border); background: var(--bg-body); color: var(--text-main); font-family: inherit; transition: 0.2s; }
        .form-input:focus, .form-select:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(37,99,235,0.1); }
        
        .btn-submit { width: 100%; padding: 0.9rem; background: var(--primary); color: white; border: none; border-radius: var(--radius-md); font-weight: 600; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 0.5rem; transition: 0.2s; }
        .btn-submit:hover { background: var(--primary-dark); transform: translateY(-1px); }

        /* Lists */
        .item-list { list-style: none; padding: 0; margin: 0; }
        .list-item { display: flex; justify-content: space-between; align-items: center; padding: 0.75rem 0; border-bottom: 1px dashed var(--border); }
        .list-item:last-child { border-bottom: none; }
        .item-name { font-weight: 500; color: var(--text-main); display: flex; align-items: center; gap: 0.75rem; }
        .item-badge { font-size: 0.7rem; padding: 2px 8px; border-radius: 99px; background: var(--bg-body); color: var(--text-muted); border: 1px solid var(--border); }
        
        .btn-delete { background: rgba(220, 38, 38, 0.1); color: var(--danger); border: none; padding: 0.4rem 0.8rem; border-radius: 6px; font-size: 0.8rem; cursor: pointer; transition: 0.2s; font-weight: 600; }
        .btn-delete:hover { background: var(--danger); color: white; }

        /* Toast */
        .toast-container { position: fixed; top: 85px; right: 20px; z-index: 100; display: flex; flex-direction: column; gap: 10px; pointer-events: none; }
        .toast { pointer-events: auto; background: var(--bg-surface); color: var(--text-main); padding: 1rem; border-radius: var(--radius-md); box-shadow: var(--shadow-md); border-left: 4px solid var(--primary); min-width: 250px; animation: slideIn 0.3s forwards; display: flex; align-items: center; gap: 0.75rem; font-size: 0.9rem; }
        .toast.success { border-left-color: var(--success); }
        .toast.error { border-left-color: var(--danger); }
        @keyframes slideIn { from { opacity: 0; transform: translateX(50px); } to { opacity: 1; transform: translateX(0); } }

        /* Tree View */
        .tree-node { margin-left: 1.5rem; border-left: 1px solid var(--border); padding-left: 1rem; margin-bottom: 0.5rem; position: relative; }
        .tree-node::before { content: ''; position: absolute; left: 0; top: 12px; width: 10px; height: 1px; background: var(--border); }
        .tree-header { display: flex; align-items: center; gap: 0.5rem; font-weight: 600; padding: 0.5rem 0; color: var(--text-main); }
        .tree-icon { color: var(--primary); font-size: 0.9rem; }
    </style>
</head>
<body>

    <header class="main-header">
        <div class="navbar">
            <a href="<?php echo htmlspecialchars($base_path . 'index.php'); ?>" class="logo">
                <i class="fas fa-cubes-stacked"></i>
                <span>CUPAD</span>
            </a>
            <div class="nav-right">
                <a href="<?php echo htmlspecialchars($base_path . 'admin/dashboard.php'); ?>" class="icon-btn" title="Back to Dashboard">
                    <i class="fas fa-arrow-left"></i>
                </a>
                <button class="icon-btn" id="themeToggle"><i class="fas fa-moon"></i></button>
                <div style="display:flex;align-items:center;gap:0.5rem;padding:5px 10px;border-radius:99px;background:var(--bg-body);border:1px solid var(--border);">
                    <i class="fas fa-user-circle" style="color:var(--text-muted)"></i>
                    <span style="font-size:0.85rem;font-weight:600"><?php echo htmlspecialchars($full_name); ?></span>
                </div>
            </div>
        </div>
    </header>

    <div id="toastContainer" class="toast-container"></div>

    <main class="container">
        <!-- Page Header -->
        <div class="page-header">
            <h1 class="page-title">Structure Management</h1>
            <p class="page-subtitle">Configure Zones, Areas, and Branches for the organization.</p>
        </div>

        <!-- Stats Overview -->
        <div class="stats-grid">
            <div class="stat-card blue">
                <i class="fas fa-map stat-icon"></i>
                <div class="stat-value"><?php echo count($zones); ?></div>
                <div class="stat-label">Total Zones</div>
            </div>
            <div class="stat-card green">
                <i class="fas fa-map-marker-alt stat-icon"></i>
                <div class="stat-value"><?php echo count($areas); ?></div>
                <div class="stat-label">Total Areas</div>
            </div>
            <div class="stat-card orange">
                <i class="fas fa-building stat-icon"></i>
                <div class="stat-value"><?php echo count($branches); ?></div>
                <div class="stat-label">Active Branches</div>
            </div>
        </div>

        <!-- Navigation Tabs -->
        <div class="tabs-header">
            <button class="tab-btn active" onclick="openTab('t_view', this)">
                <i class="fas fa-sitemap"></i> View Structure
            </button>
            <button class="tab-btn" onclick="openTab('t_add', this)">
                <i class="fas fa-plus-circle"></i> Add Data
            </button>
            <button class="tab-btn" onclick="openTab('t_manage', this)">
                <i class="fas fa-edit"></i> Manage & Delete
            </button>
        </div>

        <!-- Tab 1: View Structure -->
        <div id="t_view" class="tab-content active">
            <div class="card">
                <div class="card-header">
                    <div class="card-title"><i class="fas fa-project-diagram" style="color:var(--primary)"></i> Organization Hierarchy</div>
                </div>
                <?php if (empty($zones)): ?>
                    <div style="text-align:center; padding:2rem; color:var(--text-muted);">
                        <i class="fas fa-folder-open" style="font-size:2rem; margin-bottom:1rem; opacity:0.5;"></i>
                        <p>No hierarchy data found. Go to 'Add Data' to start.</p>
                    </div>
                <?php else: ?>
                    <div style="padding: 0.5rem;">
                        <?php foreach ($zones as $zone): 
                            $zoneAreas = getAreasByZone($pdo, $zone['id']);
                        ?>
                            <div class="tree-header" style="font-size:1.1rem; border-bottom:1px solid var(--border); margin-top:1rem;">
                                <i class="fas fa-map tree-icon"></i> <?php echo htmlspecialchars($zone['name']); ?>
                            </div>
                            
                            <?php if (empty($zoneAreas)): ?>
                                <div class="tree-node" style="color:var(--text-muted); font-size:0.9rem;">No areas</div>
                            <?php else: foreach ($zoneAreas as $area): 
                                $areaBranches = getBranchesByArea($pdo, $area['id']);
                            ?>
                                <div class="tree-node">
                                    <div class="tree-header">
                                        <i class="fas fa-map-marker-alt tree-icon" style="color:var(--success)"></i> <?php echo htmlspecialchars($area['name']); ?>
                                    </div>
                                    
                                    <?php if (empty($areaBranches)): ?>
                                        <div class="tree-node" style="color:var(--text-muted); font-size:0.85rem;">No branches</div>
                                    <?php else: foreach ($areaBranches as $branch): ?>
                                        <div class="tree-node" style="display:flex; align-items:center; gap:0.5rem;">
                                            <i class="fas fa-building" style="color:var(--text-muted); font-size:0.8rem;"></i>
                                            <span><?php echo htmlspecialchars($branch['name']); ?></span>
                                        </div>
                                    <?php endforeach; endif; ?>
                                </div>
                            <?php endforeach; endif; ?>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Tab 2: Add Data -->
        <div id="t_add" class="tab-content">
            <div class="form-grid">
                <!-- Add Zone -->
                <div class="card">
                    <div class="card-header">
                        <div class="card-title"><i class="fas fa-map"></i> New Zone</div>
                    </div>
                    <form method="post">
                        <div class="form-group">
                            <label class="form-label">Zone Name</label>
                            <input type="text" name="zone_name" placeholder="e.g., South West" class="form-input" required>
                        </div>
                        <button type="submit" name="add_zone" class="btn-submit">
                            <i class="fas fa-plus"></i> Create Zone
                        </button>
                    </form>
                </div>

                <!-- Add Area -->
                <div class="card">
                    <div class="card-header">
                        <div class="card-title"><i class="fas fa-map-marker-alt"></i> New Area</div>
                    </div>
                    <form method="post">
                        <div class="form-group">
                            <label class="form-label">Parent Zone</label>
                            <select name="zone_id" class="form-select" required>
                                <option value="">Select Zone</option>
                                <?php foreach ($zones as $zone): ?>
                                    <option value="<?php echo htmlspecialchars($zone['id']); ?>"><?php echo htmlspecialchars($zone['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Area Name</label>
                            <input type="text" name="area_name" placeholder="e.g., Lagos Mainland" class="form-input" required>
                        </div>
                        <button type="submit" name="add_area" class="btn-submit">
                            <i class="fas fa-plus"></i> Create Area
                        </button>
                    </form>
                </div>

                <!-- Add Branch -->
                <div class="card">
                    <div class="card-header">
                        <div class="card-title"><i class="fas fa-building"></i> New Branch</div>
                    </div>
                    <form method="post">
                        <div class="form-group">
                            <label class="form-label">Select Zone</label>
                            <select id="zone_select" name="zone_id" class="form-select" required onchange="filterAreas()">
                                <option value="">Select Zone</option>
                                <?php foreach ($zones as $zone): ?>
                                    <option value="<?php echo htmlspecialchars($zone['id']); ?>"><?php echo htmlspecialchars($zone['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Select Area</label>
                            <select id="area_select" name="area_id" class="form-select" required>
                                <option value="">Select Zone First</option>
                                <?php foreach ($areas as $area): ?>
                                    <option value="<?php echo htmlspecialchars($area['id']); ?>" data-zone="<?php echo htmlspecialchars($area['zone_id']); ?>" style="display:none;">
                                        <?php echo htmlspecialchars($area['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Branch Name</label>
                            <input type="text" name="branch_name" placeholder="e.g., Yaba Branch" class="form-input" required>
                        </div>
                        <button type="submit" name="add_branch" class="btn-submit">
                            <i class="fas fa-plus"></i> Create Branch
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Tab 3: Manage/Delete -->
        <div id="t_manage" class="tab-content">
            <div class="form-grid">
                
                <!-- Manage Zones -->
                <div class="card">
                    <div class="card-header">
                        <div class="card-title"><i class="fas fa-map"></i> Zones</div>
                    </div>
                    <ul class="item-list">
                        <?php foreach ($zones as $zone): ?>
                            <li class="list-item">
                                <span class="item-name"><?php echo htmlspecialchars($zone['name']); ?></span>
                                <form method="post" onsubmit="return confirm('Deleting this Zone will delete ALL its areas and branches. Continue?');">
                                    <input type="hidden" name="zone_id" value="<?php echo htmlspecialchars($zone['id']); ?>">
                                    <button type="submit" name="delete_zone" class="btn-delete"><i class="fas fa-trash"></i> Delete</button>
                                </form>
                            </li>
                        <?php endforeach; if(empty($zones)) echo "<li class='list-item text-muted'>No zones.</li>"; ?>
                    </ul>
                </div>

                <!-- Manage Areas -->
                <div class="card">
                    <div class="card-header">
                        <div class="card-title"><i class="fas fa-map-marker-alt"></i> Areas</div>
                    </div>
                    <ul class="item-list">
                        <?php foreach ($areas as $area): ?>
                            <li class="list-item">
                                <div>
                                    <div class="item-name"><?php echo htmlspecialchars($area['name']); ?></div>
                                    <span class="item-badge"><?php echo htmlspecialchars($area['zone_name'] ?? 'Unknown Zone'); ?></span>
                                </div>
                                <form method="post" onsubmit="return confirm('Deleting this Area will delete ALL its branches. Continue?');">
                                    <input type="hidden" name="area_id" value="<?php echo htmlspecialchars($area['id']); ?>">
                                    <button type="submit" name="delete_area" class="btn-delete"><i class="fas fa-trash"></i></button>
                                </form>
                            </li>
                        <?php endforeach; if(empty($areas)) echo "<li class='list-item text-muted'>No areas.</li>"; ?>
                    </ul>
                </div>

                <!-- Manage Branches -->
                <div class="card">
                    <div class="card-header">
                        <div class="card-title"><i class="fas fa-building"></i> Branches</div>
                    </div>
                    <div style="max-height: 400px; overflow-y: auto;">
                        <ul class="item-list">
                            <?php foreach ($branches as $branch): ?>
                                <li class="list-item">
                                    <div>
                                        <div class="item-name"><?php echo htmlspecialchars($branch['name']); ?></div>
                                        <span class="item-badge"><?php echo htmlspecialchars($branch['area_name'] ?? 'Unknown Area'); ?></span>
                                    </div>
                                    <form method="post" onsubmit="return confirm('Delete this branch?');">
                                        <input type="hidden" name="branch_id" value="<?php echo htmlspecialchars($branch['id']); ?>">
                                        <button type="submit" name="delete_branch" class="btn-delete"><i class="fas fa-trash"></i></button>
                                    </form>
                                </li>
                            <?php endforeach; if(empty($branches)) echo "<li class='list-item text-muted'>No branches.</li>"; ?>
                        </ul>
                    </div>
                </div>

            </div>
        </div>

    </main>

    <script>
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
            html.classList.toggle('dark');
            localStorage.setItem('theme', html.classList.contains('dark') ? 'dark' : 'light');
            updateThemeIcon();
        });

        // Init Theme
        if (localStorage.getItem('theme') === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            html.classList.add('dark');
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

        // PHP Toast Injection
        <?php if ($notification): ?>
            showToast("<?php echo addslashes($notification['msg']); ?>", "<?php echo $notification['type']; ?>");
        <?php endif; ?>

        // --- Form Filtering (Zone -> Area) ---
        function filterAreas() {
            const zoneSelect = document.getElementById('zone_select');
            const areaSelect = document.getElementById('area_select');
            const selectedZone = zoneSelect.value;
            
            // Reset area select
            areaSelect.value = '';
            
            // Show/hide options based on zone
            const options = areaSelect.querySelectorAll('option');
            options.forEach(opt => {
                if (opt.value === '') {
                    opt.style.display = 'block';
                    opt.textContent = selectedZone ? 'Select Area' : 'Select Zone First';
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
        document.addEventListener('DOMContentLoaded', filterAreas);
    </script>
</body>
</html>