<?php
namespace yuzhou;

class Orders
{
    private $db;
    private $catalog;
    private $finance;
    public static $types = ['boat_purchase', 'boat_rental', 'boat_upgrade', 'maintenance', 'mall', 'event'];
    public function __construct(Database $db)
    {
        $this->db = $db; $this->catalog = new Catalog($db); $this->finance = new Finance($db);
    }

    public function get($id, array $user, $lock = false)
    {
        $order = $this->db->one('SELECT * FROM orders WHERE id=?' . ($lock ? $this->db->lock() : ''), [Validation::id($id)]);
        if (!$order || !Policy::orderVisible($user, $order)) throw new ApiError('Order not found.', 404, 404);
        return $this->decode($order);
    }

    public function decode(array $row)
    {
        foreach (['id', 'customer_id', 'creator_id', 'resource_id', 'amount_cents', 'original_amount_cents', 'discount_cents', 'paid_cents', 'refund_cents', 'quote_expires_at', 'created_at', 'updated_at'] as $key) $row[$key] = (int)$row[$key];
        $row['org_id'] = $row['org_id'] === null ? null : (int)$row['org_id'];
        foreach (['broker_id', 'profit_cents', 'coupon_claim_id', 'contract_version', 'completed_at'] as $key) $row[$key] = $row[$key] === null ? null : (int)$row[$key];
        $row['profit_revision'] = (int)$row['profit_revision'];
        $row['items'] = json_decode($row['items'], true) ?: [];
        $row['rule_snapshot'] = $row['rule_snapshot'] ? json_decode($row['rule_snapshot'], true) : null;
        return $row;
    }

    public function listing(array $user, array $query)
    {
        list($where, $params) = Policy::orderScope($user);
        if (!empty($query['type'])) { $where .= ' AND type=?'; $params[] = Validation::choice($query['type'], self::$types, 'type'); }
        if (!empty($query['status'])) { $where .= ' AND status=?'; $params[] = Validation::text($query['status'], 'status', 40); }
        if (!empty($query['search'])) { $where .= ' AND order_no LIKE ?'; $params[] = '%' . Validation::text($query['search'], 'search', 100) . '%'; }
        $list = $this->db->paginate('orders', $where, $params, $query);
        $list['items'] = array_map([$this, 'decode'], $list['items']);
        return $list;
    }

    public function inventory(array $query)
    {
        $typeId = Validation::id($query['boat_type_id'] ?? 0, 'boat_type_id');
        $type = $this->catalog->get($typeId, 'boat_types');
        $dates = Validation::dates($query['start_date'] ?? '', $query['end_date'] ?? '');
        $this->expireReservations();
        $boats = $this->availableBoats($typeId, $dates);
        return ['boat_type_id' => $typeId, 'start_date' => $dates[0], 'end_date' => $query['end_date'], 'days' => count($dates),
            'available_count' => count($boats), 'boat_ids' => array_column($boats, 'id'), 'daily_price_cents' => (int)($type['payload']['daily_price_cents'] ?? $type['price_cents'])];
    }

    public function expireReservations()
    {
        return $this->db->transaction(function () {
            $expired = $this->db->all('SELECT * FROM orders WHERE type IN (?,?) AND status=? AND updated_at<?' . $this->db->lock(), ['boat_rental', 'event', 'awaiting_payment', time() - 1800]);
            foreach ($expired as $order) {
                $this->db->run('DELETE FROM rental_reservations WHERE order_id=?', [(int)$order['id']]);
                $this->db->update('orders', $order['id'], ['status' => 'cancelled', 'updated_at' => time()]);
                if ($order['coupon_claim_id']) $this->db->update('coupon_claims', $order['coupon_claim_id'], ['status' => 'available', 'order_id' => null]);
                if ($order['type'] === 'event') $this->db->run('UPDATE enrollments SET status=? WHERE order_id=?', ['cancelled', (int)$order['id']]);
            }
            return count($expired);
        });
    }

    private function availableBoats($typeId, array $dates)
    {
        $rows = $this->db->all('SELECT r.*,b.price_cents,b.boat_type_id,b.unavailable FROM resources r JOIN boats b ON b.resource_id=r.id WHERE r.kind=? AND r.status=? AND b.boat_type_id=? ORDER BY r.id' . $this->db->lock(), ['boats', 'published', $typeId]);
        $available = [];
        foreach ($rows as $row) {
            $boat = Catalog::decode($row);
            if (!empty($row['unavailable'])) continue;
            if ($this->db->one('SELECT id FROM orders WHERE resource_id=? AND type=? AND paid_cents>refund_cents' . $this->db->lock(), [$boat['id'], 'boat_purchase'])) continue;
            $busy = $this->db->one('SELECT order_id FROM rental_reservations WHERE boat_id=? AND rental_date>=? AND rental_date<=? LIMIT 1' . $this->db->lock(), [$boat['id'], $dates[0], end($dates)]);
            if (!$busy) $available[] = $boat;
        }
        return $available;
    }

