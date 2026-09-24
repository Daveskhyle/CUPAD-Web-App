<?php
// union_groups.php
// Credit Officer: Assign union/group to branch/CO and view all unions/groups
session_start();

// 1. DATABASE CONNECTION
require_once __DIR__ . '/../includes/config.php';
$pdo = getDbConnection();


// 2. AUTH CHECK
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'co') {
    header('Location: ../index.php');
    exit();
}

$base_path = '../';
$page_title = "My Unions and Groups";

// 3. USER DETAILS
$full_name = $_SESSION['full_name'] ?? $_SESSION['name'] ?? 'Credit Officer';
$username = $_SESSION['username'] ?? '';
$co_branch_id = $_SESSION['branch'] ?? '';

// Load User Profile Pic from DB
$profile_pic = 'default_avatar.png';
if ($username) {
    $stmt = $pdo->prepare("SELECT profile_pic FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user_data = $stmt->fetch();
    if ($user_data && !empty($user_data['profile_pic'])) {
        $profile_pic = $user_data['profile_pic'];
    }
}


// 4. LOGIC & DATA LOADING
function generateUUID() {
    if (function_exists('random_bytes')) {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); 
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); 
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000, mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff));
}

// Fetch all Branch Names for lookups
$branch_names = [];
$stmt = $pdo->query("SELECT id, name FROM branches");
foreach($stmt->fetchAll() as $b) {
    $branch_names[$b['id']] = $b['name'];
}
$co_branch_name = $branch_names[$co_branch_id] ?? 'Unknown';


// Fetch all Unions/Groups from unions table (for UUID lookups later)
$all_groups = [];
try {
    $stmt = $pdo->query("SELECT id as uuid, name FROM unions WHERE status = 'active'");
    $all_groups = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Failed to fetch unions: " . $e->getMessage());
}

// Fetch this CO's official assignments from the assignments table
$my_assignments = [];
if ($username && $co_branch_name !== 'Unknown') {
    $stmt = $pdo->prepare("SELECT `union`, branch, co FROM assignments WHERE co = ? AND branch = ? AND status = 'active'");
    $stmt->execute([$username, $co_branch_name]);
    $my_assignments = $stmt->fetchAll();
}
$my_union_names = array_column($my_assignments, 'union');


// Handle Form Submit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_union'])) {
    $new_name = trim($_POST['new_union_name'] ?? '');
    $new_desc = trim($_POST['new_union_desc'] ?? '');
    $assigned_co = $username;
    $assigned_branch = $co_branch_name;

    if ($new_name && $assigned_co && $assigned_branch && $assigned_branch !== 'Unknown') {
        $pdo->beginTransaction();
        try {
            // Check if the union/group already exists in the `unions` table
            $stmt = $pdo->prepare("SELECT id FROM unions WHERE name = ?");
            $stmt->execute([$new_name]);
            $existing_union = $stmt->fetch();

            if (!$existing_union) {
                $new_uuid = generateUUID();
                $stmt = $pdo->prepare(
                    "INSERT INTO unions (id, name, description, status, created_at) VALUES (?, ?, ?, 'active', NOW())"
                );
                $stmt->execute([$new_uuid, $new_name, $new_desc]);
            }

            $stmt = $pdo->prepare("SELECT id FROM assignments WHERE `union` = ? AND co = ? AND branch = ?");
            $stmt->execute([$new_name, $assigned_co, $assigned_branch]);
            $duplicate = $stmt->fetch();
            
            if (!$duplicate) {
                $stmt = $pdo->prepare(
                    "INSERT INTO assignments (co, `union`, branch, assigned_date, status, created_at) VALUES (?, ?, ?, CURDATE(), 'active', NOW())"
                );
                $stmt->execute([$assigned_co, $new_name, $assigned_branch]);
            }
            
            $pdo->commit();
            header('Location: union_groups.php'); 
            exit();
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log("Failed to create union: " . $e->getMessage());
            $_SESSION['error_message'] = "Failed to create group: " . $e->getMessage();
        }
    }
}

