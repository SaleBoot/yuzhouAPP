<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { request, attachmentBlob, siteSession } from '../api'
import { money, dateTime, centsFromInput, statusNames, orderKinds, displayTitle } from '../../domain.js'
import { orderCapabilities, positiveId } from './domain.js'

type Row = Record<string, any>
const props = defineProps<{ id: string }>()
const router = useRouter()
const order = ref<Row | null>(null), contracts = ref<Row[]>([]), error = ref(''), message = ref(''), loading = ref(false), busy = ref(false)
const channel = ref('wechat'), testPayments = ref(false), accepted = ref(false), received = ref(false), reason = ref(''), refundAmount = ref('')
const scheduledAt = ref(''), location = ref('')
let revision = 0
let paymentKey = crypto.randomUUID()
watch(channel, () => { paymentKey = crypto.randomUUID() })
const capabilities = computed(() => order.value ? orderCapabilities(order.value, contracts.value) : null)
const status = (value: string) => (statusNames as Record<string, string>)[value] || value
const kind = (value: string) => (orderKinds as Record<string, string>)[value] || '订单'
const quoteNotes = computed(() => [...(order.value?.events || [])].reverse().find((event: Row) => event.action === 'quoted')?.payload?.notes || '')

async function load() {
  const sequence = ++revision, token = siteSession.token
  loading.value = true; error.value = ''; order.value = null; contracts.value = []; accepted.value = received.value = false
  try {
    const id = positiveId(props.id)
    const [detail, materials, bootstrap] = await Promise.all([
      request<Row>(`/orders/${id}`), request<{ items: Row[] }>(`/orders/${id}/contracts`), request<Row>('/bootstrap')
    ])
    if (sequence !== revision || token !== siteSession.token) return
    order.value = detail; contracts.value = materials.items || []
    testPayments.value = Boolean(bootstrap.is_test_environment && bootstrap.test_payments_enabled)
    refundAmount.value = ((Number(detail.paid_cents) - Number(detail.refund_cents)) / 100).toFixed(2)
    location.value = detail.schedule?.location || ''
    if (detail.schedule?.scheduled_at) {
      const day = new Date(Number(detail.schedule.scheduled_at) * 1000)
      scheduledAt.value = new Date(day.getTime() - day.getTimezoneOffset() * 60000).toISOString().slice(0, 16)
    }
  } catch (cause) {
    if (sequence === revision && token === siteSession.token) error.value = cause instanceof Error ? cause.message : '订单加载失败'
  } finally { if (sequence === revision) loading.value = false }
}
watch([() => props.id, () => siteSession.token], load, { immediate: true })

async function act(name: string, body: Row = {}, success = '操作已保存，请查看最新订单状态。') {
  if (busy.value || !order.value) return
  const token = siteSession.token, id = props.id
  busy.value = true; error.value = ''; message.value = ''
  try {
    await request(`/orders/${positiveId(id)}/${name}`, { method: 'POST', body: JSON.stringify(body) })
    if (token !== siteSession.token || id !== props.id) return
    await load(); message.value = success
  } catch (cause) {
    if (token === siteSession.token && id === props.id) error.value = cause instanceof Error ? cause.message : '操作失败，请核对最新状态'
  } finally { busy.value = false }
}
async function download(contract: Row) {
  if (busy.value) return
  busy.value = true; error.value = ''
  const token = siteSession.token
  try {
    const blob = await attachmentBlob(`/api/v1/files/${positiveId(contract.file_id)}`)
    if (token !== siteSession.token) return
    const url = URL.createObjectURL(blob), link = document.createElement('a')
    link.href = url; link.download = `驭舟合同-v${contract.version}.${blob.type === 'image/png' ? 'png' : blob.type === 'image/jpeg' ? 'jpg' : 'pdf'}`
    link.click(); setTimeout(() => URL.revokeObjectURL(url), 1000)
  } catch (cause) { error.value = cause instanceof Error ? cause.message : '合同下载失败' }
  finally { busy.value = false }
}
async function contact() {
  if (busy.value) return
  const token = siteSession.token, id = props.id
  busy.value = true; error.value = ''
  try {
    const conversation = await request<Row>('/conversations', { method: 'POST', body: JSON.stringify({ order_id: positiveId(id) }) })
    if (token === siteSession.token && id === props.id) await router.push(`/account/conversations?conversation=${positiveId(conversation.id)}`)
  } catch (cause) { if (token === siteSession.token && id === props.id) error.value = cause instanceof Error ? cause.message : '无法联系接待人员' }
  finally { busy.value = false }
}
function pay() {
  if (!capabilities.value?.pay) return
  act('pay', { channel: Number(order.value?.amount_cents) === 0 ? 'free' : channel.value, idempotency_key: paymentKey }, '支付请求已处理，请核对最新订单状态。')
}
function refund() {
  try {
    const amount = centsFromInput(refundAmount.value)
    if (!reason.value.trim()) throw new Error('请填写退款原因')
    if (!capabilities.value || amount < 1 || amount > capabilities.value.refundable) throw new Error('退款金额不能超过剩余实付金额')
    act('refund-request', { amount_cents: amount, reason: reason.value.trim() }, '退款申请已提交。审核和实际退款结果请查看后续状态。')
  } catch (cause) { error.value = cause instanceof Error ? cause.message : '请核对退款信息' }
}
function saveSchedule() {
  if (!scheduledAt.value || !location.value.trim()) { error.value = '请填写服务时间和地点'; return }
  act('schedule', { scheduled_at: scheduledAt.value.replace('T', ' '), location: location.value.trim() })
}
</script>

