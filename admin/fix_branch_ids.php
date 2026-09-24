<?php
session_start();
require_once '../includes/config.php';
$pdo = getDbConnection();

// Generate CSRF token for security
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$action = $_POST['action'] ?? '';
$isAjax = !empty($_POST['ajax']);

// Fetch all branches
$branches = $pdo->query("SELECT id, name FROM branches ORDER BY name")->fetchAll();

// Handle fix submission (AJAX or Standard)
if ($action === 'fix' && !empty($_POST['updates'])) {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Invalid security token. Please refresh.']);
            exit;
        }
    } else {
        try {
            $updatedIds = [];
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("UPDATE disbursements SET branch_id = ? WHERE id = ?");
            foreach ($_POST['updates'] as $disbId => $branchId) {
                if (!empty($branchId)) {
                    $stmt->execute([$branchId, $disbId]);
                    $updatedIds[] = $disbId;
                }
            }
            $pdo->commit();
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'message' => "Successfully updated " . count($updatedIds) . " disbursement(s).", 'updated_ids' => $updatedIds]);
                exit;
            }
        } catch (Exception $e) {
            $pdo->rollBack();
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
                exit;
            }
        }
    }
}

// Fetch disbursements with bad/null branch_id
$badStmt = $pdo->query("
    SELECT d.id, d.branch_id, d.officer, d.date, d.client_name, d.principal
    FROM disbursements d
    LEFT JOIN branches b ON d.branch_id = b.id
    WHERE b.id IS NULL OR d.branch_id IS NULL
    ORDER BY d.date DESC
");
$badRows = $badStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fix Bad Branch IDs</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    
    <script>
        if (localStorage.getItem('theme') === 'dark' || (!localStorage.getItem('theme') && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            document.documentElement.setAttribute('data-theme', 'dark');
        }
    </script>

    <style>
        /* CSS Variables - Light Mode */
        :root {
            --primary: #2563eb; --primary-hover: #1d4ed8; --primary-ring: rgba(37,99,235,0.2);
            --bg: #f8fafc; --card-bg: #ffffff;
            --text-main: #0f172a; --text-muted: #64748b;
            --border: #e2e8f0; --border-hover: #cbd5e1;
            --success-bg: #ecfdf5; --success-text: #065f46; --success-border: #6ee7b7;
            --danger-bg: #fef2f2; --danger-text: #991b1b; --danger-border: #fca5a5;
            --warning-dot: #f59e0b;
            --row-selected: #eff6ff; --row-hover: #f8fafc;
            --code-bg: #f1f5f9; --th-bg: rgba(248, 250, 252, 0.9); --input-bg: #ffffff;
            --btn-sec-bg: #ffffff; --btn-sec-hover: #f1f5f9;
            --toast-bg: #ffffff; --toast-shadow: rgba(0,0,0,0.08);
            --pill-bg: #ffffff; --pill-shadow: rgba(0,0,0,0.12);
            --scroll-thumb: #cbd5e1; --scroll-track: transparent;
        }

        /* CSS Variables - Dark Mode */
        [data-theme="dark"] {
            --primary: #3b82f6; --primary-hover: #60a5fa; --primary-ring: rgba(59,130,246,0.25);
            --bg: #0B1120; --card-bg: #1e293b;
            --text-main: #f8fafc; --text-muted: #94a3b8;
            --border: #334155; --border-hover: #475569;
            --success-bg: rgba(16, 185, 129, 0.1); --success-text: #34d399; --success-border: rgba(16,185,129,0.3);
            --danger-bg: rgba(239, 68, 68, 0.1); --danger-text: #f87171; --danger-border: rgba(239,68,68,0.3);
            --warning-dot: #fbbf24;
            --row-selected: rgba(59, 130, 246, 0.1); --row-hover: #233043;
            --code-bg: #0f172a; --th-bg: rgba(30, 41, 59, 0.9); --input-bg: #0f172a;
            --btn-sec-bg: #1e293b; --btn-sec-hover: #334155;
            --toast-bg: #1e293b; --toast-shadow: rgba(0,0,0,0.5);
            --pill-bg: #1e293b; --pill-shadow: rgba(0,0,0,0.4);
            --scroll-thumb: #475569; --scroll-track: #0f172a;
            color-scheme: dark;
        }

        /* Global */
        body { font-family: 'Plus Jakarta Sans', sans-serif; background: var(--bg); color: var(--text-main); padding: 2rem 1rem; margin: 0; transition: background-color 0.3s, color 0.3s; -webkit-font-smoothing: antialiased; }
        .container { max-width: 1250px; margin: 0 auto; position: relative; }

        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: var(--scroll-track); border-radius: 4px; }
        ::-webkit-scrollbar-thumb { background: var(--scroll-thumb); border-radius: 4px; border: 2px solid transparent; background-clip: padding-box; }
        ::-webkit-scrollbar-thumb:hover { background-color: var(--text-muted); }

        /* Header */
        .top-nav { display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; }
        .top-bar { display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem; }
        h2 { font-size: 1.85rem; font-weight: 800; margin: 0 0 0.4rem 0; display: flex; align-items: center; gap: 0.75rem; letter-spacing: -0.02em; }
        p.subtitle { color: var(--text-muted); margin: 0; font-size: 0.95rem; line-height: 1.5; }

        .theme-toggle { background: transparent; border: 1px solid var(--border); color: var(--text-main); padding: 0.6rem; border-radius: 50%; cursor: pointer; transition: all 0.2s ease; }
        .theme-toggle:hover { background: var(--btn-sec-hover); transform: scale(1.05); }

        /* Search */
        .search-wrapper { position: relative; width: 100%; max-width: 320px; }
        .search-wrapper input { width: 100%; padding: 0.7rem 1rem 0.7rem 2.8rem; border: 1px solid var(--border); background: var(--input-bg); color: var(--text-main); border-radius: 10px; font-family: inherit; font-size: 0.95rem; font-weight: 500; transition: all 0.2s ease; }
        .search-wrapper input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 4px var(--primary-ring); }
        .search-wrapper svg { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: var(--text-muted); transition: color 0.2s; }
        .search-wrapper input:focus + svg { color: var(--primary); }

        /* Table */
        .card { background: var(--card-bg); border-radius: 16px; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.02); border: 1px solid var(--border); overflow: hidden; transition: all 0.3s ease; }
        .table-container { max-height: 65vh; overflow-y: auto; overflow-x: auto; position: relative; }
        
        table { width: 100%; border-collapse: separate; border-spacing: 0; min-width: 900px; }
        
        /* Sortable Headers */
        th { background: var(--th-bg); padding: 1.1rem 1.5rem; text-align: left; font-size: 0.75rem; text-transform: uppercase; color: var(--text-muted); font-weight: 800; letter-spacing: 0.05em; border-bottom: 1px solid var(--border); position: sticky; top: 0; z-index: 10; backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px); transition: background-color 0.3s; user-select: none; }
        th.sortable { cursor: pointer; transition: color 0.2s; }
        th.sortable:hover { color: var(--text-main); }
        th.sortable .sort-icon { display: inline-block; width: 12px; height: 12px; margin-left: 4px; opacity: 0.3; transition: opacity 0.2s; background: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M7 15l5 5 5-5M7 9l5-5 5 5'/%3E%3C/svg%3E") no-repeat center; background-size: contain; }
        th.sortable.asc .sort-icon { opacity: 1; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M5 15l7-7 7 7'/%3E%3C/svg%3E"); }
        th.sortable.desc .sort-icon { opacity: 1; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M19 9l-7 7-7-7'/%3E%3C/svg%3E"); }

        td { padding: 1rem 1.5rem; border-bottom: 1px solid var(--border); font-size: 0.95rem; font-weight: 500; vertical-align: middle; transition: background-color 0.2s ease, border-color 0.3s; }
        tbody tr { transition: all 0.2s ease; }
        tbody tr:hover td { background: var(--row-hover); }
        tbody tr.selected-row td { background: var(--row-selected); }
        tbody tr.fade-out { opacity: 0; transform: scale(0.98) translateY(-5px); }

        /* Form Elements */
        input[type="checkbox"] { width: 1.25rem; height: 1.25rem; cursor: pointer; border-radius: 6px; accent-color: var(--primary); }
        
        .assign-cell { display: flex; align-items: center; gap: 0.5rem; }
        select { padding: 0.55rem 0.75rem; border: 1px solid var(--border); border-radius: 8px; font-family: inherit; font-size: 0.9rem; font-weight: 500; background: var(--input-bg); color: var(--text-main); width: 100%; min-width: 170px; cursor: pointer; appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='none' stroke='%2364748b' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M4 6l4 4 4-4'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 0.75rem center; transition: all 0.2s; }
        select option { background: var(--input-bg); color: var(--text-main); }
        select:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 4px var(--primary-ring); }
        
        .select-modified { border-color: var(--primary) !important; background-color: var(--row-selected); }
        
        /* Reset button per row */
        .reset-btn { background: transparent; border: none; color: var(--text-muted); cursor: pointer; padding: 4px; border-radius: 6px; display: flex; opacity: 0; pointer-events: none; transition: all 0.2s; }
        .reset-btn:hover { color: var(--danger-text); background: var(--danger-bg); }
        .assign-cell.has-value .reset-btn { opacity: 1; pointer-events: auto; }

        /* Unsaved Dot Indicator */
        .unsaved-dot { width: 8px; height: 8px; background: var(--warning-dot); border-radius: 50%; opacity: 0; transition: opacity 0.2s; box-shadow: 0 0 6px var(--warning-dot); }
        .assign-cell.has-value .unsaved-dot { opacity: 1; }

        code { background: var(--code-bg); padding: 4px 8px; border-radius: 6px; font-size: 0.85rem; font-weight: 700; color: var(--text-muted); }

        /* Buttons */
        .btn { padding: 0.6rem 1.25rem; background: var(--primary); color: white; border: none; border-radius: 8px; font-weight: 600; font-size: 0.95rem; cursor: pointer; transition: all 0.2s ease; display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem; }
        .btn:hover:not(:disabled) { background: var(--primary-hover); transform: translateY(-1px); }
        .btn:disabled { opacity: 0.5; cursor: not-allowed; filter: grayscale(50%); }
        
        /* Smart Save Pulsing */
        @keyframes subtlePulse { 0% { box-shadow: 0 0 0 0 var(--primary-ring); } 70% { box-shadow: 0 0 0 8px rgba(37,99,235,0); } 100% { box-shadow: 0 0 0 0 rgba(37,99,235,0); } }
        .btn.ready-to-save { animation: subtlePulse 2s infinite; background: var(--primary); opacity: 1; filter: none; }

        .btn-secondary { background: var(--btn-sec-bg); color: var(--text-main); border: 1px solid var(--border); }
        .btn-secondary:hover:not(:disabled) { background: var(--btn-sec-hover); }
        .btn-large { padding: 0.85rem 2rem; font-size: 1rem; min-width: 220px; }

        .back { display: inline-flex; align-items: center; gap: 0.5rem; color: var(--text-muted); font-weight: 600; text-decoration: none; font-size: 0.95rem; transition: color 0.2s; }
        .back:hover { color: var(--primary); }
        
        .badge { background: var(--danger-bg); color: var(--danger-text); border: 1px solid var(--danger-border); padding: 0.25rem 0.75rem; border-radius: 99px; font-size: 0.8rem; font-weight: 700; display: inline-flex; align-items: center; gap: 4px; }
        .badge.zero { background: var(--success-bg); color: var(--success-text); border-color: var(--success-border); }
        
        /* Floating Pill */
        .bulk-pill { position: fixed; bottom: 2rem; left: 50%; transform: translateX(-50%) translateY(150%); opacity: 0; pointer-events: none; background: var(--pill-bg); border: 1px solid var(--border); box-shadow: 0 20px 25px -5px var(--pill-shadow), 0 8px 10px -6px var(--pill-shadow); border-radius: 99px; padding: 0.75rem 1rem 0.75rem 1.5rem; display: flex; align-items: center; gap: 1rem; z-index: 100; transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275); }
        .bulk-pill.show { transform: translateX(-50%) translateY(0); opacity: 1; pointer-events: auto; }
        .bulk-pill span.count { font-weight: 700; color: var(--primary); background: var(--primary-ring); padding: 2px 10px; border-radius: 99px; font-size: 0.85rem; }
        .bulk-pill .divider { width: 1px; height: 24px; background: var(--border); }
        
        .footer-actions { padding: 1.5rem; background: var(--bg); border-top: 1px solid var(--border); display: flex; justify-content: flex-end; align-items: center; gap: 1rem; }
        .unsaved-text { color: var(--warning-dot); font-weight: 600; font-size: 0.9rem; opacity: 0; transition: opacity 0.2s; display: flex; align-items: center; gap: 6px; }
        .unsaved-text.show { opacity: 1; }

        .spinner { display: none; width: 18px; height: 18px; border: 2.5px solid rgba(255,255,255,0.3); border-radius: 50%; border-top-color: #fff; animation: spin 0.8s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* States */
        .empty-state { padding: 4rem 2rem; text-align: center; background: var(--card-bg); border: 1px dashed var(--border); border-radius: 16px; display: <?php echo empty($badRows) ? 'flex' : 'none'; ?>; flex-direction: column; align-items: center; gap: 1rem; }
        .empty-icon { background: var(--success-bg); color: var(--success-text); padding: 1.25rem; border-radius: 50%; }
        .no-results { text-align: center; padding: 3rem 1rem; color: var(--text-muted); font-weight: 500; display: none; }

        .toast-container { position: fixed; top: 2rem; right: 2rem; z-index: 9999; display: flex; flex-direction: column; gap: 12px; }
        .toast { background: var(--toast-bg); color: var(--text-main); border-left: 4px solid var(--primary); box-shadow: 0 10px 25px -5px var(--toast-shadow); padding: 1rem 1.25rem; border-radius: 10px; font-weight: 600; display: flex; align-items: center; gap: 0.85rem; transform: translateX(120%); transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.1); opacity: 0; min-width: 250px; }
        .toast.show { transform: translateX(0); opacity: 1; }
        .toast.success { border-color: #10b981; } .toast.error { border-color: #ef4444; }
    </style>
</head>
<body>
    <div class="toast-container" id="toastContainer"></div>

    <div class="bulk-pill" id="bulkPill">
        <span class="count" id="selectedCount">0</span> <span style="font-size:0.9rem; font-weight:600; color:var(--text-main);">Selected</span>
        <div class="divider"></div>
        <select id="bulkBranchSelect" style="width: 200px; border-color: transparent; background-color: var(--btn-sec-hover); color: var(--text-main);">
            <option value="">Apply branch...</option>
            <?php foreach ($branches as $b): ?>
                <option value="<?php echo $b['id']; ?>"><?php echo htmlspecialchars($b['name']); ?></option>
            <?php endforeach; ?>
        </select>
        <button type="button" class="btn" onclick="applyBulk()">Apply</button>
        <button type="button" class="btn btn-secondary" style="padding: 0.6rem;" onclick="clearSelection()" title="Clear">
            <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
        </button>
    </div>

    <div class="container">
        <div class="top-nav">
            <a href="branch_disbursement.php" class="back">
                <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path></svg>
                Back to Report
            </a>
            <button class="theme-toggle" id="themeToggle" title="Toggle Theme">
                <svg class="sun-icon" style="display:none;" width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"></path></svg>
                <svg class="moon-icon" style="display:block;" width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"></path></svg>
            </button>
        </div>

        <div class="top-bar">
            <div>
                <h2>Fix Bad Branch IDs 
                    <span class="badge <?php echo empty($badRows) ? 'zero' : ''; ?>" id="rowCountBadge">
                        <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                        <span class="count-val"><?php echo count($badRows); ?></span>
                    </span>
                </h2>
                <p class="subtitle">Assign the correct branch to records missing valid location data.</p>
            </div>
            
            <div class="search-wrapper" id="searchWrapper" style="display: <?php echo empty($badRows) ? 'none' : 'block'; ?>;">
                <input type="text" id="searchInput" placeholder="Search records...">
                <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
            </div>
        </div>

        <div class="empty-state" id="emptyState">
            <div class="empty-icon">
                <svg width="40" height="40" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"></path></svg>
            </div>
            <div style="font-size: 1.25rem; font-weight:700; color: var(--text-main);">All caught up!</div>
            <div style="color: var(--text-muted); font-weight: 500;">No bad branch IDs found in the database.</div>
            <a href="branch_disbursement.php" class="btn" style="margin-top: 1rem;">Return to Dashboard</a>
        </div>

        <form method="POST" id="fixForm" style="display: <?php echo empty($badRows) ? 'none' : 'block'; ?>;">
            <input type="hidden" name="action" value="fix">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            
            <div class="card">
                <div class="table-container">
                    <table id="dataTable">
                        <thead>
                            <tr>
                                <th style="width: 40px; padding-right:0;"><input type="checkbox" id="selectAll" title="Select All Visible"></th>
                                <th class="sortable" data-sort="id" data-type="num">Disb ID <span class="sort-icon"></span></th>
                                <th class="sortable" data-sort="date" data-type="date">Date <span class="sort-icon"></span></th>
                                <th class="sortable" data-sort="client" data-type="str">Client Name <span class="sort-icon"></span></th>
                                <th>Officer</th>
                                <th class="sortable" data-sort="amount" data-type="num">Amount <span class="sort-icon"></span></th>
                                <th>Assign Branch</th>
                            </tr>
                        </thead>
                        <tbody id="tableBody">
                            <?php foreach ($badRows as $row): 
                                // Clean Data formatting
                                $cleanDate = !empty($row['date']) ? date('d M Y', strtotime($row['date'])) : 'N/A';
                                $rawDate = !empty($row['date']) ? strtotime($row['date']) : 0;
                            ?>
                                <tr class="data-row" data-id="<?php echo $row['id']; ?>">
                                    <td style="padding-right:0;"><input type="checkbox" class="row-checkbox" value="<?php echo $row['id']; ?>" tabindex="-1"></td>
                                    <td data-val="<?php echo $row['id']; ?>"><strong>#<?php echo htmlspecialchars($row['id']); ?></strong></td>
                                    <td data-val="<?php echo $rawDate; ?>" style="color: var(--text-muted);"><?php echo $cleanDate; ?></td>
                                    <td data-val="<?php echo htmlspecialchars($row['client_name'] ?? ''); ?>"><?php echo htmlspecialchars($row['client_name'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($row['officer'] ?? 'N/A'); ?></td>
                                    <td data-val="<?php echo $row['principal'] ?? 0; ?>" style="font-weight: 700;">₦<?php echo number_format($row['principal'] ?? 0); ?></td>
                                    <td onclick="event.stopPropagation();">
                                        <div class="assign-cell" id="cell-<?php echo $row['id']; ?>">
                                            <div class="unsaved-dot" title="Unsaved changes"></div>
                                            <select name="updates[<?php echo $row['id']; ?>]" class="branch-dropdown" id="select-<?php echo $row['id']; ?>">
                                                <option value="">— Select —</option>
                                                <?php foreach ($branches as $b): ?>
                                                    <option value="<?php echo $b['id']; ?>"><?php echo htmlspecialchars($b['name']); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="button" class="reset-btn" onclick="resetRow(<?php echo $row['id']; ?>)" title="Undo selection">
                                                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <tr id="noResultsRow" class="no-results-tr" style="display: none;">
                                <td colspan="7">
                                    <div class="no-results">
                                        <svg width="48" height="48" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                        <div>No matching records found for your search.</div>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="footer-actions">
                    <div class="unsaved-text" id="unsavedText">
                        <div class="unsaved-dot" style="opacity:1; position:static;"></div> <span id="unsavedCount">0</span> rows staged
                    </div>
                    <button type="submit" class="btn btn-large" id="submitBtn" disabled>
                        <span id="btnIcon"><svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3 3m0 0l-3-3m3 3V4"></path></svg></span>
                        <div class="spinner" id="btnSpinner"></div>
                        <span id="btnText">Save All Changes</span>
                    </button>
                </div>
            </div>
        </form>
    </div>

    <script>
        document.addEventListener("DOMContentLoaded", () => {
            // Theme Toggle
            const themeBtn = document.getElementById('themeToggle');
            const sun = document.querySelector('.sun-icon'), moon = document.querySelector('.moon-icon');
            const updateIcons = () => { const isDark = document.documentElement.getAttribute('data-theme') === 'dark'; sun.style.display = isDark?'block':'none'; moon.style.display = isDark?'none':'block'; };
            updateIcons();
            themeBtn.addEventListener('click', () => {
                const target = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
                document.documentElement.setAttribute('data-theme', target); localStorage.setItem('theme', target); updateIcons();
            });

            // State & Elements
            const selectAll = document.getElementById("selectAll");
            const bulkPill = document.getElementById("bulkPill");
            const searchInput = document.getElementById("searchInput");
            const tableBody = document.getElementById("tableBody");
            const submitBtn = document.getElementById("submitBtn");
            const unsavedText = document.getElementById("unsavedText");
            const unsavedCount = document.getElementById("unsavedCount");

            // Toast Utility
            const showToast = (msg, type = 'success') => {
                const c = document.getElementById("toastContainer");
                const t = document.createElement("div"); t.className = `toast ${type}`;
                t.innerHTML = (type === 'success' ? `<svg width="22" height="22" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"></path></svg>` : `<svg width="22" height="22" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>`) + `<span>${msg}</span>`;
                c.appendChild(t);
                requestAnimationFrame(() => requestAnimationFrame(() => t.classList.add('show')));
                setTimeout(() => { t.classList.remove('show'); setTimeout(() => t.remove(), 400); }, 3500);
            };

            // Smart Save Checker
            const checkUnsaved = () => {
                const staged = document.querySelectorAll(".branch-dropdown");
                let count = 0;
                staged.forEach(sel => {
                    const cell = sel.closest('.assign-cell');
                    if (sel.value !== "") { count++; cell.classList.add('has-value'); sel.classList.add('select-modified'); }
                    else { cell.classList.remove('has-value'); sel.classList.remove('select-modified'); }
                });

                unsavedCount.textContent = count;
                if (count > 0) {
                    submitBtn.disabled = false; submitBtn.classList.add('ready-to-save'); unsavedText.classList.add('show');
                } else {
                    submitBtn.disabled = true; submitBtn.classList.remove('ready-to-save'); unsavedText.classList.remove('show');
                }
            };

            // Table UI Updater
            const updateUI = () => {
                const visible = Array.from(document.querySelectorAll(".data-row")).filter(r => r.style.display !== 'none');
                const checked = document.querySelectorAll(".row-checkbox:checked");
                const visibleChecked = Array.from(checked).filter(cb => cb.closest('.data-row').style.display !== 'none');
                
                document.getElementById("selectedCount").textContent = visibleChecked.length;
                bulkPill.classList.toggle('show', visibleChecked.length > 0);
                if(selectAll) selectAll.checked = (visibleChecked.length === visible.length) && visible.length > 0;

                document.querySelectorAll(".data-row").forEach(r => {
                    const cb = r.querySelector('.row-checkbox');
                    r.classList.toggle('selected-row', cb && cb.checked);
                });
            };

            // Listeners
            tableBody.addEventListener("change", (e) => {
                if(e.target.classList.contains("row-checkbox")) updateUI();
                if(e.target.classList.contains("branch-dropdown")) checkUnsaved();
            });

            tableBody.addEventListener("click", (e) => {
                const r = e.target.closest('.data-row');
                if(!r) return;
                // Exclude interactive elements from row click
                if(!['SELECT','OPTION','INPUT','BUTTON','SVG','PATH'].includes(e.target.tagName.toUpperCase())) {
                    const cb = r.querySelector(".row-checkbox");
                    if(cb) { cb.checked = !cb.checked; updateUI(); }
                }
            });

            if (selectAll) {
                selectAll.addEventListener("change", (e) => {
                    document.querySelectorAll(".data-row").forEach(r => {
                        if(r.style.display !== 'none') {
                            const cb = r.querySelector('.row-checkbox');
                            if (cb) cb.checked = e.target.checked;
                        }
                    });
                    updateUI();
                });
            }

            // Interactive Table Sorting
            document.querySelectorAll('th.sortable').forEach(th => {
                th.addEventListener('click', () => {
                    const table = th.closest('table');
                    const tbody = table.querySelector('tbody');
                    const rows = Array.from(tbody.querySelectorAll('tr.data-row'));
                    const index = Array.from(th.parentNode.children).indexOf(th);
                    const type = th.dataset.type;
                    let asc = !th.classList.contains('asc');
                    
                    // Reset others
                    table.querySelectorAll('th').forEach(t => t.classList.remove('asc', 'desc'));
                    th.classList.add(asc ? 'asc' : 'desc');

                    rows.sort((a, b) => {
                        let valA = a.children[index].dataset.val || a.children[index].textContent;
                        let valB = b.children[index].dataset.val || b.children[index].textContent;
                        if (type === 'num' || type === 'date') {
                            return asc ? (parseFloat(valA) - parseFloat(valB)) : (parseFloat(valB) - parseFloat(valA));
                        }
                        return asc ? valA.localeCompare(valB) : valB.localeCompare(valA);
                    });

                    // Re-append sorted rows (noResultsRow stays at bottom natively as it's not in the array)
                    rows.forEach(r => tbody.insertBefore(r, document.getElementById('noResultsRow')));
                });
            });

            // Search Filter
            if(searchInput) {
                searchInput.addEventListener("input", (e) => {
                    const term = e.target.value.toLowerCase();
                    let vis = 0;
                    document.querySelectorAll(".data-row").forEach(r => {
                        if(r.textContent.toLowerCase().includes(term)) { r.style.display = ""; vis++; } 
                        else { r.style.display = "none"; const cb = r.querySelector('.row-checkbox'); if(cb) cb.checked = false; }
                    });
                    document.getElementById("noResultsRow").style.display = vis === 0 ? "table-row" : "none";
                    updateUI();
                });
            }

            // AJAX Form Submit
            document.getElementById("fixForm").addEventListener("submit", function(e) {
                e.preventDefault();
                submitBtn.disabled = true; submitBtn.classList.remove('ready-to-save');
                document.getElementById("btnIcon").style.display = "none";
                document.getElementById("btnSpinner").style.display = "inline-block";
                document.getElementById("btnText").textContent = "Saving to Database...";
                bulkPill.classList.remove('show');

                const fd = new FormData(this); fd.append('ajax', '1');

                fetch(window.location.href, { method: 'POST', body: fd })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        showToast(data.message, 'success');
                        if(data.updated_ids) {
                            data.updated_ids.forEach(id => {
                                const row = document.querySelector(`tr[data-id="${id}"]`);
                                if(row) { row.classList.add('fade-out'); setTimeout(() => row.remove(), 300); }
                            });
                        }
                        setTimeout(() => {
                            const rem = document.querySelectorAll(".data-row").length;
                            document.querySelector('.count-val').textContent = rem;
                            clearSelection(); checkUnsaved();

                            if (rem === 0) {
                                this.style.opacity = '0';
                                setTimeout(() => {
                                    this.style.display = 'none'; document.getElementById('searchWrapper').style.display = 'none';
                                    document.getElementById('rowCountBadge').classList.add('zero');
                                    document.getElementById('emptyState').style.display = 'flex';
                                }, 300);
                            }
                        }, 350);
                    } else { showToast(data.message || 'Error occurred', 'error'); checkUnsaved(); }
                })
                .catch(() => { showToast('Network error.', 'error'); checkUnsaved(); })
                .finally(() => {
                    document.getElementById("btnIcon").style.display = "inline-block";
                    document.getElementById("btnSpinner").style.display = "none";
                    document.getElementById("btnText").textContent = "Save All Changes";
                });
            });

            // Global Functions
            window.applyBulk = () => {
                const val = document.getElementById("bulkBranchSelect").value;
                if (!val) { showToast("Select a branch from the floating bar first.", "error"); return; }
                document.querySelectorAll(".row-checkbox:checked").forEach(cb => {
                    const sel = document.getElementById(`select-${cb.value}`);
                    if (sel) { sel.value = val; sel.classList.add("select-highlighted"); setTimeout(() => sel.classList.remove("select-highlighted"), 2000); }
                });
                clearSelection(); checkUnsaved();
            };

            window.clearSelection = () => {
                if(selectAll) selectAll.checked = false;
                document.querySelectorAll(".row-checkbox").forEach(cb => cb.checked = false);
                document.getElementById("bulkBranchSelect").value = "";
                updateUI();
            };

            window.resetRow = (id) => {
                const sel = document.getElementById(`select-${id}`);
                if(sel) { sel.value = ""; checkUnsaved(); }
            };
        });
    </script>
</body>
</html>