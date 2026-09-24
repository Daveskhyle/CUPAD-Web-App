<?php
session_start();

require_once '../includes/config.php';
$pdo = getDbConnection();

// --- AUTHENTICATION CHECK ---
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: ../index.php');
    exit();
}

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$base_path = '../'; 
$page_title = "Backup & Restore Engine — CUPAD Admin";
$full_name = $_SESSION['full_name'] ?? 'Admin';
$username = $_SESSION['username'] ?? '';
$role = $_SESSION['user_role'] ?? 'admin';

// --- BACKUP DIRECTORY CONFIGURATION ---
$backup_dir = __DIR__ . '/../backups/';
if (!is_dir($backup_dir)) {
    mkdir($backup_dir, 0755, true);
}

// --- SECURE DOWNLOAD HANDLER ---
if (isset($_GET['download'])) {
    $download_file = basename($_GET['download']);
    $file_path = $backup_dir . $download_file;

    if (file_exists($file_path) && pathinfo($file_path, PATHINFO_EXTENSION) === 'sql') {
        if (ob_get_level()) {
            ob_end_clean();
        }
        header('Content-Description: Database Snapshot Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $download_file . '"');
        header('Content-Transfer-Encoding: binary');
        header('Expires: 0');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header('Pragma: public');
        header('Content-Length: ' . filesize($file_path));
        readfile($file_path);
        exit();
    } else {
        $_SESSION['flash_message'] = 'Requested backup snapshot does not exist or has an invalid format.';
        $_SESSION['flash_type'] = 'error';
        header('Location: ' . strtok($_SERVER["REQUEST_URI"], '?'));
        exit();
    }
}

// Profile Picture Logic
$profile_pic_path = $base_path . 'uploads/default_avatar.png';
try {
    $stmt = $pdo->prepare("SELECT profile_pic FROM users WHERE username = ? LIMIT 1");
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($user && !empty($user['profile_pic']) && file_exists($base_path . $user['profile_pic'])) {
        $profile_pic_path = $base_path . $user['profile_pic'];
    }
} catch (PDOException $e) {
    // Silently continue
}

// Handle Flash Messages
$message = '';
$message_type = '';
if (isset($_SESSION['flash_message'])) {
    $message = $_SESSION['flash_message'];
    $message_type = $_SESSION['flash_type'] ?? 'success';
    unset($_SESSION['flash_message'], $_SESSION['flash_type']);
}

// --- MEMORY-EFFICIENT BACKUP STREAMER ---
function createDatabaseBackup($pdo, $backup_path) {
    $handle = fopen($backup_path, 'w');
    if (!$handle) {
        error_log('Backup error: Unable to open file write stream: ' . $backup_path);
        return false;
    }

    try {
        fwrite($handle, "-- --------------------------------------------------------\n");
        fwrite($handle, "-- CUPAD Database Backup Snapshot\n");
        fwrite($handle, "-- Generated: " . date('Y-m-d H:i:s') . "\n");
        fwrite($handle, "-- Host: " . ($_SERVER['SERVER_NAME'] ?? 'localhost') . "\n");
        fwrite($handle, "-- --------------------------------------------------------\n\n");
        fwrite($handle, "SET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE=\"NO_AUTO_VALUE_ON_ZERO\";\nSET AUTOCOMMIT=0;\nSTART TRANSACTION;\n\n");

        $tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);

        foreach ($tables as $table) {
            $createStmt = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_ASSOC);
            $createSql = $createStmt['Create Table'] ?? null;

            if ($createSql) {
                fwrite($handle, "\n-- Table structure for `$table`\n");
                fwrite($handle, "DROP TABLE IF EXISTS `$table`;\n");
                fwrite($handle, $createSql . ";\n\n");
            }

            $stmt = $pdo->query("SELECT * FROM `$table`");
            $columnCount = $stmt->columnCount();

            if ($columnCount > 0) {
                $batchSize = 250;
                $currentBatch = [];
                $firstRow = true;
                $columnList = '';

                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    if ($firstRow) {
                        $columns = array_keys($row);
                        $columnList = '`' . implode('`, `', $columns) . '`';
                        fwrite($handle, "-- Data dump for `$table`\n");
                        $firstRow = false;
                    }

                    $values = [];
                    foreach ($row as $val) {
                        $values[] = ($val === null) ? 'NULL' : $pdo->quote($val);
                    }

                    $currentBatch[] = '(' . implode(', ', $values) . ')';

                    if (count($currentBatch) >= $batchSize) {
                        fwrite($handle, "INSERT INTO `$table` ($columnList) VALUES\n" . implode(",\n", $currentBatch) . ";\n");
                        $currentBatch = [];
                    }
                }

                if (!empty($currentBatch)) {
                    fwrite($handle, "INSERT INTO `$table` ($columnList) VALUES\n" . implode(",\n", $currentBatch) . ";\n");
                }

                if (!$firstRow) {
                    fwrite($handle, "\n");
                }
            }
        }

        fwrite($handle, "\nSET FOREIGN_KEY_CHECKS=1;\nCOMMIT;\n");
        fclose($handle);
        return true;
    } catch (Exception $e) {
        if (is_resource($handle)) {
            fclose($handle);
        }
        error_log('Backup error: ' . $e->getMessage());
        return false;
    }
}

