<?php
/**
 * adjust_client_savings.php
 * Admin tool to adjust client savings balance
 * For CUPAD (Credit Union Management System)
 */

session_start();
require_once '../includes/config.php';
$conn = getDbConnection();

// --- AUTH CHECK ---
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: ../index.php');
    exit();
}

$base_path = '../';
$current_user = $_SESSION['username'] ?? 'Unknown';
$message = '';
$message_type = '';

// --- PROCESS FORM SUBMISSION ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['adjust_savings'])) {
    try {
        $client_id = $_POST['client_id'] ?? '';
        $adjustment_type = $_POST['adjustment_type'] ?? '';
        $amount = floatval($_POST['amount'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');

        if (empty($client_id) || empty($adjustment_type) || $amount <= 0 || empty($reason)) {
            throw new Exception("All fields are required and amount must be greater than zero.");
        }

        $stmt = $conn->prepare("SELECT id, name, phone FROM clients WHERE id = ?");
        $stmt->execute([$client_id]);
        $client = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$client) {
            throw new Exception("Client not found.");
        }

        // Fetch current balance from saving_balances table
        $stmt = $conn->prepare("SELECT balance FROM saving_balances WHERE client_id = ?");
        $stmt->execute([$client_id]);
        $current_balance = floatval($stmt->fetchColumn() ?: 0);

        // Calculate new balance
        $new_balance = $adjustment_type === 'add' ? $current_balance + $amount : $current_balance - $amount;

        if ($adjustment_type === 'subtract' && $new_balance < 0) {
            throw new Exception("Insufficient balance. Current balance: ₦" . number_format($current_balance, 2));
        }

        $conn->beginTransaction();

        // 1. DELETE old entry first to avoid duplicates (matching your system's logic)
        $stmt = $conn->prepare("DELETE FROM saving_balances WHERE client_id = ?");
        $stmt->execute([$client_id]);

        // 2. INSERT the updated balance
        $stmt = $conn->prepare("INSERT INTO saving_balances (client_id, balance, last_updated) VALUES (?, ?, NOW())");
        $stmt->execute([$client_id, $new_balance]);

        $conn->commit();

        $message = "Savings adjusted successfully! {$client['name']}'s balance changed from ₦" . 
                   number_format($current_balance, 2) . " to ₦" . number_format($new_balance, 2);
        $message_type = 'success';

    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        $message = "Error: " . $e->getMessage();
        $message_type = 'error';
    }
}

