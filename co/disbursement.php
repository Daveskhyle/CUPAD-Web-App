<?php
date_default_timezone_set('Africa/Lagos');
// co/disbursement.php - SQL Optimized & UI Fixed
session_start();

require_once '../includes/config.php';
$pdo = getDbConnection();

// --- 1. LOGIC SECTION ---

// The following section has been updated to fetch configuration
// from the new standardized settings tables (`disbursement_settings`, `loan_plans`, etc.)

// Default Settings (Fallback) - Kept for resilience
$default_settings = [
    'min_disbursement' => 20000,
    'max_disbursement' => 200000,
    'num_installments' => 23, 
    'weekly_installments' => 24, 
    'min_days_after_registration' => 3,
    'min_days_weekly_monthly' => 7,
    'max_first_loan_amount' => 20000, 
    'max_increment_amount' => 20000, 
    'savings_requirement_percentage' => 0.2,
    'interest_rate' => 0.15, 
    'weekly_interest_rate' => 0.20, 
    'disbursement_date_readonly' => false,
    'disbursement_picture_required' => true
];
$disbursement_settings = [];

// 1. Fetch from `disbursement_settings` table
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
    }
} catch (Exception $e) {
    error_log("DB Error fetching disbursement_settings: " . $e->getMessage());
}

// 2. Fetch from `app_settings` for other relevant settings
try {
    $stmt = $pdo->prepare("SELECT disbursement_picture_required FROM app_settings ORDER BY id DESC LIMIT 1");
    $stmt->execute();
    $app_settings = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($app_settings) {
        $disbursement_settings['disbursement_picture_required'] = (bool)$app_settings['disbursement_picture_required'];
    }
} catch (Exception $e) {
    error_log("DB Error fetching app_settings: " . $e->getMessage());
}

// Merge DB settings with defaults to ensure all keys exist
$disbursement_settings = array_merge($default_settings, $disbursement_settings);

// 3. Fetch Loan Plans from `loan_plans` table and merge with disbursement settings
$loan_plans = [];
$loan_terms = [];

// Helper: merge global disbursement settings with a plan record
function merge_plan_settings($plan) {
    global $disbursement_settings;
    $overrides = [
        'id' => $plan['id'] ?? null,
        'duration' => isset($plan['duration']) ? (int)$plan['duration'] : ($disbursement_settings['num_installments'] ?? 0),
        'installments' => isset($plan['installments']) ? (int)$plan['installments'] : (isset($plan['duration']) ? (int)$plan['duration'] : $disbursement_settings['num_installments']),
        'unit' => $plan['unit'] ?? ($disbursement_settings['unit'] ?? 'Days'),
        'interest_rate' => isset($plan['rate']) ? (float)$plan['rate'] : $disbursement_settings['interest_rate'],
        'weekly_interest_rate' => $disbursement_settings['weekly_interest_rate'] ?? null,
        'min_amount' => isset($plan['min_amount']) ? (float)$plan['min_amount'] : $disbursement_settings['min_disbursement'],
        'max_amount' => isset($plan['max_amount']) ? (float)$plan['max_amount'] : $disbursement_settings['max_disbursement'],
        'increment_amount' => isset($plan['increment_amount']) ? (float)$plan['increment_amount'] : $disbursement_settings['max_increment_amount'],
        'max_first_loan_amount' => isset($plan['max_first_loan_amount']) ? (float)$plan['max_first_loan_amount'] : $disbursement_settings['max_first_loan_amount'],
        'min_days_after_registration' => $plan['min_days_after_registration'] ?? $disbursement_settings['min_days_after_registration'],
        'min_days_weekly_monthly' => $plan['min_days_weekly_monthly'] ?? $disbursement_settings['min_days_weekly_monthly'] ?? 7,
        'disbursement_date_readonly' => isset($plan['disbursement_date_readonly']) ? (bool)$plan['disbursement_date_readonly'] : $disbursement_settings['disbursement_date_readonly'],
        'disbursement_picture_required' => isset($plan['disbursement_picture_required']) ? (bool)$plan['disbursement_picture_required'] : $disbursement_settings['disbursement_picture_required'],
        'savings_requirement_percentage' => $plan['savings_requirement_percentage'] ?? $disbursement_settings['savings_requirement_percentage']
    ];
    return array_merge($disbursement_settings, $overrides);
}

try {
    $stmt = $pdo->prepare("SELECT * FROM loan_plans");
    $stmt->execute();
    $all_plans_from_db = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($all_plans_from_db as $plan) {
        $merged = merge_plan_settings($plan);

        $loan_plans[$plan['id']] = [
            'id' => $merged['id'],
            'duration' => (int)$merged['duration'],
            'installments' => (int)$merged['installments'],
            'unit' => $merged['unit'],
            'rate' => (float)$merged['interest_rate'],
            'min_amount' => (float)$merged['min_amount'],
            'max_amount' => (float)$merged['max_amount'],
            'increment_amount' => (float)$merged['increment_amount'],
            'max_first_loan_amount' => (float)$merged['max_first_loan_amount'],
            'min_days_after_registration' => $merged['min_days_after_registration'],
            'min_days_weekly_monthly' => $merged['min_days_weekly_monthly'],
            'disbursement_date_readonly' => $merged['disbursement_date_readonly'],
            'disbursement_picture_required' => $merged['disbursement_picture_required'],
            'savings_requirement_percentage' => $merged['savings_requirement_percentage']
        ];

        $plan_key = strtolower($merged['duration'] . '_' . str_replace(' ', '_', $merged['unit']));
        $loan_terms[$plan_key] = [
            'installments' => (int)$merged['installments'],
            'duration' => (int)$merged['duration'],
            'interest_rate' => (float)$merged['interest_rate'],
            'min_amount' => (float)$merged['min_amount'],
            'max_amount' => (float)$merged['max_amount'],
            'increment_amount' => (float)$merged['increment_amount'],
            'max_first_loan_amount' => (float)$merged['max_first_loan_amount'],
            'unit' => $merged['unit'],
            'min_days_after_registration' => $merged['min_days_after_registration'],
            'min_days_weekly_monthly' => $merged['min_days_weekly_monthly'],
            'disbursement_date_readonly' => $merged['disbursement_date_readonly'],
            'disbursement_picture_required' => $merged['disbursement_picture_required'],
            'savings_requirement_percentage' => $merged['savings_requirement_percentage']
        ];
    }
} catch (Exception $e) {
     error_log("DB Error fetching loan_plans: " . $e->getMessage());
}

if (empty($loan_terms)) {
    $fallbacks = [
        ['key'=>'23_days','duration'=>23,'unit'=>'Days','interest_rate'=>0.15,'min_amount'=>20000,'max_amount'=>200000,'increment_amount'=>20000,'max_first_loan_amount'=>20000],
        ['key'=>'13_weeks','duration'=>13,'unit'=>'Weeks','interest_rate'=>0.20,'min_amount'=>100000,'max_amount'=>250000,'increment_amount'=>20000,'max_first_loan_amount'=>50000],
        ['key'=>'24_weeks','duration'=>24,'unit'=>'Weeks','interest_rate'=>0.25,'min_amount'=>150000,'max_amount'=>600000,'increment_amount'=>20000,'max_first_loan_amount'=>50000]
    ];
    foreach ($fallbacks as $f) {
        $merged = merge_plan_settings($f + ['id'=>$f['key']]);
        $loan_terms[$f['key']] = [
            'installments' => (int)$merged['installments'],
            'duration' => (int)$merged['duration'],
            'interest_rate' => (float)$merged['interest_rate'],
            'min_amount' => (float)$merged['min_amount'],
            'max_amount' => (float)$merged['max_amount'],
            'increment_amount' => (float)$merged['increment_amount'],
            'max_first_loan_amount' => (float)$merged['max_first_loan_amount'],
            'unit' => $merged['unit'],
            'min_days_after_registration' => $merged['min_days_after_registration'],
            'min_days_weekly_monthly' => $merged['min_days_weekly_monthly'],
            'disbursement_date_readonly' => $merged['disbursement_date_readonly'],
            'disbursement_picture_required' => $merged['disbursement_picture_required'],
            'savings_requirement_percentage' => $merged['savings_requirement_percentage']
        ];
    }
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

// 4. Fetch Messages
$messages = [];
try {
    $stmt = $pdo->prepare("SELECT message_key, message_text FROM disbursement_messages");
    $stmt->execute();
    $messages = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
} catch(Exception $e) {
    error_log("DB Error fetching disbursement_messages: " . $e->getMessage());
}
$default_messages = ['duplicate_detected' => 'Duplicate disbursement detected. Please wait.', 'invalid_input' => 'Please select a client and valid amount.'];
$messages = array_merge($default_messages, $messages);

// Auth
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'co') { header('Location: ../index.php'); exit(); }
$base_path = '../';
$co_username = $_SESSION['username'] ?? '';

