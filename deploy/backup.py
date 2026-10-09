#!/usr/bin/env python3
"""Back up Yuzhou only; restore only into an explicitly isolated drill target."""
import argparse
import contextlib
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import stat
import subprocess
import tarfile
import tempfile
import time

ROOT = Path('/www/wwwroot/yuzhou')
MYSQL = '/www/server/mysql/bin/mysql'
MYSQLDUMP = '/www/server/mysql/bin/mysqldump'
TOOL = 'yuzhou-backup-v1'
BACKUP_NAME = r'yuzhou-backup-\d{8}T\d{6}Z-[a-f0-9]{8}'
OBJECT_NAME = r'(?:[a-f0-9]{48}\.(?:pdf|jpg|png|webp|gif|mp4)|development-license-[a-f0-9]{16}\.png)'
KEEP = 7


def private_directory(path):
    if path.is_symlink(): raise ValueError('Private directory must not be a symbolic link.')
    path.mkdir(mode=0o700, exist_ok=True)
    info = path.stat()
    if not stat.S_ISDIR(info.st_mode) or info.st_uid != os.geteuid():
        raise ValueError('Private directory must be owned by the executing administrator.')
    path.chmod(0o700)


def configuration():
    file = ROOT / 'shared/.env'
    info = file.lstat()
    if not stat.S_ISREG(info.st_mode) or info.st_uid != os.geteuid() or info.st_mode & 0o007:
        raise ValueError('The independent environment file must be a private regular file.')
    values = {}
    for line in file.read_text().splitlines():
        if not line.strip() or line.lstrip().startswith('#') or '=' not in line: continue
        key, value = line.strip().split('=', 1)
        if re.fullmatch(r'[A-Z][A-Z0-9_]*', key): values[key] = value.strip(" \t\r\n\"'")
    expected = {'DB_DRIVER': 'mysql', 'DB_HOST': '127.0.0.1', 'DB_DATABASE': 'yuzhou', 'DB_USERNAME': 'yuzhou_app',
                'STORAGE_PATH': str(ROOT / 'shared/storage')}
    if any(values.get(key) != value for key, value in expected.items()):
        raise ValueError('Configuration does not identify the independent Yuzhou database and storage.')
    if not values.get('DB_PASSWORD') or not re.fullmatch(r'[0-9]{1,5}', values.get('DB_PORT', '')) or not 1 <= int(values['DB_PORT']) <= 65535:
        raise ValueError('Independent MySQL credentials are incomplete.')
    return values


@contextlib.contextmanager
def client_options(values, directory):
    temporary = None
    try:
        with tempfile.NamedTemporaryFile(mode='w', dir=directory, prefix='.yuzhou-client-', delete=False) as handle:
            temporary = Path(handle.name)
            os.chmod(temporary, 0o600)
            handle.write('[client]\n')
            for key, value in values.items():
                if any(ord(char) < 32 for char in str(value)): raise ValueError('Invalid MySQL option value.')
                handle.write('%s="%s"\n' % (key, str(value).replace('\\', '\\\\').replace('"', '\\"')))
            handle.flush()
            os.fsync(handle.fileno())
        yield str(temporary)
    finally:
        if temporary is not None: temporary.unlink(missing_ok=True)


def command(binary, options, values):
    return [binary, '--defaults-extra-file=' + options, '--protocol=TCP', '--host=127.0.0.1',
            '--port=' + str(values['port']), '--user=' + values['user']]


def execute(args, **kwargs):
    environment = {key: value for key, value in os.environ.items() if key not in ['MYSQL_PWD', 'MYSQL_HOST', 'MYSQL_TCP_PORT', 'MYSQL_UNIX_PORT']}
    result = subprocess.run(args, stderr=subprocess.PIPE, env=environment, timeout=1800, **kwargs)
    # Client errors can contain connection details; only the exit code is emitted.
    if result.returncode: raise RuntimeError('Independent MySQL operation failed (exit %s).' % result.returncode)
    return result.stdout


def sha256(file):
    digest = hashlib.sha256()
    with file.open('rb') as handle:
        for block in iter(lambda: handle.read(1024 * 1024), b''): digest.update(block)
    return digest.hexdigest()


