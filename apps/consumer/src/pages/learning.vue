<template>
  <view class="page"
    ><view class="title">我的学习</view
    ><view class="subtitle">课程进度与实际获得的奖励</view
    ><view class="card section"
      ><view class="card-head">课程进度</view
      ><view
        v-for="item in progress"
        :key="item.id"
        class="list-item"
        @click="go('course', { id: item.course_id })"
        ><view
          >课程 {{ item.course_id }} · 课时 {{ item.lesson_id
          }}<view class="small muted"
            >已学习 {{ item.position_seconds }} 秒</view
          ></view
        ><text class="badge">{{
          item.completed ? "已完成" : "学习中"
        }}</text></view
      ><view v-if="!progress.length" class="empty">暂无学习记录</view></view
    ><view class="card section"
      ><view class="card-head">学习奖励</view
      ><LearningRewards :refresh-key="refreshKey" /><view
        class="small muted section"
        >活动资格请进入活动页面完成报名；实物礼包须等待平台实际履约。</view
      ></view
    ><view v-if="error" class="error">{{ error }}</view></view
  >
</template>
<script setup>
import { ref } from "vue";
import { onShow } from "@dcloudio/uni-app";
import { request, go, requireLogin } from "@shared/api.js";
import LearningRewards from "@shared/LearningRewards.vue";
const progress = ref([]),
  error = ref(""),
  refreshKey = ref(0);
onShow(async () => {
  if (!requireLogin()) return;
  try {
    const p = await request("/learning/progress?page_size=100");
    progress.value = p.items || [];
    refreshKey.value++;
  } catch (e) {
    error.value = e.message;
  }
});
</script>
