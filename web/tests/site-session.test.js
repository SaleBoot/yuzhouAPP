import { test } from 'node:test'
import assert from 'node:assert/strict'
import { sessionKey, checkIdentity, privateFilePath, safeWebsiteReturn, responseMessage } from '../src/site/session-domain.js'

test('PC sessions separate consumers and actual brokers from management and native clients', () => {
  assert.notEqual(sessionKey('consumer'), sessionKey('broker'))
  assert.equal(checkIdentity('broker', { id: 2, role: 'broker' }).id, 2)
  assert.throws(() => checkIdentity('broker', { id: 2, role: 'agency' }))
  assert.throws(() => checkIdentity('consumer', { id: 2, role: 'platform' }))
  assert.throws(() => sessionKey('platform'))
  assert.throws(() => checkIdentity('consumer', { id: 0, role: 'consumer' }))
})

test('private attachment and login return paths stay inside this website', () => {
  assert.equal(privateFilePath('/api/v1/files/12'), '/api/v1/files/12')
  for (const value of ['https://other.invalid/12', '//other.invalid/', '/api/v1/files/0', '/api/v1/files/12?token=abc', '/api/v1/files/../settings']) assert.throws(() => privateFilePath(value))
  assert.equal(safeWebsiteReturn('/orders/12'), '/orders/12')
  for (const value of ['//other.invalid', 'https://other.invalid', '/\\other.invalid', '/\nother']) assert.equal(safeWebsiteReturn(value), '/account')
})

test('bad credentials, expired sessions and unavailable payment preserve their actual meaning', () => {
  assert.equal(responseMessage('Invalid username or password.', 401), '账号或密码错误，请检查后重试')
  assert.equal(responseMessage('Session has expired. Please sign in again.', 401), '登录已失效，请重新登录')
  assert.equal(responseMessage('Real payment service is not configured.', 503), '此支付通道暂未开通，请联系平台')
  assert.equal(responseMessage('Reservation expired.', 409), 'Reservation expired.')
})
