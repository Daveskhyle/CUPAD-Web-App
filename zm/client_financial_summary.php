<?php
date_default_timezone_set('Africa/Lagos');
session_start();
require_once '../includes/config.php';

// --- AUTHENTICATION ---
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'zm') {
    header('Location: ../index.php');
    exit();
}
$user_id = $_SESSION['user_id'];

// =================================================================
// --- AJAX HANDLER: Responds to requests for client data chunks ---
// =================================================================
if (isset($_GET['action']) && $_GET['action'] === 'load_clients') {
    $pdo = getDbConnection();
    header('Content-Type: application/json');

    $stmt_z = $pdo->prepare("SELECT zone_id FROM users WHERE id = ?");
    $stmt_z->execute([$user_id]);
    $zm_zone_id = $stmt_z->fetchColumn();

    // --- Get parameters from the AJAX request ---
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $limit = 12; // Number of clients to load per request
    $offset = ($page - 1) * $limit;

    $selected_area = $_GET['area'] ?? 'all';
    $search_term = isset($_GET['search']) ? '%' . trim($_GET['search']) . '%' : '%';
    $filter_type = $_GET['filter'] ?? 'all';
    
    // --- Build Dynamic SQL Query ---
    $query = "
        SELECT 
            c.id, c.name, c.phone, c.union, c.status AS client_status, c.officer_username,
            b.name as branch_name, a.name as area_name,
            COALESCE(u.full_name, c.officer_username) as officer_name,
            COALESCE(sb.balance, 0) as total_savings,
            COALESCE(l.active_balance, 0) as loan_balance,
            COALESCE(l.original_principal, 0) as loan_principal,
            COALESCE(l.has_error_loan, 0) as has_error_loan
        FROM clients c
        JOIN branches b ON c.branch_id = b.id
        JOIN areas a ON b.area_id = a.id
        LEFT JOIN users u ON c.officer_username = u.username
        LEFT JOIN saving_balances sb ON c.id = sb.client_id
        LEFT JOIN (
            SELECT 
                client_id, 
                SUM(remaining_balance) as active_balance,
                SUM(principal) as original_principal,
                MAX(CASE WHEN status = 'completed' AND remaining_balance > 0 THEN 1 ELSE 0 END) as has_error_loan
            FROM disbursements 
            WHERE remaining_balance > 0
            GROUP BY client_id
        ) l ON c.id = l.client_id
        WHERE a.zone_id = :zone_id
    ";
    
    $params =['zone_id' => $zm_zone_id];

    // -- Add filters to query --
    if ($selected_area !== 'all') {
        $query .= " AND a.id = :area_id";
        $params['area_id'] = $selected_area;
    }

    if (trim($search_term, '%') !== '') {
        $query .= " AND (c.name LIKE :search OR c.phone LIKE :search OR b.name LIKE :search OR u.full_name LIKE :search OR c.union LIKE :search)";
        $params['search'] = $search_term;
    }
    
    // -- Add status/type filters --
    switch ($filter_type) {
        case 'debtor': $query .= " AND COALESCE(l.active_balance, 0) > 0"; break;
        case 'saver': $query .= " AND (COALESCE(l.active_balance, 0) <= 0 OR l.active_balance IS NULL)"; break;
        case 'risk': $query .= " AND (COALESCE(sb.balance, 0) - COALESCE(l.active_balance, 0) < 0 OR COALESCE(l.has_error_loan, 0) = 1)"; break;
        case 'error': $query .= " AND COALESCE(l.has_error_loan, 0) = 1"; break;
    }
    
    // Ensure that we always show active clients OR clients with a balance
    $query .= " AND (c.status = 'active' OR COALESCE(l.active_balance, 0) > 0)";

    $query .= " ORDER BY l.has_error_loan DESC, a.name ASC, b.name ASC, c.name ASC LIMIT :limit OFFSET :offset";
    $params['limit'] = $limit;
    $params['offset'] = $offset;

    $stmt = $pdo->prepare($query);
    foreach ($params as $key => &$val) {
        $stmt->bindParam($key, $val, is_int($val) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->execute();
    $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // --- Generate HTML for the fetched clients ---
    $html = '';
    foreach ($clients as $c) {
        $client_data = processClientData($c);
        $html .= generateClientHTML($client_data);
    }

    echo json_encode([
        'success' => true,
        'html' => $html,
        'has_more' => count($clients) === $limit
    ]);
    exit();
}

// =================================================================
// --- HELPER FUNCTIONS for data processing and HTML generation ---
// =================================================================
function processClientData($row) {
    $client_savings = floatval($row['total_savings'] ?? 0);
    $active_loan = floatval($row['loan_balance'] ?? 0); 
    $principal = floatval($row['loan_principal'] ?? 0);
    $has_error_loan = (bool)$row['has_error_loan'];

    $loan_progress = 0;
    $has_active_loan = ($active_loan > 0);
    if ($has_active_loan && $principal > 0) {
        $repaid = max(0, $principal - $active_loan);
        $loan_progress = min(100, round(($repaid / $principal) * 100));
    }
    
    $net_position = $client_savings - $active_loan;

    $status_type = 'saver'; 
    if ($has_active_loan) $status_type = 'debtor';
    if ($net_position < 0) $status_type = 'risk';
    if ($has_error_loan) $status_type = 'risk';

    return [
        'id' => $row['id'], 'name' => $row['name'], 'phone' => $row['phone'] ?? '',
        'officer' => explode(' ', trim($row['officer_name']))[0], 'branch' => $row['branch_name'] ?: 'N/A',
        'union' => $row['union'] ?: 'N/A', 'savings' => $client_savings, 'loan_balance' => $active_loan,
        'loan_progress' => $loan_progress, 'net_position' => $net_position, 'has_loan' => $has_active_loan,
        'status_type' => $status_type, 'client_status' => $row['client_status'], 'has_error_loan' => $has_error_loan
    ];
}

function generateClientHTML($c) {
    ob_start(); // Start output buffering to capture HTML
    $initial = strtoupper(substr($c['name'], 0, 1));
    
    // UI Logic: Define standard colors based on client standing
    if ($c['status_type'] === 'saver') {
        $status_border = 'border-l-4 border-emerald-500';
        $avatar_bg = 'bg-emerald-100 dark:bg-emerald-500/20 text-emerald-600 dark:text-emerald-400';
    } elseif ($c['status_type'] === 'debtor') {
        $status_border = 'border-l-4 border-amber-500';
        $avatar_bg = 'bg-amber-100 dark:bg-amber-500/20 text-amber-600 dark:text-amber-400';
    } else { // High Risk / Error
        $status_border = 'border-l-4 border-rose-500';
        $avatar_bg = 'bg-rose-100 dark:bg-rose-500/20 text-rose-600 dark:text-rose-400';
    }

    $net_color_class = $c['net_position'] >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400';
    $loan_color_class = $c['loan_balance'] > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-gray-900 dark:text-gray-100';

    // Generate dynamic badges
    $badges = '';
    if ($c['client_status'] !== 'active') {
        $badges .= '<span class="px-1.5 py-0.5 text-[0.6rem] font-bold uppercase tracking-wide bg-gray-200 text-gray-600 dark:bg-gray-700 dark:text-gray-300 rounded">Inactive</span>';
    }
    if ($c['has_error_loan']) {
        $badges .= '<span class="px-1.5 py-0.5 text-[0.6rem] font-bold uppercase tracking-wide bg-rose-500 text-white rounded shadow-sm" title="Completed loan with balance"><i class="fas fa-exclamation-triangle"></i> Fix</span>';
    }
    ?>
    <!-- GRID VIEW MARKUP -->
    <div class="client-item-grid">
        <a href="history.php?client_id=<?php echo $c['id']; ?>" class="block relative bg-white dark:bg-slate-800 rounded-xl p-4 border border-gray-200 dark:border-slate-700 <?php echo $status_border; ?> hover:shadow-lg hover:border-blue-500/50 dark:hover:border-blue-400/50 hover:-translate-y-1 transition-all duration-200 group">
            
            <!-- Header Section -->
            <div class="flex items-start gap-3 mb-4">
                <div class="flex shrink-0 items-center justify-center w-10 h-10 rounded-full font-bold text-lg <?php echo $avatar_bg; ?>">
                    <?php echo $initial; ?>
                </div>
                <div class="flex-1 min-w-0 pt-0.5">
                    <div class="flex justify-between items-start gap-2">
                        <h3 class="font-bold text-[0.95rem] text-gray-900 dark:text-white truncate leading-tight group-hover:text-blue-600 dark:group-hover:text-blue-400 transition-colors">
                            <?php echo htmlspecialchars($c['name']); ?>
                        </h3>
                        <?php if ($badges): ?>
                            <div class="flex shrink-0 gap-1 mt-0.5"><?php echo $badges; ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="text-xs text-gray-500 dark:text-gray-400 mt-1 flex items-center gap-1.5 truncate">
                        <i class="fas fa-map-marker-alt opacity-70"></i> <span class="truncate"><?php echo htmlspecialchars($c['branch']); ?></span>
                        <span class="text-gray-300 dark:text-gray-600">•</span>
                        <i class="fas fa-user opacity-70"></i> <span class="truncate"><?php echo htmlspecialchars($c['officer']); ?></span>
                    </div>
                </div>
            </div>

            <!-- Stats Grid Section (3-Column) -->
            <div class="grid grid-cols-3 gap-2 bg-gray-50 dark:bg-slate-900/50 rounded-lg p-2.5 mb-3 border border-gray-100 dark:border-slate-800">
                <div class="flex flex-col">
                    <span class="text-[10px] text-gray-400 dark:text-gray-500 font-bold uppercase tracking-wider mb-0.5">Saved</span>
                    <span class="text-sm font-bold text-gray-900 dark:text-gray-100">₦<?php echo number_format($c['savings']); ?></span>
                </div>
                <div class="flex flex-col border-l border-gray-200 dark:border-slate-700 pl-2">
                    <span class="text-[10px] text-gray-400 dark:text-gray-500 font-bold uppercase tracking-wider mb-0.5">Loan</span>
                    <span class="text-sm font-bold <?php echo $loan_color_class; ?>">₦<?php echo number_format($c['loan_balance']); ?></span>
                </div>
                <div class="flex flex-col border-l border-gray-200 dark:border-slate-700 pl-2">
                    <span class="text-[10px] text-gray-400 dark:text-gray-500 font-bold uppercase tracking-wider mb-0.5">Net Pos</span>
                    <span class="text-sm font-bold <?php echo $net_color_class; ?>">
                        <?php echo $c['net_position'] > 0 ? '+' : ''; ?>₦<?php echo number_format($c['net_position']); ?>
                    </span>
                </div>
            </div>

            <!-- Progress / Status Footer Section -->
            <?php if ($c['has_loan']): ?>
                <div class="mt-1">
                    <div class="flex justify-between items-center text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1.5">
                        <span>Repayment Progress</span>
                        <span class="text-blue-600 dark:text-blue-400"><?php echo $c['loan_progress']; ?>%</span>
                    </div>
                    <div class="w-full h-1.5 bg-gray-200 dark:bg-slate-700 rounded-full overflow-hidden">
                        <div class="h-full bg-blue-500 rounded-full transition-all duration-500" style="width: <?php echo $c['loan_progress']; ?>%;"></div>
                    </div>
                </div>
            <?php else: ?>
                <div class="mt-1 flex items-center justify-center gap-1.5 py-1.5 text-xs font-semibold text-emerald-600 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-400/10 rounded-md">
                    <i class="fas fa-shield-check"></i> In Good Standing
                </div>
            <?php endif; ?>
        </a>
    </div>

    <!-- TABLE VIEW MARKUP -->
    <tr class="client-item-table" onclick="window.location.href='history.php?client_id=<?php echo $c['id']; ?>'">
        <td><div class="flex items-center"><div class="table-avatar"><?php echo $initial; ?></div><div>
            <div class="font-bold flex items-center gap-2">
                <?php echo htmlspecialchars($c['name']); ?>
                <?php echo $badges; ?>
            </div><div class="text-xs text-gray-500"><?php echo htmlspecialchars($c['phone']); ?></div></div></div>
        </td>
        <td><div class="flex flex-col gap-1"><span class="text-[10px] uppercase font-bold text-gray-500"><?php echo htmlspecialchars($c['branch']); ?></span><span class="text-xs text-blue-600 dark:text-blue-400 font-semibold"><i class="fas fa-user mr-1"></i><?php echo htmlspecialchars($c['officer']); ?></span></div></td>
        <td class="font-mono text-emerald-500 font-bold">₦<?php echo number_format($c['savings']); ?></td>
        <td class="font-mono <?php echo $c['loan_balance'] > 0 ? 'text-amber-500' : 'text-gray-400'; ?> font-bold">₦<?php echo number_format($c['loan_balance']); ?></td>
        <td class="font-mono font-bold <?php echo $c['net_position'] >= 0 ? 'text-emerald-500' : 'text-rose-500'; ?>"><?php echo $c['net_position'] > 0 ? '+' : ''; ?>₦<?php echo number_format($c['net_position']); ?></td>
        <td>
            <?php if($c['has_error_loan']): ?><span class="text-[10px] bg-rose-100 text-rose-600 px-2 py-1 rounded font-bold uppercase tracking-wider border border-rose-200">Data Error</span>
            <?php elseif($c['has_loan']): ?><span class="text-[10px] bg-amber-100 text-amber-600 px-2 py-1 rounded font-bold uppercase tracking-wider border border-amber-200">Loan Active</span>
            <?php else: ?><span class="text-[10px] bg-emerald-100 text-emerald-600 px-2 py-1 rounded font-bold uppercase tracking-wider border border-emerald-200">Saver</span><?php endif; ?>
        </td>
    </tr>
    <?php
    return ob_get_clean(); // Return buffered HTML
}

// ====================================================================
// --- INITIAL PAGE LOAD: Fetch summary data and render the shell ---
// ====================================================================
$pdo = getDbConnection();
$base_path = '../';

// Fetch ZM Profile, Zone, and Area list for dropdown (fast queries)
$stmt_z = $pdo->prepare("SELECT u.zone_id, u.full_name, u.profile_pic, z.name as zone_name FROM users u LEFT JOIN zones z ON u.zone_id = z.id WHERE u.id = ?");
$stmt_z->execute([$user_id]);
$user_data = $stmt_z->fetch(PDO::FETCH_ASSOC);

$zm_zone_id = $user_data['zone_id'] ?? 0;
$full_name = !empty($user_data['full_name']) ? $user_data['full_name'] : 'Zonal Manager';
$profile_pic = !empty($user_data['profile_pic']) ? $user_data['profile_pic'] : 'default_avatar.png';
$zone_name = $user_data['zone_name'] ?? 'Unknown Zone';
if (!$zm_zone_id) die("Access denied: No zone assigned.");

$stmt_ar = $pdo->prepare("SELECT id, name FROM areas WHERE zone_id = ? ORDER BY name ASC");
$stmt_ar->execute([$zm_zone_id]);
$zone_areas = $stmt_ar->fetchAll(PDO::FETCH_ASSOC);

$selected_area = $_GET['area'] ?? 'all';
$selected_area_name = 'Zone';
if($selected_area !== 'all'){
    foreach($zone_areas as $ar){
        if($ar['id'] == $selected_area) {
            $selected_area_name = $ar['name'];
            break;
        }
    }
}

// --- Fetch Grand Totals for the top stat cards ---
$grand_totals_query = "
    SELECT
        COUNT(c.id) as clients_count,
        SUM(COALESCE(sb.balance, 0)) as total_savings,
        SUM(COALESCE(l.active_balance, 0)) as total_loans,
        SUM(CASE WHEN COALESCE(l.active_balance, 0) > 0 THEN 1 ELSE 0 END) as debtors_count
    FROM clients c
    JOIN branches b ON c.branch_id = b.id
    JOIN areas a ON b.area_id = a.id
    LEFT JOIN saving_balances sb ON c.id = sb.client_id
    LEFT JOIN (
        SELECT client_id, SUM(remaining_balance) as active_balance
        FROM disbursements WHERE remaining_balance > 0
        GROUP BY client_id
    ) l ON c.id = l.client_id
    WHERE a.zone_id = :zone_id AND (c.status = 'active' OR COALESCE(l.active_balance, 0) > 0)
";

$grand_params =['zone_id' => $zm_zone_id];

if ($selected_area !== 'all') {
    $grand_totals_query .= " AND a.id = :area_id";
    $grand_params['area_id'] = $selected_area;
}

$stmt_gt = $pdo->prepare($grand_totals_query);
$stmt_gt->execute($grand_params);
$totals_data = $stmt_gt->fetch(PDO::FETCH_ASSOC);

$grand_totals =[
    'savings' => $totals_data['total_savings'] ?? 0,
    'loans' => $totals_data['total_loans'] ?? 0,
    'net' => ($totals_data['total_savings'] ?? 0) - ($totals_data['total_loans'] ?? 0),
    'clients_count' => $totals_data['clients_count'] ?? 0,
    'debtors_count' => $totals_data['debtors_count'] ?? 0
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#3b82f6">
    <title>Zone Financial Summary | CUPAD ZM</title>
    
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">    
    <script src="https://cdn.tailwindcss.com"></script>

    <script>
    tailwind.config = { /* ... */ } // Tailwind config is large, keeping it collapsed for brevity
    </script>

    <style>
        :root {
            --primary-color: #3b82f6; --secondary-color: #8b5cf6;
            --success-color: #10b981; --warning-color: #f59e0b; --error-color: #f43f5e;
            --bg-primary: #f0f2f5; --bg-secondary: #ffffff; --bg-card: #ffffff;
            --bg-header: rgba(255, 255, 255, 0.95);
            --text-primary: #1f2937; --text-secondary: #6b7280; --border-color: #e5e7eb;
            --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1);
            --border-radius-xl: 1.5rem;
        }
        
        html.dark {
            --bg-primary: #0f172a; --bg-secondary: #1e293b; --bg-card: #1e293b;
            --bg-header: rgba(15, 23, 42, 0.95);
            --text-primary: #f1f5f9; --text-secondary: #94a3b8; --border-color: rgba(255, 255, 255, 0.08);
        }

        body { font-family: 'Inter', sans-serif; background-color: var(--bg-primary); color: var(--text-primary); padding-bottom: 90px; -webkit-tap-highlight-color: transparent; }

        .main-header { background: var(--bg-header); backdrop-filter: blur(12px); border-bottom: 1px solid var(--border-color); position: sticky; top: 0; z-index: 50; padding-top: env(safe-area-inset-top); }
        .navbar { display: flex; justify-content: space-between; align-items: center; padding: 0.8rem 1rem; max-width: 1280px; margin: 0 auto; }
        .logo { font-weight: 700; font-size: 1.25rem; color: var(--primary-color); display: flex; align-items: center; gap: 0.5rem; text-decoration: none; }
        .logo img { height: 32px; width: auto; }
        .profile-btn { width: 36px; height: 36px; border-radius: 50%; overflow: hidden; border: 2px solid var(--primary-color); background: var(--bg-secondary); cursor: pointer; display: flex; align-items: center; justify-content: center; position: relative; }
        .profile-btn img { width: 100%; height: 100%; object-fit: cover; }

        .dashboard-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1rem; margin-bottom: 1.5rem; }
        .dashboard-card { border-radius: var(--border-radius-xl); padding: 1.5rem; position: relative; overflow: hidden; box-shadow: var(--shadow-md); border: none; color: white; }
        .card-gradient-blue { background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); }
        .card-gradient-orange { background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); }
        .card-gradient-purple { background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%); }
        .card-bg-icon { position: absolute; right: -10px; bottom: -20px; font-size: 6rem; color: white; opacity: 0.1; transform: rotate(-10deg); }

        .controls-wrapper { position: sticky; top: 72px; z-index: 40; background: var(--bg-primary); padding-bottom: 1rem; padding-top: 0.5rem; }
        .controls-inner { display: flex; flex-direction: column; gap: 0.8rem; }
        .search-row { display: flex; gap: 0.5rem; }
        .co-select { background: var(--bg-card); border: 1px solid var(--border-color); color: var(--text-primary); border-radius: 1rem; padding: 0.6rem 2.5rem 0.6rem 1rem; font-size: 0.9rem; font-weight: 600; outline: none; cursor: pointer; height: 100%; appearance: none; background-image: url("data:image/svg+xml;charset=US-ASCII,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%22292.4%22%20height%3D%22292.4%22%3E%3Cpath%20fill%3D%22%236b7280%22%20d%3D%22M287%2069.4a17.6%2017.6%200%200%200-13-5.4H18.4c-5%200-9.3%201.8-12.9%205.4A17.6%2017.6%200%200%200%200%2082.2c0%205%201.8%209.3%205.4%2012.9l128%20127.9c3.6%203.6%207.8%205.4%2012.8%205.4s9.2-1.8%2012.8-5.4L287%2095c3.5-3.5%205.4-7.8%205.4-12.8%200-5-1.9-9.2-5.5-12.8z%22%2F%3E%3C%2Fsvg%3E"); background-repeat: no-repeat; background-position: right 1rem top 50%; background-size: 0.65rem auto; }
        .search-container { background: var(--bg-card); border-radius: 1rem; padding: 0.5rem; border: 1px solid var(--border-color); display: flex; align-items: center; flex: 1; min-width: 0; }
        .search-input { width: 100%; padding: 0.5rem 0.5rem 0.5rem 2.5rem; border: none; background: transparent; color: var(--text-primary); font-size: 1rem; outline: none; }
        .view-toggles { display: flex; background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 1rem; padding: 0.25rem; }
        .view-btn { padding: 0.5rem 1rem; border-radius: 0.75rem; color: var(--text-secondary); cursor: pointer; transition: all 0.2s; border: none; background: transparent; }
        .view-btn.active { background: var(--primary-color); color: white; }
        
        .filter-tabs { display: flex; gap: 0.5rem; overflow-x: auto; padding: 0.2rem 0; scrollbar-width: none; }
        .filter-chip { white-space: nowrap; padding: 0.4rem 1rem; border-radius: 2rem; font-size: 0.8rem; font-weight: 600; background: var(--bg-card); border: 1px solid var(--border-color); color: var(--text-secondary); cursor: pointer; transition: all 0.2s; }
        .filter-chip.active { background: var(--primary-color); color: white; border-color: var(--primary-color); box-shadow: 0 2px 5px rgba(59, 130, 246, 0.3); }

        .filter-chip-error { background-color: #fffbeb; color: #d97706; border-color: #fcd34d; }
        html.dark .filter-chip-error { background-color: rgba(245, 158, 11, 0.1); color: #f59e0b; border-color: rgba(245, 158, 11, 0.3); }
        .filter-chip-error.active { background: var(--warning-color); color: white; border-color: var(--warning-color); box-shadow: 0 2px 5px rgba(245, 158, 11, 0.3); }

        /* VIEW MODES */
        #clientListContainer.view-mode-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 1rem; }
        .client-item-grid { display: block; } .client-item-table { display: none; }
        #clientListContainer.view-mode-table .client-item-grid { display: none; }
        #clientListContainer.view-mode-table .client-item-table { display: table-row; }
        #clientListContainer.view-mode-table { display: block; }

        /* TABLE DESIGN */
        .client-table-container { overflow-x: auto; border-radius: 1rem; border: 1px solid var(--border-color); background: var(--bg-card); }
        .client-table { w-full: 100%; border-collapse: collapse; width: 100%; font-size: 0.85rem; }
        .client-table th { background: var(--bg-primary); color: var(--text-secondary); text-align: left; padding: 1rem; font-weight: 600; text-transform: uppercase; font-size: 0.7rem; border-bottom: 1px solid var(--border-color); white-space: nowrap; }
        .client-table td { padding: 0.8rem 1rem; border-bottom: 1px solid var(--border-color); color: var(--text-primary); vertical-align: middle; white-space: nowrap; }
        .client-table tr:hover { background: var(--bg-primary); cursor: pointer; }
        .table-avatar { width: 30px; height: 30px; border-radius: 50%; background: var(--bg-primary); display: flex; align-items: center; justify-content: center; font-size: 0.8rem; font-weight: bold; margin-right: 0.75rem; }
        
        /* SKELETON LOADER ANIMATIONS */
        .skeleton-loader-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 1rem; }
        .skeleton-pulse { animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite; }
        @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: .5; } }
        .skeleton-line { background-color: var(--border-color); border-radius: 0.25rem; }
        
        /* MOBILE NAV STYLES */
        .mobile-bottom-nav { display: none; position: fixed; bottom: 0; left: 0; right: 0; background: var(--bg-secondary); border-top: 1px solid var(--border-color); padding: 0.5rem 1rem calc(0.5rem + env(safe-area-inset-bottom)); z-index: 100; justify-content: space-between; box-shadow: 0 -4px 10px rgba(0,0,0,0.05); }
        .nav-item { display: flex; flex-direction: column; align-items: center; justify-content: center; text-decoration: none; color: var(--text-secondary); font-size: 0.7rem; font-weight: 500; gap: 4px; flex: 1; }
        .nav-item i { font-size: 1.4rem; }
        .nav-item.active { color: var(--primary-color); }

        @media (max-width: 768px) { 
            .mobile-bottom-nav { display: flex; } 
            .controls-wrapper { top: 60px; } 
        }
    </style>
