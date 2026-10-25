export function sessionKey(role) {
  if (!['consumer', 'broker'].includes(role)) throw new Error('登录入口无效')
  return `yuzhou_desktop_${role}_token`
}

export function checkIdentity(role, user) {
  sessionKey(role)
  if (!user || user.role !== role || !Number.isSafeInteger(Number(user.id)) || Number(user.id) < 1)
    throw new Error(role === 'broker' ? '请使用销售经纪人账号登录' : '请使用用户账号登录')
  return user
}

export function privateFilePath(path) {
  if (typeof path !== 'string' || !/^\/api\/v1\/files\/[1-9]\d*$/.test(path)) throw new Error('附件地址无效')
  return path
}

export function safeWebsiteReturn(value, fallback = '/account') {
  return typeof value === 'string' && value.startsWith('/') && !value.startsWith('//') && !/[\\\r\n]/.test(value) ? value : fallback
}

export function responseMessage(value, status) {
  if (/Invalid (?:username|account) or password|Invalid credentials/i.test(value || '')) return '账号或密码错误，请检查后重试'
  if (status === 401) return '登录已失效，请重新登录'
  if (/Real payment.*not configured|Payment.*not configured/i.test(value || '')) return '此支付通道暂未开通，请联系平台'
  if (/Real SMS service is not configured/i.test(value || '')) return '短信服务暂未开通，请使用账号密码登录'
  if (/File access is not permitted/i.test(value || '')) return '当前账号无权访问此附件'
  return value || `请求失败（${status}）`
}
