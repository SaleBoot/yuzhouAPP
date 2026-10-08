<?php
require_once __DIR__ . '/../../backend/extend/yuzhou/bootstrap.php';

use yuzhou\Api;
use yuzhou\ApiError;
use yuzhou\Database;
use yuzhou\FileStore;
use yuzhou\Rbac;

$checks = 0;
function privateFileCheck($condition, $label)
{
    global $checks;
    if (!$condition) throw new RuntimeException($label);
    $checks++;
}
function privateFileDenied($callback, $label)
{
    try { $callback(); }
    catch (ApiError $error) {
        privateFileCheck($error->status === 403 && $error->getCode() === 40320, $label);
        return;
    }
    throw new RuntimeException($label . ' was allowed');
}

putenv('APP_ENV=test');
$previousStorage = getenv('STORAGE_PATH');
$storage = sys_get_temp_dir() . '/yuzhou-private-files-' . bin2hex(random_bytes(8));
mkdir($storage, 0700);
putenv('STORAGE_PATH=' . $storage);
$paths = [];
try {
    $db = new Database(new PDO('sqlite::memory:'));
    $db->pdo->exec(file_get_contents(__DIR__ . '/../../database/sqlite.sql'));
    $db->pdo->exec(file_get_contents(__DIR__ . '/../../database/communication-sqlite.sql'));
    foreach (glob(__DIR__ . '/../../database/migrations/*-sqlite.sql') as $migration) $db->pdo->exec(file_get_contents($migration));
    $now = time();
    $merchantOrg = $db->insert('organizations', ['name' => 'Synthetic merchant', 'kind' => 'merchant', 'status' => 'approved', 'payload' => '{}', 'created_at' => $now]);
    $agencyOrg = $db->insert('organizations', ['name' => 'Synthetic agency', 'kind' => 'agency', 'status' => 'approved', 'payload' => '{}', 'created_at' => $now]);
    $users = [];
    foreach (['full', 'orders', 'applications', 'organizations', 'inactive', 'wrongbase', 'consumer', 'broker', 'merchant', 'agency'] as $name) {
        $role = in_array($name, ['consumer', 'broker', 'merchant', 'agency'], true) ? $name : 'platform';
        $org = $role === 'merchant' ? $merchantOrg : (in_array($role, ['broker', 'agency'], true) ? $agencyOrg : null);
        $id = $db->insert('users', ['username' => 'private_files_' . $name, 'password_hash' => 'unused', 'nickname' => $name, 'role' => $role,
            'org_id' => $org, 'created_at' => $now, 'updated_at' => $now]);
        $users[$name] = $db->one('SELECT * FROM users WHERE id=?', [$id]);
        $db->insert('tokens', ['user_id' => $id, 'token_hash' => hash('sha256', 'private-files-token-' . $name), 'created_at' => $now, 'expires_at' => $now + 3600]);
    }
    $roleIds = [];
    foreach (['orders' => ['orders'], 'applications' => ['applications'], 'organizations' => ['organizations'], 'agency' => ['finance'], 'inactive' => Rbac::$keys, 'wrongbase' => Rbac::$keys] as $name => $permissions) {
        $base = in_array($name, ['agency', 'wrongbase'], true) ? 'agency' : 'platform';
        $id = $db->insert('management_roles', ['name' => 'Synthetic ' . $name, 'base_role' => $base, 'org_id' => $base === 'agency' ? $agencyOrg : null,
            'status' => 'active', 'created_by' => $users['full']['id'], 'created_at' => $now, 'updated_at' => $now]);
        foreach ($permissions as $key) $db->insert('management_role_permissions', ['role_id' => $id, 'permission_key' => $key]);
        $db->insert('management_user_roles', ['user_id' => $users[$name]['id'], 'role_id' => $id]);
        $roleIds[$name] = $id;
    }
    $files = [];
    foreach (['identity' => 'consumer', 'license' => 'merchant', 'contract' => 'broker', 'orphan' => 'consumer', 'public' => 'merchant'] as $name => $owner) {
        $contents = $name === 'public' ? 'Synthetic published image' : '%PDF-1.4 Synthetic ' . $name;
        $path = $storage . '/' . $name;
        file_put_contents($path, $contents);
        chmod($path, 0600);
        $paths[] = $path;
        $files[$name] = $db->insert('uploads', ['owner_id' => $users[$owner]['id'], 'org_id' => $users[$owner]['org_id'], 'storage_name' => $name,
            'original_name' => 'Synthetic ' . $name, 'mime' => $name === 'public' ? 'image/png' : 'application/pdf', 'size_bytes' => strlen($contents),
            'sha256' => hash('sha256', $contents), 'is_private' => $name === 'public' ? 0 : 1, 'created_at' => $now]);
    }
    $application = $db->insert('applications', ['user_id' => $users['consumer']['id'], 'kind' => 'identity', 'status' => 'pending', 'payload' => '{}',
        'file_ids' => '[]', 'review_notes' => '', 'created_at' => $now, 'updated_at' => $now]);
    $db->insert('application_files', ['application_id' => $application, 'file_id' => $files['identity']]);
    $db->insert('merchants', ['credit_code' => '91370200MA3YY0001D', 'organization_id' => $merchantOrg, 'legal_name' => 'Synthetic license owner',
        'license_file_id' => $files['license'], 'status' => 'approved', 'created_at' => $now]);
    $resource = $db->insert('resources', ['kind' => 'maintenance', 'org_id' => $agencyOrg, 'owner_id' => $users['broker']['id'], 'title' => 'Synthetic service',
        'status' => 'published', 'payload' => '{}', 'created_at' => $now, 'updated_at' => $now]);
    $order = $db->insert('orders', ['order_no' => 'SYNTHETIC-PRIVATE-FILES', 'type' => 'maintenance', 'customer_id' => $users['consumer']['id'],
        'creator_id' => $users['broker']['id'], 'broker_id' => $users['broker']['id'], 'org_id' => $agencyOrg, 'resource_id' => $resource,
        'status' => 'awaiting_payment', 'amount_cents' => 100, 'original_amount_cents' => 100, 'items' => '[]', 'notes' => '', 'created_at' => $now, 'updated_at' => $now]);
    $db->insert('contracts', ['order_id' => $order, 'version' => 1, 'file_id' => $files['contract'], 'title' => 'Synthetic contract',
        'file_sha256' => hash_file('sha256', $storage . '/contract'), 'creator_id' => $users['broker']['id'], 'review_notes' => '', 'created_at' => $now]);
    $published = $db->insert('resources', ['kind' => 'banners', 'owner_id' => $users['full']['id'], 'title' => 'Synthetic media', 'status' => 'pending',
        'payload' => json_encode(['image_urls' => ['/api/v1/files/' . $files['public']]], JSON_UNESCAPED_SLASHES), 'created_at' => $now, 'updated_at' => $now]);
    $api = new Api($db);
    $read = function ($file, $user = null) use ($api, $files) {
        return $api->dispatch('GET', 'files/' . $files[$file], [], [], $user ? 'private-files-token-' . $user : null);
    };
    $permit = function ($file, $user, $label) use ($read, $storage) {
        privateFileCheck($read($file, $user)['_file'] === $storage . '/' . $file, $label);
    };

    $permit('identity', 'consumer', 'Owner retains access to private identity material');
    $permit('orphan', 'full', 'Full effective platform permissions can read unassociated private files');
    privateFileDenied(function () use ($read) { $read('identity', 'orders'); }, 'Orders-only platform cannot read identity material');
    privateFileDenied(function () use ($read) { $read('license', 'orders'); }, 'Orders-only platform cannot read business license');
    $permit('contract', 'orders', 'Orders-only platform reads an actual linked order contract');
    privateFileDenied(function () use ($read) { $read('orphan', 'orders'); }, 'Orders permission does not grant unassociated private files');
    $permit('identity', 'applications', 'Applications-only platform reads a linked application file');
    privateFileDenied(function () use ($read) { $read('contract', 'applications'); }, 'Application permission does not bypass contract order permission');
    privateFileDenied(function () use ($read) { $read('license', 'applications'); }, 'Application permission cannot read a license outside applications');
    $permit('license', 'organizations', 'Organizations-only platform reads the actual linked merchant license');
    privateFileDenied(function () use ($read) { $read('identity'); }, 'Optional authentication does not expose private files to anonymous users');
    privateFileDenied(function () use ($read) { $read('public'); }, 'Unpublished non-private media remains unavailable anonymously');
    $db->update('resources', $published, ['status' => 'published']);
    $permit('public', null, 'Published public media remains anonymously accessible');
    privateFileDenied(function () use ($read) { $read('contract', 'agency'); }, 'Restricted agency needs orders even when its organization can see the order');
    privateFileDenied(function () use ($read) { $read('contract', 'merchant'); }, 'Merchant cannot read a broker organization contract');
    $permit('contract', 'consumer', 'Authorized customer retains linked contract access');

    $store = new FileStore($db);
    $stale = $users['inactive'] + ['permissions' => Rbac::$keys];
    $db->update('management_roles', $roleIds['inactive'], ['status' => 'inactive']);
    privateFileDenied(function () use ($store, $files, $stale) { $store->read($files['identity'], $stale); }, 'Inactive current DB role rejects stale full permissions');
    $forged = $users['wrongbase'] + ['permissions' => Rbac::$keys];
    privateFileDenied(function () use ($store, $files, $forged) { $store->read($files['identity'], $forged); }, 'Wrong base role or organization cannot delegate private file access');
} finally {
    foreach ($paths as $path) if (is_file($path)) unlink($path);
    rmdir($storage);
    putenv($previousStorage === false ? 'STORAGE_PATH' : 'STORAGE_PATH=' . $previousStorage);
}
echo 'Private file permission checks: ' . $checks . ' passed.' . PHP_EOL;
