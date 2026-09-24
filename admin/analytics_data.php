<?php
session_start();
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit();
}

header('Content-Type: application/json');

// ==========================================
//        DATABASE CONFIGURATION
// ==========================================
define('DB_HOST', 'localhost');
define('DB_NAME', 'cupadnam_db');
define('DB_USER', 'cupadnam_db');
define('DB_PASS', 'f2GrjZQCz8E39nCu9eLg');
define('DB_CHARSET', 'utf8mb4');

function getDbConnection() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            die(json_encode(['error' => "Database Connection Error: " . $e->getMessage()]));
        }
    }
    return $pdo;
}

$pdo = getDbConnection();

// === DATE FILTERING UTILITIES ===
function getDateRangeForFilter($filterType, $customStart = null, $customEnd = null) {
    switch ($filterType) {
        case 'today': return ['start' => date('Y-m-d'), 'end' => date('Y-m-d')];
        case 'week': return ['start' => date('Y-m-d', strtotime('monday this week')), 'end' => date('Y-m-d')];
        case 'month': return ['start' => date('Y-m-01'), 'end' => date('Y-m-d')];
        case 'custom': return ['start' => $customStart, 'end' => $customEnd];
        default: return ['start' => null, 'end' => null];
    }
}

// === ANALYTICS FUNCTIONS (SQL BASED) ===

function processSavingsAnalytics($pdo, $startDate, $endDate) {
    $params = [];
    $where = "1=1";
    
    if ($startDate && $endDate) {
        $where .= " AND DATE(date) BETWEEN ? AND ?";
        $params = [$startDate, $endDate];
    }

    // 1. Total Amount & Count
    $stmt = $pdo->prepare("SELECT SUM(amount) as total_amount, COUNT(*) as total_transactions FROM saving_collections WHERE type = 'deposit' AND $where");
    $stmt->execute($params);
    $totals = $stmt->fetch();

    // 2. By Officer
    $stmt = $pdo->prepare("SELECT officer, SUM(amount) as total, COUNT(*) as count FROM saving_collections WHERE type = 'deposit' AND $where GROUP BY officer");
    $stmt->execute($params);
    $byOfficer = $stmt->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC); // Key by officer

    // 3. By Month (Trends)
    $stmt = $pdo->prepare("SELECT DATE_FORMAT(date, '%Y-%m') as month, SUM(amount) as total, COUNT(*) as count FROM saving_collections WHERE type = 'deposit' AND $where GROUP BY month");
    $stmt->execute($params);
    $byMonth = $stmt->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);

    return [
        'total_amount' => (float)($totals['total_amount'] ?? 0),
        'total_transactions' => (int)($totals['total_transactions'] ?? 0),
        'by_officer' => $byOfficer,
        'by_month' => $byMonth
    ];
}

