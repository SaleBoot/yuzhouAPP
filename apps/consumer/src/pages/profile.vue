<template>
  <view class="page profile-page">
    <view class="profile-navbar">
      <view class="navbar-spacer" />
      <text>{{ t('个人中心') }}</text>
      <button class="notification-button" :aria-label="t('通知消息')" @click="go('account', { kind: 'notifications' })">
        <image src="/static/profile-notifications-light.png" mode="aspectFit" />
      </button>
    </view>
    <view class="profile-hero">
      <view class="profile-identity" @click="!user && go('login')">
        <text class="profile-avatar">{{ (user?.nickname || t('舟'))[0] }}</text>
        <view class="identity-copy">
          <view class="profile-name">{{ user?.nickname || t('登录，开启航海生活') }}</view>
          <view class="profile-caption">{{ user ? t('驭舟编号 {id} · {username}', { id: user.id, username: user.username }) : t('查看订单、成长与专属权益') }}</view>
        </view>
      </view>
      <view class="member-entry" @click="go('membership')">
        <view class="member-copy">
          <text class="member-name">{{ member ? t('驭舟航海会员') : t('驭舟会员') }}</text>
          <text class="member-caption">{{ member ? t('有效期至 {date}', { date: dateTime(user.member_until, false) }) : t('学习与航程，一起积累成长') }}</text>
        </view>
        <text class="member-arrow">›</text>
      </view>
    </view>
    <view class="profile-content">
      <view class="saved-card">
        <button v-for="entry in savedEntries" :key="entry.title" class="saved-entry" @click="entry.action">
          <image :src="entry.icon" mode="aspectFit" />
          <text>{{ t(entry.title) }}</text>
        </button>
      </view>
      <view class="featured-grid">
        <button v-for="(entry, index) in featuredEntries" :key="entry.title" class="featured-entry" :class="{ aqua: index === 1 }" @click="entry.action">
          <text>{{ t(entry.title) }}</text>
          <image :src="entry.icon.replace('.png', '-light.png')" mode="aspectFit" />
        </button>
      </view>
      <view class="profile-card order-card">
        <view class="card-heading">
          <text class="profile-card-title">{{ t('我的订单') }}</text>
          <button class="all-orders" @click="go('orders')">{{ t('全部订单 ›') }}</button>
        </view>
        <view class="order-grid">
          <button v-for="entry in orderEntries" :key="entry.title" class="order-entry" @click="go('orders')">
            <image :src="entry.icon" mode="aspectFit" />
            <text>{{ t(entry.title) }}</text>
          </button>
        </view>
      </view>
      <view class="profile-card service-card">
        <view class="card-heading"><text class="profile-card-title">{{ t('我的服务') }}</text></view>
        <view class="service-grid">
          <button v-for="entry in serviceEntries" :key="entry.title" class="service-entry" @click="entry.action">
            <image :src="entry.icon" mode="aspectFit" />
            <text>{{ t(entry.title) }}</text>
          </button>
        </view>
      </view>
      <PhoneBinding v-if="user" :user="user" @bound="refreshUser" />
      <view v-if="user" class="profile-card account-card">
        <view class="small muted">{{ t('邀请码 {code}', { code: user.invite_code || t('暂未分配') }) }}</view>
        <button class="secondary section" @click="logout">{{ t('退出登录') }}</button>
      </view>
      <view v-if="error" class="error">{{ t(error) }}</view>
    </view>
  </view>
</template>
<script setup>
import { t } from "@shared/i18n.js";
import { ref, computed } from "vue";
import { onShow } from "@dcloudio/uni-app";
import { request, post, session, saveSession, go } from "@shared/api.js";
import { dateTime, memberActive } from "@shared/domain.js";
import PhoneBinding from "@shared/PhoneBinding.vue";
const user = ref(null),
  error = ref("");
