<?php
// --- 1. CONFIGURATION ---
date_default_timezone_set('Africa/Lagos');
ini_set('date.timezone', 'Africa/Lagos');
session_start();
require_once '../includes/config.php';
$conn = getDbConnection();

// --- 2. AUTH CHECK ---
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: ../index.php');
    exit();
}

$base_path = '../';

// --- 3. FETCH CURRENT SETTINGS ---
try {
    // UPDATED: Query now joins with the users table to get the updater's name
    $stmt = $conn->query("SELECT s.*, u.name as updated_by_name FROM loan_collection_settings s LEFT JOIN users u ON s.updated_by = u.id WHERE s.id = 1");
    $settings = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$settings) {
        $conn->query("INSERT INTO loan_collection_settings (id, updated_by) VALUES (1, " . ($_SESSION['user_id'] ?? 'NULL') . ")");
        $settings = [
            'max_installments_per_payment' => 2,
            'min_installments_per_payment' => 1,
            'grace_period_days' => 2,
            'allow_partial_payments' => 0,
            'allow_overpayment' => 0,
            'collection_date_readonly' => 0,
            'updated_at' => null, // No update time for new record
            'updated_by' => $_SESSION['user_id'] ?? 0,
            'updated_by_name' => $_SESSION['full_name'] ?? 'System' // Add name for new record
        ];
    }
} catch (PDOException $e) {
    $error_message = "Error loading settings: " . $e->getMessage();
    $settings = [
        'max_installments_per_payment' => 2,
        'min_installments_per_payment' => 1,
        'grace_period_days' => 2,
        'allow_partial_payments' => 0,
        'allow_overpayment' => 0,
        'collection_date_readonly' => 0,
        'updated_at' => null,
        'updated_by_name' => null
    ];
}

