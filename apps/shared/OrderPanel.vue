<template>
  <view class="page">
    <view v-if="error" class="error"
      >{{ error }}<text class="link" @click="load"> 重试</text></view
    >
    <view v-if="!order && !error" class="empty">订单加载中…</view>
    <template v-if="order">
      <view class="order-head"
        ><view class="title">{{ state(order.status) }}</view
        ><view class="small"
          >{{ ORDER_TYPES[order.type] }} · {{ order.order_no }}</view
        ></view
      >
      <view class="card"
        ><view class="row spread"
          ><text class="card-head">订单金额</text
          ><text class="price">¥{{ money(order.amount_cents) }}</text></view
        ><view class="row spread muted small"
          ><text>已付 ¥{{ money(order.paid_cents) }}</text
          ><text>已退款 ¥{{ money(order.refund_cents) }}</text></view
        ><view
          v-for="item in order.items || []"
          :key="item.id"
          class="list-item"
          ><text>{{
            displayTitle(item.title || item.name) ||
            `船艇 ${item.boat_id || item.resource_id}`
          }}</text
          ><text>× {{ item.quantity || 1 }}</text></view
        ><view v-if="order.start_date" class="section small"
          >{{ order.start_date }} 至 {{ order.end_date }}（归还日不计租）</view
        ><view class="muted small section">{{
          order.notes || "无备注"
        }}</view></view
      >
      <view
        v-if="broker && ['awaiting_quote', 'quoted'].includes(order.status)"
        class="card section"
        ><view class="card-head">向客户报价</view
        ><view class="formline"
          ><text class="form-label">总报价（元）</text
          ><input
            v-model="quoteAmount"
            type="digit"
            placeholder="以最终配置为准" /></view
        ><view class="formline"
          ><text class="form-label">报价说明</text
          ><textarea
            v-model="quoteNotes"
            placeholder="配置、交付时间、包含项目"
          /></view
        ><button class="primary" :disabled="busy" @click="quote">
          提交报价
        </button></view
      >
      <view v-if="order.status === 'quoted' && !broker" class="card section"
        ><view class="card-head">报价确认</view
        ><view class="article">{{
          order.quote?.notes ||
          order.quote_notes ||
          "请确认上述总金额和订单配置"
        }}</view
        ><button
          class="primary"
          :disabled="busy"
          @click="action('accept-quote')"
        >
          接受报价
        </button></view
      >
      <view
        v-if="broker || requiresContract || contracts.length"
        class="card section"
        ><view class="row spread"
          ><text class="card-head">合同材料</text
          ><text class="badge">离线签署 PDF</text></view
        ><view v-if="!contracts.length" class="muted small"
          >暂未上传合同。由销售提供合同，签署后上传 PDF，再由平台审核。</view
        ><view v-for="c in contracts" :key="c.id" class="list-item"
          ><view
            ><view>{{ c.title || "合同文件" }} · v{{ c.version }}</view
            ><view class="muted small">{{
              c.signed_at
                ? "已确认签署"
                : c.review_status === "approved"
                  ? "已审核，待客户确认"
                  : c.review_status === "rejected"
                    ? "审核拒绝，请重传"
                    : "待平台审核"
            }}</view></view
          ><text class="link" @click="preview(c.file_id)">预览</text></view
        ><view class="actions section"
          ><button
            v-if="
              broker &&
              !order.paid_cents &&
              !['cancelled', 'refunded'].includes(order.status)
            "
            class="secondary"
            :disabled="busy"
            @click="uploadContract"
          >
            上传合同 PDF</button
          ><button
            v-if="
              contracts.length &&
              !signedLatest &&
              !broker &&
              contracts[0].review_status === 'approved'
            "
            class="secondary"
            :disabled="busy"
            @click="sign"
          >
            确认签署当前版
          </button></view
        ><view class="muted small section"
          >当前PDF经平台审核通过后，由客户确认线下签署。电子签服务将另行接入。</view
        ></view
      >
      <view
        v-if="!broker && ['awaiting_payment'].includes(order.status)"
        class="card section"
        ><view class="card-head">支付订单</view
        ><picker
          :range="channels.map((x) => x.label)"
          :value="channelIndex"
          @change="channelIndex = Number($event.detail.value)"
          ><view class="picker-value"
            >{{ channels[channelIndex].label }} ▾</view
          ></picker
        ><view
          v-if="channels[channelIndex].value === 'test'"
          class="notice small"
          >开发环境测试支付，仅验证订单状态，不产生真实扣款。</view
        ><view v-if="requiresContract && !canPay" class="notice small section"
          >请等待当前合同通过平台审核，并确认线下签署后支付。</view
        ><button
          class="primary"
          :loading="busy"
          :disabled="busy || !canPay"
          @click="pay"
        >
          支付服务端订单金额
        </button></view
      >
      <view
        v-if="['boat_upgrade', 'maintenance'].includes(order.type)"
        class="card section"
        ><view class="card-head">服务预约</view
        ><view v-if="order.schedule" class="small section"
          >{{ dateTime(order.schedule.scheduled_at) }} ·
          {{ order.schedule.location }}</view
        ><template
          v-if="!['completed', 'cancelled', 'refunded'].includes(order.status)"
          ><ServiceScheduleFields
            v-model:date="serviceDate"
            v-model:time="serviceTime"
            v-model:location="serviceLocation"
          /><button class="secondary" :disabled="busy" @click="schedule">
            保存服务预约
          </button></template
        ></view
      >
      <view
        v-if="
          order.progress?.length ||
          (broker &&
            ['paid', 'fulfilling', 'partially_refunded'].includes(order.status))
        "
        class="card section"
        ><view class="card-head">履约进度</view
        ><view
          v-for="entry in order.progress || []"
          :key="entry.id"
          class="list-item"
          ><view
            ><view
              >{{ progressLabels[entry.stage] || entry.stage }} ·
              {{ entry.percent }}%</view
            ><view class="small muted">{{ entry.notes }}</view
            ><view class="small muted">{{
              dateTime(entry.created_at)
            }}</view></view
          ></view
        ><template
          v-if="
            broker &&
            ['paid', 'fulfilling', 'partially_refunded'].includes(order.status)
          "
          ><view class="formline"
            ><text class="form-label">实际进度阶段</text
            ><picker
              :range="progressStages.map((s) => s.label)"
              :value="stageIndex"
              @change="stageIndex = Number($event.detail.value)"
              ><view class="picker-value"
                >{{ progressStages[stageIndex].label }} ▾</view
              ></picker
            ></view
          ><view class="formline"
            ><text class="form-label">完成百分比</text
            ><input
              v-model="progressPercent"
              type="number"
              placeholder="0至100" /></view
          ><textarea
            v-model="progressNotes"
            placeholder="填写实际安装或服务进度"
          /><button
            class="secondary section"
            :disabled="busy"
            @click="saveProgress"
          >
            保存实际进度
          </button></template
        ><view class="small muted section"
          >交付验收以订单履约状态为准。</view
        ></view
      >
      <view
        v-if="broker && ['paid', 'fulfilling'].includes(order.status)"
        class="card section"
        ><view class="card-head">履约进度</view
        ><textarea
          v-model="fulfillNotes"
          placeholder="填写实际交付或履约说明"
        /><view class="actions"
          ><button
            v-if="order.status === 'paid'"
            class="secondary"
            :disabled="busy"
            @click="
              action('fulfill', { status: 'fulfilling', notes: fulfillNotes })
            "
          >
            开始履约</button
          ><button
            class="primary"
            :disabled="busy"
            @click="
              action('fulfill', { status: 'completed', notes: fulfillNotes })
            "
          >
            完成交付
          </button></view
        ></view
      >
      <view v-if="!broker && order.status === 'fulfilling'" class="card section"
        ><view class="card-head">验收交付</view
        ><view class="small muted">请核对实际交付内容后确认。</view
        ><button class="primary section" :disabled="busy" @click="receive">
          确认交付完成
        </button>
      </view>
      <view class="card section"
        ><view class="card-head">客户服务</view
        ><view class="formline"
          ><input v-model="reason" placeholder="取消或退款原因" /></view
        ><view class="actions"
          ><button
            v-if="
              ['awaiting_quote', 'quoted', 'awaiting_payment'].includes(
                order.status,
              )
            "
            class="secondary"
            :disabled="busy"
            @click="cancel"
          >
            取消订单</button
          ><button
            v-if="
              !broker &&
              order.paid_cents > order.refund_cents &&
              order.status !== 'refunded'
            "
            class="secondary"
            :disabled="busy || refundPending"
            @click="refund"
          >
            申请退款</button
          ><button v-if="broker" class="secondary" @click="share">
            复制客户订单链接
          </button></view
        ><view
          v-for="entry in order.refund_requests || []"
          :key="entry.id"
          class="section small"
          ><text>退款申请 ¥{{ money(entry.amount_cents) }} · {{ refundLabels[entry.status] || entry.status }}</text
          ><view v-if="entry.review_notes" class="muted">{{ entry.review_notes }}</view></view
        ><view
          v-if="broker && order.profit_cents !== null"
          class="section muted small"
          >平台确认利润 ¥{{
            money(order.profit_cents)
          }}，分配以平台规则为准。</view
        ></view
      >
    </template>
  </view>
