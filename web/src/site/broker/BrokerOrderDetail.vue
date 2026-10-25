<script setup lang="ts">
import { computed, onUnmounted, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { request, attachmentBlob, siteSession } from '../api'
import { money, centsFromInput, dateTime, localDateTimeInput, orderKinds, statusNames } from '../../domain.js'
import { progressInput, progressStages, scheduledAt } from '../broker-domain.js'

type Row = Record<string, any>
const props = defineProps<{ id: string }>()
const router = useRouter()
const order = ref<Row | null>(null), loading = ref(false), busy = ref(false), error = ref(''), message = ref('')
const quote = reactive({ amount: '', notes: '', valid_until: '' })
const schedule = reactive({ at: '', location: '' })
const progress = reactive({ stage: 'planned', percent: 0, notes: '' })
const notes = ref(''), reason = ref(''), contractFile = ref<File | null>(null)
const label = (value: string) => (statusNames as Record<string, string>)[value] || value
const canQuote = computed(() => order.value && !order.value.paid_cents && ['awaiting_quote', 'quoted', 'awaiting_payment'].includes(order.value.status))
const canContract = computed(() => order.value && !order.value.paid_cents && !['cancelled', 'refunded'].includes(order.value.status))
const canProgress = computed(() => order.value && ['paid', 'fulfilling', 'partially_refunded'].includes(order.value.status))
const canSchedule = computed(() => order.value && ['boat_upgrade', 'maintenance'].includes(order.value.type) && !['cancelled', 'refunded', 'completed'].includes(order.value.status))
const netPaid = computed(() => Number(order.value?.paid_cents || 0) - Number(order.value?.refund_cents || 0))
let generation = 0
const downloads = new Set<string>()

async function load() {
  const version = ++generation
  loading.value = true
  error.value = ''
  order.value = null
  try {
    const value = await request<Row>(`/orders/${encodeURIComponent(props.id)}`)
    if (version !== generation) return
    order.value = value
    quote.amount = (Number(value.original_amount_cents) / 100).toFixed(2)
    quote.valid_until = ''
    schedule.at = localDateTimeInput(value.schedule?.scheduled_at)
    schedule.location = value.schedule?.location || ''
    progress.percent = Math.max(0, ...(value.progress || []).map((row: Row) => Number(row.percent)))
  } catch (e) { if (version === generation) error.value = e instanceof Error ? e.message : String(e) }
  finally { if (version === generation) loading.value = false }
}
async function perform(action: () => Promise<unknown>, success = '操作已保存') {
  if (busy.value) return
  busy.value = true
  error.value = message.value = ''
  try { await action(); message.value = success; await load() }
  catch (e) { error.value = e instanceof Error ? e.message : String(e) }
  finally { busy.value = false }
}
const post = (action: string, body: Row) => request(`/orders/${encodeURIComponent(props.id)}/${action}`, { method: 'POST', body: JSON.stringify(body) })
function submitQuote() {
  return perform(() => post('quote', { amount_cents: centsFromInput(quote.amount), notes: quote.notes, ...(quote.valid_until ? { valid_until: scheduledAt(quote.valid_until) } : {}) }), '报价已保存，请客户确认当前报价')
}
function submitSchedule() {
  return perform(() => {
    if (!schedule.location.trim()) throw new Error('请填写服务地点')
    return post('schedule', { scheduled_at: scheduledAt(schedule.at), location: schedule.location.trim() })
  })
}
function submitProgress() {
  return perform(() => post('progress', progressInput(progress.stage, progress.percent, progress.notes, Math.max(0, ...(order.value?.progress || []).map((row: Row) => Number(row.percent))))))
}
function uploadContract() {
  const orderId = props.id
  return perform(async () => {
    const file = contractFile.value
    if (!file || !/\.pdf$/i.test(file.name)) throw new Error('请选择线下签署的PDF合同')
    if (!file.size || file.size > 50 * 1024 * 1024) throw new Error('合同文件须大于0字节且不超过50 MB')
    const body = new FormData()
    body.append('file', file)
    body.append('private', '1')
    const uploaded = await request<Row>('/uploads', { method: 'POST', body })
    await request(`/orders/${encodeURIComponent(orderId)}/contract`, { method: 'POST', body: JSON.stringify({ file_id: uploaded.id, title: '线下签署合同 PDF' }) })
    contractFile.value = null
  }, '合同新版本已上传，等待平台审核')
}
async function downloadContract(contract: Row) {
  if (busy.value) return
  busy.value = true
  error.value = ''
  try {
    const blob = await attachmentBlob(`/api/v1/files/${contract.file_id}`)
    const url = URL.createObjectURL(blob)
    downloads.add(url)
    const link = document.createElement('a')
    link.href = url
    const extension = blob.type === 'image/jpeg' ? 'jpg' : blob.type === 'image/png' ? 'png' : 'pdf'
    link.download = `驭舟合同-${order.value?.order_no || props.id}-v${contract.version}.${extension}`
    link.click()
  } catch (e) { error.value = e instanceof Error ? e.message : String(e) }
  finally { busy.value = false }
}
async function conversation() {
  if (busy.value) return
  busy.value = true
  error.value = ''
  try {
    const value = await request<Row>('/conversations', { method: 'POST', body: JSON.stringify({ order_id: Number(props.id) }) })
    await router.push({ path: '/sales/messages', query: { conversation_id: value.id } })
  } catch (e) { error.value = e instanceof Error ? e.message : String(e) }
  finally { busy.value = false }
}
watch(() => [props.id, siteSession.token], () => { message.value = ''; contractFile.value = null; load() }, { immediate: true })
onUnmounted(() => { generation++; for (const url of downloads) URL.revokeObjectURL(url) })
</script>

<template>
  <section class="order-detail" :aria-busy="loading || busy">
    <header class="page-heading detail-heading">
<div>
<span class="eyebrow">订单详情</span>
<h2>{{ order?.order_no || `订单 ${id}` }}</h2>
</div>
<div class="actions">
<RouterLink class="button" to="/sales/orders">返回订单列表</RouterLink>
<button class="button" :disabled="loading || busy" @click="load">刷新</button>
</div>
</header>
    <p v-if="error" class="notice danger" role="alert">{{ error }}</p>
<p v-if="message" class="notice success" role="status">{{ message }}</p>
    <p v-if="loading" class="empty">正在读取订单…</p>
    <template v-if="order">
      <div class="summary-grid">
        <article>
<small>订单状态</small>
<strong>{{ label(order.status) }}</strong>
<span>{{ (orderKinds as Record<string,string>)[order.type] || order.type }}</span>
</article>
        <article>
<small>当前应付</small>
<strong>{{ money(order.amount_cents) }}</strong>
<span>原价 {{ money(order.original_amount_cents) }} · 优惠 {{ money(order.discount_cents) }}</span>
</article>
        <article>
<small>实际支付 / 已退款</small>
<strong>{{ money(order.paid_cents) }}</strong>
<span>已退款 {{ money(order.refund_cents) }}</span>
</article>
        <article>
<small>客户 / 所属公司</small>
<strong>#{{ order.customer_id }}</strong>
<span>公司 #{{ order.org_id }} · 创建于 {{ dateTime(order.created_at) }}</span>
</article>
      </div>
      <div class="panel">
<h3>配置与交付</h3>
<table>
<thead>
<tr>
<th>项目</th>
<th>数量</th>
<th>单价</th>
<th>选配</th>
</tr>
</thead>
<tbody>
<tr v-for="(item, index) in order.items" :key="index">
<td>{{ item.title }}</td>
<td>{{ item.quantity }}</td>
<td>{{ money(item.unit_price_cents) }}</td>
<td>{{ item.options?.map((option: Row) => option.title || option.name).join('、') || '—' }}</td>
</tr>
</tbody>
</table>
<p class="preserve">{{ order.notes || '暂无交付说明' }}</p>
<button class="button" :disabled="busy" @click="conversation">联系订单客户</button>
</div>
      <form v-if="canQuote" class="panel form-grid" @submit.prevent="submitQuote">
<h3>确认销售报价</h3>
<label>报价金额（元）<input v-model="quote.amount" required inputmode="decimal" />
</label>
<label>报价有效期（留空为3天）<input v-model="quote.valid_until" type="datetime-local" />
</label>
<label class="wide span-all">报价说明<textarea v-model="quote.notes" maxlength="2000" rows="2" />
</label>
<p class="muted wide">重新报价会使原合同版本失效，需上传对应新合同并由客户确认。</p>
<div class="wide span-all">
<button class="button primary" :disabled="busy">保存报价</button>
</div>
</form>
      <div class="panel">
<div class="panel-heading section-title">
<h3>合同版本</h3>
<span class="muted">当前版本 {{ order.contract_version ? `V${order.contract_version}` : '尚未上传' }}</span>
</div>
<p class="muted">采用PDF合同与线下签署文件，审核通过后由客户确认当前版本。</p>
<table>
<thead>
<tr>
<th>版本 / 文件</th>
<th>审核结果</th>
<th>客户确认</th>
<th>文件</th>
</tr>
</thead>
<tbody>
<tr v-for="contract in order.contracts" :key="contract.id">
<td>V{{ contract.version }} · {{ contract.title }}<small class="hash">SHA256 {{ contract.file_sha256 }}</small>
</td>
<td>{{ label(contract.review_status) }}<small>{{ contract.review_notes }}</small>
</td>
<td>{{ contract.signed_at ? dateTime(contract.signed_at) : '尚未确认' }}</td>
<td>
<button class="button" :disabled="busy" @click="downloadContract(contract)">下载合同</button>
</td>
</tr>
</tbody>
</table>
<p v-if="!order.contracts?.length" class="empty">暂无合同版本</p>
<form v-if="canContract" class="upload-row" @submit.prevent="uploadContract">
<label>上传线下合同 PDF<input type="file" accept="application/pdf,.pdf" :disabled="busy" @change="contractFile = ($event.target as HTMLInputElement).files?.[0] || null" />
</label>
<button class="button primary" :disabled="busy || !contractFile">上传新版本</button>
</form>
</div>
      <div class="two-columns">
        <form v-if="canSchedule" class="panel form-grid" @submit.prevent="submitSchedule">
<h3>服务预约</h3>
<label class="wide span-all">时间<input v-model="schedule.at" type="datetime-local" required />
</label>
<label class="wide span-all">地点<input v-model="schedule.location" required maxlength="300" />
</label>
<div class="wide span-all">
<button class="button primary" :disabled="busy">保存服务预约</button>
</div>
</form>
        <form v-if="canProgress" class="panel form-grid" @submit.prevent="submitProgress">
<h3>履约进度</h3>
<label>阶段<select aria-label="阶段" v-model="progress.stage">
<option v-for="(title, value) in progressStages" :key="value" :value="value">{{ title }}</option>
</select>
</label>
<label>完成百分比<input v-model.number="progress.percent" type="number" min="0" max="100" step="1" required />
</label>
<label class="wide span-all">说明<textarea v-model="progress.notes" maxlength="2000" rows="2" />
</label>
<div class="wide span-all">
<button class="button primary" :disabled="busy">更新进度</button>
</div>
</form>
      </div>
      <div v-if="canProgress && (!order.paid_cents || netPaid > 0)" class="panel">
<h3>履约操作</h3>
<label>交付备注<textarea v-model="notes" rows="2" maxlength="2000" />
</label>
<div class="actions">
<button v-if="['paid', 'partially_refunded'].includes(order.status)" class="button primary" :disabled="busy" @click="perform(() => post('fulfill', { status: 'fulfilling', notes }))">开始履约</button>
<button v-if="['fulfilling', 'partially_refunded'].includes(order.status)" class="button primary" :disabled="busy" @click="perform(() => post('fulfill', { status: 'completed', notes }))">完成履约</button>
</div>
</div>
      <div v-if="order.progress?.length" class="panel">
<h3>进度记录</h3>
<ol class="timeline">
<li v-for="entry in order.progress" :key="entry.id">
<strong>{{ (progressStages as Record<string,string>)[entry.stage] || entry.stage }} · {{ entry.percent }}%</strong>
<span>{{ dateTime(entry.created_at) }}</span>
<p class="preserve">{{ entry.notes }}</p>
</li>
</ol>
</div>
      <div class="panel">
<h3>订单动态</h3>
<ol class="timeline">
<li v-for="event in order.events" :key="event.id">
<strong>{{ ({ created: '订单已创建', quoted: '报价已更新', quote_accepted: '客户已确认报价', contract_version_created: '合同已上传', contract_reviewed: '合同已审核', contract_accepted: '客户已确认合同', paid: '付款已记录', fulfilling: '开始履约', completed: '履约完成', cancelled: '订单已取消', progress: '进度已更新', schedule: '预约已更新', refund_requested: '客户申请退款', refund_succeeded: '退款成功' } as Record<string,string>)[event.action] || event.action }}</strong>
<span>{{ dateTime(event.created_at) }}</span>
<p v-if="event.payload?.notes || event.payload?.reason" class="preserve">{{ event.payload.notes || event.payload.reason }}</p>
</li>
</ol>
</div>
      <form v-if="canQuote" class="panel cancel-row" @submit.prevent="perform(() => post('cancel', { reason }), '订单已取消')">
<label>取消原因<input v-model="reason" maxlength="2000" />
</label>
<button class="button danger" :disabled="busy">取消未付款订单</button>
</form>
    </template>
  </section>
</template>

<style scoped>
.order-detail{display:grid;gap:22px}.detail-heading,.section-title{display:flex;justify-content:space-between;align-items:center;gap:12px}.detail-heading h2{font-size:24px;margin-top:6px}.summary-grid{grid-template-columns:repeat(4,minmax(0,1fr));gap:16px}.summary-grid article{display:flex;flex-direction:column;gap:10px}.summary-grid strong{font-size:23px}.summary-grid small,.summary-grid span{font-size:12px}.panel h3{margin-bottom:16px}.panel p{margin:14px 0}.form-grid h3,.wide{grid-column:1/-1}.form-grid h3{margin:0}.two-columns{display:grid;grid-template-columns:1fr 1fr;gap:20px}.upload-row,.cancel-row{display:flex;align-items:flex-end;gap:18px}.upload-row label,.cancel-row label{flex:1}.hash{font-size:10px;overflow-wrap:anywhere;max-width:300px}.preserve{white-space:pre-wrap;overflow-wrap:anywhere}.timeline{padding-left:19px;margin:0;display:grid;gap:18px}.timeline span{margin-left:14px;font-size:12px}.timeline p{margin:7px 0}
@media(max-width:1100px){.summary-grid{grid-template-columns:repeat(2,1fr)}.two-columns{grid-template-columns:1fr}}@media(max-width:700px){.detail-heading{align-items:flex-start;flex-direction:column}.panel{overflow-x:auto}.summary-grid{grid-template-columns:1fr}.upload-row,.cancel-row{flex-direction:column;align-items:stretch}}
</style>
