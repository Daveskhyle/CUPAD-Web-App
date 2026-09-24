<?php
// manage_users.php
session_start();
require_once '../includes/config.php';
$pdo = getDbConnection();

// Security check: Only admins can access this page
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: ../index.php');
    exit();
}

// --- Configuration ---
$base_path = '../'; 

// --- Header Data Logic (Profile Pic) ---
$full_name = $_SESSION['full_name'] ?? 'Admin';
$username = $_SESSION['username'] ?? '';
$user_role = $_SESSION['user_role'] ?? 'admin';
$profile_pic_path = '';
$has_profile_pic = false;

try {
    $stmt = $pdo->prepare("SELECT profile_pic FROM users WHERE username = ? LIMIT 1");
    $stmt->execute([$username]);
    $currentUserRow = $stmt->fetch();

    if ($currentUserRow && !empty($currentUserRow['profile_pic'])) {
        $checkPath = __DIR__ . '/../uploads/' . $currentUserRow['profile_pic'];
        if (file_exists($checkPath)) {
             $profile_pic_path = '../uploads/' . $currentUserRow['profile_pic'];
             $has_profile_pic = true;
        } elseif (file_exists(__DIR__ . '/../' . $currentUserRow['profile_pic'])) {
             $profile_pic_path = '../' . $currentUserRow['profile_pic'];
             $has_profile_pic = true;
        }
    }
} catch (PDOException $e) {
    // Handle error silently for header
}

// --- Table Generator Function ---
function generateUserTableHTML($pdo, $page, $limit, $searchTerm = '') {
    $offset = ($page - 1) * $limit;
    
    $sql = "SELECT u.*, 
            z.name as zone_name, 
            a.name as area_name, 
            b.name as branch_name,
            (SELECT COUNT(*) FROM user_passkeys up WHERE up.user_id = u.id) as passkey_count
            FROM users u
            LEFT JOIN zones z ON u.zone_id = z.id
            LEFT JOIN areas a ON u.area_id = a.id
            LEFT JOIN branches b ON u.branch_id = b.id
            WHERE 1=1";
    
    $params = [];

    if (!empty($searchTerm)) {
        $term = "%$searchTerm%";
        $sql .= " AND (u.name LIKE ? OR u.username LIKE ? OR u.role LIKE ? OR z.name LIKE ? OR a.name LIKE ? OR b.name LIKE ?)";
        $params = array_merge($params, [$term, $term, $term, $term, $term, $term]);
    }

    $countSql = str_replace("u.*, \n            z.name as zone_name, \n            a.name as area_name, \n            b.name as branch_name,\n            (SELECT COUNT(*) FROM user_passkeys up WHERE up.user_id = u.id) as passkey_count", "COUNT(*)", $sql);
    $stmtCount = $pdo->prepare($countSql);
    $stmtCount->execute($params);
    $totalUsers = $stmtCount->fetchColumn();

    $sql .= " ORDER BY u.id DESC LIMIT $limit OFFSET $offset";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $users = $stmt->fetchAll();

    $totalPages = ceil($totalUsers / $limit);
    $page = max(1, min($page, $totalPages > 0 ? $totalPages : 1));

    ob_start();
    ?>
    <table class="table" id="userTable">
        <thead>
            <tr>
                <th>Name</th>
                <th>Username</th>
                <th>Role</th>
                <th>Assignment</th>
                <th>Passkeys</th>
                <th style="text-align: right;">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($users)): ?>
                <tr>
                    <td colspan="6" style="text-align: center; color: var(--text-muted); padding: 3rem;">
                        <div style="display: flex; flex-direction: column; align-items: center; gap: 0.5rem;">
                            <i class="fas fa-search" style="font-size: 2rem; opacity: 0.2;"></i>
                            <span>No users found.</span>
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
                $is_protected = ($username === 'Daveskhyle' || ($role_class === 'admin' && $username !== $_SESSION['username']));

                $assignment_parts = [];
                if (!empty($user['zone_name'])) {
                    $assignment_parts[] = '<span style="color:var(--success); font-weight:600;">' . htmlspecialchars($user['zone_name']) . '</span>';
                } elseif (!empty($user['zone_id'])) {
                    $assignment_parts[] = '<span style="color:var(--danger);">Zone: ' . htmlspecialchars($user['zone_id']) . '</span>';
                }
                
                if (!empty($user['area_name'])) {
                    $assignment_parts[] = htmlspecialchars($user['area_name']);
                } elseif (!empty($user['area_id'])) {
                    $assignment_parts[] = '<span style="color:var(--danger);">Area: ' . htmlspecialchars($user['area_id']) . '</span>';
                }
                
                if (!empty($user['branch_name'])) {
                    $assignment_parts[] = htmlspecialchars($user['branch_name']);
                } elseif (!empty($user['branch_id'])) {
                    $assignment_parts[] = '<span style="color:var(--danger);">Branch: ' . htmlspecialchars($user['branch_id']) . '</span>';
                }
                
                $assignment = implode(' <span style="color:var(--text-muted); font-size:0.7em;">❯</span> ', $assignment_parts);
                if (empty($assignment)) {
                    if (in_array($role, ['admin', 'tm'])) {
                        $assignment = '<span style="color:var(--text-muted); font-style:italic;">Headquarters</span>';
                    } else {
                        $assignment = '<span style="color:var(--danger); font-style:italic;">Not Assigned</span>';
                    }
                }

                $passDisplay = "Hash Protected"; 
                $roleLabel = strtoupper($role);
                if($role === 'co') $roleLabel = 'CREDIT OFFICER';
                if($role === 'tm') $roleLabel = 'TOP MGT';
                
                $passkey_count = $user['passkey_count'];
                ?>
                <tr data-user-id="<?php echo htmlspecialchars($user_id); ?>" 
                    data-username="<?php echo htmlspecialchars($username); ?>"
                    data-plain-password="<?php echo htmlspecialchars($passDisplay); ?>"
                    data-role="<?php echo htmlspecialchars($role_class); ?>"
                    class="user-row <?php echo $is_protected ? 'user-protected' : ''; ?>">
                    <td style="font-weight: 600; color:var(--text-main)">
                        <?php echo htmlspecialchars($user['name'] ?? $user['full_name'] ?? 'Unknown'); ?>
                    </td>
                    <td style="color: var(--text-muted); font-family: monospace; font-size: 0.85rem;">
                        @<?php echo htmlspecialchars($username); ?>
                    </td>
                    <td>
                        <span class="role-badge <?php echo htmlspecialchars($role_class); ?>">
                            <?php echo htmlspecialchars($roleLabel); ?>
                        </span>
                    </td>
                    <td class="assignment"><?php echo $assignment; ?></td>
                    <td>
                        <?php if ($passkey_count > 0): ?>
                            <span class="passkey-badge">
                                <i class="fas fa-key"></i> <?php echo $passkey_count; ?>
                            </span>
                            <?php if (!$is_protected): ?>
                                <button onclick="deletePasskeys('<?php echo htmlspecialchars($username); ?>', event)" class="action-btn danger" title="Delete All Passkeys" style="margin-left: 0.5rem;">
                                    <i class="fas fa-trash-alt"></i>
                                </button>
                            <?php endif; ?>
                        <?php else: ?>
                            <span style="color: var(--text-muted); font-size: 0.8rem;">None</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align: right;">
                        <div class="table-actions" style="justify-content: flex-end;">
                            <?php if (!$is_protected): ?>
                                <button onclick="editUser(<?php echo $user_id; ?>, event)" class="action-btn" title="Edit User">
                                    <i class="fas fa-pen"></i>
                                </button>
                                <button onclick="deleteUser('<?php echo htmlspecialchars($username); ?>', event)" class="action-btn danger" title="Delete User">
                                    <i class="fas fa-trash"></i>
                                </button>
                            <?php else: ?>
                                <span class="badge-protected"><i class="fas fa-lock"></i></span>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php
    $tableHTML = ob_get_clean();

    ob_start();
    ?>
    <div class="pagination-controls">
        <div style="color: var(--text-muted); font-size: 0.85rem;">
            Page <strong><?php echo $page; ?></strong> of <strong><?php echo $totalPages; ?></strong>
        </div>
        <div style="display: flex; gap: 0.5rem;">
            <button class="btn btn-sm" onclick="loadUserTable(<?php echo $page - 1; ?>)" <?php echo $page <= 1 ? 'disabled' : ''; ?>>
                <i class="fas fa-chevron-left"></i> Prev
            </button>
            <button class="btn btn-sm" onclick="loadUserTable(<?php echo $page + 1; ?>)" <?php echo $page >= $totalPages ? 'disabled' : ''; ?>>
                Next <i class="fas fa-chevron-right"></i>
            </button>
        </div>
    </div>
    <?php
    $paginationHTML = ob_get_clean();

    return [
        'tableHTML' => $tableHTML,
        'paginationHTML' => $paginationHTML,
        'totalUsers' => $totalUsers,
        'currentPage' => $page
    ];
}

