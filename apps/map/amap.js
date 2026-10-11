import { locatedBoats } from './model.js';
let pending;
export function amapConfig(config = {}) {
  const key = config.amaps_js_key ?? '';
  if (typeof key !== 'string' || (key && !/^[a-f0-9]{32}$/i.test(key))) throw new Error('高德地图Key配置不正确。');
  if (config.serviceHost !== undefined && config.serviceHost !== '/_AMapService') throw new Error('高德地图必须使用本站安全代理。');
  for (const name of ['securityJsCode', 'security_js_code', 'jscode'])
    if (Object.prototype.hasOwnProperty.call(config, name)) throw new Error('地图前端配置不能包含安全密钥。');
  return { amaps_js_key: key, serviceHost: '/_AMapService' };
}
export function amapPosition(boat) {
  if (!locatedBoats([boat]).length) return null;
  const system = String(boat.payload.coordinate_system || '').toUpperCase().replace(/[-_]/g, '');
  return system === 'GCJ02' ? [boat.payload.longitude, boat.payload.latitude] : null;
}
export function loadAMap(key, target = window) {
  amapConfig({ amaps_js_key: key });
  if (!key) throw new Error('高德地图尚未配置。');
  if (pending) return pending;
  target._AMapSecurityConfig = { serviceHost: `${target.location.origin}/_AMapService` };
  pending = new Promise((resolve, reject) => {
    let script, timer, settled = false;
    const failed = (message = '高德地图加载失败，请检查Key、域名、安全代理或网络。') => {
      if (settled) return;
      settled = true;
      clearTimeout(timer); script?.remove(); pending = null;
      reject(new Error(message));
    };
    const load = () => {
      if (!target.AMapLoader?.load) { failed(); return; }
      Promise.resolve().then(() => target.AMapLoader.load({ key, version: '2.0' })).then(sdk => {
        if (settled) return;
        if (!sdk?.Map || !sdk.Marker || !sdk.InfoWindow) { failed(); return; }
        settled = true; clearTimeout(timer); resolve(sdk);
      }, () => failed());
    };
    timer = setTimeout(() => failed('高德地图加载超时，请重试。'), 20000);
    if (target.AMapLoader?.load) load();
    else {
      script = target.document.createElement('script');
      script.async = true; script.src = 'https://webapi.amap.com/loader.js';
      script.referrerPolicy = 'strict-origin-when-cross-origin';
      script.onload = load; script.onerror = () => failed();
      target.document.head.append(script);
    }
  });
  return pending;
}
