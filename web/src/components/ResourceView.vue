<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { request, query, session, downloadAttachment, type Page } from '../api'
import { centsFromInput, money, statusNames, dateTime, localDateTimeInput, orderKinds } from '../domain.js'
import { labels, type Resource, type Field } from '../schemas'
import Modal from './Modal.vue'
import StatusBadge from './StatusBadge.vue'
import StructuredField from './StructuredField.vue'
import AttachmentPreview from './AttachmentPreview.vue'
import PostDiscussion from './PostDiscussion.vue'
const discussion=ref<any>(null)
async function pin(row:any){error.value='';try{await request(`/posts/${row.id}/pin`,{method:'POST',body:JSON.stringify({is_pinned:!Number(row.payload?.is_pinned)})});success.value='置顶状态已保存';await load()}catch(err){error.value=(err as Error).message}}
const preview=ref<number|null>(null),boatTypes=ref<any[]>([]),history=ref<any>(null),historyAccount=ref<any>(null),historyLoading=ref(false),historyPage=ref(1)
async function userHistory(row:any,reset=true){if(reset)historyPage.value=1;historyAccount.value=row;history.value=null;historyLoading.value=true;error.value='';try{history.value=await request(`/admin/users/${row.id}/history${query({page:historyPage.value,page_size:10})}`)}catch(err){error.value=(err as Error).message}finally{historyLoading.value=false}}
function historyRows(key:string){const value=history.value?.[key];return Array.isArray(value)?value:value?.items||[]}

const props = defineProps<{ resource: Resource }>()
const rows = ref<Record<string, any>[]>([]), total = ref(0), page = ref(1), search = ref(''), status = ref('')
const loading = ref(false), saving = ref(false), error = ref(''), success = ref('')
const structuredKeys = ['image_urls','lessons','questions','parameters','order_types','rewards','options','joint_merchant_codes','before_parameters','after_parameters']
const editing = ref<Record<string, any> | null>(null), form = ref<Record<string, any>>({}), selected = ref<Record<string, any> | null>(null)
const reviewer=ref<Record<string,any>|null>(null),reviewStatus=ref('published'),reviewNotes=ref('')
let loadSequence = 0
async function loadBoatTypes(){if(!props.resource.fields.some(field=>field.key==='boat_type_id'))return;try{const result=await request<Page>('/resources/boat_types?page_size=100&mine=true');boatTypes.value=result.items}catch(err){error.value=(err as Error).message}}
const endpoint = computed(() => props.resource.path || `/resources/${props.resource.kind}`)
const detailEntries = computed(() => selected.value ? [...new Set(['id',...props.resource.fields.map(field=>field.key),'status','version','org_id','created_at','updated_at'])].map(key=>({key,item:value(selected.value!,key)})).filter(entry=>entry.item!==undefined) : [])
const fields = computed(() => props.resource.fields.map(field => {
  if (field.key === 'status' && !props.resource.path && session.role !== 'platform') return { ...field, options: { draft: '草稿', pending: '提交审核', archived: '下架' } }
  if (field.key === 'role' && session.role === 'agency') return { ...field, options: { broker: '销售经纪人' } }
  return field
}).filter(field => !(session.role === 'agency' && ['role','org_id','grant_membership'].includes(field.key)) && !(session.role!=='platform'&&field.key==='joint_merchant_codes'))
  .filter(field => !['credit_code','legal_name','license_file_id'].includes(field.key) || form.value.kind === 'merchant' || props.resource.kind === 'merchants'))

