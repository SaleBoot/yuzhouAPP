import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';
import { amapConfig, amapPosition } from './amap.js';
const key = 'a'.repeat(32);
const sdk = { Map() {}, Marker() {}, InfoWindow() {} };
let sequence = 0;
const fresh = () => import(`./amap.js?test=${++sequence}`);
function browser() {
  const scripts = [];
  const target = { location: { origin: 'https://yuzhou.ourideas.top' }, document: {
    createElement: () => ({ remove() { this.removed = true; } }),
    head: { append: script => scripts.push(script) },
  } };
  return { target, scripts };
}
test('public map config fixes the same-origin proxy and rejects secrets or invalid keys', () => {
  assert.deepEqual(amapConfig(), { amaps_js_key: '', serviceHost: '/_AMapService' });
  assert.equal(amapConfig({ amaps_js_key: key }).amaps_js_key, key);
  for (const value of [17, 'not-a-key', 'a'.repeat(31)]) assert.throws(() => amapConfig({ amaps_js_key: value }));
  assert.throws(() => amapConfig({ amaps_js_key: key, serviceHost: 'https://other.example/_AMapService' }));
  for (const name of ['securityJsCode', 'security_js_code', 'jscode']) assert.throws(() => amapConfig({ [name]: 'private' }));
});
test('only explicitly GCJ02 coordinates enter AMap; original records are preserved', () => {
  const boat = { id: 5, kind: 'boats', status: 'published', payload: { boat_type_id: 2, longitude: 121.5, latitude: 31.2 } };
  for (const coordinate_system of [undefined, 'WGS84', 'BD09', 'unknown']) {
    const input = { ...boat, payload: { ...boat.payload, coordinate_system } };
    const before = structuredClone(input);
    assert.equal(amapPosition(input), null);
    assert.deepEqual(input, before);
  }
  for (const coordinate_system of ['GCJ02', 'GCJ-02', 'gcj_02']) assert.deepEqual(amapPosition({ ...boat, payload: { ...boat.payload, coordinate_system } }), [121.5, 31.2]);
  assert.equal(amapPosition({ ...boat, status: 'draft', payload: { ...boat.payload, coordinate_system: 'GCJ02' } }), null);
});
test('missing key never requests the official SDK', async () => {
  const { loadAMap } = await fresh(); const { target, scripts } = browser();
  assert.throws(() => loadAMap('', target), /尚未配置/);
  assert.equal(scripts.length, 0); assert.equal(target._AMapSecurityConfig, undefined);
});
test('official loader receives JSAPI 2.0 with serviceHost set before load, and reuses one load', async () => {
  const { loadAMap } = await fresh(); const { target, scripts } = browser();
  const first = loadAMap(key, target); assert.equal(loadAMap(key, target), first);
  assert.equal(scripts.length, 1); assert.equal(scripts[0].src, 'https://webapi.amap.com/loader.js');
  target.AMapLoader = { load(options) {
    assert.deepEqual(options, { key, version: '2.0' });
    assert.deepEqual(target._AMapSecurityConfig, { serviceHost: 'https://yuzhou.ourideas.top/_AMapService' });
    return Promise.resolve(sdk);
  } };
  scripts[0].onload(); assert.equal(await first, sdk);
});
test('network errors remove failed scripts and allow an explicit retry', async () => {
  const { loadAMap } = await fresh(); const { target, scripts } = browser();
  const first = loadAMap(key, target); scripts[0].onerror();
  await assert.rejects(first, /加载失败/); assert.equal(scripts[0].removed, true);
  const retry = loadAMap(key, target); assert.equal(scripts.length, 2);
  target.AMapLoader = { load: () => Promise.resolve(sdk) }; scripts[1].onload(); assert.equal(await retry, sdk);
});
test('authentication failures, incomplete SDK and synchronous errors fail without exposing provider error content', async () => {
  for (const result of [() => Promise.reject(new Error('INVALID_USER_SCODE private diagnostic')), () => Promise.resolve({ Map() {} }), () => { throw new Error('private diagnostic'); }]) {
    const { loadAMap } = await fresh(); const { target } = browser(); target.AMapLoader = { load: result };
    await assert.rejects(loadAMap(key, target), error => error.message === '高德地图加载失败，请检查Key、域名、安全代理或网络。');
    target.AMapLoader.load = () => Promise.resolve(sdk); assert.equal(await loadAMap(key, target), sdk);
  }
});
test('both native and H5 map caller URLs carry the current Chinese or English locale', async () => {
  const source = await readFile(new URL('../shared/BoatMap.vue', import.meta.url), 'utf8');
  const script = source.match(/<script setup>([\s\S]*?)<\/script>/)[1];
  for (const client of ['consumer', 'broker']) for (const locale of ['zh-Hans', 'en']) for (const native of [true, false]) {
    const compiled = script.replace(/import .*;\n/g, '').replace(/import\.meta\.env\.VITE_CLIENT/g, JSON.stringify(client)).replace(/\/\/ #ifdef H5\n([\s\S]*?)\/\/ #endif/g, (_block, body) => native ? '' : body);
    const result = vm.runInNewContext(compiled + '\nurl;', { getLocale: () => locale, window: { location: { origin: 'https://yuzhou.ourideas.top' } }, ref: () => ({}), onMounted() {}, onUnmounted() {} });
    const url = new URL(result); assert.equal(url.searchParams.get('lang'), locale); assert.equal(url.searchParams.get('client'), client);
    assert.equal(url.searchParams.get('native'), native ? '1' : null);
  }
});
test('map locale honors explicit query before H5 storage and replaces every supplied placeholder', async () => {
  const originalLocation = globalThis.location, originalStorage = globalThis.localStorage;
  try {
    for (const [search, stored, expected] of [['?lang=en', 'zh-Hans', 'en'], ['?lang=zh-Hans', 'en', 'zh-Hans'], ['', 'en', 'en'], ['', null, 'zh-Hans']]) {
      globalThis.location = { search }; globalThis.localStorage = { getItem: () => stored };
      const { t, locale } = await import(`./i18n.js?test=${++sequence}`);
      assert.equal(locale, expected);
      const translated = t('已显示 {count} 艘船艇，另有 {missing} 艘尚未录入有效位置。', { count: 0, missing: 22 });
      assert.match(translated, /0/); assert.match(translated, /22/); assert.doesNotMatch(translated, /\{\w+\}/);
      assert.equal(t('{value}', { value: 'a$b' }), 'a$b');
    }
  } finally {
    if (originalLocation === undefined) delete globalThis.location; else globalThis.location = originalLocation;
    if (originalStorage === undefined) delete globalThis.localStorage; else globalThis.localStorage = originalStorage;
  }
});
