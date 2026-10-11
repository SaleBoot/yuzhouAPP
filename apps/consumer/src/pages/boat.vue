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
        ><button class="secondary small favorite-control" :disabled="favoriteBusy" :aria-pressed="favoriteKnown ? String(saved) : undefined" @click="favorite">{{
          favoriteBusy ? t('读取收藏中…') : !favoriteKnown ? t('重试收藏状态') : saved ? t('♥ 已收藏') : t('♡ 收藏')
        }}</button></view
      ><view v-if="favoriteError" class="notice small section" role="alert">{{ favoriteError }}</view
      ><view v-if="boat.model_code" class="subtitle section">{{ t('船型型号 · {model}', { model: boat.model_code }) }}</view
      ><view v-if="mode === 'buy'" class="price section"
        >{{ t('实船报价') }}<text class="small muted">{{ t('· 选择船艇后查看售价') }}</text></view
      ><view v-else class="price section"
        >¥{{ money(boat.daily_price_cents || boat.price_cents)
        }}<text class="small muted">{{ t('/天') }}</text></view
      ><view class="card section"
        ><view class="card-head">{{ t('看船方式') }}</view
        ><view class="actions"
          ><button
            v-if="boat.vr_url || boat.model_url || boat.three_d_url"
            class="primary"
            @click="external(boat.vr_url || boat.model_url || boat.three_d_url)"
          >{{ t('本船 3D') }}</button
          ><button class="primary" @click="external(vrBaseUrl)">{{ t('3D 看船平台') }}</button></view
        ></view
      ><view v-if="instance" class="card section"><view class="card-head">{{ instance.name }}</view><view class="small muted section">{{ instance.serial_number || "" }} · {{ instance.location || instance.city || t('已录入位置的实船') }}</view><view class="price section">{{ t('实船售价 ¥{amount}', { amount: money(instance.price_cents) }) }}</view></view
      ><view class="card section"
        ><view class="card-head">{{ t('核心参数') }}</view
        ><view class="grid stats" :style="{ gridTemplateColumns: hasSpeed ? 'repeat(4, 1fr)' : 'repeat(3, 1fr)' }"
          ><view
            ><text class="value">{{ metricText(boat.length_m, 'm', true) }}</text
            ><text class="muted small">{{ t('全长') }}</text></view
          ><view
            ><text class="value"
              >{{ metricText(boat.width_m || boat.parameters?.beam_m, 'm', true) }}</text
            ><text class="muted small">{{ t('宽度') }}</text></view
          ><view
            ><text class="value">{{ metricText(boat.capacity, t('人'), true) }}</text
            ><text class="muted small">{{ t('核定人数') }}</text></view
          ><view v-if="hasSpeed"
            ><text class="value">{{ metricText(boat.speed_knots, t('节')) }}</text
            ><text class="muted small">{{ t('航速') }}</text></view
          ></view
        ><view v-for="parameter in parameters" :key="parameter.key" class="list-item">
          <text class="muted small">{{ t(parameter.label) }}</text>
          <text class="article">{{ parameter.value }}</text>
        </view>
        </view
      ><view class="card section"
        ><view class="card-head">{{ t('船艇介绍') }}</view
        ><view class="article">{{ boat.description || t('详情待商家完善') }}</view
        ><view class="small muted section">{{
          boat.location || boat.city || ""
        }}</view></view
      ><view class="card section"
        ><view class="card-head">{{ t('专属选配') }}</view
        ><view
          v-for="option in boat.options || []"
          :key="option.id || option.name"
          class="list-item"
          ><text>{{ option.name || option.title }}</text
          ><text>¥{{ money(option.price_cents) }}</text></view
        ><view v-if="!boat.options?.length" class="small muted"
          >{{ t('配置和交付细节可向销售咨询。') }}</view
        ><button
          class="secondary section"
          @click="go('appointment', { resource_id: boat.id })"
        >{{ t('预约看船') }}</button></view
      ></view
    ><view class="bottom-bar"
      ><text class="small" @click="go('chat', { resource_id: boat.id })"
        >{{ t('咨询销售') }}</text
      ><text class="small" @click="compare">{{ t('加入对比') }}</text
      ><button class="primary" @click="book">
        {{ mode === "buy" ? t('配置购买') : t('立即预约') }}
      </button></view
    ></view
  >
  <view v-else class="page"
    ><view class="empty">{{ error || t('船艇加载中…') }}</view></view
  >
