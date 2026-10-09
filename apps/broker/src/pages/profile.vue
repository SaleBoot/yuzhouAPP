<template>
  <view class="page"
    ><view class="row section"
      ><text class="avatar">{{ (user?.nickname || "销")[0] }}</text
      ><view
        ><view class="title">{{ user?.nickname || "登录销售账号" }}</view
        ><view class="small muted">{{
          user?.username || "由平台分配销售身份"
        }}</view></view
      ></view
    ><view class="hero section"
      ><view class="card-head">驭舟销售</view
      ><view class="small">{{
        user?.org_name || `所属销售公司 ${user?.org_id || "待关联"}`
      }}</view></view
    ><view class="card section"
      ><view class="list-item" @click="go('commission')"
        ><text>佣金结算</text><text>›</text></view
      ><view class="list-item" @click="go('reports')"
        ><text>销售统计与Excel报表</text><text>›</text></view
      ><view class="list-item" @click="go('chat')"
        ><text>咨询消息</text><text>›</text></view
      ><view class="list-item" @click="go('appointment')"
        ><text>带看预约</text><text>›</text></view
      ><view class="list-item" @click="tab('customers')"
        ><text>我的客户</text><text>›</text></view
      ><view class="list-item" @click="tab('orders')"
        ><text>合同与履约</text><text>›</text></view
      ></view
    ><PhoneBinding v-if="user" :user="user" @bound="refreshUser" />
    <view class="notice small section"
      >这是销售人员工作端。机构管理、规则配置与审核由平台后台处理。</view
    ><button class="secondary section" @click="user ? logout() : go('login')">
      {{ user ? "退出登录" : "销售登录" }}</button
    ><view v-if="error" class="error">{{ error }}</view></view
  >
</template>
<script setup>
import { ref } from "vue";
import { onShow } from "@dcloudio/uni-app";
import { request, post, session, saveSession, go, tab } from "@shared/api.js";
import PhoneBinding from "@shared/PhoneBinding.vue";
const user = ref(session()?.user),
  error = ref("");
async function refreshUser() {
  error.value = "";
  user.value = session()?.user;
  if (user.value)
    try {
      const u = await request("/auth/me");
      user.value = u.user || u;
      saveSession({ ...session(), user: user.value });
    } catch (e) {
      error.value = e.message;
    }
}
onShow(refreshUser);
async function logout() {
  try {
    await post("/auth/logout");
  } catch {}
  saveSession(null);
  user.value = null;
  go("login");
}
</script>
