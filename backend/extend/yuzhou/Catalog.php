<?php
namespace yuzhou;

class Catalog
{
    public static $kinds = ['boat_types', 'boats', 'products', 'upgrades', 'maintenance', 'events', 'courses', 'coupons', 'posts', 'growth_products', 'banners'];
    private $db;
    public function __construct(Database $db) { $this->db = $db; }

    public static function decode(array $row, $public = true)
    {
        $row['payload'] = json_decode($row['payload'], true) ?: [];
        foreach (['id', 'owner_id', 'price_cents', 'version', 'created_at', 'updated_at'] as $field) $row[$field] = (int)($row[$field] ?? 0);
        $row['org_id'] = $row['org_id'] === null ? null : (int)$row['org_id'];
        if ($public && $row['kind'] === 'courses') {
            foreach ($row['payload']['questions'] ?? [] as $index => $question) unset($row['payload']['questions'][$index]['answer']);
        }
        return $row;
    }

    public function get($id, $kind = null, $user = null, $lock = false)
    {
        $row = $this->db->one('SELECT * FROM resources WHERE id=?' . ($lock ? $this->db->lock() : ''), [Validation::id($id)]);
        if (!$row || ($kind && $row['kind'] !== $kind)) throw new ApiError('Resource not found.', 404, 404);
        $privileged = $user && ($user['role'] === 'platform' || (int)$user['id'] === (int)$row['owner_id'] || ($user['org_id'] && (int)$user['org_id'] === (int)$row['org_id']));
        if (!$privileged && $user && $user['role'] === 'merchant' && $row['kind'] === 'events') $privileged = (bool)$this->db->one('SELECT ep.event_id FROM event_participants ep JOIN merchants m ON m.credit_code=ep.merchant_credit_code WHERE ep.event_id=? AND m.organization_id=?', [(int)$row['id'], (int)$user['org_id']]);
        if ($row['status'] !== 'published' && !$privileged) throw new ApiError('Resource not found.', 404, 404);
        $result = $this->aggregate($row, !$user || $user['role'] !== 'platform', $lock);
        if ($row['kind'] === 'posts') $result['payload']['liked'] = $user && (bool)$this->db->one('SELECT post_id FROM post_likes WHERE post_id=? AND user_id=?', [(int)$row['id'], (int)$user['id']]);
        return $result;
    }

    public function listing($kind, array $query, $user = null)
    {
        Validation::choice($kind, self::$kinds, 'kind');
        $where = 'kind=?'; $params = [$kind];
        if (!empty($query['mine'])) {
            if (!$user) throw new ApiError('Authentication required.', 401, 401);
            if ($user['role'] !== 'platform') {
                $where .= ' AND (owner_id=? OR (org_id=? AND org_id>0) OR id IN (SELECT ep.event_id FROM event_participants ep JOIN merchants m ON m.credit_code=ep.merchant_credit_code WHERE m.organization_id=?))'; $params[] = (int)$user['id']; $params[] = (int)$user['org_id']; $params[] = (int)$user['org_id'];
            }
        } else { $where .= ' AND status=?'; $params[] = 'published'; }
        if (!empty($query['search'])) { $where .= ' AND title LIKE ?'; $params[] = '%' . Validation::text($query['search'], 'search', 100) . '%'; }
        if ($kind === 'banners' && !empty($query['placement'])) { $where .= ' AND id IN (SELECT resource_id FROM banners WHERE placement=?)'; $params[] = Validation::choice($query['placement'], ['home', 'shop', 'learning', 'community'], 'placement'); }
        $order = $kind === 'posts' ? 'COALESCE((SELECT is_pinned FROM post_metadata WHERE post_id=resources.id),0) DESC,id DESC' : ($kind === 'banners' ? '(SELECT sort_order FROM banners WHERE resource_id=resources.id) ASC,id DESC' : 'id DESC');
        $list = $this->db->paginate('resources', $where, $params, $query, $order);
        $public = !$user || $user['role'] !== 'platform';
        $list['items'] = array_map(function ($row) use ($public, $user) {
            $item = $this->aggregate($row, $public);
            if ($row['kind'] === 'posts') $item['payload']['liked'] = $user && (bool)$this->db->one('SELECT post_id FROM post_likes WHERE post_id=? AND user_id=?', [(int)$row['id'], (int)$user['id']]);
            return $item;
        }, $list['items']);
        return $list;
    }

