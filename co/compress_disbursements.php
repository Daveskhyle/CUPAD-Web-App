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
    // header('Location: ../login.php'); // Uncomment in production
    // For now, ensure script doesn't run logic if not auth (though header is commented out per original)
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
            // Check if path is relative or just filename
            $check_path = file_exists($base_path . $pic) ? $base_path . $pic : $base_path . 'uploads/' . $pic;
            if (file_exists($check_path)) {
                $profile_pic_path = $check_path;
                $has_profile_pic = true;
            }
        }
    }
}

// --- Fetch Default Settings from DB ---
$default_max_kb = 500;
try {
    $stmtSettings = $pdo->query("SELECT max_file_size_mb FROM file_upload_settings LIMIT 1");
    $settings = $stmtSettings->fetch();
    if ($settings) {
        // Convert MB to KB for the compression tool default, explicitly casting
        $default_max_kb = (int)($settings['max_file_size_mb'] * 1024);
    }
} catch (Exception $e) { /* use default */ }


// --- Check GD Library ---
if (!extension_loaded('gd')) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'GD extension is not loaded.']);
        exit;
    }
}

// --- Handle AJAX Compression Request ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty(file_get_contents('php://input'))) {
    header('Content-Type: application/json');
    
    $rawInput = file_get_contents('php://input');
    $inputData = json_decode($rawInput, true);
    
    $quality = isset($inputData['quality']) ? (int)$inputData['quality'] : 75;
    $batch_size = isset($inputData['batch_size']) ? (int)$inputData['batch_size'] : 10;
    $create_backup = isset($inputData['create_backup']) ? (bool)$inputData['create_backup'] : true;
    $resize_images = isset($inputData['resize_images']) ? (bool)$inputData['resize_images'] : false;
    $max_width = isset($inputData['max_width']) ? (int)$inputData['max_width'] : 1200;
    $max_height = isset($inputData['max_height']) ? (int)$inputData['max_height'] : 1200;
    $max_size_kb = isset($inputData['max_size_kb']) ? (int)$inputData['max_size_kb'] : 500;
    
    // Define directory (Assumed based on app structure)
    $disbursement_dir = '../uploads/disbursements/';
    
    if (!is_dir($disbursement_dir)) {
        // Attempt to create if missing (though typically created by upload logic)
        if (!mkdir($disbursement_dir, 0777, true)) {
            echo json_encode(['status' => 'error', 'message' => 'Disbursements directory not found']);
            exit;
        }
    }
    
    $response = [
        'status' => 'success',
        'message' => 'Batch processing complete.',
        'processed' => 0,
        'total_size_before' => 0,
        'total_size_after' => 0,
        'savings' => 0,
        'files' => []
    ];
    
    try {
        if ($create_backup) {
            $backup_dir = $disbursement_dir . 'backups/';
            if (!is_dir($backup_dir)) mkdir($backup_dir, 0777, true);
        }
        
        $files = glob($disbursement_dir . '*.{jpg,jpeg,png,gif,webp}', GLOB_BRACE);
        $response['total_files'] = count($files);
        
        if (empty($files)) {
            $response['message'] = 'No disbursement images found';
            echo json_encode($response);
            exit;
        }
        
        // If client requested "all" by sending 0, process all
        if ($batch_size <= 0) {
            $batch_size = count($files);
        }

        $processed_count = 0;
        foreach ($files as $file) {
            if ($processed_count >= $batch_size) break;
            
            $size_before = filesize($file);
            
            // Skip files that are already small enough (optimization)
            if ($size_before <= ($max_size_kb * 1024) && !$resize_images) {
                continue; 
            }

            $response['total_size_before'] += $size_before;
            
            if ($create_backup) {
                $backup_file = $backup_dir . basename($file);
                if (!file_exists($backup_file)) copy($file, $backup_file);
            }
            
            $compressed = compressDisbursementImage($file, $quality, $resize_images, $max_width, $max_height, $max_size_kb);
            
            if ($compressed) {
                clearstatcache();
                $size_after = filesize($file);
                $response['total_size_after'] += $size_after;
                $response['processed']++;
                
                $response['files'][] = [
                    'name' => basename($file),
                    'savings' => $size_before - $size_after,
                    'ratio' => round((($size_before - $size_after) / $size_before) * 100, 1)
                ];
            }
            $processed_count++;
        }
        
        $response['savings'] = $response['total_size_before'] - $response['total_size_after'];
        $response['overall_compression'] = $response['total_size_before'] > 0 
            ? round(($response['savings'] / $response['total_size_before']) * 100, 1) : 0;

        // --- Log Action to Database (Audit Log) ---
        if ($response['processed'] > 0) {
            $logDetails = json_encode([
                'action' => 'IMAGE_COMPRESSION',
                'files_processed' => $response['processed'],
                'space_saved_bytes' => $response['savings'],
                'target_kb' => $max_size_kb
            ]);
            
            $auditSql = "INSERT INTO audit_log (user_id, username, action, table_name, details, ip_address) 
                         VALUES (?, ?, 'COMPRESS', 'disbursements', ?, ?)";
            $stmtLog = $pdo->prepare($auditSql);
            $stmtLog->execute([$user_id, $username, $logDetails, $_SERVER['REMOTE_ADDR']]);
        }
            
    } catch (Exception $e) {
        $response = ['status' => 'error', 'message' => 'Compression failed: ' . $e->getMessage()];
    }
    
    echo json_encode($response);
    exit;
}

