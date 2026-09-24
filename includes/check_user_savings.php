<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['username'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$clientId = $_GET['client_id'] ?? '';

if (empty($clientId)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing client ID']);
    exit();
}

try {
    // Load client data to get name
    $clientsFile = __DIR__ . '/../data/clients.json';
    $clients = json_decode(file_get_contents($clientsFile), true) ?: [];
    
    $clientName = '';
    foreach ($clients as $client) {
        if ($client['id'] === $clientId) {
            $clientName = $client['name'];
            break;
        }
    }
    
    if (empty($clientName)) {
        echo json_encode(['has_savings' => false, 'savings_count' => 0, 'total_amount' => 0]);
        exit();
    }
    
    // Load savings data
    $savingsFile = __DIR__ . '/../data/savings.json';
    $savings = json_decode(file_get_contents($savingsFile), true) ?: [];
    
    $userSavings = [];
    $totalAmount = 0;
    
    foreach ($savings as $saving) {
        if ($saving['client'] === $clientName) {
            $userSavings[] = $saving;
            $totalAmount += $saving['amount'];
        }
    }
    
    header('Content-Type: application/json');
    echo json_encode([
        'has_savings' => count($userSavings) > 0,
        'savings_count' => count($userSavings),
        'total_amount' => $totalAmount
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}
?>