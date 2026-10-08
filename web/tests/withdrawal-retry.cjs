// Component-only synthetic API fixture; not a transaction or backend acceptance test.
const {chromium}=require('playwright')
const assert=require('node:assert/strict')
const origin=process.env.WEB_TEST_ORIGIN||'http://127.0.0.1:5173'
let browser
async function main(){
  browser=await chromium.launch({headless:true});const page=await browser.newPage()
  const requests=[],records=new Map();let failNext=true
  await page.route('**/api/v1/**',async route=>{
    const path=new URL(route.request().url()).pathname.slice('/api/v1'.length)
    const reply=(data,status=200,message='ok')=>route.fulfill({status,contentType:'application/json',body:JSON.stringify({code:status===200?0:status,message,data})})
    if(path==='/bootstrap')return reply({is_test_environment:true})
    if(path==='/withdrawals'&&route.request().method()==='POST'){
      const body=route.request().postDataJSON();requests.push(body);assert.ok(body.idempotency_key)
      let record=records.get(body.idempotency_key)
      if(!record){record={id:records.size+1,amount_cents:body.amount_cents};records.set(body.idempotency_key,record)}
      if(failNext){failNext=false;return reply(null,503,'合成夹具：回执暂时不可用，请重试')}
      return reply(record)
    }
    return reply({items:[],total:0,page:1,page_size:15})
  })
  await page.goto(origin+'/admin/')
  await page.evaluate(async()=>{
    const [{createApp},{default:Finance},{session}]=await Promise.all([import('/admin/node_modules/.vite/deps/vue.js'),import('/admin/src/components/FinanceView.vue'),import('/admin/src/api.ts')])
    Object.assign(session,{role:'agency',token:'synthetic-component-fixture',user:{id:1,role:'agency',org_id:1}})
    const element=document.createElement('div');document.body.replaceChildren(element);createApp(Finance,{mode:'withdrawals'}).mount(element)
  })
  const open=async amount=>{await page.getByRole('button',{name:'申请提现',exact:true}).click();await page.getByLabel('申请金额（元）').fill(amount)}
  const submit=async()=>page.getByRole('button',{name:'确认提交',exact:true}).click()
  await open('10');await submit();await page.getByRole('alert').filter({hasText:'回执暂时不可用'}).waitFor()
  await page.getByLabel('申请金额（元）').fill('10.00');await submit();await page.getByRole('button',{name:'申请提现',exact:true}).waitFor()
  assert.equal(requests[0].idempotency_key,requests[1].idempotency_key);assert.equal(records.size,1)
  console.log('PASS failed same-amount retry preserves one key including decimal formatting')
  await open('10');await submit();await page.getByRole('button',{name:'申请提现',exact:true}).waitFor()
  assert.notEqual(requests[2].idempotency_key,requests[1].idempotency_key)
  console.log('PASS a new application after success receives a new key')
  failNext=true;await open('20');await submit();await page.getByRole('alert').filter({hasText:'回执暂时不可用'}).waitFor()
  await page.getByLabel('申请金额（元）').fill('20.01');await submit();await page.getByRole('button',{name:'申请提现',exact:true}).waitFor()
  assert.notEqual(requests[4].idempotency_key,requests[3].idempotency_key);assert.equal(requests[4].amount_cents,2001)
  console.log('PASS changed amount receives a new key and exact cents')
  await browser.close()
}
main().catch(async error=>{console.error(error.message);await browser?.close();process.exit(1)})
