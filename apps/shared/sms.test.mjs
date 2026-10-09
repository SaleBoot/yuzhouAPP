import test from "node:test";
import assert from "node:assert/strict";
import { normalizePhone, smsChallenge, secondsUntil } from "./sms.js";
const phone = "13800000000";
const accepted = { challenge_id: "a".repeat(64), purpose: "login", expires_in: 300, resend_after: 60, delivery_status: "accepted" };
test("短信手机号规范化且拒绝非法号码", () => {
  assert.equal(normalizePhone(" +86" + phone + " "), phone);
  for (const value of ["", "123", "12800000000", phone + "0"]) assert.throws(() => normalizePhone(value));
});
test("短信受理保留challenge、用途、过期和重发时间", async () => {
  const calls = [], state = smsChallenge(async (...args) => { calls.push(args); return accepted; }, () => 1000);
  await state.send(phone, "login");
  assert.deepEqual(calls, [["/auth/sms", { phone, purpose: "login" }]]);
  assert.equal(state.challengeId, accepted.challenge_id);
  assert.equal(state.expiresAt, 301000);
  assert.equal(state.resendAt, 61000);
  assert.match(state.notice, /受理不代表已送达/);
  assert.equal(state.busy, false);
});
test("短信未配置不产生成功状态或冷却", async () => {
  const state = smsChallenge(async () => { throw Object.assign(new Error("not configured"), { code: 50311 }); }, () => 1000);
  await assert.rejects(state.send(phone, "login"), /not configured/);
  assert.equal(state.notice, ""); assert.equal(state.challengeId, ""); assert.equal(state.resendAt, 0); assert.equal(state.busy, false);
});
test("短信未知结果和网络失败保留冷却且不自动重发", async () => {
  for (const code of [50313, undefined]) {
    let calls = 0;
    const state = smsChallenge(async () => { calls++; throw Object.assign(new Error("unknown"), { code }); }, () => 1000);
    await assert.rejects(state.send(phone, "login"));
    await assert.rejects(state.send(phone, "login"), /倒计时/);
    assert.equal(calls, 1); assert.equal(state.challengeId, ""); assert.equal(state.notice, ""); assert.equal(state.resendAt, 61000);
  }
});
test("短信平台明确拒绝仍保留服务端手机号冷却", async () => {
  const state = smsChallenge(async () => { throw Object.assign(new Error("rejected"), { code: 50312 }); }, () => 1000);
  await assert.rejects(state.send(phone, "login"), /rejected/);
  assert.equal(state.resendAt, 61000); assert.equal(state.challengeId, ""); assert.equal(state.notice, "");
  await assert.rejects(state.send(phone, "login"), /倒计时/);
});
test("用途或手机号变化不能使用旧challenge", async () => {
  let calls = 0;
  const state = smsChallenge(async () => { calls++; return accepted; }, () => 1000);
  await state.send(phone, "login");
  await assert.rejects(state.verify("13900000000", "login", "000001"), /重新获取/);
  await assert.rejects(state.verify(phone, "register", "000001"), /重新获取/);
  state.invalidate();
  await assert.rejects(state.verify(phone, "login", "000001"), /重新获取/);
  assert.equal(calls, 1); assert.equal(state.resendAt, 61000);
});
test("过期边界和非法验证码不提交核验", async () => {
  let clock = 1000, calls = 0;
  const state = smsChallenge(async () => { calls++; return accepted; }, () => clock);
  await state.send(phone, "login");
  for (const code of ["1", "12345a", "1234567"]) await assert.rejects(state.verify(phone, "login", code), /6位/);
  clock = state.expiresAt;
  await assert.rejects(state.verify(phone, "login", "000001"), /过期/);
  assert.equal(calls, 1);
  assert.equal(secondsUntil(1001, 1000), 1); assert.equal(secondsUntil(1000, 1000), 0);
});
test("核验保留前导零、成功才清除challenge", async () => {
  const calls = [], result = { token: "offline-test-token", user: { role: "consumer" } };
  const state = smsChallenge(async (path, data) => { calls.push([path, data]); return path === "/auth/sms" ? accepted : result; }, () => 1000);
  await state.send(phone, "login");
  assert.deepEqual(await state.verify(phone, "login", "000001"), result);
  assert.deepEqual(calls[1], ["/auth/sms/verify", { challenge_id: accepted.challenge_id, phone, purpose: "login", code: "000001" }]);
  assert.equal(state.challengeId, "");
});
test("短信核验拒绝保留challenge以便纠正输入", async () => {
  const state = smsChallenge(async (path) => { if (path.endsWith("verify")) throw new Error("invalid code"); return accepted; }, () => 1000);
  await state.send(phone, "login"); await assert.rejects(state.verify(phone, "login", "000001"), /invalid/);
  assert.equal(state.challengeId, accepted.challenge_id); assert.equal(state.busy, false);
});
test("短信注册只发送注册可选字段，绑定要求明确bound", async () => {
  const calls = [], state = smsChallenge(async (path, data) => { calls.push([path, data]); return path === "/auth/sms" ? { ...accepted, purpose: data.purpose } : data.purpose === "bind" ? { bound: true } : { token: "offline-token", user: { role: "consumer" } }; }, () => 1000);
  await state.send(phone, "register"); await state.verify(phone, "register", "000001", { nickname: " 合成昵称 ", invitation_code: " TEST ", role: "broker" });
  assert.equal(calls[1][1].nickname, "合成昵称"); assert.equal(calls[1][1].invitation_code, "TEST"); assert.equal(calls[1][1].role, undefined);
  state.resendAt = 0; await state.send(phone, "bind"); await state.verify(phone, "bind", "000001", { nickname: "ignored" });
  assert.equal(calls[3][1].nickname, undefined);
});
test("不完整受理或核验响应不能显示成功", async () => {
  const broken = smsChallenge(async () => ({ ...accepted, delivery_status: "delivered" }), () => 1000);
  await assert.rejects(broken.send(phone, "login"), /不完整/); assert.equal(broken.challengeId, ""); assert.equal(broken.notice, "");
  const bind = smsChallenge(async (path) => path.endsWith("verify") ? {} : { ...accepted, purpose: "bind" }, () => 1000);
  await bind.send(phone, "bind"); await assert.rejects(bind.verify(phone, "bind", "000001"), /不完整/); assert.equal(bind.challengeId, accepted.challenge_id);
});
test("并行获取验证码只派发一次请求", async () => {
  let release, calls = 0;
  const state = smsChallenge(() => { calls++; return new Promise(resolve => { release = resolve; }); }, () => 1000);
  const first = state.send(phone, "login"); await assert.rejects(state.send(phone, "login"), /处理中/); assert.equal(state.busy, true);
  release(accepted); await first; assert.equal(calls, 1);
});
