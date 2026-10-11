import { locatedBoats, boatPath } from "./model.js";
import { amapConfig, amapPosition, loadAMap } from "./amap.js";
import { t, locale } from "./i18n.js";
const client = new URLSearchParams(location.search).get("client") === "broker" ? "broker" : "consumer";
const native = new URLSearchParams(location.search).get("native") === "1";
const status = document.getElementById("status");
const list = document.getElementById("boats");
const note = document.getElementById("provider-note");
const refresh = document.getElementById("refresh");
document.documentElement.lang = locale;
document.title = t('驭舟 · 地图找船');
for (const element of document.querySelectorAll('[data-i18n]')) element.textContent = t(element.dataset.i18n);
for (const element of document.querySelectorAll('[aria-label]')) element.setAttribute('aria-label', t(element.getAttribute('aria-label')));
let bridgeReady = window.UniAppJSBridge === true;
document.addEventListener("UniAppJSBridgeReady", () => { bridgeReady = true; });
let map;
let disposeMap = () => {};
function clearMap() { disposeMap(); disposeMap = () => {}; map = null; }
async function api(path) {
  const abort = new AbortController();
  const timer = setTimeout(() => abort.abort(), 20000);
  try {
    const response = await fetch(`/api/v1${path}`, { signal: abort.signal });
    const value = await response.json();
    if (!response.ok || value.code !== 0) throw new Error(value.message || t("船艇信息暂不可用"));
    return value.data;
  } finally { clearTimeout(timer); }
}
function detailLink(boat) {
  const link = document.createElement("a");
  link.className = "detail-link";
  link.textContent = client === "broker" ? t("查看实船与配置") : t("查看船艇");
  link.href = `/${client}/#${boatPath(client, boat)}`;
  link.addEventListener("click", (event) => {
    if (native) {
      event.preventDefault();
      if (!bridgeReady || !window.uni?.webView) {
        status.textContent = t("App连接尚未完成，请稍后重试。");
        return;
      }
      window.uni.webView.navigateTo({ url: boatPath(client, boat) });
    } else if (window.parent !== window) {
      event.preventDefault();
      window.parent.postMessage({ action: "boat-detail", boat_id: Number(boat.id), boat_type_id: Number(boat.payload.boat_type_id) }, location.origin);
    }
  });
  return link;
}
function text(tag, value) {
  const item = document.createElement(tag);
  item.textContent = value;
  return item;
}
function boatCard(boat, locateBoat, popup = false) {
  const p = boat.payload;
  const card = document.createElement("article");
  card.className = popup ? "" : "boat-card";
  if (!popup && p.image_urls?.[0]) {
    try {
      const url = new URL(p.image_urls[0], location.origin);
      if (["http:", "https:"].includes(url.protocol)) {
        const image = document.createElement("img");
        image.src = url.href; image.alt = boat.title; image.loading = "lazy";
        card.append(image);
      }
    } catch { /* Invalid media URLs do not block the public map. */ }
  }
  card.append(text(popup ? "strong" : "h2", locale === 'en' ? p.title_en || boat.title : boat.title));
  card.append(text("p", p.location || p.city || t("已录入船艇位置")));
  if (!popup) {
    card.append(text("p", t('经度 {longitude} · 纬度 {latitude}', { longitude: p.longitude, latitude: p.latitude })));
    if (Number.isSafeInteger(Number(boat.price_cents))) card.append(text("p", t('实船售价 ¥{amount}', { amount: (Number(boat.price_cents) / 100).toFixed(2) })));
  }
  const actions = document.createElement("div"); actions.className = "actions";
  actions.append(detailLink(boat));
  if (!popup && locateBoat) {
    const locate = text("button", t("在地图中查看")); locate.type = "button"; locate.className = "locate";
    locate.addEventListener("click", () => {
      locateBoat();
      document.getElementById("map").scrollIntoView({ behavior: "smooth" });
    });
    actions.append(locate);
  }
  card.append(actions);
  return card;
}
function tileConfig(config) {
  if (config.map_tiles?.url) return config.map_tiles;
  if (config.is_test_environment) return { url: "https://tile.openstreetmap.org/{z}/{x}/{y}.png", attribution: "© OpenStreetMap contributors", is_test_provider: true };
  return null;
}
async function load() {
  refresh.disabled = true; status.className = ""; status.textContent = t("正在加载已发布船艇…"); note.textContent = "";
  try {
    const config = await api("/bootstrap");
    const all = new Map();
    for (let page = 1; ; page++) {
      const batch = await api(`/resources/boats?page=${page}&page_size=100`);
      const before = all.size;
      for (const item of batch.items || []) all.set(Number(item.id), item);
      if (!batch.items?.length || all.size >= Number(batch.total)) break;
      if (all.size === before || Number(batch.page) !== page) throw new Error(t("船艇列表分页返回异常，请重试"));
    }
    const boats = locatedBoats([...all.values()]);
    status.textContent = boats.length ? t('已显示 {count} 艘船艇，另有 {missing} 艘尚未录入有效位置。', { count: boats.length, missing: all.size - boats.length }) : t("暂无已录入位置的已发布实船，请联系船艇发布方完善位置。");
    clearMap();
    list.replaceChildren();
    const holder = document.getElementById("map"); holder.replaceChildren();
    for (const boat of boats) list.append(boatCard(boat, null));
    if (!boats.length) list.append(text("p", t("发布方完善船艇位置后，将在地图中显示船艇标记。")));
    let key = '';
    try {
      const abort = new AbortController(); const timer = setTimeout(() => abort.abort(), 20000);
      try {
        const response = await fetch('./map-config.json', { cache: 'no-store', credentials: 'omit', signal: abort.signal });
        if (!response.ok) throw new Error('地图配置暂不可用');
        key = amapConfig(await response.json()).amaps_js_key;
      } finally { clearTimeout(timer); }
    } catch { note.textContent = t("高德地图配置暂不可用，当前使用既有地图。"); }
    if (key) {
      document.getElementById('tile-credit').hidden = true;
      const failed = error => {
        clearMap(); holder.replaceChildren(text('p', t('高德地图暂不可用，船艇列表和坐标仍可查看。')));
        list.replaceChildren(...boats.map(boat => boatCard(boat, null)));
        if (!boats.length) list.append(text('p', t('发布方完善船艇位置后，将在地图中显示船艇标记。')));
        status.className = 'error'; status.textContent = t(error.message);
      };
      let maps;
      try { maps = await loadAMap(key); }
      catch (error) { failed(error); return; }
      try {
        map = new maps.Map(holder, { center: [105, 35], zoom: 4, lang: locale === 'en' ? 'en' : 'zh_cn' });
        disposeMap = () => map.destroy();
        const popup = new maps.InfoWindow();
        const markers = [];
        disposeMap = () => { popup.close(); map.destroy(); };
        list.replaceChildren();
        for (const boat of boats) {
          const position = amapPosition(boat);
          if (!position) { const card = boatCard(boat, null); card.append(text('p', t('坐标类型待确认，暂不在高德定位。'))); list.append(card); continue; }
          const marker = new maps.Marker({ map, position, title: boat.title });
          const open = () => { popup.setContent(boatCard(boat, null, true)); popup.open(map, position); };
          marker.on('click', open); markers.push(marker);
          list.append(boatCard(boat, () => { map.setZoomAndCenter(13, position); open(); }));
        }
        if (markers.length) { map.setFitView(markers, true); if (map.getZoom() > 13) map.setZoom(13); }
        if (!boats.length) list.append(text('p', t('发布方完善船艇位置后，将在地图中显示船艇标记。')));
        note.textContent = boats.length > markers.length ? t('{count} 艘船艇坐标类型待确认，原始坐标仍可查看。', { count: boats.length - markers.length }) : '';
      } catch { failed(new Error('高德地图加载失败，请检查Key、域名、安全代理或网络。')); }
      return;
    }
    map = window.L.map(holder, { zoomControl: true });
    disposeMap = () => map.remove();
    const tiles = tileConfig(config);
    document.getElementById('tile-credit').hidden = !tiles?.is_test_provider;
    const prefix = note.textContent || t("高德地图尚未配置。");
    if (tiles) {
      if (!/^https:\/\//i.test(tiles.url)) throw new Error(t("地图底图配置必须使用HTTPS"));
      // Attribution is rendered as text; configured HTML is never injected.
      const credit = document.createElement("span"); credit.textContent = tiles.attribution || t("地图服务提供方");
      window.L.tileLayer(tiles.url, { maxZoom: 18, keepBuffer: 0, attribution: credit.outerHTML, referrerPolicy: "strict-origin-when-cross-origin" })
        .on("tileerror", () => { note.textContent = `${prefix}${t('底图暂不可用，船艇位置和列表仍可查看。')}`; }).addTo(map);
      note.textContent = `${prefix}${tiles.is_test_provider ? t("当前使用既有OSM底图。") : t("当前使用既有地图底图。")}`;
    } else note.textContent = `${prefix}${t('地图底图尚未配置，当前可查看船艇标记和坐标。')}`;
    list.replaceChildren();
    const markers = [];
    for (const boat of boats) {
      const marker = window.L.marker([boat.payload.latitude, boat.payload.longitude]).addTo(map);
      marker.bindPopup(boatCard(boat, null, true)); markers.push(marker); list.append(boatCard(boat, () => { map.setView(marker.getLatLng(), 13); marker.openPopup(); }));
    }
    if (markers.length) map.fitBounds(window.L.featureGroup(markers).getBounds().pad(0.2), { maxZoom: 13 });
    else { map.setView([35, 105], 4); list.append(text('p', t('发布方完善船艇位置后，将在地图中显示船艇标记。'))); }
  } catch (error) {
    status.className = "error";
    status.textContent = error.name === "AbortError" ? t("船艇信息加载超时，请重试。") : t(error.message || "地图暂不可用，请重试。");
  } finally { refresh.disabled = false; }
}
refresh.addEventListener("click", load);
load();
