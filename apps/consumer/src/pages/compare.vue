<template>
  <view class="page"
    ><view class="title">{{ t('船艇对比') }}</view
    ><view class="subtitle">{{ t('相同角度，更好做决定') }}</view
    ><view v-if="error" class="error">{{ t(error) }}</view
    ><scroll-view scroll-x
      ><view class="comparison"
        ><view class="compare-col" v-for="boat in boats" :key="boat.id"
          ><image
            :src="boat.image_url || fallbackBoat"
            mode="aspectFill"
          /><view class="card-head">{{ boat.name }}</view
          ><view v-if="mode === 'buy'" class="price">{{ t('实船报价') }}</view
          ><view v-else class="price"
            >¥{{ money(boat.daily_price_cents || boat.price_cents) }}</view
          ><view v-for="field in fields" :key="field.key" class="compare-cell"
            ><text class="muted small">{{ t(field.label) }}</text
            ><view>{{ metricText(boat[field.key], t(field.unit), field.zeroUnknown) }}</view></view
          ><view v-for="field in parameterFields" :key="'parameter-' + field.key" class="compare-cell">
            <text class="muted small">{{ t(field.label) }}</text>
            <view>{{ boatParameterValue(boat, field.key) }}</view>
          </view>
          <view
            v-for="angle in ['总览', '正面', '俯视']"
            :key="angle"
            class="compare-cell"
            ><text class="muted small">{{ t(angle) }}</text
            ><image
              v-if="angleImage(boat, angle)"
              :src="angleImage(boat, angle)"
              mode="aspectFit"
              @click="preview(boat, angle)"
            /><view v-else class="small muted">{{ t('该角度图片待补充') }}</view></view
          ><button
            v-if="boat.vr_url || boat.model_url || boat.three_d_url"
            class="secondary"
            @click="external(boat.vr_url || boat.model_url || boat.three_d_url)"
          >{{ t('3D 看船') }}</button
          ><button
            class="primary section"
            @click="go('boat', { id: boat.id, mode })"
          >{{ t('查看船艇') }}</button></view
        ></view
      ></scroll-view
    ><view v-if="!boats.length && !error" class="empty"
      >{{ t('在首页选择两至三艘船艇进行对比。') }}</view
    ></view
  >
</template>
<script setup>
import { t } from "@shared/i18n.js";
import { ref, computed } from "vue";
import { onLoad } from "@dcloudio/uni-app";
import { detail, go, external, fallbackBoat } from "@shared/api.js";
import { money } from "@shared/domain.js";
import { comparisonParameterFields, boatParameterValue, metricText } from "@shared/boat-parameters.js";
const boats = ref([]),
  mode = ref("rent"),
  error = ref("");
const fields = computed(() => [
  { key: "model_code", label: "船型型号", unit: "" },
  { key: "length_m", label: "全长", unit: "m", zeroUnknown: true },
  { key: "width_m", label: "宽度", unit: "m", zeroUnknown: true },
  { key: "capacity", label: "核定人数", unit: "人", zeroUnknown: true },
  ...(boats.value.some(boat => boat.speed_knots !== null && boat.speed_knots !== undefined && boat.speed_knots !== "") ? [{ key: "speed_knots", label: "航速", unit: "节" }] : []),
  { key: "category", label: "船艇类型", unit: "" },
]);
const parameterFields = computed(() => comparisonParameterFields(boats.value));
onLoad(async (q) => {
  mode.value = q.mode || "rent";
  try {
    boats.value = await Promise.all(
      (q.ids || "")
        .split(",")
        .filter(Boolean)
        .slice(0, 3)
        .map((id) => detail("boat_types", id)),
    );
  } catch (e) {
    error.value = e.message;
  }
});
function preview(boat, angle) {
  uni.previewImage({ urls: [angleImage(boat, angle)] });
}
function angleImage(boat, angle) {
  return (
    boat.comparison_images?.[
      angle === "总览" ? "overview" : angle === "正面" ? "front" : "top"
    ] ||
    boat.image_urls?.[angle === "总览" ? 0 : angle === "正面" ? 1 : 2] ||
    (angle === "总览" ? boat.image_url || fallbackBoat : "")
  );
}
</script>
<style scoped>
.comparison {
  display: flex;
  min-width: 100%;
  gap: 16rpx;
}
.compare-col {
  min-width: 300rpx;
  max-width: 360rpx;
  background: white;
  padding: 20rpx;
  border-radius: 18rpx;
}
.compare-col > image {
  width: 100%;
  height: 180rpx;
  border-radius: 12rpx;
}
.compare-cell {
  padding: 20rpx 0;
  border-top: 1rpx solid #edf0f3;
  line-height: 1.8;
}
.compare-cell image {
  width: 100%;
  height: 200rpx;
}
</style>
