<template>
  <view class="page"
    ><template v-if="!selected"
      ><view class="title">咨询消息</view
      ><view class="subtitle">与销售沟通船艇、配置和交付</view
      ><button
        v-if="resourceId"
        class="primary section"
        :disabled="busy"
        @click="start"
      >
        咨询这艘船艇</button
      ><view
        v-for="chat in conversations"
        :key="chat.id"
        class="card section list-item"
        @click="
          selected = chat;
          loadMessages();
        "
        ><view
          ><view class="card-head">{{
            chat.title || chat.resource_title || `咨询 ${chat.id}`
          }}</view
          ><view class="muted small">{{
            chat.last_message?.content || "查看咨询记录"
          }}</view></view
        ><text>›</text></view
      ><view v-if="!conversations.length" class="empty"
        >暂无咨询记录。可从船艇详情发起咨询。</view
      ></template
    ><template v-else
      ><view class="row spread"
        ><view class="card-head">{{ selected.title || "船艇咨询" }}</view
        ><text class="link" @click="selected = null">全部咨询</text></view
      ><view class="notice small"
        >消息保存于服务端，当前每5秒同步一次。系统推送尚未配置。</view
      ><scroll-view scroll-y class="messages" :scroll-into-view="lastMessage"
        ><view
          v-for="m in messages"
          :key="m.id"
          :id="`msg-${m.id}`"
          :class="[
            'message',
            { mine: Number(m.sender_id || m.user_id) === Number(user?.id) },
          ]"
          ><text class="small muted"
            >{{
              m.sender_name ||
              m.nickname ||
              (Number(m.sender_id || m.user_id) === Number(user?.id)
                ? "我"
                : "销售")
            }}
            · {{ dateTime(m.created_at) }}</text
          ><view class="bubble">{{ m.content }}</view></view
        ></scroll-view
      ><view class="chat-input row"
        ><input
          v-model="content"
          placeholder="输入咨询内容"
          maxlength="2000"
          @confirm="send"
        /><button class="primary" :disabled="busy" @click="send">
          发送
        </button></view
      ></template
    ><view v-if="error" class="error"
      >{{ error }} <text class="link" @click="load">重试</text></view
    ></view
  >
</template>
<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from "vue";
import { request, post, notify, requireLogin, session, uuid } from "./api.js";
import { dateTime } from "./domain.js";
const props = defineProps({
  resourceId: [String, Number],
  conversationId: [String, Number],
});
const conversations = ref([]),
  selected = ref(null),
  messages = ref([]),
  content = ref(""),
  busy = ref(false),
  error = ref("");
const user = ref(session()?.user);
let timer;
const lastMessage = computed(() =>
  messages.value.length
    ? `msg-${messages.value[messages.value.length - 1].id}`
    : "",
);
async function load() {
  if (!requireLogin()) return;
  error.value = "";
  try {
    const r = await request("/conversations");
    conversations.value = r.items || [];
    if (props.conversationId) {
      selected.value = conversations.value.find(
        (c) => Number(c.id) === Number(props.conversationId),
      );
      if (selected.value) await loadMessages();
    }
  } catch (e) {
    error.value = e.message;
  }
}
async function start() {
  busy.value = true;
  try {
    selected.value = await post("/conversations", {
      resource_id: Number(props.resourceId),
    });
    await loadMessages();
  } catch (e) {
    notify(e);
  } finally {
    busy.value = false;
  }
}
async function loadMessages() {
  if (!selected.value) return;
  try {
    const r = await request(`/conversations/${selected.value.id}/messages`);
    messages.value = r.items || [];
    await post(`/conversations/${selected.value.id}/read`);
    error.value = "";
  } catch (e) {
    error.value = e.message;
  }
}
async function send() {
  if (!content.value.trim() || busy.value) return;
  busy.value = true;
  try {
    await post(`/conversations/${selected.value.id}/messages`, {
      content: content.value,
      client_id: uuid(),
    });
    content.value = "";
    await loadMessages();
  } catch (e) {
    notify(e);
  } finally {
    busy.value = false;
  }
}
function resume() {
  pause();
  timer = setInterval(() => (selected.value ? loadMessages() : null), 5000);
}
function pause() {
  clearInterval(timer);
}
onMounted(() => {
  load();
  resume();
});
onUnmounted(pause);
defineExpose({ resume, pause });
watch(() => props.resourceId, load);
</script>
<style scoped>
.messages {
  height: calc(100vh - 340rpx);
  margin: 24rpx 0;
}
.message {
  margin: 24rpx 0;
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 12rpx;
}
.message.mine {
  align-items: flex-end;
}
.bubble {
  background: white;
  border: 1rpx solid #e5eaf0;
  border-radius: 18rpx;
  padding: 22rpx;
  max-width: 85%;
  font-size: 28rpx;
  line-height: 1.7;
  white-space: pre-wrap;
}
.mine .bubble {
  background: #09233f;
  color: white;
  border: 0;
}
.chat-input {
  padding: 16rpx 0;
  gap: 16rpx;
}
.chat-input input {
  flex: 1;
  background: white;
  border-radius: 15rpx;
  padding: 22rpx;
}
.chat-input button {
  padding: 0 26rpx;
  font-size: 26rpx;
  margin: 0;
}
</style>
