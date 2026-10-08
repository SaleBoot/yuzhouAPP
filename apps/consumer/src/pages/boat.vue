<template>
  <view v-if="boat" class="detail-page"
    ><swiper class="detail-swiper" :indicator-dots="images.length > 1"
      ><swiper-item v-for="img in images" :key="img"
        ><image
          :src="img"
          mode="aspectFill"
          class="cover"
          @click="preview(img)" /></swiper-item></swiper
    ><view class="page"
      ><view class="row spread"
        ><view class="title">{{ boat.name }}</view
        ><text class="link" @click="favorite">{{
          saved ? "♥ 已收藏" : "♡ 收藏"
        }}</text></view
      ><view v-if="mode === 'buy'" class="price section"
        >实船报价<text class="small muted"> · 选择船艇后查看售价</text></view
      ><view v-else class="price section"
        >¥{{ money(boat.daily_price_cents || boat.price_cents)
        }}<text class="small muted">/天</text></view
      ><view class="card section"
        ><view class="card-head">看船方式</view
        ><view class="actions"
          ><button
            v-if="boat.vr_url || boat.model_url || boat.three_d_url"
            class="primary"
            @click="external(boat.vr_url || boat.model_url || boat.three_d_url)"
          >
            本船 3D</button
          ><button class="primary" @click="external(vrBaseUrl)">
            3D 看船平台
          </button></view
        ></view
      ><view v-if="instance" class="card section"><view class="card-head">{{ instance.name }}</view><view class="small muted section">{{ instance.serial_number || "" }} · {{ instance.location || instance.city || "已录入位置的实船" }}</view><view class="price section">实船售价 ¥{{ money(instance.price_cents) }}</view></view
      ><view class="card section"
        ><view class="card-head">核心参数</view
        ><view class="grid stats"
          ><view
            ><text class="value">{{ boat.length_m || "—" }}m</text
            ><text class="muted small">全长</text></view
          ><view
            ><text class="value"
              >{{ boat.width_m || boat.parameters?.beam_m || "—" }}m</text
            ><text class="muted small">宽度</text></view
          ><view
            ><text class="value">{{ boat.capacity || "—" }}人</text
            ><text class="muted small">核定人数</text></view
          ><view
            ><text class="value">{{ boat.speed_knots || "—" }}节</text
            ><text class="muted small">最高航速</text></view
          ></view
        ></view
      ><view class="card section"
        ><view class="card-head">船艇介绍</view
        ><view class="article">{{ boat.description || "详情待商家完善" }}</view
        ><view class="small muted section">{{
          boat.location || boat.city || ""
        }}</view></view
      ><view class="card section"
        ><view class="card-head">专属选配</view
        ><view
          v-for="option in boat.options || []"
          :key="option.id || option.name"
          class="list-item"
          ><text>{{ option.name || option.title }}</text
          ><text>¥{{ money(option.price_cents) }}</text></view
        ><view v-if="!boat.options?.length" class="small muted"
          >配置和交付细节可向销售咨询。</view
        ><button
          class="secondary section"
          @click="go('appointment', { resource_id: boat.id })"
        >
          预约看船
        </button></view
      ></view
    ><view class="bottom-bar"
      ><text class="small" @click="go('chat', { resource_id: boat.id })"
        >咨询销售</text
      ><text class="small" @click="compare">加入对比</text
      ><button class="primary" @click="book">
        {{ mode === "buy" ? "配置购买" : "立即预约" }}
      </button></view
    ></view
  >
  <view v-else class="page"
    ><view class="empty">{{ error || "船艇加载中…" }}</view></view
  >
</template>
<script setup>
import { ref, computed } from "vue";
import { onLoad } from "@dcloudio/uni-app";
import {
  detail,
  request,
  post,
  go,
  external,
  notify,
  requireLogin,
  fallbackBoat,
} from "@shared/api.js";
import { money } from "@shared/domain.js";
const boat = ref(null),
  instance = ref(null),
  mode = ref("rent"),
  error = ref(""),
  saved = ref(false),
  vrBaseUrl = ref("");
const images = computed(() =>
  boat.value?.image_urls?.length ? boat.value.image_urls : [fallbackBoat],
);
onLoad(async (q) => {
  mode.value = q.mode || "rent";
  try {
    const [b, config] = await Promise.all([
      detail("boat_types", q.id),
      request("/bootstrap"),
    ]);
    if (q.instance_id) {
      const actual = await detail("boats", q.instance_id);
      if (Number(actual.boat_type_id) !== Number(b.id)) throw new Error("实船与船型不匹配，请返回地图重试");
      instance.value = actual;
    }
    boat.value = b;
    vrBaseUrl.value = config.vr_base_url || "";
    if (requireLoginSilent())
      post("/footprints", { resource_id: boat.value.id }).catch(() => {});
  } catch (e) {
    error.value = e.message;
  }
});
function requireLoginSilent() {
  return Boolean(uni.getStorageSync("yuzhou_consumer_session"));
}
async function favorite() {
  if (!requireLogin()) return;
  try {
    const r = await post("/favorites", { resource_id: boat.value.id });
    saved.value = r.active !== false;
    uni.showToast({
      title: saved.value ? "已收藏" : "已取消收藏",
      icon: "none",
    });
  } catch (e) {
    notify(e);
  }
}
function compare() {
  const ids = uni.getStorageSync("yuzhou_compare") || [];
  if (!ids.includes(boat.value.id)) {
    if (ids.length >= 3) return notify(new Error("一次最多对比三艘"));
    ids.push(boat.value.id);
    uni.setStorageSync("yuzhou_compare", ids);
  }
  go("compare", { ids: ids.join(","), mode: mode.value });
}
function preview(img) {
  uni.previewImage({ urls: images.value, current: img });
}
function book() {
  if (requireLogin())
    go("booking", {
      kind: "boat_types",
      id: boat.value.id,
      type: mode.value === "buy" ? "boat_purchase" : "boat_rental",
      instance_id: instance.value?.id,
    });
}
</script>
<style scoped>
.detail-swiper {
  height: 450rpx;
}
.detail-swiper image {
  width: 100%;
  height: 100%;
}
.stats {
  grid-template-columns: repeat(4, 1fr);
}
.stats view {
  display: flex;
  flex-direction: column;
  gap: 12rpx;
  text-align: center;
}
.value {
  font-weight: 700;
  font-size: 29rpx;
}
.bottom-bar button {
  flex: 1;
  margin: 0;
}
.bottom-bar {
  gap: 26rpx;
  max-width: 900rpx;
  margin: auto;
}
</style>