    public function create(array $body, array $user)
    {
        Policy::roles($user, ['consumer', 'broker', 'agency', 'platform']);
        $type = Validation::choice($body['type'] ?? '', self::$types, 'type');
        return $this->db->transaction(function () use ($body, $user, $type) {
            $customerId = $user['role'] === 'consumer' ? (int)$user['id'] : Validation::id($body['customer_id'] ?? 0, 'customer_id');
            $customer = $this->db->one('SELECT * FROM users WHERE id=?', [$customerId]);
            if (!$customer || $customer['status'] !== 'active' || $customer['role'] !== 'consumer') throw new ApiError('Customer must be an active consumer account.');
            $brokerId = null;
            if ($user['role'] === 'broker') $brokerId = (int)$user['id'];
            elseif (in_array($user['role'], ['agency', 'platform'], true) && !empty($body['broker_id'])) $brokerId = Validation::id($body['broker_id'], 'broker_id');
            if ($user['role'] === 'agency' && !$brokerId) throw new ApiError('An assigned broker is required.');
            $broker = null;
            if ($brokerId) {
                $broker = $this->db->one('SELECT * FROM users WHERE id=?', [$brokerId]);
                if (!$broker || $broker['role'] !== 'broker' || $broker['status'] !== 'active' || !$broker['org_id']) throw new ApiError('Broker is not approved.');
                if ($user['role'] === 'agency' && (int)$broker['org_id'] !== (int)$user['org_id']) throw new ApiError('Broker belongs to another agency.', 403, 403);
                if ($user['role'] !== 'platform' && !$this->db->one('SELECT id FROM customers WHERE user_id=? AND broker_id=?', [$customerId, $brokerId])) throw new ApiError('Customer is not assigned to this broker.', 403, 403);
            }
            if ($type === 'mall' && $brokerId) throw new ApiError('Mall orders are placed by consumers and fulfilled by the platform.');
            $kind = ['boat_purchase' => 'boats', 'boat_rental' => 'boat_types', 'boat_upgrade' => 'upgrades', 'maintenance' => 'maintenance', 'mall' => 'products', 'event' => 'events'][$type];
            $checkout = $type === 'mall' ? ($body['items'] ?? [['resource_id' => $body['resource_id'] ?? 0, 'quantity' => $body['quantity'] ?? 1]]) : [];
            if ($type === 'mall') {
                if (!is_array($checkout) || count($checkout) < 1 || count($checkout) > 30) throw new ApiError('Checkout requires 1 to 30 products.');
                usort($checkout, function ($a, $b) { return (int)($a['resource_id'] ?? 0) <=> (int)($b['resource_id'] ?? 0); });
                $body['resource_id'] = $checkout[0]['resource_id']; $body['quantity'] = $checkout[0]['quantity'] ?? 1;
            }
            $resource = $this->catalog->get(Validation::id($body['resource_id'] ?? 0, 'resource_id'), $kind, null, true);
            $quantity = Validation::integer($body['quantity'] ?? 1, 'quantity', 1, 50);
            $amount = $resource['price_cents'] * $quantity;
            $item = ['resource_id' => $resource['id'], 'title' => $resource['title'], 'quantity' => $quantity, 'unit_price_cents' => $resource['price_cents']];
            $items = [$item];
            if (isset($body['option_ids'])) {
                $options = $this->selectedOptions($resource['id'], $body['option_ids']);
                $item['options'] = $options; $item['unit_price_cents'] += array_sum(array_column($options, 'price_cents'));
                $amount = $item['unit_price_cents'] * $quantity; $items = [$item];
            }
            $boats = []; $dates = [];
            if ($type === 'boat_rental') {
                $dates = Validation::dates($body['start_date'] ?? '', $body['end_date'] ?? '');
                if ($dates[0] < date('Y-m-d')) throw new ApiError('Rental cannot begin in the past.');
                $this->expireReservations();
                $boats = $this->availableBoats($resource['id'], $dates);
                if (count($boats) < $quantity) throw new ApiError('Not enough boats are available for these dates.', 409, 40920);
                $boats = array_slice($boats, 0, $quantity);
                $unit = (int)($resource['payload']['daily_price_cents'] ?? $resource['price_cents']);
                $amount = $unit * count($dates) * $quantity;
                $item += ['boat_ids' => array_column($boats, 'id'), 'start_date' => $body['start_date'], 'end_date' => $body['end_date'], 'days' => count($dates), 'daily_price_cents' => $unit];
                $items = [$item];
            }
            if ($type === 'boat_purchase') {
                if ($quantity !== 1) throw new ApiError('A specific boat can only be bought once.');
                $this->expireReservations();
                if (!empty($resource['payload']['unavailable']) || $this->db->one('SELECT id FROM rental_reservations WHERE boat_id=? AND rental_date>=?' . $this->db->lock(), [$resource['id'], date('Y-m-d')])) throw new ApiError('This boat is unavailable or has a current/future rental.', 409, 40921);
                if ($this->db->one('SELECT id FROM orders WHERE resource_id=? AND type=? AND (status NOT IN (?,?) OR paid_cents>refund_cents)' . $this->db->lock(), [$resource['id'], 'boat_purchase', 'cancelled', 'refunded'])) throw new ApiError('This boat already has an active purchase order.', 409, 40921);
            }
            if ($type === 'mall') {
                $items = []; $amount = 0; $seen = [];
                foreach ($checkout as $line) {
                    $product = $this->catalog->get(Validation::id($line['resource_id'] ?? 0, 'resource_id'), 'products', null, true);
                    if (isset($seen[$product['id']])) throw new ApiError('Duplicate checkout product.'); $seen[$product['id']] = true;
                    $count = Validation::integer($line['quantity'] ?? 1, 'quantity', 1, 50);
                    if ((int)$product['payload']['stock'] < $count) throw new ApiError('Product stock is insufficient.', 409, 40922);
                    $this->db->run('UPDATE products SET stock=stock-? WHERE resource_id=?', [$count, $product['id']]);
                    $this->db->run('UPDATE resources SET version=version+1,updated_at=? WHERE id=?', [time(), $product['id']]);
                    $items[] = ['resource_id' => $product['id'], 'title' => $product['title'], 'quantity' => $count, 'unit_price_cents' => $product['price_cents']];
                    $amount += $product['price_cents'] * $count;
                }
            }
            Validation::money($amount);
            $claim = null; $discount = 0;
            if (!empty($body['coupon_id'])) {
                $claim = $this->db->one('SELECT * FROM coupon_claims WHERE id=? AND user_id=? AND status=?' . $this->db->lock(), [Validation::id($body['coupon_id'], 'coupon_id'), $customerId, 'available']);
                if (!$claim) throw new ApiError('Coupon is unavailable.');
                $discount = $this->couponDiscount($claim, $amount, $type);
            }
            $needsQuote = $brokerId || in_array($type, ['boat_purchase', 'boat_upgrade', 'maintenance'], true);
            $now = time();
            $id = $this->db->insert('orders', ['order_no' => 'YZ' . date('YmdHis') . strtoupper(bin2hex(random_bytes(4))), 'type' => $type, 'customer_id' => $customerId,
                'creator_id' => (int)$user['id'], 'broker_id' => $brokerId, 'org_id' => $broker ? (int)$broker['org_id'] : ($resource['org_id'] ?: null), 'merchant_credit_code' => $resource['merchant_credit_code'],
                'resource_id' => $resource['id'], 'status' => $needsQuote ? 'awaiting_quote' : 'awaiting_payment', 'amount_cents' => $amount - $discount,
                'original_amount_cents' => $amount, 'discount_cents' => $discount, 'paid_cents' => 0, 'refund_cents' => 0, 'profit_cents' => null,
                'profit_status' => 'unconfirmed', 'coupon_claim_id' => $claim ? (int)$claim['id'] : null, 'quote_expires_at' => 0,
                'items' => json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'notes' => Validation::text($body['notes'] ?? '', 'notes', 2000, false),
                'rule_snapshot' => null, 'contract_version' => null, 'created_at' => $now, 'updated_at' => $now, 'completed_at' => null]);
            if (isset($body['scheduled_at'])) $this->schedule($this->get($id, $user), ['scheduled_at' => $body['scheduled_at'], 'location' => $body['service_location'] ?? ''], $user);
            foreach ($boats as $boat) {
                $this->db->one('SELECT id FROM resources WHERE id=?' . $this->db->lock(), [$boat['id']]);
                foreach ($dates as $date) $this->db->insert('rental_reservations', ['boat_id' => $boat['id'], 'rental_date' => $date, 'order_id' => $id, 'created_at' => $now]);
            }
            foreach ($items as $line) $this->db->insert('order_items', ['order_id' => $id, 'resource_id' => $line['resource_id'], 'quantity' => $line['quantity'], 'unit_price_cents' => $line['unit_price_cents'],
                'amount_cents' => $type === 'boat_rental' ? $amount : $line['unit_price_cents'] * $line['quantity'], 'start_date' => $line['start_date'] ?? null, 'end_date' => $line['end_date'] ?? null]);
            if ($type === 'event') (new Community($this->db))->reserveEnrollment($resource['id'], $customerId, $id);
            if ($type === 'mall') foreach ($items as $line) $this->db->run('DELETE FROM cart_items WHERE user_id=? AND product_id=?', [$customerId, $line['resource_id']]);
            if ($claim) $this->db->update('coupon_claims', $claim['id'], ['status' => 'reserved', 'order_id' => $id]);
            $this->event($id, $user, 'created', ['type' => $type]);
            return $this->get($id, $user);
        });
    }

