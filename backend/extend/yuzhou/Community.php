<?php
namespace yuzhou;

class Community
{
    private $db;
    private $catalog;
    public function __construct(Database $db) { $this->db = $db; $this->catalog = new Catalog($db); }

    public function grow($userId, $key, $points, $description)
    {
        $this->db->one('SELECT id FROM users WHERE id=?' . $this->db->lock(), [(int)$userId]);
        if ($this->db->one('SELECT id FROM growth_logs WHERE event_key=?', [$key])) return;
        $this->db->insert('growth_logs', ['user_id' => $userId, 'event_key' => $key, 'points' => $points, 'description' => $description, 'created_at' => time()]);
        $this->db->run('UPDATE users SET growth_points=growth_points+? WHERE id=?', [$points, $userId]);
    }

    public function progress(array $body, array $user)
    {
        return $this->db->transaction(function () use ($body, $user) {
            $course = $this->catalog->get(Validation::id($body['course_id'] ?? 0, 'course_id'), 'courses');
            $lessonId = Validation::text((string)($body['lesson_id'] ?? ''), 'lesson_id', 100);
            $lesson = null;
            foreach ($course['payload']['lessons'] as $candidate) if ((string)$candidate['id'] === $lessonId) $lesson = $candidate;
            if (!$lesson) throw new ApiError('Lesson not found.', 404, 404);
            $position = Validation::integer($body['position_seconds'] ?? 0, 'position_seconds', 0, (int)$lesson['duration_seconds']);
            $old = $this->db->one('SELECT * FROM learning_progress WHERE user_id=? AND course_id=? AND lesson_id=?' . $this->db->lock(), [(int)$user['id'], $course['id'], $lessonId]);
            $position = max($position, $old ? (int)$old['position_seconds'] : 0);
            $complete = $position >= (int)ceil($lesson['duration_seconds'] * 0.9) ? 1 : 0;
            $values = ['position_seconds' => $position, 'completed' => $complete, 'updated_at' => time()];
            if ($old) $this->db->update('learning_progress', $old['id'], $values);
            else $this->db->insert('learning_progress', $values + ['user_id' => (int)$user['id'], 'course_id' => $course['id'], 'lesson_id' => $lessonId]);
            if ($complete) $this->grow((int)$user['id'], 'lesson:' . $user['id'] . ':' . $course['id'] . ':' . $lessonId, (new Finance($this->db))->growthRules()['lesson_points'], 'Course lesson completed');
            $rewards = $this->grantRewards($course['id'], (int)$user['id']);
            return $this->db->one('SELECT * FROM learning_progress WHERE user_id=? AND course_id=? AND lesson_id=?', [(int)$user['id'], $course['id'], $lessonId]) + ['rewards' => $rewards];
        });
    }

    public function progressList(array $query, array $user)
    {
        $list = $this->db->paginate('learning_progress', 'user_id=?', [(int)$user['id']], $query);
        if (!$list['items']) return $list;
        $ids = array_map('intval', array_column($list['items'], 'id'));
        $titles = $this->db->all('SELECT p.id,r.title AS course_title,l.title AS lesson_title FROM learning_progress p '
            . 'LEFT JOIN resources r ON r.id=p.course_id '
            . 'LEFT JOIN course_lessons l ON l.course_id=p.course_id AND l.lesson_key=p.lesson_id '
            . 'WHERE p.user_id=? AND p.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', array_merge([(int)$user['id']], $ids));
        $byId = []; foreach ($titles as $row) $byId[(int)$row['id']] = $row;
        foreach ($list['items'] as &$row) {
            $title = $byId[(int)$row['id']] ?? [];
            $row['course_title'] = $title['course_title'] ?? null;
            $row['lesson_title'] = $title['lesson_title'] ?? null;
        }
        return $list;
    }

