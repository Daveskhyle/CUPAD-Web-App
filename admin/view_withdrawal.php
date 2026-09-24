<?php
// admin/view_withdrawals.php
session_start();
require_once '../includes/config.php';
$pdo = getDbConnection();

// --- AUTH CHECK ---
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: ../index.php');
    exit();
}

$base_path = '../';

// Supercharged Helper to extract images
function extractWithdrawalImage($notes, $base_path = '../') {
    $text = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $notes));
    if (preg_match('/Image:\s*([^\n\r]+)/i', $text, $matches)) {
        $fileName = basename(trim($matches[1]));
        if (!empty($fileName)) {
            $possible_folders = ['uploads/withdrawals/', 'uploads/', 'uploads/collections/'];
            foreach ($possible_folders as $folder) {
                if (file_exists($base_path . $folder . $fileName)) {
                    return $folder . $fileName;
                }
            }
            return 'uploads/withdrawals/' . $fileName;
        }
    }
    if (preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $notes, $matches)) {
        $fileName = basename(trim($matches[1]));
        if (file_exists($base_path . 'uploads/' . $fileName)) return 'uploads/' . $fileName;
    }
    return null;
}

// --- AJAX REPLACE IMAGE ENDPOINT ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'replace_image') {
    header('Content-Type: application/json');
    
    $withdrawal_id = isset($_POST['withdrawal_id']) ? (int)$_POST['withdrawal_id'] : 0;
    
    if ($withdrawal_id > 0 && isset($_FILES['new_image']) && $_FILES['new_image']['error'] === UPLOAD_ERR_OK) {
        $fileTmp = $_FILES['new_image']['tmp_name'];
        $fileName = basename($_FILES['new_image']['name']);
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $allowed = ['jpg','jpeg','png','gif','webp'];
        
        if (in_array($ext, $allowed)) {
            $newFileName = uniqid('with_') . '.' . $ext;
            $uploadDir = $base_path . 'uploads/withdrawals/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            
            $uploadPath = $uploadDir . $newFileName;
            
            if (move_uploaded_file($fileTmp, $uploadPath)) {
                $stmt = $pdo->prepare("SELECT notes FROM saving_collections WHERE id = ? AND type IN ('cash', 'withdrawal', 'return')");
                $stmt->execute([$withdrawal_id]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($row !== false) {
                    $oldNotes = $row['notes'] ?? '';
                    $oldImage = extractWithdrawalImage($oldNotes, $base_path);
                    
                    // Strip out the old Image string safely
                    $cleanNotes = preg_replace('/Image:\s*[^\r\n<]+/i', '', $oldNotes);
                    $cleanNotes = preg_replace('/<img[^>]+>/i', '', $cleanNotes);
                    
                    $newNotes = trim($cleanNotes) . "\nImage: " . $newFileName;
                    
                    $upd = $pdo->prepare("UPDATE saving_collections SET notes = ? WHERE id = ?");
                    if ($upd->execute([trim($newNotes), $withdrawal_id])) {
                        
                        // FIX: Only delete the old image from the server if it exists AND is not shared with other records
                        if ($oldImage && is_file($base_path . $oldImage)) {
                            $oldFileName = basename($oldImage);
                            
                            $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM saving_collections WHERE notes LIKE ? AND id != ?");
                            $checkStmt->execute(["%" . $oldFileName . "%", $withdrawal_id]);
                            $isUsedElsewhere = $checkStmt->fetchColumn() > 0;
                            
                            if (!$isUsedElsewhere) {
                                unlink($base_path . $oldImage);
                            }
                        }
                        
                        echo json_encode(['success' => true, 'message' => 'Proof image updated successfully.']);
                        exit();
                    }
                }
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid file format. Allowed: JPG, PNG, GIF, WEBP.']);
            exit();
        }
    }
    echo json_encode(['success' => false, 'message' => 'Failed to upload image. Please try again.']);
    exit();
}

// --- AJAX DATA FETCHING ENDPOINT ---
if (isset($_GET['ajax_fetch'])) {
    header('Content-Type: application/json');
    
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $limit = isset($_GET['limit']) ? max(1, (int)$_GET['limit']) : 25; 
    $offset = ($page - 1) * $limit;

    $base_sql = "FROM saving_collections sc
                 LEFT JOIN clients c ON sc.client_id = c.id
                 LEFT JOIN branches b ON c.branch_id = b.id
                 LEFT JOIN areas a ON b.area_id = a.id
                 LEFT JOIN zones z ON b.zone_id = z.id
                 WHERE sc.amount < 0"; 
    $params =[];

    if (!empty($_GET['withdrawal_type'])) {
        $base_sql .= " AND sc.type = ?";
        $params[] = $_GET['withdrawal_type'];
    } else {
        $base_sql .= " AND sc.type IN ('cash', 'withdrawal', 'return')";
    }
    if (!empty($_GET['zone_id'])) { $base_sql .= " AND z.id = ?"; $params[] = $_GET['zone_id']; }
    if (!empty($_GET['area_id'])) { $base_sql .= " AND a.id = ?"; $params[] = $_GET['area_id']; }
    if (!empty($_GET['branch_id'])) { $base_sql .= " AND c.branch_id = ?"; $params[] = $_GET['branch_id']; }
    if (!empty($_GET['search'])) {
        $base_sql .= " AND (c.name LIKE ? OR sc.officer LIKE ? OR sc.transaction_id LIKE ?)";
        $search_param = "%" . trim($_GET['search']) . "%";
        $params[] = $search_param; $params[] = $search_param; $params[] = $search_param;
    }
    if (!empty($_GET['start_date'])) { $base_sql .= " AND DATE(sc.date) >= ?"; $params[] = $_GET['start_date']; }
    if (!empty($_GET['end_date'])) { $base_sql .= " AND DATE(sc.date) <= ?"; $params[] = $_GET['end_date']; }

    $count_stmt = $pdo->prepare("SELECT COUNT(*) " . $base_sql);
    $count_stmt->execute($params);
    $total_records = $count_stmt->fetchColumn();
    $total_pages = ceil($total_records / $limit);

    $data_sql = "SELECT sc.*, c.name as client_name, c.phone as client_phone, 
                 b.name as branch_name, a.name as area_name, z.name as zone_name " 
              . $base_sql 
              . " ORDER BY sc.date DESC LIMIT " . (int)$limit . " OFFSET " . (int)$offset;
    
    $data_stmt = $pdo->prepare($data_sql);
    $data_stmt->execute($params);
    $records = $data_stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($records as &$rec) {
        $clean_notes = $rec['notes'] ?? '';
        $image_path = extractWithdrawalImage($clean_notes, $base_path);
        
        if ($image_path) {
            $clean_notes = preg_replace('/Image:\s*[^\r\n<]+/i', '', $clean_notes);
            $clean_notes = preg_replace('/<img[^>]+>/i', '', $clean_notes);
        }

        $rec['has_image'] = $image_path && file_exists($base_path . $image_path);
        $rec['image_url'] = $rec['has_image'] ? htmlspecialchars($base_path . $image_path) : null;
        $rec['clean_notes'] = nl2br(htmlspecialchars(trim($clean_notes) ?: 'No additional notes provided.'));
        
        $rec['txn_id_safe'] = htmlspecialchars($rec['transaction_id']);
        $rec['client_name_safe'] = htmlspecialchars($rec['client_name']);
        $rec['client_id_safe'] = htmlspecialchars($rec['client_id']);
        $rec['client_phone_safe'] = htmlspecialchars($rec['client_phone'] ?? 'N/A');
        $rec['branch_safe'] = htmlspecialchars($rec['branch_name'] ?? 'Unassigned');
        $rec['area_safe'] = htmlspecialchars($rec['area_name'] ?? '-');
        $rec['zone_safe'] = htmlspecialchars($rec['zone_name'] ?? '-');
        $rec['officer_safe'] = htmlspecialchars($rec['officer']);
        
        $abs_amount = abs((float)$rec['amount']);
        $rec['amount_formatted'] = number_format($abs_amount, 2);
        $rec['balance_after_fmt'] = number_format((float)$rec['balance_after'], 2);
        
        $rec['date_fmt'] = date('M d, Y', strtotime($rec['date']));
        $rec['time_fmt'] = date('h:i A', strtotime($rec['date']));

        $type_map = ['cash' => 'Cash (CSH)', 'withdrawal' => 'Withdrawal (WTH)', 'return' => 'Return (RTN)'];
        $rec['type_friendly'] = isset($type_map[$rec['type']]) ? $type_map[$rec['type']] : ucfirst($rec['type']);
    }

    echo json_encode([
        'records' => $records,
        'total_records' => $total_records,
        'total_pages' => $total_pages,
        'current_page' => $page
    ]);
    exit();
}

// --- USER PROFILE FETCH ---
$full_name = $_SESSION['full_name'] ?? 'Admin';
$username = $_SESSION['username'] ?? '';
$role = $_SESSION['user_role'] ?? '';

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

// --- FETCH HIERARCHY DATA FOR FILTERS INITIALIZATION ---
$zones = $pdo->query("SELECT * FROM zones ORDER BY name ASC")->fetchAll();
$areas = $pdo->query("SELECT id, name, zone_id FROM areas ORDER BY name ASC")->fetchAll();
$branches = $pdo->query("SELECT id, name, area_id, zone_id FROM branches ORDER BY name ASC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en" class="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cash Withdrawals - CUPAD</title>
    <link rel="icon" type="image/png" href="<?php echo htmlspecialchars($base_path . 'uploads/CUPAD LOGO.png'); ?>">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />
    <style>
        :root { 
            --primary: #4f46e5;       /* Modern Indigo */
            --primary-hover: #4338ca;
            --success: #10b981; 
            --warning: #f59e0b; 
            --danger: #ef4444; 
            
            --bg-main: #f8fafc;       /* Soft Light Gray */
            --bg-surface: #ffffff; 
            --bg-hover: #f1f5f9;
            
            --text-main: #0f172a; 
            --text-muted: #64748b; 
            --border: #e2e8f0; 
            
            --radius-xl: 20px; 
            --radius-lg: 16px; 
            --radius-md: 12px; 
            --radius-sm: 8px;

            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05); 
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03); 
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.05), 0 4px 6px -2px rgba(0, 0, 0, 0.025);
            --shadow-float: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            
            --nav-height: 72px; 
            --skeleton-base: #e2e8f0; --skeleton-light: #f1f5f9;
            
            --font-sans: 'Plus Jakarta Sans', system-ui, sans-serif;
        }
        
        html.dark { 
            --bg-main: #0f172a; 
            --bg-surface: #1e293b; 
            --bg-hover: #334155;
            
            --text-main: #f8fafc; 
            --text-muted: #94a3b8; 
            --border: #334155; 
            
            --primary: #6366f1; 
            --primary-hover: #818cf8;
            
            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.3);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.3);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.4);
            --shadow-float: 0 25px 35px -5px rgba(0, 0, 0, 0.5);

            --skeleton-base: #334155; --skeleton-light: #475569;
        }
        
        * { box-sizing: border-box; outline: none; margin: 0; padding: 0; }
        body { font-family: var(--font-sans); background: var(--bg-main); color: var(--text-main); padding-top: var(--nav-height); transition: background-color 0.3s, color 0.3s; -webkit-font-smoothing: antialiased; }
        a { text-decoration: none; color: inherit; }

        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: var(--bg-main); }
        ::-webkit-scrollbar-thumb { background: var(--border); border-radius: 10px; }
        ::-webkit-scrollbar-thumb:hover { background: var(--text-muted); }
        
        /* Navbar */
        .main-header { position: fixed; top: 0; left: 0; right: 0; height: var(--nav-height); background: rgba(255,255,255,0.8); backdrop-filter: blur(16px); border-bottom: 1px solid var(--border); z-index: 50; transition: background 0.3s; }
        html.dark .main-header { background: rgba(30, 41, 59, 0.8); }
        .navbar { max-width: 1400px; margin: 0 auto; padding: 0 2rem; display: flex; align-items: center; justify-content: space-between; height: 100%; }
        .logo { display: flex; align-items: center; gap: 0.75rem; font-weight: 800; font-size: 1.25rem; color: var(--primary); transition: transform 0.2s;}
        .logo:hover { transform: translateY(-1px); }
        .logo img { height: 36px; }
        .nav-right { display: flex; align-items: center; gap: 1rem; }
        .icon-btn { width: 40px; height: 40px; border-radius: 50%; border: 1px solid transparent; background: transparent; color: var(--text-muted); cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; transition: all 0.2s; }
        .icon-btn:hover { background: var(--bg-hover); color: var(--text-main); }
        .user-pill { display: flex; align-items: center; gap: 0.75rem; padding: 4px 12px 4px 4px; border: 1px solid var(--border); border-radius: 99px; background: var(--bg-surface); cursor: pointer; transition: all 0.2s; box-shadow: var(--shadow-sm); }
        .user-pill:hover { border-color: var(--primary); box-shadow: var(--shadow-md); }
        .user-avatar { width: 36px; height: 36px; border-radius: 50%; object-fit: cover; }
        .user-info { display: flex; flex-direction: column; line-height: 1.2; }
        .user-name { font-weight: 700; font-size: 0.85rem; }
        .user-role { font-size: 0.7rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; }

        /* Container & Grid */
        .container { max-width: 1400px; margin: 0 auto; padding: 2.5rem 2rem 4rem; }
        .page-header { margin-bottom: 2rem; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; }
        .page-title { font-size: 1.75rem; font-weight: 800; letter-spacing: -0.5px; color: var(--text-main); }
        .page-desc { color: var(--text-muted); font-size: 0.95rem; margin-top: 0.25rem; }
        
        .stat-badge { padding: 0.6rem 1.2rem; background: var(--bg-surface); border: 1px solid var(--border); border-radius: var(--radius-md); box-shadow: var(--shadow-sm); font-size: 0.9rem; color: var(--text-muted); display: flex; align-items: center; gap: 0.5rem; font-weight: 500;}
        .stat-badge b { color: var(--primary); font-size: 1.1rem; }

        .card { background: var(--bg-surface); border: 1px solid var(--border); border-radius: var(--radius-lg); padding: 1.5rem; box-shadow: var(--shadow-md); margin-bottom: 1.5rem; }
        
        /* Filters */
        .filter-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 1.25rem; align-items: end; }
        .form-group { display: flex; flex-direction: column; gap: 0.5rem; }
        .form-label { font-size: 0.75rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; }
        .form-control { width: 100%; padding: 0.75rem 1rem; border-radius: var(--radius-sm); border: 1px solid var(--border); background: var(--bg-main); color: var(--text-main); font-family: inherit; font-size: 0.9rem; transition: all 0.2s; box-shadow: inset 0 1px 2px rgba(0,0,0,0.02); }
        .form-control:focus { background: var(--bg-surface); border-color: var(--primary); box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.1); }
        
        .btn { padding: 0.75rem 1.25rem; border-radius: var(--radius-sm); font-weight: 600; cursor: pointer; transition: all 0.2s; font-size: 0.9rem; border: 1px solid transparent; display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem; }
        .btn-primary { background: var(--primary); color: white; box-shadow: 0 2px 4px rgba(79,70,229,0.2); }
        .btn-primary:hover:not(:disabled) { background: var(--primary-hover); transform: translateY(-1px); box-shadow: 0 4px 6px rgba(79,70,229,0.3); }
        .btn-primary:active:not(:disabled) { transform: translateY(0); box-shadow: none; }
        .btn-outline { background: var(--bg-surface); border-color: var(--border); color: var(--text-main); }
        .btn-outline:hover:not(:disabled) { background: var(--bg-hover); border-color: var(--text-muted); }
        .btn-icon { width: 36px; height: 36px; padding: 0; border-radius: var(--radius-sm); display: inline-flex; align-items: center; justify-content: center; border: 1px solid var(--border); background: var(--bg-surface); color: var(--text-muted); transition: 0.2s; cursor: pointer;}
        .btn-icon:hover { color: var(--primary); border-color: var(--primary); background: rgba(79, 70, 229, 0.05); }

        /* Table Design */
        .table-responsive { overflow-x: auto; overflow-y: auto; max-height: 650px; }
        table { width: 100%; border-collapse: separate; border-spacing: 0; min-width: 1000px; }
        th { position: sticky; top: 0; z-index: 10; background: var(--bg-hover); color: var(--text-muted); font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; padding: 1rem 1.25rem; text-align: left; border-bottom: 1px solid var(--border); backdrop-filter: blur(8px); }
        td { padding: 1.25rem; border-bottom: 1px solid var(--border); color: var(--text-main); font-size: 0.9rem; vertical-align: middle; transition: background 0.2s; }
        tr:last-child td { border-bottom: none; }
        tr:hover td { background: var(--bg-hover); }

        .text-strong { font-weight: 700; color: var(--text-main); }
        .text-sub { font-size: 0.8rem; color: var(--text-muted); margin-top: 4px; display: block; }
        
        /* Thumbnails */
        .thumb-box { width: 48px; height: 48px; border-radius: var(--radius-sm); background: var(--bg-main); border: 1px solid var(--border); display: flex; align-items: center; justify-content: center; overflow: hidden; cursor: zoom-in; transition: all 0.2s; }
        .thumb-box img { width: 100%; height: 100%; object-fit: cover; }
        .thumb-box:hover:not(.no-interaction) { transform: scale(1.1) translateY(-2px); box-shadow: var(--shadow-md); border-color: var(--primary); z-index: 10; position: relative;}
        .no-thumb { color: var(--text-muted); font-size: 1.25rem; opacity: 0.4; cursor: default; }

        /* Badges */
        .badge { padding: 0.35rem 0.75rem; border-radius: 99px; font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; display: inline-flex; align-items: center; gap: 4px; }
        .badge-cash { background: rgba(16, 185, 129, 0.1); color: var(--success); border: 1px solid rgba(16, 185, 129, 0.2); }
        .badge-withdrawal { background: rgba(245, 158, 11, 0.1); color: var(--warning); border: 1px solid rgba(245, 158, 11, 0.2); }
        .badge-return { background: rgba(239, 68, 68, 0.1); color: var(--danger); border: 1px solid rgba(239, 68, 68, 0.2); }

        /* Amount styling */
        .amount-cell { font-family: monospace; font-size: 1.1rem; font-weight: 800; color: var(--text-main); }
        
        /* Pagination */
        .pagination-container { display: flex; justify-content: space-between; align-items: center; padding: 1.25rem 1.5rem; border-top: 1px solid var(--border); background: var(--bg-main); }
        .pagination { display: flex; gap: 0.5rem; }
        .pagination button { padding: 0.5rem 0.75rem; min-width: 36px; border: 1px solid var(--border); background: var(--bg-surface); color: var(--text-main); border-radius: var(--radius-sm); cursor: pointer; font-weight: 600; font-size: 0.85rem; transition: all 0.2s; display: flex; align-items: center; justify-content: center; }
        .pagination button:hover:not(:disabled) { border-color: var(--primary); color: var(--primary); }
        .pagination button.active { background: var(--primary); color: white; border-color: var(--primary); }
        .pagination button:disabled { opacity: 0.5; cursor: not-allowed; background: var(--bg-main); }

        /* Skeletons */
        .skeleton { background: linear-gradient(90deg, var(--skeleton-base) 25%, var(--skeleton-light) 50%, var(--skeleton-base) 75%); background-size: 200% 100%; animation: skeletonLoading 1.5s infinite; border-radius: 4px; }
        @keyframes skeletonLoading { 0% { background-position: 200% 0; } 100% { background-position: -200% 0; } }

        /* Modals */
        .modal-overlay { display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(8px); z-index: 2000; align-items: center; justify-content: center; padding: 1.5rem; animation: fadeIn 0.25s ease-out; }
        .modal-container { background: var(--bg-surface); width: 100%; max-width: 1000px; max-height: 90vh; border-radius: var(--radius-xl); display: flex; flex-direction: column; box-shadow: var(--shadow-float); border: 1px solid rgba(255,255,255,0.1); overflow: hidden; animation: scaleIn 0.3s cubic-bezier(0.16, 1, 0.3, 1); }
        
        .modal-header { padding: 1.5rem 2rem; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; background: var(--bg-main); }
        .modal-title { font-size: 1.25rem; font-weight: 800; color: var(--text-main); display: flex; align-items: center; gap: 0.75rem; }
        .close-modal-btn { background: var(--bg-surface); border: 1px solid var(--border); font-size: 1.2rem; color: var(--text-muted); cursor: pointer; transition: 0.2s; width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; }
        .close-modal-btn:hover { background: var(--danger); color: white; border-color: var(--danger); transform: rotate(90deg); }

        .modal-body { padding: 2rem; overflow-y: auto; flex: 1; }
        .detail-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; }
        @media(max-width: 900px) { .detail-grid { grid-template-columns: 1fr; } }

        /* Receipt Card inside Modal */
        .receipt-card { background: var(--bg-main); border: 1px dashed var(--border); border-radius: var(--radius-md); padding: 1.5rem; position: relative; }
        .receipt-card::before, .receipt-card::after { content: ''; position: absolute; width: 24px; height: 24px; background: var(--bg-surface); border-radius: 50%; border: 1px dashed var(--border); top: 50%; transform: translateY(-50%); z-index: 1;}
        .receipt-card::before { left: -13px; border-right-color: transparent; border-top-color: transparent; border-bottom-color: transparent; }
        .receipt-card::after { right: -13px; border-left-color: transparent; border-top-color: transparent; border-bottom-color: transparent; }
        
        .data-list { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 1rem; }
        .data-item { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid rgba(0,0,0,0.05); padding-bottom: 1rem; }
        html.dark .data-item { border-bottom-color: rgba(255,255,255,0.05); }
        .data-item:last-child { border-bottom: none; padding-bottom: 0; }
        .data-label { color: var(--text-muted); font-size: 0.85rem; font-weight: 600; display: flex; align-items: center; gap: 0.5rem; }
        .data-value { color: var(--text-main); font-weight: 700; font-size: 0.95rem; text-align: right; }
        
        /* Toast Notification */
        #toast-container { position: fixed; bottom: 2rem; right: 2rem; z-index: 9999; display: flex; flex-direction: column; gap: 0.5rem; }
        .toast { padding: 1rem 1.5rem; border-radius: var(--radius-md); background: var(--bg-surface); box-shadow: var(--shadow-lg); border-left: 4px solid var(--primary); display: flex; align-items: center; gap: 1rem; font-weight: 600; font-size: 0.9rem; animation: slideInRight 0.3s cubic-bezier(0.16, 1, 0.3, 1); min-width: 300px;}
        .toast.success { border-left-color: var(--success); }
        .toast.error { border-left-color: var(--danger); }
        .toast i { font-size: 1.2rem; }
        .toast.success i { color: var(--success); }
        .toast.error i { color: var(--danger); }

        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
        @keyframes scaleIn { from { opacity: 0; transform: scale(0.95) translateY(10px); } to { opacity: 1; transform: scale(1) translateY(0); } }
        @keyframes slideInRight { from { opacity: 0; transform: translateX(50px); } to { opacity: 1; transform: translateX(0); } }
        
        /* Full Image Zoom */
        #imgModal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.9); backdrop-filter: blur(8px); z-index: 3000; align-items: center; justify-content: center; padding: 2rem; cursor: zoom-out; animation: fadeIn 0.2s; }
        #imgModal img { max-width: 100%; max-height: 90vh; border-radius: var(--radius-lg); box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5); cursor: default; }

        @media print {
            body > *:not(#detailsModal) { display: none !important; }
            #detailsModal { display: block !important; position: static; background: white; padding: 0; }
            .modal-container { box-shadow: none; border: none; max-width: 100%; }
            .modal-header .btn-outline, .close-modal-btn { display: none !important; }
            .receipt-card::before, .receipt-card::after { display: none; }
        }
    </style>
</head>
<body>

    <!-- Toast Container -->
    <div id="toast-container"></div>

    <!-- Header -->
    <header class="main-header">
        <div class="navbar">
            <a href="dashboard.php" class="logo">
                <img src="<?php echo htmlspecialchars($base_path . 'uploads/CUPAD LOGO.png'); ?>" alt="Logo">
                <span>CUPAD</span>
            </a>
            <div class="nav-right">
                <a href="dashboard.php" class="icon-btn" aria-label="Dashboard"><i class="fas fa-home"></i></a>
                <button class="icon-btn" id="themeToggle" aria-label="Theme"><i class="fas fa-moon"></i></button>
                <div class="user-pill">
                    <?php if($has_profile_pic): ?>
                        <img src="<?php echo htmlspecialchars($profile_pic_path); ?>" class="user-avatar" alt="User">
                    <?php else: ?>
                        <div class="user-avatar" style="background: var(--bg-main); border: 1px solid var(--border); display:flex; align-items:center; justify-content:center; color: var(--text-muted);"><i class="fas fa-user"></i></div>
                    <?php endif; ?>
                    <div class="user-info" style="padding-right:0.5rem;">
                        <span class="user-name"><?php echo htmlspecialchars($full_name); ?></span>
                        <span class="user-role"><?php echo ucfirst(htmlspecialchars($role)); ?></span>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <main class="container">
        
        <div class="page-header">
            <div>
                <h1 class="page-title">Payout Directory</h1>
                <p class="page-desc">Track and manage client withdrawals, returns, and attached proofs.</p>
            </div>
            <div class="stat-badge">
                <i class="fas fa-file-invoice-dollar" style="color:var(--text-muted)"></i>
                Total Found: <b id="totalRecordsCounter">0</b>
            </div>
        </div>

        <!-- Filter Card -->
        <div class="card" style="padding: 1.25rem;">
            <form id="filterForm" class="filter-grid">
                <div class="form-group" style="grid-column: 1 / -1; margin-bottom: 0.5rem;">
                    <div style="position: relative;">
                        <i class="fas fa-search" style="position: absolute; left: 1rem; top: 50%; transform: translateY(-50%); color: var(--text-muted);"></i>
                        <input type="text" name="search" id="filter_search" class="form-control" placeholder="Search by Client Name, Txn ID, or Officer..." style="padding-left: 2.5rem; font-size: 1rem;" autocomplete="off">
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label" for="filter_type">Type</label>
                    <select name="withdrawal_type" id="filter_type" class="form-control">
                        <option value="cash" selected>Cash (CSH)</option>
                        <option value="withdrawal">Withdrawal (WTH)</option>
                        <option value="return">Return (RTN)</option>
                        <option value="">All Types</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label" for="filter_zone">Zone</label>
                    <select name="zone_id" id="filter_zone" class="form-control" onchange="filterHierarchies()">
                        <option value="">All Zones</option>
                        <?php foreach ($zones as $z): ?>
                            <option value="<?php echo $z['id']; ?>"><?php echo htmlspecialchars($z['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label" for="filter_area">Area</label>
                    <select name="area_id" id="filter_area" class="form-control" onchange="filterHierarchies()">
                        <option value="">All Areas</option>
                        <?php foreach ($areas as $a): ?>
                            <option value="<?php echo $a['id']; ?>" data-zone="<?php echo $a['zone_id']; ?>"><?php echo htmlspecialchars($a['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label" for="filter_branch">Branch</label>
                    <select name="branch_id" id="filter_branch" class="form-control">
                        <option value="">All Branches</option>
                        <?php foreach ($branches as $b): ?>
                            <option value="<?php echo $b['id']; ?>" data-area="<?php echo $b['area_id']; ?>" data-zone="<?php echo $b['zone_id']; ?>"><?php echo htmlspecialchars($b['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label" for="filter_start">Start Date</label>
                    <input type="date" name="start_date" id="filter_start" class="form-control">
                </div>
                <div class="form-group">
                    <label class="form-label" for="filter_end">End Date</label>
                    <input type="date" name="end_date" id="filter_end" class="form-control">
                </div>
                <div class="form-group" style="align-items: flex-end;">
                    <button type="button" class="btn btn-outline" title="Clear Filters" onclick="resetFilters()" style="width: 100%;">
                        <i class="fas fa-times-circle"></i> Clear
                    </button>
                </div>
            </form>
        </div>

        <!-- Data Table -->
        <div class="card" style="padding: 0; display: flex; flex-direction: column;">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th style="width: 70px; text-align: center;">Proof</th>
                            <th>Transaction</th>
                            <th>Client Info</th>
                            <th>Location / Officer</th>
                            <th style="text-align: right;">Amount</th>
                            <th style="text-align: right; position: sticky; right: 0;">Action</th>
                        </tr>
                    </thead>
                    <tbody id="tableBody">
                        <!-- Content injected via JS -->
                    </tbody>
                </table>
            </div>
            
            <div class="pagination-container" id="paginationContainer">
                <span class="text-sub" id="pageInfoText" style="margin: 0; font-weight: 600;"></span>
                <div class="pagination" id="paginationControls"></div>
            </div>
        </div>
    </main>

    <!-- Details Modal -->
    <div id="detailsModal" class="modal-overlay" onclick="handleModalOverlayClick(event)" aria-modal="true" role="dialog">
        <div class="modal-container">
            <div class="modal-header">
                <div>
                    <h2 class="modal-title">
                        <i class="fas fa-file-invoice" style="color:var(--primary)"></i> Transaction Details
                    </h2>
                    <span class="text-sub" style="font-family: monospace; margin-top: 4px;">ID: <span id="mdl_txn_id"></span></span>
                </div>
                <div style="display:flex; gap:1rem; align-items:center;">
                    <button onclick="window.print()" class="btn btn-outline" aria-label="Print">
                        <i class="fas fa-print"></i> Print
                    </button>
                    <button class="close-modal-btn" onclick="closeDetailsModal()"><i class="fas fa-times"></i></button>
                </div>
            </div>
            
            <div class="modal-body">
                <div class="detail-grid">
                    
                    <!-- Left: Receipt Format -->
                    <div class="receipt-card">
                        <div style="text-align: center; margin-bottom: 2rem;">
                            <div id="mdl_status_wrap" style="margin-bottom: 1rem;"></div>
                            <h3 style="font-size: 2rem; font-weight: 800; font-family: monospace; color: var(--text-main);" id="mdl_amount"></h3>
                            <span class="text-sub">Total Amount Processed</span>
                        </div>
                        
                        <h4 style="font-size: 0.8rem; text-transform: uppercase; color: var(--text-muted); margin-bottom: 1rem; border-bottom: 1px solid var(--border); padding-bottom: 0.5rem;">Entity Info</h4>
                        <ul class="data-list" style="margin-bottom: 2rem;">
                            <li class="data-item">
                                <span class="data-label">Client Name</span>
                                <span class="data-value" id="mdl_client_name"></span>
                            </li>
                            <li class="data-item">
                                <span class="data-label">Phone</span>
                                <span class="data-value" id="mdl_client_phone"></span>
                            </li>
                            <li class="data-item">
                                <span class="data-label">Location</span>
                                <span class="data-value" id="mdl_location"></span>
                            </li>
                            <li class="data-item">
                                <span class="data-label">Handled By</span>
                                <span class="data-value" id="mdl_officer"></span>
                            </li>
                        </ul>

                        <h4 style="font-size: 0.8rem; text-transform: uppercase; color: var(--text-muted); margin-bottom: 1rem; border-bottom: 1px solid var(--border); padding-bottom: 0.5rem;">Meta Data</h4>
                        <ul class="data-list">
                            <li class="data-item">
                                <span class="data-label">Date & Time</span>
                                <span class="data-value" id="mdl_date"></span>
                            </li>
                            <li class="data-item">
                                <span class="data-label">Balance After</span>
                                <span class="data-value" style="color:var(--success)" id="mdl_balance"></span>
                            </li>
                        </ul>
                    </div>

                    <!-- Right: Proof & Notes -->
                    <div style="display: flex; flex-direction: column; gap: 1.5rem;">
                        <div>
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem;">
                                <h4 style="font-size: 1rem; font-weight: 700;">Proof Document</h4>
                                <div style="display:flex; gap:0.5rem;">
                                    <input type="hidden" id="mdl_withdrawal_id">
                                    <input type="file" id="edit_image_file" style="display:none" accept="image/jpeg, image/png, image/webp, image/gif" onchange="uploadNewImage(this)">
                                    <button class="btn btn-outline" style="padding: 0.4rem 0.8rem; font-size: 0.8rem;" id="mdl_btn_replace" onclick="document.getElementById('edit_image_file').click()">
                                        <i class="fas fa-camera"></i> Replace
                                    </button>
                                    <a href="#" id="mdl_btn_download" download class="btn btn-primary" style="padding: 0.4rem 0.8rem; font-size: 0.8rem;">
                                        <i class="fas fa-download"></i> Save
                                    </a>
                                </div>
                            </div>
                            
                            <div id="mdl_image_container" style="width: 100%; height: 320px; background: var(--bg-main); border-radius: var(--radius-md); border: 1px solid var(--border); display: flex; align-items: center; justify-content: center; overflow: hidden; box-shadow: inset var(--shadow-sm);">
                                <!-- Img JS -->
                            </div>
                        </div>

                        <div>
                            <h4 style="font-size: 0.9rem; font-weight: 700; margin-bottom: 0.75rem; color: var(--text-muted);">System Notes</h4>
                            <div id="mdl_notes" style="padding: 1.25rem; background: var(--bg-main); border-radius: var(--radius-md); font-size: 0.9rem; color: var(--text-main); border: 1px solid var(--border); min-height: 80px; line-height: 1.6;">
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </div>

    <!-- Full Image Modal -->
    <div id="imgModal" onclick="handleImgModalClick(event)">
        <img id="modalImg" src="" alt="Proof">
    </div>

    <script>
        let currentRecords = [];
        let searchTimeout = null;
        let currentPage = 1;

        // Theme Toggle
        const themeToggle = document.getElementById('themeToggle');
        const themeIcon = themeToggle.querySelector('i');
        const html = document.documentElement;

        function updateThemeIcon() { themeIcon.className = html.classList.contains('dark') ? 'fas fa-sun' : 'fas fa-moon'; }
        themeToggle.addEventListener('click', () => {
            const isDark = html.classList.toggle('dark');
            html.classList.toggle('light');
            localStorage.setItem('theme', isDark ? 'dark' : 'light');
            updateThemeIcon();
        });
        if (localStorage.getItem('theme') === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            html.classList.add('dark'); html.classList.remove('light');
        }
        updateThemeIcon();

        // Toast Notification System
        function showToast(message, type = 'success') {
            const container = document.getElementById('toast-container');
            const toast = document.createElement('div');
            toast.className = `toast ${type}`;
            const icon = type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle';
            toast.innerHTML = `<i class="fas ${icon}"></i> <span>${message}</span>`;
            container.appendChild(toast);
            setTimeout(() => {
                toast.style.opacity = '0';
                toast.style.transform = 'translateX(50px)';
                toast.style.transition = 'all 0.3s ease';
                setTimeout(() => toast.remove(), 300);
            }, 3000);
        }

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') { closeDetailsModal(); closeImage(); }
        });

        function filterHierarchies() {
            const zoneId = document.getElementById('filter_zone').value;
            const areaSelect = document.getElementById('filter_area');
            const branchSelect = document.getElementById('filter_branch');

            Array.from(areaSelect.options).forEach(opt => {
                if (opt.value === "") return;
                opt.style.display = (zoneId === "" || opt.dataset.zone === zoneId) ? "block" : "none";
            });
            if (areaSelect.selectedOptions[0] && areaSelect.selectedOptions[0].style.display === "none") areaSelect.value = "";

            Array.from(branchSelect.options).forEach(opt => {
                if (opt.value === "") return;
                const matchZone = (zoneId === "" || opt.dataset.zone === zoneId);
                const matchArea = (areaSelect.value === "" || opt.dataset.area === areaSelect.value);
                opt.style.display = (matchZone && matchArea) ? "block" : "none";
            });
            if (branchSelect.selectedOptions[0] && branchSelect.selectedOptions[0].style.display === "none") branchSelect.value = "";
        }
        document.addEventListener("DOMContentLoaded", filterHierarchies);

        const filterForm = document.getElementById('filterForm');
        const autoSubmitInputs = document.querySelectorAll('#filterForm select, #filterForm input[type="date"]');
        autoSubmitInputs.forEach(input => input.addEventListener('change', () => fetchData(1)));

        const searchInput = document.getElementById('filter_search');
        searchInput.addEventListener('input', () => {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => fetchData(1), 350);
        });
        filterForm.addEventListener('submit', (e) => { e.preventDefault(); fetchData(1); });

        function resetFilters() {
            filterForm.reset();
            filterHierarchies();
            fetchData(1);
        }

        function showSkeletonLoader() {
            const tbody = document.getElementById('tableBody');
            let skeletonHtml = '';
            for(let i=0; i<5; i++) {
                skeletonHtml += `<tr>
                    <td style="text-align:center;"><div class="skeleton" style="width:48px;height:48px;border-radius:8px;margin:0 auto"></div></td>
                    <td><div class="skeleton" style="height:14px;width:70%;margin-bottom:6px"></div><div class="skeleton" style="height:10px;width:50%"></div></td>
                    <td><div class="skeleton" style="height:14px;width:80%;margin-bottom:6px"></div><div class="skeleton" style="height:10px;width:60%"></div></td>
                    <td><div class="skeleton" style="height:14px;width:90%"></div></td>
                    <td style="text-align:right;"><div class="skeleton" style="height:18px;width:60%;float:right;"></div></td>
                    <td style="text-align:right;"><div class="skeleton" style="height:32px;width:36px;border-radius:8px;float:right"></div></td>
                </tr>`;
            }
            tbody.innerHTML = skeletonHtml;
        }

        async function fetchData(page = 1) {
            showSkeletonLoader();
            const formData = new FormData(filterForm);
            const params = new URLSearchParams(formData);
            params.append('ajax_fetch', '1');
            params.append('page', page);
            params.append('limit', 25);

            try {
                const res = await fetch('?' + params.toString());
                const data = await res.json();
                currentRecords = data.records;
                currentPage = data.current_page;
                document.getElementById('totalRecordsCounter').innerText = parseInt(data.total_records).toLocaleString();
                renderTable(data.records);
                renderPagination(data.current_page, data.total_pages);
            } catch (err) {
                document.getElementById('tableBody').innerHTML = `<tr><td colspan="6" style="text-align:center; padding: 4rem 2rem; color:var(--danger)">
                    <i class="fas fa-exclamation-triangle" style="font-size:2.5rem; opacity:0.5; margin-bottom:1rem"></i>
                    <h3>Connection Error</h3>
                    <p style="color:var(--text-muted)">Failed to load data. Check your network.</p>
                </td></tr>`;
                document.getElementById('paginationControls').innerHTML = '';
            }
        }

        function renderTable(records) {
            const tbody = document.getElementById('tableBody');
            if (records.length === 0) {
                tbody.innerHTML = `<tr><td colspan="6" style="text-align:center; padding: 4rem 2rem;">
                    <i class="fas fa-folder-open" style="font-size:3rem; color:var(--text-muted); opacity:0.3; margin-bottom:1rem"></i>
                    <h3 style="color:var(--text-main); margin-bottom:0.5rem">No records found</h3>
                    <p style="color:var(--text-muted)">Adjust filters to find what you're looking for.</p>
                </td></tr>`;
                return;
            }

            let html = '';
            records.forEach(d => {
                let imgHtml = d.has_image ? `<img src="${d.image_url}" loading="lazy">` : `<i class="fas fa-image no-thumb"></i>`;
                let thumbClass = d.has_image ? 'thumb-box' : 'thumb-box no-interaction';
                let imgOnClick = d.has_image ? `onclick="openImage('${d.image_url}')"` : '';
                
                let badgeClass = d.type === 'return' ? 'badge-return' : (d.type === 'withdrawal' ? 'badge-withdrawal' : 'badge-cash');
                let badgeIcon = d.type === 'return' ? 'fa-undo' : (d.type === 'withdrawal' ? 'fa-arrow-down' : 'fa-money-bill');

                html += `
                    <tr>
                        <td style="text-align: center;">
                            <div class="${thumbClass}" style="margin: 0 auto;" ${imgOnClick}>${imgHtml}</div>
                        </td>
                        <td>
                            <span class="text-strong" style="font-family:monospace">${d.txn_id_safe}</span>
                            <span class="text-sub"><i class="far fa-calendar-alt"></i> ${d.date_fmt} &bull; ${d.time_fmt}</span>
                            <div style="margin-top:6px"><span class="badge ${badgeClass}"><i class="fas ${badgeIcon}"></i> ${d.type_friendly}</span></div>
                        </td>
                        <td>
                            <span class="text-strong">${d.client_name_safe}</span>
                            <span class="text-sub">ID: ${d.client_id_safe}</span>
                        </td>
                        <td>
                            <span class="text-strong"><i class="fas fa-building text-muted"></i> ${d.branch_safe}</span>
                            <span class="text-sub"><i class="fas fa-user-tie text-muted"></i> ${d.officer_safe}</span>
                        </td>
                        <td style="text-align: right;">
                            <span class="amount-cell" style="color: var(--danger)">-₦${d.amount_formatted}</span>
                        </td>
                        <td style="text-align: right; position: sticky; right: 0; background: inherit;">
                            <button onclick="openDetailsModal('${d.id}')" class="btn-icon" title="View Details">
                                <i class="fas fa-chevron-right"></i>
                            </button>
                        </td>
                    </tr>
                `;
            });
            tbody.innerHTML = html;
        }

        function renderPagination(currentPage, totalPages) {
            const container = document.getElementById('paginationControls');
            document.getElementById('pageInfoText').innerText = totalPages > 0 ? `Showing page ${currentPage} of ${totalPages}` : '';
            if (totalPages <= 0) { container.innerHTML = ''; return; }

            let html = '';
            html += `<button ${currentPage <= 1 ? 'disabled' : `onclick="fetchData(${currentPage - 1})"`}><i class="fas fa-chevron-left"></i></button>`;
            
            let start = Math.max(1, currentPage - 2);
            let end = Math.min(totalPages, currentPage + 2);
            if (start > 1) { html += `<button onclick="fetchData(1)">1</button>`; if (start > 2) html += `<span style="padding:0 4px; color:var(--text-muted)">...</span>`; }
            for (let i = start; i <= end; i++) {
                html += `<button class="${i === currentPage ? 'active' : ''}" onclick="fetchData(${i})">${i}</button>`;
            }
            if (end < totalPages) { if (end < totalPages - 1) html += `<span style="padding:0 4px; color:var(--text-muted)">...</span>`; html += `<button onclick="fetchData(${totalPages})">${totalPages}</button>`; }
            
            html += `<button ${currentPage >= totalPages ? 'disabled' : `onclick="fetchData(${currentPage + 1})"`}><i class="fas fa-chevron-right"></i></button>`;
            container.innerHTML = html;
        }

        function openDetailsModal(id) {
            const record = currentRecords.find(r => r.id == id);
            if (!record) return;

            document.getElementById('mdl_withdrawal_id').value = record.id;
            document.getElementById('mdl_txn_id').innerText = record.txn_id_safe;
            document.getElementById('mdl_amount').innerText = '-₦' + record.amount_formatted;
            document.getElementById('mdl_balance').innerText = '₦' + record.balance_after_fmt;
            document.getElementById('mdl_date').innerText = record.date_fmt + ', ' + record.time_fmt;

            document.getElementById('mdl_client_name').innerText = record.client_name_safe;
            document.getElementById('mdl_client_phone').innerText = record.client_phone_safe;
            document.getElementById('mdl_officer').innerText = record.officer_safe;
            document.getElementById('mdl_location').innerText = `${record.branch_safe} (${record.area_safe})`;
            
            let badgeClass = record.type === 'return' ? 'badge-return' : (record.type === 'withdrawal' ? 'badge-withdrawal' : 'badge-cash');
            document.getElementById('mdl_status_wrap').innerHTML = `<span class="badge ${badgeClass}" style="font-size:0.85rem">${record.type_friendly}</span>`;

            const imgContainer = document.getElementById('mdl_image_container');
            const downloadBtn = document.getElementById('mdl_btn_download');

            if (record.has_image) {
                imgContainer.style.background = 'var(--bg-surface)';
                imgContainer.innerHTML = `<img src="${record.image_url}" style="width:100%; height:100%; object-fit:contain; cursor:zoom-in;" onclick="openImage('${record.image_url}')">`;
                downloadBtn.href = record.image_url; downloadBtn.download = `Proof_${record.txn_id_safe}`;
                downloadBtn.style.display = 'inline-flex';
            } else {
                imgContainer.style.background = 'var(--bg-main)';
                imgContainer.innerHTML = `<div style="text-align:center; color:var(--text-muted);">
                    <i class="fas fa-image" style="font-size:3rem; opacity:0.2; margin-bottom:1rem; display:block;"></i>
                    <span style="font-size:0.9rem">No document attached</span>
                </div>`;
                downloadBtn.style.display = 'none';
            }

            document.getElementById('mdl_notes').innerHTML = record.clean_notes;
            
            document.getElementById('detailsModal').style.display = 'flex';
            document.body.style.overflow = 'hidden'; 
        }

        function closeDetailsModal() {
            document.getElementById('detailsModal').style.display = 'none';
            document.body.style.overflow = ''; 
        }

        function handleModalOverlayClick(e) { if (e.target.id === 'detailsModal') closeDetailsModal(); }

        async function uploadNewImage(input) {
            if (!input.files || input.files.length === 0) return;
            const file = input.files[0];
            const withdrawalId = document.getElementById('mdl_withdrawal_id').value;
            
            if (!withdrawalId) { showToast("Error: Missing transaction ID.", "error"); input.value = ''; return; }
            if (!['image/jpeg', 'image/png', 'image/webp', 'image/gif'].includes(file.type)) {
                showToast("Invalid file type. Allowed: JPG, PNG, GIF, WEBP.", "error"); input.value = ''; return;
            }

            const formData = new FormData();
            formData.append('action', 'replace_image');
            formData.append('withdrawal_id', withdrawalId);
            formData.append('new_image', file);

            const replaceBtn = document.getElementById('mdl_btn_replace');
            const originalText = replaceBtn.innerHTML;
            replaceBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>'; replaceBtn.disabled = true;

            try {
                const res = await fetch(window.location.href, { method: 'POST', body: formData });
                const data = await res.json();
                if (data.success) {
                    showToast("Proof document replaced successfully!");
                    await fetchData(currentPage);
                    openDetailsModal(withdrawalId);
                } else {
                    showToast(data.message || "Upload failed.", "error");
                }
            } catch (error) {
                showToast("Network error during upload.", "error");
            } finally {
                replaceBtn.innerHTML = originalText; replaceBtn.disabled = false; input.value = '';
            }
        }

        const imgModal = document.getElementById('imgModal');
        const modalImg = document.getElementById('modalImg');
        function openImage(src) { modalImg.src = src; imgModal.style.display = 'flex'; }
        function closeImage() { imgModal.style.display = 'none'; }
        function handleImgModalClick(e) { if (e.target === imgModal || e.target === modalImg) closeImage(); }

        document.addEventListener('DOMContentLoaded', () => fetchData(1));
    </script>
</body>
</html>