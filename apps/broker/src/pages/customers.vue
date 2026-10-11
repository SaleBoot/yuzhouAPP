<template>
  <view class="page"
    ><LoginPrompt /><view class="row spread"
      ><view class="title">{{ t("客户管理") }}</view
      ><text class="link" @click="adding = !adding">{{
        adding ? t("收起") : t("＋ 添加客户")
      }}</text></view
    ><view class="subtitle">{{ t("客户需求与下一次行动") }}</view
    ><view v-if="adding" class="card section"
      ><view class="formline"
        ><text class="form-label">{{ t("客户驭舟编号") }}</text
        ><input
          v-model="form.user_id"
          type="number"
          :placeholder="t('客户提供的驭舟编号')" /></view
      ><view class="formline"
        ><text class="form-label">{{ t("称呼") }}</text
        ><input v-model="form.name" :placeholder="t('填写客户称呼')" /></view
      ><view class="formline">
        <textarea
          v-model="form.notes"
          :placeholder="t('需求、偏好与跟进说明')"
        /></view
      ><button class="primary" :loading="busy" :disabled="busy" @click="create"> {{ t("保存客户") }} </button></view
    ><view class="formline section"
      ><input v-model="search" :placeholder="t('搜索客户称呼')" /></view
    ><view v-for="c in filtered" :key="c.id" class="card section"
      ><view class="row spread"
        ><view class="row"
          ><text class="avatar">{{ (c.name || t("客"))[0] }}</text
          ><view
            ><view class="card-head">{{ c.name }}</view
            ><text class="muted small">{{ t("驭舟编号 {id}", { id: c.user_id }) }}</text></view
          ></view
        ><text class="badge">{{
          t(stages[c.stage] || c.stage || "新客户")
        }}</text></view
      ><view class="article small section">{{ c.notes || t("暂无跟进备注") }}</view
      ><view v-if="c.tags?.length" class="chips section"><text v-for="tag in c.tags" :key="tag.id" class="chip">{{ tag.name }}</text></view
      ><view class="actions section"
        ><button class="secondary" @click="edit(c)">{{ t("跟进备注") }}</button
        ><button
          class="secondary"
          @click="go('appointment', { customer_id: c.user_id })"
        > {{ t("带看预约") }}</button
        ><button
          class="primary"
          @click="go('quote', { customer_id: c.user_id })"
        > {{ t("配置报价") }} </button></view
      ><view v-if="editing === c.id" class="section"
        ><view class="small muted">{{ t("客户标签") }}</view><view class="chips section"><text v-for="tag in tags" :key="tag.id" :class="['chip', {active: selectedTags.includes(Number(tag.id))}]" @click="toggleTag(tag.id)">{{ tag.name }}</text></view><view v-if="!tags.length" class="small muted">{{ t("公司尚未配置可用标签") }}</view><button class="secondary section" :disabled="busy" @click="saveTags(c)">{{ t("保存标签") }}</button
        ><picker
          :range="stageOptions.map((s) => t(s.label))"
          :value="stageIndex"
          @change="stageIndex = Number($event.detail.value)"
          ><view class="picker-value"
            >{{ t(stageOptions[stageIndex].label) }} ▾</view
          ></picker
        ><textarea v-model="notes" :placeholder="t('跟进备注')" /><button
          class="secondary section"
          :disabled="busy"
          @click="save(c)"
        > {{ t("保存跟进") }} </button></view
      ></view
    ><view v-if="!filtered.length && !error" class="empty">{{ t("暂无客户") }}</view
    ><view v-if="error" class="error"
      >{{ t(error) }} <text @click="load">{{ t("重试") }}</text></view
    ></view
  >
</template>
<script setup>
import LoginPrompt from "@shared/LoginPrompt.vue";
import { t } from "@shared/i18n.js";
import { ref, reactive, computed } from "vue";
import { onShow } from "@dcloudio/uni-app";
import { request, post, patch, go, notify, requireLogin } from "@shared/api.js";
const customers = ref([]),
  search = ref(""),
  adding = ref(false),
  busy = ref(false),
  editing = ref(null),
  notes = ref(""),
  stageIndex = ref(0),
  tags = ref([]),
  selectedTags = ref([]),
  error = ref("");
const stages = {
  new: "新客户",
  viewing: "带看中",
  quoting: "报价中",
  won: "已成交",
  lost: "暂未成交",
};
const stageOptions = Object.entries(stages).map(([value, label]) => ({
  value,
  label,
}));
const form = reactive({ user_id: "", name: "", notes: "" });
const filtered = computed(() =>
  customers.value.filter((c) => (c.name || "").includes(search.value)),
);
async function load() {
  if (!requireLogin(false)) { customers.value = []; editing.value = null; selectedTags.value = []; return; }
  try {
    const [r, t] = await Promise.all([request("/customers?page_size=100"), request("/customer-tags?page_size=100")]);
    customers.value = r.items || [];
    tags.value = (t.items || []).filter((tag) => tag.status === "active");
    error.value = "";
  } catch (e) {
    error.value = e.message;
  }
}
async function create() {
  if (!form.user_id || !form.name.trim())
    return notify(new Error(t("请填写客户用户ID及称呼")));
  busy.value = true;
  try {
    await post("/customers", { ...form, user_id: Number(form.user_id) });
    adding.value = false;
    await load();
  } catch (e) {
    notify(e);
  } finally {
    busy.value = false;
  }
}
function edit(c) {
  editing.value = editing.value === c.id ? null : c.id;
  notes.value = c.notes || "";
  selectedTags.value = (c.tags || []).map((tag) => Number(tag.id)).filter((id) => tags.value.some((tag) => Number(tag.id) === id));
  stageIndex.value = Math.max(
    0,
    stageOptions.findIndex((s) => s.value === c.stage),
  );
}
async function save(c) {
  busy.value = true;
  try {
    await patch(`/customers/${c.id}`, {
      notes: notes.value,
      stage: stageOptions[stageIndex.value].value,
    });
    editing.value = null;
    await load();
  } catch (e) {
    notify(e);
  } finally {
    busy.value = false;
  }
}
function toggleTag(id) {
  if (busy.value) return;
  id = Number(id);
  selectedTags.value = selectedTags.value.includes(id) ? selectedTags.value.filter((tag) => tag !== id) : [...selectedTags.value, id];
}
async function saveTags(c) {
  if (busy.value) return;
  busy.value = true;
  try {
    await request(`/customers/${c.id}/tags`, { method: "PUT", data: { tag_ids: selectedTags.value } });
    await load();
    uni.showToast({ title: t("标签已保存"), icon: "success" });
  } catch (e) { notify(e); }
  finally { busy.value = false; }
}
onShow(load);
</script>
