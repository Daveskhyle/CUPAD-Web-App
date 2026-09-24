<?php
date_default_timezone_set('Africa/Lagos');
session_start();

require_once __DIR__ . '/../includes/config.php';
$pdo = getDbConnection();

if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: ../index.php');
    exit();
}

// Fetch user data for the unified dashboard header
$current_username = $_SESSION['username'] ?? '';
$full_name = $_SESSION['full_name'] ?? 'Admin';
$role = $_SESSION['user_role'] ?? 'admin';

function format_naira(float $amount): string {
    return '₦' . number_format($amount, 0);
}

// ==========================================
// CORE HELPERS FOR TRANSACTION MANAGER LOGIC
// ==========================================
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
    } catch (Exception $e) { error_log("Audit Log Failed: " . $e->getMessage()); }
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
    } catch (Exception $e) { error_log("Archive Transaction Failed: " . $e->getMessage()); }
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

// ==========================================
// AJAX HANDLERS
// ==========================================

// 1. Update Client Profile Data
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax']) && $_POST['ajax'] === 'update_client_data') {
    header('Content-Type: application/json');
    $client_id = $_POST['client_id'] ?? '';
    $g_name = trim($_POST['guarantor_name'] ?? '');
    $g_phone = trim($_POST['guarantor_phone'] ?? '');
    $savings_bal = floatval($_POST['savings_balance'] ?? 0);
    $loan_bal = floatval($_POST['loan_balance'] ?? 0);

    if (empty($client_id)) {
        echo json_encode(['success' => false, 'message' => 'Client ID is required.']);
        exit();
    }

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("UPDATE clients SET guarantor_name = ?, guarantor_phone = ? WHERE id = ?");
        $stmt->execute([$g_name, $g_phone, $client_id]);

        $stmt = $pdo->prepare("SELECT client_id FROM saving_balances WHERE client_id = ?");
        $stmt->execute([$client_id]);
        if ($stmt->fetch()) {
            $stmt = $pdo->prepare("UPDATE saving_balances SET balance = ? WHERE client_id = ?");
            $stmt->execute([$savings_bal, $client_id]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO saving_balances (client_id, balance) VALUES (?, ?)");
            $stmt->execute([$client_id, $savings_bal]);
        }

        $stmt = $pdo->prepare("SELECT SUM(remaining_balance) FROM disbursements WHERE client_id = ? AND status != 'completed' AND remaining_balance > 0");
        $stmt->execute([$client_id]);
        $current_total_loan = floatval($stmt->fetchColumn() ?? 0);

        if (abs($current_total_loan - $loan_bal) > 0.01) {
            $stmt = $pdo->prepare("SELECT id, remaining_balance FROM disbursements WHERE client_id = ? AND status != 'completed' AND remaining_balance > 0 ORDER BY date DESC LIMIT 1");
            $stmt->execute([$client_id]);
            $latest_loan = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($latest_loan) {
                $delta = $loan_bal - $current_total_loan;
                $new_latest_bal = max(0, floatval($latest_loan['remaining_balance']) + $delta);
                $stmt = $pdo->prepare("UPDATE disbursements SET remaining_balance = ? WHERE id = ?");
                $stmt->execute([$new_latest_bal, $latest_loan['id']]);
            } else if ($loan_bal > 0) {
                throw new Exception("Cannot set loan balance: This client has no active loans to attach the balance to.");
            }
        }
        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Client data & balances updated successfully!']);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit();
}

// 2. Delete Transaction
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax']) && $_POST['ajax'] === 'delete_transaction') {
    header('Content-Type: application/json');
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
                        if ($loan_id) update_loan_balance_sql($pdo, $loan_id, $amount); 
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
        echo json_encode(['success' => true, 'message' => 'Deleted successfully.']);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Delete failed: ' . $e->getMessage()]);
    }
    exit();
}

// 3. Edit Transaction
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax']) && $_POST['ajax'] === 'edit_transaction') {
    header('Content-Type: application/json');
    try {
        $id = $_POST['transaction_id'] ?? '';
        $type = $_POST['transaction_type'] ?? '';
        $data = json_decode($_POST['data'] ?? '[]', true);

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
                $date, $data['principal_amount'], ($data['interest_rate_used'] * 100), 
                $data['total_payable'], $data['num_installments'], $data['remaining_balance'], $id
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
            
            // Recalculate Savings Wallet
            $revert_delta = $is_deposit ? -1 * $old_amount : $old_amount;
            update_client_savings_balance_sql($pdo, $old['client_id'], $revert_delta);

            $apply_delta = $is_deposit ? $new_amount : -1 * $new_amount;
            update_client_savings_balance_sql($pdo, $old['client_id'], $apply_delta);

            // Recalculate Loan (if withdrawal/return)
            if ($recType === 'withdrawal' || $recType === 'return') {
                $findLoan = $pdo->prepare("SELECT id FROM disbursements WHERE client_id = ? AND status IN ('active', 'completed') ORDER BY date DESC LIMIT 1");
                $findLoan->execute([$old['client_id']]);
                $loan_id = $findLoan->fetchColumn();

                if ($loan_id) {
                    $loan_adjustment = $old_amount - $new_amount;
                    if ($loan_adjustment != 0) update_loan_balance_sql($pdo, $loan_id, $loan_adjustment);
                }
            }

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
        echo json_encode(['success' => true, 'message' => 'Updated successfully.']);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Update failed: ' . $e->getMessage()]);
    }
    exit();
}

// 4. Get Clients Data (List)
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_clients_data') {
    header('Content-Type: application/json');
    $page = max(1, intval($_GET['page'] ?? 1));
    $per_page = 10;
    $offset = ($page - 1) * $per_page;
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    
    $zone_id = $_GET['zone_id'] ?? '';
    $area_id = $_GET['area_id'] ?? '';
    $branch_id = $_GET['branch_id'] ?? '';

    $sort_param = $_GET['sort'] ?? 'name_asc';
    $sort_map = ['name_asc' => 'c.name ASC', 'savings_desc' => 'savings DESC, c.name ASC', 'loan_desc' => 'outstanding_loan DESC, c.name ASC'];
    $order_by = $sort_map[$sort_param] ?? 'c.name ASC';

    try {
        $joins = "LEFT JOIN branches b ON c.branch_id = b.id
                  LEFT JOIN areas a ON b.area_id = a.id
                  LEFT JOIN zones z ON b.zone_id = z.id";
        
        $where_clauses = ["c.status = 'active'"];
        $params = [];

        if ($search !== '') {
            $search_term = "%$search%";
            $where_clauses[] = "(c.name LIKE ? OR c.`union` LIKE ?)";
            $params[] = $search_term;
            $params[] = $search_term;
        }

        if ($zone_id !== '') {
            $where_clauses[] = "z.id = ?";
            $params[] = $zone_id;
        }
        if ($area_id !== '') {
            $where_clauses[] = "a.id = ?";
            $params[] = $area_id;
        }
        if ($branch_id !== '') {
            $where_clauses[] = "b.id = ?";
            $params[] = $branch_id;
        }

        $where_sql = implode(' AND ', $where_clauses);

        // Count
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM clients c $joins WHERE $where_sql");
        $stmt->execute($params);
        $total_clients = $stmt->fetchColumn();

        // Total Savings
        $stmt = $pdo->prepare("SELECT SUM(sb.balance) FROM saving_balances sb WHERE sb.client_id IN (SELECT c.id FROM clients c $joins WHERE $where_sql)");
        $stmt->execute($params);
        $total_savings = floatval($stmt->fetchColumn() ?? 0);

        // Total Loans
        $stmt = $pdo->prepare("SELECT SUM(d.remaining_balance) FROM disbursements d WHERE d.status != 'completed' AND d.remaining_balance > 0 AND d.client_id IN (SELECT c.id FROM clients c $joins WHERE $where_sql)");
        $stmt->execute($params);
        $total_loans = floatval($stmt->fetchColumn() ?? 0);

        $sql = "SELECT c.id, c.name, c.union, c.guarantor_name, c.guarantor_phone, c.officer_username,
                    b.name as branch_name, a.name as area_name, z.name as zone_name,
                    (SELECT balance FROM saving_balances WHERE client_id = c.id LIMIT 1) as savings,
                    (SELECT SUM(remaining_balance) FROM disbursements d WHERE d.client_id = c.id AND d.status != 'completed' AND d.remaining_balance > 0) as outstanding_loan,
                    (SELECT COUNT(*) FROM saving_collections WHERE client_id = c.id AND (type IN ('withdrawal','return_cash') OR amount < 0) AND date >= COALESCE((SELECT MAX(date) FROM disbursements WHERE client_id = c.id), '1970-01-01')) as withdrawal_count,
                    (SELECT COUNT(*) FROM loan_collections WHERE client_id = c.id AND date >= COALESCE((SELECT MAX(date) FROM disbursements WHERE client_id = c.id), '1970-01-01')) as repayment_count
                FROM clients c
                $joins
                WHERE $where_sql
                ORDER BY $order_by LIMIT ? OFFSET ?";
        
        $stmt = $pdo->prepare($sql);
        $param_idx = 1;
        foreach($params as $val) {
            $stmt->bindValue($param_idx++, $val);
        }
        $stmt->bindValue($param_idx++, $per_page, PDO::PARAM_INT);
        $stmt->bindValue($param_idx, $offset, PDO::PARAM_INT);
        $stmt->execute();
        
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $display_data = [];
        foreach ($results as $row) {
            $row['savings'] = floatval($row['savings'] ?? 0);
            $row['outstanding_loan'] = floatval($row['outstanding_loan'] ?? 0);
            $row['withdrawal_count'] = intval($row['withdrawal_count'] ?? 0);
            $row['repayment_count'] = intval($row['repayment_count'] ?? 0);
            $row['guarantor_name'] = $row['guarantor_name'] ?: 'N/A';
            $row['guarantor_phone'] = $row['guarantor_phone'] ?: 'N/A';
            $row['officer_username'] = $row['officer_username'] ?: 'Unassigned';
            $row['branch_name'] = $row['branch_name'] ?: 'Unknown Branch';
            $row['area_name'] = $row['area_name'] ?: 'Unknown Area';
            $row['zone_name'] = $row['zone_name'] ?: 'Unknown Zone';
            $display_data[] = $row;
        }

        echo json_encode([
            'success' => true,
            'summary' => ['total_clients' => $total_clients, 'total_savings' => $total_savings, 'total_loans' => $total_loans],
            'clients' => $display_data,
            'pagination' => ['has_more' => (($page * $per_page) < $total_clients)]
        ]);

    } catch (PDOException $e) { echo json_encode(['success' => false, 'message' => $e->getMessage()]); }
    exit();
}

