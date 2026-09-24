<?php
session_start();
require_once '../includes/config.php';
$pdo = getDbConnection();

// --- CONFIGURATION ---
define('MAX_EXECUTION_TIME', 60); 
define('RATE_LIMIT_REQUESTS', 100); 
define('RATE_LIMIT_WINDOW', 300);

// --- CACHE CONFIGURATION ---
// using system temp dir to avoid permission issues, specific to this app
define('CACHE_DIR', sys_get_temp_dir() . '/app_trans_cache/');
define('CACHE_TTL', 300); // 5 Minutes

if (!is_dir(CACHE_DIR)) {
    @mkdir(CACHE_DIR, 0755, true);
}

// --- SECURITY & AUTHENTICATION ---
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: ../index.php');
    exit();
}

set_time_limit(MAX_EXECUTION_TIME);
error_reporting(E_ALL);
ini_set('display_errors', 0); 
ini_set('log_errors', 1);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// --- CACHE HELPER FUNCTIONS ---

function get_cached_data($key) {
    $file = CACHE_DIR . md5($key) . '.json';
    if (file_exists($file)) {
        // Check if file is still valid based on TTL
        if ((time() - filemtime($file)) < CACHE_TTL) {
            $content = @file_get_contents($file);
            return $content ? json_decode($content, true) : null;
        } else {
            // Expired, delete it
            @unlink($file);
        }
    }
    return null;
}

function set_cached_data($key, $data) {
    $file = CACHE_DIR . md5($key) . '.json';
    @file_put_contents($file, json_encode($data), LOCK_EX);
}

function clear_global_cache() {
    // Delete all cache files in the directory
    $files = glob(CACHE_DIR . '*');
    foreach ($files as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }
    // Also clear session stats
    if (isset($_SESSION['dashboard_stats_cache'])) {
        unset($_SESSION['dashboard_stats_cache']);
    }
}

// --- RATE LIMITING ---
function check_rate_limit() {
    $key = 'rate_limit_' . ($_SESSION['username'] ?? 'unknown');
    if (!isset($_SESSION[$key])) {
        $_SESSION[$key] = ['count' => 0, 'first_request' => time()];
    }
    
    $current_time = time();
    $window_start = $_SESSION[$key]['first_request'];
    
    if ($current_time - $window_start > RATE_LIMIT_WINDOW) {
        $_SESSION[$key] = ['count' => 0, 'first_request' => $current_time];
    }
    
    if ($_SESSION[$key]['count'] >= RATE_LIMIT_REQUESTS) {
        http_response_code(429);
        return false;
    }
    
    $_SESSION[$key]['count']++;
    return true;
}

// --- CORE LOGIC FUNCTIONS ---

function log_audit_trail($pdo, $action, $table, $record_id, $old_values = [], $new_values = []) {
    try {
        $stmt = $pdo->prepare("INSERT INTO audit_log (user_id, username, action, table_name, record_id, old_values, new_values, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $_SESSION['user_id'] ?? 0,
            $_SESSION['username'] ?? 'admin',
            $action,
            $table,
            $record_id,
            json_encode($old_values),
            json_encode($new_values),
            $_SERVER['REMOTE_ADDR'],
            $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
        ]);
    } catch (Exception $e) {
        error_log("Audit Log Failed: " . $e->getMessage());
    }
}

function archive_transaction($pdo, $original_id, $type, $source_table, $data, $action_type) {
    try {
        $stmt = $pdo->prepare("INSERT INTO transaction_archives (original_id, transaction_type, source_table, record_data, action_type, archived_by) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $original_id,
            $type,
            $source_table,
            json_encode($data),
            $action_type, 
            $_SESSION['username'] ?? 'admin'
        ]);
    } catch (Exception $e) {
        error_log("Archive Transaction Failed: " . $e->getMessage());
    }
}

function update_client_savings_balance_sql($pdo, $client_id, $delta_amount) {
    if (!$client_id || $delta_amount == 0) return true;
    
    $stmt = $pdo->prepare("SELECT balance FROM saving_balances WHERE client_id = ?");
    $stmt->execute([$client_id]);
    $current = $stmt->fetchColumn();
    $new_balance = ($current ? floatval($current) : 0) + $delta_amount;
    
    $pdo->prepare("DELETE FROM saving_balances WHERE client_id = ?")->execute([$client_id]);
    $stmt = $pdo->prepare("INSERT INTO saving_balances (client_id, balance) VALUES (?, ?)");
    return $stmt->execute([$client_id, $new_balance]);
}

function update_loan_balance_sql($pdo, $disbursement_id, $delta_amount) {
    if (!$disbursement_id || $delta_amount == 0) return true;

    $stmt = $pdo->prepare("SELECT remaining_balance FROM disbursements WHERE id = ?");
    $stmt->execute([$disbursement_id]);
    $loan = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$loan) return false;

    $new_balance = max(0, floatval($loan['remaining_balance']) + $delta_amount);
    $status = ($new_balance <= 0) ? 'completed' : 'active';
    $payoff_date = ($status === 'completed') ? date('Y-m-d') : null;

    $updateSql = "UPDATE disbursements SET remaining_balance = ?, status = ?";
    $params = [$new_balance, $status];

    if ($payoff_date) {
        $updateSql .= ", payoff_date = ?";
        $params[] = $payoff_date;
    } else {
         $updateSql .= ", payoff_date = NULL";
    }

    $updateSql .= " WHERE id = ?";
    $params[] = $disbursement_id;

    $updStmt = $pdo->prepare($updateSql);
    return $updStmt->execute($params);
}

