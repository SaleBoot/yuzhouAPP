import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { createRequire } from "node:module";
import { runInNewContext } from "node:vm";

const { ref, computed } = createRequire(new URL("../broker/package.json", import.meta.url))("vue");
const config = JSON.parse(readFileSync(new URL("../broker/src/pages.json", import.meta.url)));
const pageSource = page => readFileSync(new URL(`../broker/src/pages/${page}.vue`, import.meta.url), "utf8");
const pageScript = page => pageSource(page).match(/<script setup>([\s\S]*?)<\/script>/)[1]
  .replace(/^import[\s\S]*?;\n/gm, "");

function navigation() {
  const calls = [];
  const uni = {
    navigateTo({ url }) {
      const path = url.split("?")[0].slice(1);
      assert.ok(config.pages.some(page => page.path === path), `Missing route: ${path}`);
      assert.ok(!config.tabBar.list.some(page => page.pagePath === path), `navigateTo used for tab: ${path}`);
      calls.push({ method: "navigateTo", url });
    },
    switchTab({ url }) {
      assert.ok(config.tabBar.list.some(page => `/${page.pagePath}` === url), `Invalid tab: ${url}`);
      calls.push({ method: "switchTab", url });
    },
  };
  const source = readFileSync(new URL("./api.js", import.meta.url), "utf8")
    .replace(/^import .*\n/gm, "").replaceAll("import.meta.env", "env").replace(/^export /gm, "");
  const api = runInNewContext(`${source}; ({ go, tab });`, {
    t: value => value, getLocale: () => "zh-Hans", env: { VITE_CLIENT: "broker" }, uni,
  });
  return { ...api, uni, calls };
}

test("broker profile keeps all destinations, uses packaged icons and preserves the four tabs", () => {
  const routes = navigation();
  const { entries } = runInNewContext(`${pageScript("profile")}; ({ entries });`, {
    ref, computed, session: () => null, onShow() {}, t: value => value, go: routes.go, tab: routes.tab,
  });
  assert.equal(entries.length, 8);
  for (const entry of entries) {
    entry.action();
    assert.match(entry.icon, /^\/static\/profile-[a-z-]+\.png$/);
    const image = readFileSync(new URL(`../broker/src${entry.icon}`, import.meta.url));
    assert.equal(image.subarray(0, 8).toString("hex"), "89504e470d0a1a0a", `Packaged PNG: ${entry.title}`);
  }
  assert.deepEqual(routes.calls, [
    { method: "navigateTo", url: "/pages/commission?" },
    { method: "navigateTo", url: "/pages/reports?" },
    { method: "navigateTo", url: "/pages/chat?" },
    { method: "navigateTo", url: "/pages/appointment?" },
    { method: "switchTab", url: "/pages/customers" },
    { method: "switchTab", url: "/pages/orders" },
    { method: "navigateTo", url: "/pages/viewer?url=" + encodeURIComponent("https://yuzhou.ourideas.top/app-updates/?lang=zh-Hans") },
    { method: "navigateTo", url: "/pages/settings?" },
  ]);
  assert.deepEqual(config.tabBar.list.map(page => [page.pagePath, page.text]), [
    ["pages/work", "工作台"], ["pages/customers", "客户"], ["pages/orders", "订单"], ["pages/profile", "我的"],
  ]);
});

test("broker language control lives in settings, whose back fallback uses the profile tab", () => {
  assert.doesNotMatch(pageSource("profile"), /LanguagePicker/);
  assert.match(pageSource("settings"), /import LanguagePicker from ["']@shared\/LanguagePicker\.vue["']/);
  assert.match(pageSource("settings"), /<LanguagePicker\s*\/>/);
  for (const page of ["profile", "settings"])
    assert.equal(config.pages.find(item => item.path === `pages/${page}`).style.navigationStyle, "custom");
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

test("broker profile refresh and logout retain the authenticated session behavior", async () => {
  let saved = { token: "session-token", user: { id: 1, nickname: "原昵称" } }, shown;
  const requested = [], posts = [], destinations = [], updated = { id: 1, nickname: "新昵称", org_id: 2 };
  const page = runInNewContext(`${pageScript("profile")}; ({ user, error, logout });`, {
    ref, computed, session: () => saved, saveSession: value => { saved = value; },
    onShow: callback => { shown = callback; }, t: value => value,
    request: async path => { requested.push(path); return { user: updated }; },
    post: async path => { posts.push(path); }, go: path => destinations.push(path), tab() {},
  });
  await shown();
  assert.deepEqual(requested, ["/auth/me"]);
  assert.equal(page.user.value.nickname, "新昵称");
  assert.equal(saved.token, "session-token");
  assert.equal(saved.user.id, 1);
  await page.logout();
  assert.deepEqual(posts, ["/auth/logout"]);
  assert.equal(saved, null);
  assert.equal(page.user.value, null);
  assert.deepEqual(destinations, ["login"]);
});
