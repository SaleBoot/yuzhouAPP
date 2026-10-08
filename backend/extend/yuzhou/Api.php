<?php
namespace yuzhou;

class Api
{
    private $db;
    public function __construct(Database $db) { $this->db = $db; }

    public function dispatch($method, $path, array $body = [], array $query = [], $token = null, array $files = [], $ip = 'local')
    {
        $path = trim($path, '/');
        if (strpos($path, 'api/v1/') === 0) $path = substr($path, 7);
        $public = in_array($path, ['bootstrap', 'health', 'auth/register', 'auth/login', 'auth/sms'], true)
            || ($method === 'GET' && (strpos($path, 'resources/') === 0 || strpos($path, 'files/') === 0 || $path === 'inventory' || $path === 'points-shop/products' || $path === 'recommendations' || preg_match('#^posts/[0-9]+/(comments|official-replies)$#D', $path)));
        $auth = new Auth($this->db); $user = $auth->user($token, !$public);
        (new Rbac($this->db))->guard($path, $user);
        $catalog = new Catalog($this->db); $orders = new Orders($this->db); $finance = new Finance($this->db); $community = new Community($this->db); $admin = new Admin($this->db);
        $parts = explode('/', $path); $root = $parts[0];
        if ($path === 'health' && $method === 'GET') return ['status' => 'ok', 'framework' => 'ThinkPHP 5.1.41 LTS', 'database' => $this->db->driver];
        if ($root === 'auth') {
            if ($path === 'auth/register' && $method === 'POST') return $auth->register($body, $ip);
            if ($path === 'auth/login' && $method === 'POST') return $auth->login($body, $ip);
            if ($path === 'auth/me' && $method === 'GET') return Auth::publicUser($user);
            if ($path === 'auth/invitation' && $method === 'GET') return $auth->invitation($user);
            if ($path === 'auth/logout' && $method === 'POST') return $auth->logout($token);
            if ($path === 'auth/sms' && $method === 'POST') throw new ApiError('Real SMS service is not configured.', 503, 50311);
        }
        if ($path === 'bootstrap' && $method === 'GET') {
            $env = getenv('APP_ENV') ?: 'production';
            return ['brand' => '驭舟', 'api_version' => '1.0', 'vr_base_url' => getenv('VR_BASE_URL') ?: '', 'environment' => $env, 'is_test_environment' => in_array($env, ['development', 'test'], true),
                'test_payments_enabled' => in_array($env, ['development', 'test'], true) && getenv('ALLOW_TEST_PAYMENTS') === '1',
                'services' => ['payment' => 'unconfigured', 'sms' => 'unconfigured', 'external_esign' => 'unconfigured', 'insurance' => 'unconfigured', 'push' => 'unconfigured', 'ai_moderation' => 'unconfigured'],
                'resource_kinds' => Catalog::$kinds, 'order_types' => Orders::$types];
        }
        if ($root === 'resources' && isset($parts[1])) {
            if (count($parts) === 2 && $method === 'GET') return $catalog->listing($parts[1], $query, $user);
            if (count($parts) === 3 && $method === 'GET') return $catalog->get($parts[2], $parts[1], $user);
            if (!$user) throw new ApiError('Authentication required.', 401, 401);
            if (count($parts) === 2 && $method === 'POST') return $catalog->save($parts[1], $body, $user);
            if (count($parts) === 3 && $method === 'PATCH') return $catalog->save($parts[1], $body, $user, $parts[2]);
        }
        if ($path === 'inventory' && $method === 'GET') return $orders->inventory($query);
        if ($path === 'recommendations' && $method === 'GET') return (new Recommendations($this->db))->listing($query,$user);
        if ($root === 'orders') {
            if ($path === 'orders' && $method === 'POST') return $orders->create($body, $user);
            if ($path === 'orders' && $method === 'GET') return $orders->listing($user, $query);
            if (count($parts) === 3 && $parts[2] === 'contracts' && $method === 'GET') return $orders->contracts($parts[1], $user);
            if (count($parts) === 2 && $method === 'GET') {
                $order = $orders->get($parts[1], $user);
                $order['events'] = $this->db->all('SELECT * FROM order_events WHERE order_id=? ORDER BY id', [$order['id']]);
                foreach ($order['events'] as &$event) $event['payload'] = json_decode($event['payload'], true);
                $order['contracts'] = $orders->contracts($order['id'], $user)['items'];
                $order['refund_requests'] = $this->db->all('SELECT * FROM refund_requests WHERE order_id=? ORDER BY id DESC', [$order['id']]);
                $order['schedule'] = $this->db->one('SELECT * FROM order_service_schedules WHERE order_id=?', [$order['id']]);
                $order['progress'] = $this->db->all('SELECT * FROM order_progress WHERE order_id=? ORDER BY id', [$order['id']]);
                return $order;
            }
            if (count($parts) === 3 && $method === 'POST') return $orders->action($parts[1], $parts[2], $body, $user);
        }
        if ($path === 'ledger' && $method === 'GET') return $finance->ledger($user, $query);
        if ($path === 'withdrawals' && $method === 'GET') {
            list($where, $params) = $finance->scope($user, $query);
            return $this->db->paginate('withdrawals', $where, $params, $query);
        }
        if ($path === 'withdrawals' && $method === 'POST') return $finance->withdraw($body, $user);
        if (preg_match('#^withdrawals/([0-9]+)/review$#D', $path, $match) && $method === 'POST') return $finance->reviewWithdrawal($match[1], $body, $user);
        if ($path === 'settings') {
            Policy::roles($user, ['platform']);
            if ($method === 'GET') return $finance->settings();
            if ($method === 'PUT') return $finance->saveSettings($body, $user);
        }
        if ($path === 'uploads' && $method === 'POST') return (new FileStore($this->db))->upload($files['file'] ?? [], $body, $user);
        if (preg_match('#^files/([0-9]+)$#D', $path, $match) && $method === 'GET') return (new FileStore($this->db))->read($match[1], $user);
        if (preg_match('#^events/([0-9]+)/(enroll|review)$#D', $path, $match) && $method === 'POST') return $match[2] === 'enroll' ? $community->enroll($match[1], $user) : $community->reviewEvent($match[1], $body, $user);
        if ($path === 'enrollments' && $method === 'GET') return $community->enrollmentList($user, $query);
        if (preg_match('#^enrollments/([0-9]+)/check-in$#D', $path, $match) && $method === 'POST') return $community->checkIn($match[1], $body, $user);
        if (preg_match('#^enrollments/([0-9]+)/cancel$#D', $path, $match) && $method === 'POST') return $community->cancelEnrollment($match[1], $body, $user);
        if ($path === 'learning/progress') {
            if ($method === 'POST') return $community->progress($body, $user);
            if ($method === 'GET') return $this->db->paginate('learning_progress', 'user_id=?', [(int)$user['id']], $query);
        }
        if ($path === 'learning/exams' && $method === 'POST') return $community->exam($body, $user);
        if ($path === 'learning/rewards' && $method === 'GET') return $community->rewardList($query, $user);
        if ($path === 'admin/course-rewards' && $method === 'GET') return $community->rewardList($query, $user, true);
        if (preg_match('#^admin/course-rewards/([0-9]+)/fulfill$#D', $path, $match) && $method === 'POST') return $community->fulfillReward($match[1], $body, $user);
        if ($path === 'growth' && $method === 'GET') {
            $list = $this->db->paginate('growth_logs', 'user_id=?', [(int)$user['id']], $query);
            return $list + ['points' => (int)$user['growth_points'], 'member_until' => (int)$user['member_until']];
        }
        if ($path === 'coupons/my' && $method === 'GET') return $community->coupons($query, $user);
        if (preg_match('#^coupons/([0-9]+)/claim$#D', $path, $match) && $method === 'POST') return $community->claimCoupon($match[1], $user);
        if (preg_match('#^posts/([0-9]+)/comments$#D', $path, $match)) {
            if ($method === 'POST') return $community->comment($match[1], $body, $user);
            if ($method === 'GET') { $catalog->get($match[1], 'posts'); return $this->db->paginate('comments', 'post_id=? AND status=?', [(int)$match[1], 'published'], $query); }
        }
        if (preg_match('#^posts/([0-9]+)/review$#D', $path, $match) && $method === 'POST') return $community->reviewPost($match[1], $body, $user);
        if ($path === 'admin/comments' && $method === 'GET') {
            Policy::roles($user, ['platform']); $where = '1=1'; $params = [];
            if (isset($query['status'])) { $where .= ' AND status=?'; $params[] = Validation::choice($query['status'], ['pending', 'published', 'rejected'], 'status'); }
            if (isset($query['post_id'])) { $where .= ' AND post_id=?'; $params[] = Validation::id($query['post_id'], 'post_id'); }
            return $this->db->paginate('comments', $where, $params, $query);
        }
        if (preg_match('#^comments/([0-9]+)/review$#D', $path, $match) && $method === 'POST') {
            Policy::roles($user, ['platform']); $status = Validation::choice($body['status'] ?? '', ['published', 'rejected'], 'status');
            $row = $this->db->one('SELECT * FROM comments WHERE id=? AND status=?', [(int)$match[1], 'pending']);
            if (!$row) throw new ApiError('Comment is not awaiting review.', 409, 409);
            $this->db->update('comments', $row['id'], ['status' => $status]); Policy::audit($this->db, $user, 'comment.review', 'comment', $row['id'], ['status' => $status]);
            return $this->db->one('SELECT * FROM comments WHERE id=?', [(int)$row['id']]);
        }
        if ($root === 'cart') {
            if ($path === 'cart' && $method === 'GET') return $community->cart($query, $user);
            if ($path === 'cart' && $method === 'POST') return $community->cartSave($body, $user);
            if (count($parts) === 2 && $method === 'PATCH') return $community->cartSave($body, $user, Validation::id($parts[1]));
            if (count($parts) === 2 && $method === 'DELETE') { $this->db->run('DELETE FROM cart_items WHERE id=? AND user_id=?', [Validation::id($parts[1]), (int)$user['id']]); return ['deleted' => true]; }
        }
        if ($user) {
            $result = (new Crm($this->db))->dispatch($method,$path,$body,$query,$user);
            if ($result !== null) return $result;
        }
        if ($root === 'customers') return $this->customers($method, $parts, $body, $query, $user);
        if ($path === 'applications' && $method === 'POST') return $admin->apply($body, $user);
        if ($path === 'applications' && $method === 'GET') return $admin->applications($query, $user);
        if (preg_match('#^applications/([0-9]+)/review$#D', $path, $match) && $method === 'POST') return $admin->reviewApplication($match[1], $body, $user);
        if ($path === 'admin/users' && $method === 'GET') return $admin->users($query, $user);
        if (preg_match('#^admin/users/([0-9]+)$#D', $path, $match) && $method === 'PATCH') return $admin->updateUser($match[1], $body, $user);
        if ($path === 'admin/organizations' && $method === 'GET') return $admin->organizations($query, $user);
        if ($path === 'admin/organizations' && $method === 'POST') return $admin->organization($body, $user);
        if (preg_match('#^admin/organizations/([0-9]+)$#D', $path, $match) && $method === 'PATCH') return $admin->organization($body, $user, $match[1]);
        if ($path === 'admin/audit' && $method === 'GET') { Policy::roles($user, ['platform']); return $this->db->paginate('audit_logs', '1=1', [], $query); }
        if ($path === 'admin/refund-requests' && $method === 'GET') {
            Policy::roles($user, ['platform']); $where = '1=1'; $params = [];
            if (!empty($query['status'])) { $where .= ' AND status=?'; $params[] = Validation::choice($query['status'], ['pending', 'approved', 'rejected', 'successful'], 'status'); }
            return $this->db->paginate('refund_requests', $where, $params, $query);
        }
        if (preg_match('#^admin/refund-requests/([0-9]+)/review$#D', $path, $match) && $method === 'POST') {
            Policy::roles($user, ['platform']);
            return $this->db->transaction(function () use ($match, $body, $user) {
                $row = $this->db->one('SELECT * FROM refund_requests WHERE id=? AND status=?' . $this->db->lock(), [(int)$match[1], 'pending']);
                if (!$row) throw new ApiError('Refund request is not awaiting review.', 409, 409);
                $status = Validation::choice($body['status'] ?? '', ['approved', 'rejected'], 'status');
                $this->db->update('refund_requests', $row['id'], ['status' => $status, 'reviewed_by' => (int)$user['id'], 'review_notes' => Validation::text($body['notes'] ?? '', 'notes', 2000, false), 'updated_at' => time()]);
                Policy::audit($this->db, $user, 'refund_request.' . $status, 'refund_request', $row['id']);
                return $this->db->one('SELECT * FROM refund_requests WHERE id=?', [(int)$row['id']]);
            });
        }
        if ($user && class_exists('yuzhou\\Reports')) {
            $result = (new Reports($this->db))->dispatch($method, $path, $query, $user);
            if ($result !== null) return $result;
        }
        $result = (new Operations($this->db))->dispatch($method, $path, $body, $query, $user);
        if ($result !== null) return $result;
        if ($user) {
            $result = (new Rbac($this->db))->dispatch($method, $path, $body, $query, $user);
            if ($result !== null) return $result;
        }
        if (class_exists('yuzhou\\Communication')) {
            $result = (new Communication($this->db))->dispatch($method, $path, $body, $query, $user);
            if ($result !== null) return $result;
        }
        throw new ApiError('Endpoint not found.', 404, 404);
    }