    private function couponDiscount(array $claim, $amount, $type)
    {
        $coupon = $this->catalog->get($claim['coupon_id'], 'coupons'); $data = $coupon['payload'];
        if (strtotime($data['expires_at'] ?? '') <= time() || $amount < (int)($data['min_amount_cents'] ?? 0) || !in_array($type, $data['order_types'] ?? ['mall'], true)) throw new ApiError('Coupon does not apply to this order.');
        return min($amount, (int)($data['discount_cents'] ?? 0));
    }

    private function selectedOptions($resourceId, $ids)
    {
        if (!is_array($ids) || count($ids) > 30) throw new ApiError('option_ids is invalid.');
        $selected = [];
        foreach (array_unique($ids) as $key) {
            $key = Validation::text((string)$key, 'option_id', 100);
            $option = $this->db->one('SELECT * FROM resource_options WHERE resource_id=? AND option_key=?' . $this->db->lock(), [$resourceId, $key]);
            if (!$option) throw new ApiError('Selected option is not configured for this resource.');
            $selected[] = ['id' => $key, 'title' => $option['title'], 'price_cents' => (int)$option['price_cents']];
        }
        return $selected;
    }

    private function event($id, array $user, $action, array $data = [])
    {
        $this->db->insert('order_events', ['order_id' => $id, 'actor_id' => (int)$user['id'], 'action' => $action, 'payload' => json_encode($data, JSON_UNESCAPED_UNICODE), 'created_at' => time()]);
        Policy::audit($this->db, $user, 'order.' . $action, 'order', $id, $data);
        $labels = ['quoted' => '报价已更新', 'contract_version_created' => '合同已上传，请查看当前版本', 'contract_reviewed' => '合同审核状态已更新', 'fulfilling' => '订单正在履约', 'completed' => '订单履约已完成', 'refund_succeeded' => '退款处理成功', 'progress' => '履约进度已更新', 'schedule' => '服务预约已更新'];
        if (isset($labels[$action])) {
            $customer = $this->db->scalar('SELECT customer_id FROM orders WHERE id=?', [$id]);
            if ((int)$customer !== (int)$user['id'] && class_exists('yuzhou\\Communication')) Communication::notify($this->db, (int)$customer, '订单状态更新', $labels[$action], 'order', $id);
        }
    }

