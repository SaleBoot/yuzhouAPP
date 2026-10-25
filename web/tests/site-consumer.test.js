import test from 'node:test'
import assert from 'node:assert/strict'
import { checkoutPayload, orderCapabilities, positiveId, rentalDates } from '../src/site/consumer/domain.js'

test('desktop checkout submits resource IDs and exact quantities without invented amounts', () => {
  const body = checkoutPayload({ type: 'boat_rental', resource_id: '21', quantity: '2', start_date: '2026-12-01', end_date: '2026-12-03', coupon_id: '4', notes: '  码头  ' })
  assert.deepEqual(body, { type: 'boat_rental', resource_id: 21, quantity: 2, start_date: '2026-12-01', end_date: '2026-12-03', coupon_id: 4, notes: '码头' })
  assert.equal('amount_cents' in body, false)
  for (const quantity of [0, 1.5, 51]) assert.throws(() => checkoutPayload({ type: 'mall', resource_id: 1, quantity }))
  assert.throws(() => checkoutPayload({ type: 'courses', resource_id: 1, quantity: 1 }))
  assert.throws(() => checkoutPayload({ type: 'boat_purchase', resource_id: 1, quantity: 2 }))
  for (const id of ['0', '../1', '9007199254740992']) assert.throws(() => positiveId(id))
  assert.throws(() => rentalDates('2026-02-30', '2026-03-03'))
  assert.throws(() => rentalDates('2026-12-03', '2026-12-03'))
})

test('contract and payment controls follow the current server version rather than the newest array element', () => {
  const order = { type: 'boat_purchase', status: 'awaiting_payment', contract_version: 2, paid_cents: 0, refund_cents: 0 }
  const contracts = [{ version: 1, review_status: 'approved', signed_at: 1 }, { version: 2, review_status: 'pending', signed_at: null }]
  assert.equal(orderCapabilities(order, contracts).pay, false)
  assert.equal(orderCapabilities(order, contracts).sign, false)
  contracts[1].review_status = 'approved'
  assert.equal(orderCapabilities(order, contracts).sign, true)
  contracts[1].signed_at = 123
  assert.equal(orderCapabilities(order, contracts).pay, true)
  assert.equal(orderCapabilities({ ...order, contract_version: 3 }, contracts).pay, false)
  assert.equal(orderCapabilities({ ...order, status: 'cancelled' }, contracts).sign, false)
})

test('partial refunds keep the remaining cents and fulfillment requirements', () => {
  const order = { status: 'partially_refunded', paid_cents: 10001, refund_cents: 2000, refund_requests: [{ status: 'pending' }] }
  const current = orderCapabilities(order, [])
  assert.equal(current.refundable, 8001)
  assert.equal(current.refundPending, true)
  assert.equal(current.receive, false)
  assert.equal(orderCapabilities({ ...order, events: [{ action: 'fulfilling' }] }, []).receive, true)
  assert.equal(orderCapabilities({ ...order, paid_cents: 2000 }, []).receive, false)
})
