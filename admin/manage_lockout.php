<?php
session_start();

// --- 1. CONFIGURATION & DATABASE ---
define('DB_HOST', 'localhost');
define('DB_NAME', 'cupadnam_db');
define('DB_USER', 'cupadnam_db');
define('DB_PASS', 'f2GrjZQCz8E39nCu9eLg');
define('DB_CHARSET', 'utf8mb4');

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Database Connection Failed: " . $e->getMessage());
}

// --- Security Check ---
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: ../index.php');
    exit;
}

// --- UserLockoutManager Class (SQL Version) ---
class UserLockoutManager {
    private $pdo;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    public function unlockUser($username) {
        // Clear failed attempts and reset lockout time
        $stmt = $this->pdo->prepare("UPDATE users SET lockout_until = NULL, failed_login_attempts = '[]' WHERE username = ?");
        $stmt->execute([$username]);
        return $stmt->rowCount() > 0 ? 'unlocked' : 'was_not_locked';
    }
    
    public function lockUser($username, $lockout_until_timestamp) {
        // Convert timestamp to MySQL datetime format
        $lockout_datetime = date('Y-m-d H:i:s', $lockout_until_timestamp);
        
        $stmt = $this->pdo->prepare("UPDATE users SET lockout_until = ? WHERE username = ?");
        $stmt->execute([$lockout_datetime, $username]);
        return $stmt->rowCount() > 0;
    }
}

$lockout_manager = new UserLockoutManager($pdo);
$full_name = $_SESSION['full_name'] ?? 'Admin';
$username = $_SESSION['username'] ?? 'admin';
$user_role = $_SESSION['user_role'] ?? 'admin';
$base_path = '../';

// Profile Pic Logic (Fetch from DB)
$stmt = $pdo->prepare("SELECT profile_pic FROM users WHERE username = ?");
$stmt->execute([$username]);
$current_user_data = $stmt->fetch();
$profile_pic_path = '';
$has_profile_pic = false;

if ($current_user_data && !empty($current_user_data['profile_pic']) && file_exists($base_path . $current_user_data['profile_pic'])) {
    $profile_pic_path = $base_path . $current_user_data['profile_pic'];
    $has_profile_pic = true;
}

// --- Data Fetching ---
function fetch_locked_users_data($pdo) {
    // Fetch users where lockout_until is in the future
    $stmt = $pdo->query("SELECT username, full_name as name, lockout_until FROM users WHERE lockout_until > NOW()");
    return $stmt->fetchAll();
}

function fetch_all_users_data($pdo) {
    $stmt = $pdo->query("SELECT username, full_name as name FROM users ORDER BY username ASC");
    return $stmt->fetchAll();
}

