import test from 'node:test'
import assert from 'node:assert/strict'
import { entryRole, centsFromInput, basisPointsFromInput, safeAttachmentPath, visibleKinds } from '../src/domain.js'

test('each management entrance has an explicit role; unknown path grants none', () => {
  assert.equal(entryRole('/agency/orders'), 'agency')
  assert.equal(entryRole('/platform'), 'platform')
  assert.equal(entryRole('/admin/platform/orders'), 'platform')
  assert.equal(entryRole('/'), null)
  assert.equal(entryRole('/broker'), null)
  assert.deepEqual(visibleKinds('agency'), ['users'])
  assert.ok(!visibleKinds('merchant').includes('organizations'))
})

test('money and commission percentages are exact integer minor units', () => {
  assert.equal(centsFromInput('0.29'), 29)
  assert.equal(centsFromInput('58000.01'), 5800001)
  assert.equal(basisPointsFromInput('12.50'), 1250)
  for (const invalid of ['-1', '2.999', '1e3', '', 'NaN']) assert.throws(() => centsFromInput(invalid))
  assert.throws(() => basisPointsFromInput('100.01'))
})

test('private attachments cannot forward bearer tokens to arbitrary origins', () => {
  assert.equal(safeAttachmentPath('/api/v1/files/123'), '/api/v1/files/123')
  for (const path of ['https://evil.test/api/v1/files/123', '//evil.test/a', '/uploads/a.pdf', '/api/v1/../secret']) assert.throws(() => safeAttachmentPath(path))
})
