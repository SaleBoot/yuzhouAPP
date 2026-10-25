<script setup lang="ts">
import { ref, watch } from 'vue'
import { request, query, siteSession } from './api'
import { dateTime, money, orderKinds, statusNames } from '../domain.js'
import AccountPanel from './consumer/AccountPanel.vue'
import CheckoutForm from './consumer/CheckoutForm.vue'
import OrderDetail from './consumer/OrderDetail.vue'
import LearningWorkspace from './consumer/LearningWorkspace.vue'

const props = defineProps<{ page: string; id?: string; kind?: string }>()
type Row = Record<string, any>
const orders = ref<Row[]>([]), status = ref(''), search = ref(''), pageNumber = ref(1), total = ref(0), loading = ref(false), error = ref('')
const filters = [{ value: '', label: '全部订单' }, { value: 'awaiting_quote', label: '待报价' }, { value: 'quoted', label: '待确认报价' }, { value: 'awaiting_payment', label: '待付款' }, { value: 'paid', label: '待履约' }, { value: 'fulfilling', label: '履约中' }, { value: 'completed', label: '已完成' }, { value: 'cancelled', label: '已取消' }, { value: 'refunded', label: '已退款' }]
let sequence = 0
const orderName = (value: string) => (orderKinds as Record<string, string>)[value] || '订单'
const stateName = (value: string) => (statusNames as Record<string, string>)[value] || value
async function loadOrders() {
  const version = ++sequence, token = siteSession.token
  orders.value = []; total.value = 0; error.value = ''
  if (props.page !== 'orders' || !token || siteSession.role !== 'consumer') return
  loading.value = true
  try {
    const result = await request<{ items: Row[]; total: number; page: number; page_size: number }>(`/orders${query({ status: status.value, search: search.value, page: pageNumber.value, page_size: 20 })}`)
    if (version !== sequence || token !== siteSession.token) return
    if (!Array.isArray(result.items) || !Number.isInteger(result.total) || result.total < 0 || result.page !== pageNumber.value || result.page_size !== 20) throw new Error('订单列表不完整，请重试')
    orders.value = result.items; total.value = result.total
  } catch (cause) { if (version === sequence && token === siteSession.token) error.value = cause instanceof Error ? cause.message : '订单加载失败' }
  finally { if (version === sequence) loading.value = false }
}
function applyFilter() { if (pageNumber.value === 1) loadOrders(); else pageNumber.value = 1 }
watch([() => props.page, () => siteSession.token, () => siteSession.role, pageNumber], loadOrders, { immediate: true })
watch(status, applyFilter)
</script>

<template>
  <div class="consumer-workspace">
    <p v-if="!siteSession.token" class="notice">请先登录用户账号后继续。</p>
    <p v-else-if="siteSession.role !== 'consumer'" class="notice">请切换到用户入口查看个人订单与账户。</p>
    <template v-else>
      <AccountPanel v-if="page === 'account'" :key="siteSession.token" />
      <OrderDetail v-else-if="page === 'order' && id" :key="`${siteSession.token}:${id}`" :id="id" />
      <LearningWorkspace v-else-if="page === 'checkout' && id && (kind === 'courses' || kind === 'events')" :key="`${siteSession.token}:${kind}:${id}`" :kind="kind" :id="id" />
      <CheckoutForm v-else-if="page === 'checkout' && id" :key="`${siteSession.token}:${kind}:${id}`" :kind="kind" :id="id" />
      <template v-else-if="page === 'orders'">
        <div class="page-heading"><div><span class="cw-kicker">YUZHOU · 我的航程</span><h1>我的订单</h1><p class="muted">查看报价、合同、付款与履约进度。</p></div><button class="button" :disabled="loading" @click="loadOrders">刷新订单</button></div>
        <section class="panel"><form class="filter-bar" @submit.prevent="applyFilter"><label>订单状态<select v-model="status"><option v-for="filter in filters" :key="filter.value" :value="filter.value">{{ filter.label }}</option></select></label><label class="cw-search">订单编号<input v-model="search" type="search" maxlength="100" placeholder="输入订单编号搜索"></label><button class="button primary" :disabled="loading">搜索</button></form><p v-if="error" class="notice danger" role="alert">{{ error }}</p><div class="table-scroll"><table><thead><tr><th>订单信息</th><th>业务类型</th><th>创建时间</th><th>状态</th><th>金额</th><th>操作</th></tr></thead><tbody><tr v-for="order in orders" :key="order.id"><td><strong>{{ order.order_no }}</strong><p class="muted">{{ order.items?.map((item: Row) => item.title || item.name).filter(Boolean).join('、') || '查看订单项目' }}</p></td><td>{{ orderName(order.type) }}</td><td>{{ dateTime(order.created_at) }}</td><td><span class="status-badge">{{ stateName(order.status) }}</span></td><td><strong>{{ money(order.amount_cents) }}</strong><small class="cw-block muted">已付 {{ money(order.paid_cents) }}</small></td><td><RouterLink :to="`/orders/${order.id}`" class="text-button">订单详情 →</RouterLink></td></tr></tbody></table></div><p v-if="!orders.length && !error" class="empty">{{ loading ? '正在加载订单…' : '暂无相关订单。' }}</p><div v-if="total > 20" class="pagination"><button class="button" :disabled="loading || pageNumber <= 1" @click="pageNumber--">上一页</button><span>第 {{ pageNumber }} 页 · 共 {{ total }} 条</span><button class="button" :disabled="loading || pageNumber * 20 >= total" @click="pageNumber++">下一页</button></div></section>
      </template>
      <section v-else class="panel"><h1>请选择有效业务项目</h1><RouterLink to="/account" class="text-button">返回个人中心</RouterLink></section>
    </template>
  </div>
