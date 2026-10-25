<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { query, request, siteSession } from '../api'
import { money, orderKinds, displayTitle } from '../../domain.js'
import { checkoutPayload, positiveId, rentalDates, resourceKinds } from './domain.js'

type Row = Record<string, any>
const props = defineProps<{ id: string; kind?: string }>()
const route = useRoute(), router = useRouter()
const item = ref<Row | null>(null), instances = ref<Row[]>([]), coupons = ref<Row[]>([]), availability = ref<Row | null>(null)
const error = ref(''), loading = ref(false), busy = ref(false), inventoryBusy = ref(false)
const form = reactive({ type: 'boat_rental', resource_id: '', quantity: 1, start_date: '', end_date: '', coupon_id: '', option_ids: [] as string[], scheduled_at: '', service_location: '', notes: '' })
const appointment = computed(() => form.type === 'appointment')
const selectedBoat = computed(() => instances.value.find(boat => Number(boat.id) === Number(form.resource_id)))
const options = computed<Row[]>(() => selectedBoat.value?.payload?.options || [])
const heading = computed(() => appointment.value ? '预约看船' : (orderKinds as Record<string, string>)[form.type] || '确认业务需求')
let revision = 0
watch(() => [form.start_date, form.end_date, form.quantity], () => { availability.value = null })
watch(() => form.resource_id, () => { form.option_ids = [] })

async function load() {
  const version = ++revision, token = siteSession.token
  error.value = ''; loading.value = true; item.value = null; instances.value = []; availability.value = null; coupons.value = []
  form.type = String(route.query.type || (props.kind === 'products' ? 'mall' : props.kind === 'upgrades' ? 'boat_upgrade' : props.kind === 'maintenance' ? 'maintenance' : props.kind === 'boats' ? 'boat_purchase' : 'boat_rental'))
  form.option_ids = []; form.coupon_id = ''; form.quantity = Number(route.query.quantity || 1); form.resource_id = props.id
  try {
    if (!appointment.value && !Object.hasOwn(resourceKinds, form.type)) throw new Error('该项目不能通过此页面下单')
    const resourceKind = props.kind || (appointment.value || form.type === 'boat_purchase' ? 'boat_types' : (resourceKinds as Record<string, string>)[form.type])
    if (!['boat_types', 'boats', 'products', 'upgrades', 'maintenance'].includes(resourceKind)) throw new Error('请选择有效业务项目')
    const resource = await request<Row>(`/resources/${resourceKind}/${positiveId(props.id)}`)
    if (version !== revision || token !== siteSession.token) return
    item.value = resource
    if (form.type === 'boat_purchase') {
      if (resourceKind === 'boats') instances.value = [resource]
      else {
        let page = 1, total = Infinity
        const boats: Row[] = []
        while (boats.length < total) {
          const result = await request<{ items: Row[]; total: number }>(`/resources/boats${query({ page, page_size: 100 })}`)
          if (version !== revision || token !== siteSession.token) return
          if (!Array.isArray(result.items) || !Number.isInteger(result.total) || result.total < 0 || (!result.items.length && boats.length < result.total)) throw new Error('实船列表不完整，请重试')
          boats.push(...result.items); total = result.total; page++
        }
        instances.value = boats.filter(boat => Number(boat.payload?.boat_type_id) === Number(props.id) && !boat.payload?.unavailable)
      }
      const instance = route.query.instance_id ? instances.value.find(boat => Number(boat.id) === Number(route.query.instance_id)) : instances.value[0]
      form.resource_id = instance ? String(instance.id) : ''
    }
    if (!appointment.value) {
      const result = await request<{ items: Row[] }>('/coupons/my?page_size=100')
      if (version !== revision || token !== siteSession.token) return
      coupons.value = (result.items || []).filter(coupon => coupon.status === 'available')
    }
  } catch (cause) {
    if (version === revision && token === siteSession.token) error.value = cause instanceof Error ? cause.message : '项目加载失败'
  } finally { if (version === revision) loading.value = false }
}
watch([() => props.id, () => props.kind, () => route.query.type, () => route.query.instance_id, () => route.query.quantity, () => siteSession.token], load, { immediate: true })

async function checkInventory() {
  rentalDates(form.start_date, form.end_date)
  const quantity = Number(form.quantity)
  if (!Number.isInteger(quantity) || quantity < 1 || quantity > 50) throw new Error('数量须为 1 至 50 的整数')
  const result = await request<Row>(`/inventory${query({ boat_type_id: positiveId(props.id), start_date: form.start_date, end_date: form.end_date, quantity })}`)
  availability.value = result
  return Number(result.available_count) >= quantity
}
async function inspectInventory() {
  if (inventoryBusy.value || busy.value) return
  inventoryBusy.value = true; error.value = ''
  try { await checkInventory() } catch (cause) { error.value = cause instanceof Error ? cause.message : '库存查询失败' }
  finally { inventoryBusy.value = false }
}
async function submit() {
  if (busy.value || inventoryBusy.value || loading.value || !item.value) return
  const token = siteSession.token, version = revision
  busy.value = true; error.value = ''
  try {
    if (appointment.value) {
      if (!form.scheduled_at) throw new Error('请选择预约时间')
      await request('/appointments', { method: 'POST', body: JSON.stringify({ resource_id: positiveId(props.id), scheduled_at: form.scheduled_at.replace('T', ' '), notes: form.notes }) })
      if (token === siteSession.token && version === revision) await router.push('/account?tab=appointments')
      return
    }
    const body = checkoutPayload(form)
    if (form.type === 'boat_rental' && !await checkInventory()) throw new Error('所选日期船艇数量不足，请调整日期或数量')
    if (token !== siteSession.token || version !== revision) return
    const result = await request<Row>('/orders', { method: 'POST', body: JSON.stringify(body) })
    if (token === siteSession.token && version === revision) await router.push(`/orders/${positiveId(result.id)}`)
  } catch (cause) {
    if (token === siteSession.token && version === revision) error.value = cause instanceof Error ? cause.message : '提交失败'
  } finally { busy.value = false }
}
</script>

