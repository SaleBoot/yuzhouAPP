<template>
  <view class="page"
    ><LoginPrompt /><view class="title">{{ t('我的优惠券') }}</view
    ><view class="subtitle">{{ t('一次订单使用一张，优惠由服务端核算') }}</view
    ><view class="chips"
      ><text
        :class="['chip', { active: !claimMode }]"
        @click="
          claimMode = false;
          load();
        "
        >{{ t('我的券') }}</text
      ><text
        :class="['chip', { active: claimMode }]"
        @click="
          claimMode = true;
          load();
        "
        >{{ t('领券中心') }}</text
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
          >{{ t('满 ¥{amount} 可用', { amount: money(coupon.min_amount_cents || coupon.payload?.min_amount_cents) }) }}</text
        ></view
      ><view class="coupon-copy"
        ><view class="card-head">{{
          coupon.title || coupon.coupon?.title || t('驭舟优惠券')
        }}</view
        ><view class="small muted"
          >{{ t('有效期 {date}', { date: coupon.expires_at || coupon.payload?.expires_at || t('以券详情为准') }) }}</view
        ><view class="small section">{{
          (coupon.order_types || coupon.payload?.order_types || [])
            .map((x) => t(ORDER_TYPES[x] || x))
            .join(" · ")
        }}</view
        ><button
          v-if="claimMode"
          class="secondary section"
          :disabled="busy"
          @click="claim(coupon.id)"
        >{{ t('领取') }}</button
        ><text v-else class="badge section">{{
          coupon.used_at ? t('已使用') : t('待使用')
        }}</text></view
      ></view
    ><view v-if="!coupons.length && !error" class="empty">{{ t('暂无优惠券') }}</view
    ><view v-if="error" class="error">{{ t(error) }}</view></view
  >
</template>
<script setup>
import LoginPrompt from "@shared/LoginPrompt.vue";
import { t } from "@shared/i18n.js";
import { ref } from "vue";
import { onShow } from "@dcloudio/uni-app";
import { request, list, post, notify, requireLogin } from "@shared/api.js";
import { money, ORDER_TYPES } from "@shared/domain.js";
const coupons = ref([]),
  claimMode = ref(false),
  busy = ref(false),
  error = ref("");
async function load() {
  if (!requireLogin(false)) { coupons.value = []; error.value = ""; return; }
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
    uni.showToast({ title: t("领取成功"), icon: "success" });
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
