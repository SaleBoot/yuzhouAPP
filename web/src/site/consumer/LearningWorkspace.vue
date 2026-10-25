<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { request, query, siteSession } from '../api'
import { dateTime, money } from '../../domain.js'
import { activityEligibility, examAnswers, lessonMedia, lessonPosition } from './learning.js'

interface Lesson { id: string | number; title: string; video_url: string; duration_seconds: number }
interface Question { title?: string; question?: string; options: (string | { text: string })[] }
interface Reward { id: number | string; title?: string; type?: string }
interface Resource {
  id: number; title: string; price_cents: number
  payload: { description?: string; content?: string; lessons?: Lesson[]; questions?: Question[]; rewards?: Reward[]; reward_description?: string; starts_at?: string; location?: string; capacity?: number }
}
interface Progress { lesson_id: string; course_id: number; completed: number | string; position_seconds: number }
interface RewardClaim { id: number; status: string; notes?: string; created_at: number; reward_snapshot?: { course_id?: number; title?: string; reward_type?: string; points?: number; event_id?: number } }
interface Enrollment { id: number; event_id: number; order_id?: number | null; ticket_code: string; status: string }
interface User { role: string; status: string; identity_status: string; member_until: number }
interface Page<T> { items: T[]; total: number; page: number; page_size: number }
interface ExamResult { score: number; passed: number | string; message?: string }

const props = defineProps<{ kind: string; id: string }>()
const router = useRouter()
const resource = ref<Resource | null>(null), loading = ref(false), error = ref(''), notice = ref(''), busy = ref(false)
const lessonIndex = ref(0), answers = ref<number[]>([]), progress = ref<Progress[]>([]), rewards = ref<RewardClaim[]>([])
const result = ref<ExamResult | null>(null), enrollment = ref<Enrollment | null>(null), user = ref<User | null>(null)
const cancelOpen = ref(false), cancelReason = ref('')
const lessons = computed(() => resource.value?.payload.lessons || [])
const questions = computed(() => resource.value?.payload.questions || [])
const currentLesson = computed(() => lessons.value[lessonIndex.value])
const currentMedia = computed(() => lessonMedia(currentLesson.value?.video_url))
const isConsumer = computed(() => Boolean(siteSession.token) && siteSession.role === 'consumer')
const eligibility = computed(() => activityEligibility(user.value))
const enrollmentNames: Record<string, string> = { pending: '待支付', active: '已报名', checked_in: '已签到', cancelled: '已取消' }
const identityNames: Record<string, string> = { unverified: '尚未提交', pending: '平台审核中', verified: '平台资料审核通过', rejected: '审核未通过' }
const rewardNames: Record<string, string> = { earned: '已获得', issued: '已发放', already_claimed: '已在我的优惠券', unavailable: '暂不可发放', pending_fulfillment: '待平台履约', fulfilled: '平台已履约', eligible: '课程条件已满足' }
let version = 0
let progressRequest: Promise<void> | null = null
let lastPosition: Record<string, number> = {}
let nextRetry = 0
let loadedContext = ''

