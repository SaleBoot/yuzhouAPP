import test from "node:test";
import assert from "node:assert/strict";
import { cents, dayCount, validateOrder, eventEligibility } from "./domain.js";
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