// --- FETCH CLIENTS FOR DROPDOWN ---
$clients =[];
try {
    // Modified to join with saving_balances instead of savings
    $stmt = $conn->query("
        SELECT c.id, c.name, c.phone, c.officer_username, b.name as branch_name, u.full_name as officer_name,
        COALESCE(sb.balance, 0) as saving_balance
        FROM clients c 
        LEFT JOIN branches b ON c.branch_id = b.id 
        LEFT JOIN saving_balances sb ON c.id = sb.client_id
        LEFT JOIN users u ON c.officer_username = u.username
        WHERE c.status = 'active' 
        ORDER BY c.name ASC 
        LIMIT 500
    ");
    $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $message = "Error loading clients: " . $e->getMessage();
    $message_type = 'error';
}

// --- FETCH RECENT ADJUSTMENTS ---
$recent_adjustments =[];
// History array left empty since we are only updating the saving_balances table now
?>
<!DOCTYPE html>
<html lang="en" class="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Adjust Client Savings - CUPAD Admin</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />
    <style>
        :root { 
            --primary: #2563eb; --primary-dark: #1d4ed8; --success: #059669; 
            --warning: #d97706; --danger: #dc2626; --info: #0284c7;
            --bg-body: #f1f5f9; --bg-surface: #ffffff; --text-main: #0f172a;
            --text-muted: #64748b; --border: #e2e8f0; --radius-lg: 16px;
            --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1);
        }
        html.dark { 
            --bg-body: #0f172a; --bg-surface: #1e293b; --text-main: #f8fafc;
            --text-muted: #94a3b8; --border: #334155;
        }
        * { box-sizing: border-box; margin: 0; }
        body { 
            font-family: 'Plus Jakarta Sans', sans-serif; 
            background: var(--bg-body); color: var(--text-main);
            padding: 2rem; line-height: 1.6;
        }
        .container { max-width: 900px; margin: 0 auto; }
        .card { 
            background: var(--bg-surface); border: 1px solid var(--border);
            border-radius: var(--radius-lg); padding: 2rem; margin-bottom: 1.5rem;
            box-shadow: var(--shadow-md);
        }
        .card-header { 
            display: flex; align-items: center; gap: 0.75rem;
            margin-bottom: 1.5rem; padding-bottom: 1rem;
            border-bottom: 2px solid var(--border);
        }
        .card-title { font-size: 1.25rem; font-weight: 700; }
        .alert { 
            padding: 1rem; border-radius: 8px; margin-bottom: 1rem;
            display: flex; align-items: center; gap: 0.75rem;
        }
        .alert-success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
        .alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
        .form-group { margin-bottom: 1.25rem; }
        .form-label { 
            display: block; margin-bottom: 0.5rem; 
            font-weight: 600; font-size: 0.9rem;
        }
        .form-control, .form-select {
            width: 100%; padding: 0.75rem 1rem;
            border: 1px solid var(--border); border-radius: 8px;
            background: var(--bg-body); color: var(--text-main);
            font-family: inherit; font-size: 0.95rem;
            transition: all 0.2s;
        }
        .form-control:focus, .form-select:focus {
            outline: none; border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37,99,235,0.1);
        }
        .input-group { display: flex; gap: 0.5rem; }
        .input-group .form-control { flex: 1; }
        .btn {
            padding: 0.75rem 1.5rem; border: none; border-radius: 8px;
            font-weight: 600; cursor: pointer; transition: all 0.2s;
            display: inline-flex; align-items: center; gap: 0.5rem;
        }
        .btn-primary { background: var(--primary); color: white; }
        .btn-primary:hover { background: var(--primary-dark); }
        .btn-success { background: var(--success); color: white; }
        .btn-danger { background: var(--danger); color: white; }
        .btn-secondary { background: var(--bg-body); color: var(--text-main); border: 1px solid var(--border); }
        
        .balance-display {
            background: linear-gradient(135deg, #3b82f6, #1d4ed8);
            color: white; padding: 1.5rem; border-radius: 12px;
            text-align: center; margin-bottom: 1.5rem;
        }
        .balance-amount { font-size: 2rem; font-weight: 800; }
        .balance-label { font-size: 0.875rem; opacity: 0.9; text-transform: uppercase; }
        
        .client-info {
            background: var(--bg-body); padding: 1rem;
            border-radius: 8px; margin-bottom: 1rem;
            border-left: 4px solid var(--primary);
        }
        .client-name { font-weight: 700; font-size: 1.1rem; }
        .client-meta { color: var(--text-muted); font-size: 0.85rem; }
        
        .adjustment-type {
            display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;
            margin-bottom: 1rem;
        }
        .type-option {
            padding: 1rem; border: 2px solid var(--border);
            border-radius: 8px; cursor: pointer; text-align: center;
            transition: all 0.2s;
        }
        .type-option:hover { border-color: var(--primary); }
        .type-option.active {
            border-color: var(--primary); background: rgba(37,99,235,0.05);
        }
        .type-option.add.active { border-color: var(--success); background: rgba(5,150,105,0.05); }
        .type-option.subtract.active { border-color: var(--danger); background: rgba(220,38,38,0.05); }
        
        input[type="radio"] { display: none; }
        
        @media (max-width: 768px) {
            body { padding: 1rem; }
            .adjustment-type { grid-template-columns: 1fr; }
            .input-group { flex-direction: column; }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div style="margin-bottom: 2rem;">
            <a href="dashboard.php" class="btn btn-secondary" style="margin-bottom: 1rem;">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>
            <h1 style="font-size: 1.75rem; font-weight: 800;">
                <i class="fas fa-wallet" style="color: var(--primary);"></i>
                Adjust Client Savings
            </h1>
            <p style="color: var(--text-muted);">Manually adjust client savings balance</p>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?>">
                <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <!-- Adjustment Form -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-sliders-h" style="color: var(--primary);"></i>
                <span class="card-title">New Adjustment</span>
            </div>

            <form method="POST" action="" id="adjustForm">
                <!-- Client Selection -->
                <div class="form-group">
                    <label class="form-label">Select Client *</label>
                    <input type="text" id="clientSearch" class="form-control" placeholder="Search by name, ID, or phone..." style="margin-bottom: 0.5rem;" oninput="filterClients()">
                    <select name="client_id" id="clientSelect" class="form-select" required onchange="updateClientInfo()" size="8" style="height: 200px;">
                        <option value="">-- Choose a client --</option>
                        <?php foreach ($clients as $client): ?>
                            <option value="<?php echo htmlspecialchars($client['id']); ?>" 
                                    data-balance="<?php echo floatval($client['saving_balance']); ?>"
                                    data-phone="<?php echo htmlspecialchars($client['phone'] ?? 'N/A'); ?>"
                                    data-branch="<?php echo htmlspecialchars($client['branch_name'] ?? 'N/A'); ?>"
                                    data-officer="<?php echo htmlspecialchars($client['officer_name'] ?? $client['officer_username'] ?? 'N/A'); ?>"
                                    data-search="<?php echo strtolower(htmlspecialchars($client['name'] . ' ' . $client['id'] . ' ' . ($client['phone'] ?? ''))); ?>">
                                <?php echo htmlspecialchars($client['name']); ?> 
                                (₦<?php echo number_format(floatval($client['saving_balance']), 2); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Client Info Display -->
                <div id="clientInfo" class="client-info" style="display: none;">
                    <div class="client-name" id="selectedClientName"></div>
                    <div class="client-meta">
                        <span id="selectedClientPhone"></span> • 
                        <span id="selectedClientBranch"></span> • 
                        <span id="selectedClientOfficer"></span>
                    </div>
                </div>

                <!-- Current Balance Display -->
                <div id="balanceDisplay" class="balance-display" style="display: none;">
                    <div class="balance-label">Current Savings Balance</div>
                    <div class="balance-amount" id="currentBalance">₦0.00</div>
                </div>

                <!-- Adjustment Type -->
                <div class="form-group">
                    <label class="form-label">Adjustment Type *</label>
                    <div class="adjustment-type">
                        <label class="type-option add">
                            <input type="radio" name="adjustment_type" value="add" required onchange="updateType(this)">
                            <i class="fas fa-plus-circle" style="font-size: 1.5rem; color: var(--success); margin-bottom: 0.5rem;"></i>
                            <div style="font-weight: 600;">Add Funds</div>
                            <div style="font-size: 0.75rem; color: var(--text-muted);">Credit account</div>
                        </label>
                        <label class="type-option subtract">
                            <input type="radio" name="adjustment_type" value="subtract" required onchange="updateType(this)">
                            <i class="fas fa-minus-circle" style="font-size: 1.5rem; color: var(--danger); margin-bottom: 0.5rem;"></i>
                            <div style="font-weight: 600;">Deduct Funds</div>
                            <div style="font-size: 0.75rem; color: var(--text-muted);">Debit account</div>
                        </label>
                    </div>
                </div>

                <!-- Amount -->
                <div class="form-group">
                    <label class="form-label">Amount (₦) *</label>
                    <div class="input-group">
                        <span style="padding: 0.75rem; background: var(--bg-body); border: 1px solid var(--border); border-radius: 8px 0 0 8px; border-right: none;">₦</span>
                        <input type="number" name="amount" class="form-control" min="1" step="0.01" required 
                               placeholder="Enter amount" style="border-radius: 0 8px 8px 0;">
                    </div>
                </div>

                <!-- Reason -->
                <div class="form-group">
                    <label class="form-label">Reason for Adjustment *</label>
                    <textarea name="reason" class="form-control" rows="3" required 
                              placeholder="e.g., Correction of previous entry, System reconciliation..."></textarea>
                    <small style="color: var(--text-muted); font-size: 0.8rem;">
                        Provide a detailed reason for modifying the balance manually.
                    </small>
                </div>

                <!-- Submit -->
                <div style="margin-top: 1.5rem; display: flex; gap: 1rem;">
                    <button type="submit" name="adjust_savings" class="btn btn-primary" style="flex: 1;">
                        <i class="fas fa-check"></i> Confirm Adjustment
                    </button>
                    <button type="reset" class="btn btn-secondary" onclick="resetForm()">
                        <i class="fas fa-undo"></i> Reset
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function filterClients() {
            const search = document.getElementById('clientSearch').value.toLowerCase();
            const select = document.getElementById('clientSelect');
            const options = select.options;
            
            for (let i = 1; i < options.length; i++) {
                const searchText = options[i].dataset.search || '';
                options[i].style.display = searchText.includes(search) ? '' : 'none';
            }
        }

        function updateClientInfo() {
            const select = document.getElementById('clientSelect');
            const option = select.options[select.selectedIndex];
            
            if (select.value) {
                const balance = parseFloat(option.dataset.balance || 0);
                
                document.getElementById('clientInfo').style.display = 'block';
                document.getElementById('balanceDisplay').style.display = 'block';
                document.getElementById('selectedClientName').textContent = option.text.split('(')[0].trim();
                document.getElementById('selectedClientPhone').textContent = '📞 ' + (option.dataset.phone || 'N/A');
                document.getElementById('selectedClientBranch').textContent = '🏢 ' + (option.dataset.branch || 'N/A');
                document.getElementById('selectedClientOfficer').textContent = '👤 ' + (option.dataset.officer || 'N/A');
                document.getElementById('currentBalance').textContent = '₦' + balance.toLocaleString('en-NG', {minimumFractionDigits: 2});
            } else {
                resetForm();
            }
        }

        function updateType(radio) {
            document.querySelectorAll('.type-option').forEach(el => el.classList.remove('active'));
            radio.closest('.type-option').classList.add('active');
        }

        function resetForm() {
            document.getElementById('clientInfo').style.display = 'none';
            document.getElementById('balanceDisplay').style.display = 'none';
            document.querySelectorAll('.type-option').forEach(el => el.classList.remove('active'));
        }

        // Form validation
        document.getElementById('adjustForm').addEventListener('submit', function(e) {
            const type = document.querySelector('input[name="adjustment_type"]:checked');
            if (!type) {
                e.preventDefault();
                alert('Please select an adjustment type (Add or Deduct).');
                return false;
            }
            
            const amount = parseFloat(document.querySelector('input[name="amount"]').value);
            if (isNaN(amount) || amount <= 0) {
                e.preventDefault();
                alert('Please enter a valid amount greater than zero.');
                return false;
            }

            const reason = document.querySelector('textarea[name="reason"]').value.trim();
            if (reason.length < 5) {
                e.preventDefault();
                alert('Please provide a detailed reason.');
                return false;
            }

            return confirm('Are you sure you want to proceed with this adjustment? This will directly update the saving_balances table.');
        });
    </script>
</body>
</html>