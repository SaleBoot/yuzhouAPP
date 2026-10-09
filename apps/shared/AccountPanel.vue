<template>
  <view class="page"
    ><view class="title">{{ labels[kind] }}</view
    ><view class="subtitle">{{
      hints[kind] || "与你的航海生活有关的记录"
    }}</view
    ><view v-if="error" class="error"
      >{{ error }} <text @click="load">重试</text></view
    ><view v-if="creatable" class="card section"
      ><view class="card-head">{{
        kind === "feedback"
          ? "提交反馈"
          : kind === "reviews"
            ? "评价交付"
            : "添加记录"
      }}</view
      ><view class="formline"
        ><text class="form-label">{{
          kind === "boat-records" ? "船艇名称" : "标题"
        }}</text
        ><input v-model="form.title" placeholder="填写标题" /></view
      ><view v-if="kind === 'reviews'" class="formline"
        ><text class="form-label">已完成订单</text
        ><picker
          :range="orderLabels"
          :value="orders.findIndex((order) => order.id === form.order_id) + 1"
          :disabled="loading || ordersLoading || busy || !orders.length || !!ordersError || !!error"
          @change="selectOrder"
          ><view class="picker-value">{{
            selectedOrder ? orderLabel(selectedOrder) : "选择要评价的订单 ▾"
          }}</view></picker
        ><view v-if="ordersError" class="error">{{ ordersError }} <text class="link" @click="loadOrders">重试</text></view
        ><view v-else-if="loading || ordersLoading" class="small muted">正在加载已完成订单…</view
        ><view v-else-if="!orders.length && !error" class="small muted">{{ moreOrders ? "当前页暂无可评价订单，可加载更多" : "暂无待评价的已完成订单" }}</view
        ><button v-if="moreOrders && !ordersError" class="secondary section" :loading="ordersLoading" :disabled="ordersLoading || loading || busy" @click="loadOrders">加载更多已完成订单</button></view
      ><view v-if="kind === 'reminders'" class="formline"
        ><text class="form-label">提醒日期</text
        ><picker
          mode="date"
          :value="form.date"
          @change="form.date = $event.detail.value"
          ><view class="picker-value">{{
            form.date || "选择日期"
          }}</view></picker
        ></view
      ><view v-if="kind === 'boat-records'" class="formline"
        ><text class="form-label">船艇编号</text
        ><input v-model="form.serial_number" placeholder="填写船艇编号" /></view
      ><view v-if="kind === 'reviews'" class="formline"
        ><text class="form-label">评分（1至5）</text
        ><picker
          :range="[1, 2, 3, 4, 5]"
          :value="form.rating - 1"
          @change="form.rating = Number($event.detail.value) + 1"
          ><view class="picker-value">{{ form.rating }} 星 ▾</view></picker
        ></view
      ><view class="formline">
        <textarea v-model="form.content" placeholder="详细说明" /></view
      ><button class="primary" :loading="busy" :disabled="busy || (kind === 'reviews' && (!selectedOrder || loading || ordersLoading || !!ordersError || !!error))" @click="create">
        提交
      </button></view
    ><view
      v-for="item in items"
      :key="item.id"
      class="card section"
      @click="open(item)"
      ><view class="row spread"
        ><text class="card-head">{{
          item.title ||
          item.resource?.title ||
          item.event_title ||
          item.payload?.title ||
          labels[kind]
        }}</text
        ><text class="small muted">{{
          recordStates[item.status] || item.status || ""
        }}</text></view
      ><view class="article small">{{
        item.payload?.content ||
        item.content ||
        item.message ||
        item.payload?.notes ||
        ""
      }}</view
      ><view
        v-if="kind === 'feedback' && item.payload?.reply"
        class="notice section"
        ><view class="small">平台回复</view
        ><view class="article">{{ item.payload.reply }}</view
        ><view class="small muted">{{
          dateTime(item.payload.replied_at)
        }}</view></view
      >
      <view class="small muted section">{{
        item.payload?.due_date || dateTime(item.created_at)
      }}</view
      ><TicketCode
        v-if="
          kind === 'enrollments' &&
          ['active', 'checked_in'].includes(item.status)
        "
        :code="item.ticket_code || item.code"
      /><view v-if="kind === 'enrollments' && ['active', 'checked_in'].includes(item.status)" class="notice section"
        >报名码 {{ item.ticket_code || item.code || "待生成" }}</view
      ><EnrollmentCancel v-if="kind === 'enrollments'" :enrollment="item" @cancelled="load" />
      <button v-if="kind === 'enrollments' && item.order_id" class="secondary section" @click.stop="go('order', { id: item.order_id })">查看活动订单</button>
      <view v-if="item.resource?.payload?.image_urls?.[0]" class="section"
        ><image
          :src="media(item.resource.payload.image_urls[0])"
          class="cover"
          mode="aspectFill" /></view
      ><button
        v-if="kind === 'notifications' && !item.read_at"
        class="secondary section"
        @click.stop="read(item.id)"
      >
        标记已读
      </button><button v-if="kind === 'favorites'" class="secondary section" :loading="removing === item.id" :disabled="removing !== null" @click.stop="unfavorite(item)">取消收藏</button></view
    ><view v-if="!items.length && !error" class="empty">{{
      loading ? "正在加载…" : "暂无记录"
    }}</view></view
  >
