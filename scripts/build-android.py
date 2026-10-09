#!/usr/bin/env python3
"""Build two independent temporary APKs with the official DCloud offline SDK."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import xml.etree.ElementTree as ET
from zipfile import ZipFile

if not __debug__:
    raise SystemExit('Optimized Python disables package safety checks; run without -O or PYTHONOPTIMIZE.')

CORE_FEATURES = {
    'Navigator': 'io.dcloud.feature.ui.navigator.NavigatorUIFeatureImpl',
    'NativeUI': 'io.dcloud.feature.ui.nativeui.NativeUIFeatureImpl',
    'Console': 'io.dcloud.feature.pdr.LoggerFeatureImpl',
}


def assert_core_features(root):
    for name, implementation in CORE_FEATURES.items():
        matches = root.findall("features/feature[@name='" + name + "']")
        assert len(matches) == 1 and matches[0].get('value') == implementation, 'Missing or invalid DCloud core feature: ' + name


def configure_modules(root):
    features, services = root.find('features'), root.find('services')
    assert features is not None and services is not None, 'Invalid official DCloud module properties'
    allowed = {'Device', 'File', 'Storage', 'Cache', 'Camera', 'Gallery', 'UI', 'Downloader', 'Uploader', 'Zip', 'Runtime', 'VideoPlayer', 'XMLHttpRequest', 'NativeObj', 'Invocation'} | CORE_FEATURES.keys()
    for feature in list(features):
        if feature.attrib.get('name') not in allowed:
            features.remove(feature)
    for service in list(services):
        if service.attrib.get('name') != 'Downloader':
            services.remove(service)
    assert_core_features(root)


def verify_apk_modules(apk):
    with ZipFile(apk) as package:
        assert_core_features(ET.fromstring(package.read('assets/data/dcloud_properties.xml')))
        dex_files = [package.read(name) for name in package.namelist() if re.fullmatch(r'classes(?:[2-9]|[1-9][0-9]+)?\.dex', name)]
        for name, implementation in CORE_FEATURES.items():
            descriptor = ('L' + implementation.replace('.', '/') + ';').encode()
            assert any(descriptor in dex for dex in dex_files), 'APK lacks DCloud core implementation: ' + name


ROOT = Path(__file__).resolve().parents[1]
parser = argparse.ArgumentParser()
parser.add_argument('app', choices=['consumer', 'broker'])
parser.add_argument('--dcloud-sdk', type=Path, required=True)
parser.add_argument('--release-notes', type=Path, required=True)
parser.add_argument('--android-sdk', type=Path, required=True)
parser.add_argument('--gradle', type=Path, required=True)
parser.add_argument('--java-home', type=Path, required=True)
args = parser.parse_args()
git_head = subprocess.run(['git', 'rev-parse', 'HEAD'], cwd=ROOT, capture_output=True, text=True)
git_status = subprocess.run(['git', 'status', '--porcelain'], cwd=ROOT, capture_output=True, text=True)
if git_head.returncode or git_status.returncode or git_status.stdout.strip():
    raise SystemExit('Commit the source before building a version-bound Android test package.')
source_commit = git_head.stdout.strip()
api_base = 'https://yuzhou.ourideas.top/api/v1'
compiled = subprocess.run(['npm', 'run', 'build:app'], cwd=ROOT / 'apps' / args.app, env=dict(os.environ, VITE_API_BASE=api_base), capture_output=True, text=True)
print((compiled.stdout + compiled.stderr)[-2500:])
if compiled.returncode:
    raise SystemExit(compiled.returncode)
private = ROOT / 'artifacts/native-private'
signing = json.loads((private / 'signing.json').read_text())
runtime = json.loads((private / (args.app + '-appkey.json')).read_text())
identity = json.loads((ROOT / 'docs/app-identities.json').read_text())[args.app]
resources = ROOT / 'apps' / args.app / 'dist/build/app'
manifest = json.loads((resources / 'manifest.json').read_text())
assert runtime['appid'] == identity['appid'] == manifest['id'], 'AppID mismatch'
assert manifest['plus']['uni-app']['compilerVersion'] == '5.26', 'Offline SDK version must match compiler'
assert '5.26.2026091802' in args.release_notes.read_text() and '5.26.' in args.dcloud_sdk.name, 'Wrong official SDK release'
assert (args.android_sdk / 'platforms/android-36/android.jar').is_file(), 'Android API 36 is required'
assert (args.android_sdk / 'build-tools/36.0.0/apksigner').is_file(), 'Android build tools 36 are required'
assert len(runtime['appkey']) == 32 and Path(signing['store_file']).is_file(), 'Missing private signing configuration'
version_code = int(manifest['version']['code'])
version_name = manifest['version']['name']
assert 0 < version_code <= 2147483647 and re.fullmatch(r'[0-9A-Za-z.-]+', version_name), 'Invalid package version'
project = ROOT / 'artifacts/native-android' / args.app
if project.exists():
    shutil.rmtree(project)
project.mkdir(parents=True, exist_ok=True)
project.chmod(0o700)
template = args.dcloud_sdk / 'HBuilder-HelloUniApp/app'
app = project / 'app'
main = app / 'src/main'
main.mkdir(parents=True, exist_ok=True)
shutil.copytree(template / 'src/main/res', main / 'res', dirs_exist_ok=True)
shutil.copytree(template / 'src/main/assets/data', main / 'assets/data', dirs_exist_ok=True)
app_assets = main / 'assets/apps' / identity['appid'] / 'www'
if app_assets.exists():
    shutil.rmtree(app_assets)
shutil.copytree(resources, app_assets)
(main / 'assets/data/dcloud_control.xml').write_text('<hbuilder><apps><app appid="' + identity['appid'] + '" appver=""/></apps></hbuilder>')
properties = ET.parse(main / 'assets/data/dcloud_properties.xml')
configure_modules(properties.getroot())
properties.write(main / 'assets/data/dcloud_properties.xml', encoding='utf-8', xml_declaration=True)
libs = app / 'libs'
if libs.exists():
    shutil.rmtree(libs)
libs.mkdir()
for name in ['lib.5plus.base-release.aar', 'uniapp-v8-release.aar', 'android-gif-drawable-1.2.29.aar', 'oaid_sdk_1.0.25.aar', 'breakpad-build-release.aar', 'install-apk-release.aar', 'media-release.aar', 'weex_videoplayer-release.aar']:
    shutil.copy2(template / 'libs' / name, libs / name)
package = identity['android_package']
label = manifest['name']
(main / 'res/values/strings.xml').write_text('<resources><string name="app_name">' + label + '</string></resources>')
(main / 'res/drawable').mkdir(exist_ok=True)
shutil.copy2(ROOT / 'apps' / args.app / 'src/static/yacht.jpg', main / 'res/drawable/yuzhou_test.jpg')
for sample_splash in (main / 'res').glob('drawable*/splash.*'):
    sample_splash.unlink()
shutil.copy2(ROOT / 'apps' / args.app / 'src/static/yacht.jpg', main / 'res/drawable/splash.jpg')
(main / 'AndroidManifest.xml').write_text('''<manifest xmlns:android="http://schemas.android.com/apk/res/android" xmlns:tools="http://schemas.android.com/tools">
<uses-permission android:name="android.permission.INTERNET"/>
<uses-permission android:name="android.permission.ACCESS_NETWORK_STATE"/>
<uses-permission android:name="android.permission.CAMERA" tools:node="remove"/>
<uses-permission android:name="com.asus.msa.SupplementaryDID.ACCESS" tools:node="remove"/>
<uses-permission android:name="freemme.permission.msa" tools:node="remove"/>
<uses-permission android:name="com.huawei.android.launcher.permission.CHANGE_BADGE" tools:node="remove"/>
<uses-permission android:name="com.vivo.notification.permission.BADGE_ICON" tools:node="remove"/>
<uses-permission android:name="android.permission.READ_EXTERNAL_STORAGE" android:maxSdkVersion="32" tools:replace="android:maxSdkVersion"/>
<uses-permission android:name="android.permission.WRITE_EXTERNAL_STORAGE" android:maxSdkVersion="28" tools:replace="android:maxSdkVersion"/>
<application android:name="io.dcloud.application.DCloudApplication" android:label="@string/app_name" android:icon="@drawable/yuzhou_test" android:allowBackup="false" android:usesCleartextTraffic="false" tools:replace="android:usesCleartextTraffic" android:largeHeap="true">
<activity android:name="io.dcloud.PandoraEntry" android:label="@string/app_name" android:launchMode="singleTask" android:hardwareAccelerated="true" android:theme="@style/TranslucentTheme" android:exported="true" android:configChanges="orientation|keyboardHidden|keyboard|navigation" android:windowSoftInputMode="adjustResize"><intent-filter><action android:name="android.intent.action.MAIN"/><category android:name="android.intent.category.LAUNCHER"/></intent-filter></activity>
<activity android:name="io.dcloud.PandoraEntryActivity" android:launchMode="singleTask" android:configChanges="orientation|keyboardHidden|screenSize|mcc|mnc|fontScale|keyboard|smallestScreenSize|screenLayout|uiMode" android:hardwareAccelerated="true" android:theme="@style/DCloudTheme" android:exported="false" android:windowSoftInputMode="adjustResize"/>
<meta-data android:name="dcloud_appkey" android:value="''' + runtime['appkey'] + '''"/>
</application></manifest>''')
(project / 'settings.gradle').write_text("rootProject.name='YuzhouTemporary'; include ':app'\n")
(project / 'build.gradle').write_text("buildscript { repositories { maven { url 'https://maven.aliyun.com/repository/google' }; mavenCentral(); google() }; dependencies { classpath 'com.android.tools.build:gradle:8.12.0' } }\nallprojects { repositories { maven { url 'https://maven.aliyun.com/repository/google' }; mavenCentral(); google() } }\n")
(project / 'gradle.properties').write_text('android.useAndroidX=true\nandroid.enableJetifier=true\norg.gradle.jvmargs=-Xmx3g -Dfile.encoding=UTF-8\n')
(project / 'local.properties').write_text('sdk.dir=' + str(args.android_sdk) + '\n')
(app / 'build.gradle').write_text('''apply plugin: 'com.android.application'
android {
 namespace 'PACKAGE'; compileSdk 36; buildToolsVersion '36.0.0'
 defaultConfig { applicationId 'PACKAGE'; minSdk 21; targetSdk 35; versionCode VERSION_CODE; versionName 'VERSION_NAME'; multiDexEnabled true; ndk { abiFilters 'arm64-v8a','armeabi-v7a','x86' } }
 compileOptions { sourceCompatibility JavaVersion.VERSION_1_8; targetCompatibility JavaVersion.VERSION_1_8 }
 signingConfigs { test { storeFile file(System.getenv('YZ_KEYSTORE')); storePassword System.getenv('YZ_KEYSTORE_PASSWORD'); keyAlias System.getenv('YZ_KEY_ALIAS'); keyPassword System.getenv('YZ_KEYSTORE_PASSWORD'); v1SigningEnabled true; v2SigningEnabled true } }
 buildTypes { release { signingConfig signingConfigs.test; minifyEnabled false } }
 androidResources { additionalParameters '--auto-add-overlay'; ignoreAssetsPattern '!.svn:!.git:.*:!CVS:!thumbs.db:!picasa.ini:!*.scc:*~' }
}
dependencies {
 implementation fileTree(include: ['*.aar'], dir: 'libs')
 implementation 'androidx.appcompat:appcompat:1.1.0'
 implementation 'androidx.localbroadcastmanager:localbroadcastmanager:1.0.0'
 implementation 'androidx.core:core:1.6.0'
 implementation 'androidx.fragment:fragment:1.1.0'
 implementation 'androidx.recyclerview:recyclerview:1.1.0'
 implementation 'androidx.webkit:webkit:1.5.0'
 implementation 'com.facebook.fresco:fresco:3.4.0'
 implementation 'com.facebook.fresco:middleware:3.4.0'
 implementation 'com.facebook.fresco:animated-gif:3.4.0'
 implementation 'com.facebook.fresco:webpsupport:3.4.0'
 implementation 'com.facebook.fresco:animated-webp:3.4.0'
 implementation 'com.github.bumptech.glide:glide:4.9.0'
 annotationProcessor 'com.github.bumptech.glide:compiler:4.9.0'
 implementation 'com.alibaba:fastjson:1.2.83'
 implementation 'net.lingala.zip4j:zip4j:2.11.5'
}
'''.replace('PACKAGE', package).replace('VERSION_CODE', str(version_code)).replace('VERSION_NAME', version_name))
env = dict(os.environ, JAVA_HOME=str(args.java_home), ANDROID_HOME=str(args.android_sdk), YZ_KEYSTORE=signing['store_file'], YZ_KEYSTORE_PASSWORD=signing['store_password'], YZ_KEY_ALIAS=signing['key_alias'])
result = subprocess.run([str(args.gradle), '--no-daemon', '--console=plain', ':app:assembleRelease'], cwd=project, env=env, capture_output=True, text=True)
safe_output = (result.stdout + result.stderr).replace(signing['store_password'], '[private]').replace(runtime['appkey'], '[private]')
print(safe_output[-12000:])
if result.returncode:
    raise SystemExit(result.returncode)
apk = app / 'build/outputs/apk/release/app-release.apk'
verify_apk_modules(apk)
verified = subprocess.run([str(args.android_sdk / 'build-tools/36.0.0/apksigner'), 'verify', '--verbose', '--print-certs', str(apk)], env=env, check=True, capture_output=True, text=True)
assert 'v2 scheme (APK Signature Scheme v2): true' in verified.stdout, 'APK v2 signature is required'
certificate = re.search(r'Signer #1 certificate SHA-256 digest: ([0-9a-f]+)', verified.stdout)
assert certificate and certificate.group(1) == identity['android_certificate_sha256'].replace(':', '').lower(), 'APK signing certificate differs from the registered application'
merged = ET.parse(app / 'build/intermediates/merged_manifests/release/processReleaseManifest/AndroidManifest.xml').getroot()
android = '{http://schemas.android.com/apk/res/android}'
assert merged.get('package') == package, 'Merged Android package mismatch'
assert merged.find('application').get(android + 'allowBackup') == 'false' and merged.find('application').get(android + 'usesCleartextTraffic') == 'false', 'Private app data or network protections were weakened'
permissions = {item.get(android + 'name'): item.get(android + 'maxSdkVersion') for item in merged.findall('uses-permission')}
assert not set(permissions) & {'android.permission.CAMERA', 'android.permission.RECORD_AUDIO', 'android.permission.READ_CONTACTS', 'android.permission.READ_PHONE_STATE', 'android.permission.ACCESS_FINE_LOCATION', 'android.permission.SEND_SMS'}, 'Unexpected unrelated device permission'
assert permissions.get('android.permission.READ_EXTERNAL_STORAGE') == '32' and permissions.get('android.permission.WRITE_EXTERNAL_STORAGE') == '28', 'Legacy media permissions must be bounded'
destination = ROOT / 'artifacts/native-android' / ('yuzhou-' + args.app + '-test.apk')
git_head = subprocess.run(['git', 'rev-parse', 'HEAD'], cwd=ROOT, capture_output=True, text=True)
git_status = subprocess.run(['git', 'status', '--porcelain'], cwd=ROOT, capture_output=True, text=True)
if git_head.returncode or git_head.stdout.strip() != source_commit or git_status.returncode or git_status.stdout.strip():
    raise SystemExit('Source changed during the build; the APK is not approved for this commit.')
shutil.copy2(apk, destination)
receipt = {'app': args.app, 'appid': identity['appid'], 'package': package, 'name': label, 'version_name': version_name, 'version_code': version_code, 'api_base': api_base, 'apk': str(destination), 'sha256': hashlib.sha256(destination.read_bytes()).hexdigest(), 'signature_v2_verified': True, 'core_features_verified': CORE_FEATURES, 'permissions': permissions, 'source_commit': source_commit, 'installed_test': False, 'store_release': False}
destination.with_suffix('.json').write_text(json.dumps(receipt, ensure_ascii=False, indent=2) + '\n')
print(json.dumps(receipt, ensure_ascii=False))
