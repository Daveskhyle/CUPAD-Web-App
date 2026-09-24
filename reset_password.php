<?php
date_default_timezone_set('Africa/Lagos');
require_once __DIR__ . '/includes/config.php';

session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict']);
session_start();

header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("Cache-Control: no-store");

$message  = ['type' => '', 'text' => ''];
$token    = $_GET['token'] ?? '';
$valid    = false;
$resetRow = null;

try {
    $pdo = getDbConnection();
} catch (Exception $e) {
    die('Database connection failed.');
}

// FIX 3: Removed the automatic redirect loop. 
// If the token is missing, it stays on the page and tells the user exactly what went wrong.
if (empty($token)) {
    $message =['type' => 'error', 'text' => 'The security token is missing from the URL. Please ensure you clicked the full link provided in the email.'];
} else {
    // Check if token is valid and not expired using Database Time directly.
    $stmt = $pdo->prepare("SELECT pr.*, u.username, u.full_name, u.name 
                           FROM password_resets pr 
                           JOIN users u ON u.id = pr.user_id 
                           WHERE pr.token = ? AND pr.used = 0 AND pr.expires_at > NOW() LIMIT 1");
    $stmt->execute([$token]);
    $resetRow = $stmt->fetch();

    if (!$resetRow) {
        $message =['type' => 'error', 'text' => 'This reset link is invalid or has expired. Please request a new one.'];
    } else {
        $valid = true;
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $valid) {
    $enteredCode = trim($_POST['reset_code'] ?? '');
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPass = $_POST['confirm_password'] ?? '';

    if (empty($enteredCode)) {
        $message =['type' => 'error', 'text' => 'Please enter the 6-digit code from your email.'];
    } elseif (!hash_equals($resetRow['code'], $enteredCode)) {
        $message =['type' => 'error', 'text' => 'Incorrect code. Check your email and try again.'];
    } elseif (strlen($newPassword) < 6) {
        $message =['type' => 'error', 'text' => 'Password must be at least 6 characters.'];
    } elseif ($newPassword !== $confirmPass) {
        $message =['type' => 'error', 'text' => 'Passwords do not match.'];
    } else {
        try {
            $hashed = password_hash($newPassword, PASSWORD_BCRYPT);
            $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$hashed, $resetRow['user_id']]);
            $pdo->prepare("UPDATE password_resets SET used = 1 WHERE token = ?")->execute([$token]);
            $valid   = false;
            $message =['type' => 'success', 'text' => 'Password reset successfully! You can now log in.'];
        } catch (Exception $e) {
            error_log("reset_password error: " . $e->getMessage());
            $message =['type' => 'error', 'text' => 'An error occurred. Please try again.'];
        }
    }
}

