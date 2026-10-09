<?php
// Offline SendSms mocks only. Optional --mysql requires the isolated acceptance database.
if (PHP_SAPI !== 'cli') exit(1);
$mysql = in_array('--mysql', $argv, true) || (($argv[1] ?? '') === '--worker-mysql');
if ($mysql) {
    $configuration = [];
    foreach (['HOST','PORT','NAME','USER','PASSWORD'] as $key) $configuration[$key] = getenv('YUZHOU_MYSQL_TEST_DB_' . $key);
    if ($configuration['NAME'] !== 'yuzhou_acceptance_20261009' || !$configuration['HOST'] || !$configuration['USER'] || !$configuration['PASSWORD']
        || !preg_match('/^[A-Za-z0-9._:-]{1,200}$/D', $configuration['HOST']) || !ctype_digit((string)$configuration['PORT'])) {
        fwrite(STDERR, "SMS MySQL test configuration rejected. No database work performed.\n"); exit(1);
    }
    foreach ($configuration as $key => $value) putenv('DB_' . ($key === 'NAME' ? 'DATABASE' : ($key === 'USER' ? 'USERNAME' : $key)) . '=' . $value);
    putenv('DB_DRIVER=mysql');
}
putenv('APP_ENV=test');
foreach (['ENABLED'=>'1','ACCESS_KEY_ID'=>'SyntheticSmsAccessId','ACCESS_KEY_SECRET'=>'SyntheticSmsAccessSecretOnly',
    'SIGN_NAME'=>'SyntheticSmsSign','TEMPLATE_CODE'=>'SMS_SYNTHETIC','CODE_PARAM'=>'code','HMAC_KEY'=>str_repeat('synthetic-hmac-',4),'SECURITY_TOKEN'=>''] as $key=>$value) putenv('ALI_SMS_'.$key.'='.$value);
require_once __DIR__ . '/../../backend/extend/yuzhou/bootstrap.php';
use yuzhou\AliSms;
use yuzhou\Auth;
use yuzhou\Api;
use yuzhou\ApiError;
use yuzhou\Database;