</template>
<script setup>
import { t } from "@shared/i18n.js";
import { ref, computed } from "vue";
import { onLoad, onShow } from "@dcloudio/uni-app";
import {
  detail,
  request,
  post,
  go,
  external,
  notify,
  requireLogin,
  session,
  fallbackBoat,
} from "@shared/api.js";
import { money } from "@shared/domain.js";
import { findBoatFavorite, toggleBoatFavorite } from "@shared/post-boat.js";
import { boatParameterRows, metricText } from "@shared/boat-parameters.js";
const boat = ref(null),
  instance = ref(null),
  mode = ref("rent"),
  error = ref(""),
  favoriteId = ref(null),
  favoriteKnown = ref(false),
  favoriteBusy = ref(false),
  favoriteError = ref(""),
  vrBaseUrl = ref("");
const saved = computed(() => favoriteId.value !== null);
const parameters = computed(() => boatParameterRows(boat.value?.parameters));
const hasSpeed = computed(() => boat.value?.speed_knots !== null && boat.value?.speed_knots !== undefined && boat.value?.speed_knots !== "");
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
      if (Number(actual.boat_type_id) !== Number(b.id)) throw new Error(t("实船与船型不匹配，请返回地图重试"));
      instance.value = actual;
    }
    boat.value = b;
    vrBaseUrl.value = config.vr_base_url || "";
    if (session())
      post("/footprints", { resource_id: boat.value.id }).catch(() => {});
    await refreshFavorite();
  } catch (e) {
    error.value = e.message;
  }
});
onShow(() => { if (boat.value) refreshFavorite(); });
async function refreshFavorite() {
  if (favoriteBusy.value) return;
  favoriteError.value = "";
  favoriteKnown.value = false;
  favoriteId.value = null;
  const userId = session()?.user?.id;
  if (!userId) { favoriteKnown.value = true; return; }
  favoriteBusy.value = true;
  try {
    const row = await findBoatFavorite(request, boat.value.id);
    if (session()?.user?.id === userId) {
      favoriteId.value = row?.id || null;
      favoriteKnown.value = true;
    }
  } catch (e) {
    favoriteError.value = e.message;
  } finally {
    favoriteBusy.value = false;
  }
}
async function favorite() {
  if (!requireLogin() || favoriteBusy.value) return;
  if (!favoriteKnown.value) return refreshFavorite();
  favoriteBusy.value = true;
  favoriteError.value = "";
  try {
    const row = await toggleBoatFavorite(request, boat.value.id, favoriteId.value);
    favoriteId.value = row?.id || null;
    uni.showToast({
      title: t(saved.value ? "已收藏" : "已取消收藏"),
      icon: "none",
    });
  } catch (e) {
    favoriteKnown.value = false;
    favoriteError.value = e.message;
    notify(e);
  } finally {
    favoriteBusy.value = false;
  }
}
function compare() {
  const ids = uni.getStorageSync("yuzhou_compare") || [];
  if (!ids.includes(boat.value.id)) {
    if (ids.length >= 3) return notify(new Error(t("一次最多对比三艘")));
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
.favorite-control {
  flex-shrink: 0;
  white-space: nowrap;
  margin: 0;
  padding: 12rpx 20rpx;
  line-height: 1.8;
}
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
  font-weight: 500;
  font-size: 27rpx;
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
