<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['username'])) {
    header("Location: ../login.php");
    exit();
}

// ==========================================
//        DATABASE CONFIGURATION
// ==========================================
define('DB_HOST', 'localhost');
define('DB_NAME', 'cupadnam_db');
define('DB_USER', 'cupadnam_db');
define('DB_PASS', 'f2GrjZQCz8E39nCu9eLg');
define('DB_CHARSET', 'utf8mb4');

function getDbConnection() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            // For AJAX requests, return JSON error
            if (isset($_GET['ajax'])) {
                header('Content-Type: application/json');
                echo json_encode(['error' => 'Database connection failed']);
                exit;
            }
            die("Database Connection Error: " . $e->getMessage());
        }
    }
    return $pdo;
}

// ==========================================
//        AJAX HANDLER (API)
// ==========================================
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    $pdo = getDbConnection();

    try {
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $limit = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 50;
        $search = isset($_GET['search']) ? trim($_GET['search']) : '';
        $offset = ($page - 1) * $limit;

        // Build Query
        $sql = "SELECT 
                    c.id, c.name, c.phone, c.address, c.`union`, c.status,
                    u.full_name as officer_name,
                    (SELECT SUM(s.balance) FROM savings s WHERE s.client_id = c.id) as total_savings,
                    (SELECT COUNT(*) FROM savings s WHERE s.client_id = c.id) as savings_count
                FROM clients c
                LEFT JOIN users u ON c.officer_username = u.username
                WHERE 1=1";
        
        $params = [];

        if (!empty($search)) {
            $sql .= " AND (c.name LIKE ? OR c.id LIKE ? OR c.phone LIKE ? OR c.address LIKE ?)";
            $term = "%$search%";
            $params = [$term, $term, $term, $term];
        }

        // Count Total for Pagination
        $countSql = str_replace("SELECT 
                    c.id, c.name, c.phone, c.address, c.`union`, c.status,
                    u.full_name as officer_name,
                    (SELECT SUM(s.balance) FROM savings s WHERE s.client_id = c.id) as total_savings,
                    (SELECT COUNT(*) FROM savings s WHERE s.client_id = c.id) as savings_count", "SELECT COUNT(*)", $sql);
        
        $stmtCount = $pdo->prepare($countSql);
        $stmtCount->execute($params);
        $totalClients = $stmtCount->fetchColumn();

        // Fetch Data
        $sql .= " ORDER BY c.created_at DESC LIMIT $limit OFFSET $offset";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $clients = $stmt->fetchAll();

        // Process data for frontend
        $formattedClients = array_map(function($client) {
            return [
                'id' => $client['id'],
                'name' => $client['name'],
                'phone' => $client['phone'] ?? '-',
                'address' => $client['address'] ?? '-',
                'union' => $client['union'] ?? '-',
                'officer_name' => $client['officer_name'] ?? 'Unassigned',
                'status' => $client['status'],
                'total_savings' => number_format((float)$client['total_savings'], 2),
                'savings_count' => (int)$client['savings_count'],
                'has_savings' => ((float)$client['total_savings'] > 0)
            ];
        }, $clients);

        echo json_encode([
            'clients' => $formattedClients,
            'pagination' => [
                'total_clients' => $totalClients,
                'total_pages' => ceil($totalClients / $limit),
                'current_page' => $page,
                'from' => $totalClients > 0 ? $offset + 1 : 0,
                'to' => min($offset + $limit, $totalClients)
            ]
        ]);
        exit;

    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
        exit;
    }
}

