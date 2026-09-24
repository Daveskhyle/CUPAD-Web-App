<?php
// =================================================================
// 1. BACKEND LOGIC & CONFIGURATION
// =================================================================
date_default_timezone_set('Africa/Lagos');
session_start();

// --- Database Connection ---
$db_host = 'localhost';
$db_name = 'cupad_db'; // UPDATE THIS
$db_user = 'root';     // UPDATE THIS
$db_pass = '';         // UPDATE THIS

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        echo json_encode(['status' => 'error', 'message' => "Database Connection Failed: " . $e->getMessage()]);
        exit;
    }
    die("Database Connection Failed.");
}

// --- Security Check ---
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    // header('Location: ../index.php'); // Uncomment in production
    // Ensure critical logic doesn't run if not authorized
}

$base_path = '../'; 
$username = $_SESSION['username'] ?? '';
$user_id = $_SESSION['user_id'] ?? 0;

// --- Fetch User Details (Header) ---
$full_name = 'Admin';
$role = 'admin';
$profile_pic_path = '';
$has_profile_pic = false;

if ($username) {
    $stmt = $pdo->prepare("SELECT full_name, role, profile_pic FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user) {
        $full_name = $user['full_name'] ?? $username;
        $role = $user['role'];
        $pic = $user['profile_pic'];
        
        // Handle profile pic path
        if ($pic && $pic !== 'default_avatar.png') {
            $check_path = file_exists($base_path . $pic) ? $base_path . $pic : $base_path . 'uploads/' . $pic;
            if (file_exists($check_path)) {
                $profile_pic_path = $check_path;
                $has_profile_pic = true;
            }
        }
    }
}

// --- Fetch Upload Settings (For Optimization Thresholds) ---
$max_upload_size_mb = 5;
try {
    $stmtSettings = $pdo->query("SELECT max_file_size_mb FROM file_upload_settings LIMIT 1");
    $res = $stmtSettings->fetch();
    if ($res) $max_upload_size_mb = $res['max_file_size_mb'];
} catch (Exception $e) { /* use default */ }


// --- Check GD Library ---
if (!extension_loaded('gd')) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'GD extension missing in php.ini']);
        exit;
    }
}

