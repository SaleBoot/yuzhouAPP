export const resourceKinds = { boat_rental: 'boat_types', boat_purchase: 'boats', boat_upgrade: 'upgrades', maintenance: 'maintenance', mall: 'products' }

export function positiveId(value) {
  if (!/^[1-9]\d*$/.test(String(value)) || !Number.isSafeInteger(Number(value))) throw new Error('请选择有效项目')
  return Number(value)
}

export function rentalDates(start, end) {
  for (const day of [start, end]) {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(day) || new Date(day + 'T00:00:00Z').toISOString().slice(0, 10) !== day) throw new Error('请选择有效租赁日期')
  }
  if (end <= start) throw new Error('归还日期须晚于开始日期，归还日不计租')
}

export function checkoutPayload(form) {
  if (!Object.hasOwn(resourceKinds, form.type)) throw new Error('请选择有效业务类型')
  const quantity = Number(form.quantity)
  if (!Number.isInteger(quantity) || quantity < 1 || quantity > 50) throw new Error('数量须为 1 至 50 的整数')
  if (form.type === 'boat_purchase' && quantity !== 1) throw new Error('每笔购船订单须对应一艘实船')
  const body = { type: form.type, resource_id: positiveId(form.resource_id), quantity, notes: String(form.notes || '').trim() }
  if (form.type === 'boat_rental') {
    rentalDates(form.start_date, form.end_date)
    Object.assign(body, { start_date: form.start_date, end_date: form.end_date })
  }
  if (form.coupon_id) Object.assign(body, { coupon_id: positiveId(form.coupon_id) })
  if (form.type === 'boat_purchase') Object.assign(body, { option_ids: form.option_ids || [] })
  if (['boat_upgrade', 'maintenance'].includes(form.type)) {
    if (!form.scheduled_at || !String(form.service_location || '').trim()) throw new Error('请填写服务时间和地点')
    Object.assign(body, { scheduled_at: form.scheduled_at.replace('T', ' '), service_location: form.service_location.trim() })
  }
  return body
}

export function orderCapabilities(order, contracts, now = Date.now() / 1000) {
  const current = contracts.find(contract => Number(contract.version) === Number(order.contract_version))
  const unpaid = !Number(order.paid_cents)
  const open = !['cancelled', 'refunded'].includes(order.status)
  const contractRequired = Boolean(order.broker_id || order.type === 'boat_purchase' || order.contract_version)
  return {
    current,
    acceptQuote: order.status === 'quoted' && Number(order.quote_expires_at) > now,
    sign: unpaid && open && Boolean(current && current.review_status === 'approved' && !current.signed_at),
    pay: order.status === 'awaiting_payment' && (!contractRequired || Boolean(current?.review_status === 'approved' && current.signed_at)),
    cancel: unpaid && (['awaiting_quote', 'quoted', 'awaiting_payment'].includes(order.status) || (order.type === 'event' && !order.amount_cents && order.status === 'paid')),
    receive: Number(order.paid_cents) > Number(order.refund_cents) && (order.status === 'fulfilling' || (order.status === 'partially_refunded' && (order.events || []).some(event => event.action === 'fulfilling'))),
    refundable: Math.max(0, Number(order.paid_cents) - Number(order.refund_cents)),
    refundPending: (order.refund_requests || []).some(entry => ['pending', 'approved'].includes(entry.status))
  }
}
