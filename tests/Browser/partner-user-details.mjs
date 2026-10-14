import assert from 'node:assert/strict';
import { readFileSync, mkdirSync } from 'node:fs';
import { chromium, webkit } from 'playwright';
import { addParityStates } from '../../scripts/client/parity-states.mjs';
const fixtures = addParityStates(JSON.parse(readFileSync('storage/framework/testing/uni-parity/fixtures.json')));
const entry = JSON.parse(readFileSync('public/build/manifest.json'))['resources/js/app.tsx'];
const origin = 'http://admin.localhost:8000', company = '22222222-2222-4222-8222-222222222222', partner = '33333333-3333-4333-8333-333333333333', userId = '44444444-4444-4444-8444-444444444444';
const user = { id:userId, companyId:company, companyName:'Fixture company', accountId:'202610140001', displayName:'Fixture partner', email:'partner@example.test', status:'ACTIVE', createdAt:'2026-10-14T00:00:00Z', lastLoginAt:null, remark:null, remarkRevision:0, supportAgent:false, supportRevision:0, partnerId:partner, promotionRank:7, ordinaryMember:false, wallets:[], availableBalance:'0', securityDeposit:'0', commission:'0', totalWithdrawn:'0', receiptTotals:[], ancestors:[], promotionTeamCount:0, invitationCode:'523612' };
const selected = {id:partner,tenant_id:company,user_id:userId,company_name:'Fixture company',enabled:true,updated_at:'2026-10-14T00:00:00Z',share_percent:'40',account_id:user.accountId,display_name:user.displayName,email:user.email,userInfo:{id:userId,companyId:company,companyName:'Fixture company',accountId:user.accountId,displayName:user.displayName,email:user.email}};
const report = {...structuredClone(fixtures.pages['/promotion/stock'].props.report),partnerId:partner,displayName:user.displayName,email:user.email,accountId:user.accountId,version:'partner'};
report.trends.depositRefunds = { today:'12.34000000', '3':'0', '7':'0', '15':'0', '30':'0' };
const detail = {user, financialAccess:{balances:true,receipts:true,commission:true,withdrawals:true,funds:true},canManagePartners:true,canManageSupport:true,canCreateUser:true,canCleanup:true,canRemark:true,canManageRestrictions:true,canAdjustWallet:true,canChangeInvitation:true,canChangeReferrer:true,canAdjustCommission:true,canViewKyc:true,canViewFunds:true,canViewTopups:true};
const out='artifacts/partner-user-details';mkdirSync(out,{recursive:true});
for(const [name,engine,launch] of [['chrome',chromium,{channel:'chrome'}],['webkit',webkit,{}]]) {
 const browser=await engine.launch({headless:true,...launch});
 try {
  const context=await browser.newContext({viewport:{width:1257,height:852}}), page=await context.newPage(), errors=[],writes=[];let wrongIdentity=false;
  page.setDefaultTimeout(10000);
  page.on('pageerror',e=>errors.push(e.message));
  await context.route('**/*',route=>{
   const req=route.request(),url=new URL(req.url());if(url.origin!==origin)return route.abort();
   if(req.method()!=='GET'){writes.push(url.pathname);return route.abort();}
   if(url.pathname.startsWith('/build/'))return route.fulfill({body:readFileSync('public'+url.pathname),contentType:url.pathname.endsWith('.css')?'text/css':'text/javascript'});
   if(url.pathname.endsWith('/details')) {
    assert.equal(url.pathname,`/platform/tenants/${company}/users/${userId}/details`);
    return route.fulfill({json:wrongIdentity?{...detail,user:{...user,id:'different-account'}}:detail});
   }
   if(url.pathname.endsWith('/invitations'))return route.fulfill({json:{account:{companyName:'Fixture company',accountId:user.accountId,displayName:user.displayName,email:user.email},timezone:'Asia/Shanghai',commission:'0',summary:{tables:{ANNUAL:[],ACTIVATION:[]},teamByLevel:[],directPeople:0,indirectPeople:0},details:null}});
   if(url.pathname==='/platform/partners') {
    const state={component:'platform/Partners',url:url.pathname+url.search,version:'fixture',props:{errors:{},tenant:null,flash:{},publicAssets:[],auth:{admin:{id:'admin',name:'Fixture owner',permissions:['partners.manage','users.read','wallet.read','ledger.read','cards.read','kyc.read','withdrawals.read','wallet_topups.read']},user:null},i18n:{surface:'platform',locale:url.searchParams.get('fixture_locale')??'en',enabledLocales:['en','zh-CN'],timezone:'Asia/Shanghai'},companies:[{id:company,name:'Fixture company'}],companyId:company,reportCompanyId:company,report,selectedPartner:selected,partners:{data:[],current_page:2,last_page:2,total:21},pendingFees:{data:[],current_page:1,last_page:1,total:0}}};
    if(req.headers()['x-inertia'])return route.fulfill({headers:{'X-Inertia':'true'},json:state});
    return route.fulfill({contentType:'text/html',body:`<html><head>${(entry.css??[]).map(f=>`<link rel="stylesheet" href="/build/${f}">`).join('')}</head><body><script data-page="app" type="application/json">${JSON.stringify(state)}</script><div id="app"></div><script type="module" src="/build/${entry.file}"></script></body></html>`});
   }
   return route.fulfill({status:404,json:{}});
  });
  await page.goto(origin+`/platform/partners?partner=${partner}&page=2`);
  const drawer=page.locator('[data-admin-detail-drawer]');
  await drawer.getByRole('tab',{name:'Basic information',exact:true}).waitFor();
  for(const label of ['User fund flows','Verification','Deposit orders','Withdrawal orders','View report','Invitation data'])assert.equal(await drawer.getByRole('tab',{name:label,exact:true}).count(),1);
  assert.equal(await drawer.getByRole('button',{name:'Cleanup data',exact:true}).count(),0);
  assert.equal(await drawer.getByRole('button',{name:'Set as partner',exact:true}).count(),0);
  assert.equal(await drawer.getByRole('button',{name:'Make support agent',exact:true}).count(),0);
  await drawer.getByRole('region',{name:'Account information',exact:true}).waitFor();
  for(const label of ['Record entry','Edit','Revoke partner']) {
   const action=drawer.getByRole('button',{name:label,exact:true});assert.equal(await action.count(),1);
   assert.ok(await action.evaluate(el=>!!el.closest('[aria-label="Actions"]')),'partner controls share the user footer');
  }
  await page.screenshot({path:`${out}/${name}-basic.png`});
  await drawer.getByRole('tab',{name:'View report',exact:true}).click();
  await drawer.locator('.partner-stock').waitFor();
  const footer=drawer.locator('[aria-label="Actions"]');const before=await footer.boundingBox();
  await drawer.locator('[data-detail-body]').evaluate(el=>{el.scrollTop=el.scrollHeight;});
  const after=await footer.boundingBox();assert.equal(Math.round(before.y),Math.round(after.y));
  await page.screenshot({path:`${out}/${name}-report-footer.png`});
  await drawer.getByRole('tab',{name:'Invitation data',exact:true}).click();
  await page.waitForTimeout(200);assert.ok(page.url().includes('invitation_partner='+partner));
  assert.equal(await drawer.getByRole('button',{name:'Record entry',exact:true}).count(),1);
  await drawer.getByRole('tab',{name:'Basic information',exact:true}).click();
  await page.setViewportSize({width:375,height:852});
  const mobileFooter=await footer.boundingBox();assert.ok(mobileFooter && mobileFooter.y>=0 && mobileFooter.y+mobileFooter.height<=852);
  await page.screenshot({path:`${out}/${name}-375.png`});
  await drawer.getByRole('button',{name:'Record entry',exact:true}).click();await page.locator('#partner-journal-form').waitFor();assert.equal(writes.length,0);
  await page.setViewportSize({width:1257,height:852});
  await page.goto(origin+`/platform/partners?partner=${partner}&page=2&fixture_locale=zh-CN`);
  await drawer.getByRole('tab',{name:'查看报表',exact:true}).click();
  const refundTitle=drawer.getByRole('rowheader',{name:'保证金退回金额',exact:true});
  await refundTitle.scrollIntoViewIfNeeded();
  const trendsTable=drawer.getByRole('table',{name:'日趋势',exact:true});
  assert.equal(await trendsTable.getByRole('columnheader').count(),6);
  assert.equal(await trendsTable.getByRole('row').count(),Object.keys(report.trends).length+1);
  assert.equal(await refundTitle.locator('..').getByRole('cell').count(),5);
  assert.equal(await drawer.getByRole('rowheader',{name:'depositRefunds',exact:true}).count(),0);
  await page.screenshot({path:`${out}/${name}-refund-trend-zh.png`});
  // Mismatched user/company detail evidence must fail closed, even with a valid report DTO.
  wrongIdentity=true;await page.goto(origin+`/platform/partners?partner=${partner}&page=2`);await page.reload();
  await page.getByText('Unable to load. Please retry.',{exact:true}).waitFor();
  assert.equal(await page.getByRole('region',{name:'Account information',exact:true}).count(),0);
  assert.equal(await page.getByRole('button',{name:'Record entry',exact:true}).count(),0);
  assert.equal(writes.length,0);assert.deepEqual(errors,[]);
  console.log(`${name}: shared user content/tabs, partner report/invitations, fixed bottom actions, removed grants, off-page identity, 375px layout and mismatched identity gate passed (fixtures only).`);
 }finally{await browser.close();}
}
