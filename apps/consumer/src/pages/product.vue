<template>
  <view class="page" v-if="item"
    ><image
      :src="item.image_url || fallbackBoat"
      mode="aspectFill"
      class="cover"
    /><view class="title section">{{ item.name }}</view
    ><view class="price section">{{
      item.price_cents ? `¥${money(item.price_cents)}` : t('按需求报价')
    }}</view
    ><view class="card section"
      ><view class="card-head">{{
        kind === "products" ? t('商品介绍') : t('服务项目')
      }}</view
      ><view class="article">{{
        item.description || t('请向销售了解服务详情')
      }}</view
      ><view v-if="kind === 'products'" class="muted small section"
        >{{ t('平台自营') }} · {{
          item.stock !== undefined ? t('库存 {count}', { count: item.stock }) : t('以提交时库存为准')
        }}</view
      ></view
    ><view v-if="kind === 'upgrades'" class="card section">
      <view class="card-head">{{ t('升级效果对比') }}</view>
      <view v-if="boatTypeName" class="small muted section"
        >{{ t('适配船型 · {name}', { name: boatTypeName }) }}</view
      >
      <view class="parameter-row small muted"
        ><text>{{ t('参数') }}</text><text>{{ t('升级前') }}</text><text>{{ t('升级后') }}</text></view
      >
      <view v-for="row in parameterRows" :key="row.key" class="parameter-row"
        ><text>{{ t(row.label) }}</text
        ><text>{{ row.before }}</text
        ><text>{{ row.after }}</text></view
      >
      <view v-if="!parameterRows.length" class="small muted section"
        >{{ t('升级前后参数待服务方补充，可咨询销售确认适配与安装方案。') }}</view
      >
    </view>
    <view class="actions section"
      ><button v-if="kind === 'products'" class="secondary" @click="addCart">{{ t('加入购物车') }}</button
      ><button class="secondary" @click="go('chat', { resource_id: item.id })">{{ t('咨询') }}</button
      ><button class="primary" @click="buy">
        {{ kind === "products" ? t('立即购买') : t('提交需求') }}
      </button></view
    ></view
  ><view v-else class="page empty">{{ error || t('正在加载…') }}</view>
</template>
<script setup>
import { t } from "@shared/i18n.js";
import { ref, computed } from "vue";
import { onLoad } from "@dcloudio/uni-app";
import {
  detail,
  go,
  requireLogin,
  post,
  notify,
  fallbackBoat,
} from "@shared/api.js";
import { money } from "@shared/domain.js";
const item = ref(null),
  kind = ref("products"),
  error = ref(""),
  boatTypeName = ref("");
const parameterLabels = {
  length_m: "全长（m）",
  width_m: "宽度（m）",
  beam_m: "宽度（m）",
  capacity: "核定人数",
  speed_knots: "航速（节）",
  range_km: "续航（km）",
  power_kw: "动力（kW）",
  battery_kwh: "电池容量（kWh）",
  fuel_consumption: "油耗",
  engine: "主机",
  equipment: "设备",
};
const parameterRows = computed(() => {
  const before = item.value?.before_parameters || {},
    after = item.value?.after_parameters || {};
  return [...new Set([...Object.keys(before), ...Object.keys(after)])].map(
    (key) => ({
      key,
      label: parameterLabels[key] || key,
      before: formatParameter(before[key]),
      after: formatParameter(after[key]),
    }),
  );
});
function formatParameter(value) {
  if (value === undefined || value === null || value === "") return t("待补充");
  if (Array.isArray(value)) return value.map(formatParameter).join("、");
  if (typeof value === "object")
    return value.value !== undefined
      ? `${value.value}${value.unit || ""}`
      : t("待补充");
  return typeof value === "boolean" ? (value ? t("是") : t("否")) : String(value);
}
onLoad(async (q) => {
  kind.value = q.kind || "products";
  try {
    item.value = await detail(kind.value, q.id);
    if (kind.value === "upgrades" && item.value.boat_type_id) {
      const boatType = await detail("boat_types", item.value.boat_type_id);
      boatTypeName.value = boatType.name;
    }
  } catch (e) {
    error.value = e.message;
  }
});
async function addCart() {
  if (!requireLogin()) return;
  try {
    await post("/cart", { product_id: item.value.id, quantity: 1 });
    uni.showToast({ title: t("已加入购物车"), icon: "success" });
  } catch (e) {
    notify(e);
  }
}
function buy() {
  if (requireLogin())
    go("booking", {
      id: item.value.id,
      kind: kind.value,
      type:
        kind.value === "products"
          ? "mall"
          : kind.value === "upgrades"
            ? "boat_upgrade"
            : "maintenance",
    });
}
</script>
<style scoped>
.parameter-row {
  display: grid;
  grid-template-columns: 1.2fr 1fr 1fr;
  gap: 18rpx;
  padding: 22rpx 0;
  border-bottom: 1rpx solid #edf0f3;
  word-break: break-word;
}
</style>
