<?php
namespace yuzhou;

class Crm
{
    private $db;
    public function __construct(Database $db) { $this->db = $db; }

    public function dispatch($method, $path, array $body, array $query, array $user)
    {
        if (strpos($path, 'customer-tags') !== 0 && strpos($path, 'agency-targets') !== 0 && !preg_match('#^customers/[0-9]+/tags$#D', $path)) return null;
        Policy::roles($user, ['broker', 'agency', 'platform']);
        if ($path === 'customer-tags' && $method === 'GET') {
            $org = $user['role'] === 'platform' && empty($query['org_id']) ? null : $this->agency($user, $query['org_id'] ?? null);
            $where = $org ? 'org_id=?' : '1=1'; $params = $org ? [$org] : [];
            if (!empty($query['status'])) { $where .= ' AND status=?'; $params[] = Validation::choice($query['status'], ['active','inactive'], 'status'); }
            return $this->db->paginate('customer_tags', $where, $params, $query, 'name ASC,id ASC');
        }
        if (($path === 'customer-tags' && $method === 'POST') || (preg_match('#^customer-tags/([0-9]+)$#D', $path, $match) && $method === 'PATCH')) {
            Policy::roles($user, ['agency','platform']);
            return $this->saveTag($body, $user, isset($match[1]) ? (int)$match[1] : null);
        }
        if (preg_match('#^customers/([0-9]+)/tags$#D', $path, $match) && $method === 'PUT') return $this->tagCustomer((int)$match[1], $body, $user);
        if ($path === 'agency-targets' && $method === 'GET') return $this->target($this->agency($user, $query['org_id'] ?? null), $this->month($query['month'] ?? date('Y-m')));
        if (preg_match('#^agency-targets/([0-9]{4}-[0-9]{2})$#D', $path, $match) && $method === 'PUT') {
            Policy::roles($user, ['agency','platform']);
            $month = $this->month($match[1]); $org = $this->agency($user, $body['org_id'] ?? null); $amount = Validation::money($body['target_amount_cents'] ?? null, 'target_amount_cents');
            return $this->db->transaction(function () use ($month, $org, $amount, $user) {
                $this->db->one('SELECT id FROM organizations WHERE id=?' . $this->db->lock(), [$org]);
                if ($this->db->one('SELECT org_id FROM agency_monthly_targets WHERE org_id=? AND month=?' . $this->db->lock(), [$org,$month])) $this->db->run('UPDATE agency_monthly_targets SET target_amount_cents=?,updated_by=?,updated_at=? WHERE org_id=? AND month=?', [$amount,(int)$user['id'],time(),$org,$month]);
                else $this->db->insert('agency_monthly_targets', ['org_id'=>$org,'month'=>$month,'target_amount_cents'=>$amount,'updated_by'=>(int)$user['id'],'updated_at'=>time()]);
                Policy::audit($this->db, $user, 'agency.target', 'organization', $org, ['month'=>$month,'target_amount_cents'=>$amount]);
                return $this->target($org,$month);
            });
        }
        return null;
    }

    private function agency(array $user, $requested = null)
    {
        $id = $user['role'] === 'platform' ? Validation::id($requested ?? 0,'org_id') : Validation::id($user['org_id'] ?? 0,'org_id');
        if ($requested !== null && Validation::id($requested,'org_id') !== $id) throw new ApiError('Agency is outside your organization.',403,403);
        $org = $this->db->one('SELECT id FROM organizations WHERE id=? AND kind=? AND status=?', [$id,'agency','approved']);
        if (!$org) throw new ApiError('Approved agency not found.',404,404);
        return $id;
    }

