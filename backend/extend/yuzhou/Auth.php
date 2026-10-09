<?php
namespace yuzhou;

class Auth
{
    private $db;
    private $sms;
    public function __construct(Database $db, AliSms $sms = null) { $this->db = $db; $this->sms = $sms ?: new AliSms(); }

    public function user($token, $required = true)
    {
        if (!$token) {
            if ($required) throw new ApiError('Authentication required.', 401, 401);
            return null;
        }
        $user = $this->db->one('SELECT u.*,EXISTS(SELECT 1 FROM phone_identities p WHERE p.user_id=u.id) AS phone_bound FROM users u JOIN tokens t ON t.user_id=u.id WHERE t.token_hash=? AND t.expires_at>? AND u.status=?', [hash('sha256', $token), time(), 'active']);
        if (!$user) throw new ApiError('Session has expired. Please sign in again.', 401, 401);
        if ($user['org_id']) {
            $organization = $this->db->one('SELECT id,status FROM organizations WHERE id=?', [(int)$user['org_id']]);
            if (!$organization || $organization['status'] !== 'approved') throw new ApiError('Organization access is not approved.', 403, 40301);
        }
        $user['permissions'] = (new Rbac($this->db))->effective($user);
        return $user;
    }

    public function register(array $body, $ip)
    {
        $username = strtolower(Validation::text($body['username'] ?? '', 'username', 120));
        if (!preg_match('/^[A-Za-z0-9@._+-]{3,120}$/D', $username)) throw new ApiError('Invalid account name.');
        $password = Validation::text($body['password'] ?? '', 'password', 72);
        if (strlen($password) < 8) throw new ApiError('Password must contain at least 8 characters.');
        $nickname = Validation::text($body['nickname'] ?? $username, 'nickname', 120);
        $this->rateLimit($ip, 'register');
        if ($this->db->one('SELECT id FROM users WHERE username=?', [$username])) throw new ApiError('Account already exists.', 409, 40901);
        return $this->db->transaction(function () use ($body, $username, $password, $nickname) {
            return $this->issue($this->createConsumer($body, $username, $password, $nickname));
        });
    }

    private function createConsumer(array $body, $username, $password, $nickname)
    {
        $referrer = null;
        if (!empty($body['invitation_code'])) {
            $code = strtoupper(Validation::text($body['invitation_code'], 'invitation_code', 16));
            $referrer = $this->db->one('SELECT i.user_id FROM invitations i JOIN users u ON u.id=i.user_id WHERE i.code=? AND u.status=?', [$code, 'active']);
            if (!$referrer) throw new ApiError('Invitation code is invalid.');
        }
        $now = time();
        $id = $this->db->insert('users', ['username' => $username, 'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'nickname' => $nickname,
            'role' => 'consumer', 'org_id' => null, 'status' => 'active', 'member_until' => 0, 'growth_points' => 0, 'identity_status' => 'unverified', 'created_at' => $now, 'updated_at' => $now]);
        $this->db->insert('invitations', ['user_id' => $id, 'code' => strtoupper(bin2hex(random_bytes(6))), 'referrer_id' => $referrer ? (int)$referrer['user_id'] : null, 'created_at' => $now]);
        if ($referrer) (new Community($this->db))->grow((int)$referrer['user_id'], 'invitation:' . $id, (new Finance($this->db))->growthRules()['invitation_points'], 'Invited account registered');
        return $this->db->one('SELECT * FROM users WHERE id=?', [$id]);
    }