function value(row: Record<string, any>, key: string) { return row[key] ?? row.payload?.[key] ?? row.merchant?.[key] }
function display(row: Record<string, any>, key: string) {
  const item = value(row, key)
  if (key.endsWith('_cents')) return money(item)
  if (key.endsWith('_at') || key === 'member_until') return dateTime(item)
  if (item === null || item === undefined || item === '') return '—'
  if (typeof item === 'object') return JSON.stringify(item)
  return String(item)
}
async function load() {
  const sequence = ++loadSequence
  loading.value = true; error.value = ''
  try {
    const result = await request<Page>(`${endpoint.value}${query({ page: page.value, page_size: 10, search: search.value, status: status.value, mine: true })}`)
    if (sequence === loadSequence) { rows.value = result.items; total.value = result.total }
  } catch (err) { if (sequence === loadSequence) error.value = (err as Error).message }
  finally { if (sequence === loadSequence) loading.value = false }
}
function filter() { page.value = 1; load() }
function openEdit(row?: Record<string, any>) {
  editing.value = row || {}
  form.value = {}
  for (const field of props.resource.fields) {
    const item = row ? value(row, field.key) : undefined
    form.value[field.key] = field.type === 'money' ? (item === undefined ? '' : (Number(item) / 100).toFixed(2)) : field.type === 'json' ? (item === undefined ? '' : JSON.stringify(item, null, 2)) : field.type === 'datetime' ? localDateTimeInput(item) : item ?? (field.key === 'status' ? (props.resource.kind==='organizations'?'pending':props.resource.kind==='users'?'active':'draft') : '')
  }
  error.value = ''
}
function fieldValue(field: Field) {
  const text = form.value[field.key]
  if (field.required && (text === '' || text === undefined)) throw new Error(`请填写${field.label}`)
  if (text === '' || text === undefined) return ['latitude','longitude'].includes(field.key)?null:undefined
  if (field.type === 'money') return centsFromInput(text)
  if (field.type === 'integer') {
    const integer = Number(text)
    if (!Number.isSafeInteger(integer) || integer < 0) throw new Error(`${field.label}须为非负整数`)
    return integer
  }
  if (field.type === 'decimal') {
    const number=Number(text)
    if(!Number.isFinite(number)||(field.min!==undefined&&number<field.min)||(field.max!==undefined&&number>field.max))throw new Error(`${field.label}须为 ${field.min} 至 ${field.max} 的有效数字`)
    return number
  }
  if (field.type === 'json') {
    try { return JSON.parse(text) } catch { throw new Error(`${field.label}须为有效 JSON`) }
  }
  if (field.key === 'member_until') return Math.floor(new Date(text).getTime()/1000)
  if (field.type === 'datetime') return String(text).replace('T', ' ') + ':00'
  return String(text).trim()
}
async function save() {
  if (saving.value || !editing.value) return
  saving.value = true; error.value = ''; success.value = ''
  try {
    const body: Record<string, any> = props.resource.path ? {} : { payload: { ...(editing.value.payload || {}) } }
    if (editing.value.id && !props.resource.path) body.version = editing.value.version
    for (const field of fields.value) {
      const item = fieldValue(field)
      if (item !== undefined) {
        if (props.resource.path || ['title', 'price_cents', 'status'].includes(field.key)) body[field.key] = item
        else body.payload[field.key] = item
      }
    }
    if(props.resource.kind==='boats'&&((body.payload.latitude===null)!==(body.payload.longitude===null)))throw new Error('纬度与经度须成对填写或清空')
    if (props.resource.kind === 'users' && session.role === 'platform') body.org_id = ['consumer','platform'].includes(body.role) ? null : Number(form.value.org_id) || null
    if (body.kind === 'merchant' || props.resource.kind === 'merchants') {
      if (!/^[0-9A-HJ-NP-RTUWXY]{18}$/.test(String(body.credit_code || ''))) throw new Error('请输入18位统一社会信用代码')
      if (!body.license_file_id) throw new Error('请上传营业执照私有附件')
    }
    await request(`${endpoint.value}${editing.value.id ? '/' + editing.value.id : ''}`, { method: editing.value.id ? 'PATCH' : 'POST', body: JSON.stringify(body) })
    editing.value = null; success.value = '已保存'; await load()
  } catch (err) { error.value = (err as Error).message }
  finally { saving.value = false }
}
async function detail(row: Record<string, any>) {
  error.value = ''
  try { selected.value = props.resource.path ? row : await request(`${endpoint.value}/${row.id}`) }
  catch (err) { error.value = (err as Error).message }
}
async function upload(event: Event) {
  const file = (event.target as HTMLInputElement).files?.[0]
  if (!file) return
  if (!['image/png','image/jpeg','image/webp'].includes(file.type) || file.size > 10 * 1024 * 1024) { error.value = '请选择不超过 10MB 的 PNG、JPEG 或 WebP 图片'; return }
  saving.value = true; error.value = ''
  try {
    const body = new FormData(); body.append('file', file); body.append('private', '0')
    const result = await request<{ id: number; url?: string }>('/uploads', { method: 'POST', body })
    if (!result.url) throw new Error('上传已返回文件，但没有公开图片地址，请联系平台确认文件配置')
    const current = form.value.image_urls ? JSON.parse(form.value.image_urls) : []
    if (!Array.isArray(current)) throw new Error('图片地址应为数组')
    form.value.image_urls = JSON.stringify([...current, result.url], null, 2)
  } catch (err) { error.value = (err as Error).message }
  finally { saving.value = false }
}
async function uploadLicense(event: Event) {
  const file = (event.target as HTMLInputElement).files?.[0]
  if (!file) return
  if (!['application/pdf','image/png','image/jpeg'].includes(file.type) || file.size > 10 * 1024 * 1024) { error.value='营业执照须为不超过10MB的PDF、PNG或JPEG';return }
  saving.value=true;error.value=''
  try { const body=new FormData();body.append('file',file);body.append('private','1');const result=await request<{id:number}>('/uploads',{method:'POST',body});form.value.license_file_id=result.id }
  catch(err){error.value=(err as Error).message}finally{saving.value=false}
}
async function review(){if(!reviewer.value||saving.value)return;saving.value=true;error.value='';try{const row=reviewer.value;await request(props.resource.kind==='posts'?`/posts/${row.id}/review`:props.resource.kind==='events'?`/events/${row.id}/review`:`${endpoint.value}/${row.id}`,{method:['posts','events'].includes(props.resource.kind)?'POST':'PATCH',body:JSON.stringify({version:row.version,status:props.resource.kind==='events'&&reviewStatus.value==='published'?'approved':reviewStatus.value,notes:reviewNotes.value,review_notes:reviewNotes.value,...(props.resource.kind==='organizations'&&row.merchant?{credit_code:row.merchant.credit_code,legal_name:row.merchant.legal_name,license_file_id:row.merchant.license_file_id}:{})})});reviewer.value=null;success.value='审核结果已保存';await load()}catch(err){error.value=(err as Error).message}finally{saving.value=false}}
async function licenseDownload(row:Record<string,any>){try{await downloadAttachment(`/api/v1/files/${row.license_file_id||row.merchant?.license_file_id}`,'营业执照')}catch(err){error.value=(err as Error).message}}
watch(() => props.resource.kind, () => { page.value = 1; search.value = ''; status.value = ''; selected.value = null; editing.value = null; success.value = ''; load();loadBoatTypes() }, { immediate: true })
</script>

