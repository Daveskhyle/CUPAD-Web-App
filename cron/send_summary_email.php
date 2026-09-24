<?php
// send_summary_email.php
// Usage: php send_summary_email.php daily|weekly|monthly
// Or via browser: ?key=YOUR_SECRET_CRON_KEY_123&period=daily

require_once __DIR__ . '/../includes/config.php';
date_default_timezone_set('Africa/Lagos');

$secure_cron_key = "YOUR_SECRET_CRON_KEY_123";
$is_cli = php_sapi_name() === 'cli';

if (!$is_cli && (!isset($_GET['key']) || $_GET['key'] !== $secure_cron_key)) {
    http_response_code(403);
    die("Unauthorized.\n");
}

$period = $is_cli ? ($argv[1] ?? 'daily') : ($_GET['period'] ?? 'monthly');
if (!in_array($period, ['daily', 'weekly', 'monthly'])) {
    die("Invalid period. Use: daily, weekly, or monthly\n");
}

try {
    $pdo = getDbConnection();
} catch (Exception $e) {
    die("DB connection failed: " . $e->getMessage() . "\n");
}

$today = date('Y-m-d');
switch ($period) {
    case 'weekly':
        $date_from = date('Y-m-d', strtotime('monday this week'));
        $date_to   = date('Y-m-d', strtotime('sunday this week'));
        $label     = 'Weekly (' . date('M d', strtotime($date_from)) . ' - ' . date('M d, Y', strtotime($date_to)) . ')';
        break;
    case 'monthly':
        $date_from = date('Y-m-01');
        $date_to   = date('Y-m-t');
        $label     = 'Monthly (' . date('F Y') . ')';
        break;
    default:
        $date_from = $today;
        $date_to   = $today;
        $label     = 'Daily (' . date('l, F j, Y') . ')';
        break;
}

$users = $pdo->query("SELECT id, username, full_name, name, email, role, branch_id, area_id, zone_id FROM users WHERE status = 'active' AND email IS NOT NULL AND email != ''")->fetchAll();

if (empty($users)) {
    die("No users with email found.\n");
}

// Reusable savings CASE expressions matching tm/zones.php exactly
function savCases(string $prefix = ''): array {
    $a = $prefix ? "{$prefix}.amount" : 'amount';
    $t = $prefix ? "LOWER({$prefix}.type)" : 'LOWER(type)';
    return [
        "dep" => "CASE WHEN {$a} < 0 OR {$t} IN ('withdrawal','return','adjust') THEN 0 ELSE {$a} END",
        "wit" => "CASE WHEN {$a} < 0 OR {$t} IN ('withdrawal','return','adjust') THEN ABS({$a}) ELSE 0 END",
        "net" => "CASE WHEN {$a} < 0 OR {$t} IN ('withdrawal','return','adjust') THEN -ABS({$a}) ELSE {$a} END",
    ];
}

