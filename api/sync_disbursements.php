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
            $result = createDisbursement($data);
            break;
        case 'update':
            $result = updateDisbursement($data);
            break;
        case 'delete':
            $result = deleteDisbursement($data['id']);
            break;
        case 'bulk_sync':
            $result = bulkSyncDisbursements($data);
            break;
        default:
            throw new Exception('Invalid action');
    }
    
    echo json_encode(['success' => true, 'result' => $result]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}

function createDisbursement($data) {
    $disbursements = loadJsonData('disbursements.json');
    $data['id'] = generateId();
    $data['created_at'] = date('Y-m-d H:i:s');
    $disbursements[] = $data;
    saveJsonData('disbursements.json', $disbursements);
    return $data['id'];
}

function updateDisbursement($data) {
    $disbursements = loadJsonData('disbursements.json');
    foreach ($disbursements as &$disbursement) {
        if ($disbursement['id'] == $data['id']) {
            $disbursement = array_merge($disbursement, $data);
            $disbursement['updated_at'] = date('Y-m-d H:i:s');
            break;
        }
    }
    saveJsonData('disbursements.json', $disbursements);
    return true;
}

function deleteDisbursement($id) {
    $disbursements = loadJsonData('disbursements.json');
    $disbursements = array_filter($disbursements, function($disbursement) use ($id) {
        return $disbursement['id'] != $id;
    });
    saveJsonData('disbursements.json', array_values($disbursements));
    return true;
}

function bulkSyncDisbursements($disbursementsData) {
    $existingDisbursements = loadJsonData('disbursements.json');
    $synced = 0;
    
    foreach ($disbursementsData as $disbursementData) {
        $exists = false;
        foreach ($existingDisbursements as &$existing) {
            if ($existing['id'] == $disbursementData['id']) {
                $existing = array_merge($existing, $disbursementData);
                $existing['updated_at'] = date('Y-m-d H:i:s');
                $exists = true;
                break;
            }
        }
        
        if (!$exists) {
            $disbursementData['created_at'] = date('Y-m-d H:i:s');
            $existingDisbursements[] = $disbursementData;
        }
        $synced++;
    }
    
    saveJsonData('disbursements.json', $existingDisbursements);
    return $synced;
}

function generateId() {
    return uniqid() . '_' . time();
}
?>