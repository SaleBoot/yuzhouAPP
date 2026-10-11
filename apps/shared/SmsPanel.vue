<template>
  <view ref="fields">
    <view class="formline"><text class="form-label">{{ t("手机号") }}</text><input v-model="phone" type="text" inputmode="tel" maxlength="14" :aria-label="t('手机号')" :placeholder="t('11位手机号，可带+86')" :disabled="challenge.busy" /></view>
    <view class="formline"><text class="form-label">{{ t("验证码") }}</text><input :value="code" @input="code = String($event.detail.value)" type="number" inputmode="numeric" maxlength="6" :aria-label="t('短信验证码')" text-content-type="one-time-code" :placeholder="t('6位数字验证码')" :disabled="challenge.busy" /></view>
    <button class="secondary" :disabled="challenge.busy || resendSeconds > 0" :loading="challenge.busy" @click="send">{{ resendSeconds > 0 ? t('{seconds}秒后可重发', { seconds: resendSeconds }) : t("获取验证码") }}</button>
    <view v-if="purpose === 'register'" class="formline"><text class="form-label">{{ t("昵称") }}</text><input v-model="nickname" :aria-label="t('昵称')" :placeholder="t('你希望我们如何称呼你')" :disabled="challenge.busy" /></view>
    <view v-if="purpose === 'register'" class="formline"><text class="form-label">{{ t("邀请码（选填）") }}</text><input v-model="invitationCode" :aria-label="t('邀请码（选填）')" :placeholder="t('填写好友邀请码')" :disabled="challenge.busy" /></view>
    <view v-if="challenge.notice" class="notice small section" role="status">{{ t(challenge.notice) }}</view>
    <view v-if="challenge.challengeId" class="small muted section">{{ expiresSeconds > 0 ? t('验证码有效期剩余{seconds}秒', { seconds: expiresSeconds }) : t("验证码已过期，请重新获取") }}</view>
    <view v-if="!allowed" class="small muted section">{{ t("请先勾选上方确认选项") }}</view>
    <button class="primary section" :disabled="challenge.busy || !allowed" :loading="challenge.busy" @click="verify">{{ purpose === 'bind' ? t("验证并绑定手机号") : purpose === 'register' ? t("验证并注册") : t("验证码登录") }}</button>
    <view v-if="error" class="error" role="alert">{{ t(error) }}</view>
  </view>
</template>
<script setup>
import { t } from './i18n.js';

import { computed, onMounted, onUnmounted, onUpdated, reactive, ref, watch } from "vue";
import { post } from "./api.js";
import { smsChallenge, secondsUntil } from "./sms.js";
const props = defineProps({ purpose: { type: String, default: "login" }, allowed: { type: Boolean, default: true } });
const emit = defineEmits(["verified", "busy"]);
const phone = ref(""), code = ref(""), nickname = ref(""), invitationCode = ref(""), error = ref(""), now = ref(Date.now());
const challenge = reactive(smsChallenge(post));
const fields = ref(null);
const resendSeconds = computed(() => secondsUntil(challenge.resendAt, now.value));
const expiresSeconds = computed(() => secondsUntil(challenge.expiresAt, now.value));
let timer;
function labelInputs() {
  // #ifdef H5
  // Uni H5 puts inherited aria-label on its wrapper, so also label the focusable input.
  const element = fields.value?.$el || fields.value;
  element?.querySelectorAll?.("uni-input[aria-label]").forEach((wrapper) => {
    wrapper.querySelector("input")?.setAttribute("aria-label", wrapper.getAttribute("aria-label"));
  });
  // #endif
}
onMounted(() => { labelInputs(); timer = setInterval(() => { now.value = Date.now(); }, 1000); });
onUpdated(labelInputs);
onUnmounted(() => clearInterval(timer));
watch([phone, () => props.purpose], () => { challenge.invalidate(); code.value = ""; error.value = ""; });
watch(() => challenge.busy, (value) => emit("busy", value));
async function send() {
  error.value = "";
  try { await challenge.send(phone.value, props.purpose); now.value = Date.now(); }
  catch (e) { error.value = e.message; now.value = Date.now(); }
}
async function verify() {
  error.value = "";
  if (!props.allowed) { error.value = t("请先勾选确认选项"); return; }
  try {
    const result = await challenge.verify(phone.value, props.purpose, code.value, { nickname: nickname.value, invitation_code: invitationCode.value });
    code.value = "";
    emit("verified", result);
  } catch (e) { error.value = e.message; }
}
</script>
