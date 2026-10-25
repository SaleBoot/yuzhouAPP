// Synthetic desktop UI fixtures only. Every API request is intercepted; no provider or live database is contacted.
const { chromium } = require('playwright')
const assert = require('node:assert/strict')
const fs = require('node:fs')
const path = require('node:path')
const { spawn } = require('node:child_process')

const web = path.resolve(__dirname, '..'), base = 'http://127.0.0.1:5183'
const output = path.resolve(web, '../artifacts/desktop-web-20261025/consumer-fixtures')
fs.mkdirSync(output, { recursive: true })
const now = Math.floor(Date.now() / 1000), pdf = Buffer.from('%PDF-1.4\n% Synthetic desktop fixture, no real contract\n%%EOF')
const results = [], networkViolations = [], runtimeErrors = []
let browser, server, serverLog = '', currentCase = ''

function consumer(overrides = {}) { return { id: 7, role: 'consumer', username: 'fixture-consumer', nickname: '合成桌面用户', status: 'active', identity_status: 'verified', member_until: now + 86400 * 60, growth_points: 32, ...overrides } }
function order(id, overrides = {}) { return { id, order_no: `PC-FIXTURE-${id}`, type: 'boat_purchase', customer_id: 7, broker_id: null, org_id: 1, resource_id: 202, status: 'awaiting_payment', amount_cents: 5500000, paid_cents: 0, refund_cents: 0, contract_version: null, created_at: now, updated_at: now, items: [{ title: '合成桌面船艇', resource_id: 202, quantity: 1, unit_price_cents: 5500000 }], events: [], progress: [], refund_requests: [], schedule: null, ...overrides } }
function resources() {
  return {
    boat_types: [{ id: 101, kind: 'boat_types', title: '合成 PC 船型', status: 'published', price_cents: 12000, payload: { daily_price_cents: 12000, description: '独立桌面业务验证资料', image_urls: [], parameters: [] } }],
    boats: [
      { id: 201, kind: 'boats', title: '合成实船甲', status: 'published', price_cents: 5000000, payload: { boat_type_id: 101, unavailable: 0, serial_number: 'PC-201', options: [{ id: 'shade', title: '遮阳配置', price_cents: 2000 }] } },
      { id: 202, kind: 'boats', title: '合成实船乙', status: 'published', price_cents: 5500000, payload: { boat_type_id: 101, unavailable: 0, serial_number: 'PC-202', options: [{ id: 'solar', title: '太阳能配置', price_cents: 8000 }] } }
    ],
    products: [{ id: 301, kind: 'products', title: '合成航海用品', status: 'published', price_cents: 9900, payload: { stock: 5, image_urls: [] } }],
    courses: [{ id: 401, kind: 'courses', title: '合成桌面航海课', status: 'published', price_cents: 0, payload: { description: '免费航海课程', lessons: [{ id: 'safety', title: '安全课时', video_url: '', duration_seconds: 90 }], questions: [{ question: '选择正确的航海做法', options: ['正确航海做法', '错误航海做法'] }], rewards: [] } }],
    events: [{ id: 501, kind: 'events', title: '合成桌面航海活动', status: 'published', price_cents: 0, payload: { starts_at: '2026-12-01 10:00:00', location: '合成码头', capacity: 10 } }],
    posts: [], banners: [], upgrades: [], maintenance: [], coupons: [], growth_products: []
  }
}
function paginate(rows, url, extra = {}) {
  const page = Number(url.searchParams.get('page') || 1), size = Number(url.searchParams.get('page_size') || 20)
  return { items: rows.slice((page - 1) * size, page * size), total: rows.length, page, page_size: size, ...extra }
}
async function fixture(name, options, scenario) {
  currentCase = name
  const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, acceptDownloads: true })
  if (options.auth) await context.addInitScript(() => sessionStorage.setItem('yuzhou_desktop_consumer_token', 'fixture-consumer-token'))
  const page = await context.newPage()
  page.setDefaultTimeout(8000)
  const state = { user: consumer(options.user), resources: resources(), orders: new Map(), contracts: new Map(), orderGets: [], mutations: [], inventory: [], availableCount: 1, applications: [], enrollments: [], privateUpload: false, messages: [{ id: 1, sender_id: 8, content: '合成接待消息', created_at: now }], messageGets: [], messagePosts: [], failNextMessage: false, assertions: 0 }
  if (options.presetOrder) {
    state.orders.set(902, order(902, { contract_version: 2 }))
    state.contracts.set(902, [{ id: 12, version: 2, file_id: 702, title: '合成当前合同', review_status: 'pending', signed_at: null }, { id: 11, version: 1, file_id: 701, title: '合成旧合同', review_status: 'approved', signed_at: now - 60 }])
  }
  let fatalReject
  const fatal = new Promise((_, reject) => { fatalReject = reject })
  page.on('pageerror', error => { runtimeErrors.push({ case: name, message: error.message }); fatalReject(error) })
  await page.route('**/*', async route => {
    const request = route.request(), url = new URL(request.url()), method = request.method()
    if (url.origin !== base) {
      const violation = `${method} ${url.origin}${url.pathname}`
      networkViolations.push({ case: name, request: violation }); await route.abort(); fatalReject(new Error(`External request blocked: ${violation}`)); return
    }
    if (!url.pathname.startsWith('/api/')) return route.continue()
    const endpoint = url.pathname.slice('/api/v1'.length)
    const reply = (data, status = 200, message = 'ok') => route.fulfill({ status, contentType: 'application/json', body: JSON.stringify({ code: status === 200 ? 0 : status, message, data }) })
    try {
      assert.ok(url.pathname.startsWith('/api/v1/'), 'Only the declared API prefix is allowed')
      const body = request.headers()['content-type']?.includes('application/json') ? request.postDataJSON() : {}
      if (method !== 'GET') state.mutations.push({ endpoint, method, body })
      if (method === 'GET' && endpoint === '/bootstrap') return reply({ brand: '驭舟', is_test_environment: false, test_payments_enabled: false, resource_kinds: Object.keys(state.resources), vr_base_url: '' })
      if (method === 'POST' && endpoint === '/auth/login') {
        state.user = body.username === 'fixture-broker' ? { ...consumer(), id: 8, role: 'broker', nickname: '合成经纪人' } : consumer(options.user)
        return reply({ token: 'fixture-consumer-token', user: state.user })
      }
      if (method === 'GET' && endpoint === '/auth/me') return reply(state.user)
      if (method === 'GET' && endpoint === '/growth') return reply(paginate([], url, { points: 32 }))
      if (method === 'GET' && endpoint.startsWith('/resources/')) {
        const match = endpoint.match(/^\/resources\/([a-z_]+)(?:\/([1-9]\d*))?$/)
        assert.ok(match && Object.hasOwn(state.resources, match[1]), `Unexpected resource request ${endpoint}`)
        const rows = state.resources[match[1]]
        if (match[2]) { const row = rows.find(item => item.id === Number(match[2])); assert.ok(row, 'Only fixture resources may be read'); return reply(row) }
        return reply(paginate(rows, url))
      }
      if (method === 'GET' && endpoint === '/coupons/my') return reply(paginate([], url))
      if (method === 'GET' && endpoint === '/inventory') {
        state.inventory.push(Object.fromEntries(url.searchParams)); return reply({ boat_type_id: 101, days: 2, start_date: url.searchParams.get('start_date'), end_date: url.searchParams.get('end_date'), available_count: state.availableCount, boat_ids: state.availableCount > 1 ? [201, 202] : [201], daily_price_cents: 12000 })
      }
      if (method === 'GET' && endpoint === '/orders') {
        state.orderGets.push(Object.fromEntries(url.searchParams))
        const status = url.searchParams.get('status'), rows = [...state.orders.values()].filter(row => !status || row.status === status)
        return reply(paginate(rows, url))
      }
      if (method === 'POST' && endpoint === '/orders') {
        assert.ok(['boat_rental', 'boat_purchase', 'mall', 'maintenance', 'boat_upgrade'].includes(body.type), 'A course must never become an order')
        assert.equal(Object.hasOwn(body, 'amount_cents'), false, 'The browser must not quote an authoritative amount')
        const id = body.type === 'boat_purchase' ? 902 : 901
        const row = order(id, { type: body.type, resource_id: body.resource_id, status: body.type === 'boat_purchase' ? 'awaiting_quote' : 'awaiting_payment', amount_cents: body.type === 'boat_rental' ? 48000 : 5508000, items: [{ resource_id: body.resource_id, title: '合成已创建订单项目', quantity: body.quantity, unit_price_cents: body.type === 'boat_rental' ? 12000 : 5508000 }] })
        state.orders.set(id, row); state.contracts.set(id, []); return reply(row)
      }
      const orderMatch = endpoint.match(/^\/orders\/(\d+)(?:\/(contracts|pay|sign))?$/)
      if (orderMatch) {
        const id = Number(orderMatch[1]), row = state.orders.get(id), action = orderMatch[2]
        assert.ok(row, 'Only fixture orders may be accessed')
        if (method === 'GET' && !action) return reply({ ...row, contracts: state.contracts.get(id) || [] })
        if (method === 'GET' && action === 'contracts') return reply({ items: state.contracts.get(id) || [] })
        if (method === 'POST' && action === 'pay') return reply(null, 503, 'Real payment or refund service is not configured.')
        if (method === 'POST' && action === 'sign') {
          assert.equal(body.version, row.contract_version); assert.equal(body.accepted, true)
          const contract = state.contracts.get(id).find(item => item.version === body.version)
          assert.equal(contract.review_status, 'approved'); contract.signed_at = now; return reply(contract)
        }
      }
      if (method === 'GET' && /^\/files\/(701|702)$/.test(endpoint)) return route.fulfill({ status: 200, contentType: 'application/pdf', body: pdf })
      if (method === 'GET' && endpoint === '/applications') return reply(paginate(state.applications, url))
      if (method === 'POST' && endpoint === '/uploads') {
        assert.match(request.headers()['content-type'], /^multipart\/form-data;/)
        const raw = request.postDataBuffer().toString('latin1')
        assert.match(raw, /name="private"\r\n\r\n1\r\n/)
        assert.match(raw, /name="file";/)
        state.privateUpload = true; return reply({ id: 880, mime: 'application/pdf', size_bytes: pdf.length, private: true })
      }
      if (method === 'POST' && endpoint === '/applications') {
        assert.equal(body.kind, 'identity'); assert.deepEqual(body.file_ids, [880])
        const record = { id: 44, kind: 'identity', status: 'pending', created_at: now }
        state.applications.push(record); state.user.identity_status = 'pending'; return reply(record)
      }
      if (method === 'GET' && ['/learning/progress', '/learning/rewards'].includes(endpoint)) return reply(paginate([], url))
      if (method === 'POST' && endpoint === '/learning/exams') { assert.equal(body.course_id, 401); assert.deepEqual(body.answers, [0]); return reply({ score: 100, passed: 1 }) }
      if (method === 'GET' && endpoint === '/enrollments') return reply(paginate(state.enrollments, url))
      if (method === 'POST' && endpoint === '/events/501/enroll') {
        assert.equal(state.user.identity_status, 'verified'); assert.ok(state.user.member_until > now)
        const enrollment = { id: 51, event_id: 501, order_id: null, status: 'active', ticket_code: 'PC-FIXTURE-TICKET' }
        state.enrollments.push(enrollment); return reply({ enrollment })
      }
      if (method === 'GET' && endpoint === '/conversations') return reply(paginate([{ id: 77, members: [{ id: 7, nickname: '合成桌面用户' }, { id: 8, nickname: '合成接待人员' }], last_message: state.messages.at(-1), unread_count: 1 }], url))
      if (method === 'GET' && endpoint === '/conversations/77/messages') {
        const after = Number(url.searchParams.get('after_id') || 0); state.messageGets.push(after)
        return reply(paginate(state.messages.filter(item => item.id > after), url))
      }
      if (method === 'POST' && endpoint === '/conversations/77/messages') {
        state.messagePosts.push(body)
        if (state.failNextMessage) { state.failNextMessage = false; return reply(null, 503, '合成消息通道暂时不可用，请重试') }
        const previous = state.messages.find(item => item.client_id === body.client_id)
        if (previous) return reply(previous)
        const row = { id: state.messages.at(-1).id + 1, sender_id: 7, content: body.content, client_id: body.client_id, created_at: now }
        state.messages.push(row); return reply(row)
      }
      throw new Error(`Unmatched fixture API: ${method} ${endpoint}`)
    } catch (error) {
      networkViolations.push({ case: name, request: `${method} ${endpoint}`, reason: error.message })
      await route.abort(); fatalReject(error)
    }
  })
  const verify = (condition, label) => { state.assertions++; assert.ok(condition, label) }
  const shot = async label => { await page.evaluate(() => scrollTo(0, 0)); await page.waitForTimeout(80); return page.screenshot({ path: path.join(output, `${name}-${label}.png`), fullPage: true }) }
  try {
    await Promise.race([scenario({ page, state, verify, shot }), fatal])
    results.push({ case: name, passed: true, assertions: state.assertions, mutations: state.mutations.map(item => ({ method: item.method, endpoint: item.endpoint })) })
  } catch (error) {
    await shot('failure').catch(() => {})
    results.push({ case: name, passed: false, assertions: state.assertions, error: error.message })
  } finally { await context.close() }
}