</template>
<script setup>
import { computed, ref, watch } from "vue";
import ServiceScheduleFields from "./ServiceScheduleFields.vue";
import {
  request,
  post,
  chooseUpload,
  previewFile,
  notify,
  uuid,
} from "./api.js";
import { money, state, cents, dateTime, ORDER_TYPES, displayTitle } from "./domain.js";
const props = defineProps({ id: [String, Number], broker: Boolean });
const order = ref(null),
  contracts = ref([]),
  error = ref(""),
  busy = ref(false),
  quoteAmount = ref(""),
  quoteNotes = ref(""),
  fulfillNotes = ref(""),
  reason = ref(""),
  channelIndex = ref(0),
  testEnabled = ref(false),
  serviceDate = ref(""),
  serviceTime = ref("10:00"),
  serviceLocation = ref(""),
  stageIndex = ref(0),
  progressPercent = ref(0),
  progressNotes = ref("");
const progressLabels = {
  planned: "已计划",
  preparing: "准备中",
  installing: "安装中",
  testing: "调试中",
  delivery: "交付中",
  completed: "进度已完成",
};
const refundLabels = {
  pending: "待平台审核",
  approved: "已批准，待退款处理",
  rejected: "申请已驳回",
  successful: "退款处理成功",
};
const refundPending = computed(() =>
  (order.value?.refund_requests || []).some((entry) =>
    ["pending", "approved"].includes(entry.status),
  ),
);
const progressStages = Object.entries(progressLabels).map(([value, label]) => ({
  value,
  label,
}));
const channels = computed(() => [
  { label: "微信支付", value: "wechat" },
  { label: "支付宝", value: "alipay" },
  ...(testEnabled.value ? [{ label: "开发测试支付", value: "test" }] : []),
]);
const signedLatest = computed(
  () =>
    contracts.value.length &&
    (contracts.value[0].signed_at || contracts.value[0].signatures?.length),
);
const requiresContract = computed(() =>
  Boolean(
    order.value?.broker_id ||
    order.value?.type === "boat_purchase" ||
    order.value?.contract_version,
  ),
);
const canPay = computed(
  () =>
    !requiresContract.value ||
    Boolean(
      signedLatest.value && contracts.value[0]?.review_status === "approved",
    ),
);
watch(() => props.id, load, { immediate: true });
async function load() {
  if (!props.id) return;
  error.value = "";
  try {
    order.value = await request(`/orders/${props.id}`);
    if (order.value.schedule) {
      const [day, time] = dateTime(order.value.schedule.scheduled_at).split(
        " ",
      );
      serviceDate.value = day;
      serviceTime.value = time;
      serviceLocation.value = order.value.schedule.location;
    }
    const entries = order.value.progress || [];
    const last = entries[entries.length - 1];
    if (last) {
      progressPercent.value = last.percent;
      stageIndex.value = Math.max(
        0,
        progressStages.findIndex((s) => s.value === last.stage),
      );
    }
    const c = await request(`/orders/${props.id}/contracts`);
    contracts.value = (Array.isArray(c) ? c : c.items || []).sort(
      (a, b) => b.version - a.version,
    );
    const b = await request("/bootstrap");
    testEnabled.value = Boolean(
      b.test_payments_enabled ||
      b.payments?.test_enabled ||
      b.allow_test_payments,
    );
  } catch (e) {
    error.value = e.message;
  }
}
async function action(name, data = {}) {
  if (busy.value) return;
  busy.value = true;
  try {
    await post(`/orders/${props.id}/${name}`, data);
    await load();
    uni.showToast({ title: "操作已保存", icon: "success" });
  } catch (e) {
    notify(e);
  } finally {
    busy.value = false;
  }
}
async function quote() {
  try {
    await action("quote", {
      amount_cents: cents(quoteAmount.value),
      notes: quoteNotes.value,
    });
  } catch (e) {
    notify(e);
  }
}
function schedule() {
  if (!serviceDate.value || !serviceTime.value || !serviceLocation.value.trim())
    return notify(new Error("请确定服务日期、时间和地点"));
  action("schedule", {
    scheduled_at: `${serviceDate.value} ${serviceTime.value}:00`,
    location: serviceLocation.value,
  });
}
function saveProgress() {
  const percent = Number(progressPercent.value);
  if (!Number.isInteger(percent) || percent < 0 || percent > 100)
    return notify(new Error("进度应为0至100的整数"));
  action("progress", {
    stage: progressStages[stageIndex.value].value,
    percent,
    notes: progressNotes.value,
  });
}
async function uploadContract() {
  busy.value = true;
  try {
    const f = await chooseUpload({ purpose: "contract" });
    await post(`/orders/${props.id}/contract`, {
      file_id: f.id,
      title: "线下签署合同 PDF",
    });
    await load();
  } catch (e) {
    notify(e);
  } finally {
    busy.value = false;
  }
}
async function preview(id) {
  try {
    await previewFile(id);
  } catch (e) {
    notify(e);
  }
}
function sign() {
  uni.showModal({
    title: "确认当前合同",
    content: "请确认你已阅读且已线下签署最新版本PDF。",
    success: (r) => {
      if (r.confirm)
        action("sign", { version: contracts.value[0].version, accepted: true });
    },
  });
}
async function pay() {
  await action("pay", {
    channel: channels.value[channelIndex.value].value,
    idempotency_key: uuid(),
  });
}
function receive() {
  uni.showModal({
    title: "确认交付完成",
    content: "请确认你已实际验收本订单的交付内容。",
    success: (r) => {
      if (r.confirm)
        action("fulfill", { status: "completed", notes: "客户确认交付完成" });
    },
  });
}
function cancel() {
  if (!reason.value.trim()) return notify(new Error("请填写取消原因"));
  action("cancel", { reason: reason.value });
}
function refund() {
  if (!reason.value.trim()) return notify(new Error("请填写退款原因"));
  uni.showModal({
    title: "申请退款",
    content:
      "将提交当前订单剩余实付金额的退款申请，平台审核并实际处理后更新账务。",
    success: (r) => {
      if (r.confirm)
        action("refund-request", {
          amount_cents: Math.max(
            0,
            order.value.paid_cents - order.value.refund_cents,
          ),
          reason: reason.value,
          channel: channels.value[channelIndex.value].value,
          idempotency_key: uuid(),
        });
    },
  });
}
function share() {
  uni.setClipboardData({
    data: `https://yuzhou.ourideas.top/consumer/#/pages/order?id=${props.id}`,
  });
}
</script>
