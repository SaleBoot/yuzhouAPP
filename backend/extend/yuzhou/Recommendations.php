<?php
namespace yuzhou;

class Recommendations
{
    private $db;
    public function __construct(Database $db) { $this->db = $db; }

    public function listing(array $query, $user)
    {
        $limit = Validation::integer($query['limit'] ?? 6, 'limit', 1, 24);
        $preferences = [];
        if ($user && $user['role'] === 'consumer') {
            $preferences = $this->db->all('SELECT COALESCE(d.category,m.category) AS category, SUM(CASE h.kind WHEN ? THEN 3 ELSE 1 END) AS score FROM (SELECT kind,resource_id FROM user_records WHERE user_id=? AND status=? AND kind IN (?,?) ORDER BY updated_at DESC LIMIT 100) h JOIN resources r ON r.id=h.resource_id AND r.status=? LEFT JOIN boat_types d ON d.resource_id=r.id LEFT JOIN boats b ON b.resource_id=r.id LEFT JOIN boat_types m ON m.resource_id=b.boat_type_id WHERE COALESCE(d.category,m.category) IS NOT NULL GROUP BY COALESCE(d.category,m.category)', ['favorite', (int)$user['id'], 'active', 'favorite', 'footprint', 'published']);
        }
        $rank = '0'; $params = [];
        if ($preferences) {
            $rank = 'CASE b.category';
            foreach ($preferences as $preference) { $rank .= ' WHEN ? THEN ?'; $params[] = $preference['category']; $params[] = (int)$preference['score']; }
            $rank .= ' ELSE 0 END';
        }
        $params[] = 'boat_types'; $params[] = 'published';
        $rows = $this->db->all('SELECT r.id,' . $rank . ' AS preference_score FROM resources r JOIN boat_types b ON b.resource_id=r.id WHERE r.kind=? AND r.status=? ORDER BY preference_score DESC,r.created_at DESC,r.id DESC LIMIT ' . $limit, $params);
        $catalog = new Catalog($this->db); $items = [];
        foreach ($rows as $row) $items[] = $catalog->get((int)$row['id'], 'boat_types', null);
        return ['items' => $items, 'based_on' => $preferences ? 'own_favorites_and_views' : 'recently_published', 'personalized' => (bool)$preferences];
    }
}
