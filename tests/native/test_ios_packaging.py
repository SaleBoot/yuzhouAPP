"""Checks handoff integrity and signature-verification calls; never performs signing."""
from datetime import datetime, timedelta
import hashlib
import importlib.util
import json
from pathlib import Path
import plistlib
import subprocess
import tempfile
import unittest
from unittest.mock import patch
import warnings
from zipfile import ZipFile, ZipInfo


ROOT = Path(__file__).resolve().parents[2]


def load_script(name):
    spec = importlib.util.spec_from_file_location(name.replace('-', '_'), ROOT / 'scripts' / (name + '.py'))
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


resources = load_script('build-ios-resources')
ipa = load_script('build-ios-ipa')


class ResourceHandoffTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='yuzhou-ios-test-')
        self.addCleanup(self.temporary.cleanup)
        self.folder = Path(self.temporary.name)
        self.contents = {
            'manifest.json': json.dumps({'id': '__UNI__E148E73'}).encode(),
            'app-service.js': b'const api="https://yuzhou.ourideas.top/api/v1";',
            'static/image.png': b'example-resource',
        }
        self.package = {
            'appid': '__UNI__E148E73',
            'files': [{'path': name, 'sha256': hashlib.sha256(data).hexdigest(), 'bytes': len(data)}
                      for name, data in self.contents.items()],
        }

    def archive(self, entries):
        filename = self.folder / 'resources.zip'
        with warnings.catch_warnings():
            warnings.simplefilter('ignore', UserWarning)
            with ZipFile(filename, 'w') as archive:
                for name, data in entries:
                    archive.writestr(name, data)
        return filename

    def standard_entries(self):
        return [('www/' + name, data) for name, data in self.contents.items()]

    def test_valid_round_trip_and_reproducible_zip(self):
        source = self.folder / 'compiled'
        for name, data in self.contents.items():
            target = source / name
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(data)
        inventory = resources.resource_files(source)
        first, second = self.folder / 'first.zip', self.folder / 'second.zip'
        resources.write_zip(source, inventory, first)
        resources.write_zip(source, inventory, second)
        self.assertEqual(resources.digest(first), resources.digest(second))
        output = self.folder / 'imported'
        ipa.unpack_resources(first, self.package, output)
        self.assertEqual({name: (output / name).read_bytes() for name in self.contents}, self.contents)

    def test_changed_resource_is_rejected(self):
        entries = [('www/' + name, b'changed' if name == 'app-service.js' else data)
                   for name, data in self.contents.items()]
        with self.assertRaisesRegex(RuntimeError, 'checksum mismatch'):
            ipa.unpack_resources(self.archive(entries), self.package, self.folder / 'output')

    def test_missing_resource_is_rejected(self):
        with self.assertRaisesRegex(RuntimeError, 'all resources'):
            ipa.unpack_resources(self.archive(self.standard_entries()[:-1]), self.package, self.folder / 'output')

    def test_extra_resource_is_rejected(self):
        entries = self.standard_entries() + [('www/extra.js', b'extra')]
        with self.assertRaisesRegex(RuntimeError, 'Unlisted or duplicate'):
            ipa.unpack_resources(self.archive(entries), self.package, self.folder / 'output')

    def test_duplicate_resource_is_rejected(self):
        entries = self.standard_entries() + [self.standard_entries()[0]]
        with self.assertRaisesRegex(RuntimeError, 'Unlisted or duplicate'):
            ipa.unpack_resources(self.archive(entries), self.package, self.folder / 'output')

    def test_unsafe_paths_are_rejected_without_writing_outside_target(self):
        for name in ('www/../../escaped', '/tmp/escaped', 'www\\escaped'):
            with self.subTest(name=name):
                entries = self.standard_entries() + [(name, b'escaped')]
                with self.assertRaisesRegex(RuntimeError, 'Unexpected ZIP entry'):
                    ipa.unpack_resources(self.archive(entries), self.package, self.folder / 'output')
                self.assertFalse((self.folder / 'escaped').exists())

    def test_symlink_entry_is_rejected(self):
        symlink = ZipInfo('www/static/image.png')
        symlink.create_system = 3
        symlink.external_attr = 0o120777 << 16
        entries = self.standard_entries()[:-1] + [(symlink, b'../../escaped')]
        with self.assertRaisesRegex(RuntimeError, 'Unexpected ZIP entry'):
            ipa.unpack_resources(self.archive(entries), self.package, self.folder / 'output')

    def test_other_client_identity_is_rejected_even_with_valid_file_hashes(self):
        package = dict(self.package, appid='__UNI__59A80C3')
        with self.assertRaisesRegex(RuntimeError, 'AppID'):
            ipa.unpack_resources(self.archive(self.standard_entries()), package, self.folder / 'output')

    def test_private_signing_files_are_rejected_from_resource_inventory(self):
        source = self.folder / 'compiled'
        source.mkdir()
        (source / 'accidental.p12').write_bytes(b'private-placeholder')
        with self.assertRaisesRegex(RuntimeError, 'Private or development file'):
            resources.resource_files(source)

    def ipa_fixture(self):
        package = dict(self.package, bundle_id='top.ourideas.yuzhou.consumer',
                       version_name='1.0.7', version_code='107')
        prefix = 'Payload/Yuzhou.app/'
        entries = [(prefix + 'Info.plist', plistlib.dumps({
            'CFBundleIdentifier': package['bundle_id'],
            'CFBundleShortVersionString': package['version_name'],
            'CFBundleVersion': package['version_code'],
        })), (prefix + 'embedded.mobileprovision', b'fixture-profile'),
                   (prefix + '_CodeSignature/CodeResources', b'fixture-signature'),
                   (prefix + 'HBuilder.debug.dylib', b'fixture-root-dylib'),
                   (prefix + 'Frameworks/Test.framework/Nested.dylib', b'fixture-framework-dylib')]
        entries.extend((prefix + 'Pandora/apps/' + package['appid'] + '/www/' + name, data)
                       for name, data in self.contents.items())
        return self.archive(entries), package

    def signature_tool_result(self, command, **options):
        entitlements = {'application-identifier': 'TESTTEAM01.top.ourideas.yuzhou.consumer',
                        'com.apple.developer.team-identifier': 'TESTTEAM01'}
        if command[0] == 'security':
            data = {'TeamIdentifier': ['TESTTEAM01'], 'Entitlements': entitlements,
                    'ExpirationDate': datetime.now() + timedelta(days=1)}
        elif command[:2] == ['codesign', '-d']:
            data = entitlements
        else:
            self.assertEqual(command[:2], ['codesign', '--verify'])
            data = None
        return subprocess.CompletedProcess(command, 0, plistlib.dumps(data) if data else b'')

    def test_root_and_framework_dylibs_are_individually_verified(self):
        archive, package = self.ipa_fixture()
        with patch.object(ipa.subprocess, 'run', side_effect=self.signature_tool_result) as executed:
            receipt = ipa.verify_ipa(archive, package, 'TESTTEAM01', self.folder, self.folder / 'verify.log')
        expected = ['Frameworks/Test.framework/Nested.dylib', 'HBuilder.debug.dylib']
        self.assertEqual(receipt['verified_dylib_paths'], expected)
        app = self.folder / 'signature-inspection/Payload/Yuzhou.app'
        commands = [call.args[0] for call in executed.call_args_list]
        self.assertIn(['codesign', '--verify', '--deep', '--strict', str(app)], commands)
        for relative in expected:
            self.assertIn(['codesign', '--verify', '--strict', str(app / relative)], commands)

    def test_unsigned_root_or_framework_dylib_rejects_the_ipa(self):
        archive, package = self.ipa_fixture()
        for filename in ('HBuilder.debug.dylib', 'Nested.dylib'):
            with self.subTest(filename=filename):
                def reject_dylib(command, **options):
                    result = self.signature_tool_result(command, **options)
                    if command[:3] == ['codesign', '--verify', '--strict'] and Path(command[-1]).name == filename:
                        result.returncode = 1
                    return result
                with patch.object(ipa.subprocess, 'run', side_effect=reject_dylib):
                    with self.assertRaisesRegex(RuntimeError, 'Command failed'):
                        ipa.verify_ipa(archive, package, 'TESTTEAM01', self.folder, self.folder / 'verify.log')


