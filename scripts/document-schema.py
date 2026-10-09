#!/usr/bin/env python3
"""从实际SQLite建表文件生成字段和外键说明；MySQL建表另作服务端验证。"""
import json
from pathlib import Path
import sqlite3

root = Path(__file__).resolve().parent.parent
connection = sqlite3.connect(':memory:')
connection.execute('PRAGMA foreign_keys=ON')
for file in ['database/sqlite.sql', 'database/communication-sqlite.sql']:
    connection.executescript((root / file).read_text())
for file in sorted((root / 'database/migrations').glob('*-sqlite.sql')):
    connection.executescript(file.read_text())
tables = {}
for (name,) in connection.execute("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name"):
    columns = [dict(zip(['position', 'name', 'type', 'not_null', 'default', 'primary_key_position'], row)) for row in connection.execute('PRAGMA table_info(%s)' % name)]
    relations = [dict(zip(['id', 'sequence', 'target_table', 'source_column', 'target_column', 'on_update', 'on_delete', 'match'], row)) for row in connection.execute('PRAGMA foreign_key_list(%s)' % name)]
    if len(columns) > 40:
        raise SystemExit('%s 字段超过40项，请拆分具体业务职责。' % name)
    tables[name] = {'columns': columns, 'foreign_keys': relations}
assert [column['name'] for column in tables['merchants']['columns'] if column['primary_key_position']] == ['credit_code'], '商家必须以社会信用代码做主键'
assert any(key['target_table'] == 'merchants' and key['source_column'] == 'merchant_credit_code' for key in tables['orders']['foreign_keys']), '订单缺少商家外键'
assert any(key['target_table'] == 'boat_types' for key in tables['boats']['foreign_keys']), '实体船必须依赖船型'
assert any(key['target_table'] == 'orders' for key in tables['order_items']['foreign_keys']), '订单明细必须依赖订单'
(root / 'docs/database-schema.json').write_text(json.dumps(tables, ensure_ascii=False, indent=2) + '\n')
lines = ['# 驭舟数据库设计', '', '此说明由实际建表文件生成。MySQL 5.7用于阿里云，SQLite只用于本地测试。', '',
         '商家企业主键为 `merchants.credit_code`（18位统一社会信用代码）；`organizations.id`只标识账号权限容器，经纪公司沿用机构ID。业务中的商家关联引用社会信用代码，供应商关联本身不会授予读取经纪订单的权限。', '',
         '船型与实体船分开，租赁库存按实体船/日期约束。订单明细、合同版本、支付退款、分红账本、课程课时与考题分别保存。业务关联、库存和金额使用明确字段；JSON只保存展示规格、不可变配置快照和辅助内容。', '',
         '金额以整数分保存；比例以basis points保存。历史财务及合同记录保留，身份停用或内容下架使用状态，不能级联删除订单与账本。', '',
         '## 关系图', '', '```mermaid', 'erDiagram']
for name, data in tables.items():
    for relation in data['foreign_keys']:
        lines.append('    %s ||--o{ %s : "%s"' % (relation['target_table'], name, relation['source_column']))
lines += ['```', '', '## 数据表', '']
for name, data in tables.items():
    lines += ['### %s（%s字段）' % (name, len(data['columns'])), '', '| 字段 | 类型 | 主键 | 必填 | 默认 |', '|---|---|---|---|---|']
    for column in data['columns']:
        lines.append('| %s | %s | %s | %s | %s |' % (column['name'], column['type'], '是' if column['primary_key_position'] else '', '是' if column['not_null'] else '', column['default'] if column['default'] is not None else ''))
    lines.append('')
    if data['foreign_keys']:
        lines += ['外键：' + '；'.join('`%s` → `%s.%s`' % (key['source_column'], key['target_table'], key['target_column']) for key in data['foreign_keys']), '']
(root / 'docs/database.md').write_text('\n'.join(lines).rstrip() + '\n')
print(json.dumps({'tables': len(tables), 'max_columns': max(len(table['columns']) for table in tables.values()), 'merchant_primary_key': 'credit_code'}, ensure_ascii=False))
