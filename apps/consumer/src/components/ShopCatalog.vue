<template>
  <view class="page"
    ><view class="title">{{ t(title) }}</view
    ><view class="subtitle">{{ t('从船艇配置，到每一次出行') }}</view
    ><BannerStrip placement="shop" />
    <view v-if="tabs.length > 1" class="chips"
      ><text
        v-for="tab in tabs"
        :key="tab.kind"
        :class="['chip', { active: kind === tab.kind }]"
        @click="selectKind(tab.kind)"
        >{{ t(tab.title) }}</text
      ></view
    ><view v-if="error" class="error"
      >{{ t(error) }} <text @click="load">{{ t('重试') }}</text></view
    ><view class="grid"
      ><view
        v-for="item in items"
        :key="item.id"
        class="card product-card"
        @click="open(item)"
        ><image :src="item.image_url || fallbackBoat" mode="aspectFill" /><view
          class="card-head"
          >{{ item.name }}</view
        ><view class="small muted">{{
          item.location || item.description?.slice(0, 45)
        }}</view
        ><view class="row spread section"
          ><text class="price">{{
            item.price_cents
              ? `¥${money(item.price_cents)}`
              : kind === "events"
                ? t('免费活动')
                : t('询价')
          }}</text
          ><text class="round-action">↗</text></view
        ></view
      ></view
    ><view v-if="!items.length && !error" class="empty">{{
      loading ? t('正在加载…') : t('暂无已发布内容')
    }}</view></view
  >
</template>
<script setup>
import { t } from "@shared/i18n.js";
import { ref, computed, watch, onMounted } from "vue";
import BannerStrip from "@shared/BannerStrip.vue";
import { list, go, fallbackBoat } from "@shared/api.js";
import { money } from "@shared/domain.js";
const props = defineProps({
  title: { type: String, required: true },
  kinds: { type: Array, required: true },
  initialKind: { type: String, default: "" },
});
const titles = { products: "周边商城", upgrades: "升级改装", maintenance: "保养维修", events: "航海活动" };
const tabs = computed(() => props.kinds.map(kind => ({ kind, title: titles[kind] })));
const kind = ref(props.kinds.includes(props.initialKind) ? props.initialKind : props.kinds[0]),
  items = ref([]), error = ref(""), loading = ref(false);
let loadSequence = 0;
async function load() {
  const sequence = ++loadSequence;
  loading.value = true;
  error.value = "";
  items.value = [];
  try {
    const result = await list(kind.value);
    if (sequence === loadSequence) items.value = result;
  } catch (e) {
    if (sequence === loadSequence) error.value = e.message;
  } finally {
    if (sequence === loadSequence) loading.value = false;
  }
}
function selectKind(next) {
  if (!props.kinds.includes(next)) return;
  kind.value = next;
  return load();
}
watch(() => props.initialKind, next => selectKind(props.kinds.includes(next) ? next : props.kinds[0]));
onMounted(load);
defineExpose({ load });
function open(item) {
  go(kind.value === "events" ? "event" : "product", {
    id: item.id,
    kind: kind.value,
  });
}
</script>
<style scoped>
.product-card {
  padding: 0;
  overflow: hidden;
}
.product-card > image {
  height: 210rpx;
  width: 100%;
}
.product-card > view {
  padding: 0 18rpx;
}
.product-card .card-head {
  padding-top: 20rpx;
  margin-bottom: 12rpx;
  font-size: 28rpx;
}
.product-card .section {
  padding-bottom: 22rpx;
}
</style>
