<?php
// CLI only; creates missing test resources without reseeding accounts or replacing edited records.
require_once dirname(__DIR__) . '/backend/extend/yuzhou/bootstrap.php';

use yuzhou\Catalog;
use yuzhou\Database;
use yuzhou\FileStore;
use yuzhou\Rbac;
use yuzhou\Validation;

class TestCatalogImport
{
    public static function guard()
    {
        if (PHP_SAPI !== 'cli' || !in_array(getenv('APP_ENV'), ['development', 'test'], true)) {
            throw new RuntimeException('Test catalog import is restricted to CLI development/test.');
        }
        // A shell override must not turn a configured production installation into a seed target.
        $environmentFile = dirname(__DIR__) . '/backend/.env';
        if (is_file($environmentFile)) foreach (file($environmentFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (!preg_match('/^APP_ENV=(.*)$/D', trim($line), $match)) continue;
            if (!in_array(trim($match[1], " \t\r\n\"'"), ['development', 'test'], true)) {
                throw new RuntimeException('The configured installation does not permit test catalog import.');
            }
            break;
        }
    }

    public static function read($directory)
    {
        self::guard();
        $directory = realpath($directory);
        if (!$directory || !is_dir($directory)) throw new RuntimeException('Catalog directory is missing.');
        $data = json_decode(file_get_contents($directory . '/catalog.json'), true);
        if (!is_array($data) || ($data['schema_version'] ?? null) !== 1 || !is_array($data['boats'] ?? null) || !is_array($data['posts'] ?? null)) {
            throw new RuntimeException('Invalid test catalog dataset.');
        }
        $seen = [];
        foreach (['boats', 'posts'] as $kind) foreach ($data[$kind] as $row) {
            if (!is_array($row) || !is_string($row['external_id'] ?? null) || !preg_match('/^[A-Za-z0-9_-]{1,100}$/D', $row['external_id'])) {
                throw new RuntimeException('Invalid external catalog ID.');
            }
            $key = $kind . ':' . $row['external_id'];
            if (isset($seen[$key])) throw new RuntimeException('Duplicate external catalog ID.');
            $seen[$key] = true;
            Validation::text($row[$kind === 'boats' ? 'name' : 'title'] ?? '', 'title', 200);
            if ($kind === 'boats') {
                $asset = $row['asset'] ?? [];
                if (!is_string($asset['name'] ?? null) || !preg_match('/^[A-Za-z0-9_-]+\.(png|webp|jpg)$/D', $asset['name'])) {
                    throw new RuntimeException('Invalid catalog image name.');
                }
                $assetPath = $directory . '/assets/' . $asset['name'];
                $actualPath = realpath($assetPath);
                if (!$actualPath || is_link($assetPath) || strpos($actualPath, $directory . '/assets/') !== 0 || !is_file($actualPath)) {
                    throw new RuntimeException('Catalog image must be a regular local asset.');
                }
                $mime = (new finfo(FILEINFO_MIME_TYPE))->file($actualPath);
                if (!in_array($mime, ['image/png', 'image/webp', 'image/jpeg'], true) || $mime !== ($asset['mime'] ?? null)
                    || filesize($actualPath) < 1 || filesize($actualPath) > 5 * 1024 * 1024
                    || !is_string($asset['sha256'] ?? null) || !hash_equals($asset['sha256'], hash_file('sha256', $actualPath))) {
                    throw new RuntimeException('Catalog image integrity or media type failed.');
                }
            }
        }
        return $data;
    }

    public static function run(Database $db, $directory, $username = 'platform.demo')
    {
        self::guard();
        $data = self::read($directory);
        $directory = realpath($directory);
        $createdFiles = [];
        try {
            return $db->transaction(function () use ($db, $directory, $username, $data, &$createdFiles) {
                // All importer actors share one current-read lock, including concurrent MySQL runs.
                $db->one('SELECT id FROM users WHERE role=? ORDER BY id LIMIT 1' . $db->lock(), ['platform']);
                $actor = $db->one('SELECT * FROM users WHERE username=?' . $db->lock(), [$username]);
                if (!$actor || $actor['role'] !== 'platform' || $actor['status'] !== 'active' || $actor['org_id'] !== null) {
                    throw new RuntimeException('An existing active platform test account is required.');
                }
                $permissions = (new Rbac($db))->effective($actor);
                if (array_diff(['catalog', 'community'], $permissions)) throw new RuntimeException('The selected account lacks catalog/community access.');
                $existing = [];
                foreach ($db->all('SELECT * FROM resources WHERE kind IN (?,?,?)' . $db->lock(), ['boat_types', 'boats', 'posts']) as $row) {
                    $payload = json_decode($row['payload'], true) ?: [];
                    if (!in_array($payload['provider'] ?? '', ['ourideas_3d', 'public_maritime'], true) || !isset($payload['external_id'])) continue;
                    $key = $row['kind'] . ':' . $payload['provider'] . ':' . $payload['external_id'];
                    if (isset($existing[$key])) throw new RuntimeException('Duplicate existing import mapping; resolve it before importing.');
                    if ((int)$row['owner_id'] !== (int)$actor['id']) throw new RuntimeException('An existing import mapping belongs to another account.');
                    $existing[$key] = $row;
                }
                $catalog = new Catalog($db);
                $report = ['created' => 0, 'retained' => 0, 'boat_type_ids' => [], 'boat_ids' => [], 'post_ids' => []];
                $save = function ($kind, $provider, $externalId, array $body) use ($catalog, $actor, &$existing, &$report) {
                    $key = $kind . ':' . $provider . ':' . $externalId;
                    if (isset($existing[$key])) { $report['retained']++; return (int)$existing[$key]['id']; }
                    $body['status'] = 'published';
                    $body['payload']['provider'] = $provider;
                    $body['payload']['external_id'] = $externalId;
                    $body['payload']['test_business'] = true;
                    $row = $catalog->save($kind, $body, $actor);
                    $existing[$key] = $row;
                    $report['created']++;
                    return $row['id'];
                };
                foreach ($data['boats'] as $boat) {
                    $typeKey = 'boat_types:ourideas_3d:' . $boat['external_id'];
                    $boatKey = 'boats:ourideas_3d:' . $boat['external_id'];
                    $image = null;
                    if (!isset($existing[$typeKey]) || !isset($existing[$boatKey])) {
                        $image = self::image($db, $directory, $boat['asset'], $actor, $createdFiles);
                    }
                    $payload = ['category' => $boat['category'], 'model_code' => $boat['model_code'], 'length_m' => $boat['length_m'],
                        'capacity' => $boat['capacity'], 'parameters' => $boat['parameters'], 'description' => $boat['description'],
                        'daily_price_cents' => 10000, 'deposit_cents' => 20000, 'vr_url' => $boat['vr_url'], 'image_urls' => $image ? [$image] : []];
                    if (isset($boat['width_m'])) $payload['width_m'] = $boat['width_m'];
                    $typeId = $save('boat_types', 'ourideas_3d', $boat['external_id'], ['title' => $boat['name'], 'price_cents' => 10000, 'payload' => $payload]);
                    $boatId = $save('boats', 'ourideas_3d', $boat['external_id'], ['title' => $boat['name'] . ' · 演示1号', 'price_cents' => 100000000,
                        'payload' => ['boat_type_id' => $typeId, 'serial_number' => 'YZ-TEST-3D-' . $boat['external_id'], 'location' => '演示码头',
                            'image_urls' => $image ? [$image] : [], 'description' => '用于测试预订和履约的演示实体，价格与库存仅供测试。']]);
                    $report['boat_type_ids'][$boat['external_id']] = $typeId;
                    $report['boat_ids'][$boat['external_id']] = $boatId;
                }
                foreach ($data['posts'] as $post) {
                    $report['post_ids'][$post['external_id']] = $save('posts', 'public_maritime', $post['external_id'], [
                        'title' => $post['title'], 'price_cents' => 0, 'payload' => ['post_type' => 'news', 'content' => $post['content'], 'image_urls' => []]]);
                }
                return $report;
            });
        } catch (Throwable $error) {
            foreach ($createdFiles as $file) if (is_file($file)) unlink($file);
            throw $error;
        }
    }

    private static function image(Database $db, $directory, array $asset, array $actor, array &$createdFiles)
    {
        $file = $db->one('SELECT * FROM uploads WHERE owner_id=? AND sha256=? AND is_private=0 ORDER BY id LIMIT 1', [(int)$actor['id'], $asset['sha256']]);
        if ($file) {
            $media = (new FileStore($db))->read($file['id'], $actor);
            if ($file['mime'] !== $asset['mime'] || !hash_equals($asset['sha256'], hash_file('sha256', $media['_file']))) {
                throw new RuntimeException('Existing public catalog image integrity failed.');
            }
            return '/api/v1/files/' . $file['id'];
        }
        $root = FileStore::root();
        if (is_link($root) || (!is_dir($root) && !mkdir($root, 0700, true))) throw new RuntimeException('Independent image storage is unavailable.');
        $name = bin2hex(random_bytes(24)) . '.' . pathinfo($asset['name'], PATHINFO_EXTENSION);
        $path = $root . '/' . $name;
        $createdFiles[] = $path;
        if (!copy($directory . '/assets/' . $asset['name'], $path) || !chmod($path, 0600) || !hash_equals($asset['sha256'], hash_file('sha256', $path))) {
            throw new RuntimeException('Catalog image could not be stored.');
        }
        $id = $db->insert('uploads', ['owner_id' => (int)$actor['id'], 'org_id' => null, 'storage_name' => $name, 'original_name' => $asset['name'],
            'mime' => $asset['mime'], 'size_bytes' => filesize($path), 'sha256' => $asset['sha256'], 'is_private' => 0, 'created_at' => time()]);
        return '/api/v1/files/' . $id;
    }
}

if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === __FILE__) {
    try {
        TestCatalogImport::guard();
        $options = getopt('', ['data:', 'actor:']);
        $result = TestCatalogImport::run(Database::connect(), $options['data'] ?? dirname(__DIR__) . '/data/test-catalog', $options['actor'] ?? 'platform.demo');
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    } catch (Throwable $error) {
        fwrite(STDERR, 'Test catalog import stopped: ' . ($error instanceof RuntimeException && !($error instanceof PDOException) ? $error->getMessage() : 'catalog/database/storage operation failed') . PHP_EOL);
        exit(1);
    }
}
