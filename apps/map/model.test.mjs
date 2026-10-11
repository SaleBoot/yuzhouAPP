import test from "node:test";
import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import vm from "node:vm";
import { locatedBoats, boatPath, validDetailMessage } from "./model.js";
const boat = { id: 5, kind: "boats", status: "published", payload: { boat_type_id: 2, latitude: 0, longitude: 0 } };
test("only published actual boats with paired valid coordinates enter the map", () => {
  const items = [boat,
    { ...boat, status: "pending" },
    { ...boat, kind: "boat_types" },
    { ...boat, payload: { ...boat.payload, latitude: null } },
    { ...boat, payload: { ...boat.payload, longitude: "121.5" } },
    { ...boat, payload: { ...boat.payload, latitude: 91 } },
    { ...boat, payload: { ...boat.payload, longitude: Infinity } },
  ];
  assert.deepEqual(locatedBoats(items), [boat]);
});
test("detail paths carry the actual instance and validate bridge identifiers", () => {
  assert.equal(boatPath("consumer", boat), "/pages/boat?id=2&instance_id=5&mode=buy");
  assert.equal(boatPath("broker", boat), "/pages/quote?boat_id=5");
  assert.equal(validDetailMessage({ action: "boat-detail", boat_id: 5, boat_type_id: 2 }), true);
  assert.equal(validDetailMessage({ action: "boat-detail", boat_id: "5", boat_type_id: 2 }), false);
  assert.equal(validDetailMessage({ action: "boat-detail", boat_id: -1, boat_type_id: 2 }), false);
});
test("map has only refresh in the header for native and H5 clients", async () => {
  const html = await readFile(new URL("./index.html", import.meta.url), "utf8");
  const source = await readFile(new URL("./map.js", import.meta.url), "utf8");
  assert.doesNotMatch(html, /app-update|应用更新|app-updates/);
  assert.match(html, /<button\b[^>]*\bid="refresh"[^>]*>刷新<\/button>/);
  for (const search of ["?client=broker&native=1", "?client=consumer&native=1", "?native=0", ""]) {
    const elements = new Map([...html.matchAll(/\bid="([^"]+)"/g)].map((match) => [match[1], { hidden: true, addEventListener() {} }]));
    vm.runInNewContext(source.replace(/^import .*;\n/gm, ""), {
      URLSearchParams, AbortController,
      location: { search },
      window: {},
      document: { documentElement: {}, querySelectorAll: () => [], getElementById: (id) => elements.get(id), addEventListener() {} },
      t: (text) => text, locale: 'zh-Hans',
      setTimeout() {}, clearTimeout() {}, fetch: () => new Promise(() => {}),
    });
    assert.equal(elements.has("app-update"), false);
  }
});
