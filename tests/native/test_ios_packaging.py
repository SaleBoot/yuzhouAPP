"""Checks for native-resource handoff integrity; no Apple signing is simulated."""
import hashlib
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest
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


if __name__ == '__main__':
    unittest.main()
