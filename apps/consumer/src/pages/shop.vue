<template>
  <view class="page"
    ><view class="title">航海生活</view
    ><view class="subtitle">从船艇配置，到每一次出行</view
    ><BannerStrip placement="shop" />
    <view class="chips"
      ><text
        v-for="tab in tabs"
        :key="tab.kind"
        :class="['chip', { active: kind === tab.kind }]"
        @click="
          kind = tab.kind;
          load();
        "
        >{{ tab.title }}</text
      ></view
    ><view v-if="error" class="error"
      >{{ error }} <text @click="load">重试</text></view
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
                ? "免费活动"
                : "询价"
          }}</text
          ><text class="round-action">↗</text></view
        ></view
      ></view
    ><view v-if="!items.length && !error" class="empty">{{
      loading ? "正在加载…" : "暂无已发布内容"
    }}</view></view
  >
</template>
<script setup>
import { ref } from "vue";
import { onLoad } from "@dcloudio/uni-app";
import BannerStrip from "@shared/BannerStrip.vue";
import { list, go, fallbackBoat } from "@shared/api.js";
import { money } from "@shared/domain.js";
const tabs = [
  { kind: "products", title: "周边商城" },
  { kind: "upgrades", title: "升级改装" },
  { kind: "maintenance", title: "保养维修" },
  { kind: "events", title: "航海活动" },
];
const kind = ref("products"),
  items = ref([]),
  error = ref(""),
  loading = ref(false);
onLoad((q) => {
  kind.value = q.kind || "products";
  load();
});
async function load() {
  loading.value = true;
  error.value = "";
  try {
    items.value = await list(kind.value);
  } catch (e) {
    error.value = e.message;
  } finally {
    loading.value = false;
  }
}
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
