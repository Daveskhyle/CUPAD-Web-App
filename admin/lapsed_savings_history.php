<?php
// admin/lapsed_savings_history.php
date_default_timezone_set('Africa/Lagos');
session_start();

$base_path = '../';

// --- DATABASE CONNECTION ---
require_once $base_path . 'includes/config.php';
$pdo = getDbConnection();

// Access Control - ADMIN ONLY
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: ../index.php');
    exit();
}

// Fetch user data for the unified dashboard header
$current_username = $_SESSION['username'] ?? '';
$full_name = $_SESSION['full_name'] ?? 'Admin';
$role = $_SESSION['user_role'] ?? 'admin';

$stmt = $pdo->prepare("SELECT full_name, profile_pic FROM users WHERE username = ?");
$stmt->execute([$current_username]);
$u_row = $stmt->fetch(PDO::FETCH_ASSOC);
$profile_pic = $u_row['profile_pic'] ?? 'default_avatar.png';
$full_name = !empty($u_row['full_name']) ? $u_row['full_name'] : $full_name;
$profile_pic_path = $base_path . 'uploads/' . $profile_pic;
$has_profile_pic = file_exists($profile_pic_path) && $profile_pic !== 'default_avatar.png';

// Fetch History Data & Calculate Advanced Stats
$history_records = [];
$grouped_records = [];
$total_amount = 0;
$total_records = 0;
$this_month_amount = 0;
$max_deduction = 0;
$unique_clients = [];

try {
    $tableExists = $pdo->query("SHOW TABLES LIKE 'lapsed_savings_history'")->rowCount() > 0;
    
    if ($tableExists) {
        $sql = "
            SELECT 
                lsh.*,
                c.name AS live_client_name,
                c.officer_username AS live_co,
                b.name AS live_branch,
                a.name AS live_area,
                z.name AS live_zo
            FROM lapsed_savings_history lsh
            LEFT JOIN clients c ON lsh.client_id = c.id
            LEFT JOIN branches b ON c.branch_id = b.id
            LEFT JOIN areas a ON b.area_id = a.id
            LEFT JOIN zones z ON b.zone_id = z.id
            ORDER BY lsh.date DESC
        ";
        
        $stmt = $pdo->query($sql);
        $history_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $total_records = count($history_records);
        $current_month_str = date('Y-m');

        foreach($history_records as $rec) {
            $amt = (float)$rec['amount'];
            $total_amount += $amt;
            
            if ($amt > $max_deduction) $max_deduction = $amt;
            if (!in_array($rec['client_id'], $unique_clients)) $unique_clients[] = $rec['client_id'];
            if (strpos($rec['date'], $current_month_str) === 0) $this_month_amount += $amt;

            // Group by Month and Year for UI Timeline
            $month_year = date('F Y', strtotime($rec['date']));
            $grouped_records[$month_year][] = $rec;
        }
    }
} catch (Exception $e) { }

