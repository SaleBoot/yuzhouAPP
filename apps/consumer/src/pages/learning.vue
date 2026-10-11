<template>
  <view class="page"
    ><LoginPrompt /><view class="title">{{ t('我的学习') }}</view
    ><view class="subtitle">{{ t('课程进度与实际获得的奖励') }}</view
    ><view class="card section"
      ><view class="card-head">{{ t('课程进度') }}</view
      ><view
        v-for="item in progress"
        :key="item.id"
        class="list-item"
        @click="go('course', { id: item.course_id })"
        ><view
          >{{ item.course_title || t('课程信息暂不可用') }}
          <view class="small muted">{{ item.lesson_title || t('课时已移除或暂不可用') }}</view>
          <view class="small muted"
            >{{ t('已学习 {count} 秒', { count: item.position_seconds }) }}</view
          ></view
        ><text class="badge">{{
          item.completed ? t('已完成') : t('学习中')
        }}</text></view
      ><view v-if="!progress.length" class="empty">{{ t('暂无学习记录') }}</view></view
    ><view class="card section"
      ><view class="card-head">{{ t('学习奖励') }}</view
      ><LearningRewards :refresh-key="refreshKey" /><view
        class="small muted section"
        >{{ t('活动资格请进入活动页面完成报名；实物礼包须等待平台实际履约。') }}</view
      ></view
    ><view v-if="error" class="error">{{ t(error) }}</view></view
  >
</template>
<script setup>
import LoginPrompt from "@shared/LoginPrompt.vue";
import { t } from "@shared/i18n.js";
import { ref } from "vue";
import { onShow } from "@dcloudio/uni-app";
import { request, go, requireLogin } from "@shared/api.js";
import LearningRewards from "@shared/LearningRewards.vue";
const progress = ref([]),
  error = ref(""),
  refreshKey = ref(0);
onShow(async () => {
  if (!requireLogin(false)) { progress.value = []; refreshKey.value++; error.value = ""; return; }
  try {
    const p = await request("/learning/progress?page_size=100");
    progress.value = p.items || [];
    refreshKey.value++;
  } catch (e) {
    error.value = e.message;
  }
});
</script>
