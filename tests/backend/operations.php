<?php
require_once __DIR__ . '/../../backend/extend/yuzhou/bootstrap.php';
use yuzhou\Database;
use yuzhou\ApiError;
use yuzhou\Catalog;
use yuzhou\Operations;
use yuzhou\Rbac;
use yuzhou\Api;
use yuzhou\Orders;
$checks=0;
function verify($condition,$label) { global $checks; if (!$condition) throw new RuntimeException($label); $checks++; }
function reject($callback,$status,$label) { try { $callback(); } catch (ApiError $e) { verify($e->status===$status,$label); return; } throw new RuntimeException($label); }
function initOperationsDb(Database $db) {
    $db->pdo->exec(file_get_contents(__DIR__.'/../../database/sqlite.sql'));
    $db->pdo->exec(file_get_contents(__DIR__.'/../../database/communication-sqlite.sql'));
    foreach(glob(__DIR__.'/../../database/migrations/*-sqlite.sql') as $path) $db->pdo->exec(file_get_contents($path));
}
function operationUsers(Database $db) {
    $now=time();
    foreach(['merchant','agency','merchant'] as $i=>$kind) $db->insert('organizations',['name'=>'Synthetic '.$i,'kind'=>$kind,'status'=>'approved','payload'=>'{}','created_at'=>$now]);
    $users=[];
    foreach(['platform'=>null,'merchant'=>1,'agency'=>2,'broker'=>2,'consumer'=>null,'outsider'=>null,'limited'=>null] as $role=>$org) {
        $id=$db->insert('users',['username'=>'ops_'.$role,'password_hash'=>'unused','nickname'=>$role,'role'=>$role==='limited'?'platform':($role==='outsider'?'consumer':$role),'org_id'=>$org,'growth_points'=>$role==='consumer'?100:0,'created_at'=>$now,'updated_at'=>$now]);
        $users[$role]=$db->one('SELECT * FROM users WHERE id=?',[$id]);
        $db->insert('tokens',['user_id'=>$id,'token_hash'=>hash('sha256','ops-token-'.$role),'created_at'=>$now,'expires_at'=>$now+3600]);
    }
    $file=$db->insert('uploads',['owner_id'=>$users['merchant']['id'],'org_id'=>1,'storage_name'=>'synthetic.png','original_name'=>'test.png','mime'=>'image/png','size_bytes'=>1,'sha256'=>str_repeat('a',64),'is_private'=>1,'created_at'=>$now]);
    $db->insert('merchants',['credit_code'=>'91370200MA3YY0001D','organization_id'=>1,'legal_name'=>'Synthetic','license_file_id'=>$file,'status'=>'approved','created_at'=>$now]);
    return $users;
}
putenv('APP_ENV=test');putenv('ALLOW_TEST_PAYMENTS=1');
$db=new Database(new PDO('sqlite::memory:'));initOperationsDb($db);$u=operationUsers($db);$cat=new Catalog($db);$ops=new Operations($db);$rbac=new Rbac($db);$api=new Api($db);
$call=function($method,$path,$body=[],$role='consumer')use($api){ return $api->dispatch($method,$path,$body,[],'ops-token-'.$role); };
$product=$cat->save('growth_products',['title'=>'Synthetic points gift','payload'=>['points_price'=>60,'stock'=>3]],$u['platform']);
$redeem=$call('POST','points-shop/redeem',['product_id'=>$product['id'],'quantity'=>1,'idempotency_key'=>'points-redeem-001']);
$repeat=$call('POST','points-shop/redeem',['product_id'=>$product['id'],'quantity'=>1,'idempotency_key'=>'points-redeem-001']);
verify($redeem['id']===$repeat['id'] && (int)$db->scalar('SELECT growth_points FROM users WHERE id=?',[$u['consumer']['id']])===40,'Idempotent redemption deducts points once');
verify($redeem['status']==='pending_fulfillment' && $cat->get($product['id'])['payload']['stock']===2,'Redemption reserves actual stock and remains awaiting physical fulfillment');
reject(function()use($call,$product){$call('POST','points-shop/redeem',['product_id'=>$product['id'],'quantity'=>2,'idempotency_key'=>'points-redeem-001']);},409,'Key cannot change redemption quantity');
reject(function()use($call,$product){$call('POST','points-shop/redeem',['product_id'=>$product['id'],'quantity'=>1,'idempotency_key'=>'points-redeem-002']);},409,'Insufficient points prevent second purchase');
reject(function()use($call,$redeem){$call('POST','points-shop/redemptions/'.$redeem['id'].'/cancel',[],'outsider');},404,'Other user cannot cancel redemption');
$call('POST','points-shop/redemptions/'.$redeem['id'].'/cancel');
verify((int)$db->scalar('SELECT growth_points FROM users WHERE id=?',[$u['consumer']['id']])===100 && $cat->get($product['id'])['payload']['stock']===3,'Cancellation returns exact points and stock');
reject(function()use($call,$redeem){$call('POST','points-shop/redemptions/'.$redeem['id'].'/cancel');},409,'Cancellation cannot double-return points');
$next=$call('POST','points-shop/redeem',['product_id'=>$product['id'],'quantity'=>1,'idempotency_key'=>'points-redeem-003']);
reject(function()use($call,$next){$call('POST','points-shop/redemptions/'.$next['id'].'/review',['status'=>'fulfilled'], 'platform');},422,'Real fulfillment requires evidence');
$call('POST','points-shop/redemptions/'.$next['id'].'/review',['status'=>'fulfilled','notes'=>'Synthetic delivery record'], 'platform');
reject(function()use($call,$next){$call('POST','points-shop/redemptions/'.$next['id'].'/cancel');},409,'Delivered redemption cannot silently refund points');
$banner=$cat->save('banners',['title'=>'Home promotion','payload'=>['placement'=>'home','sort_order'=>2,'target_url'=>'/pages/community/points']],$u['platform']);
verify($cat->listing('banners',['placement'=>'home'])['total']===1 && $cat->listing('banners',['placement'=>'shop'])['total']===0,'Banners filter by real placement');
reject(function()use($cat,$u){$cat->save('banners',['title'=>'Bad','payload'=>['target_url'=>'javascript:alert(1)']],$u['platform']);},422,'Unsafe promotional URLs rejected');
$news=$cat->save('posts',['title'=>'Official test news','payload'=>['post_type'=>'news','is_pinned'=>true,'content'=>'Synthetic content']],$u['platform']);
verify($cat->listing('posts',[])['items'][0]['id']===$news['id'] && $news['payload']['is_pinned'],'Official pinned news is published and ordered');
reject(function()use($cat,$u){$cat->save('posts',['title'=>'Impersonation','payload'=>['post_type'=>'news']],$u['consumer']);},403,'Consumer cannot impersonate official news');
$call('POST','posts/'.$news['id'].'/like');$liked=$call('POST','posts/'.$news['id'].'/like');
verify($liked['like_count']===1 && $cat->get($news['id'],'posts',$u['consumer'])['payload']['liked'],'Repeat like is idempotent and user liked flag is server derived');
$unliked=$call('DELETE','posts/'.$news['id'].'/like');verify($unliked['like_count']===0 && !$unliked['liked'],'Unlike removes only caller like');
reject(function()use($call,$news){$call('POST','posts/'.$news['id'].'/official-replies',['content'=>'Fake official']);},403,'Only platform can issue official replies');
$call('POST','posts/'.$news['id'].'/official-replies',['content'=>'Official answer'],'platform');
verify($api->dispatch('GET','posts/'.$news['id'].'/official-replies')['total']===1,'Official reply is publicly readable');

