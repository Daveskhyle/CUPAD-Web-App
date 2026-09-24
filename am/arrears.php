<?php
// am/arrears.php - Area Manager Arrears Management (Custom CSS / Rich UX)
session_start();
require_once '../includes/config.php';
$pdo = getDbConnection();

// Ensure User is Logged In and is an Area Manager (am)
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'am') {
    if (isset($_GET['action'])) {
        http_response_code(403);
        echo json_encode(['error' => 'Unauthorized']);
        exit();
    }
    header('Location: ../index.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username']; 

// Fetch the AM's area_id and profile pic
$stmt_u = $pdo->prepare("SELECT area_id, profile_pic FROM users WHERE id = :uid");
$stmt_u->execute(['uid' => $user_id]);
$user_data = $stmt_u->fetch();

$area_id = $user_data['area_id'] ?? null;
$profile_pic = !empty($user_data['profile_pic']) ? $user_data['profile_pic'] : 'default_avatar.png';

// Fetch all Branches under this AM
$stmt_branches = $pdo->prepare("SELECT id, name FROM branches WHERE area_id = :aid AND status = 'active' ORDER BY name ASC");
$stmt_branches->execute(['aid' => $area_id]);
$area_branches = $stmt_branches->fetchAll();
$branch_ids = array_column($area_branches, 'id');

// Fetch all COs under this AM
$stmt_cos = $pdo->prepare("
    SELECT username, full_name, branch_id 
    FROM users 
    WHERE branch_id IN (SELECT id FROM branches WHERE area_id = :aid) 
    AND role = 'co' 
    AND status = 'active'
    ORDER BY full_name ASC
");
$stmt_cos->execute(['aid' => $area_id]);
$area_cos = $stmt_cos->fetchAll();

// --- AJAX & EXPORT HANDLERS ---
if (isset($_GET['action'])) {
    $action = $_GET['action'];
    
    if ($action === 'load_arrears' || $action === 'export_csv') {
        
        $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
        $limit = isset($_GET['limit']) ? max(10, (int)$_GET['limit']) : 20;
        $offset = ($page - 1) * $limit;
        
        // Base Query Setup
        // *Note: Assuming arrears is determined by due_date < CURDATE() and remaining_balance > 0.
        // If your schema uses an `arrears_amount` column or different logic, adjust the WHERE clause accordingly.
        $base_sql = "
            FROM disbursements d
            JOIN clients c ON d.client_id = c.id
            JOIN branches b ON c.branch_id = b.id
            WHERE b.area_id = :aid 
            AND d.status = 'active' 
            AND d.remaining_balance > 0 
            AND d.due_date < CURDATE()
        ";

        $where_clauses = [];
        $params = [':aid' => $area_id];

        // Filters
        if (!empty($_GET['branch']) && $_GET['branch'] !== 'all') {
            $where_clauses[] = "b.id = :branch_id";
            $params[':branch_id'] = $_GET['branch'];
        }
        if (!empty($_GET['officer']) && $_GET['officer'] !== 'all') {
            $where_clauses[] = "d.officer = :officer";
            $params[':officer'] = $_GET['officer'];
        }
        if (!empty($_GET['risk']) && $_GET['risk'] !== 'all') {
            switch($_GET['risk']) {
                case 'low': // 1-15 days
                    $where_clauses[] = "DATEDIFF(CURDATE(), d.due_date) BETWEEN 1 AND 15";
                    break;
                case 'medium': // 16-30 days
                    $where_clauses[] = "DATEDIFF(CURDATE(), d.due_date) BETWEEN 16 AND 30";
                    break;
                case 'high': // 31-60 days
                    $where_clauses[] = "DATEDIFF(CURDATE(), d.due_date) BETWEEN 31 AND 60";
                    break;
                case 'severe': // 60+ days
                    $where_clauses[] = "DATEDIFF(CURDATE(), d.due_date) > 60";
                    break;
            }
        }

        if (!empty($where_clauses)) {
            $base_sql .= " AND " . implode(" AND ", $where_clauses);
        }

        // Export to CSV
        if ($action === 'export_csv') {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="arrears_report_' . date('Y-m-d') . '.csv"');
            
            $output = fopen('php://output', 'w');
            fputcsv($output,['Client Name', 'Phone', 'Branch', 'Officer', 'Principal (NGN)', 'Remaining Balance (NGN)', 'Due Date', 'Days Overdue']);
            
            $export_sql = "
                SELECT 
                    c.name, c.phone, b.name as branch_name, d.officer, 
                    d.principal, d.remaining_balance, d.due_date, 
                    DATEDIFF(CURDATE(), d.due_date) as days_overdue
                " . $base_sql . " ORDER BY days_overdue DESC";
                
            $stmt = $pdo->prepare($export_sql);
            $stmt->execute($params);
            
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                fputcsv($output,[
                    $row['name'],
                    $row['phone'],
                    $row['branch_name'],
                    $row['officer'],
                    $row['principal'],
                    $row['remaining_balance'],
                    $row['due_date'],
                    $row['days_overdue']
                ]);
            }
            fclose($output);
            exit();
        }

        // Action: load_arrears (AJAX)
        if ($action === 'load_arrears') {
            // Summary Stats for Cards
            $stats_sql = "SELECT COUNT(d.id) as total_clients, COALESCE(SUM(d.remaining_balance), 0) as total_at_risk " . $base_sql;
            $stmt_stats = $pdo->prepare($stats_sql);
            $stmt_stats->execute($params);
            $summary = $stmt_stats->fetch(PDO::FETCH_ASSOC);

            // Count for Pagination
            $count_sql = "SELECT COUNT(d.id) " . $base_sql;
            $stmt_count = $pdo->prepare($count_sql);
            $stmt_count->execute($params);
            $total_records = $stmt_count->fetchColumn();
            $total_pages = ceil($total_records / $limit);

            // Fetch Paginated Data
            $data_sql = "
                SELECT 
                    c.name as client_name, 
                    c.phone, 
                    b.name as branch_name, 
                    d.principal, 
                    d.remaining_balance, 
                    d.due_date, 
                    d.officer,
                    DATEDIFF(CURDATE(), d.due_date) as days_overdue
                " . $base_sql . " 
                ORDER BY days_overdue DESC 
                LIMIT " . (int)$limit . " OFFSET " . (int)$offset;
                
            $stmt_data = $pdo->prepare($data_sql);
            $stmt_data->execute($params);
            $arrears_data = $stmt_data->fetchAll(PDO::FETCH_ASSOC);

            // Generate HTML
            $html = '';
            foreach ($arrears_data as $row) {
                $days = $row['days_overdue'];
                
                // Determine Risk Color/Level
                if ($days <= 15) { $risk_color = '#f59e0b'; $risk_label = 'Warning'; } // Yellow
                elseif ($days <= 30) { $risk_color = '#ea580c'; $risk_label = 'High Risk'; } // Orange
                else { $risk_color = '#ef4444'; $risk_label = 'Severe'; } // Red

                $html .= '
                <tr class="table-row">
                    <td>
                        <div class="td-flex">
                            <div class="icon-box" style="background: '.$risk_color.'15; color: '.$risk_color.';">
                                <i class="fas fa-exclamation-triangle"></i>
                            </div>
                            <div>
                                <div class="client-name">'.htmlspecialchars($row['client_name']).'</div>
                                <div class="sub-text"><i class="fas fa-building"></i> '.htmlspecialchars($row['branch_name']).'</div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <div class="td-flex align-center">
                            <div class="officer-avatar"><i class="fas fa-user-tie"></i></div>
                            <span class="officer-name">'.htmlspecialchars($row['officer']).'</span>
                        </div>
                    </td>
                    <td>
                        <span class="type-badge" style="background: '.$risk_color.'10; color: '.$risk_color.'; border-color: '.$risk_color.'30;">
                            <span class="dot" style="background: '.$risk_color.';"></span>
                            '.$days.' Days Overdue
                        </span>
                    </td>
                    <td>
                        <div class="amount-text" style="color: #ef4444;">₦'.number_format($row['remaining_balance']).'</div>
                        <div class="sub-text">of ₦'.number_format($row['principal']).'</div>
                    </td>
                </tr>';
            }

            if (empty($arrears_data)) {
                $html = '
                <tr>
                    <td colspan="4">
                        <div class="empty-state">
                            <div class="empty-icon" style="color: #22c55e; background: rgba(34, 197, 94, 0.1);">
                                <i class="fas fa-check-circle"></i>
                            </div>
                            <h3>Clean Portfolio</h3>
                            <p>Great job! No loans are currently in arrears based on your selected filters.</p>
                        </div>
                    </td>
                </tr>';
            }

            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'html' => $html,
                'total_pages' => $total_pages,
                'current_page' => $page,
                'total_records' => $total_records,
                'summary' =>[
                    'total_clients' => number_format($summary['total_clients']),
                    'total_at_risk' => '₦' . number_format($summary['total_at_risk'])
                ]
            ]);
            exit();
        }
    }
}

