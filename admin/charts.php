<?php
// =================================================================
// BACKEND LOGIC (Place this before the HTML view)
// =================================================================

// 1. Setup Date Ranges (Last 12 Months)
$months = [];
$chart_data = [];
// Start date for SQL queries (1st day of the month, 11 months ago)
$sql_start_date = date('Y-m-01', strtotime("-11 months")); 

for ($i = 11; $i >= 0; $i--) {
    $key = date('Y-m', strtotime("-$i months"));
    $label = date('M Y', strtotime("-$i months"));
    $months[] = $label;
    
    // Initialize with zeros to ensure continuous lines even with no data
    $chart_data[$key] = [
        'disbursed' => 0,
        'collected' => 0,
        'savings_deposit' => 0,
        'savings_withdrawal' => 0,
    ];
}

// Initialize Totals for Doughnut Chart
$transaction_types_data = [
    'Disbursements' => 0,
    'Loan Collections' => 0,
    'Savings Deposits' => 0,
    'Savings Withdrawals' => 0,
];

// 2. Fetch Disbursements
// Schema: disbursements(date, principal)
try {
    $stmt = $pdo->prepare("
        SELECT 
            DATE_FORMAT(date, '%Y-%m') as month_year, 
            SUM(principal) as total_amount,
            COUNT(*) as total_count
        FROM disbursements 
        WHERE date >= :start_date 
        AND status != 'cancelled' -- Assuming you might have cancelled status, otherwise remove
        GROUP BY month_year
    ");
    $stmt->execute(['start_date' => $sql_start_date]);
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (isset($chart_data[$row['month_year']])) {
            $chart_data[$row['month_year']]['disbursed'] = (float)$row['total_amount'];
        }
        $transaction_types_data['Disbursements'] += $row['total_count'];
    }
} catch (PDOException $e) { /* Handle error */ }

// 3. Fetch Loan Collections
// Schema: loan_collections(date, amount_collected)
try {
    $stmt = $pdo->prepare("
        SELECT 
            DATE_FORMAT(date, '%Y-%m') as month_year, 
            SUM(amount_collected) as total_amount,
            COUNT(*) as total_count
        FROM loan_collections 
        WHERE date >= :start_date 
        GROUP BY month_year
    ");
    $stmt->execute(['start_date' => $sql_start_date]);
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (isset($chart_data[$row['month_year']])) {
            $chart_data[$row['month_year']]['collected'] = (float)$row['total_amount'];
        }
        $transaction_types_data['Loan Collections'] += $row['total_count'];
    }
} catch (PDOException $e) { /* Handle error */ }

// 4. Fetch Savings Transactions
// Schema: saving_collections(date, amount, type)
try {
    $stmt = $pdo->prepare("
        SELECT 
            DATE_FORMAT(date, '%Y-%m') as month_year, 
            type,
            SUM(amount) as total_amount,
            COUNT(*) as total_count
        FROM saving_collections 
        WHERE date >= :start_date 
        GROUP BY month_year, type
    ");
    $stmt->execute(['start_date' => $sql_start_date]);
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $month = $row['month_year'];
        $type = $row['type'];
        $amount = (float)$row['total_amount'];
        $count = (int)$row['total_count'];

        // Map database enums to chart categories
        // Enum: 'deposit','withdrawal','cash','return','adjust','interest','fee'
        
        if (in_array($type, ['deposit', 'interest', 'adjust'])) {
            // Money In
            if (isset($chart_data[$month])) {
                $chart_data[$month]['savings_deposit'] += $amount;
            }
            $transaction_types_data['Savings Deposits'] += $count;
        } elseif (in_array($type, ['withdrawal', 'cash', 'return', 'fee'])) {
            // Money Out
            if (isset($chart_data[$month])) {
                $chart_data[$month]['savings_withdrawal'] += $amount;
            }
            $transaction_types_data['Savings Withdrawals'] += $count;
        }
    }
} catch (PDOException $e) { /* Handle error */ }

// 5. Prepare Data for JavaScript
$chart_labels = json_encode($months);
$chart_disbursed = json_encode(array_column(array_values($chart_data), 'disbursed'));
$chart_collected = json_encode(array_column(array_values($chart_data), 'collected'));
$chart_savings_deposit = json_encode(array_column(array_values($chart_data), 'savings_deposit'));
$chart_savings_withdrawal = json_encode(array_column(array_values($chart_data), 'savings_withdrawal'));