// --- HANDLE POST ACTIONS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['flash_message'] = 'Security validation failed (Invalid CSRF Token). Please refresh.';
        $_SESSION['flash_type'] = 'error';
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit();
    }
    
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    
    try {
        switch ($_POST['action']) {
            case 'create_backup':
                $backup_name = 'cupad_backup_' . date('Y-m-d_H-i-s') . '.sql';
                $backup_path = $backup_dir . $backup_name;
                
                @ini_set('memory_limit', '512M');
                set_time_limit(300);
                
                if (createDatabaseBackup($pdo, $backup_path)) {
                    try {
                        $stmt = $pdo->prepare("INSERT INTO backup_logs (filename, created_by, created_at, size_bytes, source) VALUES (?, ?, NOW(), ?, 'system')");
                        $stmt->execute([$backup_name, $username, filesize($backup_path)]);
                    } catch (Exception $e) {}
                    
                    $_SESSION['flash_message'] = 'Database snapshot created successfully: ' . $backup_name;
                    $_SESSION['flash_type'] = 'success';
                } else {
                    $_SESSION['flash_message'] = 'Database snapshot generation failed. Check server error logs.';
                    $_SESSION['flash_type'] = 'error';
                }
                break;
                
            case 'restore_backup':
                $backup_file = basename($_POST['backup_file'] ?? '');
                $backup_path = $backup_dir . $backup_file;
                
                if (empty($backup_file) || !file_exists($backup_path) || pathinfo($backup_path, PATHINFO_EXTENSION) !== 'sql') {
                    $_SESSION['flash_message'] = 'Invalid backup snapshot file selected.';
                    $_SESSION['flash_type'] = 'error';
                    header('Location: ' . $_SERVER['PHP_SELF']);
                    exit();
                }
                
                try {
                    @ini_set('memory_limit', '512M');
                    set_time_limit(600);
                    
                    $file = fopen($backup_path, 'r');
                    if (!$file) {
                        throw new Exception("Unable to open snapshot file for reading.");
                    }

                    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
                    
                    $query = '';
                    $inString = false;
                    $stringChar = '';

                    while (($line = fgets($file)) !== false) {
                        $trimmedLine = trim($line);
                        if (!$inString && ($trimmedLine === '' || strpos($trimmedLine, '--') === 0 || strpos($trimmedLine, '/*') === 0)) {
                            continue;
                        }

                        $len = strlen($line);
                        for ($i = 0; $i < $len; $i++) {
                            $char = $line[$i];
                            if (!$inString) {
                                if ($char === "'" || $char === '"') {
                                    $inString = true;
                                    $stringChar = $char;
                                }
                            } else {
                                if ($char === '\\') {
                                    $i++;
                                } elseif ($char === $stringChar) {
                                    $inString = false;
                                }
                            }
                        }

                        $query .= $line;
                        $trimmedQuery = rtrim($query);
                        if (!$inString && $trimmedQuery !== '' && substr($trimmedQuery, -1) === ';') {
                            $pdo->exec($query);
                            $query = '';
                        }
                    }
                    fclose($file);
                    
                    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");
                    
                    try {
                        $stmt = $pdo->prepare("INSERT INTO restore_logs (backup_filename, restored_by, restored_at) VALUES (?, ?, NOW())");
                        $stmt->execute([$backup_file, $username]);
                    } catch (Exception $e) {}
                    
                    $_SESSION['flash_message'] = 'Database successfully restored from snapshot: ' . $backup_file;
                    $_SESSION['flash_type'] = 'success';
                } catch (Exception $e) {
                    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");
                    $_SESSION['flash_message'] = 'Restoration failed: ' . $e->getMessage();
                    $_SESSION['flash_type'] = 'error';
                }
                break;
                
            case 'delete_backup':
                $backup_file = basename($_POST['backup_file'] ?? '');
                $backup_path = $backup_dir . $backup_file;
                
                if (file_exists($backup_path) && pathinfo($backup_path, PATHINFO_EXTENSION) === 'sql') {
                    unlink($backup_path);
                    try {
                        $stmt = $pdo->prepare("UPDATE backup_logs SET deleted_at = NOW(), deleted_by = ? WHERE filename = ?");
                        $stmt->execute([$username, $backup_file]);
                    } catch (Exception $e) {}
                    
                    $_SESSION['flash_message'] = 'Backup snapshot deleted permanently.';
                    $_SESSION['flash_type'] = 'success';
                } else {
                    $_SESSION['flash_message'] = 'Target backup file could not be found.';
                    $_SESSION['flash_type'] = 'error';
                }
                break;
                
            case 'upload_backup':
                if (isset($_FILES['backup_file']) && $_FILES['backup_file']['error'] === UPLOAD_ERR_OK) {
                    $upload_name = preg_replace('/[^a-zA-Z0-9_\.-]/', '_', basename($_FILES['backup_file']['name']));
                    if (!str_ends_with(strtolower($upload_name), '.sql')) {
                        $upload_name .= '.sql';
                    }
                    
                    $target_path = $backup_dir . $upload_name;
                    
                    if (move_uploaded_file($_FILES['backup_file']['tmp_name'], $target_path)) {
                        try {
                            $stmt = $pdo->prepare("INSERT INTO backup_logs (filename, created_by, created_at, size_bytes, source) VALUES (?, ?, NOW(), ?, 'upload')");
                            $stmt->execute([$upload_name, $username, filesize($target_path)]);
                        } catch (Exception $e) {}
                        
                        $_SESSION['flash_message'] = 'SQL backup snapshot uploaded successfully: ' . $upload_name;
                        $_SESSION['flash_type'] = 'success';
                    } else {
                        $_SESSION['flash_message'] = 'Failed to write uploaded file to backup repository.';
                        $_SESSION['flash_type'] = 'error';
                    }
                } else {
                    $_SESSION['flash_message'] = 'Upload failed. File exceeds allowed limits or network interrupted.';
                    $_SESSION['flash_type'] = 'error';
                }
                break;
        }
    } catch (Exception $e) {
        $_SESSION['flash_message'] = 'System Error: ' . $e->getMessage();
        $_SESSION['flash_type'] = 'error';
    }
    
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit();
}

