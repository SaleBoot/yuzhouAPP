<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { money, displayTitle, dateTime } from '../domain.js'
import { boatParameterRows } from '../../../apps/shared/boat-parameters.js'
import { findBoatFavorite, toggleBoatFavorite } from '../../../apps/shared/post-boat.js'
import { listResources, query, request, siteSession } from './api'
import { catalogNames, imageUrl, minimumPurchasePrice } from './catalog.js'
import SiteIcon from './SiteIcon.vue'
const props = defineProps<{ kind: string; id: string }>()
const route = useRoute(), router = useRouter()
const item = ref<any>(null), boats = ref<any[]>([]), config = ref<any>({}), image = ref(0), error = ref(''), busy = ref(false), actionError = ref(''), success = ref(''), saving = ref(false)
const favorite = ref<any>(null), favoriteKnown = ref(false), comments = ref<any[]>([]), replies = ref<any[]>([]), comment = ref('')
const mode = computed(() => route.query.mode === 'buy' ? 'buy' : 'rent')
const photos = computed<string[]>(() => Array.isArray(item.value?.payload?.image_urls) ? item.value.payload.image_urls.map(imageUrl).filter(Boolean) : [])
const payload = computed(() => item.value?.payload || {})
const parameters = computed(() => boatParameterRows(payload.value.parameters))
const selectedActual = computed(() => boats.value.find(boat=>Number(boat.id)===Number(route.query.instance_id)&&Number(boat.payload?.boat_type_id)===Number(props.id)))
const salePrice = computed(() => selectedActual.value?.price_cents ?? minimumPurchasePrice(props.id, boats.value))
const price = computed(() => props.kind === 'boat_types' ? (mode.value === 'buy' ? salePrice.value : payload.value.daily_price_cents ?? item.value.price_cents) : item.value?.price_cents)
const actionLabel = computed(() => ({boat_types:mode.value==='buy'?'选择实船与配置':'选择日期并预约',products:'选购商品',courses:'进入课堂',events:'查看报名安排',maintenance:'预约维修养护',upgrades:'预约船艇升级'} as Record<string,string>)[props.kind])
const checkout = computed(() => '/checkout' + query({kind:props.kind,id:props.id,instance_id:mode.value==='buy'?route.query.instance_id:undefined,type:props.kind==='boat_types'?(mode.value==='buy'?'boat_purchase':'boat_rental'):props.kind==='products'?'mall':props.kind==='upgrades'?'boat_upgrade':props.kind==='maintenance'?'maintenance':undefined}))
const vr = computed(() => imageUrl(payload.value.vr_url || payload.value.model_url || config.value.vr_base_url))
const backPath = computed(() => ({boat_types:'/boats',products:'/market',courses:'/learn',events:'/journal?kind=events',posts:'/journal',maintenance:'/services',upgrades:'/services?kind=upgrades'} as Record<string,string>)[props.kind] || '/')
let loadId = 0
async function load() {
  const run = ++loadId
  busy.value = true; error.value = ''; image.value = 0; actionError.value = ''; success.value = '';favoriteKnown.value=false;favorite.value=null
  try {
    const [data, settings, actual] = await Promise.all([request(`/resources/${encodeURIComponent(props.kind)}/${encodeURIComponent(props.id)}`),request('/bootstrap'),props.kind==='boat_types'?listResources('boats'):Promise.resolve([])])
    if (run !== loadId) return
    if(props.kind==='boat_types'&&route.query.instance_id&&!actual.some((boat:any)=>Number(boat.id)===Number(route.query.instance_id)&&Number(boat.payload?.boat_type_id)===Number(props.id)))throw new Error('实船与船型不匹配，请返回地图重新选择')
    item.value = data; config.value = settings; boats.value = actual
    if (props.kind === 'posts') {
      const [c,r] = await Promise.all([request(`/posts/${props.id}/comments?page_size=100`),request(`/posts/${props.id}/official-replies?page_size=100`)])
      if (run === loadId) { comments.value=c.items||[];replies.value=r.items||[] }
    }
    if (props.kind === 'boat_types' && siteSession.user) {
      try{favorite.value=await findBoatFavorite(request,props.id);favoriteKnown.value=true}catch(e){actionError.value=(e as Error).message}
    }else favoriteKnown.value=true
  } catch (e) { if(run===loadId)error.value=(e as Error).message }
  finally { if(run===loadId)busy.value=false }
}
function signIn() { router.push({path:'/login',query:{return:route.fullPath}}) }
async function toggleFavorite() {
  if(!siteSession.user)return signIn()
  saving.value=true;actionError.value=''
  try {if(!favoriteKnown.value){favorite.value=await findBoatFavorite(request,props.id);favoriteKnown.value=true;return} favorite.value=await toggleBoatFavorite((path:string,options:any={})=>request(path,{method:options.method,body:options.data===undefined?undefined:JSON.stringify(options.data)}),Number(props.id),favorite.value?.id);success.value=favorite.value?'已加入收藏':'已取消收藏' }
  catch(e){favoriteKnown.value=false;actionError.value=(e as Error).message}finally{saving.value=false}
}
async function submitComment() {
  if(!siteSession.user)return signIn()
  saving.value=true;actionError.value='';success.value=''
  try { const result=await request(`/posts/${props.id}/comments`,{method:'POST',body:JSON.stringify({content:comment.value.trim()})});comment.value='';success.value=result.status==='pending'?'评论已提交，审核后展示':'评论已提交' }
  catch(e){actionError.value=(e as Error).message}finally{saving.value=false}
}
async function like() {
  if(!siteSession.user)return signIn()
  saving.value=true;actionError.value=''
  try { const data=await request(`/posts/${props.id}/like`,{method:payload.value.liked?'DELETE':'POST'});item.value.payload={...payload.value,...data} }
  catch(e){actionError.value=(e as Error).message}finally{saving.value=false}
}
watch(()=>[props.kind,props.id,siteSession.user?.id],load,{immediate:true})
</script>
<template>
  <div class="site-container detail-page"><nav class="breadcrumbs"><RouterLink to="/">首页</RouterLink><span>/</span><RouterLink :to="backPath">{{ (catalogNames as Record<string,string>)[kind]||'资料' }}</RouterLink><span>/</span><span>详情</span></nav><div v-if="busy" class="panel empty" role="status">正在载入详情…</div><p v-if="error" class="notice danger" role="alert">{{ error }} <button class="text-button" @click="load">重试</button></p><template v-if="item&&!busy">
    <section class="detail-intro" :class="{'article-intro':kind==='posts'}"><div class="detail-gallery"><div class="detail-main-image"><img v-if="photos[image]" :src="photos[image]" :alt="item.title" /><SiteIcon v-else name="ship" :size="72" /></div><div v-if="photos.length>1" class="detail-thumbnails"><button v-for="(photo,index) in photos" :key="photo" :class="{active:index===image}" :aria-label="`查看第${index+1}张图片`" @click="image=index"><img :src="photo" alt="" /></button></div></div><div class="detail-summary"><span class="eyebrow">{{ kind==='products'?'平台直营':(catalogNames as Record<string,string>)[kind] }}</span><h1>{{ displayTitle(item.title) }}</h1><p class="detail-description">{{ payload.description }}</p><div v-if="kind==='boat_types'" class="detail-facts"><span v-if="payload.length_m"><strong>{{ payload.length_m }}</strong> 米 · 船长</span><span v-if="payload.capacity"><strong>{{ payload.capacity }}</strong> 人 · 核定人数</span><span v-if="payload.model_code">型号 {{ payload.model_code }}</span></div><div v-if="kind==='boat_types'" class="catalog-switch"><RouterLink :to="{query:{...route.query,mode:'rent'}}" :class="{active:mode==='rent'}">按日租船</RouterLink><RouterLink :to="{query:{...route.query,mode:'buy'}}" :class="{active:mode==='buy'}">购船咨询</RouterLink></div><div v-if="!['posts','courses'].includes(kind)" class="detail-price"><strong v-if="price!==null&&price!==undefined">{{ money(price) }}</strong><strong v-else>价格待确认</strong><span v-if="kind==='boat_types'">{{ mode==='rent'?'/ 天':'起 · 以所选实船为准' }}</span></div><p v-if="payload.starts_at" class="muted"><SiteIcon name="clock" /> {{ dateTime(payload.starts_at) }}</p><p v-if="payload.location" class="muted"><SiteIcon name="map" /> {{ payload.location }}</p><div class="actions detail-actions"><RouterLink v-if="actionLabel" :to="checkout" class="button primary">{{ actionLabel }} <SiteIcon name="arrow" :size="19" /></RouterLink><a v-if="kind==='boat_types'&&vr" :href="vr" target="_blank" rel="noopener" class="button"><SiteIcon name="compass" /> 3D 看船</a><button v-if="kind==='boat_types'" class="button" :disabled="saving" @click="toggleFavorite"><SiteIcon name="heart" /> {{ favorite?'已收藏':'收藏船型' }}</button></div><p v-if="kind==='boat_types'" class="muted">租赁按日计费，购船可选择实际船艇和选配；价格与档期以订单确认为准。</p><p v-if="success" class="notice success" role="status">{{ success }}</p><p v-if="actionError" class="notice danger" role="alert">{{ actionError }}</p></div></section>
    <div class="detail-content-grid"><div><section class="panel"><div class="panel-heading"><h2>{{ kind==='posts'?'正文':'详细介绍' }}</h2></div><p class="article-copy">{{ payload.content || payload.description || '详细资料待发布方补充。' }}</p></section><section v-if="parameters.length" class="panel"><div class="panel-heading"><h2>船艇参数</h2></div><dl class="parameter-grid"><div v-for="row in parameters" :key="row.key"><dt>{{ row.label }}</dt><dd>{{ row.value }}</dd></div></dl></section><section v-if="kind==='posts'" class="panel"><div class="panel-heading"><h2>讨论与交流</h2><button class="button small" :disabled="saving" @click="like">{{ payload.liked?'已点赞':'点赞' }} {{ payload.like_count||0 }}</button></div><div v-for="reply in replies" :key="'r'+reply.id" class="discussion-item"><strong>官方回复</strong><p>{{ reply.content }}</p><small>{{ dateTime(reply.created_at) }}</small></div><div v-for="row in comments" :key="row.id" class="discussion-item"><strong>{{ row.nickname||`用户 ${row.user_id}` }}</strong><p>{{ row.content }}</p><small>{{ dateTime(row.created_at) }}</small></div><p v-if="!comments.length&&!replies.length" class="muted">暂无公开评论。</p><form v-if="siteSession.user" class="comment-form" @submit.prevent="submitComment"><label>发表评论<textarea v-model="comment" maxlength="2000" rows="4" required placeholder="分享你的想法" /></label><button class="button primary" :disabled="saving||!comment.trim()">提交评论</button></form><RouterLink v-else :to="{path:'/login',query:{return:route.fullPath}}" class="button">登录后参与讨论</RouterLink></section></div><aside class="detail-side panel"><SiteIcon name="compass" :size="32" /><h3>让航海生活更简单</h3><p>浏览船艇资料、查看服务安排，在个人中心跟进订单与预约。</p><RouterLink to="/account" class="text-link">前往个人中心 →</RouterLink><RouterLink v-if="kind==='boat_types'" to="/map" class="text-link">在地图中找船 →</RouterLink></aside></div>
  </template></div>
</template>
