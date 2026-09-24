<?php
// unions_manage.php
session_start();

// 1. Authentication Check
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: ../index.php');
    exit();
}

// 2. Database Connection
// Update these credentials to match your actual database environment
$db_host = 'localhost';
$db_name = 'cupad_db';
$db_user = 'root';
$db_pass = '';

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Database Connection Failed: " . $e->getMessage());
}

// 3. Handle Form Submissions (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $branch_id = !empty($_POST['branch_id']) ? $_POST['branch_id'] : null;

        if ($name) {
            // Generate a unique string ID (e.g., u_65c3b...) to fit varchar(50)
            $new_id = uniqid('u_', true);
            
            $stmt = $pdo->prepare("INSERT INTO unions (id, name, branch_id, description, status) VALUES (?, ?, ?, ?, 'active')");
            $stmt->execute([$new_id, $name, $branch_id, $description]);
            
            header('Location: unions_manage.php');
            exit();
        }
    } elseif ($action === 'delete' && isset($_POST['id'])) {
        $id_to_delete = $_POST['id'];
        
        // Hard delete based on request, or you could update status to 'inactive'
        $stmt = $pdo->prepare("DELETE FROM unions WHERE id = ?");
        $stmt->execute([$id_to_delete]);
        
        header('Location: unions_manage.php');
        exit();
    }
}

// 4. Fetch Data for Display (GET)

// Fetch all active branches for the dropdown
$branches = $pdo->query("SELECT id, name FROM branches WHERE status = 'active' ORDER BY name ASC")->fetchAll();

// Fetch unions with branch names
$sql = "SELECT u.id, u.name, u.description, b.name as branch_name 
        FROM unions u 
        LEFT JOIN branches b ON u.branch_id = b.id 
        ORDER BY u.created_at DESC";
$unions = $pdo->query($sql)->fetchAll();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manage Unions</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f8f8f8; margin: 0; padding: 0; }
        main { max-width: 800px; margin: 30px auto; background: #fff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); padding: 24px; }
        h1 { color: #2c3e50; border-bottom: 2px solid #f0f0f0; padding-bottom: 10px; }
        
        form { background: #f9f9f9; padding: 15px; border-radius: 6px; border: 1px solid #eee; margin-bottom: 24px; }
        .form-group { margin-bottom: 12px; }
        label { display: block; margin-bottom: 6px; font-weight: 600; color: #555; }
        
        input[type="text"], textarea, select { 
            width: 100%; 
            padding: 10px; 
            border: 1px solid #ccc; 
            border-radius: 4px; 
            box-sizing: border-box;
        }
        
        button { background: #3498db; color: #fff; border: none; border-radius: 4px; padding: 10px 20px; cursor: pointer; font-size: 14px; }
        button:hover { background: #2980b9; }
        
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border-bottom: 1px solid #e1e4e8; padding: 12px; text-align: left; }
        th { background: #f4f6fa; color: #555; font-weight: 600; }
        tr:hover { background-color: #fafafa; }
        
        .delete-btn { background: #e74c3c; padding: 6px 12px; font-size: 12px; }
        .delete-btn:hover { background: #c0392b; }
        .badge { display: inline-block; padding: 2px 8px; background: #eee; border-radius: 10px; font-size: 12px; color: #666; }
    </style>
</head>
<body>
    <main>
        <h1>Manage Unions</h1>
        
        <!-- Add Union Form -->
        <form method="post">
            <input type="hidden" name="action" value="add">
            
            <div class="form-group">
                <label for="name">Union Name</label>
                <input type="text" name="name" id="name" required placeholder="e.g. Market Traders Association">
            </div>

            <div class="form-group">
                <label for="branch_id">Branch (Optional)</label>
                <select name="branch_id" id="branch_id">
                    <option value="">-- Select Branch --</option>
                    <?php foreach ($branches as $branch): ?>
                        <option value="<?= htmlspecialchars($branch['id']) ?>">
                            <?= htmlspecialchars($branch['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="description">Description</label>
                <textarea name="description" id="description" rows="2" placeholder="Brief description..."></textarea>
            </div>
            
            <button type="submit">Create Union</button>
        </form>

        <!-- List Unions Table -->
        <table>
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Branch</th>
                    <th>Description</th>
                    <th width="100">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($unions) > 0): ?>
                    <?php foreach ($unions as $union): ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars($union['name']) ?></strong>
                            </td>
                            <td>
                                <?php if($union['branch_name']): ?>
                                    <span class="badge"><?= htmlspecialchars($union['branch_name']) ?></span>
                                <?php else: ?>
                                    <span style="color:#999; font-style:italic;">None</span>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($union['description']) ?></td>
                            <td>
                                <form method="post" onsubmit="return confirm('Are you sure you want to delete this union?');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= htmlspecialchars($union['id']) ?>">
                                    <button type="submit" class="delete-btn">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="4" style="text-align:center; padding: 20px; color: #777;">No unions found in the database.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </main>
</body>
</html>