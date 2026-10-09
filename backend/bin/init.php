<?php
if (PHP_SAPI !== 'cli') exit(1);
require dirname(__DIR__) . '/extend/yuzhou/bootstrap.php';

use yuzhou\Database;
use yuzhou\Catalog;
use yuzhou\Community;

try {
    $db = Database::connect();
    $root = dirname(dirname(__DIR__));
    $exists = $db->driver === 'sqlite'
        ? (bool)$db->one('SELECT name FROM sqlite_master WHERE type=? AND name=?', ['table', 'users'])
        : (bool)$db->one('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', ['users']);
    $skipDdl = in_array('--seed-only', $argv, true) || in_array('--skip-migrations', $argv, true);
    if (!$exists && $skipDdl) throw new RuntimeException('Schema is missing. Apply it with a separate migration account first.');
    if (!$exists) {
        $db->pdo->exec(file_get_contents($root . '/database/' . $db->driver . '.sql'));
        $db->pdo->exec(file_get_contents($root . '/database/communication-' . $db->driver . '.sql'));
        echo "Independent schema initialized.\n";
    } else echo "Existing schema retained; no tables recreated.\n";
    if (!$skipDdl) {
        if ($db->driver === 'sqlite' && !in_array('idempotency_key',array_column($db->all('PRAGMA table_info(withdrawals)'),'name'),true)) $db->pdo->exec('ALTER TABLE withdrawals ADD COLUMN idempotency_key TEXT');
        foreach (glob($root . '/database/migrations/*-' . $db->driver . '.sql') as $migration) $db->pdo->exec(file_get_contents($migration));
    }
    if (!in_array('--seed', $argv, true) && !in_array('--seed-only', $argv, true)) exit(0);
    if (!in_array(getenv('APP_ENV'), ['development', 'test'], true)) throw new RuntimeException('Development seed is forbidden outside development/test.');
    if ((int)$db->scalar('SELECT COUNT(*) FROM users') > 0) { echo "Seed skipped: users already exist.\n"; exit(0); }
    $password = getenv('SEED_PASSWORD');
    if (!$password || strlen($password) < 12) throw new RuntimeException('Set SEED_PASSWORD with at least 12 characters; it is never printed.');
    $db->transaction(function () use ($db, $password) {
        $now = time();
        $merchantOrg = $db->insert('organizations', ['name' => '驭舟船艇商家', 'kind' => 'merchant', 'status' => 'approved', 'payload' => '{}', 'created_at' => $now]);
        $agencyOrg = $db->insert('organizations', ['name' => '驭舟经纪公司', 'kind' => 'agency', 'status' => 'approved', 'payload' => '{}', 'created_at' => $now]);
        $users = [];
        foreach (['platform' => null, 'consumer' => null, 'broker' => $agencyOrg, 'merchant' => $merchantOrg, 'agency' => $agencyOrg] as $role => $orgId) {
            $id = $db->insert('users', ['username' => $role . '.demo', 'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'nickname' => ['platform' => '平台管理员', 'consumer' => '航海爱好者', 'broker' => '经纪人', 'merchant' => '商家', 'agency' => '经纪公司管理员'][$role],
                'role' => $role, 'org_id' => $orgId, 'status' => 'active', 'member_until' => $role === 'consumer' ? (new DateTimeImmutable())->modify('+6 months')->getTimestamp() : 0,
                'growth_points' => 0, 'identity_status' => 'unverified', 'created_at' => $now, 'updated_at' => $now]);
            $users[$role] = $db->one('SELECT * FROM users WHERE id=?', [$id]);
        }
        $storage = yuzhou\FileStore::root(); if (!is_dir($storage)) mkdir($storage, 0700, true);
        $filename = 'development-license-' . bin2hex(random_bytes(8)) . '.png';
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
        file_put_contents($storage . '/' . $filename, $png); chmod($storage . '/' . $filename, 0600);
        $license = $db->insert('uploads', ['owner_id' => (int)$users['merchant']['id'], 'org_id' => $merchantOrg, 'storage_name' => $filename, 'original_name' => 'development-fixture.png',
            'mime' => 'image/png', 'size_bytes' => strlen($png), 'sha256' => hash('sha256', $png), 'is_private' => 1, 'created_at' => $now]);
        // Synthetic test identity, not a verified business license or real customer record.
        $credit = '91370200MA3YY0001D';
        $db->insert('merchants', ['credit_code' => $credit, 'organization_id' => $merchantOrg, 'legal_name' => '商家（合成测试资料）', 'license_file_id' => $license, 'status' => 'approved', 'created_at' => $now]);
        $catalog = new Catalog($db);
        $create = function ($kind, $body, $owner) use ($catalog, $users) {
            $row = $catalog->save($kind, $body, $owner);
            if ($row['status'] !== 'published') $row = $catalog->save($kind, ['version' => $row['version'], 'status' => 'published'], $users['platform'], $row['id']);
            return $row;
        };
        $type = $create('boat_types', ['title' => '驭舟 42 休闲游艇', 'price_cents' => 280000, 'payload' => ['category' => 'yacht', 'length_m' => '12.8', 'capacity' => 10, 'daily_price_cents' => 280000,
            'deposit_cents' => 500000, 'parameters' => ['beam_m' => 3.9, 'draft_m' => 1.1, 'power_hp' => 380, 'material' => '复合材料'], 'description' => '休闲游艇，支持船型参数查看、预订与咨询。', 'image_urls' => [], 'vr_url' => '']], $users['merchant']);
        for ($i = 1; $i <= 3; $i++) $create('boats', ['title' => '驭舟 42 · ' . $i . '号', 'price_cents' => 268000000, 'payload' => ['boat_type_id' => $type['id'], 'serial_number' => 'DEMO-YZ-42-' . $i, 'location' => '码头待确认', 'image_urls' => []]], $users['merchant']);
        $create('upgrades', ['title' => '航电与舒适升级', 'price_cents' => 680000, 'payload' => ['boat_type_id' => $type['id'], 'description' => '管理端可配置可选设备与实际报价。', 'options' => [['id' => 'navigation', 'title' => '导航设备', 'price_cents' => 180000], ['id' => 'comfort', 'title' => '舒适座舱', 'price_cents' => 260000]]]], $users['merchant']);
        $create('maintenance', ['title' => '年度船艇保养', 'price_cents' => 120000, 'payload' => ['boat_type_id' => $type['id'], 'description' => '保养项目和履约由相应经营主体确认。']], $users['merchant']);
        foreach ([['航海防水包', 9900], ['船用救生衣', 19900], ['航海指南', 5900]] as $product) $create('products', ['title' => $product[0], 'price_cents' => $product[1], 'payload' => ['stock' => 50, 'description' => '航海出行用品', 'image_urls' => []]], $users['platform']);
        $coupon = $create('coupons', ['title' => '新客体验券', 'payload' => ['discount_cents' => 1000, 'min_amount_cents' => 5000, 'expires_at' => date('Y-m-d H:i:s', $now + 90 * 86400), 'stock' => 100, 'order_types' => ['mall']]], $users['platform']);
        $create('events', ['title' => '周末航海体验', 'price_cents' => 0, 'payload' => ['starts_at' => date('Y-m-d H:i:s', $now + 14 * 86400), 'capacity' => 20, 'location' => '码头待确认', 'description' => '活动详情与集合地点以报名确认为准。']], $users['platform']);
        $create('courses', ['title' => '航海安全入门', 'payload' => ['description' => '航海安全基础知识，出航前了解救生与天气条件。', 'pass_score' => 60,
            'lessons' => [['id' => 'safety', 'title' => '安全基础', 'video_url' => '', 'duration_seconds' => 120]],
            'questions' => [['question' => '出航前应先检查哪些内容？', 'options' => ['救生与天气条件', '仅船体颜色'], 'answer' => 0], ['question' => '救生衣应怎样使用？', 'options' => ['合身并按规定佩戴', '只放在行李中'], 'answer' => 0]]]], $users['platform']);
        $post = $catalog->save('posts', ['title' => '欢迎来到驭舟', 'payload' => ['content' => '欢迎来到驭舟航海社区！在这里分享航海见闻、交流船艇使用经验，也可以发布你的出海故事。一起发现更广阔的水上生活。', 'image_urls' => []]], $users['consumer']);
        (new Community($db))->reviewPost($post['id'], ['status' => 'published'], $users['platform']);
        $db->insert('customers', ['user_id' => (int)$users['consumer']['id'], 'broker_id' => (int)$users['broker']['id'], 'org_id' => $agencyOrg, 'name' => '航海爱好者', 'notes' => '合成CRM资料', 'stage' => 'new', 'created_at' => $now, 'updated_at' => $now]);
    });
    echo "Synthetic development catalog and five demo roles created; no external service or real transaction was invoked.\n";
} catch (Throwable $error) {
    if (getenv('APP_DEBUG') === '1' && in_array(getenv('APP_ENV'), ['development', 'test'], true)) {
        $trace = []; foreach (array_slice($error->getTrace(), 0, 5) as $frame) $trace[] = ['file' => basename($frame['file'] ?? ''), 'line' => $frame['line'] ?? 0, 'function' => $frame['function'] ?? ''];
        fwrite(STDERR, json_encode(['class' => get_class($error), 'file' => basename($error->getFile()), 'line' => $error->getLine(), 'trace' => $trace]) . PHP_EOL);
    }
    fwrite(STDERR, 'Initialization stopped: ' . ($error instanceof RuntimeException && !($error instanceof PDOException) ? $error->getMessage() : 'database or storage operation failed') . "\n");
    exit(1);
}
