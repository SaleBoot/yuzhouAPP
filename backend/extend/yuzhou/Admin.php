<?php
namespace yuzhou;

class Admin
{
    private $db;
    public function __construct(Database $db) { $this->db = $db; }

    public function users(array $query, array $user)
    {
        Policy::roles($user, ['platform', 'agency']);
        $where = $user['role'] === 'platform' ? '1=1' : 'org_id=?';
        $params = $user['role'] === 'platform' ? [] : [(int)$user['org_id']];
        if (!empty($query['search'])) { $where .= ' AND (nickname LIKE ? OR username LIKE ?)'; $search = '%' . Validation::text($query['search'], 'search', 100) . '%'; $params[] = $search; $params[] = $search; }
        $list = $this->db->paginate('users', $where, $params, $query);
        $list['items'] = array_map(['yuzhou\\Auth', 'publicUser'], $list['items']);
        return $list;
    }

    public function updateUser($id, array $body, array $actor)
    {
        Policy::roles($actor, ['platform', 'agency']);
        return $this->db->transaction(function () use ($id, $body, $actor) {
            $user = $this->db->one('SELECT * FROM users WHERE id=?' . $this->db->lock(), [Validation::id($id)]);
            if (!$user) throw new ApiError('User not found.', 404, 404);
            if ($actor['role'] === 'agency') {
                if ($user['role'] !== 'broker' || (int)$user['org_id'] !== (int)$actor['org_id']) throw new ApiError('Broker belongs to another organization.', 403, 403);
                foreach (array_keys($body) as $key) if (!in_array($key, ['nickname', 'status'], true)) throw new ApiError('Agency managers can only update broker nickname/status.', 403, 403);
                $values = ['nickname' => Validation::text($body['nickname'] ?? $user['nickname'], 'nickname', 120),
                    'status' => Validation::choice($body['status'] ?? $user['status'], ['active', 'suspended'], 'status'), 'updated_at' => time()];
                $this->db->update('users', $id, $values);
                if ($values['status'] !== $user['status']) $this->db->run('DELETE FROM tokens WHERE user_id=?', [(int)$id]);
                Policy::audit($this->db, $actor, 'agency.broker.update', 'user', $id, $values);
                return Auth::publicUser($this->db->one('SELECT * FROM users WHERE id=?', [(int)$id]));
            }
            $role = Validation::choice($body['role'] ?? $user['role'], ['consumer', 'broker', 'merchant', 'agency', 'platform'], 'role');
            $orgId = array_key_exists('org_id', $body) ? ($body['org_id'] ? Validation::id($body['org_id'], 'org_id') : null) : ($user['org_id'] ?: null);
            if (in_array($role, ['broker', 'merchant', 'agency'], true)) {
                $org = $this->db->one('SELECT * FROM organizations WHERE id=?', [$orgId]);
                if (!$org || $org['status'] !== 'approved' || $org['kind'] !== ($role === 'merchant' ? 'merchant' : 'agency')) throw new ApiError('Role requires an approved organization of the correct type.');
                if ($role === 'merchant' && !$this->db->one('SELECT credit_code FROM merchants WHERE organization_id=? AND status=?', [$orgId, 'approved'])) throw new ApiError('Approved merchant license is required.');
            } elseif ($orgId) throw new ApiError('Consumer/platform accounts do not belong to merchant or agency organizations.');
            $status = Validation::choice($body['status'] ?? $user['status'], ['active', 'suspended'], 'status');
            if (($role !== $user['role'] || (string)$orgId !== (string)$user['org_id']) && array_diff(Rbac::$keys, (new Rbac($this->db))->effective($actor))) throw new ApiError('Identity and organization changes require full platform access.', 403, 403);
            if ((int)$user['id'] === (int)$actor['id'] && ($role !== 'platform' || $status !== 'active')) throw new ApiError('You cannot remove your own platform access.');
            $values = ['role' => $role, 'org_id' => $orgId, 'status' => $status, 'updated_at' => time()];
            if (isset($body['nickname'])) $values['nickname'] = Validation::text($body['nickname'], 'nickname', 120);
            if (!empty($body['grant_membership'])) {
                $start = max(time(), (int)$user['member_until']);
                $values['member_until'] = (new \DateTimeImmutable('@' . $start))->modify('+6 months')->getTimestamp();
            }
            if (array_key_exists('member_until', $body)) throw new ApiError('Use grant_membership to grant the fixed six-month membership.');
            $this->db->update('users', $id, $values);
            if ($role !== $user['role'] || $orgId !== ($user['org_id'] ?: null) || $status !== $user['status']) $this->db->run('DELETE FROM tokens WHERE user_id=?', [(int)$id]);
            Policy::audit($this->db, $actor, 'user.update', 'user', $id, $values);
            return Auth::publicUser($this->db->one('SELECT * FROM users WHERE id=?', [(int)$id]));
        });
    }

