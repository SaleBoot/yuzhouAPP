<script setup lang="ts">
import { computed, ref, onMounted } from 'vue'
import { request, query, session, downloadAttachment, can, type Page } from '../api'
import { centsFromInput, money, dateTime, localDateTimeInput, orderKinds, statusNames, displayTitle } from '../domain.js'
import Modal from './Modal.vue'
import StatusBadge from './StatusBadge.vue'
import AttachmentPreview from './AttachmentPreview.vue'

const rows = ref<any[]>([]), total = ref(0), page = ref(1), search = ref(''), type = ref(''), status = ref('')
const loading = ref(false), busy = ref(false), error = ref(''), success = ref(''), current = ref<any>(null), contracts = ref<any[]>([])
const action = ref(''), form = ref<Record<string, any>>({}), file = ref<File | null>(null), uploadedFileId = ref<number | null>(null)
const recipients = ref<{ user_id: string; role: string }[]>([]), users = ref<any[]>([])
const userSearch=ref(''), searchingUsers=ref(false)
const rates = ref({ broker_bps: 0, member_bps: 0, settlement_days: 0 }), allowTest = ref(false)
const preview=ref<any>(null)
const reconfirming=computed(()=>current.value?.profit_status==='needs_reconfirmation')
const actionTitles: Record<string, string> = { quote: '提交报价', fulfill: '更新履约', schedule: '安排服务时间', progress: '记录履约进度', contract: '上传新合同版本', 'contract-review': '审核当前合同版本', 'profit-confirm': '确认可分配利润', refund: '处理退款', cancel: '取消订单' }
const progressStages: Record<string,string> = { planned:'已计划', preparing:'准备中', installing:'安装中', testing:'调试中', delivery:'交付中', completed:'本阶段完成' }
const progressPercent=computed(()=>Math.max(0,...(current.value?.progress||[]).map((entry:any)=>Number(entry.percent))))
const canSchedule=computed(()=>['boat_upgrade','maintenance'].includes(current.value?.type)&&!['cancelled','refunded','completed'].includes(current.value?.status))
const canProgress=computed(()=>['paid','fulfilling','partially_refunded'].includes(current.value?.status)&&!current.value?.completed_at)
const platform = computed(() => session.role === 'platform')
const distributionTotal = computed(() => recipients.value.reduce((sum, item) => sum + Number(item.role === 'broker' ? rates.value.broker_bps : rates.value.member_bps), 0))

