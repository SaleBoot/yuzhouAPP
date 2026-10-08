<?php
// Credentials stay in the process environment; this script cannot use the live database.
$variables = ['HOST', 'PORT', 'NAME', 'USER', 'PASSWORD']; $configuration = [];
foreach ($variables as $key) $configuration[$key] = getenv('YUZHOU_MYSQL_TEST_DB_' . $key);
if ($configuration['NAME'] !== 'yuzhou_acceptance_20261009' || !$configuration['HOST'] || !$configuration['USER'] || !$configuration['PASSWORD']
    || !preg_match('/^[A-Za-z0-9._:-]{1,200}$/D', $configuration['HOST']) || !ctype_digit((string)$configuration['PORT'])
    || (int)$configuration['PORT'] < 1 || (int)$configuration['PORT'] > 65535) {
    fwrite(STDERR, "MySQL acceptance configuration rejected. No database work performed.\n"); exit(1);
}
foreach ($configuration as $key => $value) putenv('DB_' . ($key === 'NAME' ? 'DATABASE' : ($key === 'USER' ? 'USERNAME' : $key)) . '=' . $value);
putenv('DB_DRIVER=mysql'); putenv('APP_ENV=test'); putenv('ALLOW_TEST_PAYMENTS=1');
require_once __DIR__ . '/../../backend/extend/yuzhou/bootstrap.php';
use yuzhou\Database;
use yuzhou\Catalog;
use yuzhou\Orders;
use yuzhou\Finance;
use yuzhou\Operations;
use yuzhou\Recommendations;
use yuzhou\ApiError;

function acceptanceDb()
{
    $db = Database::connect();
    if ($db->driver !== 'mysql' || $db->scalar('SELECT DATABASE()') !== 'yuzhou_acceptance_20261009') throw new RuntimeException('Unsafe database rejected.');
    $db->run('SET SESSION innodb_lock_wait_timeout=10');
    return $db;
}

function acceptanceCheck($condition, $label)
{
    global $checks;
    if (!$condition) throw new RuntimeException($label);
    $checks++;
}

function acceptanceReject($callback, $status, $label)
{
    try { $callback(); } catch (ApiError $e) { acceptanceCheck($e->status === $status, $label); return; }
    throw new RuntimeException($label);
}

function acceptanceForeignKey($callback, $label)
{
    try { $callback(); } catch (PDOException $e) { acceptanceCheck((string)$e->getCode() === '23000', $label); return; }
    throw new RuntimeException($label);
}

function acceptanceConcurrent($mode, array $state, $directory)
{
    $state['barrier'] = $directory . '/' . $mode;
    $file = $directory . '/' . $mode . '.json';
    file_put_contents($file, json_encode($state)); chmod($file, 0600);
    $children = [];
    for ($i = 0; $i < 2; $i++) {
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --worker ' . escapeshellarg($file) . ' ' . escapeshellarg($mode) . ' ' . $i;
        $pipes = [];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('Concurrent worker could not start.');
        fclose($pipes[0]); $children[] = [$process, $pipes];
    }
    $deadline = microtime(true) + 12;
    while (!is_file($state['barrier'] . '.ready0') || !is_file($state['barrier'] . '.ready1')) {
        if (microtime(true) > $deadline) {
            foreach ($children as $child) proc_terminate($child[0]);
            throw new RuntimeException('Concurrent workers did not become ready.');
        }
        usleep(10000);
    }
    file_put_contents($state['barrier'] . '.go', 'go');
    $codes = [];
    foreach ($children as $child) {
        // Only a whitelisted error class/SQLSTATE diagnostic is accepted from failed workers.
        stream_get_contents($child[1][1]); $diagnostic = trim(stream_get_contents($child[1][2]));
        if (preg_match('/^Worker failure: [A-Za-z0-9_\\\\]+; state=[A-Z0-9]{1,5}; driver=[0-9]{1,5}$/D', $diagnostic)) fwrite(STDERR, $diagnostic . PHP_EOL);
        fclose($child[1][1]); fclose($child[1][2]); $codes[] = proc_close($child[0]);
    }
    sort($codes);
    if (!in_array($codes, [[0,0],[0,2]], true)) fwrite(STDERR, 'Worker result codes: ' . implode(',', $codes) . PHP_EOL);
    return $codes;
}

