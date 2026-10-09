import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, mkdirSync, writeFileSync, cpSync, existsSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { createHash } from 'node:crypto';

test('发布要求已提交源码，并排除运行数据与秘密', () => {
  const dir = mkdtempSync(path.join(tmpdir(), 'yuzhou-release-test-'));
  const put = (name, content) => { const file = path.join(dir, name); mkdirSync(path.dirname(file), { recursive: true }); writeFileSync(file, content); };
  const git = (...args) => { const r = spawnSync('git', args, { cwd: dir, encoding: 'utf8' }); assert.equal(r.status, 0, r.stderr); };
  try {
    put('scripts/release.mjs', readFileSync(new URL('../scripts/release.mjs', import.meta.url)));
    put('web/source.txt', 'SOURCE_A');
    put('scripts/build.mjs', "import assert from 'node:assert/strict'; import {readFileSync,writeFileSync} from 'node:fs'; assert.equal(process.env.VITE_API_BASE, '/api/v1'); assert.equal(process.env.VITE_API_BASE_URL, '/api/v1'); writeFileSync('web/dist/index.html',readFileSync('web/source.txt'));\n");
    put('web/dist/index.html', 'admin');
    put('apps/map/dist/index.html', 'map');
    put('docs/app-identities.json', JSON.stringify({ consumer: { appid: 'consumer', android_package: 'test.consumer' } }));
    for (const app of ['consumer', 'broker']) { put(`apps/${app}/dist/build/h5/index.html`, app); put(`apps/${app}/src/manifest.json`, JSON.stringify({ appid: app, versionName: '0.1.0', versionCode: '100' })); }
    put('backend/public/index.php', '<?php'); put('backend/.env', 'secret=test-only'); put('backend/storage/private.txt', 'do not release'); put('backend/runtime/token.txt', 'do not release');
    put('database/mysql.sql', 'schema'); put('deploy/landing.html', 'landing'); put('data/test-catalog/catalog.json', '{}'); put('data/test-catalog/assets/example.webp', 'synthetic media'); put('scripts/import-test-catalog.php', '<?php'); put('.gitignore', 'artifacts/\ndist/\n');
    git('init', '--initial-branch=main'); git('add', '.'); git('-c', 'user.name=Release test', '-c', 'user.email=test@example.invalid', 'commit', '-m', 'fixture');
    const run = () => spawnSync(process.execPath, ['scripts/release.mjs'], { cwd: dir, env: { ...process.env, VITE_API_BASE: 'http://127.0.0.1:8088', VITE_API_BASE_URL: 'http://development.invalid' }, encoding: 'utf8' });
    const result = run(); assert.equal(result.status, 0, result.stderr);
    const match = result.stdout.match(/发布目录：(.*)/); assert.ok(match);
    const output = match[1].trim();
    assert.ok(existsSync(path.join(output, 'backend/public/index.php')));
    assert.equal(readFileSync(path.join(output, 'public/admin/index.html'), 'utf8'), 'SOURCE_A');
    assert.ok(existsSync(path.join(output, 'public/boat-map/index.html')));
    assert.ok(existsSync(path.join(output, 'data/test-catalog/assets/example.webp')));
    assert.ok(existsSync(path.join(output, 'deploy/import-test-catalog.php')));
    for (const name of ['backend/.env', 'backend/storage/private.txt', 'backend/runtime/token.txt']) assert.equal(existsSync(path.join(output, name)), false, name);
    const manifest = JSON.parse(readFileSync(path.join(output, 'release.json')));
    assert.match(manifest.source_commit, /^[a-f0-9]{40}$/); assert.ok(manifest.files.every(file => /^[a-f0-9]{64}$/.test(file.sha256)));
    const apk = 'synthetic-package-checks-only';
    const receipt = { app: 'consumer', appid: 'consumer', package: 'test.consumer', api_base: 'https://yuzhou.ourideas.top/api/v1', source_commit: manifest.source_commit, sha256: createHash('sha256').update(apk).digest('hex'), signature_v2_verified: true, installed_test: false };
    put('artifacts/native-android/yuzhou-consumer-test.apk', apk);
    put('artifacts/native-android/yuzhou-consumer-test.json', JSON.stringify(receipt));
    const withPackage = run(); assert.equal(withPackage.status, 0, withPackage.stderr);
    const withOutput = withPackage.stdout.match(/发布目录：(.*)/)[1].trim(); assert.notEqual(withOutput, output);
    const withManifest = JSON.parse(readFileSync(path.join(withOutput, 'release.json')));
    assert.equal(withManifest.clients[0].android_test_package.sha256, receipt.sha256);
    assert.equal(withManifest.clients[0].native_package_verified, false);
    assert.ok(existsSync(path.join(withOutput, 'public/downloads/yuzhou-consumer-test.apk')));
    assert.equal(withManifest.files.some(file => file.path === 'release.json'), false);
    put('artifacts/native-android/yuzhou-consumer-test.json', JSON.stringify({ ...receipt, package: 'wrong.package' })); assert.notEqual(run().status, 0);
    put('artifacts/native-android/yuzhou-consumer-test.json', JSON.stringify({ ...receipt, api_base: 'http://127.0.0.1:8088' })); assert.notEqual(run().status, 0);
    put('artifacts/native-android/yuzhou-consumer-test.json', JSON.stringify({ ...receipt, source_commit: '0'.repeat(40) })); assert.notEqual(run().status, 0);
    put('artifacts/native-android/yuzhou-consumer-test.json', JSON.stringify(receipt));
    put('artifacts/native-android/yuzhou-consumer-test.apk', apk + 'changed'); assert.notEqual(run().status, 0);
    rmSync(path.join(dir, 'artifacts/native-android'), { recursive: true });
    put('web/source.txt', 'SOURCE_B'); git('add', 'web/source.txt'); git('-c', 'user.name=Release test', '-c', 'user.email=test@example.invalid', 'commit', '-m', 'source B without manual build');
    const newSource = run(); assert.equal(newSource.status, 0, newSource.stderr);
    const newOutput = newSource.stdout.match(/发布目录：(.*)/)[1].trim();
    assert.equal(readFileSync(path.join(newOutput, 'public/admin/index.html'), 'utf8'), 'SOURCE_B');
    put('database/mysql.sql', 'changed'); assert.notEqual(run().status, 0);
  } finally { rmSync(dir, { recursive: true, force: true }); }
});