$role=$rbac->dispatch('POST','admin/roles',['name'=>'Finance clerk','base_role'=>'platform','permissions'=>['finance']],[],$u['platform']);
$rbac->dispatch('PUT','admin/users/'.$u['limited']['id'].'/roles',['role_ids'=>[$role['id']]],[],$u['platform']);
verify($call('GET','auth/me',[],'limited')['permissions']===['finance'],'Effective sub-role is returned to frontend');
$call('GET','ledger',[],'limited');
reject(function()use($call){$call('PUT','settings',['distribution'=>[]],'limited');},403,'Finance clerk cannot change settings');
reject(function()use($call,$u){$call('PATCH','admin/users/'.$u['consumer']['id'],['role'=>'platform'],'limited');},403,'Finance clerk cannot elevate identity through user administration');
$usersRole=$rbac->dispatch('POST','admin/roles',['name'=>'User clerk','base_role'=>'platform','permissions'=>['users']],[],$u['platform']);
$rbac->dispatch('PUT','admin/users/'.$u['limited']['id'].'/roles',['role_ids'=>[$usersRole['id']]],[],$u['platform']);
reject(function()use($call,$u){$call('PATCH','admin/users/'.$u['consumer']['id'],['role'=>'platform'],'limited');},403,'User clerk also cannot grant unrestricted platform identity');
$otherAgency=$db->insert('organizations',['name'=>'Other synthetic agency','kind'=>'agency','status'=>'approved','payload'=>'{}','created_at'=>time()]);
reject(function()use($call,$u,$otherAgency){$call('PATCH','admin/users/'.$u['broker']['id'],['org_id'=>$otherAgency],'limited');},403,'User clerk cannot reassign organization to bypass isolation');
$registered=$api->dispatch('POST','auth/register',['username'=>'ops.register','password'=>'Synthetic-pass-123','role'=>'platform','org_id'=>1,'member_until'=>time()+99999],[],null,[],'ops-register-fixture');
verify($registered['user']['role']==='consumer' && $registered['user']['org_id']===null && $registered['user']['member_until']===0,'Public account creation cannot grant requested roles, organization or membership');
reject(function()use($rbac,$u,$role){$rbac->dispatch('PUT','admin/users/'.$u['merchant']['id'].'/roles',['role_ids'=>[$role['id']]],[],$u['platform']);},403,'Role cannot cross base identity');
$merchantRole=$rbac->dispatch('POST','admin/roles',['name'=>'Merchant order clerk','base_role'=>'merchant','org_id'=>1,'permissions'=>['orders','finance']],[],$u['platform']);
$rbac->dispatch('PUT','admin/users/'.$u['merchant']['id'].'/roles',['role_ids'=>[$merchantRole['id']]],[],$u['platform']);
$service=$cat->save('maintenance',['title'=>'Test service','price_cents'=>10000,'payload'=>[]],$u['merchant']);$cat->save('maintenance',['version'=>$service['version'],'status'=>'published'], $u['platform'],$service['id']);
$db->insert('customers',['user_id'=>$u['consumer']['id'],'broker_id'=>$u['broker']['id'],'org_id'=>2,'name'=>'Test','stage'=>'new','notes'=>'','created_at'=>time(),'updated_at'=>time()]);
$order=(new Orders($db))->create(['type'=>'maintenance','resource_id'=>$service['id'],'customer_id'=>$u['consumer']['id'],'scheduled_at'=>date('Y-m-d H:i:s',time()+86400),'service_location'=>'Test dock'],$u['broker']);
verify($db->scalar('SELECT location FROM order_service_schedules WHERE order_id=?',[$order['id']])==='Test dock','Service order creates validated appointment time and location');
reject(function()use($call,$order){$call('GET','orders/'.$order['id'],[],'merchant');},404,'Merchant sub-role still cannot see broker order');
reject(function()use($call,$order){$call('POST','orders/'.$order['id'].'/progress',['stage'=>'installing','percent'=>10],'broker');},409,'Installation progress cannot begin before payment');
$db->update('orders',$order['id'],['status'=>'paid','paid_cents'=>10000]);
$call('POST','orders/'.$order['id'].'/progress',['stage'=>'installing','percent'=>40,'notes'=>'Synthetic installation progress'],'broker');
reject(function()use($call,$order){$call('POST','orders/'.$order['id'].'/progress',['stage'=>'preparing','percent'=>20],'broker');},409,'Progress cannot decrease');
reject(function()use($call,$order){$call('POST','orders/'.$order['id'].'/progress',['stage'=>'completed','percent'=>100]);},403,'Customer cannot forge installation completion');
verify($call('GET','orders/'.$order['id'])['progress'][0]['percent']==40,'Customer can read actual progress with schedule');
$clerkRole=$rbac->dispatch('POST','admin/roles',['name'=>'Orders clerk','base_role'=>'platform','permissions'=>['orders']],[],$u['platform']);
$rbac->dispatch('PUT','admin/users/'.$u['limited']['id'].'/roles',['role_ids'=>[$clerkRole['id']]],[],$u['platform']);
reject(function()use($call,$order){$call('POST','orders/'.$order['id'].'/profit-confirm',['profit_cents'=>100,'notes'=>'Unauthorized'],'limited');},403,'Orders-only sub-role cannot confirm financial profit');
$comment=$call('POST','posts/'.$news['id'].'/comments',['content'=>'Awaiting moderation']);
verify($api->dispatch('GET','admin/comments',[],['status'=>'pending','post_id'=>$news['id']],'ops-token-platform')['total']===1,'Platform has a real pending comment queue');
reject(function()use($call){$call('GET','admin/comments',[],'limited');},403,'Orders-only clerk cannot see community moderation queue');
$call('PATCH','admin/users/'.$u['consumer']['id'],['nickname'=>'Platform editable name'],'platform');
verify($db->scalar('SELECT nickname FROM users WHERE id=?',[$u['consumer']['id']])==='Platform editable name','Platform can edit nickname without changing identity');
verify(yuzhou\Validation::creditCode('91370200MA3YY0001D')==='91370200MA3YY0001D','Synthetic credit code uses the agreed 18-character alphabet');
reject(function(){yuzhou\Validation::creditCode('91370200MA3YZ0001X');},422,'Excluded credit-code letters are rejected');
try {$db->insert('merchants',['credit_code'=>null,'organization_id'=>3,'legal_name'=>'Invalid','status'=>'pending','created_at'=>time()]);throw new RuntimeException('NULL merchant primary key accepted');}catch(PDOException $e){verify(true,'Social credit code PK explicitly rejects NULL');}
verify(count($db->all('PRAGMA foreign_key_check'))===0,'Operations foreign keys remain valid');

