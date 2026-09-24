<?php
require_once '../includes/config.php';
require_once '../includes/auth_functions.php';
require_once '../includes/json_helpers.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$action = $_POST['action'] ?? '';
$data = json_decode($_POST['data'] ?? '{}', true);

try {
    switch ($action) {
        case 'create':
            $result = createClient($data);
            break;
        case 'update':
            $result = updateClient($data);
            break;
        case 'delete':
            $result = deleteClient($data['id']);
            break;
        case 'bulk_sync':
            $result = bulkSyncClients($data);
            break;
        default:
            throw new Exception('Invalid action');
    }
    
    echo json_encode(['success' => true, 'result' => $result]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}

function createClient($data) {
    $clients = loadJsonData('clients.json');
    $data['id'] = generateId();
    $data['created_at'] = date('Y-m-d H:i:s');
    $clients[] = $data;
    saveJsonData('clients.json', $clients);
    return $data['id'];
}

function updateClient($data) {
    $clients = loadJsonData('clients.json');
    foreach ($clients as &$client) {
        if ($client['id'] == $data['id']) {
            $client = array_merge($client, $data);
            $client['updated_at'] = date('Y-m-d H:i:s');
            break;
        }
    }
    saveJsonData('clients.json', $clients);
    return true;
}

function deleteClient($id) {
    $clients = loadJsonData('clients.json');
    $clients = array_filter($clients, function($client) use ($id) {
        return $client['id'] != $id;
    });
    saveJsonData('clients.json', array_values($clients));
    return true;
}

function bulkSyncClients($clientsData) {
    $existingClients = loadJsonData('clients.json');
    $synced = 0;
    
    foreach ($clientsData as $clientData) {
        $exists = false;
        foreach ($existingClients as &$existing) {
            if ($existing['id'] == $clientData['id']) {
                $existing = array_merge($existing, $clientData);
                $existing['updated_at'] = date('Y-m-d H:i:s');
                $exists = true;
                break;
            }
        }
        
        if (!$exists) {
            $clientData['created_at'] = date('Y-m-d H:i:s');
            $existingClients[] = $clientData;
        }
        $synced++;
    }
    
    saveJsonData('clients.json', $existingClients);
    return $synced;
}

function generateId() {
    return uniqid() . '_' . time();
}
?>