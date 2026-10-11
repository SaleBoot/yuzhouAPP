import shared from './locales/shared.js';
import business from './locales/shared-business.js';
import consumer from './locales/consumer.js';
import broker from './locales/broker.js';

const STORAGE_KEY = 'yuzhou_language';
export const english = { ...shared, ...business, ...consumer, ...broker };
let translator;
let pageConfig;
export function getLocale() {
  return translator?.locale.value || 'zh-Hans';
}
export function t(key, params = {}) {
  key = String(key ?? "");
  if (translator && translator.te(key)) return translator.t(key, params);
  return String(key ?? '').replace(/\{(\w+)\}/g, (match, name) => params[name] ?? match);
}
export function refreshNavigation() {
  if (!pageConfig || typeof uni === 'undefined') return;
  const pages = typeof getCurrentPages === 'function' ? getCurrentPages() : [];
  const route = pages[pages.length - 1]?.route;
  if (!route) return;
  if (pageConfig.tabBar?.list.some(item => item.pagePath === route))
    for (const [index, item] of pageConfig.tabBar.list.entries())
      uni.setTabBarItem({ index, text: t(item.text), fail() {} });
  const page = pageConfig.pages.find(item => item.path === route);
  if (page && page.style?.navigationStyle !== 'custom')
    uni.setNavigationBarTitle({ title: t(page.style?.navigationBarTitleText || pageConfig.globalStyle.navigationBarTitleText), fail() {} });
}
export function setLocale(locale) {
  if (!['zh-Hans', 'en'].includes(locale)) return;
  translator.locale.value = locale;
  uni.setStorageSync(STORAGE_KEY, locale);
  uni.setLocale?.(locale);
  refreshNavigation();
}
export function installLanguage(app, createI18n, config) {
  let saved;
  try { saved = uni.getStorageSync(STORAGE_KEY); } catch {}
  const locale = saved === 'en' ? 'en' : 'zh-Hans';
  const chinese = Object.fromEntries(Object.keys(english).map(key => [key, key]));
  const i18n = createI18n({
    legacy: false, locale, fallbackLocale: 'zh-Hans',
    messages: { en: english, 'zh-Hans': chinese },
    messageResolver: (messages, key) => Object.prototype.hasOwnProperty.call(messages, key) ? messages[key] : null,
    missingWarn: false, fallbackWarn: false,
  });
  translator = i18n.global;
  pageConfig = config;
  app.use(i18n);
  app.mixin({ onShow: refreshNavigation });
  uni.setLocale?.(locale);
  return i18n;
}