$checks=0;
function smsCheck($ok,$label) { global $checks; if (!$ok) throw new RuntimeException($label); $checks++; }
function smsReject($callback,$status,$code,$label) {
    try {$callback();} catch(ApiError $e) {smsCheck($e->status===$status && $e->getCode()===$code,$label); return;}
    throw new RuntimeException($label);
}
class OfflineSmsAuth extends Auth {
    private $scope;
    public function __construct(Database $db,AliSms $sms,$scope) {parent::__construct($db,$sms);$this->scope=$scope.'|';}
    public function smsSend(array $body,$ip,array $user=null) {return parent::smsSend($body,$this->scope.$ip,$user);}
    public function smsVerify(array $body,$ip,array $user=null) {return parent::smsVerify($body,$this->scope.$ip,$user);}
}
class SmsGapDatabase extends Database {
    private $barrier;private $index;private $armed=true;
    public function __construct(PDO $pdo,$barrier,$index) {parent::__construct($pdo);$this->barrier=$barrier;$this->index=$index;}
    public function one($sql,array $params=[]) {
        $result=parent::one($sql,$params);
        if($this->armed&&strpos($sql,'SELECT * FROM phone_identities WHERE phone_hash=')===0) {
            $this->armed=false;file_put_contents($this->barrier.'.gap'.$this->index,'ready');$deadline=microtime(true)+12;
            while(!is_file($this->barrier.'.gap'.(1-$this->index))){if(microtime(true)>$deadline)throw new RuntimeException('SMS gap barrier timed out');usleep(1000);}
        }
        return $result;
    }
}
// Isolated SQLite rollback probe emulates only the two MySQL statements used by SMS verification.
class SmsRollbackProbe extends Database {
    public $remaining=0;public $faults=0;public $number=1213;
    public function __construct() {parent::__construct(new PDO('sqlite::memory:'));$this->driver='mysql';}
    public function run($sql,array $params=[]) {
        $sql=str_replace(' FOR UPDATE','',$sql);
        if(strpos($sql,' ON DUPLICATE KEY UPDATE updated_at=VALUES(updated_at)')!==false) {
            $sql=str_replace('INSERT INTO sms_rate_limits','INSERT OR IGNORE INTO sms_rate_limits',str_replace(' ON DUPLICATE KEY UPDATE updated_at=VALUES(updated_at)','',$sql));
            $statement=parent::run($sql,$params);parent::run('UPDATE sms_rate_limits SET updated_at=? WHERE bucket_key=?',[$params[2],$params[0]]);return $statement;
        }
        return parent::run($sql,$params);
    }
    public function insert($table,array $data) {
        $id=parent::insert($table,$data);
        if($table==='tokens'&&$this->remaining>0){$this->remaining--;$this->faults++;$error=new PDOException('Synthetic database rollback probe');$error->errorInfo=['40001',$this->number,'synthetic'];throw $error;}
        return $id;
    }
}
function smsDb($file=null) {
    global $mysql;
    if ($mysql) {
        $db=Database::connect();
        if ($db->driver!=='mysql'||$db->scalar('SELECT DATABASE()')!=='yuzhou_acceptance_20261009') throw new RuntimeException('Unsafe database rejected');
        $db->run('SET SESSION innodb_lock_wait_timeout=10'); return $db;
    }
    return new Database(new PDO('sqlite:'.($file ?: ':memory:')));
}
function smsUser(Database $db,$tag,$role='consumer',$org=null) {
    $id=$db->insert('users',['username'=>$tag,'password_hash'=>password_hash('Synthetic-Sms-Only-Password',PASSWORD_DEFAULT), 'nickname'=>'Synthetic SMS account',
        'role'=>$role,'org_id'=>$org,'status'=>'active','member_until'=>0,'growth_points'=>0,'identity_status'=>'unverified','created_at'=>time(),'updated_at'=>time()]);
    return $db->one('SELECT * FROM users WHERE id=?',[$id]);
}
function smsHash($purpose,$value) { return hash_hmac('sha256',$purpose.'|'.$value,getenv('ALI_SMS_HMAC_KEY')); }
function smsPhone() { return '+86139'.str_pad((string)random_int(0,99999999),8,'0',STR_PAD_LEFT); }
function smsPhonesInSameGap(Database $db) {
    $largest=$db->scalar('SELECT MAX(phone_hash) FROM phone_identities')?:str_repeat('0',64);$phones=[];
    for($i=0;$i<100000&&count($phones)<2;$i++){$phone=smsPhone();if(strcmp(smsHash('phone',$phone),$largest)>0&&!in_array($phone,$phones,true))$phones[]=$phone;}
    if(count($phones)!==2)throw new RuntimeException('Synthetic same-gap fixture generation failed');return $phones;
}
function smsChallenge(Database $db,$phone,$purpose,$code,$user=null) {
    $challenge=bin2hex(random_bytes(32)); $hash=smsHash('phone',$phone);
    $db->insert('sms_challenges',['challenge_id'=>$challenge,'phone_hash'=>$hash,'purpose'=>$purpose,'code_hash'=>smsHash('code',$challenge.'|'.$purpose.'|'.$hash.'|'.$code),
        'status'=>'accepted','attempts'=>0,'expires_at'=>time()+300,'created_at'=>time(),'user_id'=>$user]);
    return ['challenge_id'=>$challenge,'phone'=>$phone,'purpose'=>$purpose,'code'=>$code];
}
function smsConcurrent($mode,array $state,$directory) {
    global $mysql;
    $state['barrier']=$directory.'/'.$mode; $file=$state['barrier'].'.json';
    file_put_contents($file,json_encode($state));chmod($file,0600);$children=[];
    for($i=0;$i<2;$i++) {
        $command=escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__).' '.($mysql?'--worker-mysql':'--worker').' '.escapeshellarg($file).' '.$i;
        $pipes=[];$process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if(!is_resource($process)) throw new RuntimeException('Concurrent SMS worker failed to start');
        fclose($pipes[0]);$children[]=[$process,$pipes];
    }
    $deadline=microtime(true)+15;
    while(!is_file($state['barrier'].'.ready0')||!is_file($state['barrier'].'.ready1')) {
        if(microtime(true)>$deadline){foreach($children as $child)proc_terminate($child[0]);throw new RuntimeException('SMS workers timed out');}usleep(10000);
    }
    file_put_contents($state['barrier'].'.go','go');$codes=[];
    foreach($children as $child){stream_get_contents($child[1][1]);$diagnostic=trim(stream_get_contents($child[1][2]));
        if(preg_match('/^SMS worker error: [A-Za-z0-9_\\\\]+ [A-Z0-9]{1,5}$/D',$diagnostic))fwrite(STDERR,$diagnostic.PHP_EOL);
        fclose($child[1][1]);fclose($child[1][2]);$codes[]=proc_close($child[0]);}
    sort($codes);return $codes;
}

