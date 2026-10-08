// Synthetic API fixtures verify Web behavior only; this is not backend or payment acceptance.
const { chromium } = require('playwright')
const assert = require('node:assert/strict')
const fs = require('node:fs')
const base = process.env.WEB_TEST_ORIGIN || 'http://127.0.0.1:5173'
const output = process.env.WEB_TEST_OUTPUT || '/tmp/yuzhou-web-ui-fixtures'
fs.mkdirSync(output, { recursive: true })

let activeBrowser, activePage
const runtimeErrors=[]
async function main() {
  const browser = activeBrowser = await chromium.launch({ headless: true })
  const errors = [], mutations = []
  const future = Math.floor(Date.now()/1000)+86400*30
  const account = role => ({ id: role==='platform'?9:role==='agency'?8:7, username:`ui-${role}`, nickname:`合成${role}账号`, role, org_id:role==='platform'?null:1 })
  const order = { id:11,order_no:'UI-FIXTURE-001',type:'boat_purchase',customer_id:1,broker_id:2,org_id:1,status:'completed',amount_cents:1000000,paid_cents:1000000,refund_cents:0,profit_cents:null,contract_version:1,items:[] }
  const contract = { id:1,file_id:1,title:'合成合同',version:1,review_status:'pending',created_at:Math.floor(Date.now()/1000) }
  const records = { courses:[], boat_types:[{id:1,kind:'boat_types',title:'合成船型',price_cents:500000,payload:{daily_price_cents:10000,category:'yacht'},status:'pending',version:1}] }
  const page = activePage = await browser.newPage({ viewport:{width:1440,height:980} })
  page.on('pageerror', err=>(errors.push(err.message),runtimeErrors.push(err.message)))
  await page.route('**/api/v1/**',async route=>{
    const req=route.request(),path=new URL(req.url()).pathname.slice('/api/v1'.length),method=req.method()
    const send=(data,status=200,message='ok')=>route.fulfill({status,contentType:'application/json',body:JSON.stringify({code:status===200?0:status,message,data})})
    const body=method==='GET'?{}:req.postDataJSON()
    if(method!=='GET')mutations.push({path,body})
    if(path==='/auth/login')return send({token:'ui-fixture-token',user:account(body.username.replace('ui-',''))})
    if(path==='/auth/me')return send(account('platform'))
    if(path==='/bootstrap')return send({allow_test_payments:false})
    if(path==='/settings')return send({distribution:{broker_bps:1000,member_bps:500,settlement_days:7},version:1})
    if(path==='/admin/users')return send({items:[{id:2,username:'fixture-broker',nickname:'合成经纪人',role:'broker',org_id:1},{id:1,username:'fixture-member',nickname:'合成会员',role:'consumer',member_until:future,is_member:true}],total:2,page:1,page_size:500})
    if(path==='/orders'){const filter=new URL(req.url()).searchParams.get('status');const items=!filter||filter===order.status?[order]:[];return send({items,total:items.length,page:1,page_size:10})}
    if(path==='/orders/11')return send(order)
    if(path==='/orders/11/contracts')return send({items:[contract]})
    if(path==='/orders/11/contract-review'){contract.review_status=body.status;return send(contract)}
    if(path==='/orders/11/profit-confirm'){order.profit_cents=body.profit_cents;return send(order)}
    if(path==='/orders/11/refund')return send(null,503,'真实支付通道尚未配置')
    if(path.startsWith('/resources/')){
      const [, ,kind,id]=path.split('/');const items=records[kind]||(records[kind]=[])
      if(method==='POST'){const row={...body,id:items.length+1,kind,version:1};items.push(row);return send(row)}
      if(method==='PATCH'){const row=items.find(row=>row.id===Number(id));Object.assign(row,body);return send(row)}
      return send(id?items.find(row=>row.id===Number(id)):{items,total:items.length,page:1,page_size:10})
    }
    if(['/ledger','/withdrawals','/admin/audit','/customers','/admin/organizations'].includes(path))return send({items:[],total:0,page:1,page_size:10})
    return send({})
  })
  await page.goto(`${base}/admin/platform`)
  await page.getByLabel('登录账号').fill('ui-merchant')
  await page.getByLabel('密码',{exact:true}).fill('UiFixtureOnly123!')
  await page.getByRole('button',{name:'登录管理后台'}).click()
  await page.getByRole('alert').filter({hasText:'此账号不属于当前管理入口'}).waitFor()
  assert.equal(await page.getByRole('button',{name:'订单与合同',exact:true}).count(),0)
  await page.getByLabel('登录账号').fill('ui-platform')
  await page.getByLabel('密码',{exact:true}).fill('UiFixtureOnly123!')
  await page.getByRole('button',{name:'登录管理后台'}).click()
  await page.getByRole('heading',{name:'你好，合成platform账号'}).waitFor()
  await page.screenshot({path:`${output}/platform-dashboard.png`,fullPage:true})
  await page.getByRole('button',{name:'课程管理',exact:true}).click()
  await page.getByRole('button',{name:'＋ 新增课程'}).click()
  await page.getByLabel('名称').fill('合成课程')
  await page.getByRole('button',{name:'＋ 添加章节'}).click()
  await page.getByLabel('章节标题').fill('第一章')
  await page.getByLabel('学习视频地址').fill('https://example.invalid/fixture.mp4')
  await page.getByLabel('视频总时长（秒）').fill('60')
  await page.getByRole('button',{name:'＋ 添加题目'}).click()
  await page.getByLabel('题目内容').fill('合成测试题')
  await page.getByLabel('选项 1',{exact:true}).fill('A')
  await page.getByLabel('选项 2',{exact:true}).fill('B')
  await page.getByLabel('及格分数').fill('60')
  await page.screenshot({path:`${output}/course-editor.png`,fullPage:true})
  await page.getByRole('button',{name:'保存',exact:true}).click()
  await page.getByRole('status').filter({hasText:'已保存'}).waitFor()
  const course=mutations.find(item=>item.path==='/resources/courses').body
  assert.equal(course.payload.lessons[0].duration_seconds,60)
  assert.equal(course.payload.questions[0].answer,0)
  assert.equal(course.title,'合成课程')
  await page.getByRole('button',{name:'船型管理',exact:true}).click()
  await page.getByRole('button',{name:'审核',exact:true}).click()
  await page.getByLabel('审核说明').fill('合成审核说明')
  await page.getByRole('button',{name:'保存审核结果'}).click()
  await page.getByRole('status').filter({hasText:'审核结果已保存'}).waitFor()
  assert.equal(records.boat_types[0].status,'published')
  await page.getByRole('button',{name:'订单与合同',exact:true}).click()
  await page.getByRole('button',{name:'查看订单 →'}).click()
  await page.getByRole('button',{name:'审核合同'}).click()
  await page.getByLabel('处理说明').fill('合成合同审核')
  await page.getByRole('button',{name:'确认提交'}).click()
  await page.getByRole('button',{name:'确认利润'}).click()
  await page.getByLabel('可分配利润（元）').fill('2000.29')
  await page.getByRole('button',{name:'＋ 添加受益人'}).click()
  await page.getByLabel('选择受益人').selectOption('2')
  await page.getByLabel('处理说明').fill('合成利润确认说明')
  await page.getByRole('button',{name:'确认提交'}).click()
  await page.getByRole('button',{name:'处理退款'}).click()
  await page.getByLabel('原因').fill('合成退款失败测试')
  await page.getByRole('button',{name:'确认提交'}).click()
  await page.getByRole('alert').filter({hasText:'真实支付通道尚未配置'}).waitFor()
  assert.equal(order.refund_cents,0)
  assert.equal(mutations.find(item=>item.path==='/orders/11/profit-confirm').body.profit_cents,200029)
  assert.equal(await page.getByRole('option',{name:'开发环境测试通道'}).count(),0)
  await page.screenshot({path:`${output}/refund-error.png`,fullPage:true})
  await page.setViewportSize({width:390,height:844})
  await page.screenshot({path:`${output}/mobile-refund.png`,fullPage:true})
  for(const role of ['agency','merchant']){
    await page.goto(`${base}/admin/${role}`)
    await page.getByLabel('登录账号').fill(`ui-${role}`)
    await page.getByLabel('密码',{exact:true}).fill('UiFixtureOnly123!')
    await page.getByRole('button',{name:'登录管理后台'}).click()
    await page.getByRole('heading',{name:`你好，合成${role}账号`}).waitFor()
    assert.equal(await page.getByRole('button',{name:'课程管理',exact:true}).count(),0)
    assert.equal(await page.getByRole('button',{name:'商城商品',exact:true}).count(),0)
    assert.equal(await page.getByRole('button',{name:'商家与经纪公司',exact:true}).count(),0)
    assert.equal(await page.getByRole('button',{name:'活动管理',exact:true}).count(),role==='merchant'?1:0)
    assert.equal(await page.getByRole('button',{name:'账号与经纪人',exact:true}).count(),role==='agency'?1:0)
    await page.screenshot({path:`${output}/${role}-dashboard.png`,fullPage:true})
  }
  assert.deepEqual(errors,[])
  await browser.close()
  console.log(JSON.stringify({fixture_only:true,checks:['wrong-role rejection','management layout','structured course create','resource audit','contract review','exact profit cents','real refund failure retains state','test channel hidden','responsive modal','merchant menus','agency menus'],page_errors:errors,screenshots:output},null,2))
}
main().catch(async err=>{console.error(err);console.error(runtimeErrors);if(activePage){await activePage.screenshot({path:`${output}/failure.png`,fullPage:true});console.error(await activePage.getByRole('button').allTextContents())}await activeBrowser?.close();process.exit(1)})
