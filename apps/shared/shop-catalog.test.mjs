import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { createRequire } from "node:module";
const require = createRequire(new URL("../consumer/package.json", import.meta.url));
const { ref, reactive, computed, watch, nextTick } = require("vue");
const source = readFileSync(new URL("../consumer/src/components/ShopCatalog.vue", import.meta.url), "utf8");
const script = file => file.match(/<script setup>([\s\S]*?)<\/script>/)[1].replace(/^import .*;\n/gm, "");
const setup = new Function("dependencies", `const { ref, computed, watch, onMounted, defineProps, defineExpose, list, go } = dependencies; ${script(source)}; return { kind, tabs, items, error, loading, load, selectKind, open };`);

function harness({ kinds = ["products", "upgrades", "maintenance", "events"], initialKind = "", list = async () => [] } = {}) {
  const props = reactive({ title: "航海生活", kinds, initialKind }), mounted = [], calls = [], destinations = [];
  let exposed;
  const state = setup({
    ref, computed, watch, onMounted: callback => mounted.push(callback), defineProps: () => props,
    defineExpose: value => { exposed = value; },
    list: kind => { calls.push(kind); return list(kind); },
    go: (page, params) => destinations.push({ page, ...params }),
  });
  return { state, props, calls, destinations, mounted, exposed };
}

function page(name) {
  const source = readFileSync(new URL(`../consumer/src/pages/${name}.vue`, import.meta.url), "utf8");
  const shown = [], loaded = [];
  const state = new Function("ref", "onLoad", "onShow", `${script(source)}; return { catalog${name === "shop" ? ", initialKind" : ""} };`)(
    ref, callback => loaded.push(callback), callback => shown.push(callback),
  );
  const kinds = new Function(`return ${source.match(/:kinds="([^"]+)"/)[1]}`)();
  return { source, state, shown, loaded, kinds };
}

test("维保只列保养维修和升级改装，商城只列平台商品，初次进入与返回页面刷新同一组件", async () => {
  for (const [name, expected] of [["maintenance", ["maintenance", "upgrades"]], ["mall", ["products"]]]) {
    const wrapper = page(name), component = harness({ kinds: wrapper.kinds });
    assert.deepEqual(wrapper.kinds, expected);
    assert.equal(component.state.kind.value, expected[0]);
    assert.deepEqual(component.state.tabs.value.map(tab => tab.kind), expected);
    assert.equal(wrapper.loaded.length, 0);
    assert.doesNotThrow(() => wrapper.shown[0]());
    await component.mounted[0]();
    wrapper.state.catalog.value = component.exposed;
    await wrapper.shown[0]();
    assert.deepEqual(component.calls, [expected[0], expected[0]]);
    const count = component.calls.length;
    await component.state.selectKind("events");
    assert.equal(component.calls.length, count);
    assert.equal(component.state.kind.value, expected[0]);
  }
});

test("原商城普通页保留四种 kind 查询入口及对应商品或活动详情跳转", async () => {
  for (const kind of ["products", "upgrades", "maintenance", "events"]) {
    const wrapper = page("shop");
    wrapper.loaded[0]({ kind });
    const component = harness({ kinds: wrapper.kinds, initialKind: wrapper.state.initialKind.value });
    await component.mounted[0]();
    component.state.open({ id: 17 });
    assert.deepEqual(component.calls, [kind]);
    assert.deepEqual(component.destinations, [{ page: kind === "events" ? "event" : "product", id: 17, kind }]);
  }
  const component = harness({ initialKind: "not-a-catalog" });
  await component.mounted[0]();
  assert.deepEqual(component.calls, ["products"]);
});

test("切换维保类别清空上一类卡片，失败显示错误，重试成功清除错误", async () => {
  let fail = false;
  const component = harness({ kinds: ["maintenance", "upgrades"], list: async kind => {
    if (fail) throw new Error("合成网络失败");
    return [{ id: kind === "maintenance" ? 1 : 2 }];
  } });
  await component.mounted[0]();
  assert.deepEqual(component.state.items.value, [{ id: 1 }]);
  fail = true;
  const changed = component.state.selectKind("upgrades");
  assert.deepEqual(component.state.items.value, []);
  assert.equal(component.state.loading.value, true);
  await changed;
  assert.equal(component.state.error.value, "合成网络失败");
  assert.equal(component.state.loading.value, false);
  assert.deepEqual(component.state.items.value, []);
  fail = false;
  await component.state.load();
  assert.equal(component.state.error.value, "");
  assert.deepEqual(component.state.items.value, [{ id: 2 }]);
  component.state.open(component.state.items.value[0]);
  assert.deepEqual(component.destinations, [{ page: "product", id: 2, kind: "upgrades" }]);
});

test("迟到的旧类别结果不能覆盖当前列表或提前结束当前加载", async () => {
  const requests = [];
  const component = harness({ kinds: ["maintenance", "upgrades"], list: kind => new Promise((resolve, reject) => requests.push({ kind, resolve, reject })) });
  const older = component.mounted[0](), newer = component.state.selectKind("upgrades");
  requests[0].resolve([{ id: 1 }]); await older;
  assert.equal(component.state.loading.value, true);
  assert.deepEqual(component.state.items.value, []);
  requests[1].resolve([{ id: 2 }]); await newer;
  assert.deepEqual(component.state.items.value, [{ id: 2 }]);
  const lateFailure = component.state.load(), current = component.state.selectKind("maintenance");
  requests[3].resolve([]); await current;
  requests[2].reject(new Error("旧类别请求失败")); await lateFailure;
  assert.equal(component.state.kind.value, "maintenance");
  assert.equal(component.state.error.value, "");
  assert.equal(component.state.loading.value, false);
  assert.deepEqual(component.state.items.value, []);
});

test("原商城查询变化按允许分类刷新，异常分类不能请求其他资源", async () => {
  const component = harness();
  await component.mounted[0]();
  component.props.initialKind = "events"; await nextTick(); await nextTick();
  assert.equal(component.state.kind.value, "events");
  component.props.initialKind = "orders"; await nextTick(); await nextTick();
  assert.equal(component.state.kind.value, "products");
  assert.deepEqual(component.calls, ["products", "events", "products"]);
});

test("复用组件和三个薄入口的 Vue 脚本与模板可编译", () => {
  const { parse, compileScript, compileTemplate } = require("@vue/compiler-sfc");
  for (const [filename, text] of [["ShopCatalog.vue", source], ...["shop", "maintenance", "mall"].map(name => [`${name}.vue`, page(name).source])]) {
    const { descriptor, errors } = parse(text, { filename });
    assert.deepEqual(errors, []);
    const bindings = compileScript(descriptor, { id: filename }).bindings;
    const result = compileTemplate({ source: descriptor.template.content, filename, id: filename, compilerOptions: { bindingMetadata: bindings } });
    assert.deepEqual(result.errors, []);
  }
});
