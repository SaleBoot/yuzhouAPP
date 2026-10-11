<template>
  <view class="page"
    ><view class="title">{{ t('我的航程') }}</view
    ><view class="subtitle">{{ t('订单、合同与支付进度') }}</view><OrderList ref="list"
  /></view>
</template>
<script setup>
import { t } from "@shared/i18n.js";
import { ref } from "vue";
import { onShow } from "@dcloudio/uni-app";
import OrderList from "@shared/OrderList.vue";
const list = ref(null);
onShow(() => list.value?.load());
</script>
