#!/usr/bin/env python3
"""Install only the desktop website; retain the current API, Apps and management site."""
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import re
import subprocess
import sys
import tempfile
import time

ROOT = Path('/www/wwwroot/yuzhou')
VHOST = Path('/etc/nginx/conf.d/yuzhou.conf')
ROOT_LOCATION = '    location / { try_files $uri $uri/ =404; }'
SITE_LOCATION = '''    # Yuzhou desktop website entry aliases
    location = /consumer { return 302 /; }
    location = /consumer/ { return 302 /; }
    location = /broker { return 302 /sales; }
    location = /broker/ { return 302 /sales; }
    location / { try_files $uri $uri/ /index.html; }'''

def digest(file):
    return hashlib.sha256(file.read_bytes()).hexdigest()

def activate(link):
    pending = ROOT / 'current.site-pending'
    if pending.is_symlink():
        pending.unlink()
    if pending.exists():
        raise RuntimeError('Unexpected pending activation path.')
    pending.symlink_to(link)
    pending.replace(ROOT / 'current')

def atomic_write(file, data, mode):
    temporary = None
    try:
        with tempfile.NamedTemporaryFile(dir=file.parent, prefix='.' + file.name + '.', delete=False) as handle:
            temporary = Path(handle.name)
            os.fchmod(handle.fileno(), mode)
            handle.write(data)
            handle.flush()
            os.fsync(handle.fileno())
        temporary.replace(file)
    finally:
        if temporary is not None:
            temporary.unlink(missing_ok=True)

def validate_files(release, manifest):
    files = manifest.get('files')
    if not isinstance(files, list) or not files:
        raise SystemExit('Website manifest is empty.')
    listed = set()
    for item in files:
        name = item.get('path', '')
        if not isinstance(name, str):
            raise SystemExit('Website manifest contains a noncanonical path.')
        path = PurePosixPath(name)
        if path.is_absolute() or '..' in path.parts or path.as_posix() != name or '\\' in name:
            raise SystemExit('Website manifest contains a noncanonical path.')
        # shortcut: Vite assets are flat; add recursive retention before permitting nested assets.
        if name not in ['public/index.html', 'install-site.py'] and not (name.startswith('public/site-assets/') and len(path.parts) == 3):
            raise SystemExit('Website manifest contains another service.')
        if name in listed:
            raise SystemExit('Website manifest contains duplicate files.')
        listed.add(name)
        file = release / name
        if file.is_symlink() or not file.is_file() or release not in file.resolve().parents or digest(file) != item['sha256']:
            raise SystemExit('Website checksum validation failed.')
    actual = set()
    for file in release.rglob('*'):
        if file.is_symlink() or not (file.is_dir() or file.is_file()):
            raise SystemExit('Website stage contains an unexpected link or special file.')
        if file.is_file():
            actual.add(file.relative_to(release).as_posix())
        elif file.relative_to(release).as_posix() not in ['public', 'public/site-assets']:
            raise SystemExit('Website stage contains another service directory.')
    if actual != listed | {'site-release.json'} or not {'public/index.html', 'install-site.py'} <= listed or not any(name.startswith('public/site-assets/') for name in listed):
        raise SystemExit('Website stage and complete manifest do not match.')

