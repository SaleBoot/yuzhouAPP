<template>
  <view class="page">
    <view class="title">身份资料与审核</view>
    <view class="notice section">身份资料由平台人工审核。审核结果以本页状态为准；活动报名还须会员在有效期内。</view>
    <view v-if="error" class="error section">{{ error }} <text @click="load">重试</text></view>
    <button v-if="!session()" class="primary section" @click="go('login')">登录后查看或提交资料</button>
    <template v-else>
      <view class="card section"><view class="row spread"><text class="card-head">当前审核状态</text><text class="badge">{{ identityLabels[user?.identity_status] || '加载中' }}</text></view>
        <view v-for="item in applications" :key="item.id" class="section">
          <view class="row spread"><text>{{ applicationLabels[item.status] || item.status }}</text><text class="small muted">{{ dateTime(item.created_at) }}</text></view>
          <view v-if="item.review_notes" class="small muted section">审核说明：{{ item.review_notes }}</view>
        </view>
      </view>
      <view v-if="user && user.identity_status !== 'verified' && !pending" class="card section">
        <view class="card-head">提交身份资料</view>
        <text class="label">姓名</text><input v-model="form.name" class="input" maxlength="60" :disabled="busy" placeholder="与身份材料一致的姓名" />
        <text class="label">材料说明</text><textarea v-model="form.notes" class="input textarea" maxlength="1000" :disabled="busy" placeholder="可填写需要平台核对的说明" />
        <view class="small muted section">上传平台要求的身份材料，支持图片或 PDF，最多10份。文件按私有材料保存，仅你与平台审核人员可访问。</view>
        <view v-for="(file, i) in files" :key="file.id" class="list-item"><view><view>审核材料 {{ i + 1 }}</view><text class="small muted">{{ file.mime }} · {{ Math.ceil(file.size_bytes / 1024) }} KB</text></view><view class="row"><text v-if="file.mime === 'application/pdf'" class="link small" @click="preview(file.id)">预览</text><text class="link small" @click="removeFile(i)">移除</text></view></view>
        <view class="grid two section"><button class="secondary" :disabled="busy || uploading || files.length >= 10" @click="addFile('image')">上传图片</button><button class="secondary" :disabled="busy || uploading || files.length >= 10" @click="addFile('pdf')">上传 PDF</button></view>
        <view v-if="uploading" class="small muted section">正在上传私有材料…</view>
        <button class="primary section" :disabled="busy || uploading || loading" :loading="busy" @click="submit">提交审核</button>
      </view>
      <view v-else-if="pending" class="notice section">资料已提交，等待平台审核。请勿重复提交。</view>
    </template>
  </view>
</template>
<script setup>
import { computed, reactive, ref } from 'vue';
import { onShow } from '@dcloudio/uni-app';
import { request, post, chooseUpload, previewFile, notify, session, saveSession, go } from '@shared/api.js';
import { dateTime } from '@shared/domain.js';
const user = ref(null), applications = ref([]), files = ref([]), error = ref(''), loading = ref(false), busy = ref(false), uploading = ref(false);
const form = reactive({ name: '', notes: '' });
const identityLabels = { unverified: '尚未提交', pending: '平台审核中', verified: '平台资料审核通过', rejected: '审核未通过' };
const applicationLabels = { pending: '待审核', approved: '审核通过', rejected: '审核未通过' };
const pending = computed(() => applications.value.some((item) => item.status === 'pending'));
onShow(load);
async function load() {
  if (!session() || loading.value) return;
  loading.value = true; error.value = '';
  try {
    const [me, records] = await Promise.all([request('/auth/me'), request('/applications?page_size=100')]);
    user.value = me.user || me;
    saveSession({ ...session(), user: user.value });
    applications.value = (records.items || []).filter((item) => item.kind === 'identity');
  } catch (e) {
    error.value = session() ? e.message : '登录已失效，请重新登录查看最新审核状态';
    if (!session()) { user.value = null; applications.value = []; }
  }
  finally { loading.value = false; }
}
async function addFile(type) {
  if (busy.value || uploading.value || files.value.length >= 10) return;
  uploading.value = true;
  try { files.value.push(await chooseUpload({ purpose: 'application', private: true, file_type: type })); }
  catch (e) { notify(e); }
  finally { uploading.value = false; }
}
function removeFile(index) { if (!busy.value && !uploading.value) files.value.splice(index, 1); }
async function preview(id) { try { await previewFile(id); } catch (e) { notify(e); } }
async function submit() {
  if (busy.value || uploading.value || pending.value) return;
  if (!form.name.trim() || !files.value.length) return notify(new Error('请填写姓名并上传身份材料'));
  busy.value = true;
  try {
    await post('/applications', { kind: 'identity', payload: { identity_name: form.name.trim(), notes: form.notes.trim() }, file_ids: files.value.map((file) => file.id) });
    form.name = ''; form.notes = ''; files.value = [];
    await load();
    uni.showToast({ title: '审核申请已提交', icon: 'success' });
  } catch (e) { notify(e); }
  finally { busy.value = false; }
}
</script>