<template>
  <div>
    <div class="page-heading"><div><span class="cw-kicker">YUZHOU · 服务与订单</span><h1>{{ heading }}</h1><p class="muted">提交需求，跟进预约、报价与订单进度。</p></div><RouterLink to="/orders" class="text-button">我的订单 →</RouterLink></div>
    <p v-if="error" class="notice danger" role="alert">{{ error }}</p><p v-if="loading" class="empty">正在读取项目与可用选项…</p>
    <div v-if="item" class="cw-checkout-layout">
      <form class="panel cw-form" @submit.prevent="submit"><fieldset class="cw-form" :disabled="busy || inventoryBusy || loading">
        <h2>{{ displayTitle(item.title) }}</h2>
        <label v-if="form.type === 'boat_purchase'">选择实际船艇<select v-model="form.resource_id" required><option value="" disabled>请选择实际船艇</option><option v-for="boat in instances" :key="boat.id" :value="String(boat.id)">{{ displayTitle(boat.title) }} · {{ boat.payload?.serial_number || boat.id }} · {{ money(boat.price_cents) }}</option></select><small v-if="!instances.length">暂无可购买实船，请联系销售。</small></label>
        <div v-if="form.type === 'boat_rental'" class="cw-two"><label>开始日期<input v-model="form.start_date" type="date" required></label><label>归还日期<input v-model="form.end_date" type="date" :min="form.start_date" required></label></div>
        <label v-if="!appointment && form.type !== 'boat_purchase'">{{ form.type === 'boat_rental' ? '船艇数量' : '数量' }}<input v-model.number="form.quantity" type="number" min="1" max="50" step="1" required></label>
        <template v-if="form.type === 'boat_rental'"><button type="button" class="button" :disabled="busy || inventoryBusy" @click="inspectInventory">{{ inventoryBusy ? '查询中…' : '查询可用船艇' }}</button><p v-if="availability" class="notice">当前区间可用 {{ availability.available_count }} 艘，租期 {{ availability.days }} 天。实际分配以提交订单时为准。</p></template>
        <div v-if="options.length" class="cw-options"><h3>购船配置</h3><label v-for="option in options" :key="option.id" class="cw-check"><input v-model="form.option_ids" type="checkbox" :value="String(option.id)"><span>{{ option.title }} · {{ money(option.price_cents) }}</span></label></div>
        <template v-if="appointment || ['boat_upgrade', 'maintenance'].includes(form.type)"><label>{{ appointment ? '看船时间' : '服务时间' }}<input v-model="form.scheduled_at" type="datetime-local" required></label><label v-if="!appointment">服务地点<input v-model="form.service_location" maxlength="300" required></label></template>
        <label v-if="!appointment">使用优惠券<select v-model="form.coupon_id"><option value="">不使用优惠券</option><option v-for="coupon in coupons" :key="coupon.id" :value="String(coupon.id)">{{ coupon.coupon?.title || '优惠券' }} · 优惠 {{ money(coupon.coupon?.payload?.discount_cents) }}</option></select><small>每订单限一张，适用范围与优惠以订单结果为准。</small></label>
        <label>需求备注<textarea v-model="form.notes" rows="4" maxlength="2000" placeholder="出行、配置、看船地点或交付需求" /></label>
        <button class="button primary" :disabled="busy || inventoryBusy || loading || (form.type === 'boat_purchase' && !form.resource_id)">{{ busy ? '正在提交…' : appointment ? '提交看船预约' : '提交订单' }}</button>
      </fieldset></form>
      <aside class="cw-stack"><section class="panel"><span class="cw-kicker">所选项目</span><h2>{{ displayTitle(item.title) }}</h2><p class="cw-price">{{ money(form.type === 'boat_purchase' ? selectedBoat?.price_cents : item.price_cents) }}<small v-if="form.type === 'boat_rental'"> / 天</small></p><p>{{ item.payload?.description }}</p></section><section class="panel"><h2>提交前确认</h2><p>租赁按自然日计费，归还日不计租。同一船型按所选日期的实际可用船艇安排。</p><p>购船和定制服务提交后，由销售确认配置与报价。合同采用线下签署并上传审核。</p><p>最终金额、优惠、合同和可用船艇以订单为准。提交订单不会自动扣款。</p></section></aside>
    </div>
  </div>
</template>