    public function exam(array $body, array $user)
    {
        return $this->db->transaction(function () use ($body, $user) {
            $course = $this->catalog->get(Validation::id($body['course_id'] ?? 0, 'course_id'), 'courses');
            $questions = $this->db->all('SELECT * FROM course_questions WHERE course_id=? ORDER BY question_index', [$course['id']]);
            $answers = $body['answers'] ?? [];
            if (!$questions || !is_array($answers) || count($answers) !== count($questions)) throw new ApiError('Provide one answer for every exam question.');
            $correct = 0;
            foreach ($questions as $index => $question) {
                $options = json_decode($question['options_json'], true);
                $answer = Validation::integer($answers[$index], 'answer', 0, count($options) - 1);
                if ($answer === (int)$question['answer_index']) $correct++;
            }
            $score = (int)floor($correct * 100 / count($questions));
            $passed = $score >= (int)($course['payload']['pass_score'] ?? 60) ? 1 : 0;
            $id = $this->db->insert('exam_attempts', ['user_id' => (int)$user['id'], 'course_id' => $course['id'], 'score' => $score, 'passed' => $passed, 'answers' => json_encode($answers), 'created_at' => time()]);
            if ($passed) $this->grow((int)$user['id'], 'exam:' . $user['id'] . ':' . $course['id'], (new Finance($this->db))->growthRules()['exam_points'], 'Course exam passed');
            return $this->db->one('SELECT * FROM exam_attempts WHERE id=?', [$id]) + ['rewards' => $this->grantRewards($course['id'], (int)$user['id'])];
        });
    }

    public function claimCoupon($id, array $user)
    {
        return $this->db->transaction(function () use ($id, $user) {
            $coupon = $this->catalog->get($id, 'coupons', null, true);
            if (strtotime($coupon['payload']['expires_at']) <= time()) throw new ApiError('Coupon has expired.');
            if ($this->db->one('SELECT id FROM coupon_claims WHERE user_id=? AND coupon_id=?', [(int)$user['id'], $coupon['id']])) throw new ApiError('You have already claimed this coupon.', 409, 409);
            if ((int)$coupon['payload']['stock'] < 1) throw new ApiError('Coupon stock is exhausted.', 409, 409);
            $this->db->run('UPDATE coupons SET stock=stock-1 WHERE resource_id=?', [$coupon['id']]);
            $id = $this->db->insert('coupon_claims', ['user_id' => (int)$user['id'], 'coupon_id' => $coupon['id'], 'status' => 'available', 'order_id' => null, 'created_at' => time()]);
            return $this->db->one('SELECT * FROM coupon_claims WHERE id=?', [$id]);
        });
    }

    public function coupons(array $query, array $user)
    {
        $list = $this->db->paginate('coupon_claims', 'user_id=?', [(int)$user['id']], $query);
        foreach ($list['items'] as &$row) $row['coupon'] = $this->catalog->get($row['coupon_id'], 'coupons', ['id' => 0, 'org_id' => null, 'role' => 'platform']);
        return $list;
    }

    public function reserveEnrollment($eventId, $userId, $orderId = null)
    {
        $event = $this->catalog->get($eventId, 'events', null, true);
        $this->requireEventEligibility($userId);
        if (strtotime($event['payload']['starts_at']) <= time()) throw new ApiError('The event has already started.');
        $existing = $this->db->one('SELECT * FROM enrollments WHERE user_id=? AND event_id=?' . $this->db->lock(), [$userId, $eventId]);
        if ($existing && $existing['status'] !== 'cancelled') throw new ApiError('You have already enrolled in this event.', 409, 409);
        $count = count($this->db->all('SELECT id FROM enrollments WHERE event_id=? AND status IN (?,?,?)' . $this->db->lock(), [$eventId, 'pending', 'active', 'checked_in']));
        if ($count >= (int)$event['payload']['capacity']) throw new ApiError('The event is full.', 409, 40940);
        $values = ['order_id' => $orderId, 'status' => 'pending', 'ticket_code' => bin2hex(random_bytes(20)), 'created_at' => time(), 'checked_in_at' => null];
        if ($existing) { $this->db->update('enrollments', $existing['id'], $values); $id = (int)$existing['id']; }
        else $id = $this->db->insert('enrollments', $values + ['user_id' => $userId, 'event_id' => $eventId]);
        return $this->db->one('SELECT * FROM enrollments WHERE id=?', [$id]);
    }

    public function activateEnrollment($eventId, $userId, $orderId = null)
    {
        $this->catalog->get($eventId,'events',null,true);
        $this->requireEventEligibility($userId);
        $row = $this->db->one('SELECT * FROM enrollments WHERE user_id=? AND event_id=?' . $this->db->lock(), [$userId, $eventId]);
        if (!$row) $row = $this->reserveEnrollment($eventId, $userId, $orderId);
        if ($row['status'] !== 'pending' || ($row['order_id'] && (int)$row['order_id'] !== (int)$orderId)) throw new ApiError('Enrollment is not awaiting this payment.', 409, 409);
        $this->db->update('enrollments', $row['id'], ['status' => 'active']);
        return $this->db->one('SELECT * FROM enrollments WHERE id=?', [(int)$row['id']]);
    }

