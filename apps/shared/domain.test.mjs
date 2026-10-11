import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { t } from "./i18n.js";
import { runInNewContext } from "node:vm";
import { cents, dayCount, validateOrder, eventEligibility, memberActive } from "./domain.js";
test("金额按分解析且不接受负数和额外小数", () => {
  assert.equal(cents("0.29"), 29);
  assert.equal(cents("1680"), 168000);
  assert.throws(() => cents("-1"));
  assert.throws(() => cents("1.001"));
});
test("活动须资料审核通过且会员未过期，过期秒数边界不放行", () => {
  assert.equal(eventEligibility({ identity_status: "verified", member_until: 101 }, 100).eligible, true);
  assert.equal(eventEligibility({ identity_status: "verified", member_until: 100 }, 100).eligible, false);
  assert.equal(eventEligibility({ identity_status: "unverified", member_until: 101 }, 100).eligible, false);
  assert.equal(eventEligibility(null, 100).eligible, false);
});
test("租赁按日期跨度且至少一天", () => {
  assert.equal(dayCount("2028-02-28", "2028-03-01"), 2);
  assert.throws(() => dayCount("2028-03-01", "2028-03-01"));
  assert.throws(() =>
    validateOrder({
      type: "boat_rental",
      resource_id: "1",
      boat_ids: [],
      quantity: 1,
    }),
  );
});

test("iOS 严格日期解析器可格式化后端活动和优惠券日期", () => {
  class WebKitDate extends Date {
    constructor(...args) {
      if (typeof args[0] === "string" && /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}/.test(args[0])) args[0] = NaN;
      super(...args);
    }
  }
  const source = readFileSync(new URL("./domain.js", import.meta.url), "utf8").replace(/^import .*\n/gm, "").replace(/^export /gm, "");
  const { dateTime } = runInNewContext(`${source}; ({ dateTime });`, { t, Date: WebKitDate });
  assert.equal(dateTime("2026-10-11 09:35:42"), "2026-10-11 09:35");
  assert.equal(dateTime("2026-10-11 09:35:42", false), "2026-10-11");
  assert.equal(dateTime("invalid"), "invalid");
  assert.equal(dateTime(null), "—");
});

test("会员到期秒数无需经由日期字符串解析或丢失精度", () => {
  class FixedDate extends Date {
    constructor(...args) { super(...(args.length ? args : [Date.UTC(2026, 9, 11, 0, 0, 30)])); }
  }
  const source = readFileSync(new URL("./domain.js", import.meta.url), "utf8").replace(/^import .*\n/gm, "").replace(/^export /gm, "");
  const { memberActive: active } = runInNewContext(`${source}; ({ memberActive });`, { t, Date: FixedDate });
  const now = Date.UTC(2026, 9, 11, 0, 0, 30);
  assert.equal(active({ member_until: now / 1000 + 15 }), true);
  assert.equal(active({ member_until: String(now / 1000 + 15) }), true);
  assert.equal(active({ member_until: now + 15000 }), true);
  assert.equal(active({ member_until: now / 1000 }), false);
  assert.equal(active({ member_until: now / 1000 - 1 }), false);
  assert.equal(active({ member_until: "invalid" }), false);
  assert.equal(active({ is_member: false, member_until: now / 1000 + 15 }), false);
  assert.equal(memberActive(null), false);
});
