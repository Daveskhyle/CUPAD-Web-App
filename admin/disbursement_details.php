<?php
// admin/disbursement_details.php
session_start();
require_once '../includes/config.php';
$pdo = getDbConnection();

// --- AUTH CHECK ---
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: ../index.php');
    exit();
}

$base_path = '../';
$transaction_id = $_GET['id'] ?? '';

if (empty($transaction_id)) {
    // Redirect back if no ID is provided
    header('Location: dashboard.php');
    exit();
}

// Fetch Disbursement Details
$stmt = $pdo->prepare("
    SELECT d.*, c.phone as client_phone, c.address as client_address, b.name as branch_name 
    FROM disbursements d
    LEFT JOIN clients c ON d.client_id = c.id
    LEFT JOIN branches b ON d.branch_id = b.id
    WHERE d.id = ?
");
$stmt->execute([$transaction_id]);
$disbursement = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$disbursement) {
    die("Disbursement record not found.");
}

// Extract Image Path from Notes
// (In your co/disbursement.php, image is appended as: " | Image: uploads/disbursements/filename.ext")
$image_path = null;
$clean_notes = $disbursement['notes'] ?? '';

if (preg_match('/\|\s*Image:\s*(.+)$/i', $clean_notes, $matches)) {
    $image_path = trim($matches[1]);
    // Remove the image path from the notes so we can display clean notes
    $clean_notes = preg_replace('/\|\s*Image:\s*.+$/i', '', $clean_notes);
}

// Helper for UI Profile
$full_name = $_SESSION['full_name'] ?? 'Admin';
$username = $_SESSION['username'] ?? '';
?>
<!DOCTYPE html>
<html lang="en" class="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Disbursement Details - CUPAD</title>
    <link rel="icon" type="image/png" href="<?php echo htmlspecialchars($base_path . 'uploads/CUPAD LOGO.png'); ?>">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />
    <style>
        :root {
            --primary: #2563eb; --primary-dark: #1d4ed8;
            --success: #059669; --warning: #d97706; --danger: #dc2626; --info: #0284c7;
            --bg-body: #f1f5f9; --bg-surface: #ffffff;
            --text-main: #0f172a; --text-muted: #64748b;
            --border: #e2e8f0; 
            --radius-lg: 16px; --radius-md: 10px;
            --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
            --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1);
            --nav-height: 70px;
        }

        html.dark {
            --bg-body: #0f172a; --bg-surface: #1e293b;
            --text-main: #f8fafc; --text-muted: #94a3b8;
            --border: #334155; --primary: #3b82f6; 
            --success: #34d399; --danger: #f87171; --warning: #fbbf24;
        }

        * { box-sizing: border-box; outline: none; }
        body { font-family: 'Plus Jakarta Sans', sans-serif; background: var(--bg-body); color: var(--text-main); margin: 0; padding-top: var(--nav-height); transition: background-color 0.3s, color 0.3s; }
        a { text-decoration: none; color: inherit; }

        /* Modern Dashboard Header */
        .main-header { position: fixed; top: 0; left: 0; right: 0; height: var(--nav-height); background: rgba(255,255,255,0.85); backdrop-filter: blur(12px); border-bottom: 1px solid var(--border); z-index: 50; transition: background 0.3s; }
        html.dark .main-header { background: rgba(15, 23, 42, 0.85); }
        .navbar { max-width: 1400px; margin: 0 auto; padding: 0 1.5rem; display: flex; align-items: center; justify-content: space-between; height: 100%; }
        .logo { display: flex; align-items: center; gap: 0.75rem; font-weight: 800; font-size: 1.35rem; color: var(--primary); }
        .logo img { height: 38px; }
        .nav-right { display: flex; align-items: center; gap: 1rem; }
        .icon-btn { width: 36px; height: 36px; border-radius: 50%; border: none; background: transparent; color: var(--text-muted); cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; transition: 0.2s; }
        .icon-btn:hover { background: var(--bg-body); color: var(--text-main); }

        /* Container & Cards */
        .container { max-width: 1200px; margin: 0 auto; padding: 2rem 1.5rem 4rem; }
        
        .page-header { margin-bottom: 2rem; display: flex; justify-content: space-between; align-items: flex-end; flex-wrap: wrap; gap: 1rem; }
        .page-title { font-size: 1.5rem; font-weight: 800; margin: 0; display: flex; align-items: center; gap: 0.5rem; }
        .page-subtitle { color: var(--text-muted); margin-top: 0.25rem; font-family: monospace; }
        
        .btn-outline { padding: 0.6rem 1.2rem; background: var(--bg-surface); border: 1px solid var(--border); border-radius: var(--radius-md); font-size: 0.9rem; font-weight: 600; display: inline-flex; align-items: center; gap: 0.5rem; cursor: pointer; transition: 0.2s; color: var(--text-main); }
        .btn-outline:hover { background: var(--bg-body); border-color: var(--primary); color: var(--primary); }

        .detail-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; }
        @media(max-width: 900px) { .detail-grid { grid-template-columns: 1fr; } }

        .card { background: var(--bg-surface); border: 1px solid var(--border); border-radius: var(--radius-lg); padding: 1.5rem; box-shadow: var(--shadow-sm); display: flex; flex-direction: column; }
        .card-header { margin-bottom: 1.25rem; border-bottom: 1px solid var(--border); padding-bottom: 1rem; display: flex; justify-content: space-between; align-items: center; }
        .card-title { font-size: 1.1rem; font-weight: 700; color: var(--text-main); display: flex; align-items: center; gap: 0.5rem; }

        /* Data Rows */
        .data-list { list-style: none; padding: 0; margin: 0; }
        .data-item { display: flex; justify-content: space-between; align-items: center; padding: 0.75rem 0; border-bottom: 1px dashed var(--border); }
        .data-item:last-child { border-bottom: none; }
        .data-label { color: var(--text-muted); font-size: 0.9rem; font-weight: 500; display: flex; align-items: center; gap: 0.5rem; }
        .data-value { color: var(--text-main); font-weight: 700; font-size: 0.95rem; text-align: right; }
        
        /* Badges */
        .badge { padding: 0.25rem 0.75rem; border-radius: 99px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; }
        .badge.active { background: rgba(5, 150, 105, 0.1); color: var(--success); }
        .badge.completed { background: rgba(37, 99, 235, 0.1); color: var(--primary); }
        .badge.default { background: var(--bg-body); color: var(--text-muted); }

        /* Image Display */
        .image-container { width: 100%; min-height: 300px; background: var(--bg-body); border-radius: var(--radius-md); border: 1px dashed var(--border); display: flex; align-items: center; justify-content: center; overflow: hidden; position: relative; }
        .image-container img { max-width: 100%; max-height: 500px; object-fit: contain; cursor: zoom-in; transition: transform 0.3s ease; }
        .image-container img:hover { transform: scale(1.02); }
        .no-image { text-align: center; color: var(--text-muted); display: flex; flex-direction: column; gap: 0.5rem; }
        .no-image i { font-size: 2.5rem; opacity: 0.5; }

        .highlight-text { font-size: 1.25rem; color: var(--primary); font-weight: 800; }
    </style>
