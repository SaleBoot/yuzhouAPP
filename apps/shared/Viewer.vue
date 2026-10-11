<template>
  <web-view v-if="valid" :src="resolved" /><view v-else class="page error"
    >{{ t("链接无效，请返回重试。") }}</view
  >
</template>
<script setup>
import { t } from './i18n.js';

import { computed } from "vue";
const props = defineProps({ url: String });
const resolved = computed(() => {
  const url = props.url || "";
  if (/^https?:\/\//i.test(url)) return url;
  try {
    return decodeURIComponent(url);
  } catch {
    return "";
  }
});
const valid = computed(() => /^https?:\/\//i.test(resolved.value));
</script>
