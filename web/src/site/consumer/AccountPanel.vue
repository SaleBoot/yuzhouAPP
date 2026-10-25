<script setup lang="ts">
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { attachmentBlob, loadIdentity, query, request, siteSession } from '../api'
import { dateTime, displayTitle, money, statusNames } from '../../domain.js'
import { positiveId } from './domain.js'

type Row = Record<string, any>
type Page = { items: Row[]; total: number; page: number; page_size: number }
const route = useRoute(), router = useRouter()
const tabs: Record<string, string> = { overview: '账户总览', identity: '身份资料与审核', appointments: '看船预约', cart: '购物车', coupons: '我的优惠券', enrollments: '我的活动', learning: '学习记录', growth: '成长明细', redemptions: '积分兑换', conversations: '咨询消息', notifications: '通知消息', favorites: '我的收藏', footprints: '浏览足迹', 'boat-records': '我的船艇', reminders: '保养提醒', reviews: '交付评价', feedback: '意见反馈' }
const tab = computed(() => Object.hasOwn(tabs, String(route.params.tab || route.query.tab || 'overview')) ? String(route.params.tab || route.query.tab || 'overview') : 'overview')
const endpoints: Record<string, string> = { identity: '/applications', learning: '/learning/progress', redemptions: '/points-shop/redemptions' }
const items = ref<Row[]>([]), user = ref<Row | null>(null), overview = ref<Row | null>(null), boats = ref<Row[]>([]), completedOrders = ref<Row[]>([])
const error = ref(''), notice = ref(''), loading = ref(false), busy = ref(false), page = ref(1), total = ref(0)
const materials = ref<Row[]>([]), fileInput = ref<HTMLInputElement | null>(null)
const form = reactive({ title: '', content: '', name: '', contact: '', resource_id: '', scheduled_at: '', date: '', serial_number: '', order_id: '', rating: 5 })
const conversationId = ref(''), messages = ref<Row[]>([]), draft = ref(''), messagesLoading = ref(false)
let revision = 0, messageRevision = 0, messageClientId = crypto.randomUUID()
let poll: ReturnType<typeof setInterval> | undefined
const writableRecord = computed(() => ['boat-records', 'reminders', 'feedback', 'reviews'].includes(tab.value))
const identityPending = computed(() => items.value.some(item => item.kind === 'identity' && item.status === 'pending'))
const identityNames: Record<string, string> = { unverified: '尚未提交', pending: '审核中', verified: '审核通过', rejected: '审核未通过' }
const state = (value: string) => (statusNames as Record<string, string>)[value] || ({ open: '处理中', checked_in: '已签到', earned: '已获得' } as Record<string, string>)[value] || value
const post = <T,>(path: string, body: object = {}) => request<T>(path, { method: 'POST', body: JSON.stringify(body) })

async function all(path: string, sequence: number, token: string) {
  const rows: Row[] = []
  for (let number = 1; ; number++) {
    const result = await request<Page>(`${path}${path.includes('?') ? '&' : '?'}${query({ page: number, page_size: 100 }).slice(1)}`)
    if (sequence !== revision || token !== siteSession.token) return []
    if (!Array.isArray(result.items) || !Number.isInteger(result.total) || result.total < 0 || (!result.items.length && rows.length < result.total)) throw new Error('记录分页不完整，请重试')
    rows.push(...result.items)
    if (rows.length >= result.total) return rows
  }
}
async function load() {
  const sequence = ++revision, token = siteSession.token, current = tab.value
  loading.value = true; error.value = ''; items.value = []; total.value = 0; user.value = null; overview.value = null; boats.value = []; completedOrders.value = []
  try {
    const me = await request<Row>('/auth/me')
    if (sequence !== revision || token !== siteSession.token) return
    user.value = me
    if (current === 'overview') {
      const growth = await request<Row>('/growth?page_size=5')
      if (sequence === revision && token === siteSession.token) overview.value = growth
      return
    }
    const path = endpoints[current] || `/${current}`
    const result = await request<Page>(`${path}${query({ page: page.value, page_size: 20 })}`)
    if (sequence !== revision || token !== siteSession.token) return
    if (!Array.isArray(result.items) || !Number.isInteger(result.total)) throw new Error('记录列表不完整，请重试')
    items.value = current === 'identity' ? result.items.filter(item => item.kind === 'identity') : result.items
    total.value = result.total
    if (current === 'appointments') {
      const options = await all('/resources/boat_types', sequence, token)
      if (sequence !== revision || token !== siteSession.token) return
      boats.value = options
      if (!form.resource_id && boats.value.length) form.resource_id = String(boats.value[0].id)
    }
    if (current === 'reviews') {
      const [orders, reviews] = await Promise.all([all('/orders?status=completed', sequence, token), all('/reviews', sequence, token)])
      if (sequence !== revision || token !== siteSession.token) return
      const reviewed = new Set(reviews.map(item => Number(item.order_id)))
      completedOrders.value = orders.filter(order => order.status === 'completed' && Number(order.customer_id) === Number(me.id) && !reviewed.has(Number(order.id)))
    }
    if (current === 'conversations') {
      const candidate = String(route.query.conversation || '')
      if (candidate) conversationId.value = String(positiveId(candidate))
      else if (!items.value.some(item => String(item.id) === conversationId.value)) conversationId.value = items.value.length ? String(items.value[0].id) : ''
    }
  } catch (cause) { if (sequence === revision && token === siteSession.token) error.value = cause instanceof Error ? cause.message : '记录加载失败' }
  finally { if (sequence === revision) loading.value = false }
}
watch([tab, () => siteSession.token], () => { page.value = 1; notice.value = ''; materials.value = []; messages.value = []; conversationId.value = ''; load() }, { immediate: true })
watch(page, load)