if (($argv[1] ?? '') === '--worker') {
    try {
        $state = json_decode(file_get_contents($argv[2]), true); $mode = $argv[3]; $index = (int)$argv[4];
        $db = acceptanceDb(); file_put_contents($state['barrier'] . '.ready' . $index, 'ready');
        $deadline = microtime(true) + 15;
        while (!is_file($state['barrier'] . '.go')) { if (microtime(true) > $deadline) exit(6); usleep(10000); }
        $user = $db->one('SELECT * FROM users WHERE id=?', [(int)$state['consumer_id']]);
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                if ($mode === 'rent_sale') {
                    if ($index === 0) (new Orders($db))->action($state['purchase_id'], 'pay', ['channel' => 'test', 'idempotency_key' => $state['tag'] . '-sale-pay'], $user);
                    else (new Orders($db))->create(['type' => 'boat_rental', 'resource_id' => $state['type_id'], 'start_date' => $state['start_date'], 'end_date' => $state['end_date']], $user);
                } elseif ($mode === 'mall_stock') {
                    (new Orders($db))->create(['type' => 'mall', 'resource_id' => $state['product_id'], 'quantity' => 1], $user);
                } elseif ($mode === 'purchase_create') {
                    (new Orders($db))->create(['type'=>'boat_purchase','resource_id'=>$state['purchase_boat_id']],$user);
                } elseif ($mode === 'points' || $mode === 'points_same_key') {
                    (new Operations($db))->dispatch('POST', 'points-shop/redeem', ['product_id' => $state['product_id'], 'quantity' => 1,
                        'idempotency_key' => $state['tag'] . '-' . $mode . '-' . ($mode === 'points_same_key' ? 'same' : $index)], [], $user);
                } elseif ($mode === 'withdraw' || $mode === 'withdraw_same_key') {
                    $broker = $db->one('SELECT * FROM users WHERE id=?', [(int)$state['broker_id']]);
                    (new Finance($db))->withdraw(['amount_cents' => 600, 'idempotency_key'=>$state['tag'].'-'.$mode.'-'.($mode==='withdraw_same_key'?'same':$index)], $broker);
                } else exit(6);
                exit(0);
            } catch (PDOException $e) {
                // InnoDB may choose a transaction victim; retry the same guarded request after rollback.
                $number = isset($e->errorInfo[1]) ? (int)$e->errorInfo[1] : 0;
                if (!in_array($number, [1205, 1213], true) || $attempt === 2) {
                    fwrite(STDERR, 'Worker failure: PDOException; state=' . preg_replace('/[^A-Z0-9]/', '', (string)$e->getCode()) . '; driver=' . $number . PHP_EOL); exit(4);
                }
                usleep(20000 * ($attempt + 1));
            }
        }
        exit(4);
    } catch (ApiError $e) { if ($e->status !== 409) fwrite(STDERR, 'Worker failure: ApiError; state=' . $e->status . '; driver=0' . PHP_EOL); exit($e->status === 409 ? 2 : 3); }
    catch (Throwable $e) { fwrite(STDERR, 'Worker failure: ' . get_class($e) . '; state=OTHER; driver=0' . PHP_EOL); exit(5); }
}