</template>
<script setup>
import TicketCode from "./TicketCode.vue";
import EnrollmentCancel from "./EnrollmentCancel.vue";
import { ref, computed, reactive, watch } from "vue";
import { request, post, go, media, notify, requireLogin, session } from "./api.js";
import { dateTime, ORDER_TYPES } from "./domain.js";
const props = defineProps({ kind: String });
const labels = {
  favorites: "我的收藏",
  footprints: "浏览足迹",
  "boat-records": "我的船艇",
  reminders: "保养提醒",
  feedback: "意见反馈",
  reviews: "交付评价",
  notifications: "通知消息",
  enrollments: "我的活动",
};
const hints = {
  notifications: "站内消息由服务端保存，系统推送待配置",
  reminders: "添加需要跟进的保养事项",
  reviews: "评价须关联你的已完成订单",
};
const recordStates = {
  handled: "已回复",
  open: "处理中",
  pending: "待处理",
  active: "有效",
  completed: "已完成",
  cancelled: "已取消",
  checked_in: "已签到",
};
const items = ref([]),
  error = ref(""),
  loading = ref(false),
  busy = ref(false),
  removing = ref(null),
  orders = ref([]),
  ordersPage = ref(0),
  ordersTotal = ref(0),
  ordersLoading = ref(false),
  ordersError = ref(""),
  form = reactive({
    title: "",
    content: "",
    date: "",
    serial_number: "",
    order_id: null,
    rating: 5,
  });
