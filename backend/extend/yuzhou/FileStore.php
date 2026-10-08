<?php
namespace yuzhou;

class FileStore
{
    private $db;
    public function __construct(Database $db) { $this->db = $db; }
    public static function root()
    {
        return getenv('STORAGE_PATH') ?: dirname(dirname(__DIR__)) . '/storage/files';
    }

    public function upload(array $file, array $body, array $user)
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) throw new ApiError('A valid uploaded file is required.');
        $size = filesize($file['tmp_name']);
        if ($size < 1 || $size > 50 * 1024 * 1024) throw new ApiError('File size must be between 1 byte and 50 MB.');
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        $types = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif', 'video/mp4' => 'mp4'];
        if (!isset($types[$mime])) throw new ApiError('Only PDF, JPEG, PNG, WebP, GIF and MP4 files are accepted.');
        $private = !isset($body['private']) || in_array($body['private'], [true, 1, '1'], true);
        if ($mime === 'application/pdf') $private = true;
        $root = self::root();
        if (!is_dir($root) && !mkdir($root, 0700, true)) throw new ApiError('Private storage is unavailable.', 503, 50320);
        $name = bin2hex(random_bytes(24)) . '.' . $types[$mime];
        $hash = hash_file('sha256', $file['tmp_name']);
        if (!move_uploaded_file($file['tmp_name'], $root . '/' . $name)) throw new ApiError('File could not be stored.', 503, 50320);
        chmod($root . '/' . $name, 0600);
        try {
            $id = $this->db->insert('uploads', ['owner_id' => (int)$user['id'], 'org_id' => $user['org_id'] ?: null, 'storage_name' => $name,
                'original_name' => mb_substr(basename($file['name']), 0, 200, 'UTF-8'), 'mime' => $mime, 'size_bytes' => $size, 'sha256' => $hash, 'is_private' => $private ? 1 : 0, 'created_at' => time()]);
        } catch (\Throwable $error) { unlink($root . '/' . $name); throw $error; }
        return ['id' => $id, 'url' => '/api/v1/files/' . $id, 'mime' => $mime, 'size_bytes' => $size, 'sha256' => $hash, 'private' => $private];
    }

    public function read($id, $user)
    {
        $file = $this->db->one('SELECT * FROM uploads WHERE id=?', [Validation::id($id)]);
        if (!$file) throw new ApiError('File not found.', 404, 404);
        $allowed = $user && (int)$user['id'] === (int)$file['owner_id'];
        $management = $user && in_array($user['role'], ['platform', 'merchant', 'agency'], true);
        $permissions = [];
        if (!$allowed && $management) {
            // Re-read assigned roles: request-provided permissions may be stale or forged.
            $permissions = (new Rbac($this->db))->effective($user);
            if ($user['role'] === 'platform' && !array_diff(Rbac::$keys, $permissions)) $allowed = true;
        }
        if (!$allowed && $file['is_private'] && $user && $user['role'] === 'platform') {
            if (in_array('applications', $permissions, true) && $this->db->one('SELECT application_id FROM application_files WHERE file_id=?', [(int)$file['id']])) $allowed = true;
            if (!$allowed && in_array('organizations', $permissions, true) && $this->db->one('SELECT organization_id FROM merchants WHERE license_file_id=?', [(int)$file['id']])) $allowed = true;
        }
        if (!$allowed && $file['is_private'] && $user && (!$management || in_array('orders', $permissions, true))) {
            foreach ($this->db->all('SELECT o.* FROM orders o JOIN contracts c ON c.order_id=o.id WHERE c.file_id=?', [(int)$file['id']]) as $order) {
                if (Policy::orderVisible($user, $order)) { $allowed = true; break; }
            }
        }
        if (!$file['is_private'] && !$allowed) {
            $needle = '/api/v1/files/' . $file['id'];
            foreach ($this->db->all('SELECT payload FROM resources WHERE status=? AND payload LIKE ?', ['published', '%' . $needle . '%']) as $resource) {
                $payload = json_decode($resource['payload'], true) ?: [];
                if (in_array($needle, $payload['image_urls'] ?? [], true) || ($payload['video_url'] ?? '') === $needle) { $allowed = true; break; }
            }
            if (!$allowed && $this->db->one('SELECT l.id FROM course_lessons l JOIN resources r ON r.id=l.course_id WHERE l.video_url=? AND r.status=?', [$needle, 'published'])) $allowed = true;
        }
        if (!$allowed) throw new ApiError('File access is not permitted.', 403, 40320);
        $path = self::root() . '/' . $file['storage_name'];
        if (!is_file($path) || is_link($path)) throw new ApiError('File is unavailable.', 404, 404);
        return ['_file' => $path, 'mime' => $file['mime'], 'filename' => $file['original_name'], 'private' => (bool)$file['is_private']];
    }
}