// ==========================================
//        INITIAL PAGE LOAD (HTML)
// ==========================================
// We only need the total count for the initial render, everything else loads via JS
$pdo = getDbConnection();
$totalClients = $pdo->query("SELECT COUNT(*) FROM clients")->fetchColumn();
$clientsPerPage = 50;
$totalPages = max(1, ceil($totalClients / $clientsPerPage));
?>
<!DOCTYPE html>
<html lang="en" class="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Clients - CUPAD Admin</title>
    <link rel="icon" type="image/png" href="../uploads/CUPAD LOGO.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />
    <style>
        :root {
            --primary-color: #2563eb;
            --primary-dark: #1d4ed8;
            --secondary-color: #7c3aed;
            --secondary-dark: #6d28d9;
            --accent-color: #06b6d4;
            --success-color: #10b981;
            --warning-color: #f59e0b;
            --danger-color: #ef4444;
            --info-color: #3b82f6;
            --glow-color-1: #3b82f6;
            --glow-color-2: #8b5cf6;
            --surface-light: rgba(255, 255, 255, 0.95);
            --surface-dark: rgba(17, 24, 39, 0.95);
            --text-primary: #111827;
            --text-secondary: #6b7280;
            --border-color: rgba(255, 255, 255, 0.2);
            --shadow-light: 0 10px 40px rgba(0, 0, 0, 0.08);
            --shadow-medium: 0 15px 50px rgba(0, 0, 0, 0.12);
            --shadow-heavy: 0 25px 70px rgba(0, 0, 0, 0.2);
        }

        html.dark {
            --text-primary: #f9fafb;
            --text-secondary: #9ca3af;
            --border-color: rgba(255, 255, 255, 0.15);
            --shadow-light: 0 10px 40px rgba(0, 0, 0, 0.3);
            --shadow-medium: 0 15px 50px rgba(0, 0, 0, 0.4);
            --shadow-heavy: 0 25px 70px rgba(0, 0, 0, 0.6);
            --success-color: #34d399;
            --warning-color: #fbbf24;
            --danger-color: #f87171;
            --info-color: #60a5fa;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body { 
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 50%, #f8fafc 100%);
            color: var(--text-primary);
            position: relative;
            overflow-x: hidden;
            min-height: 100vh;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            line-height: 1.6;
        }

        html.dark body { 
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 50%, #0f1419 100%);
        }

        /* Background Effects */
        #background-effects {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: -2;
            overflow: hidden;
            opacity: 0.1;
        }

        #background-effects::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle at 30% 40%, var(--glow-color-1) 0%, transparent 50%),
                        radial-gradient(circle at 80% 20%, var(--glow-color-2) 0%, transparent 50%);
            opacity: 0.3;
        }

        html.dark #background-effects::before { 
            opacity: 0.2; 
        }

        /* Animations */
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes slideUp {
            from { 
                opacity: 0; 
                transform: translateY(20px); 
            }
            to { 
                opacity: 1; 
                transform: translateY(0); 
            }
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.6; }
        }

        /* Header */
        .main-header {
            background: var(--surface-light);
            backdrop-filter: blur(25px);
            -webkit-backdrop-filter: blur(25px);
            border: 1px solid var(--border-color);
            position: sticky;
            top: 0;
            z-index: 1000;
            box-shadow: var(--shadow-light);
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        html.dark .main-header {
            background: var(--surface-dark);
        }

        .header-glow {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, 
                var(--glow-color-1) 0%, 
                var(--glow-color-2) 50%, 
                var(--accent-color) 100%);
            opacity: 0.9;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 2rem;
        }

        .navbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1.25rem 0;
        }

        .logo {
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--primary-color);
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .nav-link {
            color: var(--text-secondary);
            text-decoration: none;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            transition: all 0.3s ease;
            font-weight: 500;
        }

        .nav-link:hover {
            color: var(--primary-color);
            background: rgba(37, 99, 235, 0.1);
        }

        .theme-btn {
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid var(--border-color);
            color: var(--text-primary);
            padding: 0.5rem;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .theme-btn:hover {
            background: rgba(255, 255, 255, 0.2);
        }

        /* Main Content */
        .main-content {
            padding: 2rem 0;
            animation: fadeIn 0.6s ease-out;
        }

        .main-title {
            font-size: 2.5rem;
            font-weight: 800;
            color: var(--text-primary);
            margin-bottom: 0.5rem;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .main-subtitle {
            color: var(--text-secondary);
            font-size: 1.1rem;
            margin-bottom: 2rem;
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .section-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-primary);
        }

        /* Search and Controls */
        .search-container {
            position: relative;
            max-width: 400px;
            width: 100%;
        }

        .search-input {
            width: 100%;
            padding: 0.75rem 1rem 0.75rem 3rem;
            border: 1px solid var(--border-color);
            border-radius: 12px;
            background: var(--surface-light);
            color: var(--text-primary);
            font-size: 1rem;
            transition: all 0.3s ease;
        }

        html.dark .search-input {
            background: var(--surface-dark);
        }

        .search-input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        .search-icon {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-secondary);
        }

        /* Loading States */
        .loading-container {
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 4rem 2rem;
            min-height: 400px;
        }

        .loading-spinner {
            display: inline-block;
            width: 50px;
            height: 50px;
            border: 3px solid rgba(37, 99, 235, 0.1);
            border-radius: 50%;
            border-top-color: var(--primary-color);
            animation: spin 1s ease-in-out infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        .loading-text {
            margin-left: 1rem;
            color: var(--text-secondary);
            font-size: 1.1rem;
        }

        /* Table Styles */
        .table-container {
            background: var(--surface-light);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            overflow: hidden;
            box-shadow: var(--shadow-medium);
            animation: slideUp 0.8s ease-out;
        }

        html.dark .table-container {
            background: var(--surface-dark);
        }

        .table-header {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 1.5rem;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
        }

        .data-table th {
            background: rgba(0, 0, 0, 0.05);
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            color: var(--text-primary);
            border-bottom: 1px solid var(--border-color);
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .data-table td {
            padding: 1rem;
            border-bottom: 1px solid var(--border-color);
            color: var(--text-secondary);
            transition: all 0.3s ease;
        }

        .data-table tr:hover td {
            background: rgba(37, 99, 235, 0.02);
            color: var(--text-primary);
        }

        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Status Colors - Adjust based on SQL enum values */
        .status-active {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success-color);
        }

        .status-inactive {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning-color);
        }
        
        .status-blacklisted {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger-color);
        }

        .action-btn {
            padding: 0.5rem 0.75rem;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 0.9rem;
            transition: all 0.3s ease;
            margin: 0 0.25rem;
        }

        .btn-view {
            background: var(--info-color);
            color: white;
        }

        .btn-edit {
            background: var(--warning-color);
            color: white;
        }

        .btn-delete {
            background: var(--danger-color);
            color: white;
        }

        .action-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        /* Pagination */
        .pagination-container {
            margin-top: 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
            padding: 1rem;
        }

        .pagination-info {
            color: var(--text-secondary);
            font-size: 0.9rem;
        }

        .pagination-controls {
            display: flex;
            gap: 0.5rem;
            align-items: center;
        }

        .pagination-btn, .pagination-number {
            padding: 0.5rem 1rem;
            background: var(--primary-color);
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-size: 0.9rem;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
        }

        .pagination-number {
            background: rgba(255, 255, 255, 0.1);
            color: var(--text-primary);
            border: 1px solid var(--border-color);
        }

        .pagination-current {
            padding: 0.5rem 0.75rem;
            background: var(--secondary-color);
            color: white;
            border-radius: 8px;
            font-size: 0.9rem;
            font-weight: 600;
        }

        .pagination-btn:hover, .pagination-number:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        .pagination-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            color: var(--text-secondary);
        }

        .empty-icon {
            font-size: 4rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        .empty-title {
            font-size: 1.5rem;
            margin-bottom: 0.5rem;
            color: var(--text-primary);
        }

        /* Responsive */
        @media (max-width: 768px) {
            .container {
                padding: 0 1rem;
            }
            
            .main-title {
                font-size: 2rem;
            }
            
            .section-header {
                flex-direction: column;
                align-items: stretch;
            }
            
            .search-container {
                max-width: 100%;
            }
            
            .pagination-container {
                flex-direction: column;
                align-items: center;
            }
            
            .data-table {
                font-size: 0.9rem;
            }
            
            .action-btn {
                padding: 0.25rem 0.5rem;
                font-size: 0.8rem;
                margin: 0.125rem;
            }
        }

        /* Dark mode adjustments */
        html.dark .search-input,
        html.dark .data-table {
            background: var(--surface-dark);
        }

        html.dark .table-header {
            background: linear-gradient(135deg, var(--primary-dark), var(--secondary-dark));
        }

        /* Scrollbar styling */
        ::-webkit-scrollbar {
            width: 8px;
        }

        ::-webkit-scrollbar-track {
            background: rgba(0, 0, 0, 0.1);
        }

        ::-webkit-scrollbar-thumb {
            background: var(--primary-color);
            border-radius: 4px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: var(--primary-dark);
        }
    </style>
</head>
<body>
    <div id="background-effects"></div>
    
    <header class="main-header">
        <div class="header-glow"></div>
        <div class="container">
            <nav class="navbar">
                <a href="#" class="logo">
                    <i class="fas fa-users-cog"></i>
                    CUPAD Admin
                </a>
                <div style="display: flex; align-items: center; gap: 1rem;">
                    <a href="dashboard.php" class="nav-link">
                        <i class="fas fa-tachometer-alt"></i> Dashboard
                    </a>
                    <button class="theme-btn" onclick="toggleTheme()">
                        <i class="fas fa-moon" id="theme-icon"></i>
                    </button>
                </div>
            </nav>
        </div>
    </header>

    <main class="main-content">
        <div class="container">
            <h1 class="main-title">Manage Clients</h1>
            <p class="main-subtitle">View and manage all registered clients</p>

            <div class="section-header">
                <h2 class="section-title">Client List</h2>
                <div class="search-container">
                    <i class="fas fa-search search-icon"></i>
                    <input type="text" class="search-input" id="searchInput" placeholder="Search clients by name, ID, phone, or address...">
                </div>
            </div>

            <div class="table-container">
                <div class="table-header">
                    <h3 style="margin: 0; font-size: 1.2rem;">Client Information</h3>
                </div>
                
                <div id="loadingContainer" class="loading-container">
                    <div class="loading-spinner"></div>
                    <span class="loading-text">Loading clients...</span>
                </div>

                <div id="tableContent" style="display: none;">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Client ID</th>
                                <th>Name</th>
                                <th>Phone</th>
                                <th>Address</th>
                                <th>Union</th>
                                <th>Officer</th>
                                <th>Savings</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="clientsTableBody">
                        </tbody>
                    </table>

                    <div id="paginationContainer" class="pagination-container">
                        <div class="pagination-info" id="paginationInfo"></div>
                        <div class="pagination-controls" id="paginationControls"></div>
                    </div>
                </div>

                <div id="emptyState" class="empty-state" style="display: none;">
                    <div class="empty-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="empty-title">No clients found</div>
                    <p>Try adjusting your search criteria or add new clients.</p>
                </div>
            </div>
        </div>
    </main>

    <script>
        // Global variables
        let currentPage = 1;
        let currentSearch = '';
        let isLoading = false;
        let totalClients = <?php echo $totalClients; ?>;
        let totalPages = <?php echo $totalPages; ?>;

        // Theme toggle function
        function toggleTheme() {
            const html = document.documentElement;
            const themeIcon = document.getElementById('theme-icon');
            
            if (html.classList.contains('dark')) {
                html.classList.remove('dark');
                themeIcon.classList.remove('fa-sun');
                themeIcon.classList.add('fa-moon');
                localStorage.setItem('theme', 'light');
            } else {
                html.classList.add('dark');
                themeIcon.classList.remove('fa-moon');
                themeIcon.classList.add('fa-sun');
                localStorage.setItem('theme', 'dark');
            }
        }

        // Load saved theme
        const savedTheme = localStorage.getItem('theme') || 'light';
        if (savedTheme === 'dark') {
            document.documentElement.classList.add('dark');
            document.getElementById('theme-icon').classList.remove('fa-moon');
            document.getElementById('theme-icon').classList.add('fa-sun');
        }

        // Load clients via AJAX (Fetching from SELF)
        async function loadClients(page = 1, search = '') {
            if (isLoading) return;
            
            isLoading = true;
            showLoading();
            
            try {
                const params = new URLSearchParams({
                    ajax: 1, // Trigger AJAX mode
                    page: page,
                    per_page: 50,
                    search: search
                });
                
                // Point to current file
                const response = await fetch(`?${params}`);
                
                if (!response.ok) {
                    throw new Error('Failed to load clients');
                }
                
                const data = await response.json();
                
                if (data.error) {
                    throw new Error(data.error);
                }
                
                renderClients(data.clients);
                renderPagination(data.pagination);
                
                currentPage = page;
                currentSearch = search;
                totalClients = data.pagination.total_clients;
                totalPages = data.pagination.total_pages;
                
                showTableContent();
                
            } catch (error) {
                console.error('Error loading clients:', error);
                showError('Failed to load clients. Please try again.');
            } finally {
                isLoading = false;
            }
        }

        // Render clients in table
        function renderClients(clients) {
            const tbody = document.getElementById('clientsTableBody');
            
            if (clients.length === 0) {
                showEmptyState();
                return;
            }
            
            tbody.innerHTML = clients.map(client => `
                <tr>
                    <td><strong>${escapeHtml(client.id)}</strong></td>
                    <td><strong>${escapeHtml(client.name)}</strong></td>
                    <td>${escapeHtml(client.phone)}</td>
                    <td>${escapeHtml(client.address)}</td>
                    <td>${escapeHtml(client.union)}</td>
                    <td>${escapeHtml(client.officer_name)}</td>
                    <td>
                        <div style="text-align: center;">
                            ${client.has_savings ? 
                                `<span style="color: var(--success-color); font-weight: 600;">₦${client.total_savings}</span><br>
                                 <small style="color: var(--text-secondary);">${client.savings_count} records</small>` :
                                '<span style="color: var(--text-secondary);">No savings</span>'
                            }
                        </div>
                    </td>
                    <td>
                        <span class="status-badge status-${client.status}">
                            ${client.status}
                        </span>
                    </td>
                    <td>
                        <button class="action-btn btn-view" onclick="viewClient('${client.id}')" title="View">
                            <i class="fas fa-eye"></i>
                        </button>
                        <button class="action-btn btn-edit" onclick="editClient('${client.id}')" title="Edit">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button class="action-btn btn-delete" onclick="deleteClient('${client.id}')" title="Delete">
                            <i class="fas fa-trash"></i>
                        </button>
                    </td>
                </tr>
            `).join('');
        }

        // Render pagination controls
        function renderPagination(pagination) {
            const infoContainer = document.getElementById('paginationInfo');
            const controlsContainer = document.getElementById('paginationControls');
            
            // Update info
            infoContainer.innerHTML = `Showing ${pagination.from} to ${pagination.to} of ${pagination.total_clients} clients (Page ${pagination.current_page} of ${pagination.total_pages})`;
            
            // Build pagination controls
            let controlsHtml = '';
            
            // Previous button
            if (pagination.current_page > 1) {
                controlsHtml += `
                    <button class="pagination-btn" onclick="loadClients(${pagination.current_page - 1}, currentSearch)">
                        <i class="fas fa-chevron-left"></i> Previous
                    </button>
                `;
            }
            
            // Page numbers
            const startPage = Math.max(1, pagination.current_page - 2);
            const endPage = Math.min(pagination.total_pages, pagination.current_page + 2);
            
            for (let i = startPage; i <= endPage; i++) {
                if (i === pagination.current_page) {
                    controlsHtml += `<span class="pagination-current">${i}</span>`;
                } else {
                    controlsHtml += `
                        <button class="pagination-number" onclick="loadClients(${i}, currentSearch)">
                            ${i}
                        </button>
                    `;
                }
            }
            
            // Next button
            if (pagination.current_page < pagination.total_pages) {
                controlsHtml += `
                    <button class="pagination-btn" onclick="loadClients(${pagination.current_page + 1}, currentSearch)">
                        Next <i class="fas fa-chevron-right"></i>
                    </button>
                `;
            }
            
            controlsContainer.innerHTML = controlsHtml;
        }

        // Show loading state
        function showLoading() {
            document.getElementById('loadingContainer').style.display = 'flex';
            document.getElementById('tableContent').style.display = 'none';
            document.getElementById('emptyState').style.display = 'none';
        }

        // Show table content
        function showTableContent() {
            document.getElementById('loadingContainer').style.display = 'none';
            document.getElementById('tableContent').style.display = 'block';
            document.getElementById('emptyState').style.display = 'none';
        }

        // Show empty state
        function showEmptyState() {
            document.getElementById('loadingContainer').style.display = 'none';
            document.getElementById('tableContent').style.display = 'none';
            document.getElementById('emptyState').style.display = 'block';
        }

        // Show error message
        function showError(message) {
            const tbody = document.getElementById('clientsTableBody');
            tbody.innerHTML = `
                <tr>
                    <td colspan="9" style="text-align: center; padding: 2rem; color: var(--danger-color);">
                        <i class="fas fa-exclamation-triangle" style="font-size: 2rem; margin-bottom: 1rem;"></i><br>
                        ${message}
                    </td>
                </tr>
            `;
            showTableContent();
        }

        // Escape HTML to prevent XSS
        function escapeHtml(text) {
            if (text === null || text === undefined) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Client action functions
        function viewClient(clientId) {
            window.location.href = `view_client.php?id=${clientId}`;
        }

        function editClient(clientId) {
            window.location.href = `edit_client.php?id=${clientId}`;
        }

        function deleteClient(clientId) {
            if (confirm('Are you sure you want to delete this client? This action cannot be undone.')) {
                // Implement delete functionality
                alert('Delete functionality to be implemented');
            }
        }

        // Search functionality with debouncing
        let searchTimeout;
        document.getElementById('searchInput').addEventListener('input', function(e) {
            clearTimeout(searchTimeout);
            const searchTerm = e.target.value.trim();
            
            searchTimeout = setTimeout(() => {
                loadClients(1, searchTerm);
            }, 300); // Debounce for 300ms
        });

        // Initialize page
        document.addEventListener('DOMContentLoaded', function() {
            loadClients(1, '');
        });
    </script>
</body>
</html>