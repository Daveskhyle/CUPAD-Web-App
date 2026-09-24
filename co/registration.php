<?php
// co/registration.php - MySQL Version (Integrated with new Settings Tables & Balance Checks)
require_once __DIR__ . '/../includes/config.php';
session_start();

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    die("Database Connection Failed: " . $conn->connect_error);
}

// Security check
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'co') {
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Unauthorized access']);
        exit();
    }
    header('Location: ../index.php');
    exit();
}

$base_path = '../';
$current_username = $_SESSION['username'] ?? '';

// --- HELPER FUNCTIONS ---

function generate_uuid() {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

// --- SETTINGS LOADING (From New Tables) ---

// 1. Load General Fee Defaults from app_settings
$fee_daily = 1000.00;
$fee_weekly = 2000.00;
$fee_monthly = 2500.00;

$as_query = $conn->query("SELECT * FROM app_settings LIMIT 1");
if ($as_query && $row = $as_query->fetch_assoc()) {
    $fee_daily = (float)$row['client_registration_fee'];
    $fee_weekly = (float)$row['weekly_registration_fee'];
    $fee_monthly = (float)$row['monthly_registration_fee'];
}

// 2. Load Loan Plans from loan_plans table
$loan_plans = [];
$lp_query = $conn->query("SELECT * FROM loan_plans ORDER BY unit ASC, duration ASC");
if ($lp_query) {
    while ($row = $lp_query->fetch_assoc()) {
        $loan_plans[] = $row;
    }
}

// --- DATA PREPARATION ---

// 1. Get Branch Name
$branch_id = $_SESSION['branch'] ?? '';
$branch_name_display = 'Unknown Branch';
if (!empty($branch_id)) {
    $stmt = $conn->prepare("SELECT name FROM branches WHERE id = ?");
    $stmt->bind_param("s", $branch_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($b = $res->fetch_assoc()) {
        $branch_name_display = $b['name'];
    }
}

// 2. GET ASSIGNED UNIONS (RESTRICTED LOGIC)
$assigned_unions = [];

// Source A: Official Assignments (Table: assignments)
$stmt = $conn->prepare("SELECT `union` FROM assignments WHERE co = ? AND status = 'active'");
$stmt->bind_param("s", $current_username);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    if (!empty($row['union'])) {
        $assigned_unions[] = $row['union'];
    }
}

// Source B: Existing Client Unions (Table: clients)
$stmt = $conn->prepare("SELECT DISTINCT `union` FROM clients WHERE officer_username = ?");
$stmt->bind_param("s", $current_username);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    if (!empty($row['union'])) {
        $assigned_unions[] = $row['union'];
    }
}

$assigned_unions = array_unique($assigned_unions);
sort($assigned_unions);

