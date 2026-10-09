<template>
  <view class="page"
    ><view class="row spread"
      ><view
        ><text class="small muted">YUZHOU SALES</text
        ><view class="title"
          >{{ user?.nickname || "驭舟销售" }}，早安</view
        ></view
      ><text class="avatar" @click="tab('profile')">舟</text></view
    ><view class="subtitle">把每一次咨询，带到更远的航程</view
    ><view class="hero section"
      ><view class="hero-kicker">YOUR SALES DESK</view
      ><view class="title">从看船，到交付</view
      ><view class="small section">客户、配置、合同与履约，随时跟进</view
      ><button class="gold-button section" @click="go('quote')">
        ＋ 代客配置下单
      </button></view
    ><view class="row spread section" @click="external(vrBaseUrl)"
      ><text class="card-head">智能 3D 看船</text
      ><text class="link small">进入看船平台 ↗</text></view
    >
    <view class="row spread section" @click="go('map')"><text class="card-head">地图找船</text><text class="link small">查看已发布实船 ›</text></view>
    <view class="grid section"
      ><view class="kpi card" @click="tab('customers')"
        ><text class="kpi-value">{{ customerCount }}</text
        ><text class="muted small">负责客户</text></view
      ><view class="kpi card" @click="tab('orders')"
        ><text class="kpi-value">{{ pending.length }}</text
        ><text class="muted small">跟进中的订单</text></view
      ></view
    ><view class="card section"
      v-if="monthlyTarget"
      ><view class="card-head">公司 {{ monthlyTarget.month }} 销售目标</view><view class="small muted section">当月实收净额 ¥{{ money(monthlyTarget.actual_net_paid_cents) }}</view><template v-if="monthlyTarget.configured"><view class="small section">目标 ¥{{ money(monthlyTarget.target_amount_cents) }}</view><progress class="section" :percent="targetProgress" activeColor="#216cf7" /></template><view v-else class="small muted section">公司尚未设置本月目标</view></view>
    <view class="card section"
      ><view class="grid four"
        ><view
          v-for="s in shortcuts"
          :key="s.title"
          class="scene"
          @click="s.action"
          ><text class="tile-icon">{{ s.icon }}</text
          ><text>{{ s.title }}</text></view
        ></view
      ></view
    ><view class="row spread section"
      ><view class="card-head">待办航程</view
      ><text class="small link" @click="tab('orders')">全部订单 ›</text></view
    ><view
      v-for="o in pending.slice(0, 5)"
      :key="o.id"
      class="card section"
      @click="go('order', { id: o.id })"
      ><view class="row spread"
        ><text>{{ o.order_no }}</text
        ><text class="badge">{{ state(o.status) }}</text></view
      ><view class="row spread section"
        ><text class="muted small">{{ ORDER_TYPES[o.type] }}</text
        ><text class="price">¥{{ money(o.amount_cents) }}</text></view
      ></view
    ><view v-if="!pending.length && !error" class="empty">暂无待跟进订单</view
    ><view v-if="error" class="error"
      >{{ error }} <text @click="load">重试</text></view
    ></view
  >
</template>
<script setup>
import { ref, computed } from "vue";
import { onShow } from "@dcloudio/uni-app";
import {
  request,
  session,
  requireLogin,
  go,
  tab,
  external,
} from "@shared/api.js";
import { money, state, ORDER_TYPES } from "@shared/domain.js";
const user = ref(session()?.user),
  customers = ref([]),
  orders = ref([]),
  error = ref(""),
  customerCount = ref(0),
  vrBaseUrl = ref("");
const monthlyTarget = ref(null);
const targetProgress = computed(() => monthlyTarget.value?.target_amount_cents > 0 ? Math.max(0, Math.min(100, Math.round(100 * monthlyTarget.value.actual_net_paid_cents / monthlyTarget.value.target_amount_cents))) : 0);
const pending = computed(() =>
  orders.value.filter(
    (o) => !["completed", "cancelled", "refunded"].includes(o.status),
  ),
);
const shortcuts = [
  { title: "客户管理", icon: "♧", action: () => tab("customers") },
  { title: "合同履约", icon: "▤", action: () => tab("orders") },
  { title: "佣金结算", icon: "￥", action: () => go("commission") },
  { title: "咨询预约", icon: "⌁", action: () => go("chat") },
];
async function load() {
  if (!requireLogin()) return;
  try {
    const [c, o, config, target] = await Promise.all([
      request("/customers?page_size=100"),
      request("/orders?page_size=100"),
      request("/bootstrap"),
      request("/agency-targets"),
    ]);
    customers.value = c.items || [];
    customerCount.value = c.total ?? customers.value.length;
    vrBaseUrl.value = config.vr_base_url || "";
    monthlyTarget.value = target;
    orders.value = o.items || [];
    user.value = session()?.user;
    error.value = "";
  } catch (e) {
    error.value = e.message;
  }
}
onShow(load);
</script>
<style scoped>
.four {
  grid-template-columns: repeat(4, 1fr);
}
.four .scene {
  font-size: 22rpx;
}
.gold-button {
  background: #fff;
  border: 2rpx solid #fff;
  border-radius: 44rpx;
  color: #216cf7;
  font-size: 30rpx;
  line-height: 84rpx;
  height: 88rpx;
  box-sizing: border-box;
  padding: 0 26rpx;
  margin-left: 0;
  display: inline-block;
}
</style>
