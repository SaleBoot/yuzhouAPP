<template>
  <view class="page profile-page">
    <view class="profile-navbar">
      <view class="navbar-spacer" />
      <text>{{ t('个人中心') }}</text>
      <view class="navbar-spacer" />
    </view>
    <view class="profile-hero">
      <view class="profile-identity" @click="!user && go('login')">
        <text class="profile-avatar">{{ (user?.nickname || t('销'))[0] }}</text>
        <view class="identity-copy">
          <view class="profile-name">{{ user?.nickname || t('登录销售账号') }}</view>
          <view class="profile-caption">{{ user?.username || t('由平台分配销售身份') }}</view>
        </view>
      </view>
      <view class="company-card">
        <text class="company-name">{{ t('驭舟销售') }}</text>
        <text class="company-caption">{{ user?.org_name || t('所属销售公司 {id}', { id: user?.org_id || t('待关联') }) }}</text>
      </view>
    </view>
    <view class="profile-content">
      <view class="profile-card service-card">
        <view class="card-heading"><text class="profile-card-title">{{ t('我的服务') }}</text></view>
        <view class="service-grid">
          <button v-for="entry in entries" :key="entry.title" class="service-entry" @click="entry.action">
            <image :src="entry.icon" mode="aspectFit" />
            <text>{{ t(entry.title) }}</text>
          </button>
        </view>
      </view>
      <PhoneBinding v-if="user" :user="user" @bound="refreshUser" />
      <view class="profile-card account-card">
        <view class="small muted">{{ t('这是销售人员工作端。机构管理、规则配置与审核由平台后台处理。') }}</view>
        <button class="secondary section" @click="user ? logout() : go('login')">
          {{ user ? t('退出登录') : t('销售登录') }}
        </button>
      </view>
      <view v-if="error" class="error">{{ t(error) }}</view>
    </view>
  </view>
</template>
<script setup>
import { ref } from "vue";
import { onShow } from "@dcloudio/uni-app";
import { t } from "@shared/i18n.js";
import { request, post, session, saveSession, go, tab } from "@shared/api.js";
import PhoneBinding from "@shared/PhoneBinding.vue";
const user = ref(session()?.user),
  error = ref("");
const entries = [
  { title: "佣金结算", icon: "/static/profile-order-pay.png", action: () => go("commission") },
  { title: "销售统计与Excel报表", icon: "/static/profile-reviews.png", action: () => go("reports") },
  { title: "咨询消息", icon: "/static/profile-chat.png", action: () => go("chat") },
  { title: "带看预约", icon: "/static/profile-appointment.png", action: () => go("appointment") },
  { title: "我的客户", icon: "/static/profile-identity.png", action: () => tab("customers") },
  { title: "合同与履约", icon: "/static/profile-order-fulfill.png", action: () => tab("orders") },
  { title: "应用更新", icon: "/static/profile-update.png", action: () => go("viewer", { url: "https://yuzhou.ourideas.top/app-updates/" }) },
  { title: "设置", icon: "/static/profile-settings.png", action: () => go("settings") },
];
async function refreshUser() {
  error.value = "";
  user.value = session()?.user;
  if (user.value)
    try {
      const u = await request("/auth/me");
      user.value = u.user || u;
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
  go("login");
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
.navbar-spacer { width: 96rpx; }
.profile-hero { padding: 24rpx 32rpx 28rpx; background: var(--profile-gradient); color: #fff; }
.profile-identity { display: flex; align-items: center; gap: 24rpx; }
.profile-avatar { display: flex; align-items: center; justify-content: center; width: 112rpx; height: 112rpx; flex-shrink: 0; border: 4rpx solid #ffffffdd; border-radius: 50%; background: #ffffff20; color: #fff; font-size: 42rpx; }
.identity-copy { min-width: 0; }
.profile-name { font-size: 32rpx; font-weight: 600; overflow-wrap: anywhere; }
.profile-caption { margin-top: 8rpx; color: #e4efff; font-size: 22rpx; overflow-wrap: anywhere; }
.company-card { display: flex; flex-wrap: wrap; align-items: center; gap: 8rpx 16rpx; margin-top: 24rpx; padding: 14rpx 20rpx; border-radius: 16rpx; background: #ffffff18; }
.company-name { font-size: 24rpx; font-weight: 500; }
.company-caption { min-width: 0; font-size: 21rpx; color: #edf5ff; overflow-wrap: anywhere; }
.profile-content { padding: 0 24rpx; }
.profile-card { margin-top: 24rpx; border-radius: 20rpx; background: #fff; box-shadow: 0 4rpx 12rpx #142e570c; }
.card-heading { display: flex; align-items: center; min-height: 78rpx; padding: 8rpx 28rpx; }
.profile-card-title { position: relative; padding-left: 18rpx; color: #253343; font-size: 28rpx; font-weight: 600; }
.profile-card-title::before { content: ""; position: absolute; left: 0; top: 9rpx; width: 6rpx; height: 24rpx; border-radius: 4rpx; background: linear-gradient(#4bc8ff, #216cf7); }
.service-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); row-gap: 16rpx; padding: 8rpx 12rpx 24rpx; }
.service-entry { display: flex; flex-direction: column; align-items: center; justify-content: flex-start; width: 100%; max-width: 100%; min-width: 0; min-height: 148rpx; margin: 0; padding: 12rpx 8rpx; border: 0; line-height: 1.5; box-sizing: border-box; background: transparent; text-align: center; color: #344252; }
.service-entry::after { border: 0; }
.service-entry image { width: 56rpx; height: 56rpx; flex-shrink: 0; margin-bottom: 14rpx; }
.service-entry text { display: block; width: 100%; font-size: 22rpx; line-height: 32rpx; overflow-wrap: anywhere; }
.account-card { padding: 24rpx; }
</style>
