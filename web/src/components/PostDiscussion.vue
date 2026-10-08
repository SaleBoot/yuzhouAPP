<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { request, type Page } from '../api'
import { dateTime } from '../domain.js'
import Modal from './Modal.vue'
const props=defineProps<{post:any}>(),emit=defineEmits<{close:[]}>()
const comments=ref<any[]>([]),replies=ref<any[]>([]),error=ref(''),content=ref(''),loading=ref(false),busy=ref(false)
async function load(){loading.value=true;error.value='';try{const [a,b]=await Promise.all([request<Page>(`/posts/${props.post.id}/comments?page_size=100`),request<Page>(`/posts/${props.post.id}/official-replies?page_size=100`)]);comments.value=a.items;replies.value=b.items}catch(err){error.value=(err as Error).message}finally{loading.value=false}}
async function reply(){if(busy.value)return;busy.value=true;error.value='';try{await request(`/posts/${props.post.id}/official-replies`,{method:'POST',body:JSON.stringify({content:content.value})});content.value='';await load()}catch(err){error.value=(err as Error).message}finally{busy.value=false}}
onMounted(load)
</script>
<template><Modal :title="post.title+' · 评论与官方回复'" wide @close="!busy&&emit('close')"><p v-if="error" class="notice danger" role="alert">{{ error }}</p><p class="notice">点赞 {{ post.payload?.like_count||0 }} 次 · 展示已审核评论和官方回复</p><p v-if="loading" class="empty">正在读取评论…</p><h3 class="section-heading">已发布评论</h3><p v-if="!comments.length" class="inline-empty">暂无已发布评论</p><article v-for="comment in comments" :key="comment.id" class="message-item"><small>用户 #{{ comment.user_id }} · {{ dateTime(comment.created_at) }}</small><p>{{ comment.content }}</p></article><h3 class="section-heading">官方回复</h3><p v-if="!replies.length" class="inline-empty">暂无官方回复</p><article v-for="item in replies" :key="item.id" class="message-item own"><small>平台 #{{ item.author_id }} · {{ dateTime(item.created_at) }}</small><p>{{ item.content }}</p></article><form id="official-reply" style="margin-top:20px" @submit.prevent="reply"><label><span>官方回复内容 *</span><textarea v-model="content" rows="4" required maxlength="2000" /></label></form><template #footer><button class="button" :disabled="busy" @click="emit('close')">关闭</button><button class="button primary" form="official-reply" :disabled="busy">{{ busy?'正在发布…':'发布官方回复' }}</button></template></Modal></template>
