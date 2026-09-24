<?php
date_default_timezone_set('Africa/Lagos');
session_start();
require_once __DIR__ . '/../includes/config.php';
$pdo = getDbConnection();

if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: ../index.php'); exit();
}

$message = null;

// ── Fix single client ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fix_one'])) {
    $client_id = $_POST['client_id'] ?? '';
    $branch_id = $_POST['branch_id'] ?? '';
    if ($client_id && $branch_id) {
        $pdo->prepare("UPDATE clients SET branch_id = ? WHERE id = ?")->execute([$branch_id, $client_id]);
        $message = ['type' => 'success', 'text' => 'Client branch assigned successfully.'];
    }
}

// ── Fix all auto-fixable ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fix_all'])) {
    $stmt = $pdo->query("
        SELECT c.id, u.branch_id as officer_branch_id
        FROM clients c
        JOIN users u ON u.username = c.officer_username
        WHERE (c.branch_id IS NULL OR c.branch_id NOT IN (SELECT id FROM branches))
          AND u.branch_id IS NOT NULL
          AND u.branch_id IN (SELECT id FROM branches)
    ");
    $clients = $stmt->fetchAll();
    $count = 0;
    foreach ($clients as $c) {
        $pdo->prepare("UPDATE clients SET branch_id = ? WHERE id = ?")->execute([$c['officer_branch_id'], $c['id']]);
        $count++;
    }
    $message = ['type' => 'success', 'text' => "Fixed {$count} orphaned clients successfully."];
}

