<?php
require_once __DIR__ . '/../../backend/extend/yuzhou/bootstrap.php';
use yuzhou\Database;
use yuzhou\Reports;
use yuzhou\ApiError;

$db = new Database(new PDO('sqlite::memory:'));
$db->pdo->exec(file_get_contents(__DIR__ . '/../../database/sqlite.sql'));
$db->pdo->exec(file_get_contents(__DIR__ . '/../../database/communication-sqlite.sql'));
foreach (glob(__DIR__ . '/../../database/migrations/*-sqlite.sql') as $migration) $db->pdo->exec(file_get_contents($migration));
$now = time(); $users = []; $checks = 0;
$check = function ($condition, $label) use (&$checks) { if (!$condition) throw new RuntimeException($label); $checks++; };
foreach (['merchant','agency'] as $kind) $db->insert('organizations', ['name'=>'合成统计机构','kind'=>$kind,'status'=>'approved','payload'=>'{}','created_at'=>$now]);
foreach (['platform'=>null,'consumer'=>null,'merchant'=>1,'agency'=>2,'broker'=>2] as $role=>$org) {
    $id = $db->insert('users',['username'=>$role,'password_hash'=>'synthetic','nickname'=>'统计测试','role'=>$role,'org_id'=>$org,'created_at'=>$now,'updated_at'=>$now]);
    $users[$role] = $db->one('SELECT * FROM users WHERE id=?',[$id]);
}
$resource = $db->insert('resources',['kind'=>'maintenance','org_id'=>1,'owner_id'=>$users['merchant']['id'],'title'=>'合成维修方案','status'=>'published','payload'=>'{}','created_at'=>$now,'updated_at'=>$now]);
$db->run('INSERT INTO maintenance_plans(resource_id,price_cents) VALUES (?,?)',[$resource,10000]);
foreach (['private-broker','direct-merchant'] as $index=>$name) {
    $broker = $index === 0 ? $users['broker']['id'] : null;
    $id = $db->insert('orders',['order_no'=>$name,'type'=>'maintenance','customer_id'=>$users['consumer']['id'],'creator_id'=>$broker ?: $users['consumer']['id'],'broker_id'=>$broker,'org_id'=>$broker ? 2 : 1,'resource_id'=>$resource,'status'=>'completed','amount_cents'=>10000,'original_amount_cents'=>10000,'paid_cents'=>10000,'refund_cents'=>$broker ? 1000 : 0,'profit_status'=>'confirmed','profit_cents'=>2000,'items'=>'[]','notes'=>'','created_at'=>$now,'updated_at'=>$now]);
    $db->insert('ledger',['user_id'=>$broker ?: $users['consumer']['id'],'order_id'=>$id,'beneficiary_role'=>$broker ? 'broker':'member','entry_key'=>$name,'amount_cents'=>$broker ? 200 : 100,'state'=>'available','available_at'=>$now,'created_at'=>$now]);
    $db->insert('appointments',['customer_id'=>$users['consumer']['id'],'broker_id'=>$broker,'org_id'=>$broker ? 2 : 1,'resource_id'=>$resource,'scheduled_at'=>$now+86400,'status'=>'pending','notes'=>'','created_at'=>$now,'updated_at'=>$now]);
}
$db->insert('customers',['user_id'=>$users['consumer']['id'],'broker_id'=>$users['broker']['id'],'org_id'=>2,'name'=>'合成客户','notes'=>'','stage'=>'new','created_at'=>$now,'updated_at'=>$now]);
$service = new Reports($db);
$summary = $service->summary([], $users['platform']);
$check($summary['order_count']===2 && $summary['net_paid_cents']===19000 && $summary['profit_cents']===4000, '平台准确累计实付、退款及确认利润');
foreach (['merchant','agency','broker'] as $role) $check($service->summary([], $users[$role])['order_count']===1 && $service->summary([], $users[$role])['appointment_count']===1, '统计隔离：'.$role);
$check($service->summary([], $users['broker'])['customer_count']===1 && $service->summary([], $users['agency'])['commission_cents']===200, '公司和经纪人只统计旗下客户分红');
$check($service->summary(['from_date'=>date('Y-m-d',$now+86400)],$users['platform'])['order_count']===0, '日期筛选准确');
try { $service->summary(['from_date'=>'2026-02-30'],$users['platform']); throw new RuntimeException('无效日期被接受'); } catch(ApiError $e) { $check($e->status===422, '拒绝无效日期'); }
try { $service->dispatch('GET','admin/users/'.$users['consumer']['id'].'/history',[],$users['merchant']); throw new RuntimeException('商家读取平台用户历史'); } catch(ApiError $e) { $check($e->status===403, '用户历史仅平台'); }
$temporary = sys_get_temp_dir().'/yuzhou-report-test-'.bin2hex(random_bytes(6));
mkdir($temporary,0700);putenv('STORAGE_PATH='.$temporary);
try {
    $file = $service->dividends([], $users['agency']);
    $zip = new ZipArchive(); $check($zip->open($file['_file'])===true && $zip->numFiles===5,'输出完整OOXML工作簿');
    $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $check(strpos($xml,'private-broker')!==false && strpos($xml,'direct-merchant')===false, 'Excel不包含其他经营主体的账本');
    $check(strpos($xml,'<c r="E2"><v>2.00</v></c>')!==false && strpos($xml,'<f>')===false, '金额为精确数值，文本不成为Excel公式');
    $zip->close();
} finally { foreach (glob($temporary.'/reports/*') ?: [] as $file) unlink($file); rmdir($temporary.'/reports');rmdir($temporary);putenv('STORAGE_PATH'); }
echo 'Report checks: '.$checks.' passed.'.PHP_EOL;
