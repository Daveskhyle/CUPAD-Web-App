<?php
session_start();
require_once '../includes/config.php';
$pdo = getDbConnection();

if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: ../index.php');
    exit();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function log_audit_trail($pdo, $action, $table, $record_id, $old_values =[], $new_values =[]) {
    try {
        $stmt = $pdo->prepare("INSERT INTO audit_log (user_id, username, action, table_name, record_id, old_values, new_values, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $_SESSION['user_id'] ?? 0,
            $_SESSION['username'] ?? 'admin',
            $action,
            $table,
            $record_id,
            json_encode($old_values),
            json_encode($new_values),
            $_SERVER['REMOTE_ADDR'],
            $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
        ]);
    } catch (Exception $e) {
        error_log("Audit Log Failed: " . $e->getMessage());
    }
}

function update_client_savings_balance($pdo, $client_id, $delta_amount) {
    if (!$client_id || $delta_amount == 0) return true;
    
    $stmt = $pdo->prepare("SELECT balance FROM saving_balances WHERE client_id = ?");
    $stmt->execute([$client_id]);
    $current = $stmt->fetchColumn();
    $new_balance = ($current ? floatval($current) : 0) + $delta_amount;
    
    $pdo->prepare("DELETE FROM saving_balances WHERE client_id = ?")->execute([$client_id]);
    $stmt = $pdo->prepare("INSERT INTO saving_balances (client_id, balance) VALUES (?, ?)");
    return $stmt->execute([$client_id, $new_balance]);
}

