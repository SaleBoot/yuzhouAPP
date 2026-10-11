import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { runInNewContext } from "node:vm";

function apiHarness(response, { native = false } = {}) {
  const calls = [], storage = new Map(), enabled = [];
  const source = readFileSync(new URL("./api.js", import.meta.url), "utf8")
    .split("\n").filter((line) => {
      const condition = line.match(/^\/\/ #ifdef (.*)$/);
      if (condition) { enabled.push(condition[1] === (native ? "APP-PLUS" : "H5")); return false; }
      if (line === "// #endif") { enabled.pop(); return false; }
      return enabled.every(Boolean) && !line.startsWith("import ");
    }).join("\n").replaceAll("import.meta.env", "env").replace(/^export /gm, "");
  const api = runInNewContext(`${source}; ({ list, media });`, {
    env: {}, URL, window: { location: { origin: "https://h5.test" } },
    uni: {
      getStorageSync: (key) => storage.get(key),
      request(options) {
        calls.push(options.url);
        Promise.resolve().then(() => response(new URL(options.url, "https://h5.test"), calls.length)).then(
          data => options.success({ statusCode: 200, data: { code: 0, data } }),
          () => options.fail(),
        );
      },
    },
  });
  return { api, calls };
}
const resource = (id) => ({ id, title: `实船 ${id}`, price_cents: id * 100, payload: { boat_type_id: 1, image_urls: [`/api/v1/files/${id}`] } });

test("目录和实船选择器加载第 100 条之后的资源并保留筛选条件", async () => {
  const records = Array.from({ length: 101 }, (_, index) => resource(index + 1));
  const { api, calls } = apiHarness(url => {
    assert.equal(url.searchParams.get("search"), "船艇 & 配置");
    const page = Number(url.searchParams.get("page"));
    return { items: records.slice((page - 1) * 100, page * 100), page, page_size: 100, total: records.length };
  });
  const items = await api.list("boats", { search: "船艇 & 配置" });
  assert.equal(items.length, 101);
  assert.equal(items[100].id, 101);
  assert.equal(items[100].name, "实船 101");
  assert.equal(items[100].image_url, "https://h5.test/api/v1/files/101");
  assert.equal(calls.length, 2);
});

test("资源分页使用实际返回的页面容量，显式页码保持单页行为", async () => {
  const { api, calls } = apiHarness(url => {
    const page = Number(url.searchParams.get("page"));
    assert.equal(url.searchParams.get("placement"), "home");
    return { items: [resource(page)], total: 3, page, page_size: 1 };
  });
  const items = await api.list("banners", { placement: "home", page_size: 1 });
  assert.equal(items.length, 3);
  assert.equal(calls.length, 3);
  const page = await api.list("banners", { placement: "home", page: 2, page_size: 1 });
  assert.equal(page.length, 1);
  assert.equal(page[0].id, 2);
  assert.equal(calls.length, 4);
});

test("错误页码、重复页面和后续网络失败不会冒充完整资源目录", async () => {
  const wrongPage = apiHarness(() => ({ items: [resource(1)], total: 3, page: 1, page_size: 1 }));
  await assert.rejects(wrongPage.api.list("boats"), /返回信息不完整/);
  const repeated = apiHarness(url => ({ items: [resource(1)], total: 3, page: Number(url.searchParams.get("page")), page_size: 1 }));
  await assert.rejects(repeated.api.list("boats"), /分页返回异常/);
  assert.equal(repeated.calls.length, 2);
  const emptyPage = apiHarness(url => ({ items: [], total: 3, page: Number(url.searchParams.get("page")), page_size: 1 }));
  await assert.rejects(emptyPage.api.list("boats"), /分页返回异常/);
  const emptyCatalog = apiHarness(() => ({ items: [], total: 0, page: 1, page_size: 100 }));
  assert.equal((await emptyCatalog.api.list("boats")).length, 0);
  const offline = apiHarness((url, attempt) => {
    if (attempt === 2) throw new Error("offline");
    return { items: [resource(1)], total: 2, page: 1, page_size: 1 };
  });
  await assert.rejects(offline.api.list("boats"), /暂时无法连接服务/);
});

test("原生 App 的后续资源和图片继续使用 HTTPS 服务地址", async () => {
  const { api, calls } = apiHarness(url => {
    const page = Number(url.searchParams.get("page"));
    return { items: [resource(page)], total: 2, page, page_size: 1 };
  }, { native: true });
  const items = await api.list("boats", { page_size: 1 });
  assert.equal(calls[1], "https://yuzhou.ourideas.top/api/v1/resources/boats?page_size=1&page=2");
  assert.equal(items[1].image_url, "https://yuzhou.ourideas.top/api/v1/files/2");
});
