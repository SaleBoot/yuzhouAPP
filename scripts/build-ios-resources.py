#!/usr/bin/env python3
"""Compile both UniApp clients and prepare versioned iOS native-resource handoffs.

This creates www resource ZIPs, not signed or installable IPA files.
"""
import argparse
from datetime import datetime, timezone
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
from urllib.parse import urlparse
from zipfile import ZIP_DEFLATED, ZipFile, ZipInfo


ROOT = Path(__file__).resolve().parents[1]
APPS = ('consumer', 'broker')
DEFAULT_API = 'https://yuzhou.ourideas.top/api/v1'


def run(command, cwd=ROOT, capture=False, env=None):
    result = subprocess.run(command, cwd=cwd, env=env, text=True,
                            capture_output=capture, check=False)
    if result.returncode:
        raise RuntimeError('Command failed: ' + ' '.join(command))
    return result.stdout.strip() if capture else None


def digest(file):
    result = hashlib.sha256()
    with file.open('rb') as stream:
        for block in iter(lambda: stream.read(1024 * 1024), b''):
            result.update(block)
    return result.hexdigest()


def source_snapshot():
    # Include ignored dotenv files in the fingerprint, but never copy their contents.
    # Explicit VITE_API_BASE below takes precedence over dotenv for the API origin.
    result = subprocess.run(
        ['git', 'ls-files', '--cached', '--others', '--exclude-standard', '-z',
         'apps', 'scripts', 'docs/app-identities.json', 'docs/ios-testing-build.md'],
        cwd=ROOT, capture_output=True, check=True)
    paths = {os.fsdecode(item) for item in result.stdout.split(b'\0') if item}
    for app in APPS:
        paths.update(str(file.relative_to(ROOT))
                     for file in (ROOT / 'apps' / app).glob('.env*') if file.is_file())
    inventory = []
    for relative in sorted(paths):
        file = ROOT / relative
        if file.is_symlink():
            raise RuntimeError('Source symlinks are not supported: ' + relative)
        if file.is_file():
            inventory.append({'path': relative, 'sha256': digest(file)})
    serialized = json.dumps(inventory, separators=(',', ':'), ensure_ascii=False)
    return inventory, hashlib.sha256(serialized.encode()).hexdigest()


def resource_files(directory):
    files = []
    for file in sorted(directory.rglob('*')):
        if file.is_symlink():
            raise RuntimeError('Resource symlinks are not supported: ' + str(file))
        if not file.is_file():
            continue
        relative = file.relative_to(directory).as_posix()
        if (file.name.startswith('.env') or file.suffix.lower() in
                {'.p12', '.mobileprovision', '.key', '.keystore', '.jks', '.map'}):
            raise RuntimeError('Private or development file found in resources: ' + relative)
        files.append({'path': relative, 'sha256': digest(file), 'bytes': file.stat().st_size})
    return files


