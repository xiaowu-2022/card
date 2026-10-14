import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { chromium, webkit } from 'playwright';

// Cleanup is retired from the UI. Legacy permission/DTO flags cannot restore an entry.
// All session and read responses are fixtures; no cleanup/provider/server writes escape.
const entry=JSON.parse(readFileSync('public/build/manifest.json'))['resources/js/app.tsx'];
const origin='http://admin.localhost:8000', company='22222222-2222-4222-8222-222222222222', id='33333333-3333-4333-8333-333333333333';
const user={id,companyId:company,companyName:'测试公司',displayName:'测试账号',accountId:'202601010001',email:'user@example.test',status:'ACTIVE',createdAt:'2026-10-14T00:00:00Z',lastLoginAt:null,promotionRank:0,ordinaryMember:false,remark:null,remarkRevision:0,supportAgent:false,supportRevision:0,ancestors:[],descendantCount:0};
for(const [name,engine,options] of [['chrome',chromium,{channel:'chrome'}],['webkit',webkit,{}]]) {
 const browser=await engine.launch({headless:true,...options});
 try {
  for(const allowed of [true,false]) {
   const context=await browser.newContext({viewport:{width:1257,height:852}}),page=await context.newPage(),errors=[],cleanupRequests=[],writes=[];
   const access={financialAccess:{receipts:false,balances:false,commission:false,withdrawals:false},canCleanup:allowed,canAdjustWallet:false,canChangeInvitation:false,canChangeReferrer:false,canAdjustCommission:false,canViewKyc:false,canViewFunds:false,canViewTopups:false,canManageSupport:false,canManagePartners:false,canCreateUser:false,canRemark:false,canManageRestrictions:true};
   const state={component:'platform/Users',url:'/platform/users',version:'fixture',props:{errors:{},publicAssets:[],unreadSupport:0,unreadMessages:0,requestId:'fixture',tenant:null,flash:{},auth:{admin:{id:'admin',name:'测试管理员',permissions:['users.read',...(allowed?['users.cleanup']:[])]},user:null},i18n:{surface:'platform',locale:'zh-CN',enabledLocales:['zh-CN','en'],timezone:'Asia/Shanghai'},companies:[{id:company,name:'测试公司'}],filters:{company},users:{data:[user],total:1,current_page:1,last_page:1,prev_page_url:null,next_page_url:null},...access}};
   page.on('pageerror',e=>errors.push(e.message));
   await context.route('**/*',route=>{
    const req=route.request(),url=new URL(req.url());
    if(url.pathname.includes('data-cleanup')){cleanupRequests.push(url.pathname);return route.abort();}
    if(req.method()!=='GET'){writes.push(url.pathname);return route.abort();}
    if(url.origin!==origin)return route.abort();
    if(url.pathname.startsWith('/build/'))return route.fulfill({body:readFileSync('public'+url.pathname),contentType:url.pathname.endsWith('.css')?'text/css':'text/javascript'});
    if(url.pathname===`/platform/tenants/${company}/users/${id}/details`)return route.fulfill({json:{...access,user}});
    if(url.pathname==='/platform/users')return route.fulfill({contentType:'text/html',body:`<html><head>${(entry.css??[]).map(file=>`<link rel="stylesheet" href="/build/${file}">`).join('')}</head><body><script data-page="app" type="application/json">${JSON.stringify(state)}</script><div id="app"></div><script type="module" src="/build/${entry.file}"></script></body></html>`});
    return route.fulfill({status:404,json:{}});
   });
   await page.goto(origin+'/platform/users');await page.getByRole('button',{name:'详情',exact:true}).waitFor();
   assert.equal(await page.getByRole('button',{name:'清理数据',exact:true}).count(),0,'no list/header entry');
   await page.getByRole('button',{name:'详情',exact:true}).click();
   const drawer=page.locator('[data-admin-detail-drawer]');await drawer.getByRole('tab',{name:'基本信息',exact:true}).waitFor();
   assert.equal(await page.getByRole('button',{name:'清理数据',exact:true}).count(),0,'no detail/footer entry');
   assert.equal(await page.getByText('预览清理',{exact:true}).count(),0,'cleanup dialog never mounted');
   assert.equal(await drawer.getByRole('button',{name:'操作限制',exact:true}).count(),1,'other user actions retained');
   await page.setViewportSize({width:375,height:852});assert.equal(await page.getByRole('button',{name:'清理数据',exact:true}).count(),0);
   assert.deepEqual(cleanupRequests,[]);assert.deepEqual(writes,[]);assert.deepEqual(errors,[]);
   await context.close();
  }
  console.log(`${name}: user list/detail/mobile cleanup entries hidden with both legacy permission flags; other actions retained; zero cleanup reads/writes.`);
 }finally{await browser.close();}
}