function processDisbursementAnalytics($pdo, $startDate, $endDate) {
    $params = [];
    $where = "1=1";
    
    if ($startDate && $endDate) {
        $where .= " AND date BETWEEN ? AND ?";
        $params = [$startDate, $endDate];
    }

    // 1. Totals
    $stmt = $pdo->prepare("SELECT 
        SUM(principal) as total_principal, 
        SUM(total_payable) as total_payable, 
        SUM(remaining_balance) as total_remaining, 
        COUNT(*) as total_count 
        FROM disbursements WHERE $where");
    $stmt->execute($params);
    $totals = $stmt->fetch();

    // 2. By Officer
    $stmt = $pdo->prepare("SELECT officer, SUM(principal) as total, COUNT(*) as count FROM disbursements WHERE $where GROUP BY officer");
    $stmt->execute($params);
    $byOfficer = $stmt->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);

    // 3. Amount Ranges
    $stmt = $pdo->prepare("SELECT 
        SUM(CASE WHEN principal <= 50000 THEN 1 ELSE 0 END) as `0-50k`,
        SUM(CASE WHEN principal > 50000 AND principal <= 100000 THEN 1 ELSE 0 END) as `50k-100k`,
        SUM(CASE WHEN principal > 100000 AND principal <= 500000 THEN 1 ELSE 0 END) as `100k-500k`,
        SUM(CASE WHEN principal > 500000 THEN 1 ELSE 0 END) as `500k+`
        FROM disbursements WHERE $where");
    $stmt->execute($params);
    $byRange = $stmt->fetch();

    // 4. By Month
    $stmt = $pdo->prepare("SELECT DATE_FORMAT(date, '%Y-%m') as month, SUM(principal) as total, COUNT(*) as count FROM disbursements WHERE $where GROUP BY month");
    $stmt->execute($params);
    $byMonth = $stmt->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);

    // 5. Overdue Loans (Snapshot - assumes current state if no historic tracking table)
    // Only count active overdue loans
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM disbursements WHERE status = 'active' AND remaining_balance > 0 AND due_date < CURDATE()");
    $stmt->execute();
    $overdueLoans = $stmt->fetchColumn();

    $totalCount = (int)($totals['total_count'] ?? 0);
    $totalPrincipal = (float)($totals['total_principal'] ?? 0);

    return [
        'total_principal' => $totalPrincipal,
        'total_payable' => (float)($totals['total_payable'] ?? 0),
        'total_remaining' => (float)($totals['total_remaining'] ?? 0),
        'total_count' => $totalCount,
        'by_officer' => $byOfficer,
        'overdue_loans' => (int)$overdueLoans,
        'by_amount_range' => $byRange,
        'by_month' => $byMonth,
        'average_amount' => $totalCount > 0 ? $totalPrincipal / $totalCount : 0
    ];
}

function processClientAnalytics($pdo, $startDate, $endDate) {
    // Total Clients
    $totalClients = $pdo->query("SELECT COUNT(*) FROM clients")->fetchColumn();

    // Recent Registrations (Created At)
    $params = [];
    $where = "1=1";
    if ($startDate && $endDate) {
        $where .= " AND DATE(created_at) BETWEEN ? AND ?";
        $params = [$startDate, $endDate];
    }
    
    // Recent Regs count based on filter
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM clients WHERE $where");
    $stmt->execute($params);
    $filteredCount = $stmt->fetchColumn();

    // By Branch
    $stmt = $pdo->query("SELECT b.name, COUNT(c.id) as count FROM clients c LEFT JOIN branches b ON c.branch_id = b.id GROUP BY c.branch_id");
    $byBranch = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    return [
        'total_clients' => (int)$totalClients,
        'recent_registrations' => (int)$filteredCount, // Clients added in period
        'by_branch' => $byBranch
    ];
}

function processRegistrationAnalytics($pdo, $startDate, $endDate) {
    $params = [];
    $where = "1=1";
    if ($startDate && $endDate) {
        $where .= " AND DATE(date) BETWEEN ? AND ?";
        $params = [$startDate, $endDate];
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) as count, SUM(amount) as total FROM registrations WHERE $where");
    $stmt->execute($params);
    $res = $stmt->fetch();

    return [
        'total_count' => (int)($res['count'] ?? 0),
        'total_amount' => (float)($res['total'] ?? 0)
    ];
}

function processCollectionAnalytics($pdo, $startDate, $endDate) {
    $params = [];
    $where = "1=1";
    if ($startDate && $endDate) {
        $where .= " AND DATE(date) BETWEEN ? AND ?";
        $params = [$startDate, $endDate];
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) as count, SUM(amount_collected) as total FROM loan_collections WHERE $where");
    $stmt->execute($params);
    $res = $stmt->fetch();

    return [
        'total_count' => (int)($res['count'] ?? 0),
        'total_amount' => (float)($res['total'] ?? 0)
    ];
}

