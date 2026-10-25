import test from 'node:test'
import assert from 'node:assert/strict'
import { brokerOrderInput, progressInput, scheduledAt, withdrawalAttempt, allPages, customerStages } from '../src/site/broker-domain.js'

const base = { type: 'boat_purchase', customer_id: '12', resource_id: '34', quantity: '2', option_ids: ['deck'], notes: '  交付说明  ', start_date: '', end_date: '', scheduled_at: '', service_location: '' }
test('broker input preserves option keys and customer identity; specific boat quantity is one', () => {
  assert.deepEqual(brokerOrderInput(base), { type: 'boat_purchase', customer_id: 12, resource_id: 34, quantity: 1, option_ids: ['deck'], notes: '交付说明' })
  assert.throws(() => brokerOrderInput({ ...base, customer_id: '12.5' }))
  assert.throws(() => brokerOrderInput({ ...base, type: 'mall' }))
  assert.ok(customerStages.quoted)
  assert.equal(customerStages.quoting, undefined)
})
test('rental rejects calendar rollover and invalid duration', () => {
  const rental = { ...base, type: 'boat_rental', start_date: '2099-02-01', end_date: '2099-02-03' }
  assert.equal(brokerOrderInput(rental).quantity, 2)
  for (const end_date of ['2099-02-01', '2099-02-30', '2099-06-01']) assert.throws(() => brokerOrderInput({ ...rental, end_date }))
})
test('service appointments require an actual future time and location', () => {
  assert.equal(scheduledAt('2099-03-01T10:30'), '2099-03-01 10:30:00')
  for (const value of ['2099-02-30T10:00', '2099-03-01T24:00', '2020-01-01T10:00']) assert.throws(() => scheduledAt(value))
  assert.throws(() => brokerOrderInput({ ...base, type: 'maintenance', scheduled_at: '2099-03-01T10:00', service_location: ' ' }))
})
test('progress cannot reverse and withdrawal retry retains its idempotency key', () => {
  assert.deepEqual(progressInput('testing', '60', '  调试  ', 50), { stage: 'testing', percent: 60, notes: '调试' })
  for (const percent of [49, 100.5, 101]) assert.throws(() => progressInput('testing', percent, '', 50))
  const old = { amount_cents: 1200, idempotency_key: 'same-request-123' }
  assert.deepEqual(withdrawalAttempt(JSON.stringify(old), 1200, 'new-request-123'), old)
  assert.equal(withdrawalAttempt(JSON.stringify(old), 1300, 'new-request-123').idempotency_key, 'new-request-123')
  assert.equal(withdrawalAttempt('{bad', 1200, 'new-request-123').idempotency_key, 'new-request-123')
})
test('pickers consume every page and reject stalled pagination', async () => {
  assert.deepEqual(await allPages(async page => ({ items: [{ id: page }], total: 2, page, page_size: 1 })), [{ id: 1 }, { id: 2 }])
  await assert.rejects(allPages(async page => ({ items: [{ id: 1 }], total: 3, page, page_size: 1 })))
  await assert.rejects(allPages(async () => ({ items: [], total: 5, page: 1, page_size: 0 })))
})
