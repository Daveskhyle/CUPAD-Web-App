<?php
declare(strict_types=1);

function mobileUser(): array {
    $auth = requireJwt();
    $id = (int)($auth['sub'] ?? 0);
    $s = db()->prepare('SELECT id,username,role,branch_id,area_id,zone_id,status FROM users WHERE id=? AND status="active" LIMIT 1');
    $s->execute([$id]);
    $user = $s->fetch();
    if (!$user) respond(['success'=>false,'error'=>'User not found'],404);
    return $user;
}

function mobileScopeClause(array $user, string $alias='c'): array {
    $role = strtolower((string)$user['role']);
    $where = ["{$alias}.deleted_at IS NULL"];
    $params = [];
    if ($role === 'co') { $where[] = "{$alias}.officer_username=?"; $params[] = $user['username']; }
    elseif ($role === 'bm' && $user['branch_id'] !== null && $user['branch_id'] !== '') { $where[] = "{$alias}.branch_id=?"; $params[] = $user['branch_id']; }
    elseif ($role === 'am' && $user['area_id'] !== null && $user['area_id'] !== '') { $where[] = "{$alias}.branch_id IN (SELECT id FROM branches WHERE area_id=?)"; $params[] = $user['area_id']; }
    elseif (in_array($role,['zm','dzm','tm'],true) && $user['zone_id'] !== null && $user['zone_id'] !== '') { $where[] = "{$alias}.branch_id IN (SELECT id FROM branches WHERE zone_id=? OR area_id IN (SELECT id FROM areas WHERE zone_id=?))"; $params[] = $user['zone_id']; $params[] = $user['zone_id']; }
    return [implode(' AND ', $where), $params];
}

function mobileClientAllowed(array $user,string $clientId): void {
    [$scope,$params] = mobileScopeClause($user,'c');
    $s=db()->prepare("SELECT c.id FROM clients c WHERE c.id=? AND {$scope} LIMIT 1");
    array_unshift($params,$clientId); $s->execute($params);
    if (!$s->fetch()) respond(['success'=>false,'error'=>'Client not found or access denied'],403);
}

function mobileTxn(string $prefix): string { return $prefix.date('YmdHis').mt_rand(1000,9999); }

