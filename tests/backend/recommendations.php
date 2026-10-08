<?php
require_once __DIR__ . '/../../backend/extend/yuzhou/bootstrap.php';
use yuzhou\Database;
use yuzhou\Recommendations;
use yuzhou\ApiError;
$db = new Database(new PDO('sqlite::memory:'));
$db->pdo->exec(file_get_contents(__DIR__.'/../../database/sqlite.sql'));
$db->pdo->exec(file_get_contents(__DIR__.'/../../database/communication-sqlite.sql'));
foreach (glob(__DIR__.'/../../database/migrations/*-sqlite.sql') as $migration) $db->pdo->exec(file_get_contents($migration));
$now = time(); $users = []; $checks = 0;
$check = function($value) use (&$checks) { if (!$value) throw new RuntimeException('Recommendation boundary failed.'); $checks++; };
foreach (['owner','first','second'] as $name) {
 $id = $db->insert('users',['username'=>$name,'password_hash'=>'unused-synthetic','nickname'=>'合成偏好测试','role'=>'consumer','created_at'=>$now,'updated_at'=>$now]);
 $users[$name] = $db->one('SELECT * FROM users WHERE id=?',[$id]);
}
$boats = [];
foreach ([['yacht','published'],['sailboat','published'],['yacht','pending']] as $index=>$record) {
 $id = $db->insert('resources',['kind'=>'boat_types','owner_id'=>$users['owner']['id'],'title'=>'合成船型'.$index,'status'=>$record[1],'payload'=>'{}','created_at'=>$now+$index,'updated_at'=>$now]);
 $db->insert('boat_types',['resource_id'=>$id,'category'=>$record[0],'capacity'=>4,'daily_price_cents'=>0,'deposit_cents'=>0,'specs_json'=>'{}']);$boats[]=$id;
}
$service = new Recommendations($db);
$check($service->listing([],null)['items'][0]['id'] === $boats[1]);
$record = ['user_id'=>$users['first']['id'],'kind'=>'favorite','resource_id'=>$boats[0],'title'=>'合成收藏','payload'=>'{}','status'=>'active','created_at'=>$now,'updated_at'=>$now];
$favorite = $db->insert('user_records',$record);
$result = $service->listing([],$users['first']);
$check($result['personalized'] && $result['items'][0]['id'] === $boats[0]);
$check(!$service->listing([],$users['second'])['personalized']);
$check(!in_array($boats[2],array_column($result['items'],'id'),true));
$db->update('user_records',$favorite,['status'=>'deleted']);
$check(!$service->listing([],$users['first'])['personalized']);
try { $service->listing(['limit'=>25],null);throw new RuntimeException('Unbounded recommendations.'); } catch(ApiError $e) { $check($e->status===422); }
echo 'Recommendation checks: '.$checks.' passed.'.PHP_EOL;