// --- 4. HANDLE AJAX FORM SUBMISSION (Now handles both manual and autosave) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    header('Content-Type: application/json');
    try {
        $max_installments = intval($_POST['max_installments_per_payment'] ?? 2);
        $min_installments = intval($_POST['min_installments_per_payment'] ?? 1);
        $grace_period = intval($_POST['grace_period_days'] ?? 2);
        $allow_partial = isset($_POST['allow_partial_payments']) ? 1 : 0;
        $allow_overpayment = isset($_POST['allow_overpayment']) ? 1 : 0;
        $date_readonly = isset($_POST['collection_date_readonly']) ? 1 : 0;
        $current_user_id = $_SESSION['user_id'] ?? 0;

        // Validation
        if ($min_installments < 1) {
            throw new Exception("Minimum installments must be at least 1");
        }
        if ($max_installments < $min_installments) {
            throw new Exception("Maximum installments cannot be less than minimum");
        }
        if ($grace_period < 0 || $grace_period > 30) {
            throw new Exception("Grace period must be between 0 and 30 days");
        }

        // UPDATED: Added `updated_by` to the prepared statement
        $stmt = $conn->prepare("UPDATE loan_collection_settings SET 
            max_installments_per_payment = ?, 
            min_installments_per_payment = ?, 
            grace_period_days = ?,
            allow_partial_payments = ?,
            allow_overpayment = ?,
            collection_date_readonly = ?,
            updated_at = NOW(),
            updated_by = ?
            WHERE id = 1");

        $stmt->execute([$max_installments, $min_installments, $grace_period, $allow_partial, $allow_overpayment, $date_readonly, $current_user_id]);

        // Log the action
        $log_stmt = $conn->prepare("INSERT INTO system_logs (user_id, action, details, created_at) VALUES (?, 'update_loan_settings', ?, NOW())");
        $log_details = json_encode([
            'max_installments_per_payment' => $max_installments,
            'min_installments_per_payment' => $min_installments,
            'grace_period_days' => $grace_period,
            'allow_partial_payments' => $allow_partial,
            'allow_overpayment' => $allow_overpayment,
            'collection_date_readonly' => $date_readonly,
            'updated_by' => $current_user_id
        ]);
        $log_stmt->execute([$current_user_id, $log_details]);
        
        // UPDATED: Return the current user's name in the JSON response
        $new_updated_at = date('F j, Y g:i A');
        $updated_by_name = $_SESSION['full_name'] ?? 'System';
        echo json_encode(['success' => true, 'message' => 'Settings saved successfully', 'updated_at' => $new_updated_at, 'updated_by' => $updated_by_name]);
        exit;
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

// --- 5. USER DATA ---
$full_name = $_SESSION['full_name'] ?? 'Admin';
$username = $_SESSION['username'] ?? '';
$role = $_SESSION['role'] ?? '';

$my_pic = 'default_avatar.png';
try {
    $stmt = $conn->prepare("SELECT profile_pic FROM users WHERE username = ?");
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
    <title>Loan Collection Settings - CUPAD</title>
    <link rel="icon" type="image/png" href="<?php echo htmlspecialchars($base_path . 'uploads/CUPAD LOGO.png'); ?>">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />
    <style>
        :root { 
            --primary: #2563eb; --primary-dark: #1d4ed8; --primary-light: #dbeafe;
            --success: #059669; --success-light: #d1fae5;
            --warning: #d97706; --warning-light: #ffedd5;
            --danger: #dc2626; --danger-light: #fee2e2;
            --info: #0284c7; --info-light: #e0f2fe;
            --bg-body: #f1f5f9; --bg-surface: #ffffff; 
            --text-main: #0f172a; --text-muted: #64748b; --border: #e2e8f0; 
            --radius-lg: 16px; --radius-md: 10px; 
            --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05); --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1); 
            --shadow-lg: 0 20px 25px -5px rgba(0,0,0,0.1), 0 10px 10px -5px rgba(0,0,0,0.04);
            --nav-height: 70px; 
        }
        html.dark { 
            --bg-body: #0f172a; --bg-surface: #1e293b; 
            --text-main: #f8fafc; --text-muted: #94a3b8; --border: #334155; 
            --primary: #3b82f6; --primary-light: rgba(59, 130, 246, 0.2);
            --success: #34d399; --success-light: rgba(16, 185, 129, 0.2);
            --warning: #fbbf24; --warning-light: rgba(245, 158, 11, 0.2);
            --danger: #f87171; --danger-light: rgba(244, 63, 94, 0.2);
            --info: #38bdf8; --info-light: rgba(56, 189, 248, 0.2);
        }
        
        *, *::before, *::after { box-sizing: border-box; }
        :focus:not(:focus-visible) { outline: none; }
        :focus-visible { outline: 2px solid var(--primary); outline-offset: 2px; border-radius: 4px; }
        body { font-family: 'Plus Jakarta Sans', sans-serif; background: var(--bg-body); color: var(--text-main); margin: 0; padding-top: var(--nav-height); transition: background-color 0.3s, color 0.3s; }
        a { text-decoration: none; color: inherit; }

        .toast-container{position:fixed;top:calc(var(--nav-height) + 1rem);right:1.5rem;z-index:9999;display:flex;flex-direction:column;gap:.75rem;pointer-events:none}.toast{background:var(--bg-surface);border-left:4px solid var(--success);padding:1rem 1.5rem;border-radius:var(--radius-md);box-shadow:var(--shadow-lg);display:flex;align-items:center;gap:.75rem;min-width:320px;transform:translateX(400px);opacity:0;transition:all .4s cubic-bezier(.175,.885,.32,1.275);pointer-events:auto;position:relative;overflow:hidden}.toast.show{transform:translateX(0);opacity:1}.toast.error{border-left-color:var(--danger)}.toast-progress{position:absolute;bottom:0;left:0;height:4px;background:var(--success);width:100%;animation:toastProgress 4.6s linear forwards}.toast.error .toast-progress{background:var(--danger)}@keyframes toastProgress{from{width:100%}to{width:0%}}.toast-icon{width:32px;height:32px;border-radius:50%;background:var(--success-light);color:var(--success);display:flex;align-items:center;justify-content:center}.toast.error .toast-icon{background:var(--danger-light);color:var(--danger)}.toast-content{flex:1}.toast-title{font-weight:700;font-size:.95rem;margin-bottom:.25rem}.toast-message{font-size:.85rem;color:var(--text-muted)}.toast-close{background:0 0;border:none;color:var(--text-muted);cursor:pointer;padding:.25rem;transition:color .2s}.toast-close:hover{color:var(--text-main)}

        .main-header{position:fixed;top:0;left:0;right:0;height:var(--nav-height);background:rgba(255,255,255,.85);backdrop-filter:blur(12px);border-bottom:1px solid var(--border);z-index:50;transition:background .3s}html.dark .main-header{background:rgba(15,23,42,.85)}.navbar{max-width:1400px;margin:0 auto;padding:0 1.5rem;display:flex;align-items:center;justify-content:space-between;height:100%}.logo{display:flex;align-items:center;gap:.75rem;font-weight:800;font-size:1.35rem;color:var(--primary)}.logo img{height:38px}.nav-right{display:flex;align-items:center;gap:1rem}.icon-btn{width:36px;height:36px;border-radius:50%;border:none;background:0 0;color:var(--text-muted);cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:1.1rem;transition:.2s}.icon-btn:hover{background:var(--bg-body);color:var(--text-main)}.user-dropdown-wrap{position:relative}.user-pill{display:flex;align-items:center;gap:.75rem;padding:4px 8px 4px 4px;border:1px solid var(--border);border-radius:99px;background:var(--bg-surface);cursor:pointer;transition:all .2s}.user-pill:hover{border-color:var(--primary);box-shadow:var(--shadow-sm)}.user-avatar{width:34px;height:34px;border-radius:50%;object-fit:cover}.user-avatar-fallback{width:34px;height:34px;border-radius:50%;background:var(--bg-body);color:var(--text-muted);display:flex;align-items:center;justify-content:center;font-size:1rem;border:1px solid var(--border)}.user-info{display:flex;flex-direction:column;line-height:1.1;padding-right:.5rem}.user-name{font-weight:600;font-size:.85rem}.user-role{font-size:.7rem;color:var(--text-muted);text-transform:uppercase}.dropdown-menu{position:absolute;top:125%;right:0;background:var(--bg-surface);border:1px solid var(--border);border-radius:var(--radius-md);box-shadow:var(--shadow-md);min-width:200px;display:none;z-index:1000;flex-direction:column;overflow:hidden;animation:scaleIn .2s ease;transform-origin:top right}@keyframes scaleIn{from{opacity:0;transform:scale(.95)}to{opacity:1;transform:scale(1)}}.dropdown-menu.show{display:flex}.dropdown-item{padding:.75rem 1rem;display:flex;align-items:center;gap:.75rem;font-size:.9rem;transition:.2s}.dropdown-item:hover{background:var(--bg-body);color:var(--primary)}.text-danger{color:var(--danger)!important}

        .container{max-width:900px;margin:0 auto;padding:2rem 1.5rem 4rem}.page-header{margin-bottom:2rem;position:relative}.back-link{display:inline-flex;align-items:center;gap:.5rem;color:var(--text-muted);font-size:.9rem;margin-bottom:1rem;transition:all .2s;padding:.5rem 0;font-weight:500}.back-link:hover{color:var(--primary);transform:translateX(-4px)}.page-title-wrap{display:flex;align-items:center;gap:1rem}.page-icon{width:56px;height:56px;border-radius:var(--radius-lg);background:linear-gradient(135deg,var(--warning),#b45309);color:#fff;display:flex;align-items:center;justify-content:center;font-size:1.5rem;box-shadow:var(--shadow-md)}.page-title-content h1{margin:0;font-size:1.75rem;font-weight:800;color:var(--text-main)}.page-title-content p{margin:.25rem 0 0;color:var(--text-muted);font-size:.95rem}
        .card{background:var(--bg-surface);border:1px solid var(--border);border-radius:var(--radius-lg);padding:1.75rem;box-shadow:var(--shadow-sm);margin-bottom:1.75rem;transition:transform .2s,box-shadow .2s}.card:hover{box-shadow:var(--shadow-md)}.card-header{display:flex;align-items:center;gap:1rem;margin-bottom:1.75rem}.card-header-icon{width:48px;height:48px;border-radius:12px;background:var(--primary-light);color:var(--primary);display:flex;align-items:center;justify-content:center;font-size:1.25rem}.card-header h2{margin:0;font-size:1.2rem;font-weight:700;color:var(--text-main)}.card-header p{margin:.25rem 0 0;font-size:.85rem;color:var(--text-muted)}
        .form-row{display:grid;grid-template-columns:repeat(3,1fr);gap:1.5rem}.form-group{margin-bottom:0}.form-label{display:flex;align-items:center;justify-content:space-between;margin-bottom:.6rem;font-weight:600;font-size:.9rem;color:var(--text-main)}.badge{font-size:.7rem;padding:.25rem .5rem;border-radius:99px;background:var(--bg-body);color:var(--text-muted);font-weight:600}
        .number-control{display:flex;align-items:center;gap:.5rem}.num-btn{width:44px;height:44px;border-radius:var(--radius-md);border:2px solid var(--border);background:var(--bg-surface);color:var(--text-main);display:flex;align-items:center;justify-content:center;cursor:pointer;transition:all .2s;flex-shrink:0;font-size:1rem}.num-btn:active:not(:disabled),.num-btn:hover:not(:disabled){border-color:var(--primary);color:var(--primary);background:var(--primary-light)}.num-btn:active:not(:disabled){transform:scale(.95)}.num-btn:disabled{opacity:.4;cursor:not-allowed;background:var(--bg-body)}.input-wrapper{position:relative;flex:1}.input-icon{position:absolute;left:1rem;top:50%;transform:translateY(-50%);color:var(--text-muted);font-size:1rem;transition:color .2s;z-index:2;pointer-events:none}input[type=number],input[type=text]{width:100%;padding:.875rem 1rem .875rem 2.5rem;border-radius:var(--radius-md);border:2px solid var(--border);background:var(--bg-body);color:var(--text-main);font-family:inherit;font-size:1rem;font-weight:600;transition:all .2s}input[type=number]::-webkit-inner-spin-button,input[type=number]::-webkit-outer-spin-button{-webkit-appearance:none;margin:0}input[type=number]{-moz-appearance:textfield}input[type=number]:focus,input[type=text]:focus{border-color:var(--primary);background:var(--bg-surface);box-shadow:0 0 0 4px rgba(37,99,235,.1);outline:0}input[type=number]:focus+.input-icon,input[type=text]:focus+.input-icon{color:var(--primary)}.input-hint{font-size:.8rem;color:var(--text-muted);margin-top:.5rem;display:flex;align-items:center;gap:.4rem}.validation-msg{font-size:.8rem;margin-top:.5rem;display:none;align-items:center;gap:.4rem;font-weight:500}.validation-msg.show{display:flex}.validation-msg.error{color:var(--danger)}
        [data-tooltip]{position:relative;cursor:help}[data-tooltip]::after{content:attr(data-tooltip);position:absolute;bottom:110%;left:50%;transform:translateX(-50%);padding:.5rem .8rem;border-radius:var(--radius-md);background:#262626;color:#fff;font-size:.8rem;font-weight:500;white-space:nowrap;opacity:0;visibility:hidden;transition:opacity .2s,visibility .2s,transform .2s;z-index:100}[data-tooltip]:hover::after{opacity:1;visibility:visible;transform:translateX(-50%) translateY(-5px)}
        .toggle-group{display:flex;align-items:center;justify-content:space-between;padding:1.25rem;background:var(--bg-body);border-radius:var(--radius-md);border:2px solid transparent;margin-bottom:1rem;transition:all .2s;user-select:none}.toggle-group:hover{border-color:var(--primary);background:var(--bg-surface);transform:translateY(-2px);box-shadow:var(--shadow-sm)}.toggle-group.active{border-color:var(--success);background:var(--success-light)}.toggle-label{display:flex;align-items:center;gap:1rem;flex:1;cursor:pointer}.toggle-icon-wrap{width:44px;height:44px;border-radius:10px;background:var(--bg-surface);color:var(--primary);display:flex;align-items:center;justify-content:center;font-size:1.1rem;transition:all .2s}.toggle-group:hover .toggle-icon-wrap{transform:scale(1.1)}.toggle-group.active .toggle-icon-wrap{background:var(--success);color:#fff}.toggle-text h4{margin:0 0 .25rem;font-size:.95rem;font-weight:700;color:var(--text-main)}.toggle-text p{margin:0;font-size:.8rem;color:var(--text-muted);line-height:1.4}.switch{position:relative;display:inline-block;width:52px;height:30px;flex-shrink:0}.switch input{opacity:0;width:0;height:0;position:absolute}.slider{position:absolute;cursor:pointer;top:0;left:0;right:0;bottom:0;background-color:var(--border);transition:.4s;border-radius:30px}.slider:before{position:absolute;content:"";height:24px;width:24px;left:3px;bottom:3px;background-color:#fff;transition:.4s;border-radius:50%;box-shadow:0 2px 4px rgba(0,0,0,.1)}input:checked+.slider{background-color:var(--success)}input:checked+.slider:before{transform:translateX(22px)}.switch input:focus-visible+.slider{outline:2px solid var(--primary);outline-offset:2px}
        .stats-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:1.25rem;margin-bottom:2rem}.stat-card{background:var(--bg-surface);padding:1.25rem;border-radius:var(--radius-md);border:1px solid var(--border);text-align:center;transition:all .2s;position:relative;overflow:hidden}.stat-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;background:var(--primary);transform:scaleX(0);transition:transform .3s}.stat-card:hover::before{transform:scaleX(1)}.stat-card:hover{transform:translateY(-4px);box-shadow:var(--shadow-md)}.stat-card.changed{border-color:var(--primary);box-shadow:0 4px 12px var(--primary-light)}.stat-value{font-size:1.85rem;font-weight:800;color:var(--text-main);margin-bottom:.25rem;transition:all .3s;display:inline-block}.stat-card.changed .stat-value{color:var(--primary)}@keyframes popIn{0%{transform:scale(.95);opacity:.8}50%{transform:scale(1.15);color:var(--primary)}100%{transform:scale(1);opacity:1}}.pop-animate{animation:popIn .35s ease-out}.stat-label{font-size:.75rem;color:var(--text-muted);font-weight:700;text-transform:uppercase;letter-spacing:.5px}.stat-badge{display:inline-flex;align-items:center;gap:.3rem;font-size:.75rem;margin-top:.5rem;padding:.3rem .75rem;border-radius:99px;background:var(--bg-body);font-weight:600;transition:all .3s}.stat-badge.active{background:var(--success-light);color:var(--success)}.stat-badge.inactive{background:var(--danger-light);color:var(--danger)}.range-display{display:flex;align-items:center;justify-content:center;gap:.5rem;margin-top:.5rem;font-size:.8rem;color:var(--text-muted)}.range-display span{font-weight:700;color:var(--primary)}
        
        /* --- Autosave Styles --- */
        .btn-group{display:flex;gap:1rem;align-items:center;margin-top:2rem;position:sticky;bottom:1rem;background:var(--bg-surface);padding:.75rem;border-radius:var(--radius-lg);box-shadow:var(--shadow-lg);border:1px solid var(--border);transition:all .3s;z-index:10}
        .autosave-status{display:flex;align-items:center;gap:.5rem;font-size:.85rem;font-weight:700;flex-grow:1;transition:all .3s;opacity:0}
        .autosave-status.show{opacity:1}
        .autosave-status.status-unsaved{color:var(--warning)}
        .autosave-status.status-saving{color:var(--info)}
        .autosave-status.status-saved{color:var(--success)}
        .autosave-status.status-error{color:var(--danger)}
        @keyframes spin{to{transform:rotate(360deg)}}
        .autosave-status .fa-spinner{animation:spin 1s linear infinite}
        /* --- End Autosave --- */
        
        .btn{padding:.875rem 1.5rem;border-radius:var(--radius-md);font-weight:700;font-size:.95rem;cursor:pointer;transition:all .2s;display:inline-flex;align-items:center;justify-content:center;gap:.5rem;border:none;font-family:inherit}.btn-primary{background:var(--primary);color:#fff;position:relative;overflow:hidden}.btn-primary:hover:not(:disabled){background:var(--primary-dark);transform:translateY(-2px);box-shadow:0 10px 20px -5px var(--primary-light)}.btn-primary:disabled{background:#94a3b8;color:#e2e8f0;cursor:not-allowed}html.dark .btn-primary:disabled{background:#475569;color:#94a3b8}.btn-secondary{background:var(--bg-body);color:var(--text-muted);border:1px solid var(--border)}.btn-secondary:hover{background:var(--border);color:var(--text-main)}.btn-primary.loading .btn-text{opacity:0}.btn-spinner{position:absolute;width:22px;height:22px;border:3px solid hsla(0,0%,100%,.3);border-top-color:#fff;border-radius:50%;animation:spin .8s linear infinite;opacity:0;transition:opacity .2s}.btn-primary.loading .btn-spinner{opacity:1}

        @media (max-width: 768px){.stats-grid{grid-template-columns:repeat(2,1fr)}.form-row{grid-template-columns:1fr}.toggle-group{flex-direction:column;gap:1rem;text-align:center}.toggle-label{flex-direction:column}}
        @media (max-width: 640px){.container{padding:1rem 1rem 5rem}.card{padding:1.25rem}.btn-group{flex-direction:column-reverse;position:fixed;left:1rem;right:1rem;bottom:1rem;margin:0}.page-title-wrap{flex-direction:column;text-align:center}.stats-grid{grid-template-columns:1fr}}
    </style>
</head>
<body>

    <div class="toast-container" id="toastContainer"></div>

    <header class="main-header">
        <div class="navbar">
            <a href="dashboard.php" class="logo">
                <img src="<?php echo htmlspecialchars($base_path . 'uploads/CUPAD LOGO.png'); ?>" alt="Logo">
                <span>CUPAD</span>
            </a>
            <div class="nav-right">
                <button class="icon-btn" id="themeToggle" title="Toggle Theme" aria-label="Toggle Theme"><i class="fas fa-moon"></i></button>
                <div class="user-dropdown-wrap">
                    <div class="user-pill" id="userDropdownTrigger" tabindex="0" role="button" aria-expanded="false">
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

    <main class="container">
        <div class="page-header">
            <a href="dashboard.php" class="back-link"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
            <div class="page-title-wrap">
                <div class="page-icon"><i class="fas fa-hand-holding-dollar"></i></div>
                <div class="page-title-content">
                    <h1>Loan Collection Settings</h1>
                    <p>Configure repayment rules and collection parameters</p>
                </div>
            </div>
        </div>

        <div class="stats-grid">
            <div class="stat-card" id="statInstallments">
                <div class="stat-value" id="installmentRange"><?php echo $settings['min_installments_per_payment']; ?>-<?php echo $settings['max_installments_per_payment']; ?></div>
                <div class="stat-label">Installments / Payment</div>
                <div class="range-display">Min: <span id="statMinInstall"><?php echo $settings['min_installments_per_payment']; ?></span> | Max: <span id="statMaxInstall"><?php echo $settings['max_installments_per_payment']; ?></span></div>
            </div>
            <div class="stat-card" id="statGrace">
                <div class="stat-value" id="graceValue"><?php echo $settings['grace_period_days']; ?></div>
                <div class="stat-label">Grace Period (Days)</div>
                <div class="stat-badge <?php echo $settings['grace_period_days'] > 0 ? 'active' : 'inactive'; ?>">
                    <i class="fas fa-<?php echo $settings['grace_period_days'] > 0 ? 'clock' : 'times'; ?>"></i>
                    <?php echo $settings['grace_period_days'] > 0 ? 'Active' : 'None'; ?>
                </div>
            </div>
            <div class="stat-card" id="statOptions">
                <div style="display: flex; flex-direction: column; gap: 0.5rem; margin-top: 0.5rem;">
                    <div class="stat-badge <?php echo $settings['allow_partial_payments'] ? 'active' : 'inactive'; ?>" id="statPartial"><i class="fas fa-<?php echo $settings['allow_partial_payments'] ? 'check' : 'times'; ?>"></i> Partial Payments</div>
                    <div class="stat-badge <?php echo $settings['allow_overpayment'] ? 'active' : 'inactive'; ?>" id="statOverpay"><i class="fas fa-<?php echo $settings['allow_overpayment'] ? 'check' : 'times'; ?>"></i> Overpayment</div>
                </div>
                <div class="stat-label" style="margin-top: 0.75rem;">Payment Options</div>
            </div>
        </div>

        <form method="POST" action="" id="settingsForm" autocomplete="off">
            <div class="card">
                <div class="card-header">
                    <div class="card-header-icon"><i class="fas fa-list-ol"></i></div>
                    <div><h2>Installment Rules</h2><p>Configure how many installments can be processed per payment</p></div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Minimum Installments <span class="badge">Required</span></label>
                        <div class="number-control">
                            <button type="button" class="num-btn minus" tabindex="-1" aria-label="Decrease Minimum"><i class="fas fa-minus"></i></button>
                            <div class="input-wrapper"><i class="fas fa-arrow-down input-icon"></i><input type="number" name="min_installments_per_payment" id="minInstallments" min="1" max="12" value="<?php echo htmlspecialchars($settings['min_installments_per_payment']); ?>" required></div>
                            <button type="button" class="num-btn plus" tabindex="-1" aria-label="Increase Minimum"><i class="fas fa-plus"></i></button>
                        </div>
                        <div class="input-hint"><i class="fas fa-info-circle"></i> Minimum per transaction</div>
                        <div class="validation-msg" id="minValidation"></div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Maximum Installments <span class="badge">Required</span></label>
                        <div class="number-control">
                            <button type="button" class="num-btn minus" tabindex="-1" aria-label="Decrease Maximum"><i class="fas fa-minus"></i></button>
                            <div class="input-wrapper"><i class="fas fa-arrow-up input-icon"></i><input type="number" name="max_installments_per_payment" id="maxInstallments" min="1" max="12" value="<?php echo htmlspecialchars($settings['max_installments_per_payment']); ?>" required></div>
                            <button type="button" class="num-btn plus" tabindex="-1" aria-label="Increase Maximum"><i class="fas fa-plus"></i></button>
                        </div>
                        <div class="input-hint"><i class="fas fa-info-circle"></i> Maximum per transaction</div>
                        <div class="validation-msg" id="maxValidation"></div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Grace Period (Days) <span class="badge">0-30</span></label>
                        <div class="number-control">
                            <button type="button" class="num-btn minus" tabindex="-1" aria-label="Decrease Grace Period"><i class="fas fa-minus"></i></button>
                            <div class="input-wrapper"><i class="fas fa-calendar-day input-icon"></i><input type="number" name="grace_period_days" id="gracePeriod" min="0" max="30" value="<?php echo htmlspecialchars($settings['grace_period_days']); ?>" required></div>
                            <button type="button" class="num-btn plus" tabindex="-1" aria-label="Increase Grace Period"><i class="fas fa-plus"></i></button>
                        </div>
                        <div class="input-hint"><i class="fas fa-info-circle"></i> 0 days means no grace period.</div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <div class="card-header-icon" style="background: var(--info-light); color: var(--info);"><i class="fas fa-sliders"></i></div>
                    <div><h2>Payment Options</h2><p>Configure payment flexibility and restrictions</p></div>
                </div>
                <div class="toggle-group">
                    <label class="toggle-label" for="partialToggle">
                        <div class="toggle-icon-wrap" data-tooltip="Enable to accept payments smaller than the required amount."><i class="fas fa-money-bill-wave"></i></div>
                        <div class="toggle-text"><h4>Allow Partial Payments</h4><p>Permit payments less than the full installment amount</p></div>
                    </label>
                    <label class="switch"><input type="checkbox" name="allow_partial_payments" id="partialToggle" <?php echo $settings['allow_partial_payments'] ? 'checked' : ''; ?>><span class="slider"></span></label>
                </div>
                <div class="toggle-group">
                    <label class="toggle-label" for="overpayToggle">
                        <div class="toggle-icon-wrap" data-tooltip="Enable to let users pay more than what is currently due."><i class="fas fa-coins"></i></div>
                        <div class="toggle-text"><h4>Allow Overpayment</h4><p>Permit payments exceeding the scheduled amount</p></div>
                    </label>
                    <label class="switch"><input type="checkbox" name="allow_overpayment" id="overpayToggle" <?php echo $settings['allow_overpayment'] ? 'checked' : ''; ?>><span class="slider"></span></label>
                </div>
                <div class="toggle-group">
                    <label class="toggle-label" for="readonlyToggle">
                        <div class="toggle-icon-wrap" data-tooltip="When enabled, collectors cannot change the date of a payment transaction."><i class="fas fa-calendar-alt"></i></div>
                        <div class="toggle-text"><h4>Collection Date Readonly</h4><p>Prevent users from modifying transaction dates</p></div>
                    </label>
                    <label class="switch"><input type="checkbox" name="collection_date_readonly" id="readonlyToggle" <?php echo $settings['collection_date_readonly'] ? 'checked' : ''; ?>><span class="slider"></span></label>
                </div>
            </div>

            <div class="btn-group" id="actionContainer">
                <div class="autosave-status" id="autosaveStatus">
                    <i class="fas fa-check-circle"></i>
                    <span>All changes saved</span>
                </div>
                <a href="dashboard.php" class="btn btn-secondary" id="cancelBtn">Cancel</a>
                <button type="submit" class="btn btn-primary" id="saveBtn" disabled>
                    <div class="btn-spinner"></div>
                    <span class="btn-text">Save Now</span>
                </button>
            </div>
        </form>

        <!-- UPDATED: This section now properly displays the updater's name on initial load -->
        <div id="lastUpdatedText" style="text-align: center; margin-top: 3rem; color: var(--text-muted); font-size: 0.8rem; font-weight: 500;">
            <i class="fas fa-history"></i>
            Last saved: <span id="lastUpdatedTimestamp"><?php echo isset($settings['updated_at']) ? date('F j, Y g:i A', strtotime($settings['updated_at'])) : 'Never'; ?></span>
            <?php if (!empty($settings['updated_by_name'])): ?>
                by <span id="lastUpdatedBy"><?php echo htmlspecialchars($settings['updated_by_name']); ?></span>
            <?php endif; ?>
        </div>
    </main>

    <script>
        // --- DEBOUNCE UTILITY ---
        function debounce(func, delay = 1000) {
            let timeout;
            return function(...args) {
                clearTimeout(timeout);
                timeout = setTimeout(() => func.apply(this, args), delay);
            };
        }

        document.addEventListener('DOMContentLoaded', () => {
            // --- UI & THEME ---
            const userTrigger = document.getElementById('userDropdownTrigger');
            const userDropdown = document.getElementById('userDropdown');
            userTrigger.addEventListener('click', e => { e.stopPropagation(); userDropdown.classList.toggle('show'); userTrigger.setAttribute('aria-expanded', userDropdown.classList.contains('show')); });
            document.addEventListener('click', e => { if (!userTrigger.contains(e.target) && !userDropdown.contains(e.target)) { userDropdown.classList.remove('show'); userTrigger.setAttribute('aria-expanded', 'false'); }});
            const themeToggle = document.getElementById('themeToggle');
            const html = document.documentElement;
            themeToggle.addEventListener('click', () => { const isDark = html.classList.toggle('dark'); html.classList.toggle('light'); localStorage.setItem('theme', isDark ? 'dark' : 'light'); themeToggle.innerHTML = isDark ? '<i class="fas fa-sun"></i>' : '<i class="fas fa-moon"></i>'; });
            if (localStorage.getItem('theme') === 'dark') { html.classList.add('dark'); html.classList.remove('light'); themeToggle.innerHTML = '<i class="fas fa-sun"></i>'; }

            function showToast(message, type = 'success') {
                const container = document.getElementById('toastContainer');
                const toast = document.createElement('div');
                toast.className = `toast ${type}`;
                toast.innerHTML = `<div class="toast-progress"></div><div class="toast-icon"><i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-triangle'}"></i></div><div class="toast-content"><div class="toast-title">${type === 'success' ? 'Success' : 'Error'}</div><div class="toast-message">${message}</div></div><button class="toast-close" onclick="this.parentElement.remove()" aria-label="Close"><i class="fas fa-times"></i></button>`;
                container.appendChild(toast);
                setTimeout(() => toast.classList.add('show'), 10);
                setTimeout(() => { toast.classList.remove('show'); setTimeout(() => toast.remove(), 400); }, 5000);
            }

            // --- FORM INTERACTION LOGIC ---
            const form = document.getElementById('settingsForm');
            const minInput = document.getElementById('minInstallments');
            const maxInput = document.getElementById('maxInstallments');
            const graceInput = document.getElementById('gracePeriod');
            const partialToggle = document.getElementById('partialToggle');
            const overpayToggle = document.getElementById('overpayToggle');
            const readonlyToggle = document.getElementById('readonlyToggle');
            const saveBtn = document.getElementById('saveBtn');
            const cancelBtn = document.getElementById('cancelBtn');
            const autosaveStatusEl = document.getElementById('autosaveStatus');
            
            const originalValues = {
                min: minInput.value, max: maxInput.value, grace: graceInput.value,
                partial: partialToggle.checked, overpay: overpayToggle.checked, readonly: readonlyToggle.checked
            };
            
            let hasChanges = false;
            let isFormValid = true;
            let isSaving = false;

            const statusMessages = {
                unsaved: { icon: 'fa-pen-nib', text: 'Unsaved changes', class: 'status-unsaved' },
                saving: { icon: 'fa-spinner fa-spin', text: 'Saving...', class: 'status-saving' },
                saved: { icon: 'fa-check-circle', text: 'All changes saved', class: 'status-saved' },
                error: { icon: 'fa-exclamation-triangle', text: 'Invalid input', class: 'status-error' }
            };

            function updateAutosaveStatus(statusKey) {
                const status = statusMessages[statusKey];
                autosaveStatusEl.className = `autosave-status show ${status.class}`;
                autosaveStatusEl.innerHTML = `<i class="fas ${status.icon}"></i> <span>${status.text}</span>`;
            }

            function performSave() {
                if (isSaving || !isFormValid || !hasChanges) return;

                isSaving = true;
                updateAutosaveStatus('saving');
                saveBtn.classList.add('loading');
                saveBtn.disabled = true;

                const formData = new FormData(form);
                formData.append('ajax', '1');

                fetch('', { method: 'POST', body: formData })
                    .then(response => response.json())
                    .then(result => {
                        if (result.success) {
                            updateAutosaveStatus('saved');
                            showToast(result.message);
                            
                            originalValues.min = minInput.value;
                            originalValues.max = maxInput.value;
                            originalValues.grace = graceInput.value;
                            originalValues.partial = partialToggle.checked;
                            originalValues.overpay = overpayToggle.checked;
                            originalValues.readonly = readonlyToggle.checked;

                            // UPDATED: This section dynamically updates the 'updated by' name on the page
                            document.getElementById('lastUpdatedTimestamp').textContent = result.updated_at;
                            if (result.updated_by) {
                                let updatedByEl = document.getElementById('lastUpdatedBy');
                                if (!updatedByEl) {
                                    // Creates the 'by ...' element if it doesn't exist
                                    const timestampEl = document.getElementById('lastUpdatedTimestamp');
                                    const textNode = document.createTextNode(' by ');
                                    updatedByEl = document.createElement('span');
                                    updatedByEl.id = 'lastUpdatedBy';
                                    timestampEl.parentNode.appendChild(textNode);
                                    timestampEl.parentNode.appendChild(updatedByEl);
                                }
                                updatedByEl.textContent = result.updated_by;
                            }
                            document.querySelectorAll('.stat-card').forEach(card => card.classList.remove('changed'));
                            updateVisualState();
                        } else {
                            showToast(result.message, 'error');
                            updateAutosaveStatus('unsaved');
                        }
                    })
                    .catch(error => {
                        showToast('Network error. Please try again.', 'error');
                        updateAutosaveStatus('unsaved');
                    })
                    .finally(() => {
                        isSaving = false;
                        saveBtn.classList.remove('loading');
                        updateVisualState();
                    });
            }

            const debouncedAutosave = debounce(performSave, 2000);

            function updateVisualState() {
                hasChanges = minInput.value !== originalValues.min || maxInput.value !== originalValues.max || graceInput.value !== originalValues.grace ||
                             partialToggle.checked !== originalValues.partial || overpayToggle.checked !== originalValues.overpay || readonlyToggle.checked !== originalValues.readonly;
                saveBtn.disabled = !hasChanges || !isFormValid || isSaving;
            }

            function validateInputs() {
                const min = parseInt(minInput.value) || 1;
                const max = parseInt(maxInput.value) || 1;
                const minValEl = document.getElementById('minValidation');
                const maxValEl = document.getElementById('maxValidation');
                
                isFormValid = true;
                minValEl.className = 'validation-msg'; maxValEl.className = 'validation-msg';
                minInput.style.borderColor = ''; maxInput.style.borderColor = '';

                if (min > max) {
                    minValEl.textContent = 'Cannot exceed maximum'; minValEl.className = 'validation-msg error show';
                    maxValEl.textContent = 'Must be > minimum'; maxValEl.className = 'validation-msg error show';
                    minInput.style.borderColor = 'var(--danger)'; maxInput.style.borderColor = 'var(--danger)';
                    isFormValid = false;
                }
                
                if (hasChanges) {
                    updateAutosaveStatus(isFormValid ? 'unsaved' : 'error');
                }
                updateVisualState();
            }

            function updateStats() {
                const min = parseInt(minInput.value) || 1;
                const max = parseInt(maxInput.value) || 1;
                const grace = parseInt(graceInput.value) || 0;
                
                const installmentRangeEl = document.getElementById('installmentRange');
                const newRange = min === max ? min : `${min}-${max}`;
                if (installmentRangeEl.textContent !== newRange) {
                    installmentRangeEl.textContent = newRange;
                }
                document.getElementById('statMinInstall').textContent = min;
                document.getElementById('statMaxInstall').textContent = max;
                
                if (document.getElementById('graceValue').textContent !== String(grace)) {
                    document.getElementById('graceValue').textContent = grace;
                }
                const graceBadge = document.getElementById('statGrace').querySelector('.stat-badge');
                graceBadge.className = `stat-badge ${grace > 0 ? 'active' : 'inactive'}`;
                graceBadge.innerHTML = `<i class="fas fa-${grace > 0 ? 'clock' : 'times'}"></i> ${grace > 0 ? 'Active' : 'None'}`;
                
                const partialBadge = document.getElementById('statPartial');
                partialBadge.className = `stat-badge ${partialToggle.checked ? 'active' : 'inactive'}`;
                partialBadge.innerHTML = `<i class="fas fa-${partialToggle.checked ? 'check' : 'times'}"></i> Partial Payments`;
                
                const overpayBadge = document.getElementById('statOverpay');
                overpayBadge.className = `stat-badge ${overpayToggle.checked ? 'active' : 'inactive'}`;
                overpayBadge.innerHTML = `<i class="fas fa-${overpayToggle.checked ? 'check' : 'times'}"></i> Overpayment`;
            }

            form.addEventListener('input', () => {
                updateVisualState();
                validateInputs();
                updateStats();
                if (isFormValid && hasChanges) {
                    debouncedAutosave();
                }
            });

            form.addEventListener('submit', e => { e.preventDefault(); performSave(); });

            function clampValueOnBlur(e) {
                const input = e.target;
                const min = parseInt(input.min), max = parseInt(input.max);
                let value = parseInt(input.value);
                if (isNaN(value)) value = min;
                if (value < min) input.value = min;
                else if (value > max) input.value = max;
                input.dispatchEvent(new Event('input', { bubbles: true }));
            }
            [minInput, maxInput, graceInput].forEach(input => input.addEventListener('blur', clampValueOnBlur));
            document.querySelectorAll('.toggle-group').forEach(group => {
                const checkbox = group.querySelector('input[type="checkbox"]');
                checkbox.addEventListener('change', () => group.classList.toggle('active', checkbox.checked));
                if (checkbox.checked) group.classList.add('active');
            });
            document.querySelectorAll('.number-control').forEach(control => {
                const input = control.querySelector('input[type="number"]');
                control.querySelector('.minus').addEventListener('click', () => { input.value = (parseInt(input.value) || 0) - 1; input.dispatchEvent(new Event('input', { bubbles: true })); });
                control.querySelector('.plus').addEventListener('click', () => { input.value = (parseInt(input.value) || 0) + 1; input.dispatchEvent(new Event('input', { bubbles: true })); });
            });
            
            window.addEventListener('beforeunload', e => { if (hasChanges) { e.preventDefault(); e.returnValue = ''; }});
            cancelBtn.addEventListener('click', e => { if (hasChanges && !confirm('You have unsaved changes. Are you sure you want to cancel?')) { e.preventDefault(); }});

            // --- INITIALIZE ---
            updateStats();
            validateInputs();
            updateAutosaveStatus('saved'); // Start with a clean state
        });
    </script>
</body>
</html>