    private function schedule(array $order, array $body, array $user)
    {
        if (!in_array($order['type'], ['boat_upgrade', 'maintenance'], true)) throw new ApiError('Only upgrade and maintenance orders support service appointments.');
        if (in_array($order['status'], ['cancelled', 'refunded', 'completed'], true)) throw new ApiError('This service appointment cannot be changed.', 409, 409);
        if ((int)$user['id'] !== $order['customer_id']) Policy::orderOperator($user, $order);
        $scheduled = strtotime(Validation::text($body['scheduled_at'] ?? '', 'scheduled_at', 40));
        if (!$scheduled || $scheduled <= time()) throw new ApiError('Service appointment must be in the future.');
        $values = ['scheduled_at' => $scheduled, 'location' => Validation::text($body['location'] ?? '', 'location', 300), 'updated_by' => (int)$user['id'], 'updated_at' => time()];
        if ($this->db->one('SELECT order_id FROM order_service_schedules WHERE order_id=?', [$order['id']])) $this->db->run('UPDATE order_service_schedules SET scheduled_at=?,location=?,updated_by=?,updated_at=? WHERE order_id=?', [$scheduled, $values['location'], $values['updated_by'], $values['updated_at'], $order['id']]);
        else $this->db->insert('order_service_schedules', ['order_id' => $order['id']] + $values);
        $this->event($order['id'], $user, 'schedule', $values);
        return $this->db->one('SELECT * FROM order_service_schedules WHERE order_id=?', [$order['id']]);
    }

