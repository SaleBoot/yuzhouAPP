<template>
  <view
    ><LoginPrompt /><view v-if="error" class="error"
      >{{ t(error) }} <text class="link" @click="load">{{ t("重试") }}</text></view
    ><view class="chips"
      ><text
        v-for="s in filters"
        :key="s.value"
        :class="['chip', { active: status === s.value }]"
        @click="status = s.value"
        >{{ t(s.label) }}</text
      ></view
    ><view v-if="!filtered.length && !error" class="empty">{{
      t(loading ? "订单加载中…" : "暂无相关订单")
    }}</view
    ><view
      v-for="o in filtered"
      :key="o.id"
      class="card section order-card"
      @click="go('order', { id: o.id })"
      ><view class="row spread"
        ><text class="badge">{{ t(ORDER_TYPES[o.type]) }}</text
        ><text class="gold">{{ state(o.status) }}</text></view
      ><view class="section">{{ o.order_no }}</view
      ><view class="row spread section"
        ><text class="muted small">{{
          o.customer_name || dateTime(o.created_at)
        }}</text
        ><text class="price">¥{{ money(o.amount_cents) }}</text></view
      ><view class="muted small">{{
        o.notes || t("查看订单与合同详情")
      }}</view></view
    ></view
  >
</template>
<script setup>
import { t } from "./i18n.js";
import LoginPrompt from "./LoginPrompt.vue";
import { ref, computed, onMounted } from "vue";
import { request, go, requireLogin } from "./api.js";
import { ORDER_TYPES, money, state, dateTime } from "./domain.js";
const items = ref([]),
  error = ref(""),
  loading = ref(false),
  status = ref("");
const filters = [
  { value: "", label: "全部" },
  { value: "awaiting_quote", label: "待报价" },
  { value: "awaiting_payment", label: "待支付" },
  { value: "paid", label: "待履约" },
  { value: "completed", label: "已完成" },
];
const filtered = computed(() =>
  items.value.filter((o) => !status.value || o.status === status.value),
);
let loadVersion = 0;
async function load() {
  const version = ++loadVersion;
  if (!requireLogin(false)) {
    items.value = [];
    error.value = "";
    loading.value = false;
    return;
  }
  loading.value = true;
  error.value = "";
  try {
    const r = await request("/orders?page_size=100");
    if (version !== loadVersion) return;
    items.value = r.items || [];
  } catch (e) {
    if (version === loadVersion) error.value = e.message;
  } finally {
    if (version === loadVersion) loading.value = false;
  }
}
onMounted(load);
defineExpose({ load });
</script>
<style scoped>
.order-card {
  overflow-wrap: anywhere;
}
</style>
