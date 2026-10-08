<?php
namespace yuzhou;

class Finance
{
    private $db;
    public function __construct(Database $db) { $this->db = $db; }

    public function settings()
    {
        $row = $this->db->one('SELECT * FROM settings WHERE setting_key=?', ['distribution']);
        $settings = $row ? ['distribution' => json_decode($row['value_json'], true), 'version' => (int)$row['version']]
            : ['distribution' => ['broker_bps' => 0, 'member_bps' => 0, 'settlement_days' => 7], 'version' => 0];
        return $settings + ['growth' => $this->growthRules()];
    }

    public function growthRules()
    {
        $row = $this->db->one('SELECT value_json FROM settings WHERE setting_key=?', ['growth']);
        return array_merge(['invitation_points' => 0, 'rental_points_per_day' => 0, 'lesson_points' => 0, 'exam_points' => 0, 'post_points' => 0], $row ? (json_decode($row['value_json'], true) ?: []) : []);
    }

    public function saveSettings(array $body, array $user)
    {
        Policy::roles($user, ['platform']);
        $rule = $body['distribution'] ?? $this->settings()['distribution'];
        if (!is_array($rule)) throw new ApiError('distribution is invalid.');
        $rule = ['broker_bps' => Validation::integer($rule['broker_bps'] ?? 0, 'broker_bps', 0, 10000),
            'member_bps' => Validation::integer($rule['member_bps'] ?? 0, 'member_bps', 0, 10000),
            'settlement_days' => Validation::integer($rule['settlement_days'] ?? 7, 'settlement_days', 0, 365)];
        if ($rule['broker_bps'] + $rule['member_bps'] > 10000) throw new ApiError('Distribution percentages cannot exceed 100% of profit.');
        $growth = $this->growthRules();
        if (isset($body['growth'])) {
            if (!is_array($body['growth']) || array_diff(array_keys($body['growth']), array_keys($growth))) throw new ApiError('Invalid growth configuration.');
            foreach ($body['growth'] as $key => $value) $growth[$key] = Validation::integer($value, $key, 0, 10000);
        }
        return $this->db->transaction(function () use ($rule, $growth, $user) {
            $row = $this->db->one('SELECT * FROM settings WHERE setting_key=?' . $this->db->lock(), ['distribution']);
            $data = ['value_json' => json_encode($rule), 'version' => $row ? (int)$row['version'] + 1 : 1, 'updated_at' => time()];
            if ($row) $this->db->update('settings', $row['id'], $data);
            else $this->db->insert('settings', $data + ['setting_key' => 'distribution']);
            $growthRow = $this->db->one('SELECT * FROM settings WHERE setting_key=?' . $this->db->lock(), ['growth']);
            $growthData = ['value_json' => json_encode($growth), 'version' => $growthRow ? (int)$growthRow['version'] + 1 : 1, 'updated_at' => time()];
            if ($growthRow) $this->db->update('settings', $growthRow['id'], $growthData); else $this->db->insert('settings', $growthData + ['setting_key' => 'growth']);
            Policy::audit($this->db, $user, 'distribution.configure', 'settings', $row ? $row['id'] : 0, $rule);
            return $this->settings();
        });
    }

    public static function proportional($amount, $numerator, $denominator)
    {
        if ($denominator <= 0 || $numerator < 0 || $numerator > $denominator) throw new \LogicException('Invalid proportion.');
        // BCMath prevents float conversion and multiplication overflow for large boat orders.
        return (int)bcdiv(bcmul((string)$amount, (string)$numerator, 0), (string)$denominator, 0);
    }

