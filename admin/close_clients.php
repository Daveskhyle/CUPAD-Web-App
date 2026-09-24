<?php
// admin/close_clients.php - Admin tool to close zero-balance clients
require_once __DIR__ . '/../includes/config.php';
session_start();

$pdo = getDbConnection();

// Ensure User is Logged In and is an Admin
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Unauthorized access']);
        exit();
    }
    header('Location: ../index.php');
    exit();
}

$current_username = $_SESSION['username'] ?? '';
$base_path = '../';

// Helper function to log Admin actions
function log_admin_audit($pdo, $action, $table, $record_id) {
    try {
        $stmt = $pdo->prepare("INSERT INTO audit_log (user_id, username, action, table_name, record_id, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $_SESSION['user_id'] ?? 0,
            $_SESSION['username'] ?? 'admin',
            $action,
            $table,
            $record_id,
            $_SERVER['REMOTE_ADDR'],
            $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
        ]);
    } catch (Exception $e) { /* Fail silently */ }
}

// ===== AJAX HANDLING =====
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
    header('Content-Type: application/json');
    $action = $_GET['action'] ?? $_POST['action'] ?? '';

    switch ($action) {
        
        // 1. Fetch All Ready Clients (AJAX Load)
        case 'get_clients':
            try {
                $stmt_clients = $pdo->prepare("
                    SELECT c.id, c.name, c.`union`, c.phone, c.officer_username 
                    FROM clients c 
                    WHERE c.status = 'active'
                    AND ROUND(COALESCE((SELECT balance FROM saving_balances WHERE client_id = c.id LIMIT 1), 0), 2) = 0
                    AND ROUND(COALESCE((SELECT SUM(remaining_balance) FROM disbursements WHERE client_id = c.id AND remaining_balance > 0), 0), 2) = 0
                    ORDER BY c.name ASC
                ");
                $stmt_clients->execute();
                $ready_clients = $stmt_clients->fetchAll(PDO::FETCH_ASSOC);
                
                echo json_encode(['success' => true, 'clients' => $ready_clients]);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'error' => 'Failed to load clients.']);
            }
            exit();

        // 2. Fetch Single Client Balance Details
        case 'get_client_details':
            $client_id = $_GET['client_id'] ?? '';
            $stmt = $pdo->prepare("
                SELECT c.id, c.name, c.`union`,
                    COALESCE((SELECT balance FROM saving_balances WHERE client_id = c.id LIMIT 1), 0) as total_savings,
                    COALESCE((SELECT SUM(remaining_balance) FROM disbursements WHERE client_id = c.id AND remaining_balance > 0), 0) as total_loan
                FROM clients c
                WHERE c.id = :cid AND c.status = 'active'
            ");
            $stmt->execute(['cid' => $client_id]);
            $client = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($client) {
                echo json_encode(['success' => true, 'client' => $client]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Client not found or already closed.']);
            }
            exit();

        // 3. Close Single Client
        case 'close_client':
            $client_id = $_POST['client_id'] ?? '';
            if (empty($client_id)) {
                echo json_encode(['success' => false, 'error' => 'Client ID is required']);
                exit();
            }

            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare("
                    SELECT COALESCE((SELECT balance FROM saving_balances WHERE client_id = ? LIMIT 1), 0) as total_savings,
                           COALESCE((SELECT SUM(remaining_balance) FROM disbursements WHERE client_id = ? AND remaining_balance > 0), 0) as total_loan
                    FROM clients WHERE id = ? AND status = 'active' FOR UPDATE
                ");
                $stmt->execute([$client_id, $client_id, $client_id]);
                $bal_res = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$bal_res) throw new Exception("Client not found or already closed.");

                $savings = round((float)$bal_res['total_savings'], 2);
                $loan = round((float)$bal_res['total_loan'], 2);

                if ($savings != 0 || $loan != 0) {
                    throw new Exception("Outstanding balances detected (Savings: ₦$savings, Loan: ₦$loan).");
                }

                $pdo->prepare("UPDATE clients SET status = 'inactive' WHERE id = ?")->execute([$client_id]);
                log_admin_audit($pdo, 'DEACTIVATE', 'clients', $client_id);
                
                $pdo->commit();
                echo json_encode(['success' => true, 'message' => 'Client account successfully closed.']);
            } catch (Exception $e) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            exit();

        // 4. Bulk Close All Ready Clients
        case 'bulk_close':
            try {
                $pdo->beginTransaction();

                // Fetch and lock eligible clients
                $stmt = $pdo->prepare("
                    SELECT c.id 
                    FROM clients c 
                    WHERE c.status = 'active'
                    AND ROUND(COALESCE((SELECT balance FROM saving_balances WHERE client_id = c.id LIMIT 1), 0), 2) = 0
                    AND ROUND(COALESCE((SELECT SUM(remaining_balance) FROM disbursements WHERE client_id = c.id AND remaining_balance > 0), 0), 2) = 0
                    FOR UPDATE
                ");
                $stmt->execute();
                $eligible_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

                if (empty($eligible_ids)) {
                    throw new Exception("No eligible clients found to close.");
                }

                // Execute Bulk Update
                $inQuery = implode(',', array_fill(0, count($eligible_ids), '?'));
                $pdo->prepare("UPDATE clients SET status = 'inactive' WHERE id IN ($inQuery)")->execute($eligible_ids);

                foreach ($eligible_ids as $cid) {
                    log_admin_audit($pdo, 'DEACTIVATE_BULK', 'clients', $cid);
                }

                $pdo->commit();
                echo json_encode(['success' => true, 'count' => count($eligible_ids), 'message' => count($eligible_ids) . ' client(s) closed successfully.']);
            } catch (Exception $e) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            exit();
            
        // 5. Close Selected Clients Only
        case 'close_selected':
            $client_ids = $_POST['client_ids'] ?? [];
            if (!is_array($client_ids) || empty($client_ids)) {
                echo json_encode(['success' => false, 'error' => 'No clients selected.']);
                exit();
            }

            try {
                $pdo->beginTransaction();

                // Lock only the selected eligible clients to verify zero balances aren't altered
                $inQuery = implode(',', array_fill(0, count($client_ids), '?'));
                $stmt = $pdo->prepare("
                    SELECT c.id 
                    FROM clients c 
                    WHERE c.status = 'active' AND c.id IN ($inQuery)
                    AND ROUND(COALESCE((SELECT balance FROM saving_balances WHERE client_id = c.id LIMIT 1), 0), 2) = 0
                    AND ROUND(COALESCE((SELECT SUM(remaining_balance) FROM disbursements WHERE client_id = c.id AND remaining_balance > 0), 0), 2) = 0
                    FOR UPDATE
                ");
                $stmt->execute($client_ids);
                $eligible_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

                if (empty($eligible_ids)) {
                    throw new Exception("None of the selected clients are currently eligible for closure.");
                }

                // Execute Bulk Update on valid selected targets
                $updateQuery = implode(',', array_fill(0, count($eligible_ids), '?'));
                $pdo->prepare("UPDATE clients SET status = 'inactive' WHERE id IN ($updateQuery)")->execute($eligible_ids);

                foreach ($eligible_ids as $cid) {
                    log_admin_audit($pdo, 'DEACTIVATE_SELECTED', 'clients', $cid);
                }

                $pdo->commit();
                echo json_encode(['success' => true, 'count' => count($eligible_ids), 'message' => count($eligible_ids) . ' selected client(s) closed successfully.']);
            } catch (Exception $e) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            exit();
    }
}

// User Profile for UI
$stmt = $pdo->prepare("SELECT full_name, profile_pic FROM users WHERE username = ?");
$stmt->execute([$current_username]);
$u_row = $stmt->fetch(PDO::FETCH_ASSOC);

$full_name = !empty($u_row['full_name']) ? $u_row['full_name'] : 'Admin';
$profile_pic = $u_row['profile_pic'] ?? 'default_avatar.png';
$page_title = "Close Ready Accounts";

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title><?php echo htmlspecialchars($page_title); ?> | CUPAD Admin</title>
    
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    
    <script>
    tailwind.config = {
        darkMode: 'class',
        theme: {
            fontFamily: { sans: ['"Plus Jakarta Sans"', 'sans-serif'] },
            extend: { colors: { primary: '#f43f5e', bg: { light: '#f4f6f8', dark: '#0f172a' } } }
        }
    }
    </script>

    <style>
        :root {
            --bg-primary: #f4f6f8; --bg-secondary: #ffffff; --bg-card: #ffffff;
            --text-primary: #1f2937; --text-secondary: #6b7280; --border-color: #e5e7eb;
        }
        html.dark {
            --bg-primary: #0f172a; --bg-secondary: #1e293b; --bg-card: #1e293b;
            --text-primary: #f8fafc; --text-secondary: #94a3b8; --border-color: rgba(255, 255, 255, 0.08);
        }
        body { background-color: var(--bg-primary); color: var(--text-primary); padding-bottom: 90px; -webkit-tap-highlight-color: transparent; }
        
        .main-header { background: rgba(255, 255, 255, 0.95); backdrop-filter: blur(12px); border-bottom: 1px solid var(--border-color); position: sticky; top: 0; z-index: 50; }
        html.dark .main-header { background: rgba(15, 23, 42, 0.95); }
        .navbar { display: flex; justify-content: space-between; align-items: center; padding: 0.8rem 1rem; max-width: 1400px; margin: 0 auto; }
        .profile-btn { width: 36px; height: 36px; border-radius: 50%; overflow: hidden; border: 2px solid var(--primary); background: var(--bg-secondary); cursor: pointer; display: flex; align-items: center; justify-content: center; position: relative; }
        .profile-btn img { width: 100%; height: 100%; object-fit: cover; }
        
        .hero-banner { background: linear-gradient(135deg, #f43f5e 0%, #be123c 100%); border-radius: 1.25rem; padding: 2rem; position: relative; overflow: hidden; color: white; box-shadow: 0 10px 25px -5px rgba(244, 63, 94, 0.4); margin-bottom: 1.5rem; }
        .hero-icon-bg { position: absolute; right: -20px; bottom: -30px; font-size: 8rem; opacity: 0.1; transform: rotate(-15deg); pointer-events: none; }
        
        .search-container { position: sticky; top: 60px; z-index: 30; background: rgba(244, 246, 248, 0.8); backdrop-filter: blur(12px); padding: 1rem 0; margin-bottom: 1rem; margin-top: -1rem; }
        html.dark .search-container { background: rgba(15, 23, 42, 0.8); }
        
        .input-field { width: 100%; padding: 0.875rem 1rem 0.875rem 3.5rem; border-radius: 1rem; border: 1px solid var(--border-color); background: var(--bg-card); color: var(--text-primary); font-size: 0.95rem; font-weight: 500; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); transition: all 0.2s; }
        .input-field:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 4px rgba(244, 63, 94, 0.15); transform: translateY(-1px); }
        .select-field { padding: 0.875rem 1rem; border-radius: 1rem; border: 1px solid var(--border-color); background: var(--bg-card); color: var(--text-primary); font-size: 0.95rem; font-weight: 500; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); outline: none; cursor: pointer; transition: all 0.2s; appearance: none; padding-right: 2.5rem; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='%236b7280'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M19 9l-7 7-7-7'%3E%3C/path%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 1rem center; background-size: 1em; }
        .select-field:focus { border-color: var(--primary); box-shadow: 0 0 0 4px rgba(244, 63, 94, 0.15); transform: translateY(-1px); }

        .client-card { background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 1.25rem; padding: 1.25rem; transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1); box-shadow: 0 2px 4px rgba(0,0,0,0.02); display: flex; flex-direction: column; justify-content: space-between; cursor: pointer; position: relative; overflow: hidden; }
        .client-card:hover { transform: translateY(-4px) scale(1.01); box-shadow: 0 15px 25px -5px rgba(0, 0, 0, 0.1); border-color: rgba(244, 63, 94, 0.4); }
        .client-card::after { content: ''; position: absolute; top: 0; left: 0; width: 4px; height: 100%; background: var(--border-color); transition: background 0.3s; }
        .client-card:hover::after { background: var(--primary); }
        .client-card.is-selected { border-color: var(--primary); background: rgba(244, 63, 94, 0.03); box-shadow: 0 0 0 2px rgba(244, 63, 94, 0.2); }
        .client-card.is-selected::after { background: var(--primary); }
        html.dark .client-card.is-selected { background: rgba(244, 63, 94, 0.1); }
        
        .avatar { width: 46px; height: 46px; border-radius: 12px; background: linear-gradient(135deg, rgba(244, 63, 94, 0.15), rgba(190, 18, 60, 0.15)); color: var(--primary); display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 1.2rem; flex-shrink: 0; }
        
        /* Checkbox styling */
        .custom-checkbox { width: 22px; height: 22px; cursor: pointer; accent-color: var(--primary); }
        
        /* Modals */
        .modal-overlay { backdrop-filter: blur(6px); transition: opacity 0.3s ease; }
        .modal-content { transition: all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275); transform: scale(0.95); opacity: 0; }
        .modal-content.show { transform: scale(1); opacity: 1; }

        #toast-container { position: fixed; top: 1rem; left: 50%; transform: translateX(-50%); z-index: 999; width: 90%; max-width: 350px; pointer-events: none; }
        .toast { background: var(--bg-secondary); border-left: 4px solid; padding: 1rem; border-radius: 12px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1); margin-bottom: 0.5rem; display: flex; align-items: center; gap: 10px; animation: slideDown 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275); pointer-events: auto; font-weight: 600; }
        @keyframes slideDown { from { transform: translateY(-30px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
        
        .loader { border: 3px solid var(--border-color); border-top: 3px solid var(--primary); border-radius: 50%; width: 28px; height: 28px; animation: spin 1s linear infinite; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        
        .badge-pop { animation: pop 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275); }
        @keyframes pop { 0% { transform: scale(1); } 50% { transform: scale(1.4); } 100% { transform: scale(1); } }
    </style>
</head>
<body>

    <div id="toast-container"></div>

    <!-- ADMIN HEADER -->
    <header class="main-header">
        <nav class="navbar">
            <a href="dashboard.php" class="flex items-center gap-2 no-underline">
                <img src="<?php echo htmlspecialchars($base_path . 'uploads/CUPAD LOGO.png'); ?>" alt="Logo" class="h-8">
                <span class="text-xl font-extrabold text-blue-600 tracking-tight">CUPAD</span>
            </a>
            
            <div class="flex items-center gap-4">
                <a href="dashboard.php" class="text-gray-500 hover:text-gray-800 dark:text-gray-400 dark:hover:text-white transition" title="Dashboard">
                    <i class="fas fa-home text-lg"></i>
                </a>
                <a href="clients.php" class="text-gray-500 hover:text-gray-800 dark:text-gray-400 dark:hover:text-white transition" title="All Clients">
                    <i class="fas fa-users text-lg"></i>
                </a>
                <button id="themeToggle" class="p-1 text-gray-500 dark:text-gray-400 rounded-full hover:bg-gray-100 dark:hover:bg-gray-800 transition">
                    <i class="fas fa-moon dark:hidden"></i><i class="fas fa-sun hidden dark:inline"></i>
                </button>
                
                <div class="profile-btn" onclick="window.location.href='profile.php'">
                    <i class="fas fa-user profile-fallback-icon"></i>
                    <?php if (isset($profile_pic) && $profile_pic !== 'default_avatar.png' && file_exists($base_path . 'uploads/' . $profile_pic)): ?>
                        <img src="<?php echo $base_path . 'uploads/' . $profile_pic; ?>" alt="Profile" class="absolute inset-0" onerror="this.style.display='none'"> 
                    <?php endif; ?>
                </div>
            </div>
        </nav>
    </header>

    <main class="max-w-[1400px] mx-auto px-4 py-6">
        
        <!-- RICH HERO BANNER -->
        <div class="hero-banner">
            <i class="fas fa-user-check hero-icon-bg"></i>
            <div class="flex justify-between items-center relative z-10 flex-wrap gap-4">
                <div>
                    <h1 class="text-2xl md:text-3xl font-extrabold mb-1 drop-shadow-md">Close Ready Accounts</h1>
                    <p class="text-rose-100 text-sm font-medium opacity-90">These clients have exactly ₦0 in savings and ₦0 in loans.</p>
                </div>
                
                <div class="flex items-center gap-2 sm:gap-4 flex-wrap justify-end">
                    
                    <!-- Select All Toggle -->
                    <button id="btnSelectAll" onclick="toggleSelectAll()" class="hidden bg-white/20 hover:bg-white/30 border border-white/30 text-white px-3 py-2.5 rounded-xl font-bold transition flex items-center gap-2 text-sm shadow-sm backdrop-blur-md">
                        <i class="fas fa-check-square"></i> Select All
                    </button>

                    <!-- Bulk Close Button -->
                    <button id="btnBulkClose" onclick="openBulkModal('bulk')" class="hidden bg-white text-rose-600 px-4 py-2.5 rounded-xl font-bold shadow-lg hover:shadow-xl hover:bg-rose-50 transition transform hover:-translate-y-0.5 flex items-center gap-2 text-sm">
                        <i class="fas fa-layer-group"></i> Bulk Close All
                    </button>

                    <!-- Close Selected Button -->
                    <button id="btnCloseSelected" onclick="openBulkModal('selected')" class="hidden bg-gray-900 dark:bg-white dark:text-gray-900 text-white px-4 py-2.5 rounded-xl font-bold shadow-lg hover:shadow-xl transition transform hover:-translate-y-0.5 flex items-center gap-2 text-sm">
                        <i class="fas fa-check-double"></i> Close Selected (<span id="selectedCount" class="inline-block transition-transform duration-200">0</span>)
                    </button>

                    <!-- Counter -->
                    <div class="bg-white/20 backdrop-blur-md px-4 py-3 rounded-2xl border border-white/30 text-center shadow-inner hidden sm:block">
                        <div class="text-3xl font-black leading-none drop-shadow-md" id="heroCount">0</div>
                        <div class="text-[10px] uppercase tracking-wider font-bold text-rose-100 mt-1">Ready to Close</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- STICKY SEARCH BAR & SORTING -->
        <div class="search-container">
            <div class="flex flex-col sm:flex-row gap-3 w-full">
                <div class="relative w-full sm:flex-1">
                    <i class="fas fa-search absolute left-5 top-1/2 transform -translate-y-1/2 text-rose-400 text-lg"></i>
                    <input type="text" id="searchInput" placeholder="Search by name, union, or officer..." autocomplete="off" class="input-field" oninput="filterClients()">
                </div>
                
                <div class="flex gap-3">
                    <select id="sortSelect" onchange="sortAndRender()" class="select-field flex-shrink-0 min-w-[140px]">
                        <option value="name_asc">Name (A-Z)</option>
                        <option value="name_desc">Name (Z-A)</option>
                        <option value="union_asc">Union</option>
                    </select>
                    
                    <button onclick="loadClients()" class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 px-4 rounded-xl text-gray-500 hover:text-rose-500 transition shadow-sm flex-shrink-0" title="Refresh List">
                        <i class="fas fa-sync-alt"></i>
                    </button>
                </div>
            </div>
        </div>

        <!-- DYNAMIC CONTAINER -->
        <div id="loadingState" class="py-20 flex flex-col items-center justify-center">
            <div class="loader mb-4"></div>
            <p class="text-sm font-bold text-gray-500 animate-pulse">Scanning ledgers for eligible clients...</p>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4 hidden" id="clientGrid">
            <!-- Populated via AJAX -->
        </div>

        <!-- EMPTY STATE FOR SEARCH -->
        <div id="noResults" class="hidden text-center py-16">
            <div class="w-24 h-24 bg-gray-100 dark:bg-gray-800 rounded-full flex items-center justify-center mx-auto mb-5 text-gray-400 text-4xl shadow-inner">
                <i class="fas fa-search-minus"></i>
            </div>
            <h3 class="text-xl font-bold text-gray-900 dark:text-white mb-2">No matching clients</h3>
            <p class="text-sm text-gray-500 font-medium">Try adjusting your search term or filter.</p>
        </div>

        <!-- GLOBAL EMPTY STATE -->
        <div id="globalEmptyState" class="hidden text-center py-20 bg-white dark:bg-gray-800 rounded-3xl border-2 border-dashed border-gray-200 dark:border-gray-700 shadow-sm">
            <div class="w-24 h-24 bg-emerald-50 dark:bg-emerald-900/20 rounded-full flex items-center justify-center mx-auto mb-5 text-emerald-400 text-4xl shadow-inner border border-emerald-100 dark:border-emerald-800">
                <i class="fas fa-check-double"></i>
            </div>
            <h3 class="text-xl font-bold text-gray-900 dark:text-white mb-2">All Clear!</h3>
            <p class="text-sm text-gray-500 font-medium max-w-md mx-auto">There are currently no active clients in the system with ₦0 savings and ₦0 loans.</p>
        </div>

    </main>

    <!-- SINGLE CLOSURE MODAL -->
    <div id="closeModal" class="fixed inset-0 z-[60] hidden flex items-center justify-center p-4">
        <div class="modal-overlay absolute inset-0 bg-gray-900/60" onclick="closeModal('closeModal')"></div>
        <div class="modal-content relative bg-white dark:bg-gray-800 w-full max-w-md rounded-[1.5rem] shadow-2xl overflow-hidden border border-gray-200 dark:border-gray-700 flex flex-col max-h-[90vh]">
            
            <div class="bg-gray-50 dark:bg-gray-900/80 backdrop-blur p-4 border-b border-gray-200 dark:border-gray-700 flex justify-between items-center">
                <h3 class="font-bold text-gray-900 dark:text-white flex items-center gap-2 text-lg">
                    <span class="w-8 h-8 rounded-full bg-rose-100 dark:bg-rose-900/30 text-rose-600 flex items-center justify-center text-sm shadow-inner"><i class="fas fa-shield-alt"></i></span>
                    Closure Review
                </h3>
                <button onclick="closeModal('closeModal')" class="text-gray-400 hover:text-gray-800 dark:hover:text-gray-200 w-8 h-8 flex items-center justify-center rounded-full hover:bg-gray-200 dark:hover:bg-gray-700 transition">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <div class="p-6 overflow-y-auto">
                <div class="text-center mb-6">
                    <h2 class="text-xl font-black text-gray-900 dark:text-white tracking-tight" id="modalClientName">Client Name</h2>
                    <p class="inline-flex items-center justify-center gap-1.5 px-3 py-1 mt-2 bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 rounded-lg text-xs font-bold uppercase tracking-wider" id="modalClientUnion">
                        <i class="fas fa-users text-rose-500"></i> Union Name
                    </p>
                </div>

                <div id="modalLoading" class="py-10 flex flex-col items-center justify-center">
                    <div class="loader mb-4"></div>
                    <p class="text-sm font-bold text-gray-500 animate-pulse">Verifying ledger balances...</p>
                </div>

                <div id="modalData" class="hidden">
                    <div class="flex gap-4 p-5 rounded-2xl bg-gray-50 dark:bg-gray-900/50 border border-gray-200 dark:border-gray-700 mb-6 shadow-inner">
                        <div class="flex-1 text-center">
                            <p class="text-[10px] text-gray-500 uppercase tracking-widest font-extrabold mb-1.5">Savings Balance</p>
                            <p class="text-2xl font-black tracking-tight" id="lblSavings">₦0.00</p>
                        </div>
                        <div class="w-px bg-gray-200 dark:bg-gray-700"></div>
                        <div class="flex-1 text-center">
                            <p class="text-[10px] text-gray-500 uppercase tracking-widest font-extrabold mb-1.5">Loan Balance</p>
                            <p class="text-2xl font-black tracking-tight" id="lblLoan">₦0.00</p>
                        </div>
                    </div>

                    <div id="closureWarning" class="hidden p-4 rounded-xl bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800/30 text-red-700 dark:text-red-400 text-sm mb-4 shadow-sm">
                        <div class="flex gap-3 items-start">
                            <div class="w-8 h-8 rounded-full bg-red-100 dark:bg-red-900/50 flex items-center justify-center flex-shrink-0 mt-0.5"><i class="fas fa-lock text-red-500"></i></div>
                            <div>
                                <strong class="block mb-1 text-base text-red-800 dark:text-red-300">Closure Blocked</strong>
                                <span class="opacity-90">Client balances are no longer zero.</span>
                            </div>
                        </div>
                    </div>

                    <div id="closureReady" class="hidden p-4 rounded-xl bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-800/30 text-emerald-700 dark:text-emerald-400 text-sm mb-4 shadow-sm">
                        <div class="flex gap-3 items-start">
                            <div class="w-8 h-8 rounded-full bg-emerald-100 dark:bg-emerald-900/50 flex items-center justify-center flex-shrink-0 mt-0.5"><i class="fas fa-unlock text-emerald-500"></i></div>
                            <div>
                                <strong class="block mb-1 text-base text-emerald-800 dark:text-emerald-300">Ready to Close</strong>
                                <span class="opacity-90">Ledgers are verified clear. Safely deactivate account.</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="p-5 border-t border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-900/80 backdrop-blur">
                <form id="closeForm">
                    <input type="hidden" id="selectedClientId" name="client_id">
                    <button type="submit" id="closeBtn" disabled class="w-full py-3.5 px-4 bg-rose-600 hover:bg-rose-700 disabled:bg-gray-300 dark:disabled:bg-gray-700 disabled:text-gray-500 disabled:cursor-not-allowed text-white font-extrabold rounded-xl transition-all shadow-[0_8px_20px_-6px_rgba(244,63,94,0.5)] disabled:shadow-none flex justify-center items-center gap-2 text-base active:transform active:scale-[0.98]">
                        <i class="fas fa-user-slash"></i> Confirm Deactivation
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- BULK / MULTI-SELECTION CLOSURE MODAL -->
    <div id="bulkModal" class="fixed inset-0 z-[60] hidden flex items-center justify-center p-4">
        <div class="modal-overlay absolute inset-0 bg-gray-900/60" onclick="closeModal('bulkModal')"></div>
        <div class="modal-content relative bg-white dark:bg-gray-800 w-full max-w-sm rounded-[1.5rem] shadow-2xl overflow-hidden border border-gray-200 dark:border-gray-700 flex flex-col">
            
            <div class="p-6 text-center">
                <div class="w-16 h-16 bg-rose-100 dark:bg-rose-900/30 text-rose-600 rounded-full flex items-center justify-center mx-auto mb-4 text-2xl shadow-inner">
                    <i class="fas fa-layer-group"></i>
                </div>
                <h2 class="text-xl font-black text-gray-900 dark:text-white tracking-tight mb-2" id="bulkModalTitle">Bulk Close Clients</h2>
                
                <p class="text-sm text-gray-500 dark:text-gray-400 mb-2" id="bulkModalDesc">
                    You are about to permanently deactivate <strong class="text-gray-900 dark:text-white" id="bulkCountDisplay">0</strong> accounts. This action is irreversible. Proceed?
                </p>

                <!-- Dynamic snippet of selected names -->
                <div id="bulkSummaryBox" class="bg-gray-50 dark:bg-gray-900/50 rounded-xl p-3 mb-6 text-xs text-left text-gray-600 dark:text-gray-400 border border-gray-200 dark:border-gray-700 max-h-24 overflow-y-auto">
                    <!-- populated dynamically -->
                </div>

                <div class="flex gap-3">
                    <button type="button" onclick="closeModal('bulkModal')" class="flex-1 py-3 px-4 bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 font-bold rounded-xl hover:bg-gray-200 dark:hover:bg-gray-600 transition">Cancel</button>
                    <button type="button" id="confirmBulkBtn" onclick="executeBulkClose()" class="flex-1 py-3 px-4 bg-rose-600 text-white font-bold rounded-xl hover:bg-rose-700 shadow-[0_8px_20px_-6px_rgba(244,63,94,0.5)] transition flex justify-center items-center gap-2">
                        Confirm
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        let clientsData = [];
        let closeMode = 'bulk'; // Can be 'bulk' or 'selected'

        // --- UI UTILS ---
        function showToast(msg, type) {
            const el = document.createElement('div');
            el.className = 'toast';
            el.style.borderLeftColor = type === 'success' ? '#10b981' : '#f43f5e';
            el.innerHTML = `<i class="fas ${type==='success'?'fa-check-circle text-emerald-500':'fa-exclamation-circle text-rose-500'}"></i> <span class="text-sm">${msg}</span>`;
            document.getElementById('toast-container').appendChild(el);
            setTimeout(() => { el.style.opacity = '0'; el.style.transform = 'translateY(-20px)'; setTimeout(() => el.remove(), 300); }, 3000);
        }

        function escapeHtml(str) {
            if(!str) return '';
            return str.replace(/[&<>'"]/g, tag => ({
                '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;'
            }[tag]));
        }

        // --- AJAX DATA LOADING & SORTING ---
        async function loadClients() {
            document.getElementById('loadingState').classList.remove('hidden');
            document.getElementById('clientGrid').classList.add('hidden');
            document.getElementById('globalEmptyState').classList.add('hidden');
            document.getElementById('noResults').classList.add('hidden');
            
            // Hide action buttons during load
            document.getElementById('btnBulkClose').classList.add('hidden');
            document.getElementById('btnCloseSelected').classList.add('hidden');
            document.getElementById('btnSelectAll').classList.add('hidden');
            
            document.getElementById('heroCount').textContent = '0';

            try {
                const res = await fetch('?action=get_clients', {headers: {'X-Requested-With': 'XMLHttpRequest'}});
                const data = await res.json();
                
                if (data.success) {
                    clientsData = data.clients || [];
                    sortAndRender(); // Sorts and renders based on current select dropdown
                } else {
                    showToast('Error loading clients', 'error');
                }
            } catch (err) {
                showToast('Network error', 'error');
            } finally {
                document.getElementById('loadingState').classList.add('hidden');
            }
        }

        function sortAndRender() {
            const sortVal = document.getElementById('sortSelect').value;
            
            // Sort the array in memory
            clientsData.sort((a, b) => {
                if (sortVal === 'name_asc') return a.name.localeCompare(b.name);
                if (sortVal === 'name_desc') return b.name.localeCompare(a.name);
                if (sortVal === 'union_asc') return a.union.localeCompare(b.union) || a.name.localeCompare(b.name);
                return 0;
            });
            
            renderClients();
        }

        function renderClients() {
            const grid = document.getElementById('clientGrid');
            const count = clientsData.length;
            
            document.getElementById('heroCount').textContent = count;
            
            if (count === 0) {
                document.getElementById('globalEmptyState').classList.remove('hidden');
                grid.innerHTML = '';
                updateSelectionUI();
                return;
            }

            let html = '';
            clientsData.forEach(c => {
                const name = escapeHtml(c.name);
                const union = escapeHtml(c.union);
                const officer = escapeHtml(c.officer_username || 'Unassigned');
                const initials = name.substring(0, 1).toUpperCase();
                const searchStr = `${name} ${union} ${officer}`.toLowerCase();

                // Build HTML using data attributes instead of inline js strings for safety
                html += `
                <div class="client-card group" data-id="${c.id}" data-search="${searchStr}" onclick="openCloseModal('${c.id}')">
                    
                    <!-- Multi-Select Checkbox -->
                    <div class="absolute top-3 right-3 z-20" onclick="event.stopPropagation();">
                        <input type="checkbox" value="${c.id}" class="client-select-cb custom-checkbox" onchange="handleCheckboxToggle(this)">
                    </div>

                    <div class="flex items-start gap-4 mb-3">
                        <div class="avatar">${initials}</div>
                        <div class="flex-1 min-w-0 pt-1 pr-6">
                            <h3 class="font-extrabold text-gray-900 dark:text-white truncate text-base">${name}</h3>
                            <div class="flex items-center mt-1">
                                <span class="bg-blue-50 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300 text-[10px] uppercase font-bold px-2 py-0.5 rounded-md border border-blue-200 dark:border-blue-800 truncate">
                                    <i class="fas fa-users mr-1 opacity-70"></i> ${union}
                                </span>
                            </div>
                        </div>
                    </div>
                    <div class="flex justify-between items-center text-xs border-t border-gray-100 dark:border-gray-800 pt-3 mt-auto">
                        <span class="text-gray-500 dark:text-gray-400 font-medium bg-gray-100 dark:bg-gray-800 px-2 py-1 rounded truncate max-w-[60%]">
                            <i class="fas fa-user-tie text-xs mr-1 opacity-70"></i> ${officer}
                        </span>
                        <span class="text-rose-500 font-bold flex items-center gap-1 group-hover:translate-x-1 transition-transform bg-rose-50 dark:bg-rose-900/30 px-2 py-1 rounded shrink-0">
                            Review <i class="fas fa-arrow-right text-[10px]"></i>
                        </span>
                    </div>
                </div>`;
            });

            grid.innerHTML = html;
            grid.classList.remove('hidden');
            
            filterClients();
            updateSelectionUI(); // Initialize button visibility
        }

        // --- SEARCH FILTERING ---
        function filterClients() {
            const term = document.getElementById('searchInput').value.toLowerCase();
            const cards = document.querySelectorAll('.client-card');
            let visibleCount = 0;

            if(cards.length === 0) return;

            cards.forEach(card => {
                if (card.getAttribute('data-search').includes(term)) {
                    card.style.display = 'flex';
                    visibleCount++;
                } else {
                    card.style.display = 'none';
                }
            });

            document.getElementById('noResults').style.display = (visibleCount === 0 && term !== '') ? 'block' : 'none';
            document.getElementById('clientGrid').style.display = visibleCount > 0 ? 'grid' : 'none';
            
            // Re-evaluate Selection UI to update Select All state based on visible items
            updateSelectionUI();
        }

        // --- MULTIPLE SELECTION LOGIC & UI EFFECTS ---
        function handleCheckboxToggle(checkbox) {
            const card = checkbox.closest('.client-card');
            if (checkbox.checked) {
                card.classList.add('is-selected');
            } else {
                card.classList.remove('is-selected');
            }
            updateSelectionUI();
            triggerBadgeAnimation();
        }

        function triggerBadgeAnimation() {
            const badge = document.getElementById('selectedCount');
            badge.classList.remove('badge-pop');
            void badge.offsetWidth; // trigger reflow
            badge.classList.add('badge-pop');
        }

        function updateSelectionUI() {
            const selectedCount = document.querySelectorAll('.client-select-cb:checked').length;
            const visibleCheckboxes = Array.from(document.querySelectorAll('.client-select-cb')).filter(cb => cb.closest('.client-card').style.display !== 'none');
            const visibleCount = visibleCheckboxes.length;
            const selectedVisibleCount = visibleCheckboxes.filter(cb => cb.checked).length;
            
            document.getElementById('selectedCount').textContent = selectedCount;
            
            const btnSelectAll = document.getElementById('btnSelectAll');
            const btnBulk = document.getElementById('btnBulkClose');
            const btnSelected = document.getElementById('btnCloseSelected');
            
            // Show Select All if data exists
            if (clientsData.length > 0) {
                btnSelectAll.classList.remove('hidden');
            } else {
                btnSelectAll.classList.add('hidden');
            }

            if (selectedCount > 0) {
                btnBulk.classList.add('hidden');
                btnSelected.classList.remove('hidden');
            } else {
                if (clientsData.length > 0) btnBulk.classList.remove('hidden');
                btnSelected.classList.add('hidden');
            }

            // Update Select All wording based on VISIBLE checked items
            if (selectedVisibleCount >= visibleCount && visibleCount > 0) {
                btnSelectAll.innerHTML = '<i class="fas fa-square"></i> Deselect All';
            } else {
                btnSelectAll.innerHTML = '<i class="fas fa-check-square"></i> Select All';
            }
        }

        function toggleSelectAll() {
            const checkboxes = Array.from(document.querySelectorAll('.client-select-cb'))
                                    .filter(cb => cb.closest('.client-card').style.display !== 'none');
            
            if(checkboxes.length === 0) return;

            const allChecked = checkboxes.every(cb => cb.checked);
            
            checkboxes.forEach(cb => {
                cb.checked = !allChecked; // flip selection based on current generic state
                const card = cb.closest('.client-card');
                if (cb.checked) {
                    card.classList.add('is-selected');
                } else {
                    card.classList.remove('is-selected');
                }
            });
            
            updateSelectionUI();
            triggerBadgeAnimation();
        }

        // --- MODAL LOGIC (SINGLE) ---
        function openCloseModal(id) {
            // Find client safely from JS memory
            const client = clientsData.find(c => c.id == id);
            if(!client) return;

            document.getElementById('selectedClientId').value = client.id;
            document.getElementById('modalClientName').textContent = client.name;
            document.getElementById('modalClientUnion').innerHTML = `<i class="fas fa-users text-rose-500"></i> ${escapeHtml(client.union)}`;
            
            document.getElementById('modalLoading').classList.remove('hidden');
            document.getElementById('modalData').classList.add('hidden');
            document.getElementById('closureWarning').classList.add('hidden');
            document.getElementById('closureReady').classList.add('hidden');
            document.getElementById('closeBtn').disabled = true;

            const modal = document.getElementById('closeModal');
            modal.classList.remove('hidden');
            document.body.style.overflow = 'hidden';
            setTimeout(() => { modal.querySelector('.modal-content').classList.add('show'); }, 10);

            fetchClientDetails(id);
        }

        function closeModal(modalId) {
            const modal = document.getElementById(modalId);
            modal.querySelector('.modal-content').classList.remove('show');
            setTimeout(() => {
                modal.classList.add('hidden');
                document.body.style.overflow = '';
            }, 300);
        }

        async function fetchClientDetails(id) {
            try {
                const res = await fetch(`?action=get_client_details&client_id=${encodeURIComponent(id)}`, {headers:{'X-Requested-With':'XMLHttpRequest'}});
                const data = await res.json();

                document.getElementById('modalLoading').classList.add('hidden');
                document.getElementById('modalData').classList.remove('hidden');

                if (data.success) {
                    const c = data.client;
                    const savings = parseFloat(c.total_savings);
                    const loan = parseFloat(c.total_loan);

                    const lblSavings = document.getElementById('lblSavings');
                    const lblLoan = document.getElementById('lblLoan');

                    lblSavings.textContent = '₦' + savings.toLocaleString('en-US', {minimumFractionDigits: 2});
                    lblLoan.textContent = '₦' + loan.toLocaleString('en-US', {minimumFractionDigits: 2});

                    lblSavings.className = `text-2xl font-black tracking-tight ${savings > 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-gray-900 dark:text-white'}`;
                    lblLoan.className = `text-2xl font-black tracking-tight ${loan > 0 ? 'text-rose-600 dark:text-rose-400' : 'text-gray-900 dark:text-white'}`;

                    if (savings != 0 || loan != 0) {
                        document.getElementById('closureWarning').classList.remove('hidden');
                        document.getElementById('closeBtn').disabled = true;
                    } else {
                        document.getElementById('closureReady').classList.remove('hidden');
                        document.getElementById('closeBtn').disabled = false;
                    }
                } else {
                    showToast(data.error, 'error');
                    closeModal('closeModal');
                }
            } catch (err) {
                showToast('Failed to load balances.', 'error');
                closeModal('closeModal');
            }
        }

        // --- SUBMIT CLOSURE (SINGLE) ---
        document.getElementById('closeForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            const btn = document.getElementById('closeBtn');
            const fd = new FormData(e.target);
            fd.append('action', 'close_client');

            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';

            try {
                const res = await fetch('', { method:'POST', body:fd, headers:{'X-Requested-With':'XMLHttpRequest'}});
                const data = await res.json();

                if (data.success) {
                    showToast(data.message, 'success');
                    closeModal('closeModal');
                    loadClients();
                } else {
                    showToast(data.error || 'Failed to close account', 'error');
                }
            } catch (err) {
                showToast('Network error occurred.', 'error');
            } finally {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-user-slash"></i> Confirm Deactivation';
            }
        });

        // --- BULK / MULTI-SELECTION CLOSURE ---
        function openBulkModal(mode = 'bulk') {
            closeMode = mode;
            const summaryBox = document.getElementById('bulkSummaryBox');
            summaryBox.innerHTML = ''; // reset summary

            if (mode === 'bulk') {
                document.getElementById('bulkModalTitle').textContent = 'Bulk Close All Clients';
                document.getElementById('bulkCountDisplay').textContent = clientsData.length;
                document.getElementById('bulkModalDesc').innerHTML = `You are about to permanently deactivate <strong class="text-gray-900 dark:text-white">${clientsData.length}</strong> eligible accounts. This action is irreversible.`;
                summaryBox.classList.add('hidden');
            } else {
                const checkedBoxes = Array.from(document.querySelectorAll('.client-select-cb:checked'));
                const selectedCount = checkedBoxes.length;
                
                document.getElementById('bulkModalTitle').textContent = 'Close Selected Clients';
                document.getElementById('bulkCountDisplay').textContent = selectedCount;
                document.getElementById('bulkModalDesc').innerHTML = `You are about to permanently deactivate <strong class="text-gray-900 dark:text-white">${selectedCount}</strong> selected accounts. This action is irreversible.`;
                
                // Build a summary of names
                let summaryHtml = '<ul class="list-disc pl-4 space-y-1">';
                let showCount = Math.min(selectedCount, 4); // show up to 4 names explicitly
                for(let i = 0; i < showCount; i++) {
                    const cid = checkedBoxes[i].value;
                    const c = clientsData.find(x => x.id == cid);
                    if(c) summaryHtml += `<li><span class="font-semibold text-gray-800 dark:text-gray-200">${escapeHtml(c.name)}</span> &mdash; ${escapeHtml(c.union)}</li>`;
                }
                if(selectedCount > 4) {
                    summaryHtml += `<li class="font-bold italic opacity-80 mt-1">...and ${selectedCount - 4} more.</li>`;
                }
                summaryHtml += '</ul>';
                summaryBox.innerHTML = summaryHtml;
                summaryBox.classList.remove('hidden');
            }

            const modal = document.getElementById('bulkModal');
            modal.classList.remove('hidden');
            document.body.style.overflow = 'hidden';
            setTimeout(() => { modal.querySelector('.modal-content').classList.add('show'); }, 10);
        }

        async function executeBulkClose() {
            const btn = document.getElementById('confirmBulkBtn');
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
            
            const fd = new FormData();
            
            if (closeMode === 'bulk') {
                fd.append('action', 'bulk_close');
            } else {
                fd.append('action', 'close_selected');
                document.querySelectorAll('.client-select-cb:checked').forEach(cb => {
                    fd.append('client_ids[]', cb.value);
                });
            }

            try {
                const res = await fetch('', { method:'POST', body:fd, headers:{'X-Requested-With':'XMLHttpRequest'}});
                const data = await res.json();

                if (data.success) {
                    showToast(data.message, 'success');
                    closeModal('bulkModal');
                    loadClients();
                } else {
                    showToast(data.error || 'Operation failed.', 'error');
                }
            } catch (err) {
                showToast('Network error occurred.', 'error');
            } finally {
                btn.disabled = false;
                btn.innerHTML = 'Confirm';
            }
        }

        // --- INITIALIZATION ---
        if(localStorage.getItem('theme') === 'dark') document.documentElement.classList.add('dark');
        document.getElementById('themeToggle').onclick = () => {
            document.documentElement.classList.toggle('dark');
            localStorage.setItem('theme', document.documentElement.classList.contains('dark') ? 'dark' : 'light');
        };

        // Load data on start
        document.addEventListener('DOMContentLoaded', loadClients);

    </script>
</body>
</html>