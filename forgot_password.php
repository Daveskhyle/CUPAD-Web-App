<?php
date_default_timezone_set('Africa/Lagos');
require_once __DIR__ . '/includes/config.php';

session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict']);
session_start();

header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("Cache-Control: no-store");

$message =['type' => '', 'text' => ''];

try {
    $pdo = getDbConnection();
    $pdo->exec("CREATE TABLE IF NOT EXISTS password_resets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        token VARCHAR(128) NOT NULL UNIQUE,
        code VARCHAR(6) NOT NULL,
        expires_at DATETIME NOT NULL,
        used TINYINT(1) DEFAULT 0,
        created_at DATETIME DEFAULT NOW()
    )");
    $pdo->exec("ALTER TABLE password_resets MODIFY COLUMN token VARCHAR(128) NOT NULL");
    try { $pdo->exec("ALTER TABLE password_resets ADD COLUMN code VARCHAR(6) NOT NULL DEFAULT '' AFTER token"); } catch(Exception $e) {}
} catch (Exception $e) {
    error_log("password_resets setup error: " . $e->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = trim($_POST['identifier'] ?? '');

    if (empty($identifier)) {
        $message =['type' => 'error', 'text' => 'Please enter your username or email.'];
    } else {
        try {
            $stmt = $pdo->prepare("SELECT id, username, email, full_name, name FROM users WHERE (username = ? OR email = ?) AND status = 'active' LIMIT 1");
            $stmt->execute([$identifier, $identifier]);
            $user = $stmt->fetch();

            if (!$user) {
                $message =['type' => 'error', 'text' => 'No active account found with that username or email.'];
            } elseif (empty($user['email'])) {
                $message =['type' => 'error', 'text' => 'This account does not have an email address linked to it. Please contact support.'];
            } else {
                $pdo->prepare("UPDATE password_resets SET used = 1 WHERE user_id = ?")->execute([$user['id']]);

                $newToken  = bin2hex(random_bytes(32));
                $resetCode = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                
                // FIX 1: Use Database NOW() to prevent PHP vs Database Timezone mismatches
                $pdo->prepare("INSERT INTO password_resets (user_id, token, code, expires_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))")
                    ->execute([$user['id'], $newToken, $resetCode]);

                // FIX 2: Safely construct URL without double slashes (//)
                $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $_SERVER['SERVER_PORT'] == 443;
                $protocol = $isHttps ? 'https' : 'http';
                $dir = dirname($_SERVER['PHP_SELF']);
                // Remove trailing slash to prevent double slash in link
                $dir = rtrim(str_replace('\\', '/', $dir), '/');
                
                $resetLink   = $protocol . "://" . $_SERVER['HTTP_HOST'] . $dir . "/reset_password?token=" . $newToken;
                $displayName = $user['full_name'] ?? $user['name'] ?? $user['username'];
                $toEmail     = $user['email'];

                $subject  = "CUPAD - Password Reset Request";
                $htmlBody = "
                    <div style='font-family:Inter,sans-serif;max-width:520px;margin:auto;background:#f9fafb;border-radius:12px;overflow:hidden;border:1px solid #e5e7eb;'>
                        <div style='background:linear-gradient(135deg,#3b82f6,#8b5cf6);padding:28px 32px;text-align:center;'>
                            <h1 style='color:#fff;margin:0;font-size:1.5rem;'>CUPAD</h1>
                            <p style='color:rgba(255,255,255,0.9);margin:6px 0 0;font-size:0.9rem;'>Password Reset Request</p>
                        </div>
                        <div style='padding:32px;'>
                            <p style='color:#374151;font-size:1rem;'>Hello <strong>{$displayName}</strong>,</p>
                            <p style='color:#6b7280;font-size:0.95rem;line-height:1.6;'>Click the button below to reset your password. Then enter the reset code shown below into the form.</p>
                            <div style='text-align:center;margin:24px 0 16px;'>
                                <a href='{$resetLink}' style='background:linear-gradient(135deg,#3b82f6,#8b5cf6);color:#fff;padding:14px 32px;border-radius:8px;text-decoration:none;font-weight:600;font-size:1rem;display:inline-block;'>Reset My Password</a>
                            </div>
                            <p style='color:#374151;font-size:0.9rem;text-align:center;margin-bottom:8px;'>Your 6-digit reset code:</p>
                            <div style='background:#f3f4f6;border:1px dashed #d1d5db;border-radius:8px;padding:14px;text-align:center;margin-bottom:20px;'>
                                <span style='font-size:2rem;font-weight:800;letter-spacing:10px;color:#2563eb;'>{$resetCode}</span>
                            </div>
                            <p style='color:#9ca3af;font-size:0.8rem;word-break:break-all;'>Or copy this link to your browser: <br><a href='{$resetLink}' style='color:#3b82f6;'>{$resetLink}</a></p>
                            <p style='color:#9ca3af;font-size:0.8rem;'>This link and code expire in <strong>1 hour</strong>. If you did not request this, ignore this email.</p>
                            <hr style='border:none;border-top:1px solid #e5e7eb;margin:20px 0;'>
                            <p style='color:#d1d5db;font-size:0.75rem;text-align:center;'>CUPAD ICT &mdash; Comeup Poverty Alleviation Development</p>
                        </div>
                    </div>
                ";

                $headers  = "MIME-Version: 1.0\r\n";
                $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
                $headers .= "From: CUPAD ICT <noreply@cupad.com>\r\n";
                $headers .= "X-Mailer: PHP/" . phpversion();

                $emailSent = @mail($toEmail, $subject, $htmlBody, $headers);

                $parts  = explode('@', $toEmail);
                $masked = substr($parts[0], 0, 2) . str_repeat('*', max(2, strlen($parts[0]) - 2)) . '@' . $parts[1];

                if ($emailSent) {
                    $message =['type' => 'success', 'text' => "Reset link sent to <strong>{$masked}</strong>. Check your inbox and spam folder."];
                } else {
                    $message =['type' => 'error', 'text' => "Email unavailable on this server. <a href='{$resetLink}' style='text-decoration:underline;'>Click here to reset your password</a> &mdash; valid 1 hour."];
                }
            }
        } catch (Exception $e) {
            error_log("forgot_password error: " . $e->getMessage());
            $message =['type' => 'error', 'text' => 'An error occurred processing your request. Please try again.'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - CUPAD</title>
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
        .floating-label { position: absolute; left: 3rem; top: 20px; color: var(--text-sub); font-size: 1rem; pointer-events: none; transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1); }
        .form-input:focus + .floating-label, .form-input:not(:placeholder-shown) + .floating-label { top: 10px; font-size: 0.75rem; color: var(--primary); font-weight: 600; }
        .btn { width: 100%; padding: 1rem; border-radius: 1rem; border: none; background: linear-gradient(135deg, var(--primary), var(--secondary)); color: #fff; font-size: 1.05rem; font-weight: 600; cursor: pointer; transition: all 0.3s; font-family: inherit; display: flex; align-items: center; justify-content: center; gap: 0.5rem; }
        .btn:hover { transform: translateY(-2px); box-shadow: 0 10px 25px rgba(59, 130, 246, 0.3); }
        .btn:active { transform: translateY(0); }
        .btn:disabled { opacity: 0.7; cursor: not-allowed; transform: none; box-shadow: none; }
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
        <h1>Forgot Password</h1>
        <p>Enter your username or email to receive a secure reset link.</p>
    </div>

    <?php if (!empty($message['text'])): ?>
        <div class="alert <?= htmlspecialchars($message['type']) ?>" role="alert">
            <i class="fas <?= $message['type'] === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle' ?>"></i>
            <div><?= $message['text'] ?></div>
        </div>
    <?php endif; ?>

    <form method="POST" action="forgot_password.php" id="form">
        <div class="form-group">
            <input type="text" name="identifier" id="identifier" class="form-input" placeholder=" " required autocomplete="username">
            <label for="identifier" class="floating-label">Username or Email</label>
            <i class="fas fa-user icon"></i>
        </div>
        
        <button type="submit" class="btn" id="submitBtn">
            <i class="fas fa-paper-plane"></i> Send Reset Link
        </button>
    </form>

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

    document.getElementById('form').addEventListener('submit', function() {
        const btn = document.getElementById('submitBtn');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending Request...';
    });
</script>
</body>
</html>