def main():
    if os.geteuid() != 0 or len(sys.argv) != 2:
        raise SystemExit('Run as root with a staged independent website release.')
    release = Path(sys.argv[1]).resolve()
    if release.parent != ROOT / 'releases' or not release.is_dir() or not release.name.startswith('site-'):
        raise SystemExit('Unexpected website release target.')
    manifest = json.loads((release / 'site-release.json').read_text())
    if manifest['release_id'] != release.name or manifest['domain'] != 'yuzhou.ourideas.top' or manifest['scope'] != 'desktop website only' or not re.fullmatch('[a-f0-9]{40}', manifest['source_commit']):
        raise SystemExit('Unexpected website manifest identity.')
    validate_files(release, manifest)
    if not (release / 'public/index.html').is_file() or not (release / 'public/site-assets').is_dir():
        raise SystemExit('Missing website entry or assets.')
    previous = (ROOT / 'current').resolve()
    if ROOT / 'releases' not in previous.parents or previous == release:
        raise SystemExit('Unexpected current release.')
    pending = ROOT / 'current.site-pending'
    if pending.exists() and not pending.is_symlink():
        raise SystemExit('Unexpected pending activation path.')
    conf = VHOST.read_bytes()
    text = conf.decode()
    if text.count(ROOT_LOCATION) == 1:
        updated = text.replace(ROOT_LOCATION, SITE_LOCATION)
    elif SITE_LOCATION in text:
        updated = text
    else:
        raise SystemExit('Review the current website routing before activation.')
    if '/releases/api-109-067b058/backend/public/index.php' not in text:
        raise SystemExit('API baseline changed; review before preserving it.')
    preserved = [ROOT / 'shared/app-updates/latest.json', ROOT / 'shared/.env', previous / 'public/admin/index.html']
    before = {str(file): digest(file) for file in preserved}
    receipt_file = ROOT / 'shared/desktop-site-deployment.json'
    previous_receipt = receipt_file.read_bytes() if receipt_file.is_file() else None
    for file in previous.iterdir():
        if file.name != 'public' and not (release / file.name).exists():
            (release / file.name).symlink_to(file)
    for file in (previous / 'public').iterdir():
        if file.name not in ['index.html', 'site-assets']:
            (release / 'public' / file.name).symlink_to(file)
    old_assets = previous / 'public/site-assets'
    if old_assets.is_dir():
        for file in old_assets.iterdir():
            if not (release / 'public/site-assets' / file.name).exists():
                (release / 'public/site-assets' / file.name).symlink_to(file)
    try:
        VHOST.write_text(updated)
        subprocess.run(['nginx', '-t'], check=True)
        activate(release)
        subprocess.run(['systemctl', 'reload', 'nginx'], check=True)
        base = ['curl', '--noproxy', '*', '--silent', '--show-error', '--fail', '--connect-timeout', '5', '--max-time', '20', '--resolve', 'yuzhou.ourideas.top:443:127.0.0.1']
        for attempt in range(5):
            home = subprocess.check_output(base + ['https://yuzhou.ourideas.top/'])
            if hashlib.sha256(home).hexdigest() == digest(release / 'public/index.html'):
                break
            time.sleep(1)
        else:
            raise RuntimeError('The desktop entry did not activate.')
        health = json.loads(subprocess.check_output(base + ['https://yuzhou.ourideas.top/api/v1/health']))
        if health.get('data', {}).get('status') != 'ok' or any(digest(Path(file)) != value for file, value in before.items()):
            raise RuntimeError('A preserved service changed.')
        receipt = {'release_id': release.name, 'source_commit': manifest['source_commit'], 'previous_release': str(previous), 'scope': manifest['scope'], 'activated_at_utc': time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime()), 'entry_sha256': digest(release / 'public/index.html'), 'api_and_app_update_manifest_and_environment_and_admin_preserved': True, 'app_versions_changed': False}
        atomic_write(receipt_file, json.dumps(receipt, indent=2).encode(), 0o600)
    except BaseException:
        failures = []
        def restore(description, action):
            try:
                action()
                return True
            except BaseException as error:
                failures.append(description + ': ' + type(error).__name__)
                return False
        restore('current release', lambda: activate(previous))
        restore('website config', lambda: VHOST.write_bytes(conf))
        if restore('validate restored config', lambda: subprocess.run(['nginx', '-t'], check=True)):
            restore('reload restored config', lambda: subprocess.run(['systemctl', 'reload', 'nginx'], check=True))
        if previous_receipt is None:
            restore('remove new receipt', lambda: receipt_file.unlink(missing_ok=True))
        else:
            restore('restore receipt', lambda: atomic_write(receipt_file, previous_receipt, 0o600))
        if failures:
            print('Website rollback requires inspection: ' + '; '.join(failures), file=sys.stderr)
        raise
    print(json.dumps(receipt))

if __name__ == '__main__':
    main()
