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
            $result = createSaving($data);
            break;
        case 'update':
            $result = updateSaving($data);
            break;
        case 'delete':
            $result = deleteSaving($data['id']);
            break;
        case 'bulk_sync':
            $result = bulkSyncSavings($data);
            break;
        default:
            throw new Exception('Invalid action');
    }
    
    echo json_encode(['success' => true, 'result' => $result]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}

function createSaving($data) {
    $savings = loadJsonData('savings.json');
    $data['id'] = generateId();
    $data['created_at'] = date('Y-m-d H:i:s');
    $savings[] = $data;
    saveJsonData('savings.json', $savings);
    return $data['id'];
}

function updateSaving($data) {
    $savings = loadJsonData('savings.json');
    foreach ($savings as &$saving) {
        if ($saving['id'] == $data['id']) {
            $saving = array_merge($saving, $data);
            $saving['updated_at'] = date('Y-m-d H:i:s');
            break;
        }
    }
    saveJsonData('savings.json', $savings);
    return true;
}

function deleteSaving($id) {
    $savings = loadJsonData('savings.json');
    $savings = array_filter($savings, function($saving) use ($id) {
        return $saving['id'] != $id;
    });
    saveJsonData('savings.json', array_values($savings));
    return true;
}

function bulkSyncSavings($savingsData) {
    $existingSavings = loadJsonData('savings.json');
    $synced = 0;
    
    foreach ($savingsData as $savingData) {
        $exists = false;
        foreach ($existingSavings as &$existing) {
            if ($existing['id'] == $savingData['id']) {
                $existing = array_merge($existing, $savingData);
                $existing['updated_at'] = date('Y-m-d H:i:s');
                $exists = true;
                break;
            }
        }
        
        if (!$exists) {
            $savingData['created_at'] = date('Y-m-d H:i:s');
            $existingSavings[] = $savingData;
        }
        $synced++;
    }
    
    saveJsonData('savings.json', $existingSavings);
    return $synced;
}

function generateId() {
    return uniqid() . '_' . time();
}
?>