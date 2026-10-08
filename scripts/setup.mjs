import { spawnSync } from 'node:child_process';
import { existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
for (const dir of ['web', 'apps/consumer', 'apps/broker', 'apps/map']) {
  if (!existsSync(path.join(root, dir, 'package-lock.json'))) throw new Error(`${dir}: 缺少锁定依赖文件`);
  const result = spawnSync('npm', ['ci', '--no-audit', '--no-fund'], { cwd: path.join(root, dir), stdio: 'inherit' });
  if (result.status !== 0) process.exit(result.status || 1);
}
console.log('依赖已按锁定文件安装。数据库初始化见 backend/bin。');
