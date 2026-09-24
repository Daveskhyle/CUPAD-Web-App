<?php
// fix_outstanding_mismatch.php - Identify and fix outstanding balance mismatches
session_start();
require_once '../includes/config.php';

// Ensure only ZM can run this
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'zm') {
    die('Unauthorized');
}

$pdo = getDbConnection();
$user_id = $_SESSION['user_id'];

// Get ZM's zone_id
$stmt_z = $pdo->prepare("SELECT zone_id FROM users WHERE id = :uid");
$stmt_z->execute(['uid' => $user_id]);
$zone_id = $stmt_z->fetchColumn();

if (!$zone_id) {
    die('No zone assigned to this user');
}

echo "<h2>Outstanding Balance Mismatch Analysis</h2>";
echo "<p>Zone ID: $zone_id</p>";

// 1. Get stat card total (current calculation)
$sql_stat = "
    SELECT COUNT(*) as loan_count, COALESCE(SUM(d.remaining_balance), 0) as total_outstanding
    FROM disbursements d
    JOIN clients c ON d.client_id = c.id
    JOIN branches b ON c.branch_id = b.id
    JOIN areas a ON b.area_id = a.id
    WHERE a.zone_id = :zid AND d.remaining_balance > 0";
$stmt = $pdo->prepare($sql_stat);
$stmt->execute(['zid' => $zone_id]);
$stat_data = $stmt->fetch();

echo "<h3>Stat Card Calculation:</h3>";
echo "<p>Total Outstanding: ₦" . number_format($stat_data['total_outstanding']) . "</p>";
echo "<p>Active Loans: " . $stat_data['loan_count'] . "</p>";

// 2. Get area totals
$sql_areas = "
    SELECT 
        a.id,
        a.name,
        (SELECT COALESCE(SUM(d.remaining_balance), 0) 
         FROM disbursements d 
         JOIN clients c ON d.client_id = c.id 
         JOIN branches b ON c.branch_id = b.id
         WHERE b.area_id = a.id AND d.remaining_balance > 0) as outstanding,
        (SELECT COUNT(*) 
         FROM disbursements d 
         JOIN clients c ON d.client_id = c.id 
         JOIN branches b ON c.branch_id = b.id
         WHERE b.area_id = a.id AND d.remaining_balance > 0) as loan_count
    FROM areas a
    WHERE a.zone_id = :zid";
$stmt = $pdo->prepare($sql_areas);
$stmt->execute(['zid' => $zone_id]);
$areas = $stmt->fetchAll();

echo "<h3>Area Breakdown:</h3>";
$area_total = 0;
$area_loan_count = 0;
foreach ($areas as $area) {
    echo "<p><strong>{$area['name']}</strong>: ₦" . number_format($area['outstanding']) . " ({$area['loan_count']} loans)</p>";
    $area_total += $area['outstanding'];
    $area_loan_count += $area['loan_count'];
}
echo "<p><strong>Sum of Areas:</strong> ₦" . number_format($area_total) . " ({$area_loan_count} loans)</p>";

$difference = $stat_data['total_outstanding'] - $area_total;
echo "<h3>Mismatch: ₦" . number_format(abs($difference)) . "</h3>";

// 3. Find orphaned disbursements (branches with no area or wrong zone)
$sql_orphans = "
    SELECT 
        d.id as disbursement_id,
        d.client_id,
        c.name as client_name,
        d.remaining_balance,
        b.id as branch_id,
        b.name as branch_name,
        b.area_id,
        a.name as area_name,
        a.zone_id
    FROM disbursements d
    JOIN clients c ON d.client_id = c.id
    JOIN branches b ON c.branch_id = b.id
    LEFT JOIN areas a ON b.area_id = a.id
    WHERE d.remaining_balance > 0 
    AND (b.area_id IS NULL OR a.zone_id != :zid OR a.zone_id IS NULL)
    AND EXISTS (
        SELECT 1 FROM users u 
        JOIN branches ub ON u.branch_id = ub.id
        JOIN areas ua ON ub.area_id = ua.id
        WHERE ua.zone_id = :zid2 AND u.role = 'co'
    )";
$stmt = $pdo->prepare($sql_orphans);
$stmt->execute(['zid' => $zone_id, 'zid2' => $zone_id]);
$orphans = $stmt->fetchAll();

if (!empty($orphans)) {
    echo "<h3>Orphaned Disbursements (Branches with no area or wrong zone):</h3>";
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>Disbursement ID</th><th>Client</th><th>Branch</th><th>Area</th><th>Amount</th></tr>";
    $orphan_total = 0;
    foreach ($orphans as $orphan) {
        echo "<tr>";
        echo "<td>{$orphan['disbursement_id']}</td>";
        echo "<td>{$orphan['client_name']}</td>";
        echo "<td>{$orphan['branch_name']} (ID: {$orphan['branch_id']})</td>";
        echo "<td>" . ($orphan['area_name'] ?? 'NULL') . "</td>";
        echo "<td>₦" . number_format($orphan['remaining_balance']) . "</td>";
        echo "</tr>";
        $orphan_total += $orphan['remaining_balance'];
    }
    echo "</table>";
    echo "<p><strong>Total Orphaned:</strong> ₦" . number_format($orphan_total) . "</p>";
}

// 4. Check for disbursements counted in stat but not in areas
$sql_missing = "
    SELECT 
        d.id,
        c.name as client_name,
        b.name as branch_name,
        a.name as area_name,
        d.remaining_balance
    FROM disbursements d
    JOIN clients c ON d.client_id = c.id
    JOIN branches b ON c.branch_id = b.id
    JOIN areas a ON b.area_id = a.id
    WHERE a.zone_id = :zid 
    AND d.remaining_balance > 0
    AND b.area_id NOT IN (SELECT id FROM areas WHERE zone_id = :zid2)";
$stmt = $pdo->prepare($sql_missing);
$stmt->execute(['zid' => $zone_id, 'zid2' => $zone_id]);
$missing = $stmt->fetchAll();

if (!empty($missing)) {
    echo "<h3>Disbursements in wrong area:</h3>";
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>ID</th><th>Client</th><th>Branch</th><th>Area</th><th>Amount</th></tr>";
    foreach ($missing as $m) {
        echo "<tr>";
        echo "<td>{$m['id']}</td>";
        echo "<td>{$m['client_name']}</td>";
        echo "<td>{$m['branch_name']}</td>";
        echo "<td>{$m['area_name']}</td>";
        echo "<td>₦" . number_format($m['remaining_balance']) . "</td>";
        echo "</tr>";
    }
    echo "</table>";
}

echo "<hr>";
echo "<h3>Recommendations:</h3>";
echo "<ul>";
echo "<li>The stat card should use the sum of area totals to match the overview</li>";
echo "<li>Check if branches need to be assigned to correct areas</li>";
echo "<li>Verify that all areas belong to the correct zone</li>";
echo "</ul>";
?>
