import { spawnSync } from 'node:child_process';
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { randomBytes } from 'node:crypto';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const storage = path.join(root, 'backend/storage');
mkdirSync(storage, { recursive: true, mode: 0o700 });
const accountFile = path.join(storage, 'dev-accounts.json');
const password = existsSync(accountFile) ? JSON.parse(readFileSync(accountFile)).password : randomBytes(24).toString('base64url');
const environmentFile = path.join(root, 'backend/.env');
if (!existsSync(environmentFile)) writeFileSync(environmentFile, [
  'APP_ENV=development', 'APP_DEBUG=0', 'APP_URL=http://127.0.0.1:8088',
  'DB_DRIVER=sqlite', `DB_SQLITE_PATH=${path.join(storage, 'development.sqlite')}`,
  'ALLOW_TEST_PAYMENTS=1', 'CORS_ORIGINS=http://127.0.0.1:5173,http://127.0.0.1:5174,http://127.0.0.1:5175', ''
].join('\n'), { mode: 0o600 });
const result = spawnSync('php', ['backend/bin/init.php', '--seed'], { cwd: root, env: { ...process.env, SEED_PASSWORD: password }, stdio: 'inherit' });
if (result.status !== 0) process.exit(result.status || 1);
if (!existsSync(accountFile)) writeFileSync(accountFile, JSON.stringify({ environment: 'development', password, users: ['platform', 'consumer', 'broker', 'merchant', 'agency'].map(role => `${role}.demo`) }, null, 2), { mode: 0o600 });
console.log('测试账号口令保存在 backend/storage/dev-accounts.json，仅供本地开发。');
