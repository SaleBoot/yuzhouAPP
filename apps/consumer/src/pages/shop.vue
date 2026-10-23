<template>
  <ShopCatalog ref="catalog" title="航海生活" :kinds="['products', 'upgrades', 'maintenance', 'events']" :initial-kind="initialKind" />
</template>
<script setup>
import { ref } from "vue";
import { onLoad, onShow } from "@dcloudio/uni-app";
import ShopCatalog from "@/components/ShopCatalog.vue";
const catalog = ref(null), initialKind = ref("products");
onLoad((query) => { initialKind.value = query.kind || "products"; });
onShow(() => catalog.value?.load());
</script>
