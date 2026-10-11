<template>
  <view class="map-page">
    <!-- #ifdef APP-PLUS -->
    <web-view :src="url" />
    <!-- #endif -->
    <!-- #ifdef H5 -->
    <iframe ref="frame" :src="url" :title="t('地图找船')" class="map-frame" allow="geolocation 'none'; camera 'none'; microphone 'none'" />
    <!-- #endif -->
  </view>
</template>
<script setup>
import { t } from './i18n.js';

import { ref, onMounted, onUnmounted } from "vue";
import { go } from "./api.js";
import { validDetailMessage } from "../map/model.js";
const client = import.meta.env.VITE_CLIENT || "consumer";
let url = `https://yuzhou.ourideas.top/boat-map/?client=${client}&native=1`;
// #ifdef H5
url = `${window.location.origin}/boat-map/?client=${client}`;
// #endif
const frame = ref(null);
function onMessage(event) {
  // #ifdef H5
  if (event.origin !== window.location.origin || event.source !== frame.value?.contentWindow || !validDetailMessage(event.data)) return;
  if (client === "broker") go("quote", { boat_id: event.data.boat_id });
  else go("boat", { id: event.data.boat_type_id, instance_id: event.data.boat_id, mode: "buy" });
  // #endif
}
onMounted(() => {
  // #ifdef H5
  window.addEventListener("message", onMessage);
  // #endif
});
onUnmounted(() => {
  // #ifdef H5
  window.removeEventListener("message", onMessage);
  // #endif
});
</script>
<style scoped>
.map-page { height: calc(100vh - 44px); }
.map-frame { border: 0; width: 100%; height: 100%; }
</style>