</head>
<body>

    <header class="main-header">
        <div class="navbar">
            <a href="<?php echo htmlspecialchars($base_path . 'index.php'); ?>" class="logo">
                <img src="<?php echo htmlspecialchars($base_path . 'uploads/CUPAD LOGO.png'); ?>" alt="Logo">
                <span>CUPAD</span>
            </a>
            <div class="nav-right">
                <a href="javascript:history.back()" class="icon-btn" title="Go Back">
                    <i class="fas fa-arrow-left"></i>
                </a>
                <button class="icon-btn" id="themeToggle"><i class="fas fa-moon"></i></button>
                <div style="display:flex;align-items:center;gap:0.5rem;padding:5px 10px;border-radius:99px;background:var(--bg-body);border:1px solid var(--border);">
                    <i class="fas fa-user-circle" style="color:var(--text-muted)"></i>
                    <span style="font-size:0.85rem;font-weight:600"><?php echo htmlspecialchars($username); ?></span>
                </div>
            </div>
        </div>
    </header>

    <main class="container">
        
        <!-- Header -->
        <div class="page-header">
            <div>
                <h1 class="page-title"><i class="fas fa-file-invoice-dollar" style="color:var(--primary)"></i> Disbursement Record</h1>
                <p class="page-subtitle">TXN ID: <?php echo htmlspecialchars($disbursement['id']); ?></p>
            </div>
            <div>
                <button onclick="window.print()" class="btn-outline">
                    <i class="fas fa-print"></i> Print Record
                </button>
            </div>
        </div>

        <div class="detail-grid">
            
            <!-- Left Column: Details -->
            <div style="display: flex; flex-direction: column; gap: 1.5rem;">
                
                <!-- Financial Overview -->
                <div class="card">
                    <div class="card-header">
                        <div class="card-title"><i class="fas fa-money-check-alt"></i> Financial Details</div>
                        <?php 
                            $status_class = 'default';
                            $status_val = strtolower($disbursement['status']);
                            if ($status_val === 'active') $status_class = 'active';
                            if ($status_val === 'completed') $status_class = 'completed';
                        ?>
                        <span class="badge <?php echo $status_class; ?>"><?php echo htmlspecialchars($disbursement['status']); ?></span>
                    </div>
                    <ul class="data-list">
                        <li class="data-item">
                            <span class="data-label"><i class="fas fa-hand-holding-usd"></i> Principal Amount</span>
                            <span class="data-value highlight-text">₦<?php echo number_format($disbursement['principal'], 2); ?></span>
                        </li>
                        <li class="data-item">
                            <span class="data-label"><i class="fas fa-percentage"></i> Interest Rate</span>
                            <span class="data-value"><?php echo number_format($disbursement['interest_rate']); ?>%</span>
                        </li>
                        <li class="data-item">
                            <span class="data-label"><i class="fas fa-equals"></i> Total Payable</span>
                            <span class="data-value">₦<?php echo number_format($disbursement['total_payable'], 2); ?></span>
                        </li>
                        <li class="data-item">
                            <span class="data-label"><i class="fas fa-balance-scale-left" style="color:var(--warning)"></i> Remaining Balance</span>
                            <span class="data-value" style="color:var(--danger)">₦<?php echo number_format($disbursement['remaining_balance'], 2); ?></span>
                        </li>
                    </ul>
                </div>

                <!-- Term & Dates -->
                <div class="card">
                    <div class="card-header">
                        <div class="card-title"><i class="fas fa-calendar-alt"></i> Schedule & Terms</div>
                    </div>
                    <ul class="data-list">
                        <li class="data-item">
                            <span class="data-label">Loan Plan</span>
                            <span class="data-value"><?php echo htmlspecialchars($disbursement['loan_term_type'] ?? 'Standard'); ?></span>
                        </li>
                        <li class="data-item">
                            <span class="data-label">Installments</span>
                            <span class="data-value"><?php echo htmlspecialchars($disbursement['num_installments']); ?> Payments</span>
                        </li>
                        <li class="data-item">
                            <span class="data-label">Disbursement Date</span>
                            <span class="data-value"><?php echo date('d M Y, h:i A', strtotime($disbursement['date'])); ?></span>
                        </li>
                        <li class="data-item">
                            <span class="data-label">Expected Payoff Date</span>
                            <span class="data-value" style="color:var(--info)"><?php echo date('d M Y', strtotime($disbursement['payoff_date'])); ?></span>
                        </li>
                    </ul>
                </div>

                <!-- Entity Information -->
                <div class="card">
                    <div class="card-header">
                        <div class="card-title"><i class="fas fa-users"></i> Entity Information</div>
                    </div>
                    <ul class="data-list">
                        <li class="data-item">
                            <span class="data-label"><i class="fas fa-user text-muted"></i> Client Name</span>
                            <span class="data-value"><?php echo htmlspecialchars($disbursement['client_name']); ?></span>
                        </li>
                        <li class="data-item">
                            <span class="data-label"><i class="fas fa-phone text-muted"></i> Phone</span>
                            <span class="data-value"><?php echo htmlspecialchars($disbursement['client_phone'] ?? 'N/A'); ?></span>
                        </li>
                        <li class="data-item">
                            <span class="data-label"><i class="fas fa-user-tie text-muted"></i> Credit Officer</span>
                            <span class="data-value"><?php echo htmlspecialchars($disbursement['officer']); ?></span>
                        </li>
                        <li class="data-item">
                            <span class="data-label"><i class="fas fa-building text-muted"></i> Branch</span>
                            <span class="data-value"><?php echo htmlspecialchars($disbursement['branch_name'] ?? 'N/A'); ?></span>
                        </li>
                    </ul>
                </div>

            </div>

            <!-- Right Column: Proof Image -->
            <div style="display: flex; flex-direction: column; gap: 1.5rem;">
                <div class="card" style="flex: 1;">
                    <div class="card-header">
                        <div class="card-title"><i class="fas fa-camera"></i> Proof of Disbursement</div>
                        <a href="<?php echo htmlspecialchars($base_path . $image_path); ?>" download="Proof_<?php echo htmlspecialchars($disbursement['id']); ?>" class="btn-outline" style="padding: 0.4rem 0.8rem; font-size:0.8rem;" <?php if(!$image_path) echo 'style="display:none;"'; ?>>
                            <i class="fas fa-download"></i> Save
                        </a>
                    </div>
                    
                    <div class="image-container">
                        <?php if ($image_path && file_exists($base_path . $image_path)): ?>
                            <a href="<?php echo htmlspecialchars($base_path . $image_path); ?>" target="_blank" title="Click to view full image">
                                <img src="<?php echo htmlspecialchars($base_path . $image_path); ?>" alt="Disbursement Proof">
                            </a>
                        <?php else: ?>
                            <div class="no-image">
                                <i class="fas fa-image"></i>
                                <span>No proof image attached to this record.</span>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div style="margin-top: 1.5rem;">
                        <span class="data-label" style="margin-bottom: 0.5rem;"><i class="fas fa-sticky-note"></i> System Notes</span>
                        <div style="padding: 1rem; background: var(--bg-body); border-radius: var(--radius-md); font-size: 0.9rem; color: var(--text-main); border: 1px solid var(--border);">
                            <?php echo nl2br(htmlspecialchars($clean_notes ?: 'No additional notes provided.')); ?>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </main>

    <script>
        // --- Theme Toggle Logic ---
        const themeToggle = document.getElementById('themeToggle');
        const themeIcon = themeToggle.querySelector('i');
        const html = document.documentElement;

        function updateThemeIcon() {
            themeIcon.className = html.classList.contains('dark') ? 'fas fa-sun' : 'fas fa-moon';
        }

        themeToggle.addEventListener('click', () => {
            const isDark = html.classList.toggle('dark');
            html.classList.toggle('light');
            localStorage.setItem('theme', isDark ? 'dark' : 'light');
            updateThemeIcon();
        });

        // Initialize theme from storage
        if (localStorage.getItem('theme') === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            html.classList.add('dark');
            html.classList.remove('light');
        }
        updateThemeIcon();
    </script>
</body>
</html>