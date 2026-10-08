<template>
  <view class="page"
    ><text class="small muted">YUZHOU ACADEMY</text
    ><view class="title">在海上，更从容</view
    ><view class="subtitle">免费学习航海知识，积累成长与奖励</view
    ><BannerStrip placement="learning"
      ><view class="hero section"
        ><view class="hero-copy"
          ><text class="hero-kicker">航海课堂</text
          ><view class="title">把知识带上船</view
          ><view class="small">完成学习和考试，奖励由平台发放</view></view
        ></view
      ></BannerStrip
    >
    ><view class="chips"
      ><text
        v-for="cat in categories"
        :key="cat"
        :class="['chip', { active: category === cat }]"
        @click="category = cat"
        >{{ cat }}</text
      ></view
    ><view v-if="error" class="error"
      >{{ error }} <text @click="load">重试</text></view
    ><view
      v-for="course in displayed"
      :key="course.id"
      class="card section row"
      @click="go('course', { id: course.id })"
      ><image
        :src="course.image_url || fallbackBoat"
        mode="aspectFill"
        class="lesson-thumb"
      /><view class="flex"
        ><view class="card-head">{{ course.name }}</view
        ><view class="small muted"
          >{{ course.category || "航海知识" }} ·
          {{ course.lessons?.length || 0 }}课时</view
        ><view class="row spread section"
          ><text class="badge">免费学习</text
          ><text class="link">开始学习 ↗</text></view
        ></view
      ></view
    ><view v-if="!displayed.length && !error" class="empty">{{
      loading ? "课堂加载中…" : "暂无已发布课程"
    }}</view></view
  >
</template>
<script setup>
import { ref, computed } from "vue";
import { onShow } from "@dcloudio/uni-app";
import BannerStrip from "@shared/BannerStrip.vue";
import { list, go, fallbackBoat } from "@shared/api.js";
const courses = ref([]),
  category = ref("全部"),
  loading = ref(false),
  error = ref("");
const categories = computed(() => [
  "全部",
  ...new Set(courses.value.map((c) => c.category).filter(Boolean)),
]);
const displayed = computed(() =>
  courses.value.filter(
    (c) => category.value === "全部" || c.category === category.value,
  ),
);
async function load() {
  loading.value = true;
  error.value = "";
  try {
    courses.value = await list("courses");
  } catch (e) {
    error.value = e.message;
  } finally {
    loading.value = false;
  }
}
onShow(load);
</script>
<style scoped>
.lesson-thumb {
  width: 200rpx;
  height: 160rpx;
  border-radius: 18rpx;
  margin-right: 24rpx;
  flex-shrink: 0;
}
.flex {
  flex: 1;
}
.hero {
  background: #09233f;
  color: white;
  padding: 34rpx;
}
</style>
