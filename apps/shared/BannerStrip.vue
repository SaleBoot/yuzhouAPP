<template>
  <view class="section">
    <swiper
      v-if="items.length"
      class="banners"
      :indicator-dots="items.length > 1"
      :autoplay="items.length > 1"
      :interval="5000"
      circular
    >
      <swiper-item v-for="item in items" :key="item.id"
        ><view class="banner" @click="open(item)"
          ><image
            :src="item.image_url || fallbackBoat"
            mode="aspectFill"
          /><view class="banner-caption"><text class="banner-title">{{ bannerText(item, getLocale()).title }}</text><text v-if="bannerText(item, getLocale()).description" class="banner-description">{{ bannerText(item, getLocale()).description }}</text></view></view
        ></swiper-item
      >
    </swiper>
    <slot v-else />
    <view v-if="error" class="error small" @click="load"
      >{{ t(error) }}{{ t("· 点击重试") }}</view
    >
  </view>
</template>
<script setup>
import { t, getLocale } from './i18n.js';

import { ref, watch } from "vue";
import { onShow } from "@dcloudio/uni-app";
import config from "@/pages.json";
import { bannerText, bannerTarget } from "./banner-model.js";
import { list, external, tab, notify, fallbackBoat } from "./api.js";
const props = defineProps({ placement: { type: String, default: "home" } }),
  items = ref([]),
  error = ref("");
let loadSequence = 0;
async function load() {
  const sequence = ++loadSequence;
  error.value = "";
  try {
    const result = await list("banners", { placement: props.placement });
    if (sequence === loadSequence) items.value = result;
  } catch (e) {
    if (sequence === loadSequence) { items.value = []; error.value = e.message; }
  }
}
function open(item) {
  const target = bannerTarget(item.target_url, config);
  if (!target) return notify(new Error(t("此轮播入口尚未正确配置")));
  if (target.external) return external(target.external);
  if (target.tab) tab(target.page);
  else uni.navigateTo({ url: target.url });
}
watch(() => props.placement, load, { immediate: true });
onShow(load);
</script>
<style scoped>
.banners {
  height: 340rpx;
  border-radius: 22rpx;
  overflow: hidden;
}
.banner {
  height: 100%;
  position: relative;
}
.banner image {
  width: 100%;
  height: 100%;
}
.banner-caption {
  position: absolute;
  bottom: 0;
  left: 0;
  right: 0;
  color: white;
  background: linear-gradient(transparent, #216cf7aa);
  padding: 40rpx 24rpx 24rpx;
  font-size: 27rpx;
  font-weight: 500;
  line-height: 1.35;
}
.banner-title, .banner-description {
  overflow: hidden;
  display: -webkit-box;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
  overflow-wrap: anywhere;
}
.banner-description {
  margin-top: 8rpx;
  font-size: 23rpx;
  font-weight: 400;
  line-height: 1.4;
}
</style>