// ── Fetch orphaned clients ────────────────────────────────────────────────────
$orphaned = $pdo->query("
    SELECT c.id, c.name, c.officer_username, c.branch_id as current_branch_id, c.status,
           u.branch_id as officer_branch_id,
           b.name as officer_branch_name,
           a.name as area_name,
           z.name as zone_name,
           (SELECT COALESCE(SUM(CASE WHEN sc.amount < 0 OR LOWER(sc.type) IN ('withdrawal','return','adjust') THEN -ABS(sc.amount) ELSE sc.amount END), 0) FROM saving_collections sc WHERE sc.client_id = c.id) as savings_balance,
           (SELECT COALESCE(SUM(d.remaining_balance), 0) FROM disbursements d WHERE d.client_id = c.id AND d.remaining_balance > 0) as loan_balance
    FROM clients c
    LEFT JOIN users u ON u.username = c.officer_username
    LEFT JOIN branches b ON b.id = u.branch_id
    LEFT JOIN areas a ON a.id = b.area_id
    LEFT JOIN zones z ON z.id = a.zone_id
    WHERE c.branch_id IS NULL OR c.branch_id NOT IN (SELECT id FROM branches)
    ORDER BY z.name, a.name, b.name, c.name
")->fetchAll();

$total     = count($orphaned);
$fixable   = count(array_filter($orphaned, fn($r) => !empty($r['officer_branch_id'])));
$unfixable = $total - $fixable;

$branches = $pdo->query("SELECT b.id, b.name, a.name as area_name, z.name as zone_name FROM branches b LEFT JOIN areas a ON a.id = b.area_id LEFT JOIN zones z ON z.id = a.zone_id ORDER BY z.name, a.name, b.name")->fetchAll();

$full_name = $_SESSION['full_name'] ?? 'Admin';
$base_path = '../';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Orphaned Clients | CUPAD Admin</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
    tailwind.config = { darkMode: 'class', theme: { extend: { colors: { primary: '#3b82f6', secondary: '#8b5cf6' } } } }
    </script>
    <style>
        body { font-family: 'Inter', sans-serif; }
        .badge { display: inline-flex; align-items: center; padding: 2px 10px; border-radius: 99px; font-size: 0.72rem; font-weight: 700; }
    </style>
</head>
<body class="bg-gray-50 dark:bg-gray-900 text-gray-900 dark:text-gray-100 min-h-screen">

<!-- Header -->
<header class="bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 sticky top-0 z-50">
    <div class="max-w-7xl mx-auto px-4 py-3 flex justify-between items-center">
        <div class="flex items-center gap-3">
            <a href="dashboard.php" class="text-gray-400 hover:text-primary transition"><i class="fas fa-arrow-left"></i></a>
            <h1 class="text-lg font-extrabold text-gray-900 dark:text-white">Orphaned Clients</h1>
            <span class="badge bg-red-100 text-red-700"><?= $total ?> Found</span>
        </div>
        <div class="flex items-center gap-3">
            <?php if ($fixable > 0): ?>
            <form method="POST" onsubmit="return confirm('Fix all <?= $fixable ?> auto-fixable clients?')">
                <button name="fix_all" class="flex items-center gap-2 bg-green-600 hover:bg-green-700 text-white text-sm font-bold px-4 py-2 rounded-lg transition">
                    <i class="fas fa-magic"></i> Fix All (<?= $fixable ?>)
                </button>
            </form>
            <?php endif; ?>
            <button onclick="toggleTheme()" class="p-2 rounded-full text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-700 transition">
                <i class="fas fa-moon dark:hidden"></i><i class="fas fa-sun hidden dark:inline"></i>
            </button>
        </div>
    </div>
</header>

<main class="max-w-7xl mx-auto px-4 py-6">

    <?php if ($message): ?>
    <div class="mb-4 p-4 rounded-xl flex items-center gap-3 <?= $message['type'] === 'success' ? 'bg-green-50 text-green-800 border border-green-200' : 'bg-red-50 text-red-800 border border-red-200' ?>">
        <i class="fas <?= $message['type'] === 'success' ? 'fa-check-circle text-green-500' : 'fa-exclamation-circle text-red-500' ?>"></i>
        <?= htmlspecialchars($message['text']) ?>
    </div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="grid grid-cols-3 gap-4 mb-6">
        <div class="bg-white dark:bg-gray-800 rounded-xl p-4 border border-gray-200 dark:border-gray-700 text-center">
            <div class="text-3xl font-extrabold text-red-500"><?= $total ?></div>
            <div class="text-xs text-gray-500 font-semibold mt-1">Total Orphaned</div>
        </div>
        <div class="bg-white dark:bg-gray-800 rounded-xl p-4 border border-gray-200 dark:border-gray-700 text-center">
            <div class="text-3xl font-extrabold text-green-500"><?= $fixable ?></div>
            <div class="text-xs text-gray-500 font-semibold mt-1">Auto-Fixable</div>
        </div>
        <div class="bg-white dark:bg-gray-800 rounded-xl p-4 border border-gray-200 dark:border-gray-700 text-center">
            <div class="text-3xl font-extrabold text-orange-500"><?= $unfixable ?></div>
            <div class="text-xs text-gray-500 font-semibold mt-1">Needs Manual Fix</div>
        </div>
    </div>

    <?php if (empty($orphaned)): ?>
    <div class="bg-white dark:bg-gray-800 rounded-xl p-12 text-center border border-gray-200 dark:border-gray-700">
        <i class="fas fa-check-circle text-5xl text-green-400 mb-4"></i>
        <h3 class="text-xl font-bold text-gray-700 dark:text-gray-300">No Orphaned Clients</h3>
        <p class="text-gray-400 mt-1">All clients are properly linked to a branch.</p>
    </div>
    <?php else: ?>

    <!-- Search -->
    <div class="relative mb-4">
        <i class="fas fa-search absolute left-4 top-1/2 -translate-y-1/2 text-gray-400"></i>
        <input type="text" id="searchInput" placeholder="Search by name, officer, branch..." oninput="filterTable()"
            class="w-full pl-10 pr-4 py-2.5 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-primary">
    </div>

    <!-- Table -->
    <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm" id="orphanTable">
                <thead class="bg-gray-50 dark:bg-gray-900 text-xs font-semibold text-gray-500 uppercase">
                    <tr>
                        <th class="px-4 py-3 text-left">Client</th>
                        <th class="px-4 py-3 text-left">Officer</th>
                        <th class="px-4 py-3 text-left">Suggested Branch</th>
                        <th class="px-4 py-3 text-right">Savings</th>
                        <th class="px-4 py-3 text-right">Loan</th>
                        <th class="px-4 py-3 text-center">Status</th>
                        <th class="px-4 py-3 text-center">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                <?php foreach ($orphaned as $c): ?>
                    <?php
                        $fixable_row = !empty($c['officer_branch_id']);
                        $savings = (float)$c['savings_balance'];
                        $loan    = (float)$c['loan_balance'];
                    ?>
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50 transition orphan-row"
                        data-search="<?= strtolower($c['name'] . ' ' . $c['officer_username'] . ' ' . $c['officer_branch_name'] . ' ' . $c['zone_name']) ?>">
                        <td class="px-4 py-3">
                            <div class="font-semibold text-gray-900 dark:text-white"><?= htmlspecialchars($c['name']) ?></div>
                            <div class="text-xs text-gray-400 font-mono"><?= htmlspecialchars($c['id']) ?></div>
                        </td>
                        <td class="px-4 py-3">
                            <span class="font-semibold text-blue-600 dark:text-blue-400"><?= htmlspecialchars($c['officer_username']) ?></span>
                        </td>
                        <td class="px-4 py-3">
                            <?php if ($fixable_row): ?>
                                <div class="font-semibold"><?= htmlspecialchars($c['officer_branch_name']) ?></div>
                                <div class="text-xs text-gray-400"><?= htmlspecialchars($c['zone_name'] . ' › ' . $c['area_name']) ?></div>
                            <?php else: ?>
                                <span class="badge bg-red-100 text-red-600">No officer branch</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-right font-mono <?= $savings > 0 ? 'text-green-600' : 'text-gray-400' ?>">
                            ₦<?= number_format($savings) ?>
                        </td>
                        <td class="px-4 py-3 text-right font-mono <?= $loan > 0 ? 'text-red-500' : 'text-gray-400' ?>">
                            ₦<?= number_format($loan) ?>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <span class="badge <?= $c['status'] === 'active' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500' ?>">
                                <?= ucfirst($c['status']) ?>
                            </span>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <?php if ($fixable_row): ?>
                            <form method="POST" class="inline">
                                <input type="hidden" name="client_id" value="<?= htmlspecialchars($c['id']) ?>">
                                <input type="hidden" name="branch_id" value="<?= htmlspecialchars($c['officer_branch_id']) ?>">
                                <button name="fix_one" class="bg-green-500 hover:bg-green-600 text-white text-xs font-bold px-3 py-1.5 rounded-lg transition">
                                    <i class="fas fa-wrench mr-1"></i> Fix
                                </button>
                            </form>
                            <?php else: ?>
                            <button onclick="openManualFix('<?= htmlspecialchars($c['id']) ?>', '<?= htmlspecialchars(addslashes($c['name'])) ?>')"
                                class="bg-orange-500 hover:bg-orange-600 text-white text-xs font-bold px-3 py-1.5 rounded-lg transition">
                                <i class="fas fa-hand-pointer mr-1"></i> Assign
                            </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</main>

<!-- Manual Fix Modal -->
<div id="manualModal" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-50 hidden items-center justify-center">
    <div class="bg-white dark:bg-gray-800 rounded-2xl w-full max-w-md mx-4 shadow-2xl border border-gray-200 dark:border-gray-700">
        <div class="p-5 border-b border-gray-200 dark:border-gray-700 flex justify-between items-center">
            <div>
                <h3 class="font-extrabold text-gray-900 dark:text-white">Assign Branch</h3>
                <p class="text-xs text-gray-400 mt-0.5" id="modalClientName"></p>
            </div>
            <button onclick="closeManualFix()" class="w-8 h-8 rounded-full bg-gray-100 dark:bg-gray-700 flex items-center justify-center text-gray-500 hover:bg-gray-200 transition">
                <i class="fas fa-times text-xs"></i>
            </button>
        </div>
        <form method="POST" class="p-5">
            <input type="hidden" name="client_id" id="modalClientId">
            <div class="mb-4">
                <label class="block text-xs font-bold text-gray-500 uppercase mb-2">Select Branch</label>
                <select name="branch_id" required class="w-full border border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-700 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-primary">
                    <option value="">-- Choose Branch --</option>
                    <?php foreach ($branches as $b): ?>
                    <option value="<?= htmlspecialchars($b['id']) ?>">
                        <?= htmlspecialchars($b['zone_name'] . ' › ' . $b['area_name'] . ' › ' . $b['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="flex gap-3">
                <button type="button" onclick="closeManualFix()" class="flex-1 py-2.5 rounded-xl border border-gray-200 dark:border-gray-600 text-sm font-bold text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 transition">Cancel</button>
                <button type="submit" name="fix_one" class="flex-1 py-2.5 rounded-xl bg-primary hover:bg-blue-700 text-white text-sm font-bold transition">Assign Branch</button>
            </div>
        </form>
    </div>
</div>

<script>
    const t = localStorage.getItem('theme');
    if (t === 'dark') document.documentElement.classList.add('dark');

    function toggleTheme() {
        const isDark = document.documentElement.classList.toggle('dark');
        localStorage.setItem('theme', isDark ? 'dark' : 'light');
    }

    function filterTable() {
        const q = document.getElementById('searchInput').value.toLowerCase();
        document.querySelectorAll('.orphan-row').forEach(row => {
            row.style.display = row.dataset.search.includes(q) ? '' : 'none';
        });
    }

    function openManualFix(id, name) {
        document.getElementById('modalClientId').value = id;
        document.getElementById('modalClientName').textContent = name;
        document.getElementById('manualModal').classList.remove('hidden');
        document.getElementById('manualModal').classList.add('flex');
    }

    function closeManualFix() {
        document.getElementById('manualModal').classList.add('hidden');
        document.getElementById('manualModal').classList.remove('flex');
    }
</script>
</body>
</html>