<template>
  <div class="page-heading"><div><h1>{{ resource.title }}</h1><p>{{ resource.description }}</p></div><button v-if="resource.create !== false" class="button primary" @click="openEdit()">＋ 新增{{ resource.singular }}</button></div>
  <p v-if="error && !editing" class="notice danger" role="alert">{{ error }} <button class="text-button" @click="load">重试</button></p>
  <p v-if="success" class="notice success" role="status">{{ success }}</p>
  <section class="panel">
    <form class="filter-bar" @submit.prevent="filter"><label class="search-field"><span>⌕</span><input v-model="search" :placeholder="`搜索${resource.singular}名称或编号`" aria-label="搜索" /></label><select v-model="status" aria-label="筛选状态"><option value="">全部状态</option><option v-for="(name,key) in statusNames" :key="key" :value="key">{{ name }}</option></select><button class="button" type="submit">查询</button><button class="text-button" type="button" @click="search='';status='';filter()">重置</button><span class="record-count">共 {{ total }} 条</span></form>
    <div class="table-scroll"><table><thead><tr><th>编号</th><th v-for="key in resource.columns" :key="key">{{ labels[key] || key }}</th><th class="operations">操作</th></tr></thead><tbody><tr v-if="loading"><td :colspan="resource.columns.length+2" class="empty">正在加载数据…</td></tr><tr v-else-if="!rows.length"><td :colspan="resource.columns.length+2" class="empty"><div class="empty-mark">▤</div>暂无数据<p>调整筛选条件，或新增{{ resource.singular }}</p></td></tr><tr v-for="row in rows" v-else :key="row.id"><td class="muted">#{{ row.id }}</td><td v-for="key in resource.columns" :key="key"><StatusBadge v-if="key==='status'" :value="row.status" /><span v-else :class="{ 'cell-title': key==='title'||key==='name' }">{{ display(row,key) }}</span></td><td class="operations"><button class="text-button" @click="detail(row)">详情</button><button v-if="resource.kind==='posts'&&session.role==='platform'&&row.status==='published'" class="text-button" @click="discussion=row">评论与回复</button><button v-if="resource.kind==='posts'&&session.role==='platform'" class="text-button" @click="pin(row)">{{ Number(row.payload?.is_pinned)?'取消置顶':'置顶' }}</button><button v-if="resource.kind==='users'&&session.role==='platform'" class="text-button" @click="userHistory(row)">业务历史</button><button v-if="session.role==='platform'||!row.payload?.joint_merchant_codes?.length" class="text-button" @click="openEdit(row)">编辑</button><button v-if="(session.role==='platform'&&row.status==='pending'&&!(resource.kind==='events'&&row.payload?.joint_merchant_codes?.length))||(session.role==='merchant'&&resource.kind==='events'&&row.payload?.joint_merchant_codes?.length)" class="text-button" @click="reviewer=row;reviewStatus=resource.path?'approved':'published';reviewNotes='';error=''">审核</button></td></tr></tbody></table></div>
    <div class="pagination"><span>第 {{ page }} 页 / {{ Math.max(1,Math.ceil(total/10)) }} 页</span><button class="button small" :disabled="page<=1 || loading" @click="page--;load()">上一页</button><button class="button small" :disabled="page*10>=total || loading" @click="page++;load()">下一页</button></div>
  </section>
  <Modal v-if="editing" :title="`${editing.id?'编辑':'新增'}${resource.singular}`" wide @close="!saving && (editing=null)">
    <form id="resource-form" class="form-grid" @submit.prevent="save"><p v-if="error" class="notice danger span-all" role="alert">{{ error }}</p><div v-for="field in fields" :key="field.key" class="form-field" :class="{ 'span-all': ['textarea','json'].includes(field.type||'') }"><label :for="`resource-${field.key}`">{{ field.label }} <b v-if="field.required" class="required">*</b></label><StructuredField v-if="structuredKeys.includes(field.key)" v-model="form[field.key]" :kind="field.key" :disabled="saving" @busy="saving=$event" /><select :id="`resource-${field.key}`" v-else-if="field.key==='boat_type_id'" v-model="form[field.key]" :required="field.required"><option value="">请选择船型</option><option v-for="boatType in boatTypes" :key="boatType.id" :value="boatType.id">{{ boatType.title }} #{{ boatType.id }}</option><option v-if="form[field.key]&&!boatTypes.some(row=>Number(row.id)===Number(form[field.key]))" :value="form[field.key]">当前关联船型 #{{ form[field.key] }}</option></select><select :id="`resource-${field.key}`" v-else-if="field.type==='select'" v-model="form[field.key]" :required="field.required"><option value="">请选择</option><option v-for="(label,key) in field.options" :key="key" :value="key">{{ label }}</option></select><textarea :id="`resource-${field.key}`" v-else-if="field.type==='textarea'||field.type==='json'" v-model="form[field.key]" :rows="field.type==='json'?5:4" :required="field.required" :class="{ code: field.type==='json' }" /><div v-else-if="field.key==='license_file_id'"><input type="file" accept="application/pdf,image/png,image/jpeg" :disabled="saving" aria-label="上传营业执照" @change="uploadLicense" /><small>{{ form.license_file_id?'已上传私有附件 #'+form.license_file_id:'请选择营业执照文件' }}</small></div><input :id="`resource-${field.key}`" v-else v-model="form[field.key]" :type="['integer','decimal'].includes(field.type||'')?'number':field.type==='date'?'date':field.type==='datetime'?'datetime-local':'text'" :inputmode="['money','decimal'].includes(field.type||'')?'decimal':undefined" :min="field.min??(field.type==='integer'?0:undefined)" :max="field.max" :step="field.type==='decimal'?'any':field.type==='integer'?1:undefined" :required="field.required" /><small v-if="field.help&&!structuredKeys.includes(field.key)">{{ field.help }}</small><input v-if="field.key==='image_urls'" type="file" accept="image/png,image/jpeg,image/webp" :disabled="saving" aria-label="上传图片" @change="upload" /></div></form>
    <template #footer><button class="button" :disabled="saving" @click="editing=null">取消</button><button class="button primary" form="resource-form" :disabled="saving">{{ saving?'正在保存…':'保存' }}</button></template>
  </Modal>
  <Modal v-if="selected" :title="`${resource.singular}详情 #${selected.id}`" wide @close="selected=null"><p v-if="error" class="notice danger" role="alert">{{ error }}</p><dl class="detail-grid"><template v-for="entry in detailEntries" :key="entry.key"><dt>{{ labels[entry.key] || entry.key }}</dt><dd><StatusBadge v-if="entry.key==='status'" :value="String(entry.item)" /><ol v-else-if="['lessons','questions'].includes(entry.key)&&Array.isArray(entry.item)" class="detail-list"><li v-for="(row,index) in entry.item" :key="index">{{ row.title||row.question }} <small v-if="row.duration_seconds" class="muted">· {{ row.duration_seconds }} 秒</small><p v-if="row.options" class="muted">{{ row.options.join(' / ') }} · 正确答案 {{ Number(row.answer)+1 }}</p></li></ol><div v-else-if="typeof entry.item==='object'"><p v-for="(item,key) in entry.item" :key="key" class="detail-line">{{ Array.isArray(entry.item)?'':String(key)+'：' }}{{ item }}</p></div><span v-else>{{ entry.key.endsWith('_cents')?money(entry.item):entry.key.endsWith('_at')||entry.key==='member_until'?dateTime(entry.item):entry.item ?? '—' }}</span></dd></template></dl><template #footer><button v-if="selected.license_file_id||selected.merchant?.license_file_id" class="button" @click="preview=Number(selected.license_file_id||selected.merchant?.license_file_id)">预览营业执照</button><button v-if="selected.license_file_id||selected.merchant?.license_file_id" class="button" @click="licenseDownload(selected)">下载营业执照</button><button class="button primary" @click="openEdit(selected);selected=null">编辑资料</button></template></Modal>
  <Modal v-if="reviewer" :title="`审核${resource.singular}`" @close="!saving&&(reviewer=null)"><form id="review-form" class="form-grid" @submit.prevent="review"><p v-if="error" class="notice danger span-all" role="alert">{{ error }}</p><p class="notice span-all">{{ reviewer.title||reviewer.name }} · 当前版本 {{ reviewer.version||'—' }}</p><div v-if="reviewer.merchant" class="notice span-all">{{ reviewer.merchant.legal_name }}<br />统一社会信用代码：{{ reviewer.merchant.credit_code }} <button type="button" class="text-button" @click="preview=Number(reviewer.merchant.license_file_id)">预览营业执照</button><button type="button" class="text-button" @click="licenseDownload(reviewer)">下载营业执照</button></div><label class="span-all"><span>审核结果</span><select v-model="reviewStatus"><option :value="resource.path?'approved':'published'">审核通过</option><option value="rejected">驳回修改</option></select></label><label class="span-all"><span>审核说明 *</span><textarea v-model="reviewNotes" rows="4" required /></label></form><template #footer><button class="button" :disabled="saving" @click="reviewer=null">取消</button><button class="button primary" form="review-form" :disabled="saving">{{ saving?'正在提交…':'保存审核结果' }}</button></template></Modal>