    public function organizations(array $query, array $user)
    {
        Policy::roles($user, ['platform']);
        $list = $this->db->paginate('organizations', '1=1', [], $query);
        foreach ($list['items'] as &$row) { $row['payload'] = json_decode($row['payload'], true) ?: []; $row['merchant'] = $this->db->one('SELECT * FROM merchants WHERE organization_id=?', [(int)$row['id']]); }
        return $list;
    }

    public function organization(array $body, array $actor, $id = null)
    {
        Policy::roles($actor, ['platform']);
        return $this->db->transaction(function () use ($body, $actor, $id) {
            $old = $id ? $this->db->one('SELECT * FROM organizations WHERE id=?' . $this->db->lock(), [Validation::id($id)]) : null;
            if ($id && !$old) throw new ApiError('Organization not found.', 404, 404);
            $kind = Validation::choice($body['kind'] ?? ($old ? $old['kind'] : ''), ['merchant', 'agency'], 'organization kind');
            if ($old && $kind !== $old['kind']) throw new ApiError('Organization type cannot change.');
            $name = Validation::text($body['name'] ?? ($old ? $old['name'] : ''), 'name', 200);
            $status = Validation::choice($body['status'] ?? ($old ? $old['status'] : 'pending'), ['pending', 'approved', 'rejected', 'suspended'], 'status');
            if (!$old) $id = $this->db->insert('organizations', ['name' => $name, 'kind' => $kind, 'status' => 'pending', 'payload' => '{}', 'created_at' => time()]);
            if ($kind === 'merchant' && isset($body['credit_code'])) $this->merchant($id, $body, $actor, $status);
            if ($kind === 'merchant' && $status === 'approved' && !$this->db->one('SELECT credit_code FROM merchants WHERE organization_id=? AND status=?', [(int)$id, 'approved'])) throw new ApiError('Approve the 18-character business license before this organization.');
            $this->db->update('organizations', $id, ['name' => $name, 'status' => $status]);
            Policy::audit($this->db, $actor, 'organization.' . $status, 'organization', $id);
            return $this->db->one('SELECT * FROM organizations WHERE id=?', [(int)$id]);
        });
    }

    private function merchant($orgId, array $body, array $actor, $status)
    {
        $credit = Validation::creditCode($body['credit_code']);
        $fileId = Validation::id($body['license_file_id'] ?? 0, 'license_file_id');
        $file = $this->db->one('SELECT * FROM uploads WHERE id=? AND is_private=1', [$fileId]);
        if (!$file || !in_array($file['mime'], ['image/jpeg', 'image/png', 'application/pdf'], true)) throw new ApiError('A private business-license file is required.');
        $old = $this->db->one('SELECT * FROM merchants WHERE organization_id=?', [(int)$orgId]);
        if ($old && $old['credit_code'] !== $credit) throw new ApiError('Merchant primary credit code cannot change. Create the correct merchant record.');
        $values = ['organization_id' => (int)$orgId, 'legal_name' => Validation::text($body['legal_name'] ?? '', 'legal_name', 200), 'license_file_id' => $fileId, 'status' => $status];
        if (!$old) $this->db->insert('merchants', ['credit_code' => $credit, 'created_at' => time()] + $values);
        else $this->db->run('UPDATE merchants SET legal_name=?,license_file_id=?,status=? WHERE credit_code=?', [$values['legal_name'], $fileId, $status, $credit]);
    }

