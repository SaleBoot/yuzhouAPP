<template>
  <view class="page" v-if="event"
    ><image
      :src="event.image_url || fallbackBoat"
      mode="aspectFill"
      class="cover"
    /><view class="title section">{{ event.name }}</view
    ><view v-if="error" class="error section">{{ error }} <text @click="load">重试</text></view
    ><view class="row spread section"
      ><text class="badge">航海活动</text
      ><text class="price">{{
        event.price_cents ? `¥${money(event.price_cents)}` : "免费报名"
      }}</text></view
    ><view class="card section"
      ><view class="list-item"
        ><text class="muted">活动时间</text
        ><text>{{
          event.starts_at ? dateTime(event.starts_at) : "待公布"
        }}</text></view
      ><view class="list-item"
        ><text class="muted">活动地点</text
        ><text>{{ event.location || "待公布" }}</text></view
      ><view class="list-item"
        ><text class="muted">参与人数</text
        ><text>{{ event.capacity || "以报名为准" }}</text></view
      ><view class="article section">{{ event.description }}</view></view
    ><view class="card section"
      ><view class="card-head">参加活动</view
      ><view class="notice small"
        >参加活动须平台资料审核通过且会员在有效期内。课程奖励仍须报名；每位参与者对应一个报名码。</view
      ><view v-if="eligibilityUser" class="small section"><view>资料审核：{{ identityLabels[eligibilityUser.identity_status] || "状态待确认" }}</view><view>会员：{{ eligibility.member ? `有效期至 ${dateTime(eligibilityUser.member_until, false)}` : "尚未生效或已过期" }}</view><view v-if="!eligibility.verified" class="link" @click="go('identity')">提交身份资料与查看审核 ›</view><text v-if="!eligibility.member" class="link" @click="go('membership')">查看会员与成长 ›</text></view><view v-else class="small muted section">登录后查看你的实际活动资格。</view
      ><view v-if="enrollment" class="section"
        ><TicketCode
          v-if="['active', 'checked_in'].includes(enrollment.status)"
          :code="enrollment.ticket_code || enrollment.code"
        /><view v-if="['active', 'checked_in'].includes(enrollment.status)" class="value"
          >报名码
          {{ enrollment.ticket_code || enrollment.code || "等待确认" }}</view
        ><button
          v-if="enrollment.order_id"
          class="secondary section"
          @click="go('order', { id: enrollment.order_id })"
        >
          报名订单</button
        ><text class="muted small">{{
          {
            pending: "待支付",
            active: "已报名",
            checked_in: "已签到",
            cancelled: "已取消",
          }[enrollment.status] || enrollment.status
        }}</text></view
      ><EnrollmentCancel :enrollment="enrollment" @cancelled="enrollment = $event" />
      <button
        v-if="!enrollment || enrollment.status === 'cancelled'"
        class="primary section"
        :loading="busy"
        :disabled="busy || loading || Boolean(session() && !eligibility.eligible)"
        @click="enroll"
      >
        {{ enrollment?.status === "cancelled" ? "重新报名" : event.price_cents ? "创建报名订单" : "立即报名" }}
      </button></view
    ></view
  ><view v-else class="page empty">{{ error || "活动加载中…" }}</view>
</template>
<script setup>
import TicketCode from "@shared/TicketCode.vue";
import EnrollmentCancel from "@shared/EnrollmentCancel.vue";
import { ref, computed } from "vue";
import { onLoad, onShow } from "@dcloudio/uni-app";
import {
  detail,
  post,
  notify,
  go,
  requireLogin,
  fallbackBoat,
  request,
  session,
} from "@shared/api.js";
import { money, dateTime, eventEligibility } from "@shared/domain.js";
const event = ref(null),
  enrollment = ref(null),
  eventId = ref(null),
  eligibilityUser = ref(null),
  loading = ref(false),
  busy = ref(false),
  error = ref("");
const eligibility = computed(() => eventEligibility(eligibilityUser.value));
const identityLabels = { unverified: "尚未完成审核", pending: "资料审核中", verified: "平台资料审核通过", rejected: "资料审核未通过" };
onLoad((q) => { eventId.value = q.id; load(); });
onShow(load);
async function load() {
  if (!eventId.value || loading.value) return;
  loading.value = true;
  error.value = "";
  eligibilityUser.value = null;
  try {
    event.value = await detail("events", eventId.value);
    if (session()) {
      const [r, user] = await Promise.all([request("/enrollments?page_size=100"), request("/auth/me")]);
      eligibilityUser.value = user.user || user;
      enrollment.value = (r.items || []).find((e) => Number(e.event_id) === Number(eventId.value)) || null;
    } else enrollment.value = null;
  } catch (e) { error.value = e.message; }
  finally { loading.value = false; }
}
async function enroll() {
  if (!requireLogin() || busy.value) return;
  busy.value = true;
  try {
    const user = await request("/auth/me");
    eligibilityUser.value = user.user || user;
    const current = eventEligibility(eligibilityUser.value);
    if (!current.verified) throw new Error("活动报名需平台资料审核通过，请提交身份资料");
    if (!current.member) throw new Error("活动报名需有效会员，请查看会员状态");
    const r = await post(`/events/${event.value.id}/enroll`);
    if (r.order_id) go("order", { id: r.order_id });
    else if (r.order?.id) go("order", { id: r.order.id });
    else enrollment.value = r.enrollment || r;
  } catch (e) {
    notify(e);
  } finally {
    busy.value = false;
  }
}
</script>