$checks = 0; $stage = 'configuration'; $directory = null;
try {
    $db = acceptanceDb(); $tag = 'myt' . bin2hex(random_bytes(6)); $now = time();
    $directory = sys_get_temp_dir() . '/yuzhou-mysql-' . $tag; mkdir($directory, 0700);
    putenv('STORAGE_PATH=' . $directory . '/files');
    $stage = 'synthetic fixture';
    $merchantOrg = $db->insert('organizations', ['name' => $tag . ' merchant synthetic', 'kind' => 'merchant', 'status' => 'approved', 'payload' => '{}', 'created_at' => $now]);
    $agencyOrg = $db->insert('organizations', ['name' => $tag . ' agency synthetic', 'kind' => 'agency', 'status' => 'approved', 'payload' => '{}', 'created_at' => $now]);
    $otherAgency = $db->insert('organizations', ['name' => $tag . ' other agency synthetic', 'kind' => 'agency', 'status' => 'approved', 'payload' => '{}', 'created_at' => $now]);
    $u = [];
    foreach (['platform' => null, 'merchant' => $merchantOrg, 'agency' => $agencyOrg, 'broker' => $agencyOrg, 'consumer' => null, 'otheragency' => $otherAgency, 'outsider' => null] as $role => $org) {
        $id = $db->insert('users', ['username' => $tag . '_' . $role, 'password_hash' => 'unused-synthetic-fixture', 'nickname' => 'Synthetic ' . $role,
            'role' => $role === 'otheragency' ? 'agency' : ($role === 'outsider' ? 'consumer' : $role), 'org_id' => $org, 'member_until' => $role === 'consumer' ? $now + 86400 * 180 : 0,
            'growth_points' => $role === 'consumer' ? 100 : 0, 'created_at' => $now, 'updated_at' => $now]);
        $u[$role] = $db->one('SELECT * FROM users WHERE id=?', [$id]);
    }
    $file = $db->insert('uploads', ['owner_id' => (int)$u['merchant']['id'], 'org_id' => $merchantOrg, 'storage_name' => $tag . '.pdf', 'original_name' => 'Synthetic acceptance contract.pdf', 'mime' => 'application/pdf', 'size_bytes' => 1, 'sha256' => str_repeat('a', 64), 'is_private' => 1, 'created_at' => $now]);
    $prefix = '91370200' . str_pad((string)random_int(0, 999999999), 9, '0', STR_PAD_LEFT);
    $alphabet = '0123456789ABCDEFGHJKLMNPQRTUWXY'; $weights = [1,3,9,27,19,26,16,17,20,29,25,13,8,24,10,30,28]; $sum = 0;
    for ($i = 0; $i < 17; $i++) $sum += strpos($alphabet, $prefix[$i]) * $weights[$i];
    $credit = $prefix . $alphabet[(31 - $sum % 31) % 31];
    $db->insert('merchants', ['credit_code' => $credit, 'organization_id' => $merchantOrg, 'legal_name' => 'Synthetic acceptance merchant', 'license_file_id' => $file, 'status' => 'approved', 'created_at' => $now]);
    $catalog = new Catalog($db); $orders = new Orders($db);
    $publish = function ($kind, array $body) use ($catalog, $u) {
        $resource = $catalog->save($kind, $body, $u['merchant']);
        return $catalog->save($kind, ['version' => $resource['version'], 'status' => 'published'], $u['platform'], $resource['id']);
    };
    $type = $publish('boat_types', ['title' => $tag . ' rental type', 'payload' => ['daily_price_cents' => 1000, 'capacity' => 3]]);
    $boat = $publish('boats', ['title' => $tag . ' physical boat', 'price_cents' => 10000, 'payload' => ['boat_type_id' => $type['id'], 'serial_number' => $tag . '-boat']]);
    $purchase = $orders->create(['type' => 'boat_purchase', 'resource_id' => $boat['id']], $u['consumer']);
    $orders->action($purchase['id'], 'quote', ['amount_cents' => 10000], $u['merchant']); $orders->action($purchase['id'], 'accept-quote', [], $u['consumer']);
    $orders->action($purchase['id'], 'contract', ['file_id' => $file], $u['merchant']); $orders->action($purchase['id'], 'contract-review', ['version' => 1, 'status' => 'approved'], $u['platform']);
    $orders->action($purchase['id'], 'sign', ['version' => 1, 'accepted' => true], $u['consumer']);
    $state = ['tag' => $tag, 'consumer_id' => (int)$u['consumer']['id'], 'broker_id' => (int)$u['broker']['id'], 'type_id' => $type['id'], 'purchase_id' => $purchase['id'],
        'start_date' => date('Y-m-d', $now + 86400), 'end_date' => date('Y-m-d', $now + 86400 * 3)];
    $stage = 'physical boat rent and sale concurrency';
    acceptanceCheck(acceptanceConcurrent('rent_sale', $state, $directory) === [0,2], 'Rent and sale do not both succeed.');
    $paid = (int)$db->scalar('SELECT paid_cents FROM orders WHERE id=?', [$purchase['id']]);
    $reserved = (int)$db->scalar('SELECT COUNT(*) FROM rental_reservations WHERE boat_id=?', [$boat['id']]);
    acceptanceCheck(($paid === 10000 && $reserved === 0) || ($paid === 0 && $reserved === 2), 'Physical allocation and money are mutually exclusive.');
    $inventory = $orders->inventory(['boat_type_id' => $type['id'], 'start_date' => $state['start_date'], 'end_date' => $state['end_date']]);
    acceptanceCheck($inventory['available_count'] === 0, 'Committed rent or sale removes physical inventory.');
    $stage='single physical boat concurrent purchases';
    $purchaseBoat=$publish('boats',['title'=>$tag.' concurrent purchase boat','price_cents'=>10000,'payload'=>['boat_type_id'=>$type['id'],'serial_number'=>$tag.'-purchase']],$u['merchant']);
    $state['purchase_boat_id']=$purchaseBoat['id'];
    acceptanceCheck(acceptanceConcurrent('purchase_create',$state,$directory)===[0,2],'Two concurrent purchase creations cannot claim one physical boat.');
    acceptanceCheck((int)$db->scalar('SELECT COUNT(*) FROM orders WHERE resource_id=?',[$purchaseBoat['id']])===1,'Only one committed purchase order exists for the physical boat.');
    $activeId=(int)$db->scalar('SELECT id FROM orders WHERE resource_id=?',[$purchaseBoat['id']]);
    $orders->action($activeId,'quote',['amount_cents'=>10000],$u['merchant']);$orders->action($activeId,'accept-quote',[],$u['consumer']);
    $orders->action($activeId,'contract',['file_id'=>$file],$u['merchant']);$orders->action($activeId,'contract-review',['version'=>1,'status'=>'approved'],$u['platform']);$orders->action($activeId,'sign',['version'=>1,'accepted'=>true],$u['consumer']);
    $legacy=$db->one('SELECT * FROM orders WHERE id=?',[$activeId]);unset($legacy['id']);$legacy['order_no']=$tag.'-synthetic-legacy-duplicate';$legacy['paid_cents']=10000;$legacy['status']='cancelled';$db->insert('orders',$legacy);
    try{$orders->action($activeId,'pay',['channel'=>'test','idempotency_key'=>$tag.'-duplicate-pay'],$u['consumer']);throw new RuntimeException('Duplicate purchase payment was accepted.');}
    catch(ApiError $e){acceptanceCheck($e->status===409&&$e->getCode()===40921,'Another received purchase blocks payment despite inconsistent historical status.');}
    acceptanceCheck((int)$db->scalar('SELECT COUNT(*) FROM payments WHERE order_id=?',[$activeId])===0,'Duplicate purchase rejection creates no receipt.');
    $stage = 'mall stock concurrency';
    $product = $catalog->save('products', ['title' => $tag . ' one stock item', 'price_cents' => 700, 'payload' => ['stock' => 1]], $u['platform']);
    $state['product_id'] = $product['id'];
    acceptanceCheck(acceptanceConcurrent('mall_stock', $state, $directory) === [0,2], 'Only one stock checkout succeeds.');
    acceptanceCheck((int)$db->scalar('SELECT stock FROM products WHERE resource_id=?', [$product['id']]) === 0, 'Stock is never negative.');
    acceptanceCheck((int)$db->scalar('SELECT COUNT(*) FROM order_items WHERE resource_id=?', [$product['id']]) === 1, 'Only one committed inventory line exists.');
    $stage = 'points concurrency';
    $pointsProduct = $catalog->save('growth_products', ['title' => $tag . ' points gift', 'payload' => ['points_price' => 60, 'stock' => 3]], $u['platform']);
    $state['product_id'] = $pointsProduct['id'];
    acceptanceCheck(acceptanceConcurrent('points', $state, $directory) === [0,2], 'Concurrent point purchases cannot overspend.');
    acceptanceCheck((int)$db->scalar('SELECT growth_points FROM users WHERE id=?', [(int)$u['consumer']['id']]) === 40, 'Exactly one points deduction commits.');
    acceptanceCheck((int)$db->scalar('SELECT COUNT(*) FROM point_redemptions WHERE product_id=?', [$pointsProduct['id']]) === 1 && (int)$db->scalar('SELECT stock FROM growth_products WHERE resource_id=?', [$pointsProduct['id']]) === 2, 'Point stock and claim agree.');
    $sameProduct = $catalog->save('growth_products', ['title' => $tag . ' idempotent gift', 'payload' => ['points_price' => 60, 'stock' => 2]], $u['platform']);
    $db->update('users', $u['consumer']['id'], ['growth_points' => 100]); $state['product_id'] = $sameProduct['id'];
    acceptanceCheck(acceptanceConcurrent('points_same_key', $state, $directory) === [0,0], 'Concurrent same-key redemptions return existing receipt.');
    acceptanceCheck((int)$db->scalar('SELECT COUNT(*) FROM point_redemptions WHERE product_id=?', [$sameProduct['id']]) === 1 && (int)$db->scalar('SELECT growth_points FROM users WHERE id=?', [(int)$u['consumer']['id']]) === 40, 'Same-key redemption deducts once.');
    $stage = 'withdrawal concurrency';
    $service = $publish('maintenance', ['title' => $tag . ' synthetic service', 'price_cents' => 10000, 'payload' => []]);
    $db->insert('customers', ['user_id' => (int)$u['consumer']['id'], 'broker_id' => (int)$u['broker']['id'], 'org_id' => $agencyOrg, 'name' => 'Synthetic test customer', 'notes' => '', 'stage' => 'new', 'created_at' => $now, 'updated_at' => $now]);
    $brokerOrder = $orders->create(['type' => 'maintenance', 'resource_id' => $service['id'], 'customer_id' => (int)$u['consumer']['id']], $u['broker']);
    $db->update('orders', $brokerOrder['id'], ['status' => 'completed', 'paid_cents' => 10000, 'completed_at' => $now]);
    $db->insert('ledger', ['user_id' => (int)$u['broker']['id'], 'order_id' => $brokerOrder['id'], 'beneficiary_role' => 'broker', 'entry_key' => $tag . '-withdraw-balance', 'amount_cents' => 1000, 'state' => 'available', 'available_at' => $now - 1, 'reversal_of' => null, 'created_at' => $now]);
    acceptanceCheck(acceptanceConcurrent('withdraw', $state, $directory) === [0,2], 'Two withdrawals cannot reserve above balance.');
    acceptanceCheck((int)$db->scalar('SELECT COALESCE(SUM(amount_cents),0) FROM withdrawals WHERE user_id=?', [(int)$u['broker']['id']]) === 600, 'Exactly one withdrawal reservation commits.');
    acceptanceCheck((new Finance($db))->balance($u['broker']['id'])['available_cents'] === 400, 'Residual withdrawable balance is correct.');
    $db->insert('ledger', ['user_id'=>(int)$u['broker']['id'],'order_id'=>$brokerOrder['id'],'beneficiary_role'=>'broker','entry_key'=>$tag.'-same-key-balance','amount_cents'=>1000,'state'=>'available','available_at'=>$now-1,'reversal_of'=>null,'created_at'=>$now]);
    acceptanceCheck(acceptanceConcurrent('withdraw_same_key',$state,$directory)===[0,0], 'Same-key withdrawal returns one reservation under concurrency.');
    acceptanceCheck((int)$db->scalar('SELECT COUNT(*) FROM withdrawals WHERE user_id=?',[(int)$u['broker']['id']])===2 && (new Finance($db))->balance($u['broker']['id'])['available_cents']===800, 'Duplicate withdrawal does not reserve twice.');
    acceptanceReject(function()use($db,$u,$state){(new Finance($db))->withdraw(['amount_cents'=>601,'idempotency_key'=>$state['tag'].'-withdraw_same_key-same'],$u['broker']);},409,'Withdrawal key cannot change amount.');
    $stage = 'identity and organization isolation';
    acceptanceCheck($orders->get($brokerOrder['id'], $u['agency'])['id'] === $brokerOrder['id'], 'Own agency can read broker order.');
    acceptanceReject(function () use ($orders, $brokerOrder, $u) { $orders->get($brokerOrder['id'], $u['merchant']); }, 404, 'Supplier merchant is isolated from broker order.');
    acceptanceReject(function () use ($orders, $brokerOrder, $u) { $orders->get($brokerOrder['id'], $u['otheragency']); }, 404, 'Other agency is isolated.');
    acceptanceReject(function () use ($orders, $brokerOrder, $u) { $orders->get($brokerOrder['id'], $u['outsider']); }, 404, 'Other customer is isolated.');
    acceptanceCheck((new Finance($db))->ledger($u['agency'], [])['total'] === 2, 'Agency ledger contains its own broker.');
    acceptanceCheck((new Finance($db))->ledger($u['merchant'], [])['total'] === 0, 'Merchant cannot read broker commission.');
    $stage = 'foreign key constraints';
    acceptanceCheck((int)$db->scalar("SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='merchants' AND CONSTRAINT_NAME='PRIMARY' AND COLUMN_NAME='credit_code'") === 1, 'Credit code is actual merchant primary key.');
    $nullOrg = $db->insert('organizations', ['name' => $tag . ' null code fixture', 'kind' => 'merchant', 'status' => 'pending', 'payload' => '{}', 'created_at' => $now]);
    acceptanceForeignKey(function () use ($db, $nullOrg, $now) { $db->insert('merchants', ['credit_code' => null, 'organization_id' => $nullOrg, 'legal_name' => 'Invalid synthetic', 'status' => 'pending', 'created_at' => $now]); }, 'Credit code cannot be NULL.');
    acceptanceForeignKey(function () use ($db, $u, $tag, $now) { $db->insert('resources', ['kind' => 'posts', 'org_id' => 0, 'owner_id' => (int)$u['consumer']['id'], 'title' => $tag, 'status' => 'pending', 'payload' => '{}', 'created_at' => $now, 'updated_at' => $now]); }, 'Organization zero is not a fake foreign key.');
    acceptanceForeignKey(function () use ($db, $u, $tag, $now) { $db->insert('resources', ['kind' => 'posts', 'merchant_credit_code' => '91370200000000000A', 'owner_id' => (int)$u['consumer']['id'], 'title' => $tag, 'status' => 'pending', 'payload' => '{}', 'created_at' => $now, 'updated_at' => $now]); }, 'Unknown merchant credit cannot enter resource domain.');
    acceptanceForeignKey(function () use ($db, $brokerOrder) { $db->update('orders', $brokerOrder['id'], ['customer_id' => 9223372036854775806]); }, 'Order customer must exist.');
    acceptanceForeignKey(function () use ($db, $brokerOrder) { $db->update('orders', $brokerOrder['id'], ['coupon_claim_id' => 9223372036854775806]); }, 'Order coupon reference must exist.');
    $stage = 'published personalized recommendations';
    $favoriteType = $catalog->save('boat_types',['title'=>$tag.' favorite category','payload'=>['category'=>$tag.'_favorite']],$u['platform']);
    $viewedType = $catalog->save('boat_types',['title'=>$tag.' viewed category','payload'=>['category'=>$tag.'_viewed']],$u['platform']);
    $privateType = $catalog->save('boat_types',['title'=>$tag.' unpublished category','status'=>'pending','payload'=>['category'=>$tag.'_favorite']],$u['platform']);
    $viewedBoat = $catalog->save('boats',['title'=>$tag.' viewed instance','payload'=>['boat_type_id'=>$viewedType['id'],'serial_number'=>$tag.'-reco']],$u['platform']);
    // Control ordering on only this fixture, without changing or clearing previous test data.
    $db->update('resources',$viewedType['id'],['created_at'=>(int)$db->scalar('SELECT MAX(created_at) FROM resources')+1]);
    $recommendations = new Recommendations($db);
    $cold = $recommendations->listing(['limit'=>2],null);
    acceptanceCheck(!$cold['personalized'] && $cold['based_on']==='recently_published' && $cold['items'][0]['id']===$viewedType['id'],'Cold start uses newest published category.');
    $record = ['user_id'=>(int)$u['consumer']['id'],'kind'=>'favorite','resource_id'=>$favoriteType['id'],'title'=>'Synthetic own preference','payload'=>'{}','status'=>'active','created_at'=>$now,'updated_at'=>$now];
    $favorite = $db->insert('user_records',$record);
    $record['kind']='footprint';$record['resource_id']=$viewedBoat['id'];$footprint=$db->insert('user_records',$record);
    $record['kind']='favorite';$record['resource_id']=$privateType['id'];$db->insert('user_records',$record);
    $personal = $recommendations->listing(['limit'=>2],$u['consumer']);
    acceptanceCheck($personal['personalized'] && array_column($personal['items'],'id')===[$favoriteType['id'],$viewedType['id']],'Own favorite outweighs own actual-instance view on MySQL.');
    $allPersonal = $recommendations->listing(['limit'=>24],$u['consumer']);
    acceptanceCheck(!in_array($privateType['id'],array_column($allPersonal['items'],'id'),true),'Unpublished category is never recommended.');
    $outside = $recommendations->listing(['limit'=>2],$u['outsider']);
    acceptanceCheck(!$outside['personalized'] && $outside['items'][0]['id']===$viewedType['id'],'Other customer does not inherit preference.');
    $db->update('user_records',$favorite,['status'=>'deleted']);
    $viewOnly = $recommendations->listing(['limit'=>2],$u['consumer']);
    acceptanceCheck($viewOnly['personalized'] && $viewOnly['items'][0]['id']===$viewedType['id'],'Own instance footprint alone ranks its boat type.');
    $db->update('user_records',$footprint,['status'=>'deleted']);
    acceptanceCheck(!$recommendations->listing(['limit'=>2],$u['consumer'])['personalized'],'Pending-only preferences do not activate personalization.');
    echo 'MySQL acceptance checks: ' . $checks . ' passed.' . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'MySQL acceptance failed during ' . $stage . '. No production data was accessed or cleared.' . PHP_EOL);
    $state = $error instanceof PDOException ? preg_replace('/[^A-Z0-9]/','',(string)$error->getCode()) : ($error instanceof ApiError ? (string)$error->status : 'OTHER');
    $number = $error instanceof PDOException && isset($error->errorInfo[1]) ? (int)$error->errorInfo[1] : 0;
    fwrite(STDERR,'Checks completed: '.$checks.'; class='.get_class($error).'; state='.$state.'; driver='.$number.PHP_EOL);
    if (get_class($error)==='RuntimeException' && preg_match('/^[A-Za-z .-]{1,180}$/D',$error->getMessage())) fwrite(STDERR,'Check: '.$error->getMessage().PHP_EOL);
    exit(1);
} finally {
    if ($directory && is_dir($directory)) {
        // Only files created by this test in its random private directory are removed.
        foreach (glob($directory . '/*') as $file) if (is_file($file) && !is_link($file)) unlink($file);
        rmdir($directory);
    }
}