</head>

<body>
    <!-- HEADER -->
    <header class="main-header">
        <nav class="navbar">
            <a href="dashboard.php" class="logo"><img src="<?php echo htmlspecialchars($base_path . 'favicon.ico'); ?>" alt="Logo"><span>CUPAD ZM</span></a>
            <div class="flex items-center gap-3">
                <button id="theme-toggle" class="p-2 text-gray-500 dark:text-gray-400"><i class="fas fa-moon dark:hidden"></i><i class="fas fa-sun hidden dark:inline"></i></button>
                <div class="profile-btn" onclick="window.location.href='profile.php'"><i class="fas fa-user"></i><?php if (isset($profile_pic) && $profile_pic !== 'default_avatar.png'): ?><img src="<?php echo $base_path . $profile_pic; ?>" alt="Profile" class="absolute inset-0"><?php endif; ?></div>
                <a href="<?php echo htmlspecialchars($base_path . 'logout.php'); ?>" class="p-2 text-red-500" title="Logout"><i class="fas fa-sign-out-alt"></i></a>
            </div>
        </nav>
    </header>

    <main class="max-w-7xl mx-auto px-4 py-6">
        <div class="flex justify-between items-end mb-4">
            <div>
                <h1 class="text-2xl font-extrabold text-gray-900 dark:text-white"><?php echo htmlspecialchars($selected_area_name) . " Summary"; ?></h1>
                <p class="text-xs text-gray-500 mt-1"><?php echo htmlspecialchars($zone_name); ?> Zone • <?php echo date('M d, Y'); ?> • <span id="clientCountHeader"><?php echo $grand_totals['clients_count']; ?></span> Clients</p>
            </div>
            <button onclick="window.print()" class="text-sm text-blue-500 font-semibold hidden md:block"><i class="fas fa-print mr-1"></i> Print Report</button>
        </div>
        
        <!-- DASHBOARD STATS -->
        <div class="dashboard-grid">
            <div class="dashboard-card card-gradient-blue"><i class="fas fa-piggy-bank card-bg-icon"></i><div class="relative z-10"><div class="text-sm font-semibold uppercase opacity-90">Total Savings</div><div class="text-3xl font-extrabold mt-1">₦<?php echo number_format($grand_totals['savings']); ?></div></div></div>
            <div class="dashboard-card card-gradient-orange"><i class="fas fa-hand-holding-usd card-bg-icon"></i><div class="relative z-10"><div class="text-sm font-semibold uppercase opacity-90">Outstanding Loans</div><div class="text-3xl font-extrabold mt-1">₦<?php echo number_format($grand_totals['loans']); ?></div></div></div>
            <div class="dashboard-card card-gradient-purple"><i class="fas fa-users card-bg-icon"></i><div class="relative z-10"><div class="text-sm font-semibold uppercase opacity-90">Client Base</div><div class="text-3xl font-extrabold mt-1"><?php echo $grand_totals['clients_count']; ?></div><div class="text-xs opacity-80 mt-1"><?php echo $grand_totals['debtors_count']; ?> with Active Loans</div></div></div>
        </div>

        <!-- STICKY CONTROLS -->
        <div class="controls-wrapper">
            <div class="controls-inner">
                <div class="search-row flex-col md:flex-row w-full gap-2">
                    <form id="areaFilterForm" method="GET" class="w-full md:w-auto">
                        <select name="area" id="areaFilterSelect" class="co-select w-full md:w-56">
                            <option value="all">All Zone Areas</option>
                            <?php foreach($zone_areas as $ar): ?>
                            <option value="<?php echo htmlspecialchars($ar['id']); ?>" <?php echo $selected_area == $ar['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($ar['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                    <div class="search-container flex-1 w-full relative">
                        <i class="fas fa-search text-gray-400 absolute left-4 top-1/2 transform -translate-y-1/2"></i>
                        <input type="text" id="clientSearch" class="search-input" placeholder="Search clients...">
                    </div>
                    <div class="view-toggles shrink-0 self-end md:self-auto">
                        <button class="view-btn active" id="btnGrid" title="Grid View"><i class="fas fa-th-large"></i></button>
                        <button class="view-btn" id="btnTable" title="List View"><i class="fas fa-list"></i></button>
                    </div>
                </div>
                <div class="filter-tabs" id="filterTabs">
                    <button class="filter-chip active" data-filter="all">All Clients</button>
                    <button class="filter-chip" data-filter="debtor">With Loans</button>
                    <button class="filter-chip" data-filter="saver">Debt Free</button>
                    <button class="filter-chip" data-filter="risk">High Risk</button>
                    <button class="filter-chip filter-chip-error" data-filter="error" title="Show clients with completed loans that still have a balance"><i class="fas fa-exclamation-triangle mr-1"></i> Data Errors</button>
                </div>
            </div>
        </div>

        <!-- CLIENT LIST CONTAINER (Populated by AJAX) -->
        <div id="clientListContainer" class="view-mode-grid">
            <!-- Skeleton Loader will be inserted here by JS -->
        </div>

        <!-- This is a separate container for the table view to work correctly -->
        <div id="clientTableContainer" class="hidden">
            <div class="client-table-container">
                <table class="client-table">
                    <thead><tr><th>Client</th><th>Branch / Officer</th><th>Savings</th><th>Loan Bal</th><th>Net Pos</th><th>Status</th></tr></thead>
                    <tbody id="clientTableBody"></tbody>
                </table>
            </div>
        </div>
        
        <div id="loader" class="text-center py-8 hidden"><i class="fas fa-spinner fa-spin text-2xl text-blue-500"></i></div>
        <div id="noResults" class="hidden flex flex-col items-center justify-center py-12 text-gray-400"><div class="w-16 h-16 bg-gray-100 dark:bg-gray-800 rounded-full flex items-center justify-center mb-4"><i class="fas fa-search text-2xl"></i></div><p>No clients match your criteria.</p></div>
    </main>
    
    <!-- MOBILE BOTTOM NAVIGATION -->
    <nav class="mobile-bottom-nav">
        <a href="dashboard.php" class="nav-item">
             <div style="background: var(--primary-color); width: 45px; height: 45px; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; margin-top: -25px; box-shadow: 0 4px 10px rgba(59, 130, 246, 0.4);">
                <i class="fas fa-home" style="font-size: 1.2rem; margin:0;"></i>
             </div>
             <span style="font-size:0.65rem; font-weight:600; margin-top:2px;">Home</span>
        </a>
        <a href="areas.php" class="nav-item">
            <i class="fas fa-map-marked-alt"></i>
            <span>Areas</span>
        </a>
        <a href="branches.php" class="nav-item">
            <i class="fas fa-building"></i>
            <span>Branches</span>
        </a>
        <a href="analytics.php" class="nav-item">
            <i class="fas fa-chart-bar"></i>
            <span>Reports</span>
        </a>
    </nav>
    
<script>
    class ClientLoader {
        constructor() {
            this.container = document.getElementById('clientListContainer');
            this.tableBody = document.getElementById('clientTableBody');
            this.tableContainer = document.getElementById('clientTableContainer');
            this.loader = document.getElementById('loader');
            this.noResults = document.getElementById('noResults');

            this.currentPage = 1;
            this.isLoading = false;
            this.hasMore = true;
            this.currentView = 'grid';

            this.filters = {
                area: document.getElementById('areaFilterSelect').value,
                search: '',
                type: 'all'
            };

            this.init();
        }

        init() {
            // Theme toggle
            const t = localStorage.getItem('theme');
            if(t==='dark') document.documentElement.classList.add('dark');
            document.getElementById('theme-toggle').addEventListener('click', () => {
                document.documentElement.classList.toggle('dark');
                localStorage.setItem('theme', document.documentElement.classList.contains('dark') ? 'dark' : 'light');
            });

            // View toggling
            this.currentView = localStorage.getItem('zmClientViewPref') || 'grid';
            this.toggleView(this.currentView, true);
            document.getElementById('btnGrid').addEventListener('click', () => this.toggleView('grid'));
            document.getElementById('btnTable').addEventListener('click', () => this.toggleView('table'));

            // Filter event listeners
            document.getElementById('areaFilterSelect').addEventListener('change', e => {
                document.getElementById('areaFilterForm').submit(); 
            });

            document.getElementById('clientSearch').addEventListener('input', this.debounce(e => {
                this.filters.search = e.target.value;
                this.resetAndLoad();
            }, 300));

            document.querySelectorAll('#filterTabs .filter-chip').forEach(btn => {
                btn.addEventListener('click', e => {
                    document.querySelector('#filterTabs .filter-chip.active').classList.remove('active');
                    e.currentTarget.classList.add('active');
                    this.filters.type = e.currentTarget.dataset.filter;
                    this.resetAndLoad();
                });
            });

            // Infinite scroll observer
            const observer = new IntersectionObserver(entries => {
                if (entries[0].isIntersecting) {
                    this.loadClients();
                }
            }, { rootMargin: '0px 0px 400px 0px' }); // Load when 400px from bottom
            observer.observe(this.loader);

            // Initial load
            this.loadClients();
        }

        toggleView(view, force = false) {
            if (!force && this.currentView === view) return;
            this.currentView = view;
            localStorage.setItem('zmClientViewPref', view);

            if (view === 'grid') {
                this.container.classList.add('view-mode-grid');
                this.container.classList.remove('hidden');
                this.tableContainer.classList.add('hidden');
                document.getElementById('btnGrid').classList.add('active');
                document.getElementById('btnTable').classList.remove('active');
            } else {
                this.container.classList.remove('view-mode-grid');
                this.container.classList.add('hidden');
                this.tableContainer.classList.remove('hidden');
                document.getElementById('btnGrid').classList.remove('active');
                document.getElementById('btnTable').classList.add('active');
            }
        }
        
        // Accurate Grid Card Skeleton
        generateGridSkeleton() {
            let skeletonHTML = '';
            for (let i = 0; i < 6; i++) {
                skeletonHTML += `
                    <div class="client-item-grid bg-white dark:bg-slate-800 rounded-xl p-4 border border-gray-200 dark:border-slate-700 border-l-4 skeleton-pulse">
                        <div class="flex items-start gap-3 mb-4">
                            <div class="skeleton-line shrink-0 rounded-full w-10 h-10"></div>
                            <div class="flex-1 min-w-0 pt-0.5 space-y-2.5">
                                <div class="flex justify-between items-start gap-2">
                                    <div class="skeleton-line h-3.5 w-1/2"></div>
                                    <div class="skeleton-line h-3 w-10"></div>
                                </div>
                                <div class="skeleton-line h-2 w-3/4"></div>
                            </div>
                        </div>
                        <div class="grid grid-cols-3 gap-2 bg-gray-50 dark:bg-slate-900/50 rounded-lg p-2.5 mb-3 border border-gray-100 dark:border-slate-800">
                            <div class="flex flex-col">
                                <div class="skeleton-line h-2 w-10 mb-1.5"></div>
                                <div class="skeleton-line h-3.5 w-16"></div>
                            </div>
                            <div class="flex flex-col border-l border-gray-200 dark:border-slate-700 pl-2">
                                <div class="skeleton-line h-2 w-10 mb-1.5"></div>
                                <div class="skeleton-line h-3.5 w-16"></div>
                            </div>
                            <div class="flex flex-col border-l border-gray-200 dark:border-slate-700 pl-2">
                                <div class="skeleton-line h-2 w-10 mb-1.5"></div>
                                <div class="skeleton-line h-3.5 w-16"></div>
                            </div>
                        </div>
                        <div class="mt-1">
                            <div class="flex justify-between items-center mb-1.5">
                                <div class="skeleton-line h-2 w-24"></div>
                                <div class="skeleton-line h-2 w-6"></div>
                            </div>
                            <div class="w-full h-1.5 bg-gray-200 dark:bg-slate-700 rounded-full"></div>
                        </div>
                    </div>`;
            }
            return skeletonHTML;
        }

        // Accurate Table Row Skeleton
        generateTableSkeleton() {
            let skeletonHTML = '';
            for (let i = 0; i < 6; i++) {
                skeletonHTML += `
                    <tr class="skeleton-pulse">
                        <td class="p-3">
                            <div class="flex items-center">
                                <div class="skeleton-line rounded-full w-[30px] h-[30px] shrink-0 mr-3"></div>
                                <div class="flex flex-col gap-1.5 w-full">
                                    <div class="skeleton-line h-3.5 w-32"></div>
                                    <div class="skeleton-line h-2 w-24"></div>
                                </div>
                            </div>
                        </td>
                        <td class="p-3">
                            <div class="flex flex-col gap-1.5">
                                <div class="skeleton-line h-2 w-16"></div>
                                <div class="skeleton-line h-3 w-20"></div>
                            </div>
                        </td>
                        <td class="p-3"><div class="skeleton-line h-4 w-20"></div></td>
                        <td class="p-3"><div class="skeleton-line h-4 w-20"></div></td>
                        <td class="p-3"><div class="skeleton-line h-4 w-24"></div></td>
                        <td class="p-3"><div class="skeleton-line h-5 w-16 rounded"></div></td>
                    </tr>`;
            }
            return skeletonHTML;
        }

        async loadClients() {
            if (this.isLoading || !this.hasMore) return;
            this.isLoading = true;
            this.noResults.classList.add('hidden');

            if (this.currentPage === 1) {
                // Determine which skeleton to show based on the current view state
                if (this.currentView === 'grid') {
                    this.container.innerHTML = this.generateGridSkeleton();
                    this.tableBody.innerHTML = '';
                } else {
                    this.container.innerHTML = '';
                    this.tableBody.innerHTML = this.generateTableSkeleton();
                }
            }
            this.loader.classList.remove('hidden');

            const url = new URL(window.location.href);
            url.searchParams.set('action', 'load_clients');
            url.searchParams.set('page', this.currentPage);
            url.searchParams.set('area', this.filters.area);
            url.searchParams.set('search', this.filters.search);
            url.searchParams.set('filter', this.filters.type);

            try {
                const response = await fetch(url);
                const data = await response.json();

                if (data.success) {
                    if (this.currentPage === 1) {
                        this.container.innerHTML = '';
                        this.tableBody.innerHTML = '';
                    }
                    
                    const tempDiv = document.createElement('div');
                    tempDiv.innerHTML = data.html;

                    Array.from(tempDiv.querySelectorAll('.client-item-grid')).forEach(el => this.container.appendChild(el));
                    Array.from(tempDiv.querySelectorAll('.client-item-table')).forEach(el => this.tableBody.appendChild(el));
                    
                    this.hasMore = data.has_more;
                    this.currentPage++;

                    if (!this.hasMore && this.container.children.length === 0) {
                        this.noResults.classList.remove('hidden');
                    }
                }
            } catch (error) {
                console.error("Failed to load clients:", error);
            } finally {
                this.isLoading = false;
                if (!this.hasMore) {
                    this.loader.classList.add('hidden');
                }
            }
        }
        
        resetAndLoad() {
            this.currentPage = 1;
            this.hasMore = true;
            this.container.innerHTML = '';
            this.tableBody.innerHTML = '';
            window.scrollTo(0, 0); 
            this.loadClients();
        }

        debounce(func, delay) {
            let timeout;
            return function(...args) {
                clearTimeout(timeout);
                timeout = setTimeout(() => func.apply(this, args), delay);
            };
        }
    }

    document.addEventListener('DOMContentLoaded', () => {
        new ClientLoader();
    });
    </script>
</body>
</html>