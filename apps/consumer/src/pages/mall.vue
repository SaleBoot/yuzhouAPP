<template>
  <ShopCatalog ref="catalog" title="商城" :kinds="['products']" />
</template>
<script setup>
import { ref } from "vue";
import { onShow } from "@dcloudio/uni-app";
import ShopCatalog from "@/components/ShopCatalog.vue";
const catalog = ref(null);
onShow(() => catalog.value?.load());
</script>
