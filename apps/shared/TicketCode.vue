<template>
  <view v-if="code" class="ticket"
    ><image :src="qr" mode="aspectFit" class="qr" /><view class="small muted"
      >入场时出示此报名码</view
    ><text class="ticket-text" @click="copy">{{ code }}</text
    ><text class="small link" @click="copy">复制报名码</text></view
  >
</template>
<script setup>
import { computed } from "vue";
import qrcode from "qrcode-generator";
const props = defineProps({ code: String });
const qr = computed(() => {
  const q = qrcode(0, "M");
  q.addData(props.code || "");
  q.make();
  const n = q.getModuleCount();
  let path = "";
  for (let y = 0; y < n; y++)
    for (let x = 0; x < n; x++)
      if (q.isDark(y, x)) path += `M${x + 4},${y + 4}h1v1h-1z`;
  const svg = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${n + 8} ${n + 8}" shape-rendering="crispEdges"><path fill="white" d="M0 0h${n + 8}v${n + 8}H0z"/><path fill="#09233f" d="${path}"/></svg>`;
  return "data:image/svg+xml;charset=utf-8," + encodeURIComponent(svg);
});
function copy() {
  uni.setClipboardData({ data: props.code });
}
</script>
<style scoped>
.ticket {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 18rpx;
  padding: 20rpx 0;
}
.qr {
  height: 340rpx;
  width: 340rpx;
  background: white;
}
.ticket-text {
  word-break: break-all;
  font-family: monospace;
  font-size: 21rpx;
  color: #6e7d8d;
  max-width: 520rpx;
  text-align: center;
}
</style>