def write_receipt(file, value):
    with file.open('x') as handle:
        os.chmod(file, 0o600)
        json.dump(value, handle, indent=2)
        handle.write('\n')
        handle.flush()
        os.fsync(handle.fileno())


def sync_directory(directory):
    descriptor = os.open(directory, os.O_RDONLY)
    try: os.fsync(descriptor)
    finally: os.close(descriptor)


def storage_archive(source, destination):
    if source.is_symlink() or not source.is_dir(): raise ValueError('Independent storage must be a real directory.')
    items = []
    with tarfile.open(destination, 'w') as archive:
        os.chmod(destination, 0o600)
        for file in sorted(source.iterdir()):
            before = file.lstat()
            if not stat.S_ISREG(before.st_mode) or not re.fullmatch(OBJECT_NAME, file.name):
                raise ValueError('Storage contains an unexpected entry; nothing is silently omitted.')
            with file.open('rb') as handle:
                opened = os.fstat(handle.fileno())
                if (opened.st_dev, opened.st_ino) != (before.st_dev, before.st_ino):
                    raise ValueError('Storage object changed while being opened.')
                digest = hashlib.sha256()
                class Reader:
                    def read(self, size):
                        block = handle.read(size)
                        digest.update(block)
                        return block
                member = tarfile.TarInfo(file.name)
                member.size, member.mode, member.mtime = before.st_size, 0o600, int(before.st_mtime)
                archive.addfile(member, Reader())
                after = file.lstat()
                if (before.st_dev, before.st_ino, before.st_size, before.st_mtime_ns) != (after.st_dev, after.st_ino, after.st_size, after.st_mtime_ns):
                    raise ValueError('Storage object changed during backup; retry without file maintenance.')
                items.append({'name': file.name, 'size_bytes': before.st_size, 'sha256': digest.hexdigest()})
    return items


def completed(directory):
    if directory.is_symlink() or not re.fullmatch(BACKUP_NAME, directory.name) or not directory.is_dir():
        raise ValueError('Backup is not a tool-owned completed directory.')
    if set(file.name for file in directory.iterdir()) != {'database.sql', 'storage.tar', 'complete.json'}:
        raise ValueError('Backup contains unexpected or missing files.')
    for file in directory.iterdir():
        if not stat.S_ISREG(file.lstat().st_mode): raise ValueError('Backup files must be regular files.')
    receipt = json.loads((directory / 'complete.json').read_text())
    if receipt.get('tool') != TOOL or receipt.get('status') != 'complete' or receipt.get('backup_id') != directory.name or receipt.get('database') != 'yuzhou':
        raise ValueError('Backup completion receipt is invalid.')
    if type(receipt.get('created_at')) is not int: raise ValueError('Backup completion time is invalid.')
    return receipt


