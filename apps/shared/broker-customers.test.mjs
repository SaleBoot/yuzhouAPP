import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { createRequire } from "node:module";
import { runInNewContext } from "node:vm";

const { ref, reactive, computed } = createRequire(new URL("../broker/package.json", import.meta.url))("vue");
const source = readFileSync(new URL("../broker/src/pages/customers.vue", import.meta.url), "utf8")
  .match(/<script setup>([\s\S]*?)<\/script>/)[1].replace(/^import[\s\S]*?;\n/gm, "");
const api = readFileSync(new URL("../../backend/extend/yuzhou/Api.php", import.meta.url), "utf8");
const accepted = JSON.parse(api.match(/Validation::choice\(\$body\['stage'\] \?\? \$row\['stage'\], (\[[^\]]+\])/)[1].replaceAll("'", '"'));

function page(customer) {
  const patches = [], errors = [];
  const state = runInNewContext(`${source}; ({ stages, stageOptions, edit, save, notes, stageIndex });`, {
    ref, reactive, computed, onShow() {}, requireLogin: () => true, t: value => value,
    request: async path => ({ items: path.startsWith("/customers?") ? [customer] : [] }),
    patch: async (path, body) => {
      if (body.stage !== undefined) assert.ok(accepted.includes(body.stage), `API rejects ${body.stage}`);
      patches.push({ path, body: { ...body } });
    },
    notify: error => errors.push(error),
  });
  return { ...state, patches, errors };
}

test("broker quotation submits the stage accepted by the current API", async () => {
  const p = page({ id: 7, stage: "new" });
  p.edit({ id: 7, stage: "new" });
  p.stageIndex.value = p.stageOptions.findIndex(option => option.label === "报价中");
  await p.save({ id: 7 });
  assert.equal(p.errors.length, 0);
  assert.equal(p.patches[0]?.body.stage, "quoted");
});

test("editing notes retains every stage accepted by the API", async () => {
  for (const stage of accepted) {
    const customer = { id: 7, stage, notes: "原备注" }, p = page(customer);
    p.edit(customer);
    p.notes.value = "更新备注";
    await p.save(customer);
    assert.equal(p.errors.length, 0, stage);
    assert.deepEqual(p.patches, [{ path: "/customers/7", body: { notes: "更新备注", stage } }]);
  }
});

test("an unfamiliar future stage is preserved when only notes are edited", async () => {
  const customer = { id: 7, stage: "future_stage" }, p = page(customer);
  p.edit(customer);
  p.notes.value = "仅修改备注";
  await p.save(customer);
  assert.equal(p.errors.length, 0);
  assert.deepEqual(p.patches, [{ path: "/customers/7", body: { notes: "仅修改备注" } }]);
});