class SceneMigrationTests(unittest.TestCase):
    def test_legacy_host_patch_applies_without_fuzz_and_only_once(self):
        legacy = '''/*
 * @Summary:程序启动时收到push消息
 */
- (BOOL)application:(UIApplication *)application didFinishLaunchingWithOptions:(NSDictionary *)launchOptions
{
    BOOL ret = [PDRCore initEngineWihtOptions:launchOptions
                                  withRunMode:PDRCoreRunModeNormal withDelegate:self];

    UIWindow *window = [[UIWindow alloc] initWithFrame:[UIScreen mainScreen].bounds];
    self.window = window;

    ViewController *viewController = [[ViewController alloc] init];
    self.h5ViewContoller = viewController;

    UINavigationController *navigationController = [[UINavigationController alloc] initWithRootViewController:viewController];
    self.rootViewController = navigationController;
    navigationController.navigationBarHidden = YES;
    {
        [self startMainApp];
        self.h5ViewContoller.showLoadingView = YES;
    }
    self.window.rootViewController = navigationController;
    [self.window makeKeyAndVisible];
    return ret;
}

-(BOOL)getStatusBarHidden {
    return [self.h5ViewContoller getStatusBarHidden];
}
@end

@implementation UINavigationController(Orient)
- (UIInterfaceOrientationMask)supportedInterfaceOrientations {
    return UIInterfaceOrientationMaskPortrait;
}
@end
'''
        legacy = legacy.replace('self.window = window;\n\n', 'self.window = window;\n    \n')
        with tempfile.TemporaryDirectory(prefix='yuzhou-scene-test-') as temporary:
            folder = Path(temporary)
            source = folder / 'AppDelegate.m'
            source.write_text(legacy)
            command = ['patch', '--batch', '--fuzz=0', '-p0',
                       '-i', str(ROOT / 'scripts/ios/AppDelegate-scene.patch')]
            applied = subprocess.run(command + ['--forward'], cwd=folder, capture_output=True, text=True)
            self.assertEqual(applied.returncode, 0, applied.stdout + applied.stderr)
            repeated = subprocess.run(command + ['--forward', '--dry-run'], cwd=folder,
                                      capture_output=True, text=True)
            self.assertNotEqual(repeated.returncode, 0, 'An already migrated host must not be patched twice')
            reversed_patch = subprocess.run(command + ['--reverse'], cwd=folder,
                                            capture_output=True, text=True)
            self.assertEqual(reversed_patch.returncode, 0, reversed_patch.stdout + reversed_patch.stderr)
            self.assertEqual(source.read_text(), legacy)


if __name__ == '__main__':
    unittest.main()
