import assert from 'node:assert/strict';
import { readFile, mkdir, writeFile } from 'node:fs/promises';
import { chromium } from 'playwright';

// Local UI acceptance. Every POST after login is blocked or fulfilled with browser-only data.
const base='http://a.localhost:8000';
const output=process.env.PROMOTION_HUB_OUTPUT??'/tmp/card-promotion-hub';
await mkdir(output,{recursive:true});
const manifest=JSON.parse(await readFile('public/build/manifest.json','utf8'));
const entry=manifest['resources/js/app.tsx'];
const assets=(entry.css??[]).map(f=>`<link rel="stylesheet" href="/build/${f}">`).join('')+`<script type="module" src="/build/${entry.file}"></script>`;
const pattern=/<script[^>]*data-page="app"[^>]*>([\s\S]*?)<\/script>/;
const replaceAssets=html=>html.replace(/<script\b[^>]*type="module"[^>]*>[\s\S]*?<\/script>/g,'').replace('</head>',assets+'</head>');
const browser=await chromium.launch({channel:'chrome',headless:true});
const checks=[],errors=[],writes=[],quotes=[];
const fast=process.env.PROMOTION_HUB_FAST==='1';
try {
 const context=await browser.newContext({permissions:['local-network-access']});
 await context.route('**/*',async route=>{
  const req=route.request();
  if(req.isNavigationRequest()&&req.method()==='GET'&&new URL(req.url()).origin===base){const res=await route.fetch();return route.fulfill({response:res,body:replaceAssets(await res.text())});}
  return route.continue();
 });
 const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));
 await page.goto(base+'/login');await page.locator('#identifier').fill('user@a.localhost');await page.locator('#password').fill(process.env.PROMOTION_TEST_PASSWORD??'123456');await page.locator('form button[type=submit]').click();await page.waitForURL('**/dashboard');
 const raw=await (await page.request.get(base+'/promotion')).text();
 const initial=JSON.parse(raw.match(pattern)[1]);
 assert.equal(initial.component,'user/PromotionHub');assert.equal(initial.props.home.paid.rank,0);
 assert.equal(initial.props.home.paid.tables,undefined);
 const membership=JSON.parse((await (await page.request.get(base+'/promotion/membership')).text()).match(pattern)[1]);
 const invites=JSON.parse((await (await page.request.get(base+'/promotion/invitations')).text()).match(pattern)[1]);
 let fixture=structuredClone(initial),mockQuote=false;
 await page.route('**/*',async route=>{
  const req=route.request(),url=new URL(req.url());
  if(!['GET','HEAD','OPTIONS'].includes(req.method())){
   if(url.pathname==='/promotion/quotes'&&mockQuote){
    quotes.push(req.postDataJSON());
    const response=structuredClone(membership);response.url='/promotion/membership?order=preview';response.props.paid={...response.props.paid,...fixture.props.home.paid,availableBalance:'100000.00000000'};response.props.quote={id:'preview',rank:7,amount:'50000.00000000',previousTariff:'50000.00000000',tariff:'100000.00000000',expiresAt:new Date(Date.now()+300000).toISOString(),status:'QUOTED',cycleId:'preview-cycle'};
    return route.fulfill({status:200,headers:{'X-Inertia':'true','Content-Type':'application/json'},body:JSON.stringify(response)});
   }
   writes.push(url.pathname);return route.abort();
  }
  if(url.pathname==='/__promotion-hub-preview')return route.fulfill({contentType:'text/html',body:replaceAssets(raw).replace(pattern,()=>`<script data-page="app" type="application/json">${JSON.stringify(fixture).replaceAll('<','\\u003c')}</script>`)});
  return route.fallback();
 });
 const active=()=>({ ...structuredClone(initial.props.home.paid),rank:6,percent:80,reward:'100',membershipStatus:'ACTIVE',cycle:{id:'preview-cycle',startsAt:'2026-09-01T00:00:00Z',endsAt:'2027-09-01T00:00:00Z',tariff:'50000.00000000'},hasClaims:true });
 const show=async()=>{await page.goto(base+'/__promotion-hub-preview',{waitUntil:'networkidle'});assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'page overflow');assert.equal(await page.locator('.user-header').count(),0);};
 for(const width of fast?[375]:[375,768,1440])for(const locale of fast?['zh-CN']:['zh-CN','en','ms','es'])for(const screen of ['home','invitations','rules']){
  fixture=structuredClone(screen==='invitations'?invites:initial);fixture.props.i18n.locale=locale;fixture.props.i18n.enabledLocales=['zh-CN','en','ms','es'];fixture.props.errors={};fixture.props.flash={};
  if(screen!=='invitations'){fixture.props.home={...fixture.props.home,paid:active(),canPurchase:true};fixture.props.section=screen==='rules'?'rules':'overview';fixture.url=screen==='rules'?'/promotion/rules':'/promotion';}
  await page.setViewportSize({width,height:900});await show();
  if(screen==='home'){
   assert.equal(await page.locator('[data-rank]').count(),9);
   assert.equal(await page.locator('.promotion-level-card.is-current').getAttribute('data-rank'),'6');
   const center=await page.locator('.promotion-level-track').evaluate(el=>{const card=el.querySelector('[data-rank="6"]');return Math.abs(card.getBoundingClientRect().x+card.clientWidth/2-el.getBoundingClientRect().x-el.clientWidth/2);});assert.ok(center<3,`selected center ${center}`);
   assert.ok((await page.locator('[data-rank="6"]').innerText()).includes('80%'));
   assert.equal(await page.locator('.promotion-level-card summary').count(),0);
   const levelTitle=await page.locator('[data-rank="6"] h2').boundingBox();
   const levelPrice=await page.locator('[data-rank="6"] .promotion-level-price').boundingBox();
   assert.ok(levelPrice.x>=levelTitle.x+levelTitle.width && Math.abs(levelPrice.y-levelTitle.y)<2,'price in top right');

   assert.ok((await page.locator('[data-rank="6"]').innerText()).includes('50,000 USDT'));
   assert.equal(await page.locator('a[href="/promotion/invitations"]').count(),1);
   const initialTop=await page.locator('.promotion-share-dock').evaluate(el=>el.getBoundingClientRect().top);
   await page.evaluate(()=>window.scrollTo(0,document.documentElement.scrollHeight));
   await page.waitForTimeout(100);
   const dock=await page.locator('.promotion-share-dock').evaluate(el=>{
    const r=el.getBoundingClientRect(),button=el.querySelector('button'),b=button.getBoundingClientRect();
    return {top:r.top,bottom:r.bottom,position:getComputedStyle(el).position,visible:button.contains(document.elementFromPoint(b.x+b.width/2,b.y+b.height/2)),scroll:window.scrollY};
   });
   const nav=await page.locator('.user-bottom-navigation').boundingBox();
   assert.equal(dock.position,'fixed');assert.ok(dock.top>=0&&dock.visible,JSON.stringify(dock));
   assert.ok(Math.abs(dock.bottom-nav.y)<2,'share dock directly above navigation');
   assert.ok(Math.abs(dock.top-initialTop)<2,'share dock stays fixed while scrolling');
   const copyButton=await page.locator('.promotion-hub > section').last().boundingBox();
   if(copyButton)assert.ok(copyButton.y+copyButton.height<=dock.top,'last content clears dock');
   await page.evaluate(()=>window.scrollTo(0,0));

  }
  if(screen==='invitations'){assert.equal(await page.locator('#team-summary table').count(),1);assert.equal(await page.locator('[data-rank]').count(),0);}
  if(locale==='zh-CN')await page.screenshot({path:`${output}/${width}-${screen}.png`,fullPage:true});
  checks.push({width,locale,screen});
 }
 await page.setViewportSize({width:375,height:900});
 for(const scenario of ['ordinary','expired','highest','disabled','pending','snapshot','unavailable']){
  fixture=structuredClone(initial);fixture.props.i18n.locale='zh-CN';fixture.props.home={...fixture.props.home,paid:active(),canPurchase:true};
  const p=fixture.props.home.paid;
  if(['ordinary','expired'].includes(scenario)){p.rank=0;p.percent=0;p.reward='20';p.cycle=null;p.membershipStatus=scenario==='expired'?'EXPIRED':'NONE';p.previousCycle=scenario==='expired'?{rank:4,endsAt:'2025-01-01T00:00:00Z'}:null;}
  if(scenario==='highest'){p.rank=8;p.percent=100;p.reward='120';p.cycle.tariff='200000';}
  if(scenario==='disabled')p.levels=p.levels.map(l=>({...l,enabled:false}));
  if(scenario==='pending')p.pending=true;
  if(scenario==='snapshot')p.levels=p.levels.map(l=>l.rank===6?{...l,fee:'60000',percent:85,reward:'105'}:l);
  if(scenario==='unavailable')fixture.props.home.canPurchase=false;
  await show();
  if(['ordinary','expired'].includes(scenario)){assert.equal(await page.locator('.is-current').getAttribute('data-rank'),'0');assert.equal(await page.locator('[data-rank="1"] button').count(),1);}
  if(['highest','disabled'].includes(scenario))assert.equal(await page.locator('.promotion-level-card button').count(),0);
  if(['pending','unavailable'].includes(scenario))for(const btn of await page.locator('.promotion-level-card button').all())assert.ok(await btn.isDisabled());
  if(scenario==='snapshot'){const content=await page.locator('[data-rank="6"]').innerText();assert.ok(content.includes('50,000 USDT'));assert.ok(content.includes('80%'));assert.ok(!content.includes('85%'));}
  checks.push({scenario});
 }
 fixture=structuredClone(initial);fixture.props.i18n.locale='zh-CN';fixture.props.home={...fixture.props.home,paid:active(),canPurchase:true};await show();
 await page.locator('.promotion-level-track').focus();await page.keyboard.press('ArrowRight');await page.waitForTimeout(500);
 assert.equal(await page.locator('button[aria-current=true]').getAttribute('aria-label'),'万事达7级');
 await page.setViewportSize({width:768,height:900});await page.waitForTimeout(500);
 assert.equal(await page.locator('button[aria-current=true]').getAttribute('aria-label'),'万事达7级');
 mockQuote=true;await page.locator('[data-rank="7"] button').dblclick();await page.locator('[data-payment-review]').waitFor();
 assert.equal(quotes.length,1);assert.equal(quotes[0].level_id,fixture.props.home.paid.levels[6].id);assert.match(quotes[0].request_id,/^[0-9a-f-]{36}$/);assert.equal(quotes[0].amount,undefined);
 assert.ok((await page.locator('[data-payment-review]').innerText()).includes('50,000.00 USDT'));assert.equal(await page.locator('input[type=radio]').count(),0);mockQuote=false;checks.push({quote:'mocked',noAutomaticPayment:true});
 await show();
 await page.evaluate(()=>{Object.defineProperty(navigator,'share',{configurable:true,value:undefined});Object.defineProperty(navigator,'clipboard',{configurable:true,value:{writeText:async value=>{window.__copied=value;}}});});
 await page.getByRole('button',{name:'分享邀请链接',exact:true}).click();assert.equal(await page.evaluate(()=>window.__copied),base+'/register?invite='+fixture.props.home.invitationCode);assert.ok((await page.locator('[role=status]').innerText()).includes('已复制'));
 await page.evaluate(()=>Object.defineProperty(navigator,'share',{configurable:true,value:async()=>{throw new DOMException('cancel','AbortError');}}));
 await page.getByRole('button',{name:'分享邀请链接',exact:true}).click();assert.equal((await page.locator('[role=status]').innerText()).trim(),'');
 await page.evaluate(()=>{Object.defineProperty(navigator,'share',{configurable:true,value:undefined});Object.defineProperty(navigator,'clipboard',{configurable:true,value:{writeText:async()=>{throw new Error('denied');}}});});
 await page.getByRole('button',{name:'分享邀请链接',exact:true}).click();assert.equal(await page.locator('input[readonly]').inputValue(),base+'/register?invite='+fixture.props.home.invitationCode);checks.push({share:'copy, cancellation and denied clipboard'});
 await page.goto(base+'/promotion');await page.locator('a[href="/promotion/invitations"]').click();await page.waitForURL('**/promotion/invitations');await page.locator('.user-page-header a').click();await page.waitForURL('**/promotion');
 assert.deepEqual(writes,[]);assert.deepEqual(errors,[]);
 await writeFile(output+'/report.json',JSON.stringify({checks,errors,writes,mockedQuotes:quotes.length},null,2));console.log(JSON.stringify({checks:checks.length,errors,writes,mockedQuotes:quotes.length,output}));
} finally {await browser.close();}
