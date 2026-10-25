export const customerStages = { new: '新客户', contacted: '已联系', viewing: '带看中', quoted: '已报价', won: '已成交', lost: '暂未成交', archived: '已归档' }
export const brokerOrderTypes = { boat_purchase: '船艇购买', boat_rental: '船艇租赁', boat_upgrade: '船艇升级', maintenance: '维修养护' }
export const resourceKinds = { boat_purchase: 'boats', boat_rental: 'boat_types', boat_upgrade: 'upgrades', maintenance: 'maintenance' }
export const progressStages = { planned: '已计划', preparing: '准备中', installing: '安装中', testing: '调试中', delivery: '交付中', completed: '进度已完成' }

function positiveInteger(value, label, maximum = Number.MAX_SAFE_INTEGER) {
  const result = Number(value)
  if (!Number.isSafeInteger(result) || result < 1 || result > maximum) throw new Error(`${label}须为1至${maximum === Number.MAX_SAFE_INTEGER ? '安全整数上限' : maximum}的整数`)
  return result
}

function dateValue(value) {
  if (typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(value)) throw new Error('请选择有效日期')
  const date = new Date(`${value}T00:00:00Z`)
  if (!Number.isFinite(date.getTime()) || date.toISOString().slice(0, 10) !== value) throw new Error('请选择有效日期')
  return date.getTime()
}

export function scheduledAt(value) {
  if (!/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(value) || !Number.isFinite(new Date(value).getTime())) throw new Error('请选择有效预约时间')
  dateValue(value.slice(0, 10))
  const [hour, minute] = value.slice(11).split(':').map(Number)
  if (hour > 23 || minute > 59) throw new Error('请选择有效预约时间')
  if (new Date(value).getTime() <= Date.now()) throw new Error('预约时间必须晚于当前时间')
  return value.replace('T', ' ') + ':00'
}

export function brokerOrderInput(form) {
  if (!Object.hasOwn(brokerOrderTypes, form.type)) throw new Error('请选择有效销售订单类型')
  const body = { type: form.type, customer_id: positiveInteger(form.customer_id, '客户编号'), resource_id: positiveInteger(form.resource_id, '船艇或服务编号'), quantity: form.type === 'boat_purchase' ? 1 : positiveInteger(form.quantity, '数量', 50), option_ids: [...form.option_ids], notes: form.notes.trim() }
  if (form.type === 'boat_rental') {
    const start = dateValue(form.start_date), end = dateValue(form.end_date)
    const days = (end - start) / 86400000
    if (days < 1 || days > 90) throw new Error('租期须为1至90天，归还日期应晚于开始日期')
    Object.assign(body, { start_date: form.start_date, end_date: form.end_date })
  }
  if (['boat_upgrade', 'maintenance'].includes(form.type)) {
    if (!form.service_location.trim()) throw new Error('请填写服务地点')
    Object.assign(body, { scheduled_at: scheduledAt(form.scheduled_at), service_location: form.service_location.trim() })
  }
  return body
}

export function progressInput(stage, percent, notes, minimum = 0) {
  const value = Number(percent)
  if (!Object.hasOwn(progressStages, stage) || !Number.isInteger(value) || value < minimum || value > 100) throw new Error(`请选择有效进度阶段，百分比须为${minimum}至100的整数`)
  return { stage, percent: value, notes: notes.trim() }
}

export function withdrawalAttempt(raw, amount, newKey) {
  try {
    const previous = JSON.parse(raw)
    if (previous?.amount_cents === amount && /^[A-Za-z0-9_-]{8,100}$/.test(previous.idempotency_key)) return previous
  } catch { /* An interrupted local write must not prevent a new request. */ }
  return { amount_cents: amount, idempotency_key: newKey }
}

export async function allPages(fetchPage) {
  const items = new Map()
  for (let page = 1; ; page++) {
    const result = await fetchPage(page)
    if (!Array.isArray(result.items) || result.page !== page || !Number.isSafeInteger(result.total) || result.total < 0 || !Number.isSafeInteger(result.page_size) || result.page_size < 1) throw new Error('列表分页信息不完整，请刷新重试')
    const before = items.size
    for (const item of result.items) items.set(item.id, item)
    if (page * result.page_size >= result.total) return [...items.values()]
    if (!result.items.length || items.size === before) throw new Error('列表分页未前进，请刷新重试')
  }
}
