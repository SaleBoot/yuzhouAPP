import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
for (const dir of ['apps/map', 'web', 'apps/consumer', 'apps/broker']) {
  const result = spawnSync('npm', ['run', 'build'], { cwd: path.join(root, dir), stdio: 'inherit' });
  if (result.status !== 0) process.exit(result.status || 1);
}
console.log('网页和两端H5预览构建成功；这不代表原生安装包或真机验收。');