$pageLimit = 10;
$currentPage = (isset($_POST['page']) && is_numeric($_POST['page']) && $_POST['page'] > 0) ? (int)$_POST['page'] : 1;

// --- AJAX Handler ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
              strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    
    if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Access denied']);
        exit();
    }
    
    if ($isAjax) {
        header('Content-Type: application/json');
        $response = ['success' => false, 'message' => '', 'data' => null];
        
        try {
            if (!isset($_POST['action'])) {
                throw new Exception('No action specified');
            }
            
            $currentPage = (isset($_POST['page']) && is_numeric($_POST['page'])) ? (int)$_POST['page'] : 1;
            
            switch ($_POST['action']) {
                case 'delete_user':
                    // CHANGED: Accept username instead of user_id
                    $username = $_POST['username'] ?? '';
                    if (empty($username)) {
                        $response['message'] = 'Username required.';
                        break;
                    }
                    
                    $stmt = $pdo->prepare("SELECT id, username, role FROM users WHERE username = ?");
                    $stmt->execute([$username]);
                    $targetUser = $stmt->fetch();

                    if (!$targetUser) {
                        $response['message'] = 'User not found.';
                    } elseif ($targetUser['username'] === 'Daveskhyle' || $targetUser['role'] === 'admin') {
                        $response['message'] = 'Cannot delete protected user.';
                    } else {
                        $delStmt = $pdo->prepare("DELETE FROM users WHERE username = ?");
                        if ($delStmt->execute([$username])) {
                            $tableData = generateUserTableHTML($pdo, $currentPage, $pageLimit, $_POST['search'] ?? '');
                            $response['table_html'] = $tableData['tableHTML'];
                            $response['pagination_html'] = $tableData['paginationHTML'];
                            $response['total_users'] = $tableData['totalUsers'];
                            $response['success'] = true;
                            $response['message'] = 'User deleted.';
                        } else {
                            $response['message'] = 'Deletion failed.';
                        }
                    }
                    break;
                    
                case 'get_user':
                    $user_id = $_POST['user_id'] ?? '';
                    if (empty($user_id)) {
                        $response['message'] = 'User ID required.';
                        break;
                    }
                    
                    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
                    $stmt->execute([$user_id]);
                    $found_user = $stmt->fetch();
                    
                    if ($found_user) {
                        unset($found_user['password']);
                        $found_user['weekly_interest_rate_pct'] = isset($found_user['weekly_interest_rate']) ? ($found_user['weekly_interest_rate'] * 100) : 0;
                        
                        $response['success'] = true;
                        $response['data'] = $found_user;
                    } else {
                        $response['message'] = 'User not found.';
                    }
                    break;
                    
                case 'save_user':
                    $user_id = $_POST['user_id'] ?? '';
                    $name = trim($_POST['name'] ?? '');
                    $username = trim($_POST['username'] ?? '');
                    $role = strtolower(trim($_POST['role'] ?? ''));
                    
                    if (empty($name) || empty($username) || empty($role)) {
                        $response['message'] = 'Name, username, and role are required.';
                        echo json_encode($response);
                        exit();
                    }
                    
                    // Check if editing existing user or creating new
                    $existingUser = null;
                    if (!empty($user_id)) {
                        $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ?");
                        $stmt->execute([$user_id]);
                        $existingUser = $stmt->fetch();
                    }
                    
                    // Validate Duplicate Username (exclude current user if editing)
                    $checkSql = "SELECT id FROM users WHERE username = ?";
                    $checkParams = [$username];
                    if ($existingUser) {
                        $checkSql .= " AND id != ?";
                        $checkParams[] = $user_id;
                    }
                    $checkStmt = $pdo->prepare($checkSql);
                    $checkStmt->execute($checkParams);
                    if ($checkStmt->fetch()) {
                        $response['message'] = 'Username already exists.';
                        echo json_encode($response);
                        exit();
                    }
                    
                    $needsZone = in_array($role, ['zm', 'dzm', 'am', 'bm', 'co']);
                    $needsArea = in_array($role, ['am', 'bm', 'co']);
                    $needsBranch = in_array($role, ['bm', 'co']);
                    
                    $zone_id = null;
                    $area_id = null;
                    $branch_id = null;
                    
                    if ($needsZone && !empty($_POST['zone_id'])) {
                        $zone_id = $_POST['zone_id'];
                        $zoneCheck = $pdo->prepare("SELECT id FROM zones WHERE id = ?");
                        $zoneCheck->execute([$zone_id]);
                        if (!$zoneCheck->fetch()) {
                            $response['message'] = 'Selected zone does not exist.';
                            echo json_encode($response);
                            exit();
                        }
                    }
                    
                    if ($needsArea && !empty($_POST['area_id'])) {
                        $area_id = $_POST['area_id'];
                        $areaCheck = $pdo->prepare("SELECT id FROM areas WHERE id = ? AND (zone_id = ? OR zone_id IS NULL)");
                        $areaCheck->execute([$area_id, $zone_id]);
                        if (!$areaCheck->fetch()) {
                            $response['message'] = 'Selected area does not exist or does not belong to the selected zone.';
                            echo json_encode($response);
                            exit();
                        }
                    }
                    
                    if ($needsBranch && !empty($_POST['branch_id'])) {
                        $branch_id = $_POST['branch_id'];
                        $branchCheck = $pdo->prepare("SELECT id FROM branches WHERE id = ? AND area_id = ? AND (zone_id = ? OR zone_id IS NULL)");
                        $branchCheck->execute([$branch_id, $area_id, $zone_id]);
                        if (!$branchCheck->fetch()) {
                            $response['message'] = 'Selected branch does not exist or does not belong to the selected area/zone.';
                            echo json_encode($response);
                            exit();
                        }
                    }
                    
                    $is_weekly = (isset($_POST['is_weekly']) && $_POST['is_weekly'] == '1') ? 1 : 0;

                    if ($existingUser) {
                        // UPDATE existing user
                        $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
                        $stmt->execute([$user_id]);
                        $curr = $stmt->fetch();
                        
                        if ($curr) {
                            $oldRole = $curr['role'];
                            $oldNeedsZone = in_array($oldRole, ['zm', 'dzm', 'am', 'bm', 'co']);
                            $oldNeedsArea = in_array($oldRole, ['am', 'bm', 'co']);
                            $oldNeedsBranch = in_array($oldRole, ['bm', 'co']);
                            
                            if ($oldNeedsZone && !$needsZone) $zone_id = null;
                            if ($oldNeedsArea && !$needsArea) $area_id = null;
                            if ($oldNeedsBranch && !$needsBranch) $branch_id = null;
                        }

                        $sql = "UPDATE users SET name = ?, username = ?, role = ?, zone_id = ?, area_id = ?, branch_id = ?, is_weekly = ?";
                        $params = [$name, $username, $role, $zone_id, $area_id, $branch_id, $is_weekly];

                        if (!empty($_POST['password'])) {
                            $sql .= ", password = ?";
                            $params[] = password_hash($_POST['password'], PASSWORD_DEFAULT);
                        }
                        
                        $sql .= " WHERE id = ?";
                        $params[] = $user_id;
                        
                        $stmt = $pdo->prepare($sql);
                        $stmt->execute($params);

                    } else {
                        // INSERT new user
                        $sql = "INSERT INTO users (name, full_name, username, role, zone_id, area_id, branch_id, is_weekly, password, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')";
                        $password = !empty($_POST['password']) ? password_hash($_POST['password'], PASSWORD_DEFAULT) : password_hash('123456', PASSWORD_DEFAULT);
                        $stmt = $pdo->prepare($sql);
                        $stmt->execute([$name, $name, $username, $role, $zone_id, $area_id, $branch_id, $is_weekly, $password]);
                    }

                    $tableData = generateUserTableHTML($pdo, $currentPage, $pageLimit, $_POST['search'] ?? '');
                    $response['table_html'] = $tableData['tableHTML'];
                    $response['pagination_html'] = $tableData['paginationHTML'];
                    $response['total_users'] = $tableData['totalUsers'];
                    $response['success'] = true;
                    $response['message'] = 'User saved successfully.';
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
                    if (empty($zone_id)) {
                        $response['success'] = true;
                        $response['data'] = [];
                    } else {
                        $stmt = $pdo->prepare("SELECT id, name FROM areas WHERE zone_id = ? ORDER BY name ASC");
                        $stmt->execute([$zone_id]);
                        $response['success'] = true;
                        $response['data'] = $stmt->fetchAll();
                    }
                    break;
                    
                case 'get_branches':
                    $area_id = $_POST['area_id'] ?? '';
                    if (empty($area_id)) {
                        $response['success'] = true;
                        $response['data'] = [];
                    } else {
                        $stmt = $pdo->prepare("SELECT id, name FROM branches WHERE area_id = ? ORDER BY name ASC");
                        $stmt->execute([$area_id]);
                        $response['success'] = true;
                        $response['data'] = $stmt->fetchAll();
                    }
                    break;
                    
                case 'delete_passkeys':
                    // CHANGED: Accept username instead of user_id
                    $username = $_POST['username'] ?? '';
                    if (empty($username)) {
                        $response['message'] = 'Username required.';
                        break;
                    }
                    
                    // First get user_id from username
                    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
                    $stmt->execute([$username]);
                    $user = $stmt->fetch();
                    
                    if (!$user) {
                        $response['message'] = 'User not found.';
                        break;
                    }
                    
                    $user_id = $user['id'];
                    $stmt = $pdo->prepare("DELETE FROM user_passkeys WHERE user_id = ?");
                    $stmt->execute([$user_id]);
                    
                    $tableData = generateUserTableHTML($pdo, $currentPage, $pageLimit, $_POST['search'] ?? '');
                    $response['table_html'] = $tableData['tableHTML'];
                    $response['pagination_html'] = $tableData['paginationHTML'];
                    $response['total_users'] = $tableData['totalUsers'];
                    $response['success'] = true;
                    $response['message'] = 'Passkeys deleted.';
                    break;
                    
                default:
                    throw new Exception('Unknown action');
            }
        } catch (Exception $e) { 
            $response['message'] = 'Error: ' . $e->getMessage();
            error_log('AJAX Error in manage_users: ' . $e->getMessage());
        }
        
        echo json_encode($response);
        exit();
    }
}

