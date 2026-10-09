"""Synthetic private paths and executable fake MySQL clients; no real database calls."""
import contextlib
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import stat
import sys
import tarfile
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import patch

SPEC = importlib.util.spec_from_file_location('yuzhou_backup', Path(__file__).resolve().parents[2] / 'deploy/backup.py')
BACKUP = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(BACKUP)
FAKE_CLIENT = r'''
import json, os, pathlib, stat, sys
state_file = pathlib.Path(os.environ['YUZHOU_BACKUP_TEST_STATE'])
state = json.loads(state_file.read_text())
options = pathlib.Path(sys.argv[1].split('=', 1)[1])
call = {'args': sys.argv[1:], 'mode': stat.S_IMODE(options.stat().st_mode),
        'has_password': 'password="synthetic-secret"' in options.read_text(), 'mysql_pwd_present': 'MYSQL_PWD' in os.environ}
state.setdefault('calls', []).append(call)
state_file.write_text(json.dumps(state))
if 'mysqldump' in pathlib.Path(sys.argv[0]).name:
    if state.get('fail_dump'):
        sys.stdout.write('incomplete SQL')
        sys.stderr.write('synthetic-secret connection detail must not be emitted')
        sys.exit(3)
    sys.stdout.write('CREATE TABLE `synthetic` (`id` INT PRIMARY KEY);\nINSERT INTO `synthetic` VALUES (1);\n')
else:
    sql = sys.stdin.buffer.read().decode()
    if 'SHOW GRANTS' in sql:
        print(state.get('grants', "GRANT USAGE ON *.* TO 'yuzhou_restore_test'@'127.0.0.1'\nGRANT ALL PRIVILEGES ON `yuzhou\\_restore\\_test`.* TO 'yuzhou_restore_test'@'127.0.0.1'"))
    elif 'COUNT(*)' in sql:
        print(state.get('table_count', 0))
    elif 'SELECT TABLE_NAME FROM' in sql:
        print(state.get('restored_tables', 'synthetic'))
    elif 'information_schema.tables' in sql:
        print(state.get('metadata', 'synthetic\tBASE TABLE\tInnoDB'))
    elif state.get('fail_restore'):
        sys.stderr.write('synthetic-secret')
        sys.exit(4)
'''


class BackupTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='yuzhou-backup-test-')
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name).resolve()
        shared = self.root / 'shared'
        shared.mkdir()
        self.storage = shared / 'storage'
        self.storage.mkdir()
        self.name = 'a' * 48 + '.pdf'
        (self.storage / self.name).write_bytes(b'%PDF-1.4 synthetic private file\n')
        (shared / '.env').write_text('\n'.join(['DB_DRIVER=mysql', 'DB_HOST=127.0.0.1', 'DB_PORT=3306', 'DB_DATABASE=yuzhou',
            'DB_USERNAME=yuzhou_app', 'DB_PASSWORD=synthetic-secret', 'STORAGE_PATH=' + str(self.storage)]))
        (shared / '.env').chmod(0o640)
        (shared / 'deployment.json').write_text(json.dumps({'source_commit': 'a' * 40, 'release_id': 'synthetic-release'}))
        self.directory = self.root / 'backups'
        BACKUP.private_directory(self.directory)
        self.state = self.root / 'state.json'
        self.state.write_text('{}')
        self.mysql = self.root / 'fake-mysql'
        self.dump = self.root / 'fake-mysqldump'
        for file in [self.mysql, self.dump]:
            file.write_text('#!' + sys.executable + '\n' + FAKE_CLIENT)
            file.chmod(0o700)
        self.stack = contextlib.ExitStack()
        self.addCleanup(self.stack.close)
        for item in [patch.object(BACKUP, 'ROOT', self.root), patch.object(BACKUP, 'MYSQL', str(self.mysql)),
                     patch.object(BACKUP, 'MYSQLDUMP', str(self.dump)),
                     patch.dict(os.environ, {'YUZHOU_BACKUP_TEST_STATE': str(self.state), 'MYSQL_PWD': 'must-be-removed'})]:
            self.stack.enter_context(item)
        self.values = BACKUP.configuration()

    def change_state(self, **updates):
        value = json.loads(self.state.read_text())
        value.update(updates)
        self.state.write_text(json.dumps(value))

    def make_backup(self):
        result = BACKUP.backup(self.values, self.directory)
        return self.directory / result['backup_id']

    def restore_args(self, source):
        credentials = self.root / 'restore-credentials.json'
        credentials.write_text(json.dumps({'host': '127.0.0.1', 'port': '3306', 'user': 'yuzhou_restore_test', 'password': 'synthetic-secret'}))
        credentials.chmod(0o600)
        return SimpleNamespace(backup=str(source), database='yuzhou_restore_test', credentials=str(credentials),
                               storage_target=str(self.root / 'restore-drills/test'))

    def assert_no_pending(self):
        self.assertEqual(list(self.directory.glob('.pending-*')), [])
        self.assertEqual(list(self.directory.glob('.yuzhou-client-*')), [])

    def test_backup_hashes_permissions_and_no_password_in_arguments(self):
        source = self.make_backup()
        receipt = BACKUP.completed(source)
        self.assertEqual(receipt['tables'], ['synthetic'])
        self.assertEqual(receipt['storage_objects'][0]['sha256'], hashlib.sha256((self.storage / self.name).read_bytes()).hexdigest())
        for name, item in receipt['files'].items(): self.assertEqual(item['sha256'], BACKUP.sha256(source / name))
        for path in [self.directory, source]: self.assertEqual(stat.S_IMODE(path.stat().st_mode), 0o700)
        for file in source.iterdir(): self.assertEqual(stat.S_IMODE(file.stat().st_mode), 0o600)
        calls = json.loads(self.state.read_text())['calls']
        self.assertTrue(all(call['mode'] == 0o600 and call['has_password'] and not call['mysql_pwd_present'] for call in calls))
        arguments = calls[-1]['args']
        self.assertEqual(arguments[-1], 'yuzhou')
        for value in ['--single-transaction', '--skip-lock-tables', '--skip-add-locks', '--skip-add-drop-table', '--no-tablespaces']:
            self.assertIn(value, arguments)
        self.assertNotIn('synthetic-secret', repr(calls))
        self.assert_no_pending()

    def test_failed_dump_does_not_publish_receipt_or_expose_client_error(self):
        self.change_state(fail_dump=True)
        with self.assertRaisesRegex(RuntimeError, r'exit 3') as error: self.make_backup()
        self.assertNotIn('synthetic-secret', str(error.exception))
        self.assertEqual(list(self.directory.glob('yuzhou-backup-*')), [])
        self.assert_no_pending()

    def test_non_innodb_tables_are_rejected_before_dump(self):
        self.change_state(metadata='synthetic\tBASE TABLE\tMyISAM')
        with self.assertRaisesRegex(ValueError, 'InnoDB'): self.make_backup()
        self.assertEqual(len(json.loads(self.state.read_text())['calls']), 1)
        self.assert_no_pending()

    def test_wrong_database_environment_and_public_env_permissions_rejected(self):
        file = self.root / 'shared/.env'
        file.write_text(file.read_text().replace('DB_DATABASE=yuzhou', 'DB_DATABASE=another_site'))
        with self.assertRaisesRegex(ValueError, 'independent'): BACKUP.configuration()
        file.chmod(0o644)
        with self.assertRaisesRegex(ValueError, 'private regular'): BACKUP.configuration()

    def test_symlink_or_unexpected_private_storage_fails_without_complete(self):
        outsider = self.root / 'unrelated'
        outsider.write_text('never read')
        (self.storage / ('b' * 48 + '.pdf')).symlink_to(outsider)
        with self.assertRaisesRegex(ValueError, 'unexpected'): self.make_backup()
        self.assertEqual(outsider.read_text(), 'never read')
        self.assert_no_pending()

    def test_receipt_disk_failure_does_not_publish_backup(self):
        with patch.object(BACKUP, 'write_receipt', side_effect=OSError('synthetic full disk')):
            with self.assertRaises(OSError): self.make_backup()
        self.assertEqual(list(self.directory.glob('yuzhou-backup-*')), [])
        self.assert_no_pending()

    def test_retention_never_removes_unowned_or_incomplete_directories(self):
        unowned = self.directory / 'operator-notes'
        unowned.mkdir()
        (unowned / 'keep.txt').write_text('keep')
        suspicious = self.directory / 'yuzhou-backup-20000101T000000Z-00000000'
        suspicious.mkdir()
        (suspicious / 'complete.json').write_text('{"tool":"other-tool"}')
        with patch.object(BACKUP, 'KEEP', 1):
            self.make_backup()
            newest = self.make_backup()
        owned = []
        for path in self.directory.iterdir():
            try: BACKUP.completed(path); owned.append(path)
            except ValueError: pass
        self.assertEqual(len(owned), 1)
        self.assertEqual(owned, [newest])
        self.assertEqual((unowned / 'keep.txt').read_text(), 'keep')
        self.assertTrue(suspicious.exists())

    def test_isolated_restore_verifies_objects_and_leaves_inactive_receipt(self):
        source = self.make_backup()
        args = self.restore_args(source)
        result = BACKUP.restore(self.values, self.directory, args)
        target = Path(args.storage_target)
        self.assertEqual((target / self.name).read_bytes(), (self.storage / self.name).read_bytes())
        self.assertEqual(stat.S_IMODE((target / self.name).stat().st_mode), 0o600)
        self.assertEqual(stat.S_IMODE(target.stat().st_mode), 0o700)
        self.assertEqual(result['database'], 'yuzhou_restore_test')
        self.assertFalse(result['activated'])
        self.assertTrue((target / 'restore-complete.json').is_file())
        self.assert_no_pending()

    def test_existing_seed_license_filename_is_backed_up_and_restored(self):
        name = 'development-license-' + 'b' * 16 + '.png'
        (self.storage / name).write_bytes(b'synthetic seed license PNG')
        source = self.make_backup()
        self.assertIn(name, [item['name'] for item in BACKUP.completed(source)['storage_objects']])
        args = self.restore_args(source)
        BACKUP.restore(self.values, self.directory, args)
        self.assertEqual((Path(args.storage_target) / name).read_bytes(), b'synthetic seed license PNG')

    def test_restore_rejects_archive_path_escape_even_with_updated_container_hash(self):
        source = self.make_backup()
        with tarfile.open(source / 'storage.tar', 'w') as archive:
            member = tarfile.TarInfo('../../escape.pdf')
            member.size = 0
            archive.addfile(member)
        receipt = BACKUP.completed(source)
        receipt['files']['storage.tar'] = {'size_bytes': (source / 'storage.tar').stat().st_size, 'sha256': BACKUP.sha256(source / 'storage.tar')}
        (source / 'complete.json').write_text(json.dumps(receipt))
        args = self.restore_args(source)
        with self.assertRaisesRegex(ValueError, 'unexpected'): BACKUP.restore(self.values, self.directory, args)
        self.assertFalse((self.root / 'escape.pdf').exists())
        self.assertFalse((Path(args.storage_target) / 'restore-complete.json').exists())

    def test_restore_table_inventory_mismatch_keeps_drill_incomplete(self):
        args = self.restore_args(self.make_backup())
        self.change_state(restored_tables='missing_or_unexpected_table')
        with self.assertRaisesRegex(ValueError, 'table inventory'): BACKUP.restore(self.values, self.directory, args)
        self.assertFalse((Path(args.storage_target) / 'restore-complete.json').exists())

    def test_restore_rejects_live_or_nonempty_or_overprivileged_database(self):
        args = self.restore_args(self.make_backup())
        args.database = 'yuzhou'
        with self.assertRaisesRegex(ValueError, 'drill database'): BACKUP.restore(self.values, self.directory, args)
        args.database = 'yuzhou_restore_test'
        self.change_state(grants="GRANT ALL PRIVILEGES ON *.* TO 'yuzhou_restore_test'@'127.0.0.1'")
        with self.assertRaisesRegex(ValueError, 'outside'): BACKUP.restore(self.values, self.directory, args)
        self.change_state(grants="GRANT ALL PRIVILEGES ON `yuzhou\\_restore\\_test`.* TO 'yuzhou_restore_test'@'127.0.0.1'", table_count=1)
        with self.assertRaisesRegex(ValueError, 'not empty'): BACKUP.restore(self.values, self.directory, args)
        self.assertFalse(Path(args.storage_target).exists())

    def test_restore_rejects_database_underscore_wildcard_grants(self):
        args = self.restore_args(self.make_backup())
        self.change_state(grants="GRANT ALL PRIVILEGES ON `yuzhou_restore_test`.* TO 'yuzhou_restore_test'@'127.0.0.1'")
        with self.assertRaisesRegex(ValueError, 'outside'): BACKUP.restore(self.values, self.directory, args)
        self.assertFalse(Path(args.storage_target).exists())

    def test_restore_checks_hashes_before_mysql_and_private_credentials(self):
        source = self.make_backup()
        args = self.restore_args(source)
        (source / 'database.sql').write_text('tampered')
        before = len(json.loads(self.state.read_text())['calls'])
        with self.assertRaisesRegex(ValueError, 'checksum'): BACKUP.restore(self.values, self.directory, args)
        self.assertEqual(len(json.loads(self.state.read_text())['calls']), before)
        self.assertFalse(Path(args.storage_target).exists())
        source = self.make_backup()
        args = self.restore_args(source)
        Path(args.credentials).chmod(0o644)
        with self.assertRaisesRegex(ValueError, '0600'): BACKUP.restore(self.values, self.directory, args)

    def test_restore_failure_has_no_completion_and_never_drops_database(self):
        args = self.restore_args(self.make_backup())
        self.change_state(fail_restore=True)
        with self.assertRaisesRegex(RuntimeError, 'exit 4'): BACKUP.restore(self.values, self.directory, args)
        target = Path(args.storage_target)
        self.assertTrue((target / self.name).exists())
        self.assertFalse((target / 'restore-complete.json').exists())
        self.assertNotIn('DROP', (Path(args.backup) / 'database.sql').read_text())
        self.assert_no_pending()


if __name__ == '__main__': unittest.main(verbosity=2)
