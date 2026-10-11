<template>
  <view class="card section">
    <view class="card-head">{{ t('语言') }}</view>
    <view class="chips">
      <button v-for="language in languages" :key="language.code" class="chip"
        :class="{ active: getLocale() === language.code }" @click="setLocale(language.code)">
        {{ language.label }}
      </button>
    </view>
  </view>
</template>
<script setup>
import { t, getLocale, setLocale } from './i18n.js';
const languages = [{ code: 'zh-Hans', label: '中文' }, { code: 'en', label: 'English' }];
</script>
