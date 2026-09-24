<?php
date_default_timezone_set('Africa/Lagos');
// bm/disbursement.php - Branch Manager Version
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

// --- 1. LOGIC SECTION ---

// Default Settings (Fallback structure, but values will be overwritten by DB)
$default_settings =[
    'min_disbursement' => 20000,
    'max_disbursement' => 200000,
    'num_installments' => 23, 
    'weekly_installments' => 24, 
    'min_days_after_registration' => 0, 
    'max_first_loan_amount' => 20000, 
    'max_increment_amount' => 20000, 
    'savings_requirement_percentage' => 0.2,
    'interest_rate' => 0.15, 
    'weekly_interest_rate' => 0.20, 
    'disbursement_date_readonly' => false,
    'disbursement_picture_required' => true
];
$disbursement_settings =[];

// 1. Fetch all settings from `disbursement_settings` table
try {
    $stmt = $pdo->prepare("SELECT * FROM disbursement_settings ORDER BY id DESC LIMIT 1");
    $stmt->execute();
    $db_settings = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($db_settings) {
        $disbursement_settings['min_disbursement'] = $db_settings['min_disbursement'];
        $disbursement_settings['max_disbursement'] = $db_settings['max_disbursement'];
        $disbursement_settings['num_installments'] = $db_settings['default_num_installments'];
        $disbursement_settings['weekly_installments'] = $db_settings['default_weekly_installments'];
        $disbursement_settings['min_days_after_registration'] = $db_settings['min_days_after_registration'];
        $disbursement_settings['max_first_loan_amount'] = $db_settings['global_max_first_loan_amount'];
        $disbursement_settings['max_increment_amount'] = $db_settings['global_max_increment_amount'];
        $disbursement_settings['savings_requirement_percentage'] = $db_settings['savings_requirement_percentage'];
        $disbursement_settings['interest_rate'] = $db_settings['default_interest_rate'];
        $disbursement_settings['weekly_interest_rate'] = $db_settings['default_weekly_interest_rate'];
        $disbursement_settings['disbursement_date_readonly'] = (bool)$db_settings['disbursement_date_readonly'];
        $disbursement_settings['disbursement_picture_required'] = (bool)($db_settings['disbursement_picture_required'] ?? true);
    }
} catch (Exception $e) {
    error_log("DB Error fetching disbursement_settings: " . $e->getMessage());
}

$disbursement_settings = array_merge($default_settings, $disbursement_settings);

// 2. Fetch Loan Plans from `loan_plans` table
$loan_plans =[];
$loan_terms =[];
try {
    $stmt = $pdo->prepare("SELECT * FROM loan_plans");
    $stmt->execute();
    $all_plans_from_db = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($all_plans_from_db as $plan) {
        $plan_attributes = [
            'id' => $plan['id'],
            'duration' => (int)$plan['duration'],
            'installments' => (int)$plan['duration'],
            'unit' => $plan['unit'],
            'interest_rate' => (float)$plan['rate'],
            'rate' => (float)$plan['rate'],
            'min_amount' => (float)$plan['min_amount'],
            'max_amount' => (float)$plan['max_amount'],
            'increment_amount' => (float)$plan['increment_amount'],
            'max_first_loan_amount' => (float)$plan['max_first_loan_amount'],
            'min_days_after_registration' => isset($plan['min_days_after_registration']) ? (int)$plan['min_days_after_registration'] : 0,
            'savings_requirement_percentage' => isset($plan['savings_requirement_percentage']) ? (float)$plan['savings_requirement_percentage'] : 0.20,
            'disbursement_percentage' => isset($plan['disbursement_percentage']) ? (int)$plan['disbursement_percentage'] : 100,
            'disbursement_date_readonly' => isset($plan['disbursement_date_readonly']) ? (bool)$plan['disbursement_date_readonly'] : true,
            'allow_weekends' => isset($plan['allow_weekends']) ? (bool)$plan['allow_weekends'] : false,
            'auto_repayment' => isset($plan['auto_repayment']) ? (bool)$plan['auto_repayment'] : false
        ];

        $loan_plans[$plan['id']] = $plan_attributes;
        $plan_key = strtolower($plan['duration'] . '_' . str_replace(' ', '_', $plan['unit']));
        $loan_terms[$plan_key] = $plan_attributes;
    }
} catch (Exception $e) {
     error_log("DB Error fetching loan_plans: " . $e->getMessage());
}

if (empty($loan_terms)) {
    $loan_terms =[
        '23_days' =>['installments' => 23, 'duration' => 23, 'interest_rate' => 0.15, 'min_amount' => 20000, 'max_amount' => 200000, 'increment_amount' => 20000, 'max_first_loan_amount' => 20000, 'unit' => 'Days', 'min_days_after_registration' => 3, 'savings_requirement_percentage' => 0.20, 'disbursement_date_readonly' => true],
        '13_weeks' =>['installments' => 13, 'duration' => 13, 'interest_rate' => 0.20, 'min_amount' => 100000, 'max_amount' => 250000, 'increment_amount' => 20000, 'max_first_loan_amount' => 50000, 'unit' => 'Weeks', 'min_days_after_registration' => 7, 'savings_requirement_percentage' => 0.20, 'disbursement_date_readonly' => true],
        '24_weeks' =>['installments' => 24, 'duration' => 24, 'interest_rate' => 0.25, 'min_amount' => 150000, 'max_amount' => 600000, 'increment_amount' => 20000, 'max_first_loan_amount' => 50000, 'unit' => 'Weeks', 'min_days_after_registration' => 7, 'savings_requirement_percentage' => 0.25, 'disbursement_date_readonly' => true],
    ];
}

function getLoanTermForAmount($amount) {
    global $loan_terms;
    foreach ($loan_terms as $term_key => $term) {
        if ($amount >= $term['min_amount'] && $amount <= $term['max_amount']) return $term_key;
    }
    return null; 
}

$disbursement_date_readonly = $disbursement_settings['disbursement_date_readonly'];
$disbursement_picture_required = $disbursement_settings['disbursement_picture_required'];

// 3. Fetch Messages from `disbursement_messages` table
$messages =[];
try {
    $stmt = $pdo->prepare("SELECT message_key, message_text FROM disbursement_messages");
    $stmt->execute();
    $messages = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
} catch(Exception $e) {
    error_log("DB Error fetching disbursement_messages: " . $e->getMessage());
}
$default_messages =['duplicate_detected' => 'Duplicate disbursement detected. Please wait.', 'invalid_input' => 'Please select a client and valid amount.'];
$messages = array_merge($default_messages, $messages);


// Load BM Details from DB
$stmt = $pdo->prepare("SELECT full_name, profile_pic FROM users WHERE username = ?");
$stmt->execute([$username]);
$user_data = $stmt->fetch();

$profile_pic = $user_data['profile_pic'] ?? 'default_avatar.png';

// Fetch all active COs in branch
$stmt_cos = $pdo->prepare("SELECT username, is_weekly FROM users WHERE branch_id = ? AND role = 'co' AND status = 'active'");
$stmt_cos->execute([$branch_id]);
$co_list = [];
$co_weekly_status =[];
while ($row = $stmt_cos->fetch(PDO::FETCH_ASSOC)) {
    $co_list[] = $row['username'];
    $co_weekly_status[$row['username']] = (bool)$row['is_weekly'];
}

// Load Clients (Branch Wide)
$clients =[];
$assigned_unions = [];
$unions_by_co =[];

foreach ($co_list as $co) {
    $unions_by_co[$co] =[];
}

try {
    $stmt = $pdo->prepare("SELECT * FROM clients WHERE branch_id = ? AND status = 'active' ORDER BY name ASC");
    $stmt->execute([$branch_id]);

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $clients[] = $row;
        $u = $row['union'] ?? 'Unassigned';
        $co = $row['officer_username'] ?? 'Unassigned';
        
        if (!isset($unions_by_co[$co])) {
            $unions_by_co[$co] =[];
            if ($co !== 'Unassigned' && !in_array($co, $co_list)) {
                $co_list[] = $co;
            }
        }
        if (!in_array($u, $unions_by_co[$co])) {
            $unions_by_co[$co][] = $u;
        }
        if (!in_array($u, $assigned_unions)) {
            $assigned_unions[] = $u;
        }
    }
    sort($assigned_unions);
    sort($co_list);
} catch (PDOException $e) {
    error_log("Failed to fetch clients: " . $e->getMessage());
}

function getClientSavingsBalance($client_id, $pdo) {
    try {
        $stmt = $pdo->prepare("SELECT balance FROM saving_balances WHERE client_id = ?");
        $stmt->execute([$client_id]);
        $result = $stmt->fetch();
        return $result ? floatval($result['balance']) : 0.0;
    } catch (Exception $e) {
        error_log("getClientSavingsBalance error: " . $e->getMessage());
        return 0.0;
    }
}

function convertUnitToDays($unit) {
    $unit_lower = strtolower($unit ?? 'days');
    if (stripos($unit_lower, 'week') !== false) return 7;
    if (stripos($unit_lower, 'month') !== false) return 30;
    return 1;
}

function generate_uuid() {
    return sprintf('%04x%04x-%04x%04x-%04x%04x-%04x%04x', mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff));
}

