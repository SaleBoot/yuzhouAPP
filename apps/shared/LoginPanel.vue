<template>
  <view class="login">
    <view class="brand">YUZHOU <text class="brand-cn">{{ t("驭舟") }}</text></view>
    <view class="login-welcome">{{
      broker ? t("每一段航程，从你开始") : t("驶向生活的另一面")
    }}</view>
    <button class="secondary section" @click="tab(broker ? 'work' : 'home')">{{ t("返回首页") }}</button>
    <view class="glass">
      <view class="title">{{
        registering ? t("创建驭舟账号") : broker ? t("销售登录") : t("欢迎来到驭舟")
      }}</view>
      <view class="subtitle">{{
        registering
          ? t("注册普通用户，销售身份由平台分配")
          : mode === "sms" ? t("使用已绑定当前账号的手机号登录") : t("使用账号开启你的航海之旅")
      }}</view>
      <view class="row login-methods section">
        <button class="secondary small" :disabled="busy || smsBusy" @click="mode = 'password'">{{ t("账号密码") }}</button>
        <button class="secondary small" :disabled="busy || smsBusy" @click="mode = 'sms'">{{ t("手机验证码") }}</button>
      </view>
      <view v-if="mode === 'password'" class="formline"
        ><text class="form-label">{{ t("账号") }}</text
        ><input
          v-model="form.username"
          :placeholder="t('请输入账号')"
          :aria-label="t('账号')"
          autocomplete="username"
      /></view>
      <view v-if="mode === 'password'" class="formline"
        ><text class="form-label">{{ t("密码") }}</text
        ><input
          v-model="form.password"
          :password="true"
          :placeholder="t('至少8位密码')"
          :aria-label="t('密码')"
      /></view>
      <view v-if="registering && mode === 'password'" class="formline"
        ><text class="form-label">{{ t("昵称") }}</text
        ><input v-model="form.nickname" :placeholder="t('你希望我们如何称呼你')"
      /></view>
      <view v-if="registering && mode === 'password'" class="formline"
        ><text class="form-label">{{ t("邀请码（选填）") }}</text
        ><input v-model="form.invite_code" :placeholder="t('填写好友邀请码')"
      /></view>
      <view class="row small agreement" role="checkbox" :aria-checked="String(agreed)" tabindex="0" @click="agreed = !agreed" @keydown.enter="agreed = !agreed" @keydown.space.prevent="agreed = !agreed"
        ><text>{{ agreed ? "☑" : "☐" }}</text
        ><text>{{ t("我已阅读并同意服务条款和隐私政策") }}</text></view
      >
      <SmsPanel v-if="mode === 'sms'" :purpose="registering ? 'register' : 'login'" :allowed="agreed" @busy="smsBusy = $event" @verified="smsVerified" />
      <button v-else class="primary" :loading="busy" :disabled="busy" @click="submit">
        {{ registering ? t("注册并登录") : t("登录") }}
      </button>
      <view v-if="!broker" class="section small"
        ><button class="secondary small" :disabled="busy || smsBusy" @click="registering = !registering; error = ''">{{
          registering ? t("已有账号，去登录") : t("注册新账号")
        }}</button></view
      >
      <view v-if="error" class="error">{{ t(error) }}</view>
    </view>
  </view>
</template>
<script setup>
import { t } from './i18n.js';

import { reactive, ref } from "vue";
import { post, saveSession, notify, tab, request } from "./api.js";
import SmsPanel from "./SmsPanel.vue";
const props = defineProps({ broker: Boolean });
const form = reactive({
  username: "",
  password: "",
  nickname: "",
  invite_code: "",
});
const registering = ref(false),
  mode = ref("password"),
  smsBusy = ref(false),
  agreed = ref(false),
  busy = ref(false),
  error = ref("");
async function submit() {
  if (!agreed.value)
    return notify(new Error(t("请先同意服务条款和隐私政策")));
  if (!form.username.trim() || form.password.length < 8)
    return notify(new Error(t("请填写账号及至少8位密码")));
  busy.value = true;
  error.value = "";
  try {
    const result = registering.value
      ? await post("/auth/register", {
          username: form.username,
          password: form.password,
          nickname: form.nickname || form.username,
          invitation_code: form.invite_code || undefined,
        })
      : await post("/auth/login", {
          username: form.username,
          password: form.password,
        });
    await finishLogin(result);
  } catch (e) {
    error.value = e.message;
  } finally {
    busy.value = false;
  }
}
async function finishLogin(result) {
  const wrongRole = props.broker ? result.user?.role !== "broker" : result.user?.role !== "consumer";
  if (wrongRole) {
    const message = props.broker ? t("该账号没有销售权限，请联系平台分配身份") : t("请使用普通用户账号登录消费端");
    saveSession(null);
    try { await request("/auth/logout", { method: "POST", data: {}, authToken: result.token }); }
    catch { throw new Error(t("{message}；登录授权撤销失败，请检查网络后重试", { message })); }
    throw new Error(message);
  }
  saveSession(result);
  tab(props.broker ? "work" : "home");
}
async function smsVerified(result) {
  error.value = "";
  try {
    await finishLogin(result);
  } catch (e) {
    error.value = e.message;
  }
}
</script>
<style scoped>
.brand {
  font-size: 44rpx;
  letter-spacing: 4rpx;
  font-weight: 700;
  margin: 24rpx auto;
  max-width: 686rpx;
  color: #216cf7;
}
.brand-cn {
  font-size: 32rpx;
  letter-spacing: 2rpx;
  margin-left: 18rpx;
}
.login-welcome {
  color: #666;
  font-size: 30rpx;
  margin-bottom: 48rpx;
}
.agreement {
  margin: 28rpx 0;
  font-size: 24rpx;
  align-items: flex-start;
  gap: 12rpx;
}
.login-methods { gap: 16rpx; }
.login-methods button { flex: 1; }
</style>
