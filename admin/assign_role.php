<?php
// Enable error reporting for development
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Security headers
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Content-Security-Policy: default-src \'self\'; style-src \'self\' \'unsafe-inline\' https://fonts.googleapis.com https://cdnjs.cloudflare.com; font-src \'self\' https://fonts.gstatic.com https://cdnjs.cloudflare.com; script-src \'self\' \'unsafe-inline\' blob:; img-src \'self\' data:; connect-src \'self\';');

date_default_timezone_set('Africa/Lagos');
session_start();

// --- 1. CONFIGURATION & DATABASE ---
class Config {
    const MAX_LOGIN_ATTEMPTS = 5;
    const SESSION_TIMEOUT = 3600;
    const CSRF_TOKEN_NAME = 'csrf_token';
    const ITEMS_PER_PAGE = 10;
    
    // Database Config
    const DB_HOST = 'localhost';
    const DB_NAME = 'cupadnam_db';
    const DB_USER = 'cupadnam_db';
    const DB_PASS = 'f2GrjZQCz8E39nCu9eLg';
    const DB_CHARSET = 'utf8mb4';
}

function getDbConnection() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = "mysql:host=" . Config::DB_HOST . ";dbname=" . Config::DB_NAME . ";charset=" . Config::DB_CHARSET;
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];
            $pdo = new PDO($dsn, Config::DB_USER, Config::DB_PASS, $options);
        } catch (PDOException $e) {
            die("Database Connection Error: " . $e->getMessage());
        }
    }
    return $pdo;
}

class SecurityValidator {
    public static function validateSession(): void {
        if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > Config::SESSION_TIMEOUT) {
            session_destroy(); header('Location: ../index.php?timeout=1'); exit();
        }
        $_SESSION['last_activity'] = time();
        if ($_SERVER['REQUEST_METHOD'] === 'POST') self::validateCSRFToken();
        if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
            header('Location: ../index.php'); exit();
        }
    }
    public static function generateCSRFToken(): string {
        if (!isset($_SESSION[Config::CSRF_TOKEN_NAME])) $_SESSION[Config::CSRF_TOKEN_NAME] = bin2hex(random_bytes(32));
        return $_SESSION[Config::CSRF_TOKEN_NAME];
    }
    private static function validateCSRFToken(): void {
        $token = $_POST[Config::CSRF_TOKEN_NAME] ?? '';
        if (!hash_equals($_SESSION[Config::CSRF_TOKEN_NAME] ?? '', $token)) { http_response_code(403); die('CSRF token validation failed'); }
    }
    public static function sanitizeInput(string $input): string { return htmlspecialchars(trim($input), ENT_QUOTES | ENT_HTML5, 'UTF-8'); }
    public static function validateRole(string $role): bool { return in_array($role, ['admin', 'dzm', 'zm', 'am', 'bm', 'co', 'client', 'tm']); }
}

class DataManager {
    private $pdo;

    public function __construct() {
        $this->pdo = getDbConnection();
    }

    public function logActivity(string $action, string $description, string $target = ''): void {
        try {
            $stmt = $this->pdo->prepare("INSERT INTO activity_log (user, action, details, ip_address) VALUES (?, ?, ?, ?)");
            $stmt->execute([
                $_SESSION['username'] ?? 'system',
                $action,
                $description . ($target ? " (Target: $target)" : ""),
                $_SERVER['REMOTE_ADDR']
            ]);
        } catch (Exception $e) {
            // Silently fail logging if DB issue to avoid breaking main flow
        }
    }

    public function getUserStats() {
        $stats = ['total' => 0, 'active' => 0, 'suspended' => 0];
        try {
            $stmt = $this->pdo->query("SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN status = 'suspended' THEN 1 ELSE 0 END) as suspended
                FROM users");
            $result = $stmt->fetch();
            $stats['total'] = $result['total'] ?? 0;
            $stats['active'] = $result['active'] ?? 0;
            $stats['suspended'] = $result['suspended'] ?? 0;
        } catch (Exception $e) {}
        return $stats;
    }

    public function getUsersPaginated($page, $roleFilter, $search) {
        $limit = Config::ITEMS_PER_PAGE;
        $offset = ($page - 1) * $limit;
        
        $sql = "SELECT id, username, full_name, email, role, status, last_login, zone_id, area_id, branch_id FROM users WHERE 1=1";
        $params = [];

        if (!empty($roleFilter)) {
            $sql .= " AND role = ?";
            $params[] = $roleFilter;
        }

        if (!empty($search)) {
            $sql .= " AND (username LIKE ? OR full_name LIKE ? OR email LIKE ?)";
            $term = "%$search%";
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
        }

        // Count Query
        $countSql = str_replace("SELECT id, username, full_name, email, role, status, last_login, zone_id, area_id, branch_id", "SELECT COUNT(*)", $sql);
        $stmt = $this->pdo->prepare($countSql);
        $stmt->execute($params);
        $totalItems = $stmt->fetchColumn();

        // Data Query
        $sql .= " ORDER BY created_at DESC LIMIT $limit OFFSET $offset";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $users = $stmt->fetchAll();

        return [
            'users' => $users,
            'total_pages' => ceil($totalItems / $limit)
        ];
    }
    
    // Fetch hierarchy for JS
    public function getHierarchy() {
        $data = ['zones' => [], 'areas' => [], 'branches' => []];
        $data['zones'] = $this->pdo->query("SELECT id, name FROM zones ORDER BY name")->fetchAll();
        $data['areas'] = $this->pdo->query("SELECT id, name, zone_id FROM areas ORDER BY name")->fetchAll();
        $data['branches'] = $this->pdo->query("SELECT id, name, area_id FROM branches ORDER BY name")->fetchAll();
        return $data;
    }
}

class UserOperations {
    private DataManager $dm;
    private $pdo;

    public function __construct(DataManager $dm) { 
        $this->dm = $dm; 
        $this->pdo = getDbConnection();
    }
    