// --- AJAX: CLIENT PROFILE ---
if (isset($_GET['action']) && $_GET['action'] === 'get_client_profile') {
    header('Content-Type: application/json');
    $client_id = $_GET['client_id'] ?? '';
    if (empty($client_id)) { echo json_encode(['status' => 'error', 'message' => 'No client ID provided']); exit; }

    try {
        // BM Security check
        $stmt = $pdo->prepare("SELECT * FROM clients WHERE id = ? AND branch_id = ?");
        $stmt->execute([$client_id, $branch_id]);
        $client = $stmt->fetch();
        if (!$client) { echo json_encode(['status' => 'error', 'message' => 'Client not found or unauthorized']); exit; }

        $client_savings = getClientSavingsBalance($client_id, $pdo);

        $stmt = $pdo->prepare("SELECT principal, remaining_balance, date FROM disbursements WHERE client_id = ? ORDER BY date DESC");
        $stmt->execute([$client_id]);
        $client_disbursements = $stmt->fetchAll();

        $has_active_loan = false;
        $active_loan_balance = 0;
        foreach ($client_disbursements as $loan) {
            if (floatval($loan['remaining_balance']) > 0) {
                $has_active_loan = true; 
                $active_loan_balance = floatval($loan['remaining_balance']); 
                break;
            }
        }

        $is_first_loan = count($client_disbursements) === 0;
        $max_previous = 0;
        if (!$is_first_loan) {
            foreach ($client_disbursements as $hist) {
                $max_previous = max($max_previous, floatval($hist['principal']));
            }
        }
        
        $client_plan = null;
        if (!empty($client['client_type'])) {
            foreach ($loan_terms as $tk => $term) {
                if (stripos(strtolower($client['client_type']), str_replace('_', ' ', strtolower($tk))) !== false) {
                    $client_plan = $term; break;
                }
            }
        }

        if ($is_first_loan) {
            $max_next = $client_plan['max_first_loan_amount'] ?? $disbursement_settings['max_first_loan_amount'];
        } else {
            $plan_increment = $client_plan['increment_amount'] ?? $disbursement_settings['max_increment_amount'];
            $plan_max = $client_plan['max_amount'] ?? $disbursement_settings['max_disbursement'];
            $max_next = min($max_previous + $plan_increment, $plan_max);
        }

        echo json_encode(['status' => 'success', 'data' =>[
            'is_first_loan' => $is_first_loan, 
            'has_active_loan' => $has_active_loan, 
            'active_loan_balance' => $active_loan_balance,
            'max_next_loan' => $max_next, 
            'client_savings' => $client_savings, 
            'client_type' => $client['client_type'] ?? null,
            'co_username' => $client['officer_username']
        ]]);
        exit;

    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
        exit;
    }
}

$message = '';
$message_type = '';
$allow_disbursement = true;

// Handle File Upload Directory
$upload_dir = $base_path . 'uploads/disbursements/';
if (!file_exists($upload_dir)) mkdir($upload_dir, 0755, true);

// --- POST HANDLING ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    $client_id = trim($_POST['client'] ?? '');
    $principal_amount = floatval(preg_replace('/[^\d.]/', '', $_POST['principal_amount'] ?? '0'));
    $date = $_POST['date'] ?? date('Y-m-d H:i:s');
    
    $stmt = $pdo->prepare("SELECT id, date FROM disbursements WHERE client_id = ? AND principal = ? AND date > (NOW() - INTERVAL 5 MINUTE)");
    $stmt->execute([$client_id, $principal_amount]);
    if ($stmt->fetch()) {
        $message = $messages['duplicate_detected']; 
        $allow_disbursement = false; 
        $message_type = 'error';
    }

    if ($allow_disbursement && (empty($client_id) || $principal_amount <= 0)) {
        $message = $messages['invalid_input']; 
        $allow_disbursement = false; 
        $message_type = 'error';
    }

    // Branch Security
    $stmt = $pdo->prepare("SELECT * FROM clients WHERE id = ? AND branch_id = ?");
    $stmt->execute([$client_id, $branch_id]);
    $selected_client = $stmt->fetch();

    if (!$selected_client) {
        $message = 'Client not found or unauthorized.'; $allow_disbursement = false; $message_type = 'error';
    }

    if ($allow_disbursement) {
        $client_type = $selected_client['client_type'] ?? null;
        $client_co = $selected_client['officer_username'] ?? '';
        $is_co_weekly = $co_weekly_status[$client_co] ?? false;

        $effective_interest_rate = $is_co_weekly 
            ? $disbursement_settings['weekly_interest_rate']
            : $disbursement_settings['interest_rate'];

        $matched_plan = null;
        $active_term_key = null;
        
        if ($client_type) {
            foreach ($loan_terms as $term_key => $term_data) {
                if (stripos(strtolower($client_type), str_replace('_', ' ', strtolower($term_key))) !== false) {
                    $matched_plan = $term_data; $active_term_key = $term_key; break;
                }
            }
        }
        
        if (!$matched_plan) {
            $active_term_key = getLoanTermForAmount($principal_amount);
            if ($active_term_key) $matched_plan = $loan_terms[$active_term_key];
        }

        $stmt_reg = $pdo->prepare("SELECT date FROM registrations WHERE client_id = ? ORDER BY date ASC LIMIT 1");
        $stmt_reg->execute([$client_id]);
        $registration_record = $stmt_reg->fetch();

        if ($registration_record) {
            $reg_date = $registration_record['date'];
            $disbursement_date = date('Y-m-d', strtotime($date));
            $reg_date_only = date('Y-m-d', strtotime($reg_date));
            
            try {
                $reg_dt = new DateTime($reg_date_only);
                $disb_dt = new DateTime($disbursement_date);
                $days_registered = (int)$disb_dt->diff($reg_dt)->format('%a');
            } catch (Exception $e) {
                $days_registered = floor((strtotime($disbursement_date) - strtotime($reg_date)) / 86400);
            }
            
            $min_days_req = (int)($matched_plan['min_days_after_registration'] ?? $disbursement_settings['min_days_after_registration']);

            if ($days_registered < $min_days_req) {
                $message = "Client too new ($days_registered days). Min wait: $min_days_req days."; 
                $allow_disbursement = false; 
                $message_type = 'error';
            }
        }

        if ($allow_disbursement) {
            $min_req = $matched_plan['min_amount'] ?? $disbursement_settings['min_disbursement'];
            $max_req = $matched_plan['max_amount'] ?? $disbursement_settings['max_disbursement'];
            if ($principal_amount < $min_req) { $message = "Below min (₦" . number_format($min_req) . ")"; $allow_disbursement = false; $message_type = 'error'; }
            if ($principal_amount > $max_req) { $message = "Exceeds max (₦" . number_format($max_req) . ")"; $allow_disbursement = false; $message_type = 'error'; }
        }

        if ($allow_disbursement) {
            $current_savings = getClientSavingsBalance($client_id, $pdo);
            
            $stmt = $pdo->prepare("SELECT * FROM disbursements WHERE client_id = ? ORDER BY date DESC");
            $stmt->execute([$client_id]);
            $client_disbursements = $stmt->fetchAll();
            
            $is_first_loan = count($client_disbursements) === 0;
            $has_active = false;
            
            foreach ($client_disbursements as $l) { 
                if (floatval($l['remaining_balance']) > 0) { $has_active = true; break; }
            }
            
            if ($has_active) { $message = 'Client has active loan.'; $allow_disbursement = false; $message_type = 'error'; }
            
            if ($allow_disbursement && !$is_first_loan) {
                $current_savings = getClientSavingsBalance($client_id, $pdo);
                
                $savings_req_pct = $matched_plan['savings_requirement_percentage'] ?? $disbursement_settings['savings_requirement_percentage'];
                $req = $principal_amount * $savings_req_pct;
                
                if ($current_savings < $req) { $message = "Insufficient Savings (Needs ₦" . number_format($req) . ")"; $allow_disbursement = false; $message_type = 'error'; }
            }
            
            if ($allow_disbursement) {
                if ($is_first_loan) {
                     $max = $matched_plan['max_first_loan_amount'] ?? $disbursement_settings['max_first_loan_amount'];
                     if ($principal_amount > $max) { $message = "First loan limit (₦" . number_format($max) . ") exceeded."; $allow_disbursement = false; $message_type = 'error'; }
                } else {
                    $max_prev = 0;
                    foreach ($client_disbursements as $h) if (floatval($h['remaining_balance']) <= 0) $max_prev = max($max_prev, floatval($h['principal']));
                    
                    $inc = $matched_plan['increment_amount'] ?? $disbursement_settings['max_increment_amount'];
                    $limit = min($max_prev + $inc, $matched_plan['max_amount'] ?? $disbursement_settings['max_disbursement']);
                    
                    if ($principal_amount > $limit) { $message = "Increment limit exceeded. Max: ₦" . number_format($limit); $allow_disbursement = false; $message_type = 'error'; }
                }
            }
        }
    }

    if ($allow_disbursement) {
        $pic_path = '';
        if (isset($_FILES['disbursement_picture']) && $_FILES['disbursement_picture']['error'] === UPLOAD_ERR_OK) {
            $new_name = preg_replace('/[^a-zA-Z0-9_-]/', '_', $client_id) . '_' . time() . '.' . pathinfo($_FILES['disbursement_picture']['name'], PATHINFO_EXTENSION);
            if (move_uploaded_file($_FILES['disbursement_picture']['tmp_name'], $upload_dir . $new_name)) $pic_path = 'uploads/disbursements/' . $new_name;
        } elseif ($disbursement_picture_required) {
            $message = 'Picture required.'; $allow_disbursement = false; $message_type = 'error';
        }

        if ($allow_disbursement) {
            $transaction_id = 'DSB-' . generate_uuid();
            
            $term_data = $matched_plan ?:[
                'interest_rate' => $effective_interest_rate, 
                'installments' => $disbursement_settings['num_installments'],
                'unit' => $is_co_weekly ? 'Weeks' : 'Days',
                'duration' => $disbursement_settings['num_installments']
            ];
            
            $service_charge = $principal_amount * $term_data['interest_rate'];
            $total = round($principal_amount + $service_charge);
            
            $payoff_date = date('Y-m-d', strtotime($date . ' + ' . ($term_data['installments'] * convertUnitToDays($term_data['unit'])) . ' days'));
            $override_balance = (isset($_POST['remaining_balance']) && $_POST['remaining_balance'] !== '') ? floatval($_POST['remaining_balance']) : $total;

            try {
                $pdo->beginTransaction();

                $notes = "Unit: " . $term_data['unit'] . ".";
                if ($pic_path) {
                    $notes .= " | Image: " . $pic_path;
                }
                $notes .= " | Processed by BM: " . $username;

                // BM is assigning the loan to the client's actual CO
                $sql = "INSERT INTO disbursements (
                    id, client_id, client_name, officer, branch_id, 
                    principal, interest_rate, total_payable, remaining_balance, 
                    num_installments, loan_term_type, client_type, 
                    date, payoff_date, status, notes
                ) VALUES (
                    ?, ?, ?, ?, ?, 
                    ?, ?, ?, ?, 
                    ?, ?, ?, 
                    ?, ?, 'active', ?
                )";

                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    $transaction_id, 
                    $client_id, 
                    $selected_client['name'], 
                    $client_co, // Important: assign to CO
                    $selected_client['branch_id'],
                    $principal_amount,
                    ($term_data['interest_rate'] * 100),
                    $total,
                    $override_balance,
                    $term_data['installments'],
                    isset($active_term_key) ? str_replace('_', ' ', $active_term_key) : 'Standard',
                    $client_type,
                    date('Y-m-d', strtotime($date)),
                    $payoff_date,
                    $notes
                ]);

                $pdo->commit();
                $message = 'Disbursement recorded successfully.'; $message_type = 'success';

            } catch (PDOException $e) {
                $pdo->rollBack();
                $message = 'Database Error: ' . $e->getMessage();
                $allow_disbursement = false; $message_type = 'error';
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json');
    echo json_encode(['status' => ($message_type === 'success' ? 'success' : 'error'), 'message' => $message ?? '']);
    exit;
}

