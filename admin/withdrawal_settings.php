<?php
session_start();
date_default_timezone_set('Africa/Lagos');

// --- 1. Database Connection (Embedded) ---
define('DB_HOST', 'localhost');
define('DB_NAME', 'cupadnam_db');
define('DB_USER', 'cupadnam_db');
define('DB_PASS', 'f2GrjZQCz8E39nCu9eLg');

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

if ($conn->connect_error) {
    // Return JSON error if this is an AJAX request, otherwise die
    if (isset($_POST['ajax_save'])) {
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database connection failed: ' . $conn->connect_error]);
        exit();
    }
    die("Connection failed: " . $conn->connect_error);
}
$conn->set_charset("utf8mb4");

// --- Security Check ---
// Ensure the user is an admin
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    if (isset($_POST['ajax_save'])) {
        header('Content-Type: application/json');
        http_response_code(403); 
        echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
        exit();
    }
    header('Location: ../index.php');
    exit();
}

$base_path = '../';

// --- 2. Helper Functions ---

function get_withdrawal_settings($conn) {
    // defaults matching the schema
    $defaults = [
        'max_cash_withdrawal' => 50000.00,
        'require_image_for_cash' => 1,
        'allow_weekend_withdrawals' => 0,
        'max_withdrawals_per_day' => 1,
        'blocked_withdrawal_types' => [], // Will be decoded from JSON
        'withdrawal_date_readonly' => 0,
        'buffer_cash' => 0.00,
        'buffer_withdrawal' => 0.00,
        'buffer_return' => 0.00
    ];

    $sql = "SELECT * FROM withdrawal_settings LIMIT 1";
    $result = $conn->query($sql);

    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        
        // Data Type Corrections from DB to PHP
        $row['max_cash_withdrawal'] = (float)$row['max_cash_withdrawal'];
        $row['max_withdrawals_per_day'] = (int)$row['max_withdrawals_per_day'];
        $row['buffer_cash'] = (float)$row['buffer_cash'];
        $row['buffer_withdrawal'] = (float)$row['buffer_withdrawal'];
        $row['buffer_return'] = (float)$row['buffer_return'];
        
        // Decode JSON field
        $blocked = json_decode($row['blocked_withdrawal_types'], true);
        $row['blocked_withdrawal_types'] = is_array($blocked) ? $blocked : [];

        return $row;
    } else {
        // Initialize defaults if table is empty
        $stmt = $conn->prepare("INSERT INTO withdrawal_settings (
            max_cash_withdrawal, require_image_for_cash, allow_weekend_withdrawals, 
            max_withdrawals_per_day, blocked_withdrawal_types, withdrawal_date_readonly, 
            buffer_cash, buffer_withdrawal, buffer_return
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");

        $json_blocked = json_encode($defaults['blocked_withdrawal_types']);
        
        $stmt->bind_param("diiisiddd", 
            $defaults['max_cash_withdrawal'],
            $defaults['require_image_for_cash'],
            $defaults['allow_weekend_withdrawals'],
            $defaults['max_withdrawals_per_day'],
            $json_blocked,
            $defaults['withdrawal_date_readonly'],
            $defaults['buffer_cash'],
            $defaults['buffer_withdrawal'],
            $defaults['buffer_return']
        );
        
        $stmt->execute();
        return $defaults;
    }
}

