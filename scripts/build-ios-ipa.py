#!/usr/bin/env python3
"""Archive/export one client using an explicitly supplied official DCloud Xcode project.

Run on the user's Mac with an installed signing identity and provisioning profile.
No SDK paths, Apple credentials or signing keys are embedded in this script.
"""
import argparse
from datetime import datetime, timezone
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import plistlib
import re
import shutil
import subprocess
import sys
from zipfile import ZipFile


ROOT = Path(__file__).resolve().parents[1]


def sha256(file):
    digest = hashlib.sha256()
    with file.open('rb') as stream:
        for block in iter(lambda: stream.read(1024 * 1024), b''):
            digest.update(block)
    return digest.hexdigest()


def run(command, logfile):
    print('Running ' + command[0] + ' ' + command[1])
    with logfile.open('ab') as stream:
        result = subprocess.run(command, stdout=stream, stderr=subprocess.STDOUT,
                                stdin=subprocess.DEVNULL, check=False)
    if result.returncode:
        raise RuntimeError('Command failed; inspect the private build log: ' + str(logfile))


def unpack_resources(archive_path, package, destination):
    expected = {item['path']: item['sha256'] for item in package['files']}
    seen = set()
    with ZipFile(archive_path) as archive:
        for entry in archive.infolist():
            name = PurePosixPath(entry.filename)
            if (not name.parts or name.parts[0] != 'www' or len(name.parts) < 2 or
                    name.is_absolute() or '..' in name.parts or '\\' in entry.filename or
                    entry.is_dir() or (entry.external_attr >> 16) & 0o170000 == 0o120000):
                raise RuntimeError('Unexpected ZIP entry: ' + entry.filename)
            relative = PurePosixPath(*name.parts[1:]).as_posix()
            if relative not in expected or relative in seen:
                raise RuntimeError('Unlisted or duplicate resource: ' + relative)
            data = archive.read(entry)
            if hashlib.sha256(data).hexdigest() != expected[relative]:
                raise RuntimeError('Resource checksum mismatch: ' + relative)
            target = destination.joinpath(*name.parts[1:])
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(data)
            seen.add(relative)
    if seen != set(expected):
        raise RuntimeError('The ZIP does not contain all resources in its receipt')
    manifest = json.loads((destination / 'manifest.json').read_text())
    if manifest['id'] != package['appid']:
        raise RuntimeError('Compiled DCloud AppID does not match the receipt')