    public function save($kind, array $body, array $user, $id = null)
    {
        Validation::choice($kind, self::$kinds, 'kind');
        if ($kind !== 'posts') Policy::roles($user, in_array($kind, ['products', 'courses', 'coupons', 'growth_products', 'banners'], true) ? ['platform'] : ($kind === 'events' ? ['merchant', 'platform'] : ['merchant', 'agency', 'platform']));
        return $this->db->transaction(function () use ($kind, $body, $user, $id) {
            $old = $id ? $this->get($id, $kind, $user, true) : null;
            if ($old && $user['role'] !== 'platform' && (int)$old['owner_id'] !== (int)$user['id'] && (!(int)$user['org_id'] || (int)$old['org_id'] !== (int)$user['org_id'])) throw new ApiError('Resource operation is not permitted.', 403, 403);
            if ($old && (int)($body['version'] ?? 0) !== $old['version']) throw new ApiError('This resource changed. Reload before editing.', 409, 409);
            $title = Validation::text($body['title'] ?? ($old ? $old['title'] : ''), 'title', 200);
            $price = Validation::money($body['price_cents'] ?? ($old ? $old['price_cents'] : 0), 'price_cents');
            if ($kind === 'courses') $price = 0;
            $payload = $body['payload'] ?? ($old ? $old['payload'] : []);
            if (!is_array($payload) || strlen(json_encode($payload)) > 100000) throw new ApiError('Invalid resource payload.');
            if ($kind === 'posts' && $user['role'] !== 'platform' && (($payload['post_type'] ?? 'forum') !== 'forum' || !empty($payload['is_pinned']))) throw new ApiError('Official news and pinning require platform management.', 403, 403);
            $this->validatePayload($kind, $payload);
            $status = $user['role'] === 'platform' ? ($body['status'] ?? ($old ? $old['status'] : 'published')) : 'pending';
            if ($kind === 'events' && !empty($payload['joint_merchant_codes'])) {
                if ($user['role'] !== 'platform') throw new ApiError('Only the platform can organize joint merchant events.', 403, 403);
                $status = 'pending';
            }
            Validation::choice($status, ['draft', 'pending', 'published', 'rejected', 'archived'], 'status');
            $merchant = null;
            if ($user['role'] === 'merchant') {
                $merchant = $this->db->one('SELECT credit_code FROM merchants WHERE organization_id=? AND status=?', [(int)$user['org_id'], 'approved']);
                if (!$merchant) throw new ApiError('Approved business license is required.', 403, 40320);
            }
            $metadata = $payload;
            foreach (['stock', 'capacity', 'daily_price_cents', 'deposit_cents', 'boat_type_id', 'serial_number', 'location', 'latitude', 'longitude', 'unavailable', 'category', 'length_m', 'parameters', 'lessons', 'questions', 'pass_score', 'starts_at', 'discount_cents', 'min_amount_cents', 'expires_at', 'order_types', 'options', 'joint_merchant_codes', 'participant_reviews', 'rewards', 'reward_coupon_ids', 'points_price', 'placement', 'sort_order', 'target_url', 'post_type', 'is_pinned', 'liked', 'like_count'] as $field) unset($metadata[$field]);
            $values = ['title' => $title, 'status' => $status, 'payload' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'updated_at' => time()];
            if ($old) { $values['version'] = $old['version'] + 1; $this->db->update('resources', $old['id'], $values); $id = $old['id']; }
            else {
                $values += ['kind' => $kind, 'org_id' => $kind === 'products' ? null : ($user['org_id'] ?: null), 'merchant_credit_code' => $merchant ? $merchant['credit_code'] : null, 'owner_id' => (int)$user['id'], 'version' => 1, 'created_at' => time()];
                $id = $this->db->insert('resources', $values);
            }
            $credit = $old ? $old['merchant_credit_code'] : ($merchant ? $merchant['credit_code'] : null);
            $this->saveDomain($id, $kind, $price, $payload, $credit);
            Policy::audit($this->db, $user, $old ? 'resource.update' : 'resource.create', $kind, $id, ['status' => $status]);
            return $this->get($id, $kind, $user);
        });
    }

