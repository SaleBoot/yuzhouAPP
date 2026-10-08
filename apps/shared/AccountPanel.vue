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
        ><text class="form-label">已完成订单ID</text
        ><input
          v-model="form.order_id"
          type="number"
          placeholder="填写订单ID" /></view
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
      ><button class="primary" :loading="busy" :disabled="busy" @click="create">
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
      </button></view
    ><view v-if="!items.length && !error" class="empty">{{
      loading ? "正在加载…" : "暂无记录"
    }}</view></view
  >
</template>
<script setup>
import TicketCode from "./TicketCode.vue";
import EnrollmentCancel from "./EnrollmentCancel.vue";
import { ref, computed, reactive, watch } from "vue";
import { request, post, go, media, notify, requireLogin } from "./api.js";
import { dateTime } from "./domain.js";
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
  form = reactive({
    title: "",
    content: "",
    date: "",
    serial_number: "",
    order_id: "",
    rating: 5,
  });
const creatable = computed(() =>
  ["boat-records", "reminders", "feedback", "reviews"].includes(props.kind),
);
watch(() => props.kind, load, { immediate: true });
async function load() {
  if (!props.kind || !requireLogin()) return;
  loading.value = true;
  error.value = "";
  try {
    const r = await request(`/${props.kind}?page_size=100`);
    items.value = r.items || r || [];
  } catch (e) {
    error.value = e.message;
  } finally {
    loading.value = false;
  }
}
async function create() {
  if (!form.title.trim()) return notify(new Error("请填写标题"));
  if (props.kind === "reviews" && !form.order_id)
    return notify(new Error("请填写订单ID"));
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
    await load();
    uni.showToast({ title: "已保存", icon: "success" });
  } catch (e) {
    notify(e);
  } finally {
    busy.value = false;
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
