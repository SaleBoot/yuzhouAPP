#!/usr/bin/env python3
"""只建立驭舟的新HTTP站点用于申请证书，其他请求暂返回503。"""
from pathlib import Path
import subprocess

root = Path('/www/wwwroot/yuzhou')
config = Path('/www/server/panel/vhost/nginx/yuzhou.ourideas.top.conf')
if config.exists():
    raise SystemExit('驭舟站点配置已存在，停止避免覆盖。')
for folder in ['acme', 'releases', 'shared']:
    (root / folder).mkdir(parents=True, exist_ok=True)
content = '''server {
    listen 80;
    server_name yuzhou.ourideas.top;
    location ^~ /.well-known/acme-challenge/ { root /www/wwwroot/yuzhou/acme; }
    location / { return 503; }
}
'''
config.write_text(content)
binary = '/www/server/nginx/sbin/nginx'
check = subprocess.run([binary, '-t'], capture_output=True, text=True)
if check.returncode:
    config.unlink()
    raise SystemExit('Nginx校验失败，已撤回本次新建配置，未重载。')
subprocess.run([binary, '-s', 'reload'], check=True)
print('新HTTP站点准备完成，当前业务访问503；仅开放证书挑战路径。')
