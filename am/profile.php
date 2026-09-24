<?php
// co/profile.php - MySQL Version
session_start();
require_once '../includes/config.php';
$pdo = getDbConnection();

// BASE CONFIGURATION
$base_path = '../';
$page_title = "User Profile";

// --- SECURITY CHECK ---
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'am') {
    header('Location: ' . $base_path . 'index.php');
    exit();
}

$username = $_SESSION['username'] ?? '';

// --- FETCH USER DATA ---
// We join with location tables to get names instead of IDs for display
$query = "SELECT u.*, 
                 b.name as branch_name, 
                 a.name as area_name, 
                 z.name as zone_name 
          FROM users u
          LEFT JOIN branches b ON u.branch_id = b.id
          LEFT JOIN areas a ON u.area_id = a.id
          LEFT JOIN zones z ON u.zone_id = z.id
          WHERE u.username = ?";

$stmt = $pdo->prepare($query);
$stmt->execute([$username]);
$user_data = $stmt->fetch();

if (!$user_data) {
    // Should not happen if session is valid, but handle safely
    session_destroy();
    header('Location: ../index.php');
    exit();
}

// Prepare Display Data
$full_name = $user_data['full_name'] ?? $user_data['name'] ?? 'User';
$email = $user_data['email'] ?? '';
$role = strtoupper($user_data['role'] ?? 'CO');
$branch_name = $user_data['branch_name'] ?? 'Unassigned';
$profile_pic_filename = $user_data['profile_pic'] ?? 'default_avatar.png';
$profile_pic_path = $base_path . $profile_pic_filename;
$has_valid_pic = file_exists($profile_pic_path) && strpos($profile_pic_filename, 'default') === false;

// Decode failed logins (stored as JSON in DB)
$failed_logins = json_decode($user_data['failed_login_attempts'] ?? '[]', true);
$failed_count = is_array($failed_logins) ? count($failed_logins) : 0;

