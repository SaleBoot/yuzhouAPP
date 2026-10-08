<template>
  <web-view v-if="valid" :src="url" /><view v-else class="page error"
    >链接无效，请返回重试。</view
  >
</template>
<script setup>
import { computed } from "vue";
const props = defineProps({ url: String });
const valid = computed(() => /^https?:\/\//i.test(props.url || ""));
</script>
