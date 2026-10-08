import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import { resolve, extname } from 'node:path';
import { chromium } from 'playwright';
import { addParityStates } from '../../scripts/client/parity-states.mjs';
const fixture = addParityStates(JSON.parse(readFileSync('storage/framework/testing/uni-parity/fixtures.json')));
const report = structuredClone(fixture.pages['/promotion/stock'].props.report);
const company = '22222222-2222-4222-8222-222222222222', partner = '33333333-3333-4333-8333-333333333333';
Object.assign(report, { partnerId: partner, version:'partner', cashFlow:null, flowDetails:null,
 accountBalance:{advances:'0',activationCommission:'0',annualCommission:'0',unclassifiedCommission:'0',reimbursements:'0',adjustments:'-19.87654322',hasAdjustments:true,theoretical:'-19.87654322',actual:'0',difference:'-19.87654322'},
 journal:{items:[{id:'adjustment',partner_id:partner,kind:'ADJUSTMENT_DECREASE',amount:'19.87654322',business_date:'2026-10-08',note:'Offline fixture',reverses_id:null,reversed:false,actor_id:'admin',created_at:'2026-10-08T00:00:00Z',account_id:'123456'}],page:1,total:1,hasMore:false}
});
const browser = await chromium.launch({channel:'chrome',headless:true});
try {
 const page = await browser.newPage({viewport:{width:1440,height:1000}});
 const errors=[], writes=[]; page.on('pageerror', e=>errors.push(e.message));
 let accept=false;
 page.on('dialog', async dialog=>{ if(accept) await dialog.accept(); else await dialog.dismiss(); });
 const entry=JSON.parse(readFileSync('public/build/manifest.json'))['resources/js/app.tsx'];
 const origin='http://admin.localhost:8000';
 await page.route('**/*',route=>{
  const req=route.request(),url=new URL(req.url());
  if(url.origin!==origin) return route.abort();
  if(url.pathname.startsWith('/build/')) return route.fulfill({body:readFileSync('public'+url.pathname),contentType:url.pathname.endsWith('.css')?'text/css':'text/javascript'});
  if(req.method()==='POST') {assert.equal(url.pathname,`/platform/tenants/${company}/partners/${partner}/journal`);writes.push(req.postDataJSON());}
  const state={component:'platform/Partners',url:'/platform/partners?partner='+partner,version:'fixture',props:{errors:{},tenant:null,flash:{},publicAssets:[],auth:{admin:{id:'admin',name:'Owner',scope:'PLATFORM',permissions:['partners.manage']},user:null},i18n:{locale:'en',enabledLocales:['en'],timezone:'UTC',surface:'platform'},companies:[{id:company,name:'Fixture company'}],companyId:company,reportCompanyId:company,report:structuredClone(report),partners:{data:[{id:partner,tenant_id:company,company_name:'Fixture company',enabled:true,share_percent:'40',account_id:'123456',display_name:'Fixture partner',email:'partner@example.test'}],current_page:1,last_page:1,total:1},pendingFees:{data:[],current_page:1,last_page:1,total:0}}};
  if(req.headers()['x-inertia']) return route.fulfill({headers:{'X-Inertia':'true'},json:state});
  return route.fulfill({contentType:'text/html',body:`<html><head>${(entry.css??[]).map(f=>`<link rel="stylesheet" href="/build/${f}">`).join('')}</head><body><script data-page="app" type="application/json">${JSON.stringify(state)}</script><div id="app"></div><script type="module" src="/build/${entry.file}"></script></body></html>`});
 });
 await page.goto(origin+'/platform/partners?partner='+partner);
 await page.getByText('Adjustment amount',{exact:true}).waitFor();
 await page.locator('.stock-detail strong').filter({hasText:'Decrease theoretical balance'}).waitFor();
 // Close the report drawer, then use the existing row's Record entry action.
 await page.keyboard.press('Escape');
 await page.getByRole('button',{name:'Record entry',exact:true}).click();
 const form=page.locator('#partner-journal-form');
 await form.locator('select').selectOption('ADJUSTMENT_INCREASE');
 await form.locator('input[inputmode="decimal"]').fill('12.12345678');
 await form.locator('textarea').fill('Offline theoretical adjustment');
 await form.getByRole('button',{name:'Record entry',exact:true}).click();
 assert.equal(writes.length,0);
 accept=true;
 await form.getByRole('button',{name:'Record entry',exact:true}).click();
 await form.waitFor({state:'hidden'});
 assert.equal(writes.length,1);assert.equal(writes[0].kind,'ADJUSTMENT_INCREASE');assert.equal(writes[0].amount,'12.12345678');assert.equal(writes[0].confirmed,true);
 assert.deepEqual(errors,[]); await page.close();
 const mobile=await browser.newPage({viewport:{width:390,height:844}});
 const mobileErrors=[]; mobile.on('pageerror',e=>mobileErrors.push(e.message));
 const mobileOrigin='http://127.0.0.1:5247',root=resolve('dist/clients/specpay/release/h5');
 await mobile.route('**/*',route=>{
  const req=route.request(),u=new URL(req.url());
  if(u.pathname.startsWith('/api/v1')) {
   const key=u.pathname.slice(7);
   if(req.method()!=='GET') {assert.equal(key,'/wallet/ensure');return route.fulfill({json:{}});}
   if(key==='/bootstrap') return route.fulfill({json:{...fixture.authenticated,locale:'en'}});
   if(key==='/client/promotion/stock') return route.fulfill({json:{...structuredClone(fixture.pages['/promotion/stock']),props:{report:structuredClone(report)}}});
   if(key==='/unread') return route.fulfill({json:{messages:0,support:0}});
   return route.fulfill({json:fixture.api[key]??{}});
  }
  if(u.origin!==mobileOrigin) return route.abort();
  const file=resolve(root,'.'+(u.pathname==='/'?'/index.html':decodeURIComponent(u.pathname)));
  if(!file.startsWith(root+'/')||!existsSync(file)) return route.abort();
  const contentType={'.html':'text/html','.js':'text/javascript','.css':'text/css','.json':'application/json','.png':'image/png','.svg':'image/svg+xml','.woff2':'font/woff2'}[extname(file)]??'application/octet-stream';
  return route.fulfill({body:readFileSync(file),contentType});
 });
 for(const hasHistory of [true,false,true]) {
  report.accountBalance.hasAdjustments=hasHistory;
  if(hasHistory && report.accountBalance.adjustments==='-19.87654322') report.accountBalance.adjustments='0.00000000';
  await mobile.goto(mobileOrigin+'/#/pages/screen/index?path='+encodeURIComponent('/promotion/stock'));
  await mobile.getByText('Account balance reconciliation',{exact:true}).waitFor();
  assert.equal(await mobile.getByText('Adjustment amount',{exact:true}).count(),hasHistory?1:0);
  await mobile.goto('about:blank');
 }
 assert.deepEqual(mobileErrors,[]);
 console.log('PASS: Platform adjustment confirmation and exact payload; journal labels; H5 adjustment row including zero history and no-history hiding. Offline only.');
} finally {await browser.close();}
