<?php
namespace yuzhou;

class Rbac
{
    private $db;
    public static $keys = ['catalog', 'orders', 'finance', 'settings', 'users', 'organizations', 'applications', 'customers', 'community', 'learning', 'operations', 'communication', 'reports', 'rbac'];
    public function __construct(Database $db) { $this->db = $db; }

    public function effective(array $user)
    {
        if (!in_array($user['role'], ['merchant', 'agency', 'platform'], true)) return [];
        if (!$this->db->one('SELECT role_id FROM management_user_roles WHERE user_id=?', [(int)$user['id']])) return self::$keys;
        return array_column($this->db->all('SELECT DISTINCT p.permission_key FROM management_user_roles ur JOIN users u ON u.id=ur.user_id JOIN management_roles r ON r.id=ur.role_id JOIN management_role_permissions p ON p.role_id=r.id WHERE ur.user_id=? AND r.status=? AND r.base_role=? AND (r.org_id=u.org_id OR (r.org_id IS NULL AND u.org_id IS NULL))', [(int)$user['id'], 'active', $user['role']]), 'permission_key');
    }

    public function guard($path, $user)
    {
        if (!$user || !in_array($user['role'], ['merchant', 'agency', 'platform'], true)) return;
        if (preg_match('#^(auth/|bootstrap$|health$|files/|uploads$)#', $path)) return;
        $key = null;
        if (preg_match('#^admin/(roles|permission-keys)#', $path) || preg_match('#^admin/users/[0-9]+/roles$#', $path)) $key = 'rbac';
        elseif (strpos($path, 'settings') === 0) $key = 'settings';
        elseif (preg_match('#^(ledger|withdrawals|admin/refund-requests)#', $path)) $key = 'finance';
        elseif (strpos($path, 'reports') === 0) $key = 'reports';
        elseif (strpos($path, 'admin/users') === 0) $key = 'users';
        elseif (strpos($path, 'admin/organizations') === 0) $key = 'organizations';
        elseif (strpos($path, 'applications') === 0) $key = 'applications';
        elseif (preg_match('#^(customers|customer-tags|agency-targets)#', $path)) $key = 'customers';
        elseif (preg_match('#^(resources/posts|posts/|comments/|admin/comments|events/|enrollments)#', $path)) $key = 'community';
        elseif (preg_match('#^(resources/growth_products|resources/banners|points-shop)#', $path)) $key = 'operations';
        elseif (preg_match('#^(learning/|admin/course-rewards)#', $path)) $key = 'learning';
        elseif (strpos($path, 'resources/') === 0 || $path === 'inventory') $key = 'catalog';
        elseif (preg_match('#^orders/[0-9]+/(profit-confirm|refund)$#D', $path)) $key = 'finance';
        elseif (strpos($path, 'orders') === 0) $key = 'orders';
        elseif (preg_match('#^(conversations|appointments|notifications|favorites|footprints|boat-records|reminders|feedback|reviews)#', $path)) $key = 'communication';
        elseif ($path === 'admin/audit') $key = 'users';
        if ($key && !in_array($key, $this->effective($user), true)) throw new ApiError('This management sub-role does not permit this module.', 403, 40330);
    }

    public function dispatch($method, $path, array $body, array $query, array $user)
    {
        if (strpos($path, 'admin/roles') !== 0 && $path !== 'admin/permission-keys' && !preg_match('#^admin/users/[0-9]+/roles$#D', $path)) return null;
        Policy::roles($user, ['platform']);
        if ($path === 'admin/permission-keys' && $method === 'GET') return ['items' => self::$keys];
        if ($path === 'admin/roles' && $method === 'GET') {
            $list = $this->db->paginate('management_roles', '1=1', [], $query);
            foreach ($list['items'] as &$role) $role['permissions'] = array_column($this->db->all('SELECT permission_key FROM management_role_permissions WHERE role_id=?', [(int)$role['id']]), 'permission_key');
            return $list;
        }
        if (($path === 'admin/roles' && $method === 'POST') || (preg_match('#^admin/roles/([0-9]+)$#D', $path, $match) && $method === 'PATCH')) return $this->save($body, $user, isset($match[1]) ? (int)$match[1] : null);
        if (preg_match('#^admin/users/([0-9]+)/roles$#D', $path, $match) && in_array($method, ['GET', 'PUT'], true)) {
            $target = $this->db->one('SELECT * FROM users WHERE id=?', [(int)$match[1]]);
            if (!$target || !in_array($target['role'], ['merchant', 'agency', 'platform'], true)) throw new ApiError('Select a management account.', 404, 404);
            if ($method === 'GET') return ['role_ids' => array_column($this->db->all('SELECT role_id FROM management_user_roles WHERE user_id=?', [(int)$target['id']]), 'role_id'), 'permissions' => $this->effective($target)];
            return $this->assign($target, $body, $user);
        }
        return null;
    }