// --- FORM SUBMISSION HANDLING ---
$is_ajax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
$response_data = ['success' => false, 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // 1. UPDATE PROFILE DETAILS
    if (isset($_POST['form_action']) && $_POST['form_action'] === 'update_profile') {
        $new_full_name = htmlspecialchars(trim($_POST['fullName']));
        $new_email = filter_var(trim($_POST['email']), FILTER_SANITIZE_EMAIL);
        $field_errors = [];

        if (empty($new_full_name) || strlen($new_full_name) < 2) $field_errors['fullName'] = 'Invalid name.';
        if (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) $field_errors['email'] = 'Invalid email.';

        if (empty($field_errors)) {
            // Handle Image Upload
            $new_filename = $profile_pic_filename; // Default to existing
            
            if (isset($_FILES['settings_profile_picture']) && $_FILES['settings_profile_picture']['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES['settings_profile_picture'];
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                $allowed = ['jpeg', 'jpg', 'png', 'gif', 'webp'];
                
                if (in_array($ext, $allowed) && $file['size'] <= 5 * 1024 * 1024) {
                    // Create unique name: username_timestamp.ext
                    $upload_name = $username . '_' . time() . '.' . $ext;
                    $target_dir = $base_path . 'uploads/';
                    
                    if (!is_dir($target_dir)) mkdir($target_dir, 0755, true);
                    
                    if (move_uploaded_file($file['tmp_name'], $target_dir . $upload_name)) {
                        $new_filename = 'uploads/' . $upload_name;
                        // Update UI variables immediately
                        $profile_pic_path = $base_path . $new_filename;
                        $has_valid_pic = true;
                    }
                }
            }

            // Update Database
            $upd_stmt = $pdo->prepare("UPDATE users SET full_name = ?, email = ?, profile_pic = ?, updated_at = NOW() WHERE username = ?");
            
            if ($upd_stmt->execute([$new_full_name, $new_email, $new_filename, $username])) {
                $_SESSION['name'] = $new_full_name; // Sync session
                $response_data['success'] = true;
                $response_data['message'] = 'Profile updated successfully.';
                $response_data['data'] = [
                    'fullName' => $new_full_name, 
                    'email' => $new_email, 
                    'profilePicPath' => $profile_pic_path,
                    'hasValidPic' => $has_valid_pic
                ];
            } else {
                $response_data['message'] = 'Database update failed.';
            }
        } else {
            $response_data['field_errors'] = $field_errors;
        }
    }
    
    // 2. CHANGE PASSWORD
    if (isset($_POST['form_action']) && $_POST['form_action'] === 'change_password') {
        $current = $_POST['currentPassword'];
        $new = $_POST['newPassword'];
        $confirm = $_POST['confirmPassword'];
        $field_errors = [];

        // Verify Current Password
        if (!password_verify($current, $user_data['password'])) {
            $field_errors['currentPassword'] = 'Incorrect password.';
        }
        
        if (strlen($new) < 6) $field_errors['newPassword'] = 'Password too short (min 6).';
        if ($new !== $confirm) $field_errors['confirmPassword'] = 'Passwords do not match.';

        if (empty($field_errors)) {
            $new_hash = password_hash($new, PASSWORD_DEFAULT);
            $upd_stmt = $pdo->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE username = ?");
            
            if ($upd_stmt->execute([$new_hash, $username])) {
                $response_data['success'] = true;
                $response_data['message'] = 'Password changed successfully.';
            } else {
                $response_data['message'] = 'Database error.';
            }
        } else {
            $response_data['field_errors'] = $field_errors;
        }
    }
    
    if ($is_ajax) {
        header('Content-Type: application/json');
        echo json_encode($response_data);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#3b82f6">
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

        /* HEADER - Matched to Dashboard */
        .main-header { 
            background: var(--bg-header); 
            backdrop-filter: blur(12px); 
            -webkit-backdrop-filter: blur(12px);
            border-bottom: 1px solid var(--border-color); 
            position: sticky; top: 0; z-index: 100;
            padding-top: env(safe-area-inset-top);
        }
        .navbar { display: flex; justify-content: space-between; align-items: center; padding: 0.8rem 1rem; max-width: 1280px; margin: 0 auto; }
        .logo { font-weight: 800; font-size: 1.25rem; color: var(--primary-color); display: flex; align-items: center; gap: 0.5rem; text-decoration: none; }
        .logo img { height: 32px; width: auto; }
        
        /* CARDS & GRADIENTS */
        .card { background: var(--bg-secondary); border-radius: 1.5rem; border: 1px solid var(--border-color); box-shadow: 0 1px 2px 0 rgba(0,0,0,0.05); overflow: hidden; margin-bottom: 1.5rem; }
        
        /* Updated Hero Card to Blue Gradient */
        .hero-card { background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); color: white; padding: 2rem 1.5rem; border-radius: 1.5rem; position: relative; overflow: hidden; margin-bottom: 1.5rem; box-shadow: 0 10px 20px -5px rgba(59, 130, 246, 0.4); }
        .hero-bg-icon { position: absolute; right: -20px; bottom: -40px; font-size: 10rem; opacity: 0.1; transform: rotate(-15deg); pointer-events: none; }
        
        /* FORM ELEMENTS */
        .input-group { position: relative; margin-bottom: 1rem; }
        .input-field { width: 100%; padding: 0.8rem 1rem 0.8rem 2.8rem; border-radius: 0.75rem; border: 1px solid var(--border-color); background: var(--bg-primary); color: var(--text-primary); transition: all 0.2s; }
        .input-field:focus { outline: none; border-color: var(--primary-color); box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.2); background: var(--bg-secondary); }
        .input-icon { position: absolute; left: 1rem; top: 50%; transform: translateY(-50%); color: var(--text-secondary); }
        
        /* AVATAR UPLOAD & FALLBACK */
        .avatar-wrapper { position: relative; width: 100px; height: 100px; margin: 0 auto 1rem auto; }
        .avatar-img { width: 100%; height: 100%; object-fit: cover; border-radius: 50%; border: 4px solid rgba(255,255,255,0.3); box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        
        /* Profile Fallback Box (For when img is missing) */
        .avatar-fallback { width: 100%; height: 100%; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 2.5rem; color: var(--primary-color); background: var(--bg-secondary); border: 4px solid rgba(255,255,255,0.3); }
        
        .avatar-edit-btn { position: absolute; bottom: 0; right: 0; background: white; color: var(--primary-color); width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 4px rgba(0,0,0,0.1); cursor: pointer; transition: transform 0.2s; }
        .avatar-edit-btn:active { transform: scale(0.9); }

        /* STAT CARDS */
        .stat-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 1rem; margin-bottom: 1.5rem; }
        .stat-card { background: var(--bg-secondary); padding: 1rem; border-radius: 1rem; border: 1px solid var(--border-color); text-align: center; }
        .stat-val { font-size: 1.25rem; font-weight: 800; color: var(--text-primary); }
        .stat-label { font-size: 0.75rem; color: var(--text-secondary); font-weight: 600; text-transform: uppercase; }

        /* TIMELINE */
        .timeline-item { padding-left: 1.5rem; border-left: 2px solid var(--border-color); position: relative; padding-bottom: 1.5rem; }
        .timeline-item::before { content: ''; position: absolute; left: -5px; top: 0; width: 8px; height: 8px; border-radius: 50%; background: var(--primary-color); }
        .timeline-item:last-child { border-left: none; padding-bottom: 0; }
        
        /* BOTTOM NAV - Matched Exactly to Dashboard */
        .mobile-bottom-nav {
            display: none; position: fixed; bottom: 0; left: 0; right: 0;
            background: var(--bg-secondary); border-top: 1px solid var(--border-color);
            padding: 0.5rem 1rem calc(0.5rem + env(safe-area-inset-bottom));
            z-index: 1000; justify-content: space-between;
            box-shadow: 0 -4px 10px rgba(0,0,0,0.05);
        }
        .nav-item { 
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            text-decoration: none; color: var(--text-secondary);
            font-size: 0.7rem; font-weight: 500; gap: 4px; flex: 1;
        }
        .nav-item i { font-size: 1.4rem; margin-bottom: 2px; }
        .nav-item.active { color: var(--primary-color); }
        
        @media (max-width: 768px) {
            .mobile-bottom-nav { display: flex; }
        }

        #toast-container { position: fixed; top: 1rem; left: 50%; transform: translateX(-50%); z-index: 999; width: 90%; max-width: 350px; pointer-events: none; }
        .toast { background: var(--bg-secondary); border-left: 4px solid; padding: 1rem; border-radius: 8px; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); margin-bottom: 0.5rem; display: flex; align-items: center; gap: 10px; animation: slideDown 0.3s ease; pointer-events: auto; }
        @keyframes slideDown { from { transform: translateY(-20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
    </style>
</head>
<body class="bg-gray-50 dark:bg-slate-900">

    <div id="toast-container"></div>

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
                
                <!-- Profile Avatar in Header -->
                <div class="w-9 h-9 rounded-full overflow-hidden border-2 border-blue-500 bg-gray-100 dark:bg-slate-700 flex items-center justify-center">
                    <?php if($has_valid_pic): ?>
                        <img src="<?php echo htmlspecialchars($profile_pic_path); ?>" alt="Profile" class="w-full h-full object-cover">
                    <?php else: ?>
                        <i class="fas fa-user text-gray-400 dark:text-gray-300"></i>
                    <?php endif; ?>
                </div>

                <!-- Logout -->
                <a href="<?php echo htmlspecialchars($base_path . 'logout.php'); ?>" class="p-2 text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-full transition">
                    <i class="fas fa-sign-out-alt"></i>
                </a>
            </div>
        </nav>
    </header>

    <main class="max-w-3xl mx-auto px-4 py-6">
        
        <!-- HERO CARD -->
        <div class="hero-card">
            <i class="fas fa-user-circle hero-bg-icon"></i>
            <div class="relative z-10 text-center">
                
                <!-- Large Avatar with Fallback -->
                <div class="avatar-wrapper">
                    <?php if($has_valid_pic): ?>
                        <img src="<?php echo htmlspecialchars($profile_pic_path); ?>" alt="Avatar" id="heroAvatar" class="avatar-img">
                    <?php else: ?>
                        <div id="heroAvatarFallback" class="avatar-fallback"><i class="fas fa-user"></i></div>
                        <img src="" alt="Avatar" id="heroAvatar" class="avatar-img hidden">
                    <?php endif; ?>
                    
                    <label for="settings_profile_picture" class="avatar-edit-btn">
                        <i class="fas fa-camera"></i>
                    </label>
                </div>

                <h1 class="text-2xl font-bold" id="displayName"><?php echo htmlspecialchars($full_name); ?></h1>
                <p class="opacity-90 text-sm mb-3">@<?php echo htmlspecialchars($username); ?></p>
                
                <div class="inline-flex gap-2 flex-wrap justify-center">
                    <?php if($branch_name): ?><span class="px-3 py-1 bg-white/20 rounded-full text-xs backdrop-blur-sm"><i class="fas fa-code-branch mr-1"></i> <?php echo htmlspecialchars($branch_name); ?></span><?php endif; ?>
                    <?php if($role): ?><span class="px-3 py-1 bg-white/20 rounded-full text-xs backdrop-blur-sm uppercase"><i class="fas fa-user-shield mr-1"></i> <?php echo htmlspecialchars($role); ?></span><?php endif; ?>
                </div>
            </div>
        </div>

        <!-- STATS GRID -->
        <div class="stat-grid">
            <div class="stat-card">
                <div class="stat-val text-green-500">Active</div>
                <div class="stat-label">Account Status</div>
            </div>
            <div class="stat-card">
                <div class="stat-val text-red-500"><?php echo $failed_count; ?></div>
                <div class="stat-label">Failed Logins</div>
            </div>
        </div>

        <!-- PROFILE FORM -->
        <div class="card">
            <div class="p-5 border-b border-gray-100 dark:border-slate-700 bg-gray-50 dark:bg-slate-800/50">
                <h2 class="font-bold text-gray-800 dark:text-white flex items-center gap-2">
                    <i class="fas fa-user-edit text-blue-500"></i> Edit Profile
                </h2>
            </div>
            <div class="p-5">
                <form id="profileForm" enctype="multipart/form-data">
                    <input type="hidden" name="form_action" value="update_profile">
                    
                    <!-- Hidden File Input -->
                    <input type="file" id="settings_profile_picture" name="settings_profile_picture" class="hidden" accept="image/*">

                    <div class="input-group">
                        <i class="fas fa-user input-icon"></i>
                        <input type="text" name="fullName" value="<?php echo htmlspecialchars($full_name); ?>" class="input-field" placeholder="Full Name" required>
                    </div>

                    <div class="input-group">
                        <i class="fas fa-envelope input-icon"></i>
                        <input type="email" name="email" value="<?php echo htmlspecialchars($email); ?>" class="input-field" placeholder="Email Address" required>
                    </div>

                    <button type="submit" class="w-full py-3 bg-blue-600 hover:bg-blue-700 text-white rounded-xl font-bold shadow-lg shadow-blue-500/30 transition transform active:scale-95">
                        Save Changes
                    </button>
                </form>
            </div>
        </div>

        <!-- SECURITY FORM -->
        <div class="card">
            <div class="p-5 border-b border-gray-100 dark:border-slate-700 bg-gray-50 dark:bg-slate-800/50">
                <h2 class="font-bold text-gray-800 dark:text-white flex items-center gap-2">
                    <i class="fas fa-lock text-orange-500"></i> Security
                </h2>
            </div>
            <div class="p-5">
                <form id="passwordForm">
                    <input type="hidden" name="form_action" value="change_password">
                    
                    <div class="input-group">
                        <i class="fas fa-key input-icon"></i>
                        <input type="password" name="currentPassword" class="input-field" placeholder="Current Password" required>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div class="input-group">
                            <i class="fas fa-lock input-icon"></i>
                            <input type="password" name="newPassword" class="input-field" placeholder="New Password" required>
                        </div>
                        <div class="input-group">
                            <i class="fas fa-check-circle input-icon"></i>
                            <input type="password" name="confirmPassword" class="input-field" placeholder="Confirm" required>
                        </div>
                    </div>

                    <button type="submit" class="w-full py-3 bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 text-gray-700 dark:text-gray-200 rounded-xl font-bold hover:bg-gray-50 dark:hover:bg-slate-700 transition">
                        Update Password
                    </button>
                </form>
            </div>
        </div>

        <!-- ACTIVITY TIMELINE -->
        <div class="card p-5">
            <h3 class="font-bold text-sm text-gray-500 uppercase mb-4">Recent Activity</h3>
            <div class="timeline">
                <?php if ($user_data && !empty($user_data['last_login'])): ?>
                <div class="timeline-item">
                    <div class="text-sm font-bold text-gray-800 dark:text-white">Successful Login</div>
                    <div class="text-xs text-gray-500"><?php echo htmlspecialchars($user_data['last_login']); ?></div>
                </div>
                <?php endif; ?>
                <div class="timeline-item">
                    <div class="text-sm font-bold text-gray-800 dark:text-white">Profile Loaded</div>
                    <div class="text-xs text-gray-500">Accessed settings page</div>
                </div>
            </div>
        </div>

    </main>

    <!-- MOBILE NAV (Matches Dashboard Layout & Spacing) -->
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
        <a href="clients.php" class="nav-item">
            <i class="fas fa-users"></i>
            <span>Clients</span>
        </a>
    </nav>

    <script>
        // --- UX LOGIC ---
        const $ = id => document.getElementById(id);
        
        function showToast(msg, type) {
            const el = document.createElement('div');
            el.className = 'toast';
            el.style.borderLeftColor = type === 'success' ? '#22c55e' : '#ef4444';
            el.innerHTML = `<i class="fas ${type==='success'?'fa-check-circle text-green-500':'fa-exclamation-circle text-red-500'}"></i> <span class="text-sm font-medium text-gray-800 dark:text-gray-200">${msg}</span>`;
            document.getElementById('toast-container').appendChild(el);
            setTimeout(() => { el.style.opacity = '0'; setTimeout(() => el.remove(), 300); }, 3000);
        }

        // Theme Toggle
        const themeBtn = $('themeToggle');
        themeBtn.onclick = () => {
            document.documentElement.classList.toggle('dark');
            localStorage.setItem('theme', document.documentElement.classList.contains('dark') ? 'dark' : 'light');
        };
        if(localStorage.getItem('theme') === 'dark') document.documentElement.classList.add('dark');

        // Image Preview Logic
        const fileInput = $('settings_profile_picture');
        const heroAvatar = $('heroAvatar');
        const fallbackIcon = $('heroAvatarFallback');
        
        fileInput.addEventListener('change', (e) => {
            const file = e.target.files[0];
            if(file) {
                const reader = new FileReader();
                reader.onload = (e) => { 
                    heroAvatar.src = e.target.result;
                    // Show img, hide fallback if it exists
                    heroAvatar.classList.remove('hidden');
                    if(fallbackIcon) fallbackIcon.classList.add('hidden');
                };
                reader.readAsDataURL(file);
            }
        });

        // AJAX Form Handling
        const handleForm = (formId) => {
            const form = $(formId);
            form.addEventListener('submit', async (e) => {
                e.preventDefault();
                const btn = form.querySelector('button');
                const originalText = btn.innerText;
                btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';

                try {
                    const fd = new FormData(form);
                    const res = await fetch('', { method:'POST', body:fd, headers:{'X-Requested-With':'XMLHttpRequest'}});
                    const data = await res.json();

                    if(data.success) {
                        showToast(data.message, 'success');
                        if(formId === 'profileForm' && data.data) {
                            $('displayName').innerText = data.data.fullName;
                        }
                        if(formId === 'passwordForm') form.reset();
                    } else {
                        let msg = data.message || 'Error occurred';
                        if(data.field_errors) msg = Object.values(data.field_errors)[0];
                        showToast(msg, 'error');
                    }
                } catch(err) { showToast('Connection Error', 'error'); }
                finally { btn.disabled = false; btn.innerText = originalText; }
            });
        };

        handleForm('profileForm');
        handleForm('passwordForm');
    </script>
</body>
</html>