/* Profile updates accept both PUT and POST for compatibility with Apache/proxy configurations. */
if (in_array($method, ['PUT','POST'], true) && $route === 'profile') {
    $auth = requireJwt();
    $userId = (int)($auth['sub'] ?? 0);
    if ($userId <= 0) respond(['success'=>false,'error'=>'Invalid user session'],401);
    $b = jsonBody();
    $fullName = trim((string)($b['full_name'] ?? $b['name'] ?? ''));
    $email = trim((string)($b['email'] ?? ''));
    $currentPassword = (string)($b['current_password'] ?? '');
    $newPassword = (string)($b['new_password'] ?? '');
    $profilePic = trim((string)($b['profile_pic'] ?? ''));

    if ($fullName !== '' && mb_strlen($fullName) < 2) respond(['success'=>false,'error'=>'Name is too short'],422);
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) respond(['success'=>false,'error'=>'Enter a valid email address'],422);
    if ($newPassword !== '' && strlen($newPassword) < 6) respond(['success'=>false,'error'=>'New password must be at least 6 characters'],422);
    if ($newPassword !== '' && $currentPassword === '') respond(['success'=>false,'error'=>'Current password is required to change your password'],422);

    $pdo = db();
    $s = $pdo->prepare('SELECT id,name,full_name,email,password,profile_pic FROM users WHERE id=? AND status="active" LIMIT 1');
    $s->execute([$userId]);
    $current = $s->fetch();
    if (!$current) respond(['success'=>false,'error'=>'User not found'],404);

    if ($newPassword !== '' && !(password_verify($currentPassword, (string)$current['password']) || hash_equals((string)$current['password'], $currentPassword))) {
        respond(['success'=>false,'error'=>'Current password is incorrect'],422);
    }

    if ($email !== '' && strcasecmp($email, (string)$current['email']) !== 0) {
        $s = $pdo->prepare('SELECT id FROM users WHERE LOWER(email)=LOWER(?) AND id<>? LIMIT 1');
        $s->execute([$email, $userId]);
        if ($s->fetch()) respond(['success'=>false,'error'=>'That email address is already in use'],409);
    }

    $savedPic = null;
    if ($profilePic !== '') {
        if (!preg_match('#^data:image/(jpeg|jpg|png|webp);base64,#i', $profilePic, $m)) respond(['success'=>false,'error'=>'Unsupported profile picture format'],422);
        $encoded = preg_replace('#^data:image/[^;]+;base64,#i', '', $profilePic);
        $raw = base64_decode((string)$encoded, true);
        if ($raw === false || strlen($raw) > 2 * 1024 * 1024) respond(['success'=>false,'error'=>'Profile picture must be 2MB or smaller'],422);
        $dir = __DIR__ . '/uploads/profile';
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) respond(['success'=>false,'error'=>'Unable to prepare profile picture storage'],500);
        $ext = in_array(strtolower($m[1]), ['jpeg','jpg'], true) ? 'jpg' : strtolower($m[1]);
        $filename = 'user_' . $userId . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
        if (file_put_contents($dir . '/' . $filename, $raw) === false) respond(['success'=>false,'error'=>'Unable to save profile picture'],500);
        $savedPic = '/api/v1/uploads/profile/' . $filename;
    }

    $sets = [];$params = [];
    if ($fullName !== '') { $sets[] = 'name=?'; $params[] = $fullName; $sets[] = 'full_name=?'; $params[] = $fullName; }
    if ($email !== '') { $sets[] = 'email=?'; $params[] = $email; }
    if ($newPassword !== '') { $sets[] = 'password=?'; $params[] = password_hash($newPassword, PASSWORD_DEFAULT); }
    if ($savedPic !== null) { $sets[] = 'profile_pic=?'; $params[] = $savedPic; }
    if (!$sets) respond(['success'=>false,'error'=>'No profile changes supplied'],422);
    $params[] = $userId;
    $pdo->prepare('UPDATE users SET ' . implode(',', $sets) . ' WHERE id=?')->execute($params);
    $s = $pdo->prepare('SELECT id,username,name,full_name,email,phone,role,branch_id,area_id,zone_id,profile_pic,status,last_login FROM users WHERE id=? LIMIT 1');
    $s->execute([$userId]);
    $updated = $s->fetch();
    if ($updated && !empty($updated['profile_pic']) && str_starts_with((string)$updated['profile_pic'], '/')) {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $updated['profile_pic'] = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? '') . $updated['profile_pic'];
    }
    respond(['success'=>true,'data'=>$updated,'message'=>'Profile updated successfully']);
}

