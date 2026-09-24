<?php
// One-time setup: creates the internal "Company Fund (Lapsed)" client account.
// Run this ONCE, then delete or restrict access to this file.
session_start();
require_once '../includes/config.php';
$pdo = getDbConnection();

if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: ../index.php'); exit();
}

define('LAPSED_FUND_CLIENT_ID', 'COMPANY-FUND-LAPSED');

$msg = '';
$exists = $pdo->prepare("SELECT id FROM clients WHERE id = ?");
$exists->execute([LAPSED_FUND_CLIENT_ID]);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($exists->fetch()) {
        $msg = 'Already exists.';
    } else {
        $pdo->beginTransaction();
        $pdo->prepare("INSERT INTO clients (id, name, `union`, status, date_registered) VALUES (?, 'Company Fund (Lapsed)', 'INTERNAL', 'active', CURDATE())")
            ->execute([LAPSED_FUND_CLIENT_ID]);
        $pdo->prepare("INSERT INTO saving_balances (client_id, balance) VALUES (?, 0.00)")
            ->execute([LAPSED_FUND_CLIENT_ID]);
        $pdo->commit();
        $msg = 'Company Fund (Lapsed) client created successfully. ID: ' . LAPSED_FUND_CLIENT_ID;
    }
    $exists->execute([LAPSED_FUND_CLIENT_ID]);
}

$fundClient = $exists->fetch();
?>
<!DOCTYPE html><html><head><title>Setup Lapsed Fund</title>
<style>body{font-family:sans-serif;max-width:600px;margin:3rem auto;padding:2rem;} .msg{padding:1rem;border-radius:8px;margin-bottom:1rem;} .ok{background:#d1fae5;color:#065f46;} .info{background:#dbeafe;color:#1e40af;}</style>
</head><body>
<h2>Setup: Company Fund (Lapsed)</h2>
<?php if($msg): ?><div class="msg ok"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if($fundClient): ?>
    <div class="msg info">
        <b>Fund client exists.</b><br>
        ID: <?= htmlspecialchars($fundClient['id']) ?><br>
        Current balance: ₦<?= number_format($pdo->query("SELECT balance FROM saving_balances WHERE client_id = '".LAPSED_FUND_CLIENT_ID."'")->fetchColumn(), 2) ?>
    </div>
    <p>Setup complete. You may delete this file.</p>
<?php else: ?>
    <form method="POST">
        <p>This will create an internal client <b>"Company Fund (Lapsed)"</b> with ID <code><?= LAPSED_FUND_CLIENT_ID ?></code> to receive all lapsed savings transfers.</p>
        <button type="submit" style="padding:0.75rem 1.5rem;background:#2563eb;color:white;border:none;border-radius:8px;cursor:pointer;font-size:1rem;">Create Fund Client</button>
    </form>
<?php endif; ?>
<br><a href="dashboard.php">← Back to Dashboard</a>
</body></html>
