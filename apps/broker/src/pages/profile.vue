<template>
  <view class="page"
    ><view class="row section"
      ><text class="avatar">{{ (user?.nickname || t("销"))[0] }}</text
      ><view
        ><view class="title">{{ user?.nickname || t("登录销售账号") }}</view
        ><view class="small muted">{{
          user?.username || t("由平台分配销售身份")
        }}</view></view
      ></view
    ><view class="hero section"
      ><view class="card-head">{{ t("驭舟销售") }}</view
      ><view class="small">{{
        user?.org_name || t("所属销售公司 {id}", { id: user?.org_id || t("待关联") })
      }}</view></view
    ><view class="card section"
      ><view class="list-item" @click="go('commission')"
        ><text>{{ t("佣金结算") }}</text><text>›</text></view
      ><view class="list-item" @click="go('reports')"
        ><text>{{ t("销售统计与Excel报表") }}</text><text>›</text></view
      ><view class="list-item" @click="go('chat')"
        ><text>{{ t("咨询消息") }}</text><text>›</text></view
      ><view class="list-item" @click="go('appointment')"
        ><text>{{ t("带看预约") }}</text><text>›</text></view
      ><view class="list-item" @click="tab('customers')"
        ><text>{{ t("我的客户") }}</text><text>›</text></view
      ><view class="list-item" @click="tab('orders')"
        ><text>{{ t("合同与履约") }}</text><text>›</text></view
      ><view class="list-item" @click="go('viewer', { url: 'https://yuzhou.ourideas.top/app-updates/' })"
        ><text>{{ t("应用更新") }}</text><text>›</text></view
      ></view
    ><LanguagePicker /><PhoneBinding v-if="user" :user="user" @bound="refreshUser" />
    <view class="notice small section"
      >{{ t("这是销售人员工作端。机构管理、规则配置与审核由平台后台处理。") }}</view
    ><button class="secondary section" @click="user ? logout() : go('login')">
      {{ user ? t("退出登录") : t("销售登录") }}</button
    ><view v-if="error" class="error">{{ t(error) }}</view></view
  >
</template>
<script setup>
import { ref } from "vue";
import { onShow } from "@dcloudio/uni-app";
import { t } from "@shared/i18n.js";
import { request, post, session, saveSession, go, tab } from "@shared/api.js";
import LanguagePicker from "@shared/LanguagePicker.vue";
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
