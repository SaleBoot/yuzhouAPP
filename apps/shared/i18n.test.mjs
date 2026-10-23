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
let currentRoute = 'pages/home';
globalThis.uni = {
  getStorageSync: key => storage.get(key), setStorageSync: (key, value) => storage.set(key, value),
  setLocale() {}, setTabBarItem: value => tabs.push(value), setNavigationBarTitle: value => titles.push(value),
};
globalThis.getCurrentPages = () => [{ route: currentRoute }];

test('language defaults to Chinese and changes live with saved navigation and named parameters', () => {
  installLanguage(createSSRApp({}), createI18n, pages);
  const label = computed(() => t('船艇销售'));
  assert.equal(label.value, '船艇销售');
  setLocale('en');
  assert.equal(label.value, 'Boats');
  assert.equal(t('咨询 {id}', { id: 12 }), 'Enquiry 12');
  assert.equal(t('原始船艇名称'), '原始船艇名称');
  assert.equal(t('未登记提示 {id}', { id: 12 }), '未登记提示 12');
  assert.equal(storage.get('yuzhou_language'), 'en');
  assert.equal(titles.at(-1).title, 'Yuzhou');
  assert.deepEqual(tabs.slice(-4).map(item => item.text), ['Boats', 'Maintenance', 'Shop', 'Me']);
  installLanguage(createSSRApp({}), createI18n, pages);
  assert.equal(getLocale(), 'en');
  setLocale('invalid');
  assert.equal(getLocale(), 'en');
  setLocale('zh-Hans');
  assert.equal(t('咨询 {id}', { id: 12 }), '咨询 12');
  refreshNavigation();
  assert.equal(titles.at(-1).title, '驭舟');
  assert.deepEqual(tabs.slice(-4).map(item => item.text), ['船艇销售', '船艇维保', '商城', '我的']);
});

test('custom profile and settings translate their own headings without writing a native navigation title', () => {
  const before = titles.length;
  currentRoute = 'pages/profile';
  setLocale('en');
  assert.equal(t('个人中心'), 'Profile');
  assert.deepEqual(tabs.slice(-4).map(item => item.text), ['Boats', 'Maintenance', 'Shop', 'Me']);
  currentRoute = 'pages/settings';
  setLocale('zh-Hans');
  assert.equal(t('设置'), '设置');
  setLocale('en');
  assert.equal(t('设置'), 'Settings');
  assert.equal(t('返回'), 'Back');
  assert.equal(titles.length, before);
  currentRoute = 'pages/home';
  setLocale('zh-Hans');
});

test('broker navigation keeps its existing dashboard, customer and order tabs in both languages', () => {
  const broker = JSON.parse(readFileSync(new URL('../broker/src/pages.json', import.meta.url)));
  currentRoute = 'pages/profile';
  installLanguage(createSSRApp({}), createI18n, broker);
  const before = titles.length;
  setLocale('en');
  assert.deepEqual(tabs.slice(-4).map(item => item.text), ['Dashboard', 'Customer', 'Order', 'Me']);
  assert.equal(t('个人中心'), 'Profile');
  currentRoute = 'pages/settings';
  refreshNavigation();
  assert.equal(t('设置'), 'Settings');
  assert.equal(titles.length, before);
  currentRoute = 'pages/work';
  refreshNavigation();
  assert.equal(titles.at(-1).title, 'Yuzhou Sales');
  setLocale('zh-Hans');
  assert.equal(titles.at(-1).title, '驭舟销售');
  assert.deepEqual(tabs.slice(-4).map(item => item.text), ['工作台', '客户', '订单', '我的']);
  currentRoute = 'pages/home';
  installLanguage(createSSRApp({}), createI18n, pages);
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
