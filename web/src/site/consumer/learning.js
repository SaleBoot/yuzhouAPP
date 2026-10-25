export function lessonPosition(lesson, position) {
  const duration = Number(lesson?.duration_seconds)
  if (!lesson?.id || !Number.isInteger(duration) || duration < 1 || !Number.isFinite(position) || position < 0)
    throw new Error('课时或播放进度无效，请刷新后重试')
  return { lesson_id: String(lesson.id), position_seconds: Math.min(duration, Math.floor(position)) }
}

export function examAnswers(questions, answers) {
  if (!questions.length || answers.length !== questions.length || !questions.every((question, index) =>
    Number.isInteger(answers[index]) && answers[index] >= 0 && answers[index] < question.options.length))
    throw new Error('请完成所有题目后再提交')
  return [...answers]
}

export function activityEligibility(user, now = Math.floor(Date.now() / 1000)) {
  const verified = user?.identity_status === 'verified'
  const member = Number(user?.member_until) > now
  return { verified, member, eligible: Boolean(user?.role === 'consumer' && user?.status === 'active' && verified && member) }
}

export function lessonMedia(value) {
  if (typeof value !== 'string') return ''
  if (/^\/api\/v1\/files\/[1-9]\d*$/.test(value)) return value
  try {
    const url = new URL(value)
    return ['https:', 'http:'].includes(url.protocol) && !url.username && !url.password ? url.href : ''
  } catch { return '' }
}