function format_naira(float $amount): string { return '₦' . number_format($amount, 0); }
?>
<!DOCTYPE html>
<html lang="en" class="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#3b82f6">
    <title>Lapsed Savings History | CUPAD Admin</title>
    
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <style>
        /* --- CSS VARIABLES & RESET --- */
        :root {
            --primary: #3b82f6; --primary-hover: #2563eb; 
            --success: #22c55e; --warning: #f59e0b; --danger: #ef4444; --danger-hover: #dc2626;
            --bg-body: #f4f6f8; --bg-card: #ffffff; --bg-alt: #f8fafc; --bg-hover: #f1f5f9;
            --text-main: #1f2937; --text-muted: #6b7280; --text-light: #9ca3af;
            --border: #e5e7eb;
            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            --radius-md: 0.5rem; --radius-lg: 1rem; --radius-xl: 1.25rem;
            --nav-height: 70px;
        }
        
        html.dark {
            --bg-body: #0f172a; --bg-card: #1e293b; --bg-alt: #0f172a; --bg-hover: #334155;
            --text-main: #f8fafc; --text-muted: #94a3b8; --text-light: #64748b;
            --border: #334155;
        }
        
        * { box-sizing: border-box; margin: 0; padding: 0; outline: none; font-family: 'Plus Jakarta Sans', sans-serif; }
        
        body { background-color: var(--bg-body); color: var(--text-main); padding-top: var(--nav-height); padding-bottom: 90px; -webkit-tap-highlight-color: transparent; transition: background-color 0.3s, color 0.3s; }
        a { text-decoration: none; color: inherit; }
        button { cursor: pointer; border: none; background: none; }
        ::-webkit-scrollbar { width: 5px; height: 5px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
        html.dark ::-webkit-scrollbar-thumb { background: #475569; }

        /* --- NAVBAR --- */
        .main-header { position: fixed; top: 0; left: 0; right: 0; height: var(--nav-height); background: var(--bg-card); opacity: 0.95; backdrop-filter: blur(10px); border-bottom: 1px solid var(--border); z-index: 50; transition: background 0.3s; }
        .navbar { max-width: 1400px; margin: 0 auto; padding: 0 1.5rem; display: flex; align-items: center; justify-content: space-between; height: 100%; }
        .logo { display: flex; align-items: center; gap: 0.75rem; font-weight: 800; font-size: 1.35rem; color: var(--primary); }
        .logo img { height: 38px; }
        .nav-right { display: flex; align-items: center; gap: 1rem; }
        .icon-btn { width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; color: var(--text-muted); transition: 0.2s; }
        .icon-btn:hover { background: var(--bg-body); color: var(--text-main); }
        
        .user-pill { display: flex; align-items: center; gap: 0.75rem; padding: 4px 8px 4px 4px; border: 1px solid var(--border); border-radius: 99px; background: var(--bg-card); cursor: pointer; transition: 0.2s; }
        .user-pill:hover { border-color: var(--primary); }
        .user-avatar { width: 34px; height: 34px; border-radius: 50%; object-fit: cover; }
        .user-info { display: flex; flex-direction: column; line-height: 1.1; padding-right: 0.5rem; }
        .user-name { font-weight: 600; font-size: 0.85rem; color: var(--text-main); }
        .user-role { font-size: 0.7rem; color: var(--text-muted); text-transform: uppercase; }
        
        /* --- MAIN CONTENT & HEADER --- */
        .main-content { max-width: 1400px; margin: 0 auto; padding: 1.5rem 1.5rem; }
        .page-header { margin-bottom: 1.25rem; display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap; }
        .page-title { font-size: 1.4rem; font-weight: 800; color: var(--text-main); letter-spacing: -0.02em; }
        .page-subtitle { font-size: 0.8rem; color: var(--text-muted); margin-top: 0.2rem; }

        /* --- CAROUSEL STATS --- */
        .carousel-wrapper { position: relative; margin-bottom: 1.5rem; display: flex; align-items: center; }
        .carousel-track { display: flex; gap: 1.25rem; overflow-x: auto; scroll-snap-type: x mandatory; scroll-behavior: smooth; padding-bottom: 1rem; -ms-overflow-style: none; scrollbar-width: none; cursor: grab; }
        .carousel-track.active { cursor: grabbing; scroll-snap-type: none; }
        .carousel-track::-webkit-scrollbar { display: none; }
        
        .carousel-btn { position: absolute; top: 50%; transform: translateY(-70%); width: 40px; height: 40px; background: var(--bg-card); border: 1px solid var(--border); border-radius: 50%; display: flex; align-items: center; justify-content: center; box-shadow: var(--shadow-md); z-index: 10; color: var(--text-main); transition: 0.2s; opacity: 0; pointer-events: none; }
        .carousel-wrapper:hover .carousel-btn { opacity: 1; pointer-events: auto; }
        .carousel-btn:hover { background: var(--primary); color: white; border-color: var(--primary); }
        .carousel-btn.left { left: -15px; } .carousel-btn.right { right: -15px; }
        @media(max-width: 768px) { .carousel-btn { display: none !important; } }

        .stat-card { min-width: 260px; scroll-snap-align: start; flex: 0 0 auto; border-radius: var(--radius-xl); padding: 1.5rem; position: relative; overflow: hidden; box-shadow: var(--shadow-md); color: white; transition: transform 0.2s; user-select: none; }
        .stat-card:hover { transform: translateY(-3px); box-shadow: var(--shadow-lg); }
        .card-red { background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); }
        .card-blue { background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); }
        .card-purple { background: linear-gradient(135deg, #8b5cf6 0%, #6d28d9 100%); }
        .card-emerald { background: linear-gradient(135deg, #10b981 0%, #059669 100%); }
        .card-orange { background: linear-gradient(135deg, #f97316 0%, #ea580c 100%); }
        
        .stat-icon-bg { position: absolute; right: -10px; bottom: -20px; font-size: 6rem; color: white; opacity: 0.1; transform: rotate(-10deg); pointer-events: none; }
        .stat-label { font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; opacity: 0.9; margin-bottom: 0.25rem; }
        .stat-value { font-size: 1.7rem; font-weight: 800; letter-spacing: -0.02em; position: relative; z-index: 10; }

        /* --- CONTROLS --- */
        .controls-wrapper { display: flex; flex-direction: column; gap: 0.75rem; margin-bottom: 1.5rem; }
        @media(min-width: 768px) { .controls-wrapper { flex-direction: row; align-items: center; } }
        .search-box { background: var(--bg-card); border-radius: var(--radius-lg); border: 1px solid var(--border); flex-grow: 1; display: flex; align-items: center; position: relative; transition: 0.2s; padding: 0.25rem; }
        .search-box:focus-within { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15); }
        .search-icon { position: absolute; left: 1.2rem; color: var(--text-light); }
        .search-input { width: 100%; padding: 0.75rem 2.5rem 0.75rem 3rem; border: none; background: transparent; color: var(--text-main); font-size: 0.95rem; }
        .search-clear { position: absolute; right: 1.2rem; color: var(--text-muted); cursor: pointer; display: none; }
        .search-clear:hover { color: var(--danger); }
        
        .btn-export-top { display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.75rem 1.25rem; background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius-lg); font-size: 0.9rem; font-weight: 700; color: var(--text-main); cursor: pointer; transition: 0.2s; box-shadow: var(--shadow-sm); }
        .btn-export-top:hover { background: var(--bg-body); color: var(--primary); border-color: var(--primary); box-shadow: var(--shadow-md); }

        /* --- LEDGER LIST --- */
        .timeline-month { position: sticky; top: var(--nav-height); background: rgba(244, 246, 248, 0.95); backdrop-filter: blur(8px); padding: 1rem 0 0.5rem; font-size: 0.8rem; font-weight: 800; color: var(--text-muted); text-transform: uppercase; z-index: 10; margin-bottom: 0.5rem; display: flex; align-items: center; justify-content: space-between; border-bottom: 2px solid var(--border); }
        .dark .timeline-month { background: rgba(15, 23, 42, 0.95); }
        .month-badge { font-size: 0.65rem; background: var(--bg-card); padding: 0.2rem 0.5rem; border-radius: 99px; border: 1px solid var(--border); }

        .month-group { margin-bottom: 1.5rem; }
        .ledger-container { display: flex; flex-direction: column; gap: 0.75rem; }
        
        .ledger-item { background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius-lg); padding: 1.25rem; display: flex; flex-direction: column; gap: 1rem; transition: 0.2s; box-shadow: var(--shadow-sm); opacity: 0; transform: translateY(15px); animation: fadeInUp 0.4s ease forwards; }
        .ledger-item:hover { border-color: rgba(239, 68, 68, 0.4); transform: translateY(-2px); box-shadow: var(--shadow-md); }
        @media(min-width: 900px) { .ledger-item { flex-direction: row; align-items: center; justify-content: space-between; } }

        .li-left { display: flex; gap: 1rem; align-items: center; flex: 1; min-width: 250px; }
        .li-icon { width: 44px; height: 44px; border-radius: var(--radius-md); background: rgba(239, 68, 68, 0.1); color: var(--danger); display: flex; align-items: center; justify-content: center; font-size: 1.2rem; flex-shrink: 0; }
        .dark .li-icon { background: rgba(239, 68, 68, 0.2); }
        
        .li-client { overflow: hidden; }
        .li-name { font-size: 1rem; font-weight: 700; color: var(--text-main); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-bottom: 0.2rem; }
        .li-date { font-size: 0.75rem; font-weight: 500; color: var(--text-muted); display: flex; align-items: center; gap: 0.5rem; }
        
        .li-middle { flex: 1.5; display: flex; flex-wrap: wrap; gap: 0.4rem; align-items: center; min-width: 200px; }
        .li-badge { display: inline-flex; align-items: center; gap: 0.3rem; padding: 0.25rem 0.5rem; border-radius: 0.35rem; font-size: 0.65rem; font-weight: 700; text-transform: uppercase; border: 1px solid var(--border); background: var(--bg-body); color: var(--text-muted); }
        .badge-co { background: rgba(59, 130, 246, 0.08); color: var(--primary); border-color: rgba(59, 130, 246, 0.2); }
        
        .li-right { display: flex; flex-direction: column; align-items: flex-start; gap: 0.25rem; flex-shrink: 0; min-width: 150px; }
        @media(min-width: 900px) { .li-right { align-items: flex-end; } }
        
        .li-amount { font-size: 1.1rem; font-weight: 800; color: var(--danger); }
        .li-processed { font-size: 0.7rem; color: var(--text-muted); font-weight: 600; display: flex; align-items: center; gap: 0.3rem; }
        .li-id { font-family: monospace; font-size: 0.7rem; color: var(--text-light); background: var(--bg-body); padding: 0.2rem 0.4rem; border-radius: 0.25rem; cursor: pointer; border: 1px solid transparent; transition: 0.2s; }
        .li-id:hover { border-color: var(--border); color: var(--text-main); }

        .empty-state { text-align: center; padding: 4rem 1rem; color: var(--text-muted); background: var(--bg-card); border-radius: var(--radius-lg); border: 1px dashed var(--border); }
        .empty-state i { font-size: 3rem; margin-bottom: 1rem; opacity: 0.3; }

        @keyframes fadeInUp { to { opacity: 1; transform: translateY(0); } }

        /* Toast Notifications */
        .toast { position: fixed; top: 20px; left: 50%; background: var(--bg-card); padding: 0.8rem 1.2rem; border-radius: var(--radius-lg); border-left: 4px solid var(--primary); box-shadow: var(--shadow-lg); z-index: 9999; display: flex; align-items: center; gap: 0.75rem; opacity: 0; transform: translateY(-20px) translateX(-50%); transition: opacity 0.3s, transform 0.3s; animation: toast-in 0.4s forwards cubic-bezier(0.21, 1.02, 0.73, 1); }
        @keyframes toast-in { to { opacity: 1; transform: translateY(0) translateX(-50%); } }

        /* Print Styles */
        @media print {
            body { background: #fff !important; color: #000 !important; padding-top: 0 !important; }
            .main-header, .controls-wrapper, .carousel-wrapper { display: none !important; }
            .main-content { padding: 0 !important; max-width: 100% !important; }
            .page-title { font-size: 18pt !important; text-align: center; margin-bottom: 5px; }
            .page-subtitle { text-align: center; margin-bottom: 20px; }
            
            .timeline-month { position: static !important; background: none !important; color: #000 !important; border-bottom: 2px solid #000 !important; padding: 10px 0 !important; margin-top: 20px !important; }
            .ledger-container { display: block !important; gap: 0 !important; }
            .ledger-item { border: none !important; border-bottom: 1px solid #ddd !important; border-radius: 0 !important; box-shadow: none !important; padding: 10px 0 !important; flex-direction: row !important; align-items: center !important; justify-content: space-between !important; opacity: 1 !important; transform: none !important; animation: none !important; break-inside: avoid; }
            .li-icon { display: none !important; }
            .li-badge { border: none !important; background: transparent !important; color: #555 !important; padding: 0 !important; font-size: 9pt !important; }
            .li-badge i { display: none !important; }
            .li-badge::after { content: ", "; }
            .li-badge:last-child::after { content: ""; }
            .li-amount { color: #000 !important; font-size: 12pt !important; }
        }
    </style>
</head>
<body>

    <header class="main-header">
        <div class="navbar">
            <a href="dashboard.php" class="logo">
                <img src="<?php echo htmlspecialchars($base_path . 'uploads/CUPAD LOGO.png'); ?>" alt="Logo">
                <span>CUPAD</span>
            </a>
            <div class="nav-right">
                <a href="saving_withdrawal.php" class="icon-btn" title="Back to Savings Tools"><i class="fas fa-arrow-left"></i></a>
                <button class="icon-btn" id="themeToggle"><i class="fas fa-moon"></i></button>
            </div>
        </div>
    </header>

    <main class="main-content">
        <div class="page-header">
            <div>
                <h1 class="page-title">Lapsed Savings Ledger</h1>
                <p class="page-subtitle">Immutable record of inactive funds securely transferred to the Company Fund.</p>
            </div>
        </div>

        <!-- Draggable Stat Carousel -->
        <div class="carousel-wrapper">
            <button class="carousel-btn left" id="btnPrev"><i class="fas fa-chevron-left"></i></button>
            
            <div class="carousel-track" id="statCarousel">
                <div class="stat-card card-red">
                    <i class="fas fa-vault stat-icon-bg"></i>
                    <div class="stat-label">Total Lapsed Funds</div>
                    <div class="stat-value"><?php echo format_naira($total_amount); ?></div>
                </div>
                <div class="stat-card card-blue">
                    <i class="fas fa-calendar-check stat-icon-bg"></i>
                    <div class="stat-label">This Month (<?php echo date('M'); ?>)</div>
                    <div class="stat-value"><?php echo format_naira($this_month_amount); ?></div>
                </div>
                <div class="stat-card card-purple">
                    <i class="fas fa-arrow-trend-up stat-icon-bg"></i>
                    <div class="stat-label">Largest Deduction</div>
                    <div class="stat-value"><?php echo format_naira($max_deduction); ?></div>
                </div>
                <div class="stat-card card-emerald">
                    <i class="fas fa-users stat-icon-bg"></i>
                    <div class="stat-label">Affected Clients</div>
                    <div class="stat-value"><?php echo number_format(count($unique_clients)); ?></div>
                </div>
                <div class="stat-card card-orange">
                    <i class="fas fa-file-invoice stat-icon-bg"></i>
                    <div class="stat-label">Total Transactions</div>
                    <div class="stat-value"><?php echo number_format($total_records); ?></div>
                </div>
            </div>

            <button class="carousel-btn right" id="btnNext"><i class="fas fa-chevron-right"></i></button>
        </div>

        <div class="controls-wrapper">
            <div class="search-box">
                <i class="fas fa-search search-icon"></i>
                <input type="text" id="searchInput" class="search-input" placeholder="Search client name, branch, zone, or CO...">
                <i class="fas fa-times search-clear" id="searchClear" title="Clear"></i>
            </div>
            <button class="btn-export-top" onclick="window.print()">
                <i class="fas fa-print"></i> Print Ledger
            </button>
        </div>

        <div id="ledgerWrapper">
            <?php if (empty($grouped_records)): ?>
                <div class="empty-state">
                    <i class="fas fa-folder-open"></i>
                    <h3>No Records Found</h3>
                    <p style="font-size:0.9rem; margin-top:0.5rem;">No lapsed savings have been processed yet.</p>
                </div>
            <?php else: ?>
                <?php 
                $animDelay = 0;
                foreach($grouped_records as $month => $records): ?>
                    <div class="month-group">
                        <div class="timeline-month">
                            <?php echo $month; ?> 
                            <span class="month-badge"><?php echo count($records); ?> records</span>
                        </div>
                        <div class="ledger-container">
                            <?php foreach($records as $row): 
                                $client_name = !empty($row['live_client_name']) ? $row['live_client_name'] : (!empty($row['client_name']) ? $row['client_name'] : 'Unknown Client');
                                $branch = !empty($row['live_branch']) ? $row['live_branch'] : (!empty($row['branch']) ? $row['branch'] : 'Unassigned Branch');
                                $zone = !empty($row['live_zo']) ? $row['live_zo'] : (!empty($row['zo']) ? $row['zo'] : 'Unassigned Zone');
                                $area = !empty($row['live_area']) ? $row['live_area'] : (!empty($row['area']) ? $row['area'] : 'Unassigned Area');
                                $co = !empty($row['live_co']) ? $row['live_co'] : (!empty($row['co']) ? $row['co'] : 'Unassigned Officer');
                            ?>
                                <div class="ledger-item" style="animation-delay: <?php echo $animDelay; ?>s;">
                                    <div class="li-left">
                                        <div class="li-icon"><i class="fas fa-minus"></i></div>
                                        <div class="li-client">
                                            <div class="li-name" title="<?php echo htmlspecialchars($client_name); ?>">
                                                <?php echo htmlspecialchars($client_name); ?>
                                            </div>
                                            <div class="li-date">
                                                <span><i class="fas fa-id-badge" style="opacity:0.6;"></i> <?php echo htmlspecialchars($row['client_id']); ?></span>
                                                <span>&bull;</span>
                                                <span><?php echo date('M d, Y h:i A', strtotime($row['date'])); ?></span>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="li-middle">
                                        <span class="li-badge" title="Branch"><i class="fas fa-building"></i> <?php echo htmlspecialchars($branch); ?></span>
                                        <span class="li-badge" title="Zone"><i class="fas fa-map"></i> <?php echo htmlspecialchars($zone); ?></span>
                                        <span class="li-badge" title="Area"><i class="fas fa-location-dot"></i> <?php echo htmlspecialchars($area); ?></span>
                                        <span class="li-badge badge-co" title="Credit Officer"><i class="fas fa-user-tie"></i> <?php echo htmlspecialchars($co); ?></span>
                                    </div>

                                    <div class="li-right">
                                        <div class="li-amount">-<?php echo format_naira($row['amount']); ?></div>
                                        <div class="li-processed"><i class="fas fa-shield-halved" style="color:var(--primary);"></i> <?php echo htmlspecialchars($row['processed_by']); ?></div>
                                        <div class="li-id copy-id" data-id="<?php echo htmlspecialchars($row['transaction_id']); ?>" title="Click to copy">
                                            <?php echo htmlspecialchars($row['transaction_id']); ?> <i class="fas fa-copy" style="opacity:0.5; margin-left:2px;"></i>
                                        </div>
                                    </div>
                                </div>
                            <?php $animDelay += 0.05; endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </main>

    <script>
        // Theme Toggle
        const themeBtn = document.getElementById('themeToggle');
        if(localStorage.getItem('theme') === 'dark') document.documentElement.classList.add('dark');
        if (themeBtn) themeBtn.onclick = () => { 
            const isDark = document.documentElement.classList.toggle('dark'); 
            localStorage.setItem('theme', isDark ? 'dark' : 'light'); 
        };

        // Toast Notifier
        function showToast(msg) {
            const el = document.createElement('div');
            el.className = 'toast'; 
            el.innerHTML = `<i class="fas fa-check-circle" style="color:var(--success); font-size:1.25rem;"></i> <span style="font-size:0.875rem; font-weight:600;">${msg}</span>`;
            document.body.appendChild(el);
            setTimeout(() => { 
                el.style.opacity = '0'; el.style.transform = 'translateY(-20px) translateX(-50%)'; 
                setTimeout(() => el.remove(), 400); 
            }, 3000);
        }

        // Draggable Carousel Logic
        const slider = document.getElementById('statCarousel');
        let isDown = false, startX, scrollLeft;

        slider.addEventListener('mousedown', (e) => {
            isDown = true;
            slider.classList.add('active');
            startX = e.pageX - slider.offsetLeft;
            scrollLeft = slider.scrollLeft;
        });
        slider.addEventListener('mouseleave', () => { isDown = false; slider.classList.remove('active'); });
        slider.addEventListener('mouseup', () => { isDown = false; slider.classList.remove('active'); });
        slider.addEventListener('mousemove', (e) => {
            if (!isDown) return;
            e.preventDefault();
            const x = e.pageX - slider.offsetLeft;
            const walk = (x - startX) * 2; // Scroll fast
            slider.scrollLeft = scrollLeft - walk;
        });

        // Carousel Button Nav
        const scrollAmount = 300;
        document.getElementById('btnPrev').onclick = () => slider.scrollBy({ left: -scrollAmount, behavior: 'smooth' });
        document.getElementById('btnNext').onclick = () => slider.scrollBy({ left: scrollAmount, behavior: 'smooth' });

        // Advanced Search Logic (Hides empty month groups)
        const searchInput = document.getElementById('searchInput');
        const searchClear = document.getElementById('searchClear');

        function performSearch() {
            let filter = searchInput.value.toLowerCase().trim();
            searchClear.style.display = filter ? 'block' : 'none';
            
            let monthGroups = document.querySelectorAll('.month-group');
            let hasVisibleGlobal = false;
            
            monthGroups.forEach(group => {
                let items = group.querySelectorAll('.ledger-item');
                let hasVisibleInGroup = false;
                
                items.forEach(item => {
                    if(item.textContent.toLowerCase().includes(filter)) {
                        item.style.display = '';
                        hasVisibleInGroup = true;
                        hasVisibleGlobal = true;
                    } else {
                        item.style.display = 'none';
                    }
                });
                // Hide whole month block if no items match
                group.style.display = hasVisibleInGroup ? 'block' : 'none';
            });

            // Empty State Handling
            let existingEmpty = document.getElementById('searchEmptyState');
            if (!hasVisibleGlobal && monthGroups.length > 0) {
                if (!existingEmpty) {
                    const emptyHtml = `<div class="empty-state" id="searchEmptyState" style="margin-top:2rem;"><i class="fas fa-search"></i><h3>No results found</h3><p style="font-size:0.9rem; margin-top:0.5rem;">No records match "${filter}".</p></div>`;
                    document.getElementById('ledgerWrapper').insertAdjacentHTML('beforeend', emptyHtml);
                } else {
                    existingEmpty.querySelector('p').innerText = `No records match "${filter}".`;
                }
            } else if (existingEmpty) {
                existingEmpty.remove();
            }
        }

        searchInput.addEventListener('input', performSearch);
        searchClear.addEventListener('click', () => {
            searchInput.value = '';
            performSearch();
            searchInput.focus();
        });

        // Click to Copy ID
        document.addEventListener('click', function(e) {
            const el = e.target.closest('.copy-id');
            if (!el) return;
            const id = el.dataset.id;
            const originalHTML = el.innerHTML;
            
            navigator.clipboard.writeText(id).then(() => {
                el.innerHTML = '<span style="color:var(--success); font-weight:bold;"><i class="fas fa-check"></i> Copied</span>';
                showToast('Transaction ID copied');
                setTimeout(() => { el.innerHTML = originalHTML; }, 1500);
            }).catch(() => {
                const ta = document.createElement('textarea');
                ta.value = id; ta.style.position = 'fixed'; ta.style.opacity = '0';
                document.body.appendChild(ta); ta.select();
                document.execCommand('copy');
                document.body.removeChild(ta);
                el.innerHTML = '<span style="color:var(--success); font-weight:bold;"><i class="fas fa-check"></i> Copied</span>';
                showToast('Transaction ID copied');
                setTimeout(() => { el.innerHTML = originalHTML; }, 1500);
            });
        });
    </script>
</body>
</html>