function update_loan_balance($pdo, $disbursement_id, $delta_amount) {
    if (!$disbursement_id || $delta_amount == 0) return true;

    $stmt = $pdo->prepare("SELECT remaining_balance FROM disbursements WHERE id = ?");
    $stmt->execute([$disbursement_id]);
    $loan = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$loan) return false;

    $new_balance = max(0, floatval($loan['remaining_balance']) + $delta_amount);
    $status = ($new_balance <= 0) ? 'completed' : 'active';
    $payoff_date = ($status === 'completed') ? date('Y-m-d') : null;

    $updateSql = "UPDATE disbursements SET remaining_balance = ?, status = ?";
    $params = [$new_balance, $status];

    if ($payoff_date) {
        $updateSql .= ", payoff_date = ?";
        $params[] = $payoff_date;
    } else {
        $updateSql .= ", payoff_date = NULL";
    }

    $updateSql .= " WHERE id = ?";
    $params[] = $disbursement_id;

    $updStmt = $pdo->prepare($updateSql);
    return $updStmt->execute($params);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Invalid token']);
        exit();
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'get_archives') {
        try {
            $stmt = $pdo->query("SELECT id, original_id, transaction_type, source_table, record_data, action_type, archived_by, archived_at FROM transaction_archives WHERE action_type = 'DELETE' ORDER BY archived_at DESC");
            $archives = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($archives as &$archive) {
                $data = json_decode($archive['record_data'], true);
                $archive['client_id'] = $data['client_id'] ?? null;
                $archive['amount'] = $data['amount'] ?? $data['principal'] ?? $data['amount_collected'] ?? 0;
                $archive['date'] = $data['date'] ?? null;
                $archive['sub_type'] = isset($data['type']) ? strtolower(trim($data['type'])) : '';
                
                if ($archive['client_id']) {
                    $stmt = $pdo->prepare("SELECT name FROM clients WHERE id = ?");
                    $stmt->execute([$archive['client_id']]);
                    $archive['client_name'] = $stmt->fetchColumn() ?: 'Unknown';
                }
            }
            
            echo json_encode(['success' => true, 'data' => $archives]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit();
    }

    if ($action === 'delete_permanent') {
        try {
            $archive_id = $_POST['archive_id'] ?? '';
            if (!$archive_id) throw new Exception("Archive ID required");

            $stmt = $pdo->prepare("SELECT * FROM transaction_archives WHERE id = ?");
            $stmt->execute([$archive_id]);
            $archive = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$archive) throw new Exception("Archive not found");

            $pdo->prepare("DELETE FROM transaction_archives WHERE id = ?")->execute([$archive_id]);
            log_audit_trail($pdo, 'PERMANENT_DELETE', 'transaction_archives', $archive_id, json_decode($archive['record_data'], true), []);
            
            echo json_encode(['success' => true, 'message' => 'Transaction permanently deleted']);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Delete failed: ' . $e->getMessage()]);
        }
        exit();
    }

    if ($action === 'restore') {
        try {
            $archive_id = $_POST['archive_id'] ?? '';
            
            if (!$archive_id) throw new Exception("Archive ID required");

            $stmt = $pdo->prepare("SELECT * FROM transaction_archives WHERE id = ?");
            $stmt->execute([$archive_id]);
            $archive = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$archive) throw new Exception("Archive not found");
            
            $data = json_decode($archive['record_data'], true);
            $pdo->beginTransaction();

            if ($archive['source_table'] === 'disbursements') {
                $cols = [];
                $vals =[];
                foreach ($data as $key => $value) {
                    $cols[] = $key;
                    $vals[] = $value;
                }
                $placeholders = implode(',', array_fill(0, count($vals), '?'));
                $stmt = $pdo->prepare("INSERT INTO disbursements (" . implode(',', $cols) . ") VALUES ($placeholders)");
                $stmt->execute($vals);
                
                log_audit_trail($pdo, 'RESTORE', 'disbursements', $data['id'] ?? $pdo->lastInsertId(),[], $data);

            } elseif ($archive['source_table'] === 'saving_collections') {
                $cols = [];
                $vals =[];
                foreach ($data as $key => $value) {
                    $cols[] = $key;
                    $vals[] = $value;
                }
                $placeholders = implode(',', array_fill(0, count($vals), '?'));
                $stmt = $pdo->prepare("INSERT INTO saving_collections (" . implode(',', $cols) . ") VALUES ($placeholders)");
                $stmt->execute($vals);

                $amount = abs(floatval($data['amount']));
                $recType = strtolower(trim($data['type']));
                $is_deposit = ($recType === 'deposit' || $recType === 'interest');
                $delta = $is_deposit ? $amount : -1 * $amount;
                
                update_client_savings_balance($pdo, $data['client_id'], $delta);
                
                // Restore linked loan collection if withdrawal/return
                if ($recType === 'withdrawal' || $recType === 'return') {
                    $findLc = $pdo->prepare("SELECT * FROM transaction_archives WHERE source_table = 'loan_collections' AND action_type = 'DELETE' AND archived_at >= ? AND archived_at <= DATE_ADD(?, INTERVAL 5 SECOND) ORDER BY archived_at ASC LIMIT 1");
                    $findLc->execute([$archive['archived_at'], $archive['archived_at']]);
                    $linkedArchive = $findLc->fetch(PDO::FETCH_ASSOC);
                    
                    if ($linkedArchive) {
                        $linkedData = json_decode($linkedArchive['record_data'], true);
                        if ($linkedData['client_id'] == $data['client_id'] && abs(floatval($linkedData['amount_collected'])) == $amount) {
                            $lcCols = [];
                            $lcVals =[];
                            foreach ($linkedData as $key => $value) {
                                $lcCols[] = $key;
                                $lcVals[] = $value;
                            }
                            $lcPlaceholders = implode(',', array_fill(0, count($lcVals), '?'));
                            $stmt = $pdo->prepare("INSERT INTO loan_collections (" . implode(',', $lcCols) . ") VALUES ($lcPlaceholders)");
                            $stmt->execute($lcVals);
                            
                            if (isset($linkedData['disbursement_id']) && $linkedData['disbursement_id']) {
                                update_loan_balance($pdo, $linkedData['disbursement_id'], -1 * $amount);
                            }
                            
                            $pdo->prepare("DELETE FROM transaction_archives WHERE id = ?")->execute([$linkedArchive['id']]);
                            log_audit_trail($pdo, 'RESTORE', 'loan_collections', $linkedData['transaction_id'],[], $linkedData);
                        }
                    } else {
                        $findLoan = $pdo->prepare("SELECT id FROM disbursements WHERE client_id = ? AND status IN ('active', 'completed') ORDER BY date DESC LIMIT 1");
                        $findLoan->execute([$data['client_id']]);
                        $loan_id = $findLoan->fetchColumn();
                        if ($loan_id) {
                            update_loan_balance($pdo, $loan_id, -1 * $amount);
                        }
                    }
                }
                
                log_audit_trail($pdo, 'RESTORE', 'saving_collections', $data['transaction_id'], [], $data);

            } elseif ($archive['source_table'] === 'loan_collections') {
                $cols = [];
                $vals =[];
                foreach ($data as $key => $value) {
                    $cols[] = $key;
                    $vals[] = $value;
                }
                $placeholders = implode(',', array_fill(0, count($vals), '?'));
                $stmt = $pdo->prepare("INSERT INTO loan_collections (" . implode(',', $cols) . ") VALUES ($placeholders)");
                $stmt->execute($vals);

                if (isset($data['disbursement_id']) && $data['disbursement_id']) {
                    $amount_collected = abs(floatval($data['amount_collected']));
                    update_loan_balance($pdo, $data['disbursement_id'], -1 * $amount_collected);
                }
                
                log_audit_trail($pdo, 'RESTORE', 'loan_collections', $data['transaction_id'],[], $data);
            }

            $pdo->prepare("DELETE FROM transaction_archives WHERE id = ?")->execute([$archive_id]);
            
            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Transaction restored successfully']);

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Restore failed: ' . $e->getMessage()]);
        }
        exit();
    }
}

$base_path = '../';
$full_name = $_SESSION['full_name'] ?? 'Admin';
$username = $_SESSION['username'] ?? '';
$role = $_SESSION['role'] ?? 'admin';