    private function validatePayload($kind, array $payload)
    {
        foreach (['daily_price_cents', 'deposit_cents', 'discount_cents', 'min_amount_cents'] as $key) if (isset($payload[$key])) Validation::money($payload[$key], $key);
        foreach (['stock', 'capacity'] as $key) if (isset($payload[$key])) Validation::integer($payload[$key], $key, 0, 1000000);
        foreach (['before_parameters', 'after_parameters', 'parameters'] as $key) if (isset($payload[$key]) && !is_array($payload[$key])) throw new ApiError($key . ' must be an object.');
        foreach (['vr_url', 'video_url'] as $key) if (!empty($payload[$key]) && !preg_match('#^https?://#i', $payload[$key])) throw new ApiError($key . ' must be an HTTP URL.');
        foreach ($payload['image_urls'] ?? [] as $url) if (!is_string($url) || (!preg_match('#^https?://#i', $url) && strpos($url, '/api/v1/files/') !== 0)) throw new ApiError('Invalid image URL.');
        if ($kind === 'boats') {
            $type = $this->get(Validation::id($payload['boat_type_id'] ?? 0, 'boat_type_id'), 'boat_types');
            if (empty($payload['serial_number'])) throw new ApiError('Boat serial_number is required.');
            $latitude = $payload['latitude'] ?? null; $longitude = $payload['longitude'] ?? null;
            if (($latitude === null) !== ($longitude === null)) throw new ApiError('Latitude and longitude must be provided or cleared together.');
            if ($latitude !== null && ((!is_int($latitude) && !is_float($latitude)) || (!is_int($longitude) && !is_float($longitude)) || !is_finite((float)$latitude) || !is_finite((float)$longitude) || $latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180)) throw new ApiError('Boat coordinates are outside valid geographic bounds.');
        }
        if ($kind === 'courses') {
            if (empty($payload['lessons']) || !is_array($payload['lessons'])) throw new ApiError('At least one course lesson is required.');
            $ids = [];
            foreach ($payload['lessons'] as $lesson) {
                $id = Validation::text((string)($lesson['id'] ?? ''), 'lesson id', 100);
                if (isset($ids[$id])) throw new ApiError('Lesson IDs must be unique.');
                $ids[$id] = true;
                Validation::text($lesson['title'] ?? '', 'lesson title', 200);
                Validation::integer($lesson['duration_seconds'] ?? 0, 'duration_seconds', 1, 86400);
            }
            foreach ($payload['questions'] ?? [] as $question) {
                if (!isset($question['answer'], $question['options']) || !is_array($question['options']) || count($question['options']) < 2) throw new ApiError('Invalid course question.');
                Validation::integer($question['answer'], 'answer', 0, count($question['options']) - 1);
            }
        }
    }