function getRecentActivityFeed($pdo, $limit = 5) {
    $sql = "
    (SELECT 'savings' as type, CONCAT('Savings by ', COALESCE(c.name, 'Unknown')) as description, sc.amount, sc.date, sc.officer, 'fa-piggy-bank' as icon, '#10b981' as color 
     FROM saving_collections sc LEFT JOIN clients c ON sc.client_id = c.id WHERE sc.type='deposit')
    UNION ALL
    (SELECT 'disbursement' as type, CONCAT('Loan to ', client_name) as description, principal as amount, date, officer, 'fa-hand-holding-usd' as icon, '#3b82f6' as color 
     FROM disbursements)
    UNION ALL
    (SELECT 'collection' as type, CONCAT('Payment (', type, ')') as description, amount_collected as amount, date, officer, 'fa-money-bill-wave' as icon, '#f59e0b' as color 
     FROM loan_collections)
    UNION ALL
    (SELECT 'registration' as type, CONCAT('New client: ', client_name) as description, amount, date, officer, 'fa-user-plus' as icon, '#8b5cf6' as color 
     FROM registrations)
    ORDER BY date DESC LIMIT " . (int)$limit;

    $stmt = $pdo->query($sql);
    return $stmt->fetchAll();
}

// === KPI & GROWTH HELPERS ===

function calculateGrowthRate($currentData, $previousData) {
    if ($previousData == 0) return 0;
    return (($currentData - $previousData) / $previousData) * 100;
}

function getDateRangeForComparison($filterType, $customStart = null, $customEnd = null) {
    switch ($filterType) {
        case 'today':
            $prevStart = date('Y-m-d', strtotime('-1 day'));
            $prevEnd = date('Y-m-d', strtotime('-1 day'));
            break;
        case 'week':
            $prevStart = date('Y-m-d', strtotime('monday last week'));
            $prevEnd = date('Y-m-d', strtotime('sunday last week'));
            break;
        case 'month':
            $prevStart = date('Y-m-01', strtotime('last month'));
            $prevEnd = date('Y-m-t', strtotime('last month'));
            break;
        case 'custom':
            if ($customStart && $customEnd) {
                $currentRange = strtotime($customEnd) - strtotime($customStart);
                $prevStart = date('Y-m-d', strtotime($customStart) - $currentRange - 86400);
                $prevEnd = date('Y-m-d', strtotime($customStart) - 86400);
            } else {
                $prevStart = null; $prevEnd = null;
            }
            break;
        default:
            $prevStart = null; $prevEnd = null;
    }
    return ['start' => $prevStart, 'end' => $prevEnd];
}

function calculateHealthScore($kpis) {
    $healthScore = 0;
    // Portfolio at Risk (25 points) - lower is better
    $parScore = max(0, 25 - ($kpis['portfolio_at_risk'] * 2.5));
    $healthScore += $parScore;
    // Collection Efficiency (25 points) - higher is better
    $collectionScore = min(25, ($kpis['collection_efficiency'] / 4));
    $healthScore += $collectionScore;
    // Savings to Loans Ratio (25 points) - optimal around 80%
    $ratioScore = 25 - abs($kpis['savings_to_loans_ratio'] - 80) / 4;
    $ratioScore = max(0, min(25, $ratioScore));
    $healthScore += $ratioScore;
    // Growth Trend (25 points) - positive growth is good
    $growthScore = max(0, min(25, 12.5 + $kpis['savings_growth'] * 0.5));
    $healthScore += $growthScore;
    
    return round(max(0, min(100, $healthScore)));
}

// === EXECUTION ===

$dateFilter = $_GET['date_filter'] ?? 'all';
$customStartDate = $_GET['start_date'] ?? null;
$customEndDate = $_GET['end_date'] ?? null;

// Current Range Data
$dateRange = getDateRangeForFilter($dateFilter, $customStartDate, $customEndDate);
$startDate = $dateRange['start'];
$endDate = $dateRange['end'];

