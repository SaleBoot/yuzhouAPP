<?php
namespace yuzhou;

class Policy
{
    public static function roles(array $user, array $roles)
    {
        if (!in_array($user['role'], $roles, true)) throw new ApiError('You do not have permission for this action.', 403, 403);
    }

    public static function orderVisible(array $user, array $order)
    {
        if ($user['role'] === 'platform' || (int)$order['customer_id'] === (int)$user['id']) return true;
        if ($user['role'] === 'broker') return (int)$order['broker_id'] === (int)$user['id'];
        if ($user['role'] === 'agency') return (int)$order['broker_id'] > 0 && (int)$order['org_id'] === (int)$user['org_id'] && (int)$user['org_id'] > 0;
        if ($user['role'] === 'merchant') return !$order['broker_id'] && (int)$order['org_id'] === (int)$user['org_id'] && (int)$user['org_id'] > 0;
        return false;
    }

    public static function orderOperator(array $user, array $order)
    {
        if ($user['role'] === 'platform') return;
        if (!self::orderVisible($user, $order) || !in_array($user['role'], ['broker', 'agency', 'merchant'], true)) throw new ApiError('Order operation is not permitted.', 403, 403);
        if ($order['type'] === 'mall' && $user['role'] !== 'platform') throw new ApiError('Mall orders are fulfilled by the platform.', 403, 403);
    }

    public static function orderScope(array $user)
    {
        switch ($user['role']) {
            case 'platform': return ['1=1', []];
            case 'broker': return ['broker_id=?', [(int)$user['id']]];
            case 'agency': return ['org_id=? AND broker_id IS NOT NULL AND broker_id>0', [(int)$user['org_id']]];
            case 'merchant': return ['org_id=? AND (broker_id IS NULL OR broker_id=0)', [(int)$user['org_id']]];
            default: return ['customer_id=?', [(int)$user['id']]];
        }
    }

    public static function audit(Database $db, array $user, $action, $type, $id, array $payload = [])
    {
        $db->insert('audit_logs', ['actor_id' => (int)$user['id'], 'org_id' => $user['org_id'] ?: null, 'action' => $action,
            'object_type' => $type, 'object_id' => (int)$id, 'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'created_at' => time()]);
    }
}