// --- API CONTROLLER ---
if (isset($_REQUEST['action'])) {
    if (!check_rate_limit()) {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            header('Content-Type: application/json');
            http_response_code(429);
            echo json_encode(['success' => false, 'message' => 'Too many requests.']);
        }
        exit();
    }
    
    $action = $_REQUEST['action'];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json');
        if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
            http_response_code(403); echo json_encode(['success' => false, 'message' => 'Token invalid.']); exit();
        }

        if ($action === 'clear_cache') {
            clear_global_cache();
            echo json_encode(['success' => true]);
            exit();
        }

        if ($action === 'get_transactions') {
            try {
                $filters_json = $_POST['filters'] ?? '[]';
                $filters = json_decode($filters_json, true);
                
                // --- 1. CHECK CACHE ---
                // Create a unique key based on the exact request parameters
                $cache_key = 'trans_list_' . $filters_json;
                $cached_response = get_cached_data($cache_key);
                
                if ($cached_response !== null) {
                    echo json_encode($cached_response);
                    exit();
                }

                $page = max(1, intval($filters['page'] ?? 1));
                
                // UPDATED DEFAULT LIMIT TO 50
                $limit = isset($filters['limit']) ? intval($filters['limit']) : 50;
                $limit = ($limit > 0 && $limit <= 500) ? $limit : 50;
                $offset = ($page - 1) * $limit;

                $base_query = "
                    SELECT 
                        t.id as primary_key,
                        t.transaction_id,
                        t.client_id,
                        c.name as client_name,
                        t.branch_id,
                        COALESCE(t.branch_id, c.branch_id) as actual_branch_id,
                        COALESCE(b.name, c.branch_id, 'N/A') as branch_name,
                        t.date as transaction_date,
                        CASE 
                            WHEN t.source_table = 'disbursements' THEN 'disbursement'
                            WHEN t.source_table = 'loan_collections' THEN t.subtype
                            WHEN t.source_table = 'saving_collections' THEN CONCAT('savings_', t.subtype)
                        END as transaction_type,
                        t.amount,
                        t.total_payable,
                        t.remaining_balance,
                        t.interest_rate,
                        t.num_installments,
                        t.installment_amount
                    FROM (
                        SELECT 
                            id, id as transaction_id, client_id, branch_id, date, 'disbursements' as source_table, 
                            NULL as subtype, principal as amount, total_payable, remaining_balance, 
                            interest_rate, num_installments, 
                            (total_payable / NULLIF(num_installments,0)) as installment_amount
                        FROM disbursements
                        
                        UNION ALL
                        
                        SELECT 
                            id, transaction_id, client_id, NULL as branch_id, date, 'loan_collections' as source_table, 
                            type as subtype, amount_collected as amount, NULL, remaining_balance, 
                            NULL, NULL, NULL
                        FROM loan_collections
                        
                        UNION ALL
                        
                        SELECT 
                            id, transaction_id, client_id, NULL as branch_id, date, 'saving_collections' as source_table, 
                            type as subtype, amount, NULL, balance_after, 
                            NULL, NULL, NULL
                        FROM saving_collections
                    ) t
                    INNER JOIN clients c ON t.client_id = c.id
                    LEFT JOIN branches b ON COALESCE(t.branch_id, c.branch_id) = b.id
                    LEFT JOIN users u ON c.officer_username = u.username
                    WHERE 1=1
                ";

                $params = [];

                if (!empty($filters['search'])) {
                    $base_query .= " AND (t.transaction_id LIKE ? OR c.name LIKE ?)";
                    $params[] = "%" . $filters['search'] . "%";
                    $params[] = "%" . $filters['search'] . "%";
                }
                if (!empty($filters['branch']) && $filters['branch'] !== 'all') {
                    $base_query .= " AND c.branch_id = ?";
                    $params[] = $filters['branch'];
                }
                if (!empty($filters['creditOfficer']) && $filters['creditOfficer'] !== 'all') {
                    $base_query .= " AND c.officer_username = ?";
                    $params[] = $filters['creditOfficer'];
                }
                if (!empty($filters['dateFrom'])) {
                    $base_query .= " AND t.date >= ?";
                    $params[] = $filters['dateFrom'] . " 00:00:00";
                }
                if (!empty($filters['dateTo'])) {
                    $base_query .= " AND t.date <= ?";
                    $params[] = $filters['dateTo'] . " 23:59:59";
                }
                if (!empty($filters['type']) && $filters['type'] !== 'all') {
                    if ($filters['type'] === 'disbursement') {
                        $base_query .= " AND t.source_table = 'disbursements'";
                    } elseif ($filters['type'] === 'repayment') {
                        $base_query .= " AND t.source_table = 'loan_collections' AND t.subtype = 'repayment'";
                    } elseif ($filters['type'] === 'savings') {
                        $base_query .= " AND t.source_table = 'saving_collections'";
                    }
                }

                $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM ($base_query) as count_tbl");
                $count_stmt->execute($params);
                $total_records = $count_stmt->fetchColumn();

                $base_query .= " ORDER BY t.date DESC LIMIT $limit OFFSET $offset";
                $stmt = $pdo->prepare($base_query);
                $stmt->execute($params);
                $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($data as &$row) {
                    $row['client'] = htmlspecialchars($row['client_name'] ?? 'Unknown');
                    $row['transaction_id'] = (string)$row['transaction_id']; 
                    if (strpos($row['transaction_type'], 'withdrawal') !== false || strpos($row['transaction_type'], 'return') !== false) {
                         $row['amount'] = -1 * abs($row['amount']);
                    }
                }

                $response_data = [
                    'success' => true, 
                    'data' => $data, 
                    'pagination' => [
                        'page' => $page, 
                        'total_records' => $total_records, 
                        'total_pages' => ceil($total_records / $limit)
                    ]
                ];

                // --- 2. SET CACHE ---
                set_cached_data($cache_key, $response_data);

                echo json_encode($response_data);

            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
            }
            exit();
        }

        if ($action === 'delete_transaction') {
            try {
                $id = $_POST['transaction_id'] ?? '';
                $type = $_POST['transaction_type'] ?? '';

                if (!$id) throw new Exception("ID required");

                $pdo->beginTransaction();

                if ($type === 'disbursement') {
                    $stmt = $pdo->prepare("SELECT * FROM disbursements WHERE id = ?");
                    $stmt->execute([$id]);
                    $record = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($record) {
                        archive_transaction($pdo, $id, $type, 'disbursements', $record, 'DELETE');
                        $pdo->prepare("DELETE FROM disbursements WHERE id = ?")->execute([$id]);
                        log_audit_trail($pdo, 'DELETE', 'disbursements', $id, $record);
                    }

                } elseif (strpos($type, 'savings') !== false) {
                    $stmt = $pdo->prepare("SELECT * FROM saving_collections WHERE transaction_id = ?");
                    $stmt->execute([$id]);
                    $record = $stmt->fetch(PDO::FETCH_ASSOC);

                    if ($record) {
                        archive_transaction($pdo, $id, $type, 'saving_collections', $record, 'DELETE');

                        $amount = abs(floatval($record['amount']));
                        $recType = strtolower(trim($record['type']));
                        $is_deposit = ($recType === 'deposit' || $recType === 'interest');
                        $delta = $is_deposit ? -1 * $amount : $amount;
                        
                        update_client_savings_balance_sql($pdo, $record['client_id'], $delta);
                        
                        if ($recType === 'withdrawal' || $recType === 'return') {
                            $findLc = $pdo->prepare("SELECT * FROM loan_collections WHERE client_id = ? AND amount_collected = ? AND date = ? LIMIT 1");
                            $findLc->execute([$record['client_id'], $amount, $record['date']]);
                            $linked_loan_trx = $findLc->fetch(PDO::FETCH_ASSOC);

                            if ($linked_loan_trx) {
                                archive_transaction($pdo, $linked_loan_trx['transaction_id'], 'auto_repayment', 'loan_collections', $linked_loan_trx, 'DELETE');
                                update_loan_balance_sql($pdo, $linked_loan_trx['disbursement_id'], $amount); 
                                $pdo->prepare("DELETE FROM loan_collections WHERE transaction_id = ?")->execute([$linked_loan_trx['transaction_id']]);
                                log_audit_trail($pdo, 'DELETE', 'loan_collections', $linked_loan_trx['transaction_id'], $linked_loan_trx);
                            } else {
                                $findLoan = $pdo->prepare("SELECT id FROM disbursements WHERE client_id = ? AND status IN ('active', 'completed') ORDER BY date DESC LIMIT 1");
                                $findLoan->execute([$record['client_id']]);
                                $loan_id = $findLoan->fetchColumn();
                                if ($loan_id) {
                                    update_loan_balance_sql($pdo, $loan_id, $amount); 
                                }
                            }
                        }
                        $pdo->prepare("DELETE FROM saving_collections WHERE transaction_id = ?")->execute([$id]);
                        log_audit_trail($pdo, 'DELETE', 'saving_collections', $id, $record);
                    }

                } else {
                    $stmt = $pdo->prepare("SELECT * FROM loan_collections WHERE transaction_id = ?");
                    $stmt->execute([$id]);
                    $record = $stmt->fetch(PDO::FETCH_ASSOC);

                    if ($record) {
                        archive_transaction($pdo, $id, $type, 'loan_collections', $record, 'DELETE');
                        $amount_collected = abs(floatval($record['amount_collected']));

                        if ($record['disbursement_id']) {
                            update_loan_balance_sql($pdo, $record['disbursement_id'], $amount_collected);
                        }
                        $pdo->prepare("DELETE FROM loan_collections WHERE transaction_id = ?")->execute([$id]);
                        log_audit_trail($pdo, 'DELETE', 'loan_collections', $id, $record);
                    }
                }

                $pdo->commit();
                
                // INVALIDATE CACHE ON CHANGE
                clear_global_cache();
                
                echo json_encode(['success' => true, 'message' => 'Deleted successfully.']);

            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                http_response_code(500); 
                echo json_encode(['success' => false, 'message' => 'Delete failed: ' . $e->getMessage()]);
            }
            exit();
        }

        if ($action === 'batch_delete') {
            try {
                $items = json_decode($_POST['items'] ?? '[]', true);
                $pdo->beginTransaction();
                $count = 0;

                foreach ($items as $item) {
                    $id = $item['id'];
                    $type = $item['type'];

                    if ($type === 'disbursement') {
                         $stmt = $pdo->prepare("SELECT * FROM disbursements WHERE id = ?");
                         $stmt->execute([$id]);
                         $record = $stmt->fetch(PDO::FETCH_ASSOC);
                         if($record) {
                             archive_transaction($pdo, $id, $type, 'disbursements', $record, 'DELETE');
                             $pdo->prepare("DELETE FROM disbursements WHERE id = ?")->execute([$id]);
                             log_audit_trail($pdo, 'BATCH_DELETE', 'disbursements', $id, $record);
                             $count++;
                         }
                    } elseif (strpos($type, 'savings') !== false) {
                        $stmt = $pdo->prepare("SELECT * FROM saving_collections WHERE transaction_id = ? FOR UPDATE");
                        $stmt->execute([$id]);
                        $record = $stmt->fetch(PDO::FETCH_ASSOC);
                        if ($record) {
                            archive_transaction($pdo, $id, $type, 'saving_collections', $record, 'DELETE');
                            $amount = abs(floatval($record['amount']));
                            $recType = strtolower(trim($record['type']));
                            $is_deposit = ($recType === 'deposit' || $recType === 'interest');
                            $delta = $is_deposit ? -1 * $amount : $amount;
                            
                            update_client_savings_balance_sql($pdo, $record['client_id'], $delta);

                            if ($recType === 'withdrawal' || $recType === 'return') {
                                $findLc = $pdo->prepare("SELECT * FROM loan_collections WHERE client_id = ? AND amount_collected = ? AND date = ? LIMIT 1");
                                $findLc->execute([$record['client_id'], $amount, $record['date']]);
                                $linked_loan_trx = $findLc->fetch(PDO::FETCH_ASSOC);

                                if ($linked_loan_trx) {
                                    archive_transaction($pdo, $linked_loan_trx['transaction_id'], 'auto_repayment', 'loan_collections', $linked_loan_trx, 'DELETE');
                                    update_loan_balance_sql($pdo, $linked_loan_trx['disbursement_id'], $amount); 
                                    $pdo->prepare("DELETE FROM loan_collections WHERE transaction_id = ?")->execute([$linked_loan_trx['transaction_id']]);
                                    log_audit_trail($pdo, 'BATCH_DELETE', 'loan_collections', $linked_loan_trx['transaction_id'], $linked_loan_trx);
                                } else {
                                    $findLoan = $pdo->prepare("SELECT id FROM disbursements WHERE client_id = ? AND status IN ('active', 'completed') ORDER BY date DESC LIMIT 1");
                                    $findLoan->execute([$record['client_id']]);
                                    $loan_id = $findLoan->fetchColumn();
                                    if ($loan_id) {
                                        update_loan_balance_sql($pdo, $loan_id, $amount); 
                                    }
                                }
                            }
                            $pdo->prepare("DELETE FROM saving_collections WHERE transaction_id = ?")->execute([$id]);
                            log_audit_trail($pdo, 'BATCH_DELETE', 'saving_collections', $id, $record);
                            $count++;
                        }
                    } else {
                        $stmt = $pdo->prepare("SELECT * FROM loan_collections WHERE transaction_id = ? FOR UPDATE");
                        $stmt->execute([$id]);
                        $record = $stmt->fetch(PDO::FETCH_ASSOC);
                        if ($record) {
                            archive_transaction($pdo, $id, $type, 'loan_collections', $record, 'DELETE');
                            $amount_collected = abs(floatval($record['amount_collected']));

                            if ($record['disbursement_id']) {
                                update_loan_balance_sql($pdo, $record['disbursement_id'], $amount_collected);
                            }
                            $pdo->prepare("DELETE FROM loan_collections WHERE transaction_id = ?")->execute([$id]);
                            log_audit_trail($pdo, 'BATCH_DELETE', 'loan_collections', $id, $record);
                            $count++;
                        }
                    }
                }

                $pdo->commit();
                
                // INVALIDATE CACHE ON CHANGE
                clear_global_cache();

                echo json_encode(['success' => true, 'message' => "Deleted $count transactions."]);
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Batch delete failed.']);
            }
            exit();
        }

        if ($action === 'edit_transaction') {
            try {
                $id = $_POST['transaction_id'] ?? '';
                $type = $_POST['transaction_type'] ?? '';
                $raw_data = $_POST['data'] ?? '[]';
                $data = json_decode($raw_data, true);

                if (!$id || empty($data)) throw new Exception("Invalid data");
                
                $date = date('Y-m-d H:i:s', strtotime($data['date']));

                $pdo->beginTransaction();

                if ($type === 'disbursement') {
                    $stmt = $pdo->prepare("SELECT * FROM disbursements WHERE id = ?");
                    $stmt->execute([$id]);
                    $old = $stmt->fetch(PDO::FETCH_ASSOC);

                    if (!$old) throw new Exception("Record not found");

                    archive_transaction($pdo, $id, $type, 'disbursements', $old, 'UPDATE');

                    $sql = "UPDATE disbursements SET date = ?, principal = ?, interest_rate = ?, total_payable = ?, num_installments = ?, remaining_balance = ? WHERE id = ?";
                    $pdo->prepare($sql)->execute([
                        $date, 
                        $data['principal_amount'], 
                        ($data['interest_rate_used'] * 100), 
                        $data['total_payable'], 
                        $data['num_installments'], 
                        $data['remaining_balance'], 
                        $id
                    ]);
                    
                    log_audit_trail($pdo, 'UPDATE', 'disbursements', $id, $old, $data);

                } elseif (strpos($type, 'savings') !== false) {
                    $stmt = $pdo->prepare("SELECT * FROM saving_collections WHERE transaction_id = ?");
                    $stmt->execute([$id]);
                    $old = $stmt->fetch(PDO::FETCH_ASSOC);

                    if (!$old) throw new Exception("Record not found");

                    archive_transaction($pdo, $id, $type, 'saving_collections', $old, 'UPDATE');

                    $old_amount = abs(floatval($old['amount']));
                    $new_amount = abs(floatval($data['amount'])); 
                    
                    $recType = strtolower(trim($old['type']));
                    $is_deposit = ($recType === 'deposit' || $recType === 'interest');
                    
                    // 1. RECALCULATE SAVINGS WALLET BALANCE
                    $revert_delta = $is_deposit ? -1 * $old_amount : $old_amount;
                    update_client_savings_balance_sql($pdo, $old['client_id'], $revert_delta);

                    $apply_delta = $is_deposit ? $new_amount : -1 * $new_amount;
                    update_client_savings_balance_sql($pdo, $old['client_id'], $apply_delta);

                    // 2. RECALCULATE LOAN BALANCE (If Withdrawal/Return)
                    if ($recType === 'withdrawal' || $recType === 'return') {
                        $findLoan = $pdo->prepare("SELECT id FROM disbursements WHERE client_id = ? AND status IN ('active', 'completed') ORDER BY date DESC LIMIT 1");
                        $findLoan->execute([$old['client_id']]);
                        $loan_id = $findLoan->fetchColumn();

                        if ($loan_id) {
                            $loan_adjustment = $old_amount - $new_amount;
                            if ($loan_adjustment != 0) {
                                update_loan_balance_sql($pdo, $loan_id, $loan_adjustment);
                            }
                        }
                    }

                    // 3. Update Transaction Record
                    $db_amount = $is_deposit ? $new_amount : -1 * $new_amount;
                    $sql = "UPDATE saving_collections SET date = ?, amount = ? WHERE transaction_id = ?";
                    $pdo->prepare($sql)->execute([$date, $db_amount, $id]);
                    
                    log_audit_trail($pdo, 'UPDATE', 'saving_collections', $id, $old, $data);

                } else {
                    $stmt = $pdo->prepare("SELECT * FROM loan_collections WHERE transaction_id = ?");
                    $stmt->execute([$id]);
                    $old = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if (!$old) throw new Exception("Record not found");

                    archive_transaction($pdo, $id, $type, 'loan_collections', $old, 'UPDATE');

                    $old_collected = floatval($old['amount_collected']);
                    $new_collected = floatval($data['amount_collected']);

                    $diff = $new_collected - $old_collected;
                    
                    if ($old['disbursement_id'] && $diff != 0) {
                        update_loan_balance_sql($pdo, $old['disbursement_id'], -1 * $diff);
                    }

                    $sql = "UPDATE loan_collections SET date = ?, amount_collected = ? WHERE transaction_id = ?";
                    $pdo->prepare($sql)->execute([$date, $new_collected, $id]);

                    log_audit_trail($pdo, 'UPDATE', 'loan_collections', $id, $old, $data);
                }

                $pdo->commit();
                
                // INVALIDATE CACHE ON CHANGE
                clear_global_cache();

                echo json_encode(['success' => true, 'message' => 'Updated successfully.']);

            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Update failed: ' . $e->getMessage()]);
            }
            exit();
        }
    }
}

