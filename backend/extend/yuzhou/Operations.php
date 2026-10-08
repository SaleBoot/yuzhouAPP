<?php
namespace yuzhou;

class Operations
{
    private $db;
    public function __construct(Database $db) { $this->db = $db; }

    public function dispatch($method, $path, array $body, array $query, $user)
    {
        if ($path === 'points-shop/products' && $method === 'GET') return (new Catalog($this->db))->listing('growth_products', $query);
        if ($path === 'points-shop/redeem' && $method === 'POST') return $this->redeem($body, $user);
        if ($path === 'points-shop/redemptions' && $method === 'GET') return $this->db->paginate('point_redemptions', $user['role'] === 'platform' ? '1=1' : 'user_id=?', $user['role'] === 'platform' ? [] : [(int)$user['id']], $query);
        if (preg_match('#^points-shop/redemptions/([0-9]+)/(cancel|review)$#D', $path, $match) && $method === 'POST') return $this->review($match[1], $match[2], $body, $user);
        if (preg_match('#^posts/([0-9]+)/like$#D', $path, $match) && in_array($method, ['POST', 'DELETE'], true)) {
            return $this->db->transaction(function () use ($method, $match, $user) {
                (new Catalog($this->db))->get($match[1], 'posts', null, true);
                $this->db->run('DELETE FROM post_likes WHERE post_id=? AND user_id=?', [(int)$match[1], (int)$user['id']]);
                if ($method === 'POST') $this->db->insert('post_likes', ['post_id' => (int)$match[1], 'user_id' => (int)$user['id'], 'created_at' => time()]);
                return ['liked' => $method === 'POST', 'like_count' => (int)$this->db->scalar('SELECT COUNT(*) FROM post_likes WHERE post_id=?', [(int)$match[1]])];
            });
        }
        if (preg_match('#^posts/([0-9]+)/pin$#D', $path, $match) && $method === 'POST') {
            Policy::roles($user, ['platform']);
            if (!isset($body['is_pinned']) || !is_bool($body['is_pinned'])) throw new ApiError('is_pinned must be boolean.');
            return $this->db->transaction(function () use ($match, $body, $user) {
                $post = (new Catalog($this->db))->get($match[1], 'posts', $user, true);
                if ($this->db->one('SELECT post_id FROM post_metadata WHERE post_id=?', [$post['id']])) $this->db->run('UPDATE post_metadata SET is_pinned=? WHERE post_id=?', [$body['is_pinned'] ? 1 : 0, $post['id']]);
                else $this->db->insert('post_metadata', ['post_id' => $post['id'], 'post_type' => 'forum', 'is_pinned' => $body['is_pinned'] ? 1 : 0]);
                $this->db->run('UPDATE resources SET version=version+1,updated_at=? WHERE id=?', [time(), $post['id']]);
                Policy::audit($this->db, $user, 'post.pin', 'post', $post['id'], ['is_pinned' => $body['is_pinned']]);
                return (new Catalog($this->db))->get($post['id'], 'posts', $user);
            });
        }
        if (preg_match('#^posts/([0-9]+)/official-replies$#D', $path, $match)) {
            (new Catalog($this->db))->get($match[1], 'posts');
            if ($method === 'GET') return $this->db->paginate('post_official_replies', 'post_id=?', [(int)$match[1]], $query, 'id ASC');
            if ($method === 'POST') {
                Policy::roles($user, ['platform']);
                $id = $this->db->insert('post_official_replies', ['post_id' => (int)$match[1], 'author_id' => (int)$user['id'], 'content' => Validation::text($body['content'] ?? '', 'content', 2000), 'created_at' => time()]);
                Policy::audit($this->db, $user, 'post.official_reply', 'post', $match[1], ['reply_id' => $id]);
                return $this->db->one('SELECT * FROM post_official_replies WHERE id=?', [$id]);
            }
        }
        return null;
    }