    private function requireEventEligibility($userId)
    {
        $account = $this->db->one('SELECT role,status,identity_status,member_until FROM users WHERE id=?' . $this->db->lock(),[$userId]);
        if (!$account || $account['role']!=='consumer' || $account['status']!=='active') throw new ApiError('An active consumer account is required for activity enrollment.',403,40340);
        if ($account['identity_status']!=='verified') throw new ApiError('Activity enrollment requires approved identity materials.',403,40341);
        if ((int)$account['member_until']<=time()) throw new ApiError('Activity enrollment requires an active membership.',403,40342);
    }

    public function cancelEnrollment($id,array $body,array $user)
    {
        Policy::roles($user,['consumer']);
        $id=Validation::id($id);$ticket=Validation::text($body['ticket_code']??'','ticket_code',100);
        return $this->db->transaction(function()use($id,$ticket,$body,$user){
            $candidate=$this->db->one('SELECT * FROM enrollments WHERE id=? AND user_id=?',[$id,(int)$user['id']]);
            if (!$candidate) throw new ApiError('Enrollment not found.',404,404);
            $order=$candidate['order_id'] ? $this->db->one('SELECT * FROM orders WHERE id=?' . $this->db->lock(),[(int)$candidate['order_id']]) : null;
            $this->db->one('SELECT id FROM resources WHERE id=?' . $this->db->lock(),[(int)$candidate['event_id']]);
            $row=$this->db->one('SELECT * FROM enrollments WHERE id=?' . $this->db->lock(),[$id]);
            if (!hash_equals($row['ticket_code'],$ticket) || (string)$row['order_id']!==(string)$candidate['order_id']) throw new ApiError('The enrollment changed. Reload its current ticket.',409,40940);
            if ($row['status']==='cancelled') return $row;
            if (!in_array($row['status'],['pending','active'],true)) throw new ApiError('A checked-in enrollment cannot be cancelled here.',409,40940);
            if ($order && ((int)$order['amount_cents']>0 || (int)$order['paid_cents']>0)) throw new ApiError('This activity has a payment order. Cancel the unpaid order or request a refund for a paid order.',409,40941);
            if ($order) (new Orders($this->db))->action($order['id'],'cancel',['reason'=>$body['reason']??''],$user);
            $this->db->update('enrollments',$id,['status'=>'cancelled']);
            Policy::audit($this->db,$user,'enrollment.cancel','enrollment',$id,['reason'=>Validation::text($body['reason']??'','reason',2000,false)]);
            return $this->db->one('SELECT * FROM enrollments WHERE id=?',[$id]);
        });
    }

    public function enroll($id, array $user)
    {
        Policy::roles($user, ['consumer']);
        return $this->db->transaction(function () use ($id, $user) {
            $event = $this->catalog->get($id, 'events', null, true);
            if ($event['price_cents'] > 0) return ['order' => (new Orders($this->db))->create(['type' => 'event', 'resource_id' => $event['id']], $user)];
            $row = $this->reserveEnrollment($event['id'], (int)$user['id']);
            return ['enrollment' => $this->activateEnrollment($event['id'], (int)$user['id'])];
        });
    }

    public function enrollmentList(array $user, array $query)
    {
        if ($user['role'] === 'platform') return $this->db->paginate('enrollments', '1=1', [], $query);
        if ($user['role'] === 'merchant') return $this->db->paginate('enrollments', 'event_id IN (SELECT id FROM resources WHERE kind=? AND org_id=?) OR event_id IN (SELECT ep.event_id FROM event_participants ep JOIN merchants m ON m.credit_code=ep.merchant_credit_code WHERE m.organization_id=?)', ['events', (int)$user['org_id'], (int)$user['org_id']], $query);
        return $this->db->paginate('enrollments', 'user_id=?', [(int)$user['id']], $query);
    }

