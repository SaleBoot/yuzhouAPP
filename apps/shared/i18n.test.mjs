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

test('all named messages interpolate in both languages with Uni string-only runtime compilation', () => {
  const stringRuntime = options => createI18n({
    ...options,
    messageCompiler: source => context => context.normalize([source]),
  });
  const i18n = installLanguage(createSSRApp({}), stringRuntime, pages);
  setLocale('zh-Hans');
  assert.equal(i18n.global.t('{name}，早安', { name: '陈船长' }), '{name}，早安');
  assert.equal(t('{name}，早安', { name: '陈船长' }), '陈船长，早安');
  assert.equal(t('公司 {month} 销售目标', { month: '2026-10' }), '公司 2026-10 销售目标');
  assert.equal(t('当月实收净额 ¥{amount}', { amount: '0.00' }), '当月实收净额 ¥0.00');
  setLocale('en');
  assert.equal(i18n.global.t('{name}，早安', { name: 'Captain Chen' }), 'Good morning, {name}');
  assert.equal(t('{name}，早安', { name: 'Captain Chen' }), 'Good morning, Captain Chen');
  assert.equal(t('公司 {month} 销售目标', { month: '2026-10' }), 'Company sales target · 2026-10');
  assert.equal(t('当月实收净额 ¥{amount}', { amount: '0.00' }), 'Net received this month ¥0.00');
  for (const locale of ['zh-Hans', 'en']) {
    setLocale(locale);
    for (const [key, translation] of Object.entries(english)) {
      const names = [...key.matchAll(/\{(\w+)\}/g)].map(match => match[1]);
      if (!names.length) continue;
      const parameters = Object.fromEntries(names.map(name => [name, `[${name}:值]`]));
      const expected = (locale === 'en' ? translation : key).replace(/\{(\w+)\}/g, (_, name) => parameters[name]);
      assert.equal(t(key, parameters), expected, `${locale}: ${key}`);
      assert.doesNotMatch(t(key, parameters), /\{\w+\}/, `${locale}: ${key}`);
    }
    assert.equal(t('已选 {count} 艘', { count: 0 }), locale === 'en' ? '0 boats selected' : '已选 0 艘');
    assert.equal(t('{name}，早安', { name: '{month} $&' }), locale === 'en' ? 'Good morning, {month} $&' : '{month} $&，早安');
    assert.equal(t('未登记 {value}', { value: 0 }), '未登记 0');
    assert.equal(t('未登记 {value}', { value: '' }), '未登记 ');
  }
  setLocale('zh-Hans');
});
