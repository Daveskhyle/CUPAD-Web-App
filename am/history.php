<?php
// am/history.php - Area Manager Activity History (Custom CSS / Rich UX)
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

// Fetch all COs under this AM's area for filtering
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
$co_usernames = array_column($area_cos, 'username');

// Fallback if no COs exist
if (empty($co_usernames)) {
    $co_usernames =['_NO_COS_']; 
}

// --- AJAX & EXPORT HANDLERS ---
if (isset($_GET['action'])) {
    $action = $_GET['action'];
    
    if ($action === 'load_history' || $action === 'export_csv') {
        
        $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
        $limit = isset($_GET['limit']) ? max(10, (int)$_GET['limit']) : 20;
        $offset = ($page - 1) * $limit;
        
        // Build Base Union Query
        $placeholders = implode(',', array_fill(0, count($co_usernames), '?'));
        $union_params = array_merge($co_usernames, $co_usernames, $co_usernames, $co_usernames);
        
        $base_sql = "
            SELECT type, client_name, amount, date, officer, activity_id FROM (
                SELECT 
                    CASE 
                        WHEN s.type IN ('withdrawal', 'return') OR s.amount < 0 THEN 'Withdrawal'
                        ELSE 'Saving'
                    END as type,
                    c.name as client_name, 
                    ABS(s.amount) as amount, 
                    s.date, 
                    s.officer,
                    s.id as activity_id
                FROM saving_collections s 
                JOIN clients c ON s.client_id = c.id 
                WHERE s.officer IN ($placeholders)
                
                UNION ALL
                
                SELECT 'Payment' as type, c.name as client_name, p.amount_collected as amount, p.date, p.officer, p.id as activity_id 
                FROM loan_collections p 
                JOIN clients c ON p.client_id = c.id 
                WHERE p.officer IN ($placeholders)
                
                UNION ALL
                
                SELECT 'Disbursement' as type, c.name as client_name, d.principal as amount, d.created_at as date, d.officer, d.id as activity_id 
                FROM disbursements d 
                JOIN clients c ON d.client_id = c.id 
                WHERE d.officer IN ($placeholders)

                UNION ALL

                SELECT 'Registration' as type, r.client_name, r.amount, r.date, r.officer, r.id as activity_id
                FROM registrations r 
                WHERE r.officer IN ($placeholders)
            ) as activities
        ";

        // Build Filters
        $where_clauses = ["1=1"];
        $filter_params =[];

        if (!empty($_GET['start_date'])) {
            $where_clauses[] = "DATE(date) >= ?";
            $filter_params[] = $_GET['start_date'];
        }
        if (!empty($_GET['end_date'])) {
            $where_clauses[] = "DATE(date) <= ?";
            $filter_params[] = $_GET['end_date'];
        }
        if (!empty($_GET['type']) && $_GET['type'] !== 'all') {
            $type_map =[
                'saving' => 'Saving',
                'withdrawal' => 'Withdrawal',
                'payment' => 'Payment',
                'disbursement' => 'Disbursement',
                'registration' => 'Registration'
            ];
            if (isset($type_map[$_GET['type']])) {
                $where_clauses[] = "type = ?";
                $filter_params[] = $type_map[$_GET['type']];
            }
        }
        if (!empty($_GET['officer']) && $_GET['officer'] !== 'all') {
            $where_clauses[] = "officer = ?";
            $filter_params[] = $_GET['officer'];
        }

        $where_sql = " WHERE " . implode(' AND ', $where_clauses);
        $final_sql = $base_sql . $where_sql . " ORDER BY date DESC, activity_id DESC";
        $final_params = array_merge($union_params, $filter_params);

        if ($action === 'export_csv') {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="area_activity_history_' . date('Y-m-d') . '.csv"');
            
            $output = fopen('php://output', 'w');
            fputcsv($output,['Date', 'Type', 'Client Name', 'Amount (NGN)', 'Officer']);
            
            $stmt = $pdo->prepare($final_sql);
            $stmt->execute($final_params);
            
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                fputcsv($output,[
                    $row['date'],
                    $row['type'],
                    $row['client_name'],
                    $row['amount'],
                    $row['officer']
                ]);
            }
            fclose($output);
            exit();
        }

        if ($action === 'load_history') {
            // Count total for pagination
            $count_sql = "SELECT COUNT(*) FROM (" . $base_sql . ") as activities " . $where_sql;
            $stmt_count = $pdo->prepare($count_sql);
            $stmt_count->execute($final_params);
            $total_records = $stmt_count->fetchColumn();
            $total_pages = ceil($total_records / $limit);

            // Fetch Paginated Data
            $final_sql .= " LIMIT ? OFFSET ?";
            $final_params[] = (int)$limit;
            $final_params[] = (int)$offset;

            $stmt = $pdo->prepare($final_sql);
            foreach ($final_params as $key => $val) {
                $type = is_int($val) ? PDO::PARAM_INT : PDO::PARAM_STR;
                $stmt->bindValue($key + 1, $val, $type);
            }
            $stmt->execute();
            $activities = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Generate HTML
            $html = '';
            foreach ($activities as $activity) {
                $icon = getActivityIcon($activity['type']);
                $color = getActivityColor($activity['type']);
                
                $timestamp = strtotime($activity['date']);
                $formatted_date = ($timestamp !== false) ? date('M d, Y • h:i A', $timestamp) : htmlspecialchars($activity['date']);
                
                $html .= '
                <tr class="table-row">
                    <td>
                        <div class="td-flex">
                            <div class="icon-box" style="background: '.$color.'15; color: '.$color.';">
                                <i class="fas '.$icon.'"></i>
                            </div>
                            <div>
                                <div class="client-name">'.htmlspecialchars($activity['client_name']).'</div>
                                <div class="date-text"><i class="far fa-clock"></i> '.$formatted_date.'</div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <span class="type-badge" style="background: '.$color.'10; color: '.$color.'; border-color: '.$color.'30;">
                            <span class="dot" style="background: '.$color.';"></span>
                            '.htmlspecialchars($activity['type']).'
                        </span>
                    </td>
                    <td class="amount-text" style="color: '.$color.';">
                        ₦'.number_format($activity['amount']).'
                    </td>
                    <td>
                        <div class="td-flex align-center">
                            <div class="officer-avatar">
                                <i class="fas fa-user-tie"></i>
                            </div>
                            <span class="officer-name">'.htmlspecialchars($activity['officer']).'</span>
                        </div>
                    </td>
                </tr>';
            }

            if(empty($activities)) {
                $html = '
                <tr>
                    <td colspan="4">
                        <div class="empty-state">
                            <div class="empty-icon">
                                <i class="fas fa-folder-open"></i>
                            </div>
                            <h3>No activities found</h3>
                            <p>Try adjusting your filters or search criteria to find what you\'re looking for.</p>
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
                'total_records' => $total_records
            ]);
            exit();
        }
    }
}