// ===== AJAX HANDLING =====
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
    header('Content-Type: application/json');
    $action = $_GET['action'] ?? $_POST['action'] ?? '';
    
    switch ($action) {
        case 'check_duplicate':
            $check_name = trim($_GET['name'] ?? '');
            $check_union = trim($_GET['union'] ?? '');
            $exists = false; 
            $has_funds = false;
            $details = null;
            
            if ($check_name !== '') {
                // Check for active duplicate AND pull their balances
                $stmt = $conn->prepare("
                    SELECT c.id, c.`union`,
                        COALESCE((SELECT balance FROM saving_balances WHERE client_id = c.id LIMIT 1), 0) as total_savings,
                        COALESCE((SELECT SUM(remaining_balance) FROM disbursements WHERE client_id = c.id AND status != 'completed' AND remaining_balance > 0), 0) as total_loan
                    FROM clients c
                    WHERE c.name = ? AND c.officer_username = ? AND c.status = 'active'
                ");
                $stmt->bind_param("ss", $check_name, $current_username);
                $stmt->execute();
                $res = $stmt->get_result();
                
                if ($row = $res->fetch_assoc()) {
                    if ($check_union === '' || $row['union'] === $check_union) {
                        $exists = true;
                        $details = ['union' => $row['union']];
                        
                        $savings = (float)$row['total_savings'];
                        $loan = (float)$row['total_loan'];
                        
                        if ($savings > 0 || $loan > 0) {
                            $has_funds = true;
                            $details['savings'] = $savings;
                            $details['loan'] = $loan;
                        }
                    }
                }
            }
            echo json_encode(['success' => true, 'exists' => $exists, 'has_funds' => $has_funds, 'details' => $details]);
            exit();
            
        case 'get_stats':
            $stmt = $conn->prepare("SELECT COUNT(*) as total FROM clients WHERE officer_username = ? AND status = 'active'");
            $stmt->bind_param("s", $current_username);
            $stmt->execute();
            $total_clients = $stmt->get_result()->fetch_assoc()['total'];
            
            $stmt = $conn->prepare("SELECT SUM(amount) as total FROM registrations WHERE officer = ?");
            $stmt->bind_param("s", $current_username);
            $stmt->execute();
            $total_fees = floatval($stmt->get_result()->fetch_assoc()['total'] ?? 0);
            
            echo json_encode(['success' => true, 'stats' => [
                'total_clients' => $total_clients,
                'total_registration_fees' => $total_fees
            ]]);
            exit();
            
        case 'add_client':
            $name = ucwords(strtolower(trim($_POST['name'] ?? '')));
            $phone = preg_replace('/[^0-9]/', '', $_POST['phone'] ?? ''); 
            if ($phone && strlen($phone) === 10 && $phone[0] !== '0') $phone = '0'.$phone;
            
            $address = trim($_POST['address'] ?? '');
            $g_name = ucwords(strtolower(trim($_POST['guarantor_name'] ?? '')));
            $g_phone = preg_replace('/[^0-9]/', '', trim($_POST['guarantor_phone'] ?? ''));
            $union = trim($_POST['union'] ?? '');
            $branch = $branch_id;
            $plan_id = trim($_POST['plan_id'] ?? '');

            if (empty($name)) { echo json_encode(['success'=>false, 'error'=>'Name required']); exit; }
            if (empty($union) || !in_array($union, $assigned_unions)) { echo json_encode(['success'=>false, 'error'=>'Invalid Union']); exit; }
            if (empty($plan_id)) { echo json_encode(['success'=>false, 'error'=>'Please select a Loan Plan']); exit; }

            // === FETCH PLAN DATA FROM DB ===
            $p_stmt = $conn->prepare("SELECT * FROM loan_plans WHERE id = ?");
            $p_stmt->bind_param("s", $plan_id);
            $p_stmt->execute();
            $p_res = $p_stmt->get_result();
            $plan_data = $p_res->fetch_assoc();

            if (!$plan_data) {
                echo json_encode(['success'=>false, 'error'=>'Plan not found']); exit;
            }

            $type = $plan_data['duration'] . ' ' . ucfirst($plan_data['unit']);
            $registration_amount = (float)$plan_data['registration_amount'];

            // Start Transaction
            $conn->begin_transaction();

            try {
                // Ensure branch is present
                if (empty($branch)) {
                    $ustmt = $conn->prepare("SELECT branch_id FROM users WHERE username = ? LIMIT 1");
                    $ustmt->bind_param("s", $current_username);
                    $ustmt->execute();
                    $ures = $ustmt->get_result();
                    if ($ur = $ures->fetch_assoc()) { $branch = $ur['branch_id'] ?? ''; }
                }

                // 1. Handle Existing Client (Check Balances before Auto-Closing)
                $stmt = $conn->prepare("SELECT id FROM clients WHERE name = ? AND officer_username = ? AND `union` = ? AND status = 'active' FOR UPDATE");
                $stmt->bind_param("sss", $name, $current_username, $union);
                $stmt->execute();
                $existing_res = $stmt->get_result();
                
                if ($existing_res->num_rows > 0) {
                    $old_client = $existing_res->fetch_assoc();
                    $old_client_id = $old_client['id'];

                    // Double-Check Balances before allowing closure
                    $bal_stmt = $conn->prepare("
                        SELECT 
                            COALESCE((SELECT balance FROM saving_balances WHERE client_id = ? LIMIT 1), 0) as total_savings,
                            COALESCE((SELECT SUM(remaining_balance) FROM disbursements WHERE client_id = ? AND status != 'completed' AND remaining_balance > 0), 0) as total_loan
                    ");
                    $bal_stmt->bind_param("ss", $old_client_id, $old_client_id);
                    $bal_stmt->execute();
                    $bal_res = $bal_stmt->get_result()->fetch_assoc();

                    $old_savings = (float)$bal_res['total_savings'];
                    $old_loan = (float)$bal_res['total_loan'];

                    if ($old_savings > 0 || $old_loan > 0) {
                        throw new Exception("Blocked: Client has unsettled balances (Savings: ₦" . number_format($old_savings, 2) . ", Loan: ₦" . number_format($old_loan, 2) . "). Please settle before registration.");
                    }

                    // Update status to 'inactive' if balances are zero
                    $update_stmt = $conn->prepare("UPDATE clients SET status = 'inactive' WHERE id = ?");
                    $update_stmt->bind_param("s", $old_client_id);
                    if (!$update_stmt->execute()) {
                        throw new Exception("Failed to close previous active client record.");
                    }
                }

                // 2. Insert Client
                $client_uuid = generate_uuid();
                $reg_date = date('Y-m-d');
                
                $stmt = $conn->prepare("INSERT INTO clients (id, name, phone, address, branch_id, `union`, client_type, plan_id, officer_username, guarantor_name, guarantor_phone, date_registered, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')");
                $stmt->bind_param("ssssssssssss", $client_uuid, $name, $phone, $address, $branch, $union, $type, $plan_id, $current_username, $g_name, $g_phone, $reg_date);

                if (!$stmt->execute()) {
                    throw new Exception("Failed to create client record: " . $conn->error);
                }

                // 3. Initialize Savings Balance
                $stmt = $conn->prepare("INSERT INTO saving_balances (client_id, balance) VALUES (?, 0.00)");
                $stmt->bind_param("s", $client_uuid);
                if (!$stmt->execute()) {
                    throw new Exception("Failed to initialize savings.");
                }

                // 4. Record Registration Fee
                $date_time = date('Y-m-d H:i:s');
                $stmt = $conn->prepare("INSERT INTO registrations (client_id, client_name, amount, `union`, officer, date) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("ssdsss", $client_uuid, $name, $registration_amount, $union, $current_username, $date_time);
                
                if (!$stmt->execute()) {
                    throw new Exception("Failed to record registration fee");
                }

                $conn->commit();
                echo json_encode(['success'=>true, 'message'=>'Client Registered Successfully!']);

            } catch (Exception $e) {
                $conn->rollback();
                echo json_encode(['success'=>false, 'error'=>$e->getMessage()]);
            }
            exit();
    }
}

// User Profile for UI
$stmt = $conn->prepare("SELECT full_name, profile_pic FROM users WHERE username = ?");
$stmt->bind_param("s", $current_username);
$stmt->execute();
$u_res = $stmt->get_result();
if ($u_row = $u_res->fetch_assoc()) {
    $full_name = $u_row['full_name'];
    $profile_pic = $u_row['profile_pic'] ?? 'default_avatar.png';
} else {
    $full_name = 'Officer';
    $profile_pic = 'default_avatar.png';
}

$page_title = "Client Registration";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#4f46e5">
    <title><?php echo htmlspecialchars($page_title); ?> | CUPAD</title>
    
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    
    <script>
    tailwind.config = {
        darkMode: 'class',
        theme: {
            extend: {
                colors: {
                    primary: '#4f46e5',
                    secondary: '#8b5cf6',
                    success: '#22c55e',
                    warning: '#f59e0b',
                    error: '#ef4444',
                    bg: { light: '#f0f2f5', dark: '#0f172a' }
                },
                animation: {
                    'bounce-slow': 'bounce 3s infinite',
                }
            }
        }
    }
    </script>

    <style>
        :root {
            --primary-color: #4f46e5; --secondary-color: #8b5cf6;
            --success-color: #22c55e; --warning-color: #f59e0b; --error-color: #ef4444;
            --bg-primary: #f0f2f5; --bg-secondary: #ffffff; --bg-card: #ffffff;
            --text-primary: #1f2937; --text-secondary: #6b7280; --border-color: #e5e7eb;
            --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
            --border-radius-lg: 1rem; --border-radius-xl: 1.5rem;
        }
        html.dark {
            --bg-primary: #0f172a; --bg-secondary: #1e293b; --bg-card: #1e293b;
            --text-primary: #f1f5f9; --text-secondary: #94a3b8; --border-color: rgba(255, 255, 255, 0.08);
        }
        body { font-family: 'Inter', sans-serif; background-color: var(--bg-primary); color: var(--text-primary); padding-bottom: 90px; -webkit-tap-highlight-color: transparent; }
        .main-header { background: rgba(255, 255, 255, 0.95); backdrop-filter: blur(12px); border-bottom: 1px solid var(--border-color); position: sticky; top: 0; z-index: 50; padding-top: env(safe-area-inset-top); }
        html.dark .main-header { background: rgba(15, 23, 42, 0.95); }
        .navbar { display: flex; justify-content: space-between; align-items: center; padding: 0.8rem 1rem; max-width: 1280px; margin: 0 auto; }
        .dashboard-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1rem; margin-bottom: 1.5rem; }
        .dashboard-card { border-radius: var(--border-radius-xl); padding: 1.5rem; position: relative; overflow: hidden; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1); color: white; transition: transform 0.2s ease; border: none; }
        .dashboard-card:active { transform: scale(0.98); }
        .card-gradient-indigo { background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%); }
        .card-gradient-amber { background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); }
        .card-gradient-emerald { background: linear-gradient(135deg, #10b981 0%, #059669 100%); }
        .card-bg-icon { position: absolute; right: -10px; bottom: -20px; font-size: 6rem; color: white; opacity: 0.1; transform: rotate(-10deg); pointer-events: none; }
        .form-card { background: var(--bg-card); border-radius: var(--border-radius-xl); border: 1px solid var(--border-color); overflow: hidden; box-shadow: var(--shadow-sm); }
        .form-header { padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--border-color); background: var(--bg-primary); display: flex; justify-content: space-between; align-items: center; }
        .input-group { position: relative; margin-bottom: 1.25rem; }
        .input-label { display: block; font-size: 0.75rem; font-weight: 700; color: var(--text-secondary); text-transform: uppercase; margin-bottom: 0.4rem; letter-spacing: 0.05em; }
        .input-field { width: 100%; padding: 0.85rem 1rem 0.85rem 2.8rem; border-radius: 0.75rem; border: 1px solid var(--border-color); background: var(--bg-primary); color: var(--text-primary); font-size: 0.95rem; transition: all 0.2s; appearance: none; }
        .input-field:focus { outline: none; border-color: var(--primary-color); box-shadow: 0 0 0 2px rgba(99, 102, 241, 0.2); background: var(--bg-card); }
        .input-field option { padding: 0.75rem 1rem; font-size: 0.9rem; background: var(--bg-card); color: var(--text-primary); }
        .input-field optgroup { font-weight: 600; color: white; background: var(--primary-color); padding: 0.5rem 1rem; border-radius: 0.25rem; margin: 0.25rem 0; }
        .input-icon { position: absolute; left: 1rem; top: 2.7rem; color: var(--text-secondary); pointer-events: none; font-size: 1rem; }
        .capitalize-input { text-transform: capitalize; }
        .field-valid { border-color: var(--success-color) !important; background-color: rgba(34, 197, 94, 0.05) !important; }
        .field-invalid { border-color: var(--error-color) !important; background-color: rgba(239, 68, 68, 0.05) !important; }
        .validation-message { font-size: 0.75rem; font-weight: 600; margin-top: 0.25rem; height: 0; overflow: hidden; transition: all 0.3s; opacity: 0; }
        .validation-message.show { height: auto; opacity: 1; padding-top: 0.25rem; }
        .btn-primary { background: var(--primary-color); color: white; width: 100%; padding: 0.9rem; border-radius: 0.75rem; font-weight: 700; border: none; cursor: pointer; transition: transform 0.1s, box-shadow 0.2s; box-shadow: 0 4px 6px -1px rgba(79, 70, 229, 0.3); display: flex; align-items: center; justify-content: center; gap: 0.5rem; }
        .btn-primary:active { transform: scale(0.98); }
        #success-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.6); backdrop-filter: blur(8px); z-index: 200; display: flex; align-items: center; justify-content: center; opacity: 0; pointer-events: none; transition: opacity 0.3s ease; }
        #success-overlay.active { opacity: 1; pointer-events: auto; }
        .success-card { background: var(--bg-card); border-radius: 1.5rem; padding: 2rem; text-align: center; transform: scale(0.9); transition: transform 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275); max-width: 90%; width: 400px; border: 1px solid var(--border-color); box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04); }
        .mobile-bottom-nav { display: none; position: fixed; bottom: 0; left: 0; right: 0; background: var(--bg-secondary); border-top: 1px solid var(--border-color); padding: 0.5rem 1rem calc(0.5rem + env(safe-area-inset-bottom)); z-index: 100; justify-content: space-between; box-shadow: 0 -4px 10px rgba(0,0,0,0.05); }
        .nav-item { display: flex; flex-direction: column; align-items: center; justify-content: center; text-decoration: none; color: var(--text-secondary); font-size: 0.7rem; font-weight: 500; gap: 4px; flex: 1; }
        .nav-item i { font-size: 1.4rem; }
        .nav-item.active { color: var(--primary-color); }
        @media (max-width: 768px) {
            .mobile-bottom-nav { display: flex; }
            .dashboard-grid { display: flex; overflow-x: auto; scroll-snap-type: x mandatory; gap: 1rem; padding-bottom: 0.5rem; margin-right: -1rem; padding-right: 1.5rem; scrollbar-width: none; }
            .dashboard-grid::-webkit-scrollbar { display: none; }
            .dashboard-card { min-width: 85vw; scroll-snap-align: center; flex-shrink: 0; }
        }
        #toast-container { position: fixed; top: 1rem; left: 50%; transform: translateX(-50%); z-index: 999; width: 90%; max-width: 350px; pointer-events: none; }
        .toast { background: var(--bg-secondary); border-left: 4px solid; padding: 1rem; border-radius: 8px; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); margin-bottom: 0.5rem; display: flex; align-items: center; gap: 10px; animation: slideDown 0.3s ease; pointer-events: auto; }
        @keyframes slideDown { from { transform: translateY(-20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
        .profile-btn { width: 36px; height: 36px; border-radius: 50%; overflow: hidden; border: 2px solid var(--primary-color); background: var(--bg-secondary); cursor: pointer; display: flex; align-items: center; justify-content: center; position: relative; }
        .profile-btn img { width: 100%; height: 100%; object-fit: cover; }
    </style>
</head>
<body>

    <div id="toast-container"></div>

    <div id="success-overlay">
        <div class="success-card">
            <div class="w-16 h-16 bg-emerald-100 dark:bg-emerald-900/30 rounded-full flex items-center justify-center mx-auto mb-4 animate-bounce-slow">
                <i class="fas fa-check text-2xl text-emerald-600 dark:text-emerald-400"></i>
            </div>
            <h3 class="text-xl font-bold text-gray-900 dark:text-white mb-2">Registration Complete!</h3>
            <p class="text-gray-500 dark:text-gray-400 mb-6 text-sm">Client has been added successfully.</p>
            <div class="flex gap-3 justify-center">
                <button onclick="window.location.reload()" class="px-5 py-2.5 bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200 rounded-xl font-medium hover:bg-gray-200 dark:hover:bg-gray-600 transition">
                    Add Another
                </button>
                <a href="dashboard.php" class="px-5 py-2.5 bg-indigo-600 text-white rounded-xl font-medium hover:bg-indigo-700 transition shadow-lg shadow-indigo-500/30">
                    Dashboard
                </a>
            </div>
        </div>
    </div>

    <!-- HEADER -->
    <header class="main-header">
        <nav class="navbar">
            <a href="dashboard.php" class="flex items-center gap-2 no-underline">
                <img src="<?php echo htmlspecialchars($base_path . 'favicon.ico'); ?>" alt="Logo" class="h-8">
                <span class="text-xl font-extrabold text-blue-600 tracking-tight">CUPAD</span>
            </a>
            
            <div class="flex items-center gap-3">
                <button id="themeToggle" class="p-2 text-gray-500 dark:text-gray-400">
                    <i class="fas fa-moon dark:hidden"></i><i class="fas fa-sun hidden dark:inline"></i>
                </button>
                
                <div class="profile-btn" onclick="window.location.href='profile.php'">
                    <i class="fas fa-user profile-fallback-icon"></i>
                    <?php if ($profile_pic && $profile_pic !== 'default_avatar.png'): ?>
                        <img src="<?php echo $base_path . $profile_pic; ?>" alt="Profile" class="absolute inset-0" onerror="this.style.display='none'"> 
                    <?php endif; ?>
                </div>

                <a href="<?php echo htmlspecialchars($base_path . 'logout.php'); ?>" class="p-2 text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-full transition" title="Logout">
                    <i class="fas fa-sign-out-alt"></i>
                </a>
            </div>
        </nav>
    </header>

    <main class="max-w-7xl mx-auto px-4 py-6">
        
        <div class="mb-6">
            <h1 class="text-2xl font-extrabold text-gray-900 dark:text-white">Registration</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400">Add new clients to the system</p>
        </div>

        <!-- STATS -->
        <div class="dashboard-grid">
            <div class="dashboard-card card-gradient-indigo">
                <i class="fas fa-users card-bg-icon"></i>
                <div class="flex justify-between items-start relative z-10">
                    <div>
                        <div class="text-sm font-semibold uppercase opacity-90">Total Clients</div>
                        <div class="text-3xl font-extrabold mt-1 mb-2 count-up" id="totalClients">...</div>
                        <div class="text-xs bg-black/20 rounded-full px-3 py-1 inline-flex items-center"><i class="fas fa-hashtag mr-1"></i> Registered</div>
                    </div>
                    <div class="w-12 h-12 bg-white/20 backdrop-blur rounded-xl flex items-center justify-center text-xl"><i class="fas fa-user-plus"></i></div>
                </div>
            </div>

            <div class="dashboard-card card-gradient-amber">
                <i class="fas fa-wallet card-bg-icon"></i>
                <div class="flex justify-between items-start relative z-10">
                    <div>
                        <div class="text-sm font-semibold uppercase opacity-90">Total Fees</div>
                        <div class="text-3xl font-extrabold mt-1 mb-2" id="registrationFees">...</div>
                        <div class="text-xs bg-black/20 rounded-full px-3 py-1 inline-flex items-center"><i class="fas fa-coins mr-1"></i> Collected</div>
                    </div>
                    <div class="w-12 h-12 bg-white/20 backdrop-blur rounded-xl flex items-center justify-center text-xl"><i class="fas fa-money-bill-wave"></i></div>
                </div>
            </div>

            <!-- DYNAMIC FEE CARD -->
            <div class="dashboard-card card-gradient-emerald">
                <i class="fas fa-tag card-bg-icon"></i>
                <div class="flex justify-between items-start relative z-10">
                    <div>
                        <div class="text-sm font-semibold uppercase opacity-90">Registration Fee</div>
                        <div class="text-3xl font-extrabold mt-1 mb-2 transition-transform duration-200" id="cardFeeAmount">₦<?php echo number_format($fee_daily); ?></div>
                        <div class="text-xs bg-black/20 rounded-full px-3 py-1 inline-flex items-center" id="cardFeeLabel"><i class="fas fa-info-circle mr-1"></i> Daily Standard</div>
                    </div>
                    <div class="w-12 h-12 bg-white/20 backdrop-blur rounded-xl flex items-center justify-center text-xl"><i class="fas fa-receipt"></i></div>
                </div>
            </div>
        </div>

        <!-- REGISTRATION FORM -->
        <div class="max-w-3xl mx-auto">
            <div class="form-card">
                <div class="form-header">
                    <h2 class="text-lg font-bold flex items-center gap-2 text-gray-800 dark:text-white">
                        <i class="fas fa-user-edit text-indigo-500"></i> New Client
                    </h2>
                    <span id="feeBadge" class="text-xs bg-indigo-100 text-indigo-700 dark:bg-indigo-900/30 dark:text-indigo-300 px-3 py-1 rounded-full font-bold transition-all">
                        Fee: ₦<?php echo number_format($fee_daily); ?>
                    </span>
                </div>

                <form id="clientForm" class="p-6 space-y-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        
                        <!-- Name -->
                        <div class="input-group">
                            <label for="name" class="input-label">Full Name</label>
                            <i class="fas fa-user input-icon"></i>
                            <input type="text" id="name" name="name" required autocomplete="off" placeholder="e.g. John Doe" class="input-field capitalize-input">
                            <div id="nameValidation" class="validation-message"></div>
                        </div>

                        <!-- Phone -->
                        <div class="input-group">
                            <label for="phone" class="input-label">Phone Number</label>
                            <i class="fas fa-phone input-icon"></i>
                            <input type="tel" id="phone" name="phone" autocomplete="off" placeholder="0803 000 0000" maxlength="15" class="input-field">
                            <div id="phoneValidation" class="validation-message"></div>
                        </div>

                        <!-- Branch -->
                        <div class="input-group">
                            <label class="input-label">Branch</label>
                            <i class="fas fa-code-branch input-icon"></i>
                            <input type="text" value="<?php echo htmlspecialchars($branch_name_display); ?>" readonly class="input-field bg-gray-100 dark:bg-gray-700/50 cursor-not-allowed text-gray-500">
                        </div>

                        <!-- Union -->
                        <div class="input-group">
                            <label for="union" class="input-label">Union</label>
                            <i class="fas fa-users input-icon"></i>
                            <select id="union" name="union" required class="input-field cursor-pointer">
                                <option value="">Select Union</option>
                                <?php foreach ($assigned_unions as $union): ?>
                                    <option value="<?php echo htmlspecialchars($union); ?>"><?php echo htmlspecialchars($union); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <i class="fas fa-chevron-down absolute right-4 top-10 text-gray-400 pointer-events-none text-xs"></i>
                        </div>

                        <!-- Plan Type (LOADED DYNAMICALLY) -->
                        <div class="input-group">
                            <label for="type" class="input-label">Plan / Type</label>
                            <i class="fas fa-tag input-icon"></i>
                            <select id="type" name="type" required class="input-field cursor-pointer">
                                <option value="">Select Plan</option>
                                <?php if (!empty($loan_plans)): ?>
                                    <?php 
                                    $grouped_plans = [];
                                    foreach ($loan_plans as $plan) {
                                        $unit = ucfirst(strtolower($plan['unit'] ?? 'days'));
                                        $group_key = $unit . ' Plans';
                                        if (!isset($grouped_plans[$group_key])) $grouped_plans[$group_key] = [];
                                        
                                        $reg_fee = $plan['registration_amount'] ?? $fee_daily;
                                        $label = $plan['duration'] . ' ' . $unit . ' • ' . (($plan['rate'] ?? 0) * 100) . '% • ₦' . number_format($reg_fee) . ' fee';
                                        
                                        $grouped_plans[$group_key][] = [
                                            'value' => $plan['duration'] . ' ' . $unit,
                                            'label' => $label,
                                            'plan_id' => $plan['id'],
                                            'fee' => $reg_fee
                                        ];
                                    }
                                    ?>
                                    <?php foreach ($grouped_plans as $group_label => $plans): ?>
                                        <optgroup label="<?php echo htmlspecialchars($group_label); ?>">
                                            <?php foreach ($plans as $p): ?>
                                                <option value="<?php echo htmlspecialchars($p['value']); ?>" data-plan-id="<?php echo htmlspecialchars($p['plan_id']); ?>" data-fee="<?php echo $p['fee']; ?>">
                                                    <?php echo htmlspecialchars($p['label']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </optgroup>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                            <i class="fas fa-chevron-down absolute right-4 top-10 text-gray-400 pointer-events-none text-xs"></i>
                            <p class="text-xs text-gray-500 mt-1" id="feeHint">Registration Fee varies by plan.</p>
                        </div>

                        <!-- Address -->
                        <div class="md:col-span-2 input-group">
                            <label for="address" class="input-label">Address</label>
                            <i class="fas fa-map-marker-alt input-icon"></i>
                            <input type="text" id="address" name="address" autocomplete="off" placeholder="House Address" class="input-field capitalize-input">
                        </div>

                        <!-- Guarantor -->
                        <div class="input-group">
                            <label for="guarantor_name" class="input-label">Guarantor Name</label>
                            <i class="fas fa-user-shield input-icon"></i>
                            <input type="text" id="guarantor_name" name="guarantor_name" autocomplete="off" placeholder="Full Name" class="input-field capitalize-input">
                        </div>

                        <div class="input-group">
                            <label for="guarantor_phone" class="input-label">Guarantor Phone</label>
                            <i class="fas fa-phone-alt input-icon"></i>
                            <input type="tel" id="guarantor_phone" name="guarantor_phone" autocomplete="off" placeholder="080..." maxlength="15" class="input-field">
                        </div>
                    </div>

                    <button type="submit" id="addClientBtn" class="btn-primary">
                        <span>Register Client</span>
                        <i class="fas fa-paper-plane"></i>
                    </button>
                </form>
            </div>
        </div>

    </main>

    <!-- MOBILE BOTTOM NAV -->
    <nav class="mobile-bottom-nav">
        <a href="dashboard.php" class="nav-item"><i class="fas fa-home"></i><span>Home</span></a>
        <a href="saving_collection.php" class="nav-item"><i class="fas fa-piggy-bank"></i><span>Save</span></a>
        <a href="disbursement.php" class="nav-item"><i class="fas fa-hand-holding-usd"></i><span>Disburse</span></a>
        <a href="loan_collection.php" class="nav-item"><i class="fas fa-money-bill-wave"></i><span>Repay</span></a>
        <a href="registration.php" class="nav-item active">
             <div style="background: var(--primary-color); width: 45px; height: 45px; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; margin-top: -25px; box-shadow: 0 4px 10px rgba(99, 102, 241, 0.4);">
                <i class="fas fa-user-plus" style="font-size: 1.2rem; margin:0;"></i>
             </div>
             <span style="font-size:0.65rem; font-weight:600; margin-top:2px;">Register</span>
        </a>
    </nav>

    <script>
        const formatPhone = (val) => {
            val = val.replace(/\D/g, '');
            if (val.length > 4) val = val.slice(0,4) + ' ' + val.slice(4);
            if (val.length > 8) val = val.slice(0,8) + ' ' + val.slice(8);
            return val.trim();
        };

        const BASE_FEE = <?php echo $fee_daily; ?>;
        const LOAN_PLANS = <?php 
            $js_plans = [];
            foreach($loan_plans as $p) { $js_plans[$p['id']] = $p; }
            echo json_encode($js_plans); 
        ?>;

        class LiveRegistration {
            constructor() { this.init(); this.bindEvents(); this.loadDashboardStats(); }
            
            init() {
                this.form = document.getElementById('clientForm');
                this.nameField = document.getElementById('name');
                this.unionField = document.getElementById('union');
                this.phoneField = document.getElementById('phone');
                this.gPhoneField = document.getElementById('guarantor_phone');
                this.typeField = document.getElementById('type');
                this.feeBadge = document.getElementById('feeBadge');
                this.submitBtn = document.getElementById('addClientBtn');
                this.cardFeeAmount = document.getElementById('cardFeeAmount');
                this.cardFeeLabel = document.getElementById('cardFeeLabel');
                this.duplicateCheckTimeout = null;
                this.isChecking = false; this.isSubmitting = false;
            }
            
            bindEvents() {
                this.nameField.addEventListener('input', () => this.validateName());
                this.unionField.addEventListener('change', () => this.validateName());
                
                this.typeField.addEventListener('change', (e) => {
                    const selectedOption = e.target.options[e.target.selectedIndex];
                    const fee = selectedOption.getAttribute('data-fee') || BASE_FEE;
                    const planId = selectedOption.getAttribute('data-plan-id');
                    const formattedFee = '₦' + parseInt(fee).toLocaleString();
                    
                    this.feeBadge.textContent = 'Fee: ' + formattedFee;
                    if(this.cardFeeAmount) this.cardFeeAmount.textContent = formattedFee;

                    const labelText = selectedOption.value.includes('Week') ? 'Weekly Plan' : (selectedOption.value.includes('Month') ? 'Monthly Plan' : 'Daily Standard');
                    if(this.cardFeeLabel) this.cardFeeLabel.innerHTML = `<i class="fas fa-info-circle mr-1"></i> ${labelText}`;
                    
                    this.updateFeeHint(selectedOption.value, planId);
                });
                
                [this.phoneField, this.gPhoneField].forEach(field => {
                    field.addEventListener('input', (e) => {
                        e.target.value = formatPhone(e.target.value);
                        if(field === this.phoneField) this.validatePhone();
                    });
                });

                this.form.addEventListener('submit', (e) => { e.preventDefault(); this.submitForm(); });
            }
            
            updateFeeHint(selectedPlan, planId) {
                const feeHint = document.getElementById('feeHint');
                if (!feeHint) return;
                if (!selectedPlan || !planId || !LOAN_PLANS[planId]) {
                    feeHint.textContent = 'Registration Fee varies by plan.';
                    return;
                }
                const plan = LOAN_PLANS[planId];
                feeHint.innerHTML = `<i class="fas fa-info-circle mr-1"></i> ${((plan.rate || 0)*100).toFixed(0)}% rate • Min: ₦${parseInt(plan.min_amount).toLocaleString()}`;
            }

            validateName() {
                const name = this.nameField.value.trim();
                const union = this.unionField.value;
                const validation = document.getElementById('nameValidation');
                if (!name) { this.showFieldValidation(this.nameField, validation, '', 'clear'); return; }
                if (this.duplicateCheckTimeout) clearTimeout(this.duplicateCheckTimeout);
                this.showFieldValidation(this.nameField, validation, 'Checking...', 'checking');
                this.duplicateCheckTimeout = setTimeout(() => this.checkDuplicate(name, union), 500);
            }

            validatePhone() {
                const rawPhone = this.phoneField.value.replace(/\D/g, '');
                const validation = document.getElementById('phoneValidation');
                if (!rawPhone) { this.showFieldValidation(this.phoneField, validation, '', 'clear'); return; }
                this.showFieldValidation(this.phoneField, validation, rawPhone.length < 10 ? 'Too short' : 'Valid format', rawPhone.length < 10 ? 'warning' : 'success');
            }

            async checkDuplicate(name, union) {
                try {
                    const res = await fetch(`?action=check_duplicate&name=${name}&union=${union||''}`, {headers:{'X-Requested-With':'XMLHttpRequest'}});
                    const data = await res.json();
                    const val = document.getElementById('nameValidation');
                    
                    if (data.exists) {
                        if (data.has_funds) {
                            // If they have active savings or loan, block and show error
                            this.showFieldValidation(
                                this.nameField, 
                                val, 
                                `Blocked: Client has unsettled balances (Save: ₦${data.details.savings.toLocaleString()}, Loan: ₦${data.details.loan.toLocaleString()})`, 
                                'error'
                            );
                        } else {
                            // Safe to close old account
                            this.showFieldValidation(
                                this.nameField, 
                                val, 
                                `Active client found in ${data.details.union} (Balances are clear. Old record will be closed)`, 
                                'warning'
                            );
                        }
                    } else {
                        // Brand new client
                        this.showFieldValidation(this.nameField, val, 'Name available', 'success');
                    }
                } catch(e) {}
            }

            showFieldValidation(field, el, msg, type) {
                field.classList.remove('field-valid', 'field-invalid');
                el.classList.remove('show', 'text-emerald', 'text-red', 'text-amber');
                if(type === 'clear') return;
                const colors = { 'success': 'text-emerald', 'error': 'text-red', 'checking': 'text-amber', 'warning': 'text-amber' };
                if(type === 'success') field.classList.add('field-valid');
                if(type === 'error') field.classList.add('field-invalid');
                el.className = `validation-message show ${colors[type]}`;
                el.textContent = msg;
            }

            async submitForm() {
                if(this.isSubmitting) return;
                const fd = new FormData(this.form);
                const selectedOption = this.typeField.options[this.typeField.selectedIndex];
                const planId = selectedOption ? selectedOption.getAttribute('data-plan-id') : '';
                
                if(!planId) { showToast('Please select a plan', 'error'); return; }

                fd.append('action', 'add_client');
                fd.append('plan_id', planId);
                fd.set('phone', fd.get('phone').replace(/\s/g, ''));
                fd.set('guarantor_phone', fd.get('guarantor_phone').replace(/\s/g, ''));

                this.isSubmitting = true;
                this.submitBtn.disabled = true;
                this.submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Registering...';
                
                try {
                    const res = await fetch('', { method:'POST', body:fd, headers:{'X-Requested-With':'XMLHttpRequest'}});
                    const data = await res.json();
                    if(data.success) {
                        document.getElementById('success-overlay').classList.add('active');
                        this.form.reset();
                    } else {
                        showToast(data.error || 'Failed', 'error');
                    }
                } catch(e) { showToast('Network Error', 'error'); }
                finally {
                    this.isSubmitting = false;
                    this.submitBtn.disabled = false;
                    this.submitBtn.innerHTML = '<span>Register Client</span><i class="fas fa-paper-plane"></i>';
                }
            }

            async loadDashboardStats() {
                try {
                    const res = await fetch('?action=get_stats', {headers:{'X-Requested-With':'XMLHttpRequest'}});
                    const data = await res.json();
                    if(data.success) {
                        document.getElementById('totalClients').textContent = data.stats.total_clients;
                        document.getElementById('registrationFees').textContent = '₦' + Number(data.stats.total_registration_fees).toLocaleString();
                    }
                } catch(e) {}
            }
        }

        function showToast(msg, type) {
            const el = document.createElement('div');
            el.className = 'toast';
            el.style.borderLeftColor = type === 'success' ? '#22c55e' : '#ef4444';
            el.innerHTML = `<i class="fas ${type==='success'?'fa-check-circle text-emerald-500':'fa-exclamation-circle text-red-500'}"></i> <span class="text-sm font-medium text-gray-800 dark:text-gray-200">${msg}</span>`;
            document.getElementById('toast-container').appendChild(el);
            setTimeout(() => { el.style.opacity = '0'; setTimeout(() => el.remove(), 300); }, 3000);
        }

        document.addEventListener('DOMContentLoaded', () => {
            new LiveRegistration();
            const t = localStorage.getItem('theme');
            if(t==='dark') document.documentElement.classList.add('dark');
            const themeBtn = document.getElementById('themeToggle');
            themeBtn.onclick = () => {
                document.documentElement.classList.toggle('dark');
                localStorage.setItem('theme', document.documentElement.classList.contains('dark') ? 'dark' : 'light');
            };
        });
    </script>
</body>
</html>