<template>
  <view class="page"
    ><view class="title">{{ t("销售订单") }}</view
    ><view class="row spread"
      ><view class="subtitle">{{ t("报价到交付，全程跟进") }}</view
      ><text class="link" @click="go('quote')">{{ t("＋ 代客下单") }}</text></view
    ><OrderList ref="list"
  /></view>
</template>
<script setup>
import { t } from "@shared/i18n.js";
import { ref } from "vue";
import { onShow } from "@dcloudio/uni-app";
import OrderList from "@shared/OrderList.vue";
import { go } from "@shared/api.js";
const list = ref(null);
onShow(() => list.value?.load());
</script>
