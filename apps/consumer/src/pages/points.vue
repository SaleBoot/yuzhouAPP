<template>
  <view class="page"
    ><view class="title">积分商城</view
    ><view class="subtitle">使用实际成长积分兑换平台礼品</view>
    <view class="hero section"
      ><view class="small">可用积分</view
      ><view class="title">{{ balance === null ? "—" : balance }}</view
      ><view class="small section"
        >兑换记录生成后，由平台安排实际履约。</view
      ></view
    >
    <view v-if="error" class="error"
      >{{ error }} <text @click="load">重试</text></view
    >
    <view class="grid section"
      ><view v-for="item in products" :key="item.id" class="card"
        ><image
          :src="item.image_url || fallbackBoat"
          class="point-image"
          mode="aspectFill"
        /><view class="card-head section">{{ item.name }}</view
        ><view class="small muted">{{ item.description }}</view
        ><view class="price section">{{ item.points_price }} 积分</view
        ><view class="small muted">库存 {{ item.stock }}</view
        ><button
          class="primary section"
          :disabled="busy || item.stock < 1"
          @click="redeem(item)"
        >
          兑换一件
        </button></view
      ></view
    >
    <view v-if="!products.length && !error" class="empty">暂无可兑换礼品</view>
    <view class="card section"
      ><view class="card-head">我的兑换</view
      ><view v-for="item in redemptions" :key="item.id" class="redemption"
        ><view class="row spread"
          ><text>{{ snapshot(item).title || "积分兑换" }}</text
          ><text class="badge">{{
            states[item.status] || item.status
          }}</text></view
        ><view class="small muted section"
          >{{ item.points }} 积分 · {{ dateTime(item.created_at) }}</view
        ><view v-if="item.notes" class="small muted">{{ item.notes }}</view
        ><button
          v-if="item.status === 'pending_fulfillment'"
          class="secondary section"
          :disabled="busy"
          @click="cancel(item)"
        >
          取消兑换并返还积分
        </button></view
      ><view v-if="!redemptions.length" class="empty">暂无兑换记录</view></view
    >
  </view>
</template>
<script setup>
import { ref } from "vue";
import { onShow } from "@dcloudio/uni-app";
import {
  request,
  post,
  resource,
  requireLogin,
  notify,
  uuid,
  fallbackBoat,
} from "@shared/api.js";
import { dateTime } from "@shared/domain.js";
const balance = ref(null),
  products = ref([]),
  redemptions = ref([]),
  error = ref(""),
  busy = ref(false),
  keys = new Map();
const states = {
  pending_fulfillment: "待平台履约",
  fulfilled: "平台已履约",
  cancelled: "已取消并返还积分",
  rejected: "已拒绝并返还积分",
};
function snapshot(item) {
  if (typeof item.item_snapshot === "object") return item.item_snapshot || {};
  try {
    return JSON.parse(item.item_snapshot || "{}");
  } catch {
    return {};
  }
}
async function load() {
  if (!requireLogin()) return;
  error.value = "";
  try {
    const [g, p, r] = await Promise.all([
      request("/growth"),
      request("/points-shop/products?page_size=100"),
      request("/points-shop/redemptions?page_size=100"),
    ]);
    balance.value = g.points;
    products.value = (p.items || []).map(resource);
    redemptions.value = r.items || [];
  } catch (e) {
    error.value = e.message;
  }
}
async function redeem(item) {
  if (busy.value) return;
  busy.value = true;
  try {
    if (!keys.has(item.id)) keys.set(item.id, uuid());
    await post("/points-shop/redeem", {
      product_id: item.id,
      quantity: 1,
      idempotency_key: keys.get(item.id),
    });
    keys.delete(item.id);
    await load();
    uni.showToast({ title: "兑换已提交，待平台履约", icon: "none" });
  } catch (e) {
    notify(e);
  } finally {
    busy.value = false;
  }
}
async function cancel(item) {
  if (busy.value) return;
  busy.value = true;
  try {
    await post(`/points-shop/redemptions/${item.id}/cancel`, {
      notes: "客户取消待履约兑换",
    });
    await load();
    uni.showToast({ title: "已取消并返还积分", icon: "none" });
  } catch (e) {
    notify(e);
  } finally {
    busy.value = false;
  }
}
onShow(load);
</script>
<style scoped>
.point-image {
  width: 100%;
  height: 180rpx;
  border-radius: 12rpx;
}
.redemption {
  padding: 24rpx 0;
  border-bottom: 1rpx solid #edf0f3;
}
</style>
