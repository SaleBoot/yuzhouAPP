"""Desktop publisher checks use only temporary paths and fake external commands."""
import contextlib
import hashlib
import importlib.util
import io
import json
import os
from pathlib import Path
import subprocess
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import patch


SPEC = importlib.util.spec_from_file_location('site_installer', Path(__file__).resolve().parents[2] / 'deploy/install-site.py')
INSTALLER = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(INSTALLER)


class SiteInstallerTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='yuzhou-site-installer-test-')
        self.addCleanup(self.temp.cleanup)
        self.base = Path(self.temp.name).resolve()
        self.root = self.base / 'yuzhou'
        self.old = self.root / 'releases/broker-final'
        self.release = self.root / 'releases/site-synthetic'
        self.commands = []
        self.fail_once = None
        self.failed_command = False
        self.bad_home = False
        self.bad_health = False
        self.stdout, self.stderr = io.StringIO(), io.StringIO()
        self.old.mkdir(parents=True)
        (self.root / 'current').symlink_to(self.old)
        self.shared = self.root / 'shared'
        self.write(self.shared / '.env', b'synthetic private configuration\n')
        (self.shared / '.env').chmod(0o640)
        self.write(self.shared / 'app-update-releases/final/latest.json', b'{"consumer":"1.0.13","broker":"1.0.13"}\n')
        self.write(self.shared / 'app-update-releases/final/consumer.wgt', b'consumer final package')
        self.write(self.shared / 'app-update-releases/final/broker.wgt', b'broker final package')
        (self.shared / 'app-updates').symlink_to(self.shared / 'app-update-releases/final')
        self.write(self.shared / 'storage/upload.pdf', b'unchanged private attachment')
        self.write(self.old / 'backend/bin/jobs.php', b'unchanged jobs implementation')
        (self.old / 'backend/.env').symlink_to(self.shared / '.env')
        self.write(self.old / 'deploy/yuzhou-jobs.service', b'unchanged source unit')
        self.write(self.old / 'release.json', b'{"release_id":"broker-final"}\n')
        self.write(self.old / 'public/index.html', b'<html>previous landing</html>')
        self.write(self.old / 'public/admin/index.html', b'<html>unchanged management</html>')
        self.write(self.old / 'public/admin/assets/admin-old.js', b'unchanged management assets')
        self.write(self.old / 'public/consumer/index.html', b'<html>unchanged consumer H5</html>')
        self.write(self.old / 'public/consumer/assets/consumer-old.js', b'unchanged consumer H5 assets')
        self.write(self.old / 'public/broker/index.html', b'<html>unchanged broker H5</html>')
        self.write(self.old / 'public/broker/assets/broker-old.js', b'unchanged broker H5 assets')
        self.write(self.old / 'public/site-assets/old-hash.js', b'keep existing browser asset')
        self.write(self.old / 'public/app-download.html', b'unchanged App download page')
        self.write(self.old / 'public/favicon.svg', b'<svg>unchanged icon</svg>')
        self.api = self.root / 'releases/api-109-067b058/backend/public/index.php'
        self.write(self.api, b'unchanged fixed API entry')
        self.write(self.release / 'public/index.html', b'<html><script src="/site-assets/new-hash.js"></script>desktop site</html>')
        self.write(self.release / 'public/site-assets/new-hash.js', b'new desktop-only script')
        self.write(self.release / 'install-site.py', b'# synthetic desktop publisher capsule\n')
        self.manifest = {
            'release_id': self.release.name, 'source_commit': 'a' * 40,
            'domain': 'yuzhou.ourideas.top', 'scope': 'desktop website only',
            'files': [{'path': str(file.relative_to(self.release)), 'sha256': self.digest(file)}
                      for file in sorted(self.release.rglob('*')) if file.is_file()],
        }
        self.save_manifest()
        self.vhost = self.base / 'nginx/yuzhou.conf'
        self.original_conf = ('''server {
    server_name yuzhou.ourideas.top;
    root /www/wwwroot/yuzhou/current/public;
    fastcgi_param SCRIPT_FILENAME /www/wwwroot/yuzhou/releases/api-109-067b058/backend/public/index.php;
    location /admin/ { try_files $uri /admin/index.html; }
    location /consumer/ { try_files $uri /consumer/index.html; }
    location /broker/ { try_files $uri /broker/index.html; }
    location ^~ /app-updates/ { alias /www/wwwroot/yuzhou/shared/app-updates/; }
''' + INSTALLER.ROOT_LOCATION + '\n}\n').encode()
        self.write(self.vhost, self.original_conf)
        self.other_vhost = self.vhost.parent / 'other-project.conf'
        self.write(self.other_vhost, b'other project is untouched')
        self.receipt = self.shared / 'desktop-site-deployment.json'
        self.old_receipt = b'{"release_id":"previous-site"}\n'
        self.write(self.receipt, self.old_receipt)
        self.preserved = {str(file): file.read_bytes() for directory in [self.old, self.shared]
                          for file in directory.rglob('*') if file.is_file()}
        self.preserved.pop(str(self.receipt))
        self.preserved[str(self.api)] = self.api.read_bytes()
        self.preserved[str(self.other_vhost)] = self.other_vhost.read_bytes()
        self.preserved_modes = {file: Path(file).stat().st_mode & 0o777 for file in self.preserved}
        self.stack = contextlib.ExitStack()
        self.addCleanup(self.stack.close)
        for item in [patch.object(INSTALLER, 'ROOT', self.root), patch.object(INSTALLER, 'VHOST', self.vhost),
                     patch.object(INSTALLER.os, 'geteuid', return_value=0),
                     patch.object(INSTALLER.sys, 'argv', ['install-site-test', str(self.release)]),
                     patch.object(INSTALLER.subprocess, 'run', side_effect=self.run_command),
                     patch.object(INSTALLER.subprocess, 'check_output', side_effect=self.output_command),
                     patch.object(INSTALLER.time, 'sleep'), contextlib.redirect_stdout(self.stdout),
                     contextlib.redirect_stderr(self.stderr)]:
            self.stack.enter_context(item)

    @staticmethod
    def digest(file):
        return hashlib.sha256(file.read_bytes()).hexdigest()

    @staticmethod
    def write(file, content):
        file.parent.mkdir(parents=True, exist_ok=True)
        file.write_bytes(content)

    def save_manifest(self):
        (self.release / 'site-release.json').write_text(json.dumps(self.manifest))

    def run_command(self, args, **kwargs):
        self.commands.append(tuple(args))
        self.assertIn(tuple(args), [('nginx', '-t'), ('systemctl', 'reload', 'nginx')], 'Another service must never be invoked')
        if self.fail_once == tuple(args) and not self.failed_command:
            self.failed_command = True
            raise subprocess.CalledProcessError(1, args)
        return SimpleNamespace(returncode=0)

    def output_command(self, args, **kwargs):
        self.commands.append(tuple(args))
        self.assertEqual(args[0], 'curl')
        self.assertIn('--noproxy', args)
        self.assertIn('yuzhou.ourideas.top:443:127.0.0.1', args)
        if args[-1] == 'https://yuzhou.ourideas.top/':
            return b'old cached landing' if self.bad_home else (self.release / 'public/index.html').read_bytes()
        self.assertEqual(args[-1], 'https://yuzhou.ourideas.top/api/v1/health')
        return json.dumps({'code': 0, 'data': {'status': 'failed' if self.bad_health else 'ok'}}).encode()

    def assert_preserved(self):
        for file, contents in self.preserved.items():
            self.assertEqual(Path(file).read_bytes(), contents, file)
            self.assertEqual(Path(file).stat().st_mode & 0o777, self.preserved_modes[file], file)

    def assert_restored(self):
        self.assertEqual((self.root / 'current').resolve(), self.old)
        self.assertEqual(self.vhost.read_bytes(), self.original_conf)
        self.assertEqual(self.receipt.read_bytes(), self.old_receipt)
        self.assert_preserved()

    def assert_rejected(self):
        with self.assertRaises((SystemExit, ValueError, RuntimeError)):
            INSTALLER.main()
        self.assert_restored()
        self.assertEqual(self.commands, [], 'Invalid packages must fail before external commands')

    def test_valid_site_changes_only_the_website_and_preserves_all_existing_entries(self):
        INSTALLER.main()
        self.assertEqual((self.root / 'current').resolve(), self.release)
        self.assert_preserved()
        for name in ['backend', 'deploy', 'release.json']:
            self.assertTrue((self.release / name).is_symlink())
            self.assertEqual((self.release / name).resolve(), (self.old / name).resolve())
        for name in ['admin', 'consumer', 'broker', 'app-download.html', 'favicon.svg']:
            self.assertTrue((self.release / 'public' / name).is_symlink())
            self.assertEqual((self.release / 'public' / name).resolve(), (self.old / 'public' / name).resolve())
        self.assertEqual((self.release / 'public/site-assets/old-hash.js').read_bytes(), b'keep existing browser asset')
        updated = self.vhost.read_text()
        self.assertEqual(updated.replace(INSTALLER.SITE_LOCATION, INSTALLER.ROOT_LOCATION).encode(), self.original_conf)
        for alias, destination in [('consumer', '/'), ('consumer/', '/'), ('broker', '/sales'), ('broker/', '/sales')]:
            self.assertEqual(updated.count('location = /' + alias + ' { return 302 ' + destination + '; }'), 1)
        receipt = json.loads(self.receipt.read_text())
        self.assertEqual(receipt['entry_sha256'], self.digest(self.release / 'public/index.html'))
        self.assertEqual(receipt['source_commit'], 'a' * 40)
        self.assertEqual(receipt['previous_release'], str(self.old))
        self.assertFalse(receipt['app_versions_changed'])
        self.assertEqual(self.receipt.stat().st_mode & 0o777, 0o600)

    def test_existing_desktop_routes_are_not_duplicated(self):
        existing = self.original_conf.decode().replace(INSTALLER.ROOT_LOCATION, INSTALLER.SITE_LOCATION).encode()
        self.vhost.write_bytes(existing)
        INSTALLER.main()
        self.assertEqual(self.vhost.read_bytes(), existing)
        self.assert_preserved()

    def test_nested_assets_are_rejected_until_recursive_preservation_is_supported(self):
        old_font = self.old / 'public/site-assets/fonts/old-hash.woff2'
        new_font = self.release / 'public/site-assets/fonts/new-hash.woff2'
        self.write(old_font, b'previous cached browser font')
        self.write(new_font, b'new desktop font')
        self.manifest['files'].append({'path': str(new_font.relative_to(self.release)), 'sha256': self.digest(new_font)})
        self.save_manifest()
        self.assert_rejected()
        self.assertEqual(old_font.read_bytes(), b'previous cached browser font')
        self.assertEqual(new_font.read_bytes(), b'new desktop font')

    def test_cross_release_backend_links_remain_at_the_original_target(self):
        backend = self.root / 'releases/api-jobs-final/backend'
        backend.parent.mkdir()
        (self.old / 'backend').rename(backend)
        (self.old / 'backend').symlink_to(backend)
        INSTALLER.main()
        self.assertEqual((self.release / 'backend').resolve(), backend)
        self.assert_preserved()

    def test_nonroot_and_release_outside_its_project_are_refused(self):
        with patch.object(INSTALLER.os, 'geteuid', return_value=1000):
            self.assert_rejected()
        outside = self.base / 'site-outside'
        outside.mkdir()
        with patch.object(INSTALLER.sys, 'argv', ['test', str(outside)]):
            self.assert_rejected()

    def test_manifest_identity_is_pinned_to_project_scope_and_commit(self):
        original = dict(self.manifest)
        for key, bad in [('domain', 'other.example'), ('scope', 'whole server'), ('source_commit', 'g' * 40), ('release_id', 'another-release')]:
            with self.subTest(key=key):
                self.manifest = dict(original, **{key: bad})
                self.save_manifest()
                self.assert_rejected()

    def test_checksum_mismatch_is_rejected(self):
        (self.release / 'public/index.html').write_bytes(b'tampered website')
        self.assert_rejected()

    def test_absolute_and_noncanonical_paths_are_rejected_even_inside_the_release(self):
        original = list(self.manifest['files'])
        for name in ['public/site-assets/../index.html', 'public/site-assets/../../install-site.py', str(self.release / 'public/index.html')]:
            with self.subTest(path=name):
                file = self.release / name
                self.manifest['files'] = original + [{'path': name, 'sha256': self.digest(file)}]
                self.save_manifest()
                self.assert_rejected()

    def test_manifest_cannot_omit_the_entry_installer_or_all_assets(self):
        original = list(self.manifest['files'])
        for name in [None, 'public/index.html', 'install-site.py', 'public/site-assets/new-hash.js']:
            with self.subTest(omitted=name):
                self.manifest['files'] = [] if name is None else [item for item in original if item['path'] != name]
                self.save_manifest()
                self.assert_rejected()

    def test_unlisted_backend_cannot_replace_the_preserved_jobs_implementation(self):
        self.write(self.release / 'backend/bin/jobs.php', b'forbidden backend replacement')
        self.assert_rejected()

    def test_even_an_empty_unlisted_backend_directory_cannot_shadow_the_original(self):
        (self.release / 'backend').mkdir()
        self.assert_rejected()

    def test_duplicate_manifest_paths_are_rejected(self):
        self.manifest['files'].append(dict(self.manifest['files'][0]))
        self.save_manifest()
        self.assert_rejected()

    def test_unlisted_asset_cannot_bypass_checksums(self):
        self.write(self.release / 'public/site-assets/unlisted.js', b'unverified browser script')
        self.assert_rejected()

    def test_staged_file_symlink_is_rejected(self):
        asset = self.release / 'public/site-assets/new-hash.js'
        asset.unlink()
        asset.symlink_to(self.old / 'public/site-assets/old-hash.js')
        self.assert_rejected()

    def test_unlisted_directory_symlink_is_rejected(self):
        (self.release / 'borrowed-backend').symlink_to(self.old / 'backend')
        self.assert_rejected()

    @unittest.skipUnless(hasattr(os, 'mkfifo'), 'FIFO checks require Unix')
    def test_special_files_are_rejected_without_reading_them(self):
        os.mkfifo(self.release / 'public/site-assets/unsafe.pipe')
        self.assert_rejected()

    def test_unknown_routing_or_api_baseline_refuses_before_activation(self):
        for content in [self.original_conf.replace(INSTALLER.ROOT_LOCATION.encode(), b'    location / { return 404; }'), self.original_conf.replace(b'api-109-067b058', b'another-api')]:
            with self.subTest(content=content):
                self.vhost.write_bytes(content)
                with self.assertRaises(SystemExit):
                    INSTALLER.main()
                self.assertEqual((self.root / 'current').resolve(), self.old)
                self.assertEqual(self.vhost.read_bytes(), content)
                self.assertEqual(self.commands, [])
                self.assert_preserved()

    def test_unexpected_pending_path_is_rejected_before_any_mutation(self):
        pending = self.root / 'current.site-pending'
        pending.write_bytes(b'not an installer symlink')
        self.assert_rejected()
        self.assertEqual(pending.read_bytes(), b'not an installer symlink')

    def test_nginx_validation_and_reload_failures_restore_the_old_site(self):
        self.fail_once = ('nginx', '-t')
        with self.assertRaises(subprocess.CalledProcessError):
            INSTALLER.main()
        self.assert_restored()
        self.assertFalse(any(args[0] == 'curl' for args in self.commands))

    def test_reload_failure_after_current_switch_also_restores_the_old_site(self):
        self.fail_once = ('systemctl', 'reload', 'nginx')
        with self.assertRaises(subprocess.CalledProcessError):
            INSTALLER.main()
        self.assert_restored()
        self.assertEqual(self.commands.count(('systemctl', 'reload', 'nginx')), 2)

    def test_unactivated_home_and_unhealthy_api_trigger_rollback(self):
        self.bad_home = True
        with self.assertRaises(RuntimeError):
            INSTALLER.main()
        self.assert_restored()

    def test_unhealthy_api_triggers_rollback(self):
        self.bad_health = True
        with self.assertRaises(RuntimeError):
            INSTALLER.main()
        self.assert_restored()

    def test_receipt_io_failure_rolls_back_and_preserves_the_previous_receipt(self):
        original_open, original_replace = Path.open, Path.replace

        def failing_open(file, mode='r', *args, **kwargs):
            if file == self.receipt and any(flag in mode for flag in ['w', 'a', 'x', '+']):
                raise OSError('synthetic receipt write failure')
            return original_open(file, mode, *args, **kwargs)

        def failing_replace(file, target):
            if Path(target) == self.receipt:
                raise OSError('synthetic receipt replacement failure')
            return original_replace(file, target)

        with patch.object(Path, 'open', new=failing_open), patch.object(Path, 'replace', new=failing_replace):
            with self.assertRaises(OSError):
                INSTALLER.main()
        self.assert_restored()

    def test_first_site_receipt_failure_removes_temporary_files_and_rolls_back(self):
        self.receipt.unlink()
        with patch.object(INSTALLER.os, 'fsync', side_effect=OSError('synthetic first receipt failure')):
            with self.assertRaises(OSError):
                INSTALLER.main()
        self.assertEqual((self.root / 'current').resolve(), self.old)
        self.assertEqual(self.vhost.read_bytes(), self.original_conf)
        self.assertFalse(self.receipt.exists())
        self.assertEqual(list(self.shared.glob('.desktop-site-deployment.json.*')), [])
        self.assert_preserved()

    def test_rollback_symlink_failure_does_not_skip_vhost_or_receipt_restore(self):
        activate = INSTALLER.activate

        def fail_rollback(link):
            if link == self.old:
                raise OSError('synthetic rollback link failure')
            return activate(link)

        self.bad_health = True
        with patch.object(INSTALLER, 'activate', side_effect=fail_rollback):
            with self.assertRaises(Exception):
                INSTALLER.main()
        self.assertEqual(self.vhost.read_bytes(), self.original_conf)
        self.assertEqual(self.receipt.read_bytes(), self.old_receipt)
        self.assertEqual(self.commands.count(('systemctl', 'reload', 'nginx')), 2)
        self.assert_preserved()


if __name__ == '__main__':
    unittest.main(verbosity=2)