// Load User Details
$stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
$stmt->execute([$co_username]);
$user_data = $stmt->fetch();

$co_branch_id = $user_data['branch_id'] ?? '';
$co_is_weekly = !empty($user_data['is_weekly']);
$profile_pic = $user_data['profile_pic'] ?? 'default_avatar.png';
$co_weekly_interest_rate = isset($user_data['weekly_interest_rate']) ? floatval($user_data['weekly_interest_rate']) : null;

// Adjust settings based on User
if ($co_is_weekly) {
    $disbursement_settings['num_installments'] = $disbursement_settings['weekly_installments'];
}
$effective_interest_rate = $co_is_weekly 
    ? ($co_weekly_interest_rate !== null ? $co_weekly_interest_rate : $disbursement_settings['weekly_interest_rate'])
    : $disbursement_settings['interest_rate'];

// Load Clients
$clients = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM clients WHERE officer_username = ? AND status = 'active' ORDER BY name ASC");
    $stmt->execute([$co_username]);
    $clients = $stmt->fetchAll();
} catch (PDOException $e) {
    $clients = [];
}

// Helpers
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
        $stmt = $pdo->prepare("SELECT * FROM clients WHERE id = ?");
        $stmt->execute([$client_id]);
        $client = $stmt->fetch();
        if (!$client) { echo json_encode(['status' => 'error', 'message' => 'Client not found']); exit; }

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
        
        $max_next = $disbursement_settings['max_disbursement'];

        if ($is_first_loan) {
            $max_next = $disbursement_settings['max_first_loan_amount'];
            if (!empty($client['client_type'])) {
                foreach ($loan_plans as $plan) {
                     $plan_name = ($plan['duration'] ?? 0) . ' ' . $plan['unit'];
                     if (stripos($client['client_type'], $plan_name) !== false) {
                         $max_next = $plan['max_first_loan_amount'];
                         break;
                     }
                }
            }
        } else {
            $plan_increment = $disbursement_settings['max_increment_amount'];
            $found_specific_plan = false;
            
            if (!empty($client['client_type'])) {
                foreach ($loan_plans as $plan) {
                    $p_name = ($plan['duration'] ?? '') . ' ' . ($plan['unit'] ?? '');
                    if (stripos($client['client_type'], $p_name) !== false && isset($plan['increment_amount'])) {
                        $plan_increment = $plan['increment_amount']; $found_specific_plan = true; break;
                    }
                }
            }
            if (!$found_specific_plan) {
                foreach ($loan_terms as $term) {
                    if ($max_previous >= $term['min_amount'] && $max_previous <= $term['max_amount']) {
                        if (isset($term['increment_amount'])) { $plan_increment = $term['increment_amount']; break; }
                    }
                }
            }
            $max_next = min($max_previous + $plan_increment, $disbursement_settings['max_disbursement']);
        }

        echo json_encode(['status' => 'success', 'data' => [
            'is_first_loan' => $is_first_loan, 
            'has_active_loan' => $has_active_loan, 
            'active_loan_balance' => $active_loan_balance,
            'max_next_loan' => $max_next, 
            'client_savings' => $client_savings, 
            'client_type' => $client['client_type'] ?? null
        ]]);
        exit;

    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
        exit;
    }
}

