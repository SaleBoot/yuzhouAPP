import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { runInNewContext } from "node:vm";
import { bannerText, bannerTarget } from "./banner-model.js";
const consumer = JSON.parse(readFileSync(new URL("../consumer/src/pages.json", import.meta.url)));
const broker = JSON.parse(readFileSync(new URL("../broker/src/pages.json", import.meta.url)));

test("published advert text follows the chosen App language and keeps Chinese metadata intact", () => {
  const item = { name: "租船出海", title_en: "Sail for the day", description: "按日租赁", description_en: "Daily boat rentals" };
  assert.deepEqual(bannerText(item, "en"), { title: "Sail for the day", description: "Daily boat rentals" });
  assert.deepEqual(bannerText(item, "zh-Hans"), { title: "租船出海", description: "按日租赁" });
  assert.deepEqual(bannerText({ name: "旧广告", description: "旧副标题" }, "en"), { title: "旧广告", description: "" });
  assert.equal(item.name, "租船出海");
  const cached = { title: "租船出海", name: "Sail for the day", description: "Daily boat rentals", payload: item };
  assert.deepEqual(bannerText(cached, "zh-Hans"), { title: "租船出海", description: "按日租赁" });
});

test("advert destinations preserve boat mode and every existing shop category using actual App routes", () => {
  for (const url of ["/pages/boat?id=36&mode=rent", "/pages/boat?id=34&mode=buy", ...["maintenance", "upgrades", "products", "events"].map(kind => `/pages/shop?kind=${kind}`)])
    assert.equal(bannerTarget(url, consumer).url, url);
  for (const page of ["classroom", "discover"])
    assert.deepEqual(bannerTarget(`/pages/${page}`, consumer), { page, url: `/pages/${page}`, tab: false });
  for (const page of ["maintenance", "mall"])
    assert.deepEqual(bannerTarget(`/pages/${page}`, consumer), { page, url: `/pages/${page}`, tab: true });
  assert.deepEqual(bannerTarget("https://www.ourideas.top/detail.html?id=12", consumer), { external: "https://www.ourideas.top/detail.html?id=12" });
  for (const invalid of ["javascript:alert(1)", "//other.test", "https://", "/pages/community/points", "/pages/missing", "/pages/home?mode=buy", "/pages/maintenance?kind=upgrades", "/pages/mall?kind=products", "/pages/boat#fragment", "/pages/boat?bad=space value"])
    assert.equal(bannerTarget(invalid, consumer), null);
  assert.equal(bannerTarget("/pages/home", broker), null);
});

test("advert taps use ordinary navigation for classroom and Discover and tab switching for the new catalogs", () => {
  const source = readFileSync(new URL("./BannerStrip.vue", import.meta.url), "utf8").match(/<script setup>([\s\S]*?)<\/script>/)[1].replace(/^import .*;\n/gm, "");
  const navigations = [], tabs = [], errors = [];
  const { open } = runInNewContext(`${source}; ({ open });`, {
    defineProps: () => ({ placement: "home" }), ref: value => ({ value }), watch() {}, onShow() {},
    t: value => value, getLocale: () => "zh-Hans", config: consumer, bannerText, bannerTarget,
    list() {}, external() {}, tab: page => tabs.push(page), notify: error => errors.push(error), fallbackBoat: "/static/yacht.jpg",
    uni: { navigateTo: options => navigations.push(options.url) },
  });
  for (const page of ["classroom", "discover", "maintenance", "mall"])
    open({ target_url: `/pages/${page}` });
  open({ target_url: "/pages/shop?kind=upgrades" });
  open({ target_url: "/pages/mall?kind=products" });
  assert.deepEqual(navigations, ["/pages/classroom", "/pages/discover", "/pages/shop?kind=upgrades"]);
  assert.deepEqual(tabs, ["maintenance", "mall"]);
  assert.equal(errors.length, 1);
});

test("page re-entry refreshes advertising, late placement responses are ignored and failed refresh clears stale adverts", async () => {
  const requests = [], lifecycle = [];
  const props = { placement: "home" };
  let changed;
  const source = readFileSync(new URL("./BannerStrip.vue", import.meta.url), "utf8").match(/<script setup>([\s\S]*?)<\/script>/)[1].replace(/^import .*;\n/gm, "");
  const component = runInNewContext(`${source}; ({ load, items, error, open });`, {
    defineProps: () => props, ref: value => ({ value }), watch: (getter, handler, options) => { changed = handler; if (options.immediate) handler(); }, onShow: handler => lifecycle.push(handler),
    t: value => value, getLocale: () => "en", config: consumer, bannerText, bannerTarget,
    list: (kind, filters) => new Promise((resolve, reject) => requests.push({ kind, filters, resolve, reject })),
    external() {}, tab() {}, notify() {}, fallbackBoat: "/static/yacht.jpg", uni: {},
  });
  assert.equal(requests[0].filters.placement, "home");
  requests[0].resolve([{ id: 1 }]); await Promise.resolve(); await Promise.resolve();
  const refresh = lifecycle[0]();
  assert.equal(requests.length, 2);
  requests[1].resolve([{ id: 2 }]); await refresh;
  assert.equal(component.items.value[0].id, 2);
  props.placement = "shop"; const older = changed();
  props.placement = "learning"; const newer = changed();
  requests[3].resolve([{ id: 4 }]); await newer;
  requests[2].resolve([{ id: 3 }]); await older;
  assert.equal(component.items.value[0].id, 4);
  const failure = component.load(); requests[4].reject(new Error("offline")); await failure;
  assert.equal(component.items.value.length, 0);
  assert.equal(component.error.value, "offline");
});
