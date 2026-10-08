<template>
  <view class="login">
    <view class="brand">YUZHOU <text class="brand-cn">驭舟</text></view>
    <view class="login-welcome">{{
      broker ? "每一段航程，从你开始" : "驶向生活的另一面"
    }}</view>
    <view class="glass">
      <view class="title">{{
        registering ? "创建驭舟账号" : broker ? "销售登录" : "欢迎来到驭舟"
      }}</view>
      <view class="subtitle">{{
        registering
          ? "注册普通用户，销售身份由平台分配"
          : "使用账号开启你的航海之旅"
      }}</view>
      <view class="formline"
        ><text class="form-label">账号</text
        ><input
          v-model="form.username"
          placeholder="请输入账号"
          autocomplete="username"
      /></view>
      <view class="formline"
        ><text class="form-label">密码</text
        ><input
          v-model="form.password"
          :password="true"
          placeholder="至少8位密码"
      /></view>
      <view v-if="registering" class="formline"
        ><text class="form-label">昵称</text
        ><input v-model="form.nickname" placeholder="你希望我们如何称呼你"
      /></view>
      <view v-if="registering" class="formline"
        ><text class="form-label">邀请码（选填）</text
        ><input v-model="form.invite_code" placeholder="填写好友邀请码"
      /></view>
      <view class="row small agreement" @click="agreed = !agreed"
        ><text>{{ agreed ? "☑" : "☐" }}</text
        ><text>{{
          development
            ? "我了解这是开发测试环境"
            : "我已阅读并同意服务条款和隐私政策"
        }}</text></view
      >
      <button class="primary" :loading="busy" :disabled="busy" @click="submit">
        {{ registering ? "注册并登录" : "登录" }}
      </button>
      <view class="row spread section small"
        ><text class="link" @click="registering = !registering">{{
          registering ? "已有账号，去登录" : "注册新账号"
        }}</text
        ><text class="link" @click="sms">短信登录</text></view
      >
      <view v-if="error" class="error">{{ error }}</view>
      <view class="notice small"
        >本机开发测试请使用开发环境生成的测试账号。平台数据和外部能力由实际服务配置决定。</view
      >
    </view>
  </view>
</template>
<script setup>
import { reactive, ref, onMounted } from "vue";
import { post, saveSession, notify, tab, request } from "./api.js";
const development = ref(import.meta.env.DEV);
onMounted(async () => {
  try {
    const b = await request("/bootstrap");
    development.value = Boolean(b.is_test_environment);
  } catch {}
});
const props = defineProps({ broker: Boolean });
const form = reactive({
  username: "",
  password: "",
  nickname: "",
  invite_code: "",
});
const registering = ref(false),
  agreed = ref(false),
  busy = ref(false),
  error = ref("");
async function submit() {
  if (!agreed.value)
    return notify(
      new Error(
        development.value
          ? "请先确认开发测试环境提示"
          : "请先同意服务条款和隐私政策",
      ),
    );
  if (!form.username.trim() || form.password.length < 8)
    return notify(new Error("请填写账号及至少8位密码"));
  busy.value = true;
  error.value = "";
  try {
    const result = registering.value
      ? await post("/auth/register", {
          ...form,
          nickname: form.nickname || form.username,
        })
      : await post("/auth/login", {
          username: form.username,
          password: form.password,
        });
    if (props.broker && result.user?.role !== "broker")
      throw new Error("该账号没有销售权限，请联系平台分配身份");
    if (!props.broker && result.user?.role !== "consumer")
      throw new Error("请使用普通用户账号登录消费端");
    saveSession(result);
    tab(props.broker ? "work" : "home");
  } catch (e) {
    error.value = e.message;
  } finally {
    busy.value = false;
  }
}
async function sms() {
  try {
    await post("/auth/sms", { phone: form.username });
  } catch (e) {
    notify(e);
  }
}
</script>
<style scoped>
.brand {
  font-size: 52rpx;
  letter-spacing: 8rpx;
  font-weight: 700;
  margin: 70rpx auto 24rpx;
  max-width: 760rpx;
  color: white;
}
.brand-cn {
  font-size: 38rpx;
  letter-spacing: 2rpx;
  margin-left: 18rpx;
}
.login-welcome {
  color: #e9f6ff;
  font-size: 34rpx;
  margin-bottom: 90rpx;
}
.agreement {
  margin: 30rpx 0;
  font-size: 23rpx;
  align-items: flex-start;
  gap: 12rpx;
}
</style>