// Helper functions 
function getActivityIcon($type) {
    $icons =['Saving' => 'fa-piggy-bank', 'Withdrawal' => 'fa-wallet', 'Payment' => 'fa-money-bill-wave', 'Disbursement' => 'fa-hand-holding-usd', 'Registration' => 'fa-user-plus', 'default' => 'fa-dollar-sign'];
    return $icons[$type] ?? $icons['default'];
}

function getActivityColor($type) {
    $colors =['Saving' => '#10b981', 'Withdrawal' => '#ef4444', 'Payment' => '#8b5cf6', 'Disbursement' => '#3b82f6', 'Registration' => '#f59e0b', 'default' => '#6b7280'];
    return $colors[$type] ?? $colors['default'];
}

$base_path = '../';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>Activity History | CUPAD AM</title>
    
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

        .logo img {
            height: 32px;
            width: auto;
        }

        /* HEADER ACTIONS (MATCHING DASHBOARD) */
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
            position: relative;
        }

        .action-btn:hover {
            background-color: rgba(100, 116, 139, 0.1);
            color: var(--text-main);
        }

        .btn-logout {
            color: #ef4444; /* red-500 */
        }
        
        .btn-logout:hover {
            background-color: rgba(239, 68, 68, 0.1);
            color: #dc2626;
        }

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
        
        .profile-btn:hover {
            transform: scale(1.05);
        }

        .profile-btn img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            position: absolute;
            inset: 0;
        }

        .profile-fallback-icon {
            font-size: 1.2rem;
            color: var(--text-muted);
        }
        
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
            margin-bottom: 2rem;
        }

        @media (min-width: 640px) {
            .page-header {
                flex-direction: row;
                align-items: flex-end;
                justify-content: space-between;
            }
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
        .btn-export i { transition: transform 0.2s; }
        .btn-export:hover i { transform: translateY(-2px); }

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

        .filter-title {
            font-size: 1rem;
            font-weight: 700;
        }

        .filter-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1.25rem;
            padding: 0 1.5rem 1.5rem;
        }

        @media (min-width: 768px) { .filter-grid { grid-template-columns: repeat(2, 1fr); } }
        @media (min-width: 1024px) { .filter-grid { grid-template-columns: repeat(4, 1fr); } }

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

        .floating-input:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);
        }

        .input-icon {
            position: absolute;
            left: 1rem;
            top: 2.4rem; /* Adjusted for label height */
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

        .btn-clear:hover {
            background-color: rgba(100, 116, 139, 0.2);
            color: var(--text-main);
        }

        /* TABLE STYLES */
        .table-wrapper {
            width: 100%;
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
        }

        th {
            padding: 1rem 1.25rem;
            font-size: 0.75rem;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            background-color: var(--table-header);
            border-bottom: 1px solid var(--border-color);
            white-space: nowrap;
        }

        td {
            padding: 1rem 1.25rem;
            vertical-align: middle;
            border-bottom: 1px solid var(--border-color);
            white-space: nowrap;
        }

        .table-row {
            transition: background-color 0.2s;
        }

        .table-row:hover {
            background-color: var(--table-hover);
        }

        .td-flex {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .icon-box {
            width: 40px;
            height: 40px;
            border-radius: 0.75rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            flex-shrink: 0;
        }

        .client-name {
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--text-main);
        }

        .date-text {
            font-size: 0.75rem;
            color: var(--text-muted);
            margin-top: 0.125rem;
        }

        .date-text i { margin-right: 0.25rem; }

        .type-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            padding: 0.25rem 0.625rem;
            border-radius: 0.375rem;
            font-size: 0.75rem;
            font-weight: 600;
            border: 1px solid;
        }

        .dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
        }

        .amount-text {
            font-size: 0.875rem;
            font-weight: 700;
        }

        .officer-avatar {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background-color: rgba(100, 116, 139, 0.1);
            border: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.625rem;
            color: var(--text-muted);
        }

        .officer-name {
            font-size: 0.875rem;
            font-weight: 500;
            color: var(--text-muted);
        }

        /* EMPTY STATE */
        .empty-state {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 4rem 1rem;
            text-align: center;
        }

        .empty-icon {
            width: 64px;
            height: 64px;
            border-radius: 50%;
            background-color: rgba(100, 116, 139, 0.1);
            color: var(--text-muted);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            margin-bottom: 1rem;
            box-shadow: var(--shadow-inner);
        }

        .empty-state h3 {
            font-size: 1.125rem;
            font-weight: 600;
            margin-bottom: 0.25rem;
        }

        .empty-state p {
            font-size: 0.875rem;
            color: var(--text-muted);
            max-width: 300px;
        }

        /* PAGINATION */
        .pagination-container {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: space-between;
            padding: 1rem 1.5rem;
            border-top: 1px solid var(--border-color);
            background-color: var(--bg-card);
            gap: 1rem;
        }

        @media (min-width: 640px) {
            .pagination-container {
                flex-direction: row;
            }
        }

        .total-records {
            font-size: 0.875rem;
            color: var(--text-muted);
            font-weight: 500;
            background-color: rgba(100, 116, 139, 0.05);
            padding: 0.375rem 0.75rem;
            border-radius: 0.5rem;
        }

        .total-records span {
            font-weight: 700;
            color: var(--text-main);
        }

        .pagination-controls {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .page-btn {
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 0.5rem;
            border: 1px solid var(--border-color);
            background-color: var(--bg-card);
            color: var(--text-muted);
            cursor: pointer;
            transition: all 0.2s;
        }

        .page-btn:not(:disabled):hover {
            background-color: rgba(100, 116, 139, 0.1);
            color: var(--text-main);
        }

        .page-btn:disabled {
            opacity: 0.4;
            cursor: not-allowed;
        }

        .page-info {
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--text-muted);
            padding: 0 0.5rem;
        }

        .page-info .current {
            color: var(--primary-color);
        }

        /* SKELETON LOADING */
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.4; }
        }

        .skeleton-row td { padding: 1rem 1.25rem; }
        
        .skeleton-block {
            background-color: rgba(100, 116, 139, 0.2);
            border-radius: 0.25rem;
            animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
        }

        .sk-avatar { width: 40px; height: 40px; border-radius: 0.75rem; }
        .sk-text-lg { height: 16px; width: 120px; margin-bottom: 6px; }
        .sk-text-sm { height: 12px; width: 80px; }
        .sk-badge { height: 24px; width: 90px; border-radius: 0.375rem; }
        .sk-amount { height: 20px; width: 70px; }
        .sk-officer-icon { width: 24px; height: 24px; border-radius: 50%; }

        /* MOBILE BOTTOM NAV */
        .mobile-bottom-nav {
            display: none;
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: var(--bg-card);
            border-top: 1px solid var(--border-color);
            padding: 0.5rem 1rem calc(0.5rem + env(safe-area-inset-bottom));
            z-index: 100;
            justify-content: space-between;
            box-shadow: 0 -10px 20px rgba(0,0,0,0.02);
        }

        @media (max-width: 768px) {
            .mobile-bottom-nav { display: flex; }
        }

        .nav-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            color: var(--text-muted);
            font-size: 0.7rem;
            font-weight: 500;
            gap: 4px;
            flex: 1;
            transition: color 0.2s;
        }

        .nav-item i { font-size: 1.4rem; }
        
        .nav-item.active {
            color: var(--primary-color);
        }

        .nav-fab {
            background: var(--primary-color);
            width: 48px;
            height: 48px;
            border-radius: 1rem;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            margin-top: -28px;
            box-shadow: 0 8px 20px rgba(59, 130, 246, 0.4);
            transform: rotate(-10deg);
            transition: transform 0.3s;
        }

        .nav-fab i {
            font-size: 1.3rem;
            margin: 0;
            transform: rotate(10deg);
        }
        
        .nav-item.active span {
            font-weight: 700;
            margin-top: 4px;
        }

        /* Custom Scrollbar */
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        html.dark ::-webkit-scrollbar-thumb { background: #475569; }
        ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
        
        /* Utility */
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
                <h1 class="page-title">Activity History</h1>
                <p class="page-subtitle">
                    <i class="fas fa-shield-alt" style="color: var(--primary-color);"></i> 
                    Area-wide transaction audit trail.
                </p>
            </div>
            <button id="export-btn" class="btn-export">
                <i class="fas fa-cloud-download-alt"></i> Export Data
            </button>
        </div>

        <!-- FILTERS CARD -->
        <div class="card">
            <div class="filter-header">
                <div class="filter-icon-box">
                    <i class="fas fa-sliders-h"></i>
                </div>
                <h2 class="filter-title">Filter Records</h2>
            </div>

            <form id="filter-form" class="filter-grid">
                <div class="input-group">
                    <label class="input-label" for="start_date">Start Date</label>
                    <i class="far fa-calendar-alt input-icon"></i>
                    <input type="date" id="start_date" name="start_date" class="floating-input">
                </div>
                
                <div class="input-group">
                    <label class="input-label" for="end_date">End Date</label>
                    <i class="far fa-calendar-check input-icon"></i>
                    <input type="date" id="end_date" name="end_date" class="floating-input">
                </div>
                
                <div class="input-group">
                    <label class="input-label" for="type">Activity Type</label>
                    <i class="fas fa-tag input-icon"></i>
                    <select id="type" name="type" class="floating-input select-input">
                        <option value="all">All Transactions</option>
                        <option value="saving">Savings Deposit</option>
                        <option value="withdrawal">Withdrawals</option>
                        <option value="payment">Loan Repayments</option>
                        <option value="disbursement">Disbursements</option>
                        <option value="registration">Registrations</option>
                    </select>
                </div>
                
                <div class="input-group">
                    <label class="input-label" for="officer">Officer</label>
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

                <div class="filter-actions">
                    <button type="button" id="reset-btn" class="btn-clear">
                        Clear Filters
                    </button>
                    <!-- Apply is hidden on desktop because of auto-submit, but kept for logic -->
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
                            <th>Client & Date</th>
                            <th>Type</th>
                            <th>Amount</th>
                            <th>Handled By</th>
                        </tr>
                    </thead>
                    <tbody id="history-tbody">
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
                    <button id="prev-page" class="page-btn">
                        <i class="fas fa-chevron-left"></i>
                    </button>
                    <span class="page-info">
                        Page <span id="current-page-badge" class="current">1</span> of <span id="total-pages">1</span>
                    </span>
                    <button id="next-page" class="page-btn">
                        <i class="fas fa-chevron-right"></i>
                    </button>
                </div>
            </div>
        </div>

    </main>

    <!-- MOBILE BOTTOM NAVIGATION (AM) -->
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
        <a href="history.php" class="nav-item active">
            <div class="nav-fab">
                <i class="fas fa-history"></i>
             </div>
             <span>History</span>
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

        class HistoryManager {
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
                this.tbody = document.getElementById('history-tbody');
                
                this.btnPrev = document.getElementById('prev-page');
                this.btnNext = document.getElementById('next-page');
                
                this.totalPagesSpan = document.getElementById('total-pages');
                this.totalRecords = document.getElementById('total-records');
                this.badge = document.getElementById('current-page-badge');
                
                this.inputs = this.form.querySelectorAll('input, select');
            }

            bindEvents() {
                // Auto-submit on change for better UX
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
                        <td><div class="skeleton-block sk-badge"></div></td>
                        <td><div class="skeleton-block sk-amount"></div></td>
                        <td>
                            <div class="td-flex align-center">
                                <div class="skeleton-block sk-officer-icon"></div>
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
                    const url = `history.php?action=load_history&page=${this.currentPage}&limit=${this.limit}&${filters}`;
                    
                    const response = await fetch(url);
                    const data = await response.json();
                    
                    if (data.success) {
                        this.tbody.innerHTML = data.html;
                        this.totalPages = data.total_pages > 0 ? data.total_pages : 1;
                        this.currentPage = data.current_page;
                        
                        this.updatePaginationUI(data.total_records);
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
                window.location.href = `history.php?action=export_csv&${filters}`;

                // Reset button after short delay assuming download started
                setTimeout(() => {
                    btn.innerHTML = originalHtml;
                    btn.style.pointerEvents = 'auto';
                    btn.style.opacity = '1';
                }, 2000);
            }
        }

        document.addEventListener('DOMContentLoaded', () => {
            new HistoryManager();
        });
    </script>
</body>
</html>