    public function checkIn($id, array $body, array $user)
    {
        Policy::roles($user, ['merchant', 'platform']);
        return $this->db->transaction(function () use ($id, $body, $user) {
            $row = $this->db->one('SELECT * FROM enrollments WHERE id=?' . $this->db->lock(), [Validation::id($id)]);
            if (!$row || $row['status'] !== 'active' || !hash_equals($row['ticket_code'], (string)($body['ticket_code'] ?? ''))) throw new ApiError('Ticket is invalid or already used.', 409, 40940);
            $event = $this->catalog->get($row['event_id'], 'events', $user);
            if ($user['role'] !== 'platform' && (int)$event['org_id'] !== (int)$user['org_id'] && !$this->db->one('SELECT ep.event_id FROM event_participants ep JOIN merchants m ON m.credit_code=ep.merchant_credit_code WHERE ep.event_id=? AND m.organization_id=?', [(int)$row['event_id'], (int)$user['org_id']])) throw new ApiError('Ticket belongs to another merchant event.', 403, 403);
            $this->db->update('enrollments', $id, ['status' => 'checked_in', 'checked_in_at' => time()]);
            Policy::audit($this->db, $user, 'event.check_in', 'enrollment', $id);
            return $this->db->one('SELECT * FROM enrollments WHERE id=?', [(int)$id]);
        });
    }

    public function reviewEvent($id, array $body, array $user)
    {
        Policy::roles($user, ['merchant', 'platform']);
        return $this->db->transaction(function () use ($id, $body, $user) {
            $event = $this->catalog->get($id, 'events', $user, true);
            $status = Validation::choice($body['status'] ?? '', ['approved', 'rejected'], 'status');
            $participants = $this->db->all('SELECT * FROM event_participants WHERE event_id=?', [$event['id']]);
            if ($participants) {
                $merchant = $this->db->one('SELECT credit_code FROM merchants WHERE organization_id=? AND status=?', [$user['org_id'], 'approved']);
                if ($user['role'] !== 'merchant' || !$merchant || !$this->db->one('SELECT event_id FROM event_participants WHERE event_id=? AND merchant_credit_code=?', [$event['id'], $merchant['credit_code']])) throw new ApiError('Only a designated merchant can review this joint event.', 403, 403);
                $this->db->run('UPDATE event_participants SET status=?,reviewer_id=?,reviewed_at=?,notes=? WHERE event_id=? AND merchant_credit_code=?', [$status, (int)$user['id'], time(), Validation::text($body['notes'] ?? '', 'notes', 2000, false), $event['id'], $merchant['credit_code']]);
                $approved = (int)$this->db->scalar('SELECT COUNT(*) FROM event_participants WHERE event_id=? AND status=?', [$event['id'], 'approved']);
                $pending = (int)$this->db->scalar('SELECT COUNT(*) FROM event_participants WHERE event_id=? AND status=?', [$event['id'], 'pending']);
                $resourceStatus = $approved ? 'published' : ($pending ? 'pending' : 'rejected');
            } else {
                Policy::roles($user, ['platform']); $resourceStatus = $status === 'approved' ? 'published' : 'rejected';
            }
            $this->db->run('UPDATE resources SET status=?,version=version+1,updated_at=? WHERE id=?', [$resourceStatus, time(), $event['id']]);
            Policy::audit($this->db, $user, 'event.review', 'event', $event['id'], ['status' => $status]);
            return $this->catalog->get($event['id'], 'events', $user);
        });
    }

    public function comment($id, array $body, array $user)
    {
        $this->catalog->get($id, 'posts');
        $id = $this->db->insert('comments', ['post_id' => (int)$id, 'user_id' => (int)$user['id'], 'content' => Validation::text($body['content'] ?? '', 'content', 2000), 'status' => 'pending', 'created_at' => time()]);
        return $this->db->one('SELECT * FROM comments WHERE id=?', [$id]);
    }

    public function reviewPost($id, array $body, array $user)
    {
        Policy::roles($user, ['platform']);
        return $this->db->transaction(function () use ($id, $body, $user) {
            $post = $this->catalog->get($id, 'posts', $user, true);
            $status = Validation::choice($body['status'] ?? '', ['published', 'rejected'], 'status');
            if ($post['status'] !== 'pending') throw new ApiError('Post is not awaiting review.', 409, 409);
            $this->db->run('UPDATE resources SET status=?,version=version+1,updated_at=? WHERE id=?', [$status, time(), $post['id']]);
            if ($status === 'published') $this->grow($post['owner_id'], 'post:' . $post['id'], (new Finance($this->db))->growthRules()['post_points'], 'Post approved');
            Policy::audit($this->db, $user, 'post.review', 'post', $post['id'], ['status' => $status, 'notes' => Validation::text($body['notes'] ?? '', 'notes', 2000, false)]);
            return $this->catalog->get($id, 'posts', $user);
        });
    }

