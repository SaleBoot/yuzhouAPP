<?php
require_once __DIR__ . '/../../backend/extend/yuzhou/bootstrap.php';

use yuzhou\ApiError;
use yuzhou\Catalog;
use yuzhou\Database;
use yuzhou\FileStore;

$checks = 0;
function mediaCheck($condition, $label) { global $checks; if (!$condition) throw new RuntimeException($label); $checks++; }
function mediaDenied($operation, $status, $label) {
    try { $operation(); } catch (ApiError $error) { mediaCheck($error->status === $status, $label); return; }
    throw new RuntimeException($label . ' was allowed');
}
putenv('APP_ENV=test');
$previous = getenv('STORAGE_PATH');
$storage = sys_get_temp_dir() . '/yuzhou-media-' . bin2hex(random_bytes(8));
mkdir($storage, 0700); putenv('STORAGE_PATH=' . $storage);
try {
    $db = new Database(new PDO('sqlite::memory:'));
    foreach (['sqlite.sql', 'communication-sqlite.sql'] as $file) $db->pdo->exec(file_get_contents(__DIR__ . '/../../database/' . $file));
    foreach (glob(__DIR__ . '/../../database/migrations/*-sqlite.sql') as $file) $db->pdo->exec(file_get_contents($file));
    $users = [];
    foreach (['owner', 'other', 'platform'] as $name) {
        $id = $db->insert('users', ['username' => 'synthetic_media_' . $name, 'password_hash' => 'unused', 'nickname' => $name,
            'role' => $name === 'platform' ? 'platform' : 'consumer', 'created_at' => time(), 'updated_at' => time()]);
        $users[$name] = $db->one('SELECT * FROM users WHERE id=?', [$id]);
    }
    $files = [];
    foreach (['video' => ['video/mp4', 0], 'image' => ['image/png', 0], 'private' => ['video/mp4', 1], 'unpublished' => ['video/mp4', 0]] as $name => $type) {
        file_put_contents($storage . '/' . $name, 'Synthetic media boundary fixture: ' . $name);
        $files[$name] = $db->insert('uploads', ['owner_id' => $users['owner']['id'], 'storage_name' => $name, 'original_name' => $name,
            'mime' => $type[0], 'is_private' => $type[1], 'size_bytes' => filesize($storage . '/' . $name), 'sha256' => hash_file('sha256', $storage . '/' . $name), 'created_at' => time()]);
    }
    $catalog = new Catalog($db); $store = new FileStore($db);
    $video = '/api/v1/files/' . $files['video']; $image = '/api/v1/files/' . $files['image'];
    $post = function ($payload, $actor = 'owner') use ($catalog, $users) { return $catalog->save('posts', ['title' => 'Synthetic media post', 'payload' => $payload], $users[$actor]); };
    mediaDenied(function () use ($store, $files) { $store->read($files['video'], null); }, 403, 'Unpublished public upload is not anonymously readable');
    $pending = $post(['video_url' => $video, 'image_urls' => [$image]]);
    mediaCheck($pending['status'] === 'pending' && $pending['payload']['video_url'] === $video, 'User video submission keeps canonical path and publication review');
    mediaDenied(function () use ($store, $files) { $store->read($files['video'], null); }, 403, 'Pending post does not publish its media');
    mediaDenied(function () use ($post, $files) { $post(['video_url' => '/api/v1/files/' . $files['unpublished']], 'other'); }, 403, 'Another user cannot publish an inaccessible public upload');
    $before = (int)$db->scalar('SELECT COUNT(*) FROM resources');
    foreach (['/api/v1/files/0', $video . '?x=1', $video . '#x', $video . '/extra', '/api/v1/files/../1', 'javascript:alert(1)'] as $invalid) {
        mediaDenied(function () use ($post, $invalid) { $post(['video_url' => $invalid]); }, 422, 'Noncanonical local video reference is rejected');
    }
    mediaDenied(function () use ($post, $files) { $post(['video_url' => '/api/v1/files/' . $files['private']]); }, 422, 'Owner cannot publish a private video through a resource');
    mediaDenied(function () use ($post, $image) { $post(['video_url' => $image]); }, 422, 'Image cannot be attached as a video');
    mediaDenied(function () use ($post, $video) { $post(['image_urls' => [$video]]); }, 422, 'Video cannot be attached as an image');
    mediaDenied(function () use ($post) { $post(['image_urls' => 'invalid']); }, 422, 'Malformed image collection is rejected');
    mediaDenied(function () use ($post) { $post(['video_url' => ['invalid']]); }, 422, 'Malformed video URL is rejected');
    mediaCheck((int)$db->scalar('SELECT COUNT(*) FROM resources') === $before, 'Failed media validation creates no partial resource');
    $published = $catalog->save('posts', ['version' => $pending['version'], 'status' => 'published'], $users['platform'], $pending['id']);
    mediaCheck($store->read($files['video'], null)['_file'] === $storage . '/video', 'Approved canonical post video is anonymously readable');
    mediaCheck($store->read($files['image'], null)['_file'] === $storage . '/image', 'Approved canonical post image is anonymously readable');
    mediaCheck($post(['video_url' => $video], 'other')['status'] === 'pending', 'Already public media may be referenced without exposing unpublished uploads');
    $catalog->save('posts', ['version' => $published['version'], 'status' => 'archived'], $users['platform'], $published['id']);
    mediaDenied(function () use ($store, $files) { $store->read($files['video'], null); }, 403, 'Archiving all published references removes anonymous video access');
    $course = $catalog->save('courses', ['title' => 'Synthetic media course', 'payload' => ['lessons' => [['id' => 'one', 'title' => 'One', 'duration_seconds' => 1, 'video_url' => $video]]]], $users['platform']);
    mediaCheck($store->read($files['video'], null)['_file'] === $storage . '/video', 'Published course canonical lesson grants anonymous video access');
    mediaDenied(function () use ($catalog, $users, $files) { $catalog->save('courses', ['title' => 'Private course rejection', 'payload' => ['lessons' => [['id' => 'one', 'title' => 'One', 'duration_seconds' => 1, 'video_url' => '/api/v1/files/' . $files['private']]]]], $users['platform']); }, 422, 'Platform cannot publish private course media');
    mediaCheck($post(['video_url' => 'https://example.invalid/video.mp4'])['payload']['video_url'] === 'https://example.invalid/video.mp4', 'Existing external HTTP video references remain accepted without network calls');
} finally {
    foreach (glob($storage . '/*') as $file) unlink($file);
    rmdir($storage); putenv($previous === false ? 'STORAGE_PATH' : 'STORAGE_PATH=' . $previous);
}
echo 'Media publication boundary checks: ' . $checks . " passed. No real video playback was tested.\n";