$current_month = date('Y-m');
$stmt = $pdo->prepare("SELECT COUNT(*) as count, SUM(d.principal) as total FROM disbursements d JOIN clients c ON d.client_id = c.id WHERE c.branch_id = ? AND DATE_FORMAT(d.date, '%Y-%m') = ?");
$stmt->execute([$branch_id, $current_month]);
$stats = $stmt->fetch();
$total_disbursements = $stats['count'] ?? 0;
$total_amount_disbursed = $stats['total'] ?? 0;

if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    header('Content-Type: application/json');
    $page = max(1, intval($_GET['page'] ?? 1));
    $limit = 10;
    $offset = ($page - 1) * $limit;
    
    $co_filter = $_GET['co'] ?? 'all';
    $union_filter = $_GET['union'] ?? 'all';
    $date_filter = $_GET['filter'] ?? 'current_month';

    $where_clauses = ["c.branch_id = ?"];
    $params =[$branch_id];

    if ($co_filter !== 'all') {
        $where_clauses[] = "c.officer_username = ?";
        $params[] = $co_filter;
    }

    if ($union_filter !== 'all') {
        $where_clauses[] = "c.union = ?";
        $params[] = $union_filter;
    }

    if ($date_filter === 'today') {
        $where_clauses[] = "DATE(d.date) = CURDATE()";
    } elseif ($date_filter === 'current_month') {
        $where_clauses[] = "d.date BETWEEN ? AND ?";
        $params[] = date('Y-m-01');
        $params[] = date('Y-m-t');
    }

    $where_sql = implode(' AND ', $where_clauses);
    
    $sql = "SELECT c.name as client_name, d.principal, d.date, d.officer 
            FROM disbursements d 
            JOIN clients c ON d.client_id = c.id 
            WHERE $where_sql 
            ORDER BY d.date DESC 
            LIMIT $limit OFFSET $offset";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $entries =[];
        foreach ($rows as $e) {
            $entries[] =[
                'type' => 'Disbursement',
                'client_name' => $e['client_name'],
                'amount' => floatval($e['principal']),
                'date' => $e['date'],
                'officer' => $e['officer']
            ];
        }
        echo json_encode(['status'=>'success', 'entries'=>$entries, 'has_more'=> count($entries) === $limit]);
    } catch (PDOException $e) {
        echo json_encode(['status'=>'error', 'message'=>$e->getMessage()]);
    }
    exit;
}