    public function confirm(array $order, array $body, array $user)
    {
        Policy::roles($user, ['platform']);
        if (!in_array($order['status'], ['paid', 'fulfilling', 'completed', 'partially_refunded'], true)) throw new ApiError('Profit can only be confirmed for a paid order.', 409, 409);
        if (!in_array($order['profit_status'], ['unconfirmed', 'needs_reconfirmation'], true)) throw new ApiError('Profit has already been confirmed.', 409, 409);
        $profit = Validation::money($body['profit_cents'] ?? null, 'profit_cents');
        if ($profit > (int)$order['paid_cents'] - (int)$order['refund_cents']) throw new ApiError('Profit cannot exceed net paid amount.');
        $notes = Validation::text($body['notes'] ?? '', 'profit confirmation notes', 2000);
        $snapshot = json_decode($order['rule_snapshot'] ?: '', true);
        $revision = (int)$order['profit_revision'] + 1;
        if ($snapshot) {
            $awards = $snapshot['beneficiaries']; $rules = $snapshot['rule'];
            if (isset($body['beneficiaries'])) {
                $provided = []; foreach ($body['beneficiaries'] as $b) $provided[$b['role']] = (int)$b['user_id'];
                $original = []; foreach ($awards as $b) $original[$b['role']] = (int)$b['user_id'];
                ksort($provided); ksort($original);
                if ($provided !== $original) throw new ApiError('Reconfirmation retains the original beneficiaries.', 409, 40910);
            }
        } else {
            $settings = $this->settings(); $rules = $settings['distribution'];
            $beneficiaries = $body['beneficiaries'] ?? [];
            if (!is_array($beneficiaries) || count($beneficiaries) > 2) throw new ApiError('Confirm at most one broker and one member beneficiary.');
            $awards = []; $roles = [];
            foreach ($beneficiaries as $beneficiary) {
                $role = Validation::choice($beneficiary['role'] ?? null, ['broker', 'member'], 'beneficiary role');
                if (isset($roles[$role])) throw new ApiError('Duplicate beneficiary role.');
                $roles[$role] = true;
                $id = Validation::id($beneficiary['user_id'] ?? 0, 'beneficiary user_id');
                $person = $this->db->one('SELECT * FROM users WHERE id=?' . $this->db->lock(), [$id]);
                if (!$person || $person['status'] !== 'active') throw new ApiError('Beneficiary is not active.');
                if ($role === 'broker' && ($person['role'] !== 'broker' || (int)$order['broker_id'] !== $id)) throw new ApiError('Broker beneficiary must be this order broker.');
                if ($role === 'member' && (int)$person['member_until'] <= time()) throw new ApiError('Member beneficiary does not have a current membership.');
                $bps = (int)$rules[$role . '_bps'];
                if (!$settings['version'] || !$bps) throw new ApiError('Distribution rule is not configured.', 409, 40910);
                $awards[] = ['user_id' => $id, 'role' => $role, 'bps' => $bps, 'amount_cents' => self::proportional($profit, $bps, 10000)];
            }
            $snapshot = ['version' => $settings['version'], 'rule' => $rules, 'initial_profit_cents' => $profit, 'beneficiaries' => $awards,
                'confirmed_by' => (int)$user['id'], 'confirmed_at' => time(), 'notes' => $notes];
        }
        $due = $order['completed_at'] ? (int)$order['completed_at'] + (int)$rules['settlement_days'] * 86400 : 0;
        $state = $due && $due <= time() ? 'available' : 'pending'; $total = 0;
        foreach ($awards as $award) {
            $this->db->one('SELECT id FROM users WHERE id=?' . $this->db->lock(), [(int)$award['user_id']]);
            $target = self::proportional($profit, (int)$award['bps'], 10000); $total += $target;
            $current = (int)$this->db->scalar('SELECT COALESCE(SUM(amount_cents),0) FROM ledger WHERE order_id=? AND user_id=? AND beneficiary_role=? AND state<>?', [(int)$order['id'], (int)$award['user_id'], $award['role'], 'cancelled']);
            $delta = $target - $current;
            if ($delta) $this->db->insert('ledger', ['user_id' => (int)$award['user_id'], 'order_id' => (int)$order['id'], 'beneficiary_role' => $award['role'],
                'entry_key' => 'profit:' . $order['id'] . ':' . $revision . ':' . $award['role'], 'amount_cents' => $delta,
                'state' => $state, 'available_at' => $due, 'reversal_of' => null, 'created_at' => time()]);
        }
        if ($total > $profit) throw new ApiError('Distribution cannot exceed confirmed profit.');
        $this->db->run('UPDATE ledger SET state=?,available_at=? WHERE order_id=? AND state=?', [$state, $due, (int)$order['id'], 'frozen']);
        $this->db->insert('profit_confirmations', ['order_id' => (int)$order['id'], 'revision' => $revision, 'profit_cents' => $profit,
            'notes' => $notes, 'actor_id' => (int)$user['id'], 'created_at' => time()]);
        $this->db->update('orders', $order['id'], ['profit_cents' => $profit, 'profit_status' => 'confirmed', 'profit_revision' => $revision,
            'rule_snapshot' => json_encode($snapshot), 'updated_at' => time()]);
        Policy::audit($this->db, $user, 'profit.confirm', 'order', $order['id'], ['revision' => $revision, 'profit_cents' => $profit, 'notes' => $notes]);
        return ['revision' => $revision, 'profit_cents' => $profit, 'rule_snapshot' => $snapshot];
    }