// --- FETCH DATABASE STATS & BACKUPS ---
$db_info = ['tables' => 0, 'size_mb' => '0.00'];
try {
    $db_name_stmt = $pdo->query("SELECT DATABASE()")->fetchColumn();
    if ($db_name_stmt) {
        $size_stmt = $pdo->prepare("
            SELECT 
                COUNT(table_name) AS table_count,
                ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS size_mb 
            FROM information_schema.TABLES 
            WHERE table_schema = ?
        ");
        $size_stmt->execute([$db_name_stmt]);
        $db_metrics = $size_stmt->fetch(PDO::FETCH_ASSOC);
        $db_info['tables'] = $db_metrics['table_count'] ?? 0;
        $db_info['size_mb'] = $db_metrics['size_mb'] ?? '0.00';
    }
} catch (Exception $e) {}

$backups = [];
$backup_stats = ['total_backups' => 0, 'total_size' => 0, 'last_backup' => null];

try {
    $db_backups = [];
    try {
        $stmt = $pdo->query("SELECT * FROM backup_logs WHERE deleted_at IS NULL ORDER BY created_at DESC");
        $db_backups = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {}

    if (is_dir($backup_dir)) {
        $files = glob($backup_dir . '*.sql');
        foreach ($files as $file) {
            $filename = basename($file);
            $found_in_db = false;
            
            foreach ($db_backups as $db_backup) {
                if ($db_backup['filename'] === $filename) {
                    $found_in_db = true;
                    $backups[] = [
                        'filename' => $filename,
                        'created_at' => $db_backup['created_at'],
                        'created_by' => $db_backup['created_by'] ?? 'System',
                        'size' => filesize($file),
                        'source' => $db_backup['source'] ?? 'system'
                    ];
                    break;
                }
            }
            
            if (!$found_in_db) {
                $backups[] = [
                    'filename' => $filename,
                    'created_at' => date('Y-m-d H:i:s', filemtime($file)),
                    'created_by' => 'System Engine',
                    'size' => filesize($file),
                    'source' => 'system'
                ];
            }
        }
    }
    
    usort($backups, fn($a, $b) => strtotime($b['created_at']) - strtotime($a['created_at']));
    
    $backup_stats['total_backups'] = count($backups);
    $backup_stats['total_size'] = array_sum(array_column($backups, 'size'));
    if (!empty($backups)) {
        $backup_stats['last_backup'] = $backups[0]['created_at'];
    }
} catch (Exception $e) {}

function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}

function timeAgo($datetime) {
    $time = strtotime($datetime);
    $diff = time() - $time;
    if ($diff < 60) return 'Just now';
    if ($diff < 3600) return floor($diff / 60) . ' mins ago';
    if ($diff < 86400) return floor($diff / 3600) . ' hrs ago';
    if ($diff < 604800) return floor($diff / 86400) . ' days ago';
    return date('M j, Y', $time);
}

// Fetch restore logs
$restore_logs = [];
try {
    $stmt = $pdo->query("
        SELECT r.*, u.full_name as restored_by_name 
        FROM restore_logs r 
        LEFT JOIN users u ON r.restored_by = u.username 
        ORDER BY r.restored_at DESC 
        LIMIT 6
    ");
    $restore_logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}
?>
<!DOCTYPE html>
<html lang="en" class="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    <link rel="icon" type="image/png" href="<?php echo htmlspecialchars($base_path . 'uploads/CUPAD LOGO.png'); ?>">
    
    <!-- Fonts & Icons -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />

    <style>
        :root {
            --primary: #2563eb;
            --primary-hover: #1d4ed8;
            --primary-soft: rgba(37, 99, 235, 0.08);
            --primary-border: rgba(37, 99, 235, 0.2);
            --success: #10b981;
            --success-soft: rgba(16, 185, 129, 0.1);
            --warning: #f59e0b;
            --warning-soft: rgba(245, 158, 11, 0.1);
            --danger: #ef4444;
            --danger-soft: rgba(239, 68, 68, 0.1);
            --info: #06b6d4;
            --info-soft: rgba(6, 182, 212, 0.1);
            
            --bg-body: #f8fafc;
            --bg-surface: #ffffff;
            --bg-subtle: #f1f5f9;
            --text-main: #0f172a;
            --text-muted: #64748b;
            --border: #e2e8f0;
            
            --radius-xl: 18px;
            --radius-lg: 12px;
            --radius-md: 8px;
            --radius-sm: 6px;
            
            --shadow-xs: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --shadow-sm: 0 4px 6px -1px rgba(0, 0, 0, 0.04), 0 2px 4px -2px rgba(0, 0, 0, 0.04);
            --shadow-md: 0 10px 15px -3px rgba(0, 0, 0, 0.06), 0 4px 6px -4px rgba(0, 0, 0, 0.04);
            --shadow-lg: 0 20px 25px -5px rgba(0, 0, 0, 0.08), 0 8px 10px -6px rgba(0, 0, 0, 0.04);
            
            --nav-height: 70px;
            --transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        }

        html.dark {
            --bg-body: #0a0f1d;
            --bg-surface: #111827;
            --bg-subtle: #1f293d;
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
            --border: #1f2937;
            --primary: #3b82f6;
            --primary-hover: #60a5fa;
            --primary-soft: rgba(59, 130, 246, 0.15);
            --primary-border: rgba(59, 130, 246, 0.3);
            --shadow-sm: 0 4px 6px -1px rgba(0, 0, 0, 0.3);
            --shadow-md: 0 10px 15px -3px rgba(0, 0, 0, 0.4);
            --shadow-lg: 0 20px 25px -5px rgba(0, 0, 0, 0.5);
        }

        * { box-sizing: border-box; outline: none; margin: 0; padding: 0; }
        body { 
            font-family: 'Plus Jakarta Sans', sans-serif; 
            background: var(--bg-body); 
            color: var(--text-main); 
            padding-top: var(--nav-height); 
            transition: background-color 0.3s ease, color 0.3s ease; 
            min-height: 100vh;
            -webkit-font-smoothing: antialiased;
        }
        a { text-decoration: none; color: inherit; }

        /* Top Header */
        .main-header { 
            position: fixed; 
            top: 0; left: 0; right: 0; 
            height: var(--nav-height); 
            background: rgba(255, 255, 255, 0.85); 
            backdrop-filter: blur(12px); 
            -webkit-backdrop-filter: blur(12px);
            border-bottom: 1px solid var(--border); 
            z-index: 100; 
            transition: var(--transition);
        }
        html.dark .main-header { background: rgba(17, 24, 39, 0.85); }
        
        .navbar { 
            max-width: 1400px; 
            margin: 0 auto; 
            padding: 0 1.5rem; 
            display: flex; 
            align-items: center; 
            justify-content: space-between; 
            height: 100%; 
        }
        .logo { 
            display: flex; 
            align-items: center; 
            gap: 0.75rem; 
            font-weight: 800; 
            font-size: 1.25rem; 
            color: var(--primary); 
            letter-spacing: -0.02em;
        }
        .logo img { height: 36px; width: auto; object-fit: contain; }

        .nav-right { display: flex; align-items: center; gap: 0.85rem; }
        .icon-btn { 
            width: 38px; 
            height: 38px; 
            border-radius: 50%; 
            border: 1px solid var(--border); 
            background: var(--bg-surface); 
            color: var(--text-muted); 
            cursor: pointer; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            font-size: 0.95rem; 
            transition: var(--transition); 
        }
        .icon-btn:hover { background: var(--bg-subtle); color: var(--text-main); transform: translateY(-1px); }
        
        .user-dropdown-wrap { position: relative; }
        .user-pill { 
            display: flex; 
            align-items: center; 
            gap: 0.65rem; 
            padding: 4px 10px 4px 4px; 
            border: 1px solid var(--border); 
            border-radius: 99px; 
            background: var(--bg-surface); 
            cursor: pointer; 
            transition: var(--transition); 
        }
        .user-pill:hover { border-color: var(--primary); box-shadow: var(--shadow-xs); }
        .user-avatar { width: 32px; height: 32px; border-radius: 50%; object-fit: cover; }
        .user-info { display: flex; flex-direction: column; line-height: 1.1; }
        .user-name { font-weight: 700; font-size: 0.82rem; }
        .user-role { font-size: 0.65rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; font-weight: 600; }
        
        .dropdown-menu { 
            position: absolute; 
            top: calc(100% + 8px); 
            right: 0; 
            background: var(--bg-surface); 
            border: 1px solid var(--border); 
            border-radius: var(--radius-lg); 
            box-shadow: var(--shadow-lg); 
            min-width: 200px; 
            display: none; 
            z-index: 1000; 
            flex-direction: column; 
            overflow: hidden; 
            padding: 0.4rem;
        }
        .dropdown-menu.show { display: flex; animation: slideIn 0.15s ease-out; }
        @keyframes slideIn { from { opacity: 0; transform: translateY(-6px); } to { opacity: 1; transform: translateY(0); } }
        .dropdown-item { 
            padding: 0.65rem 0.85rem; 
            display: flex; 
            align-items: center; 
            gap: 0.65rem; 
            font-size: 0.85rem; 
            font-weight: 500;
            color: var(--text-main); 
            border-radius: var(--radius-md);
            transition: var(--transition); 
        }
        .dropdown-item:hover { background: var(--primary-soft); color: var(--primary); }
        .dropdown-item.text-danger:hover { background: var(--danger-soft); color: var(--danger); }
        .dropdown-divider { height: 1px; background: var(--border); margin: 0.3rem 0; }

        /* Container */
        .container { max-width: 1400px; margin: 0 auto; padding: 2rem 1.5rem 4rem; }

        /* Hero Header */
        .page-header {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 1.25rem;
            margin-bottom: 2rem;
        }
        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.8rem;
            color: var(--text-muted);
            margin-bottom: 0.35rem;
            font-weight: 500;
        }
        .breadcrumb a:hover { color: var(--primary); }
        .page-title {
            font-size: 1.7rem;
            font-weight: 800;
            letter-spacing: -0.03em;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        .page-subtitle {
            font-size: 0.88rem;
            color: var(--text-muted);
            margin-top: 0.25rem;
        }

        /* Buttons */
        .btn {
            padding: 0.625rem 1.2rem;
            border-radius: var(--radius-md);
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            border: 1px solid transparent;
            font-family: inherit;
        }
        .btn-primary { 
            background: var(--primary); 
            color: #ffffff; 
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.25); 
        }
        .btn-primary:hover { 
            background: var(--primary-hover); 
            transform: translateY(-1px); 
            box-shadow: 0 6px 16px rgba(37, 99, 235, 0.35); 
        }
        .btn-secondary { 
            background: var(--bg-surface); 
            color: var(--text-main); 
            border-color: var(--border); 
        }
        .btn-secondary:hover { 
            background: var(--bg-subtle); 
            border-color: var(--text-muted); 
            transform: translateY(-1px); 
        }
        .btn-danger { background: var(--danger); color: #ffffff; }
        .btn-danger:hover { opacity: 0.92; transform: translateY(-1px); }

        /* Alert Toast/Banner */
        .alert-banner {
            border-radius: var(--radius-lg);
            padding: 0.95rem 1.25rem;
            margin-bottom: 1.75rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            box-shadow: var(--shadow-sm);
            animation: slideDown 0.3s ease;
        }
        @keyframes slideDown { from { opacity: 0; transform: translateY(-8px); } to { opacity: 1; transform: translateY(0); } }
        .alert-banner.success {
            background: var(--success-soft);
            border: 1px solid rgba(16, 185, 129, 0.3);
            color: var(--success);
        }
        .alert-banner.error {
            background: var(--danger-soft);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: var(--danger);
        }
        .alert-content { display: flex; align-items: center; gap: 0.75rem; font-weight: 600; font-size: 0.88rem; }
        .alert-close { background: none; border: none; color: inherit; cursor: pointer; font-size: 1.15rem; opacity: 0.7; }
        .alert-close:hover { opacity: 1; }

        /* Stats Cards */
        .stats-grid { 
            display: grid; 
            grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); 
            gap: 1.25rem; 
            margin-bottom: 2rem; 
        }
        .stats-card { 
            background: var(--bg-surface); 
            padding: 1.25rem 1.4rem; 
            border-radius: var(--radius-xl); 
            border: 1px solid var(--border); 
            box-shadow: var(--shadow-sm); 
            transition: var(--transition); 
            display: flex;
            align-items: center;
            gap: 1.15rem;
        }
        .stats-card:hover { 
            transform: translateY(-2px); 
            box-shadow: var(--shadow-md); 
            border-color: var(--primary-border); 
        }
        .stats-icon-wrap {
            width: 48px;
            height: 48px;
            border-radius: var(--radius-lg);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
            flex-shrink: 0;
        }
        .stats-label { font-size: 0.75rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; }
        .stats-val { font-size: 1.45rem; font-weight: 800; margin-top: 0.15rem; letter-spacing: -0.02em; }

        /* Main Workspace Grid */
        .main-grid { 
            display: grid; 
            grid-template-columns: 1fr 340px; 
            gap: 1.75rem; 
            align-items: start;
        }

        /* Generic Card */
        .card { 
            background: var(--bg-surface); 
            border: 1px solid var(--border); 
            border-radius: var(--radius-xl); 
            padding: 1.35rem; 
            box-shadow: var(--shadow-sm); 
        }

        /* Table Card Container */
        .table-container { 
            background: var(--bg-surface); 
            border: 1px solid var(--border); 
            border-radius: var(--radius-xl); 
            box-shadow: var(--shadow-sm); 
            overflow: hidden; 
        }
        .table-header-bar { 
            padding: 1.15rem 1.4rem; 
            border-bottom: 1px solid var(--border); 
            display: flex; 
            align-items: center; 
            justify-content: space-between; 
            gap: 1rem; 
            flex-wrap: wrap;
        }
        
        .filter-controls {
            display: flex;
            align-items: center;
            gap: 0.65rem;
            flex-wrap: wrap;
        }

        .search-box {
            position: relative;
            min-width: 240px;
        }
        .search-box input {
            width: 100%;
            padding: 0.5rem 0.85rem 0.5rem 2.25rem;
            border-radius: var(--radius-md);
            border: 1px solid var(--border);
            background: var(--bg-subtle);
            color: var(--text-main);
            font-size: 0.825rem;
            font-family: inherit;
            transition: var(--transition);
        }
        .search-box input:focus {
            border-color: var(--primary);
            background: var(--bg-surface);
            box-shadow: 0 0 0 3px var(--primary-soft);
        }
        .search-icon {
            position: absolute;
            left: 0.8rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            font-size: 0.8rem;
        }

        .filter-select {
            padding: 0.5rem 0.85rem;
            border-radius: var(--radius-md);
            border: 1px solid var(--border);
            background: var(--bg-subtle);
            color: var(--text-main);
            font-size: 0.825rem;
            font-family: inherit;
            cursor: pointer;
        }

        table { width: 100%; border-collapse: collapse; text-align: left; }
        th, td { padding: 0.95rem 1.25rem; border-bottom: 1px solid var(--border); vertical-align: middle; }
        th { 
            background: var(--bg-subtle); 
            font-weight: 700; 
            font-size: 0.72rem; 
            color: var(--text-muted); 
            text-transform: uppercase; 
            letter-spacing: 0.05em;
        }
        tr:last-child td { border-bottom: none; }
        tr.backup-row:hover td { background: var(--bg-subtle); }

        /* Badges */
        .badge { 
            display: inline-flex; 
            align-items: center; 
            gap: 0.35rem; 
            padding: 0.25rem 0.6rem; 
            border-radius: 99px; 
            font-size: 0.725rem; 
            font-weight: 600; 
        }
        .badge-success { background: var(--success-soft); color: var(--success); }
        .badge-warning { background: var(--warning-soft); color: var(--warning); }
        .badge-info { background: var(--info-soft); color: var(--info); }
        .badge-primary { background: var(--primary-soft); color: var(--primary); }

        /* Action Buttons */
        .action-group { display: flex; align-items: center; justify-content: flex-end; gap: 0.4rem; }
        .action-btn {
            width: 32px;
            height: 32px;
            border-radius: var(--radius-md);
            border: 1px solid var(--border);
            background: var(--bg-surface);
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: var(--transition);
            color: var(--text-muted);
            font-size: 0.825rem;
        }
        .action-btn:hover { transform: translateY(-1px); }
        .action-btn.restore:hover { background: var(--success-soft); color: var(--success); border-color: rgba(16, 185, 129, 0.3); }
        .action-btn.download:hover { background: var(--primary-soft); color: var(--primary); border-color: var(--primary-border); }
        .action-btn.delete:hover { background: var(--danger-soft); color: var(--danger); border-color: rgba(239, 68, 68, 0.3); }
        .action-btn.copy:hover { background: var(--bg-subtle); color: var(--text-main); }

        /* Timeline / History */
        .timeline-list { display: flex; flex-direction: column; gap: 0.75rem; }
        .timeline-item {
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            padding: 0.75rem;
            background: var(--bg-subtle);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            transition: var(--transition);
        }
        .timeline-item:hover { border-color: var(--primary-border); }
        .timeline-icon {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background: var(--success-soft);
            color: var(--success);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.78rem;
            flex-shrink: 0;
        }

        /* Modals */
        .modal-backdrop { 
            position: fixed; 
            inset: 0; 
            background: rgba(15, 23, 42, 0.6); 
            backdrop-filter: blur(6px); 
            -webkit-backdrop-filter: blur(6px);
            z-index: 1000; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            opacity: 0; 
            pointer-events: none; 
            transition: opacity 0.2s ease;
            padding: 1rem;
        }
        .modal-backdrop.show { opacity: 1; pointer-events: auto; }
        .modal-content { 
            background: var(--bg-surface); 
            padding: 1.75rem; 
            border-radius: var(--radius-xl); 
            width: 100%; 
            max-width: 480px; 
            box-shadow: var(--shadow-lg); 
            border: 1px solid var(--border); 
            transform: scale(0.95); 
            transition: transform 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            max-height: 90vh; 
            overflow-y: auto; 
        }
        .modal-backdrop.show .modal-content { transform: scale(1); }
        
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem; }
        .modal-title { font-size: 1.15rem; font-weight: 700; }
        .modal-close { background: none; border: none; font-size: 1.25rem; color: var(--text-muted); cursor: pointer; }
        .modal-close:hover { color: var(--text-main); }

        .modal-icon-badge {
            width: 52px;
            height: 52px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            font-size: 1.35rem;
        }
        .modal-icon-badge.warning { background: var(--warning-soft); color: var(--warning); }
        .modal-icon-badge.danger { background: var(--danger-soft); color: var(--danger); }
        .modal-icon-badge.primary { background: var(--primary-soft); color: var(--primary); }

        /* Upload Dropzone */
        .upload-zone {
            border: 2px dashed var(--border);
            border-radius: var(--radius-xl);
            padding: 2rem 1.25rem;
            text-align: center;
            transition: var(--transition);
            cursor: pointer;
            background: var(--bg-subtle);
        }
        .upload-zone:hover, .upload-zone.dragover {
            border-color: var(--primary);
            background: var(--primary-soft);
        }
        .upload-icon { font-size: 2.25rem; color: var(--primary); margin-bottom: 0.65rem; }
        
        /* Progress Bar */
        .progress-bar {
            width: 100%;
            height: 5px;
            background: var(--bg-subtle);
            border-radius: 99px;
            overflow: hidden;
            margin-top: 1.25rem;
            display: none;
        }
        .progress-bar.active { display: block; }
        .progress-fill {
            height: 100%;
            background: var(--primary);
            width: 0%;
            transition: width 0.3s ease;
            border-radius: 99px;
        }

        /* Empty State */
        .empty-state { text-align: center; padding: 3.5rem 1.5rem; color: var(--text-muted); }
        .empty-state i { font-size: 2.8rem; margin-bottom: 0.85rem; opacity: 0.35; }

        @media (max-width: 992px) {
            .main-grid { grid-template-columns: 1fr; }
        }
        @media (max-width: 640px) {
            .page-header { flex-direction: column; align-items: flex-start; }
            .table-header-bar { flex-direction: column; align-items: stretch; }
            .search-box { width: 100%; }
        }
    </style>