    public function updateUserRole($username, $role, $hierarchyData = []) {
        if (!SecurityValidator::validateRole($role)) return ['success'=>false, 'message'=>'Invalid role'];

        $zoneId = $hierarchyData['zone_id'] ?? null;
        $areaId = $hierarchyData['area_id'] ?? null;
        $branchId = $hierarchyData['branch_id'] ?? null;

        // Validation logic for hierarchy based on role can be added here
        if (!in_array($role, ['zm', 'am', 'bm', 'co', 'tm'])) {
            $zoneId = $areaId = $branchId = null;
        }

        try {
            $stmt = $this->pdo->prepare("UPDATE users SET role = ?, zone_id = ?, area_id = ?, branch_id = ?, updated_at = NOW() WHERE username = ?");
            $stmt->execute([$role, $zoneId, $areaId, $branchId, $username]);
            
            if ($stmt->rowCount() > 0) {
                $this->dm->logActivity('update_role', "Updated role to $role", $username);
                return ['success'=>true, 'message'=>'Role updated successfully'];
            }
            return ['success'=>false, 'message'=>'No changes made or user not found'];
        } catch (Exception $e) {
            return ['success'=>false, 'message'=>'Database error: ' . $e->getMessage()];
        }
    }

    public function toggleUserStatus($username) {
        try {
            // Get current status
            $stmt = $this->pdo->prepare("SELECT status FROM users WHERE username = ?");
            $stmt->execute([$username]);
            $current = $stmt->fetchColumn();

            if (!$current) return ['success'=>false, 'message'=>'User not found'];

            $newStatus = ($current === 'active') ? 'suspended' : 'active';
            
            $stmt = $this->pdo->prepare("UPDATE users SET status = ?, updated_at = NOW() WHERE username = ?");
            $stmt->execute([$newStatus, $username]);
            
            $this->dm->logActivity('toggle_status', "Changed status to $newStatus", $username);
            return ['success'=>true, 'message'=>"User is now $newStatus"];
        } catch (Exception $e) {
            return ['success'=>false, 'message'=>'Database error'];
        }
    }

    public function resetPassword($username) {
        try {
            // Check existence
            $stmt = $this->pdo->prepare("SELECT id FROM users WHERE username = ?");
            $stmt->execute([$username]);
            if (!$stmt->fetch()) return ['success'=>false, 'message'=>'User not found'];

            $pw = substr(str_shuffle('ABCDEFGHJKLMNPQRSTUVWXYZ23456789!@#$'), 0, 10);
            $hashed = password_hash($pw, PASSWORD_ARGON2ID);
            
            // Note: Schema doesn't have force_password_change, adding generic note or ignoring logic
            $stmt = $this->pdo->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE username = ?");
            $stmt->execute([$hashed, $username]);
            
            $this->dm->logActivity('reset_password', "Reset password", $username);
            return ['success'=>true, 'message'=>'Password reset successfully', 'new_password'=>$pw];
        } catch (Exception $e) {
            return ['success'=>false, 'message'=>'Database error'];
        }
    }

    public function deleteUser($username) {
        // Prevent deleting self or last admin
        if ($username === ($_SESSION['username'] ?? '')) {
            return ['success'=>false, 'message'=>'Cannot delete yourself'];
        }

        try {
            $this->pdo->beginTransaction();
            
            // Optional: Check if user has associated records (loans, etc) that might block deletion via FK
            // For now, assuming CASCADE or standard delete
            
            $stmt = $this->pdo->prepare("DELETE FROM users WHERE username = ?");
            $stmt->execute([$username]);
            
            if ($stmt->rowCount() > 0) {
                $this->pdo->commit();
                $this->dm->logActivity('delete_user', "Deleted user account", $username);
                return ['success'=>true, 'message'=>'User deleted successfully'];
            } else {
                $this->pdo->rollBack();
                return ['success'=>false, 'message'=>'User not found'];
            }
        } catch (Exception $e) {
            $this->pdo->rollBack();
            return ['success'=>false, 'message'=>'Delete failed: ' . $e->getMessage()];
        }
    }

    public function bulkUpdateUsers($updates, $hierarchyData = []) {
        $success = 0;
        $failed = 0;
        
        $zoneId = $hierarchyData['zone_id'] ?? null;
        $areaId = $hierarchyData['area_id'] ?? null;
        $branchId = $hierarchyData['branch_id'] ?? null;

        try {
            $this->pdo->beginTransaction();
            $stmt = $this->pdo->prepare("UPDATE users SET role = ?, zone_id = ?, area_id = ?, branch_id = ?, updated_at = NOW() WHERE username = ?");

            foreach ($updates as $username => $data) {
                $role = $data['role'];
                
                // Logic to clear hierarchy if role doesn't support it
                $z = $zoneId; $a = $areaId; $b = $branchId;
                if (!in_array($role, ['zm', 'am', 'bm', 'co', 'tm'])) {
                    $z = $a = $b = null;
                }

                if($stmt->execute([$role, $z, $a, $b, $username])) {
                    $success++;
                } else {
                    $failed++;
                }
            }
            $this->pdo->commit();
            $this->dm->logActivity('bulk_update', "Updated $success users");
            return ['success'=>$success, 'failed'=>$failed];
        } catch (Exception $e) {
            $this->pdo->rollBack();
            return ['success'=>0, 'failed'=>count($updates), 'message'=>$e->getMessage()];
        }
    }
}

// --- 2. INITIALIZATION ---
SecurityValidator::validateSession();
$dm = new DataManager();
$userOps = new UserOperations($dm);

$base_path = '../';
$full_name = $_SESSION['full_name'] ?? 'Admin';
$current_username = $_SESSION['username'] ?? '';
$current_role = $_SESSION['role'] ?? 'admin';

// User Stats
$stats = $dm->getUserStats();
$total_users = $stats['total'];
$active_users = $stats['active'];
$suspended_users = $stats['suspended'];

$availableRoles = ['admin'=>'Admin', 'tm'=>'TM', 'zm'=>'ZM', 'dzm'=>'DZM', 'am'=>'AM', 'bm'=>'BM', 'co'=>'CO', 'client'=>'Client'];

// Fetch logged in user profile pic
$pdo = getDbConnection();
$stmt = $pdo->prepare("SELECT profile_pic FROM users WHERE username = ?");
$stmt->execute([$current_username]);
$u_data = $stmt->fetch();
$profile_pic_path = '';
$has_profile_pic = false;
if ($u_data && !empty($u_data['profile_pic']) && $u_data['profile_pic'] !== 'default_avatar.png') {
    $profile_pic_path = $base_path . 'uploads/' . $u_data['profile_pic'];
    $has_profile_pic = true;
}

// --- 3. AJAX HANDLERS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        header('Content-Type: application/json');
        
