<?php
date_default_timezone_set('Africa/Lagos');
session_start();
require_once '../includes/config.php';
$pdo = getDbConnection();

// Ensure User is Logged In and is a Branch Manager (bm)
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'bm') {
    header('Location: ../index.php');
    exit();
}
$base_path = '../';
$username = $_SESSION['username'] ?? '';
$user_id = $_SESSION['user_id'] ?? '';

// Fetch the BM's branch_id
$stmt_b = $pdo->prepare("SELECT branch_id FROM users WHERE id = :uid");
$stmt_b->execute(['uid' => $user_id]);
$branch_id = $stmt_b->fetchColumn();

// Generate CSRF Token for Auto-save Security
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ==========================================================================
// 1. HELPER FUNCTIONS
// ==========================================================================

function generate_uuid() {
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

// ==========================================================================
// 2. SETTINGS & CONTEXT
// ==========================================================================

$profile_pic = 'default_avatar.png';
$co_is_weekly = false;
$stmt = $pdo->prepare("SELECT profile_pic, is_weekly FROM users WHERE username = ?");
$stmt->execute([$username]);
if ($u = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $profile_pic = $u['profile_pic'] ?? 'default_avatar.png';
    $co_is_weekly = (bool)$u['is_weekly'];
}

$col_settings =[
    'max_installments_per_payment' => 3, 'min_installments_per_payment' => 1, 
    'grace_period_days' => 2, 'allow_partial_payments' => 0, 'allow_overpayment' => 0
];
$sav_settings =[
    'min_savings_amount' => 100, 'max_savings_amount' => 500000, 'allow_weekend_collection' => 0
];
$w_settings =[
    'max_cash_withdrawal' => 50000, 'require_image_for_cash' => 1,
    'allow_weekend_withdrawals' => 0, 'max_withdrawals_per_day' => 1,
    'blocked_withdrawal_types' => '[]',
    'buffer_cash' => 10, 'buffer_withdrawal' => 10, 'buffer_return' => 10
];

$q = $pdo->query("SELECT * FROM loan_collection_settings LIMIT 1");
if ($q && $r = $q->fetch(PDO::FETCH_ASSOC)) $col_settings = array_merge($col_settings, $r);

$q = $pdo->query("SELECT * FROM savings_settings LIMIT 1");
if ($q && $r = $q->fetch(PDO::FETCH_ASSOC)) $sav_settings = array_merge($sav_settings, $r);

$q = $pdo->query("SELECT * FROM withdrawal_settings LIMIT 1");
if ($q && $r = $q->fetch(PDO::FETCH_ASSOC)) $w_settings = array_merge($w_settings, $r);

$date_readonly = false;
$q = $pdo->query("SELECT date_readonly FROM date_control_settings LIMIT 1");
if ($q && $r = $q->fetch(PDO::FETCH_ASSOC)) $date_readonly = (bool)$r['date_readonly'];

$sav_req_pct = 0.2;
$q = $pdo->query("SELECT savings_requirement_percentage FROM disbursement_settings LIMIT 1");
if ($q && $r = $q->fetch(PDO::FETCH_ASSOC)) $sav_req_pct = floatval($r['savings_requirement_percentage']);

// ==========================================================================
// 3. AJAX HANDLERS
// ==========================================================================

if (isset($_GET['ajax_action'])) {
    ob_clean();
    header('Content-Type: application/json');

    if ($_GET['ajax_action'] === 'get_union_data') {
        $union = $_GET['union'] ?? '';
        $date_in = $_GET['date'] ?? date('Y-m-d');
        $co_filter = $_GET['co'] ?? 'all';
        $query_date = date('Y-m-d', strtotime($date_in));
        
        $sql = "SELECT id, name FROM clients WHERE branch_id = ? AND status = 'active' AND `union` = ?";
        $params =[$branch_id, $union];

        if ($co_filter !== 'all') {
            $sql .= " AND officer_username = ?";
            $params[] = $co_filter;
        }

        $sql .= " ORDER BY name ASC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $client_data =[];

        if (count($clients) > 0) {
            $client_ids = array_column($clients, 'id');
            $placeholders = str_repeat('?,', count($client_ids) - 1) . '?';

            // 1. Bulk Fetch Today's Existing Loan Transactions
            $stmt_l_tx = $pdo->prepare("SELECT client_id, transaction_id, amount_collected, disbursement_id FROM loan_collections WHERE DATE(date) = ? AND client_id IN ($placeholders)");
            $stmt_l_tx->execute(array_merge([$query_date], $client_ids));
            $existing_loan_txs =[];
            while ($row = $stmt_l_tx->fetch(PDO::FETCH_ASSOC)) {
                $existing_loan_txs[$row['client_id']] = $row;
            }

            // 2. Bulk Fetch Active Loans
            $stmt_act_l = $pdo->prepare("SELECT * FROM disbursements WHERE client_id IN ($placeholders) AND status != 'completed' AND remaining_balance > 0.01 ORDER BY date DESC");
            $stmt_act_l->execute($client_ids);
            $active_loans =[];
            while ($row = $stmt_act_l->fetch(PDO::FETCH_ASSOC)) {
                if (!isset($active_loans[$row['client_id']])) {
                    $active_loans[$row['client_id']] = $row; 
                }
            }

            $completed_disb_ids =[];
            foreach ($existing_loan_txs as $cid => $tx) {
                if (!empty($tx['disbursement_id']) && !isset($active_loans[$cid])) {
                    $completed_disb_ids[] = $tx['disbursement_id'];
                }
            }
            if (!empty($completed_disb_ids)) {
                $cp_placeholders = str_repeat('?,', count($completed_disb_ids) - 1) . '?';
                $stmt_cp = $pdo->prepare("SELECT * FROM disbursements WHERE id IN ($cp_placeholders)");
                $stmt_cp->execute($completed_disb_ids);
                while ($row = $stmt_cp->fetch(PDO::FETCH_ASSOC)) {
                    $active_loans[$row['client_id']] = $row;
                }
            }

            // 3. Bulk Fetch Saving Balances
            $stmt_sav = $pdo->prepare("SELECT client_id, balance FROM saving_balances WHERE client_id IN ($placeholders)");
            $stmt_sav->execute($client_ids);
            $sav_balances =[];
            while ($row = $stmt_sav->fetch(PDO::FETCH_ASSOC)) {
                $sav_balances[$row['client_id']] = floatval($row['balance']);
            }

            // 4. Bulk Fetch Today's Existing Saving Transactions
            $stmt_s_tx = $pdo->prepare("SELECT client_id, amount, type FROM saving_collections WHERE DATE(date) = ? AND client_id IN ($placeholders)");
            $stmt_s_tx->execute(array_merge([$query_date], $client_ids));
            $existing_sav_txs =[];
            while ($row = $stmt_s_tx->fetch(PDO::FETCH_ASSOC)) {
                $cid = $row['client_id'];
                if (!isset($existing_sav_txs[$cid])) $existing_sav_txs[$cid] =['sav_amt' => 0, 'wth_type' => '', 'wth_amt' => 0];

                if ($row['type'] === 'deposit') {
                    $existing_sav_txs[$cid]['sav_amt'] += floatval($row['amount']);
                } else {
                    $existing_sav_txs[$cid]['wth_type'] = $row['type'];
                    $existing_sav_txs[$cid]['wth_amt'] = abs(floatval($row['amount']));
                }
            }

            // Assemble Data
            foreach ($clients as $c) {
                $cid = $c['id'];
                $existing =[ 'loan_amt' => 0, 'sav_amt' => 0, 'wth_type' => '', 'wth_amt' => 0 ];

                if (isset($existing_loan_txs[$cid])) {
                    $existing['loan_amt'] = floatval($existing_loan_txs[$cid]['amount_collected']);
                }
                if (isset($existing_sav_txs[$cid])) {
                    $existing['sav_amt'] = $existing_sav_txs[$cid]['sav_amt'];
                    $existing['wth_type'] = $existing_sav_txs[$cid]['wth_type'];
                    $existing['wth_amt'] = $existing_sav_txs[$cid]['wth_amt'];
                }

                $loan = $active_loans[$cid] ?? null;
                if ($loan) {
                    $loan_total = floatval($loan['total_payable']);
                    $loan_rem = floatval($loan['remaining_balance']);
                    $paid = $loan_total - $loan_rem;
                    $num_inst = intval($loan['num_installments'] ?: ($co_is_weekly ? 24 : 23));
                    $inst_amt = ($num_inst > 0) ? ($loan_total / $num_inst) : 0;
                    $loan['installments_paid'] = ($inst_amt > 0) ? floor($paid / $inst_amt) : 0;
                    $loan['inst_amt'] = $inst_amt;
                }

                $sav_bal = $sav_balances[$cid] ?? 0;

                $client_data[] =[
                    'id' => $cid,
                    'name' => $c['name'],
                    'loan' => $loan,
                    'savings_balance' => $sav_bal,
                    'existing' => $existing
                ];
            }
        }

        echo json_encode(['success' => true, 'data' => $client_data]);
        exit();
    }
}

// ==========================================================================
// 4. POST HANDLER
// ==========================================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'save_client') {
    ob_clean();
    header('Content-Type: application/json');

    // Security: Validate CSRF Token
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        echo json_encode(['success' => false, 'message' => 'Security token mismatch. Please refresh the page.']); 
        exit;
    }

    $client_id = $_POST['client_id'] ?? '';
    $date_in = $_POST['date'] ?? date('Y-m-d H:i:s');
    $date_fmt = date('Y-m-d H:i:s', strtotime($date_in));
    $payment_date = date('Y-m-d', strtotime($date_fmt));

    if (empty($client_id)) {
        echo json_encode(['success' => false, 'message' => 'Client ID missing.']); exit;
    }

    // Branch Security Check
    $stmt_check = $pdo->prepare("SELECT branch_id FROM clients WHERE id = ?");
    $stmt_check->execute([$client_id]);
    if ($stmt_check->fetchColumn() != $branch_id) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized client branch mismatch.']); exit;
    }

    // SERVER-SIDE DATE VALIDATION: Check if date was modified when readonly
    if ($date_readonly) {
        $current_date = date('Y-m-d');
        if ($payment_date !== $current_date) {
            echo json_encode(['success' => false, 'message' => 'Date modification is not allowed. Please use current date.']); 
            exit;
        }
    }

    $loan_inst_val = $_POST['installment'] ?? '';
    $saving_amt = floatval($_POST['savings_amount'] ?? 0);
    $withdrawal_type = $_POST['withdrawal_type'] ?? '';
    $withdrawal_amt = floatval($_POST['withdrawal_amount'] ?? 0);

    // MUTUALLY EXCLUSIVE VALIDATION (Repayment vs Deduct/Return)
    if (intval($loan_inst_val) > 0 && in_array($withdrawal_type, ['withdrawal', 'return'])) {
        echo json_encode(['success' => false, 'message' => 'Repayment and non-cash withdrawal (Deduct/Return) cannot be processed at the same time.']); 
        exit;
    }

    if ($saving_amt < 0 || $withdrawal_amt < 0) {
        echo json_encode(['success' => false, 'message' => 'Negative amounts are not allowed.']); exit;
    }

    // SERVER-SIDE WEEKEND BLOCKING VALIDATION
    $day_of_week = date('N', strtotime($payment_date)); 
    if ($day_of_week >= 6) { 
        if (($saving_amt > 0 || intval($loan_inst_val) > 0) && empty($sav_settings['allow_weekend_collection'])) {
            echo json_encode(['success' => false, 'message' => 'Weekend collections are disabled.']); exit;
        }
        if ($withdrawal_amt > 0 && empty($w_settings['allow_weekend_withdrawals'])) {
            echo json_encode(['success' => false, 'message' => 'Weekend withdrawals are disabled.']); exit;
        }
    }

    // Settings bounds
    if ($saving_amt > 0 && $saving_amt > floatval($sav_settings['max_savings_amount'])) {
        echo json_encode(['success' => false, 'message' => "Savings amount exceeds maximum allowed limit."]); exit;
    }
    if ($withdrawal_type === 'cash' && $withdrawal_amt > floatval($w_settings['max_cash_withdrawal'])) {
        echo json_encode(['success' => false, 'message' => "Cash withdrawal exceeds maximum allowed limit."]); exit;
    }

    // Secure File Upload Handling
    $uploaded_pic_path = null;
    if ($withdrawal_type === 'cash') {
        if (!isset($_FILES['picture']) || $_FILES['picture']['error'] !== UPLOAD_ERR_OK) {
            if (intval($w_settings['require_image_for_cash']) === 1) {
                echo json_encode(['success' => false, 'message' => 'Proof image is required for cash withdrawals.']); exit;
            }
        } else {
            $upload_dir = '../uploads/withdrawals/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
            
            $tmp_path = $_FILES['picture']['tmp_name'];
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime_type = finfo_file($finfo, $tmp_path);
            finfo_close($finfo);

            $allowed_mimes = ['image/jpeg', 'image/png', 'image/jpg'];
            $ext = strtolower(pathinfo($_FILES['picture']['name'], PATHINFO_EXTENSION));
            
            if (in_array($mime_type, $allowed_mimes) && in_array($ext, ['jpg', 'jpeg', 'png'])) {
                $filename = 'wth_' . preg_replace('/[^a-zA-Z0-9_-]/', '', $client_id) . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
                if (move_uploaded_file($tmp_path, $upload_dir . $filename)) {
                    $uploaded_pic_path = 'uploads/withdrawals/' . $filename;
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'Invalid image file format. Only JPG/PNG are allowed.']); exit;
            }
        }
    }

    $pdo->beginTransaction();
    try {
        $client_updates =[];

        // --- CLOSURE HELPERS FOR DRY DB UPDATES ---
        $updateDisbursementBal = function($disb_id, $new_rem, $pay_date) use ($pdo) {
            $status = ($new_rem <= 0.01) ? 'completed' : 'active';
            $payoff_sql = ($status === 'completed') ? ", payoff_date = '$pay_date'" : ", payoff_date = NULL";
            $pdo->prepare("UPDATE disbursements SET remaining_balance = ?, status = ? $payoff_sql WHERE id = ?")->execute([$new_rem, $status, $disb_id]);
        };

        $revertDisbursementBal = function($disb_id, $amount) use ($pdo) {
            $pdo->prepare("UPDATE disbursements SET remaining_balance = remaining_balance + ?, status = 'active', payoff_date = NULL WHERE id = ?")->execute([$amount, $disb_id]);
        };

        $updateSavingsBal = function($cid, $sid, $new_bal) use ($pdo) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM saving_balances WHERE client_id = ?");
            $stmt->execute([$cid]);
            if ($stmt->fetchColumn() > 0) {
                $pdo->prepare("UPDATE saving_balances SET balance = ?, last_updated = NOW() WHERE client_id = ?")->execute([$new_bal, $cid]);
            } else {
                $pdo->prepare("INSERT INTO saving_balances (client_id, balance, last_updated) VALUES (?, ?, NOW())")->execute([$cid, $new_bal]);
            }
            $pdo->prepare("UPDATE savings SET balance = ?, updated_at = NOW() WHERE id = ? AND client_id = ?")->execute([$new_bal, $sid, $cid]);
        };
        // ------------------------------------------

        // PRE-FETCH GLOBAL SAVINGS ID (Needed for both Savings and Withdrawals)
        $stmt = $pdo->prepare("SELECT id FROM savings WHERE client_id = ? AND status = 'active' LIMIT 1");
        $stmt->execute([$client_id]);
        $savings_id = $stmt->fetchColumn();
        
        if (!$savings_id && ($saving_amt > 0 || $withdrawal_amt > 0)) {
            $savings_id = 'SVG-' . strtoupper(generate_uuid());
            $pdo->prepare("INSERT INTO savings (id, client_id, officer, balance, status, created_at) VALUES (?, ?, ?, 0, 'active', NOW())")->execute([$savings_id, $client_id, $username]);
        }

        // =======================================================
        // 1. LOAN REPAYMENT
        // =======================================================
        $chk = $pdo->prepare("SELECT transaction_id, amount_collected, disbursement_id FROM loan_collections WHERE client_id = ? AND DATE(date) = ? FOR UPDATE");
        $chk->execute([$client_id, $payment_date]);
        $existing_loan_tx = $chk->fetch(PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare("SELECT * FROM disbursements WHERE client_id = ? AND status != 'completed' AND remaining_balance > 0.01 ORDER BY date DESC LIMIT 1");
        $stmt->execute([$client_id]);
        $active_loan = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$active_loan && $existing_loan_tx && !empty($existing_loan_tx['disbursement_id'])) {
            $stmt = $pdo->prepare("SELECT * FROM disbursements WHERE id = ?");
            $stmt->execute([$existing_loan_tx['disbursement_id']]);
            $active_loan = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        $loan = $active_loan;
        $pay_amt = 0;
        
        if ($loan && intval($loan_inst_val) > 0) {
            $loan_inst = intval($loan_inst_val);
            $num_inst = intval($loan['num_installments'] ?: ($co_is_weekly ? 24 : 23));
            $inst_val = ($num_inst > 0) ? ($loan['total_payable'] / $num_inst) : 0;
            $pay_amt = $inst_val * $loan_inst;
        }

        if ($pay_amt > 0 && $loan) {
            $disb_id = $loan['id'];
            
            // Grace Period Check
            $grace_days = intval($col_settings['grace_period_days']);
            $disb_date = new DateTime($loan['date']);
            $pay_date_obj = new DateTime($payment_date);
            $min_date = clone $disb_date;
            $min_date->modify("+$grace_days days");

            if ($pay_date_obj < $min_date) {
                throw new Exception("Collections allowed after " . $min_date->format('d M Y') . " ($grace_days day grace period).");
            }
            
            if ($existing_loan_tx) {
                $existing_disb_id = $existing_loan_tx['disbursement_id'];
                if ($existing_disb_id && $existing_disb_id == $disb_id) {
                    $old_amt = floatval($existing_loan_tx['amount_collected']);
                    $diff = $pay_amt - $old_amt;
                    $new_rem = max(0, floatval($loan['remaining_balance']) - $diff);
                    
                    $updateDisbursementBal($disb_id, $new_rem, $payment_date);
                    $pdo->prepare("UPDATE loan_collections SET amount_collected = ?, remaining_balance = ? WHERE transaction_id = ? AND client_id = ?")->execute([$pay_amt, $new_rem, $existing_loan_tx['transaction_id'], $client_id]);
                    $client_updates[] = "Loan Updated";
                } else {
                    if ($existing_disb_id) $revertDisbursementBal($existing_disb_id, floatval($existing_loan_tx['amount_collected']));
                    $pdo->prepare("DELETE FROM loan_collections WHERE transaction_id = ? AND client_id = ?")->execute([$existing_loan_tx['transaction_id'], $client_id]);
                    
                    $new_rem = max(0, floatval($loan['remaining_balance']) - $pay_amt);
                    $updateDisbursementBal($disb_id, $new_rem, $payment_date);
                    
                    $tx_id = 'LCL-' . strtoupper(generate_uuid());
                    $pdo->prepare("INSERT INTO loan_collections (transaction_id, client_id, disbursement_id, amount_collected, date, officer, type, remaining_balance) VALUES (?, ?, ?, ?, ?, ?, 'repayment', ?)")
                        ->execute([$tx_id, $client_id, $disb_id, $pay_amt, $date_fmt, $username, $new_rem]);
                    $client_updates[] = "Loan Switched & Paid";
                }
            } else {
                $new_rem = max(0, floatval($loan['remaining_balance']) - $pay_amt);
                $updateDisbursementBal($disb_id, $new_rem, $payment_date);
                
                $tx_id = 'LCL-' . strtoupper(generate_uuid());
                $pdo->prepare("INSERT INTO loan_collections (transaction_id, client_id, disbursement_id, amount_collected, date, officer, type, remaining_balance) VALUES (?, ?, ?, ?, ?, ?, 'repayment', ?)")
                    ->execute([$tx_id, $client_id, $disb_id, $pay_amt, $date_fmt, $username, $new_rem]);
                $client_updates[] = "Loan Paid";
            }
        } elseif ($existing_loan_tx && $pay_amt == 0) {
            $disb_id = $existing_loan_tx['disbursement_id'];
            if ($disb_id) $revertDisbursementBal($disb_id, floatval($existing_loan_tx['amount_collected']));
            $pdo->prepare("DELETE FROM loan_collections WHERE transaction_id = ? AND client_id = ?")->execute([$existing_loan_tx['transaction_id'], $client_id]);
            $client_updates[] = "Loan Removed";
        }

        // =======================================================
        // 2. SAVINGS DEPOSIT
        // =======================================================
        $chk = $pdo->prepare("SELECT transaction_id, amount FROM saving_collections WHERE client_id = ? AND DATE(date) = ? AND type = 'deposit' FOR UPDATE");
        $chk->execute([$client_id, $payment_date]);
        $existing_sav_tx = $chk->fetch(PDO::FETCH_ASSOC);

        if ($saving_amt > 0 || $existing_sav_tx) {
            $stmt = $pdo->prepare("SELECT balance FROM saving_balances WHERE client_id = ?");
            $stmt->execute([$client_id]);
            $current_balance = floatval($stmt->fetchColumn() ?: 0);

            // COLLATERAL CHECK: Block reducing/clearing savings deposit if client has active loan and it drops balance below requirement
            if ($existing_sav_tx && $saving_amt < floatval($existing_sav_tx['amount']) && $loan) {
                $req_savings = floatval($loan['principal']) * $sav_req_pct;
                $test_bal = $current_balance - floatval($existing_sav_tx['amount']) + $saving_amt;
                
                if ($test_bal < $req_savings) {
                    throw new Exception("Cannot clear or reduce savings deposit. Must maintain " . ($sav_req_pct * 100) . "% of loan principal (₦" . number_format($req_savings, 2) . ") as collateral.");
                }
            }

            if ($existing_sav_tx) {
                $old_amt = floatval($existing_sav_tx['amount']);
                $diff = $saving_amt - $old_amt;
                $new_bal = $current_balance + $diff;

                if ($saving_amt > 0) {
                    $updateSavingsBal($client_id, $savings_id, $new_bal);
                    $pdo->prepare("UPDATE saving_collections SET amount = ?, balance_after = ? WHERE transaction_id = ? AND client_id = ?")->execute([$saving_amt, $new_bal, $existing_sav_tx['transaction_id'], $client_id]);
                    $client_updates[] = "Savings Updated";
                } else {
                    $updateSavingsBal($client_id, $savings_id, $new_bal);
                    $pdo->prepare("DELETE FROM saving_collections WHERE transaction_id = ? AND client_id = ?")->execute([$existing_sav_tx['transaction_id'], $client_id]);
                    $client_updates[] = "Savings Removed";
                }
            } elseif ($saving_amt > 0) {
                $new_bal = $current_balance + $saving_amt;
                $updateSavingsBal($client_id, $savings_id, $new_bal);

                $tx_id = 'SAV-' . strtoupper(generate_uuid());
                $pdo->prepare("INSERT INTO saving_collections (transaction_id, client_id, savings_id, amount, type, date, officer, balance_after, created_at) VALUES (?, ?, ?, ?, 'deposit', ?, ?, ?, NOW())")
                    ->execute([$tx_id, $client_id, $savings_id, $saving_amt, $date_fmt, $username, $new_bal]);
                $client_updates[] = "Saved";
            }
        }

        // =======================================================
        // 3. WITHDRAWAL
        // =======================================================
        $chk = $pdo->prepare("SELECT transaction_id, amount, type FROM saving_collections WHERE client_id = ? AND DATE(date) = ? AND type IN ('cash','withdrawal','return') FOR UPDATE");
        $chk->execute([$client_id, $payment_date]);
        $existing_wth_tx = $chk->fetch(PDO::FETCH_ASSOC);

        if ($existing_wth_tx) {
            $old_deduct = abs(floatval($existing_wth_tx['amount']));
            $old_type = $existing_wth_tx['type'];

            // Fetch latest balance safely before reverting
            $stmt = $pdo->prepare("SELECT balance FROM saving_balances WHERE client_id = ?");
            $stmt->execute([$client_id]);
            $cur_bal = floatval($stmt->fetchColumn() ?: 0);
            
            $updateSavingsBal($client_id, $savings_id, $cur_bal + $old_deduct);
            
            if (($old_type === 'withdrawal' || $old_type === 'return') && $loan) {
                $revertDisbursementBal($loan['id'], $old_deduct);
            }
            
            $pdo->prepare("DELETE FROM saving_collections WHERE transaction_id = ? AND client_id = ?")->execute([$existing_wth_tx['transaction_id'], $client_id]);
            if ($withdrawal_type === '') $client_updates[] = "Withdrawal Removed";
        }

        if ($withdrawal_type !== '' && $withdrawal_amt > 0) {
            // Re-fetch current balance in case it changed due to above reverts/deposits
            $stmt = $pdo->prepare("SELECT balance FROM saving_balances WHERE client_id = ?");
            $stmt->execute([$client_id]);
            $cur_bal = floatval($stmt->fetchColumn() ?: 0);

            // --- WITHDRAWAL LOGIC ENFORCEMENT ---
            if ($loan) {
                $loan_total = floatval($loan['total_payable']);
                $loan_rem = floatval($loan['remaining_balance']);
                $paid = $loan_total - $loan_rem;
                $num_inst = intval($loan['num_installments'] ?: ($co_is_weekly ? 24 : 23));
                $inst_amt = ($num_inst > 0) ? ($loan_total / $num_inst) : 0;
                $installments_paid = ($inst_amt > 0) ? floor($paid / $inst_amt) : 0;

                // Rule 1: 10th Installment Check
                if (($withdrawal_type === 'cash' || $withdrawal_type === 'withdrawal') && $installments_paid < 10) {
                    throw new Exception("Withdrawal denied. Minimum 10 installments required (Paid: $installments_paid).");
                }

                // Rule 2: Savings Buffer Percentage Check
                $principal = floatval($loan['principal']); 
                $buffer_col = 'buffer_' . $withdrawal_type;
                $buffer_pct = floatval($w_settings[$buffer_col] ?? 10) / 100;
                $required_savings = $principal * $buffer_pct;

                if ($cur_bal < $required_savings) {
                    throw new Exception("Denied. Savings below " . ($buffer_pct * 100) . "% of principal. Req: ₦" . number_format($required_savings, 2));
                }
            }

            // Rule 3: Daily Limit Check
            $today = date('Y-m-d', strtotime($payment_date));
            $sql_daily = "SELECT COUNT(*) FROM saving_collections WHERE client_id = ? AND DATE(date) = ? AND type IN ('cash','withdrawal','return')";
            $params_daily = [$client_id, $today];
            if ($existing_wth_tx) {
                $sql_daily .= " AND transaction_id != ?";
                $params_daily[] = $existing_wth_tx['transaction_id'];
            }
            $stmt_daily = $pdo->prepare($sql_daily);
            $stmt_daily->execute($params_daily);
            if ($stmt_daily->fetchColumn() >= floatval($w_settings['max_withdrawals_per_day'])) {
                throw new Exception("Daily withdrawal limit reached.");
            }
            // ------------------------------------

            // For 'return' type, use all savings up to loan balance
            $actual_deduct = $withdrawal_amt;
            if ($withdrawal_type === 'return' && $loan) {
                $actual_deduct = min($cur_bal, floatval($loan['remaining_balance']));
            }

            $new_bal = $cur_bal - $actual_deduct;
            if ($new_bal < 0) {
                throw new Exception("Insufficient savings balance.");
            }

            $updateSavingsBal($client_id, $savings_id, $new_bal);
            
            if (($withdrawal_type === 'withdrawal' || $withdrawal_type === 'return') && $loan) {
                $new_loan_rem = max(0, floatval($loan['remaining_balance']) - $actual_deduct);
                $updateDisbursementBal($loan['id'], $new_loan_rem, $payment_date);
            }

            $tx_prefix = ($withdrawal_type==='return' ? 'RTN-' : ($withdrawal_type==='cash' ? 'CSH-' : 'WTH-'));
            $tx_id = $tx_prefix . strtoupper(generate_uuid());
            
            $pdo->prepare("INSERT INTO saving_collections (transaction_id, client_id, savings_id, amount, type, date, officer, balance_after, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())")
                ->execute([$tx_id, $client_id, $savings_id, -$actual_deduct, $withdrawal_type, $date_fmt, $username, $new_bal]);
            
            $action = ($existing_wth_tx) ? "Withdrawal Updated" : "Withdrawn";
            $client_updates[] = $action;
        }

        if (!empty($client_updates)) {
            $pdo->commit();
            echo json_encode(['success' => true, 'message' => implode(', ', array_unique($client_updates))]);
        } else {
            $pdo->rollback();
            echo json_encode(['success' => true, 'message' => 'No edits processed']);
        }
        
    } catch (Exception $e) {
        $pdo->rollback();
        // Clean up orphan file upload if DB transaction fails
        if ($uploaded_pic_path && file_exists('../' . $uploaded_pic_path)) {
            unlink('../' . $uploaded_pic_path);
        }
        echo json_encode(['success' => false, 'message' => 'Processing Error: ' . $e->getMessage()]);
    }
    exit();
}

