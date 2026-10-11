import test from "node:test";
import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { createRequire } from "node:module";
import { displayTitle } from "./domain.js";
import { t } from "./i18n.js";
const require = createRequire(new URL("../consumer/package.json", import.meta.url));
const { ref, reactive, computed } = require("vue");
const source = await readFile(new URL("./AccountPanel.vue", import.meta.url), "utf8");
const script = source.match(/<script setup>([\s\S]*?)<\/script>/)[1].replace(/^import[\s\S]*?;\n/gm, "");
const setup = new Function("dependencies", `const { ref, reactive, computed, watch, defineProps, request, post, go, media, notify, requireLogin, session, dateTime, ORDER_TYPES, displayTitle, t, uni } = dependencies; ${script}; return { load, loadOrders, create, selectOrder, unfavorite, form, items, error, loading, busy, orders, ordersPage, ordersLoading, ordersError, moreOrders, selectedOrder, orderLabels, removing };`);
const order = (id, extra = {}) => ({ id, order_no: `YZ-TEST-${id}`, customer_id: 7, status: "completed", type: "mall", items: [{ title: "合成商品" }], ...extra });
function harness({ kind = "reviews", orders = [], reviews = [], favorites = [], request: override, post: postOverride } = {}) {
  const props = reactive({ kind }), calls = [], notices = [], toasts = [];
  let loggedIn = true;
  const state = setup({
    ref, reactive, computed, watch: () => {}, defineProps: () => props,
    request: async (path, options = {}) => {
      calls.push({ path, ...options });
      if (override) return override(path, options);
      const url = new URL(path, "https://offline.invalid"), page = Number(url.searchParams.get("page") || 1);
      if (url.pathname === "/orders") return { items: orders.slice((page - 1) * 100, page * 100), page, page_size: 100, total: orders.length };
      if (url.pathname === "/reviews") return { items: reviews.slice((page - 1) * 100, page * 100), page, page_size: 100, total: reviews.length };
      if (url.pathname === "/favorites") return { items: favorites };
      if (options.method === "DELETE") return { deleted: true };
      throw new Error(`Unexpected offline request: ${path}`);
    },
    post: async (path, data) => {
      calls.push({ path, method: "POST", data });
      if (postOverride) return postOverride(path, data);
      reviews.push({ id: reviews.length + 1, order_id: data.order_id });
      return reviews.at(-1);
    },
    session: () => ({ user: { id: 7 } }), requireLogin: () => loggedIn,
    notify: error => notices.push(error.message), uni: { showToast: toast => toasts.push(toast) },
    go: () => {}, media: value => value, dateTime: () => "", ORDER_TYPES: { mall: "周边商城" }, displayTitle, t,
  });
  return { state, props, calls, notices, toasts, setLoggedIn: value => { loggedIn = value; } };
}

test("退出登录后清空缓存评价和可选订单，不再请求私人记录", async () => {
  const { state, calls, setLoggedIn } = harness({ orders: [order(1), order(2)], reviews: [{ order_id: 2 }] });
  await state.load();
  state.selectOrder({ detail: { value: 1 } });
  assert.equal(state.items.value.length, 1);
  assert.equal(state.orders.value.length, 1);
  assert.equal(state.form.order_id, 1);
  const requestCount = calls.length;
  setLoggedIn(false);
  await state.load();
  assert.equal(calls.length, requestCount);
  assert.deepEqual(state.items.value, []);
  assert.deepEqual(state.orders.value, []);
  assert.equal(state.form.order_id, null);
  assert.equal(state.ordersPage.value, 0);
  assert.equal(state.moreOrders.value, false);
  assert.equal(state.loading.value, false);
  assert.equal(state.ordersLoading.value, false);
});