$js_clients_data = json_encode($clients);
$js_unions_by_co = json_encode($unions_by_co);
$js_co_weekly_status = json_encode($co_weekly_status);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#f59e0b">
    <title>Disbursement | CUPAD BM</title>
    
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
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
            --text-primary: #1f2937; --text-secondary: #6b7280; --border-color: #e5e7eb;
            --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
            --border-radius-lg: 1rem; --border-radius-xl: 1.5rem;
        }
        html.dark {
            --bg-primary: #0f172a; --bg-secondary: #1e293b; --bg-card: #1e293b;
            --text-primary: #f1f5f9; --text-secondary: #94a3b8; --border-color: rgba(255, 255, 255, 0.08);
        }
        body { font-family: 'Inter', sans-serif; background-color: var(--bg-primary); color: var(--text-primary); padding-bottom: 90px; -webkit-tap-highlight-color: transparent; }
        ::-webkit-scrollbar { width: 5px; height: 5px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        html.dark ::-webkit-scrollbar-thumb { background: #334155; }
        .main-header { background: rgba(255, 255, 255, 0.95); backdrop-filter: blur(12px); border-bottom: 1px solid var(--border-color); position: sticky; top: 0; z-index: 50; padding-top: env(safe-area-inset-top); }
        html.dark .main-header { background: rgba(15, 23, 42, 0.95); }
        .navbar { display: flex; justify-content: space-between; align-items: center; padding: 0.8rem 1rem; max-width: 1280px; margin: 0 auto; }
        .dashboard-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1rem; margin-bottom: 1.5rem; }
        .dashboard-card { border-radius: var(--border-radius-xl); padding: 1.5rem; position: relative; overflow: hidden; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1); color: white; transition: transform 0.2s ease; border: none; }
        .card-gradient-orange { background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); }
        .card-gradient-blue { background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); }
        .card-bg-icon { position: absolute; right: -10px; bottom: -20px; font-size: 6rem; color: white; opacity: 0.1; transform: rotate(-10deg); pointer-events: none; }
        .activity-card { display: flex; align-items: center; background: var(--bg-secondary); padding: 1rem; margin-bottom: 0.75rem; border-radius: var(--border-radius-lg); border: 1px solid var(--border-color); }
        .ac-icon-box { width: 44px; height: 44px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; margin-right: 0.8rem; flex-shrink: 0; }
        .ac-content { flex: 1; min-width: 0; }
        .ac-top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 2px;}
        .ac-bottom { display: flex; justify-content: space-between; align-items: center; margin-top: 2px; }
        .client-name { font-weight: 600; font-size: 0.95rem; color: var(--text-primary); }
        .activity-amount { font-weight: 700; font-size: 1rem; }
        .activity-date { font-size: 0.7rem; color: var(--text-secondary); }
        .input-group { background: var(--bg-primary); border: 1px solid var(--border-color); border-radius: 0.75rem; padding: 0.75rem; transition: all 0.2s ease; position: relative; }
        .input-group:focus-within { border-color: var(--warning-color); box-shadow: 0 0 0 2px rgba(245, 158, 11, 0.2); background: var(--bg-secondary); }
        .input-field { background: transparent; border: none; width: 100%; color: var(--text-primary); outline: none; font-size: 0.95rem; appearance: none; -webkit-appearance: none; padding-right: 2rem; cursor: pointer; }
        .input-field option { background-color: var(--bg-secondary); color: var(--text-primary); padding: 10px; }
        .mobile-bottom-nav { display: none; position: fixed; bottom: 0; left: 0; right: 0; background: var(--bg-secondary); border-top: 1px solid var(--border-color); padding: 0.5rem 1rem calc(0.5rem + env(safe-area-inset-bottom)); z-index: 100; justify-content: space-between; box-shadow: 0 -4px 10px rgba(0,0,0,0.05); }
        .nav-item { display: flex; flex-direction: column; align-items: center; justify-content: center; text-decoration: none; color: var(--text-secondary); font-size: 0.7rem; font-weight: 500; gap: 4px; flex: 1; }
        .nav-item i { font-size: 1.4rem; }
        .nav-item.active { color: var(--warning-color); }
        @media (max-width: 768px) {
            .mobile-bottom-nav { display: flex; }
            .dashboard-grid { display: flex; overflow-x: auto; scroll-snap-type: x mandatory; gap: 1rem; padding-bottom: 0.5rem; margin-right: -1rem; padding-right: 1.5rem; scrollbar-width: none; }
            .dashboard-grid::-webkit-scrollbar { display: none; }
            .dashboard-card { min-width: 85vw; scroll-snap-align: center; flex-shrink: 0; }
        }
        #toast-container { position: fixed; top: 1rem; left: 50%; transform: translateX(-50%); z-index: 999; width: 90%; max-width: 350px; pointer-events: none; }
        .toast { background: var(--bg-secondary); border-left: 4px solid; padding: 1rem; border-radius: 8px; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); margin-bottom: 0.5rem; display: flex; align-items: center; gap: 10px; animation: slideDown 0.3s ease; pointer-events: auto; }
        @keyframes slideDown { from { transform: translateY(-20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
        .profile-btn { width: 36px; height: 36px; border-radius: 50%; overflow: hidden; border: 2px solid var(--warning-color); background: var(--bg-secondary); cursor: pointer; display: flex; align-items: center; justify-content: center; position: relative; }
        .profile-btn img { width: 100%; height: 100%; object-fit: cover; }
    </style>
</head>
<body>

    <div id="toast-container"></div>

    <header class="main-header">
        <nav class="navbar">
            <a href="dashboard.php" class="flex items-center gap-2 no-underline">
                <img src="<?php echo htmlspecialchars($base_path . 'favicon.ico'); ?>" alt="Logo" class="h-8">
                <span class="text-xl font-extrabold text-blue-600 tracking-tight">CUPAD BM</span>
            </a>
            <div class="flex items-center gap-3">
                <button id="theme-toggle" class="p-2 text-gray-500 dark:text-gray-400">
                    <i class="fas fa-moon dark:hidden"></i><i class="fas fa-sun hidden dark:inline"></i>
                </button>
                <div class="profile-btn" onclick="window.location.href='profile.php'">
                    <i class="fas fa-user text-gray-400"></i>
                    <?php if ($profile_pic && $profile_pic !== 'default_avatar.png'): ?>
                        <img src="<?php echo $base_path . $profile_pic; ?>" class="absolute inset-0" onerror="this.style.display='none'"> 
                    <?php endif; ?>
                </div>
                <a href="<?php echo htmlspecialchars($base_path . 'logout.php'); ?>" class="p-2 text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-full transition">
                    <i class="fas fa-sign-out-alt"></i>
                </a>
            </div>
        </nav>
    </header>

    <main class="max-w-7xl mx-auto px-4 py-6">
        
        <div class="mb-6">
            <h1 class="text-2xl font-extrabold text-gray-900 dark:text-white">Disbursements</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400">Manage branch loans and view history</p>
        </div>

        <div class="dashboard-grid">
            <div class="dashboard-card card-gradient-orange">
                <i class="fas fa-hand-holding-usd card-bg-icon"></i>
                <div class="flex justify-between items-start relative z-10">
                    <div>
                        <div class="text-sm font-semibold uppercase opacity-90">Branch Disbursed</div>
                        <div class="text-3xl font-extrabold mt-1 mb-2 count-up" data-target="<?php echo $total_disbursements; ?>">0</div>
                        <div class="text-xs bg-black/20 rounded-full px-3 py-1 inline-flex items-center"><i class="fas fa-calendar-alt mr-1"></i> This Month</div>
                    </div>
                    <div class="w-12 h-12 bg-white/20 backdrop-blur rounded-xl flex items-center justify-center text-xl"><i class="fas fa-file-invoice"></i></div>
                </div>
            </div>

            <div class="dashboard-card card-gradient-blue">
                <i class="fas fa-wallet card-bg-icon"></i>
                <div class="flex justify-between items-start relative z-10">
                    <div>
                        <div class="text-sm font-semibold uppercase opacity-90">Branch Value</div>
                        <div class="text-3xl font-extrabold mt-1 mb-2">₦<span class="count-up" data-target="<?php echo $total_amount_disbursed; ?>" data-currency="true">0</span></div>
                        <div class="text-xs bg-black/20 rounded-full px-3 py-1 inline-flex items-center"><i class="fas fa-coins mr-1"></i> Total Amount</div>
                    </div>
                    <div class="w-12 h-12 bg-white/20 backdrop-blur rounded-xl flex items-center justify-center text-xl"><i class="fas fa-coins"></i></div>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            
            <!-- FORM SECTION -->
            <div class="lg:col-span-1">
                <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm border border-gray-200 dark:border-slate-700 sticky top-24">
                    <div class="p-5 border-b border-gray-100 dark:border-slate-700">
                        <h2 class="text-lg font-bold flex items-center gap-2 text-gray-800 dark:text-white">
                            <i class="fas fa-plus-circle text-orange-500"></i> New Disbursement
                        </h2>
                    </div>

                    <form id="disbursementForm" class="p-5 space-y-5" enctype="multipart/form-data">
                        
                        <!-- Credit Officer Filter -->
                        <div>
                            <label class="text-xs font-bold text-gray-500 uppercase mb-1 block">Credit Officer</label>
                            <div class="input-group py-2">
                                <i class="fas fa-user-tie absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 pointer-events-none"></i>
                                <select id="co-filter" class="input-field cursor-pointer pl-8">
                                    <option value="all">All Credit Officers</option>
                                    <?php foreach($co_list as $co_name): ?>
                                        <option value="<?php echo htmlspecialchars($co_name); ?>"><?php echo htmlspecialchars($co_name); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <i class="fas fa-chevron-down absolute right-3 top-1/2 -translate-y-1/2 text-xs text-gray-400 pointer-events-none"></i>
                            </div>
                        </div>

                        <div>
                            <label class="text-xs font-bold text-gray-500 uppercase mb-2 block">Union Filter</label>
                            <div id="union-tags" class="flex flex-wrap gap-2"></div>
                        </div>

                        <div>
                            <label class="text-xs font-bold text-gray-500 uppercase mb-1 block">Client</label>
                            <div class="input-group py-2">
                                <i class="fas fa-user absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 group-focus-within:text-orange-500 transition-colors pointer-events-none"></i>
                                <select id="client" name="client" required class="input-field cursor-pointer pl-8">
                                    <option value="">Select Client...</option>
                                </select>
                                <i class="fas fa-chevron-down absolute right-3 top-1/2 -translate-y-1/2 text-xs text-gray-400 pointer-events-none"></i>
                            </div>
                        </div>

                        <!-- Client Profile & Plan Unified UI -->
                        <div id="credit-profile-card" class="hidden rounded-xl border border-gray-100 dark:border-slate-700 overflow-hidden shadow-sm">
                            <div id="profile-header" class="px-3 py-2.5 text-xs font-bold text-white bg-slate-600 flex justify-between items-center transition-colors">
                                <span class="flex items-center gap-2"><i class="fas fa-user-check opacity-70"></i> Client Status</span> 
                                <span id="profile-status-badge" class="bg-black/20 px-2.5 py-0.5 rounded-full text-[10px] tracking-wide">Checking...</span>
                            </div>
                            <div class="p-3 bg-white dark:bg-slate-800 grid grid-cols-2 gap-3 text-sm">
                                <div class="bg-gray-50 dark:bg-slate-700/50 p-2 rounded-lg border border-gray-100 dark:border-slate-600">
                                    <span class="text-[9px] text-gray-500 dark:text-gray-400 font-bold uppercase tracking-wide block mb-0.5"><i class="fas fa-wallet mr-1"></i> Savings Bal</span>
                                    <div id="profile-savings" class="font-extrabold text-gray-800 dark:text-white text-sm">₦0</div>
                                </div>
                                <div class="bg-orange-50 dark:bg-orange-900/10 p-2 rounded-lg border border-orange-100 dark:border-orange-800/30 text-right">
                                    <span class="text-[9px] text-orange-500 dark:text-orange-400 font-bold uppercase tracking-wide block mb-0.5">Approved Limit <i class="fas fa-check-circle ml-1"></i></span>
                                    <div id="profile-limit" class="font-extrabold text-orange-600 dark:text-orange-400 text-sm">₦0</div>
                                </div>
                            </div>
                            <div id="profile-active-loan" class="hidden px-3 py-2 bg-red-50 dark:bg-red-900/20 text-red-700 dark:text-red-400 text-xs font-bold border-t border-red-100 dark:border-red-900/30 flex items-center justify-between">
                                <span class="flex items-center gap-1.5"><i class="fas fa-exclamation-circle"></i> Outstanding Arrears</span>
                                <span id="profile-balance" class="font-extrabold">₦0</span>
                            </div>
                        </div>

                        <!-- Available Loan Plans -->
                        <div id="plans-details-card" class="hidden rounded-xl border border-gray-100 dark:border-slate-700 overflow-hidden shadow-sm">
                            <div id="plans-list" class="divide-y divide-gray-100 dark:divide-slate-700"></div>
                        </div>

                        <!-- Amount -->
                        <div>
                            <label class="text-xs font-bold text-gray-500 uppercase mb-1 block">Principal Amount</label>
                            <div class="input-group flex items-center py-2">
                                <span class="text-gray-400 mr-2">₦</span>
                                <input type="text" id="principal_amount" name="principal_amount" class="input-field font-bold p-0 pr-0" placeholder="0" disabled>
                            </div>
                            <div id="savings-validation" class="hidden mt-2 p-3 rounded-lg bg-gray-50 dark:bg-slate-700/50 border border-gray-100 dark:border-slate-600 text-xs shadow-inner">
                                <div class="flex justify-between mb-1.5">
                                    <span id="validation-text" class="font-semibold text-[10px] uppercase tracking-wide">Requirement</span>
                                    <span id="validation-amount" class="font-bold">0</span>
                                </div>
                                <div class="h-1.5 w-full bg-gray-200 dark:bg-slate-600 rounded-full overflow-hidden">
                                    <div id="validation-bar" class="h-full bg-blue-500 transition-all" style="width:0%"></div>
                                </div>
                                <p id="validation-msg" class="mt-1.5 text-[10px] opacity-75 font-medium"></p>
                            </div>
                        </div>

                        <!-- 3-COLUMN CALC GRID -->
                        <div class="grid grid-cols-3 gap-2">
                            <div class="p-2 bg-gray-50 dark:bg-slate-800 border border-gray-100 dark:border-slate-600 rounded-lg text-center">
                                <div class="text-[9px] uppercase text-gray-400 font-bold">Duration</div>
                                <div id="duration-display" class="font-bold text-gray-700 dark:text-gray-200 text-sm mt-1">23 Days</div>
                            </div>
                            <div class="p-2 bg-gray-50 dark:bg-slate-800 border border-gray-100 dark:border-slate-600 rounded-lg text-center">
                                <div class="text-[9px] uppercase text-gray-400 font-bold">Total</div>
                                <input type="text" id="total-payable" readonly class="bg-transparent font-bold text-gray-700 dark:text-gray-200 text-sm w-full text-center mt-0.5" value="₦0">
                            </div>
                            <div class="p-2 bg-gray-50 dark:bg-slate-800 border border-gray-100 dark:border-slate-600 rounded-lg text-center">
                                <div class="text-[9px] uppercase text-gray-400 font-bold">Installment</div>
                                <input type="text" id="installment-amount" readonly class="bg-transparent font-bold text-orange-600 text-sm w-full text-center mt-0.5" value="N/A">
                            </div>
                        </div>

                        <?php if (!empty($settings['show_remaining_balance'])): ?>
                        <div>
                            <label class="text-xs font-bold text-gray-500 uppercase mb-1 block">Balance Override</label>
                            <div class="input-group py-2">
                                <input type="number" name="remaining_balance" class="input-field text-sm p-0 pr-0" placeholder="Auto-calculated if empty">
                            </div>
                        </div>
                        <?php endif; ?>

                        <div>
                            <div class="flex justify-between items-center mb-1">
                                <label class="text-xs font-bold text-gray-500 uppercase block">Date</label>
                                <button type="button" id="btn-set-now" class="text-[10px] font-bold text-blue-600 hover:underline" style="display: <?php echo $disbursement_date_readonly ? 'none' : 'block'; ?>">SET NOW</button>
                            </div>
                            <div class="input-group py-2">
                                <input type="datetime-local" id="date" name="date" value="<?php echo date('Y-m-d\TH:i'); ?>" <?php echo $disbursement_date_readonly ? 'readonly' : ''; ?> class="input-field text-sm p-0 pr-0">
                            </div>
                        </div>

                        <div>
                            <label class="text-xs font-bold text-gray-500 uppercase mb-1 block">Proof Image</label>
                            <input type="file" name="disbursement_picture" id="fileInput" accept="image/*" class="hidden">
                            <div id="dropZone" class="border-2 border-dashed border-gray-300 dark:border-slate-600 rounded-xl p-4 text-center cursor-pointer hover:bg-gray-50 dark:hover:bg-slate-800/50 transition">
                                <div id="upload-placeholder">
                                    <i class="fas fa-cloud-upload-alt text-2xl text-gray-300 mb-1"></i>
                                    <p class="text-xs text-gray-500">Tap to upload</p>
                                    <p class="text-[10px] text-gray-400 mt-0.5">Max size 5MB</p>
                                </div>
                                <div id="previewContainer" class="hidden relative inline-block">
                                    <img id="previewImg" class="h-16 w-auto rounded border dark:border-slate-600">
                                    <button type="button" id="removeFile" class="absolute -top-2 -right-2 bg-red-500 text-white rounded-full w-5 h-5 text-xs flex items-center justify-center"><i class="fas fa-times"></i></button>
                                </div>
                            </div>
                        </div>

                        <button type="submit" id="submitBtn" class="w-full py-3.5 bg-orange-500 hover:bg-orange-600 text-white rounded-xl font-bold shadow-lg shadow-orange-500/30 transition transform active:scale-95">
                            Confirm Disbursement
                        </button>
                    </form>
                </div>
            </div>

            <!-- HISTORY FEED -->
            <div class="lg:col-span-2">
                <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm border border-gray-200 dark:border-slate-700 min-h-[500px] flex flex-col">
                    <div class="p-5 border-b border-gray-100 dark:border-slate-700 flex flex-wrap justify-between items-center gap-3">
                        <h2 class="text-lg font-bold text-gray-800 dark:text-white whitespace-nowrap">Recent Activity</h2>
                        
                        <div class="flex flex-wrap items-center gap-2">
                            <select id="history-co-filter" class="text-xs bg-gray-100 dark:bg-slate-700 border-none rounded-lg px-2 py-1 outline-none cursor-pointer font-semibold text-orange-600">
                                <option value="all">All COs</option>
                                <?php foreach($co_list as $co_name): ?>
                                    <option value="<?php echo htmlspecialchars($co_name); ?>"><?php echo htmlspecialchars($co_name); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select id="history-union-filter" class="text-xs bg-gray-100 dark:bg-slate-700 border-none rounded-lg px-2 py-1 outline-none cursor-pointer">
                                <option value="all">All Unions</option>
                            </select>
                            <div class="flex border border-gray-200 dark:border-slate-600 rounded-lg overflow-hidden">
                                <button class="filter-date-btn text-xs px-3 py-1 text-gray-500 hover:bg-gray-100 dark:hover:bg-slate-700" data-val="all">All</button>
                                <button class="filter-date-btn active text-xs px-3 py-1 bg-orange-100 text-orange-600 font-bold" data-val="current_month">This Month</button>
                                <button class="filter-date-btn text-xs px-3 py-1 text-gray-500 hover:bg-gray-100 dark:hover:bg-slate-700" data-val="today">Today</button>
                            </div>
                        </div>
                    </div>
                    <div id="history-container" class="p-4 flex-1 overflow-y-auto max-h-[600px]">
                        <div class="text-center py-10 text-gray-400">Loading...</div>
                    </div>
                    <div class="p-4 border-t border-gray-100 dark:border-slate-700 text-center">
                        <button id="loadMoreBtn" class="text-sm font-bold text-blue-500 hover:text-blue-600 hidden">Load More</button>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- BM Mobile Navigation -->
    <nav class="mobile-bottom-nav">
        <a href="dashboard.php" class="nav-item">
            <i class="fas fa-home"></i>
            <span>Home</span>
        </a>
        <a href="combined_collection.php" class="nav-item">
            <i class="fas fa-coins"></i>
            <span>Collect</span>
        </a>
        <a href="disbursement.php" class="nav-item active">
             <div style="background: var(--warning-color); width: 45px; height: 45px; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; margin-top: -25px; box-shadow: 0 4px 10px rgba(245, 158, 11, 0.4);">
                <i class="fas fa-hand-holding-usd" style="font-size: 1.2rem; margin:0;"></i>
             </div>
             <span style="font-size:0.65rem; font-weight:600; margin-top:2px; color: var(--warning-color);">Disburse</span>
        </a>
        <a href="loan_collection.php" class="nav-item">
             <i class="fas fa-file-invoice-dollar"></i>
             <span>Loans</span>
        </a>
        <a href="clients.php" class="nav-item">
            <i class="fas fa-users"></i>
            <span>Clients</span>
        </a>
    </nav>

   <script>
        const allClientsData = <?php echo $js_clients_data; ?>;
        const unionsByCo = <?php echo $js_unions_by_co; ?>;
        const settings = <?php echo json_encode($disbursement_settings); ?>;
        const loanTerms = <?php echo json_encode($loan_terms); ?>;
        const coWeeklyStatus = <?php echo $js_co_weekly_status; ?>;
        const defaultRate = settings.interest_rate;
        const serverMsg = <?php echo $message ? json_encode(['text' => $message, 'type' => $message_type]) : 'null'; ?>;
        const loanPlans = <?php echo json_encode($loan_plans); ?>; 
        const disbursementDateReadonly = <?php echo $disbursement_date_readonly ? 'true' : 'false'; ?>;
        
        const ORANGE_COLOR = '#f59e0b';
        let historyState = { page: 1, co: 'all', union: 'all', filter: 'current_month', loading: false, hasMore: true };
        let currentClientData = null;
        let currentActivePlan = null; 

        const formatMoney = amt => '₦' + Math.round(amt).toLocaleString('en-US');

        function showToast(msg, type) {
            const el = document.createElement('div');
            el.className = 'toast';
            el.style.borderLeftColor = type === 'success' ? '#22c55e' : '#ef4444';
            el.innerHTML = `<i class="fas ${type==='success'?'fa-check-circle text-green-500':'fa-exclamation-circle text-red-500'}"></i> <span class="text-sm font-medium text-gray-800 dark:text-gray-200">${msg}</span>`;
            document.getElementById('toast-container').appendChild(el);
            setTimeout(() => { el.style.opacity = '0'; setTimeout(() => el.remove(), 300); }, 3000);
        }

        document.addEventListener('DOMContentLoaded', () => {
            const t = localStorage.getItem('theme');
            if(t==='dark') document.documentElement.classList.add('dark');
            document.getElementById('theme-toggle').onclick = () => {
                document.documentElement.classList.toggle('dark');
                localStorage.setItem('theme', document.documentElement.classList.contains('dark') ? 'dark' : 'light');
            };

            if(serverMsg) showToast(serverMsg.text, serverMsg.type);
            initUnionPills();
            initHistoryFilters();
            loadHistory(true);
            animateNumbers();
        });

        const coFilterEl = document.getElementById('co-filter');
        coFilterEl.addEventListener('change', initUnionPills);

        function getUnions(co) {
            const unions = new Set();
            allClientsData.forEach(c => {
                if (co === 'all' || c.officer_username === co) unions.add(c.union || 'Unassigned');
            });
            return Array.from(unions).sort();
        }

        function getClients(union, co) {
            return allClientsData.filter(c => (c.union || 'Unassigned') === union && (co === 'all' || c.officer_username === co));
        }

        function initUnionPills() {
            const c = document.getElementById('union-tags');
            const co = coFilterEl.value;
            const unions = getUnions(co);

            if(!unions.length) { c.innerHTML = '<span class="text-xs italic text-gray-400">No unions assigned</span>'; populateClients(null, co); return; }
            c.innerHTML = '';
            unions.forEach((u, i) => {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = `px-3 py-1 rounded-full text-xs font-bold border transition ${i===0 ? 'bg-orange-500 text-white border-orange-500' : 'bg-white text-gray-500 border-gray-200 hover:bg-gray-50 dark:bg-slate-700 dark:text-gray-300 dark:border-slate-600'}`;
                btn.textContent = u;
                btn.onclick = () => {
                    Array.from(c.children).forEach(b => b.className = 'px-3 py-1 rounded-full text-xs font-bold border transition bg-white text-gray-500 border-gray-200 hover:bg-gray-50 dark:bg-slate-700 dark:text-gray-300 dark:border-slate-600');
                    btn.className = 'px-3 py-1 rounded-full text-xs font-bold border transition bg-orange-500 text-white border-orange-500';
                    populateClients(u, co);
                };
                c.appendChild(btn);
                if(i===0) populateClients(u, co);
            });
        }

        function populateClients(u, co) {
            const s = document.getElementById('client');
            s.innerHTML = '<option value="">Select Client...</option>';
            if(u) {
                const clients = getClients(u, co);
                clients.forEach(c => s.innerHTML += `<option value="${c.id}">${c.name}</option>`);
            }
            hideCreditProfile();
        }

        function hideCreditProfile() {
            document.getElementById('credit-profile-card').classList.add('hidden');
            document.getElementById('plans-details-card').classList.add('hidden');
            document.getElementById('principal_amount').disabled = true;
            document.getElementById('principal_amount').value = '';
            document.getElementById('total-payable').value = '₦0';
            document.getElementById('installment-amount').value = 'N/A';
            document.getElementById('savings-validation').classList.add('hidden');
            currentClientData = null;
            currentActivePlan = null;
            
            // Revert date readonly to global default
            document.getElementById('date').readOnly = disbursementDateReadonly;
            document.getElementById('btn-set-now').style.display = disbursementDateReadonly ? 'none' : 'block';
        }

        function updatePlanEstimate(amount) {
            const estimateEl = document.getElementById('est-repayment-val');
            if (!estimateEl) return;

            if (!currentActivePlan || amount <= 0) {
                estimateEl.textContent = 'N/A';
                return;
            }
            
            const serviceCharge = amount * currentActivePlan.interest_rate;
            const totalPayable = Math.round(amount + serviceCharge);
            const repaymentAmount = Math.round(totalPayable / currentActivePlan.installments);
            
            estimateEl.textContent = `₦${repaymentAmount.toLocaleString()}`;
        }

        function displayClientPlan() {
            const plansContainer = document.getElementById('plans-list');
            const plansCard = document.getElementById('plans-details-card');
            
            if (!currentClientData || !loanTerms || Object.keys(loanTerms).length === 0) {
                plansCard.classList.add('hidden');
                return null;
            }
            
            let matchedPlan = null;
            let matchedKey = null;

            if (currentClientData.client_type) {
                const typeLower = currentClientData.client_type.toLowerCase().trim();
                for (const[planKey, planData] of Object.entries(loanTerms)) {
                    const planName = planKey.replace(/_/g, ' ');
                    if (typeLower.includes(planName)) {
                        matchedPlan = planData;
                        matchedKey = planKey;
                        break;
                    }
                }
            }

            // Fallback matching logic based on allowed amounts
            if (!matchedPlan) {
                let matchingPlans =[];
                Object.entries(loanTerms).forEach(([termKey, termData]) => {
                    if (currentClientData.max_next_loan >= termData.min_amount && currentClientData.max_next_loan <= termData.max_amount) {
                        matchingPlans.push({ key: termKey, data: termData, fit: 'exact' });
                    }
                });
                
                if (matchingPlans.length === 0) {
                    Object.entries(loanTerms).forEach(([termKey, termData]) => {
                        if (currentClientData.max_next_loan <= termData.max_amount) {
                            matchingPlans.push({ key: termKey, data: termData, fit: 'within' });
                        }
                    });
                }

                if (matchingPlans.length > 0) {
                    let selectedMatch = matchingPlans.find(p => p.fit === 'exact') || matchingPlans[0];
                    matchedPlan = selectedMatch.data; matchedKey = selectedMatch.key;
                }
            }
            
            if (!matchedPlan) { plansCard.classList.add('hidden'); return null; }
            
            const planDuration = matchedPlan.duration || matchedPlan.installments || 23;
            const planUnit = matchedPlan.unit || 'Days';
            const planName = matchedKey ? matchedKey.replace(/_/g, ' ').split(' ').map(w => w.charAt(0).toUpperCase() + w.slice(1)).join(' ') : `${planDuration} ${planUnit}`;
            
            const isWeekly = coWeeklyStatus[currentClientData.co_username] || false;
            let actualInterestRate = matchedPlan.interest_rate;
            if (isWeekly && settings.weekly_interest_rate) {
                 actualInterestRate = settings.weekly_interest_rate;
            }
            matchedPlan.interest_rate = actualInterestRate; // Override for calculation

            const interestPercent = Math.round(actualInterestRate * 100);
            const savingsReqPercent = Math.round((matchedPlan.savings_requirement_percentage || settings.savings_requirement_percentage) * 100);
            
            const minAmount = `₦${(matchedPlan.min_amount/1000).toFixed(0)}k`;
            const maxAmount = `₦${(matchedPlan.max_amount/1000).toFixed(0)}k`;
            const maxFirstLoan = `₦${(matchedPlan.max_first_loan_amount/1000).toFixed(0)}k`;
            const incAmount = matchedPlan.increment_amount || settings.max_increment_amount;
            
            let repaymentLabel = planUnit === 'Weeks' ? 'Weekly' : (planUnit === 'Months' ? 'Monthly' : 'Daily');
            
            plansContainer.innerHTML = `
                <div class="p-4 bg-white dark:bg-slate-800">
                    <div class="flex justify-between items-start mb-4">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-xl bg-orange-50 dark:bg-orange-900/30 text-orange-600 flex items-center justify-center text-lg shadow-sm border border-orange-100 dark:border-orange-800">
                                <i class="fas fa-clipboard-check"></i>
                            </div>
                            <div>
                                <h3 class="font-bold text-gray-800 dark:text-white leading-tight">${planName}</h3>
                                <p class="text-[10px] text-gray-500 dark:text-gray-400 font-medium mt-0.5">${planDuration} ${planUnit} Term</p>
                            </div>
                        </div>
                        ${currentClientData.is_first_loan 
                            ? `<span class="bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-400 text-[9px] font-extrabold px-2 py-1 rounded tracking-wide border border-amber-200 dark:border-amber-800">1ST LOAN</span>` 
                            : `<span class="bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-400 text-[9px] font-extrabold px-2 py-1 rounded tracking-wide border border-green-200 dark:border-green-800">RENEWAL</span>`
                        }
                    </div>

                    <div class="grid grid-cols-2 gap-2 mb-3">
                        <div class="bg-gray-50 dark:bg-slate-700/50 rounded-lg p-2.5 border border-gray-100 dark:border-slate-600 flex flex-col justify-center">
                            <div class="flex items-center gap-1.5 text-gray-500 dark:text-gray-400 mb-1">
                                <i class="fas fa-percentage text-[10px]"></i>
                                <span class="text-[9px] font-bold uppercase tracking-wide">Interest</span>
                            </div>
                            <div class="font-bold text-gray-800 dark:text-white text-sm">${interestPercent}%</div>
                        </div>
                        
                        <div class="bg-gray-50 dark:bg-slate-700/50 rounded-lg p-2.5 border border-gray-100 dark:border-slate-600 flex flex-col justify-center">
                            <div class="flex items-center gap-1.5 text-gray-500 dark:text-gray-400 mb-1">
                                <i class="fas fa-piggy-bank text-[10px]"></i>
                                <span class="text-[9px] font-bold uppercase tracking-wide">Savings Req</span>
                            </div>
                            <div class="font-bold text-gray-800 dark:text-white text-sm">${savingsReqPercent}%</div>
                        </div>

                        <div class="col-span-2 bg-gray-50 dark:bg-slate-700/50 rounded-lg p-2.5 border border-gray-100 dark:border-slate-600 flex justify-between items-center mt-1">
                            <div>
                                <div class="text-[9px] font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-0.5">Plan Bounds</div>
                                <div class="text-xs font-semibold text-gray-700 dark:text-gray-300">
                                    ${minAmount} <i class="fas fa-arrow-right mx-1 text-gray-300 dark:text-gray-600 text-[8px]"></i> ${maxAmount}
                                </div>
                            </div>
                            <div class="text-right">
                                <div class="text-[9px] font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-0.5">
                                    ${currentClientData.is_first_loan ? '1st Loan Cap' : 'Increment Cap'}
                                </div>
                                <div class="text-xs font-bold text-orange-600 dark:text-orange-400">
                                    ${currentClientData.is_first_loan ? maxFirstLoan : '+ ' + formatMoney(incAmount)}
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="bg-gradient-to-r from-orange-50 to-amber-50 dark:from-orange-900/20 dark:to-amber-900/20 rounded-lg p-3 border border-orange-100 dark:border-orange-800 flex justify-between items-center">
                        <div class="flex items-center gap-2">
                            <div class="w-7 h-7 rounded bg-white dark:bg-slate-800 shadow-sm text-orange-600 flex items-center justify-center text-xs">
                                <i class="fas fa-calculator"></i>
                            </div>
                            <span class="text-xs font-bold text-orange-900 dark:text-orange-200">Est. Repayment <span class="opacity-60 text-[10px] font-normal">(/${repaymentLabel})</span></span>
                        </div>
                        <strong class="text-lg text-orange-700 dark:text-orange-400 font-extrabold tracking-tight" id="est-repayment-val">N/A</strong>
                    </div>
                </div>
            `;
            
            plansCard.classList.remove('hidden');
            return matchedPlan;
        }

        document.getElementById('client').addEventListener('change', e => {
            const id = e.target.value;
            if(!id) { hideCreditProfile(); return; }
            
            const badge = document.getElementById('profile-status-badge');
            if(badge) {
                document.getElementById('profile-header').className = 'px-3 py-2.5 text-xs font-bold text-white bg-slate-600 flex justify-between items-center transition-colors';
                badge.className = 'bg-black/20 px-2.5 py-0.5 rounded-full text-[10px] tracking-wide'; 
                badge.textContent = 'CHECKING...';
            }
            document.getElementById('credit-profile-card').classList.remove('hidden');
            
            fetch(`?action=get_client_profile&client_id=${id}`)
                .then(r => r.json())
                .then(res => {
                    if (res.status === 'success') {
                        currentClientData = res.data;
                        updateProfileUI();
                    } else {
                        showToast(res.message, 'error');
                        hideCreditProfile();
                    }
                })
                .catch(err => {
                    showToast('Connection error', 'error');
                    hideCreditProfile();
                });
        });

        function updateProfileUI() {
            if(!currentClientData) return;
            
            const head = document.getElementById('profile-header');
            const badge = document.getElementById('profile-status-badge');
            const inp = document.getElementById('principal_amount');
            
            document.getElementById('profile-savings').textContent = formatMoney(currentClientData.client_savings);
            
            currentActivePlan = displayClientPlan(); 
            
            let displayMaxLoan = currentClientData.max_next_loan;
            
            // Adjust max visually based on specific matched plan
            if (currentActivePlan) {
                if (currentClientData.is_first_loan) {
                    displayMaxLoan = Math.min(displayMaxLoan, currentActivePlan.max_first_loan_amount);
                } else {
                    displayMaxLoan = Math.min(displayMaxLoan, currentActivePlan.max_amount);
                }
            }
            document.getElementById('profile-limit').textContent = formatMoney(displayMaxLoan);
            
            if (currentActivePlan) {
                const dur = currentActivePlan.duration || currentActivePlan.installments;
                const unit = currentActivePlan.unit || 'Days';
                document.getElementById('duration-display').textContent = `${dur} ${unit}`;
                
                // Adjust Date readonly state based on specific plan
                if (currentActivePlan.disbursement_date_readonly !== undefined) {
                    document.getElementById('date').readOnly = currentActivePlan.disbursement_date_readonly;
                    document.getElementById('btn-set-now').style.display = currentActivePlan.disbursement_date_readonly ? 'none' : 'block';
                }
            } else {
                const isWeekly = coWeeklyStatus[currentClientData.co_username] || false;
                const dur = isWeekly ? settings.weekly_installments : settings.num_installments;
                const unit = isWeekly ? 'Weeks' : 'Days';
                document.getElementById('duration-display').textContent = dur + ' ' + unit;
                
                // Revert to global readonly state
                document.getElementById('date').readOnly = disbursementDateReadonly;
                document.getElementById('btn-set-now').style.display = disbursementDateReadonly ? 'none' : 'block';
            }

            if(currentClientData.has_active_loan) {
                head.className = 'px-3 py-2.5 text-xs font-bold text-white bg-red-500 flex justify-between items-center transition-colors';
                badge.className = 'bg-black/20 px-2.5 py-0.5 rounded-full text-[10px] tracking-wide'; 
                badge.textContent = 'INELIGIBLE';
                document.getElementById('profile-active-loan').classList.remove('hidden');
                document.getElementById('profile-balance').textContent = formatMoney(currentClientData.active_loan_balance);
                inp.disabled = true;
            } else {
                head.className = 'px-3 py-2.5 text-xs font-bold text-white bg-emerald-500 flex justify-between items-center transition-colors';
                badge.className = 'bg-black/20 px-2.5 py-0.5 rounded-full text-[10px] tracking-wide'; 
                badge.textContent = 'ELIGIBLE';
                document.getElementById('profile-active-loan').classList.add('hidden');
                inp.disabled = false;
            }
            inp.value = '';
            document.getElementById('total-payable').value = '₦0';
            document.getElementById('installment-amount').value = 'N/A';
            document.getElementById('savings-validation').classList.add('hidden');
        }

        function recalculateValues(amount) {
            if(!currentClientData) return;
            const val = parseFloat(amount) || 0;
            const savings = currentClientData.client_savings;
            
            // Use matched plan savings requirement or global fallback
            const activeSavingsReq = currentActivePlan?.savings_requirement_percentage ?? settings.savings_requirement_percentage;
            const req = currentClientData.is_first_loan ? 0 : (val * activeSavingsReq);
            
            let total, inst;
            
            if (val <= 0) {
                document.getElementById('total-payable').value = '₦0';
                document.getElementById('installment-amount').value = 'N/A';
                document.getElementById('savings-validation').classList.add('hidden');
                updatePlanEstimate(0);
                return;
            }
            
            if (currentActivePlan) {
                const serviceCharge = val * currentActivePlan.interest_rate;
                total = Math.round(val + serviceCharge);
                inst = Math.round(total / currentActivePlan.installments);
            } else {
                const isWeekly = coWeeklyStatus[currentClientData.co_username] || false;
                const activeRate = isWeekly ? settings.weekly_interest_rate : defaultRate;
                const dur = isWeekly ? settings.weekly_installments : settings.num_installments;
                
                total = Math.round(val * (1 + activeRate));
                inst = Math.round(total / dur);
            }
            
            document.getElementById('total-payable').value = formatMoney(total);
            document.getElementById('installment-amount').value = formatMoney(inst);
            
            updatePlanEstimate(val);

            const box = document.getElementById('savings-validation');
            const bar = document.getElementById('validation-bar');
            const txt = document.getElementById('validation-text');
            const amt = document.getElementById('validation-amount');
            const msg = document.getElementById('validation-msg');

            box.classList.remove('hidden');
            let maxAllow = currentClientData.max_next_loan;
            if(currentActivePlan) {
                maxAllow = Math.min(maxAllow, currentActivePlan.max_amount);
                if(currentClientData.is_first_loan) {
                    maxAllow = Math.min(maxAllow, currentActivePlan.max_first_loan_amount);
                }
            } else if (currentClientData.is_first_loan) {
                maxAllow = Math.min(maxAllow, settings.max_first_loan_amount);
            }

            let minAllow = currentActivePlan?.min_amount || settings.min_disbursement;
            
            if(val > maxAllow) {
                bar.className = 'h-full bg-red-500 transition-all'; bar.style.width = '100%';
                txt.textContent = 'Limit Exceeded'; txt.className = 'font-bold text-red-600';
                amt.textContent = formatMoney(maxAllow);
                msg.textContent = `Max allow is ${formatMoney(maxAllow)}`;
            } else if(val < minAllow) {
                bar.className = 'h-full bg-red-500 transition-all'; bar.style.width = '100%';
                txt.textContent = 'Below Minimum'; txt.className = 'font-bold text-red-600';
                amt.textContent = formatMoney(minAllow);
                msg.textContent = `Minimum required is ${formatMoney(minAllow)}`;
            } else if(!currentClientData.is_first_loan && savings < req) {
                const pct = req > 0 ? Math.min(100, (savings/req)*100) : 100;
                bar.className = 'h-full bg-orange-500 transition-all'; bar.style.width = pct+'%';
                txt.textContent = 'Savings Required'; txt.className = 'font-bold text-orange-600';
                amt.textContent = formatMoney(req);
                msg.textContent = req > savings ? `Short by ${formatMoney(req-savings)}` : 'Requirement met';
            } else {
                bar.className = 'h-full bg-blue-500 transition-all'; bar.style.width = '100%';
                txt.textContent = 'Eligible'; txt.className = 'font-bold text-blue-600';
                amt.textContent = 'OK';
                msg.textContent = 'Client qualifies for this amount';
            }
        }

        document.getElementById('principal_amount').addEventListener('input', e => {
            let val = e.target.value.replace(/[^0-9]/g, '');
            e.target.value = val ? parseInt(val, 10).toLocaleString('en-US') : '';
            recalculateValues(val);
        });

        function initHistoryFilters() {
            const coSel = document.getElementById('history-co-filter');
            const uSel = document.getElementById('history-union-filter');
            
            coSel.onchange = e => {
                historyState.co = e.target.value;
                const unions = getUnions(historyState.co);
                uSel.innerHTML = '<option value="all">All Unions</option>';
                unions.forEach(u => uSel.innerHTML += `<option value="${u}">${u}</option>`);
                historyState.union = 'all';
                historyState.page = 1;
                loadHistory(true);
            };

            const unions = getUnions(historyState.co);
            uSel.innerHTML = '<option value="all">All Unions</option>';
            unions.forEach(u => uSel.innerHTML += `<option value="${u}">${u}</option>`);

            uSel.onchange = e => { historyState.union = e.target.value; historyState.page = 1; loadHistory(true); };
            
            document.querySelectorAll('.filter-date-btn').forEach(b => {
                b.onclick = () => {
                    document.querySelectorAll('.filter-date-btn').forEach(btn => btn.className = 'filter-date-btn text-xs px-3 py-1 text-gray-500 hover:bg-gray-100 dark:hover:bg-slate-700 rounded-lg transition');
                    b.className = 'filter-date-btn active text-xs px-3 py-1 bg-blue-100 text-blue-600 rounded-lg font-bold transition';
                    historyState.filter = b.dataset.val; historyState.page = 1; loadHistory(true);
                }
            });
        }

        async function loadHistory(reset) {
            const c = document.getElementById('history-container');
            const loadBtn = document.getElementById('loadMoreBtn');
            if (reset) {
                c.innerHTML = '<div class="text-center py-10 text-gray-400">Loading...</div>';
                historyState.page = 1;
                historyState.hasMore = true;
                loadBtn.classList.add('hidden');
            }
            if(historyState.loading || !historyState.hasMore) return;
            historyState.loading = true;
            
            const params = new URLSearchParams({ ajax: 1, page: historyState.page, co: historyState.co, union: historyState.union, filter: historyState.filter });
            try {
                const res = await fetch(`?${params}`);
                const data = await res.json();
                
                if(reset) c.innerHTML = '';

                if(data.entries.length === 0 && reset) {
                    c.innerHTML = '<div class="text-center py-10 flex flex-col items-center opacity-50"><i class="fas fa-inbox text-4xl mb-2"></i><span>No disbursements found</span></div>';
                } else {
                    data.entries.forEach(item => {
                        const html = `
                        <div class="activity-card">
                            <div class="ac-icon-box" style="background: ${ORANGE_COLOR}15; color: ${ORANGE_COLOR};">
                                <i class="fas fa-hand-holding-usd"></i>
                            </div>
                            <div class="ac-content min-w-0">
                                <div class="ac-top mb-1">
                                    <div class="client-name truncate pr-2">${item.client_name}</div>
                                    <div class="activity-amount" style="color: ${ORANGE_COLOR};">₦${parseInt(item.amount).toLocaleString()}</div>
                                </div>
                                <div class="ac-bottom">
                                    <span style="font-size:0.65rem; font-weight:700; background:${ORANGE_COLOR}15; color:${ORANGE_COLOR}; padding:2px 6px; border-radius:4px;">DISBURSEMENT</span>
                                    <div class="activity-date whitespace-nowrap"><i class="fas fa-user-tie"></i> ${item.officer} &bull; <i class="far fa-clock"></i> ${new Date(item.date).toLocaleString('en-US', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })}</div>
                                </div>
                            </div>
                        </div>`;
                        c.insertAdjacentHTML('beforeend', html);
                    });
                }
                historyState.hasMore = data.has_more;
                if(historyState.hasMore) { loadBtn.classList.remove('hidden'); loadBtn.onclick = () => { historyState.page++; loadHistory(false); }; } 
                else { loadBtn.classList.add('hidden'); }
            } catch(e) { console.error(e); c.innerHTML = '<div class="text-center py-10 text-red-500">Failed to load history.</div>'; } finally { historyState.loading = false; }
        }

        const drop = document.getElementById('dropZone');
        const fin = document.getElementById('fileInput');
        drop.onclick = () => fin.click();
        fin.onchange = () => {
            if(fin.files[0]) {
                const reader = new FileReader();
                reader.onload = e => { 
                    document.getElementById('previewImg').src = e.target.result;
                    document.getElementById('previewContainer').classList.remove('hidden');
                    document.getElementById('upload-placeholder').classList.add('hidden');
                };
                reader.readAsDataURL(fin.files[0]);
            }
        };
        document.getElementById('removeFile').onclick = (e) => {
            e.stopPropagation(); fin.value = '';
            document.getElementById('previewContainer').classList.add('hidden');
            document.getElementById('upload-placeholder').classList.remove('hidden');
        };

        document.getElementById('btn-set-now').onclick = () => {
            const isReadonly = currentActivePlan !== null ? currentActivePlan.disbursement_date_readonly : disbursementDateReadonly;
            if (!isReadonly) {
                const now = new Date();
                now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
                document.getElementById('date').value = now.toISOString().slice(0,16);
            }
        };

        document.getElementById('disbursementForm').onsubmit = async function(e) {
            e.preventDefault();
            const amountField = document.getElementById('principal_amount');
            const cleanValue = amountField.value.replace(/,/g, '');
            // Temporarily add a hidden input with the clean value for submission
            const hiddenInput = document.createElement('input');
            hiddenInput.type = 'hidden';
            hiddenInput.name = 'principal_amount';
            hiddenInput.value = cleanValue;
            this.appendChild(hiddenInput);
            
            const btn = document.getElementById('submitBtn');
            const originalText = btn.innerHTML;
            btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
            try {
                const res = await fetch(window.location.href, { method: 'POST', body: new FormData(this), headers: {'X-Requested-With': 'XMLHttpRequest'} });
                const data = await res.json();
                showToast(data.message, data.status);
                if(data.status === 'success') {
                    this.reset(); 
                    document.getElementById('removeFile').click(); 
                    hideCreditProfile(); 
                    loadHistory(true);
                    // Update dashboard stats without full page reload
                    document.querySelectorAll('.count-up').forEach(el => {
                       const target = el.dataset.target;
                       const isCurrency = el.dataset.currency;
                       if (target) {
                           const newTarget = isCurrency ? parseFloat(target) + parseFloat(cleanValue) : parseFloat(target) + 1;
                           el.dataset.target = newTarget;
                       }
                    });
                    animateNumbers();
                }
            } catch(err) { showToast('Network Error', 'error'); }
            finally { 
                btn.disabled = false; 
                btn.innerHTML = originalText;
                hiddenInput.remove(); // Clean up the hidden input
            }
        };

        function animateNumbers() {
            document.querySelectorAll('.count-up').forEach(el => {
                const target = parseFloat(el.dataset.target) || 0;
                const isCurrency = el.dataset.currency === 'true';
                let start = parseFloat(el.innerText.replace(/[^0-9.]/g, '')) || 0;
                let startTime = null;

                const animation = (currentTime) => {
                    if (startTime === null) startTime = currentTime;
                    const progress = Math.min((currentTime - startTime) / 1000, 1); // 1 second animation
                    const currentVal = Math.floor(progress * (target - start) + start);
                    
                    el.innerText = isCurrency ? currentVal.toLocaleString('en-US') : currentVal;

                    if (progress < 1) {
                        requestAnimationFrame(animation);
                    } else {
                        el.innerText = isCurrency ? target.toLocaleString('en-US') : target;
                    }
                };
                requestAnimationFrame(animation);
            });
        }
    </script>
</body>

</html>