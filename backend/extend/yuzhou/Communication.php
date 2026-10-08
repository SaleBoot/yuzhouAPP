<?php
namespace yuzhou;

class Communication
{
    private $db;
    public function __construct(Database $db) { $this->db = $db; }

    public function dispatch($method, $path, array $body, array $query, $user)
    {
        $path = trim($path, '/');
        if (strpos($path, 'api/v1/') === 0) $path = substr($path, 7);
        $roots = ['conversations', 'appointments', 'notifications', 'favorites', 'footprints', 'boat-records', 'reminders', 'feedback', 'reviews'];
        $root = explode('/', $path)[0];
        if (!in_array($root, $roots, true)) return null;
        if (!$user) throw new ApiError('请先登录。', 401, 401);
        $id = preg_match('#^[a-z-]+/([1-9][0-9]*)#D', $path, $match) ? (int)$match[1] : null;
        if ($root === 'conversations') {
            if ($path === $root && $method === 'GET') return $this->conversationList($user, $query);
            if ($path === $root && $method === 'POST') return $this->conversationCreate($user, $body);
            if ($id && preg_match('#^conversations/[0-9]+/messages$#D', $path)) {
                $this->conversation($id, $user);
                if ($method === 'GET') return $this->db->paginate('messages', 'conversation_id=? AND id>?', [$id, (int)($query['after_id'] ?? 0)], $query, 'id ASC');
                if ($method === 'POST') return $this->messageCreate($id, $user, $body);
            }
            if ($id && $path === 'conversations/' . $id . '/read' && $method === 'POST') {
                $this->conversation($id, $user);
                $latest = (int)$this->db->scalar('SELECT MAX(id) FROM messages WHERE conversation_id=?', [$id]);
                $this->db->run('UPDATE conversation_members SET read_message_id=? WHERE conversation_id=? AND user_id=?', [$latest, $id, (int)$user['id']]);
                return ['read_message_id' => $latest];
            }
        } elseif ($root === 'appointments') {
            if ($path === $root && $method === 'GET') {
                list($where, $params) = $this->appointmentScope($user);
                return $this->db->paginate('appointments', $where, $params, $query);
            }
            if ($path === $root && $method === 'POST') return $this->appointmentCreate($user, $body);
            if ($id && $path === $root . '/' . $id && $method === 'PATCH') return $this->appointmentUpdate($id, $user, $body);
        } elseif ($root === 'notifications') {
            if ($path === $root && $method === 'GET') return $this->db->paginate('notifications', 'user_id=?', [(int)$user['id']], $query);
            if ($id && $path === $root . '/' . $id . '/read' && $method === 'POST') {
                $row = $this->db->one('SELECT * FROM notifications WHERE id=? AND user_id=?', [$id, (int)$user['id']]);
                if (!$row) throw new ApiError('消息不存在。', 404, 404);
                $this->db->update('notifications', $id, ['read_at' => time()]); return ['read' => true];
            }
        } else {
            $kind = ['favorites' => 'favorite', 'footprints' => 'footprint', 'boat-records' => 'boat', 'reminders' => 'reminder', 'feedback' => 'feedback', 'reviews' => 'review'][$root];
            if ($kind === 'feedback' && $id && $path === 'feedback/' . $id . '/reply' && $method === 'POST') return $this->feedbackReply($id, $body, $user);
            if ($path === $root && $method === 'GET') {
                $where = 'kind=? AND status<>?'; $params = [$kind, 'deleted'];
                if ($kind !== 'feedback' || $user['role'] !== 'platform') { $where .= ' AND user_id=?'; $params[] = (int)$user['id']; }
                $rows = $this->db->paginate('user_records', $where, $params, $query);
                foreach ($rows['items'] as &$row) $row = $this->decode($row);
                return $rows;
            }
            if ($path === $root && $method === 'POST') return $this->recordCreate($kind, $user, $body);
            if ($id && $path === $root . '/' . $id && in_array($method, ['PATCH', 'DELETE'], true)) {
                $row = $this->db->one('SELECT * FROM user_records WHERE id=? AND kind=? AND status<>?', [$id, $kind, 'deleted']);
                if (!$row || (int)$row['user_id'] !== (int)$user['id']) throw new ApiError('记录不存在。', 404, 404);
                if ($method === 'DELETE') { $this->db->update('user_records', $id, ['status' => 'deleted', 'updated_at' => time()]); return ['deleted' => true]; }
                if (in_array($kind, ['review', 'favorite', 'footprint', 'feedback'], true)) throw new ApiError('该记录不支持修改。');
                $payload = $this->validateRecord($kind, $body['payload'] ?? []);
                $this->db->transaction(function () use ($kind, $payload, $id, $body, $row) {
                    $this->db->update('user_records', $id, ['title' => Validation::text($body['title'] ?? $row['title'], 'title'), 'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE), 'updated_at' => time()]);
                    if ($kind === 'reminder') $this->db->run('UPDATE reminders SET due_date=? WHERE record_id=?', [$payload['due_date'], $id]);
                });
                return $this->decode($this->db->one('SELECT * FROM user_records WHERE id=?', [$id]));
            }
        }
        throw new ApiError('接口不存在。', 404, 404);
    }

    private function feedbackReply($id, array $body, array $user)
    {
        Policy::roles($user, ['platform']);
        $reply = Validation::text($body['reply'] ?? null, 'reply', 2000);
        return $this->db->transaction(function () use ($id, $reply, $user) {
            $row = $this->db->one('SELECT * FROM user_records WHERE id=? AND kind=? AND status<>?' . $this->db->lock(), [$id, 'feedback', 'deleted']);
            if (!$row) throw new ApiError('反馈不存在。', 404, 404);
            $payload = json_decode($row['payload'], true) ?: [];
            if (($payload['reply'] ?? '') === $reply && $row['status'] === 'handled') return $this->decode($row);
            $payload['reply'] = $reply; $payload['replied_at'] = time(); $payload['replied_by'] = (int)$user['id'];
            $this->db->update('user_records', $id, ['payload' => json_encode($payload, JSON_UNESCAPED_UNICODE), 'status' => 'handled', 'updated_at' => time()]);
            self::notify($this->db, (int)$row['user_id'], '意见反馈已回复', $reply, 'feedback', $id);
            Policy::audit($this->db, $user, 'feedback.reply', 'feedback', $id, ['status' => 'handled']);
            return $this->decode($this->db->one('SELECT * FROM user_records WHERE id=?', [$id]));
        });
    }

    private function conversation($id, array $user)
    {
        $row = $this->db->one('SELECT c.* FROM conversations c JOIN conversation_members m ON m.conversation_id=c.id WHERE c.id=? AND m.user_id=?', [$id, (int)$user['id']]);
        if (!$row) throw new ApiError('会话不存在。', 404, 404);
        if ($row['order_id']) {
            $order = $this->db->one('SELECT * FROM orders WHERE id=?', [(int)$row['order_id']]);
            if (!$order || !Policy::orderVisible($user, $order)) throw new ApiError('订单会话访问已失效。', 403, 403);
        }
        return $row;
    }

    private function conversationList(array $user, array $query)
    {
        $page = max(1, (int)($query['page'] ?? 1)); $size = max(1, min(100, (int)($query['page_size'] ?? 20)));
        $ids = $this->db->all('SELECT c.*,m.read_message_id FROM conversations c JOIN conversation_members m ON m.conversation_id=c.id WHERE m.user_id=? ORDER BY c.updated_at DESC LIMIT ' . $size . ' OFFSET ' . (($page - 1) * $size), [(int)$user['id']]);
        $items = [];
        foreach ($ids as $row) {
            try { $this->conversation((int)$row['id'], $user); } catch (ApiError $e) { continue; }
            $row['members'] = $this->db->all('SELECT u.id,u.nickname FROM users u JOIN conversation_members m ON m.user_id=u.id WHERE m.conversation_id=?', [(int)$row['id']]);
            $row['last_message'] = $this->db->one('SELECT id,sender_id,content,created_at FROM messages WHERE conversation_id=? ORDER BY id DESC LIMIT 1', [(int)$row['id']]);
            $row['unread_count'] = (int)$this->db->scalar('SELECT COUNT(*) FROM messages WHERE conversation_id=? AND id>? AND sender_id<>?', [(int)$row['id'], (int)$row['read_message_id'], (int)$user['id']]);
            $items[] = $row;
        }
        return ['items' => $items, 'total' => (int)$this->db->scalar('SELECT COUNT(*) FROM conversation_members WHERE user_id=?', [(int)$user['id']]), 'page' => $page, 'page_size' => $size];
    }

    private function conversationCreate(array $user, array $body)
    {
        if (!empty($body['order_id'])) {
            $orderId = Validation::id($body['order_id'], 'order_id');
            $order = $this->db->one('SELECT * FROM orders WHERE id=?', [$orderId]);
            if (!$order || !Policy::orderVisible($user, $order)) throw new ApiError('订单不存在。', 404, 404);
            $buyer = (int)$order['customer_id'];
            $seller = (int)$order['broker_id'];
            if (!$seller) {
                if ($order['type'] === 'mall') $seller = (int)$this->db->scalar('SELECT id FROM users WHERE role=? AND status=? ORDER BY id LIMIT 1', ['platform', 'active']);
                else $seller = (int)$this->db->scalar('SELECT id FROM users WHERE org_id=? AND role=? AND status=? ORDER BY id LIMIT 1', [(int)$order['org_id'], 'merchant', 'active']);
            }
            $resourceId = (int)$order['resource_id']; $key = 'order:' . $orderId;
        } else {
            Policy::roles($user, ['consumer', 'broker']);
            $resourceId = Validation::id($body['resource_id'] ?? null, 'resource_id');
            $resource = (new Catalog($this->db))->get($resourceId, null, null);
            $seller = (int)$resource['owner_id']; $buyer = (int)$user['id']; $orderId = null;
            $key = 'resource:' . $resourceId . ':user:' . $buyer;
        }
        $sellerUser = $this->db->one('SELECT id,role,status FROM users WHERE id=?', [$seller]);
        if (!$sellerUser || $sellerUser['status'] !== 'active' || !in_array($sellerUser['role'], ['broker', 'merchant', 'platform'], true)) throw new ApiError('暂未配置接待人员。', 409, 409);
        if ($buyer === $seller) throw new ApiError('不能与自己创建会话。');
        return $this->db->transaction(function () use ($key, $buyer, $seller, $orderId, $resourceId, $user) {
            $existing = $this->db->one('SELECT * FROM conversations WHERE context_key=?' . $this->db->lock(), [$key]);
            if ($existing) { $this->conversation((int)$existing['id'], $user); return $existing; }
            $id = $this->db->insert('conversations', ['context_key' => $key, 'order_id' => $orderId, 'resource_id' => $resourceId, 'created_at' => time(), 'updated_at' => time()]);
            foreach ([$buyer, $seller] as $member) $this->db->insert('conversation_members', ['conversation_id' => $id, 'user_id' => $member, 'read_message_id' => 0]);
            return $this->conversation($id, $user);
        });
    }

    private function messageCreate($id, array $user, array $body)
    {
        $key = Validation::key($body['client_id'] ?? null); $content = Validation::text($body['content'] ?? null, 'content', 2000);
        return $this->db->transaction(function () use ($id, $user, $key, $content) {
            $this->db->one('SELECT id FROM conversations WHERE id=?' . $this->db->lock(), [$id]);
            $existing = $this->db->one('SELECT * FROM messages WHERE conversation_id=? AND sender_id=? AND client_id=?', [$id, (int)$user['id'], $key]);
            if ($existing) { if ($existing['content'] !== $content) throw new ApiError('相同消息标识对应不同内容。', 409, 409); return $existing; }
            $recent = (int)$this->db->scalar('SELECT COUNT(*) FROM messages WHERE sender_id=? AND created_at>?', [(int)$user['id'], time() - 60]);
            if ($recent >= 30) throw new ApiError('发送过于频繁，请稍后再试。', 429, 429);
            $messageId = $this->db->insert('messages', ['conversation_id' => $id, 'sender_id' => (int)$user['id'], 'client_id' => $key, 'content' => $content, 'created_at' => time()]);
            $this->db->update('conversations', $id, ['updated_at' => time()]);
            foreach ($this->db->all('SELECT user_id FROM conversation_members WHERE conversation_id=? AND user_id<>?', [$id, (int)$user['id']]) as $member) self::notify($this->db, (int)$member['user_id'], '收到咨询消息', $user['nickname'] . '发送了一条消息', 'conversation', $id);
            return $this->db->one('SELECT * FROM messages WHERE id=?', [$messageId]);
        });
    }

    private function appointmentScope(array $user)
    {
        if ($user['role'] === 'platform') return ['1=1', []];
        if ($user['role'] === 'agency') return ['org_id=? AND broker_id>0', [(int)$user['org_id']]];
        if ($user['role'] === 'broker') return ['broker_id=?', [(int)$user['id']]];
        if ($user['role'] === 'merchant') return ['org_id=? AND broker_id IS NULL', [(int)$user['org_id']]];
        return ['customer_id=?', [(int)$user['id']]];
    }

    private function appointmentCreate(array $user, array $body)
    {
        Policy::roles($user, ['consumer', 'broker']);
        $customer = (int)$user['id']; $broker = null;
        if ($user['role'] === 'broker') {
            $customer = Validation::id($body['customer_id'] ?? null, 'customer_id');
            if (!$this->db->one('SELECT id FROM customers WHERE user_id=? AND broker_id=?', [$customer, (int)$user['id']])) throw new ApiError('请选择本人负责的客户。', 403, 403);
            $broker = (int)$user['id'];
        }
        $resourceId = Validation::id($body['resource_id'] ?? null, 'resource_id');
        $resource = (new Catalog($this->db))->get($resourceId, 'boat_types', null);
        $scheduled = strtotime(Validation::text($body['scheduled_at'] ?? null, 'scheduled_at', 40));
        if (!$scheduled || $scheduled <= time()) throw new ApiError('预约时间必须晚于当前时间。');
        $notes = Validation::text($body['notes'] ?? '', 'notes', 2000, false);
        $organization = $broker ? (int)$user['org_id'] : (int)$resource['org_id'];
        $id = $this->db->insert('appointments', ['customer_id' => $customer, 'broker_id' => $broker, 'org_id' => $organization ?: null, 'merchant_credit_code' => $resource['merchant_credit_code'] ?? null, 'resource_id' => $resourceId, 'scheduled_at' => $scheduled, 'status' => 'pending', 'notes' => $notes, 'created_at' => time(), 'updated_at' => time()]);
        Policy::audit($this->db, $user, 'appointment.create', 'appointment', $id);
        return $this->db->one('SELECT * FROM appointments WHERE id=?', [$id]);
    }

    private function appointmentUpdate($id, array $user, array $body)
    {
        list($where, $params) = $this->appointmentScope($user); $params[] = $id;
        $row = $this->db->one('SELECT * FROM appointments WHERE ' . $where . ' AND id=?', $params);
        if (!$row) throw new ApiError('预约不存在。', 404, 404);
        $status = Validation::choice($body['status'] ?? null, ['confirmed', 'completed', 'cancelled'], 'status');
        if ($user['role'] === 'consumer' && $status !== 'cancelled') throw new ApiError('请由接待人员确认带看。', 403, 403);
        if (in_array($row['status'], ['completed', 'cancelled'], true)) throw new ApiError('预约已经结束。', 409, 409);
        if ($status === 'completed' && $row['status'] !== 'confirmed') throw new ApiError('请先确认预约。', 409, 409);
        $notes = Validation::text($body['notes'] ?? $row['notes'], 'notes', 2000, false);
        $this->db->update('appointments', $id, ['status' => $status, 'notes' => $notes, 'updated_at' => time()]);
        self::notify($this->db, (int)$row['customer_id'], '带看预约更新', '预约状态：' . $status, 'appointment', $id);
        Policy::audit($this->db, $user, 'appointment.' . $status, 'appointment', $id);
        return $this->db->one('SELECT * FROM appointments WHERE id=?', [$id]);
    }

    private function recordCreate($kind, array $user, array $body)
    {
        return $this->db->transaction(function () use ($kind, $user, $body) {
        $resourceId = null; $orderId = null; $payload = $this->validateRecord($kind, $body['payload'] ?? []);
        $title = Validation::text($body['title'] ?? ($kind === 'feedback' ? '意见反馈' : '记录'), 'title', 200);
        if (in_array($kind, ['favorite', 'footprint'], true)) {
            $resourceId = Validation::id($body['resource_id'] ?? null, 'resource_id');
            $resource = (new Catalog($this->db))->get($resourceId, null, null); $title = $resource['title'];
            $existing = $this->db->one('SELECT * FROM user_records WHERE user_id=? AND kind=? AND resource_id=? AND status=?', [(int)$user['id'], $kind, $resourceId, 'active']);
            if ($existing) { $this->db->update('user_records', (int)$existing['id'], ['updated_at' => time()]); return $this->decode($existing); }
        }
        if ($kind === 'review') {
            $orderId = Validation::id($body['order_id'] ?? null, 'order_id');
            $order = $this->db->one('SELECT * FROM orders WHERE id=?', [$orderId]);
            if (!$order || (int)$order['customer_id'] !== (int)$user['id']) throw new ApiError('订单不存在。', 404, 404);
            if ($order['status'] !== 'completed') throw new ApiError('履约完成后才可评价。', 409, 409);
            if ($this->db->one('SELECT id FROM user_records WHERE order_id=? AND kind=?', [$orderId, 'review'])) throw new ApiError('此订单已评价。', 409, 409);
        }
        $id = $this->db->insert('user_records', ['user_id' => (int)$user['id'], 'kind' => $kind, 'resource_id' => $resourceId, 'order_id' => $orderId, 'title' => $title, 'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE), 'status' => 'active', 'created_at' => time(), 'updated_at' => time()]);
        if ($kind === 'reminder') $this->db->run('INSERT INTO reminders (record_id,due_date) VALUES (?,?)', [$id, $payload['due_date']]);
        return $this->decode($this->db->one('SELECT * FROM user_records WHERE id=?', [$id]));
        });
    }

    public function sendDueReminders()
    {
        $today = date('Y-m-d'); $count = 0;
        $rows = $this->db->all('SELECT r.record_id FROM reminders r JOIN user_records u ON u.id=r.record_id WHERE u.status=? AND r.due_date<=? AND (r.last_notified_due_date IS NULL OR r.last_notified_due_date<>r.due_date)', ['active', $today]);
        foreach ($rows as $candidate) $count += $this->db->transaction(function () use ($candidate, $today) {
            $record = $this->db->one('SELECT * FROM user_records WHERE id=?' . $this->db->lock(), [(int)$candidate['record_id']]);
            $reminder = $this->db->one('SELECT * FROM reminders WHERE record_id=?' . $this->db->lock(), [(int)$candidate['record_id']]);
            if (!$record || $record['status'] !== 'active' || $reminder['due_date'] > $today || $reminder['last_notified_due_date'] === $reminder['due_date']) return 0;
            self::notify($this->db, (int)$record['user_id'], '船艇事项提醒', $record['title'] . '已到提醒日期：' . $reminder['due_date'], 'reminder', (int)$record['id']);
            $this->db->run('UPDATE reminders SET last_notified_due_date=? WHERE record_id=?', [$reminder['due_date'], (int)$record['id']]);
            return 1;
        });
        return $count;
    }

    private function validateRecord($kind, $payload)
    {
        if (!is_array($payload)) throw new ApiError('payload必须为对象。');
        $result = [];
        $fields = ['boat' => ['serial_number', 'model', 'notes'], 'reminder' => ['due_date', 'notes'], 'feedback' => ['content', 'contact'], 'review' => ['content']];
        foreach ($fields[$kind] ?? [] as $field) $result[$field] = Validation::text($payload[$field] ?? '', $field, $field === 'content' ? 3000 : 1000, $field === 'content' || $field === 'due_date');
        if ($kind === 'review') $result['rating'] = Validation::integer($payload['rating'] ?? null, 'rating', 1, 5);
        if ($kind === 'reminder') {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $result['due_date']);
            if (!$date || $date->format('Y-m-d') !== $result['due_date']) throw new ApiError('提醒日期格式错误。');
        }
        return $result;
    }

    private function decode(array $row) { $row['payload'] = json_decode($row['payload'], true) ?: []; return $row; }
    public static function notify(Database $db, $userId, $title, $content, $type, $objectId)
    {
        return $db->insert('notifications', ['user_id' => $userId, 'title' => $title, 'content' => $content, 'object_type' => $type, 'object_id' => $objectId, 'read_at' => null, 'created_at' => time()]);
    }
}
