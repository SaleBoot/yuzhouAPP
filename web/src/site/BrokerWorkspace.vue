<script setup lang="ts">
import { computed, onUnmounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { request, siteSession, listResources } from './api'
import { money, centsFromInput, dateTime, orderKinds, statusNames } from '../domain.js'
import { allPages, brokerOrderInput, brokerOrderTypes, customerStages, resourceKinds, scheduledAt, withdrawalAttempt } from './broker-domain.js'
import BrokerOrderDetail from './broker/BrokerOrderDetail.vue'

type Row = Record<string, any>
interface Page { items: Row[]; total: number; page: number; page_size: number }
const props = defineProps<{ section: string; id?: string }>()
const route = useRoute(), router = useRouter()
const titles: Record<string, string> = { overview: '销售工作台', customers: '客户管理', quotes: '配置报价', orders: '销售订单', appointments: '带看预约', ledger: '佣金与结算', messages: '客户消息' }
const descriptions: Record<string, string> = { overview: '从客户需求到合同交付，掌握每一笔销售进展。', customers: '管理本人客户、需求备注、跟进阶段与公司标签。', quotes: '为负责客户选择船艇和服务，创建订单后确认报价。', orders: '查看销售订单，处理报价、合同与履约进度。', appointments: '安排客户带看，并记录确认与接待结果。', ledger: '查看平台确认后的分红与可申请提现余额。', messages: '围绕船艇咨询与订单交付，持续跟进客户。' }
const section = computed(() => titles[props.section] ? props.section : 'overview')
const orderId = computed(() => props.id || String(route.query.order_id || ''))
const busy = ref(false), loading = ref(false), error = ref(''), message = ref('')
const rows = ref<Row[]>([]), total = ref(0), page = ref(1), search = ref(''), tagFilter = ref(''), orderStatus = ref(''), orderType = ref('')
const pageSize = 20
const customers = ref<Row[]>([]), tags = ref<Row[]>([]), resources = ref<Row[]>([]), appointmentBoats = ref<Row[]>([])
const overview = reactive({ customers: 0, orders: 0, appointments: 0, recent: [] as Row[], target: null as Row | null })
const adding = ref(false), editing = ref<Row | null>(null)
const customerForm = reactive({ user_id: '', name: '', notes: '' })
const followup = reactive({ stage: 'new', notes: '', tag_ids: [] as number[] })
const quoteForm = reactive({ type: 'boat_purchase', customer_id: '', resource_id: '', quantity: 1, start_date: '', end_date: '', option_ids: [] as string[], notes: '', scheduled_at: '', service_location: '' })
const appointmentForm = reactive({ customer_id: '', resource_id: '', scheduled_at: '', notes: '' })
const balance = ref<Row>({}), withdrawals = ref<Row[]>([]), withdrawalPage = ref(1), withdrawalTotal = ref(0), amount = ref('')
const conversations = ref<Row[]>([]), selectedChat = ref<Row | null>(null), chatMessages = ref<Row[]>([]), content = ref('')
let generation = 0, messageGeneration = 0
let pendingSend: { conversation_id: number; content: string; client_id: string } | null = null
const chosen = computed(() => resources.value.find(row => String(row.id) === String(quoteForm.resource_id)))
const optionList = computed<Row[]>(() => chosen.value?.payload?.options || [])
const pages = computed(() => Math.max(1, Math.ceil(total.value / pageSize)))
const label = (value: string) => (statusNames as Record<string, string>)[value] || ({ contacted: '已联系', quoting: '报价中', available: '可结算', new: '新客户' } as Record<string,string>)[value] || value
const kind = (value: string) => (orderKinds as Record<string, string>)[value] || value
const customerName = (id: number) => customers.value.find(row => Number(row.user_id) === Number(id))?.name || `客户 #${id}`
const post = <T = Row>(path: string, body: Row, method = 'POST') => request<T>(path, { method, body: JSON.stringify(body) })
const all = (path: string) => allPages((current: number) => request<Page>(`${path}${path.includes('?') ? '&' : '?'}page=${current}&page_size=100`)) as Promise<Row[]>
const failed = (e: unknown) => e instanceof Error ? e.message : String(e)

async function loadResources() {
  const currentType = quoteForm.type
  resources.value = []; quoteForm.resource_id = ''; quoteForm.option_ids = []
  const result = await listResources((resourceKinds as Record<string,string>)[currentType])
  if (quoteForm.type !== currentType || section.value !== 'quotes') return
  resources.value = result
  quoteForm.resource_id = result[0] ? String(result[0].id) : ''
  quoteForm.option_ids = []
}
async function load() {
  const version = ++generation
  loading.value = true
  error.value = ''
  rows.value = []; total.value = 0
  try {
    if (!siteSession.token || siteSession.role !== 'broker') return
    if (section.value === 'overview') {
      const [c, o, a, target] = await Promise.all([request<Page>('/customers?page_size=1'), request<Page>('/orders?page_size=6'), request<Page>('/appointments?page_size=1'), request<Row>('/agency-targets')])
      if (version !== generation) return
      Object.assign(overview, { customers: c.total, orders: o.total, appointments: a.total, recent: o.items, target })
    } else if (section.value === 'customers') {
      const query = new URLSearchParams({ page: String(page.value), page_size: String(pageSize), ...(search.value ? { search: search.value } : {}), ...(tagFilter.value ? { tag_id: tagFilter.value } : {}) })
      const [list, tagList] = await Promise.all([request<Page>(`/customers?${query}`), all('/customer-tags?status=active')])
      if (version !== generation) return
      rows.value = list.items; total.value = list.total; tags.value = tagList
    } else if (section.value === 'quotes' || section.value === 'appointments') {
      const c = await all('/customers')
      if (version !== generation) return
      customers.value = c
      const preselected = String(route.query.customer_id || '')
      const customer = c.find(row => String(row.user_id) === preselected) || c[0]
      if (section.value === 'quotes') {
        quoteForm.customer_id = customer ? String(customer.user_id) : ''
        await loadResources()
      } else {
        const [list, boats] = await Promise.all([request<Page>(`/appointments?page=${page.value}&page_size=${pageSize}`), listResources('boat_types')])
        if (version !== generation) return
        rows.value = list.items; total.value = list.total; appointmentBoats.value = boats
        appointmentForm.customer_id = customer ? String(customer.user_id) : ''
        appointmentForm.resource_id = boats[0] ? String(boats[0].id) : ''
      }
    } else if (section.value === 'orders') {
      if (orderId.value) return
      const params = new URLSearchParams({ page: String(page.value), page_size: String(pageSize), ...(search.value ? { search: search.value } : {}), ...(orderStatus.value ? { status: orderStatus.value } : {}), ...(orderType.value ? { type: orderType.value } : {}) })
      const result = await request<Page>(`/orders?${params}`)
      if (version !== generation) return
      rows.value = result.items; total.value = result.total
    } else if (section.value === 'ledger') {
      const [ledger, history] = await Promise.all([request<Page & { balance: Row }>(`/ledger?page=${page.value}&page_size=${pageSize}`), request<Page>(`/withdrawals?page=${withdrawalPage.value}&page_size=${pageSize}`)])
      if (version !== generation) return
      rows.value = ledger.items; total.value = ledger.total; balance.value = ledger.balance
      withdrawals.value = history.items; withdrawalTotal.value = history.total
    } else if (section.value === 'messages') {
      const result = await request<Page>(`/conversations?page=${page.value}&page_size=${pageSize}`)
      if (version !== generation) return
      conversations.value = result.items; total.value = result.total
      const id = Number(route.query.conversation_id)
      const chat = result.items.find((row: Row) => Number(row.id) === id)
      if (id && !chat) await openChat({ id })
      else if (chat) await openChat(chat)
    }
  } catch (e) { if (version === generation) error.value = failed(e) }
  finally { if (version === generation) loading.value = false }
}
async function mutate(action: () => Promise<unknown>, success: string) {
  if (busy.value) return
  busy.value = true; error.value = message.value = ''
  try { await action(); message.value = success; await load() }
  catch (e) { error.value = failed(e) }
  finally { busy.value = false }
}
function filter() { page.value = 1; load() }
function turn(delta: number) { page.value += delta; load() }
function editCustomer(customer: Row) {
  editing.value = customer
  followup.stage = customer.stage
  followup.notes = customer.notes || ''
  followup.tag_ids = (customer.tags || []).filter((tag: Row) => tags.value.some(row => Number(row.id) === Number(tag.id))).map((tag: Row) => Number(tag.id))
}
function createCustomer() {
  return mutate(async () => {
    const id = Number(customerForm.user_id)
    if (!Number.isSafeInteger(id) || id < 1 || !customerForm.name.trim()) throw new Error('请填写有效客户驭舟编号及称呼')
    await post('/customers', { user_id: id, name: customerForm.name.trim(), notes: customerForm.notes.trim() })
    Object.assign(customerForm, { user_id: '', name: '', notes: '' }); adding.value = false
  }, '客户已添加')
}
function saveFollowup() {
  const customer = editing.value
  if (!customer) return
  return mutate(async () => { await post(`/customers/${customer.id}`, { stage: followup.stage, notes: followup.notes }, 'PATCH'); editing.value = null }, '跟进已保存')
}
function saveTags() {
  const customer = editing.value
  if (!customer) return
  return mutate(async () => { await post(`/customers/${customer.id}/tags`, { tag_ids: followup.tag_ids }, 'PUT'); customer.tags = tags.value.filter(tag => followup.tag_ids.includes(Number(tag.id))) }, '客户标签已保存')
}
function createOrder() {
  return mutate(async () => {
    const result = await post('/orders', brokerOrderInput(quoteForm))
    await router.push({ path: '/sales/orders', query: { order_id: result.id } })
  }, '代客订单已创建')
}
function createAppointment() {
  return mutate(async () => {
    if (!appointmentForm.customer_id || !appointmentForm.resource_id) throw new Error('请选择客户及船艇型号')
    await post('/appointments', { customer_id: Number(appointmentForm.customer_id), resource_id: Number(appointmentForm.resource_id), scheduled_at: scheduledAt(appointmentForm.scheduled_at), notes: appointmentForm.notes })
    appointmentForm.scheduled_at = appointmentForm.notes = ''
  }, '带看预约已创建')
}
function updateAppointment(id: number, status: string) {
  return mutate(() => post(`/appointments/${id}`, { status }, 'PATCH'), '预约状态已更新')
}
function withdraw() {
  return mutate(async () => {
    const cents = centsFromInput(amount.value)
    if (cents < 1) throw new Error('提现金额须大于0')
    const key = `yuzhou_pc_broker_withdrawal_pending_${siteSession.user?.id}`
    const attempt = withdrawalAttempt(localStorage.getItem(key), cents, crypto.randomUUID())
    localStorage.setItem(key, JSON.stringify(attempt))
    await post('/withdrawals', attempt)
    localStorage.removeItem(key)
    amount.value = ''
  }, '提现申请已提交，实际打款结果以平台处理为准')
}
async function openChat(chat: Row) {
  const version = ++messageGeneration
  if (Number(selectedChat.value?.id) !== Number(chat.id)) content.value = ''
  selectedChat.value = chat; chatMessages.value = []; error.value = ''
  try {
    const result = await all(`/conversations/${chat.id}/messages?after_id=0`)
    if (version !== messageGeneration || section.value !== 'messages') return
    chatMessages.value = result
    await post(`/conversations/${chat.id}/read`, {})
    if (version === messageGeneration) { chat.unread_count = 0; chat.last_message = result.at(-1) || null }
  } catch (e) { if (version === messageGeneration) error.value = failed(e) }
}
async function sendMessage() {
  const chat = selectedChat.value, text = content.value.trim()
  if (!chat || !text || busy.value) return
  busy.value = true; error.value = ''
  if (!pendingSend || pendingSend.conversation_id !== Number(chat.id) || pendingSend.content !== text) pendingSend = { conversation_id: Number(chat.id), content: text, client_id: crypto.randomUUID() }
  try {
    await post(`/conversations/${chat.id}/messages`, { content: pendingSend.content, client_id: pendingSend.client_id })
    pendingSend = null
    if (selectedChat.value?.id === chat.id) { if (content.value.trim() === text) content.value = ''; await openChat(chat) }
  } catch (e) { error.value = failed(e) }
  finally { busy.value = false }
}
function nameChat(chat: Row) {
  const members = (chat.members || []).filter((member: Row) => Number(member.id) !== Number(siteSession.user?.id))
  return members.map((member: Row) => member.nickname).join('、') || (chat.order_id ? `订单 #${chat.order_id}` : `咨询 #${chat.id}`)
}
watch(() => [section.value, props.id, siteSession.token, route.query.order_id, route.query.customer_id, route.query.conversation_id], () => {
  page.value = withdrawalPage.value = 1
  search.value = tagFilter.value = orderStatus.value = orderType.value = ''
  editing.value = null; adding.value = false; selectedChat.value = null; chatMessages.value = []; conversations.value = []; messageGeneration++
  message.value = ''; balance.value = {}; withdrawals.value = []; customers.value = []; resources.value = []
  load()
}, { immediate: true })
onUnmounted(() => { generation++; messageGeneration++ })
</script>

<template>
  <div class="broker-workspace" :aria-busy="loading || busy">
    <header class="page-heading workspace-heading">
<div>
<span class="eyebrow">驭舟 · 销售中心</span>
<h1>{{ titles[section] }}</h1>
<p>{{ descriptions[section] }}</p>
</div>
<div class="heading-actions">
<RouterLink v-if="section === 'overview' || section === 'orders'" class="button primary" to="/sales/quotes">创建代客订单</RouterLink>
<button class="button" :disabled="loading || busy" @click="load">刷新数据</button>
</div>
</header>
    <p v-if="!siteSession.token || siteSession.role !== 'broker'" class="notice">请使用已获批准的经纪销售账号登录销售中心。</p>
    <template v-else>
      <p v-if="error" class="notice danger" role="alert">{{ error }}</p>
<p v-if="message" class="notice success" role="status">{{ message }}</p>
      <p v-if="loading" class="loading" role="status">正在读取销售数据…</p>

      <template v-if="section === 'overview' && !loading && !error">
        <div class="summary-grid metrics">
<article>
<small>本人客户</small>
<strong>{{ overview.customers }}</strong>
<RouterLink to="/sales/customers">管理客户 →</RouterLink>
</article>
<article>
<small>销售订单</small>
<strong>{{ overview.orders }}</strong>
<RouterLink to="/sales/orders">查看订单 →</RouterLink>
</article>
<article>
<small>带看预约总数</small>
<strong>{{ overview.appointments }}</strong>
<RouterLink to="/sales/appointments">安排带看 →</RouterLink>
</article>
<article>
<small>公司本月净收款</small>
<strong>{{ money(overview.target?.actual_net_paid_cents) }}</strong>
<span>{{ overview.target?.month }} · {{ overview.target?.configured ? `目标 ${money(overview.target.target_amount_cents)}` : '公司尚未设置月目标' }}</span>
</article>
</div>
        <div class="overview-columns">
<section class="panel">
<div class="panel-heading section-heading">
<h2>最近销售订单</h2>
<RouterLink to="/sales/orders">全部订单 →</RouterLink>
</div>
<div class="table-scroll">
<table>
<thead>
<tr>
<th>订单 / 项目</th>
<th>客户</th>
<th>金额</th>
<th>状态</th>
<th>操作</th>
</tr>
</thead>
<tbody>
<tr v-for="order in overview.recent" :key="order.id">
<td>
<strong>{{ order.order_no }}</strong>
<small>{{ order.items?.[0]?.title || kind(order.type) }}</small>
</td>
<td>#{{ order.customer_id }}</td>
<td>{{ money(order.amount_cents) }}</td>
<td>
<span class="status-badge">{{ label(order.status) }}</span>
</td>
<td>
<RouterLink class="text-button" :to="{ path: '/sales/orders', query: { order_id: order.id } }">查看详情</RouterLink>
</td>
</tr>
</tbody>
</table>
</div>
<p v-if="!overview.recent.length" class="empty">暂无销售订单</p>
</section>
<aside class="panel quick-links">
<div class="panel-heading"><h2>开始今天的销售跟进</h2></div>
<RouterLink to="/sales/customers">
<strong>客户与需求</strong>
<span>记录跟进，安排下一次行动 →</span>
</RouterLink>
<RouterLink to="/sales/quotes">
<strong>船艇配置报价</strong>
<span>选配船艇、服务与交付安排 →</span>
</RouterLink>
<RouterLink to="/sales/ledger">
<strong>佣金与结算</strong>
<span>查看平台确认后的可结算金额 →</span>
</RouterLink>
<RouterLink to="/sales/messages">
<strong>客户咨询</strong>
<span>沟通配置、合同和交付 →</span>
</RouterLink>
</aside>
</div>
      </template>

      <template v-if="section === 'customers'">
        <div class="panel">
<form class="filter-bar toolbar" @submit.prevent="filter">
<input v-model="search" aria-label="搜索客户" placeholder="搜索客户称呼或需求备注" />
<select v-model="tagFilter" aria-label="按客户标签筛选" @change="filter">
<option value="">全部公司标签</option>
<option v-for="tag in tags" :key="tag.id" :value="tag.id">{{ tag.name }}</option>
</select>
<button class="button" :disabled="loading || busy">搜索</button>
<button type="button" class="button primary" @click="adding = !adding">{{ adding ? '收起新增' : '添加客户' }}</button>
</form>
          <form v-if="adding" class="inset form-grid" @submit.prevent="createCustomer">
<h3>添加已注册客户</h3>
<label>客户驭舟编号<input v-model="customerForm.user_id" inputmode="numeric" required />
</label>
<label>客户称呼<input v-model="customerForm.name" required maxlength="120" />
</label>
<label class="wide span-all">需求与偏好<textarea v-model="customerForm.notes" rows="2" maxlength="2000" />
</label>
<div class="wide span-all">
<button class="button primary" :disabled="busy">保存客户</button>
</div>
</form>
          <div class="table-scroll">
<table>
<thead>
<tr>
<th>客户 / 编号</th>
<th>跟进阶段</th>
<th>标签</th>
<th>需求备注</th>
<th>最近更新</th>
<th>操作</th>
</tr>
</thead>
<tbody>
<tr v-for="customer in rows" :key="customer.id">
<td>
<strong>{{ customer.name }}</strong>
<small>#{{ customer.user_id }}</small>
</td>
<td>
<span class="status-badge">{{ (customerStages as Record<string,string>)[customer.stage] || customer.stage }}</span>
</td>
<td>
<span v-for="tag in customer.tags" :key="tag.id" class="tag">{{ tag.name }}</span>
<span v-if="!customer.tags?.length" class="muted">—</span>
</td>
<td class="notes-cell">{{ customer.notes || '暂无备注' }}</td>
<td>{{ dateTime(customer.updated_at) }}</td>
<td>
<div class="row-actions">
<button class="text-button" @click="editCustomer(customer)">跟进 / 标签</button>
<RouterLink :to="{ path: '/sales/quotes', query: { customer_id: customer.user_id } }">配置报价</RouterLink>
<RouterLink :to="{ path: '/sales/appointments', query: { customer_id: customer.user_id } }">带看预约</RouterLink>
</div>
</td>
</tr>
</tbody>
</table>
</div>
<p v-if="!rows.length && !loading && !error" class="empty">暂无符合条件的客户</p>
        </div>
        <section v-if="editing" class="panel">
<div class="panel-heading section-heading">
<h2>{{ editing.name }} · 跟进记录</h2>
<button class="text-button" @click="editing = null">关闭</button>
</div>
<div class="two-columns">
<form class="form-grid" @submit.prevent="saveFollowup">
<label class="wide span-all">跟进阶段<select aria-label="跟进阶段" v-model="followup.stage">
<option v-for="(title, value) in customerStages" :key="value" :value="value">{{ title }}</option>
</select>
</label>
<label class="wide span-all">需求与跟进备注<textarea v-model="followup.notes" rows="4" maxlength="2000" />
</label>
<div class="wide span-all">
<button class="button primary" :disabled="busy">保存跟进</button>
</div>
</form>
<form @submit.prevent="saveTags">
<h3>公司客户标签</h3>
<div class="checkboxes">
<label v-for="tag in tags" :key="tag.id">
<input v-model="followup.tag_ids" type="checkbox" :value="Number(tag.id)" />{{ tag.name }}</label>
</div>
<p v-if="!tags.length" class="muted">公司尚未配置可用标签。</p>
<button class="button" :disabled="busy">保存客户标签</button>
</form>
</div>
</section>
      </template>

      <form v-if="section === 'quotes'" class="panel quote-layout" @submit.prevent="createOrder">
        <div class="form-grid">
<h2>配置代客订单</h2>
<label>负责客户<select aria-label="负责客户" v-model="quoteForm.customer_id" required>
<option value="" disabled>请选择客户</option>
<option v-for="customer in customers" :key="customer.id" :value="String(customer.user_id)">{{ customer.name }} · #{{ customer.user_id }}</option>
</select>
</label>
<label>订单类型<select aria-label="订单类型" v-model="quoteForm.type" @change="loadResources().catch(e => error = failed(e))">
<option v-for="(title, value) in brokerOrderTypes" :key="value" :value="value">{{ title }}</option>
</select>
</label>
<label class="wide span-all">船艇或服务<select aria-label="船艇或服务" v-model="quoteForm.resource_id" required @change="quoteForm.option_ids = []">
<option value="" disabled>请选择船艇或服务</option>
<option v-for="resource in resources" :key="resource.id" :value="String(resource.id)">{{ resource.title }} · {{ money(resource.price_cents) }}</option>
</select>
</label>
<label v-if="quoteForm.type !== 'boat_purchase'">数量<input v-model.number="quoteForm.quantity" type="number" min="1" max="50" step="1" required />
</label>
<template v-if="quoteForm.type === 'boat_rental'">
<label>开始日期<input v-model="quoteForm.start_date" type="date" required />
</label>
<label>归还日期<input v-model="quoteForm.end_date" type="date" :min="quoteForm.start_date" required />
</label>
</template>
<template v-if="['boat_upgrade', 'maintenance'].includes(quoteForm.type)">
<label>服务时间<input v-model="quoteForm.scheduled_at" type="datetime-local" required />
</label>
<label>服务地点<input v-model="quoteForm.service_location" required maxlength="300" />
</label>
</template>
<fieldset v-if="optionList.length" class="wide span-all">
<legend>选配项目</legend>
<label v-for="option in optionList" :key="option.id" class="option-line">
<input v-model="quoteForm.option_ids" type="checkbox" :value="option.id" />
<span>{{ option.title || option.name }}</span>
<strong>+ {{ money(option.price_cents) }}</strong>
</label>
</fieldset>
<label class="wide span-all">配置与交付说明<textarea v-model="quoteForm.notes" rows="4" maxlength="2000" placeholder="记录客户确认的配置、交付地点、时间与约定" />
</label>
<div class="wide span-all">
<button class="button primary" :disabled="busy || loading || !customers.length || !resources.length">创建代客订单</button>
</div>
</div>
<aside class="quote-aside">
<div class="boat-mark">驭舟 YUZHOU</div>
<template v-if="chosen">
<img v-if="chosen.payload?.image_urls?.[0] || chosen.payload?.image_url" :src="chosen.payload.image_urls?.[0] || chosen.payload.image_url" :alt="chosen.title" />
<h3>{{ chosen.title }}</h3>
<strong class="resource-price">{{ money(chosen.price_cents) }}</strong>
<p>{{ chosen.payload?.description || '船艇配置与交付安排由销售和客户共同确认。' }}</p>
</template>
<p class="muted">订单将绑定本人及所属销售公司。金额、库存和订单状态以服务端确认为准；创建后继续确认报价与PDF合同。</p>
<RouterLink v-if="!customers.length && !loading" class="text-button" to="/sales/customers">先添加负责客户 →</RouterLink>
</aside>
      </form>

      <template v-if="section === 'orders'">
        <BrokerOrderDetail v-if="orderId" :id="orderId" />
        <section v-else class="panel">
<form class="filter-bar toolbar" @submit.prevent="filter">
<input v-model="search" aria-label="搜索订单编号" placeholder="搜索订单编号" />
<select v-model="orderType" aria-label="订单类型" @change="filter">
<option value="">全部订单类型</option>
<option v-for="(title, value) in brokerOrderTypes" :key="value" :value="value">{{ title }}</option>
</select>
<select v-model="orderStatus" aria-label="订单状态" @change="filter">
<option value="">全部状态</option>
<option v-for="status in ['awaiting_quote','quoted','awaiting_payment','paid','fulfilling','completed','partially_refunded','refunded','cancelled']" :key="status" :value="status">{{ label(status) }}</option>
</select>
<button class="button" :disabled="loading || busy">搜索</button>
</form>
<div class="table-scroll">
<table>
<thead>
<tr>
<th>订单编号 / 创建时间</th>
<th>项目</th>
<th>客户</th>
<th>金额 / 实付</th>
<th>状态</th>
<th>操作</th>
</tr>
</thead>
<tbody>
<tr v-for="order in rows" :key="order.id">
<td>
<strong>{{ order.order_no }}</strong>
<small>{{ dateTime(order.created_at) }}</small>
</td>
<td>{{ order.items?.[0]?.title }}<small>{{ kind(order.type) }}</small>
</td>
<td>#{{ order.customer_id }}</td>
<td>{{ money(order.amount_cents) }}<small>实付 {{ money(order.paid_cents) }}</small>
</td>
<td>
<span class="status-badge">{{ label(order.status) }}</span>
</td>
<td>
<RouterLink class="text-button" :to="{ path: '/sales/orders', query: { order_id: order.id } }">合同与履约 →</RouterLink>
</td>
</tr>
</tbody>
</table>
</div>
<p v-if="!rows.length && !loading && !error" class="empty">暂无符合条件的销售订单</p>
</section>
      </template>

      <template v-if="section === 'appointments'">
        <form class="panel form-grid" @submit.prevent="createAppointment">
<h2>创建客户带看预约</h2>
<label>负责客户<select aria-label="负责客户" v-model="appointmentForm.customer_id" required>
<option value="" disabled>请选择客户</option>
<option v-for="customer in customers" :key="customer.id" :value="String(customer.user_id)">{{ customer.name }} · #{{ customer.user_id }}</option>
</select>
</label>
<label>船艇型号<select aria-label="船艇型号" v-model="appointmentForm.resource_id" required>
<option value="" disabled>请选择船艇型号</option>
<option v-for="boat in appointmentBoats" :key="boat.id" :value="String(boat.id)">{{ boat.title }}</option>
</select>
</label>
<label>带看时间<input v-model="appointmentForm.scheduled_at" type="datetime-local" required />
</label>
<label>接待说明<input v-model="appointmentForm.notes" maxlength="2000" />
</label>
<div class="wide span-all">
<button class="button primary" :disabled="busy || loading || !customers.length || !appointmentBoats.length">创建带看预约</button>
</div>
</form>
<section class="panel">
<div class="panel-heading section-heading">
<h2>带看安排</h2>
<span class="muted">共 {{ total }} 条</span>
</div>
<div class="table-scroll">
<table>
<thead>
<tr>
<th>客户</th>
<th>船艇型号</th>
<th>预约时间</th>
<th>接待说明</th>
<th>状态</th>
<th>操作</th>
</tr>
</thead>
<tbody>
<tr v-for="appointment in rows" :key="appointment.id">
<td>{{ customerName(appointment.customer_id) }}</td>
<td>{{ appointmentBoats.find(boat => Number(boat.id) === Number(appointment.resource_id))?.title || `#${appointment.resource_id}` }}</td>
<td>{{ dateTime(appointment.scheduled_at) }}</td>
<td class="notes-cell">{{ appointment.notes || '—' }}</td>
<td>
<span class="status-badge">{{ ({pending:'待确认',confirmed:'已确认',completed:'已完成',cancelled:'已取消'} as Record<string,string>)[appointment.status] || appointment.status }}</span>
</td>
<td>
<div class="row-actions">
<button v-if="appointment.status === 'pending'" class="text-button" :disabled="busy" @click="updateAppointment(appointment.id,'confirmed')">确认预约</button>
<button v-if="appointment.status === 'confirmed'" class="text-button" :disabled="busy" @click="updateAppointment(appointment.id,'completed')">完成接待</button>
<button v-if="['pending','confirmed'].includes(appointment.status)" class="text-button danger" :disabled="busy" @click="updateAppointment(appointment.id,'cancelled')">取消预约</button>
</div>
</td>
</tr>
</tbody>
</table>
</div>
<p v-if="!rows.length && !loading && !error" class="empty">暂无带看预约</p>
</section>
      </template>

      <template v-if="section === 'ledger'">
        <div class="summary-grid metrics">
<article>
<small>可申请提现</small>
<strong>{{ money(balance.available_cents) }}</strong>
<span>以账本当前可用余额为准</span>
</article>
<article>
<small>待结算</small>
<strong>{{ money(balance.pending_cents) }}</strong>
<span>等待配置的结算时间</span>
</article>
<article>
<small>已提现与申请中</small>
<strong>{{ money(balance.withdrawn_and_reserved_cents) }}</strong>
<span>含待审核、已批准及已打款</span>
</article>
<article>
<small>待抵扣金额</small>
<strong>{{ money(balance.debt_cents) }}</strong>
<span>退款调整后的余额差额</span>
</article>
</div>
<form class="panel withdrawal-form" @submit.prevent="withdraw">
<div>
<h2>申请提现</h2>
<p class="muted">分红依据平台逐单确认的利润生成，提现与实际打款结果以平台处理为准。</p>
</div>
<label>提现金额（元）<input v-model="amount" :disabled="busy" inputmode="decimal" required placeholder="请输入金额" />
</label>
<button class="button primary" :disabled="busy || loading">提交申请</button>
</form>
<section class="panel">
<h2>结算账本</h2>
<div class="table-scroll">
<table>
<thead>
<tr>
<th>账本编号</th>
<th>订单</th>
<th>金额</th>
<th>状态</th>
<th>可用时间</th>
<th>记录时间</th>
</tr>
</thead>
<tbody>
<tr v-for="entry in rows" :key="entry.id">
<td>#{{ entry.id }}</td>
<td>
<RouterLink class="text-button" :to="{path:'/sales/orders',query:{order_id:entry.order_id}}">订单 #{{ entry.order_id }}</RouterLink>
</td>
<td>{{ money(entry.amount_cents) }}</td>
<td>{{ entry.state === 'available' ? '可结算' : label(entry.state) }}</td>
<td>{{ dateTime(entry.available_at) }}</td>
<td>{{ dateTime(entry.created_at) }}</td>
</tr>
</tbody>
</table>
</div>
<p v-if="!rows.length && !loading && !error" class="empty">暂无已确认结算记录</p>
</section>
<section class="panel">
<h2>提现申请</h2>
<div class="table-scroll">
<table>
<thead>
<tr>
<th>申请编号</th>
<th>金额</th>
<th>处理状态</th>
<th>备注</th>
<th>申请时间</th>
</tr>
</thead>
<tbody>
<tr v-for="withdrawal in withdrawals" :key="withdrawal.id">
<td>#{{ withdrawal.id }}</td>
<td>{{ money(withdrawal.amount_cents) }}</td>
<td>{{ withdrawal.status === 'paid' ? '已打款' : label(withdrawal.status) }}</td>
<td>{{ withdrawal.notes || '—' }}</td>
<td>{{ dateTime(withdrawal.created_at) }}</td>
</tr>
</tbody>
</table>
</div>
<p v-if="!withdrawals.length && !loading && !error" class="empty">暂无提现申请</p>
<div class="pagination">
<span>共 {{ withdrawalTotal }} 条</span>
<button class="button" :disabled="withdrawalPage <= 1 || loading || busy" @click="withdrawalPage--;load()">上一页</button>
<span>{{ withdrawalPage }}</span>
<button class="button" :disabled="withdrawalPage * pageSize >= withdrawalTotal || loading || busy" @click="withdrawalPage++;load()">下一页</button>
</div>
</section>
      </template>

      <section v-if="section === 'messages'" class="panel chat-workspace">
<aside class="conversation-list">
<h2>客户会话</h2>
<button v-for="chat in conversations" :key="chat.id" :class="['conversation', {selected:selectedChat?.id === chat.id}]" @click="openChat(chat)">
<div>
<strong>{{ nameChat(chat) }}</strong>
<span v-if="chat.unread_count" class="unread">{{ chat.unread_count }}</span>
</div>
<p>{{ chat.last_message?.content || '查看咨询记录' }}</p>
<small>{{ dateTime(chat.updated_at) }}</small>
</button>
<p v-if="!conversations.length && !loading && !error" class="empty">暂无客户会话，可从订单详情联系客户。</p>
</aside>
<div class="chat-detail">
<template v-if="selectedChat">
<header class="panel-heading section-heading">
<div>
<h2>{{ nameChat(selectedChat) }}</h2>
<p class="muted">{{ selectedChat.order_id ? `订单 #${selectedChat.order_id}` : '船艇与服务咨询' }}</p>
</div>
<button class="button" :disabled="busy" @click="openChat(selectedChat)">刷新消息</button>
</header>
<div class="messages" aria-live="polite">
<article v-for="chatMessage in chatMessages" :key="chatMessage.id" :class="['chat-message',{mine:Number(chatMessage.sender_id) === Number(siteSession.user?.id)}]">
<small>{{ Number(chatMessage.sender_id) === Number(siteSession.user?.id) ? '我' : '客户' }} · {{ dateTime(chatMessage.created_at) }}</small>
<p>{{ chatMessage.content }}</p>
</article>
<p v-if="!chatMessages.length" class="empty">暂无消息</p>
</div>
<form class="message-compose" @submit.prevent="sendMessage">
<label class="sr-only" for="broker-message">咨询内容</label>
<textarea id="broker-message" v-model="content" rows="2" maxlength="2000" placeholder="输入配置、合同或交付沟通内容" required />
<button class="button primary" :disabled="busy || !content.trim()">发送消息</button>
</form>
</template>
<p v-else class="empty chat-empty">选择左侧客户会话，查看咨询与交付沟通。</p>
</div>
</section>

      <footer v-if="['customers','appointments','ledger','messages'].includes(section) || (section === 'orders' && !orderId)" class="pagination">
<span>共 {{ total }} 条 · 每页 {{ pageSize }} 条</span>
<button class="button" :disabled="page <= 1 || loading || busy" @click="turn(-1)">上一页</button>
<span>{{ page }} / {{ pages }}</span>
<button class="button" :disabled="page >= pages || loading || busy" @click="turn(1)">下一页</button>
</footer>
    </template>
  </div>
</template>

<style scoped>
.broker-workspace{display:grid;gap:24px;min-width:0}
.heading-actions,.section-heading,.row-actions{display:flex;align-items:center;gap:12px}
.heading-actions{padding-top:10px;flex-wrap:wrap}.section-heading{justify-content:space-between;margin-bottom:18px}
.metrics{grid-template-columns:repeat(4,minmax(0,1fr));gap:18px}.metrics article{display:grid;gap:12px}.metrics strong{font-size:30px;line-height:1.25}.metrics small,.metrics span,.metrics a{font-size:12px}
.overview-columns{display:grid;grid-template-columns:minmax(0,1fr) 290px;gap:22px}.quick-links{display:grid;align-content:start;gap:23px}.quick-links a{display:grid;gap:7px;padding-bottom:12px}.quick-links span{font-size:12px}
.notes-cell{max-width:260px;white-space:pre-wrap;overflow-wrap:anywhere}td small{display:block;margin-top:5px}.row-actions{flex-wrap:wrap;gap:9px;min-width:150px}.tag{display:inline-block;padding:3px 8px;background:#f0f5fb;color:#5f7b9e;border-radius:4px;font-size:11px;margin:2px 4px 2px 0}
.toolbar{margin-bottom:22px;flex-wrap:wrap}.toolbar input{max-width:340px;min-width:180px;flex:1}.toolbar select{width:170px}.toolbar>.primary{margin-left:auto}
.form-grid>h2,.form-grid>h3,.wide{grid-column:1/-1}.inset{padding:24px 0;margin-bottom:23px}.two-columns{display:grid;grid-template-columns:1fr 1fr;gap:45px}.two-columns h3{margin-bottom:18px}.checkboxes{display:flex;flex-wrap:wrap;gap:14px;margin:18px 0 25px}.checkboxes label{display:flex;flex-direction:row;align-items:center;gap:6px}.checkboxes input,.option-line input{width:16px;height:16px;min-height:0;padding:0}
.quote-layout{display:grid;grid-template-columns:minmax(0,1.5fr) minmax(240px,1fr);gap:48px}.quote-aside{padding:26px;background:#f5f9fd;border-radius:10px;align-self:start}.boat-mark{font-size:11px;letter-spacing:2px;margin-bottom:20px}.quote-aside img{width:100%;height:190px;object-fit:cover;border-radius:7px}.quote-aside h3{margin:20px 0 10px}.resource-price{display:block;font-size:25px}.quote-aside p{line-height:1.9;margin-top:20px}.quote-aside a{display:inline-block;margin-top:20px}fieldset{padding:13px 18px}legend{padding:0 7px}.option-line{display:flex!important;flex-direction:row;align-items:center;gap:10px!important;padding:9px 0}.option-line span{flex:1}
.withdrawal-form{display:flex;gap:24px;align-items:flex-end}.withdrawal-form>div{flex:1}.withdrawal-form p{margin-top:10px}.withdrawal-form label{width:200px}.panel .pagination{margin-top:20px}
.chat-workspace{display:grid;grid-template-columns:300px minmax(0,1fr);padding:0;min-height:600px;overflow:hidden}.conversation-list{padding:24px 0;border-right:1px solid #e7edf4}.conversation-list>h2{padding:0 23px;margin-bottom:20px}.conversation{display:block;text-align:left;background:#fff;border:0;width:100%;padding:19px 23px;border-bottom:1px solid #f0f3f7}.conversation:hover,.conversation.selected{background:#edf5ff}.conversation>div{display:flex;align-items:center;justify-content:space-between}.conversation p{margin:8px 0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.conversation small{font-size:11px}.unread{background:#197ce7;color:white;padding:2px 6px;border-radius:10px;font-size:10px}.chat-detail{padding:25px;display:flex;flex-direction:column;min-width:0}.chat-detail .section-heading{padding-bottom:19px;border-bottom:1px solid #edf1f6}.chat-detail .section-heading p{margin-top:7px}.messages{display:flex;flex-direction:column;gap:20px;overflow-y:auto;max-height:480px;min-height:350px;flex:1;padding:10px 0 25px}.chat-message{max-width:80%;align-self:flex-start}.chat-message small{font-size:11px}.chat-message p{background:#f2f5f9;padding:12px 16px;border-radius:9px;white-space:pre-wrap;overflow-wrap:anywhere;margin-top:7px}.chat-message.mine{align-self:flex-end}.chat-message.mine p{background:#e9f3ff}.message-compose{display:flex;gap:15px;align-items:flex-end;padding-top:20px;border-top:1px solid #edf1f6}.message-compose textarea{flex:1}.chat-empty{margin:auto}.sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
@media(max-width:1250px){.overview-columns{grid-template-columns:1fr}.quick-links{grid-template-columns:1fr 1fr}.quick-links .panel-heading{grid-column:1/-1}.quote-layout{gap:24px}.chat-workspace{grid-template-columns:250px minmax(0,1fr)}}
@media(max-width:900px){.metrics{grid-template-columns:1fr 1fr}.quote-layout,.two-columns{grid-template-columns:1fr}.withdrawal-form{flex-wrap:wrap}.withdrawal-form>div{flex-basis:100%}.chat-workspace{grid-template-columns:210px minmax(0,1fr)}}
@media(max-width:650px){.chat-workspace{grid-template-columns:1fr;padding:0}.conversation-list{max-height:230px;overflow:auto;border-right:0;border-bottom:1px solid #e7edf4}.chat-detail{padding:18px}.toolbar input,.toolbar select{width:100%;max-width:none}.toolbar>.primary{margin-left:0}.quick-links{grid-template-columns:1fr}}
</style>
