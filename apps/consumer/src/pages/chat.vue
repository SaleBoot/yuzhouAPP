<template>
  <ChatPanel
    ref="panel"
    :resource-id="resourceId"
    :conversation-id="conversationId"
  />
</template>
<script setup>
import { ref } from "vue";
import { onLoad, onShow, onHide } from "@dcloudio/uni-app";
import ChatPanel from "@shared/ChatPanel.vue";
const panel = ref(null);
onShow(() => panel.value?.resume());
onHide(() => panel.value?.pause());
const resourceId = ref(""),
  conversationId = ref("");
onLoad((q) => {
  resourceId.value = q.resource_id || "";
  conversationId.value = q.id || "";
});
</script>
