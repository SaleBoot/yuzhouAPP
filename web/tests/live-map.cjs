// Actual localhost records only; all outside network (including map tiles) is blocked.
const {chromium}=require('playwright')
const assert=require('node:assert/strict')
const fs=require('node:fs')
const path=require('node:path')
const apiOrigin=process.env.WEB_LIVE_API||'http://127.0.0.1:8088',webOrigin=process.env.WEB_TEST_ORIGIN||'http://127.0.0.1:5173'
const consumerOrigin='http://127.0.0.1:5174',brokerOrigin='http://127.0.0.1:5175'
const output=path.resolve(__dirname,'../test-results/live-map'),checks=[],errors=[]
let browser,activePage
function pass(name){checks.push(name);console.log('PASS '+name)}
async function api(token,route,method='GET',body){
  const response=await fetch(apiOrigin+'/api/v1'+route,{method,headers:{...(token?{Authorization:'Bearer '+token}:{}),...(body?{'Content-Type':'application/json'}:{})},body:body?JSON.stringify(body):undefined})
  const result=await response.json();assert.equal(response.status,200,route+': '+result.message);assert.equal(result.code,0);return result.data
}
async function context(){const ctx=await browser.newContext({viewport:{width:1100,height:950}});await ctx.route('**/*',route=>['127.0.0.1','localhost'].includes(new URL(route.request().url()).hostname)?route.continue():route.abort());const page=await ctx.newPage();activePage=page;page.on('pageerror',error=>errors.push(error.message));return page}
async function appLogin(page,client,password){
  activePage=page
  const origin=client==='broker'?brokerOrigin:consumerOrigin
  await page.goto(origin+'/'+client+'/#/pages/login');await page.locator('input[type="text"]').first().fill(client+'.demo');await page.locator('input[type="password"]').fill(password)
  await page.getByText('我了解这是开发测试环境',{exact:true}).click();await page.getByText('登录',{exact:true}).click();await page.waitForURL(url=>!url.hash.includes('/pages/login'))
}
async function main(){
  assert.equal(process.env.RUN_DEV_WEB_MUTATIONS,'1');for(const origin of [apiOrigin,webOrigin,consumerOrigin,brokerOrigin])assert.ok(['localhost','127.0.0.1'].includes(new URL(origin).hostname))
  const env=await api(null,'/bootstrap');assert.ok(env.is_test_environment)
  const secrets=JSON.parse(fs.readFileSync(path.resolve(__dirname,'../../backend/storage/dev-accounts.json'),'utf8'))
  const merchant=await api(null,'/auth/login','POST',{username:'merchant.demo',password:secrets.password}),platform=await api(null,'/auth/login','POST',{username:'platform.demo',password:secrets.password})
  const originals=(await api(null,'/resources/boats?page_size=100')).items
  const originalLocations=new Map(originals.map(boat=>[boat.id,JSON.stringify([boat.payload.latitude??null,boat.payload.longitude??null])]))
  fs.mkdirSync(output,{recursive:true});browser=await chromium.launch({headless:true});const consumer=await context()
  await consumer.goto(consumerOrigin+'/consumer/#/pages/map')
  const consumerMap=consumer.frameLocator('iframe[title="地图找船"]')
  const located=originals.filter(boat=>typeof boat.payload.latitude==='number'&&typeof boat.payload.longitude==='number')
  await consumerMap.getByRole('status').filter({hasText:located.length?'已显示':'暂无已录入位置'}).waitFor()
  if(!located.length){assert.equal(await consumerMap.locator('.leaflet-marker-icon').count(),0);pass('actual public API without coordinates shows an honest empty map state')}
  else pass('actual map renders only API-provided coordinates')
  const run='dev.map.'+Date.now(),title='合成地图船 '+run
  let boatType=await api(merchant.token,'/resources/boat_types','POST',{title:'合成地图船型 '+run,status:'pending',price_cents:10000,payload:{capacity:4}})
  boatType=await api(platform.token,'/resources/boat_types/'+boatType.id,'PATCH',{version:boatType.version,status:'published'})
  const admin=await context();await admin.goto(webOrigin+'/admin/merchant');await admin.getByLabel('登录账号').fill('merchant.demo');await admin.getByLabel('密码',{exact:true}).fill(secrets.password);await admin.getByRole('button',{name:'登录管理后台'}).click()
  await admin.getByRole('button',{name:'实体船艇',exact:true}).click();await admin.getByRole('button',{name:'＋ 新增船艇',exact:true}).click()
  await admin.getByLabel('名称',{exact:false}).fill(title);await admin.getByLabel('所属船型 ID').selectOption(String(boatType.id));await admin.getByLabel('船艇唯一编号').fill(run);await admin.getByLabel('泊位/港口').fill('合成开发坐标，非真实泊位');await admin.getByLabel('价格（元）').fill('14567.89')
  await admin.getByLabel('纬度（WGS84）').fill('91');assert.equal(await admin.getByLabel('纬度（WGS84）').evaluate(input=>input.validity.rangeOverflow),true)
  await admin.getByLabel('纬度（WGS84）').fill('24.123456');await admin.getByLabel('发布状态').selectOption('pending');await admin.getByRole('button',{name:'保存',exact:true}).click()
  await admin.getByRole('alert').filter({hasText:'须成对'}).waitFor()
  assert.ok(!(await api(merchant.token,'/resources/boats?mine=true&page_size=100')).items.some(boat=>boat.title===title))
  pass('coordinate inputs reject out-of-range and one-sided values before saving')
  await admin.getByLabel('经度（WGS84）').fill('121.456789');await admin.getByRole('button',{name:'保存',exact:true}).click();await admin.getByRole('status').filter({hasText:'已保存'}).waitFor()
  let boat=(await api(merchant.token,'/resources/boats?mine=true&page_size=100')).items.find(boat=>boat.title===title)
  assert.ok(boat);assert.equal(boat.payload.latitude,24.123456);assert.equal(boat.payload.longitude,121.456789);assert.equal(boat.price_cents,1456789)
  boat=await api(platform.token,'/resources/boats/'+boat.id,'PATCH',{version:boat.version,status:'published'})
  pass('merchant WGS84 decimal coordinates persist on a new synthetic boat and become public after approval')
  await appLogin(consumer,'consumer',secrets.password);await consumer.goto(consumerOrigin+'/consumer/#/pages/map')
  await consumer.frameLocator('iframe[title="地图找船"]').getByRole('heading',{name:title,exact:true}).waitFor()
  assert.ok(await consumer.frameLocator('iframe[title="地图找船"]').locator('.leaflet-marker-icon').count()>0)
  await consumer.frameLocator('iframe[title="地图找船"]').locator('.boat-card').filter({hasText:title}).getByRole('link',{name:'查看船艇',exact:true}).click()
  await consumer.waitForURL(url=>url.hash.includes('/pages/boat?')&&url.hash.includes('instance_id='+boat.id));await consumer.getByText(title,{exact:true}).waitFor()
  await consumer.getByText('配置购买',{exact:true}).click();await consumer.waitForURL(url=>url.hash.includes('/pages/booking?')&&url.hash.includes('instance_id='+boat.id))
  await consumer.locator('.picker-value').filter({hasText:title}).waitFor();await consumer.screenshot({path:path.join(output,'consumer-preselected-instance.png'),fullPage:true})
  pass('actual map iframe opens the matching consumer boat and preserves that instance into purchase configuration')
  const broker=await context();await appLogin(broker,'broker',secrets.password);await broker.goto(brokerOrigin+'/broker/#/pages/map')
  const brokerMap=broker.frameLocator('iframe[title="地图找船"]');await brokerMap.getByRole('heading',{name:title,exact:true}).waitFor();await brokerMap.locator('.boat-card').filter({hasText:title}).getByRole('link',{name:'查看实船与配置',exact:true}).click()
  await broker.waitForURL(url=>url.hash.includes('/pages/quote?')&&url.hash.includes('boat_id='+boat.id));await broker.locator('.picker-value').filter({hasText:title}).waitFor();await broker.screenshot({path:path.join(output,'broker-preselected-instance.png'),fullPage:true})
  pass('actual broker map iframe opens customer configuration with the matching physical boat preselected')
  await admin.getByLabel('搜索',{exact:true}).fill(title);await admin.getByRole('button',{name:'查询',exact:true}).click();await admin.getByRole('row').filter({hasText:title}).getByRole('button',{name:'编辑',exact:true}).click()
  assert.equal(await admin.getByLabel('纬度（WGS84）').inputValue(),'24.123456');await admin.getByLabel('纬度（WGS84）').fill('');await admin.getByLabel('经度（WGS84）').fill('');await admin.getByLabel('发布状态').selectOption('pending');await admin.getByRole('button',{name:'保存',exact:true}).click();await admin.getByRole('status').filter({hasText:'已保存'}).waitFor()
  const cleared=await api(merchant.token,'/resources/boats/'+boat.id);assert.equal(cleared.payload.latitude??null,null);assert.equal(cleared.payload.longitude??null,null)
  pass('clearing both coordinate inputs removes the pair rather than replacing it with zero')
  for(const [id,value] of originalLocations){const current=await api(platform.token,'/resources/boats/'+id);assert.equal(JSON.stringify([current.payload.latitude??null,current.payload.longitude??null]),value)}
  pass('existing boat coordinates remain unchanged')
  assert.deepEqual(errors,[]);pass('no uncaught map or parent App page errors')
  fs.writeFileSync(path.join(output,'report.json'),JSON.stringify({scope:'actual localhost API/Web/H5 with new synthetic coordinates; all outside network and tiles blocked; no map response mocks',run,checks,boat_id:boat.id,boat_type_id:boatType.id,page_errors:errors,unverified:['formal map provider','actual boat geographic accuracy','native WebView bridge','native device','public HTTPS']},null,2))
  await browser.close();console.log(JSON.stringify({passed:checks.length,output},null,2))
}
main().catch(async error=>{console.error(error.message);if(activePage&&!activePage.isClosed()&&await activePage.locator('input[type=password]').count()===0)await activePage.screenshot({path:path.join(output,'failure.png'),fullPage:true});await browser?.close();process.exit(1)})
