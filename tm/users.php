<?php
// tm/users.php (or staff.php) - Top Management Staff Directory
session_start();
require_once '../includes/config.php';
$pdo = getDbConnection();

// Security check: admin, tm, zm, am can access this page
$allowed_roles =['admin', 'tm', 'zm', 'am'];
if (!isset($_SESSION['user_role']) || !in_array($_SESSION['user_role'], $allowed_roles)) {
    header('Location: ../index.php');
    exit();
}
$viewer_role = $_SESSION['user_role'];
$can_add_staff = in_array($viewer_role, ['admin', 'tm']);

// --- Configuration ---
$base_path = '../'; 
$current_username = $_SESSION['username'] ?? '';

// --- Header Data Logic (Profile Pic) ---
$full_name = $_SESSION['name'] ?? $_SESSION['full_name'] ?? 'Manager';
$user_role = $_SESSION['user_role'];
$profile_pic_path = '';
$has_profile_pic = false;

// Generate a beautiful dynamic avatar based on the user's name
$fallback_avatar = "https://ui-avatars.com/api/?name=" . urlencode($full_name) . "&background=3b82f6&color=fff&rounded=true&bold=true";

try {
    $stmt = $pdo->prepare("SELECT profile_pic FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$_SESSION['user_id']]);
    $currentUserRow = $stmt->fetch();

    if ($currentUserRow && !empty($currentUserRow['profile_pic'])) {
        $checkPath = __DIR__ . '/../uploads/' . $currentUserRow['profile_pic'];
        if (file_exists($checkPath)) {
             $profile_pic_path = 'uploads/' . $currentUserRow['profile_pic'];
             $has_profile_pic = true;
        } elseif (file_exists(__DIR__ . '/../' . $currentUserRow['profile_pic'])) {
             $profile_pic_path = $currentUserRow['profile_pic'];
             $has_profile_pic = true;
        }
    }
} catch (PDOException $e) {
    // Handle error silently for header
}

// --- Table Generator Function ---
function generateUserTableHTML($pdo, $page, $limit, $searchTerm = '') {
    $offset = ($page - 1) * $limit;
    
    $params =[];
    // DO NOT SHOW ADMINS
    $where = "WHERE u.role != 'admin'";

    if (!empty($searchTerm)) {
        $term = "%$searchTerm%";
        $where .= " AND (u.name LIKE ? OR u.username LIKE ? OR u.role LIKE ? OR z.name LIKE ? OR a.name LIKE ? OR b.name LIKE ?)";
        $params =[$term, $term, $term, $term, $term, $term];
    }

    $countSql = "SELECT COUNT(*) FROM users u
        LEFT JOIN zones z ON u.zone_id = z.id
        LEFT JOIN areas a ON u.area_id = a.id
        LEFT JOIN branches b ON u.branch_id = b.id
        $where";
    $stmtCount = $pdo->prepare($countSql);
    $stmtCount->execute($params);
    $totalUsers = $stmtCount->fetchColumn();

    $sql = "SELECT u.*,
        z.name as zone_name,
        a.name as area_name,
        b.name as branch_name,
        (SELECT name FROM users zm_user WHERE zm_user.zone_id = u.zone_id AND zm_user.role IN ('zm','dzm') AND zm_user.status='active' LIMIT 1) as zm_name,
        (SELECT name FROM users am_user WHERE am_user.area_id = u.area_id AND am_user.role='am' AND am_user.status='active' LIMIT 1) as am_name,
        (SELECT COUNT(*) FROM user_passkeys up WHERE up.user_id = u.id) as passkey_count
        FROM users u
        LEFT JOIN zones z ON u.zone_id = z.id
        LEFT JOIN areas a ON u.area_id = a.id
        LEFT JOIN branches b ON u.branch_id = b.id
        $where";

    $sql .= " ORDER BY u.id DESC LIMIT $limit OFFSET $offset";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $users = $stmt->fetchAll();

    $totalPages = ceil($totalUsers / $limit);
    $page = max(1, min($page, $totalPages > 0 ? $totalPages : 1));

    ob_start();
    ?>
    <div class="overflow-x-auto w-full">
        <table class="w-full text-left border-collapse whitespace-nowrap" id="userTable">
            <thead>
                <tr class="table-header text-xs uppercase tracking-wider">
                    <th class="p-4 font-semibold rounded-tl-xl">Staff Details</th>
                    <th class="p-4 font-semibold">Role</th>
                    <th class="p-4 font-semibold">Location Assignment</th>
                    <th class="p-4 font-semibold">Reporting Hierarchy</th>
                    <th class="p-4 font-semibold text-right rounded-tr-xl">Actions</th>
                </tr>
            </thead>
            <tbody style="border-color: var(--border-color);">
                <?php if (empty($users)): ?>
                    <tr>
                        <td colspan="5" class="p-12 text-center table-cell-muted">
                            <div class="flex flex-col items-center justify-center">
                                <i class="fas fa-users-slash text-5xl mb-4 opacity-20"></i>
                                <span class="text-base font-medium">No staff members found.</span>
                                <p class="text-xs mt-1 opacity-70">Try adjusting your search criteria.</p>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($users as $user): ?>
                    <?php 
                    $user_id = $user['id'];
                    $username = $user['username'] ?? '';
                    $role = $user['role'] ?? '';
                    $role_class = strtolower($role);

                    // Location String
                    $assignment_parts =[];
                    if (!empty($user['zone_name'])) $assignment_parts[] = '<span class="font-semibold text-purple-600 dark:text-purple-400">' . htmlspecialchars($user['zone_name']) . '</span>';
                    if (!empty($user['area_name'])) $assignment_parts[] = htmlspecialchars($user['area_name']);
                    if (!empty($user['branch_name'])) $assignment_parts[] = htmlspecialchars($user['branch_name']);
                    
                    $assignment = implode(' <i class="fas fa-chevron-right text-[9px] table-cell-muted mx-1"></i> ', $assignment_parts);
                    if (empty($assignment)) {
                        $assignment = in_array($role, ['tm']) 
                            ? '<span class="table-cell-muted italic">Headquarters</span>' 
                            : '<span class="text-red-500 italic">Not Assigned</span>';
                    }

                    // Hierarchy String
                    $hierarchy_html = '';
                    if (in_array($role_class, ['co', 'bm'])) {
                        $am_display = $user['am_name'] ? htmlspecialchars($user['am_name']) : '<span class="text-red-400 italic">Unassigned</span>';
                        $zm_display = $user['zm_name'] ? htmlspecialchars($user['zm_name']) : '<span class="text-red-400 italic">Unassigned</span>';
                        $hierarchy_html = 'AM: <span class="font-semibold table-cell">'.$am_display.'</span> &bull; ZM: <span class="font-semibold table-cell">'.$zm_display.'</span>';
                    } elseif ($role_class === 'am') {
                        $zm_display = $user['zm_name'] ? htmlspecialchars($user['zm_name']) : '<span class="text-red-400 italic">Unassigned</span>';
                        $hierarchy_html = 'ZM: <span class="font-semibold table-cell">'.$zm_display.'</span>';
                    } elseif (in_array($role_class, ['zm', 'dzm'])) {
                        $hierarchy_html = '<span class="table-cell-muted">Headquarters Group</span>';
                    } else {
                        $hierarchy_html = '<span class="table-cell-muted">-</span>';
                    }

                    // Role Styling
                    $roleLabel = strtoupper($role);
                    if($role === 'co') $roleLabel = 'CREDIT OFFICER';
                    if($role === 'tm') $roleLabel = 'TOP MGT';
                    
                    $roleStyles =[
                        'tm' => 'bg-purple-100 text-purple-800 dark:bg-purple-900/30 dark:text-purple-400 border border-purple-200 dark:border-purple-800',
                        'zm' => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-400 border border-emerald-200 dark:border-emerald-800',
                        'dzm' => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-400 border border-emerald-200 dark:border-emerald-800',
                        'am' => 'bg-orange-100 text-orange-800 dark:bg-orange-900/30 dark:text-orange-400 border border-orange-200 dark:border-orange-800',
                        'bm' => 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400 border border-blue-200 dark:border-blue-800',
                        'co' => 'bg-indigo-100 text-indigo-800 dark:bg-indigo-900/30 dark:text-indigo-400 border border-indigo-200 dark:border-indigo-800'
                    ];
                    $rStyle = $roleStyles[$role_class] ?? 'bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-400';
                    ?>
                    <tr class="table-row group">
                        <td class="p-4">
                            <div class="font-semibold table-cell flex items-center gap-3">
                                <?php 
                                    $userAvatar = "https://ui-avatars.com/api/?name=" . urlencode($user['name'] ?? $username) . "&background=random&color=fff&rounded=true&size=32";
                                ?>
                                <img src="<?php echo $userAvatar; ?>" alt="avatar" class="w-9 h-9 rounded-full shadow-sm border border-[color:var(--border-color)]">
                                <div>
                                    <div class="leading-tight"><?php echo htmlspecialchars($user['name'] ?? $user['full_name'] ?? 'Unknown'); ?></div>
                                    <div class="text-xs table-cell-muted font-mono mt-1">@<?php echo htmlspecialchars($username); ?></div>
                                </div>
                            </div>
                        </td>
                        <td class="p-4">
                            <span class="px-2.5 py-1 rounded-md text-[10px] font-bold tracking-wide <?php echo $rStyle; ?>">
                                <?php echo htmlspecialchars($roleLabel); ?>
                            </span>
                        </td>
                        <td class="p-4 text-sm table-cell">
                            <?php echo $assignment; ?>
                        </td>
                        <td class="p-4 text-xs table-cell-muted">
                            <?php echo $hierarchy_html; ?>
                        </td>
                        <td class="p-4 text-right">
                            <button onclick="viewUserDetail(<?php echo $user_id; ?>)" class="px-3 py-1.5 btn-secondary text-xs inline-flex items-center gap-1.5 border border-transparent hover:border-[color:var(--border-color)]">
                                <i class="fas fa-eye"></i> View
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
    $tableHTML = ob_get_clean();

    ob_start();
    ?>
    <div class="flex flex-col sm:flex-row justify-between items-center gap-4 p-4 border-t border-[color:var(--border-color)] table-header rounded-b-xl">
        <div class="text-sm table-cell-muted">
            Showing Page <span class="font-bold table-cell"><?php echo $page; ?></span> of <span class="font-bold table-cell"><?php echo $totalPages; ?></span>
        </div>
        <div class="flex gap-2">
            <button onclick="loadUserTable(<?php echo $page - 1; ?>)" <?php echo $page <= 1 ? 'disabled' : ''; ?> class="btn-secondary disabled:opacity-50 disabled:cursor-not-allowed">
                <i class="fas fa-chevron-left mr-1"></i> Prev
            </button>
            <button onclick="loadUserTable(<?php echo $page + 1; ?>)" <?php echo $page >= $totalPages ? 'disabled' : ''; ?> class="btn-secondary disabled:opacity-50 disabled:cursor-not-allowed">
                Next <i class="fas fa-chevron-right ml-1"></i>
            </button>
        </div>
    </div>
    <?php
    $paginationHTML = ob_get_clean();

    return[
        'tableHTML' => $tableHTML,
        'paginationHTML' => $paginationHTML,
        'totalUsers' => $totalUsers,
        'currentPage' => $page
    ];
}

$pageLimit = 25;
$currentPage = (isset($_POST['page']) && is_numeric($_POST['page']) && $_POST['page'] > 0) ? (int)$_POST['page'] : 1;

// --- AJAX Handlers ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    
    if ($isAjax) {
        header('Content-Type: application/json');
        $response =['success' => false, 'message' => '', 'data' => null];
        
        try {
            if (!isset($_POST['action'])) throw new Exception('No action specified');
            $currentPage = (isset($_POST['page']) && is_numeric($_POST['page'])) ? (int)$_POST['page'] : 1;
            
            switch ($_POST['action']) {
                case 'get_user':
                    $user_id = $_POST['user_id'] ?? '';
                    if (empty($user_id)) { $response['message'] = 'User ID required.'; break; }
                    
                    $stmt = $pdo->prepare("SELECT u.*, 
                            z.name as zone_name, 
                            a.name as area_name, 
                            b.name as branch_name,
                            (SELECT name FROM users zm_user WHERE zm_user.zone_id = u.zone_id AND zm_user.role IN ('zm', 'dzm') AND zm_user.status = 'active' LIMIT 1) as zm_name,
                            (SELECT name FROM users am_user WHERE am_user.area_id = u.area_id AND am_user.role = 'am' AND am_user.status = 'active' LIMIT 1) as am_name,
                            (SELECT COUNT(*) FROM user_passkeys up WHERE up.user_id = u.id) as passkey_count
                            FROM users u
                            LEFT JOIN zones z ON u.zone_id = z.id
                            LEFT JOIN areas a ON u.area_id = a.id
                            LEFT JOIN branches b ON u.branch_id = b.id
                            WHERE u.id = ?");
                    $stmt->execute([$user_id]);
                    $found_user = $stmt->fetch();
                    
                    if ($found_user) {
                        unset($found_user['password']);
                        $response['success'] = true;
                        $response['data'] = $found_user;
                    } else {
                        $response['message'] = 'User not found.';
                    }
                    break;
                    
                case 'save_user':
                    $name = trim($_POST['name'] ?? '');
                    $username = trim($_POST['username'] ?? '');
                    $role = strtolower(trim($_POST['role'] ?? ''));
                    
                    if (empty($name) || empty($username) || empty($role)) {
                        $response['message'] = 'Name, username, and role are required.';
                        echo json_encode($response); exit();
                    }

                    if ($role === 'admin') {
                        $response['message'] = 'Access denied. You cannot assign the Administrator role.';
                        echo json_encode($response); exit();
                    }
                    if (!in_array($_SESSION['user_role'], ['admin', 'tm'])) {
                        $response['message'] = 'Access denied. You do not have permission to add staff.';
                        echo json_encode($response); exit();
                    }
                    
                    // Always block user duplicates
                    $checkStmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
                    $checkStmt->execute([$username]);
                    if ($checkStmt->fetch()) {
                        $response['message'] = 'Username already exists.';
                        echo json_encode($response); exit();
                    }
                    
                    $needsZone = in_array($role,['zm', 'dzm', 'am', 'bm', 'co']);
                    $needsArea = in_array($role,['am', 'bm', 'co']);
                    $needsBranch = in_array($role, ['bm', 'co']);
                    
                    $zone_id = $needsZone && !empty($_POST['zone_id']) ? $_POST['zone_id'] : null;
                    $area_id = $needsArea && !empty($_POST['area_id']) ? $_POST['area_id'] : null;
                    $branch_id = $needsBranch && !empty($_POST['branch_id']) ? $_POST['branch_id'] : null;
                    $is_weekly = (isset($_POST['is_weekly']) && $_POST['is_weekly'] == '1') ? 1 : 0;

                    // Strictly enforce INSERT ONLY as requested
                    $sql = "INSERT INTO users (name, full_name, username, role, zone_id, area_id, branch_id, is_weekly, password, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')";
                    $password = !empty($_POST['password']) ? password_hash($_POST['password'], PASSWORD_DEFAULT) : password_hash('123456', PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([$name, $name, $username, $role, $zone_id, $area_id, $branch_id, $is_weekly, $password]);

                    $tableData = generateUserTableHTML($pdo, $currentPage, $pageLimit, $_POST['search'] ?? '');
                    $response['table_html'] = $tableData['tableHTML'];
                    $response['pagination_html'] = $tableData['paginationHTML'];
                    $response['total_users'] = $tableData['totalUsers'];
                    $response['success'] = true;
                    $response['message'] = 'Staff account added and assigned successfully.';
                    break;
                    
                case 'load_table':
                    $tableData = generateUserTableHTML($pdo, $currentPage, $pageLimit, $_POST['search'] ?? '');
                    $response['success'] = true;
                    $response['table_html'] = $tableData['tableHTML'];
                    $response['pagination_html'] = $tableData['paginationHTML'];
                    $response['total_users'] = $tableData['totalUsers'];
                    $response['current_page'] = $tableData['currentPage'];
                    break;
                    
                case 'get_areas':
                    $zone_id = $_POST['zone_id'] ?? '';
                    if (empty($zone_id)) { $response['success'] = true; $response['data'] =[]; }
                    else {
                        $stmt = $pdo->prepare("SELECT id, name FROM areas WHERE zone_id = ? ORDER BY name ASC");
                        $stmt->execute([$zone_id]);
                        $response['success'] = true;
                        $response['data'] = $stmt->fetchAll();
                    }
                    break;
                    
                case 'get_branches':
                    $area_id = $_POST['area_id'] ?? '';
                    if (empty($area_id)) { $response['success'] = true; $response['data'] =[]; }
                    else {
                        $stmt = $pdo->prepare("SELECT id, name FROM branches WHERE area_id = ? ORDER BY name ASC");
                        $stmt->execute([$area_id]);
                        $response['success'] = true;
                        $response['data'] = $stmt->fetchAll();
                    }
                    break;
                    
                default:
                    throw new Exception('Unknown action');
            }
        } catch (Exception $e) { 
            $response['message'] = 'Error: ' . $e->getMessage();
        }
        echo json_encode($response);
        exit();
    }
}

$initialTableData = generateUserTableHTML($pdo, $currentPage, $pageLimit, '');
$currentPage = $initialTableData['currentPage'];

$zonesStmt = $pdo->query("SELECT id, name FROM zones ORDER BY name ASC");
$zonesList = $zonesStmt->fetchAll();

// Determine base dashboard URL for returning home
$dashboard_url =['admin' => '../admin/dashboard.php', 'zm' => '../zm/dashboard.php', 'am' => '../am/dashboard.php'][$viewer_role] ?? 'dashboard.php';
?>
<!DOCTYPE html>
<html lang="en" class="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>Staff Directory | TM Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />
    <script src="https://cdn.tailwindcss.com"></script>

    <script>
    tailwind.config = {
        darkMode: 'class',
        theme: {
            extend: {
                colors: {
                    primary: '#3b82f6',
                    secondary: '#8b5cf6',
                    success: '#22c55e',
                    warning: '#f59e0b',
                    error: '#ef4444',
                    bg: { light: '#f0f2f5', dark: '#0f172a' }
                },
                fontFamily: {
                    sans: ['Inter', 'sans-serif'],
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

        body { font-family: 'Inter', sans-serif; background-color: var(--bg-primary); color: var(--text-primary); padding-bottom: 90px; -webkit-tap-highlight-color: transparent; transition: background-color 0.2s, color 0.2s; }
        
        /* Layout & Shared Classes */
        .main-header { background: var(--bg-header); backdrop-filter: blur(12px); border-bottom: 1px solid var(--border-color); position: sticky; top: 0; z-index: 50; padding-top: env(safe-area-inset-top); transition: background-color 0.2s, border-color 0.2s; }
        .navbar { display: flex; justify-content: space-between; align-items: center; padding: 0.8rem 1rem; max-width: 1280px; margin: 0 auto; }
        .logo { font-weight: 700; font-size: 1.25rem; color: var(--primary-color); display: flex; align-items: center; gap: 0.5rem; text-decoration: none; }
        .logo img { height: 32px; width: auto; }
        
        .profile-btn { width: 36px; height: 36px; border-radius: 50%; overflow: hidden; border: 2px solid var(--primary-color); background: var(--bg-secondary); cursor: pointer; display: flex; align-items: center; justify-content: center; position: relative; }
        .profile-btn img { width: 100%; height: 100%; object-fit: cover; }
        .profile-fallback-icon { font-size: 1.2rem; color: var(--text-secondary); }

        .content-card { background: var(--bg-card); border-radius: var(--border-radius-xl); box-shadow: var(--shadow-sm); border: 1px solid var(--border-color); display: flex; flex-direction: column; min-h: 500px; transition: background-color 0.2s, border-color 0.2s; position: relative; }
        
        .search-input { width: 100%; padding: 0.75rem 1rem 0.75rem 2.5rem; border: 1px solid var(--border-color); border-radius: 0.75rem; background: var(--bg-primary); color: var(--text-primary); font-size: 14px; outline: none; transition: border-color 0.2s, box-shadow 0.2s; }
        .search-input:focus { border-color: var(--primary-color); box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1); }
        .form-input { width: 100%; padding: 0.75rem 1rem; border: 1px solid var(--border-color); border-radius: 0.75rem; background: var(--bg-primary); color: var(--text-primary); font-size: 14px; outline: none; transition: border-color 0.2s, box-shadow 0.2s; }
        .form-input:focus { border-color: var(--primary-color); box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1); }

        .btn-primary { background: var(--primary-color); color: white; border-radius: 0.75rem; padding: 0.6rem 1.25rem; font-weight: 600; font-size: 0.9rem; transition: transform 0.2s, background 0.2s, box-shadow 0.2s; }
        .btn-primary:active { transform: scale(0.95); }
        .btn-primary:hover { background: #2563eb; box-shadow: 0 4px 12px rgba(37, 99, 235, 0.2); }
        .btn-secondary { background: var(--bg-primary); border: 1px solid var(--border-color); color: var(--text-primary); border-radius: 0.75rem; padding: 0.5rem 1rem; font-weight: 600; font-size: 0.85rem; transition: background 0.2s; }
        .btn-secondary:hover:not(:disabled) { background: var(--bg-secondary); }

        /* Table Specific Styling */
        .table-header { background: var(--bg-secondary); border-bottom: 1px solid var(--border-color); color: var(--text-secondary); }
        .table-row { border-bottom: 1px solid var(--border-color); transition: background 0.2s; }
        .table-row:hover { background: var(--bg-primary); }
        .table-cell { color: var(--text-primary); }
        .table-cell-muted { color: var(--text-secondary); }

        /* Modals & Animations */
        .modal-overlay { transition: opacity 0.2s ease-out; }
        .modal-content { transition: transform 0.3s cubic-bezier(0.16, 1, 0.3, 1); background: var(--bg-card); border: 1px solid var(--border-color); }
        .modal-header { background: var(--bg-secondary); border-bottom: 1px solid var(--border-color); }
        .modal-footer { background: var(--bg-secondary); border-top: 1px solid var(--border-color); }
        .modal-hidden { opacity: 0; pointer-events: none; }
        .modal-hidden .modal-content { transform: scale(0.95) translateY(10px); }

        .toast-slide-in { animation: slideIn 0.3s cubic-bezier(0.16, 1, 0.3, 1) forwards; }
        @keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }

        /* Smooth Scrollbar */
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background-color: rgba(156, 163, 175, 0.5); border-radius: 20px; }
        html.dark ::-webkit-scrollbar-thumb { background-color: rgba(75, 85, 99, 0.5); }

        /* TM Mobile Bottom Nav */
        .mobile-bottom-nav { display: none; position: fixed; bottom: 0; left: 0; right: 0; background: var(--bg-secondary); border-top: 1px solid var(--border-color); padding: 0.5rem 1rem calc(0.5rem + env(safe-area-inset-bottom)); z-index: 100; justify-content: space-between; box-shadow: 0 -4px 10px rgba(0,0,0,0.05); transition: background-color 0.2s, border-color 0.2s; }
        .nav-item { display: flex; flex-direction: column; align-items: center; justify-content: center; text-decoration: none; color: var(--text-secondary); font-size: 0.7rem; font-weight: 500; gap: 4px; flex: 1; transition: color 0.2s; }
        .nav-item i { font-size: 1.4rem; }
        .nav-item.active { color: var(--primary-color); }
        
        @media (max-width: 768px) {
            .mobile-bottom-nav { display: flex; }
        }

        #loadingOverlay {
            backdrop-filter: blur(2px);
            transition: opacity 0.3s ease;
        }
    </style>
</head>

<body>

    <!-- HEADER (Identical to Dashboard) -->
    <header class="main-header">
        <nav class="navbar">
            <a href="<?php echo $dashboard_url; ?>" class="logo">
                <img src="<?php echo htmlspecialchars($base_path . 'favicon.ico'); ?>" alt="Logo">
                <span>CUPAD TM</span>
            </a>
            
            <div class="flex items-center gap-3">
                <button id="theme-toggle" class="p-2 text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800 rounded-full transition">
                    <i class="fas fa-moon dark:hidden"></i><i class="fas fa-sun hidden dark:inline"></i>
                </button>
                
                <!-- Notification Bell (Links to Dashboard) -->
                <button onclick="window.location.href='<?php echo $dashboard_url; ?>'" class="p-2 text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800 rounded-full transition relative" title="Notifications">
                    <i class="fas fa-bell text-lg"></i>
                </button>

                <div class="profile-btn" onclick="window.location.href='profile.php'" title="My Profile">
                    <i class="fas fa-user profile-fallback-icon"></i>
                    <?php if ($has_profile_pic): ?>
                        <img src="<?php echo $base_path . $profile_pic_path; ?>" alt="Profile" class="absolute inset-0" onerror="this.style.display='none'"> 
                    <?php endif; ?>
                </div>

                <a href="<?php echo htmlspecialchars($base_path . 'logout.php'); ?>" class="p-2 text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-full transition" title="Logout">
                    <i class="fas fa-sign-out-alt text-lg"></i>
                </a>
            </div>
        </nav>
    </header>

    <main class="max-w-7xl mx-auto px-4 py-6">
        
        <!-- Page Header -->
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
            <div class="flex items-center gap-3">
                <div class="w-12 h-12 rounded-xl bg-[color:var(--bg-card)] border border-[color:var(--border-color)] flex items-center justify-center text-blue-600 dark:text-blue-500 shadow-sm">
                    <i class="fas fa-user-tie text-xl"></i>
                </div>
                <div>
                    <h1 class="text-2xl font-extrabold table-cell">Staff Directory</h1>
                    <p class="text-sm table-cell-muted">Manage system personnel and operational hierarchies</p>
                </div>
            </div>
            <?php if ($can_add_staff): ?>
            <button onclick="openFormModal()" class="btn-primary flex items-center justify-center gap-2">
                <i class="fas fa-user-plus"></i> Add Staff & Assign
            </button>
            <?php endif; ?>
        </div>

        <!-- Data Card -->
        <div class="content-card">
            
            <!-- Table Header Controls -->
            <div class="p-5 border-b border-[color:var(--border-color)] flex flex-col sm:flex-row justify-between items-center gap-4">
                <div>
                    <h2 class="text-lg font-bold table-cell">Active Personnel</h2>
                    <p class="text-sm table-cell-muted">Staff Count: <span id="totalUsersCount" class="font-bold table-cell"><?php echo $initialTableData['totalUsers']; ?></span></p>
                </div>
                
                <div class="flex items-center gap-3 w-full sm:w-auto">
                    <div class="relative w-full sm:w-64">
                        <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 table-cell-muted"></i>
                        <input type="text" id="userSearchInput" onkeyup="handleSearch(event)" placeholder="Search names, roles, branches..." class="search-input">
                    </div>
                    <button onclick="loadUserTable(currentPage, true)" id="refresh-activities" class="w-10 h-10 rounded-xl border border-[color:var(--border-color)] bg-[color:var(--bg-primary)] hover:bg-[color:var(--bg-secondary)] table-cell-muted hover:text-blue-500 flex items-center justify-center transition flex-shrink-0" title="Refresh List">
                        <i class="fas fa-sync-alt"></i>
                    </button>
                </div>
            </div>

            <!-- Loading Overlay -->
            <div id="loadingOverlay" class="absolute inset-0 top-[80px] bg-[color:var(--bg-card)]/60 z-10 flex flex-col items-center justify-center hidden rounded-b-xl">
                <div class="animate-spin rounded-full h-10 w-10 border-4 border-blue-500/30 border-t-blue-500 mb-3"></div>
                <span class="text-sm font-medium table-cell-muted">Loading directory...</span>
            </div>

            <!-- Table Wrapper -->
            <div id="userTableWrapper" class="flex-1 relative">
                <?php echo $initialTableData['tableHTML']; ?>
            </div>

            <!-- Pagination -->
            <div id="paginationControls">
                <?php echo $initialTableData['paginationHTML']; ?>
            </div>
        </div>
    </main>

    <!-- Mobile Bottom Navigation -->
    <nav class="mobile-bottom-nav">
        <a href="<?php echo $dashboard_url; ?>" class="nav-item">
            <i class="fas fa-home"></i>
            <span>Home</span>
        </a>
        <a href="zones.php" class="nav-item">
            <i class="fas fa-layer-group"></i>
            <span>Zones</span>
        </a>
        <a href="users.php" class="nav-item active">
             <div style="background: var(--primary-color); width: 45px; height: 45px; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; margin-top: -25px; box-shadow: 0 4px 10px rgba(59, 130, 246, 0.4);">
                <i class="fas fa-user-tie" style="font-size: 1.2rem; margin:0;"></i>
             </div>
             <span style="font-size:0.65rem; font-weight:600; margin-top:2px;">Staff</span>
        </a>
        <a href="analytics.php" class="nav-item">
            <i class="fas fa-chart-bar"></i>
            <span>Reports</span>
        </a>
    </nav>

    <!-- Staff Add & Assign Modal -->
    <div id="staffModal" class="modal-hidden fixed inset-0 z-[100] flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm">
        <div class="modal-content w-full max-w-md rounded-2xl shadow-2xl overflow-hidden flex flex-col max-h-[90vh]">
            
            <div class="p-5 modal-header flex justify-between items-center">
                <h3 class="text-xl font-bold table-cell">Add & Assign Staff</h3>
                <button onclick="closeFormModal()" class="w-8 h-8 rounded-full flex items-center justify-center table-cell-muted hover:bg-[color:var(--bg-primary)] transition">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <div class="p-5 overflow-y-auto custom-scrollbar flex-1 relative">
                <form id="staffForm" class="space-y-4">
                    <div>
                        <label class="block text-sm font-semibold table-cell mb-1.5">Full Name</label>
                        <input type="text" id="formName" name="name" required class="form-input" placeholder="e.g. John Doe">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-semibold table-cell mb-1.5">Username</label>
                        <input type="text" id="formUsername" name="username" required class="form-input" placeholder="e.g. johndoe">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-semibold table-cell mb-1.5">Password</label>
                        <input type="password" id="formPassword" name="password" placeholder="Leave blank for default" class="form-input">
                        <p class="text-xs table-cell-muted mt-1.5"><i class="fas fa-info-circle"></i> Default password is '123456'.</p>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold table-cell mb-1.5">System Role</label>
                        <select id="formRole" name="role" required onchange="handleRoleToggle()" class="form-input">
                            <option value="">Select Role...</option>
                            <option value="tm">Top Management (TM)</option>
                            <option value="zm">Zone Manager (ZM)</option>
                            <option value="dzm">Deputy Zone Manager (DZM)</option>
                            <option value="am">Area Manager (AM)</option>
                            <option value="bm">Branch Manager (BM)</option>
                            <option value="co">Credit Officer (CO)</option>
                        </select>
                    </div>

                    <!-- CO Settings -->
                    <div id="coSettings" class="hidden p-3.5 bg-indigo-50 dark:bg-indigo-900/20 border border-indigo-200 dark:border-indigo-800 rounded-xl transition-all">
                        <label class="flex items-center gap-3 cursor-pointer">
                            <input type="checkbox" id="formIsWeekly" name="is_weekly" value="1" class="w-5 h-5 rounded text-indigo-600 focus:ring-indigo-500 border-indigo-300 bg-white dark:bg-gray-800 dark:border-gray-600">
                            <span class="text-sm font-semibold text-indigo-900 dark:text-indigo-300">Is Weekly Credit Officer?</span>
                        </label>
                    </div>

                    <!-- Hierarchy Assignment -->
                    <div id="hierarchySection" class="hidden pt-4 mt-2 border-t border-[color:var(--border-color)] space-y-4">
                        <h4 class="text-xs font-bold uppercase tracking-wider table-cell-muted">Location Assignment</h4>
                        
                        <div id="groupZone" class="hidden">
                            <label class="block text-sm font-semibold table-cell mb-1.5">Zone <span class="text-red-500">*</span></label>
                            <select id="formZone" name="zone_id" onchange="handleZoneChange()" class="form-input">
                                <option value="">Select Zone</option>
                                <?php foreach ($zonesList as $zone): ?>
                                    <option value="<?php echo htmlspecialchars($zone['id']); ?>"><?php echo htmlspecialchars($zone['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div id="groupArea" class="hidden">
                            <label class="block text-sm font-semibold table-cell mb-1.5">Area <span class="text-red-500">*</span></label>
                            <select id="formArea" name="area_id" onchange="handleAreaChange()" class="form-input">
                                <option value="">Select Area</option>
                            </select>
                        </div>
                        
                        <div id="groupBranch" class="hidden">
                            <label class="block text-sm font-semibold table-cell mb-1.5">Branch <span class="text-red-500">*</span></label>
                            <select id="formBranch" name="branch_id" class="form-input">
                                <option value="">Select Branch</option>
                            </select>
                        </div>
                    </div>
                </form>
            </div>
            
            <div class="p-5 modal-footer">
                <button type="submit" form="staffForm" class="w-full btn-primary flex justify-center items-center gap-2">
                    <i class="fas fa-save"></i> Save Staff Member
                </button>
            </div>
        </div>
    </div>

    <!-- Read-Only View Detail Modal -->
    <div id="viewDetailModal" class="modal-hidden fixed inset-0 z-[100] flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm">
        <div class="modal-content w-full max-w-sm rounded-2xl shadow-2xl overflow-hidden">
            <div class="p-5 modal-header flex justify-between items-center">
                <h3 class="text-lg font-bold table-cell">Staff Profile Overview</h3>
                <button onclick="closeDetailModal()" class="w-8 h-8 rounded-full flex items-center justify-center table-cell-muted hover:bg-[color:var(--bg-primary)] transition">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="p-6 space-y-4">
                <div class="flex flex-col items-center pb-4 border-b border-[color:var(--border-color)]">
                    <div id="viewAvatarContainer" class="mb-3">
                        <img id="viewAvatar" src="" class="w-20 h-20 rounded-full shadow-md border-4 border-[color:var(--bg-secondary)]" alt="Avatar">
                    </div>
                    <h4 id="viewName" class="text-xl font-bold table-cell">...</h4>
                    <span id="viewUsername" class="text-sm font-mono table-cell-muted mt-1">@username</span>
                    <span id="viewRoleBadge" class="mt-3 px-3 py-1 rounded-md text-xs font-bold uppercase tracking-wider bg-[color:var(--bg-primary)] table-cell border border-[color:var(--border-color)]">ROLE</span>
                </div>
                
                <div class="space-y-4 text-sm">
                    <div class="flex justify-between items-start gap-4">
                        <span class="table-cell-muted font-medium shrink-0">Assignment:</span>
                        <span id="viewAssignment" class="text-right table-cell">...</span>
                    </div>
                    <div class="flex justify-between items-start gap-4">
                        <span class="table-cell-muted font-medium shrink-0">Line Managers:</span>
                        <div id="viewLineManagers" class="text-right table-cell flex flex-col gap-1">...</div>
                    </div>
                    <div class="flex justify-between items-start gap-4">
                        <span class="table-cell-muted font-medium shrink-0">Biometrics:</span>
                        <span id="viewBiometrics" class="text-right table-cell font-semibold">...</span>
                    </div>
                    <div class="flex justify-between items-start gap-4">
                        <span class="table-cell-muted font-medium shrink-0">Account Status:</span>
                        <span id="viewStatus" class="text-right font-bold text-green-500">Active</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Toast Container -->
    <div id="toastContainer" class="fixed top-[80px] right-4 z-[200] flex flex-col gap-2"></div>

    <script>
        const $ = (id) => document.getElementById(id);
        const CURRENT_URL = window.location.href.split('?')[0]; 
        
        // --- Theme ---
        const themeBtn = $('theme-toggle');
        const html = document.documentElement;
        if(localStorage.getItem('theme') === 'dark') { html.classList.add('dark'); themeBtn.innerHTML = '<i class="fas fa-sun"></i>'; }
        else { html.classList.remove('dark'); themeBtn.innerHTML = '<i class="fas fa-moon"></i>'; }

        themeBtn.addEventListener('click', () => {
            const isDark = html.classList.toggle('dark');
            localStorage.setItem('theme', isDark ? 'dark' : 'light');
            themeBtn.innerHTML = isDark ? '<i class="fas fa-sun"></i>' : '<i class="fas fa-moon"></i>';
        });

        // --- Data Variables ---
        let currentPage = <?php echo $currentPage; ?>;
        let searchTimeout;

        function showToast(msg, type = 'success') {
            const toast = document.createElement('div');
            const icon = type === 'success' ? '<i class="fas fa-check-circle text-green-500"></i>' : '<i class="fas fa-exclamation-circle text-red-500"></i>';
            const border = type === 'success' ? 'border-green-500' : 'border-red-500';
            
            toast.className = `toast-slide-in flex items-center gap-3 px-4 py-3 bg-[color:var(--bg-card)] border-l-4 ${border} shadow-lg rounded-r-lg text-sm font-medium table-cell min-w-[250px]`;
            toast.innerHTML = `${icon} <span>${msg}</span>`;
            
            $('toastContainer').appendChild(toast);
            setTimeout(() => {
                toast.style.opacity = '0';
                toast.style.transform = 'translateX(100%)';
                toast.style.transition = 'all 0.3s ease';
                setTimeout(() => toast.remove(), 300);
            }, 3500);
        }

        // --- Table Actions ---
        function handleSearch(e) {
            clearTimeout(searchTimeout);
            // Show loading if searching
            $('loadingOverlay').classList.remove('hidden');
            searchTimeout = setTimeout(() => loadUserTable(1, false, e.target.value), 400);
        }

        async function loadUserTable(page = 1, isRefresh = false, searchTerm = null) {
            if(searchTerm === null) searchTerm = $('userSearchInput').value;
            page = Math.max(1, page);
            
            $('loadingOverlay').classList.remove('hidden');

            try {
                const fd = new FormData();
                fd.append('action', 'load_table');
                fd.append('page', page);
                fd.append('search', searchTerm);

                const res = await fetch(CURRENT_URL, { method: 'POST', body: fd, headers: {'X-Requested-With': 'XMLHttpRequest'} });
                const data = await res.json();

                if(data.success) {
                    currentPage = data.current_page;
                    $('userTableWrapper').innerHTML = data.table_html;
                    $('paginationControls').innerHTML = data.pagination_html;
                    $('totalUsersCount').textContent = data.total_users;
                    if(isRefresh) showToast('Directory refreshed');
                } else throw new Error(data.message);
            } catch(e) {
                showToast(e.message, 'error');
            } finally {
                $('loadingOverlay').classList.add('hidden');
            }
        }

        // --- Read-Only Profile View ---
        async function viewUserDetail(id) {
            try {
                const fd = new FormData();
                fd.append('action', 'get_user');
                fd.append('user_id', id);
                
                const res = await fetch(CURRENT_URL, { method: 'POST', body: fd, headers: {'X-Requested-With': 'XMLHttpRequest'} });
                const json = await res.json();
                
                if (json.success && json.data) {
                    const u = json.data;
                    
                    const actualName = u.name || u.full_name || 'User';
                    $('viewName').textContent = actualName;
                    $('viewUsername').textContent = '@' + u.username;
                    $('viewAvatar').src = `https://ui-avatars.com/api/?name=${encodeURIComponent(actualName)}&background=random&color=fff&rounded=true&size=128`;
                    
                    // Style Role Badge
                    const badge = $('viewRoleBadge');
                    badge.textContent = u.role.toUpperCase() === 'CO' ? 'CREDIT OFFICER' : (u.role.toUpperCase() === 'TM' ? 'TOP MGT' : u.role.toUpperCase());
                    badge.className = "mt-3 px-3 py-1 rounded-md text-xs font-bold uppercase tracking-wider border";
                    
                    if (u.role === 'tm') badge.classList.add('bg-purple-100', 'text-purple-800', 'dark:bg-purple-900/30', 'dark:text-purple-400', 'border-purple-200', 'dark:border-purple-800');
                    else if (['zm', 'dzm'].includes(u.role)) badge.classList.add('bg-emerald-100', 'text-emerald-800', 'dark:bg-emerald-900/30', 'dark:text-emerald-400', 'border-emerald-200', 'dark:border-emerald-800');
                    else if (u.role === 'am') badge.classList.add('bg-orange-100', 'text-orange-800', 'dark:bg-orange-900/30', 'dark:text-orange-400', 'border-orange-200', 'dark:border-orange-800');
                    else if (u.role === 'bm') badge.classList.add('bg-blue-100', 'text-blue-800', 'dark:bg-blue-900/30', 'dark:text-blue-400', 'border-blue-200', 'dark:border-blue-800');
                    else if (u.role === 'co') badge.classList.add('bg-indigo-100', 'text-indigo-800', 'dark:bg-indigo-900/30', 'dark:text-indigo-400', 'border-indigo-200', 'dark:border-indigo-800');
                    else badge.classList.add('bg-[color:var(--bg-primary)]', 'table-cell', 'border-[color:var(--border-color)]');

                    // Set Assignments
                    let assignment =[];
                    if (u.zone_name) assignment.push(u.zone_name);
                    if (u.area_name) assignment.push(u.area_name);
                    if (u.branch_name) assignment.push(u.branch_name);
                    $('viewAssignment').innerHTML = assignment.length ? assignment.join(' <br><i class="fas fa-arrow-down text-[10px] text-gray-300 dark:text-gray-600 my-1"></i><br> ') : '<span class="italic table-cell-muted">Headquarters</span>';
                    
                    // Set Hierarchy
                    let hierarchy =[];
                    if (u.am_name) hierarchy.push(`<span><span class="text-xs table-cell-muted uppercase mr-1">AM:</span> ${u.am_name}</span>`);
                    if (u.zm_name) hierarchy.push(`<span><span class="text-xs table-cell-muted uppercase mr-1">ZM:</span> ${u.zm_name}</span>`);
                    $('viewLineManagers').innerHTML = hierarchy.length ? hierarchy.join('') : '<span class="table-cell-muted">Headquarters Directors</span>';
                    
                    // Set Biometric Info
                    $('viewBiometrics').innerHTML = u.passkey_count > 0 
                        ? `<span class="text-blue-500"><i class="fas fa-fingerprint mr-1"></i> Enrolled (${u.passkey_count})</span>` 
                        : '<span class="table-cell-muted">Password Only</span>';
                        
                    $('viewStatus').textContent = u.status ? u.status.charAt(0).toUpperCase() + u.status.slice(1) : 'Active';
                    $('viewStatus').className = u.status === 'active' ? "text-right font-bold text-green-500" : "text-right font-bold text-red-500";
                    
                    $('viewDetailModal').classList.remove('modal-hidden');
                } else throw new Error(json.message);
            } catch (e) {
                showToast(e.message, 'error');
            }
        }

        function closeDetailModal() {
            $('viewDetailModal').classList.add('modal-hidden');
        }

        // --- Add Form Modal Logic ---
        function resetForm() {
            $('staffForm').reset();
            $('formArea').innerHTML = '<option value="">Select Area</option>';
            $('formBranch').innerHTML = '<option value="">Select Branch</option>';
            handleRoleToggle(true);
        }

        function openFormModal() {
            resetForm();
            $('staffModal').classList.remove('modal-hidden');
        }

        function closeFormModal() {
            $('staffModal').classList.add('modal-hidden');
        }

        function handleRoleToggle(isInit = false) {
            const role = $('formRole').value;
            const nz =['zm','dzm','am','bm','co'].includes(role);
            const na = ['am','bm','co'].includes(role);
            const nb = ['bm','co'].includes(role);
            
            $('hierarchySection').classList.toggle('hidden', !nz);
            $('groupZone').classList.toggle('hidden', !nz);
            $('groupArea').classList.toggle('hidden', !na);
            $('groupBranch').classList.toggle('hidden', !nb);
            
            $('coSettings').classList.toggle('hidden', role !== 'co');

            if(!isInit) {
                $('formZone').value = ''; 
                $('formArea').innerHTML = '<option value="">Select Area</option>'; 
                $('formBranch').innerHTML = '<option value="">Select Branch</option>';
            }
        }

        // --- Dropdown Cascade AJAX calls ---
        async function handleZoneChange() {
            $('formArea').innerHTML = '<option value="">Loading Areas...</option>';
            $('formBranch').innerHTML = '<option value="">Select Branch</option>';
            await loadAreasList();
        }

        async function handleAreaChange() {
            $('formBranch').innerHTML = '<option value="">Loading Branches...</option>';
            await loadBranchesList();
        }

        async function loadAreasList() {
            const zid = $('formZone').value;
            if(!zid) {
                $('formArea').innerHTML = '<option value="">Select Area</option>';
                return;
            }
            try {
                const fd = new FormData(); 
                fd.append('action', 'get_areas'); 
                fd.append('zone_id', zid);
                
                const res = await fetch(CURRENT_URL, { method: 'POST', body: fd, headers: {'X-Requested-With': 'XMLHttpRequest'} });
                const json = await res.json();
                
                if(json.success) {
                    let h = '<option value="">Select Area</option>';
                    json.data.forEach(a => h += `<option value="${a.id}">${a.name}</option>`);
                    $('formArea').innerHTML = h;
                }
            } catch(e) {
                showToast('Failed to load Areas.', 'error');
            }
        }

        async function loadBranchesList() {
            const aid = $('formArea').value;
            if(!aid) {
                $('formBranch').innerHTML = '<option value="">Select Branch</option>';
                return;
            }
            try {
                const fd = new FormData(); 
                fd.append('action', 'get_branches'); 
                fd.append('area_id', aid);
                
                const res = await fetch(CURRENT_URL, { method: 'POST', body: fd, headers: {'X-Requested-With': 'XMLHttpRequest'} });
                const json = await res.json();
                
                if(json.success) {
                    let h = '<option value="">Select Branch</option>';
                    json.data.forEach(b => h += `<option value="${b.id}">${b.name}</option>`);
                    $('formBranch').innerHTML = h;
                }
            } catch(e) {
                showToast('Failed to load Branches.', 'error');
            }
        }

        // --- Save Staff via AJAX ---
        $('staffForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            const btn = this.querySelector('button[type="submit"]');
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...'; btn.disabled = true;
            
            try {
                const fd = new FormData(this);
                fd.append('action', 'save_user');
                const res = await fetch(CURRENT_URL, { method: 'POST', body: fd, headers: {'X-Requested-With': 'XMLHttpRequest'} });
                const json = await res.json();
                
                if(json.success) {
                    showToast('Staff member successfully added');
                    closeFormModal();
                    loadUserTable(1, true); // Reload to page 1 to see new staff
                } else throw new Error(json.message);
            } catch(e) { 
                showToast(e.message, 'error'); 
            } finally { 
                btn.innerHTML = '<i class="fas fa-save"></i> Save Staff Member'; 
                btn.disabled = false; 
            }
        });
    </script>
</body>
</html>