if(function_exists('pcntl_fork')) {
 $path=tempnam(sys_get_temp_dir(),'yuzhou-points-');$parallel=new Database(new PDO('sqlite:'.$path));initOperationsDb($parallel);$pu=operationUsers($parallel);
 $gift=(new Catalog($parallel))->save('growth_products',['title'=>'Concurrent gift','payload'=>['points_price'=>60,'stock'=>3]],$pu['platform']);
 $children=[];
 for($i=0;$i<2;$i++){ $pid=pcntl_fork();if($pid===0){try{$child=new Database(new PDO('sqlite:'.$path));(new Operations($child))->dispatch('POST','points-shop/redeem',['product_id'=>$gift['id'],'quantity'=>1,'idempotency_key'=>'concurrent-points-'.$i],[],$pu['consumer']);exit(0);}catch(ApiError $e){exit($e->status===409?2:3);}catch(Throwable $e){exit(4);}}$children[]=$pid;}
 $codes=[];foreach($children as $pid){pcntl_waitpid($pid,$status);$codes[]=pcntl_wexitstatus($status);}sort($codes);
 verify($codes===[0,2] && (int)$parallel->scalar('SELECT growth_points FROM users WHERE id=?',[$pu['consumer']['id']])===40 && (int)$parallel->scalar('SELECT COUNT(*) FROM point_redemptions')===1,'Concurrent redemptions cannot overspend points');unset($parallel);unlink($path);
}else throw new RuntimeException('Concurrent points test requires pcntl.');