def backup(values, directory):
    pending = Path(tempfile.mkdtemp(prefix='.pending-', dir=directory))
    destination = None
    published = False
    try:
        client = {'host': '127.0.0.1', 'port': values['DB_PORT'], 'user': values['DB_USERNAME'], 'password': values['DB_PASSWORD']}
        with client_options(client, directory) as options:
            metadata = execute(command(MYSQL, options, client) + ['--batch', '--raw', '--skip-column-names', '--database=yuzhou'],
                               input=b"SELECT TABLE_NAME,TABLE_TYPE,ENGINE FROM information_schema.tables WHERE TABLE_SCHEMA='yuzhou' ORDER BY TABLE_NAME;\n",
                               stdout=subprocess.PIPE).decode().splitlines()
            tables = []
            for row in metadata:
                parts = row.split('\t')
                if len(parts) != 3 or parts[1:] != ['BASE TABLE', 'InnoDB']:
                    raise ValueError('Single-transaction backup requires only InnoDB tables; views or other engines need a reviewed backup change.')
                tables.append(parts[0])
            if not tables: raise ValueError('The independent database contains no tables.')
            sql = pending / 'database.sql'
            with sql.open('xb') as handle:
                os.chmod(sql, 0o600)
                execute(command(MYSQLDUMP, options, client) + ['--single-transaction', '--skip-lock-tables', '--skip-add-locks',
                        '--skip-add-drop-table', '--skip-disable-keys', '--skip-triggers', '--no-tablespaces',
                        '--set-gtid-purged=OFF', '--default-character-set=utf8mb4', 'yuzhou'], stdout=handle)
                handle.flush()
                os.fsync(handle.fileno())
            if not sql.stat().st_size: raise RuntimeError('MySQL returned an empty dump.')
        items = storage_archive(ROOT / 'shared/storage', pending / 'storage.tar')
        with (pending / 'storage.tar').open('rb') as handle: os.fsync(handle.fileno())
        deployment = json.loads((ROOT / 'shared/deployment.json').read_text())
        identity = 'yuzhou-backup-' + time.strftime('%Y%m%dT%H%M%SZ', time.gmtime()) + '-' + os.urandom(4).hex()
        receipt = {'tool': TOOL, 'status': 'complete', 'backup_id': identity, 'database': 'yuzhou', 'created_at': int(time.time()),
                   'source_commit': deployment['source_commit'], 'release_id': deployment['release_id'],
                   'tables': tables,
                   'files': {name: {'size_bytes': (pending / name).stat().st_size, 'sha256': sha256(pending / name)} for name in ['database.sql', 'storage.tar']},
                   'storage_objects': items,
                   'consistency': 'InnoDB single-transaction dump, then immutable storage copy; later/orphan files may be included. No DDL or file deletion during backup.'}
        write_receipt(pending / 'complete.json', receipt)
        sync_directory(pending)
        destination = directory / identity
        pending.rename(destination)
        sync_directory(directory)
        published = True
        owned = []
        for candidate in directory.iterdir():
            if candidate == destination: continue
            try: owned.append((completed(candidate)['created_at'], candidate))
            except (ValueError, OSError, KeyError, json.JSONDecodeError): continue
        for _, candidate in sorted(owned, reverse=True)[KEEP - 1:]:
            try: shutil.rmtree(candidate)
            except OSError: pass
        return {'status': 'complete', 'backup_id': identity, 'storage_objects': len(items), 'source_commit': receipt['source_commit']}
    finally:
        if pending.exists(): shutil.rmtree(pending)
        if destination is not None and not published and destination.exists(): shutil.rmtree(destination)


