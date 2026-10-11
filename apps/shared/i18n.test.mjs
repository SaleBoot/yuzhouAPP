import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { readFileSync } from 'node:fs';
import { english, t, getLocale, setLocale, installLanguage, refreshNavigation } from './i18n.js';
const require = createRequire(new URL('../consumer/package.json', import.meta.url));
const { createI18n } = require('vue-i18n');
const { createSSRApp, computed } = require('vue');
const pages = JSON.parse(readFileSync(new URL('../consumer/src/pages.json', import.meta.url)));
const storage = new Map(), tabs = [], titles = [];
globalThis.uni = {
  getStorageSync: key => storage.get(key), setStorageSync: (key, value) => storage.set(key, value),
  setLocale() {}, setTabBarItem: value => tabs.push(value), setNavigationBarTitle: value => titles.push(value),
};
globalThis.getCurrentPages = () => [{ route: 'pages/profile' }];

test('language defaults to Chinese and changes live with saved navigation and named parameters', () => {
  installLanguage(createSSRApp({}), createI18n, pages);
  const label = computed(() => t('首页'));
  assert.equal(label.value, '首页');
  setLocale('en');
  assert.equal(label.value, 'Home');
  assert.equal(t('咨询 {id}', { id: 12 }), 'Enquiry 12');
  assert.equal(t('原始船艇名称'), '原始船艇名称');
  assert.equal(t('未登记提示 {id}', { id: 12 }), '未登记提示 12');
  assert.equal(storage.get('yuzhou_language'), 'en');
  assert.equal(titles.at(-1).title, 'Me');
  assert.deepEqual(tabs.slice(-4).map(item => item.text), ['Home', 'Learn', 'Discover', 'Me']);
  installLanguage(createSSRApp({}), createI18n, pages);
  assert.equal(getLocale(), 'en');
  setLocale('invalid');
  assert.equal(getLocale(), 'en');
  setLocale('zh-Hans');
  assert.equal(t('咨询 {id}', { id: 12 }), '咨询 12');
  refreshNavigation();
  assert.equal(titles.at(-1).title, '我的');
});

test('both apps navigation entries have English and parameter names agree', () => {
  for (const client of ['consumer', 'broker']) {
    const config = JSON.parse(readFileSync(new URL(`../${client}/src/pages.json`, import.meta.url)));
    for (const label of [...config.pages.map(page => page.style.navigationBarTitleText), ...config.tabBar.list.map(item => item.text)])
      assert.ok(english[label], `Missing navigation: ${label}`);
  }
  for (const [key, value] of Object.entries(english)) {
    const parameters = text => [...text.matchAll(/\{(\w+)\}/g)].map(match => match[1]).sort();
    assert.deepEqual(parameters(key), parameters(value), `Parameters: ${key}`);
  }
});