// --- AJAX Handlers ---
if (isset($_SERVER['HTTP_X_FETCH_LOCKED_USERS'])) {
    header('Content-Type: application/json');
    $locked_users = fetch_locked_users_data($pdo);
    $html = '';
    
    foreach ($locked_users as $user) {
        $name = htmlspecialchars($user['name'] ?? 'N/A');
        $u_name = htmlspecialchars($user['username']);
        // Convert SQL datetime to timestamp for JS
        $time = strtotime($user['lockout_until']);
        
        $html .= "
        <div class='action-card locked-card' data-username='{$u_name}'>
            <div class='ac-icon bg-rose'><i class='fas fa-user-lock'></i></div>
            <div class='ac-text'>
                <h3>{$name} <span style='font-weight:400; font-size:0.8rem; opacity:0.7'>@{$u_name}</span></h3>
                <p class='lockout-timer' data-timestamp='{$time}'><i class='fas fa-clock'></i> Calculating...</p>
            </div>
            <button class='icon-btn' onclick=\"performAction('unlock', '{$u_name}')\" title='Unlock User' style='color:var(--success); border:1px solid var(--border)'>
                <i class='fas fa-unlock'></i>
            </button>
        </div>";
    }
    echo json_encode(['html' => $html, 'count' => count($locked_users)]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $post_user = trim($_POST['username'] ?? '');
    $action = $_POST['action'] ?? '';
    $duration = intval($_POST['duration'] ?? 300);

    // Check if user exists
    $stmt = $pdo->prepare("SELECT username FROM users WHERE username = ?");
    $stmt->execute([$post_user]);
    $target = $stmt->fetch();

    if (!$target) { 
        echo json_encode(['message'=>'User not found', 'type'=>'error']); 
        exit; 
    }
    
    // --- PROTECTION LOGIC ---
    // Protect Root Admin 'Daveskhyle'
    if ($target['username'] === 'Daveskhyle') { 
        echo json_encode(['message'=>'Cannot modify the Root Administrator.', 'type'=>'error']); 
        exit; 
    }

    if ($action === 'lock') {
        $lockout_manager->lockUser($post_user, time() + $duration);
        $msg = "User locked successfully."; $type = "success";
    } else {
        $lockout_manager->unlockUser($post_user);
        $msg = "User unlocked successfully."; $type = "success";
    }
    echo json_encode(['message'=>$msg, 'type'=>$type]);
    exit;
}

$locked_out_users = fetch_locked_users_data($pdo);
$all_users_data = fetch_all_users_data($pdo);
?>
<!DOCTYPE html>
<html lang="en" class="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lockout Manager - CUPAD</title>
    <link rel="icon" type="image/png" href="../uploads/CUPAD LOGO.png">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />
    <style>
        /* --- DASHBOARD VARIABLES (SYNCED) --- */
        :root {
            --primary: #2563eb; --primary-dark: #1d4ed8;
            --success: #059669; --warning: #d97706; --danger: #dc2626; --info: #0284c7;
            --bg-body: #f1f5f9; --bg-surface: #ffffff;
            --text-main: #0f172a; --text-muted: #64748b;
            --border: #e2e8f0; --radius-lg: 16px; --radius-md: 10px;
            --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
            --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1);
            --nav-height: 70px;
        }

        html.dark {
            --bg-body: #0f172a; --bg-surface: #1e293b;
            --text-main: #f8fafc; --text-muted: #94a3b8;
            --border: #334155; --primary: #3b82f6; 
            --success: #34d399; --warning: #fbbf24; --danger: #f87171;
        }

        * { box-sizing: border-box; outline: none; }
        body { font-family: 'Plus Jakarta Sans', sans-serif; background: var(--bg-body); color: var(--text-main); margin: 0; padding-top: var(--nav-height); transition: 0.3s; }
        a { text-decoration: none; color: inherit; }

        /* HEADER */
        .main-header { position: fixed; top: 0; left: 0; right: 0; height: var(--nav-height); background: rgba(255,255,255,0.85); backdrop-filter: blur(10px); border-bottom: 1px solid var(--border); z-index: 50; }
        html.dark .main-header { background: rgba(15, 23, 42, 0.85); }
        .navbar { max-width: 1400px; margin: 0 auto; padding: 0 1.5rem; display: flex; align-items: center; justify-content: space-between; height: 100%; }
        .logo { display: flex; align-items: center; gap: 0.75rem; font-weight: 800; font-size: 1.35rem; color: var(--primary); }
        .logo img { height: 38px; }
        .nav-right { display: flex; align-items: center; gap: 1rem; }
        
        .icon-btn { width: 36px; height: 36px; border-radius: 50%; border: none; background: transparent; color: var(--text-muted); cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; transition: 0.2s; }
        .icon-btn:hover { background: var(--bg-body); color: var(--text-main); }

        /* User Dropdown */
        .user-dropdown-wrap { position: relative; }
        .user-pill { display: flex; align-items: center; gap: 0.75rem; padding: 4px 8px 4px 4px; border: 1px solid var(--border); border-radius: 99px; background: var(--bg-surface); cursor: pointer; transition: all 0.2s; }
        .user-pill:hover { border-color: var(--primary); }
        .user-avatar { width: 34px; height: 34px; border-radius: 50%; object-fit: cover; }
        .user-avatar-fallback { width: 34px; height: 34px; border-radius: 50%; background: var(--bg-body); color: var(--text-muted); display: flex; align-items: center; justify-content: center; font-size: 1rem; border: 1px solid var(--border); }
        .user-info { display: flex; flex-direction: column; line-height: 1.1; padding-right: 0.5rem; }
        .user-name { font-weight: 600; font-size: 0.85rem; }
        .user-role { font-size: 0.7rem; color: var(--text-muted); text-transform: uppercase; }
        
        .dropdown-menu { position: absolute; top: 120%; right: 0; background: var(--bg-surface); border: 1px solid var(--border); border-radius: var(--radius-md); box-shadow: var(--shadow-md); min-width: 200px; display: none; z-index: 1000; flex-direction: column; overflow: hidden; animation: fadeIn 0.2s ease; }
        .dropdown-menu.show { display: flex; }
        .dropdown-item { padding: 0.75rem 1rem; display: flex; align-items: center; gap: 0.75rem; font-size: 0.9rem; transition: 0.2s; }
        .dropdown-item:hover { background: var(--bg-body); color: var(--primary); }
        .text-danger { color: var(--danger) !important; }

        /* LAYOUT & CARDS */
        .container { max-width: 1200px; margin: 0 auto; padding: 2rem 1.5rem; }
        
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; }
        .page-title { font-weight: 700; font-size: 1.5rem; margin: 0; display: flex; align-items: center; gap: 0.75rem; }
        
        .layout-grid { display: grid; grid-template-columns: 1fr 1.2fr; gap: 2rem; }
        @media(max-width: 768px) { .layout-grid { grid-template-columns: 1fr; } }

        .card { background: var(--bg-surface); border: 1px solid var(--border); border-radius: var(--radius-lg); padding: 1.5rem; box-shadow: var(--shadow-sm); display: flex; flex-direction: column; height: 100%; }
        .card-title { font-size: 1.1rem; font-weight: 700; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.5rem; border-bottom: 1px solid var(--border); padding-bottom: 1rem; }

        /* FORM ELEMENTS */
        .search-wrapper { position: relative; margin-bottom: 1rem; }
        .search-wrapper i { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--text-muted); font-size: 0.85rem; }
        .form-input { width: 100%; padding: 0.7rem 1rem 0.7rem 2.4rem; border-radius: 99px; border: 1px solid var(--border); background: var(--bg-body); color: var(--text-main); font-size: 0.95rem; }
        .form-input:focus { border-color: var(--primary); outline: none; box-shadow: 0 0 0 3px rgba(37,99,235,0.1); }
        
        .form-select { width: 100%; padding: 0.7rem 1rem; border-radius: 12px; border: 1px solid var(--border); background: var(--bg-body); color: var(--text-main); font-size: 0.9rem; margin-bottom: 1rem; cursor: pointer; }

        .btn { width: 100%; padding: 0.75rem; border-radius: 99px; border: none; font-weight: 600; cursor: pointer; transition: 0.2s; display: flex; align-items: center; justify-content: center; gap: 0.5rem; font-size: 0.9rem; margin-top: 0.5rem; }
        .btn-danger { background: var(--danger); color: white; }
        .btn-danger:hover { background: #b91c1c; }
        .btn-success { background: var(--success); color: white; }
        .btn-success:hover { background: #047857; }
        .btn:disabled { opacity: 0.5; cursor: not-allowed; }

        /* ACTION CARDS (Locked List) */
        .action-card { display: flex; gap: 1rem; padding: 1rem; background: var(--bg-body); border: 1px solid var(--border); border-radius: 12px; transition: 0.2s; align-items: center; margin-bottom: 0.75rem; }
        .action-card:hover { border-color: var(--primary); background: var(--bg-surface); transform: translateY(-2px); }
        .ac-icon { width: 40px; height: 40px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; flex-shrink: 0; }
        .ac-text { flex: 1; }
        .ac-text h3 { margin: 0 0 0.2rem; font-size: 0.95rem; font-weight: 700; }
        .ac-text p { margin: 0; font-size: 0.75rem; color: var(--text-muted); }
        
        .bg-rose { background: #ffe4e6; color: #e11d48; }
        html.dark .bg-rose { background: rgba(225,29,72,0.15); color: #fb7185; }

        .lockout-timer { color: var(--danger); font-weight: 600; display: flex; align-items: center; gap: 0.4rem; }

        .empty-state { text-align: center; padding: 3rem 1rem; color: var(--text-muted); }
        .btn-back { padding: 0.6rem 1.2rem; border-radius: 99px; border: 1px solid var(--border); background: var(--bg-surface); color: var(--text-main); font-size: 0.85rem; cursor: pointer; transition: 0.2s; display: flex; align-items: center; gap: 0.5rem; text-decoration: none; font-weight: 600; }
        .btn-back:hover { border-color: var(--primary); color: var(--primary); }

        /* TOAST */
        .toast-container { position: fixed; top: 90px; right: 20px; z-index: 100; display: flex; flex-direction: column; gap: 10px; }
        .toast { background: var(--bg-surface); color: var(--text-main); padding: 1rem; border-radius: var(--radius-md); box-shadow: var(--shadow-md); border-left: 4px solid var(--primary); min-width: 250px; animation: slideIn 0.3s; display: flex; align-items: center; gap: 0.75rem; font-size: 0.9rem; }
        .toast.success { border-color: var(--success); } .toast.error { border-color: var(--danger); }
        @keyframes slideIn { from { opacity: 0; transform: translateX(50px); } to { opacity: 1; transform: translateX(0); } }
    </style>
</head>
<body>

    <!-- Header -->
    <header class="main-header">
        <div class="navbar">
            <a href="dashboard.php" class="logo">
                <img src="<?php echo htmlspecialchars($base_path . 'uploads/CUPAD LOGO.png'); ?>" alt="Logo">
                <span>CUPAD</span>
            </a>
            <div class="nav-right">
                <button class="icon-btn" id="themeToggle"><i class="fas fa-moon"></i></button>
                
                <div class="user-dropdown-wrap">
                    <div class="user-pill" id="userDropdownTrigger">
                        <?php if($has_profile_pic): ?>
                            <img src="<?php echo htmlspecialchars($profile_pic_path); ?>" class="user-avatar" alt="User">
                        <?php else: ?>
                            <div class="user-avatar-fallback"><i class="fas fa-user"></i></div>
                        <?php endif; ?>
                        <div class="user-info">
                            <span class="user-name"><?php echo htmlspecialchars($full_name); ?></span>
                            <span class="user-role"><?php echo ucfirst(htmlspecialchars($user_role)); ?></span>
                        </div>
                        <i class="fas fa-chevron-down" style="font-size:0.7rem; color:var(--text-muted)"></i>
                    </div>
                    <div class="dropdown-menu" id="userDropdown">
                        <a href="profile.php" class="dropdown-item"><i class="fas fa-user-circle" style="color:var(--primary)"></i> My Profile</a>
                        <div style="height:1px; background:var(--border); margin:0"></div>
                        <a href="../logout.php" class="dropdown-item text-danger"><i class="fas fa-sign-out-alt"></i> Logout</a>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <main class="container">
        <!-- Page Title -->
        <div class="page-header">
            <h1 class="page-title">
                <span class="icon-btn" style="background:var(--bg-surface); width:42px; height:42px; cursor:default"><i class="fas fa-user-lock text-muted"></i></span> 
                Lockout Manager
            </h1>
            <a href="dashboard.php" class="btn-back"><i class="fas fa-arrow-left"></i> Dashboard</a>
        </div>

        <div class="layout-grid">
            
            <!-- Left: Manual Control -->
            <div class="card">
                <div class="card-title"><i class="fas fa-sliders-h" style="color:var(--primary)"></i> Manual Controls</div>
                
                <div style="flex:1">
                    <label style="display:block; margin-bottom:0.5rem; font-weight:600; font-size:0.9rem">Select User</label>
                    <div class="search-wrapper">
                        <i class="fas fa-search"></i>
                        <input list="userList" id="userInput" class="form-input" placeholder="Search any user..." autocomplete="off">
                        <datalist id="userList">
                            <?php foreach ($all_users_data as $u): ?>
                                <?php if($u['username'] !== 'Daveskhyle'): ?>
                                <option value="<?php echo htmlspecialchars($u['username']); ?>"><?php echo htmlspecialchars($u['name'] ?? ''); ?></option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </datalist>
                    </div>

                    <div id="durationGroup">
                        <label style="display:block; margin-bottom:0.5rem; font-weight:600; font-size:0.9rem">Lockout Duration</label>
                        <select id="durationSelect" class="form-select">
                            <option value="300">5 Minutes</option>
                            <option value="1800">30 Minutes</option>
                            <option value="3600">1 Hour</option>
                            <option value="86400">24 Hours</option>
                        </select>
                    </div>
                </div>

                <div style="margin-top:1.5rem">
                    <button id="btnLock" class="btn btn-danger" disabled><i class="fas fa-lock"></i> Lock User</button>
                    <button id="btnUnlock" class="btn btn-success" disabled style="display:none"><i class="fas fa-unlock"></i> Unlock User</button>
                </div>
            </div>

            <!-- Right: Active Lockouts -->
            <div class="card">
                <div class="card-title">
                    <div style="display:flex; align-items:center; gap:0.5rem">
                        <i class="fas fa-list-ul" style="color:var(--danger)"></i> Active Lockouts
                    </div>
                    <span style="font-size:0.85rem; color:var(--text-muted); background:var(--bg-body); padding:2px 8px; border-radius:6px" id="countBadge">
                        <?php echo count($locked_out_users); ?>
                    </span>
                </div>

                <div id="cardList" style="flex:1; overflow-y:auto; max-height:500px">
                    <?php if (empty($locked_out_users)): ?>
                        <div class="empty-state">
                            <i class="fas fa-shield-alt" style="font-size:3rem; color:var(--border); margin-bottom:1rem"></i>
                            <p>No users are currently locked out.</p>
                        </div>
                    <?php else: 
                        foreach ($locked_out_users as $user) {
                            $name = htmlspecialchars($user['name'] ?? 'N/A');
                            $u_name = htmlspecialchars($user['username']);
                            $time = strtotime($user['lockout_until']);
                            echo "
                            <div class='action-card locked-card' data-username='{$u_name}'>
                                <div class='ac-icon bg-rose'><i class='fas fa-user-lock'></i></div>
                                <div class='ac-text'>
                                    <h3>{$name} <span style='font-weight:400; font-size:0.8rem; opacity:0.7'>@{$u_name}</span></h3>
                                    <p class='lockout-timer' data-timestamp='{$time}'><i class='fas fa-clock'></i> Calculating...</p>
                                </div>
                                <button class='icon-btn' onclick=\"performAction('unlock', '{$u_name}')\" title='Unlock User' style='color:var(--success); border:1px solid var(--border)'>
                                    <i class='fas fa-unlock'></i>
                                </button>
                            </div>";
                        }
                    endif; ?>
                </div>
            </div>

        </div>
    </main>

    <div id="toastContainer" class="toast-container"></div>

    <script>
        const $ = (id) => document.getElementById(id);

        // Header & Theme
        const themeBtn = $('themeToggle');
        const html = document.documentElement;
        if(localStorage.getItem('theme') === 'dark') { html.classList.add('dark'); themeBtn.innerHTML = '<i class="fas fa-sun"></i>'; }
        else { html.classList.add('light'); themeBtn.innerHTML = '<i class="fas fa-moon"></i>'; }

        themeBtn.addEventListener('click', () => {
            const isDark = html.classList.toggle('dark');
            html.classList.toggle('light');
            localStorage.setItem('theme', isDark ? 'dark' : 'light');
            themeBtn.innerHTML = isDark ? '<i class="fas fa-sun"></i>' : '<i class="fas fa-moon"></i>';
        });

        const userTrigger = $('userDropdownTrigger');
        const userDropdown = $('userDropdown');
        userTrigger.addEventListener('click', (e) => { e.stopPropagation(); userDropdown.classList.toggle('show'); });
        document.addEventListener('click', (e) => { if (!userTrigger.contains(e.target)) userDropdown.classList.remove('show'); });

        // Logic
        const lockedUsernames = new Set([<?php foreach($locked_out_users as $u) echo "'".htmlspecialchars($u['username'])."',"; ?>]);
        const userInput = $('userInput');
        const btnLock = $('btnLock');
        const btnUnlock = $('btnUnlock');
        const durationGroup = $('durationGroup');

        userInput.addEventListener('input', () => {
            const val = userInput.value.trim();
            const options = $('userList').options;
            let isValid = false;
            for (let i=0; i<options.length; i++) if (options[i].value === val) isValid = true;

            if(!isValid) { 
                btnLock.disabled = true; btnUnlock.style.display = 'none'; btnLock.style.display = 'flex';
                return; 
            }

            const isLocked = lockedUsernames.has(val);
            if(isLocked) {
                btnLock.style.display = 'none';
                btnUnlock.style.display = 'flex';
                btnUnlock.disabled = false;
                durationGroup.style.opacity = '0.5';
                durationGroup.style.pointerEvents = 'none';
            } else {
                btnLock.style.display = 'flex';
                btnUnlock.style.display = 'none';
                btnLock.disabled = false;
                durationGroup.style.opacity = '1';
                durationGroup.style.pointerEvents = 'auto';
            }
        });

        async function performAction(action, username = null) {
            const user = username || userInput.value.trim();
            const duration = $('durationSelect').value;
            const btn = action === 'lock' ? btnLock : btnUnlock;
            
            if(!username) { btn.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i> Processing...'; btn.disabled = true; }

            try {
                const fd = new FormData();
                fd.append('username', user); fd.append('action', action);
                if(action === 'lock') fd.append('duration', duration);

                const res = await fetch(window.location.href, { method: 'POST', body: fd });
                const data = await res.json();
                
                showToast(data.message, data.type);
                if(data.type === 'success') await fetchRealtimeList();
            } catch(e) { showToast('Connection error', 'error'); } 
            finally {
                if(!username) {
                    btn.innerHTML = action === 'lock' ? '<i class="fas fa-lock"></i> Lock User' : '<i class="fas fa-unlock"></i> Unlock User';
                    userInput.value = ''; userInput.dispatchEvent(new Event('input'));
                }
            }
        }

        $('btnLock').onclick = () => performAction('lock');
        $('btnUnlock').onclick = () => performAction('unlock');

        async function fetchRealtimeList() {
            const res = await fetch(window.location.href, { headers: {'X-FETCH-LOCKED-USERS': '1'} });
            const data = await res.json();
            
            const list = $('cardList');
            if(data.count === 0) list.innerHTML = `<div class="empty-state"><i class="fas fa-shield-alt" style="font-size:3rem;color:var(--border);margin-bottom:1rem"></i><p>No users are currently locked out.</p></div>`;
            else list.innerHTML = data.html;
            
            $('countBadge').innerText = data.count;
            lockedUsernames.clear();
            list.querySelectorAll('.locked-card').forEach(c => lockedUsernames.add(c.dataset.username));
        }

        setInterval(() => {
            const now = Math.floor(Date.now() / 1000);
            document.querySelectorAll('.lockout-timer').forEach(el => {
                const ts = parseInt(el.dataset.timestamp);
                if(!ts) return;
                const left = ts - now;
                if(left <= 0) {
                    el.innerHTML = '<span style="color:var(--success)">Auto-Unlocked</span>';
                    setTimeout(fetchRealtimeList, 1500);
                } else {
                    const m = Math.floor(left / 60);
                    const s = left % 60;
                    el.innerHTML = `<i class="fas fa-clock"></i> ${m}m ${s}s remaining`;
                }
            });
        }, 1000);

        function showToast(msg, type) {
            const d = document.createElement('div');
            d.className = `toast ${type}`;
            d.innerHTML = `<i class="fas fa-${type==='success'?'check-circle':'exclamation-triangle'}"></i> ${msg}`;
            $('toastContainer').appendChild(d);
            setTimeout(()=>d.style.animation='none', 300);
            setTimeout(()=>d.remove(), 3000);
        }
    </script>
</body>
</html>