// Fetch Client Data assigned to this officer
$clients_by_union = [];
$total_clients_managed = 0;

try {
    $stmt = $pdo->prepare("SELECT id, name, phone, `union`, branch_id FROM clients WHERE officer_username = ? AND status = 'active' ORDER BY name ASC");
    $stmt->execute([$username]);
    $raw_clients = $stmt->fetchAll();
    
    foreach ($raw_clients as $client) {
        $union_name = $client['union'] ?? 'Unassigned';
        $clients_by_union[$union_name][] = $client;
        $total_clients_managed++;
    }
} catch (Exception $e) {
    error_log("Failed to fetch clients: " . $e->getMessage());
}

// --- UPDATED LOGIC TO SHOW EMPTY GROUPS ---
// 1. Groups that have clients
$unions_with_clients = array_keys($clients_by_union);
// 2. Official assignments (even if 0 clients)
$official_assigned_unions = $my_union_names;
// 3. Merge and unique to get the full list for tabs
$unique_assigned_unions = array_unique(array_merge($unions_with_clients, $official_assigned_unions));
sort($unique_assigned_unions); // Alphabetical order
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#3b82f6">
    <title>Unions & Groups | CUPAD</title>
    
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">    
    <script src="https://cdn.tailwindcss.com"></script>

    <script>
    tailwind.config = {
        darkMode: 'class',
        theme: {
            extend: {
                fontFamily: { sans: ['Inter', 'sans-serif'] },
                colors: {
                    primary: '#3b82f6', secondary: '#8b5cf6', success: '#22c55e', warning: '#f59e0b', error: '#ef4444',
                    bg: { light: '#f0f2f5', dark: '#0f172a' },
                    dark: { bg: '#0f172a', surface: '#1e293b', border: '#334155' }
                }
            }
        }
    }
    </script>
    <style>
        :root {
            --primary-color: #3b82f6; --secondary-color: #8b5cf6;
            --success-color: #22c55e; --warning-color: #f59e0b; --error-color: #ef4444;
            --bg-primary: #f0f2f5; --bg-secondary: #ffffff; --bg-card: #ffffff;
            --bg-header: rgba(255, 255, 255, 0.95);
            --text-primary: #1f2937; --text-secondary: #6b7280; --border-color: #e5e7eb;
            --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
            --border-radius-lg: 1rem; --border-radius-xl: 1.5rem;
        }

        html.dark {
            --bg-primary: #0f172a; --bg-secondary: #1e293b; --bg-card: #1e293b;
            --bg-header: rgba(15, 23, 42, 0.95);
            --text-primary: #f1f5f9; --text-secondary: #94a3b8; --border-color: rgba(255, 255, 255, 0.08);
        }

        body { font-family: 'Inter', sans-serif; background-color: var(--bg-primary); color: var(--text-primary); padding-bottom: 90px; -webkit-tap-highlight-color: transparent; }

        /* HEADER */
        .main-header { background: var(--bg-header); backdrop-filter: blur(12px); border-bottom: 1px solid var(--border-color); position: sticky; top: 0; z-index: 50; padding-top: env(safe-area-inset-top); }
        .navbar { display: flex; justify-content: space-between; align-items: center; padding: 0.8rem 1rem; max-width: 1280px; margin: 0 auto; }
        .logo { font-weight: 700; font-size: 1.25rem; color: var(--primary-color); display: flex; align-items: center; gap: 0.5rem; text-decoration: none; }
        .logo img { height: 32px; width: auto; }

        /* CARDS & GRADIENTS */
        .dashboard-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1rem; margin-bottom: 1.5rem; }
        .dashboard-card { border-radius: var(--border-radius-xl); padding: 1.5rem; position: relative; overflow: hidden; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1); border: none; color: white; }
        .card-gradient-blue { background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); }
        .card-gradient-green { background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%); }
        .card-gradient-purple { background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%); }
        .card-bg-icon { position: absolute; right: -10px; bottom: -20px; font-size: 6rem; color: white; opacity: 0.1; transform: rotate(-10deg); pointer-events: none; }

        /* CONTENT CARDS */
        .union-card { background: var(--bg-card); border-radius: var(--border-radius-xl); padding: 1.5rem; border: 1px solid var(--border-color); box-shadow: var(--shadow-sm); }
        .union-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; border-bottom: 1px solid var(--border-color); padding-bottom: 1rem; }

        /* FORMS */
        .input-group { margin-bottom: 1rem; }
        .input-label { display: block; font-size: 0.85rem; font-weight: 500; margin-bottom: 0.5rem; color: var(--text-secondary); }
        .form-input { width: 100%; padding: 0.75rem 1rem; border-radius: 0.75rem; border: 1px solid var(--border-color); background: var(--bg-primary); color: var(--text-primary); transition: all 0.2s; }
        .form-input:focus { border-color: var(--primary-color); outline: none; ring: 2px solid rgba(59, 130, 246, 0.2); }

        /* TABS & TABLE */
        .tabs-container { display: flex; gap: 0.5rem; overflow-x: auto; padding-bottom: 0.5rem; margin-bottom: 1rem; scrollbar-width: none; }
        .tab-btn { white-space: nowrap; padding: 0.5rem 1.25rem; border-radius: 2rem; font-size: 0.85rem; font-weight: 600; cursor: pointer; border: 1px solid transparent; transition: all 0.2s; }
        .tab-btn.active { background: var(--primary-color); color: white; box-shadow: 0 4px 6px -1px rgba(59, 130, 246, 0.3); }
        .tab-btn.inactive { background: var(--bg-primary); color: var(--text-secondary); border-color: var(--border-color); }
        
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; padding: 0.75rem 1rem; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-secondary); background: var(--bg-primary); }
        td { padding: 0.75rem 1rem; border-bottom: 1px solid var(--border-color); font-size: 0.9rem; }
        tr:last-child td { border-bottom: none; }

        /* NAV & PROFILE */
        .mobile-bottom-nav { display: none; position: fixed; bottom: 0; left: 0; right: 0; background: var(--bg-secondary); border-top: 1px solid var(--border-color); padding: 0.5rem 1rem calc(0.5rem + env(safe-area-inset-bottom)); z-index: 100; justify-content: space-between; }
        .nav-item { display: flex; flex-direction: column; align-items: center; justify-content: center; text-decoration: none; color: var(--text-secondary); font-size: 0.7rem; font-weight: 500; gap: 4px; flex: 1; }
        .nav-item i { font-size: 1.4rem; }
        .nav-item.active { color: var(--primary-color); }
        .profile-btn { width: 36px; height: 36px; border-radius: 50%; overflow: hidden; border: 2px solid var(--primary-color); background: var(--bg-secondary); cursor: pointer; display: flex; align-items: center; justify-content: center; position: relative; }
        .profile-btn img { width: 100%; height: 100%; object-fit: cover; }
        .profile-fallback-icon { font-size: 1.2rem; color: var(--text-secondary); }

        @media (max-width: 768px) {
            .mobile-bottom-nav { display: flex; }
            .dashboard-grid { display: flex; overflow-x: auto; scroll-snap-type: x mandatory; gap: 1rem; padding-bottom: 0.5rem; margin-right: -1rem; padding-right: 1.5rem; scrollbar-width: none; }
            .dashboard-card { min-width: 85vw; scroll-snap-align: center; flex-shrink: 0; }
        }
    </style>
