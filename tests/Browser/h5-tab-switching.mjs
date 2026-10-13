import assert from 'node:assert/strict';
import { readFileSync, mkdirSync } from 'node:fs';
import { chromium, webkit } from 'playwright';
const fixture=JSON.parse(readFileSync('storage/framework/testing/uni-parity/fixtures.json'));
const origin='http://127.0.0.1:5237', root='dist/clients/specpay/release/h5', out='artifacts/h5-tab-switching';
mkdirSync(out,{recursive:true});
for(const [name,engine,launch] of [['chrome',chromium,{channel:'chrome'}],['webkit',webkit,{}]]) {
 const browser=await engine.launch({headless:true,...launch});
 try {
  const context=await browser.newContext({viewport:{width:375,height:812},isMobile:true,hasTouch:true});
  const page=await context.newPage(),errors=[],reads=[],writes=[],scripts=new Map(),pending=[],pendingCode=[];
  let slow=false,slowCode=true;
  page.on('pageerror',e=>errors.push(e.stack ?? e.message));
  await context.route('**/*',async route=>{
   const request=route.request(),u=new URL(request.url());
   if(u.origin!==origin)return route.abort();
   if(u.pathname.startsWith('/api/v1')) {
    const path=u.pathname.slice(7);
    if(request.method()!=='GET'){writes.push(path);return route.fulfill({json:{}});}
    reads.push(path);
    let result=path==='/bootstrap'?{...fixture.authenticated,locale:'en'}:path==='/client/cards'?fixture.pages['/cards?fixture=verified']:path==='/client/dashboard'?fixture.pages['/dashboard']:fixture.api[path]??{};
    if(slow && ['/client/dashboard','/client/cards','/account'].includes(path)){pending.push({route,result});return;}
    return route.fulfill({json:result});
   }
   if(request.method()!=='GET')return route.abort();
   if(u.pathname.endsWith('.js'))scripts.set(u.pathname,(scripts.get(u.pathname)??0)+1);
   if(slowCode && u.pathname.includes('pages-cards-index') && u.pathname.endsWith('.js')) { pendingCode.push({route,path:u.pathname});return; }
   try{return route.fulfill({body:readFileSync(root+(u.pathname==='/'?'/index.html':u.pathname)),contentType:u.pathname.endsWith('.js')?'text/javascript':u.pathname.endsWith('.css')?'text/css':'text/html'});}catch{return route.abort();}
  });
  await page.goto(origin+'/#/pages/assets/index');
  await page.locator('.shell').waitFor();
  await page.waitForTimeout(2300);
  assert.equal(reads.includes('/client/cards'),false,'code warmup never loads financial pages');
  assert.equal(reads.includes('/account'),false,'code warmup never loads account data');
  assert.equal(await page.locator('uni-tabbar:visible').count(),0,'one custom navigation only');
  slow=true;
  assert.ok(pendingCode.length,'idle code preloading started without mounting the card page');
  await page.locator('.tabs .tab').filter({hasText:/^Cards$/}).click();
  await page.waitForTimeout(150);
  assert.ok(await page.locator('.shell:visible').count(),'old shell remains painted while the target JS chunk is stalled');
  assert.ok(await page.locator('.tabs .tab.active').innerText().then(x=>x.trim()==='Assets'));
  slowCode=false;
  for(const {route,path} of pendingCode.splice(0)) await route.fulfill({body:readFileSync(root+path),contentType:'text/javascript'});
  await page.waitForFunction(()=>[...document.querySelectorAll('.tabs .tab.active')].some(e=>e.textContent.trim()==='Cards'));
  const durations=[];let repeatScripts;
  for(const target of ['Me','Assets','Cards','Me','Assets','Cards']) {
   if(durations.length===3)repeatScripts=new Map(scripts);
   const started=Date.now();await page.locator('.tabs .tab').filter({hasText:new RegExp('^'+target+'$')}).click();
   await page.waitForFunction(target=>[...document.querySelectorAll('.tabs .tab.active')].some(e=>e.textContent.trim()===target),target);
   durations.push(Date.now()-started);
   assert.ok(await page.locator('.shell:visible').count(),'shell stays visible while API is blocked');
   await page.waitForTimeout(100);
   if(target==='Assets')assert.ok(await page.locator('.asset-overview:visible, .asset-center:visible, .asset-summary:visible').count() || (await page.locator('.shell-main').innerText()).includes('USDT'),'cached assets remain visible');
   await page.screenshot({path:`${out}/${name}-${target}-${durations.length}.png`});
  }
  assert.deepEqual([...scripts],[...repeatScripts],'repeated tab switches do not fetch page chunks again');
  assert.equal(writes.filter(path=>path.includes('/cards') || path.includes('/ledger')).length,0);
  for(const {route,result} of pending.splice(0))await route.fulfill({json:result});
  await page.waitForTimeout(100);
  assert.deepEqual(errors,[]);
  console.log(`${name}: six switches with API responses held, shell visible, code-only warmup, cached assets and no financial writes; switch times ${durations.join(', ')} ms`);
 }finally{await browser.close();}
}
