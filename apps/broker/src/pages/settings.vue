<template>
  <view class="page settings-page">
    <view class="settings-navbar">
      <button class="back-button" :aria-label="t('返回')" @click="back"><view class="back-arrow" /></button>
      <text>{{ t('设置') }}</text>
      <view class="navbar-spacer" />
    </view>
    <view class="settings-content"><LanguagePicker /></view>
  </view>
</template>
<script setup>
import { t } from "@shared/i18n.js";
import { tab } from "@shared/api.js";
import LanguagePicker from "@shared/LanguagePicker.vue";
function back() {
  uni.navigateBack({ fail: () => tab("profile") });
}
</script>
<style scoped>
.settings-page { min-height: 100vh; padding: 0; background: #f4f8ff; }
.settings-navbar {
  position: sticky;
  top: 0;
  z-index: 20;
  display: flex;
  align-items: center;
  justify-content: space-between;
  min-height: 88rpx;
  padding-top: var(--status-bar-height, env(safe-area-inset-top, 0px));
  background: linear-gradient(110deg, #33b6ed 0%, #2e67d8 65%, #3560cb 100%);
  color: #fff;
  font-size: 32rpx;
  box-sizing: content-box;
}
.back-button { display: flex; align-items: center; justify-content: center; width: 96rpx; height: 88rpx; margin: 0; padding: 0; border: 0; line-height: 1.5; box-sizing: border-box; background: transparent; }
.back-button::after { border: 0; }
.back-arrow { width: 18rpx; height: 18rpx; border-left: 3rpx solid #fff; border-bottom: 3rpx solid #fff; transform: rotate(45deg); }
.navbar-spacer { width: 96rpx; }
.settings-content { padding: 8rpx 24rpx 48rpx; }
</style>
