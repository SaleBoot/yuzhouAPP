<template>
  <view class="page"
    ><view class="title">预约看船</view
    ><view class="subtitle">与销售约定时间，感受真实的航海体验</view
    ><view class="card section"
      ><view v-if="broker" class="formline"
        ><text class="form-label">预约客户</text
        ><picker
          :range="customers.map((c) => c.name)"
          :value="customerIndex"
          @change="
            form.customer_id = customers[Number($event.detail.value)]?.user_id
          "
          ><view class="picker-value"
            >{{ customers[customerIndex]?.name || "请选择负责客户" }} ▾</view
          ></picker
        ></view
      ><view class="formline"
        ><text class="form-label">看船型号</text
        ><picker
          :range="boats.map((b) => b.name)"
          :value="boatIndex"
          @change="form.resource_id = boats[Number($event.detail.value)]?.id"
          ><view class="picker-value"
            >{{ boats[boatIndex]?.name || "请选择船艇型号" }} ▾</view
          ></picker
        ></view
      ><view class="row"
        ><view class="formline flex"
          ><text class="form-label">日期</text
          ><picker
            mode="date"
            :value="form.date"
            :start="today()"
            @change="form.date = $event.detail.value"
            ><view class="picker-value">{{ form.date }} ▾</view></picker
          ></view
        ><view class="formline flex"
          ><text class="form-label">时间</text
          ><picker
            mode="time"
            :value="form.time"
            @change="form.time = $event.detail.value"
            ><view class="picker-value">{{ form.time }} ▾</view></picker
          ></view
        ></view
      ><view class="formline">
        <textarea v-model="form.notes" placeholder="看船地点和需求备注" /></view
      ><button class="primary" :loading="busy" :disabled="busy" @click="create">
        提交预约
      </button></view
    ><view class="card-head section">我的预约</view
    ><view v-for="item in items" :key="item.id" class="card section"
      ><view class="row spread"
        ><text>{{
          item.resource_title ||
          boats.find((b) => Number(b.id) === Number(item.resource_id))?.name ||
          "船艇预约"
        }}</text
        ><text class="badge">{{
          states[item.status] || item.status
        }}</text></view
      ><view class="section">{{ dateTime(item.scheduled_at) }}</view
      ><view class="small muted">{{ item.notes }}</view
      ><view class="actions section"
        ><button
          v-if="broker && item.status === 'pending'"
          class="secondary"
          @click="update(item.id, 'confirmed')"
        >
          确认时间</button
        ><button
          v-if="broker && item.status === 'confirmed'"
          class="primary"
          @click="update(item.id, 'completed')"
        >
          完成接待</button
        ><button
          v-if="!['cancelled', 'completed'].includes(item.status)"
          class="secondary"
          @click="update(item.id, 'cancelled')"
        >
          取消预约
        </button></view
      ></view
    ><view v-if="!items.length && !error" class="empty">暂无预约记录</view
    ><view v-if="error" class="error">{{ error }}</view></view
  >
</template>
<script setup>
import { ref, computed, reactive, onMounted, watch } from "vue";
import { request, list, post, patch, notify, requireLogin } from "./api.js";
import { today, dateTime } from "./domain.js";
const props = defineProps({
  resourceId: [String, Number],
  customerId: [String, Number],
  broker: Boolean,
});
const states = {
  pending: "待确认",
  confirmed: "已确认",
  completed: "已完成",
  cancelled: "已取消",
};
const form = reactive({
    resource_id: props.resourceId || "",
    customer_id: props.customerId || "",
    date: today(),
    time: "10:00",
    notes: "",
  }),
  items = ref([]),
  boats = ref([]),
  customers = ref([]),
  busy = ref(false),
  error = ref("");
const boatIndex = computed(() =>
  Math.max(
    0,
    boats.value.findIndex((b) => Number(b.id) === Number(form.resource_id)),
  ),
);
const customerIndex = computed(() =>
  Math.max(
    0,
    customers.value.findIndex(
      (c) => Number(c.user_id) === Number(form.customer_id),
    ),
  ),
);
watch(
  () => props.resourceId,
  (v) => (form.resource_id = v || ""),
);
watch(
  () => props.customerId,
  (v) => (form.customer_id = v || ""),
);
async function load() {
  if (!requireLogin()) return;
  try {
    const [r, b, c] = await Promise.all([
      request("/appointments"),
      list("boat_types"),
      props.broker
        ? request("/customers?page_size=100")
        : Promise.resolve({ items: [] }),
    ]);
    items.value = r.items || [];
    boats.value = b;
    customers.value = c.items || [];
    if (!form.resource_id && b[0]) form.resource_id = b[0].id;
    if (props.broker && !form.customer_id && customers.value[0])
      form.customer_id = customers.value[0].user_id;
    error.value = "";
  } catch (e) {
    error.value = e.message;
  }
}
async function create() {
  if (!form.resource_id) return notify(new Error("请选择船艇型号"));
  if (props.broker && !form.customer_id)
    return notify(new Error("请选择负责客户"));
  busy.value = true;
  try {
    await post("/appointments", {
      resource_id: Number(form.resource_id),
      ...(props.broker && form.customer_id
        ? { customer_id: Number(form.customer_id) }
        : {}),
      scheduled_at: `${form.date} ${form.time}:00`,
      notes: form.notes,
    });
    await load();
    uni.showToast({ title: "预约已提交", icon: "success" });
  } catch (e) {
    notify(e);
  } finally {
    busy.value = false;
  }
}
async function update(id, status) {
  try {
    await patch(`/appointments/${id}`, { status });
    await load();
  } catch (e) {
    notify(e);
  }
}
onMounted(load);
</script>
<style scoped>
.flex {
  flex: 1;
}
</style>
