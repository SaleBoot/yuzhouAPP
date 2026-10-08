"""Installer regression checks: synthetic paths, mocked commands and HTTPS only."""
import contextlib
import hashlib
import importlib.util
import io
import json
import os
from pathlib import Path
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import patch

SPEC = importlib.util.spec_from_file_location('installer', Path(__file__).resolve().parents[2] / 'deploy/install-release.py')
INSTALLER = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(INSTALLER)


class Response:
    def __enter__(self): return self
    def __exit__(self, *args): pass
    def read(self): return b'{"data":{"status":"ok"}}'


class InstallerTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='yuzhou-installer-test-')
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name).resolve()
        self.release = self.root / 'releases/new'
        (self.release / 'deploy').mkdir(parents=True)
        (self.release / 'backend').mkdir()
        self.old = self.root / 'releases/old'
        self.old.mkdir()
        (self.root / 'current').symlink_to(self.old)
        self.shared = self.root / 'shared'
        self.shared.mkdir()
        (self.shared / '.env').write_text('synthetic configuration')
        (self.shared / 'deployment.json').write_text('{"release_id":"old"}')
        (self.root / 'certs').mkdir()
        (self.root / 'certs/fullchain.pem').write_text('synthetic certificate')
        self.vhost = self.root / 'vhost.conf'
        self.vhost.write_bytes(b'old-site')
        self.systemd = self.root / 'systemd'
        self.systemd.mkdir()
        self.units = ['yuzhou-jobs.service', 'yuzhou-jobs.timer', 'yuzhou-certificate.service', 'yuzhou-certificate.timer']
        for name in self.units:
            (self.systemd / name).write_bytes(b'old-unit')
            (self.release / 'deploy' / name).write_bytes(b'new-unit')
        (self.release / 'deploy/nginx.conf.example').write_bytes(b'new-site')
        (self.release / 'deploy/preflight.php').write_text('<?php /* synthetic */')
        files = [{'path': str(file.relative_to(self.release)), 'sha256': hashlib.sha256(file.read_bytes()).hexdigest()}
                 for file in self.release.rglob('*') if file.is_file()]
        (self.release / 'release.json').write_text(json.dumps({
            'domain': 'yuzhou.ourideas.top', 'source_commit': 'a' * 40,
            'release_id': 'synthetic-new', 'files': files}))
        self.states = {name: {'enabled': True, 'active': False} for name in self.units if name.endswith('.timer')}
        self.commands = []
        self.fail = None
        self.stderr = io.StringIO()
        self.stdout = io.StringIO()
        self.stack = contextlib.ExitStack()
        self.addCleanup(self.stack.close)
        patches = [
            patch.object(INSTALLER, 'ROOT', self.root), patch.object(INSTALLER, 'VHOST', self.vhost),
            patch.object(INSTALLER, 'Path', self.safe_path), patch.object(INSTALLER, 'run', self.run_command),
            patch.object(INSTALLER.sys, 'argv', ['installer-test', str(self.release)]),
            patch.object(INSTALLER.os, 'geteuid', return_value=0), patch.object(INSTALLER.os, 'chown'),
            patch.object(INSTALLER.pwd, 'getpwnam', return_value=SimpleNamespace(pw_uid=os.getuid(), pw_gid=os.getgid())),
            patch.object(INSTALLER.subprocess, 'run', side_effect=self.subprocess),
            patch.object(INSTALLER.urllib.request, 'urlopen', return_value=Response()),
            contextlib.redirect_stderr(self.stderr), contextlib.redirect_stdout(self.stdout),
        ]
        for item in patches: self.stack.enter_context(item)

    def safe_path(self, value):
        value = str(value)
        return self.systemd / Path(value).name if value.startswith('/etc/systemd/system/') else Path(value)

    def subprocess(self, args, **kwargs):
        if args[:2] in [['systemctl', 'is-enabled'], ['systemctl', 'is-active']]:
            state = args[1][3:]
            return SimpleNamespace(returncode=0 if self.states[args[2]][state] else 1)
        return SimpleNamespace(returncode=0)

    def run_command(self, *args):
        self.commands.append(args)
        if self.fail and self.fail(args): raise RuntimeError('synthetic command failure')
        if args[0] != 'systemctl': return
        if args[1] in ['enable', 'disable', 'start', 'stop']:
            for name in [value for value in args[2:] if value.endswith('.timer')]:
                if args[1] in ['enable', 'disable']: self.states[name]['enabled'] = args[1] == 'enable'
                if args[1] in ['start', 'stop'] or '--now' in args:
                    self.states[name]['active'] = args[1] in ['enable', 'start']

    def assert_site_restored(self):
        self.assertEqual((self.root / 'current').resolve(), self.old)
        self.assertEqual(self.vhost.read_bytes(), b'old-site')

    def assert_job_failure(self):
        self.fail = lambda args: args == ('systemctl', 'start', 'yuzhou-jobs.service')
        with self.assertRaisesRegex(RuntimeError, 'synthetic'): INSTALLER.main()
        self.assert_site_restored()

    def test_enabled_but_inactive_timer_stays_inactive(self):
        self.assert_job_failure()
        self.assertTrue(all(state == {'enabled': True, 'active': False} for state in self.states.values()))

    def test_disabled_but_active_timer_stays_active(self):
        for state in self.states.values(): state.update(enabled=False, active=True)
        self.assert_job_failure()
        self.assertTrue(all(state == {'enabled': False, 'active': True} for state in self.states.values()))

    def test_repeated_daemon_reload_failure_cannot_skip_site_restore(self):
        self.fail = lambda args: args == ('systemctl', 'daemon-reload')
        with self.assertRaisesRegex(RuntimeError, 'synthetic'): INSTALLER.main()
        self.assert_site_restored()
        self.assertIn('Rollback step failed: reload restored units', self.stderr.getvalue())
        self.assertEqual(self.commands.count((INSTALLER.NGINX, '-s', 'reload')), 2)
        for name in self.units: self.assertEqual((self.systemd / name).read_bytes(), b'old-unit')

    def test_atomic_receipt_io_failure_restores_site_and_keeps_previous_receipt(self):
        with patch.object(INSTALLER.os, 'fsync', side_effect=OSError('synthetic disk failure')):
            with self.assertRaisesRegex(OSError, 'synthetic'): INSTALLER.main()
        self.assert_site_restored()
        self.assertEqual(json.loads((self.shared / 'deployment.json').read_text()), {'release_id': 'old'})
        self.assertEqual(list(self.shared.glob('.deployment.json.*')), [])
        self.assertTrue(all(not state['active'] for state in self.states.values()))

    def test_receipt_directory_error_also_rolls_back(self):
        receipt = self.shared / 'deployment.json'
        receipt.unlink()
        receipt.mkdir()
        with self.assertRaises(OSError): INSTALLER.main()
        self.assert_site_restored()
        self.assertTrue(receipt.is_dir())
        self.assertEqual(list(self.shared.glob('.deployment.json.*')), [])

    def test_nginx_activation_failure_restores_site_without_touching_timers(self):
        self.fail = lambda args: args == (INSTALLER.NGINX, '-t') and len(self.commands) == 2
        with self.assertRaisesRegex(RuntimeError, 'synthetic'): INSTALLER.main()
        self.assert_site_restored()
        self.assertFalse(any(args[0] == 'systemctl' for args in self.commands))

    def test_failed_first_install_removes_new_site_and_units(self):
        (self.root / 'current').unlink()
        self.vhost.unlink()
        for name in self.units: (self.systemd / name).unlink()
        for state in self.states.values(): state.update(enabled=False, active=False)
        self.assert_job_failure_without_previous_site()

    def assert_job_failure_without_previous_site(self):
        self.fail = lambda args: args == ('systemctl', 'start', 'yuzhou-jobs.service')
        with self.assertRaisesRegex(RuntimeError, 'synthetic'): INSTALLER.main()
        self.assertFalse((self.root / 'current').is_symlink())
        self.assertFalse(self.vhost.exists())
        self.assertTrue(all(not (self.systemd / name).exists() for name in self.units))

    def test_success_writes_receipt_atomically_and_activates_only_owned_paths(self):
        INSTALLER.main()
        self.assertEqual((self.root / 'current').resolve(), self.release)
        self.assertEqual(self.vhost.read_bytes(), b'new-site')
        receipt = json.loads((self.shared / 'deployment.json').read_text())
        self.assertEqual(receipt['release_id'], 'synthetic-new')
        self.assertFalse(receipt['database_shared_with_existing_site'])
        self.assertEqual(list(self.shared.glob('.deployment.json.*')), [])
        for name in ['.env', 'storage', 'runtime']:
            self.assertEqual((self.release / 'backend' / name).resolve(), (self.shared / name).resolve())
        self.assertTrue(all(state == {'enabled': True, 'active': True} for state in self.states.values()))


if __name__ == '__main__': unittest.main(verbosity=2)