function context() { return `${props.kind}:${props.id}:${siteSession.role}` }
function active(epoch: number, token: string) { return epoch === version && token === siteSession.token && loadedContext === context() }
function message(cause: unknown) { return cause instanceof Error ? cause.message : '请求未完成，请重试' }
function post<T>(path: string, body: object = {}) { return request<T>(path, { method: 'POST', body: JSON.stringify(body) }) }
async function all<T>(path: string, epoch: number, token: string): Promise<T[]> {
  const items: T[] = []
  for (let page = 1; ; page++) {
    const response = await request<Page<T>>(`${path}${query({ page, page_size: 100 })}`)
    if (!active(epoch, token)) return []
    if (!Array.isArray(response.items) || response.page !== page || response.page_size !== 100 || !Number.isInteger(response.total) || response.total < 0 || response.items.length === 0 && items.length < response.total)
      throw new Error('记录分页不完整，请重试')
    items.push(...response.items)
    if (items.length >= Number(response.total)) return items
  }
}
async function load() {
  const epoch = ++version, token = siteSession.token
  loadedContext = context()
  resource.value = null; progress.value = []; rewards.value = []; enrollment.value = null; user.value = null
  result.value = null; answers.value = []; lessonIndex.value = 0; cancelOpen.value = false; cancelReason.value = ''
  busy.value = false; error.value = ''; notice.value = ''; lastPosition = {}; nextRetry = 0; progressRequest = null
  loading.value = true
  try {
    if (!['courses', 'events'].includes(props.kind) || !/^[1-9]\d*$/.test(props.id)) throw new Error('课程或活动地址无效')
    const record = await request<Resource>(`/resources/${props.kind}/${props.id}`)
    if (!active(epoch, token)) return
    resource.value = record
    if (token && siteSession.role === 'consumer') {
      if (props.kind === 'courses') {
        const [records, claims] = await Promise.all([all<Progress>('/learning/progress', epoch, token), all<RewardClaim>('/learning/rewards', epoch, token)])
        if (!active(epoch, token)) return
        progress.value = records.filter(item => Number(item.course_id) === record.id)
        rewards.value = claims.filter(item => Number(item.reward_snapshot?.course_id) === record.id)
      } else {
        const [me, entries] = await Promise.all([request<User>('/auth/me'), all<Enrollment>('/enrollments', epoch, token)])
        if (!active(epoch, token)) return
        user.value = me
        enrollment.value = entries.find((item: Enrollment) => Number(item.event_id) === record.id) || null
      }
    }
  } catch (cause) { if (active(epoch, token)) error.value = message(cause) }
  finally { if (active(epoch, token)) loading.value = false }
}
function completed(id: string | number) { return progress.value.some(item => String(item.lesson_id) === String(id) && Number(item.completed) === 1) }
async function refreshRewards(epoch: number, token: string) {
  const entries = await all<RewardClaim>('/learning/rewards', epoch, token)
  if (active(epoch, token)) rewards.value = entries.filter(item => Number(item.reward_snapshot?.course_id) === resource.value?.id)
}
async function recordProgress(event: Event, ended = false) {
  if (!isConsumer.value || !resource.value) return
  const video = event.currentTarget as HTMLVideoElement
  const lesson = lessons.value.find(item => String(item.id) === video.dataset.lessonId)
  if (!lesson || !Number.isFinite(video.currentTime)) return
  const position = video.currentTime, epoch = version, token = siteSession.token, courseId = resource.value.id
  if (!ended && (progressRequest || position - (lastPosition[String(lesson.id)] || 0) < 30 || Date.now() < nextRetry)) return
  if (progressRequest) {
    await progressRequest
    if (!active(epoch, token)) return
  }
  const pending = (async () => {
    try {
      const saved = await post<Progress>('/learning/progress', { course_id: courseId, ...lessonPosition(lesson, position), completed: ended })
      if (!active(epoch, token)) return
      progress.value = [...progress.value.filter(item => String(item.lesson_id) !== String(saved.lesson_id)), saved]
      lastPosition[String(lesson.id)] = Math.floor(position)
      if (error.value.startsWith('学习进度未保存：')) error.value = ''
      if (Number(saved.completed) === 1) await refreshRewards(epoch, token)
    } catch (cause) {
      if (active(epoch, token)) { error.value = `学习进度未保存：${message(cause)}`; nextRetry = Date.now() + 30000 }
    }
  })()
  progressRequest = pending
  await pending
  if (progressRequest === pending) progressRequest = null
}
async function submitExam() {
  if (!isConsumer.value || !resource.value || busy.value) return
  const epoch = version, token = siteSession.token
  busy.value = true; error.value = ''; notice.value = ''
  try {
    const values = examAnswers(questions.value, answers.value)
    const response = await post<ExamResult>('/learning/exams', { course_id: resource.value.id, answers: values })
    if (!active(epoch, token)) return
    result.value = response
    await refreshRewards(epoch, token)
  } catch (cause) { if (active(epoch, token)) error.value = message(cause) }
  finally { if (active(epoch, token)) busy.value = false }
}
async function enroll() {
  if (!isConsumer.value || !resource.value || busy.value) return
  const epoch = version, token = siteSession.token
  busy.value = true; error.value = ''; notice.value = ''
  try {
    const me = await request<User>('/auth/me')
    if (!active(epoch, token)) return
    user.value = me
    if (!activityEligibility(me).eligible) throw new Error('活动报名需平台资料审核通过、账号启用且会员有效，请先在个人中心核对')
    const response = await post<{ order?: { id: number }; enrollment?: Enrollment }>(`/events/${resource.value.id}/enroll`)
    if (!active(epoch, token)) return
    if (response.order?.id) await router.push(`/orders/${response.order.id}`)
    else if (response.enrollment) { enrollment.value = response.enrollment; notice.value = '报名结果已更新' }
    else throw new Error('报名结果不完整，请刷新核对后再操作')
  } catch (cause) { if (active(epoch, token)) error.value = message(cause) }
  finally { if (active(epoch, token)) busy.value = false }
}
async function cancelEnrollment() {
  if (!isConsumer.value || !enrollment.value || busy.value) return
  const epoch = version, token = siteSession.token
  busy.value = true; error.value = ''; notice.value = ''
  try {
    if (!cancelReason.value.trim()) throw new Error('请填写取消原因')
    const response = await post<Enrollment>(`/enrollments/${enrollment.value.id}/cancel`, { reason: cancelReason.value.trim(), ticket_code: enrollment.value.ticket_code })
    if (!active(epoch, token)) return
    enrollment.value = response; cancelOpen.value = false; notice.value = '报名结果已更新'
  } catch (cause) { if (active(epoch, token)) error.value = message(cause) }
  finally { if (active(epoch, token)) busy.value = false }
}
watch(() => [props.kind, props.id, siteSession.token, siteSession.role], load, { immediate: true })
onBeforeUnmount(() => { version++ })
</script>