<template>
  <div>
    <div class="page-heading"><div><RouterLink to="/orders" class="text-button">← 我的订单</RouterLink><h1>订单详情</h1></div><div class="actions"><button class="button" :disabled="loading || busy || !order" @click="contact">联系销售</button><button class="button" :disabled="loading || busy" @click="load">刷新状态</button></div></div>
    <p v-if="error" class="notice danger" role="alert">{{ error }}</p><p v-if="message" class="notice success" role="status">{{ message }}</p>
    <p v-if="loading" class="empty">正在加载订单与合同…</p>
    <template v-if="order && capabilities">
      <section class="panel cw-order-summary"><div><span class="cw-kicker">{{ kind(order.type) }}</span><h2>{{ order.order_no }}</h2><p>{{ dateTime(order.created_at) }} 创建</p></div><div class="cw-align-right"><span class="status-badge">{{ status(order.status) }}</span><strong class="cw-price">{{ money(order.amount_cents) }}</strong><small>已付 {{ money(order.paid_cents) }} · 已退 {{ money(order.refund_cents) }}</small></div></section>
      <div class="cw-order-layout">
        <div class="cw-stack">
          <section class="panel"><h2>订单项目</h2><div class="table-scroll"><table><thead><tr><th>项目</th><th>数量</th><th>单价</th></tr></thead><tbody><tr v-for="(item, index) in order.items || []" :key="index"><td>{{ displayTitle(item.title || item.name) || '船艇及服务' }}<p v-if="item.options?.length" class="muted">{{ item.options.map((option: Row) => option.title).join(' / ') }}</p><p v-if="item.start_date" class="muted">{{ item.start_date }} 至 {{ item.end_date }}，归还日不计租</p></td><td>{{ item.quantity }}</td><td>{{ money(item.unit_price_cents) }}</td></tr></tbody></table></div><p class="muted">{{ order.notes || '无备注' }}</p></section>
          <section v-if="order.status === 'quoted'" class="panel"><h2>销售报价</h2><p>{{ quoteNotes || '请核对订单配置与当前总金额。' }}</p><p class="muted">报价有效期至 {{ dateTime(order.quote_expires_at) }}</p><button class="button primary" :disabled="busy || !capabilities.acceptQuote" @click="act('accept-quote')">确认接受报价</button></section>
          <section class="panel"><h2>合同与线下签署</h2><p class="muted">由销售上传合同，平台审核后确认线下签署。请先下载并核对当前版本。</p><p v-if="!contracts.length" class="empty">暂无合同材料，请联系销售。</p><div v-for="contract in contracts" :key="contract.id" class="cw-record"><div><strong>{{ contract.title || '合同材料' }} · v{{ contract.version }}</strong><span v-if="Number(contract.version) === Number(order.contract_version)" class="status-badge">当前版本</span><p>{{ status(contract.review_status) }} · {{ contract.signed_at ? '已确认线下签署' : '未确认签署' }}</p><p v-if="contract.review_notes" class="muted">{{ contract.review_notes }}</p></div><button class="button" :disabled="busy" @click="download(contract)">下载合同</button></div><form v-if="capabilities.sign" class="cw-form" @submit.prevent="act('sign', { version: capabilities.current.version, accepted: true })"><label class="cw-check"><input v-model="accepted" type="checkbox" required>我已阅读并在线下签署当前 v{{ order.contract_version }} 合同</label><button class="button primary" :disabled="busy || !accepted">确认签署当前版</button></form></section>
          <section v-if="['boat_upgrade', 'maintenance'].includes(order.type)" class="panel"><h2>服务预约</h2><p v-if="order.schedule">{{ dateTime(order.schedule.scheduled_at) }} · {{ order.schedule.location }}</p><form v-if="!['completed', 'cancelled', 'refunded'].includes(order.status)" class="cw-form" @submit.prevent="saveSchedule"><label>服务时间<input v-model="scheduledAt" type="datetime-local" required></label><label>服务地点<input v-model="location" maxlength="300" required></label><button class="button" :disabled="busy">保存服务预约</button></form></section>
          <section v-if="order.progress?.length || order.events?.length" class="panel"><h2>履约与订单记录</h2><ol class="cw-timeline"><li v-for="entry in order.progress || []" :key="`progress-${entry.id}`"><strong>履约进度 {{ entry.percent }}%</strong><p>{{ entry.notes }}</p><small>{{ dateTime(entry.created_at) }}</small></li><li v-for="event in [...(order.events || [])].reverse()" :key="`event-${event.id}`"><strong>{{ ({created:'订单已创建',quoted:'报价已更新',quote_accepted:'报价已确认',contract_version_created:'合同已上传',contract_reviewed:'合同审核已更新',contract_accepted:'已确认线下签署',payment_succeeded:'付款结果已记录',fulfilling:'正在履约',completed:'履约已完成',cancelled:'订单已取消',refund_requested:'退款申请已提交',refund_succeeded:'退款成功',schedule:'服务预约已更新',progress:'进度已更新'} as Record<string,string>)[event.action] || '订单状态已更新' }}</strong><p v-if="event.payload?.notes">{{ event.payload.notes }}</p><small>{{ dateTime(event.created_at) }}</small></li></ol></section>
        </div>
        <aside class="cw-stack">
          <section v-if="order.status === 'awaiting_payment'" class="panel"><h2>支付订单</h2><p class="cw-price">{{ money(order.amount_cents) }}</p><p v-if="!capabilities.pay" class="notice">请等待当前合同审核通过，并确认线下签署。</p><form class="cw-form" @submit.prevent="pay"><label v-if="order.amount_cents > 0">支付方式<select v-model="channel"><option value="wechat">微信支付</option><option value="alipay">支付宝</option><option v-if="testPayments" value="test">开发测试支付（不扣款）</option></select></label><p v-if="channel === 'test' && order.amount_cents > 0" class="notice">此方式只供开发环境验证，不产生真实扣款。</p><button class="button primary" :disabled="busy || !capabilities.pay">{{ busy ? '处理中…' : order.amount_cents === 0 ? '确认零元订单' : '发起支付' }}</button></form></section>
          <section v-if="capabilities.receive" class="panel"><h2>交付验收</h2><form class="cw-form" @submit.prevent="act('fulfill', { status: 'completed', notes: '客户在网页确认交付完成' })"><label class="cw-check"><input v-model="received" type="checkbox" required>我已实际收到并验收本订单的交付内容</label><button class="button primary" :disabled="busy || !received">确认交付完成</button></form></section>
          <section v-if="capabilities.cancel || capabilities.refundable > 0 || order.refund_requests?.length" class="panel"><h2>取消与售后</h2><form v-if="capabilities.cancel || (capabilities.refundable > 0 && !capabilities.refundPending)" class="cw-form" @submit.prevent="capabilities.cancel ? act('cancel', { reason }) : refund()"><label>原因<textarea v-model="reason" required maxlength="2000" rows="4" /></label><label v-if="!capabilities.cancel">申请退款金额（元）<input v-model="refundAmount" inputmode="decimal" required><small>最多 {{ money(capabilities.refundable) }}，以服务端审核和实际处理为准。</small></label><button class="button" :disabled="busy">{{ capabilities.cancel ? '取消订单' : '提交退款申请' }}</button></form><p v-if="capabilities.refundPending" class="notice">已有退款申请正在处理，请勿重复提交。</p><div v-for="entry in order.refund_requests || []" :key="entry.id" class="cw-record"><div><strong>{{ money(entry.amount_cents) }} · {{ status(entry.status) }}</strong><p>{{ entry.reason }}</p><p v-if="entry.review_notes" class="muted">{{ entry.review_notes }}</p></div></div></section>
        </aside>
      </div>
    </template>
  </div>
</template>
