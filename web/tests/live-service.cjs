// Local synthetic records only; these checks do not represent actual installation or payment.
const { chromium }=require('playwright')
const assert=require('node:assert/strict')
const fs=require('node:fs')
const path=require('node:path')
const crypto=require('node:crypto')
const apiOrigin=process.env.WEB_LIVE_API||'http://127.0.0.1:8088'
const webOrigin=process.env.WEB_TEST_ORIGIN||'http://127.0.0.1:5173'
const output=path.resolve(__dirname,'../test-results/live-service')
const checks=[],pageErrors=[]
let browser,activePage
function pass(name){checks.push(name);console.log('PASS '+name)}
async function api(token,route,method='GET',body,expected=200){
  const response=await fetch(apiOrigin+'/api/v1'+route,{method,headers:{...(token?{Authorization:'Bearer '+token}:{}),...(body?{'Content-Type':'application/json'}:{})},body:body?JSON.stringify(body):undefined})
  const result=await response.json();assert.equal(response.status,expected,route+': '+result.message)
  if(expected===200)assert.equal(result.code,0)
  return result.data
}
async function loginUi(role,password){
  const page=await(await browser.newContext({viewport:{width:1440,height:1000}})).newPage();activePage=page
  page.on('pageerror',error=>pageErrors.push(error.message))
  await page.goto(webOrigin+'/admin/'+role)
  await page.getByLabel('登录账号').fill(role+'.demo');await page.getByLabel('密码',{exact:true}).fill(password)
  await page.getByRole('button',{name:'登录管理后台'}).click();await page.getByRole('button',{name:'订单与合同',exact:true}).waitFor()
  return page
}
async function openOrder(page,order){
  activePage=page;await page.getByRole('button',{name:'订单与合同',exact:true}).click()
  await page.getByRole('button',{name:'刷新订单',exact:true}).click()
  await page.getByRole('row').filter({hasText:order.order_no}).getByRole('button',{name:'查看订单 →'}).click()
  await page.getByRole('dialog',{name:'订单 '+order.order_no,exact:true}).waitFor()
}
async function closeOrder(page){await page.getByRole('dialog').getByRole('button',{name:'关闭',exact:true}).click()}
async function scheduleUi(page,time,location){
  await page.getByRole('button',{name:'安排服务时间',exact:true}).click()
  await page.getByLabel('服务时间 *',{exact:true}).fill(time)
  await page.getByLabel('服务地点 *',{exact:true}).fill(location)
  await page.getByRole('button',{name:'确认提交',exact:true}).click()
  await page.getByText(location,{exact:true}).waitFor()
}
async function progressUi(page,stage,percent,notes){
  await page.getByRole('button',{name:'记录履约进度',exact:true}).click()
  await page.getByLabel('履约阶段').selectOption(stage)
  await page.getByLabel('完成进度（%）').fill(String(percent))
  await page.getByLabel('处理说明').fill(notes)
  await page.getByRole('button',{name:'确认提交',exact:true}).click()
  await page.getByText(notes,{exact:false}).waitFor()
}
function datetimeLocal(date){return new Date(date.getTime()-date.getTimezoneOffset()*60000).toISOString().slice(0,16)}
async function main(){
  assert.equal(process.env.RUN_DEV_WEB_MUTATIONS,'1','Enable explicit synthetic development mutations.')
  for(const origin of [apiOrigin,webOrigin])assert.ok(['127.0.0.1','localhost'].includes(new URL(origin).hostname),'Localhost only')
  const env=await api(null,'/bootstrap');assert.ok(env.is_test_environment&&env.test_payments_enabled)
  const secrets=JSON.parse(fs.readFileSync(path.resolve(__dirname,'../../backend/storage/dev-accounts.json'),'utf8'))
  const auth={};for(const role of ['platform','merchant','agency','broker'])auth[role]=await api(null,'/auth/login','POST',{username:role+'.demo',password:secrets.password})
  const run='dev.service.'+Date.now(),customer=await api(null,'/auth/register','POST',{username:run,password:crypto.randomBytes(20).toString('base64url'),nickname:'合成服务验证用户'})
  let resource=await api(auth.merchant.token,'/resources/maintenance','POST',{title:'合成服务验证 '+run,status:'pending',price_cents:12000,payload:{description:'开发合成服务，不是实际维修'}})
  resource=await api(auth.platform.token,'/resources/maintenance/'+resource.id,'PATCH',{status:'published',version:resource.version})
  const direct=await api(customer.token,'/orders','POST',{type:'maintenance',resource_id:resource.id,notes:'合成开发：商家直单'})
  await api(auth.merchant.token,`/orders/${direct.id}/quote`,'POST',{amount_cents:12000})
  await api(customer.token,`/orders/${direct.id}/accept-quote`,'POST',{})
  await api(customer.token,`/orders/${direct.id}/pay`,'POST',{channel:'test',idempotency_key:crypto.randomUUID()})
  fs.mkdirSync(output,{recursive:true});browser=await chromium.launch({headless:true})
  const merchant=await loginUi('merchant',secrets.password),agency=await loginUi('agency',secrets.password),platform=await loginUi('platform',secrets.password)
  await openOrder(merchant,direct)
  const future=datetimeLocal(new Date(Date.now()+86400000)),location='合成开发服务泊位，非真实服务预约'
  await scheduleUi(merchant,future,location)
  let saved=await api(auth.merchant.token,'/orders/'+direct.id)
  assert.equal(saved.schedule.location,location);assert.equal(Number(saved.schedule.scheduled_at),new Date(future).getTime()/1000)
  pass('merchant service time preserves local timezone and location through the actual API')
  await progressUi(merchant,'installing',40,'合成开发：记录安装40%，没有实际安装')
  saved=await api(customer.token,'/orders/'+direct.id);assert.equal(Number(saved.progress.at(-1).percent),40);assert.equal(saved.status,'paid')
  await merchant.screenshot({path:path.join(output,'merchant-service.png'),fullPage:true});await closeOrder(merchant)
  pass('merchant writes visible 40 percent progress without completing the order')
  await api(auth.agency.token,'/customers','POST',{user_id:customer.user.id,broker_id:auth.broker.user.id,name:'合成公司服务客户',notes:'仅开发验证'})
  const brokerOrder=await api(auth.broker.token,'/orders','POST',{type:'maintenance',resource_id:resource.id,customer_id:customer.user.id,notes:'合成开发：公司履约订单'})
  await api(auth.agency.token,`/orders/${brokerOrder.id}/quote`,'POST',{amount_cents:12000})
  await api(customer.token,`/orders/${brokerOrder.id}/accept-quote`,'POST',{})
  const data=new FormData();data.append('private','1');data.append('file',new Blob(['%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n'],{type:'application/pdf'}),'synthetic-development-contract.pdf')
  const uploaded=await fetch(apiOrigin+'/api/v1/uploads',{method:'POST',headers:{Authorization:'Bearer '+auth.agency.token},body:data});assert.equal(uploaded.status,200)
  const file=(await uploaded.json()).data;assert.ok(file.id)
  await api(auth.agency.token,`/orders/${brokerOrder.id}/contract`,'POST',{file_id:file.id,title:'合成开发合同，不是实际法律文件'})
  await api(auth.platform.token,`/orders/${brokerOrder.id}/contract-review`,'POST',{version:1,status:'approved',notes:'只审核开发合成材料'})
  await api(customer.token,`/orders/${brokerOrder.id}/sign`,'POST',{version:1,accepted:true})
  await api(customer.token,`/orders/${brokerOrder.id}/pay`,'POST',{channel:'test',idempotency_key:crypto.randomUUID()})
  await openOrder(agency,brokerOrder);await scheduleUi(agency,future,'合成经纪公司服务地点')
  await progressUi(agency,'testing',60,'合成开发：公司记录调试60%')
  await api(auth.merchant.token,'/orders/'+brokerOrder.id,'GET',undefined,404)
  await agency.screenshot({path:path.join(output,'agency-service.png'),fullPage:true});await closeOrder(agency)
  pass('agency schedules and records its broker order while supplier merchant remains denied')
  await openOrder(platform,brokerOrder);await progressUi(platform,'delivery',70,'合成开发：平台记录交付70%')
  saved=await api(auth.agency.token,'/orders/'+brokerOrder.id);assert.equal(Number(saved.progress.at(-1).percent),70)
  pass('platform progress persists and is visible in the owning agency scope')
  await platform.getByRole('button',{name:'记录履约进度',exact:true}).click()
  await platform.getByLabel('完成进度（%）').fill('20')
  assert.equal(await platform.getByLabel('完成进度（%）').evaluate(input=>input.validity.rangeUnderflow),true)
  await api(auth.platform.token,`/orders/${brokerOrder.id}/progress`,'POST',{stage:'installing',percent:20,notes:'合成回退拒绝检查'},409)
  assert.equal(Number((await api(auth.platform.token,'/orders/'+brokerOrder.id)).progress.at(-1).percent),70)
  await platform.getByRole('button',{name:'返回订单',exact:true}).click()
  pass('browser and server prevent decreasing recorded progress')
  await progressUi(platform,'completed',100,'合成开发：记录完成阶段，仍需订单完成确认')
  assert.equal((await api(customer.token,'/orders/'+brokerOrder.id)).status,'paid')
  await platform.screenshot({path:path.join(output,'platform-service.png'),fullPage:true})
  pass('100 percent stage does not bypass the normal order completion flow')
  assert.deepEqual(pageErrors,[]);pass('no uncaught errors across the three service management entrances')
  fs.writeFileSync(path.join(output,'report.json'),JSON.stringify({scope:'actual localhost API and Chromium; synthetic service records and test payments only',run,checks,order_ids:[direct.id,brokerOrder.id],page_errors:pageErrors,unverified:['actual installation or service delivery','real payment','native device','public TLS','MySQL business acceptance']},null,2))
  await browser.close();console.log(JSON.stringify({passed:checks.length,output},null,2))
}
main().catch(async error=>{console.error(error.message);if(activePage&&!activePage.isClosed()&&await activePage.locator('input[type=password]').count()===0)await activePage.screenshot({path:path.join(output,'failure.png'),fullPage:true});await browser?.close();process.exit(1)})
