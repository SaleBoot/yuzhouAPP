<template>
  <view
    ><view v-if="error" class="error"
      >{{ error }} <text class="link" @click="load">重试</text></view
    ><view class="chips"
      ><text
        v-for="s in filters"
        :key="s.value"
        :class="['chip', { active: status === s.value }]"
        @click="status = s.value"
        >{{ s.label }}</text
      ></view
    ><view v-if="!filtered.length && !error" class="empty">{{
      loading ? "订单加载中…" : "暂无相关订单"
    }}</view
    ><view
      v-for="o in filtered"
      :key="o.id"
      class="card section"
      @click="go('order', { id: o.id })"
      ><view class="row spread"
        ><text class="badge">{{ ORDER_TYPES[o.type] }}</text
        ><text class="gold">{{ state(o.status) }}</text></view
      ><view class="section">{{ o.order_no }}</view
      ><view class="row spread section"
        ><text class="muted small">{{
          o.customer_name || dateTime(o.created_at)
        }}</text
        ><text class="price">¥{{ money(o.amount_cents) }}</text></view
      ><view class="muted small">{{
        o.notes || "查看订单与合同详情"
      }}</view></view
    ></view
  >
</template>
<script setup>
import { ref, computed, onMounted } from "vue";
import { request, go } from "./api.js";
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
async function load() {
  loading.value = true;
  error.value = "";
  try {
    const r = await request("/orders?page_size=100");
    items.value = r.items || [];
  } catch (e) {
    error.value = e.message;
  } finally {
    loading.value = false;
  }
}
onMounted(load);
defineExpose({ load });
</script>
