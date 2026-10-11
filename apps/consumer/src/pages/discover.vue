<template>
  <view class="page"
    ><view class="row spread"
      ><view
        ><text class="small muted">YUZHOU COMMUNITY</text
        ><view class="title">{{ t('发现海上的故事') }}</view></view
      ><text class="round-action" @click="go('publish')">＋</text></view
    ><BannerStrip placement="community" />
    <view class="chips section"
      ><text
        :class="['chip', { active: postType === 'forum' }]"
        @click="postType = 'forum'"
        >{{ t('航海社区') }}</text
      ><text
        :class="['chip', { active: postType === 'news' }]"
        @click="postType = 'news'"
        >{{ t('航海资讯') }}</text
      ><text class="chip" @click="go('shop', { kind: 'events' })">{{ t('航海活动') }}</text
      ><text class="chip" @click="go('account', { kind: 'notifications' })"
        >{{ t('消息') }}</text
      ></view
    ><view v-if="error" class="error"
      >{{ t(error) }} <text @click="load">{{ t('重试') }}</text></view
    ><view
      v-for="item in displayed"
      :key="item.id"
      class="card section"
      @click="go('post', { id: item.id })"
      ><view class="row"
        ><text class="avatar">{{ (item.author_name || t('驭舟'))[0] }}</text
        ><view
          ><view>{{ item.author_name || t('航海爱好者') }}</view
          ><text class="muted small">{{
            dateTime(item.created_at)
          }}</text></view
        ></view
      ><view class="card-head section"
        ><text v-if="item.is_pinned" class="badge">{{ t('置顶') }}</text>
        {{ item.name }}</view
      ><view class="article">{{ item.content?.slice(0, 180) }}</view
      ><view v-if="item.image_urls?.length" class="photo-row"
        ><image
          v-for="url in item.image_urls"
          :key="url"
          :src="url"
          mode="aspectFill" /></view
      ><view class="row spread section small muted"
        ><text>{{ item.category || t('航海生活') }}</text
        ><text>{{ t('赞 {count} · 阅读与评论 ↗', { count: item.like_count || 0 }) }}</text></view
      ></view
    ><view v-if="!displayed.length && !error" class="empty">{{
      loading ? t('故事加载中…') : t('还没有公开动态，来分享第一段航程吧')
    }}</view></view
  >
</template>
<script setup>
import { t } from "@shared/i18n.js";
import { ref, computed } from "vue";
import { onShow } from "@dcloudio/uni-app";
import BannerStrip from "@shared/BannerStrip.vue";
import { list, go } from "@shared/api.js";
import { dateTime } from "@shared/domain.js";
const posts = ref([]),
  error = ref(""),
  loading = ref(false),
  postType = ref("forum");
const displayed = computed(() =>
  posts.value.filter((x) => (x.post_type || "forum") === postType.value),
);
async function load() {
  loading.value = true;
  error.value = "";
  try {
    posts.value = await list("posts");
  } catch (e) {
    error.value = e.message;
  } finally {
    loading.value = false;
  }
}
onShow(load);
</script>