async function main() {
  server = spawn(process.execPath, [path.join(web, 'node_modules/vite/bin/vite.js'), '--config', 'vite.site.config.ts', '--host', '127.0.0.1', '--port', '5183', '--strictPort'], { cwd: web, stdio: ['ignore', 'pipe', 'pipe'] })
  server.stdout.on('data', data => { serverLog += data.toString() }); server.stderr.on('data', data => { serverLog += data.toString() })
  for (let attempt = 0; ; attempt++) {
    if (server.exitCode !== null) throw new Error('Fixture server exited before startup: ' + serverLog.slice(-1500))
    try { if ((await fetch(base + '/site.html')).ok) break } catch {}
    if (attempt > 80) throw new Error('Fixture server failed to start')
    await new Promise(resolve => setTimeout(resolve, 150))
  }
  browser = await chromium.launch({ headless: true })
  await fixture('login-account', { auth: false }, async ({ page, state, verify, shot }) => {
    await page.goto(base + '/account')
    await page.getByRole('heading', { name: '欢迎回来' }).waitFor()
    verify(await page.getByRole('heading', { name: '账户总览', exact: true }).count() === 0, 'Unauthenticated account data stays hidden')
    await page.getByLabel('登录账号').fill('fixture-broker'); await page.getByLabel('密码', { exact: true }).fill('FixturePassword123!')
    await page.getByRole('button', { name: '登录', exact: true }).click()
    await page.getByRole('alert').filter({ hasText: '请使用用户账号登录' }).waitFor()
    verify(await page.evaluate(() => !sessionStorage.getItem('yuzhou_desktop_consumer_token')), 'Broker credentials cannot become a consumer session')
    await page.getByLabel('登录账号').fill('fixture-consumer'); await page.getByLabel('密码', { exact: true }).fill('FixturePassword123!')
    await page.getByRole('button', { name: '登录', exact: true }).click()
    await page.getByRole('heading', { name: '账户总览', exact: true }).waitFor()
    await page.getByText('会员有效', { exact: true }).waitFor()
    verify(await page.locator('iframe[src*="consumer"]').count() === 0, 'Consumer workspace uses desktop DOM')
    verify(await page.locator('body').evaluate(node => node.scrollWidth <= innerWidth), 'Desktop account has no horizontal overflow')
    await shot('overview')
  })
  await fixture('boat-detail-layout', { auth: false }, async ({ page, state, verify, shot }) => {
    const description = 'JS–910B 铝合金高速公务执法艇，用于水上巡逻、执法、指挥通信、救援急救、登临行动及取证办公展示。\n\n设备配置：5083–H116铝合金，尖艏方艉单体V形折角船底，600L燃油箱，聚脲护舷，夹层钢化前风挡，甚高频无线电话、对外扩音、探照灯、航行信号灯、长排警灯及消防救生设备。实际交付设备以最终合同与船检要求为准。\n\n内饰展示包括六座巡逻、指挥通信、救援急救、登临行动、取证办公。五套内饰为展示配置；本艇专属船检证书待提供。'
    const model = 'JS910B'.repeat(24), parameter = 'Aluminium5083H116'.repeat(24)
    const boat = state.resources.boat_types[0]
    boat.title = 'JS–910B 广州水警铝合金执法艇'
    boat.payload = { daily_price_cents: 680000, description, length_m: '9.10', capacity: 6, model_code: 'JS–910B', image_urls: ['/__fixture/boat-0.svg'], vr_url: '/__fixture/model', parameters: { model_code: model, material: parameter } }
    await page.route(`${base}/__fixture/boat-*.svg`, route => route.fulfill({ contentType: 'image/svg+xml', body: '<svg xmlns="http://www.w3.org/2000/svg" width="400" height="300"><rect width="400" height="300" fill="#e7eff6"/></svg>' }))
    await page.goto(base + '/boats/101')
    await page.getByRole('heading', { name: boat.title, exact: true }).waitFor()
    await page.locator('.detail-main-image img').evaluate(image => image.decode())
    for (const width of [1440, 1280]) {
      await page.setViewportSize({ width, height: 1000 })
      verify(await page.evaluate(() => document.querySelector('.detail-actions').getBoundingClientRect().bottom <= document.querySelector('.detail-gallery').getBoundingClientRect().bottom + 1), `${width}px: normal boat booking actions fit beside the fully loaded main image`)
      await shot(`normal-${width}px`)
    }
    boat.payload.model_code = model
    boat.payload.image_urls = Array.from({ length: 12 }, (_, index) => `/__fixture/boat-${index}.svg`)
    await page.reload()
    await page.getByRole('heading', { name: boat.title, exact: true }).waitFor()
    for (const width of [1440, 1280, 1024, 851, 850, 600, 375, 320]) {
      await page.setViewportSize({ width, height: 1000 })
      const layout = await page.evaluate(() => {
        const rect = selector => document.querySelector(selector).getBoundingClientRect()
        const contained = (node, parent) => { const box = node.getBoundingClientRect(), bounds = parent.getBoundingClientRect(); return box.left >= bounds.left - 1 && box.right <= bounds.right + 1 }
        const wraps = node => { const range = document.createRange(); range.selectNodeContents(node); return range.getClientRects().length > 1 && node.scrollWidth <= node.clientWidth + 1 }
        const summary = document.querySelector('.detail-summary'), description = document.querySelector('.detail-description'), thumbnails = document.querySelector('.detail-thumbnails')
        return {
          noOverflow: document.documentElement.scrollWidth <= innerWidth && document.body.scrollWidth <= innerWidth,
          summaryLines: description.getBoundingClientRect().height / parseFloat(getComputedStyle(description).lineHeight),
          fullDescription: document.querySelector('.article-copy').textContent,
          actionsContained: [...document.querySelectorAll('.detail-actions>.button')].length === 3 && [...document.querySelectorAll('.detail-actions>.button')].every(node => contained(node, summary)),
          thumbnailsContained: thumbnails.scrollWidth > thumbnails.clientWidth && rect('.detail-thumbnails').width <= rect('.detail-gallery').width + 1,
          modelWraps: wraps(document.querySelector('.detail-facts>span:last-child')),
          parametersWrap: [...document.querySelectorAll('.parameter-grid dd')].every(node => wraps(node) && contained(node, node.parentElement))
        }
      })
      verify(layout.noOverflow, `${width}px: detail page has no horizontal overflow`)
      verify(layout.summaryLines <= 3.05, `${width}px: description preview is at most three lines`)
      verify(layout.fullDescription === description, `${width}px: detailed introduction retains the complete original description`)
      verify(layout.actionsContained, `${width}px: booking, 3D and favorite actions stay inside the summary`)
      verify(layout.thumbnailsContained, `${width}px: thumbnails scroll within the gallery`)
      verify(layout.modelWraps && layout.parametersWrap, `${width}px: long model and parameter values wrap within their columns`)
      if ([1440, 851, 375, 320].includes(width)) await shot(`${width}px`)
    }
    boat.title = 'JS910BAluminiumPatrolBoat'.repeat(12)
    await page.reload()
    await page.getByRole('heading', { name: boat.title, exact: true }).waitFor()
    verify(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth && document.body.scrollWidth <= innerWidth), 'A long unbroken title also stays inside the narrow detail page')
    verify(state.mutations.length === 0, 'Layout verification performs no business mutations')
  })
  await fixture('rental-inventory', { auth: true }, async ({ page, state, verify, shot }) => {
    await page.goto(base + '/checkout?kind=boat_types&id=101&type=boat_rental')
    await page.getByRole('heading', { name: '合成 PC 船型', exact: true }).first().waitFor()
    await page.getByLabel('开始日期').fill('2026-12-01'); await page.getByLabel('归还日期').fill('2026-12-03'); await page.getByLabel('船艇数量').fill('2')
    await page.getByRole('button', { name: '查询可用船艇', exact: true }).click()
    await page.getByText(/当前区间可用 1 艘/).waitFor()
    await page.getByRole('button', { name: '提交订单', exact: true }).click()
    await page.getByRole('alert').filter({ hasText: '船艇数量不足' }).waitFor()
    verify(state.mutations.filter(item => item.endpoint === '/orders').length === 0, 'Insufficient rental stock never posts an order')
    state.availableCount = 3
    await page.getByRole('button', { name: '提交订单', exact: true }).click()
    await page.waitForURL('**/orders/901'); await page.getByRole('heading', { name: '订单详情', exact: true }).waitFor()
    const created = state.mutations.find(item => item.endpoint === '/orders').body
    verify(created.resource_id === 101 && created.quantity === 2 && created.type === 'boat_rental', 'Rental submits the chosen type and quantity')
    verify(!Object.hasOwn(created, 'amount_cents') && state.inventory.length >= 3, 'The server confirms amount and rechecks inventory on submit')
    await shot('order')
  })
  await fixture('orders-pagination', { auth: true }, async ({ page, state, verify, shot }) => {
    for (let index = 1; index <= 21; index++) state.orders.set(1000 + index, order(1000 + index, { type: 'mall', status: index === 21 ? 'completed' : 'awaiting_payment' }))
    await page.goto(base + '/orders')
    await page.getByRole('heading', { name: '我的订单', exact: true }).waitFor()
    await page.getByRole('cell', { name: 'PC-FIXTURE-1001', exact: false }).waitFor()
    verify(await page.locator('tbody tr').count() === 20, 'Order table renders the first complete server page')
    await page.getByRole('button', { name: '下一页', exact: true }).click()
    await page.getByRole('cell', { name: 'PC-FIXTURE-1021', exact: false }).waitFor()
    verify(await page.locator('tbody tr').count() === 1, 'Next page reaches records beyond the initial page')
    const filtered = page.waitForResponse(response => new URL(response.url()).pathname === '/api/v1/orders' && new URL(response.url()).searchParams.get('status') === 'completed')
    await page.getByLabel('订单状态').selectOption('completed')
    await filtered
    await page.getByRole('cell', { name: 'PC-FIXTURE-1021', exact: false }).waitFor()
    verify(state.orderGets.at(-1).page === '1' && state.orderGets.at(-1).status === 'completed' && await page.locator('tbody tr').count() === 1 && await page.locator('tbody .status-badge').innerText() === '已完成', 'Changing the status filter resets pagination and preserves the server result')
    await shot('filtered-table')
  })
  await fixture('specific-purchase', { auth: true }, async ({ page, state, verify, shot }) => {
    await page.goto(base + '/checkout?kind=boat_types&id=101&type=boat_purchase')
    await page.getByLabel('选择实际船艇').selectOption('202')
    await page.getByLabel(/太阳能配置/).check()
    await shot('selection')
    await page.getByRole('button', { name: '提交订单', exact: true }).click()
    await page.waitForURL('**/orders/902')
    const created = state.mutations.find(item => item.endpoint === '/orders').body
    verify(created.resource_id === 202 && created.resource_id !== 101 && created.quantity === 1, 'A purchase refers to the selected actual boat rather than its model')
    verify(JSON.stringify(created.option_ids) === '["solar"]', 'Selected configuration keys retain their server identity')
  })
  await fixture('contract-payment', { auth: true, presetOrder: true }, async ({ page, state, verify, shot }) => {
    await page.goto(base + '/orders/902')
    await page.getByRole('button', { name: '发起支付', exact: true }).waitFor()
    verify(await page.getByRole('button', { name: '发起支付', exact: true }).isDisabled(), 'An approved older contract never unlocks the pending current version')
    verify(await page.getByRole('button', { name: '确认签署当前版', exact: true }).count() === 0, 'Pending current version cannot be accepted')
    const download = page.waitForEvent('download')
    await page.locator('.cw-record').filter({ hasText: '合成当前合同' }).getByRole('button', { name: '下载合同' }).click()
    verify((await download).suggestedFilename().includes('v2'), 'Current PDF downloads through an authenticated blob request')
    state.contracts.get(902)[0].review_status = 'approved'
    await page.getByRole('button', { name: '刷新状态', exact: true }).click()
    await page.getByLabel('我已阅读并在线下签署当前 v2 合同').check()
    await page.getByRole('button', { name: '确认签署当前版', exact: true }).click()
    await page.getByText('已确认线下签署', { exact: false }).first().waitFor()
    verify(state.mutations.find(item => item.endpoint.endsWith('/sign')).body.version === 2, 'Acceptance submits the current version explicitly')
    await page.getByRole('button', { name: '发起支付', exact: true }).click()
    await page.getByRole('alert').filter({ hasText: '此支付通道暂未开通' }).waitFor()
    verify(state.orders.get(902).status === 'awaiting_payment' && state.orders.get(902).paid_cents === 0, '503 payment does not manufacture a paid state')
    verify(await page.locator('.cw-order-summary .status-badge').innerText() === '待付款', 'Payment failure also leaves the rendered status awaiting payment')
    verify(await page.getByRole('option', { name: '开发测试支付（不扣款）' }).count() === 0, 'Production payment choices do not expose a fabricated test success')
    verify(state.mutations.find(item => item.endpoint.endsWith('/pay')).body.channel === 'wechat', 'Real payment uses the selected actual channel')
    await shot('unconfigured-payment')
  })
  await fixture('private-identity', { auth: true, user: { identity_status: 'unverified' } }, async ({ page, state, verify, shot }) => {
    await page.goto(base + '/account/identity')
    await page.getByLabel('姓名', { exact: true }).fill('合成审核姓名')
    await page.getByLabel('材料说明', { exact: true }).fill('仅供离线界面验证')
    await page.locator('input[type="file"]').setInputFiles({ name: 'synthetic-identity.pdf', mimeType: 'application/pdf', buffer: pdf })
    await page.getByText(/审核材料 1/).waitFor()
    await page.getByRole('button', { name: '提交审核', exact: true }).click()
    await page.getByText('申请已提交，等待审核，请勿重复提交。').waitFor()
    verify(state.privateUpload, 'Private review upload sends multipart private=1')
    verify(state.applications.length === 1 && state.applications[0].status === 'pending', 'Review application stays pending until a real review result')
    await shot('pending-review')
  })
  await fixture('learning-not-commerce', { auth: true }, async ({ page, state, verify, shot }) => {
    await page.goto(base + '/checkout?kind=courses&id=401')
    await page.getByRole('heading', { name: '合成桌面航海课', exact: true }).waitFor()
    await page.getByRole('button', { name: '提交考试', exact: true }).click()
    await page.getByRole('alert').filter({ hasText: '请完成所有题目' }).waitFor()
    verify(!state.mutations.some(item => item.endpoint === '/learning/exams'), 'Incomplete answers do not reach the API')
    await page.getByLabel('正确航海做法', { exact: true }).check()
    await page.getByRole('button', { name: '提交考试', exact: true }).click()
    await page.getByRole('status').filter({ hasText: '考试通过' }).waitFor()
    verify(state.mutations.some(item => item.endpoint === '/learning/exams') && !state.mutations.some(item => item.endpoint === '/orders'), 'A course submits learning results and never creates a purchase order')
    await shot('exam')
  })
  await fixture('activity-eligibility', { auth: true, user: { identity_status: 'unverified', member_until: now - 1 } }, async ({ page, state, verify, shot }) => {
    await page.goto(base + '/checkout?kind=events&id=501')
    await page.getByRole('button', { name: '立即报名', exact: true }).waitFor()
    verify(await page.getByRole('button', { name: '立即报名', exact: true }).isDisabled(), 'Actual review and membership state gate activity enrollment')
    verify(!state.mutations.some(item => item.endpoint === '/events/501/enroll'), 'Ineligible account never posts enrollment')
    state.user.identity_status = 'verified'; state.user.member_until = now + 86400
    await page.getByRole('button', { name: '刷新记录', exact: true }).click()
    await page.getByRole('button', { name: '立即报名', exact: true }).click()
    await page.getByText('PC-FIXTURE-TICKET', { exact: true }).waitFor()
    verify(state.enrollments.length === 1 && !state.mutations.some(item => item.endpoint === '/orders'), 'Eligible free activity displays the server ticket without inventing a payment')
    await shot('ticket')
  })
  await fixture('messages-retry', { auth: true }, async ({ page, state, verify, shot }) => {
    await page.goto(base + '/account/conversations?conversation=77')
    await page.getByText('合成接待消息', { exact: true }).last().waitFor()
    state.messages.push({ id: 2, sender_id: 8, content: '合成增量消息', created_at: now })
    await page.getByRole('button', { name: '刷新', exact: true }).click()
    await page.getByText('合成增量消息', { exact: true }).waitFor()
    verify(state.messageGets.includes(1), 'Subsequent reads request only messages after the current ID')
    state.failNextMessage = true
    await page.getByLabel('发送消息', { exact: true }).fill('合成桌面消息重试')
    await page.getByRole('button', { name: '发送', exact: true }).click()
    await page.getByRole('alert').filter({ hasText: '合成消息通道暂时不可用' }).waitFor()
    verify(await page.getByLabel('发送消息', { exact: true }).inputValue() === '合成桌面消息重试', 'A failed send preserves the draft')
    await page.getByRole('button', { name: '发送', exact: true }).click()
    await page.getByText('合成桌面消息重试', { exact: true }).waitFor()
    verify(state.messagePosts.length === 2 && state.messagePosts[0].client_id === state.messagePosts[1].client_id, 'A retry keeps the same idempotent client message ID')
    verify(state.messages.filter(item => item.content === '合成桌面消息重试').length === 1, 'Only one sent message is stored by the fixture')
    verify(await page.locator('.cw-chat-log').getByText('合成桌面消息重试', { exact: true }).count() === 1 && await page.getByLabel('发送消息', { exact: true }).inputValue() === '', 'Successful retry clears the draft and renders the message once')
    await shot('conversation')
  })
}