</head>
<body>

    <!-- HEADER -->
    <header class="main-header">
        <nav class="navbar">
            <a href="dashboard.php" class="logo">
                <img src="<?php echo htmlspecialchars($base_path . 'favicon.ico'); ?>" alt="Logo">
                <span>CUPAD</span>
            </a>

            <div class="flex items-center gap-3">
                <button id="themeToggle" class="p-2 text-gray-500 dark:text-gray-400">
                    <i class="fas fa-moon dark:hidden"></i><i class="fas fa-sun hidden dark:inline"></i>
                </button>

                <div class="profile-btn" onclick="window.location.href='profile.php'">
                    <i class="fas fa-user profile-fallback-icon"></i>
                    <?php if (isset($profile_pic) && $profile_pic !== 'default_avatar.png'): ?>
                        <img src="<?php echo $base_path . $profile_pic; ?>" alt="Profile" class="absolute inset-0" onerror="this.style.display='none'"> 
                    <?php endif; ?>
                </div>
                
                <a href="<?php echo htmlspecialchars($base_path . 'logout.php'); ?>" class="p-2 text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-full transition"><i class="fas fa-sign-out-alt"></i></a>
            </div>
        </nav>
    </header>

    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        
        <div class="flex justify-between items-center mb-6">
            <div>
                <h1 class="text-2xl font-extrabold text-gray-900 dark:text-white">Unions & Groups</h1>
                <p class="text-sm text-gray-500 dark:text-gray-400">Manage client assignments and groups</p>
            </div>
        </div>

        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="mb-4 p-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-xl text-red-600 dark:text-red-400">
                <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($_SESSION['error_message']) ?>
            </div>
            <?php unset($_SESSION['error_message']); ?>
        <?php endif; ?>

        <!-- STATS CARDS -->
        <div class="dashboard-grid">
            <div class="dashboard-card card-gradient-blue">
                <i class="fas fa-sitemap card-bg-icon"></i>
                <div class="relative z-10">
                    <div class="text-sm font-semibold uppercase opacity-90">Assigned Groups</div>
                    <div class="text-3xl font-extrabold mt-1"><?= count($unique_assigned_unions) ?></div>
                </div>
            </div>
            <div class="dashboard-card card-gradient-green">
                <i class="fas fa-users card-bg-icon"></i>
                <div class="relative z-10">
                    <div class="text-sm font-semibold uppercase opacity-90">Total Clients</div>
                    <div class="text-3xl font-extrabold mt-1"><?= $total_clients_managed ?></div>
                </div>
            </div>
            <div class="dashboard-card card-gradient-purple">
                <i class="fas fa-chart-pie card-bg-icon"></i>
                <div class="relative z-10">
                    <div class="text-sm font-semibold uppercase opacity-90">Avg Size</div>
                    <div class="text-3xl font-extrabold mt-1"><?= count($unique_assigned_unions) > 0 ? round($total_clients_managed / count($unique_assigned_unions), 1) : 0 ?></div>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            
            <!-- CREATE FORM -->
            <div class="lg:col-span-1">
                <div class="union-card sticky top-24">
                    <div class="union-head">
                        <h2 class="text-lg font-bold text-gray-900 dark:text-white"><i class="fas fa-plus-circle text-primary"></i> Create Group</h2>
                    </div>
                    
                    <form method="post" class="space-y-4">
                        <input type="hidden" name="create_union" value="1">
                        
                        <div class="input-group">
                            <label class="input-label">Group Name</label>
                            <input type="text" id="new_union_name" name="new_union_name" required class="form-input" placeholder="e.g. Market Traders A">
                        </div>

                        <div class="input-group">
                            <label class="input-label">Description (Optional)</label>
                            <div class="flex gap-2">
                                <input type="text" id="new_union_desc" name="new_union_desc" class="form-input" placeholder="Translate from Yoruba...">
                                <button type="button" id="translate_button" class="px-3 bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 rounded-lg border border-gray-200 dark:border-gray-600 hover:bg-gray-200 transition"><i class="fas fa-language"></i></button>
                            </div>
                        </div>

                        <div class="pt-4 border-t border-gray-100 dark:border-gray-700">
                            <div class="grid grid-cols-1 gap-3">
                                <div>
                                    <label class="text-xs text-gray-500">Branch</label>
                                    <input type="text" name="branch" value="<?= htmlspecialchars($co_branch_name) ?>" readonly class="w-full bg-gray-100 dark:bg-gray-800 border-0 rounded text-sm px-2 py-1 text-gray-500 cursor-not-allowed">
                                </div>
                                <div>
                                    <label class="text-xs text-gray-500">Officer</label>
                                    <input type="text" name="co" value="<?= htmlspecialchars($username) ?>" readonly class="w-full bg-gray-100 dark:bg-gray-800 border-0 rounded text-sm px-2 py-1 text-gray-500 cursor-not-allowed">
                                </div>
                            </div>
                        </div>

                        <button type="submit" class="w-full py-3 bg-primary text-white rounded-xl font-bold hover:bg-blue-600 transition shadow-lg shadow-blue-500/30">
                            Create & Assign
                        </button>
                    </form>
                </div>
            </div>

            <!-- LIST VIEW -->
            <div class="lg:col-span-2">
                <div class="union-card min-h-[500px]">
                    <div class="union-head flex-col sm:flex-row gap-4 items-start sm:items-center">
                        <h2 class="text-lg font-bold text-gray-900 dark:text-white">Your Assignments</h2>
                    </div>

                    <?php if (empty($unique_assigned_unions)): ?>
                        <div class="text-center py-12 text-gray-500">
                            <i class="fas fa-folder-open text-4xl mb-3 opacity-30"></i>
                            <p>No groups assigned yet. Create your first group above.</p>
                        </div>
                    <?php else: ?>
                        <!-- Tabs -->
                        <div class="tabs-container">
                            <?php foreach ($unique_assigned_unions as $i => $union_name): 
                                $count = isset($clients_by_union[$union_name]) ? count($clients_by_union[$union_name]) : 0;
                            ?>
                                <button onclick="switchTab('union-<?= $i ?>', this)" class="tab-btn <?= $i === 0 ? 'active' : 'inactive' ?>">
                                    <?= htmlspecialchars($union_name) ?> <span class="ml-1 opacity-70 text-xs">(<?= $count ?>)</span>
                                </button>
                            <?php endforeach; ?>
                        </div>

                        <!-- Tab Content -->
                        <?php foreach ($unique_assigned_unions as $i => $union_name): 
                            $union_clients = $clients_by_union[$union_name] ?? [];
                            
                            // Find UUID for this group
                            $uuid = 'N/A';
                            foreach($all_groups as $g) {
                                if($g['name'] === $union_name) {
                                    $uuid = $g['uuid'];
                                    break;
                                }
                            }
                        ?>
                            <div id="union-<?= $i ?>" class="tab-content <?= $i !== 0 ? 'hidden' : '' ?>">
                                <div class="mb-4 flex items-center justify-between bg-gray-50 dark:bg-gray-800 p-3 rounded-lg border border-gray-100 dark:border-gray-700">
                                    <span class="text-xs font-mono text-gray-500 select-all">UUID: <?= htmlspecialchars($uuid) ?></span>
                                    <span class="text-xs font-bold text-primary"><?= count($union_clients) ?> Clients</span>
                                </div>

                                <div class="overflow-x-auto">
                                    <table class="w-full">
                                        <thead>
                                            <tr>
                                                <th>Client Name</th>
                                                <th>Contact</th>
                                                <th class="hidden sm:table-cell">Branch</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($union_clients)): ?>
                                                <tr>
                                                    <td colspan="3" class="text-center py-12 text-gray-400">
                                                        <i class="fas fa-user-slash block text-2xl mb-2 opacity-20"></i>
                                                        No clients registered in this group yet.
                                                    </td>
                                                </tr>
                                            <?php else: ?>
                                                <?php foreach ($union_clients as $client): ?>
                                                    <tr class="hover:bg-gray-50 dark:hover:bg-white/5 transition">
                                                        <td>
                                                            <div class="flex items-center gap-3">
                                                                <div class="w-8 h-8 rounded-full bg-blue-100 dark:bg-blue-900 text-blue-600 dark:text-blue-300 flex items-center justify-center text-xs font-bold">
                                                                    <?= htmlspecialchars(strtoupper(substr($client['name'], 0, 1))) ?>
                                                                </div>
                                                                <span class="font-medium"><?= htmlspecialchars($client['name']) ?></span>
                                                            </div>
                                                        </td>
                                                        <td>
                                                            <?php if (!empty($client['phone'])): ?>
                                                                <a href="tel:<?= htmlspecialchars($client['phone']) ?>" class="text-xs text-gray-500 hover:text-primary"><i class="fas fa-phone"></i> <?= htmlspecialchars($client['phone']) ?></a>
                                                            <?php else: ?>
                                                                <span class="text-xs text-gray-400">-</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td class="hidden sm:table-cell"><span class="text-xs px-2 py-1 rounded bg-gray-100 dark:bg-gray-800 text-gray-500"><?= htmlspecialchars($branch_names[$client['branch_id']] ?? $co_branch_name) ?></span></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <div class="mt-8 text-center pb-8">
            <a href="dashboard.php" class="text-sm text-gray-500 hover:text-primary transition"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
        </div>
    </main>

    <!-- MOBILE NAV -->
    <nav class="mobile-bottom-nav">
        <a href="dashboard.php" class="nav-item">
            <i class="fas fa-home"></i>
            <span>Home</span>
        </a>
        <a href="clients.php" class="nav-item">
            <i class="fas fa-users"></i>
            <span>Clients</span>
        </a>
        <a href="union_groups.php" class="nav-item active">
            <div style="background: var(--primary-color); width: 45px; height: 45px; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; margin-top: -25px; box-shadow: 0 4px 10px rgba(59, 130, 246, 0.4);">
                <i class="fas fa-layer-group" style="font-size: 1.2rem;"></i>
            </div>
            <span style="font-size:0.65rem; font-weight:600; margin-top:2px;">Groups</span>
        </a>
        <a href="saving_collection.php" class="nav-item">
            <i class="fas fa-piggy-bank"></i>
            <span>Save</span>
        </a>
        <a href="loan_collection.php" class="nav-item">
            <i class="fas fa-money-bill-wave"></i>
            <span>Repay</span>
        </a>
    </nav>

    <script>
        // Init Theme
        const html = document.documentElement;
        if(localStorage.getItem('theme') === 'dark') html.classList.add('dark');
        
        document.getElementById('themeToggle').addEventListener('click', () => {
            html.classList.toggle('dark');
            localStorage.setItem('theme', html.classList.contains('dark') ? 'dark' : 'light');
        });

        // Tab Switching
        function switchTab(tabId, btn) {
            document.querySelectorAll('.tab-content').forEach(el => el.classList.add('hidden'));
            document.getElementById(tabId).classList.remove('hidden');
            
            document.querySelectorAll('.tab-btn').forEach(b => {
                b.classList.remove('active');
                b.classList.add('inactive');
            });
            btn.classList.remove('inactive');
            btn.classList.add('active');
        }

        // Gemini Translation
        const API_KEY = "AIzaSyC7KpTuaEFPMsSr6gBcdplhZjTwfHjsGUE";
        document.getElementById('translate_button').addEventListener('click', async function() {
            var unionName = document.getElementById('new_union_name').value;
            var btn = this;
            const originalHTML = btn.innerHTML;

            if (unionName) {
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
                btn.disabled = true;
                
                try {
                    const response = await fetch(`https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=${API_KEY}`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ "contents": [{ "parts": [{ "text": "Translate the following Yoruba name to English. Only return the English translation: " + unionName }] }] })
                    });
                    const data = await response.json();
                    if (data.candidates?.[0]?.content?.parts?.[0]?.text) {
                        document.getElementById('new_union_desc').value = data.candidates[0].content.parts[0].text.trim();
                    }
                } catch (error) {
                    console.error('Translation error:', error);
                } finally {
                    btn.innerHTML = originalHTML;
                    btn.disabled = false;
                }
            } else {
                alert("Enter a Group Name first.");
            }
        });
    </script>
</body>
</html>