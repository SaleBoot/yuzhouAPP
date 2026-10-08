import { locatedBoats, boatPath } from "./model.js";
const client = new URLSearchParams(location.search).get("client") === "broker" ? "broker" : "consumer";
const native = new URLSearchParams(location.search).get("native") === "1";
const status = document.getElementById("status");
const list = document.getElementById("boats");
const note = document.getElementById("provider-note");
const refresh = document.getElementById("refresh");
let bridgeReady = window.UniAppJSBridge === true;
document.addEventListener("UniAppJSBridgeReady", () => { bridgeReady = true; });
let map;
async function api(path) {
  const abort = new AbortController();
  const timer = setTimeout(() => abort.abort(), 20000);
  try {
    const response = await fetch(`/api/v1${path}`, { signal: abort.signal });
    const value = await response.json();
    if (!response.ok || value.code !== 0) throw new Error(value.message || "船艇信息暂不可用");
    return value.data;
  } finally { clearTimeout(timer); }
}
function detailLink(boat) {
  const link = document.createElement("a");
  link.className = "detail-link";
  link.textContent = client === "broker" ? "查看实船与配置" : "查看船艇";
  link.href = `/${client}/#${boatPath(client, boat)}`;
  link.addEventListener("click", (event) => {
    if (native) {
      event.preventDefault();
      if (!bridgeReady || !window.uni?.webView) {
        status.textContent = "App连接尚未完成，请稍后重试。";
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
function boatCard(boat, marker, popup = false) {
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
  card.append(text(popup ? "strong" : "h2", boat.title));
  card.append(text("p", p.location || p.city || "已录入船艇位置"));
  if (!popup) {
    card.append(text("p", `经度 ${p.longitude} · 纬度 ${p.latitude}`));
    if (Number.isSafeInteger(Number(boat.price_cents))) card.append(text("p", `实船售价 ¥${(Number(boat.price_cents) / 100).toFixed(2)}`));
  }
  const actions = document.createElement("div"); actions.className = "actions";
  actions.append(detailLink(boat));
  if (!popup) {
    const locate = text("button", "在地图中查看"); locate.type = "button"; locate.className = "locate";
    locate.addEventListener("click", () => {
      map.setView(marker.getLatLng(), 13); marker.openPopup();
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
  refresh.disabled = true; status.className = ""; status.textContent = "正在加载已发布船艇…"; note.textContent = "";
  try {
    const config = await api("/bootstrap");
    const all = new Map();
    for (let page = 1; ; page++) {
      const batch = await api(`/resources/boats?page=${page}&page_size=100`);
      const before = all.size;
      for (const item of batch.items || []) all.set(Number(item.id), item);
      if (!batch.items?.length || all.size >= Number(batch.total)) break;
      if (all.size === before || Number(batch.page) !== page) throw new Error("船艇列表分页返回异常，请重试");
    }
    const boats = locatedBoats([...all.values()]);
    status.textContent = boats.length ? `已显示 ${boats.length} 艘船艇，另有 ${all.size - boats.length} 艘尚未录入有效位置。` : "暂无已录入位置的已发布实船，请联系船艇发布方完善位置。";
    if (map) { map.remove(); map = null; }
    list.replaceChildren();
    const holder = document.getElementById("map"); holder.replaceChildren();
    if (!boats.length) { holder.append(text("p", "位置完善后，将在这里展示船艇地图。")); return; }
    map = window.L.map(holder, { zoomControl: true });
    const tiles = tileConfig(config);
    if (tiles) {
      if (!/^https:\/\//i.test(tiles.url)) throw new Error("地图底图配置必须使用HTTPS");
      // Attribution is rendered as text; configured HTML is never injected.
      const credit = document.createElement("span"); credit.textContent = tiles.attribution || "地图服务提供方";
      window.L.tileLayer(tiles.url, { maxZoom: 18, keepBuffer: 0, attribution: credit.outerHTML, referrerPolicy: "strict-origin-when-cross-origin" })
        .on("tileerror", () => { note.textContent = "底图暂不可用，船艇位置和列表仍可查看。"; }).addTo(map);
      if (tiles.is_test_provider) note.textContent = "当前底图用于低流量测试。";
    } else note.textContent = "地图底图尚未配置，当前可查看船艇标记和坐标。";
    const markers = [];
    for (const boat of boats) {
      const marker = window.L.marker([boat.payload.latitude, boat.payload.longitude]).addTo(map);
      marker.bindPopup(boatCard(boat, marker, true)); markers.push(marker); list.append(boatCard(boat, marker));
    }
    map.fitBounds(window.L.featureGroup(markers).getBounds().pad(0.2), { maxZoom: 13 });
  } catch (error) {
    status.className = "error";
    status.textContent = error.name === "AbortError" ? "船艇信息加载超时，请重试。" : error.message || "地图暂不可用，请重试。";
  } finally { refresh.disabled = false; }
}
refresh.addEventListener("click", load);
load();
