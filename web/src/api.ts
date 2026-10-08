import { reactive } from 'vue'
import { safeAttachmentPath } from './domain.js'

export type Role = 'platform' | 'merchant' | 'agency'
export interface User { id: number; role: string; name?: string; nickname?: string; username?: string; org_id?: number; organization_name?: string; permissions?: string[] }
export interface Page<T = Record<string, any>> { items: T[]; total: number; page: number; page_size: number }

const base = (import.meta.env.VITE_API_BASE_URL || '/api/v1').replace(/\/$/, '')
export const session = reactive<{ token: string; user: User | null; role: Role | null }>({ token: '', user: null, role: null })
export const environment = reactive({ name: '', isTest: false, testPayments: false })
export function can(permission:string){return !session.user?.permissions||session.user.permissions.includes(permission)}
export async function loadEnvironment() {
  const data=await request<any>('/bootstrap')
  environment.name=String(data.environment||'')
  environment.isTest=data.is_test_environment===true
  environment.testPayments=environment.isTest&&data.test_payments_enabled===true
}

export function restoreSession(role: Role) {
  session.role = role
  session.token = sessionStorage.getItem(`yuzhou_${role}_token`) || ''
  session.user = null
}

export function clearSession() {
  if (session.role) sessionStorage.removeItem(`yuzhou_${session.role}_token`)
  session.token = ''
  session.user = null
}

export async function request<T = any>(path: string, options: RequestInit = {}): Promise<T> {
  const headers = new Headers(options.headers)
  if (session.token) headers.set('Authorization', `Bearer ${session.token}`)
  if (options.body && !(options.body instanceof FormData)) headers.set('Content-Type', 'application/json')
  let response: Response
  try { response = await fetch(`${base}${path}`, { ...options, headers, credentials: 'omit' }) }
  catch { throw new Error('无法连接服务，请检查网络或稍后重试') }
  let envelope: any
  try { envelope = await response.json() }
  catch { throw new Error(`服务响应无效（HTTP ${response.status}）`) }
  if (!response.ok || envelope.code !== 0) {
    if (response.status === 401) clearSession()
    throw new Error(envelope.message || `请求失败（HTTP ${response.status}）`)
  }
  return envelope.data as T
}

export async function login(role: Role, username: string, password: string) {
  const data = await request<{ token: string; user: User }>('/auth/login', { method: 'POST', body: JSON.stringify({ username, password }) })
  if (!data.token || data.user.role !== role) throw new Error('此账号不属于当前管理入口')
  session.role = role
  session.token = data.token
  session.user = data.user
  sessionStorage.setItem(`yuzhou_${role}_token`, data.token)
}

export async function loadIdentity() {
  const data = await request<User | { user: User }>('/auth/me')
  const user = 'user' in data ? data.user : data
  if (user.role !== session.role) { clearSession(); throw new Error('账号权限与入口不匹配，请重新登录') }
  session.user = user
}

export function query(values: Record<string, unknown>) {
  const result = new URLSearchParams()
  for (const [key, value] of Object.entries(values)) if (value !== '' && value !== null && value !== undefined) result.set(key, String(value))
  return result.size ? `?${result}` : ''
}

export async function attachmentBlob(path: string) {
  safeAttachmentPath(path)
  const origin = new URL(base, location.origin).origin
  const response = await fetch(`${origin}${path}`, { headers: { Authorization: `Bearer ${session.token}` }, credentials: 'omit' })
  if (!response.ok) throw new Error('附件无权限、已失效或下载失败')
  return response.blob()
}

export async function downloadAttachment(path: string, filename: string) {
  const url = URL.createObjectURL(await attachmentBlob(path))
  const link = document.createElement('a')
  link.href = url; link.download = filename || '合同附件'; link.click()
  setTimeout(() => URL.revokeObjectURL(url), 1000)
}

export async function downloadDividendReport(filters:Record<string,string>={}) {
  const response=await fetch(`${base}/reports/dividends.xlsx${query(filters)}`,{headers:{Authorization:`Bearer ${session.token}`},credentials:'omit'})
  if(!response.ok){const result=await response.json().catch(()=>({}));throw new Error(result.message||'报表导出失败')}
  const blob=await response.blob()
  if(!blob.type.includes('spreadsheetml'))throw new Error('服务端未返回Excel报表，请联系平台核查')
  const url=URL.createObjectURL(blob),link=document.createElement('a')
  link.href=url;link.download=`驭舟分红报表-${new Date().toISOString().slice(0,10)}.xlsx`;link.click();setTimeout(()=>URL.revokeObjectURL(url),1000)
}
