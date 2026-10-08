<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { attachmentBlob, downloadAttachment } from '../api'
import Modal from './Modal.vue'
const props=defineProps<{path:string;title:string}>()
const emit=defineEmits<{close:[]}>()
const url=ref(''),mime=ref(''),loading=ref(true),error=ref('')
let closed=false
onMounted(async()=>{try{const blob=await attachmentBlob(props.path);if(!['application/pdf','image/jpeg','image/png','image/webp'].includes(blob.type))throw new Error('此材料不支持内嵌预览，请使用鉴权下载');if(closed)return;mime.value=blob.type;url.value=URL.createObjectURL(blob)}catch(err){error.value=(err as Error).message}finally{loading.value=false}})
onBeforeUnmount(()=>{closed=true;if(url.value)URL.revokeObjectURL(url.value)})
async function download(){try{await downloadAttachment(props.path,props.title)}catch(err){error.value=(err as Error).message}}
</script>
<template><Modal :title="title+' · 私有材料预览'" wide @close="emit('close')"><p class="notice">材料通过当前账号鉴权读取，预览关闭后会释放本地临时地址。</p><p v-if="error" class="notice danger" role="alert">{{ error }}</p><div v-if="loading" class="empty">正在鉴权读取文件…</div><object v-else-if="url&&mime==='application/pdf'" :data="url" type="application/pdf" class="pdf-preview" aria-label="PDF合同预览"><p>当前浏览器不支持PDF预览，可使用下方鉴权下载。</p></object><img v-else-if="url" :src="url" :alt="title" class="image-preview" /><template #footer><button class="button" @click="emit('close')">关闭预览</button><button class="button primary" @click="download">鉴权下载</button></template></Modal></template>