/* Dashboard statistics intentionally mirror co/dashboard.php exactly. */
if ($method === 'GET' && $route === 'dashboard/stats') {
    $user=mobileUser();$username=$user['username'];$pdo=db();$currentMonth=date('Y-m');
    $scalar=static function(string $sql,array $params=[])use($pdo){$s=$pdo->prepare($sql);$s->execute($params);return $s->fetchColumn();};
    $clients=(int)$scalar("SELECT COUNT(*) FROM clients WHERE officer_username=? AND status='active'",[$username]);
    $netSavingsMonth=(float)$scalar("SELECT COALESCE(SUM(CASE WHEN amount < 0 OR LOWER(type) IN ('withdrawal','return','adjust') THEN -ABS(amount) ELSE amount END),0) FROM saving_collections WHERE officer=? AND DATE_FORMAT(date,'%Y-%m')=?",[$username,$currentMonth]);
    $monthlyDisbursed=(float)$scalar("SELECT COALESCE(SUM(principal),0) FROM disbursements WHERE officer=? AND DATE_FORMAT(date,'%Y-%m')=?",[$username,$currentMonth]);
    $activeLoans=(int)$scalar("SELECT COUNT(DISTINCT client_id) FROM disbursements WHERE officer=? AND status!='completed' AND remaining_balance>0",[$username]);
    $sqlUnion="SELECT c.id,c.`union`,(SELECT balance FROM saving_balances WHERE client_id=c.id LIMIT 1) AS total_savings,COALESCE(l.active_balance,0) AS loan_balance FROM clients c LEFT JOIN (SELECT client_id,SUM(remaining_balance) AS active_balance FROM disbursements WHERE status!='completed' AND remaining_balance>0 GROUP BY client_id) l ON c.id=l.client_id WHERE c.officer_username=? AND c.status='active'";
    $stmt=$pdo->prepare($sqlUnion);$stmt->execute([$username]);$grandSavings=0.0;$grandLoans=0.0;$unionStats=[];
    while($row=$stmt->fetch(PDO::FETCH_ASSOC)){$savings=(float)($row['total_savings']??0);$loans=(float)($row['loan_balance']??0);$grandSavings+=$savings;$grandLoans+=$loans;$name=trim((string)($row['union']??''));$name=$name===''?'Unassigned':ucwords(strtolower($name));if(!isset($unionStats[$name]))$unionStats[$name]=['name'=>$name,'clients'=>0,'savings'=>0,'loans'=>0];$unionStats[$name]['clients']++;$unionStats[$name]['savings']+=$savings;$unionStats[$name]['loans']+=$loans;}
    ksort($unionStats,SORT_NATURAL|SORT_FLAG_CASE);$unions=array_values($unionStats);
    $collectedToday=(float)$scalar("SELECT COALESCE(SUM(amount_collected),0) FROM loan_collections WHERE officer=? AND DATE(date)=CURDATE()",[$username]);
    $collectedMonth=(float)$scalar("SELECT COALESCE(SUM(amount_collected),0) FROM loan_collections WHERE officer=? AND DATE_FORMAT(date,'%Y-%m')=?",[$username,$currentMonth]);
    $savingsToday=(float)$scalar("SELECT COALESCE(SUM(amount),0) FROM saving_collections WHERE officer=? AND type='deposit' AND DATE(date)=CURDATE()",[$username]);
    respond(['success'=>true,'data'=>['monthly_net_savings'=>$netSavingsMonth,'monthly_disbursed'=>$monthlyDisbursed,'active_loans'=>$activeLoans,'total_savings'=>$grandSavings,'total_loans_outstanding'=>$grandLoans,'portfolio_net'=>$grandSavings-$grandLoans,'clients'=>$clients,'savings_today'=>$savingsToday,'collected_today'=>$collectedToday,'collected_month'=>$collectedMonth,'net_savings_month'=>$netSavingsMonth,'outstanding'=>$grandLoans,'unions'=>$unions]]);
}

if ($method === 'GET' && $route === 'activities') {
    $user=mobileUser();$limit=min(100,max(1,(int)($_GET['limit']??7)));
    $sql="SELECT type,client_name,amount,date FROM (SELECT 'Saving' AS type,c.name AS client_name,s.amount,s.date FROM saving_collections s JOIN clients c ON s.client_id=c.id WHERE s.officer=? AND s.type='deposit' UNION ALL SELECT 'Withdrawal' AS type,c.name AS client_name,s.amount,s.date FROM saving_collections s JOIN clients c ON s.client_id=c.id WHERE s.officer=? AND s.type='withdrawal' UNION ALL SELECT 'Payment' AS type,c.name AS client_name,p.amount_collected AS amount,p.date FROM loan_collections p JOIN clients c ON p.client_id=c.id WHERE p.officer=? UNION ALL SELECT 'Disbursement' AS type,c.name AS client_name,d.principal AS amount,d.created_at AS date FROM disbursements d JOIN clients c ON d.client_id=c.id WHERE d.officer=?) activities ORDER BY date DESC LIMIT $limit";
    try{$s=db()->prepare($sql);$s->execute([$user['username'],$user['username'],$user['username'],$user['username']]);respond(['success'=>true,'data'=>$s->fetchAll()]);}catch(Throwable $e){respond(['success'=>false,'error'=>'Unable to load activity history'],500);}
}

