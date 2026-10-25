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
