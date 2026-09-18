// Offline browser acceptance against the built application. No database/provider calls.
import { createServer } from 'node:http';
import { readFile, mkdir } from 'node:fs/promises';
import { chromium } from 'playwright';
import assert from 'node:assert/strict';
const manifest = JSON.parse(await readFile('public/build/manifest.json', 'utf8'));
const app = manifest['resources/js/app.tsx'];
const css = new Set([manifest['resources/css/app.css'].file, ...(app.css ?? [])]);
const company = { id: '00000000-0000-4000-8000-000000000001', name: 'Wealth test company' };
const settings = ['USDT','USDC','ETH','BTC'].map(asset => ({ asset, minimum: '1', revision: '00000000-0000-4000-8000-000000000002', available: '1200.123456', principal: '1000', net:'6.66666666', interest: '6.66666666', products: [1,3,6,12,24,36,60].map((months,i) => ({ months, rate: ['6','8','12','15','16','17','18'][i], enabled: true })) }));
const order = { id: '00000000-0000-4000-8000-000000000003', asset:'USDT', principal:'1000', rate:'8', months:3, status:'ACTIVE',displayStatus:'ACTIVE', startedAt:'2099-01-31T04:00:00Z', maturesAt:'2099-04-30T04:00:00Z', paid:'6.66666666', returnAmount:'993.33333334', clawback:null, closedAt:null, canCancel:true, timezone:'Asia/Kuala_Lumpur', schedule:[1,2,3].map(month => ({month,dueAt:`2099-0${month+1}-28T04:00:00Z`,amount:month===3?'6.66666667':'6.66666666',settledAt:month===1?'2099-02-28T04:00:00Z':null})) };
const overview = {nextInterest:{dueAt:'2026-10-18T00:00:00Z',overdue:false,amounts:[{asset:'USDT',amount:'6.66666666'}]},principalEstimate:'4000',netEstimate:'24',updatedAt:'2026-09-18T00:00:00Z',timezone:'Asia/Kuala_Lumpur',assets:settings.map(s=>({...s,annualRateMin:'6',annualRateMax:'18',charts:{6:{maximum:'6.66666666',ratios:['1','1','1','1','1','-0.5']},12:{maximum:'6.66666666',ratios:['1','1','1','1','1','1','1','1','1','1','1','-0.5']}},paid:'6.66666666',recovered:'0.66666666',net:'6',count:1,value:'1000',share:'25',months:Array.from({length:12},(_,i)=>({month:`2026-${String(i+1).padStart(2,'0')}`,paid:'6.66666666',recovered:i===11?'10':'0',net:i===11?'-3.33333334':'6.66666666',paidRatio:'0.66666666',recoveredRatio:i===11?'1':'0',netRatio:i===11?'-0.33333333':'0.66666666'}))}))};
let locale='zh-CN';
let variant='normal';
const requests=[];
const server=createServer(async(req,res)=>{
  if(req.url.startsWith('/build/')) {
    const filename=req.url.slice('/build/'.length).split('?')[0];
    if(!/^assets\/[a-zA-Z0-9._-]+$/.test(filename)) {res.writeHead(404).end();return;}
    res.writeHead(200,{'Content-Type':filename.endsWith('.css')?'text/css':'text/javascript'}).end(await readFile('public/build/'+filename)); return;
  }
  if(req.url==='/favicon.ico'){res.writeHead(204).end();return;}
  let body='';for await(const chunk of req)body+=chunk;
  if(req.method==='POST') {requests.push({url:req.url,body:JSON.parse(body)});res.writeHead(303,{Location:'/wealth/orders/'+order.id}).end();return;}
  const parsedUrl=new URL(req.url,'http://localhost');
  const view=parsedUrl.searchParams.get('view')??'deposit';
  const history=req.url.startsWith('/assets/')||req.url.startsWith('/funds');
  const activityRows=['Wealth deposit debit','Wallet top up','Promotion annual fee paid','Card reload reserved','Card opening fee reserved','Wealth interest received','Security deposit'].map((kind,i)=>({id:String(i),asset:'USDT',kind,amount:i===1?'16880':i===5?'15':'-3000',time:'2026-09-18T08:27:00Z'}));
  const admin=req.url.startsWith('/platform')||req.url.startsWith('/admin');
  const readOnly=req.url.startsWith('/admin');
  const detail=req.url.startsWith('/wealth/orders/');
  const home=req.url==='/wealth';
  const overviewPage=structuredClone(overview);
  if(variant==='empty') {
    overviewPage.nextInterest=null;overviewPage.principalEstimate='0';overviewPage.netEstimate='0';overviewPage.updatedAt=null;
    overviewPage.assets.forEach(a=>{a.principal='0';a.net='0';a.count=0;a.share='0';a.annualRateMin=null;a.annualRateMax=null;a.months.forEach(m=>{m.paid='0';m.recovered='0';m.net='0';});a.charts={6:{maximum:'0',ratios:Array(6).fill('0')},12:{maximum:'0',ratios:Array(12).fill('0')}};});
  }
  if(variant==='expired') { overviewPage.principalEstimate=null;overviewPage.netEstimate=null;overviewPage.updatedAt=null;overviewPage.assets.forEach(a=>{a.share=null;a.value=null;}); }
  if(variant==='long') overviewPage.assets.forEach(a=>{a.available='123456789012.123456789012345678';a.principal='0.000000000000000001';a.net='0.000000000000000001';});
  const page={component:history?'user/AssetHistory':admin?'platform/WealthSettings':detail?'user/WealthOrder':home?'user/WealthOverview':'user/Wealth',url:req.url,version:'wealth-preview',props:{
    errors:{},flash:{success:null},tenant:{...company,branding:{brandName:'Spec Pay',primaryColor:'#39ad8d',logoUrl:null},locales:['zh-CN','en','ms','es']},
    auth:{user:{id:'preview',status:'ACTIVE',displayStatus:'ACTIVE',displayName:'Preview'},admin:{id:'admin',name:'Preview admin',permissions:['tenant.manage','tenant_settings.manage'],scope:readOnly?'TENANT':'PLATFORM'}},
    i18n:{locale,timezone:'Asia/Kuala_Lumpur',enabledLocales:admin?['zh-CN','en']:['zh-CN','en','ms','es'],surface:admin?(readOnly?'tenant-admin':'platform'):'user'},
    ...(history?{selectedAsset:parsedUrl.searchParams.get('asset')??(req.url.startsWith('/funds')?'ALL':'USDT'),balances:['USDT','USDC','ETH','BTC'].map(asset=>({asset,available:'1200.123456'})),rows:{data:activityRows,next_page_url:null,prev_page_url:null}}:admin?{company,settings,readOnly}:detail?{order,startWithdrawal:view==='withdraw'}:home?overviewPage:{settings,view,selectedAsset:parsedUrl.pathname.split('/').at(-1),orders:{data:[order],prev_page_url:null,next_page_url:null}})
  }};
  if(req.headers['x-inertia']){res.writeHead(200,{'Content-Type':'application/json','X-Inertia':'true'}).end(JSON.stringify(page));return;}
  const json=JSON.stringify(page).replaceAll('<', '\\u003c');
  res.writeHead(200,{'Content-Type':'text/html'}).end(`<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1">${[...css].map(f=>`<link rel="stylesheet" href="/build/${f}">`).join('')}</head><body><script type="application/json" data-page="app">${json}</script><div id="app"></div><script type="module" src="/build/${app.file}"></script></body></html>`);
});
await new Promise(r=>server.listen(0,'127.0.0.1',r));
const origin=`http://127.0.0.1:${server.address().port}`;
const browser=await chromium.launch({channel:'chrome',headless:true});
await mkdir('/tmp/card-wealth-browser',{recursive:true});
try {
 for(const width of [375,768,1440]) {
  for(locale of ['zh-CN','en','ms','es']) {
   const page=await browser.newPage({viewport:{width,height:960}});const errors=[];page.on('pageerror',e=>{errors.push(e.message);console.error(e.message);});page.on('console',m=>{if(m.type()==='error')console.error(m.text());});
   await page.goto(origin+'/assets/USDT/activity');await page.locator('main').getByText('+16880 USDT',{exact:false}).waitFor();
   assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
   if(locale==='zh-CN')await page.screenshot({path:`/tmp/card-wealth-browser/${width}-activity.png`,fullPage:true});
   await page.goto(origin+'/funds');await page.locator('#funds-asset').waitFor();
   assert.equal(await page.locator('#funds-asset').inputValue(),'ALL');
   await page.locator('#funds-asset').selectOption('ETH');await page.waitForURL('**/funds?asset=ETH');
   assert.equal(await page.locator('#funds-asset').inputValue(),'ETH');
   assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
   await page.goto(origin+'/wealth');await page.locator('a[href="/wealth/assets/USDT?view=deposit"]').waitFor();
   assert.equal(await page.locator('svg[role=img]').count(),0);
   assert.equal(await page.locator('#earnings-asset').count(),0);
   assert.equal(await page.locator('a[href^="/wealth/assets/"]').count(),12);
   assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
   if(locale==='zh-CN')await page.screenshot({path:`/tmp/card-wealth-browser/${width}-overview.png`,fullPage:true});
   await page.locator('a[href="/wealth/assets/USDT?view=details"]').click();
   await page.locator('a[href*="view=details"]').last().waitFor();
   const depositRow=page.locator('a[href*="/wealth/orders/"]').first();
   await depositRow.focus();assert.equal(await depositRow.evaluate(el=>el===document.activeElement),true);
   assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
   if(locale==='zh-CN')await page.screenshot({path:`/tmp/card-wealth-browser/${width}-details.png`,fullPage:true});assert.equal(await page.locator('#wealth-amount').count(),0);
   await page.goto(origin+'/wealth');
   await page.locator('a[href="/wealth/assets/USDT?view=withdraw"]').click();
   await page.locator('a[href*="/wealth/orders/"]').click();await page.locator('#wealth-password').waitFor();
   await page.goto(origin+'/wealth');
   await page.locator('a[href="/wealth/assets/USDT?view=deposit"]').click();await page.locator('#wealth-amount').waitFor();
   await page.locator('#wealth-term').selectOption('3');await page.locator('#wealth-amount').fill('1000');await page.locator('form button[type=submit]').click();
   await page.locator('[role=dialog]').waitFor();
   assert.equal(await page.locator('[role=dialog] button[type=submit]').isDisabled(),true);
   await page.keyboard.press('Escape');
   assert.equal(await page.locator('[role=dialog]').count(),0);
   assert.equal(await page.locator('#wealth-amount').inputValue(),'1000');
   await page.locator('form button[type=submit]').click();
   await page.locator('[role=dialog]').waitFor();
   assert.equal(await page.locator('[role=dialog] input[type=checkbox]').isChecked(),false);
   await page.locator('[role=dialog] input[type=checkbox]').check();
   assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
   if(locale==='zh-CN')await page.screenshot({path:`/tmp/card-wealth-browser/${width}-deposit-confirm.png`,fullPage:true});
   await page.locator('[role=dialog] button[type=submit]').click();
   await page.waitForTimeout(100);assert.equal(requests.at(-1)?.body.amount,'1000');assert.equal(requests.at(-1)?.body.months,3);
   await page.goto(origin+'/wealth/orders/'+order.id);
   if(locale==='zh-CN')await page.screenshot({path:`/tmp/card-wealth-browser/${width}-order.png`,fullPage:true});
   await page.getByText('993.33333334',{exact:false}).count();
   await page.locator('main button').first().click();await page.locator('#wealth-password').waitFor();
   assert.ok((await page.locator('[role=dialog]').innerText()).includes('993.33333334'));
   await page.locator('#wealth-password').fill('test-password');
   await page.locator('[role=dialog] input[type=checkbox]').check();
   await page.keyboard.press('Escape');
   assert.equal(await page.locator('[role=dialog]').count(),0);
   await page.locator('[data-wealth-withdraw]').click();await page.locator('#wealth-password').waitFor();
   assert.equal(await page.locator('#wealth-password').inputValue(),'');
   assert.equal(await page.locator('[role=dialog] input[type=checkbox]').isChecked(),false);
   const dimensions=await page.evaluate(()=>({scroll:document.documentElement.scrollWidth,width:innerWidth}));assert.ok(dimensions.scroll<=dimensions.width,JSON.stringify(dimensions));
   assert.ok(!(await page.locator('main').innerText()).includes('{{'));
   if(locale==='zh-CN')await page.screenshot({path:`/tmp/card-wealth-browser/${width}-cancel.png`,fullPage:true});
   assert.deepEqual(errors,[]);await page.close();
  }
  locale='zh-CN';
  const page=await browser.newPage({viewport:{width,height:960}});
  await page.goto(origin+'/platform/tenants/'+company.id+'/configuration/wealth');await page.locator('#minimum-USDT').waitFor();
  assert.equal(await page.locator('#minimum-USDT').isEnabled(),true);
  await page.screenshot({path:`/tmp/card-wealth-browser/${width}-settings.png`,fullPage:true});
  await page.goto(origin+'/admin/wealth');await page.locator('#minimum-USDT').waitFor();assert.equal(await page.locator('#minimum-USDT').isDisabled(),true);
  assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));await page.close();
 }
 for (const width of [375,768,1440]) {
  for (variant of ['empty','expired','long']) {
   const page=await browser.newPage({viewport:{width,height:960}});
   await page.goto(origin+'/wealth');await page.locator('a[href="/wealth/assets/USDT?view=deposit"]').waitFor();
   assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
   if(variant==='empty') {assert.equal(await page.locator('svg[role=img]').count(),0);assert.ok((await page.locator('main').innerText()).includes('暂无待到账利息'));}
   if(variant==='expired') assert.ok((await page.locator('main').innerText()).includes('估值暂不可用'));
   if(variant==='long') assert.ok((await page.locator('main').innerText()).includes('0.000000000000000001'));
   await page.close();
  }
 }
 console.log('Wealth browser checks passed: 3 widths, 4 consumer locales, deposit review and cancellation preview, SaaS/company configuration. All requests used offline fixtures.');
} finally {await browser.close();await new Promise(r=>server.close(r));}