/* Mobile savings collection now mirrors co/saving_collection.php: update both the savings account and saving_balances atomically. */
if ($method === 'POST' && $route === 'savings/collect') {
    $user=mobileUser();$b=jsonBody();$clientId=trim((string)($b['client_id']??''));$amount=(float)($b['amount']??0);
    if($clientId===''||$amount<=0)respond(['success'=>false,'error'=>'client_id and positive amount required'],422);
    mobileClientAllowed($user,$clientId);
    $pdo=db();
    $paymentDate=trim((string)($b['date']??date('Y-m-d')));
    $ts=strtotime($paymentDate);$paymentDate=$ts?date('Y-m-d',$ts):date('Y-m-d');
    try{
        $pdo->beginTransaction();
        $dup=$pdo->prepare("SELECT transaction_id FROM saving_collections WHERE client_id=? AND type='deposit' AND DATE(date)=? LIMIT 1 FOR UPDATE");
        $dup->execute([$clientId,$paymentDate]);
        if($dup->fetchColumn())throw new RuntimeException('A savings transaction already exists for this client on the selected date.');

        // Source of truth used by co/saving_collection.php: savings.balance.
        $acct=$pdo->prepare("SELECT id,balance FROM savings WHERE client_id=? AND status='active' ORDER BY created_at ASC LIMIT 1 FOR UPDATE");
        $acct->execute([$clientId]);
        $acctRow=$acct->fetch(PDO::FETCH_ASSOC);
        $old=(float)($acctRow['balance']??0);
        $new=$old+$amount;

        $savingId=$acctRow['id']??null;
        if($savingId){
            $pdo->prepare("UPDATE savings SET balance=?,updated_at=NOW() WHERE id=?")->execute([$new,$savingId]);
        }else{
            $savingId='SVG-'.strtoupper(bin2hex(random_bytes(16)));
            $pdo->prepare("INSERT INTO savings (id,client_id,officer,balance,status,created_at) VALUES (?,?,?,?,'active',NOW())")->execute([$savingId,$clientId,$user['username'],$new]);
        }

        $txn=mobileTxn('SV');
        $ins=$pdo->prepare("INSERT INTO saving_collections (transaction_id,client_id,savings_id,amount,type,date,officer,balance_after,notes,created_at) VALUES (?,?,?,?,?,?,?,?,?,NOW())");
        $ins->execute([$txn,$clientId,$savingId,$amount,'deposit',$paymentDate.' '.date('H:i:s'),$user['username'],$new,'']);

        // Keep the balance cache/table used by the mobile Combined API synchronized too.
        $bal=$pdo->prepare('SELECT id FROM saving_balances WHERE client_id=? ORDER BY id LIMIT 1 FOR UPDATE');
        $bal->execute([$clientId]);
        $balId=$bal->fetchColumn();
        if($balId){
            $pdo->prepare('UPDATE saving_balances SET balance=?,last_updated=NOW() WHERE id=?')->execute([$new,$balId]);
        }else{
            $pdo->prepare('INSERT INTO saving_balances(client_id,balance,last_updated) VALUES(?,?,NOW())')->execute([$clientId,$new]);
        }
        $pdo->commit();
        respond(['success'=>true,'transaction_id'=>$txn,'balance'=>$new,'message'=>'Savings collected']);
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        $message=$e instanceof RuntimeException?$e->getMessage():'Unable to record savings collection';
        respond(['success'=>false,'error'=>$message],$e instanceof RuntimeException?409:500);
    }
}

if ($method === 'POST' && $route === 'savings/withdraw') {
    $user=mobileUser();$b=jsonBody();$clientId=trim((string)($b['client_id']??''));$amount=(float)($b['amount']??0);$notes=trim((string)($b['notes']??$b['reason']??''));
    if($clientId===''||$amount<=0)respond(['success'=>false,'error'=>'client_id and positive amount required'],422);mobileClientAllowed($user,$clientId);$txn=mobileTxn('WD');$pdo=db();
    try{$pdo->beginTransaction();$s=$pdo->prepare("SELECT COALESCE(SUM(balance),0) FROM savings WHERE client_id=? AND status<>'closed'");$s->execute([$clientId]);if((float)$s->fetchColumn()<$amount){$pdo->rollBack();respond(['success'=>false,'error'=>'Insufficient savings balance'],422);}$s=$pdo->prepare("INSERT INTO saving_collections (client_id,amount,type,date,officer,notes,transaction_id) VALUES (?,?,'withdrawal',NOW(),?,?,?)");$s->execute([$clientId,-$amount,$user['username'],$notes,$txn]);try{$pdo->prepare("UPDATE savings SET balance=balance-? WHERE client_id=? AND status<>'closed'")->execute([$amount,$clientId]);}catch(Throwable $e){}$pdo->commit();respond(['success'=>true,'transaction_id'=>$txn,'message'=>'Withdrawal recorded']);}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();respond(['success'=>false,'error'=>'Unable to record withdrawal'],500);}
}

