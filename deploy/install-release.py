#!/usr/bin/env python3
"""Verify a release, then activate only the independent Yuzhou vhost."""
import hashlib
import json
import os
from pathlib import Path
import pwd
import re
import subprocess
import sys
import tempfile
import time
import urllib.request

ROOT = Path('/www/wwwroot/yuzhou')
NGINX = '/www/server/nginx/sbin/nginx'
PHP = '/www/server/php/72/bin/php'
VHOST = Path('/www/server/panel/vhost/nginx/yuzhou.ourideas.top.conf')

def run(*args):
    subprocess.run(list(args), check=True)

def atomic_write(file, data):
    temporary = None
    try:
        with tempfile.NamedTemporaryFile(dir=file.parent, prefix='.' + file.name + '.', delete=False) as handle:
            temporary = Path(handle.name)
            handle.write(data)
            handle.flush()
            os.fsync(handle.fileno())
        temporary.replace(file)
    finally:
        if temporary is not None: temporary.unlink(missing_ok=True)

def main():
    if os.geteuid() != 0 or len(sys.argv) != 2:
        raise SystemExit('Run as root with the absolute release directory.')
    release = Path(sys.argv[1]).resolve()
    if release.parent != ROOT / 'releases':
        raise SystemExit('Release must be in the independent Yuzhou releases directory.')
    manifest = json.loads((release / 'release.json').read_text())
    if manifest['domain'] != 'yuzhou.ourideas.top' or not re.fullmatch('[a-f0-9]{40}', manifest['source_commit']):
        raise SystemExit('Unexpected release identity.')
    for item in manifest['files']:
        file = (release / item['path']).resolve()
        if release not in file.parents or not file.is_file() or hashlib.sha256(file.read_bytes()).hexdigest() != item['sha256']:
            raise SystemExit('Release checksum validation failed: ' + item['path'])
    run(PHP, str(release / 'deploy/preflight.php'))
    shared = ROOT / 'shared'
    if not (shared / '.env').is_file() or not (ROOT / 'certs/fullchain.pem').is_file():
        raise SystemExit('Initialize the independent database and certificate first.')
    migrations = sorted((release / 'database/migrations').glob('*-mysql.sql'))
    if migrations:
        receipt_file = shared / 'migrations.json'
        migration_receipt = json.loads(receipt_file.read_text()) if receipt_file.is_file() else {}
        applied = migration_receipt.get('migration_sha256', {})
        if migration_receipt.get('database') != 'yuzhou' or any(
            applied.get(file.name) != hashlib.sha256(file.read_bytes()).hexdigest() for file in migrations
        ):
            raise SystemExit('Apply this release\'s reviewed independent database migrations before activation.')
    account = pwd.getpwnam('www')
    for name in ['storage', 'runtime', 'backups']:
        directory = shared / name
        directory.mkdir(exist_ok=True)
        os.chown(str(directory), account.pw_uid, account.pw_gid)
        directory.chmod(0o700)
    os.chown(str(shared / '.env'), 0, account.pw_gid)
    (shared / '.env').chmod(0o640)
    for name, target in [('.env', shared / '.env'), ('runtime', shared / 'runtime'), ('storage', shared / 'storage')]:
        link = release / 'backend' / name
        if not link.is_symlink():
            link.symlink_to(target)
    for file in (release / 'backend').rglob('*.php'):
        if 'thinkphp' not in file.parts:
            subprocess.run([PHP, '-l', str(file)], check=True, stdout=subprocess.DEVNULL)
    previous_conf = VHOST.read_bytes() if VHOST.exists() else None
    current = ROOT / 'current'
    previous_release = current.resolve() if current.is_symlink() else None
    units = ['yuzhou-jobs.service', 'yuzhou-jobs.timer', 'yuzhou-certificate.service', 'yuzhou-certificate.timer']
    previous_units = {name: Path('/etc/systemd/system/' + name).read_bytes() if Path('/etc/systemd/system/' + name).exists() else None for name in units}
    previous_timers = {name: {
        state: subprocess.run(['systemctl', 'is-' + state, name], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL).returncode == 0
        for state in ['enabled', 'active']
    } for name in units if name.endswith('.timer')}
    units_changed = False
    try:
        pending = ROOT / 'current.new'
        if pending.is_symlink(): pending.unlink()
        pending.symlink_to(release)
        pending.replace(current)
        # An immutable PHP entry avoids FPM realpath caches retaining the old current symlink.
        conf = (release / 'deploy/nginx.conf.example').read_text().replace('/www/wwwroot/yuzhou/current/backend/public/index.php', str(release / 'backend/public/index.php'))
        VHOST.write_text(conf)
        run(NGINX, '-t')
        run(NGINX, '-s', 'reload')
        for attempt in range(3):
            try:
                with urllib.request.urlopen('https://yuzhou.ourideas.top/api/v1/health', timeout=20) as response:
                    health = json.loads(response.read())
                break
            except Exception:
                if attempt == 2: raise
                time.sleep(1)
        if health.get('data', {}).get('status') != 'ok':
            raise RuntimeError('Application health check failed.')
        units_changed = True
        for name in units:
            Path('/etc/systemd/system/' + name).write_bytes((release / 'deploy' / name).read_bytes())
        run('systemctl', 'daemon-reload')
        run('systemctl', 'enable', '--now', 'yuzhou-jobs.timer', 'yuzhou-certificate.timer')
        run('systemctl', 'start', 'yuzhou-jobs.service')
        receipt = {'release_id': manifest['release_id'], 'source_commit': manifest['source_commit'], 'health': health['data'], 'database_shared_with_existing_site': False}
        atomic_write(shared / 'deployment.json', json.dumps(receipt, indent=2).encode())
    except Exception:
        rollback_errors = []
        def restore(description, action):
            try:
                action()
                return True
            except Exception as error:
                rollback_errors.append(description + ': ' + str(error))
                return False
        def restore_site_conf():
            if previous_conf is not None: VHOST.write_bytes(previous_conf)
            elif VHOST.exists(): VHOST.unlink()
        def restore_current():
            if previous_release is not None:
                pending = ROOT / 'current.rollback'
                if pending.is_symlink(): pending.unlink()
                pending.symlink_to(previous_release)
                pending.replace(current)
            elif current.is_symlink(): current.unlink()
        # A systemd failure must not prevent restoring the serving site.
        restore('restore current release', restore_current)
        restore('restore site config', restore_site_conf)
        if restore('validate restored nginx', lambda: run(NGINX, '-t')):
            restore('reload restored nginx', lambda: run(NGINX, '-s', 'reload'))
        if units_changed:
            for name in previous_timers:
                restore('stop new timer ' + name, lambda name=name: run('systemctl', 'stop', name))
            for name in units:
                unit = Path('/etc/systemd/system/' + name)
                if previous_units[name] is None:
                    restore('remove new unit ' + name, lambda unit=unit: unit.unlink(missing_ok=True))
                else:
                    restore('restore unit ' + name, lambda unit=unit, name=name: unit.write_bytes(previous_units[name]))
            restore('reload restored units', lambda: run('systemctl', 'daemon-reload'))
            for name, state in previous_timers.items():
                restore('restore timer enablement ' + name, lambda name=name, state=state: run('systemctl', 'enable' if state['enabled'] else 'disable', name))
                restore('restore timer activity ' + name, lambda name=name, state=state: run('systemctl', 'start' if state['active'] else 'stop', name))
        for error in rollback_errors: print('Rollback step failed: ' + error, file=sys.stderr)
        raise
    print(json.dumps(receipt))

if __name__ == '__main__':
    main()
