<?php
// bm/history.php - Branch Manager Transaction History
session_start();

// --- DATABASE CONNECTION ---
require_once __DIR__ . '/../includes/config.php';
$conn = getDbConnection();

// Security Check
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'bm') {
    header('Location: ../index.php');
    exit();
}

$base_path = '../';
$username = $_SESSION['username'] ?? '';
$user_id = $_SESSION['user_id'] ?? '';
$page_title = "Branch History";
$full_name = $_SESSION['name'] ?? 'Branch Manager';

// --- DATA FETCHING ---

// 1. Get User Profile Pic & Branch ID
$profile_pic = 'default_avatar.png';
$stmt = $conn->prepare("SELECT profile_pic, branch_id FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$u = $stmt->fetch(PDO::FETCH_ASSOC);

if ($u) {
    $profile_pic = !empty($u['profile_pic']) ? $u['profile_pic'] : 'default_avatar.png';
    $branch_id = $u['branch_id'];
}
$profile_pic_path = $base_path . $profile_pic;
$has_valid_pic = file_exists($profile_pic_path) && strpos($profile_pic, 'default') === false;

// 2. Fetch Credit Officers for Filter
$stmt_cos = $conn->prepare("SELECT username, full_name FROM users WHERE branch_id = ? AND role = 'co' AND status = 'active' ORDER BY full_name ASC");
$stmt_cos->execute([$branch_id]);
$co_list = $stmt_cos->fetchAll(PDO::FETCH_ASSOC);

// 3. Fetch All Branch Transactions (Unified Query)
$query = "
    SELECT t.transaction_id, t.date, t.client_id, t.amount, t.type, t.source_cat, t.officer, c.name as client_name
    FROM (
        -- Savings & Withdrawals
        SELECT transaction_id, date, client_id, amount, type, officer, 'saving_tbl' as source_cat 
        FROM saving_collections 
        
        UNION ALL
        
        -- Loan Repayments
        SELECT transaction_id, date, client_id, amount_collected as amount, 'repayment' as type, officer, 'collection_tbl' as source_cat 
        FROM loan_collections 
        
        UNION ALL
        
        -- Disbursements
        SELECT id as transaction_id, date, client_id, principal as amount, 'disbursement' as type, officer, 'disbursement_tbl' as source_cat 
        FROM disbursements 
    ) t
    INNER JOIN clients c ON t.client_id = c.id
    WHERE c.branch_id = ?
    ORDER BY t.date DESC
";

$stmt = $conn->prepare($query);
$stmt->execute([$branch_id]);

$all_transactions =[];

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $raw_amt = floatval($row['amount']);
    $raw_type = strtolower($row['type'] ?? '');
    $source = $row['source_cat'];
    
    // Determine Logic Category & Display Type
    $displayType = 'Transaction';
    $category = 'unknown';
    $icon = 'fa-circle';
    
    if ($source === 'saving_tbl') {
        if ($raw_amt < 0 || $raw_type === 'withdrawal' || $raw_type === 'return' || $raw_type === 'adjust' || $raw_type === 'cash') {
            $displayType = ucfirst($raw_type === 'cash' ? 'Cash Withdrawal' : ($raw_type === 'return' ? 'Loan Payoff' : 'Withdrawal'));
            $category = 'withdrawal';
            $icon = 'fa-wallet';
        } else {
            $displayType = 'Saving Deposit';
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

    $all_transactions[] = [
        'id' => $row['transaction_id'],
        'date' => $row['date'],
        'client' => $row['client_name'] ?? 'Unknown Client',
        'type' => $displayType,
        'amount' => abs($raw_amt),
        'cat' => $category,
        'icon' => $icon,
        'officer' => $row['officer'] ?? 'System'
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#3b82f6">
    <title>Branch History | CUPAD BM</title>
    
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    
    <script>
    tailwind.config = {
        darkMode: 'class',
        theme: {
            extend: {
                colors: {
                    primary: '#3b82f6', secondary: '#8b5cf6',
                    success: '#22c55e', warning: '#f59e0b', error: '#ef4444',
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
        .search-input { width: 100%; padding: 0.8rem 1rem 0.8rem 2.8rem; border-radius: 0.75rem; border: 1px solid var(--border-color); background: var(--bg-primary); color: var(--text-primary); transition: all 0.2s; font-size: 0.9rem; }
        .search-input:focus { outline: none; border-color: var(--primary-color); box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.2); background: var(--bg-secondary); }
        .search-icon { position: absolute; left: 1rem; top: 50%; transform: translateY(-50%); color: var(--text-secondary); }
        
        .filter-tabs { display: flex; gap: 0.5rem; overflow-x: auto; margin-top: 1rem; padding-bottom: 5px; scrollbar-width: none; }
        .filter-tabs::-webkit-scrollbar { display: none; }
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
        .tx-sub { font-size: 0.75rem; color: var(--text-secondary); margin-bottom: 4px; display: flex; align-items: center; gap: 6px; }
        
        /* COPYABLE ID BADGE */
        .id-badge {
            display: inline-flex; align-items: center; gap: 6px;
            background: var(--bg-primary); 
            border: 1px solid var(--border-color);
            padding: 4px 10px; border-radius: 6px; 
            font-size: 0.7rem; font-family: monospace; font-weight: 600;
            color: var(--text-secondary); cursor: pointer; 
            transition: all 0.2s; width: fit-content; margin-top: 2px;
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
                <span>CUPAD BM</span>
            </a>
            
            <div class="flex items-center gap-3">
                <button id="themeToggle" class="p-2 text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-slate-800 rounded-full transition">
                    <i class="fas fa-moon dark:hidden"></i><i class="fas fa-sun hidden dark:inline"></i>
                </button>
                
                <div class="w-9 h-9 rounded-full overflow-hidden border-2 border-blue-500 bg-gray-100 dark:bg-slate-700 flex items-center justify-center cursor-pointer" onclick="window.location.href='profile.php'">
                    <?php if($has_valid_pic): ?>
                        <img src="<?php echo htmlspecialchars($profile_pic_path); ?>" alt="Profile" class="w-full h-full object-cover">
                    <?php else: ?>
                        <i class="fas fa-user text-gray-400 dark:text-gray-300"></i>
                    <?php endif; ?>
                </div>

                <!-- LOGOUT BUTTON -->
                <a href="<?php echo htmlspecialchars($base_path . 'logout.php'); ?>" class="p-2 text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-full transition" title="Logout">
                    <i class="fas fa-sign-out-alt"></i>
                </a>
            </div>
        </nav>
    </header>

    <main class="max-w-3xl mx-auto px-4 py-6">
        
        <div class="flex justify-between items-end mb-6">
            <div>
                <h1 class="text-2xl font-extrabold text-gray-900 dark:text-white">Branch History</h1>
                <p class="text-sm text-gray-500 dark:text-gray-400">All branch transactions</p>
            </div>
            <button onclick="exportData()" class="text-blue-500 text-sm font-bold hover:underline bg-blue-50 dark:bg-blue-900/30 px-3 py-1.5 rounded-lg"><i class="fas fa-download mr-1"></i> Export</button>
        </div>

        <!-- Stats (Carousel on Mobile) -->
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
            <div class="flex flex-col gap-3">
                <div class="relative">
                    <i class="fas fa-search search-icon"></i>
                    <input type="text" id="searchInput" class="search-input" placeholder="Search by client, ID, amount...">
                </div>
                
                <div class="relative">
                    <i class="fas fa-user-tie search-icon"></i>
                    <select id="officerFilter" class="search-input appearance-none cursor-pointer">
                        <option value="all">All Officers</option>
                        <?php foreach($co_list as $co): ?>
                            <option value="<?php echo htmlspecialchars($co['username'] ?? ''); ?>">
                                <?php echo htmlspecialchars($co['full_name'] ?? $co['username'] ?? 'Unknown'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <i class="fas fa-chevron-down absolute right-4 top-1/2 -translate-y-1/2 text-gray-400 text-xs pointer-events-none"></i>
                </div>
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
                <input type="date" id="startDate" class="search-input !w-1/2 !py-2 !px-3">
                <input type="date" id="endDate" class="search-input !w-1/2 !py-2 !px-3">
                <button id="clearDates" class="hidden w-10 h-10 rounded-xl bg-gray-100 dark:bg-slate-700 text-gray-500 hover:bg-red-500 hover:text-white transition-all flex items-center justify-center flex-shrink-0" title="Clear dates">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        </div>

        <!-- List -->
        <div id="txList" class="tx-list">
            <!-- JS Injects -->
            <div class="text-center py-10 text-gray-400">
                <i class="fas fa-circle-notch fa-spin text-2xl"></i>
            </div>
        </div>
        
        <div id="loadMoreContainer" class="text-center mt-6 hidden">
            <button onclick="loadMore()" class="px-6 py-2 bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-xl text-sm font-bold text-gray-600 dark:text-gray-300 shadow-sm hover:bg-gray-50 transition w-full md:w-auto">
                Load More History
            </button>
        </div>

    </main>

    <!-- Mobile Nav for BM -->
    <nav class="mobile-bottom-nav">
        <a href="dashboard.php" class="nav-item">
            <i class="fas fa-home"></i>
            <span>Home</span>
        </a>
        <a href="combined_collection.php" class="nav-item">
            <i class="fas fa-coins"></i>
            <span>Collect</span>
        </a>
        <a href="history.php" class="nav-item active">
            <!-- BLUE CENTER FAB STYLE FOR ACTIVE TAB -->
             <div style="background: var(--primary-color); width: 45px; height: 45px; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; margin-top: -25px; box-shadow: 0 4px 10px rgba(59, 130, 246, 0.4);">
                <i class="fas fa-history" style="font-size: 1.2rem; margin:0;"></i>
             </div>
             <span style="font-size:0.65rem; font-weight:600; margin-top:2px;">History</span>
        </a>
        <a href="loans.php" class="nav-item">
            <i class="fas fa-file-invoice-dollar"></i>
            <span>Loans</span>
        </a>
        <a href="analytics.php" class="nav-item">
            <i class="fas fa-chart-bar"></i>
            <span>Reports</span>
        </a>
    </nav>

    <script>
        // Data Injection
        const allTx = <?php echo json_encode($all_transactions); ?>;
        
        // State
        let state = {
            data: allTx,
            filtered: allTx,
            type: 'all',
            search: '',
            officer: 'all',
            startDate: null,
            endDate: null,
            page: 1,
            perPage: 25
        };

        const $ = id => document.getElementById(id);
        const formatMoney = amt => '₦' + Number(amt).toLocaleString();

        // Theme Toggle
        const themeBtn = $('themeToggle');
        if(localStorage.getItem('theme') === 'dark') document.documentElement.classList.add('dark');
        themeBtn.onclick = () => {
            document.documentElement.classList.toggle('dark');
            localStorage.setItem('theme', document.documentElement.classList.contains('dark') ? 'dark' : 'light');
        };

        // Render Logic
        function render() {
            // Filter
            state.filtered = state.data.filter(item => {
                const today = new Date().toDateString();
                const itemDate = new Date(item.date);
                const itemDateStr = itemDate.toDateString();
                
                const matchesType = state.type === 'all' || 
                                   (state.type === 'today' ? itemDateStr === today : item.cat === state.type);
                
                const matchesSearch = item.client.toLowerCase().includes(state.search) || 
                                      item.id.toLowerCase().includes(state.search) ||
                                      item.amount.toString().includes(state.search);

                const matchesOfficer = state.officer === 'all' || item.officer === state.officer;
                
                let matchesDate = true;
                if (state.startDate) {
                    matchesDate = matchesDate && (itemDate >= state.startDate);
                }
                if (state.endDate) {
                    matchesDate = matchesDate && (itemDate <= state.endDate);
                }

                return matchesType && matchesSearch && matchesOfficer && matchesDate;
            });

            // Update Stats
            $('totalCount').innerText = state.filtered.length;
            const vol = state.filtered.reduce((sum, item) => sum + parseFloat(item.amount), 0);
            $('totalVol').innerText = formatMoney(vol);

            // Pagination Slice
            const displayData = state.filtered.slice(0, state.page * state.perPage);
            
            const listEl = $('txList');
            listEl.innerHTML = '';

            if (displayData.length === 0) {
                listEl.innerHTML = `
                    <div class="text-center py-12 text-gray-400">
                        <div class="w-16 h-16 bg-gray-100 dark:bg-slate-800 rounded-full flex items-center justify-center mx-auto mb-4 border border-dashed border-gray-300 dark:border-slate-600">
                            <i class="fas fa-search text-2xl text-gray-400"></i>
                        </div>
                        <p class="font-medium text-gray-600 dark:text-gray-300">No branch transactions found.</p>
                        <p class="text-xs mt-1">Try adjusting your filters.</p>
                    </div>`;
                $('loadMoreContainer').classList.add('hidden');
                return;
            }

            displayData.forEach(tx => {
                const dateObj = new Date(tx.date);
                const dateStr = dateObj.toLocaleDateString(undefined, {month:'short', day:'numeric', year:'numeric'});
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
                        <div class="tx-sub">
                            <span>${tx.type}</span>
                            <span class="opacity-50">•</span>
                            <span class="bg-gray-100 dark:bg-slate-700 px-1.5 py-0.5 rounded text-[10px] font-bold tracking-wide uppercase"><i class="fas fa-user-tie text-[9px] mr-1"></i>${tx.officer}</span>
                        </div>
                        
                        <!-- CLICKABLE ID BADGE -->
                        <div class="id-badge" onclick="copyId('${tx.id}', event)" title="Copy ID">
                            <span>#${tx.id.substring(0,10)}...</span>
                            <i class="fas fa-copy opacity-50"></i>
                        </div>
                    </div>
                    <div class="tx-right">
                        <div class="tx-amount ${colorClass}">${formatMoney(tx.amount)}</div>
                        <span class="tx-amt-sub">${dateStr}</span>
                        <span class="tx-amt-sub text-[9px] opacity-70">${timeStr}</span>
                    </div>
                `;
                listEl.appendChild(el);
            });

            // Load More Btn
            if (displayData.length < state.filtered.length) {
                $('loadMoreContainer').classList.remove('hidden');
            } else {
                $('loadMoreContainer').classList.add('hidden');
            }
        }

        // --- Actions ---

        function updateClearButtonVisibility() {
            if ($('startDate').value || $('endDate').value) {
                $('clearDates').classList.remove('hidden');
            } else {
                $('clearDates').classList.add('hidden');
            }
        }

        function filterType(type, btn) {
            state.type = type;
            state.page = 1;
            document.querySelectorAll('.filter-tab').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            render();
        }

        $('officerFilter').addEventListener('change', (e) => {
            state.officer = e.target.value;
            state.page = 1;
            render();
        });

        $('searchInput').addEventListener('input', (e) => {
            state.search = e.target.value.toLowerCase();
            state.page = 1;
            render();
        });

        $('startDate').addEventListener('input', (e) => {
            if (e.target.value) {
                state.startDate = new Date(e.target.value);
                state.startDate.setHours(0, 0, 0, 0); 
            } else {
                state.startDate = null;
            }
            state.page = 1;
            render();
            updateClearButtonVisibility();
        });

        $('endDate').addEventListener('input', (e) => {
            if (e.target.value) {
                state.endDate = new Date(e.target.value);
                state.endDate.setHours(23, 59, 59, 999);
            } else {
                state.endDate = null;
            }
            state.page = 1;
            render();
            updateClearButtonVisibility();
        });

        $('clearDates').addEventListener('click', () => {
            $('startDate').value = '';
            $('endDate').value = '';
            state.startDate = null;
            state.endDate = null;
            state.page = 1;
            render();
            updateClearButtonVisibility();
        });

        function loadMore() {
            state.page++;
            render();
        }

        function copyId(id, event) {
            if(event) event.stopPropagation();
            navigator.clipboard.writeText(id).then(() => {
                showToast(`ID ${id} copied!`);
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
            // Function placeholder - can be linked to an actual export script later
            showToast("Exporting data as CSV...");
            setTimeout(() => {
                alert("Export script goes here. Data ready for download.");
            }, 800);
        }

        // Init
        document.addEventListener('DOMContentLoaded', () => {
            render();
            updateClearButtonVisibility();
        });
    </script>
</body>
</html>