    public function saveRewards($courseId, $rewards)
    {
        if (!is_array($rewards) || count($rewards) > 30) throw new ApiError('Invalid course rewards.');
        $this->db->run('UPDATE course_reward_definitions SET active=0 WHERE course_id=?', [$courseId]);
        $keys = [];
        foreach ($rewards as $reward) {
            if (!is_array($reward)) throw new ApiError('Invalid course reward.');
            $key = Validation::text((string)($reward['id'] ?? ''), 'reward id', 100);
            if (isset($keys[$key])) throw new ApiError('Duplicate reward id.'); $keys[$key] = true;
            $type = Validation::choice($reward['type'] ?? '', ['badge', 'growth', 'coupon', 'maintenance_coupon', 'physical_gift', 'event_invitation'], 'reward type');
            $couponId = null; $eventId = null; $points = 0;
            if (in_array($type, ['coupon', 'maintenance_coupon'], true)) {
                $coupon = $this->catalog->get(Validation::id($reward['coupon_id'] ?? 0, 'coupon_id'), 'coupons'); $couponId = $coupon['id'];
                if ($type === 'maintenance_coupon' && !in_array('maintenance', $coupon['payload']['order_types'], true)) throw new ApiError('Maintenance reward requires a maintenance coupon.');
            }
            if ($type === 'event_invitation') $eventId = $this->catalog->get(Validation::id($reward['event_id'] ?? 0, 'event_id'), 'events')['id'];
            if ($type === 'growth') $points = Validation::integer($reward['points'] ?? 0, 'reward points', 1, 10000);
            $values = ['reward_type' => $type, 'title' => Validation::text($reward['title'] ?? '', 'reward title', 200), 'points' => $points, 'coupon_id' => $couponId, 'event_id' => $eventId, 'active' => 1];
            $old = $this->db->one('SELECT * FROM course_reward_definitions WHERE course_id=? AND reward_key=?', [$courseId, $key]);
            if ($old) {
                if ($this->db->one('SELECT id FROM course_reward_claims WHERE reward_id=?', [(int)$old['id']])) {
                    foreach (['reward_type', 'points', 'coupon_id', 'event_id'] as $field) if ((string)$old[$field] !== (string)$values[$field]) throw new ApiError('An issued reward cannot change; use a new reward id.', 409, 409);
                }
                $this->db->update('course_reward_definitions', $old['id'], $values);
            } else $this->db->insert('course_reward_definitions', $values + ['course_id' => $courseId, 'reward_key' => $key]);
        }
    }

    public function grantRewards($courseId, $userId)
    {
        $total = (int)$this->db->scalar('SELECT COUNT(*) FROM course_lessons WHERE course_id=?', [$courseId]);
        $complete = (int)$this->db->scalar('SELECT COUNT(*) FROM learning_progress WHERE course_id=? AND user_id=? AND completed=1', [$courseId, $userId]);
        if (!$total || $complete !== $total) return [];
        if ($this->db->scalar('SELECT COUNT(*) FROM course_questions WHERE course_id=?', [$courseId]) && !$this->db->one('SELECT id FROM exam_attempts WHERE course_id=? AND user_id=? AND passed=1', [$courseId, $userId])) return [];
        $this->db->one('SELECT id FROM users WHERE id=?' . $this->db->lock(), [$userId]);
        foreach ($this->db->all('SELECT * FROM course_reward_definitions WHERE course_id=? AND active=1 ORDER BY id', [$courseId]) as $reward) {
            if ($this->db->one('SELECT id FROM course_reward_claims WHERE reward_id=? AND user_id=?', [(int)$reward['id'], $userId])) continue;
            $type = $reward['reward_type']; $status = 'issued';
            if ($type === 'growth') $this->grow($userId, 'course-reward:' . $reward['id'] . ':' . $userId, (int)$reward['points'], $reward['title']);
            if (in_array($type, ['coupon', 'maintenance_coupon'], true)) {
                $coupon = $this->db->one('SELECT c.*,r.status FROM coupons c JOIN resources r ON r.id=c.resource_id WHERE c.resource_id=?' . $this->db->lock(), [(int)$reward['coupon_id']]);
                $claimed = $this->db->one('SELECT id FROM coupon_claims WHERE coupon_id=? AND user_id=?', [(int)$reward['coupon_id'], $userId]);
                if ($claimed) $status = 'already_claimed';
                elseif (!$coupon || $coupon['status'] !== 'published' || (int)$coupon['stock'] < 1 || (int)$coupon['expires_at'] <= time()) $status = 'unavailable';
                else {
                    $this->db->run('UPDATE coupons SET stock=stock-1 WHERE resource_id=?', [(int)$reward['coupon_id']]);
                    $this->db->insert('coupon_claims', ['coupon_id' => (int)$reward['coupon_id'], 'user_id' => $userId, 'status' => 'available', 'order_id' => null, 'created_at' => time()]);
                }
            }
            if ($type === 'badge') $status = 'earned';
            if ($type === 'physical_gift') $status = 'pending_fulfillment';
            if ($type === 'event_invitation') $status = 'eligible';
            $this->db->insert('course_reward_claims', ['reward_id' => (int)$reward['id'], 'user_id' => $userId, 'status' => $status, 'reward_snapshot' => json_encode($reward, JSON_UNESCAPED_UNICODE), 'notes' => '', 'reviewed_by' => null, 'created_at' => time(), 'updated_at' => time()]);
        }
        return $this->db->all('SELECT c.* FROM course_reward_claims c JOIN course_reward_definitions d ON d.id=c.reward_id WHERE d.course_id=? AND c.user_id=? ORDER BY c.id', [$courseId, $userId]);
    }