function querySavings(PDO $pdo, string $where, array $params, string $prefix = 'sc'): array {
    $c = savCases($prefix);
    $tbl = $prefix === 'sc' ? 'saving_collections sc' : 'saving_collections';
    $stmt = $pdo->prepare("SELECT
        COALESCE(SUM({$c['dep']}), 0) AS total_deposits,
        COALESCE(SUM({$c['wit']}), 0) AS total_withdrawals,
        COALESCE(SUM({$c['net']}), 0) AS net_savings,
        COUNT(*) AS total_transactions,
        COUNT(DISTINCT " . ($prefix === 'sc' ? 'sc.client_id' : 'client_id') . ") AS unique_clients
        FROM {$tbl} {$where}");
    $stmt->execute($params);
    return $stmt->fetch();
}

function queryLoans(PDO $pdo, string $where, array $params, string $prefix = 'lc'): array {
    $tbl = $prefix === 'lc' ? 'loan_collections lc' : 'loan_collections';
    $ac  = $prefix === 'lc' ? 'lc.amount_collected' : 'amount_collected';
    $tp  = $prefix === 'lc' ? 'lc.type' : 'type';
    $ci  = $prefix === 'lc' ? 'lc.client_id' : 'client_id';
    $stmt = $pdo->prepare("SELECT
        COALESCE(SUM({$ac}), 0) AS total_collected,
        COALESCE(SUM(CASE WHEN {$tp}='penalty' THEN {$ac} ELSE 0 END), 0) AS total_penalties,
        COUNT(*) AS total_transactions,
        COUNT(DISTINCT {$ci}) AS unique_clients
        FROM {$tbl} {$where}");
    $stmt->execute($params);
    return $stmt->fetch();
}

$log  = ["=== SUMMARY EMAIL CRON [{$period}] " . date('Y-m-d H:i:s') . " ==="];
$sent = 0;
$failed = 0;

foreach ($users as $user) {
    $officer  = $user['username'];
    $name     = $user['full_name'] ?? $user['name'] ?? $officer;
    $email    = $user['email'];
    $role     = $user['role'];
    $savings  = $loans = null;
    $breakdown = [];
    $breakdown_title = '';
    $scope    = '';

    if ($role === 'co') {
        $savings = querySavings($pdo, "WHERE officer = ? AND DATE(date) BETWEEN ? AND ?", [$officer, $date_from, $date_to], '');
        $loans   = queryLoans($pdo,   "WHERE officer = ? AND DATE(date) BETWEEN ? AND ?", [$officer, $date_from, $date_to], '');
        $scope   = 'Personal';

    } elseif ($role === 'bm' && !empty($user['branch_id'])) {
        $bid = $user['branch_id'];
        $savings = querySavings($pdo, "JOIN clients c ON c.id = sc.client_id WHERE c.branch_id = ? AND DATE(sc.date) BETWEEN ? AND ?", [$bid, $date_from, $date_to]);
        $loans   = queryLoans($pdo,   "JOIN clients c ON c.id = lc.client_id WHERE c.branch_id = ? AND DATE(lc.date) BETWEEN ? AND ?", [$bid, $date_from, $date_to]);
        $scope   = 'Branch';

        // Breakdown: each CO in this branch
        $breakdown_title = 'Credit Officers Breakdown';
        $cos = $pdo->prepare("SELECT id, username, full_name FROM users WHERE branch_id = ? AND role = 'co' AND status = 'active' ORDER BY username");
        $cos->execute([$bid]);
        foreach ($cos->fetchAll() as $co) {
            $cs = querySavings($pdo, "WHERE officer = ? AND DATE(date) BETWEEN ? AND ?", [$co['username'], $date_from, $date_to], '');
            $cl = queryLoans($pdo,   "WHERE officer = ? AND DATE(date) BETWEEN ? AND ?", [$co['username'], $date_from, $date_to], '');
            $breakdown[] = ['name' => $co['full_name'] ?: $co['username'], 'savings' => $cs, 'loans' => $cl];
        }

    } elseif ($role === 'am' && !empty($user['area_id'])) {
        $aid = $user['area_id'];
        $savings = querySavings($pdo, "JOIN clients c ON c.id = sc.client_id JOIN branches b ON b.id = c.branch_id WHERE b.area_id = ? AND DATE(sc.date) BETWEEN ? AND ?", [$aid, $date_from, $date_to]);
        $loans   = queryLoans($pdo,   "JOIN clients c ON c.id = lc.client_id JOIN branches b ON b.id = c.branch_id WHERE b.area_id = ? AND DATE(lc.date) BETWEEN ? AND ?", [$aid, $date_from, $date_to]);
        $scope   = 'Area';

        // Breakdown: each Branch in this area
        $breakdown_title = 'Branches Breakdown';
        $brs = $pdo->prepare("SELECT id, name FROM branches WHERE area_id = ? AND status = 'active' ORDER BY name");
        $brs->execute([$aid]);
        foreach ($brs->fetchAll() as $br) {
            $bs = querySavings($pdo, "JOIN clients c ON c.id = sc.client_id WHERE c.branch_id = ? AND DATE(sc.date) BETWEEN ? AND ?", [$br['id'], $date_from, $date_to]);
            $bl = queryLoans($pdo,   "JOIN clients c ON c.id = lc.client_id WHERE c.branch_id = ? AND DATE(lc.date) BETWEEN ? AND ?", [$br['id'], $date_from, $date_to]);
            $breakdown[] = ['name' => $br['name'], 'savings' => $bs, 'loans' => $bl];
        }

    } elseif (in_array($role, ['zm', 'dzm']) && !empty($user['zone_id'])) {
        $zid = $user['zone_id'];
        $savings = querySavings($pdo, "JOIN clients c ON c.id = sc.client_id JOIN branches b ON b.id = c.branch_id JOIN areas a ON a.id = b.area_id WHERE a.zone_id = ? AND DATE(sc.date) BETWEEN ? AND ?", [$zid, $date_from, $date_to]);
        $loans   = queryLoans($pdo,   "JOIN clients c ON c.id = lc.client_id JOIN branches b ON b.id = c.branch_id JOIN areas a ON a.id = b.area_id WHERE a.zone_id = ? AND DATE(lc.date) BETWEEN ? AND ?", [$zid, $date_from, $date_to]);
        $scope   = 'Zone';

        // Breakdown: each Area in this zone
        $breakdown_title = 'Areas Breakdown';
        $ars = $pdo->prepare("SELECT id, name FROM areas WHERE zone_id = ? ORDER BY name");
        $ars->execute([$zid]);
        foreach ($ars->fetchAll() as $ar) {
            $as = querySavings($pdo, "JOIN clients c ON c.id = sc.client_id JOIN branches b ON b.id = c.branch_id WHERE b.area_id = ? AND DATE(sc.date) BETWEEN ? AND ?", [$ar['id'], $date_from, $date_to]);
            $al = queryLoans($pdo,   "JOIN clients c ON c.id = lc.client_id JOIN branches b ON b.id = c.branch_id WHERE b.area_id = ? AND DATE(lc.date) BETWEEN ? AND ?", [$ar['id'], $date_from, $date_to]);
            $breakdown[] = ['name' => $ar['name'], 'savings' => $as, 'loans' => $al];
        }

    } elseif (in_array($role, ['tm', 'admin'])) {
        $savings = querySavings($pdo, "WHERE DATE(date) BETWEEN ? AND ?", [$date_from, $date_to], '');
        $loans   = queryLoans($pdo,   "WHERE DATE(date) BETWEEN ? AND ?", [$date_from, $date_to], '');
        $scope   = 'System-Wide';

        // Breakdown: each Zone
        $breakdown_title = 'Zones Breakdown';
        $zns = $pdo->query("SELECT id, name FROM zones ORDER BY name")->fetchAll();
        foreach ($zns as $zn) {
            $zs = querySavings($pdo, "JOIN clients c ON c.id = sc.client_id JOIN branches b ON b.id = c.branch_id JOIN areas a ON a.id = b.area_id WHERE a.zone_id = ? AND DATE(sc.date) BETWEEN ? AND ?", [$zn['id'], $date_from, $date_to]);
            $zl = queryLoans($pdo,   "JOIN clients c ON c.id = lc.client_id JOIN branches b ON b.id = c.branch_id JOIN areas a ON a.id = b.area_id WHERE a.zone_id = ? AND DATE(lc.date) BETWEEN ? AND ?", [$zn['id'], $date_from, $date_to]);
            $breakdown[] = ['name' => $zn['name'], 'savings' => $zs, 'loans' => $zl];
        }

        // Add unassigned row for clients with no branch/zone link
        $us = querySavings($pdo, "JOIN clients c ON c.id = sc.client_id LEFT JOIN branches b ON b.id = c.branch_id LEFT JOIN areas a ON a.id = b.area_id WHERE a.zone_id IS NULL AND DATE(sc.date) BETWEEN ? AND ?", [$date_from, $date_to]);
        $ul = queryLoans($pdo,   "JOIN clients c ON c.id = lc.client_id LEFT JOIN branches b ON b.id = c.branch_id LEFT JOIN areas a ON a.id = b.area_id WHERE a.zone_id IS NULL AND DATE(lc.date) BETWEEN ? AND ?", [$date_from, $date_to]);
        if ($us['total_transactions'] > 0 || $ul['total_transactions'] > 0) {
            $breakdown[] = ['name' => 'Unassigned', 'savings' => $us, 'loans' => $ul];
        }

    } else {
        $log[] = "Skipped {$officer} ({$role}): No scope defined.";
        continue;
    }

    if ($savings['total_transactions'] == 0 && $loans['total_transactions'] == 0) {
        $log[] = "Skipped {$officer} ({$role}): No activity in period.";
        continue;
    }

    $subject = "CUPAD - {$label} {$scope} Collection Summary";
    $html    = buildEmailHtml($name, $label, $savings, $loans, $period, $scope, strtoupper($role), $breakdown, $breakdown_title);

    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: CUPAD System <noreply@cupad.com>\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion();

    if (@mail($email, $subject, $html, $headers)) {
        $log[] = "Sent to {$officer} ({$role}) <{$email}>";
        $sent++;
    } else {
        $log[] = "FAILED to send to {$officer} ({$role}) <{$email}>";
        $failed++;
    }
}

$log[] = "Done. Sent: {$sent} | Failed: {$failed}";
$output = implode("\n", $log) . "\n\n";
file_put_contents(__DIR__ . '/cron_log.txt', $output, FILE_APPEND);
echo $output;

function buildEmailHtml(string $name, string $label, array $savings, array $loans, string $period, string $scope, string $role, array $breakdown, string $breakdown_title): string
{
    $fmt       = fn($n) => '&#8358;' . number_format((float)$n, 2);
    $netColor  = (float)$savings['net_savings'] < 0 ? '#dc2626' : '#2563eb';
    $td        = "padding:9px 12px;font-size:0.85rem;color:#374151;border-bottom:1px solid #e5e7eb;";
    $tdR       = "padding:9px 12px;font-size:0.85rem;color:#374151;border-bottom:1px solid #e5e7eb;text-align:right;";
    $th        = "padding:9px 12px;font-size:0.78rem;font-weight:600;text-align:left;";
    $thR       = "padding:9px 12px;font-size:0.78rem;font-weight:600;text-align:right;";

    $periodNote = match($period) {
        'weekly'  => 'This is your weekly summary.',
        'monthly' => 'This is your monthly summary.',
        default   => 'This is your daily summary.',
    };

    $scopeBadge = "<span style='display:inline-block;background:#e0f2fe;color:#0369a1;font-size:0.75rem;font-weight:600;padding:2px 10px;border-radius:99px;margin-left:6px;'>{$scope}</span>";

    // Summary tables
    $dep  = $fmt($savings['total_deposits']);
    $wit  = $fmt($savings['total_withdrawals']);
    $net  = $fmt($savings['net_savings']);
    $stx  = $savings['total_transactions'];
    $scli = $savings['unique_clients'];
    $col  = $fmt($loans['total_collected']);
    $pen  = $fmt($loans['total_penalties']);
    $ltx  = $loans['total_transactions'];
    $lcli = $loans['unique_clients'];

    // Breakdown table HTML
    $breakdownHtml = '';
    if (!empty($breakdown) && !empty($breakdown_title)) {
        $rows = '';
        $i = 0;
        foreach ($breakdown as $row) {
            $bg      = $i % 2 === 1 ? "background:#f9fafb;" : "";
            $nc      = (float)$row['savings']['net_savings'] < 0 ? '#dc2626' : '#16a34a';
            $rows .= "<tr style='{$bg}'>
                <td style='{$td}'><strong>{$row['name']}</strong></td>
                <td style='{$tdR}'>" . $fmt($row['savings']['total_deposits']) . "</td>
                <td style='{$tdR}color:#dc2626;'>" . $fmt($row['savings']['total_withdrawals']) . "</td>
                <td style='{$tdR}color:{$nc};font-weight:600;'>" . $fmt($row['savings']['net_savings']) . "</td>
                <td style='{$tdR}color:#2563eb;font-weight:600;'>" . $fmt($row['loans']['total_collected']) . "</td>
                <td style='{$tdR}'>{$row['loans']['total_transactions']}</td>
            </tr>";
            $i++;
        }

        $breakdownHtml = "
    <p style='margin:0 0 8px;font-size:0.95rem;font-weight:700;color:#374151;border-left:4px solid #f59e0b;padding-left:10px;'>&#128202; {$breakdown_title}</p>
    <table width='100%' cellpadding='0' cellspacing='0' style='border-collapse:collapse;border:1px solid #e2e8f0;overflow:hidden;margin-bottom:28px;font-size:0.82rem;'>
      <thead>
        <tr style='background:#fffbeb;'>
          <th style='{$th}color:#92400e;'>Name</th>
          <th style='{$thR}color:#92400e;'>Deposits</th>
          <th style='{$thR}color:#92400e;'>Withdrawals</th>
          <th style='{$thR}color:#92400e;'>Net Savings</th>
          <th style='{$thR}color:#92400e;'>Loan Collected</th>
          <th style='{$thR}color:#92400e;'>Txns</th>
        </tr>
      </thead>
      <tbody>{$rows}</tbody>
    </table>";
    }

    return "<!DOCTYPE html><html><body style='margin:0;padding:20px;background:#f1f5f9;'>
<div style='font-family:Arial,sans-serif;max-width:680px;margin:auto;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e2e8f0;box-shadow:0 4px 16px rgba(0,0,0,0.07);'>

  <div style='background:linear-gradient(135deg,#3b82f6,#8b5cf6);padding:28px 32px;text-align:center;'>
    <h1 style='color:#fff;margin:0 0 4px;font-size:1.5rem;letter-spacing:1px;'>CUPAD</h1>
    <p style='color:rgba(255,255,255,0.85);margin:0;font-size:0.9rem;'>{$label} Collection Summary &bull; {$role}</p>
  </div>

  <div style='padding:28px 32px;'>
    <p style='color:#374151;margin:0 0 4px;'>Hello <strong>{$name}</strong>,</p>
    <p style='color:#6b7280;font-size:0.88rem;margin:0 0 24px;'>{$periodNote} Here is a breakdown of your {$scopeBadge} collection activity.</p>

    <p style='margin:0 0 8px;font-size:0.95rem;font-weight:700;color:#1e40af;border-left:4px solid #3b82f6;padding-left:10px;'>&#128176; Savings Collections</p>
    <table width='100%' cellpadding='0' cellspacing='0' style='border-collapse:collapse;border:1px solid #e2e8f0;overflow:hidden;margin-bottom:28px;'>
      <thead><tr style='background:#eff6ff;'>
        <th style='{$th}color:#1e40af;'>Description</th>
        <th style='{$thR}color:#1e40af;'>Amount / Count</th>
      </tr></thead>
      <tbody>
        <tr><td style='{$td}'>Total Deposits</td><td style='{$tdR}'><strong style='color:#16a34a;'>{$dep}</strong></td></tr>
        <tr style='background:#f9fafb;'><td style='{$td}'>Total Withdrawals</td><td style='{$tdR}'><strong style='color:#dc2626;'>{$wit}</strong></td></tr>
        <tr><td style='{$td}'>Net Savings</td><td style='{$tdR}'><strong style='color:{$netColor};'>{$net}</strong></td></tr>
        <tr style='background:#f9fafb;'><td style='{$td}'>No. of Transactions</td><td style='{$tdR}'>{$stx}</td></tr>
        <tr><td style='{$td}border-bottom:none;'>Unique Clients Served</td><td style='{$tdR}border-bottom:none;'>{$scli}</td></tr>
      </tbody>
    </table>

    <p style='margin:0 0 8px;font-size:0.95rem;font-weight:700;color:#6d28d9;border-left:4px solid #8b5cf6;padding-left:10px;'>&#127974; Loan Collections</p>
    <table width='100%' cellpadding='0' cellspacing='0' style='border-collapse:collapse;border:1px solid #e2e8f0;overflow:hidden;margin-bottom:28px;'>
      <thead><tr style='background:#f5f3ff;'>
        <th style='{$th}color:#6d28d9;'>Description</th>
        <th style='{$thR}color:#6d28d9;'>Amount / Count</th>
      </tr></thead>
      <tbody>
        <tr><td style='{$td}'>Total Repayments Collected</td><td style='{$tdR}'><strong style='color:#2563eb;'>{$col}</strong></td></tr>
        <tr style='background:#f9fafb;'><td style='{$td}'>Penalties Collected</td><td style='{$tdR}'><strong style='color:#d97706;'>{$pen}</strong></td></tr>
        <tr><td style='{$td}'>No. of Transactions</td><td style='{$tdR}'>{$ltx}</td></tr>
        <tr style='background:#f9fafb;'><td style='{$td}border-bottom:none;'>Unique Clients Served</td><td style='{$tdR}border-bottom:none;'>{$lcli}</td></tr>
      </tbody>
    </table>

    {$breakdownHtml}

    <p style='color:#9ca3af;font-size:0.78rem;margin:0;'>If you believe there is an error in this summary, please contact your administrator.</p>
  </div>

  <div style='background:#f8fafc;border-top:1px solid #e2e8f0;padding:14px 32px;text-align:center;'>
    <p style='color:#cbd5e1;font-size:0.75rem;margin:0;'>CUPAD System &mdash; Comeup Poverty Alleviation Development</p>
  </div>

</div>
</body></html>";
}