<template>
  <section class="learning-workspace">
    <header class="learning-heading"><div><span class="eyebrow">{{ kind === 'courses' ? '航海课堂' : '航海活动' }}</span><h1>{{ resource?.title || (kind === 'courses' ? '课程学习' : '活动报名') }}</h1></div><button type="button" class="plain-button" :disabled="loading || busy" @click="load">刷新记录</button></header>
    <p v-if="error" class="alert error" role="alert">{{ error }}</p>
    <p v-if="notice" class="alert success" role="status">{{ notice }}</p>
    <p v-if="loading" class="empty-state" aria-live="polite">正在加载…</p>
    <template v-if="resource && !loading">
      <p v-if="!isConsumer" class="alert">登录用户账号后可保存学习记录或参加活动。<RouterLink to="/account">前往个人中心</RouterLink></p>
      <template v-if="kind === 'courses'">
        <div class="course-layout">
          <div class="lesson-player panel"><video v-if="currentMedia && currentLesson" :key="String(currentLesson.id)" :data-lesson-id="currentLesson.id" :src="currentMedia" :aria-label="currentLesson.title" controls playsinline preload="metadata" @timeupdate="recordProgress($event)" @ended="recordProgress($event, true)" @error="error = '视频暂时无法播放，请稍后重试或联系平台'">当前浏览器不支持视频播放。</video><div v-else class="video-empty">{{ currentLesson ? '该课时视频尚未配置' : '课程内容待完善' }}</div><div class="player-caption"><h2>{{ currentLesson?.title || '请选择课时' }}</h2><p>{{ resource.payload.description || resource.payload.content || '选择右侧课时，开始学习。' }}</p><small>播放进度每 30 秒及播放结束时保存，完成状态以服务端记录为准。</small></div></div>
          <aside class="panel lesson-list"><h2>课程目录 <span>{{ lessons.length }} 课时</span></h2><button v-for="(lesson, index) in lessons" :key="lesson.id" type="button" :class="['lesson-choice', { selected: lessonIndex === index }]" @click="lessonIndex = index"><span class="lesson-mark">{{ completed(lesson.id) ? '✓' : String(index + 1).padStart(2, '0') }}</span><span>{{ lesson.title }}<small>{{ Math.ceil(lesson.duration_seconds / 60) }} 分钟 · {{ completed(lesson.id) ? '已完成' : '待学习' }}</small></span></button><p v-if="!lessons.length" class="muted">暂无课时。</p></aside>
        </div>
        <div class="course-lower-grid"><section class="panel exam-panel"><h2>课程考试</h2><form v-if="questions.length" @submit.prevent="submitExam"><fieldset v-for="(question, index) in questions" :key="index" :disabled="busy || !isConsumer"><legend>{{ index + 1 }}. {{ question.title || question.question }}</legend><label v-for="(option, optionIndex) in question.options" :key="optionIndex" class="answer-choice"><input v-model="answers[index]" type="radio" :name="`question-${index}`" :value="optionIndex">{{ typeof option === 'string' ? option : option.text }}</label></fieldset><button type="submit" class="primary-button" :disabled="busy || !isConsumer">{{ busy ? '提交中…' : '提交考试' }}</button></form><p v-else class="muted">暂未配置课程考试。</p><p v-if="result" class="alert" role="status">{{ Number(result.passed) === 1 ? '考试通过' : '尚未通过' }} · 得分 {{ result.score }}<br>{{ result.message || '奖励以平台实际发放记录为准。' }}</p></section>
          <aside class="panel"><h2>学习奖励</h2><p class="muted">{{ resource.payload.reward_description || '成长值和课程奖励按平台配置及实际学习、考试结果发放。' }}</p><p v-for="reward in resource.payload.rewards || []" :key="reward.id" class="configured-reward">{{ reward.title }}<small>完成课程要求后核发</small></p><h3>我的奖励记录</h3><article v-for="reward in rewards" :key="reward.id" class="reward-record"><strong>{{ reward.reward_snapshot?.title || '课程奖励' }}</strong><span>{{ rewardNames[reward.status] || reward.status }}</span><p v-if="reward.reward_snapshot?.reward_type === 'growth'">{{ reward.reward_snapshot.points }} 成长值</p><small>{{ dateTime(reward.created_at) }}</small><p v-if="reward.notes">{{ reward.notes }}</p><RouterLink v-if="reward.reward_snapshot?.event_id && reward.status === 'eligible'" :to="{ path: '/checkout', query: { kind: 'events', id: reward.reward_snapshot.event_id } }">查看对应活动</RouterLink></article><p v-if="!rewards.length" class="muted">{{ isConsumer ? '暂无已获得的课程奖励。' : '登录后查看实际奖励记录。' }}</p></aside></div>
      </template>
      <div v-else class="event-layout"><article class="panel event-description"><h2>活动信息</h2><dl><div><dt>活动时间</dt><dd>{{ resource.payload.starts_at ? dateTime(resource.payload.starts_at) : '待公布' }}</dd></div><div><dt>活动地点</dt><dd>{{ resource.payload.location || '待公布' }}</dd></div><div><dt>活动名额</dt><dd>{{ resource.payload.capacity || '以报名结果为准' }}</dd></div><div><dt>报名费用</dt><dd class="price">{{ resource.price_cents ? money(resource.price_cents) : '免费报名' }}</dd></div></dl><p class="article-text">{{ resource.payload.description || resource.payload.content || '活动详情待完善。' }}</p></article><aside class="panel enrollment-panel"><h2>参加活动</h2><p class="muted">活动报名须平台身份资料审核通过且会员在有效期内，名额以服务端确认结果为准。</p><dl v-if="user"><div><dt>资料审核</dt><dd>{{ identityNames[user.identity_status] || '状态待确认' }}</dd></div><div><dt>会员状态</dt><dd>{{ eligibility.member ? `有效至 ${dateTime(user.member_until)}` : '尚未生效或已过期' }}</dd></div></dl><RouterLink v-if="user && !eligibility.eligible" to="/account">前往个人中心完善资料</RouterLink><template v-if="enrollment"><p class="enrollment-status">{{ enrollmentNames[enrollment.status] || enrollment.status }}</p><template v-if="['active', 'checked_in'].includes(enrollment.status)"><h3>活动报名码</h3><code class="ticket-code">{{ enrollment.ticket_code }}</code><p class="muted">请妥善保管，参加活动时向工作人员出示。</p></template><RouterLink v-if="enrollment.order_id" class="plain-link" :to="`/orders/${enrollment.order_id}`">查看报名订单</RouterLink><template v-if="!enrollment.order_id && ['pending', 'active'].includes(enrollment.status)"><button v-if="!cancelOpen" type="button" class="plain-button cancel-button" :disabled="busy" @click="cancelOpen = true">取消免费报名</button><form v-else class="cancel-form" @submit.prevent="cancelEnrollment"><p>取消后将释放活动名额，原报名码失效。</p><label>取消原因<input v-model="cancelReason" maxlength="2000" required></label><div class="button-row"><button type="button" class="plain-button" :disabled="busy" @click="cancelOpen = false">保留报名</button><button type="submit" class="plain-button" :disabled="busy">确认取消</button></div></form></template></template><button v-if="!enrollment || enrollment.status === 'cancelled'" type="button" class="primary-button enroll-button" :disabled="busy || !isConsumer || !eligibility.eligible" @click="enroll">{{ busy ? '处理中…' : resource.price_cents ? '创建报名订单' : enrollment ? '重新报名' : '立即报名' }}</button></aside></div>
    </template>
  </section>
