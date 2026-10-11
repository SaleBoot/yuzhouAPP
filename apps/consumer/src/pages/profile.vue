<template>
  <view class="page"
    ><view class="profile"
      ><view class="row"
        ><text class="avatar">{{ (user?.nickname || t('舟'))[0] }}</text
        ><view @click="!user && go('login')"
          ><view class="title">{{
            user?.nickname || t('登录，开启航海生活')
          }}</view
          ><view class="small muted">{{
            user
              ? t('驭舟编号 {id} · {username}', { id: user.id, username: user.username })
              : t('查看订单、成长与专属权益')
          }}</view></view
        ></view
      ><view class="member-banner section" @click="go('membership')"
        ><view
          ><view class="card-head">{{
            member ? t('驭舟航海会员') : t('驭舟会员')
          }}</view
          ><view class="small">{{
            member
              ? t('有效期至 {date}', { date: dateTime(user.member_until, false) })
              : t('学习与航程，一起积累成长')
          }}</view></view
        ><text>{{ t('权益 ↗') }}</text></view
      ></view
    ><view class="card section"
      ><view class="row spread"
        ><text class="card-head">{{ t('我的订单') }}</text
        ><text class="link small" @click="go('orders')">{{ t('全部订单 ›') }}</text></view
      ><view class="grid four"
        ><view
          v-for="label in ['待报价', '待支付', '待履约', '已完成']"
          :key="label"
          class="scene"
          @click="go('orders')"
          ><text class="tile-icon">⌁</text><text>{{ t(label) }}</text></view
        ></view
      ></view
    ><view class="card section"
      ><view
        v-for="entry in entries"
        :key="entry.title"
        class="list-item"
        @click="entry.action"
        ><text>{{ t(entry.title) }}</text
        ><text class="muted">›</text></view
      ></view
    ><LanguagePicker />
    <PhoneBinding v-if="user" :user="user" @bound="refreshUser" />
    <view v-if="user" class="card section"
      ><view class="small muted"
        >{{ t('邀请码 {code}', { code: user.invite_code || t('暂未分配') }) }}</view
      ><button class="secondary section" @click="logout">{{ t('退出登录') }}</button></view
    ><view v-if="error" class="error">{{ t(error) }}</view></view
  >
</template>
<script setup>
import { t } from "@shared/i18n.js";
import { ref, computed } from "vue";
import { onShow } from "@dcloudio/uni-app";
import { request, post, session, saveSession, go } from "@shared/api.js";
import { dateTime, memberActive } from "@shared/domain.js";
import LanguagePicker from "@shared/LanguagePicker.vue";
import PhoneBinding from "@shared/PhoneBinding.vue";
const user = ref(null),
  error = ref("");
const member = computed(() => memberActive(user.value));
const entries = [
  { title: "身份资料与审核", action: () => go("identity") },
  { title: "购物车", action: () => go("cart") },
  { title: "成长值与会员权益", action: () => go("membership") },
  { title: "我的学习与奖励", action: () => go("learning") },
  { title: "积分商城与兑换记录", action: () => go("points") },
  { title: "我的优惠券", action: () => go("coupons") },
  {
    title: "我的活动与报名码",
    action: () => go("account", { kind: "enrollments" }),
  },
  { title: "咨询消息", action: () => go("chat") },
  { title: "我的预约", action: () => go("appointment") },
  { title: "收藏的船艇", action: () => go("account", { kind: "favorites" }) },
  { title: "浏览足迹", action: () => go("account", { kind: "footprints" }) },
  { title: "我的船艇", action: () => go("account", { kind: "boat-records" }) },
  { title: "保养提醒", action: () => go("account", { kind: "reminders" }) },
  { title: "通知消息", action: () => go("account", { kind: "notifications" }) },
  { title: "意见反馈", action: () => go("account", { kind: "feedback" }) },
  { title: "交付评价", action: () => go("account", { kind: "reviews" }) },
  { title: "应用更新", action: () => go("viewer", { url: "https://yuzhou.ourideas.top/app-updates/" }) },
];
async function refreshUser() {
  error.value = "";
  user.value = session()?.user || null;
  if (user.value)
    try {
      const r = await request("/auth/me");
      user.value = r.user || r;
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
}
</script>
<style scoped>
.member-banner {
  background: linear-gradient(90deg, #4bc8ff, #216cf7);
  color: #fff;
  border-radius: 16rpx;
  padding: 24rpx;
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.four {
  grid-template-columns: repeat(4, 1fr);
}
.four .tile-icon {
  background: #fff;
}
.four .scene {
  font-size: 22rpx;
}
.profile {
  padding: 28rpx;
}
.member-banner .card-head { color: #fff; }
</style>
