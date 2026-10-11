const english = {
  '驭舟 · 地图找船': 'Yuzhou · Boat map',
  '地图找船': 'Find boats on map',
  '船艇位置由发布方维护': 'Boat locations are maintained by their publishers',
  '刷新': 'Refresh',
  '已录入位置的船艇地图': 'Map of published boat locations',
  '船艇列表': 'Boat list',
  '地图服务使用政策': 'Map service policy',
  '地图数据 © ': 'Map data © ',
  '正在加载已发布船艇…': 'Loading published boats…',
  '船艇信息暂不可用': 'Boat information is unavailable',
  '查看实船与配置': 'View boat and options',
  '查看船艇': 'View boat',
  'App连接尚未完成，请稍后重试。': 'The App connection is not ready. Please try again shortly.',
  '已录入船艇位置': 'Published boat location',
  '经度 {longitude} · 纬度 {latitude}': 'Longitude {longitude} · Latitude {latitude}',
  '实船售价 ¥{amount}': 'Boat price ¥{amount}',
  '在地图中查看': 'Show on map',
  '船艇列表分页返回异常，请重试': 'The boat list could not be loaded correctly. Please retry.',
  '已显示 {count} 艘船艇，另有 {missing} 艘尚未录入有效位置。': '{count} boats have published locations; {missing} have no valid location yet.',
  '暂无已录入位置的已发布实船，请联系船艇发布方完善位置。': 'No published boats have a valid location yet. Please ask their publishers to add one.',
  '发布方完善船艇位置后，将在地图中显示船艇标记。': 'Boat markers will appear after their publishers add locations.',
  '高德地图配置暂不可用，当前使用既有地图。': 'AMap configuration is unavailable. The existing map is shown.',
  '高德地图暂不可用，船艇列表和坐标仍可查看。': 'AMap is unavailable. Boat listings and coordinates remain available.',
  '高德地图Key配置不正确。': 'The AMap key configuration is invalid.',
  '高德地图必须使用本站安全代理。': 'AMap requires this site’s security proxy.',
  '地图前端配置不能包含安全密钥。': 'The browser map configuration must not contain a security secret.',
  '高德地图尚未配置。': 'AMap has not been configured yet.',
  '高德地图加载失败，请检查Key、域名、安全代理或网络。': 'AMap could not load. Please check its key, domain, security proxy and network.',
  '高德地图加载超时，请重试。': 'AMap loading timed out. Please retry.',
  '坐标类型待确认，暂不在高德定位。': 'The coordinate system needs confirmation before this boat can be placed on AMap.',
  '{count} 艘船艇坐标类型待确认，原始坐标仍可查看。': 'The coordinate system of {count} boats needs confirmation. Their original coordinates remain available.',
  '地图底图配置必须使用HTTPS': 'Map tiles must use HTTPS',
  '地图服务提供方': 'Map provider',
  '底图暂不可用，船艇位置和列表仍可查看。': 'Map tiles are unavailable. Boat locations and listings remain available.',
  '当前使用既有OSM底图。': 'The existing OSM map is shown.',
  '当前使用既有地图底图。': 'The existing map tiles are shown.',
  '地图底图尚未配置，当前可查看船艇标记和坐标。': 'Map tiles have not been configured. Boat markers and coordinates remain available.',
  '船艇信息加载超时，请重试。': 'Boat information loading timed out. Please retry.',
  '地图暂不可用，请重试。': 'The map is unavailable. Please retry.',
};
let stored;
try { stored = localStorage.getItem('yuzhou_language'); } catch {}
const requested = typeof location === 'undefined' ? null : new URLSearchParams(location.search).get('lang');
export const locale = (requested || stored) === 'en' ? 'en' : 'zh-Hans';
export function t(key, params = {}) {
  return (locale === 'en' ? english[key] || key : key).replace(/\{(\w+)\}/g, (match, name) => params[name] ?? match);
}