const creatable = computed(() =>
  ["boat-records", "reminders", "feedback", "reviews"].includes(props.kind),
);
const selectedOrder = computed(() => orders.value.find((order) => order.id === form.order_id));
const orderLabels = computed(() => ["请选择已完成订单", ...orders.value.map(orderLabel)]);
const moreOrders = computed(() => ordersPage.value * 100 < ordersTotal.value);
let loadVersion = 0;
watch(() => props.kind, load, { immediate: true });
async function load() {
  if (!props.kind || !requireLogin()) return;
  const kind = props.kind, version = ++loadVersion;
  loading.value = true;
  error.value = "";
  orders.value = [];
  ordersPage.value = ordersTotal.value = 0;
  ordersLoading.value = false;
  ordersError.value = "";
  form.order_id = null;
  try {
    let r, page = 1, records = [];
    do {
      r = await request(`/${kind}?page_size=100${kind === "reviews" ? `&page=${page}` : ""}`);
      if (version !== loadVersion) return;
      records = records.concat(r.items || r || []);
      page++;
    } while (kind === "reviews" && (page - 1) * 100 < r.total);
    items.value = records;
    if (kind === "reviews") await loadOrders();
  } catch (e) {
    if (version === loadVersion) error.value = e.message;
  } finally {
    if (version === loadVersion) loading.value = false;
  }
}
function orderLabel(order) {
  return `${order.order_no} · ${order.items?.map((item) => item.title).filter(Boolean).join("、") || ORDER_TYPES[order.type] || "订单"}`;
}
function selectOrder(event) {
  if (busy.value || loading.value || ordersLoading.value || ordersError.value || error.value) return;
  const order = orders.value[Number(event.detail.value) - 1];
  form.order_id = order?.id || null;
}
async function loadOrders() {
  if (props.kind !== "reviews" || ordersLoading.value) return;
  const version = loadVersion, page = ordersPage.value + 1;
  ordersLoading.value = true;
  ordersError.value = "";
  try {
    const r = await request(`/orders?status=completed&page_size=100&page=${page}`);
    if (version !== loadVersion) return;
    if (!Array.isArray(r.items) || r.page !== page || r.page_size !== 100 || !Number.isInteger(r.total) || r.total < 0)
      throw new Error("订单列表响应不完整，请重试");
    const reviewed = new Set(items.value.map((item) => Number(item.order_id)));
    const seen = new Set(orders.value.map((order) => order.id));
    orders.value.push(...r.items.filter((order) => order.status === "completed" && order.customer_id === Number(session()?.user?.id) && !reviewed.has(order.id) && !seen.has(order.id)));
    ordersPage.value = r.page;
    ordersTotal.value = r.total;
  } catch (e) {
    if (version === loadVersion) ordersError.value = e.message;
  } finally {
    if (version === loadVersion) ordersLoading.value = false;
  }
}
async function create() {
  if (busy.value) return;
  if (!form.title.trim()) return notify(new Error("请填写标题"));
  if (props.kind === "reviews" && (!selectedOrder.value || loading.value || ordersLoading.value || ordersError.value || error.value))
    return notify(new Error("请选择已完成订单，加载失败时请重试"));
  busy.value = true;
  try {
    await post(`/${props.kind}`, {
      title: form.title,
      order_id: form.order_id ? Number(form.order_id) : undefined,
      payload: {
        content: form.content,
        notes: form.content,
        due_date: form.date,
        serial_number: form.serial_number,
        rating: form.rating,
      },
    });
    form.title = "";
    form.content = "";
    form.order_id = null;
    await load();
    uni.showToast({ title: "已保存", icon: "success" });
  } catch (e) {
    if (props.kind === "reviews" && e.message === "此订单已评价。") {
      orders.value = orders.value.filter((order) => order.id !== form.order_id);
      form.order_id = null;
    }
    notify(e);
  } finally {
    busy.value = false;
  }
}
async function unfavorite(item) {
  if (props.kind !== "favorites" || removing.value !== null) return;
  removing.value = item.id;
  try {
    const result = await request(`/favorites/${encodeURIComponent(item.id)}`, { method: "DELETE" });
    if (result?.deleted !== true) throw new Error("未能确认取消收藏，请刷新后重试");
    items.value = items.value.filter((record) => record.id !== item.id);
    await load();
    uni.showToast({ title: "已取消收藏", icon: "success" });
  } catch (e) {
    notify(e);
  } finally {
    removing.value = null;
  }
}
function open(item) {
  if (["favorites", "footprints"].includes(props.kind))
    go("boat", { id: item.resource_id || item.resource?.id });
  if (props.kind === "enrollments" && item.event_id)
    go("event", { id: item.event_id });
}
async function read(id) {
  try {
    await post(`/notifications/${id}/read`);
    await load();
  } catch (e) {
    notify(e);
  }
}
</script>