    private function aggregate(array $row, $public, $lock = false)
    {
        $id = (int)$row['id']; $payload = json_decode($row['payload'], true) ?: []; $price = 0;
        $mapping = ['boat_types' => 'boat_types', 'boats' => 'boats', 'products' => 'products', 'upgrades' => 'upgrade_schemes', 'maintenance' => 'maintenance_plans', 'events' => 'events', 'courses' => 'courses', 'coupons' => 'coupons', 'growth_products' => 'growth_products', 'banners' => 'banners'];
        if (isset($mapping[$row['kind']])) {
            // The typed stock/price row must be a current read after waiting for the resource lock.
            $domain = $this->db->one('SELECT * FROM ' . $mapping[$row['kind']] . ' WHERE resource_id=?' . ($lock ? $this->db->lock() : ''), [$id]);
            if ($domain) {
                $price = (int)($domain['price_cents'] ?? ($domain['daily_price_cents'] ?? 0));
                unset($domain['resource_id'], $domain['merchant_credit_code'], $domain['price_cents']);
                foreach (['capacity', 'daily_price_cents', 'deposit_cents', 'boat_type_id', 'unavailable', 'stock', 'starts_at', 'discount_cents', 'min_amount_cents', 'expires_at', 'pass_score', 'points_price', 'sort_order'] as $key) if (isset($domain[$key])) $domain[$key] = (int)$domain[$key];
                if (isset($domain['starts_at'])) $domain['starts_at'] = date('Y-m-d H:i:s', $domain['starts_at']);
                if (isset($domain['expires_at'])) $domain['expires_at'] = date('Y-m-d H:i:s', $domain['expires_at']);
                if (isset($domain['specs_json'])) { $domain['parameters'] = json_decode($domain['specs_json'], true) ?: []; unset($domain['specs_json']); }
                if (isset($domain['order_types_json'])) { $domain['order_types'] = json_decode($domain['order_types_json'], true) ?: []; unset($domain['order_types_json']); }
                $payload = array_merge($payload, $domain);
            }
        }
        if ($row['kind'] === 'courses') {
            $payload['lessons'] = array_map(function ($lesson) { return ['id' => $lesson['lesson_key'], 'title' => $lesson['title'], 'video_url' => $lesson['video_url'], 'duration_seconds' => (int)$lesson['duration_seconds']]; }, $this->db->all('SELECT * FROM course_lessons WHERE course_id=? ORDER BY sort_order', [$id]));
            $payload['questions'] = array_map(function ($q) { return ['question' => $q['question'], 'options' => json_decode($q['options_json'], true), 'answer' => (int)$q['answer_index']]; }, $this->db->all('SELECT * FROM course_questions WHERE course_id=? ORDER BY question_index', [$id]));
            $payload['rewards'] = $this->db->all('SELECT reward_key AS id,reward_type AS type,title,points,coupon_id,event_id FROM course_reward_definitions WHERE course_id=? AND active=1 ORDER BY id', [$id]);
        }
        if ($row['kind'] === 'boats') {
            $location = $this->db->one('SELECT latitude,longitude FROM boat_locations WHERE boat_id=?' . ($lock ? $this->db->lock() : ''),[$id]);
            $payload['latitude'] = $location ? (float)$location['latitude'] : null;
            $payload['longitude'] = $location ? (float)$location['longitude'] : null;
        }
        $payload['options'] = array_map(function ($option) { return ['id' => $option['option_key'], 'title' => $option['title'], 'price_cents' => (int)$option['price_cents']]; }, $this->db->all('SELECT * FROM resource_options WHERE resource_id=? ORDER BY id' . ($lock ? $this->db->lock() : ''), [$id]));
        if ($row['kind'] === 'events') {
            $participants = $this->db->all('SELECT merchant_credit_code,status FROM event_participants WHERE event_id=?', [$id]);
            $payload['joint_merchant_codes'] = array_column($participants, 'merchant_credit_code'); $payload['participant_reviews'] = $participants;
        }
        if ($row['kind'] === 'posts') {
            $meta = $this->db->one('SELECT post_type,is_pinned FROM post_metadata WHERE post_id=?', [$id]) ?: ['post_type' => 'forum', 'is_pinned' => 0];
            $payload = array_merge($payload, $meta); $payload['is_pinned'] = (bool)$meta['is_pinned'];
            $payload['like_count'] = (int)$this->db->scalar('SELECT COUNT(*) FROM post_likes WHERE post_id=?', [$id]);
        }
        $row['price_cents'] = $price; $row['payload'] = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return self::decode($row, $public);
    }

    private function domain($table, $id, array $data)
    {
        $exists = $this->db->one('SELECT resource_id FROM ' . $table . ' WHERE resource_id=?', [$id]);
        if (!$exists) { $this->db->insert($table, ['resource_id' => $id] + $data); return; }
        $sets = []; foreach (array_keys($data) as $key) $sets[] = $key . '=?';
        $params = array_values($data); $params[] = $id;
        $this->db->run('UPDATE ' . $table . ' SET ' . implode(',', $sets) . ' WHERE resource_id=?', $params);
    }