$transaction_types_labels = json_encode(array_keys($transaction_types_data));
$transaction_types_values = json_encode(array_values($transaction_types_data));
?>

<!-- =================================================================
     FRONTEND VIEW
     ================================================================= -->

<div class="row">
    <div class="col-md-8">
        <div class="content-card" style="position: relative; height: 400px; width: 100%;">
            <h5 style="margin-bottom: 20px;">Financial Overview (Last 12 Months)</h5>
            <canvas id="transactionChart"></canvas>
        </div>
    </div>
    <div class="col-md-4">
        <div class="content-card" style="position: relative; height: 400px; width: 100%;">
            <h5 style="margin-bottom: 20px;">Transaction Volume</h5>
            <canvas id="transactionTypesChart"></canvas>
        </div>
    </div>
</div>

<!-- Load Chart.js (Ensure this is included in your header or footer) -->
<!-- <script src="https://cdn.jsdelivr.net/npm/chart.js"></script> -->

<script>
    document.addEventListener('DOMContentLoaded', function() {
        // 1. Main Financial Line Chart
        const ctxChart = document.getElementById('transactionChart').getContext('2d');
        
        // Gradient configurations
        let gradientDisbursed = ctxChart.createLinearGradient(0, 0, 0, 400);
        gradientDisbursed.addColorStop(0, 'rgba(76, 175, 80, 0.4)');
        gradientDisbursed.addColorStop(1, 'rgba(76, 175, 80, 0.0)');

        const transactionChart = new Chart(ctxChart, {
            type: 'line',
            data: {
                labels: <?= $chart_labels ?>,
                datasets: [{
                    label: 'Disbursed',
                    data: <?= $chart_disbursed ?>,
                    borderColor: '#4CAF50', // Green
                    backgroundColor: gradientDisbursed,
                    fill: true,
                    tension: 0.4,
                    pointRadius: 3
                }, {
                    label: 'Loan Collected',
                    data: <?= $chart_collected ?>,
                    borderColor: '#2196F3', // Blue
                    backgroundColor: 'rgba(33, 150, 243, 0.1)',
                    fill: true,
                    tension: 0.4,
                    pointRadius: 3
                }, {
                    label: 'Savings In',
                    data: <?= $chart_savings_deposit ?>,
                    borderColor: '#9C27B0', // Purple
                    backgroundColor: 'transparent',
                    borderDash: [5, 5],
                    fill: false,
                    tension: 0.4,
                    pointRadius: 2
                }, {
                    label: 'Savings Out',
                    data: <?= $chart_savings_withdrawal ?>,
                    borderColor: '#f44336', // Red
                    backgroundColor: 'transparent',
                    borderDash: [2, 2],
                    fill: false,
                    tension: 0.4,
                    pointRadius: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false,
                },
                plugins: {
                    legend: {
                        position: 'top',
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                let label = context.dataset.label || '';
                                if (label) {
                                    label += ': ';
                                }
                                if (context.parsed.y !== null) {
                                    // Format as Currency
                                    label += new Intl.NumberFormat('en-NG', { style: 'currency', currency: 'NGN' }).format(context.parsed.y);
                                }
                                return label;
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: 'rgba(0, 0, 0, 0.05)'
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        }
                    }
                }
            }
        });

        // 2. Transaction Types Doughnut Chart
        const ctxTypesChart = document.getElementById('transactionTypesChart').getContext('2d');
        const transactionTypesChart = new Chart(ctxTypesChart, {
            type: 'doughnut',
            data: {
                labels: <?= $transaction_types_labels ?>,
                datasets: [{
                    label: 'Count',
                    data: <?= $transaction_types_values ?>,
                    backgroundColor: [
                        'rgba(76, 175, 80, 0.8)',  // Disbursed (Green)
                        'rgba(33, 150, 243, 0.8)',  // Collected (Blue)
                        'rgba(156, 39, 176, 0.8)',  // Savings In (Purple)
                        'rgba(244, 67, 54, 0.8)'    // Savings Out (Red)
                    ],
                    borderColor: [
                        '#ffffff',
                        '#ffffff',
                        '#ffffff',
                        '#ffffff'
                    ],
                    borderWidth: 2,
                    hoverOffset: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            boxWidth: 12
                        }
                    }
                }
            }
        });
    });
</script>