if(in_array($argv[1]??'',['--worker','--worker-mysql'],true)) {
    try {
        $state=json_decode(file_get_contents($argv[2]),true);$index=(int)$argv[3];$db=smsDb($state['sqlite_file']);
        if($mysql&&!empty($state['gap_barrier']))$db=new SmsGapDatabase($db->pdo,$state['barrier'],$index);
        $auth=new Auth($db,new AliSms(function(){return ['status'=>200,'body'=>'{"Code":"OK","RequestId":"synthetic-request","BizId":"synthetic-biz"}'];}));
        file_put_contents($state['barrier'].'.ready'.$index,'ready');$deadline=microtime(true)+15;
        while(!is_file($state['barrier'].'.go')){if(microtime(true)>$deadline)exit(6);usleep(10000);}
        // No worker-level retry: this must exercise the same transaction behavior as the HTTP endpoint.
        {
            if($state['mode']==='send')$auth->smsSend(['phone'=>$state['phone'],'purpose'=>'register'],$state['barrier'].'|synthetic-worker-'.$index);
            else {
                $body=$state['bodies'][$state['mode']==='once'?0:$index];
                $actor=$state['mode']==='bind'?$db->one('SELECT * FROM users WHERE id=?',[$state['actors'][$index]]):null;
                $auth->smsVerify($body,$state['barrier'].'|synthetic-worker-'.$index,$actor);
            }
            exit(0);
        }
    }catch(ApiError $e){exit(in_array($e->getCode(),[40103,40903,42903],true)?2:3);}
    catch(Throwable $e){fwrite(STDERR,'SMS worker error: '.get_class($e).' '.preg_replace('/[^A-Z0-9]/','',(string)$e->getCode()).PHP_EOL);exit(4);}
    exit(4);
}

