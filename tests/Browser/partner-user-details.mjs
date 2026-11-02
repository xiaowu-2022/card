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
const detail = {user, financialAccess:{balances:true,receipts:true,commission:true,withdrawals:true,funds:true},canManagePartners:true,canManageSupport:true,canCreateUser:true,canCleanup:true,canRemark:true,canManageRestrictions:true,canAdjustWallet:true,canChangeInvitation:true,canChangeReferrer:true,canAdjustCommission:true,canViewKyc:true,canViewFunds:true,canViewTopups:true,canViewCards:true};
const card = {id:'55555555-5555-4555-8555-555555555555',tenantId:company,userId,userInfo:{id:userId,companyId:company,companyName:user.companyName,accountId:user.accountId,displayName:user.displayName,email:user.email},companyName:user.companyName,userEmail:user.email,promotionRank:7,ordinaryMember:false,directReferrer:{accountId:'202610130001',displayName:'Fixture referrer'},funded:'1234567.12345678',consumed:'-12.87654321',openingFee:'8.12345678',openingFeeAsset:'USDT',balance:'123.45678901',overflowBalance:'0.12345678',providerBalance:'123.33333223',balanceLimit:'2000.00000000',effectiveBalanceLimit:'2000.00000000',currency:'USD',balanceUpdatedAt:'2026-10-30T19:20:21Z',lastTransactionSyncAt:null,productName:'Fixture physical card',maskedPan:'5454 **** **** 1234',formFactor:'physical_card',produceStatus:'produced',trackingNumber:'TRACK-FIXTURE-001',providerStatus:'normal'};
const firstCards = Array.from({length:20},(_,index)=>({...card,id:index?`55555555-5555-4555-8555-${String(index).padStart(12,'0')}`:card.id,productName:index?`Fixture card ${index+1}`:card.productName}));
const lastCard = {...card,id:'66666666-6666-4666-8666-666666666666',productName:'Fixture second page virtual card',maskedPan:'5454 **** **** 9876',formFactor:'virtual_card',produceStatus:null,trackingNumber:null,balance:null,funded:null,consumed:null,openingFee:null,providerStatus:'UNKNOWN'};
const paginator = (data,page=1,total=data.length,last=1)=>({data,total,current_page:page,last_page:last,prev_page_url:page>1?'?page=1':null,next_page_url:page<last?'?page=2':null});
const cardSnapshot = async table=>({headers:(await table.getByRole('columnheader').allTextContents()).slice(0,9),cells:(await table.getByRole('row').nth(1).getByRole('cell').allTextContents()).slice(0,9)});
const out='output/user-partner-card-tabs';mkdirSync(out,{recursive:true});
for(const [name,engine,launch] of [['chrome',chromium,{channel:'chrome'}],['webkit',webkit,{}]]) {
 const browser=await engine.launch({headless:true,...launch});
 try {
  const context=await browser.newContext({viewport:{width:1257,height:852}}), page=await context.newPage(), errors=[],writes=[],cardsReads=[];let wrongIdentity=false,cardsMode='normal',cardAccess=true,detailReadOnly=false,releaseCards,delayedCardStarted,delayedCardFinished;
  page.setDefaultTimeout(10000);
  page.on('pageerror',e=>errors.push(e.message));
  await context.route('**/*',async route=>{
   const req=route.request(),url=new URL(req.url());if(url.origin!==origin)return route.abort();
   if(req.method()!=='GET'){writes.push(url.pathname);return route.abort();}
   if(url.pathname.startsWith('/build/'))return route.fulfill({body:readFileSync('public'+url.pathname),contentType:url.pathname.endsWith('.css')?'text/css':'text/javascript'});
   if(url.pathname.endsWith('/details')) {
    assert.equal(url.pathname,`/platform/tenants/${company}/users/${userId}/details`);
    return route.fulfill({json:wrongIdentity?{...detail,user:{...user,id:'different-account'}}:{...detail,canViewCards:cardAccess,readOnly:detailReadOnly}});
   }
   if(url.pathname===`/platform/tenants/${company}/users/${userId}/cards`) {
    const number=Number(url.searchParams.get('page'));assert.ok([1,2].includes(number));cardsReads.push(number);
    const mode=cardsMode;
    if(mode==='delayed')await new Promise(resolve=>{releaseCards=resolve;delayedCardStarted?.();});
    if(mode==='error')return route.fulfill({status:503,json:{message:'Fixture unavailable'}});
    const result={tenantId:company,userId,cards:mode==='empty'?paginator([]):paginator(number===1?firstCards:[lastCard],number,21,2)};
    if(mode==='wrong-scope')result.userId='another-user';
    if(mode==='wrong-row')result.cards.data=[{...card,tenantId:'another-company'}];
    await route.fulfill({json:result}).catch(()=>{});if(mode==='delayed')delayedCardFinished?.();return;
   }
   if(url.pathname===`/platform/tenants/${company}/cards/${card.id}/transactions`)return route.fulfill({json:{items:[],page:1,hasMore:false,timezone:'Asia/Shanghai',lastPage:1,total:0}});
   if(url.pathname.endsWith('/invitations'))return route.fulfill({json:{account:{companyName:'Fixture company',accountId:user.accountId,displayName:user.displayName,email:user.email},timezone:'Asia/Shanghai',commission:'0',summary:{tables:{ANNUAL:[],ACTIVATION:[]},teamByLevel:[],directPeople:0,indirectPeople:0},details:null}});
   if(['/platform/partners','/platform/users','/platform/cards'].includes(url.pathname)) {
    const shared={errors:{},tenant:null,flash:{},publicAssets:[],auth:{admin:{id:'admin',name:'Fixture owner',permissions:['partners.manage','users.read','wallet.read','ledger.read','cards.read','kyc.read','withdrawals.read','wallet_topups.read']},user:null},i18n:{surface:'platform',locale:url.searchParams.get('fixture_locale')??'en',enabledLocales:['en','zh-CN'],timezone:'Asia/Shanghai'},companies:[{id:company,name:'Fixture company'}]};
    const props=url.pathname==='/platform/partners'?{companyId:company,reportCompanyId:company,report,selectedPartner:selected,partners:{data:[],current_page:2,last_page:2,total:21},pendingFees:{data:[],current_page:1,last_page:1,total:0}}:url.pathname==='/platform/cards'?{filters:{company,tab:'cards'},cards:paginator(firstCards,1,21,2),orders:paginator([]),loads:paginator([])}:{...detail,users:paginator([user],2,21,2),filters:{company,search:'Fixture'}};
    const state={component:url.pathname==='/platform/partners'?'platform/Partners':url.pathname==='/platform/cards'?'platform/Cards':'platform/Users',url:url.pathname+url.search,version:'fixture',props:{...shared,...props}};
    if(req.headers()['x-inertia'])return route.fulfill({headers:{'X-Inertia':'true'},json:state});
    return route.fulfill({contentType:'text/html',body:`<html><head>${(entry.css??[]).map(f=>`<link rel="stylesheet" href="/build/${f}">`).join('')}</head><body><script data-page="app" type="application/json">${JSON.stringify(state)}</script><div id="app"></div><script type="module" src="/build/${entry.file}"></script></body></html>`});
   }
   return route.fulfill({status:404,json:{}});
  });
  // Compare detail information against the real list renderer, using only local fixture reads.
  await page.goto(origin+'/platform/cards');await page.getByText(card.productName,{exact:true}).waitFor();
  const listCards=await cardSnapshot(page.getByRole('table').filter({hasText:card.productName}));
  await page.goto(origin+`/platform/partners?partner=${partner}&page=2`);
  const drawer=page.locator('[data-admin-detail-drawer]');
  await drawer.getByRole('tab',{name:'Basic information',exact:true}).waitFor();
  for(const label of ['User fund flows','Verification','Deposit orders','Withdrawal orders','Cards','View report','Invitation data'])assert.equal(await drawer.getByRole('tab',{name:label,exact:true}).count(),1);
  assert.deepEqual(cardsReads,[],'opening details does not eagerly read cards');
  assert.equal(await drawer.getByRole('button',{name:'Cleanup data',exact:true}).count(),0);
  assert.equal(await drawer.getByRole('button',{name:'Set as partner',exact:true}).count(),0);
  assert.equal(await drawer.getByRole('button',{name:'Make support agent',exact:true}).count(),0);
  await drawer.getByRole('region',{name:'Account information',exact:true}).waitFor();
  for(const label of ['Record entry','Edit','Revoke partner']) {
   const action=drawer.getByRole('button',{name:label,exact:true});assert.equal(await action.count(),1);
   assert.ok(await action.evaluate(el=>!!el.closest('[aria-label="Actions"]')),'partner controls share the user footer');
  }
  await page.screenshot({path:`${out}/${name}-basic.png`});
  const partnerUrl=page.url();
  await drawer.getByRole('tab',{name:'Cards',exact:true}).click();
  const cardsTable=drawer.getByRole('table',{name:'Cards',exact:true});await cardsTable.getByText(card.productName,{exact:true}).waitFor();
  assert.deepEqual(await cardSnapshot(cardsTable),listCards,'partner card fields match the card list');
  assert.deepEqual(cardsReads,[1]);assert.equal(await cardsTable.getByRole('row').count(),21);
  await drawer.getByRole('navigation',{name:'Pagination',exact:true}).getByRole('button',{name:'2',exact:true}).click();
  await cardsTable.getByText(lastCard.productName,{exact:true}).waitFor();
  assert.equal(page.url(),partnerUrl,'card paging preserves partner list filters, page and selected drawer');
  assert.equal(await drawer.getByRole('navigation',{name:'Pagination',exact:true}).getByRole('button',{name:'2',exact:true}).getAttribute('aria-current'),'page');
  assert.equal(await drawer.getByRole('button',{name:'Record entry',exact:true}).count(),1);
  await drawer.getByRole('navigation',{name:'Pagination',exact:true}).getByRole('spinbutton',{name:'Go to page',exact:true}).fill('1');
  await drawer.getByRole('navigation',{name:'Pagination',exact:true}).getByRole('button',{name:'Go',exact:true}).click();
  await cardsTable.getByText(card.productName,{exact:true}).waitFor();
  await cardsTable.getByRole('button',{name:'View transactions',exact:true}).first().click();
  const transactionDrawer=page.locator('[data-admin-detail-drawer]').last();
  await transactionDrawer.getByText('No recorded card transactions.',{exact:true}).waitFor();
  assert.equal(await transactionDrawer.getByRole('button',{name:'Sync this card',exact:true}).count(),0);
  await transactionDrawer.getByRole('button',{name:'Close',exact:true}).click();
  assert.equal(await cardsTable.getByText(card.productName,{exact:true}).count(),1,'closing local transactions retains parent cards tab');
  await page.screenshot({path:`${out}/${name}-partner-cards.png`});
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
  await drawer.getByRole('tab',{name:'卡片',exact:true}).click();
  const chineseCards=drawer.getByRole('table',{name:'卡片',exact:true});await chineseCards.getByText(card.productName,{exact:true}).waitFor();
  for(const label of ['公司 / 用户','用户等级','直属上级','U卡充值','开卡费','消费金额','卡包余额','产品 / 卡片','状态','操作'])assert.equal(await chineseCards.getByRole('columnheader',{name:label,exact:true}).count(),1);
  await page.setViewportSize({width:375,height:852});
  const cardsBody=drawer.locator('[data-detail-body]');assert.ok(await cardsBody.evaluate(el=>el.scrollWidth>=el.clientWidth));
  const chineseFooter=await drawer.locator('[aria-label="操作"]').boundingBox();assert.ok(chineseFooter&&chineseFooter.y>=0&&chineseFooter.y+chineseFooter.height<=852);
  await page.screenshot({path:`${out}/${name}-partner-cards-375-zh.png`});
  await page.setViewportSize({width:1257,height:852});
  // Ordinary user details use the same lazy panel and preserve their parent list URL.
  await page.goto(origin+`/platform/users?company=${company}&search=Fixture&page=2`);
  await page.getByRole('button',{name:'User details · '+user.displayName,exact:true}).click();
  await drawer.getByRole('tab',{name:'Cards',exact:true}).click();await cardsTable.getByText(card.productName,{exact:true}).waitFor();
  assert.deepEqual(await cardSnapshot(cardsTable),listCards,'user card fields match the card list');
  const userUrl=page.url();
  await drawer.getByRole('navigation',{name:'Pagination',exact:true}).getByRole('button',{name:'2',exact:true}).click();
  await cardsTable.getByText(lastCard.productName,{exact:true}).waitFor();assert.equal(page.url(),userUrl);
  await page.screenshot({path:`${out}/${name}-user-cards-page-2.png`});
  // All panel failure/retry and empty states are read-only and retain the detail workspace.
  for(const mode of ['error','wrong-scope','wrong-row','empty']) {
   await drawer.getByRole('tab',{name:'Basic information',exact:true}).click();cardsMode=mode;
   await drawer.getByRole('tab',{name:'Cards',exact:true}).click();
   if(mode==='empty')await drawer.getByText('No matching records.',{exact:true}).waitFor();
   else {
    const operation=page.locator('[data-operation-result]');await operation.getByText('Unable to load. Please retry.',{exact:true}).waitFor();
    assert.equal(await cardsTable.count(),0,'rejected evidence is never rendered');
    await operation.getByRole('button').click();
    cardsMode='normal';await drawer.getByRole('button',{name:'Retry',exact:true}).click();await cardsTable.getByText(card.productName,{exact:true}).waitFor();
   }
   assert.equal(page.url(),userUrl);
  }
  await drawer.getByRole('tab',{name:'Basic information',exact:true}).click();cardsMode='delayed';
  const delayedStarted=new Promise(resolve=>{delayedCardStarted=resolve;}),delayedFinished=new Promise(resolve=>{delayedCardFinished=resolve;});
  await drawer.getByRole('tab',{name:'Cards',exact:true}).click();await delayedStarted;await drawer.getByText('Loading…',{exact:true}).waitFor();
  await drawer.getByRole('tab',{name:'Basic information',exact:true}).click();
  assert.equal(typeof releaseCards,'function');releaseCards();await delayedFinished;cardsMode='normal';
  await drawer.getByRole('region',{name:'Account information',exact:true}).waitFor();
  assert.equal(await cardsTable.count(),0,'a read completed after leaving the tab cannot restore stale cards');
  for(const [allowed,readOnly] of [[false,false],[true,true]]) {
   cardAccess=allowed;detailReadOnly=readOnly;
   const readsBefore=cardsReads.length;await page.goto(origin+`/platform/partners?partner=${partner}&page=2`);
   await drawer.getByRole('tab',{name:'Basic information',exact:true}).waitFor();
   assert.equal(await drawer.getByRole('tab',{name:'Cards',exact:true}).count(),0,'permission/snapshot gates hide live card reads');
   assert.equal(cardsReads.length,readsBefore);
  }
  cardAccess=true;detailReadOnly=false;
  // Mismatched user/company detail evidence must fail closed, even with a valid report DTO.
  wrongIdentity=true;await page.goto(origin+`/platform/partners?partner=${partner}&page=2`);await page.reload();
  await page.getByText('Unable to load. Please retry.',{exact:true}).waitFor();
  assert.equal(await page.getByRole('region',{name:'Account information',exact:true}).count(),0);
  assert.equal(await page.getByRole('button',{name:'Record entry',exact:true}).count(),0);
  assert.equal(writes.length,0);assert.deepEqual(errors,[]);
  console.log(`${name}: shared user/partner card tabs match card-list fields; lazy scoped reads, paging, local transactions, retry/empty/scope/abort gates, English/Chinese and 375px layouts passed (fixtures only).`);
 }finally{await browser.close();}
}