    public function complete(array $order)
    {
        $snapshot = json_decode($order['rule_snapshot'] ?: '', true);
        if (!$snapshot) return;
        $due = time() + (int)$snapshot['rule']['settlement_days'] * 86400;
        $this->db->run('UPDATE ledger SET available_at=? WHERE order_id=? AND state=? AND available_at=0', [$due, (int)$order['id'], 'pending']);
    }

    public function reverse(array $order, $refundId, $newRefundTotal)
    {
        // The same user lock serializes refund adjustments with withdrawal reservations.
        $beneficiaries = $this->db->all('SELECT DISTINCT user_id FROM ledger WHERE order_id=? ORDER BY user_id', [(int)$order['id']]);
        foreach ($beneficiaries as $beneficiary) $this->db->one('SELECT id FROM users WHERE id=?' . $this->db->lock(), [(int)$beneficiary['user_id']]);
        if ($newRefundTotal < (int)$order['paid_cents']) {
            $this->db->run('UPDATE ledger SET state=? WHERE order_id=? AND state IN (?,?)', ['frozen', (int)$order['id'], 'pending', 'available']);
            $this->db->update('orders', $order['id'], ['profit_cents' => null, 'profit_status' => 'needs_reconfirmation']);
            return;
        }
        $snapshot = json_decode($order['rule_snapshot'] ?: '', true);
        if ($snapshot) {
            $due = $order['completed_at'] ? (int)$order['completed_at'] + (int)$snapshot['rule']['settlement_days'] * 86400 : 0;
            $state = $due && $due <= time() ? 'available' : 'pending';
            foreach ($snapshot['beneficiaries'] as $award) {
                $this->db->one('SELECT id FROM users WHERE id=?' . $this->db->lock(), [(int)$award['user_id']]);
                $net = (int)$this->db->scalar('SELECT COALESCE(SUM(amount_cents),0) FROM ledger WHERE order_id=? AND user_id=? AND beneficiary_role=? AND state<>?', [(int)$order['id'], (int)$award['user_id'], $award['role'], 'cancelled']);
                if ($net) $this->db->insert('ledger', ['user_id' => (int)$award['user_id'], 'order_id' => (int)$order['id'], 'beneficiary_role' => $award['role'],
                    'entry_key' => 'full-refund:' . $refundId . ':' . $award['role'], 'amount_cents' => -$net, 'state' => $state,
                    'available_at' => $due, 'reversal_of' => null, 'created_at' => time()]);
            }
            $this->db->run('UPDATE ledger SET state=? WHERE order_id=? AND state=?', [$state, (int)$order['id'], 'frozen']);
            if ($state === 'pending') $this->db->run('UPDATE ledger SET state=? WHERE order_id=? AND state=?', ['cancelled', (int)$order['id'], 'pending']);
        }
        $this->db->update('orders', $order['id'], ['profit_cents' => 0, 'profit_status' => 'refunded']);
    }

    public function settle($userId = null)
    {
        $params = ['available', 'pending', time()];
        if ($userId !== null) $params[] = (int)$userId;
        return $this->db->run('UPDATE ledger SET state=? WHERE state=? AND available_at>0 AND available_at<=?' . ($userId !== null ? ' AND user_id=?' : ''), $params)->rowCount();
    }

    public function balance($userId)
    {
        $this->settle($userId);
        $available = (int)$this->db->scalar('SELECT COALESCE(SUM(amount_cents),0) FROM ledger WHERE user_id=? AND state=?', [$userId, 'available']);
        $held = (int)$this->db->scalar('SELECT COALESCE(SUM(amount_cents),0) FROM withdrawals WHERE user_id=? AND status IN (?,?,?)', [$userId, 'pending', 'approved', 'paid']);
        $pending = (int)$this->db->scalar('SELECT COALESCE(SUM(amount_cents),0) FROM ledger WHERE user_id=? AND state=?', [$userId, 'pending']);
        $net = $available - $held;
        return ['available_cents' => max(0, $net), 'pending_cents' => $pending, 'debt_cents' => max(0, -$net), 'withdrawn_and_reserved_cents' => $held];
    }

