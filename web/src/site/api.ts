import { reactive } from 'vue'
import { checkIdentity, privateFilePath, sessionKey } from './session-domain.js'

export type SiteRole = 'consumer' | 'broker'
export const siteSession = reactive<{ token: string; user: any; role: SiteRole; restoring: boolean }>({ token: '', user: null, role: 'consumer', restoring: false })
const base = (import.meta.env.VITE_API_BASE_URL || '/api/v1').replace(/\/$/, '')

function clear() {
  sessionStorage.removeItem(sessionKey(siteSession.role))
  siteSession.token = ''
  siteSession.user = null
}

function message(value: string, status: number) {
  if (status === 401) return '登录已失效，请重新登录'
  if (/Real payment.*not configured|Payment.*not configured/i.test(value)) return '此支付通道暂未开通，请联系平台'
  if (/Real SMS service is not configured/i.test(value)) return '短信服务暂未开通，请使用账号密码登录'
  if (/File access is not permitted/i.test(value)) return '当前账号无权访问此附件'
  return value || `请求失败（${status}）`
}

export async function request<T = any>(path: string, options: RequestInit = {}): Promise<T> {
  if (!path.startsWith('/') || path.startsWith('//')) throw new Error('请求地址无效')
  const token = siteSession.token, role = siteSession.role
  const headers = new Headers(options.headers)
  if (token) headers.set('Authorization', `Bearer ${token}`)
  if (options.body && !(options.body instanceof FormData)) headers.set('Content-Type', 'application/json')
  let response: Response
  try { response = await fetch(base + path, { ...options, headers, credentials: 'omit' }) }
  catch { throw new Error('无法连接服务，请检查网络后重试') }
  if (siteSession.token !== token || siteSession.role !== role) throw new Error('登录状态已切换，请重新打开当前页面')
  let result: any
  try { result = await response.json() }
  catch { throw new Error(`服务响应无效（${response.status}）`) }
  if (!response.ok || result.code !== 0) {
    if (response.status === 401) clear()
    throw new Error(message(result.message, response.status))
  }
  return result.data as T
}

function save(role: SiteRole, data: any) {
  checkIdentity(role, data.user)
  if (typeof data.token !== 'string' || !data.token) throw new Error('登录结果无效，请重试')
  siteSession.role = role
  siteSession.user = data.user
  siteSession.token = data.token
  sessionStorage.setItem(sessionKey(role), data.token)
}

export async function login(role: SiteRole, username: string, password: string) {
  if (role !== siteSession.role) throw new Error('登录入口已切换，请重试')
  const data = await request('/auth/login', { method: 'POST', body: JSON.stringify({ username, password }) })
  save(role, data)
}

export async function registerConsumer(body: { username: string; password: string; nickname?: string; invitation_code?: string }) {
  if (siteSession.role !== 'consumer') throw new Error('请从用户入口注册')
  const data = await request('/auth/register', { method: 'POST', body: JSON.stringify(body) })
  save('consumer', data)
}

export async function loadIdentity() {
  const data = await request('/auth/me')
  const user = data.user || data
  try { checkIdentity(siteSession.role, user) }
  catch (error) { clear(); throw error }
  siteSession.user = user
  return user
}

export async function activateRole(role: SiteRole) {
  siteSession.role = role
  siteSession.token = sessionStorage.getItem(sessionKey(role)) || ''
  siteSession.user = null
  siteSession.restoring = true
  try { if (siteSession.token) await loadIdentity() }
  finally { if (siteSession.role === role) siteSession.restoring = false }
}

export async function logout() {
  try { if (siteSession.token) await request('/auth/logout', { method: 'POST' }) }
  finally { clear() }
}

export function query(values: Record<string, unknown>) {
  const params = new URLSearchParams()
  for (const [key, value] of Object.entries(values)) if (value !== '' && value !== null && value !== undefined) params.set(key, String(value))
  return params.size ? '?' + params.toString() : ''
}

export async function attachmentBlob(path: string) {
  privateFilePath(path)
  const origin = new URL(base, location.origin).origin
  const token = siteSession.token
  const response = await fetch(origin + path, { headers: token ? { Authorization: `Bearer ${token}` } : {}, credentials: 'omit' })
  if (token !== siteSession.token) throw new Error('登录状态已切换，请重新下载')
  if (!response.ok) throw new Error('附件无权访问或已失效')
  return response.blob()
}

export async function listResources(kind: string) {
  const items = new Map<number, any>()
  for (let page = 1; ; page++) {
    const result = await request(`/resources/${encodeURIComponent(kind)}${query({ page, page_size: 100 })}`)
    if (!Array.isArray(result.items) || !Number.isSafeInteger(result.total) || result.total < 0 || result.page !== page || !Number.isSafeInteger(result.page_size) || result.page_size < 1) throw new Error('资料列表不完整，请重新加载')
    const before = items.size
    for (const item of result.items) items.set(Number(item.id), item)
    if (page * result.page_size >= result.total) return [...items.values()]
    if (items.size === before) throw new Error('资料分页异常，请重新加载')
  }
}
