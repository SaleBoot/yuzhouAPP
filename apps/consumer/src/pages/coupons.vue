<template>
  <view class="page"
    ><view class="title">我的优惠券</view
    ><view class="subtitle">一次订单使用一张，优惠由服务端核算</view
    ><view class="chips"
      ><text
        :class="['chip', { active: !claimMode }]"
        @click="
          claimMode = false;
          load();
        "
        >我的券</text
      ><text
        :class="['chip', { active: claimMode }]"
        @click="
          claimMode = true;
          load();
        "
        >领券中心</text
      ></view
    ><view
      v-for="coupon in coupons"
      :key="coupon.id"
      class="coupon card section"
      ><view class="coupon-amount"
        ><text class="price"
          >¥{{
            money(coupon.discount_cents || coupon.payload?.discount_cents)
          }}</text
        ><text class="small muted"
          >满 ¥{{
            money(coupon.min_amount_cents || coupon.payload?.min_amount_cents)
          }}
          可用</text
        ></view
      ><view class="coupon-copy"
        ><view class="card-head">{{
          coupon.title || coupon.coupon?.title || "驭舟优惠券"
        }}</view
        ><view class="small muted"
          >有效期
          {{
            coupon.expires_at || coupon.payload?.expires_at || "以券详情为准"
          }}</view
        ><view class="small section">{{
          (coupon.order_types || coupon.payload?.order_types || [])
            .map((x) => ORDER_TYPES[x] || x)
            .join(" · ")
        }}</view
        ><button
          v-if="claimMode"
          class="secondary section"
          :disabled="busy"
          @click="claim(coupon.id)"
        >
          领取</button
        ><text v-else class="badge section">{{
          coupon.used_at ? "已使用" : "待使用"
        }}</text></view
      ></view
    ><view v-if="!coupons.length && !error" class="empty">暂无优惠券</view
    ><view v-if="error" class="error">{{ error }}</view></view
  >
</template>
<script setup>
import { ref } from "vue";
import { onShow } from "@dcloudio/uni-app";
import { request, list, post, notify, requireLogin } from "@shared/api.js";
import { money, ORDER_TYPES } from "@shared/domain.js";
const coupons = ref([]),
  claimMode = ref(false),
  busy = ref(false),
  error = ref("");
async function load() {
  if (!requireLogin()) return;
  error.value = "";
  try {
    if (claimMode.value) coupons.value = await list("coupons");
    else {
      const r = await request("/coupons/my");
      coupons.value = (r.items || r || []).map((c) => ({
        ...c,
        ...c.payload,
        ...c.coupon,
        ...c.coupon?.payload,
      }));
    }
  } catch (e) {
    error.value = e.message;
  }
}
async function claim(id) {
  busy.value = true;
  try {
    await post(`/coupons/${id}/claim`);
    uni.showToast({ title: "领取成功", icon: "success" });
    await load();
  } catch (e) {
    notify(e);
  } finally {
    busy.value = false;
  }
}
onShow(load);
</script>
<style scoped>
.coupon {
  display: flex;
  gap: 24rpx;
}
.coupon-amount {
  display: flex;
  flex-direction: column;
  justify-content: center;
  gap: 10rpx;
  border-right: 2rpx dashed #dee4eb;
  padding-right: 24rpx;
  min-width: 190rpx;
}
.coupon-copy {
  flex: 1;
}
</style>
