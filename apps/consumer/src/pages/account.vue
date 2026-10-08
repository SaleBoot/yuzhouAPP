<template><AccountPanel :kind="kind" /></template>
<script setup>
import { ref } from "vue";
import { onLoad } from "@dcloudio/uni-app";
import AccountPanel from "@shared/AccountPanel.vue";
const kind = ref("notifications");
onLoad((q) => (kind.value = q.kind || "notifications"));
</script>
