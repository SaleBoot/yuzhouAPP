import { test } from 'node:test'
import assert from 'node:assert/strict'
import { imageUrl, minimumPurchasePrice, catalogFilter } from '../src/site/catalog.js'

test('desktop purchase cards use available actual-boat prices and never substitute rental prices', () => {
  const boats = [{ price_cents: 90000, payload: { boat_type_id: 1 } }, { price_cents: 10000, payload: { boat_type_id: 1, unavailable: 1 } }, { price_cents: 80000, payload: { boat_type_id: 2 } }]
  assert.equal(minimumPurchasePrice(1, boats), 90000)
  assert.equal(minimumPurchasePrice(3, boats), null)
})

test('desktop filters respect real fields and put unknown prices after quoted prices', () => {
  const items = [{ id: 1, title: 'A游艇', payload: { category: 'yacht', city: '广州', daily_price_cents: 10000 } }, { id: 2, title: 'B船', payload: { category: 'workboat', city: '珠海', daily_price_cents: 20000 } }]
  assert.deepEqual(catalogFilter(items, { category: 'yacht', city: '广州' }).map(x => x.id), [1])
  assert.deepEqual(catalogFilter(items, { mode: 'buy', sort: 'price_desc' }, { 2: 90000 }).map(x => x.id), [2, 1])
  assert.equal(imageUrl('javascript:alert(1)'), '')
  assert.equal(imageUrl('//outside.invalid/a.png'), '')
  assert.equal(imageUrl('/api/v1/files/2'), '/api/v1/files/2')
})
