<?php
namespace yuzhou;

class Reports
{
    private $db;
    public function __construct(Database $db) { $this->db = $db; }

    public function dispatch($method, $path, array $query, array $user)
    {
        if ($method !== 'GET') return null;
        if ($path === 'reports/summary') return $this->summary($query, $user);
        if ($path === 'reports/dividends.xlsx') return $this->dividends($query, $user);
        if (preg_match('#^admin/users/([1-9][0-9]*)/history$#D', $path, $match)) {
            Policy::roles($user, ['platform']);
            $person = $this->db->one('SELECT * FROM users WHERE id=?', [(int)$match[1]]);
            if (!$person) throw new ApiError('用户不存在。', 404, 404);
            $orders = $this->db->paginate('orders', 'customer_id=?', [(int)$person['id']], $query);
            foreach ($orders['items'] as &$order) $order['items'] = json_decode($order['items'], true) ?: [];
            $boats = $this->db->all('SELECT id,title,payload,created_at FROM user_records WHERE user_id=? AND kind=? AND status<>? ORDER BY id DESC', [(int)$person['id'], 'boat', 'deleted']);
            foreach ($boats as &$boat) $boat['payload'] = json_decode($boat['payload'], true) ?: [];
            return ['user' => Auth::publicUser($person), 'orders' => $orders, 'boat_records' => $boats];
        }
        return null;
    }

