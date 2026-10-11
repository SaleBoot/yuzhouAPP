export const ORDER_TYPES = {
  boat_rental: "租船",
  boat_purchase: "买船",
  boat_upgrade: "升级",
  maintenance: "保养维修",
  mall: "周边商品",
  event: "活动",
};
export const STATES = {
  awaiting_quote: "待报价",
  awaiting_payment: "待支付",
  paid: "已支付",
  fulfilling: "履约中",
  partially_refunded: "部分退款",
  draft: "待完善",
  pending_quote: "待报价",
  quoted: "待确认报价",
  pending_payment: "待支付",
  deposit_paid: "定金已支付",
  pending_contract: "待上传合同",
  contract_uploaded: "待确认合同",
  pending_signature: "待确认合同",
  signed: "合同已确认",
  pending_balance: "待付尾款",
  in_progress: "进行中",
  fulfilled: "已交付",
  completed: "已完成",
  cancelled: "已取消",
  refunded: "已退款",
  pending_refund: "退款处理中",
};
export function money(cents = 0) {
  return (Number(cents) / 100).toLocaleString("zh-CN", {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  });
}
export function state(value) {
  return STATES[value] || value || "待处理";
}
export function displayTitle(value) {
  return typeof value === "string"
    ? value.replace(/（演示(?:版)?）$/u, "").replace(/ · 演示1号$/u, " · 1号")
    : value;
}
export function cents(value) {
  if (!/^\d+(\.\d{1,2})?$/.test(String(value)))
    throw new Error("请输入有效金额，最多两位小数");
  const [whole, fraction = ""] = String(value).split(".");
  const amount = Number(whole) * 100 + Number(fraction.padEnd(2, "0"));
  if (!Number.isSafeInteger(amount)) throw new Error("金额超出可处理范围");
  return amount;
}
export function dayCount(start, end) {
  const count = Math.round(
    (Date.parse(`${end}T00:00:00Z`) - Date.parse(`${start}T00:00:00Z`)) /
      86400000,
  );
  if (!Number.isFinite(count) || count < 1)
    throw new Error("结束日期应晚于开始日期，最少租赁一天");
  return count;
}
export const today = () => {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
};
export function validateOrder(form) {
  if (!Object.prototype.hasOwnProperty.call(ORDER_TYPES, form.type))
    throw new Error("请选择订单类型");
  if (!form.resource_id) throw new Error("请选择船艇或商品");
  if (form.type === "boat_rental") {
    if (form.start_date < today()) throw new Error("预约日期不能早于今天");
    dayCount(form.start_date, form.end_date);
  }
  if (!Number.isInteger(Number(form.quantity)) || Number(form.quantity) < 1)
    throw new Error("数量应为正整数");
  return { ...form, quantity: Number(form.quantity) };
}
function dateValue(value) {
  return new Date(
    typeof value === "number" || /^\d+$/.test(String(value))
      ? Number(value) * (Number(value) < 1e12 ? 1000 : 1)
      // SQL datetime strings are not a supported Date format on iOS/WebKit.
      : typeof value === "string" ? value.replace(/^(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2})/, "$1T$2") : value,
  );
}
export function dateTime(value, full = true) {
  if (!value) return "—";
  const date = dateValue(value);
  if (Number.isNaN(date.getTime())) return String(value);
  const day = `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, "0")}-${String(date.getDate()).padStart(2, "0")}`;
  return full
    ? `${day} ${String(date.getHours()).padStart(2, "0")}:${String(date.getMinutes()).padStart(2, "0")}`
    : day;
}
export function memberActive(user) {
  return (
    user?.is_member ??
    Boolean(
      user?.member_until &&
      dateValue(user.member_until) > new Date(),
    )
  );
}
export function eventEligibility(user, now = Math.floor(Date.now() / 1000)) {
  const verified = user?.identity_status === "verified";
  const member = Number(user?.member_until || 0) > now;
  return { verified, member, eligible: verified && member };
}
