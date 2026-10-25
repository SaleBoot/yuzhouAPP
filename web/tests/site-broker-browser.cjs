// Browser fixtures check desktop wiring only; they do not prove live business acceptance.
const { chromium } = require('playwright')
const assert = require('node:assert/strict')
const fs = require('node:fs')
const path = require('node:path')
const base = process.env.SITE_TEST_ORIGIN || 'http://127.0.0.1:5180'
const output = process.env.SITE_TEST_OUTPUT || path.resolve(__dirname, '../../artifacts/pc-web-broker')

async function main() {
  fs.mkdirSync(output, { recursive: true })
  const browser = await chromium.launch({ headless: true })
  const page = await browser.newPage({ viewport: { width: 1512, height: 982 } })
  page.setDefaultTimeout(10000)
  const errors = [], mutations = [], unexpected = []
  const now = Math.floor(Date.now() / 1000)
  const user = { id: 31, role: 'broker', org_id: 4, nickname: '本地销售测试', username: 'local-broker', status: 'active' }
  const customers = [{ id: 1, user_id: 11, name: '本地测试客户', stage: 'new', notes: '试驾需求', tags: [], updated_at: now }]
  const tags = [{ id: 1, name: '购船意向', status: 'active', org_id: 4 }]
  const resources = { boats: [{ id: 51, title: '本地测试实船', price_cents: 100000, payload: { options: [{ id: 'deck', title: '甲板选配', price_cents: 1000 }] } }], boat_types: [{ id: 61, title: '本地测试船型', price_cents: 10000, payload: {} }], upgrades: [], maintenance: [] }
  const orders = [], appointments = [], withdrawals = []
  const messages = [{ id: 1, sender_id: 11, content: '咨询船艇配置', created_at: now }]
  let withdrawalAttempts = 0
  const paginate = (items, url) => {
    const current = Number(url.searchParams.get('page') || 1), size = Number(url.searchParams.get('page_size') || 20)
    return { items: items.slice((current - 1) * size, current * size), total: items.length, page: current, page_size: size }
  }
  page.on('pageerror', error => errors.push(error.message))
  await page.addInitScript(() => sessionStorage.setItem('yuzhou_desktop_broker_token', 'local-broker-fixture'))
  await page.route('**/api/v1/**', async route => {
    const req = route.request(), url = new URL(req.url()), pathname = url.pathname.slice('/api/v1'.length), method = req.method()
    const body = req.headers()['content-type']?.includes('application/json') ? req.postDataJSON() : {}
    const send = (data, status = 200, message = 'ok') => route.fulfill({ status, contentType: 'application/json', body: JSON.stringify({ code: status === 200 ? 0 : status, message, data }) })
    if (method !== 'GET') mutations.push({ path: pathname, method, body })
    if (pathname === '/auth/me') return send(user)
    if (pathname === '/bootstrap') return send({ services: { payment: 'unconfigured' }, is_test_environment: false, vr_base_url: '' })
    if (pathname === '/agency-targets') return send({ month: '2099-01', configured: false, actual_net_paid_cents: 0 })
    if (pathname === '/customer-tags') return send(paginate(tags, url))
    if (pathname === '/customers') {
      if (method === 'POST') { customers.push({ ...body, id: customers.length + 1, stage: 'new', tags: [], updated_at: now }); return send(customers.at(-1)) }
      const search = url.searchParams.get('search') || ''
      return send(paginate(customers.filter(row => (row.name + row.notes).includes(search)), url))
    }
    if (/^\/customers\/\d+(?:\/tags)?$/.test(pathname)) {
      const row = customers.find(item => item.id === Number(pathname.split('/')[2]))
      assert.ok(row)
      if (pathname.endsWith('/tags')) row.tags = tags.filter(tag => body.tag_ids.includes(tag.id))
      else Object.assign(row, body)
      return send(row)
    }
    if (pathname.startsWith('/resources/')) return send(paginate(resources[pathname.split('/')[2]] || [], url))
    if (pathname === '/orders') {
      if (method === 'POST') {
        const resource = Object.values(resources).flat().find(row => row.id === body.resource_id)
        const order = { ...body, id: orders.length + 101, order_no: `LOCAL-BROKER-${orders.length + 101}`, broker_id: user.id, org_id: user.org_id, status: 'awaiting_quote', amount_cents: resource.price_cents, original_amount_cents: resource.price_cents, discount_cents: 0, paid_cents: 0, refund_cents: 0, created_at: now, contracts: [], events: [], progress: [], schedule: null, items: [{ title: resource.title, quantity: body.quantity, unit_price_cents: resource.price_cents, options: [] }] }
        orders.push(order)
        return send(order)
      }
      return send(paginate(orders, url))
    }
    if (/^\/orders\/\d+/.test(pathname)) {
      const order = orders.find(row => row.id === Number(pathname.split('/')[2]))
      assert.ok(order)
      if (method === 'GET') return send(order)
      const action = pathname.split('/')[3]
      if (action === 'quote') Object.assign(order, { amount_cents: body.amount_cents, original_amount_cents: body.amount_cents, status: 'quoted', contract_version: null })
      else if (action === 'contract') { order.contract_version = 1; order.contracts.push({ id: 1, version: 1, file_id: body.file_id, title: body.title, review_status: 'pending', signed_at: null, file_sha256: 'local-fixture-sha256' }) }
      else if (action === 'progress') order.progress.push({ ...body, id: order.progress.length + 1, created_at: now })
      else if (action === 'fulfill') order.status = body.status
      else if (action === 'cancel') order.status = 'cancelled'
      else if (action === 'schedule') order.schedule = body
      else { unexpected.push(`${method} ${pathname}`); return send(null, 404, 'Unknown fixture mutation') }
      return send(order)
    }
    if (pathname === '/uploads') {
      assert.match(req.postData(), /name="private"\r\n\r\n1/)
      return send({ id: 99, private: true })
    }
    if (pathname === '/appointments') {
      if (method === 'POST') { appointments.push({ ...body, id: appointments.length + 1, status: 'pending' }); return send(appointments.at(-1)) }
      return send(paginate(appointments, url))
    }
    if (/^\/appointments\/\d+$/.test(pathname)) { const row = appointments.find(item => item.id === Number(pathname.split('/')[2])); Object.assign(row, body); return send(row) }
    if (pathname === '/ledger') return send({ ...paginate([], url), balance: { available_cents: 200000, pending_cents: 10000, withdrawn_and_reserved_cents: 0, debt_cents: 0 } })
    if (pathname === '/withdrawals') {
      if (method === 'POST') {
        withdrawalAttempts++
        if (withdrawalAttempts === 1) return send(null, 503, '本地合成网络故障，请重试')
        withdrawals.push({ ...body, id: 1, status: 'pending', created_at: now })
        return send(withdrawals.at(-1))
      }
      return send(paginate(withdrawals, url))
    }
    if (pathname === '/conversations') return send(paginate([{ id: 41, members: [{ id: user.id, nickname: user.nickname }, { id: 11, nickname: '本地测试客户' }], last_message: messages.at(-1), unread_count: 1, updated_at: now }], url))
    if (pathname === '/conversations/41/messages') {
      if (method === 'POST') { messages.push({ ...body, id: messages.length + 1, sender_id: user.id, created_at: now }); return send(messages.at(-1)) }
      return send(paginate(messages, url))
    }
    if (pathname === '/conversations/41/read') return send({ read_message_id: messages.length })
    unexpected.push(`${method} ${pathname}`)
    return send(null, 404, 'Unknown fixture request')
  })
  const open = async pathname => { await page.goto(base + pathname) }
  try {
    await open('/sales/customers')
    await page.getByText('本地测试客户', { exact: true }).waitFor()
    await page.getByRole('button', { name: '添加客户', exact: true }).click()
    await page.getByLabel('客户驭舟编号', { exact: true }).fill('12')
    await page.getByLabel('客户称呼', { exact: true }).fill('新增本地客户')
    await page.getByRole('button', { name: '保存客户', exact: true }).click()
    await page.getByText('新增本地客户', { exact: true }).waitFor()
    await page.getByRole('button', { name: '跟进 / 标签', exact: true }).first().click()
    await page.getByLabel('跟进阶段', { exact: true }).selectOption('quoted')
    await page.getByLabel('需求与跟进备注', { exact: true }).fill('桌面已确认需求')
    await page.getByRole('button', { name: '保存跟进', exact: true }).click()
    await page.getByText('桌面已确认需求', { exact: true }).waitFor()
    await page.getByRole('button', { name: '跟进 / 标签', exact: true }).first().click()
    await page.getByRole('checkbox', { name: '购船意向', exact: true }).check()
    await page.getByRole('button', { name: '保存客户标签', exact: true }).click()
    await page.getByText('客户标签已保存', { exact: true }).waitFor()

    await open('/sales/quotes?customer_id=11')
    await page.getByLabel('负责客户', { exact: true }).selectOption('11')
    await page.getByLabel('船艇或服务', { exact: true }).selectOption('51')
    await page.getByRole('checkbox', { name: /甲板选配/ }).check()
    await page.getByRole('button', { name: '创建代客订单', exact: true }).click()
    await page.getByRole('heading', { name: 'LOCAL-BROKER-101', exact: true }).waitFor()
    await page.getByLabel('报价金额（元）', { exact: true }).fill('1234.56')
    await page.getByRole('button', { name: '保存报价', exact: true }).click()
    await page.getByText('报价已保存，请客户确认当前报价', { exact: true }).waitFor()
    await page.locator('input[type=file]').setInputFiles({ name: 'local-contract.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4\nlocal fixture only') })
    await page.getByRole('button', { name: '上传新版本', exact: true }).click()
    await page.getByText('合同新版本已上传，等待平台审核', { exact: true }).waitFor()
    Object.assign(orders[0], { status: 'paid', paid_cents: orders[0].amount_cents })
    await page.getByRole('button', { name: '刷新', exact: true }).click()
    await page.getByLabel('完成百分比', { exact: true }).fill('25')
    await page.getByRole('button', { name: '更新进度', exact: true }).click()
    await page.getByRole('button', { name: '开始履约', exact: true }).click()
    await page.getByRole('button', { name: '完成履约', exact: true }).waitFor()
    await page.getByRole('button', { name: '完成履约', exact: true }).click()
    await page.getByRole('heading', { name: '履约操作', exact: true }).waitFor({ state: 'detached' })

    await open('/sales/appointments')
    await page.getByLabel('负责客户', { exact: true }).selectOption('11')
    await page.getByLabel('船艇型号', { exact: true }).selectOption('61')
    await page.getByLabel('带看时间', { exact: true }).fill('2099-01-01T10:00')
    await page.getByRole('button', { name: '创建带看预约', exact: true }).click()
    await page.getByRole('button', { name: '确认预约', exact: true }).click()
    await page.getByRole('button', { name: '完成接待', exact: true }).click()
    await page.getByRole('button', { name: '完成接待', exact: true }).waitFor({ state: 'detached' })

    await open('/sales/ledger')
    await page.getByLabel('提现金额（元）', { exact: true }).fill('10.01')
    await page.getByRole('button', { name: '提交申请', exact: true }).click()
    await page.getByText('本地合成网络故障，请重试', { exact: true }).waitFor()
    await page.getByRole('button', { name: '提交申请', exact: true }).click()
    await page.getByText('提现申请已提交，实际打款结果以平台处理为准', { exact: true }).waitFor()
    const attempts = mutations.filter(row => row.path === '/withdrawals')
    assert.equal(attempts.length, 2)
    assert.equal(attempts[0].body.amount_cents, 1001)
    assert.equal(attempts[0].body.idempotency_key, attempts[1].body.idempotency_key)

    await open('/sales/messages?conversation_id=41')
    await page.getByText('咨询船艇配置', { exact: true }).last().waitFor()
    await page.getByLabel('咨询内容', { exact: true }).fill('桌面销售回复')
    await page.getByRole('button', { name: '发送消息', exact: true }).click()
    await page.getByText('桌面销售回复', { exact: true }).last().waitFor()
    await page.getByLabel('咨询内容', { exact: true }).fill('未发送的草稿')
    await page.getByRole('button', { name: '刷新消息', exact: true }).click()
    assert.equal(await page.getByLabel('咨询内容', { exact: true }).inputValue(), '未发送的草稿')
    await page.screenshot({ path: path.join(output, 'broker-messages.png'), fullPage: true })
    await open('/sales')
    await page.getByRole('heading', { name: '最近销售订单', exact: true }).waitFor()
    await page.screenshot({ path: path.join(output, 'broker-overview.png'), fullPage: true })
    const created = mutations.find(row => row.path === '/orders')
    assert.equal(created.body.customer_id, 11)
    assert.equal(created.body.quantity, 1)
    assert.deepEqual(created.body.option_ids, ['deck'])
    assert.equal(mutations.find(row => row.path === '/orders/101/quote').body.amount_cents, 123456)
    assert.equal(mutations.find(row => row.path === '/orders/101/contract').body.file_id, 99)
    assert.equal(mutations.find(row => row.path === '/orders/101/progress').body.percent, 25)
    assert.equal(orders[0].status, 'completed')
    assert.equal(appointments[0].status, 'completed')
    assert.deepEqual(errors, [])
    assert.deepEqual(unexpected, [])
    fs.writeFileSync(path.join(output, 'receipt.json'), JSON.stringify({ status: 'passed', fixture_only: true, mutations, browser_errors: errors, unexpected_requests: unexpected }, null, 2))
    console.log('PASS desktop broker fixture: customers, tags, quote, contract, progress, fulfillment, appointments, withdrawal retry, messages and draft preservation')
  } catch (error) {
    await page.screenshot({ path: path.join(output, 'broker-failed.png'), fullPage: true }).catch(() => {})
    fs.writeFileSync(path.join(output, 'failure.json'), JSON.stringify({ error: String(error), browser_errors: errors, unexpected_requests: unexpected, labels: await page.locator('label').allTextContents() }, null, 2))
    throw error
  } finally { await browser.close() }
}
main().catch(error => { console.error(error); process.exitCode = 1 })