    public function action($id, $action, array $body, array $user)
    {
        return $this->db->transaction(function () use ($id, $action, $body, $user) {
            if ($action === 'pay') {
                $candidate = $this->db->one('SELECT type,resource_id FROM orders WHERE id=?', [Validation::id($id)]);
                if ($candidate && $candidate['type'] === 'boat_purchase') {
                    $this->expireReservations();
                    // Physical boat lock precedes its order lock, matching rental allocation.
                    $this->db->one('SELECT id FROM resources WHERE id=?' . $this->db->lock(), [(int)$candidate['resource_id']]);
                }
            }
            $order = $this->get($id, $user, true);
            switch ($action) {
                case 'schedule': return $this->schedule($order, $body, $user);
                case 'progress':
                    Policy::orderOperator($user, $order);
                    if (!in_array($order['status'], ['paid', 'fulfilling', 'partially_refunded'], true)) throw new ApiError('Progress requires a paid order awaiting completion.', 409, 409);
                    $percent = Validation::integer($body['percent'] ?? null, 'percent', 0, 100);
                    $stage = Validation::choice($body['stage'] ?? '', ['planned', 'preparing', 'installing', 'testing', 'delivery', 'completed'], 'stage');
                    if ($percent < (int)$this->db->scalar('SELECT COALESCE(MAX(percent),0) FROM order_progress WHERE order_id=?', [$order['id']])) throw new ApiError('Progress cannot decrease.', 409, 409);
                    $progressId = $this->db->insert('order_progress', ['order_id' => $order['id'], 'actor_id' => (int)$user['id'], 'stage' => $stage, 'percent' => $percent, 'notes' => Validation::text($body['notes'] ?? '', 'progress notes', 2000, false), 'created_at' => time()]);
                    $this->event($order['id'], $user, 'progress', ['stage' => $stage, 'percent' => $percent]);
                    return $this->db->one('SELECT * FROM order_progress WHERE id=?', [$progressId]);
                case 'quote':
                    Policy::orderOperator($user, $order);
                    if (!in_array($order['status'], ['awaiting_quote', 'quoted', 'awaiting_payment'], true) || $order['paid_cents']) throw new ApiError('This order cannot be quoted.', 409, 409);
                    $amount = Validation::money($body['amount_cents'] ?? null);
                    $discount = $order['coupon_claim_id'] ? $this->couponDiscount($this->db->one('SELECT * FROM coupon_claims WHERE id=?', [$order['coupon_claim_id']]), $amount, $order['type']) : 0;
                    $until = empty($body['valid_until']) ? time() + 86400 * 3 : strtotime($body['valid_until']);
                    if ($until <= time() || $until > time() + 86400 * 30) throw new ApiError('Quote expiry is invalid.');
                    $this->db->update('orders', $id, ['amount_cents' => $amount - $discount, 'original_amount_cents' => $amount, 'discount_cents' => $discount, 'status' => 'quoted', 'quote_expires_at' => $until, 'contract_version' => null, 'updated_at' => time()]);
                    if (isset($body['option_ids'])) { $order['items'][0]['options'] = $this->selectedOptions($order['resource_id'], $body['option_ids']); $this->db->update('orders', $id, ['items' => json_encode($order['items'], JSON_UNESCAPED_UNICODE)]); }
                    $this->event($id, $user, 'quoted', ['amount_cents' => $amount - $discount, 'notes' => Validation::text($body['notes'] ?? '', 'notes', 2000, false)]);
                    break;
                case 'accept-quote':
                    if ((int)$user['id'] !== $order['customer_id']) throw new ApiError('Only the customer can accept a quote.', 403, 403);
                    if ($order['status'] !== 'quoted' || $order['quote_expires_at'] <= time()) throw new ApiError('Quote is not available or has expired.', 409, 409);
                    $this->db->update('orders', $id, ['status' => 'awaiting_payment', 'updated_at' => time()]); $this->event($id, $user, 'quote_accepted');
                    break;
                case 'pay': return $this->pay($order, $body, $user);
                case 'fulfill':
                    $status = Validation::choice($body['status'] ?? '', ['fulfilling', 'completed'], 'fulfillment status');
                    if ($status === 'completed' && (int)$user['id'] === $order['customer_id']) {
                        if ($order['status'] !== 'fulfilling' && !($order['status'] === 'partially_refunded' && $this->db->one('SELECT id FROM order_events WHERE order_id=? AND action=?', [$order['id'], 'fulfilling']))) throw new ApiError('Fulfillment has not begun.', 409, 409);
                    } else Policy::orderOperator($user, $order);
                    if ($order['refund_cents'] >= $order['paid_cents'] && $order['paid_cents'] > 0) throw new ApiError('A fully refunded order cannot be fulfilled.', 409, 409);
                    if ($status === 'fulfilling' && !in_array($order['status'], ['paid', 'partially_refunded'], true)) throw new ApiError('Order is not ready for fulfillment.', 409, 409);
                    if ($status === 'completed' && !in_array($order['status'], ['fulfilling', 'partially_refunded'], true)) throw new ApiError('Order is not ready for completion.', 409, 409);
                    $update = ['status' => $status, 'updated_at' => time()];
                    if ($status === 'completed') { $update['completed_at'] = time(); $this->finance->complete($this->raw($order)); }
                    $this->db->update('orders', $id, $update);
                    $this->event($id, $user, $status, ['notes' => Validation::text($body['notes'] ?? '', 'notes', 2000, false)]);
                    break;
                case 'cancel':
                    if ($order['paid_cents'] || (!in_array($order['status'], ['awaiting_quote', 'quoted', 'awaiting_payment'], true) && !($order['type']==='event' && !$order['amount_cents'] && $order['status']==='paid'))) throw new ApiError('Paid orders require a refund.', 409, 409);
                    $this->release($order, true);
                    $this->db->update('orders', $id, ['status' => 'cancelled', 'updated_at' => time()]);
                    $this->event($id, $user, 'cancelled', ['reason' => Validation::text($body['reason'] ?? '', 'reason', 2000, false)]);
                    break;
                case 'profit-confirm': return $this->finance->confirm($this->raw($order), $body, $user);
                case 'refund': return $this->refund($order, $body, $user);
                case 'refund-request':
                    if ((int)$user['id'] !== $order['customer_id']) throw new ApiError('Only the customer can request a refund.', 403, 403);
                    $requested = Validation::integer($body['amount_cents'] ?? null, 'amount_cents', 1, 100000000000);
                    if (!$order['paid_cents'] || $requested > $order['paid_cents'] - $order['refund_cents']) throw new ApiError('Refund request exceeds refundable amount.');
                    if ($this->db->one('SELECT id FROM refund_requests WHERE order_id=? AND status IN (?,?)', [$order['id'], 'pending', 'approved'])) throw new ApiError('A refund request is already awaiting processing.', 409, 409);
                    $requestId = $this->db->insert('refund_requests', ['order_id' => $order['id'], 'customer_id' => $order['customer_id'], 'amount_cents' => $requested,
                        'reason' => Validation::text($body['reason'] ?? '', 'reason', 2000), 'status' => 'pending', 'reviewed_by' => null, 'review_notes' => '', 'refund_id' => null, 'created_at' => time(), 'updated_at' => time()]);
                    $this->event($id, $user, 'refund_requested', ['request_id' => $requestId, 'amount_cents' => $requested]);
                    return $this->db->one('SELECT * FROM refund_requests WHERE id=?', [$requestId]);
                case 'contract': return $this->contract($order, $body, $user);
                case 'contract-review': return $this->reviewContract($order, $body, $user);
                case 'sign': return $this->sign($order, $body, $user);
                default: throw new ApiError('Order action not found.', 404, 404);
            }
            return $this->get($id, $user);
        });
    }

