<?php
require_once __DIR__ . '/../../backend/bin/refresh-catalog-copy.php';
require_once __DIR__ . '/../../scripts/import-test-catalog.php';

use yuzhou\Catalog;
use yuzhou\Database;
use yuzhou\Orders;

$checks = 0;
function copyCheck($condition, $label)
{
    global $checks;
    if (!$condition) throw new RuntimeException($label);
    $checks++;
}
function copyDenied(callable $callback, $label)
{
    try { $callback(); } catch (Throwable $error) { copyCheck(true, $label); return; }
    throw new RuntimeException($label . ' was allowed');
}
function copyCli($script, array $environment)
{
    $pipes = [];
    $process = proc_open(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' --seed', [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
    if (!is_resource($process)) throw new RuntimeException('Fixture CLI could not start.');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
    return [proc_close($process), $stdout, $stderr];
}
function copySnapshot(Database $db)
{
    $snapshot = [];
    foreach ($db->all("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name") as $table) {
        $snapshot[$table['name']] = $db->all('SELECT * FROM ' . $table['name'] . ' ORDER BY rowid');
    }
    return $snapshot;
}

$oldEnvironment = getenv('APP_ENV'); $oldStorage = getenv('STORAGE_PATH');
$temporary = sys_get_temp_dir() . '/yuzhou-catalog-copy-' . bin2hex(random_bytes(8));
mkdir($temporary, 0700);
putenv('APP_ENV=test'); putenv('STORAGE_PATH=' . $temporary . '/files');
try {
    $environment = array_merge(getenv(), ['APP_ENV' => 'test', 'DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => $temporary . '/fixture.sqlite',
        'STORAGE_PATH' => $temporary . '/files', 'SEED_PASSWORD' => 'CopyFixtureOnly2026']);
    $seed = copyCli(__DIR__ . '/../../backend/bin/init.php', $environment);
    copyCheck($seed[0] === 0, 'Current seed works: ' . $seed[2]);
    $db = new Database(new PDO('sqlite:' . $temporary . '/fixture.sqlite'));
    $catalog = new Catalog($db);
    foreach ($db->all('SELECT id FROM resources ORDER BY id') as $row) {
        $resource = $catalog->get($row['id']);
        copyCheck(!preg_match('/演示|测试版|独立测试环境/', $resource['title'] . json_encode($resource['payload'], JSON_UNESCAPED_UNICODE)), 'New public seed uses product copy');
    }
    $import = TestCatalogImport::run($db, __DIR__ . '/../../data/test-catalog');
    foreach ($import['boat_ids'] as $id) {
        $entity = $catalog->get($id, 'boats');
        copyCheck(!preg_match('/演示|测试版/', $entity['title'] . $entity['payload']['description'] . $entity['payload']['location']), 'New imported entities use neutral display copy');
    }
    $db->run('UPDATE users SET username=? WHERE id=1', ['admin']);
    $db->run('UPDATE users SET username=? WHERE id=3', ['broker']);
    foreach ($db->all('SELECT id,role FROM users') as $row) $db->update('users', $row['id'], ['nickname' => '演示 ' . $row['role']]);
    $db->update('organizations', 1, ['name' => '演示船艇商家（测试）']);
    $db->update('organizations', 2, ['name' => '演示经纪公司（测试）']);
    $db->run('UPDATE merchants SET legal_name=? WHERE credit_code=?', ['演示商家（合成测试资料）', '91370200MA3YY0001D']);
    $db->update('customers', 1, ['name' => '演示消费者', 'notes' => '合成CRM演示资料']);
    foreach ($db->all('SELECT * FROM resources WHERE id<=13 ORDER BY id') as $row) {
        $payload = json_decode($row['payload'], true);
        if ($row['kind'] === 'boat_types') $payload['description'] = '合成演示船型；实际船艇与价格由管理端录入。';
        if ($row['kind'] === 'products') $payload['description'] = '平台直营测试商品';
        if ($row['kind'] === 'events') $payload['description'] = '免费合成活动，用于测试报名与电子票核销。';
        if ($row['kind'] === 'courses') $payload['description'] = '免费课程；请管理端上传实际教学视频。';
        if ($row['kind'] === 'posts') $payload['content'] = '这里是独立测试环境，船艇、商品和活动均为合成演示资料。真实交易服务尚未配置。';
        $payload['retained_custom_field'] = 'preserve-' . $row['id'];
        $db->update('resources', $row['id'], ['title' => $row['title'] . '（演示）', 'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE)]);
    }
    $db->run('UPDATE boats SET location=? WHERE resource_id IN (2,3,4)', ['演示码头']);
    $db->run('UPDATE events SET location=? WHERE resource_id=11', ['演示码头']);
    foreach ($import['boat_ids'] as $id) {
        $row = $db->one('SELECT * FROM resources WHERE id=?', [$id]);
        $payload = json_decode($row['payload'], true);
        $payload['description'] = '用于测试预订和履约的演示实体，价格与库存仅供测试。';
        $db->update('resources', $id, ['title' => str_replace(' · 1号', ' · 演示1号', $row['title']), 'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE)]);
        $db->run('UPDATE boats SET location=? WHERE resource_id=?', ['演示码头', $id]);
    }
    $consumer = $db->one('SELECT * FROM users WHERE id=2');
    $personal = $catalog->save('posts', ['title' => '欢迎来到驭舟（演示）', 'payload' => ['content' => '我的个人航海记录']], $consumer);
    $order = (new Orders($db))->create(['type' => 'mall', 'resource_id' => 7, 'quantity' => 1], $consumer);
    $db->insert('ledger', ['user_id' => 2, 'order_id' => $order['id'], 'beneficiary_role' => 'member', 'entry_key' => 'copy-preserved-ledger',
        'amount_cents' => 78900, 'state' => 'frozen', 'available_at' => time(), 'created_at' => time()]);
    $before = copySnapshot($db);
    copy($temporary . '/fixture.sqlite', $temporary . '/before.sqlite');
    $dry = CatalogCopyRefresh::run($db);
    copyCheck(!$dry['applied'] && $dry['changed_rows'] === 64, 'Dry-run identifies 41 display records and 23 normalized location rows');
    copyCheck(copySnapshot($db) === $before, 'Dry-run leaves every table and audit entry unchanged');
    $result = CatalogCopyRefresh::run($db, true);
    copyCheck($result['applied'] && $result['changed_rows'] === 64, 'Applying performs the same exact display-only plan');
    $after = copySnapshot($db);
    foreach ($before as $table => $rows) {
        if ($table === 'audit_logs') continue;
        $allowed = ['users' => ['nickname'], 'organizations' => ['name'], 'merchants' => ['legal_name'], 'customers' => ['name', 'notes'],
            'resources' => ['title', 'payload', 'version', 'updated_at'], 'boats' => ['location'], 'events' => ['location']][$table] ?? [];
        $actual = $after[$table];
        foreach ($rows as &$row) foreach ($allowed as $field) unset($row[$field]); unset($row);
        foreach ($actual as &$row) foreach ($allowed as $field) unset($row[$field]); unset($row);
        copyCheck($actual === $rows, 'IDs, identity, permission, inventory, price, finance and all other fields retained in ' . $table);
    }
    foreach ($after['resources'] as $row) {
        $old = array_values(array_filter($before['resources'], function ($candidate) use ($row) { return $candidate['id'] === $row['id']; }))[0];
        $payload = json_decode($row['payload'], true); $oldPayload = json_decode($old['payload'], true);
        foreach (['description', 'content'] as $field) { unset($payload[$field], $oldPayload[$field]); }
        copyCheck($payload === $oldPayload, 'Resource images, provider mapping, synthetic marker and custom metadata retained');
    }
    copyCheck(count($after['audit_logs']) === count($before['audit_logs']) + 41, 'Only display maintenance audits appended');
    copyCheck($catalog->get($personal['id'], 'posts', $consumer) === $personal, 'A user-created post with the same old title remains intact');
    copyCheck(strpos($catalog->get(13, 'posts')['payload']['content'], '欢迎来到驭舟航海社区') === 0, 'Existing welcome post gets community copy');
    copyCheck(strpos($db->scalar('SELECT legal_name FROM merchants'), '合成测试资料') !== false, 'Synthetic business qualification remains explicit');
    copyCheck(CatalogCopyRefresh::run($db, true)['changed_rows'] === 0 && copySnapshot($db) === $after, 'Second run makes no writes or new audits');

    $editedDb = new Database(new PDO('sqlite:' . $temporary . '/before.sqlite'));
    $welcome = $editedDb->one('SELECT * FROM resources WHERE id=13');
    $payload = json_decode($welcome['payload'], true); $payload['content'] = '管理员已编辑的社区公告';
    $editedDb->update('resources', 13, ['payload' => json_encode($payload)]);
    $editedDb->update('resources', 7, ['title' => '管理员的商品标题']);
    $editedProduct = $editedDb->one('SELECT * FROM resources WHERE id=8');
    $payload = json_decode($editedProduct['payload'], true); $payload['description'] = '商家已编辑的商品说明';
    $editedDb->update('resources', 8, ['payload' => json_encode($payload)]);
    $editedBoatId = $import['boat_ids']['1'];
    $editedDb->run('UPDATE boats SET serial_number=? WHERE resource_id=?', ['MANUAL-BOAT-SERIAL', $editedBoatId]);
    $editedBoat = $editedDb->one('SELECT * FROM resources WHERE id=?', [$editedBoatId]);
    $editedDb->update('users', 2, ['nickname' => '用户自选昵称']);
    $editedBefore = [$editedDb->one('SELECT * FROM resources WHERE id=13'), $editedDb->one('SELECT * FROM resources WHERE id=7'), $editedDb->one('SELECT * FROM users WHERE id=2')];
    CatalogCopyRefresh::run($editedDb, true);
    copyCheck([$editedDb->one('SELECT * FROM resources WHERE id=13'), $editedDb->one('SELECT * FROM resources WHERE id=7'), $editedDb->one('SELECT * FROM users WHERE id=2')] === $editedBefore, 'Existing user nickname, product title and welcome-body edits are not overwritten');
    copyCheck($editedDb->scalar('SELECT title FROM resources WHERE id=8') === '船用救生衣'
        && json_decode($editedDb->scalar('SELECT payload FROM resources WHERE id=8'), true)['description'] === '商家已编辑的商品说明', 'An old product title can change without replacing an edited description');
    copyCheck($editedDb->one('SELECT * FROM resources WHERE id=?', [$editedBoatId]) === $editedBoat
        && $editedDb->scalar('SELECT location FROM boats WHERE resource_id=?', [$editedBoatId]) === '演示码头', 'An entity with an edited serial no longer matches the original imported fixture');

    copy($temporary . '/fixture.sqlite', $temporary . '/rollback.sqlite');
    $rollbackDb = new Database(new PDO('sqlite:' . $temporary . '/rollback.sqlite'));
    $rollbackDb->update('users', 1, ['nickname' => '演示 platform']);
    $rollbackDb->update('users', 2, ['nickname' => '演示 consumer']);
    $rollbackDb->pdo->exec("CREATE TRIGGER stop_copy BEFORE INSERT ON audit_logs WHEN NEW.object_type='users' AND NEW.object_id=2 BEGIN SELECT RAISE(ABORT,'forced copy failure'); END");
    $rollbackBefore = copySnapshot($rollbackDb);
    copyDenied(function () use ($rollbackDb) { CatalogCopyRefresh::run($rollbackDb, true); }, 'Failure in a later audit rolls back the complete transaction');
    copyCheck(copySnapshot($rollbackDb) === $rollbackBefore, 'No partial nickname or audit update survives failed transaction');
    copyDenied(function () use ($db) { CatalogCopyRefresh::run($db, true, 'broker'); }, 'A broker cannot run platform copy maintenance');
    putenv('APP_ENV=production');
    copyDenied(function () use ($db) { CatalogCopyRefresh::run($db, true); }, 'Production environment is rejected before writes');
    copyCheck(copySnapshot($db) === $after, 'Rejected actor and environment leave every table unchanged');
    putenv('APP_ENV=test');
    $configured = $temporary . '/configured/backend';
    mkdir($configured . '/bin', 0700, true); mkdir($configured . '/extend/yuzhou', 0700, true);
    copy(__DIR__ . '/../../backend/bin/refresh-catalog-copy.php', $configured . '/bin/refresh-catalog-copy.php');
    copy(__DIR__ . '/../../backend/extend/yuzhou/bootstrap.php', $configured . '/extend/yuzhou/bootstrap.php');
    file_put_contents($configured . '/.env', "APP_ENV=production\n");
    $blocked = copyCli($configured . '/bin/refresh-catalog-copy.php', array_merge($environment, ['DB_DRIVER' => 'invalid-driver']));
    copyCheck($blocked[0] === 1 && strpos($blocked[2], 'configured installation') !== false, 'Shell test override cannot reach a configured production database');
    echo 'Catalog display-copy checks passed: ' . $checks . PHP_EOL;
} finally {
    $paths = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($paths as $path) { if ($path->isDir() && !$path->isLink()) rmdir($path->getPathname()); else unlink($path->getPathname()); }
    rmdir($temporary);
    $oldEnvironment === false ? putenv('APP_ENV') : putenv('APP_ENV=' . $oldEnvironment);
    $oldStorage === false ? putenv('STORAGE_PATH') : putenv('STORAGE_PATH=' . $oldStorage);
}
