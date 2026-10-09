<?php
require_once __DIR__ . '/../../scripts/import-test-catalog.php';

use yuzhou\Catalog;
use yuzhou\Database;
use yuzhou\FileStore;

$checks = 0;
function importCheck($condition, $label)
{
    global $checks;
    if (!$condition) throw new RuntimeException($label);
    $checks++;
}
function importDenied(callable $callback, $label)
{
    try { $callback(); } catch (Throwable $error) { importCheck(true, $label); return; }
    throw new RuntimeException($label . ' was allowed');
}
function importDb($dsn = 'sqlite::memory:')
{
    $db = new Database(new PDO($dsn));
    $db->pdo->exec(file_get_contents(__DIR__ . '/../../database/sqlite.sql'));
    $db->pdo->exec(file_get_contents(__DIR__ . '/../../database/communication-sqlite.sql'));
    foreach (glob(__DIR__ . '/../../database/migrations/*-sqlite.sql') as $path) $db->pdo->exec(file_get_contents($path));
    $now = time();
    foreach (['platform.demo' => 'platform', 'untouched.demo' => 'consumer'] as $username => $role) {
        $db->insert('users', ['username' => $username, 'password_hash' => 'unchanged-fixture-password', 'nickname' => $username, 'role' => $role,
            'org_id' => null, 'created_at' => $now, 'updated_at' => $now]);
    }
    return $db;
}
function importCli($script, array $environment)
{
    $pipes = [];
    $process = proc_open(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
    if (!is_resource($process)) throw new RuntimeException('Import CLI could not start.');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
    return [proc_close($process), $stdout, $stderr];
}

$oldEnvironment = getenv('APP_ENV');
$oldStorage = getenv('STORAGE_PATH');
$temporary = sys_get_temp_dir() . '/yuzhou-catalog-import-' . bin2hex(random_bytes(8));
mkdir($temporary, 0700);
putenv('STORAGE_PATH=' . $temporary . '/files');
putenv('APP_ENV=test');
$dataDirectory = realpath(__DIR__ . '/../../data/test-catalog');
try {
    $data = TestCatalogImport::read($dataDirectory);
    importCheck(count($data['boats']) === 19 && count($data['posts']) === 2, 'Complete boat directory and two short news entries');
    importCheck($data['boats'][18]['external_id'] === '2138' && strpos($data['boats'][18]['name'], 'JS-910B') === 0, 'Actual JS-910B external ID/name preserved');
    importCheck($data['boats'][18]['width_m'] === '2.52' && $data['boats'][18]['parameters']['设计航速'] === '约65km/h（35kn），非实船测速值', 'Known JS-910B dimensions and estimated design speed preserve their meaning');
    importCheck(!preg_match('/企业资质|体系认证|资料依据|平台归属/', $data['boats'][18]['description']), 'Catalog does not claim merchant certification or show acquisition/source prose');
    importCheck($data['boats'][1]['parameters']['航区'] === '内河A、B、C级J1航段三峡库区(双机)；内河A、B、C级(单机)', 'Fragmented navigation-zone values stay attached to their parameter');
    importCheck(count(array_filter($data['boats'], function ($boat) { return $boat['capacity'] === 0; })) === 6, 'Six undeclared passenger capacities use the existing zero/unknown value instead of the default one');
    $db = importDb();
    $actor = $db->one('SELECT * FROM users WHERE username=?', ['platform.demo']);
    $catalog = new Catalog($db);
    $manual = $catalog->save('boat_types', ['title' => 'Untouched existing fixture', 'payload' => ['daily_price_cents' => 54321, 'capacity' => 8]], $actor);
    $usersBefore = $db->all('SELECT * FROM users ORDER BY id');
    mkdir(FileStore::root(), 0700);
    foreach ([['private-match.webp', $actor['id'], 1], ['other-owner.webp', 2, 0]] as $fixture) {
        copy($dataDirectory . '/assets/' . $data['boats'][0]['asset']['name'], FileStore::root() . '/' . $fixture[0]);
        $db->insert('uploads', ['owner_id' => $fixture[1], 'org_id' => null, 'storage_name' => $fixture[0], 'original_name' => $fixture[0],
            'mime' => 'image/webp', 'size_bytes' => filesize(FileStore::root() . '/' . $fixture[0]),
            'sha256' => $data['boats'][0]['asset']['sha256'], 'is_private' => $fixture[2], 'created_at' => time()]);
    }
    $result = TestCatalogImport::run($db, $dataDirectory);
    importCheck($result['created'] === 40 && $result['retained'] === 0, 'Nineteen types, nineteen demo entities and two news records created');
    importCheck($db->all('SELECT * FROM users ORDER BY id') === $usersBefore, 'Existing account identities and password hashes remain unchanged');
    importCheck($catalog->get($manual['id'], 'boat_types') === $manual, 'Existing synthetic resource remains unchanged');
    importCheck((int)$db->scalar('SELECT COUNT(*) FROM organizations') === 0 && (int)$db->scalar('SELECT COUNT(*) FROM merchants') === 0, 'No actual or fabricated merchant identity is created');
    importCheck((int)$db->scalar('SELECT COUNT(*) FROM uploads WHERE is_private=0 AND owner_id=?', [$actor['id']]) === 19, 'Private and different-owner matching images are not reused');
    foreach ($data['boats'] as $boat) {
        $type = $catalog->get($result['boat_type_ids'][$boat['external_id']], 'boat_types');
        $entity = $catalog->get($result['boat_ids'][$boat['external_id']], 'boats');
        importCheck($type['title'] === $boat['name'] && $type['payload']['external_id'] === $boat['external_id'] && $type['payload']['provider'] === 'ourideas_3d', 'Real boat name has a separate provider/external ID mapping');
        importCheck($entity['payload']['boat_type_id'] === $type['id'] && $entity['payload']['test_business'] && $entity['merchant_credit_code'] === null, 'Demo entity references its own normalized boat type without real merchant claims');
        importCheck($type['payload']['parameters'] === $boat['parameters'] && $type['payload']['capacity'] === $boat['capacity'], 'Declared and unknown vessel parameters are preserved');
        $file = (new FileStore($db))->read(substr($type['payload']['image_urls'][0], strlen('/api/v1/files/')), null);
        importCheck(!$file['private'] && hash_file('sha256', $file['_file']) === $boat['asset']['sha256'], 'Published picture is readable anonymously with original bytes');
        importCheck((bool)preg_match('/^[a-f0-9]{48}\.(webp|png)$/D', basename($file['_file'])), 'Stored image follows FileStore and backup object naming');
    }
    importCheck($result['boat_type_ids']['2138'] !== 2138 && $result['boat_ids']['2138'] !== 2138, '3D source ID is never assigned as a local resource or relational ID');
    foreach ($result['post_ids'] as $id) {
        $post = $catalog->get($id, 'posts');
        importCheck($post['owner_id'] === (int)$actor['id'] && $post['payload']['post_type'] === 'news' && !isset($post['payload']['source_url']), 'Public news uses the platform author without a source description');
    }
    $type = $catalog->get($result['boat_type_ids']['1'], 'boat_types', $actor);
    $catalog->save('boat_types', ['version' => $type['version'], 'title' => 'Existing admin edit', 'payload' => $type['payload']], $actor, $type['id']);
    $rowsBefore = $db->all('SELECT * FROM resources ORDER BY id');
    $filesBefore = $db->all('SELECT * FROM uploads ORDER BY id');
    $auditBefore = (int)$db->scalar('SELECT COUNT(*) FROM audit_logs');
    $again = TestCatalogImport::run($db, $dataDirectory);
    importCheck($again['created'] === 0 && $again['retained'] === 40 && $again['boat_type_ids'] === $result['boat_type_ids'], 'Second run is idempotent with identical local IDs');
    importCheck($db->all('SELECT * FROM resources ORDER BY id') === $rowsBefore && $db->all('SELECT * FROM uploads ORDER BY id') === $filesBefore && (int)$db->scalar('SELECT COUNT(*) FROM audit_logs') === $auditBefore, 'Second run preserves admin edits, upload IDs and audit history without writing');
    putenv('APP_ENV=production');
    importDenied(function () use ($db, $dataDirectory) { TestCatalogImport::run($db, $dataDirectory); }, 'Production import refused before writes');
    putenv('APP_ENV');
    importDenied(function () use ($db, $dataDirectory) { TestCatalogImport::run($db, $dataDirectory); }, 'Missing environment refused');
    putenv('APP_ENV=test');
    importDenied(function () use ($db, $dataDirectory) { TestCatalogImport::run($db, $dataDirectory, 'missing.demo'); }, 'Missing actor is not seeded automatically');
    importDenied(function () use ($db, $dataDirectory) { TestCatalogImport::run($db, $dataDirectory, 'untouched.demo'); }, 'Consumer cannot import platform catalog');

    $badDirectory = $temporary . '/dataset';
    mkdir($badDirectory, 0700);
    mkdir($badDirectory . '/assets', 0700);
    foreach ($data['boats'] as $boat) copy($dataDirectory . '/assets/' . $boat['asset']['name'], $badDirectory . '/assets/' . $boat['asset']['name']);
    $bad = $data;
    $bad['boats'][] = $data['boats'][0];
    file_put_contents($badDirectory . '/catalog.json', json_encode($bad));
    importDenied(function () use ($db, $badDirectory) { TestCatalogImport::run($db, $badDirectory); }, 'Duplicate incoming external ID refused');
    $bad = $data;
    $bad['boats'][0]['asset']['name'] = '../catalog.json';
    file_put_contents($badDirectory . '/catalog.json', json_encode($bad));
    importDenied(function () use ($db, $badDirectory) { TestCatalogImport::run($db, $badDirectory); }, 'Traversing asset name refused');
    $bad = $data;
    $bad['boats'][0]['asset']['sha256'] = str_repeat('0', 64);
    file_put_contents($badDirectory . '/catalog.json', json_encode($bad));
    importDenied(function () use ($db, $badDirectory) { TestCatalogImport::run($db, $badDirectory); }, 'Changed picture bytes refused');
    $bad = $data;
    $bad['boats'][0]['asset']['mime'] = 'video/mp4';
    file_put_contents($badDirectory . '/catalog.json', json_encode($bad));
    importDenied(function () use ($db, $badDirectory) { TestCatalogImport::run($db, $badDirectory); }, 'Wrong picture MIME refused');
    importCheck($db->all('SELECT * FROM resources ORDER BY id') === $rowsBefore && $db->all('SELECT * FROM uploads ORDER BY id') === $filesBefore, 'Rejected imports leave existing catalog and media untouched');

    $rollbackDb = importDb();
    putenv('STORAGE_PATH=' . $temporary . '/rollback-files');
    $bad = $data;
    $bad['boats'][1]['category'] = '';
    file_put_contents($badDirectory . '/catalog.json', json_encode($bad));
    importDenied(function () use ($rollbackDb, $badDirectory) { TestCatalogImport::run($rollbackDb, $badDirectory); }, 'Invalid later resource stops the entire batch');
    importCheck((int)$rollbackDb->scalar('SELECT COUNT(*) FROM resources') === 0 && (int)$rollbackDb->scalar('SELECT COUNT(*) FROM uploads') === 0 && count(glob(FileStore::root() . '/*')) === 0, 'Failed transaction rolls back prior resources and removes copied public pictures');

    $duplicate = $db->one('SELECT * FROM resources WHERE id=?', [$result['boat_type_ids']['1']]);
    unset($duplicate['id']);
    $db->insert('resources', $duplicate);
    putenv('STORAGE_PATH=' . $temporary . '/files');
    importDenied(function () use ($db, $dataDirectory) { TestCatalogImport::run($db, $dataDirectory); }, 'Ambiguous existing mapping is refused instead of guessing');

    $release = $temporary . '/release';
    mkdir($release . '/deploy', 0700, true);
    mkdir($release . '/backend/extend/yuzhou', 0700, true);
    mkdir($release . '/data', 0700);
    copy(__DIR__ . '/../../scripts/import-test-catalog.php', $release . '/deploy/import-test-catalog.php');
    foreach (glob(__DIR__ . '/../../backend/extend/yuzhou/*.php') as $class) copy($class, $release . '/backend/extend/yuzhou/' . basename($class));
    symlink($dataDirectory, $release . '/data/test-catalog');
    $cliDb = importDb('sqlite:' . $temporary . '/cli.sqlite');
    $cliEnvironment = array_merge(getenv(), ['APP_ENV' => 'test', 'DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => $temporary . '/cli.sqlite', 'STORAGE_PATH' => $temporary . '/cli-files']);
    file_put_contents($release . '/backend/.env', "APP_ENV=test\n");
    $cli = importCli($release . '/deploy/import-test-catalog.php', $cliEnvironment);
    importCheck($cli[0] === 0 && (json_decode($cli[1], true)['created'] ?? null) === 40, 'Deploy-position CLI resolves its own backend and data without relying on the working directory');
    $cli = importCli($release . '/deploy/import-test-catalog.php', $cliEnvironment);
    importCheck($cli[0] === 0 && (json_decode($cli[1], true)['retained'] ?? null) === 40, 'Deploy-position CLI rerun retains existing records');
    file_put_contents($release . '/backend/.env', "APP_ENV=production\n");
    $cliEnvironment['DB_DRIVER'] = 'invalid-driver';
    $cli = importCli($release . '/deploy/import-test-catalog.php', $cliEnvironment);
    importCheck($cli[0] === 1 && strpos($cli[2], 'configured installation') !== false && strpos($cli[2], 'Database') === false, 'Shell test override cannot seed a configured production installation or connect its database');
    echo 'Catalog import checks passed: ' . $checks . PHP_EOL;
} finally {
    $paths = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($paths as $path) { if ($path->isDir() && !$path->isLink()) rmdir($path->getPathname()); else unlink($path->getPathname()); }
    rmdir($temporary);
    $oldEnvironment === false ? putenv('APP_ENV') : putenv('APP_ENV=' . $oldEnvironment);
    $oldStorage === false ? putenv('STORAGE_PATH') : putenv('STORAGE_PATH=' . $oldStorage);
}