    public function smsSend(array $body, $ip, array $user = null)
    {
        $this->sms->requireConfiguration();
        $purpose = Validation::choice($body['purpose'] ?? '', ['login', 'register', 'bind'], 'purpose');
        if ($purpose === 'bind' && !$user) throw new ApiError('Authentication required.', 401, 401);
        $phone = self::smsPhone($body['phone'] ?? null); $phoneHash = self::smsHash('phone', $phone);
        $challenge = bin2hex(random_bytes(32)); $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT); $now = time();
        $reservation = $this->db->transaction(function () use ($phoneHash, $purpose, $user, $ip, $challenge, $code, $now) {
            $error = $this->smsLimits([
                ['phone-cooldown|' . $phoneHash, 60, 1], ['phone-hour|' . $phoneHash, 3600, 5], ['phone-day|' . $phoneHash, 86400, 10],
                ['send-ip-hour|' . $ip, 3600, 20], ['send-ip-day|' . $ip, 86400, 100]
            ], $now);
            if ($error) return $error;
            // The phone cooldown lock serializes sends across purposes and invalidates every older code.
            $this->db->run('UPDATE sms_challenges SET status=? WHERE phone_hash=? AND status IN (?,?)', ['superseded', $phoneHash, 'accepted', 'pending']);
            return $this->db->insert('sms_challenges', ['challenge_id' => $challenge, 'phone_hash' => $phoneHash, 'purpose' => $purpose,
                'code_hash' => self::smsHash('code', $challenge . '|' . $purpose . '|' . $phoneHash . '|' . $code), 'status' => 'pending',
                'attempts' => 0, 'expires_at' => $now + 300, 'created_at' => $now, 'user_id' => $purpose === 'bind' ? (int)$user['id'] : null]);
        });
        if ($reservation instanceof ApiError) throw $reservation;
        // External HTTP never holds a database transaction. Unknown outcomes are not retried automatically.
        $result = $this->sms->send($phone, $code);
        $updated = $this->db->run('UPDATE sms_challenges SET status=?,provider_request_id=?,provider_biz_id=? WHERE id=? AND status=?',
            [$result['status'], $result['request_id'] ?? null, $result['biz_id'] ?? null, $reservation, 'pending']);
        if ($updated->rowCount() !== 1) throw new ApiError('SMS delivery status is unknown. Wait before requesting another code.', 503, 50313);
        if ($result['status'] !== 'accepted') throw new ApiError($result['status'] === 'unknown'
            ? 'SMS delivery status is unknown. Wait before requesting another code.' : 'SMS provider rejected the request.', 503, $result['status'] === 'unknown' ? 50313 : 50312);
        return ['challenge_id' => $challenge, 'purpose' => $purpose, 'expires_in' => max(0, $now + 300 - time()), 'resend_after' => max(0, $now + 60 - time()), 'delivery_status' => 'accepted'];
    }

    public function smsVerify(array $body, $ip, array $actor = null)
    {
        $this->sms->requireConfiguration();
        $purpose = Validation::choice($body['purpose'] ?? '', ['login', 'register', 'bind'], 'purpose');
        if ($purpose === 'bind' && !$actor) throw new ApiError('Authentication required.', 401, 401);
        $phoneHash = self::smsHash('phone', self::smsPhone($body['phone'] ?? null));
        $challenge = $body['challenge_id'] ?? null; $code = $body['code'] ?? null;
        if (!is_string($challenge) || !preg_match('/^[a-f0-9]{64}$/D', $challenge) || !is_string($code) || !preg_match('/^[0-9]{6}$/D', $code)) throw new ApiError('Invalid SMS challenge or code.');
        // SMS proof never authorizes choosing another account, role, organization, or password.
        foreach (['username', 'user_id', 'role', 'org_id', 'password'] as $field) if (array_key_exists($field, $body)) throw new ApiError('SMS authentication does not accept account assignment.');
        $nickname = Validation::text($body['nickname'] ?? '驭舟用户', 'nickname', 120);
        $result = $this->smsVerificationTransaction(function () use ($body, $ip, $purpose, $phoneHash, $challenge, $code, $nickname, $actor) {
            $now = time(); $error = $this->smsLimits([['verify-ip|' . $ip, 900, 30]], $now);
            if ($error) return $error;
            $row = $this->db->one('SELECT * FROM sms_challenges WHERE challenge_id=?' . $this->db->lock(), [$challenge]);
            $invalid = new ApiError('SMS code is invalid or expired.', 401, 40103);
            if (!$row || $row['status'] !== 'accepted' || (int)$row['expires_at'] <= $now || (int)$row['attempts'] >= 5) return $invalid;
            if ($row['purpose'] === 'bind' && (!$actor || (int)$row['user_id'] !== (int)$actor['id'])) return new ApiError('SMS binding belongs to another session.', 403, 40303);
            if ($row['purpose'] !== $purpose || !hash_equals($row['phone_hash'], $phoneHash)
                || !hash_equals($row['code_hash'], self::smsHash('code', $challenge . '|' . $purpose . '|' . $phoneHash . '|' . $code))) {
                $this->db->update('sms_challenges', $row['id'], ['attempts' => (int)$row['attempts'] + 1, 'status' => (int)$row['attempts'] + 1 >= 5 ? 'locked' : 'accepted']);
                return $invalid; // Return first, commit the attempt, then throw outside the transaction.
            }
            $this->smsBucket('identity|' . $phoneHash, $now);
            // Only current locking reads precede this point. Serialize an account's first bind before opening
            // the RR snapshot, so a waiter sees its previous bind. The phone bucket serializes each phone;
            // absent phone/user identity SELECT FOR UPDATE would instead lock shared unique-index gaps.
            $user = $purpose === 'bind' ? $this->db->one('SELECT * FROM users WHERE id=?' . $this->db->lock(), [(int)$actor['id']]) : null;
            $identity = $this->db->one('SELECT * FROM phone_identities WHERE phone_hash=?', [$phoneHash]);
            $this->db->update('sms_challenges', $row['id'], ['status' => 'consumed', 'consumed_at' => $now]);
            if ($purpose === 'bind') {
                if (!$user || $user['status'] !== 'active') return new ApiError('Account is inactive.', 401, 40104);
                $own = $this->db->one('SELECT * FROM phone_identities WHERE user_id=?', [(int)$user['id']]);
                if (($identity && (int)$identity['user_id'] !== (int)$user['id']) || ($own && !hash_equals($own['phone_hash'], $phoneHash))) return new ApiError('Phone identity cannot be reassigned.', 409, 40903);
                if (!$own) $this->db->insert('phone_identities', ['user_id' => (int)$user['id'], 'phone_hash' => $phoneHash, 'verified_at' => $now]);
                return ['bound' => true];
            }
            if ($purpose === 'register') {
                if ($identity) return new ApiError('Phone identity already exists. Use SMS login.', 409, 40903);
                try { $user = $this->createConsumer($body, 'sms_' . bin2hex(random_bytes(16)), bin2hex(random_bytes(24)), $nickname); }
                catch (ApiError $error) { return $error; }
                $this->db->insert('phone_identities', ['user_id' => (int)$user['id'], 'phone_hash' => $phoneHash, 'verified_at' => $now]);
            } else {
                $user = $identity ? $this->db->one('SELECT * FROM users WHERE id=?' . $this->db->lock(), [(int)$identity['user_id']]) : null;
                if (!$user || $user['status'] !== 'active') return new ApiError('Phone login is unavailable for this account.', 401, 40104);
                if ($user['org_id']) {
                    $org = $this->db->one('SELECT status FROM organizations WHERE id=?' . $this->db->lock(), [(int)$user['org_id']]);
                    if (!$org || $org['status'] !== 'approved') return new ApiError('Organization access is not approved.', 403, 40301);
                }
            }
            $this->db->update('sms_challenges', $row['id'], ['user_id' => (int)$user['id']]);
            return $this->issue($user);
        });
        if ($result instanceof ApiError) throw $result;
        return $result;
    }

    private function smsVerificationTransaction(callable $callback)
    {
        for ($attempt = 0; ; $attempt++) {
            try { return $this->db->transaction($callback); }
            catch (\PDOException $error) {
                $number = (int)($error->errorInfo[1] ?? 0);
                // Database::transaction must have rolled back the whole attempt. Never retry a nested live
                // transaction, arbitrary errors, or external SMS sends. The callback recomputes time each try.
                if ($this->db->driver !== 'mysql' || $this->db->pdo->inTransaction() || !in_array($number, [1205, 1213], true) || $attempt >= 2) throw $error;
                usleep(20000 * ($attempt + 1));
            }
        }
    }

    public static function smsPhone($value)
    {
        if (!is_string($value)) throw new ApiError('Invalid mainland China mobile number.');
        $value = trim($value);
        if (preg_match('/^(?:\+86)?(1[3-9][0-9]{9})$/D', $value, $match)) return '+86' . $match[1];
        throw new ApiError('Invalid mainland China mobile number.');
    }

    private static function smsHash($purpose, $value) { return hash_hmac('sha256', $purpose . '|' . $value, getenv('ALI_SMS_HMAC_KEY')); }

    private function smsBucket($label, $now)
    {
        $key = self::smsHash('rate', $label);
        $sql = $this->db->driver === 'mysql'
            ? 'INSERT INTO sms_rate_limits(bucket_key,window_start,attempt_count,updated_at) VALUES(?,?,0,?) ON DUPLICATE KEY UPDATE updated_at=VALUES(updated_at)'
            : 'INSERT OR IGNORE INTO sms_rate_limits(bucket_key,window_start,attempt_count,updated_at) VALUES(?,?,0,?)';
        $this->db->run($sql, [$key, $now, $now]);
        if ($this->db->driver === 'sqlite') $this->db->run('UPDATE sms_rate_limits SET updated_at=? WHERE bucket_key=?', [$now, $key]);
        return $this->db->one('SELECT * FROM sms_rate_limits WHERE bucket_key=?' . $this->db->lock(), [$key]);
    }

    public function purgeSms()
    {
        // Each exact-key DELETE commits independently: cleanup never holds one bucket while waiting for
        // another request's bucket/challenge. The current delete rechecks a touched row after waiting.
        $now = time(); $count = ['challenges' => 0, 'rate_buckets' => 0];
        foreach ($this->db->all('SELECT id FROM sms_challenges WHERE expires_at<? ORDER BY expires_at LIMIT 200', [$now - 86400]) as $row)
            $count['challenges'] += $this->db->run('DELETE FROM sms_challenges WHERE id=? AND expires_at<?', [(int)$row['id'], $now - 86400])->rowCount();
        foreach ($this->db->all('SELECT bucket_key FROM sms_rate_limits WHERE updated_at<? ORDER BY updated_at LIMIT 200', [$now - 7 * 86400]) as $row)
            $count['rate_buckets'] += $this->db->run('DELETE FROM sms_rate_limits WHERE bucket_key=? AND updated_at<?', [$row['bucket_key'], $now - 7 * 86400])->rowCount();
        return $count;
    }

    private function smsLimits(array $limits, $now)
    {
        usort($limits, function ($a, $b) { return strcmp($a[0], $b[0]); }); $rows = [];
        foreach ($limits as $limit) {
            $row = $this->smsBucket($limit[0], $now);
            $count = $now - (int)$row['window_start'] >= $limit[1] ? 0 : (int)$row['attempt_count'];
            if ($count >= $limit[2]) return new ApiError('Too many SMS attempts. Please try again later.', 429, 42903);
            $rows[] = [$row, $count];
        }
        foreach ($rows as $entry) $this->db->run('UPDATE sms_rate_limits SET window_start=?,attempt_count=?,updated_at=? WHERE bucket_key=?',
            [$entry[1] === 0 ? $now : (int)$entry[0]['window_start'], $entry[1] + 1, $now, $entry[0]['bucket_key']]);
        return null;
    }

    public function invitation(array $user)
    {
        return $this->db->transaction(function () use ($user) {
            $this->db->one('SELECT id FROM users WHERE id=?' . $this->db->lock(), [(int)$user['id']]);
            $row = $this->db->one('SELECT code FROM invitations WHERE user_id=?', [(int)$user['id']]);
            if (!$row) {
                $row = ['code' => strtoupper(bin2hex(random_bytes(6)))];
                $this->db->insert('invitations', $row + ['user_id' => (int)$user['id'], 'referrer_id' => null, 'created_at' => time()]);
            }
            return ['invitation_code' => $row['code']];
        });
    }

    public function login(array $body, $ip)
    {
        $username = strtolower(Validation::text($body['username'] ?? '', 'username', 120));
        $password = Validation::text($body['password'] ?? '', 'password', 72);
        $this->rateLimit($ip, 'login');
        $user = $this->db->one('SELECT * FROM users WHERE username=?', [$username]);
        // Keep password verification work comparable for an unknown account.
        $hash = $user ? $user['password_hash'] : '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi';
        if (!password_verify($password, $hash) || !$user || $user['status'] !== 'active') throw new ApiError('Account or password is incorrect.', 401, 40102);
        if ($user['org_id']) {
            $org = $this->db->one('SELECT status FROM organizations WHERE id=?', [(int)$user['org_id']]);
            if (!$org || $org['status'] !== 'approved') throw new ApiError('Organization access is not approved.', 403, 40301);
        }
        $this->db->run('DELETE FROM auth_attempts WHERE attempt_key=?', [hash('sha256', 'login|' . $ip)]);
        return $this->issue($user);
    }

    private function rateLimit($ip, $purpose)
    {
        $key = hash('sha256', $purpose . '|' . $ip);
        $count = (int)$this->db->scalar('SELECT COUNT(*) FROM auth_attempts WHERE attempt_key=? AND created_at>?', [$key, time() - 900]);
        if ($count >= 10) throw new ApiError('Too many attempts. Please try again later.', 429, 429);
        $this->db->insert('auth_attempts', ['attempt_key' => $key, 'created_at' => time()]);
    }

    private function issue(array $user)
    {
        $user['permissions'] = (new Rbac($this->db))->effective($user);
        $user['phone_bound'] = (bool)$this->db->scalar('SELECT COUNT(*) FROM phone_identities WHERE user_id=?', [(int)$user['id']]);
        $token = bin2hex(random_bytes(32));
        $ttl = max(300, min(2592000, (int)(getenv('TOKEN_TTL_SECONDS') ?: 604800)));
        $this->db->insert('tokens', ['user_id' => (int)$user['id'], 'token_hash' => hash('sha256', $token), 'expires_at' => time() + $ttl, 'created_at' => time()]);
        return ['token' => $token, 'expires_at' => time() + $ttl, 'user' => self::publicUser($user)];
    }

    public function logout($token) { $this->db->run('DELETE FROM tokens WHERE token_hash=?', [hash('sha256', $token)]); return ['logged_out' => true]; }

    public static function publicUser(array $user)
    {
        unset($user['password_hash']);
        foreach (['id', 'member_until', 'growth_points', 'created_at', 'updated_at'] as $field) $user[$field] = (int)$user[$field];
        $user['org_id'] = $user['org_id'] === null ? null : (int)$user['org_id'];
        $user['is_member'] = $user['member_until'] > time();
        if (array_key_exists('phone_bound', $user)) $user['phone_bound'] = (bool)$user['phone_bound'];
        return $user;
    }
}