</template>

<style scoped>
.consumer-workspace{min-width:0}.cw-kicker,:deep(.cw-kicker){color:#2784af;font-size:12px;letter-spacing:.13em;font-weight:700}.cw-block,:deep(.cw-block){display:block;margin-top:6px}.cw-search{flex:1;min-width:220px}
:deep(.cw-stack){display:grid;gap:24px;align-content:start}:deep(.cw-two){display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:24px}:deep(.cw-order-layout){display:grid;grid-template-columns:minmax(0,2fr) minmax(300px,1fr);gap:24px;align-items:start}
:deep(.cw-checkout-layout){display:grid;grid-template-columns:minmax(0,1.6fr) minmax(300px,1fr);gap:28px;align-items:start}:deep(.cw-account-layout){display:grid;grid-template-columns:210px minmax(0,1fr);gap:28px;align-items:start}:deep(.cw-account-nav){padding:24px 18px}:deep(.cw-account-nav nav){display:grid;gap:4px}:deep(.cw-account-nav nav a){padding:10px 12px;border-radius:7px;text-decoration:none;color:#577080;font-size:14px}:deep(.cw-account-nav nav a.selected){background:#eaf4fb;color:#0879ad;font-weight:700}
:deep(.cw-form){display:grid;gap:18px;min-width:0}:deep(fieldset.cw-form){border:0;padding:0;margin:0}:deep(.cw-form label){display:grid;gap:8px;font-size:14px;font-weight:600}:deep(.cw-form small){font-weight:400;color:#6b7f8c}:deep(.cw-form .button){justify-self:start}:deep(label.cw-check){display:flex;flex-direction:row;align-items:flex-start;gap:10px;line-height:1.7}:deep(.cw-check input){width:17px;min-height:17px;height:17px;flex:0 0 17px;margin-top:4px}:deep(.cw-options){display:grid;gap:12px}
:deep(.cw-order-summary){display:flex;justify-content:space-between;gap:24px;align-items:center;margin-bottom:24px}:deep(.cw-align-right){text-align:right}:deep(.cw-price){display:block;color:#087faf;font-size:28px;font-weight:700;margin:12px 0}:deep(.cw-price small){font-size:14px;color:#6a7c88}:deep(.cw-record){display:flex;align-items:center;justify-content:space-between;gap:18px;border-bottom:1px solid #edf1f4;padding:18px 0}:deep(.cw-record:last-child){border-bottom:0}:deep(.cw-record p){margin:7px 0}:deep(.cw-record-card){margin-top:20px}:deep(.cw-ticket){font:600 22px ui-monospace,monospace;letter-spacing:.13em;padding:18px;background:#eff7fb;border-radius:8px;overflow-wrap:anywhere}:deep(.cw-timeline){padding-left:22px;display:grid;gap:22px}:deep(.cw-timeline li){padding-left:7px}:deep(.cw-timeline small){color:#7a8b95}:deep(.cw-stat-grid){display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:24px;margin-top:28px}:deep(.cw-stat-grid>div){display:grid;gap:10px;padding-right:20px;border-right:1px solid #e9eef1}:deep(.cw-stat-grid>div:last-child){border:0}:deep(.cw-stat-grid strong){font-size:24px}:deep(.cw-stat-grid small){color:#718693}:deep(.cw-stat-grid p){font-size:13px;margin:0}:deep(.cw-quick-link){display:grid;gap:16px;text-decoration:none;margin-top:24px}:deep(.cw-quick-link strong){color:#087faf}
:deep(.cw-chat-layout){display:grid;grid-template-columns:240px minmax(0,1fr);gap:20px}:deep(.cw-chat-contacts){padding:12px}:deep(.cw-conversation){display:grid;width:100%;padding:16px 12px;gap:8px;border:0;background:transparent;border-radius:8px;text-align:left;color:#476273;cursor:pointer}:deep(.cw-conversation.selected){background:#eaf5fc}:deep(.cw-conversation small){overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:190px}:deep(.cw-chat-log){height:400px;overflow-y:auto;display:flex;flex-direction:column;gap:16px;margin:20px 0;padding:18px;background:#f5f8fa;border-radius:10px}:deep(.cw-chat-message){max-width:85%;align-self:flex-start}:deep(.cw-chat-message.own){align-self:flex-end}:deep(.cw-chat-message small){font-size:12px;color:#7b8e9a}:deep(.cw-chat-message p){background:white;padding:12px 16px;border-radius:10px;white-space:pre-wrap;overflow-wrap:anywhere;margin:5px 0}:deep(.cw-chat-message.own p){background:#dff2fc}
@media(max-width:1050px){:deep(.cw-account-layout){grid-template-columns:175px minmax(0,1fr);gap:18px}:deep(.cw-order-layout),:deep(.cw-checkout-layout){grid-template-columns:minmax(0,1fr)}:deep(.cw-chat-layout){grid-template-columns:190px minmax(0,1fr)}}
@media(max-width:720px){:deep(.cw-account-layout),:deep(.cw-two),:deep(.cw-chat-layout){grid-template-columns:minmax(0,1fr)}:deep(.cw-account-nav nav){grid-template-columns:repeat(2,minmax(0,1fr))}:deep(.cw-stat-grid){grid-template-columns:1fr}:deep(.cw-stat-grid>div){border:0}:deep(.cw-order-summary){align-items:flex-start;flex-direction:column}:deep(.cw-align-right){text-align:left}}
</style>