</head>
<body>

    <!-- Main Navigation Bar -->
    <header class="main-header">
        <div class="navbar">
            <a href="dashboard.php" class="logo">
                <img src="<?php echo htmlspecialchars($base_path . 'uploads/CUPAD LOGO.png'); ?>" alt="CUPAD Logo">
                <span>CUPAD Admin</span>
            </a>
            <div class="nav-right">
                <button class="icon-btn" id="themeToggle" title="Toggle Dark/Light Mode">
                    <i class="fas fa-moon"></i>
                </button>
                <div class="user-dropdown-wrap">
                    <div class="user-pill" id="userDropdownTrigger">
                        <?php if (strpos($profile_pic_path, 'default_avatar.png') === false): ?>
                            <img src="<?php echo htmlspecialchars($profile_pic_path); ?>" class="user-avatar" alt="Avatar">
                        <?php else: ?>
                            <div class="user-avatar" style="background:var(--bg-subtle); border:1px solid var(--border); display:flex; align-items:center; justify-content:center; color:var(--text-muted);"><i class="fas fa-user"></i></div>
                        <?php endif; ?>
                        <div class="user-info">
                            <span class="user-name"><?php echo htmlspecialchars($full_name); ?></span>
                            <span class="user-role"><?php echo htmlspecialchars($role); ?></span>
                        </div>
                        <i class="fas fa-chevron-down" style="font-size:0.7rem; color:var(--text-muted);"></i>
                    </div>
                    <div class="dropdown-menu" id="userDropdown">
                        <a href="profile.php" class="dropdown-item"><i class="fas fa-user-circle"></i> My Profile</a>
                        <div class="dropdown-divider"></div>
                        <a href="<?php echo htmlspecialchars($base_path . 'logout.php'); ?>" class="dropdown-item text-danger"><i class="fas fa-sign-out-alt"></i> Sign Out</a>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <main class="container">
        
        <!-- Header Hero Section -->
        <div class="page-header">
            <div>
                <div class="breadcrumb">
                    <a href="dashboard.php">Dashboard</a>
                    <i class="fas fa-chevron-right" style="font-size:0.6rem;"></i>
                    <span>System Maintenance</span>
                </div>
                <h1 class="page-title">
                    <i class="fas fa-database" style="color: var(--primary);"></i>
                    Database Backup & Restore
                </h1>
                <p class="page-subtitle">Generate complete SQL dumps, download offline copies, and restore full system data.</p>
            </div>
            <div style="display: flex; gap: 0.75rem; flex-wrap: wrap;">
                <button type="button" onclick="openModal('uploadModal')" class="btn btn-secondary">
                    <i class="fas fa-cloud-arrow-up"></i> Upload SQL Dump
                </button>
                <button type="button" onclick="openModal('createBackupModal')" class="btn btn-primary">
                    <i class="fas fa-plus"></i> Create Snapshot
                </button>
            </div>
        </div>

        <!-- Flash Alert Message -->
        <?php if ($message): ?>
            <div class="alert-banner <?php echo $message_type === 'success' ? 'success' : 'error'; ?>" id="flashAlert">
                <div class="alert-content">
                    <i class="fas <?php echo $message_type === 'success' ? 'fa-check-circle' : 'fa-triangle-exclamation'; ?>"></i>
                    <span><?php echo htmlspecialchars($message); ?></span>
                </div>
                <button class="alert-close" onclick="document.getElementById('flashAlert').remove()">&times;</button>
            </div>
        <?php endif; ?>

        <!-- Stats Overview Cards -->
        <div class="stats-grid">
            <div class="stats-card">
                <div class="stats-icon-wrap" style="background: var(--primary-soft); color: var(--primary);">
                    <i class="fas fa-box-archive"></i>
                </div>
                <div>
                    <p class="stats-label">Stored Snapshots</p>
                    <h3 class="stats-val"><?php echo number_format($backup_stats['total_backups']); ?></h3>
                </div>
            </div>
            <div class="stats-card">
                <div class="stats-icon-wrap" style="background: var(--info-soft); color: var(--info);">
                    <i class="fas fa-hard-drive"></i>
                </div>
                <div>
                    <p class="stats-label">Storage Used</p>
                    <h3 class="stats-val"><?php echo formatBytes($backup_stats['total_size']); ?></h3>
                </div>
            </div>
            <div class="stats-card">
                <div class="stats-icon-wrap" style="background: var(--success-soft); color: var(--success);">
                    <i class="fas fa-clock-rotate-left"></i>
                </div>
                <div>
                    <p class="stats-label">Latest Snapshot</p>
                    <h3 class="stats-val" style="font-size: 1.15rem; margin-top: 0.3rem;">
                        <?php echo $backup_stats['last_backup'] ? timeAgo($backup_stats['last_backup']) : 'Never'; ?>
                    </h3>
                </div>
            </div>
            <div class="stats-card">
                <div class="stats-icon-wrap" style="background: var(--warning-soft); color: var(--warning);">
                    <i class="fas fa-table-cells"></i>
                </div>
                <div>
                    <p class="stats-label">Live Tables / Size</p>
                    <h3 class="stats-val" style="font-size: 1.15rem; margin-top: 0.3rem;">
                        <?php echo $db_info['tables']; ?> tables • <?php echo $db_info['size_mb']; ?> MB
                    </h3>
                </div>
            </div>
        </div>

        <!-- Main Layout Grid -->
        <div class="main-grid">
            
            <!-- Backup List Table -->
            <div class="table-container">
                <div class="table-header-bar">
                    <div style="display: flex; align-items: center; gap: 0.75rem;">
                        <h3 style="font-size: 1.05rem; font-weight: 700;">Available Backups</h3>
                        <span class="badge badge-primary" id="backupCountBadge"><?php echo count($backups); ?></span>
                    </div>
                    
                    <div class="filter-controls">
                        <select class="filter-select" id="sourceFilter" onchange="filterBackups()">
                            <option value="all">All Sources</option>
                            <option value="system">System Generated</option>
                            <option value="upload">Uploaded</option>
                        </select>
                        <div class="search-box">
                            <i class="fas fa-search search-icon"></i>
                            <input type="text" id="backupSearch" placeholder="Search file or author..." onkeyup="filterBackups()">
                        </div>
                    </div>
                </div>

                <div style="overflow-x: auto;">
                    <table id="backupsTable">
                        <thead>
                            <tr>
                                <th>File Name & Details</th>
                                <th>Creation Date</th>
                                <th>File Size</th>
                                <th>Source</th>
                                <th style="text-align: right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="backupsTableBody">
                            <?php if (empty($backups)): ?>
                                <tr>
                                    <td colspan="5">
                                        <div class="empty-state">
                                            <i class="fas fa-database"></i>
                                            <p style="font-weight: 700; font-size: 1rem; color: var(--text-main);">No Backups Available</p>
                                            <p style="font-size: 0.85rem; margin-top: 0.3rem;">Generate your first database snapshot to ensure data safety.</p>
                                            <button type="button" onclick="openModal('createBackupModal')" class="btn btn-primary" style="margin-top: 1.25rem;">
                                                <i class="fas fa-plus"></i> Create Backup Snapshot
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($backups as $backup): ?>
                                <tr class="backup-row" data-source="<?php echo htmlspecialchars($backup['source']); ?>">
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 0.85rem;">
                                            <div style="width: 36px; height: 36px; border-radius: var(--radius-md); background: var(--primary-soft); color: var(--primary); display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                                                <i class="fas fa-file-code"></i>
                                            </div>
                                            <div style="min-width: 0;">
                                                <div style="font-weight: 700; font-size: 0.85rem; color: var(--text-main); word-break: break-all;" class="filename-text">
                                                    <?php echo htmlspecialchars($backup['filename']); ?>
                                                </div>
                                                <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 0.15rem;" class="author-text">
                                                    Created by: <strong style="color: var(--text-main);"><?php echo htmlspecialchars($backup['created_by']); ?></strong>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td style="font-size: 0.825rem; color: var(--text-muted); font-weight: 500; white-space: nowrap;">
                                        <span title="<?php echo $backup['created_at']; ?>"><?php echo date('M d, Y', strtotime($backup['created_at'])); ?></span>
                                        <div style="font-size: 0.725rem; opacity: 0.8;"><?php echo date('H:i:s', strtotime($backup['created_at'])); ?> (<?php echo timeAgo($backup['created_at']); ?>)</div>
                                    </td>
                                    <td>
                                        <span class="badge badge-info"><?php echo formatBytes($backup['size']); ?></span>
                                    </td>
                                    <td>
                                        <span class="badge <?php echo $backup['source'] === 'upload' ? 'badge-warning' : 'badge-success'; ?>">
                                            <i class="fas <?php echo $backup['source'] === 'upload' ? 'fa-upload' : 'fa-robot'; ?>"></i>
                                            <?php echo ucfirst($backup['source']); ?>
                                        </span>
                                    </td>
                                    <td style="text-align: right;">
                                        <div class="action-group">
                                            <!-- Copy Filename -->
                                            <button type="button" class="action-btn copy" title="Copy Filename" onclick="copyToClipboard('<?php echo htmlspecialchars($backup['filename']); ?>')">
                                                <i class="fas fa-copy"></i>
                                            </button>
                                            <!-- Secure Download via PHP streaming -->
                                            <a href="?download=<?php echo urlencode($backup['filename']); ?>" class="action-btn download" title="Download SQL Dump">
                                                <i class="fas fa-download"></i>
                                            </a>
                                            <!-- Restore -->
                                            <button type="button" class="action-btn restore" title="Restore Snapshot" onclick="confirmRestore('<?php echo htmlspecialchars($backup['filename']); ?>')">
                                                <i class="fas fa-rotate-left"></i>
                                            </button>
                                            <!-- Delete -->
                                            <button type="button" class="action-btn delete" title="Delete Snapshot" onclick="confirmDelete('<?php echo htmlspecialchars($backup['filename']); ?>')">
                                                <i class="fas fa-trash-can"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Sidebar Info & History -->
            <div style="display: flex; flex-direction: column; gap: 1.5rem;">
                
                <!-- Recent Restores Card -->
                <div class="card">
                    <h3 style="font-size: 0.95rem; font-weight: 700; margin-bottom: 0.85rem; display: flex; align-items: center; justify-content: space-between;">
                        <span><i class="fas fa-history" style="color: var(--primary); margin-right: 0.4rem;"></i> Recent Restores</span>
                        <span class="badge badge-primary"><?php echo count($restore_logs); ?></span>
                    </h3>
                    
                    <?php if (empty($restore_logs)): ?>
                        <div class="empty-state" style="padding: 1.5rem 0.5rem;">
                            <i class="fas fa-clock-rotate-left" style="font-size: 1.8rem;"></i>
                            <p style="font-size: 0.8rem;">No restoration operations recorded.</p>
                        </div>
                    <?php else: ?>
                        <div class="timeline-list">
                            <?php foreach ($restore_logs as $log): ?>
                            <div class="timeline-item">
                                <div class="timeline-icon">
                                    <i class="fas fa-check"></i>
                                </div>
                                <div style="flex: 1; min-width: 0;">
                                    <div style="font-size: 0.8rem; font-weight: 700; text-overflow: ellipsis; overflow: hidden; white-space: nowrap;">
                                        <?php echo htmlspecialchars($log['backup_filename']); ?>
                                    </div>
                                    <div style="font-size: 0.725rem; color: var(--text-muted); margin-top: 0.15rem;">
                                        By <strong><?php echo htmlspecialchars($log['restored_by_name'] ?? $log['restored_by']); ?></strong>
                                    </div>
                                    <div style="font-size: 0.68rem; color: var(--text-muted); margin-top: 0.1rem;">
                                        <?php echo timeAgo($log['restored_at']); ?> (<?php echo date('H:i', strtotime($log['restored_at'])); ?>)
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Safety Guidelines Card -->
                <div class="card" style="border-left: 4px solid var(--primary);">
                    <div style="display: flex; align-items: center; gap: 0.65rem; margin-bottom: 0.65rem;">
                        <i class="fas fa-shield-halved" style="color: var(--primary); font-size: 1.1rem;"></i>
                        <h3 style="font-size: 0.925rem; font-weight: 700;">Zero-Risk Guarantee</h3>
                    </div>
                    <p style="font-size: 0.8rem; color: var(--text-muted); line-height: 1.5; margin-bottom: 0.85rem;">
                        Backups stream sequentially in buffered chunks without invoking hazardous shell commands or exhausting PHP memory.
                    </p>
                    <div style="display: flex; gap: 0.4rem; flex-wrap: wrap;">
                        <span class="badge badge-success"><i class="fas fa-bolt"></i> Buffered Stream</span>
                        <span class="badge badge-info"><i class="fas fa-lock"></i> CSRF Verified</span>
                    </div>
                </div>

            </div>
        </div>
    </main>

    <!-- Create Backup Modal -->
    <div id="createBackupModal" class="modal-backdrop">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Generate Database Snapshot</h3>
                <button type="button" onclick="closeModal('createBackupModal')" class="modal-close">&times;</button>
            </div>
            <form method="post" action="" id="backupForm" onsubmit="startBackupProgress()">
                <input type="hidden" name="action" value="create_backup">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                
                <div style="margin-bottom: 1.25rem;">
                    <p style="color: var(--text-muted); font-size: 0.85rem; line-height: 1.5; margin-bottom: 1rem;">
                        This will generate a complete SQL snapshot of all database structures, schemas, relationships, and data records.
                    </p>
                    <div style="background: var(--bg-subtle); padding: 0.75rem 1rem; border-radius: var(--radius-md); display: flex; align-items: center; gap: 0.65rem;">
                        <i class="fas fa-info-circle" style="color: var(--primary);"></i>
                        <span style="font-size: 0.78rem; color: var(--text-muted);">Database operations will continue without downtime.</span>
                    </div>
                </div>
                
                <div class="progress-bar" id="backupProgress">
                    <div class="progress-fill" id="backupProgressFill"></div>
                </div>
                
                <div style="display:flex; justify-content:flex-end; gap:0.65rem; margin-top: 1.5rem;">
                    <button type="button" onclick="closeModal('createBackupModal')" class="btn btn-secondary">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-play"></i> Start Backup
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Restore Confirmation Modal -->
    <div id="restoreModal" class="modal-backdrop">
        <div class="modal-content">
            <div class="modal-icon-badge danger">
                <i class="fas fa-triangle-exclamation"></i>
            </div>
            <h3 style="font-weight:800; font-size: 1.2rem; margin-bottom: 0.4rem; text-align: center;">Confirm Database Restore</h3>
            <p style="color: var(--text-muted); font-size: 0.85rem; text-align: center; margin-bottom: 1.25rem;">
                Target Snapshot: <br>
                <strong id="restoreFilename" style="color: var(--text-main); word-break: break-all;"></strong>
            </p>
            
            <div style="background: var(--danger-soft); padding: 0.85rem 1rem; border-radius: var(--radius-lg); margin-bottom: 1.25rem; border: 1px solid rgba(239, 68, 68, 0.2);">
                <div style="font-size: 0.8rem; color: var(--danger); display: flex; align-items: flex-start; gap: 0.65rem;">
                    <i class="fas fa-circle-exclamation" style="margin-top: 2px;"></i>
                    <span><strong>CRITICAL:</strong> Restoring this snapshot will completely drop and replace all existing tables. Ensure you have backed up current data before proceeding.</span>
                </div>
            </div>

            <form method="post" action="" id="restoreForm" onsubmit="showRestoreProgress()">
                <input type="hidden" name="action" value="restore_backup">
                <input type="hidden" name="backup_file" id="restoreFileInput">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                
                <div style="display:flex; gap:0.65rem;">
                    <button type="button" onclick="closeModal('restoreModal')" class="btn btn-secondary" style="flex:1;">Cancel</button>
                    <button type="submit" class="btn btn-danger" style="flex:1;">
                        <i class="fas fa-rotate-left"></i> Restore Now
                    </button>
                </div>
            </form>
            <div class="progress-bar" id="restoreProgress">
                <div class="progress-fill" id="restoreProgressFill"></div>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="modal-backdrop">
        <div class="modal-content" style="max-width: 420px; text-align: center;">
            <div class="modal-icon-badge danger">
                <i class="fas fa-trash-can"></i>
            </div>
            <h3 style="font-weight:800; font-size: 1.15rem; margin-bottom:0.4rem;">Delete Snapshot?</h3>
            <p style="color: var(--text-muted); font-size: 0.85rem; margin-bottom: 1.25rem; line-height: 1.4;">
                Are you sure you want to delete <strong id="deleteFilename" style="color: var(--text-main); word-break: break-all;"></strong>? This file cannot be recovered.
            </p>
            <form method="post" action="" style="display:flex; gap:0.65rem;">
                <input type="hidden" name="action" value="delete_backup">
                <input type="hidden" name="backup_file" id="deleteFileInput">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <button type="button" onclick="closeModal('deleteModal')" class="btn btn-secondary" style="flex:1;">Cancel</button>
                <button type="submit" class="btn btn-danger" style="flex:1;">
                    <i class="fas fa-trash"></i> Delete
                </button>
            </form>
        </div>
    </div>

    <!-- Upload Modal -->
    <div id="uploadModal" class="modal-backdrop">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Upload SQL Backup</h3>
                <button type="button" onclick="closeModal('uploadModal')" class="modal-close">&times;</button>
            </div>
            <form method="post" action="" enctype="multipart/form-data">
                <input type="hidden" name="action" value="upload_backup">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                
                <div class="upload-zone" id="dropZone" onclick="document.getElementById('backupUpload').click()">
                    <i class="fas fa-cloud-arrow-up upload-icon"></i>
                    <div style="font-weight: 700; font-size: 0.92rem; margin-bottom: 0.25rem;">Click to select or drag & drop</div>
                    <div style="font-size: 0.78rem; color: var(--text-muted);">Standard SQL dump files supported (.sql)</div>
                    <input type="file" name="backup_file" id="backupUpload" accept=".sql" style="display: none;" onchange="updateFileName(this)">
                    
                    <div id="selectedFile" style="margin-top: 1rem; font-size: 0.825rem; color: var(--success); font-weight: 600; display: none;">
                        <i class="fas fa-file-circle-check"></i> <span id="fileName"></span>
                    </div>
                </div>
                
                <div style="display:flex; justify-content:flex-end; gap:0.65rem; margin-top: 1.5rem;">
                    <button type="button" onclick="closeModal('uploadModal')" class="btn btn-secondary">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-upload"></i> Upload Snapshot
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Modal Controls
        function openModal(id) {
            const modal = document.getElementById(id);
            if (modal) modal.classList.add('show');
        }
        
        function closeModal(id) {
            const modal = document.getElementById(id);
            if (modal) modal.classList.remove('show');
        }

        document.querySelectorAll('.modal-backdrop').forEach(backdrop => {
            backdrop.addEventListener('click', (e) => {
                if (e.target === backdrop) closeModal(backdrop.id);
            });
        });

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                document.querySelectorAll('.modal-backdrop.show').forEach(m => closeModal(m.id));
            }
        });

        // Search & Source Filtering
        function filterBackups() {
            const query = document.getElementById('backupSearch').value.toLowerCase();
            const sourceFilter = document.getElementById('sourceFilter').value;
            const rows = document.querySelectorAll('.backup-row');
            let visibleCount = 0;
            
            rows.forEach(row => {
                const filename = row.querySelector('.filename-text')?.textContent.toLowerCase() || '';
                const author = row.querySelector('.author-text')?.textContent.toLowerCase() || '';
                const rowSource = row.getAttribute('data-source') || '';
                
                const matchesSearch = filename.includes(query) || author.includes(query);
                const matchesSource = (sourceFilter === 'all' || rowSource === sourceFilter);
                
                if (matchesSearch && matchesSource) {
                    row.style.display = '';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            });

            const badge = document.getElementById('backupCountBadge');
            if (badge) badge.textContent = visibleCount;
        }

        // Action Trigger Handlers
        function confirmRestore(filename) {
            document.getElementById('restoreFilename').textContent = filename;
            document.getElementById('restoreFileInput').value = filename;
            openModal('restoreModal');
        }
        
        function confirmDelete(filename) {
            document.getElementById('deleteFilename').textContent = filename;
            document.getElementById('deleteFileInput').value = filename;
            openModal('deleteModal');
        }

        function copyToClipboard(text) {
            navigator.clipboard.writeText(text).then(() => {
                alert('Filename copied to clipboard: ' + text);
            });
        }

        // Progress Indicators
        function startBackupProgress() {
            const bar = document.getElementById('backupProgress');
            const fill = document.getElementById('backupProgressFill');
            bar.classList.add('active');
            let width = 0;
            const interval = setInterval(() => {
                if (width >= 90) clearInterval(interval);
                width += 4;
                fill.style.width = width + '%';
            }, 100);
        }

        function showRestoreProgress() {
            const bar = document.getElementById('restoreProgress');
            const fill = document.getElementById('restoreProgressFill');
            bar.classList.add('active');
            let width = 0;
            const interval = setInterval(() => {
                if (width >= 90) clearInterval(interval);
                width += 2;
                fill.style.width = width + '%';
            }, 200);
        }

        // File Upload Selection Feedback
        function updateFileName(input) {
            if (input.files && input.files[0]) {
                const file = input.files[0];
                document.getElementById('fileName').textContent = file.name + ' (' + (file.size / 1024 / 1024).toFixed(2) + ' MB)';
                document.getElementById('selectedFile').style.display = 'block';
            }
        }

        // Drag and Drop Zone Handling
        const dropZone = document.getElementById('dropZone');
        if (dropZone) {
            ['dragenter', 'dragover'].forEach(eventName => {
                dropZone.addEventListener(eventName, (e) => {
                    e.preventDefault();
                    dropZone.classList.add('dragover');
                }, false);
            });

            ['dragleave', 'drop'].forEach(eventName => {
                dropZone.addEventListener(eventName, (e) => {
                    e.preventDefault();
                    dropZone.classList.remove('dragover');
                }, false);
            });

            dropZone.addEventListener('drop', (e) => {
                const dt = e.dataTransfer;
                const files = dt.files;
                if (files.length && files[0].name.toLowerCase().endsWith('.sql')) {
                    document.getElementById('backupUpload').files = files;
                    updateFileName(document.getElementById('backupUpload'));
                } else {
                    alert('Please select a valid .sql backup snapshot.');
                }
            });
        }

        // Theme and Dropdown Handlers
        document.addEventListener('DOMContentLoaded', () => {
            if (localStorage.getItem('theme') === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                document.documentElement.classList.add('dark');
            } else {
                document.documentElement.classList.remove('dark');
            }

            document.getElementById('themeToggle')?.addEventListener('click', () => {
                const isDark = document.documentElement.classList.toggle('dark');
                localStorage.setItem('theme', isDark ? 'dark' : 'light');
            });

            const userPill = document.getElementById('userDropdownTrigger');
            const userMenu = document.getElementById('userDropdown');
            if (userPill && userMenu) {
                userPill.addEventListener('click', (e) => {
                    e.stopPropagation();
                    userMenu.classList.toggle('show');
                });
                document.addEventListener('click', () => userMenu.classList.remove('show'));
            }

            const flashAlert = document.getElementById('flashAlert');
            if (flashAlert) {
                setTimeout(() => {
                    flashAlert.style.opacity = '0';
                    flashAlert.style.transition = 'opacity 0.4s ease';
                    setTimeout(() => flashAlert.remove(), 400);
                }, 5000);
            }
        });

        if (window.history.replaceState) {
            window.history.replaceState(null, null, window.location.pathname);
        }
    </script>
</body>
</html>