    private function dates(array $query, $where, array $params)
    {
        foreach (['from_date' => '>=', 'to_date' => '<'] as $key => $operator) {
            if (empty($query[$key])) continue;
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string)$query[$key]);
            if (!$date || $date->format('Y-m-d') !== $query[$key]) throw new ApiError('日期格式应为YYYY-MM-DD。');
            if ($key === 'to_date') $date = $date->modify('+1 day');
            $where .= ' AND created_at' . $operator . '?'; $params[] = $date->getTimestamp();
        }
        if (!empty($query['from_date']) && !empty($query['to_date']) && $query['from_date'] > $query['to_date']) throw new ApiError('开始日期不能晚于结束日期。');
        return [$where, $params];
    }

    public function summary(array $query, array $user)
    {
        list($where, $params) = Policy::orderScope($user);
        list($where, $params) = $this->dates($query, $where, $params);
        $totals = $this->db->one('SELECT COUNT(*) AS order_count,COALESCE(SUM(paid_cents),0) AS paid_cents,COALESCE(SUM(refund_cents),0) AS refund_cents,COALESCE(SUM(CASE WHEN profit_status=? THEN profit_cents ELSE 0 END),0) AS profit_cents FROM orders WHERE ' . $where, array_merge(['confirmed'], $params));
        foreach ($totals as &$value) $value = (int)$value;
        $totals['net_paid_cents'] = $totals['paid_cents'] - $totals['refund_cents'];
        $totals['status_counts'] = [];
        foreach ($this->db->all('SELECT status,COUNT(*) AS count FROM orders WHERE ' . $where . ' GROUP BY status', $params) as $row) $totals['status_counts'][$row['status']] = (int)$row['count'];
        list($ledgerWhere, $ledgerParams) = (new Finance($this->db))->scope($user);
        list($ledgerWhere, $ledgerParams) = $this->dates($query, $ledgerWhere, $ledgerParams);
        $totals['commission_cents'] = (int)$this->db->scalar('SELECT COALESCE(SUM(amount_cents),0) FROM ledger WHERE ' . $ledgerWhere . ' AND state<>?', array_merge($ledgerParams, ['cancelled']));
        $totals['frozen_commission_cents'] = (int)$this->db->scalar('SELECT COALESCE(SUM(amount_cents),0) FROM ledger WHERE ' . $ledgerWhere . ' AND state=?', array_merge($ledgerParams, ['frozen']));
        $customerWhere = '1=1'; $customerParams = [];
        if ($user['role'] === 'broker') { $customerWhere = 'broker_id=?'; $customerParams = [(int)$user['id']]; }
        elseif ($user['role'] === 'agency') { $customerWhere = 'org_id=?'; $customerParams = [(int)$user['org_id']]; }
        elseif ($user['role'] !== 'platform') { $customerWhere = 'user_id=?'; $customerParams = [(int)$user['id']]; }
        $totals['customer_count'] = in_array($user['role'], ['platform', 'agency', 'broker'], true) ? (int)$this->db->scalar('SELECT COUNT(DISTINCT user_id) FROM customers WHERE ' . $customerWhere, $customerParams) : 0;
        if ($user['role'] === 'merchant') { $appointmentWhere = 'org_id=? AND broker_id IS NULL'; $appointmentParams = [(int)$user['org_id']]; }
        elseif ($user['role'] === 'consumer') { $appointmentWhere = 'customer_id=?'; $appointmentParams = [(int)$user['id']]; }
        else { $appointmentWhere = $customerWhere; $appointmentParams = $customerParams; }
        list($appointmentWhere, $appointmentParams) = $this->dates($query, $appointmentWhere, $appointmentParams);
        $totals['appointment_count'] = (int)$this->db->scalar('SELECT COUNT(*) FROM appointments WHERE ' . $appointmentWhere, $appointmentParams);
        return $totals;
    }

    public function dividends(array $query, array $user)
    {
        if (!class_exists('ZipArchive')) throw new ApiError('Excel导出服务不可用。', 503, 503);
        list($where, $params) = (new Finance($this->db))->scope($user, $query);
        list($where, $params) = $this->dates($query, $where, $params);
        $count = (int)$this->db->scalar('SELECT COUNT(*) FROM ledger WHERE ' . $where, $params);
        if ($count > 20000) throw new ApiError('请按日期筛选至20000条以内再导出。');
        $rows = [['流水编号', '订单编号', '受益人编号', '分红角色', '金额（元）', '状态', '可结算时间', '记录时间']];
        foreach ($this->db->all('SELECT ledger.*,(SELECT order_no FROM orders WHERE orders.id=ledger.order_id) AS order_no FROM ledger WHERE ' . $where . ' ORDER BY id', $params) as $row) {
            $amount = (int)$row['amount_cents']; $absolute = abs($amount);
            $money = ($amount < 0 ? '-' : '') . intdiv($absolute, 100) . '.' . str_pad((string)($absolute % 100), 2, '0', STR_PAD_LEFT);
            $rows[] = [(string)$row['id'], (string)$row['order_no'], (string)$row['user_id'], (string)$row['beneficiary_role'], $money, (string)$row['state'], $row['available_at'] ? date('Y-m-d H:i:s', $row['available_at']) : '履约后确定', date('Y-m-d H:i:s', $row['created_at'])];
        }
        $directory = FileStore::root() . '/reports';
        if (!is_dir($directory) && !mkdir($directory, 0700, true)) throw new ApiError('报表目录不可用。', 503, 503);
        $temporary = tempnam($directory, 'excel-');
        try {
            $zip = new \ZipArchive();
            if ($zip->open($temporary, \ZipArchive::OVERWRITE) !== true) throw new ApiError('无法生成Excel文件。', 503, 503);
            $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
            $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
            $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="分红记录" sheetId="1" r:id="rId1"/></sheets></workbook>');
            $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
            $sheet = '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
            foreach ($rows as $r => $values) {
                $sheet .= '<row r="' . ($r + 1) . '">';
                foreach ($values as $c => $value) {
                    $cell = chr(65 + $c) . ($r + 1);
                    $sheet .= $c === 4 && $r > 0 ? '<c r="' . $cell . '"><v>' . $value . '</v></c>' : '<c r="' . $cell . '" t="inlineStr"><is><t>' . htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</t></is></c>';
                }
                $sheet .= '</row>';
            }
            $zip->addFromString('xl/worksheets/sheet1.xml', $sheet . '</sheetData></worksheet>');
            if (!$zip->close()) throw new ApiError('Excel文件写入失败。', 503, 503);
            chmod($temporary, 0600);
            $path = $directory . '/dividends-' . (int)$user['id'] . '.xlsx';
            if (!rename($temporary, $path)) throw new ApiError('Excel文件保存失败。', 503, 503);
            Policy::audit($this->db, $user, 'report.dividends', 'report', 0, ['row_count' => $count]);
            return ['_file' => $path, 'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'filename' => '驭舟分红报表.xlsx', 'private' => true];
        } finally { if (is_file($temporary)) unlink($temporary); }
    }
}
