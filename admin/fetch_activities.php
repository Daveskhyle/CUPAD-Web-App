<?php
date_default_timezone_set('Africa/Lagos');
ini_set('date.timezone', 'Africa/Lagos');
session_start();

// Clear any output buffers
while (ob_get_level()) ob_end_clean();
ob_start();

require_once '../includes/config.php';

header('Content-Type: application/json');

try {
    // Check authentication
    if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
        throw new Exception('Unauthorized access');
    }

    $conn = getDbConnection();
    
    if (!$conn) {
        throw new Exception('Database connection failed');
    }

    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
    $offset = ($page - 1) * $limit;

    $activities = [];

    $sql = "
    SELECT 
        t.id as primary_key,
        t.transaction_id,
        t.client_id,
        c.name as client_name,
        t.branch_id,
        COALESCE(t.branch_id, c.branch_id) as actual_branch_id,
        COALESCE(b.name, c.branch_id, 'N/A') as branch_name,
        t.date as timestamp,
        CASE 
            WHEN t.source_table = 'disbursements' THEN 'disbursement'
            WHEN t.source_table = 'loan_collections' THEN t.subtype
            WHEN t.source_table = 'saving_collections' THEN CONCAT('savings_', t.subtype)
            WHEN t.source_table = 'clients' THEN 'new_client'
            WHEN t.source_table = 'users' THEN 'new_user'
        END as activity_type,
        t.amount,
        t.total_payable,
        t.remaining_balance,
        t.interest_rate,
        t.num_installments,
        t.installment_amount,
        t.source_table,
        t.subtype as transaction_subtype
    FROM (
        SELECT 
            id, 
            id as transaction_id, 
            client_id, 
            branch_id, 
            date, 
            'disbursements' as source_table, 
            NULL as subtype, 
            principal as amount, 
            total_payable, 
            remaining_balance, 
            interest_rate, 
            num_installments, 
            (total_payable / NULLIF(num_installments,0)) as installment_amount
        FROM disbursements
        
        UNION ALL
        
        SELECT 
            id, 
            transaction_id, 
            client_id, 
            NULL as branch_id, 
            date, 
            'loan_collections' as source_table, 
            type as subtype, 
            amount_collected as amount, 
            NULL, 
            remaining_balance, 
            NULL, 
            NULL, 
            NULL
        FROM loan_collections
        
        UNION ALL
        
        SELECT 
            id, 
            transaction_id, 
            client_id, 
            NULL as branch_id, 
            date, 
            'saving_collections' as source_table, 
            type as subtype, 
            amount, 
            NULL, 
            balance_after, 
            NULL, 
            NULL, 
            NULL
        FROM saving_collections
        
        UNION ALL
        
        SELECT 
            id,
            CONCAT('CLI-', id) as transaction_id,
            id as client_id,
            branch_id,
            created_at as date,
            'clients' as source_table,
            'new_client' as subtype,
            0 as amount,
            NULL,
            NULL,
            NULL,
            NULL,
            NULL
        FROM clients
        
        UNION ALL
        
        SELECT 
            u.id,
            CONCAT('USR-', u.id) as transaction_id,
            NULL as client_id,
            u.branch_id,
            u.created_at as date,
            'users' as source_table,
            'new_user' as subtype,
            0 as amount,
            NULL,
            NULL,
            NULL,
            NULL,
            NULL
        FROM users u
        WHERE u.created_at IS NOT NULL
        
    ) t
    LEFT JOIN clients c ON t.client_id = c.id
    LEFT JOIN branches b ON COALESCE(t.branch_id, c.branch_id) = b.id
    ORDER BY t.date DESC 
    LIMIT $limit OFFSET $offset
    ";

    $stmt = $conn->query($sql);
    $raw_activities = $stmt->fetchAll(PDO::FETCH_ASSOC);

    function timeAgo($timestamp) {
        if (empty($timestamp)) return 'Unknown';
        $ts = strtotime($timestamp);
        if (!$ts) return 'Unknown';
        $diff = time() - $ts;
        if ($diff < 60) return 'Just now';
        if ($diff < 3600) return floor($diff / 60) . ' mins ago';
        if ($diff < 86400) return floor($diff / 3600) . ' hours ago';
        return date('M j, g:i A', $ts);
    }

    function formatCurrency($amount) {
        return '₦' . number_format(abs(floatval($amount)), 2);
    }

    function getActivityIcon($type, $subtype) {
        $iconMap = [
            'disbursement' => 'fa-money-bill-wave',
            'repayment' => 'fa-hand-holding-usd',
            'epayment' => 'fa-hand-holding-usd',
            'auto_repayment' => 'fa-sync-alt',
            'savings_deposit' => 'fa-piggy-bank',
            'savings_withdrawal' => 'fa-wallet',
            'savings_withdrawal_cash' => 'fa-wallet',
            'savings_return' => 'fa-undo',
            'savings_transfer' => 'fa-exchange-alt',
            'savings_interest' => 'fa-percentage',
            'savings_fee' => 'fa-file-invoice-dollar',
            'new_client' => 'fa-user-plus',
            'new_user' => 'fa-user-cog',
            'penalty' => 'fa-exclamation-circle',
            'adjustment' => 'fa-sliders-h'
        ];
        
        $key = $type === 'saving_collections' ? 'savings_' . $subtype : ($subtype ?: $type);
        return $iconMap[$key] ?? 'fa-circle';
    }

    function getActivityColor($type, $subtype) {
        $colorMap = [
            'disbursement' => 'warning',
            'repayment' => 'success',
            'epayment' => 'success',
            'auto_repayment' => 'info',
            'savings_deposit' => 'success',
            'savings_withdrawal' => 'warning',
            'savings_withdrawal_cash' => 'warning',
            'savings_return' => 'danger',
            'savings_transfer' => 'info',
            'savings_interest' => 'success',
            'savings_fee' => 'danger',
            'new_client' => 'success',
            'new_user' => 'info',
            'penalty' => 'danger',
            'adjustment' => 'muted'
        ];
        
        $key = $type === 'saving_collections' ? 'savings_' . $subtype : ($subtype ?: $type);
        return $colorMap[$key] ?? 'muted';
    }

    foreach ($raw_activities as $row) {
        $type = $row['activity_type'];
        $amount = floatval($row['amount']);
        $isDisbursement = strpos($type, 'disbursement') !== false;
        $isRepayment = strpos($type, 'repayment') !== false || strpos($type, 'collection') !== false;
        $isSavings = strpos($type, 'savings') !== false;
        $isNewClient = strpos($type, 'new_client') !== false;
        $isNewUser = strpos($type, 'new_user') !== false;
        
        $description = '';
        $title = '';
        
        if ($isDisbursement) {
            $title = 'Loan Disbursement';
            $desc = "Principal: " . formatCurrency($amount);
            if ($row['total_payable']) {
                $desc .= " | Payable: " . formatCurrency($row['total_payable']);
            }
            if ($row['interest_rate']) {
                $desc .= " @ " . round($row['interest_rate']) . "%";
            }
            if ($row['num_installments']) {
                $desc .= " (" . $row['num_installments'] . " weeks)";
            }
            $description = $desc;
            
        } elseif ($isRepayment) {
            $title = 'Loan Repayment';
            $desc = "Amount: " . formatCurrency($amount);
            if ($row['remaining_balance'] !== null) {
                $desc .= " | Balance: " . formatCurrency($row['remaining_balance']);
            }
            $description = $desc;
            
        } elseif ($isSavings) {
            $subtype = $row['transaction_subtype'] ?? '';
            $action = str_replace('savings_', '', $type);
            $title = 'Savings ' . ucfirst($action);
            $desc = "Amount: " . formatCurrency($amount);
            if ($row['remaining_balance'] !== null) {
                $desc .= " | New Balance: " . formatCurrency($row['remaining_balance']);
            }
            $description = $desc;
            
        } elseif ($isNewClient) {
            $title = 'New Client Registered';
            $description = "Client joined the system";
            
        } elseif ($isNewUser) {
            $title = 'New User Created';
            $description = "Staff account created";
            
        } else {
            $title = str_replace('_', ' ', ucfirst($type));
            $description = formatCurrency($amount);
        }

        $meta = [];
        if (!empty($row['client_name']) && !$isNewClient) {
            $meta[] = htmlspecialchars($row['client_name']);
        }
        if (!empty($row['branch_name']) && $row['branch_name'] !== 'N/A') {
            $meta[] = htmlspecialchars($row['branch_name']);
        }
        if (!empty($meta)) {
            $description .= " — " . implode(' • ', $meta);
        }

        $activities[] = [
            'title' => $title,
            'description' => $description,
            'icon' => 'fas ' . getActivityIcon($row['source_table'], $row['transaction_subtype']),
            'type' => getActivityColor($row['source_table'], $row['transaction_subtype']),
            'timestamp' => $row['timestamp'],
            'time_ago' => timeAgo($row['timestamp']),
            'raw_data' => $row
        ];
    }

    $has_more = count($activities) >= $limit;

    ob_end_clean();
    echo json_encode([
        'success' => true,
        'activities' => $activities, 
        'page' => $page,
        'has_more' => $has_more
    ]);

} catch (Exception $e) {
    ob_end_clean();
    error_log("Activities Error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(), 
        'activities' => []
    ]);
}
exit();
