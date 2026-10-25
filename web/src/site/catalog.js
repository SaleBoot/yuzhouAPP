export const catalogNames = { boat_types: '船艇租售', products: '周边商城', maintenance: '维保服务', upgrades: '船艇升级', courses: '航海课堂', events: '航海活动', posts: '航海资讯' }
export const categoryNames = { yacht: '游艇', leisure: '休闲船', passenger: '游览船', workboat: '作业艇', rescue: '救援艇', official: '公务船', fishing: '钓鱼艇' }

export function resourcePath(item) {
  return item.kind === 'boat_types' ? `/boats/${item.id}` : `/resources/${encodeURIComponent(item.kind)}/${item.id}`
}

export function imageUrl(value) {
  if (typeof value !== 'string') return ''
  if (value.startsWith('/') && !value.startsWith('//')) return value
  try { const url = new URL(value); return ['https:', 'http:'].includes(url.protocol) ? url.href : '' } catch { return '' }
}

export function minimumPurchasePrice(typeId, boats) {
  const amounts = boats.filter(item => Number(item.payload?.boat_type_id) === Number(typeId) && !Number(item.payload?.unavailable))
    .map(item => item.price_cents).filter(value => Number.isSafeInteger(value) && value > 0)
  return amounts.length ? Math.min(...amounts) : null
}

export function catalogFilter(items, values, purchasePrices = {}) {
  const search = String(values.search || '').trim().toLowerCase(), city = String(values.city || '').trim().toLowerCase()
  const rows = items.filter(item => {
    const payload = item.payload || {}
    return (!search || `${item.title || ''} ${payload.model_code || ''}`.toLowerCase().includes(search)) &&
      (!values.category || payload.category === values.category) &&
      (!city || `${payload.city || ''} ${payload.location || ''}`.toLowerCase().includes(city))
  })
  const price = item => values.mode === 'buy' ? (purchasePrices[item.id] ?? Infinity) : (item.payload?.daily_price_cents ?? item.price_cents ?? Infinity)
  if (values.sort === 'price_asc') rows.sort((a, b) => price(a) - price(b))
  if (values.sort === 'price_desc') rows.sort((a, b) => {
    const x = price(a), y = price(b)
    return x === y ? 0 : x === Infinity ? 1 : y === Infinity ? -1 : y - x
  })
  return rows
}