    private function customers($method, array $parts, array $body, array $query, array $user)
    {
        Policy::roles($user, ['broker', 'agency', 'platform']);
        $where = $user['role'] === 'platform' ? '1=1' : ($user['role'] === 'broker' ? 'broker_id=?' : 'org_id=?');
        $params = $user['role'] === 'platform' ? [] : [(int)($user['role'] === 'broker' ? $user['id'] : $user['org_id'])];
        if (count($parts) === 1 && $method === 'GET') {
            if (!empty($query['search'])) { $where .= ' AND (name LIKE ? OR notes LIKE ?)'; $search = '%' . Validation::text($query['search'], 'search', 100) . '%'; $params[] = $search; $params[] = $search; }
            if (!empty($query['tag_id'])) { $where .= ' AND id IN (SELECT customer_id FROM customer_tag_links WHERE tag_id=?)'; $params[] = Validation::id($query['tag_id'],'tag_id'); }
            $list = $this->db->paginate('customers', $where, $params, $query);
            $list['items'] = array_map([new Crm($this->db),'customer'],$list['items']);
            return $list;
        }
        if (count($parts) === 1 && $method === 'POST') {
            $brokerId = $user['role'] === 'broker' ? (int)$user['id'] : Validation::id($body['broker_id'] ?? 0, 'broker_id');
            $broker = $this->db->one('SELECT * FROM users WHERE id=? AND role=? AND status=?', [$brokerId, 'broker', 'active']);
            if (!$broker || !$broker['org_id'] || ($user['role'] === 'agency' && (int)$broker['org_id'] !== (int)$user['org_id'])) throw new ApiError('Broker is outside your organization.', 403, 403);
            $customer = isset($body['user_id']) ? $this->db->one('SELECT * FROM users WHERE id=?', [Validation::id($body['user_id'], 'user_id')]) : $this->db->one('SELECT * FROM users WHERE username=?', [strtolower(Validation::text($body['username'] ?? '', 'username', 120))]);
            if (!$customer || $customer['role'] !== 'consumer' || $customer['status'] !== 'active') throw new ApiError('Customer account not found.', 404, 404);
            if ($this->db->one('SELECT id FROM customers WHERE user_id=? AND broker_id=?', [(int)$customer['id'], $brokerId])) throw new ApiError('Customer is already assigned.', 409, 409);
            $id = $this->db->insert('customers', ['user_id' => (int)$customer['id'], 'broker_id' => $brokerId, 'org_id' => (int)$broker['org_id'],
                'name' => Validation::text($body['name'] ?? $customer['nickname'], 'name', 120), 'notes' => Validation::text($body['notes'] ?? '', 'notes', 2000, false), 'stage' => 'new', 'created_at' => time(), 'updated_at' => time()]);
            return (new Crm($this->db))->customer($this->db->one('SELECT * FROM customers WHERE id=?', [$id]));
        }
        if (count($parts) === 2 && $method === 'PATCH') {
            $params[] = Validation::id($parts[1]); $row = $this->db->one('SELECT * FROM customers WHERE ' . $where . ' AND id=?', $params);
            if (!$row) throw new ApiError('Customer not found.', 404, 404);
            $this->db->update('customers', $row['id'], ['notes' => Validation::text($body['notes'] ?? $row['notes'], 'notes', 2000, false),
                'stage' => Validation::choice($body['stage'] ?? $row['stage'], ['new', 'contacted', 'viewing', 'quoted', 'won', 'lost', 'archived'], 'stage'), 'updated_at' => time()]);
            return (new Crm($this->db))->customer($this->db->one('SELECT * FROM customers WHERE id=?', [(int)$row['id']]));
        }
        throw new ApiError('Endpoint not found.', 404, 404);
    }
}
