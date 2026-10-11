<template>
  <view class="page"
    ><text class="small muted">{{ t('驭舟课堂') }}</text
    ><view class="title">{{ t('在海上，更从容') }}</view
    ><view class="subtitle">{{ t('免费学习航海知识，积累成长与奖励') }}</view
    ><BannerStrip placement="learning"
      ><view class="hero section"
        ><view class="hero-copy"
          ><text class="hero-kicker">{{ t('航海课堂') }}</text
          ><view class="title">{{ t('把知识带上船') }}</view
          ><view class="small">{{ t('完成学习和考试，奖励由平台发放') }}</view></view
        ></view
      ></BannerStrip
    >
    ><view class="chips"
      ><text
        v-for="cat in categories"
        :key="cat"
        :class="['chip', { active: category === cat }]"
        @click="category = cat"
        >{{ t(cat) }}</text
      ></view
    ><view v-if="error" class="error"
      >{{ t(error) }} <text @click="load">{{ t('重试') }}</text></view
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
          >{{ course.category || t('航海知识') }} ·
          {{ t('{count}课时', { count: course.lessons?.length || 0 }) }}</view
        ><view class="row spread section"
          ><text class="badge">{{ t('免费学习') }}</text
          ><text class="link">{{ t('开始学习 ↗') }}</text></view
        ></view
      ></view
    ><view v-if="!displayed.length && !error" class="empty">{{
      loading ? t('课堂加载中…') : t('暂无已发布课程')
    }}</view></view
  >
</template>
<script setup>
import { t } from "@shared/i18n.js";
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
  background: linear-gradient(90deg, #4bc8ff, #216cf7);
  color: white;
  padding: 34rpx;
}
</style>