if(function_exists('pcntl_fork')) {
 $path=tempnam(sys_get_temp_dir(),'yuzhou-rent-sale-');$parallel=new Database(new PDO('sqlite:'.$path));initOperationsDb($parallel);$su=operationUsers($parallel);$c=new Catalog($parallel);$o=new Orders($parallel);
 $type=$c->save('boat_types',['title'=>'Concurrent physical type','payload'=>['daily_price_cents'=>1000]],$su['merchant']);$c->save('boat_types',['version'=>$type['version'],'status'=>'published'],$su['platform'],$type['id']);
 $boat=$c->save('boats',['title'=>'Concurrent physical boat','price_cents'=>10000,'payload'=>['boat_type_id'=>$type['id'],'serial_number'=>'SALE-RENT-LOCK-001']],$su['merchant']);$c->save('boats',['version'=>$boat['version'],'status'=>'published'],$su['platform'],$boat['id']);
 $purchase=$o->create(['type'=>'boat_purchase','resource_id'=>$boat['id']],$su['consumer']);
 $o->action($purchase['id'],'quote',['amount_cents'=>10000],$su['merchant']);$o->action($purchase['id'],'accept-quote',[],$su['consumer']);
 $o->action($purchase['id'],'contract',['file_id'=>1],$su['merchant']);$o->action($purchase['id'],'contract-review',['version'=>1,'status'=>'approved'],$su['platform']);$o->action($purchase['id'],'sign',['version'=>1,'accepted'=>true],$su['consumer']);
 $children=[];
 for($i=0;$i<2;$i++){ $pid=pcntl_fork();if($pid===0){try{$child=new Database(new PDO('sqlite:'.$path));$service=new Orders($child);if($i===0)$service->action($purchase['id'],'pay',['channel'=>'test','idempotency_key'=>'sale-pay-concurrent'],$su['consumer']);else $service->create(['type'=>'boat_rental','resource_id'=>$type['id'],'start_date'=>date('Y-m-d',time()+86400),'end_date'=>date('Y-m-d',time()+86400*3)],$su['consumer']);exit($i===0?10:20);}catch(ApiError $e){exit($e->status===409?($i===0?11:21):3);}catch(Throwable $e){exit(4);}}$children[]=$pid;}
 $codes=[];foreach($children as $pid){pcntl_waitpid($pid,$status);$codes[]=pcntl_wexitstatus($status);}sort($codes);
 $paid=(int)$parallel->scalar('SELECT paid_cents FROM orders WHERE id=?',[$purchase['id']]);$reserved=(int)$parallel->scalar('SELECT COUNT(*) FROM rental_reservations');
 verify(($codes===[10,21] && $paid===10000 && $reserved===0)||($codes===[11,20] && $paid===0 && $reserved===2),'Concurrent purchase payment and rental cannot allocate the same physical boat');unset($parallel);unlink($path);
}
echo 'Operations checks: '.$checks.' passed.'.PHP_EOL;