if ($method === 'POST' && $route === 'loans/collect') {
    $user=mobileUser();$b=jsonBody();$clientId=trim((string)($b['client_id']??''));$amount=(float)($b['amount']??0);$notes=trim((string)($b['notes']??''));$loanId=$b['loan_id']??null;
    if($clientId===''||$amount<=0)respond(['success'=>false,'error'=>'client_id and positive amount required'],422);mobileClientAllowed($user,$clientId);$pdo=db();$txn=mobileTxn('LP');
    try{$pdo->beginTransaction();if($loanId){$s=$pdo->prepare('SELECT remaining_balance FROM disbursements WHERE id=? AND client_id=? FOR UPDATE');$s->execute([$loanId,$clientId]);$remaining=(float)$s->fetchColumn();}else{$s=$pdo->prepare('SELECT id,remaining_balance FROM disbursements WHERE client_id=? AND remaining_balance>0 ORDER BY date DESC LIMIT 1 FOR UPDATE');$s->execute([$clientId]);$loan=$s->fetch();$loanId=$loan['id']??null;$remaining=(float)($loan['remaining_balance']??0);}if(!$loanId){$pdo->rollBack();respond(['success'=>false,'error'=>'No active loan found'],422);}if($amount>$remaining){$pdo->rollBack();respond(['success'=>false,'error'=>'Payment exceeds remaining balance'],422);}$new=max(0,$remaining-$amount);$s=$pdo->prepare("INSERT INTO loan_collections (client_id,amount_collected,type,date,officer,notes,transaction_id,remaining_balance) VALUES (?,?,'repayment',NOW(),?,?,?,?)");$s->execute([$clientId,$amount,$user['username'],$notes,$txn,$new]);$pdo->prepare('UPDATE disbursements SET remaining_balance=?,status=? WHERE id=?')->execute([$new,$new<=0?'completed':'active',$loanId]);$pdo->commit();respond(['success'=>true,'transaction_id'=>$txn,'remaining_balance'=>$new,'message'=>'Loan payment recorded']);}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();respond(['success'=>false,'error'=>'Unable to record loan payment'],500);}
}

if ($method === 'POST' && $route === 'loans/disburse') {
    $user=mobileUser();$b=jsonBody();$clientId=trim((string)($b['client_id']??''));$principal=(float)($b['principal']??0);$interest=(float)($b['interest_rate']??0);$installments=max(1,(int)($b['num_installments']??12));$term=in_array($b['loan_term_type']??'', ['daily','weekly','monthly'],true)?$b['loan_term_type']:'weekly';
    if($clientId===''||$principal<=0)respond(['success'=>false,'error'=>'client_id and principal required'],422);mobileClientAllowed($user,$clientId);if($interest<0)respond(['success'=>false,'error'=>'Invalid interest rate'],422);$total=$principal*(1+$interest/100);
    try{$pdo=db();$s=$pdo->prepare("INSERT INTO disbursements (client_id,principal,interest_rate,total_payable,remaining_balance,num_installments,loan_term_type,date,officer,status) VALUES (?,?,?,?,?,?,?,CURDATE(),?,'active')");$s->execute([$clientId,$principal,$interest,$total,$total,$installments,$term,$user['username']]);respond(['success'=>true,'disbursement_id'=>(int)$pdo->lastInsertId(),'total_payable'=>$total,'message'=>'Loan disbursed']);}catch(Throwable $e){respond(['success'=>false,'error'=>'Unable to record loan disbursement'],500);}
}

if ($method === 'POST' && $route === 'clients/register') {
    $user=mobileUser();$b=jsonBody();$name=trim((string)($b['name']??''));$phone=trim((string)($b['phone']??''));$email=trim((string)($b['email']??''));$type=in_array($b['client_type']??'', ['individual','group'],true)?$b['client_type']:'individual';
    if($name===''||$phone==='')respond(['success'=>false,'error'=>'name and phone required'],422);$id='C'.date('ymd').mt_rand(10000,99999);
    try{$s=db()->prepare("INSERT INTO clients (id,name,phone,email,branch_id,officer_username,client_type,status,date_registered) VALUES (?,?,?,?,?,?,?,'active',CURDATE())");$s->execute([$id,$name,$phone,$email?:null,$user['branch_id']??null,$user['username'],$type]);respond(['success'=>true,'client_id'=>$id,'message'=>'Client registered']);}catch(Throwable $e){respond(['success'=>false,'error'=>'Unable to register client'],500);}
}