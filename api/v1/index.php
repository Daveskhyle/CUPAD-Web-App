<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

$path=trim(parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH),'/');
$method=$_SERVER['REQUEST_METHOD'];
$parts=$path===''?[]:explode('/',$path);
while($parts && ($parts[0]==='api'||$parts[0]==='v1')) array_shift($parts);
$route=implode('/',$parts);

/* Serve uploaded profile pictures before the API router handles /uploads paths. */
if($method==='GET' && preg_match('#^uploads/profile/([A-Za-z0-9._-]+)$#',$route,$m)){
    $filename=$m[1];
    $file=__DIR__.'/uploads/profile/'.$filename;
    if(!is_file($file)) respond(['success'=>false,'error'=>'Image not found'],404);
    $mime=function_exists('mime_content_type') ? mime_content_type($file) : null;
    $allowed=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
    if(!$mime || !isset($allowed[$mime])) respond(['success'=>false,'error'=>'Invalid image'],404);
    header('Content-Type: '.$mime);
    header('Content-Length: '.(string)filesize($file));
    header('Cache-Control: public, max-age=86400');
    readfile($file);
    exit;
}

/* Mobile endpoints are loaded first so they can handle their routes and share the same JWT/scope helpers. */
require_once __DIR__ . '/mobile-writes.php';

if($method==='GET' && $route==='health'){
    try { db()->query('SELECT 1'); respond(['success'=>true,'service'=>'CUPAD API','version'=>'1.2.0','database'=>'ok']); }
    catch(Throwable $e){ respond(['success'=>false,'database'=>'error'],503); }
}
if($method==='GET' && $route==='test'){
    requireApiKey();
    try { db()->query('SELECT 1'); respond(['success'=>true,'authenticated'=>true,'service'=>'CUPAD API','version'=>'1.2.0','database'=>'ok','time'=>date('c')]); }
    catch(Throwable $e){ respond(['success'=>false,'authenticated'=>true,'database'=>'error'],503); }
}
if($method==='GET' && $route==='openapi.json'){
    requireApiKey(); header('Content-Type: application/json; charset=utf-8');
    echo file_get_contents(dirname(__DIR__).'/openapi.json'); exit;
}

/*
 * Location mapping follows the CUPAD hierarchy used by the CO dashboard:
 * Branch -> Area -> Zone. The user's explicit area/zone assignments are
 * preferred, while the branch hierarchy provides a reliable fallback.
 */
$locationSelect = "
    b.name AS branch_name,
    a.name AS area_name,
    z.name AS zone_name
";
$locationJoins = "
    LEFT JOIN branches b ON u.branch_id = b.id
    LEFT JOIN areas a ON a.id = COALESCE(u.area_id, b.area_id)
    LEFT JOIN zones z ON z.id = COALESCE(u.zone_id, a.zone_id)
";

if($method==='POST' && $route==='auth/login'){
    $b=jsonBody(); $username=trim((string)($b['username']??'')); $password=(string)($b['password']??'');
    if($username===''||$password==='') respond(['success'=>false,'error'=>'username and password are required'],422);
    $s=db()->prepare("SELECT u.id,u.username,u.password,u.name,u.full_name,u.email,u.phone,u.role,u.branch_id,u.area_id,u.zone_id,u.status,u.profile_pic,$locationSelect FROM users u $locationJoins WHERE u.username=? LIMIT 1");
    $s->execute([$username]); $u=$s->fetch();
    if(!$u || $u['status']!=='active' || !(password_verify($password,(string)$u['password']) || hash_equals((string)$u['password'],$password))) respond(['success'=>false,'error'=>'Invalid credentials'],401);
    $now=time(); $token=jwtEncode(['sub'=>(int)$u['id'],'username'=>$u['username'],'role'=>$u['role'],'iat'=>$now,'exp'=>$now+86400]);
    unset($u['password']); respond(['success'=>true,'token'=>$token,'expires_at'=>date('c',$now+86400),'user'=>$u]);
}
if($method==='GET' && $route==='me'){
    $auth=requireJwt();
    $s=db()->prepare("SELECT u.id,u.username,u.name,u.full_name,u.email,u.phone,u.role,u.branch_id,u.area_id,u.zone_id,u.profile_pic,u.status,u.last_login,$locationSelect FROM users u $locationJoins WHERE u.id=?");
    $s->execute([(int)$auth['sub']]);
    respond(['success'=>true,'data'=>$s->fetch()?:null]);
}

