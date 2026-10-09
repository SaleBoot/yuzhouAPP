import { spawnSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { existsSync, readFileSync, readdirSync, mkdirSync, cpSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const result = spawnSync('git', ['rev-parse', 'HEAD'], { cwd: root, encoding: 'utf8' });
if (result.status !== 0) throw new Error('发布前必须提交源码以绑定准确Git版本');
const commit = result.stdout.trim();
const dirty = spawnSync('git', ['status', '--porcelain'], { cwd: root, encoding: 'utf8' });
if (dirty.status !== 0 || dirty.stdout.trim()) throw new Error('发布前源码必须提交，不能把工作区变更标成已发布提交');
const build = spawnSync(process.execPath, ['scripts/build.mjs'], { cwd: root, env: { ...process.env, VITE_API_BASE: '/api/v1', VITE_API_BASE_URL: '/api/v1' }, stdio: 'inherit' });
if (build.status !== 0) throw new Error('当前提交的网页、H5及地图构建失败，停止发布');
const afterBuildHead = spawnSync('git', ['rev-parse', 'HEAD'], { cwd: root, encoding: 'utf8' });
const afterBuildStatus = spawnSync('git', ['status', '--porcelain'], { cwd: root, encoding: 'utf8' });
if (afterBuildHead.status !== 0 || afterBuildHead.stdout.trim() !== commit || afterBuildStatus.status !== 0 || afterBuildStatus.stdout.trim()) throw new Error('构建期间源码发生变化，停止发布');
const releaseId = `${new Date().toISOString().replace(/[-:.]/g, '').slice(0, 18)}-${commit.slice(0, 8)}`;
const output = path.join(root, 'artifacts', releaseId);
mkdirSync(path.join(root, 'artifacts'), { recursive: true });
mkdirSync(output);
mkdirSync(path.join(output, 'public'));
for (const [source, target] of [
  ['web/dist', 'public/admin'],
  ['apps/consumer/dist/build/h5', 'public/consumer'],
  ['apps/broker/dist/build/h5', 'public/broker'],
  ['apps/map/dist', 'public/boat-map'],
  ['backend', 'backend'],
  ['database', 'database'],
  ['data/test-catalog', 'data/test-catalog'],
  ['deploy', 'deploy'],
  ['scripts/import-test-catalog.php', 'deploy/import-test-catalog.php']
]) {
  const sourcePath = path.join(root, source);
  if (!existsSync(sourcePath)) throw new Error(`缺少产物 ${source}，请先构建`);
  cpSync(sourcePath, path.join(output, target), { recursive: true, filter: file => !/(^|\/)(node_modules|runtime|storage)(\/|$)/.test(file) && !/(^|\/)(\.env[^/]*|local\.php)$/.test(file) });
}
const sha256 = file => createHash('sha256').update(readFileSync(file)).digest('hex');
const androidPackages = {};
for (const app of ['consumer', 'broker']) {
  const apk = path.join(root, 'artifacts/native-android', `yuzhou-${app}-test.apk`);
  const receiptPath = apk.replace(/\.apk$/, '.json');
  if (!existsSync(apk) && !existsSync(receiptPath)) continue;
  if (!existsSync(apk) || !existsSync(receiptPath)) throw new Error(`缺少${app} Android安装包或构建回执`);
  const receipt = JSON.parse(readFileSync(receiptPath, 'utf8'));
  const identity = JSON.parse(readFileSync(path.join(root, 'docs/app-identities.json'), 'utf8'))[app];
  if (receipt.app !== app || receipt.appid !== identity.appid || receipt.package !== identity.android_package || receipt.api_base !== 'https://yuzhou.ourideas.top/api/v1' || receipt.source_commit !== commit || receipt.sha256 !== sha256(apk) || !receipt.signature_v2_verified) throw new Error(`请为当前源码提交重新生成${app} Android测试包`);
  const target = `downloads/yuzhou-${app}-test.apk`;
  mkdirSync(path.join(output, 'public/downloads'), { recursive: true });
  cpSync(apk, path.join(output, 'public', target));
  androidPackages[app] = { path: target, sha256: receipt.sha256, signature_v2_verified: true, installed_test: receipt.installed_test, store_release: false };
}
const downloads = Object.keys(androidPackages).length ? '<section aria-label="Android临时测试安装包"><h2>Android 测试版</h2><p>使用临时签名，仅供体验。设备验收仍在进行，请勿用于真实交易。</p><nav>' + Object.entries(androidPackages).map(([app, info]) => `<a href="/${info.path}" download>${app === 'consumer' ? '用户端' : '经纪人端'}安装包<small>Android 临时测试版</small></a>`).join('') + '</nav></section>' : '';
writeFileSync(path.join(output, 'public/index.html'), readFileSync(path.join(root, 'deploy/landing.html'), 'utf8').replace('<!-- ANDROID_TEST_DOWNLOADS -->', downloads));
const files = [];
function inventory(dir) {
  for (const item of readdirSync(dir, { withFileTypes: true })) {
    const full = path.join(dir, item.name);
    if (item.isDirectory()) inventory(full);
    else files.push({ path: path.relative(output, full), sha256: sha256(full) });
  }
}
inventory(output);
const clients = ['consumer', 'broker'].map(app => {
  const candidates = [`apps/${app}/src/manifest.json`, `apps/${app}/manifest.json`];
  const file = candidates.map(x => path.join(root, x)).find(existsSync);
  if (!file) throw new Error(`缺少${app} manifest`);
  const manifest = JSON.parse(readFileSync(file, 'utf8'));
  return { app, appid: manifest.appid, version_name: manifest.versionName, version_code: manifest.versionCode, native_package_verified: false, android_test_package: androidPackages[app] || null };
});
const manifest = { release_id: releaseId, source_commit: commit, created_at: new Date().toISOString(), domain: 'yuzhou.ourideas.top', framework: 'ThinkPHP 5.1.41 LTS custom', framework_commit: '8f8e7c0bf37baf86f0762b17c06291d6dab29607', api_version: 'v1', clients, files };
const finalHead = spawnSync('git', ['rev-parse', 'HEAD'], { cwd: root, encoding: 'utf8' });
const finalStatus = spawnSync('git', ['status', '--porcelain'], { cwd: root, encoding: 'utf8' });
if (finalHead.status !== 0 || finalHead.stdout.trim() !== commit || finalStatus.status !== 0 || finalStatus.stdout.trim()) throw new Error('打包期间源码发生变化，停止发布');
writeFileSync(path.join(output, 'release.json'), JSON.stringify(manifest, null, 2));
console.log(`发布目录：${output}`);
console.log('包含API、三个后台入口及两个H5预览。原生包、真实服务和服务器验收另记。');