// --- AJAX: GET CLIENTS BY PLAN ---
if (isset($_GET['action']) && $_GET['action'] === 'get_clients_by_plan') {
    header('Content-Type: application/json');
    $plan_id = $_GET['plan_id'] ?? '';

    if (empty($plan_id)) {
        echo json_encode(['status' => 'error', 'message' => 'No plan ID provided.']);
        exit;
    }

    $plan_name = '';
    if (isset($loan_plans[$plan_id])) {
        $plan = $loan_plans[$plan_id];
        $plan_name = ($plan['duration'] ?? $plan['installments']) . ' ' . $plan['unit'];
    }

    if (empty($plan_name)) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid Plan ID specified.']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("SELECT id, name FROM clients WHERE officer_username = ? AND status = 'active' AND client_type = ? ORDER BY name ASC");
        $stmt->execute([$co_username, $plan_name]);
        $plan_clients = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['status' => 'success', 'clients' => $plan_clients]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'DB Error: ' . $e->getMessage()]);
    }
    exit;
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

    $stmt = $pdo->prepare("SELECT * FROM clients WHERE id = ?");
    $stmt->execute([$client_id]);
    $selected_client = $stmt->fetch();

    if (!$selected_client) {
        $message = 'Client not found.'; $allow_disbursement = false; $message_type = 'error';
    }

    if ($allow_disbursement) {
        $client_type = $selected_client['client_type'] ?? null;
        
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

        $effective_plan_settings = $disbursement_settings;
        if ($matched_plan && is_array($matched_plan)) {
            $effective_plan_settings = array_merge($disbursement_settings, $matched_plan);
        }

        $disbursement_date_readonly = $effective_plan_settings['disbursement_date_readonly'] ?? $disbursement_date_readonly;
        $disbursement_picture_required = $effective_plan_settings['disbursement_picture_required'] ?? $disbursement_picture_required;

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
            
            $min_days_req = $effective_plan_settings['min_days_after_registration'] ?? $disbursement_settings['min_days_after_registration'];
            
            $is_weekly_monthly = false;
            if ($matched_plan) {
                if (strpos(strtolower($matched_plan['unit']), 'week') !== false || strpos(strtolower($matched_plan['unit']), 'month') !== false) $is_weekly_monthly = true;
            } elseif ($co_is_weekly) { $is_weekly_monthly = true; }

            if ($is_weekly_monthly) $min_days_req = $effective_plan_settings['min_days_weekly_monthly'] ?? ($disbursement_settings['min_days_weekly_monthly'] ?? 7); 

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
                $req = $principal_amount * ($effective_plan_settings['savings_requirement_percentage'] ?? $disbursement_settings['savings_requirement_percentage']);
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
        $file_upload_attempted = isset($_FILES['disbursement_picture']) && $_FILES['disbursement_picture']['error'] !== UPLOAD_ERR_NO_FILE;

        if ($file_upload_attempted) {
            if ($_FILES['disbursement_picture']['error'] === UPLOAD_ERR_OK) {
                if ($_FILES['disbursement_picture']['size'] > 5242880) {
                    $message = 'Image is too heavy. Maximum size is 5MB.'; 
                    $allow_disbursement = false; 
                    $message_type = 'error';
                } else {
                    $new_name = preg_replace('/[^a-zA-Z0-9_-]/', '_', $client_id) . '_' . time() . '.' . pathinfo($_FILES['disbursement_picture']['name'], PATHINFO_EXTENSION);
                    if (move_uploaded_file($_FILES['disbursement_picture']['tmp_name'], $upload_dir . $new_name)) {
                        $pic_path = 'uploads/disbursements/' . $new_name;
                    } else {
                        $message = 'Server error: Failed to save the image.'; 
                        $allow_disbursement = false; 
                        $message_type = 'error';
                    }
                }
            } else {
                $message = 'Image upload failed. The file may be too heavy or corrupted.'; 
                $allow_disbursement = false; 
                $message_type = 'error';
            }
        } elseif ($disbursement_picture_required) {
            $message = 'Picture required.'; 
            $allow_disbursement = false; 
            $message_type = 'error';
        }

        if ($allow_disbursement) {
            $transaction_id = 'DSB-' . generate_uuid();
            $term_data = $matched_plan ?: [
                'interest_rate' => $effective_interest_rate, 
                'installments' => $disbursement_settings['num_installments'],
                'unit' => $co_is_weekly ? 'Weeks' : 'Days',
                'duration' => $co_is_weekly ? 24 : 23
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
                    $co_username, 
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

// Prepare JSON Response for POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json');
    echo json_encode(['status' => ($message_type === 'success' ? 'success' : 'error'), 'message' => $message ?? '']);
    exit;
}

// Load Dashboard Stats
$current_month = date('Y-m');
$stmt = $pdo->prepare("SELECT COUNT(*) as count, SUM(principal) as total FROM disbursements WHERE officer = ? AND DATE_FORMAT(date, '%Y-%m') = ?");
$stmt->execute([$co_username, $current_month]);
$stats = $stmt->fetch();
$total_disbursements = $stats['count'] ?? 0;
$total_amount_disbursed = $stats['total'] ?? 0;

$clients_by_union = [];
foreach ($clients as $client) {
    $u = $client['union'] ?? 'Unassigned';
    $clients_by_union[$u][] = $client;
}
$unique_assigned_unions = array_keys($clients_by_union);

// Ajax History Support
if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    header('Content-Type: application/json');
    $page = max(1, intval($_GET['page'] ?? 1));
    $limit = 10;
    $offset = ($page - 1) * $limit;
    $union_filter = $_GET['union'] ?? 'all';
    $date_filter = $_GET['filter'] ?? 'current_month';

    $where_clauses = ["officer = ?"];
    $params = [$co_username];

    if ($union_filter !== 'all') {
        $sql = "SELECT d.client_name, d.principal, d.date 
                FROM disbursements d 
                JOIN clients c ON d.client_id = c.id 
                WHERE d.officer = ? AND c.union = ?";
        $params[] = $union_filter;
    } else {
        $sql = "SELECT client_name, principal, date FROM disbursements WHERE officer = ?";
    }

    if ($date_filter === 'today') {
        $sql .= " AND DATE(date) = CURDATE()";
    } elseif ($date_filter === 'current_month') {
        $sql .= " AND date BETWEEN ? AND ?";
        $params[] = date('Y-m-01');
        $params[] = date('Y-m-t');
    }

    $sql .= " ORDER BY date DESC LIMIT $limit OFFSET $offset";
    
    if ($union_filter === 'all') {
         $sql = str_replace("d.", "", $sql);
    }

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $entries = [];
        foreach ($rows as $e) {
            $entries[] = [
                'type' => 'Disbursement',
                'client_name' => $e['client_name'],
                'amount' => floatval($e['principal']),
                'date' => $e['date']
            ];
        }
        echo json_encode(['status'=>'success', 'entries'=>$entries, 'has_more'=> count($entries) === $limit]);
    } catch (PDOException $e) {
        echo json_encode(['status'=>'error', 'message'=>$e->getMessage()]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#3b82f6">
    <title>Disbursement | CUPAD</title>
    
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
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
                }
            }
        }
    }
    </script>
    <style>
        /* Custom Scrollbar */
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        html.dark ::-webkit-scrollbar-thumb { background: #475569; }
        
        body { padding-bottom: 90px; -webkit-tap-highlight-color: transparent; font-family: 'Inter', sans-serif; }
        
        /* Hide scrollbar for horizontal scroll areas */
        .hide-scrollbar::-webkit-scrollbar { display: none; }
        .hide-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }

        /* Toast Animations */
        @keyframes slideInUp {
            from { transform: translateY(20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        #toast-container {
            position: fixed;
            bottom: 5.5rem;
            left: 50%;
            transform: translateX(-50%);
            z-index: 9999;
            width: max-content;
            max-width: 90vw;
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            pointer-events: none;
        }
        .toast {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            padding: 0.75rem 1rem;
            border-radius: 9999px;
            box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1), 0 4px 6px -4px rgba(0,0,0,0.1);
            display: flex;
            align-items: center;
            gap: 0.5rem;
            pointer-events: auto;
            animation: slideInUp 0.3s cubic-bezier(0.16, 1, 0.3, 1) forwards;
        }
        html.dark .toast {
            background: #1e293b;
            border-color: #334155;
            box-shadow: 0 10px 15px -3px rgba(0,0,0,0.5);
        }
        
        /* Input Group Styling */
        .input-group {
            position: relative;
            display: flex;
            align-items: center;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 0.75rem;
            padding: 0.875rem 1rem;
            transition: all 0.2s ease;
        }
        html.dark .input-group {
            background: #1e293b;
            border-color: #334155;
        }
        .input-group:focus-within {
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
            background: #ffffff;
        }
        html.dark .input-group:focus-within {
            background: #0f172a;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2);
        }
        
        .input-field {
            width: 100%;
            background: transparent;
            border: none;
            outline: none;
            color: inherit;
            font-size: 0.95rem;
        }
        .input-field:disabled { opacity: 0.6; cursor: not-allowed; }
        
        /* Mobile Bottom Nav */
        .mobile-bottom-nav {
            display: none;
            position: fixed;
            bottom: 0; left: 0; right: 0;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-top: 1px solid #e2e8f0;
            padding: 0.5rem 0.5rem calc(0.5rem + env(safe-area-inset-bottom));
            z-index: 100;
            justify-content: space-around;
            box-shadow: 0 -4px 10px rgba(0,0,0,0.03);
        }
        html.dark .mobile-bottom-nav {
            background: rgba(15, 23, 42, 0.95);
            border-top-color: #334155;
        }
        .nav-item {
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            text-decoration: none; color: #64748b; font-size: 0.65rem; font-weight: 600; gap: 4px; padding: 0.5rem; border-radius: 0.75rem; transition: all 0.2s;
        }
        html.dark .nav-item { color: #94a3b8; }
        .nav-item i { font-size: 1.25rem; }
        .nav-item.active { color: #3b82f6; background: #eff6ff; }
        html.dark .nav-item.active { color: #60a5fa; background: #1e3a8a; }

        .nav-item.center-btn {
            position: relative;
            top: -15px;
            background: transparent !important;
        }
        .center-btn-inner {
            background: linear-gradient(135deg, #f59e0b 0%, #ea580c 100%);
            width: 50px; height: 50px;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            color: white;
            box-shadow: 0 4px 15px rgba(245, 158, 11, 0.4);
            border: 4px solid #ffffff;
        }
        html.dark .center-btn-inner { border-color: #0f172a; }

        @media (max-width: 768px) {
            .mobile-bottom-nav { display: flex; }
        }
    </style>
</head>
<body class="antialiased bg-gray-50 dark:bg-slate-900 text-gray-900 dark:text-gray-100 transition-colors duration-200">

    <div id="toast-container"></div>

    <header class="sticky top-0 z-50 bg-white/80 dark:bg-slate-900/80 backdrop-blur-lg border-b border-gray-200 dark:border-slate-800">
        <div class="max-w-7xl mx-auto px-4 h-16 flex items-center justify-between">
            <a href="dashboard.php" class="flex items-center gap-2 no-underline group">
                <img src="<?php echo htmlspecialchars($base_path . 'favicon.ico'); ?>" alt="Logo" class="h-8 transition-transform group-hover:scale-110">
                <span class="text-xl font-black bg-clip-text text-transparent bg-gradient-to-r from-blue-600 to-blue-400 tracking-tight">CUPAD</span>
            </a>
            <div class="flex items-center gap-3">
                <button id="theme-toggle" class="w-10 h-10 flex items-center justify-center rounded-full bg-gray-100 dark:bg-slate-800 text-gray-500 dark:text-gray-400 hover:bg-gray-200 dark:hover:bg-slate-700 transition-colors">
                    <i class="fas fa-moon dark:hidden"></i>
                    <i class="fas fa-sun hidden dark:inline"></i>
                </button>
                <div class="w-10 h-10 rounded-full overflow-hidden border-2 border-blue-500/30 cursor-pointer hover:border-blue-500 transition-colors bg-gray-100 dark:bg-slate-800 flex items-center justify-center relative" onclick="window.location.href='profile.php'">
                    <i class="fas fa-user text-gray-400"></i>
                    <?php if ($profile_pic && $profile_pic !== 'default_avatar.png'): ?>
                        <img src="<?php echo $base_path . $profile_pic; ?>" class="absolute inset-0 w-full h-full object-cover" onerror="this.style.display='none'"> 
                    <?php endif; ?>
                </div>
                <a href="<?php echo htmlspecialchars($base_path . 'logout.php'); ?>" class="w-10 h-10 flex items-center justify-center rounded-full bg-red-50 dark:bg-red-900/20 text-red-500 hover:bg-red-100 dark:hover:bg-red-900/40 transition-colors">
                    <i class="fas fa-sign-out-alt"></i>
                </a>
            </div>
        </div>
    </header>

    <main class="max-w-7xl mx-auto px-4 py-6">
        
        <div class="mb-6 lg:mb-8 flex items-end justify-between">
            <div>
                <h1 class="text-3xl font-black text-gray-900 dark:text-white tracking-tight">Disbursements</h1>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Manage new loans and view recent activity</p>
            </div>
        </div>

        <!-- Carousel / Grid Stats -->
        <div class="flex overflow-x-auto hide-scrollbar snap-x snap-mandatory gap-4 lg:gap-6 mb-8 pb-4 -mx-4 px-4 md:mx-0 md:px-0">
            <!-- Stats Card 1 -->
            <div class="w-[85vw] sm:w-[350px] md:w-1/2 md:flex-1 shrink-0 snap-center relative overflow-hidden rounded-3xl bg-gradient-to-br from-amber-500 to-orange-600 p-6 text-white shadow-lg shadow-orange-500/20 transition-transform md:hover:-translate-y-1">
                <div class="absolute -right-6 -bottom-6 opacity-10">
                    <i class="fas fa-hand-holding-usd text-9xl"></i>
                </div>
                <div class="relative z-10 flex justify-between items-start">
                    <div>
                        <p class="text-amber-100 text-sm font-semibold uppercase tracking-wider mb-1">Monthly Disbursed</p>
                        <h2 class="text-4xl font-black mb-3 count-up" data-target="<?php echo $total_disbursements; ?>">0</h2>
                        <span class="inline-flex items-center gap-1.5 bg-black/20 backdrop-blur-sm px-3 py-1 rounded-full text-xs font-medium">
                            <i class="fas fa-calendar-alt"></i> This Month
                        </span>
                    </div>
                    <div class="w-12 h-12 bg-white/20 backdrop-blur-md rounded-2xl flex items-center justify-center text-xl shadow-inner">
                        <i class="fas fa-file-invoice"></i>
                    </div>
                </div>
            </div>

            <!-- Stats Card 2 -->
            <div class="w-[85vw] sm:w-[350px] md:w-1/2 md:flex-1 shrink-0 snap-center relative overflow-hidden rounded-3xl bg-gradient-to-br from-blue-500 to-indigo-600 p-6 text-white shadow-lg shadow-blue-500/20 transition-transform md:hover:-translate-y-1">
                <div class="absolute -right-6 -bottom-6 opacity-10">
                    <i class="fas fa-wallet text-9xl"></i>
                </div>
                <div class="relative z-10 flex justify-between items-start">
                    <div>
                        <p class="text-blue-100 text-sm font-semibold uppercase tracking-wider mb-1">Monthly Value</p>
                        <h2 class="text-4xl font-black mb-3">₦<span class="count-up" data-target="<?php echo $total_amount_disbursed; ?>" data-currency="true">0</span></h2>
                        <span class="inline-flex items-center gap-1.5 bg-black/20 backdrop-blur-sm px-3 py-1 rounded-full text-xs font-medium">
                            <i class="fas fa-coins"></i> Total Amount
                        </span>
                    </div>
                    <div class="w-12 h-12 bg-white/20 backdrop-blur-md rounded-2xl flex items-center justify-center text-xl shadow-inner">
                        <i class="fas fa-coins"></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8 items-start">
            
            <!-- FORM SECTION -->
            <div class="lg:col-span-1">
                <div class="bg-white dark:bg-slate-800 rounded-3xl shadow-sm border border-gray-200 dark:border-slate-700 sticky top-24 overflow-hidden">
                    <div class="p-5 border-b border-gray-100 dark:border-slate-700 bg-gray-50/50 dark:bg-slate-800/50">
                        <h2 class="text-lg font-bold flex items-center gap-2 text-gray-800 dark:text-white">
                            <div class="w-8 h-8 rounded-full bg-blue-100 dark:bg-blue-900/50 text-blue-600 flex items-center justify-center">
                                <i class="fas fa-plus"></i>
                            </div>
                            New Disbursement
                        </h2>
                    </div>

                    <form id="disbursementForm" class="p-6 space-y-6" enctype="multipart/form-data">
                        
                        <!-- Union Filter -->
                        <div class="space-y-2">
                            <label class="text-xs font-bold text-gray-500 uppercase tracking-wider block">Union Filter</label>
                            <div id="union-tags" class="flex flex-nowrap overflow-x-auto gap-2 pb-1 hide-scrollbar"></div>
                        </div>

                        <!-- Client Select -->
                        <div class="space-y-2">
                            <label class="text-xs font-bold text-gray-500 uppercase tracking-wider block">Client</label>
                            <div class="input-group group">
                                <i class="fas fa-user text-gray-400 group-focus-within:text-blue-500 transition-colors mr-3"></i>
                                <select id="client" name="client" required class="input-field appearance-none cursor-pointer text-gray-700 dark:text-gray-200 bg-transparent">
                                    <option value="" class="bg-white dark:bg-slate-800">Select Client...</option>
                                </select>
                                <i class="fas fa-chevron-down text-xs text-gray-400 pointer-events-none absolute right-4"></i>
                            </div>
                        </div>

                        <!-- Credit Profile Card -->
                        <div id="credit-profile-card" class="hidden rounded-2xl border border-gray-200 dark:border-slate-700 overflow-hidden shadow-sm transition-all duration-300 bg-white dark:bg-slate-800">
                            <div id="profile-header" class="px-4 py-3 text-sm font-bold text-white bg-slate-500 flex justify-between items-center transition-colors">
                                <span class="flex items-center gap-2"><i class="fas fa-user-circle text-lg"></i> Client Overview</span>
                                <span id="profile-status-badge" class="bg-black/20 px-2.5 py-1 rounded-full text-[10px] uppercase tracking-wider backdrop-blur-sm">Checking...</span>
                            </div>
                            <div class="p-4 bg-gray-50 dark:bg-slate-900/50 grid grid-cols-2 gap-4">
                                <div class="bg-white dark:bg-slate-800 p-3 rounded-xl border border-gray-100 dark:border-slate-700 shadow-sm">
                                    <span class="text-[10px] text-gray-400 font-bold uppercase tracking-wider block mb-1">Savings Balance</span>
                                    <div id="profile-savings" class="font-extrabold text-gray-800 dark:text-white text-lg">₦0</div>
                                </div>
                                <div class="bg-white dark:bg-slate-800 p-3 rounded-xl border border-gray-100 dark:border-slate-700 shadow-sm text-right">
                                    <span class="text-[10px] text-gray-400 font-bold uppercase tracking-wider block mb-1">Available Limit</span>
                                    <div id="profile-limit" class="font-extrabold text-blue-600 dark:text-blue-400 text-lg">₦0</div>
                                </div>
                            </div>
                            <!-- Assigned Plan Info Bar -->
                            <div id="profile-plan-info" class="px-4 py-3 bg-blue-50 dark:bg-blue-900/20 text-blue-700 dark:text-blue-300 text-xs font-bold border-t border-blue-100 dark:border-blue-800/50 hidden flex items-center justify-between">
                                <span class="flex items-center gap-2"><i class="fas fa-tag"></i> Assigned Plan</span>
                                <span id="profile-plan-type" class="bg-blue-100 dark:bg-blue-800/80 px-2 py-0.5 rounded text-blue-800 dark:text-blue-200">Standard</span>
                            </div>
                            <!-- Active Loan Warning -->
                            <div id="profile-active-loan" class="hidden px-4 py-3 bg-red-50 dark:bg-red-900/20 text-red-600 dark:text-red-400 text-xs font-bold border-t border-red-100 dark:border-red-800/50 flex items-center justify-between">
                                <span class="flex items-center gap-2"><i class="fas fa-exclamation-triangle"></i> Active Loan Balance</span>
                                <span id="profile-balance" class="font-extrabold">₦0</span>
                            </div>
                        </div>

                        <!-- Available Loan Plans -->
                        <div id="plans-details-card" class="hidden rounded-2xl border border-gray-200 dark:border-slate-700 overflow-hidden shadow-sm transition-all duration-300">
                            <div class="px-4 py-3 text-xs font-bold text-white bg-blue-600 flex items-center gap-2">
                                <i class="fas fa-layer-group"></i> Available Loan Plans
                            </div>
                            <div id="plans-list" class="divide-y divide-gray-100 dark:divide-slate-700 bg-white dark:bg-slate-800"></div>
                        </div>

                        <!-- Amount Input -->
                        <div class="space-y-2">
                            <label class="text-xs font-bold text-gray-500 uppercase tracking-wider block">Principal Amount</label>
                            <div class="relative bg-gray-50 dark:bg-slate-800/50 rounded-2xl border border-gray-200 dark:border-slate-700 p-4 focus-within:border-blue-500 focus-within:ring-4 focus-within:ring-blue-500/10 transition-all">
                                <div class="flex items-center">
                                    <span class="text-gray-400 text-2xl font-bold mr-2">₦</span>
                                    <input type="text" id="principal_amount" name="principal_amount" class="bg-transparent border-none outline-none text-3xl font-black text-gray-900 dark:text-white w-full p-0 tracking-tight placeholder-gray-300 dark:placeholder-gray-600" placeholder="0" disabled>
                                </div>
                            </div>
                            <!-- Savings Validation Indicator -->
                            <div id="savings-validation" class="hidden mt-3 p-3 rounded-xl bg-gray-50 dark:bg-slate-800/50 border border-gray-100 dark:border-slate-700">
                                <div class="flex justify-between mb-1.5 items-end">
                                    <span id="validation-text" class="text-xs font-bold uppercase tracking-wider">Requirement</span>
                                    <span id="validation-amount" class="text-sm font-black">0</span>
                                </div>
                                <div class="h-2 w-full bg-gray-200 dark:bg-slate-700 rounded-full overflow-hidden">
                                    <div id="validation-bar" class="h-full bg-blue-500 transition-all duration-500 ease-out" style="width:0%"></div>
                                </div>
                                <p id="validation-msg" class="mt-1.5 text-[10px] text-gray-500 dark:text-gray-400 font-medium"></p>
                            </div>
                        </div>

                        <!-- 3-Column Stats -->
                        <div class="grid grid-cols-3 gap-3">
                            <div class="bg-gray-50 dark:bg-slate-800/50 rounded-xl p-3 flex flex-col justify-center items-center border border-gray-100 dark:border-slate-700 shadow-sm">
                                <span class="text-[10px] text-gray-400 font-bold uppercase tracking-wider mb-1">Duration</span>
                                <span id="duration-display" class="font-bold text-gray-800 dark:text-gray-200 text-sm">23 Days</span>
                            </div>
                            <div class="bg-gray-50 dark:bg-slate-800/50 rounded-xl p-3 flex flex-col justify-center items-center border border-gray-100 dark:border-slate-700 shadow-sm">
                                <span class="text-[10px] text-gray-400 font-bold uppercase tracking-wider mb-1">Total Pay</span>
                                <input type="text" id="total-payable" readonly class="bg-transparent font-bold text-gray-800 dark:text-gray-200 text-sm w-full text-center outline-none cursor-default" value="₦0">
                            </div>
                            <div class="bg-blue-50 dark:bg-blue-900/20 rounded-xl p-3 flex flex-col justify-center items-center border border-blue-100 dark:border-blue-800/50 shadow-sm">
                                <span class="text-[10px] text-blue-500 dark:text-blue-400 font-bold uppercase tracking-wider mb-1">Installment</span>
                                <input type="text" id="installment-amount" readonly class="bg-transparent font-black text-blue-700 dark:text-blue-300 text-sm w-full text-center outline-none cursor-default" value="N/A">
                            </div>
                        </div>

                        <?php if (!empty($settings['show_remaining_balance'])): ?>
                        <div class="space-y-2">
                            <label class="text-xs font-bold text-gray-500 uppercase tracking-wider block">Balance Override</label>
                            <div class="input-group">
                                <input type="number" name="remaining_balance" class="input-field" placeholder="Auto-calculated if empty">
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Date Selection -->
                        <div class="space-y-2">
                            <div class="flex justify-between items-center">
                                <label class="text-xs font-bold text-gray-500 uppercase tracking-wider block">Date</label>
                                <button type="button" id="btn-set-now" class="text-[10px] font-bold text-blue-600 bg-blue-50 hover:bg-blue-100 dark:text-blue-400 dark:bg-blue-900/30 dark:hover:bg-blue-900/50 px-2.5 py-1 rounded-md transition-colors">SET NOW</button>
                            </div>
                            <div class="input-group">
                                <i class="far fa-calendar-alt text-gray-400 mr-3"></i>
                                <input type="datetime-local" id="date" name="date" value="<?php echo date('Y-m-d\TH:i'); ?>" <?php echo $disbursement_date_readonly ? 'readonly' : ''; ?> class="input-field appearance-none">
                            </div>
                        </div>

                        <!-- Image Upload -->
                        <div class="space-y-2">
                            <label class="text-xs font-bold text-gray-500 uppercase tracking-wider block">Proof Image</label>
                            <input type="file" name="disbursement_picture" id="fileInput" accept="image/*" class="hidden">
                            <div id="dropZone" class="border-2 border-dashed border-gray-300 dark:border-slate-600 rounded-2xl py-6 px-4 text-center cursor-pointer hover:bg-blue-50 dark:hover:bg-slate-800/50 hover:border-blue-300 dark:hover:border-slate-500 transition-colors group">
                                <div id="upload-placeholder" class="flex flex-col items-center justify-center space-y-2">
                                    <div class="w-12 h-12 bg-blue-100 dark:bg-slate-700 rounded-full flex items-center justify-center group-hover:scale-110 transition-transform">
                                        <i class="fas fa-camera text-xl text-blue-500 dark:text-blue-400"></i>
                                    </div>
                                    <div>
                                        <p class="text-sm font-semibold text-gray-700 dark:text-gray-300">Tap to upload proof</p>
                                        <p class="text-[10px] text-gray-500 dark:text-gray-400 mt-1 uppercase tracking-wider">Max size 5MB</p>
                                    </div>
                                </div>
                                <div id="previewContainer" class="hidden relative inline-block mt-2">
                                    <img id="previewImg" class="h-28 w-auto rounded-lg border-2 border-white dark:border-slate-800 shadow-md object-cover">
                                    <button type="button" id="removeFile" class="absolute -top-3 -right-3 bg-red-500 hover:bg-red-600 text-white rounded-full w-7 h-7 flex items-center justify-center shadow-lg transition-transform hover:scale-110">
                                        <i class="fas fa-times text-sm"></i>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <button type="submit" id="submitBtn" class="w-full py-4 bg-blue-600 hover:bg-blue-700 text-white rounded-xl font-bold shadow-lg shadow-blue-500/30 transition-all transform active:scale-[0.98] flex justify-center items-center gap-2">
                            <span>Confirm Disbursement</span>
                            <i class="fas fa-arrow-right"></i>
                        </button>
                    </form>
                </div>
            </div>

            <!-- HISTORY FEED SECTION -->
            <div class="lg:col-span-2">
                <div class="bg-white dark:bg-slate-800 rounded-3xl shadow-sm border border-gray-200 dark:border-slate-700 flex flex-col overflow-hidden h-full min-h-[500px]">
                    
                    <div class="p-5 border-b border-gray-100 dark:border-slate-700 bg-gray-50/50 dark:bg-slate-800/50 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
                        <h2 class="text-lg font-bold text-gray-800 dark:text-white flex items-center gap-2">
                            <i class="fas fa-history text-gray-400"></i> Recent Activity
                        </h2>
                        <div class="flex flex-wrap items-center gap-2">
                            <select id="history-union-filter" class="text-xs bg-white dark:bg-slate-700 border border-gray-200 dark:border-slate-600 rounded-lg px-3 py-1.5 outline-none font-medium text-gray-700 dark:text-gray-300 shadow-sm cursor-pointer">
                                <option value="all">All Unions</option>
                            </select>
                            <div class="flex bg-gray-100 dark:bg-slate-700 p-1 rounded-lg">
                                <button class="filter-date-btn text-xs px-3 py-1 text-gray-500 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white rounded-md transition-all" data-val="all">All</button>
                                <button class="filter-date-btn active text-xs px-3 py-1 bg-white dark:bg-slate-600 text-blue-600 dark:text-blue-400 shadow-sm rounded-md font-bold transition-all" data-val="current_month">This Month</button>
                                <button class="filter-date-btn text-xs px-3 py-1 text-gray-500 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white rounded-md transition-all" data-val="today">Today</button>
                            </div>
                        </div>
                    </div>

                    <div id="history-container" class="flex-1 overflow-y-auto p-2">
                        <!-- Content injected here -->
                    </div>
                    
                    <div class="p-4 border-t border-gray-100 dark:border-slate-700 text-center bg-gray-50/50 dark:bg-slate-800/50">
                        <button id="loadMoreBtn" class="text-sm font-bold text-blue-500 hover:text-blue-600 dark:hover:text-blue-400 hidden transition-colors flex items-center justify-center gap-2 mx-auto">
                            <span>Load More</span> <i class="fas fa-chevron-down text-xs"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <nav class="mobile-bottom-nav">
        <a href="dashboard.php" class="nav-item">
            <i class="fas fa-home"></i><span>Home</span>
        </a>
        <a href="saving_collection.php" class="nav-item">
            <i class="fas fa-piggy-bank"></i><span>Save</span>
        </a>
        <a href="disbursement.php" class="nav-item active center-btn">
             <div class="center-btn-inner">
                <i class="fas fa-hand-holding-usd text-xl"></i>
             </div>
             <span class="text-orange-500 font-bold mt-1">Disburse</span>
        </a>
        <a href="loan_collection.php" class="nav-item">
            <i class="fas fa-money-bill-wave"></i><span>Repay</span>
        </a>
        <a href="clients.php" class="nav-item">
            <i class="fas fa-users"></i><span>Clients</span>
        </a>
    </nav>

   <script>
        const clientsByUnion = <?php echo json_encode($clients_by_union); ?>;
        const settings = <?php echo json_encode($disbursement_settings); ?>;
        const loanTerms = <?php echo json_encode($loan_terms); ?>;
        const defaultRate = <?php echo $effective_interest_rate; ?>;
        const uniqueUnions = <?php echo json_encode($unique_assigned_unions); ?>;
        const serverMsg = <?php echo $message ? json_encode(['text' => $message, 'type' => $message_type]) : 'null'; ?>;
        const loanPlans = <?php echo json_encode($loan_plans); ?>; 
        const disbursementDateReadonly = <?php echo $disbursement_date_readonly ? 'true' : 'false'; ?>;
        
        let historyState = { page: 1, union: 'all', filter: 'current_month', loading: false, hasMore: true };
        let currentClientData = null;
        let currentActivePlan = null; 

        const formatMoney = amt => '₦' + Math.round(amt).toLocaleString('en-US');

        function showToast(msg, type) {
            const el = document.createElement('div');
            el.className = 'toast';
            const isSuccess = type === 'success';
            const iconColor = isSuccess ? 'text-green-500' : 'text-red-500';
            const iconClass = isSuccess ? 'fa-check-circle' : 'fa-exclamation-circle';
            
            el.innerHTML = `
                <i class="fas ${iconClass} ${iconColor} text-lg"></i>
                <span class="text-sm font-semibold text-gray-800 dark:text-gray-200 mr-2">${msg}</span>
            `;
            
            const container = document.getElementById('toast-container');
            container.appendChild(el);
            
            setTimeout(() => { 
                el.style.transform = 'translateY(20px)'; 
                el.style.opacity = '0'; 
                el.style.transition = 'all 0.3s ease-in';
                setTimeout(() => el.remove(), 300); 
            }, 3500);
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

        function initUnionPills() {
            const c = document.getElementById('union-tags');
            if(!uniqueUnions.length) { c.innerHTML = '<span class="text-sm italic text-gray-400 py-2">No unions assigned</span>'; return; }
            uniqueUnions.forEach((u, i) => {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = `px-4 py-1.5 rounded-full text-xs font-bold transition-all whitespace-nowrap ${i===0 ? 'bg-orange-500 text-white shadow-md shadow-orange-500/20' : 'bg-white text-gray-600 border border-gray-200 hover:bg-gray-50 dark:bg-slate-800 dark:text-gray-300 dark:border-slate-700 dark:hover:bg-slate-700'}`;
                btn.textContent = u;
                btn.onclick = () => {
                    Array.from(c.children).forEach(b => b.className = 'px-4 py-1.5 rounded-full text-xs font-bold transition-all whitespace-nowrap bg-white text-gray-600 border border-gray-200 hover:bg-gray-50 dark:bg-slate-800 dark:text-gray-300 dark:border-slate-700 dark:hover:bg-slate-700');
                    btn.className = 'px-4 py-1.5 rounded-full text-xs font-bold transition-all whitespace-nowrap bg-orange-500 text-white shadow-md shadow-orange-500/20';
                    populateClients(u);
                };
                c.appendChild(btn);
                if(i===0) populateClients(u);
            });
        }

        function populateClients(u) {
            const s = document.getElementById('client');
            s.innerHTML = '<option value="" class="bg-white dark:bg-slate-800">Select Client...</option>';
            if(clientsByUnion[u]) clientsByUnion[u].forEach(c => s.innerHTML += `<option value="${c.id}" class="bg-white dark:bg-slate-800">${c.name}</option>`);
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
                for (const [planKey, planData] of Object.entries(loanTerms)) {
                    const planName = planKey.replace(/_/g, ' ');
                    if (typeLower.includes(planName)) {
                        matchedPlan = planData;
                        matchedKey = planKey;
                        break;
                    }
                }
            }

            if (!matchedPlan) {
                let matchingPlans = [];
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
            const interestPercent = Math.round(matchedPlan.interest_rate * 100);
            const minAmount = `₦${(matchedPlan.min_amount/1000).toFixed(0)}k`;
            const maxAmount = `₦${(matchedPlan.max_amount/1000).toFixed(0)}k`;
            const maxFirstLoan = `₦${(matchedPlan.max_first_loan_amount/1000).toFixed(0)}k`;
            
            let repaymentLabel = planUnit === 'Weeks' ? 'Weekly Repayment' : (planUnit === 'Months' ? 'Monthly Repayment' : 'Daily Repayment');
            
            plansContainer.innerHTML = `
                <div class="p-5 bg-gradient-to-br from-blue-50 to-white dark:from-slate-800 dark:to-slate-900 border-l-4 border-blue-500">
                    <div class="flex items-center justify-between mb-4">
                        <div>
                            <h3 class="text-sm font-extrabold text-gray-800 dark:text-white uppercase tracking-wider">${planName}</h3>
                            <div class="text-[11px] text-gray-500 dark:text-gray-400 mt-1 font-medium">
                                <i class="far fa-clock mr-1"></i> ${planDuration} ${planUnit} Duration
                            </div>
                        </div>
                        <div class="flex flex-col items-end gap-1.5">
                            ${currentClientData.is_first_loan ? `<span class="text-[10px] font-bold text-amber-800 bg-amber-100 dark:bg-amber-900/50 dark:text-amber-300 px-2.5 py-0.5 rounded-full border border-amber-200 dark:border-amber-700 shadow-sm">First Loan</span>` : ''}
                            <span class="text-[10px] font-bold text-blue-700 bg-blue-100 dark:bg-blue-900/50 dark:text-blue-300 px-2.5 py-0.5 rounded-full border border-blue-200 dark:border-blue-800 shadow-sm"><i class="fas fa-percent mr-1 text-[8px]"></i>${interestPercent}% Interest</span>
                        </div>
                    </div>

                    <div class="grid grid-cols-3 gap-3 mb-4">
                        <div class="bg-white dark:bg-slate-700 p-2.5 rounded-xl border border-gray-100 dark:border-slate-600 flex flex-col items-center justify-center shadow-sm">
                            <i class="fas fa-arrow-down text-red-500 mb-1"></i>
                            <div class="text-[9px] text-gray-400 uppercase font-bold tracking-wider">Min</div>
                            <div class="text-xs font-bold text-gray-800 dark:text-gray-200 mt-0.5">${minAmount}</div>
                        </div>
                        <div class="bg-white dark:bg-slate-700 p-2.5 rounded-xl border border-gray-100 dark:border-slate-600 flex flex-col items-center justify-center shadow-sm">
                            <i class="fas fa-arrow-up text-green-500 mb-1"></i>
                            <div class="text-[9px] text-gray-400 uppercase font-bold tracking-wider">Max</div>
                            <div class="text-xs font-bold text-gray-800 dark:text-gray-200 mt-0.5">${maxAmount}</div>
                        </div>
                        <div class="bg-white dark:bg-slate-700 p-2.5 rounded-xl border border-gray-100 dark:border-slate-600 flex flex-col items-center justify-center shadow-sm">
                            <i class="fas fa-star text-purple-500 mb-1"></i>
                            <div class="text-[9px] text-gray-400 uppercase font-bold tracking-wider">1st Limit</div>
                            <div class="text-xs font-bold text-gray-800 dark:text-gray-200 mt-0.5">${maxFirstLoan}</div>
                        </div>
                    </div>

                    <div class="flex justify-between items-center pt-3 border-t border-gray-200 dark:border-slate-700">
                        <span class="text-xs font-semibold text-gray-500 dark:text-gray-400 flex items-center gap-1.5">
                            <i class="fas fa-calculator text-orange-500"></i> Est. ${repaymentLabel}
                        </span>
                        <strong class="text-xl text-orange-600 font-black" id="est-repayment-val">N/A</strong>
                    </div>
                </div>`;
            
            plansCard.classList.remove('hidden');
            return matchedPlan;
        }

        document.getElementById('client').addEventListener('change', e => {
            const id = e.target.value;
            if(!id) { hideCreditProfile(); return; }
            
            const badge = document.getElementById('profile-status-badge');
            if(badge) {
                document.getElementById('profile-header').className = 'px-4 py-3 text-sm font-bold text-white bg-slate-500 flex justify-between items-center transition-colors';
                badge.className = 'bg-black/20 px-2.5 py-1 rounded-full text-[10px] uppercase tracking-wider backdrop-blur-sm'; 
                badge.textContent = 'Checking...';
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
            const planInfo = document.getElementById('profile-plan-info');
            const planType = document.getElementById('profile-plan-type');
            
            document.getElementById('profile-savings').textContent = formatMoney(currentClientData.client_savings);
            
            currentActivePlan = displayClientPlan(); 
            
            let displayMaxLoan = currentClientData.max_next_loan;
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
            } else {
                document.getElementById('duration-display').textContent = settings.num_installments + (settings.num_installments === 24 ? ' Weeks' : ' Days');
            }

            if (currentClientData.client_type) {
                planInfo.classList.remove('hidden');
                let rateText = currentActivePlan ? ` (${Math.round(currentActivePlan.interest_rate*100)}%)` : '';
                planType.textContent = currentClientData.client_type + rateText;
            } else {
                planInfo.classList.add('hidden');
                planType.textContent = 'Standard';
            }

            if(currentClientData.has_active_loan) {
                head.className = 'px-4 py-3 text-sm font-bold text-white bg-gradient-to-r from-red-600 to-red-500 flex justify-between items-center transition-colors';
                badge.textContent = 'Ineligible';
                document.getElementById('profile-active-loan').classList.remove('hidden');
                document.getElementById('profile-balance').textContent = formatMoney(currentClientData.active_loan_balance);
                inp.disabled = true;
            } else {
                head.className = 'px-4 py-3 text-sm font-bold text-white bg-gradient-to-r from-green-600 to-emerald-500 flex justify-between items-center transition-colors';
                badge.textContent = 'Eligible';
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
            const req = currentClientData.is_first_loan ? 0 : (val * settings.savings_requirement_percentage);
            
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
                total = Math.round(val * (1 + defaultRate));
                inst = Math.round(total / settings.num_installments);
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
                bar.className = 'h-full bg-red-500 transition-all duration-500 ease-out'; bar.style.width = '100%';
                txt.textContent = 'Limit Exceeded'; txt.className = 'text-xs font-bold uppercase tracking-wider text-red-600 dark:text-red-400';
                amt.textContent = formatMoney(maxAllow);
                msg.textContent = `Max allow is ${formatMoney(maxAllow)}`;
            } else if(val < minAllow) {
                bar.className = 'h-full bg-red-500 transition-all duration-500 ease-out'; bar.style.width = '100%';
                txt.textContent = 'Below Minimum'; txt.className = 'text-xs font-bold uppercase tracking-wider text-red-600 dark:text-red-400';
                amt.textContent = formatMoney(minAllow);
                msg.textContent = `Minimum required is ${formatMoney(minAllow)}`;
            } else if(!currentClientData.is_first_loan && savings < req) {
                const pct = req > 0 ? Math.min(100, (savings/req)*100) : 100;
                bar.className = 'h-full bg-orange-500 transition-all duration-500 ease-out'; bar.style.width = pct+'%';
                txt.textContent = 'Savings Required'; txt.className = 'text-xs font-bold uppercase tracking-wider text-orange-600 dark:text-orange-400';
                amt.textContent = formatMoney(req);
                msg.textContent = req > savings ? `Short by ${formatMoney(req-savings)}` : 'Requirement met';
            } else {
                bar.className = 'h-full bg-green-500 transition-all duration-500 ease-out'; bar.style.width = '100%';
                txt.textContent = 'Eligible'; txt.className = 'text-xs font-bold uppercase tracking-wider text-green-600 dark:text-green-400';
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
            const s = document.getElementById('history-union-filter');
            uniqueUnions.forEach(u => s.innerHTML += `<option value="${u}">${u}</option>`);
            s.onchange = e => { historyState.union = e.target.value; historyState.page = 1; loadHistory(true); };
            
            document.querySelectorAll('.filter-date-btn').forEach(b => {
                b.onclick = () => {
                    document.querySelectorAll('.filter-date-btn').forEach(btn => {
                        btn.className = 'filter-date-btn text-xs px-3 py-1 text-gray-500 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white rounded-md transition-all';
                    });
                    b.className = 'filter-date-btn active text-xs px-3 py-1 bg-white dark:bg-slate-600 text-blue-600 dark:text-blue-400 shadow-sm rounded-md font-bold transition-all';
                    historyState.filter = b.dataset.val; 
                    historyState.page = 1; 
                    loadHistory(true);
                }
            });
        }

        async function loadHistory(reset) {
            const c = document.getElementById('history-container');
            const loadBtn = document.getElementById('loadMoreBtn');
            if (reset) {
                c.innerHTML = `
                    <div class="flex flex-col justify-center items-center h-48">
                        <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600 mb-3"></div>
                        <p class="text-sm text-gray-500">Loading history...</p>
                    </div>`;
                historyState.page = 1;
                historyState.hasMore = true;
                loadBtn.classList.add('hidden');
            }
            if(historyState.loading || !historyState.hasMore) return;
            historyState.loading = true;
            
            const params = new URLSearchParams({ ajax: 1, page: historyState.page, union: historyState.union, filter: historyState.filter });
            try {
                const res = await fetch(`?${params}`);
                const data = await res.json();
                
                if(reset) c.innerHTML = '';

                if(data.entries.length === 0 && reset) {
                    c.innerHTML = `
                        <div class="flex flex-col justify-center items-center h-64 text-gray-400">
                            <div class="w-16 h-16 bg-gray-100 dark:bg-slate-800 rounded-full flex items-center justify-center mb-4">
                                <i class="fas fa-inbox text-2xl text-gray-400 dark:text-gray-500"></i>
                            </div>
                            <p class="font-medium text-gray-500 dark:text-gray-400">No disbursements found</p>
                            <p class="text-xs mt-1 text-gray-400">Try changing the filter criteria</p>
                        </div>`;
                } else {
                    data.entries.forEach(item => {
                        const dateStr = new Date(item.date).toLocaleString('en-US', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
                        const html = `
                        <div class="flex items-center p-4 hover:bg-gray-50 dark:hover:bg-slate-700/50 transition-colors border-b border-gray-100 dark:border-slate-700/50 last:border-0 m-2 rounded-xl">
                            <div class="w-12 h-12 rounded-full bg-orange-100 dark:bg-orange-900/30 flex items-center justify-center text-orange-600 dark:text-orange-400 mr-4 shrink-0 shadow-inner">
                                <i class="fas fa-hand-holding-usd text-lg"></i>
                            </div>
                            <div class="flex-1">
                                <div class="flex justify-between items-start">
                                    <h4 class="font-bold text-sm text-gray-900 dark:text-white">${item.client_name}</h4>
                                    <span class="font-black text-sm text-gray-900 dark:text-white">₦${parseInt(item.amount).toLocaleString()}</span>
                                </div>
                                <div class="flex justify-between items-center mt-1.5">
                                    <span class="text-[9px] font-bold bg-orange-100 text-orange-700 dark:bg-orange-900/40 dark:text-orange-400 px-2 py-0.5 rounded uppercase tracking-wider">Disbursement</span>
                                    <span class="text-xs font-medium text-gray-500 dark:text-gray-400"><i class="far fa-clock mr-1 opacity-70"></i>${dateStr}</span>
                                </div>
                            </div>
                        </div>`;
                        c.insertAdjacentHTML('beforeend', html);
                    });
                }
                historyState.hasMore = data.has_more;
                if(historyState.hasMore) { 
                    loadBtn.classList.remove('hidden'); 
                    loadBtn.onclick = () => { historyState.page++; loadHistory(false); }; 
                } else { 
                    loadBtn.classList.add('hidden'); 
                }
            } catch(e) { 
                console.error(e); 
                c.innerHTML = '<div class="text-center py-10 text-red-500 font-medium">Failed to load history.</div>'; 
            } finally { 
                historyState.loading = false; 
            }
        }

        const drop = document.getElementById('dropZone');
        const fin = document.getElementById('fileInput');
        drop.onclick = () => fin.click();
        
        fin.onchange = () => {
            if(fin.files[0]) {
                if (fin.files[0].size > 5 * 1024 * 1024) {
                    showToast('File is too heavy. Max size is 5MB.', 'error');
                    fin.value = ''; 
                    return;
                }

                const reader = new FileReader();
                reader.onload = e => { 
                    document.getElementById('previewImg').src = e.target.result;
                    document.getElementById('previewContainer').classList.remove('hidden');
                    document.getElementById('upload-placeholder').classList.add('hidden');
                    drop.classList.remove('py-6');
                    drop.classList.add('py-4');
                };
                reader.readAsDataURL(fin.files[0]);
            }
        };
        
        document.getElementById('removeFile').onclick = (e) => {
            e.stopPropagation(); fin.value = '';
            document.getElementById('previewContainer').classList.add('hidden');
            document.getElementById('upload-placeholder').classList.remove('hidden');
            drop.classList.add('py-6');
            drop.classList.remove('py-4');
        };

        document.getElementById('btn-set-now').onclick = () => {
            if (!disbursementDateReadonly) {
                const now = new Date();
                now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
                document.getElementById('date').value = now.toISOString().slice(0,16);
            }
        };

        document.getElementById('disbursementForm').onsubmit = async function(e) {
            e.preventDefault();
            const amountField = document.getElementById('principal_amount');
            const cleanValue = amountField.value.replace(/,/g, '');
            
            const hiddenInput = document.createElement('input');
            hiddenInput.type = 'hidden';
            hiddenInput.name = 'principal_amount';
            hiddenInput.value = cleanValue;
            this.appendChild(hiddenInput);
            
            const btn = document.getElementById('submitBtn');
            const originalText = btn.innerHTML;
            btn.disabled = true; 
            btn.innerHTML = '<i class="fas fa-spinner fa-spin text-lg"></i> <span class="ml-2">Processing...</span>';
            
            try {
                const res = await fetch(window.location.href, { method: 'POST', body: new FormData(this), headers: {'X-Requested-With': 'XMLHttpRequest'} });
                const data = await res.json();
                showToast(data.message, data.status);
                if(data.status === 'success') {
                    this.reset(); 
                    document.getElementById('removeFile').click(); 
                    hideCreditProfile(); 
                    loadHistory(true);
                    
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
            } catch(err) { 
                showToast('Network Error', 'error'); 
            } finally { 
                btn.disabled = false; 
                btn.innerHTML = originalText;
                hiddenInput.remove(); 
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
                    const progress = Math.min((currentTime - startTime) / 1000, 1); 
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