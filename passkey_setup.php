<?php
date_default_timezone_set('Africa/Lagos');
// passkey_setup.php - MySQL Version
session_start();

// --- DATABASE CONNECTION START ---
require_once __DIR__ . '/includes/config.php';
$conn = getDbConnection();
// --- DATABASE CONNECTION END ---

// 2. AUTH CHECK
if (!isset($_SESSION['user_role']) || !isset($_SESSION['username'])) {
    header("Location: index.php?error=unauthorized");
    exit();
}

// 3. ROLE-BASED REDIRECT HELPER
function getRoleBasedDashboard($role) {
    $role = strtolower($role);
    return match ($role) {
        'admin' => 'admin/dashboard.php',
        'tm' => 'tm/dashboard.php', 
        'am' => 'am/dashboard.php',
        'bm' => 'bm/dashboard.php',
        'client' => 'client/dashboard.php',
        'co' => 'co/dashboard.php',
        'dzm' => 'dzm/dashboard.php',
        'zm' => 'zm/dashboard.php',
        default => 'co/dashboard.php'
    };
}

// 4. GENERATE CHALLENGE
if (empty($_SESSION['webauthn_challenge'])) {
    $_SESSION['webauthn_challenge'] = bin2hex(random_bytes(32));
}

// 5. FETCH CURRENT USER ID (Needed for DB linking)
$current_username = $_SESSION['username'];
$user_id = 0;
$user_name = $current_username;
$profile_pic = 'default_avatar.png';

$stmt = $conn->prepare("SELECT id, full_name, profile_pic FROM users WHERE username = ?");
$stmt->execute([$current_username]);
if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $user_id = $row['id'];
    $user_name = $row['full_name'] ?? $current_username;
    $profile_pic = !empty($row['profile_pic']) ? $row['profile_pic'] : 'default_avatar.png';
} else {
    // Session exists but user not in DB? Edge case.
    session_destroy();
    header("Location: index.php");
    exit();
}