def restore(values, directory, args):
    source = Path(args.backup)
    if source.parent != directory or source.resolve() != source: raise ValueError('Select an absolute completed backup under the independent backup directory.')
    receipt = completed(source)
    for name in ['database.sql', 'storage.tar']:
        expected = receipt['files'][name]
        if expected['size_bytes'] != (source / name).stat().st_size or expected['sha256'] != sha256(source / name):
            raise ValueError('Backup checksum verification failed; no restore was started.')
    if not re.fullmatch(r'yuzhou_restore_[a-z0-9_]{1,49}', args.database): raise ValueError('Restore database must be an explicit yuzhou_restore_* drill database.')
    credentials = Path(args.credentials)
    info = credentials.lstat()
    if not stat.S_ISREG(info.st_mode) or info.st_uid != os.geteuid() or stat.S_IMODE(info.st_mode) != 0o600:
        raise ValueError('Restore credential JSON must be an administrator-owned 0600 regular file.')
    client = json.loads(credentials.read_text())
    if set(client) != {'host', 'port', 'user', 'password'} or client['host'] != '127.0.0.1' or str(client['port']) != values['DB_PORT'] or not re.fullmatch(r'yuzhou_restore_[a-z0-9_]{1,17}', client['user']) or not client['password']:
        raise ValueError('Restore requires a dedicated local yuzhou_restore_* account.')
    drill_root = ROOT / 'restore-drills'
    target = Path(args.storage_target)
    if target.parent != drill_root or not re.fullmatch(r'[a-z0-9][a-z0-9_-]{0,79}', target.name) or target.exists() or target.is_symlink():
        raise ValueError('Restore storage must be a new explicit directory directly under restore-drills.')
    with client_options(client, directory) as options:
        mysql = command(MYSQL, options, client) + ['--batch', '--raw', '--skip-column-names', '--skip-force', '--database=' + args.database]
        grants = execute(mysql, input=b'SHOW GRANTS;\n', stdout=subprocess.PIPE).decode()
        has_database_grant = False
        for grant in grants.splitlines():
            if 'WITH GRANT OPTION' in grant: raise ValueError('Restore account must not delegate privileges.')
            if re.match(r'^GRANT USAGE ON \*\.\* TO ', grant): continue
            # MySQL 5.7 database grants treat unescaped underscores as wildcards.
            scope = args.database.replace('_', r'\_')
            if not re.match(r'^GRANT [A-Z_, ]+ ON `?' + re.escape(scope) + r'`?\.\* TO ', grant):
                raise ValueError('Restore account has permissions outside its drill database.')
            has_database_grant = True
        if not has_database_grant: raise ValueError('Restore account has no isolated drill database grant.')
        count = execute(mysql, input=b'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE();\n', stdout=subprocess.PIPE).strip()
        if count != b'0': raise ValueError('Restore database is not empty; existing tables are never overwritten.')
        private_directory(drill_root)
        target.mkdir(mode=0o700)
        expected = {item['name']: item for item in receipt['storage_objects']}
        if len(expected) != len(receipt['storage_objects']) or any(not re.fullmatch(OBJECT_NAME, name) for name in expected):
            raise ValueError('Backup object inventory is invalid.')
        seen = set()
        with tarfile.open(source / 'storage.tar', 'r:') as archive:
            for member in archive:
                if not member.isfile() or member.name not in expected or member.name in seen or member.size != expected[member.name]['size_bytes']:
                    raise ValueError('Backup archive contains an unexpected object.')
                file = target / member.name
                with archive.extractfile(member) as incoming, file.open('xb') as output:
                    os.chmod(file, 0o600)
                    shutil.copyfileobj(incoming, output)
                    output.flush()
                    os.fsync(output.fileno())
                if sha256(file) != expected[member.name]['sha256']: raise ValueError('Restored object checksum differs.')
                seen.add(member.name)
        if seen != set(expected): raise ValueError('Backup archive is missing an object.')
        # MySQL DDL is not transactional: failed drills remain inspectable, no database is ever dropped.
        with (source / 'database.sql').open('rb') as handle: execute(mysql, stdin=handle, stdout=subprocess.DEVNULL)
        tables = execute(mysql, input=b'SELECT TABLE_NAME FROM information_schema.tables WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME;\n', stdout=subprocess.PIPE).decode().splitlines()
        if tables != receipt['tables']: raise ValueError('Restored table inventory differs; no completion receipt was issued.')
        result = {'tool': TOOL, 'status': 'complete', 'backup_id': receipt['backup_id'], 'database': args.database,
                  'storage_objects': len(seen), 'tables': len(tables), 'source_commit': receipt['source_commit'], 'activated': False}
        write_receipt(target / 'restore-complete.json', result)
        try: sync_directory(target)
        except OSError:
            (target / 'restore-complete.json').unlink()
            raise
        return result


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    commands = parser.add_subparsers(dest='action', required=True)
    commands.add_parser('backup')
    restore_parser = commands.add_parser('restore')
    for option in ['backup', 'database', 'credentials', 'storage-target']: restore_parser.add_argument('--' + option, required=True)
    args = parser.parse_args()
    if os.geteuid() != 0: raise SystemExit('Run as administrator; private backups are never web-accessible.')
    if ROOT.resolve() != ROOT: raise SystemExit('Independent project root must not be redirected.')
    values = configuration()
    directory = ROOT / 'backups'
    private_directory(directory)
    descriptor = os.open(directory / '.backup.lock', os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW, 0o600)
    with os.fdopen(descriptor, 'w') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        result = backup(values, directory) if args.action == 'backup' else restore(values, directory, args)
    print(json.dumps(result))


if __name__ == '__main__':
    try: main()
    except (OSError, ValueError, RuntimeError, KeyError, subprocess.TimeoutExpired, tarfile.TarError):
        raise SystemExit('Yuzhou backup/restore failed; no new completion receipt was issued. Inspect the private target and retry after correcting the cause.')