// --- Handle AJAX Compression Requests ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty(file_get_contents('php://input'))) {
    header('Content-Type: application/json');

    $rawInput = file_get_contents('php://input');
    $inputData = json_decode($rawInput, true);
    
    // Determine target subfolder
    $subfolder = $inputData['subfolder'] ?? '';
    $is_disbursement = isset($inputData['disbursement_mode']) && $inputData['disbursement_mode'] === true;
    
    if ($is_disbursement) $subfolder = 'disbursements';

    // Sanitize
    $subfolder = str_replace(['..', '\\'], '', $subfolder);
    $subfolder = preg_replace('/[^a-zA-Z0-9_\-\/]/', '', $subfolder);
    $subfolder = trim($subfolder, "/");

    $upload_dir = '../uploads/' . ($subfolder ? $subfolder . '/' : '');
    
    if (!is_dir($upload_dir)) {
        echo json_encode(['status' => 'error', 'message' => "Directory not found: $upload_dir"]);
        exit;
    }

    $response = [
        'status' => 'success',
        'processed' => 0,
        'total_size_before' => 0,
        'total_size_after' => 0,
        'files' => []
    ];
    
    try {
        // Backup Dir
        $backup_dir = $upload_dir . 'backups/';
        if (!is_dir($backup_dir)) mkdir($backup_dir, 0777, true);
        
        $files = glob($upload_dir . '*.{jpg,jpeg,png,gif,webp}', GLOB_BRACE);
        
        if (empty($files)) {
            echo json_encode(['status' => 'error', 'message' => 'No images found.']);
            exit;
        }
        
        foreach ($files as $file) {
            $size_before = filesize($file);
            $response['total_size_before'] += $size_before;
            
            // Backup
            $backup_file = $backup_dir . basename($file);
            if (!file_exists($backup_file)) copy($file, $backup_file);
            
            // Settings
            $quality = $inputData['quality'] ?? 75;
            
            // Compression Logic
            $info = getimagesize($file);
            if ($info) {
                $mime = $info['mime'];
                $image = null;
                
                switch ($mime) {
                    case 'image/jpeg': $image = imagecreatefromjpeg($file); break;
                    case 'image/png':  $image = imagecreatefrompng($file); break;
                    case 'image/gif':  $image = imagecreatefromgif($file); break;
                    case 'image/webp': $image = imagecreatefromwebp($file); break;
                }

                if ($image) {
                    // Resize logic (using GD)
                    // If disbursements, enforce max width from settings or static default
                    if ($is_disbursement) {
                        $max_w = 1200; $max_h = 1200;
                        $w = imagesx($image); $h = imagesy($image);
                        if ($w > $max_w || $h > $max_h) {
                            $ratio = min($max_w/$w, $max_h/$h);
                            $new_w = round($w * $ratio);
                            $new_h = round($h * $ratio);
                            $new_img = imagecreatetruecolor($new_w, $new_h);
                            
                            if ($mime == 'image/png' || $mime == 'image/gif') {
                                imagealphablending($new_img, false);
                                imagesavealpha($new_img, true);
                            }
                            imagecopyresampled($new_img, $image, 0, 0, 0, 0, $new_w, $new_h, $w, $h);
                            imagedestroy($image);
                            $image = $new_img;
                        }
                    }

                    // Save
                    switch ($mime) {
                        case 'image/jpeg': imagejpeg($image, $file, $quality); break;
                        case 'image/png':  imagepng($image, $file, 9 - round(($quality/100)*9)); break;
                        case 'image/gif':  imagegif($image, $file); break;
                        case 'image/webp': imagewebp($image, $file, $quality); break;
                    }
                    imagedestroy($image);
                }
            }
            
            clearstatcache();
            $size_after = filesize($file);
            $response['total_size_after'] += $size_after;
            $response['processed']++;
            
            $response['files'][] = [
                'name' => basename($file),
                'saved' => $size_before - $size_after
            ];
        }
        
        $response['savings'] = $response['total_size_before'] - $response['total_size_after'];
        $response['overall_compression'] = $response['total_size_before'] > 0 
            ? round(($response['savings'] / $response['total_size_before']) * 100, 1) : 0;

        // --- Log to Audit Log (Schema Requirement) ---
        if ($response['processed'] > 0) {
            $logDetails = json_encode([
                'folder' => $subfolder,
                'files_processed' => $response['processed'],
                'saved_bytes' => $response['savings'],
                'quality' => $inputData['quality'] ?? 75
            ]);
            
            $auditSql = "INSERT INTO audit_log (user_id, username, action, table_name, details, ip_address) 
                         VALUES (?, ?, 'COMPRESS', 'system_files', ?, ?)";
            $stmtLog = $pdo->prepare($auditSql);
            $stmtLog->execute([$user_id, $username, $logDetails, $_SERVER['REMOTE_ADDR']]);
        }
            
    } catch (Exception $e) {
        $response = ['status' => 'error', 'message' => $e->getMessage()];
    }
    
    echo json_encode($response);
    exit;
}

// Initial Stats Calculation for View
function getDirStats($dir) {
    if (!is_dir($dir)) return ['count' => 0, 'size' => 0];
    $files = glob($dir . '*.*');
    $size = 0;
    if ($files) {
        foreach($files as $f) $size += filesize($f);
    }
    return ['count' => count($files), 'size' => $size];
}

$root_stats = getDirStats('../uploads/');
$disb_stats = getDirStats('../uploads/disbursements/');

