// Mutates only an explicitly enabled local development/test API, using synthetic records.
const { chromium }=require('playwright')
const assert=require('node:assert/strict')
const fs=require('node:fs')
const path=require('node:path')
const crypto=require('node:crypto')
const apiOrigin=process.env.WEB_LIVE_API||'http://127.0.0.1:8088'
const webOrigin=process.env.WEB_TEST_ORIGIN||'http://127.0.0.1:5173'
const output=path.resolve(__dirname,'../test-results/live-management')
let browser,activePage
const checks=[],pageErrors=[]
function passed(name){checks.push(name);console.log('PASS '+name)}
async function api(token,route,method='GET',body,expected=200){
  const response=await fetch(apiOrigin+'/api/v1'+route,{method,headers:{...(token?{Authorization:'Bearer '+token}:{}),...(body?{'Content-Type':'application/json'}:{})},body:body?JSON.stringify(body):undefined})
  const envelope=await response.json()
  assert.equal(response.status,expected,`${method} ${route}: ${envelope.message}`)
  if(expected===200)assert.equal(envelope.code,0,`${method} ${route}: ${envelope.message}`)
  return envelope.data
}
function pdfFile(){
  const stream='BT /F1 18 Tf 30 760 Td (DEVELOPMENT TEST ONLY - SYNTHETIC CONTRACT) Tj ET'
  const objects=['<< /Type /Catalog /Pages 2 0 R >>','<< /Type /Pages /Kids [3 0 R] /Count 1 >>','<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>','<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',`<< /Length ${Buffer.byteLength(stream)} >>\nstream\n${stream}\nendstream`]
  let text='%PDF-1.4\n',offsets=[0]
  objects.forEach((value,index)=>{offsets.push(Buffer.byteLength(text));text+=`${index+1} 0 obj\n${value}\nendobj\n`})
  const xref=Buffer.byteLength(text);text+=`xref\n0 6\n0000000000 65535 f \n${offsets.slice(1).map(n=>String(n).padStart(10,'0')+' 00000 n \n').join('')}trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n${xref}\n%%EOF\n`
  const file=path.join(output,'development-test-only.pdf');fs.writeFileSync(file,text);return file
}
async function uiLogin(role,password){
  const context=await browser.newContext({viewport:{width:1440,height:980}}),page=await context.newPage()
  page.on('pageerror',error=>pageErrors.push(error.message));activePage=page
  await page.goto(webOrigin+'/admin/'+role)
  await page.getByLabel('登录账号').fill(role+'.demo')
  await page.getByLabel('密码',{exact:true}).fill(password)
  await page.getByRole('button',{name:'登录管理后台'}).click()
  await page.getByRole('button',{name:'订单与合同',exact:true}).waitFor()
  return page
}
async function openOrder(page,order){activePage=page;await page.getByRole('button',{name:'订单与合同',exact:true}).click();await page.getByRole('button',{name:'刷新订单',exact:true}).click();await page.getByRole('row').filter({hasText:order.order_no}).getByRole('button',{name:'查看订单 →'}).click();await page.getByRole('dialog',{name:'订单 '+order.order_no,exact:true}).waitFor()}
async function uploadContract(page,order,file,title){
  await openOrder(page,order);await page.getByRole('button',{name:'上传合同',exact:true}).click()
  await page.getByLabel('合同标题').fill(title);await page.getByLabel('已签合同文件').setInputFiles(file)
  await page.getByRole('button',{name:'确认提交'}).click()
  await page.getByRole('dialog',{name:'订单 '+order.order_no,exact:true}).waitFor()
  const fileResponse=page.waitForResponse(response=>/\/api\/v1\/files\/\d+$/.test(response.url())&&response.request().method()==='GET')
  await page.getByRole('button',{name:'预览',exact:true}).last().click();assert.equal((await fileResponse).status(),200)
  await page.getByLabel('PDF合同预览').waitFor();assert.match(await page.getByLabel('PDF合同预览').getAttribute('data'),/^blob:/)
  await page.screenshot({path:path.join(output,title+'-preview.png'),fullPage:true})
  await page.getByRole('button',{name:'关闭预览'}).click();await page.getByRole('dialog',{name:'订单 '+order.order_no,exact:true}).getByRole('button',{name:'关闭',exact:true}).click()
}
async function reviewContract(page,order){await openOrder(page,order);await page.getByRole('button',{name:'审核合同'}).click();await page.getByLabel('处理说明').fill('开发验证：只审核合成PDF材料');await page.getByRole('button',{name:'确认提交'}).click();await page.getByRole('dialog',{name:'订单 '+order.order_no,exact:true}).waitFor();await page.getByRole('dialog').getByRole('button',{name:'关闭',exact:true}).click()}
async function quoteUi(page,order){await openOrder(page,order);await page.getByRole('button',{name:'报价',exact:true}).click();await page.getByLabel('报价金额（元）').fill('1000.00');await page.getByRole('button',{name:'确认提交'}).click();await page.getByRole('dialog',{name:'订单 '+order.order_no,exact:true}).waitFor();await page.getByRole('dialog').getByRole('button',{name:'关闭',exact:true}).click()}
async function fulfillUi(page,order){await openOrder(page,order);for(const state of ['fulfilling','completed']){await page.getByRole('button',{name:'更新履约'}).click();await page.getByLabel('履约状态').selectOption(state);await page.getByLabel('处理说明').fill('开发验证：合成订单履约');await page.getByRole('button',{name:'确认提交'}).click();await page.getByRole('dialog',{name:'订单 '+order.order_no,exact:true}).waitFor()}await page.getByRole('dialog').getByRole('button',{name:'关闭',exact:true}).click()}
async function main(){
  if(process.env.RUN_DEV_WEB_MUTATIONS!=='1')throw Error('Set RUN_DEV_WEB_MUTATIONS=1 to enable synthetic development-record mutations.')
  for(const origin of [apiOrigin,webOrigin])assert.ok(['127.0.0.1','localhost'].includes(new URL(origin).hostname),'This runner only permits localhost.')
  const bootstrap=await api(null,'/bootstrap');assert.ok(bootstrap.is_test_environment&&bootstrap.test_payments_enabled,'API must explicitly enable development test payments.')
  const accounts=JSON.parse(fs.readFileSync(path.resolve(__dirname,'../../backend/storage/dev-accounts.json'),'utf8'))
  fs.mkdirSync(output,{recursive:true});const file=pdfFile(),run='webqa.'+Date.now()
  const auth={};for(const role of ['platform','merchant','agency','broker'])auth[role]=await api(null,'/auth/login','POST',{username:role+'.demo',password:accounts.password})
  const customer=await api(null,'/auth/register','POST',{username:run,password:crypto.randomBytes(18).toString('base64url'),nickname:'开发验证用户 '+run})
  await api(auth.platform.token,'/settings','PUT',{distribution:{broker_bps:1000,member_bps:500,settlement_days:0}})
  const type=await api(auth.merchant.token,'/resources/boat_types','POST',{title:'开发验证船型 '+run,status:'pending',price_cents:10000,payload:{daily_price_cents:10000,capacity:6,category:'开发验证'}})
  await api(auth.platform.token,'/resources/boat_types/'+type.id,'PATCH',{status:'published',version:type.version})
  const boats=[];for(let n=0;n<3;n++){let boat=await api(auth.merchant.token,'/resources/boats','POST',{title:'开发验证实体船 '+run+'.'+n,status:'pending',price_cents:100000,payload:{boat_type_id:type.id,serial_number:run+'.'+n,location:'合成开发泊位',unavailable:0}});boat=await api(auth.platform.token,'/resources/boats/'+boat.id,'PATCH',{status:'published',version:boat.version});boats.push(boat)}
  browser=await chromium.launch({headless:true});const platform=await uiLogin('platform',accounts.password),merchant=await uiLogin('merchant',accounts.password),agency=await uiLogin('agency',accounts.password)
  passed('three management entrances authenticate against the live development API')
  const direct=await api(customer.token,'/orders','POST',{type:'boat_purchase',resource_id:boats[0].id,notes:'开发验证：商家直接订单'})
  await quoteUi(merchant,direct);await api(customer.token,`/orders/${direct.id}/accept-quote`,'POST',{})
  await uploadContract(merchant,direct,file,'merchant-synthetic-contract');passed('merchant quotes, privately uploads and previews a real API PDF')
  await reviewContract(platform,direct);await api(customer.token,`/orders/${direct.id}/sign`,'POST',{version:1,accepted:true});await api(customer.token,`/orders/${direct.id}/pay`,'POST',{channel:'test',idempotency_key:crypto.randomUUID()})
  await fulfillUi(merchant,direct);assert.equal((await api(customer.token,'/orders/'+direct.id)).status,'completed');passed('platform contract audit and merchant fulfillment operate on a synthetic test-paid order')
  await openOrder(platform,direct);await platform.getByRole('button',{name:'确认利润',exact:true}).click();await platform.getByLabel('可分配利润（元）').fill('400.00');await platform.getByLabel('搜索受益人').fill(run);await platform.getByRole('button',{name:'查询账号',exact:true}).click();await platform.getByRole('button',{name:'＋ 添加受益人'}).click();await platform.getByLabel('受益人类别').selectOption('member');await platform.getByLabel('选择受益人').selectOption(String(customer.user.id));await platform.getByLabel('处理说明').fill('开发验证：逐单人工确认合成利润');await platform.getByRole('button',{name:'确认提交'}).click();await platform.getByRole('dialog',{name:'订单 '+direct.order_no,exact:true}).waitFor();await platform.getByRole('dialog').getByRole('button',{name:'关闭',exact:true}).click()
  const initial=await api(auth.platform.token,'/orders/'+direct.id);assert.equal(initial.profit_cents,40000);assert.equal(initial.rule_snapshot.beneficiaries[0].user_id,customer.user.id);passed('initial profit uses an explicit valid member and configured 5 percent')
  const refundRequest=await api(customer.token,`/orders/${direct.id}/refund-request`,'POST',{amount_cents:10000,reason:'开发验证部分退款'})
  await platform.getByRole('button',{name:'退款申请处理',exact:true}).click();await platform.getByRole('row').filter({hasText:'开发验证部分退款'}).getByRole('button',{name:'处理申请'}).click();await platform.getByLabel('审核说明').fill('开发验证：批准合成部分退款');await platform.getByRole('button',{name:'保存审核结果'}).click();await platform.getByRole('button',{name:'执行已批准退款'}).waitFor()
  await platform.getByRole('button',{name:'执行已批准退款'}).click();await platform.getByRole('alert').filter({hasText:/not configured|未配置|unconfigured/}).waitFor();assert.equal((await api(auth.platform.token,'/orders/'+direct.id)).refund_cents,0);passed('unconfigured real refund rejects and preserves approved request and order')
  await platform.getByLabel('执行退款的通道').selectOption('test');await platform.getByRole('button',{name:'执行已批准退款'}).click();await platform.getByRole('status').filter({hasText:'退款已由通道确认成功'}).waitFor()
  const partial=await api(auth.platform.token,'/orders/'+direct.id);assert.equal(partial.refund_cents,10000);assert.equal(partial.profit_status,'needs_reconfirmation');let ledger=await api(auth.platform.token,'/ledger?page_size=100');assert.ok(ledger.items.some(x=>x.order_id==direct.id&&x.state==='frozen'));passed('explicit test refund freezes ledger and marks remaining profit for reconfirmation')
  await openOrder(platform,direct);await platform.getByRole('button',{name:'复核剩余利润'}).click();assert.equal(await platform.getByLabel('选择受益人').count(),0);await platform.getByLabel('可分配利润（元）').fill('350.00');await platform.getByLabel('处理说明').fill('开发验证：退款后重新确认剩余利润');await platform.getByRole('button',{name:'确认提交'}).click();await platform.getByRole('dialog',{name:'订单 '+direct.order_no,exact:true}).waitFor();await platform.screenshot({path:path.join(output,'partial-refund-reconfirmed.png'),fullPage:true});await platform.getByRole('dialog').getByRole('button',{name:'关闭',exact:true}).click()
  const revised=await api(auth.platform.token,'/orders/'+direct.id);assert.equal(revised.profit_cents,35000);assert.equal(revised.profit_status,'confirmed');assert.equal(revised.profit_revision,2);assert.equal(revised.rule_snapshot.beneficiaries[0].user_id,customer.user.id);ledger=await api(auth.platform.token,'/ledger?page_size=100');assert.equal(ledger.items.filter(x=>x.order_id==direct.id).reduce((sum,x)=>sum+x.amount_cents,0),1750);assert.ok(ledger.items.filter(x=>x.order_id==direct.id).every(x=>x.state!=='frozen'));passed('partial refund profit reconfirm retains beneficiaries and adjusts ledger to 1750 cents')
  await api(auth.agency.token,'/customers','POST',{user_id:customer.user.id,broker_id:auth.broker.user.id,name:'开发验证客户 '+run,stage:'viewing',notes:'开发验证客户分配'})
  const brokerOrder=await api(auth.broker.token,'/orders','POST',{type:'boat_purchase',customer_id:customer.user.id,resource_id:boats[1].id,notes:'开发验证：经纪人归属订单'})
  await quoteUi(agency,brokerOrder);await api(customer.token,`/orders/${brokerOrder.id}/accept-quote`,'POST',{})
  await uploadContract(agency,brokerOrder,file,'agency-synthetic-contract');passed('agency quotes, privately uploads and previews its broker order PDF')
  await reviewContract(platform,brokerOrder);await api(customer.token,`/orders/${brokerOrder.id}/sign`,'POST',{version:1,accepted:true});await api(customer.token,`/orders/${brokerOrder.id}/pay`,'POST',{channel:'test',idempotency_key:crypto.randomUUID()});await fulfillUi(agency,brokerOrder)
  await api(auth.merchant.token,'/orders/'+brokerOrder.id,'GET',null,404);await api(auth.agency.token,'/orders/'+direct.id,'GET',null,404)
  const contractList=await api(auth.agency.token,`/orders/${brokerOrder.id}/contracts`);const privateFile=contractList.items[0].file_id
  for(const token of [null,auth.merchant.token]){const res=await fetch(apiOrigin+'/api/v1/files/'+privateFile,{headers:token?{Authorization:'Bearer '+token}:{}});assert.ok([401,403,404].includes(res.status),'private PDF denied')}
  passed('merchant cannot access broker order or PDF; agency cannot access direct merchant order; anonymous PDF denied')
  const third=await api(customer.token,'/orders','POST',{type:'boat_purchase',resource_id:boats[2].id,notes:'开发验证：平台合同上传'})
  await uploadContract(platform,third,file,'platform-synthetic-contract');passed('platform privately uploads and previews a real API PDF')
  assert.deepEqual(pageErrors,[]);passed('no uncaught page errors')
  fs.writeFileSync(path.join(output,'report.json'),JSON.stringify({scope:'local development API and Chromium Web; synthetic records and test payment only',run,checks,page_errors:pageErrors,order_ids:[direct.id,brokerOrder.id,third.id],refund_request_id:refundRequest.id,unverified:['real provider payments/refunds','native device builds','Safari file chooser','cross-agency second-company isolation','production deployment']},null,2))
  await browser.close();console.log(JSON.stringify({scope:'development-only',passed:checks.length,output},null,2))
}
main().catch(async error=>{console.error(error.message);if(activePage&&!activePage.isClosed()){const noPassword=await activePage.locator('input[type=password]').count()===0;if(noPassword)await activePage.screenshot({path:path.join(output,'failure.png'),fullPage:true});console.error('Page alerts:',await activePage.getByRole('alert').allTextContents())}await browser?.close();process.exit(1)})
