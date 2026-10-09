import assert from "node:assert/strict";
import test from "node:test";
import { conversationMessages } from "./chat.js";

function fixture(count, calls = []) {
  const rows = Array.from({ length: count }, (_, i) => ({ id: i + 1, content: `synthetic-${i + 1}` }));
  return async (path) => {
    calls.push(path);
    const after = Number(new URL(path, "https://example.invalid").searchParams.get("after_id"));
    const items = rows.filter((row) => row.id > after);
    return { items: items.slice(0, 100), total: items.length };
  };
}

test("21条消息都读取，明确请求100条和游标", async () => {
  const calls = [];
  const messages = await conversationMessages(fixture(21, calls), 7, [], () => true);
  assert.equal(messages.length, 21);
  assert.equal(messages.at(-1).id, 21);
  assert.deepEqual(calls, ["/conversations/7/messages?after_id=0&page_size=100"]);
});
test("多页用最后实际ID前进，不使用易移动的页码", async () => {
  const calls = [];
  const messages = await conversationMessages(fixture(205, calls), 7, [], () => true);
  assert.equal(messages.length, 205);
  assert.deepEqual(calls.map((path) => Number(new URL(path, "https://example.invalid").searchParams.get("after_id"))), [0, 100, 200]);
});
test("轮询追加新消息，重复轮询不重复既有消息", async () => {
  const existing = await conversationMessages(fixture(21), 7, [], () => true);
  const updates = await conversationMessages(fixture(23), 7, existing, () => true);
  assert.equal(existing.length, 21);
  assert.deepEqual(updates.map((row) => row.id), Array.from({ length: 23 }, (_, i) => i + 1));
  assert.deepEqual(await conversationMessages(fixture(23), 7, updates, () => true), updates);
});
test("会话已切换/暂停时不发请求", async () => {
  let calls = 0;
  assert.equal(await conversationMessages(async () => { calls++; }, 7, [], () => false), null);
  assert.equal(calls, 0);
});
test("在途响应失效不提交旧会话消息或继续请求下一页", async () => {
  let current = true, calls = 0;
  assert.equal(await conversationMessages(async () => {
    calls++; current = false;
    return { items: [{ id: 1 }], total: 1000 };
  }, 7, [], () => current), null);
  assert.equal(calls, 1);
});
test("中途失败不改既有消息，重试从原游标补齐", async () => {
  const existing = [{ id: 1 }];
  await assert.rejects(conversationMessages(async () => { throw new Error("network failure"); }, 7, existing, () => true), /network/);
  assert.deepEqual(existing, [{ id: 1 }]);
  assert.equal((await conversationMessages(fixture(21), 7, existing, () => true)).length, 21);
});
test("错误或不前进的分页拒绝，不死循环或重复消息", async () => {
  for (const id of [1, 0, "invalid", 1.5]) {
    await assert.rejects(conversationMessages(async () => ({ items: [{ id }], total: 10 }), 7, [{ id: 1 }], () => true), /消息分页异常/);
  }
});
