<?php
namespace yuzhou;

class Auth
{
    private $db;
    public function __construct(Database $db) { $this->db = $db; }

    public function user($token, $required = true)
    {
        if (!$token) {
            if ($required) throw new ApiError('Authentication required.', 401, 401);
            return null;
        }
        $user = $this->db->one('SELECT u.* FROM users u JOIN tokens t ON t.user_id=u.id WHERE t.token_hash=? AND t.expires_at>? AND u.status=?', [hash('sha256', $token), time(), 'active']);
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
            return $this->issue($this->db->one('SELECT * FROM users WHERE id=?', [$id]));
        });
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
        return $user;
    }
}
