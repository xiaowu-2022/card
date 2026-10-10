import assert from 'node:assert/strict';
import { readFileSync, mkdirSync } from 'node:fs';
import { chromium, webkit } from 'playwright';
const origin='http://admin.localhost:8000';
const entry=JSON.parse(readFileSync('public/build/manifest.json'))['resources/js/app.tsx'];
const out='artifacts/platform-redesign';mkdirSync(out,{recursive:true});
const company={id:'22222222-2222-4222-8222-222222222222',name:'测试公司'};
const pageOf=(data=[])=>({data,total:data.length,current_page:1,last_page:1,prev_page_url:null,next_page_url:null});
const user={id:'33333333-3333-4333-8333-333333333333',companyId:company.id,companyName:company.name,accountId:'202610109999',displayName:'测试客户',email:'long.customer@example.test',remark:'长备注用于验证换行。'.repeat(30),remarkRevision:0,supportAgent:false,supportRevision:0,status:'ACTIVE',promotionRank:7,ordinaryMember:false,createdAt:'2026-10-10T00:00:00Z',lastLoginAt:null,wallets:[{id:'wallet',asset:'USDT',status:'ACTIVE',available:'12345678901234.123456',securityDeposit:'0',held:'0'}],receiptTotals:[{asset:'USDT',actual:'12345678901234.123456',advance:'100'}],availableBalance:'12345678901234.123456',securityDeposit:'0',commission:'10',totalWithdrawn:'0',promotionTeamCount:500,invitationCode:'523456',ancestors:Array.from({length:12},(_,i)=>({id:`ancestor-${i}`,accountId:`20261010${i}`,displayName:'上级代理名称',email:'ancestor@example.test',distance:i+1,promotionRank:7-i%7,ordinaryMember:false}))};
const financialAccess={balances:true,commission:true,withdrawals:true};
const routes={
 '/platform/settings/assets':['AssetSettings',{company:null,tronAddress:'',tronFeePercent:'1.00000000',tronMinimum:'10.00000000',market:{enabled:true,configured:false,snapshot:null},networks:[],rails:[],companyRails:[],policies:[]}],
 '/platform/settings/domains':['Domains',{company:null,domains:[]}],
 '/platform/settings/sms':['NotificationProfiles',{channel:'sms',profiles:[]}],
 '/platform/settings/email':['NotificationProfiles',{channel:'email',profiles:[]}],
 '/platform/settings/oss':['OssSettings',{configuration:null,serverStorage:true,driverRevision:0}],
 '/platform/settings/kyc':['KycSettings',{policy:{enabled:true,maxAccountsPerIdentity:1,reviewMode:'MANUAL'}}],
 '/platform/users':['Users',{users:pageOf([user]),financialAccess,canCreateUser:true}],
 '/platform/tenants':['Tenants',{tenants:pageOf([{...company,slug:'test',status:'ACTIVE',createdAt:user.createdAt,domains:['example.test']}]),financialAccess:{inflow:false,outflow:false},totals:{}}],
 '/platform/demo':['Dashboard',{filters:{start:'2026-10-01',end:'2026-10-10',scope:'all',companies:[]},days:[],totals:{inflow:'0',outflow:'0',net:'0'},financialAccess:{inflow:true,outflow:true,overflow:false}}],
 '/platform/kyc':['Kyc',{applications:pageOf()}],
 '/platform/cards':['Cards',{orders:pageOf(),loads:pageOf(),cards:pageOf(),filters:{tab:'cards'}}],
 '/platform/topups':['AssetOrders',{mode:'deposit',orders:pageOf(),observations:[],statuses:['PENDING','CREDITED']}],
 '/platform/asset-withdrawals':['AssetOrders',{mode:'withdrawal',orders:pageOf(),observations:[],statuses:['PENDING','COMPLETED']}],
 '/platform/card-products':['CardProducts',{products:[],cardProviders:[]}],
 '/platform/card-providers':['CardProviders',{providers:pageOf()}],
 '/platform/notifications':['Notifications',{company:null,batches:pageOf()}],
 '/platform/financial-operations':['FinancialOperations',{operations:pageOf()}],
 '/platform/administrators':['Administrators',{team:{members:[],roles:['PLATFORM_AUDITOR']}}],
 '/platform/partners':['Partners',{companyId:null,reportCompanyId:null,partners:pageOf(),report:null,pendingFees:pageOf()}],
 '/platform/support':['Support',{inbox:pageOf(),chat:null,supportName:'客服'}],
};
for(const [name,engine,options] of [['chrome',chromium,{channel:'chrome'}],['webkit',webkit,{}]]) {
 const browser=await engine.launch({headless:true,...options});
 try {
 const context=await browser.newContext({viewport:{width:1244,height:839}});
 const page=await context.newPage();page.setDefaultTimeout(8000);
 const errors=[],unexpected=[];let restricted=false,empty=false,slow=false,fail=false;
 page.on('pageerror',error=>{errors.push(error.message);console.error('PAGE ERROR',error.message)});
 await context.route('**/*',async route=>{
  const request=route.request(),url=new URL(request.url());
  if(url.origin!==origin || request.method()!=='GET'){unexpected.push(request.method()+' '+url.pathname);return route.abort();}
  if(url.pathname.startsWith('/build/'))return route.fulfill({body:readFileSync('public'+url.pathname),contentType:url.pathname.endsWith('.css')?'text/css':'text/javascript'});
  if(url.pathname.endsWith('/details')){
   if(slow)await new Promise(resolve=>setTimeout(resolve,600));
   if(fail)return route.fulfill({status:503,json:{message:'fixture failure'}});
   return route.fulfill({json:{user,financialAccess,canCreateUser:false}});
  }
  if(url.pathname.endsWith('/support/poll'))return route.fulfill({json:{}});
  const fixture=routes[url.pathname];
  if(!fixture){unexpected.push(url.pathname);return route.abort();}
  const shared={errors:{},publicAssets:[],tenant:null,flash:{},auth:{admin:{name:'测试管理员',permissions:restricted?['users.read']:['users.read','users.create','users.remark.manage','users.invitation.manage','users.referrer.manage','users.commission.manage','wallet.read','ledger.read','wallet_topups.read','withdrawals.read','partners.manage','kyc.read','support.read','notifications.read','notifications.send','tenant.read','tenant.manage','storage.manage','audit.read','card_product.manage','cards.read','provider_operation.read','admin_team.read']},user:null},i18n:{locale:'zh-CN',enabledLocales:['zh-CN','en'],timezone:'Asia/Shanghai',surface:'platform'},companies:[company],filters:{}};
  const props={...shared,...fixture[1]};if(url.pathname==='/platform/users'){props.filters=Object.fromEntries(url.searchParams);props.canCreateUser=!restricted;if(empty)props.users=pageOf();if(restricted)props.financialAccess={balances:false,commission:false,withdrawals:false};}
  const state={component:'platform/'+fixture[0],props,url:url.pathname+url.search,version:'fixture'};
  if(request.headers()['x-inertia'])return route.fulfill({headers:{'X-Inertia':'true'},json:state});
  return route.fulfill({contentType:'text/html',body:`<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">${(entry.css??[]).map(f=>`<link rel="stylesheet" href="/build/${f}">`).join('')}</head><body><script data-page="app" type="application/json">${JSON.stringify(state).replaceAll('<','\\u003c')}</script><div id="app"></div><script type="module" src="/build/${entry.file}"></script></body></html>`});
 });
 for(const width of [375,768,1244,1440,1920]) {
  await page.setViewportSize({width,height:839});
  for(const path of Object.keys(routes)) {
   await page.goto(origin+path);await page.locator('[data-platform-header]').waitFor();
   assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true,`${name} ${width} ${path} overflow`);
   assert.equal(await page.locator('[data-platform-header]').evaluate(el=>Math.round(el.getBoundingClientRect().height)),56);
   if(width===1244 && ['/platform/users','/platform/demo','/platform/partners','/platform/support'].includes(path))await page.screenshot({path:`${out}/${name}-${path.split('/').pop()}-1244.png`});
  }
  await page.goto(origin+'/platform/users?partner=Enabled');await page.getByRole('button',{name:'详情',exact:true}).click();
  const dialog=page.getByRole('dialog');try{await dialog.getByRole('heading',{name:'账号信息',exact:true}).waitFor();}catch(error){console.log(await page.locator('body').innerText());throw error;}
  const rect=await dialog.boundingBox();assert.ok(rect.x>=0 && rect.x+rect.width<=width+1);assert.equal(rect.y,0);
  await page.screenshot({path:`${out}/${name}-detail-${width}.png`});
  await dialog.getByRole('button',{name:'关闭',exact:true}).click();
  await dialog.waitFor({state:'hidden'});
  await page.waitForFunction(()=>document.activeElement?.textContent==='详情');
 }
 await page.getByRole('button',{name:'更多筛选',exact:true}).click();await page.getByRole('combobox',{name:'客服用户',exact:true}).waitFor();
 await page.getByRole('button',{name:'更多筛选',exact:true}).click();assert.equal(await page.getByRole('combobox',{name:'客服用户',exact:true}).count(),0);
 await page.getByRole('button',{name:'添加账号',exact:true}).click();
 const create=page.getByRole('dialog');await create.locator('input[type=email]').fill('synthetic@example.test');
 page.once('dialog',prompt=>prompt.dismiss());await create.getByRole('button',{name:'取消',exact:true}).click();assert.equal(await create.isVisible(),true);
 page.once('dialog',prompt=>prompt.accept());await create.getByRole('button',{name:'取消',exact:true}).click();await create.waitFor({state:'hidden'});
 slow=true;await page.getByRole('button',{name:'详情',exact:true}).click();await page.getByText('加载中…',{exact:true}).waitFor();await page.getByRole('heading',{name:'账号信息',exact:true}).waitFor();await page.getByRole('dialog').getByRole('button',{name:'关闭',exact:true}).click();slow=false;
 fail=true;await page.getByRole('button',{name:'详情',exact:true}).click();await page.locator('[data-operation-result]').waitFor();await page.locator('[data-operation-result]').getByRole('button',{name:'确定',exact:true}).click();fail=false;await page.getByRole('button',{name:'重试',exact:true}).click();await page.getByRole('heading',{name:'账号信息',exact:true}).waitFor();await page.getByRole('dialog').getByRole('button',{name:'关闭',exact:true}).click();
 empty=true;restricted=true;await page.reload();await page.getByText('暂无匹配记录。',{exact:true}).waitFor();assert.equal(await page.getByRole('button',{name:'添加账号',exact:true}).count(),0);assert.equal(await page.getByRole('columnheader',{name:'可用余额',exact:true}).count(),0);
 assert.deepEqual(errors,[]);assert.deepEqual(unexpected,[]);
 console.log(`PASS ${name}: 20 Platform pages x 5 widths; long data, drawer focus, filters, dirty exit protection, loading, error/retry, empty and restricted states; no writes.`);
 } finally {await browser.close();}
}
