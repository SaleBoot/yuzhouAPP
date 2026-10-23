<template>
  <ShopCatalog ref="catalog" title="船艇维保" :kinds="['maintenance', 'upgrades']" />
</template>
<script setup>
import { ref } from "vue";
import { onShow } from "@dcloudio/uni-app";
import ShopCatalog from "@/components/ShopCatalog.vue";
const catalog = ref(null);
onShow(() => catalog.value?.load());
</script>
