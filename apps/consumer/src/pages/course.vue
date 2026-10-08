<template>
  <view class="page" v-if="course"
    ><view class="title">{{ course.name }}</view
    ><view class="subtitle">{{ course.description }}</view
    ><video
      v-if="current?.video_url"
      :id="'lesson-video'"
      :src="media(current.video_url)"
      class="video"
      controls
      @timeupdate="track"
      @ended="completeLesson"
    /><view v-else class="notice">{{
      current ? "该课时视频尚未配置" : "课程内容待完善"
    }}</view
    ><view class="card section"
      ><view class="card-head">课程目录</view
      ><view
        v-for="(lesson, i) in course.lessons || []"
        :key="lesson.id || i"
        :class="['list-item', { gold: lessonIndex === i }]"
        @click="lessonIndex = i"
        ><text
          >{{ completedIds.includes(String(lesson.id || i + 1)) ? "✓" : "▷" }}
          {{ lesson.title || `第 ${i + 1} 课` }}</text
        ><text class="small muted">{{
          lesson.duration_seconds
            ? `${Math.ceil(lesson.duration_seconds / 60)}分钟`
            : ""
        }}</text></view
      ></view
    ><view class="card section"
      ><view class="card-head">学习奖励</view
      ><view class="small muted">{{
        course.reward_description ||
        "成长值及课程奖励以平台配置和实际考试结果为准"
      }}</view
      ><view
        v-for="reward in course.rewards || []"
        :key="reward.id"
        class="list-item"
        ><text>{{ reward.title }}</text
        ><text class="small muted">完成课程要求后发放</text></view
      ><LearningRewards
        :course-id="course.id"
        :refresh-key="rewardRefresh" /></view
    ><view class="card section"
      ><view class="card-head">课程考试</view
      ><view
        v-for="(question, i) in course.questions || []"
        :key="i"
        class="section"
        ><view>{{ i + 1 }}. {{ question.title || question.question }}</view
        ><view
          v-for="(option, j) in question.options || []"
          :key="j"
          class="list-item"
          @click="answers[i] = j"
          ><text
            >{{ answers[i] === j ? "◉" : "○" }}
            {{ typeof option === "string" ? option : option.text }}</text
          ></view
        ></view
      ><button
        v-if="course.questions?.length"
        class="primary section"
        :disabled="busy"
        @click="exam"
      >
        提交考试</button
      ><view v-else class="muted small">暂未配置课程考试。</view
      ><view v-if="result" class="notice section"
        >{{ result.passed ? "考试通过" : "尚未通过" }} · 得分 {{ result.score
        }}<view>{{ result.message || "奖励以会员成长页记录为准" }}</view></view
      ></view
    ></view
  ><view v-else class="page empty">{{ error || "课程加载中…" }}</view>
</template>
<script setup>
import { ref, computed, watch } from "vue";
import { onLoad } from "@dcloudio/uni-app";
import LearningRewards from "@shared/LearningRewards.vue";
import {
  detail,
  request,
  post,
  media,
  notify,
  requireLogin,
} from "@shared/api.js";
const course = ref(null),
  lessonIndex = ref(0),
  answers = ref([]),
  completedIds = ref([]),
  busy = ref(false),
  error = ref(""),
  result = ref(null),
  rewardRefresh = ref(0);
let lastSaved = 0;
watch(lessonIndex, () => (lastSaved = 0));
const current = computed(() => course.value?.lessons?.[lessonIndex.value]);
onLoad(async (q) => {
  try {
    course.value = await detail("courses", q.id);
    if (requireLogin()) {
      const p = await request("/learning/progress");
      completedIds.value = (p.items || [])
        .filter((x) => Number(x.course_id) === Number(q.id) && x.completed)
        .map((x) => String(x.lesson_id));
    }
  } catch (e) {
    error.value = e.message;
  }
});
async function save(position, ended = false) {
  if (!requireLogin()) return;
  try {
    const r = await post("/learning/progress", {
      course_id: course.value.id,
      lesson_id: String(current.value?.id || lessonIndex.value + 1),
      position_seconds: Math.min(
        Math.floor(position),
        current.value?.duration_seconds || Math.floor(position),
      ),
      completed: ended,
    });
    if (r.completed && !completedIds.value.includes(String(r.lesson_id)))
      completedIds.value.push(String(r.lesson_id));
    rewardRefresh.value++;
  } catch (e) {
    notify(e);
  }
}
function track(e) {
  const seconds = e.detail.currentTime;
  if (seconds - lastSaved >= 30) {
    lastSaved = seconds;
    save(seconds);
  }
}
function completeLesson(e) {
  save(
    e.detail?.duration || current.value?.duration_seconds || lastSaved,
    true,
  );
}
async function exam() {
  if (!requireLogin() || busy.value) return;
  if (
    answers.value.filter((x) => x !== undefined).length !==
    course.value.questions.length
  )
    return notify(new Error("请完成所有题目"));
  busy.value = true;
  try {
    result.value = await post("/learning/exams", {
      course_id: course.value.id,
      answers: answers.value,
    });
    rewardRefresh.value++;
  } catch (e) {
    notify(e);
  } finally {
    busy.value = false;
  }
}
</script>
<style scoped>
.video {
  width: 100%;
  height: 410rpx;
  border-radius: 18rpx;
}
</style>