    private function saveDomain($id, $kind, $price, array $payload, $credit)
    {
        switch ($kind) {
            case 'boat_types':
                $this->domain('boat_types', $id, ['merchant_credit_code' => $credit, 'category' => Validation::text($payload['category'] ?? 'yacht', 'category', 60), 'length_m' => isset($payload['length_m']) ? (string)$payload['length_m'] : null,
                    'capacity' => (int)($payload['capacity'] ?? 1), 'daily_price_cents' => (int)($payload['daily_price_cents'] ?? $price), 'deposit_cents' => (int)($payload['deposit_cents'] ?? 0), 'specs_json' => json_encode($payload['parameters'] ?? [])]);
                break;
            case 'boats':
                $this->domain('boats', $id, ['boat_type_id' => (int)$payload['boat_type_id'], 'merchant_credit_code' => $credit, 'serial_number' => Validation::text($payload['serial_number'], 'serial_number', 100),
                    'price_cents' => $price, 'location' => Validation::text($payload['location'] ?? '', 'location', 200, false), 'unavailable' => !empty($payload['unavailable']) ? 1 : 0]);
                $this->db->run('DELETE FROM boat_locations WHERE boat_id=?',[$id]);
                if (isset($payload['latitude'],$payload['longitude'])) $this->db->insert('boat_locations',['boat_id'=>$id,'latitude'=>$payload['latitude'],'longitude'=>$payload['longitude']]);
                break;
            case 'upgrades': case 'maintenance':
                $boatTypeId = empty($payload['boat_type_id']) ? null : Validation::id($payload['boat_type_id'], 'boat_type_id');
                if ($boatTypeId) $this->get($boatTypeId, 'boat_types');
                $this->domain($kind === 'upgrades' ? 'upgrade_schemes' : 'maintenance_plans', $id, ['boat_type_id' => $boatTypeId, 'merchant_credit_code' => $credit, 'price_cents' => $price]);
                break;
            case 'products': $this->domain('products', $id, ['price_cents' => $price, 'stock' => (int)($payload['stock'] ?? 0)]); break;
            case 'growth_products': $this->domain('growth_products', $id, ['points_price' => Validation::integer($payload['points_price'] ?? 0, 'points_price', 1, 1000000), 'stock' => (int)($payload['stock'] ?? 0)]); break;
            case 'banners':
                $target = Validation::text($payload['target_url'] ?? '', 'target_url', 500);
                if (!preg_match('#^https?://#i', $target) && !preg_match('#^/[^/]#', $target)) throw new ApiError('Banner target must be HTTP or a local application path.');
                $this->domain('banners', $id, ['placement' => Validation::choice($payload['placement'] ?? 'home', ['home', 'shop', 'learning', 'community'], 'placement'), 'target_url' => $target, 'sort_order' => Validation::integer($payload['sort_order'] ?? 0, 'sort_order', 0, 10000)]);
                break;
            case 'posts':
                $meta = ['post_type' => Validation::choice($payload['post_type'] ?? 'forum', ['forum', 'news'], 'post_type'), 'is_pinned' => !empty($payload['is_pinned']) ? 1 : 0];
                if ($this->db->one('SELECT post_id FROM post_metadata WHERE post_id=?', [$id])) $this->db->run('UPDATE post_metadata SET post_type=?,is_pinned=? WHERE post_id=?', [$meta['post_type'], $meta['is_pinned'], $id]);
                else $this->db->insert('post_metadata', ['post_id' => $id] + $meta);
                break;
            case 'events':
                $start = strtotime($payload['starts_at'] ?? '');
                if (!$start) throw new ApiError('Event starts_at is required.');
                $this->domain('events', $id, ['price_cents' => $price, 'capacity' => (int)($payload['capacity'] ?? 0), 'starts_at' => $start, 'location' => Validation::text($payload['location'] ?? '', 'location', 200, false)]);
                $codes = $payload['joint_merchant_codes'] ?? [];
                if (!is_array($codes) || count($codes) > 30) throw new ApiError('Invalid joint-event merchant list.');
                $this->db->run('DELETE FROM event_participants WHERE event_id=?', [$id]);
                foreach (array_unique($codes) as $code) {
                    if (!$this->db->one('SELECT credit_code FROM merchants WHERE credit_code=? AND status=?', [$code, 'approved'])) throw new ApiError('Joint-event merchant is not approved.');
                    $this->db->insert('event_participants', ['event_id' => $id, 'merchant_credit_code' => $code, 'status' => 'pending', 'reviewer_id' => null, 'reviewed_at' => null, 'notes' => '', 'created_at' => time()]);
                }
                break;
            case 'coupons':
                $expires = strtotime($payload['expires_at'] ?? '');
                if (!$expires) throw new ApiError('Coupon expires_at is required.');
                foreach ($payload['order_types'] ?? ['mall'] as $type) Validation::choice($type, Orders::$types, 'order_type');
                $this->domain('coupons', $id, ['discount_cents' => (int)($payload['discount_cents'] ?? 0), 'min_amount_cents' => (int)($payload['min_amount_cents'] ?? 0), 'expires_at' => $expires,
                    'stock' => (int)($payload['stock'] ?? 0), 'order_types_json' => json_encode($payload['order_types'] ?? ['mall'])]);
                break;
            case 'courses':
                $this->domain('courses', $id, ['pass_score' => Validation::integer($payload['pass_score'] ?? 60, 'pass_score', 0, 100)]);
                $old = $this->db->all('SELECT * FROM course_lessons WHERE course_id=?', [$id]);
                $keys = array_map(function ($lesson) { return (string)$lesson['id']; }, $payload['lessons']);
                foreach ($old as $lesson) if (!in_array($lesson['lesson_key'], $keys, true)) {
                    if ($this->db->one('SELECT id FROM learning_progress WHERE course_id=? AND lesson_id=?', [$id, $lesson['lesson_key']])) throw new ApiError('A lesson with learning progress cannot be removed.', 409, 409);
                    $this->db->run('DELETE FROM course_lessons WHERE id=?', [(int)$lesson['id']]);
                }
                foreach ($payload['lessons'] as $index => $lesson) {
                    $previous = $this->db->one('SELECT id FROM course_lessons WHERE course_id=? AND lesson_key=?', [$id, (string)$lesson['id']]);
                    $values = ['title' => $lesson['title'], 'video_url' => (string)($lesson['video_url'] ?? ''), 'duration_seconds' => (int)$lesson['duration_seconds'], 'sort_order' => $index];
                    if ($previous) $this->db->update('course_lessons', $previous['id'], $values);
                    else $this->db->insert('course_lessons', $values + ['course_id' => $id, 'lesson_key' => (string)$lesson['id']]);
                }
                $this->db->run('DELETE FROM course_questions WHERE course_id=?', [$id]);
                foreach ($payload['questions'] ?? [] as $index => $q) $this->db->insert('course_questions', ['course_id' => $id, 'question_index' => $index, 'question' => Validation::text($q['question'] ?? '', 'question', 2000),
                    'options_json' => json_encode($q['options'], JSON_UNESCAPED_UNICODE), 'answer_index' => (int)$q['answer']]);
                (new Community($this->db))->saveRewards($id, $payload['rewards'] ?? []);
                break;
        }
        $this->db->run('DELETE FROM resource_options WHERE resource_id=?', [$id]);
        if (!is_array($payload['options'] ?? [])) throw new ApiError('Invalid options.');
        foreach ($payload['options'] ?? [] as $option) {
            $this->db->insert('resource_options', ['resource_id' => $id, 'option_key' => Validation::text((string)($option['id'] ?? ''), 'option id', 100),
                'title' => Validation::text($option['title'] ?? '', 'option title', 200), 'price_cents' => Validation::money($option['price_cents'] ?? 0, 'option price_cents')]);
        }
    }
}