    private function raw(array $order)
    {
        $order['rule_snapshot'] = $order['rule_snapshot'] ? json_encode($order['rule_snapshot']) : null;
        return $order;
    }

    public static function requireTestPayment($channel)
    {
        if ($channel !== 'test') throw new ApiError('Real payment or refund service is not configured.', 503, 50310);
        if (!in_array(getenv('APP_ENV'), ['development', 'test'], true) || getenv('ALLOW_TEST_PAYMENTS') !== '1') throw new ApiError('Test payment is disabled in this environment.', 403, 40310);
    }

    private function pay(array $order, array $body, array $user)
    {
        if ((int)$user['id'] !== $order['customer_id']) throw new ApiError('Only the customer can pay.', 403, 403);
        $channel = $body['channel'] ?? '';
        if (!($order['amount_cents'] === 0 && $channel === 'free')) self::requireTestPayment($channel);
        $key = Validation::key($body['idempotency_key'] ?? '');
        $previous = $this->db->one('SELECT * FROM payments WHERE idempotency_key=?', [$key]);
        if ($previous) {
            if ((int)$previous['order_id'] !== $order['id'] || $previous['channel'] !== $channel) throw new ApiError('Idempotency request does not match.', 409, 409);
            return ['payment' => $previous, 'order' => $this->get($order['id'], $user)];
        }
        if ($order['status'] !== 'awaiting_payment') throw new ApiError('Order is not awaiting payment.', 409, 409);
        if ($order['type']==='boat_purchase' && $this->db->one('SELECT id FROM orders WHERE resource_id=? AND type=? AND id<>? AND paid_cents>refund_cents' . $this->db->lock(),[$order['resource_id'],'boat_purchase',$order['id']])) throw new ApiError('This physical boat already has another purchase payment.',409,40921);
        if ($order['type'] === 'boat_purchase' && $this->db->one('SELECT id FROM rental_reservations WHERE boat_id=? AND rental_date>=?' . $this->db->lock(), [$order['resource_id'], date('Y-m-d')])) throw new ApiError('This boat now has a current/future rental. Purchase payment cannot proceed.', 409, 40921);
        if ($order['broker_id'] || $order['type'] === 'boat_purchase') {
            if (!$order['contract_version']) throw new ApiError('A private contract is required before payment.', 409, 40930);
        }
        if ($order['contract_version']) {
            $contract = $this->db->one('SELECT * FROM contracts WHERE order_id=? AND version=?', [$order['id'], $order['contract_version']]);
            if (!$contract || !$contract['signed_at'] || $contract['review_status'] !== 'approved') throw new ApiError('The current contract version must be reviewed and accepted before payment.', 409, 40930);
        }
        if (in_array($order['type'], ['boat_rental', 'event'], true) && $order['updated_at'] < time() - 1800) throw new ApiError('Reservation expired. Create a new order.', 409, 40920);
        if ($order['coupon_claim_id']) $this->db->update('coupon_claims', $order['coupon_claim_id'], ['status' => 'used']);
        $paymentId = $this->db->insert('payments', ['order_id' => $order['id'], 'amount_cents' => $order['amount_cents'], 'channel' => $channel, 'status' => 'succeeded', 'idempotency_key' => $key,
            'provider_reference' => $channel === 'test' ? 'TEST-' . bin2hex(random_bytes(8)) : 'FREE', 'created_at' => time()]);
        $this->db->update('orders', $order['id'], ['status' => 'paid', 'paid_cents' => $order['amount_cents'], 'updated_at' => time()]);
        if ($order['type'] === 'boat_purchase') {
            $customer = $this->db->one('SELECT id,member_until FROM users WHERE id=?' . $this->db->lock(), [$order['customer_id']]);
            $start = max(time(), (int)$customer['member_until']);
            $this->db->update('users', $order['customer_id'], ['member_until' => (new \DateTimeImmutable('@' . $start))->modify('+6 months')->getTimestamp(), 'updated_at' => time()]);
        }
        if ($order['type'] === 'boat_rental') {
            $days = (int)($order['items'][0]['days'] ?? 0);
            $points = $days * (int)(new Finance($this->db))->growthRules()['rental_points_per_day'];
            (new Community($this->db))->grow($order['customer_id'], 'rental:' . $order['id'], $points, 'Rental order paid');
        }
        if ($order['type'] === 'event') (new Community($this->db))->activateEnrollment($order['resource_id'], $order['customer_id'], $order['id']);
        $this->event($order['id'], $user, 'payment_succeeded', ['channel' => $channel, 'amount_cents' => $order['amount_cents'], 'payment_id' => $paymentId]);
        return ['payment' => $this->db->one('SELECT * FROM payments WHERE id=?', [$paymentId]), 'order' => $this->get($order['id'], $user)];
    }

