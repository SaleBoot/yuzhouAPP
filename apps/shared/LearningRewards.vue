<template>
  <view>
    <view v-if="error" class="error"
      >{{ t(error) }} <text @click="load">{{ t("重试") }}</text></view
    >
    <view v-for="item in displayed" :key="item.id" class="list-item">
      <view>
        <view>{{
          item.reward_snapshot?.title ||
          typeLabel(item.reward_snapshot?.reward_type)
        }}</view>
        <view class="small muted"
          >{{ typeLabel(item.reward_snapshot?.reward_type) }} ·
          {{ dateTime(item.created_at) }}</view
        >
        <view v-if="item.notes" class="small muted">{{ item.notes }}</view>
        <view v-if="item.status === 'eligible'" class="small muted">{{ t("参加活动仍须平台资料审核与有效会员。") }}</view>
        <text
          v-if="item.reward_snapshot?.reward_type === 'growth'"
          class="small gold"
          >{{ item.reward_snapshot.points }}{{ t("成长值") }}</text
        >
      </view>
      <view class="reward-status">
        <text class="badge">{{ stateLabel(item) }}</text>
        <text
          v-if="item.reward_snapshot?.event_id && item.status === 'eligible'"
          class="link small"
          @click="go('event', { id: item.reward_snapshot.event_id })"
          >{{ t("查看活动 ›") }}</text
        >
        <text
          v-if="
            item.reward_snapshot?.coupon_id &&
            ['issued', 'already_claimed'].includes(item.status)
          "
          class="link small"
          @click="go('coupons')"
          >{{ t("查看优惠券 ›") }}</text
        >
      </view>
    </view>
    <view v-if="!displayed.length && !error" class="small muted section">{{
      loading
        ? t("奖励记录加载中…")
        : session()
          ? t("暂无已获得的课程奖励")
          : t("登录后查看实际奖励记录")
    }}</view>
  </view>
</template>
<script setup>
import { t } from './i18n.js';

import { ref, computed, watch } from "vue";
import { request, go, session } from "./api.js";
import { dateTime } from "./domain.js";
const props = defineProps({
  courseId: [Number, String],
  refreshKey: { type: Number, default: 0 },
});
const items = ref([]),
  loading = ref(false),
  error = ref("");
const displayed = computed(() =>
  props.courseId
    ? items.value.filter(
        (x) => Number(x.reward_snapshot?.course_id) === Number(props.courseId),
      )
    : items.value,
);
const types = {
  badge: "航海徽章",
  growth: "成长值",
  coupon: "优惠券",
  maintenance_coupon: "保养优惠券",
  physical_gift: "实物礼包",
  event_invitation: "活动资格",
};
const states = {
  earned: "已获得",
  issued: "已发放",
  already_claimed: "已在我的优惠券",
  unavailable: "暂不可发放",
  pending_fulfillment: "待平台履约",
  fulfilled: "平台已履约",
  eligible: "课程条件已满足",
};
function typeLabel(type) {
  return t(types[type] || "课程奖励");
}
function stateLabel(item) {
  return t(states[item.status] || item.status || "状态待更新");
}
async function load() {
  if (!session()) return;
  loading.value = true;
  error.value = "";
  try {
    const r = await request("/learning/rewards?page_size=100");
    items.value = r.items || [];
  } catch (e) {
    error.value = e.message;
  } finally {
    loading.value = false;
  }
}
watch(() => [props.courseId, props.refreshKey], load, { immediate: true });
</script>
<style scoped>
.reward-status {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 12rpx;
  max-width: 46%;
}
</style>
