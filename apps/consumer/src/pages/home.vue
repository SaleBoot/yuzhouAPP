<template>
  <view class="page home">
    <view class="row spread"
      ><view
        ><text class="small muted">YUZHOU · 航海生活</text
        ><view class="title">今天，向海出发</view></view
      ><text class="avatar small" @click="tab('profile')">驭舟</text></view
    >
    <view class="row section"
      ><input
        v-model="search"
        class="search"
        placeholder="搜索船型、目的地"
        @confirm="load"
      /><text class="link" @click="load">搜索</text></view
    >
    <BannerStrip placement="home"
      ><view class="hero section"
        ><image :src="fallbackBoat" mode="aspectFill" class="hero-image" /><view
          class="hero-copy"
          ><text class="hero-kicker">YOUR NEXT VOYAGE</text
          ><view class="hero-title">把日常，交给海风</view
          ><text class="hero-sub">精选船艇 · 轻松预约</text></view
        ></view
      ></BannerStrip
    >
    <view class="row spread section" @click="external(vrBaseUrl)"
      ><text class="card-head">智能 3D 看船</text
      ><text class="link small">进入看船平台 ↗</text></view
    >
    <view class="row spread section" @click="go('map')"><text class="card-head">地图找船</text><text class="link small">查看船艇位置 ›</text></view>
    <view class="grid scene-grid"
      ><view v-for="s in scenes" :key="s.label" class="scene" @click="s.action"
        ><text class="tile-icon">{{ s.icon }}</text
        ><text>{{ s.label }}</text></view
      ></view
    >
    <view class="row spread section"
      ><view class="tabs"
        ><text :class="{ active: mode === 'rent' }" @click="mode = 'rent'"
          >租船</text
        ><text :class="{ active: mode === 'buy' }" @click="mode = 'buy'"
          >买船</text
        ></view
      ><text class="small link" @click="horizontal = !horizontal">{{
        horizontal ? "▦ 卡片" : "☷ 列表"
      }}</text></view
    >
    <view class="chips"
      ><text
        v-for="cat in categories"
        :key="cat"
        :class="['chip', { active: category === cat }]"
        @click="category = cat"
        >{{ cat }}</text
      ><text
        v-if="mode === 'rent'"
        :class="['chip', { active: cheap }]"
        @click="cheap = !cheap"
        >价格从低到高</text
      ></view
    >
    <view v-if="error" class="error"
      >{{ error }} <text @click="load">重试</text></view
    >
    <view v-if="!displayed.length && !error" class="empty">{{
      loading ? "正在寻找你的航程…" : "暂无已发布船艇"
    }}</view>
    <view :class="horizontal ? 'boat-list' : 'grid'"
      ><BoatCard
        v-for="boat in displayed"
        :key="boat.id"
        :boat="boat"
        :horizontal="horizontal"
        :mode="mode"
        :compare="true"
        :selected="compared.includes(boat.id)"
        @open="go('boat', { id: boat.id, mode })"
        @compare="toggle(boat.id)"
    /></view>
    <view v-if="compared.length" class="compare-dock"
      ><text>已选 {{ compared.length }} 艘</text
      ><text class="small" @click="clear">清空</text
      ><button
        class="primary"
        @click="go('compare', { ids: compared.join(','), mode })"
      >
        开始对比
      </button></view
    >
    <view v-if="recommended.length" class="section"><view class="card-head">{{ personalized ? "根据收藏和浏览为你推荐" : "精选船艇" }}</view><view class="grid section"><BoatCard v-for="boat in recommended" :key="boat.id" :boat="boat" :mode="mode" @open="go('boat', { id: boat.id, mode })" /></view></view>
    <view v-if="news.length" class="card section"
      ><view class="card-head">航海资讯</view
      ><view
        v-for="item in news"
        :key="item.id"
        class="list-item"
        @click="go('post', { id: item.id })"
        ><text>{{ item.is_pinned ? "置顶 · " : "" }}{{ item.name }}</text
        ><text class="muted">›</text></view
      ></view
    >
  </view>
