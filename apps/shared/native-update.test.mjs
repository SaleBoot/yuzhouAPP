import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';

const source = readFileSync(new URL('./native-update.js', import.meta.url), 'utf8')
  .replace(/^import .*$/gm, '')
  .replace(/export (async )?function /g, '$1function ');
const identities = {
  consumer: ['__UNI__E148E73', 'top.ourideas.yuzhou.consumer'],
  broker: ['__UNI__59A80C3', 'top.ourideas.yuzhou.broker'],
};

function client(options = {}) {
  const role = options.role || 'consumer';
  const [appid, packageName] = identities[role];
  const state = { now: 2e12, version: options.version || '108', propertyTimeout: !!options.propertyTimeout, propertyCalls: 0, requests: [], toasts: [], modals: [], views: [], pending: [], storage: options.storage || new Map(), removed: [] };
  const timers = new Map();
  let nextTimer = 1;
  const item = {
    appid, package: packageName, version_name: '1.0.9', version_code: 109,
    compiler_version: '5.26', mandatory: true, bytes: 1234,
    sha256: 'a'.repeat(64), source_commit: 'b'.repeat(40),
    url: `/app-updates/${role}-1.0.9.wgt`, ...options.item,
  };
  const views = {
    create(url, id, style) {
      const view = { url, id, style, shows: 0, reloads: 0, closed: false,
        show() { this.shows++; }, reload() { this.reloads++; },
        close() { this.closed = true; return this.onclose?.(); },
        hide() { return this.onhide?.(); },
      };
      state.views.push(view);
      return view;
    },
  };
  const context = {
    getLocale: () => options.locale || 'zh-Hans', t: value => value,
    Date: { now: () => state.now },
    setTimeout(callback, ms) { const id = nextTimer++; timers.set(id, { callback, at: state.now + ms }); return id; },
    clearTimeout(id) { timers.delete(id); },
    uni: {
      getStorageSync(key) { return state.storage.get(key); },
      setStorageSync(key, value) { state.storage.set(key, value); },
      removeStorageSync(key) { state.removed.push(key); state.storage.delete(key); },
      request(settings) {
        state.requests.push(settings);
        const complete = () => options.networkError ? settings.fail({ errMsg: 'offline' }) : settings.success({ statusCode: options.status || 200, data: { apps: { [appid]: item } } });
        if (options.pendingRequest) state.pending.push(complete); else complete();
      },
      showToast(settings) { state.toasts.push(settings); },
      showModal(settings) { state.modals.push(settings); },
    },
    plus: options.noPlus ? undefined : {
      runtime: { appid: options.appid || appid, uniVersion: options.compiler || '5.26.0', versionCode: '107',
        getProperty(id, callback) {
          state.propertyCalls++;
          if (!state.propertyTimeout) callback({ appid: options.propertyAppid || id, versionCode: state.version, version: `1.0.${state.version.slice(-1)}` });
        },
      },
      webview: views,
    },
  };
  vm.createContext(context);
  vm.runInContext(source + '\nthis.check = checkNativeUpdate;', context);
  return { state, item, check: () => context.check(),
    async advance(ms) {
      state.now += ms;
      for (const [id, timer] of [...timers]) if (timer.at <= state.now) { timers.delete(id); timer.callback(); }
      await Promise.resolve(); await Promise.resolve();
    },
  };
}

test('consumer and broker independently block on a validated higher mandatory resource', async () => {
  for (const role of Object.keys(identities)) {
    const p = client({ role, locale: 'en' });
    await p.check();
    assert.equal(p.state.views.length, 1);
    const view = p.state.views[0];
    const url = new URL(view.url);
    assert.equal(url.origin, 'https://yuzhou.ourideas.top');
    assert.equal(url.pathname, '/app-updates/');
    assert.equal(url.searchParams.get('required'), '1');
    assert.equal(url.searchParams.get('minimum'), '109');
    assert.equal(url.searchParams.get('lang'), 'en');
    assert.equal(view.style.popGesture, 'none');
    assert.equal(view.style.top, '0px'); assert.equal(view.style.bottom, '0px');
    assert.equal(p.state.requests[0].url.startsWith('https://yuzhou.ourideas.top/app-updates/latest.json'), true);
    assert.equal(p.state.requests[0].header.Authorization, undefined);
  }
});

