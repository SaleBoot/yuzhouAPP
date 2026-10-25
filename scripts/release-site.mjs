import { spawnSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { cpSync, existsSync, mkdirSync, readFileSync, readdirSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const git = (...args) => {
  const result = spawnSync('git', args, { cwd: root, encoding: 'utf8' });
  if (result.status !== 0) throw new Error('Cannot read the source revision.');
  return result.stdout.trim();
};
const commit = git('rev-parse', 'HEAD');
if (git('status', '--porcelain')) throw new Error('Commit website sources before packaging.');
const build = spawnSync('npm', ['run', 'build:site'], { cwd: path.join(root, 'web'), env: { ...process.env, VITE_API_BASE_URL: '/api/v1' }, stdio: 'inherit' });
if (build.status !== 0) throw new Error('Desktop website build failed.');
if (git('rev-parse', 'HEAD') !== commit || git('status', '--porcelain')) throw new Error('Sources changed during the website build.');
const releaseId = `site-${new Date().toISOString().replace(/[-:.]/g, '').slice(0,18)}-${commit.slice(0,8)}`;
const destination = path.join(root, 'artifacts', releaseId);
mkdirSync(path.join(destination, 'public'), { recursive: true });
cpSync(path.join(root, 'web/dist-site/site.html'), path.join(destination, 'public/index.html'));
cpSync(path.join(root, 'web/dist-site/site-assets'), path.join(destination, 'public/site-assets'), { recursive: true });
cpSync(path.join(root, 'deploy/install-site.py'), path.join(destination, 'install-site.py'));
const files = [];
function inventory(directory) {
  for (const item of readdirSync(directory, { withFileTypes: true })) {
    const file = path.join(directory, item.name);
    if (item.isDirectory()) inventory(file);
    else files.push({ path: path.relative(destination, file), sha256: createHash('sha256').update(readFileSync(file)).digest('hex') });
  }
}
inventory(destination);
if (!files.some(file => file.path === 'public/index.html') || files.some(file => !file.path.startsWith('public/site-assets/') && !['public/index.html','install-site.py'].includes(file.path))) throw new Error('Unexpected website package scope.');
writeFileSync(path.join(destination, 'site-release.json'), JSON.stringify({ release_id: releaseId, source_commit: commit, domain: 'yuzhou.ourideas.top', created_at: new Date().toISOString(), scope: 'desktop website only', files }, null, 2));
if (!existsSync(path.join(destination, 'public/site-assets'))) throw new Error('Website assets are missing.');
console.log(`Website release: ${destination}`);
console.log('App packages, WGT manifests, API, management frontend and databases are excluded.');
