<?php
require_once dirname(__DIR__) . '/extend/yuzhou/bootstrap.php';

use yuzhou\Database;
use yuzhou\Policy;
use yuzhou\Rbac;

// Changes only the exact original seed/import display copy; edited and user-created records stay intact.
class CatalogCopyRefresh
{
    public static function guard()
    {
        if (PHP_SAPI !== 'cli' || !in_array(getenv('APP_ENV'), ['development', 'test'], true)) {
            throw new RuntimeException('Catalog copy refresh is restricted to CLI development/test.');
        }
        $environmentFile = dirname(__DIR__) . '/.env';
        if (is_file($environmentFile)) foreach (file($environmentFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (!preg_match('/^APP_ENV=(.*)$/D', trim($line), $match)) continue;
            if (!in_array(trim($match[1], " \t\r\n\"'"), ['development', 'test'], true)) {
                throw new RuntimeException('The configured installation does not permit catalog copy refresh.');
            }
            break;
        }
    }

    public static function run(Database $db, $apply = false, $username = 'admin')
    {
        self::guard();
        return $db->transaction(function () use ($db, $apply, $username) {
            $actor = $db->one('SELECT * FROM users WHERE id=1 AND username=?' . $db->lock(), [$username]);
            if (!$actor || $actor['role'] !== 'platform' || $actor['status'] !== 'active' || $actor['org_id'] !== null
                || array_diff(['catalog', 'community', 'users', 'organizations'], (new Rbac($db))->effective($actor))) {
                throw new RuntimeException('The original active platform administrator is required.');
            }
            $changes = [];
            $patch = function ($table, $key, $id, array $before, array $after, $auditId = null) use ($db, $apply, $actor, &$changes) {
                if (!$after) return;
                $changes[] = ['table' => $table, 'key' => $id, 'before' => $before, 'after' => $after];
                if (!$apply) return;
                $sets = []; $params = [];
                foreach ($after as $field => $value) { $sets[] = $field . '=?'; $params[] = $value; }
                $params[] = $id;
                $db->run('UPDATE ' . $table . ' SET ' . implode(',', $sets) . ' WHERE ' . $key . '=?', $params);
                Policy::audit($db, $actor, 'catalog.display_copy', $table, $auditId === null ? $id : $auditId, ['fields' => array_keys($after)]);
            };
            foreach ([
                [1, 'platform', null, '平台管理员'], [2, 'consumer', null, '航海爱好者'],
                [3, 'broker', 2, '经纪人'], [4, 'merchant', 1, '商家'], [5, 'agency', 2, '经纪公司管理员']
            ] as $target) {
                list($id, $role, $org, $nickname) = $target;
                $row = $db->one('SELECT id,role,org_id,nickname FROM users WHERE id=?' . $db->lock(), [$id]);
                if ($row && $row['role'] === $role && ($row['org_id'] === null ? null : (int)$row['org_id']) === $org
                    && $row['nickname'] === '演示 ' . $role) $patch('users', 'id', $id, ['nickname' => $row['nickname']], ['nickname' => $nickname]);
            }
            foreach ([[1, 'merchant', '演示船艇商家（测试）', '驭舟船艇商家'], [2, 'agency', '演示经纪公司（测试）', '驭舟经纪公司']] as $target) {
                list($id, $kind, $old, $new) = $target;
                $row = $db->one('SELECT id,kind,name FROM organizations WHERE id=?' . $db->lock(), [$id]);
                if ($row && $row['kind'] === $kind && $row['name'] === $old) $patch('organizations', 'id', $id, ['name' => $old], ['name' => $new]);
            }
            $credit = '91370200MA3YY0001D';
            $merchant = $db->one('SELECT organization_id,legal_name FROM merchants WHERE credit_code=?' . $db->lock(), [$credit]);
            if ($merchant && (int)$merchant['organization_id'] === 1 && $merchant['legal_name'] === '演示商家（合成测试资料）') {
                $patch('merchants', 'credit_code', $credit, ['legal_name' => $merchant['legal_name']], ['legal_name' => '商家（合成测试资料）'], 1);
            }
            $customer = $db->one('SELECT id,user_id,broker_id,org_id,name,notes FROM customers WHERE id=1' . $db->lock());
            if ($customer && (int)$customer['user_id'] === 2 && (int)$customer['broker_id'] === 3 && (int)$customer['org_id'] === 2) {
                $before = []; $after = [];
                foreach (['name' => ['演示消费者', '航海爱好者'], 'notes' => ['合成CRM演示资料', '合成CRM资料']] as $field => $copy) {
                    if ($customer[$field] === $copy[0]) { $before[$field] = $copy[0]; $after[$field] = $copy[1]; }
                }
                $patch('customers', 'id', 1, $before, $after);
            }
            $targets = [
                1 => ['boat_types', 4, '驭舟 42 休闲游艇（演示）', ['description' => ['合成演示船型；实际船艇与价格由管理端录入。', '休闲游艇，支持船型参数查看、预订与咨询。']]],
                2 => ['boats', 4, '驭舟 42 · 1号（演示）', []],
                3 => ['boats', 4, '驭舟 42 · 2号（演示）', []],
                4 => ['boats', 4, '驭舟 42 · 3号（演示）', []],
                5 => ['upgrades', 4, '航电与舒适升级（演示）', []],
                6 => ['maintenance', 4, '年度船艇保养（演示）', []],
                7 => ['products', 1, '航海防水包（演示）', ['description' => ['平台直营测试商品', '航海出行用品']]],
                8 => ['products', 1, '船用救生衣（演示）', ['description' => ['平台直营测试商品', '航海出行用品']]],
                9 => ['products', 1, '航海指南（演示）', ['description' => ['平台直营测试商品', '航海出行用品']]],
                10 => ['coupons', 1, '新客体验券（演示）', []],
                11 => ['events', 1, '周末航海体验（演示）', ['description' => ['免费合成活动，用于测试报名与电子票核销。', '活动详情与集合地点以报名确认为准。']]],
                12 => ['courses', 1, '航海安全入门（演示）', ['description' => ['免费课程；请管理端上传实际教学视频。', '航海安全基础知识，出航前了解救生与天气条件。']]],
                13 => ['posts', 2, '欢迎来到驭舟（演示）', ['content' => ['这里是独立测试环境，船艇、商品和活动均为合成演示资料。真实交易服务尚未配置。', '欢迎来到驭舟航海社区！在这里分享航海见闻、交流船艇使用经验，也可以发布你的出海故事。一起发现更广阔的水上生活。']]]
            ];
            $resources = $db->all('SELECT * FROM resources WHERE id<=13 OR (kind=? AND owner_id=1)' . $db->lock(), ['boats']);
            foreach ($resources as $row) {
                $id = (int)$row['id']; $payload = json_decode($row['payload'], true);
                if (!is_array($payload)) throw new RuntimeException('Resource metadata must be valid JSON before refreshing copy.');
                $copies = []; $newTitle = null; $locationTable = null;
                if (isset($targets[$id])) {
                    list($kind, $owner, $oldTitle, $copies) = $targets[$id];
                    $merchantOwned = $owner === 4;
                    if ($row['kind'] !== $kind || (int)$row['owner_id'] !== $owner || $row['title'] !== $oldTitle
                        || ($merchantOwned ? ((int)$row['org_id'] !== 1 || $row['merchant_credit_code'] !== $credit) : ($row['org_id'] !== null || $row['merchant_credit_code'] !== null))) continue;
                    // A changed welcome message is user content; do not replace it even if its old title remains.
                    if ($id === 13 && ($payload['content'] ?? null) !== $copies['content'][0]) continue;
                    $newTitle = str_replace('（演示）', '', $oldTitle);
                    if ($kind === 'boats' || $kind === 'events') $locationTable = $kind;
                } elseif ($row['kind'] === 'boats' && $row['org_id'] === null && $row['merchant_credit_code'] === null
                    && ($payload['provider'] ?? null) === 'ourideas_3d' && ($payload['test_business'] ?? null) === true
                    && preg_match('/^(?:[1-9]|1[0-9]|2138)$/D', (string)($payload['external_id'] ?? ''))) {
                    $boat = $db->one('SELECT * FROM boats WHERE resource_id=?' . $db->lock(), [$id]);
                    $type = $boat ? $db->one('SELECT * FROM resources WHERE id=?' . $db->lock(), [(int)$boat['boat_type_id']]) : null;
                    $typePayload = $type ? json_decode($type['payload'], true) : [];
                    if (!$boat || !$type || $type['kind'] !== 'boat_types' || (int)$type['owner_id'] !== 1 || $type['org_id'] !== null
                        || $type['merchant_credit_code'] !== null || ($typePayload['provider'] ?? null) !== 'ourideas_3d'
                        || ($typePayload['external_id'] ?? null) !== $payload['external_id'] || ($typePayload['test_business'] ?? null) !== true
                        || $boat['serial_number'] !== 'YZ-TEST-3D-' . $payload['external_id'] || $row['title'] !== $type['title'] . ' · 演示1号') continue;
                    $newTitle = $type['title'] . ' · 1号'; $locationTable = 'boats';
                    $copies = ['description' => ['用于测试预订和履约的演示实体，价格与库存仅供测试。', '船艇详情、交付地点与可用时间以预订确认为准。']];
                }
                if ($newTitle === null) continue;
                $before = ['title' => $row['title']]; $after = ['title' => $newTitle];
                foreach ($copies as $field => $copy) if (($payload[$field] ?? null) === $copy[0]) $payload[$field] = $copy[1];
                if ($payload !== json_decode($row['payload'], true)) {
                    $before['payload'] = $row['payload']; $after['payload'] = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
                if ($locationTable) {
                    $location = $db->one('SELECT location FROM ' . $locationTable . ' WHERE resource_id=?' . $db->lock(), [$id]);
                    if ($location && $location['location'] === '演示码头') {
                        $changes[] = ['table' => $locationTable, 'key' => $id, 'before' => ['location' => '演示码头'], 'after' => ['location' => '码头待确认']];
                        if ($apply) $db->run('UPDATE ' . $locationTable . ' SET location=? WHERE resource_id=?', ['码头待确认', $id]);
                    }
                }
                $after['version'] = (int)$row['version'] + 1; $after['updated_at'] = time();
                $patch('resources', 'id', $id, $before, $after);
            }
            return ['applied' => $apply, 'changed_rows' => count($changes), 'changes' => $changes];
        });
    }
}

if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === __FILE__) {
    try {
        CatalogCopyRefresh::guard();
        $options = getopt('', ['apply', 'actor:']);
        echo json_encode(CatalogCopyRefresh::run(Database::connect(), array_key_exists('apply', $options), $options['actor'] ?? 'admin'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    } catch (Throwable $error) {
        fwrite(STDERR, 'Catalog copy refresh stopped: ' . ($error instanceof RuntimeException && !($error instanceof PDOException) ? $error->getMessage() : 'catalog/database operation failed') . PHP_EOL);
        exit(1);
    }
}