test('equal or newer WGT versions are not blocked even when the native installer remains 107', async () => {
  for (const version of ['109', '110']) {
    const p = client({ version });
    await p.check();
    assert.equal(p.state.propertyCalls, 1);
    assert.equal(p.state.views.length, 0);
    assert.equal(p.state.toasts.length, 0);
  }
});

test('optional and legacy metadata do not force a window', async () => {
  for (const mandatory of [false, undefined]) {
    const p = client({ item: { mandatory } }); await p.check();
    assert.equal(p.state.views.length, 0);
    assert.equal(p.state.toasts.length, 0);
  }
});

test('invalid identity, compiler, version, hash, size and source cannot force an update', async () => {
  const invalid = [
    { item: { appid: '__UNI__59A80C3' } }, { item: { package: identities.broker[1] } },
    { item: { url: '/app-updates/broker-1.0.9.wgt' } }, { item: { url: 'https://elsewhere.example/app.wgt' } },
    { item: { url: '/app-updates/consumer-1.0.9.wgt?token=x' } },
    { item: { version_name: 'latest' } }, { item: { version_code: '109' } },
    { item: { version_code: Number.MAX_SAFE_INTEGER + 1 } }, { version: '108.0' },
    { item: { compiler_version: '5.27' } }, { compiler: '5.260' },
    { propertyAppid: '__UNI__59A80C3' }, { item: { mandatory: 'true' } },
    { item: { bytes: 0 } }, { item: { bytes: 100 * 1024 * 1024 + 1 } },
    { item: { sha256: 'broken' } }, { item: { source_commit: 'main' } },
  ];
  for (const options of invalid) {
    const p = client(options); await p.check();
    assert.equal(p.state.views.length, 0, JSON.stringify(options));
    assert.equal(p.state.toasts.length, 1, JSON.stringify(options));
  }
});

test('unavailable native runtime skips checks and an unknown offline update does not block', async () => {
  for (const options of [{ noPlus: true }, { appid: 'HBuilder' }, { appid: '__proto__' }]) {
    const p = client(options); await p.check();
    assert.equal(p.state.requests.length, 0);
    assert.equal(p.state.views.length, 0);
  }
  for (const options of [{ networkError: true }, { status: 503 }]) {
    const p = client(options); await p.check();
    assert.equal(p.state.views.length, 0); assert.equal(p.state.toasts.length, 1);
    await p.advance(60001); await p.check();
    assert.equal(p.state.requests.length, 2);
  }
});

test('property timeout releases checking state and permits a later retry', async () => {
  const p = client({ propertyTimeout: true });
  const first = p.check();
  await p.advance(15001); await first;
  assert.equal(p.state.toasts.length, 1); assert.equal(p.state.views.length, 0);
  await p.advance(60001);
  const second = p.check();
  await p.advance(15001); await second;
  assert.equal(p.state.propertyCalls, 2);
});

test('launch and foreground checks cannot issue parallel requests or duplicate windows', async () => {
  const p = client({ pendingRequest: true });
  const first = p.check(); await Promise.resolve();
  const second = p.check(); await Promise.resolve();
  assert.equal(p.state.requests.length, 1);
  p.state.pending.shift()(); await first; await second;
  await p.check();
  assert.equal(p.state.views.filter(view => !view.closed).length, 1);
  assert.equal(p.state.requests.length, 1);
});