        // Pagination
        if ($_POST['action'] === 'get_page_data') {
            $page = intval($_POST['page'] ?? 1);
            $roleFilter = $_POST['role_filter'] ?? '';
            $search = trim($_POST['search'] ?? '');

            $result = $dm->getUsersPaginated($page, $roleFilter, $search);
            
            echo json_encode([
                'success' => true,
                'html' => renderTableRows($result['users'], $availableRoles),
                'pagination' => renderPagination($page, $result['total_pages'])
            ]);
            exit();
        }

        // Actions
        $u = SecurityValidator::sanitizeInput($_POST['username'] ?? '');
        $act = $_POST['action'];
        $res = ['success' => false, 'message' => 'Invalid action'];

        if ($act === 'role_update') {
            $hierarchyData = [];
            if (isset($_POST['zone_id'])) $hierarchyData['zone_id'] = $_POST['zone_id'] ?: null;
            if (isset($_POST['area_id'])) $hierarchyData['area_id'] = $_POST['area_id'] ?: null;
            if (isset($_POST['branch_id'])) $hierarchyData['branch_id'] = $_POST['branch_id'] ?: null;
            $res = $userOps->updateUserRole($u, $_POST['new_role'], $hierarchyData);
        }
        elseif ($act === 'toggle_status') $res = $userOps->toggleUserStatus($u);
        elseif ($act === 'reset_password') $res = $userOps->resetPassword($u);
        elseif ($act === 'delete_user') $res = $userOps->deleteUser($u);
        
        echo json_encode($res);
        exit();
    }

    if (isset($_POST['bulk_update'])) {
        header('Content-Type: application/json');
        $sel = json_decode($_POST['selected_users']??'[]', true);
        $role = SecurityValidator::sanitizeInput($_POST['new_role']??'');
        $hierarchyData = [];
        
        if (isset($_POST['hierarchy_data'])) {
            $hierarchyData = json_decode($_POST['hierarchy_data'], true) ?? [];
        }
        
        if (empty($sel) || !SecurityValidator::validateRole($role)) { 
            echo json_encode(['success'=>0, 'message'=>'Invalid data']); exit(); 
        }
        
        $upd = []; 
        foreach($sel as $u) $upd[$u] = ['role'=>$role];
        echo json_encode($userOps->bulkUpdateUsers($upd, $hierarchyData)); 
        exit();
    }
}

// Helper Render Functions
function getInitials($name) {
    if(empty($name)) return 'U';
    return strtoupper(substr($name, 0, 2));
}

function renderTableRows($users, $roles) {
    ob_start();
    if(empty($users)) { echo '<tr><td colspan="6" style="text-align:center; padding:2rem; color:var(--text-muted)">No users found matching your criteria.</td></tr>'; }
    else {
        foreach ($users as $u) {
            $uname = htmlspecialchars($u['username']);
            $role = htmlspecialchars($u['role']);
            $active = ($u['status'] === 'active');
            $last = !empty($u['last_login']) ? date('M j, Y', strtotime($u['last_login'])) : 'Never';
            $fname = htmlspecialchars($u['full_name'] ?? ucfirst($uname));
            
            // Prepare JSON data for Edit Modal
            $json = htmlspecialchars(json_encode([
                'username'=>$uname, 
                'role'=>$role, 
                'status'=>$u['status'],
                'zone_id'=>$u['zone_id']??null,
                'area_id'=>$u['area_id']??null,
                'branch_id'=>$u['branch_id']??null
            ]), ENT_QUOTES, 'UTF-8');
            ?>
            <tr class="user-row" id="user-<?php echo $uname; ?>" data-user='<?php echo $json; ?>'>
                <td style="width:40px"><input type="checkbox" class="user-chk" value="<?php echo $uname; ?>"></td>
                <td>
                    <div class="user-info-cell">
                        <div class="avatar-sm"><?php echo getInitials($uname); ?></div>
                        <div>
                            <div class="u-name"><?php echo $fname; ?></div>
                            <div class="u-email"><?php echo htmlspecialchars($u['email']??$uname); ?></div>
                        </div>
                    </div>
                </td>
                <td><span class="badge badge-<?php echo $role; ?>"><?php echo $roles[$role]??ucfirst($role); ?></span></td>
                <td>
                    <span class="status-dot <?php echo $active?'active':'suspended'; ?>"></span>
                    <span class="<?php echo $active?'text-success':'text-danger'; ?>"><?php echo $active?'Active':'Suspended'; ?></span>
                </td>
                <td class="text-muted text-sm"><?php echo $last; ?></td>
                <td>
                    <div class="action-group">
                        <button class="icon-btn edit-role-btn" data-user="<?php echo $uname; ?>" title="Edit Role"><i class="fas fa-pen"></i></button>
                        <button class="icon-btn" onclick="performAction('reset_password', '<?php echo $uname; ?>')" title="Reset Password"><i class="fas fa-key"></i></button>
                        <button class="icon-btn <?php echo $active?'btn-suspend':'btn-activate'; ?>" onclick="performAction('toggle_status', '<?php echo $uname; ?>')" title="Toggle Status"><i class="fas fa-<?php echo $active?'ban':'check'; ?>"></i></button>
                        <button class="icon-btn btn-delete" onclick="performAction('delete_user', '<?php echo $uname; ?>')" title="Delete"><i class="fas fa-trash"></i></button>
                    </div>
                </td>
            </tr>
            <?php
        }
    }
    return ob_get_clean();
}

