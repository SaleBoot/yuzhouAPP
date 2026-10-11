"""Synthetic module assets and DEX descriptors; does not compile or install an APK."""
import ast
import json
from pathlib import Path
import re
import shutil
import struct
import tempfile
import unittest
import xml.etree.ElementTree as ET
from zipfile import ZipFile


SCRIPT = Path(__file__).resolve().parents[2] / 'scripts/build-android.py'
# Load only the checks; importing the build script would invoke npm and Gradle.
tree = ast.parse(SCRIPT.read_text())
nodes = [node for node in tree.body if
         isinstance(node, ast.FunctionDef) and node.name in {'assert_core_features', 'configure_modules', 'verify_apk_modules', 'configure_branding'} or
         isinstance(node, ast.Assign) and any(isinstance(target, ast.Name) and target.id in {'CORE_FEATURES', 'ANDROID_ICON_SIZES'} for target in node.targets)]
checks = {'ET': ET, 'ZipFile': ZipFile, 're': re, 'shutil': shutil}
exec(compile(ast.Module(body=nodes, type_ignores=[]), str(SCRIPT), 'exec'), checks)

XML = '''<properties><features>
<feature name="Navigator" value="io.dcloud.feature.ui.navigator.NavigatorUIFeatureImpl"/>
<feature name="NativeUI" value="io.dcloud.feature.ui.nativeui.NativeUIFeatureImpl"/>
<feature name="Console" value="io.dcloud.feature.pdr.LoggerFeatureImpl"/>
<feature name="UI" value="io.dcloud.feature.ui.UIFeatureImpl"><module name="Navigator" value="io.dcloud.feature.ui.NavView"/></feature>
<feature name="VideoPlayer" value="io.dcloud.media.MediaFeatureImpl"/>
<feature name="Push" value="unconfigured.Push"/>
<feature name="Payment" value="unconfigured.Payment"/>
<feature name="Maps" value="unconfigured.Maps"/>
</features><services>
<service name="Downloader" value="io.dcloud.net.DownloaderBootImpl"/>
<service name="push" value="unconfigured.Push"/>
<service name="Statistic" value="unconfigured.Statistic"/>
</services></properties>'''
DESCRIPTORS = b'\0'.join([
    b'Lio/dcloud/feature/ui/navigator/NavigatorUIFeatureImpl;',
    b'Lio/dcloud/feature/ui/nativeui/NativeUIFeatureImpl;',
    b'Lio/dcloud/feature/pdr/LoggerFeatureImpl;',
])


