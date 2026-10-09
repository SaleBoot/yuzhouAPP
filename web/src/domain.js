export const roleNames = { platform: '平台管理', merchant: '商家管理', agency: '经纪公司管理' }

export function entryRole(pathname) {
  const parts = pathname.split('/').filter(Boolean)
  const role = parts[0] === 'admin' ? parts[1] : parts[0]
  return Object.hasOwn(roleNames, role) ? role : null
}

export function centsFromInput(value) {
  const text = String(value).trim()
  if (!/^\d+(?:\.\d{1,2})?$/.test(text)) throw new Error('金额须为非负数字，最多两位小数')
  const [whole, fraction = ''] = text.split('.')
  const result = Number(whole) * 100 + Number(fraction.padEnd(2, '0'))
  if (!Number.isSafeInteger(result)) throw new Error('金额超出可处理范围')
  return result
}

export function basisPointsFromInput(value) {
  const result = centsFromInput(value)
  if (result > 10000) throw new Error('比例须在 0% 至 100% 之间')
  return result
}

export function money(cents) {
  return cents === undefined || cents === null ? '—' : new Intl.NumberFormat('zh-CN', { style: 'currency', currency: 'CNY' }).format(Number(cents) / 100)
}

export function displayTitle(value) {
  return typeof value === 'string' ? value.replace(/（演示(?:版)?）$/u, '').replace(/ · 演示1号$/u, ' · 1号') : value
}

export function dateTime(value) {
  if (!value) return '—'
  const date = typeof value === 'number' || /^\d{10}$/.test(String(value)) ? new Date(Number(value) * 1000) : new Date(value)
  return Number.isNaN(date.getTime()) ? String(value) : new Intl.DateTimeFormat('zh-CN', { year:'numeric', month:'2-digit', day:'2-digit', hour:'2-digit', minute:'2-digit', hour12:false }).format(date)
}

export function localDateTimeInput(value) {
  if (!value) return ''
  const date = typeof value === 'number' || /^\d{10}$/.test(String(value)) ? new Date(Number(value) * 1000) : new Date(value)
  if (Number.isNaN(date.getTime())) return ''
  return new Date(date.getTime() - date.getTimezoneOffset() * 60000).toISOString().slice(0,16)
}

export function safeAttachmentPath(value) {
  if (typeof value !== 'string' || !/^\/api\/v1\/[A-Za-z0-9_/-]+(?:\?[^#]*)?$/.test(value)) throw new Error('附件地址无效')
  return value
}

export function visibleKinds(role) {
  if (role === 'platform') return ['boat_types', 'boats', 'products', 'events', 'courses', 'coupons', 'posts', 'upgrades', 'maintenance', 'organizations', 'users','growth_products','banners']
  if (role === 'merchant') return ['boat_types', 'boats', 'events', 'upgrades', 'maintenance']
  if (role === 'agency') return ['users']
  return []
}

export const orderKinds = {
  boat_purchase: '船艇购买', boat_rental: '船艇租赁', boat_upgrade: '船艇升级', maintenance: '维修养护', mall: '商城商品', event: '活动报名'
}

export const statusNames = {
  inactive:'停用',handled:'已处理',issued:'已发放',pending_fulfillment:'实物待发放',fulfilled:'已交付',eligible:'邀请资格有效',successful: '退款成功', frozen: '退款后冻结', needs_reconfirmation: '利润待复核', unconfirmed: '利润未确认', confirmed: '已确认', draft: '草稿', pending: '待审核', submitted: '已提交', approved: '已通过', rejected: '已驳回',
  active: '启用', suspended: '停用', archived: '已下架', disabled: '停用', published: '已发布', offline: '已下架', available: '可用', rented: '出租中', sold: '已售',
  awaiting_quote: '待报价', quoted: '待确认报价', awaiting_payment: '待付款', pending_contract: '待合同', contract_pending: '合同待审',
  contract_approved: '合同已通过', pending_payment: '待付款', paid: '已付款', fulfilling: '履约中', completed: '已完成',
  refund_pending: '退款待处理', partially_refunded: '部分退款', refunded: '已退款', cancelled: '已取消', pending_profit: '待确认利润', settled: '已结算', failed: '失败'
}