    public function ledger(array $user, array $query)
    {
        list($where, $params) = $this->scope($user, $query);
        $this->settle();
        $available = (int)$this->db->scalar('SELECT COALESCE(SUM(amount_cents),0) FROM ledger WHERE ' . $where . ' AND state=?', array_merge($params, ['available']));
        $held = (int)$this->db->scalar('SELECT COALESCE(SUM(amount_cents),0) FROM withdrawals WHERE ' . $where . ' AND status IN (?,?,?)', array_merge($params, ['pending', 'approved', 'paid']));
        $pending = (int)$this->db->scalar('SELECT COALESCE(SUM(amount_cents),0) FROM ledger WHERE ' . $where . ' AND state=?', array_merge($params, ['pending']));
        $balance = ['available_cents' => max(0, $available - $held), 'pending_cents' => $pending, 'debt_cents' => max(0, $held - $available), 'withdrawn_and_reserved_cents' => $held];
        $list = $this->db->paginate('ledger', $where, $params, $query);
        foreach ($list['items'] as &$row) foreach (['id', 'user_id', 'order_id', 'amount_cents', 'available_at', 'created_at'] as $key) $row[$key] = (int)$row[$key];
        return $list + ['balance' => $balance];
    }

    public function scope(array $user, array $query = [])
    {
        if (isset($query['user_id'])) {
            $id = Validation::id($query['user_id'], 'user_id');
            if ($user['role'] === 'agency' && !$this->db->one('SELECT id FROM users WHERE id=? AND org_id=? AND role=?', [$id, (int)$user['org_id'], 'broker'])) throw new ApiError('Beneficiary is outside your agency.', 403, 403);
            if (!in_array($user['role'], ['platform', 'agency'], true) && $id !== (int)$user['id']) throw new ApiError('Ledger access is not permitted.', 403, 403);
            return ['user_id=?', [$id]];
        }
        if ($user['role'] === 'platform') return ['1=1', []];
        if ($user['role'] === 'agency') return ['user_id IN (SELECT id FROM users WHERE org_id=? AND role=?)', [(int)$user['org_id'], 'broker']];
        return ['user_id=?', [(int)$user['id']]];
    }

    public function withdraw(array $body, array $user)
    {
        $amount = Validation::integer($body['amount_cents'] ?? null, 'amount_cents', 1, 100000000000);
        $key = Validation::key($body['idempotency_key'] ?? '');
        return $this->db->transaction(function () use ($amount, $key, $user) {
            $this->db->one('SELECT id FROM users WHERE id=?' . $this->db->lock(), [(int)$user['id']]);
            $old = $this->db->one('SELECT * FROM withdrawals WHERE user_id=? AND idempotency_key=?' . $this->db->lock(),[(int)$user['id'],$key]);
            if ($old) {
                if ((int)$old['amount_cents'] !== $amount) throw new ApiError('Withdrawal idempotency request does not match.',409,409);
                return $old;
            }
            $balance = $this->balance((int)$user['id']);
            if ($balance['debt_cents'] > 0 || $amount > $balance['available_cents']) throw new ApiError('Available balance is insufficient.', 409, 40911);
            $id = $this->db->insert('withdrawals', ['user_id' => (int)$user['id'], 'amount_cents' => $amount, 'idempotency_key'=>$key, 'status' => 'pending', 'notes' => '', 'created_at' => time(), 'updated_at' => time()]);
            Policy::audit($this->db, $user, 'withdrawal.request', 'withdrawal', $id, ['amount_cents' => $amount]);
            return $this->db->one('SELECT * FROM withdrawals WHERE id=?', [$id]);
        });
    }

    public function reviewWithdrawal($id, array $body, array $user)
    {
        Policy::roles($user, ['platform']);
        $status = Validation::choice($body['status'] ?? '', ['approved', 'rejected', 'paid'], 'status');
        if ($status === 'paid') throw new ApiError('Real payout service is not configured.', 503, 50312);
        return $this->db->transaction(function () use ($id, $body, $status, $user) {
            $row = $this->db->one('SELECT * FROM withdrawals WHERE id=?' . $this->db->lock(), [Validation::id($id)]);
            if (!$row) throw new ApiError('Withdrawal not found.', 404, 404);
            if ($row['status'] !== 'pending') throw new ApiError('Withdrawal has already been reviewed.', 409, 409);
            $this->db->update('withdrawals', $id, ['status' => $status, 'notes' => Validation::text($body['notes'] ?? '', 'notes', 2000, false), 'updated_at' => time()]);
            Policy::audit($this->db, $user, 'withdrawal.' . $status, 'withdrawal', $id);
            return $this->db->one('SELECT * FROM withdrawals WHERE id=?', [$id]);
        });
    }
}