// 5. Get Client History
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_client_history') {
    header('Content-Type: application/json');
    $client_id = $_GET['id'] ?? '';
    try {
        $stmt = $pdo->prepare("
            SELECT c.name, c.union, c.guarantor_name, c.guarantor_phone, c.officer_username,
                b.name as branch_name, a.name as area_name, z.name as zone_name,
                (SELECT balance FROM saving_balances WHERE client_id = c.id LIMIT 1) as savings_bal,
                (SELECT SUM(remaining_balance) FROM disbursements d WHERE d.client_id = c.id AND d.status != 'completed' AND d.remaining_balance > 0) as loan_bal,
                (SELECT COUNT(*) FROM saving_collections WHERE client_id = c.id AND (type IN ('withdrawal','return_cash') OR amount < 0)) as total_withdrawals,
                (SELECT COUNT(*) FROM loan_collections WHERE client_id = c.id) as total_repayments,
                (SELECT COUNT(*) FROM disbursements WHERE client_id = c.id) as total_loans
            FROM clients c
            LEFT JOIN branches b ON c.branch_id = b.id
            LEFT JOIN areas a ON b.area_id = a.id
            LEFT JOIN zones z ON b.zone_id = z.id
            WHERE c.id = ?");
        $stmt->execute([$client_id]);
        $client = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if(!$client) { echo json_encode(['success'=>false, 'message'=>'Client not found']); exit; }

        $timeline = [];

        // Savings
        $stmt = $pdo->prepare("SELECT id, transaction_id, amount, date, type FROM saving_collections WHERE client_id = ? ORDER BY date DESC, id DESC LIMIT 40");
        $stmt->execute([$client_id]);
        while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $amount = floatval($row['amount']);
            $type = strtolower($row['type'] ?? 'deposit');
            $is_withdrawal = ($amount < 0) || in_array($type, ['withdrawal', 'return_cash', 'return cash', 'debit', 'charge']);
            
            $description = 'Deposit';
            if (strpos($type, 'return') !== false || $type === 'return_cash') $description = 'Return Cash';
            elseif ($type === 'withdrawal' || $amount < 0) $description = 'Withdrawal';
            elseif ($type === 'transfer') $description = 'Transfer';
            elseif ($type === 'charge' || $type === 'fee') $description = 'Fee/Charges';
            
            $timeline[] = [
                'timestamp' => strtotime($row['date']),
                'sort_key' => $row['date'] . sprintf('%020d', $row['id']),
                'raw_date' => $row['date'],
                'date_formatted' => date('M d, Y h:i A', strtotime($row['date'])),
                'type' => 'savings',
                'title' => 'Savings Trans.',
                'amount' => format_naira(abs($amount)),
                'raw_amount' => abs($amount),
                'description' => $description,
                'icon' => 'fa-piggy-bank',
                'transaction_id' => $row['transaction_id'],
                'is_withdrawal' => $is_withdrawal
            ];
        }

        // Loans (Disbursements)
        $stmt = $pdo->prepare("SELECT id, principal, total_payable, remaining_balance, interest_rate, num_installments, date FROM disbursements WHERE client_id = ? ORDER BY date DESC, id DESC LIMIT 20");
        $stmt->execute([$client_id]);
        while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $timeline[] = [
                'timestamp' => strtotime($row['date']),
                'sort_key' => $row['date'] . sprintf('%020d', $row['id']),
                'raw_date' => $row['date'],
                'date_formatted' => date('M d, Y h:i A', strtotime($row['date'])),
                'type' => 'loan',
                'title' => 'Disbursement',
                'amount' => format_naira($row['principal']),
                'raw_amount' => floatval($row['principal']),
                'total_payable' => floatval($row['total_payable']),
                'remaining_balance' => floatval($row['remaining_balance']),
                'interest_rate' => floatval($row['interest_rate']),
                'num_installments' => intval($row['num_installments']),
                'description' => 'Loan Issued',
                'icon' => 'fa-hand-holding-usd',
                'transaction_id' => $row['id']
            ];
        }

        // Repayments
        $stmt = $pdo->prepare("SELECT id, transaction_id, amount_collected, date FROM loan_collections WHERE client_id = ? ORDER BY date DESC, id DESC LIMIT 40");
        $stmt->execute([$client_id]);
        while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $timeline[] = [
                'timestamp' => strtotime($row['date']),
                'sort_key' => $row['date'] . sprintf('%020d', $row['id']),
                'raw_date' => $row['date'],
                'date_formatted' => date('M d, Y h:i A', strtotime($row['date'])),
                'type' => 'repayment',
                'title' => 'Repayment',
                'amount' => format_naira($row['amount_collected']),
                'raw_amount' => floatval($row['amount_collected']),
                'description' => 'Loan Payment',
                'icon' => 'fa-money-bill-wave',
                'transaction_id' => $row['transaction_id']
            ];
        }

        usort($timeline, fn($a, $b) => strcmp($b['sort_key'], $a['sort_key']));
        echo json_encode([
            'success' => true,
            'client_name' => $client['name'],
            'client_details' => [
                'union'             => $client['union'] ?: 'N/A',
                'branch'            => $client['branch_name'] ?: 'N/A',
                'area'              => $client['area_name'] ?: 'N/A',
                'zone'              => $client['zone_name'] ?: 'N/A',
                'officer'           => $client['officer_username'] ?: 'Unassigned',
                'guarantor_name'    => $client['guarantor_name'] ?: 'N/A',
                'guarantor_phone'   => $client['guarantor_phone'] ?: 'N/A',
                'total_withdrawals' => intval($client['total_withdrawals'] ?? 0),
                'total_repayments'  => intval($client['total_repayments'] ?? 0),
                'total_loans'       => intval($client['total_loans'] ?? 0),
            ],
            'summary' => ['savings' => floatval($client['savings_bal'] ?? 0), 'loan' => floatval($client['loan_bal'] ?? 0)],
            'history' => $timeline
        ]);
    } catch (PDOException $e) { echo json_encode(['success'=>false, 'message'=>'DB Error']); }
    exit();
}

// 6. Search Transaction by ID
if (isset($_GET['ajax']) && $_GET['ajax'] === 'search_tx') {
    header('Content-Type: application/json');
    $tx_id = trim($_GET['tx_id'] ?? '');
    if (!$tx_id) { echo json_encode(['success'=>false, 'message'=>'No ID provided']); exit; }

    $tx = null;

    // Check savings
    $stmt = $pdo->prepare("SELECT * FROM saving_collections WHERE transaction_id = ?");
    $stmt->execute([$tx_id]);
    if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $amount = floatval($row['amount']);
        $type = strtolower($row['type'] ?? 'deposit');
        $is_withdrawal = ($amount < 0) || in_array($type, ['withdrawal', 'return_cash', 'return cash', 'debit', 'charge']);
        $tx = [
            'type' => 'savings', 'transaction_id' => $row['transaction_id'],
            'raw_date' => $row['date'], 'date_formatted' => date('M d, Y h:i A', strtotime($row['date'])),
            'raw_amount' => abs($amount), 'amount' => format_naira(abs($amount)),
            'title' => 'Savings Trans.', 'description' => $description ?? 'Deposit/Withdrawal', 'icon' => 'fa-piggy-bank',
            'is_withdrawal' => $is_withdrawal, 'client_id' => $row['client_id']
        ];
    }

    // Check loans
    if (!$tx) {
        $stmt = $pdo->prepare("SELECT * FROM disbursements WHERE id = ?");
        $stmt->execute([$tx_id]);
        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $tx = [
                'type' => 'loan', 'transaction_id' => $row['id'],
                'raw_date' => $row['date'], 'date_formatted' => date('M d, Y h:i A', strtotime($row['date'])),
                'raw_amount' => floatval($row['principal']), 'amount' => format_naira($row['principal']),
                'title' => 'Disbursement', 'description' => 'Loan Issued', 'icon' => 'fa-hand-holding-usd',
                'total_payable' => floatval($row['total_payable']), 'remaining_balance' => floatval($row['remaining_balance']),
                'interest_rate' => floatval($row['interest_rate']), 'num_installments' => intval($row['num_installments']),
                'client_id' => $row['client_id']
            ];
        }
    }

    // Check repayments
    if (!$tx) {
        $stmt = $pdo->prepare("SELECT * FROM loan_collections WHERE transaction_id = ?");
        $stmt->execute([$tx_id]);
        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $tx = [
                'type' => 'repayment', 'transaction_id' => $row['transaction_id'],
                'raw_date' => $row['date'], 'date_formatted' => date('M d, Y h:i A', strtotime($row['date'])),
                'raw_amount' => floatval($row['amount_collected']), 'amount' => format_naira($row['amount_collected']),
                'title' => 'Repayment', 'description' => 'Loan Payment', 'icon' => 'fa-money-bill-wave',
                'client_id' => $row['client_id']
            ];
        }
    }

    if ($tx) {
        // Get client name for context
        $stmt = $pdo->prepare("SELECT name FROM clients WHERE id = ?");
        $stmt->execute([$tx['client_id']]);
        $tx['client_name'] = $stmt->fetchColumn() ?: 'Unknown Client';

        echo json_encode(['success' => true, 'transaction' => $tx]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Transaction not found']);
    }
    exit;
}

$stmt = $pdo->prepare("SELECT full_name, profile_pic FROM users WHERE username = ?");
$stmt->execute([$current_username]);
$u_row = $stmt->fetch(PDO::FETCH_ASSOC);
$profile_pic = $u_row['profile_pic'] ?? 'default_avatar.png';
$full_name = !empty($u_row['full_name']) ? $u_row['full_name'] : $full_name;
$base_path = '../';
$profile_pic_path = $base_path . 'uploads/' . $profile_pic;
$has_profile_pic = file_exists($profile_pic_path) && $profile_pic !== 'default_avatar.png';

// Default Loan settings for Edit Modal calculation
$stmt = $pdo->query("SELECT * FROM disbursement_settings LIMIT 1");
$d_settings = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
$default_interest_rate = isset($d_settings['default_interest_rate']) ? $d_settings['default_interest_rate'] * 100 : 15;
$default_installments = $d_settings['default_num_installments'] ?? 23;

