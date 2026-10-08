#!/usr/bin/env python3
"""在当前宝塔阿里云上首次创建驭舟独立库。管理员口令只在进程内读取。"""
import hashlib
import json
import os
from pathlib import Path
import re
import secrets
import subprocess
import sys

release = Path(__file__).resolve().parent.parent
shared = Path('/www/wwwroot/yuzhou/shared')
migration_files = sorted((release / 'database/migrations').glob('*-mysql.sql'))
schema_files = [release / 'database/mysql.sql', release / 'database/communication-mysql.sql'] + migration_files
migrate = '--migrate' in sys.argv
if os.geteuid() != 0:
    raise SystemExit('需使用服务器管理员执行一次初始化。')
if not all(file.is_file() for file in schema_files):
    raise SystemExit('缺少已审核的MySQL建表文件。')
if (shared / '.env').exists() and not migrate:
    raise SystemExit('驭舟已配置；不会重置库、账号或覆盖环境文件。')
os.chdir('/www/server/panel')
sys.path.insert(0, '/www/server/panel')
sys.path.insert(0, '/www/server/panel/class')
import public
admin_password = public.M('config').where('id=?', (1,)).getField('mysql_root')
configuration = public.readFile('/etc/my.cnf') or ''
socket = re.search(r'socket\s*=\s*(.+)', configuration)
port = re.search(r'port\s*=\s*([0-9]+)', configuration)
command = ['/www/server/mysql/bin/mysql', '--user=root', '--batch', '--skip-column-names']
if socket:
    command.append('--socket=' + socket.group(1).strip())
if port:
    command.append('--port=' + port.group(1))
environment = dict(os.environ, MYSQL_PWD=admin_password)

def mysql(sql):
    result = subprocess.run(command, input=sql, env=environment, text=True, capture_output=True, timeout=60)
    if result.returncode:
        # SQL contains generated credentials during user creation; never include it in diagnostics.
        raise RuntimeError('MySQL管理操作失败，退出码 %s；保留现场，未重置任何库。' % result.returncode)
    return result.stdout.strip()

database_exists = mysql("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='yuzhou';")
if migrate:
    if not database_exists or not (shared / '.env').is_file():
        raise SystemExit('首次初始化尚未完成；增量迁移不会另建库。')
    mysql('USE yuzhou;\n' + '\n'.join(file.read_text() for file in migration_files))
    receipt = {'database': 'yuzhou', 'migration_sha256': {file.name: hashlib.sha256(file.read_bytes()).hexdigest() for file in migration_files}}
    (shared / 'migrations.json').write_text(json.dumps(receipt, indent=2))
    print(json.dumps(receipt))
    raise SystemExit(0)
if database_exists:
    raise SystemExit('yuzhou库已存在，请检查状态并增量迁移；此脚本不删除已有数据。')
if mysql("SELECT User FROM mysql.user WHERE User='yuzhou_app';"):
    raise SystemExit('yuzhou_app账号已存在，停止以避免覆盖已有访问配置。')
password = secrets.token_urlsafe(40)
mysql("CREATE DATABASE yuzhou CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n"
      "CREATE USER 'yuzhou_app'@'127.0.0.1' IDENTIFIED BY '%s';\n" % password +
      "GRANT SELECT,INSERT,UPDATE,DELETE ON yuzhou.* TO 'yuzhou_app'@'127.0.0.1';")
mysql('USE yuzhou;\n' + '\n'.join(file.read_text() for file in schema_files))
shared.mkdir(parents=True, exist_ok=True)
for name in ['storage', 'runtime', 'backups']:
    (shared / name).mkdir(exist_ok=True)
content = '\n'.join([
    'APP_ENV=test', 'APP_DEBUG=0', 'APP_URL=https://yuzhou.ourideas.top',
    'DB_DRIVER=mysql', 'DB_HOST=127.0.0.1', 'DB_PORT=' + (port.group(1) if port else '3306'),
    'DB_DATABASE=yuzhou', 'DB_USERNAME=yuzhou_app', 'DB_PASSWORD=' + password,
    'ALLOW_TEST_PAYMENTS=1', 'STORAGE_PATH=' + str(shared / 'storage'), ''
])
file = shared / '.env'
file.write_text(content)
file.chmod(0o640)
receipt = {'database': 'yuzhou', 'user': 'yuzhou_app', 'app_grants': ['SELECT', 'INSERT', 'UPDATE', 'DELETE'],
           'initialized_schema_sha256': {file.name: hashlib.sha256(file.read_bytes()).hexdigest() for file in schema_files},
           'environment': 'test', 'real_services_configured': False}
(shared / 'database-initialization.json').write_text(json.dumps(receipt, indent=2))
print(json.dumps(receipt))