/* Mobile clients endpoint: JWT only; no API key is exposed in the app. */
if($method==='GET' && $route==='clients'){
    $auth=requireJwt(); $userId=(int)($auth['sub']??0);
    $uStmt=db()->prepare('SELECT id,username,role,branch_id,area_id,zone_id FROM users WHERE id=? AND status="active" LIMIT 1'); $uStmt->execute([$userId]); $user=$uStmt->fetch();
    if(!$user) respond(['success'=>false,'error'=>'User not found'],404);
    $q=trim((string)($_GET['q']??'')); $limit=min(100,max(1,(int)($_GET['limit']??20))); $offset=max(0,(int)($_GET['offset']??0)); $where='deleted_at IS NULL'; $params=[]; $role=strtolower((string)$user['role']);
    if($role==='co'){ $where.=' AND officer_username=?'; $params[]=$user['username']; }
    elseif($role==='bm' && $user['branch_id']!==null && $user['branch_id']!==''){ $where.=' AND branch_id=?'; $params[]=$user['branch_id']; }
    elseif($role==='am' && $user['area_id']!==null && $user['area_id']!==''){ $where.=' AND branch_id IN (SELECT id FROM branches WHERE area_id=?)'; $params[]=$user['area_id']; }
    elseif(in_array($role,['zm','dzm','tm'],true) && $user['zone_id']!==null && $user['zone_id']!==''){ $where.=' AND branch_id IN (SELECT id FROM branches WHERE zone_id=? OR area_id IN (SELECT id FROM areas WHERE zone_id=?))'; $params[]=$user['zone_id']; $params[]=$user['zone_id']; }
    if($q!==''){ $where.=' AND (id LIKE ? OR name LIKE ? OR phone LIKE ?)'; $like="%$q%"; $params[]=$like; $params[]=$like; $params[]=$like; }
    $count=db()->prepare("SELECT COUNT(*) FROM clients WHERE $where"); $count->execute($params); $total=(int)$count->fetchColumn();
    $sql="SELECT id,name,phone,email,`union`,branch_id,officer_username,client_type,plan_id,status,date_registered FROM clients WHERE $where ORDER BY name LIMIT $limit OFFSET $offset"; $s=db()->prepare($sql); $s->execute($params);
    respond(['success'=>true,'data'=>$s->fetchAll(),'pagination'=>['total'=>$total,'limit'=>$limit,'offset'=>$offset,'has_more'=>$offset+$limit<$total]]);
}
if($method==='GET' && preg_match('#^clients/([^/]+)/portfolio$#',$route,$m)){
    $user=mobileUser(); mobileClientAllowed($user,$m[1]); respond(['success'=>true,'data'=>portfolioData($m[1])]);
}
if($method==='GET' && preg_match('#^clients/([^/]+)/savings$#',$route,$m)){
    $user=mobileUser(); mobileClientAllowed($user,$m[1]);
    $s=db()->prepare('SELECT * FROM savings WHERE client_id=? ORDER BY created_at DESC'); $s->execute([$m[1]]);
    respond(['success'=>true,'data'=>$s->fetchAll()]);
}
if($method==='GET' && preg_match('#^clients/([^/]+)/loans$#',$route,$m)){
    $user=mobileUser(); mobileClientAllowed($user,$m[1]);
    $s=db()->prepare('SELECT id,principal,interest_rate,total_payable,remaining_balance,num_installments,loan_term_type,date,due_date,payoff_date,status FROM disbursements WHERE client_id=? ORDER BY date DESC'); $s->execute([$m[1]]);
    respond(['success'=>true,'data'=>$s->fetchAll()]);
}
if($method==='GET' && preg_match('#^clients/([^/]+)/transactions$#',$route,$m)){
    $user=mobileUser(); mobileClientAllowed($user,$m[1]);
    $s=db()->prepare("SELECT transaction_id,'savings' source,amount,type,date,balance_after,notes FROM saving_collections WHERE client_id=? UNION ALL SELECT transaction_id,'loan' source,amount_collected amount,type,date,remaining_balance balance_after,notes FROM loan_collections WHERE client_id=? ORDER BY date DESC LIMIT 200"); $s->execute([$m[1],$m[1]]);
    respond(['success'=>true,'data'=>$s->fetchAll()]);
}
if($method==='GET' && preg_match('#^portfolio/([^/]+)$#',$route,$m)){
    $user=mobileUser(); mobileClientAllowed($user,$m[1]); respond(['success'=>true,'data'=>portfolioData($m[1])]);
}