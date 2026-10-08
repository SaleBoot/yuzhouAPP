import { spawn } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const children = [];
function run(command, args, dir, env = {}) {
  const child = spawn(command, args, { cwd: path.join(root, dir), env: { ...process.env, ...env }, stdio: 'inherit' });
  children.push(child);
  child.on('exit', code => { if (code) stop(code); });
}
function stop(code = 0) { for (const child of children) child.kill('SIGTERM'); process.exit(code); }
process.on('SIGINT', () => stop()); process.on('SIGTERM', () => stop());
run('php', ['-S', '127.0.0.1:8088', '-t', 'public', 'public/router.php'], 'backend', { APP_ENV: 'development' });
run('npm', ['run', 'dev', '--', '--host', '127.0.0.1', '--port', '5173'], 'web');
run('npm', ['run', 'dev', '--', '--host', '127.0.0.1', '--port', '5174'], 'apps/consumer');
run('npm', ['run', 'dev', '--', '--host', '127.0.0.1', '--port', '5175'], 'apps/broker');