// Get subfolders for dropdown
$subfolders = [];
if(is_dir('../uploads/')) {
    foreach(scandir('../uploads/') as $f) {
        if($f !== '.' && $f !== '..' && is_dir("../uploads/$f")) $subfolders[] = $f;
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Compress Uploads | CUPAD Admin</title>
    <link rel="icon" type="image/png" href="<?php echo htmlspecialchars($base_path . 'uploads/CUPAD LOGO.png'); ?>">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>
        /* --- CORE DASHBOARD VARIABLES --- */
        :root {
            --primary: #2563eb; 
            --primary-dark: #1d4ed8;
            --success: #059669;
            --warning: #d97706; 
            --danger: #dc2626; 
            --info: #0284c7;
            
            --bg-body: #f1f5f9; 
            --bg-surface: #ffffff;
            
            --text-main: #0f172a; 
            --text-muted: #64748b;
            
            --border: #e2e8f0; 
            --radius-lg: 16px; 
            --radius-md: 10px;
            
            --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
            --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1);
            --nav-height: 70px;
        }

        html.dark {
            --bg-body: #0f172a; 
            --bg-surface: #1e293b;
            --text-main: #f8fafc; 
            --text-muted: #94a3b8;
            --border: #334155;
            --primary: #3b82f6; 
        }

        * { box-sizing: border-box; outline: none; }
        body { font-family: 'Plus Jakarta Sans', sans-serif; background: var(--bg-body); color: var(--text-main); margin: 0; padding-top: var(--nav-height); transition: 0.3s; }
        a { text-decoration: none; color: inherit; }

        /* HEADER */
        .main-header { position: fixed; top: 0; left: 0; right: 0; height: var(--nav-height); background: rgba(255,255,255,0.85); backdrop-filter: blur(10px); border-bottom: 1px solid var(--border); z-index: 50; }
        html.dark .main-header { background: rgba(15, 23, 42, 0.85); }
        .navbar { max-width: 1400px; margin: 0 auto; padding: 0 1.5rem; display: flex; align-items: center; justify-content: space-between; height: 100%; }
        .logo { display: flex; align-items: center; gap: 0.75rem; font-weight: 800; font-size: 1.35rem; color: var(--primary); }
        .logo img { height: 38px; }
        .nav-right { display: flex; align-items: center; gap: 1rem; }
        
        .icon-btn { width: 36px; height: 36px; border-radius: 50%; border: none; background: transparent; color: var(--text-muted); cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; transition: 0.2s; }
        .icon-btn:hover { background: var(--bg-body); color: var(--text-main); }

        /* USER DROPDOWN */
        .user-dropdown-wrap { position: relative; }
        .user-pill { 
            display: flex; align-items: center; gap: 0.75rem; padding: 4px 8px 4px 4px; 
            border: 1px solid var(--border); border-radius: 99px; background: var(--bg-surface); 
            cursor: pointer; transition: all 0.2s;
        }
        .user-pill:hover { border-color: var(--primary); }
        .user-avatar { width: 34px; height: 34px; border-radius: 50%; object-fit: cover; }
        .user-avatar-fallback { width: 34px; height: 34px; border-radius: 50%; background: var(--bg-body); color: var(--text-muted); display: flex; align-items: center; justify-content: center; font-size: 1rem; border: 1px solid var(--border); }
        .user-info { display: flex; flex-direction: column; line-height: 1.1; padding-right: 0.5rem; }
        .user-name { font-weight: 600; font-size: 0.85rem; }
        .user-role { font-size: 0.7rem; color: var(--text-muted); text-transform: uppercase; }
        .user-dropdown-icon { margin-right: 0.5rem; color: var(--text-muted); font-size: 0.7rem; }

        .dropdown-menu {
            position: absolute; top: 120%; right: 0;
            background: var(--bg-surface); border: 1px solid var(--border);
            border-radius: var(--radius-md); box-shadow: var(--shadow-md);
            min-width: 200px; display: none; z-index: 1000;
            flex-direction: column; overflow: hidden;
            animation: fadeIn 0.2s ease;
        }
        .dropdown-menu.show { display: flex; }
        .dropdown-item {
            padding: 0.75rem 1rem; display: flex; align-items: center; gap: 0.75rem;
            font-size: 0.9rem; color: var(--text-main); transition: 0.2s;
        }
        .dropdown-item:hover { background: var(--bg-body); color: var(--primary); }
        .dropdown-divider { height: 1px; background: var(--border); margin: 0; }
        .text-danger { color: var(--danger) !important; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(5px); } to { opacity: 1; transform: translateY(0); } }

        /* CONTAINER */
        .container { max-width: 1400px; margin: 0 auto; padding: 2rem 1.5rem 4rem; }
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; }
        .page-title h1 { margin: 0; font-size: 1.5rem; font-weight: 700; }
        .page-title p { margin: 0.25rem 0 0; color: var(--text-muted); font-size: 0.9rem; }

        /* STATS GRID */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1rem; margin-bottom: 2rem; }
        .stat-card { background: var(--bg-surface); padding: 1.5rem; border-radius: var(--radius-lg); border: 1px solid var(--border); box-shadow: var(--shadow-sm); display: flex; flex-direction: column; transition: 0.2s; }
        .stat-card:hover { transform: translateY(-3px); box-shadow: var(--shadow-md); border-color: var(--primary); }
        .stat-icon { width: 48px; height: 48px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.25rem; margin-bottom: 1rem; }
        .stat-value { font-size: 1.75rem; font-weight: 800; line-height: 1; margin-bottom: 0.25rem; }
        .stat-label { font-size: 0.85rem; color: var(--text-muted); font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }

        /* COLORS */
        .bg-blue { background: rgba(37, 99, 235, 0.1); color: var(--primary); }
        .bg-green { background: rgba(5, 150, 105, 0.1); color: var(--success); }
        .bg-orange { background: rgba(217, 119, 6, 0.1); color: var(--warning); }
        .bg-rose { background: rgba(220, 38, 38, 0.1); color: var(--danger); }

        /* CONTROL CARD */
        .card { background: var(--bg-surface); border: 1px solid var(--border); border-radius: var(--radius-lg); padding: 1.5rem; margin-bottom: 1.5rem; box-shadow: var(--shadow-sm); }
        .control-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 1.5rem; }
        @media(max-width: 900px) { .control-grid { grid-template-columns: 1fr; } }
        
        .control-header { margin-bottom: 1.5rem; border-bottom: 1px solid var(--border); padding-bottom: 1rem; }
        .control-header h2 { margin: 0; font-size: 1.1rem; font-weight: 700; display: flex; align-items: center; gap: 0.5rem; }

        /* FORM ELEMENTS */
        .form-group { margin-bottom: 1.5rem; }
        .form-label { display: block; font-size: 0.9rem; font-weight: 600; margin-bottom: 0.5rem; color: var(--text-main); }
        .form-select, .form-range { width: 100%; padding: 0.75rem; border-radius: var(--radius-md); border: 1px solid var(--border); background: var(--bg-body); color: var(--text-main); font-family: inherit; }
        
        .btn-primary { background: var(--primary); color: white; border: none; padding: 0.85rem 1.5rem; border-radius: var(--radius-md); font-weight: 600; cursor: pointer; transition: 0.2s; width: 100%; display: flex; justify-content: center; align-items: center; gap: 0.5rem; font-size: 1rem; }
        .btn-primary:hover { background: var(--primary-dark); transform: translateY(-1px); }
        .btn-primary:disabled { opacity: 0.7; cursor: not-allowed; transform: none; }

        /* TERMINAL / LOGS */
        .log-console { background: #0f172a; border-radius: var(--radius-md); padding: 1rem; height: 300px; overflow-y: auto; font-family: 'Courier New', monospace; font-size: 0.85rem; color: #38bdf8; border: 1px solid var(--border); }
        .log-entry { margin-bottom: 0.25rem; border-bottom: 1px solid rgba(255,255,255,0.05); padding-bottom: 0.25rem; }
        .log-success { color: #4ade80; }
        .log-error { color: #f87171; }
        .log-info { color: #94a3b8; }
        
        /* PROGRESS BAR */
        .progress-container { margin-top: 1.5rem; }
        .progress-label { display: flex; justify-content: space-between; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.5rem; }
        .progress-track { background: var(--bg-body); height: 10px; border-radius: 99px; overflow: hidden; border: 1px solid var(--border); }
        .progress-fill { height: 100%; background: var(--success); width: 0%; transition: width 0.3s ease; border-radius: 99px; }

        /* ALERTS */
        .alert-box { padding: 1rem; border-radius: var(--radius-md); margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.75rem; font-size: 0.9rem; }
        .alert-info { background: rgba(59, 130, 246, 0.1); color: var(--primary); border: 1px solid rgba(59, 130, 246, 0.2); }
        .alert-warning { background: rgba(245, 158, 11, 0.1); color: var(--warning); border: 1px solid rgba(245, 158, 11, 0.2); }

    </style>
</head>
<body>

    <header class="main-header">
        <div class="navbar">
            <a href="admin_dashboard.php" class="logo">
                <img src="<?php echo htmlspecialchars($base_path . 'uploads/CUPAD LOGO.png'); ?>" alt="Logo">
                <span>CUPAD</span>
            </a>
            <div class="nav-right">
                <button class="icon-btn" id="themeToggle"><i class="fas fa-moon"></i></button>
                
                <!-- User Dropdown -->
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
                        <i class="fas fa-chevron-down user-dropdown-icon"></i>
                    </div>

                    <!-- Dropdown Menu -->
                    <div class="dropdown-menu" id="userDropdown">
                        <a href="profile.php" class="dropdown-item">
                            <i class="fas fa-user-circle" style="color:var(--primary)"></i> My Profile
                        </a>
                        <div class="dropdown-divider"></div>
                        <a href="../logout.php" class="dropdown-item text-danger">
                            <i class="fas fa-sign-out-alt"></i> Logout
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <main class="container">
        
        <div class="page-header">
            <div class="page-title">
                <h1>Image Compression Suite</h1>
                <p>Optimize storage space and improve system performance.</p>
            </div>
            <div style="display:flex; gap:0.5rem; align-items:center;">
                <a href="admin_dashboard.php" class="btn-primary" style="width:auto; padding:0.5rem 1rem; font-size:0.9rem;">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
                <a href="compress_disbursements.php" class="btn-primary" style="width:auto; padding:0.5rem 1rem; font-size:0.9rem; background:#7c3aed;">
                    <i class="fas fa-hand-holding-usd"></i> Disbursement Compression
                </a>
            </div>
        </div>

        <?php if (!extension_loaded('gd')): ?>
        <div class="alert-box alert-warning">
            <i class="fas fa-exclamation-triangle"></i>
            <div><strong>Extension Missing:</strong> The GD Image library is not enabled in php.ini. Compression will not work.</div>
        </div>
        <?php endif; ?>

        <!-- Quick Stats -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon bg-blue"><i class="fas fa-images"></i></div>
                <div class="stat-value"><?php echo $root_stats['count']; ?></div>
                <div class="stat-label">Root Images</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon bg-orange"><i class="fas fa-folder-open"></i></div>
                <div class="stat-value"><?php echo count($subfolders); ?></div>
                <div class="stat-label">Subfolders</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon bg-green"><i class="fas fa-hdd"></i></div>
                <div class="stat-value"><?php echo round($root_stats['size'] / 1048576, 2); ?> MB</div>
                <div class="stat-label">Root Size</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon bg-rose"><i class="fas fa-money-bill-wave"></i></div>
                <div class="stat-value"><?php echo round($disb_stats['size'] / 1048576, 2); ?> MB</div>
                <div class="stat-label">Disbursements</div>
            </div>
        </div>

        <div class="control-grid">
            <!-- Left: Controls -->
            <div class="card">
                <div class="control-header">
                    <h2><i class="fas fa-sliders-h" style="color:var(--primary)"></i> Configuration</h2>
                </div>

                <div class="form-group">
                    <label class="form-label">Target Directory</label>
                    <select id="subfolder" class="form-select">
                        <option value="">Root (/uploads)</option>
                        <option value="disbursements" style="font-weight:bold; color:var(--primary)">Disbursements (Recommended)</option>
                        <?php foreach($subfolders as $sf): if($sf !== 'disbursements'): ?>
                            <option value="<?php echo htmlspecialchars($sf); ?>"><?php echo htmlspecialchars($sf); ?></option>
                        <?php endif; endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Compression Level (Quality)</label>
                    <input type="range" id="quality" class="form-range" min="40" max="90" value="75" oninput="document.getElementById('qVal').innerText = this.value + '%'">
                    <div style="display:flex; justify-content:space-between; font-size:0.8rem; color:var(--text-muted); margin-top:5px;">
                        <span>Max Compression (40%)</span>
                        <span id="qVal" style="font-weight:bold; color:var(--primary)">75%</span>
                        <span>Best Quality (90%)</span>
                    </div>
                </div>

                <div class="alert-box alert-info">
                    <i class="fas fa-info-circle"></i>
                    <div>Original images will be backed up to a <code>/backups</code> folder inside the target directory before processing.</div>
                </div>

                <button id="startBtn" class="btn-primary" onclick="startCompression()">
                    <i class="fas fa-play"></i> Start Compression
                </button>

                <!-- Progress UI -->
                <div class="progress-container" style="display:none" id="progressArea">
                    <div class="progress-label">
                        <span id="progressStatus">Initializing...</span>
                        <span id="progressPercent">0%</span>
                    </div>
                    <div class="progress-track">
                        <div class="progress-fill" id="progressBar"></div>
                    </div>
                </div>
            </div>

            <!-- Right: Logs & Charts -->
            <div class="card">
                <div class="control-header">
                    <h2><i class="fas fa-terminal" style="color:var(--warning)"></i> Live Logs</h2>
                </div>
                <div class="log-console" id="console">
                    <div class="log-entry log-info">> System ready.</div>
                    <div class="log-entry log-info">> Max Upload Setting: <?php echo $max_upload_size_mb; ?>MB</div>
                    <div class="log-entry log-info">> Select a folder to begin.</div>
                </div>
                
                <div style="margin-top:1.5rem">
                    <div class="control-header">
                        <h2><i class="fas fa-chart-pie" style="color:var(--success)"></i> Results</h2>
                    </div>
                    <canvas id="resultsChart" style="max-height: 200px;"></canvas>
                </div>
            </div>
        </div>

    </main>

    <script>
        // --- 1. DROPDOWN LOGIC ---
        const userTrigger = document.getElementById('userDropdownTrigger');
        const userDropdown = document.getElementById('userDropdown');

        if(userTrigger && userDropdown) {
            userTrigger.addEventListener('click', (e) => {
                e.stopPropagation();
                userDropdown.classList.toggle('show');
            });

            // Close dropdown when clicking outside
            document.addEventListener('click', (e) => {
                if (!userTrigger.contains(e.target) && !userDropdown.contains(e.target)) {
                    userDropdown.classList.remove('show');
                }
            });
        }

        // --- 2. THEME LOGIC ---
        const themeBtn = document.getElementById('themeToggle');
        const themeIcon = themeBtn.querySelector('i');
        
        // Check saved theme
        if (localStorage.getItem('theme') === 'dark') {
            document.documentElement.classList.add('dark');
            document.documentElement.classList.remove('light');
            themeIcon.classList.remove('fa-moon');
            themeIcon.classList.add('fa-sun');
        }

        themeBtn.addEventListener('click', () => {
            const isDark = document.documentElement.classList.toggle('dark');
            document.documentElement.classList.toggle('light');
            
            themeIcon.classList.toggle('fa-moon', !isDark);
            themeIcon.classList.toggle('fa-sun', isDark);
            localStorage.setItem('theme', isDark ? 'dark' : 'light');
        });

        // 3. LOGGING FUNCTION
        const consoleDiv = document.getElementById('console');
        function log(msg, type = 'info') {
            const div = document.createElement('div');
            div.className = `log-entry log-${type}`;
            div.innerText = `> ${msg}`;
            consoleDiv.appendChild(div);
            consoleDiv.scrollTop = consoleDiv.scrollHeight;
        }

        // 4. CHART LOGIC
        let chartInstance = null;
        function updateChart(before, after) {
            const ctx = document.getElementById('resultsChart').getContext('2d');
            if (chartInstance) chartInstance.destroy();

            chartInstance = new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: ['Compressed Size', 'Saved Space'],
                    datasets: [{
                        data: [after, before - after],
                        backgroundColor: ['#3b82f6', '#10b981'],
                        borderWidth: 0
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { position: 'right' } }
                }
            });
        }

        // 5. COMPRESSION LOGIC
        async function startCompression() {
            const btn = document.getElementById('startBtn');
            const subfolder = document.getElementById('subfolder').value;
            const quality = document.getElementById('quality').value;
            const progressArea = document.getElementById('progressArea');
            const progressBar = document.getElementById('progressBar');
            const progressPercent = document.getElementById('progressPercent');
            const progressStatus = document.getElementById('progressStatus');

            // UI Reset
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
            progressArea.style.display = 'block';
            progressBar.style.width = '0%';
            consoleDiv.innerHTML = '';
            
            log(`Starting job for: ${subfolder || 'Root'}`, 'info');
            log(`Quality setting: ${quality}%`, 'info');

            // Payload
            const payload = {
                subfolder: subfolder,
                quality: parseInt(quality),
                disbursement_mode: (subfolder === 'disbursements')
            };

            try {
                // Determine step simulation for better UX (since PHP is blocking)
                let fakeProgress = 0;
                const interval = setInterval(() => {
                    if(fakeProgress < 90) {
                        fakeProgress += Math.random() * 10;
                        progressBar.style.width = fakeProgress + '%';
                        progressPercent.innerText = Math.round(fakeProgress) + '%';
                    }
                }, 500);

                const response = await fetch('<?php echo basename(__FILE__); ?>', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify(payload)
                });

                const result = await response.json();
                clearInterval(interval);

                if (result.status === 'success') {
                    progressBar.style.width = '100%';
                    progressPercent.innerText = '100%';
                    progressStatus.innerText = 'Completed';
                    
                    log(`Processed ${result.processed} files.`, 'success');
                    
                    const savedMB = (result.savings / 1048576).toFixed(2);
                    log(`Total saved: ${savedMB} MB`, 'success');
                    log(`Overall Reduction: ${result.overall_compression}%`, 'success');

                    // Individual files (first 5)
                    result.files.slice(0, 5).forEach(f => {
                        log(`Optimized: ${f.name}`, 'info');
                    });
                    if(result.files.length > 5) log(`...and ${result.files.length - 5} more.`, 'info');

                    updateChart(result.total_size_before, result.total_size_after);
                } else {
                    log(`Error: ${result.message}`, 'error');
                    progressStatus.innerText = 'Failed';
                }

            } catch (error) {
                log(`Network Error: ${error.message}`, 'error');
            } finally {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-play"></i> Start Compression';
            }
        }
    </script>
</body>
</html>