// --- 3. AJAX Endpoint for Saving ---
if (isset($_POST['ajax_save']) && $_POST['ajax_save'] === 'true') {
    header('Content-Type: application/json');
    
    try {
        $posted_settings = $_POST['withdrawal_settings'] ?? [];
        $validation_errors = [];

        // --- Server-side Validation & Sanitization ---
        $max_cash = str_replace([',', ' '], '', $posted_settings['max_cash_withdrawal'] ?? '50000');
        $max_per_day = str_replace([',', ' '], '', $posted_settings['max_withdrawals_per_day'] ?? '1');
        
        // Buffer Inputs
        $buf_cash = str_replace([',', ' '], '', $posted_settings['buffer_cash'] ?? '0');
        $buf_with = str_replace([',', ' '], '', $posted_settings['buffer_withdrawal'] ?? '0');
        $buf_ret  = str_replace([',', ' '], '', $posted_settings['buffer_return'] ?? '0');

        if (!is_numeric($max_cash) || $max_cash < 1000) {
            $validation_errors['max_cash_withdrawal'] = 'Minimum withdrawal limit is ₦1,000.';
        }
        
        if (!is_numeric($max_per_day) || $max_per_day < 1) {
            $validation_errors['max_withdrawals_per_day'] = 'At least 1 withdrawal per day is required.';
        }
        
        // Validate Buffers (0-100)
        $buffers = [
            'buffer_cash' => $buf_cash, 
            'buffer_withdrawal' => $buf_with, 
            'buffer_return' => $buf_ret
        ];

        foreach($buffers as $key => $val) {
            if (!is_numeric($val) || $val < 0 || $val > 100) {
                $validation_errors[$key] = 'Percentage must be between 0% and 100%.';
            }
        }

        if (!empty($validation_errors)) {
            http_response_code(422); 
            echo json_encode(['success' => false, 'message' => 'Validation failed.', 'errors' => $validation_errors]);
            exit();
        }

        // --- Prepare Data for SQL ---
        $max_cash = (float)$max_cash;
        $max_per_day = (int)$max_per_day;
        $buf_cash = (float)$buf_cash;
        $buf_with = (float)$buf_with;
        $buf_ret = (float)$buf_ret;
        
        // Booleans (Checkboxes)
        $require_image = isset($_POST['require_image_for_cash']) ? 1 : 0;
        $allow_weekend = isset($_POST['allow_weekend_withdrawals']) ? 1 : 0;
        $date_readonly = isset($_POST['withdrawal_date_readonly']) ? 1 : 0;
        
        // Blocked Types Array -> JSON String
        $blocked_raw = $_POST['blocked_withdrawal_types'] ?? [];
        $blocked_json = json_encode($blocked_raw);

        // --- Execute Update ---
        // We assume ID 1 is the configuration row based on schema initialization
        $stmt = $conn->prepare("UPDATE withdrawal_settings SET 
            max_cash_withdrawal = ?,
            max_withdrawals_per_day = ?,
            buffer_cash = ?,
            buffer_withdrawal = ?,
            buffer_return = ?,
            require_image_for_cash = ?,
            allow_weekend_withdrawals = ?,
            withdrawal_date_readonly = ?,
            blocked_withdrawal_types = ?
            WHERE id = 1
        ");

        if (!$stmt) {
            throw new Exception("Database preparation failed: " . $conn->error);
        }

        // Bind Types: d=double, i=int, s=string
        $stmt->bind_param("dddddiiis", 
            $max_cash, 
            $max_per_day, 
            $buf_cash, 
            $buf_with, 
            $buf_ret, 
            $require_image, 
            $allow_weekend, 
            $date_readonly, 
            $blocked_json
        );

        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Settings saved successfully.']);
        } else {
            throw new Exception('Failed to update database: ' . $stmt->error);
        }

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit();
}

// --- Load settings for page display ---
$current_ws = get_withdrawal_settings($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Withdrawal Configuration | Admin</title>
    <link rel="icon" type="image/png" href="<?php echo htmlspecialchars($base_path . 'uploads/CUPAD LOGO.png'); ?>">
    
    <!-- Fonts & Icons -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    
    <style>
        :root {
            /* Light Theme Variables (Red Theme) */
            --primary: #dc2626; /* Red 600 */
            --primary-hover: #b91c1c; /* Red 700 */
            --primary-light: #fef2f2; /* Red 50 */
            
            --bg-body: #f3f4f6;
            --bg-card: #ffffff;
            --text-main: #111827;
            --text-muted: #6b7280;
            --border-color: #e5e7eb;
            --input-bg: #f9fafb;
            
            --success: #10b981;
            --danger: #ef4444;
            
            --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -2px rgba(0, 0, 0, 0.06);
            --radius: 16px;
            --transition: all 0.25s ease;
            --safe-area-bottom: env(safe-area-inset-bottom, 20px);
        }

        html.dark {
            /* Dark Theme Variables (Red Theme) */
            --primary: #ef4444; /* Red 500 */
            --primary-hover: #f87171; /* Red 400 */
            --primary-light: rgba(239, 68, 68, 0.15);
            
            --bg-body: #111827;
            --bg-card: #1f2937;
            --text-main: #f9fafb;
            --text-muted: #9ca3af;
            --border-color: #374151;
            --input-bg: #111827;
            --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.3);
        }

        * { margin: 0; padding: 0; box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
        
        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-body);
            color: var(--text-main);
            transition: background-color 0.3s, color 0.3s;
            min-height: 100vh;
            padding-bottom: calc(80px + var(--safe-area-bottom)); /* Space for fixed footer */
        }

        /* --- Layout --- */
        .container { max-width: 850px; margin: 0 auto; padding: 2rem 1.5rem; }
        
        /* --- Header --- */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
        }
        .header-content h1 { font-size: 1.75rem; font-weight: 700; letter-spacing: -0.025em; line-height: 1.2; }
        .header-content p { color: var(--text-muted); margin-top: 0.25rem; font-size: 0.95rem; }

        /* --- Theme Toggle --- */
        .theme-toggle {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            color: var(--text-main);
            width: 44px;
            height: 44px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: var(--transition);
            box-shadow: var(--shadow);
            font-size: 1.2rem;
            flex-shrink: 0;
        }
        .theme-toggle:active { transform: scale(0.95); }

        /* --- Card Styles --- */
        .settings-card {
            background: var(--bg-card);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            border: 1px solid var(--border-color);
            margin-bottom: 1.5rem;
            overflow: hidden;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        
        .card-header { padding: 1.5rem; border-bottom: 1px solid var(--border-color); display: flex; align-items: flex-start; gap: 1rem; }
        .header-icon { 
            width: 42px; height: 42px; 
            background: var(--primary-light); color: var(--primary); 
            border-radius: 12px; display: flex; align-items: center; justify-content: center; 
            font-size: 1.25rem; flex-shrink: 0;
        }
        .header-text h2 { font-size: 1.125rem; font-weight: 600; margin-bottom: 0.25rem; }
        .header-text p { font-size: 0.85rem; color: var(--text-muted); line-height: 1.4; }

        .card-body { padding: 1.5rem; }

        /* --- Forms --- */
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1.5rem; }
        .form-group { margin-bottom: 0; position: relative; }
        
        label { display: block; font-size: 0.9rem; font-weight: 500; margin-bottom: 0.5rem; color: var(--text-main); }
        
        .input-wrapper { position: relative; }
        .input-wrapper i { position: absolute; left: 1rem; top: 50%; transform: translateY(-50%); color: var(--text-muted); pointer-events: none; }
        
        input[type="number"], input[type="text"] {
            width: 100%;
            padding: 0.875rem 1rem 0.875rem 2.75rem; /* Larger touch target */
            background: var(--input-bg);
            border: 1px solid var(--border-color);
            border-radius: 10px;
            color: var(--text-main);
            font-family: inherit;
            font-size: 1rem;
            transition: var(--transition);
            appearance: none;
        }
        input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px var(--primary-light); }
        input.error { border-color: var(--danger); background: rgba(239, 68, 68, 0.05); }
        .error-msg { color: var(--danger); font-size: 0.8rem; margin-top: 0.4rem; display: none; }

        /* --- Toggle Switch UI --- */
        .toggle-row { display: flex; justify-content: space-between; align-items: center; padding: 1rem 0; border-bottom: 1px solid var(--border-color); cursor: pointer; }
        .toggle-row:last-child { border-bottom: none; padding-bottom: 0; }
        .toggle-row:first-child { padding-top: 0; }
        
        .toggle-info { max-width: 75%; pointer-events: none; } 
        .toggle-info h4 { font-size: 1rem; font-weight: 500; margin-bottom: 2px; }
        .toggle-info p { font-size: 0.85rem; color: var(--text-muted); line-height: 1.3; }

        .switch { position: relative; display: inline-block; width: 52px; height: 28px; flex-shrink: 0; }
        .switch input { opacity: 0; width: 0; height: 0; appearance: none; -webkit-appearance: none; }
        .slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: var(--border-color); transition: .3s; border-radius: 34px; }
        .slider:before { position: absolute; content: ""; height: 22px; width: 22px; left: 3px; bottom: 3px; background-color: white; transition: .3s cubic-bezier(0.4, 0.0, 0.2, 1); border-radius: 50%; box-shadow: 0 2px 4px rgba(0,0,0,0.2); }
        input:checked + .slider { background-color: var(--primary); }
        input:checked + .slider:before { transform: translateX(24px); }

        /* --- Checkbox Cards (Blocked Types) FIX --- */
        .checkbox-grid { display: grid; gap: 0.75rem; }
        .checkbox-card {
            position: relative;
            display: flex;
            align-items: flex-start;
            padding: 1rem;
            background: var(--input-bg);
            border: 1px solid var(--border-color);
            border-radius: 10px;
            cursor: pointer;
            transition: var(--transition);
            user-select: none;
        }
        .checkbox-card:active { transform: scale(0.99); background: var(--bg-body); }
        
        /* The FIX: Aggressively hide the native checkbox input */
        .checkbox-card input[type="checkbox"] {
            position: absolute;
            opacity: 0;
            width: 0;
            height: 0;
            margin: 0;
            padding: 0;
            appearance: none; 
            -webkit-appearance: none;
            pointer-events: none;
            z-index: -1;
        }

        /* Custom Checkmark Circle */
        .checkmark {
            height: 24px; width: 24px; 
            border: 2px solid var(--border-color); 
            border-radius: 50%; 
            margin-right: 1rem; 
            position: relative; flex-shrink: 0;
            transition: var(--transition);
            background: var(--bg-card);
        }
        .checkmark:after {
            content: ""; position: absolute; display: none;
            left: 8px; top: 4px; width: 5px; height: 10px;
            border: solid white; border-width: 0 2px 2px 0;
            transform: rotate(45deg);
        }
        
        /* Selected State */
        .checkbox-card input:checked ~ .checkmark { background-color: var(--primary); border-color: var(--primary); }
        .checkbox-card input:checked ~ .checkmark:after { display: block; }
        .checkbox-card:has(input:checked) { border-color: var(--primary); background: var(--primary-light); }
        
        .cb-content h4 { font-size: 0.95rem; font-weight: 600; color: var(--text-main); }
        .cb-content p { font-size: 0.8rem; color: var(--text-muted); margin-top: 0.2rem; }

        /* --- Footer --- */
        .sticky-footer {
            position: fixed; bottom: 0; left: 0; width: 100%;
            background: var(--bg-card);
            border-top: 1px solid var(--border-color);
            padding: 1rem;
            padding-bottom: calc(1rem + var(--safe-area-bottom));
            display: flex; justify-content: space-between; align-items: center;
            z-index: 100;
            box-shadow: 0 -4px 10px rgba(0,0,0,0.05);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
        }
        
        .btn {
            display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem;
            padding: 0.875rem 1.5rem;
            font-weight: 600; font-size: 1rem;
            border-radius: 10px;
            cursor: pointer; transition: var(--transition);
            text-decoration: none; border: none;
            touch-action: manipulation;
        }
        .btn-secondary { background: transparent; color: var(--text-muted); border: 1px solid var(--border-color); }
        .btn-secondary:active { background: var(--bg-body); color: var(--text-main); }
        
        .btn-primary { background: var(--primary); color: white; box-shadow: 0 4px 6px -1px rgba(220, 38, 38, 0.3); }
        .btn-primary:active { background: var(--primary-hover); transform: translateY(1px); }
        .btn-primary:disabled { opacity: 0.7; cursor: wait; }

        /* --- Status & Toast --- */
        .status-indicator { display: flex; align-items: center; gap: 0.5rem; font-size: 0.8rem; color: var(--text-muted); opacity: 0; transition: opacity 0.3s; }
        .status-indicator.visible { opacity: 1; }
        
        .toast {
            position: fixed; top: 1.5rem; right: 1.5rem;
            background: var(--bg-card); color: var(--text-main);
            padding: 1rem 1.25rem; border-radius: 12px;
            box-shadow: 0 10px 25px -5px rgba(0,0,0,0.15);
            border-left: 4px solid var(--primary);
            display: flex; align-items: center; gap: 1rem;
            z-index: 2000; transform: translateY(-150%); transition: transform 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            max-width: 400px;
        }
        .toast.show { transform: translateY(0); }
        .toast.error { border-left-color: #b91c1c; }
        .toast.success { border-left-color: var(--success); }

        /* Loader */
        .spinner { width: 20px; height: 20px; border: 2px solid rgba(255,255,255,0.3); border-top-color: white; border-radius: 50%; animation: spin 0.8s linear infinite; display: none; }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* --- Mobile Optimization --- */
        @media (max-width: 640px) {
            .container { padding: 1rem; padding-top: 1.5rem; }
            .page-header { flex-direction: row; margin-bottom: 1.5rem; }
            .header-content h1 { font-size: 1.4rem; }
            
            .settings-card { border-radius: 12px; margin-bottom: 1rem; }
            .card-header { padding: 1rem; }
            .header-icon { width: 36px; height: 36px; font-size: 1rem; border-radius: 8px; }
            .header-text h2 { font-size: 1rem; }
            .card-body { padding: 1.25rem 1rem; }

            .form-grid { grid-template-columns: 1fr; gap: 1.25rem; }
            
            .sticky-footer { 
                padding: 0.75rem 1rem; 
                padding-bottom: calc(0.75rem + var(--safe-area-bottom));
                gap: 1rem;
            }
            .footer-actions { flex: 1; display: flex; justify-content: flex-end; align-items: center; gap: 1rem; }
            
            .btn-secondary { padding: 0.875rem; border-radius: 50%; width: 48px; height: 48px; flex-shrink: 0; }
            .btn-secondary span { display: none; }
            .btn-secondary i { font-size: 1.1rem; margin: 0; }
            
            .btn-primary { width: 100%; }
            .toast { top: 1rem; left: 1rem; right: 1rem; width: auto; max-width: none; }
            
            .status-indicator span { display: none; }
            .status-indicator.visible { opacity: 0.7; }
        }
    </style>
</head>
<body>

    <div class="container">
        <!-- Header -->
        <header class="page-header">
            <div class="header-content">
                <h1>Withdrawal Settings</h1>
                <p>Manage limits and security.</p>
            </div>
            <button id="themeToggle" class="theme-toggle" title="Toggle Dark Mode">
                <i class="fas fa-moon"></i>
            </button>
        </header>

        <form id="settingsForm">
            <!-- Hidden input needed for AJAX check -->
            <input type="hidden" name="ajax_save" value="true">

            <!-- 1. Limits Section -->
            <div class="settings-card">
                <div class="card-header">
                    <div class="header-icon"><i class="fas fa-wallet"></i></div>
                    <div class="header-text">
                        <h2>Withdrawal Limits</h2>
                        <p>Set monetary boundaries.</p>
                    </div>
                </div>
                <div class="card-body">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="max_cash_withdrawal">Max Cash Withdrawal (₦)</label>
                            <div class="input-wrapper">
                                <i class="fas fa-money-bill-wave"></i>
                                <input type="text" inputmode="numeric" pattern="[0-9,]*" id="max_cash_withdrawal" name="withdrawal_settings[max_cash_withdrawal]" 
                                       value="<?php echo htmlspecialchars($current_ws['max_cash_withdrawal']); ?>" 
                                       placeholder="50000">
                            </div>
                            <div class="error-msg" data-field="max_cash_withdrawal">Minimum value is 1000.</div>
                        </div>
                        <div class="form-group">
                            <label for="max_withdrawals_per_day">Daily Frequency Limit</label>
                            <div class="input-wrapper">
                                <i class="fas fa-history"></i>
                                <input type="text" inputmode="numeric" pattern="[0-9]*" id="max_withdrawals_per_day" name="withdrawal_settings[max_withdrawals_per_day]" 
                                       value="<?php echo htmlspecialchars($current_ws['max_withdrawals_per_day']); ?>" 
                                       placeholder="1">
                            </div>
                            <div class="error-msg" data-field="max_withdrawals_per_day">Minimum value is 1.</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 2. Buffer Section (Split into 3) -->
            <div class="settings-card">
                <div class="card-header">
                    <div class="header-icon" style="background: rgba(16, 185, 129, 0.1); color: #059669;"><i class="fas fa-percentage"></i></div>
                    <div class="header-text">
                        <h2>Savings Buffer</h2>
                        <p>% of Principal required in savings.</p>
                    </div>
                </div>
                <div class="card-body">
                    <div class="form-grid">
                        <!-- Cash Buffer -->
                        <div class="form-group">
                            <label for="buffer_cash">For Cash Withdrawal (%)</label>
                            <div class="input-wrapper">
                                <i class="fas fa-money-bill"></i>
                                <input type="text" inputmode="numeric" pattern="[0-9]*" id="buffer_cash" name="withdrawal_settings[buffer_cash]" 
                                       value="<?php echo htmlspecialchars($current_ws['buffer_cash'] ?? 10); ?>" 
                                       placeholder="10">
                            </div>
                            <div class="error-msg" data-field="buffer_cash">0-100% required.</div>
                        </div>
                        
                        <!-- Deduction Buffer -->
                        <div class="form-group">
                            <label for="buffer_withdrawal">For Deduction (%)</label>
                            <div class="input-wrapper">
                                <i class="fas fa-file-invoice-dollar"></i>
                                <input type="text" inputmode="numeric" pattern="[0-9]*" id="buffer_withdrawal" name="withdrawal_settings[buffer_withdrawal]" 
                                       value="<?php echo htmlspecialchars($current_ws['buffer_withdrawal'] ?? 10); ?>" 
                                       placeholder="10">
                            </div>
                            <div class="error-msg" data-field="buffer_withdrawal">0-100% required.</div>
                        </div>
                        
                        <!-- Return Buffer -->
                        <div class="form-group">
                            <label for="buffer_return">For Loan Return (%)</label>
                            <div class="input-wrapper">
                                <i class="fas fa-undo"></i>
                                <input type="text" inputmode="numeric" pattern="[0-9]*" id="buffer_return" name="withdrawal_settings[buffer_return]" 
                                       value="<?php echo htmlspecialchars($current_ws['buffer_return'] ?? 10); ?>" 
                                       placeholder="10">
                            </div>
                            <div class="error-msg" data-field="buffer_return">0-100% required.</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 3. Security Section -->
            <div class="settings-card">
                <div class="card-header">
                    <div class="header-icon"><i class="fas fa-shield-alt"></i></div>
                    <div class="header-text">
                        <h2>Security & Workflow</h2>
                        <p>Toggle verification & days.</p>
                    </div>
                </div>
                <div class="card-body">
                    <label class="toggle-row">
                        <div class="toggle-info">
                            <h4>Require Photo Verification</h4>
                            <p>Mandatory image upload for cash withdrawals.</p>
                        </div>
                        <div class="switch">
                            <input type="checkbox" name="require_image_for_cash" 
                                   <?php echo !empty($current_ws['require_image_for_cash']) ? 'checked' : ''; ?>>
                            <span class="slider"></span>
                        </div>
                    </label>

                    <label class="toggle-row">
                        <div class="toggle-info">
                            <h4>Weekend Withdrawals</h4>
                            <p>Allow processing on Sat/Sun.</p>
                        </div>
                        <div class="switch">
                            <input type="checkbox" name="allow_weekend_withdrawals" 
                                   <?php echo !empty($current_ws['allow_weekend_withdrawals']) ? 'checked' : ''; ?>>
                            <span class="slider"></span>
                        </div>
                    </label>

                    <label class="toggle-row">
                        <div class="toggle-info">
                            <h4>Lock Withdrawal Date</h4>
                            <p>Prevent admins from backdating.</p>
                        </div>
                        <div class="switch">
                            <input type="checkbox" name="withdrawal_date_readonly" 
                                   <?php echo !empty($current_ws['withdrawal_date_readonly']) ? 'checked' : ''; ?>>
                            <span class="slider"></span>
                        </div>
                    </label>
                </div>
            </div>

            <!-- 4. Restrictions Section -->
            <div class="settings-card">
                <div class="card-header">
                    <div class="header-icon" style="background: rgba(185, 28, 28, 0.1); color: #991b1b;"><i class="fas fa-ban"></i></div>
                    <div class="header-text">
                        <h2>Blocked Types</h2>
                        <p>Disable specific methods.</p>
                    </div>
                </div>
                <div class="card-body">
                    <div class="checkbox-grid">
                        <?php 
                        $types = [
                            'cash' => ['label' => 'Manual Cash Payout', 'desc' => 'Physical cash handling.'],
                            'withdrawal' => ['label' => 'Standard Deduction', 'desc' => 'Automatic balance deduction.'],
                            'return' => ['label' => 'Loan Repayment', 'desc' => 'Payoff loan with savings.']
                        ];
                        $blocked = $current_ws['blocked_withdrawal_types'] ?? [];
                        foreach ($types as $key => $data):
                        ?>
                        <label class="checkbox-card">
                            <input type="checkbox" name="blocked_withdrawal_types[]" value="<?php echo $key; ?>" 
                                   <?php echo in_array($key, $blocked) ? 'checked' : ''; ?>>
                            <span class="checkmark"></span>
                            <div class="cb-content">
                                <h4><?php echo htmlspecialchars($data['label']); ?></h4>
                                <p><?php echo htmlspecialchars($data['desc']); ?></p>
                            </div>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Footer -->
            <div class="sticky-footer">
                <a href="<?php echo $base_path; ?>admin/dashboard.php" class="btn btn-secondary" title="Back to Dashboard">
                    <i class="fas fa-arrow-left"></i> <span>Back</span>
                </a>
                
                <div class="footer-actions">
                    <div id="statusIndicator" class="status-indicator">
                        <i class="fas fa-circle-notch fa-spin"></i> <span>Saving...</span>
                    </div>
                    <button type="submit" class="btn btn-primary" id="saveBtn">
                        <span class="spinner"></span>
                        <span class="btn-text">Save Changes</span>
                    </button>
                </div>
            </div>
        </form>
    </div>

    <!-- Toast Notification Container -->
    <div id="toast" class="toast">
        <i class="fas fa-check-circle"></i>
        <span class="toast-msg">Settings saved successfully</span>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const form = document.getElementById('settingsForm');
            const saveBtn = document.getElementById('saveBtn');
            const statusInd = document.getElementById('statusIndicator');
            const toast = document.getElementById('toast');
            
            // --- Dark Mode Logic ---
            const themeToggle = document.getElementById('themeToggle');
            const icon = themeToggle.querySelector('i');
            
            function setTheme(isDark) {
                if (isDark) {
                    document.documentElement.classList.add('dark');
                    icon.className = 'fas fa-sun';
                    localStorage.theme = 'dark';
                } else {
                    document.documentElement.classList.remove('dark');
                    icon.className = 'fas fa-moon';
                    localStorage.theme = 'light';
                }
            }

            // Init Theme
            if (localStorage.theme === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                setTheme(true);
            } else {
                setTheme(false);
            }

            themeToggle.addEventListener('click', () => {
                const isDark = document.documentElement.classList.contains('dark');
                setTheme(!isDark);
            });

            // --- Autosave & Manual Save Logic ---
            let autoSaveTimer;
            const inputs = form.querySelectorAll('input');
            
            // Function to handle UI updates
            const showStatus = (state, msg) => {
                statusInd.classList.add('visible');
                const i = statusInd.querySelector('i');
                const span = statusInd.querySelector('span');
                
                if (state === 'saving') {
                    i.className = 'fas fa-circle-notch fa-spin';
                    statusInd.style.color = 'var(--text-muted)';
                } else if (state === 'saved') {
                    i.className = 'fas fa-check';
                    statusInd.style.color = 'var(--success)';
                } else if (state === 'error') {
                    i.className = 'fas fa-exclamation-triangle';
                    statusInd.style.color = 'var(--danger)';
                }
                if(span) span.textContent = msg;
                
                if (state !== 'saving') {
                    setTimeout(() => statusInd.classList.remove('visible'), 3000);
                }
            };

            const showToast = (msg, type = 'success') => {
                toast.querySelector('.toast-msg').textContent = msg;
                toast.className = `toast show ${type}`;
                const iconClass = type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle';
                toast.querySelector('i').className = `fas ${iconClass}`;
                
                setTimeout(() => {
                    toast.classList.remove('show');
                }, 4000);
            };

            const saveSettings = async (isManual = false) => {
                // Clear previous errors
                document.querySelectorAll('.error-msg').forEach(e => e.style.display = 'none');
                document.querySelectorAll('input').forEach(i => i.classList.remove('error'));

                if(isManual) {
                    saveBtn.disabled = true;
                    saveBtn.querySelector('.spinner').style.display = 'inline-block';
                    saveBtn.querySelector('.btn-text').textContent = 'Saving...';
                } else {
                    showStatus('saving', 'Autosaving...');
                }

                try {
                    const formData = new FormData(form);
                    const response = await fetch(window.location.href, {
                        method: 'POST',
                        body: formData
                    });
                    
                    const result = await response.json();

                    // FIX: Handle 422 Validation Errors Gracefully
                    if (response.status === 422) {
                        if (result.errors) {
                            for (const [key, message] of Object.entries(result.errors)) {
                                // Match PHP naming convention: withdrawal_settings[key]
                                const inputName = `withdrawal_settings[${key}]`;
                                const input = form.querySelector(`[name="${inputName}"]`);
                                
                                if (input) {
                                    input.classList.add('error');
                                    const errorDiv = input.closest('.form-group').querySelector('.error-msg');
                                    if (errorDiv) {
                                        errorDiv.textContent = message;
                                        errorDiv.style.display = 'block';
                                    }
                                }
                            }
                        }
                        throw new Error(result.message || 'Validation failed.');
                    }

                    if (!response.ok) throw new Error(result.message || 'Error occurred');

                    if (isManual) showToast(result.message);
                    showStatus('saved', 'All changes saved');

                } catch (error) {
                    console.error(error);
                    if (isManual) showToast(error.message, 'error');
                    showStatus('error', 'Save failed');
                } finally {
                    if(isManual) {
                        saveBtn.disabled = false;
                        saveBtn.querySelector('.spinner').style.display = 'none';
                        saveBtn.querySelector('.btn-text').textContent = 'Save Changes';
                    }
                }
            };

            // Event Listeners for Autosave
            inputs.forEach(input => {
                input.addEventListener('change', () => {
                    // Immediate save for toggles/checkboxes
                    if(input.type === 'checkbox') {
                        saveSettings();
                    } else {
                        // Debounce for text inputs
                        clearTimeout(autoSaveTimer);
                        autoSaveTimer = setTimeout(() => saveSettings(), 1000);
                    }
                });
                
                // Also trigger on keyup for text inputs
                if(input.type !== 'checkbox') {
                    input.addEventListener('input', () => {
                        showStatus('saving', 'Typing...'); // Visual feedback only
                        clearTimeout(autoSaveTimer);
                        autoSaveTimer = setTimeout(() => saveSettings(), 1000);
                    });
                }
            });

            // Manual Submit
            form.addEventListener('submit', (e) => {
                e.preventDefault();
                clearTimeout(autoSaveTimer); // Cancel pending autosaves
                saveSettings(true);
            });
        });
    </script>
</body>
</html>