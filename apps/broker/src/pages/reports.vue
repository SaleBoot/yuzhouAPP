<template>
  <view class="page"><LoginPrompt />
    <view class="title">{{ t("销售报表") }}</view
    ><view class="subtitle">{{ t("服务端统计本人负责的业务与结算记录") }}</view>
    <view class="card section"
      ><view class="row"
        ><picker
          mode="date"
          :value="fromDate"
          @change="fromDate = $event.detail.value"
          ><view class="picker-value"
            >{{ fromDate || t("开始日期") }} ▾</view
          ></picker
        ><text class="small muted">{{ t("至") }}</text
        ><picker
          mode="date"
          :value="toDate"
          @change="toDate = $event.detail.value"
          ><view class="picker-value"
            >{{ toDate || t("结束日期") }} ▾</view
          ></picker
        ></view
      ><view class="actions section"
        ><button class="primary" :disabled="loading" @click="load"> {{ t("查询统计") }}</button
        ><button class="secondary" @click="reset">{{ t("全部日期") }}</button></view
      ></view
    >
    <view v-if="error" class="error">{{ t(error) }}</view>
    <template v-if="summary">
      <view class="grid section"
        ><view v-for="kpi in kpis" :key="kpi.label" class="card"
          ><view class="muted small">{{ t(kpi.label) }}</view
          ><view class="kpi-value section">{{ kpi.value }}</view></view
        ></view
      >
      <view class="card section"
        ><view class="card-head">{{ t("订单状态") }}</view
        ><view
          v-for="(count, status) in summary.status_counts"
          :key="status"
          class="list-item"
          ><text>{{ state(status) }}</text
          ><text>{{ count }}</text></view
        ><view
          v-if="!Object.keys(summary.status_counts || {}).length"
          class="empty"
          >{{ t("此日期范围暂无订单") }}</view
        ></view
      >
    </template>
    <view class="card section"
      ><view class="card-head">{{ t("分红记录") }}</view
      ><view class="small muted section"
        >{{ t("导出权限范围内、按记录日期筛选的实际分红流水。") }}</view
      ><button
        class="secondary section"
        :disabled="exporting"
        :loading="exporting"
        @click="download"
      > {{ t("导出 Excel") }} </button></view
    >
  </view>
</template>
<script setup>
import LoginPrompt from "@shared/LoginPrompt.vue";
import { t } from "@shared/i18n.js";
import { ref, computed } from "vue";
import { onShow } from "@dcloudio/uni-app";
import {
  request,
  query,
  exportFile,
  notify,
  requireLogin,
} from "@shared/api.js";
import { money, state } from "@shared/domain.js";
const fromDate = ref(""),
  toDate = ref(""),
  summary = ref(null),
  loading = ref(false),
  exporting = ref(false),
  error = ref("");
const kpis = computed(() =>
  summary.value
    ? [
        { label: "订单数量", value: summary.value.order_count },
        {
          label: "订单净实收",
          value: `¥${money(summary.value.net_paid_cents)}`,
        },
        { label: "我的客户总数", value: summary.value.customer_count },
        { label: "带看预约", value: summary.value.appointment_count },
        {
          label: "实际佣金",
          value: `¥${money(summary.value.commission_cents)}`,
        },
        {
          label: "冻结佣金",
          value: `¥${money(summary.value.frozen_commission_cents)}`,
        },
      ]
    : [],
);
function filters() {
  if (fromDate.value && toDate.value && fromDate.value > toDate.value)
    throw new Error(t("开始日期不能晚于结束日期"));
  return query({ from_date: fromDate.value, to_date: toDate.value });
}
async function load() {
  if (!requireLogin(false) || loading.value) return;
  loading.value = true;
  error.value = "";
  try {
    summary.value = await request(`/reports/summary?${filters()}`);
  } catch (e) {
    error.value = e.message;
  } finally {
    loading.value = false;
  }
}
function reset() {
  fromDate.value = "";
  toDate.value = "";
  load();
}
async function download() {
  if (exporting.value) return;
  exporting.value = true;
  try {
    await exportFile(
      `/reports/dividends.xlsx?${filters()}`,
      t("驭舟分红报表.xlsx"),
    );
  } catch (e) {
    notify(e);
  } finally {
    exporting.value = false;
  }
}
onShow(load);
</script>