// --- VIEW PREPARATION ---

$stmt = $pdo->query("SELECT * FROM disbursement_settings LIMIT 1");
$d_settings = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
$interest_rate = isset($d_settings['default_interest_rate']) ? $d_settings['default_interest_rate'] : 0.15;
$num_installments = $d_settings['default_num_installments'] ?? 23;

$stmt = $pdo->query("SELECT id, name FROM branches WHERE status='active'");
$branches = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

$branch_map = json_encode($branches);

$stmt = $pdo->query("SELECT username, full_name, branch_id FROM users WHERE role IN ('co','bm','am') AND status='active' ORDER BY full_name");
$officers_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

$credit_officers = [];
$officers_by_branch = [];
foreach ($officers_raw as $off) {
    $credit_officers[$off['username']] = $off['full_name'];
    $officers_by_branch[$off['branch_id']][$off['username']] = $off['full_name'];
}

$current_year = date('Y');

// --- SERVER-SIDE CACHING FOR DASHBOARD STATS (5 Minutes) ---
// Using file-based cache to reduce DB load for all users
$stats_cache_key = 'dashboard_stats_' . $current_year;
$stats = get_cached_data($stats_cache_key);

if (!$stats) {
    $stats = [];
    $stmt = $pdo->prepare("SELECT SUM(principal) FROM disbursements WHERE YEAR(date) = ?");
    $stmt->execute([$current_year]);
    $stats['disbursed'] = $stmt->fetchColumn() ?: 0;

    $stmt = $pdo->prepare("SELECT SUM(amount_collected) FROM loan_collections WHERE YEAR(date) = ?");
    $stmt->execute([$current_year]);
    $stats['collected'] = $stmt->fetchColumn() ?: 0;

    $stmt = $pdo->query("SELECT SUM(remaining_balance) FROM disbursements WHERE remaining_balance > 0");
    $stats['outstanding'] = $stmt->fetchColumn() ?: 0;

    $stmt = $pdo->prepare("
        SELECT 
            SUM(CASE WHEN type IN ('deposit','interest') THEN amount ELSE -amount END) 
        FROM saving_collections 
        WHERE YEAR(date) = ?
    ");
    $stmt->execute([$current_year]);
    $stats['net_savings'] = $stmt->fetchColumn() ?: 0;
    
    set_cached_data($stats_cache_key, $stats);
}

?>
<!DOCTYPE html>
<html lang="en" class="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Transaction Manager</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --bg-body: #f1f5f9; --bg-card: #ffffff; --text-main: #0f172a; --text-muted: #64748b; --primary: #3b82f6; --primary-hover: #2563eb; --danger: #ef4444; --success: #10b981; --border: #e2e8f0; --shadow: 0 4px 6px -1px rgba(0,0,0,0.1); }
        html.dark { --bg-body: #0f172a; --bg-card: #1e293b; --text-main: #f1f5f9; --text-muted: #94a3b8; --primary: #60a5fa; --primary-hover: #3b82f6; --danger: #f87171; --success: #34d399; --border: #334155; }
        * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
        body { margin: 0; font-family: 'Inter', sans-serif; background: var(--bg-body); color: var(--text-main); font-size: 0.95rem; padding-bottom: 80px; }
        .app-container { max-width: 1600px; margin: 0 auto; padding: 1.5rem; }
        header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; }
        .brand { font-weight: 800; font-size: 1.4rem; text-decoration: none; color: var(--text-main); display: flex; align-items: center; gap: 10px; }
        .brand img { height: 36px; }
        .header-actions { display: flex; gap: 0.75rem; }
        .theme-toggle { background: var(--bg-card); border: 1px solid var(--border); width: 40px; height: 40px; border-radius: 50%; display: grid; place-items: center; cursor: pointer; color: var(--text-main); }
        
        /* Stats */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1.25rem; margin-bottom: 2rem; }
        .stat-card { padding: 1.5rem; border-radius: 1.25rem; position: relative; overflow: hidden; color: white; cursor: pointer; transition: transform 0.2s; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); }
        .stat-card:hover { transform: translateY(-5px); }
        .card-1 { background: linear-gradient(135deg, #3b82f6, #2563eb); }
        .card-2 { background: linear-gradient(135deg, #10b981, #059669); }
        .card-3 { background: linear-gradient(135deg, #8b5cf6, #7c3aed); }
        .card-4 { background: linear-gradient(135deg, #f97316, #ea580c); cursor: default; }
        .stat-card i { position: absolute; right: -10px; bottom: -20px; font-size: 6rem; opacity: 0.15; transform: rotate(-15deg); }
        .stat-title { font-size: 0.8rem; font-weight: 600; text-transform: uppercase; margin-bottom: 0.5rem; opacity: 0.9; }
        .stat-value { font-size: 1.8rem; font-weight: 800; }

        /* Filters */
        .filter-toggle-btn { display: none; width: 100%; padding: 1rem; background: var(--bg-card); border: 1px solid var(--border); border-radius: 0.75rem; font-weight: 600; justify-content: space-between; align-items: center; margin-bottom: 1rem; cursor: pointer; }
        .filter-bar { background: var(--bg-card); padding: 1.5rem; border-radius: 1rem; border: 1px solid var(--border); display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem; margin-bottom: 1.5rem; align-items: end; }
        .form-group { display: flex; flex-direction: column; gap: 0.4rem; }
        .form-group label { font-size: 0.75rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; margin-bottom: 0.3rem; }
        input, select { padding: 0.6rem; border-radius: 0.6rem; border: 1px solid var(--border); background: var(--bg-body); color: var(--text-main); font-family: inherit; }
        
        .btn { padding: 0.6rem 1.2rem; border-radius: 0.6rem; border: none; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 0.5rem; text-decoration: none; color: white; font-size: 0.9rem; transition: opacity 0.2s; }
        .btn:disabled { opacity: 0.6; cursor: not-allowed; }
        .btn-reset { background: var(--bg-card); color: var(--text-muted); border: 1px solid var(--border); }
        .btn-success { background: var(--success); }
        .btn-primary { background: var(--primary); }
        .btn-danger { background: var(--danger); }

        @keyframes shimmer { 0% { background-position: -1000px 0; } 100% { background-position: 1000px 0; } }
        .skeleton { animation: shimmer 40s infinite linear; background: linear-gradient(to right, #f1f5f9 4%, #e2e8f0 25%, #f1f5f9 36%); background-size: 1000px 100%; height: 16px; border-radius: 4px; display:inline-block; }
        html.dark .skeleton { background: linear-gradient(to right, #1e293b 4%, #334155 25%, #1e293b 36%); }

        .spinner { border: 2px solid rgba(255,255,255,0.3); border-top: 2px solid #fff; border-radius: 50%; width: 14px; height: 14px; animation: spin 1s linear infinite; display: none; margin-right: 5px; }
        .loading .spinner { display: inline-block; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }

        .table-container { background: var(--bg-card); border-radius: 1rem; border: 1px solid var(--border); overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; min-width: 900px; }
        th, td { padding: 1rem; border-bottom: 1px solid var(--border); text-align: left; }
        th { background: var(--bg-body); font-size: 0.75rem; text-transform: uppercase; color: var(--text-muted); }
        
        .badge { padding: 0.35rem 0.85rem; border-radius: 1rem; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; display: inline-block; }
        .badge.disbursement { background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; }
        .badge.repayment, .badge.epayment, .badge.loan_collection { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }
        .badge.auto_repayment { background: #fef3c7; color: #d97706; border: 1px solid #fed7aa; }
        .badge.savings_deposit { background: #f3e8ff; color: #7e22ce; border: 1px solid #e9d5ff; }
        .badge.savings_withdrawal, .badge.savings_withdrawal_cash { background: #fff7ed; color: #c2410c; border: 1px solid #ffedd5; }
        .badge.savings_return { background: #fef2f2; color: #b91c1c; border: 1px solid #fee2e2; }
        .badge.savings_transfer { background: #eff6ff; color: #1d4ed8; border: 1px solid #dbeafe; }
        .badge.penalty { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
        .badge.adjustment { background: #f3f4f6; color: #374151; border: 1px solid #d1d5db; }
        .badge.savings_interest { background: #ecfccb; color: #3f6212; border: 1px solid #d9f99d; }
        .badge.savings_fee { background: #fff1f2; color: #be123c; border: 1px solid #fda4af; }

        html.dark .badge.disbursement { background: rgba(56, 189, 248, 0.15); color: #38bdf8; border-color: rgba(56, 189, 248, 0.2); }
        html.dark .badge.repayment, html.dark .badge.epayment, html.dark .badge.loan_collection { background: rgba(74, 222, 128, 0.15); color: #4ade80; border-color: rgba(74, 222, 128, 0.2); }
        html.dark .badge.auto_repayment { background: rgba(251, 191, 36, 0.15); color: #fbbf24; border-color: rgba(251, 191, 36, 0.2); }
        html.dark .badge.savings_deposit { background: rgba(192, 132, 252, 0.15); color: #c084fc; border-color: rgba(192, 132, 252, 0.2); }
        html.dark .badge.savings_withdrawal, html.dark .badge.savings_withdrawal_cash { background: rgba(251, 146, 60, 0.15); color: #fb923c; border-color: rgba(251, 146, 60, 0.2); }
        html.dark .badge.savings_return { background: rgba(248, 113, 113, 0.15); color: #f87171; border-color: rgba(248, 113, 113, 0.2); }
        html.dark .badge.savings_transfer { background: rgba(96, 165, 250, 0.15); color: #60a5fa; border-color: rgba(96, 165, 250, 0.2); }
        html.dark .badge.penalty { background: rgba(248, 113, 113, 0.2); color: #fca5a5; border-color: rgba(248, 113, 113, 0.3); }
        html.dark .badge.adjustment { background: rgba(156, 163, 175, 0.2); color: #d1d5db; border-color: rgba(156, 163, 175, 0.3); }
        html.dark .badge.savings_interest { background: rgba(163, 230, 53, 0.15); color: #bef264; border-color: rgba(163, 230, 53, 0.25); }
        html.dark .badge.savings_fee { background: rgba(244, 63, 94, 0.15); color: #fda4af; border-color: rgba(244, 63, 94, 0.25); }

        .action-btn { width: 32px; height: 32px; border-radius: 6px; border: none; background: transparent; color: var(--text-muted); cursor: pointer; font-size: 1rem; }
        .action-btn:hover { background: var(--bg-body); color: var(--primary); }
        .action-btn.delete:hover { color: var(--danger); }

        .batch-bar { position: fixed; bottom: 2rem; left: 50%; transform: translateX(-50%) translateY(150px); background: #1e293b; color: white; padding: 0.75rem 1.5rem; border-radius: 50px; display: flex; gap: 1.5rem; align-items: center; transition: transform 0.3s; z-index: 100; box-shadow: 0 10px 25px rgba(0,0,0,0.2); }
        .batch-bar.active { transform: translateX(-50%) translateY(0); }
        .batch-bar.loading { opacity: 0.7; pointer-events: none; }
        .batch-btn { background: none; border: none; color: white; font-weight: 600; cursor: pointer; }
        .batch-btn.danger { color: #fca5a5; }

        .modal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.6); z-index: 1000; align-items: center; justify-content: center; backdrop-filter: blur(4px); animation: fadeIn 0.2s; }
        .modal.active { display: flex; }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
        @keyframes slideUp { from { transform: translateY(20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
        .modal-content { background: var(--bg-card); padding: 0; border-radius: 1.25rem; width: 90%; max-width: 600px; max-height: 90vh; overflow: hidden; box-shadow: 0 20px 60px rgba(0,0,0,0.3); animation: slideUp 0.3s; display: flex; flex-direction: column; }
        .modal-header { display: flex; justify-content: space-between; align-items: center; padding: 1.5rem; border-bottom: 1px solid var(--border); background: linear-gradient(135deg, var(--primary), var(--primary-hover)); color: white; }
        .modal-header span { font-weight: 700; font-size: 1.1rem; display: flex; align-items: center; gap: 0.5rem; }
        .modal-header .action-btn { color: white; width: 36px; height: 36px; border-radius: 50%; }
        .modal-header .action-btn:hover { background: rgba(255,255,255,0.2); }
        
        .modal-body { padding: 1.25rem 1.5rem; max-height: calc(90vh - 180px); overflow-y: auto; display: flex; flex-direction: column; gap: 1rem; }
        .modal-footer { padding: 1rem 1.5rem; display: flex; justify-content: flex-end; gap: 0.75rem; border-top: 1px solid var(--border); background: var(--bg-body); flex-shrink: 0; }
        
        .form-grid-row { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
        .loan-summary-box { background: var(--bg-body); border: 1px solid var(--border); border-radius: 0.75rem; padding: 1rem; margin-top: 0.25rem; }
        .loan-summary-header { font-size: 0.75rem; text-transform: uppercase; font-weight: 700; color: var(--text-muted); margin-bottom: 0.75rem; display: flex; align-items: center; gap: 0.5rem; }
        .highlight-input { font-weight: 700; color: var(--primary); background-color: var(--bg-card); }
        .info-text-sm { font-size: 0.8rem; color: var(--text-muted); margin-top: 0.35rem; display: block; }
        
        .preview-box { background: linear-gradient(135deg, var(--primary), var(--primary-hover)); color: white; border-radius: 0.75rem; padding: 1rem; margin-top: 1rem; }
        .preview-header { font-size: 0.75rem; text-transform: uppercase; font-weight: 700; margin-bottom: 0.75rem; display: flex; align-items: center; gap: 0.5rem; opacity: 0.9; }
        .preview-content { display: flex; flex-direction: column; gap: 0.5rem; }
        .preview-row { display: flex; justify-content: space-between; align-items: center; font-size: 0.9rem; }
        .preview-row.highlight { font-weight: 700; padding-top: 0.5rem; border-top: 1px solid rgba(255,255,255,0.2); }

        .input-wrapper { position: relative; }
        .input-icon { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--text-muted); font-size: 0.9rem; }
        .form-group input[type="number"], .form-group input[type="datetime-local"] { padding-left: 2.5rem; font-size: 1rem; font-weight: 600; transition: all 0.2s; width: 100%; }
        .form-group input:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1); outline: none; }

        .pagination { padding: 1rem; display: flex; justify-content: space-between; align-items: center; border-top: 1px solid var(--border); }
        .toast-container { position: fixed; bottom: 2rem; right: 2rem; z-index: 1100; display: flex; flex-direction: column; gap: 0.5rem; }
        .toast { background: var(--bg-card); padding: 1rem; border-radius: 0.5rem; border-left: 4px solid var(--success); box-shadow: 0 5px 15px rgba(0,0,0,0.1); transform: translateX(120%); transition: transform 0.3s; display: flex; align-items: center; gap: 10px; }
        .toast.error { border-color: var(--danger); }
        .toast.show { transform: translateX(0); }

        @media (max-width: 768px) {
            .filter-toggle-btn { display: flex; }
            .filter-bar { display: none; grid-template-columns: 1fr; }
            .filter-bar.open { display: grid; }
            .stats-grid { display: flex; overflow-x: auto; gap: 1rem; padding-bottom: 1rem; scroll-snap-type: x mandatory; }
            .stat-card { min-width: 85%; scroll-snap-align: center; }
        }
    </style>
</head>
<body>

<div class="app-container">
    <header>
        <a href="dashboard.php" class="brand">
            <img src="../uploads/CUPAD LOGO.png" alt="Logo">
            <span>Transaction Manager</span>
        </a>
        <div class="header-actions">
            <a href="dashboard.php" class="btn btn-reset"><i class="fas fa-arrow-left"></i> Back</a>
            <div class="theme-toggle" id="themeBtn"><i class="fas fa-moon"></i></div>
        </div>
    </header>

    <div class="stats-grid">
        <div class="stat-card card-1" onclick="filterByType('disbursement')">
            <i class="fas fa-wallet"></i>
            <div class="stat-title">Disbursed (<?= $current_year ?>)</div>
            <div class="stat-value">₦<?= number_format($stats['disbursed']) ?></div>
        </div>
        <div class="stat-card card-2" onclick="filterByType('repayment')">
            <i class="fas fa-hand-holding-usd"></i>
            <div class="stat-title">Collected (<?= $current_year ?>)</div>
            <div class="stat-value">₦<?= number_format($stats['collected']) ?></div>
        </div>
        <div class="stat-card card-3" onclick="filterByType('savings')">
            <i class="fas fa-piggy-bank"></i>
            <div class="stat-title">Net Savings (<?= $current_year ?>)</div>
            <div class="stat-value">₦<?= number_format($stats['net_savings']) ?></div>
        </div>
        <div class="stat-card card-4">
            <i class="fas fa-exclamation-circle"></i>
            <div class="stat-title">Total Outstanding</div>
            <div class="stat-value">₦<?= number_format($stats['outstanding']) ?></div>
        </div>
    </div>

    <div class="filter-toggle-btn" onclick="toggleFilters()">
        <span><i class="fas fa-filter"></i> Filters</span>
        <i class="fas fa-chevron-down" id="filterIcon"></i>
    </div>

    <div class="filter-bar" id="filterBar">
        <div class="form-group" style="grid-column: span 2;">
            <label>Search Client/ID</label>
            <input type="text" id="filterSearch" placeholder="Name or Transaction ID...">
        </div>
        <div class="form-group">
            <label>Branch</label>
            <select id="filterBranch">
                <option value="all">All Branches</option>
                <?php foreach($branches as $id => $name): ?>
                    <option value="<?= $id ?>"><?= htmlspecialchars($name) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label>Type</label>
            <select id="filterType">
                <option value="all">All Transactions</option>
                <option value="disbursement">Disbursements</option>
                <option value="repayment">Loan Repayments</option>
                <option value="savings">Savings (All)</option>
            </select>
        </div>
        <div class="form-group">
            <label>Rows</label>
            <select id="filterLimit">
                <option value="10">10 rows</option>
                <option value="25">25 rows</option>
                <option value="50" selected>50 rows</option>
                <option value="100">100 rows</option>
            </select>
        </div>
        <div class="form-group"><label>Date From</label><input type="date" id="filterDateFrom"></div>
        <div class="form-group"><label>Date To</label><input type="date" id="filterDateTo"></div>
        <div class="form-group">
            <label>Credit Officer</label>
            <select id="filterCreditOfficer">
                <option value="all">All Officers</option>
                <?php foreach($credit_officers as $u => $n): ?>
                    <option value="<?= $u ?>"><?= htmlspecialchars($n) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="grid-column:1/-1; display:flex; gap:0.5rem;">
            <button class="btn btn-reset" onclick="resetFilters()">Reset</button>
        </div>
    </div>

    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th style="width:40px;"><input type="checkbox" id="selectAll" onchange="toggleAll(this)"></th>
                    <th>Date</th>
                    <th>Client</th>
                    <th>Branch</th>
                    <th>Type</th>
                    <th>Amount</th>
                    <th>Payable</th>
                    <th>Balance</th>
                    <th style="text-align: center;">Actions</th>
                </tr>
            </thead>
            <tbody id="tableBody"></tbody>
        </table>
        <div class="pagination" id="paginationControls" style="display:none;">
            <span id="pageInfo">Page 1</span>
            <div style="display:flex; gap:0.5rem;">
                <button class="btn btn-reset" id="prevBtn" disabled>Prev</button>
                <button class="btn btn-reset" id="nextBtn">Next</button>
            </div>
        </div>
    </div>
</div>

<div class="batch-bar" id="batchBar">
    <div><span id="selectedCount">0</span> selected</div>
    <div style="display:flex; gap:1rem;">
        <button class="batch-btn danger" onclick="confirmBatchDelete()" id="batchDelBtn"><span class="spinner"></span> DELETE</button>
        <button class="batch-btn" onclick="clearSelection()" id="batchCancelBtn">CANCEL</button>
    </div>
</div>

<!-- Edit Modal -->
<div class="modal" id="editModal">
    <div class="modal-content">
        <div class="modal-header">
            <span><i class="fas fa-edit"></i> Edit Transaction</span>
            <button class="action-btn" onclick="closeModal('editModal')"><i class="fas fa-times"></i></button>
        </div>
        <form id="editForm">
            <div class="modal-body">
                <input type="hidden" id="editId">
                <input type="hidden" id="editType">
                
                <div class="form-group">
                    <label>Transaction Date & Time</label>
                    <div class="input-wrapper">
                        <i class="fas fa-calendar-alt input-icon"></i>
                        <input type="datetime-local" id="editDate" required>
                    </div>
                </div>
                
                <div class="form-group">
                    <label id="lblAmount">Amount</label>
                    <div class="input-wrapper">
                        <i class="fas fa-naira-sign input-icon"></i>
                        <input type="number" id="editAmount" required step="0.01" placeholder="0.00" style="font-size: 1.1rem; font-weight: 600;">
                    </div>
                </div>

                <!-- DISBURSEMENT SPECIFIC FIELDS -->
                <div id="loanFields" style="display:none; flex-direction: column; gap: 1.5rem;">
                    
                    <div class="form-group">
                        <label>Interest Rate <small class="text-muted">(%)</small></label>
                        <div class="input-wrapper">
                            <i class="fas fa-percent input-icon"></i>
                            <input type="number" id="editRate" step="0.01" min="0" max="100" class="highlight-input">
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Number of Installments</label>
                        <div class="input-wrapper">
                            <i class="fas fa-hashtag input-icon"></i>
                            <input type="number" id="editNumInstallments" min="1" max="100" class="highlight-input">
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Installment Amount <small class="text-muted">(Per payment)</small></label>
                        <div class="input-wrapper">
                            <i class="fas fa-coins input-icon"></i>
                            <input type="number" id="editInstallment" step="0.01" class="highlight-input">
                        </div>
                    </div>

                    <div class="loan-summary-box">
                        <div class="loan-summary-header">
                            <i class="fas fa-calculator"></i> Loan Balance Summary
                        </div>
                        <div class="form-grid-row">
                            <div class="form-group">
                                <label>Total Repayable</label>
                                <div class="input-wrapper">
                                    <i class="fas fa-equals input-icon"></i>
                                    <input type="number" id="editPayable" step="0.01" class="highlight-input">
                                </div>
                            </div>
                            <div class="form-group">
                                <label>Remaining Balance</label>
                                <div class="input-wrapper">
                                    <i class="fas fa-wallet input-icon"></i>
                                    <input type="number" id="editBalance" step="0.01" class="highlight-input">
                                </div>
                            </div>
                        </div>
                        <div style="margin-top: 1rem; padding-top: 0.5rem; border-top: 1px solid var(--border); font-size: 0.8rem; color: var(--text-muted); text-align: right;">
                            <span id="rateInfoText">Rate: 0% | Duration: 0 weeks</span>
                        </div>
                    </div>

                    <!-- LIVE PREVIEW -->
                    <div class="preview-box" id="previewBox" style="display:none;">
                        <div class="preview-header">
                            <i class="fas fa-eye"></i> Live Preview
                        </div>
                        <div class="preview-content">
                            <div class="preview-row">
                                <span>Principal:</span>
                                <span id="previewPrincipal">₦0</span>
                            </div>
                            <div class="preview-row">
                                <span>Interest Rate:</span>
                                <span id="previewRate">0%</span>
                            </div>
                            <div class="preview-row">
                                <span>Total Payable:</span>
                                <span id="previewPayable">₦0</span>
                            </div>
                            <div class="preview-row">
                                <span>Per Installment:</span>
                                <span id="previewInstallment">₦0</span>
                            </div>
                            <div class="preview-row highlight">
                                <span>Interest Amount:</span>
                                <span id="previewInterest">₦0</span>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- END DISBURSEMENT FIELDS -->

            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-reset" onclick="closeModal('editModal')">Cancel</button>
                <button type="submit" class="btn btn-primary" id="saveEditBtn"><span class="spinner"></span> Save Changes</button>
            </div>
        </form>
    </div>
</div>

<div class="modal" id="deleteModal">
    <div class="modal-content">
        <div class="modal-header" style="background: linear-gradient(135deg, var(--danger), #dc2626);">
            <span><i class="fas fa-trash"></i> Confirm Delete</span>
            <button class="action-btn" onclick="closeModal('deleteModal')"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <p id="delMsg" style="text-align:center; margin-bottom: 1rem;">Are you sure?</p>
            <div id="deletePreview" style="display:none;">
                <div class="preview-box" style="background: linear-gradient(135deg, #ef4444, #dc2626);">
                    <div class="preview-header"><i class="fas fa-info-circle"></i> Transaction Details</div>
                    <div class="preview-content">
                        <div class="preview-row"><span>Transaction ID:</span><span id="delPreviewId">-</span></div>
                        <div class="preview-row"><span>Client:</span><span id="delPreviewClient">-</span></div>
                        <div class="preview-row"><span>Type:</span><span id="delPreviewType">-</span></div>
                        <div class="preview-row"><span>Date:</span><span id="delPreviewDate">-</span></div>
                        <div class="preview-row highlight"><span>Amount:</span><span id="delPreviewAmount">₦0</span></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-reset" onclick="closeModal('deleteModal')" id="cancelDelBtn">Cancel</button>
            <button class="btn btn-danger" id="confirmDeleteBtn"><span class="spinner"></span> Delete</button>
        </div>
    </div>
</div>

<div class="toast-container" id="toastArea"></div>

<script>
    const CSRF_TOKEN = "<?= $csrf_token ?>";
    const DEFAULT_INTEREST_RATE = <?= $interest_rate ?>;
    const DEFAULT_NUM_INSTALLMENTS = <?= $num_installments ?>;
    const state = { page: 1, filters: { type: 'all', branch: 'all', search: '', dateFrom: '', dateTo: '', creditOfficer: 'all', limit: 50 }, selected: new Set(), cachedData: [] };
    const formatCurrency = (amt) => '₦' + Number(amt).toLocaleString(undefined, {minimumFractionDigits: 0, maximumFractionDigits: 2});
    const officersByBranch = <?= json_encode($officers_by_branch) ?>;
    const branchMap = <?= $branch_map ?>;
    
    // REQUEST CACHE FOR API OPTIMIZATION
    const requestCache = new Map();

    function showToast(msg, type = 'success') {
        const t = document.createElement('div'); t.className = `toast ${type}`; t.innerHTML = msg;
        document.getElementById('toastArea').appendChild(t);
        setTimeout(() => t.classList.add('show'), 10);
        setTimeout(() => { t.classList.remove('show'); setTimeout(() => t.remove(), 300); }, 3000);
    }

    function toggleFilters() {
        document.getElementById('filterBar').classList.toggle('open');
    }

    const setLoading = (btnId, isLoading) => {
        const btn = document.getElementById(btnId); 
        if(isLoading) { btn.classList.add('loading'); btn.disabled = true; } 
        else { btn.classList.remove('loading'); btn.disabled = false; }
    };

    const fetchData = async () => {
        const skeletonRow = `<tr>${Array(9).fill('<td><div class="skeleton" style="width: ' + (Math.random()*50 + 40) + 'px"></div></td>').join('')}</tr>`.repeat(Math.min(state.filters.limit, 20));
        document.getElementById('tableBody').innerHTML = skeletonRow;
        
        // CHECK CACHE
        const cacheKey = JSON.stringify({ ...state.filters, page: state.page });
        if (requestCache.has(cacheKey)) {
            const cached = requestCache.get(cacheKey);
            state.cachedData = cached.data;
            renderTable(cached.data);
            updatePagination(cached.pagination);
            updateBatchUI();
            return;
        }

        const fd = new FormData();
        fd.append('action', 'get_transactions');
        fd.append('csrf_token', CSRF_TOKEN);
        fd.append('filters', JSON.stringify({ ...state.filters, page: state.page }));
        
        try {
            const res = await fetch('', { method: 'POST', body: fd });
            const json = await res.json();
            if(json.success) {
                // SAVE TO CACHE
                requestCache.set(cacheKey, json);
                
                state.cachedData = json.data;
                renderTable(json.data);
                updatePagination(json.pagination);
                updateBatchUI();
            } else showToast(json.message, 'error');
        } catch(e) { showToast('Load failed', 'error'); }
    };

    function clearRequestCache() {
        requestCache.clear();
    }
    
    async function clearServerCache() {
        const fd = new FormData();
        fd.append('action', 'clear_cache');
        fd.append('csrf_token', CSRF_TOKEN);
        await fetch('', { method: 'POST', body: fd });
    }

    const renderTable = (data) => {
        if(!data.length) { document.getElementById('tableBody').innerHTML = '<tr><td colspan="9" style="text-align:center; padding:2rem;">No data</td></tr>'; return; }
        document.getElementById('tableBody').innerHTML = data.map(row => {
            const key = `${row.transaction_id}|${row.transaction_type}`;
            const amount = parseFloat(row.amount || 0);
            const isChecked = state.selected.has(key) ? 'checked' : '';
            return `<tr>
                <td><input type="checkbox" ${isChecked} onchange="toggleRow('${row.transaction_id}','${row.transaction_type}')"></td>
                <td>${new Date(row.transaction_date).toLocaleDateString()}</td>
                <td><div style="font-weight:700">${row.client}</div><small>${row.transaction_id}</small></td>
                <td>
                    <div>${row.branch_name || 'N/A'}</div>
                    <small style="color:var(--text-muted); font-family:monospace;">${row.actual_branch_id || ''}</small>
                </td>
                <td><span class="badge ${row.transaction_type}">${row.transaction_type.replace(/_/g,' ')}</span></td>
                <td style="font-weight:700">${formatCurrency(amount)}</td>
                <td>${row.total_payable ? formatCurrency(row.total_payable) : '-'}</td>
                <td>${row.remaining_balance!==null ? formatCurrency(row.remaining_balance) : '-'}</td>
                <td style="text-align:center">
                    <button class="action-btn" onclick='openEdit(${JSON.stringify(row)})'><i class="fas fa-edit"></i></button>
                    <button class="action-btn delete" onclick='confirmDelete("${row.transaction_id}","${row.transaction_type}")'><i class="fas fa-trash"></i></button>
                </td>
            </tr>`;
        }).join('');
    };

    function toggleRow(id, type) {
        const k = `${id}|${type}`;
        state.selected.has(k) ? state.selected.delete(k) : state.selected.add(k);
        updateBatchUI();
    }
    function toggleAll(el) {
        state.cachedData.forEach(r => {
            const k = `${r.transaction_id}|${r.transaction_type}`;
            el.checked ? state.selected.add(k) : state.selected.delete(k);
        });
        updateBatchUI(); renderTable(state.cachedData);
    }
    function updateBatchUI() {
        document.getElementById('selectedCount').innerText = state.selected.size;
        document.getElementById('batchBar').classList.toggle('active', state.selected.size > 0);
    }
    function clearSelection() { state.selected.clear(); updateBatchUI(); renderTable(state.cachedData); }

    function confirmDelete(id, type) {
        const row = state.cachedData.find(r => r.transaction_id == id && r.transaction_type == type);
        state.deleteTarget = {id, type};
        
        if(row) {
            document.getElementById('delMsg').innerText = "This will update balances and remove the record. Continue?";
            document.getElementById('deletePreview').style.display = 'block';
            document.getElementById('delPreviewId').textContent = row.transaction_id;
            document.getElementById('delPreviewClient').textContent = row.client;
            document.getElementById('delPreviewType').textContent = row.transaction_type.replace(/_/g, ' ').toUpperCase();
            document.getElementById('delPreviewDate').textContent = new Date(row.transaction_date).toLocaleString();
            document.getElementById('delPreviewAmount').textContent = formatCurrency(Math.abs(row.amount));
        } else {
            document.getElementById('delMsg').innerText = "This will update balances and remove the record. Continue?";
            document.getElementById('deletePreview').style.display = 'none';
        }
        
        document.getElementById('deleteModal').classList.add('active');
    }
    function confirmBatchDelete() {
        if(!state.selected.size) return;
        state.deleteTarget = 'batch';
        document.getElementById('delMsg').innerText = `Delete ${state.selected.size} transactions?`;
        document.getElementById('deletePreview').style.display = 'none';
        document.getElementById('deleteModal').classList.add('active');
    }

    document.getElementById('confirmDeleteBtn').onclick = async () => {
        setLoading('confirmDeleteBtn', true);
        const fd = new FormData(); fd.append('csrf_token', CSRF_TOKEN);
        
        if(state.deleteTarget === 'batch') {
            document.getElementById('batchBar').classList.add('loading');
            fd.append('action', 'batch_delete');
            fd.append('items', JSON.stringify(Array.from(state.selected).map(k=>{ const [id,t]=k.split('|'); return {id,type:t}; })));
        } else {
            fd.append('action', 'delete_transaction');
            fd.append('transaction_id', state.deleteTarget.id);
            fd.append('transaction_type', state.deleteTarget.type);
        }
        
        await sendAction(fd);
        clearRequestCache();
        await clearServerCache();
        setLoading('confirmDeleteBtn', false);
        document.getElementById('batchBar').classList.remove('loading');
        closeModal('deleteModal');
        if(state.deleteTarget === 'batch') clearSelection();
    };

    let currentEditRow = null;

    function openEdit(row) {
        manualInstallmentEdit = false;
        manualRateEdit = false;
        currentEditRow = row;
        document.getElementById('editId').value = row.transaction_id; 
        document.getElementById('editType').value = row.transaction_type;
        
        // Date formatting
        let dateStr = row.transaction_date;
        if(dateStr) document.getElementById('editDate').value = dateStr.replace(' ', 'T').substring(0, 16);
        
        // Determine Amount
        let amt = Math.abs(parseFloat(row.amount));
        document.getElementById('editAmount').value = amt;

        // Toggle Disbursement UI
        const isDisb = row.transaction_type === 'disbursement';
        const loanFields = document.getElementById('loanFields');
        const lblAmount = document.getElementById('lblAmount');

        if(isDisb) {
            loanFields.style.display = 'flex';
            lblAmount.innerText = "Principal Amount Disbursed";
            
            // Populate Loan specific fields
            const rate = (row.interest_rate || DEFAULT_INTEREST_RATE); 
            document.getElementById('editRate').value = rate;
            document.getElementById('editPayable').value = row.total_payable;
            document.getElementById('editBalance').value = row.remaining_balance;
            document.getElementById('editInstallment').value = row.installment_amount ?? 0;
            document.getElementById('editNumInstallments').value = row.num_installments ?? DEFAULT_NUM_INSTALLMENTS;
            
            // Update Info Text
            const dur = row.num_installments || DEFAULT_NUM_INSTALLMENTS;
            document.getElementById('rateInfoText').innerText = `Rate: ${Math.round(rate)}% | Duration: ${dur} installments`;
            
            // Initialize preview
            updatePreview();
        } else {
            loanFields.style.display = 'none';
            lblAmount.innerText = "Amount";
            document.getElementById('previewBox').style.display = 'none';
        }

        document.getElementById('editModal').classList.add('active');
    }

    function updatePreview() {
        if(document.getElementById('editType').value !== 'disbursement') return;
        
        const principal = parseFloat(document.getElementById('editAmount').value) || 0;
        const rate = (parseFloat(document.getElementById('editRate').value) || 0) / 100;
        const installments = parseInt(document.getElementById('editNumInstallments').value) || DEFAULT_NUM_INSTALLMENTS;
        const payable = Math.round(principal * (1 + rate));
        const installmentAmt = installments > 0 ? Math.round(payable / installments) : 0;
        const interest = payable - principal;
        
        document.getElementById('previewPrincipal').textContent = formatCurrency(principal);
        document.getElementById('previewRate').textContent = Math.round(rate * 100) + '%';
        document.getElementById('previewPayable').textContent = formatCurrency(payable);
        document.getElementById('previewInstallment').textContent = formatCurrency(installmentAmt);
        document.getElementById('previewInterest').textContent = formatCurrency(interest);
        
        document.getElementById('previewBox').style.display = principal > 0 ? 'block' : 'none';
    }

    // Logic for recalculating loan details when Principal changes
    document.getElementById('editAmount').addEventListener('input', (e) => {
        if(document.getElementById('editType').value === 'disbursement' && currentEditRow) {
            const newPrincipal = parseFloat(e.target.value) || 0;
            const rate = manualRateEdit ? (parseFloat(document.getElementById('editRate').value) || 0) / 100 : (parseFloat(document.getElementById('editRate').value) || 0) / 100;
            const installments = parseInt(document.getElementById('editNumInstallments').value) || DEFAULT_NUM_INSTALLMENTS;
            const newPayable = Math.round(newPrincipal * (1 + rate));
            const newInstallment = installments > 0 ? Math.round(newPayable / installments) : 0;
            document.getElementById('editPayable').value = newPayable;
            if(!manualInstallmentEdit) document.getElementById('editInstallment').value = newInstallment;
            document.getElementById('editBalance').value = newPayable;
            updatePreview();
        }
    });

    let manualRateEdit = false;
    document.getElementById('editRate').addEventListener('input', (e) => {
        manualRateEdit = true;
        if(document.getElementById('editType').value === 'disbursement' && currentEditRow) {
            const newPrincipal = parseFloat(document.getElementById('editAmount').value) || 0;
            const rate = (parseFloat(e.target.value) || 0) / 100;
            const installments = parseInt(document.getElementById('editNumInstallments').value) || DEFAULT_NUM_INSTALLMENTS;
            const newPayable = Math.round(newPrincipal * (1 + rate));
            const newInstallment = installments > 0 ? Math.round(newPayable / installments) : 0;
            document.getElementById('editPayable').value = newPayable;
            if(!manualInstallmentEdit) document.getElementById('editInstallment').value = newInstallment;
            document.getElementById('editBalance').value = newPayable;
            document.getElementById('rateInfoText').innerText = `Rate: ${Math.round(parseFloat(e.target.value))}% | Duration: ${installments} installments`;
            updatePreview();
        }
    });

    document.getElementById('editPayable').addEventListener('input', (e) => {
        if(document.getElementById('editType').value === 'disbursement' && currentEditRow) {
            const newPayable = parseFloat(e.target.value) || 0;
            const installments = parseInt(document.getElementById('editNumInstallments').value) || DEFAULT_NUM_INSTALLMENTS;
            const newInstallment = installments > 0 ? Math.round(newPayable / installments) : 0;
            if(!manualInstallmentEdit) document.getElementById('editInstallment').value = newInstallment;
            updatePreview();
        }
    });

    document.getElementById('editNumInstallments').addEventListener('input', (e) => {
        if(document.getElementById('editType').value === 'disbursement' && currentEditRow) {
            const installments = parseInt(e.target.value) || DEFAULT_NUM_INSTALLMENTS;
            const newPayable = parseFloat(document.getElementById('editPayable').value) || 0;
            const newInstallment = installments > 0 ? Math.round(newPayable / installments) : 0;
            if(!manualInstallmentEdit) document.getElementById('editInstallment').value = newInstallment;
            const rate = parseFloat(document.getElementById('editRate').value) || 0;
            document.getElementById('rateInfoText').innerText = `Rate: ${Math.round(rate)}% | Duration: ${installments} installments`;
            updatePreview();
        }
    });

    let manualInstallmentEdit = false;
    document.getElementById('editInstallment').addEventListener('input', (e) => {
        manualInstallmentEdit = true;
    });

    document.getElementById('editForm').onsubmit = async (e) => {
        e.preventDefault(); setLoading('saveEditBtn', true);
        const data = { date: document.getElementById('editDate').value, amount: parseFloat(document.getElementById('editAmount').value) };
        const type = document.getElementById('editType').value;
        if(type==='disbursement') {
            data.principal_amount = data.amount;
            data.total_payable = parseFloat(document.getElementById('editPayable').value);
            data.remaining_balance = parseFloat(document.getElementById('editBalance').value);
            data.installment_amount = parseFloat(document.getElementById('editInstallment').value) || 0;
            data.interest_rate_used = (parseFloat(document.getElementById('editRate').value) || 0) / 100;
            data.num_installments = parseInt(document.getElementById('editNumInstallments').value) || DEFAULT_NUM_INSTALLMENTS;
            delete data.amount;
        } else if(type.includes('repayment') || type.includes('collection')) {
            data.amount_collected = data.amount; delete data.amount;
        } 
        
        const fd = new FormData();
        fd.append('action', 'edit_transaction');
        fd.append('csrf_token', CSRF_TOKEN);
        fd.append('transaction_id', document.getElementById('editId').value);
        fd.append('transaction_type', type);
        fd.append('data', JSON.stringify(data));
        
        await sendAction(fd);
        clearRequestCache();
        await clearServerCache();
        setLoading('saveEditBtn', false);
        closeModal('editModal');
    };

    async function sendAction(fd) {
        try {
            const res = await fetch('', { method:'POST', body:fd });
            const json = await res.json();
            showToast(json.message, json.success?'success':'error');
            if(json.success) fetchData();
        } catch(e) { showToast('Action failed', 'error'); }
    }

    function closeModal(id) { document.getElementById(id).classList.remove('active'); }
    function resetFilters() { 
        state.filters={type:'all',branch:'all',search:'',dateFrom:'',dateTo:'',creditOfficer:'all',limit:50}; 
        state.page=1; 
        document.querySelectorAll('.filter-bar input, .filter-bar select').forEach(e => {
            if(e.id === 'filterLimit') e.value = '50';
            else e.value = e.tagName==='SELECT'?'all':'';
        }); 
        fetchData(); 
    }
    
    ['filterSearch','filterBranch','filterType','filterDateFrom','filterDateTo','filterCreditOfficer','filterLimit'].forEach(id => {
        document.getElementById(id).addEventListener(id==='filterSearch'?'input':'change', (e) => {
            const key = id.replace('filter','').replace('Search','search').replace('Branch','branch').replace('Type','type').replace('DateFrom','dateFrom').replace('DateTo','dateTo').replace('CreditOfficer','creditOfficer').replace('Limit','limit');
            state.filters[key] = key === 'limit' ? parseInt(e.target.value) : e.target.value;
            if(id === 'filterBranch') updateOfficerFilter(e.target.value);
            if(id !== 'filterSearch') { state.page = 1; fetchData(); }
        });
    });
    
    function updateOfficerFilter(branchId) {
        const select = document.getElementById('filterCreditOfficer');
        select.innerHTML = '<option value="all">All Officers</option>';
        if(branchId === 'all') {
            <?php foreach($credit_officers as $u => $n): ?>
            select.innerHTML += '<option value="<?= $u ?>"><?= htmlspecialchars($n) ?></option>';
            <?php endforeach; ?>
        } else {
            const officers = officersByBranch[branchId] || {};
            Object.entries(officers).forEach(([username, name]) => {
                select.innerHTML += `<option value="${username}">${name}</option>`;
            });
        }
        state.filters.creditOfficer = 'all';
    }
    
    let timeout;
    document.getElementById('filterSearch').addEventListener('keyup', () => { clearTimeout(timeout); timeout = setTimeout(() => { state.page=1; fetchData(); }, 500); });

    function updatePagination(p) {
        const div = document.getElementById('paginationControls');
        if(p.total_pages <= 1) { div.style.display = 'none'; return; }
        div.style.display = 'flex';
        document.getElementById('pageInfo').innerText = `Page ${p.page} of ${p.total_pages}`;
        document.getElementById('prevBtn').disabled = p.page === 1;
        document.getElementById('nextBtn').disabled = p.page === p.total_pages;
        document.getElementById('prevBtn').onclick = () => { state.page--; fetchData(); };
        document.getElementById('nextBtn').onclick = () => { state.page++; fetchData(); };
    }

    window.filterByType = (t) => {
        state.filters.type = t; document.getElementById('filterType').value = t; state.page=1; fetchData();
        if(window.innerWidth <= 768) document.getElementById('filterBar').scrollIntoView({behavior:'smooth'});
    };

    document.getElementById('themeBtn').onclick = () => {
        const isDark = document.documentElement.classList.toggle('dark');
        localStorage.setItem('theme', isDark ? 'dark' : 'light');
    };
    if(localStorage.getItem('theme')==='dark') document.documentElement.classList.add('dark');

    fetchData();
</script>
</body>
</html>