"""Synthetic module assets and DEX descriptors; does not compile or install an APK."""
import ast
from pathlib import Path
import re
import tempfile
import unittest
import xml.etree.ElementTree as ET
from zipfile import ZipFile


SCRIPT = Path(__file__).resolve().parents[2] / 'scripts/build-android.py'
# Load only the checks; importing the build script would invoke npm and Gradle.
tree = ast.parse(SCRIPT.read_text())
nodes = [node for node in tree.body if
         isinstance(node, ast.FunctionDef) and node.name in {'assert_core_features', 'configure_modules', 'verify_apk_modules'} or
         isinstance(node, ast.Assign) and any(isinstance(target, ast.Name) and target.id == 'CORE_FEATURES' for target in node.targets)]
checks = {'ET': ET, 'ZipFile': ZipFile, 're': re}
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
