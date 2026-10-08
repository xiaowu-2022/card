import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { chromium } from 'playwright';
const entry = JSON.parse(readFileSync('public/build/manifest.json'))['resources/js/app.tsx'];
const origin = 'http://admin.localhost:8000';
const company = '22222222-2222-4222-8222-222222222222';
const id = '33333333-3333-4333-8333-333333333333';
const browser = await chromium.launch({ headless: true, channel: 'chrome' });
try {
 const page = await browser.newPage();
 const writes = [], errors = [];
 page.on('pageerror', e => errors.push(e.message));
 let legacy = true;
 await page.route('**/*', async route => {
  const req = route.request(), url = new URL(req.url());
  if (url.origin !== origin) return route.abort();
  if (url.pathname.startsWith('/build/')) return route.fulfill({ body: readFileSync('public'+url.pathname), contentType: url.pathname.endsWith('.css') ? 'text/css' : 'text/javascript' });
  if(req.method() === 'POST') {
   assert.equal(url.pathname, `/platform/tenants/${company}/${legacy ? 'topups' : 'asset-orders'}/${id}/confirm`);
   writes.push(req.postDataJSON());
  }
  const state = { component: 'platform/AssetOrders', url: '/platform/topups', version:'fixture', props: {
   errors: {}, publicAssets: [], tenant:null, flash:{},
   auth:{admin:{id:'owner',name:'Owner',email:'owner@example.test',scope:'PLATFORM',permissions:['wallet_topups.read','wallet_topups.confirm','partners.manage']},user:null},
   i18n:{locale:'en',enabledLocales:['en'],timezone:'UTC',surface:'platform'},
   mode:'deposit',companies:[{id:company,name:'Test company'}],filters:{},statuses:['PENDING'],observations:[],
   orders:{data:[{id,source:legacy?'primary':'asset',legacy,reference:'TEST123',tenant_id:company,company:'Test company',accountId:'123456',userEmail:'member@example.test',asset:'USDT',network:'TRON',amount:'100.01000000',status:'PENDING',created_at:'2026-10-08T00:00:00Z',arrival_at:null,operated_at:null,operator:null,fee:null,address:'offline',tx_hash:'',canConfirm:true,canAdvance:true,canRecheck:false,manuallyConfirmed:false,receiptType:'ACTUAL'}],current_page:1,last_page:1,from:1,to:1,total:1,links:[]}
  }};
  if(req.headers()['x-inertia']) return route.fulfill({headers:{'X-Inertia':'true'},json:state});
  return route.fulfill({contentType:'text/html',body:`<!doctype html><html><head>${(entry.css??[]).map(f=>`<link rel="stylesheet" href="/build/${f}">`).join('')}</head><body><script data-page="app" type="application/json">${JSON.stringify(state)}</script><div id="app"></div><script type="module" src="/build/${entry.file}"></script></body></html>`});
 });
 for (const primary of [true,false]) {
  legacy=primary;
  await page.goto(origin+'/platform/topups');
  await page.getByRole('button',{name:'View details',exact:true}).click();
  await page.getByRole('button',{name:'Manual receipt confirmation',exact:true}).click();
  assert.equal(await page.getByRole('button',{name:'Check amount',exact:true}).isDisabled(),true);
  await page.getByRole('textbox',{name:'Actual received amount (USDT)'}).fill('99.12345678');
  await page.getByRole('button',{name:'Check amount',exact:true}).click();
  await page.getByText('-0.88654322 USDT',{exact:true}).waitFor();
  assert.equal(writes.length, primary ? 0 : 1);
  await page.getByRole('button',{name:'Return to edit',exact:true}).click();
  assert.equal(await page.getByRole('textbox',{name:'Actual received amount (USDT)'}).inputValue(),'99.12345678');
  await page.getByRole('textbox',{name:'Actual received amount (USDT)'}).fill('101.01');
  await page.getByRole('button',{name:'Check amount',exact:true}).click();
  await page.getByText('1 USDT',{exact:true}).waitFor();
  await page.getByRole('button',{name:'Confirm receipt',exact:true}).click();
  await page.getByRole('button',{name:'Confirm receipt',exact:true}).waitFor({state:'hidden'});
  assert.equal(writes.at(-1).actual_received_amount,'101.01');
  assert.equal(writes.at(-1).confirmed,true);
 }
 assert.equal(writes.length,2); assert.deepEqual(errors,[]);
 console.log('PASS: both receipt routes, required input, exact difference, return/edit preservation and explicit submission; offline only.');
} finally {await browser.close();}