</template>

<style scoped>
.learning-workspace{max-width:1240px;margin:0 auto;padding:36px 24px 64px;color:#19334d}.learning-heading{display:flex;align-items:center;justify-content:space-between;gap:24px;margin-bottom:24px}.eyebrow{color:#2478d4;font-size:13px;font-weight:600}.learning-heading h1{font-size:30px;font-weight:600;margin:9px 0 0}.panel{background:#fff;border:1px solid #e4eaf0;border-radius:16px;padding:26px}.panel h2{font-size:19px;font-weight:600;margin:0 0 22px}.panel h3{font-size:15px;margin-top:28px}.course-layout{display:grid;grid-template-columns:minmax(0,1fr) 320px;gap:24px}.lesson-player{overflow:hidden;padding:0}.lesson-player video{display:block;width:100%;aspect-ratio:16/9;max-height:550px;background:#142438}.player-caption{padding:24px}.player-caption h2{margin-bottom:12px}.player-caption p,.article-text{white-space:pre-wrap;line-height:1.8}.player-caption small,.muted,.reward-record small{color:#718397;line-height:1.7}.video-empty{display:grid;place-items:center;aspect-ratio:16/9;background:#eaf0f7;color:#657a91}.lesson-list h2 span{font-size:13px;color:#7a8b9e;font-weight:400;float:right}.lesson-choice{display:flex;align-items:center;gap:12px;width:100%;padding:18px 12px;text-align:left;color:#435b73;border:0;border-bottom:1px solid #edf0f4;background:transparent;cursor:pointer}.lesson-choice.selected{border-radius:10px;background:#eaf3ff;color:#1670cf}.lesson-choice small{display:block;font-size:12px;margin-top:7px;color:#8190a0}.lesson-mark{font-size:13px;font-weight:600;min-width:20px}.course-lower-grid{display:grid;grid-template-columns:minmax(0,1fr) 320px;gap:24px;margin-top:24px}.exam-panel fieldset{border:0;padding:0 0 22px;margin:0 0 22px;border-bottom:1px solid #e8edf3}.exam-panel legend{font-size:15px;line-height:1.8;font-weight:500;margin-bottom:10px}.answer-choice{display:flex;align-items:center;gap:10px;padding:10px 4px;color:#536a80}.answer-choice input{accent-color:#247ae6}.configured-reward,.reward-record{border-bottom:1px solid #edf0f4;padding:12px 0;font-size:14px}.configured-reward small{display:block;color:#8391a1;font-size:12px;margin-top:5px}.reward-record strong,.reward-record span{display:block;margin-bottom:8px}.reward-record span{font-size:12px;color:#2478d4}.reward-record p{line-height:1.7}.reward-record a,.plain-link{display:inline-block;color:#1674d4;font-size:14px;margin-top:12px}.event-layout{display:grid;grid-template-columns:minmax(0,1fr) 390px;gap:24px}.event-layout dl{margin:0}.event-layout dl>div{display:grid;grid-template-columns:92px minmax(0,1fr);gap:12px;padding:15px 0;border-bottom:1px solid #edf0f4;font-size:14px}.event-layout dt{color:#8090a0}.event-layout dd{margin:0;overflow-wrap:anywhere}.price{color:#1674d4;font-size:20px;font-weight:600}.article-text{margin-top:28px;font-size:15px}.alert{padding:14px 18px;background:#eef5ff;color:#3d648d;border-radius:10px;line-height:1.7;margin:14px 0 22px}.alert a{color:#1674d4;margin-left:8px}.alert.error{background:#fff1f0;color:#a63b32}.alert.success{background:#eef8f1;color:#287447}.empty-state{padding:70px;text-align:center;color:#7d8b9c}.primary-button,.plain-button{font:inherit;border-radius:24px;padding:11px 24px;cursor:pointer;font-size:14px}.primary-button{background:linear-gradient(110deg,#4bc8ff,#216cf7);border:0;color:white}.plain-button{border:1px solid #dce5ee;background:white;color:#3d658d}.primary-button:disabled,.plain-button:disabled{cursor:not-allowed;opacity:.55}.enroll-button{margin-top:24px;width:100%}.enrollment-status{font-size:22px;color:#2678d2;margin-top:28px}.ticket-code{display:block;background:#f1f6fc;padding:16px;font-size:16px;border-radius:10px;overflow-wrap:anywhere;letter-spacing:1px}.cancel-button{display:block;margin-top:24px}.cancel-form{margin-top:20px;background:#f7f9fc;border-radius:10px;padding:18px}.cancel-form p{font-size:13px;color:#6b7d90;line-height:1.7}.cancel-form label{font-size:14px;display:block}.cancel-form input{display:block;margin-top:10px;padding:10px;width:100%;box-sizing:border-box;border:1px solid #d9e3ef;border-radius:8px;font:inherit}.button-row{display:flex;gap:10px;margin-top:16px}.enrollment-panel>a{display:block;color:#1674d4;font-size:14px;margin-top:20px}button:focus-visible,input:focus-visible,a:focus-visible{outline:3px solid #8dbeff;outline-offset:3px}@media(max-width:900px){.course-layout,.course-lower-grid,.event-layout{grid-template-columns:1fr}.lesson-list{max-height:440px;overflow:auto}}@media(max-width:600px){.learning-workspace{padding:24px 14px}.learning-heading h1{font-size:24px}.panel{padding:20px}.lesson-player{padding:0}.learning-heading{gap:12px}.plain-button{padding:10px 16px}}
</style>
