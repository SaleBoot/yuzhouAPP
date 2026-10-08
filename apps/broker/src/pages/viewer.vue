<template><Viewer :url="url" /></template>
<script setup>
import { ref } from "vue";
import { onLoad } from "@dcloudio/uni-app";
import Viewer from "@shared/Viewer.vue";
const url = ref("");
onLoad((q) => (url.value = q.url || ""));
</script>