    private function saveTag(array $body, array $user, $id)
    {
        return $this->db->transaction(function () use ($body,$user,$id) {
            $old = $id ? $this->db->one('SELECT * FROM customer_tags WHERE id=?' . $this->db->lock(),[$id]) : null;
            if ($id && !$old) throw new ApiError('Customer tag not found.',404,404);
            $org = $this->agency($user,$body['org_id'] ?? ($old ? (int)$old['org_id'] : null));
            if ($old && (int)$old['org_id'] !== $org) throw new ApiError('Tag organization is immutable.',403,403);
            $this->db->one('SELECT id FROM organizations WHERE id=?' . $this->db->lock(),[$org]);
            $name = Validation::text($body['name'] ?? ($old['name'] ?? ''),'name',80);
            $color = Validation::text($body['color'] ?? ($old['color'] ?? '#1677ff'),'color',7);
            if (!preg_match('/^#[0-9a-fA-F]{6}$/D',$color)) throw new ApiError('Tag color must be a six-digit hex color.');
            $duplicate = $this->db->one('SELECT id FROM customer_tags WHERE org_id=? AND name=? AND id<>?' . $this->db->lock(),[$org,$name,$id ?: 0]);
            if ($duplicate) throw new ApiError('A tag with this name already exists in this agency.',409,409);
            $values = ['name'=>$name,'color'=>strtolower($color),'status'=>Validation::choice($body['status'] ?? ($old['status'] ?? 'active'),['active','inactive'],'status'),'updated_at'=>time()];
            if ($old) $this->db->update('customer_tags',$id,$values);
            else $id = $this->db->insert('customer_tags',$values+['org_id'=>$org,'created_by'=>(int)$user['id'],'created_at'=>time()]);
            Policy::audit($this->db,$user,'customer_tag.save','customer_tag',$id);
            return $this->db->one('SELECT * FROM customer_tags WHERE id=?',[$id]);
        });
    }

    public function customer(array $row)
    {
        $row['tags'] = $this->db->all('SELECT t.id,t.name,t.color,t.status FROM customer_tag_links l JOIN customer_tags t ON t.id=l.tag_id WHERE l.customer_id=? ORDER BY t.name,t.id',[(int)$row['id']]);
        foreach ($row['tags'] as &$tag) $tag['id'] = (int)$tag['id'];
        return $row;
    }

    private function tagCustomer($id,array $body,array $user)
    {
        $ids = $body['tag_ids'] ?? null;
        if (!is_array($ids) || count($ids)>20) throw new ApiError('tag_ids must contain at most twenty tags.');
        $ids = array_values(array_unique(array_map(function ($id) { return Validation::id($id,'tag_id'); },$ids))); sort($ids);
        return $this->db->transaction(function () use ($id,$ids,$user) {
            $row = $this->db->one('SELECT * FROM customers WHERE id=?' . $this->db->lock(),[$id]);
            if (!$row || ($user['role']==='broker' && (int)$row['broker_id']!==(int)$user['id']) || ($user['role']==='agency' && (int)$row['org_id']!==(int)$user['org_id'])) throw new ApiError('Customer not found.',404,404);
            foreach ($ids as $tagId) if (!$this->db->one('SELECT id FROM customer_tags WHERE id=? AND org_id=? AND status=?' . $this->db->lock(),[$tagId,(int)$row['org_id'],'active'])) throw new ApiError('Customer tags must be active and belong to the same agency.',403,403);
            $this->db->run('DELETE FROM customer_tag_links WHERE customer_id=?',[$id]);
            foreach ($ids as $tagId) $this->db->insert('customer_tag_links',['customer_id'=>$id,'tag_id'=>$tagId,'created_by'=>(int)$user['id'],'created_at'=>time()]);
            Policy::audit($this->db,$user,'customer.tags','customer',$id,['tag_ids'=>$ids]);
            return $this->customer($row);
        });
    }

    private function month($value)
    {
        if (!is_string($value) || !preg_match('/^(20[0-9]{2})-(0[1-9]|1[0-2])$/D',$value)) throw new ApiError('month must be YYYY-MM.');
        return $value;
    }

    private function target($org,$month)
    {
        $row = $this->db->one('SELECT * FROM agency_monthly_targets WHERE org_id=? AND month=?',[$org,$month]);
        $start = strtotime($month.'-01 00:00:00'); $end = strtotime('+1 month',$start);
        $paid = (int)$this->db->scalar('SELECT COALESCE(SUM(p.amount_cents),0) FROM payments p JOIN orders o ON o.id=p.order_id WHERE o.org_id=? AND o.broker_id IS NOT NULL AND p.status=? AND p.created_at>=? AND p.created_at<?',[$org,'succeeded',$start,$end]);
        $refund = (int)$this->db->scalar('SELECT COALESCE(SUM(r.amount_cents),0) FROM refunds r JOIN orders o ON o.id=r.order_id WHERE o.org_id=? AND o.broker_id IS NOT NULL AND r.status=? AND r.created_at>=? AND r.created_at<?',[$org,'succeeded',$start,$end]);
        return ['org_id'=>$org,'month'=>$month,'configured'=>(bool)$row,'target_amount_cents'=>$row ? (int)$row['target_amount_cents'] : 0,'actual_paid_cents'=>$paid,'actual_refund_cents'=>$refund,'actual_net_paid_cents'=>$paid-$refund];
    }
}