    private function refund(array $order, array $body, array $user)
    {
        Policy::roles($user, ['platform']);
        self::requireTestPayment($body['channel'] ?? '');
        $key = Validation::key($body['idempotency_key'] ?? '');
        $amount = Validation::integer($body['amount_cents'] ?? null, 'amount_cents', 1, 100000000000);
        $previous = $this->db->one('SELECT * FROM refunds WHERE idempotency_key=?', [$key]);
        if ($previous) {
            if ((int)$previous['order_id'] !== $order['id'] || (int)$previous['amount_cents'] !== $amount) throw new ApiError('Idempotency request does not match.', 409, 409);
            return ['refund' => $previous, 'order' => $this->get($order['id'], $user)];
        }
        if (!$order['paid_cents'] || $amount > $order['paid_cents'] - $order['refund_cents']) throw new ApiError('Refund exceeds refundable amount.', 409, 40931);
        $reason = Validation::text($body['reason'] ?? '', 'refund reason', 2000);
        $request = null;
        if (!empty($body['request_id'])) {
            $request = $this->db->one('SELECT * FROM refund_requests WHERE id=? AND order_id=?' . $this->db->lock(), [Validation::id($body['request_id'], 'request_id'), $order['id']]);
            if (!$request || !in_array($request['status'], ['pending', 'approved'], true) || (int)$request['amount_cents'] !== $amount) throw new ApiError('Refund request does not match this refund.', 409, 409);
        }
        $id = $this->db->insert('refunds', ['order_id' => $order['id'], 'amount_cents' => $amount, 'reason' => $reason, 'channel' => 'test', 'status' => 'succeeded',
            'idempotency_key' => $key, 'provider_reference' => 'TEST-REFUND-' . bin2hex(random_bytes(8)), 'created_at' => time()]);
        $total = $order['refund_cents'] + $amount;
        if ($request) $this->db->update('refund_requests', $request['id'], ['status' => 'successful', 'reviewed_by' => (int)$user['id'], 'refund_id' => $id, 'updated_at' => time()]);
        $this->finance->reverse($this->raw($order), $id, $total);
        $this->db->update('orders', $order['id'], ['refund_cents' => $total, 'status' => $total === $order['paid_cents'] ? 'refunded' : 'partially_refunded', 'updated_at' => time()]);
        if ($total === $order['paid_cents']) {
            $this->release($order, false);
            if ($order['type'] === 'event') $this->db->run('UPDATE enrollments SET status=? WHERE order_id=?', ['cancelled', $order['id']]);
        }
        $this->event($order['id'], $user, 'refund_succeeded', ['refund_id' => $id, 'amount_cents' => $amount, 'channel' => 'test']);
        return ['refund' => $this->db->one('SELECT * FROM refunds WHERE id=?', [$id]), 'order' => $this->get($order['id'], $user)];
    }

    private function release(array $order, $releaseCoupon)
    {
        $this->db->run('DELETE FROM rental_reservations WHERE order_id=?', [$order['id']]);
        if ($order['type']==='event') $this->db->run('UPDATE enrollments SET status=? WHERE order_id=?',['cancelled',$order['id']]);
        if ($releaseCoupon && $order['coupon_claim_id']) $this->db->update('coupon_claims', $order['coupon_claim_id'], ['status' => 'available', 'order_id' => null]);
        if ($order['type'] === 'mall') {
            foreach ($order['items'] as $line) {
                $product = $this->catalog->get($line['resource_id'], 'products', ['id' => 0, 'role' => 'platform', 'org_id' => null], true);
                $this->db->run('UPDATE products SET stock=stock+? WHERE resource_id=?', [(int)$line['quantity'], $product['id']]);
                $this->db->run('UPDATE resources SET version=version+1,updated_at=? WHERE id=?', [time(), $product['id']]);
            }
        }
    }

