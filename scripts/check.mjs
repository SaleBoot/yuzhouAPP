import { spawnSync } from 'node:child_process';
import { existsSync, readdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const phpFiles = [];
function scan(dir) {
  if (!existsSync(dir)) return;
  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    if (['node_modules', 'runtime', 'storage', 'thinkphp'].includes(entry.name)) continue;
    const file = path.join(dir, entry.name);
    if (entry.isDirectory()) scan(file);
    else if (entry.name.endsWith('.php')) phpFiles.push(file);
  }
}
scan(path.join(root, 'backend')); scan(path.join(root, 'tests')); scan(path.join(root, 'scripts'));
for (const file of phpFiles) {
  const result = spawnSync('php', ['-l', file], { encoding: 'utf8' });
  if (result.status !== 0) { console.error(result.stdout, result.stderr); process.exit(1); }
}
console.log(`PHP语法检查：${phpFiles.length}个自有文件。`);
const releaseTest = spawnSync(process.execPath, ['--test', 'tests/release.test.mjs'], { cwd: root, stdio: 'inherit' });
if (releaseTest.status !== 0) process.exit(releaseTest.status || 1);
const androidModulesTest = spawnSync('python3', ['-B', 'tests/native/test_android_modules.py'], { cwd: root, stdio: 'inherit' });
if (androidModulesTest.status !== 0) process.exit(androidModulesTest.status || 1);
const installerTest = spawnSync('python3', ['tests/deploy/test_install_release.py'], { cwd: root, stdio: 'inherit' });
if (installerTest.status !== 0) process.exit(installerTest.status || 1);
const backupTest = spawnSync('python3', ['-B', 'tests/deploy/test_backup.py'], { cwd: root, stdio: 'inherit' });
if (backupTest.status !== 0) process.exit(backupTest.status || 1);
const httpsFixtureTest = spawnSync('python3', ['tests/deployment/test_https.py'], { cwd: root, stdio: 'inherit' });
if (httpsFixtureTest.status !== 0) process.exit(httpsFixtureTest.status || 1);
const tests = path.join(root, 'tests/backend/run.php');
if (!existsSync(tests)) throw new Error('缺少后端业务测试');
let result = spawnSync('php', [tests], { cwd: root, stdio: 'inherit' });
if (result.status !== 0) process.exit(result.status || 1);
result = spawnSync('php', ['tests/backend/communication.php'], { cwd: root, stdio: 'inherit' });
if (result.status !== 0) process.exit(result.status || 1);
result = spawnSync('php', ['tests/backend/operations.php'], { cwd: root, stdio: 'inherit' });
if (result.status !== 0) process.exit(result.status || 1);
result = spawnSync('php', ['tests/backend/reports.php'], { cwd: root, stdio: 'inherit' });
if (result.status !== 0) process.exit(result.status || 1);
result = spawnSync('php', ['tests/backend/recommendations.php'], { cwd: root, stdio: 'inherit' });
if (result.status !== 0) process.exit(result.status || 1);
result = spawnSync('php', ['tests/backend/crm.php'], { cwd: root, stdio: 'inherit' });
if (result.status !== 0) process.exit(result.status || 1);
result = spawnSync('php', ['tests/backend/events.php'], { cwd: root, stdio: 'inherit' });
if (result.status !== 0) process.exit(result.status || 1);
result = spawnSync('php', ['tests/backend/private-files.php'], { cwd: root, stdio: 'inherit' });
if (result.status !== 0) process.exit(result.status || 1);
result = spawnSync('php', ['tests/backend/media.php'], { cwd: root, stdio: 'inherit' });
if (result.status !== 0) process.exit(result.status || 1);
result = spawnSync('php', ['tests/backend/catalog-import.php'], { cwd: root, stdio: 'inherit' });
if (result.status !== 0) process.exit(result.status || 1);
result = spawnSync('php', ['tests/backend/catalog-copy.php'], { cwd: root, stdio: 'inherit' });
if (result.status !== 0) process.exit(result.status || 1);
result = spawnSync('php', ['tests/backend/sms.php'], { cwd: root, stdio: 'inherit' });
if (result.status !== 0) process.exit(result.status || 1);
result = spawnSync('python3', ['tests/backend/http-smoke.py'], { cwd: root, stdio: 'inherit' });
if (result.status !== 0) process.exit(result.status || 1);
for (const dir of ['web', 'apps/consumer', 'apps/broker', 'apps/map']) {
  result = spawnSync('npm', ['run', 'check'], { cwd: path.join(root, dir), stdio: 'inherit' });
  if (result.status !== 0) process.exit(result.status || 1);
}
