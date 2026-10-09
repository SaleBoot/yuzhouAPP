<?php
namespace yuzhou;

// Independent Yuzhou SendSms adapter; no credentials or dependencies from another site.
class AliSms
{
    private $transport;

    public function __construct(callable $transport = null)
    {
        if ($transport && getenv('APP_ENV') !== 'test') throw new \LogicException('SMS mock transport is restricted to isolated tests.');
        $this->transport = $transport;
    }

    public static function configured()
    {
        foreach (['ALI_SMS_ACCESS_KEY_ID', 'ALI_SMS_ACCESS_KEY_SECRET', 'ALI_SMS_SIGN_NAME', 'ALI_SMS_TEMPLATE_CODE'] as $key) {
            $value = getenv($key);
            if (!$value || strlen($value) > 256 || preg_match('/[\r\n\x00]/', $value)) return false;
        }
        $hashKey = getenv('ALI_SMS_HMAC_KEY');
        $parameter = getenv('ALI_SMS_CODE_PARAM') ?: 'code';
        $securityToken = getenv('ALI_SMS_SECURITY_TOKEN') ?: '';
        return getenv('ALI_SMS_ENABLED') === '1' && is_string($hashKey) && strlen($hashKey) >= 32 && strlen($hashKey) <= 256
            && !preg_match('/[\r\n\x00]/', $hashKey)
            && preg_match('/^[A-Za-z][A-Za-z0-9_]{0,30}$/D', $parameter) && !preg_match('/[\r\n\x00]/', $securityToken)
            && function_exists('curl_init') && function_exists('hash_hmac');
    }

    public function requireConfiguration()
    {
        if (!self::configured()) throw new ApiError('Real SMS service is not configured.', 503, 50311);
    }

    // ACS3 fixed POST / RPC signing. Official fixed vector is checked offline in tests/backend/sms.php.
    public static function sign(array $headers, array $query, $body, $accessKey, $secret)
    {
        $normalized = [];
        foreach ($headers as $key => $value) {
            $key = strtolower($key);
            if ($key === 'host' || $key === 'content-type' || strpos($key, 'x-acs-') === 0) $normalized[$key] = trim($value);
        }
        $normalized['x-acs-content-sha256'] = hash('sha256', $body);
        ksort($normalized, SORT_STRING); ksort($query, SORT_STRING);
        $parts = []; foreach ($query as $key => $value) $parts[] = rawurlencode($key) . '=' . rawurlencode((string)$value);
        $encoded = implode('&', $parts); $canonicalHeaders = '';
        foreach ($normalized as $key => $value) $canonicalHeaders .= $key . ':' . $value . "\n";
        $signedHeaders = implode(';', array_keys($normalized));
        $digest = hash('sha256', "POST\n/\n" . $encoded . "\n" . $canonicalHeaders . "\n" . $signedHeaders . "\n" . hash('sha256', $body));
        $signature = hash_hmac('sha256', "ACS3-HMAC-SHA256\n" . $digest, $secret);
        $normalized['authorization'] = 'ACS3-HMAC-SHA256 Credential=' . $accessKey . ',SignedHeaders=' . $signedHeaders . ',Signature=' . $signature;
        return ['headers' => $normalized, 'query' => $encoded, 'canonical_hash' => $digest, 'signature' => $signature];
    }

    public function send($phone, $code)
    {
        $this->requireConfiguration();
        $headers = ['host' => 'dysmsapi.aliyuncs.com', 'content-type' => 'application/x-www-form-urlencoded',
            'x-acs-action' => 'SendSms', 'x-acs-version' => '2017-05-25', 'x-acs-date' => gmdate('Y-m-d\TH:i:s\Z'),
            'x-acs-signature-nonce' => bin2hex(random_bytes(16))];
        if (getenv('ALI_SMS_SECURITY_TOKEN')) $headers['x-acs-security-token'] = getenv('ALI_SMS_SECURITY_TOKEN');
        $request = self::sign($headers, ['PhoneNumbers' => substr($phone, 3), 'SignName' => getenv('ALI_SMS_SIGN_NAME'),
            'TemplateCode' => getenv('ALI_SMS_TEMPLATE_CODE'), 'TemplateParam' => json_encode([(getenv('ALI_SMS_CODE_PARAM') ?: 'code') => $code])],
            '', getenv('ALI_SMS_ACCESS_KEY_ID'), getenv('ALI_SMS_ACCESS_KEY_SECRET'));
        // Do not log this URL, headers, body, response body, or cURL diagnostics: they may contain private data.
        $request['url'] = 'https://dysmsapi.aliyuncs.com/?' . $request['query'];
        $request['body'] = '';
        try { $response = $this->transport ? call_user_func($this->transport, $request) : $this->http($request); }
        catch (\Throwable $error) { return ['status' => 'unknown']; }
        if (!is_array($response) || !empty($response['error']) || !is_string($response['body'] ?? null) || strlen($response['body']) > 65536) return ['status' => 'unknown'];
        $data = json_decode($response['body'], true); $status = (int)($response['status'] ?? 0);
        if (!is_array($data) || !is_string($data['Code'] ?? null)) return ['status' => 'unknown'];
        if ($data['Code'] !== 'OK') return ['status' => 'failed'];
        if ($status < 200 || $status >= 300 || !self::identifier($data['RequestId'] ?? null) || !self::identifier($data['BizId'] ?? null)) return ['status' => 'unknown'];
        return ['status' => 'accepted', 'request_id' => $data['RequestId'], 'biz_id' => $data['BizId']];
    }

    private static function identifier($value) { return is_string($value) && strlen($value) > 0 && strlen($value) <= 128 && !preg_match('/[\x00-\x1f\x7f]/', $value); }

    private function http(array $request)
    {
        $curl = curl_init($request['url']); $body = ''; $headers = [];
        foreach ($request['headers'] as $key => $value) $headers[] = $key . ': ' . $value;
        curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => '', CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 10, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_WRITEFUNCTION => function ($handle, $chunk) use (&$body) {
                if (strlen($body) + strlen($chunk) > 65536) return 0;
                $body .= $chunk; return strlen($chunk);
            }]);
        $ok = curl_exec($curl); $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE); $error = curl_errno($curl) !== 0 || $ok === false;
        curl_close($curl);
        return ['status' => $status, 'body' => $body, 'error' => $error];
    }
}