async function mutate(path: string, body: object, method = 'POST', success = '操作已保存。') {
  if (busy.value) return
  const token = siteSession.token, current = tab.value
  busy.value = true; error.value = ''; notice.value = ''
  try {
    await request(path, { method, body: method === 'DELETE' ? undefined : JSON.stringify(body) })
    if (token !== siteSession.token || current !== tab.value) return
    await load(); notice.value = success
  } catch (cause) { if (token === siteSession.token && current === tab.value) error.value = cause instanceof Error ? cause.message : '操作失败' }
  finally { busy.value = false }
}
async function createRecord() {
  const token = siteSession.token, current = tab.value
  if (!form.title.trim()) { error.value = '请填写标题'; return }
  if (tab.value === 'reviews' && !completedOrders.value.some(order => Number(order.id) === Number(form.order_id))) { error.value = '请选择实际已完成订单'; return }
  await mutate(`/${tab.value}`, { title: form.title.trim(), ...(tab.value === 'reviews' ? { order_id: positiveId(form.order_id) } : {}), payload: { content: form.content, notes: form.content, contact: form.contact, due_date: form.date, serial_number: form.serial_number, rating: Number(form.rating) } })
  if (!error.value && token === siteSession.token && current === tab.value) form.title = form.content = form.contact = form.date = form.serial_number = form.order_id = ''
}
async function uploadIdentity(event: Event) {
  const input = event.target as HTMLInputElement, file = input.files?.[0]
  if (!file || busy.value) return
  error.value = ''
  if (materials.value.length >= 10) { error.value = '最多上传十份材料'; return }
  if (!['application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'image/gif'].includes(file.type) || !file.size || file.size > 50 * 1024 * 1024) { error.value = '请选择 50 MB 以内的图片或 PDF'; input.value = ''; return }
  const token = siteSession.token
  busy.value = true
  try {
    const body = new FormData(); body.append('file', file); body.append('private', '1')
    const result = await request<Row>('/uploads', { method: 'POST', body })
    if (token === siteSession.token && tab.value === 'identity') materials.value.push(result)
  } catch (cause) { error.value = cause instanceof Error ? cause.message : '材料上传失败' }
  finally { busy.value = false; input.value = '' }
}
async function submitIdentity() {
  const token = siteSession.token
  if (!form.name.trim() || !materials.value.length) { error.value = '请填写姓名并上传身份材料'; return }
  await mutate('/applications', { kind: 'identity', payload: { identity_name: form.name.trim(), notes: form.content.trim() }, file_ids: materials.value.map(item => item.id) }, 'POST', '资料已提交，等待平台审核。')
  if (!error.value && token === siteSession.token && tab.value === 'identity') { materials.value = []; form.name = form.content = ''; await loadIdentity() }
}
async function downloadMaterial(file: Row) {
  const token = siteSession.token
  try {
    const blob = await attachmentBlob(`/api/v1/files/${positiveId(file.id)}`)
    if (token !== siteSession.token || tab.value !== 'identity') return
    const url = URL.createObjectURL(blob), link = document.createElement('a')
    const extension = ({ 'application/pdf': 'pdf', 'image/jpeg': 'jpg', 'image/png': 'png', 'image/webp': 'webp', 'image/gif': 'gif' } as Record<string, string>)[blob.type] || 'bin'
    link.href = url; link.download = `审核材料-${file.id}.${extension}`; link.click(); setTimeout(() => URL.revokeObjectURL(url), 1000)
  } catch (cause) { error.value = cause instanceof Error ? cause.message : '材料下载失败' }
}
async function createAppointment() {
  if (!form.scheduled_at) { error.value = '请选择看船时间'; return }
  await mutate('/appointments', { resource_id: positiveId(form.resource_id), scheduled_at: form.scheduled_at.replace('T', ' '), notes: form.content })
}
async function changeQuantity(item: Row, value: number) {
  if (value < 1 || value > 50) return
  await mutate(`/cart/${positiveId(item.id)}`, { product_id: positiveId(item.product_id), quantity: value }, 'PATCH')
}
function openResource(item: Row) {
  const kind = item.resource?.kind || 'boat_types', id = item.resource?.id || item.resource_id
  if (id && kind) router.push(kind === 'boat_types' ? `/boats/${positiveId(id)}` : `/resources/${encodeURIComponent(kind)}/${positiveId(id)}`)
}
function chooseConversation(id: string | number) {
  conversationId.value = String(positiveId(id))
  router.replace({ path: '/account/conversations', query: { conversation: conversationId.value } })
}
watch(() => route.query.conversation, value => {
  if (tab.value === 'conversations' && value && /^[1-9]\d*$/.test(String(value))) conversationId.value = String(value)
})
async function loadMessages() {
  if (tab.value !== 'conversations' || !conversationId.value || messagesLoading.value) return
  const epoch = ++messageRevision, token = siteSession.token, id = conversationId.value
  messagesLoading.value = true
  try {
    const rows: Row[] = [], existing = messages.value, afterId = existing.length ? Number(existing[existing.length - 1].id) : 0
    for (let number = 1; ; number++) {
      const result = await request<Page>(`/conversations/${positiveId(id)}/messages${query({ page: number, page_size: 100, after_id: afterId })}`)
      if (epoch !== messageRevision || token !== siteSession.token || id !== conversationId.value || tab.value !== 'conversations') return
      if (!Array.isArray(result.items) || !Number.isInteger(result.total) || (!result.items.length && rows.length < result.total)) throw new Error('消息分页不完整，请重试')
      rows.push(...result.items)
      if (rows.length >= result.total) break
    }
    if (epoch === messageRevision && token === siteSession.token && id === conversationId.value) messages.value = [...existing, ...rows]
  } catch (cause) { if (epoch === messageRevision && token === siteSession.token && id === conversationId.value) error.value = cause instanceof Error ? cause.message : '消息加载失败' }
  finally { if (epoch === messageRevision) messagesLoading.value = false }
}
watch([conversationId, tab, () => siteSession.token], () => {
  messageRevision++; messagesLoading.value = false; messages.value = []; draft.value = ''; messageClientId = crypto.randomUUID()
  if (poll) clearInterval(poll)
  if (tab.value === 'conversations' && conversationId.value) { loadMessages(); poll = setInterval(() => { if (!document.hidden) loadMessages() }, 5000) }
})
watch(draft, () => { messageClientId = crypto.randomUUID() })
async function sendMessage() {
  if (busy.value || !draft.value.trim() || !conversationId.value) return
  const token = siteSession.token, id = conversationId.value, content = draft.value.trim(), clientId = messageClientId
  busy.value = true; error.value = ''
  try {
    await post(`/conversations/${positiveId(id)}/messages`, { content, client_id: clientId })
    if (token === siteSession.token && id === conversationId.value && tab.value === 'conversations') { draft.value = ''; await loadMessages() }
  } catch (cause) { if (token === siteSession.token && id === conversationId.value) error.value = cause instanceof Error ? cause.message : '消息发送失败，保留内容后可重试' }
  finally { busy.value = false }
}
onBeforeUnmount(() => { revision++; messageRevision++; if (poll) clearInterval(poll) })
</script>

<template>
  <div class="cw-account-layout">
    <aside class="panel cw-account-nav"><span class="cw-kicker">个人中心</span><h2>{{ user?.nickname || '我的账户' }}</h2><nav aria-label="个人中心导航"><RouterLink v-for="(label, key) in tabs" :key="key" :to="{ path: '/account', query: { tab: key } }" :class="{ selected: tab === key }">{{ label }}</RouterLink></nav></aside>
    <div>
      <div class="page-heading"><div><h1>{{ tabs[tab] }}</h1><p class="muted">账户与业务记录以当前登录身份为准。</p></div><button class="button" :disabled="loading || busy" @click="load">刷新记录</button></div>
      <p v-if="error" class="notice danger" role="alert">{{ error }}</p><p v-if="notice" class="notice success" role="status">{{ notice }}</p><p v-if="loading" class="empty">正在加载…</p>
      <template v-if="tab === 'overview' && user"><section class="panel"><h2>{{ user.nickname || user.username }}</h2><div class="cw-stat-grid"><div><small>会员状态</small><strong>{{ Number(user.member_until) * 1000 > Date.now() ? '会员有效' : '普通用户' }}</strong><p>{{ user.member_until ? `有效期至 ${dateTime(user.member_until)}` : '尚未开通会员' }}</p></div><div><small>成长值</small><strong>{{ overview?.points ?? user.growth_points ?? '—' }}</strong><RouterLink to="/account?tab=growth">查看成长明细</RouterLink></div><div><small>资料审核</small><strong>{{ identityNames[user.identity_status] || '状态待确认' }}</strong><RouterLink to="/account?tab=identity">查看审核记录</RouterLink></div></div></section><div class="cw-two"><RouterLink to="/orders" class="panel cw-quick-link"><span>订单、合同与付款进度</span><strong>我的订单 →</strong></RouterLink><RouterLink to="/account?tab=appointments" class="panel cw-quick-link"><span>与销售约定看船时间</span><strong>看船预约 →</strong></RouterLink></div></template>
      <section v-if="tab === 'identity' && user" class="panel"><h2>身份资料与审核</h2><p class="notice">当前状态：{{ identityNames[user.identity_status] || '待确认' }}。资料由平台人工审核，活动报名还需要有效会员。</p><p v-if="identityPending" class="muted">申请已提交，等待审核，请勿重复提交。</p><form v-if="user.identity_status !== 'verified' && !identityPending" class="cw-form" @submit.prevent="submitIdentity"><label>姓名<input v-model="form.name" maxlength="60" required :disabled="busy"></label><label>材料说明<textarea v-model="form.content" rows="3" maxlength="1000" :disabled="busy" /></label><p class="muted">上传图片或 PDF，最多十份，按私有材料保存。</p><input ref="fileInput" type="file" accept="image/jpeg,image/png,image/webp,image/gif,application/pdf" :disabled="busy || materials.length >= 10" @change="uploadIdentity"><div v-for="(file, index) in materials" :key="file.id" class="cw-record"><span>审核材料 {{ index + 1 }} · {{ Math.ceil(file.size_bytes / 1024) }} KB</span><div class="actions"><button type="button" class="text-button" @click="downloadMaterial(file)">下载查看</button><button type="button" class="text-button" :disabled="busy" @click="materials.splice(index, 1)">移除</button></div></div><button class="button primary" :disabled="busy || loading">{{ busy ? '处理中…' : '提交审核' }}</button></form></section>
      <section v-if="tab === 'appointments'" class="panel"><h2>预约看船</h2><form class="cw-form" @submit.prevent="createAppointment"><div class="cw-two"><label>看船型号<select v-model="form.resource_id" required :disabled="busy || loading"><option value="" disabled>选择船型</option><option v-for="boat in boats" :key="boat.id" :value="String(boat.id)">{{ displayTitle(boat.title) }}</option></select></label><label>预约时间<input v-model="form.scheduled_at" type="datetime-local" required :disabled="busy"></label></div><label>看船地点和需求<textarea v-model="form.content" rows="3" maxlength="2000" :disabled="busy" /></label><button class="button primary" :disabled="busy || loading || !boats.length">提交看船预约</button></form></section>
      <section v-if="writableRecord" class="panel"><h2>{{ tab === 'reviews' ? '评价已完成订单' : `添加${tabs[tab]}` }}</h2><form class="cw-form" @submit.prevent="createRecord"><label>标题<input v-model="form.title" maxlength="200" required :disabled="busy"></label><label v-if="tab === 'reviews'">已完成订单<select v-model="form.order_id" required :disabled="busy || loading"><option value="" disabled>请选择已完成订单</option><option v-for="order in completedOrders" :key="order.id" :value="String(order.id)">{{ order.order_no }}</option></select></label><label v-if="tab === 'reviews'">评分<select v-model.number="form.rating" :disabled="busy"><option v-for="rating in [5,4,3,2,1]" :key="rating" :value="rating">{{ rating }} 星</option></select></label><label v-if="tab === 'reminders'">提醒日期<input v-model="form.date" type="date" required :disabled="busy"></label><label v-if="tab === 'boat-records'">船艇编号<input v-model="form.serial_number" maxlength="1000" :disabled="busy"></label><label>详细说明<textarea v-model="form.content" rows="4" maxlength="3000" :required="['feedback','reviews'].includes(tab)" :disabled="busy" /></label><label v-if="tab === 'feedback'">联系方式<input v-model="form.contact" maxlength="1000" :disabled="busy"></label><button class="button primary" :disabled="busy || loading">提交</button></form></section>
      <div v-if="tab === 'conversations'" class="cw-chat-layout"><section class="panel cw-chat-contacts"><button v-for="conversation in items" :key="conversation.id" :class="['cw-conversation', { selected: String(conversation.id) === conversationId }]" @click="chooseConversation(conversation.id)"><strong>{{ conversation.members?.filter((member: Row) => Number(member.id) !== Number(user?.id)).map((member: Row) => member.nickname).join('、') || '业务咨询' }}</strong><small>{{ conversation.last_message?.content || '暂无消息' }}</small><span v-if="conversation.unread_count">{{ conversation.unread_count }} 条未读</span></button></section><section class="panel cw-chat-main"><template v-if="conversationId"><div class="page-heading"><h2>咨询消息</h2><button class="text-button" :disabled="messagesLoading" @click="loadMessages">刷新</button></div><div class="cw-chat-log" aria-live="polite"><article v-for="entry in messages" :key="entry.id" :class="['cw-chat-message', { own: Number(entry.sender_id) === Number(user?.id) }]"><small>{{ Number(entry.sender_id) === Number(user?.id) ? '我' : '接待人员' }} · {{ dateTime(entry.created_at) }}</small><p>{{ entry.content }}</p></article><p v-if="!messages.length" class="muted">{{ messagesLoading ? '正在加载消息…' : '暂无消息' }}</p></div><form class="cw-form" @submit.prevent="sendMessage"><label>发送消息<textarea v-model="draft" maxlength="2000" rows="3" :disabled="busy" required /></label><div class="actions"><button class="button primary" :disabled="busy || !draft.trim()">发送</button><button type="button" class="button" :disabled="busy" @click="mutate(`/conversations/${conversationId}/read`, {}, 'POST', '消息已标记为已读。')">标为已读</button></div></form></template><p v-else class="empty">暂无咨询会话，可从船艇详情或订单联系销售。</p></section></div>
      <div v-else-if="tab !== 'overview'" class="cw-stack">
        <article v-for="item in items" :key="item.id" class="panel cw-record-card"><div class="page-heading"><div><h2>{{ displayTitle(item.title || item.coupon?.title || item.product?.title || item.event_title || item.resource?.title) || (tab === 'appointments' ? boats.find(boat => Number(boat.id) === Number(item.resource_id))?.title : '') || tabs[tab] }}</h2><small>{{ dateTime(item.created_at) }}</small></div><span v-if="item.status" class="status-badge">{{ state(item.status) }}</span></div><p v-if="item.notes || item.content || item.message || item.payload?.content || item.payload?.notes">{{ item.notes || item.content || item.message || item.payload?.content || item.payload?.notes }}</p><p v-if="tab === 'identity' && item.review_notes" class="notice">审核说明：{{ item.review_notes }}</p><p v-if="tab === 'feedback' && item.payload?.reply" class="notice">平台回复：{{ item.payload.reply }}</p><p v-if="tab === 'reminders'">提醒日期：{{ item.payload?.due_date }}</p><p v-if="tab === 'boat-records'">{{ item.payload?.serial_number }} {{ item.payload?.model }}</p><p v-if="tab === 'appointments'">{{ dateTime(item.scheduled_at) }}</p><p v-if="tab === 'coupons'">优惠 {{ money(item.coupon?.payload?.discount_cents) }} · 满 {{ money(item.coupon?.payload?.min_amount_cents) }} 可用 · {{ item.coupon?.payload?.expires_at }} 到期</p><p v-if="tab === 'learning'">{{ item.course_title || '课程名称待完善' }} · {{ item.lesson_title || '课时名称待完善' }} · 已学习 {{ item.position_seconds }} 秒 · {{ Number(item.completed) === 1 ? '已完成' : '学习中' }}</p><p v-if="tab === 'growth'">{{ item.points > 0 ? '+' : '' }}{{ item.points }} 成长值 · {{ item.description }}</p><p v-if="tab === 'redemptions'">{{ item.quantity }} 件 · {{ item.points }} 成长值</p><p v-if="tab === 'enrollments' && ['active','checked_in'].includes(item.status)" class="cw-ticket">报名码 {{ item.ticket_code }}</p><p v-if="tab === 'cart'" class="cw-price">{{ money(item.product?.price_cents) }} × {{ item.quantity }}</p><div class="actions"><button v-if="tab === 'appointments' && !['completed','cancelled'].includes(item.status)" class="button" :disabled="busy" @click="mutate(`/appointments/${item.id}`, { status: 'cancelled' }, 'PATCH')">取消预约</button><RouterLink v-if="tab === 'enrollments'" class="button" :to="{ path: '/checkout', query: { kind: 'events', id: item.event_id } }">查看活动与报名</RouterLink><RouterLink v-if="tab === 'enrollments' && item.order_id" class="text-button" :to="`/orders/${item.order_id}`">查看活动订单</RouterLink><RouterLink v-if="tab === 'learning'" class="button" :to="{ path: '/checkout', query: { kind: 'courses', id: item.course_id } }">继续学习</RouterLink><button v-if="tab === 'notifications' && !item.read_at" class="button" :disabled="busy" @click="mutate(`/notifications/${item.id}/read`, {})">标记已读</button><button v-if="['favorites','footprints'].includes(tab)" class="button" @click="openResource(item)">查看项目</button><button v-if="tab === 'favorites'" class="text-button" :disabled="busy" @click="mutate(`/favorites/${item.id}`, {}, 'DELETE')">取消收藏</button><template v-if="tab === 'cart'"><button class="button" :disabled="busy || item.quantity <= 1" aria-label="减少数量" @click="changeQuantity(item, Number(item.quantity) - 1)">−</button><span>{{ item.quantity }}</span><button class="button" :disabled="busy || item.quantity >= 50" aria-label="增加数量" @click="changeQuantity(item, Number(item.quantity) + 1)">＋</button><RouterLink class="button primary" :to="{ path: '/checkout', query: { kind: 'products', id: item.product_id, type: 'mall', quantity: item.quantity } }">创建该商品订单</RouterLink><button class="text-button" :disabled="busy" @click="mutate(`/cart/${item.id}`, {}, 'DELETE')">移除</button></template><button v-if="tab === 'redemptions' && item.status === 'pending_fulfillment'" class="button" :disabled="busy" @click="mutate(`/points-shop/redemptions/${item.id}/cancel`, { notes: '用户在网页取消兑换' })">取消兑换</button></div></article>
        <p v-if="!items.length && !loading && !error" class="empty">暂无记录。</p>
        <div v-if="total > 20" class="pagination"><button class="button" :disabled="loading || page <= 1" @click="page--">上一页</button><span>第 {{ page }} 页 · 共 {{ total }} 条</span><button class="button" :disabled="loading || page * 20 >= total" @click="page++">下一页</button></div>
      </div>
      <div v-if="tab === 'conversations' && total > 20" class="pagination"><button class="button" :disabled="loading || page <= 1" @click="page--">上一页会话</button><span>第 {{ page }} 页 · 共 {{ total }} 条</span><button class="button" :disabled="loading || page * 20 >= total" @click="page++">下一页会话</button></div>
    </div>
  </div>
</template>
