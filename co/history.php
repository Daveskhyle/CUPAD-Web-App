<?php
// co/track_transactions.php - MySQL Version (AJAX Optimized & Fortified)
session_start();

// --- DATABASE CONNECTION ---
require_once __DIR__ . '/../includes/config.php';
$conn = getDbConnection();

// Security Check
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'co') {
    if (isset($_GET['ajax'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit();
    }
    header('Location: ../index.php');
    exit();
}

$username = $_SESSION['username'] ?? '';

// ==============================================================================
// 1. AJAX ENDPOINT FOR FETCHING DATA DYNAMICALLY
// ==============================================================================
if (isset($_GET['ajax'])) {
    // Start output buffering to prevent random PHP Warnings from corrupting the JSON
    ob_start(); 
    header('Content-Type: application/json');
    
    try {
        // Pagination params
        $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
        $perPage = isset($_GET['perPage']) ? max(1, (int)$_GET['perPage']) : 20;
        $offset = ($page - 1) * $perPage;
        
        // Filter params
        $type = $_GET['type'] ?? 'all';
        $search = trim($_GET['search'] ?? '');
        $startDate = $_GET['startDate'] ?? '';
        $endDate = $_GET['endDate'] ?? '';

        // Base subquery: Match clients.php behavior by fetching all transactions 
        // for clients that belong to this officer (instead of who processed it)
        $baseSubquery = "
            SELECT transaction_id, date, client_id, amount, type, 'saving_tbl' as source_cat 
            FROM saving_collections WHERE client_id IN (SELECT id FROM clients WHERE officer_username = ?)
            UNION ALL
            SELECT transaction_id, date, client_id, amount_collected as amount, 'repayment' as type, 'collection_tbl' as source_cat 
            FROM loan_collections WHERE client_id IN (SELECT id FROM clients WHERE officer_username = ?)
            UNION ALL
            SELECT id as transaction_id, date, client_id, principal as amount, 'disbursement' as type, 'disbursement_tbl' as source_cat 
            FROM disbursements WHERE client_id IN (SELECT id FROM clients WHERE officer_username = ?)
        ";
        
        // Initialize parameters with the 3 officers needed for the UNION
        $params = [$username, $username, $username];
        $whereBlocks = [];

        // Apply Filters via SQL (Matching clients.php classification)
        if ($type === 'today') {
            $whereBlocks[] = "DATE(t.date) = CURDATE()";
        } elseif ($type === 'saving') {
            $whereBlocks[] = "t.source_cat = 'saving_tbl' AND t.amount >= 0 AND (t.type IS NULL OR LOWER(t.type) NOT IN ('withdrawal', 'return_cash', 'return cash', 'return', 'adjust', 'cash', 'debit', 'charge'))";
        } elseif ($type === 'withdrawal') {
            $whereBlocks[] = "t.source_cat = 'saving_tbl' AND (t.amount < 0 OR LOWER(t.type) IN ('withdrawal', 'return_cash', 'return cash', 'return', 'adjust', 'cash', 'debit', 'charge'))";
        } elseif ($type === 'repayment') {
            $whereBlocks[] = "t.source_cat = 'collection_tbl'";
        } elseif ($type === 'disbursement') {
            $whereBlocks[] = "t.source_cat = 'disbursement_tbl'";
        }

        if (!empty($startDate)) {
            $whereBlocks[] = "DATE(t.date) >= ?";
            $params[] = $startDate;
        }
        
        if (!empty($endDate)) {
            $whereBlocks[] = "DATE(t.date) <= ?";
            $params[] = $endDate;
        }

        if (!empty($search)) {
            $whereBlocks[] = "(c.name LIKE ? OR t.transaction_id LIKE ? OR t.amount LIKE ?)";
            $searchWildcard = "%$search%";
            $params[] = $searchWildcard;
            $params[] = $searchWildcard;
            $params[] = $searchWildcard;
        }

        $whereSql = "";
        if (count($whereBlocks) > 0) {
            $whereSql = "WHERE " . implode(" AND ", $whereBlocks);
        }

        // A. Query for total statistics (Count & Volume)
        $statsQuery = "
            SELECT COUNT(*) as total_count, COALESCE(SUM(ABS(t.amount)), 0) as total_volume
            FROM ($baseSubquery) t
            LEFT JOIN clients c ON t.client_id = c.id
            $whereSql
        ";
        $stmtStats = $conn->prepare($statsQuery);
        $stmtStats->execute($params);
        $stats = $stmtStats->fetch(PDO::FETCH_ASSOC);
        $totalCount = (int)$stats['total_count'];
        $totalVol = (float)$stats['total_volume'];

        // B. Query for actual paginated data
        $dataQuery = "
            SELECT t.transaction_id, t.date, t.client_id, t.amount, t.type, t.source_cat, c.name as client_name
            FROM ($baseSubquery) t
            LEFT JOIN clients c ON t.client_id = c.id
            $whereSql
            ORDER BY t.date DESC
            LIMIT $perPage OFFSET $offset
        ";
        $stmtData = $conn->prepare($dataQuery);
        $stmtData->execute($params);
        
        $transactions = [];
        while ($row = $stmtData->fetch(PDO::FETCH_ASSOC)) {
            $raw_amt = floatval($row['amount']);
            $raw_type = strtolower($row['type'] ?? '');
            $source = $row['source_cat'];
            
            $displayType = 'Transaction';
            $category = 'unknown';
            $icon = 'fa-circle';
            
            if ($source === 'saving_tbl') {
                // Same logic as clients.php
                $is_withdrawal = ($raw_amt < 0) || in_array($raw_type, ['withdrawal', 'return_cash', 'return cash', 'return', 'adjust', 'cash', 'debit', 'charge']);
                
                if ($is_withdrawal) {
                    $displayType = 'Withdrawal';
                    if (strpos($raw_type, 'return') !== false || $raw_type === 'return_cash') {
                        $displayType = 'Return Cash';
                    } elseif ($raw_type === 'charge' || $raw_type === 'fee' || $raw_type === 'debit') {
                        $displayType = 'Fee/Charges';
                    }
                    $category = 'withdrawal';
                    $icon = 'fa-wallet';
                } else {
                    $displayType = 'Saving Deposit';
                    if ($raw_type === 'transfer') {
                        $displayType = 'Transfer';
                    }
                    $category = 'saving';
                    $icon = 'fa-piggy-bank';
                }
            } elseif ($source === 'collection_tbl') {
                $displayType = 'Loan Repayment';
                $category = 'repayment';
                $icon = 'fa-money-bill-wave';
            } elseif ($source === 'disbursement_tbl') {
                $displayType = 'Loan Disbursement';
                $category = 'disbursement';
                $icon = 'fa-hand-holding-usd';
            }

            $transactions[] = [
                'id' => $row['transaction_id'],
                'date' => $row['date'],
                'client' => $row['client_name'] ?? 'Unknown Client',
                'type' => $displayType,
                'amount' => abs($raw_amt),
                'cat' => $category,
                'icon' => $icon
            ];
        }
        
        ob_end_clean(); // Discard any warnings/spaces
        echo json_encode([
            'success' => true,
            'data' => $transactions,
            'totalCount' => $totalCount,
            'totalVolume' => $totalVol,
            'hasMore' => ($offset + count($transactions)) < $totalCount
        ]);
        
    } catch (Exception $e) {
        ob_end_clean();
        echo json_encode([
            'success' => false,
            'error' => "PHP/SQL Error: " . $e->getMessage()
        ]);
    }
    exit();
}
// ==============================================================================


$base_path = '../';
$page_title = "History";
$full_name = $_SESSION['name'] ?? 'Credit Officer';

// Get User Profile Pic for layout
$profile_pic = 'default_avatar.png';
$stmt = $conn->prepare("SELECT profile_pic FROM users WHERE username = ?");
$stmt->execute([$username]);
if ($u = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $profile_pic = !empty($u['profile_pic']) ? $u['profile_pic'] : 'default_avatar.png';
}
$profile_pic_path = $base_path . $profile_pic;
$has_valid_pic = file_exists($profile_pic_path) && strpos($profile_pic, 'default') === false;

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#3b82f6">
    <title>History | CUPAD</title>
    
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    
    <script>
    tailwind.config = {
        darkMode: 'class',
        theme: {
            extend: {
                colors: {
                    primary: '#3b82f6',
                    secondary: '#8b5cf6',
                    success: '#22c55e',
                    warning: '#f59e0b',
                    error: '#ef4444',
                    bg: { light: '#f0f2f5', dark: '#0f172a' }
                }
            }
        }
    }
    </script>

    <style>
        :root {
            --primary-color: #3b82f6; 
            --bg-primary: #f0f2f5; 
            --bg-secondary: #ffffff;
            --bg-header: rgba(255, 255, 255, 0.95);
            --border-color: #e5e7eb;
            --text-primary: #1f2937;
            --text-secondary: #6b7280;
        }
        html.dark {
            --bg-primary: #0f172a; 
            --bg-secondary: #1e293b;
            --bg-header: rgba(15, 23, 42, 0.95);
            --border-color: rgba(255, 255, 255, 0.08);
            --text-primary: #f1f5f9;
            --text-secondary: #94a3b8;
        }
        
        body { font-family: 'Inter', sans-serif; background-color: var(--bg-primary); color: var(--text-primary); padding-bottom: 90px; -webkit-tap-highlight-color: transparent; }

        /* HEADER */
        .main-header { background: var(--bg-header); backdrop-filter: blur(12px); border-bottom: 1px solid var(--border-color); position: sticky; top: 0; z-index: 50; padding-top: env(safe-area-inset-top); }
        .navbar { display: flex; justify-content: space-between; align-items: center; padding: 0.8rem 1rem; max-width: 1280px; margin: 0 auto; }
        .logo { font-weight: 800; font-size: 1.25rem; color: var(--primary-color); display: flex; align-items: center; gap: 0.5rem; text-decoration: none; }
        .logo img { height: 32px; width: auto; }
        
        /* CARDS & STATS */
        .dashboard-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 1.5rem; }
        .dashboard-card { border-radius: 1.25rem; padding: 1.5rem; position: relative; overflow: hidden; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1); color: white; transition: transform 0.2s ease; border: none; }
        .card-blue { background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); }
        .card-purple { background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%); }
        .card-bg-icon { position: absolute; right: -10px; bottom: -20px; font-size: 5rem; color: white; opacity: 0.1; transform: rotate(-10deg); pointer-events: none; }

        /* FILTERS */
        .search-container { background: var(--bg-secondary); padding: 1rem; border-radius: 1rem; border: 1px solid var(--border-color); margin-bottom: 1.5rem; }
        .search-input { width: 100%; padding: 0.8rem 1rem 0.8rem 2.8rem; border-radius: 0.75rem; border: 1px solid var(--border-color); background: var(--bg-primary); color: var(--text-primary); transition: all 0.2s; }
        .search-input:focus { outline: none; border-color: var(--primary-color); box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.2); background: var(--bg-secondary); }
        .search-icon { position: absolute; left: 1rem; top: 50%; transform: translateY(-50%); color: var(--text-secondary); }
        .filter-tabs { display: flex; gap: 0.5rem; overflow-x: auto; margin-top: 1rem; padding-bottom: 5px; scrollbar-width: none; }
        .filter-tab { white-space: nowrap; padding: 0.4rem 1rem; border-radius: 2rem; background: var(--bg-primary); color: var(--text-secondary); border: 1px solid transparent; font-size: 0.8rem; font-weight: 600; cursor: pointer; transition: 0.2s; }
        .filter-tab.active { background: var(--primary-color); color: white; }

        /* LIST ITEM */
        .tx-list { display: flex; flex-direction: column; gap: 0.75rem; }
        .tx-card { display: flex; align-items: flex-start; background: var(--bg-secondary); padding: 1rem; border-radius: 1rem; border: 1px solid var(--border-color); transition: transform 0.1s; position: relative; }
        .tx-card:active { transform: scale(0.98); }
        
        .tx-icon { width: 44px; height: 44px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; margin-right: 0.8rem; flex-shrink: 0; color: white; margin-top: 2px; }
        .bg-saving { background: linear-gradient(135deg, #10b981, #059669); }
        .bg-withdrawal { background: linear-gradient(135deg, #ef4444, #dc2626); }
        .bg-repayment { background: linear-gradient(135deg, #8b5cf6, #7c3aed); }
        .bg-disbursement { background: linear-gradient(135deg, #f59e0b, #d97706); }
        
        .tx-info { flex: 1; min-width: 0; display: flex; flex-direction: column; }
        .tx-title { font-weight: 600; color: var(--text-primary); font-size: 0.95rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .tx-sub { font-size: 0.75rem; color: var(--text-secondary); margin-bottom: 4px; }
        
        /* COPYABLE ID BADGE */
        .id-badge {
            display: inline-flex; align-items: center; gap: 6px;
            background: var(--bg-primary); 
            border: 1px solid var(--border-color);
            padding: 4px 10px; border-radius: 6px; 
            font-size: 0.7rem; font-family: monospace; font-weight: 600;
            color: var(--text-secondary); cursor: pointer; 
            transition: all 0.2s; width: fit-content;
        }
        .id-badge:hover { background: rgba(59, 130, 246, 0.1); color: var(--primary-color); border-color: var(--primary-color); }
        .id-badge:active { transform: scale(0.95); }
        
        .tx-right { text-align: right; }
        .tx-amount { font-weight: 700; font-size: 1rem; }
        .tx-amt-sub { font-size: 0.7rem; color: var(--text-secondary); display: block; margin-top: 2px; }
        .amt-green { color: #10b981; } .amt-red { color: #ef4444; } .amt-blue { color: #3b82f6; } .amt-orange { color: #f59e0b; }

        /* BOTTOM NAV */
        .mobile-bottom-nav { display: none; position: fixed; bottom: 0; left: 0; right: 0; background: var(--bg-secondary); border-top: 1px solid var(--border-color); padding: 0.5rem 1rem calc(0.5rem + env(safe-area-inset-bottom)); z-index: 100; justify-content: space-between; box-shadow: 0 -4px 10px rgba(0,0,0,0.05); }
        .nav-item { display: flex; flex-direction: column; align-items: center; justify-content: center; text-decoration: none; color: var(--text-secondary); font-size: 0.7rem; font-weight: 500; gap: 4px; flex: 1; }
        .nav-item i { font-size: 1.4rem; margin-bottom: 2px; }
        .nav-item.active { color: var(--primary-color); }
        
        /* Mobile Carousel for Dashboard Grid */
        @media (max-width: 768px) {
            .mobile-bottom-nav { display: flex; }
            .dashboard-grid { display: flex; overflow-x: auto; scroll-snap-type: x mandatory; gap: 1rem; padding-bottom: 0.5rem; margin-right: -1rem; padding-right: 1.5rem; scrollbar-width: none; }
            .dashboard-grid::-webkit-scrollbar { display: none; }
            .dashboard-card { min-width: 85vw; scroll-snap-align: center; flex-shrink: 0; }
        }

        /* Toast */
        #toast-container { position: fixed; top: 1rem; left: 50%; transform: translateX(-50%); z-index: 999; width: 90%; max-width: 350px; pointer-events: none; }
        .toast { background: var(--bg-secondary); border-left: 4px solid; padding: 1rem; border-radius: 8px; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); margin-bottom: 0.5rem; display: flex; align-items: center; gap: 10px; animation: slideDown 0.3s ease; pointer-events: auto; }
        @keyframes slideDown { from { transform: translateY(-20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
    </style>
</head>
<body>

    <div id="toast-container"></div>

    <!-- Header -->
    <header class="main-header">
        <nav class="navbar">
            <a href="dashboard.php" class="logo">
                <img src="<?php echo htmlspecialchars($base_path . 'favicon.ico'); ?>" alt="Logo">
                <span>CUPAD</span>
            </a>
            
            <div class="flex items-center gap-3">
                <button id="themeToggle" class="p-2 text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-slate-800 rounded-full transition">
                    <i class="fas fa-moon dark:hidden"></i><i class="fas fa-sun hidden dark:inline"></i>
                </button>
                
                <div class="w-9 h-9 rounded-full overflow-hidden border-2 border-blue-500 bg-gray-100 dark:bg-slate-700 flex items-center justify-center">
                    <?php if($has_valid_pic): ?>
                        <img src="<?php echo htmlspecialchars($profile_pic_path); ?>" alt="Profile" class="w-full h-full object-cover">
                    <?php else: ?>
                        <i class="fas fa-user text-gray-400 dark:text-gray-300"></i>
                    <?php endif; ?>
                </div>

                <a href="<?php echo htmlspecialchars($base_path . 'logout.php'); ?>" class="p-2 text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-full transition" title="Logout">
                    <i class="fas fa-sign-out-alt"></i>
                </a>
            </div>
        </nav>
    </header>

    <main class="max-w-3xl mx-auto px-4 py-6">
        
        <div class="flex justify-between items-end mb-6">
            <div>
                <h1 class="text-2xl font-extrabold text-gray-900 dark:text-white">History</h1>
                <p class="text-sm text-gray-500 dark:text-gray-400">All transactions</p>
            </div>
            <button onclick="exportData()" class="text-blue-500 text-sm font-bold hover:underline"><i class="fas fa-download mr-1"></i> Report</button>
        </div>

        <!-- Stats -->
        <div class="dashboard-grid">
            <div class="dashboard-card card-blue">
                <i class="fas fa-chart-line card-bg-icon"></i>
                <div class="relative z-10">
                    <div class="text-xs uppercase opacity-80 font-bold">Total Volume</div>
                    <div class="text-2xl font-extrabold mt-1"><span id="totalVol">...</span></div>
                </div>
            </div>
            <div class="dashboard-card card-purple">
                <i class="fas fa-list-ol card-bg-icon"></i>
                <div class="relative z-10">
                    <div class="text-xs uppercase opacity-80 font-bold">Transactions</div>
                    <div class="text-2xl font-extrabold mt-1" id="totalCount">...</div>
                </div>
            </div>
        </div>

        <!-- Search & Filter -->
        <div class="search-container">
            <div class="relative">
                <i class="fas fa-search search-icon"></i>
                <input type="text" id="searchInput" class="search-input" placeholder="Search by name, ID, or amount...">
            </div>
            <div class="filter-tabs">
                <button class="filter-tab active" onclick="filterType('all', this)">All</button>
                <button class="filter-tab" onclick="filterType('today', this)">Today</button>
                <button class="filter-tab" onclick="filterType('saving', this)">Savings</button>
                <button class="filter-tab" onclick="filterType('withdrawal', this)">Withdrawals</button>
                <button class="filter-tab" onclick="filterType('repayment', this)">Loans</button>
                <button class="filter-tab" onclick="filterType('disbursement', this)">Disbursed</button>
            </div>
            <div class="flex items-center gap-2 mt-4">
                <input type="date" id="startDate" class="search-input !w-1/2 !py-2">
                <input type="date" id="endDate" class="search-input !w-1/2 !py-2">
                <button id="clearDates" class="hidden w-8 h-8 rounded-full bg-gray-200 dark:bg-slate-700 text-gray-500 hover:bg-red-500 hover:text-white transition-all flex items-center justify-center flex-shrink-0" title="Clear dates">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        </div>

        <!-- List -->
        <div id="txList" class="tx-list">
            <!-- Loading Spinner -->
            <div class="text-center py-10 text-gray-400">
                <i class="fas fa-circle-notch fa-spin text-2xl"></i>
            </div>
        </div>
        
        <div id="loadMoreContainer" class="text-center mt-6 hidden">
            <button id="loadMoreBtn" onclick="loadMore()" class="px-6 py-2 bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-xl text-sm font-bold text-gray-600 dark:text-gray-300 shadow-sm hover:bg-gray-50 transition">
                Load More
            </button>
        </div>

    </main>

    <!-- Mobile Nav -->
    <nav class="mobile-bottom-nav">
        <a href="dashboard.php" class="nav-item">
            <i class="fas fa-home"></i>
            <span>Home</span>
        </a>
        <a href="saving_collection.php" class="nav-item">
            <i class="fas fa-piggy-bank"></i>
            <span>Save</span>
        </a>
        <a href="disbursement.php" class="nav-item">
             <i class="fas fa-hand-holding-usd"></i>
             <span>Disburse</span>
        </a>
        <a href="loan_collection.php" class="nav-item">
            <i class="fas fa-money-bill-wave"></i>
            <span>Repay</span>
        </a>
        <a href="track_transactions.php" class="nav-item active">
             <div style="background: var(--primary-color); width: 45px; height: 45px; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; margin-top: -25px; box-shadow: 0 4px 10px rgba(59, 130, 246, 0.4);">
                <i class="fas fa-history" style="font-size: 1.2rem; margin:0;"></i>
             </div>
             <span style="font-size:0.65rem; font-weight:600; margin-top:2px;">History</span>
        </a>
    </nav>

    <script>
        const $ = id => document.getElementById(id);
        const formatMoney = amt => '₦' + Number(amt).toLocaleString();

        let state = {
            type: 'all',
            search: '',
            startDate: '',
            endDate: '',
            page: 1,
            perPage: 20,
            hasMore: false,
            isLoading: false
        };

        let searchTimeout;

        const themeBtn = $('themeToggle');
        if(localStorage.getItem('theme') === 'dark') document.documentElement.classList.add('dark');
        themeBtn.onclick = () => {
            document.documentElement.classList.toggle('dark');
            localStorage.setItem('theme', document.documentElement.classList.contains('dark') ? 'dark' : 'light');
        };

        async function fetchTransactions(append = false) {
            if (state.isLoading) return;
            state.isLoading = true;

            const listEl = $('txList');
            const loadMoreBtn = $('loadMoreBtn');
            const loadMoreContainer = $('loadMoreContainer');

            if (!append) {
                listEl.innerHTML = `
                    <div class="text-center py-10 text-gray-400">
                        <i class="fas fa-circle-notch fa-spin text-2xl"></i>
                        <p class="mt-2 text-sm">Loading data...</p>
                    </div>`;
                loadMoreContainer.classList.add('hidden');
            } else {
                loadMoreBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Loading...';
                loadMoreBtn.disabled = true;
            }

            const params = new URLSearchParams({
                ajax: 1,
                page: state.page,
                perPage: state.perPage,
                type: state.type,
                search: state.search,
                startDate: state.startDate,
                endDate: state.endDate
            });

            try {
                const response = await fetch(window.location.pathname + '?' + params.toString());
                const res = await response.json(); 

                if (res.success) {
                    state.hasMore = res.hasMore;

                    $('totalCount').innerText = res.totalCount.toLocaleString();
                    $('totalVol').innerText = formatMoney(res.totalVolume);

                    if (!append) listEl.innerHTML = ''; 

                    if (res.data.length === 0 && !append) {
                        listEl.innerHTML = `
                            <div class="text-center py-12 text-gray-400">
                                <i class="fas fa-search text-4xl mb-3 opacity-30"></i>
                                <p>No transactions found.</p>
                            </div>`;
                    } else {
                        renderItems(res.data, listEl);
                    }

                    if (state.hasMore) {
                        loadMoreContainer.classList.remove('hidden');
                        loadMoreBtn.innerHTML = 'Load More';
                        loadMoreBtn.disabled = false;
                    } else {
                        loadMoreContainer.classList.add('hidden');
                    }
                } else {
                    throw new Error(res.error || 'Server returned false success state');
                }
            } catch (err) {
                console.error("AJAX ERROR LOG:", err);
                if (!append) {
                    listEl.innerHTML = `
                        <div class="text-center py-10">
                            <i class="fas fa-exclamation-triangle text-3xl mb-3 text-red-500"></i>
                            <h3 class="text-gray-900 dark:text-white font-bold mb-1">Database Error</h3>
                            <div class="bg-red-50 dark:bg-red-900/30 text-red-600 dark:text-red-400 border border-red-200 dark:border-red-800 p-3 rounded-lg text-xs text-left max-w-[90%] mx-auto overflow-auto font-mono">
                                ${err.message}
                            </div>
                            <button onclick="fetchTransactions()" class="mt-4 text-blue-500 hover:underline text-sm font-bold">Try Again</button>
                        </div>`;
                }
            }

            state.isLoading = false;
        }

        function renderItems(data, listEl) {
            data.forEach(tx => {
                const dateObj = new Date(tx.date);
                const dateStr = dateObj.toLocaleDateString(undefined, {month:'short', day:'numeric'});
                const timeStr = dateObj.toLocaleTimeString(undefined, {hour:'2-digit', minute:'2-digit'});
                
                let colorClass = 'amt-blue';
                let bgClass = 'bg-repayment';
                
                if (tx.cat === 'saving') { colorClass='amt-green'; bgClass='bg-saving'; }
                if (tx.cat === 'withdrawal') { colorClass='amt-red'; bgClass='bg-withdrawal'; }
                if (tx.cat === 'disbursement') { colorClass='amt-orange'; bgClass='bg-disbursement'; }

                const el = document.createElement('div');
                el.className = 'tx-card';
                el.innerHTML = `
                    <div class="tx-icon ${bgClass}">
                        <i class="fas ${tx.icon}"></i>
                    </div>
                    <div class="tx-info">
                        <div class="tx-title">${tx.client}</div>
                        <div class="tx-sub">${tx.type}</div>
                        
                        <div class="id-badge" onclick="copyId('${tx.id}', event)">
                            <span>#${tx.id.substring(0,10)}...</span>
                            <i class="fas fa-copy" style="font-size:0.6rem"></i>
                        </div>
                    </div>
                    <div class="tx-right">
                        <div class="tx-amount ${colorClass}">${formatMoney(tx.amount)}</div>
                        <span class="tx-amt-sub">${dateStr}, ${timeStr}</span>
                    </div>
                `;
                listEl.appendChild(el);
            });
        }

        function filterType(type, btn) {
            document.querySelectorAll('.filter-tab').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            
            state.type = type;
            state.page = 1;
            fetchTransactions();
        }

        $('searchInput').addEventListener('input', (e) => {
            state.search = e.target.value.trim();
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                state.page = 1;
                fetchTransactions();
            }, 400); 
        });

        $('startDate').addEventListener('input', (e) => {
            state.startDate = e.target.value;
            state.page = 1;
            fetchTransactions();
            updateClearButtonVisibility();
        });

        $('endDate').addEventListener('input', (e) => {
            state.endDate = e.target.value;
            state.page = 1;
            fetchTransactions();
            updateClearButtonVisibility();
        });

        function updateClearButtonVisibility() {
            if ($('startDate').value || $('endDate').value) {
                $('clearDates').classList.remove('hidden');
            } else {
                $('clearDates').classList.add('hidden');
            }
        }

        $('clearDates').addEventListener('click', () => {
            $('startDate').value = '';
            $('endDate').value = '';
            state.startDate = '';
            state.endDate = '';
            state.page = 1;
            fetchTransactions();
            updateClearButtonVisibility();
        });

        function loadMore() {
            if (!state.hasMore) return;
            state.page++;
            fetchTransactions(true);
        }

        function copyId(id, event) {
            if(event) event.stopPropagation();
            navigator.clipboard.writeText(id).then(() => {
                showToast(`ID copied!`);
            }).catch(err => {
                console.error('Failed to copy: ', err);
            });
        }

        function showToast(msg) {
            const el = document.createElement('div');
            el.className = 'toast';
            el.style.borderLeftColor = '#22c55e';
            el.innerHTML = `<i class="fas fa-check-circle text-green-500"></i> <span class="text-sm font-medium text-gray-800 dark:text-gray-200">${msg}</span>`;
            $('toast-container').appendChild(el);
            setTimeout(() => { el.style.opacity='0'; setTimeout(()=>el.remove(), 300); }, 2000);
        }

        function exportData() {
            alert("Export functionality coming soon!");
        }

        document.addEventListener('DOMContentLoaded', () => {
            fetchTransactions();
            updateClearButtonVisibility();
        });
    </script>
</body>
</html>