// Profile Picture Fetch
$my_pic = 'default_avatar.png';
try {
    $stmt = $pdo->prepare("SELECT profile_pic FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user_data = $stmt->fetch();
    $my_pic = $user_data['profile_pic'] ?? 'default_avatar.png';
} catch (Exception $e) { }

$profile_pic_path = $base_path . 'uploads/' . $my_pic;
$has_profile_pic = file_exists($profile_pic_path) && $my_pic !== 'default_avatar.png';
?>
<!DOCTYPE html>
<html lang="en" class="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Restore Transactions - CUPAD</title>
    <link rel="icon" type="image/png" href="<?php echo htmlspecialchars($base_path . 'uploads/CUPAD LOGO.png'); ?>">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <style>
        :root { 
            --primary: #2563eb; 
            --primary-dark: #1d4ed8; 
            --primary-light: #eff6ff;
            --success: #059669; 
            --warning: #d97706; 
            --danger: #dc2626; 
            --info: #0284c7; 
            --bg-body: #f8fafc; 
            --bg-surface: #ffffff; 
            --bg-hover: #f1f5f9;
            --text-main: #0f172a; 
            --text-muted: #64748b; 
            --border: #e2e8f0; 
            --radius-lg: 16px; 
            --radius-md: 10px; 
            --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05); 
            --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1); 
            --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1);
            --nav-height: 70px; 
        }
        html.dark { 
            --bg-body: #0f172a; 
            --bg-surface: #1e293b; 
            --bg-hover: #334155;
            --text-main: #f8fafc; 
            --text-muted: #94a3b8; 
            --border: #334155; 
            --primary: #3b82f6; 
            --primary-light: rgba(59, 130, 246, 0.1);
            --success: #34d399; 
            --warning: #fbbf24; 
            --danger: #f87171; 
            --info: #38bdf8; 
        }

        /* Custom Scrollbars */
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: var(--bg-body); border-radius: 4px; }
        ::-webkit-scrollbar-thumb { background: var(--border); border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: var(--text-muted); }

        * { box-sizing: border-box; outline: none; margin: 0; padding: 0; }
        body { 
            font-family: 'Plus Jakarta Sans', sans-serif; 
            background: var(--bg-body); 
            color: var(--text-main); 
            margin: 0; 
            padding-top: var(--nav-height); 
            transition: background-color 0.3s, color 0.3s; 
        }
        a { text-decoration: none; color: inherit; }
        
        /* Header/Nav */
        .main-header { 
            position: fixed; 
            top: 0; 
            left: 0; 
            right: 0; 
            height: var(--nav-height); 
            background: rgba(255,255,255,0.85); 
            backdrop-filter: blur(12px); 
            border-bottom: 1px solid var(--border); 
            z-index: 50; 
            transition: background 0.3s; 
        }
        html.dark .main-header { background: rgba(15, 23, 42, 0.85); }
        .navbar { 
            max-width: 1400px; 
            margin: 0 auto; 
            padding: 0 1.5rem; 
            display: flex; 
            align-items: center; 
            justify-content: space-between; 
            height: 100%; 
        }
        .logo { display: flex; align-items: center; gap: 0.75rem; font-weight: 800; font-size: 1.35rem; color: var(--primary); }
        .logo img { height: 38px; }
        .nav-right { display: flex; align-items: center; gap: 1rem; }
        .icon-btn { 
            width: 36px; height: 36px; border-radius: 50%; border: none; background: transparent; 
            color: var(--text-muted); cursor: pointer; display: flex; align-items: center; 
            justify-content: center; font-size: 1.1rem; transition: 0.2s; 
        }
        .icon-btn:hover { background: var(--bg-body); color: var(--text-main); }
        .user-dropdown-wrap { position: relative; }
        .user-pill { 
            display: flex; align-items: center; gap: 0.75rem; padding: 4px 8px 4px 4px; 
            border: 1px solid var(--border); border-radius: 99px; background: var(--bg-surface); 
            cursor: pointer; transition: all 0.2s; 
        }
        .user-pill:hover { border-color: var(--primary); box-shadow: var(--shadow-sm); }
        .user-avatar { width: 34px; height: 34px; border-radius: 50%; object-fit: cover; }
        .user-avatar-fallback { 
            width: 34px; height: 34px; border-radius: 50%; background: var(--bg-body); 
            color: var(--text-muted); display: flex; align-items: center; justify-content: center; 
            font-size: 1rem; border: 1px solid var(--border); 
        }
        .user-info { display: flex; flex-direction: column; line-height: 1.1; padding-right: 0.5rem; }
        .user-name { font-weight: 600; font-size: 0.85rem; }
        .user-role { font-size: 0.7rem; color: var(--text-muted); text-transform: uppercase; }
        .dropdown-menu { 
            position: absolute; top: 125%; right: 0; background: var(--bg-surface); 
            border: 1px solid var(--border); border-radius: var(--radius-md); box-shadow: var(--shadow-md); 
            min-width: 200px; display: none; z-index: 1000; flex-direction: column; overflow: hidden; 
            animation: scaleIn 0.2s ease; transform-origin: top right; 
        }
        @keyframes scaleIn { from { opacity: 0; transform: scale(0.95); } to { opacity: 1; transform: scale(1); } }
        .dropdown-menu.show { display: flex; }
        .dropdown-item { padding: 0.75rem 1rem; display: flex; align-items: center; gap: 0.75rem; font-size: 0.9rem; transition: 0.2s; }
        .dropdown-item:hover { background: var(--bg-hover); color: var(--primary); }
        .text-danger { color: var(--danger) !important; }

        /* Main Container */
        .container { max-width: 1400px; margin: 0 auto; padding: 2rem 1.5rem 4rem; }
        
        /* Page Header */
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; flex-wrap: wrap; gap: 1rem; }
        .page-title { font-size: 1.5rem; font-weight: 800; display: flex; align-items: center; gap: 0.75rem; }
        .page-title i { color: var(--primary); background: var(--primary-light); padding: 0.6rem; border-radius: var(--radius-md); font-size: 1.25rem;}

        /* Card Styling */
        .card { background: var(--bg-surface); border: 1px solid var(--border); border-radius: var(--radius-lg); overflow: hidden; box-shadow: var(--shadow-sm); }
        .card-header { display: flex; justify-content: space-between; align-items: center; padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--border); flex-wrap: wrap; gap: 1rem;}
        .card-title { font-size: 1.1rem; font-weight: 700; display: flex; align-items: center; gap: 0.5rem; }
        
        /* Search Bar */
        .header-actions { display: flex; align-items: center; gap: 1rem; width: 100%; max-width: 450px; justify-content: flex-end; }
        .search-box { position: relative; width: 100%; max-width: 280px; }
        .search-box i.fa-search { position: absolute; left: 1rem; top: 50%; transform: translateY(-50%); color: var(--text-muted); pointer-events: none;}
        .search-box input { 
            width: 100%; padding: 0.6rem 2.5rem 0.6rem 2.5rem; border: 1px solid var(--border); 
            border-radius: var(--radius-md); background: var(--bg-body); color: var(--text-main); 
            font-size: 0.9rem; transition: all 0.2s; font-family: inherit;
        }
        .search-box input:focus { border-color: var(--primary); box-shadow: 0 0 0 3px var(--primary-light); }
        .search-box .clear-search {
            position: absolute; right: 0.8rem; top: 50%; transform: translateY(-50%);
            color: var(--text-muted); cursor: pointer; display: none; padding: 4px;
            background: transparent; border: none; font-size: 0.9rem;
        }
        .search-box .clear-search:hover { color: var(--danger); }

        /* Table Styling */
        .table-container { overflow-x: auto; max-height: 600px; overflow-y: auto; }
        table { width: 100%; border-collapse: separate; border-spacing: 0; }
        th, td { padding: 1rem 1.5rem; text-align: left; border-bottom: 1px solid var(--border); }
        th { 
            background: var(--bg-surface); font-weight: 700; text-transform: uppercase; 
            font-size: 0.75rem; color: var(--text-muted); letter-spacing: 0.5px; white-space: nowrap;
            position: sticky; top: 0; z-index: 10;
        }
        /* Fix bottom border for sticky headers */
        th::after { content: ''; position: absolute; left: 0; right: 0; bottom: 0; border-bottom: 1px solid var(--border); }
        th.text-right, td.text-right { text-align: right; }
        th.text-center, td.text-center { text-align: center; }
        tbody tr { transition: background-color 0.2s ease; }
        tbody tr:hover { background: var(--bg-hover); }
        td { font-size: 0.9rem; color: var(--text-main); white-space: nowrap; }
        .text-muted { color: var(--text-muted); }

        /* Badges */
        .badge { padding: 0.35rem 0.75rem; border-radius: 1rem; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; display: inline-flex; align-items: center; gap: 0.4rem;}
        .badge.disbursement { background: rgba(37, 99, 235, 0.1); color: var(--primary); }
        .badge.savings { background: rgba(5, 150, 105, 0.1); color: var(--success); }
        .badge.loan { background: rgba(217, 119, 6, 0.1); color: var(--warning); }
        .badge.danger { background: rgba(220, 38, 38, 0.1); color: var(--danger); }
        html.dark .badge.disbursement { background: rgba(59, 130, 246, 0.2); }
        html.dark .badge.savings { background: rgba(52, 211, 153, 0.2); }
        html.dark .badge.loan { background: rgba(251, 191, 36, 0.2); }
        html.dark .badge.danger { background: rgba(248, 113, 113, 0.2); }

        /* Buttons */
        .btn { 
            padding: 0.6rem 1.2rem; border-radius: var(--radius-md); border: none; font-weight: 600; 
            cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem; 
            text-decoration: none; font-size: 0.9rem; transition: all 0.2s; font-family: inherit;
        }
        .btn:focus-visible { outline: 2px solid var(--primary); outline-offset: 2px; }
        .btn:hover:not(:disabled) { transform: translateY(-1px); box-shadow: var(--shadow-md); }
        .btn:disabled { opacity: 0.6; cursor: not-allowed; transform: none; box-shadow: none; }
        .btn-primary { background: var(--primary); color: white; }
        .btn-primary:hover:not(:disabled) { background: var(--primary-dark); }
        .btn-success { background: var(--success); color: white; }
        .btn-success:hover:not(:disabled) { filter: brightness(1.1); }
        .btn-danger { background: var(--danger); color: white; }
        .btn-danger:hover:not(:disabled) { filter: brightness(1.1); }
        .btn-secondary { background: transparent; color: var(--text-muted); border: 1px solid var(--border); }
        .btn-secondary:hover:not(:disabled) { background: var(--bg-hover); color: var(--text-main); border-color: var(--text-muted); }
        .btn-sm { padding: 0.4rem 0.8rem; font-size: 0.8rem; }

        /* Empty State */
        .empty-state { text-align: center; padding: 4rem 2rem; color: var(--text-muted); }
        .empty-state i { font-size: 3rem; margin-bottom: 1rem; opacity: 0.5; display: block; color: var(--border); }
        .empty-state p { font-size: 1.05rem; font-weight: 500; }
        .empty-state span { display: block; font-size: 0.85rem; margin-top: 0.5rem; color: var(--text-muted); }

        /* Toast Notifications */
        .toast-container { position: fixed; top: 85px; right: 20px; z-index: 9999; display: flex; flex-direction: column; gap: 10px; pointer-events: none; }
        .toast { 
            pointer-events: auto; background: var(--bg-surface); color: var(--text-main); 
            padding: 1rem 1.25rem; border-radius: var(--radius-md); box-shadow: var(--shadow-lg); 
            border-left: 4px solid var(--primary); min-width: 250px; display: flex; align-items: center; gap: 0.75rem; 
            font-size: 0.95rem; font-weight: 500; opacity: 0; transform: translateX(50px); animation: slideIn 0.3s forwards; 
        }
        .toast.success { border-left-color: var(--success); }
        .toast.error { border-left-color: var(--danger); }
        @keyframes slideIn { from { opacity: 0; transform: translateX(50px); } to { opacity: 1; transform: translateX(0); } }

        /* Custom Modal */
        .modal-overlay {
            position: fixed; top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0, 0, 0, 0.5); backdrop-filter: blur(4px);
            z-index: 10000; display: flex; align-items: center; justify-content: center;
            opacity: 0; pointer-events: none; transition: opacity 0.3s ease;
        }
        .modal-overlay.active { opacity: 1; pointer-events: auto; }
        .modal {
            background: var(--bg-surface); padding: 2.5rem 2rem; border-radius: var(--radius-lg);
            width: 90%; max-width: 450px; text-align: center;
            transform: translateY(20px) scale(0.95); transition: all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275); 
            box-shadow: var(--shadow-lg); border: 1px solid var(--border);
        }
        .modal-overlay.active .modal { transform: translateY(0) scale(1); }
        .modal-icon {
            width: 64px; height: 64px; border-radius: 50%; background: var(--primary-light);
            color: var(--primary); display: flex; align-items: center; justify-content: center;
            font-size: 1.75rem; margin: 0 auto 1.25rem;
        }
        .modal-title { font-size: 1.35rem; font-weight: 800; margin-bottom: 0.75rem; color: var(--text-main);}
        .modal-desc { color: var(--text-muted); font-size: 0.95rem; margin-bottom: 2rem; line-height: 1.6; }
        .modal-actions { display: flex; gap: 1rem; justify-content: center; }
        .modal-actions .btn { flex: 1; padding: 0.75rem; }

        /* Loading Skeleton */
        .skeleton { background: linear-gradient(90deg, var(--bg-body) 25%, var(--border) 50%, var(--bg-body) 75%); background-size: 200% 100%; animation: loading 1.5s infinite; border-radius: 4px; }
        @keyframes loading { 0% { background-position: 200% 0; } 100% { background-position: -200% 0; } }
        .sk-row { height: 20px; margin: 1rem 1.5rem; }
        
        /* Responsive */
        @media (max-width: 768px) {
            .page-header { flex-direction: column; align-items: flex-start; }
            .card-header { flex-direction: column; align-items: flex-start; }
            .header-actions { justify-content: flex-start; max-width: 100%; }
            .search-box { max-width: 100%; }
            th, td { padding: 0.75rem; font-size: 0.85rem; }
            .btn span.btn-text { display: none; }
            .modal { padding: 1.5rem; }
        }
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
                <button class="icon-btn" id="themeToggle" title="Toggle Theme"><i class="fas fa-moon"></i></button>
                <div class="user-dropdown-wrap">
                    <div class="user-pill" id="userDropdownTrigger" tabindex="0" role="button" aria-haspopup="true" aria-expanded="false">
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

    <div id="toastContainer" class="toast-container" aria-live="polite"></div>

    <!-- Custom Confirm Modal -->
    <div id="confirmModal" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
        <div class="modal">
            <div class="modal-icon"><i class="fas fa-trash-restore-alt"></i></div>
            <h3 class="modal-title" id="modalTitle">Restore Transaction</h3>
            <p class="modal-desc" id="restoreModalDesc">Are you sure you want to restore this transaction? Client balances will be automatically recalculated.</p>
            <div class="modal-actions">
                <button class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                <button class="btn btn-primary" id="confirmRestoreBtn">Yes, Restore</button>
            </div>
        </div>
    </div>

    <!-- Delete Confirm Modal -->
    <div id="deleteModal" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="deleteModalTitle">
        <div class="modal">
            <div class="modal-icon" style="background: rgba(220, 38, 38, 0.1); color: var(--danger);"><i class="fas fa-exclamation-triangle"></i></div>
            <h3 class="modal-title" id="deleteModalTitle">Delete Permanently</h3>
            <p class="modal-desc" id="deleteModalDesc">Are you sure you want to permanently delete this transaction? This action cannot be undone.</p>
            <div class="modal-actions">
                <button class="btn btn-secondary" onclick="closeDeleteModal()">Cancel</button>
                <button class="btn btn-danger" id="confirmDeleteBtn">Yes, Delete</button>
            </div>
        </div>
    </div>

    <main class="container">
        <div class="page-header">
            <h1 class="page-title"><i class="fas fa-history"></i> Restore Transactions</h1>
            <a href="transaction_manager.php" class="btn btn-secondary" title="Return to Transaction Manager">
                <i class="fas fa-arrow-left"></i> <span class="btn-text">Back to Transactions</span>
            </a>
        </div>

        <div class="card">
            <div class="card-header">
                <div class="card-title"><i class="fas fa-archive" style="color: var(--warning)"></i> Archived Records</div>
                <div class="header-actions">
                    <div class="search-box">
                        <i class="fas fa-search"></i>
                        <input type="text" id="searchInput" placeholder="Search archives..." autocomplete="off" aria-label="Search archived transactions">
                        <button class="clear-search" id="clearSearch" title="Clear Search"><i class="fas fa-times"></i></button>
                    </div>
                    <span class="text-muted" id="recordCount" style="font-size: 0.85rem; font-weight: 600;">Loading...</span>
                </div>
            </div>
            <div class="table-container">
                <table id="archivesTable">
                    <thead>
                        <tr>
                            <th>Archive ID</th>
                            <th>TXN ID</th>
                            <th>Client</th>
                            <th>Type</th>
                            <th class="text-right">Amount</th>
                            <th>Date</th>
                            <th>Deleted By</th>
                            <th>Deleted At</th>
                            <th class="text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody id="archiveTableBody">
                        <tr><td colspan="9"><div class="skeleton sk-row"></div><div class="skeleton sk-row"></div><div class="skeleton sk-row"></div></td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <script>
        const CSRF_TOKEN = "<?= $_SESSION['csrf_token'] ?>";
        let currentArchiveIdToRestore = null;
        let currentArchiveIdToDelete = null;
        let allArchives =[];
        
        // Theme Toggle Logic
        const themeToggle = document.getElementById('themeToggle');
        const html = document.documentElement;
        
        themeToggle.addEventListener('click', () => {
            const isDark = html.classList.toggle('dark');
            html.classList.toggle('light');
            localStorage.setItem('theme', isDark ? 'dark' : 'light');
            themeToggle.innerHTML = isDark ? '<i class="fas fa-sun"></i>' : '<i class="fas fa-moon"></i>';
        });
        
        if(localStorage.getItem('theme') === 'dark') {
            html.classList.add('dark');
            html.classList.remove('light');
            themeToggle.innerHTML = '<i class="fas fa-sun"></i>';
        }

        // User Dropdown Accessibility & Interaction
        const userTrigger = document.getElementById('userDropdownTrigger');
        const userDropdown = document.getElementById('userDropdown');
        
        userTrigger.addEventListener('click', (e) => { 
            e.stopPropagation(); 
            const isExpanded = userDropdown.classList.toggle('show');
            userTrigger.setAttribute('aria-expanded', isExpanded);
        });
        userTrigger.addEventListener('keydown', (e) => {
            if(e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                userTrigger.click();
            }
        });
        
        document.addEventListener('click', (e) => { 
            if (!userTrigger.contains(e.target) && !userDropdown.contains(e.target)) {
                userDropdown.classList.remove('show');
                userTrigger.setAttribute('aria-expanded', 'false');
            }
        });

        // Toast Notification System
        function showToast(msg, type = 'success') {
            const div = document.createElement('div');
            div.className = `toast ${type}`;
            const icon = type === 'success' ? 'check-circle' : 'exclamation-circle';
            div.innerHTML = `<i class="fas fa-${icon}"></i> <span>${msg}</span>`;
            document.getElementById('toastContainer').appendChild(div);
            setTimeout(() => {
                div.style.opacity = '0';
                div.style.transform = 'translateX(50px)';
                setTimeout(() => div.remove(), 300);
            }, 4000);
        }

        // Search & Filter Logic with Empty States
        const searchInput = document.getElementById('searchInput');
        const clearSearchBtn = document.getElementById('clearSearch');

        searchInput.addEventListener('input', function(e) {
            const query = e.target.value.toLowerCase().trim();
            const rows = document.querySelectorAll('#archiveTableBody tr.data-row');
            const tbody = document.getElementById('archiveTableBody');
            let visibleCount = 0;

            // Toggle clear button visibility
            clearSearchBtn.style.display = query.length > 0 ? 'block' : 'none';

            rows.forEach(row => {
                if (row.textContent.toLowerCase().includes(query)) {
                    row.style.display = '';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            });

            // Update Counter Text
            const countSpan = document.getElementById('recordCount');
            countSpan.textContent = `${visibleCount} record${visibleCount !== 1 ? 's' : ''}`;

            // Handle Search Empty State Visually
            let emptySearchRow = document.getElementById('emptySearchRow');
            if (visibleCount === 0 && query !== '') {
                if (!emptySearchRow) {
                    emptySearchRow = document.createElement('tr');
                    emptySearchRow.id = 'emptySearchRow';
                    emptySearchRow.innerHTML = `
                        <td colspan="9">
                            <div class="empty-state">
                                <i class="fas fa-search" style="color: var(--border)"></i>
                                <p>No archives found matching "<span style="color: var(--text-main)">${query}</span>"</p>
                                <span>Try adjusting your search keywords.</span>
                            </div>
                        </td>`;
                    tbody.appendChild(emptySearchRow);
                } else {
                    emptySearchRow.querySelector('span').textContent = query;
                    emptySearchRow.style.display = '';
                }
            } else if (emptySearchRow) {
                emptySearchRow.style.display = 'none';
            }
        });

        clearSearchBtn.addEventListener('click', () => {
            searchInput.value = '';
            searchInput.dispatchEvent(new Event('input'));
            searchInput.focus();
        });

        // Load Archives from Server
        async function loadArchives() {
            const fd = new FormData();
            fd.append('action', 'get_archives');
            fd.append('csrf_token', CSRF_TOKEN);

            try {
                const res = await fetch('', { method: 'POST', body: fd });
                const json = await res.json();
                
                const tbody = document.getElementById('archiveTableBody');
                const countSpan = document.getElementById('recordCount');
                
                if (json.success) {
                    allArchives = json.data;
                    countSpan.textContent = `${allArchives.length} record${allArchives.length !== 1 ? 's' : ''} found`;
                    
                    if (allArchives.length === 0) {
                        tbody.innerHTML = `
                            <tr>
                                <td colspan="9">
                                    <div class="empty-state">
                                        <i class="fas fa-folder-open"></i>
                                        <p>No deleted transactions found.</p>
                                        <span>Items deleted from the transaction manager will appear here.</span>
                                    </div>
                                </td>
                            </tr>`;
                        return;
                    }
                    
                    tbody.innerHTML = allArchives.map(row => {
                        const type = row.transaction_type.includes('saving') ? 'savings' : 
                                    row.transaction_type.includes('disbursement') ? 'disbursement' : 'loan';
                        
                        // Determine if this is specifically a withdrawal to assign red UI treatments
                        const isWithdrawal = row.transaction_type.toLowerCase().includes('withdrawal') || 
                                           row.transaction_type.toLowerCase().includes('return') || 
                                           row.sub_type === 'withdrawal';
                        
                        let badgeClass = type;
                        let typeIcon = 'piggy-bank';
                        let amountColor = 'success';
                        
                        if(type === 'disbursement') {
                            typeIcon = 'hand-holding-usd';
                            amountColor = 'danger';
                        } else if(type === 'loan') {
                            typeIcon = 'money-check-alt';
                        }
                        
                        if (isWithdrawal) {
                            badgeClass = 'danger';
                            typeIcon = 'arrow-circle-down';
                            amountColor = 'danger';
                        }

                        let displayType = row.transaction_type.replace(/_/g, ' ');
                        if (isWithdrawal && displayType.toLowerCase() === 'saving collections') {
                            displayType = 'saving withdrawal';
                        }

                        const amount = Math.abs(parseFloat(row.amount) || 0);
                        const formattedAmount = `₦${amount.toLocaleString('en-NG', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
                        const formattedDate = row.date ? new Date(row.date).toLocaleDateString('en-NG', {day: '2-digit', month: 'short', year: 'numeric'}) : 'N/A';
                        const formattedArchivedDate = new Date(row.archived_at).toLocaleString('en-NG', {day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit'});

                        return `<tr class="data-row">
                            <td><code style="background: var(--bg-hover); padding: 4px 8px; border-radius: 6px; font-size: 0.8rem; border: 1px solid var(--border); color: var(--primary)">#${row.id}</code></td>
                            <td><strong>${row.original_id}</strong></td>
                            <td style="font-weight: 500;">${row.client_name || '<span class="text-muted"><i>Unknown</i></span>'}</td>
                            <td><span class="badge ${badgeClass}"><i class="fas fa-${typeIcon}"></i> ${displayType}</span></td>
                            <td class="text-right" style="font-weight: 700; color: var(--${amountColor})">
                                ${(isWithdrawal || type === 'disbursement') ? '-' : ''}${formattedAmount}
                            </td>
                            <td class="text-muted">${formattedDate}</td>
                            <td class="text-muted"><i class="fas fa-user-shield" style="font-size: 0.8rem; margin-right:6px; color: var(--border)"></i>${row.archived_by}</td>
                            <td class="text-muted" style="font-size: 0.85rem;">${formattedArchivedDate}</td>
                            <td class="text-center">
                                <button class="btn btn-success btn-sm" onclick="openRestoreModal(${row.id})" title="Restore Transaction" aria-label="Restore transaction ${row.id}">
                                    <i class="fas fa-undo"></i> <span class="btn-text">Restore</span>
                                </button>
                                <button class="btn btn-danger btn-sm" onclick="openDeleteModal(${row.id})" title="Delete Permanently" aria-label="Delete transaction ${row.id}" style="margin-left: 0.5rem;">
                                    <i class="fas fa-trash"></i> <span class="btn-text">Delete</span>
                                </button>
                            </td>
                        </tr>`;
                    }).join('');
                    
                    // Trigger search filter if there's already text in input (e.g. on reload)
                    searchInput.dispatchEvent(new Event('input'));
                } else {
                    throw new Error(json.message);
                }
            } catch (e) {
                showToast('Failed to load archives: ' + e.message, 'error');
                document.getElementById('archiveTableBody').innerHTML = `
                    <tr>
                        <td colspan="9">
                            <div class="empty-state">
                                <i class="fas fa-exclamation-triangle" style="color: var(--danger)"></i>
                                <p>Error loading data. Please refresh the page.</p>
                            </div>
                        </td>
                    </tr>`;
            }
        }

        // Dynamic Confirmation Modal Logic
        const modal = document.getElementById('confirmModal');
        const confirmBtn = document.getElementById('confirmRestoreBtn');

        function openRestoreModal(archiveId) {
            currentArchiveIdToRestore = archiveId;
            const archive = allArchives.find(a => a.id == archiveId);
            const descElement = document.getElementById('restoreModalDesc');

            if (archive) {
                const amount = Math.abs(parseFloat(archive.amount) || 0);
                const formattedAmount = `₦${amount.toLocaleString('en-NG', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
                const typeText = archive.transaction_type.replace(/_/g, ' ').toUpperCase();
                const clientName = archive.client_name || 'Unknown Client';
                
                descElement.innerHTML = `Are you sure you want to restore <strong>${typeText}</strong> for <strong>${clientName}</strong> amount <strong style="color:var(--text-main)">${formattedAmount}</strong>?<br><br><span style="font-size: 0.85rem; color: var(--warning)"><i class="fas fa-info-circle"></i> Client balances will be automatically recalculated.</span>`;
            } else {
                descElement.innerHTML = "Are you sure you want to restore this transaction? Client balances will be automatically recalculated.";
            }

            modal.classList.add('active');
        }

        function closeModal() {
            modal.classList.remove('active');
            currentArchiveIdToRestore = null;
        }

        // Close modal on outside click or ESC
        modal.addEventListener('click', (e) => {
            if(e.target === modal) closeModal();
        });
        document.addEventListener('keydown', (e) => {
            if(e.key === 'Escape' && modal.classList.contains('active')) closeModal();
        });

        // Handle Confirm Action
        confirmBtn.addEventListener('click', async () => {
            if (!currentArchiveIdToRestore) return;
            
            const originalContent = confirmBtn.innerHTML;
            confirmBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Restoring...';
            confirmBtn.disabled = true;
            
            // Disable the specific table button visually so user sees exactly what is processing
            const tableBtn = document.querySelector(`button[onclick="openRestoreModal(${currentArchiveIdToRestore})"]`);
            if(tableBtn) {
                tableBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
                tableBtn.disabled = true;
            }

            const fd = new FormData();
            fd.append('action', 'restore');
            fd.append('archive_id', currentArchiveIdToRestore);
            fd.append('csrf_token', CSRF_TOKEN);

            try {
                const res = await fetch('', { method: 'POST', body: fd });
                const json = await res.json();
                
                if (json.success) {
                    showToast(json.message, 'success');
                    closeModal();
                    loadArchives(); // Reload table data to fetch the updated state
                } else {
                    throw new Error(json.message);
                }
            } catch (e) {
                showToast('Restore failed: ' + e.message, 'error');
                if(tableBtn) {
                    tableBtn.innerHTML = '<i class="fas fa-undo"></i> <span class="btn-text">Restore</span>';
                    tableBtn.disabled = false;
                }
            } finally {
                confirmBtn.innerHTML = originalContent;
                confirmBtn.disabled = false;
            }
        });

        // Delete Modal Logic
        const deleteModal = document.getElementById('deleteModal');
        const confirmDeleteBtn = document.getElementById('confirmDeleteBtn');

        function openDeleteModal(archiveId) {
            currentArchiveIdToDelete = archiveId;
            const archive = allArchives.find(a => a.id == archiveId);
            const descElement = document.getElementById('deleteModalDesc');

            if (archive) {
                const amount = Math.abs(parseFloat(archive.amount) || 0);
                const formattedAmount = `₦${amount.toLocaleString('en-NG', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
                const typeText = archive.transaction_type.replace(/_/g, ' ').toUpperCase();
                const clientName = archive.client_name || 'Unknown Client';
                
                descElement.innerHTML = `Are you sure you want to permanently delete <strong>${typeText}</strong> for <strong>${clientName}</strong> amount <strong style="color:var(--text-main)">${formattedAmount}</strong>?<br><br><span style="font-size: 0.85rem; color: var(--danger)"><i class="fas fa-exclamation-triangle"></i> This action cannot be undone.</span>`;
            } else {
                descElement.innerHTML = "Are you sure you want to permanently delete this transaction? This action cannot be undone.";
            }

            deleteModal.classList.add('active');
        }

        function closeDeleteModal() {
            deleteModal.classList.remove('active');
            currentArchiveIdToDelete = null;
        }

        deleteModal.addEventListener('click', (e) => {
            if(e.target === deleteModal) closeDeleteModal();
        });
        document.addEventListener('keydown', (e) => {
            if(e.key === 'Escape' && deleteModal.classList.contains('active')) closeDeleteModal();
        });

        confirmDeleteBtn.addEventListener('click', async () => {
            if (!currentArchiveIdToDelete) return;
            
            const originalContent = confirmDeleteBtn.innerHTML;
            confirmDeleteBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Deleting...';
            confirmDeleteBtn.disabled = true;
            
            const tableBtn = document.querySelector(`button[onclick="openDeleteModal(${currentArchiveIdToDelete})"]`);
            if(tableBtn) {
                tableBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
                tableBtn.disabled = true;
            }

            const fd = new FormData();
            fd.append('action', 'delete_permanent');
            fd.append('archive_id', currentArchiveIdToDelete);
            fd.append('csrf_token', CSRF_TOKEN);

            try {
                const res = await fetch('', { method: 'POST', body: fd });
                const json = await res.json();
                
                if (json.success) {
                    showToast(json.message, 'success');
                    closeDeleteModal();
                    loadArchives();
                } else {
                    throw new Error(json.message);
                }
            } catch (e) {
                showToast('Delete failed: ' + e.message, 'error');
                if(tableBtn) {
                    tableBtn.innerHTML = '<i class="fas fa-trash"></i> <span class="btn-text">Delete</span>';
                    tableBtn.disabled = false;
                }
            } finally {
                confirmDeleteBtn.innerHTML = originalContent;
                confirmDeleteBtn.disabled = false;
            }
        });

        // Initialize Initial Data Fetch
        document.addEventListener('DOMContentLoaded', loadArchives);

    </script>
</body>
</html>