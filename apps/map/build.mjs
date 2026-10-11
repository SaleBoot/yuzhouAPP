import { cp, mkdir, rm, readFile, writeFile } from "node:fs/promises";
import assert from "node:assert/strict";
import { amapConfig } from './amap.js';
const root = new URL("./", import.meta.url);
const pkg = JSON.parse(await readFile(new URL("node_modules/leaflet/package.json", root)));
assert.equal(pkg.version, "1.9.4");
const config = process.env.MAP_CONFIG_FILE ? JSON.parse(await readFile(process.env.MAP_CONFIG_FILE, 'utf8')) : {};
const publicConfig = amapConfig({ amaps_js_key: process.env.AMAP_JS_API_KEY ?? config.amaps_js_key ?? '', serviceHost: '/_AMapService' });
const dist = new URL("dist/", root);
await rm(dist, { recursive: true, force: true });
await mkdir(new URL("vendor/leaflet/", dist), { recursive: true });
for (const file of ["index.html", "map.js", "model.js", "amap.js", "i18n.js", "map.css"])
  await cp(new URL(file, root), new URL(file, dist));
await writeFile(new URL('map-config.json', dist), JSON.stringify(publicConfig) + '\n');
for (const file of ["leaflet.js", "leaflet.js.map", "leaflet.css", "images"])
  await cp(new URL(`node_modules/leaflet/dist/${file}`, root), new URL(`vendor/leaflet/${file}`, dist), { recursive: true });
await cp(new URL("node_modules/leaflet/LICENSE", root), new URL("vendor/leaflet/LICENSE", dist));
await cp(new URL("vendor/uni.webview.1.5.8.js", root), new URL("vendor/uni.webview.1.5.8.js", dist));
await cp(new URL("vendor/uni-app-LICENSE", root), new URL("vendor/uni-app-LICENSE", dist));
console.log(`Boat map built: dist/ (AMap JS API Key configured: ${Boolean(publicConfig.amaps_js_key)}, Leaflet 1.9.4, local UniApp webview bridge)`);
