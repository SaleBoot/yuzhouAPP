import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { createRequire } from "node:module";
import { runInNewContext } from "node:vm";

const { ref, computed } = createRequire(new URL("../consumer/package.json", import.meta.url))("vue");
const consumer = JSON.parse(readFileSync(new URL("../consumer/src/pages.json", import.meta.url)));
const broker = JSON.parse(readFileSync(new URL("../broker/src/pages.json", import.meta.url)));
const pageScript = page => readFileSync(new URL(`../consumer/src/pages/${page}.vue`, import.meta.url), "utf8")
  .match(/<script setup>([\s\S]*?)<\/script>/)[1].replace(/^import[\s\S]*?;\n/gm, "");

function navigation() {
  const calls = [];
  const uni = {
    navigateTo(options) {
      const path = options.url.split("?")[0].slice(1);
      assert.ok(consumer.pages.some(page => page.path === path), `Missing route: ${path}`);
      assert.ok(!consumer.tabBar.list.some(page => page.pagePath === path), `navigateTo used for tab: ${path}`);
      calls.push({ method: "navigateTo", url: options.url });
    },
    redirectTo(options) {
      const path = options.url.split("?")[0].slice(1);
      assert.ok(consumer.pages.some(page => page.path === path), `Missing route: ${path}`);
      assert.ok(!consumer.tabBar.list.some(page => page.pagePath === path), `redirectTo used for tab: ${path}`);
      calls.push({ method: "redirectTo", url: options.url });
    },
    switchTab(options) {
      assert.ok(consumer.tabBar.list.some(page => `/${page.pagePath}` === options.url), `switchTab used for ordinary page: ${options.url}`);
      calls.push({ method: "switchTab", url: options.url });
    },
    showToast() {},
  };
  const source = readFileSync(new URL("./api.js", import.meta.url), "utf8")
    .replace(/^import .*\n/gm, "").replaceAll("import.meta.env", "env").replace(/^export /gm, "");
  const api = runInNewContext(`${source}; ({ go, tab });`, {
    t: value => value, getLocale: () => "zh-Hans", env: {}, uni,
  });
  return { ...api, uni, calls };
}

test("consumer has the four requested tabs and keeps academy, Discover and legacy shop routes reachable", () => {
  assert.deepEqual(consumer.tabBar.list.map(item => [item.pagePath, item.text]), [
    ["pages/home", "船艇销售"], ["pages/maintenance", "船艇维保"], ["pages/mall", "商城"], ["pages/profile", "我的"],
  ]);
  assert.equal(consumer.pages[0].path, "pages/home");
  for (const page of ["classroom", "discover", "shop", "settings"]) {
    assert.ok(consumer.pages.some(item => item.path === `pages/${page}`));
    assert.ok(!consumer.tabBar.list.some(item => item.pagePath === `pages/${page}`));
  }
  for (const page of ["profile", "settings"])
    assert.equal(consumer.pages.find(item => item.path === `pages/${page}`).style.navigationStyle, "custom");
  assert.deepEqual(broker.tabBar.list.map(item => [item.pagePath, item.text]), [
    ["pages/work", "工作台"], ["pages/customers", "客户"], ["pages/orders", "订单"], ["pages/profile", "我的"],
  ]);
});

test("My menu opens academy, Discover and settings through the actual ordinary-page navigation helper", () => {
  const routes = navigation();
  const { entries } = runInNewContext(`${pageScript("profile")}; ({ entries });`, {
    ref, computed, onShow() {}, t: value => value,
    go: routes.go, tab: routes.tab,
  });
  const destinations = [];
  for (const entry of entries) {
    const before = routes.calls.length;
    entry.action();
    destinations.push(...routes.calls.slice(before).map(call => ({ ...call, title: entry.title })));
  }
  for (const page of ["classroom", "discover", "settings"]) {
    const matches = destinations.filter(call => call.url.split("?")[0] === `/pages/${page}`);
    assert.equal(matches.length, 1, `My menu entry: ${page}`);
    assert.equal(matches[0].method, "navigateTo");
  }
});

