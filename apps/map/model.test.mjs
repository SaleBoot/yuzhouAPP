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
test("native map exposes the same-origin update link without waiting for boat data", async () => {
  const html = await readFile(new URL("./index.html", import.meta.url), "utf8");
  const source = await readFile(new URL("./map.js", import.meta.url), "utf8");
  const anchor = html.match(/<a\b[^>]*\bid="app-update"[^>]*>应用更新<\/a>/)?.[0];
  assert.ok(anchor);
  assert.match(anchor, /\bhref="\/app-updates\/"/);
  assert.match(anchor, /\bhidden\b/);
  for (const [search, hidden] of [["?client=broker&native=1", false], ["?client=consumer&native=1", false], ["?native=0", true], ["", true]]) {
    const elements = new Map([...html.matchAll(/\bid="([^"]+)"/g)].map((match) => [match[1], { hidden: true, addEventListener() {} }]));
    vm.runInNewContext(source.replace(/^import .*;\n/, ""), {
      URLSearchParams, AbortController,
      location: { search },
      window: {},
      document: { getElementById: (id) => elements.get(id), addEventListener() {} },
      setTimeout() {}, clearTimeout() {}, fetch: () => new Promise(() => {}),
    });
    assert.equal(elements.get("app-update").hidden, hidden);
  }
});