main().catch(error => { results.push({ case: currentCase || 'startup', passed: false, error: error.message }) }).finally(async () => {
  if (browser) await browser.close()
  if (server && server.exitCode === null) { server.kill('SIGTERM'); await new Promise(resolve => { server.once('exit', resolve); setTimeout(resolve, 3000) }) }
  const summary = { checked_at_utc: new Date().toISOString(), origin: base, api_mode: 'All API responses are synthetic local fixtures; unmatched API and external requests abort immediately', real_provider_or_live_database_acceptance: false, cases: results, passed: results.filter(item => item.passed).length, failed: results.filter(item => !item.passed).length, assertions: results.reduce((sum, item) => sum + (item.assertions || 0), 0), network_violations: networkViolations, runtime_errors: runtimeErrors, own_vite_process_exited: !server || server.exitCode !== null || server.signalCode !== null }
  fs.writeFileSync(path.join(output, 'summary.json'), JSON.stringify(summary, null, 2) + '\n')
  fs.writeFileSync(path.join(output, 'vite.log'), serverLog)
  console.log(JSON.stringify({ ...summary, cases: results.map(item => ({ case: item.case, passed: item.passed, assertions: item.assertions, error: item.error })) }, null, 2))
  if (summary.failed || networkViolations.length || runtimeErrors.length || !summary.own_vite_process_exited) process.exitCode = 1
})