// ==========================================================================
// 5. VIEW DATA PREP
// ==========================================================================
$assigned_unions = [];
$clients_by_union =[];
$unions_by_co =[];

// Fetch ALL active COs in the branch so they appear in the dropdown regardless of whether they have active clients yet
$stmt_cos = $pdo->prepare("SELECT username FROM users WHERE branch_id = ? AND role = 'co' AND status = 'active'");
$stmt_cos->execute([$branch_id]);
$co_list = $stmt_cos->fetchAll(PDO::FETCH_COLUMN);

foreach ($co_list as $co) {
    $unions_by_co[$co] =[];
}

$stmt = $pdo->prepare("SELECT id, name, `union`, officer_username FROM clients WHERE branch_id = ? AND status='active' ORDER BY name ASC");
$stmt->execute([$branch_id]);

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $u = $row['union'] ?? 'Unassigned';
    $co = $row['officer_username'] ?? 'Unassigned';
    
    if (!isset($clients_by_union[$u])) { $clients_by_union[$u] =[]; }
    $clients_by_union[$u][] = $row;
    
    if (!isset($unions_by_co[$co])) { 
        $unions_by_co[$co] =[]; 
        if ($co !== 'Unassigned' && !in_array($co, $co_list)) {
            $co_list[] = $co; // Include them if they have active clients but are somehow not in the user table fetch
        }
    }
    if (!in_array($u, $unions_by_co[$co])) { $unions_by_co[$co][] = $u; }
}
sort($co_list);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#3b82f6">
    <title>Bulk Collection | CUPAD</title>
    
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <style>
        :root {
            --primary-color: #3b82f6; 
            --secondary-color: #8b5cf6;
            --success-color: #22c55e; 
            --warning-color: #f59e0b; 
            --error-color: #ef4444;
            --bg-primary: #f0f2f5; 
            --bg-secondary: #ffffff; 
            --bg-card: #ffffff;
            --bg-header: rgba(255, 255, 255, 0.95);
            --text-primary: #1f2937; 
            --text-secondary: #6b7280; 
            --border-color: #e5e7eb;
            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --border-radius-lg: 1rem; 
            --border-radius-xl: 1.5rem;
        }
        html.dark {
            --bg-primary: #0f172a; 
            --bg-secondary: #1e293b; 
            --bg-card: #1e293b;
            --bg-header: rgba(15, 23, 42, 0.95);
            --text-primary: #f1f5f9; 
            --text-secondary: #94a3b8; 
            --border-color: rgba(255, 255, 255, 0.08);
        }
        
        * { box-sizing: border-box; margin: 0; padding: 0; scrollbar-width: thin; scrollbar-color: rgba(0, 0, 0, 0.2) transparent; }
        html.dark * { scrollbar-color: rgba(255, 255, 255, 0.2) transparent; }
        
        body { 
            font-family: 'Inter', sans-serif; 
            background-color: var(--bg-primary); 
            color: var(--text-primary); 
            padding-bottom: 140px; 
            -webkit-tap-highlight-color: transparent; 
            transition: background-color 0.3s, color 0.3s;
        }
        
        input, select, textarea, button, a, label[for] { cursor: pointer; }
        input[type="text"], input[type="number"], input[type="datetime-local"], textarea { cursor: text; }
        input:disabled, button:disabled { cursor: not-allowed; }
        input:read-only { cursor: not-allowed; }
        select { cursor: pointer; }
        select:disabled, option:disabled { cursor: not-allowed; }

        /* Typography */
        .text-sm { font-size: 0.875rem; line-height: 1.25rem; }
        .text-xs { font-size: 0.75rem; line-height: 1rem; }
        .font-bold { font-weight: 700; }
        .font-extrabold { font-weight: 800; }
        .font-medium { font-weight: 500; }
        .text-primary { color: var(--primary-color); }
        .text-secondary { color: var(--text-secondary); }
        .text-success { color: var(--success-color); }
        .text-warning { color: var(--warning-color); }
        .text-error { color: var(--error-color); }
        .text-center { text-align: center; }

        /* Layout Utilities */
        .container { max-width: 1280px; margin: 0 auto; padding: 1.5rem 1rem; }
        @media (max-width: 768px) { .container { padding: 0.5rem 0.75rem; } }
        .flex { display: flex; }
        .flex-col { flex-direction: column; }
        .items-center { align-items: center; }
        .justify-between { justify-content: space-between; }
        .justify-center { justify-content: center; }
        .flex-1 { flex: 1; }
        .gap-1 { gap: 0.25rem; }
        .gap-2 { gap: 0.5rem; }
        .gap-3 { gap: 0.75rem; }
        .w-full { width: 100%; }
        .mt-1 { margin-top: 0.25rem; }
        .mb-1 { margin-bottom: 0.25rem; }
        .mb-2 { margin-bottom: 0.5rem; }
        .hidden { display: none !important; }

        /* Unified Header Styles */
        .main-header { background: var(--bg-header); backdrop-filter: blur(12px); border-bottom: 1px solid var(--border-color); position: sticky; top: 0; z-index: 50; padding-top: env(safe-area-inset-top); }
        .navbar { display: flex; justify-content: space-between; align-items: center; padding: 0.8rem 1rem; max-width: 1280px; margin: 0 auto; }
        .logo { font-weight: 700; font-size: 1.25rem; color: var(--primary-color); display: flex; align-items: center; gap: 0.5rem; text-decoration: none; }
        .logo img { height: 32px; width: auto; }

        .nav-actions { display: flex; align-items: center; gap: 0.75rem; }
        .icon-btn { padding: 0.5rem; color: #6b7280; border-radius: 50%; transition: all 0.2s; background: none; border: none; cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 1rem; }
        .icon-btn:hover { background-color: #f3f4f6; }
        .icon-btn.text-red { color: #ef4444; }
        .icon-btn.text-red:hover { background-color: #fef2f2; }
        html.dark .icon-btn { color: #9ca3af; }
        html.dark .icon-btn:hover { background-color: #1f2937; }
        html.dark .icon-btn.text-red:hover { background-color: rgba(127, 29, 29, 0.2); }
        
        html.dark .dark-hidden { display: none; }
        .dark-inline { display: none; }
        html.dark .dark-inline { display: inline-block; }

        .profile-btn { width: 36px; height: 36px; border-radius: 50%; overflow: hidden; border: 2px solid var(--primary-color); background: var(--bg-secondary); cursor: pointer; display: flex; align-items: center; justify-content: center; position: relative; }
        .profile-btn img { position: absolute; width: 100%; height: 100%; object-fit: cover; }
        .profile-fallback-icon { font-size: 1.2rem; color: var(--text-secondary); }

        /* Unified Bottom Navigation */
        .mobile-bottom-nav { display: none; position: fixed; bottom: 0; left: 0; right: 0; background: var(--bg-secondary); border-top: 1px solid var(--border-color); padding: 0.5rem 1rem calc(0.5rem + env(safe-area-inset-bottom)); z-index: 101; justify-content: space-between; box-shadow: 0 -4px 10px rgba(0,0,0,0.05); }
        .nav-item { display: flex; flex-direction: column; align-items: center; justify-content: center; text-decoration: none; color: var(--text-secondary); font-size: 0.7rem; font-weight: 500; gap: 4px; flex: 1; }
        .nav-item i { font-size: 1.4rem; }
        .nav-item.active { color: var(--primary-color); }
        .nav-item.active .nav-icon-highlight { background: var(--primary-color); width: 45px; height: 45px; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; margin-top: -25px; box-shadow: 0 4px 10px rgba(59, 130, 246, 0.4); }
        @media (max-width: 768px) { .mobile-bottom-nav { display: flex; } }

        /* Page Specific Styles */
        .card { background-color: var(--bg-card); border-radius: var(--border-radius-xl); box-shadow: var(--shadow-sm); border: 1px solid var(--border-color); padding: 1.25rem; margin-bottom: 1.5rem; }
        @media (max-width: 768px) { .card { padding: 0.875rem; margin-bottom: 0.75rem; border-radius: 1rem; } }
        .controls-layout { display: flex; flex-direction: column; gap: 1rem; }
        @media (min-width: 768px) { .controls-layout { flex-direction: row; justify-content: space-between; align-items: flex-start; } }
        
        .controls-header { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; margin-bottom: 0.75rem; gap: 0.5rem; }
        .label-heading { font-size: 0.75rem; font-weight: 700; color: #6b7280; text-transform: uppercase; display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem; }
        html.dark .label-heading { color: #9ca3af; }
        .btn-link { font-size: 0.625rem; color: var(--primary-color); background: none; border: none; padding: 0; cursor: pointer; text-decoration: none; }
        .btn-link:hover { text-decoration: underline; }

        .btn-autofill { background-color: #eff6ff; color: #1d4ed8; padding: 0.375rem 1rem; border-radius: 9999px; font-weight: 700; font-size: 0.75rem; display: flex; align-items: center; border: none; transition: all 0.2s; box-shadow: var(--shadow-sm); }
        .btn-autofill:hover:not(:disabled) { background-color: #dbeafe; transform: translateY(-1px); box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        .btn-autofill:active:not(:disabled) { transform: translateY(0); }
        html.dark .btn-autofill { background-color: rgba(30, 58, 138, 0.3); color: #60a5fa; }
        html.dark .btn-autofill:hover:not(:disabled) { background-color: rgba(30, 58, 138, 0.5); }
        .btn-autofill:disabled { opacity: 0.6; cursor: not-allowed; }

        .search-wrapper { position: relative; }
        .search-icon { position: absolute; left: 0.75rem; top: 50%; transform: translateY(-50%); color: #9ca3af; font-size: 0.75rem; }
        .search-input { background-color: var(--bg-primary); border: 1px solid var(--border-color); border-radius: 9999px; padding: 0.375rem 0.75rem 0.375rem 2rem; font-size: 0.75rem; width: 9rem; color: var(--text-primary); transition: all 0.3s; outline: none; box-shadow: var(--shadow-sm); }
        .search-input:focus { width: 14rem; border-color: var(--primary-color); box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.2); }

        .union-container { display: flex; flex-wrap: wrap; gap: 0.5rem; }
        .union-pill { padding: 0.5rem 1rem; border-radius: 99px; font-size: 0.75rem; font-weight: 700; white-space: nowrap; border: 1px solid var(--border-color); cursor: pointer; transition: all 0.2s; background: var(--bg-secondary); color: var(--text-secondary); }
        .union-pill:hover:not(.active) { background: #f9fafb; transform: translateY(-1px); box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        html.dark .union-pill:hover:not(.active) { background: #334155; }
        .union-pill.active { background: var(--primary-color) !important; color: white !important; border-color: var(--primary-color) !important; box-shadow: 0 4px 6px -1px rgba(59, 130, 246, 0.3); transform: translateY(-2px); }

        .date-wrapper { flex-shrink: 0; }
        @media (min-width: 768px) { .date-wrapper { width: 12rem; border-left: 1px solid var(--border-color); padding-left: 1rem; } }

        .alert-warning { background-color: #fefce8; color: #a16207; padding: 0.75rem; border-radius: var(--border-radius-xl); margin-bottom: 1.5rem; font-size: 0.875rem; font-weight: 700; display: flex; align-items: center; gap: 0.5rem; border: 1px solid #fef08a; box-shadow: var(--shadow-sm); }
        html.dark .alert-warning { background-color: rgba(113, 63, 18, 0.3); color: #facc15; border-color: rgba(133, 77, 14, 0.5); }

        .input-field { background: var(--bg-primary); border: 1px solid var(--border-color); width: 100%; color: var(--text-primary); outline: none; transition: all 0.2s; border-radius: 0.75rem; padding: 0.625rem; font-size: 0.875rem; font-weight: 700; box-shadow: inset 0 2px 4px 0 rgba(0, 0, 0, 0.02); }
        .input-field:focus { border-color: var(--primary-color); box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.2); background: var(--bg-secondary); }
        .input-field:read-only { background-color: #f3f4f6; color: #6b7280; cursor: not-allowed; }
        .input-field:disabled { background-color: #f3f4f6; opacity: 0.6; cursor: not-allowed; }
        html.dark .input-field:read-only, html.dark .input-field:disabled { background-color: #1e293b; color: #94a3b8; border-color: #334155; }
        
        .input-loan { border-color: #e9d5ff; font-size: 0.75rem; }
        .input-loan:focus { border-color: #a855f7; }
        .input-sav { border-color: #bbf7d0; }
        .input-sav:focus { border-color: #22c55e; }
        .input-wth { border-color: #fecaca; font-size: 0.75rem; }
        .input-wth:focus { border-color: #ef4444; }
        
        html.dark .input-loan { border-color: #6b21a8; }
        html.dark .input-sav { border-color: #166534; }
        html.dark .input-wth { border-color: #991b1b; }

        /* Table Styles & Sticky Header */
        .table-container { border-radius: var(--border-radius-xl); border: 1px solid var(--border-color); overflow-x: auto; background: var(--bg-secondary); max-height: 65vh; position: relative; box-shadow: var(--shadow-sm); }
        table { width: 100%; text-align: left; border-collapse: separate; border-spacing: 0; }
        
        thead th { background: #f8fafc; text-transform: uppercase; font-size: 0.65rem; font-weight: 700; letter-spacing: 0.05em; color: var(--text-secondary); white-space: nowrap; padding: 1rem 0.75rem; position: sticky; top: 0; z-index: 10; border-bottom: 1px solid var(--border-color); }
        html.dark thead th { background: #0f172a; }
        th.col-client { width: 25%; min-width: 220px; }
        th.col-loan { width: 25%; min-width: 180px; background-color: #faf5ff; color: #9333ea; border-left: 1px solid var(--border-color); border-right: 1px solid var(--border-color); }
        th.col-sav { width: 20%; min-width: 130px; background-color: #f0fdf4; color: #16a34a; }
        th.col-wth { width: 25%; min-width: 200px; background-color: #fef2f2; color: #dc2626; border-left: 1px solid var(--border-color); }
        html.dark th.col-loan { background-color: rgba(88, 28, 135, 0.2); }
        html.dark th.col-sav { background-color: rgba(20, 83, 45, 0.2); }
        html.dark th.col-wth { background-color: rgba(127, 29, 29, 0.2); }

        td { border-bottom: 1px solid var(--border-color); padding: 0.75rem; vertical-align: top; transition: background-color 0.3s; }
        tr:last-child td { border-bottom: none; }
        .empty-state { padding: 2.5rem; text-align: center; color: var(--text-secondary); }

        .client-row { transition: background-color 0.3s; }
        .client-row td:first-child { border-left: 4px solid transparent; transition: border-color 0.3s; }
        .client-row:hover { background-color: #f9fafb; cursor: pointer; }
        html.dark .client-row:hover { background-color: rgba(30, 41, 59, 0.5); }

        .row-edited { background-color: #eff6ff !important; }
        .row-edited td:first-child { border-left-color: var(--primary-color) !important; }
        html.dark .row-edited { background-color: rgba(59, 130, 246, 0.1) !important; }

        .row-visited { background-color: #dcfce7 !important; }
        .row-visited td:first-child { border-left-color: var(--success-color) !important; }
        html.dark .row-visited { background-color: rgba(34, 197, 94, 0.15) !important; }

        .col-loan-cell { background-color: rgba(243, 232, 255, 0.5); border-left: 1px solid var(--border-color); border-right: 1px solid var(--border-color); }
        .col-sav-cell { background-color: rgba(220, 252, 231, 0.5); }
        .col-wth-cell { background-color: rgba(254, 226, 226, 0.5); border-left: 1px solid var(--border-color); }
        html.dark .col-loan-cell { background-color: rgba(88, 28, 135, 0.1); }
        html.dark .col-sav-cell { background-color: rgba(20, 83, 45, 0.1); }
        html.dark .col-wth-cell { background-color: rgba(127, 29, 29, 0.1); }

        .client-name { font-weight: 700; font-size: 0.875rem; color: var(--text-primary); max-width: 10rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .client-balances { font-size: 0.625rem; background-color: var(--bg-secondary); border-radius: var(--border-radius-lg); padding: 0.375rem 0.625rem; box-shadow: var(--shadow-sm); color: var(--text-secondary); border: 1px solid var(--border-color); width: 100%; max-width: 170px; margin-top: 0.25rem; }
        .balance-row { display: flex; justify-content: space-between; margin-bottom: 0.125rem; }
        .balance-row:last-child { margin-bottom: 0; }
        .balance-divider { border-top: 1px solid var(--border-color); margin-top: 0.25rem; padding-top: 0.25rem; }
        
        .text-purple { color: #9333ea; }
        .text-red { color: #ef4444; }
        .text-green { color: #16a34a; }
        html.dark .text-purple { color: #c084fc; }
        html.dark .text-red { color: #f87171; }
        html.dark .text-green { color: #4ade80; }

        .reset-btn { opacity: 0; font-size: 0.625rem; color: #9ca3af; background: transparent; border: none; cursor: pointer; transition: all 0.2s; padding: 0.25rem; display: inline-flex; align-items: center; gap: 0.25rem; }
        .client-row:hover .reset-btn { opacity: 1; }
        .reset-btn:hover { color: #ef4444; transform: scale(1.1); }

        .pic-label { display: flex; align-items: center; justify-content: center; background-color: var(--bg-secondary); color: #ef4444; border: 1px solid #fecaca; padding: 0 0.625rem; border-radius: 0.75rem; cursor: pointer; transition: all 0.2s; box-shadow: var(--shadow-sm); }
        .pic-label:hover { background-color: #fef2f2; }
        .pic-label.active { box-shadow: 0 0 0 2px #ef4444; background-color: #fef2f2; }
        html.dark .pic-label { border-color: #991b1b; }
        html.dark .pic-label:hover, html.dark .pic-label.active { background-color: rgba(127, 29, 29, 0.3); }
        .pic-label.disabled { opacity: 0.5; cursor: not-allowed; pointer-events: none; }

        .table-footer-wrapper { background: var(--bg-primary); border-top: 4px solid var(--border-color); position: relative; z-index: 20; }
        .footer-label { padding: 0.75rem; text-align: right; font-weight: 800; color: var(--text-secondary); font-size: 0.875rem; }
        .footer-val { padding: 0.75rem; font-weight: 800; font-size: 1.125rem; }
        .val-loan { color: #9333ea; border-left: 1px solid var(--border-color); border-right: 1px solid var(--border-color); }
        .val-sav { color: #16a34a; }
        .val-wth { color: #dc2626; border-left: 1px solid var(--border-color); }

        .sticky-footer { position: fixed; bottom: 0; left: 0; right: 0; background: var(--bg-header); backdrop-filter: blur(12px); border-top: 1px solid var(--border-color); padding: 1rem; z-index: 100; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 -10px 15px -3px rgba(0,0,0,0.05); }
        @media (max-width: 768px) { .sticky-footer { bottom: calc(64px + env(safe-area-inset-bottom)); padding: 0.5rem 0.75rem; } }

        .footer-title { font-size: 0.625rem; font-weight: 700; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.025em; margin-bottom: 0.125rem; }
        .footer-total { font-size: 1.5rem; font-weight: 800; line-height: 1; }
        @media (max-width: 768px) { 
            .footer-title { font-size: 0.5625rem; margin-bottom: 0.0625rem; }
            .footer-total { font-size: 1.25rem; }
        }
        .auto-save-indicator { display: flex; align-items: center; gap: 0.5rem; font-size: 0.6875rem; font-weight: 700; color: var(--text-secondary); background-color: var(--bg-primary); padding: 0.5rem 1rem; border-radius: 9999px; border: 1px solid var(--border-color); box-shadow: var(--shadow-sm); }
        @media (max-width: 768px) { .auto-save-indicator { padding: 0.375rem 0.625rem; font-size: 0.625rem; gap: 0.375rem; } }
        
        .ping-dot { position: relative; display: flex; width: 0.625rem; height: 0.625rem; }
        .ping-dot-bg { position: absolute; width: 100%; height: 100%; border-radius: 50%; background-color: #4ade80; opacity: 0.75; animation: ping 1s cubic-bezier(0, 0, 0.2, 1) infinite; }
        .ping-dot-fg { position: relative; width: 100%; height: 100%; border-radius: 50%; background-color: #22c55e; }
        @keyframes ping { 75%, 100% { transform: scale(2); opacity: 0; } }

        /* SKELETON LOADING */
        .skeleton-box { position: relative; overflow: hidden; background-color: #e5e7eb; border-radius: 0.25rem; z-index: 1; }
        .skeleton-box::after { content: ""; position: absolute; top: 0; right: 0; bottom: 0; left: 0; transform: translateX(-100%); background-image: linear-gradient(90deg, rgba(255, 255, 255, 0) 0, rgba(255, 255, 255, 0.4) 20%, rgba(255, 255, 255, 0.8) 60%, rgba(255, 255, 255, 0)); animation: shimmer 1.5s infinite; z-index: 2; }
        html.dark .skeleton-box { background-color: #334155; }
        html.dark .skeleton-box::after { background-image: linear-gradient(90deg, rgba(255, 255, 255, 0) 0, rgba(255, 255, 255, 0.05) 20%, rgba(255, 255, 255, 0.1) 60%, rgba(255, 255, 255, 0)); }
        @keyframes shimmer { 100% { transform: translateX(100%); } }

        .skel-title { height: 1.125rem; width: 60%; margin-bottom: 0.5rem; }
        .skel-block { height: 3.5rem; width: 100%; max-width: 170px; border-radius: var(--border-radius-lg); }
        .skel-input { height: 2.5rem; width: 100%; border-radius: 0.75rem; }

        /* TINY SCROLLBAR */
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: rgba(0, 0, 0, 0.2); border-radius: 3px; }
        ::-webkit-scrollbar-thumb:hover { background: rgba(0, 0, 0, 0.3); }
        html.dark ::-webkit-scrollbar-thumb { background: rgba(255, 255, 255, 0.2); }
        html.dark ::-webkit-scrollbar-thumb:hover { background: rgba(255, 255, 255, 0.3); }

        /* MODAL */
        .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 9999; display: none; align-items: center; justify-content: center; backdrop-filter: blur(4px); }
        .modal-overlay.show { display: flex; animation: fadeIn 0.2s; }
        .modal-box { background: var(--bg-secondary); border-radius: var(--border-radius-xl); padding: 1.5rem; max-width: 400px; width: 90%; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.3); animation: scaleIn 0.2s; }
        .modal-icon { width: 3rem; height: 3rem; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 1rem; font-size: 1.5rem; }
        .modal-success { background: rgba(34,197,94,0.1); color: var(--success-color); }
        .modal-error { background: rgba(239,68,68,0.1); color: var(--error-color); }
        .modal-info { background: rgba(59,130,246,0.1); color: var(--primary-color); }
        .modal-title { font-size: 1.125rem; font-weight: 700; text-align: center; margin-bottom: 0.5rem; color: var(--text-primary); }
        .modal-message { text-align: center; color: var(--text-secondary); margin-bottom: 1.5rem; font-size: 0.875rem; }
        .modal-btn { width: 100%; padding: 0.75rem; border-radius: 0.75rem; font-weight: 700; border: none; cursor: pointer; transition: all 0.2s; }
        .modal-btn-success { background: var(--success-color); color: white; }
        .modal-btn-error { background: var(--error-color); color: white; }
        .modal-btn-info { background: var(--primary-color); color: white; }
        .modal-btn:hover { opacity: 0.9; transform: translateY(-1px); }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
        @keyframes scaleIn { from { transform: scale(0.9); opacity: 0; } to { transform: scale(1); opacity: 1; } }
        @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.4; } }
        @keyframes spin { to { transform: rotate(360deg); } }
    </style>
</head>
<body>

    <header class="main-header">
        <nav class="navbar">
            <a href="dashboard.php" class="logo">
                <img src="<?php echo htmlspecialchars($base_path . 'favicon.ico'); ?>" alt="Logo">
                <span>CUPAD BM</span>
            </a>
            
            <div class="nav-actions">
                <button id="theme-toggle" class="icon-btn">
                    <i class="fas fa-moon dark-hidden"></i><i class="fas fa-sun dark-inline"></i>
                </button>
                
                <div class="profile-btn" onclick="window.location.href='profile.php'">
                    <i class="fas fa-user profile-fallback-icon"></i>
                    <?php if (isset($profile_pic) && $profile_pic !== 'default_avatar.png'): ?>
                        <img src="<?php echo $base_path . $profile_pic; ?>" alt="Profile" onerror="this.style.display='none'"> 
                    <?php endif; ?>
                </div>

                <a href="<?php echo htmlspecialchars($base_path . 'logout.php'); ?>" class="icon-btn text-red" title="Logout">
                    <i class="fas fa-sign-out-alt"></i>
                </a>
            </div>
        </nav>
    </header>

    <main class="container">
        
        <!-- TOP CONTROLS -->
        <div class="card">
            <div class="controls-layout">
                <div class="flex-1">
                    <div class="controls-header">
                        <label class="label-heading" style="display:flex; align-items:center;">
                            Select Union
                            <select id="co-filter" class="input-field" style="width: auto; padding: 0.5rem 2rem 0.5rem 1rem; font-size: 0.85rem; border-radius: 0.5rem; font-weight: 600; margin-left: 0.5rem; color:var(--primary-color); background:var(--bg-secondary); border-color:var(--border-color); cursor: pointer;" onchange="filterUnionsByCO(this.value)">
                                <option value="all">All Credit Officers</option>
                                <?php foreach($co_list as $co_name): ?>
                                    <option value="<?php echo htmlspecialchars($co_name); ?>"><?php echo htmlspecialchars($co_name); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <div class="flex items-center gap-2">
                            <!-- Quick Action: Auto-Fill 1x Loan -->
                            <button type="button" onclick="fillAllLoans()" class="btn-autofill" id="btn-autofill">
                                <i class="fas fa-magic mr-1" style="margin-right:0.375rem;"></i> Auto-fill 1x Loan
                            </button>
                            <!-- Search Filter -->
                            <div class="search-wrapper">
                                <i class="fas fa-search search-icon"></i>
                                <input type="text" id="clientSearch" onkeyup="filterClients(this.value)" class="search-input" placeholder="Search name...">
                            </div>
                        </div>
                    </div>
                    <div id="union-container" class="union-container"></div>
                </div>

                <div class="date-wrapper">
                    <label class="label-heading">
                        <div class="flex items-center gap-2">
                            Date 
                            <span id="day_display" style="background: var(--bg-secondary); color: var(--primary-color); padding: 0.125rem 0.375rem; border-radius: 0.25rem; border: 1px solid var(--border-color); text-transform: none; font-size: 0.8rem; letter-spacing: normal;"></span>
                        </div>
                        <button type="button" onclick="setNow()" class="btn-link">Now</button>
                    </label>
                    <input type="datetime-local" id="global_date" class="input-field" value="<?php echo date('Y-m-d\TH:i'); ?>" <?php echo $date_readonly?'readonly':''; ?>>
                </div>
            </div>
        </div>

        <div id="weekend-warning" class="alert-warning hidden">
            <i class="fas fa-exclamation-triangle"></i>
            <span>Weekend transactions are disabled for the selected date. Inputs are locked.</span>
        </div>

        <!-- BULK TABLE UI -->
        <div id="bulkFormContainer">
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th class="col-client">Client / Balances</th>
                            <th class="col-loan">Loan Repay (₦)</th>
                            <th class="col-sav">Sav. Deposit (₦)</th>
                            <th class="col-wth">Withdrawal</th>
                        </tr>
                    </thead>
                    <tbody id="table-body">
                        <!-- Default empty state will be injected here if no union exists -->
                    </tbody>
                    <tfoot id="table-footer" class="hidden table-footer-wrapper">
                        <tr>
                            <td class="footer-label">TOTAL:</td>
                            <td class="footer-val val-loan" id="col_total_loan">₦0</td>
                            <td class="footer-val val-sav" id="col_total_sav">₦0</td>
                            <td class="footer-val val-wth" id="col_total_wth">₦0</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

    </main>

    <!-- STICKY BOTTOM BAR -->
    <div class="sticky-footer">
        <div class="container flex justify-between items-center w-full" style="padding:0; max-width:1280px; margin:0 auto;">
            <div>
                <div class="footer-title">Total Net Collection</div>
                <div id="global_total" class="footer-total text-primary">₦0</div>
            </div>
            <div class="auto-save-indicator">
                <span class="ping-dot">
                  <span class="ping-dot-bg"></span>
                  <span class="ping-dot-fg"></span>
                </span>
                Auto-saving active
            </div>
        </div>
    </div>

    <!-- MOBILE BOTTOM NAVIGATION (Matches BM Dashboard) -->
    <nav class="mobile-bottom-nav">
        <a href="dashboard.php" class="nav-item">
             <i class="fas fa-home"></i>
             <span>Home</span>
        </a>
        <a href="combined_collection.php" class="nav-item active">
             <div class="nav-icon-highlight">
                <i class="fas fa-coins" style="font-size: 1.2rem; margin:0;"></i>
             </div>
             <span style="font-size:0.65rem; font-weight:600; margin-top:2px;">Collect</span>
        </a>
        <a href="disbursement.php" class="nav-item">
             <i class="fas fa-file-invoice-dollar"></i>
             <span>Loans</span>
        </a>
        <a href="clients.php" class="nav-item">
            <i class="fas fa-users"></i>
            <span>Clients</span>
        </a>
        <a href="analytics.php" class="nav-item">
            <i class="fas fa-chart-bar"></i>
            <span>Reports</span>
        </a>
    </nav>

    <div id="modal-overlay" class="modal-overlay" onclick="if(event.target===this) closeModal()">
        <div class="modal-box">
            <div id="modal-icon" class="modal-icon"></div>
            <div id="modal-title" class="modal-title"></div>
            <div id="modal-message" class="modal-message"></div>
            <button id="modal-btn" class="modal-btn" onclick="closeModal()">OK</button>
        </div>
    </div>

    <script>
        const clientsByUnion = <?php echo json_encode($clients_by_union); ?>;
        const unionsByCo = <?php echo json_encode($unions_by_co); ?>;
        const colSettings = <?php echo json_encode($col_settings); ?>;
        const savSettings = <?php echo json_encode($sav_settings); ?>;
        const wSettings = <?php echo json_encode($w_settings); ?>;
        const csrfToken = <?php echo json_encode($_SESSION['csrf_token']); ?>;
        
        let currentUnion = null;

        const NGNFormatter = new Intl.NumberFormat('en-NG', { style: 'currency', currency: 'NGN', minimumFractionDigits: 0, maximumFractionDigits: 0 });

        const dom = {
            unionContainer: document.getElementById('union-container'),
            tableBody: document.getElementById('table-body'),
            tableFooter: document.getElementById('table-footer'),
            colTotalLoan: document.getElementById('col_total_loan'),
            colTotalSav: document.getElementById('col_total_sav'),
            colTotalWth: document.getElementById('col_total_wth'),
            globalDate: document.getElementById('global_date'),
            globalTotal: document.getElementById('global_total'),
            modalOverlay: document.getElementById('modal-overlay'),
            modalIcon: document.getElementById('modal-icon'),
            modalTitle: document.getElementById('modal-title'),
            modalMessage: document.getElementById('modal-message'),
            modalBtn: document.getElementById('modal-btn')
        };

        const saveTimers = {};
        const isSaving = {};
        const needsSave = {};

        // Helper to prevent XSS vulnerability in javascript render loops
        function escapeHTML(str) {
            if (!str) return '';
            return str.replace(/[&<>'"]/g, tag => ({
                '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;'
            }[tag] || tag));
        }

        window.addEventListener('beforeunload', (e) => {
            const pendingSaves = Object.keys(saveTimers).length > 0 || Object.values(isSaving).some(v => v);
            if (pendingSaves) {
                e.preventDefault();
                e.returnValue = 'You have unsaved changes syncing in the background. Are you sure you want to leave?';
            }
        });

        function updateDayDisplay() {
            const dateVal = dom.globalDate.value;
            if (dateVal) {
                const dateObj = new Date(dateVal);
                if (!isNaN(dateObj.getTime())) {
                    const days =['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
                    const dayDisplay = document.getElementById('day_display');
                    if (dayDisplay) {
                        dayDisplay.textContent = days[dateObj.getDay()];
                        
                        // Highlight in red if it's a weekend
                        if (dateObj.getDay() === 0 || dateObj.getDay() === 6) {
                            dayDisplay.style.color = 'var(--error-color)';
                        } else {
                            dayDisplay.style.color = 'var(--primary-color)';
                        }
                    }
                }
            }
        }

        dom.globalDate.addEventListener('change', () => {
            updateDayDisplay();
            reloadTableData();
        });

        function init() {
            updateDayDisplay();
            renderUnionPills('all');
        }

        function filterUnionsByCO(co) {
            renderUnionPills(co);
        }

        function renderUnionPills(coFilter) {
            dom.unionContainer.innerHTML = '';
            let unionsToShow =[];
            
            if (coFilter === 'all') {
                unionsToShow = Object.keys(clientsByUnion);
            } else {
                unionsToShow = unionsByCo[coFilter] ||[];
            }
            
            unionsToShow.sort();

            if(unionsToShow.length === 0) {
                dom.unionContainer.innerHTML = '<span class="text-xs text-secondary italic">No assigned unions</span>';
                dom.tableBody.innerHTML = '<tr><td colspan="4" class="empty-state">No unions matched</td></tr>';
                currentUnion = null;
                dom.tableFooter.classList.add('hidden');
            } else {
                unionsToShow.forEach((u, i) => {
                    const pill = document.createElement('button');
                    pill.type = 'button';
                    pill.className = `union-pill ${i===0 ? 'active' : ''}`;
                    pill.textContent = u;
                    pill.onclick = () => selectUnion(u, pill);
                    dom.unionContainer.appendChild(pill);
                    if(i===0) selectUnion(u, pill);
                });
            }
        }

        function reloadTableData() {
            if(currentUnion) {
                let activeBtn = Array.from(dom.unionContainer.children).find(b => b.textContent === currentUnion);
                selectUnion(currentUnion, activeBtn);
            }
        }
        
        async function selectUnion(union, el) {
            currentUnion = union;
            Array.from(dom.unionContainer.children).forEach(c => c.className = 'union-pill');
            if(el) el.className = 'union-pill active';
            
            document.getElementById('clientSearch').value = ''; 
            dom.tableFooter.classList.add('hidden');

            const coFilter = document.getElementById('co-filter') ? document.getElementById('co-filter').value : 'all';

            // Generate skeleton rows based on actual client count
            let clientCount = 0;
            if (clientsByUnion[union]) {
                if (coFilter === 'all') {
                    clientCount = clientsByUnion[union].length;
                } else {
                    clientCount = clientsByUnion[union].filter(c => c.officer_username === coFilter).length;
                }
            }

            let skeletonHtml = '';
            for(let i=0; i < Math.max(1, Math.min(clientCount, 15)); i++) {
                skeletonHtml += `
                <tr>
                    <td>
                        <div class="skeleton-box skel-title"></div>
                        <div class="skeleton-box skel-block"></div>
                    </td>
                    <td class="col-loan-cell"><div class="skeleton-box skel-input"></div></td>
                    <td class="col-sav-cell"><div class="skeleton-box skel-input"></div></td>
                    <td class="col-wth-cell"><div class="skeleton-box skel-input"></div></td>
                </tr>`;
            }
            dom.tableBody.innerHTML = skeletonHtml;

            const dateVal = dom.globalDate.value;

            try {
                const res = await fetch(`?ajax_action=get_union_data&union=${encodeURIComponent(union)}&date=${encodeURIComponent(dateVal)}&co=${encodeURIComponent(coFilter)}`);
                const json = await res.json();
                renderTable(json.data);
            } catch (e) {
                dom.tableBody.innerHTML = '<tr><td colspan="4" class="empty-state text-error"><i class="fas fa-exclamation-triangle mb-2" style="font-size:1.5rem"></i><br>Error loading data</td></tr>';
            }
        }

        function renderTable(clientData) {
            const d = new Date(dom.globalDate.value);
            const day = d.getDay();
            const isWeekend = (day === 0 || day === 6);
            
            const blockCol = isWeekend && parseInt(savSettings.allow_weekend_collection) !== 1;
            const blockWth = isWeekend && parseInt(wSettings.allow_weekend_withdrawals) !== 1;
            
            const warningEl = document.getElementById('weekend-warning');
            if (warningEl) {
                if (blockCol || blockWth) {
                    warningEl.classList.remove('hidden');
                } else {
                    warningEl.classList.add('hidden');
                }
            }

            if(!clientData || clientData.length === 0) {
                dom.tableBody.innerHTML = '<tr><td colspan="4" class="empty-state"><i class="fas fa-inbox mb-2" style="font-size:1.5rem"></i><br>No active clients in this union.</td></tr>';
                dom.tableFooter.classList.add('hidden');
                return;
            } else {
                dom.tableFooter.classList.remove('hidden');
            }

            let html = '';
            const blockedWths = JSON.parse(wSettings.blocked_withdrawal_types || '[]');

            const colDisabledHtml = blockCol ? 'disabled data-weekend-blocked="true" title="Weekend collections disabled"' : '';
            const wthDisabledHtml = blockWth ? 'disabled data-weekend-blocked="true" title="Weekend withdrawals disabled"' : '';

            clientData.forEach(c => {
                const existing = c.existing || { loan_amt:0, sav_amt:0, wth_type:'', wth_amt:0 };

                let loanOpts = `<option value="">-- None --</option>`;
                let instAmt = 0, loanPrincipal = 0, loanRem = 0, isLoanActive = false;

                if (c.loan) {
                    isLoanActive = true;
                    loanPrincipal = parseFloat(c.loan.principal) || 0;
                    loanRem = parseFloat(c.loan.remaining_balance) || 0;
                    instAmt = parseFloat(c.loan.inst_amt) || 0;
                    
                    if(existing.loan_amt > 0) loanRem += existing.loan_amt;

                    const numInst = parseInt(c.loan.num_installments);
                    const remInst = Math.ceil(loanRem / instAmt);
                    let min = parseInt(colSettings.min_installments_per_payment) || 1;
                    let max = parseInt(colSettings.max_installments_per_payment) || 3;
                    if(numInst === 20 || remInst <= 3) max = Math.max(max, 3);

                    let matchedInstallment = false;
                    for(let i=min; i<=max; i++) {
                        const cost = i * instAmt;
                        if(colSettings.allow_overpayment == 1 || cost <= loanRem + 0.01) {
                            const isSelected = (Math.abs(existing.loan_amt - cost) < 1) ? 'selected' : '';
                            if(isSelected) matchedInstallment = true;
                            loanOpts += `<option value="${i}" data-amt="${cost}" ${isSelected}>${i}x (${NGNFormatter.format(cost)})</option>`;
                        }
                    }
                    
                    if(existing.loan_amt > 0 && !matchedInstallment) { 
                        loanOpts += `<option value="1" data-amt="${existing.loan_amt}" selected>Custom (${NGNFormatter.format(existing.loan_amt)})</option>`;
                    }

                } else {
                    loanOpts = `<option value="" disabled>No Loan</option>`;
                }

                // Initial withdrawal locks
                let wthCash = blockedWths.includes('cash') ? 'disabled' : '';
                let wthDeduct = blockedWths.includes('withdrawal') ? 'disabled' : '';
                let wthRtn = blockedWths.includes('return') ? 'disabled' : '';
                
                let cashLabel = blockedWths.includes('cash') ? 'Cash (Blocked)' : 'Cash';
                let deductLabel = blockedWths.includes('withdrawal') ? 'Deduct (Blocked)' : 'Deduct';
                let rtnLabel = blockedWths.includes('return') ? 'Return (Blocked)' : 'Return';

                // Evaluate Withdrawal Logic rules for UI
                if (c.loan) {
                    const principal = parseFloat(c.loan.principal) || 0;
                    const installmentsPaid = parseInt(c.loan.installments_paid) || 0;
                    const currentSav = parseFloat(c.savings_balance) || 0;

                    const reqCash = principal * (parseFloat(wSettings.buffer_cash || 10) / 100);
                    const reqDeduct = principal * (parseFloat(wSettings.buffer_withdrawal || 10) / 100);
                    const reqReturn = principal * (parseFloat(wSettings.buffer_return || 10) / 100);

                    if (installmentsPaid < 10) {
                        wthCash = 'disabled'; cashLabel = 'Cash (< 10 Paid)';
                        wthDeduct = 'disabled'; deductLabel = 'Deduct (< 10 Paid)';
                    }

                    if (!wthCash && currentSav < reqCash) { wthCash = 'disabled'; cashLabel = 'Cash (Low Sav)'; }
                    if (!wthDeduct && currentSav < reqDeduct) { wthDeduct = 'disabled'; deductLabel = 'Deduct (Low Sav)'; }
                    if (!wthRtn && currentSav < reqReturn) { wthRtn = 'disabled'; rtnLabel = 'Return (Low Sav)'; }
                }

                const wthSelCash = (existing.wth_type === 'cash') ? 'selected' : '';
                const wthSelDed = (existing.wth_type === 'withdrawal') ? 'selected' : '';
                const wthSelRtn = (existing.wth_type === 'return') ? 'selected' : '';
                
                const wthInputVal = (existing.wth_amt > 0) ? existing.wth_amt : '';
                const savInputVal = (existing.sav_amt > 0) ? existing.sav_amt : '';

                let dispSavBal = parseFloat(c.savings_balance);
                if(existing.sav_amt > 0) dispSavBal -= existing.sav_amt;
                if(existing.wth_amt > 0) dispSavBal += existing.wth_amt;

                const hasExistingData = existing.loan_amt > 0 || existing.sav_amt > 0 || existing.wth_amt > 0;
                const visitedClass = hasExistingData ? 'row-visited' : '';
                const safeName = escapeHTML(c.name);

                html += `
                <tr class="client-row ${visitedClass}" data-name="${safeName.toLowerCase()}" id="row_${c.id}">
                    <td>
                        <div class="flex justify-between items-start mb-1">
                            <div class="client-name" title="${safeName}">${safeName}</div>
                            <div class="flex items-center gap-2">
                                <div id="status_${c.id}" class="text-xs font-bold transition-all"></div>
                                <button type="button" onclick="clearRow('${c.id}')" class="reset-btn ${blockCol && blockWth ? 'hidden' : ''}" title="Reset/Clear Row">
                                    <i class="fas fa-undo"></i> Reset
                                </button>
                            </div>
                        </div>
                        <div class="mt-1">
                            <div class="client-balances">
                                <div class="balance-row"><span class="font-bold text-purple">Disbursed:</span> <span class="font-medium">${NGNFormatter.format(loanPrincipal)}</span></div>
                                <div class="balance-row"><span class="font-bold text-red">Outstanding:</span> <span class="font-medium" id="disp_out_${c.id}" data-base-rem="${loanRem}">${NGNFormatter.format(loanRem)}</span></div>
                                <div class="balance-row balance-divider"><span class="font-bold text-green">Savings:</span> <span class="font-medium" id="disp_sav_${c.id}" data-base-sav="${dispSavBal}">${NGNFormatter.format(dispSavBal)}</span></div>
                            </div>
                        </div>
                    </td>
                    
                    <td class="col-loan-cell">
                        <select id="loan_${c.id}" class="input-field input-loan" onchange="handleLoanSelect('${c.id}'); this.style.transform='scale(1.02)'; setTimeout(()=>this.style.transform='',200)" ${colDisabledHtml}>
                            ${loanOpts}
                        </select>
                    </td>
                    
                    <td class="col-sav-cell">
                        <input type="number" id="sav_${c.id}" class="input-field input-sav" placeholder="0.00" value="${savInputVal}" oninput="triggerAutoSave('${c.id}'); calculateGlobal()" onfocus="this.select()" min="0" max="${savSettings.max_savings_amount}" ${colDisabledHtml}>
                    </td>
                    
                    <td class="col-wth-cell">
                        <div class="flex gap-1 mb-1">
                            <select id="wtype_${c.id}" data-inst-amt="${instAmt}" class="input-field input-wth flex-1" onchange="handleWthSelect(this, '${c.id}')" ${wthDisabledHtml}>
                                <option value="">-- None --</option>
                                <option value="cash" ${wthCash} ${wthSelCash}>${cashLabel}</option>
                                <option value="withdrawal" ${wthDeduct} ${wthSelDed}>${deductLabel}</option>
                                <option value="return" ${wthRtn} ${wthSelRtn}>${rtnLabel}</option>
                            </select>
                            
                            <label id="pic_lbl_${c.id}" for="pic_${c.id}" class="pic-label ${existing.wth_type==='cash'?'flex':'hidden'} ${blockWth?'disabled':''}" title="Attach Proof">
                                <i class="fas fa-camera text-sm"></i>
                            </label>
                            <input type="file" id="pic_${c.id}" class="hidden" accept="image/jpeg, image/png, image/jpg" onchange="triggerAutoSave('${c.id}'); document.getElementById('pic_lbl_${c.id}').classList.add('active')" ${wthDisabledHtml}>
                        </div>
                        <input type="number" id="wamt_${c.id}" class="${existing.wth_type?'':'hidden'} input-field input-wth w-full" placeholder="Amount" value="${wthInputVal}" oninput="triggerAutoSave('${c.id}'); calculateGlobal()" onfocus="this.select()" min="0" max="${wSettings.max_cash_withdrawal}" ${(existing.wth_type==='withdrawal'||existing.wth_type==='return')?'readonly':''} ${wthDisabledHtml}>
                    </td>
                </tr>`;
            });

            dom.tableBody.innerHTML = html;

            // Enforce constraints immediately based on any existing loaded states
            clientData.forEach(c => updateRowDependencies(c.id));

            calculateGlobal();
        }

        // Handles disabling specific options between Withdrawal & Loan to avoid conflicts
        function updateRowDependencies(cid) {
            const lSel = document.getElementById(`loan_${cid}`);
            const wSel = document.getElementById(`wtype_${cid}`);
            if (!lSel || !wSel) return;

            const hasLoan = lSel.value !== "";
            const wVal = wSel.value;

            // 1. Disable non-cash withdrawal options if loan is selected
            Array.from(wSel.options).forEach(opt => {
                if (opt.value === 'withdrawal' || opt.value === 'return') {
                    // Do not override if already disabled by our new rules
                    if (!opt.text.includes('(Locked)') && !opt.text.includes('< 10') && !opt.text.includes('Low Sav') && !opt.text.includes('Blocked')) {
                        opt.disabled = hasLoan;
                    }
                }
            });

            // If the user somehow has a forbidden withdrawal type while loan is selected, clear it
            if (hasLoan && (wVal === 'withdrawal' || wVal === 'return')) {
                wSel.value = "";
                const wAmt = document.getElementById(`wamt_${cid}`);
                const wPic = document.getElementById(`pic_lbl_${cid}`);
                if(wAmt) { wAmt.classList.add('hidden'); wAmt.value = ''; }
                if(wPic) { wPic.classList.add('hidden'); }
            }

            // 2. Disable loan select if 'withdrawal' (Deduct) or 'return' is selected
            if (wSel.value === 'withdrawal' || wSel.value === 'return') {
                lSel.disabled = true;
                lSel.value = "";
            } else {
                // Re-enable if it's not locked down by the weekend setting
                if (lSel.getAttribute('data-weekend-blocked') !== 'true') {
                    lSel.disabled = false;
                }
            }
        }

        function handleLoanSelect(cid) {
            updateRowDependencies(cid);
            triggerAutoSave(cid);
            calculateGlobal();
        }

        function handleWthSelect(sel, cid) {
            const wAmt = document.getElementById(`wamt_${cid}`);
            const wPic = document.getElementById(`pic_lbl_${cid}`);
            const val = sel.value;
            const instAmt = parseFloat(sel.getAttribute('data-inst-amt')) || 0;

            if(val === 'return') { 
                wAmt.classList.remove('hidden'); wPic.classList.add('hidden');
                const savDisp = document.getElementById(`disp_sav_${cid}`);
                const baseSav = savDisp ? parseFloat(savDisp.dataset.baseSav) || 0 : 0;
                wAmt.value = baseSav > 0 ? Math.round(baseSav) : '';
                wAmt.readOnly = true;
                wAmt.title = 'Auto-calculated: All savings will be used to settle loan';
            }
            else if(val === 'cash') { 
                wAmt.classList.remove('hidden'); if(!wAmt.value) wAmt.value=''; wAmt.readOnly = false;
                wAmt.title = '';
                if(wSettings.require_image_for_cash == 1) wPic.classList.remove('hidden'); 
                else wPic.classList.add('hidden');
            }
            else if(val === 'withdrawal') { 
                wAmt.classList.remove('hidden'); wPic.classList.add('hidden'); 
                wAmt.value = instAmt > 0 ? Math.round(instAmt) : ''; wAmt.readOnly = true;
                wAmt.title = 'Auto-calculated: 1 installment amount';
            }
            else { 
                wAmt.classList.add('hidden'); wAmt.value=''; wAmt.readOnly = false; wAmt.title = ''; wPic.classList.add('hidden'); 
            }

            updateRowDependencies(cid);
            triggerAutoSave(cid);
            calculateGlobal();
        }

        const AUTO_SAVE_DELAY = 1000; 

        function triggerAutoSave(cid) {
            updateRowStatus(cid, 'pending');
            
            if (saveTimers[cid]) clearTimeout(saveTimers[cid]);
            
            saveTimers[cid] = setTimeout(() => {
                if (isSaving[cid]) {
                    needsSave[cid] = true;
                } else {
                    executeSave(cid);
                }
            }, AUTO_SAVE_DELAY);
        }

        function updateRowStatus(cid, state) {
            const statusEl = document.getElementById(`status_${cid}`);
            const rowEl = document.getElementById(`row_${cid}`);
            if (!statusEl || !rowEl) return;

            if (state === 'pending') {
                statusEl.innerHTML = '<span class="text-warning" style="display:flex;align-items:center;gap:0.25rem;"><span style="width:0.5rem;height:0.5rem;border-radius:50%;background:currentColor;animation:pulse 1.5s ease-in-out infinite;"></span>Pending</span>';
                rowEl.classList.add('row-edited');
            } else if (state === 'saving') {
                statusEl.innerHTML = '<span class="text-primary" style="display:flex;align-items:center;gap:0.25rem;"><span style="width:0.5rem;height:0.5rem;border:2px solid currentColor;border-top-color:transparent;border-radius:50%;animation:spin 0.6s linear infinite;"></span>Saving</span>';
            } else if (state === 'saved') {
                statusEl.innerHTML = '<span class="text-success" style="display:flex;align-items:center;gap:0.25rem;"><i class="fas fa-check-circle"></i>Saved</span>';
                rowEl.classList.remove('row-edited');
                rowEl.style.backgroundColor = 'rgba(34, 197, 94, 0.1)';
                setTimeout(() => { rowEl.style.backgroundColor = ''; }, 800);
            } else if (state === 'error') {
                statusEl.innerHTML = '<span class="text-error" style="display:flex;align-items:center;gap:0.25rem;"><i class="fas fa-exclamation-triangle"></i>Error</span>';
                rowEl.classList.add('row-edited');
                rowEl.style.backgroundColor = 'rgba(239, 68, 68, 0.1)';
            } else if (state === 'clear') {
                statusEl.innerHTML = '';
            }
        }

        async function executeSave(cid) {
            return new Promise(async (resolve) => {
                isSaving[cid] = true;
                updateRowStatus(cid, 'saving');

                const fd = new FormData();
                fd.append('ajax_action', 'save_client');
                fd.append('csrf_token', csrfToken);
                fd.append('client_id', cid);
                fd.append('date', dom.globalDate.value);
                
                fd.append('installment', document.getElementById(`loan_${cid}`)?.value || '');
                fd.append('savings_amount', document.getElementById(`sav_${cid}`)?.value || 0);
                fd.append('withdrawal_type', document.getElementById(`wtype_${cid}`)?.value || '');
                fd.append('withdrawal_amount', document.getElementById(`wamt_${cid}`)?.value || 0);
                
                const picInput = document.getElementById(`pic_${cid}`);
                if(picInput && picInput.files.length > 0) fd.append('picture', picInput.files[0]);

                try {
                    const res = await fetch('', { method: 'POST', body: fd });
                    const text = await res.text();
                    let json;
                    try { json = JSON.parse(text); } catch(e) { throw new Error('Invalid Server Response'); }
                    
                    if (json.success) {
                        updateRowStatus(cid, 'saved');
                    } else {
                        throw new Error(json.message || 'Error saving client');
                    }
                } catch(e) {
                    console.error(e);
                    updateRowStatus(cid, 'error');
                    const clientName = document.getElementById(`row_${cid}`)?.dataset?.name || 'Client';
                    showToast(`Failed to save ${clientName}: ${e.message}`, 'error');
                } finally {
                    isSaving[cid] = false;
                    delete saveTimers[cid];
                    
                    if (needsSave[cid]) {
                        needsSave[cid] = false;
                        executeSave(cid).then(resolve);
                    } else {
                        setTimeout(() => {
                            const statusEl = document.getElementById(`status_${cid}`);
                            if (statusEl && statusEl.innerText.includes('Saved')) {
                                updateRowStatus(cid, 'clear');
                            }
                        }, 3000);
                        resolve();
                    }
                }
            });
        }

        function filterClients(query) {
            const lowerQuery = query.toLowerCase();
            const rows = document.querySelectorAll('.client-row');
            let visibleCount = 0;
            rows.forEach(row => {
                if(row.dataset.name.includes(lowerQuery)) {
                    row.style.display = '';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            });
            
            if(visibleCount === 0 && query) {
                const tbody = document.getElementById('table-body');
                if(!document.getElementById('no-results-row')) {
                    const noResultsRow = document.createElement('tr');
                    noResultsRow.id = 'no-results-row';
                    noResultsRow.innerHTML = '<td colspan="4" class="empty-state"><i class="fas fa-search mb-2" style="font-size:1.5rem"></i><br>No clients found matching "' + query + '"</td>';
                    tbody.appendChild(noResultsRow);
                }
            } else {
                document.getElementById('no-results-row')?.remove();
            }
        }

        // Refactored to sequential logic to prevent DDoS database locking
        async function fillAllLoans() {
            const btn = document.getElementById('btn-autofill');
            const originalText = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin" style="margin-right:0.375rem;"></i> Working...';
            btn.disabled = true;

            const selects = document.querySelectorAll('select[id^="loan_"]');
            let filled = 0;

            for (const sel of selects) {
                if (sel.disabled) continue; 
                
                const opt1 = Array.from(sel.options).find(o => o.value === "1");
                if(opt1 && !opt1.disabled) {
                    sel.value = "1";
                    const cid = sel.id.replace('loan_', '');
                    updateRowDependencies(cid);
                    calculateGlobal();
                    
                    // Await to ensure we don't spam the server with hundreds of concurrent POSTs
                    await executeSave(cid);
                    filled++;
                }
            }

            btn.innerHTML = originalText;
            btn.disabled = false;

            if(filled > 0) showToast(`Auto-filled 1 installment for ${filled} clients`, 'success');
            else showToast('No eligible active loans found to auto-fill', 'info');
        }

        function clearRow(cid) {
            const lSel = document.getElementById(`loan_${cid}`);
            if(lSel && lSel.getAttribute('data-weekend-blocked') !== 'true') lSel.disabled = false;
            if(lSel && !lSel.disabled) lSel.value = "";
            
            const savInp = document.getElementById(`sav_${cid}`);
            if(savInp && !savInp.disabled) savInp.value = "";
            
            const wSel = document.getElementById(`wtype_${cid}`);
            if(wSel && !wSel.disabled) { 
                wSel.value = ""; 
                const wAmt = document.getElementById(`wamt_${cid}`);
                const wPic = document.getElementById(`pic_lbl_${cid}`);
                const picInput = document.getElementById(`pic_${cid}`);
                if(wAmt) { wAmt.classList.add('hidden'); wAmt.value = ''; wAmt.readOnly = false; wAmt.title = ''; }
                if(wPic) { wPic.classList.add('hidden'); wPic.classList.remove('active'); }
                if(picInput) { picInput.value = ''; }
            }

            updateRowDependencies(cid);
            calculateGlobal();
            
            if (saveTimers[cid]) clearTimeout(saveTimers[cid]);
            executeSave(cid);
        }

        let calcTimeout;
        function calculateGlobal() {
            clearTimeout(calcTimeout);
            calcTimeout = setTimeout(() => {
                let tL = 0, tS = 0, tW = 0;
                const rows = document.querySelectorAll('.client-row');
                
                rows.forEach(tr => {
                    const cid = tr.id.replace('row_', '');

                    const lSel = document.getElementById(`loan_${cid}`);
                    let lVal = 0;
                    if(lSel && lSel.value) { lVal = parseFloat(lSel.options[lSel.selectedIndex].dataset.amt)||0; }
                    tL += lVal;

                    const sInp = document.getElementById(`sav_${cid}`);
                    let sVal = sInp ? (parseFloat(sInp.value)||0) : 0;
                    tS += sVal;

                    const wSel = document.getElementById(`wtype_${cid}`);
                    const wAmt = document.getElementById(`wamt_${cid}`);
                    let wVal = 0;
                    let wType = '';
                    if(wSel && wSel.value) {
                        wType = wSel.value;
                        if(wType === 'cash' || wType === 'withdrawal' || wType === 'return') {
                            wVal = (parseFloat(wAmt.value)||0);
                            tW += wVal;
                        }
                    }

                    const outDisp = document.getElementById(`disp_out_${cid}`);
                    if (outDisp) {
                        const baseRem = parseFloat(outDisp.dataset.baseRem) || 0;
                        let extraDeduct = (wType === 'withdrawal' || wType === 'return') ? wVal : 0;
                        const newRem = Math.max(0, baseRem - lVal - extraDeduct);
                        outDisp.textContent = NGNFormatter.format(newRem);
                    }

                    const savDisp = document.getElementById(`disp_sav_${cid}`);
                    if (savDisp) {
                        const baseSav = parseFloat(savDisp.dataset.baseSav) || 0;
                        const newSav = baseSav + sVal - wVal;
                        savDisp.textContent = NGNFormatter.format(newSav);
                    }
                });

                dom.colTotalLoan.textContent = NGNFormatter.format(tL);
                dom.colTotalSav.textContent  = NGNFormatter.format(tS);
                dom.colTotalWth.textContent  = NGNFormatter.format(tW);

                const net = tL + tS - tW;
                dom.globalTotal.textContent = NGNFormatter.format(net);
                if(net < 0) dom.globalTotal.className = "footer-total text-error";
                else dom.globalTotal.className = "footer-total text-primary";
            }, 50);
        }

        function showToast(msg, type) {
            const icons = { success: 'fa-check-circle', error: 'fa-exclamation-circle', info: 'fa-info-circle' };
            const titles = { success: 'Success', error: 'Error', info: 'Information' };
            const classes = { success: 'modal-success', error: 'modal-error', info: 'modal-info' };
            const btnClasses = { success: 'modal-btn-success', error: 'modal-btn-error', info: 'modal-btn-info' };
            
            dom.modalIcon.innerHTML = `<i class="fas ${icons[type]}"></i>`;
            dom.modalIcon.className = `modal-icon ${classes[type]}`;
            dom.modalTitle.textContent = titles[type];
            dom.modalMessage.textContent = msg;
            dom.modalBtn.className = `modal-btn ${btnClasses[type]}`;
            dom.modalOverlay.classList.add('show');
        }

        function closeModal() {
            dom.modalOverlay.classList.remove('show');
        }

        function setNow() {
            if(!dom.globalDate.hasAttribute('readonly')) {
                const n = new Date(); n.setMinutes(n.getMinutes() - n.getTimezoneOffset());
                dom.globalDate.value = n.toISOString().slice(0,16);
                updateDayDisplay();
                reloadTableData();
            }
        }

        document.getElementById('theme-toggle').onclick = () => {
            document.documentElement.classList.toggle('dark');
            localStorage.setItem('theme', document.documentElement.classList.contains('dark') ? 'dark' : 'light');
        };
        if(localStorage.getItem('theme') === 'dark') document.documentElement.classList.add('dark');

        init();
    </script>
</body>
</html>