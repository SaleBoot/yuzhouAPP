<template>
  <view v-if="!loggedIn" class="card section">
    <view class="small muted">{{ t('此功能需要登录，请点击登录后继续。') }}</view>
    <button class="primary section" @click="go('login')">{{ t('去登录') }}</button>
  </view>
</template>
<script setup>
import { ref } from 'vue';
import { onShow } from '@dcloudio/uni-app';
import { t } from './i18n.js';
import { session, go } from './api.js';
const loggedIn = ref(Boolean(session()));
onShow(() => { loggedIn.value = Boolean(session()); });
</script>
