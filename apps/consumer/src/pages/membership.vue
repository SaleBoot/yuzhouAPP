<template>
  <view class="page"
    ><LoginPrompt /><view class="hero"
      ><view class="hero-kicker">{{ t('驭舟会员') }}</view
      ><view class="title">{{ t('每一段航程，都有回响') }}</view
      ><view class="section">{{
        member ? t('航海会员权益生效中') : t('积累成长，解锁更多航海体验')
      }}</view
      ><view class="small section">{{
        member
          ? t('有效期至 {date}', { date: dateTime(user.member_until, false) })
          : t('会员按平台授予起算，有效期六个月')
      }}</view></view
    ><view class="card section"
      ><view class="row spread"
        ><text class="card-head">{{ t('我的成长') }}</text
        ><text class="kpi-value">{{
          growth.total || growth.balance || user?.growth_points || 0
        }}</text></view
      ><view class="muted small"
        >{{ t('学习、报名和完成订单的成长奖励，以实际发放记录为准。') }}</view
      ></view
    ><view class="card section"
      ><view class="card-head">{{ t('成长记录') }}</view
      ><view v-for="entry in records" :key="entry.id" class="list-item"
        ><view
          ><view>{{
            entry.title || entry.description || entry.reason || t('成长奖励')
          }}</view
          ><text class="small muted">{{
            dateTime(entry.created_at)
          }}</text></view
        ><text class="gold">{{ entry.points || entry.amount || 0 }}</text></view
      ><view v-if="!records.length" class="empty">{{ t('暂无成长记录') }}</view></view
    ><view class="card section"
      ><view class="card-head">{{ t('会员权益') }}</view
      ><view class="article small"
        >{{ t('专属活动资格、课程奖励与订单权益，按平台公布的具体规则使用。分红仅在平台确认订单利润并登记受益人后生成，以结算记录为准。') }}</view
      ></view
    ><view v-if="error" class="error">{{ t(error) }}</view></view
  >
</template>
<script setup>
import LoginPrompt from "@shared/LoginPrompt.vue";
import { t } from "@shared/i18n.js";
import { ref, computed } from "vue";
import { onShow } from "@dcloudio/uni-app";
import { request, session, requireLogin } from "@shared/api.js";
import { dateTime, memberActive } from "@shared/domain.js";
const user = ref(session()?.user || null),
  growth = ref({}),
  records = ref([]),
  error = ref("");
const member = computed(() => memberActive(user.value));
onShow(async () => {
  if (!requireLogin(false)) { user.value = null; growth.value = {}; records.value = []; error.value = ""; return; }
  try {
    const [u, g] = await Promise.all([request("/auth/me"), request("/growth")]);
    user.value = u.user || u;
    growth.value = g;
    records.value = g.items || g.records || [];
  } catch (e) {
    error.value = e.message;
  }
});
</script>
