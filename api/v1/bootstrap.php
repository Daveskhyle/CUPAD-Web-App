<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-API-Key');
header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

function respond(array $data, int $status=200): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}
function db(): PDO { return getDbConnection(); }

function getBearerToken(): ?string {
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    return preg_match('/^Bearer\s+(.+)$/i', $h, $m) ? trim($m[1]) : null;
}
function requireApiKey(): void {
    $key = $_SERVER['HTTP_X_API_KEY'] ?? getBearerToken() ?? '';
    if ($key === '' || !hash_equals(API_KEY, $key)) {
        respond(['success'=>false,'error'=>'Invalid API key'],401);
    }
}
function jsonBody(): array {
    $data = json_decode(file_get_contents('php://input') ?: '{}', true);
    return is_array($data) ? $data : [];
}
function b64url(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function jwtEncode(array $payload): string {
    $h=b64url(json_encode(['alg'=>'HS256','typ'=>'JWT']));
    $p=b64url(json_encode($payload));
    $s=$h.'.'.$p;
    return $s.'.'.b64url(hash_hmac('sha256',$s,JWT_SECRET,true));
}
function jwtDecode(string $token): ?array {
    $parts=explode('.',$token);
    if(count($parts)!==3) return null;
    $expected=b64url(hash_hmac('sha256',$parts[0].'.'.$parts[1],JWT_SECRET,true));
    if(!hash_equals($expected,$parts[2])) return null;
    $payload=json_decode(base64_decode(strtr($parts[1],'-_','+/')),true);
    return is_array($payload) && (int)($payload['exp']??0) >= time() ? $payload : null;
}
function requireJwt(): array {
    $token=getBearerToken();
    $data=$token ? jwtDecode($token) : null;
    if(!$data) respond(['success'=>false,'error'=>'Authentication required'],401);
    return $data;
}
function clientExists(string $id): bool {
    $s=db()->prepare("SELECT 1 FROM clients WHERE id=? AND deleted_at IS NULL LIMIT 1");
    $s->execute([$id]);
    return (bool)$s->fetchColumn();
}
function portfolioData(string $clientId): array {
    if (!clientExists($clientId)) respond(['success'=>false,'error'=>'Client not found'],404);
    $pdo=db();
    $s=$pdo->prepare('SELECT id,name,phone,email,union,branch_id,officer_username,client_type,plan_id,status,date_registered FROM clients WHERE id=? AND deleted_at IS NULL');
    $s->execute([$clientId]); $client=$s->fetch();
    $s=$pdo->prepare("SELECT COALESCE(SUM(balance),0) FROM savings WHERE client_id=? AND status <> 'closed'");
    $s->execute([$clientId]); $savings=(float)$s->fetchColumn();
    $s=$pdo->prepare("SELECT COUNT(*) loans, COALESCE(SUM(principal),0) principal, COALESCE(SUM(remaining_balance),0) outstanding FROM disbursements WHERE client_id=?");
    $s->execute([$clientId]); $loan=$s->fetch() ?: ['loans'=>0,'principal'=>0,'outstanding'=>0];
    $s=$pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM saving_collections WHERE client_id=? AND type IN ('deposit','cash','return','interest')");
    $s->execute([$clientId]); $deposits=(float)$s->fetchColumn();
    $s=$pdo->prepare("SELECT COALESCE(SUM(amount_collected),0) FROM loan_collections WHERE client_id=? AND type='repayment'");
    $s->execute([$clientId]); $repayments=(float)$s->fetchColumn();
    return [
        'client'=>$client,
        'savings'=>['balance'=>$savings,'total_deposits'=>$deposits],
        'loans'=>['count'=>(int)$loan['loans'],'principal'=>(float)$loan['principal'],'outstanding'=>(float)$loan['outstanding'],'total_repaid'=>$repayments]
    ];
}