    public function rewardList(array $query, array $user, $admin = false)
    {
        if ($admin) Policy::roles($user, ['platform']);
        $list = $this->db->paginate('course_reward_claims', $admin ? '1=1' : 'user_id=?', $admin ? [] : [(int)$user['id']], $query);
        foreach ($list['items'] as &$row) $row['reward_snapshot'] = json_decode($row['reward_snapshot'], true);
        return $list;
    }

    public function fulfillReward($id, array $body, array $user)
    {
        Policy::roles($user, ['platform']);
        return $this->db->transaction(function () use ($id, $body, $user) {
            $row = $this->db->one('SELECT * FROM course_reward_claims WHERE id=?' . $this->db->lock(), [Validation::id($id)]);
            if (!$row || $row['status'] !== 'pending_fulfillment') throw new ApiError('Reward is not awaiting physical fulfillment.', 409, 409);
            $notes = Validation::text($body['notes'] ?? '', 'fulfillment evidence', 2000);
            $this->db->update('course_reward_claims', $id, ['status' => 'fulfilled', 'notes' => $notes, 'reviewed_by' => (int)$user['id'], 'updated_at' => time()]);
            Policy::audit($this->db, $user, 'course_reward.fulfill', 'course_reward_claim', $id, ['notes' => $notes]);
            return $this->db->one('SELECT * FROM course_reward_claims WHERE id=?', [(int)$id]);
        });
    }

    public function cart(array $query, array $user)
    {
        Policy::roles($user, ['consumer']);
        $list = $this->db->paginate('cart_items', 'user_id=?', [(int)$user['id']], $query);
        foreach ($list['items'] as &$row) $row['product'] = $this->catalog->get($row['product_id'], 'products');
        return $list;
    }

    public function cartSave(array $body, array $user, $id = null)
    {
        Policy::roles($user, ['consumer']);
        $product = $this->catalog->get(Validation::id($body['product_id'] ?? 0, 'product_id'), 'products');
        $quantity = Validation::integer($body['quantity'] ?? 1, 'quantity', 1, 50);
        if ($quantity > (int)$product['payload']['stock']) throw new ApiError('Product stock is insufficient.', 409, 40922);
        $old = $this->db->one('SELECT * FROM cart_items WHERE user_id=? AND product_id=?', [(int)$user['id'], $product['id']]);
        if ($id && (!$old || (int)$old['id'] !== (int)$id)) throw new ApiError('Cart item not found.', 404, 404);
        if ($old) { $this->db->update('cart_items', $old['id'], ['quantity' => $quantity, 'updated_at' => time()]); $id = $old['id']; }
        else $id = $this->db->insert('cart_items', ['user_id' => (int)$user['id'], 'product_id' => $product['id'], 'quantity' => $quantity, 'updated_at' => time()]);
        return $this->db->one('SELECT * FROM cart_items WHERE id=?', [(int)$id]);
    }
}
