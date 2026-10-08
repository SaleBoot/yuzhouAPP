<template>
  <view class="page"
    ><view class="title">分享你的航程</view
    ><view class="subtitle">让更多人看到海上的美好</view
    ><view class="card section"
      ><view class="formline"
        ><input
          v-model="title"
          placeholder="给这段航程起个名字"
          maxlength="100" /></view
      ><view class="formline">
        <textarea
          v-model="content"
          placeholder="记录风景、经验，或一个航海故事…"
          maxlength="10000"
        /></view
      ><view class="photo-row"
        ><image
          v-for="url in previews"
          :key="url"
          :src="media(url)"
          mode="aspectFill" /></view
      ><button class="secondary" :disabled="busy" @click="attach">
        ＋ 添加图片或视频</button
      ><view v-if="video" class="notice small section">已上传视频</view></view
    ><view class="notice small section"
      >动态提交后由平台审核，审核通过后将在发现页展示。</view
    ><button
      class="primary section"
      :disabled="busy"
      :loading="busy"
      @click="submit"
    >
      提交审核
    </button></view
  >
</template>
<script setup>
import { ref } from "vue";
import {
  post,
  chooseUpload,
  media,
  notify,
  requireLogin,
  tab,
} from "@shared/api.js";
const previews = ref([]);
const title = ref(""),
  content = ref(""),
  images = ref([]),
  video = ref(""),
  busy = ref(false);
async function attach() {
  if (!requireLogin() || busy.value) return;
  busy.value = true;
  try {
    const f = await chooseUpload({ purpose: "post" });
    if (
      (f.mime || f.mime_type)?.startsWith("video/") ||
      f.original_name?.endsWith(".mp4")
    )
      video.value = f.url;
    else {
      images.value.push(f.url);
      previews.value.push(f.local_path || media(f.url));
    }
  } catch (e) {
    notify(e);
  } finally {
    busy.value = false;
  }
}
async function submit() {
  if (!requireLogin() || busy.value) return;
  if (!title.value.trim() || !content.value.trim())
    return notify(new Error("请填写标题和内容"));
  busy.value = true;
  try {
    await post("/resources/posts", {
      title: title.value,
      payload: {
        content: content.value,
        image_urls: images.value,
        video_url: video.value,
      },
    });
    uni.showToast({ title: "已提交审核", icon: "success" });
    tab("discover");
  } catch (e) {
    notify(e);
  } finally {
    busy.value = false;
  }
}
</script>