$directory=sys_get_temp_dir().'/yuzhou-sms-'.bin2hex(random_bytes(8));mkdir($directory,0700);$file=$directory.'/synthetic.sqlite';$stage='schema';
try {
    $db=smsDb($file);
    if(!$mysql) {
        $db->pdo->exec(file_get_contents(__DIR__.'/../../database/sqlite.sql'));
        $db->pdo->exec(file_get_contents(__DIR__.'/../../database/communication-sqlite.sql'));
        foreach(glob(__DIR__.'/../../database/migrations/*-sqlite.sql') as $migration)$db->pdo->exec(file_get_contents($migration));
    }
    $tag='sms'.bin2hex(random_bytes(8));$phone=smsPhone();$otherPhone=smsPhone();
    $stage='official fixed signature';
    $signed=AliSms::sign(['host'=>'ecs.cn-shanghai.aliyuncs.com','x-acs-action'=>'RunInstances','x-acs-version'=>'2014-05-26',
        'x-acs-date'=>'2023-10-26T10:22:32Z','x-acs-signature-nonce'=>'3156853299f313e23d1673dc12e1703d'],
        ['RegionId'=>'cn-shanghai','ImageId'=>'win2019_1809_x64_dtc_zh-cn_40G_alibase_20230811.vhd'],'','YourAccessKeyId','YourAccessKeySecret');
    smsCheck($signed['canonical_hash']==='7ea06492da5221eba5297e897ce16e55f964061054b7695beedaac1145b1e259','Official canonical SHA256');
    smsCheck($signed['signature']==='06563a9e1b43f5dfe96b81484da74bceab24a1d853912eee15083a6f0f3283c0','Official HMAC signature');
    smsCheck($signed['headers']['authorization']==='ACS3-HMAC-SHA256 Credential=YourAccessKeyId,SignedHeaders=host;x-acs-action;x-acs-content-sha256;x-acs-date;x-acs-signature-nonce;x-acs-version,Signature=06563a9e1b43f5dfe96b81484da74bceab24a1d853912eee15083a6f0f3283c0','Official Authorization');
    $captured=[];$reply=['status'=>200,'body'=>'{"Code":"OK","RequestId":"synthetic-request","BizId":"synthetic-biz"}'];
    $transport=function($request)use(&$captured,&$reply){parse_str(parse_url($request['url'],PHP_URL_QUERY),$parameters);$captured[]=['request'=>$request,'parameters'=>$parameters];if($reply instanceof Throwable)throw $reply;return $reply;};
    $adapter=new AliSms($transport);$auth=new OfflineSmsAuth($db,$adapter,$tag);
    $stage='configured service and mock HTTP';putenv('ALI_SMS_ENABLED=0');
    smsReject(function()use($auth,$phone){$auth->smsSend(['phone'=>$phone,'purpose'=>'register'],'synthetic-ip');},503,50311,'Disabled service refuses send');
    smsCheck(!$captured&&(int)$db->scalar('SELECT COUNT(*) FROM sms_challenges WHERE phone_hash=?',[smsHash('phone',$phone)])===0,'Unconfigured service creates no challenge');
    putenv('ALI_SMS_ENABLED=1');
    putenv('ALI_SMS_HMAC_KEY='.str_repeat('x',32)."\n");smsCheck(!AliSms::configured(),'HMAC secret rejects control characters');putenv('ALI_SMS_HMAC_KEY='.str_repeat('synthetic-hmac-',4));
    smsReject(function()use($auth){$auth->smsSend(['phone'=>'invalid','purpose'=>'register'],'synthetic-ip');},422,422,'Invalid phone rejected');
    smsReject(function()use($auth,$phone){$auth->smsSend(['phone'=>$phone,'purpose'=>'reset'],'synthetic-ip');},422,422,'Unknown purpose rejected');
    $sent=$auth->smsSend(['phone'=>$phone,'purpose'=>'register'],'synthetic-ip');$code=json_decode($captured[0]['parameters']['TemplateParam'],true)['code'];
    smsCheck($sent['delivery_status']==='accepted'&&!isset($sent['phone'],$sent['code'])&&preg_match('/^[0-9]{6}$/D',$code),'Response reports acceptance without private values');
    $request=$captured[0]['request'];smsCheck(strpos($request['url'],'https://dysmsapi.aliyuncs.com/?')===0&&$request['body']===''&&$request['headers']['x-acs-action']==='SendSms'&&$request['headers']['x-acs-version']==='2017-05-25','Official HTTPS SendSms request');
    smsCheck($captured[0]['parameters']['PhoneNumbers']===substr($phone,3)&&$captured[0]['parameters']['TemplateCode']==='SMS_SYNTHETIC','Normalized phone and dedicated template');
    $row=$db->one('SELECT * FROM sms_challenges WHERE challenge_id=?',[$sent['challenge_id']]);
    smsCheck($row['code_hash']===smsHash('code',$sent['challenge_id'].'|register|'.smsHash('phone',$phone).'|'.$code)&&$row['code_hash']!==hash('sha256',$code)&&$row['phone_hash']!==$phone,'Only keyed hashes persisted');
    smsCheck($row['status']==='accepted'&&$row['provider_biz_id']==='synthetic-biz'&&$row['provider_request_id']==='synthetic-request','Provider acceptance receipt persisted');
    smsReject(function()use($auth,$phone){$auth->smsSend(['phone'=>$phone,'purpose'=>'login'],'another-ip');},429,42903,'Phone cooldown applies across purposes and IPs');
    $stage='atomic registration and identity';$body=$sent+['phone'=>$phone,'code'=>$code];unset($body['expires_in'],$body['resend_after'],$body['delivery_status']);
    $wrong=$body;$wrong['code']=$code==='000000'?'000001':'000000';
    smsReject(function()use($auth,$wrong){$auth->smsVerify($wrong,'synthetic-ip');},401,40103,'Wrong code rejected');
    smsCheck((int)$db->scalar('SELECT attempts FROM sms_challenges WHERE challenge_id=?',[$sent['challenge_id']])===1,'Wrong attempt commits despite error');
    $passwordOwner=smsUser($db,substr($phone,3),'platform');
    smsReject(function()use($auth,$body){$auth->smsVerify($body+['username'=>'someone','role'=>'platform'],'synthetic-ip');},422,422,'Cannot assign an account or role via OTP');
    $registered=$auth->smsVerify($body+['nickname'=>'Synthetic verified mobile user'],'synthetic-ip');
    smsCheck($registered['user']['role']==='consumer'&&$registered['user']['org_id']===null&&$registered['user']['identity_status']==='unverified'&&$registered['user']['id']!==(int)$passwordOwner['id'],'Verified mobile registration creates only a new consumer');
    smsCheck($registered['user']['phone_bound']===true&&(new Api($db))->dispatch('GET','auth/me',[],[],$registered['token'])['phone_bound']===true,'Login and auth/me expose bound flag only');
    smsCheck((int)$db->scalar('SELECT COUNT(*) FROM phone_identities WHERE phone_hash=?',[smsHash('phone',$phone)])===1,'One verified phone identity exists');
    $tokens=(int)$db->scalar('SELECT COUNT(*) FROM tokens WHERE user_id=?',[$registered['user']['id']]);
    smsReject(function()use($auth,$body){$auth->smsVerify($body,'synthetic-ip');},401,40103,'Successful code cannot replay');
    smsCheck((int)$db->scalar('SELECT COUNT(*) FROM tokens WHERE user_id=?',[$registered['user']['id']])===$tokens,'Replay issues no token');
    $login=smsChallenge($db,$phone,'login','000017');$session=(new Api($db))->dispatch('POST','auth/sms/verify',$login,[],null,[],'synthetic-login');
    smsCheck($session['user']['id']===$registered['user']['id'],'Leading-zero code logs into verified identity');
    $unknown=smsChallenge($db,$otherPhone,'login','123456');
    smsReject(function()use($auth,$unknown){$auth->smsVerify($unknown,'synthetic-login');},401,40104,'Unknown mobile login does not register or match username');
    $duplicate=smsChallenge($db,$phone,'register','123456');smsReject(function()use($auth,$duplicate){$auth->smsVerify($duplicate,'synthetic-ip');},409,40903,'Registration cannot duplicate phone identity');
    $stage='purpose expiry attempt cap';$boundConsumer=$db->one('SELECT * FROM users WHERE id=?',[$registered['user']['id']]);
    $purpose=smsChallenge($db,smsPhone(),'register','123456');$purpose['purpose']='login';
    smsReject(function()use($auth,$purpose){$auth->smsVerify($purpose,'synthetic-purpose');},401,40103,'Purpose mismatch rejects code');
    $wrongPhone=smsChallenge($db,smsPhone(),'register','123456');$wrongPhone['phone']=smsPhone();
    smsReject(function()use($auth,$wrongPhone){$auth->smsVerify($wrongPhone,'synthetic-wrong-phone');},401,40103,'Code cannot verify another phone');
    $expired=smsChallenge($db,smsPhone(),'register','123456');$db->run('UPDATE sms_challenges SET expires_at=? WHERE challenge_id=?',[time()-1,$expired['challenge_id']]);
    smsReject(function()use($auth,$expired){$auth->smsVerify($expired,'synthetic-expiry');},401,40103,'Expired code cannot register');
    $locked=smsChallenge($db,smsPhone(),'register','123456');$locked['code']='000000';
    for($i=0;$i<5;$i++)smsReject(function()use($auth,$locked,$i){$auth->smsVerify($locked,'synthetic-guess-'.$i);},401,40103,'Wrong guess rejected');
    $lockRow=$db->one('SELECT * FROM sms_challenges WHERE challenge_id=?',[$locked['challenge_id']]);
    smsCheck((int)$lockRow['attempts']===5&&$lockRow['status']==='locked','Attempt cap persists across IPs and thrown responses');
    $locked['code']='123456';smsReject(function()use($auth,$locked){$auth->smsVerify($locked,'synthetic-guess-correct');},401,40103,'Correct code cannot unlock exhausted challenge');
    $stage='authenticated binding';$org=$db->insert('organizations',['name'=>$tag.' agency','kind'=>'agency','status'=>'approved','payload'=>'{}','created_at'=>time()]);
    $broker=smsUser($db,$tag.'-broker','broker',$org);$outsider=smsUser($db,$tag.'-outsider');$bindPhone=smsPhone();
    smsReject(function()use($auth,$bindPhone){$auth->smsSend(['phone'=>$bindPhone,'purpose'=>'bind'],'synthetic-bind');},401,401,'Anonymous binding send refused');
    $bind=smsChallenge($db,$bindPhone,'bind','234567',(int)$broker['id']);
    smsReject(function()use($auth,$bind,$outsider){$auth->smsVerify($bind,'synthetic-bind-other',$outsider);},403,40303,'Other user cannot consume binding challenge');
    smsReject(function()use($auth,$bind){$auth->smsVerify($bind,'synthetic-bind-anonymous');},401,401,'Anonymous binding verify refused');
    $ownSend=$auth->smsSend(['phone'=>smsPhone(),'purpose'=>'bind'],'synthetic-actor-send',$outsider);
    $ownCaptured=end($captured);$ownCode=json_decode($ownCaptured['parameters']['TemplateParam'],true)['code'];
    smsCheck((int)$db->scalar('SELECT user_id FROM sms_challenges WHERE challenge_id=?',[$ownSend['challenge_id']])===(int)$outsider['id'],'Binding send persists the authenticated owner');
    $expiredSession=$auth->login(['username'=>$outsider['username'],'password'=>'Synthetic-Sms-Only-Password'],$tag.'expired-session');
    $db->run('UPDATE tokens SET expires_at=? WHERE token_hash=?',[time()-1,hash('sha256',$expiredSession['token'])]);
    $ownVerify=['challenge_id'=>$ownSend['challenge_id'],'phone'=>'+86'.$ownCaptured['parameters']['PhoneNumbers'],'purpose'=>'bind','code'=>$ownCode];
    smsReject(function()use($db,$ownVerify,$expiredSession){(new Api($db))->dispatch('POST','auth/sms/verify',$ownVerify,[],$expiredSession['token']);},401,401,'Expired Bearer cannot bind even with correct code');
    $before=(int)$db->scalar('SELECT COUNT(*) FROM tokens WHERE user_id=?',[$broker['id']]);
    smsCheck($auth->smsVerify($bind,'synthetic-bind',$broker)===['bound'=>true]&&(int)$db->scalar('SELECT COUNT(*) FROM tokens WHERE user_id=?',[$broker['id']])===$before,'Binding changes no session or role');
    $brokerLogin=smsChallenge($db,$bindPhone,'login','123456');smsCheck($auth->smsVerify($brokerLogin,'synthetic-broker-login')['user']['role']==='broker','Bound existing broker can use SMS login');
    $steal=smsChallenge($db,$bindPhone,'bind','123456',(int)$outsider['id']);smsReject(function()use($auth,$steal,$outsider){$auth->smsVerify($steal,'synthetic-steal',$outsider);},409,40903,'Bound phone cannot move to another account');
    $replace=smsChallenge($db,smsPhone(),'bind','123456',(int)$broker['id']);smsReject(function()use($auth,$replace,$broker){$auth->smsVerify($replace,'synthetic-replace',$broker);},409,40903,'Existing phone cannot be automatically replaced');
    $confirm=smsChallenge($db,$bindPhone,'bind','123456',(int)$broker['id']);smsCheck($auth->smsVerify($confirm,'synthetic-confirm',$broker)===['bound'=>true],'Same verified phone can be confirmed');
    $db->run('UPDATE users SET status=? WHERE id=?',['suspended',$broker['id']]);$disabled=smsChallenge($db,$bindPhone,'login','123456');
    smsReject(function()use($auth,$disabled){$auth->smsVerify($disabled,'synthetic-disabled');},401,40104,'OTP does not reactivate suspended accounts');
    $db->run('UPDATE users SET status=? WHERE id=?',['active',$broker['id']]);$db->run('UPDATE organizations SET status=? WHERE id=?',['pending',$org]);
    $unapproved=smsChallenge($db,$bindPhone,'login','123456');smsReject(function()use($auth,$unapproved){$auth->smsVerify($unapproved,'synthetic-org');},403,40301,'OTP does not bypass organization approval');
    $invalidInvitation=smsChallenge($db,smsPhone(),'register','123456');$invalidInvitation['invitation_code']='UNKNOWN_SYNTHETIC';$usersBefore=(int)$db->scalar('SELECT COUNT(*) FROM users');
    smsReject(function()use($auth,$invalidInvitation){$auth->smsVerify($invalidInvitation,'synthetic-invitation');},422,422,'Unconfirmed invitation cannot create an account');
    smsCheck((int)$db->scalar('SELECT COUNT(*) FROM users')===$usersBefore&&$db->scalar('SELECT status FROM sms_challenges WHERE challenge_id=?',[$invalidInvitation['challenge_id']])==='consumed','Failed invitation consumes proof without creating an account');
    $stage='provider failure boundaries';
    foreach([
        [['status'=>400,'body'=>'{"Code":"isv.BUSINESS_LIMIT_CONTROL"}'],'failed',50312],
        [['status'=>200,'body'=>'invalid json'],'unknown',50313],
        [['status'=>500,'body'=>'{"Code":"OK","RequestId":"req","BizId":"biz"}'],'unknown',50313],
        [['status'=>200,'body'=>'{"Code":"OK","RequestId":"req"}'],'unknown',50313],
        [new RuntimeException('Synthetic transport interruption'),'unknown',50313]
    ] as $case) {
        $reply=$case[0];$failurePhone=smsPhone();$failureHash=smsHash('phone',$failurePhone);
        smsReject(function()use($auth,$failurePhone){$auth->smsSend(['phone'=>$failurePhone,'purpose'=>'register'],'synthetic-failure');},503,$case[2],'Non-accepted HTTP result rejected');
        smsCheck($db->scalar('SELECT status FROM sms_challenges WHERE phone_hash=?',[$failureHash])===$case[1],'Non-accepted status persisted');
        smsReject(function()use($auth,$failurePhone){$auth->smsSend(['phone'=>$failurePhone,'purpose'=>'register'],'synthetic-failure-other');},429,42903,'Unknown or rejected outcome retains cooldown');
    }
    $reply=['status'=>200,'body'=>'{"Code":"OK","RequestId":"synthetic-request","BizId":"synthetic-biz"}'];
    $stage='IP and phone limits';$blockedIP='synthetic-blocked-'.$tag;
    $db->insert('sms_rate_limits',['bucket_key'=>smsHash('rate','send-ip-hour|'.$tag.'|'.$blockedIP),'window_start'=>time(),'attempt_count'=>20,'updated_at'=>time()]);
    smsReject(function()use($auth,$blockedIP){$auth->smsSend(['phone'=>smsPhone(),'purpose'=>'register'],$blockedIP);},429,42903,'IP send limit enforced across numbers');
    $limitedPhone=smsPhone();$db->insert('sms_rate_limits',['bucket_key'=>smsHash('rate','phone-hour|'.smsHash('phone',$limitedPhone)),'window_start'=>time(),'attempt_count'=>5,'updated_at'=>time()]);
    smsReject(function()use($auth,$limitedPhone){$auth->smsSend(['phone'=>$limitedPhone,'purpose'=>'register'],'synthetic-number-limit');},429,42903,'Phone hourly limit enforced');
    $verifyIP='synthetic-verify-limited-'.$tag;$db->insert('sms_rate_limits',['bucket_key'=>smsHash('rate','verify-ip|'.$tag.'|'.$verifyIP),'window_start'=>time(),'attempt_count'=>30,'updated_at'=>time()]);
    $limited=smsChallenge($db,smsPhone(),'register','123456');smsReject(function()use($auth,$limited,$verifyIP){$auth->smsVerify($limited,$verifyIP);},429,42903,'IP verification limit enforced');
    $stage='concurrent one-time identity';$concurrent=smsChallenge($db,smsPhone(),'register','012345');
    $state=['mode'=>'once','bodies'=>[$concurrent],'sqlite_file'=>$file];
    smsCheck(smsConcurrent('once',$state,$directory)===[0,2],'Two simultaneous consumers cannot redeem one challenge');
    $created=(int)$db->scalar('SELECT user_id FROM phone_identities WHERE phone_hash=?',[smsHash('phone',$concurrent['phone'])]);
    smsCheck((int)$db->scalar('SELECT COUNT(*) FROM tokens WHERE user_id=?',[$created])===1,'One-time proof creates exactly one session');
    $wrongConcurrent=smsChallenge($db,smsPhone(),'register','012345');$wrongConcurrent['code']='999999';
    smsCheck(smsConcurrent('wrong',['mode'=>'wrong','bodies'=>[$wrongConcurrent,$wrongConcurrent],'sqlite_file'=>$file],$directory)===[2,2],'Two incorrect simultaneous verifications are rejected');
    smsCheck((int)$db->scalar('SELECT attempts FROM sms_challenges WHERE challenge_id=?',[$wrongConcurrent['challenge_id']])===2,'Concurrent failed attempts are both committed');
    $samePhone=smsPhone();$first=smsChallenge($db,$samePhone,'register','012345');$second=smsChallenge($db,$samePhone,'register','012345');
    smsCheck(smsConcurrent('identity',['mode'=>'identity','bodies'=>[$first,$second],'sqlite_file'=>$file],$directory)===[0,2],'Two valid challenges cannot create duplicate mobile identities');
    smsCheck((int)$db->scalar('SELECT COUNT(*) FROM phone_identities WHERE phone_hash=?',[smsHash('phone',$samePhone)])===1,'Phone identity remains unique under concurrent registration');
    $sameGap=smsPhonesInSameGap($db);
    smsCheck(smsConcurrent('different-register',['mode'=>'identity','bodies'=>[smsChallenge($db,$sameGap[0],'register','012345'),smsChallenge($db,$sameGap[1],'register','012345')],
        'sqlite_file'=>$file,'gap_barrier'=>true],$directory)===[0,0],'Different first-use phones in one unique-index gap can register without a caller retry');
    $bindPhone2=smsPhone();$userA=smsUser($db,$tag.'-bind-a');$userB=smsUser($db,$tag.'-bind-b');
    smsCheck(smsConcurrent('bind',['mode'=>'bind','bodies'=>[smsChallenge($db,$bindPhone2,'bind','012345',(int)$userA['id']),smsChallenge($db,$bindPhone2,'bind','012345',(int)$userB['id'])],
        'actors'=>[(int)$userA['id'],(int)$userB['id']],'sqlite_file'=>$file],$directory)===[0,2],'Same phone cannot concurrently bind to two users');
    $userC=smsUser($db,$tag.'-bind-c');
    smsCheck(smsConcurrent('bind-many',['mode'=>'bind','bodies'=>[smsChallenge($db,smsPhone(),'bind','012345',(int)$userC['id']),smsChallenge($db,smsPhone(),'bind','012345',(int)$userC['id'])],
        'actors'=>[(int)$userC['id'],(int)$userC['id']],'sqlite_file'=>$file],$directory)===[0,2],'One user cannot concurrently acquire two bound phones');
    $userD=smsUser($db,$tag.'-bind-d');$userE=smsUser($db,$tag.'-bind-e');
    $sameGap=smsPhonesInSameGap($db);
    smsCheck(smsConcurrent('different-bind',['mode'=>'bind','bodies'=>[smsChallenge($db,$sameGap[0],'bind','012345',(int)$userD['id']),smsChallenge($db,$sameGap[1],'bind','012345',(int)$userE['id'])],
        'actors'=>[(int)$userD['id'],(int)$userE['id']],'sqlite_file'=>$file,'gap_barrier'=>true],$directory)===[0,0],'Different users bind new phones in one unique-index gap without a caller retry');
    $sendPhone=smsPhone();smsCheck(smsConcurrent('send',['mode'=>'send','phone'=>$sendPhone,'sqlite_file'=>$file],$directory)===[0,2],'Concurrent sends share atomic phone cooldown');
    smsCheck((int)$db->scalar('SELECT COUNT(*) FROM sms_challenges WHERE phone_hash=?',[smsHash('phone',$sendPhone)])===1,'Concurrent send reserves only one challenge');
    $stage='foreign key';
    try{$db->insert('phone_identities',['user_id'=>-1,'phone_hash'=>smsHash('phone',smsPhone()),'verified_at'=>time()]);throw new RuntimeException('Missing user accepted');}
    catch(PDOException $e){smsCheck((string)$e->getCode()==='23000','Phone identity requires a real user foreign key');}
    if(!$mysql) {
        // Retention is checked only in this freshly created SQLite database, never against unrelated scratch rows.
        $stage='limited retention';$stale=smsChallenge($db,smsPhone(),'register','123456');
        $db->run('UPDATE sms_challenges SET expires_at=? WHERE challenge_id=?',[time()-86401,$stale['challenge_id']]);
        $staleKey=smsHash('rate','stale-'.$tag);$db->insert('sms_rate_limits',['bucket_key'=>$staleKey,'window_start'=>time()-8*86400,'attempt_count'=>1,'updated_at'=>time()-8*86400]);
        $activeKey=smsHash('rate','send-ip-hour|'.$tag.'|active-'.$tag);$db->insert('sms_rate_limits',['bucket_key'=>$activeKey,'window_start'=>time()-8*86400,'attempt_count'=>1,'updated_at'=>time()-8*86400]);
        $auth->smsSend(['phone'=>smsPhone(),'purpose'=>'register'],'active-'.$tag);$identities=(int)$db->scalar('SELECT COUNT(*) FROM phone_identities');
        $purged=$auth->purgeSms();
        smsCheck($purged['challenges']===1&&!$db->one('SELECT id FROM sms_challenges WHERE challenge_id=?',[$stale['challenge_id']]),'Expired challenges are retained for one day then removed');
        smsCheck($purged['rate_buckets']===1&&!$db->one('SELECT bucket_key FROM sms_rate_limits WHERE bucket_key=?',[$staleKey]),'Only stale rate buckets are removed');
        smsCheck((int)$db->scalar('SELECT updated_at FROM sms_rate_limits WHERE bucket_key=?',[$activeKey])>time()-60,'Active bucket refreshed and retained');
        smsCheck((int)$db->scalar('SELECT COUNT(*) FROM phone_identities')===$identities&&$db->one('SELECT id FROM sms_challenges WHERE challenge_id=?',[$limited['challenge_id']]),'Verified identities and active challenges retained');
    }
    $stage='complete rollback retry guard';$probe=new SmsRollbackProbe();
    $probe->pdo->exec(file_get_contents(__DIR__.'/../../database/sqlite.sql'));
    foreach(glob(__DIR__.'/../../database/migrations/*-sqlite.sql') as $migration)$probe->pdo->exec(file_get_contents($migration));
    $probeAuth=new Auth($probe,new AliSms(function(){throw new RuntimeException('No send belongs in verification retry');}));
    $proof=smsChallenge($probe,smsPhone(),'register','123456');$probe->remaining=2;
    $retried=$probeAuth->smsVerify($proof,'synthetic-retry');
    smsCheck($probe->faults===2&&$retried['user']['role']==='consumer','Fully rolled-back deadlocks retry only verification');
    smsCheck((int)$probe->scalar('SELECT COUNT(*) FROM users')===1&&(int)$probe->scalar('SELECT COUNT(*) FROM phone_identities')===1&&(int)$probe->scalar('SELECT COUNT(*) FROM tokens')===1,'Rollback removes temporary accounts and tokens before retry');
    $exhausted=smsChallenge($probe,smsPhone(),'register','123456');$beforeFaults=$probe->faults;$probe->remaining=4;
    try{$probeAuth->smsVerify($exhausted,'synthetic-exhausted');throw new RuntimeException('Unbounded retry accepted');}
    catch(PDOException $e){smsCheck($probe->faults-$beforeFaults===3&&!$probe->pdo->inTransaction(),'Deadlock retry stops after three fully rolled-back attempts');}
    smsCheck($probe->scalar('SELECT status FROM sms_challenges WHERE challenge_id=?',[$exhausted['challenge_id']])==='accepted'&&(int)$probe->scalar('SELECT COUNT(*) FROM users')===1,'Exhausted database retries consume no proof or create partial account');
    $nested=smsChallenge($probe,smsPhone(),'register','123456');$beforeFaults=$probe->faults;$probe->remaining=2;
    try{$probe->transaction(function()use($probeAuth,$nested){return $probeAuth->smsVerify($nested,'synthetic-nested');});throw new RuntimeException('Nested live transaction retried');}
    catch(PDOException $e){smsCheck($probe->faults-$beforeFaults===1,'Nested live transaction is never retried by SMS');}
    $constraint=smsChallenge($probe,smsPhone(),'register','123456');$beforeFaults=$probe->faults;$probe->remaining=2;$probe->number=1062;
    try{$probeAuth->smsVerify($constraint,'synthetic-constraint');throw new RuntimeException('Constraint was retried');}
    catch(PDOException $e){smsCheck($probe->faults-$beforeFaults===1,'Other database errors are never retried');}
    if(!$mysql)smsCheck($db->all('PRAGMA foreign_key_check')===[],'All synthetic relationships preserve foreign keys');
    echo 'Offline SMS authentication checks: '.$checks.' passed ('.$db->driver.'). No real SMS was sent.'.PHP_EOL;
}catch(Throwable $e){fwrite(STDERR,'Offline SMS checks failed at '.$stage.'; completed '.$checks.'; class='.get_class($e).'; state='.preg_replace('/[^A-Z0-9]/','',(string)$e->getCode()).'. No real SMS was sent.'.PHP_EOL);exit(1);}
finally {foreach(glob($directory.'/*') as $entry)if(is_file($entry))unlink($entry);rmdir($directory);}
