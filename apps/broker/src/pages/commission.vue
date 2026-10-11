<template>
  <view class="page"
    ><LoginPrompt /><view class="title">{{ t("佣金与结算") }}</view
    ><view class="subtitle">{{ t("平台确认利润后生成，金额以账本为准") }}</view
    ><view class="hero"
      ><text class="hero-kicker">{{ t("可结算金额") }}</text
      ><view class="title section"
        >¥{{
          money(summary.available_cents || summary.withdrawable_cents || 0)
        }}</view
      ><view class="small section"
        >{{ t("分红规则由平台明确配置，无默认比例。") }}</view
      ></view
    ><view class="card section"
      ><view class="card-head">{{ t("申请提现") }}</view
      ><view class="formline"
        ><text class="form-label">{{ t("金额（元）") }}</text
        ><input
          v-model="amount"
          :disabled="busy"
          type="digit"
          :placeholder="t('填写可用余额范围内的金额')" /></view
      ><button
        class="primary"
        :disabled="busy"
        :loading="busy"
        @click="withdraw"
      > {{ t("提交申请") }}</button
      ><view class="notice small section"
        >{{ t("申请由平台审核，外部打款以实际处理结果为准。") }}</view
      ></view
    ><view class="card section"
      ><view class="card-head">{{ t("结算账本") }}</view
      ><view v-for="entry in entries" :key="entry.id" class="list-item"
        ><view
          ><view>{{ entry.title || t("订单 {id}", { id: entry.order_id }) }}</view
          ><view class="small muted"
            >{{ dateTime(entry.created_at) }} · {{ entry.state }}</view
          ></view
        ><text class="price">¥{{ money(entry.amount_cents) }}</text></view
      ><view v-if="!entries.length" class="empty">{{ t("暂无已确认结算") }}</view></view
    ><view class="card section"
      ><view class="card-head">{{ t("提现记录") }}</view
      ><view v-for="w in withdrawals" :key="w.id" class="list-item"
        ><view
          ><view>¥{{ money(w.amount_cents) }}</view
          ><text class="muted small">{{
            w.notes || dateTime(w.created_at)
          }}</text></view
        ><text class="badge">{{ w.status }}</text></view
      ><view v-if="!withdrawals.length" class="empty">{{ t("暂无提现申请") }}</view></view
    ><view v-if="error" class="error"
      >{{ t(error) }} <text @click="load">{{ t("重试") }}</text></view
    ></view
  >
</template>
<script setup>
import LoginPrompt from "@shared/LoginPrompt.vue";
import { t } from "@shared/i18n.js";
import { ref } from "vue";
import { onShow } from "@dcloudio/uni-app";
import { request, post, notify, requireLogin, session, uuid } from "@shared/api.js";
import { money, cents, dateTime } from "@shared/domain.js";
const entries = ref([]),
  withdrawals = ref([]),
  summary = ref({}),
  amount = ref(""),
  busy = ref(false),
  error = ref("");
async function load() {
  if (!requireLogin(false)) { entries.value = []; withdrawals.value = []; summary.value = {}; return; }
  try {
    const [l, w] = await Promise.all([
      request("/ledger"),
      request("/withdrawals"),
    ]);
    entries.value = l.items || [];
    summary.value = l.balance || l.summary || l;
    withdrawals.value = w.items || [];
    error.value = "";
  } catch (e) {
    error.value = e.message;
  }
}
async function withdraw() {
  if (busy.value) return;
  busy.value = true;
  try {
    const amountCents = cents(amount.value);
    const userId = session()?.user?.id;
    const storageKey = `yuzhou_broker_withdrawal_pending_${userId}`;
    const previous = uni.getStorageSync(storageKey);
    const key = previous?.amount_cents === amountCents ? previous.idempotency_key : uuid();
    uni.setStorageSync(storageKey, { amount_cents: amountCents, idempotency_key: key });
    await post("/withdrawals", { amount_cents: amountCents, idempotency_key: key });
    uni.removeStorageSync(storageKey);
    amount.value = "";
    await load();
    uni.showToast({ title: t("申请已提交"), icon: "success" });
  } catch (e) {
    notify(e);
  } finally {
    busy.value = false;
  }
}
onShow(load);
</script>
