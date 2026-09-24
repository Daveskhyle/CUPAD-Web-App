<?php
// co/close_client.php - Adapted for CUPAD System
require_once __DIR__ . '/../includes/config.php';
session_start();

$pdo = getDbConnection();

// Ensure User is Logged In and is a CO
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'co') {
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

// ===== AJAX HANDLING =====
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
    header('Content-Type: application/json');
    $action = $_GET['action'] ?? $_POST['action'] ?? '';

    switch ($action) {
        case 'get_client_details':
            $client_id = $_GET['client_id'] ?? '';
            
            // Get client and their current balances
            $stmt = $pdo->prepare("
                SELECT c.id, c.name, c.`union`,
                    COALESCE((SELECT balance FROM saving_balances WHERE client_id = c.id LIMIT 1), 0) as total_savings,
                    COALESCE((SELECT SUM(remaining_balance) FROM disbursements WHERE client_id = c.id AND remaining_balance > 0), 0) as total_loan
                FROM clients c
                WHERE c.id = :cid AND c.officer_username = :uname AND c.status = 'active'
            ");
            $stmt->execute(['cid' => $client_id, 'uname' => $current_username]);
            $client = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($client) {
                echo json_encode(['success' => true, 'client' => $client]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Client not found or already closed.']);
            }
            exit();

        case 'close_client':
            $client_id = $_POST['client_id'] ?? '';
            
            if (empty($client_id)) {
                echo json_encode(['success' => false, 'error' => 'Client ID is required']);
                exit();
            }

            try {
                $pdo->beginTransaction();

                // 1. Lock the client row and double-check balances
                $stmt = $pdo->prepare("
                    SELECT 
                        COALESCE((SELECT balance FROM saving_balances WHERE client_id = ? LIMIT 1), 0) as total_savings,
                        COALESCE((SELECT SUM(remaining_balance) FROM disbursements WHERE client_id = ? AND remaining_balance > 0), 0) as total_loan
                    FROM clients 
                    WHERE id = ? AND officer_username = ? AND status = 'active' FOR UPDATE
                ");
                $stmt->execute([$client_id, $client_id, $client_id, $current_username]);
                $bal_res = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$bal_res) {
                    throw new Exception("Client not found or not authorized.");
                }

                $savings = (float)$bal_res['total_savings'];
                $loan = (float)$bal_res['total_loan'];

                // 2. Reject if balances aren't strictly zero
                if ($savings > 0 || $loan > 0) {
                    throw new Exception("Cannot close client. Outstanding balances detected (Savings: ₦" . number_format($savings, 2) . ", Loan: ₦" . number_format($loan, 2) . ").");
                }

                // 3. Mark client as inactive
                $update_stmt = $pdo->prepare("UPDATE clients SET status = 'inactive' WHERE id = ?");
                if (!$update_stmt->execute([$client_id])) {
                    throw new Exception("Failed to update client status.");
                }

                $pdo->commit();
                echo json_encode(['success' => true, 'message' => 'Client account successfully closed.']);

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

$full_name = $u_row['full_name'] ?? 'Officer';
$profile_pic = $u_row['profile_pic'] ?? 'default_avatar.png';
$page_title = "Close Client Account";

// FETCH ALL ACTIVE CLIENTS FOR THIS OFFICER
$stmt_clients = $pdo->prepare("SELECT id, name, `union`, phone FROM clients WHERE officer_username = ? AND status = 'active' ORDER BY name ASC");
$stmt_clients->execute([$current_username]);
$active_clients = $stmt_clients->fetchAll(PDO::FETCH_ASSOC);
$total_clients = count($active_clients);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title><?php echo htmlspecialchars($page_title); ?> | CUPAD</title>
    
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    
    <script>
    tailwind.config = {
        darkMode: 'class',
        theme: {
            extend: {
                colors: {
                    primary: '#f43f5e', // Rose/Red theme for closure
                    bg: { light: '#f0f2f5', dark: '#0f172a' }
                }
            }
        }
    }
    </script>

    <style>
        :root {
            --bg-primary: #f0f2f5; --bg-secondary: #ffffff; --bg-card: #ffffff;
            --text-primary: #0f172a; --text-secondary: #64748b; --border-color: #e2e8f0;
        }
        html.dark {
            --bg-primary: #0f172a; --bg-secondary: #1e293b; --bg-card: #1e293b;
            --text-primary: #f8fafc; --text-secondary: #94a3b8; --border-color: rgba(255, 255, 255, 0.08);
        }
        body { font-family: 'Inter', sans-serif; background-color: var(--bg-primary); color: var(--text-primary); padding-bottom: 90px; -webkit-tap-highlight-color: transparent; }
        
        /* HEADER STYLES */
        .main-header { background: rgba(255, 255, 255, 0.95); backdrop-filter: blur(12px); border-bottom: 1px solid var(--border-color); position: sticky; top: 0; z-index: 50; padding-top: env(safe-area-inset-top); }
        html.dark .main-header { background: rgba(15, 23, 42, 0.95); }
        .navbar { display: flex; justify-content: space-between; align-items: center; padding: 0.8rem 1rem; max-width: 1280px; margin: 0 auto; }
        .profile-btn { width: 36px; height: 36px; border-radius: 50%; overflow: hidden; border: 2px solid var(--primary); background: var(--bg-secondary); cursor: pointer; display: flex; align-items: center; justify-content: center; position: relative; }
        .profile-btn img { width: 100%; height: 100%; object-fit: cover; }
        .profile-fallback-icon { font-size: 1.2rem; color: var(--text-secondary); }

        /* RICH VISUALS */
        .hero-banner { background: linear-gradient(135deg, #f43f5e 0%, #be123c 100%); border-radius: 1.5rem; padding: 2rem; position: relative; overflow: hidden; color: white; box-shadow: 0 10px 25px -5px rgba(244, 63, 94, 0.4); margin-bottom: 1.5rem; }
        .hero-icon-bg { position: absolute; right: -20px; bottom: -30px; font-size: 8rem; opacity: 0.1; transform: rotate(-15deg); pointer-events: none; }
        
        .search-container { position: sticky; top: 60px; z-index: 30; background: rgba(240, 242, 245, 0.8); backdrop-filter: blur(12px); padding: 1rem 0; margin-bottom: 1rem; margin-top: -1rem; }
        html.dark .search-container { background: rgba(15, 23, 42, 0.8); }
        
        .input-field { width: 100%; padding: 1rem 1rem 1rem 3.5rem; border-radius: 1rem; border: 1px solid var(--border-color); background: var(--bg-card); color: var(--text-primary); font-size: 1rem; font-weight: 500; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); transition: all 0.2s; }
        .input-field:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 4px rgba(244, 63, 94, 0.15); transform: translateY(-1px); }

        .client-card { background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 1.25rem; padding: 1.25rem; transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1); box-shadow: 0 2px 4px rgba(0,0,0,0.02); display: flex; flex-direction: column; justify-content: space-between; cursor: pointer; position: relative; overflow: hidden; }
        .client-card:hover { transform: translateY(-4px) scale(1.01); box-shadow: 0 15px 25px -5px rgba(0, 0, 0, 0.1); border-color: rgba(244, 63, 94, 0.4); }
        .client-card::after { content: ''; position: absolute; top: 0; left: 0; width: 4px; height: 100%; background: var(--border-color); transition: background 0.3s; }
        .client-card:hover::after { background: var(--primary); }
        .client-card:active { transform: scale(0.98); }
        
        .avatar { width: 46px; height: 46px; border-radius: 12px; background: linear-gradient(135deg, rgba(244, 63, 94, 0.15), rgba(190, 18, 60, 0.15)); color: var(--primary); display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 1.2rem; flex-shrink: 0; }
        
        /* Modal Styles */
        #modalBackdrop { backdrop-filter: blur(6px); transition: opacity 0.3s ease; }
        #modalContent { transition: all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275); transform: scale(0.9); opacity: 0; }
        #modalContent.show { transform: scale(1); opacity: 1; }

        #toast-container { position: fixed; top: 1rem; left: 50%; transform: translateX(-50%); z-index: 999; width: 90%; max-width: 350px; pointer-events: none; }
        .toast { background: var(--bg-secondary); border-left: 4px solid; padding: 1rem; border-radius: 12px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1); margin-bottom: 0.5rem; display: flex; align-items: center; gap: 10px; animation: slideDown 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275); pointer-events: auto; font-weight: 600; }
        @keyframes slideDown { from { transform: translateY(-30px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
        
        .loader { border: 3px solid var(--border-color); border-top: 3px solid var(--primary); border-radius: 50%; width: 28px; height: 28px; animation: spin 1s linear infinite; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }

        /* Mobile Bottom Nav */
        .mobile-bottom-nav { display: none; position: fixed; bottom: 0; left: 0; right: 0; background: var(--bg-secondary); border-top: 1px solid var(--border-color); padding: 0.5rem 1rem calc(0.5rem + env(safe-area-inset-bottom)); z-index: 100; justify-content: space-between; box-shadow: 0 -4px 15px rgba(0,0,0,0.05); }
        .nav-item { display: flex; flex-direction: column; align-items: center; justify-content: center; text-decoration: none; color: var(--text-secondary); font-size: 0.7rem; font-weight: 600; gap: 4px; flex: 1; transition: color 0.2s; }
        .nav-item i { font-size: 1.4rem; }
        .nav-item.active { color: #3b82f6; /* Dashboard primary blue */ }
        @media (max-width: 768px) { .mobile-bottom-nav { display: flex; } }
    </style>
</head>
<body>

    <div id="toast-container"></div>

    <!-- HEADER (Matches Dashboard) -->
    <header class="main-header">
        <nav class="navbar">
            <a href="dashboard.php" class="flex items-center gap-2 no-underline">
                <img src="<?php echo htmlspecialchars($base_path . 'favicon.ico'); ?>" alt="Logo" class="h-8">
                <span class="text-xl font-extrabold text-rose-600 tracking-tight">CUPAD</span>
            </a>
            
            <div class="flex items-center gap-3">
                <button id="themeToggle" class="p-2 text-gray-500 dark:text-gray-400 rounded-full hover:bg-gray-100 dark:hover:bg-gray-800 transition">
                    <i class="fas fa-moon dark:hidden"></i><i class="fas fa-sun hidden dark:inline"></i>
                </button>
                
                <div class="profile-btn" onclick="window.location.href='profile.php'">
                    <i class="fas fa-user profile-fallback-icon"></i>
                    <?php if (isset($profile_pic) && $profile_pic !== 'default_avatar.png'): ?>
                        <img src="<?php echo $base_path . $profile_pic; ?>" alt="Profile" class="absolute inset-0" onerror="this.style.display='none'"> 
                    <?php endif; ?>
                </div>

                <a href="<?php echo htmlspecialchars($base_path . 'logout.php'); ?>" class="p-2 text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-full transition" title="Logout">
                    <i class="fas fa-sign-out-alt"></i>
                </a>
            </div>
        </nav>
    </header>

    <main class="max-w-6xl mx-auto px-4 py-6">
        
        <!-- RICH HERO BANNER -->
        <div class="hero-banner">
            <i class="fas fa-user-times hero-icon-bg"></i>
            <div class="flex justify-between items-center relative z-10">
                <div>
                    <h1 class="text-2xl md:text-3xl font-extrabold mb-1 drop-shadow-md">Close Account</h1>
                    <p class="text-rose-100 text-sm font-medium opacity-90">Select an active client to permanently deactivate their account.</p>
                </div>
                <div class="bg-white/20 backdrop-blur-md px-4 py-3 rounded-2xl border border-white/30 text-center shadow-inner hidden sm:block">
                    <div class="text-3xl font-black leading-none drop-shadow-md"><?php echo $total_clients; ?></div>
                    <div class="text-[10px] uppercase tracking-wider font-bold text-rose-100 mt-1">Active Clients</div>
                </div>
            </div>
        </div>

        <!-- STICKY SEARCH BAR -->
        <div class="search-container">
            <div class="relative max-w-2xl mx-auto">
                <i class="fas fa-search absolute left-5 top-1/2 transform -translate-y-1/2 text-rose-400 text-lg"></i>
                <input type="text" id="searchInput" placeholder="Search by name, union, or phone..." autocomplete="off" class="input-field">
            </div>
        </div>

        <!-- CLIENTS GRID -->
        <?php if ($total_clients > 0): ?>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5" id="clientGrid">
                <?php foreach ($active_clients as $c): 
                    $initials = strtoupper(substr($c['name'], 0, 1));
                    $searchData = strtolower($c['name'] . ' ' . $c['union'] . ' ' . $c['phone']);
                ?>
                <div class="client-card" data-search="<?php echo htmlspecialchars($searchData); ?>" onclick="openCloseModal('<?php echo $c['id']; ?>', '<?php echo htmlspecialchars(addslashes($c['name'])); ?>', '<?php echo htmlspecialchars(addslashes($c['union'])); ?>')">
                    <div class="flex items-start gap-4 mb-4">
                        <div class="avatar"><?php echo $initials; ?></div>
                        <div class="flex-1 min-w-0 pt-1">
                            <h3 class="font-extrabold text-gray-900 dark:text-white truncate text-base"><?php echo htmlspecialchars($c['name']); ?></h3>
                            <div class="flex items-center mt-1.5">
                                <span class="bg-blue-50 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300 text-[10px] uppercase font-bold px-2 py-0.5 rounded-md border border-blue-200 dark:border-blue-800 truncate">
                                    <i class="fas fa-users mr-1 opacity-70"></i> <?php echo htmlspecialchars($c['union']); ?>
                                </span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="flex justify-between items-center text-sm border-t border-gray-100 dark:border-gray-800 pt-3">
                        <span class="text-gray-500 dark:text-gray-400 font-medium"><i class="fas fa-phone-alt text-xs mr-1 opacity-70"></i> <?php echo htmlspecialchars($c['phone']); ?></span>
                        <span class="text-rose-500 font-bold flex items-center gap-1 group-hover:translate-x-1 transition-transform">
                            Review <i class="fas fa-arrow-right text-xs"></i>
                        </span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- EMPTY STATE FOR SEARCH -->
            <div id="noResults" class="hidden text-center py-16">
                <div class="w-24 h-24 bg-gray-100 dark:bg-gray-800 rounded-full flex items-center justify-center mx-auto mb-5 text-gray-400 text-4xl shadow-inner">
                    <i class="fas fa-search-minus"></i>
                </div>
                <h3 class="text-xl font-bold text-gray-900 dark:text-white mb-2">No matching clients</h3>
                <p class="text-sm text-gray-500 font-medium">Try adjusting your search term.</p>
            </div>
        <?php else: ?>
            <!-- GLOBAL EMPTY STATE -->
            <div class="text-center py-20 bg-white dark:bg-gray-800 rounded-3xl border-2 border-dashed border-gray-200 dark:border-gray-700 shadow-sm">
                <div class="w-24 h-24 bg-gray-50 dark:bg-gray-900 rounded-full flex items-center justify-center mx-auto mb-5 text-gray-400 text-4xl shadow-inner">
                    <i class="fas fa-users-slash"></i>
                </div>
                <h3 class="text-xl font-bold text-gray-900 dark:text-white mb-2">No Active Clients</h3>
                <p class="text-sm text-gray-500 font-medium">You do not have any active clients assigned to you.</p>
            </div>
        <?php endif; ?>

    </main>

    <!-- CONFIRMATION MODAL -->
    <div id="closeModal" class="fixed inset-0 z-[60] hidden flex items-center justify-center p-4">
        <!-- Backdrop -->
        <div id="modalBackdrop" class="absolute inset-0 bg-gray-900/60" onclick="closeModal()"></div>
        
        <!-- Modal Content -->
        <div id="modalContent" class="relative bg-white dark:bg-gray-800 w-full max-w-md rounded-3xl shadow-2xl overflow-hidden border border-gray-200 dark:border-gray-700 flex flex-col max-h-[90vh]">
            
            <!-- Header -->
            <div class="bg-gray-50 dark:bg-gray-900/80 backdrop-blur p-4 border-b border-gray-200 dark:border-gray-700 flex justify-between items-center">
                <h3 class="font-bold text-gray-900 dark:text-white flex items-center gap-2 text-lg">
                    <span class="w-8 h-8 rounded-full bg-rose-100 dark:bg-rose-900/30 text-rose-600 flex items-center justify-center text-sm shadow-inner"><i class="fas fa-shield-alt"></i></span>
                    Closure Review
                </h3>
                <button onclick="closeModal()" class="text-gray-400 hover:text-gray-800 dark:hover:text-gray-200 w-8 h-8 flex items-center justify-center rounded-full hover:bg-gray-200 dark:hover:bg-gray-700 transition">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <div class="p-6 overflow-y-auto">
                <!-- Client Info -->
                <div class="text-center mb-6">
                    <h2 class="text-2xl font-black text-gray-900 dark:text-white tracking-tight" id="modalClientName">Client Name</h2>
                    <p class="inline-flex items-center justify-center gap-1.5 px-3 py-1 mt-2 bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 rounded-lg text-xs font-bold uppercase tracking-wider" id="modalClientUnion">
                        <i class="fas fa-users text-rose-500"></i> Union Name
                    </p>
                </div>

                <!-- Loading State -->
                <div id="modalLoading" class="py-10 flex flex-col items-center justify-center">
                    <div class="loader mb-4"></div>
                    <p class="text-sm font-bold text-gray-500 animate-pulse">Verifying ledger balances...</p>
                </div>

                <!-- Content State (Hidden initially) -->
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

                    <!-- Warning Panel -->
                    <div id="closureWarning" class="hidden p-4 rounded-2xl bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800/30 text-red-700 dark:text-red-400 text-sm mb-4 shadow-sm">
                        <div class="flex gap-4 items-start">
                            <div class="w-8 h-8 rounded-full bg-red-100 dark:bg-red-900/50 flex items-center justify-center flex-shrink-0 mt-0.5">
                                <i class="fas fa-lock text-red-500"></i>
                            </div>
                            <div>
                                <strong class="block mb-1 text-base text-red-800 dark:text-red-300">Closure Blocked</strong>
                                <span class="opacity-90">This account cannot be closed. Outstanding balances must be settled to zero first.</span>
                            </div>
                        </div>
                    </div>

                    <!-- Success Panel -->
                    <div id="closureReady" class="hidden p-4 rounded-2xl bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-800/30 text-emerald-700 dark:text-emerald-400 text-sm mb-4 shadow-sm">
                        <div class="flex gap-4 items-start">
                            <div class="w-8 h-8 rounded-full bg-emerald-100 dark:bg-emerald-900/50 flex items-center justify-center flex-shrink-0 mt-0.5">
                                <i class="fas fa-unlock text-emerald-500"></i>
                            </div>
                            <div>
                                <strong class="block mb-1 text-base text-emerald-800 dark:text-emerald-300">Ready to Close</strong>
                                <span class="opacity-90">Ledgers are clear. You can safely deactivate this account.</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Footer Action -->
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

    <!-- MOBILE BOTTOM NAVIGATION -->
    <nav class="mobile-bottom-nav">
        <a href="dashboard.php" class="nav-item">
            <i class="fas fa-home"></i>
            <span>Home</span>
        </a>
        <a href="combined_collection.php" class="nav-item">
            <i class="fas fa-coins"></i>
            <span>Collect</span>
        </a>
        <a href="disbursement.php" class="nav-item">
             <i class="fas fa-hand-holding-usd"></i>
             <span>Disburse</span>
        </a>
        <a href="clients.php" class="nav-item">
            <i class="fas fa-users"></i>
            <span>Clients</span>
        </a>
        <a href="close_client.php" class="nav-item active">
             <div style="background: var(--primary); width: 45px; height: 45px; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; margin-top: -25px; box-shadow: 0 4px 10px rgba(244, 63, 94, 0.4);">
                <i class="fas fa-user-times" style="font-size: 1.2rem; margin:0;"></i>
             </div>
             <span style="font-size:0.65rem; font-weight:600; margin-top:2px;">Close</span>
        </a>
    </nav>

    <script>
        // --- UI UTILS ---
        function showToast(msg, type) {
            const el = document.createElement('div');
            el.className = 'toast';
            el.style.borderLeftColor = type === 'success' ? '#10b981' : '#f43f5e';
            el.innerHTML = `<i class="fas ${type==='success'?'fa-check-circle text-emerald-500':'fa-exclamation-circle text-rose-500'}"></i> <span class="text-sm">${msg}</span>`;
            document.getElementById('toast-container').appendChild(el);
            setTimeout(() => { el.style.opacity = '0'; el.style.transform = 'translateY(-20px)'; setTimeout(() => el.remove(), 300); }, 3000);
        }

        // --- SEARCH FILTERING ---
        const searchInput = document.getElementById('searchInput');
        if (searchInput) {
            searchInput.addEventListener('input', function(e) {
                const term = e.target.value.toLowerCase();
                const cards = document.querySelectorAll('.client-card');
                let visibleCount = 0;

                cards.forEach(card => {
                    const searchData = card.getAttribute('data-search');
                    if (searchData.includes(term)) {
                        card.style.display = 'flex';
                        visibleCount++;
                    } else {
                        card.style.display = 'none';
                    }
                });

                const noResults = document.getElementById('noResults');
                if (noResults) {
                    noResults.style.display = visibleCount === 0 && term !== '' ? 'block' : 'none';
                }
            });
        }

        // --- MODAL LOGIC ---
        const modal = document.getElementById('closeModal');
        const modalContent = document.getElementById('modalContent');
        const body = document.body;

        function openCloseModal(id, name, union) {
            // Set basic info
            document.getElementById('selectedClientId').value = id;
            document.getElementById('modalClientName').textContent = name;
            document.getElementById('modalClientUnion').innerHTML = `<i class="fas fa-users text-rose-500"></i> ${union}`;
            
            // Reset state
            document.getElementById('modalLoading').classList.remove('hidden');
            document.getElementById('modalData').classList.add('hidden');
            document.getElementById('closureWarning').classList.add('hidden');
            document.getElementById('closureReady').classList.add('hidden');
            document.getElementById('closeBtn').disabled = true;

            // Show modal
            modal.classList.remove('hidden');
            body.style.overflow = 'hidden';
            // slight delay for animation
            setTimeout(() => { modalContent.classList.add('show'); }, 10);

            // Fetch balances
            fetchClientDetails(id);
        }

        function closeModal() {
            modalContent.classList.remove('show');
            setTimeout(() => {
                modal.classList.add('hidden');
                body.style.overflow = '';
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

                    // Color text based on balance
                    lblSavings.className = `text-2xl font-black tracking-tight ${savings > 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-gray-900 dark:text-white'}`;
                    lblLoan.className = `text-2xl font-black tracking-tight ${loan > 0 ? 'text-rose-600 dark:text-rose-400' : 'text-gray-900 dark:text-white'}`;

                    if (savings > 0 || loan > 0) {
                        document.getElementById('closureWarning').classList.remove('hidden');
                        document.getElementById('closeBtn').disabled = true;
                    } else {
                        document.getElementById('closureReady').classList.remove('hidden');
                        document.getElementById('closeBtn').disabled = false;
                    }
                } else {
                    showToast(data.error, 'error');
                    closeModal();
                }
            } catch (err) {
                showToast('Failed to load balances.', 'error');
                closeModal();
            }
        }

        // --- SUBMIT CLOSURE ---
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
                    // Remove card from UI instantly without refresh
                    const id = fd.get('client_id');
                    const card = document.querySelector(`.client-card[onclick*="'${id}'"]`);
                    if(card) {
                        card.style.opacity = '0';
                        card.style.transform = 'scale(0.9)';
                        setTimeout(() => card.remove(), 300);
                    }
                    
                    // Update counter
                    const counterElem = document.querySelector('.bg-white\\/20 .text-3xl');
                    if (counterElem) {
                        let currentCount = parseInt(counterElem.textContent);
                        if(currentCount > 0) counterElem.textContent = currentCount - 1;
                    }

                    closeModal();
                } else {
                    showToast(data.error || 'Failed to close account', 'error');
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-user-slash"></i> Confirm Deactivation';
                }
            } catch (err) {
                showToast('Network error occurred.', 'error');
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-user-slash"></i> Confirm Deactivation';
            }
        });

        // Theme initialization
        if(localStorage.getItem('theme') === 'dark') document.documentElement.classList.add('dark');
        document.getElementById('themeToggle').onclick = () => {
            document.documentElement.classList.toggle('dark');
            localStorage.setItem('theme', document.documentElement.classList.contains('dark') ? 'dark' : 'light');
        };
    </script>
</body>
</html>