function renderPagination($current, $total) {
    if ($total <= 1) return '';
    ob_start();
    ?>
    <span class="page-info">Page <?php echo $current; ?> of <?php echo $total; ?></span>
    <div class="page-controls">
        <button onclick="loadPage(<?php echo max(1, $current-1); ?>)" <?php echo $current<=1?'disabled':''; ?>><i class="fas fa-chevron-left"></i></button>
        <button onclick="loadPage(<?php echo min($total, $current+1); ?>)" <?php echo $current>=$total?'disabled':''; ?>><i class="fas fa-chevron-right"></i></button>
    </div>
    <?php
    return ob_get_clean();
}
?>
<!DOCTYPE html>
<html lang="en" class="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management | CUPAD Admin</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />
    <style>
        :root {
            --primary: #2563eb; 
            --primary-dark: #1d4ed8;
            --success: #059669; 
            --danger: #dc2626; 
            --warning: #d97706;
            
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
            --danger: #f87171;
        }

        * { box-sizing: border-box; outline: none; }
        body { font-family: 'Plus Jakarta Sans', sans-serif; background: var(--bg-body); color: var(--text-main); margin: 0; padding-top: var(--nav-height); transition: 0.3s; }
        
        /* HEADER (Matched to Dashboard) */
        .main-header { position: fixed; top: 0; left: 0; right: 0; height: var(--nav-height); background: rgba(255,255,255,0.85); backdrop-filter: blur(10px); border-bottom: 1px solid var(--border); z-index: 50; }
        html.dark .main-header { background: rgba(15, 23, 42, 0.85); }
        .navbar { max-width: 1400px; margin: 0 auto; padding: 0 1.5rem; display: flex; align-items: center; justify-content: space-between; height: 100%; }
        .logo { display: flex; align-items: center; gap: 0.75rem; font-weight: 800; font-size: 1.35rem; color: var(--primary); text-decoration: none; }
        .logo img { height: 38px; }
        .nav-right { display: flex; align-items: center; gap: 1rem; }
        
        .icon-btn { width: 36px; height: 36px; border-radius: 50%; border: none; background: transparent; color: var(--text-muted); cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; transition: 0.2s; }
        .icon-btn:hover { background: var(--bg-body); color: var(--text-main); }

        /* User Dropdown */
        .user-dropdown-wrap { position: relative; }
        .user-pill { 
            display: flex; align-items: center; gap: 0.75rem; padding: 4px 8px 4px 4px; 
            border: 1px solid var(--border); border-radius: 99px; background: var(--bg-surface); 
            cursor: pointer; transition: all 0.2s;
        }
        .user-pill:hover { border-color: var(--primary); }
        .user-avatar { width: 34px; height: 34px; border-radius: 50%; object-fit: cover; }
        .user-avatar-fallback { width: 34px; height: 34px; border-radius: 50%; background: var(--bg-body); color: var(--text-muted); display: flex; align-items: center; justify-content: center; font-size: 1rem; border: 1px solid var(--border); }
        .user-info { display: flex; flex-direction: column; line-height: 1.1; padding-right: 0.5rem; }
        .user-name { font-weight: 600; font-size: 0.85rem; }
        .user-role { font-size: 0.7rem; color: var(--text-muted); text-transform: uppercase; }
        
        .dropdown-menu {
            position: absolute; top: 120%; right: 0;
            background: var(--bg-surface); border: 1px solid var(--border);
            border-radius: var(--radius-md); box-shadow: var(--shadow-md);
            min-width: 200px; display: none; z-index: 1000;
            flex-direction: column; overflow: hidden; animation: fadeIn 0.2s ease;
        }
        .dropdown-menu.show { display: flex; }
        .dropdown-item {
            padding: 0.75rem 1rem; display: flex; align-items: center; gap: 0.75rem;
            font-size: 0.9rem; color: var(--text-main); transition: 0.2s; text-decoration: none;
        }
        .dropdown-item:hover { background: var(--bg-body); color: var(--primary); }
        .dropdown-divider { height: 1px; background: var(--border); margin: 0; }
        .text-danger { color: var(--danger) !important; }

        /* Main Content */
        .container { max-width: 1400px; margin: 0 auto; padding: 2rem 1.5rem; }
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; }
        
        /* Stats */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 2rem; }
        .stat-card { background: var(--bg-surface); padding: 1.5rem; border-radius: var(--radius-lg); border: 1px solid var(--border); display: flex; align-items: center; gap: 1rem; }
        .stat-icon { width: 48px; height: 48px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; }
        .stat-details h3 { margin: 0; font-size: 1.5rem; font-weight: 700; }
        .stat-details p { margin: 0; color: var(--text-muted); font-size: 0.875rem; }

        /* Table Card */
        .card { background: var(--bg-surface); border: 1px solid var(--border); border-radius: var(--radius-lg); overflow: hidden; box-shadow: var(--shadow-sm); }
        
        .toolbar { padding: 1.5rem; border-bottom: 1px solid var(--border); display: flex; gap: 1rem; flex-wrap: wrap; align-items: center; }
        .search-wrap { position: relative; flex: 1; max-width: 350px; }
        .search-wrap i { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--text-muted); }
        .search-input { width: 100%; padding: 0.65rem 1rem 0.65rem 2.2rem; border-radius: 8px; border: 1px solid var(--border); background: var(--bg-body); color: var(--text-main); font-family: inherit; }
        .filter-select { padding: 0.65rem 2rem 0.65rem 1rem; border-radius: 8px; border: 1px solid var(--border); background: var(--bg-body); color: var(--text-main); appearance: none; background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e"); background-repeat: no-repeat; background-position: right 0.7rem center; background-size: 1em; }

        .table-responsive { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; white-space: nowrap; }
        th { text-align: left; padding: 1rem 1.5rem; font-size: 0.75rem; text-transform: uppercase; color: var(--text-muted); font-weight: 600; border-bottom: 1px solid var(--border); background: var(--bg-body); }
        td { padding: 1rem 1.5rem; border-bottom: 1px solid var(--border); vertical-align: middle; }
        tr:last-child td { border-bottom: none; }
        
        .user-info-cell { display: flex; align-items: center; gap: 0.75rem; }
        .avatar-sm { width: 36px; height: 36px; border-radius: 50%; background: var(--primary); color: white; display: flex; align-items: center; justify-content: center; font-size: 0.85rem; font-weight: 600; }
        .u-name { font-weight: 600; font-size: 0.9rem; }
        .u-email { font-size: 0.8rem; color: var(--text-muted); }

        .badge { padding: 0.25rem 0.6rem; border-radius: 99px; font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.02em; }
        .badge-admin { background: #dbeafe; color: #1e40af; }
        .badge-co, .badge-bm, .badge-am { background: #dcfce7; color: #166534; }
        .badge-client { background: #f1f5f9; color: #475569; }
        
        .status-dot { display: inline-block; width: 6px; height: 6px; border-radius: 50%; margin-right: 6px; }
        .status-dot.active { background: var(--success); }
        .status-dot.suspended { background: var(--danger); }
        .text-success { color: var(--success); font-weight: 500; font-size: 0.9rem; }
        .text-danger { color: var(--danger); font-weight: 500; font-size: 0.9rem; }

        .action-group { display: flex; gap: 0.25rem; }
        .action-group .icon-btn { width: 32px; height: 32px; font-size: 0.9rem; }
        .action-group .icon-btn:hover { background: var(--bg-body); color: var(--primary); }
        .btn-delete:hover { color: var(--danger) !important; background: #fee2e2 !important; }

        /* Pagination */
        .pagination { display: flex; justify-content: space-between; align-items: center; padding: 1rem 1.5rem; background: var(--bg-body); border-top: 1px solid var(--border); }
        .page-controls { display: flex; gap: 0.5rem; }
        .page-controls button { width: 32px; height: 32px; border-radius: 6px; border: 1px solid var(--border); background: var(--bg-surface); cursor: pointer; color: var(--text-muted); display: flex; align-items: center; justify-content: center; }
        .page-controls button:hover:not(:disabled) { background: var(--primary); color: white; border-color: var(--primary); }
        .page-controls button:disabled { opacity: 0.5; cursor: default; }

        /* Floating Bulk Action Bar */
        .bulk-bar { position: fixed; bottom: 2rem; left: 50%; transform: translateX(-50%) translateY(200%); background: var(--text-main); color: white; padding: 0.75rem 1.5rem; border-radius: 99px; display: flex; align-items: center; gap: 1.5rem; box-shadow: 0 10px 25px rgba(0,0,0,0.2); transition: 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275); z-index: 90; }
        .bulk-bar.active { transform: translateX(-50%) translateY(0); }
        .bulk-btn { background: white; color: var(--text-main); border: none; padding: 0.4rem 1rem; border-radius: 99px; font-weight: 600; cursor: pointer; }
        .bulk-close { background: none; border: none; color: rgba(255,255,255,0.7); cursor: pointer; }

        /* Modal */
        .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 100; display: none; align-items: center; justify-content: center; backdrop-filter: blur(4px); }
        .modal { background: var(--bg-surface); width: 90%; max-width: 400px; padding: 2rem; border-radius: var(--radius-lg); box-shadow: var(--shadow-md); animation: slideUp 0.3s; }
        @keyframes slideUp { from { transform: translateY(20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }

        /* Toast */
        .toast { position: fixed; top: 90px; right: 20px; padding: 1rem 1.5rem; background: var(--bg-surface); border-left: 4px solid var(--primary); box-shadow: var(--shadow-md); border-radius: var(--radius-md); transform: translateX(120%); transition: 0.3s; z-index: 200; display: flex; align-items: center; gap: 1rem; }
        .toast.show { transform: translateX(0); }
        
        @media(max-width: 768px) { .stats-grid { grid-template-columns: 1fr 1fr; } .toolbar { flex-direction: column; align-items: stretch; } }
    </style>
</head>
<body>

    <!-- Header (Identical to Dashboard) -->
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
                            <span class="user-role"><?php echo ucfirst(htmlspecialchars($current_role)); ?></span>
                        </div>
                        <i class="fas fa-chevron-down" style="font-size: 0.7rem; color:var(--text-muted)"></i>
                    </div>

                    <div class="dropdown-menu" id="userDropdown">
                        <a href="profile.php" class="dropdown-item"><i class="fas fa-user-circle"></i> My Profile</a>
                        <div class="dropdown-divider"></div>
                        <a href="../logout.php" class="dropdown-item text-danger"><i class="fas fa-sign-out-alt"></i> Logout</a>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <main class="container">
        
        <div class="page-header">
            <div>
                <h1 style="margin:0; font-size:1.5rem; font-weight:700;">User Management</h1>
                <p style="margin:0.25rem 0 0; color:var(--text-muted);">Manage roles, status and credentials.</p>
            </div>
            <a href="dashboard.php" class="icon-btn" style="border:1px solid var(--border); width:auto; padding:0 1rem; border-radius:8px; font-size:0.9rem; gap:0.5rem;">
                <i class="fas fa-arrow-left"></i> Dashboard
            </a>
        </div>

        <!-- Stats -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon" style="background: #eff6ff; color: #3b82f6;"><i class="fas fa-users"></i></div>
                <div class="stat-details"><h3><?php echo $total_users; ?></h3><p>Total Users</p></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background: #ecfdf5; color: #10b981;"><i class="fas fa-user-check"></i></div>
                <div class="stat-details"><h3><?php echo $active_users; ?></h3><p>Active</p></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background: #fef2f2; color: #ef4444;"><i class="fas fa-user-slash"></i></div>
                <div class="stat-details"><h3><?php echo $suspended_users; ?></h3><p>Suspended</p></div>
            </div>
        </div>

        <!-- Main Card -->
        <div class="card">
            <!-- Toolbar -->
            <div class="toolbar">
                <div class="search-wrap">
                    <i class="fas fa-search"></i>
                    <input type="text" id="searchInput" class="search-input" placeholder="Search by name, username...">
                </div>
                <select id="roleFilter" class="filter-select">
                    <option value="">All Roles</option>
                    <?php foreach($availableRoles as $k=>$v) echo "<option value='$k'>$v</option>"; ?>
                </select>
            </div>

            <!-- Table -->
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th style="width:40px"><input type="checkbox" id="selectAll"></th>
                            <th>User Identity</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Last Login</th>
                            <th style="text-align:right">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="userTableBody">
                        <!-- Loaded via JS -->
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div class="pagination" id="pagination">
                <!-- Loaded via JS -->
            </div>
        </div>
    </main>

    <!-- Floating Bulk Bar -->
    <div id="bulkBar" class="bulk-bar">
        <span><b id="selCount">0</b> selected</span>
        <select id="bulkRoleSelect" style="background:rgba(255,255,255,0.1); border:1px solid rgba(255,255,255,0.2); color:white; padding:0.25rem 0.5rem; border-radius:4px;">
            <option value="">Change Role...</option>
            <?php foreach($availableRoles as $k=>$v) echo "<option value='$k'>$v</option>"; ?>
        </select>
        <button id="bulkHierarchyBtn" class="bulk-btn" style="display:none;">Set Hierarchy</button>
        <button id="bulkApplyBtn" class="bulk-btn">Apply</button>
        <button id="bulkCancelBtn" class="bulk-close"><i class="fas fa-times"></i></button>
    </div>

    <!-- Edit Role Modal -->
    <div id="roleModal" class="modal-overlay">
        <div class="modal">
            <h3 style="margin-top:0">Edit Role</h3>
            <p style="color:var(--text-muted); margin-bottom:1.5rem">Change role for <b id="modalUser"></b></p>
            <select id="modalRole" class="search-input" style="margin-bottom:1rem">
                <?php foreach($availableRoles as $k=>$v) echo "<option value='$k'>$v</option>"; ?>
            </select>
            
            <!-- Hierarchy Selection -->
            <div id="hierarchySection" style="display:none; margin-bottom:1rem;">
                <div id="zoneSection" style="margin-bottom:0.75rem; display:none;">
                    <label style="display:block; font-size:0.85rem; color:var(--text-muted); margin-bottom:0.25rem;">Zone</label>
                    <select id="modalZone" class="search-input">
                        <option value="">Select Zone</option>
                    </select>
                </div>
                <div id="areaSection" style="margin-bottom:0.75rem; display:none;">
                    <label style="display:block; font-size:0.85rem; color:var(--text-muted); margin-bottom:0.25rem;">Area</label>
                    <select id="modalArea" class="search-input">
                        <option value="">Select Area</option>
                    </select>
                </div>
                <div id="branchSection" style="display:none;">
                    <label style="display:block; font-size:0.85rem; color:var(--text-muted); margin-bottom:0.25rem;">Branch</label>
                    <select id="modalBranch" class="search-input">
                        <option value="">Select Branch</option>
                    </select>
                </div>
            </div>
            
            <div style="display:flex; justify-content:flex-end; gap:0.5rem">
                <button onclick="closeModal()" class="icon-btn" style="width:auto; padding:0 1rem; border:1px solid var(--border); border-radius:8px; font-size:0.9rem">Cancel</button>
                <button id="saveRoleBtn" style="background:var(--primary); color:white; border:none; padding:0.5rem 1.5rem; border-radius:8px; cursor:pointer; font-weight:600">Save</button>
            </div>
        </div>
    </div>

    <!-- Toast -->
    <div id="toast" class="toast">
        <i class="fas fa-info-circle"></i>
        <span id="toastMsg">Action successful</span>
    </div>

    <script>
        // State
        let currentPage = 1;
        let selectedUsers = new Set();
        let currentSearch = '';
        let currentFilter = '';
        let hierarchyData = { zones: [], areas: [], branches: [] };

        // Init
        document.addEventListener('DOMContentLoaded', () => {
            loadData();
            initHeader();
            
            // Populate Hierarchy Data from PHP via hidden JSON or AJAX? 
            // Using DataManager->getHierarchy logic inside PHP to inject into JS variable
            <?php 
            $dm = new DataManager();
            echo "hierarchyData = " . json_encode($dm->getHierarchy()) . ";"; 
            ?>
        });

        // Header Logic
        function initHeader() {
            // Dropdown
            const trigger = document.getElementById('userDropdownTrigger');
            const menu = document.getElementById('userDropdown');
            trigger.onclick = (e) => { e.stopPropagation(); menu.classList.toggle('show'); };
            document.onclick = (e) => { if(!trigger.contains(e.target)) menu.classList.remove('show'); };
            
            // Theme
            const toggle = document.getElementById('themeToggle');
            const html = document.documentElement;
            if(localStorage.getItem('theme') === 'dark') { html.classList.add('dark'); toggle.innerHTML = '<i class="fas fa-sun"></i>'; }
            toggle.onclick = () => {
                const isDark = html.classList.toggle('dark');
                html.classList.toggle('light', !isDark);
                localStorage.setItem('theme', isDark?'dark':'light');
                toggle.innerHTML = isDark ? '<i class="fas fa-sun"></i>' : '<i class="fas fa-moon"></i>';
            };
        }

        // Data Loading
        async function loadData(page = 1) {
            currentPage = page;
            const tbody = document.getElementById('userTableBody');
            tbody.innerHTML = '<tr><td colspan="6" style="text-align:center; padding:2rem;"><i class="fas fa-spinner fa-spin"></i> Loading...</td></tr>';
            
            const fd = new FormData();
            fd.append('csrf_token', '<?php echo SecurityValidator::generateCSRFToken(); ?>');
            fd.append('action', 'get_page_data');
            fd.append('page', page);
            fd.append('search', currentSearch);
            fd.append('role_filter', currentFilter);

            try {
                const res = await fetch('', { method: 'POST', body: fd }).then(r=>r.json());
                if(res.success) {
                    tbody.innerHTML = res.html;
                    document.getElementById('pagination').innerHTML = res.pagination;
                    restoreSelection();
                }
            } catch(e) { console.error(e); }
        }

        // Search & Filter
        const debounce = (fn, delay) => { let id; return (...args) => { clearTimeout(id); id = setTimeout(() => fn(...args), delay); } };
        document.getElementById('searchInput').addEventListener('input', debounce((e) => {
            currentSearch = e.target.value;
            loadData(1);
        }, 300));
        document.getElementById('roleFilter').addEventListener('change', (e) => {
            currentFilter = e.target.value;
            loadData(1);
        });

        // Actions
        window.loadPage = (p) => loadData(p);
        
        async function performAction(action, username, extraData = {}) {
            if(action === 'delete_user' && !confirm('Are you sure you want to delete this user?')) return;
            
            const fd = new FormData();
            fd.append('csrf_token', '<?php echo SecurityValidator::generateCSRFToken(); ?>');
            fd.append('action', action);
            fd.append('username', username);
            for(let k in extraData) fd.append(k, extraData[k]);

            try {
                const res = await fetch('', { method: 'POST', body: fd }).then(r=>r.json());
                showToast(res.message, res.success?'success':'danger');
                if(res.success) {
                    if(action === 'reset_password') alert('New Password: ' + res.new_password);
                    loadData(currentPage);
                }
            } catch(e) { showToast('Server Error', 'danger'); }
        }

        // Modal Logic
        let modalUser = '';
        document.body.addEventListener('click', (e) => {
            if(e.target.closest('.edit-role-btn')) {
                const btn = e.target.closest('.edit-role-btn');
                const row = btn.closest('tr');
                const data = JSON.parse(row.dataset.user);
                
                modalUser = data.username;
                document.getElementById('modalUser').textContent = data.username;
                document.getElementById('modalRole').value = data.role;
                updateHierarchyVisibility(data.role, data);
                document.getElementById('roleModal').style.display = 'flex';
            }
        });
        
        // Role change handler
        document.getElementById('modalRole').addEventListener('change', (e) => {
            updateHierarchyVisibility(e.target.value);
        });
        
        function updateHierarchyVisibility(role, userData = null) {
            const hierarchySection = document.getElementById('hierarchySection');
            const zoneSection = document.getElementById('zoneSection');
            const areaSection = document.getElementById('areaSection');
            const branchSection = document.getElementById('branchSection');
            
            // Hide all sections first
            hierarchySection.style.display = 'none';
            zoneSection.style.display = 'none';
            areaSection.style.display = 'none';
            branchSection.style.display = 'none';
            
            // Show relevant sections based on role
            if(['zm', 'am', 'bm', 'co', 'tm'].includes(role)) {
                hierarchySection.style.display = 'block';
                zoneSection.style.display = 'block';
                populateZones(userData?.zone_id);
                
                if(['am', 'bm', 'co'].includes(role)) {
                    areaSection.style.display = 'block';
                    if(userData?.zone_id) populateAreas(userData.zone_id, userData?.area_id);
                    
                    if(['bm', 'co'].includes(role)) {
                        branchSection.style.display = 'block';
                        if(userData?.area_id) populateBranches(userData.area_id, userData?.branch_id);
                    }
                }
            }
        }
        
        function populateZones(selectedZoneId = null) {
            const zoneSelect = document.getElementById('modalZone');
            zoneSelect.innerHTML = '<option value="">Select Zone</option>';
            
            hierarchyData.zones.forEach(zone => {
                const option = document.createElement('option');
                option.value = zone.id;
                option.textContent = zone.name;
                if(selectedZoneId === zone.id) option.selected = true;
                zoneSelect.appendChild(option);
            });
        }
        
        function populateAreas(zoneId, selectedAreaId = null) {
            const areaSelect = document.getElementById('modalArea');
            areaSelect.innerHTML = '<option value="">Select Area</option>';
            
            const areas = hierarchyData.areas.filter(area => area.zone_id === zoneId);
            areas.forEach(area => {
                const option = document.createElement('option');
                option.value = area.id;
                option.textContent = area.name;
                if(selectedAreaId === area.id) option.selected = true;
                areaSelect.appendChild(option);
            });
        }
        
        function populateBranches(areaId, selectedBranchId = null) {
            const branchSelect = document.getElementById('modalBranch');
            branchSelect.innerHTML = '<option value="">Select Branch</option>';
            
            const branches = hierarchyData.branches.filter(branch => branch.area_id === areaId);
            branches.forEach(branch => {
                const option = document.createElement('option');
                option.value = branch.id;
                option.textContent = branch.name;
                if(selectedBranchId === branch.id) option.selected = true;
                branchSelect.appendChild(option);
            });
        }
        
        // Zone change handler
        document.getElementById('modalZone').addEventListener('change', (e) => {
            const zoneId = e.target.value;
            if(zoneId) {
                populateAreas(zoneId);
            } else {
                document.getElementById('modalArea').innerHTML = '<option value="">Select Area</option>';
                document.getElementById('modalBranch').innerHTML = '<option value="">Select Branch</option>';
            }
        });
        
        // Area change handler
        document.getElementById('modalArea').addEventListener('change', (e) => {
            const areaId = e.target.value;
            if(areaId) {
                populateBranches(areaId);
            } else {
                document.getElementById('modalBranch').innerHTML = '<option value="">Select Branch</option>';
            }
        });
        
        document.getElementById('saveRoleBtn').onclick = () => {
            const role = document.getElementById('modalRole').value;
            const extraData = { new_role: role };
            
            // Add hierarchy data if applicable
            if(['zm', 'am', 'bm', 'co'].includes(role)) {
                const zoneId = document.getElementById('modalZone').value;
                if(zoneId) extraData.zone_id = zoneId;
                
                if(['am', 'bm', 'co'].includes(role)) {
                    const areaId = document.getElementById('modalArea').value;
                    if(areaId) extraData.area_id = areaId;
                    
                    if(['bm', 'co'].includes(role)) {
                        const branchId = document.getElementById('modalBranch').value;
                        if(branchId) extraData.branch_id = branchId;
                    }
                }
            }
            
            performAction('role_update', modalUser, extraData);
            closeModal();
        };
        
        function closeModal() { 
            document.getElementById('roleModal').style.display = 'none';
            // Reset hierarchy selections
            document.getElementById('modalZone').value = '';
            document.getElementById('modalArea').innerHTML = '<option value="">Select Area</option>';
            document.getElementById('modalBranch').innerHTML = '<option value="">Select Branch</option>';
        }

        // Bulk Logic
        document.getElementById('userTableBody').addEventListener('change', (e) => {
            if(e.target.classList.contains('user-chk')) {
                const val = e.target.value;
                if(e.target.checked) selectedUsers.add(val);
                else selectedUsers.delete(val);
                updateBulkUI();
            }
        });
        document.getElementById('selectAll').addEventListener('change', (e) => {
            const chks = document.querySelectorAll('.user-chk');
            chks.forEach(c => {
                c.checked = e.target.checked;
                if(e.target.checked) selectedUsers.add(c.value);
                else selectedUsers.delete(c.value);
            });
            updateBulkUI();
        });
        
        function updateBulkUI() {
            const bar = document.getElementById('bulkBar');
            document.getElementById('selCount').textContent = selectedUsers.size;
            bar.classList.toggle('active', selectedUsers.size > 0);
        }
        function restoreSelection() {
            document.querySelectorAll('.user-chk').forEach(c => {
                if(selectedUsers.has(c.value)) c.checked = true;
            });
        }
        document.getElementById('bulkCancelBtn').onclick = () => {
            selectedUsers.clear();
            document.querySelectorAll('.user-chk').forEach(c => c.checked = false);
            updateBulkUI();
        };
        // Bulk role selection handler
        document.getElementById('bulkRoleSelect').addEventListener('change', (e) => {
            const role = e.target.value;
            const hierarchyBtn = document.getElementById('bulkHierarchyBtn');
            
            if(['zm', 'am', 'bm', 'co'].includes(role)) {
                hierarchyBtn.style.display = 'inline-block';
            } else {
                hierarchyBtn.style.display = 'none';
            }
        });
        
        let bulkHierarchyData = {};
        
        document.getElementById('bulkHierarchyBtn').onclick = () => {
            const role = document.getElementById('bulkRoleSelect').value;
            if(!role) return;
            
            // Create hierarchy selection modal
            const modal = document.createElement('div');
            modal.className = 'modal-overlay';
            modal.style.display = 'flex';
            modal.innerHTML = `
                <div class="modal">
                    <h3 style="margin-top:0">Set Hierarchy for ${role.toUpperCase()}</h3>
                    <div id="bulkZoneSection" style="margin-bottom:1rem;">
                        <label style="display:block; font-size:0.85rem; color:var(--text-muted); margin-bottom:0.25rem;">Zone</label>
                        <select id="bulkZone" class="search-input">
                            <option value="">Select Zone</option>
                        </select>
                    </div>
                    <div id="bulkAreaSection" style="margin-bottom:1rem; display:${['am','bm','co'].includes(role) ? 'block' : 'none'};">
                        <label style="display:block; font-size:0.85rem; color:var(--text-muted); margin-bottom:0.25rem;">Area</label>
                        <select id="bulkArea" class="search-input">
                            <option value="">Select Area</option>
                        </select>
                    </div>
                    <div id="bulkBranchSection" style="display:${['bm','co'].includes(role) ? 'block' : 'none'};">
                        <label style="display:block; font-size:0.85rem; color:var(--text-muted); margin-bottom:0.25rem;">Branch</label>
                        <select id="bulkBranch" class="search-input">
                            <option value="">Select Branch</option>
                        </select>
                    </div>
                    <div style="display:flex; justify-content:flex-end; gap:0.5rem; margin-top:1.5rem;">
                        <button onclick="this.closest('.modal-overlay').remove()" class="icon-btn" style="width:auto; padding:0 1rem; border:1px solid var(--border); border-radius:8px; font-size:0.9rem">Cancel</button>
                        <button id="saveBulkHierarchy" style="background:var(--primary); color:white; border:none; padding:0.5rem 1.5rem; border-radius:8px; cursor:pointer; font-weight:600">Save</button>
                    </div>
                </div>
            `;
            
            document.body.appendChild(modal);
            
            // Populate zones
            const zoneSelect = modal.querySelector('#bulkZone');
            hierarchyData.zones.forEach(zone => {
                const option = document.createElement('option');
                option.value = zone.id;
                option.textContent = zone.name;
                zoneSelect.appendChild(option);
            });
            
            // Zone change handler
            zoneSelect.addEventListener('change', (e) => {
                const zoneId = e.target.value;
                const areaSelect = modal.querySelector('#bulkArea');
                areaSelect.innerHTML = '<option value="">Select Area</option>';
                
                if(zoneId) {
                    const areas = hierarchyData.areas.filter(area => area.zone_id === zoneId);
                    areas.forEach(area => {
                        const option = document.createElement('option');
                        option.value = area.id;
                        option.textContent = area.name;
                        areaSelect.appendChild(option);
                    });
                }
            });
            
            // Area change handler
            modal.querySelector('#bulkArea').addEventListener('change', (e) => {
                const areaId = e.target.value;
                const branchSelect = modal.querySelector('#bulkBranch');
                branchSelect.innerHTML = '<option value="">Select Branch</option>';
                
                if(areaId) {
                    const branches = hierarchyData.branches.filter(branch => branch.area_id === areaId);
                    branches.forEach(branch => {
                        const option = document.createElement('option');
                        option.value = branch.id;
                        option.textContent = branch.name;
                        branchSelect.appendChild(option);
                    });
                }
            });
            
            // Save handler
            modal.querySelector('#saveBulkHierarchy').onclick = () => {
                bulkHierarchyData = {};
                const zoneId = modal.querySelector('#bulkZone').value;
                const areaId = modal.querySelector('#bulkArea').value;
                const branchId = modal.querySelector('#bulkBranch').value;
                
                if(zoneId) bulkHierarchyData.zone_id = zoneId;
                if(areaId) bulkHierarchyData.area_id = areaId;
                if(branchId) bulkHierarchyData.branch_id = branchId;
                
                modal.remove();
                showToast('Hierarchy settings saved', 'success');
            };
        };
        
        document.getElementById('bulkApplyBtn').onclick = async () => {
            const role = document.getElementById('bulkRoleSelect').value;
            if(!role) return showToast('Select a role', 'warning');
            
            const fd = new FormData();
            fd.append('csrf_token', '<?php echo SecurityValidator::generateCSRFToken(); ?>');
            fd.append('bulk_update', '1');
            fd.append('new_role', role);
            fd.append('selected_users', JSON.stringify([...selectedUsers]));
            
            // Add hierarchy data if set
            if(Object.keys(bulkHierarchyData).length > 0) {
                fd.append('hierarchy_data', JSON.stringify(bulkHierarchyData));
            }
            
            const res = await fetch('', {method:'POST', body:fd}).then(r=>r.json());
            showToast(res.message, res.success>0?'success':'danger');
            if(res.success > 0) {
                selectedUsers.clear();
                bulkHierarchyData = {};
                updateBulkUI();
                loadData(currentPage);
            }
        };

        // Toast
        function showToast(msg, type) {
            const t = document.getElementById('toast');
            document.getElementById('toastMsg').textContent = msg;
            t.style.borderLeftColor = `var(--${type})`;
            t.querySelector('i').className = `fas fa-${type==='success'?'check-circle':(type==='danger'?'exclamation-circle':'info-circle')}`;
            t.querySelector('i').style.color = `var(--${type})`;
            t.classList.add('show');
            setTimeout(() => t.classList.remove('show'), 3000);
        }
    </script>
</body>
</html>