    private function redeem(array $body, array $user)
    {
        Policy::roles($user, ['consumer', 'broker']);
        $id = Validation::id($body['product_id'] ?? 0, 'product_id');
        $quantity = Validation::integer($body['quantity'] ?? 1, 'quantity', 1, 50);
        $key = Validation::key($body['idempotency_key'] ?? '');
        return $this->db->transaction(function () use ($id, $quantity, $key, $user) {
            $account = $this->db->one('SELECT * FROM users WHERE id=?' . $this->db->lock(), [(int)$user['id']]);
            $old = $this->db->one('SELECT * FROM point_redemptions WHERE user_id=? AND idempotency_key=?', [(int)$user['id'], $key]);
            if ($old) {
                if ((int)$old['product_id'] !== $id || (int)$old['quantity'] !== $quantity) throw new ApiError('Redemption idempotency request does not match.', 409, 409);
                return $old;
            }
            $product = (new Catalog($this->db))->get($id, 'growth_products', null, true);
            $points = (int)$product['payload']['points_price'] * $quantity;
            if ((int)$product['payload']['stock'] < $quantity || (int)$account['growth_points'] < $points) throw new ApiError('Points or reward stock are insufficient.', 409, 409);
            $redemptionId = $this->db->insert('point_redemptions', ['product_id' => $id, 'user_id' => (int)$user['id'], 'quantity' => $quantity, 'points' => $points, 'idempotency_key' => $key,
                'status' => 'pending_fulfillment', 'item_snapshot' => json_encode($product, JSON_UNESCAPED_UNICODE), 'notes' => '', 'reviewed_by' => null, 'created_at' => time(), 'updated_at' => time()]);
            $this->db->run('UPDATE growth_products SET stock=stock-? WHERE resource_id=?', [$quantity, $id]);
            (new Community($this->db))->grow((int)$user['id'], 'points-redemption:' . $redemptionId, -$points, 'Points shop redemption');
            Policy::audit($this->db, $user, 'points.redeem', 'point_redemption', $redemptionId, ['points' => $points]);
            return $this->db->one('SELECT * FROM point_redemptions WHERE id=?', [$redemptionId]);
        });
    }

    private function review($id, $action, array $body, array $user)
    {
        if ($action === 'review') Policy::roles($user, ['platform']);
        return $this->db->transaction(function () use ($id, $action, $body, $user) {
            $row = $this->db->one('SELECT * FROM point_redemptions WHERE id=?' . $this->db->lock(), [Validation::id($id)]);
            if (!$row || ($action === 'cancel' && (int)$row['user_id'] !== (int)$user['id'])) throw new ApiError('Redemption not found.', 404, 404);
            if ($row['status'] !== 'pending_fulfillment') throw new ApiError('Redemption has already been processed.', 409, 409);
            $this->db->one('SELECT id FROM users WHERE id=?' . $this->db->lock(), [(int)$row['user_id']]);
            $status = $action === 'cancel' ? 'cancelled' : Validation::choice($body['status'] ?? '', ['fulfilled', 'rejected'], 'status');
            $notes = Validation::text($body['notes'] ?? '', 'notes or physical fulfillment evidence', 2000, $status === 'fulfilled');
            if ($status !== 'fulfilled') {
                (new Catalog($this->db))->get($row['product_id'], 'growth_products', ['id' => 0, 'org_id' => null, 'role' => 'platform'], true);
                $this->db->run('UPDATE growth_products SET stock=stock+? WHERE resource_id=?', [(int)$row['quantity'], (int)$row['product_id']]);
                (new Community($this->db))->grow((int)$row['user_id'], 'points-redemption-return:' . $row['id'], (int)$row['points'], 'Cancelled or rejected redemption');
            }
            $this->db->update('point_redemptions', $row['id'], ['status' => $status, 'notes' => $notes, 'reviewed_by' => (int)$user['id'], 'updated_at' => time()]);
            Policy::audit($this->db, $user, 'points.' . $status, 'point_redemption', $row['id']);
            return $this->db->one('SELECT * FROM point_redemptions WHERE id=?', [(int)$row['id']]);
        });
    }
}
