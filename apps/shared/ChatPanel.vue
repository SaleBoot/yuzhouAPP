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
        @click="open(chat)"
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
        ><text class="link" @click="open(null)">全部咨询</text></view
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
                : user?.role === "broker" ? "客户" : "销售")
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
      >{{ error }} <text class="link" @click="selected ? loadMessages() : load()">重试</text></view
    ></view
  >
</template>
<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from "vue";
import { request, post, notify, requireLogin, session, uuid } from "./api.js";
import { dateTime } from "./domain.js";
import { conversationMessages } from "./chat.js";
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
let timer, pendingMessages = null, messageGeneration = 0, listGeneration = 0, visible = true;
const lastMessage = computed(() =>
  messages.value.length
    ? `msg-${messages.value[messages.value.length - 1].id}`
    : "",
);
async function load() {
  if (!requireLogin()) return;
  const generation = ++listGeneration;
  error.value = "";
  try {
    const r = await request("/conversations");
    if (generation !== listGeneration || !visible) return;
    conversations.value = r.items || [];
    if (props.conversationId) {
      const chat = conversations.value.find(
        (c) => Number(c.id) === Number(props.conversationId),
      );
      open(chat || { id: Number(props.conversationId) });
    }
  } catch (e) {
    if (generation === listGeneration && visible) error.value = e.message;
  }
}
function open(chat) {
  messageGeneration++;
  pendingMessages = null;
  selected.value = chat;
  messages.value = [];
  content.value = "";
  error.value = "";
  if (chat) loadMessages();
}
async function start() {
  if (busy.value || !visible) return;
  busy.value = true;
  const generation = messageGeneration;
  try {
    const chat = await post("/conversations", {
      resource_id: Number(props.resourceId),
    });
    if (visible && generation === messageGeneration) open(chat);
  } catch (e) {
    notify(e);
  } finally {
    busy.value = false;
  }
}
function loadMessages() {
  if (!selected.value || !visible) return Promise.resolve();
  if (pendingMessages) {
    pendingMessages.again = true;
    return pendingMessages.promise;
  }
  const task = { id: selected.value.id, generation: messageGeneration, again: false };
  const current = () => visible && task.generation === messageGeneration && task.id === selected.value?.id;
  pendingMessages = task;
  task.promise = (async () => {
    do {
      task.again = false;
      const result = await conversationMessages(request, task.id, messages.value, current);
      if (result === null) return;
      messages.value = result;
      await post(`/conversations/${task.id}/read`);
      if (current()) error.value = "";
    } while (task.again && current());
  })().catch((e) => {
    if (current()) error.value = e.message;
  }).finally(() => {
    if (pendingMessages === task) pendingMessages = null;
  });
  return task.promise;
}
async function send() {
  if (!selected.value || !visible || !content.value.trim() || busy.value) return;
  const id = selected.value.id, generation = messageGeneration, text = content.value;
  busy.value = true;
  try {
    await post(`/conversations/${id}/messages`, {
      content: text,
      client_id: uuid(),
    });
    if (visible && generation === messageGeneration && id === selected.value?.id) {
      content.value = "";
      await loadMessages();
    }
  } catch (e) {
    notify(e);
  } finally {
    busy.value = false;
  }
}
function resume() {
  clearInterval(timer);
  visible = true;
  if (selected.value) loadMessages();
  else load();
  timer = setInterval(() => (selected.value ? loadMessages() : null), 5000);
}
function pause() {
  clearInterval(timer);
  visible = false;
  messageGeneration++;
  listGeneration++;
  pendingMessages = null;
}
onMounted(resume);
onUnmounted(pause);
defineExpose({ resume, pause });
watch(() => [props.resourceId, props.conversationId], () => {
  open(null);
  load();
});
</script>
<style scoped>
.page {
  display: flex;
  flex-direction: column;
  height: calc(100vh - var(--window-top, 0px) - var(--window-bottom, 0px));
  padding-bottom: calc(24rpx + env(safe-area-inset-bottom));
}
.page > .row,
.notice,
.error {
  flex-shrink: 0;
}
.messages {
  flex: 1;
  height: 0;
  min-height: 240rpx;
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
  border: 1rpx solid #ededed;
  border-radius: 16rpx;
  padding: 20rpx;
  max-width: 85%;
  font-size: 28rpx;
  line-height: 1.7;
  white-space: pre-wrap;
  overflow-wrap: anywhere;
}
.mine .bubble {
  background: linear-gradient(90deg, #4bc8ff, #216cf7);
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
  border-radius: 44rpx;
  padding: 0 24rpx;
}
.chat-input button {
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 0 26rpx;
  font-size: 26rpx;
  margin: 0;
}
</style>