$initialTableData = generateUserTableHTML($pdo, $currentPage, $pageLimit, '');
$currentPage = $initialTableData['currentPage'];

$zonesStmt = $pdo->query("SELECT id, name FROM zones ORDER BY name ASC");
$zonesList = $zonesStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en" class="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Users - CUPAD</title>
    <link rel="icon" type="image/png" href="../uploads/CUPAD LOGO.png">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />
    <style>
        :root {
            --primary: #2563eb; 
            --primary-dark: #1d4ed8;
            --success: #059669; 
            --warning: #d97706; 
            --danger: #dc2626; 
            --info: #0284c7;
            
            --bg-body: #f1f5f9; 
            --bg-surface: #ffffff;
            
            --text-main: #0f172a; 
            --text-muted: #64748b;
            
            --border: #e2e8f0; 
            --radius-lg: 16px; 
            --radius-md: 10px;
            
            --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
            --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1);
            --nav-height: 70px;
        }

        html.dark {
            --bg-body: #0f172a; 
            --bg-surface: #1e293b;
            --text-main: #f8fafc; 
            --text-muted: #94a3b8;
            --border: #334155;
            --primary: #3b82f6; 
            --success: #34d399;
            --warning: #fbbf24;
            --danger: #f87171;
            --info: #38bdf8;
        }

        ::-webkit-scrollbar { width: 4px; height: 4px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: var(--border); border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: var(--text-muted); }

        * { box-sizing: border-box; outline: none; }
        body { font-family: 'Plus Jakarta Sans', sans-serif; background: var(--bg-body); color: var(--text-main); margin: 0; padding-top: var(--nav-height); font-size: 0.92rem; transition: background 0.3s, color 0.3s; }
        a { text-decoration: none; color: inherit; }

        .main-header { position: fixed; top: 0; left: 0; right: 0; height: var(--nav-height); background: rgba(255,255,255,0.85); backdrop-filter: blur(10px); border-bottom: 1px solid var(--border); z-index: 50; }
        html.dark .main-header { background: rgba(15, 23, 42, 0.85); }
        .navbar { max-width: 1400px; margin: 0 auto; padding: 0 1.5rem; display: flex; align-items: center; justify-content: space-between; height: 100%; }
        .logo { display: flex; align-items: center; gap: 0.75rem; font-weight: 800; font-size: 1.35rem; color: var(--primary); }
        .logo img { height: 38px; }
        .nav-right { display: flex; align-items: center; gap: 1rem; }
        
        .icon-btn { width: 36px; height: 36px; border-radius: 50%; border: none; background: transparent; color: var(--text-muted); cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; transition: 0.2s; }
        .icon-btn:hover { background: var(--bg-body); color: var(--text-main); }

        .user-dropdown-wrap { position: relative; }
        .user-pill { display: flex; align-items: center; gap: 0.75rem; padding: 4px 8px 4px 4px; border: 1px solid var(--border); border-radius: 99px; background: var(--bg-surface); cursor: pointer; transition: all 0.2s; }
        .user-pill:hover { border-color: var(--primary); }
        .user-avatar { width: 34px; height: 34px; border-radius: 50%; object-fit: cover; }
        .user-avatar-fallback { width: 34px; height: 34px; border-radius: 50%; background: var(--bg-body); color: var(--text-muted); display: flex; align-items: center; justify-content: center; font-size: 1rem; border: 1px solid var(--border); }
        .user-info { display: flex; flex-direction: column; line-height: 1.1; padding-right: 0.5rem; }
        .user-name { font-weight: 600; font-size: 0.85rem; }
        .user-role { font-size: 0.7rem; color: var(--text-muted); text-transform: uppercase; }
        
        .dropdown-menu { position: absolute; top: 120%; right: 0; background: var(--bg-surface); border: 1px solid var(--border); border-radius: var(--radius-md); box-shadow: var(--shadow-md); min-width: 200px; display: none; z-index: 1000; flex-direction: column; overflow: hidden; animation: fadeIn 0.2s ease; }
        .dropdown-menu.show { display: flex; }
        .dropdown-item { padding: 0.75rem 1rem; display: flex; align-items: center; gap: 0.75rem; font-size: 0.9rem; transition: 0.2s; }
        .dropdown-item:hover { background: var(--bg-body); color: var(--primary); }
        .text-danger { color: var(--danger) !important; }

        .container { max-width: 1400px; margin: 0 auto; padding: 2rem 1.5rem; }
        
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; }
        .page-title { font-weight: 700; font-size: 1.5rem; margin: 0; display: flex; align-items: center; gap: 0.75rem; }
        .btn { padding: 0.6rem 1.2rem; border-radius: 99px; border: 1px solid var(--border); background: var(--bg-surface); color: var(--text-main); font-weight: 600; font-size: 0.85rem; cursor: pointer; display: flex; align-items: center; gap: 0.5rem; transition: 0.2s; }
        .btn:hover { border-color: var(--primary); color: var(--primary); background: var(--bg-body); }
        .btn-primary { background: var(--primary); color: white; border: none; }
        .btn-primary:hover { background: var(--primary-dark); color: white; }
        .btn-sm { padding: 0.4rem 0.8rem; font-size: 0.8rem; }

        .card { background: var(--bg-surface); border: 1px solid var(--border); border-radius: var(--radius-lg); box-shadow: var(--shadow-sm); overflow: hidden; display: flex; flex-direction: column; min-height: 600px; }
        .card-header { padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; background: var(--bg-surface); }
        
        .search-wrapper { position: relative; width: 300px; }
        .search-wrapper i { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--text-muted); font-size: 0.85rem; }
        .search-input { width: 100%; padding: 0.6rem 1rem 0.6rem 2.4rem; border-radius: 99px; border: 1px solid var(--border); background: var(--bg-body); color: var(--text-main); transition: 0.2s; }
        .search-input:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(37,99,235,0.1); }

        .table-wrap { flex: 1; overflow-y: auto; position: relative; }
        table { width: 100%; border-collapse: separate; border-spacing: 0; }
        th { position: sticky; top: 0; z-index: 10; background: var(--bg-surface); text-align: left; padding: 1rem 1.5rem; font-size: 0.75rem; text-transform: uppercase; color: var(--text-muted); font-weight: 600; border-bottom: 1px solid var(--border); }
        td { padding: 1rem 1.5rem; border-bottom: 1px solid var(--border); vertical-align: middle; }
        tr { transition: background 0.15s; }
        tr:hover td { background: var(--bg-body); }
        
        .role-badge { padding: 4px 10px; border-radius: 6px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; }
        .admin { background: #fef3c7; color: #d97706; }
        .tm { background: #e0f2fe; color: #0284c7; }
        .zm, .dzm { background: #d1fae5; color: #059669; }
        .am { background: #ffedd5; color: #ea580c; }
        .bm, .co { background: #e0e7ff; color: #4f46e5; }
        
        html.dark .admin { background: rgba(245,158,11,0.15); color: #fbbf24; }
        html.dark .tm { background: rgba(2, 132, 199, 0.15); color: #38bdf8; }
        html.dark .zm, html.dark .dzm { background: rgba(16,185,129,0.15); color: #34d399; }
        html.dark .am { background: rgba(234,88,12,0.15); color: #fb923c; }
        html.dark .bm, html.dark .co { background: rgba(99,102,241,0.15); color: #818cf8; }

        .assignment { font-size: 0.85rem; color: var(--text-muted); }
        .badge-protected { font-size: 0.7rem; background: var(--bg-body); padding: 4px 8px; border-radius: 4px; color: var(--text-muted); border: 1px solid var(--border); }
        .passkey-badge { font-size: 0.75rem; background: #e0f2fe; color: #0284c7; padding: 4px 8px; border-radius: 6px; font-weight: 600; display: inline-flex; align-items: center; gap: 0.25rem; }
        html.dark .passkey-badge { background: rgba(2, 132, 199, 0.15); color: #38bdf8; }

        .action-btn { width: 32px; height: 32px; border-radius: 8px; border: none; background: transparent; color: var(--text-muted); cursor: pointer; transition: 0.2s; display: inline-flex; align-items: center; justify-content: center; }
        .action-btn:hover { background: var(--bg-body); color: var(--primary); }
        .action-btn.danger:hover { background: #fee2e2; color: #dc2626; }
        html.dark .action-btn.danger:hover { background: rgba(220,38,38,0.2); }

        .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 100; display: none; align-items: center; justify-content: center; backdrop-filter: blur(2px); }
        .modal { background: var(--bg-surface); padding: 2rem; border-radius: var(--radius-lg); width: 90%; max-width: 420px; box-shadow: var(--shadow-md); position: relative; max-height: 90vh; overflow-y: auto; }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; }
        .modal-title { font-size: 1.25rem; font-weight: 700; margin: 0; }
        .modal-close { background: none; border: none; font-size: 1.2rem; cursor: pointer; color: var(--text-muted); }
        
        .form-group { margin-bottom: 1.2rem; }
        .form-label { display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.4rem; color: var(--text-main); }
        .form-input, .form-select { width: 100%; padding: 0.7rem 1rem; border-radius: 8px; border: 1px solid var(--border); background: var(--bg-body); color: var(--text-main); font-size: 0.9rem; }
        .form-input:focus, .form-select:focus { border-color: var(--primary); outline: none; }

        .skeleton { background: linear-gradient(90deg, var(--bg-body) 25%, var(--border) 50%, var(--bg-body) 75%); background-size: 200% 100%; animation: shimmer 1.5s infinite; border-radius: 4px; display: inline-block; height: 16px; }
        @keyframes shimmer { 0% { background-position: 200% 0; } 100% { background-position: -200% 0; } }

        .toast { position: fixed; top: 90px; right: 20px; padding: 1rem 1.5rem; border-radius: var(--radius-md); background: var(--bg-surface); box-shadow: var(--shadow-md); border-left: 4px solid var(--primary); display: flex; align-items: center; gap: 0.75rem; transform: translateX(120%); transition: 0.3s; z-index: 200; font-weight: 500; }
        .toast.show { transform: translateX(0); }
        .toast.success { border-color: var(--success); } .toast.error { border-color: var(--danger); }
        
        .pagination-controls { padding: 1rem 1.5rem; border-top: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; background: var(--bg-surface); }
        @keyframes fadeIn { from{opacity:0} to{opacity:1} }
        
        /* Loading state for modal */
        .modal-loading { opacity: 0.6; pointer-events: none; }
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
                <button class="icon-btn" id="themeToggle"><i class="fas fa-moon"></i></button>
                
                <div class="user-dropdown-wrap">
                    <div class="user-pill" id="userDropdownTrigger">
                        <?php if($has_profile_pic): ?>
                            <img src="<?php echo htmlspecialchars($profile_pic_path); ?>" class="user-avatar" alt="User">
                        <?php else: ?>
                            <div class="user-avatar-fallback"><i class="fas fa-user"></i></div>
                        <?php endif; ?>
                        <div class="user-info">
                            <span class="user-name"><?php echo htmlspecialchars($full_name); ?></span>
                            <span class="user-role"><?php echo ucfirst(htmlspecialchars($user_role)); ?></span>
                        </div>
                        <i class="fas fa-chevron-down" style="font-size:0.7rem; color:var(--text-muted)"></i>
                    </div>

                    <div class="dropdown-menu" id="userDropdown">
                        <a href="profile.php" class="dropdown-item">
                            <i class="fas fa-user-circle" style="color:var(--primary)"></i> My Profile
                        </a>
                        <div style="height:1px; background:var(--border); margin:0"></div>
                        <a href="../logout.php" class="dropdown-item text-danger">
                            <i class="fas fa-sign-out-alt"></i> Logout
                        </a>
                    </div>
                </div>

            </div>
        </div>
    </header>

    <main class="container">
        <div class="page-header">
            <h1 class="page-title">
                <span class="icon-btn" style="background:var(--bg-surface); width:40px; height:40px; cursor:default; border:1px solid var(--border)"><i class="fas fa-users-cog text-muted"></i></span> 
                Manage Users
            </h1>
            <a href="create_account.php" class="btn btn-primary"><i class="fas fa-plus"></i> New User</a>
        </div>

        <div class="card">
            <div class="card-header">
                <div style="display:flex; flex-direction:column">
                    <div style="font-weight:700; font-size:1.1rem">System Users</div>
                    <div style="font-size:0.85rem; color:var(--text-muted)">Total: <span id="totalUsersCount"><?php echo $initialTableData['totalUsers']; ?></span></div>
                </div>
                <div style="display:flex; gap:0.5rem">
                    <div class="search-wrapper">
                        <i class="fas fa-search"></i>
                        <input type="text" id="userSearchInput" class="search-input" placeholder="Search by name, role..." onkeyup="handleSearch(event)">
                    </div>
                    <button class="icon-btn" onclick="loadUserTable(currentPage, true)" title="Refresh" style="border:1px solid var(--border)"><i class="fas fa-sync-alt"></i></button>
                </div>
            </div>

            <div class="table-wrap" id="userTableWrapper">
                <?php echo $initialTableData['tableHTML']; ?>
            </div>

            <div id="paginationControls">
                <?php echo $initialTableData['paginationHTML']; ?>
            </div>
        </div>
    </main>

    <!-- Edit User Modal -->
    <div class="modal-overlay" id="editModal">
        <div class="modal" id="editModalContent">
            <div class="modal-header">
                <h3 class="modal-title">Edit User Details</h3>
                <button class="modal-close" onclick="closeEditModal()"><i class="fas fa-times"></i></button>
            </div>
            
            <form id="editUserForm">
                <input type="hidden" id="editUserId" name="user_id">
                
                <div class="form-group">
                    <label class="form-label">Full Name</label>
                    <input type="text" id="editName" name="name" class="form-input" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Username</label>
                    <input type="text" id="editUsername" name="username" class="form-input" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">New Password (Optional)</label>
                    <input type="password" id="editPassword" name="password" class="form-input" placeholder="Leave blank to keep existing">
                </div>

                <div class="form-group">
                    <label class="form-label">System Role</label>
                    <select id="editRole" name="role" class="form-select" required onchange="handleRoleChange()">
                        <option value="">Select Role</option>
                        <option value="admin">Administrator</option>
                        <option value="tm">Top Management (TM)</option>
                        <option value="zm">Zone Manager (ZM)</option>
                        <option value="dzm">Deputy Zone Manager (DZM)</option>
                        <option value="am">Area Manager (AM)</option>
                        <option value="bm">Branch Manager (BM)</option>
                        <option value="co">Credit Officer (CO)</option>
                    </select>
                </div>

                <!-- CO Specific -->
                <div id="coSpecifics" style="display:none; padding:1rem; background:var(--bg-body); border-radius:8px; margin-bottom:1rem">
                    <div style="display:flex; align-items:center; gap:0.5rem; margin-bottom:0.5rem">
                        <input type="checkbox" id="editIsWeekly" name="is_weekly" value="1">
                        <label for="editIsWeekly" style="font-weight:600; font-size:0.9rem">Is Weekly Credit Officer?</label>
                    </div>
                </div>

                <!-- Assignment Section -->
                <div id="assignmentSection" style="display:none; border-top:1px solid var(--border); padding-top:1rem; margin-top:1rem">
                    <div style="font-size:0.75rem; text-transform:uppercase; font-weight:700; color:var(--text-muted); margin-bottom:1rem">Location Assignment</div>
                    
                    <div class="form-group" id="zoneGroup" style="display: none;">
                        <label class="form-label">Zone <span style="color:var(--danger)">*</span></label>
                        <select id="editZone" name="zone_id" class="form-select" onchange="handleZoneChange()">
                            <option value="">Select Zone</option>
                            <?php foreach ($zonesList as $zone): ?>
                                <option value="<?php echo htmlspecialchars($zone['id']); ?>"><?php echo htmlspecialchars($zone['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group" id="areaGroup" style="display: none;">
                        <label class="form-label">Area <span style="color:var(--danger)">*</span></label>
                        <select id="editArea" name="area_id" class="form-select" onchange="handleAreaChange()">
                            <option value="">Select Area</option>
                        </select>
                    </div>
                    
                    <div class="form-group" id="branchGroup" style="display: none;">
                        <label class="form-label">Branch <span style="color:var(--danger)">*</span></label>
                        <select id="editBranch" name="branch_id" class="form-select">
                            <option value="">Select Branch</option>
                        </select>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary" style="width:100%; justify-content:center; margin-top:1.5rem">Save Changes</button>
            </form>
        </div>
    </div>

    <!-- Credentials Modal -->
    <div class="modal-overlay" id="credentialsOverlay">
        <div class="modal" style="max-width:350px">
            <div class="modal-header">
                <h3 class="modal-title">User Credentials</h3>
                <button class="modal-close" onclick="closeCredentialsPopup()"><i class="fas fa-times"></i></button>
            </div>
            <div style="background:var(--bg-body); padding:1rem; border-radius:8px; border:1px solid var(--border); font-family:monospace; font-size:0.9rem">
                <div style="margin-bottom:0.5rem"><strong>User:</strong> <span id="credUser">...</span></div>
                <div><strong>Pass:</strong> <span id="credPass">...</span></div>
            </div>
            <div style="font-size:0.8rem; color:var(--danger); margin-top:1rem">
                <i class="fas fa-exclamation-triangle"></i> Password hash protection enabled. 
            </div>
        </div>
    </div>

    <div id="toastContainer"></div>

    <template id="skeletonTemplate">
        <table class="table">
            <thead><tr><th>Name</th><th>Username</th><th>Role</th><th>Assignment</th><th>Passkeys</th><th></th></tr></thead>
            <tbody>
                <?php for($i=0; $i<8; $i++): ?>
                <tr>
                    <td><div class="skeleton" style="width:120px"></div></td>
                    <td><div class="skeleton" style="width:80px"></div></td>
                    <td><div class="skeleton" style="width:60px"></div></td>
                    <td><div class="skeleton" style="width:150px"></div></td>
                    <td><div class="skeleton" style="width:50px"></div></td>
                    <td><div class="skeleton" style="width:40px"></div></td>
                </tr>
                <?php endfor; ?>
            </tbody>
        </table>
    </template>

    <script>
        const $ = (id) => document.getElementById(id);
        
        // --- Header & Theme ---
        const themeBtn = $('themeToggle');
        const html = document.documentElement;
        if(localStorage.getItem('theme') === 'dark') { html.classList.add('dark'); themeBtn.innerHTML = '<i class="fas fa-sun"></i>'; }
        else { html.classList.add('light'); themeBtn.innerHTML = '<i class="fas fa-moon"></i>'; }

        themeBtn.addEventListener('click', () => {
            const isDark = html.classList.toggle('dark');
            html.classList.toggle('light');
            localStorage.setItem('theme', isDark ? 'dark' : 'light');
            themeBtn.innerHTML = isDark ? '<i class="fas fa-sun"></i>' : '<i class="fas fa-moon"></i>';
        });

        const userTrigger = $('userDropdownTrigger');
        userTrigger.addEventListener('click', (e) => { e.stopPropagation(); $('userDropdown').classList.toggle('show'); });
        document.addEventListener('click', (e) => { if (!userTrigger.contains(e.target)) $('userDropdown').classList.remove('show'); });

        // --- Data Logic ---
        let currentPage = <?php echo $currentPage; ?>;
        const pageLimit = <?php echo $pageLimit; ?>;
        let searchTimeout;
        let isLoadingUser = false;

        function handleSearch(e) {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => loadUserTable(1, false, e.target.value), 300);
        }

        async function loadUserTable(page = 1, isRefresh = false, searchTerm = null) {
            if(searchTerm === null) searchTerm = $('userSearchInput').value;
            page = Math.max(1, page);
            
            const tableWrap = $('userTableWrapper');
            if(!isRefresh) tableWrap.innerHTML = $('skeletonTemplate').innerHTML;

            try {
                const formData = new FormData();
                formData.append('action', 'load_table');
                formData.append('page', page);
                formData.append('limit', pageLimit);
                formData.append('search', searchTerm);

                const res = await fetch('', { 
                    method: 'POST', 
                    body: formData, 
                    headers: {'X-Requested-With': 'XMLHttpRequest'},
                    cache: 'no-store'
                });
                const data = await res.json();

                if(data.success) {
                    currentPage = data.current_page;
                    tableWrap.innerHTML = data.table_html;
                    $('paginationControls').innerHTML = data.pagination_html;
                    $('totalUsersCount').textContent = data.total_users;
                    attachRowClicks();
                    if(isRefresh) showToast('Table refreshed', 'success');
                } else throw new Error(data.message);
            } catch(e) {
                showToast(e.message || 'Error loading table', 'error');
                tableWrap.innerHTML = '<div style="padding:2rem; text-align:center; color:var(--danger)">Failed to load data.</div>';
            }
        }

        function attachRowClicks() {
            document.querySelectorAll('.user-row').forEach(row => {
                row.addEventListener('click', function(e) {
                    if(e.target.closest('button')) return;
                    const user = this.dataset.username;
                    const pass = this.dataset.plainPassword;
                    if(user === 'Daveskhyle' || this.dataset.role === 'admin') return;
                    
                    $('credUser').textContent = user;
                    $('credPass').textContent = pass;
                    $('credentialsOverlay').style.display = 'flex';
                });
            });
        }

        function closeCredentialsPopup() { $('credentialsOverlay').style.display = 'none'; }

        // --- CRITICAL FIX: Completely reset form before loading new data ---
        function resetEditFormCompletely() {
            // Reset all text inputs
            $('editUserId').value = '';
            $('editName').value = '';
            $('editUsername').value = '';
            $('editPassword').value = '';
            
            // Reset select dropdowns to first option
            $('editRole').selectedIndex = 0;
            $('editZone').selectedIndex = 0;
            
            // Reset checkbox
            $('editIsWeekly').checked = false;
            
            // Clear and reset dependent dropdowns
            $('editArea').innerHTML = '<option value="">Select Area</option>';
            $('editBranch').innerHTML = '<option value="">Select Branch</option>';
            
            // Hide all conditional sections
            $('assignmentSection').style.display = 'none';
            $('coSpecifics').style.display = 'none';
            $('zoneGroup').style.display = 'none';
            $('areaGroup').style.display = 'none';
            $('branchGroup').style.display = 'none';
        }

        // --- FIXED Edit Logic with cache busting ---
        async function editUser(userId, e) {
            e.stopPropagation();
            
            if (!userId) {
                console.error('Edit user error: Error: User ID is required.');
                showToast('User ID required', 'error');
                return;
            }
            
            // Prevent multiple clicks
            if (isLoadingUser) return;
            isLoadingUser = true;
            
            // CRITICAL: Reset form BEFORE showing modal
            resetEditFormCompletely();
            
            // Show modal immediately with loading state
            const modal = $('editModal');
            const modalContent = $('editModalContent');
            modal.style.display = 'flex';
            modalContent.classList.add('modal-loading');
            
            try {
                // CRITICAL: Add cache-busting parameter to prevent browser caching
                const fd = new FormData(); 
                fd.append('action', 'get_user'); 
                fd.append('user_id', userId);
                fd.append('_t', Date.now());
                
                const res = await fetch('', { 
                    method: 'POST', 
                    body: fd, 
                    headers: {'X-Requested-With': 'XMLHttpRequest'},
                    cache: 'no-store'
                });
                
                if (!res.ok) {
                    throw new Error(`Server error: ${res.status}`);
                }
                
                const json = await res.json();
                
                if(json.success && json.data) {
                    const u = json.data;
                    
                    // Debug log
                    console.log('Loading user by ID:', userId, 'Name:', u.name);
                    
                    // Populate form fields
                    document.getElementById('editUserId').value = u.id || '';
                    document.getElementById('editName').value = u.name || u.full_name || '';
                    document.getElementById('editUsername').value = u.username || '';
                    document.getElementById('editPassword').value = '';
                    
                    // Set role dropdown
                    const roleSelect = document.getElementById('editRole');
                    roleSelect.value = u.role || '';
                    
                    // Set weekly checkbox
                    document.getElementById('editIsWeekly').checked = (u.is_weekly == 1);
                    
                    // Show/hide sections based on role
                    handleRoleChange(true);
                    
                    // Handle location hierarchy
                    if(u.zone_id) {
                        document.getElementById('editZone').value = u.zone_id;
                        await loadAreasForEdit(u.area_id);
                        
                        if(u.area_id) {
                            await loadBranchesForEdit(u.branch_id);
                        }
                    }
                    
                    console.log('Form populated successfully for user:', u.username);
                    
                } else {
                    throw new Error(json.message || 'Failed to load user data');
                }
            } catch(e) { 
                console.error('Edit user error:', e);
                showToast('Error: ' + e.message, 'error');
                closeEditModal();
            } finally {
                isLoadingUser = false;
                modalContent.classList.remove('modal-loading');
            }
        }

        function closeEditModal() { 
            $('editModal').style.display = 'none';
            setTimeout(resetEditFormCompletely, 300);
        }

        function handleRoleChange(isInit = false) {
            const role = $('editRole').value;
            const needsZone = ['zm','dzm','am','bm','co'].includes(role);
            const needsArea = ['am','bm','co'].includes(role);
            const needsBranch = ['bm','co'].includes(role);
            
            $('assignmentSection').style.display = needsZone ? 'block' : 'none';
            $('zoneGroup').style.display = needsZone ? 'block' : 'none';
            $('areaGroup').style.display = needsArea ? 'block' : 'none';
            $('branchGroup').style.display = needsBranch ? 'block' : 'none';
            
            $('coSpecifics').style.display = (role === 'co') ? 'block' : 'none';

            if(!isInit) {
                $('editZone').value = ''; 
                $('editArea').innerHTML = '<option value="">Select Area</option>'; 
                $('editBranch').innerHTML = '<option value="">Select Branch</option>';
            }
        }

        function handleZoneChange() {
            $('editArea').innerHTML = '<option value="">Select Area</option>';
            $('editBranch').innerHTML = '<option value="">Select Branch</option>';
            loadAreas();
        }

        function handleAreaChange() {
            $('editBranch').innerHTML = '<option value="">Select Branch</option>';
            loadBranches();
        }

        async function loadAreasForEdit(preSelect = null) {
            const zid = $('editZone').value;
            if(!zid) return;
            
            try {
                const fd = new FormData(); 
                fd.append('action', 'get_areas'); 
                fd.append('zone_id', zid);
                fd.append('_t', Date.now());
                
                const res = await fetch('', { 
                    method: 'POST', 
                    body: fd, 
                    headers: {'X-Requested-With': 'XMLHttpRequest'},
                    cache: 'no-store'
                });
                
                const json = await res.json();
                
                if(json.success) {
                    let html = '<option value="">Select Area</option>';
                    json.data.forEach(a => {
                        const selected = preSelect && String(a.id) === String(preSelect) ? 'selected' : '';
                        html += `<option value="${a.id}" ${selected}>${a.name}</option>`;
                    });
                    $('editArea').innerHTML = html;
                }
            } catch(e) {
                console.error('Error loading areas:', e);
            }
        }

        async function loadBranchesForEdit(preSelect = null) {
            const aid = $('editArea').value;
            if(!aid) return;
            
            try {
                const fd = new FormData(); 
                fd.append('action', 'get_branches'); 
                fd.append('area_id', aid);
                fd.append('_t', Date.now());
                
                const res = await fetch('', { 
                    method: 'POST', 
                    body: fd, 
                    headers: {'X-Requested-With': 'XMLHttpRequest'},
                    cache: 'no-store'
                });
                
                const json = await res.json();
                
                if(json.success) {
                    let html = '<option value="">Select Branch</option>';
                    json.data.forEach(b => {
                        const selected = preSelect && String(b.id) === String(preSelect) ? 'selected' : '';
                        html += `<option value="${b.id}" ${selected}>${b.name}</option>`;
                    });
                    $('editBranch').innerHTML = html;
                }
            } catch(e) {
                console.error('Error loading branches:', e);
            }
        }

        async function loadAreas(preSelect = null) {
            const zid = $('editZone').value;
            if(!zid) return;
            
            try {
                const fd = new FormData(); 
                fd.append('action', 'get_areas'); 
                fd.append('zone_id', zid);
                
                const res = await fetch('', { 
                    method: 'POST', 
                    body: fd, 
                    headers: {'X-Requested-With': 'XMLHttpRequest'} 
                });
                
                const json = await res.json();
                
                if(json.success) {
                    let html = '<option value="">Select Area</option>';
                    json.data.forEach(a => {
                        const selected = preSelect && String(a.id) === String(preSelect) ? 'selected' : '';
                        html += `<option value="${a.id}" ${selected}>${a.name}</option>`;
                    });
                    $('editArea').innerHTML = html;
                }
            } catch(e) {
                console.error('Error loading areas:', e);
            }
        }

        async function loadBranches(preSelect = null) {
            const aid = $('editArea').value;
            if(!aid) return;
            
            try {
                const fd = new FormData(); 
                fd.append('action', 'get_branches'); 
                fd.append('area_id', aid);
                
                const res = await fetch('', { 
                    method: 'POST', 
                    body: fd, 
                    headers: {'X-Requested-With': 'XMLHttpRequest'} 
                });
                
                const json = await res.json();
                
                if(json.success) {
                    let html = '<option value="">Select Branch</option>';
                    json.data.forEach(b => {
                        const selected = preSelect && String(b.id) === String(preSelect) ? 'selected' : '';
                        html += `<option value="${b.id}" ${selected}>${b.name}</option>`;
                    });
                    $('editBranch').innerHTML = html;
                }
            } catch(e) {
                console.error('Error loading branches:', e);
            }
        }

        $('editUserForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            const btn = this.querySelector('button[type="submit"]');
            const originalText = btn.innerText;
            btn.innerText = 'Saving...'; 
            btn.disabled = true;
            
            try {
                const fd = new FormData(this);
                fd.append('action', 'save_user');
                
                const res = await fetch('', { 
                    method: 'POST', 
                    body: fd, 
                    headers: {'X-Requested-With': 'XMLHttpRequest'} 
                });
                
                const json = await res.json();
                
                if(json.success) {
                    showToast('User saved successfully', 'success');
                    closeEditModal();
                    loadUserTable(currentPage);
                } else {
                    throw new Error(json.message || 'Failed to save user');
                }
            } catch(err) { 
                showToast(err.message, 'error'); 
            } finally { 
                btn.innerText = originalText; 
                btn.disabled = false; 
            }
        });

        // CHANGED: Now accepts username instead of id
        async function deleteUser(username, e) {
            e.stopPropagation();
            if(!username) {
                showToast('Username required', 'error');
                return;
            }
            if(!confirm('Delete this user permanently?')) return;
            
            try {
                const fd = new FormData(); 
                fd.append('action', 'delete_user'); 
                fd.append('username', username); // CHANGED: Send username
                
                const res = await fetch('', { 
                    method: 'POST', 
                    body: fd, 
                    headers: {'X-Requested-With': 'XMLHttpRequest'} 
                });
                
                const json = await res.json();
                if(json.success) {
                    showToast('User deleted', 'success');
                    loadUserTable(currentPage);
                } else throw new Error(json.message);
            } catch(err) { 
                showToast(err.message, 'error'); 
            }
        }

        // CHANGED: Now accepts username instead of id
        async function deletePasskeys(username, e) {
            e.stopPropagation();
            if(!username) {
                showToast('Username required', 'error');
                return;
            }
            if(!confirm('Delete all passkeys for this user? This action cannot be undone.')) return;
            
            try {
                const fd = new FormData(); 
                fd.append('action', 'delete_passkeys'); 
                fd.append('username', username); // CHANGED: Send username
                
                const res = await fetch('', { 
                    method: 'POST', 
                    body: fd, 
                    headers: {'X-Requested-With': 'XMLHttpRequest'} 
                });
                
                const json = await res.json();
                if(json.success) {
                    showToast('Passkeys deleted', 'success');
                    loadUserTable(currentPage);
                } else throw new Error(json.message);
            } catch(err) { 
                showToast(err.message, 'error'); 
            }
        }

        function showToast(msg, type) {
            const d = document.createElement('div');
            d.className = `toast ${type}`;
            d.innerHTML = `<i class="fas fa-${type==='success'?'check-circle':'exclamation-triangle'}"></i> ${msg}`;
            $('toastContainer').appendChild(d);
            setTimeout(()=>d.classList.add('show'), 10);
            setTimeout(()=>{ 
                d.classList.remove('show'); 
                setTimeout(()=>d.remove(), 300); 
            }, 3000);
        }

        // Init
        attachRowClicks();
    </script>
</body>
</html>