// --- Image Compression Logic (GD) ---
function compressDisbursementImage($source, $quality = 75, $resize = false, $max_width = 1200, $max_height = 1200, $target_kb = 0) {
    if (!file_exists($source) || !is_readable($source)) return false;
    $info = getimagesize($source);
    if ($info === false) return false;

    $mime = $info['mime'];
    $width = $info[0];
    $height = $info[1];

    switch ($mime) {
        case 'image/jpeg': $image = imagecreatefromjpeg($source); break;
        case 'image/png': $image = imagecreatefrompng($source); break;
        case 'image/gif': $image = imagecreatefromgif($source); break;
        case 'image/webp': $image = imagecreatefromwebp($source); break;
        default: return false;
    }
    if ($image === false) return false;

    // Resize if requested
    if ($resize && ($width > $max_width || $height > $max_height)) {
        $ratio = min($max_width / $width, $max_height / $height);
        $new_width = round($width * $ratio);
        $new_height = round($height * $ratio);
        $resized_image = imagecreatetruecolor($new_width, $new_height);

        if ($mime == 'image/png' || $mime == 'image/gif') {
            imagealphablending($resized_image, false);
            imagesavealpha($resized_image, true);
        }
        imagecopyresampled($resized_image, $image, 0, 0, 0, 0, $new_width, $new_height, $width, $height);
        imagedestroy($image);
        $image = $resized_image;
        $width = $new_width; $height = $new_height;
    }

    $target_bytes = $target_kb > 0 ? $target_kb * 1024 : 0;

    $saveImage = function($img, $mimeType, $filePath, $q) {
        switch ($mimeType) {
            case 'image/jpeg': return imagejpeg($img, $filePath, $q);
            case 'image/png':  return imagepng($img, $filePath, 9 - round(($q/100)*9));
            case 'image/gif':  return imagegif($img, $filePath);
            case 'image/webp': return imagewebp($img, $filePath, $q);
        }
        return false;
    };

    // Initial save
    $saved = $saveImage($image, $mime, $source, $quality);
    if ($saved === false) {
        imagedestroy($image);
        return false;
    }

    clearstatcache();
    $size_after = filesize($source);
    if ($target_bytes <= 0 || $size_after <= $target_bytes) {
        imagedestroy($image);
        return true;
    }

    // Iterative Reduction for JPEG/WEBP
    if ($mime === 'image/jpeg' || $mime === 'image/webp') {
        $min_quality = 30;
        $q = max($min_quality, $quality - 5);
        while ($size_after > $target_bytes && $q >= $min_quality) {
            if ($mime === 'image/jpeg') $tmp = imagecreatefromjpeg($source);
            else $tmp = imagecreatefromwebp($source);
            if ($tmp === false) break;
            $saveImage($tmp, $mime, $source, $q);
            imagedestroy($tmp);
            clearstatcache();
            $size_after = filesize($source);
            $q -= 5;
        }
        imagedestroy($image);
        return $size_after <= $target_bytes;
    }

    // Iterative Reduction for PNG/GIF
    if ($mime === 'image/png' || $mime === 'image/gif') {
        if ($mime === 'image/png') {
            $saveImage($image, $mime, $source, 30);
            clearstatcache();
            $size_after = filesize($source);
            if ($size_after <= $target_bytes) { imagedestroy($image); return true; }
        }

        $curW = $width; $curH = $height;
        $attempts = 0;
        while ($size_after > $target_bytes && $attempts < 8 && ($curW > 200 && $curH > 200)) {
            $curW = max(200, (int)round($curW * 0.9));
            $curH = max(200, (int)round($curH * 0.9));
            $tmpImg = imagecreatetruecolor($curW, $curH);
            if ($mime === 'image/png' || $mime === 'image/gif') {
                imagealphablending($tmpImg, false);
                imagesavealpha($tmpImg, true);
            }
            imagecopyresampled($tmpImg, $image, 0,0,0,0, $curW, $curH, $width, $height);
            $saveImage($tmpImg, $mime, $source, 30);
            imagedestroy($tmpImg);
            clearstatcache();
            $size_after = filesize($source);
            $attempts++;
        }
        imagedestroy($image);
        return $size_after <= $target_bytes;
    }

    imagedestroy($image);
    return false;
}