test("评价使用本人已完成订单和业务名称，不再输入内部订单 ID", async () => {
  const { state, calls } = harness({ orders: [order(1), order(2, { customer_id: 8 }), order(3, { status: "paid" }), order(4)], reviews: [{ order_id: "4" }] });
  await state.load();
  assert.equal(calls[1].path, "/orders?status=completed&page_size=100&page=1");
  assert.deepEqual(state.orders.value.map(item => item.id), [1]);
  assert.deepEqual(state.orderLabels.value, ["请选择已完成订单", "YZ-TEST-1 · 合成商品"]);
  assert.equal(state.selectedOrder.value, undefined);
  state.selectOrder({ detail: { value: "1" } });
  assert.equal(state.form.order_id, 1);
  state.selectOrder({ detail: { value: "0" } });
  assert.equal(state.form.order_id, null);
  assert.doesNotMatch(source, /v-model="form.order_id"|填写订单ID|已完成订单ID/);
});
test("评价订单名称清理历史版本后缀，保存的项目快照保持原值", async () => {
  for (const [original, displayed] of [["航海防水包（演示）", "航海防水包"], ["年度船艇保养（演示版）", "年度船艇保养"], ["JS-910B · 演示1号", "JS-910B · 1号"]]) {
    const snapshot = order(1, { items: [{ title: original }] });
    const { state } = harness({ orders: [snapshot] });
    await state.load();
    assert.equal(state.orderLabels.value[1], `YZ-TEST-1 · ${displayed}`);
    assert.equal(snapshot.items[0].title, original);
    assert.equal(state.orders.value[0].items[0].title, original);
  }
  for (const text of ["用户分享演示视频", "航海（演示）课程", "JS-910B · 演示2号"]) assert.equal(displayTitle(text), text);
});
test("评价排除第 100 条之后的已评价订单，订单 picker 可继续分页", async () => {
  const orders = Array.from({ length: 101 }, (_, i) => order(i + 1));
  const reviews = Array.from({ length: 101 }, (_, i) => ({ id: i + 1, order_id: i + 1 }));
  const { state, calls } = harness({ orders, reviews });
  await state.load();
  assert.equal(state.items.value.length, 101);
  assert.equal(calls[1].path, "/reviews?page_size=100&page=2");
  assert.equal(state.orders.value.length, 0);
  assert.equal(state.moreOrders.value, true);
  await state.loadOrders();
  assert.equal(state.ordersPage.value, 2);
  assert.equal(state.moreOrders.value, false);
  assert.equal(state.orders.value.length, 0);
});
test("订单请求失败可重试原页，空列表与错误状态不同", async () => {
  let failed = true;
  const { state } = harness({ request: async path => {
    if (path.startsWith("/reviews")) return { items: [], total: 0 };
    if (failed) throw new Error("合成网络失败");
    return { items: [], total: 0, page: 1, page_size: 100 };
  } });
  await state.load();
  assert.equal(state.ordersError.value, "合成网络失败");
  assert.equal(state.ordersLoading.value, false);
  assert.equal(state.ordersPage.value, 0);
  failed = false;
  await state.loadOrders();
  assert.equal(state.ordersError.value, "");
  assert.equal(state.ordersPage.value, 1);
  assert.equal(state.orders.value.length, 0);
});
test("分页追加保持所选订单，重复点击加载只发一次请求", async () => {
  let release;
  const { state, calls } = harness({ request: async path => {
    if (path.startsWith("/reviews")) return { items: [], total: 0 };
    if (path.endsWith("page=1")) return { items: [order(1)], total: 101, page: 1, page_size: 100 };
    return new Promise(resolve => { release = resolve; });
  } });
  await state.load(); state.selectOrder({ detail: { value: 1 } });
  const first = state.loadOrders(); await state.loadOrders();
  assert.equal(calls.filter(call => call.path.endsWith("page=2")).length, 1);
  release({ items: [order(2)], total: 101, page: 2, page_size: 100 }); await first;
  assert.deepEqual(state.orders.value.map(item => item.id), [1, 2]);
  assert.equal(state.selectedOrder.value.id, 1);
  assert.equal(state.moreOrders.value, false);
});
test("未选择或加载失败不能提交手工赋值的订单", async () => {
  const { state, calls, notices } = harness({ orders: [order(1)] });
  await state.load(); state.form.title = "合成评价";
  state.form.order_id = 999; await state.create();
  state.form.order_id = 1; state.ordersError.value = "合成失败"; await state.create();
  assert.equal(calls.filter(call => call.method === "POST").length, 0);
  assert.equal(notices.length, 2);
});
test("评价只提交选中真实订单，重复点击只提交一次，成功后清除选择", async () => {
  let release;
  const reviews = [], { state, calls, toasts } = harness({ orders: [order(1)], reviews, post: async (path, data) => {
    await new Promise(resolve => { release = resolve; }); reviews.push({ id: 1, order_id: data.order_id });
  } });
  await state.load(); state.form.title = "合成评价"; state.form.content = "合成内容";
  state.selectOrder({ detail: { value: "1" } });
  const first = state.create(); await state.create();
  assert.equal(calls.filter(call => call.method === "POST").length, 1);
  assert.equal(calls.find(call => call.method === "POST").data.order_id, 1);
  release(); await first;
  assert.equal(state.busy.value, false); assert.equal(state.form.order_id, null);
  assert.equal(state.form.content, ""); assert.equal(state.orders.value.length, 0);
  assert.deepEqual(toasts.map(toast => toast.title), ["已保存"]);
});
test("服务端已评价冲突清除该选择，其他失败保留输入且不显示成功", async () => {
  for (const message of ["此订单已评价。", "合成网络失败"]) {
    const { state, notices, toasts } = harness({ orders: [order(1)], post: async () => { throw new Error(message); } });
    await state.load(); state.selectOrder({ detail: { value: 1 } }); state.form.title = "合成评价"; state.form.content = "保留内容";
    await state.create();
    assert.deepEqual(notices, [message]); assert.equal(toasts.length, 0);
    assert.equal(state.form.content, "保留内容"); assert.equal(state.busy.value, false);
    assert.equal(state.form.order_id, message === "此订单已评价。" ? null : 1);
  }
});
test("切换账户记录类型后迟到订单响应不能污染新页面", async () => {
  let release;
  const { state, props } = harness({ request: async path => {
    if (path.startsWith("/reviews")) return { items: [], total: 0 };
    if (path.startsWith("/favorites")) return { items: [{ id: 9 }] };
    return new Promise(resolve => { release = resolve; });
  } });
  const first = state.load(); await new Promise(resolve => setImmediate(resolve));
  props.kind = "favorites"; await state.load();
  release({ items: [order(1)], total: 1, page: 1, page_size: 100 }); await first;
  assert.deepEqual(state.items.value, [{ id: 9 }]); assert.equal(state.orders.value.length, 0);
  assert.equal(state.loading.value, false); assert.equal(state.ordersLoading.value, false);
});
test("取消收藏仅作用于 favorites，阻止卡片跳转和重复删除", async () => {
  let release;
  const { state, props, calls, toasts } = harness({ kind: "favorites", request: async (path, options) => {
    if (options.method === "DELETE") return new Promise(resolve => { release = resolve; });
    return { items: [] };
  } });
  state.items.value = [{ id: 11 }];
  const first = state.unfavorite({ id: 11 }); await state.unfavorite({ id: 11 });
  assert.equal(calls.length, 1); assert.equal(calls[0].path, "/favorites/11"); assert.equal(calls[0].method, "DELETE");
  release({ deleted: true }); await first;
  assert.equal(state.items.value.length, 0); assert.equal(state.removing.value, null);
  assert.equal(toasts[0].title, "已取消收藏");
  props.kind = "reviews"; await state.unfavorite({ id: 12 });
  assert.equal(calls.filter(call => call.method === "DELETE").length, 1);
  assert.match(source, /@click\.stop="unfavorite\(item\)"/);
});
test("取消收藏失败或未确认删除时保留卡片且不显示成功", async () => {
  for (const result of [new Error("合成删除失败"), {}]) {
    const { state, notices, toasts } = harness({ kind: "favorites", request: async () => { if (result instanceof Error) throw result; return result; } });
    state.items.value = [{ id: 11 }]; await state.unfavorite({ id: 11 });
    assert.deepEqual(state.items.value, [{ id: 11 }]); assert.equal(state.removing.value, null);
    assert.equal(notices.length, 1); assert.equal(toasts.length, 0);
  }
});
