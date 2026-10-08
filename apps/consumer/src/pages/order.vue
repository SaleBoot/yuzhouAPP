<template><OrderPanel :id="id" /></template>
<script setup>
import { ref } from "vue";
import { onLoad } from "@dcloudio/uni-app";
import OrderPanel from "@shared/OrderPanel.vue";
const id = ref("");
onLoad((q) => (id.value = q.id));
</script>
