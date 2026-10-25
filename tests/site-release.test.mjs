import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, mkdirSync, readFileSync, writeFileSync, existsSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { spawnSync } from 'node:child_process';

test('desktop release binds committed sources and never builds or packages App/backend assets', () => {
  const root = mkdtempSync(path.join(tmpdir(), 'yuzhou-site-release-'));
  const put = (name, text) => { const file = path.join(root, name); mkdirSync(path.dirname(file), { recursive: true }); writeFileSync(file, text); };
  const git = (...args) => { const r = spawnSync('git', args, { cwd: root, encoding: 'utf8' }); assert.equal(r.status, 0, r.stderr); };
  try {
    put('scripts/release-site.mjs', readFileSync(new URL('../scripts/release-site.mjs', import.meta.url)));
    put('web/package.json', JSON.stringify({ private: true, scripts: { 'build:site': 'node build-site.cjs' } }));
    put('web/build-site.cjs', "const fs=require('node:fs');const assert=require('node:assert/strict');assert.equal(process.env.VITE_API_BASE_URL,'/api/v1');fs.mkdirSync('dist-site/site-assets',{recursive:true});fs.writeFileSync('dist-site/site.html','<html>desktop</html>');fs.writeFileSync('dist-site/site-assets/site-fixture.js','desktop bundle');");
    put('deploy/install-site.py', '# fixture installer');
    put('apps/consumer/package.json', JSON.stringify({ scripts: { build: 'exit 99' } }));
    put('apps/broker/package.json', JSON.stringify({ scripts: { build: 'exit 99' } }));
    put('backend/public/index.php', '<?php // must not package');
    put('backend/.env', 'FIXTURE_ONLY=do-not-package');
    put('.gitignore', 'artifacts/\nweb/dist-site/\n');
    git('init', '--initial-branch=develop'); git('add', '.'); git('-c', 'user.name=Site test', '-c', 'user.email=test@example.invalid', 'commit', '-m', 'desktop fixture');
    const run = () => spawnSync(process.execPath, ['scripts/release-site.mjs'], { cwd: root, env: { ...process.env, VITE_API_BASE_URL: 'https://wrong.invalid/api' }, encoding: 'utf8' });
    const result = run(); assert.equal(result.status, 0, result.stderr);
    const output = result.stdout.match(/Website release: (.*)/)[1].trim();
    const receipt = JSON.parse(readFileSync(path.join(output, 'site-release.json')));
    assert.match(receipt.source_commit, /^[a-f0-9]{40}$/);
    assert.equal(receipt.scope, 'desktop website only');
    assert.equal(readFileSync(path.join(output, 'public/index.html'), 'utf8'), '<html>desktop</html>');
    assert.deepEqual(receipt.files.map(x => x.path).sort(), ['install-site.py', 'public/index.html', 'public/site-assets/site-fixture.js']);
    assert.ok(receipt.files.every(x => /^[a-f0-9]{64}$/.test(x.sha256)));
    for (const name of ['backend', 'apps', 'public/admin', 'public/consumer', 'public/broker', 'public/app-updates']) assert.equal(existsSync(path.join(output, name)), false, name);
    put('backend/public/index.php', '<?php // dirty');
    assert.notEqual(run().status, 0);
  } finally { rmSync(root, { recursive: true, force: true }); }
});
