import { cp, mkdir, rm, readFile } from "node:fs/promises";
import assert from "node:assert/strict";
const root = new URL("./", import.meta.url);
const pkg = JSON.parse(await readFile(new URL("node_modules/leaflet/package.json", root)));
assert.equal(pkg.version, "1.9.4");
const dist = new URL("dist/", root);
await rm(dist, { recursive: true, force: true });
await mkdir(new URL("vendor/leaflet/", dist), { recursive: true });
for (const file of ["index.html", "map.js", "model.js", "map.css"])
  await cp(new URL(file, root), new URL(file, dist));
for (const file of ["leaflet.js", "leaflet.js.map", "leaflet.css", "images"])
  await cp(new URL(`node_modules/leaflet/dist/${file}`, root), new URL(`vendor/leaflet/${file}`, dist), { recursive: true });
await cp(new URL("node_modules/leaflet/LICENSE", root), new URL("vendor/leaflet/LICENSE", dist));
await cp(new URL("vendor/uni.webview.1.5.8.js", root), new URL("vendor/uni.webview.1.5.8.js", dist));
await cp(new URL("vendor/uni-app-LICENSE", root), new URL("vendor/uni-app-LICENSE", dist));
console.log("Boat map built: dist/ (Leaflet 1.9.4, local UniApp webview bridge)");