$displayName = $resetRow['full_name'] ?? $resetRow['name'] ?? $resetRow['username'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - CUPAD</title>
    <link rel="icon" type="image/png" href="uploads/CUPAD LOGO.png">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <style>
        :root { --primary: #3b82f6; --primary-hover: #2563eb; --secondary: #8b5cf6; --bg-color: #f1f5f9; --card-bg: rgba(255, 255, 255, 0.85); --border-glass: rgba(255, 255, 255, 0.6); --text-main: #0f172a; --text-sub: #64748b; --input-bg: #ffffff; --input-border: #cbd5e1; }
        html.dark { --bg-color: #0f172a; --card-bg: rgba(30, 41, 59, 0.75); --border-glass: rgba(255, 255, 255, 0.08); --text-main: #f8fafc; --text-sub: #94a3b8; --input-bg: rgba(15, 23, 42, 0.6); --input-border: #334155; }
        * { box-sizing: border-box; outline: none; margin: 0; padding: 0; }
        body { font-family: 'Inter', sans-serif; background-color: var(--bg-color); color: var(--text-main); min-height: 100vh; display: flex; align-items: center; justify-content: center; transition: background-color 0.3s ease, color 0.3s ease; }
        #bg { position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: -1; overflow: hidden; pointer-events: none; }
        #aurora { position: absolute; top: 50%; left: 50%; transform: translate(-50%,-50%); width: 150vw; height: 150vh; filter: blur(90px) saturate(140%); opacity: 0.3; }
        @keyframes aurora { 0% { transform: translate(0, 0) scale(1); } 100% { transform: translate(5%, 5%) scale(1.1); } }
        .a { position: absolute; border-radius: 50%; background: radial-gradient(circle, var(--primary), transparent 60%); animation: aurora 10s ease-in-out infinite alternate; }
        .a:nth-child(2) { background: radial-gradient(circle, var(--secondary), transparent 60%); animation-duration: 15s; animation-delay: -3s; }
        @keyframes fadeInUp { from { opacity: 0; transform: translateY(30px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes shake { 0%, 100% { transform: translateX(0); } 20%, 60% { transform: translateX(-5px); } 40%, 80% { transform: translateX(5px); } }
        .card { background: var(--card-bg); backdrop-filter: blur(24px); -webkit-backdrop-filter: blur(24px); border: 1px solid var(--border-glass); border-radius: 1.5rem; padding: 2.5rem; width: 100%; max-width: 440px; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.1); animation: fadeInUp 0.6s cubic-bezier(0.16, 1, 0.3, 1); margin: 1rem; transition: background 0.3s, border-color 0.3s; }
        .card-header { text-align: center; margin-bottom: 2rem; }
        .card-header img { height: 60px; margin-bottom: 1.2rem; transition: transform 0.3s; }
        .card-header img:hover { transform: scale(1.05); }
        .card-header h1 { font-size: 1.75rem; font-weight: 700; background: linear-gradient(135deg, var(--primary), var(--secondary)); -webkit-background-clip: text; -webkit-text-fill-color: transparent; margin-bottom: 0.5rem; }
        .card-header p { color: var(--text-sub); font-size: 0.95rem; line-height: 1.5; transition: color 0.3s; }
        .form-group { position: relative; margin-bottom: 1.5rem; }
        .form-group .icon { position: absolute; left: 1.2rem; top: 50%; transform: translateY(-50%); color: var(--text-sub); pointer-events: none; z-index: 2; font-size: 1.1rem; transition: color 0.3s; }
        .form-input { width: 100%; padding: 1.4rem 1rem 0.6rem 3rem; height: 60px; border-radius: 1rem; border: 1.5px solid var(--input-border); background: var(--input-bg); color: var(--text-main); font-size: 1rem; font-family: inherit; transition: all 0.3s ease; }
        .form-input:focus { border-color: var(--primary); box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.15); }
        .form-input:focus ~ .icon { color: var(--primary); }
        input[type="password"], input.pwd-visible { padding-right: 3rem; }
        #reset_code { font-weight: 600; letter-spacing: 2px; }
        .floating-label { position: absolute; left: 3rem; top: 20px; color: var(--text-sub); font-size: 1rem; pointer-events: none; transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1); }
        .form-input:focus + .floating-label, .form-input:not(:placeholder-shown) + .floating-label { top: 10px; font-size: 0.75rem; color: var(--primary); font-weight: 600; letter-spacing: normal; }
        .toggle-pwd { position: absolute; right: 1.2rem; top: 50%; transform: translateY(-50%); cursor: pointer; color: var(--text-sub); padding: 5px; z-index: 2; transition: color 0.3s, transform 0.2s; }
        .toggle-pwd:hover { color: var(--primary); transform: translateY(-50%) scale(1.1); }
        .strength-container { margin-top: -0.75rem; margin-bottom: 1.25rem; }
        .strength-track { height: 4px; width: 100%; background: var(--input-border); border-radius: 4px; overflow: hidden; opacity: 0.5; transition: opacity 0.3s; }
        #strength-bar { height: 100%; width: 0; background: transparent; transition: width 0.4s ease, background-color 0.4s ease; border-radius: 4px; }
        .strength-text { font-size: 0.75rem; color: var(--text-sub); margin-top: 0.4rem; text-align: right; display: block; font-weight: 500; transition: color 0.3s; }
        .btn { width: 100%; padding: 1rem; border-radius: 1rem; border: none; background: linear-gradient(135deg, var(--primary), var(--secondary)); color: #fff; font-size: 1.05rem; font-weight: 600; cursor: pointer; transition: all 0.3s; font-family: inherit; display: flex; align-items: center; justify-content: center; gap: 0.5rem; }
        .btn:hover { transform: translateY(-2px); box-shadow: 0 10px 25px rgba(59, 130, 246, 0.3); }
        .btn:active { transform: translateY(0); }
        .btn:disabled { opacity: 0.7; cursor: not-allowed; transform: none; box-shadow: none; }
        .login-btn { display: flex; margin-top: 0.5rem; width: 100%; padding: 1rem; border-radius: 1rem; background: linear-gradient(135deg, var(--primary), var(--secondary)); color: #fff; font-size: 1.05rem; font-weight: 600; text-align: center; text-decoration: none; transition: all 0.3s; justify-content: center; align-items: center; gap: 0.5rem; }
        .login-btn:hover { transform: translateY(-2px); box-shadow: 0 10px 25px rgba(59, 130, 246, 0.3); }
        .alert { padding: 1rem; border-radius: 0.75rem; font-size: 0.95rem; margin-bottom: 1.5rem; display: flex; align-items: flex-start; gap: 0.75rem; line-height: 1.4; animation: fadeInUp 0.4s ease-out; border-left: 4px solid transparent; }
        .alert i { margin-top: 0.1rem; font-size: 1.1rem; }
        .alert.success { background: rgba(34, 197, 94, 0.1); color: #166534; border-color: rgba(34, 197, 94, 0.3); border-left-color: #22c55e; }
        .alert.error { background: rgba(239, 68, 68, 0.1); color: #991b1b; border-color: rgba(239, 68, 68, 0.3); border-left-color: #ef4444; animation: shake 0.5s cubic-bezier(.36,.07,.19,.97) both; }
        html.dark .alert.success { color: #86efac; border-color: rgba(34, 197, 94, 0.2); border-left-color: #4ade80;}
        html.dark .alert.error { color: #fca5a5; border-color: rgba(239, 68, 68, 0.2); border-left-color: #f87171;}
        .back-link { display: flex; align-items: center; gap: 0.5rem; color: var(--text-sub); font-size: 0.95rem; text-decoration: none; margin-top: 1.5rem; justify-content: center; font-weight: 500; transition: color 0.3s; }
        .back-link i { transition: transform 0.3s; }
        .back-link:hover { color: var(--primary); }
        .back-link:hover i { transform: translateX(-4px); }
        #theme-toggle { position: fixed; top: 1.5rem; right: 1.5rem; background: var(--card-bg); backdrop-filter: blur(10px); border: 1px solid var(--border-glass); padding: 0.6rem 1rem; border-radius: 99px; color: var(--text-main); cursor: pointer; font-size: 0.9rem; font-weight: 500; display: flex; align-items: center; gap: 0.5rem; transition: all 0.3s; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); z-index: 50; }
        #theme-toggle:hover { transform: translateY(-2px); box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); }
        input:-webkit-autofill { -webkit-box-shadow: 0 0 0 50px #ffffff inset !important; -webkit-text-fill-color: #0f172a !important; }
        html.dark input:-webkit-autofill { -webkit-box-shadow: 0 0 0 50px #0b1120 inset !important; -webkit-text-fill-color: #f8fafc !important; }
    </style>
</head>
<body>
<div id="bg">
    <div id="aurora">
        <div class="a" style="width:50vw;height:50vw;top:10%;left:10%;"></div>
        <div class="a" style="width:40vw;height:40vw;top:60%;left:70%;"></div>
    </div>
</div>

<button id="theme-toggle" aria-label="Toggle Theme">
    <i id="themeIcon" class="fas fa-moon"></i>
    <span id="themeText">Dark</span>
</button>

<div class="card">
    <div class="card-header">
        <img src="uploads/CUPAD LOGO.png" alt="CUPAD Logo">
        <h1>Reset Password</h1>
        <?php if ($valid): ?>
            <p>Welcome back, <strong><?= htmlspecialchars($displayName) ?></strong>. <br>Enter your reset code and new password.</p>
        <?php else: ?>
            <p>Securely set your new CUPAD password.</p>
        <?php endif; ?>
    </div>

    <?php if (!empty($message['text'])): ?>
        <div class="alert <?= htmlspecialchars($message['type']) ?>" role="alert">
            <i class="fas <?= $message['type'] === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle' ?>"></i>
            <div><?= $message['text'] ?></div>
        </div>
    <?php endif; ?>

    <?php if ($message['type'] === 'success'): ?>
        <a href="index.php" class="login-btn"><i class="fas fa-sign-in-alt"></i> Go to Login</a>

    <?php elseif ($valid): ?>
        <form method="POST" action="reset_password?token=<?= urlencode($token) ?>" id="resetForm">
            <div class="form-group">
                <input type="text" name="reset_code" id="reset_code" class="form-input" placeholder=" " required autocomplete="one-time-code" inputmode="numeric" maxlength="6" pattern="[0-9]{6}">
                <label for="reset_code" class="floating-label">6-Digit Code from Email</label>
                <i class="fas fa-envelope-open-text icon"></i>
            </div>
            
            <div class="form-group" style="margin-bottom: 0.75rem;">
                <input type="password" name="new_password" id="new_password" class="form-input" placeholder=" " required autocomplete="new-password">
                <label for="new_password" class="floating-label">New Password</label>
                <i class="fas fa-lock icon"></i>
                <i class="fas fa-eye toggle-pwd" id="toggle-new" title="Show password"></i>
            </div>
            
            <div class="strength-container">
                <div class="strength-track">
                    <div id="strength-bar"></div>
                </div>
                <span id="strength-text" class="strength-text"></span>
            </div>
            
            <div class="form-group">
                <input type="password" name="confirm_password" id="confirm_password" class="form-input" placeholder=" " required autocomplete="new-password">
                <label for="confirm_password" class="floating-label">Confirm Password</label>
                <i class="fas fa-lock icon"></i>
                <i class="fas fa-eye toggle-pwd" id="toggle-confirm" title="Show password"></i>
            </div>
            
            <button type="submit" class="btn" id="resetBtn">
                <i class="fas fa-key"></i> Reset Password
            </button>
        </form>

    <?php else: ?>
        <a href="forgot_password.php" class="login-btn"><i class="fas fa-redo"></i> Request New Link</a>
    <?php endif; ?>

    <a href="index.php" class="back-link">
        <i class="fas fa-arrow-left"></i> Back to Login
    </a>
</div>

<script>
    const html = document.documentElement;
    const themeIcon = document.getElementById('themeIcon');
    const themeText = document.getElementById('themeText');

    if (localStorage.getItem('theme') === 'dark') {
        html.classList.add('dark');
        themeIcon.className = 'fas fa-sun';
        themeText.textContent = 'Light';
    }

    document.getElementById('theme-toggle').addEventListener('click', () => {
        html.classList.toggle('dark');
        const isDark = html.classList.contains('dark');
        localStorage.setItem('theme', isDark ? 'dark' : 'light');
        
        themeIcon.className = isDark ? 'fas fa-sun' : 'fas fa-moon';
        themeText.textContent = isDark ? 'Light' : 'Dark';
    });

    function togglePwd(inputId, icon) {
        const input = document.getElementById(inputId);
        if (!input) return;
        
        const isPassword = input.type === 'password';
        input.type = isPassword ? 'text' : 'password';
        input.classList.toggle('pwd-visible');
        
        icon.className = isPassword ? 'fas fa-eye-slash toggle-pwd' : 'fas fa-eye toggle-pwd';
        icon.title = isPassword ? 'Hide password' : 'Show password';
    }
    
    document.getElementById('toggle-new')?.addEventListener('click', function() { togglePwd('new_password', this); });
    document.getElementById('toggle-confirm')?.addEventListener('click', function() { togglePwd('confirm_password', this); });

    document.getElementById('new_password')?.addEventListener('input', function() {
        const p = this.value;
        const bar = document.getElementById('strength-bar');
        const text = document.getElementById('strength-text');
        const track = document.querySelector('.strength-track');
        
        let score = 0;
        if (p.length > 0) track.style.opacity = '1'; else track.style.opacity = '0.5';
        
        if (p.length >= 6) score++;
        if (p.length >= 8 && /[A-Z]/.test(p)) score++;
        if (/[0-9]/.test(p)) score++;
        if (/[^A-Za-z0-9]/.test(p)) score++;

        const percentages =['0%', '25%', '50%', '75%', '100%'];
        const colors =['transparent', '#ef4444', '#f59e0b', '#3b82f6', '#10b981'];
        const labels =['', 'Weak', 'Fair', 'Good', 'Strong'];

        bar.style.width = p.length ? percentages[score] : '0%';
        bar.style.backgroundColor = colors[score];
        
        text.textContent = p.length ? labels[score] : '';
        text.style.color = colors[score];
    });

    document.getElementById('resetForm')?.addEventListener('submit', () => {
        const btn = document.getElementById('resetBtn');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Resetting...';
    });
</script>
</body>
</html>