</template>
<script setup>
import { ref, computed } from "vue";
import { onShow } from "@dcloudio/uni-app";
import BoatCard from "@shared/BoatCard.vue";
import BannerStrip from "@shared/BannerStrip.vue";
import {
  list,
  request,
  go,
  tab,
  external,
  notify,
  fallbackBoat,
  resource,
} from "@shared/api.js";
const boats = ref([]),
  loading = ref(false),
  error = ref(""),
  search = ref(""),
  mode = ref("rent"),
  horizontal = ref(false),
  category = ref("全部"),
  cheap = ref(false),
  compared = ref([]),
  vrBaseUrl = ref("");
const news = ref([]);
const recommended = ref([]), personalized = ref(false);
const categories = computed(() => [
  "全部",
  ...new Set(boats.value.map((b) => b.category).filter(Boolean)),
]);
const displayed = computed(() => {
  const r = boats.value.filter(
    (b) => category.value === "全部" || b.category === category.value,
  );
  return cheap.value && mode.value === "rent"
    ? [...r].sort(
        (a, b) =>
          (a.daily_price_cents || a.price_cents) -
          (b.daily_price_cents || b.price_cents),
      )
    : r;
});
const scenes = [
  {
    label: "船艇租售",
    icon: "⌁",
    action: () => {
      mode.value = "rent";
    },
  },
  {
    label: "航海活动",
    icon: "⚑",
    action: () => go("shop", { kind: "events" }),
  },
  {
    label: "升级维修",
    icon: "⚙",
    action: () => go("shop", { kind: "upgrades" }),
  },
  {
    label: "周边商城",
    icon: "▧",
    action: () => go("shop", { kind: "products" }),
  },
];
async function load() {
  loading.value = true;
  error.value = "";
  try {
    const [items, config, posts, picks] = await Promise.all([
      list("boat_types", { search: search.value }),
      request("/bootstrap"),
      list("posts"),
      request("/recommendations?limit=6"),
    ]);
    boats.value = items;
    vrBaseUrl.value = config.vr_base_url || "";
    news.value = posts.filter((x) => x.post_type === "news").slice(0, 3);
    recommended.value = (picks.items || []).map(resource);
    personalized.value = Boolean(picks.personalized);
  } catch (e) {
    error.value = e.message;
  } finally {
    loading.value = false;
  }
}
function toggle(id) {
  if (compared.value.includes(id))
    compared.value = compared.value.filter((x) => x !== id);
  else if (compared.value.length < 3) compared.value.push(id);
  else notify(new Error("一次最多对比三艘船艇"));
  uni.setStorageSync("yuzhou_compare", compared.value);
}
function clear() {
  compared.value = [];
  uni.removeStorageSync("yuzhou_compare");
}
onShow(() => {
  compared.value = uni.getStorageSync("yuzhou_compare") || [];
  load();
});
</script>
<style scoped>
.hero {
  height: 310rpx;
  position: relative;
  padding: 0;
  overflow: hidden;
  background: #174563;
}
.hero-image {
  width: 100%;
  height: 100%;
  opacity: 0.7;
}
.hero-copy {
  position: absolute;
  inset: 32rpx;
  z-index: 1;
}
.hero-title {
  font-size: 40rpx;
  font-weight: 700;
  margin: 12rpx 0;
}
.hero-sub {
  font-size: 24rpx;
  color: #e1eef4;
}
.hero-kicker {
  font-size: 19rpx;
  letter-spacing: 3rpx;
}
.scene-grid {
  grid-template-columns: repeat(4, 1fr);
  gap: 12rpx;
  margin: 32rpx 0;
}
.search {
  background: white;
  border-radius: 18rpx;
  padding: 22rpx 24rpx;
  flex: 1;
  font-size: 26rpx;
}
.compare-dock {
  position: fixed;
  bottom: calc(110rpx + env(safe-area-inset-bottom));
  left: 20rpx;
  right: 20rpx;
  max-width: 840rpx;
  margin: auto;
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 18rpx 24rpx;
  box-shadow: 0 8rpx 28rpx #09233f22;
  background: white;
  border-radius: 20rpx;
  z-index: 6;
}
.compare-dock button {
  margin: 0;
  padding: 0 26rpx;
  font-size: 24rpx;
}
.tabs text {
  font-size: 34rpx;
  font-weight: 700;
  margin-right: 32rpx;
  color: #95a0ab;
}
.tabs text.active {
  color: #09233f;
  border-bottom: 6rpx solid #f4c44f;
  padding-bottom: 12rpx;
}
</style>
