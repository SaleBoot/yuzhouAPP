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
          /><view class="banner-caption">{{ item.name }}</view></view
        ></swiper-item
      >
    </swiper>
    <slot v-else />
    <view v-if="error" class="error small" @click="load"
      >{{ error }} · 点击重试</view
    >
  </view>
</template>
<script setup>
import { ref, watch } from "vue";
import { list, external, tab, notify, fallbackBoat } from "./api.js";
const props = defineProps({ placement: { type: String, default: "home" } }),
  items = ref([]),
  error = ref("");
async function load() {
  error.value = "";
  try {
    items.value = await list("banners", { placement: props.placement });
  } catch (e) {
    error.value = e.message;
  }
}
function open(item) {
  const url = item.target_url || "";
  if (/^https?:\/\//i.test(url)) return external(url);
  const page = url.match(/^\/pages\/([a-z-]+)(?:\?|$)/)?.[1];
  if (!page) return notify(new Error("此轮播入口尚未正确配置"));
  const tabs =
    import.meta.env.VITE_CLIENT === "broker"
      ? ["work", "customers", "orders", "profile"]
      : ["home", "classroom", "discover", "profile"];
  if (tabs.includes(page)) tab(page);
  else uni.navigateTo({ url });
}
watch(() => props.placement, load, { immediate: true });
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
  font-size: 32rpx;
  font-weight: 600;
}
</style>