    public function contracts($id, array $user)
    {
        $this->get($id, $user);
        return ['items' => $this->db->all('SELECT * FROM contracts WHERE order_id=? ORDER BY version DESC', [(int)$id])];
    }

    private function contract(array $order, array $body, array $user)
    {
        Policy::orderOperator($user, $order);
        if ($order['paid_cents'] || in_array($order['status'], ['cancelled', 'refunded'], true)) throw new ApiError('Contract cannot be replaced after payment or cancellation.', 409, 40930);
        $fileId = Validation::id($body['file_id'] ?? 0, 'file_id');
        $file = $this->db->one('SELECT * FROM uploads WHERE id=?', [$fileId]);
        if (!$file || !$file['is_private'] || !in_array($file['mime'], ['application/pdf', 'image/jpeg', 'image/png'], true)) throw new ApiError('A private PDF/JPEG/PNG contract file is required.');
        if ((int)$file['owner_id'] !== (int)$user['id'] && $user['role'] !== 'platform') throw new ApiError('Contract upload belongs to another user.', 403, 403);
        $version = (int)$this->db->scalar('SELECT COALESCE(MAX(version),0) FROM contracts WHERE order_id=?', [$order['id']]) + 1;
        $id = $this->db->insert('contracts', ['order_id' => $order['id'], 'version' => $version, 'file_id' => $fileId, 'title' => Validation::text($body['title'] ?? 'Contract', 'title', 200),
            'file_sha256' => $file['sha256'], 'creator_id' => (int)$user['id'], 'review_status' => 'pending', 'reviewer_id' => null, 'reviewed_at' => null, 'review_notes' => '', 'signed_by' => null, 'signed_at' => null, 'created_at' => time()]);
        $this->db->update('orders', $order['id'], ['contract_version' => $version, 'updated_at' => time()]);
        $this->event($order['id'], $user, 'contract_version_created', ['version' => $version, 'sha256' => $file['sha256']]);
        return $this->db->one('SELECT * FROM contracts WHERE id=?', [$id]);
    }

    private function sign(array $order, array $body, array $user)
    {
        if ((int)$user['id'] !== $order['customer_id']) throw new ApiError('Only the customer can accept a contract.', 403, 403);
        if (($body['accepted'] ?? null) !== true) throw new ApiError('Explicit contract acceptance is required.');
        $version = Validation::id($body['version'] ?? 0, 'version');
        if ($version !== $order['contract_version']) throw new ApiError('Contract version changed. Read the current version.', 409, 40930);
        if ($order['paid_cents'] || in_array($order['status'], ['cancelled', 'refunded'], true)) throw new ApiError('Contract acceptance is no longer available.', 409, 40930);
        $contract = $this->db->one('SELECT * FROM contracts WHERE order_id=? AND version=?', [$order['id'], $version]);
        if (!$contract) throw new ApiError('Contract not found.', 404, 404);
        if ($contract['review_status'] !== 'approved') throw new ApiError('The contract must pass platform review before acceptance.', 409, 40930);
        if (!$contract['signed_at']) $this->db->update('contracts', $contract['id'], ['signed_by' => (int)$user['id'], 'signed_at' => time()]);
        $this->event($order['id'], $user, 'contract_accepted', ['version' => $version, 'sha256' => $contract['file_sha256']]);
        // Acceptance evidence is stored; a certified external e-signature service remains an integration.
        return $this->db->one('SELECT * FROM contracts WHERE id=?', [(int)$contract['id']]);
    }

    private function reviewContract(array $order, array $body, array $user)
    {
        Policy::roles($user, ['platform']);
        $version = Validation::id($body['version'] ?? 0, 'version');
        if ($version !== $order['contract_version']) throw new ApiError('Review the current contract version.', 409, 40930);
        $contract = $this->db->one('SELECT * FROM contracts WHERE order_id=? AND version=?', [$order['id'], $version]);
        if (!$contract || $contract['review_status'] !== 'pending') throw new ApiError('Contract has already been reviewed.', 409, 40930);
        $status = Validation::choice($body['status'] ?? '', ['approved', 'rejected'], 'review status');
        $this->db->update('contracts', $contract['id'], ['review_status' => $status, 'reviewer_id' => (int)$user['id'], 'reviewed_at' => time(), 'review_notes' => Validation::text($body['notes'] ?? '', 'notes', 2000, false)]);
        $this->event($order['id'], $user, 'contract_reviewed', ['version' => $version, 'status' => $status]);
        return $this->db->one('SELECT * FROM contracts WHERE id=?', [(int)$contract['id']]);
    }
}