// 6. HANDLE AJAX REQUEST (SAVE PASSKEY)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        echo json_encode(['success' => false, 'message' => 'Invalid session. Please refresh.']);
        exit;
    }

    $rawCredId = $_POST['credentialId'] ?? '';

    if ($rawCredId && $user_id > 0) {
        // Check if user already has a passkey
        $checkStmt = $conn->prepare("SELECT id, credential_id FROM user_passkeys WHERE user_id = ? LIMIT 1");
        $checkStmt->execute([$user_id]);
        $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            // Update existing passkey
            if ($existing['credential_id'] === $rawCredId) {
                echo json_encode(['success' => true, 'message' => 'This device is already registered.']);
            } else {
                $updateStmt = $conn->prepare("UPDATE user_passkeys SET credential_id = ?, created_at = NOW() WHERE user_id = ?");
                if ($updateStmt->execute([$rawCredId, $user_id])) {
                    $redirectUrl = getRoleBasedDashboard($_SESSION['user_role']);
                    echo json_encode(['success' => true, 'message' => 'Passkey updated successfully!', 'redirect' => $redirectUrl]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Failed to update passkey.']);
                }
            }
        } else {
            // Insert new passkey
            $insStmt = $conn->prepare("INSERT INTO user_passkeys (user_id, credential_id, created_at) VALUES (?, ?, NOW())");
            if ($insStmt->execute([$user_id, $rawCredId])) {
                $redirectUrl = getRoleBasedDashboard($_SESSION['user_role']);
                echo json_encode(['success' => true, 'message' => 'Passkey added successfully!', 'redirect' => $redirectUrl]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Database error: Could not save passkey.']);
            }
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid credential data.']);
    }
    exit;
}

// CSRF
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$user_role = $_SESSION['user_role'];
$dashboard_url = getRoleBasedDashboard($user_role);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#3b82f6">
    <title>Setup Passkey | CUPAD</title>
    
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
    tailwind.config = {
        darkMode: 'class',
        theme: {
            extend: {
                fontFamily: { sans: ['Inter', 'sans-serif'] },
                colors: {
                    primary: '#3b82f6', secondary: '#8b5cf6', success: '#22c55e',
                    bg: { light: '#f0f2f5', dark: '#0f172a' },
                    dark: { bg: '#0f172a', surface: '#1e293b', border: '#334155' }
                }
            }
        }
    }
    </script>

    <style>
        :root {
            --primary-color: #3b82f6; --secondary-color: #8b5cf6;
            --bg-primary: #f0f2f5; --bg-secondary: #ffffff;
            --bg-header: rgba(255, 255, 255, 0.95);
            --text-primary: #1f2937; --text-secondary: #6b7280; --border-color: #e5e7eb;
        }

        html.dark {
            --bg-primary: #0f172a; --bg-secondary: #1e293b;
            --bg-header: rgba(15, 23, 42, 0.95);
            --text-primary: #f1f5f9; --text-secondary: #94a3b8; --border-color: rgba(255, 255, 255, 0.08);
        }

        body { font-family: 'Inter', sans-serif; background-color: var(--bg-primary); color: var(--text-primary); padding-bottom: 90px; -webkit-tap-highlight-color: transparent; }

        /* HEADER */
        .main-header { background: var(--bg-header); backdrop-filter: blur(12px); border-bottom: 1px solid var(--border-color); position: sticky; top: 0; z-index: 50; padding-top: env(safe-area-inset-top); }
        .navbar { display: flex; justify-content: space-between; align-items: center; padding: 0.8rem 1rem; max-width: 1280px; margin: 0 auto; }
        .logo { font-weight: 700; font-size: 1.25rem; color: var(--primary-color); display: flex; align-items: center; gap: 0.5rem; text-decoration: none; transition: all 0.2s; }
        .logo:hover { transform: translateY(-1px); }
        .logo img { height: 32px; width: auto; }

        /* USER INFO */
        .user-info { display: flex; flex-direction: column; align-items: end; margin-right: 0.5rem; }
        .user-name { font-size: 0.875rem; font-weight: 500; color: var(--text-primary); }
        .user-role { font-size: 0.75rem; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.5px; }

        /* GLASS CARD */
        .glass-card {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            box-shadow: 0 10px 30px -10px rgba(0, 0, 0, 0.1);
            border-radius: 1.5rem;
        }

        /* MOBILE NAV */
        .mobile-bottom-nav { display: none; position: fixed; bottom: 0; left: 0; right: 0; background: var(--bg-secondary); border-top: 1px solid var(--border-color); padding: 0.5rem 1rem calc(0.5rem + env(safe-area-inset-bottom)); z-index: 100; justify-content: space-around; box-shadow: 0 -4px 10px rgba(0,0,0,0.05); }
        .nav-item { display: flex; flex-direction: column; align-items: center; justify-content: center; text-decoration: none; color: var(--text-secondary); font-size: 0.7rem; font-weight: 500; gap: 4px; padding: 0.5rem; border-radius: 0.5rem; transition: all 0.2s; min-width: 60px; }
        .nav-item:hover { background: rgba(59, 130, 246, 0.1); }
        .nav-item i { font-size: 1.4rem; }
        .nav-item.active { color: var(--primary-color); background: rgba(59, 130, 246, 0.1); }

        /* PROFILE BTN */
        .profile-btn { width: 40px; height: 40px; border-radius: 50%; overflow: hidden; border: 2px solid var(--primary-color); background: var(--bg-secondary); cursor: pointer; display: flex; align-items: center; justify-content: center; position: relative; transition: all 0.2s; }
        .profile-btn:hover { transform: scale(1.05); box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3); }
        .profile-btn img { width: 100%; height: 100%; object-fit: cover; }
        .profile-fallback-icon { font-size: 1.2rem; color: var(--text-secondary); }

        /* TOAST */
        #toast-container { position: fixed; top: 80px; right: 20px; z-index: 60; transition: all 0.3s; opacity: 0; pointer-events: none; transform: translateY(-10px); }
        #toast-container.show { opacity: 1; pointer-events: auto; transform: translateY(0); }
        .toast { background: #1f2937; color: white; padding: 12px 24px; border-radius: 12px; font-weight: 500; display: flex; align-items: center; gap: 10px; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); }
        .dark .toast { background: #ffffff; color: #1f2937; }

        @media (max-width: 768px) {
            .mobile-bottom-nav { display: flex; }
            .user-info { display: none; }
            .navbar { padding: 0.6rem 1rem; }
            .logo { font-size: 1.1rem; }
            .profile-btn { width: 36px; height: 36px; }
        }
    </style>
</head>
<body class="bg-slate-50 text-slate-900 dark:bg-dark-bg dark:text-slate-100 transition-colors duration-200 flex flex-col min-h-screen">

    <!-- HEADER -->
    <header class="main-header">
        <nav class="navbar">
            <a href="<?php echo htmlspecialchars($dashboard_url); ?>" class="logo">
                <img src="favicon.ico" alt="Logo">
                <span>CUPAD</span>
            </a>

            <div class="flex items-center gap-2">
                <!-- User Info -->
                <div class="hidden sm:flex flex-col items-end mr-2">
                    <span class="text-sm font-medium text-slate-700 dark:text-slate-300"><?php echo htmlspecialchars($user_name); ?></span>
                    <span class="text-xs text-slate-500 dark:text-slate-400 uppercase"><?php echo htmlspecialchars($user_role); ?></span>
                </div>

                <button id="themeToggle" class="p-2 text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800 rounded-lg transition">
                    <i class="fas fa-moon dark:hidden"></i><i class="fas fa-sun hidden dark:inline"></i>
                </button>

                <div class="profile-btn" onclick="window.location.href='<?php echo str_replace('dashboard.php', 'profile.php', $dashboard_url); ?>'" title="Profile">
                    <?php if (strpos($profile_pic, 'default') === false && file_exists($profile_pic)): ?>
                        <img src="<?php echo htmlspecialchars($profile_pic); ?>" alt="Profile">
                    <?php else: ?>
                        <i class="fas fa-user profile-fallback-icon"></i>
                    <?php endif; ?>
                </div>
                
                <a href="logout.php" class="p-2 text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-lg transition" title="Logout">
                    <i class="fas fa-sign-out-alt"></i>
                </a>
            </div>
        </nav>
    </header>

    <!-- TOAST -->
    <div id="toast-container"><div id="toast-message" class="toast"></div></div>

    <!-- MAIN CONTENT -->
    <main class="flex-grow flex items-center justify-center p-4">
        
        <div class="glass-card w-full max-w-md p-8 text-center relative overflow-hidden">
            
            <!-- Decorative Background Blur -->
            <div class="absolute -top-20 -right-20 w-40 h-40 bg-blue-500 rounded-full mix-blend-multiply filter blur-3xl opacity-20 animate-blob"></div>
            <div class="absolute -bottom-20 -left-20 w-40 h-40 bg-purple-500 rounded-full mix-blend-multiply filter blur-3xl opacity-20 animate-blob animation-delay-2000"></div>

            <!-- Icon -->
            <div class="relative w-24 h-24 mx-auto mb-6 bg-blue-50 dark:bg-blue-900/20 rounded-full flex items-center justify-center z-10">
                <i class="fas fa-fingerprint text-4xl text-primary"></i>
                <!-- Pulse Effect Wrapper -->
                <div id="pulse-effect" class="absolute inset-0 rounded-full border-2 border-primary opacity-0 transition-opacity"></div>
            </div>

            <h1 class="text-2xl font-bold mb-2 relative z-10">Setup Passkey</h1>
            <p class="text-slate-500 dark:text-slate-400 mb-8 text-sm leading-relaxed relative z-10">
                Secure your account with Biometrics (Fingerprint or Face ID).
            </p>

            <div class="space-y-3 relative z-10">
                <button id="register-btn" class="w-full py-3.5 px-4 bg-gradient-to-r from-blue-600 to-blue-500 hover:from-blue-700 hover:to-blue-600 text-white rounded-xl font-semibold shadow-lg shadow-blue-500/30 transition-all active:scale-95 flex items-center justify-center gap-2">
                    <i class="fas fa-plus-circle"></i> Add This Device
                </button>

                <button onclick="window.location.href='<?php echo htmlspecialchars($dashboard_url); ?>'" class="w-full py-3.5 px-4 bg-transparent border border-slate-200 dark:border-dark-border text-slate-600 dark:text-slate-300 rounded-xl font-medium hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                    Back to Dashboard
                </button>
            </div>

            <div class="mt-8 pt-6 border-t border-slate-100 dark:border-slate-800 relative z-10">
                <p class="text-xs text-slate-400">
                    <i class="fas fa-lock mr-1"></i> Securely stored on your device.
                </p>
            </div>
        </div>
    </main>

    <!-- MOBILE NAV -->
    <nav class="mobile-bottom-nav">
        <a href="<?php echo htmlspecialchars($dashboard_url); ?>" class="nav-item">
            <i class="fas fa-home"></i>
            <span>Home</span>
        </a>
        <?php if (in_array($user_role, ['co', 'bm', 'admin'])): ?>
        <a href="<?php echo str_replace('dashboard.php', 'clients.php', $dashboard_url); ?>" class="nav-item">
            <i class="fas fa-users"></i>
            <span>Clients</span>
        </a>
        <?php endif; ?>
        <?php if (in_array($user_role, ['co', 'bm'])): ?>
        <a href="<?php echo str_replace('dashboard.php', 'union_groups.php', $dashboard_url); ?>" class="nav-item">
            <i class="fas fa-layer-group"></i>
            <span>Groups</span>
        </a>
        <?php endif; ?>
        <!-- Active Indicator for Settings/Utility -->
        <a href="#" class="nav-item active">
            <i class="fas fa-fingerprint"></i>
            <span>Passkey</span>
        </a>
        <?php if (in_array($user_role, ['co', 'bm'])): ?>
        <a href="<?php echo str_replace('dashboard.php', 'saving_collection.php', $dashboard_url); ?>" class="nav-item">
            <i class="fas fa-piggy-bank"></i>
            <span>Save</span>
        </a>
        <?php endif; ?>
    </nav>

    <script>
        // --- THEME LOGIC ---
        const html = document.documentElement;
        if(localStorage.getItem('theme') === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) html.classList.add('dark');
        
        document.getElementById('themeToggle').addEventListener('click', () => {
            html.classList.toggle('dark');
            localStorage.setItem('theme', html.classList.contains('dark') ? 'dark' : 'light');
        });

        // --- WEBAUTHN HELPERS ---
        const bufferToBase64 = (buffer) => btoa(String.fromCharCode(...new Uint8Array(buffer))).replace(/\+/g, "-").replace(/\//g, "_").replace(/=+$/, "");
        const base64ToBuffer = (base64) => {
            const padding = "=".repeat((4 - (base64.length % 4)) % 4);
            const base64Safe = (base64 + padding).replace(/-/g, "+").replace(/_/g, "/");
            const rawData = atob(base64Safe);
            const outputArray = new Uint8Array(rawData.length);
            for (let i = 0; i < rawData.length; ++i) outputArray[i] = rawData.charCodeAt(i);
            return outputArray.buffer;
        };

        // --- UI HELPERS ---
        function showToast(msg, type = 'info') {
            const container = document.getElementById('toast-container');
            const message = document.getElementById('toast-message');
            let icon = type === 'error' ? '<i class="fas fa-exclamation-circle text-red-400"></i>' : '<i class="fas fa-check-circle text-green-400"></i>';
            message.innerHTML = `${icon} <span>${msg}</span>`;
            container.classList.add('show');
            setTimeout(() => container.classList.remove('show'), 3000);
        }

        // --- MAIN LOGIC ---
        const challengeStr = "<?= $_SESSION['webauthn_challenge'] ?>";
        const currentUsername = "<?= $_SESSION['username'] ?>";
        const csrfToken = "<?= $_SESSION['csrf_token'] ?>";

        document.getElementById('register-btn').addEventListener('click', async () => {
            const btn = document.getElementById('register-btn');
            const pulse = document.getElementById('pulse-effect');
            
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Waiting...';
            btn.disabled = true;
            pulse.classList.add('animate-ping', 'opacity-75');
            pulse.classList.remove('opacity-0');

            try {
                if (location.protocol !== 'https:' && location.hostname !== 'localhost' && location.hostname !== '127.0.0.1') throw new Error("HTTPS required.");
                if (!window.PublicKeyCredential) throw new Error("Browser not supported.");

                const userIdBuffer = new TextEncoder().encode(currentUsername);
                
                const credential = await navigator.credentials.create({
                    publicKey: {
                        challenge: base64ToBuffer(challengeStr),
                        rp: { name: "CUPAD", id: window.location.hostname },
                        user: { id: userIdBuffer, name: currentUsername, displayName: currentUsername },
                        pubKeyCredParams: [{ alg: -7, type: "public-key" }, { alg: -257, type: "public-key" }],
                        authenticatorSelection: { authenticatorAttachment: "platform", userVerification: "required" },
                        timeout: 60000,
                        attestation: "none"
                    }
                });

                const formData = new FormData();
                formData.append('credentialId', bufferToBase64(credential.rawId));
                formData.append('csrf_token', csrfToken);

                const req = await fetch('passkey_setup.php', { method: 'POST', body: formData });
                const res = await req.json();

                if (res.success) {
                    showToast(res.message, 'success');
                    setTimeout(() => window.location.href = res.redirect || '<?php echo htmlspecialchars($dashboard_url); ?>', 2000);
                } else {
                    showToast(res.message, 'error');
                }

            } catch (e) {
                console.error(e);
                showToast(e.name === 'NotAllowedError' ? "Setup cancelled." : (e.message || "Error occurred."), 'error');
            } finally {
                btn.innerHTML = '<i class="fas fa-plus-circle"></i> Add This Device';
                btn.disabled = false;
                pulse.classList.remove('animate-ping', 'opacity-75');
                pulse.classList.add('opacity-0');
            }
        });
    </script>
</body>
</html>