def write_zip(directory, files, destination, prefix='www/'):
    # Stable entry dates, order and modes make identical resources yield identical ZIPs.
    with ZipFile(destination, 'w', compression=ZIP_DEFLATED, compresslevel=9) as archive:
        for item in files:
            entry = ZipInfo(prefix + item['path'], date_time=(1980, 1, 1, 0, 0, 0))
            entry.create_system = 3
            entry.external_attr = 0o100644 << 16
            entry.compress_type = ZIP_DEFLATED
            archive.writestr(entry, (directory / item['path']).read_bytes(), compresslevel=9)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--app', choices=(*APPS, 'all'), default='all')
    parser.add_argument('--api-base', default=DEFAULT_API)
    parser.add_argument('--output', type=Path,
                        help='New empty output directory (default: artifacts/native-ios/resources-...)')
    args = parser.parse_args()
    api = args.api_base.rstrip('/')
    parsed = urlparse(api)
    if (parsed.scheme != 'https' or not parsed.hostname or parsed.username or
            parsed.password or parsed.query or parsed.fragment or parsed.path != '/api/v1'):
        parser.error('--api-base must be an HTTPS origin followed by /api/v1')
    if not shutil.which('npm'):
        raise RuntimeError('npm is required; install Node.js and run npm ci in both App directories')
    apps = APPS if args.app == 'all' else (args.app,)
    identities = json.loads((ROOT / 'docs/app-identities.json').read_text())
    source_manifests = {}
    for app in apps:
        directory = ROOT / 'apps' / app
        if not (directory / 'node_modules').is_dir():
            raise RuntimeError('Missing dependencies; run npm --prefix apps/' + app + ' ci')
        manifest = json.loads((directory / 'src/manifest.json').read_text())
        identity = identities[app]
        bundle = manifest['app-plus']['distribute']['ios']['appid']
        if (manifest['appid'] != identity['appid'] or bundle != identity['ios_bundle_id'] or
                not re.fullmatch(r'__UNI__[A-Z0-9]+', manifest['appid'])):
            raise RuntimeError(app + ': DCloud identity or iOS Bundle ID mismatch')
        source_manifests[app] = manifest
    if len({manifest['appid'] for manifest in source_manifests.values()}) != len(apps):
        raise RuntimeError('The two apps must have separate DCloud identities')
    if len({manifest['app-plus']['distribute']['ios']['appid']
            for manifest in source_manifests.values()}) != len(apps):
        raise RuntimeError('The two apps must have separate iOS Bundle IDs')

    commit = run(['git', 'rev-parse', 'HEAD'], capture=True)
    clean = not run(['git', 'status', '--porcelain'], capture=True)
    sources, snapshot = source_snapshot()
    created = datetime.now(timezone.utc)
    output = (args.output or ROOT / 'artifacts/native-ios' /
              ('resources-' + created.strftime('%Y%m%dT%H%M%SZ') + '-' + snapshot[:8])).resolve()
    if output.exists():
        raise RuntimeError('Output already exists; choose a new directory: ' + str(output))
    if ROOT == output or (ROOT in output.parents and output.relative_to(ROOT).parts[0] != 'artifacts'):
        raise RuntimeError('Use artifacts/ or an external directory for generated output')

    environment = {key: value for key, value in os.environ.items()
                   if not key.startswith('UNI_')}
    environment['VITE_API_BASE'] = api
    for app in apps:
        directory = ROOT / 'apps' / app
        run(['npm', 'run', 'check'], cwd=directory, env=environment)
        # Remove stale outputs so a successful but misdirected build cannot package old resources.
        resources = directory / 'dist/build/app'
        if resources.is_symlink():
            raise RuntimeError('Refusing to remove a symlinked build directory')
        if resources.exists():
            shutil.rmtree(resources)
        run(['npm', 'run', 'build:app'], cwd=directory, env=environment)
    if run(['git', 'rev-parse', 'HEAD'], capture=True) != commit or source_snapshot()[1] != snapshot:
        raise RuntimeError('App sources changed during compilation; rebuild after edits are complete')

    packages = []
    pending = []
    for app in apps:
        resources = ROOT / 'apps' / app / 'dist/build/app'
        manifest = json.loads((resources / 'manifest.json').read_text())
        source = source_manifests[app]
        if (manifest['id'] != source['appid'] or manifest['version']['name'] != source['versionName'] or
                str(manifest['version']['code']) != str(source['versionCode'])):
            raise RuntimeError(app + ': compiled identity/version does not match source')
        compiler = manifest['plus']['uni-app']['compilerVersion']
        if compiler != '5.26':
            raise RuntimeError('Use a matching official native SDK; expected compiler 5.26, got ' + str(compiler))
        service = resources / 'app-service.js'
        if not service.is_file() or api.encode() not in service.read_bytes():
            raise RuntimeError(app + ': compiled native API origin is missing')
        files = resource_files(resources)
        pending.append((app, resources, files))
        packages.append({
            'app': app, 'name': source['name'], 'appid': source['appid'],
            'bundle_id': source['app-plus']['distribute']['ios']['appid'],
            'version_name': source['versionName'], 'version_code': str(source['versionCode']),
            'compiler_version': compiler, 'archive': 'yuzhou-' + app + '-ios-www.zip',
            'files': files,
        })

    output.mkdir(parents=True)
    try:
        for (app, resources, files), package in zip(pending, packages):
            destination = output / package['archive']
            write_zip(resources, files, destination)
            package['sha256'] = digest(destination)
        handoff_files = []
        for relative in ('scripts/build-ios-ipa.py', 'docs/app-identities.json',
                         'docs/ios-testing-build.md'):
            target = output / relative
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(ROOT / relative, target)
            handoff_files.append({'path': relative, 'sha256': digest(target)})
        if run(['git', 'rev-parse', 'HEAD'], capture=True) != commit or source_snapshot()[1] != snapshot:
            raise RuntimeError('App sources changed during packaging; rebuild after edits are complete')
        receipt = {
            'schema_version': 1, 'artifact_type': 'uniapp_native_resources',
            'signed_ipa': False, 'installable_on_ios': False, 'device_verified': False,
            'created_at': created.isoformat(), 'source_commit': commit,
            'source_worktree_clean': clean, 'source_snapshot_sha256': snapshot,
            'api_base': api, 'source_files': sources, 'packages': packages,
            'handoff_files': handoff_files,
        }
        receipt_file = output / 'ios-resource-handoff.json'
        receipt_file.write_text(json.dumps(receipt, ensure_ascii=False, indent=2) + '\n')
        checksums = [(digest(receipt_file), receipt_file.name)]
        checksums.extend((package['sha256'], package['archive']) for package in packages)
        checksums.extend((item['sha256'], item['path']) for item in handoff_files)
        checksum_file = output / 'SHA256SUMS'
        checksum_file.write_text(''.join(sha + '  ' + name + '\n' for sha, name in checksums))
        # Snapshot entries before writing the outer ZIP; it must never include itself.
        outer = output / 'ios-test-build-handoff.zip'
        write_zip(output, resource_files(output), outer, prefix='')
        with checksum_file.open('a') as stream:
            stream.write(digest(outer) + '  ' + outer.name + '\n')
    except Exception:
        shutil.rmtree(output)
        raise
    print('iOS native-resource handoff: ' + str(output))
    print('Transfer ZIP: ' + str(output / 'ios-test-build-handoff.zip'))
    print('Contains validated www ZIPs and SHA256 receipts; signing and device installation remain separate.')


if __name__ == '__main__':
    try:
        main()
    except (RuntimeError, KeyError, ValueError, OSError, subprocess.CalledProcessError) as error:
        raise SystemExit(str(error))