// Get Stats for View (Filesystem Scan)
function getDisbursementStats() {
    $dir = '../uploads/disbursements/';
    if (!is_dir($dir)) return ['total_files' => 0, 'total_size' => 0, 'avg_size' => 0, 'largest' => 0];
    
    $files = glob($dir . '*.{jpg,jpeg,png,gif,webp}', GLOB_BRACE);
    $total_size = 0; $sizes = [];
    
    if ($files) {
        foreach ($files as $file) {
            $size = filesize($file);
            $total_size += $size;
            $sizes[] = $size;
        }
    }
    
    return [
        'total_files' => count($files),
        'total_size' => $total_size,
        'avg_size' => count($files) > 0 ? round($total_size / count($files)) : 0,
        'largest' => count($sizes) > 0 ? max($sizes) : 0
    ];
}
$stats = getDisbursementStats();
?>
<!DOCTYPE html>
<html lang="en" class="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Disbursement Compression | CUPAD</title>
    <link rel="icon" type="image/png" href="<?php echo htmlspecialchars($base_path . 'uploads/CUPAD LOGO.png'); ?>">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    
    <style>
        /* --- DASHBOARD THEME VARIABLES --- */
        :root {
            --primary: #2563eb; --primary-dark: #1d4ed8;
            --success: #059669; --warning: #d97706; --danger: #dc2626;
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
        }

        * { box-sizing: border-box; outline: none; }
        body { font-family: 'Plus Jakarta Sans', sans-serif; background: var(--bg-body); color: var(--text-main); margin: 0; padding-top: var(--nav-height); transition: 0.3s; }
        a { text-decoration: none; color: inherit; }
        
        /* HEADER */
        .main-header { position: fixed; top: 0; left: 0; right: 0; height: var(--nav-height); background: rgba(255,255,255,0.85); backdrop-filter: blur(10px); border-bottom: 1px solid var(--border); z-index: 50; }
        html.dark .main-header { background: rgba(15, 23, 42, 0.85); }
        .navbar { max-width: 1400px; margin: 0 auto; padding: 0 1.5rem; display: flex; align-items: center; justify-content: space-between; height: 100%; }
        .logo { display: flex; align-items: center; gap: 0.75rem; font-weight: 800; font-size: 1.35rem; color: var(--primary); text-decoration: none; }
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

        /* MAIN CONTAINER */
        .container { max-width: 1400px; margin: 0 auto; padding: 2rem 1.5rem 4rem; }
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; }
        .page-title h1 { margin: 0; font-size: 1.5rem; font-weight: 700; }
        .page-title p { margin: 0.25rem 0 0; color: var(--text-muted); font-size: 0.9rem; }

        /* STATS GRID */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1rem; margin-bottom: 2rem; }
        .stat-card { background: var(--bg-surface); padding: 1.5rem; border-radius: var(--radius-lg); border: 1px solid var(--border); box-shadow: var(--shadow-sm); display: flex; flex-direction: column; }
        .stat-icon { width: 48px; height: 48px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.25rem; margin-bottom: 1rem; }
        .stat-value { font-size: 1.75rem; font-weight: 800; line-height: 1; }
        .stat-label { font-size: 0.85rem; color: var(--text-muted); font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }

        .bg-blue { background: rgba(37, 99, 235, 0.1); color: var(--primary); }
        .bg-purple { background: rgba(124, 58, 237, 0.1); color: #7c3aed; }
        .bg-green { background: rgba(5, 150, 105, 0.1); color: var(--success); }
        .bg-orange { background: rgba(217, 119, 6, 0.1); color: var(--warning); }

        /* CONTROL LAYOUT */
        .dashboard-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 1.5rem; }
        @media(max-width: 900px) { .dashboard-grid { grid-template-columns: 1fr; } }

        .card { background: var(--bg-surface); border: 1px solid var(--border); border-radius: var(--radius-lg); padding: 1.5rem; box-shadow: var(--shadow-sm); }
        .card-header { margin-bottom: 1.5rem; border-bottom: 1px solid var(--border); padding-bottom: 1rem; display: flex; justify-content: space-between; align-items: center; }
        .card-title { font-size: 1.1rem; font-weight: 700; display: flex; align-items: center; gap: 0.5rem; }

        /* FORMS */
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-bottom: 1.5rem; }
        .form-group { margin-bottom: 1rem; }
        .form-label { display: block; font-size: 0.9rem; font-weight: 600; margin-bottom: 0.5rem; color: var(--text-main); }
        .form-control { width: 100%; padding: 0.75rem; border-radius: var(--radius-md); border: 1px solid var(--border); background: var(--bg-body); color: var(--text-main); font-family: inherit; }
        
        /* RANGE INPUT */
        input[type=range] { width: 100%; height: 6px; background: var(--border); border-radius: 5px; outline: none; -webkit-appearance: none; }
        input[type=range]::-webkit-slider-thumb { -webkit-appearance: none; width: 18px; height: 18px; border-radius: 50%; background: var(--primary); cursor: pointer; border: 2px solid var(--bg-surface); box-shadow: 0 1px 3px rgba(0,0,0,0.3); }

        /* TOGGLES */
        .toggle-wrap { display: flex; align-items: center; justify-content: space-between; padding: 0.75rem; border: 1px solid var(--border); border-radius: var(--radius-md); margin-bottom: 0.75rem; }
        .toggle-label { font-size: 0.9rem; font-weight: 600; }
        
        .switch { position: relative; display: inline-block; width: 44px; height: 24px; }
        .switch input { opacity: 0; width: 0; height: 0; }
        .slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: var(--border); transition: .4s; border-radius: 24px; }
        .slider:before { position: absolute; content: ""; height: 18px; width: 18px; left: 3px; bottom: 3px; background-color: white; transition: .4s; border-radius: 50%; }
        input:checked + .slider { background-color: var(--primary); }
        input:checked + .slider:before { transform: translateX(20px); }

        /* BUTTONS */
        .btn-primary { width: 100%; background: var(--primary); color: white; border: none; padding: 1rem; border-radius: var(--radius-md); font-weight: 700; cursor: pointer; transition: 0.2s; display: flex; align-items: center; justify-content: center; gap: 0.5rem; font-size: 1rem; }
        .btn-primary:hover { background: var(--primary-dark); transform: translateY(-2px); }
        .btn-primary:disabled { opacity: 0.7; cursor: not-allowed; transform: none; }

        /* LOGS */
        .log-area { height: 350px; overflow-y: auto; background: var(--bg-body); border-radius: var(--radius-md); padding: 1rem; font-family: monospace; font-size: 0.85rem; border: 1px solid var(--border); }
        .log-item { display: flex; justify-content: space-between; padding: 0.5rem 0; border-bottom: 1px dashed var(--border); }
        .log-name { color: var(--text-main); }
        .log-save { color: var(--success); font-weight: 700; }
        .log-empty { text-align: center; color: var(--text-muted); padding-top: 2rem; }

        /* PROGRESS */
        .progress-box { margin-top: 1.5rem; display: none; }
        .progress-bar { height: 8px; background: var(--border); border-radius: 99px; overflow: hidden; margin-bottom: 0.5rem; }
        .progress-fill { height: 100%; background: var(--primary); width: 0%; transition: width 0.3s; }
        .progress-text { font-size: 0.85rem; color: var(--text-muted); text-align: right; }
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
                        <a href="admin_dashboard.php" class="dropdown-item">
                            <i class="fas fa-arrow-left"></i> Back to Dashboard
                        </a>
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
                <h1>Disbursement Compression</h1>
                <p>Specific tool for optimizing loan disbursement records.</p>
            </div>
            <div style="display:flex; gap:0.5rem; align-items:center;">
                <a href="admin_dashboard.php" class="btn-primary" style="width:auto; padding:0.5rem 1rem; font-size:0.9rem;">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
                <a href="compress_uploads.php" class="btn-primary" style="width:auto; padding:0.5rem 1rem; font-size:0.9rem; background:#10b981;">
                    <i class="fas fa-file-archive"></i> Compress Uploads
                </a>
            </div>
        </div>

        <?php if (!extension_loaded('gd')): ?>
        <div style="background:rgba(220,38,38,0.1); color:var(--danger); padding:1rem; border-radius:var(--radius-md); margin-bottom:1.5rem; border:1px solid rgba(220,38,38,0.2);">
            <i class="fas fa-exclamation-triangle"></i> <strong>GD Missing:</strong> Image compression requires the PHP GD library.
        </div>
        <?php endif; ?>

        <!-- Stats Overview -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon bg-blue"><i class="fas fa-images"></i></div>
                <div class="stat-value"><?php echo number_format($stats['total_files']); ?></div>
                <div class="stat-label">Total Images</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon bg-purple"><i class="fas fa-hdd"></i></div>
                <div class="stat-value"><?php echo number_format($stats['total_size'] / 1024 / 1024, 1); ?> MB</div>
                <div class="stat-label">Total Size</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon bg-green"><i class="fas fa-chart-pie"></i></div>
                <div class="stat-value"><?php echo number_format($stats['avg_size'] / 1024, 0); ?> KB</div>
                <div class="stat-label">Avg Size</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon bg-orange"><i class="fas fa-file-invoice-dollar"></i></div>
                <div class="stat-value"><?php echo number_format($stats['largest'] / 1024, 0); ?> KB</div>
                <div class="stat-label">Largest File</div>
            </div>
        </div>

        <div class="dashboard-grid">
            <!-- Left: Settings -->
            <div class="card">
                <div class="card-header">
                    <div class="card-title"><i class="fas fa-sliders-h" style="color:var(--primary)"></i> Settings</div>
                </div>

                <div class="form-group">
                    <div style="display:flex; justify-content:space-between; margin-bottom:0.5rem;">
                        <label class="form-label">Compression Quality</label>
                        <span id="qLabel" style="font-weight:700; color:var(--primary)">75%</span>
                    </div>
                    <input type="range" id="quality" min="30" max="90" value="75" oninput="document.getElementById('qLabel').innerText = this.value + '%'">
                    <div style="display:flex; justify-content:space-between; font-size:0.75rem; color:var(--text-muted); margin-top:0.25rem;">
                        <span>Max Compression</span>
                        <span>Best Quality</span>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Max File Size (KB)</label>
                    <input type="number" id="max_size_kb" class="form-control" value="<?php echo $default_max_kb; ?>" min="50" step="10">
                    <div style="font-size:0.8rem; color:var(--text-muted); margin-top:0.25rem;">Target maximum size per image in kilobytes (e.g. 500)</div>
                </div>

                <div class="toggle-wrap">
                    <span class="toggle-label">Backup Originals</span>
                    <label class="switch">
                        <input type="checkbox" id="create_backup" checked>
                        <span class="slider"></span>
                    </label>
                </div>

                <div class="toggle-wrap">
                    <span class="toggle-label">Resize Large Images</span>
                    <label class="switch">
                        <input type="checkbox" id="resize_images" onchange="toggleResize()">
                        <span class="slider"></span>
                    </label>
                </div>

                <div id="resize-box" style="display:none; padding:1rem; background:var(--bg-body); border-radius:var(--radius-md); margin-bottom:1rem;">
                    <div class="form-grid" style="margin-bottom:0">
                        <div>
                            <label class="form-label">Max Width (px)</label>
                            <input type="number" id="max_width" class="form-control" value="1200">
                        </div>
                        <div>
                            <label class="form-label">Max Height (px)</label>
                            <input type="number" id="max_height" class="form-control" value="1200">
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Batch Processing Limit</label>
                    <select id="batch_size" class="form-control">
                        <option value="10">10 Images (Recommended)</option>
                        <option value="25">25 Images</option>
                        <option value="50">50 Images</option>
                        <option value="0">All Images (Process entire folder)</option>
                    </select>
                </div>

                <button class="btn-primary" id="startBtn" onclick="runCompression()">
                    <i class="fas fa-play"></i> Start Optimization
                </button>

                <div class="progress-box" id="progressArea">
                    <div class="progress-bar"><div class="progress-fill" id="progressFill"></div></div>
                    <div class="progress-text" id="progressText">Processing...</div>
                </div>
            </div>

            <!-- Right: Results -->
            <div class="card">
                <div class="card-header">
                    <div class="card-title"><i class="fas fa-clipboard-list" style="color:var(--success)"></i> Results Log</div>
                    <button class="icon-btn" onclick="document.getElementById('logList').innerHTML = ''" title="Clear"><i class="fas fa-trash-alt"></i></button>
                </div>
                <div class="log-area" id="logList">
                    <div class="log-empty">Waiting to start...</div>
                </div>
                <div id="summaryBox" style="margin-top:1rem; padding:1rem; background:rgba(16,185,129,0.1); border-radius:var(--radius-md); display:none;">
                    <strong style="color:var(--success)">Session Complete:</strong>
                    <div style="font-size:0.9rem; margin-top:0.25rem;">Saved: <span id="totalSaved">0MB</span></div>
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

        // 2. Theme Logic
        const themeBtn = document.getElementById('themeToggle');
        if (localStorage.getItem('theme') === 'dark') document.documentElement.classList.add('dark');
        
        themeBtn.addEventListener('click', () => {
            document.documentElement.classList.toggle('dark');
            localStorage.setItem('theme', document.documentElement.classList.contains('dark') ? 'dark' : 'light');
        });

        // 3. UI Toggles
        function toggleResize() {
            const box = document.getElementById('resize-box');
            box.style.display = document.getElementById('resize_images').checked ? 'block' : 'none';
        }

        // 4. Compression Logic
        async function runCompression() {
            const btn = document.getElementById('startBtn');
            const logList = document.getElementById('logList');
            const progressArea = document.getElementById('progressArea');
            const progressFill = document.getElementById('progressFill');
            const summaryBox = document.getElementById('summaryBox');

            // Reset UI
            if(logList.querySelector('.log-empty')) logList.innerHTML = '';
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i> Processing...';
            progressArea.style.display = 'block';
            progressFill.style.width = '10%';
            summaryBox.style.display = 'none';

            const payload = {
                quality: parseInt(document.getElementById('quality').value),
                batch_size: parseInt(document.getElementById('batch_size').value),
                create_backup: document.getElementById('create_backup').checked,
                resize_images: document.getElementById('resize_images').checked,
                max_width: parseInt(document.getElementById('max_width').value),
                max_height: parseInt(document.getElementById('max_height').value),
                max_size_kb: parseInt(document.getElementById('max_size_kb').value)
            };

            try {
                // Simulating progress while waiting for PHP
                let fakeProgress = 10;
                const interval = setInterval(() => {
                    if(fakeProgress < 90) { fakeProgress += 5; progressFill.style.width = fakeProgress + '%'; }
                }, 300);

                const res = await fetch('<?php echo basename(__FILE__); ?>', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify(payload)
                });

                const data = await res.json();
                clearInterval(interval);
                progressFill.style.width = '100%';

                if(data.status === 'success') {
                    // Update Log
                    if(data.files.length > 0) {
                        data.files.forEach(f => {
                            const size = (f.savings / 1024).toFixed(1) + ' KB';
                            const html = `
                                <div class="log-item">
                                    <span class="log-name">${f.name}</span>
                                    <span class="log-save">-${size} (${f.ratio}%)</span>
                                </div>`;
                            logList.insertAdjacentHTML('afterbegin', html);
                        });
                    } else {
                        logList.insertAdjacentHTML('afterbegin', '<div class="log-item"><span style="color:var(--text-muted)">No files processed in this batch.</span></div>');
                    }

                    // Show Summary
                    document.getElementById('totalSaved').innerText = (data.savings / 1048576).toFixed(2) + ' MB';
                    summaryBox.style.display = 'block';
                } else {
                    alert('Error: ' + data.message);
                }

            } catch(e) {
                alert('Connection Error');
            } finally {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-play"></i> Start Optimization';
                setTimeout(() => progressArea.style.display = 'none', 2000);
            }
        }
    </script>
</body>
</html>