$savingsAnalytics = processSavingsAnalytics($pdo, $startDate, $endDate);
$disbursementAnalytics = processDisbursementAnalytics($pdo, $startDate, $endDate);
$clientAnalytics = processClientAnalytics($pdo, $startDate, $endDate);
$registrationAnalytics = processRegistrationAnalytics($pdo, $startDate, $endDate);
$collectionAnalytics = processCollectionAnalytics($pdo, $startDate, $endDate);
$recentActivities = getRecentActivityFeed($pdo, 5);

// Comparison Range Data
$comparisonRange = getDateRangeForComparison($dateFilter, $customStartDate, $customEndDate);
$prevSavingsAnalytics = processSavingsAnalytics($pdo, $comparisonRange['start'], $comparisonRange['end']);
$prevDisbursementAnalytics = processDisbursementAnalytics($pdo, $comparisonRange['start'], $comparisonRange['end']);
$prevClientAnalytics = processClientAnalytics($pdo, $comparisonRange['start'], $comparisonRange['end']);
$prevRegistrationAnalytics = processRegistrationAnalytics($pdo, $comparisonRange['start'], $comparisonRange['end']);
// Collection comparison omitted for brevity, logic is same

// KPI Calculations
$kpis = [
    'total_portfolio_value' => $disbursementAnalytics['total_remaining'],
    'savings_to_loans_ratio' => $disbursementAnalytics['total_principal'] > 0 ? 
        ($savingsAnalytics['total_amount'] / $disbursementAnalytics['total_principal']) * 100 : 0,
    'portfolio_at_risk' => $disbursementAnalytics['total_count'] > 0 ? 
        ($disbursementAnalytics['overdue_loans'] / $disbursementAnalytics['total_count']) * 100 : 0,
    'net_savings' => $savingsAnalytics['total_amount'] - 0, // Simplified: Withdrawal logic usually tracked separately
    'total_registrations' => $registrationAnalytics['total_count'],
    'total_withdrawals' => 0, // Need separate withdrawal query if strictly tracking cash out
    
    // Growth
    'savings_growth' => calculateGrowthRate($savingsAnalytics['total_amount'], $prevSavingsAnalytics['total_amount']),
    'disbursement_growth' => calculateGrowthRate($disbursementAnalytics['total_principal'], $prevDisbursementAnalytics['total_principal']),
    'client_growth' => calculateGrowthRate($clientAnalytics['total_clients'], $prevClientAnalytics['total_clients']), // Note: total_clients is snapshot, recent_registrations is flow
    'registration_growth' => calculateGrowthRate($registrationAnalytics['total_count'], $prevRegistrationAnalytics['total_count']),
    
    // Health Metrics
    'collection_efficiency' => $disbursementAnalytics['total_payable'] > 0 ? 
        (($disbursementAnalytics['total_payable'] - $disbursementAnalytics['total_remaining']) / $disbursementAnalytics['total_payable']) * 100 : 0,
    'average_loan_size' => $disbursementAnalytics['average_amount'],
    'average_savings_per_transaction' => $savingsAnalytics['total_transactions'] > 0 ? 
        $savingsAnalytics['total_amount'] / $savingsAnalytics['total_transactions'] : 0,
    'liquidity_ratio' => $disbursementAnalytics['total_principal'] > 0 ? 
        ($savingsAnalytics['total_amount'] / $disbursementAnalytics['total_principal']) * 100 : 0
];

$kpis['healthScore'] = calculateHealthScore($kpis);

// Combine Output
$output = [
    'savingsAnalytics' => $savingsAnalytics,
    'disbursementAnalytics' => $disbursementAnalytics,
    'clientAnalytics' => $clientAnalytics,
    'registrationAnalytics' => $registrationAnalytics,
    'collectionAnalytics' => $collectionAnalytics,
    'recentActivities' => $recentActivities,
    'kpis' => $kpis
];

echo json_encode($output);
?>