async function load() {
  loading.value = true; error.value = ''
  try { const result = await request<Page>('/orders' + query({ page: page.value, page_size: 10, search: search.value, type: type.value, status: status.value })); rows.value = result.items; total.value = result.total }
  catch (err) { error.value = (err as Error).message }
  finally { loading.value = false }
}
async function detail(order: any) {
  error.value = ''
  try {
    current.value = await request(`/orders/${order.id}`)
    const result = await request<any>(`/orders/${order.id}/contracts`)
    contracts.value = Array.isArray(result) ? result : result.items || []
  } catch (err) { error.value = (err as Error).message }
}
async function openAction(name: string) {
  action.value = name; error.value = ''; uploadedFileId.value = null; file.value = null; recipients.value = []
  form.value = { amount: current.value?.amount_cents ? (current.value.amount_cents / 100).toFixed(2) : '', profit: '', notes: '', reason: '', title: '订单合同', valid_until: '', status: name==='contract-review'?'approved':'fulfilling', channel: 'wechat', version: current.value?.contract_version }
  if(name==='schedule'){form.value.scheduled_at=localDateTimeInput(current.value?.schedule?.scheduled_at);form.value.location=current.value?.schedule?.location||''}
  if(name==='progress'){form.value.stage=current.value?.progress?.at(-1)?.stage||'planned';form.value.percent=progressPercent.value}
  if (name === 'refund') form.value.amount = ((Number(current.value.paid_cents) - Number(current.value.refund_cents)) / 100).toFixed(2)
  if (name === 'profit-confirm') {
    if(reconfirming.value){rates.value=current.value.rule_snapshot?.rule||rates.value;recipients.value=(current.value.rule_snapshot?.beneficiaries||[]).map((award:any)=>({user_id:String(award.user_id),role:award.role}));return}
    try {
      const [settings, accounts] = await Promise.all([request<any>('/settings'), request<Page>('/admin/users?page_size=500')])
      rates.value = settings.distribution || rates.value; users.value = accounts.items
    } catch (err) { error.value = (err as Error).message }
  }
}
async function execute() {
  if (busy.value || !current.value) return
  busy.value = true; error.value = ''; success.value = ''
  try {
    let body: Record<string, any> = {}
    if (action.value === 'quote') body = { amount_cents: centsFromInput(form.value.amount), valid_until: form.value.valid_until?.replace('T',' '), notes: form.value.notes }
    if (action.value === 'fulfill') body = { status: form.value.status, notes: form.value.notes }
    if (action.value === 'schedule') {
      const scheduled=new Date(form.value.scheduled_at)
      if(!Number.isFinite(scheduled.getTime())||scheduled.getTime()<=Date.now())throw new Error('请选择未来的服务时间')
      if(!form.value.location?.trim())throw new Error('请填写服务地点')
      body={scheduled_at:scheduled.toISOString(),location:form.value.location.trim()}
    }
    if (action.value === 'progress') {
      const percent=Number(form.value.percent)
      if(!Number.isInteger(percent)||percent<progressPercent.value||percent>100)throw new Error(`进度须为 ${progressPercent.value} 至 100 的整数，不能回退`)
      body={stage:form.value.stage,percent,notes:form.value.notes}
    }
    if (action.value === 'contract-review') body = { version: Number(form.value.version), status: form.value.status, notes: form.value.notes }
    if (action.value === 'cancel') body = { reason: form.value.reason }
    if (action.value === 'refund') body = { amount_cents: centsFromInput(form.value.amount), reason: form.value.reason, channel: form.value.channel, idempotency_key: form.value.idempotency_key || (form.value.idempotency_key = crypto.randomUUID()) }
    if (action.value === 'profit-confirm') {
      if (distributionTotal.value > 10000) throw new Error('受益人总比例不能超过 100%')
      const ids = recipients.value.map(item => Number(item.user_id))
      if (ids.some(id => !Number.isSafeInteger(id) || id <= 0) || new Set(recipients.value.map(item=>item.role)).size !== recipients.value.length || recipients.value.length > 2) throw new Error('请选择有效受益人，每个类别最多一人')
      body = { profit_cents: centsFromInput(form.value.profit), notes: form.value.notes }
      if(!reconfirming.value)body.beneficiaries=recipients.value.map(item => ({ user_id: Number(item.user_id), role: item.role }))
    }
    if (action.value === 'contract') {
      if (!file.value && !uploadedFileId.value) throw new Error('请选择线下签署合同 PDF 或签字图片')
      if (file.value && !uploadedFileId.value) {
        if (!['application/pdf', 'image/jpeg', 'image/png'].includes(file.value.type) || file.value.size > 10 * 1024 * 1024) throw new Error('合同须为不超过 10MB 的 PDF、JPEG 或 PNG 文件')
        const data = new FormData(); data.append('file', file.value); data.append('private', '1')
        const result = await request<{ id: number }>('/uploads', { method: 'POST', body: data }); uploadedFileId.value = result.id
      }
      body = { file_id: uploadedFileId.value, title: form.value.title, notes: form.value.notes }
    }
    await request(`/orders/${current.value.id}/${action.value}`, { method: 'POST', body: JSON.stringify(body) })
    action.value = ''; success.value = '操作已完成，状态以服务端结果为准'; await detail(current.value); await load()
  } catch (err) { error.value = (err as Error).message }
  finally { busy.value = false }
}
async function fileDownload(contract: any) {
  try { await downloadAttachment(`/api/v1/files/${contract.file_id}`, `${contract.title || '订单合同'}-v${contract.version}.pdf`) }
  catch (err) { error.value = (err as Error).message }
}
async function searchUsers(){searchingUsers.value=true;error.value='';try{const result=await request<Page>('/admin/users'+query({search:userSearch.value,page_size:100}));users.value=result.items}catch(err){error.value=(err as Error).message}finally{searchingUsers.value=false}}
function eligibleUsers(role:string){if(role==='broker'){const found=users.value.find(user=>Number(user.id)===Number(current.value.broker_id));return current.value.broker_id?[found||{id:current.value.broker_id,nickname:'本单履约经纪人'}]:[]}return users.value.filter(user=>user.is_member===true||Number(user.member_until)>Date.now()/1000)}
onMounted(async () => {
  load()
  try { const result = await request<any>('/bootstrap'); allowTest.value = result.is_test_environment===true&&result.test_payments_enabled===true } catch { /* Initial lists surface connection errors. */ }
})
</script>

