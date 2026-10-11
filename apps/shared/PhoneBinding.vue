<template>
  <view class="card section">
    <view class="card-head">{{ t("登录手机号") }}</view>
    <view v-if="user.phone_bound || bound" class="small muted section">{{ t("已绑定，可使用手机验证码登录。") }}</view>
    <view v-else>
      <view class="small muted section">{{ t("验证后绑定当前账号。已有绑定号码不能在此换绑。") }}</view>
      <button v-if="!open" class="secondary section" @click="open = true">{{ t("绑定登录手机号") }}</button>
      <SmsPanel v-else purpose="bind" @verified="finish" />
    </view>
  </view>
</template>
<script setup>
import { t } from './i18n.js';

import { ref, watch } from "vue";
import SmsPanel from "./SmsPanel.vue";
const props = defineProps({ user: { type: Object, required: true } });
const emit = defineEmits(["bound"]);
const open = ref(false), bound = ref(false);
watch(() => props.user.id, () => { open.value = false; bound.value = false; });
function finish(result) {
  if (result.bound !== true) return;
  bound.value = true;
  open.value = false;
  emit("bound");
}
</script>
