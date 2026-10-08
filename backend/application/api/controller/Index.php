<?php
namespace app\api\controller;

class Index
{
    public function handle()
    {
        $headers = ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'no-store'];
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $allowed = array_filter(array_map('trim', explode(',', getenv('CORS_ORIGINS') ?: (getenv('APP_URL') ?: ''))));
        if ($origin && in_array($origin, $allowed, true)) {
            $headers += ['Access-Control-Allow-Origin' => $origin, 'Vary' => 'Origin', 'Access-Control-Allow-Headers' => 'Authorization,Content-Type', 'Access-Control-Allow-Methods' => 'GET,POST,PATCH,PUT,DELETE,OPTIONS'];
        }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') return \think\Response::create('', 'html', 204)->header($headers);
        try {
            if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 52 * 1024 * 1024) throw new \yuzhou\ApiError('Request is too large.', 413, 413);
            $body = $_POST;
            $type = $_SERVER['CONTENT_TYPE'] ?? '';
            if (strpos($type, 'application/json') === 0) {
                $raw = file_get_contents('php://input');
                if (strlen($raw) > 1024 * 1024) throw new \yuzhou\ApiError('JSON request is too large.', 413, 413);
                $body = self::parseJsonBody($raw, $_SERVER['REQUEST_METHOD'] ?? 'GET');
            }
            $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
            $token = preg_match('/^Bearer ([A-Za-z0-9]+)$/D', $authorization, $match) ? $match[1] : null;
            $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
            $result = (new \yuzhou\Api(\yuzhou\Database::connect()))->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $path, $body, $_GET, $token, $_FILES, $_SERVER['REMOTE_ADDR'] ?? 'local');
            if (is_array($result) && isset($result['_file'])) return $this->file($result, $headers);
            return \think\Response::create(['code' => 0, 'message' => 'ok', 'data' => $result], 'json', 200)->header($headers);
        } catch (\yuzhou\ApiError $error) {
            return \think\Response::create(['code' => $error->getCode(), 'message' => $error->getMessage(), 'data' => $error->details ?: null], 'json', $error->status)->header($headers);
        } catch (\PDOException $error) {
            $constraint = in_array((string)$error->getCode(), ['23000', '23505'], true) || strpos($error->getMessage(), 'UNIQUE constraint') !== false;
            return \think\Response::create(['code' => $constraint ? 409 : 503, 'message' => $constraint ? 'Conflicting record. Reload and try again.' : 'Database service is unavailable.', 'data' => null], 'json', $constraint ? 409 : 503)->header($headers);
        } catch (\Throwable $error) {
            error_log('Yuzhou API failure: ' . get_class($error) . ' at ' . basename($error->getFile()) . ':' . $error->getLine());
            return \think\Response::create(['code' => 500, 'message' => 'Request could not be completed.', 'data' => null], 'json', 500)->header($headers);
        }
    }

    public static function parseJsonBody($raw, $method)
    {
        if ($raw === '' && in_array($method, ['GET', 'HEAD', 'DELETE'], true)) return [];
        $body = json_decode($raw, true);
        if (!is_array($body) || json_last_error() !== JSON_ERROR_NONE) throw new \yuzhou\ApiError('Invalid JSON body.');
        return $body;
    }

    private function file(array $file, array $headers)
    {
        $size = filesize($file['_file']); $start = 0; $end = $size - 1; $status = 200;
        $range = $_SERVER['HTTP_RANGE'] ?? '';
        if ($range && preg_match('/^bytes=([0-9]+)-([0-9]*)$/D', $range, $match)) {
            $start = (int)$match[1]; $end = $match[2] === '' ? $end : min($end, (int)$match[2]);
            if ($start > $end) return \think\Response::create('', 'html', 416)->header(['Content-Range' => 'bytes */' . $size]);
            $status = 206; $headers['Content-Range'] = 'bytes ' . $start . '-' . $end . '/' . $size;
        }
        $stream = fopen($file['_file'], 'rb'); fseek($stream, $start); $data = stream_get_contents($stream, $end - $start + 1); fclose($stream);
        $headers['Content-Type'] = $file['mime']; $headers['Content-Length'] = strlen($data); $headers['Accept-Ranges'] = 'bytes';
        $headers['Content-Disposition'] = 'inline; filename*=UTF-8\'\'' . rawurlencode($file['filename']);
        $headers['Content-Security-Policy'] = "sandbox; default-src 'none'";
        $headers['Cache-Control'] = $file['private'] ? 'private, no-store' : 'public, max-age=3600';
        return \think\Response::create($data, 'html', $status)->header($headers);
    }
}