test('confirmed mandatory update stays blocking when hidden, closed or unable to load', async () => {
  const p = client(); await p.check();
  const first = p.state.views[0]; const shown = first.shows;
  first.hide(); assert.equal(first.shows, shown + 1);
  first.onerror(); assert.equal(p.state.modals[0].showCancel, false);
  p.state.modals[0].success({ confirm: true }); assert.equal(first.reloads, 1);
  await first.close();
  assert.equal(p.state.views.length, 2);
  assert.equal(p.state.views[1].shows > 0, true);
  await p.check(); assert.equal(p.state.requests.length, 1);
});

test('closing the update page after a successful resource install stops reopening it', async () => {
  const p = client(); await p.check();
  p.state.version = '109';
  await p.state.views[0].close();
  await p.check();
  assert.equal(p.state.views.filter(view => !view.closed).length, 0);
  await p.advance(60001); await p.check();
  assert.equal(p.state.views.filter(view => !view.closed).length, 0);
  assert.equal(p.state.toasts.length, 0);
});

test('confirmed mandatory version persists across process restart and blocks an offline relaunch', async () => {
  for (const role of Object.keys(identities)) {
    const storage = new Map();
    const p = client({ role, storage }); await p.check();
    const key = `yuzhou_${identities[role][0]}_required_update`;
    assert.equal(storage.get(key), 109);
    const reopened = client({ role, storage, networkError: true }); await reopened.check();
    assert.equal(reopened.state.views.length, 1);
    assert.equal(reopened.state.requests.length, 0);
    assert.equal(storage.get(key), 109);
  }
});

test('installed required resource clears only this app persisted requirement and avoids restart loops', async () => {
  const consumerKey = `yuzhou_${identities.consumer[0]}_required_update`;
  const brokerKey = `yuzhou_${identities.broker[0]}_required_update`;
  const storage = new Map([[consumerKey, 109], [brokerKey, 110]]);
  const p = client({ storage, version: '109' }); await p.check();
  assert.equal(p.state.views.filter(view => !view.closed).length, 0);
  assert.equal(storage.has(consumerKey), false);
  assert.equal(storage.get(brokerKey), 110);
  const broker = client({ role: 'broker', storage, networkError: true }); await broker.check();
  assert.equal(broker.state.views.length, 1);
  assert.equal(broker.state.requests.length, 0);
});

test('malformed saved versions cannot impose an unverified requirement on another app', async () => {
  const key = `yuzhou_${identities.consumer[0]}_required_update`;
  for (const saved of ['109', -109, NaN, Number.MAX_SAFE_INTEGER + 1]) {
    const p = client({ storage: new Map([[key, saved]]), item: { mandatory: false } }); await p.check();
    assert.equal(p.state.views.length, 0);
    assert.equal(p.state.storage.has(key), false);
  }
  const p = client({ role: 'broker', storage: new Map([[key, 109]]), item: { mandatory: false } }); await p.check();
  assert.equal(p.state.views.length, 0);
  assert.equal(p.state.storage.get(key), 109);
});

test('closing a confirmed required window while version read times out reopens the block and keeps storage', async () => {
  const p = client(); await p.check();
  p.state.propertyTimeout = true;
  const closing = p.state.views[0].close();
  assert.equal(p.state.views.filter(view => !view.closed).length, 1);
  assert.equal(p.state.views[1].shows > 0, true);
  await p.advance(15001); await closing;
  assert.equal(p.state.views.length, 2);
  assert.equal(p.state.storage.get(`yuzhou_${identities.consumer[0]}_required_update`), 109);
});

test('saved confirmed requirement blocks immediately even when the first version read times out', async () => {
  const key = `yuzhou_${identities.consumer[0]}_required_update`;
  const p = client({ storage: new Map([[key, 109]]), propertyTimeout: true, networkError: true });
  const checking = p.check();
  assert.equal(p.state.views.filter(view => !view.closed).length, 1);
  assert.equal(p.state.views[0].shows > 0, true);
  await p.advance(15001); await checking;
  assert.equal(p.state.views.filter(view => !view.closed).length, 1);
  assert.equal(p.state.storage.get(key), 109);
  assert.equal(p.state.requests.length, 0);
});