// Fetch filter data for location dropdowns
$zones = [];
$areas = [];
$branches = [];
try {
    $zones = $pdo->query("SELECT id, name FROM zones ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
    $areas = $pdo->query("SELECT id, name, zone_id FROM areas ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
    $branches = $pdo->query("SELECT id, name, area_id FROM branches ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Fail silently, dropdowns will just be empty
}

$area_zone_map = [];
foreach ($areas as $a) {
    $area_zone_map[$a['id']] = $a['zone_id'];
}
?>
<!DOCTYPE html>
<html lang="en" class="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#3b82f6">
    <title>All Clients | CUPAD Admin</title>
    
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@500;600;700&display=swap" rel="stylesheet">
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
            --radius-sm: 0.375rem; --radius-md: 0.5rem; --radius-lg: 1rem; --radius-xl: 1.25rem;
            --nav-height: 70px;
        }
        
        html.dark {
            --bg-body: #0f172a; --bg-card: #1e293b; --bg-alt: #0f172a; --bg-hover: #334155;
            --text-main: #f8fafc; --text-muted: #94a3b8; --text-light: #64748b;
            --border: #334155;
            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.3);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.4);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.5);
        }
        
        * { box-sizing: border-box; margin: 0; padding: 0; outline: none; }
        
        body { 
            font-family: 'Plus Jakarta Sans', sans-serif; 
            background-color: var(--bg-body); 
            color: var(--text-main); 
            padding-top: var(--nav-height); 
            padding-bottom: 90px; 
            -webkit-tap-highlight-color: transparent; 
            transition: background-color 0.3s, color 0.3s;
        }

        a { text-decoration: none; color: inherit; }
        button { font-family: inherit; cursor: pointer; border: none; background: none; }
        input, select { font-family: inherit; }
        
        ::-webkit-scrollbar { width: 5px; height: 5px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
        html.dark ::-webkit-scrollbar-thumb { background: #475569; }

        /* --- UTILITIES --- */
        .flex { display: flex; }
        .flex-col { flex-direction: column; }
        .items-center { align-items: center; }
        .justify-between { justify-content: space-between; }
        .justify-center { justify-content: center; }
        .gap-2 { gap: 0.5rem; } .gap-3 { gap: 0.75rem; } .gap-4 { gap: 1rem; }
        .w-full { width: 100%; }
        .flex-1 { flex: 1; }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .font-bold { font-weight: 700; }
        .font-extrabold { font-weight: 800; }
        .truncate { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .hidden { display: none !important; }

        /* Hide Balance Styles */
        .money-hidden { display: none; letter-spacing: 2px; }
        body.hide-balances .stat-value .money-value { display: none !important; }
        body.hide-balances .stat-value .money-hidden { display: inline-block !important; }

        /* --- NAVBAR --- */
        .main-header { position: fixed; top: 0; left: 0; right: 0; height: var(--nav-height); background: var(--bg-card); opacity: 0.95; backdrop-filter: blur(10px); border-bottom: 1px solid var(--border); z-index: 50; transition: background 0.3s; }
        .navbar { max-width: 1400px; margin: 0 auto; padding: 0 1.5rem; display: flex; align-items: center; justify-content: space-between; height: 100%; }
        .logo { display: flex; align-items: center; gap: 0.75rem; font-weight: 800; font-size: 1.35rem; color: var(--primary); transition: opacity 0.2s; }
        .logo img { height: 38px; }
        .nav-right { display: flex; align-items: center; gap: 1rem; }
        .icon-btn { width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; color: var(--text-muted); transition: 0.2s; }
        .icon-btn:hover { background: var(--bg-body); color: var(--text-main); }
        
        .user-dropdown-wrap { position: relative; }
        .user-pill { display: flex; align-items: center; gap: 0.75rem; padding: 4px 8px 4px 4px; border: 1px solid var(--border); border-radius: 99px; background: var(--bg-card); cursor: pointer; transition: 0.2s; }
        .user-pill:hover { border-color: var(--primary); }
        .user-avatar { width: 34px; height: 34px; border-radius: 50%; object-fit: cover; }
        .user-avatar-fallback { width: 34px; height: 34px; border-radius: 50%; background: var(--bg-body); display: flex; align-items: center; justify-content: center; border: 1px solid var(--border); color: var(--text-muted); }
        .user-info { display: flex; flex-direction: column; line-height: 1.1; padding-right: 0.5rem; }
        .user-name { font-weight: 600; font-size: 0.85rem; color: var(--text-main); }
        .user-role { font-size: 0.7rem; color: var(--text-muted); text-transform: uppercase; }
        
        .dropdown-menu { position: absolute; top: 125%; right: 0; background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius-lg); box-shadow: var(--shadow-md); min-width: 200px; display: none; z-index: 1000; flex-direction: column; overflow: hidden; transform-origin: top right; }
        .dropdown-menu.show { display: flex; animation: scaleIn 0.2s ease; }
        @keyframes scaleIn { from { opacity: 0; transform: scale(0.95); } to { opacity: 1; transform: scale(1); } }
        .dropdown-item { padding: 0.75rem 1rem; display: flex; align-items: center; gap: 0.75rem; font-size: 0.9rem; color: var(--text-main); transition: 0.2s; }
        .dropdown-item:hover { background: var(--bg-body); color: var(--primary); }
        .dropdown-header { padding: 0.75rem 1rem 0.25rem; font-size: 0.75rem; color: var(--text-muted); font-weight: 600; }
        .dropdown-user { padding: 0 1rem 0.75rem; font-weight: 700; color: var(--text-main); }
        .dropdown-divider { height: 1px; background: var(--border); margin: 0; }
        .text-danger { color: var(--danger) !important; }

        /* --- MAIN CONTENT & HEADER --- */
        .main-content { max-width: 1400px; margin: 0 auto; padding: 1.5rem 1.5rem; }
        .page-header { margin-bottom: 1.25rem; display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap; }
        .page-title { font-size: 1.4rem; font-weight: 800; color: var(--text-main); letter-spacing: -0.02em; }
        .page-subtitle { font-size: 0.8rem; color: var(--text-muted); margin-top: 0.2rem; }

        /* --- DASHBOARD STATS --- */
        .dashboard-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1.25rem; margin-bottom: 1.5rem; }
        .stat-card { border-radius: var(--radius-xl); padding: 1.5rem; position: relative; overflow: hidden; box-shadow: var(--shadow-md); color: white; transition: transform 0.2s, box-shadow 0.2s; }
        .stat-card:hover { transform: translateY(-3px); box-shadow: var(--shadow-lg); }
        .card-blue { background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); }
        .card-green { background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%); }
        .card-red { background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); }
        .stat-icon-bg { position: absolute; right: -10px; bottom: -20px; font-size: 6rem; color: white; opacity: 0.1; transform: rotate(-10deg); pointer-events: none; }
        .stat-label { font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; opacity: 0.9; margin-bottom: 0.25rem; }
        .stat-value { font-size: 1.875rem; font-weight: 800; letter-spacing: -0.02em; position: relative; z-index: 10; }

        /* --- CONTROLS TOOLBAR --- */
        .controls-wrapper { display: flex; flex-direction: column; gap: 0.75rem; margin-bottom: 1.25rem; flex-wrap: wrap; }
        @media(min-width: 768px) { .controls-wrapper { flex-direction: row; align-items: center; } }
        .search-box { background: var(--bg-card); border-radius: var(--radius-lg); padding: 0.25rem; border: 1px solid var(--border); flex-grow: 1; display: flex; align-items: center; position: relative; transition: 0.2s; }
        .search-box:focus-within { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15); }
        .search-icon { position: absolute; left: 1rem; color: var(--text-light); }
        .search-input { width: 100%; padding: 0.75rem 1rem 0.75rem 2.75rem; border: none; background: transparent; color: var(--text-main); font-size: 0.95rem; }
        
        /* FORMAL PASTE & SEARCH BOX ENHANCEMENTS */
        .tx-smart-box { position: relative; min-width: 290px; flex-grow: 0; }
        .tx-smart-box .search-input { font-family: 'JetBrains Mono', 'Plus Jakarta Sans', monospace; font-size: 0.88rem; font-weight: 600; padding-right: 5.4rem; }
        
        .tx-action-cluster {
            position: absolute; right: 4px; top: 4px; bottom: 4px;
            display: flex; align-items: center; gap: 3px;
        }

        .btn-paste-smart {
            height: 32px; padding: 0 0.65rem; border-radius: var(--radius-md);
            font-size: 0.74rem; font-weight: 700; background: var(--bg-body);
            color: var(--primary); border: 1px solid var(--border);
            display: inline-flex; align-items: center; gap: 0.35rem; cursor: pointer;
            transition: all 0.2s ease;
        }
        .btn-paste-smart:hover { background: var(--primary); color: #ffffff; border-color: var(--primary); transform: translateY(-1px); }
        .btn-paste-smart:active { transform: translateY(0); }

        .btn-clear-smart {
            width: 28px; height: 28px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 0.75rem; color: var(--text-muted); background: var(--bg-body);
            border: 1px solid var(--border); cursor: pointer; transition: all 0.2s ease;
        }
        .btn-clear-smart:hover { background: var(--border); color: var(--danger); }

        .btn-go-smart {
            width: 32px; height: 32px; border-radius: var(--radius-md);
            display: flex; align-items: center; justify-content: center;
            font-size: 0.82rem; background: var(--primary); color: #ffffff;
            cursor: pointer; transition: all 0.2s ease; box-shadow: 0 2px 4px rgba(59, 130, 246, 0.25);
        }
        .btn-go-smart:hover { background: var(--primary-hover); transform: translateY(-1px); }
        .btn-go-smart:active { transform: translateY(0); }

        .filter-group { display: flex; gap: 0.5rem; flex-grow: 1; }
        .select-input { background: var(--bg-card); border: 1px solid var(--border); color: var(--text-main); border-radius: var(--radius-lg); padding: 0.5rem 2.5rem 0.5rem 1rem; font-size: 0.9rem; font-weight: 600; flex-grow: 1; appearance: none; background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e"); background-repeat: no-repeat; background-position: right 1rem center; background-size: 1em; transition: 0.2s; cursor: pointer; }
        .select-input:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15); }
        .btn-refresh { background: var(--bg-card); border: 1px solid var(--border); color: var(--text-muted); border-radius: var(--radius-lg); width: 48px; display: flex; align-items: center; justify-content: center; transition: 0.2s; }
        .btn-refresh:hover { background: var(--bg-body); color: var(--primary); }

        /* --- CLIENT CARDS --- */
        .client-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 1rem; }
        .client-card { background: var(--bg-card); border-radius: var(--radius-xl); border: 1px solid var(--border); padding: 1.25rem; box-shadow: var(--shadow-sm); display: flex; flex-direction: column; gap: 1rem; transition: transform 0.2s, box-shadow 0.2s; animation: fadeInUp 0.4s ease forwards; }
        .client-card:hover { transform: translateY(-3px); box-shadow: var(--shadow-md); border-color: rgba(59, 130, 246, 0.3); }
        
        .cc-header { display: flex; gap: 0.75rem; align-items: flex-start; }
        .cc-avatar-wrap { position: relative; flex-shrink: 0; }
        .cc-avatar { width: 44px; height: 44px; border-radius: 0.75rem; background: rgba(59, 130, 246, 0.1); color: var(--primary); border: 1px solid rgba(59, 130, 246, 0.2); display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 1.1rem; }
        .cc-status { position: absolute; bottom: -4px; right: -4px; width: 14px; height: 14px; border-radius: 50%; border: 2px solid var(--bg-card); z-index: 2; }
        .cc-info { overflow: hidden; flex: 1; }
        .cc-name { font-size: 0.95rem; font-weight: 700; color: var(--text-main); line-height: 1.2; margin-bottom: 0.25rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .cc-badge { display: inline-block; font-size: 0.65rem; background: var(--bg-body); color: var(--text-muted); padding: 0.15rem 0.4rem; border-radius: 0.25rem; font-weight: 600; border: 1px solid var(--border); }
        
        .cc-stats { display: flex; gap: 0.5rem; background: var(--bg-body); padding: 0.75rem; border-radius: var(--radius-lg); border: 1px solid var(--border); }
        .cc-stat-box { flex: 1; text-align: center; }
        .cc-stat-label { font-size: 0.65rem; text-transform: uppercase; font-weight: 700; color: var(--text-muted); margin-bottom: 0.15rem; }
        .cc-stat-val { font-size: 1rem; font-weight: 800; }
        .cc-stat-val.green { color: var(--success); }
        .cc-stat-val.red { color: var(--danger); }
        .cc-stat-val.gray { color: var(--text-light); }
        .cc-divider { width: 1px; background: var(--border); margin: 0.25rem 0; }
        
        .cc-actions { display: flex; gap: 0.5rem; margin-top: auto; padding-top: 0.25rem; }
        .btn { flex: 1; padding: 0.55rem; border-radius: var(--radius-md); font-size: 0.85rem; font-weight: 700; display: flex; align-items: center; justify-content: center; gap: 0.4rem; transition: 0.2s; }
        .btn-secondary { background: var(--bg-body); color: var(--text-main); border: 1px solid var(--border); }
        .btn-secondary:hover { background: var(--border); }
        .btn-primary { background: var(--primary); color: white; box-shadow: 0 4px 6px -1px rgba(59,130,246,0.3); }
        .btn-primary:hover { background: var(--primary-hover); }
        .btn-danger { background: var(--danger); color: white; box-shadow: 0 4px 6px -1px rgba(239,68,68,0.3); }
        .btn-danger:hover { background: var(--danger-hover); }

        /* --- MODALS --- */
        .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.6); backdrop-filter: blur(4px); z-index: 200; display: none; align-items: center; justify-content: center; opacity: 0; transition: opacity 0.3s ease; }
        .modal-overlay.active { display: flex; opacity: 1; }
        .modal-content { background: var(--bg-card); width: 92%; max-width: 480px; border-radius: var(--radius-xl); box-shadow: var(--shadow-lg); border: 1px solid var(--border); transform: scale(0.95) translateY(10px); transition: 0.3s cubic-bezier(0.34, 1.56, 0.64, 1); display: flex; flex-direction: column; max-height: 90vh; }
        .modal-overlay.active .modal-content { transform: scale(1) translateY(0); }
        
        .modal-header { padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; background: var(--bg-card); border-radius: var(--radius-xl) var(--radius-xl) 0 0; }
        .modal-title { font-size: 1.1rem; font-weight: 800; color: var(--text-main); }
        .modal-subtitle { font-size: 0.75rem; color: var(--text-muted); font-weight: 500; margin-top: 0.2rem; }
        .modal-close { width: 32px; height: 32px; border-radius: 50%; background: var(--bg-body); color: var(--text-muted); display: flex; align-items: center; justify-content: center; transition: 0.2s; }
        .modal-close:hover { background: var(--border); color: var(--text-main); }
        
        .modal-body { padding: 1.5rem; overflow-y: auto; flex: 1; }
        .modal-footer { padding: 1.25rem 1.5rem; border-top: 1px solid var(--border); display: flex; gap: 0.75rem; background: var(--bg-body); border-radius: 0 0 var(--radius-xl) var(--radius-xl); }

        /* Form Inputs */
        .form-group { margin-bottom: 1.25rem; position: relative; }
        .form-label { display: block; font-size: 0.7rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; margin-bottom: 0.4rem; letter-spacing: 0.05em; }
        .form-input { width: 100%; padding: 0.8rem 1rem; border-radius: var(--radius-md); border: 1px solid var(--border); background: var(--bg-card); color: var(--text-main); font-size: 0.95rem; font-weight: 500; transition: 0.2s; outline: none; }
        .form-input:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15); }
        .form-input:disabled { background: var(--bg-body); color: var(--text-light); cursor: not-allowed; }
        .form-icon { position: absolute; left: 1rem; top: 2.1rem; color: var(--text-light); font-size: 0.9rem; }
        .has-icon { padding-left: 2.5rem; }

        .override-box { background: rgba(239, 68, 68, 0.05); border: 1px solid rgba(239, 68, 68, 0.2); border-radius: var(--radius-lg); padding: 1rem; margin-top: 2rem; }
        .override-title { font-size: 0.75rem; font-weight: 800; color: var(--danger); text-transform: uppercase; margin-bottom: 0.75rem; display: flex; align-items: center; gap: 0.4rem; }
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }

        /* --- HISTORY TIMELINE --- */
        .history-tabs { display: flex; border-bottom: 1px solid var(--border); background: var(--bg-card); }
        .tab-btn { flex: 1; padding: 0.8rem; text-align: center; font-size: 0.8rem; font-weight: 700; text-transform: uppercase; color: var(--text-muted); border-bottom: 2px solid transparent; transition: 0.2s; }
        .tab-btn.active { color: var(--primary); border-bottom-color: var(--primary); font-weight: 800; }
        
        .timeline-month {
            position: sticky; top: 0; background: rgba(248, 250, 252, 0.95); backdrop-filter: blur(4px); 
            padding: 0.5rem 0.8rem; margin-bottom: 0.5rem; font-size: 0.7rem; font-weight: 800; color: var(--text-muted); 
            text-transform: uppercase; z-index: 10; border-bottom: 1px solid var(--border);
            display: flex; justify-content: space-between; align-items: center;
        }
        .dark .timeline-month { background: rgba(15, 23, 42, 0.95); }

        .month-summary { font-size: 0.65rem; font-weight: 700; text-transform: none; }
        .month-summary .summary-positive { color: var(--success); }
        .month-summary .summary-negative { color: var(--danger); }
        .month-summary .summary-neutral { color: var(--text-light); }
        .month-summary .summary-loan { color: #ea580c; }
        .month-summary .summary-repay { color: #9333ea; }
        
        .tx-item { background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius-lg); padding: 0.875rem; margin-bottom: 0.75rem; display: flex; justify-content: space-between; align-items: center; transition: 0.2s; box-shadow: var(--shadow-sm); }
        .tx-item:hover { border-color: rgba(59, 130, 246, 0.4); }
        .tx-left { display: flex; gap: 0.75rem; align-items: center; width: 65%; }
        .tx-icon { width: 40px; height: 40px; border-radius: var(--radius-md); display: flex; align-items: center; justify-content: center; font-size: 1.1rem; flex-shrink: 0; }
        .tx-info { overflow: hidden; }
        .tx-title { font-size: 0.875rem; font-weight: 700; color: var(--text-main); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .tx-date { font-size: 0.65rem; font-weight: 500; color: var(--text-muted); margin: 0.1rem 0 0.25rem; }
        .tx-badge { display: inline-block; padding: 0.15rem 0.4rem; border-radius: 0.25rem; font-size: 0.55rem; font-weight: 800; text-transform: uppercase; border: 1px solid; }
        
        .tx-right { display: flex; flex-direction: column; align-items: flex-end; gap: 0.25rem; flex-shrink: 0; }
        .tx-amount { font-size: 0.95rem; font-weight: 800; }
        .tx-id { font-family: monospace; font-size: 0.6rem; color: var(--text-light); background: var(--bg-body); padding: 0.15rem 0.3rem; border-radius: 0.2rem; }
        .tx-actions { display: flex; gap: 0.4rem; margin-top: 0.25rem; }
        .tx-action-btn { width: 24px; height: 24px; border-radius: 4px; display: flex; align-items: center; justify-content: center; font-size: 0.6rem; transition: 0.2s; }
        
        /* Tx Colors */
        .tx-savings-in .tx-icon { background: #dcfce7; color: #16a34a; } .dark .tx-savings-in .tx-icon { background: rgba(22, 163, 74, 0.2); }
        .tx-savings-in .tx-badge { background: #f0fdf4; color: #16a34a; border-color: #bbf7d0; } .dark .tx-savings-in .tx-badge { background: rgba(22, 163, 74, 0.1); border-color: rgba(22, 163, 74, 0.3); }
        .tx-savings-in .tx-amount { color: #16a34a; }
        
        .tx-savings-out .tx-icon { background: #fee2e2; color: #dc2626; } .dark .tx-savings-out .tx-icon { background: rgba(220, 38, 38, 0.2); }
        .tx-savings-out .tx-badge { background: #fef2f2; color: #dc2626; border-color: #fecaca; } .dark .tx-savings-out .tx-badge { background: rgba(220, 38, 38, 0.1); border-color: rgba(220, 38, 38, 0.3); }
        .tx-savings-out .tx-amount { color: #dc2626; }
        
        .tx-loan .tx-icon { background: #ffedd5; color: #ea580c; } .dark .tx-loan .tx-icon { background: rgba(234, 88, 12, 0.2); }
        .tx-loan .tx-badge { background: #fff7ed; color: #ea580c; border-color: #fed7aa; } .dark .tx-loan .tx-badge { background: rgba(234, 88, 12, 0.1); border-color: rgba(234, 88, 12, 0.3); }
        .tx-loan .tx-amount { color: #ea580c; }
        
        .tx-repay .tx-icon { background: #f3e8ff; color: #9333ea; } .dark .tx-repay .tx-icon { background: rgba(147, 51, 234, 0.2); }
        .tx-repay .tx-badge { background: #faf5ff; color: #9333ea; border-color: #e9d5ff; } .dark .tx-repay .tx-badge { background: rgba(147, 51, 234, 0.1); border-color: rgba(147, 51, 234, 0.3); }
        .tx-repay .tx-amount { color: #9333ea; }

        .tx-btn-edit { background: #eff6ff; color: #3b82f6; } .tx-btn-edit:hover { background: #dbeafe; } .dark .tx-btn-edit { background: rgba(59, 130, 246, 0.15); }
        .tx-btn-del { background: #fef2f2; color: #ef4444; } .tx-btn-del:hover { background: #fee2e2; } .dark .tx-btn-del { background: rgba(239, 68, 68, 0.15); }

        /* Tx Calc Box */
        .calc-box { background: var(--bg-body); padding: 1rem; border-radius: var(--radius-lg); border: 1px solid var(--border); margin-top: 1rem; }
        .calc-title { font-size: 0.65rem; font-weight: 800; color: var(--text-muted); text-transform: uppercase; margin-bottom: 0.75rem; display: flex; align-items: center; gap: 0.4rem; }
        
        /* Load More */
        .load-more-wrap { text-align: center; margin-top: 2rem; padding-bottom: 2rem; }
        .btn-load { padding: 0.75rem 2rem; background: var(--bg-card); border: 1px solid var(--border); border-radius: 99px; font-size: 0.875rem; font-weight: 700; color: var(--text-main); box-shadow: var(--shadow-sm); transition: 0.2s; }
        .btn-load:hover { box-shadow: var(--shadow-md); transform: translateY(-1px); }

        /* Export btn top */
        .btn-export-top { display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.55rem 1rem; background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius-lg); font-size: 0.8rem; font-weight: 700; color: var(--primary); cursor: pointer; transition: 0.2s; box-shadow: var(--shadow-sm); white-space: nowrap; }
        .btn-export-top:hover { background: rgba(59,130,246,0.08); border-color: var(--primary); box-shadow: var(--shadow-md); }

        /* Delete Confirm styles */
        .del-header { background: rgba(239, 68, 68, 0.1); border-bottom: 1px solid rgba(239, 68, 68, 0.2); }
        .del-title { color: var(--danger); font-size: 1.1rem; font-weight: 800; display: flex; align-items: center; gap: 0.5rem; }
        .del-preview-box { background: var(--bg-body); padding: 1rem; border-radius: var(--radius-lg); border: 1px solid var(--border); margin-top: 1rem; }
        .del-row { display: flex; justify-content: space-between; font-size: 0.85rem; margin-bottom: 0.4rem; }
        .del-label { color: var(--text-muted); }
        .del-val { font-weight: 700; color: var(--text-main); }

        /* Toast Notifications */
        .toast {
            position: fixed; top: 20px; left: 50%;
            background: var(--bg-card); padding: 0.8rem 1.2rem;
            border-radius: var(--radius-lg); border-left: 4px solid var(--primary);
            box-shadow: var(--shadow-lg); z-index: 9999;
            display: flex; align-items: center; gap: 0.75rem;
            opacity: 0;
            transform: translateY(-20px) translateX(-50%);
            transition: opacity 0.3s, transform 0.3s;
            animation: toast-in 0.4s forwards cubic-bezier(0.21, 1.02, 0.73, 1);
        }
        @keyframes toast-in {
            from { opacity: 0; transform: translateY(-20px) translateX(-50%); }
            to { opacity: 1; transform: translateY(0) translateX(-50%); }
        }
    </style>
</head>
<body class="hide-balances">

    <header class="main-header">
        <div class="navbar">
            <a href="dashboard.php" class="logo">
                <img src="<?php echo htmlspecialchars($base_path . 'uploads/CUPAD LOGO.png'); ?>" alt="Logo">
                <span>CUPAD</span>
            </a>
            <div class="nav-right">
                <a href="dashboard.php" class="icon-btn" title="Dashboard"><i class="fas fa-home"></i></a>
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
                            <span class="user-role"><?php echo ucfirst(htmlspecialchars($role)); ?></span>
                        </div>
                        <i class="fas fa-chevron-down" style="font-size:0.7rem;"></i>
                    </div>
                    <div class="dropdown-menu" id="userDropdown">
                        <div class="dropdown-header">SIGNED IN AS</div>
                        <div class="dropdown-user"><?php echo htmlspecialchars($current_username); ?></div>
                        <div class="dropdown-divider"></div>
                        <a href="profile.php" class="dropdown-item"><i class="fas fa-user-circle" style="color:var(--primary)"></i> My Profile</a>
                        <div class="dropdown-divider"></div>
                        <a href="../logout.php" class="dropdown-item text-danger"><i class="fas fa-sign-out-alt"></i> Logout</a>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <main class="main-content">
        <div class="page-header">
            <div class="page-header-left">
                <h1 class="page-title">
                    All Clients
                    <button id="toggleBalanceBtn" onclick="toggleBalances()" style="background:var(--bg-body); border:1px solid var(--border); border-radius:50%; width:34px; height:34px; font-size:1rem; color:var(--text-muted); cursor:pointer; margin-left:0.75rem; vertical-align:middle; transition:0.2s;" title="Toggle Balances Visibility">
                        <i class="fas fa-eye-slash"></i>
                    </button>
                </h1>
                <p class="page-subtitle">View and manage all client profiles and transactions.</p>
            </div>
        </div>

        <div class="dashboard-grid">
            <div class="stat-card card-blue">
                <i class="fas fa-users stat-icon-bg"></i>
                <div class="stat-label">Total Clients</div>
                <div class="stat-value" id="totalClients">-</div>
            </div>
            <div class="stat-card card-green">
                <i class="fas fa-wallet stat-icon-bg"></i>
                <div class="stat-label">Total Savings</div>
                <div class="stat-value" id="totalSavings"><span class="money-value">-</span><span class="money-hidden">****</span></div>
            </div>
            <div class="stat-card card-red">
                <i class="fas fa-hand-holding-usd stat-icon-bg"></i>
                <div class="stat-label">Outstanding Loans</div>
                <div class="stat-value" id="totalLoans"><span class="money-value">-</span><span class="money-hidden">****</span></div>
            </div>
        </div>

        <div class="controls-wrapper">
            <!-- Client Search -->
            <div class="search-box">
                <i class="fas fa-search search-icon"></i>
                <input type="text" id="searchInput" class="search-input" placeholder="Search by Name or Union...">
            </div>
            
            <!-- Upgraded Transaction Search with Smart Formal Paste & Clear -->
            <div class="search-box tx-smart-box">
                <i class="fas fa-hashtag search-icon"></i>
                <input type="text" id="searchTxInput" class="search-input" placeholder="Find Transaction ID..." 
                       oninput="handleTxInputState()" 
                       onkeypress="if(event.key === 'Enter') searchTx()">
                
                <div class="tx-action-cluster">
                    <!-- Paste & Go Button -->
                    <button id="pasteTxBtn" type="button" class="btn-paste-smart" onclick="pasteAndSearchTx()" title="Paste ID from clipboard and search immediately">
                        <i class="fas fa-paste"></i> <span>Paste</span>
                    </button>

                    <!-- Quick Clear Button -->
                    <button id="clearTxBtn" type="button" class="btn-clear-smart" onclick="clearTxSearch()" title="Clear search" style="display: none;">
                        <i class="fas fa-times"></i>
                    </button>

                    <!-- Search Button -->
                    <button id="submitTxBtn" type="button" class="btn-go-smart" onclick="searchTx()" title="Search Transaction ID" style="display: none;">
                        <i class="fas fa-arrow-right"></i>
                    </button>
                </div>
            </div>

            <!-- Filters -->
            <div class="filter-group">
                <select id="sortSelect" class="select-input" onchange="resetAndLoad()">
                    <option value="name_asc">Name (A-Z)</option>
                    <option value="savings_desc">Highest Savings</option>
                    <option value="loan_desc">Highest Loan</option>
                </select>
                <button class="btn-refresh" id="refreshBtn" onclick="forceRefresh()" title="Refresh Data">
                    <i class="fas fa-sync-alt"></i>
                </button>
            </div>
        </div>
        
        <!-- Location Filters -->
        <div class="controls-wrapper" style="margin-top: -0.5rem;">
            <div class="filter-group">
                <select id="zoneSelect" class="select-input" onchange="filterLocations('zone'); resetAndLoad()">
                    <option value="">All Zones</option>
                    <?php foreach($zones as $z): ?>
                        <option value="<?= htmlspecialchars($z['id']) ?>"><?= htmlspecialchars($z['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <select id="areaSelect" class="select-input" onchange="filterLocations('area'); resetAndLoad()">
                    <option value="">All Areas</option>
                    <?php foreach($areas as $a): ?>
                        <option value="<?= htmlspecialchars($a['id']) ?>" data-zone="<?= htmlspecialchars($a['zone_id']) ?>"><?= htmlspecialchars($a['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <select id="branchSelect" class="select-input" onchange="resetAndLoad()">
                    <option value="">All Branches</option>
                    <?php foreach($branches as $b): 
                        $z_id = $area_zone_map[$b['area_id']] ?? '';
                    ?>
                        <option value="<?= htmlspecialchars($b['id']) ?>" data-area="<?= htmlspecialchars($b['area_id']) ?>" data-zone="<?= htmlspecialchars($z_id) ?>"><?= htmlspecialchars($b['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div id="clientList" class="client-grid"></div>
        <div id="loadMoreContainer" class="load-more-wrap hidden">
            <button id="loadMoreBtn" class="btn-load" onclick="loadClients(true)">Load More Clients</button>
        </div>
    </main>

    <!-- Modal: Search Transaction Result -->
    <div id="txResultModal" class="modal-overlay" style="z-index: 250;">
        <div class="modal-content" style="max-width: 500px;">
            <div class="modal-header">
                <div class="modal-title">Transaction Found</div>
                <button type="button" class="modal-close" onclick="closeModal('txResultModal')"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <p style="font-size:0.8rem; color:var(--text-muted); margin-bottom:1rem;">Client: <strong id="txResultClientName" onclick="copyAndSearchClient(this)" title="Click to copy &amp; search" style="color:var(--primary);cursor:pointer;text-decoration:underline dotted;user-select:none;"></strong></p>
                <div id="txResultBody"></div>
            </div>
        </div>
    </div>

    <!-- 1. Edit Client Details Modal -->
    <div id="editModal" class="modal-overlay">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <div class="modal-title">Edit Client Data</div>
                    <div class="modal-subtitle">Update security details and overrides</div>
                </div>
                <button type="button" class="modal-close" onclick="closeModal('editModal')"><i class="fas fa-times"></i></button>
            </div>
            <form id="editForm" class="flex flex-col flex-1" style="overflow:hidden;">
                <div class="modal-body">
                    <input type="hidden" id="editClientId" name="client_id">
                    <div class="form-group">
                        <label class="form-label">Client Name</label>
                        <input type="text" id="editClientName" class="form-input" disabled>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Guarantor Name</label>
                        <i class="fas fa-user form-icon"></i>
                        <input type="text" id="editGuarantorName" name="guarantor_name" class="form-input has-icon">
                    </div>
                    <div class="form-group" style="margin-bottom:0;">
                        <label class="form-label">Guarantor Phone</label>
                        <i class="fas fa-phone form-icon"></i>
                        <input type="tel" id="editGuarantorPhone" name="guarantor_phone" class="form-input has-icon" style="font-family:monospace;">
                    </div>
                    
                    <div class="override-box">
                        <h4 class="override-title"><i class="fas fa-exclamation-triangle"></i> Financial Overrides</h4>
                        <div class="grid-2">
                            <div>
                                <label class="form-label" style="font-size:0.65rem;">Savings Balance</label>
                                <input type="number" step="0.01" id="editSavingsBalance" name="savings_balance" class="form-input" required>
                            </div>
                            <div>
                                <label class="form-label" style="font-size:0.65rem;">Loan Balance</label>
                                <input type="number" step="0.01" id="editLoanBalance" name="loan_balance" class="form-input" required>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-cancel" onclick="closeModal('editModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <!-- 2. Client History Modal -->
    <div id="historyModal" class="modal-overlay">
        <div class="modal-content" style="max-width:500px; max-height: 95vh;">
            <div class="modal-header flex-col items-start gap-2" style="padding-bottom:1rem;">
                <div class="flex justify-between w-full items-center">
                    <h3 class="modal-title truncate" id="historyModalTitle">History</h3>
                    <button class="modal-close" onclick="closeModal('historyModal')"><i class="fas fa-times"></i></button>
                </div>
                <div id="historyModalSubtitle" class="w-full"></div>
                <button id="exportHistoryBtn" onclick="exportHistoryPDF()" class="btn-export-top" style="display:none;">
                    <i class="fas fa-file-pdf"></i> Export PDF
                </button>
            </div>
            
            <div id="historySearchContainer" style="padding: 0.75rem 1.5rem; border-bottom: 1px solid var(--border); background: var(--bg-body); display:none;">
                <div class="search-box" style="padding: 0.15rem;">
                    <i class="fas fa-search search-icon" style="font-size: 0.8rem; left: 0.8rem;"></i>
                    <input type="text" id="historySearchInput" class="search-input" placeholder="Search amounts, IDs, dates..." style="padding: 0.5rem 1rem 0.5rem 2.2rem; font-size: 0.85rem;" oninput="renderHistory(currentHistoryFilter)">
                </div>
            </div>

            <div id="historyFilters" class="history-tabs" style="display:none;">
                <button class="tab-btn active history-tab" onclick="filterHistory('all', this)">All</button>
                <button class="tab-btn history-tab" onclick="filterHistory('savings', this)">Save</button>
                <button class="tab-btn history-tab" onclick="filterHistory('loan', this)">Loan</button>
                <button class="tab-btn history-tab" onclick="filterHistory('repayment', this)">Repay</button>
            </div>
            
            <div class="modal-body" id="historyModalBody" style="background:var(--bg-body);"></div>
        </div>
    </div>

    <!-- 3. Edit Transaction Modal -->
    <div id="editTxModal" class="modal-overlay" style="z-index: 300;">
        <div class="modal-content" style="max-width: 500px;">
            <div class="modal-header">
                <div class="modal-title"><i class="fas fa-edit" style="color:var(--primary); margin-right:0.5rem;"></i>Edit Transaction</div>
                <button type="button" class="modal-close" onclick="closeModal('editTxModal')"><i class="fas fa-times"></i></button>
            </div>
            <form id="editTxForm" class="flex flex-col flex-1" style="overflow:hidden;">
                <div class="modal-body">
                    <input type="hidden" id="editTxId">
                    <input type="hidden" id="editTxType">
                    
                    <div class="form-group">
                        <label class="form-label">Date & Time</label>
                        <input type="datetime-local" id="editTxDate" class="form-input" required>
                    </div>
                    
                    <div class="form-group" style="margin-bottom:0;">
                        <label id="lblTxAmount" class="form-label">Amount</label>
                        <i class="fas fa-naira-sign form-icon" style="top:2rem;"></i>
                        <input type="number" step="0.01" id="editTxAmount" class="form-input has-icon" style="font-size:1.2rem; font-weight:800;" required>
                    </div>

                    <!-- Loan Specific Fields -->
                    <div id="txLoanFields" class="hidden flex-col gap-3 mt-4 pt-4" style="border-top:1px solid var(--border);">
                        <div class="grid-2">
                            <div class="form-group" style="margin:0;">
                                <label class="form-label">Interest Rate (%)</label>
                                <input type="number" step="0.01" id="editTxRate" class="form-input" style="color:var(--primary); font-weight:800;">
                            </div>
                            <div class="form-group" style="margin:0;">
                                <label class="form-label">Installments</label>
                                <input type="number" id="editTxInstallments" class="form-input" style="color:var(--primary); font-weight:800;">
                            </div>
                        </div>

                        <div class="calc-box">
                            <h4 class="calc-title"><i class="fas fa-calculator"></i> Balances</h4>
                            <div class="grid-2" style="margin-bottom:0.75rem;">
                                <div>
                                    <label class="form-label" style="font-size:0.65rem;">Total Payable</label>
                                    <input type="number" step="0.01" id="editTxPayable" class="form-input">
                                </div>
                                <div>
                                    <label class="form-label" style="font-size:0.65rem;">Remaining Bal</label>
                                    <input type="number" step="0.01" id="editTxBalance" class="form-input">
                                </div>
                            </div>
                            <div>
                                <label class="form-label" style="font-size:0.65rem;">Amount per Installment</label>
                                <input type="number" step="0.01" id="editTxInstAmt" class="form-input">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-cancel" onclick="closeModal('editTxModal')">Cancel</button>
                    <button type="submit" id="saveTxBtn" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <!-- 4. Delete Transaction Confirm Modal -->
    <div id="deleteTxModal" class="modal-overlay" style="z-index: 300;">
        <div class="modal-content" style="max-width: 400px;">
            <div class="modal-header del-header">
                <span class="del-title"><i class="fas fa-exclamation-triangle"></i> Confirm Delete</span>
            </div>
            <div class="modal-body">
                <p style="text-align:center; font-size:0.9rem; margin-bottom:1rem;">Are you sure you want to delete this transaction? It will automatically adjust related balances.</p>
                <div class="del-preview-box">
                    <div class="del-row"><span class="del-label">Type:</span><span id="delTxType" class="del-val" style="text-transform:uppercase;"></span></div>
                    <div class="del-row"><span class="del-label">Amount:</span><span id="delTxAmt" class="del-val"></span></div>
                    <div class="del-row" style="margin-bottom:0; font-size:0.75rem;"><span class="del-label">ID:</span><span id="delTxId" class="del-val" style="font-family:monospace;"></span></div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-cancel" onclick="closeModal('deleteTxModal')">Cancel</button>
                <button id="confirmDelTxBtn" class="btn btn-danger" onclick="executeDeleteTx()">Delete</button>
            </div>
        </div>
    </div>

    <script>
        // GLOBALS
        let currentPage = 1, isLoading = false, currentClientHistory = [];
        let currentClientId = null;
        let currentClientDetails = {};
        let currentClientSummary = {};
        let currentHistoryFilter = 'all';
        let txDeleteTarget = { id: null, type: null };
        let manRate = false, manInst = false;
        let searchedTxResult = null;
        
        const DEF_RATE = <?= $default_interest_rate ?>;
        const DEF_INST = <?= $default_installments ?>;

        const $ = id => document.getElementById(id);
        const formatMoney = num => '₦' + Number(num).toLocaleString('en-US', {minimumFractionDigits: 0, maximumFractionDigits: 0});

        function showToast(msg, type) {
            const el = document.createElement('div');
            el.className = 'toast'; el.style.borderLeftColor = type === 'success' ? 'var(--success)' : 'var(--danger)';
            el.innerHTML = `<i class="fas ${type==='success'?'fa-check-circle':'fa-exclamation-circle'}" style="color:${type==='success'?'var(--success)':'var(--danger)'}; font-size:1.25rem;"></i> <span style="font-size:0.875rem; font-weight:600;">${msg}</span>`;
            document.body.appendChild(el);
            setTimeout(() => { el.style.opacity = '0'; el.style.transform = 'translateY(-20px) translateX(-50%)'; setTimeout(() => el.remove(), 400); }, 3000);
        }

        function closeModal(id) { $(id).classList.remove('active'); setTimeout(() => $(id).style.display = 'none', 300); }
        function openModal(id) { $(id).style.display = 'flex'; setTimeout(() => $(id).classList.add('active'), 10); }

        // --- DASHBOARD UI LOGIC ---
        const themeBtn = $('themeToggle');
        if(localStorage.getItem('theme') === 'dark') document.documentElement.classList.add('dark');
        if (themeBtn) themeBtn.onclick = () => { const isDark = document.documentElement.classList.toggle('dark'); localStorage.setItem('theme', isDark ? 'dark' : 'light'); };
        
        const userTrigger = $('userDropdownTrigger');
        if(userTrigger) {
            userTrigger.onclick = (e) => { e.stopPropagation(); $('userDropdown').classList.toggle('show'); };
            document.onclick = (e) => { if (!$('userDropdown').contains(e.target)) $('userDropdown').classList.remove('show'); };
        }

        // Toggle Visibility of Balances
        function toggleBalances() {
            const body = document.body;
            const btnIcon = document.querySelector('#toggleBalanceBtn i');
            
            if (body.classList.contains('hide-balances')) {
                body.classList.remove('hide-balances');
                if (btnIcon) btnIcon.className = 'fas fa-eye';
            } else {
                body.classList.add('hide-balances');
                if (btnIcon) btnIcon.className = 'fas fa-eye-slash';
            }
            localStorage.setItem('hideBalances', body.classList.contains('hide-balances'));
        }

        // Dropdown Filtering Logic
        function filterLocations(trigger) {
            const zoneId = $('zoneSelect').value;
            
            if (trigger === 'zone') {
                $('areaSelect').value = "";
                $('branchSelect').value = "";
            } else if (trigger === 'area') {
                $('branchSelect').value = "";
            }
            
            const areaId = $('areaSelect').value;

            Array.from($('areaSelect').options).forEach(opt => {
                if(opt.value === "") return;
                const show = (zoneId === "" || opt.dataset.zone === zoneId);
                opt.style.display = show ? "" : "none";
                opt.hidden = !show;
                opt.disabled = !show;
            });

            Array.from($('branchSelect').options).forEach(opt => {
                if(opt.value === "") return;
                let show = true;
                if (zoneId !== "" && opt.dataset.zone !== zoneId) show = false;
                if (areaId !== "" && opt.dataset.area !== areaId) show = false;
                opt.style.display = show ? "" : "none";
                opt.hidden = !show;
                opt.disabled = !show;
            });
        }

        async function loadClients(append = false) {
            if(isLoading) return; isLoading = true;
            const search = $('searchInput').value;
            const sort = $('sortSelect').value;
            
            const zone = $('zoneSelect') ? $('zoneSelect').value : '';
            const area = $('areaSelect') ? $('areaSelect').value : '';
            const branch = $('branchSelect') ? $('branchSelect').value : '';
            
            const page = append ? currentPage + 1 : 1;
            if (!append) $('clientList').innerHTML = '<div style="grid-column:1/-1; text-align:center; padding:3rem 0;"><i class="fas fa-spinner fa-spin" style="font-size:2rem; color:var(--primary);"></i></div>';
            
            try {
                const res = await fetch(`?ajax=get_clients_data&page=${page}&search=${encodeURIComponent(search)}&sort=${sort}&zone_id=${zone}&area_id=${area}&branch_id=${branch}`);
                const data = await res.json();
                if(data.success) {
                    if(!append) {
                        $('totalClients').textContent = data.summary.total_clients;
                        $('totalSavings').innerHTML = `<span class="money-value">${formatMoney(data.summary.total_savings)}</span><span class="money-hidden">****</span>`;
                        $('totalLoans').innerHTML = `<span class="money-value">${formatMoney(data.summary.total_loans)}</span><span class="money-hidden">****</span>`;
                        $('clientList').innerHTML = '';
                    }
                    data.clients.forEach((c) => {
                        const statusColor = c.outstanding_loan > 0 ? (c.outstanding_loan > c.savings ? 'var(--danger)' : 'var(--warning)') : 'var(--success)';
                        const card = document.createElement('div'); card.className = 'client-card';
                        const wdPill = c.withdrawal_count > 0 ? `<span style="font-size:0.6rem;font-weight:700;background:rgba(220,38,38,0.08);color:#dc2626;border:1px solid rgba(220,38,38,0.2);padding:0.1rem 0.35rem;border-radius:0.25rem;"><i class="fas fa-arrow-up" style="font-size:0.5rem;"></i> ${c.withdrawal_count}wd</span>` : '';
                        const rpPill = c.repayment_count > 0 ? `<span style="font-size:0.6rem;font-weight:700;background:rgba(147,51,234,0.08);color:#9333ea;border:1px solid rgba(147,51,234,0.2);padding:0.1rem 0.35rem;border-radius:0.25rem;"><i class="fas fa-rotate-left" style="font-size:0.5rem;"></i> ${c.repayment_count}rp</span>` : '';
                        card.innerHTML = `
                            <div class="cc-header">
                                <div class="cc-avatar-wrap">
                                    <div class="cc-avatar">${c.name.substring(0,2).toUpperCase()}</div>
                                    <div class="cc-status" style="background:${statusColor}"></div>
                                </div>
                                <div class="cc-info">
                                    <h3 class="cc-name" title="${c.name}">${c.name}</h3>
                                    <div style="display:flex;gap:0.25rem;flex-wrap:wrap;align-items:center;margin-top:0.2rem;">
                                        <span class="cc-badge"><i class="fas fa-users" style="opacity:0.7; margin-right:2px;"></i> ${c.union}</span>
                                        ${wdPill}${rpPill}
                                    </div>
                                    <div style="font-size:0.65rem; color:var(--text-muted); margin-top:0.3rem;"><i class="fas fa-map-marker-alt"></i> ${c.branch_name}</div>
                                </div>
                            </div>
                            <div class="cc-stats">
                                <div class="cc-stat-box"><div class="cc-stat-label">Savings</div><div class="cc-stat-val green">${formatMoney(c.savings)}</div></div>
                                <div class="cc-divider"></div>
                                <div class="cc-stat-box"><div class="cc-stat-label">Loan</div><div class="cc-stat-val ${c.outstanding_loan>0?'red':'gray'}">${formatMoney(c.outstanding_loan)}</div></div>
                            </div>
                            <div class="cc-actions">
                                <button class="btn btn-secondary" onclick="editClient('${c.id}', '${c.name.replace(/'/g, "\\'")}', ${c.savings}, ${c.outstanding_loan}, '${c.guarantor_name}', '${c.guarantor_phone}')"><i class="fas fa-pen"></i> Edit</button>
                                <button class="btn btn-primary" onclick="viewHistory('${c.id}')"><i class="fas fa-history"></i> History</button>
                            </div>
                        `;
                        $('clientList').appendChild(card);
                    });
                    currentPage = page;
                    $('loadMoreContainer').classList.toggle('hidden', !data.pagination.has_more);
                }
            } catch(e) {} finally { isLoading = false; }
        }

        function resetAndLoad() { currentPage = 1; loadClients(false); }
        function forceRefresh() { resetAndLoad(); }
        let searchTimeout;
        const searchIcon = document.querySelector('.search-box .search-icon');
        $('searchInput').addEventListener('input', () => {
            clearTimeout(searchTimeout);
            searchIcon.className = 'fas fa-spinner fa-spin search-icon';
            searchTimeout = setTimeout(() => { searchIcon.className = 'fas fa-search search-icon'; resetAndLoad(); }, 400);
        });

        // --- SMART PASTE & TRANSACTION SEARCH LOGIC ---
        function cleanTxInput(str) {
            if (!str) return '';
            // Strip leading/trailing quotes, '#' signs, spaces, or 'TX:' prefixes
            return str.replace(/^["'#\s]+|["'#\s]+$/g, '').replace(/^(id|tx|trx)[:#\s]*/i, '').trim();
        }

        function handleTxInputState() {
            const val = $('searchTxInput').value.trim();
            const pasteBtn = $('pasteTxBtn');
            const clearBtn = $('clearTxBtn');
            const submitBtn = $('submitTxBtn');

            if (val.length > 0) {
                pasteBtn.style.display = 'none';
                clearBtn.style.display = 'flex';
                submitBtn.style.display = 'flex';
            } else {
                pasteBtn.style.display = 'inline-flex';
                clearBtn.style.display = 'none';
                submitBtn.style.display = 'none';
            }
        }

        function clearTxSearch() {
            $('searchTxInput').value = '';
            handleTxInputState();
            $('searchTxInput').focus();
        }

        async function pasteAndSearchTx() {
            const pasteBtn = $('pasteTxBtn');
            const originalHtml = pasteBtn.innerHTML;

            try {
                if (!navigator.clipboard || !navigator.clipboard.readText) {
                    showToast('Clipboard access not supported. Please paste manually.', 'error');
                    return;
                }

                pasteBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> <span>Reading...</span>';
                
                const rawText = await navigator.clipboard.readText();
                const cleaned = cleanTxInput(rawText);

                if (!cleaned) {
                    showToast('Clipboard is empty or contains invalid ID!', 'error');
                    pasteBtn.innerHTML = originalHtml;
                    return;
                }

                $('searchTxInput').value = cleaned;
                handleTxInputState();
                showToast(`Pasted ID: ${cleaned}`, 'success');
                
                // Trigger Transaction search
                await searchTx();
            } catch (err) {
                showToast('Clipboard access denied. Please allow permission or paste manually.', 'error');
            } finally {
                pasteBtn.innerHTML = originalHtml;
            }
        }

        async function searchTx() {
            const rawId = $('searchTxInput').value;
            const id = cleanTxInput(rawId);

            if(!id) {
                showToast('Please enter a Transaction ID', 'error');
                return;
            }
            
            // Format box value if it was cleaned
            $('searchTxInput').value = id;

            const submitBtn = $('submitTxBtn');
            const origSubmit = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

            try {
                const res = await fetch(`?ajax=search_tx&tx_id=${encodeURIComponent(id)}`);
                const data = await res.json();
                if(data.success) {
                    searchedTxResult = data.transaction;
                    $('txResultClientName').textContent = data.transaction.client_name;

                    const item = data.transaction;
                    let classType='', p='';
                    if(item.type === 'savings') { classType = item.is_withdrawal ? 'tx-savings-out' : 'tx-savings-in'; p = item.is_withdrawal ? '-' : '+'; }
                    else if(item.type === 'loan') { classType = 'tx-loan'; p = ''; }
                    else if(item.type === 'repayment') { classType = 'tx-repay'; p = ''; }

                    const shortId = item.transaction_id.length > 10 ? item.transaction_id.substring(0,8)+'...' : item.transaction_id;

                    $('txResultBody').innerHTML = `
                    <div class="tx-item ${classType}" style="margin:0;">
                        <div class="tx-left">
                            <div class="tx-icon"><i class="fas ${item.icon}"></i></div>
                            <div class="tx-info">
                                <div class="tx-title">${item.title}</div>
                                <div class="tx-date">${item.date_formatted}</div>
                                <div><span class="tx-badge">${item.description}</span></div>
                            </div>
                        </div>
                        <div class="tx-right">
                            <div class="tx-amount">${p}${item.amount}</div>
                            <div class="tx-id copy-id" data-id="${item.transaction_id}" title="Click to copy" style="cursor:pointer;">${shortId}</div>
                            <div class="tx-actions">
                                <button onclick="openEditTx(0, true)" class="tx-action-btn tx-btn-edit" title="Edit"><i class="fas fa-edit"></i></button>
                                <button onclick="openDeleteTx(0, true)" class="tx-action-btn tx-btn-del" title="Delete"><i class="fas fa-trash"></i></button>
                            </div>
                        </div>
                    </div>`;

                    openModal('txResultModal');
                } else {
                    showToast(data.message, 'error');
                }
            } catch(e) {
                showToast('Error searching transaction', 'error');
            } finally {
                submitBtn.innerHTML = origSubmit;
            }
        }

        function copyAndSearchClient(el) {
            const name = el.textContent.trim();
            if (!name) return;
            const orig = el.textContent;
            try { navigator.clipboard.writeText(name); } catch(e) {
                const ta = document.createElement('textarea');
                ta.value = name; ta.style.cssText = 'position:fixed;opacity:0;';
                document.body.appendChild(ta); ta.select(); document.execCommand('copy'); document.body.removeChild(ta);
            }
            el.textContent = 'Copied!';
            setTimeout(() => { el.textContent = orig; closeModal('txResultModal'); $('searchInput').value = name; resetAndLoad(); }, 600);
        }

        function editClient(id, name, savings, loan, gName, gPhone) {
            $('editClientId').value = id; $('editClientName').value = name;
            $('editGuarantorName').value = gName === 'N/A' ? '' : gName;
            $('editGuarantorPhone').value = gPhone === 'N/A' ? '' : gPhone;
            $('editSavingsBalance').value = savings; $('editLoanBalance').value = loan;
            openModal('editModal');
        }

        $('editForm').onsubmit = async function(e) {
            e.preventDefault(); const btn = this.querySelector('button[type="submit"]');
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>'; btn.disabled = true;
            try {
                const fd = new FormData(this); fd.append('ajax', 'update_client_data');
                const res = await fetch('', { method: 'POST', body: fd });
                const data = await res.json();
                showToast(data.message, data.success ? 'success' : 'error');
                if(data.success) { closeModal('editModal'); forceRefresh(); }
            } catch(err) { showToast('Error', 'error'); } 
            finally { btn.innerHTML = 'Save Changes'; btn.disabled = false; }
        };

        // --- HISTORY TIMELINE LOGIC ---
        function timeAgo(dateStr) {
            const date = new Date(dateStr.replace(' ', 'T'));
            const now = new Date();
            const diffSec = Math.round((now - date) / 1000);
            if (diffSec < 60) return "Just now";
            const diffMin = Math.round(diffSec / 60);
            if (diffMin < 60) return `${diffMin}m ago`;
            const diffHr = Math.round(diffMin / 60);
            if (diffHr < 24) return `${diffHr}h ago`;
            const diffDays = Math.round(diffHr / 24);
            if (diffDays < 7) return `${diffDays}d ago`;
            return null;
        }

        function exportHistoryPDF() {
            if (!currentClientHistory || currentClientHistory.length === 0) return showToast('No data to export', 'error');
            const clientName = $('historyModalTitle').textContent.trim();
            const cd = currentClientDetails;
            const cs = currentClientSummary;
            const sorted = currentClientHistory.slice().sort((a,b) => b.timestamp - a.timestamp);

            let totalDeposits = 0, totalWithdrawals = 0, totalDisbursed = 0, totalRepaid = 0;
            sorted.forEach(r => {
                if (r.type === 'savings') { r.is_withdrawal ? (totalWithdrawals += r.raw_amount) : (totalDeposits += r.raw_amount); }
                else if (r.type === 'loan') totalDisbursed += r.raw_amount;
                else if (r.type === 'repayment') totalRepaid += r.raw_amount;
            });
            const fmt = n => '\u20a6' + Number(n).toLocaleString('en-US', {minimumFractionDigits:0});

            const profileRows = [
                ['Union / Group', cd.union], ['Branch', cd.branch], ['Area', cd.area], ['Zone', cd.zone],
                ['Loan Officer', cd.officer], ['Guarantor', cd.guarantor_name], ['Guarantor Phone', cd.guarantor_phone]
            ].map(([l,v]) => `<tr><td style="color:#555;width:40%;">${l}</td><td style="font-weight:600;">${v||'N/A'}</td></tr>`).join('');

            const summaryCards = `
                <table style="width:100%;border-collapse:collapse;margin-bottom:20px;">
                  <tr>
                    <td style="background:#eff6ff;border:1px solid #bfdbfe;padding:12px;border-radius:6px;text-align:center;width:48%;">
                        <div style="font-size:0.7em;font-weight:700;color:#3b82f6;text-transform:uppercase;margin-bottom:4px;">Savings Balance</div>
                        <div style="font-size:1.2em;font-weight:800;color:#1d4ed8;">${fmt(cs.savings||0)}</div>
                    </td>
                    <td style="width:4%;"></td>
                    <td style="background:#fef9c3;border:1px solid #fde68a;padding:12px;border-radius:6px;text-align:center;width:48%;">
                        <div style="font-size:0.7em;font-weight:700;color:#b45309;text-transform:uppercase;margin-bottom:4px;">Outstanding Loan</div>
                        <div style="font-size:1.2em;font-weight:800;color:#92400e;">${fmt(cs.loan||0)}</div>
                    </td>
                  </tr>
                </table>`;

            const txRows = sorted.map((row, i) => {
                const sign = row.is_withdrawal ? '-' : (row.type === 'savings' ? '+' : (row.type === 'repayment' ? '' : ''));
                const color = row.type === 'savings' ? (row.is_withdrawal ? '#dc2626' : '#16a34a') : (row.type === 'loan' ? '#ea580c' : '#7c3aed');
                const bg = i % 2 === 0 ? '#fff' : '#f9fafb';
                return `<tr style="background:${bg};">
                    <td style="padding:7px 10px;border:1px solid #e5e7eb;font-size:0.82em;">${row.raw_date}</td>
                    <td style="padding:7px 10px;border:1px solid #e5e7eb;"><span style="background:${color}18;color:${color};font-size:0.72em;font-weight:700;padding:2px 6px;border-radius:4px;border:1px solid ${color}44;text-transform:uppercase;">${row.type}</span></td>
                    <td style="padding:7px 10px;border:1px solid #e5e7eb;font-size:0.82em;">${row.description}</td>
                    <td style="padding:7px 10px;border:1px solid #e5e7eb;font-weight:700;color:${color};">${sign}${fmt(row.raw_amount)}</td>
                    <td style="padding:7px 10px;border:1px solid #e5e7eb;font-family:monospace;font-size:0.72em;color:#6b7280;">${row.transaction_id}</td>
                </tr>`;
            }).join('');

            const w = window.open('', '_blank');
            w.document.write(`<!DOCTYPE html><html><head><meta charset="UTF-8"><title>${clientName} - Statement</title>
            <style>
                * { box-sizing:border-box; margin:0; padding:0; }
                body { font-family: Arial, sans-serif; padding: 28px; color: #111; font-size: 14px; }
                .header { display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:20px; padding-bottom:16px; border-bottom:2px solid #3b82f6; }
                .header-left h1 { font-size:1.6em; font-weight:800; color:#1d4ed8; margin-bottom:2px; }
                .header-left p { font-size:0.78em; color:#6b7280; }
                .header-right { text-align:right; font-size:0.75em; color:#6b7280; }
                .section-title { font-size:0.7em; font-weight:700; text-transform:uppercase; color:#6b7280; letter-spacing:0.05em; margin-bottom:8px; }
                .profile-table { width:100%; border-collapse:collapse; margin-bottom:20px; font-size:0.85em; }
                .profile-table td { padding:6px 10px; border:1px solid #e5e7eb; }
                .profile-table tr:nth-child(even) td { background:#f9fafb; }
                .tx-table { width:100%; border-collapse:collapse; font-size:0.85em; }
                .tx-table th { background:#1d4ed8; color:#fff; padding:8px 10px; text-align:left; font-size:0.8em; }
                .footer { margin-top:24px; font-size:0.72em; color:#9ca3af; text-align:center; padding-top:12px; border-top:1px solid #e5e7eb; }
                @media print { .no-print { display:none !important; } }
            </style>
            </head><body>
            <div class="header">
                <div class="header-left">
                    <h1>${clientName}</h1>
                    <p>Client Transaction Statement &mdash; CUPAD</p>
                </div>
                <div class="header-right">
                    <div>Generated: ${new Date().toLocaleString('en-NG')}</div>
                    <div style="margin-top:4px;">Total Records: <strong>${sorted.length}</strong></div>
                </div>
            </div>

            <div class="section-title">Client Profile</div>
            <table class="profile-table">
                <tbody>${profileRows}</tbody>
            </table>

            <div class="section-title">Financial Summary</div>
            ${summaryCards}

            <div class="section-title">Transaction History (${sorted.length} records)</div>
            <table class="tx-table">
                <thead><tr><th>Date &amp; Time</th><th>Type</th><th>Description</th><th>Amount</th><th>Transaction ID</th></tr></thead>
                <tbody>${txRows}</tbody>
            </table>

            <div class="footer">CUPAD &bull; Confidential Client Statement &bull; Generated ${new Date().toLocaleDateString('en-NG')}</div>

            <br><button class="no-print" onclick="window.print()" style="margin-top:12px;padding:10px 24px;background:#3b82f6;color:#fff;border:none;border-radius:6px;font-size:0.9em;font-weight:700;cursor:pointer;">&#128438; Print / Save as PDF</button>
            </body></html>`);
            w.document.close();
        }

        async function viewHistory(id) {
            currentClientId = id;
            openModal('historyModal');
            $('historyModalBody').innerHTML = '<div style="text-align:center; padding: 2rem 0;"><i class="fas fa-spinner fa-spin" style="font-size:2rem; color:var(--primary);"></i></div>';
            $('historyFilters').style.display = 'none';
            $('historySearchContainer').style.display = 'none';
            $('exportHistoryBtn').style.display = 'none';
            $('historySearchInput').value = '';
            currentHistoryFilter = 'all';
            document.querySelectorAll('.history-tab').forEach((b,i) => b.className = 'tab-btn history-tab' + (i===0?' active':''));

            try {
                const res = await fetch(`?ajax=get_client_history&id=${id}`);
                const data = await res.json();
                if(data.success) {
                    $('historyModalTitle').textContent = data.client_name;
                    $('historyModalSubtitle').innerHTML = `<div class="flex gap-2" style="margin-top:0.4rem;">
                        <span style="font-size:0.65rem; font-weight:800; background:rgba(34,197,94,0.1); color:var(--success); padding:0.15rem 0.4rem; border-radius:0.25rem; border:1px solid rgba(34,197,94,0.2);">Save: ${formatMoney(data.summary.savings)}</span>
                        <span style="font-size:0.65rem; font-weight:800; background:rgba(245,158,11,0.1); color:var(--warning); padding:0.15rem 0.4rem; border-radius:0.25rem; border:1px solid rgba(245,158,11,0.2);">Loan: ${formatMoney(data.summary.loan)}</span>
                    </div>`;
                    currentClientDetails = data.client_details || {};
                    currentClientSummary = data.summary || {};
                    currentClientHistory = data.history;
                    if(currentClientHistory.length > 0) {
                        $('historyFilters').style.display = 'flex';
                        $('historySearchContainer').style.display = 'block';
                        $('exportHistoryBtn').style.display = 'inline-flex';
                    }
                    renderHistory('all');
                } else $('historyModalBody').innerHTML = `<div style="text-align:center; padding:3rem 0; color:var(--danger); font-weight:600;">${data.message}</div>`;
            } catch(e) { $('historyModalBody').innerHTML = '<div style="text-align:center; padding:3rem 0; color:var(--danger); font-weight:600;">Failed to load.</div>'; }
        }

        function filterHistory(type, btnElement) {
            document.querySelectorAll('.history-tab').forEach(btn => btn.className = 'tab-btn history-tab');
            btnElement.className = 'tab-btn history-tab active';
            currentHistoryFilter = type;
            renderHistory(currentHistoryFilter);
        }

        function renderHistory(filterType) {
            const searchTerm = $('historySearchInput').value.toLowerCase().trim();
            const filtered = (filterType === 'all' ? currentClientHistory : currentClientHistory.filter(i => i.type === filterType))
                .filter(i => {
                    if(!searchTerm) return true;
                    return i.description.toLowerCase().includes(searchTerm) || 
                           i.transaction_id.toLowerCase().includes(searchTerm) || 
                           String(i.raw_amount).includes(searchTerm) ||
                           i.date_formatted.toLowerCase().includes(searchTerm);
                })
                .slice().sort((a, b) => b.sort_key > a.sort_key ? 1 : -1);

            if(filtered.length === 0) {
                $('historyModalBody').innerHTML = `<div style="text-align:center; padding:3rem 0; color:var(--text-muted); font-size:0.875rem; font-weight:600;">No transactions found.</div>`; return;
            }
            
            const monthlySummaries = {};
            filtered.forEach(item => {
                const d = new Date(item.timestamp * 1000);
                const monthStr = d.toLocaleString('en-US', { month: 'long', year: 'numeric' });
                if (!monthlySummaries[monthStr]) {
                    monthlySummaries[monthStr] = { netSavings: 0, totalLoan: 0, totalRepayment: 0 };
                }
                if (item.type === 'savings') {
                    const amount = item.is_withdrawal ? -item.raw_amount : item.raw_amount;
                    monthlySummaries[monthStr].netSavings += amount;
                } else if (item.type === 'loan') {
                    monthlySummaries[monthStr].totalLoan += item.raw_amount;
                } else if (item.type === 'repayment') {
                    monthlySummaries[monthStr].totalRepayment += item.raw_amount;
                }
            });

            let html = '', currentMonth = '';
            const cycleCounters = {}; 
            const sorted_asc = filtered.slice().sort((a, b) => a.sort_key > b.sort_key ? 1 : -1);
            let curInstallments = 0, repayCount = 0, withCount = 0;
            sorted_asc.forEach(item => {
                if (item.type === 'loan') {
                    curInstallments = item.num_installments || 0;
                    repayCount = 0;
                    withCount = 0;
                } else if (item.type === 'repayment') {
                    repayCount++;
                    cycleCounters[item.transaction_id] = { n: repayCount, total: curInstallments };
                } else if (item.type === 'savings' && item.is_withdrawal) {
                    withCount++;
                    cycleCounters[item.transaction_id] = { n: withCount, total: 0 };
                }
            });

            filtered.forEach((item) => {
                const d = new Date(item.timestamp * 1000);
                const monthStr = d.toLocaleString('en-US', { month: 'long', year: 'numeric' });
                if (monthStr !== currentMonth) {
                    const summary = monthlySummaries[monthStr];
                    let summaryHtml = '';

                    if (filterType === 'all') {
                        let parts = [];
                        if (summary.netSavings !== 0) {
                            const colorClass = summary.netSavings > 0 ? 'summary-positive' : 'summary-negative';
                            parts.push(`<span class="${colorClass}">Savings: ${formatMoney(summary.netSavings)}</span>`);
                        }
                        if (summary.totalRepayment > 0) {
                            parts.push(`<span class="summary-repay">Repaid: ${formatMoney(summary.totalRepayment)}</span>`);
                        }
                        summaryHtml = parts.join(' <span style="color:var(--text-light);font-weight:400;">&bull;</span> ');
                    } else if (filterType === 'savings') {
                        if (summary.netSavings !== 0) {
                            const colorClass = summary.netSavings > 0 ? 'summary-positive' : 'summary-negative';
                            summaryHtml = `<span class="${colorClass}">Net Monthly: ${formatMoney(summary.netSavings)}</span>`;
                        } else {
                            summaryHtml = `<span class="summary-neutral">No Net Change</span>`;
                        }
                    } else if (filterType === 'loan') {
                        if (summary.totalLoan > 0) {
                            summaryHtml = `<span class="summary-loan">Total Disbursed: ${formatMoney(summary.totalLoan)}</span>`;
                        }
                    } else if (filterType === 'repayment') {
                        if (summary.totalRepayment > 0) {
                            summaryHtml = `<span class="summary-repay">Total Repaid: ${formatMoney(summary.totalRepayment)}</span>`;
                        }
                    }

                    html += `<div class="timeline-month">
                                <span>${monthStr}</span>
                                <span class="month-summary">${summaryHtml}</span>
                             </div>`;
                    currentMonth = monthStr;
                }

                let classType='', p='', counterHtml='';
                if(item.type === 'savings') { 
                    classType = item.is_withdrawal ? 'tx-savings-out' : 'tx-savings-in'; 
                    p = item.is_withdrawal ? '-' : '+'; 
                    if(item.is_withdrawal && cycleCounters[item.transaction_id]) { 
                        const c=cycleCounters[item.transaction_id]; 
                        counterHtml=`<span style="font-size:0.55rem;font-weight:800;background:rgba(220,38,38,0.1);color:#dc2626;border:1px solid rgba(220,38,38,0.25);padding:0.15rem 0.35rem;border-radius:0.25rem;margin-left:0.3rem;">#${c.n}</span>`; 
                    } 
                } else if(item.type === 'loan') { 
                    classType = 'tx-loan'; p = ''; 
                } else if(item.type === 'repayment') { 
                    classType = 'tx-repay'; p = ''; 
                    if(cycleCounters[item.transaction_id]) { 
                        const c=cycleCounters[item.transaction_id]; 
                        counterHtml=`<span style="font-size:0.55rem;font-weight:800;background:rgba(147,51,234,0.1);color:#9333ea;border:1px solid rgba(147,51,234,0.25);padding:0.15rem 0.35rem;border-radius:0.25rem;margin-left:0.3rem;">#${c.n}</span>`; 
                    } 
                }
                
                const shortId = item.transaction_id.length > 10 ? item.transaction_id.substring(0,8)+'...' : item.transaction_id;
                const realIndex = currentClientHistory.findIndex(x => x.transaction_id === item.transaction_id);
                const ago = timeAgo(item.raw_date);
                const displayDate = ago ? `${item.date_formatted} <span style="opacity:0.6; font-size:0.85em;">&bull; ${ago}</span>` : item.date_formatted;

                html += `<div class="tx-item ${classType}">
                        <div class="tx-left">
                            <div class="tx-icon"><i class="fas ${item.icon}"></i></div>
                            <div class="tx-info">
                                <div class="tx-title">${item.title}</div>
                                <div class="tx-date">${displayDate}</div>
                                <div><span class="tx-badge">${item.description}</span>${counterHtml}</div>
                            </div>
                        </div>
                        <div class="tx-right">
                            <div class="tx-amount">${p}${item.amount}</div>
                            <div class="tx-id copy-id" data-id="${item.transaction_id}" title="Click to copy" style="cursor:pointer;">${shortId}</div>
                            <div class="tx-actions">
                                <button onclick="openEditTx(${realIndex}, false)" class="tx-action-btn tx-btn-edit" title="Edit"><i class="fas fa-edit"></i></button>
                                <button onclick="openDeleteTx(${realIndex}, false)" class="tx-action-btn tx-btn-del" title="Delete"><i class="fas fa-trash"></i></button>
                            </div>
                        </div>
                    </div>`;
            });
            $('historyModalBody').innerHTML = html;
        }

        document.addEventListener('click', function(e) {
            const el = e.target.closest('.copy-id');
            if (!el) return;
            const id = el.dataset.id;
            const orig = el.textContent;
            try {
                navigator.clipboard.writeText(id).then(() => {
                    el.textContent = 'Copied!'; el.style.color = 'var(--success)';
                    setTimeout(() => { el.textContent = orig; el.style.color = ''; }, 1500);
                });
            } catch(err) {
                const ta = document.createElement('textarea');
                ta.value = id; ta.style.position = 'fixed'; ta.style.opacity = '0';
                document.body.appendChild(ta); ta.select();
                document.execCommand('copy');
                document.body.removeChild(ta);
                el.textContent = 'Copied!'; el.style.color = 'var(--success)';
                setTimeout(() => { el.textContent = orig; el.style.color = ''; }, 1500);
            }
        });

        // --- EDIT / DELETE TRANSACTION LOGIC ---
        function mapTxType(type) {
            if (type === 'loan') return 'disbursement';
            if (type === 'repayment') return 'repayment';
            return 'savings';
        }

        function openEditTx(index, isSearchResult = false) {
            const tx = isSearchResult ? searchedTxResult : currentClientHistory[index];
            manRate = false; manInst = false;
            
            $('editTxId').value = tx.transaction_id;
            $('editTxType').value = mapTxType(tx.type);
            
            let dStr = tx.raw_date;
            if (dStr) {
                if (dStr.indexOf(' ') === -1) {
                    dStr += ' 00:00:00'; 
                }
                $('editTxDate').value = dStr.replace(' ', 'T').substring(0, 16);
            }
            
            $('editTxAmount').value = tx.raw_amount;

            if (tx.type === 'loan') {
                $('txLoanFields').classList.remove('hidden');
                $('lblTxAmount').innerText = "Principal Amount Disbursed";
                $('editTxRate').value = tx.interest_rate ?? DEF_RATE;
                $('editTxInstallments').value = tx.num_installments ?? DEF_INST;
                $('editTxPayable').value = tx.total_payable ?? 0;
                $('editTxBalance').value = tx.remaining_balance ?? 0;
                $('editTxInstAmt').value = tx.num_installments > 0 ? (tx.total_payable / tx.num_installments).toFixed(2) : 0;
            } else {
                $('txLoanFields').classList.add('hidden');
                $('lblTxAmount').innerText = "Amount";
            }
            openModal('editTxModal');
        }

        function calcLoan() {
            if ($('editTxType').value !== 'disbursement') return;
            const prin = parseFloat($('editTxAmount').value) || 0;
            const rate = (parseFloat($('editTxRate').value) || 0) / 100;
            const inst = parseInt($('editTxInstallments').value) || DEF_INST;
            const pay = Math.round(prin * (1 + rate));
            const per = inst > 0 ? (pay / inst).toFixed(2) : 0;
            
            $('editTxPayable').value = pay;
            if(!manInst) $('editTxInstAmt').value = per;
            $('editTxBalance').value = pay; 
        }

        $('editTxAmount').addEventListener('input', calcLoan);
        $('editTxRate').addEventListener('input', () => { manRate = true; calcLoan(); });
        $('editTxInstallments').addEventListener('input', calcLoan);
        $('editTxInstAmt').addEventListener('input', () => { manInst = true; });

        $('editTxForm').onsubmit = async function(e) {
            e.preventDefault();
            const btn = $('saveTxBtn'); btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>'; btn.disabled = true;
            
            const type = $('editTxType').value;
            const data = { date: $('editTxDate').value, amount: parseFloat($('editTxAmount').value) };
            
            if(type === 'disbursement') {
                data.principal_amount = data.amount; delete data.amount;
                data.interest_rate_used = (parseFloat($('editTxRate').value) || 0) / 100;
                data.num_installments = parseInt($('editTxInstallments').value) || DEF_INST;
                data.total_payable = parseFloat($('editTxPayable').value);
                data.remaining_balance = parseFloat($('editTxBalance').value);
                data.installment_amount = parseFloat($('editTxInstAmt').value) || 0;
            } else if(type === 'repayment') {
                data.amount_collected = data.amount; delete data.amount;
            }

            const fd = new FormData();
            fd.append('ajax', 'edit_transaction');
            fd.append('transaction_id', $('editTxId').value);
            fd.append('transaction_type', type);
            fd.append('data', JSON.stringify(data));

            try {
                const res = await fetch('', { method: 'POST', body: fd });
                const json = await res.json();
                showToast(json.message, json.success ? 'success' : 'error');
                if (json.success) {
                    closeModal('editTxModal');
                    if ($('txResultModal').classList.contains('active')) {
                        searchTx();
                    }
                    if ($('historyModal').classList.contains('active') && currentClientId) {
                        viewHistory(currentClientId);
                    }
                    forceRefresh();
                }
            } catch(e) { showToast('Network Error', 'error'); }
            finally { btn.innerHTML = 'Save Changes'; btn.disabled = false; }
        };

        function openDeleteTx(index, isSearchResult = false) {
            const tx = isSearchResult ? searchedTxResult : currentClientHistory[index];
            txDeleteTarget = { id: tx.transaction_id, type: mapTxType(tx.type) };
            $('delTxId').textContent = tx.transaction_id;
            $('delTxType').textContent = tx.title;
            $('delTxAmt').textContent = tx.amount;
            openModal('deleteTxModal');
        }

        async function executeDeleteTx() {
            const btn = $('confirmDelTxBtn'); btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>'; btn.disabled = true;
            const fd = new FormData();
            fd.append('ajax', 'delete_transaction');
            fd.append('transaction_id', txDeleteTarget.id);
            fd.append('transaction_type', txDeleteTarget.type);
            
            try {
                const res = await fetch('', { method: 'POST', body: fd });
                const json = await res.json();
                showToast(json.message, json.success ? 'success' : 'error');
                if (json.success) {
                    closeModal('deleteTxModal');
                    closeModal('txResultModal');
                    if ($('historyModal').classList.contains('active') && currentClientId) {
                        viewHistory(currentClientId);
                    }
                    forceRefresh();
                }
            } catch(e) { showToast('Network Error', 'error'); }
            finally { btn.innerHTML = 'Delete'; btn.disabled = false; }
        }

        document.addEventListener('DOMContentLoaded', () => {
            const hideBalances = localStorage.getItem('hideBalances');
            const body = document.body;
            const btnIcon = document.querySelector('#toggleBalanceBtn i');
            
            if (hideBalances === 'false') {
                body.classList.remove('hide-balances');
                if (btnIcon) btnIcon.className = 'fas fa-eye';
            } else {
                body.classList.add('hide-balances');
                if (btnIcon) btnIcon.className = 'fas fa-eye-slash';
            }
            
            loadClients(false);
        });
    </script>
</body>
</html>