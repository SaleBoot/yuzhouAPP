import { getLocale, t } from './i18n.js';

const ORIGIN = 'https://yuzhou.ourideas.top';
const APPS = {
  __proto__: null,
  __UNI__E148E73: { id: 'consumer', package: 'top.ourideas.yuzhou.consumer' },
  __UNI__59A80C3: { id: 'broker', package: 'top.ourideas.yuzhou.broker' },
};
let checking = false;
let lastCheck = 0;
let updateWindow;
let requiredVersion = 0;
const requiredKey = () => `yuzhou_${plus.runtime.appid}_required_update`;

function code(value) {
  return /^[1-9]\d*$/.test(String(value)) && Number.isSafeInteger(Number(value)) ? Number(value) : 0;
}
function property() {
  return new Promise((resolve, reject) => {
    const timer = setTimeout(() => reject(new Error('version timeout')), 15000);
    plus.runtime.getProperty(plus.runtime.appid, info => { clearTimeout(timer); resolve(info); });
  });
}
function validate(item, current) {
  const appid = plus.runtime.appid;
  const app = APPS[appid];
  return app && current?.appid === appid && code(current.versionCode) && item?.appid === appid &&
    item.package === app.package && /^\d+\.\d+\.\d+$/.test(item.version_name) &&
    Number.isSafeInteger(item.version_code) && item.version_code > 0 &&
    (item.mandatory === undefined || typeof item.mandatory === 'boolean') &&
    Number.isSafeInteger(item.bytes) && item.bytes > 0 && item.bytes <= 100 * 1024 * 1024 &&
    /^[a-f0-9]{64}$/.test(item.sha256) && /^[a-f0-9]{40}$/.test(item.source_commit) &&
    item.compiler_version === '5.26' && /^5\.26(?:\.|$)/.test(plus.runtime.uniVersion || '') &&
    item.url === `/app-updates/${app.id}-${item.version_name}.wgt`;
}
function openRequiredUpdate() {
  if (updateWindow) return updateWindow.show('none');
  const view = plus.webview.create(`${ORIGIN}/app-updates/?lang=${getLocale()}&required=1&minimum=${requiredVersion}`, 'yuzhou-required-update', {
    top: '0px', bottom: '0px', left: '0px', right: '0px', popGesture: 'none',
    background: '#f8f8f8', titleNView: false,
  });
  updateWindow = view;
  view.onhide = () => { if (updateWindow === view) view.show('none'); };
  view.onclose = async () => {
    if (updateWindow !== view) return;
    updateWindow = undefined;
    if (!requiredVersion) return;
    openRequiredUpdate();
    try {
      const current = await property();
      if (current?.appid === plus.runtime.appid && code(current.versionCode) >= requiredVersion) {
        requiredVersion = 0;
        uni.removeStorageSync(requiredKey());
        const active = updateWindow;
        updateWindow = undefined;
        active?.close('none');
        return;
      }
    } catch {}
  };
  view.onerror = () => uni.showModal({
    title: t('应用更新'), content: t('更新页面加载失败，请检查网络后重试。'), showCancel: false,
    confirmText: t('重试'), success: () => { if (updateWindow === view) view.reload(); },
  });
  view.show('none');
}

export async function checkNativeUpdate() {
  if (typeof plus === 'undefined' || !plus.runtime || !APPS[plus.runtime.appid]) return;
  if (requiredVersion) { openRequiredUpdate(); return; }
  if (checking || Date.now() - lastCheck < 60000) return;
  checking = true;
  lastCheck = Date.now();
  try {
    const saved = uni.getStorageSync(requiredKey());
    if (Number.isSafeInteger(saved) && saved > 0) {
      requiredVersion = saved;
      openRequiredUpdate();
    }
    const current = await property();
    if (current?.appid !== plus.runtime.appid || !code(current.versionCode)) throw new Error('invalid current identity');
    if (Number.isSafeInteger(saved) && saved > code(current.versionCode)) {
      return;
    }
    requiredVersion = 0;
    uni.removeStorageSync(requiredKey());
    if (updateWindow) {
      const active = updateWindow;
      updateWindow = undefined;
      active.close('none');
    }
    const data = await new Promise((resolve, reject) => uni.request({
      url: `${ORIGIN}/app-updates/latest.json?_=${Date.now()}`, timeout: 20000,
      header: { 'Cache-Control': 'no-cache' },
      success: response => response.statusCode === 200 ? resolve(response.data) : reject(new Error('metadata unavailable')),
      fail: reject,
    }));
    const item = data?.apps?.[plus.runtime.appid];
    if (!validate(item, current)) throw new Error('invalid metadata');
    if (item.mandatory === true && item.version_code > code(current.versionCode)) {
      requiredVersion = item.version_code;
      uni.setStorageSync(requiredKey(), requiredVersion);
      openRequiredUpdate();
    }
  } catch {
    // Unknown releases never lock a working offline installation; a confirmed update remains blocking.
    uni.showToast({ title: t('暂时无法检查更新，请联网后重新打开。'), icon: 'none', duration: 3000 });
  } finally { checking = false; }
}