    public function apply(array $body, array $user)
    {
        $kind = Validation::choice($body['kind'] ?? '', ['merchant', 'agency', 'broker', 'identity'], 'application kind');
        $payload = $body['payload'] ?? []; $files = $body['file_ids'] ?? [];
        if (!is_array($payload) || strlen(json_encode($payload)) > 20000 || !is_array($files) || count($files) < 1 || count($files) > 10) throw new ApiError('Application requires details and 1 to 10 private identity/license files.');
        return $this->db->transaction(function () use ($kind, $payload, $files, $body, $user) {
            if ($this->db->one('SELECT id FROM applications WHERE user_id=? AND kind=? AND status=?', [(int)$user['id'], $kind, 'pending'])) throw new ApiError('An application is already awaiting review.', 409, 409);
            foreach ($files as $fileId) {
                $file = $this->db->one('SELECT * FROM uploads WHERE id=? AND owner_id=? AND is_private=1', [Validation::id($fileId, 'file_id'), (int)$user['id']]);
                if (!$file) throw new ApiError('Private application files must belong to you.', 403, 40320);
            }
            $orgId = empty($body['org_id']) ? null : Validation::id($body['org_id'], 'org_id');
            if ($kind === 'broker') {
                $org = $this->db->one('SELECT * FROM organizations WHERE id=? AND kind=? AND status=?', [$orgId, 'agency', 'approved']);
                if (!$org) throw new ApiError('Choose an approved agency.');
            }
            $id = $this->db->insert('applications', ['user_id' => (int)$user['id'], 'kind' => $kind, 'org_id' => $orgId, 'status' => 'pending', 'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'file_ids' => '[]', 'review_notes' => '', 'reviewer_id' => null, 'created_at' => time(), 'updated_at' => time()]);
            foreach (array_unique($files) as $fileId) $this->db->insert('application_files', ['application_id' => $id, 'file_id' => (int)$fileId]);
            if ($kind === 'identity') $this->db->update('users', $user['id'], ['identity_status' => 'pending', 'updated_at' => time()]);
            Policy::audit($this->db, $user, 'application.submit', 'application', $id, ['kind' => $kind]);
            return $this->application($id);
        });
    }

    public function application($id)
    {
        $row = $this->db->one('SELECT * FROM applications WHERE id=?', [(int)$id]);
        if (!$row) throw new ApiError('Application not found.', 404, 404);
        $row['payload'] = json_decode($row['payload'], true) ?: [];
        $row['file_ids'] = array_map('intval', array_column($this->db->all('SELECT file_id FROM application_files WHERE application_id=?', [(int)$id]), 'file_id'));
        return $row;
    }

    public function applications(array $query, array $user)
    {
        $list = $this->db->paginate('applications', $user['role'] === 'platform' ? '1=1' : 'user_id=?', $user['role'] === 'platform' ? [] : [(int)$user['id']], $query);
        foreach ($list['items'] as &$row) $row = $this->application($row['id']);
        return $list;
    }

    public function reviewApplication($id, array $body, array $actor)
    {
        Policy::roles($actor, ['platform']);
        return $this->db->transaction(function () use ($id, $body, $actor) {
            $row = $this->db->one('SELECT * FROM applications WHERE id=?' . $this->db->lock(), [Validation::id($id)]);
            if (!$row || $row['status'] !== 'pending') throw new ApiError('Application is not awaiting review.', 409, 409);
            $status = Validation::choice($body['status'] ?? '', ['approved', 'rejected'], 'status');
            $payload = json_decode($row['payload'], true) ?: [];
            if ($status === 'approved') {
                $values = ['updated_at' => time()];
                if ($row['kind'] === 'identity') $values['identity_status'] = 'verified';
                elseif ($row['kind'] === 'broker') { $values['role'] = 'broker'; $values['org_id'] = (int)$row['org_id']; }
                else {
                    $files = $this->db->all('SELECT file_id FROM application_files WHERE application_id=?', [(int)$row['id']]);
                    $org = $this->organization(['kind' => $row['kind'], 'name' => $payload['organization_name'] ?? '', 'status' => 'approved',
                        'credit_code' => $payload['credit_code'] ?? null, 'legal_name' => $payload['legal_name'] ?? '', 'license_file_id' => $files ? (int)$files[0]['file_id'] : 0], $actor);
                    $values['role'] = $row['kind']; $values['org_id'] = (int)$org['id'];
                }
                $this->db->update('users', $row['user_id'], $values);
                $this->db->run('DELETE FROM tokens WHERE user_id=?', [(int)$row['user_id']]);
            } elseif ($row['kind'] === 'identity') $this->db->update('users', $row['user_id'], ['identity_status' => 'rejected', 'updated_at' => time()]);
            $this->db->update('applications', $id, ['status' => $status, 'review_notes' => Validation::text($body['notes'] ?? '', 'notes', 2000, false), 'reviewer_id' => (int)$actor['id'], 'updated_at' => time()]);
            Policy::audit($this->db, $actor, 'application.' . $status, 'application', $id);
            return $this->application($id);
        });
    }
}
