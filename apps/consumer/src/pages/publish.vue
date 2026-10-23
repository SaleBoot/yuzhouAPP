<template>
  <view class="page"
    ><view class="title">{{ t('分享你的航程') }}</view
    ><view class="subtitle">{{ t('让更多人看到海上的美好') }}</view
    ><view class="card section"
      ><view class="formline"
        ><input
          v-model="title"
          :placeholder="t('给这段航程起个名字')"
          maxlength="100" /></view
      ><view class="formline">
        <textarea
          v-model="content"
          :placeholder="t('记录风景、经验，或一个航海故事…')"
          maxlength="10000"
        /></view
      ><view class="photo-row"
        ><image
          v-for="url in previews"
          :key="url"
          :src="media(url)"
          mode="aspectFill" /></view
      ><button class="secondary" :disabled="busy" @click="attach">{{ t('＋ 添加图片或视频') }}</button
      ><video v-if="videoPreview" class="section" :src="videoPreview" controls />
      <view v-if="video" class="notice small section">{{ t('已上传视频，审核通过后公开展示') }}</view></view
    ><view class="notice small section"
      >{{ t('动态提交后由平台审核，审核通过后将在发现页展示。') }}</view
    ><button
      class="primary section"
      :disabled="busy"
      :loading="busy"
      @click="submit"
    >{{ t('提交审核') }}</button></view
  >
</template>
<script setup>
import { t } from "@shared/i18n.js";
import { ref } from "vue";
import {
  post,
  chooseUpload,
  media,
  notify,
  requireLogin,
} from "@shared/api.js";
import { publicPostAttachment } from "@shared/post-boat.js";
const previews = ref([]);
const title = ref(""),
  content = ref(""),
  images = ref([]),
  video = ref(""),
  videoPreview = ref(""),
  busy = ref(false);
async function attach() {
  if (!requireLogin() || busy.value) return;
  busy.value = true;
  try {
    const f = await chooseUpload({ purpose: "post", private: false });
    const attachment = publicPostAttachment(f);
    if (attachment.video) {
      video.value = attachment.url;
      videoPreview.value = f.local_path || "";
    } else {
      images.value.push(attachment.url);
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
    return notify(new Error(t("请填写标题和内容")));
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
    uni.showToast({ title: t("已提交审核"), icon: "success" });
    uni.redirectTo({ url: "/pages/discover" });
  } catch (e) {
    notify(e);
  } finally {
    busy.value = false;
  }
}
</script>