<AttachmentPreview v-if="preview" :path="`/api/v1/files/${preview}`" title="营业执照" @close="preview=null" />
<Modal v-if="historyAccount" :title="(historyAccount.nickname||historyAccount.username)+' · 业务历史'" wide @close="historyAccount=null"><p v-if="error" class="notice danger" role="alert">{{ error }}</p><p v-if="historyLoading" class="empty">正在读取授权业务历史…</p><template v-else><h3 class="section-heading">订单记录</h3><div class="table-scroll"><table><thead><tr><th>订单编号</th><th>类型</th><th>金额</th><th>状态</th><th>时间</th></tr></thead><tbody><tr v-if="!historyRows('orders').length"><td colspan="5" class="empty">暂无历史订单</td></tr><tr v-for="order in historyRows('orders')" :key="order.id"><td>{{ order.order_no }}</td><td>{{ (orderKinds as Record<string,string>)[order.type]||order.type }}</td><td>{{ money(order.amount_cents) }}</td><td><StatusBadge :value="order.status" /></td><td>{{ dateTime(order.created_at) }}</td></tr></tbody></table></div><div class="pagination"><span>历史订单 {{ history?.orders?.total??historyRows('orders').length }} 笔 · 第 {{ historyPage }} 页</span><button class="button small" :disabled="historyPage<=1||historyLoading" @click="historyPage--;userHistory(historyAccount,false)">上一页</button><button class="button small" :disabled="historyPage*10>=Number(history?.orders?.total||0)||historyLoading" @click="historyPage++;userHistory(historyAccount,false)">下一页</button></div><h3 class="section-heading">船艇档案</h3><div v-if="!historyRows('boat_records').length" class="inline-empty">暂无船艇档案</div><div v-for="boat in historyRows('boat_records')" :key="boat.id" class="attachment-row"><strong>{{ boat.title }}</strong><span>{{ boat.payload?.serial_number||boat.payload?.boat_name||'档案 #'+boat.id }}</span><small class="record-count">{{ dateTime(boat.updated_at||boat.created_at) }}</small></div><p class="muted">历史范围及数量以服务端当前接口返回为准。</p></template></Modal>
<PostDiscussion v-if="discussion" :post="discussion" @close="discussion=null" />
</template>