const member = computed(() => memberActive(user.value));
const orderEntries = [
  { title: "待报价", icon: "/static/profile-order-quote.png" },
  { title: "待支付", icon: "/static/profile-order-pay.png" },
  { title: "待履约", icon: "/static/profile-order-fulfill.png" },
  { title: "已完成", icon: "/static/profile-order-complete.png" },
];
const entries = [
  { title: "航海课堂", icon: "/static/profile-classroom.png", action: () => go("classroom") },
  { title: "发现", icon: "/static/profile-discover.png", action: () => go("discover") },
  { title: "身份资料与审核", icon: "/static/profile-identity.png", action: () => go("identity") },
  { title: "购物车", icon: "/static/profile-cart.png", action: () => go("cart") },
  { title: "成长值与会员权益", icon: "/static/profile-membership.png", action: () => go("membership") },
  { title: "我的学习与奖励", icon: "/static/profile-learning.png", action: () => go("learning") },
  { title: "积分商城与兑换记录", icon: "/static/profile-points.png", action: () => go("points") },
  { title: "我的优惠券", icon: "/static/profile-coupons.png", action: () => go("coupons") },
  {
    title: "我的活动与报名码", icon: "/static/profile-enrollments.png",
    action: () => go("account", { kind: "enrollments" }),
  },
  { title: "咨询消息", icon: "/static/profile-chat.png", action: () => go("chat") },
  { title: "我的预约", icon: "/static/profile-appointment.png", action: () => go("appointment") },
  { title: "收藏的船艇", icon: "/static/profile-favorites.png", action: () => go("account", { kind: "favorites" }) },
  { title: "浏览足迹", icon: "/static/profile-footprints.png", action: () => go("account", { kind: "footprints" }) },
  { title: "我的船艇", icon: "/static/profile-boat.png", action: () => go("account", { kind: "boat-records" }) },
  { title: "保养提醒", icon: "/static/profile-reminders.png", action: () => go("account", { kind: "reminders" }) },
  { title: "通知消息", icon: "/static/profile-notifications.png", action: () => go("account", { kind: "notifications" }) },
  { title: "意见反馈", icon: "/static/profile-feedback.png", action: () => go("account", { kind: "feedback" }) },
  { title: "交付评价", icon: "/static/profile-reviews.png", action: () => go("account", { kind: "reviews" }) },
  { title: "应用更新", icon: "/static/profile-update.png", action: () => go("viewer", { url: "https://yuzhou.ourideas.top/app-updates/" }) },
  { title: "设置", icon: "/static/profile-settings.png", action: () => go("settings") },
];
const featuredEntries = entries.slice(0, 2);
const savedEntries = entries.filter(entry => ["收藏的船艇", "浏览足迹"].includes(entry.title));
const serviceEntries = entries.filter(entry => ![...featuredEntries, ...savedEntries].includes(entry));
async function refreshUser() {
  error.value = "";
  user.value = session()?.user || null;
  if (user.value)
    try {
      const r = await request("/auth/me");
      user.value = r.user || r;
      saveSession({ ...session(), user: user.value });
    } catch (e) {
      error.value = e.message;
    }
}
onShow(refreshUser);
async function logout() {
  try {
    await post("/auth/logout");
  } catch {}
  saveSession(null);
  user.value = null;
}
</script>
<style scoped>
.profile-page {
  --profile-gradient: linear-gradient(110deg, #33b6ed 0%, #2e67d8 65%, #3560cb 100%);
  min-height: 100vh;
  padding: 0 0 150rpx;
  background: #f4f8ff;
}
.profile-navbar {
  position: sticky;
  top: 0;
  z-index: 20;
  display: flex;
  align-items: center;
  justify-content: space-between;
  min-height: 88rpx;
  padding-top: var(--status-bar-height, env(safe-area-inset-top, 0px));
  background: var(--profile-gradient);
  color: #fff;
  font-size: 32rpx;
  box-sizing: content-box;
}
.notification-button, .saved-entry, .featured-entry, .all-orders, .order-entry, .service-entry {
  width: 100%;
  max-width: 100%;
  margin: 0;
  border: 0;
  line-height: 1.5;
  box-sizing: border-box;
}
.notification-button::after, .saved-entry::after, .featured-entry::after, .all-orders::after, .order-entry::after, .service-entry::after { border: 0; }
.navbar-spacer, .notification-button { width: 96rpx; }
.notification-button { display: flex; align-items: center; justify-content: center; height: 88rpx; padding: 0; background: transparent; }
.notification-button image { width: 40rpx; height: 40rpx; }
.profile-hero { padding: 24rpx 32rpx 28rpx; background: var(--profile-gradient); color: #fff; }
.profile-identity { display: flex; align-items: center; gap: 24rpx; }
.profile-avatar { display: flex; align-items: center; justify-content: center; width: 112rpx; height: 112rpx; flex-shrink: 0; border: 4rpx solid #ffffffdd; border-radius: 50%; background: #ffffff20; color: #fff; font-size: 42rpx; }
.identity-copy { min-width: 0; }
.profile-name { font-size: 32rpx; font-weight: 600; overflow-wrap: anywhere; }
.profile-caption { margin-top: 8rpx; color: #e4efff; font-size: 22rpx; overflow-wrap: anywhere; }
.member-entry { display: flex; align-items: center; justify-content: space-between; gap: 16rpx; margin-top: 24rpx; padding: 14rpx 20rpx; border-radius: 16rpx; background: #ffffff18; }
.member-copy { display: flex; flex-wrap: wrap; align-items: center; gap: 8rpx 16rpx; min-width: 0; }
.member-name { font-size: 24rpx; font-weight: 500; }
.member-caption { font-size: 21rpx; color: #edf5ff; overflow-wrap: anywhere; }
.member-arrow { font-size: 32rpx; line-height: 1; }
.profile-content { padding: 0 24rpx; }
.saved-card { display: flex; background: #fff; border-radius: 0 0 20rpx 20rpx; box-shadow: 0 4rpx 12rpx #142e5710; }
.saved-entry { display: flex; align-items: center; justify-content: center; gap: 12rpx; width: 50%; min-height: 88rpx; padding: 16rpx 8rpx; background: transparent; font-size: 24rpx; color: #344252; }
.saved-entry image { width: 38rpx; height: 38rpx; flex-shrink: 0; }
.saved-entry text { min-width: 0; overflow-wrap: anywhere; }
.featured-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 20rpx; margin-top: 24rpx; }
.featured-entry { position: relative; display: flex; align-items: flex-start; min-width: 0; min-height: 152rpx; padding: 24rpx; border-radius: 12rpx; overflow: hidden; color: #fff; background: linear-gradient(120deg, #408cdb, #767fe6); text-align: left; }
.featured-entry.aqua { background: linear-gradient(120deg, #19a7b9, #62b9dd); }
.featured-entry::before { content: ""; position: absolute; width: 160rpx; height: 160rpx; top: -94rpx; right: -18rpx; border-radius: 50%; background: #ffffff12; }
.featured-entry text { position: relative; max-width: 80%; font-size: 28rpx; line-height: 1.4; overflow-wrap: anywhere; }
.featured-entry image { position: absolute; right: 22rpx; bottom: 18rpx; width: 48rpx; height: 48rpx; opacity: .8; }
.profile-card { margin-top: 24rpx; border-radius: 20rpx; background: #fff; box-shadow: 0 4rpx 12rpx #142e570c; }
.card-heading { display: flex; align-items: center; justify-content: space-between; gap: 16rpx; min-height: 78rpx; padding: 8rpx 28rpx; }
.profile-card-title { position: relative; padding-left: 18rpx; color: #253343; font-size: 28rpx; font-weight: 600; }
.profile-card-title::before { content: ""; position: absolute; left: 0; top: 9rpx; width: 6rpx; height: 24rpx; border-radius: 4rpx; background: linear-gradient(#4bc8ff, #216cf7); }
.all-orders { width: auto; flex-shrink: 0; padding: 8rpx 0; font-size: 22rpx; color: #999; background: transparent; }
.order-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); padding: 4rpx 12rpx 28rpx; }
.order-entry, .service-entry { display: flex; flex-direction: column; align-items: center; min-width: 0; padding: 12rpx 8rpx; background: transparent; text-align: center; color: #344252; }
.order-entry image, .service-entry image { width: 56rpx; height: 56rpx; flex-shrink: 0; margin-bottom: 14rpx; }
.order-entry text, .service-entry text { display: block; width: 100%; font-size: 22rpx; line-height: 32rpx; overflow-wrap: anywhere; }
.service-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); row-gap: 16rpx; padding: 8rpx 12rpx 24rpx; }
.service-entry { min-height: 148rpx; justify-content: flex-start; }
.account-card { padding: 24rpx; }
</style>
