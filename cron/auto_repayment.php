<?php
// cron_auto_repayment.php - v5 (Fixed Officer & Processed By Logic)
// Run this file daily via Server Cron Job (e.g., at 01:00 AM)

require_once __DIR__ . '/../includes/config.php';

// 1. SECURITY: Prevent unauthorized browser access
 $secure_cron_key = "YOUR_SECRET_CRON_KEY_123";
if (php_sapi_name() !== 'cli' && (!isset($_GET['key']) || $_GET['key'] !== $secure_cron_key)) {
    http_response_code(403);
    die("Unauthorized access.\n");
}

try {
    $pdo = getDbConnection();
} catch (Exception $e) {
    die("Database Connection Failed: " . $e->getMessage() . "\n");
}

function generate_uuid() {
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

 $today = date('Y-m-d');
 $today_obj = new DateTime($today);
 $today_ymd = (int)$today_obj->format('Ymd');

 $log_messages = [];
 $log_messages[] = "=== CRON RUN: " . date('Y-m-d H:i:s') . " ===";

try {
    // 2. FETCH ACTIVE LOANS
    $stmt = $pdo->prepare("
        SELECT 
            d.id, d.client_id, d.officer, d.total_payable, d.remaining_balance, 
            d.num_installments, d.date as disb_date,
            p.unit as plan_unit, p.grace_period_days
        FROM disbursements d
        JOIN clients c ON d.client_id = c.id
        JOIN loan_plans p ON c.plan_id = p.id
        WHERE d.status != 'completed' 
          AND d.remaining_balance > 0
          AND p.auto_repayment = 1
    ");
    $stmt->execute();
    $active_loans = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $processed_count = 0;
    $skipped_count = 0;

    foreach ($active_loans as $loan) {
        $disb_date_obj = new DateTime(date('Y-m-d', strtotime($loan['disb_date'])));
        $disb_ymd = (int)$disb_date_obj->format('Ymd');
        
        // 3. CHECK GRACE PERIOD
        $grace_period_days = (int)$loan['grace_period_days'];
        $first_payment_date = (clone $disb_date_obj)->modify("+$grace_period_days days");
        $first_payment_ymd = (int)$first_payment_date->format('Ymd');

        if ($today_ymd < $first_payment_ymd) {
            continue; // Still in grace period
        }

        $is_due_today = false;

        // 4. CHECK IF DUE TODAY
        switch ($loan['plan_unit']) {
            case 'Days':
                $is_due_today = true;
                break;

            case 'Weeks':
                $interval = $disb_date_obj->diff($today_obj);
                $days_passed = $interval->days;
                $is_due_today = ($days_passed > 0 && $days_passed % 7 === 0);
                break;

            case 'Months':
                $disb_day = (int)$disb_date_obj->format('d');
                $disb_month = (int)$disb_date_obj->format('m');
                $disb_year = (int)$disb_date_obj->format('Y');
                
                $today_day = (int)$today_obj->format('d');
                $today_month = (int)$today_obj->format('m');
                $today_year = (int)$today_obj->format('Y');
                $today_month_days = (int)$today_obj->format('t');
                
                $expected_day = min($disb_day, $today_month_days);
                
                if ($today_day === $expected_day) {
                    $month_diff = (($today_year - $disb_year) * 12) + ($today_month - $disb_month);
                    if ($month_diff > 0) {
                        $is_due_today = true;
                    }
                }
                break;
        }

        // 5. PROCESS AUTOMATIC REPAYMENT
        if ($is_due_today) {
            $pdo->beginTransaction();

            try {
                // A. Prevent duplicate repayments
                // Updated: Select 'processed_by' to determine source accurately
                $chk = $pdo->prepare("
                    SELECT id, officer, processed_by, notes 
                    FROM loan_collections 
                    WHERE disbursement_id = ? 
                    AND DATE(date) = ? 
                    AND type = 'repayment'
                ");
                $chk->execute([$loan['id'], $today]);
                
                if ($chk->rowCount() > 0) {
                    $existing = $chk->fetch(PDO::FETCH_ASSOC);
                    $pdo->rollBack();
                    $skipped_count++;
                    
                    // Logic: If processed_by is SYSTEM_CRON, it's auto. Otherwise manual.
                    $source = ($existing['processed_by'] === 'SYSTEM_CRON') ? 'auto' : 'manual';
                    $log_messages[] = "Skipped Loan {$loan['id']}: Already has $source repayment today.";
                    continue;
                }

                // B. Calculate Amount due
                $total_payable = (float)$loan['total_payable'];
                $num_installments = (int)$loan['num_installments'] > 0 ? (int)$loan['num_installments'] : 1;
                $rem_bal = (float)$loan['remaining_balance'];
                
                $expected_installment = $total_payable / $num_installments;
                $pay_amt = min($expected_installment, $rem_bal);

                // C. Update disbursement
                $new_rem = max(0, $rem_bal - $pay_amt);
                $is_paid_off = ($new_rem <= 0.01);
                $status = $is_paid_off ? 'completed' : 'active';
                $payoff_sql = $is_paid_off ? ", payoff_date = :today" : "";

                $upd_sql = "UPDATE disbursements SET remaining_balance = :new_rem, status = :status $payoff_sql WHERE id = :id";
                $upd = $pdo->prepare($upd_sql);
                $upd_params = ['new_rem' => $new_rem, 'status' => $status, 'id' => $loan['id']];
                if($is_paid_off) $upd_params['today'] = $today;
                $upd->execute($upd_params);

                // D. Record collection
                $tx_id = 'AUTO-' . strtoupper(generate_uuid());
                
                // UPDATED: Added 'processed_by' column
                // officer = $loan['officer'] (Assigned CO)
                // processed_by = 'SYSTEM_CRON' (The system)
                $ins = $pdo->prepare("
                    INSERT INTO loan_collections (
                        transaction_id, client_id, disbursement_id, amount_collected, 
                        date, officer, processed_by, type, remaining_balance, notes
                    ) VALUES (?, ?, ?, ?, NOW(), ?, 'SYSTEM_CRON', 'repayment', ?, ?)
                ");
                $ins->execute([
                    $tx_id, 
                    $loan['client_id'], 
                    $loan['id'], 
                    $pay_amt, 
                    $loan['officer'], // The assigned CO username
                    $new_rem, 
                    'Automatic repayment by cron job.'
                ]);

                $pdo->commit();
                $processed_count++;
                $log_messages[] = "Processed: Client {$loan['client_id']} | Loan {$loan['id']} | Amount: " . number_format($pay_amt, 2) . ($is_paid_off ? " (PAID OFF)" : "");

            } catch (Exception $ex) {
                $pdo->rollBack();
                $log_messages[] = "ERROR on Loan {$loan['id']}: " . $ex->getMessage();
            }
        }
    }

    $log_messages[] = "Total Auto-Repayments Processed: $processed_count";
    $log_messages[] = "Total Skipped (Duplicates): $skipped_count";

} catch (Exception $e) {
    $log_messages[] = "CRITICAL ERROR: " . $e->getMessage();
}

// 6. LOGGING
 $log_output = implode("\n", $log_messages) . "\n\n";
file_put_contents(__DIR__ . '/cron_log.txt', $log_output, FILE_APPEND);
echo $log_output;

?>