class AndroidModuleTests(unittest.TestCase):
    def test_branding_uses_shared_logo_and_removes_all_sdk_splashes(self):
        assets = SCRIPT.parents[1] / 'apps/shared/assets'
        with tempfile.TemporaryDirectory(prefix='yuzhou-branding-check-') as temporary:
            res = Path(temporary)
            for directory in ['drawable', 'drawable-hdpi', 'drawable-xxhdpi']:
                (res / directory).mkdir()
                (res / directory / 'splash.jpg').write_bytes(b'old SDK image')
            checks['configure_branding'](res, assets)
            self.assertEqual((res / 'drawable/splash.png').read_bytes(), (assets / 'app-icon.png').read_bytes())
            self.assertEqual(list(res.glob('drawable*/splash.jpg')), [])
            for density, size in checks['ANDROID_ICON_SIZES'].items():
                self.assertEqual((res / ('drawable-' + density) / 'yuzhou_icon.png').read_bytes(),
                                 (assets / 'app-icons' / ('icon-' + str(size) + '.png')).read_bytes())

    def test_both_manifests_resolve_opaque_platform_icons_from_cli_project_roots(self):
        root = SCRIPT.parents[1]
        configs = []
        for app in ['consumer', 'broker']:
            project = root / 'apps' / app
            manifest = json.loads((project / 'src/manifest.json').read_text())
            icons = manifest['app-plus']['distribute']['icons']
            configs.append(icons)
            expected = [(icons['ios']['appstore'], 1024)]
            expected += [(icons['android'][density], size) for density, size in checks['ANDROID_ICON_SIZES'].items()]
            for platform, sizes in {
                'iphone': {'app@2x': 120, 'app@3x': 180, 'spotlight@2x': 80, 'spotlight@3x': 120,
                           'settings@2x': 58, 'settings@3x': 87, 'notification@2x': 40, 'notification@3x': 60},
                'ipad': {'app': 76, 'app@2x': 152, 'proapp@2x': 167, 'spotlight': 40, 'spotlight@2x': 80,
                         'settings': 29, 'settings@2x': 58, 'notification': 20, 'notification@2x': 40},
            }.items():
                expected += [(icons['ios'][platform][name], size) for name, size in sizes.items()]
            for relative, size in expected:
                with self.subTest(app=app, path=relative):
                    png = (project / relative).read_bytes()
                    self.assertEqual(png[:8], b'\x89PNG\r\n\x1a\n')
                    self.assertEqual(struct.unpack('>II', png[16:24]), (size, size))
                    self.assertEqual(png[25], 2, 'iOS icons must be RGB without an alpha channel')
        self.assertEqual(configs[0], configs[1])

    def package(self, xml=XML, dex=DESCRIPTORS):
        temp = tempfile.TemporaryDirectory(prefix='yuzhou-module-check-')
        self.addCleanup(temp.cleanup)
        apk = Path(temp.name) / 'synthetic.apk'
        with ZipFile(apk, 'w') as package:
            package.writestr('assets/data/dcloud_properties.xml', xml)
            package.writestr('classes.dex', dex)
        return apk

    def test_filter_keeps_base_features_and_removes_unconfigured_integrations(self):
        root = ET.fromstring(XML)
        checks['configure_modules'](root)
        self.assertEqual({item.get('name') for item in root.findall('features/feature')},
                         {'Navigator', 'NativeUI', 'Console', 'UI', 'VideoPlayer'})
        self.assertEqual([item.get('name') for item in root.findall('services/service')], ['Downloader'])
        self.assertIsNotNone(root.find("features/feature[@name='UI']/module[@name='Navigator']"))

    def test_nested_navigator_does_not_replace_the_required_feature(self):
        root = ET.fromstring(XML)
        root.find('features').remove(root.find("features/feature[@name='Navigator']"))
        with self.assertRaisesRegex(AssertionError, 'Navigator'):
            checks['configure_modules'](root)

    def test_other_missing_or_incorrect_base_mapping_is_rejected(self):
        for name in ['Navigator', 'NativeUI', 'Console']:
            for action in ['remove', 'replace', 'duplicate']:
                with self.subTest(name=name, action=action):
                    root = ET.fromstring(XML)
                    feature = root.find("features/feature[@name='" + name + "']")
                    if action == 'remove':
                        root.find('features').remove(feature)
                    elif action == 'replace':
                        feature.set('value', 'unavailable.Implementation')
                    else:
                        root.find('features').append(ET.fromstring(ET.tostring(feature)))
                    with self.assertRaisesRegex(AssertionError, name):
                        checks['assert_core_features'](root)

    def test_incomplete_sdk_properties_are_rejected(self):
        with self.assertRaisesRegex(AssertionError, 'Invalid official DCloud'):
            checks['configure_modules'](ET.fromstring('<properties><features/></properties>'))

    def test_final_package_assets_and_implementations_are_checked(self):
        checks['verify_apk_modules'](self.package())

    def test_final_package_missing_registration_is_rejected(self):
        root = ET.fromstring(XML)
        root.find('features').remove(root.find("features/feature[@name='Navigator']"))
        with self.assertRaisesRegex(AssertionError, 'Navigator'):
            checks['verify_apk_modules'](self.package(ET.tostring(root)))

    def test_final_package_missing_implementation_is_rejected(self):
        with self.assertRaisesRegex(AssertionError, 'core implementation: Navigator'):
            checks['verify_apk_modules'](self.package(dex=b'no Navigator implementation'))

    def test_multidex_implementation_is_accepted(self):
        apk = self.package(dex=b'other classes')
        with ZipFile(apk, 'a') as package:
            package.writestr('classes2.dex', DESCRIPTORS)
        checks['verify_apk_modules'](apk)


if __name__ == '__main__':
    unittest.main()
