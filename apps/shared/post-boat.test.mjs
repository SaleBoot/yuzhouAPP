import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { publicPostAttachment, findBoatFavorite, toggleBoatFavorite } from "./post-boat.js";

test("media display resolves canonical H5 files without changing stored or temporary URLs", () => {
  const source = readFileSync(new URL("./api.js", import.meta.url), "utf8");
  const body = source.match(/export function media\(path\) \{([\s\S]*?)\n\}/)[1];
  const h5 = new Function("BASE", "window", "path", body);
  const native = new Function("BASE", "path", body.replace(/\/\/ #ifdef H5[\s\S]*?\/\/ #endif/, ""));
  const window = { location: { origin: "https://local.test" } };
  assert.equal(h5("/api/v1", window, "/api/v1/files/8"), "https://local.test/api/v1/files/8");
  assert.equal(native("https://native.test/api/v1", "/api/v1/files/8"), "https://native.test/api/v1/files/8");
  for (const path of ["https://external.test/video.mp4", "blob:local-video", "data:image/png;base64,test", "file:///tmp/video.mp4", "_doc/video.mp4", "/consumer/static/yacht.jpg", "/api/v1/reports/dividends.xlsx", "/api/v1/files/0", undefined])
    assert.equal(h5("/api/v1", window, path), path);
  for (const path of ["https://external.test/video.mp4", "blob:local-video", "file:///tmp/video.mp4", "_doc/video.mp4"])
    assert.equal(native("https://native.test/api/v1", path), path);
});

test("public post attachments retain exact local URLs and media kinds", () => {
  assert.deepEqual(publicPostAttachment({ id: 8, url: "/api/v1/files/8", mime: "image/png", private: false }), { url: "/api/v1/files/8", video: false });
  assert.deepEqual(publicPostAttachment({ id: 9, url: "/api/v1/files/9", mime: "video/mp4", private: false }), { url: "/api/v1/files/9", video: true });
});
test("private, unconfirmed, mismatched and unsupported attachments fail", () => {
  const file = { id: 8, url: "/api/v1/files/8", mime: "image/png", private: false };
  for (const update of [{ private: true }, { private: undefined }, { id: 9 }, { url: "https://example.test/api/v1/files/8" }, { mime: "application/pdf" }])
    assert.throws(() => publicPostAttachment({ ...file, ...update }));
});
test("initial favorite lookup scans subsequent pages", async () => {
  const paths = [];
  const result = await findBoatFavorite(async path => {
    paths.push(path);
    return paths.length === 1
      ? { items: Array.from({ length: 100 }, (_, i) => ({ id: i + 1, resource_id: i + 1, status: "active" })), total: 101 }
      : { items: [{ id: 201, resource_id: 500, status: "active" }], total: 101 };
  }, 500);
  assert.equal(result.id, 201);
  assert.deepEqual(paths, ["/favorites?page=1&page_size=100", "/favorites?page=2&page_size=100"]);
});
test("empty favorite state adds only the requested resource", async () => {
  assert.equal(await findBoatFavorite(async () => ({ items: [], total: 0 }), 7), null);
  const result = await toggleBoatFavorite(async (path, options) => {
    assert.equal(path, "/favorites");
    assert.deepEqual(options, { method: "POST", data: { resource_id: 7 } });
    return { id: 20, resource_id: 7, status: "active" };
  }, 7, null);
  assert.equal(result.id, 20);
});
test("cancel uses the favorite record ID and requires server confirmation", async () => {
  assert.equal(await toggleBoatFavorite(async (path, options) => {
    assert.equal(path, "/favorites/20");
    assert.deepEqual(options, { method: "DELETE" });
    return { deleted: true };
  }, 7, 20), null);
});
test("invalid state, wrong acknowledgements and network failures do not succeed", async () => {
  await assert.rejects(findBoatFavorite(async () => ({ items: [] }), 7));
  await assert.rejects(findBoatFavorite(async () => ({ items: [{ id: 0, resource_id: 7, status: "active" }], total: 1 }), 7));
  await assert.rejects(toggleBoatFavorite(async () => ({ id: 20, resource_id: 8, status: "active" }), 7, null));
  await assert.rejects(toggleBoatFavorite(async () => ({ deleted: false }), 7, 20));
  await assert.rejects(toggleBoatFavorite(async () => { throw new Error("offline"); }, 7, 20), /offline/);
});