$base_path = '../';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>Arrears Management | CUPAD AM</title>
    
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">    
    
    <style>
        /* CSS RESET & VARIABLES */
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            -webkit-tap-highlight-color: transparent;
        }

        :root {
            --primary-color: #3b82f6;
            --primary-hover: #2563eb;
            --danger-color: #ef4444;
            --warning-color: #f59e0b;
            --bg-body: #f8fafc;
            --bg-card: #ffffff;
            --bg-input: #ffffff;
            --text-main: #0f172a;
            --text-muted: #64748b;
            --border-color: #e2e8f0;
            --shadow-soft: 0 4px 20px -2px rgba(0, 0, 0, 0.05);
            --shadow-inner: inset 0 2px 4px 0 rgba(0, 0, 0, 0.06);
            --glass-bg: rgba(255, 255, 255, 0.85);
            --table-hover: #f8fafc;
            --table-header: #f8fafc;
        }

        html.dark {
            --primary-color: #3b82f6;
            --primary-hover: #60a5fa;
            --bg-body: #0f172a;
            --bg-card: #1e293b;
            --bg-input: #1e293b;
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
            --border-color: #334155;
            --shadow-soft: 0 4px 20px -2px rgba(0, 0, 0, 0.3);
            --shadow-inner: inset 0 2px 4px 0 rgba(0, 0, 0, 0.5);
            --glass-bg: rgba(15, 23, 42, 0.85);
            --table-hover: rgba(30, 41, 59, 0.7);
            --table-header: rgba(30, 41, 59, 0.5);
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-body);
            color: var(--text-main);
            padding-bottom: 90px;
            line-height: 1.5;
            transition: background-color 0.3s, color 0.3s;
        }

        /* HEADER */
        .glass-header {
            background: var(--glass-bg);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border-bottom: 1px solid var(--border-color);
            position: sticky;
            top: 0;
            z-index: 50;
        }

        .navbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.8rem 1rem;
            max-width: 1280px;
            margin: 0 auto;
        }

        .logo {
            font-weight: 800;
            font-size: 1.25rem;
            color: var(--primary-color);
            display: flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
            letter-spacing: -0.5px;
        }

        .logo img { height: 32px; width: auto; }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .action-btn {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-muted);
            background: transparent;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            font-size: 1.1rem;
        }

        .action-btn:hover { background-color: rgba(100, 116, 139, 0.1); color: var(--text-main); }
        .btn-logout { color: #ef4444; }
        .btn-logout:hover { background-color: rgba(239, 68, 68, 0.1); color: #dc2626; }

        .profile-btn {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            overflow: hidden;
            border: 2px solid var(--primary-color);
            background: var(--bg-card);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            flex-shrink: 0;
            transition: transform 0.2s;
        }
        
        .profile-btn:hover { transform: scale(1.05); }
        .profile-btn img { width: 100%; height: 100%; object-fit: cover; position: absolute; inset: 0; }
        .profile-fallback-icon { font-size: 1.2rem; color: var(--text-muted); }
        
        html.dark .icon-sun { display: inline-block; }
        html.dark .icon-moon { display: none; }
        html:not(.dark) .icon-sun { display: none; }
        html:not(.dark) .icon-moon { display: inline-block; }

        /* MAIN CONTAINER */
        .container {
            max-width: 1280px;
            margin: 0 auto;
            padding: 2rem 1rem;
        }

        /* PAGE HEADER */
        .page-header {
            display: flex;
            flex-direction: column;
            gap: 1.25rem;
            margin-bottom: 1.5rem;
        }

        @media (min-width: 640px) {
            .page-header { flex-direction: row; align-items: flex-end; justify-content: space-between; }
        }

        .page-title {
            font-size: 1.875rem;
            font-weight: 800;
            letter-spacing: -0.025em;
            margin-bottom: 0.25rem;
        }

        .page-subtitle {
            font-size: 0.875rem;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-export {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            padding: 0.625rem 1.25rem;
            background-color: #0f172a;
            color: white;
            font-size: 0.875rem;
            font-weight: 600;
            border-radius: 0.75rem;
            border: none;
            cursor: pointer;
            box-shadow: var(--shadow-soft);
            transition: transform 0.1s, background-color 0.2s;
            text-decoration: none;
        }

        html.dark .btn-export { background-color: var(--primary-color); }
        html.dark .btn-export:hover { background-color: var(--primary-hover); }
        html:not(.dark) .btn-export:hover { background-color: #1e293b; }
        .btn-export:active { transform: scale(0.97); }

        /* SUMMARY CARDS */
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.25rem;
            margin-bottom: 2rem;
        }

        .summary-card {
            background-color: var(--bg-card);
            border-radius: 1rem;
            padding: 1.5rem;
            box-shadow: var(--shadow-soft);
            border: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            gap: 1.25rem;
        }

        .summary-icon {
            width: 56px;
            height: 56px;
            border-radius: 1rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            flex-shrink: 0;
        }

        .icon-danger { background: rgba(239, 68, 68, 0.1); color: var(--danger-color); }
        .icon-warning { background: rgba(245, 158, 11, 0.1); color: var(--warning-color); }

        .summary-details h4 {
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            margin-bottom: 0.25rem;
            letter-spacing: 0.05em;
        }

        .summary-details p {
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--text-main);
            letter-spacing: -0.025em;
        }

        /* CARDS */
        .card {
            background-color: var(--bg-card);
            border-radius: 1rem;
            box-shadow: var(--shadow-soft);
            border: 1px solid var(--border-color);
            margin-bottom: 2rem;
            position: relative;
            overflow: hidden;
        }

        /* FILTER FORM */
        .filter-header {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 1.5rem 1.5rem 0;
            margin-bottom: 1.25rem;
        }

        .filter-icon-box {
            width: 32px;
            height: 32px;
            border-radius: 0.5rem;
            background-color: rgba(59, 130, 246, 0.1);
            color: var(--primary-color);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.875rem;
        }

        .filter-title { font-size: 1rem; font-weight: 700; }

        .filter-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1.25rem;
            padding: 0 1.5rem 1.5rem;
        }

        @media (min-width: 768px) { .filter-grid { grid-template-columns: repeat(3, 1fr); } }

        .input-group { position: relative; }
        
        .input-label {
            display: block;
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--text-muted);
            margin-bottom: 0.375rem;
            margin-left: 0.25rem;
            text-transform: uppercase;
            letter-spacing: 0.025em;
        }

        .floating-input {
            width: 100%;
            background-color: var(--bg-input);
            border: 1px solid var(--border-color);
            border-radius: 0.75rem;
            padding: 0.625rem 1rem 0.625rem 2.5rem;
            font-size: 0.875rem;
            color: var(--text-main);
            outline: none;
            transition: all 0.2s;
            font-family: inherit;
        }

        .floating-input:focus { border-color: var(--primary-color); box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15); }

        .input-icon {
            position: absolute;
            left: 1rem;
            top: 2.1rem;
            color: var(--text-muted);
            pointer-events: none;
            font-size: 0.875rem;
        }

        .select-input {
            appearance: none;
            background-image: url('data:image/svg+xml;charset=US-ASCII,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%22292.4%22%20height%3D%22292.4%22%3E%3Cpath%20fill%3D%22%2394a3b8%22%20d%3D%22M287%2069.4a17.6%2017.6%200%200%200-13-5.4H18.4c-5%200-9.3%201.8-12.9%205.4A17.6%2017.6%200%200%200%200%2082.2c0%205%201.8%209.3%205.4%2012.9l128%20127.9c3.6%203.6%207.8%205.4%2012.8%205.4s9.2-1.8%2012.8-5.4L287%2095c3.5-3.5%205.4-7.8%205.4-12.8%200-5-1.9-9.2-5.5-12.8z%22%2F%3E%3C%2Fsvg%3E');
            background-repeat: no-repeat;
            background-position: right 1rem center;
            background-size: 0.65rem auto;
        }

        .filter-actions {
            grid-column: 1 / -1;
            display: flex;
            justify-content: flex-end;
            gap: 0.75rem;
            margin-top: 0.5rem;
        }

        .btn-clear {
            padding: 0.625rem 1.25rem;
            border-radius: 0.75rem;
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--text-muted);
            background-color: rgba(100, 116, 139, 0.1);
            border: none;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-clear:hover { background-color: rgba(100, 116, 139, 0.2); color: var(--text-main); }

        /* TABLE STYLES */
        .table-wrapper { width: 100%; overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; text-align: left; }
        th { padding: 1rem 1.25rem; font-size: 0.75rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; background-color: var(--table-header); border-bottom: 1px solid var(--border-color); white-space: nowrap; }
        td { padding: 1rem 1.25rem; vertical-align: middle; border-bottom: 1px solid var(--border-color); white-space: nowrap; }
        
        .table-row { transition: background-color 0.2s; }
        .table-row:hover { background-color: var(--table-hover); }

        .td-flex { display: flex; align-items: center; gap: 0.75rem; }
        .icon-box { width: 40px; height: 40px; border-radius: 0.75rem; display: flex; align-items: center; justify-content: center; font-size: 1rem; flex-shrink: 0; }
        
        .client-name { font-size: 0.875rem; font-weight: 600; color: var(--text-main); }
        .sub-text { font-size: 0.75rem; color: var(--text-muted); margin-top: 0.125rem; }
        .sub-text i { margin-right: 0.25rem; }

        .type-badge { display: inline-flex; align-items: center; gap: 0.375rem; padding: 0.25rem 0.625rem; border-radius: 0.375rem; font-size: 0.75rem; font-weight: 600; border: 1px solid; }
        .dot { width: 6px; height: 6px; border-radius: 50%; }

        .amount-text { font-size: 0.875rem; font-weight: 700; }

        .officer-avatar { width: 24px; height: 24px; border-radius: 50%; background-color: rgba(100, 116, 139, 0.1); border: 1px solid var(--border-color); display: flex; align-items: center; justify-content: center; font-size: 0.625rem; color: var(--text-muted); }
        .officer-name { font-size: 0.875rem; font-weight: 500; color: var(--text-muted); }

        /* EMPTY STATE */
        .empty-state { display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 4rem 1rem; text-align: center; }
        .empty-icon { width: 64px; height: 64px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; margin-bottom: 1rem; box-shadow: var(--shadow-inner); }
        .empty-state h3 { font-size: 1.125rem; font-weight: 600; margin-bottom: 0.25rem; }
        .empty-state p { font-size: 0.875rem; color: var(--text-muted); max-width: 300px; }

        /* PAGINATION */
        .pagination-container { display: flex; flex-direction: column; align-items: center; justify-content: space-between; padding: 1rem 1.5rem; border-top: 1px solid var(--border-color); background-color: var(--bg-card); gap: 1rem; }
        @media (min-width: 640px) { .pagination-container { flex-direction: row; } }
        
        .total-records { font-size: 0.875rem; color: var(--text-muted); font-weight: 500; background-color: rgba(100, 116, 139, 0.05); padding: 0.375rem 0.75rem; border-radius: 0.5rem; }
        .total-records span { font-weight: 700; color: var(--text-main); }
        
        .pagination-controls { display: flex; align-items: center; gap: 0.5rem; }
        .page-btn { width: 36px; height: 36px; display: flex; align-items: center; justify-content: center; border-radius: 0.5rem; border: 1px solid var(--border-color); background-color: var(--bg-card); color: var(--text-muted); cursor: pointer; transition: all 0.2s; }
        .page-btn:not(:disabled):hover { background-color: rgba(100, 116, 139, 0.1); color: var(--text-main); }
        .page-btn:disabled { opacity: 0.4; cursor: not-allowed; }
        
        .page-info { font-size: 0.875rem; font-weight: 600; color: var(--text-muted); padding: 0 0.5rem; }
        .page-info .current { color: var(--primary-color); }

        /* SKELETON LOADING */
        @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.4; } }
        .skeleton-row td { padding: 1rem 1.25rem; }
        .skeleton-block { background-color: rgba(100, 116, 139, 0.2); border-radius: 0.25rem; animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite; }
        .sk-avatar { width: 40px; height: 40px; border-radius: 0.75rem; }
        .sk-text-lg { height: 16px; width: 120px; margin-bottom: 6px; }
        .sk-text-sm { height: 12px; width: 80px; }
        .sk-badge { height: 24px; width: 90px; border-radius: 0.375rem; }
        .sk-amount { height: 20px; width: 70px; margin-bottom: 6px; }
        .sk-officer-icon { width: 24px; height: 24px; border-radius: 50%; }

        /* MOBILE BOTTOM NAV */
        .mobile-bottom-nav {
            display: none; position: fixed; bottom: 0; left: 0; right: 0; background: var(--bg-card); border-top: 1px solid var(--border-color); padding: 0.5rem 1rem calc(0.5rem + env(safe-area-inset-bottom)); z-index: 100; justify-content: space-between; box-shadow: 0 -10px 20px rgba(0,0,0,0.02);
        }
        @media (max-width: 768px) { .mobile-bottom-nav { display: flex; } }
        
        .nav-item { display: flex; flex-direction: column; align-items: center; justify-content: center; text-decoration: none; color: var(--text-muted); font-size: 0.7rem; font-weight: 500; gap: 4px; flex: 1; transition: color 0.2s; }
        .nav-item i { font-size: 1.4rem; }
        .nav-item.active { color: var(--primary-color); }
        .nav-item.active span { font-weight: 700; margin-top: 4px; }
        
        .nav-fab { background: var(--danger-color); width: 48px; height: 48px; border-radius: 1rem; display: flex; align-items: center; justify-content: center; color: white; margin-top: -28px; box-shadow: 0 8px 20px rgba(239, 68, 68, 0.4); transform: rotate(-10deg); transition: transform 0.3s; }
        .nav-fab i { font-size: 1.3rem; margin: 0; transform: rotate(10deg); }

        /* Custom Scrollbar */
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        html.dark ::-webkit-scrollbar-thumb { background: #475569; }
        ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
        .hidden { display: none !important; }
    </style>
</head>

<body>
    <!-- HEADER -->
    <header class="glass-header">
        <nav class="navbar">
            <a href="dashboard.php" class="logo">
                <img src="<?php echo htmlspecialchars($base_path . 'favicon.ico'); ?>" alt="Logo">
                <span>CUPAD AM</span>
            </a>
            
            <div class="header-actions">
                <button id="theme-toggle" class="action-btn" title="Toggle Theme">
                    <i class="fas fa-moon icon-moon"></i>
                    <i class="fas fa-sun icon-sun"></i>
                </button>
                
                <a href="dashboard.php" class="action-btn" title="Notifications">
                    <i class="fas fa-bell"></i>
                </a>

                <div class="profile-btn" onclick="window.location.href='profile.php'" title="Profile">
                    <i class="fas fa-user profile-fallback-icon"></i>
                    <?php if (isset($profile_pic) && $profile_pic !== 'default_avatar.png'): ?>
                        <img src="<?php echo htmlspecialchars($base_path . $profile_pic); ?>" alt="Profile" onerror="this.style.display='none'"> 
                    <?php endif; ?>
                </div>

                <a href="<?php echo htmlspecialchars($base_path . 'logout.php'); ?>" class="action-btn btn-logout" title="Logout">
                    <i class="fas fa-sign-out-alt"></i>
                </a>
            </div>
        </nav>
    </header>

    <main class="container">
        
        <!-- Page Header -->
        <div class="page-header">
            <div>
                <h1 class="page-title">Arrears Management</h1>
                <p class="page-subtitle">
                    <i class="fas fa-exclamation-triangle" style="color: var(--danger-color);"></i> 
                    Area-wide portfolio at risk tracking.
                </p>
            </div>
            <button id="export-btn" class="btn-export">
                <i class="fas fa-cloud-download-alt"></i> Export CSV
            </button>
        </div>

        <!-- SUMMARY CARDS -->
        <div class="summary-grid">
            <div class="summary-card">
                <div class="summary-icon icon-danger">
                    <i class="fas fa-wallet"></i>
                </div>
                <div class="summary-details">
                    <h4>Total At-Risk Balance</h4>
                    <p id="summary-balance">₦0</p>
                </div>
            </div>
            <div class="summary-card">
                <div class="summary-icon icon-warning">
                    <i class="fas fa-users"></i>
                </div>
                <div class="summary-details">
                    <h4>Clients in Arrears</h4>
                    <p id="summary-clients">0</p>
                </div>
            </div>
        </div>

        <!-- FILTERS CARD -->
        <div class="card">
            <div class="filter-header">
                <div class="filter-icon-box" style="background: rgba(239, 68, 68, 0.1); color: var(--danger-color);">
                    <i class="fas fa-filter"></i>
                </div>
                <h2 class="filter-title">Filter Portfolio</h2>
            </div>

            <form id="filter-form" class="filter-grid">
                
                <div class="input-group">
                    <label class="input-label" for="branch">Branch</label>
                    <i class="fas fa-building input-icon"></i>
                    <select id="branch" name="branch" class="floating-input select-input">
                        <option value="all">All Branches</option>
                        <?php foreach($area_branches as $branch): ?>
                            <option value="<?php echo htmlspecialchars($branch['id']); ?>">
                                <?php echo htmlspecialchars($branch['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="input-group">
                    <label class="input-label" for="officer">Credit Officer</label>
                    <i class="fas fa-id-badge input-icon"></i>
                    <select id="officer" name="officer" class="floating-input select-input">
                        <option value="all">All Officers</option>
                        <?php foreach($area_cos as $co): ?>
                            <option value="<?php echo htmlspecialchars($co['username']); ?>">
                                <?php echo htmlspecialchars($co['full_name'] . ' (' . $co['username'] . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="input-group">
                    <label class="input-label" for="risk">Risk Level (Days Overdue)</label>
                    <i class="fas fa-thermometer-half input-icon"></i>
                    <select id="risk" name="risk" class="floating-input select-input">
                        <option value="all">All Arrears</option>
                        <option value="low">Warning (1 - 15 Days)</option>
                        <option value="medium">High Risk (16 - 30 Days)</option>
                        <option value="high">Critical (31 - 60 Days)</option>
                        <option value="severe">Severe (60+ Days)</option>
                    </select>
                </div>

                <div class="filter-actions">
                    <button type="button" id="reset-btn" class="btn-clear">Clear Filters</button>
                    <button type="submit" class="hidden">Apply</button>
                </div>
            </form>
        </div>

        <!-- RESULTS TABLE CARD -->
        <div class="card">
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Client Details</th>
                            <th>Credit Officer</th>
                            <th>Status</th>
                            <th>At Risk Amount</th>
                        </tr>
                    </thead>
                    <tbody id="arrears-tbody">
                        <!-- Content populated via JS -->
                    </tbody>
                </table>
            </div>
            
            <!-- PAGINATION -->
            <div class="pagination-container">
                <div class="total-records">
                    Total: <span id="total-records">0</span> records
                </div>
                
                <div class="pagination-controls">
                    <button id="prev-page" class="page-btn"><i class="fas fa-chevron-left"></i></button>
                    <span class="page-info">
                        Page <span id="current-page-badge" class="current">1</span> of <span id="total-pages">1</span>
                    </span>
                    <button id="next-page" class="page-btn"><i class="fas fa-chevron-right"></i></button>
                </div>
            </div>
        </div>

    </main>

    <!-- MOBILE BOTTOM NAVIGATION -->
    <nav class="mobile-bottom-nav">
        <a href="dashboard.php" class="nav-item">
            <i class="fas fa-home"></i>
            <span>Home</span>
        </a>
        <a href="branches.php" class="nav-item">
            <i class="fas fa-building"></i>
            <span>Branches</span>
        </a>
        <a href="clients.php" class="nav-item">
            <i class="fas fa-users"></i>
            <span>Clients</span>
        </a>
        <a href="arrears.php" class="nav-item active">
            <div class="nav-fab">
                <i class="fas fa-exclamation-triangle"></i>
             </div>
             <span>Arrears</span>
        </a>
    </nav>

    <script>
        // Theme initialization
        if(localStorage.getItem('theme') === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            document.documentElement.classList.add('dark');
        }
        document.getElementById('theme-toggle').addEventListener('click', () => {
            document.documentElement.classList.toggle('dark');
            localStorage.setItem('theme', document.documentElement.classList.contains('dark') ? 'dark' : 'light');
        });

        class ArrearsManager {
            constructor() {
                this.currentPage = 1;
                this.totalPages = 1;
                this.limit = 20; 
                this.isLoading = false;
                
                this.cacheDOM();
                this.bindEvents();
                this.loadData();
            }

            cacheDOM() {
                this.form = document.getElementById('filter-form');
                this.tbody = document.getElementById('arrears-tbody');
                
                this.btnPrev = document.getElementById('prev-page');
                this.btnNext = document.getElementById('next-page');
                
                this.totalPagesSpan = document.getElementById('total-pages');
                this.totalRecords = document.getElementById('total-records');
                this.badge = document.getElementById('current-page-badge');
                
                this.summaryBalance = document.getElementById('summary-balance');
                this.summaryClients = document.getElementById('summary-clients');

                this.inputs = this.form.querySelectorAll('select');
            }

            bindEvents() {
                // Auto-submit on select change
                this.inputs.forEach(input => {
                    input.addEventListener('change', () => {
                        this.currentPage = 1;
                        this.loadData();
                    });
                });

                this.form.addEventListener('submit', (e) => {
                    e.preventDefault();
                    this.currentPage = 1;
                    this.loadData();
                });

                document.getElementById('reset-btn').addEventListener('click', () => {
                    this.form.reset();
                    this.currentPage = 1;
                    this.loadData();
                });

                document.getElementById('export-btn').addEventListener('click', () => this.exportCSV());

                this.btnPrev.addEventListener('click', () => {
                    if (this.currentPage > 1 && !this.isLoading) {
                        this.currentPage--;
                        this.loadData();
                    }
                });

                this.btnNext.addEventListener('click', () => {
                    if (this.currentPage < this.totalPages && !this.isLoading) {
                        this.currentPage++;
                        this.loadData();
                    }
                });
            }

            getFilters() {
                return new URLSearchParams(new FormData(this.form)).toString();
            }

            renderSkeleton() {
                let html = '';
                for(let i=0; i<5; i++) {
                    html += `
                    <tr class="skeleton-row">
                        <td>
                            <div class="td-flex">
                                <div class="skeleton-block sk-avatar"></div>
                                <div>
                                    <div class="skeleton-block sk-text-lg"></div>
                                    <div class="skeleton-block sk-text-sm"></div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <div class="td-flex align-center">
                                <div class="skeleton-block sk-officer-icon"></div>
                                <div class="skeleton-block sk-text-sm"></div>
                            </div>
                        </td>
                        <td><div class="skeleton-block sk-badge"></div></td>
                        <td>
                            <div>
                                <div class="skeleton-block sk-amount"></div>
                                <div class="skeleton-block sk-text-sm"></div>
                            </div>
                        </td>
                    </tr>`;
                }
                this.tbody.innerHTML = html;
            }

            async loadData() {
                if(this.isLoading) return;
                this.isLoading = true;
                this.renderSkeleton();
                
                try {
                    const filters = this.getFilters();
                    const url = `arrears.php?action=load_arrears&page=${this.currentPage}&limit=${this.limit}&${filters}`;
                    
                    const response = await fetch(url);
                    const data = await response.json();
                    
                    if (data.success) {
                        this.tbody.innerHTML = data.html;
                        this.totalPages = data.total_pages > 0 ? data.total_pages : 1;
                        this.currentPage = data.current_page;
                        
                        this.updatePaginationUI(data.total_records);
                        
                        // Update Summaries
                        if(data.summary) {
                            this.summaryBalance.textContent = data.summary.total_at_risk;
                            this.summaryClients.textContent = data.summary.total_clients;
                        }
                    } else {
                        throw new Error(data.error || 'Failed to load data');
                    }
                } catch (error) {
                    console.error('Error:', error);
                    this.tbody.innerHTML = `<tr><td colspan="4"><div class="empty-state"><div class="empty-icon" style="color:var(--text-muted);"><i class="fas fa-exclamation-triangle"></i></div><h3>Error Loading Data</h3><p>Please check your connection and try again.</p></div></td></tr>`;
                } finally {
                    this.isLoading = false;
                }
            }

            updatePaginationUI(total) {
                this.totalPagesSpan.textContent = this.totalPages;
                this.badge.textContent = this.currentPage;
                this.totalRecords.textContent = parseInt(total).toLocaleString();

                const disablePrev = this.currentPage <= 1;
                const disableNext = this.currentPage >= this.totalPages;

                this.btnPrev.disabled = disablePrev;
                this.btnNext.disabled = disableNext;
            }

            exportCSV() {
                const btn = document.getElementById('export-btn');
                const originalHtml = btn.innerHTML;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Preparing...';
                btn.style.pointerEvents = 'none';
                btn.style.opacity = '0.8';

                const filters = this.getFilters();
                window.location.href = `arrears.php?action=export_csv&${filters}`;

                setTimeout(() => {
                    btn.innerHTML = originalHtml;
                    btn.style.pointerEvents = 'auto';
                    btn.style.opacity = '1';
                }, 2000);
            }
        }

        document.addEventListener('DOMContentLoaded', () => {
            new ArrearsManager();
        });
    </script>
</body>
</html>