import test from 'node:test'
import assert from 'node:assert/strict'
import { activityEligibility, examAnswers, lessonMedia, lessonPosition } from '../src/site/consumer/learning.js'

test('desktop learning submits observed seconds within the configured lesson duration', () => {
  assert.deepEqual(lessonPosition({ id: 'deck-1', duration_seconds: 60 }, 34.8), { lesson_id: 'deck-1', position_seconds: 34 })
  assert.deepEqual(lessonPosition({ id: 'deck-1', duration_seconds: 60 }, 90), { lesson_id: 'deck-1', position_seconds: 60 })
  for (const position of [-1, NaN, Infinity]) assert.throws(() => lessonPosition({ id: 1, duration_seconds: 60 }, position))
  assert.throws(() => lessonPosition({ id: 1, duration_seconds: 0 }, 3))
})

test('desktop exam rejects sparse, incomplete and out of range answers', () => {
  const questions = [{ options: ['A', 'B'] }, { options: ['A', 'B', 'C'] }]
  assert.deepEqual(examAnswers(questions, [0, 2]), [0, 2])
  for (const answers of [[0], [, 1], [0, 3], [0, '1']]) assert.throws(() => examAnswers(questions, answers))
})

test('activity eligibility follows actual account, review and member expiry values', () => {
  const user = { role: 'consumer', status: 'active', identity_status: 'verified', member_until: 101 }
  assert.equal(activityEligibility(user, 100).eligible, true)
  assert.equal(activityEligibility(user, 101).eligible, false)
  for (const replacement of [{ role: 'broker' }, { status: 'suspended' }, { identity_status: 'pending' }])
    assert.equal(activityEligibility({ ...user, ...replacement }, 100).eligible, false)
  assert.equal(activityEligibility(null, 100).eligible, false)
})

test('lesson media never turns script URLs or credentials into a video request', () => {
  assert.equal(lessonMedia('/api/v1/files/13'), '/api/v1/files/13')
  assert.equal(lessonMedia('https://media.example.test/course.mp4'), 'https://media.example.test/course.mp4')
  for (const value of ['javascript:alert(1)', '//example.test/video', 'https://user:pass@example.test/a', '/private/file', null])
    assert.equal(lessonMedia(value), '')
})