def verify_ipa(ipa, package, team_id, output, logfile):
    with ZipFile(ipa) as archive:
        apps = [name for name in archive.namelist()
                if re.fullmatch(r'Payload/[^/]+\.app/Info\.plist', name)]
        if len(apps) != 1:
            raise RuntimeError('Expected exactly one main App in the IPA')
        prefix = apps[0].removesuffix('Info.plist')
        info = plistlib.loads(archive.read(apps[0]))
        if (info.get('CFBundleIdentifier') != package['bundle_id'] or
                str(info.get('CFBundleShortVersionString')) != package['version_name'] or
                str(info.get('CFBundleVersion')) != package['version_code']):
            raise RuntimeError('IPA Bundle ID or version does not match the compiled client')
        required = [prefix + 'embedded.mobileprovision', prefix + '_CodeSignature/CodeResources']
        if any(name not in archive.namelist() for name in required):
            raise RuntimeError('IPA lacks an embedded provisioning profile or code signature')
        matches = []
        for name in archive.namelist():
            if name.startswith(prefix) and name.endswith('/manifest.json'):
                try:
                    if json.loads(archive.read(name)).get('id') == package['appid']:
                        matches.append(name.removesuffix('manifest.json'))
                except (ValueError, UnicodeDecodeError):
                    continue
        if len(matches) != 1:
            raise RuntimeError('IPA does not contain exactly one matching native resource manifest')
        for item in package['files']:
            resource = matches[0] + item['path']
            if resource not in archive.namelist() or hashlib.sha256(archive.read(resource)).hexdigest() != item['sha256']:
                raise RuntimeError('IPA contains missing or changed native resources: ' + item['path'])
        # Extract into a fresh private directory after rejecting unsafe entries.
        for entry in archive.infolist():
            name = PurePosixPath(entry.filename)
            if name.is_absolute() or '..' in name.parts or '\\' in entry.filename:
                raise RuntimeError('Unsafe IPA path')
            if (entry.external_attr >> 16) & 0o170000 == 0o120000:
                raise RuntimeError('Unexpected symlink in iOS device IPA')
        inspection = output / 'signature-inspection'
        archive.extractall(inspection)
    app_path = inspection / prefix.rstrip('/')
    run(['codesign', '--verify', '--deep', '--strict', str(app_path)], logfile)
    profile_result = subprocess.run(['security', 'cms', '-D', '-i', str(app_path / 'embedded.mobileprovision')],
                                    capture_output=True, check=False)
    if profile_result.returncode:
        raise RuntimeError('Cannot decode the embedded Apple provisioning profile')
    profile = plistlib.loads(profile_result.stdout)
    entitlements = profile.get('Entitlements', {})
    application_identifier = entitlements.get('application-identifier', '')
    if (team_id not in profile.get('TeamIdentifier', []) or
            entitlements.get('com.apple.developer.team-identifier') != team_id or
            application_identifier.split('.', 1)[-1] != package['bundle_id']):
        raise RuntimeError('Provisioning profile does not match the selected Apple team and Bundle ID')
    expires = profile.get('ExpirationDate')
    if not expires or expires.replace(tzinfo=timezone.utc) <= datetime.now(timezone.utc):
        raise RuntimeError('The embedded provisioning profile is expired')
    signature_result = subprocess.run(['codesign', '-d', '--entitlements', ':-', str(app_path)],
                                      capture_output=True, check=False)
    if signature_result.returncode:
        raise RuntimeError('Cannot inspect signed application entitlements')
    signed_entitlements = plistlib.loads(signature_result.stdout)
    if (signed_entitlements.get('application-identifier') != application_identifier or
            signed_entitlements.get('com.apple.developer.team-identifier') != team_id):
        raise RuntimeError('App signature entitlements do not match its provisioning profile')
    shutil.rmtree(inspection)
    return {
        'bundle_id': info['CFBundleIdentifier'], 'version_name': info['CFBundleShortVersionString'],
        'version_code': str(info['CFBundleVersion']), 'codesign_verified': True,
        'embedded_resources_sha256_verified': True,
        'profile_expires_at': expires.replace(tzinfo=timezone.utc).isoformat(),
        'device_install_verified': False,
    }


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('app', choices=('consumer', 'broker'))
    parser.add_argument('--handoff', type=Path, required=True, help='ios-resource-handoff.json')
    project = parser.add_mutually_exclusive_group(required=True)
    project.add_argument('--project', type=Path, help='Official configured .xcodeproj')
    project.add_argument('--workspace', type=Path, help='Official configured .xcworkspace')
    parser.add_argument('--scheme', required=True)
    parser.add_argument('--team-id', required=True)
    parser.add_argument('--export-options', type=Path, required=True, help='Apple ExportOptions.plist')
    parser.add_argument('--www-dir', type=Path, required=True,
                        help='Existing www resource directory actually included by the native target')
    parser.add_argument('--output', type=Path, required=True, help='New private output directory')
    args = parser.parse_args()
    if sys.platform != 'darwin':
        raise RuntimeError('Signed iOS packaging requires execution on the user\'s Mac with Xcode')
    if not re.fullmatch(r'[A-Z0-9]{10}', args.team_id):
        parser.error('--team-id must be the 10-character Apple developer Team ID')
    for executable in ('xcodebuild', 'codesign', 'security'):
        if not shutil.which(executable):
            raise RuntimeError('Missing macOS tool: ' + executable)
    selected = (args.workspace or args.project).resolve()
    if not selected.is_dir() or selected.suffix != ('.xcworkspace' if args.workspace else '.xcodeproj'):
        raise RuntimeError('Supply an existing official configured Xcode project or workspace')
    options = plistlib.loads(args.export_options.read_bytes())
    if options.get('teamID') != args.team_id:
        raise RuntimeError('ExportOptions.plist teamID must match --team-id')
    if options.get('method') not in ('debugging', 'release-testing', 'app-store-connect',
                                     'development', 'ad-hoc', 'app-store'):
        raise RuntimeError('Use a development, Ad Hoc or TestFlight export method supported by this Xcode')
    if options.get('destination', 'export') != 'export':
        raise RuntimeError('Use destination=export; this script does not upload to Apple')
    receipt = json.loads(args.handoff.read_text())
    if receipt.get('artifact_type') != 'uniapp_native_resources' or receipt.get('schema_version') != 1:
        raise RuntimeError('Unsupported native-resource handoff receipt')
    packages = [item for item in receipt['packages'] if item['app'] == args.app]
    if len(packages) != 1:
        raise RuntimeError('Expected exactly one selected client in the handoff')
    package = packages[0]
    identity = json.loads((ROOT / 'docs/app-identities.json').read_text())[args.app]
    if package['appid'] != identity['appid'] or package['bundle_id'] != identity['ios_bundle_id']:
        raise RuntimeError('Handoff identities differ from this repository')
    if Path(package['archive']).name != package['archive']:
        raise RuntimeError('Archive name must be a filename in the handoff directory')
    archive = args.handoff.resolve().parent / package['archive']
    if sha256(archive) != package['sha256']:
        raise RuntimeError('Native-resource ZIP checksum does not match the receipt')
    target = args.www_dir.resolve()
    if (not target.is_dir() or args.www_dir.is_symlink() or
            target.name != 'www' or not (target / 'manifest.json').is_file()):
        raise RuntimeError('--www-dir must identify the existing native target www directory with manifest.json')
    output = args.output.resolve()
    if output.exists() or output == target or target in output.parents or output in target.parents:
        raise RuntimeError('Choose a new output directory outside the www resources')
    output.mkdir(parents=True, mode=0o700)
    logfile = output / 'xcodebuild.log'
    run(['xcodebuild', '-version'], logfile)
    prepared = output / 'prepared-www'
    prepared.mkdir()
    unpack_resources(archive, package, prepared)
    backup = output / 'previous-www'
    shutil.copytree(target, backup)
    resources_changed = False
    try:
        resources_changed = True
        shutil.rmtree(target)
        shutil.copytree(prepared, target)
        archive_path = output / ('yuzhou-' + args.app + '.xcarchive')
        export_path = output / 'export'
        selector = '-workspace' if args.workspace else '-project'
        run(['xcodebuild', selector, str(selected), '-scheme', args.scheme,
             '-configuration', 'Release', '-destination', 'generic/platform=iOS',
             '-archivePath', str(archive_path), 'DEVELOPMENT_TEAM=' + args.team_id,
             'PRODUCT_BUNDLE_IDENTIFIER=' + package['bundle_id'],
             'MARKETING_VERSION=' + package['version_name'],
             'CURRENT_PROJECT_VERSION=' + package['version_code'],
             'archive'], logfile)
        run(['xcodebuild', '-exportArchive', '-archivePath', str(archive_path),
             '-exportPath', str(export_path), '-exportOptionsPlist', str(args.export_options.resolve())], logfile)
        ipas = list(export_path.glob('*.ipa'))
        if len(ipas) != 1:
            raise RuntimeError('Xcode export did not produce exactly one IPA')
        verified = verify_ipa(ipas[0], package, args.team_id, output, logfile)
        filename = 'yuzhou-' + args.app + '-test.ipa'
        ipa = output / filename
        shutil.copy2(ipas[0], ipa)
        result = {
            'schema_version': 1, 'artifact_type': 'signed_ios_ipa', 'app': args.app,
            'appid': package['appid'], 'apple_team_id': args.team_id,
            'api_base': receipt['api_base'], 'source_commit': receipt['source_commit'],
            'source_worktree_clean': receipt['source_worktree_clean'],
            'source_snapshot_sha256': receipt['source_snapshot_sha256'],
            'resource_archive_sha256': package['sha256'],
            'handoff_receipt_sha256': sha256(args.handoff),
            'created_at': datetime.now(timezone.utc).isoformat(),
            'export_method': options['method'], 'ipa': filename, 'sha256': sha256(ipa),
            **verified,
        }
        result_file = output / ('yuzhou-' + args.app + '-test.json')
        result_file.write_text(json.dumps(result, ensure_ascii=False, indent=2) + '\n')
        (output / 'SHA256SUMS').write_text(result['sha256'] + '  ' + filename + '\n' +
                                         sha256(result_file) + '  ' + result_file.name + '\n')
        print('Verified signed IPA: ' + str(ipa))
        print('Device installation and runtime acceptance must still be checked on an iPhone.')
    finally:
        if resources_changed:
            if target.exists():
                shutil.rmtree(target)
            shutil.copytree(backup, target)


if __name__ == '__main__':
    try:
        main()
    except (RuntimeError, KeyError, ValueError, OSError, plistlib.InvalidFileException) as error:
        raise SystemExit(str(error))