    private function save(array $body, array $actor, $id)
    {
        return $this->db->transaction(function () use ($body, $actor, $id) {
            $old = $id ? $this->db->one('SELECT * FROM management_roles WHERE id=?' . $this->db->lock(), [$id]) : null;
            if ($id && !$old) throw new ApiError('Management role not found.', 404, 404);
            if ($id && $this->db->one('SELECT user_id FROM management_user_roles WHERE role_id=? AND user_id=?', [$id, (int)$actor['id']])) throw new ApiError('You cannot change your own assigned role.', 409, 409);
            if ($old && array_diff(array_column($this->db->all('SELECT permission_key FROM management_role_permissions WHERE role_id=?', [$id]), 'permission_key'), $this->effective($actor))) throw new ApiError('You cannot edit a role with higher permissions than your own.', 403, 403);
            $base = Validation::choice($body['base_role'] ?? ($old['base_role'] ?? ''), ['merchant', 'agency', 'platform'], 'base_role');
            $org = array_key_exists('org_id', $body) ? ($body['org_id'] ? Validation::id($body['org_id']) : null) : ($old ? $old['org_id'] : null);
            if ($base !== 'platform') {
                $organization = $this->db->one('SELECT id,kind,status FROM organizations WHERE id=?', [$org]);
                if (!$organization || $organization['status'] !== 'approved' || $organization['kind'] !== ($base === 'merchant' ? 'merchant' : 'agency')) throw new ApiError('Role requires the matching approved organization.');
            } elseif ($org !== null) throw new ApiError('Platform roles have no merchant or agency organization.');
            if ($old && ($base !== $old['base_role'] || (string)$org !== (string)$old['org_id'])) throw new ApiError('Role identity and organization are immutable.');
            $permissions = $body['permissions'] ?? ($old ? array_column($this->db->all('SELECT permission_key FROM management_role_permissions WHERE role_id=?', [$id]), 'permission_key') : []);
            if (!is_array($permissions) || array_diff($permissions, self::$keys) || array_diff($permissions, $this->effective($actor))) throw new ApiError('Cannot delegate permissions you do not have.', 403, 403);
            $values = ['name' => Validation::text($body['name'] ?? ($old['name'] ?? ''), 'role name', 100), 'status' => Validation::choice($body['status'] ?? ($old['status'] ?? 'active'), ['active', 'inactive'], 'status'), 'updated_at' => time()];
            if ($old) $this->db->update('management_roles', $id, $values);
            else $id = $this->db->insert('management_roles', $values + ['org_id' => $org, 'base_role' => $base, 'created_by' => (int)$actor['id'], 'created_at' => time()]);
            $this->db->run('DELETE FROM management_role_permissions WHERE role_id=?', [$id]);
            foreach (array_unique($permissions) as $key) $this->db->insert('management_role_permissions', ['role_id' => $id, 'permission_key' => $key]);
            Policy::audit($this->db, $actor, 'management_role.save', 'management_role', $id, ['permissions' => $permissions]);
            return $this->db->one('SELECT * FROM management_roles WHERE id=?', [$id]) + ['permissions' => array_values(array_unique($permissions))];
        });
    }

    private function assign(array $target, array $body, array $actor)
    {
        if ((int)$target['id'] === (int)$actor['id']) throw new ApiError('You cannot replace your own management access.', 409, 409);
        if (array_diff($this->effective($target), $this->effective($actor))) throw new ApiError('You cannot replace higher management access.', 403, 403);
        $ids = $body['role_ids'] ?? null;
        if (!is_array($ids) || count($ids) > 10) throw new ApiError('Invalid role_ids.');
        if (!$ids && array_diff(self::$keys, $this->effective($actor))) throw new ApiError('Only full management access can restore baseline permissions.', 403, 403);
        return $this->db->transaction(function () use ($target, $ids, $actor) {
            $this->db->one('SELECT id FROM users WHERE id=?' . $this->db->lock(), [(int)$target['id']]);
            $roles = [];
            foreach (array_unique($ids) as $id) {
                $role = $this->db->one('SELECT * FROM management_roles WHERE id=? AND status=?', [Validation::id($id), 'active']);
                if (!$role || $role['base_role'] !== $target['role'] || (string)$role['org_id'] !== (string)$target['org_id']) throw new ApiError('Role does not match this account identity and organization.', 403, 403);
                $permissions = array_column($this->db->all('SELECT permission_key FROM management_role_permissions WHERE role_id=?', [(int)$role['id']]), 'permission_key');
                if (array_diff($permissions, $this->effective($actor))) throw new ApiError('Cannot delegate permissions you do not have.', 403, 403);
                $roles[] = (int)$role['id'];
            }
            $this->db->run('DELETE FROM management_user_roles WHERE user_id=?', [(int)$target['id']]);
            foreach ($roles as $id) $this->db->insert('management_user_roles', ['user_id' => (int)$target['id'], 'role_id' => $id]);
            Policy::audit($this->db, $actor, 'management_roles.assign', 'user', $target['id'], ['role_ids' => $roles]);
            return ['role_ids' => $roles, 'permissions' => $this->effective($target)];
        });
    }
}