<template>
  <div class="page-heading"><div><h1>订单管理</h1><p>{{ session.role==='merchant'?'仅显示商家自身销售订单。经纪公司旗下订单由经纪人履约。':session.role==='agency'?'管理本公司的销售订单、合同与经纪人履约。':'统一审核交易、合同、退款与订单可分配利润。' }}</p></div><button class="button" @click="load">刷新订单</button></div>
  <p v-if="error&&!action" class="notice danger" role="alert">{{ error }}</p><p v-if="success" class="notice success" role="status">{{ success }}</p>
  <section class="panel"><form class="filter-bar" @submit.prevent="page=1;load()"><label class="search-field"><span>⌕</span><input v-model="search" placeholder="搜索订单编号" aria-label="订单编号" /></label><select v-model="type" aria-label="订单类型"><option value="">全部类型</option><option v-for="(label,key) in orderKinds" :key="key" :value="key">{{ label }}</option></select><select v-model="status" aria-label="订单状态"><option value="">全部状态</option><option v-for="key in ['awaiting_quote','quoted','awaiting_payment','paid','fulfilling','completed','cancelled','partially_refunded','refunded']" :key="key" :value="key">{{ (statusNames as Record<string,string>)[key] }}</option></select><button class="button">查询</button><span class="record-count">{{ total }} 笔订单</span></form>
    <div class="table-scroll"><table><thead><tr><th>订单编号</th><th>业务类型</th><th>客户 / 经纪人</th><th>订单金额</th><th>已收款</th><th>状态</th><th>操作</th></tr></thead><tbody><tr v-if="loading"><td colspan="7" class="empty">正在加载…</td></tr><tr v-else-if="!rows.length"><td colspan="7" class="empty"><div class="empty-mark">▤</div>暂无订单</td></tr><tr v-for="order in rows" v-else :key="order.id"><td class="cell-title">{{ order.order_no }}</td><td>{{ (orderKinds as Record<string,string>)[order.type]||order.type }}</td><td><div>客户 #{{ order.customer_id }}</div><small class="muted">{{ order.broker_id?`经纪人 #${order.broker_id}`:'商家直接履约' }}</small></td><td>{{ money(order.amount_cents) }}</td><td>{{ money(order.paid_cents) }}</td><td><StatusBadge :value="order.status" /></td><td><button class="text-button" @click="detail(order)">查看订单 →</button></td></tr></tbody></table></div><div class="pagination"><span>第 {{ page }} 页</span><button class="button small" :disabled="page<=1||loading" @click="page--;load()">上一页</button><button class="button small" :disabled="page*10>=total||loading" @click="page++;load()">下一页</button></div>
  </section>
  <Modal v-if="current&&!action" :title="`订单 ${current.order_no}`" wide @close="current=null">
    <p v-if="error" class="notice danger" role="alert">{{ error }}</p><p v-if="current.profit_status==='needs_reconfirmation'" class="notice warning">此订单已部分退款，佣金已冻结。平台须复核剩余可分配利润，原受益人和原比例继续保留。</p><div class="order-summary"><div><span>订单金额</span><strong>{{ money(current.amount_cents) }}</strong></div><div><span>实收 / 已退款</span><strong>{{ money(current.paid_cents) }} / {{ money(current.refund_cents) }}</strong></div><div><span>可分配利润</span><strong>{{ current.profit_cents===null?(reconfirming?'退款后待复核':'尚未确认'):money(current.profit_cents) }}</strong></div></div>
    <dl class="detail-grid"><dt>订单状态</dt><dd><StatusBadge :value="current.status" /></dd><dt>业务类型</dt><dd>{{ (orderKinds as Record<string,string>)[current.type]||current.type }}</dd><dt>客户 / 组织</dt><dd>#{{ current.customer_id }} / #{{ current.org_id }}</dd><dt>履约经纪人</dt><dd>{{ current.broker_id?`#${current.broker_id}`:'商家直接销售订单' }}</dd><dt>租赁日期</dt><dd>{{ current.start_date||'—' }} 至 {{ current.end_date||'—' }} <small v-if="current.start_date" class="muted">按日计费，结束日不计</small></dd><dt>订单备注</dt><dd>{{ current.notes||'—' }}</dd></dl>
    <template v-if="['boat_upgrade','maintenance'].includes(current.type)"><h3 class="section-heading">服务预约</h3><dl v-if="current.schedule" class="detail-grid"><dt>服务时间</dt><dd>{{ dateTime(current.schedule.scheduled_at) }}</dd><dt>服务地点</dt><dd>{{ current.schedule.location }}</dd></dl><p v-else class="inline-empty">尚未安排服务时间与地点</p></template>
    <h3 class="section-heading">履约进度</h3><p class="muted">进度记录与订单完成确认分开。填写100%不会自动完成订单。</p><p v-if="!current.progress?.length" class="inline-empty">暂无进度记录</p><div v-for="entry in current.progress||[]" :key="entry.id" class="attachment-row"><div><strong>{{ progressStages[entry.stage]||entry.stage }} · {{ entry.percent }}%</strong><small>{{ dateTime(entry.created_at) }} · {{ entry.notes||'未填写说明' }}</small></div></div>
    <h3 class="section-heading">合同与版本</h3><p class="muted">双方线下签署后上传。新版本须重新确认，未审核的合同不能作为付款依据。</p><div v-if="!contracts.length" class="inline-empty">暂无合同</div><div v-for="contract in contracts" :key="contract.id" class="attachment-row"><span class="file-icon">PDF</span><div><strong>{{ contract.title||'订单合同' }}</strong><small>版本 {{ contract.version }} · {{ (statusNames as Record<string,string>)[contract.review_status]||contract.review_status||'待审核' }} · {{ dateTime(contract.created_at) }}</small></div><button class="text-button" @click="preview=contract">预览</button><button class="text-button" @click="fileDownload(contract)">鉴权下载</button></div>
    <h3 v-if="current.items?.length" class="section-heading">订单项目</h3><div v-for="(item,index) in current.items" :key="index" class="attachment-row"><span>{{ displayTitle(item.title||item.name)||`项目 ${Number(index)+1}` }}</span><span class="record-count">× {{ item.quantity||1 }}　{{ money(item.unit_price_cents??item.price_cents??item.amount_cents) }}</span></div>
    <template #footer><button v-if="['awaiting_quote','quoted'].includes(current.status)" class="button" @click="openAction('quote')">报价</button><button v-if="!Number(current.paid_cents)&&!['cancelled','refunded'].includes(current.status)" class="button" @click="openAction('contract')">上传合同</button><button v-if="canSchedule" class="button" @click="openAction('schedule')">安排服务时间</button><button v-if="canProgress" class="button" @click="openAction('progress')">记录履约进度</button><button v-if="canProgress" class="button primary" @click="openAction('fulfill')">更新履约</button><button v-if="platform&&current.contract_version&&contracts.some(contract=>Number(contract.version)===Number(current.contract_version)&&contract.review_status==='pending')" class="button" @click="openAction('contract-review')">审核合同</button><button v-if="platform&&can('finance')&&['paid','fulfilling','completed','partially_refunded'].includes(current.status)&&(!current.profit_status||['unconfirmed','needs_reconfirmation'].includes(current.profit_status))" class="button primary" @click="openAction('profit-confirm')">{{ reconfirming?'复核剩余利润':'确认利润' }}</button><button v-if="platform&&can('finance')&&Number(current.paid_cents)>Number(current.refund_cents)" class="button warning" @click="openAction('refund')">处理退款</button><button v-if="['awaiting_quote','quoted','awaiting_payment'].includes(current.status)" class="button" @click="openAction('cancel')">取消订单</button></template>
  </Modal>
  <Modal v-if="current&&action" :title="actionTitles[action]" wide @close="!busy&&(action='')">
    <form id="order-action-form" class="form-grid" @submit.prevent="execute"><p v-if="error" class="notice danger span-all" role="alert">{{ error }}</p>
      <template v-if="action==='quote'"><label><span>报价金额（元） *</span><input v-model="form.amount" inputmode="decimal" required /></label><label><span>报价有效期</span><input v-model="form.valid_until" type="datetime-local" /></label></template>
      <template v-if="action==='schedule'"><label><span>服务时间 *</span><input v-model="form.scheduled_at" type="datetime-local" required /></label><label><span>服务地点 *</span><input v-model="form.location" maxlength="300" required /></label></template>
      <template v-if="action==='progress'"><p class="notice span-all">进度不得低于已记录的 {{ progressPercent }}%。记录完成阶段后，订单仍须按正常流程确认完成。</p><label><span>履约阶段</span><select v-model="form.stage"><option v-for="(label,key) in progressStages" :key="key" :value="key">{{ label }}</option></select></label><label><span>完成进度（%） *</span><input v-model.number="form.percent" type="number" :min="progressPercent" max="100" step="1" required /></label></template>
      <template v-if="action==='contract'"><p class="notice span-all">上传私有附件后登记新的合同版本。更换合同后，原版本确认不可沿用。</p><label class="span-all"><span>合同标题 *</span><input v-model="form.title" required /></label><label class="span-all"><span>已签合同文件 *</span><input type="file" accept="application/pdf,image/png,image/jpeg" :disabled="busy" @change="file=($event.target as HTMLInputElement).files?.[0]||null;uploadedFileId=null" /><small>PDF / JPEG / PNG，最多 10MB。只有订单相关人员可以读取。</small></label></template>
      <template v-if="action==='contract-review'"><p class="notice span-all">只审核当前有效合同版本。驳回后，相关人员需要重新上传并确认。</p><label><span>合同版本</span><input :value="form.version" readonly /></label><label><span>审核结果</span><select v-model="form.status"><option value="approved">审核通过</option><option value="rejected">驳回重传</option></select></label></template><label v-if="action==='fulfill'" class="span-all"><span>履约状态</span><select v-model="form.status"><option value="fulfilling">履约中</option><option value="completed">履约完成</option></select></label>
      <template v-if="action==='profit-confirm'"><p class="notice span-all">{{ reconfirming?'部分退款后复核剩余可分配利润。受益人及比例沿用原订单快照，不会按当前新配置改算。':'逐单人工确认实际可分配利润。受益人须显式选择，此页面不会自动分配给下单人。' }}</p><label><span>可分配利润（元） *</span><input v-model="form.profit" inputmode="decimal" required /></label><div class="rate-card"><span>当前配置</span><strong>经纪人 {{ rates.broker_bps/100 }}% · 会员 {{ rates.member_bps/100 }}%</strong><small>结算周期 {{ rates.settlement_days }} 天</small></div><div class="span-all"><h3>本单受益人</h3><div v-if="!reconfirming" class="recipient-row"><input v-model="userSearch" placeholder="搜索受益人姓名或账号" aria-label="搜索受益人" /><button type="button" class="button small" :disabled="searchingUsers" @click="searchUsers">{{ searchingUsers?'正在查询…':'查询账号' }}</button></div><div v-if="reconfirming"><div v-for="recipient in recipients" :key="recipient.role" class="attachment-row"><span>{{ recipient.role==='broker'?'原销售经纪人':'原分红会员' }} #{{ recipient.user_id }}</span><strong class="record-count">{{ (recipient.role==='broker'?rates.broker_bps:rates.member_bps)/100 }}%</strong></div></div><div v-for="(recipient,index) in reconfirming?[]:recipients" :key="index" class="recipient-row"><select v-model="recipient.role" aria-label="受益人类别"><option value="broker">销售经纪人</option><option value="member">分红会员</option></select><select v-model="recipient.user_id" aria-label="选择受益人" required><option value="">请选择账号</option><option v-for="user in eligibleUsers(recipient.role)" :key="user.id" :value="String(user.id)">{{ user.nickname||user.username }} #{{ user.id }}</option></select><button type="button" class="text-button danger-text" @click="recipients.splice(index,1)">移除</button></div><button v-if="!reconfirming&&recipients.length<2" type="button" class="button small" @click="recipients.push({user_id:'',role:'broker'})">＋ 添加受益人</button><p class="muted">已选总比例 {{ distributionTotal/100 }}%；实际金额及会员有效性以服务器校验为准。</p></div></template>
      <template v-if="action==='refund'"><p class="notice warning span-all">真实支付通道未配置时会拒绝退款请求。只有渠道确认成功后，订单与佣金账本才会调整；部分退款后须重新确认剩余利润。</p><label><span>退款金额（元） *</span><input v-model="form.amount" inputmode="decimal" required /></label><label><span>退款通道</span><select v-model="form.channel"><option value="wechat">微信支付</option><option value="alipay">支付宝</option><option v-if="allowTest" value="test">开发环境测试通道</option></select></label></template>
      <label v-if="action==='refund'||action==='cancel'" class="span-all"><span>原因 *</span><textarea v-model="form.reason" rows="3" required /></label><label v-else-if="action!=='schedule'" class="span-all"><span>处理说明</span><textarea v-model="form.notes" rows="3" maxlength="2000" :required="action==='profit-confirm'" /></label>
    </form><template #footer><button class="button" :disabled="busy" @click="action=''">返回订单</button><button class="button primary" form="order-action-form" :disabled="busy">{{ busy?'正在提交…':'确认提交' }}</button></template>
  </Modal>
  <AttachmentPreview v-if="preview" :path="`/api/v1/files/${preview.file_id}`" :title="`${preview.title||'订单合同'} v${preview.version}`" @close="preview=null" />
</template>