test("My service and order grids use packaged icons and language selection lives in settings", () => {
  const routes = navigation();
  const { entries, orderEntries } = runInNewContext(`${pageScript("profile")}; ({ entries, orderEntries });`, {
    ref, computed, onShow() {}, t: value => value,
    go: routes.go, tab: routes.tab,
  });
  for (const entry of [...entries, ...orderEntries]) {
    assert.match(entry.icon, /^\/static\/profile-[a-z-]+\.png$/, `Icon path: ${entry.title}`);
    const image = readFileSync(new URL(`../consumer/src${entry.icon}`, import.meta.url));
    assert.equal(image.subarray(0, 8).toString("hex"), "89504e470d0a1a0a", `Packaged PNG: ${entry.title}`);
  }
  const profile = readFileSync(new URL("../consumer/src/pages/profile.vue", import.meta.url), "utf8");
  const settings = readFileSync(new URL("../consumer/src/pages/settings.vue", import.meta.url), "utf8");
  assert.doesNotMatch(profile, /LanguagePicker/);
  assert.match(settings, /import LanguagePicker from ["']@shared\/LanguagePicker\.vue["']/);
  assert.match(settings, /<LanguagePicker\s*\/>/);
});

test("settings returns to its previous page and uses profile tab only when native back fails", () => {
  const routes = navigation(), attempts = [];
  routes.uni.navigateBack = options => attempts.push(options);
  const { back } = runInNewContext(`${pageScript("settings")}; ({ back });`, {
    t: value => value, tab: routes.tab, uni: routes.uni,
  });
  back();
  assert.equal(attempts.length, 1);
  assert.equal(routes.calls.length, 0);
  attempts[0].fail();
  assert.deepEqual(routes.calls, [{ method: "switchTab", url: "/pages/profile" }]);
});

test("concurrent submission sends one post for review then replaces the submitted form with Discover", async () => {
  const routes = navigation(), posts = [], notices = [];
  routes.uni.showToast = options => notices.push(options.title);
  let confirm;
  const page = runInNewContext(`${pageScript("publish")}; ({ submit, title, content, busy });`, {
    ref, t: value => value, requireLogin: () => true,
    go: routes.go, tab: routes.tab, uni: routes.uni,
    post: (path, data) => {
      posts.push({ path, data: JSON.parse(JSON.stringify(data)) });
      return new Promise(resolve => { confirm = resolve; });
    },
    notify: error => { throw error; },
  });
  page.title.value = "我的航程";
  page.content.value = "航海记录";
  const submitting = page.submit();
  await page.submit();
  assert.equal(posts.length, 1);
  assert.equal(page.busy.value, true);
  assert.equal(routes.calls.length, 0);
  confirm();
  await submitting;
  assert.deepEqual(posts, [{ path: "/resources/posts", data: { title: "我的航程", payload: { content: "航海记录", image_urls: [], video_url: "" } } }]);
  assert.deepEqual(notices, ["已提交审核"]);
  assert.deepEqual(routes.calls, [{ method: "redirectTo", url: "/pages/discover" }]);
  assert.equal(page.busy.value, false);
});

test("a failed community submission preserves the draft and stays on the page", async () => {
  const routes = navigation(), errors = [], notices = [];
  routes.uni.showToast = options => notices.push(options);
  const failure = new Error("offline");
  const page = runInNewContext(`${pageScript("publish")}; ({ submit, title, content, busy });`, {
    ref, t: value => value, requireLogin: () => true,
    go: routes.go, tab: routes.tab, uni: routes.uni,
    post: async () => { throw failure; }, notify: error => errors.push(error),
  });
  page.title.value = "待重试航程";
  page.content.value = "保留正文";
  await page.submit();
  assert.deepEqual(errors, [failure]);
  assert.equal(routes.calls.length, 0);
  assert.equal(notices.length, 0);
  assert.equal(page.title.value, "待重试航程");
  assert.equal(page.content.value, "保留正文");
  assert.equal(page.busy.value, false);
});
