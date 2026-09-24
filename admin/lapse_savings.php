<?php
session_start();
require_once '../includes/config.php';
$pdo = getDbConnection();

if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: ../index.php');
    exit();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

define('LAPSED_FUND_CLIENT_ID', 'COMPANY-FUND-LAPSED');

function generateUUID() {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff),
        mt_rand(0,0x0fff)|0x4000, mt_rand(0,0x3fff)|0x8000,
        mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff)
    );
}

$message = '';
$message_type = '';
$lapsed_count = 0;

// --- PROCESS LAPSE ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_lapse'])) {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $message = 'Invalid security token.';
        $message_type = 'error';
    } elseif (empty($_POST['selected_clients']) || !is_array($_POST['selected_clients'])) {
        $message = 'No clients were selected for lapsing.';
        $message_type = 'error';
    } else {
        $lapse_date = date('Y-m-d H:i:s');
        $officer    = 'HEAD OFFICE';
        $note       = 'Savings lapsed by Admin (Head Office) on ' . date('d M Y H:i');

        try {
            $pdo->beginTransaction();

            // Clean and prepare selected IDs
            $client_ids = array_filter($_POST['selected_clients']);
            if (empty($client_ids)) throw new Exception("No valid client IDs provided.");

            $placeholders = implode(',', array_fill(0, count($client_ids), '?'));

            // Re-fetch inside transaction with lock, strictly for selected IDs
            $stmt = $pdo->prepare("
                SELECT c.id AS client_id, COALESCE(sb.balance, 0) AS balance
                FROM clients c
                INNER JOIN saving_balances sb ON c.id = sb.client_id
                WHERE c.id IN ($placeholders) AND sb.balance > 0
                FOR UPDATE
            ");
            $stmt->execute($client_ids);
            $clients_to_lapse = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($clients_to_lapse)) {
                throw new Exception("None of the selected clients currently have an active savings balance above ₦0.");
            }

            // Verify company fund account exists
            $fund_row = $pdo->query("SELECT balance FROM saving_balances WHERE client_id = '" . LAPSED_FUND_CLIENT_ID . "'")->fetch();
            if (!$fund_row) throw new Exception("Company Fund (Lapsed) not set up. Run admin/setup_lapsed_fund.php first.");
            $fund_balance = floatval($fund_row['balance']);

            $insert_sc = $pdo->prepare("
                INSERT INTO saving_collections
                    (transaction_id, client_id, amount, type, date, officer, balance_after, notes)
                VALUES (?, ?, ?, 'adjust', ?, ?, 0, ?)
            ");

            $insert_fund_sc = $pdo->prepare("
                INSERT INTO saving_collections
                    (transaction_id, client_id, amount, type, date, officer, balance_after, notes)
                VALUES (?, ?, ?, 'adjust', ?, ?, ?, ?)
            ");

            $zero_balance = $pdo->prepare("
                UPDATE saving_balances SET balance = 0, last_updated = NOW()
                WHERE client_id = ?
            ");

            foreach ($clients_to_lapse as $row) {
                $tx_id = 'LPS-' . generateUUID();
                $amount = floatval($row['balance']);

                // Debit client savings
                $insert_sc->execute([$tx_id, $row['client_id'], -$amount, $lapse_date, $officer, $note]);
                $zero_balance->execute([$row['client_id']]);

                // Credit company fund
                $fund_balance += $amount;
                $fund_tx_id = 'LPF-' . generateUUID();
                $insert_fund_sc->execute([$fund_tx_id, LAPSED_FUND_CLIENT_ID, $amount, $lapse_date, $officer, $fund_balance, 'Lapsed saving transferred from client ID: ' . $row['client_id']]);

                $lapsed_count++;
            }

            // Update company fund balance
            $pdo->prepare("UPDATE saving_balances SET balance = ?, last_updated = NOW() WHERE client_id = ?")
                ->execute([$fund_balance, LAPSED_FUND_CLIENT_ID]);

            // Audit log
            try {
                $pdo->prepare("
                    INSERT INTO audit_log
                        (user_id, username, action, table_name, record_id, old_values, new_values, ip_address, user_agent)
                    VALUES (?, ?, 'LAPSE_SELECTED_SAVINGS', 'saving_balances', 'MULTIPLE', ?, ?, ?, ?)
                ")->execute([
                    $_SESSION['user_id'] ?? 0,
                    $_SESSION['username'] ?? 'admin',
                    json_encode(['clients_affected' => $lapsed_count, 'client_ids' => array_column($clients_to_lapse, 'client_id')]),
                    json_encode(['balance' => 0, 'officer' => $officer]),
                    $_SERVER['REMOTE_ADDR'],
                    $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
                ]);
            } catch (Exception $e) { /* silent fail if audit_log does not exist */ }

            $pdo->commit();

            $message = "Successfully transferred lapsed savings for {$lapsed_count} selected client(s) to the Company Fund.";
            $message_type = 'success';

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $message = 'Error: ' . $e->getMessage();
            $message_type = 'error';
        }
    }
}

// --- PREVIEW: fetch clients with savings > 0 (Runs after processing to show fresh data) ---
$preview = [];
try {
    $stmt = $pdo->query("
        SELECT c.id, c.name, c.phone, b.name AS branch_name,
               COALESCE(sb.balance, 0) AS balance
        FROM clients c
        LEFT JOIN saving_balances sb ON c.id = sb.client_id
        LEFT JOIN branches b ON c.branch_id = b.id
        WHERE COALESCE(sb.balance, 0) > 0
        ORDER BY sb.balance DESC
    ");
    $preview = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    if (empty($message)) {
        $message = 'Error loading clients: ' . $e->getMessage();
        $message_type = 'error';
    }
}

$total_savings = array_sum(array_column($preview, 'balance'));
?>
<!DOCTYPE html>
<html lang="en" class="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lapse Savings - CUPAD Admin</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <style>
        :root {
            --primary: #2563eb; --danger: #dc2626; --success: #059669; --warning: #d97706;
            --bg-body: #f8fafc; --bg-surface: #ffffff; --text-main: #0f172a;
            --text-muted: #64748b; --border: #e2e8f0; --radius: 14px;
            --shadow: 0 4px 6px -1px rgb(0 0 0 / 0.05);
        }
        html.dark {
            --bg-body: #0f172a; --bg-surface: #1e293b; --text-main: #f8fafc;
            --text-muted: #94a3b8; --border: #334155;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Plus Jakarta Sans', sans-serif; background: var(--bg-body); color: var(--text-main); padding: 2rem; line-height: 1.6; }
        .container { max-width: 1100px; margin: 0 auto; }
        .back-btn { display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.5rem 1rem; border: 1px solid var(--border); border-radius: 99px; background: var(--bg-surface); color: var(--text-muted); font-size: 0.875rem; font-weight: 600; text-decoration: none; margin-bottom: 1.5rem; transition: 0.2s; }
        .back-btn:hover { border-color: var(--primary); color: var(--primary); }
        .page-title { font-size: 1.75rem; font-weight: 800; margin-bottom: 0.25rem; display: flex; align-items: center; gap: 0.75rem; }
        .page-title i { color: var(--danger); }
        .page-sub { color: var(--text-muted); margin-bottom: 2rem; font-size: 0.95rem; }
        
        .card { background: var(--bg-surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 1.75rem; margin-bottom: 1.5rem; box-shadow: var(--shadow); }
        
        .alert { padding: 1rem 1.25rem; border-radius: 10px; margin-bottom: 1.5rem; display: flex; align-items: flex-start; gap: 0.75rem; font-weight: 500; font-size: 0.95rem;}
        .alert-success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
        .alert-error   { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
        .alert-warning { background: #fef3c7; color: #92400e; border: 1px solid #fde68a; }
        .alert i { margin-top: 3px; }

        .stats-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem; margin-bottom: 1.5rem; }
        .stat-box { background: var(--bg-surface); border: 1px solid var(--border); border-radius: 10px; padding: 1.25rem; box-shadow: var(--shadow); }
        .stat-box .label { font-size: 0.75rem; font-weight: 700; text-transform: uppercase; color: var(--text-muted); margin-bottom: 0.25rem; }
        .stat-box .value { font-size: 1.5rem; font-weight: 800; }
        .value.danger  { color: var(--danger); }
        .value.warning { color: var(--warning); }
        
        .section-title { font-size: 1.1rem; font-weight: 700; display: flex; align-items: center; gap: 0.5rem; }
        
        /* Table enhancements */
        .card-header-flex { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem; flex-wrap: wrap; gap: 1rem; }
        .search-wrapper { position: relative; flex-grow: 1; max-width: 350px; }
        .search-wrapper i { position: absolute; left: 1rem; top: 50%; transform: translateY(-50%); color: var(--text-muted); }
        .search-wrapper input { width: 100%; padding: 0.65rem 1rem 0.65rem 2.5rem; border-radius: 99px; border: 1px solid var(--border); background: var(--bg-body); color: var(--text-main); font-family: inherit; font-size: 0.9rem; transition: 0.2s; }
        .search-wrapper input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(37,99,235,0.1); }
        
        .table-wrap { overflow-y: auto; overflow-x: auto; max-height: 55vh; border-radius: 10px; border: 1px solid var(--border); position: relative; }
        table { width: 100%; border-collapse: separate; border-spacing: 0; min-width: 650px; }
        th { position: sticky; top: 0; background: var(--bg-surface); padding: 1rem; font-size: 0.75rem; text-transform: uppercase; color: var(--text-muted); font-weight: 700; text-align: left; border-bottom: 2px solid var(--border); z-index: 10; }
        td { padding: 1rem; border-bottom: 1px solid var(--border); font-size: 0.9rem; vertical-align: middle; transition: background 0.15s; }
        tr:last-child td { border-bottom: none; }
        
        /* Interactive rows & checkboxes */
        tbody tr { cursor: pointer; border-left: 3px solid transparent; }
        tbody tr:hover td { background: rgba(0, 0, 0, 0.02); }
        html.dark tbody tr:hover td { background: rgba(255, 255, 255, 0.02); }
        tbody tr.selected td { background: rgba(37, 99, 235, 0.04); }
        
        .custom-checkbox { width: 20px; height: 20px; border-radius: 6px; border: 2px solid var(--border); appearance: none; -webkit-appearance: none; outline: none; cursor: pointer; position: relative; background: var(--bg-surface); transition: all 0.2s; vertical-align: middle; flex-shrink: 0;}
        .custom-checkbox:checked { background: var(--primary); border-color: var(--primary); }
        .custom-checkbox:checked::after { content: '\f00c'; font-family: 'Font Awesome 6 Free'; font-weight: 900; color: white; font-size: 12px; position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); }
        .custom-checkbox:indeterminate { background: var(--primary); border-color: var(--primary); }
        .custom-checkbox:indeterminate::after { content: '\f068'; font-family: 'Font Awesome 6 Free'; font-weight: 900; color: white; font-size: 12px; position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); }
        
        .danger-checkbox:checked { background: var(--danger); border-color: var(--danger); }

        .balance-cell { font-weight: 800; color: var(--warning); }
        
        .form-check { display: flex; align-items: flex-start; gap: 0.85rem; padding: 1.25rem; background: var(--bg-body); border: 1px solid var(--border); border-radius: 10px; margin-bottom: 1.25rem; cursor: pointer; transition: 0.2s; }
        .form-check:hover { border-color: var(--danger); }
        .form-check label { font-weight: 700; cursor: pointer; font-size: 0.95rem; }
        .form-check small { display: block; margin-top: 4px; font-size: 0.85rem; }
        
        .danger-zone { border: 1px solid var(--danger); border-radius: var(--radius); padding: 1.75rem; background: var(--bg-surface); position: relative; overflow: hidden; }
        .danger-zone::before { content: ''; position: absolute; top: 0; left: 0; width: 4px; height: 100%; background: var(--danger); }
        .danger-zone .section-title { margin-bottom: 1.25rem; color: var(--danger); }
        
        .btn { padding: 0.75rem 1.75rem; border: none; border-radius: 99px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 0.5rem; font-family: inherit; font-size: 0.95rem; transition: 0.2s; }
        .btn-danger { background: var(--danger); color: white; }
        .btn-danger:hover:not(:disabled) { background: #b91c1c; transform: translateY(-1px); }
        .btn-danger:disabled { opacity: 0.5; cursor: not-allowed; }
        .btn-secondary { background: var(--bg-body); color: var(--text-main); border: 1px solid var(--border); }
        .btn-secondary:hover { border-color: var(--text-muted); }
        
        .empty-state { text-align: center; padding: 4rem 2rem; color: var(--text-muted); }
        .empty-state i { font-size: 3.5rem; margin-bottom: 1rem; opacity: 0.3; display: block; }
        
        .theme-btn { position: fixed; top: 1.5rem; right: 1.5rem; width: 38px; height: 38px; border-radius: 50%; border: 1px solid var(--border); background: var(--bg-surface); color: var(--text-muted); cursor: pointer; display: flex; align-items: center; justify-content: center; z-index: 100; }
    </style>
</head>
<body>
    <button class="theme-btn" id="themeBtn"><i class="fas fa-moon"></i></button>

    <div class="container">
        <a href="dashboard.php" class="back-btn"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>

        <div class="page-title"><i class="fas fa-exclamation-triangle"></i> Lapse Client Savings</div>
        <p class="page-sub">Selectively transfer unrecoverable/lapsed savings into the <strong>Company Fund</strong>. This resets the selected client's balance to ₦0 and leaves a trail assigned to 'HEAD OFFICE'.</p>

        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?>">
                <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <form method="POST" id="lapseForm">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

            <!-- Real-time Stats -->
            <div class="stats-row">
                <div class="stat-box">
                    <div class="label">Total Eligible Clients</div>
                    <div class="value"><?php echo number_format(count($preview)); ?></div>
                </div>
                <div class="stat-box">
                    <div class="label">Total Eligible Balance</div>
                    <div class="value">₦<?php echo number_format($total_savings, 2); ?></div>
                </div>
                <div class="stat-box" style="border-bottom: 3px solid var(--warning);">
                    <div class="label" style="color: var(--warning);">Clients Selected</div>
                    <div class="value warning" id="selectedCount">0</div>
                </div>
                <div class="stat-box" style="border-bottom: 3px solid var(--danger);">
                    <div class="label" style="color: var(--danger);">Amount Selected</div>
                    <div class="value danger" id="selectedTotal">₦0.00</div>
                </div>
            </div>

            <!-- Interactive Table -->
            <div class="card">
                <div class="card-header-flex">
                    <div class="section-title" style="margin:0;">
                        <i class="fas fa-list-check" style="color:var(--primary)"></i> 
                        Select Clients to Lapse
                    </div>
                    <?php if (!empty($preview)): ?>
                    <div class="search-wrapper">
                        <i class="fas fa-search"></i>
                        <input type="text" id="clientSearch" placeholder="Search by name, ID or branch...">
                    </div>
                    <?php endif; ?>
                </div>

                <?php if (!empty($preview)): ?>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th style="width: 50px; text-align: center;">
                                        <input type="checkbox" id="selectAll" class="custom-checkbox" title="Select All Visible">
                                    </th>
                                    <th>Client ID</th>
                                    <th>Name</th>
                                    <th>Phone</th>
                                    <th>Branch</th>
                                    <th style="text-align: right;">Savings Balance</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($preview as $row): ?>
                                    <tr>
                                        <td style="text-align: center;">
                                            <input type="checkbox" name="selected_clients[]" value="<?php echo htmlspecialchars($row['id']); ?>" class="custom-checkbox client-checkbox" data-balance="<?php echo $row['balance']; ?>">
                                        </td>
                                        <td><?php echo htmlspecialchars($row['id']); ?></td>
                                        <td><strong><?php echo htmlspecialchars($row['name']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($row['phone'] ?? '—'); ?></td>
                                        <td><?php echo htmlspecialchars($row['branch_name'] ?? '—'); ?></td>
                                        <td class="balance-cell" style="text-align: right;">₦<?php echo number_format($row['balance'], 2); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-piggy-bank"></i>
                        <p>No active clients currently have a savings balance above ₦0.</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Danger Action Form -->
            <?php if (!empty($preview)): ?>
            <div class="danger-zone">
                <div class="section-title"><i class="fas fa-skull-crossbones"></i> Danger Zone — Confirm Lapse Action</div>

                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle"></i>
                    <div id="dynamicWarningText">
                        <strong>Waiting for selection:</strong> You have not selected any clients to lapse yet.
                    </div>
                </div>

                <label class="form-check" style="background: rgba(220,38,38,0.03);">
                    <input type="checkbox" id="confirmCheck" class="custom-checkbox danger-checkbox">
                    <div>
                        <label for="confirmCheck" style="color: var(--danger);">I verify my selection and want to lapse these savings immediately.</label>
                        <small style="color: var(--text-muted);">Each selected client will have a permanent deduction entry recorded in their ledger.</small>
                    </div>
                </label>

                <div style="display:flex; gap:1rem; margin-top:0.5rem; flex-wrap: wrap;">
                    <button type="submit" name="confirm_lapse" id="lapseBtn" class="btn btn-danger" disabled
                            onclick="return confirm('FINAL CONFIRMATION: Are you absolutely sure you want to lapse the selected clients\' savings? This cannot be undone.');">
                        <i class="fas fa-trash-alt"></i> Lapse Selected Savings Now
                    </button>
                    <a href="dashboard.php" class="btn btn-secondary"><i class="fas fa-times"></i> Cancel</a>
                </div>
            </div>
            <?php endif; ?>
        </form>
    </div>

    <script>
        // Theme toggler
        const t = localStorage.getItem('theme');
        if (t === 'dark') document.documentElement.classList.add('dark');
        document.getElementById('themeBtn').addEventListener('click', () => {
            document.documentElement.classList.toggle('dark');
            localStorage.setItem('theme', document.documentElement.classList.contains('dark') ? 'dark' : 'light');
        });

        // Interactive Table Logic
        const searchInput = document.getElementById('clientSearch');
        const checkboxes = document.querySelectorAll('.client-checkbox');
        const selectAll = document.getElementById('selectAll');
        const selectedCountEl = document.getElementById('selectedCount');
        const selectedTotalEl = document.getElementById('selectedTotal');
        const confirmCheck = document.getElementById('confirmCheck');
        const lapseBtn = document.getElementById('lapseBtn');
        const warningText = document.getElementById('dynamicWarningText');

        function updateSelection() {
            let count = 0;
            let total = 0;
            let visibleCount = 0;
            let visibleSelected = 0;
            
            checkboxes.forEach(cb => {
                const row = cb.closest('tr');
                const isVisible = row.style.display !== 'none';
                
                if (cb.checked) {
                    count++;
                    total += parseFloat(cb.dataset.balance);
                    row.classList.add('selected');
                } else {
                    row.classList.remove('selected');
                }
                
                if (isVisible) {
                    visibleCount++;
                    if (cb.checked) visibleSelected++;
                }
            });

            if (selectedCountEl) selectedCountEl.textContent = count;
            if (selectedTotalEl) selectedTotalEl.textContent = '₦' + total.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            
            if (selectAll) {
                if (visibleCount > 0) {
                    selectAll.disabled = false;
                    selectAll.checked = (visibleSelected === visibleCount);
                    selectAll.indeterminate = (visibleSelected > 0 && visibleSelected < visibleCount);
                } else {
                    selectAll.disabled = true;
                    selectAll.checked = false;
                    selectAll.indeterminate = false;
                }
            }
            
            if (warningText) {
                if (count > 0) {
                    warningText.innerHTML = `<strong>Warning:</strong> You are about to lapse <strong>${count}</strong> selected client(s) with a combined balance of <strong>₦${total.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</strong>.`;
                } else {
                    warningText.innerHTML = `<strong>Waiting for selection:</strong> You have not selected any clients to lapse yet.`;
                }
            }

            checkSubmitBtn(count);
        }

        function checkSubmitBtn(count) {
            if (lapseBtn) {
                lapseBtn.disabled = !(count > 0 && confirmCheck && confirmCheck.checked);
            }
        }

        // Master Select All
        if (selectAll) {
            selectAll.addEventListener('change', (e) => {
                const isChecked = e.target.checked;
                checkboxes.forEach(cb => {
                    const row = cb.closest('tr');
                    // Only apply to rows currently visible from search filter
                    if (row.style.display !== 'none') {
                        cb.checked = isChecked;
                    }
                });
                updateSelection();
            });
        }

        checkboxes.forEach(cb => {
            cb.addEventListener('change', updateSelection);
        });

        // Search Filter
        if (searchInput) {
            searchInput.addEventListener('input', (e) => {
                const term = e.target.value.toLowerCase();
                document.querySelectorAll('tbody tr').forEach(row => {
                    const text = row.innerText.toLowerCase();
                    row.style.display = text.includes(term) ? '' : 'none';
                });
                updateSelection(); // Refresh SelectAll status
            });
        }

        // Allow clicking anywhere on the row to toggle checkbox
        document.querySelectorAll('tbody tr').forEach(row => {
            row.addEventListener('click', (e) => {
                // Ignore if clicked exactly on the checkbox input to prevent double firing
                if (e.target.tagName.toLowerCase() === 'input') return; 
                
                const cb = row.querySelector('.client-checkbox');
                if (cb) {
                    cb.checked = !cb.checked;
                    cb.dispatchEvent(new Event('change'));
                }
            });
        });

        // Confirm Action Checkbox
        if (confirmCheck) {
            confirmCheck.addEventListener('change', () => {
                const count = document.querySelectorAll('.client-checkbox:checked').length;
                checkSubmitBtn(count);
            });
        }

        // Initialize state on page load
        if (checkboxes.length > 0) {
            updateSelection();
        }
    </script>
</body>
</html>