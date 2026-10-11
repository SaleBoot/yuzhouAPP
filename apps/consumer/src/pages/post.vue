<template>
  <view class="page"
    ><view v-if="error" class="error">{{ t(error) }}</view
    ><template v-if="item"
      ><view class="title"
        ><text v-if="item.is_pinned" class="badge">{{ t('置顶') }}</text>
        {{ item.name }}</view
      ><view class="subtitle"
        >{{ item.author_name || t('航海爱好者') }} ·
        {{ dateTime(item.created_at) }}</view
      ><view class="card section"
        ><view class="article">{{ item.content }}</view
        ><image
          v-for="url in item.image_urls || []"
          :key="url"
          :src="url"
          mode="widthFix"
          class="post-image"
          @click="preview(url)"
        /><video
          v-if="item.video_url"
          :src="media(item.video_url)"
          controls
          class="post-image"
        /><button class="secondary section" :disabled="busy" @click="like">
          {{ item.liked ? t('♥ 已点赞') : t('♡ 点赞') }} · {{ item.like_count || 0 }}
        </button></view
      ><view v-if="officialReplies.length" class="card section"
        ><view class="card-head">{{ t('官方回复') }}</view
        ><view
          v-for="reply in officialReplies"
          :key="reply.id"
          class="list-item"
          ><view
            ><view class="article">{{ reply.content }}</view
            ><text class="small muted">{{
              dateTime(reply.created_at)
            }}</text></view
          ></view
        ></view
      ><view class="card section"
        ><view class="card-head">{{ t('航海交流') }}</view
        ><view v-for="comment in comments" :key="comment.id" class="list-item"
          ><view
            ><view class="small muted">{{
              comment.author_name || comment.nickname || t('驭舟用户')
            }}</view
            ><view class="article">{{ comment.content }}</view></view
          ></view
        ><view v-if="!comments.length" class="muted small">{{ t('暂时没有评论。') }}</view
        ><view class="formline section">
          <textarea
            v-model="content"
            :placeholder="t('分享你的想法')"
            maxlength="1000"
          /></view
        ><button class="primary" :disabled="busy" @click="send">{{ t('发表评论') }}</button></view
      ></template
    ></view
  >
</template>
<script setup>
import { t } from "@shared/i18n.js";
import { ref } from "vue";
import { onLoad } from "@dcloudio/uni-app";
import {
  detail,
  request,
  post,
  media,
  notify,
  requireLogin,
} from "@shared/api.js";
import { dateTime } from "@shared/domain.js";
const item = ref(null),
  comments = ref([]),
  officialReplies = ref([]),
  content = ref(""),
  error = ref(""),
  busy = ref(false);
onLoad(async (q) => {
  try {
    item.value = await detail("posts", q.id);
    await loadComments();
  } catch (e) {
    error.value = e.message;
  }
});
async function loadComments() {
  const [r, official] = await Promise.all([
    request(`/posts/${item.value.id}/comments`),
    request(`/posts/${item.value.id}/official-replies`),
  ]);
  comments.value = r.items || r || [];
  officialReplies.value = official.items || [];
}
async function like() {
  if (!requireLogin() || busy.value) return;
  busy.value = true;
  try {
    const r = await request(`/posts/${item.value.id}/like`, {
      method: item.value.liked ? "DELETE" : "POST",
      data: {},
    });
    item.value.liked = r.liked;
    item.value.like_count = r.like_count;
  } catch (e) {
    notify(e);
  } finally {
    busy.value = false;
  }
}
async function send() {
  if (!requireLogin() || busy.value) return;
  if (!content.value.trim()) return notify(new Error(t("请填写评论")));
  busy.value = true;
  try {
    await post(`/posts/${item.value.id}/comments`, { content: content.value });
    content.value = "";
    await loadComments();
  } catch (e) {
    notify(e);
  } finally {
    busy.value = false;
  }
}
function preview(url) {
  uni.previewImage({ urls: item.value.image_urls, current: url });
}
</script>
<style scoped>
.post-image {
  width: 100%;
  margin: 20rpx 0;
  border-radius: 14rpx;
}
</style>
